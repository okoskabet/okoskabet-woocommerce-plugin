<?php

/**
 * Økoskabet WooCommerce Plugin
 *
 * @package   okoskabet_woocommerce_plugin
 * @author    Kim Frederiksen <kim@heyrobot.com>
 * @copyright 2024 HeyRobot.AI aps
 * @license   GPL 2.0+
 * @link      https://heyrobot.ai
 */

/**
 * Get the settings of the plugin in a filterable way
 *
 * @since 1.0.0
 * @return array
 */
function o_get_settings(): array
{
	return (array) apply_filters('o_get_settings', get_option(O_TEXTDOMAIN . '-settings', array()));
}

/**
 * Look up a single merchant's stored configuration. Falls back to the
 * default merchant if `$id` is omitted; falls back to the legacy
 * single-merchant settings shape if no merchants are configured yet
 * (which can happen on the very first request after upgrade, before the
 * admin_init migration has run).
 *
 * @param string|null $id
 * @return array{api_key:string, webhook_secret:string, staging:bool, id:string, label:string, payment_gateway:string, capture_events:array, webhook_events:array, maximum_days_in_future:int, description_shipping_okoskabet:string, description_shipping_private:string}
 */
function o_get_merchant(?string $id = null): array
{
	if (class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchants')) {
		$merchant = $id !== null
			? \okoskabet_woocommerce_plugin\Integrations\Merchants::get($id)
			: \okoskabet_woocommerce_plugin\Integrations\Merchants::get_default();

		if ($merchant) {
			return $merchant;
		}
	}

	// Pre-migration fallback. Mirrors the historic single-merchant shape so
	// callers always get a complete record.
	$legacy = o_get_settings();
	return array(
		'id'                              => 'default',
		'label'                           => __('Default merchant', O_TEXTDOMAIN),
		'api_key'                         => (string) ($legacy['_api_key']                        ?? ''),
		'webhook_secret'                  => (string) ($legacy['_webhook_secret']                 ?? ''),
		'staging'                         => ! empty($legacy['_staging_api']),
		'description_shipping_okoskabet'  => (string) ($legacy['_description_shipping_okoskabet'] ?? ''),
		'description_shipping_private'    => (string) ($legacy['_description_shipping_private']   ?? ''),
		'maximum_days_in_future'          => max(1, (int) ($legacy['_maximum_days_in_future']     ?? 3)),
		'payment_gateway'                 => (string) ($legacy['_payment_gateway']                ?? 'auto'),
		'capture_events'                  => (array)  ($legacy['_capture_events']                 ?? array('label_printed')),
		'webhook_events'                  => (array)  ($legacy['_webhook_events']                 ?? array('order_delivered')),
		'product_categories'              => array(),
		'product_tags'                    => array(),
		'priority'                        => 0,
	);
}

/**
 * Resolve the API base URL for a merchant record (staging vs prod).
 */
function o_merchant_api_url(array $merchant): string
{
	return ! empty($merchant['staging']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';
}

/**
 * Returns true if at least ONE configured merchant exposes a given
 * shipping method (e.g. `shed`, `home_delivery`). Used by the shipping-
 * methods registration to decide whether to add the method to
 * WooCommerce's list at all.
 *
 * Each merchant's `/configuration` response is cached separately. The
 * union across merchants is what matters: if any single merchant supports
 * the method, customers in WooCommerce should see the method (and the
 * specific merchant for any individual cart is resolved at checkout
 * time via `Merchant_Router`).
 */
function o_check_configuration(string $value, ?string $merchant_id = null): bool
{
	// When called for a specific merchant, only that merchant's
	// configuration is consulted. Used when validating per-cart
	// availability after routing.
	if ($merchant_id !== null) {
		return o_merchant_supports_method($merchant_id, $value);
	}

	if (class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchants')
		&& \okoskabet_woocommerce_plugin\Integrations\Merchants::has_any()) {

		foreach (\okoskabet_woocommerce_plugin\Integrations\Merchants::get_all() as $merchant) {
			if (o_merchant_supports_method($merchant['id'], $value)) {
				return true;
			}
		}
		return false;
	}

	// Pre-migration fallback: legacy single-merchant path.
	return o_merchant_supports_method('default', $value);
}

/**
 * The transient key the merchant's /configuration answer is cached under.
 *
 * One definition, because a reader and a writer that disagree about this
 * string produce a cache that can never be cleared — which is exactly the
 * bug this replaced.
 */
function o_shipping_methods_transient_key(string $merchant_id): string
{
	return O_TEXTDOMAIN . '_shipping_methods_' . sanitize_key($merchant_id);
}

/**
 * Internal — does THIS merchant's `/configuration` advertise the given
 * shipping method? Cached per-merchant for 5 minutes.
 */
function o_merchant_supports_method(string $merchant_id, string $method_code): bool
{
	// Answered once per request per merchant. Registration asks this for every
	// Økoskabet method, so without the static a page load repeats the same
	// lookup — and, when the API is unreachable, the same 10-second timeout —
	// once per method.
	static $asked = array();

	$transient_key = o_shipping_methods_transient_key($merchant_id);

	if (array_key_exists($merchant_id, $asked)) {
		$shipping_methods = $asked[$merchant_id];
		return is_array($shipping_methods) && ! empty($shipping_methods[$method_code]);
	}

	$shipping_methods = get_transient($transient_key);

	if ($shipping_methods === false) {
		$merchant = o_get_merchant($merchant_id);
		if (empty($merchant['api_key'])) {
			$asked[$merchant_id] = false;
			return false;
		}

		$response = wp_remote_get(o_merchant_api_url($merchant) . '/api/v1/configuration', array(
			'timeout' => 10,
			'headers' => array(
				'Authorization' => $merchant['api_key'],
			),
		));

		if (is_wp_error($response)) {
			$asked[$merchant_id] = false;
			return false;
		}

		$http_code = wp_remote_retrieve_response_code($response);
		$body      = wp_remote_retrieve_body($response);

		if ($http_code !== 200 || empty($body)) {
			$asked[$merchant_id] = false;
			return false;
		}

		$oko_configuration = json_decode($body, true);
		$shipping_methods  = array();
		if (! empty($oko_configuration['shipping_methods'])) {
			foreach ($oko_configuration['shipping_methods'] as $method) {
				$shipping_methods[$method['method_code']] = $method;
			}
		}
		set_transient($transient_key, $shipping_methods, 5 * MINUTE_IN_SECONDS);
	}

	$asked[$merchant_id] = $shipping_methods;

	return ! empty($shipping_methods[$method_code]);
}


function enqueue_checkout_scripts(): void
{
	if (is_checkout()) {
		wp_enqueue_script('mapbox-gl-js', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js', array(), '3.3.0', true);
		wp_enqueue_style('mapbox-gl-css', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css', array(), '3.3.0');

		wp_enqueue_script('okoskabet-shipping', plugin_dir_url(__DIR__) . 'assets/build/plugin-public.js', array(), O_VERSION, true);
		wp_enqueue_style('okoskabet-shipping', plugin_dir_url(__DIR__) . 'assets/build/plugin-public.css', array(), O_VERSION);
	}
}
add_action('wp_enqueue_scripts', 'enqueue_checkout_scripts');


add_action('woocommerce_review_order_after_shipping', 'custom_content_for_custom_shipping_checkout', 10);
function custom_content_for_custom_shipping_checkout(): void
{
	$settings = o_get_settings();

	// Resolve which merchant the current cart routes to so the JS-rendered
	// checkout UI shows the right descriptions and talks to the right
	// /sheds and /home_delivery endpoints. The router has already applied
	// the mixed-cart-falls-back-to-default policy at this point — every
	// cart resolves to exactly one merchant.
	$resolved = class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchant_Router')
		? \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::resolve_for_cart()
		: array('merchant_id' => '', 'merchant' => null, 'is_mixed' => false, 'fell_back_to_default' => false, 'merchant_ids' => array(), 'per_product' => array());

	$merchant = $resolved['merchant'] ?? null;

	// If we have no merchant we still want the legacy fallback so a fresh
	// install (where the migration hasn't fired yet) doesn't break.
	if (! $merchant) {
		$merchant = o_get_merchant();
	}

	if (empty($merchant['api_key'])) {
		return;
	}

	$shed_description  = ! empty($merchant['description_shipping_okoskabet']) ? $merchant['description_shipping_okoskabet'] : __('Chilled pickup location where you can collect your goods around the clock using a code.', O_TEXTDOMAIN);
	$local_description = ! empty($merchant['description_shipping_private'])   ? $merchant['description_shipping_private']   : __('Økoskabet delivers your goods to your door.', O_TEXTDOMAIN);

	$config = wp_json_encode(array(
		'locale'        => get_locale(),
		'displayOption' => $settings['_display_option'] ?? '',
		'descriptions'  => array(
			'homeDelivery' => $local_description,
			'shedDelivery' => $shed_description,
		),
		'merchant' => array(
			'id'                   => (string) ($merchant['id']    ?? ''),
			'label'                => (string) ($merchant['label'] ?? ''),
			'is_mixed'             => (bool)   ($resolved['is_mixed']             ?? false),
			'fell_back_to_default' => (bool)   ($resolved['fell_back_to_default'] ?? false),
		),
		'deliveryLocation' => array(
			'dropdownEnabled' => !empty($settings['_delivery_location_dropdown']),
			'dropdownLabel'   => !empty($settings['_delivery_location_dropdown_label'])
				? $settings['_delivery_location_dropdown_label']
				: __('Delivery location', O_TEXTDOMAIN),
			'noteLabel' => !empty($settings['_delivery_location_note_label'])
				? $settings['_delivery_location_note_label']
				: __('Note to the driver (optional)', O_TEXTDOMAIN),
			'hideWcOrderComments' => !empty($settings['_hide_wc_order_comments']),
		),
		'endpoints' => array(
			// Endpoints accept `merchant_id` and/or `product_ids` so the
			// JS can either rely on cart routing or pin a request.
			'deliveryLocationOptions' => get_rest_url(null, 'wp/v2/okoskabet/delivery_location_options'),
			'cartResolution'          => get_rest_url(null, 'wp/v2/okoskabet/cart_resolution'),
			// Store-pickup locations plus the days each one collects on.
			'storePickup'             => get_rest_url(null, 'wp/v2/okoskabet/store_pickup'),
		),
	), JSON_HEX_TAG | JSON_HEX_AMP);

	// Strings shown to the user are localised via PHP and injected as a
	// JSON object on window so translations work in the .po/.mo file.
	$overlay_strings = wp_json_encode(array(
		'helpText' => __('You can remove one or more of the marked items from your cart to get more delivery options, or contact us for help.', O_TEXTDOMAIN),
		// The placeholder text below MUST match what Svelte renders so we
		// can find and replace it. Don't translate it without also rebuilding
		// the Svelte bundle to emit the same translated text.
		'placeholderText' => 'Ingen tilgængelige datoer.',
		// Shown to the customer when the placeholder is visible AND no
		// Delivery_Exceptions explanation kicked in — typically means the
		// merchant's display window doesn't reach far enough into the
		// future for any of the product's delivery rules, so the API
		// genuinely returned no dates. We can't recover automatically;
		// the right action is for the customer to reach the shop owner.
		'noDatesHeading' => __('No delivery dates available right now', O_TEXTDOMAIN),
		'noDatesBody'    => __('We can\'t find a delivery date for the products in your cart at this time. Please contact the shop so we can help you complete the order — sometimes it\'s a temporary configuration issue we can resolve quickly.', O_TEXTDOMAIN),
	), JSON_HEX_TAG | JSON_HEX_AMP);

	// Enqueue the external checkout-helpers.js file. Both the overlay
	// (exception explanation) module and the delivery-location dropdown
	// module live there. Configuration objects are passed via two
	// window globals injected via wp_add_inline_script — this keeps the
	// .js file static and cacheable while still letting PHP control all
	// translatable strings and merchant-configurable values.
	wp_register_script(
		'okoskabet-checkout-helpers',
		O_PLUGIN_ROOT_URL . 'assets/build/checkout-helpers.js',
		array(),
		O_VERSION,
		true
	);
	// Strings for the store-pickup UI. Opening hours are deliberately not
	// fetched from the API — Økoskabet holds the collection *days*, and what
	// time the shop is open is the shop's own business. A merchant writes it
	// in the shipping method's Description field, which WooCommerce already
	// renders under the method at checkout.
	$pickup_strings = wp_json_encode(array(
		'place'    => __('Pickup location', O_TEXTDOMAIN),
		'date'     => __('Pickup date', O_TEXTDOMAIN),
		'choose'   => __('— choose —', O_TEXTDOMAIN),
		'noPlaces' => __('No pickup locations have been set up. Please contact the shop.', O_TEXTDOMAIN),
		'noDates'  => __('No pickup dates are available right now. Please contact the shop.', O_TEXTDOMAIN),
	), JSON_HEX_TAG | JSON_HEX_AMP);

	wp_add_inline_script(
		'okoskabet-checkout-helpers',
		'window._okoskabet_checkout = ' . $config . ';' . "\n"
		. 'window._okoskabet_overlay_strings = ' . $overlay_strings . ';' . "\n"
		. 'window._okoskabet_pickup_strings = ' . $pickup_strings . ';',
		'before'
	);
	wp_enqueue_script('okoskabet-checkout-helpers');

	// Emit a hidden input listing the product IDs currently in the cart, so
	// the Svelte frontend can pass them to the home_delivery / sheds REST
	// endpoints. The endpoints use the IDs to apply Delivery_Exceptions
	// without depending on WooCommerce session state — which is unreliable
	// in REST context (cookies aren't always sent).
	$product_ids = array();
	if (function_exists('WC') && WC()->cart) {
		foreach (WC()->cart->get_cart() as $cart_item) {
			if (!empty($cart_item['product_id'])) {
				$product_ids[] = (int) $cart_item['product_id'];
			}
		}
	}
	echo '<input type="hidden" id="okoskabet-cart-product-ids" value="' . esc_attr(implode(',', array_unique($product_ids))) . '" />';

	// CSS: hide WooCommerce-rendered billing input fields — our JS injects
	// the visible UI dynamically. We hide only the labels and inputs, not the
	// wrapper, so our injected UI inside the wrapper remains visible.
	echo '<style>
		/* The pickup-location field is written by the checkout JS, never typed
		   into, so the raw WooCommerce input must not be shown. */
		#billing_okoskabet_pickup_location_id_field { display: none !important; }
		.okoskabet-delivery-location > label,
		.okoskabet-delivery-location > .woocommerce-input-wrapper > input,
		.okoskabet-delivery-note > label,
		.okoskabet-delivery-note > .woocommerce-input-wrapper > input {
			display: none !important;
		}
	</style>';
}



add_filter('woocommerce_shipping_methods', 'hey_register_okoskabet_shipping_shed_method');
function hey_register_okoskabet_shipping_shed_method(array $methods): array
{
	if (empty(o_check_configuration('shed'))) return $methods;
	$methods['hey_okoskabet_shipping_shed'] = 'WC_Hey_Okoskabet_Shipping_Method_Shed';
	return $methods;
}

/**
 * The instance-settings fields shared by every Økoskabet shipping method:
 * a free-form fee ladder plus the VAT-mode toggle, followed by the legacy
 * single-tier fields that still act as a fallback when the ladder is empty.
 *
 * @param string $default_title
 * @param string $default_cost  Fallback price when no ladder is configured.
 *                              Methods that ship something use '49'; store
 *                              pickup ships nothing and passes '0'. Method title default.
 * @return array<string,array>
 */
function oko_shipping_instance_fields(string $default_title, string $default_cost = '49'): array
{
	return array(
		'title' => array(
			'title'       => esc_html__('Method Title', O_TEXTDOMAIN),
			'type'        => 'text',
			'description' => esc_html__('Enter the method title', O_TEXTDOMAIN),
			'default'     => $default_title,
			'desc_tip'    => true,
		),
		'description' => array(
			'title'       => esc_html__('Description', O_TEXTDOMAIN),
			'type'        => 'textarea',
			'description' => esc_html__('Enter the Description', O_TEXTDOMAIN),
			'default'     => '',
			'desc_tip'    => true,
		),
		'tiers' => array(
			'title'       => esc_html__('Fee ladder', O_TEXTDOMAIN),
			'type'        => 'textarea',
			'description' => esc_html__('One tier per line as "from amount = price". The highest tier whose "from" is not above the cart subtotal wins; price 0 means free. Example: 0 = 99 / 500 = 69 / 1000 = 49 / 2000 = 0. Leave empty to use the single-tier fields below.', O_TEXTDOMAIN),
			'default'     => '',
			'desc_tip'    => false,
		),
		'amounts_include_tax' => array(
			'title'       => esc_html__('VAT', O_TEXTDOMAIN),
			'type'        => 'checkbox',
			'label'       => esc_html__('Ladder thresholds and prices are incl. VAT (what the customer sees and pays)', O_TEXTDOMAIN),
			'default'     => 'yes',
		),
		'cost' => array(
			'title'       => esc_html__('Shipping price (fallback)', O_TEXTDOMAIN),
			'type'        => 'number',
			'description' => esc_html__('Used only when the fee ladder above is empty.', O_TEXTDOMAIN),
			// Methods that actually ship something default to a real price;
			// store pickup ships nothing and passes '0'.
			'default'     => $default_cost,
			'desc_tip'    => true,
		),
		'costDiscountLimit' => array(
			'title'       => esc_html__('Discounted shipping order minimum (fallback)', O_TEXTDOMAIN),
			'type'        => 'number',
			'default'     => '0',
			'desc_tip'    => true,
		),
		'costDiscount' => array(
			'title'       => esc_html__('Discounted shipping price (fallback)', O_TEXTDOMAIN),
			'type'        => 'number',
			'default'     => '0',
			'desc_tip'    => true,
		),
		'costFreeLimit' => array(
			'title'       => esc_html__('Free shipping order minimum (fallback)', O_TEXTDOMAIN),
			'type'        => 'number',
			'default'     => '0',
			'desc_tip'    => true,
		),
	);
}

/**
 * Parse a fee ladder from the textarea. Each non-empty line is
 * "from_subtotal = price" (the separator may be '=', ':', ',' or whitespace).
 * Returns tiers sorted ascending by their "from" threshold.
 *
 * @return array<int,array{from:float,cost:float}>
 */
function oko_parse_shipping_tiers(string $raw): array
{
	$tiers = array();
	$lines = preg_split('/\r\n|\r|\n/', $raw);
	if ($lines === false) {
		return $tiers;
	}
	foreach ($lines as $line) {
		$line = trim($line);
		if ($line === '') {
			continue;
		}
		// Separators: '=', ':' or whitespace. NOT comma — in Danish that's the
		// decimal mark (e.g. "49,50"), which we normalise below.
		$parts = preg_split('/[\s=:]+/', $line);
		if ($parts === false || count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
			continue;
		}
		$from = str_replace(',', '.', $parts[0]);
		$cost = str_replace(',', '.', $parts[1]);
		// Both halves must actually be numbers. Casting blindly would turn a
		// typo into `from 0 = free`, quietly giving every order free shipping.
		if (!is_numeric($from) || !is_numeric($cost)) {
			continue;
		}
		$tiers[] = array(
			'from' => (float) $from,
			'cost' => (float) $cost,
		);
	}
	usort($tiers, static function ($a, $b) {
		return $a['from'] <=> $b['from'];
	});
	return $tiers;
}

/**
 * Pick the fee for a subtotal: the cost of the highest tier whose "from" is
 * not above the subtotal. Falls back to the lowest tier when the subtotal is
 * below every threshold, so a rate is always produced.
 */
function oko_ladder_cost_for_subtotal(array $tiers, float $subtotal): ?float
{
	if (empty($tiers)) {
		return null;
	}
	$cost = $tiers[0]['cost'];
	foreach ($tiers as $tier) {
		if ($subtotal >= $tier['from']) {
			$cost = $tier['cost'];
		}
	}
	return $cost;
}

/** The combined shipping tax ratio (e.g. 0.25), or 0 when shipping isn't taxed. */
function oko_shipping_tax_ratio(): float
{
	if (!function_exists('wc_tax_enabled') || !wc_tax_enabled()) {
		return 0.0;
	}
	$total = 0.0;
	foreach (WC_Tax::get_shipping_tax_rates() as $rate) {
		$total += (float) $rate['rate'];
	}
	return $total / 100.0;
}

/**
 * Apply the N-tier fee ladder for a method, if one is configured. Returns
 * true when it handled the rate (caller should stop), false when no ladder is
 * set so the caller falls back to the legacy single-tier logic.
 */
function oko_add_ladder_shipping_rate(WC_Shipping_Method $method, array $package): bool
{
	$tiers = oko_parse_shipping_tiers((string) $method->get_option('tiers'));
	if (empty($tiers)) {
		return false; // not configured → legacy path.
	}

	$title      = rtrim((string) $method->get_option('title'), ': ');
	$free_label = $title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')';

	// A free-shipping coupon always wins.
	foreach (WC()->cart->get_applied_coupons() as $code) {
		$coupon = new WC_Coupon($code);
		if ($coupon->get_free_shipping()) {
			$method->add_rate(array('id' => $method->id, 'label' => $free_label, 'cost' => 0));
			return true;
		}
	}

	$include_tax = wc_string_to_bool($method->get_option('amounts_include_tax', 'yes'));

	// Subtotal both ex- and incl-VAT, so thresholds can compare against whichever
	// the merchant entered.
	$ex = 0.0;
	$incl = 0.0;
	foreach ($package['contents'] as $values) {
		$line = (float) $values['line_total'];
		$ex  += $line;
		$incl += $line + (float) ($values['line_tax'] ?? 0);
	}

	$cost = oko_ladder_cost_for_subtotal($tiers, $include_tax ? $incl : $ex);

	if ($cost === null || $cost <= 0) {
		$method->add_rate(array('id' => $method->id, 'label' => $free_label, 'cost' => 0));
		return true;
	}

	// When the amount is entered incl. VAT, hand WooCommerce the ex-VAT cost so
	// it adds tax back to exactly the figure the merchant typed.
	$rate_cost = $cost;
	if ($include_tax) {
		$ratio = oko_shipping_tax_ratio();
		if ($ratio > 0) {
			$rate_cost = $cost / (1 + $ratio);
		}
	}

	$method->add_rate(array('id' => $method->id, 'label' => $title, 'cost' => $rate_cost));
	return true;
}

function hey_okoskabet_shipping_method_shed_init(): void
{
	if (empty(o_check_configuration('shed'))) return;

	if (!class_exists('WC_Hey_Okoskabet_Shipping_Method_Shed')) {
		class WC_Hey_Okoskabet_Shipping_Method_Shed extends WC_Shipping_Method
		{
			protected string $cost_value = '0';
			protected string $cost_discount = '0';
			protected string $cost_discount_limit = '0';
			protected string $cost_free_limit = '0';

			public function __construct($instance_id = 0)
			{
				$this->id                    = 'hey_okoskabet_shipping_shed';
				$this->instance_id           = absint($instance_id);
				$this->method_title       = __('Økoskabet', O_TEXTDOMAIN);
				$this->method_description = __('Delivery to Økoskabet', O_TEXTDOMAIN);
				$this->supports              = array(
					'shipping-zones',
					'instance-settings',
					'instance-settings-modal',
				);
				$this->instance_form_fields = oko_shipping_instance_fields($this->method_title);

				$this->cost_value          = $this->get_option('cost');
				$this->cost_discount       = $this->get_option('costDiscount');
				$this->cost_discount_limit = $this->get_option('costDiscountLimit');
				$this->cost_free_limit     = $this->get_option('costFreeLimit');
				$this->title               = rtrim($this->get_option('title'), ': ');
				add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
			}

			/**
			 * @param array $package (default: array())
			 */
			public function calculate_shipping($package = array()): void
			{
				// Prefer the configurable N-tier fee ladder; fall back to the
				// legacy single-tier fields below when no ladder is configured.
				if (oko_add_ladder_shipping_rate($this, $package)) {
					return;
				}

				$total = 0;
				foreach ($package['contents'] as $values) {
					$total += $values['line_total'];
				}

				$applied_coupons = WC()->cart->get_applied_coupons();

				foreach ($applied_coupons as $coupon_code) {
					$coupon = new WC_Coupon($coupon_code);
					if ($coupon->get_free_shipping()) {
						$this->add_rate(array(
							'id'    => $this->id,
							'label' => $this->title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')',
							'cost'  => 0,
						));
						return;
					}
				}

				if (!empty($this->cost_free_limit) && $total >= (float) $this->cost_free_limit) {
					$this->add_rate(array(
						'id'    => $this->id,
						'label' => $this->title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')',
						'cost'  => 0,
					));
					return;
				}

				if (!empty($this->cost_discount_limit) && $total >= (float) $this->cost_discount_limit) {
					$this->add_rate(array(
						'id'    => $this->id,
						'label' => $this->title . ' (' . esc_html__('Discounted shipping rate', O_TEXTDOMAIN) . ')',
						'cost'  => $this->cost_discount,
					));
					return;
				}

				$this->add_rate(array(
					'id'    => $this->id,
					'label' => $this->title,
					'cost'  => $this->cost_value,
				));
			}
		}
	}
}
add_action('woocommerce_shipping_init', 'hey_okoskabet_shipping_method_shed_init');

add_filter('woocommerce_shipping_methods', 'hey_register_okoskabet_shipping_home_method');
function hey_register_okoskabet_shipping_home_method(array $methods): array
{
	if (empty(o_check_configuration('home_delivery'))) return $methods;
	$methods['hey_okoskabet_shipping_home'] = 'WC_Hey_Okoskabet_Shipping_Method_Home';
	return $methods;
}

function hey_okoskabet_shipping_method_home_init(): void
{
	if (empty(o_check_configuration('home_delivery'))) return;

	if (!class_exists('WC_Hey_Okoskabet_Shipping_Method_Home')) {
		class WC_Hey_Okoskabet_Shipping_Method_Home extends WC_Shipping_Method
		{
			protected string $cost_value = '0';
			protected string $cost_discount = '0';
			protected string $cost_discount_limit = '0';
			protected string $cost_free_limit = '0';

			public function __construct($instance_id = 0)
			{
				$this->id                    = 'hey_okoskabet_shipping_home';
				$this->instance_id           = absint($instance_id);
				$this->method_title       = __('Home delivery', O_TEXTDOMAIN);
				$this->method_description = __('Delivery to your home', O_TEXTDOMAIN);
				$this->supports              = array(
					'shipping-zones',
					'instance-settings',
					'instance-settings-modal',
				);
				$this->instance_form_fields = oko_shipping_instance_fields($this->method_title);

				$this->cost_value          = $this->get_option('cost');
				$this->cost_discount       = $this->get_option('costDiscount');
				$this->cost_discount_limit = $this->get_option('costDiscountLimit');
				$this->cost_free_limit     = $this->get_option('costFreeLimit');
				$this->title               = rtrim($this->get_option('title'), ': ');
				add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
			}

			/**
			 * @param array $package (default: array())
			 */
			public function calculate_shipping($package = array()): void
			{
				// Prefer the configurable N-tier fee ladder; fall back to the
				// legacy single-tier fields below when no ladder is configured.
				if (oko_add_ladder_shipping_rate($this, $package)) {
					return;
				}

				$total = 0;
				foreach ($package['contents'] as $values) {
					$total += $values['line_total'];
				}

				$applied_coupons = WC()->cart->get_applied_coupons();

				foreach ($applied_coupons as $coupon_code) {
					$coupon = new WC_Coupon($coupon_code);
					if ($coupon->get_free_shipping()) {
						$this->add_rate(array(
							'id'    => $this->id,
							'label' => $this->title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')',
							'cost'  => 0,
						));
						return;
					}
				}

				if (!empty($this->cost_free_limit) && $total >= (float) $this->cost_free_limit) {
					$this->add_rate(array(
						'id'    => $this->id,
						'label' => $this->title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')',
						'cost'  => 0,
					));
					return;
				}

				if (!empty($this->cost_discount_limit) && $total >= (float) $this->cost_discount_limit) {
					$this->add_rate(array(
						'id'    => $this->id,
						'label' => $this->title . ' (' . esc_html__('Discounted shipping rate', O_TEXTDOMAIN) . ')',
						'cost'  => $this->cost_discount,
					));
					return;
				}

				$this->add_rate(array(
					'id'    => $this->id,
					'label' => $this->title,
					'cost'  => $this->cost_value,
				));
			}
		}
	}
}
add_action('woocommerce_shipping_init', 'hey_okoskabet_shipping_method_home_init');

add_filter('woocommerce_shipping_methods', 'hey_register_okoskabet_shipping_store_pickup_method');
function hey_register_okoskabet_shipping_store_pickup_method(array $methods): array
{
	if (empty(o_check_configuration('store_pickup'))) return $methods;
	$methods['hey_okoskabet_shipping_store_pickup'] = 'WC_Hey_Okoskabet_Shipping_Method_Store_Pickup';
	return $methods;
}

/**
 * Butiksafhentning — the customer collects from the shop itself.
 *
 * Free by default: the shop keeps the goods, so there is nothing to ship.
 * The fee-ladder fields are still offered because a merchant may want to
 * charge for a collection, and an empty ladder falls through to the legacy
 * single-tier cost, which defaults to 0.
 */
function hey_okoskabet_shipping_method_store_pickup_init(): void
{
	if (empty(o_check_configuration('store_pickup'))) return;

	if (!class_exists('WC_Hey_Okoskabet_Shipping_Method_Store_Pickup')) {
		class WC_Hey_Okoskabet_Shipping_Method_Store_Pickup extends WC_Shipping_Method
		{
			protected string $cost_value = '0';

			public function __construct($instance_id = 0)
			{
				$this->id                 = 'hey_okoskabet_shipping_store_pickup';
				$this->instance_id        = absint($instance_id);
				$this->method_title       = __('Store pickup', O_TEXTDOMAIN);
				$this->method_description = __('The customer collects the order in your shop', O_TEXTDOMAIN);
				$this->supports           = array(
					'shipping-zones',
					'instance-settings',
					'instance-settings-modal',
				);
				$this->instance_form_fields = oko_shipping_instance_fields($this->method_title, '0');

				$this->cost_value = (string) $this->get_option('cost', '0');
				$this->title      = rtrim((string) $this->get_option('title'), ': ');
				if ($this->title === '') {
					$this->title = $this->method_title;
				}
				add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
			}

			/**
			 * @param array $package (default: array())
			 */
			public function calculate_shipping($package = array()): void
			{
				// A configured ladder wins, so a merchant who wants to charge
				// for collection can. Otherwise collection is free.
				if (oko_add_ladder_shipping_rate($this, $package)) {
					return;
				}

				$cost = (float) $this->cost_value;
				$this->add_rate(array(
					'id'    => $this->id,
					'label' => $cost > 0 ? $this->title : $this->title . ' (' . esc_html__('Free', O_TEXTDOMAIN) . ')',
					'cost'  => $cost,
				));
			}
		}
	}
}
add_action('woocommerce_shipping_init', 'hey_okoskabet_shipping_method_store_pickup_init');

/**
 * Filter shipping rates per package so a cart only ever sees Økoskabet
 * methods that the cart's resolved merchant actually supports.
 *
 * Background: shipping methods (`hey_okoskabet_shipping_shed` and
 * `hey_okoskabet_shipping_home`) are registered globally as soon as ANY
 * configured merchant exposes them — see `o_check_configuration()`. That
 * design works in single-merchant mode but in multi-merchant mode it
 * surfaces methods the resolved merchant can't fulfil, leaving the
 * customer with a "select delivery date" prompt and no available dates.
 *
 * This filter runs per shipping package (so it has cart context, unlike
 * the global registration) and prunes Økoskabet methods whose underlying
 * Økoskabet shipping method is not supported by the merchant the cart
 * routes to. Non-Økoskabet rates are left untouched. The merchant
 * configuration cache (`o_merchant_supports_method` uses a 5-minute
 * transient) keeps this cheap.
 */
add_filter('woocommerce_package_rates', function (array $rates, array $package): array {
    if (!class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchant_Router')) {
        return $rates;
    }

    $product_ids = array();
    if (!empty($package['contents']) && is_array($package['contents'])) {
        foreach ($package['contents'] as $item) {
            if (!empty($item['product_id'])) {
                $product_ids[] = (int) $item['product_id'];
            }
        }
    }

    if (empty($product_ids)) {
        return $rates;
    }

    $resolved    = \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::resolve_for_products($product_ids);
    $merchant_id = $resolved['merchant_id'] ?? '';
    if ($merchant_id === '') {
        return $rates;
    }

    $oko_method_map = array(
        'hey_okoskabet_shipping_shed'         => 'shed',
        'hey_okoskabet_shipping_home'         => 'home_delivery',
        'hey_okoskabet_shipping_store_pickup' => 'store_pickup',
    );

    foreach ($rates as $rate_id => $rate) {
        $method_id = isset($rate->method_id) ? (string) $rate->method_id : '';
        if (!isset($oko_method_map[$method_id])) {
            continue;
        }
        $oko_method = $oko_method_map[$method_id];
        if (!o_merchant_supports_method($merchant_id, $oko_method)) {
            unset($rates[$rate_id]);
        }
    }

    return $rates;
}, 10, 2);


/* =========================================================================
 * Packaging-fee helpers
 * =========================================================================
 *
 * The fee itself lives in Integrations\Packaging_Fee; these two helpers sit
 * here beside the shipping ladder they share numbers with.
 */

/**
 * Normalise a saved term selection into the full set of term ids that should
 * match, descendants included.
 *
 * Picking "Frost" and getting nothing when the products actually sit in
 * "Frost > Fisk" is the kind of surprise a merchant discovers from a customer
 * complaint, so a chosen category always brings its children along.
 *
 * @param mixed  $saved
 * @param string $taxonomy
 * @return array<int,int>
 */
function oko_packaging_fee_term_ids($saved, string $taxonomy): array
{
	$ids = array();
	foreach ((array) $saved as $id) {
		$id = (int) $id;
		if ($id <= 0) {
			continue;
		}
		$ids[$id] = $id;
		$children = get_term_children($id, $taxonomy);
		if (is_array($children)) {
			foreach ($children as $child) {
				$ids[(int) $child] = (int) $child;
			}
		}
	}
	return array_values($ids);
}

/** The combined tax ratio (e.g. 0.25) for a fee tax class, or 0 when untaxed. */
function oko_fee_tax_ratio(string $tax_class): float
{
	if (!function_exists('wc_tax_enabled') || !wc_tax_enabled()) {
		return 0.0;
	}
	$total = 0.0;
	foreach (WC_Tax::get_rates($tax_class) as $rate) {
		$total += (float) $rate['rate'];
	}
	return $total / 100.0;
}

add_filter('woocommerce_checkout_fields', 'custom_override_checkout_fields');

function custom_override_checkout_fields(array $fields): array
{
	$fields['billing']['billing_okoskabet_shed_id'] = array(
		'label'       => __('Økoskabet ID', O_TEXTDOMAIN),
		'placeholder' => '',
		'required'    => false,
		'class'       => array('okoskabet-shed-id form-row-wide'),
		'clear'       => true
	);

	$fields['billing']['billing_okoskabet_delivery_date'] = array(
		'label'       => __('Økoskabet Delivery Date', O_TEXTDOMAIN),
		'placeholder' => '',
		'required'    => false,
		'class'       => array('okoskabet-delivery-date form-row-wide'),
		'clear'       => true
	);

	$fields['billing']['billing_okoskabet_pickup_location_id'] = array(
		'label'       => __('Pickup location', O_TEXTDOMAIN),
		'placeholder' => '',
		'required'    => false,
		'class'       => array('okoskabet-pickup-location-id form-row-wide'),
		'clear'       => true,
	);

	$fields['billing']['billing_okoskabet_delivery_location'] = array(
		'label'       => __('Delivery location', O_TEXTDOMAIN),
		'placeholder' => '',
		'required'    => false,
		'class'       => array('okoskabet-delivery-location form-row-wide'),
		'clear'       => true,
	);

	$fields['billing']['billing_okoskabet_delivery_note'] = array(
		'label'       => __('Note to the driver (optional)', O_TEXTDOMAIN),
		'placeholder' => '',
		'required'    => false,
		'class'       => array('okoskabet-delivery-note form-row-wide'),
		'clear'       => true,
	);

	return $fields;
}

add_action('woocommerce_admin_order_data_after_shipping_address', 'my_custom_checkout_field_display_admin_order_meta', 10, 1);
function my_custom_checkout_field_display_admin_order_meta($order): void
{
	$order_done = $order->get_meta('billing_okoskabet_done', true);
	$shed_id = $order->get_meta('_billing_okoskabet_shed_id', true);
	$delivery_date = $order->get_meta('_billing_okoskabet_delivery_date', true);
	$delivery_location = $order->get_meta('_billing_okoskabet_delivery_location', true);
	$delivery_note = $order->get_meta('_billing_okoskabet_delivery_note', true);
	$pickup_location_id = $order->get_meta('_billing_okoskabet_pickup_location_id', true);

	// Resolve the merchant that fulfilled the order. `resolve_for_order`
	// prefers the stored stamp written at checkout, and only falls back
	// to line-item resolution if the stamp is missing — which lines up
	// with the source of truth used by webhook routing and the
	// admin's mental model of "which Økoskabet account got this order".
	$merchant_id    = '';
	$merchant_label = '';
	if (class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchant_Router')
		&& $order instanceof \WC_Order) {
		$resolved       = \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::resolve_for_order($order);
		$merchant_id    = (string) ($resolved['merchant_id'] ?? '');
		$merchant_label = (string) ($resolved['merchant']['label'] ?? '');
	}

	echo '<pre>';
	if ($merchant_id !== '') {
		// Show the label (what humans recognise) plus the slug in code
		// formatting (what webhook URLs and order meta carry). Useful
		// when triaging webhook failures against Økoskabet's dashboard.
		$line = esc_html__('Økoskabet merchant', O_TEXTDOMAIN) . ': ';
		if ($merchant_label !== '' && $merchant_label !== $merchant_id) {
			$line .= esc_html($merchant_label) . ' (' . esc_html($merchant_id) . ')';
		} else {
			$line .= esc_html($merchant_id);
		}
		echo $line . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
	if (!empty($order_done)) {
		echo 'Økoskabet Done' . ': ' . esc_html($order_done) . "\n";
	}
	if (!empty($shed_id)) {
		echo 'Økoskabet SHED ID' . ': ' . esc_html($shed_id) . "\n";
	}
	if (!empty($delivery_date)) {
		echo 'Økoskabet Delivery Date' . ': ' . esc_html($delivery_date) . "\n";
	}
	if (!empty($pickup_location_id)) {
		echo esc_html__('Økoskabet pickup location', O_TEXTDOMAIN) . ': ' . esc_html($pickup_location_id) . "\n";
	}
	if (!empty($delivery_location)) {
		echo esc_html__('Økoskabet Delivery location', O_TEXTDOMAIN) . ': ' . esc_html($delivery_location) . "\n";
	}
	if (!empty($delivery_note)) {
		echo esc_html__('Note to driver', O_TEXTDOMAIN) . ': ' . esc_html($delivery_note);
	}
	echo '</pre>';
}


add_action('woocommerce_order_status_changed', 'hey_after_order_placed', 10, 4);

/**
 * @param int    $order_id   The order ID.
 * @param string $old_status Previous status.
 * @param string $new_status New status.
 * @param \WC_Order $order   The order object.
 */
function hey_after_order_placed(int $order_id, string $old_status, string $new_status, \WC_Order $order): void
{
	$order_number = $order->get_order_number();

	// Resolve the merchant that should handle this order. We look at the
	// order meta first (stamped at checkout_create_order time), falling
	// back to a routing pass over the order's items. This keeps existing
	// orders working after upgrade — they get resolved to the default
	// merchant via Merchant_Router::resolve_for_order.
	$merchant = null;
	if (class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchant_Router')) {
		$resolved = \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::resolve_for_order($order);
		$merchant = $resolved['merchant'] ?? null;
	}
	if (! $merchant) {
		$merchant = o_get_merchant();
	}

	if (empty($merchant['api_key'])) {
		error_log("okoskabet_woocommerce_plugin: API key not set for order {$order_number}, merchant=" . ($merchant['id'] ?? '?'));
		return;
	}

	$api_url = o_merchant_api_url($merchant);
	$api_key = (string) $merchant['api_key'];

	if ($new_status === 'cancelled') {
		$url = $api_url . '/api/v1/shipments/' . $order_number;

		$response = wp_remote_request($url, array(
			'method'  => 'DELETE',
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => $api_key,
			),
		));

		if (is_wp_error($response)) {
			error_log('okoskabet_woocommerce_plugin: Error deleting order ' . $order_number . ': ' . $response->get_error_message());
			return;
		}

		$http_code = wp_remote_retrieve_response_code($response);
		if ($http_code !== 204) {
			error_log('okoskabet_woocommerce_plugin: Error trying to delete order ' . $order_number . ', response(' . $http_code . '): ' . wp_remote_retrieve_body($response));
		}
	}

	if ($new_status === 'on-hold' || $new_status === 'processing') {
		$order_submitted = $order->get_meta('billing_okoskabet_done', true);
		if (!empty($order_submitted)) {
			return;
		}

		if (empty($order->get_transaction_id()) && !empty($order->get_total()) && $order->get_total() > 0) {
			error_log("okoskabet_woocommerce_plugin: Missing transaction id. Not submitting order to Økoskabet");
			return;
		}

		$order_number = $order->get_order_number();
		$order_shed = $order->get_meta('_billing_okoskabet_shed_id', true);
		$order_delivery_date = $order->get_meta('_billing_okoskabet_delivery_date', true);
		// _billing_okoskabet_delivery_location stores the English label (from dropdown).
		$order_delivery_location = $order->get_meta('_billing_okoskabet_delivery_location', true);
		// _billing_okoskabet_delivery_note stores the free-text note from the customer.
		$order_delivery_note = $order->get_meta('_billing_okoskabet_delivery_note', true);

		// Build the logistics note sent to Økoskabet's API.
		// Always in English: combine dropdown selection and free-text note.
		$logistics_note_parts = array();
		if (!empty($order_delivery_location)) {
			$logistics_note_parts[] = $order_delivery_location; // Already stored in English.
		}
		if (!empty($order_delivery_note)) {
			$logistics_note_parts[] = $order_delivery_note;
		}
		$logistics_note = implode(' — ', $logistics_note_parts);

		if (empty($order_delivery_date)) {
			return;
		}

		$is_shed_delivery  = false;
		$is_store_pickup   = false;
		foreach ($order->get_shipping_methods() as $shipping_method) {
			$method_id = $shipping_method->get_method_id();
			if ($method_id === 'hey_okoskabet_shipping_shed') {
				$is_shed_delivery = true;
				break;
			}
			if ($method_id === 'hey_okoskabet_shipping_store_pickup') {
				$is_store_pickup = true;
				break;
			}
		}

		if (!$is_shed_delivery) {
			$order_shed = '';
		}

		// A store pickup has no delivery target at all — the shop keeps the
		// goods until the customer collects them — so it carries neither a
		// shed reservation nor a delivery address. What it does carry is the
		// place to collect from, chosen at checkout from the merchant's own
		// pickup locations.
		$order_pickup_location_id = $is_store_pickup
			? $order->get_meta('_billing_okoskabet_pickup_location_id', true)
			: '';

		$url = $api_url . '/api/v1/shipments/';

		// One shipment, three shapes. Everything every shipment carries is
		// built once; each type then adds only what makes it that type —
		// so a new key (like order_number) is added in one place, not three.
		$data = [
			'locale'             => get_locale(),
			'allow_invalid'      => true,
			'shipment_reference' => (string) $order_number,
			// The shop's own order id, so a support conversation has something
			// the merchant recognises. Økoskabet keeps two: the bare number
			// people search on, and the reference as the shop prints it (which
			// a numbering plugin may prefix, "#1042" and such). Write-only at
			// Økoskabet's end; shipment_reference (our stable key) is untouched.
			'webshop_order_number'    => (string) $order->get_id(),
			'webshop_order_reference' => (string) $order->get_order_number(),
			'customer'           => [
				'first_name' => $order->get_billing_first_name(),
				'last_name'  => $order->get_billing_last_name(),
				'phone'      => $order->get_billing_phone(),
				'email'      => $order->get_billing_email(),
			],
			'notes'              => (string) $order->get_customer_note(),
			'delivery_date'      => $order_delivery_date,
		];

		if ($is_store_pickup) {
			// A collection has no delivery target at all — the shop keeps the
			// goods — so it names the place to collect from instead.
			$data['shipment_type']      = 'store_pickup';
			$data['pickup_location_id'] = $order_pickup_location_id !== '' ? (int) $order_pickup_location_id : null;
		} elseif (!empty($order_shed)) {
			$data['reservation'] = [
				'shed_id'           => $order_shed,
				'max_duration_days' => 1,
			];
		} else {
			$recipient_name = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
			$data['customer']['recipient_name'] = $recipient_name;
			$data['home_delivery'] = array_filter([
				'recipient_name' => $recipient_name,
				'address_1'      => $order->get_shipping_address_1(),
				'address_2'      => $order->get_shipping_address_2(),
				'city'           => $order->get_shipping_city(),
				'postal_code'    => $order->get_shipping_postcode(),
				// Combined English location + free-text note, or null if empty.
				'location'       => !empty($logistics_note) ? $logistics_note : null,
			]);
			// Send the logistics note (dropdown selection + free-text) as the API
			// notes field so it appears in Økoskabet's Notes column. Falls back to
			// the standard WooCommerce customer note when no logistics note is set.
			if (!empty($logistics_note)) {
				$data['notes'] = $logistics_note;
			}
		}

		$response = wp_remote_post($url, array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => $api_key,
			),
			'body' => wp_json_encode($data),
		));

		if (is_wp_error($response)) {
			$order->update_status('failed', $response->get_error_message());
			throw new \Exception($response->get_error_message());
		}

		$http_code = wp_remote_retrieve_response_code($response);
		$shipment = json_decode(wp_remote_retrieve_body($response), true);

		if ($http_code !== 201) {
			$error_text = !empty($shipment['error_message']) ? $shipment['error_message'] : __("The order could not be completed", O_TEXTDOMAIN);
			$order->update_status('failed', $error_text);
			throw new \Exception($error_text);
		}

		$customer_note = $order->get_customer_note() ?: '';
		$oko_order_note = 'ØKOSKABET ' . $order_delivery_date;
		if ($is_store_pickup) {
			$oko_order_note .= ' ' . __('Store pickup', O_TEXTDOMAIN);
		} elseif (empty($order_shed)) {
			$oko_order_note .= ' Hjemmelevering';
		} else {
			$oko_order_note .= ' ' . $order_shed;
		}
		$order->set_customer_note($oko_order_note . "\n" . $customer_note, 0);

		$order->update_meta_data('billing_okoskabet_done', true);
		$order->save();
	}
}

add_action('woocommerce_after_checkout_validation', 'okoskabet_woocommerce_plugin_after_checkout_validation');

function okoskabet_woocommerce_plugin_after_checkout_validation(array $fields): void
{
	$shipping_method = $fields['shipping_method'][0] ?? '';

	if ($shipping_method === 'hey_okoskabet_shipping_shed') {
		if (empty($fields['billing_okoskabet_shed_id']) || empty($fields['billing_okoskabet_delivery_date'])) {
			wc_add_notice(__("Please select an Økoskab and Delivery date before submitting the order.", O_TEXTDOMAIN), 'error');
		}
	}
	if ($shipping_method === 'hey_okoskabet_shipping_home') {
		if (empty($fields['billing_okoskabet_delivery_date'])) {
			wc_add_notice(__("Please select a Delivery date before submitting the order.", O_TEXTDOMAIN), 'error');
		}
	}
	// A collection needs somewhere to collect from and a day to do it on.
	// Without both, the shipment would be created pointing nowhere.
	if ($shipping_method === 'hey_okoskabet_shipping_store_pickup') {
		if (empty($fields['billing_okoskabet_pickup_location_id']) || empty($fields['billing_okoskabet_delivery_date'])) {
			wc_add_notice(__("Please select a pickup location and a pickup date before submitting the order.", O_TEXTDOMAIN), 'error');
		}
	}
}

add_action('woocommerce_checkout_create_order', 'okoskabet_woocommerce_plugin_clear_shed_id_for_home_delivery', 10, 2);

function okoskabet_woocommerce_plugin_clear_shed_id_for_home_delivery($order, $data): void
{
	$shipping_methods = (array) ($data['shipping_method'] ?? array());

	if (in_array('hey_okoskabet_shipping_home', $shipping_methods, true)) {
		$order->update_meta_data('_billing_okoskabet_shed_id', '');
	}
}

/**
 * Stamp the resolved merchant ID on the order at the moment it's created.
 *
 * Doing this at create-time (rather than at status-changed time) means
 * the order is permanently bound to the merchant the customer routed to
 * at checkout, even if a later admin action changes the cart contents
 * of a saved order or a product's category mapping moves underneath us.
 *
 * Cart vs order fallback: at the regular checkout, WC()->cart holds the
 * canonical list of products. But for orders created programmatically —
 * REST API, admin "Add order", subscription renewals — the cart is
 * empty (or belongs to a different session) at hook-fire time. We fall
 * back to the order's own line items so the stamp is correct for every
 * code path that creates an order.
 */
add_action('woocommerce_checkout_create_order', 'okoskabet_woocommerce_plugin_stamp_merchant_on_order', 20, 2);

function okoskabet_woocommerce_plugin_stamp_merchant_on_order($order, $data): void
{
	if (! class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchant_Router')) {
		return;
	}

	$product_ids = array();
	if (function_exists('WC') && WC()->cart) {
		foreach (WC()->cart->get_cart() as $cart_item) {
			$pid = (int) ($cart_item['product_id'] ?? 0);
			if ($pid > 0) {
				$product_ids[] = $pid;
			}
		}
	}

	if (empty($product_ids) && $order instanceof \WC_Order) {
		foreach ($order->get_items() as $item) {
			if ($item instanceof \WC_Order_Item_Product) {
				$pid = (int) $item->get_product_id();
				if ($pid > 0) {
					$product_ids[] = $pid;
				}
			}
		}
	}

	// Derive the shipping zone the order is shipping into so a
	// zone-restricted merchant (e.g. an "Express" merchant that only
	// covers Copenhagen) only stays as the stamped merchant when the
	// destination actually falls inside its zones. The order is the
	// authoritative source here — the WC()->cart customer session is
	// unreliable for programmatic order creation.
	$zone_id = ($order instanceof \WC_Order)
		? \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::shipping_zone_id_for_order($order)
		: \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::current_shipping_zone_id();

	$resolved = \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::resolve_for_products($product_ids, $zone_id);
	$mid      = $resolved['merchant_id'] ?? '';
	if ($mid !== '') {
		$order->update_meta_data(
			\okoskabet_woocommerce_plugin\Integrations\Merchant_Router::ORDER_META_KEY,
			sanitize_key($mid)
		);
	}
}
