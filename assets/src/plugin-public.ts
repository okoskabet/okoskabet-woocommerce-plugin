import './styles/public.scss';

import App from './App.svelte';
import type { ShippingMethod } from './types';

const SELECTED_SHIPPING_METHOD_SELECTOR = 'input[name="shipping_method[0]"]:checked';

const POSTAL_CODE_SELECTOR = "#billing_postcode";
const ADDRESS_1_SELECTOR = "#billing_address_1";
const ADDRESS_2_SELECTOR = "#billing_address_2";

const DELIVERY_DATE_INPUT_SELECTOR = "#billing_okoskabet_delivery_date";
const SHED_ID_INPUT_SELECTOR = "#billing_okoskabet_shed_id";

class OkoskabetCheckout {
  private locale: string;
  private displayOption: 'inline' | 'modal';
  private shedDeliveryDescription: string;
  private homeDeliveryDescription: string;

  private deliveryOptions: App | undefined;

  constructor(locale: string, displayOption: 'inline' | 'modal', descriptions: { homeDelivery: string, shedDelivery: string }) {
    this.locale = locale;
    this.displayOption = displayOption;
    this.homeDeliveryDescription = descriptions.homeDelivery;
    this.shedDeliveryDescription = descriptions.shedDelivery;

    this.attachEventListeners();
  }

  private attachEventListeners() {
    const $ = jQuery;
    const that = this;

    that.populateShippingOptions();

    $(document).on('change', POSTAL_CODE_SELECTOR, function () {
      if ($(this).val()) {
        $('body').trigger('update_checkout');
      }
    });

    $(document).on('updated_checkout', function () {
      if ($(POSTAL_CODE_SELECTOR).val()) {
        that.updateShippingOptions()
      }
    })
  }

  private populateShippingOptions() {
    const { shippingMethod, address, postalCode } = this.getFormData()
    const target = this.createSvelteTarget()

    this.deliveryOptions = new App({
      target,
      props: {
        displayMode: this.displayOption,
        shippingMethod: shippingMethod,
        address: address,
        postalCode: postalCode,
        locale: this.locale,
        strings: {
          shedDeliveryDescription: this.shedDeliveryDescription,
          homeDeliveryDescription: this.homeDeliveryDescription,
        },
        onSelectShed: (shedId: string) => {
          this.setLocationInput(shedId);
        },
        onSelectDeliveryDate: (date: string) => {
          this.setDeliveryDateInput(date);
        }
      }
    })
  }

  private async updateShippingOptions() {
    const { shippingMethod, address, postalCode } = this.getFormData()
    this.deliveryOptions?.$set({
      shippingMethod,
      address,
      postalCode
    })
    this.moveSvelteTarget()
  }

  private createSvelteTarget(): HTMLElement {
    const selectedShippingMethodElement = <HTMLElement>document.querySelector(SELECTED_SHIPPING_METHOD_SELECTOR)
    const parentElement = selectedShippingMethodElement.parentElement!

    const target = document.createElement("div");
    target.id = "okoskabet-shipping";
    parentElement.after(target);
    return target;
  }

  private moveSvelteTarget(): void {
    const selectedShippingMethodElement = <HTMLElement>document.querySelector(SELECTED_SHIPPING_METHOD_SELECTOR)
    const parentElement = selectedShippingMethodElement.parentElement!

    const target = document.getElementById("okoskabet-shipping")
    if (target) {
      parentElement.after(target);
    }
  }

  private getFormData(): { shippingMethod: ShippingMethod, address: string, postalCode: string } {
    const selectedShippingMethodCheckedValue = (<HTMLInputElement>document.querySelector(SELECTED_SHIPPING_METHOD_SELECTOR)).value

    let shippingMethod: ShippingMethod;
    switch (selectedShippingMethodCheckedValue) {
      case 'hey_okoskabet_shipping_home':
        shippingMethod = 'home-delivery';
        break;

      case 'hey_okoskabet_shipping_shed':
      default:
        shippingMethod = 'shed-delivery';
        break;
    }

    const postalCode = (<HTMLInputElement>document.querySelector(POSTAL_CODE_SELECTOR)).value;
    const address1 = (<HTMLInputElement>document.querySelector(ADDRESS_1_SELECTOR)).value;
    const address2 = (<HTMLInputElement>document.querySelector(ADDRESS_2_SELECTOR)).value;

    const address = [address1, address2].filter(val => val && val !== "").join(", ")

    return {
      shippingMethod,
      address,
      postalCode,
    }
  }

  private setDeliveryDateInput(value: string): void {
    jQuery(DELIVERY_DATE_INPUT_SELECTOR).val(value);
  }

  private setLocationInput(value: string): void {
    jQuery(SHED_ID_INPUT_SELECTOR).val(value);
  }
}

/**
 * @function onload The window.onload function is called when the page is loaded
 */
window.onload = () => {
  window.mapboxgl.accessToken = 'pk.eyJ1IjoiZGFub2tvc2thYmV0IiwiYSI6ImNsOTN5enc5eDF0OXgzcW10ejgyMDI3ZHIifQ.Yy_h5jy-F0E2t0EvnElFag';

  jQuery(function () {
    const {
      locale: locale,
      displayOption: displayOption,
      descriptions: descriptions,
    } = (window as any)._okoskabet_checkout

    new OkoskabetCheckout(locale, displayOption, descriptions)
  })
};
