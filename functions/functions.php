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

function o_check_configuration(string $value): bool
{
	$transient_key = O_TEXTDOMAIN . '_shipping_methods';
	$shipping_methods = get_transient($transient_key);

	if ($shipping_methods === false) {
		$settings = o_get_settings();

		$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';

		if (!empty($settings['_api_key'])) {
			$response = wp_remote_get($api_url . '/api/v1/configuration', array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => $settings['_api_key'],
				),
			));

			if (is_wp_error($response)) {
				return false;
			}

			$http_code = wp_remote_retrieve_response_code($response);
			$body = wp_remote_retrieve_body($response);

			if ($http_code === 200 && !empty($body)) {
				$oko_configuration = json_decode($body, true);

				$shipping_methods = [];
				if (!empty($oko_configuration['shipping_methods'])) {
					foreach ($oko_configuration['shipping_methods'] as $method) {
						$shipping_methods[$method['method_code']] = $method;
					}
				}

				set_transient($transient_key, $shipping_methods, 5 * MINUTE_IN_SECONDS);
			} else {
				return false;
			}
		}
	}

	if (empty($shipping_methods[$value])) return false;
	return true;
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
	if (empty($settings['_api_key'])) return;
	$shed_description  = !empty($settings['_description_shipping_okoskabet']) ? $settings['_description_shipping_okoskabet'] : 'Afkølet afhentningssted hvor du kan hente dine varer hele døgnet vha. kode.';
	$local_description = !empty($settings['_description_shipping_private'])   ? $settings['_description_shipping_private']   : 'Økoskabet leverer dine varer til døren.';

	$config = wp_json_encode(array(
		'locale'        => get_locale(),
		'displayOption' => $settings['_display_option'] ?? '',
		'descriptions'  => array(
			'homeDelivery' => $local_description,
			'shedDelivery' => $shed_description,
		),
		'deliveryLocation' => array(
			'dropdownEnabled' => !empty($settings['_delivery_location_dropdown']),
			'dropdownLabel'   => !empty($settings['_delivery_location_dropdown_label'])
				? $settings['_delivery_location_dropdown_label']
				: 'Leveringssted',
			'noteLabel' => !empty($settings['_delivery_location_note_label'])
				? $settings['_delivery_location_note_label']
				: "Besked til chauff\u00f8ren (valgfrit)",
			'hideWcOrderComments' => !empty($settings['_hide_wc_order_comments']),
		),
		'endpoints' => array(
			'deliveryLocationOptions' => get_rest_url(null, 'wp/v2/okoskabet/delivery_location_options'),
		),
	));

	// Use wp_print_inline_script_tag — emits a <script> tag without WordPress's & → &#038; escaping.
	if (function_exists('wp_print_inline_script_tag')) {
		wp_print_inline_script_tag('window._okoskabet_checkout = ' . $config . ';');
	} else {
		echo '<script type="text/javascript">window._okoskabet_checkout = ' . $config . ';</script>';
	}

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

	// Overlay script: intercept the home_delivery / sheds REST responses to
	// capture any `exceptions_explanation` returned alongside an empty
	// `delivery_dates`. Then watch the DOM for Svelte's "Ingen tilgængelige
	// datoer" placeholder and replace it with a per-product explanation.
	//
	// Patching the compiled Svelte bundle directly is risky (minified code
	// is brittle). This MutationObserver-based approach keeps the bundle
	// untouched and remains effective across re-renders.
	$overlay_js = '(function(){
		var lastExplanation = null;
		var origFetch = window.fetch;
		window.fetch = function(input, init) {
			var url = typeof input === "string" ? input : (input && input.url) || "";
			var promise = origFetch.apply(this, arguments);
			if (url.indexOf("/wp-json/wp/v2/okoskabet/") !== -1) {
				promise = promise.then(function(resp){
					try {
						var clone = resp.clone();
						clone.json().then(function(data){
							try {
								var r = data && data.results;
								if (!r) return;
								// home_delivery has results.exceptions_explanation directly,
								// sheds has it at the top level too.
								var exp = r.exceptions_explanation || (data.results && data.results.exceptions_explanation);
								if (exp && exp.has_exceptions) {
									lastExplanation = exp;
									setTimeout(applyExplanation, 50);
								} else if (exp === undefined) {
									// Successful response with dates → clear any old explanation.
									if ((r.delivery_dates && r.delivery_dates.length) || (r.sheds && r.sheds.length)) {
										lastExplanation = null;
										removeExplanation();
									}
								}
							} catch(_){}
						}).catch(function(){});
					} catch(_){}
					return resp;
				});
			}
			return promise;
		};

		function escapeHtml(s) {
			return String(s).replace(/[&<>"\']/g, function(c){
				return {"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\'":"&#39;"}[c];
			});
		}

		function buildExplanationHtml(exp) {
			var html = "";
			html += "<p style=\"font-weight:600;margin:0 0 8px;\">" + escapeHtml(exp.summary) + "</p>";
			html += "<ul style=\"margin:0 0 8px;padding-left:20px;\">";
			for (var i = 0; i < exp.product_rules.length; i++) {
				var pr = exp.product_rules[i];
				if (!pr.rules || !pr.rules.length) continue;
				html += "<li style=\"margin-bottom:4px;\"><strong>" + escapeHtml(pr.product_name) + "</strong> — " + escapeHtml(pr.rules.join("; ")) + "</li>";
			}
			html += "</ul>";
			html += "<p style=\"margin:8px 0 0;font-size:0.9em;color:#555;\">Du kan fjerne en eller flere af de markerede varer fra kurven for at få flere leveringsmuligheder, eller kontakte os for hjælp.</p>";
			return html;
		}

		function findPlaceholders() {
			var matches = [];
			var spans = document.querySelectorAll("span");
			for (var i = 0; i < spans.length; i++) {
				if (spans[i].textContent.trim() === "Ingen tilgængelige datoer.") {
					matches.push(spans[i]);
				}
			}
			return matches;
		}

		function applyExplanation() {
			if (!lastExplanation) return;
			var spans = findPlaceholders();
			for (var i = 0; i < spans.length; i++) {
				var span = spans[i];
				if (span.dataset.okoExplained === "1") continue;
				var div = document.createElement("div");
				div.className = "oko-no-dates-explained";
				div.dataset.okoExplained = "1";
				div.style.cssText = "background:#fff5f5;border:1px solid #f0c0c0;border-left:4px solid #c44;padding:12px 14px;margin:8px 0 16px;border-radius:3px;";
				div.innerHTML = buildExplanationHtml(lastExplanation);
				span.parentNode.replaceChild(div, span);
			}
		}

		function removeExplanation() {
			var nodes = document.querySelectorAll(".oko-no-dates-explained");
			for (var i = 0; i < nodes.length; i++) {
				nodes[i].parentNode.removeChild(nodes[i]);
			}
		}

		// Watch for Svelte (re)renders so we can re-apply if the placeholder
		// reappears after a checkout update.
		var observer = new MutationObserver(function(){
			if (lastExplanation) applyExplanation();
		});
		document.addEventListener("DOMContentLoaded", function(){
			observer.observe(document.body, { childList: true, subtree: true });
		});
		if (document.readyState !== "loading") {
			observer.observe(document.body, { childList: true, subtree: true });
		}
	})();';

	if (function_exists('wp_print_inline_script_tag')) {
		wp_print_inline_script_tag($overlay_js);
	} else {
		echo '<script type="text/javascript">' . $overlay_js . '</script>';
	}

	// CSS: hide WooCommerce-rendered billing input fields — our JS injects
	// the visible UI dynamically. We hide only the labels and inputs, not the wrapper,
	// so our injected UI inside the wrapper remains visible.
	echo '<style>
		.okoskabet-delivery-location > label,
		.okoskabet-delivery-location > .woocommerce-input-wrapper > input,
		.okoskabet-delivery-note > label,
		.okoskabet-delivery-note > .woocommerce-input-wrapper > input {
			display: none !important;
		}
	</style>';

	// Main JS — written without && or || operators to avoid WordPress's
	// HTML entity escaping (which converts & to &#038; in some output contexts).
	$js = '(function() {
	function and2(a, b) { if (a) { return b; } return a; }
	function or2(a, b) { if (a) { return a; } return b; }

	var _cfg1 = or2(window._okoskabet_checkout, {});
	var cfg = or2(_cfg1.deliveryLocation, {});
	var DROPDOWN_ENABLED  = cfg.dropdownEnabled !== false;
	var LABEL_DROPDOWN    = or2(cfg.dropdownLabel, "Leveringssted");
	var LABEL_NOTE        = or2(cfg.noteLabel, "Besked til chauff\u00f8ren (valgfrit)");
	var HIDE_WC_NOTE      = cfg.hideWcOrderComments === true;

	// Hide WooCommerce standard order comments field when Økoskabet leveringsinfo
	// is the configured note source — keeps the checkout to a single note input.
	if (HIDE_WC_NOTE) {
		var hideStyle = document.createElement("style");
		hideStyle.textContent = "#order_comments_field, .woocommerce-additional-fields__field-wrapper { display: none !important; }";
		document.head.appendChild(hideStyle);
	}
	var FIELD_LOCATION_ID = "billing_okoskabet_delivery_location";
	var FIELD_NOTE_ID     = "billing_okoskabet_delivery_note";
	var SELECT_ID         = "okoskabet_location_select";
	var NOTE_ID           = "okoskabet_location_note";
	var WRAPPER_ID        = "okoskabet_location_wrapper";
	var HOME_METHOD       = "hey_okoskabet_shipping_home";
	var optionsCache      = null;

	function getSelectedShippingMethod() {
		var checked = document.querySelector("input[name=\'shipping_method[0]\']:checked");
		if (checked) { return checked.value; }
		return "";
	}
	function isHomeDelivery() { return getSelectedShippingMethod() === HOME_METHOD; }
	function removeUI() { var el = document.getElementById(WRAPPER_ID); if (el) { el.parentNode.removeChild(el); } }

	function syncHiddenFields() {
		var lf = document.getElementById(FIELD_LOCATION_ID);
		var nf = document.getElementById(FIELD_NOTE_ID);
		var sl = document.getElementById(SELECT_ID);
		var ni = document.getElementById(NOTE_ID);
		// The free-text note is "active" only when no dropdown exists OR when "Andet"
		// is selected. If the customer typed in the note while "Andet" was selected
		// and then switched to a different dropdown option, the note input keeps its
		// value (so it returns if they switch back) but it must NOT be submitted —
		// otherwise the order ends up with both a location AND a chauffør note,
		// which is confusing for the driver.
		var noteIsActive = !sl || sl.value === "__OTHER__";
		if (nf) {
			if (noteIsActive && ni) { nf.value = or2(ni.value, ""); }
			else { nf.value = ""; }
		}
		// Sync location field — but if "Andet" is selected, send empty string
		// (the free-text in the note field is what gets used as the logistics note).
		if (lf) {
			if (sl) {
				if (sl.value === "__OTHER__") { lf.value = ""; }
				else { lf.value = or2(sl.value, ""); }
			} else {
				// No dropdown — location stays empty, only note is used.
				lf.value = "";
			}
		}
	}

	function buildUI(options) {
		removeUI();
		if (!isHomeDelivery()) { return; }
		var locationField = document.getElementById(FIELD_LOCATION_ID);

		// Render as a table row inside the order review table.
		var wrapper = document.createElement("tr");
		wrapper.id = WRAPPER_ID;
		wrapper.className = "okoskabet-location-row";
		var cellLabel = document.createElement("th");
		cellLabel.textContent = LABEL_DROPDOWN;
		var cellContent = document.createElement("td");
		wrapper.appendChild(cellLabel);
		wrapper.appendChild(cellContent);

		var hasOptions = false;
		if (options) { if (options.length > 0) { hasOptions = true; } }
		var showDropdown = false;
		if (DROPDOWN_ENABLED) { if (hasOptions) { showDropdown = true; } }

		// The free-text note field — hidden by default; shown when "Andet" is chosen
		// or when there is no dropdown at all.
		var noteWrapper = document.createElement("div");
		noteWrapper.style.cssText = "margin-top:8px;";
		var noteInput = document.createElement("input");
		noteInput.type = "text"; noteInput.id = NOTE_ID; noteInput.name = NOTE_ID;
		noteInput.style.cssText = "width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;";
		var nfe = document.getElementById(FIELD_NOTE_ID);
		if (nfe) { if (nfe.value) { noteInput.value = nfe.value; } }
		noteInput.addEventListener("input", syncHiddenFields);
		noteWrapper.appendChild(noteInput);

		// The descriptive instruction text — sits above the dropdown so the customer
		// reads it before making a selection. Hidden if admin leaves it empty.
		var instructionEl = document.createElement("div");
		instructionEl.style.cssText = "margin-bottom:8px;font-size:0.9em;line-height:1.3;";
		instructionEl.textContent = LABEL_NOTE;
		if (!LABEL_NOTE) { instructionEl.style.display = "none"; }

		// "Andet" sentinel value — does not get sent to API; instead the free-text is.
		var ANDET_VALUE = "__OTHER__";

		function refreshNoteVisibility() {
			var sel = document.getElementById(SELECT_ID);
			var show = false;
			if (!showDropdown) { show = true; } // no dropdown → always show
			else { if (sel) { if (sel.value === ANDET_VALUE) { show = true; } } }
			noteWrapper.style.display = show ? "block" : "none";
		}

		// Insert the instruction text first — sits above the dropdown.
		cellContent.appendChild(instructionEl);

		if (showDropdown) {
			var sel = document.createElement("select");
			sel.id = SELECT_ID; sel.name = SELECT_ID;
			sel.style.cssText = "width:100%;padding:6px;border:1px solid #ccc;border-radius:4px;";
			options.forEach(function(opt) {
				var el = document.createElement("option");
				var v = or2(opt.label_en, opt.label_da);
				el.value = or2(v, "");
				var loc = or2(or2(window._okoskabet_checkout, {}).locale, "");
				var useDa = false;
				if (loc.indexOf("da") === 0) { if (opt.label_da) { useDa = true; } }
				if (useDa) { el.textContent = opt.label_da; }
				else { el.textContent = or2(opt.label_en, or2(opt.label_da, "")); }
				sel.appendChild(el);
			});
			// Append "Andet" option at the end.
			var andetOpt = document.createElement("option");
			andetOpt.value = ANDET_VALUE;
			var loc2 = or2(or2(window._okoskabet_checkout, {}).locale, "");
			if (loc2.indexOf("da") === 0) { andetOpt.textContent = "Andet"; }
			else { andetOpt.textContent = "Other"; }
			sel.appendChild(andetOpt);
			sel.selectedIndex = 0;
			if (locationField) {
				if (locationField.value) {
					for (var i = 0; i < sel.options.length; i++) {
						if (sel.options[i].value === locationField.value) { sel.selectedIndex = i; break; }
					}
				}
			}
			sel.addEventListener("change", function() {
				refreshNoteVisibility();
				syncHiddenFields();
			});
			cellContent.appendChild(sel);
		}

		cellContent.appendChild(noteWrapper);
		refreshNoteVisibility();

		// Insert the row inside the order review table — after shipping row, before total.
		var shippingRow = document.querySelector("tr.shipping");
		var totalRow = document.querySelector("tr.order-total");
		if (shippingRow) {
			if (shippingRow.parentNode) {
				if (shippingRow.nextSibling) {
					shippingRow.parentNode.insertBefore(wrapper, shippingRow.nextSibling);
				} else {
					shippingRow.parentNode.appendChild(wrapper);
				}
			}
		} else {
			if (totalRow) {
				if (totalRow.parentNode) { totalRow.parentNode.insertBefore(wrapper, totalRow); }
			} else {
				// Fallback — append wherever woocommerce_review_order_after_shipping puts us.
				var fallback = document.getElementById("order_review");
				if (fallback) { fallback.appendChild(wrapper); }
			}
		}
		syncHiddenFields();
	}

	function fetchAndRender() {
		if (!isHomeDelivery()) { removeUI(); return; }
		if (!DROPDOWN_ENABLED) { buildUI([]); return; }
		if (optionsCache !== null) { buildUI(optionsCache); return; }
		var ep = "";
		if (window._okoskabet_checkout) {
			if (window._okoskabet_checkout.endpoints) {
				ep = or2(window._okoskabet_checkout.endpoints.deliveryLocationOptions, "");
			}
		}
		if (!ep) { buildUI([]); return; }
		fetch(ep)
			.then(function(r) { return r.json(); })
			.then(function(d) { optionsCache = or2(d.options, []); buildUI(optionsCache); })
			.catch(function() { optionsCache = []; buildUI([]); });
	}

	document.addEventListener("change", function(e) {
		if (e.target) { if (e.target.name === "shipping_method[0]") { fetchAndRender(); } }
	});
	jQuery(document.body).on("updated_checkout", function() { optionsCache = null; fetchAndRender(); });
	jQuery(document).ready(function() { fetchAndRender(); });
}());';

	if (function_exists('wp_print_inline_script_tag')) {
		wp_print_inline_script_tag($js);
	} else {
		echo '<script type="text/javascript">' . $js . '</script>';
	}
}



add_filter('woocommerce_shipping_methods', 'hey_register_okoskabet_shipping_shed_method');
function hey_register_okoskabet_shipping_shed_method(array $methods): array
{
	if (empty(o_check_configuration('shed'))) return $methods;
	$methods['hey_okoskabet_shipping_shed'] = 'WC_Hey_Okoskabet_Shipping_Method_Shed';
	return $methods;
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
				$this->instance_form_fields = array(
					'title' => array(
						'title'       => esc_html__('Method Title', O_TEXTDOMAIN),
						'type'        => 'text',
						'description' => esc_html__('Enter the method title', O_TEXTDOMAIN),
						'default'     => $this->method_title,
						'desc_tip'    => true,
					),
					'description' => array(
						'title'       => esc_html__('Description', O_TEXTDOMAIN),
						'type'        => 'textarea',
						'description' => esc_html__('Enter the Description', O_TEXTDOMAIN),
						'default'     => '',
						'desc_tip'    => true
					),
					'cost' => array(
						'title'       => esc_html__('Shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the default shipping price', O_TEXTDOMAIN),
						'default'     => '49',
						'desc_tip'    => true
					),
					'costDiscountLimit' => array(
						'title'       => esc_html__('Discounted shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted shipping total order minimum', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
					'costDiscount' => array(
						'title'       => esc_html__('Discounted shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted price', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
					'costFreeLimit' => array(
						'title'       => esc_html__('Free shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the free shipping total order minimum', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
				);

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
				$this->instance_form_fields = array(
					'title' => array(
						'title'       => esc_html__('Method Title', O_TEXTDOMAIN),
						'type'        => 'text',
						'description' => esc_html__('Enter the method title', O_TEXTDOMAIN),
						'default'     => $this->method_title,
						'desc_tip'    => true,
					),
					'description' => array(
						'title'       => esc_html__('Description', O_TEXTDOMAIN),
						'type'        => 'textarea',
						'description' => esc_html__('Enter the Description', O_TEXTDOMAIN),
						'default'     => '',
						'desc_tip'    => true
					),
					'cost' => array(
						'title'       => esc_html__('Shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the default shipping price', O_TEXTDOMAIN),
						'default'     => '49',
						'desc_tip'    => true
					),
					'costDiscountLimit' => array(
						'title'       => esc_html__('Discounted shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted shipping total order minimum', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
					'costDiscount' => array(
						'title'       => esc_html__('Discounted shipping price', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the discounted price', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
					'costFreeLimit' => array(
						'title'       => esc_html__('Free shipping order minimum', O_TEXTDOMAIN),
						'type'        => 'number',
						'description' => esc_html__('Add the free shipping total order minimum', O_TEXTDOMAIN),
						'default'     => '0',
						'desc_tip'    => true
					),
				);

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

add_filter('woocommerce_checkout_fields', 'custom_override_checkout_fields');

function custom_override_checkout_fields(array $fields): array
{
	$fields['billing']['billing_okoskabet_shed_id'] = array(
		'label'       => __('Økoskabet ID', 'woocommerce'),
		'placeholder' => '',
		'required'    => false,
		'class'       => array('okoskabet-shed-id form-row-wide'),
		'clear'       => true
	);

	$fields['billing']['billing_okoskabet_delivery_date'] = array(
		'label'       => __('Økoskabet Delivery Date', 'woocommerce'),
		'placeholder' => '',
		'required'    => false,
		'class'       => array('okoskabet-delivery-date form-row-wide'),
		'clear'       => true
	);

	$fields['billing']['billing_okoskabet_delivery_location'] = array(
		'label'       => __('Leveringssted', 'woocommerce'),
		'placeholder' => '',
		'required'    => false,
		'class'       => array('okoskabet-delivery-location form-row-wide'),
		'clear'       => true,
	);

	$fields['billing']['billing_okoskabet_delivery_note'] = array(
		'label'       => __('Besked til chaufføren (valgfrit)', 'woocommerce'),
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
	echo '<pre>';
	if (!empty($order_done)) {
		echo 'Økoskabet Done' . ': ' . esc_html($order_done) . "\n";
	}
	if (!empty($shed_id)) {
		echo 'Økoskabet SHED ID' . ': ' . esc_html($shed_id) . "\n";
	}
	if (!empty($delivery_date)) {
		echo 'Økoskabet Delivery Date' . ': ' . esc_html($delivery_date) . "\n";
	}
	if (!empty($delivery_location)) {
		echo 'Økoskabet Leveringssted' . ': ' . esc_html($delivery_location) . "\n";
	}
	if (!empty($delivery_note)) {
		echo 'Besked til chauffør' . ': ' . esc_html($delivery_note);
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

	$settings = o_get_settings();
	$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';

	if (empty($settings['_api_key'])) {
		error_log("okoskabet_woocommerce_plugin: API key not set");
		return;
	}

	if ($new_status === 'cancelled') {
		$url = $api_url . '/api/v1/shipments/' . $order_number;

		$response = wp_remote_request($url, array(
			'method'  => 'DELETE',
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => $settings['_api_key'],
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

		$is_shed_delivery = false;
		foreach ($order->get_shipping_methods() as $shipping_method) {
			if ($shipping_method->get_method_id() === 'hey_okoskabet_shipping_shed') {
				$is_shed_delivery = true;
				break;
			}
		}

		if (!$is_shed_delivery) {
			$order_shed = '';
		}

		$url = $api_url . '/api/v1/shipments/';

		$data = !empty($order_shed) ? [
			'locale' => get_locale(),
			'allow_invalid' => true,
			'shipment_reference' => (string) $order_number,
			'customer' => [
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'phone' => $order->get_billing_phone(),
				'email' => $order->get_billing_email(),
			],
			'notes' => (string) $order->get_customer_note(),
			'delivery_date' => $order_delivery_date,
			'reservation' => [
				'shed_id' => $order_shed,
				'max_duration_days' => 1,
			]
		] : [
			'locale' => get_locale(),
			'allow_invalid' => true,
			'shipment_reference' => (string) $order_number,
			'customer' => [
				'first_name' => $order->get_billing_first_name(),
				'last_name' => $order->get_billing_last_name(),
				'recipient_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
				'phone' => $order->get_billing_phone(),
				'email' => $order->get_billing_email(),
			],
			'home_delivery' => array_filter([
				'recipient_name' => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
				'address_1' => $order->get_shipping_address_1(),
				'address_2' => $order->get_shipping_address_2(),
				'city' => $order->get_shipping_city(),
				'postal_code' => $order->get_shipping_postcode(),
				// Combined English location + free-text note, or null if empty.
				'location' => !empty($logistics_note) ? $logistics_note : null,
			]),
			// Send the logistics note (dropdown selection + free-text) as the API
			// notes field so it appears in Økoskabet's Notes column. Falls back to
			// the standard WooCommerce customer note when no logistics note is set.
			'notes' => !empty($logistics_note) ? $logistics_note : (string) $order->get_customer_note(),
			'delivery_date' => $order_delivery_date,
		];

		$response = wp_remote_post($url, array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => $settings['_api_key'],
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
		if (empty($order_shed)) {
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
		$_POST['billing_okoskabet_shed_id'] = '';
	}
}
