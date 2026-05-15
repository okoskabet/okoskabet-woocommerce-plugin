/**
 * Økoskabet WooCommerce Plugin — checkout helpers
 *
 * This file replaces the inline JavaScript that previously lived in
 * functions/functions.php. It contains two independent IIFE modules:
 *
 *   1. Delivery exceptions overlay
 *      Watches the home_delivery / sheds REST responses for an
 *      `exceptions_explanation` payload and replaces Svelte's
 *      "Ingen tilgængelige datoer" placeholder with a per-product
 *      explanation when the cart's products have conflicting rules.
 *
 *   2. Delivery location dropdown / note UI
 *      Renders a per-stop dropdown ("By the front door", "By the stairs",
 *      etc.) and free-text note inside the checkout's order review table
 *      when the customer selects home delivery.
 *
 * Configuration is provided by PHP via three globals:
 *   - window._okoskabet_checkout           (config object)
 *   - window._okoskabet_overlay_strings    (translatable strings)
 *
 * Both globals are emitted by wp_add_inline_script() in PHP so this
 * file remains static and cacheable.
 */

(function () {
	"use strict";

	// =========================================================================
	// Module 1: delivery exceptions overlay
	// =========================================================================

	(function () {
		var STR = window._okoskabet_overlay_strings || {};
		var lastExplanation = null;
		var origFetch = window.fetch;

		window.fetch = function (input, init) {
			var url = typeof input === "string" ? input : (input && input.url) || "";
			var promise = origFetch.apply(this, arguments);
			if (url.indexOf("/wp-json/wp/v2/okoskabet/") !== -1) {
				promise = promise.then(function (resp) {
					try {
						var clone = resp.clone();
						clone.json().then(function (data) {
							try {
								var r = data && data.results;
								if (!r) { return; }
								// home_delivery has results.exceptions_explanation directly,
								// sheds has it at the top level too.
								var exp = r.exceptions_explanation
									|| (data.results && data.results.exceptions_explanation);
								if (exp && exp.has_exceptions) {
									lastExplanation = exp;
									setTimeout(applyExplanation, 50);
								} else if (exp === undefined) {
									// Successful response with dates — clear any old explanation.
									if ((r.delivery_dates && r.delivery_dates.length)
										|| (r.sheds && r.sheds.length)) {
										lastExplanation = null;
										removeExplanation();
									}
								}
							} catch (_) {
								/* swallow JSON parse errors silently */
							}
						}).catch(function () {});
					} catch (_) { /* swallow clone errors silently */ }
					return resp;
				});
			}
			return promise;
		};

		function escapeHtml(s) {
			return String(s).replace(/[&<>"']/g, function (c) {
				return {
					"&": "&amp;", "<": "&lt;", ">": "&gt;",
					"\"": "&quot;", "'": "&#39;"
				}[c];
			});
		}

		function buildExplanationHtml(exp) {
			var html = "";
			html += "<p style=\"font-weight:600;margin:0 0 8px;\">"
				+ escapeHtml(exp.summary) + "</p>";
			html += "<ul style=\"margin:0 0 8px;padding-left:20px;\">";
			for (var i = 0; i < exp.product_rules.length; i++) {
				var pr = exp.product_rules[i];
				if (!pr.rules || !pr.rules.length) { continue; }
				html += "<li style=\"margin-bottom:4px;\"><strong>"
					+ escapeHtml(pr.product_name) + "</strong> — "
					+ escapeHtml(pr.rules.join("; ")) + "</li>";
			}
			html += "</ul>";
			html += "<p style=\"margin:8px 0 0;font-size:0.9em;color:#555;\">"
				+ escapeHtml(STR.helpText || "") + "</p>";
			return html;
		}

		function findPlaceholders() {
			var matches = [];
			var spans = document.querySelectorAll("span");
			var needle = STR.placeholderText || "Ingen tilgængelige datoer.";
			for (var i = 0; i < spans.length; i++) {
				if (spans[i].textContent.trim() === needle) {
					matches.push(spans[i]);
				}
			}
			return matches;
		}

		function applyExplanation() {
			if (!lastExplanation) { return; }
			var spans = findPlaceholders();
			for (var i = 0; i < spans.length; i++) {
				var span = spans[i];
				if (span.dataset.okoExplained === "1") { continue; }
				var div = document.createElement("div");
				div.className = "oko-no-dates-explained";
				div.dataset.okoExplained = "1";
				div.style.cssText = "background:#fff5f5;border:1px solid #f0c0c0;"
					+ "border-left:4px solid #c44;padding:12px 14px;"
					+ "margin:8px 0 16px;border-radius:3px;";
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
		var observer = new MutationObserver(function () {
			if (lastExplanation) { applyExplanation(); }
		});
		document.addEventListener("DOMContentLoaded", function () {
			observer.observe(document.body, { childList: true, subtree: true });
		});
		if (document.readyState !== "loading") {
			observer.observe(document.body, { childList: true, subtree: true });
		}
	}());

	// =========================================================================
	// Module 2: delivery location dropdown / note
	// =========================================================================

	(function () {
		var _cfg1 = window._okoskabet_checkout || {};
		var cfg = _cfg1.deliveryLocation || {};
		var DROPDOWN_ENABLED = cfg.dropdownEnabled !== false;
		var LABEL_DROPDOWN   = cfg.dropdownLabel || "Leveringssted";
		var LABEL_NOTE       = cfg.noteLabel || "Besked til chauff\u00f8ren (valgfrit)";
		var HIDE_WC_NOTE     = cfg.hideWcOrderComments === true;

		// Hide WooCommerce standard order comments field when Økoskabet
		// leveringsinfo is the configured note source — keeps the checkout to
		// a single note input.
		if (HIDE_WC_NOTE) {
			var hideStyle = document.createElement("style");
			hideStyle.textContent = "#order_comments_field, "
				+ ".woocommerce-additional-fields__field-wrapper "
				+ "{ display: none !important; }";
			document.head.appendChild(hideStyle);
		}

		var FIELD_LOCATION_ID = "billing_okoskabet_delivery_location";
		var FIELD_NOTE_ID     = "billing_okoskabet_delivery_note";
		var SELECT_ID         = "okoskabet_location_select";
		var NOTE_ID           = "okoskabet_location_note";
		var WRAPPER_ID        = "okoskabet_location_wrapper";
		var HOME_METHOD       = "hey_okoskabet_shipping_home";
		var ANDET_VALUE       = "__OTHER__";
		var optionsCache      = null;

		function getSelectedShippingMethod() {
			var checked = document.querySelector("input[name='shipping_method[0]']:checked");
			if (checked) { return checked.value; }
			return "";
		}

		function isHomeDelivery() {
			return getSelectedShippingMethod() === HOME_METHOD;
		}

		function removeUI() {
			var el = document.getElementById(WRAPPER_ID);
			if (el) { el.parentNode.removeChild(el); }
		}

		function syncHiddenFields() {
			var lf = document.getElementById(FIELD_LOCATION_ID);
			var nf = document.getElementById(FIELD_NOTE_ID);
			var sl = document.getElementById(SELECT_ID);
			var ni = document.getElementById(NOTE_ID);
			// The free-text note is "active" only when no dropdown exists OR
			// when "Andet" is selected. If the customer typed in the note while
			// "Andet" was selected and then switched to a different dropdown
			// option, the note input keeps its value (so it returns if they
			// switch back) but it must NOT be submitted — otherwise the order
			// ends up with both a location AND a chauffør note, which is
			// confusing for the driver.
			var noteIsActive = !sl || sl.value === ANDET_VALUE;
			if (nf) {
				if (noteIsActive && ni) { nf.value = ni.value || ""; }
				else { nf.value = ""; }
			}
			// Sync location field — but if "Andet" is selected, send empty
			// string (the free-text in the note field is what gets used as
			// the logistics note).
			if (lf) {
				if (sl) {
					if (sl.value === ANDET_VALUE) { lf.value = ""; }
					else { lf.value = sl.value || ""; }
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

			var hasOptions  = !!(options && options.length > 0);
			var showDropdown = DROPDOWN_ENABLED && hasOptions;

			// The free-text note field — hidden by default; shown when "Andet"
			// is chosen or when there is no dropdown at all.
			var noteWrapper = document.createElement("div");
			noteWrapper.style.cssText = "margin-top:8px;";
			var noteInput = document.createElement("input");
			noteInput.type = "text";
			noteInput.id = NOTE_ID;
			noteInput.name = NOTE_ID;
			noteInput.style.cssText = "width:100%;padding:6px;"
				+ "border:1px solid #ccc;border-radius:4px;";
			var nfe = document.getElementById(FIELD_NOTE_ID);
			if (nfe && nfe.value) { noteInput.value = nfe.value; }
			noteInput.addEventListener("input", syncHiddenFields);
			noteWrapper.appendChild(noteInput);

			// The descriptive instruction text — sits above the dropdown so
			// the customer reads it before making a selection. Hidden if
			// admin leaves it empty.
			var instructionEl = document.createElement("div");
			instructionEl.style.cssText = "margin-bottom:8px;font-size:0.9em;line-height:1.3;";
			instructionEl.textContent = LABEL_NOTE;
			if (!LABEL_NOTE) { instructionEl.style.display = "none"; }

			function refreshNoteVisibility() {
				var sel = document.getElementById(SELECT_ID);
				var show = !showDropdown
					|| (sel && sel.value === ANDET_VALUE);
				noteWrapper.style.display = show ? "block" : "none";
			}

			cellContent.appendChild(instructionEl);

			if (showDropdown) {
				var sel = document.createElement("select");
				sel.id = SELECT_ID;
				sel.name = SELECT_ID;
				sel.style.cssText = "width:100%;padding:6px;"
					+ "border:1px solid #ccc;border-radius:4px;";
				options.forEach(function (opt) {
					var el = document.createElement("option");
					var v = opt.label_en || opt.label_da;
					el.value = v || "";
					var loc = (window._okoskabet_checkout || {}).locale || "";
					var useDa = loc.indexOf("da") === 0 && opt.label_da;
					if (useDa) { el.textContent = opt.label_da; }
					else { el.textContent = opt.label_en || opt.label_da || ""; }
					sel.appendChild(el);
				});
				// Append "Andet" / "Other" sentinel option at the end.
				var andetOpt = document.createElement("option");
				andetOpt.value = ANDET_VALUE;
				var loc2 = (window._okoskabet_checkout || {}).locale || "";
				andetOpt.textContent = loc2.indexOf("da") === 0 ? "Andet" : "Other";
				sel.appendChild(andetOpt);
				sel.selectedIndex = 0;
				if (locationField && locationField.value) {
					for (var i = 0; i < sel.options.length; i++) {
						if (sel.options[i].value === locationField.value) {
							sel.selectedIndex = i;
							break;
						}
					}
				}
				sel.addEventListener("change", function () {
					refreshNoteVisibility();
					syncHiddenFields();
				});
				cellContent.appendChild(sel);
			}

			cellContent.appendChild(noteWrapper);
			refreshNoteVisibility();

			// Insert the row inside the order review table — after shipping
			// row, before total.
			var shippingRow = document.querySelector("tr.shipping");
			var totalRow = document.querySelector("tr.order-total");
			if (shippingRow && shippingRow.parentNode) {
				if (shippingRow.nextSibling) {
					shippingRow.parentNode.insertBefore(wrapper, shippingRow.nextSibling);
				} else {
					shippingRow.parentNode.appendChild(wrapper);
				}
			} else if (totalRow && totalRow.parentNode) {
				totalRow.parentNode.insertBefore(wrapper, totalRow);
			} else {
				// Fallback — append wherever
				// woocommerce_review_order_after_shipping puts us.
				var fallback = document.getElementById("order_review");
				if (fallback) { fallback.appendChild(wrapper); }
			}
			syncHiddenFields();
		}

		function fetchAndRender() {
			if (!isHomeDelivery()) { removeUI(); return; }
			if (!DROPDOWN_ENABLED) { buildUI([]); return; }
			if (optionsCache !== null) { buildUI(optionsCache); return; }
			var ep = "";
			if (window._okoskabet_checkout && window._okoskabet_checkout.endpoints) {
				ep = window._okoskabet_checkout.endpoints.deliveryLocationOptions || "";
			}
			if (!ep) { buildUI([]); return; }
			fetch(ep)
				.then(function (r) { return r.json(); })
				.then(function (d) {
					optionsCache = d.options || [];
					buildUI(optionsCache);
				})
				.catch(function () {
					optionsCache = [];
					buildUI([]);
				});
		}

		document.addEventListener("change", function (e) {
			if (e.target && e.target.name === "shipping_method[0]") {
				fetchAndRender();
			}
		});
		if (window.jQuery) {
			jQuery(document.body).on("updated_checkout", function () {
				optionsCache = null;
				fetchAndRender();
			});
			jQuery(document).ready(function () {
				fetchAndRender();
			});
		} else {
			document.addEventListener("DOMContentLoaded", fetchAndRender);
		}
	}());
}());
