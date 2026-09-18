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
		// postcode, changes which address the dates belong to. Ask WooCommerce
		// to recalculate: it works out the shipping zone from the same address,
		// so price and dates move together, and the picker is rebuilt with the
		// new dates on updated_checkout above.
		$( document ).on( 'change', SHIP_TO_DIFFERENT_SELECTOR, function () {
			$( document.body ).trigger( 'update_checkout' );
		} );

		// WooCommerce's own checkout marks the postcode field
		// update_totals_on_change and recalculates by itself, so that case is
		// left to it. A builder's checkout does not always mark it — Bricks'
		// Checkout v2 does not — and there nothing would recalculate at all.
		$( document ).on( 'change', SHIPPING_POSTAL_CODE_SELECTOR, function () {
			const field = document.getElementById( 'shipping_postcode_field' );
			if ( field?.classList.contains( 'update_totals_on_change' ) ) {
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

	// The delivery-date setting of the chosen rate, printed next to its radio
	// button by PHP. The rate's id carries no instance, so the setting cannot
	// be looked up from here.
	private getSelectedDateMode(): DateMode {
		const mode = this.getSelectedShippingMethodElement()
			?.closest( 'li' )
			?.querySelector< HTMLElement >( '.okoskabet-date-mode' )
			?.dataset.dateMode;
		return mode === 'when_available' ? mode : 'required';
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
		jQuery( DELIVERY_DATE_INPUT_SELECTOR ).val( value );
	}

	private setLocationInput( value: string ): void {
		jQuery( SHED_ID_INPUT_SELECTOR ).val( value );
	}
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
