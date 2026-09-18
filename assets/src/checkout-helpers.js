/**
 * Økoskabet WooCommerce Plugin — checkout helpers
 *
 * This file replaces the inline JavaScript that previously lived in
 * functions/functions.php. It contains three independent IIFE modules:
 *
 *   1. Delivery exceptions overlay
 *      Watches the home_delivery / sheds REST responses for an
 *      `exceptions_explanation` payload and replaces Svelte's
 *      "Ingen tilgængelige datoer" placeholder with a per-product
 *      explanation when the cart's products have conflicting rules.
 *
 *   3. Store pickup UI
 *      Renders the pickup location and pickup date the customer collects
 *      on, when Butiksafhentning is the selected shipping method.
 *
 *   2. Delivery location dropdown / note UI
 *      Renders a per-stop dropdown ("By the front door", "By the stairs",
 *      etc.) and free-text note inside the checkout's order review table
 *      when the customer selects home delivery.
 *
 * Configuration is provided by PHP via three globals:
 *   - window._okoskabet_checkout           (config object)
 *   - window._okoskabet_overlay_strings    (translatable strings)
 *   - window._okoskabet_pickup_strings     (store-pickup strings)
 *
 * Both globals are emitted by wp_add_inline_script() in PHP so this
 * file remains static and cacheable.
 */

(function () {
	"use strict";

	// =========================================================================
	// Where the delivery rows go — and what shape they have to be
	// =========================================================================

	// Both modules below add rows to the checkout, and both used to assume
	// WooCommerce's review-order table: they built <tr>/<th>/<td> and looked
	// for tr.shipping or .woocommerce-shipping-totals to sit beside. A
	// checkout that renders its own markup — a page builder's, or the block
	// checkout — has none of that, so the rows were built and then dropped on
	// the floor by the HTML parser, because a <tr> outside a table is not
	// allowed to exist.
	//
	// okoAnchor() answers both questions at once: where to insert, and whether
	// we are inside a table. The classic selectors are tried first and in the
	// order they were tried before, so a shop on WooCommerce's own templates
	// gets the same node it has always got and never reaches the rest of this
	// function.
	function okoAnchor() {
		var shippingRow =
			document.querySelector("tr.shipping") ||
			document.querySelector(".woocommerce-shipping-totals");
		if (shippingRow && shippingRow.parentNode) {
			return { node: shippingRow, mode: "table", how: "after" };
		}

		var totalRow = document.querySelector("tr.order-total");
		if (totalRow && totalRow.parentNode) {
			return { node: totalRow, mode: "table", how: "before" };
		}

		var review = document.getElementById("order_review");
		if (review) {
			return { node: review, mode: "table", how: "append" };
		}

		// Past here we are not in a review-order table at all.

		// A mount the shop placed itself, via [okoskabet_levering].
		var mount = document.querySelector(".okoskabet-delivery-mount");
		if (mount) {
			return { node: mount, mode: "block", how: "append" };
		}

		// Otherwise sit under whatever holds the shipping choices.
		var group = okoShippingGroup();
		if (group) {
			return { node: group, mode: "block", how: "after" };
		}

		return null;
	}

	// The smallest element that contains every shipping choice.
	//
	// Guessing at the wrapper's tag does not survive contact with a builder:
	// Bricks nests the radio in label > div > div > div and there is no ul,
	// fieldset or table anywhere above it, so a tag guess lands on the label
	// and the delivery rows end up wedged between two shipping methods.
	//
	// Climbing until one node holds them all finds the list itself, whatever
	// it is built from, and the rows land after the whole list where they
	// read as the next decision rather than as part of one of the options.
	function okoShippingGroup() {
		var radios = document.querySelectorAll(
			"input[name='shipping_method[0]']"
		);
		if (!radios.length) {
			return null;
		}

		var node = radios[0].parentElement;
		while (node && node !== document.body) {
			var holdsAll = true;
			for (var i = 0; i < radios.length; i++) {
				if (!node.contains(radios[i])) {
					holdsAll = false;
					break;
				}
			}
			if (holdsAll) {
				// One method means the "group" is that method's own line, which
				// is too tight to sit after. One step out gives the rows a home
				// that is not inside the option itself.
				return radios.length === 1 && node.parentElement
					? node.parentElement
					: node;
			}
			node = node.parentElement;
		}

		return null;
	}

	// A row and its two cells, in whichever shape the anchor calls for.
	function okoRow(mode) {
		return document.createElement(mode === "table" ? "tr" : "div");
	}
	function okoLabelCell(mode) {
		return document.createElement(mode === "table" ? "th" : "div");
	}
	function okoContentCell(mode) {
		return document.createElement(mode === "table" ? "td" : "div");
	}

	// Put an element where the anchor says, however that anchor wants it.
	function okoPlace(anchor, element) {
		if (!anchor || !anchor.node) {
			return false;
		}

		if (anchor.how === "append") {
			anchor.node.appendChild(element);
			return true;
		}

		if (!anchor.node.parentNode) {
			return false;
		}

		if (anchor.how === "before") {
			anchor.node.parentNode.insertBefore(element, anchor.node);
			return true;
		}

		if (anchor.node.nextSibling) {
			anchor.node.parentNode.insertBefore(element, anchor.node.nextSibling);
		} else {
			anchor.node.parentNode.appendChild(element);
		}
		return true;
	}

	// =========================================================================
	// The fields the booking travels in
	// =========================================================================

	// The customer's choice of locker, date and pickup place is written into
	// hidden billing fields, and WooCommerce saves whatever arrives under a
	// registered field name onto the order. The plugin registers these through
	// woocommerce_checkout_fields — enough for WooCommerce's own checkout,
	// because that renders every registered field.
	//
	// A builder's checkout does not. Bricks' Checkout v2 draws each form field
	// as its own element, so fields a plugin adds never reach the form. The
	// pickers still work and still write — jQuery's .val() and a null
	// getElementById both fail without a sound — and the order goes through
	// with no locker, no date and no pickup place. Nothing is booked with
	// Økoskabet, and nothing says so.
	//
	// So make sure the fields exist inside the form. Only missing ones are
	// added, so a checkout that already renders them is left exactly as it is.
	// billing_okoskabet_done is deliberately absent: the plugin sets that on
	// the order itself, after the fact, and it must never arrive from a form.
	var OKO_BOOKING_FIELDS = [
		"billing_okoskabet_shed_id",
		"billing_okoskabet_delivery_date",
		"billing_okoskabet_pickup_location_id",
		"billing_okoskabet_delivery_location",
		"billing_okoskabet_delivery_note",
		"billing_okoskabet_pre_order"
	];

	function okoEnsureBookingFields() {
		var form = document.querySelector("form.checkout, form[name='checkout']");
		if (!form) {
			return;
		}

		OKO_BOOKING_FIELDS.forEach(function (name) {
			if (form.querySelector("[name='" + name + "']")) {
				return;
			}
			var input = document.createElement("input");
			input.type = "hidden";
			input.name = name;
			input.id = name;
			input.className = "okoskabet-booking-field";
			// On the form itself rather than inside a step, so a builder
			// re-rendering its steps cannot take the fields with it.
			form.appendChild(input);
		});
	}

	okoEnsureBookingFields();
	document.addEventListener("DOMContentLoaded", okoEnsureBookingFields);
	if (window.jQuery) {
		window.jQuery(document.body).on("updated_checkout", okoEnsureBookingFields);
	}

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

		function escapeHtml(s) {
			return String(s == null ? "" : s)
				.replace(/&/g, "&amp;")
				.replace(/</g, "&lt;")
				.replace(/>/g, "&gt;")
				.replace(/"/g, "&quot;");
		}

		// Generic fallback for when the customer sees the empty-dates
		// placeholder but no Delivery_Exceptions explanation is active —
		// typically because the merchant's date window is too narrow or
		// the API genuinely returned nothing. Replace the bare placeholder
		// with a "please contact the shop" message rather than leaving it
		// as an inscrutable "no dates available." line.
		function applyFallback() {
			if (lastExplanation) { return; }
			var heading = STR.noDatesHeading || "No delivery dates available right now";
			var body = STR.noDatesBody || "We can't find a delivery date for the products in your cart. Please contact the shop for help.";
			var spans = findPlaceholders();
			for (var i = 0; i < spans.length; i++) {
				var span = spans[i];
				if (span.dataset.okoFallback === "1") { continue; }
				var div = document.createElement("div");
				div.className = "oko-no-dates-fallback";
				div.dataset.okoFallback = "1";
				div.style.cssText = "background:#fff5f5;border:1px solid #f0c0c0;"
					+ "border-left:4px solid #c44;padding:12px 14px;"
					+ "margin:8px 0 16px;border-radius:3px;";
				div.innerHTML = "<strong>" + escapeHtml(heading) + "</strong>"
					+ "<p style=\"margin:6px 0 0;\">" + escapeHtml(body) + "</p>";
				span.parentNode.replaceChild(div, span);
			}
		}

		function removeExplanation() {
			var nodes = document.querySelectorAll(".oko-no-dates-explained, .oko-no-dates-fallback");
			for (var i = 0; i < nodes.length; i++) {
				nodes[i].parentNode.removeChild(nodes[i]);
			}
		}

		// Watch for Svelte (re)renders so we can re-apply if the placeholder
		// reappears after a checkout update. Either an explanation kicks in
		// (Delivery_Exceptions hit) or we surface the generic contact-shop
		// fallback so the customer is never stranded on a bare "no dates."
		var observer = new MutationObserver(function () {
			if (lastExplanation) {
				applyExplanation();
			} else {
				applyFallback();
			}
		});
		function reapply() {
			if (lastExplanation) {
				applyExplanation();
			} else {
				applyFallback();
			}
		}
		document.addEventListener("DOMContentLoaded", function () {
			observer.observe(document.body, { childList: true, subtree: true });
			// Catch placeholders that were already in the DOM before our
			// observer started — MutationObserver only fires for changes,
			// not for the initial state.
			reapply();
		});
		if (document.readyState !== "loading") {
			observer.observe(document.body, { childList: true, subtree: true });
			reapply();
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

			// Shaped for wherever it is going: a table row inside the review
			// table, a plain block anywhere else.
			var anchor = okoAnchor();
			var mode = anchor ? anchor.mode : "table";

			var wrapper = okoRow(mode);
			wrapper.id = WRAPPER_ID;
			wrapper.className = "okoskabet-location-row";
			var cellLabel = okoLabelCell(mode);
			cellLabel.textContent = LABEL_DROPDOWN;
			var cellContent = okoContentCell(mode);
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
				} else if (nfe && nfe.value) {
					// "Andet" leaves the location empty and keeps the text in
					// the note. This row is rebuilt on every recalculation of
					// the checkout, and without this it came back on the first
					// option — which then threw the customer's note away and
					// sent "In front of the door" instead.
					sel.value = ANDET_VALUE;
				}
				sel.addEventListener("change", function () {
					refreshNoteVisibility();
					syncHiddenFields();
				});
				cellContent.appendChild(sel);
			}

			cellContent.appendChild(noteWrapper);

			// Next to the shipping choice, wherever that turned out to be.
			// okoAnchor() tries the review-order table first, so on a classic
			// checkout this lands exactly where it always did.
			okoPlace(anchor, wrapper);
			// Only once the row is in the page: refreshNoteVisibility() looks
			// the select up by id, and before this it found nothing — so a
			// restored "Andet" came back with its note box hidden.
			refreshNoteVisibility();
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

	// =========================================================================
	// Module 3: store pickup (Butiksafhentning)
	// =========================================================================
	//
	// A store pickup goes nowhere: the shop keeps the goods until the customer
	// collects them. So this method needs neither an address nor a shed — it
	// needs the place to collect from and the day to collect on. Both are
	// rendered here, into the same order-review table the other Økoskabet UI
	// uses, and written into hidden billing fields that PHP reads when it
	// creates the shipment.
	//
	// The dates come from the plugin's own REST proxy, which has already run
	// them through the merchant's per-product cutoff rules — so a collection
	// obeys the same cutoffs a delivery does.

	(function () {
		var PICKUP_METHOD    = "hey_okoskabet_shipping_store_pickup";
		var FIELD_LOCATION   = "billing_okoskabet_pickup_location_id";
		var FIELD_DATE       = "billing_okoskabet_delivery_date";
		var WRAPPER_ID       = "okoskabet_pickup_wrapper";
		var SELECT_PLACE_ID  = "okoskabet_pickup_place";
		var SELECT_DATE_ID   = "okoskabet_pickup_date";
		var locationsCache   = null;
		var locationsCacheKey = null;
		var inFlight         = false;
		var chosenLocationId = "";

		function strings() {
			var s = window._okoskabet_pickup_strings || {};
			return {
				place:    s.place    || "Afhentningssted",
				date:     s.date     || "Afhentningsdato",
				choose:   s.choose   || "\u2014 v\u00e6lg \u2014",
				noPlaces: s.noPlaces || "Ingen afhentningssteder er sat op. Kontakt butikken.",
				noDates:  s.noDates  || "Ingen afhentningsdatoer er ledige lige nu. Kontakt butikken."
			};
		}

		function selectedMethod() {
			var checked = document.querySelector("input[name='shipping_method[0]']:checked");
			if (checked) { return checked.value; }
			// A zone with a single method renders a hidden input rather than a radio.
			var only = document.querySelector("input[name='shipping_method[0]']");
			return only ? only.value : "";
		}

		function isStorePickup() { return selectedMethod() === PICKUP_METHOD; }

		// Matched on the class alone. The rows are <tr> in a review-order
		// table and <div> anywhere else, and both have to be cleared.
		function removeUI() {
			var rows = document.querySelectorAll("." + WRAPPER_ID);
			Array.prototype.forEach.call(rows, function (r) {
				if (r.parentNode) { r.parentNode.removeChild(r); }
			});
		}

		function syncHiddenFields() {
			var lf = document.getElementById(FIELD_LOCATION);
			var df = document.getElementById(FIELD_DATE);
			var ls = document.getElementById(SELECT_PLACE_ID);
			var ds = document.getElementById(SELECT_DATE_ID);
			// With one shop there is no dropdown to read, so the id is held
			// here instead.
			if (lf) { lf.value = ls ? (ls.value || "") : chosenLocationId; }
			if (df && ds) { df.value = ds.value || ""; }
		}

		function formatDate(iso) {
			var loc = (window._okoskabet_checkout || {}).locale || "da-DK";
			loc = String(loc).replace("_", "-");
			try {
				// Same shape as assets/src/format-date.ts — parse as UTC and
				// format in UTC, so pickup dates read identically to the shed
				// and home-delivery dates the Svelte checkout renders.
				var out = new Date(iso).toLocaleDateString(loc, {
					weekday: "long", day: "numeric", month: "long", year: "numeric",
					timeZone: "UTC"
				});
				return out.charAt(0).toUpperCase() + out.slice(1);
			} catch (e) { return iso; }
		}

		function addressLine(loc) {
			var a = loc && loc.address ? loc.address : {};
			var parts = [];
			if (a.address)     { parts.push(a.address); }
			if (a.postal_code) { parts.push(a.postal_code); }
			if (a.city)        { parts.push(a.city); }
			return parts.join(", ");
		}

		// Set by buildUI() before it builds any rows, so every row in one
		// pass comes out the same shape as the place it is going.
		var placementMode = "table";

		function row(labelText, contentNode) {
			var tr = okoRow(placementMode);
			tr.className = WRAPPER_ID;
			var th = okoLabelCell(placementMode);
			th.textContent = labelText;
			var td = okoContentCell(placementMode);
			td.appendChild(contentNode);
			tr.appendChild(th);
			tr.appendChild(td);
			return tr;
		}

		function buildUI(locations) {
			removeUI();
			if (!isStorePickup()) { return; }
			var t = strings();

			// Decided once, before a single row is built: both the shape of
			// the rows and where they end up come from the same answer.
			var anchor = okoAnchor();
			placementMode = anchor ? anchor.mode : "table";

			// Two <tr> rows, appended next to the shipping row. A <tbody>
			// inserted as a sibling of a <tr> is invalid nesting and renders
			// as an anonymous nested table, misaligned with the totals.
			var wrapper = document.createDocumentFragment();

			if (!locations || !locations.length) {
				var warn = document.createElement("div");
				warn.className = "okoskabet-pickup-empty";
				warn.textContent = t.noPlaces;
				wrapper.appendChild(row(t.place, warn));
				place(wrapper);
				syncHiddenFields();
				return;
			}

			// Where to collect. With one shop there is nothing to choose, so
			// the address is stated rather than offered as a menu of one.
			var placeSel = null;
			if (locations.length === 1) {
				chosenLocationId = String(locations[0].id);
				var only = document.createElement("div");
				only.className = "okoskabet-pickup-place";
				var addrOnly = addressLine(locations[0]);
				only.textContent = addrOnly
					? (locations[0].name + " \u2014 " + addrOnly)
					: locations[0].name;
				wrapper.appendChild(row(t.place, only));
			} else {
				placeSel = document.createElement("select");
				placeSel.id = SELECT_PLACE_ID;
				placeSel.style.width = "100%";
				locations.forEach(function (loc) {
					var o = document.createElement("option");
					o.value = String(loc.id);
					var addr = addressLine(loc);
					o.textContent = addr ? (loc.name + " \u2014 " + addr) : loc.name;
					placeSel.appendChild(o);
				});
				chosenLocationId = String(locations[0].id);
				wrapper.appendChild(row(t.place, placeSel));
			}

			// When to collect, for whichever location is selected.
			var dateSel = document.createElement("select");
			dateSel.id = SELECT_DATE_ID;
			dateSel.style.width = "100%";
			wrapper.appendChild(row(t.date, dateSel));

			function fillDates() {
				var wanted = placeSel ? placeSel.value : chosenLocationId;
				chosenLocationId = wanted;
				var chosen = null;
				for (var i = 0; i < locations.length; i++) {
					if (String(locations[i].id) === wanted) { chosen = locations[i]; break; }
				}
				var dates = (chosen && chosen.delivery_dates) ? chosen.delivery_dates : [];
				dateSel.innerHTML = "";
				if (!dates.length) {
					var o = document.createElement("option");
					o.value = "";
					o.textContent = t.noDates;
					dateSel.appendChild(o);
				} else {
					dates.forEach(function (d) {
						var o = document.createElement("option");
						o.value = d;
						o.textContent = formatDate(d);
						dateSel.appendChild(o);
					});
				}
				syncHiddenFields();
			}

			if (placeSel) { placeSel.addEventListener("change", fillDates); }
			dateSel.addEventListener("change", syncHiddenFields);

			// Next to the shipping choice, so the rows read as part of the
			// delivery decision rather than as loose fields further down.
			okoPlace(anchor, wrapper);
			fillDates();
		}

		function endpoint() {
			var c = window._okoskabet_checkout || {};
			return (c.endpoints && c.endpoints.storePickup) || "";
		}

		function cartKey() {
			var ids = document.getElementById("okoskabet-cart-product-ids");
			return (ids ? ids.value : "") + "|" + (isPreOrder() ? "pre" : "");
		}

		// A pre-order is offered its own days, a normal order the normal ones.
		function isPreOrder() {
			var f = document.getElementById("billing_okoskabet_pre_order");
			return !!f && f.value === "1";
		}

		function fetchAndRender() {
			if (!isStorePickup()) { removeUI(); return; }
			var key = cartKey();
			if (locationsCache !== null && locationsCacheKey === key) {
				buildUI(locationsCache);
				return;
			}
			var ep = endpoint();
			if (!ep || inFlight) { return; }
			var ids = document.getElementById("okoskabet-cart-product-ids");
			var url = ep + (ep.indexOf("?") === -1 ? "?" : "&")
				+ "product_ids=" + encodeURIComponent(ids ? ids.value : "")
				+ (isPreOrder() ? "&pre_order=1" : "");
			inFlight = true;
			locationsCacheKey = key;
			fetch(url, { credentials: "same-origin" })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					var results = d && d.results ? d.results : {};
					locationsCache = results.pickup_locations || [];
					inFlight = false;
					buildUI(locationsCache);
				})
				.catch(function () {
					inFlight = false;
					locationsCache = [];
					buildUI([]);
				});
		}

		document.addEventListener("change", function (e) {
			if (e.target && e.target.name === "shipping_method[0]") { fetchAndRender(); }
		});
		if (window.jQuery) {
			// The cache is keyed on the cart, so this only refetches when the
			// cart actually changed — not on every address or coupon edit.
			jQuery(document.body).on("updated_checkout", fetchAndRender);
			jQuery(document).ready(fetchAndRender);
		} else {
			document.addEventListener("DOMContentLoaded", fetchAndRender);
		}
	}());
}());
