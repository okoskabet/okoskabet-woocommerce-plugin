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
function o_configuration_transient_key(string $merchant_id): string
{
	return O_TEXTDOMAIN . '_configuration_' . sanitize_key($merchant_id);
}

/** Kept under its old name: shops upgrade mid-request, and the old key is still in the table. */
function o_shipping_methods_transient_key(string $merchant_id): string
{
	return O_TEXTDOMAIN . '_shipping_methods_' . sanitize_key($merchant_id);
}

/**
 * What this merchant's Økoskabet account says about itself.
 *
 * One answer, cached for five minutes per merchant and memoised for the
 * request, because several things ask: which shipping methods exist, which
 * steps a shipment passes. Asking once means one timeout rather than one per
 * question when Økoskabet is unreachable.
 *
 * An empty array is the answer when the account has no key, when the call
 * fails, or when the body is not what we expect. Every caller has to cope with
 * that anyway — a shop whose checkout is being loaded while Økoskabet is down
 * still has to render.
 *
 * @param string $merchant_id
 * @return array<string,mixed>
 */
function oko_merchant_configuration(string $merchant_id): array
{
	static $asked = array();

	if (array_key_exists($merchant_id, $asked)) {
		return $asked[$merchant_id];
	}

	$transient_key = o_configuration_transient_key($merchant_id);
	$cached        = get_transient($transient_key);

	if (is_array($cached)) {
		$asked[$merchant_id] = $cached;
		return $cached;
	}

	$merchant = o_get_merchant($merchant_id);
	if (empty($merchant['api_key'])) {
		$asked[$merchant_id] = array();
		return array();
	}

	$response = wp_remote_get(o_merchant_api_url($merchant) . '/api/v1/configuration', array(
		'timeout' => 10,
		'headers' => array(
			'Authorization' => $merchant['api_key'],
		),
	));

	if (is_wp_error($response)
		|| wp_remote_retrieve_response_code($response) !== 200
		|| empty(wp_remote_retrieve_body($response))) {
		$asked[$merchant_id] = array();
		return array();
	}

	$configuration = json_decode(wp_remote_retrieve_body($response), true);
	if (! is_array($configuration)) {
		$configuration = array();
	}

	set_transient($transient_key, $configuration, 5 * MINUTE_IN_SECONDS);
	$asked[$merchant_id] = $configuration;

	return $configuration;
}

/**
 * The steps a shipment of this merchant's passes, as code => name.
 *
 * Read from the account rather than kept as a list here, so a step Økoskabet
 * adds later reaches the shop's own choices without a plugin release. The
 * fallback is for an account whose Økoskabet does not publish the list yet:
 * the steps that existed when this was written, so the settings screen is
 * never empty.
 *
 * @param string $merchant_id
 * @return array<string,string>
 */
function oko_merchant_statuses(string $merchant_id): array
{
	$published = oko_merchant_configuration($merchant_id)['statuses'] ?? array();

	$statuses = array();
	foreach ((array) $published as $status) {
		$code = isset($status['code']) ? (string) $status['code'] : '';
		if ($code === '') {
			continue;
		}
		$statuses[$code] = isset($status['label']) && (string) $status['label'] !== ''
			? (string) $status['label']
			: $code;
	}

	if (! empty($statuses)) {
		return $statuses;
	}

	return array(
		'registered'         => __('Registered', O_TEXTDOMAIN),
		'ready_for_dispatch' => __('Ready for dispatch', O_TEXTDOMAIN),
		'received'           => __('Received', O_TEXTDOMAIN),
		'in_shed'            => __('In shed', O_TEXTDOMAIN),
		'fulfilled'          => __('Fulfilled', O_TEXTDOMAIN),
		'removed'            => __('Removed', O_TEXTDOMAIN),
	);
}

/**
 * Internal — does THIS merchant's `/configuration` advertise the given
 * shipping method? Cached per-merchant for 5 minutes.
 */
function o_merchant_supports_method(string $merchant_id, string $method_code): bool
{
	$configuration = oko_merchant_configuration($merchant_id);

	foreach ((array) ($configuration['shipping_methods'] ?? array()) as $method) {
		if (isset($method['method_code']) && $method['method_code'] === $method_code) {
			return true;
		}
	}

	return false;
}


/**
 * The URL of a built checkout file, whose name carries a hash of its content
 * (see webpack.config.js). Page caches that strip ?ver= cannot serve a stale
 * copy of a file whose name changed. Falls back to the plain name, so a build
 * made before the hashing still loads.
 */
function oko_build_asset_url(string $name, string $ext): string
{
	static $found = array();
	$key = $name . '.' . $ext;
	if (! isset($found[$key])) {
		$matches     = glob(O_PLUGIN_ROOT . 'assets/build/' . $name . '.*.' . $ext) ?: array();
		$matches     = array_values(array_filter($matches, static fn(string $file): bool => substr($file, -10) !== '.asset.php'));
		$found[$key] = $matches ? basename($matches[0]) : $key;
	}
	return O_PLUGIN_ROOT_URL . 'assets/build/' . $found[$key];
}

function enqueue_checkout_scripts(): void
{
	if (is_checkout()) {
		wp_enqueue_script('mapbox-gl-js', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.js', array(), '3.3.0', true);
		wp_enqueue_style('mapbox-gl-css', 'https://api.mapbox.com/mapbox-gl-js/v3.3.0/mapbox-gl.css', array(), '3.3.0');

		wp_enqueue_script('okoskabet-shipping', oko_build_asset_url('plugin-public', 'js'), array(), O_VERSION, true);
		wp_enqueue_style('okoskabet-shipping', oko_build_asset_url('plugin-public', 'css'), array(), O_VERSION);
	}
}
add_action('wp_enqueue_scripts', 'enqueue_checkout_scripts');


add_action('woocommerce_review_order_after_shipping', 'custom_content_for_custom_shipping_checkout', 10);

/**
 * WooCommerce's own review-order hook — the way every shop has rendered
 * until now, and still the default.
 *
 * A checkout built on WooCommerce's stock templates reaches the delivery UI
 * through here and through nothing else, and gets exactly the markup it got
 * before. A theme that renders the UI itself turns this off with the filter
 * rather than by unhooking, so the two can never both run.
 */
function custom_content_for_custom_shipping_checkout(): void
{
	if (! apply_filters('oko_auto_render_delivery_ui', true)) {
		return;
	}

	oko_render_delivery_ui('table');
}

/**
 * Draw the delivery UI's mount point, wherever this checkout keeps it.
 *
 * The Svelte app that draws the date picker, the shed list and the map does
 * not care what the checkout is built with; it needs a place to mount and
 * the cart's product ids. Only this function knows about the surrounding
 * markup, which is why it is the one thing a builder has to be able to call.
 *
 * `$context` decides the wrapper, and nothing else:
 *   - `table` — inside WooCommerce's review-order table, so the pre-order
 *     buttons come out as a `<tr>`. The default, and what the hook above asks
 *     for, so the classic checkout is unchanged.
 *   - `block` — anywhere else: Bricks, Elementor, a theme template. Same
 *     content in a plain `<div>`, because a `<tr>` outside a table is dropped
 *     by the HTML parser before any of our JS ever sees it.
 *
 * Renders once per request. A shop that both leaves the hook on and places
 * the shortcode gets one UI, not two.
 */
function oko_render_delivery_ui(string $context = 'table'): void
{
	if (did_action(OKO_UI_DRAWN_ACTION)) {
		return;
	}

	$settings = o_get_settings();

	$resolved = oko_resolve_checkout_merchant();
	$merchant = $resolved['merchant'];

	if (empty($merchant['api_key'])) {
		return;
	}

	// Latched only once we are certain we are drawing. A shop without a key
	// renders nothing and stays free to render later in the same request, if
	// a key arrives — which is what a settings save inside checkout does.
	do_action(OKO_UI_DRAWN_ACTION);

	$shed_description  = ! empty($merchant['description_shipping_okoskabet']) ? $merchant['description_shipping_okoskabet'] : __('Chilled pickup location where you can collect your goods around the clock using a code.', O_TEXTDOMAIN);
	$local_description = ! empty($merchant['description_shipping_private'])   ? $merchant['description_shipping_private']   : __('Økoskabet delivers your goods to your door.', O_TEXTDOMAIN);

	$config = wp_json_encode(array(
		'locale'        => get_locale(),
		// Only 'inline' and 'modal' mean anything to the picker. An unset
		// option ('') is neither, which hides the locker picker with no way
		// to open it, so anything but 'modal' is inline.
		'displayOption' => ($settings['_display_option'] ?? '') === 'modal' ? 'modal' : 'inline',
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
		// Rate id → date setting, so the script can answer "does this rate
		// need a date?" from the selected radio's value alone, without
		// walking the markup around it. See oko_delivery_date_modes().
		'dateModes' => oko_delivery_date_modes(),
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
		oko_build_asset_url('checkout-helpers', 'js'),
		array(),
		O_VERSION,
		true
	);
	// Strings for the store-pickup UI. Opening hours are deliberately not
	// fetched from the API — Økoskabet holds the collection *days*, and what
	// time the shop is open is the shop's own business. A merchant writes it
	// in the shipping method's Description field, shown under the method at
	// checkout by oko_print_store_pickup_description().
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

	// Outside the review-order table there is no shipping row to sit beside,
	// so the shop's chosen spot is the anchor instead: the script puts the
	// delivery-location and store-pickup rows in here. Empty in a classic
	// checkout, where the table is a better anchor and is found first.
	if ($context !== 'table') {
		echo '<div class="okoskabet-delivery-mount"></div>';
	}

	// The way into a pre-order, and back out of it — only for a cart holding
	// something that can be pre-ordered.
	if (
		class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Delivery_Exceptions')
		&& \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::cart_has_pre_order_days($product_ids)
	) {
		// Both choices side by side, the current one marked with the theme's
		// primary button style — but only choices the basket actually has. A
		// basket held to a date further ahead than the ordinary delivery days
		// has no ordinary order to go back to, and a button that leads to an
		// empty date list is worse than no button.
		$pre_order  = oko_is_pre_order_checkout();
		$only_pre   = oko_cart_is_pre_order_only();
		$button     = '<button type="button" class="button okoskabet-pre-order-toggle%s" data-pre-order="%s" aria-pressed="%s">%s</button>';
		$notice     = $pre_order ? \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::pre_order_notice() : '';
		$normal_btn = $only_pre ? '' : sprintf($button, $pre_order ? '' : ' alt', '', $pre_order ? 'false' : 'true', esc_html(\okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::normal_order_label()));

		// Same row, two wrappers. Inside the review-order table it has to be a
		// `<tr>`; anywhere else a `<tr>` is thrown away by the parser before
		// the script can find it, so there it is a `<div>` carrying the same
		// class. The class is what the JS looks for, never the tag.
		$inner = sprintf(
			'<div class="okoskabet-order-type">%s%s%s</div>',
			$notice !== '' ? '<div class="okoskabet-pre-order-notice" style="grid-column:1/-1;box-sizing:border-box;padding:10px 12px;border:1px solid currentColor;font-weight:normal;font-size:0.9em;line-height:1.35;text-transform:none;">' . nl2br(esc_html($notice)) . '</div>' : '',
			$normal_btn,
			sprintf($button, $pre_order ? ' alt' : '', '1', $pre_order ? 'true' : 'false', esc_html(\okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::pre_order_label()))
		);

		if ($context === 'table') {
			printf('<tr class="okoskabet-pre-order-row" style="display:none"><td colspan="2">%s</td></tr>', $inner);
		} else {
			printf('<div class="okoskabet-pre-order-row" style="display:none">%s</div>', $inner);
		}
	}

	oko_print_checkout_layout_script((string) ($settings['_separate_shipping_label'] ?? ''));
	// CSS: hide WooCommerce-rendered billing input fields — our JS injects
	// the visible UI dynamically. We hide only the labels and inputs, not the
	// wrapper, so our injected UI inside the wrapper remains visible.
	echo '<style>
		/* The pickup-location field is written by the checkout JS, never typed
		   into, so the raw WooCommerce input must not be shown. */
		#billing_okoskabet_pickup_location_id_field { display: none !important; }
		#billing_okoskabet_pre_order_field { display: none !important; }
		.okoskabet-delivery-location > label,
		.okoskabet-delivery-location > .woocommerce-input-wrapper > input,
		.okoskabet-delivery-note > label,
		.okoskabet-delivery-note > .woocommerce-input-wrapper > input {
			display: none !important;
		}
	</style>';
}

/**
 * `[okoskabet_levering]` — the delivery UI, placed by the shop.
 *
 * A checkout that does not render WooCommerce's review-order template never
 * fires the hook above, and until now that meant no date picker, no shed
 * list and no explanation of why: the shipping methods still appeared, so
 * the checkout looked finished. Bricks' Checkout v2 is the case that found
 * this, but any builder that draws its own checkout has the same hole, and
 * so does WooCommerce's own block checkout.
 *
 * The shortcode is the way out that costs an existing shop nothing: it is
 * new, it is opt-in, and a shop that never places it keeps rendering through
 * the hook exactly as before. WordPress passes '' for $atts when the
 * shortcode has no attributes, hence the string.
 */
add_shortcode('okoskabet_levering', 'oko_delivery_ui_shortcode');
function oko_delivery_ui_shortcode(array|string $atts = array()): string
{
	$atts = shortcode_atts(array('context' => 'block'), (array) $atts, 'okoskabet_levering');

	ob_start();
	oko_render_delivery_ui($atts['context'] === 'table' ? 'table' : 'block');

	return (string) ob_get_clean();
}

/**
 * The same thing for a theme or a builder element that would rather call PHP
 * than place a shortcode: `oko_delivery_ui();` in the template.
 *
 * Pair it with `add_filter('oko_auto_render_delivery_ui', '__return_false')`
 * when the theme also leaves WooCommerce's review-order table in place, so
 * the UI is drawn where the theme wants it and nowhere else.
 */
function oko_delivery_ui(string $context = 'block'): void
{
	oko_render_delivery_ui($context === 'table' ? 'table' : 'block');
}

/**
 * `{do_action:okoskabet_levering}` — the same UI, reached through a page
 * builder's own mechanism.
 *
 * Bricks' text element does not run shortcodes: [okoskabet_levering] placed
 * in one comes out as the literal text. It does run `{do_action:...}`, for any
 * action name. So a Bricks checkout places the delivery UI with a text element
 * holding `{do_action:okoskabet_levering}` in the step where the shipping
 * choice lives. That is also how the Bricks WooCommerce wizard builds its own
 * checkouts — out of do_action text elements — so it reads as native there.
 * do_action() with no arguments passes '', which oko_delivery_ui() treats as
 * 'block'.
 */
add_action('okoskabet_levering', 'oko_delivery_ui');

/**
 * The merchant the current cart routes to, with the router's full answer.
 *
 * The JS-rendered checkout UI needs it to show the right descriptions and
 * talk to the right /sheds and /home_delivery endpoints, and the
 * missing-picker check needs the same merchant, or it would judge a cart by
 * a key the cart never uses. The router has already applied the
 * mixed-cart-falls-back-to-default policy — every cart resolves to exactly
 * one merchant. With no merchant at all we still fall back to the legacy
 * one, so a fresh install (where the migration hasn't fired yet) doesn't
 * break.
 *
 * @return array{merchant: array{id?: string, label?: string, api_key?: string, description_shipping_okoskabet?: string, description_shipping_private?: string}, is_mixed?: bool, fell_back_to_default?: bool}
 */
function oko_resolve_checkout_merchant(): array
{
	$resolved = class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchant_Router')
		? \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::resolve_for_cart()
		: array('merchant_id' => '', 'merchant' => null, 'is_mixed' => false, 'fell_back_to_default' => false, 'merchant_ids' => array(), 'per_product' => array());

	if (empty($resolved['merchant'])) {
		$resolved['merchant'] = o_get_merchant();
	}

	return $resolved;
}

/** Fired once, when the delivery UI is drawn; did_action() is the latch. */
const OKO_UI_DRAWN_ACTION = 'oko_delivery_ui_drawn';

/**
 * Every rate WooCommerce has calculated for this cart, keyed by rate id.
 *
 * The packages are already calculated by the time a checkout renders, so
 * this reads what WooCommerce has rather than costing another shipping run.
 *
 * @return \Generator<string, \WC_Shipping_Rate>
 */
function oko_cart_rates(): \Generator
{
	if (! function_exists('WC') || ! WC()->shipping()) {
		return;
	}

	foreach ((array) WC()->shipping()->get_packages() as $package) {
		foreach ((array) ($package['rates'] ?? array()) as $rate_id => $rate) {
			if ($rate instanceof \WC_Shipping_Rate) {
				yield (string) $rate_id => $rate;
			}
		}
	}
}

/** Is one of our own shipping methods actually on offer for this cart? */
function oko_cart_offers_okoskabet_rate(): bool
{
	foreach (oko_cart_rates() as $rate) {
		if (strpos($rate->get_method_id(), 'hey_okoskabet_') === 0) {
			return true;
		}
	}

	return false;
}

/** Where we remember that a checkout finished without drawing the UI. */
const OKO_UI_MISSING_OPTION = 'okoskabet_delivery_ui_missing';

add_action('wp_footer', 'oko_note_whether_delivery_ui_rendered', 99);

/**
 * Notice, at the end of a checkout, whether the delivery UI ever got drawn.
 *
 * When a checkout does not fire the review-order hook, nothing breaks loudly: the Økoskabet shipping
 * methods still appear, priced and selectable, because they are registered
 * shipping methods and have nothing to do with the hook. The checkout looks
 * finished. Only a customer reaching the end finds there is no way to choose
 * a shed or a date — and the shop hears about it from them.
 *
 * So the plugin now watches its own rendering and says so in wp-admin.
 *
 * The conditions are deliberately narrow, because a false alarm on a shop
 * where everything is fine is worse than no alarm at all: a real checkout
 * page, a shop that has an API key, and one of our own rates actually on
 * offer for the cart in front of the customer.
 */
function oko_note_whether_delivery_ui_rendered(): void
{
	if (! function_exists('is_checkout') || ! is_checkout()) {
		return;
	}
	if (function_exists('is_order_received_page') && is_order_received_page()) {
		return;
	}

	// Drawn: clear a warning left from before, and skip the checks below —
	// they only matter when the UI is missing.
	if (did_action(OKO_UI_DRAWN_ACTION)) {
		if (get_option(OKO_UI_MISSING_OPTION, false)) {
			update_option(OKO_UI_MISSING_OPTION, false);
		}
		return;
	}

	// The merchant this cart routes to, as the render itself decides it: a
	// cart whose merchant has no key renders nothing on purpose.
	$merchant = oko_resolve_checkout_merchant()['merchant'];
	if (empty($merchant['api_key'])) {
		return;
	}

	if (! oko_cart_offers_okoskabet_rate()) {
		return;
	}

	// Written only when the answer changes, so a busy checkout does not
	// rewrite an option on every page view. Autoloaded: it is one boolean,
	// read on every checkout view and every admin page.
	if (! get_option(OKO_UI_MISSING_OPTION, false)) {
		update_option(OKO_UI_MISSING_OPTION, true);
	}
}

add_action('admin_notices', 'oko_render_missing_delivery_ui_notice');

/** Tell the shop, in words it can act on, that the picker never drew. */
function oko_render_missing_delivery_ui_notice(): void
{
	if (! current_user_can('manage_woocommerce') || ! get_option(OKO_UI_MISSING_OPTION, false)) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong></p><p>%s</p><p>%s</p></div>',
		esc_html__('Økoskabet: the delivery picker is not showing in your checkout', O_TEXTDOMAIN),
		esc_html__('Your checkout offers Økoskabet delivery, but the date and locker picker was not drawn on the last checkout a customer opened. They can choose a delivery method and still have no way to choose a day — and the checkout gives them no sign that anything is missing.', O_TEXTDOMAIN),
		esc_html__('This happens when the checkout is built with something other than WooCommerce\'s own checkout — a page builder, or the block checkout. Place [okoskabet_levering] where the delivery options belong, or in Bricks a text element holding {do_action:okoskabet_levering}, and this notice disappears by itself.', O_TEXTDOMAIN)
	);
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

/** The customer picks a delivery date, as always. */
const OKO_DATE_MODE_REQUIRED = 'required';
/** A date where Økoskabet delivers; a postcode it doesn't is ordered without one. */
const OKO_DATE_MODE_WHEN_AVAILABLE = 'when_available';

/** Meta marking an order placed, on purpose, without a delivery date. */
const OKO_WITHOUT_DATE_META = '_okoskabet_without_delivery_date';

/**
 * The "postcodes Økoskabet doesn't cover" setting on a home-delivery method.
 *
 * Made for island deliveries: an area Økoskabet delivers to (Bornholm every
 * Monday) is booked on its days as usual, and a postcode it doesn't (Samsø)
 * can still be ordered — without a date. Such an order reaches Økoskabet as
 * an unprocessable shipment, which is where a date is given to it by hand.
 */
function oko_allow_without_date_field(): array
{
	return array(
		'title'       => esc_html__('Postcodes without delivery days', O_TEXTDOMAIN),
		'type'        => 'checkbox',
		'label'       => esc_html__('Can still be ordered — without a date, landing among unprocessed orders at Økoskabet', O_TEXTDOMAIN),
		'description' => esc_html__('For island deliveries. Areas Økoskabet delivers to are booked on their days as usual.', O_TEXTDOMAIN),
		'default'     => 'no',
		'desc_tip'    => false,
	);
}

/**
 * The delivery-date setting of the method behind a shipping rate. Anything
 * but a home delivery, or a copy that has never been saved, requires a date.
 */
function oko_delivery_date_mode_for_rate($rate): string
{
	if (! $rate instanceof \WC_Shipping_Rate || $rate->get_method_id() !== 'hey_okoskabet_shipping_home') {
		return OKO_DATE_MODE_REQUIRED;
	}
	$instance_id = (int) $rate->get_instance_id();
	$method      = $instance_id > 0 && class_exists('WC_Shipping_Zones') ? \WC_Shipping_Zones::get_shipping_method($instance_id) : false;

	return $method && $method->get_option('allow_without_date', 'no') === 'yes' ? OKO_DATE_MODE_WHEN_AVAILABLE : OKO_DATE_MODE_REQUIRED;
}

/**
 * Every home-delivery rate in this cart, and the date setting behind it,
 * keyed by rate id.
 *
 * The fallback for a checkout that never prints the date-mode span beside
 * each radio (see oko_print_delivery_date_mode()). The script asks the span
 * first, because it is reprinted whenever the shipping choices are redrawn;
 * this map is written once, at page load, and so answers for the zone the
 * page opened in.
 */
function oko_delivery_date_modes(): array
{
	$modes = array();

	foreach (oko_cart_rates() as $rate_id => $rate) {
		if ($rate->get_method_id() === 'hey_okoskabet_shipping_home') {
			$modes[$rate_id] = oko_delivery_date_mode_for_rate($rate);
		}
	}

	return $modes;
}

/** The delivery-date setting of the home delivery the customer has chosen. */
function oko_chosen_delivery_date_mode(): string
{
	if (! function_exists('WC') || ! WC()->session || ! WC()->shipping()) {
		return OKO_DATE_MODE_REQUIRED;
	}
	$packages = WC()->shipping()->get_packages();
	foreach ((array) WC()->session->get('chosen_shipping_methods', array()) as $package_key => $rate_id) {
		$rate = $packages[$package_key]['rates'][(string) $rate_id] ?? null;
		if ($rate instanceof \WC_Shipping_Rate && $rate->get_method_id() === 'hey_okoskabet_shipping_home') {
			return oko_delivery_date_mode_for_rate($rate);
		}
	}
	return OKO_DATE_MODE_REQUIRED;
}

add_action('woocommerce_after_shipping_rate', 'oko_print_delivery_date_mode');

/**
 * Tell the checkout script which date setting a home-delivery rate has. The
 * rate's id carries no instance, so the script cannot look it up itself;
 * this sits right next to the rate's radio button and is rebuilt with it.
 */
function oko_print_delivery_date_mode($rate): void
{
	if (! $rate instanceof \WC_Shipping_Rate || $rate->get_method_id() !== 'hey_okoskabet_shipping_home') {
		return;
	}
	printf('<span class="okoskabet-date-mode" data-date-mode="%s" hidden></span>', esc_attr(oko_delivery_date_mode_for_rate($rate)));
}

/** Which "what's new" notice a shop has dismissed. */
const OKO_WHATS_NEW_OPTION = 'okoskabet_whats_new_dismissed';
/** Bumped when there is something new to tell shops about. */
const OKO_WHATS_NEW_KEY = 'split-delivery-flip-v1';

add_action('admin_notices', 'oko_render_whats_new_notice');
add_action('admin_init', 'oko_dismiss_whats_new_notice');

/**
 * Tell a shop what the plugin can do now. Deliberately not a warning: every
 * one of these is off until the shop turns it on, and the notice says so, so
 * nobody reads it as work to be done.
 */
function oko_render_whats_new_notice(): void
{
	if (! current_user_can('manage_woocommerce') || get_option(OKO_WHATS_NEW_OPTION) === OKO_WHATS_NEW_KEY) {
		return;
	}
	$settings_url = admin_url('admin.php?page=' . O_TEXTDOMAIN);
	$dismiss_url  = wp_nonce_url(add_query_arg('okoskabet_dismiss_whats_new', '1'), 'okoskabet_dismiss_whats_new');
	$items        = array(
		__('Pre-order: customers choose between a normal order and a pre-order at checkout, with its own days, note and fee.', O_TEXTDOMAIN),
		__('Packaging fee by product category and delivery method.', O_TEXTDOMAIN),
		__('Store pickup as a delivery method.', O_TEXTDOMAIN),
		__('Island delivery: postcodes without delivery days can still be ordered and land among unprocessed orders.', O_TEXTDOMAIN),
		__('Shipping methods in a row of their own at checkout.', O_TEXTDOMAIN),
		__('Split delivery: a basket that needs more than one delivery day offers the customer two buttons — split it into one order per day, or see exactly which items to take out so the rest arrives together. The button wording is yours to set.', O_TEXTDOMAIN),
		__('Delivery exceptions: each rule can be turned around to cover every product OTHER than the categories and tags you picked.', O_TEXTDOMAIN),
	);
	?>
	<div class="notice notice-info">
		<p>
			<strong><?php echo esc_html(O_NAME); ?>:</strong>
			<?php esc_html_e('New in this version. You don\'t need to do anything — everything new stays off until you turn it on.', O_TEXTDOMAIN); ?>
		</p>
		<ul style="list-style:disc;margin-left:20px;">
			<?php foreach ($items as $item) : ?>
				<li><?php echo esc_html($item); ?></li>
			<?php endforeach; ?>
		</ul>
		<p>
			<a href="<?php echo esc_url($settings_url); ?>" class="button button-primary"><?php esc_html_e('See the settings', O_TEXTDOMAIN); ?></a>
			<a href="<?php echo esc_url($dismiss_url); ?>" class="button"><?php esc_html_e('Got it, dismiss', O_TEXTDOMAIN); ?></a>
		</p>
	</div>
	<?php
}

function oko_dismiss_whats_new_notice(): void
{
	if (empty($_GET['okoskabet_dismiss_whats_new']) || ! current_user_can('manage_woocommerce')) {
		return;
	}
	check_admin_referer('okoskabet_dismiss_whats_new');
	update_option(OKO_WHATS_NEW_OPTION, OKO_WHATS_NEW_KEY);
	wp_safe_redirect(remove_query_arg(array('okoskabet_dismiss_whats_new', '_wpnonce')));
	exit;
}

add_action('woocommerce_after_shipping_rate', 'oko_print_store_pickup_description');

/**
 * Show a store pickup's Description — the opening hours, say — under it once
 * it is chosen. WooCommerce keeps a method's description to itself, and home
 * delivery and Økoskab have theirs shown by the date picker instead.
 */
function oko_print_store_pickup_description($rate): void
{
	if (! $rate instanceof \WC_Shipping_Rate || $rate->get_method_id() !== 'hey_okoskabet_shipping_store_pickup') {
		return;
	}
	$chosen = function_exists('WC') && WC()->session ? (array) WC()->session->get('chosen_shipping_methods', array()) : array();
	if (! in_array($rate->get_id(), $chosen, true)) {
		return;
	}
	$method      = class_exists('WC_Shipping_Zones') ? \WC_Shipping_Zones::get_shipping_method((int) $rate->get_instance_id()) : false;
	$description = $method ? trim((string) $method->get_option('description', '')) : '';
	if ($description !== '') {
		echo '<div class="okoskabet-method-description" style="font-weight:normal;line-height:1.1;font-size:80%;margin:8px 0 4px;">' . nl2br(esc_html($description)) . '</div>';
	}
}

add_action('woocommerce_after_shipping_rate', 'oko_mark_separate_shipping_rate');

/**
 * Mark a rate the shop has chosen to show in a row of its own at checkout —
 * an add-on to an earlier order, say, which reads as a way of shipping this
 * one when it sits in the same list.
 */
function oko_mark_separate_shipping_rate($rate): void
{
	if (! $rate instanceof \WC_Shipping_Rate) {
		return;
	}
	$chosen = (array) (o_get_settings()['_separate_shipping_methods'] ?? array());
	$key    = $rate->get_method_id() . ':' . (int) $rate->get_instance_id();
	if (in_array($key, $chosen, true)) {
		echo '<span class="okoskabet-separate-rate" hidden></span>';
	}
}

/**
 * Every shipping method in every zone, for the "own row" setting.
 *
 * @return array<string,string> Keyed "method_id:instance_id".
 */
function oko_all_shipping_method_choices(): array
{
	if (! class_exists('WC_Shipping_Zones')) {
		return array();
	}
	$zones   = \WC_Shipping_Zones::get_zones();
	$zones[] = array('zone_id' => 0);
	$choices = array();
	foreach ($zones as $zone_row) {
		$zone = new \WC_Shipping_Zone((int) $zone_row['zone_id']);
		foreach ($zone->get_shipping_methods() as $method) {
			$choices[$method->id . ':' . (int) $method->get_instance_id()] = sprintf(
				'%s — %s',
				$zone->get_zone_name(),
				wp_strip_all_tags((string) $method->get_title())
			);
		}
	}
	return $choices;
}

/**
 * Arrange the shipping part of the order review, every time WooCommerce
 * rebuilds it:
 *
 *   - the normal-order and pre-order buttons go to the bottom of the
 *     "Shipping" cell, which WooCommerce's template gives no hook for;
 *   - rates the shop marked for a row of their own move into one, under
 *     "Shipping";
 *   - the pre-order switch is mirrored into a cookie, so the date lookups
 *     honour it even from a checkout script a page cache is still serving
 *     from before the switch existed.
 *
 * Inline on purpose: it arrives with the markup it arranges, so it cannot be
 * a stale copy of itself. The click is taken in the capture phase and
 * stopped there, because an earlier build's cached script also toggled on
 * the button, and two toggles cancel out.
 */
function oko_print_checkout_layout_script(string $separate_label): void
{
	$label = wp_json_encode($separate_label !== '' ? $separate_label : __('Other options', O_TEXTDOMAIN));

	// Split checkout draws a banner above the form, outside everything
	// WooCommerce re-renders on a recalculation, so it needs a page load to
	// follow a change of order type. Shops without it keep the old behaviour.
	$split_on   = wp_json_encode(
		class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Split_Checkout')
		&& \okoskabet_woocommerce_plugin\Integrations\Split_Checkout::is_feature_enabled()
	);
	$pre_url    = wp_json_encode(oko_checkout_url_for_mode(true));
	$normal_url = wp_json_encode(oko_checkout_url_for_mode(false));

	echo <<<HTML
<script>(function(){
	var SPLIT_ON={$split_on},PRE_ORDER_URL={$pre_url},NORMAL_URL={$normal_url};
	var shipping=document.querySelector('tr.woocommerce-shipping-totals, tr.shipping');
	var th=shipping&&shipping.querySelector(':scope > th');
	var field=document.getElementById('billing_okoskabet_pre_order');
	document.cookie='okoskabet_pre_order='+(field&&field.value==='1'?'1':'')+';path=/;SameSite=Lax';

	var choice=document.querySelector('.okoskabet-pre-order-row .okoskabet-order-type');
	if(choice&&th){
		th.style.position='relative';
		th.style.paddingBottom='72px';
		var pad=getComputedStyle(th).paddingLeft;
		// Filling the cell: the two buttons side by side in equal halves, one
		// under the other when the cell is too narrow, and the note spanning
		// the same width above them. Never wider than the cell it sits in.
		choice.style.cssText='position:absolute;left:'+pad+';right:'+pad+';bottom:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,150px),1fr));gap:8px;';
		var bs=choice.querySelectorAll('.okoskabet-pre-order-toggle');
		for(var j=0;j<bs.length;j++){bs[j].style.margin='0';bs[j].style.whiteSpace='normal';}
		th.appendChild(choice);
		th.style.paddingBottom=(choice.offsetHeight+32)+'px';

		// In line with the date picker next to it, once there is one: the
		// buttons as tall as the date box, their bottom edge on its bottom
		// edge. Measured from the top of the row, which the buttons' own
		// position cannot move. The picker arrives after this runs and is
		// rebuilt at will, so it is re-measured whenever the row changes.
		window.okoskabetAlignOrderType=function(){
			if(!choice.isConnected){return;}
			var sel=shipping.querySelector('select[name="okoDeliveryDates"], #okoskabet_pickup_date');
			var j;
			if(!sel||!sel.offsetParent){
				for(j=0;j<bs.length;j++){bs[j].style.minHeight='';}
				choice.style.top='auto';choice.style.bottom='16px';
				return;
			}
			var row=shipping.getBoundingClientRect(), box=sel.getBoundingClientRect();
			for(j=0;j<bs.length;j++){bs[j].style.minHeight=Math.round(box.height)+'px';}
			var top=Math.round(box.bottom-row.top)-choice.offsetHeight;
			choice.style.bottom='auto';
			choice.style.top=Math.max(48,top)+'px';
		};
		window.okoskabetAlignOrderType();
		if(window.MutationObserver){
			new MutationObserver(function(){window.okoskabetAlignOrderType();}).observe(shipping,{childList:true,subtree:true});
		}
		if(!window.okoskabetAlignBound){
			window.okoskabetAlignBound=true;
			window.addEventListener('resize',function(){if(window.okoskabetAlignOrderType){window.okoskabetAlignOrderType();}});
		}
	}

	var marks=shipping?shipping.querySelectorAll('.okoskabet-separate-rate'):[];
	if(marks.length){
		var row=document.createElement('tr');
		row.className='okoskabet-separate-shipping';
		var head=document.createElement('th');
		head.textContent={$label};
		var cell=document.createElement('td');
		var list=document.createElement('ul');
		list.className='woocommerce-shipping-methods';
		list.style.cssText='list-style:none;margin:0;padding:0;';
		// Out of WooCommerce's own list, the theme's spacing between radio and
		// label no longer reaches these, so they bring their own.
		for(var i=0;i<marks.length;i++){
			var li=marks[i].closest('li');
			if(!li){continue;}
			li.style.cssText='display:flex;align-items:center;gap:10px;margin:0;';
			var radio=li.querySelector('input');
			if(radio){radio.style.margin='0';radio.style.flex='none';}
			var text=li.querySelector('label');
			if(text){text.style.margin='0';text.style.padding='0';text.style.textIndent='0';}
			list.appendChild(li);
		}
		cell.appendChild(list);row.appendChild(head);row.appendChild(cell);
		shipping.parentNode.insertBefore(row,shipping.nextSibling);
	}

	if(window.okoskabetPreOrderBound){return;}
	window.okoskabetPreOrderBound=true;
	document.addEventListener('click',function(e){
		var t=e.target.closest&&e.target.closest('.okoskabet-pre-order-toggle');
		if(!t){return;}
		e.preventDefault();e.stopImmediatePropagation();
		var f=document.getElementById('billing_okoskabet_pre_order');
		var wanted=t.getAttribute('data-pre-order')==='1'?'1':'';
		if(!f||f.value===wanted){return;}
		f.value=wanted;
		document.cookie='okoskabet_pre_order='+f.value+';path=/;SameSite=Lax';
		var d=document.getElementById('billing_okoskabet_delivery_date');
		if(d){d.value='';}
		// With split checkout on, changing between an ordinary order and a
		// pre-order can change whether the basket needs splitting at all — and
		// that banner sits above the form, which WooCommerce does not rebuild
		// on a recalculation. Reload carrying the choice, so the whole page
		// agrees about which kind of order this is.
		if(SPLIT_ON){window.location.href=wanted==='1'?PRE_ORDER_URL:NORMAL_URL;return;}
		if(window.okoskabetWarmDeliveryDates){window.okoskabetWarmDeliveryDates();}
		if(window.jQuery){window.jQuery(document.body).trigger('update_checkout');}
	},true);
})();</script>
HTML;
}

/**
 * Whether Økoskabet has delivery days for this address and cart, asked the
 * same way the date picker asks. Null when the question could not be
 * answered.
 */
function oko_home_delivery_has_dates(string $postcode, array $product_ids): ?bool
{
	$dates = oko_home_delivery_dates($postcode, $product_ids);

	return $dates === null ? null : ! empty($dates);
}

/**
 * The days Økoskabet will deliver these products to this address, asked
 * exactly the way the date picker asks — so the answer is the days the
 * customer is about to be offered, no more and no less. Økoskabet decides
 * which days exist at all; the merchant's exception rules are applied on top,
 * inside the response.
 *
 * Null means the question could not be answered: no postcode yet, no API key,
 * the API unreachable. Null is not "no days". Callers have to tell the two
 * apart, because "we don't know yet" and "there is no day" mean opposite
 * things to a customer standing in the checkout.
 *
 * @param  int[]     $product_ids
 * @param  bool|null $pre_order Ask for pre-order days, ordinary days, or — with
 *                              null — whichever mode the checkout is in.
 * @return string[]|null Sorted Y-m-d dates, or null when unanswerable.
 */
function oko_home_delivery_dates(string $postcode, array $product_ids, ?bool $pre_order = null): ?array
{
	if ($postcode === '' || ! class_exists('\\okoskabet_woocommerce_plugin\\Rest\\OkoRest')) {
		return null;
	}
	$request = new \WP_REST_Request('GET');
	$request->set_param('zip', $postcode);
	$request->set_param('product_ids', implode(',', array_map('intval', $product_ids)));
	if ($pre_order !== null) {
		$request->set_param('pre_order', $pre_order ? '1' : '0');
	}
	$response = \okoskabet_woocommerce_plugin\Rest\OkoRest::home_delivery_response($request);
	if (! $response instanceof \WP_REST_Response) {
		return null;
	}
	$data = $response->get_data();
	if (! is_array($data['results'] ?? null) || ! array_key_exists('delivery_dates', $data['results'])) {
		return null;
	}

	$dates = array();
	foreach ((array) $data['results']['delivery_dates'] as $date) {
		if (is_string($date) && $date !== '') {
			$dates[] = $date;
		}
	}
	sort($dates);

	return $dates;
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
	return oko_tax_ratio(static fn(): array => WC_Tax::get_shipping_tax_rates());
}

/**
 * The combined ratio (e.g. 0.25) of the tax rates $rates returns, or 0 when
 * the shop doesn't charge tax. The rates are only looked up when it does.
 *
 * @param callable(): array $rates
 */
function oko_tax_ratio(callable $rates): float
{
	if (!function_exists('wc_tax_enabled') || !wc_tax_enabled()) {
		return 0.0;
	}
	$total = 0.0;
	foreach ($rates() as $rate) {
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
				$this->instance_form_fields = oko_shipping_instance_fields($this->method_title)
					+ array('allow_without_date' => oko_allow_without_date_field());

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
	return oko_tax_ratio(static fn(): array => WC_Tax::get_rates($tax_class));
}

add_filter('woocommerce_checkout_fields', 'custom_override_checkout_fields');
add_filter('woocommerce_checkout_get_value', 'oko_checkout_starts_without_last_orders_choice', 10, 2);

/**
 * Whether the customer is placing a pre-order, as the checkout form says:
 * the submitted form when the order is placed, the recalculation's copy of
 * it before that.
 */
function oko_is_pre_order_checkout(): bool
{
	// phpcs:disable WordPress.Security.NonceVerification -- read-only; WooCommerce verifies the checkout.
	if (isset($_POST['billing_okoskabet_pre_order'])) {
		return (string) wp_unslash($_POST['billing_okoskabet_pre_order']) === '1';
	}
	if (isset($_POST['post_data']) && is_string($_POST['post_data'])) {
		$fields = array();
		parse_str(wp_unslash($_POST['post_data']), $fields);
		return (string) ($fields['billing_okoskabet_pre_order'] ?? '') === '1';
	}
	// phpcs:enable WordPress.Security.NonceVerification
	return false;
}

/**
 * Whether the customer has asked for a pre-order, for the basket in front of
 * them, on this visit.
 *
 * Every checkout page load starts as an ordinary order. That is not new: the
 * pre-order field is one of the values blanked on each load, so last year's
 * Christmas pre-order cannot come back on its own, and the checkout script
 * rewrites the cookie from that blanked field the moment it runs.
 *
 * The split banner renders before that script does, and used to read the
 * cookie. So a customer arriving days later with a different basket — a galia
 * melon and some rabarber isvafler — was met with "kun en del af din kurv kan
 * forudbestilles" about a choice made on an earlier visit, with the checkout
 * form hidden behind the banner and no way back to it. A remembered cookie now
 * starts nothing.
 *
 * Pre-order is in force only when the customer says so on this page: the form
 * posts the field during a recalculation or when the order is placed, or they
 * have just pressed the button and the page reloaded carrying `oko_pre_order`.
 * And never when the basket holds nothing that could be pre-ordered — the same
 * condition that decides whether the button is offered at all.
 */
function oko_pre_order_checkout_requested(): bool
{
	// A basket that can only be pre-ordered is one, whatever the page says.
	// The way back is not offered for such a basket, so honouring a stale
	// "no" from a link or an old form would strand the customer in a checkout
	// with no date and no button.
	if (oko_cart_is_pre_order_only()) {
		return true;
	}

	// What the customer arranged for themselves, when moving items between the
	// two deliveries left only one. It is read before anything is decided, so
	// an explicit choice below can overrule it — and when it does, the
	// arrangement is dropped rather than left to fight the next page.
	$settled = class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Split_Checkout')
		? \okoskabet_woocommerce_plugin\Integrations\Split_Checkout::settled_mode()
		: null;

	// phpcs:disable WordPress.Security.NonceVerification -- read-only; WooCommerce verifies the checkout.
	if (isset($_POST['billing_okoskabet_pre_order']) || isset($_POST['post_data'])) {
		$wanted = oko_is_pre_order_checkout();
	} elseif (isset($_GET['oko_pre_order'])) {
		$wanted = (string) wp_unslash($_GET['oko_pre_order']) === '1';
	} elseif ($settled !== null) {
		$wanted = $settled;
	} else {
		// Nobody has said anything, and the basket has no ordinary day its
		// whole contents share — but it does share one as a pre-order. Then
		// that is what it is, and the ordinary button is still there for the
		// customer who would rather split it and have part of it now.
		// Asked once per request, through one instance: working it out means
		// asking Økoskabet for each product's days, and this function is called
		// many times while a checkout renders.
		static $basket_wants = null;
		if ($basket_wants === null) {
			$basket_wants = class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Split_Checkout')
				&& ( new \okoskabet_woocommerce_plugin\Integrations\Split_Checkout() )->basket_wants_pre_order();
		}
		$wanted = $basket_wants;
	}

	if ($settled !== null && $settled !== $wanted) {
		\okoskabet_woocommerce_plugin\Integrations\Split_Checkout::forget_settled_mode();
	}
	// phpcs:enable WordPress.Security.NonceVerification

	return $wanted && oko_cart_can_pre_order();
}

/**
 * Whether anything in the basket could be pre-ordered at all.
 *
 * The gate on the whole idea: with nothing to hold, a pre-order is not a state
 * the customer can be in, however they arrived.
 */
function oko_cart_can_pre_order(): bool
{
	if (! function_exists('WC') || ! WC()->cart || ! class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Delivery_Exceptions')) {
		return false;
	}

	$product_ids = array();
	foreach (WC()->cart->get_cart() as $item) {
		$pid = (int) ($item['product_id'] ?? 0);
		if ($pid > 0) {
			$product_ids[] = $pid;
		}
	}

	return \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::cart_has_pre_order_days($product_ids);
}

/**
 * Whether the basket holds nothing but goods pinned to a date further ahead
 * than the ordinary delivery days — so an ordinary order is not on offer.
 */
function oko_cart_is_pre_order_only(): bool
{
	if (! function_exists('WC') || ! WC()->cart || ! class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Delivery_Exceptions')) {
		return false;
	}

	$product_ids = array();
	foreach (WC()->cart->get_cart() as $item) {
		$pid = (int) ($item['product_id'] ?? 0);
		if ($pid > 0) {
			$product_ids[] = $pid;
		}
	}

	return \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::cart_is_pre_order_only($product_ids);
}

/**
 * The checkout URL for an ordinary order, and for a pre-order.
 *
 * A pre-order is a choice about the page the customer is on, so it travels in
 * the URL rather than in anything that outlives the visit.
 */
function oko_checkout_url_for_mode(bool $pre_order): string
{
	$url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/');

	// Both modes name themselves. "No parameter" cannot mean an ordinary order
	// any more: a basket that can only be pre-ordered starts as a pre-order,
	// and the way back has to outrank that.
	return add_query_arg('oko_pre_order', $pre_order ? '1' : '0', $url);
}

/**
 * Whether the cart, as a pre-order, has any day at all it could be delivered
 * on. Null when Økoskabet could not be asked.
 *
 * A pre-order fee pays for holding goods for a date in the future. With no
 * date there is nothing to hold and nothing to pay for, and a customer whose
 * basket cannot be pre-ordered was being charged 50 kr for the privilege of
 * being shown no dates at all.
 */
function oko_pre_order_cart_has_date(): ?bool
{
	if (! function_exists('WC') || ! WC()->cart || ! WC()->customer) {
		return null;
	}

	$postcode = trim((string) WC()->customer->get_shipping_postcode());
	if ($postcode === '') {
		$postcode = trim((string) WC()->customer->get_billing_postcode());
	}

	$product_ids = array();
	foreach (WC()->cart->get_cart() as $item) {
		$pid = (int) ($item['product_id'] ?? 0);
		if ($pid > 0) {
			$product_ids[] = $pid;
		}
	}
	if (empty($product_ids)) {
		return null;
	}

	// Asked once per cart per request: the fee is recalculated several times
	// over during one checkout render, and each miss is a call to Økoskabet.
	static $cache = array();
	$key = $postcode . '|' . implode(',', $product_ids);
	if (! array_key_exists($key, $cache)) {
		$dates = oko_home_delivery_dates($postcode, $product_ids, true);
		$cache[$key] = $dates === null ? null : ! empty($dates);
	}

	return $cache[$key];
}

/**
 * Whether this checkout is a pre-order as far as money is concerned.
 *
 * The form field says which mode the customer is in; this says what they are
 * actually asking for. They are the same thing until someone edits the page:
 * emptying the field while still asking for a December date paid the ordinary
 * packaging fee. So the fee follows the date whenever there is one, and the
 * field only answers before a date has been picked.
 *
 * Kept apart from the mode on purpose. Deciding the mode from the date would
 * throw the customer out of a pre-order they just asked for, in the moment
 * between pressing the button and the date picker refreshing.
 */
function oko_pre_order_for_pricing(): bool
{
	$date = oko_posted_delivery_date();
	if ($date !== '') {
		return \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::date_is_pre_order(
			$date,
			oko_cart_product_ids()
		);
	}

	return oko_is_pre_order_checkout();
}

/**
 * The product ids in the basket right now.
 *
 * @return int[]
 */
function oko_cart_product_ids(): array
{
	$product_ids = array();

	if (function_exists('WC') && WC()->cart) {
		foreach (WC()->cart->get_cart() as $cart_item) {
			$pid = (int) ($cart_item['product_id'] ?? 0);
			if ($pid > 0) {
				$product_ids[] = $pid;
			}
		}
	}

	return array_values(array_unique($product_ids));
}

/**
 * The delivery date the checkout is posting, however it is posting it.
 *
 * @return string Y-m-d, or an empty string when there is none.
 */
function oko_posted_delivery_date(): string
{
	// phpcs:disable WordPress.Security.NonceVerification -- read-only; WooCommerce verifies the checkout.
	if (isset($_POST['billing_okoskabet_delivery_date'])) {
		return sanitize_text_field((string) wp_unslash($_POST['billing_okoskabet_delivery_date']));
	}
	if (isset($_POST['post_data']) && is_string($_POST['post_data'])) {
		$fields = array();
		parse_str(wp_unslash($_POST['post_data']), $fields);
		return sanitize_text_field((string) ($fields['billing_okoskabet_delivery_date'] ?? ''));
	}
	// phpcs:enable WordPress.Security.NonceVerification

	return '';
}

/**
 * Start every checkout without the date, shed and pickup place of the
 * customer's last order.
 *
 * WooCommerce remembers billing fields on the customer and fills them in next
 * time. For an address that is a kindness; for these it is a trap. The picker
 * keeps a date it finds already chosen, so a customer who pre-ordered for
 * Christmas last year would open their next checkout on Christmas again —
 * with the pre-order fee — without having picked anything. A value that was
 * actually posted in this request is left alone.
 *
 * @param mixed  $value Null unless something earlier decided the value.
 * @param string $input The field name.
 * @return mixed
 */
function oko_checkout_starts_without_last_orders_choice($value, $input)
{
	$fresh_every_time = array(
		'billing_okoskabet_delivery_date',
		'billing_okoskabet_shed_id',
		'billing_okoskabet_pickup_location_id',
		'billing_okoskabet_pre_order',
	);
	// A pre-order the customer asked for on this page is not a leftover. The
	// field has to say so, or the banner would render as a pre-order while the
	// date picker beside it asked for ordinary days.
	if ($input === 'billing_okoskabet_pre_order' && !isset($_POST[$input]) && oko_pre_order_checkout_requested()) {
		return '1';
	}

	// phpcs:ignore WordPress.Security.NonceVerification -- only checks presence; WooCommerce verifies the checkout.
	if (in_array($input, $fresh_every_time, true) && !isset($_POST[$input])) {
		return '';
	}
	return $value;
}

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

	// "1" while the customer is placing a pre-order. Kept in the billing form
	// rather than the order review, which WooCommerce rebuilds on every
	// recalculation and would forget it in.
	$fields['billing']['billing_okoskabet_pre_order'] = array(
		'label'       => __('Pre-order', O_TEXTDOMAIN),
		'placeholder' => '',
		'required'    => false,
		'class'       => array('okoskabet-pre-order form-row-wide'),
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


/**
 * What an order contained, in the shape Økoskabet stores on the shipment.
 *
 * Sent on every shipment, whatever its type — a collection has contents just
 * like a delivery does, and whether an order ends up in the packing room is
 * Økoskabet's decision, not this plugin's. A merchant can be given the packing
 * room after the fact, and orders that were already in the system would then
 * have no contents for good.
 *
 * `product_id` is the field that carries weight: tags sit on the product, and
 * two variations of one product are the same goods to a warehouse. The
 * variation is kept beside it for the SKU it explains.
 *
 * Fees and deposits are left out. They are not goods: nobody put them in the
 * basket, and nobody packs them. But a packing room counts lines — the label
 * has a limit on how many it will print, and the dispatch note lists them — so
 * an order of one product and a packaging fee reads as two things to pack.
 * Only `line_item` is asked for, which is what makes the count match the box.
 *
 * The variation travels in `variant_title`, beside the product's own name,
 * rather than written into it. WooCommerce names a variation line after both
 * ("Hakkebøffer, 8 stk. - 3 pakker"), and a packing room that cannot tell the
 * two apart cannot leave the variation off a label when the shop asks it to.
 * Shopify has always sent them apart; now so do we.
 *
 * @param \WC_Order $order
 * @return array<int,array{product_id:int|null,variant_id:int|null,name:string,variant_title:string|null,sku:string,quantity:int}>
 */
function oko_order_line_items(\WC_Order $order): array
{
	$lines = array();

	foreach ($order->get_items('line_item') as $item) {
		// Minus whatever has been refunded. WooCommerce only lets a line be
		// edited while an order is pending or on hold, so on an order that has
		// been sent, refunding a line IS how the shop takes it off. Reading the
		// ordered quantity alone leaves the packing room packing three when the
		// customer is only getting two. `get_qty_refunded_for_item` answers in
		// negatives, hence the addition.
		//
		// Products only. A fee has no quantity in WooCommerce: every fee item
		// answers 1, the fee's refund lines included, so the same sum would add
		// one for each refund of the packaging fee instead of taking it off.
		// Refunding a fee is money back, not goods off the order.
		$quantity = (int) $item->get_quantity();
		if ($item->get_type() === 'line_item') {
			$quantity += (int) $order->get_qty_refunded_for_item( $item->get_id(), 'line_item' );
		}

		// A line for nothing is not something a shop sends, and a refund line
		// is not something a packer can put in a box.
		if ($quantity < 1) {
			continue;
		}

		$product_id    = 0;
		$variant_id    = 0;
		$sku           = '';
		$name          = (string) $item->get_name();
		$variant_title = null;

		if ($item instanceof \WC_Order_Item_Product) {
			$product_id = (int) $item->get_product_id();
			$variant_id = (int) $item->get_variation_id();

			// The product can be gone — deleted from the catalogue after the
			// order was placed. The line survives it; the SKU does not.
			$product = $item->get_product();
			if ($product instanceof \WC_Product) {
				$sku = (string) $product->get_sku();
			}

			// A variation line: the goods are the parent product, and what the
			// customer chose is the variation. Both are read off the product
			// rather than off the line's name, which is the two run together.
			// A variation deleted after the order is refused by WooCommerce when
			// the line loads, so the line answers with the parent and never
			// gets here: it keeps the name it was sold under, variation and all.
			if ($variant_id > 0 && $product instanceof \WC_Product_Variation) {
				$parent = wc_get_product($product->get_parent_id());
				if ($parent instanceof \WC_Product) {
					$name = (string) $parent->get_name();
				}

				// What the customer chose is read off the line, where
				// WooCommerce wrote it when the order was placed. The variation
				// itself can be edited afterwards — a shop that reuses "Uge 40"
				// as "Uge 41" would otherwise have the packing room pack the new
				// one — and an attribute left as "any" is only ever recorded on
				// the line, never on the variation.
				$chosen = array();
				foreach ($product->get_attributes() as $key => $value) {
					$on_line      = (string) $item->get_meta($key, true);
					$chosen[$key] = $on_line !== '' ? $on_line : (string) $value;
				}

				$formatted = wc_get_formatted_variation($chosen, true, false);
				$formatted = trim(html_entity_decode(wp_strip_all_tags((string) $formatted), ENT_QUOTES, 'UTF-8'));

				// Only beside a name that is the parent's. Without the parent
				// the line keeps the name it was sold under, variation and all,
				// and repeating the variation next to it says it twice.
				if ($formatted !== '' && $parent instanceof \WC_Product) {
					$variant_title = $formatted;
				}
			}
		}

		$lines[] = array(
			'product_id'    => $product_id > 0 ? $product_id : null,
			'variant_id'    => $variant_id > 0 ? $variant_id : null,
			'name'          => $name,
			'variant_title' => $variant_title,
			'sku'           => $sku,
			'quantity'      => $quantity,
		);
	}

	return $lines;
}


/**
 * Everything Økoskabet is told about an order, in one place.
 *
 * Built once and used twice — when the order is first sent, and again when it
 * is edited afterwards. Two builders would drift, and the second one would be
 * the one nobody noticed had gone wrong.
 *
 * @param \WC_Order $order
 * @param array     $merchant The merchant this order routes to.
 * @return array|null Null when the order carries no delivery date, which means
 *                    it is not an Økoskabet order at all.
 */
function oko_shipment_payload(\WC_Order $order, array $merchant): ?array
{
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

	// No date means this is not an Økoskabet order — unless it was placed
	// without one on purpose, to be given a date by hand.
	$without_date = empty($order_delivery_date) && $order->get_meta(OKO_WITHOUT_DATE_META, true) === 'yes';
	if (empty($order_delivery_date) && ! $without_date) {
		return null;
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
		'notes'              => oko_customer_note_as_written($order),
		'delivery_date'      => $order_delivery_date,
		// What the order contained. Økoskabet splits these into zones and
		// prints them as packing slips; without them an order is a name
		// with no contents.
		'line_items'         => oko_order_line_items($order),
		// Where to ask us what those product ids are. The finished address
		// rather than a base, because the route is keyed on the merchant
		// id this plugin issues — handing over a base would leave
		// Økoskabet guessing the last part of the path, which is the
		// string surgery we are trying to avoid. Sent on every order, so
		// it repairs itself if the shop moves domain.
		'webshop_products_url' => rest_url('wp/v2/okoskabet/products/' . rawurlencode((string) ($merchant['id'] ?? 'default'))),
	];

	// Left out rather than sent empty when the order has no date: the
	// shipment then lands among Økoskabet's unprocessable shipments, where it
	// is given one by hand.
	if ($without_date) {
		unset($data['delivery_date']);
	}

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
		// notes field so it appears in Økoskabet's Notes column. A message the
		// customer wrote in WooCommerce's own order-notes field comes after it
		// rather than being replaced by it: the dropdown always has a value, so
		// replacing meant a customer's message could arrive as nothing but
		// "In front of the door".
		if (!empty($logistics_note)) {
			$data['notes'] = $data['notes'] !== ''
				? $logistics_note . "\n" . $data['notes']
				: $logistics_note;
		}
	}

	return $data;
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

		// An order with money owing and no transaction id has not been paid
		// through a gateway: a card payment that never went through, or a bank
		// transfer the customer has yet to make. Hold it back while it is on
		// hold. When the shop sets it to processing, the shop is saying the
		// money has arrived, so let it through — otherwise a bank transfer,
		// which never gets a transaction id, would never be sent at all.
		if ($new_status !== 'processing' && empty($order->get_transaction_id()) && !empty($order->get_total()) && $order->get_total() > 0) {
			error_log("okoskabet_woocommerce_plugin: Missing transaction id. Not submitting order to Økoskabet");
			return;
		}

		$url  = $api_url . '/api/v1/shipments/';
		$data = oko_shipment_payload($order, $merchant);
		if ($data === null) {
			return;
		}

		// Read back off the payload rather than kept in a second set of
		// variables, so the order note and what we actually sent can never
		// disagree about which kind of shipment this was.
		$order_delivery_date = (string) ($data['delivery_date'] ?? '');
		$is_store_pickup     = ($data['shipment_type'] ?? '') === 'store_pickup';
		$order_shed          = (string) ($data['reservation']['shed_id'] ?? '');

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

		// Where this one is going, kept as the order's own fact. It used to be
		// written in front of the customer's note, which put a line meant for
		// the packing table on the customer's order confirmation and buried
		// what the customer had written underneath it. The note field belongs
		// to the customer; this belongs to the order.
		if ($is_store_pickup) {
			$oko_delivery_summary = __('Store pickup', O_TEXTDOMAIN);
		} elseif (empty($order_shed)) {
			$oko_delivery_summary = __('Home delivery', O_TEXTDOMAIN);
		} else {
			$oko_delivery_summary = (string) $order_shed;
		}
		$order->update_meta_data(OKO_DELIVERY_SUMMARY_META, $oko_delivery_summary);

		// And the same thing once more where the shop's staff read an order's
		// history. A private note: visible in the admin, never in an email and
		// never on a packing slip.
		$order->add_order_note(sprintf(
			/* translators: 1: delivery date, 2: shed name, "Home delivery" or "Store pickup". */
			__('Sent to Økoskabet — %1$s, %2$s', O_TEXTDOMAIN),
			$order_delivery_date !== '' ? $order_delivery_date : __('without delivery date', O_TEXTDOMAIN),
			$oko_delivery_summary
		));

		$order->update_meta_data('billing_okoskabet_done', true);

		// Record what Økoskabet now has. Without this the save below would look
		// like an edit and send the same order straight back a second time.
		$order->update_meta_data(OKO_SENT_FINGERPRINT_META, oko_shipment_fingerprint($data));

		$order->save();
	}
}

/** Meta holding a fingerprint of what we last told Økoskabet about an order. */
const OKO_SENT_FINGERPRINT_META = '_okoskabet_sent_fingerprint';

/**
 * Meta marking an order Økoskabet will not take changes to any more.
 *
 * A shipment locks once a parcel has left the merchant's hands, and no edit
 * after that is ever accepted. One-way on purpose: nothing unlocks a parcel
 * that has already been collected.
 */
const OKO_SHIPMENT_LOCKED_META = '_okoskabet_shipment_locked';

/** The `error_code` Økoskabet uses for a shipment that can no longer change. */
const OKO_ERROR_CODE_LOCKED = 'shipment_locked';

/** First word of the line older versions put in front of the customer's note. */
const OKO_ORDER_NOTE_PREFIX = 'ØKOSKABET ';

/** Where an order is going, as one line: a shed's name, home delivery or store pickup. */
const OKO_DELIVERY_SUMMARY_META = '_okoskabet_delivery_summary';

/**
 * The order note as the customer wrote it.
 *
 * Until this version, sending an order put a line of our own in front of the
 * note ("ØKOSKABET 2026-12-24 Hjemmelevering"). Orders placed back then still
 * carry it, and it is not the customer's text: it is read off here so an old
 * order reads like a new one, on the packing slip and everywhere else.
 */
function oko_customer_note_as_written(\WC_Order $order): string
{
	// The stored value, not the filtered one. Reading it in view context would
	// hand us a note the filter below has already shortened, and shortening it
	// again can take a line of the customer's with it.
	return oko_note_without_our_old_line((string) $order->get_customer_note('edit'));
}

/**
 * The same, for a note already read off an order.
 *
 * Takes the string rather than the order, because the filter below is handed
 * the value on its way out — asking the order for it again there would come
 * straight back through this filter.
 */
function oko_note_without_our_old_line(string $note): string
{
	// Only the line older versions actually wrote. Matching on the first word
	// alone would eat a customer's own opening line: "ØKOSKABET ved Netto er
	// fint" starts the same way and is the customer's text. Every version that
	// ever wrote the line wrote it as the word, a date or the "no date" phrase,
	// and then the place.
	$ours = '/^ØKOSKABET (?:\d{4}-\d{2}-\d{2}|without delivery date|uden leveringsdato)(?: [^\n]*)?(?:\n|$)/u';

	return (string) preg_replace($ours, '', $note, 1);
}

add_filter('woocommerce_order_get_customer_note', 'oko_hide_our_old_line_from_the_note', 10, 1);

/**
 * Old orders read like new ones.
 *
 * The shop prints packing slips from orders placed before the line moved out
 * of the note, and on those the customer's own words sit under ours. Reading
 * the note through here means the packing slip, the admin screen and the
 * emails all show what the customer wrote — without touching what is stored,
 * so nothing is lost if the shop ever wants the old line back.
 *
 * @param string $note
 * @return string
 */
function oko_hide_our_old_line_from_the_note($note): string
{
	// Not while the order is being written. WooCommerce reads the note in view
	// context on its way to storage — the posts data store does it on every
	// status change, HPOS does it when it syncs, and the admin order screen
	// puts the same value in the text box that "Update" saves. A filter meant
	// for the eye would therefore delete the old line from the database for
	// good, and removing the filter afterwards would not bring it back.
	if (oko_order_save_depth() > 0) {
		return (string) $note;
	}

	return oko_note_without_our_old_line((string) $note);
}

add_action('woocommerce_before_order_object_save', 'oko_order_save_started', PHP_INT_MIN);
add_action('woocommerce_after_order_object_save', 'oko_order_save_finished', PHP_INT_MAX);

/** @return void */
function oko_order_save_started(): void
{
	oko_order_save_depth(1);
}

/** @return void */
function oko_order_save_finished(): void
{
	oko_order_save_depth(-1);
}

/**
 * How many order saves are in progress, counted so nested saves behave.
 *
 * @param int $delta
 * @return int
 */
function oko_order_save_depth(int $delta = 0): int
{
	static $depth = 0;
	$depth = max(0, $depth + $delta);

	return $depth;
}

/**
 * A fingerprint of a payload, so we can tell whether anything actually changed.
 *
 * Leaves out what describes the request rather than the order: the language
 * and the shop's own address can differ between the customer's checkout and
 * an admin's screen on a multilingual shop, and that is not an edit.
 */
function oko_shipment_fingerprint(array $payload): string
{
	unset($payload['locale'], $payload['webshop_products_url']);
	return md5((string) wp_json_encode($payload));
}

add_action('woocommerce_update_order', 'oko_resend_shipment_on_update', 20, 1);

// A refund is how a line is taken off an order that has already been sent, and
// the order itself is not always saved when one is made. Without this the
// packing room keeps the quantity the customer no longer gets.
add_action('woocommerce_order_refunded', 'oko_resend_shipment_on_refund', 20, 2);

/**
 * @param int $order_id
 * @param int $refund_id
 * @return void
 */
function oko_resend_shipment_on_refund($order_id, $refund_id): void
{
	oko_resend_shipment_on_update((int) $order_id);
}

/**
 * Send an edited order to Økoskabet again.
 *
 * An order can be changed after it has been placed — a date moved, an address
 * corrected, a line removed — and until now none of that reached Økoskabet.
 * The order was frozen there from the moment it was created, and the packing
 * room, the driver and the shed reservation all worked from the original.
 *
 * Deliberately quiet:
 *
 *   - Only for orders Økoskabet already has. An order that was never sent is
 *     not sent by editing it; that is what placing it is for.
 *   - Only when the payload actually differs from the last one we sent.
 *     `woocommerce_update_order` fires on every save, including our own, and a
 *     shop that saves an order ten times should not send ten identical PUTs.
 *   - Once per request, whatever WooCommerce does internally.
 *   - A failure is logged and the fingerprint is left alone, so the next save
 *     tries again. It never touches the order's status: an edit that cannot
 *     reach Økoskabet is a problem to retry, not a reason to fail an order a
 *     customer has already paid for.
 *
 * @param int $order_id
 */
function oko_resend_shipment_on_update(int $order_id): void
{
	static $seen = array();

	if (isset($seen[$order_id])) {
		return;
	}

	$order = wc_get_order($order_id);
	if (! $order instanceof \WC_Order) {
		return;
	}

	// Never sent, or on its way out. A cancellation is a DELETE elsewhere.
	if (empty($order->get_meta('billing_okoskabet_done', true)) || $order->get_status() === 'cancelled') {
		return;
	}

	// Økoskabet has told us this one can no longer change. Every further edit
	// would be a call that cannot succeed, so we stop asking — the goods are
	// already on their way to someone.
	if (! empty($order->get_meta(OKO_SHIPMENT_LOCKED_META, true))) {
		return;
	}

	/**
	 * Escape hatch for a shop that would rather Økoskabet kept the order as
	 * it was placed.
	 */
	if (! apply_filters('oko_resend_shipment_on_update', true, $order)) {
		return;
	}

	$merchant = null;
	if (class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchant_Router')) {
		$resolved = \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::resolve_for_order($order);
		$merchant = $resolved['merchant'] ?? null;
	}
	if (! $merchant) {
		$merchant = o_get_merchant();
	}
	if (empty($merchant['api_key'])) {
		return;
	}

	$payload = oko_shipment_payload($order, $merchant);
	if ($payload === null) {
		return;
	}

	// Orders sent before this version have no record of what was sent, so
	// there is nothing to compare an edit with — and treating "no record" as
	// "changed" would send every old order again the first time a shop marks
	// it completed. They stay as they were placed.
	$sent_fingerprint = (string) $order->get_meta(OKO_SENT_FINGERPRINT_META, true);
	if ($sent_fingerprint === '') {
		return;
	}

	$fingerprint = oko_shipment_fingerprint($payload);
	if ($fingerprint === $sent_fingerprint) {
		return;
	}

	$seen[$order_id] = true;

	$response = wp_remote_request(
		o_merchant_api_url($merchant) . '/api/v1/shipments/' . rawurlencode((string) $order->get_order_number()),
		array(
			'method'  => 'PUT',
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => (string) $merchant['api_key'],
			),
			'body' => wp_json_encode($payload),
		)
	);

	// Could not reach Økoskabet at all. Leave the fingerprint alone so the
	// next save tries again — this is exactly the case retrying is for.
	if (is_wp_error($response)) {
		error_log('okoskabet_woocommerce_plugin: could not resend order ' . $order->get_order_number() . ': ' . $response->get_error_message());
		return;
	}

	$http_code = (int) wp_remote_retrieve_response_code($response);
	$body      = (string) wp_remote_retrieve_body($response);

	// A refusal is not a failure to deliver. Økoskabet locks a shipment once
	// a parcel has been received, and answers 422 from then on — so an order
	// past that point would otherwise be resent on every single save for the
	// rest of its life, quietly and forever. Other than 401 and 403 below, a
	// refusal says this payload will never be accepted, and sending it again
	// unchanged cannot help; it is recorded as dealt with. A later edit is a
	// different payload and gets one attempt of its own.
	//
	// 5xx is left to retry: that is Økoskabet having a bad minute, not a
	// verdict on the order.
	if ($http_code >= 500) {
		error_log(sprintf(
			'okoskabet_woocommerce_plugin: resend of order %s failed (%d), will retry: %s',
			$order->get_order_number(),
			$http_code,
			$body
		));
		return;
	}

	// Being turned away is not the same as being told no. 401 and 403 are
	// about the credential, and say nothing about the order — Økoskabet
	// answers 401 for a key that has been rotated *and* for a request that
	// carried no key at all. Treating those as settled would mean a key the
	// merchant fixes tomorrow never repairs the orders edited today, and a bug
	// that dropped the header would quietly mark every order as dealt with.
	// So they retry, and they say so loudly: this is the one failure here that
	// needs a person.
	if ($http_code === 401 || $http_code === 403) {
		error_log(sprintf(
			'okoskabet_woocommerce_plugin: RESEND REFUSED for order %s (%d) — Økoskabet would not accept the API key for merchant %s. '
				. 'Edits to this order are not reaching Økoskabet. Check the key under Økoskabet settings. Response: %s',
			$order->get_order_number(),
			$http_code,
			$merchant['id'] ?? '?',
			$body
		));
		return;
	}

	if ($http_code < 200 || $http_code > 299) {
		// 404 comes back as plain text, not JSON, so decode defensively and
		// fall back to the raw body.
		$decoded = json_decode($body, true);
		$reason  = is_array($decoded) && ! empty($decoded['error_message'])
			? (string) $decoded['error_message']
			: $body;

		// A named reason, when there is one. Deliberately matched on the code
		// and never on the message: the message is translated into the caller's
		// language, so a match written against the English one would pass every
		// test we wrote and say nothing in a Danish shop.
		if ($http_code === 422 && is_array($decoded) && ($decoded['error_code'] ?? '') === OKO_ERROR_CODE_LOCKED) {
			$order->update_meta_data(OKO_SHIPMENT_LOCKED_META, true);
			error_log(sprintf(
				'okoskabet_woocommerce_plugin: order %s can no longer be changed at Økoskabet — a parcel has been received. Later edits stay in the shop only.',
				$order->get_order_number()
			));
		} else {
			error_log(sprintf(
				'okoskabet_woocommerce_plugin: resend of order %s refused (%d), not retrying: %s',
				$order->get_order_number(),
				$http_code,
				$reason
			));
		}
	}

	// Meta only, not a full save — writing the fingerprint should not itself
	// read as an edit and call us straight back. `save_meta_data()` also goes
	// through the order's data store, so this works whether the shop keeps
	// orders in posts or in WooCommerce's own tables.
	$order->update_meta_data(OKO_SENT_FINGERPRINT_META, $fingerprint);
	$order->save_meta_data();
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
		if (empty($fields['billing_okoskabet_delivery_date']) && ! oko_home_delivery_may_go_without_date($fields)) {
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

/**
 * Whether a home delivery without a date may be placed. With "a date if the
 * area has delivery days", the answer is checked again here rather than
 * trusted from the page: a customer who got past the picker before it had
 * loaded would otherwise send a Bornholm order off to be scheduled by hand.
 */
function oko_home_delivery_may_go_without_date(array $fields): bool
{
	if (oko_chosen_delivery_date_mode() !== OKO_DATE_MODE_WHEN_AVAILABLE) {
		return false;
	}

	$postcode = (string) ($fields['shipping_postcode'] ?? '');
	if ($postcode === '' || empty($fields['ship_to_different_address'])) {
		$postcode = (string) ($fields['billing_postcode'] ?? '');
	}
	$product_ids = array();
	if (function_exists('WC') && WC()->cart) {
		foreach (WC()->cart->get_cart() as $item) {
			$product_ids[] = (int) ($item['product_id'] ?? 0);
		}
	}

	// Only a clear "no days here" lets it through. If the question cannot be
	// answered, the customer is asked to pick a date, as they always were.
	return oko_home_delivery_has_dates($postcode, array_filter($product_ids)) === false;
}

add_action('woocommerce_checkout_create_order', 'oko_mark_order_without_date', 15, 2);

/**
 * Record that an order goes without a delivery date on purpose, so it is
 * still sent to Økoskabet.
 */
function oko_mark_order_without_date($order, $data): void
{
	if (! in_array('hey_okoskabet_shipping_home', (array) ($data['shipping_method'] ?? array()), true)) {
		return;
	}
	if (oko_chosen_delivery_date_mode() === OKO_DATE_MODE_WHEN_AVAILABLE && empty($order->get_meta('_billing_okoskabet_delivery_date', true))) {
		$order->update_meta_data(OKO_WITHOUT_DATE_META, 'yes');
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

add_action('wpo_wcpdf_after_order_data', 'oko_packing_slip_delivery_row', 10, 2);

/**
 * The delivery date on the packing slip, beside the order's own data.
 *
 * The packing table works from this sheet, and until now the only place the
 * date appeared on it was the line we wrote into the customer's note — which
 * is exactly the line that buried what the customer had written. So it goes
 * where it belongs: in the block that already carries the order number, the
 * order date and the shipping method.
 *
 * Hooked to the PDF Invoices & Packing Slips plugin. A shop without it never
 * fires the action and never notices this exists.
 *
 * @param string $document_type Which document is being printed.
 * @param mixed  $order         The order it is printed from.
 * @return void
 */
function oko_packing_slip_delivery_row($document_type, $order): void
{
	// The Simple template fires this from invoice.php as well, so without this
	// the customer's own invoice would carry the packing table's row too.
	if ($document_type !== 'packing-slip' || ! $order instanceof \WC_Order) {
		return;
	}

	$date = (string) $order->get_meta('_billing_okoskabet_delivery_date', true);
	if ($date === '') {
		return;
	}

	$timestamp = strtotime($date);
	$shown     = $timestamp === false ? $date : date_i18n(get_option('date_format'), $timestamp);

	// The date and nothing else. The place used to be printed beside it, read
	// from what was stored when the order was sent, and it was wrong twice
	// over: a shed is stored as its id rather than its name, so the slip said
	// "shed-7f3a", and a date moved later would be printed next to the place
	// the order left with. The date is what the packing table was asking for.
	printf(
		'<tr class="okoskabet-delivery-date"><th>%s</th><td>%s</td></tr>',
		esc_html__('Delivery date', O_TEXTDOMAIN),
		esc_html($shown)
	);
}

add_filter('woocommerce_get_order_item_totals', 'oko_add_delivery_date_to_order_totals', 10, 2);

/**
 * The delivery date in the customer's own order summary.
 *
 * The order email, the thank-you page and the order view under My account all
 * build the block under the items from the same list, so one row reaches all
 * three. It goes straight after the shipping line, where the customer is
 * already reading how the goods get to them.
 *
 * Until this version the date reached the customer only because we wrote it
 * into the note field, where it sat under a heading that said "Bemærkning".
 * This is the same fact, in the place it belongs.
 *
 * @param array<string,array{label:string,value:string}> $total_rows
 * @param mixed                                          $order
 * @return array<string,array{label:string,value:string}>
 */
function oko_add_delivery_date_to_order_totals($total_rows, $order): array
{
	$total_rows = (array) $total_rows;

	if (! $order instanceof \WC_Order) {
		return $total_rows;
	}

	$date = (string) $order->get_meta('_billing_okoskabet_delivery_date', true);
	if ($date === '' || isset($total_rows['okoskabet_delivery_date'])) {
		return $total_rows;
	}

	$timestamp = strtotime($date);
	$row       = array(
		'label' => esc_html__('Delivery date', O_TEXTDOMAIN) . ':',
		'value' => esc_html($timestamp === false ? $date : date_i18n(get_option('date_format'), $timestamp)),
	);

	// After the shipping row, which is where the customer is reading about the
	// delivery. A shop can hide that row — free shipping, or a layout of its
	// own — so when it is not there the date goes in front of the total rather
	// than falling off the end below it.
	$after = isset($total_rows['shipping']) ? 'shipping' : null;
	if ($after === null) {
		$after = isset($total_rows['order_total']) ? null : array_key_last($total_rows);
	}

	$rebuilt = array();
	foreach ($total_rows as $key => $value) {
		if ($key === 'order_total' && $after === null) {
			$rebuilt['okoskabet_delivery_date'] = $row;
		}
		$rebuilt[$key] = $value;
		if ($key === $after) {
			$rebuilt['okoskabet_delivery_date'] = $row;
		}
	}

	return isset($rebuilt['okoskabet_delivery_date']) ? $rebuilt : $rebuilt + array('okoskabet_delivery_date' => $row);
}

/**
 * What a shop's saved choices mean, now that the names are Økoskabet's own.
 *
 * Three names were kept here by hand, and two of them said something other
 * than what they did:
 *
 * - `in_shed` fired when a shipment reached `fulfilled`, which is the box
 *   handed over, not the box standing in the shed.
 * - `order_delivered` waited for a status called `delivered`, which Økoskabet
 *   has never sent. A shop that chose it — and it was the default — was never
 *   charged and never had an order completed automatically.
 * - `label_created` was renamed to `in_shed` years ago and meant the same as
 *   the first.
 *
 * All three meant "when the customer has it", and only `fulfilled` ever says
 * that. So that is what they become, and the choice a shop made keeps doing
 * what the shop believed it was doing. `label_printed` is untouched: it was
 * right all along.
 *
 * Saved choices are rewritten once on upgrade, so this runs on rows written
 * before that — a merchant added while the old settings were still on screen,
 * for instance.
 *
 * @param array<int,string> $events
 * @return array<int,string>
 */
function oko_status_events_as_chosen(array $events): array
{
	$renamed = array(
		'label_created'   => 'fulfilled',
		'in_shed'         => 'fulfilled',
		'order_delivered' => 'fulfilled',
	);

	$out = array();
	foreach ($events as $event) {
		$event = (string) $event;
		$out[] = $renamed[$event] ?? $event;
	}

	return array_values(array_unique($out));
}

/**
 * What the shop knows about a product, beyond its name.
 *
 * A packing slip has to name the producer and the country the goods come from.
 * No two shops keep those in the same place: one has a global attribute called
 * "Producent", the next writes it straight on the product, a third has a brand
 * taxonomy from some other plugin. So nothing is assumed here — everything the
 * product carries is sent, and Økoskabet lets the shop point at the two fields
 * that matter.
 *
 * `key` is what the shop's choice is stored under at Økoskabet, so it has to
 * stay the same when a field is renamed: it is built from the taxonomy or the
 * attribute slug, never from the label. `label` is what the shop sees in
 * WooCommerce, and it is the text they pick from.
 *
 * Tags are left out. They travel in their own field and would otherwise be
 * offered twice.
 *
 * @param \WC_Product $product
 * @return array<int,array{key:string,label:string,values:array<int,string>}>
 */
function oko_product_properties(\WC_Product $product): array
{
	// A variation is one choice out of the parent's list. Answering with the
	// parent's whole list would put "Danmark, Spanien" on the packing slip for
	// a customer who bought the Spanish one, and country of origin is the one
	// field that has to be right.
	if ($product instanceof \WC_Product_Variation) {
		return oko_variation_properties($product);
	}

	$properties = array();
	$product_id = (int) $product->get_id();

	// Tags have their own field; attribute taxonomies are read as attributes
	// below, where the shop's own label for them lives.
	$covered = array('product_tag' => true);

	foreach ($product->get_attributes() as $attribute) {
		if (! $attribute instanceof \WC_Product_Attribute) {
			continue;
		}

		// Only what the shop shows its own customers. An attribute kept off
		// the product page is the shop's own bookkeeping — a buying price, a
		// supplier — and not something to hand a third party. Producer and
		// country of origin are on the page, because the law puts them there.
		if (! $attribute->get_visible()) {
			continue;
		}

		if ($attribute->is_taxonomy()) {
			$taxonomy            = (string) $attribute->get_taxonomy();
			$covered[$taxonomy]  = true;
			$key                 = 'attribute:' . $taxonomy;
			$label               = (string) wc_attribute_label($taxonomy, $product);
			$values              = (array) wc_get_product_terms($product_id, $taxonomy, array('fields' => 'names'));
		} else {
			// Written straight on the product. The name is all there is, so the
			// key is built from it — renaming such an attribute really is a
			// different field, which is the one case where the key may move.
			$name   = (string) $attribute->get_name();
			$key    = oko_local_attribute_key($name);
			$label  = $name;
			$values = (array) $attribute->get_options();
		}

		$property = oko_product_property($key, $label, $values);
		if ($property !== null) {
			$properties[] = $property;
		}
	}

	// Everything else the shop files its products under: brands, categories,
	// whatever a plugin has registered. Asked of WordPress rather than listed
	// here, so a shop that keeps its producer somewhere we have never heard of
	// still gets to point at it.
	$taxonomies = \function_exists('get_object_taxonomies')
		? (array) get_object_taxonomies('product', 'objects')
		: array();

	foreach ($taxonomies as $taxonomy => $object) {
		$taxonomy = (string) $taxonomy;
		if (isset($covered[$taxonomy]) || strpos($taxonomy, 'pa_') === 0) {
			continue;
		}

		// Public ones only. WooCommerce files its own bookkeeping as
		// taxonomies — product_type, product_visibility, shipping class — and
		// other plugins keep suppliers and buying prices the same way. None of
		// that is ours to send on.
		if (! is_object($object) || empty($object->public)) {
			continue;
		}

		$label = '';
		if (is_object($object)) {
			$label = (string) ($object->labels->singular_name ?? '');
			if ($label === '') {
				$label = (string) ($object->label ?? '');
			}
		}

		$property = oko_product_property(
			'taxonomy:' . $taxonomy,
			$label !== '' ? $label : $taxonomy,
			(array) wp_get_post_terms($product_id, $taxonomy, array('fields' => 'names'))
		);
		if ($property !== null) {
			$properties[] = $property;
		}
	}

	return $properties;
}

/**
 * The key for an attribute written straight on the product.
 *
 * Through Danish accent folding whatever language the site is in.
 * `sanitize_title` folds accents by locale, so "Fødeland" keys as `fodeland`
 * under English and `foedeland` under Danish, and the shop's choice at
 * Økoskabet would fall away the day the site changed language.
 *
 * @param string $name The attribute's name as the shop typed it.
 * @return string
 */
function oko_local_attribute_key(string $name): string
{
	return 'attribute:' . sanitize_title(remove_accents($name, 'da_DK'));
}

/**
 * One field, or nothing when there is nothing to say.
 *
 * A field the product has no value in is left out rather than sent empty: the
 * shop picks the producer field from this list, and a field that is blank on
 * every product it meets is not one anybody can choose usefully.
 *
 * @param string            $key
 * @param string            $label
 * @param array<int,mixed>  $values
 * @return array{key:string,label:string,values:array<int,string>}|null
 */
function oko_product_property(string $key, string $label, array $values): ?array
{
	$clean = array();
	foreach ($values as $value) {
		if (is_array($value) || is_object($value)) {
			continue;
		}
		$value = oko_plain_text((string) $value);
		if ($value !== '') {
			$clean[] = $value;
		}
	}

	if ($key === '' || empty($clean)) {
		return null;
	}

	$label = oko_plain_text($label);

	return array(
		'key'    => $key,
		'label'  => $label !== '' ? $label : $key,
		'values' => array_values(array_unique($clean)),
	);
}

/**
 * Text as a person wrote it, ready to be printed by somebody else.
 *
 * WordPress keeps an ampersand in a term name as `&amp;`, and stripping tags
 * leaves it that way. Sent on untouched, a packing slip prints "Hansen &amp;
 * Søn" in full. Økoskabet escapes again when it shows the text, so what it
 * needs from us is the plain characters.
 *
 * @param string $text
 * @return string
 */
function oko_plain_text(string $text): string
{
	// A non-breaking space survives an ordinary trim, and `&nbsp;` in a field
	// nobody filled in would then travel as a value with something in it.
	return (string) preg_replace('/^\s+|\s+$/u', '', html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES, 'UTF-8'));
}

/**
 * What the shop knows about one variation.
 *
 * The parent carries the list of everything that variation could have been.
 * The variation itself is one choice out of it, so each attribute the
 * variation pins is answered with that value alone. "Any" leaves the parent's
 * list standing, because that really is still the whole answer.
 *
 * @param \WC_Product_Variation $variation
 * @return array<int,array{key:string,label:string,values:array<int,string>}>
 */
function oko_variation_properties(\WC_Product_Variation $variation): array
{
	$parent = wc_get_product($variation->get_parent_id());
	if (! $parent instanceof \WC_Product || $parent instanceof \WC_Product_Variation) {
		return array();
	}

	$properties = oko_product_properties($parent);

	// WooCommerce keys a variation's choice by the parent attribute's name run
	// through `sanitize_title` in the site's language, so on an English site
	// "Fødeland" is `fodeland` here while its field is `attribute:foedeland`.
	// The parent's own attribute is what gets from one to the other.
	$attributes = array();
	foreach ($parent->get_attributes() as $attribute) {
		if ($attribute instanceof \WC_Product_Attribute) {
			$attributes[sanitize_title((string) $attribute->get_name())] = $attribute;
		}
	}

	foreach ($variation->get_attributes() as $name => $value) {
		$value     = (string) $value;
		$attribute = $attributes[(string) $name] ?? null;
		if ($value === '' || $attribute === null) {
			continue;
		}

		if ($attribute->is_taxonomy()) {
			$taxonomy = (string) $attribute->get_taxonomy();
			$key      = 'attribute:' . $taxonomy;
			$term     = get_term_by('slug', $value, $taxonomy);
			$value    = $term ? (string) $term->name : $value;
		} else {
			$key = oko_local_attribute_key((string) $attribute->get_name());
		}

		foreach ($properties as $i => $property) {
			if ($property['key'] === $key) {
				$properties[$i]['values'] = array(oko_plain_text($value));
			}
		}
	}

	return $properties;
}

/** Set once the shop has saved the plugin settings at least once. */
const OKO_SETTINGS_SAVED_OPTION = 'okoskabet_settings_saved_once';

add_action('cmb2_save_options-page_fields_' . O_TEXTDOMAIN . '_options', 'oko_remember_settings_were_saved', 5);

/**
 * Remember that the shop has been through the settings form.
 *
 * CMB2 shows a field's default whenever nothing is stored, and a multicheck
 * with every box unticked stores nothing. Without this, a shop that turns
 * completion off has it ticked again on the next page load, and there is no
 * way to say "no event completes my orders". Knowing the form has been saved
 * is what separates "never configured" from "configured as empty".
 *
 * @return void
 */
function oko_remember_settings_were_saved(): void
{
	update_option(OKO_SETTINGS_SAVED_OPTION, 1, false);
}

/**
 * The default for one of the two event lists, for a shop that has never saved.
 *
 * @param array<string,mixed> $suggested What a fresh install should start with.
 * @return array<int,string>
 */
function oko_event_default(array $suggested): array
{
	return get_option(OKO_SETTINGS_SAVED_OPTION) ? array() : $suggested;}
