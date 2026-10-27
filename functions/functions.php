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
 * A call to a merchant's Økoskabet, with the parts every one of them shares.
 *
 * The key, the timeout and the content type were written out at each of the
 * places that talk to Økoskabet, which is a place to change the day one of
 * them has to move — and a chance for them to drift apart unnoticed. The
 * answer is handed back untouched: what a refusal means differs at every call
 * site, and only the caller knows.
 *
 * @param array<string,mixed> $merchant The merchant record.
 * @param string              $method   `GET`, `POST`, `PUT` or `DELETE`.
 * @param string              $path     The path under the account's API root.
 * @param string|null         $body     An encoded body, or null for none.
 * @param int                 $timeout  Seconds to wait.
 * @return array<string,mixed>|\WP_Error The answer, as WordPress gives it.
 */
function oko_api_request(array $merchant, string $method, string $path, ?string $body = null, int $timeout = 15)
{
	$headers = array('Authorization' => (string) ($merchant['api_key'] ?? ''));

	$args = array(
		'method'  => $method,
		'timeout' => $timeout,
		'headers' => $headers,
	);

	// Only with something to describe. `Content-Type: application/json` on a
	// request with no body at all is zero bytes claiming to be JSON, which a
	// strict parser is entitled to refuse.
	if ($body !== null) {
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = $body;
	}

	return wp_remote_request(o_merchant_api_url($merchant) . $path, $args);
}

/**
 * The one holder for the configurations answered during this request.
 *
 * A memo that lasts a request is right for a checkout recalculating on every
 * keystroke and wrong for the request that has just saved new settings, so it
 * has to be droppable: `Merchants::purge_config_cache()` dropped the stored
 * copy, and the one held here outlived it.
 *
 * A holder both functions bind by reference, rather than a static inside the
 * getter reached through a merchant id nobody can have. A getter that also
 * means "forget everything" is a getter that lies about what it returns.
 *
 * @return array<string,array<string,mixed>>
 */
function &oko_merchant_configuration_memo(): array
{
	static $asked = array();

	return $asked;
}

/** Drop what this request has already been told, so the next read asks again. */
function oko_forget_merchant_configurations(): void
{
	$asked = &oko_merchant_configuration_memo();
	$asked = array();
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
	$asked = &oko_merchant_configuration_memo();

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

	$response = oko_api_request($merchant, 'GET', '/api/v1/configuration', null, 10);

	if (is_wp_error($response)
		|| wp_remote_retrieve_response_code($response) !== 200
		|| empty(wp_remote_retrieve_body($response))) {
		// Remembered too, briefly. The memo above only lasts the request, and
		// a checkout recalculates on every keystroke — so an Økoskabet that is
		// down cost a ten-second wait per letter the customer typed. Far
		// shorter than a good answer is kept: the shop is back to normal
		// within half a minute of Økoskabet being back.
		set_transient($transient_key, array(), 30);
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
 * The steps a shop can hang a choice on, in the order an order passes them.
 *
 * Printing the label is not a step a shipment passes — it is the moment the
 * boxes come into being — so it is the one entry that is ours rather than
 * Økoskabet's. It used to be written in front of the whole list, which put
 * it before "Registered" and read as though a label were printed before the
 * order existed. It belongs where it happens: after the order is registered,
 * before it is packed.
 *
 * After `registered` wherever that step is, rather than at a fixed index: the
 * list comes from the merchant's own account and Økoskabet may add a step to
 * it without a plugin release.
 *
 * @param string $merchant_id
 * @return array<string,string>
 */
function oko_merchant_events(string $merchant_id): array
{
	$label_printed = array('label_printed' => __('Label printed', O_TEXTDOMAIN));
	$statuses      = oko_merchant_statuses($merchant_id);

	if (! array_key_exists('registered', $statuses)) {
		return $label_printed + $statuses;
	}

	$events = array();
	foreach ($statuses as $code => $name) {
		$events[$code] = $name;
		if ($code === 'registered') {
			$events += $label_printed;
		}
	}

	return $events;
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

		// The bundle above is useless without its configuration, and this is
		// the only point in the request where inline script can still be
		// added. Do it here rather than leaving it to whoever draws the row.
		oko_enqueue_checkout_runtime();
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
/**
 * The script the picker is, its configuration, and the strings it shows.
 *
 * This has to happen during the page's own enqueue pass, because
 * `wp_enqueue_script` and `wp_add_inline_script` do nothing in an AJAX
 * request. The markup below may well be drawn in one: WooCommerce refreshes
 * the review-order table over AJAX whenever the basket or the shipping
 * choice changes, and the hook that draws the delivery row runs again there.
 *
 * It used to be bundled with that markup, so a checkout whose first render
 * did not draw the row — a brand-new session, before WooCommerce has settled
 * the customer — ended up with the Svelte bundle on the page and no
 * configuration for it to start on. The refresh then drew the buttons, since
 * markup in an AJAX fragment arrives fine, and the customer was left with a
 * delivery section that had every control except the date. No console error,
 * no request: nothing had anything to start. Reloading fixed it, which is
 * exactly what made it look like a caching problem.
 *
 * Runs once per request and says whether there is a merchant to draw for.
 */
function oko_enqueue_checkout_runtime(): bool
{
	// WordPress already remembers whether this ran; a static here would only
	// be a second, less truthful copy of that.
	if (wp_script_is('okoskabet-checkout-helpers', 'enqueued')) {
		return true;
	}

	$settings = o_get_settings();

	$resolved = oko_resolve_checkout_merchant();
	$merchant = $resolved['merchant'];

	if (empty($merchant['api_key'])) {
		return false;
	}

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

	return true;
}

function oko_render_delivery_ui(string $context = 'table'): void
{
	if (did_action(OKO_UI_DRAWN_ACTION)) {
		return;
	}

	// The configuration is normally already on the page by now; this is what
	// makes a checkout that calls the shortcode late still work.
	if (! oko_enqueue_checkout_runtime()) {
		return;
	}

	$settings = o_get_settings();

	$resolved = oko_resolve_checkout_merchant();
	$merchant = $resolved['merchant'];

	// Latched only once we are certain we are drawing. A shop without a key
	// renders nothing and stays free to render later in the same request, if
	// a key arrives — which is what a settings save inside checkout does.
	do_action(OKO_UI_DRAWN_ACTION);


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
	// The raw billing fields are hidden by the checkout stylesheet
	// (assets/src/styles/public.scss), not here: this row is not drawn in a
	// checkout that waits for an address before showing shipping.
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

	// A merchant without a key is as useless to the checkout as no merchant
	// at all — the delivery UI refuses to draw for either. The router can
	// hand one back when the cart routes to a merchant that has not been
	// given its key yet, so test what the caller actually needs rather than
	// whether the slot is filled.
	if (empty($resolved['merchant']) || empty($resolved['merchant']['api_key'])) {
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
const OKO_WHATS_NEW_KEY = 'address-suggestions-v1';

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
		__('Address suggestions: the checkout suggests addresses from the address register while the customer types, including floor and door.', O_TEXTDOMAIN),
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
		choice.style.cssText='position:absolute;left:'+pad+';right:'+pad+';bottom:16px;display:grid;gap:8px;';
		var bs=choice.querySelectorAll('.okoskabet-pre-order-toggle');
		for(var j=0;j<bs.length;j++){bs[j].style.margin='0';bs[j].style.whiteSpace='normal';}
		th.appendChild(choice);

		// How narrow a column may get before the two buttons go one above the
		// other. A fixed figure cannot know the shop's own wording or the
		// theme's type size: "FORUDBESTILLING" is one word, so where it does
		// not fit it does not wrap either — it runs out of its own button, as
		// it did on a phone. So measure the widest label on one line and let
		// that be the floor.
		//
		// After the move into the shipping cell, not before: until then the
		// buttons sit in their own row, which is served hidden, and a hidden
		// element measures zero. Measuring first answered 0 for both buttons
		// and quietly left the old fixed figure in force.
		//
		// And measured at max-content, because a button in the grid is already
		// stretched to the full cell: read as it stands, the widest label came
		// back as the whole cell, which would have put the two buttons under
		// each other at every width.
		var floor=0;
		for(j=0;j<bs.length;j++){
			bs[j].style.whiteSpace='nowrap';
			bs[j].style.width='max-content';
			floor=Math.max(floor,Math.ceil(bs[j].getBoundingClientRect().width));
			bs[j].style.width='';
			bs[j].style.whiteSpace='normal';
		}
		choice.style.gridTemplateColumns='repeat(auto-fit,minmax(min(100%,'+(floor||150)+'px),1fr))';
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

	// What the customer has typed, carried across the reload above.
	//
	// Pressing Forudbestilling in the middle of filling the form used to empty
	// it: the page came back from the server, and a name, an address and a
	// phone number went with it. WooCommerce remembers some of the address in
	// the session, but not the name, the e-mail or the phone, and the customer
	// had no way of knowing which. So the form is written down just before the
	// reload and put back when the page returns.
	//
	// sessionStorage, not a cookie: it stays in this tab, never goes to the
	// server, and is gone when the tab closes. Only what the customer typed
	// into this very form, and only to hand it straight back to them.
	var KEEP='okoskabet_typed';
	// The fields the choice itself decides, which the server sends back filled.
	var DECIDED=['billing_okoskabet_pre_order','billing_okoskabet_delivery_date','billing_okoskabet_shed_id','billing_okoskabet_pickup_location_id'];
	function okoskabetFields(){
		var form=document.querySelector('form.checkout, form[name="checkout"]');
		return form?form.querySelectorAll('input[type=text],input[type=email],input[type=tel],input[type=number],textarea'):[];
	}
	function okoskabetKeepTyped(){
		try{
			var out={},fs=okoskabetFields();
			for(var i=0;i<fs.length;i++){
				// Named one by one, not matched on "okoskabet": the choice
				// being made decides these four and the server fills them in
				// again, but it decides nothing about the flat the customer
				// typed or the order they are adding to. Matching on the name
				// threw those away too.
				if(!fs[i].name||DECIDED.indexOf(fs[i].name)>-1||!fs[i].value){continue;}
				out[fs[i].name]=fs[i].value;
			}
			sessionStorage.setItem(KEEP,JSON.stringify(out));
		}catch(err){}
	}
	function okoskabetRestoreTyped(){
		var saved;
		try{
			saved=sessionStorage.getItem(KEEP);
			sessionStorage.removeItem(KEEP);
		}catch(err){return;}
		if(!saved){return;}
		try{saved=JSON.parse(saved);}catch(err){return;}
		var fs=okoskabetFields(),touched=false;
		for(var i=0;i<fs.length;i++){
			// Never over the server's own answer: it knows things the customer
			// does not type, and a field it filled is a field it meant.
			if(!fs[i].name||fs[i].value||!saved[fs[i].name]){continue;}
			fs[i].value=saved[fs[i].name];
			touched=true;
		}
		// The address decides the shipping and the days, so the recalculation
		// has to see it. Without this the customer gets their address back and
		// prices worked out for the one before it.
		if(touched&&window.jQuery){window.jQuery(document.body).trigger('update_checkout');}
	}
	okoskabetRestoreTyped();

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
		if(SPLIT_ON){okoskabetKeepTyped();window.location.href=wanted==='1'?PRE_ORDER_URL:NORMAL_URL;return;}
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

    foreach ($rates as $rate_id => $rate) {
        $method_id = isset($rate->method_id) ? (string) $rate->method_id : '';
        if (!isset(OKO_SHIPPING_METHOD_KINDS[$method_id])) {
            continue;
        }
        $oko_method = OKO_SHIPPING_METHOD_KINDS[$method_id];
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

// Late, so the fields are the ones the customer will actually see. Whether we
// add a field of our own depends on whether the shop has one, and a shop that
// removes its second address line at a later priority than ours would leave
// the customer with neither: we would see it there, add nothing, and it would
// then be taken away.
add_filter('woocommerce_checkout_fields', 'custom_override_checkout_fields', 1000);
add_filter('woocommerce_checkout_get_value', 'oko_checkout_starts_without_last_orders_choice', 10, 2);

/**
 * One posted value as a string, whatever was posted.
 *
 * A form field can arrive as an array — `billing_order_id[]=` is a request
 * anyone can make — and casting that to a string is a PHP warning on the
 * checkout rather than an answer. Anything that is not a scalar is nothing.
 *
 * @param mixed $value The posted value.
 * @return string
 */
function oko_posted_string($value): string
{
	return is_scalar($value) ? trim((string) $value) : '';
}

/** The checkout fields a shop may ask the earlier order's number in. */
const OKO_ADDON_FIELDS = array('billing_order_id', 'billing_okoskabet_addon_order');

/**
 * One checkout field as it stands in the form being rendered.
 *
 * Read from the posted form, so it is already there while the shipping rates
 * are worked out: WooCommerce sends the whole form as `post_data` on every
 * recalculation, and that is also where the pre-order flag is read from. The
 * first name that is present wins, which is how a shop's own field and ours
 * can answer the same question.
 *
 * @param string ...$names The field names to try, in order.
 * @return string What the customer typed, or an empty string.
 */
function oko_posted_checkout_field(string ...$names): string
{
	// phpcs:disable WordPress.Security.NonceVerification -- read-only; WooCommerce verifies the checkout.
	foreach ($names as $name) {
		if (isset($_POST[$name])) {
			return oko_posted_string(wp_unslash($_POST[$name]));
		}
	}

	if (! isset($_POST['post_data']) || ! is_string($_POST['post_data'])) {
		return '';
	}

	// Parsed once however many fields are asked for: the form arrives as one
	// string, and parsing it per field was two passes over the whole checkout.
	static $parsed = null;
	static $parsed_from = null;
	$raw = wp_unslash($_POST['post_data']);
	// phpcs:enable WordPress.Security.NonceVerification

	if ($parsed_from !== $raw) {
		$parsed = array();
		parse_str($raw, $parsed);
		$parsed_from = $raw;
	}

	foreach ($names as $name) {
		if (isset($parsed[$name])) {
			return oko_posted_string($parsed[$name]);
		}
	}

	return '';
}

/**
 * Whether this shop takes additions to an earlier order at checkout.
 *
 * Off unless the shop turns it on: a field in every checkout that nobody asked
 * for is a question the customer has to work out how to ignore. Off is off for
 * both fields — ours is not drawn, and a shop's own `billing_order_id` is not
 * read either, so a number typed there is the shop's business, exactly as it
 * was before 1.5.3.
 *
 * Only the checkout asks this. An addition that was linked while it was on —
 * the earlier order's id is written on it — keeps riding along, being taken
 * off when cancelled and following its delivery, whatever the setting says
 * later: its goods are already on that shipment.
 *
 * @return bool
 */
function oko_shop_takes_additions(): bool
{
	return ! empty(o_get_settings()['_ask_for_addition_order']);
}

/**
 * The earlier order's number as the checkout has it, in either shop's field.
 *
 * Nothing when the shop does not take additions, which is what keeps every
 * part of the checkout that follows from it — the free freight, the notice,
 * the inherited delivery — switched off with it.
 */
function oko_addon_number_in_checkout(): string
{
	if (! oko_shop_takes_additions()) {
		return '';
	}

	return oko_posted_checkout_field(...OKO_ADDON_FIELDS);
}

/** The email the checkout is being filled in with, which an addition is matched on. */
function oko_addon_email_in_checkout(): string
{
	$email = oko_posted_checkout_field('billing_email');
	if ($email !== '' || ! function_exists('WC') || ! WC()->customer) {
		return $email;
	}

	return (string) WC()->customer->get_billing_email();
}

/**
 * The order an addition would be added to, if it can still take one.
 *
 * An addition rides along with a delivery already booked: the customer pays no
 * second freight, and the goods are packed with the first order. That only
 * holds while the first order is still ours to change — before the merchant
 * has sent it. Past that there is nothing to ride along with, and the checkout
 * says so rather than quietly promising a delivery that cannot happen.
 *
 * Refusals are deliberately quiet about why, except to the customer: a number
 * that belongs to somebody else answers exactly as a number that does not
 * exist, so a checkout cannot be used to find out what orders a shop has.
 *
 * @param string $number The earlier order's number, as the customer typed it.
 * @param string $email  The email on the checkout, which must match.
 * @return array{order:?\WC_Order,reason:string} The order, or why not.
 */
function oko_addon_parent_order(string $number, string $email): array
{
	$refused = static function (string $reason): array {
		return array('order' => null, 'reason' => $reason);
	};

	$number = trim($number);
	if ($number === '') {
		return $refused('');
	}

	// The email check below would not catch a wrong order: a numbering plugin
	// shows "#1042" where the id is something else, and an absint() of the
	// printed number lands on another of this customer's orders, which passes
	// the email check because it is theirs too.
	// Looked up without writing anything. This runs for anyone filling in a
	// checkout, on every recalculation, before the email has been checked.
	$order = oko_order_for_reference($number, false);
	if ($order === null) {
		return $refused('unknown');
	}

	// The customer's own order, and nobody else's.
	$email = strtolower(trim($email));
	if ($email === '' || strtolower((string) $order->get_billing_email()) !== $email) {
		return $refused('unknown');
	}

	// It has to be an Økoskabet delivery to ride along with.
	$delivery_date = (string) $order->get_meta('_billing_okoskabet_delivery_date', true);
	if ($delivery_date === '') {
		return $refused('not_a_delivery');
	}

	// And the merchant must not have sent it yet.
	if (! oko_order_can_still_take_more($order, $delivery_date)) {
		return $refused('too_late');
	}

	return array('order' => $order, 'reason' => '');
}

/**
 * The order this checkout would be added to, worked out once per request.
 *
 * Asked by the rate filter, the order being created and the checkout notice,
 * and each answer costs a call to Økoskabet. One answer per request, so a
 * checkout that recalculates does not ask three times.
 *
 * @return array{order:?\WC_Order,reason:string}
 */
function oko_addon_in_checkout(): array
{
	static $answer = null;
	static $asked_for = null;

	$number = oko_addon_number_in_checkout();
	if ($answer !== null && $asked_for === $number) {
		return $answer;
	}

	$asked_for = $number;
	$answer    = oko_addon_parent_order($number, oko_addon_email_in_checkout());

	return $answer;
}

add_filter('woocommerce_cart_shipping_packages', 'oko_addon_in_the_package');

/**
 * Put the addition into the package, so the rates are worked out again.
 *
 * WooCommerce keeps a package's rates in the session under a hash of the
 * package, and a checkout that recalculates reuses them unless that hash
 * changes. Our field is not part of a package, so zeroing the freight had no
 * effect until something else — an address, an item — happened to change: the
 * customer saw the full freight on an order that rides along for nothing.
 *
 * @param array<int,array<string,mixed>> $packages The shipping packages.
 * @return array<int,array<string,mixed>>
 */
function oko_addon_in_the_package(array $packages): array
{
	// The answer, not the number the customer is still typing. WooCommerce
	// hashes the package to decide whether the rates may be reused, so putting
	// the digits in meant a whole rate calculation per keystroke — and a
	// number that turns out not to count would still have thrown the rates
	// away. Two states: this basket rides along, or it does not.
	$rides_along = oko_addon_in_checkout()['order'] !== null;
	if (! $rides_along) {
		return $packages;
	}

	foreach ($packages as $i => $package) {
		$packages[$i]['okoskabet_addon'] = '1';
	}

	return $packages;
}

add_filter('woocommerce_package_rates', 'oko_addon_rides_along_for_free', 20, 2);

/**
 * An addition pays no second freight.
 *
 * The goods go in the box already booked, so there is nothing to charge for.
 * Only our own methods are zeroed: another shipping method on the same
 * checkout is somebody else's price, and a plugin that quietly made it free
 * would be taking money out of the shop's till.
 *
 * @param array<string,\WC_Shipping_Rate> $rates   The rates on offer.
 * @param array<string,mixed>             $package The package (unused).
 * @return array<string,\WC_Shipping_Rate>
 */
function oko_addon_rides_along_for_free(array $rates, array $package): array
{
	if (oko_addon_in_checkout()['order'] === null) {
		return $rates;
	}

	foreach ($rates as $rate) {
		if ($rate instanceof \WC_Shipping_Rate && strpos($rate->get_method_id(), 'hey_okoskabet_') === 0) {
			$rate->set_cost(0);
			$rate->set_taxes(array());
		}
	}

	return $rates;
}

add_action('woocommerce_review_order_before_shipping', 'oko_addon_say_if_it_is_too_late');

/**
 * Tell the customer when the earlier order can no longer take anything.
 *
 * Said where the freight is about to be charged, because that is the thing
 * that changes: the order still goes through, with its own delivery and the
 * shop's ordinary price. Silence here would be a customer expecting a free
 * ride and finding a second delivery charge after paying.
 */
function oko_addon_say_if_it_is_too_late(): void
{
	$answer = oko_addon_in_checkout();
	if ($answer['order'] !== null || $answer['reason'] === '') {
		return;
	}

	// Not before there is an email to check against. The email field comes
	// after the address in the form, so a customer who fills the order number
	// first would otherwise be told their own order number is not recognised,
	// for as long as it takes them to reach the field below.
	if ($answer['reason'] === 'unknown' && oko_addon_email_in_checkout() === '') {
		return;
	}

	// "Already sent" is not always true — the order may be cancelled, or past
	// the day's cutoff — and a shop should not tell a customer something that
	// is not so. What they need is the same either way: it cannot take more.
	//
	// On the shop's own add-on method there is no delivery of its own to
	// promise: the order goes to the shop on that method, at the shop's price
	// for it, and nobody sends it anywhere. Only say what is so — it is not
	// added — and leave what happens next to the shop.
	if (oko_checkout_chose_addon_shipping()) {
		$message = $answer['reason'] === 'too_late'
			? __('That order can no longer take anything more, so this is not added to it.', O_TEXTDOMAIN)
			: __('We cannot find that order number on your email address, so this is not added to an earlier order.', O_TEXTDOMAIN);
	} else {
		$message = $answer['reason'] === 'too_late'
			? __('That order can no longer take anything more. This is ordered on its own, with its own delivery.', O_TEXTDOMAIN)
			: __('We cannot find that order number on your email address. This is ordered on its own, with its own delivery.', O_TEXTDOMAIN);
	}

	echo '<tr class="okoskabet-addon-notice"><td colspan="2">' . esc_html($message) . '</td></tr>';
}

add_action('woocommerce_checkout_create_order', 'oko_addon_inherits_the_delivery', 25, 2);

/**
 * An addition is delivered with the order it belongs to, not on its own.
 *
 * The customer chose the day, the shed and the pickup point once already; the
 * addition rides in that same box, so asking again could only produce a second
 * answer that cannot be honoured. Copied onto the order rather than looked up
 * later, so the order says for itself what was agreed.
 *
 * @param \WC_Order           $order The order being created.
 * @param array<string,mixed> $data  Posted checkout data (unused).
 */
function oko_addon_inherits_the_delivery($order, $data): void
{
	if (! $order instanceof \WC_Order) {
		return;
	}

	// Only when Økoskabet is carrying it, or the customer chose a shipping
	// method the shop has said means exactly this. A customer who types the
	// earlier order's number and then picks the shop's own courier pays that
	// courier's freight; putting the goods on our delivery as well would send
	// them twice, or send them with the wrong one.
	if (! oko_order_is_ours($order) && ! oko_order_chose_addon_shipping($order)) {
		return;
	}

	$parent = oko_addon_in_checkout()['order'];
	if (! $parent instanceof \WC_Order) {
		return;
	}

	// The id, and only the id. A shop with a numbering plugin prints "#1042"
	// where the id is something else, and the goods are looked up again by
	// this value when the addition is sent — an absint() of the printed number
	// would find the wrong order, or none, and the whole shipment would be
	// rewritten with only the addition's goods on it. The number the notes
	// show is read off the order the id finds, so the two can never disagree
	// about which order this belongs to.
	$order->update_meta_data(OKO_ADDON_PARENT_ID_META, (string) $parent->get_id());

	foreach (array(
		'_billing_okoskabet_delivery_date',
		'_billing_okoskabet_shed_id',
		'_billing_okoskabet_pickup_location_id',
	) as $key) {
		$order->update_meta_data($key, (string) $parent->get_meta($key, true));
	}
}

/** The earlier order's id, written on the addition once it is settled. */
const OKO_ADDON_PARENT_ID_META = '_okoskabet_addon_of_id';

/**
 * Set on an addition this plugin put on hold because its goods did not get
 * onto the delivery. Read by the status hook, so the hold itself does not set
 * off another attempt; cleared once the goods are on the delivery.
 */
const OKO_ADDITION_HELD_META = '_okoskabet_addition_held';

/** The additions an order already carries, in the order they were added. */
const OKO_ADDITIONS_META = '_okoskabet_additions';

/**
 * The ids of the additions an order carries, as ids.
 *
 * Read in four places, three of which cast and one of which did not. One
 * reader so they cannot disagree about the shape.
 *
 * @param \WC_Order $parent
 * @return array<int,int>
 */
function oko_addition_ids(\WC_Order $parent): array
{
	return array_values(array_filter(
		array_map('intval', (array) $parent->get_meta(OKO_ADDITIONS_META, true)),
		static function (int $id): bool {
			return $id > 0;
		}
	));
}

/**
 * The order a shipment reference names, and only that order.
 *
 * `wc_get_order()` takes an id; a reference is the number the shop prints.
 * A numbering plugin makes those two different values — id 5500 printing as
 * 1042 — so the number is looked up and then checked against what the order
 * found actually prints. Without the check a reference lands on a stranger's
 * order: their address rewritten, their order noted on.
 *
 * @param string $reference The shipment reference.
 * @param bool   $remember  Whether an order found by its number may be given
 *                          the reference to keep; see the lookup itself.
 * @return \WC_Order|null
 */
function oko_order_for_reference(string $reference, bool $remember = true): ?\WC_Order
{
	// One lookup, in `oko_order_for_shipment_reference()`. This used to read
	// the reference as an order id and check the number afterwards, which
	// never hit the wrong order but could not find one at a shop whose
	// numbering plugin makes id and number differ — Avokadokontoret's, and
	// their webhooks answered 404 (#52).
	$found = oko_order_for_shipment_reference($reference, $remember);

	if ($found instanceof \WC_Order) {
		return $found;
	}

	// Two orders claim the reference. Both callers here read `null` as "no
	// such order", which is the right answer — we cannot say which one — but
	// it is not the same thing as not finding one, and the shop would have no
	// way to tell the two apart. The webhook answers 409 for this; these
	// paths say it to the log.
	if (is_wp_error($found)) {
		error_log(sprintf(
			'okoskabet_woocommerce_plugin: reference %s matches more than one order; treated as unknown',
			$reference
		));
	}

	return null;
}

/**
 * The additions already on an order, oldest first.
 *
 * Kept as a list on the order being added to, written when an addition
 * actually reaches Økoskabet. A query over the additions' own meta would say
 * the same thing, but it answers differently depending on order storage and
 * status, and this is read on the path that rewrites a shipment's whole
 * contents — it has to be the same answer every time.
 *
 * Only additions that got through are in it, so an addition that failed is
 * never resurrected into a delivery the shop was told had not gone.
 *
 * @param \WC_Order $parent The order being added to.
 * @param \WC_Order $except The addition being sent right now.
 * @return array<int,\WC_Order>
 */
function oko_additions_to($parent, $except): array
{
	if (! $parent instanceof \WC_Order) {
		return array();
	}

	$except_id = $except instanceof \WC_Order ? $except->get_id() : 0;
	$ids       = oko_addition_ids($parent);

	$orders = array();
	foreach ($ids as $id) {
		if ((int) $id === $except_id) {
			continue;
		}

		$order = wc_get_order((int) $id);
		if ($order instanceof \WC_Order) {
			$orders[] = $order;
		}
	}

	return $orders;
}

/**
 * Remember that an addition made it onto the order.
 *
 * Written only after Økoskabet has taken it, so the list is what the shipment
 * actually holds rather than what was attempted.
 *
 * @param \WC_Order $parent   The order added to.
 * @param \WC_Order $addition The addition.
 */
function oko_remember_addition($parent, $addition): void
{
	if (! $parent instanceof \WC_Order || ! $addition instanceof \WC_Order) {
		return;
	}

	$ids = oko_addition_ids($parent);
	if (in_array($addition->get_id(), $ids, true)) {
		return;
	}

	$ids[] = $addition->get_id();
	$parent->update_meta_data(OKO_ADDITIONS_META, $ids);
	$parent->save();
}

/**
 * And forget it again, once its goods are off the shipment.
 *
 * @param \WC_Order $parent   The order added to.
 * @param \WC_Order $addition The addition.
 */
function oko_forget_addition($parent, $addition): void
{
	if (! $parent instanceof \WC_Order || ! $addition instanceof \WC_Order) {
		return;
	}

	$ids = array_values(array_diff(oko_addition_ids($parent), array($addition->get_id())));

	$parent->update_meta_data(OKO_ADDITIONS_META, $ids);
	$parent->save();
}

/**
 * Hold an order's additions still while one of them is written.
 *
 * Writing an addition reads the order's list of additions, sends the whole
 * list of goods with the new ones at the bottom, and then puts the addition
 * on the list. Two additions to one order written at the same moment — two
 * bank transfers marked paid from two tabs, a gateway's callbacks crossing —
 * each read the list before the other had written it. The later PUT replaced
 * the earlier one's goods, the later list replaced the earlier one's id, and
 * one addition was gone from the delivery and from the order with nothing to
 * ever put it back. Rare, and silent when it happens.
 *
 * A row in the options table, inserted only where there is none: the table's
 * unique name makes the insert itself the test, so two requests cannot both
 * get it. Not add_option(), which asks first and then inserts "on duplicate
 * key update" — two requests asking at once are both told there is none, and
 * both succeed. Not a transient, which an object cache keeps outside the
 * table, with no such promise.
 *
 * A hold nobody let go of, from a request that died before its `finally`, is
 * taken over once it is OKO_ADDITION_LOCK_SECONDS old — by one request only:
 * the take-over names the stale value it replaces.
 *
 * The value is the hold's own: when it was taken, and a part nobody else's
 * can share, handed back so only this request can let go of it. A holder that
 * overran the limit and then finished used to delete the row by its name —
 * the hold another request had taken over, while that one was still writing.
 *
 * The hold also names the addition it was taken for, so a request that
 * finds an order held can tell another addition from this one written twice —
 * a gateway's callback and the customer's return both marking it paid. See
 * oko_additions_held_for().
 *
 * @param int $parent_id The order the additions ride on.
 * @param int $for       The addition being written, or 0.
 * @return string The hold, for oko_unlock_additions_to(); empty when another
 *                request has it.
 */
function oko_lock_additions_to(int $parent_id, int $for = 0): string
{
	global $wpdb;

	$name = OKO_ADDITION_LOCK_PREFIX . $parent_id;
	$hold = time() . ':' . wp_generate_uuid4() . ':' . $for;

	$taken = $wpdb->query($wpdb->prepare(
		"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
		$name,
		$hold
	));
	if ((int) $taken === 1) {
		$here             = &oko_additions_held_here();
		$here[$parent_id] = $hold;
		return $hold;
	}

	$since = (string) $wpdb->get_var($wpdb->prepare(
		"SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s",
		$name
	));
	// The value starts with the time it was taken, which is all (int) reads.
	if ($since === '' || (int) $since > time() - OKO_ADDITION_LOCK_SECONDS) {
		return '';
	}

	$taken = $wpdb->query($wpdb->prepare(
		"UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
		$hold,
		$name,
		$since
	));

	if ((int) $taken !== 1) {
		return '';
	}
	$here             = &oko_additions_held_here();
	$here[$parent_id] = $hold;

	return $hold;
}

/**
 * The holds on additions this request has, by the order they are on.
 *
 * A write under a hold saves the order, and the save runs the order's own
 * resend, which takes the same hold before it sends goods (#81). It is this
 * request's already, so that resend goes ahead rather than finding it taken.
 *
 * @return array<int,string>
 */
function &oko_additions_held_here(): array
{
	static $held = array();

	return $held;
}

/**
 * And let go of them again — this hold, and never one taken over from it.
 *
 * @param int    $parent_id The order the additions ride on.
 * @param string $hold      What oko_lock_additions_to() handed back.
 */
function oko_unlock_additions_to(int $parent_id, string $hold): void
{
	global $wpdb;

	$wpdb->delete($wpdb->options, array(
		'option_name'  => OKO_ADDITION_LOCK_PREFIX . $parent_id,
		'option_value' => $hold,
	));

	$here = &oko_additions_held_here();
	if (($here[$parent_id] ?? '') === $hold) {
		unset($here[$parent_id]);
	}
}

/**
 * The addition the hold on an order's additions was taken for: 0 when it is
 * not held, or held for something else.
 *
 * @param int $parent_id The order the additions ride on.
 */
function oko_additions_held_for(int $parent_id): int
{
	global $wpdb;

	$hold  = (string) $wpdb->get_var($wpdb->prepare(
		"SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s",
		OKO_ADDITION_LOCK_PREFIX . $parent_id
	));
	$parts = explode(':', $hold);

	return count($parts) === 3 ? (int) $parts[2] : 0;
}

/** The name of the hold on an order's additions, before the order's id. */
const OKO_ADDITION_LOCK_PREFIX = '_oko_addition_lock_';

/**
 * How long a hold on an order's additions is honoured, in seconds.
 *
 * Longer than any write that holds it can take: the questions an addition
 * asks Økoskabet before it is sent, a PUT of fifteen seconds, and the save
 * after it can each wait on Økoskabet when it is slow, and PHP does not count
 * that wait against its own time limit. A hold broken while its write is
 * still going is the very race it exists to stop; one kept too long only
 * holds an addition for the shop to try again.
 */
const OKO_ADDITION_LOCK_SECONDS = 60;

/**
 * An addition's goods, each line named as one.
 *
 * A packer who has already picked this order will not pick it again, so the
 * extra goods have to announce themselves on the line itself — the slip prints
 * the order's own title, and that is the one thing we decide. The product is
 * found by its id, so this is what the paper says and nothing more.
 *
 * @param \WC_Order $order The addition.
 * @return array<int,array<string,mixed>>
 */
function oko_addition_lines($order, ?array $merchant = null): array
{
	if (! $order instanceof \WC_Order) {
		return array();
	}

	return array_map(static function (array $line): array {
		$line['name'] = sprintf(
			/* translators: %s: the product's name. */
			__('ADDITION — %s', O_TEXTDOMAIN),
			$line['name']
		);

		return $line;
	}, oko_order_line_items($order, $merchant));
}

/**
 * Write the delivery's whole list of goods, and a line saying what changed.
 *
 * `PUT /shipments/{reference}` replaces the lines it is given, and no endpoint
 * answers with the lines a shipment already has — so the whole list goes every
 * time one of them changes, rebuilt from the WooCommerce orders that make it
 * up. Nothing else edits a shipment's lines: the backoffice screens only show
 * them, and the other two writers are the subscription engine and the Shopify
 * app, neither of which touches a WooCommerce order's shipment.
 *
 * The note is read in the same breath and the line added to what is already
 * there, because a PUT replaces that too and the customer's own message is in
 * it. The remembered copy is dropped afterwards: what we just wrote is not
 * what it says any more, and the next addition has to read the note we left.
 *
 * @param \WC_Order            $parent    The order holding the delivery.
 * @param array<int,\WC_Order> $additions Its additions, oldest first.
 * @param array<string,mixed>  $merchant  The merchant record.
 * @param string               $note_line What the packing room is told.
 * @return int The HTTP status, or 0 when nothing could be sent at all.
 */
function oko_write_shipment_contents(\WC_Order $parent, array $additions, array $merchant, string $note_line): int
{
	// What Økoskabet booked it under, which is not always its number: a shop
	// with a numbering plugin has two, and only one of them is ours (#52).
	$reference = oko_shipment_reference($parent);

	$body = array('line_items' => oko_lines_for($parent, $additions, $merchant));

	$shipment = oko_shipment_at_okoskabet($reference, $merchant);
	if ($shipment !== null) {
		$notes         = (string) ($shipment['notes'] ?? '');
		$body['notes'] = $notes !== '' ? $notes . "\n" . $note_line : $note_line;
	}

	$encoded = wp_json_encode($body);
	if ($encoded === false) {
		return 0;
	}

	$response = oko_api_request($merchant, 'PUT', '/api/v1/shipments/' . rawurlencode($reference), $encoded);

	oko_forget_shipment($reference, $merchant);

	$code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);

	// And the goods are now what Økoskabet holds, so the record says so. The
	// caller saves the order next, to keep the list of additions, and that
	// save runs the order's own resend: against a record without these goods
	// it found the list it had just been sent and sent it a second time.
	//
	// Only for an order that has a record. One with a fingerprint and no
	// record gets one at that save, its additions' goods included — see
	// oko_sent_by_1_5_2(). Meta only, and kept even when the caller has no
	// reason to save after all.
	$sent = $parent->get_meta(OKO_SENT_META, true);
	if ($code === 200 && is_array($sent)) {
		$sent['line_items'] = $body['line_items'];
		oko_remember_what_was_sent($parent, $sent);
		$parent->save_meta_data();
	}

	return $code;
}

/**
 * Take a cancelled, or fully refunded, addition's goods off the delivery they
 * rode along with.
 *
 * The list is sent again without it. Only then is it dropped from the order's
 * own list — a write that did not get through leaves the goods where they are,
 * and the shop should see that rather than a record saying they are gone.
 *
 * Only while holding the earlier order's additions still, as any write of its
 * list of goods does; see oko_lock_additions_to().
 *
 * @param \WC_Order           $order     The cancelled or refunded addition.
 * @param int                 $parent_id The order it rode along with.
 * @param array<string,mixed> $merchant  Unused: the shipment is the earlier
 *                                       order's, and so is the account it is
 *                                       written under. Kept for the callers.
 */
function oko_take_addition_off_its_order($order, int $parent_id, array $merchant): void
{
	if (! $order instanceof \WC_Order) {
		return;
	}

	// An addition the order never took is nothing to take off it, and nothing
	// to wait for a hold over: asked before the hold as well as inside it, or a
	// cancellation of an addition that never got on was told the goods "may
	// still be on that delivery" whenever another addition was being written.
	$parent = wc_get_order($parent_id);
	if (! $parent instanceof \WC_Order || ! in_array($order->get_id(), oko_addition_ids($parent), true)) {
		return;
	}

	// Already cancelled or refunded, so it cannot be put on hold to try again
	// later. The shop is told, the way it is told when the write itself fails.
	// Unless the hold is this addition's own: the cancellation is being
	// handled by another request at this moment.
	$hold = oko_lock_additions_to($parent_id, $order->get_id());
	if ($hold === '') {
		if (oko_additions_held_for($parent_id) === $order->get_id()) {
			return;
		}
		$order->add_order_note(sprintf(
			/* translators: %s: the order the addition rode along with. */
			__('The goods could not be taken off order %s: another addition to it was being written at the same moment. They may still be on that delivery.', O_TEXTDOMAIN),
			oko_shipment_reference($parent)
		));
		return;
	}

	try {
		oko_take_addition_off_while_its_order_is_held($order, $parent_id, $merchant);
	} finally {
		oko_unlock_additions_to($parent_id, $hold);
	}
}

/**
 * The taking off itself, once the earlier order's additions are held still.
 *
 * The earlier order is read here, inside the hold, so its list of additions is
 * the one the last write left — not one read before another write began.
 *
 * @param \WC_Order           $order     The cancelled or refunded addition.
 * @param int                 $parent_id The order it rode along with.
 * @param array<string,mixed> $merchant  Unused; see above.
 */
function oko_take_addition_off_while_its_order_is_held($order, int $parent_id, array $merchant): void
{
	$parent = wc_get_order($parent_id);
	if (! $order instanceof \WC_Order || ! $parent instanceof \WC_Order) {
		return;
	}

	// The shipment is the earlier order's, so it is written under that
	// order's account — see oko_send_addition_to_its_order().
	$merchant = oko_merchant_for_order($parent);

	// An addition the order never took is nothing to take off it. That is a
	// cancellation of an order Økoskabet was never told about — writing the
	// shipment again would be a call that changes nothing, and a note saying
	// goods were removed that were never there.
	if (! in_array($order->get_id(), oko_addition_ids($parent), true)) {
		return;
	}

	$reference = oko_shipment_reference($parent);
	$code      = oko_write_shipment_contents(
		$parent,
		oko_additions_to($parent, $order),
		$merchant,
		$order->get_status() === 'refunded'
			? sprintf(
				/* translators: %s: the refunded addition's order number. */
				__('ADDITION: order %s was refunded. Its goods have been taken off the list.', O_TEXTDOMAIN),
				$order->get_order_number()
			)
			: sprintf(
				/* translators: %s: the cancelled addition's order number. */
				__('ADDITION: order %s was cancelled. Its goods have been taken off the list.', O_TEXTDOMAIN),
				$order->get_order_number()
			)
	);

	if ($code !== 200) {
		$order->add_order_note(sprintf(
			/* translators: 1: the order the addition rode along with, 2: the HTTP status or 0 when unreachable. */
			__('The goods could not be taken off order %1$s (%2$s). They may still be on that delivery.', O_TEXTDOMAIN),
			$reference,
			(string) $code
		));
		return;
	}

	oko_forget_addition($parent, $order);

	$order->add_order_note(sprintf(
		/* translators: %s: the order the addition rode along with. */
		__('The goods were taken off order %s.', O_TEXTDOMAIN),
		$reference
	));
}

/**
 * Put an addition's goods on the shipment they ride along with.
 *
 * A refusal leaves the WooCommerce order alone and unmarked, so the shop sees
 * an order that did not reach Økoskabet rather than one that silently did not
 * get its goods. The note says which order was meant.
 *
 * Only while holding the earlier order's additions still; see
 * oko_lock_additions_to(). An addition that arrives while another is being
 * written is held for the shop, like any other that did not get on.
 *
 * @param \WC_Order           $order     The addition.
 * @param int                 $parent_id The earlier order's WooCommerce id.
 * @param array<string,mixed> $merchant  Unused: the shipment is the earlier
 *                                       order's, and so is the account it is
 *                                       written under. Kept for the callers.
 */
function oko_send_addition_to_its_order($order, int $parent_id, array $merchant): void
{
	if (! $order instanceof \WC_Order) {
		return;
	}

	// Held for this very addition, by another request writing it at the same
	// moment — a gateway's callback and the customer's return both marking it
	// paid. That request puts it on; holding it here told the shop the goods
	// were not added while they were.
	$hold = oko_lock_additions_to($parent_id, $order->get_id());
	if ($hold === '' && oko_additions_held_for($parent_id) === $order->get_id()) {
		return;
	}
	if ($hold === '') {
		$parent = wc_get_order($parent_id);
		oko_hold_addition($order, sprintf(
			/* translators: %s: the earlier order's number. */
			__('Another addition to order %s was being written at the same moment, so nothing was added to it. Økoskabet has not been told about this order.', O_TEXTDOMAIN),
			$parent instanceof \WC_Order ? oko_shipment_reference($parent) : (string) $parent_id
		));
		return;
	}

	try {
		oko_send_addition_while_its_order_is_held($order, $parent_id, $merchant);
	} finally {
		oko_unlock_additions_to($parent_id, $hold);
	}
}

/**
 * The sending itself, once the earlier order's additions are held still.
 *
 * The earlier order is read here, inside the hold, so its list of additions is
 * the one the last write left — not one read before another write began.
 *
 * @param \WC_Order           $order     The addition.
 * @param int                 $parent_id The earlier order's WooCommerce id.
 * @param array<string,mixed> $merchant  Unused; see above.
 */
function oko_send_addition_while_its_order_is_held(\WC_Order $order, int $parent_id, array $merchant): void
{
	// The earlier order's goods have to be sent again with the addition's: a
	// PUT replaces the shipment's lines, so a list that is missing them would
	// delete them. If that order cannot be read, nothing is sent at all —
	// better an order the shop can see did not go through than a delivery
	// quietly emptied of what the customer first bought.
	//
	// By id, never by the number the customer typed. A shop with a numbering
	// plugin prints "#1042" where the id is something else entirely, and an
	// absint() of the printed number would find a stranger's order — whose
	// goods would then be the whole of this shipment.
	$parent = wc_get_order($parent_id);
	if (! $parent instanceof \WC_Order) {
		oko_hold_addition($order, sprintf(
			/* translators: %s: the earlier order's id. */
			__('Order %s could not be read, so nothing was added to it. Økoskabet has not been told about this order.', O_TEXTDOMAIN),
			(string) $parent_id
		));
		return;
	}

	// Already on it: written by a request that held the order first, while
	// this one waited for nothing but its own turn. Sent again, it would be
	// on the list twice.
	if (in_array($order->get_id(), oko_addition_ids($parent), true)) {
		return;
	}

	$reference = oko_shipment_reference($parent);

	// Under the earlier order's account, not this one's. The shipment being
	// written is the earlier order's, and the addition's own basket can route
	// to another merchant: its key then asked an account that has no such
	// shipment, and the customer had been charged no freight for goods that
	// were never booked.
	$merchant = oko_merchant_for_order($parent);

	// Asked again here, not only in the checkout. A bank transfer is marked
	// paid days after it was ordered, and by then the delivery may have left
	// the merchant — the customer would have been charged no freight for goods
	// with nothing left to ride along with.
	$delivery_date = (string) $parent->get_meta('_billing_okoskabet_delivery_date', true);
	if ($delivery_date === '' || ! oko_order_can_still_take_more($parent, $delivery_date)) {
		oko_hold_addition($order, sprintf(
			/* translators: %s: the earlier order's number. */
			__('Order %s can no longer take anything more, so nothing was added to it. Økoskabet has not been told about this order.', O_TEXTDOMAIN),
			$reference
		));
		return;
	}

	// Every addition this order has, not only the one being sent. A PUT
	// replaces the lines, so building the list from the parent and this
	// addition alone deleted the goods of any addition before it — a customer
	// who adds twice to the same Christmas delivery paid for the first lot and
	// would never have received it.
	$additions   = oko_additions_to($parent, $order);
	$additions[] = $order;

	$code = oko_write_shipment_contents($parent, $additions, $merchant, sprintf(
		/* translators: 1: the addition's order number, 2: the date it was placed. */
		__('ADDITION: order %1$s was added %2$s. Its goods are at the bottom of the list.', O_TEXTDOMAIN),
		$order->get_order_number(),
		date_i18n((string) get_option('date_format'))
	));

	if ($code !== 200) {
		oko_hold_addition($order, sprintf(
			/* translators: 1: the earlier order's number, 2: the HTTP status or 0 when unreachable. */
			__('The goods could not be added to order %1$s (%2$s). Økoskabet has not been told about this order.', O_TEXTDOMAIN),
			$reference,
			(string) $code
		));
		return;
	}

	oko_remember_addition($parent, $order);

	$order->delete_meta_data(OKO_ADDITION_HELD_META);
	$order->update_meta_data('billing_okoskabet_done', 'yes');
	$order->add_order_note(sprintf(
		/* translators: %s: the earlier order's number. */
		__('The goods were added to order %s. No separate delivery.', O_TEXTDOMAIN),
		$reference
	));
	$order->save();
}

/**
 * Put an addition whose goods did not get onto its delivery on hold.
 *
 * What happens to them is the shop's to decide (Marc, 21/10): send them some
 * other way, refund them, or try again. Left in processing, as it was, the
 * order looked like any other on its way, and a bank transfer marked paid
 * after the delivery had left was paid, charged no freight and never sent —
 * with only a note to say so.
 *
 * On hold rather than failed: the money is still there, and a shop that sets
 * it back to processing asks once more, which is the way to try again after
 * Økoskabet could not be reached.
 *
 * @param \WC_Order $order The addition.
 * @param string    $why   What went wrong, said first in the note.
 */
function oko_hold_addition(\WC_Order $order, string $why): void
{
	$note = $why . ' ' . __('The order is on hold for the shop to decide: set it to processing to try again, or send or refund the goods some other way.', O_TEXTDOMAIN);

	// Marked before the status changes: the change runs the status hook again,
	// with this very order, and the mark is what stops it from asking again.
	$order->update_meta_data(OKO_ADDITION_HELD_META, 'yes');

	if ($order->get_status() === 'on-hold') {
		$order->add_order_note($note);
		$order->save();
		return;
	}

	$order->update_status('on-hold', $note);
}

/**
 * The shipment in an answer from Økoskabet, whichever way it is wrapped.
 *
 * `GET /shipments/{reference}` answers with the shipment itself, while `POST`
 * and `PUT` answer with it under `shipment` beside the errors. Reading only the
 * wrapped shape made every `GET` look like a shipment with no status at all —
 * which, on an addition, read as "that order has already been sent" and
 * refused every one of them.
 *
 * @param string $body The response body.
 * @return array<string,mixed>|null The shipment, or null when there is none.
 */
function oko_shipment_from_body(string $body): ?array
{
	$decoded = json_decode($body, true);
	if (! is_array($decoded)) {
		return null;
	}

	if (isset($decoded['shipment']) && is_array($decoded['shipment'])) {
		return $decoded['shipment'];
	}

	return isset($decoded['shipment_reference']) ? $decoded : null;
}

/** The key the shipment is remembered under, and the way to forget it. */
function oko_shipment_transient_key(string $reference, array $merchant): string
{
	return O_TEXTDOMAIN . '_shipment_' . md5(((string) ($merchant['id'] ?? '')) . '|' . $reference);
}

/** Forget a remembered shipment, when we know it has changed. */
function oko_forget_shipment(string $reference, array $merchant): void
{
	delete_transient(oko_shipment_transient_key($reference, $merchant));
}

/**
 * The shipment as Økoskabet has it, asked once.
 *
 * Both the status and the note are read from it, and a checkout that
 * recalculates asks again on every keystroke once an order number is in the
 * field — so the answer is held for a minute. A minute is short enough that a
 * delivery leaving the merchant is noticed while the customer is still
 * filling the form, and long enough that typing an address does not cost a
 * round trip a letter.
 *
 * `null` says we could not ask. The callers then refuse rather than guess.
 *
 * @param string              $reference The shipment reference.
 * @param array<string,mixed> $merchant  The merchant record.
 * @return array<string,mixed>|null
 */
function oko_shipment_at_okoskabet(string $reference, array $merchant): ?array
{
	$key    = oko_shipment_transient_key($reference, $merchant);
	$cached = get_transient($key);
	if (is_array($cached)) {
		return isset($cached[OKO_NO_ANSWER]) ? null : $cached;
	}

	$response = oko_api_request($merchant, 'GET', '/api/v1/shipments/' . rawurlencode($reference), null, 10);

	$shipment = is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200
		? null
		: oko_shipment_from_body(wp_remote_retrieve_body($response));

	// A no is remembered as well, and that is the half of this that the
	// customer feels. Only a good answer used to be kept, so an Økoskabet that
	// was down — or a number that is nobody's shipment, which is most of what
	// gets typed into that field — cost a fresh ten-second wait on every
	// recalculation, and the checkout recalculates as the customer types. Half
	// a minute is short enough that an order being created right now is found
	// while they are still filling in the form.
	set_transient(
		$key,
		$shipment ?? array(OKO_NO_ANSWER => true),
		$shipment === null ? 30 : MINUTE_IN_SECONDS
	);

	return $shipment;
}

/** Marks a remembered "we asked and got nothing", as against a shipment. */
const OKO_NO_ANSWER = 'oko_no_answer';

/**
 * The second address line for the delivery, wherever the customer wrote it.
 *
 * The shipping address first, as it should be. But a shop whose checkout has
 * no second shipping line never gets one: WooCommerce copies billing to
 * shipping only for the fields the shipping form has, so the floor sits on the
 * invoice address and the delivery address goes to the register without it —
 * which is what it refuses a block of flats for.
 *
 * The fallback only applies when the two addresses are the same street, which
 * is the case it was written for: one address, written once, and only the
 * invoice form had somewhere to put the floor. An order sent somewhere else
 * is a different building, and its own second line is the only one that
 * belongs to it — carrying "2. sal" from the invoice address to a workplace
 * across town is a flat that does not exist there, which the register
 * refuses, or worse one that does.
 *
 * @param \WC_Order $order The order.
 * @return string
 */
function oko_second_address_line($order): string
{
	if (! $order instanceof \WC_Order) {
		return '';
	}

	$shipping = trim((string) $order->get_shipping_address_2());
	if ($shipping !== '') {
		return $shipping;
	}

	// Not once Økoskabet has given us the delivery. The fallback exists for a
	// checkout with no second shipping line, where the flat is only in the
	// billing address — and it must not outlive the moment somebody corrected
	// the delivery by hand. A backoffice that takes the flat off a delivery
	// clears the shipping line, and without this the customer's old billing
	// flat walked straight back into the next payload: written into the
	// record of what Økoskabet accepted, so the two ends disagreed about the
	// address with nothing to say so, and the shop's next address edit sent
	// the flat back over the correction.
	if (! empty($order->get_meta(OKO_ADDRESS_FROM_OKOSKABET_META, true))) {
		return '';
	}

	// Nor for an order 1.5.2 sent, which never had it: see
	// OKO_SENT_BY_1_5_2_META.
	if (! empty($order->get_meta(OKO_SENT_BY_1_5_2_META, true))) {
		return '';
	}

	$same_address = oko_same_line($order->get_shipping_address_1(), $order->get_billing_address_1())
		&& oko_same_line($order->get_shipping_postcode(), $order->get_billing_postcode());

	return $same_address ? trim((string) $order->get_billing_address_2()) : '';
}

/**
 * Meta marking a delivery address that came from Økoskabet's own backoffice.
 *
 * What it switches off is the billing fallback in `oko_second_address_line()`,
 * and nothing else: an address somebody corrected by hand is the address, and
 * what the customer typed in another field months ago is not a second opinion.
 */
const OKO_ADDRESS_FROM_OKOSKABET_META = '_okoskabet_address_written_back';

/**
 * Meta marking an order 1.5.2 sent, recognised by its 1.5.2 fingerprint.
 *
 * Such an order keeps 1.5.2's second address line: the shipping line as
 * typed, never the billing flat. 1.5.2 did not send the billing flat, so
 * Økoskabet's copy has none, and anything the packing room corrected while
 * the shop ran 1.5.2 stands on that copy without being written back.
 * Bringing the flat in afterwards made the address differ from both: the
 * first webhook about the delivery read Økoskabet's copy as a move, wrote a
 * note saying so and switched the fallback off, and the second save of the
 * order sent the whole address, over the correction (#80's review). The flat
 * is no worse off than it was under 1.5.2, and orders placed since get it.
 */
const OKO_SENT_BY_1_5_2_META = '_okoskabet_sent_by_1_5_2';

/**
 * Meta marking a place to collect from that Økoskabet's backoffice decided.
 *
 * Set by the write-back whenever it writes the place, or takes it away. With
 * it the place alone says whether the order is a collection, whatever the
 * shipping line says; without it only a store-pickup shipping line makes one,
 * because a place on any other order can be a leftover. See
 * oko_collection_place().
 */
const OKO_PICKUP_FROM_OKOSKABET_META = '_okoskabet_pickup_written_back';

/** Two written forms of one line, give or take case and spacing. */
function oko_same_line(string $one, string $other): bool
{
	$tidy = static function (string $value): string {
		return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
	};

	return $tidy($one) === $tidy($other);
}

/**
 * The Økoskabet account an order belongs to.
 *
 * The same resolution the order itself was sent with — the stamp written at
 * checkout first, the order's own items after it — so an addition is weighed
 * against the account that holds the delivery, not whichever one happens to be
 * the default.
 *
 * @param \WC_Order $order The order.
 * @return array<string,mixed> The merchant record, or an empty array.
 */
function oko_merchant_for_order($order): array
{
	$merchant = array();

	if ($order instanceof \WC_Order
		&& class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchant_Router')) {
		$resolved = \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::resolve_for_order($order);
		$merchant = (array) ($resolved['merchant'] ?? array());
	}

	// The router answers nothing for an order whose items name no account, and
	// the default one is what such an order is sent under. Answering "no
	// account" instead would refuse every addition for a shop with one account
	// and no routing rules — which is most of them.
	//
	// The record is handed back even without a key, so a caller that logs a
	// missing key can say whose. Callers test the key, not the array.
	//
	// Only "no account" falls back, never "an account without a key". An
	// order routed to a merchant whose key is not filled in yet is that
	// merchant's order; sending it under the default account's key booked it
	// in somebody else's packing room. 1.5.2 logged it and left it, and so
	// does this.
	return $merchant === array() ? o_get_merchant() : $merchant;
}

/**
 * Whether an order can still have goods added to it.
 *
 * Two bounds, and Økoskabet publishes both. The cutoff — `order_cutoff` in
 * `/configuration`, the same one the date endpoints apply — says when ordering
 * for that delivery day closes; past it the merchant is already picking. And
 * the shipment's own status says whether it has left them, whatever the clock
 * says, because a day that has not closed is no help once the box is gone.
 *
 * Unreachable means no. The alternative is promising a free ride on a delivery
 * we cannot see, and the customer pays for that with goods that never arrive.
 *
 * @param \WC_Order $order         The earlier order.
 * @param string    $delivery_date Its delivery date, `Y-m-d`.
 * @return bool
 */
function oko_order_can_still_take_more($order, string $delivery_date): bool
{
	if (! $order instanceof \WC_Order) {
		return false;
	}

	$merchant = oko_merchant_for_order($order);
	if (empty($merchant['api_key'])) {
		return false;
	}

	if (! oko_before_cutoff_for($delivery_date, $merchant)) {
		return false;
	}

	return oko_shipment_still_with_merchant($order, $merchant);
}

/**
 * Økoskabet's logistics cutoff, the evening before delivery: `config
 * :okoskabet, :logistics, cutoff_at:` in delivery_backend. It is not published
 * to the plugin, so it is repeated here — change both together.
 */
const OKO_LOGISTICS_CUTOFF = '21:30:00';

/**
 * Whether the shop is still taking orders for a delivery day.
 *
 * `order_cutoff` is a time of day and a number of days before delivery. The
 * last moment is that many days before the delivery date, at that time, in
 * Økoskabet's own time zone — which is the one that moves with daylight
 * saving, so the hour is read there rather than converted.
 *
 * A configuration we could not fetch answers no: this decides whether to
 * promise free delivery, and a guess in the customer's favour is a guess the
 * packing room pays for. No number of days is none, as Økoskabet reads it.
 *
 * A shop with no cutoff time has no cutoff of its own, and is then held to the
 * one Økoskabet holds every booking to: the logistics cutoff, at
 * `OKO_LOGISTICS_CUTOFF` the day before delivery
 * (`Okoskabet.DeliveryDate.after_cutoff?/3`). Later than that, a new order for
 * the day is refused, and goods added to one already booked would reach a
 * parcel packed and handed over the evening before.
 *
 * @param string                  $delivery_date `Y-m-d`.
 * @param array<string,mixed>     $merchant      The merchant record.
 * @param \DateTimeImmutable|null $now           The moment asked about; now.
 * @return bool
 */
function oko_before_cutoff_for(string $delivery_date, array $merchant, ?\DateTimeImmutable $now = null): bool
{
	$configuration = oko_merchant_configuration((string) ($merchant['id'] ?? ''));
	$cutoff        = (array) ($configuration['order_cutoff'] ?? array());

	// No `order_cutoff` at all is a configuration we could not fetch.
	if (! array_key_exists('order_cutoff', $configuration)) {
		return false;
	}

	$time = (string) ($cutoff['time'] ?? '');
	$days = (int) ($cutoff['days_before_delivery'] ?? 0);
	if ($time === '') {
		$time = OKO_LOGISTICS_CUTOFF;
		$days = 1;
	}

	try {
		$zone = new \DateTimeZone((string) ($cutoff['time_zone'] ?? 'Europe/Copenhagen'));
		$last = new \DateTimeImmutable($delivery_date . ' ' . $time, $zone);
		$last = $last->modify('-' . $days . ' days');

		return ($now ?? new \DateTimeImmutable('now', $zone)) < $last;
	} catch (\Exception $e) {
		return false;
	}
}

/**
 * Whether the merchant still has the shipment, as Økoskabet sees it.
 *
 * Anything past `registered` has been handled — scanned, packed, on its way —
 * and nothing can be added to it any more. A shipment Økoskabet does not know
 * is one that never arrived, and that cannot be ridden along with either.
 *
 * @param \WC_Order           $order    The earlier order.
 * @param array<string,mixed> $merchant The merchant record.
 * @return bool
 */
function oko_shipment_still_with_merchant($order, array $merchant): bool
{
	if (! $order instanceof \WC_Order || empty($merchant['api_key'])) {
		return false;
	}

	$shipment = oko_shipment_at_okoskabet(oko_shipment_reference($order), $merchant);

	return $shipment !== null && (string) ($shipment['status'] ?? '') === 'registered';
}

/**
 * Whether the customer is placing a pre-order, as the checkout form says:
 * the submitted form when the order is placed, the recalculation's copy of
 * it before that.
 */
function oko_is_pre_order_checkout(): bool
{
	return oko_posted_checkout_field('billing_okoskabet_pre_order') === '1';
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

	$product_ids = oko_cart_product_ids();

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

	$product_ids = oko_cart_product_ids();

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

	$product_ids = oko_cart_product_ids();
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
	return sanitize_text_field(oko_posted_checkout_field('billing_okoskabet_delivery_date'));
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
	// Which order an addition belongs to, in either shop's field. Left to
	// come back, the next ordinary order opens with the last one's number
	// still in it — and rides along with a delivery the customer never
	// meant to add to, for no freight. Seen on Gaardmester's staging: a
	// fresh checkout opened with 87893 already there.
	//
	// Only while the shop takes additions. Otherwise a number in its own
	// field rides along with nothing, and what WooCommerce remembers there
	// is the shop's own affair, as it was before 1.5.3.
	if (oko_shop_takes_additions()) {
		$fresh_every_time = array_merge($fresh_every_time, OKO_ADDON_FIELDS);
	}
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

/**
 * The order this one is an addition to, as the customer wrote it.
 *
 * A shop's own field first — gaardmester.dk has `billing_order_id`, labelled
 * "Tillægsordre: Udfyld dit tidligere ordre-id her" — and our own field for the
 * shops without one. Whatever the customer typed, unchanged: it is their word
 * for which order this belongs to, and it is shown to a person, never resolved
 * into anything on its own.
 *
 * @param \WC_Order $order Order to read.
 * @return string The earlier order's number, or an empty string.
 */
function oko_order_addon_of($order): string
{
	if (! $order instanceof \WC_Order) {
		return '';
	}

	// The same two fields the checkout asks in, as WooCommerce stores them.
	foreach (OKO_ADDON_FIELDS as $field) {
		$key = '_' . $field;
		$value = trim((string) $order->get_meta($key, true));
		if ($value !== '') {
			return $value;
		}
	}

	return '';
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

	// Which order this one is an addition to. Gaardmester asks for it already,
	// in a field of their own; this is for the shops that do not, so the
	// question is asked the same way everywhere — in the shops that have
	// turned additions on. Everywhere else neither field is touched.
	$takes_additions = oko_shop_takes_additions();
	if ($takes_additions && !isset($fields['billing']['billing_order_id'])) {
		$fields['billing']['billing_okoskabet_addon_order'] = array(
			'label'       => __('Adding to an earlier order? Write its order number here', O_TEXTDOMAIN),
			'placeholder' => '',
			'required'    => false,
			'class'       => array('okoskabet-addon-order', 'form-row-wide'),
			'clear'       => true,
		);
	}

	// WooCommerce's own second address line, put back for a shop that has
	// taken it away and asked us for it. The register refuses a block of flats
	// given without a floor ("Etage findes ikke på adresse på husnummer"), and
	// the order lands among the unprocessable ones.
	//
	// Off unless the shop turns it on, because there is a second way the floor
	// arrives and it is already working: a customer with nowhere else to put
	// it writes it on the street line — "Eksempelvej 12, 1. tv" — and
	// Økoskabet's address client lifts the unit out of that line itself.
	// gaardmester.dk delivers to blocks of flats that way today, with no
	// second address line in their checkout at all. Handing such a shop one
	// repairs nothing and adds a way to go wrong: a customer who writes the
	// floor in both places sends "Eksempelvej 12, 1. tv, 1. tv", and the
	// register refuses that outright.
	//
	// The shop's own field rather than one of ours, so there is one second
	// address line and not two ideas of one: `get_shipping_address_2()` stays
	// the only place it is read from, WooCommerce copies it to the shipping
	// address by itself, and it is not among the fields our own checkout
	// script leaves behind when the customer switches order type.
	if (oko_shop_asks_for_the_floor()) {
		$second_line = array(
			'label'        => __('Flat, floor and door', O_TEXTDOMAIN),
			'placeholder'  => __('e.g. 3. th', O_TEXTDOMAIN),
			'required'     => false,
			'class'        => array('form-row-wide'),
			'autocomplete' => 'address-line2',
			// Straight after the street, where WooCommerce itself puts it: the
			// billing form is ordered by priority and the address is 50.
			'priority'     => 55,
		);

		// Both forms, not only the billing one. WooCommerce copies billing to
		// shipping for the fields the shipping form has, so a shop that removed it
		// from both — gaardmester.dk does — would carry the floor on the invoice
		// and nowhere else, and the delivery address would reach the register
		// without it again. Measured on their staging: billing "3 th", shipping
		// empty, and the order refused.
		foreach (array('billing', 'shipping') as $form) {
			if (isset($fields[$form]) && ! isset($fields[$form][$form . '_address_2'])) {
				$fields[$form][$form . '_address_2'] = $second_line;
			}
		}
	}

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


	// Filling this in has to recalculate the freight, whosever field it is.
	// WooCommerce listens on `.update_totals_on_change input.input-text`, so
	// the class belongs on the row around the field — measured in its own
	// checkout.min.js. Without it a customer typed the order number, saw the
	// full freight, and was charged the free one at submit. Nothing to
	// recalculate for in a shop that does not take additions.
	foreach ($takes_additions ? OKO_ADDON_FIELDS : array() as $field) {
		if (! isset($fields['billing'][$field])) {
			continue;
		}

		$classes = (array) ($fields['billing'][$field]['class'] ?? array());
		if (! in_array('update_totals_on_change', $classes, true)) {
			$classes[] = 'update_totals_on_change';
		}
		$fields['billing'][$field]['class'] = $classes;
	}

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
	$delivery_location_source = $order->get_meta('_billing_okoskabet_delivery_location_source', true);

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
		echo esc_html__('Økoskabet Done', O_TEXTDOMAIN) . ': ' . esc_html($order_done) . "\n";
	}
	if (!empty($shed_id)) {
		echo esc_html__('Økoskabet SHED ID', O_TEXTDOMAIN) . ': ' . esc_html($shed_id) . "\n";
	}
	if (!empty($delivery_date)) {
		echo esc_html__('Økoskabet Delivery Date', O_TEXTDOMAIN) . ': ' . esc_html($delivery_date) . "\n";
	}
	if (!empty($pickup_location_id)) {
		echo esc_html__('Økoskabet pickup location', O_TEXTDOMAIN) . ': ' . esc_html($pickup_location_id) . "\n";
	}
	if (!empty($delivery_location)) {
		echo esc_html__('Økoskabet Delivery location', O_TEXTDOMAIN) . ': ' . esc_html($delivery_location) . "\n";
	}
	if (!empty($delivery_note)) {
		echo esc_html__('Note to driver', O_TEXTDOMAIN) . ': ' . esc_html($delivery_note) . "\n";
	}
	if (!empty($delivery_location_source)) {
		// Tells us per-order which checkout path produced the location:
		// dropdown / andet_text / default_fallback. Useful for triaging
		// the rate at which the JS UI fails to render in the wild.
		echo esc_html__('Location source', O_TEXTDOMAIN) . ': ' . esc_html($delivery_location_source);
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
 * @param \WC_Order           $order
 * @param array<string,mixed>|null $merchant The account the order is sent
 *                                           under; resolved from the order
 *                                           when the caller has none.
 * @return array<int,array{product_id:int|null,variant_id:int|null,name:string,variant_title:string|null,sku:string,quantity:int,tags:string[],properties:array,image:string}>
 */
function oko_order_line_items(\WC_Order $order, ?array $merchant = null): array
{
	$lines = array();

	// Handed down rather than resolved again: every caller that builds a
	// payload already holds the merchant, and `Merchant_Router` walks every
	// item and resolves the shipping zone for an order the checkout did not
	// stamp. An order with three additions paid for that four times.
	$merchant = $merchant ?? oko_merchant_for_order($order);

	// Only in kroner. Økoskabet keeps the figure as an amount and nothing
	// else — there is no currency beside it — so a shop selling in euros
	// would have its offers reckoned on numbers read as kroner. Our shops are
	// Danish; the day one is not, this is the line that has to be thought
	// about rather than the one that quietly went wrong.
	$with_totals = oko_shop_sends_line_totals($merchant)
		&& $order->get_currency() === 'DKK';
	$with_vat    = wc_prices_include_tax();

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
		$tags          = array();
		$properties    = array();
		$image         = '';

		if ($item instanceof \WC_Order_Item_Product) {
			$product_id = (int) $item->get_product_id();
			$variant_id = (int) $item->get_variation_id();

			// The product can be gone — deleted from the catalogue after the
			// order was placed. The line survives it; the SKU does not.
			$product = $item->get_product();
			if ($product instanceof \WC_Product) {
				$sku = (string) $product->get_sku();

				// What the packing room needs to know about the goods, sent
				// with the order instead of fetched back afterwards.
				//
				// Økoskabet used to ask the shop for this once the packing
				// room opened the day. That call goes the other way — in to
				// the shop — and it can be stopped before WordPress ever sees
				// it, as it is at Gaardmester. Then there is no producer, no
				// country of origin and no zone to sort the dispatch notes by,
				// and nothing anywhere says why. Sent on the line it cannot be
				// stopped: the order is already on its way out.
				//
				// Same keys and same shapes as the lookup answers with, so
				// nothing has to be translated at the other end.
				$tags       = oko_product_tags($product);
				$properties = oko_product_properties($product);
				$image      = oko_product_image_url($product);
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

		$line = array(
			'product_id'    => $product_id > 0 ? $product_id : null,
			'variant_id'    => $variant_id > 0 ? $variant_id : null,
			'name'          => $name,
			'variant_title' => $variant_title,
			'sku'           => $sku,
			'quantity'      => $quantity,
			'tags'          => $tags,
			'properties'    => $properties,
			'image'         => $image,
		);

		// Left out rather than sent empty when the shop is not asked for it:
		// an update that carries no `total` keeps the one the line already
		// has, so an absent key is the only way to say nothing about money.
		if ($with_totals) {
			$line['total'] = oko_line_total($order, $item, $with_vat);
		}

		$lines[] = $line;
	}

	return $lines;
}


/**
 * Meta holding the `shipment_reference` an order was booked with Økoskabet under.
 *
 * Økoskabet knows an order by that reference and by nothing else, so every
 * later conversation about it — a webhook coming in, an edit or a cancellation
 * going out — has to use exactly the same string. It is the order *number*, and
 * with a numbering plugin that is not the order's id; the number can even
 * change after booking. Keeping what was sent is the only way to be sure.
 */
const OKO_SHIPMENT_REFERENCE_META = '_okoskabet_shipment_reference';

/**
 * Meta holding the id of the order the reference above was written for.
 *
 * Order meta gets copied: a duplicate-order plugin copies all of it, and a
 * subscription renewal copies its parent's. A copied reference names the
 * *other* order's shipment, and trusting it would cancel or overwrite that
 * shipment from the copy. The id comes along in the copy unchanged, so a
 * reference whose id is not the order's own is recognised as not its own.
 */
const OKO_SHIPMENT_REFERENCE_FOR_META = '_okoskabet_shipment_reference_for';

/**
 * The reference this order itself was booked under, or '' if it has none.
 *
 * A reference copied over from another order is not this order's, and reads
 * as none.
 */
function oko_booked_reference(\WC_Order $order): string
{
	$for       = $order->get_meta(OKO_SHIPMENT_REFERENCE_FOR_META, true);
	$reference = $order->get_meta(OKO_SHIPMENT_REFERENCE_META, true);

	return is_numeric($for) && (int) $for === $order->get_id() && is_string($reference) ? $reference : '';
}

/** Keep the reference this order was booked under, as its own. */
function oko_keep_booked_reference(\WC_Order $order, string $reference): void
{
	$order->update_meta_data(OKO_SHIPMENT_REFERENCE_META, $reference);
	$order->update_meta_data(OKO_SHIPMENT_REFERENCE_FOR_META, (string) $order->get_id());
}

/**
 * The reference Økoskabet knows this order by.
 *
 * What it was booked under, or — for an order booked before that was kept, or
 * not booked yet — its number, which is what it was or will be booked under.
 */
function oko_shipment_reference(\WC_Order $order): string
{
	$booked = oko_booked_reference($order);

	return $booked !== '' ? $booked : (string) $order->get_order_number();
}

/**
 * The order booked with Økoskabet under this reference, and never another.
 *
 * This used to be `wc_get_order($reference)`, which reads the reference as an
 * order *id*. With a numbering plugin the number is not the id, so the order
 * was not found — or, where some other order had that id, that order was the
 * one completed and charged.
 *
 * Every candidate is checked here in PHP, whatever found it: the posts data
 * store quietly ignores some of what `wc_get_orders()` is asked to filter on,
 * and a lookup that trusted the query would hand back the wrong order without
 * a word.
 *
 * @param string $reference
 * @param bool   $remember Whether an order found by its number is given the
 *                         reference to keep. Only for a caller that is
 *                         Økoskabet: the checkout asks on behalf of anyone.
 * @return \WC_Order|\WP_Error 404 when no order has the reference, 409 when
 *                             more than one could and there is no telling which.
 */
function oko_order_for_shipment_reference(string $reference, bool $remember = true)
{
	// Booked since the reference was kept: the order says so itself.
	$found = $reference === '' ? array() : array_filter(
		oko_orders_by_ids(oko_order_ids_with_meta(OKO_SHIPMENT_REFERENCE_META, $reference)),
		static fn(\WC_Order $order): bool => oko_booked_reference($order) === $reference
	);

	// Booked before that: the reference was its number then, so it is found
	// by number now — and only an order this plugin booked. A number is not
	// forever: renumber the shop while a parcel is on its way, and the old
	// number can turn up on a new order Økoskabet never heard of, which would
	// then be completed and charged for the parcel.
	if (empty($found) && $reference !== '') {
		$found = array_filter(
			oko_orders_by_ids(oko_order_ids_numbered($reference)),
			static fn(\WC_Order $order): bool => (string) $order->get_order_number() === $reference
				// Booked under some other reference: Økoskabet knows it by that.
				&& oko_booked_reference($order) === ''
				&& oko_booked_by_plugin($order)
				// An addition is marked as sent, but never under a number of
				// its own: its goods went onto another order's shipment. Found
				// here, a renumbered shop could have one completed and charged
				// for some older order's parcel.
				&& (string) $order->get_meta(OKO_ADDON_PARENT_ID_META, true) === ''
		);
	}

	if (count($found) > 1) {
		return new \WP_Error('order_ambiguous', 'More than one order has this shipment reference', array('status' => 409));
	}
	if (empty($found)) {
		return new \WP_Error('order_not_found', 'Order not found', array('status' => 404));
	}

	// Found by number: given the reference to keep, so the next webhook finds
	// it directly, even if the number has changed by then.
	$order = reset($found);
	if ($remember && oko_booked_reference($order) === '') {
		oko_keep_booked_reference($order, $reference);
		$order->save_meta_data();
	}

	return $order;
}

/**
 * Whether this plugin booked the order with Økoskabet.
 *
 * `billing_okoskabet_done` is written by the one booking path there has ever
 * been, and only once Økoskabet has accepted the shipment. Versions before
 * March 2026 wrote it with `update_post_meta()`, which on a shop using
 * WooCommerce's own order tables lands beside the order rather than on it,
 * so it is read from there as well.
 */
function oko_booked_by_plugin(\WC_Order $order): bool
{
	return ! empty($order->get_meta('billing_okoskabet_done', true))
		|| ! empty(get_post_meta($order->get_id(), 'billing_okoskabet_done', true));
}

/**
 * Ids of orders that may carry this number. Candidates only: the caller
 * checks each one's number itself.
 *
 * @param string $number
 * @return int[]
 */
function oko_order_ids_numbered(string $number): array
{
	$ids = array();

	// No numbering plugin: the number is the id. Asked directly rather than
	// left to the hook below, because a numbering plugin that answers the
	// hook may answer it with its own lookup only.
	if (preg_match('/^\d+$/', $number)) {
		$ids[] = (int) $number;
	}

	// The hook WooCommerce's own order tracking asks numbering plugins to
	// answer, turning a number a customer typed into an order id.
	$ids[] = (int) apply_filters('woocommerce_shortcode_order_tracking_order_id', $number);

	/**
	 * Meta keys a numbering plugin keeps an order's number under.
	 *
	 * Searched for orders booked before the reference was kept on the order.
	 * Some plugins store only the counter and pad or prefix it on display, so
	 * the number's bare digits are searched as well. A key that matches the
	 * wrong order does no harm: every candidate's number is checked.
	 *
	 * @param string[] $keys
	 */
	$keys = (array) apply_filters('oko_order_number_meta_keys', array(
		'_order_number',                    // Sequential Order Numbers (SkyVerge, WebToffee)
		'_order_number_formatted',          // Sequential Order Numbers Pro
		'_alg_wc_full_custom_order_number', // Custom Order Numbers for WooCommerce
		'_alg_wc_custom_order_number',
		'_wcj_order_number',                // Booster for WooCommerce
	));

	$values = array($number);
	if (preg_match('/(\d+)\D*$/', $number, $digits)) {
		$values[] = ltrim($digits[1], '0');
	}
	$values = array_unique(array_filter($values, static fn(string $value): bool => $value !== ''));

	foreach ($keys as $key) {
		foreach ($values as $value) {
			$ids = array_merge($ids, oko_order_ids_with_meta((string) $key, $value));
		}
	}

	return $ids;
}

/**
 * Ids of orders carrying a meta value, from either order storage.
 *
 * `meta_key`/`meta_value` rather than `meta_query`: the posts data store drops
 * a `meta_query` on the floor and returns every order. The caller checks each
 * result anyway. Trashed orders are included, as `wc_get_order()` always did.
 *
 * @param string $key
 * @param string $value
 * @return int[]
 */
function oko_order_ids_with_meta(string $key, string $value): array
{
	static $statuses = null;
	if ($statuses === null) {
		$statuses = array_merge(array_keys(wc_get_order_statuses()), array('trash'));
	}

	// A handful is plenty, oldest first. Not every hit counts — the caller
	// drops copies, whose meta names another order — and a copy is always
	// newer than the order it was copied from. Newest first, five copies
	// made while the parcel was on its way filled the handful, and the order
	// they were copied from was answered 404.
	return array_map('intval', (array) wc_get_orders(array(
		'type'       => 'shop_order',
		'status'     => $statuses,
		'limit'      => 5,
		'orderby'    => 'ID',
		'order'      => 'ASC',
		'return'     => 'ids',
		'meta_key'   => $key,
		'meta_value' => $value,
	)));
}

/**
 * Orders by id, leaving out anything that is not a plain order.
 *
 * `wc_get_order()` answers for every order type: a refund, or a subscription,
 * which is a WC_Order too, and whose number is its id. A shipment is booked
 * for neither.
 *
 * @param int[] $ids
 * @return \WC_Order[]
 */
function oko_orders_by_ids(array $ids): array
{
	$orders = array();
	foreach (array_unique($ids) as $id) {
		$order = $id > 0 ? wc_get_order($id) : false;
		if ($order instanceof \WC_Order && $order->get_type() === 'shop_order') {
			$orders[] = $order;
		}
	}

	return $orders;
}

/**
 * Whether Økoskabet wants the line totals on this shop's orders.
 *
 * It says so in its own configuration, and only while the shop uses offers in
 * the Track&Trace app: a share of what an offer sold is reckoned on what the
 * lines came to, and a line price is kept for nothing else (Marc, 19 October
 * 2026 — "priserne må kun bruges, når butikken har sagt ja til
 * tilbudsdelen"). A shop that has not said yes is not asked for them, and an
 * Økoskabet that has not heard of the field answers nothing, which reads the
 * same way: the shop sends no prices until the day it is asked to.
 *
 * No answer at all is not a no. The configuration is an empty array when it
 * could not be read (see oko_merchant_configuration()), and that is kept for
 * half a minute; read as "no prices", every order saved in that half minute
 * sent its goods again without them, and the first save after Økoskabet came
 * back sent them again with. So the account's last real answer stands until
 * there is a new one, and an account that has never answered sends none.
 *
 * @param array<string,mixed> $merchant
 */
function oko_shop_sends_line_totals(array $merchant): bool
{
	$merchant_id   = (string) ($merchant['id'] ?? '');
	$configuration = oko_merchant_configuration($merchant_id);
	$last_said     = (array) get_option(OKO_LINE_TOTALS_SAID_OPTION, array());

	if ($configuration === array()) {
		return ! empty($last_said[$merchant_id]);
	}

	$sends = ! empty($configuration['send_line_totals']);

	// Written only when it changes, which is when a shop starts or stops
	// using offers — not on every order.
	if (! empty($last_said[$merchant_id]) !== $sends) {
		$last_said[$merchant_id] = $sends;
		update_option(OKO_LINE_TOTALS_SAID_OPTION, $last_said, false);
	}

	return $sends;
}

/** What each account's Økoskabet last said about line totals, for when it says nothing. */
const OKO_LINE_TOTALS_SAID_OPTION = 'okoskabet_line_totals_last_said';

/**
 * What a line came to, as this shop reports money.
 *
 * Økoskabet takes the line in the shop's own money after discounts, with VAT
 * in it only where the shop's own prices have VAT in them — so the figure is
 * read the way the shop would read it off the order, not converted into some
 * convention of ours.
 *
 * Minus what has been refunded, for the reason the quantity is: WooCommerce
 * only lets a line be edited while an order is pending or on hold, so on an
 * order already sent, refunding is how a shop takes goods off it. The refund
 * lines carry negative amounts, which is why they are subtracted by adding.
 *
 * Walked by hand rather than through `WC_Order::get_total_refunded_for_item()`,
 * which the quantity above uses its sibling of. That accessor answers the
 * amount without VAT, and WooCommerce has no sibling that answers the VAT for
 * an item alone — `get_tax_refunded_for_item()` wants a tax-rate id. Using the
 * accessor for one half and a walk for the other would read as two refund
 * conventions in one sum; one walk answers both.
 *
 * @param \WC_Order      $order    The order the line belongs to.
 * @param \WC_Order_Item  $item     The line itself. Untyped in the
 *                                  signature because the class is
 *                                  WooCommerce's and not loaded by the
 *                                  analyser.
 * @param bool            $with_vat Whether this shop's prices have VAT in
 *                                  them.
 * @return float The line in kroner, after discounts and refunds.
 */
function oko_line_total(\WC_Order $order, $item, bool $with_vat): float
{
	$total = (float) $item->get_total();
	if ($with_vat) {
		$total += (float) $item->get_total_tax();
	}

	$item_id = (int) $item->get_id();

	foreach ($order->get_refunds() as $refund) {
		foreach ($refund->get_items('line_item') as $refunded) {
			if ((int) $refunded->get_meta('_refunded_item_id') !== $item_id) {
				continue;
			}

			$total += (float) $refunded->get_total();
			if ($with_vat) {
				$total += (float) $refunded->get_total_tax();
			}
		}
	}

	return round($total, 2);
}

/**
 * The name a delivery is addressed to, as WooCommerce holds it.
 *
 * One definition, because the write-back asks "do Økoskabet and the shop
 * actually disagree about the name" by comparing against this exact string.
 * Two spellings of it that drift apart — a different separator, one of them
 * untrimmed — make every webhook rename every order.
 *
 * Trimmed for the same reason: a recipient of one word, which the write-back
 * can produce by splitting "Pernille" into a first name and nothing, would
 * otherwise be sent with a trailing space and stored that way.
 */
function oko_recipient_name(\WC_Order $order): string
{
	return trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name());
}

/**
 * Whether this shop wants us to ask for the flat, floor and door.
 *
 * Off unless the shop says otherwise. A shop whose customers already write
 * the floor on the street line has nothing to gain from a second field and
 * something to lose — see where the field is drawn.
 *
 * @return bool
 */
function oko_shop_asks_for_the_floor(): bool
{
	return ! empty(o_get_settings()['_ask_for_floor']);
}

/**
 * The shipping methods this plugin puts in a checkout, and the kind of
 * delivery each one is.
 *
 * One list. It was written out three times — here, in the rate filter, and
 * again as labels on the packaging fee — and a fourth copy is how a method
 * added later gets left out of one of them.
 */
const OKO_SHIPPING_METHOD_KINDS = array(
	'hey_okoskabet_shipping_shed'         => 'shed',
	'hey_okoskabet_shipping_home'         => 'home_delivery',
	'hey_okoskabet_shipping_store_pickup' => 'store_pickup',
);

/**
 * Whether Økoskabet is carrying this order.
 *
 * The shipping method the customer chose, and nothing else. A shop may sell
 * with its own couriers beside ours — Gaardmester runs Shipmondo for part of
 * what they send — and those orders are not ours to forward, whatever else
 * they carry.
 *
 * @param \WC_Order $order
 * @return bool
 */
function oko_order_is_ours(\WC_Order $order): bool
{
	foreach ($order->get_shipping_methods() as $shipping_method) {
		if (isset(OKO_SHIPPING_METHOD_KINDS[$shipping_method->get_method_id()])) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the customer chose a shipping method the shop uses for add-ons.
 *
 * Gaardmester asks customers who add to an earlier order to pick a shipping
 * method of its own — "Tillæg til en igangværende ordre" — and write the
 * order's number in the checkout. That method is not ours, so without this
 * the goods were never put on the delivery and the shop did it by hand. The
 * shop names such methods in the settings; a method it has not named is a
 * courier of its own, and is left alone.
 *
 * @param \WC_Order $order
 */
function oko_order_chose_addon_shipping(\WC_Order $order): bool
{
	foreach ($order->get_shipping_methods() as $shipping_method) {
		if (oko_is_addon_shipping_method($shipping_method->get_method_id(), (int) $shipping_method->get_instance_id())) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the rate chosen in the checkout is one of the shop's add-on methods.
 *
 * The same question as `oko_order_chose_addon_shipping()`, asked of the cart
 * before there is an order, so the checkout can say the right thing.
 */
function oko_checkout_chose_addon_shipping(): bool
{
	if (! function_exists('WC') || ! WC()->session || ! WC()->shipping()) {
		return false;
	}

	$packages = WC()->shipping()->get_packages();
	foreach ((array) WC()->session->get('chosen_shipping_methods', array()) as $package_key => $rate_id) {
		$rate = $packages[$package_key]['rates'][(string) $rate_id] ?? null;
		if ($rate instanceof \WC_Shipping_Rate && oko_is_addon_shipping_method($rate->get_method_id(), (int) $rate->get_instance_id())) {
			return true;
		}
	}

	return false;
}

/**
 * Whether the shop has ticked this shipping method for add-ons.
 *
 * Keyed "method_id:instance_id", as `oko_all_shipping_method_choices()`
 * offers it on the settings page. Nothing ticked — never saved, or saved
 * with no ticks — matches nothing.
 */
function oko_is_addon_shipping_method(string $method_id, int $instance_id): bool
{
	$addon_methods = (array) (o_get_settings()['_addon_shipping_methods'] ?? array());

	return in_array($method_id . ':' . $instance_id, $addon_methods, true);
}

/**
 * An order's goods, with the goods of every addition riding along with it.
 *
 * The same list `oko_write_shipment_contents()` sends, built here for the
 * paths that send the order on its own: the first POST, and every resend
 * after an edit in the shop.
 *
 * @param \WC_Order $order
 * @return array<int,array<string,mixed>>
 */
function oko_lines_with_additions(\WC_Order $order, ?array $merchant = null): array
{
	return oko_lines_for($order, oko_additions_to($order, null), $merchant);
}

/**
 * An order's goods followed by the goods of the additions given.
 *
 * The one place the list is put together, because it is sent from two: the
 * order on its own, and the write that puts an addition on it. Two builders
 * would drift, and a PUT replaces what a shipment contains — the drift would
 * be goods disappearing off a paid delivery.
 *
 * @param \WC_Order            $parent
 * @param array<int,\WC_Order> $additions Oldest first.
 * @param array<string,mixed>|null $merchant The parent's account.
 * @return array<int,array<string,mixed>>
 */
function oko_lines_for(\WC_Order $parent, array $additions, ?array $merchant = null): array
{
	// The parent's merchant for the additions too: an addition rides on the
	// parent's delivery, so it is sent under the account that delivery
	// belongs to.
	$merchant = $merchant ?? oko_merchant_for_order($parent);
	$lines    = oko_order_line_items($parent, $merchant);

	foreach ($additions as $addition) {
		$lines = array_merge($lines, oko_addition_lines($addition, $merchant));
	}

	return $lines;
}

/**
 * The note for the driver: where to leave it, and what the customer wrote.
 *
 * Always in English: the dropdown stores its English label, and the free text
 * follows it.
 *
 * @param \WC_Order $order
 */
function oko_logistics_note(\WC_Order $order): string
{
	// _billing_okoskabet_delivery_location stores the English label (from dropdown).
	$order_delivery_location = $order->get_meta('_billing_okoskabet_delivery_location', true);
	// _billing_okoskabet_delivery_note stores the free-text note from the customer.
	$order_delivery_note = $order->get_meta('_billing_okoskabet_delivery_note', true);

	$logistics_note_parts = array();
	if (!empty($order_delivery_location)) {
		$logistics_note_parts[] = $order_delivery_location; // Already stored in English.
	}
	if (!empty($order_delivery_note)) {
		$logistics_note_parts[] = $order_delivery_note;
	}

	return implode(' — ', $logistics_note_parts);
}

/**
 * Where an order is collected from, if it is a collection from the shop.
 *
 * Null when it is not one; an empty string for a collection with no place
 * yet, which goes to Økoskabet as one for a place to be given by hand. One
 * decision, asked by the payload and by the write-back: two copies of it
 * would let the write-back read as moved what was never sent.
 *
 * A collection Økoskabet itself moved the order to counts, even though the
 * shipping line the customer chose still says home delivery — for the same
 * reason the shed does. Without it the next edit in the shop sent the order
 * back as a home delivery and undid the move by hand.
 *
 * But only a place Økoskabet gave us counts that way, and the write-back
 * marks the ones it gave. Unlike the shed, a place to collect from is not a
 * decision just by being there. Only the browser emptied it when a customer
 * moved on from store pickup, so open orders from 1.5.2 can carry one beside
 * a locker or the door, and an admin who changes a collection's shipping line
 * to home delivery leaves one behind. 1.5.2 read it for a store pickup only;
 * read on every order, each of those became a collection on nobody's say-so,
 * and was sent as one on its next save.
 *
 * The mark cuts the other way as well. Once Økoskabet has moved a collection
 * to a shed or to the door, the write-back empties the place — and the
 * shipping line, still saying store pickup, kept sending a collection with
 * nowhere to collect from. That threw the move away, and the shop's next
 * address edit with it.
 *
 * @param \WC_Order $order
 * @return string|null
 */
function oko_collection_place(\WC_Order $order): ?string
{
	$place = trim((string) $order->get_meta('_billing_okoskabet_pickup_location_id', true));

	if (! empty($order->get_meta(OKO_PICKUP_FROM_OKOSKABET_META, true))) {
		return $place !== '' ? $place : null;
	}

	// The first of our shipping lines decides, as it does for the shed.
	foreach ($order->get_shipping_methods() as $shipping_method) {
		$method_id = $shipping_method->get_method_id();
		if ($method_id === 'hey_okoskabet_shipping_store_pickup') {
			return $place;
		}
		if ($method_id === 'hey_okoskabet_shipping_shed') {
			return null;
		}
	}

	return null;
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
 * @return array|null Null when the order is none of ours: none of our
 *                    shipping methods, whatever dates or sheds it carries.
 */
function oko_shipment_payload(\WC_Order $order, array $merchant): ?array
{
	$order_shed = $order->get_meta('_billing_okoskabet_shed_id', true);
	$order_delivery_date = $order->get_meta('_billing_okoskabet_delivery_date', true);
	$logistics_note = oko_logistics_note($order);

	// A missing date is not the same as "not our order".
	//
	// An order that carries one of our shipping methods is ours whatever else
	// is missing, and it goes to Økoskabet without a date — where it lands
	// among the unprocessable shipments and is given one by hand. It used to
	// be dropped here instead, and that drop was completely silent: no note on
	// the order, no line in the log, nothing at Økoskabet. Gaardmester's order
	// 88587 of 17 October 2026 went that way, and it was found because the
	// customer asked where the food was.
	//
	// Only the checkout writes the date, so an order that reaches this without
	// one was not born in the checkout — a renewal, an import, an order typed
	// in the admin. The checkout itself refuses to submit a home delivery with
	// no date unless the postcode has no delivery days at all.
	//
	// An order with no method of ours is a different matter: the shop's own
	// couriers carry those, and sending them would fill the packing room's
	// list with orders it must not touch.
	//
	// That holds with a date as well. The picker writes a shed and a date the
	// moment a customer picks Økoskabet, and only the browser clears them
	// again when the customer moves on to the shop's own courier. An order
	// that kept them was sent here as a home delivery, with no shed to send it
	// to: Slagter Bob, who has only sheds with us, had nine of those between
	// February and May 2026, each moved to a shed by hand.
	if (! oko_order_is_ours($order)) {
		return null;
	}
	$without_date = empty($order_delivery_date);

	$is_shed_delivery  = false;
	foreach ($order->get_shipping_methods() as $shipping_method) {
		$method_id = $shipping_method->get_method_id();
		if ($method_id === 'hey_okoskabet_shipping_shed') {
			$is_shed_delivery = true;
			break;
		}
		// A collection's line, which is not a shed's: see oko_collection_place().
		if ($method_id === 'hey_okoskabet_shipping_store_pickup') {
			break;
		}
	}

	// A shed Økoskabet itself moved the order into counts as well, even though
	// the shipping line the customer chose still says home delivery. That is
	// what the write-back records, and the checkout clears this meta whenever
	// home delivery is chosen — so a value here is a decision, never a
	// leftover. Without it the next edit in the shop sent the order back as a
	// home delivery and undid the move by hand.
	$is_shed_delivery = $is_shed_delivery || trim((string) $order_shed) !== '';

	if (!$is_shed_delivery) {
		$order_shed = '';
	}

	// A store pickup has no delivery target at all — the shop keeps the
	// goods until the customer collects them — so it carries neither a
	// shed reservation nor a delivery address. What it does carry is the
	// place to collect from, chosen at checkout from the merchant's own
	// pickup locations. Whether the order is one, and from where, is one
	// decision, asked by the write-back too; see oko_collection_place().
	$order_pickup_location_id = oko_collection_place($order);
	$is_store_pickup          = $order_pickup_location_id !== null;
	$order_pickup_location_id = (string) $order_pickup_location_id;


	// One shipment, three shapes. Everything every shipment carries is
	// built once; each type then adds only what makes it that type —
	// so a new key (like order_number) is added in one place, not three.
	$data = [
		'locale'             => get_locale(),
		'allow_invalid'      => true,
		// What Økoskabet knows this order by; see oko_shipment_reference().
		'shipment_reference' => oko_shipment_reference($order),
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
		// The order's own goods, and every addition riding along with them. A
		// PUT replaces the shipment's lines, so a resend built from this order
		// alone deleted the additions — the very mistake the sending path
		// below warns about, reached by another road. Avokadokontoret renamed
		// a product on 17 October 2026 and the rename resent three orders;
		// an addition on any of them would have lost its goods with no note.
		'line_items'         => oko_lines_with_additions($order, $merchant),
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
		//
		// And it is asked first, so that it beats a shed on the same order:
		// both can be set when the shop's own checkout and a correction made
		// here disagree, and the correction is the later word.
		$data['shipment_type']      = 'store_pickup';
		$data['pickup_location_id'] = $order_pickup_location_id !== '' ? (int) $order_pickup_location_id : null;
	} elseif (!empty($order_shed)) {
		$data['reservation'] = [
			'shed_id'           => $order_shed,
			'max_duration_days' => 1,
		];
	} else {
		$recipient_name = oko_recipient_name($order);
		$data['customer']['recipient_name'] = $recipient_name;
		// `location` used to be sent here as well, carrying the same text as
		// the note below. Økoskabet has no such field — `HomeDelivery` holds a
		// name and an address and nothing else — so the key was cast away
		// unread on every order. It is gone, and with it the resend that a
		// changed message used to set off: the payload no longer differs, so
		// nothing is sent that would arrive carrying nothing.
		$data['home_delivery'] = array_filter([
			'recipient_name' => $recipient_name,
			'address_1'      => $order->get_shipping_address_1(),
			'address_2'      => oko_second_address_line($order),
			'city'           => $order->get_shipping_city(),
			'postal_code'    => $order->get_shipping_postcode(),
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


add_action('woocommerce_checkout_order_processed', 'oko_link_addon_to_earlier_order', 20, 3);

/**
 * Write the two orders onto each other, so an addition is not a loose order.
 *
 * The customer tells us the earlier order's number; nothing else connects the
 * two, and a shop looking at either one has no way to see the other. A note on
 * both is what makes them hang together where the shop actually works, and it
 * survives everything: no new table, no meta to migrate, visible to any plugin
 * that reads order notes.
 *
 * The earlier order is looked up only to write on it. A number that matches
 * nothing — a typo, or an order from another shop — still leaves the note on
 * this order, because what the customer told us is worth keeping either way.
 *
 * Not in a shop that does not take additions: a number in its own
 * `billing_order_id` is then the shop's, and the notes would call an ordinary
 * order an addition.
 *
 * @param int       $order_id    The new order's id.
 * @param array     $posted_data Posted checkout data (unused).
 * @param \WC_Order $order       The new order.
 */
function oko_link_addon_to_earlier_order(int $order_id, array $posted_data, $order): void
{
	if (! $order instanceof \WC_Order || ! oko_shop_takes_additions()) {
		return;
	}

	$addon_of = oko_order_addon_of($order);
	if ($addon_of === '') {
		return;
	}

	/* translators: %s: the earlier order's number, as the customer wrote it. */
	$order->add_order_note(sprintf(__('Addition to order %s', O_TEXTDOMAIN), $addon_of));

	// The other order is only written on when this really is an addition to
	// it. The number the customer typed is their word; the id written at
	// checkout is the one that was found, matched against their email and
	// still open. Writing on the strength of the typed number alone put a note
	// on whichever order happened to have that id — somebody else's, and even
	// when the checkout had already refused the whole thing.
	$parent_id = (int) $order->get_meta(OKO_ADDON_PARENT_ID_META, true);
	if ($parent_id === 0) {
		return;
	}

	$earlier = wc_get_order($parent_id);
	if (! $earlier instanceof \WC_Order || $earlier->get_id() === $order->get_id()) {
		return;
	}

	/* translators: %s: the new order's number. */
	$earlier->add_order_note(sprintf(__('Order %s is an addition to this one', O_TEXTDOMAIN), $order->get_order_number()));
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

	// The merchant that should handle this order: the order meta first
	// (stamped at checkout_create_order time), falling back to a routing pass
	// over the order's items. This keeps existing orders working after an
	// upgrade — they resolve to the default merchant.
	//
	// An addition is written onto the earlier order's shipment, under that
	// order's account, so that is the account whose key decides. Asking the
	// addition's own basket stopped an addition that routed to an account
	// still without a key, although the delivery it rides on was fine.
	$parent_id    = (int) $order->get_meta(OKO_ADDON_PARENT_ID_META, true);
	$addon_parent = $parent_id !== 0 && $parent_id !== $order->get_id() ? wc_get_order($parent_id) : false;
	$merchant     = oko_merchant_for_order($addon_parent instanceof \WC_Order ? $addon_parent : $order);

	if (empty($merchant['api_key'])) {
		error_log("okoskabet_woocommerce_plugin: API key not set for order {$order_number}, merchant=" . (string) ($merchant['id'] ?? '?'));
		return;
	}

	if ($new_status === 'cancelled') {
		// An addition has no shipment of its own — its goods are on the order
		// it rode along with. Deleting under its own reference would answer 404
		// and leave the goods on that delivery, so the shop would refund a
		// customer for things the packing room still has on its list.
		if ($parent_id !== 0) {
			oko_take_addition_off_its_order($order, $parent_id, $merchant);
			return;
		}

		// The reference Økoskabet knows this order by, which is not always its
		// number: a shop with a numbering plugin books under one and WooCommerce
		// answers the other (#52).
		$response = oko_api_request(
			$merchant,
			'DELETE',
			'/api/v1/shipments/' . rawurlencode(oko_shipment_reference($order))
		);

		if (is_wp_error($response)) {
			error_log('okoskabet_woocommerce_plugin: Error deleting order ' . $order_number . ': ' . $response->get_error_message());
			return;
		}

		$http_code = wp_remote_retrieve_response_code($response);
		if ($http_code !== 204) {
			error_log('okoskabet_woocommerce_plugin: Error trying to delete order ' . $order_number . ', response(' . $http_code . '): ' . wp_remote_retrieve_body($response));
		}
	}

	// An addition refunded in full comes off its delivery the way a cancelled
	// one does. Refunding is how a shop takes goods off an order that has
	// been paid, and a refund of the money alone leaves the lines whole, so
	// only taking the addition off the list takes its goods off the box. An
	// order of its own is left as it was: what a refunded delivery should be
	// is the shop's to say, not ours.
	if ($new_status === 'refunded' && $parent_id !== 0) {
		oko_take_addition_off_its_order($order, $parent_id, $merchant);
		return;
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

		// An addition goes into the delivery it belongs to, not beside it. The
		// backoffice gets nothing new, which is the point: there is one box,
		// one freight and one shipment, and the packing room sees the extra
		// goods on the list they already have.
		if ($parent_id !== 0) {
			// Put on hold by us for the shop to decide. Only the shop moving it
			// on asks again; the hold itself would otherwise be one more try,
			// and one more note, on every save.
			if ($new_status === 'on-hold' && $order->get_meta(OKO_ADDITION_HELD_META, true) !== '') {
				return;
			}
			oko_send_addition_to_its_order($order, $parent_id, $merchant);
			return;
		}

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

		$response = oko_api_request($merchant, 'POST', '/api/v1/shipments/', (string) wp_json_encode($data));

		if (is_wp_error($response)) {
			$order->update_status('failed', $response->get_error_message());
			throw new \Exception($response->get_error_message());
		}

		$http_code = wp_remote_retrieve_response_code($response);
		$shipment = json_decode(wp_remote_retrieve_body($response), true);

		// 200 with the shipment is Økoskabet saying it has this one already: a
		// first attempt that went through while its answer did not reach us
		// (a timeout), and the order arriving here again. That is booked, not
		// failed — failing it threw the customer's checkout and marked a paid
		// order as failed for a delivery that was on its way.
		//
		// Only when it is this order's shipment. A reference is the order's
		// number, and a shop that has renumbered can hand a new order the number
		// of an old delivery that is still live — read as "booked", the new
		// order would be tied to a stranger's shipment and never sent. The
		// customer's email is the one thing the answer carries that says whose
		// it is; anything else stays a failure the shop can see.
		$their_email    = is_array($shipment) ? strtolower(trim((string) ($shipment['shipment']['customer']['email'] ?? ''))) : '';
		$already_booked = $http_code === 200
			&& $their_email !== ''
			&& $their_email === strtolower(trim((string) $order->get_billing_email()));

		if ($http_code !== 201 && !$already_booked) {
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

		// The reference it went out under; see OKO_SHIPMENT_REFERENCE_META.
		oko_keep_booked_reference($order, $data['shipment_reference']);

		// Record what Økoskabet now has. Without this the save below would look
		// like an edit and send the same order straight back a second time —
		// and the copy is what every later edit is measured against, so that
		// only what actually moved is sent.
		oko_remember_what_was_sent($order, $data);

		$order->save();
	}
}

/**
 * The fields of a shipment a change to which belongs back in the shop.
 *
 * The packing room moves a delivery to a shed, or back to the customer's
 * door, or corrects an address — in Økoskabet's backoffice, where the shop
 * cannot see it. WooCommerce then still holds what the customer ordered, and
 * the next edit in the shop sends that back over the correction. Avokadokontoret
 * renamed a product on 17 October 2026; the rename touched three orders, and
 * two deliveries that had been moved to a shed by hand and back again were
 * overwritten with the address they were ordered with.
 */
const OKO_DELIVERY_CHANGES = array(
	'shipment_type',
	'delivery_date',
	'shed_id',
	// Where the customer collects, for the third shape. A shed names its
	// `shed_id` here and an address its street; a collection said nothing,
	// because Økoskabet did not report it either — its webhook left a store
	// pickup's destination out altogether, so a merchant moving an order from
	// one of its shops to another told the shop nothing. Økoskabet now says
	// it (delivery_backend#1978), and this is the half that listens.
	'pickup_location_id',
	'recipient_name',
	'street_name',
	'house_number',
	'floor',
	'door',
	'supplementary_town',
	'city',
	'postal_code',
);

/**
 * Whether a webhook's `changes` touch the delivery itself.
 *
 * @param array<string,mixed> $changes The `changes` map from the webhook.
 * @return bool
 */
function oko_changes_touch_the_delivery(array $changes): bool
{
	return array_intersect_key($changes, array_flip(OKO_DELIVERY_CHANGES)) !== array();
}

/**
 * Put Økoskabet's delivery back on the WooCommerce order.
 *
 * The webhook says which fields changed, not what the delivery now is, and a
 * single changed `floor` cannot be merged into an address WooCommerce holds
 * as two composed lines. So the shipment is read whole and written whole —
 * one lookup, and the two ends agree afterwards rather than nearly agree.
 *
 * Nothing about money: not the shipping method, not the freight, not a line.
 * A delivery moved to a shed is the packing room's business; re-pricing an
 * order the customer has already paid is not, and a shop that saw its totals
 * change by themselves would be right to stop trusting this.
 *
 * Only what Økoskabet changed. Given what we last sent, a field where
 * Økoskabet still holds what we sent is left as the order has it: if the
 * order says something else, that is an edit of the shop's own still on its
 * way — a 5xx left to retry, a key Økoskabet would not take — and writing
 * Økoskabet's copy over it put the parcel back on the old address, with a
 * note blaming Økoskabet (#80's review). The save after the write-back then
 * sends it.
 *
 * @param \WC_Order                $order    The order to write on.
 * @param array<string,mixed>      $shipment The shipment as Økoskabet has it.
 * @param array<string,mixed>|null $sent     What we last sent, when known.
 * @return array<int,string> What was changed, for the order's own note.
 */
function oko_write_delivery_back(\WC_Order $order, array $shipment, ?array $sent = null): array
{
	$changed = array();

	// Økoskabet holds this field as we sent it, so it did not change it.
	$as_sent = static function (string $value, string $what_we_sent) use ($sent): bool {
		return $sent !== null && $value === $what_we_sent;
	};
	$sent_home = (array) ($sent['home_delivery'] ?? array());

	$date = sanitize_text_field((string) ($shipment['delivery_date'] ?? ''));
	if ($date !== '' && ! $as_sent($date, (string) ($sent['delivery_date'] ?? ''))
		&& $date !== (string) $order->get_meta('_billing_okoskabet_delivery_date', true)) {
		$order->update_meta_data('_billing_okoskabet_delivery_date', $date);
		/* translators: %s: the new delivery date. */
		$changed[] = sprintf(__('delivery date %s', O_TEXTDOMAIN), $date);
	}

	// Where the customer collects, when Økoskabet has moved the order to a
	// collection — or nothing, when it has moved it away from one. Written
	// like the shed below and read by the payload the same way, so a
	// collection made by hand survives the shop's next edit.
	$pickup = $shipment['pickup_location_id'] ?? null;
	$pickup = ($pickup === null || $pickup === '') ? '' : (string) (int) $pickup;
	// Compared with the place we send, not with whatever the order carries.
	// A place left behind on a door or a shed delivery is not one we send,
	// and read as ours, every webhook about such a delivery — a date moved by
	// a day — said it was "no longer a collection from the shop", and marked
	// the emptied place as Økoskabet's word.
	$sent_pickup = ($sent['shipment_type'] ?? '') === 'store_pickup' ? (string) ($sent['pickup_location_id'] ?? '') : '';
	if (! $as_sent($pickup, $sent_pickup) && $pickup !== (string) oko_collection_place($order)) {
		$order->update_meta_data('_billing_okoskabet_pickup_location_id', $pickup);
		// Økoskabet's word now, set or emptied, whatever the shipping line says.
		$order->update_meta_data(OKO_PICKUP_FROM_OKOSKABET_META, true);
		$changed[] = $pickup !== ''
			? __('collection from the shop', O_TEXTDOMAIN)
			: __('no longer a collection from the shop', O_TEXTDOMAIN);
	} elseif (oko_collection_place($order) === ''
		&& ! in_array((string) ($shipment['shipment_type'] ?? 'store_pickup'), array('', 'store_pickup'), true)
		&& ($sent === null || ($sent['shipment_type'] ?? '') === 'store_pickup')) {
		// A collection with no place — a shop with no pickup locations books
		// one with none — reads the same as no collection above, so Økoskabet
		// moving it to a shed or the door was never marked, the store-pickup
		// line won the next payload, and the shop's later address corrections
		// never went (#80's review). Økoskabet's own type says it moved.
		$order->update_meta_data(OKO_PICKUP_FROM_OKOSKABET_META, true);
		$changed[] = __('no longer a collection from the shop', O_TEXTDOMAIN);
	}

	$shed      = (array) (((array) ($shipment['shed_reservation'] ?? array()))['shed'] ?? array());
	$shed_id   = isset($shed['id']) ? sanitize_text_field((string) $shed['id']) : '';
	$shed_name = sanitize_text_field((string) ($shed['name'] ?? $shed_id));
	if (! $as_sent($shed_id, (string) ($sent['reservation']['shed_id'] ?? ''))
		&& $shed_id !== (string) $order->get_meta('_billing_okoskabet_shed_id', true)) {
		$order->update_meta_data('_billing_okoskabet_shed_id', $shed_id);
		$changed[] = $shed_id !== ''
			/* translators: %s: the name of the Økoskab. */
			? sprintf(__('delivery to Økoskab %s', O_TEXTDOMAIN), $shed_name)
			: __('no longer a delivery to an Økoskab', O_TEXTDOMAIN);
	}

	// The name the box is delivered to. Økoskabet holds it as one name,
	// WooCommerce as a first and a last, so it is written back only when the
	// two actually disagree: a name of three words composes to the same
	// string either way, and rewriting "Marc Seerup Hansen" as first "Marc",
	// last "Seerup Hansen" would put a change nobody made on every order.
	$home      = (array) ($shipment['home_delivery'] ?? array());
	$recipient = isset($home['recipient_name']) ? sanitize_text_field((string) $home['recipient_name']) : '';
	$held_name = oko_recipient_name($order);
	if ($recipient !== '' && ! $as_sent($recipient, (string) ($sent_home['recipient_name'] ?? '')) && $recipient !== $held_name) {
		$name = explode(' ', $recipient, 2);
		$order->set_shipping_first_name($name[0]);
		$order->set_shipping_last_name($name[1] ?? '');
		/* translators: %s: the name the delivery is now addressed to. */
		$changed[] = sprintf(__('recipient %s', O_TEXTDOMAIN), $recipient);
	}

	$address = (array) ($home['address'] ?? array());
	// Named once, as an address, rather than field by field. The note is read
	// by whoever runs the shop, and "shipping_address_1: <gade nr>,
	// shipping_address_2: 1 tv" is our own column names read out loud.
	//
	// Written through the named setters rather than `$order->{'set_' . $key}`:
	// a dynamic method name is invisible to the analyser, and this is the path
	// that rewrites a customer's delivery address.
	$address_moved = false;
	if ($address !== array()) {
		$lines = oko_delivery_address_lines($address);
		$held  = array(
			'shipping_address_1' => $order->get_shipping_address_1(),
			// The second line as we send it, not as the shipping form holds
			// it. A checkout with no second shipping line keeps the flat in
			// billing only, and we send it from there; compared with the empty
			// shipping line, the first webhook about such a delivery — a date
			// moved by a day — read Økoskabet's copy of our own flat as a move,
			// and wrote a note saying so on the order.
			'shipping_address_2' => oko_second_address_line($order),
			'shipping_city'      => $order->get_shipping_city(),
			'shipping_postcode'  => $order->get_shipping_postcode(),
		);

		$address_moved = oko_address_as_compared($lines) !== oko_address_as_compared($held)
			&& ! $as_sent(oko_address_as_compared($lines), oko_address_as_compared(array(
				'shipping_address_1' => (string) ($sent_home['address_1'] ?? ''),
				'shipping_address_2' => (string) ($sent_home['address_2'] ?? ''),
				'shipping_city'      => (string) ($sent_home['city'] ?? ''),
				'shipping_postcode'  => (string) ($sent_home['postal_code'] ?? ''),
			)));

		if ($address_moved) {
			$order->set_shipping_address_1($lines['shipping_address_1']);
			$order->set_shipping_address_2($lines['shipping_address_2']);
			$order->set_shipping_city($lines['shipping_city']);
			$order->set_shipping_postcode($lines['shipping_postcode']);

			// Økoskabet's address now, so the billing fallback is switched off
			// and cannot bring back a flat the backoffice took away. Only when
			// it moved: an address that did not is the one we sent, billing
			// flat and all, and marking it would drop from the next payload a
			// flat Økoskabet has — the shop's next address edit would then send
			// the address without it. The case the mark exists for, a flat
			// taken off a delivery whose shipping line was empty all along, is
			// a move now that the flat is compared as we send it.
			$order->update_meta_data(OKO_ADDRESS_FROM_OKOSKABET_META, true);
		}
	}

	if ($address_moved) {
		$changed[] = sprintf(
			/* translators: %s: the delivery address, on one line. */
			__('delivery address %s', O_TEXTDOMAIN),
			oko_address_on_one_line($order)
		);
	}

	return $changed;
}

/**
 * An order's delivery address, written the way a person would say it.
 *
 * @param \WC_Order $order
 * @return string
 */
function oko_address_on_one_line(\WC_Order $order): string
{
	$street = trim($order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2());

	return trim(implode(', ', array_filter(array(
		trim($street),
		trim($order->get_shipping_postcode() . ' ' . $order->get_shipping_city()),
	), static function (string $part): bool {
		return $part !== '';
	})));
}

/**
 * An address the way two of them are compared: the four fields read as one
 * line, without case, full stops or commas.
 *
 * A customer writes "Eksempelvej 12, 1. tv" on the first line and leaves the
 * second empty; Økoskabet gives back the street and the flat apart. Both are
 * the same address, and a field-by-field comparison would rewrite the order
 * and leave a note saying Økoskabet moved it, on every webhook about the
 * delivery — a date moved by a day included.
 *
 * @param array<string,string> $lines The order's field name => value.
 * @return string
 */
function oko_address_as_compared(array $lines): string
{
	$line = implode(' ', array(
		$lines['shipping_address_1'] ?? '',
		$lines['shipping_address_2'] ?? '',
		$lines['shipping_postcode'] ?? '',
		$lines['shipping_city'] ?? '',
	));

	$line = str_replace(array('.', ','), ' ', mb_strtolower($line));

	return trim((string) preg_replace('/\s+/u', ' ', $line));
}

/**
 * Økoskabet's address, as the two lines WooCommerce keeps it in.
 *
 * The register holds a street, a house number and a unit apart; WooCommerce
 * holds a line and a second line. Composed the way the plugin's own sending
 * is parsed at the other end, so writing this back and sending it again is a
 * round trip and not a slow drift.
 *
 * `supplementary_town` — "Prøveby" in "Eksempelgårdvej 122, Prøveby,
 * 2200 København N" — is a place name between the street and the postcode. It
 * goes on the first line after the house number, which is where the register
 * reads it from, and never on the second, which is the flat's.
 *
 * @param array<string,mixed> $address Økoskabet's address fields.
 * @return array<string,string> The order's field name => value.
 */
function oko_delivery_address_lines(array $address): array
{
	// Sanitised, like everything else this plugin takes off the wire. The body
	// is signed, so this is not about trust — it is that one unlucky field
	// would otherwise go on an order and into a note rendered in wp-admin.
	$field = static function (string $key) use ($address): string {
		return trim(sanitize_text_field((string) ($address[$key] ?? '')));
	};

	$street = trim(implode(' ', array_filter(array(
		$field('street_name'),
		$field('house_number'),
	), static function (string $part): bool {
		return $part !== '';
	})));

	$town = $field('supplementary_town');
	if ($town !== '') {
		$street = trim($street . ', ' . $town);
	}

	// Økoskabet holds the floor as the register does, "1" or "st", and puts
	// the full stop on only when it writes the address out ("1. tv"). So does
	// this, or every customer's "1. tv" would read as moved to "1 tv".
	$floor = $field('floor');
	if ($floor !== '' && substr($floor, -1) !== '.') {
		$floor .= '.';
	}

	$unit = trim(implode(' ', array_filter(array(
		$floor,
		$field('door'),
	), static function (string $part): bool {
		return $part !== '';
	})));

	return array(
		'shipping_address_1' => $street,
		'shipping_address_2' => $unit,
		'shipping_city'      => $field('city'),
		'shipping_postcode'  => $field('postal_code'),
	);
}

/**
 * Take Økoskabet's delivery and put it on the order it belongs to.
 *
 * Called when a webhook says a delivery changed. The shipment is read once
 * and written whole; the order gets a note naming what moved, so the shop can
 * see that somebody at Økoskabet changed it and what to.
 *
 * It does not send Økoskabet's change back. Writing the order is itself an
 * edit, and the resend would push that change at Økoskabet — so the record of
 * what Økoskabet holds is moved forward by what Økoskabet changed, and by
 * nothing else. The next genuine edit in the shop is then a difference
 * against the corrected delivery rather than against the one it replaced,
 * which is the whole point: it is what stops a product rename from undoing
 * the packing room's work.
 *
 * @param string              $reference The shipment reference, i.e. the order number.
 * @param array<string,mixed> $merchant  The merchant record.
 * @return array<int,string> What was changed, empty when nothing was.
 */
function oko_take_delivery_from_okoskabet(string $reference, array $merchant): array
{
	$order = oko_order_for_reference($reference);
	if ($order === null) {
		return array();
	}

	// Only an order of the account the webhook came in for. The status half
	// of the webhook checks this before it touches anything, and this half ran
	// in front of that check: a webhook for one merchant rewrote the address
	// of another merchant's order and was then answered 403 for it.
	if (! oko_order_belongs_to_merchant($order, $merchant)) {
		return array();
	}

	// Read fresh. The webhook is the signal, not the source: it says which
	// fields moved, and a single changed floor cannot be merged into an
	// address WooCommerce keeps as two composed lines. The remembered copy is
	// dropped first because the webhook is precisely the news that it is out
	// of date; nothing is dropped afterwards, because this writes only in
	// WooCommerce and leaves the shipment as it found it.
	oko_forget_shipment($reference, $merchant);
	$shipment = oko_shipment_at_okoskabet($reference, $merchant);
	if ($shipment === null) {
		$order->add_order_note(sprintf(
			/* translators: %s: the shipment reference. */
			__('Økoskabet changed the delivery for %s, but it could not be read back. The order still says what the customer ordered.', O_TEXTDOMAIN),
			$reference
		));
		return array();
	}

	// The order as it stands and what Økoskabet held of it, read before the
	// write-back changes the one they are both read from.
	//
	// An order with no record but a fingerprint that still matches gets the
	// record the resend would have written for it. One whose fingerprint does
	// not match has moved since it was sent, from something nobody knows; it
	// gets none, and the save below sends it whole, as any save of it would —
	// Økoskabet's change included, which is by then the order's own. An order
	// with no fingerprint at all was never compared with anything, so nothing
	// of the shop's is waiting to go, and the order as it stood is where its
	// record starts.
	$before = oko_shipment_payload($order, $merchant);
	$held   = null;
	if ($before !== null) {
		$held = $order->get_meta(OKO_SENT_META, true);
		if (! is_array($held)) {
			$held = (string) $order->get_meta(OKO_SENT_FINGERPRINT_META, true) === ''
				? oko_comparable_payload($before)
				: oko_sent_according_to_the_fingerprint($order, $before, $merchant);
		}
	}

	$changed = oko_write_delivery_back($order, $shipment, is_array($held) ? $held : null);
	if ($changed === array()) {
		// Nothing of the shop's own moved, so there is no note to write and
		// nothing to save: the write-back writes, and marks, only what it
		// finds different, and every one of those is in `$changed`.
		return array();
	}

	// Økoskabet's change goes into the record of what it holds, written in
	// the same breath as the change itself, and only that change. The resend
	// hook fires on this save and compares the order with the record: what
	// Økoskabet just changed is the same on both sides, so it is not pushed
	// back at Økoskabet — while an edit of the shop's own that has not got
	// through, a 5xx or a refused key left to retry, or a refusal held back,
	// is still a difference. This used to write the whole order down, and
	// those were marked as sent and never sent again.
	//
	// It replaces a "do not send" meta and a filter that read it. That worked,
	// but only as long as the meta came off again: an order that could not
	// build a payload kept it, and every later edit in the shop was dropped
	// for good, with no note and no line in the log. Saying what was sent is
	// the thing the resend already asks about; saying "not now" was a second
	// mechanism for the same question.
	$after = oko_shipment_payload($order, $merchant);
	if (is_array($held) && $after !== null) {
		oko_remember_what_was_sent($order, oko_with_okoskabets_changes($held, $before, $after));
	}

	$order->save();

	$order->add_order_note(sprintf(
		/* translators: %s: a list of what was changed, already joined. */
		__('Økoskabet changed the delivery: %s. Changed there, not here.', O_TEXTDOMAIN),
		implode(', ', $changed)
	));

	return $changed;
}

/**
 * The record of what Økoskabet holds, moved by what the write-back changed.
 *
 * What the write-back changed is told by the order's payload before and after
 * it, and nothing else touches the order in between. A key that moved takes
 * its new value, or goes, in the record; a key that did not keeps the
 * record's, whatever the order says — that difference is the shop's, still
 * on its way. The customer is followed field by field, as the resend sends
 * it: a renamed recipient is Økoskabet's, and a new phone beside it is not.
 *
 * @param array<string,mixed> $held   What Økoskabet held before.
 * @param array<string,mixed> $before The order's payload before the write-back.
 * @param array<string,mixed> $after  And after it.
 * @return array<string,mixed>
 */
function oko_with_okoskabets_changes(array $held, array $before, array $after): array
{
	$held   = oko_comparable_payload($held);
	$before = oko_comparable_payload($before);
	$after  = oko_comparable_payload($after);

	foreach (array_keys($before + $after) as $key) {
		if (array_key_exists($key, $before) && array_key_exists($key, $after) && $before[$key] === $after[$key]) {
			continue;
		}

		if (! array_key_exists($key, $after)) {
			unset($held[$key]);
			continue;
		}

		if (in_array($key, OKO_NESTED_PAYLOAD_KEYS, true)
			&& is_array($after[$key]) && is_array($before[$key] ?? null) && is_array($held[$key] ?? null)) {
			$held[$key] = oko_with_okoskabets_changes($held[$key], $before[$key], $after[$key]);
			continue;
		}

		$held[$key] = $after[$key];
	}

	return $held;
}

/**
 * Whether an order is one of this merchant's, as a webhook has to ask it.
 *
 * The stamp written at checkout says whose an order is. An order without one
 * predates the stamp and belongs to the default merchant. A merchant record
 * with no id — the legacy single-merchant fallback — owns everything.
 *
 * @param \WC_Order           $order
 * @param array<string,mixed> $merchant
 */
function oko_order_belongs_to_merchant(\WC_Order $order, array $merchant): bool
{
	$merchant_id = (string) ($merchant['id'] ?? '');

	return $merchant_id === '' || oko_order_merchant_id($order) === $merchant_id;
}

/**
 * The merchant an order is stamped with, or the default one for an order
 * from before the stamp.
 *
 * @param \WC_Order $order
 */
function oko_order_merchant_id(\WC_Order $order): string
{
	$owner = sanitize_key((string) $order->get_meta(\okoskabet_woocommerce_plugin\Integrations\Merchant_Router::ORDER_META_KEY, true));

	return $owner !== '' ? $owner : \okoskabet_woocommerce_plugin\Integrations\Merchants::default_id();
}

/**
 * Write down the order as Økoskabet now has it.
 *
 * The two metas are written together everywhere, and an order carrying only
 * one of them is precisely the state the resend has to special-case — a
 * caller that wrote one and forgot the other would put a live order into it
 * silently. One function, so there is nothing to forget.
 *
 * Meta only: the caller decides whether this is part of a save.
 *
 * @param \WC_Order           $order
 * @param array<string,mixed> $payload What was sent, before it was trimmed
 *                                     down to a difference.
 */
function oko_remember_what_was_sent(\WC_Order $order, array $payload): void
{
	$order->update_meta_data(OKO_SENT_FINGERPRINT_META, oko_shipment_fingerprint($payload));
	$order->update_meta_data(OKO_SENT_META, oko_comparable_payload($payload));
}

/**
 * Meta holding a fingerprint of what we last told Økoskabet about an order.
 *
 * ## It can go, and here is exactly when
 *
 * Everything it holds is derivable from `OKO_SENT_META`, which is written in
 * the same breath — so the hash answers nothing for an order that has the
 * payload. Its one job is the order that does *not*: one sent by a version
 * that recorded a fingerprint and no payload. For such an order the hash is
 * the only thing left to compare, and it buys the moment the record can be
 * written down for free: the order has not moved since it was hashed, so what
 * was hashed is what Økoskabet holds. See oko_sent_according_to_the_fingerprint().
 *
 * What was hashed, which is not always today's payload. A hash of today's
 * payload says today's payload. A hash 1.5.2 took says the order as 1.5.2
 * built it, and where 1.5.2 built a field differently, the match vouches for
 * 1.5.2's version and not for ours — so the address is written down as 1.5.2
 * sent it (oko_sent_by_1_5_2(), which says what else is and is not), and the
 * order stays on 1.5.2's address from then on (OKO_SENT_BY_1_5_2_META).
 *
 * Without it, every order placed before this release would send its *whole*
 * payload on its first save — over whatever the backoffice had corrected in
 * the meantime. That is the bug this whole change exists to stop, so it stays
 * until no editable order is in that state. That needs two things, and
 * neither can be hurried:
 *
 *   1. this release live long enough that every such order has either been
 *      saved once, which upgrades it, or been locked by Økoskabet;
 *   2. no backfill — the hash is not reversible, so writing `OKO_SENT_META`
 *      for an order whose hash does not match would bless edits Økoskabet
 *      never accepted.
 *
 * When that holds: delete this constant, the `$sent_fingerprint === ''` guard,
 * and oko_sent_according_to_the_fingerprint() with the 1.5.2 projection it
 * reads. `is_array($sent)` then carries "sent by a recording version" on its
 * own. Tracked as an issue rather than left here to be remembered.
 */
const OKO_SENT_FINGERPRINT_META = '_okoskabet_sent_fingerprint';

/**
 * Meta holding the order as Økoskabet last accepted it.
 *
 * The fingerprint above answers "has anything moved"; this answers "what".
 * Both are kept because they are written together and read at different
 * moments — an order sent by an older version has the fingerprint and not
 * this, and the resend knows what to do with that.
 */
const OKO_SENT_META = '_okoskabet_sent_payload';

/**
 * Meta holding the shape of the last change Økoskabet refused.
 *
 * So that a change it will never accept is not sent on every save for the
 * rest of the order's life, while a different change still gets its own try.
 */
const OKO_REFUSED_CHANGE_META = '_okoskabet_refused_change';

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
 * A payload reduced to what is actually about the order.
 *
 * Leaves out what describes the request rather than the order: the language
 * and the shop's own address can differ between the customer's checkout and
 * an admin's screen on a multilingual shop, and that is not an edit.
 * `allow_invalid` goes the same way — the other end reads it when a shipment
 * is created and never again.
 *
 * `notes` is left out for a different reason: it is not something we send
 * again at all after the order is created, so a change to the shop's copy of
 * it is not a reason to resend — and counting it would send an order whose
 * note is the only thing that moved, with the note stripped, achieving
 * nothing.
 *
 * And from each line, what the catalogue says about the goods rather than
 * what the order says: see OKO_CATALOGUE_LINE_KEYS.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>
 */
function oko_comparable_payload(array $payload): array
{
	unset(
		$payload['locale'],
		$payload['webshop_products_url'],
		$payload['notes'],
		$payload['allow_invalid']
	);

	if (isset($payload['line_items']) && is_array($payload['line_items'])) {
		$payload['line_items'] = array_map(static function ($line) {
			return is_array($line) ? array_diff_key($line, array_flip(OKO_CATALOGUE_LINE_KEYS)) : $line;
		}, $payload['line_items']);
	}

	return $payload;
}

/**
 * The keys of a line that come from the catalogue, not from the order.
 *
 * A new picture on a product, a tag, a category or an attribute is an edit of
 * the product, and every open order with that product would have read it as
 * an edit of the order: its next save, a status change from a webhook
 * included, sent the goods again. That is a PUT of the whole list for each of
 * them, and any change Økoskabet refused before went along with it, to be
 * refused and noted once more. So these do not count as a change, and are not
 * kept in the record of what was sent. They still travel whenever the goods
 * do, which is when the packing room reads them.
 */
const OKO_CATALOGUE_LINE_KEYS = array('tags', 'properties', 'image');

/** A fingerprint of a payload, for telling two of them apart. */
function oko_shipment_fingerprint(array $payload): string
{
	return md5((string) wp_json_encode(oko_comparable_payload($payload)));
}

/**
 * The fingerprint 1.5.2 would have taken of this order as it stands.
 *
 * 1.5.2 kept a fingerprint and nothing else, taken of a payload shaped a
 * little differently from today's: six keys on a line, the shipment named by
 * the order's number, an untrimmed recipient, the second address line as
 * typed, the delivery note repeated as `location` on the address — and only
 * the language and the products address left out of the hash, so the note and
 * `allow_invalid` are in it. Rebuilt here from today's payload so an order that
 * has not moved since 1.5.2 sent it can be told apart from one that has.
 *
 * Only the order's own goods. 1.5.2 had no additions, so what it hashed was
 * the order's lines alone; an addition placed after the update puts its goods
 * at the end of today's list, and counting them made every 1.5.2 order that
 * took an addition look changed — and its first save sent the whole order,
 * over the packing room's corrections. Seen on staging 23/10.
 *
 * @param \WC_Order           $order
 * @param array<string,mixed> $payload Today's payload for it, whole.
 */
function oko_fingerprint_as_1_5_2(\WC_Order $order, array $payload, ?array $merchant = null): string
{
	$payload['shipment_reference'] = (string) $order->get_order_number();

	$payload['line_items'] = oko_lines_as_1_5_2($order, $merchant);

	if (isset($payload['home_delivery'])) {
		$recipient = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();

		$payload['customer']['recipient_name'] = $recipient;
		$payload['home_delivery']              = array_filter(array(
			'recipient_name' => $recipient,
			'address_1'      => $order->get_shipping_address_1(),
			'address_2'      => $order->get_shipping_address_2(),
			'city'           => $order->get_shipping_city(),
			'postal_code'    => $order->get_shipping_postcode(),
			'location'       => oko_logistics_note($order),
		));
	}

	unset($payload['locale'], $payload['webshop_products_url']);

	return md5((string) wp_json_encode($payload));
}

/**
 * The order's own goods as 1.5.2 sent them: six keys on a line, in its order.
 *
 * @param \WC_Order $order
 * @return array<int,array<string,mixed>>
 */
function oko_lines_as_1_5_2(\WC_Order $order, ?array $merchant = null): array
{
	return array_map(static function (array $line): array {
		return array(
			'product_id'    => $line['product_id'] ?? null,
			'variant_id'    => $line['variant_id'] ?? null,
			'name'          => $line['name'] ?? '',
			'variant_title' => $line['variant_title'] ?? null,
			'sku'           => $line['sku'] ?? '',
			'quantity'      => $line['quantity'] ?? 0,
		);
	}, oko_order_line_items($order, $merchant));
}

/**
 * What 1.5.2 told Økoskabet about an order whose 1.5.2 fingerprint matches.
 *
 * The match says nothing has moved since 1.5.2 sent the order, but in 1.5.2's
 * terms: it vouches for the fields as 1.5.2 built them, and some of those are
 * not what today's payload sends. The second address line is the one that
 * matters. 1.5.2 sent the shipping line as typed, and today's payload falls
 * back to the billing address when the checkout has no second shipping line —
 * so writing today's payload down recorded as sent a flat Økoskabet never got,
 * and the next edit found nothing to send for the address.
 *
 * So the address is written as 1.5.2 sent it, and everything else as today's
 * payload has it, which the match says is the same. And it stays 1.5.2's:
 * the order is marked (OKO_SENT_BY_1_5_2_META), and the billing flat is not
 * brought in later either, where it would be sent over the backoffice's
 * corrections. Two of 1.5.2's quirks are
 * left out on purpose, because Økoskabet's copy does not have them either:
 * `location` on the address, a field it never had and cast away unread, which
 * written down would make every address differ forever; and the space 1.5.2
 * put after a recipient with no last name, which would send the whole address
 * to take a space off — over whatever the packing room corrected while the
 * shop ran 1.5.2, which wrote nothing back.
 *
 * The goods are today's too, on purpose. The match vouches for the six keys
 * 1.5.2 sent on a line, which are today's six; beside them today's line has
 * what the catalogue says, which is never recorded (OKO_CATALOGUE_LINE_KEYS),
 * and at a shop that sends them, the line's total. 1.5.2 never sent a total,
 * but it is written down as sent all the same: recorded as missing, it made
 * the second save of every open 1.5.2 order — a status change, or another
 * plugin's meta — send all its goods to add the totals, and an order Økoskabet
 * had locked by then got a note about an edit nobody made. The totals go with
 * the goods the next time the goods change.
 *
 * @param \WC_Order           $order
 * @param array<string,mixed> $comparable Today's payload for it, comparable.
 * @return array<string,mixed>
 */
function oko_sent_by_1_5_2(\WC_Order $order, array $comparable): array
{
	$sent = $comparable;

	if (isset($sent['home_delivery'])) {
		$sent['home_delivery'] = array_filter(array(
			'recipient_name' => oko_recipient_name($order),
			'address_1'      => $order->get_shipping_address_1(),
			'address_2'      => $order->get_shipping_address_2(),
			'city'           => $order->get_shipping_city(),
			'postal_code'    => $order->get_shipping_postcode(),
		));
	}

	return $sent;
}

/**
 * What Økoskabet holds for an order that has a fingerprint and no record, as
 * far as the fingerprint can say — or null when it cannot.
 *
 * It can when the order has not moved since it was hashed, by today's hash or
 * by 1.5.2's; see OKO_SENT_FINGERPRINT_META. An order that has moved is one we
 * know nothing about: what it moved from is not in a hash.
 *
 * @param \WC_Order           $order
 * @param array<string,mixed> $payload Today's payload for it.
 * @param array<string,mixed> $merchant
 * @return array<string,mixed>|null
 */
function oko_sent_according_to_the_fingerprint(\WC_Order $order, array $payload, array $merchant): ?array
{
	$fingerprint = (string) $order->get_meta(OKO_SENT_FINGERPRINT_META, true);
	$comparable  = oko_comparable_payload($payload);

	if ($fingerprint === '') {
		return null;
	}

	if (oko_shipment_fingerprint($comparable) === $fingerprint) {
		return $comparable;
	}

	if (oko_fingerprint_as_1_5_2($order, $payload, $merchant) === $fingerprint) {
		// Remembered on the order with the record the caller writes, and in
		// memory before that, so the address is read in 1.5.2's terms from
		// here on — the write-back about to compare it included.
		$order->update_meta_data(OKO_SENT_BY_1_5_2_META, true);

		return oko_sent_by_1_5_2($order, $comparable);
	}

	return null;
}

/**
 * The parts of a payload whose fields the other end takes one at a time.
 *
 * Only the customer. Everything else that looks like an object is read as a
 * whole and has to be sent as one: an address is two composed lines that
 * Økoskabet washes into a street, a number and a flat *together*, and the
 * wash only runs when the first line is there. Measured on Gaardmester
 * staging on 19 October 2026 — an order whose second line alone had moved
 * was accepted, changed nothing, and said nothing. A shed reservation is
 * read the same way, and is two fields anyway.
 *
 * The customer's four fields have no such reading between them: each is
 * written on its own, which is what lets a changed phone leave a name the
 * backoffice corrected exactly where it is.
 */
const OKO_NESTED_PAYLOAD_KEYS = array('customer');

/**
 * Which shape of delivery a payload describes.
 *
 * Not the value of `shipment_type` alone: the keys themselves move when an
 * order changes destination — `home_delivery` gives way to `reservation`, and
 * back — and that is the one change a difference cannot express, because
 * leaving a key out means "unchanged" and never "gone".
 *
 * `pickup_location_id` is not asked about: it is written in the same branch
 * as the only `shipment_type` there is, so it can never say anything the
 * first component has not said already.
 *
 * @param array<string,mixed> $payload
 */
function oko_destination_shape(array $payload): string
{
	return implode('|', array(
		(string) ($payload['shipment_type'] ?? ''),
		isset($payload['home_delivery']) ? 'home' : '',
		isset($payload['reservation']) ? 'shed' : '',
	));
}

/**
 * What has changed since Økoskabet was last told about this order.
 *
 * The other end writes the fields it is given and leaves the rest as they
 * stand, so sending only what moved is what keeps a product rename in the
 * shop from undoing an address the packing room corrected. Sending the whole
 * order every time is what made that possible in the first place.
 *
 * Returns an empty array when nothing moved — the order is not sent at all
 * then — and the whole payload when the delivery changed shape, which is not
 * a field edit and is not safe to express as a difference.
 *
 * @param array<string,mixed> $sent    What Økoskabet accepted last.
 * @param array<string,mixed> $payload What the order says now.
 * @return array<string,mixed>
 */
function oko_payload_changes(array $sent, array $payload): array
{
	$sent    = oko_comparable_payload($sent);
	$payload = oko_comparable_payload($payload);

	if (oko_destination_shape($sent) !== oko_destination_shape($payload)) {
		return $payload;
	}

	$changes = array();

	foreach ($payload as $key => $value) {
		if (! array_key_exists($key, $sent)) {
			$changes[$key] = $value;
			continue;
		}

		$before = $sent[$key];

		if (is_array($value) && is_array($before) && in_array($key, OKO_NESTED_PAYLOAD_KEYS, true)) {
			$inner = oko_nested_changes($before, $value);
			if ($inner !== array()) {
				$changes[$key] = $inner;
			}
			continue;
		}

		if ($before !== $value) {
			$changes[$key] = $value;
		}
	}

	// A key the order has stopped carrying altogether — a delivery date taken
	// off — is left alone, because an absent key reads as "unchanged" at the
	// other end. That is not new: the whole payload left it out too, and the
	// date stayed. Changing it belongs in Økoskabet's own backoffice.

	return $changes;
}

/**
 * The same comparison one level down, inside the customer.
 *
 * A field the order has stopped carrying is sent as empty rather than left
 * out: inside an object, leaving it out reads as "unchanged" just as it does
 * at the top, and a second address line the shop has deleted would otherwise
 * stay on the delivery for good.
 *
 * @param array<string,mixed> $before
 * @param array<string,mixed> $now
 * @return array<string,mixed>
 */
function oko_nested_changes(array $before, array $now): array
{
	$changes = array();

	foreach ($now as $key => $value) {
		if (! array_key_exists($key, $before) || $before[$key] !== $value) {
			$changes[$key] = $value;
		}
	}

	foreach ($before as $key => $value) {
		if (! array_key_exists($key, $now)) {
			$changes[$key] = '';
		}
	}

	return $changes;
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
 * Økoskabet's reason, short enough to be a note.
 *
 * A refusal with a message answers JSON and the message is a sentence. A 404
 * answers plain text, and a server in front of Økoskabet can answer a whole
 * HTML page — which is why this cuts rather than trusts the length, and why
 * the tags go: a note is rendered in the admin, and an error page pasted into
 * one would bring its markup along.
 *
 * @param string $reason The reason as it came back.
 * @return string
 */
function oko_short_reason(string $reason): string
{
	$reason = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($reason)) ?? '');

	if ($reason === '') {
		return __('no reason given', O_TEXTDOMAIN);
	}

	if (mb_strlen($reason) > 200) {
		return mb_substr($reason, 0, 200) . '…';
	}

	// Exactly one sentence ending, because the note puts a sentence of its own
	// after this one. Økoskabet's own messages end with a full stop and the
	// note read "… ufuldstændige.." on staging; a 404 answers plain text and
	// ends with nothing at all.
	return preg_match('/[.!?:…]$/u', $reason) === 1 ? $reason : $reason . '.';
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
 *   - A failure that can be retried — no answer at all, or a 5xx — is logged
 *     and the fingerprint is left alone, so the next save tries again. A
 *     *refusal* is a verdict: it is recorded as dealt with and never sent
 *     again, so it also gets a note on the order. Without one the shop had no
 *     way at all to learn that an edit never arrived; the only record was a
 *     line in `debug.log`.
 *   - It never touches the order's status: an edit that cannot reach
 *     Økoskabet is a problem to retry, not a reason to fail an order a
 *     customer has already paid for.
 *
 * @param int $order_id
 */
function oko_resend_shipment_on_update(int $order_id): void
{
	static $seen = array();
	// Orders whose hold this function took itself, to send their goods under.
	static $holding = array();

	if (isset($seen[$order_id])) {
		return;
	}

	$order = wc_get_order($order_id);
	if (! $order instanceof \WC_Order) {
		return;
	}

	// Held by this request, but not by this function: an addition is being
	// written onto this order right now, and the save that brought us here is
	// that write's own bookkeeping. On WooCommerce's order tables every meta
	// write saves the whole order as well, to move its "modified" time — so
	// recording the goods just sent ran this resend before the addition was
	// on the order's list, and it sent the goods without it: the addition's
	// goods gone from the delivery a moment after they were put on (#89,
	// found by #84's HPOS run). The write sends the goods and keeps the record
	// itself; nothing is sent here, and anything else of the order's own goes
	// with its next save. A 1.5.2 order still gets its record below, which
	// sends nothing.
	$held_here  = isset(oko_additions_held_here()[$order_id]);
	$by_a_write = $held_here && empty($holding[$order_id]);

	// Under the hold it took itself, the list is read as the last write left
	// it, not as this request first loaded it.
	if ($held_here && ! $by_a_write) {
		$order->read_meta_data(true);
	}

	// Never sent, or on its way out. A cancellation is a DELETE elsewhere.
	//
	// A refund in full is neither: the money went back, often after the goods
	// were delivered, and the shipment is the shop's to cancel if the delivery
	// is not to happen (Marc, #82). Sent again it went with `line_items: []`,
	// and the packing room found a delivery with nothing in it.
	if (empty($order->get_meta('billing_okoskabet_done', true))
		|| in_array($order->get_status(), array('cancelled', 'refunded'), true)) {
		return;
	}

	// An addition has no shipment of its own: its goods are lines on the
	// shipment of the order it rides on, so that is the order to send again.
	// It is never fingerprinted, and this used to stop at the fingerprint —
	// so a line refunded off an addition, or edited on it, stayed on the
	// packing list, and the customer paid for less than went in the box.
	// The earlier order's own resend builds its goods with every addition's,
	// refunds taken off, and sends them when they differ from what Økoskabet
	// holds; a save that changed nothing about the goods sends nothing.
	//
	// Only while the addition is on that order's list, and not once it is
	// refunded in full: the status hook takes it off the list then, with a
	// line on the shipment's note, as it does for a cancelled one.
	$parent_id = (int) $order->get_meta(OKO_ADDON_PARENT_ID_META, true);
	if ($parent_id !== 0 && $parent_id !== $order_id) {
		$parent = wc_get_order($parent_id);
		if ($order->get_status() !== 'refunded'
			&& $parent instanceof \WC_Order
			&& in_array($order_id, oko_addition_ids($parent), true)) {
			oko_resend_shipment_on_update($parent_id);
		}
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

	$merchant = oko_merchant_for_order($order);
	if (empty($merchant['api_key'])) {
		return;
	}

	// Orders sent before this version have no record of what was sent, so
	// there is nothing to compare an edit with — and treating "no record" as
	// "changed" would send every old order again the first time a shop marks
	// it completed. They stay as they were placed.
	//
	// Asked before the payload is built, and that is the point: a meta read
	// against a whole payload — every line, every product's terms and picture,
	// the refunds, the merchant resolved twice and a call to Økoskabet — built
	// only to be thrown away on the next line. Every order placed before this
	// release is in that state, so it was paid on every save and every refund
	// of all of them.
	$sent_fingerprint = (string) $order->get_meta(OKO_SENT_FINGERPRINT_META, true);
	if ($sent_fingerprint === '') {
		return;
	}

	$payload = oko_shipment_payload($order, $merchant);
	if ($payload === null) {
		return;
	}

	// Once, and passed on: every reader below wants the payload without what
	// describes the request rather than the order.
	$comparable = oko_comparable_payload($payload);
	$sent       = $order->get_meta(OKO_SENT_META, true);
	$held       = is_array($sent) ? null : oko_sent_according_to_the_fingerprint($order, $payload, $merchant);

	if (is_array($sent)) {
		$changes = oko_payload_changes($sent, $comparable);
	} elseif ($held !== null) {
		// Sent by a version that recorded a fingerprint and not the payload.
		// Nothing has moved, so this is the free moment to write down what
		// Økoskabet holds — after this the order compares like any other.
		//
		// Asked in 1.5.2's terms too, or every open order would be sent whole
		// on its first save after the update — see oko_fingerprint_as_1_5_2().
		// And written down in them: where 1.5.2 sent a field differently from
		// today, the next save sends today's, which is a real difference.
		$order->update_meta_data(OKO_SENT_META, $held);
		$order->save_meta_data();
		return;
	} else {
		// Something moved, and we have no record of what it moved from. The
		// whole order goes, exactly as it did before differences — once per
		// order, and never again.
		$changes = $comparable;
	}

	if ($changes === array() || $by_a_write) {
		return;
	}

	// The goods go only while the order's additions are held still, as every
	// other write of its list of goods does. Built outside the hold, the list
	// could miss an addition written at the same moment and land after it:
	// its goods gone from the delivery while the order says they are on, and
	// no later save to notice, because the record says they were sent (#81).
	// So the hold is taken and the whole resend run again under it, reading
	// the order afresh. Taken by another request, nothing is sent and nothing
	// recorded: the next save tries again.
	if (isset($changes['line_items']) && ! $held_here) {
		$hold = oko_lock_additions_to($order_id);
		if ($hold === '') {
			return;
		}
		$holding[$order_id] = true;
		try {
			oko_resend_shipment_on_update($order_id);
		} finally {
			unset($holding[$order_id]);
			oko_unlock_additions_to($order_id, $hold);
		}
		return;
	}

	// A refusal is about the change, not the order. Økoskabet answering 422
	// to one edit must not stop the next one, but sending the identical thing
	// again cannot help either — so the refused change is remembered by shape
	// and only that exact shape is held back.
	$change_print = oko_shipment_fingerprint($changes);
	if ($change_print === (string) $order->get_meta(OKO_REFUSED_CHANGE_META, true)) {
		return;
	}

	$seen[$order_id] = true;

	// The goods go as the payload has them, catalogue and all. The difference
	// was found without what the catalogue says (see OKO_CATALOGUE_LINE_KEYS),
	// and so was the refusal's print above, but the packing room reads those
	// facts off the very lines this replaces.
	$body = $changes;
	if (isset($body['line_items'])) {
		$body['line_items'] = $payload['line_items'];
	}

	// The reference Økoskabet booked it under, not the order's number: a shop
	// with a numbering plugin has two, and only one of them is ours (#52).
	$response = oko_api_request(
		$merchant,
		'PUT',
		'/api/v1/shipments/' . rawurlencode(oko_shipment_reference($order)),
		(string) wp_json_encode($body)
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
			(string) ($merchant['id'] ?? '?'),
			$body
		));

		// And on the order, because the log is not a place anyone looks. This
		// is how it stayed invisible: the shop edited an order, the screen
		// said the edit was saved, nothing reached Økoskabet, and the only
		// record was a line in `debug.log`. Measured on Gaardmester staging
		// 2026-10-20 with order 88091 — a corrected address refused and lost
		// with nothing said anywhere a person would see it.
		$order->add_order_note(sprintf(
			/* translators: %d: the HTTP status Økoskabet answered with. */
			__('The change did not reach Økoskabet: the API key was refused (%d). Check the key under Økoskabet settings — until then, edits to this order stay in the shop. It is tried again next time the order is saved.', O_TEXTDOMAIN),
			$http_code
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

			$order->add_order_note(__(
				'The change did not reach Økoskabet: the order can no longer be changed there, because a parcel has been received. This and later edits stay in the shop.',
				O_TEXTDOMAIN
			));
		} else {
			error_log(sprintf(
				'okoskabet_woocommerce_plugin: resend of order %s refused (%d), not retrying: %s',
				$order->get_order_number(),
				$http_code,
				$reason
			));

			// The reason in Økoskabet's own words, which are already in the
			// shop's language, and the code beside it so a reason that says
			// nothing is still placed. Cut short because a 404 answers plain
			// text rather than a message, and a note is not a log.
			$order->add_order_note(sprintf(
				/* translators: 1: the HTTP status Økoskabet answered with, 2: Økoskabet's reason. */
				__('The change did not reach Økoskabet, which refused it (%1$d): %2$s The order now says something Økoskabet does not, and this change is not sent again — a later edit is tried on its own.', O_TEXTDOMAIN),
				$http_code,
				oko_short_reason($reason)
			));
		}

		// Refused, so Økoskabet still holds what it held before: the record of
		// what it has must not move. Only this exact change is held back, and
		// the next edit — including one that touches nothing else — is sent
		// and refused on its own merits rather than silently dropped.
		//
		// Moving the record here instead would lose the refused field for
		// good: the next edit would compare against an order Økoskabet never
		// accepted, find that field unchanged, leave it out, and the two ends
		// would disagree about it forever with nothing said.
		$order->update_meta_data(OKO_REFUSED_CHANGE_META, $change_print);
		$order->save_meta_data();
		return;
	}

	// Meta only, not a full save — writing down what was sent should not
	// itself read as an edit and call us straight back. `save_meta_data()`
	// also goes through the order's data store, so this works whether the shop
	// keeps orders in posts or in WooCommerce's own tables.
	oko_remember_what_was_sent($order, $comparable);
	$order->delete_meta_data(OKO_REFUSED_CHANGE_META);
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

add_action('woocommerce_checkout_create_order', 'okoskabet_woocommerce_plugin_clear_shed_id_for_home_delivery', 10, 2);

function okoskabet_woocommerce_plugin_clear_shed_id_for_home_delivery($order, $data): void
{
	$shipping_methods = (array) ($data['shipping_method'] ?? array());

	if (in_array('hey_okoskabet_shipping_home', $shipping_methods, true)) {
		$order->update_meta_data('_billing_okoskabet_shed_id', '');
	}
}

add_action('woocommerce_checkout_create_order', 'okoskabet_woocommerce_plugin_clear_pickup_id_unless_store_pickup', 10, 2);

/**
 * A place to collect from stays only on an order collected from one.
 *
 * The hidden field keeps whatever the pickup picker last wrote into it, and
 * only the browser empties it when the customer moves on to a locker or the
 * door. A checkout whose script did not run there posted it anyway, and the
 * order carried a collection point beside its shed. The payload no longer
 * reads such a place, but the admin screen prints it, and the order should
 * say what was chosen. The shed's own clear, above, for the same reason.
 *
 * @param \WC_Order           $order The order being created.
 * @param array<string,mixed> $data  Posted checkout data.
 */
function okoskabet_woocommerce_plugin_clear_pickup_id_unless_store_pickup($order, $data): void
{
	if (! in_array('hey_okoskabet_shipping_store_pickup', (array) ($data['shipping_method'] ?? array()), true)) {
		$order->update_meta_data('_billing_okoskabet_pickup_location_id', '');
	}
}

/**
 * Stamp the delivery-location source and apply a default when neither
 * the dropdown nor the Andet free-text was set on a home-delivery order.
 *
 * Source values feed the admin metabox and the post-save diagnostic
 * log; the default ensures the driver always has a usable instruction
 * even when our checkout JS never rendered (page cache, blocks-checkout,
 * mobile timing, etc.). Runs pre-save — `get_id()` would be 0 here, so
 * logging lives in the post-save companion below.
 */
add_action('woocommerce_checkout_create_order', 'okoskabet_woocommerce_plugin_default_delivery_location', 25, 2);

function okoskabet_woocommerce_plugin_default_delivery_location($order, $data): void
{
	if (! in_array('hey_okoskabet_shipping_home', (array) ($data['shipping_method'] ?? array()), true)) {
		return;
	}

	$location = trim((string) $order->get_meta('_billing_okoskabet_delivery_location', true));
	$note     = trim((string) $order->get_meta('_billing_okoskabet_delivery_note', true));

	if ($location !== '') {
		$source = 'dropdown';
	} elseif ($note !== '') {
		$source = 'andet_text';
	} else {
		$source = 'default_fallback';
		$order->update_meta_data('_billing_okoskabet_delivery_location', 'In front of the front door');
	}

	$order->update_meta_data('_billing_okoskabet_delivery_location_source', $source);
}

/**
 * Log default_fallback events post-save, so order_id is non-zero and
 * correlatable with the Økoskabet backend. UA + postcode + login state
 * is enough to spot patterns (mobile Safari, guest-only, postcode
 * cluster) without dumping PII.
 */
add_action('woocommerce_checkout_order_processed', 'okoskabet_woocommerce_plugin_log_default_fallback', 10, 1);

function okoskabet_woocommerce_plugin_log_default_fallback(int $order_id): void
{
	$order = wc_get_order($order_id);
	if (! $order instanceof \WC_Order) {
		return;
	}
	if ($order->get_meta('_billing_okoskabet_delivery_location_source', true) !== 'default_fallback') {
		return;
	}

	$ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 200) : '';
	error_log(sprintf(
		'okoskabet_woocommerce_plugin: delivery_location default_fallback order_id=%d postcode=%s user=%s ua=%s',
		$order_id,
		$order->get_shipping_postcode(),
		is_user_logged_in() ? 'member' : 'guest',
		$ua
	));
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
 * The shop's own tags on a product, the way the lookup answers them.
 *
 * Tags are what Økoskabet splits dispatch notes into zones by, so they are
 * the one field that must not go missing. They live on the product, never on
 * the variation, so a variation is asked on its parent's behalf — otherwise a
 * line for a variation would come back with no tags and fall out of its zone.
 */
function oko_product_tags(\WC_Product $product): array
{
	$id = $product instanceof \WC_Product_Variation
		? (int) $product->get_parent_id()
		: (int) $product->get_id();

	// Asked once per product for the length of the request. A shipment's whole
	// list of goods is rebuilt from scratch every time an addition arrives,
	// and six variations of the same product are six identical term queries —
	// as is a basket holding the same goods in two sizes.
	static $tags = array();

	if (! isset($tags[$id])) {
		$tags[$id] = array_values(array_map('strval', (array) get_terms(array(
			'taxonomy'   => 'product_tag',
			'object_ids' => $id,
			'fields'     => 'names',
			'hide_empty' => false,
		))));
	}

	return $tags[$id];
}

/**
 * The picture of the goods, the way the lookup answers it.
 *
 * A variation without its own picture answers the parent's: WooCommerce does
 * that itself in `WC_Product_Variation::get_image_id()`, so the packing room
 * sees what the customer saw without us arranging it. We wrote that fallback
 * out by hand first; a mutation check showed the test passed without it, and
 * WooCommerce's source said why. The test stays — it is the parent's picture
 * we depend on arriving, whoever fetches it.
 *
 * An empty string, never null: the other end reads it as "no picture", and a
 * missing picture is not an error.
 */
function oko_product_image_url(\WC_Product $product): string
{
	$image_id = (int) $product->get_image_id();

	return $image_id > 0 ? (string) \wp_get_attachment_url($image_id) : '';
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
	// Asked once per product for the length of the request, as the tags are.
	// Every line of an order is a lookup — `wp_get_post_terms` for each public
	// taxonomy and `wc_get_product_terms` for each visible attribute, none of
	// which the object cache answers — and a shipment's whole list is rebuilt
	// from scratch every time an addition arrives. Six variations of the same
	// product were six identical scans of the parent.
	static $properties_of = array();

	// Its own name: `$key` below is each property's key, and reusing it
	// stored the answer under the last attribute's key, where the next
	// lookup by product never found it.
	$memo_key = (int) $product->get_id();
	if (isset($properties_of[$memo_key])) {
		return $properties_of[$memo_key];
	}

	// A variation is one choice out of the parent's list. Answering with the
	// parent's whole list would put "Danmark, Spanien" on the packing slip for
	// a customer who bought the Spanish one, and country of origin is the one
	// field that has to be right.
	if ($product instanceof \WC_Product_Variation) {
		return $properties_of[$memo_key] = oko_variation_properties($product);
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

	return $properties_of[$memo_key] = $properties;
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

/** When Økoskabet last reached this shop, per kind of call. */
const OKO_INBOUND_SEEN_OPTION = 'okoskabet_inbound_last_seen';

/**
 * Remember that a call from Økoskabet arrived.
 *
 * Two roads run into the shop: the status messages that finish an order, and
 * the product lookup the packing room needs for producer, country of origin
 * and the tags it sorts dispatch notes by. Either can be stopped before
 * WordPress ever sees it — a firewall in front of the site is enough, and at
 * Gaardmester one is. Nothing breaks loudly when that happens: the packing
 * room simply has nothing to sort by, and the shop has no way of telling that
 * from a quiet day.
 *
 * The plugin cannot see a call that never arrives. What it can do is say when
 * one last did, and let the shop draw the conclusion from "never".
 *
 * @param string $kind 'webhook' or 'products'.
 */
function oko_note_inbound_call(string $kind): void
{
	$seen = get_option(OKO_INBOUND_SEEN_OPTION, array());
	$seen = is_array($seen) ? $seen : array();

	// To the minute is plenty for "has anything come through lately", and it
	// keeps a busy shop from writing this option on every single call.
	if (isset($seen[$kind]) && (time() - (int) $seen[$kind]) < MINUTE_IN_SECONDS) {
		return;
	}

	$seen[$kind] = time();

	// Not autoloaded: read on one settings screen, never on the front end.
	update_option(OKO_INBOUND_SEEN_OPTION, $seen, false);
}

/**
 * When Økoskabet last reached this shop with a call of this kind, or null.
 *
 * @param string $kind 'webhook' or 'products'.
 */
function oko_inbound_last_seen(string $kind): ?int
{
	$seen = get_option(OKO_INBOUND_SEEN_OPTION, array());
	$when = is_array($seen) && isset($seen[$kind]) ? (int) $seen[$kind] : 0;

	return $when > 0 ? $when : null;
}

/**
 * "2 hours ago", or "never" — for the shop, not for a log.
 *
 * A shop owner reading the settings page needs one of two answers: something
 * came through recently, or nothing ever has. An exact timestamp invites them
 * to work out the difference themselves.
 */
function oko_inbound_seen_label(?int $when): string
{
	if ($when === null) {
		return __('never', O_TEXTDOMAIN);
	}

	/* translators: %s: human-readable time difference, e.g. "2 hours". */
	return sprintf(__('%s ago', O_TEXTDOMAIN), human_time_diff($when, time()));
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
