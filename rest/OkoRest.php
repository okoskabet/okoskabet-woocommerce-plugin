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

/**
 * OkoRest class for REST
 */
class OkoRest extends Base
{

	/**
	 * Initialize the class.
	 *
	 * @return void|bool
	 */
	public function initialize()
	{
		parent::initialize();

		\add_action('rest_api_init', array($this, 'add_routes'));
	}

	/**
	 * Examples
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_routes()
	{
		$this->add_custom_routes();
	}

	/**
	 * Routes
	 *
	 * @since 1.0.0
	 *
	 *  Make an instance of this class somewhere, then
	 *  call this method and test on the command line with
	 * `curl http://example.com/wp-json/wp/v2/calc?first=1&second=2`
	 * @return void
	 */
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
					'address' => array(
						'required' => false,
					),
					'zip' => array(
						'required' => true,
					),
					'product_ids' => array(
						'required' => false,
					),
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
					'zip' => array(
						'required' => true,
					),
					'product_ids' => array(
						'required' => false,
					),
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
				'args'                => array(),
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
					'dates' => array(
						'required' => true,
					),
				),
			)
		);

		\register_rest_route(
			'wp/v2',
			'okoskabet/webhook',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'handle_webhook'),
				'args'                => array(
					'event' => array(
						'required' => true,
					),
					'shipment_reference' => array(
						'required' => true,
					),
					'data' => array(
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Get available sheds based on zip code and address.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request<array> $request Values.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_sheds(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$params = $request->get_params();
		$settings = \o_get_settings();

		if (empty($settings['_api_key'])) {
			return new \WP_Error('missing_api_key', 'API key not configured', array('status' => 500));
		}

		$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';
		$default_days = (int) ($settings['_maximum_days_in_future'] ?? 3);
		$product_ids  = self::parse_product_ids($params['product_ids'] ?? '');

		// If the customer's cart triggers exceptions whose dates lie further
		// in the future than the merchant's default window, ask Økoskabet
		// for a wider window — otherwise the API simply won't return those
		// dates and our extension logic in the filter has nothing to add.
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
		), $api_url . '/api/v1/sheds/');

		$response = \wp_remote_get($request_url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => $settings['_api_key'],
			),
		));

		if (\is_wp_error($response)) {
			return new \WP_Error('api_error', $response->get_error_message(), array('status' => 502));
		}

		$body = \wp_remote_retrieve_body($response);
		$output_content = \json_decode($body, true);

		// Apply product date rules to each shed's delivery_dates so the
		// frontend only shows dates that are valid for everything currently
		// in the customer's cart. Each shed has its own array of dates
		// because availability depends on the shed's pickup schedule.
		$product_ids = self::parse_product_ids($params['product_ids'] ?? '');
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
			unset($shed); // break reference
		}

		// If every shed lost all its dates, attach a per-product explanation
		// so the frontend can tell the customer WHY no dates are available.
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

		$public_settings = array(
			'_display_option'                  => $settings['_display_option'] ?? '',
			'_description_shipping_okoskabet'  => $settings['_description_shipping_okoskabet'] ?? '',
			'_description_shipping_private'    => $settings['_description_shipping_private'] ?? '',
			'_maximum_days_in_future'          => $maximum_days_in_future,
		);

		return new \WP_REST_Response(array('settings' => $public_settings, 'results' => $output_content), 200);
	}

	/**
	 * Get home delivery dates based on zip code.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request<array> $request Values.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_home_delivery(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$params = $request->get_params();
		$settings = \o_get_settings();

		if (empty($settings['_api_key'])) {
			return new \WP_Error('missing_api_key', 'API key not configured', array('status' => 500));
		}

		$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';
		$default_days = (int) ($settings['_maximum_days_in_future'] ?? 3);
		$product_ids  = self::parse_product_ids($params['product_ids'] ?? '');

		if (class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Delivery_Exceptions')) {
			$maximum_days_in_future = \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::effective_query_window($default_days, $product_ids);
		} else {
			$maximum_days_in_future = $default_days;
		}

		$request_url = \add_query_arg(array(
			'maximum_days_in_future' => $maximum_days_in_future,
			'postal_code'            => $params['zip'],
		), $api_url . '/api/v1/home_delivery');

		$response = \wp_remote_get($request_url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => $settings['_api_key'],
			),
		));

		if (\is_wp_error($response)) {
			return new \WP_Error('api_error', $response->get_error_message(), array('status' => 502));
		}

		$body = \wp_remote_retrieve_body($response);
		$output_content = \json_decode($body, true);

		// Apply product date rules to the home-delivery date list so the
		// frontend dropdown only shows dates that are valid for everything
		// currently in the customer's cart. We pass product_ids from the
		// query string as filter context so the filter doesn't have to read
		// the WC cart directly (which is unreliable in REST context).
		$product_ids = self::parse_product_ids($params['product_ids'] ?? '');
		if (is_array($output_content) && !empty($output_content['delivery_dates']) && is_array($output_content['delivery_dates'])) {
			$output_content['delivery_dates'] = apply_filters(
				'okoskabet_filtered_delivery_dates',
				$output_content['delivery_dates'],
				$product_ids
			);
		}

		// If the filter eliminated every date and the cart has products
		// affected by exceptions, attach a per-product explanation so the
		// frontend can show the customer WHY no dates are available.
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

		$public_settings = array(
			'_display_option'                  => $settings['_display_option'] ?? '',
			'_description_shipping_okoskabet'  => $settings['_description_shipping_okoskabet'] ?? '',
			'_description_shipping_private'    => $settings['_description_shipping_private'] ?? '',
			'_maximum_days_in_future'          => $maximum_days_in_future,
		);

		return new \WP_REST_Response(array('settings' => $public_settings, 'results' => $output_content), 200);
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
	 * Get delivery location options for home delivery.
	 * Fetches options like "At the stairs", "At the front door" etc. from Økoskabet API.
	 * These are shown to the customer as a dropdown during checkout when home delivery is chosen.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request<array> $request Values.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_delivery_location_options(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$settings = \o_get_settings();

		if (empty($settings['_api_key'])) {
			return new \WP_Error('missing_api_key', 'API key not configured', array('status' => 500));
		}

		$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';

		// Use transient to avoid hammering the API — options rarely change.
		// Cache key includes the API environment (production vs staging) and
		// the site locale, so toggling staging or switching language flushes
		// rather than serves stale options.
		$env = !empty($settings['_staging_api']) ? 'staging' : 'production';
		$locale = function_exists('determine_locale') ? determine_locale() : (function_exists('get_locale') ? get_locale() : 'default');
		$transient_key = 'okoskabet_delivery_location_options_' . md5($env . '|' . $locale);
		$cached = get_transient($transient_key);

		if ($cached !== false) {
			return new \WP_REST_Response(array('options' => $cached), 200);
		}

		$response = \wp_remote_get($api_url . '/api/v1/delivery_location_options/', array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => $settings['_api_key'],
			),
		));

		if (\is_wp_error($response)) {
			return new \WP_Error('api_error', $response->get_error_message(), array('status' => 502));
		}

		$http_code = \wp_remote_retrieve_response_code($response);

		// If merchant has no logistics partner, API returns empty list — that's fine.
		if ($http_code === 200) {
			$body    = \wp_remote_retrieve_body($response);
			$decoded = \json_decode($body, true);
			$options = $decoded['options'] ?? array();
		} else {
			$options = array();
		}

		// Cache for 10 minutes.
		set_transient($transient_key, $options, 10 * MINUTE_IN_SECONDS);

		return new \WP_REST_Response(array('options' => $options), 200);
	}

	/**
	 * Handle webhook requests from Okoskabet.
	 * If the event is configured as a capture-trigger, attempts delayed payment capture
	 * before marking the order as completed.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request<array> $request Values.
	 * @return array|\WP_Error
	 */
	public function handle_webhook(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		// === DIAGNOSTIC LOGGING ===
		// Only emits when WP_DEBUG is enabled. The raw body is redacted to
		// length only, so any PII Økoskabet may include in the future doesn't
		// land in production logs indefinitely. The signature header is also
		// redacted (we log its presence and length, not its value).
		if (defined('WP_DEBUG') && WP_DEBUG) {
			$diag_headers = $request->get_headers();
			$diag_params  = $request->get_params();
			$body_len     = strlen($request->get_body());
			$has_sig      = !empty($diag_headers['x_hmac_sha256']) || !empty($diag_headers['x-hmac-sha256']);
			// Redact known-sensitive headers from the dump.
			$redacted = $diag_headers;
			foreach (array('x_hmac_sha256', 'x-hmac-sha256', 'authorization') as $sensitive) {
				if (isset($redacted[$sensitive])) {
					$redacted[$sensitive] = array('[REDACTED ' . strlen(is_array($diag_headers[$sensitive]) ? ($diag_headers[$sensitive][0] ?? '') : $diag_headers[$sensitive]) . ' chars]');
				}
			}
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
				'Økoskabet webhook incoming: body=%d bytes, signature_present=%s, event=%s, shipment_reference=%s',
				$body_len,
				$has_sig ? 'yes' : 'no',
				isset($diag_params['event']) ? $diag_params['event'] : '(none)',
				isset($diag_params['shipment_reference']) ? $diag_params['shipment_reference'] : '(none)'
				));
			}
			if (defined('WP_DEBUG') && WP_DEBUG) { error_log('Økoskabet webhook headers (redacted): ' . wp_json_encode($redacted)); }
		}
		// === END DIAGNOSTIC LOGGING ===

		$params   = $request->get_params();
		$settings = \o_get_settings();
		$headers  = $request->get_headers();
		$raw_body = $request->get_body();

		// --- Step 0: Webhook must be enabled in plugin settings ---
		if (empty($settings['_webhook_enabled'])) {
			if (defined('WP_DEBUG') && WP_DEBUG) { error_log('Økoskabet webhook: REJECTED — webhook functionality is disabled in plugin settings'); }
			return new \WP_Error('webhook_disabled', 'Webhook functionality is disabled', array('status' => 403));
		}

		// --- Step 1: HMAC signature verification ---
		// Økoskabet signs webhooks with HMAC-SHA256 over the raw request body, using
		// the per-webhook Secret configured in their backoffice. The signature is
		// transmitted in the x-hmac-sha256 header, hex-encoded.
		if (empty($settings['_webhook_secret'])) {
			if (defined('WP_DEBUG') && WP_DEBUG) { error_log('Økoskabet webhook: REJECTED — webhook secret is not configured in plugin settings'); }
			return new \WP_Error('webhook_secret_missing', 'Webhook secret not configured', array('status' => 500));
		}

		$received_signature = '';
		if (!empty($headers['x_hmac_sha256'])) {
			$received_signature = is_array($headers['x_hmac_sha256']) ? $headers['x_hmac_sha256'][0] : $headers['x_hmac_sha256'];
		}

		if (empty($received_signature)) {
			if (defined('WP_DEBUG') && WP_DEBUG) { error_log('Økoskabet webhook: REJECTED — missing x-hmac-sha256 signature header'); }
			return new \WP_Error('signature_missing', 'Missing signature header', array('status' => 401));
		}

		$expected_signature = hash_hmac('sha256', $raw_body, $settings['_webhook_secret']);

		// Compare timing-safe and case-insensitive (Økoskabet sends uppercase hex,
		// hash_hmac produces lowercase by default).
		if (!hash_equals($expected_signature, strtolower($received_signature))) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
				'Økoskabet webhook: REJECTED — HMAC signature mismatch. Received=%s',
				$received_signature
				));
			}
			return new \WP_Error('signature_invalid', 'Invalid HMAC signature', array('status' => 401));
		}

		// --- Step 2: Map Økoskabet's event semantics to plugin's internal event names ---
		// Plugin settings use internal names: 'label_printed', 'in_shed', 'order_delivered'.
		// Økoskabet sends these as "reservation_updated" events with different change shapes:
		//
		//   1. Webshop prints a label (calls POST /shipments/:id/parcel)
		//      → changes.parcels.previous=[] and changes.parcels.value=[{status:registered, ...}]
		//      → mapped to "label_printed"
		//
		//   2. Økoskabet places shipment in shed (admin marks as fulfilled)
		//      → changes.status: registered → fulfilled
		//      → mapped to "in_shed"
		//
		//   3. Customer collects shipment from shed
		//      → changes.status: fulfilled → delivered
		//      → mapped to "order_delivered"
		//
		// Other reservation_updated events (parcel becomes fulfilled, etc.) are ignored.
		$raw_event          = isset($params['event']) ? sanitize_text_field($params['event']) : '';
		$shipment_reference = isset($params['shipment_reference']) ? sanitize_text_field($params['shipment_reference']) : '';

		$internal_event = null;
		if ($raw_event === 'reservation_updated') {
			// Detect "label printed" — a parcel was added to a shipment that previously had none.
			$parcels_previous = isset($params['changes']['parcels']['previous']) ? $params['changes']['parcels']['previous'] : null;
			$parcels_value    = isset($params['changes']['parcels']['value']) ? $params['changes']['parcels']['value'] : null;
			if (is_array($parcels_previous) && is_array($parcels_value)
				&& count($parcels_previous) === 0 && count($parcels_value) > 0) {
				$internal_event = 'label_printed';
			}
			// Detect shipment-level status transitions.
			elseif (!empty($params['changes']['status']['value'])) {
				$new_status = sanitize_text_field($params['changes']['status']['value']);
				if ($new_status === 'fulfilled') {
					$internal_event = 'in_shed';
				} elseif ($new_status === 'delivered') {
					$internal_event = 'order_delivered';
				}
			}
		}

		// Backwards compatibility safety net: if any settings array still
		// references the legacy 'label_created' key (from plugin version 1.2.6
		// or earlier), treat it as equivalent to 'in_shed' (which is what
		// label_created actually mapped to before).
		// Note: the Upgrades integration runs a one-shot migration on
		// admin_init that rewrites these stored values. This runtime remap is
		// kept as a defense-in-depth measure for sites that bypass the admin
		// (e.g. direct REST traffic right after upgrade, before an admin
		// loads any page).
		$webhook_events_raw = !empty($settings['_webhook_events']) ? $settings['_webhook_events'] : array();
		$capture_events_raw = !empty($settings['_capture_events']) ? $settings['_capture_events'] : array();
		$webhook_events = array_map(function($e) { return $e === 'label_created' ? 'in_shed' : $e; }, $webhook_events_raw);
		$capture_events = array_map(function($e) { return $e === 'label_created' ? 'in_shed' : $e; }, $capture_events_raw);

		// If we couldn't map to a known internal event, this webhook is not actionable.
		// Return 200 so Økoskabet doesn't retry indefinitely — we acknowledge receipt
		// even though we don't act on it.
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

		// --- Step 3: Read configured event settings ---
		$gateway_hint   = !empty($settings['_payment_gateway']) ? $settings['_payment_gateway'] : 'auto';

		$triggers_capture  = in_array($internal_event, $capture_events, true);
		$triggers_complete = in_array($internal_event, $webhook_events, true);

		if (!$triggers_capture && !$triggers_complete) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log(sprintf(
				'Økoskabet webhook: ignored — internal event "%s" not enabled in plugin settings',
				$internal_event
				));
			}
			return array(
				'success'  => true,
				'message'  => 'Event not configured for any action',
				'event'    => $internal_event,
				'order_id' => $shipment_reference,
			);
		}

		// --- Step 4: Locate the WooCommerce order ---
		if (!function_exists('wc_get_order')) {
			return new \WP_Error('woocommerce_not_available', 'WooCommerce is not available', array('status' => 503));
		}

		$order = wc_get_order($shipment_reference);
		if (!$order) {
			if (defined('WP_DEBUG') && WP_DEBUG) { error_log(sprintf('Økoskabet webhook: order not found for shipment_reference=%s', $shipment_reference)); }
			return new \WP_Error('order_not_found', 'Order not found', array('status' => 404));
		}

		$capture_result = null;

		// --- Step 5: Capture payment if this event is a capture trigger ---
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

		// --- Step 6: Mark order as completed if this event triggers completion ---
		if ($triggers_complete) {
			if ($order->get_status() !== 'completed') {
				$order->update_status('completed', sprintf(
					/* translators: %s = internal event name */
					__('Ordre markeret som afsluttet via Økoskabet webhook. Event: %s', O_TEXTDOMAIN),
					$internal_event
				));
			}
		}

		// Log the successfully processed webhook.
		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log(sprintf(
			'Økoskabet webhook processed: RawEvent=%s, InternalEvent=%s, Order=%s, Capture=%s, Complete=%s',
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
			'capture_result' => $capture_result,
			'new_status'     => $order->get_status(),
		);
	}

	/**
	 * Get available delivery dates for the current cart, filtered by product date rules.
	 * Used by the checkout JS to show only valid dates.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request<array> $request Values.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_filtered_dates(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$params = $request->get_params();

		// Dates come in as comma-separated or array from the frontend.
		$raw_dates = $params['dates'] ?? array();
		if (is_string($raw_dates)) {
			$raw_dates = array_filter( array_map( 'trim', explode( ',', $raw_dates ) ) );
		}

		$raw_dates = array_map( 'sanitize_text_field', (array) $raw_dates );

		// Apply product date rule filtering.
		$filtered = apply_filters( 'okoskabet_filtered_delivery_dates', $raw_dates );

		return new \WP_REST_Response( array( 'dates' => array_values( $filtered ) ), 200 );
	}
}
