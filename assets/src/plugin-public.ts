import './styles/public.scss';

import App from './App.svelte';
import type { DateMode, ShippingMethod } from './types';

const SELECTED_SHIPPING_METHOD_SELECTOR =
	'input[name="shipping_method[0]"]:checked';
const SINGLE_SHIPPING_METHOD_SELECTOR =
	'input[name="shipping_method[0]"][type="hidden"]';

const POSTAL_CODE_SELECTOR = '#billing_postcode';
const ADDRESS_1_SELECTOR = '#billing_address_1';
const ADDRESS_2_SELECTOR = '#billing_address_2';

const DELIVERY_DATE_INPUT_SELECTOR = '#billing_okoskabet_delivery_date';
const PRE_ORDER_INPUT_SELECTOR = '#billing_okoskabet_pre_order';
const SHED_ID_INPUT_SELECTOR = '#billing_okoskabet_shed_id';

class OkoskabetCheckout {
	private locale: string;
	private displayOption: 'inline' | 'modal';
	private shedDeliveryDescription: string;
	private homeDeliveryDescription: string;
	private dateAffectsTotals: boolean;

	// Whether the customer has the shed picker open in modal mode. The picker
	// is rebuilt on every recalculation of the checkout, and without this a
	// customer part-way through choosing would see the modal close on them.
	private optionsOpen = false;

	private deliveryOptions: App | undefined;

	constructor(
		locale: string,
		displayOption: 'inline' | 'modal',
		descriptions: { homeDelivery: string; shedDelivery: string },
		dateAffectsTotals: boolean
	) {
		this.locale = locale;
		this.displayOption = displayOption;
		this.dateAffectsTotals = dateAffectsTotals;
		this.homeDeliveryDescription = descriptions.homeDelivery;
		this.shedDeliveryDescription = descriptions.shedDelivery;

		this.attachEventListeners();
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

		// Between a pre-order and a normal order. The date goes with the switch:
		// the two offer different days, and the pickers start again from the
		// first one on offer. Recalculating rebuilds them, and the button with
		// its new wording.
		$( document ).on(
			'click',
			'.okoskabet-pre-order-toggle',
			function ( e ) {
				e.preventDefault();
				const preOrder = $( PRE_ORDER_INPUT_SELECTOR ).val() === '1';
				$( PRE_ORDER_INPUT_SELECTOR ).val( preOrder ? '' : '1' );
				$( DELIVERY_DATE_INPUT_SELECTOR ).val( '' );
				$( document.body ).trigger( 'update_checkout' );
			}
		);

		$( document ).on( 'change', 'input.shipping_method', function () {
			that.deliveryOptions?.$destroy();
			that.deliveryOptions = undefined;
			that.optionsOpen = false;
			that.clearInputs();
		} );
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

		const shippingMethodsList = inputElement.closest( 'ul' );
		const insertAfter = shippingMethodsList || inputElement.parentElement;

		if ( insertAfter ) {
			const target = document.createElement( 'div' );
			target.id = 'okoskabet-shipping';
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

		const postalCode =
			this.getFormFieldValue( POSTAL_CODE_SELECTOR )?.trim();
		const address1 = this.getFormFieldValue( ADDRESS_1_SELECTOR )?.trim();
		const address2 = this.getFormFieldValue( ADDRESS_2_SELECTOR )?.trim();

		const address = [ address1, address2 ]
			.filter( ( val ) => val && val !== '' )
			.join( ', ' );

		if ( shippingMethod && postalCode ) {
			return {
				shippingMethod,
				address,
				postalCode,
				dateMode: this.getSelectedDateMode(),
			};
		}
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
		// Set directly: this runs on a change of shipping method, which
		// recalculates the checkout on its own.
		jQuery( DELIVERY_DATE_INPUT_SELECTOR ).val( '' );
	}

	private setDeliveryDateInput( value: string ): void {
		const $input = jQuery( DELIVERY_DATE_INPUT_SELECTOR );

		// Only an actual change goes any further. The picker reports its date
		// again every time it is rebuilt, and recalculating the checkout is what
		// rebuilds it — so without this, putting the same date back would ask
		// for another recalculation, forever.
		if ( $input.val() === value ) {
			return;
		}

		$input.val( value );

		// The date can change what the order costs: a date far enough ahead is
		// a pre-order, and a pre-order can carry its own fee. WooCommerce only
		// recalculates when told to, and a total that changes after the customer
		// has pressed pay is not a price they were shown. Only shops with such a
		// fee ask for it. A date being cleared counts too — a new postcode with
		// no delivery days left the pre-order fee of the old date on screen.
		if ( this.dateAffectsTotals ) {
			jQuery( document.body ).trigger( 'update_checkout' );
		}
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
		dateAffectsTotals: dateAffectsTotals,
	} = ( window as any )._okoskabet_checkout;

	new OkoskabetCheckout(
		locale,
		displayOption,
		descriptions,
		Boolean( dateAffectsTotals )
	);

	window.mapboxgl.accessToken =
		'pk.eyJ1IjoiZGFub2tvc2thYmV0IiwiYSI6ImNsOTN5enc5eDF0OXgzcW10ejgyMDI3ZHIifQ.Yy_h5jy-F0E2t0EvnElFag';
} );

window.onload = () => {};
