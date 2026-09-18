import './styles/public.scss';

import App from './App.svelte';
import { callApi } from './api';
import type { DateMode, ShippingMethod } from './types';

const SELECTED_SHIPPING_METHOD_SELECTOR =
	'input[name="shipping_method[0]"]:checked';
const SINGLE_SHIPPING_METHOD_SELECTOR =
	'input[name="shipping_method[0]"][type="hidden"]';

const POSTAL_CODE_SELECTOR = '#billing_postcode';
const ADDRESS_1_SELECTOR = '#billing_address_1';
const ADDRESS_2_SELECTOR = '#billing_address_2';

// The other address, used when the customer ticks "ship to a different
// address". See getDeliveryAddress() for when each one applies.
const SHIP_TO_DIFFERENT_SELECTOR = '#ship-to-different-address-checkbox';
const SHIPPING_POSTAL_CODE_SELECTOR = '#shipping_postcode';
const SHIPPING_ADDRESS_1_SELECTOR = '#shipping_address_1';
const SHIPPING_ADDRESS_2_SELECTOR = '#shipping_address_2';

const DELIVERY_DATE_INPUT_SELECTOR = '#billing_okoskabet_delivery_date';
const SHED_ID_INPUT_SELECTOR = '#billing_okoskabet_shed_id';

class OkoskabetCheckout {
	private locale: string;
	private displayOption: 'inline' | 'modal';
	private shedDeliveryDescription: string;
	private homeDeliveryDescription: string;

	// Whether the customer has the shed picker open in modal mode. The picker
	// is rebuilt on every recalculation of the checkout, and without this a
	// customer part-way through choosing would see the modal close on them.
	private optionsOpen = false;

	private deliveryOptions: App | undefined;

	constructor(
		locale: string,
		displayOption: 'inline' | 'modal',
		descriptions: { homeDelivery: string; shedDelivery: string }
	) {
		this.locale = locale;
		this.displayOption = displayOption;
		this.homeDeliveryDescription = descriptions.homeDelivery;
		this.shedDeliveryDescription = descriptions.shedDelivery;

		this.attachEventListeners();

		// For the pre-order buttons, whose script arrives inline with the
		// order review and switches which days are asked for.
		( window as any ).okoskabetWarmDeliveryDates = () =>
			this.warmDeliveryDates();
	}

	private attachEventListeners() {
		const $ = jQuery;
		const that = this;

		$( document ).on( 'updated_checkout', function () {
			that.deliveryOptions?.$destroy();
			that.deliveryOptions = undefined;
			setTimeout( () => {
				if ( ! that.deliveryOptions ) {
					that.populateShippingOptions();
				} else {
					that.updateShippingOptions();
				}
			}, 200 );
		} );

		$( document ).on( 'applied_coupon_in_checkout', function () {
			that.deliveryOptions?.$destroy();
			that.deliveryOptions = undefined;
			setTimeout( () => {
				if ( ! that.deliveryOptions ) {
					that.populateShippingOptions();
				} else {
					that.updateShippingOptions();
				}
			}, 200 );
		} );

		$( document ).on( 'removed_coupon_in_checkout', function () {
			that.deliveryOptions?.$destroy();
			that.deliveryOptions = undefined;
			setTimeout( () => {
				if ( ! that.deliveryOptions ) {
					that.populateShippingOptions();
				} else {
					that.updateShippingOptions();
				}
			}, 200 );
		} );

		$( document ).on( 'change', 'input.shipping_method', function () {
			that.deliveryOptions?.$destroy();
			that.deliveryOptions = undefined;
			that.optionsOpen = false;
			that.clearInputs();
			that.warmDeliveryDates();
		} );

		// Ticking "ship to a different address", or changing the shipping
		// postcode, changes which address the dates belong to. WooCommerce has
		// to recalculate: it works out the shipping zone from the same address,
		// so price and dates move together, and the picker is rebuilt with the
		// new dates on updated_checkout above.
		//
		// Where WooCommerce's own checkout script already recalculates on the
		// field, it is left to do so — asking as well cost a second, wasted
		// recalculation each time, seen on Gaardmester. Only where it does not
		// do we ask. See woocommerceRecalculatesOn() for how that is told.
		$( document ).on( 'change', SHIP_TO_DIFFERENT_SELECTOR, function () {
			if ( ! woocommerceRecalculatesOn( this, WC_RECALCULATES_ON_CHANGE ) ) {
				$( document.body ).trigger( 'update_checkout' );
			}
		} );
		// The postcode needs one more condition. WooCommerce listens to it, but
		// only acts on a change if the field was typed in first: its change
		// handler (maybe_input_changed) does nothing unless a keydown marked
		// the input dirty. A postcode filled in by the browser's autofill, or
		// pasted with the mouse, changes without a keydown and recalculates
		// nothing — so a customer who ticked the box and let the browser fill
		// the shipping address kept the billing address's dates, and the
		// server only checks that a date was chosen, not that it fits.
		//
		// So we stay out only when this very field was typed in since its last
		// change, which is when WooCommerce is sure to act. Tab is not typing:
		// WooCommerce ignores it too. Anywhere we cannot be sure, we ask — an
		// occasional second recalculation costs one aborted request, while a
		// missing one books the wrong day.
		let typedInShippingPostcode = false;
		$( document ).on(
			'keydown',
			SHIPPING_POSTAL_CODE_SELECTOR,
			function ( event: JQuery.KeyDownEvent ) {
				if ( event.key !== 'Tab' ) {
					typedInShippingPostcode = true;
				}
			}
		);
		$( document ).on( 'change', SHIPPING_POSTAL_CODE_SELECTOR, function () {
			const typed = typedInShippingPostcode;
			typedInShippingPostcode = false;
			if (
				typed &&
				woocommerceRecalculatesOn( this, WC_RECALCULATES_ON_TEXT )
			) {
				return;
			}
			$( document.body ).trigger( 'update_checkout' );
		} );
	}

	// Ask for the dates of every Økoskabet method on offer now, rather than
	// when a picker appears. WooCommerce first recalculates the checkout and
	// only then rebuilds the picker, so the lookup used to wait on that whole
	// round trip before it even began; started here the two run side by side,
	// and a customer switching between Økoskab and home delivery finds the
	// other one's dates already there. The answers are remembered, so asking
	// again costs nothing.
	private warmDeliveryDates() {
		const { postalCode, address } = this.getDeliveryAddress();
		if ( ! postalCode ) {
			return;
		}

		const offered = Array.from(
			document.querySelectorAll< HTMLInputElement >(
				'input[name="shipping_method[0]"]'
			),
			( input ) => input.value
		);
		if ( offered.includes( 'hey_okoskabet_shipping_shed' ) ) {
			callApi( 'shed-delivery', address, postalCode ).catch( () => {} );
		}
		if ( offered.includes( 'hey_okoskabet_shipping_home' ) ) {
			callApi( 'home-delivery', address, postalCode ).catch( () => {} );
		}
	}

	private populateShippingOptions() {
		const shippingData = this.getShippingData();
		if ( ! shippingData ) {
			return;
		}

		const target = this.createSvelteTarget();
		if ( ! target ) {
			// eslint-disable-next-line no-console
			console.error(
				'Failed to populate shipping options - no target element found'
			);
			return;
		}

		const { shippingMethod, address, postalCode, dateMode } = shippingData;

		// The picker is torn down and rebuilt every time WooCommerce recalculates
		// the checkout — an address edit, a coupon, a gift card. Read what the
		// customer had chosen before it goes, so the new picker can put it back
		// instead of silently starting over from the first date. The hidden
		// fields survive the rebuild because they sit in the billing form, not in
		// the order review that WooCommerce replaces.
		const initialDeliveryDate =
			this.getFormFieldValue( DELIVERY_DATE_INPUT_SELECTOR ) || undefined;
		const initialShedId =
			shippingMethod === 'shed-delivery'
				? this.getFormFieldValue( SHED_ID_INPUT_SELECTOR ) || undefined
				: undefined;

		if ( shippingMethod === 'home-delivery' ) {
			this.setLocationInput( '' );
		}

		this.deliveryOptions = new App( {
			target,
			props: {
				displayMode: this.displayOption,
				shippingMethod,
				address,
				postalCode,
				initialDeliveryDate,
				initialShedId,
				initialShowOptions: this.optionsOpen,
				dateMode,
				locale: this.locale,
				strings: {
					shedDeliveryDescription: this.shedDeliveryDescription,
					homeDeliveryDescription: this.homeDeliveryDescription,
				},
				onSelectShed: ( shedId: string ) => {
					this.setLocationInput( shedId );
				},
				onSelectDeliveryDate: ( date: string ) => {
					this.setDeliveryDateInput( date );
				},
				onToggleOptions: ( open: boolean ) => {
					this.optionsOpen = open;
				},
			},
		} );

		this.warmDeliveryDates();
	}

	private updateShippingOptions() {
		const shippingData = this.getShippingData();
		if ( ! shippingData ) {
			this.deliveryOptions?.$destroy();
			this.clearInputs();
			return;
		}

		const { shippingMethod, address, postalCode } = shippingData;
		this.deliveryOptions?.$set( {
			shippingMethod,
			address,
			postalCode,
		} );
	}

	private createSvelteTarget(): HTMLElement | null {
		const inputElement = this.getSelectedShippingMethodElement();
		if ( ! inputElement ) {
			return null;
		}

		const target = document.createElement( 'div' );
		target.id = 'okoskabet-shipping';

		// Inside the chosen method's own line, so its description and picker
		// read as belonging to it. Placed after the whole list, they sat under
		// whichever method happened to be last — the Økoskab text under store
		// pickup, once pickup was the last line.
		const chosenLine = inputElement.closest( 'li' );
		if ( chosenLine ) {
			chosenLine.appendChild( target );
			return target;
		}

		const insertAfter =
			inputElement.closest( 'ul' ) || inputElement.parentElement;
		if ( insertAfter ) {
			insertAfter.after( target );
			return target;
		}
		return null;
	}

	private getShippingData():
		| {
				shippingMethod: ShippingMethod;
				address: string;
				postalCode: string;
				dateMode: DateMode;
		  }
		| undefined {
		const shippingMethod = this.getSelectedShippingMethod();
		const { postalCode, address } = this.getDeliveryAddress();

		if ( shippingMethod && postalCode ) {
			return {
				shippingMethod,
				address,
				postalCode,
				dateMode: this.getSelectedDateMode(),
			};
		}
	}

	// The address the order will actually be delivered to — the one the
	// dates have to be asked for.
	//
	// The picker used to read the billing address and nothing else. A home
	// delivery is sent to the shipping address, though, and WooCommerce works
	// out the shipping zone from it too, so a customer who ticked "ship to a
	// different address" got the zone and price of one place and the dates of
	// another. Billing in 2100 København, shipping to 3700 Rønne: Ø-levering
	// at the Bornholm price, on a day Økoskabet only drives in København, and
	// the order went to Økoskabet like that.
	//
	// The rule is the one PHP already uses when it decides whether a home
	// delivery may go without a date (oko_home_delivery_may_go_without_date):
	// the shipping address when the box is ticked and it has a postcode, the
	// billing address otherwise. Ticked with the shipping postcode still empty
	// falls back to billing, as it does there, rather than asking for no dates.
	// A customer who never ticks the box sees exactly what they saw before.
	private getDeliveryAddress(): {
		postalCode: string | undefined;
		address: string;
	} {
		const shipsElsewhere =
			document.querySelector< HTMLInputElement >(
				SHIP_TO_DIFFERENT_SELECTOR
			)?.checked === true;
		const shippingPostalCode = shipsElsewhere
			? this.getFormFieldValue( SHIPPING_POSTAL_CODE_SELECTOR )?.trim()
			: undefined;
		const useShipping = shipsElsewhere && !! shippingPostalCode;

		const postalCode = useShipping
			? shippingPostalCode
			: this.getFormFieldValue( POSTAL_CODE_SELECTOR )?.trim();

		const address = [
			this.getFormFieldValue(
				useShipping ? SHIPPING_ADDRESS_1_SELECTOR : ADDRESS_1_SELECTOR
			)?.trim(),
			this.getFormFieldValue(
				useShipping ? SHIPPING_ADDRESS_2_SELECTOR : ADDRESS_2_SELECTOR
			)?.trim(),
		]
			.filter( ( val ) => val && val !== '' )
			.join( ', ' );

		return { postalCode, address };
	}

	// The delivery-date setting of the chosen rate. The rate's id carries no
	// instance, so the setting cannot be worked out here and has to come from
	// PHP — from the span printed beside the radio, or from the config map.
	private getSelectedDateMode(): DateMode {
		const element = this.getSelectedShippingMethodElement();

		// The span first. WooCommerce prints it with the rate and reprints it
		// every time the shipping choices are redrawn, so it always belongs
		// to the rate on screen now. That matters because every home-delivery
		// rate shares one id: Hjemmelevering and an island zone's Ø-levering
		// are both hey_okoskabet_shipping_home, with different settings.
		const spanMode = element
			? findDateModeSpan( element )?.dataset.dateMode
			: undefined;
		if ( spanMode === 'when_available' || spanMode === 'required' ) {
			return spanMode;
		}

		// The map only when the checkout never printed the span — one that
		// skips woocommerce_after_shipping_rate. It is written once, when the
		// page loads, so it answers for the zone the page opened in: a
		// customer who moves to another zone afterwards gets that zone's
		// setting only from the span.
		const rateId = element?.value;
		const mapped = rateId
			? ( window as any )._okoskabet_checkout?.dateModes?.[ rateId ]
			: undefined;
		return mapped === 'when_available' ? mapped : 'required';
	}

	private getSelectedShippingMethodElement(): HTMLInputElement | undefined {
		const selectedElement = document.querySelector(
			SELECTED_SHIPPING_METHOD_SELECTOR
		);
		if ( selectedElement instanceof HTMLInputElement ) {
			return selectedElement;
		}
		const soloElement = document.querySelector(
			SINGLE_SHIPPING_METHOD_SELECTOR
		);
		if ( soloElement instanceof HTMLInputElement ) {
			return soloElement;
		}
	}

	private getSelectedShippingMethod():
		| 'home-delivery'
		| 'shed-delivery'
		| undefined {
		const selectedValue = this.getFormFieldValue(
			SELECTED_SHIPPING_METHOD_SELECTOR
		);
		const soloValue = this.getFormFieldValue(
			SINGLE_SHIPPING_METHOD_SELECTOR
		);

		const value = selectedValue || soloValue;
		switch ( value ) {
			case 'hey_okoskabet_shipping_home':
				return 'home-delivery';

			case 'hey_okoskabet_shipping_shed':
				return 'shed-delivery';
		}
	}

	private getFormFieldValue( selector: string ): string | undefined {
		const element = document.querySelector( selector );
		if ( element instanceof HTMLInputElement ) {
			return element.value;
		}
	}

	private clearInputs() {
		this.setLocationInput( '' );
		this.setDeliveryDateInput( '' );
	}

	private setDeliveryDateInput( value: string ): void {
		ensureBookingField( DELIVERY_DATE_INPUT_SELECTOR ).val( value );
	}

	private setLocationInput( value: string ): void {
		ensureBookingField( SHED_ID_INPUT_SELECTOR ).val( value );
	}
}

// What WooCommerce's checkout.js recalculates on, copied from its own event
// bindings (WooCommerce 11.1.0, assets/js/frontend/checkout.js), so the
// question below is the one WooCommerce itself answers.
const WC_RECALCULATES_ON_CHANGE =
	'#ship-to-different-address input, .update_totals_on_change input[type="checkbox"]';
const WC_RECALCULATES_ON_TEXT =
	'.address-field input.input-text, .update_totals_on_change input.input-text';

/**
 * Whether WooCommerce's own checkout script will recalculate when this field
 * changes, so that asking as well would only recalculate twice.
 *
 * It does when its script runs on the page (wc_checkout_params is how it
 * announces itself), the field sits inside form.checkout — which is where it
 * listens — and the field matches the selectors it listens for.
 *
 * An earlier version looked for update_totals_on_change on the shipping
 * postcode field instead, and got Gaardmester wrong: its Checkout Field Editor
 * strips that class from both postcode fields, yet WooCommerce still
 * recalculates on the ship-to-different box, which it binds to directly, and
 * on the postcode, which carries address-field. Asking what WooCommerce binds
 * to, rather than guessing from one class, holds whatever sits in between.
 */
function woocommerceRecalculatesOn(
	field: Element | null,
	selector: string
): boolean {
	return (
		typeof ( window as any ).wc_checkout_params !== 'undefined' &&
		!! field?.closest( 'form.checkout' ) &&
		!! field?.matches( selector )
	);
}

/**
 * The date-setting span that belongs to one shipping radio.
 *
 * WooCommerce's own list puts each rate in an `<li>`, but a builder need not:
 * Bricks nests the radio in divs with no list around it. So climb from the
 * radio until a span turns up, and stop as soon as the climb takes in a
 * second shipping radio, because any span found from there on could be the
 * other rate's.
 */
function findDateModeSpan( radio: HTMLElement ): HTMLElement | null {
	const stop = radio.closest( 'form' ) ?? document.body;
	let node = radio.parentElement;
	while ( node && node !== stop ) {
		if (
			node.querySelectorAll( 'input[name^="shipping_method["]' ).length > 1
		) {
			return null;
		}
		const span = node.querySelector< HTMLElement >( '.okoskabet-date-mode' );
		if ( span ) {
			return span;
		}
		node = node.parentElement;
	}
	return null;
}

/**
 * The hidden field a choice is written into, added to the checkout form if
 * the checkout never rendered it.
 *
 * A builder's checkout, such as Bricks' Checkout v2, draws its own form fields and leaves out the ones this plugin registers, so
 * `jQuery( '#billing_okoskabet_shed_id' ).val( … )` wrote into nothing, and
 * the order was placed with no locker and no date. checkout-helpers.js adds
 * the missing fields too; the two scripts load in no fixed order, so this
 * does it as well rather than write into a field that is not there yet.
 * On a checkout that renders the field, it is found and nothing is added.
 */
function ensureBookingField( selector: string ): JQuery< HTMLElement > {
	const existing = document.querySelector< HTMLElement >( selector );
	if ( existing ) {
		return jQuery( existing );
	}

	const form = document.querySelector(
		'form.checkout, form[name="checkout"]'
	);
	if ( ! form ) {
		return jQuery( selector );
	}

	const name = selector.replace( /^#/, '' );
	const input = document.createElement( 'input' );
	input.type = 'hidden';
	input.name = name;
	input.id = name;
	input.className = 'okoskabet-booking-field';
	form.appendChild( input );

	return jQuery( input );
}

/**
 * @function onload The window.onload function is called when the page is loaded
 */

window.addEventListener( 'DOMContentLoaded', function () {
	const {
		locale: locale,
		displayOption: displayOption,
		descriptions: descriptions,
	} = ( window as any )._okoskabet_checkout;

	new OkoskabetCheckout( locale, displayOption, descriptions );

	window.mapboxgl.accessToken =
		'pk.eyJ1IjoiZGFub2tvc2thYmV0IiwiYSI6ImNsOTN5enc5eDF0OXgzcW10ejgyMDI3ZHIifQ.Yy_h5jy-F0E2t0EvnElFag';
} );

window.onload = () => {};
