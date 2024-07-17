import './styles/public.scss';

import DeliveryOptions from './delivery_options.svelte';
import type { ApiResponse, Shed } from './types';

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

  private deliveryOptions: DeliveryOptions | undefined;

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

    $(document).on('change', POSTAL_CODE_SELECTOR, function () {
      if ($(this).val()) {
        $('body').trigger('update_checkout');
      }
    });

    $(document).on('updated_checkout', function () {
      that.setDeliveryDateInput('');
      that.setLocationInput('')

      if (that.deliveryOptions) {
        that.deliveryOptions.$destroy()
      }

      if ($(POSTAL_CODE_SELECTOR).val()) {
        that.populateShippingOptions()
      }
    })
  }

  private async populateShippingOptions(): Promise<void> {
    const { selectedShippingMethod, address1, address2, postalCode } = this.getFormData()
    const apiResponse = await this.callApi(selectedShippingMethod, address1, address2, postalCode);

    const selectedShippingMethodElement = <HTMLElement>document.querySelector(SELECTED_SHIPPING_METHOD_SELECTOR)
    const parentElement = selectedShippingMethodElement.parentElement!

    const commonProps = {
      origin: apiResponse.origin,
      locale: this.locale,
      onSelectShed: (shed: Shed) => {
        this.setLocationInput(shed.id);
      },
      onSelectDeliveryDate: (date: string) => {
        this.setDeliveryDateInput(date);
      }
    }

    switch (apiResponse.type) {
      case 'shed-delivery':
        this.deliveryOptions = new DeliveryOptions({
          target: parentElement,
          props: {
            ...commonProps,
            description: this.shedDeliveryDescription,
            displayOption: this.displayOption,
            sheds: apiResponse.sheds,
          }
        });
        break;

      case 'home-delivery':
        this.deliveryOptions = new DeliveryOptions({
          target: parentElement,
          props: {
            ...commonProps,
            description: this.homeDeliveryDescription,
            displayOption: 'inline',
            deliveryDates: apiResponse.delivery_dates,
          }
        });
        break;
    }
  }

  private async callApi(shippingMethod: 'shed-delivery' | 'home-delivery', address1: string | null, address2: string | null, postalCode: string): Promise<ApiResponse> {
    const address = [address1, address2].filter(val => val && val !== '').join(', ');
    const queryParams = new URLSearchParams({
      zip: postalCode,
      address: encodeURIComponent(address),
    }).toString()

    const myHeaders = new Headers();
    myHeaders.append("Accept", "application/json");
    myHeaders.append("Content-Type", "application/json");

    const requestOptions: RequestInit = {
      method: "GET",
      headers: myHeaders,
      redirect: "follow"
    };

    let url: string;
    switch (shippingMethod) {
      case 'shed-delivery':
        url = "https://wc.okoskabet.dk/wp-json/wp/v2/okoskabet/sheds?" + queryParams
        break;
      case 'home-delivery':
        url = "https://wc.okoskabet.dk/wp-json/wp/v2/okoskabet/home_delivery?" + queryParams
        break;
    }

    const response = await fetch(url, requestOptions);
    const { results: results } = await response.json();
    return { ...results, type: shippingMethod };
  }

  private getFormData(): { selectedShippingMethod: 'shed-delivery' | 'home-delivery', address1: string, address2: string | null, postalCode: string } {
    const selectedShippingMethodCheckedValue = (<HTMLInputElement>document.querySelector(SELECTED_SHIPPING_METHOD_SELECTOR)).value

    let selectedShippingMethod: 'shed-delivery' | 'home-delivery';
    switch (selectedShippingMethodCheckedValue) {

      case 'hey_okoskabet_shipping_home':
        selectedShippingMethod = 'home-delivery';
        break;

      case 'hey_okoskabet_shipping_shed':
      default:
        selectedShippingMethod = 'shed-delivery';
        break;
    }

    const postalCode = (<HTMLInputElement>document.querySelector(POSTAL_CODE_SELECTOR)).value;
    const address1 = (<HTMLInputElement>document.querySelector(ADDRESS_1_SELECTOR)).value;
    const address2 = (<HTMLInputElement>document.querySelector(ADDRESS_2_SELECTOR)).value;

    return {
      selectedShippingMethod,
      address1,
      address2,
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
    const { locale: locale, displayOption: displayOption, descriptions: descriptions } = (window as any)._okoskabet_checkout
    new OkoskabetCheckout(locale, displayOption, descriptions)
  })
};
