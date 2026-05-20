<?php

/**
 * okoskabet_woocommerce_plugin
 *
 * @package   okoskabet_woocommerce_plugin
 * @author    Kim Frederiksen <kim@heyrobot.com>
 * @copyright 2024 HeyRobot.AI aps
 * @license   GPL 2.0+
 * @link      https://heyrobot.ai
 */

namespace okoskabet_woocommerce_plugin\Rest;

use okoskabet_woocommerce_plugin\Engine\Base;
use okoskabet_woocommerce_plugin\Integrations\Merchants;
use okoskabet_woocommerce_plugin\Integrations\Merchant_Router;

/**
 * Multi-merchant aware REST endpoints.
 *
 * Every customer-facing endpoint resolves the merchant from the request's
 * `product_ids` (or an explicit `merchant_id` override) and forwards the
 * call to THAT merchant's API using THAT merchant's credentials. The
 * legacy single-merchant settings (`o_get_settings()`) are no longer
 * consulted for credentials after the seed-default-merchant migration
 * has run — they're treated as global UX settings only.
 *
 * Webhook routes:
 *   - `/wp/v2/okoskabet/webhook/<merchant_id>` — preferred; signature is
 *     verified against the merchant's own webhook secret.
 *   - `/wp/v2/okoskabet/webhook` — legacy alias that routes to the
 *     default merchant. Kept indefinitely so existing webhook URLs
 *     configured at Økoskabet continue to work after the plugin is
 *     upgraded.
 */
class OkoRest extends Base
{

	public function initialize()
	{
		parent::initialize();

		\add_action('rest_api_init', array($this, 'add_routes'));
	}

	public function add_routes()
	{
		$this->add_custom_routes();
	}

	public function add_custom_routes()
	{
		\register_rest_route(
			'wp/v2',
			'okoskabet/sheds',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'get_sheds'),
				'args'                => array(
					'address'     => array('required' => false),
					'zip'         => array('required' => true),
					'product_ids' => array('required' => false),
					'merchant_id' => array('required' => false),
				),
			)
		);

		\register_rest_route(
			'wp/v2',
			'okoskabet/home_delivery',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'get_home_delivery'),
				'args'                => array(
					'zip'         => array('required' => true),
					'product_ids' => array('required' => false),
					'merchant_id' => array('required' => false),
				),
			)
		);

		\register_rest_route(
			'wp/v2',
			'okoskabet/delivery_location_options',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'get_delivery_location_options'),
				'args'                => array(
					'product_ids' => array('required' => false),
					'merchant_id' => array('required' => false),
				),
			)
		);

		\register_rest_route(
			'wp/v2',
			'okoskabet/filtered_dates',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'get_filtered_dates'),
				'args'                => array(
					'dates'       => array('required' => true),
					'product_ids' => array('required' => false),
				),
			)
		);

		// Read-only summary used by the checkout JS to show "your cart
		// is being routed to merchant X" and reveal conflicts before
		// the customer submits. Accepts an optional `zip` so the
		// frontend can preview which merchant a cart will route to
		// after a zip-code change, before WC's session has caught up.
		\register_rest_route(
			'wp/v2',
			'okoskabet/cart_resolution',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'get_cart_resolution'),
				'args'                => array(
					'product_ids' => array('required' => false),
					'zip'         => array('required' => false),
				),
			)
		);

		// Per-merchant webhook route — preferred. Each merchant has its
		// own URL/secret pair so signatures can never be cross-verified.
		\register_rest_route(
			'wp/v2',
			'okoskabet/webhook/(?P<merchant_id>[a-z0-9_\-]+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'handle_webhook'),
				'args'                => array(
					'event'              => array('required' => true),
					'shipment_reference' => array('required' => true),
					'data'               => array('required' => false),
				),
			)
		);

		// Legacy webhook route — kept for backward compatibility. Always
		// dispatches to the default merchant. New deployments should use
		// the per-merchant URL above.
		\register_rest_route(
			'wp/v2',
			'okoskabet/webhook',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'handle_webhook'),
				'args'                => array(
					'event'              => array('required' => true),
					'shipment_reference' => array('required' => true),
					'data'               => array('required' => false),
				),
			)
		);
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Resolve the merchant the request should be served from.
	 *
	 * Precedence:
	 *   1. Explicit `merchant_id` parameter — useful for admin tools, but
	 *      always validated against the merchant registry.
	 *   2. Routing from `product_ids` (cart contents), zone-filtered by
	 *      the customer's live shipping destination.
	 *   3. Default merchant.
	 *
	 * @return array|null Merchant record, or null if nothing is configured.
	 */
	private static function resolve_request_merchant(\WP_REST_Request $request): ?array {
		$explicit = $request->get_param('merchant_id');
		if (is_string($explicit) && $explicit !== '') {
			$merchant = Merchants::get(Merchant_Router::sanitize_id($explicit));
			if ($merchant) {
				return $merchant;
			}
		}

		$product_ids = self::parse_product_ids($request->get_param('product_ids'));
		if (! empty($product_ids)) {
			$zone_id  = self::resolve_request_zone($request);
			$resolved = Merchant_Router::resolve_for_products($product_ids, $zone_id);
			if (! empty($resolved['merchant'])) {
				return $resolved['merchant'];
			}
		}

		return Merchants::get_default();
	}

	/**
	 * Resolve the WooCommerce shipping zone the request is shipping into.
	 *
	 * The customer's postcode is the only field that meaningfully changes
	 * during a single checkout session, and WC's checkout JS sends it as
	 * an explicit `zip` query parameter — that input is the freshest
	 * source we have (one step ahead of the session cache, which only
	 * updates after WC's `update_order_review` AJAX commits).
	 *
	 * Country and state are read from the WC customer session because
	 * the JS doesn't forward them as parameters. This is fine in
	 * practice: country/state change much less often than zip, and the
	 * session is reliably populated by `update_order_review` before our
	 * `updated_checkout`-triggered REST calls fire.
	 *
	 * Returns null if WC's zones API isn't loaded, no country is set
	 * (haven't reached the checkout form yet), or no rule matches —
	 * which the router interprets as "skip zone filtering".
	 */
	private static function resolve_request_zone(\WP_REST_Request $request): ?int {
		$zip = $request->get_param('zip');
		$zip = is_string($zip) ? trim($zip) : '';

		$country = '';
		$state   = '';
		if (function_exists('WC') && WC()->customer) {
			$country = (string) WC()->customer->get_shipping_country();
			$state   = (string) WC()->customer->get_shipping_state();
			if ($zip === '') {
				$zip = (string) WC()->customer->get_shipping_postcode();
			}
		}

		if ($country === '') {
			return null;
		}

		return Merchant_Router::shipping_zone_id_for_destination($country, $state, $zip);
	}

	/**
	 * Parse a comma-separated list of integers into a sanitised array of
	 * positive product IDs. Defensive against any input shape.
	 */
	private static function parse_product_ids($raw): array {
		if (is_array($raw)) {
			$pieces = $raw;
		} elseif (is_string($raw) && $raw !== '') {
			$pieces = explode(',', $raw);
		} else {
			return array();
		}
		$out = array();
		foreach ($pieces as $piece) {
			$n = (int) trim((string) $piece);
			if ($n > 0) {
				$out[] = $n;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * Public settings block exposed to the frontend in every endpoint
	 * response. Includes the merchant we resolved to, so the JS knows
	 * whose descriptions / display options to render.
	 */
	private static function public_settings_payload(array $merchant, int $effective_window): array {
		return array(
			'_display_option'                  => self::global_display_option(),
			'_description_shipping_okoskabet'  => $merchant['description_shipping_okoskabet'] ?? '',
			'_description_shipping_private'    => $merchant['description_shipping_private']   ?? '',
			'_maximum_days_in_future'          => $effective_window,
			'merchant_id'                      => $merchant['id'] ?? '',
			'merchant_label'                   => $merchant['label'] ?? '',
		);
	}

	/**
	 * Display option (inline vs modal) is currently a single global
	 * choice. Could become per-merchant later — for now we read it from
	 * the legacy settings and let it be the same for every merchant.
	 */
	private static function global_display_option(): string {
		$global = \o_get_settings();
		return (string) ($global['_display_option'] ?? '');
	}

	// =========================================================================
	// Public endpoints
	// =========================================================================

	/**
	 * Get available sheds based on zip code and address.
	 *
	 * @param \WP_REST_Request<array> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_sheds(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$merchant = self::resolve_request_merchant($request);
		if ($merchant === null || empty($merchant['api_key'])) {
			return new \WP_Error('missing_api_key', 'API key not configured for the resolved merchant', array('status' => 500));
		}

		$params       = $request->get_params();
		$default_days = (int) ($merchant['maximum_days_in_future'] ?? 3);
		$product_ids  = self::parse_product_ids($params['product_ids'] ?? '');

		if (class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Delivery_Exceptions')) {
			$maximum_days_in_future = \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::effective_query_window($default_days, $product_ids);
		} else {
			$maximum_days_in_future = $default_days;
		}

		$request_url = \add_query_arg(array(
			'delivery_dates'         => 'true',
			'maximum_days_in_future' => $maximum_days_in_future,
			'zipcode'                => $params['zip'],
			'address'                => $params['address'] ?? '',
		), Merchants::api_url_for($merchant) . '/api/v1/sheds/');

		$response = \wp_remote_get($request_url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => $merchant['api_key'],
			),
		));

		if (\is_wp_error($response)) {
			return new \WP_Error('api_error', $response->get_error_message(), array('status' => 502));
		}

		$body = \wp_remote_retrieve_body($response);
		$output_content = \json_decode($body, true);

		$any_dates_left = false;
		if (is_array($output_content) && !empty($output_content['sheds']) && is_array($output_content['sheds'])) {
			foreach ($output_content['sheds'] as &$shed) {
				if (isset($shed['delivery_dates']) && is_array($shed['delivery_dates'])) {
					$shed['delivery_dates'] = apply_filters(
						'okoskabet_filtered_delivery_dates',
						$shed['delivery_dates'],
						$product_ids
					);
					if (!empty($shed['delivery_dates'])) {
						$any_dates_left = true;
					}
				}
			}
			unset($shed);
		}

		if (
			is_array($output_content)
			&& !$any_dates_left
			&& !empty($product_ids)
			&& class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Delivery_Exceptions')
		) {
			$explanation = \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::explanation_for_cart($product_ids);
			if (!empty($explanation['has_exceptions'])) {
				$output_content['exceptions_explanation'] = $explanation;
			}
		}

		return new \WP_REST_Response(array(
			'settings' => self::public_settings_payload($merchant, $maximum_days_in_future),
			'results'  => $output_content,
		), 200);
	}

	/**
	 * Get home delivery dates based on zip code.
	 *
	 * @param \WP_REST_Request<array> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_home_delivery(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$merchant = self::resolve_request_merchant($request);
		if ($merchant === null || empty($merchant['api_key'])) {
			return new \WP_Error('missing_api_key', 'API key not configured for the resolved merchant', array('status' => 500));
		}

		$params       = $request->get_params();
		$default_days = (int) ($merchant['maximum_days_in_future'] ?? 3);
		$product_ids  = self::parse_product_ids($params['product_ids'] ?? '');

		if (class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Delivery_Exceptions')) {
			$maximum_days_in_future = \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::effective_query_window($default_days, $product_ids);
		} else {
			$maximum_days_in_future = $default_days;
		}

		$request_url = \add_query_arg(array(
			'maximum_days_in_future' => $maximum_days_in_future,
			'postal_code'            => $params['zip'],
		), Merchants::api_url_for($merchant) . '/api/v1/home_delivery');

		$response = \wp_remote_get($request_url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => $merchant['api_key'],
			),
		));

		if (\is_wp_error($response)) {
			return new \WP_Error('api_error', $response->get_error_message(), array('status' => 502));
		}

		$body = \wp_remote_retrieve_body($response);
		$output_content = \json_decode($body, true);

		if (is_array($output_content) && !empty($output_content['delivery_dates']) && is_array($output_content['delivery_dates'])) {
			$output_content['delivery_dates'] = apply_filters(
				'okoskabet_filtered_delivery_dates',
				$output_content['delivery_dates'],
				$product_ids
			);
		}

		if (
			is_array($output_content)
			&& empty($output_content['delivery_dates'])
			&& ! empty($product_ids)
			&& class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Delivery_Exceptions')
		) {
			$explanation = \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::explanation_for_cart($product_ids);
			if (!empty($explanation['has_exceptions'])) {
				$output_content['exceptions_explanation'] = $explanation;
			}
		}

		return new \WP_REST_Response(array(
			'settings' => self::public_settings_payload($merchant, $maximum_days_in_future),
			'results'  => $output_content,
		), 200);
	}

	/**
	 * Get delivery location options for home delivery, fetched from the
	 * resolved merchant's API.
	 *
	 * @param \WP_REST_Request<array> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_delivery_location_options(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$merchant = self::resolve_request_merchant($request);
		if ($merchant === null || empty($merchant['api_key'])) {
			return new \WP_Error('missing_api_key', 'API key not configured for the resolved merchant', array('status' => 500));
		}

		// Cache key includes the merchant id so different merchants don't
		// share location-option caches. Locale and env (staging/prod) are
		// also factored in.
		$env           = ! empty($merchant['staging']) ? 'staging' : 'production';
		$locale        = function_exists('determine_locale') ? determine_locale() : (function_exists('get_locale') ? get_locale() : 'default');
		$transient_key = 'okoskabet_delivery_location_options_' . md5($merchant['id'] . '|' . $env . '|' . $locale);
		$cached        = get_transient($transient_key);

		if ($cached !== false) {
			return new \WP_REST_Response(array('options' => $cached), 200);
		}

		$response = \wp_remote_get(Merchants::api_url_for($merchant) . '/api/v1/delivery_location_options/', array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => $merchant['api_key'],
			),
		));

		if (\is_wp_error($response)) {
			return new \WP_Error('api_error', $response->get_error_message(), array('status' => 502));
		}

		$http_code = \wp_remote_retrieve_response_code($response);

		if ($http_code === 200) {
			$body    = \wp_remote_retrieve_body($response);
			$decoded = \json_decode($body, true);
			$options = $decoded['options'] ?? array();
		} else {
			$options = array();
		}

		set_transient($transient_key, $options, 10 * MINUTE_IN_SECONDS);

		return new \WP_REST_Response(array('options' => $options), 200);
	}

	/**
	 * Get available delivery dates for the current cart, filtered by
	 * product date rules.
	 *
	 * @param \WP_REST_Request<array> $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_filtered_dates(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$params = $request->get_params();

		$raw_dates = $params['dates'] ?? array();
		if (is_string($raw_dates)) {
			$raw_dates = array_filter(array_map('trim', explode(',', $raw_dates)));
		}
		$raw_dates = array_map('sanitize_text_field', (array) $raw_dates);

		$product_ids = self::parse_product_ids($params['product_ids'] ?? '');
		$filtered    = apply_filters('okoskabet_filtered_delivery_dates', $raw_dates, $product_ids);

		return new \WP_REST_Response(array('dates' => array_values($filtered)), 200);
	}

	/**
	 * Returns the merchant the cart resolves to. Informational fields
	 * (`is_mixed`, `fell_back_to_default`, `merchant_ids`) help the
	 * frontend explain what happened — but `merchant_id` is always a
	 * single, fully-resolved merchant. Mixed carts fall back to the
	 * default merchant; no conflict is ever surfaced.
	 *
	 * Intentionally non-sensitive — no API keys, no secrets.
	 *
	 * @param \WP_REST_Request<array> $request
	 * @return \WP_REST_Response
	 */
	public function get_cart_resolution(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$product_ids = self::parse_product_ids($request->get_param('product_ids'));
		$zone_id     = self::resolve_request_zone($request);
		$resolved    = Merchant_Router::resolve_for_products($product_ids, $zone_id);

		$payload = array(
			'merchant_id'          => $resolved['merchant_id'],
			'merchant_label'       => $resolved['merchant']['label'] ?? '',
			'is_mixed'             => (bool) ($resolved['is_mixed'] ?? false),
			'fell_back_to_default' => (bool) ($resolved['fell_back_to_default'] ?? false),
			'merchant_ids'         => $resolved['merchant_ids'] ?? array(),
			'zone_filtered_out'    => $resolved['zone_filtered_out'] ?? array(),
			'shipping_zone_id'     => $zone_id,
		);

		return new \WP_REST_Response($payload, 200);
	}

	// =========================================================================
	// Webhook
	// =========================================================================

	/**
	 * Handle webhook requests from Økoskabet.
	 *
	 * Two route shapes feed into this:
	 *   - /webhook/<merchant_id> — preferred. Verifies against THAT
	 *     merchant's secret. 404 if the merchant ID is unknown.
	 *   - /webhook (legacy) — routes to the default merchant.
	 *
	 * @param \WP_REST_Request<array> $request
	 * @return array|\WP_Error
	 */
	public function handle_webhook(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		if (defined('WP_DEBUG') && WP_DEBUG) {
			$diag_headers = $request->get_headers();
			$diag_params  = $request->get_params();
			$body_len     = strlen($request->get_body());
			$has_sig      = !empty($diag_headers['x_hmac_sha256']) || !empty($diag_headers['x-hmac-sha256']);
			$redacted = $diag_headers;
			foreach (array('x_hmac_sha256', 'x-hmac-sha256', 'authorization') as $sensitive) {
				if (isset($redacted[$sensitive])) {
					$redacted[$sensitive] = array('[REDACTED ' . strlen(is_array($diag_headers[$sensitive]) ? ($diag_headers[$sensitive][0] ?? '') : $diag_headers[$sensitive]) . ' chars]');
				}
			}
			error_log(sprintf(
				'Økoskabet webhook incoming: body=%d bytes, signature_present=%s, event=%s, shipment_reference=%s, merchant_id=%s',
				$body_len,
				$has_sig ? 'yes' : 'no',
				isset($diag_params['event']) ? $diag_params['event'] : '(none)',
				isset($diag_params['shipment_reference']) ? $diag_params['shipment_reference'] : '(none)',
				$request->get_param('merchant_id') ?: '(default-route)'
			));
			error_log('Økoskabet webhook headers (redacted): ' . wp_json_encode($redacted));
		}

		// --- Step 0: Resolve which merchant the request is for ---
		$merchant_id_param = $request->get_param('merchant_id');
		if (is_string($merchant_id_param) && $merchant_id_param !== '') {
			$merchant = Merchants::get(Merchant_Router::sanitize_id($merchant_id_param));
			if (!$merchant) {
				if (defined('WP_DEBUG') && WP_DEBUG) {
					error_log('Økoskabet webhook: REJECTED — unknown merchant_id in URL');
				}
				return new \WP_Error('unknown_merchant', 'Unknown merchant', array('status' => 404));
			}
		} else {
			// Legacy route — fall back to default merchant.
			$merchant = Merchants::get_default();

			// If no default merchant is configured (very early in setup),
			// fall back to legacy global settings so existing installations
			// keep working until the merchants migration has fired.
			if (!$merchant) {
				$legacy = \o_get_settings();
				$merchant = array(
					'id'             => Merchants::DEFAULT_MERCHANT_ID,
					'webhook_secret' => $legacy['_webhook_secret'] ?? '',
					'staging'        => ! empty($legacy['_staging_api']),
					'payment_gateway' => $legacy['_payment_gateway'] ?? 'auto',
					'capture_events'  => (array) ($legacy['_capture_events'] ?? array('label_printed')),
					'webhook_events'  => (array) ($legacy['_webhook_events'] ?? array('order_delivered')),
				);
			}
		}

		$settings = \o_get_settings();
		$headers  = $request->get_headers();
		$raw_body = $request->get_body();

		// --- Step 1: Webhook must be enabled in plugin settings ---
		// `_webhook_enabled` is a single global toggle — useful to switch
		// off webhook processing entirely without revoking every secret.
		if (empty($settings['_webhook_enabled'])) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('Økoskabet webhook: REJECTED — webhook functionality is disabled in plugin settings');
			}
			return new \WP_Error('webhook_disabled', 'Webhook functionality is disabled', array('status' => 403));
		}

		// --- Step 2: HMAC signature verification against this merchant's secret ---
		$secret = (string) ($merchant['webhook_secret'] ?? '');
		if ($secret === '') {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf('Økoskabet webhook: REJECTED — no webhook secret configured for merchant %s', $merchant['id'] ?? '?'));
			}
			return new \WP_Error('webhook_secret_missing', 'Webhook secret not configured for this merchant', array('status' => 500));
		}

		$received_signature = '';
		if (!empty($headers['x_hmac_sha256'])) {
			$received_signature = is_array($headers['x_hmac_sha256']) ? $headers['x_hmac_sha256'][0] : $headers['x_hmac_sha256'];
		}
		if (empty($received_signature)) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('Økoskabet webhook: REJECTED — missing x-hmac-sha256 signature header');
			}
			return new \WP_Error('signature_missing', 'Missing signature header', array('status' => 401));
		}

		$expected_signature = hash_hmac('sha256', $raw_body, $secret);
		if (!hash_equals($expected_signature, strtolower($received_signature))) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
					'Økoskabet webhook: REJECTED — HMAC signature mismatch for merchant %s. Received=%s',
					$merchant['id'] ?? '?',
					$received_signature
				));
			}
			return new \WP_Error('signature_invalid', 'Invalid HMAC signature', array('status' => 401));
		}

		// --- Step 3: Map Økoskabet's event semantics to internal names ---
		$params             = $request->get_params();
		$raw_event          = isset($params['event']) ? sanitize_text_field($params['event']) : '';
		$shipment_reference = isset($params['shipment_reference']) ? sanitize_text_field($params['shipment_reference']) : '';

		$internal_event = null;
		if ($raw_event === 'reservation_updated') {
			$parcels_previous = isset($params['changes']['parcels']['previous']) ? $params['changes']['parcels']['previous'] : null;
			$parcels_value    = isset($params['changes']['parcels']['value']) ? $params['changes']['parcels']['value'] : null;
			if (is_array($parcels_previous) && is_array($parcels_value)
				&& count($parcels_previous) === 0 && count($parcels_value) > 0) {
				$internal_event = 'label_printed';
			} elseif (!empty($params['changes']['status']['value'])) {
				$new_status = sanitize_text_field($params['changes']['status']['value']);
				if ($new_status === 'fulfilled') {
					$internal_event = 'in_shed';
				} elseif ($new_status === 'delivered') {
					$internal_event = 'order_delivered';
				}
			}
		}

		// Backwards-compat: still remap legacy label_created at runtime.
		$webhook_events_raw = (array) ($merchant['webhook_events'] ?? array());
		$capture_events_raw = (array) ($merchant['capture_events'] ?? array());
		$webhook_events = array_map(function ($e) { return $e === 'label_created' ? 'in_shed' : $e; }, $webhook_events_raw);
		$capture_events = array_map(function ($e) { return $e === 'label_created' ? 'in_shed' : $e; }, $capture_events_raw);

		if ($internal_event === null) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
					'Økoskabet webhook: ignored — no internal mapping for event=%s, status=%s',
					$raw_event,
					isset($params['changes']['status']['value']) ? $params['changes']['status']['value'] : '(none)'
				));
			}
			return array(
				'success' => true,
				'message' => 'Event acknowledged but not actionable',
				'event'   => $raw_event,
			);
		}

		// --- Step 4: Read configured event settings ---
		$gateway_hint     = (string) ($merchant['payment_gateway'] ?? 'auto');
		$triggers_capture  = in_array($internal_event, $capture_events, true);
		$triggers_complete = in_array($internal_event, $webhook_events, true);

		if (!$triggers_capture && !$triggers_complete) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
					'Økoskabet webhook: ignored — internal event "%s" not enabled for merchant %s',
					$internal_event,
					$merchant['id'] ?? '?'
				));
			}
			return array(
				'success'  => true,
				'message'  => 'Event not configured for any action',
				'event'    => $internal_event,
				'order_id' => $shipment_reference,
			);
		}

		// --- Step 5: Locate the WooCommerce order ---
		if (!function_exists('wc_get_order')) {
			return new \WP_Error('woocommerce_not_available', 'WooCommerce is not available', array('status' => 503));
		}

		$order = wc_get_order($shipment_reference);
		if (!$order) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf('Økoskabet webhook: order not found for shipment_reference=%s', $shipment_reference));
			}
			return new \WP_Error('order_not_found', 'Order not found', array('status' => 404));
		}

		// --- Step 5b: Verify the order actually belongs to the merchant
		// the webhook claims it does. This is defence-in-depth — a valid
		// HMAC signature already proves the request came from Økoskabet,
		// but a multi-merchant deployment with two merchants both
		// configured for the same WooCommerce shop shouldn't allow
		// merchant A's webhook to mutate merchant B's order, even if
		// somehow signed correctly. (Practically impossible without
		// leaking the other secret, but cheap to enforce.) Orders
		// without a recorded merchant predate the migration — treat
		// those as belonging to the default merchant.
		$order_merchant_id = sanitize_key((string) $order->get_meta(Merchant_Router::ORDER_META_KEY, true));
		if ($order_merchant_id === '') {
			$order_merchant_id = Merchants::default_id();
		}
		if (! empty($merchant['id']) && $order_merchant_id !== $merchant['id']) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
					'Økoskabet webhook: REJECTED — order %s belongs to merchant "%s" but webhook came in for "%s"',
					$shipment_reference,
					$order_merchant_id,
					$merchant['id']
				));
			}
			return new \WP_Error('merchant_mismatch', 'Order belongs to a different merchant', array('status' => 403));
		}

		$capture_result = null;

		// --- Step 6: Capture payment if this event is a capture trigger ---
		if ($triggers_capture && !$order->is_paid()) {
			$capture_result = \okoskabet_woocommerce_plugin\Integrations\Payment_Capture::capture(
				$order,
				$gateway_hint
			);

			$order->add_order_note(sprintf(
				/* translators: %1$s = internal event name, %2$s = capture result message */
				__('Økoskabet webhook: betalingshåndtering via event "%1$s". Resultat: %2$s', O_TEXTDOMAIN),
				$internal_event,
				$capture_result['message']
			));
		}

		// --- Step 7: Mark order as completed if this event triggers completion ---
		if ($triggers_complete) {
			if ($order->get_status() !== 'completed') {
				$order->update_status('completed', sprintf(
					/* translators: %s = internal event name */
					__('Ordre markeret som afsluttet via Økoskabet webhook. Event: %s', O_TEXTDOMAIN),
					$internal_event
				));
			}
		}

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf(
				'Økoskabet webhook processed: Merchant=%s, RawEvent=%s, InternalEvent=%s, Order=%s, Capture=%s, Complete=%s',
				$merchant['id'] ?? '?',
				$raw_event,
				$internal_event,
				$shipment_reference,
				$triggers_capture ? 'yes' : 'no',
				$triggers_complete ? 'yes' : 'no'
			));
		}

		return array(
			'success'        => true,
			'message'        => 'Webhook processed',
			'event'          => $internal_event,
			'raw_event'      => $raw_event,
			'order_id'       => $shipment_reference,
			'merchant_id'    => $merchant['id'] ?? '',
			'capture_result' => $capture_result,
			'new_status'     => $order->get_status(),
		);
	}
}
