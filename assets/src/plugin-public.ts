import { Popup } from 'mapbox-gl';
import './styles/public.scss';
import * as mapboxgl from 'mapbox-gl';

interface Shed {
  id: string;
  name: string;
  address: {
    address: string;
    city: string;
    latitude: number;
    longitude: number;
    postal_code: string;
  };
  delivery_dates: string[];
}

type Origin = { latitude: number, longitude: number } | null;

interface ShedsResponse {
  type: 'shed-delivery';
  origin: Origin;
  sheds: Shed[];
}

interface HomeDeliveryResponse {
  type: 'home-delivery';
  origin: Origin;
  delivery_dates: string[];
}

type ApiResponse = ShedsResponse | HomeDeliveryResponse


const SHED_DELIVERY_CONTAINER_ID = 'oko-shed-custom-div';
const SHED_DELIVERY_CONTAINER_SELECTOR = `#${SHED_DELIVERY_CONTAINER_ID}`;

const HOME_DELIVERY_CONTAINER_ID = 'oko-local-custom-div';
const HOME_DELIVERY_CONTAINER_SELECTOR = `#${HOME_DELIVERY_CONTAINER_ID}`;

const SELECTED_SHIPPING_METHOD_SELECTOR = 'input[name="shipping_method[0]"]:checked';

const POSTAL_CODE_SELECTOR = "#billing_postcode";
const ADDRESS_1_SELECTOR = "#billing_address_1";
const ADDRESS_2_SELECTOR = "#billing_address_2";

const DELIVERY_DATE_INPUT_SELECTOR = "#billing_okoskabet_delivery_date";
const SHED_ID_INPUT_SELECTOR = "#billing_okoskabet_shed_id";

const LOCATIONS_DROPDOWN_ID = "locationsDropdown";
const LOCATIONS_DROPDOWN_SELECTOR = `#${LOCATIONS_DROPDOWN_ID}`;

const DELIVERY_DATES_DROPDOWN_ID = "deliveryDatesDropdown";
const DELIVERY_DATES_DROPDOWN_SELECTOR = `#${DELIVERY_DATES_DROPDOWN_ID}`;

const SHED_DETAILS_DIV_ID = "oko-shed-custom-div-modal";

const SHED_DETAILS_LOCATION_ID = "oko-shed-content-location";
const SHED_DETAILS_LOCATION_SELECTOR = `#${SHED_DETAILS_LOCATION_ID}`;

const SHED_DETAILS_DATE_ID = "oko-shed-content-date";
const SHED_DETAILS_DATE_SELECTOR = `#${SHED_DETAILS_DATE_ID}`;

const MARKER_POPUP_ID = "oko-map-marker-popup";
const MARKER_POPUP_SELECTOR = `#${MARKER_POPUP_ID}`;

const MARKER_SELECTED_CLASS = "okoIconSelected";

const DESCRIPTION_DIV = (description: string) => `
  <div id="oko-descrption" style="margin-bottom: 20px;">
    ${description}
  </div>
`

const SHED_DATE_DETAILS = (deliveryDate: string) => `
  <span class="oko-shed-content-label">Levering: </span>
  <span class="oko-shed-content-value">${deliveryDate}</span>
`

const SELECTED_SHED_DETAILS_DIV = (description: string) => `
  <div id="${SHED_DETAILS_DIV_ID}">
    ${DESCRIPTION_DIV(description)}
    <div id="oko-shed-content">
      <div id="${SHED_DETAILS_LOCATION_ID}"></div>
      <div id="${SHED_DETAILS_DATE_ID}"></div>
    </div>
    <a href="#" class="button okoButtonModalOpen" style="margin-bottom: 20px;">
      Vælg Økoskab
    </a>
  </div>
`

const SHED_LOCATION_DETAILS = (shedName: string) => `
  <span class="oko-shed-content-label">Økoskab: </span>
  <span class="oko-shed-content-value">${shedName}</span>
`

const DELIVERY_DATE_OPTIONS = (deliveryDates: string[], formatter: (date: string) => string) =>
  deliveryDates
    .map(deliveryDate => `
      <option value="${deliveryDate}">
        ${formatter(deliveryDate)}
      </option>
    `)
    .join("")

const DELIVERY_DATES_DROPDOWN = (deliveryDates: string[], dateFormatter: (date: string) => string) => `
  <div class="oko-select-headline" style="font-size: 14px;">
    Leveringsdato
  </div>
  <select name="okoDeliveryDates" id="${DELIVERY_DATES_DROPDOWN_ID}" style="width: 100%; margin-bottom: 20px;">
    ${DELIVERY_DATE_OPTIONS(deliveryDates, dateFormatter)}
  </select>
`

const SHED_DIV = (sheds: Shed[], dateFormatter: (date: string) => string, description: string) => `
  <div id="${SHED_DELIVERY_CONTAINER_ID}">
    ${DESCRIPTION_DIV(description)}
    <div class="oko-select-headline" style="font-size: 14px;">
      Økoskab
    </div>
    <select name="okoLocations" id="${LOCATIONS_DROPDOWN_ID}" style="width: 100%; margin-top: 0; margin-bottom: 20px;">
      ${sheds.map(shed => (`
        <option value="${shed.id}">
          ${shed.name}
        </option>
      `)).join("")}
    </select>
    ${DELIVERY_DATES_DROPDOWN(sheds[0].delivery_dates, dateFormatter)}
    <div id="map" style="width: 100%; height: 450px; margin-bottom: 20px;">
    </div>
    <div class="okoButtonModal okoButtonModalDone">
      <div class="okoButtonModalContent">
      </div>
      <a href="#" class="button">Done</a>
    </div>
  </div>
`

const HOME_DELIVERY_DIV = (deliveryDates: string[], dateFormatter: (date: string) => string, description: string) => `
  <div id="${HOME_DELIVERY_CONTAINER_ID}">
    ${DESCRIPTION_DIV(description)}
    ${DELIVERY_DATES_DROPDOWN(deliveryDates, dateFormatter)}
  </div>
`


const MARKER_POPUP = (shed: Shed) => `
  <div id="${MARKER_POPUP_ID}" data-shed="${shed.id}">
    <h6 style='font-weight: bold; margin-bottom: 0;'>${shed.name}</h6>
    <div>${shed.address.address}</div>
    <div>${shed.address.postal_code} ${shed.address.city}</div>
  </div>
`

class OkoskabetCheckout {
  private locale: string;
  private displayOption: 'inline' | 'modal';
  private shedDeliveryDescription: string;
  private homeDeliveryDescription: string;

  private currentMap: mapboxgl.Map | undefined;
  private activePopup: mapboxgl.Popup | undefined;

  private lastApiResponse: ApiResponse | undefined;

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

    $(document).on('change', LOCATIONS_DROPDOWN_SELECTOR, function () {
      const location = $(this).val();
      that.changeLocation(location)
      if (that.activePopup) {
        that.activePopup.remove();
      }
    })

    $(document).on('change', DELIVERY_DATES_DROPDOWN_SELECTOR, function () {
      const deliveryDate = $(this).val();
      const formattedDate = that.formatDateToShopLocale(deliveryDate);
      that.setDeliveryDateInput(deliveryDate)
      $(SHED_DETAILS_DATE_SELECTOR).html(SHED_DATE_DETAILS(formattedDate));
    });

    $(document).on('click', '.okoButtonModalDone', function (event) {
      event.preventDefault();
      $(SHED_DELIVERY_CONTAINER_SELECTOR).hide();
    });

    $(document).on('click', '.okoButtonModalOpen', function (event) {
      event.preventDefault();
      $(SHED_DELIVERY_CONTAINER_SELECTOR).show();

      if (that.currentMap) {
        that.currentMap.resize();
      }
    });

    $(document).on('updated_checkout', function () {
      that.setDeliveryDateInput('');
      $(SHED_ID_INPUT_SELECTOR).val('');

      $(SHED_DELIVERY_CONTAINER_SELECTOR).remove();
      $(HOME_DELIVERY_CONTAINER_SELECTOR).remove();

      if ($(POSTAL_CODE_SELECTOR).val()) {
        that.populateShippingOptions()
      }
    })
  }

  private formatDateToShopLocale(date: Date | string): string {
    const normalizedLocale = this.locale.replace("_", "-");
    const deliveryDateObject = new Date(date);

    const deliveryDateFormatted = deliveryDateObject.toLocaleDateString(normalizedLocale, {
      year: 'numeric',
      month: 'long',
      day: 'numeric',
      weekday: 'long',
      timeZone: "UTC"
    });

    return deliveryDateFormatted.charAt(0).toUpperCase() + deliveryDateFormatted.slice(1);
  }

  private changeLocation(shedId: string): void {
    const $ = jQuery;

    if (this.lastApiResponse?.type == 'shed-delivery') {
      const shed = this.lastApiResponse.sheds.find(shed => shed.id === shedId)!

      $(SHED_ID_INPUT_SELECTOR).val(shedId);
      $(SHED_DETAILS_LOCATION_SELECTOR).html(SHED_LOCATION_DETAILS(shed.name));

      this.deselectMarkers();
      const marker = document.getElementById(`marker-${shedId}`)!;
      this.selectMarker(marker);

      $(DELIVERY_DATES_DROPDOWN_SELECTOR).html(DELIVERY_DATE_OPTIONS(shed.delivery_dates, (date: string) => this.formatDateToShopLocale(date)));
      $(DELIVERY_DATES_DROPDOWN_SELECTOR).trigger('change');
    }
  }

  private initMaps({ origin: origin, sheds: sheds }: ShedsResponse, onMarkerClick: (e: Event) => void, onPopupOpen: () => void) {
    const $ = jQuery;

    const map = new window.mapboxgl.Map({
      container: 'map', // container ID
      style: 'mapbox://styles/mapbox/streets-v12', // style URL
      center: origin ? [origin.longitude, origin.latitude] : [sheds[0].address.longitude, sheds[0].address.latitude], // starting position [lng, lat]
      zoom: 11, // starting zoom
    });

    const bounds = new window.mapboxgl.LngLatBounds();

    const selectedOption = $(LOCATIONS_DROPDOWN_SELECTOR).find('option:selected');
    const selectedShedId = selectedOption.val() as string;

    sheds.forEach((shed) => {
      const lngLat = { lng: shed.address.longitude, lat: shed.address.latitude };
      const okoIcon = this.markerIcon(shed, selectedShedId, onMarkerClick)

      const popup = new window.mapboxgl.Popup({
        offset: 20,
        closeButton: false
      })
        .setHTML(MARKER_POPUP(shed))
        .on('open', onPopupOpen);

      new window.mapboxgl.Marker(okoIcon)
        .setLngLat(lngLat)
        .setPopup(popup)
        .addTo(map);

      bounds.extend(lngLat);
    });

    if (origin && origin.longitude && origin.latitude) {
      const lngLat = { lng: origin.longitude, lat: origin.latitude };

      new window.mapboxgl.Marker()
        .setLngLat(lngLat)
        .addTo(map);

      bounds.extend(lngLat);
    }

    map.fitBounds(bounds);
    this.currentMap = map;
  }

  private markerIcon(shed: Shed, selectedShedId: string, onMarkerClick: (e: Event) => void) {
    const okoIcon = document.createElement('div');
    okoIcon.id = `marker-${shed.id}`;
    okoIcon.classList.add("marker");

    if (shed.id === selectedShedId) {
      okoIcon.classList.add(MARKER_SELECTED_CLASS);
    }
    okoIcon.addEventListener('click', onMarkerClick);

    return okoIcon;
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
        url = "/wp-json/wp/v2/okoskabet/sheds?" + queryParams
        break;
      case 'home-delivery':
        url = "/wp-json/wp/v2/okoskabet/home_delivery?" + queryParams
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

  private async populateShippingOptions(): Promise<void> {
    const $ = jQuery;

    const { selectedShippingMethod, address1, address2, postalCode } = this.getFormData()
    const apiResponse = await this.callApi(selectedShippingMethod, address1, address2, postalCode);

    const parentShipping = $(SELECTED_SHIPPING_METHOD_SELECTOR).parent();

    switch (apiResponse.type) {
      case 'shed-delivery':
        this.populateShedDeliveryOptions(apiResponse, parentShipping);
        break;

      case 'home-delivery':
        this.populateHomeDeliveryOptions(apiResponse, parentShipping);
        break;
    }

    this.lastApiResponse = apiResponse;

    $(LOCATIONS_DROPDOWN_SELECTOR).trigger('change');
    $(DELIVERY_DATES_DROPDOWN_SELECTOR).trigger('change');
  }

  private populateShedDeliveryOptions(shedsResponse: ShedsResponse, parentContainer: JQuery<HTMLElement>): void {
    if (this.displayOption === 'modal') {
      parentContainer.append(SELECTED_SHED_DETAILS_DIV(this.shedDeliveryDescription));
    }
    parentContainer.append(SHED_DIV(shedsResponse.sheds, (date) => this.formatDateToShopLocale(date), this.shedDeliveryDescription));

    const that = this;
    const onPopupOpen = function (this: Popup) {
      const markerInner = this.getElement().querySelector(MARKER_POPUP_SELECTOR);
      if (markerInner && markerInner instanceof HTMLElement) {
        const currentShed = markerInner.dataset.shed as string;
        that.setLocationInput(currentShed);
        that.changeLocation(currentShed);
        that.activePopup = this;
      }
    }

    const onMarkerClick = (e: Event) => {
      this.deselectMarkers();
      if (e.target && e.target instanceof Element) {
        this.selectMarker(e.target);
      }
    };

    this.initMaps(shedsResponse, onMarkerClick, onPopupOpen);
  }

  private populateHomeDeliveryOptions({ delivery_dates: deliveryDates }: HomeDeliveryResponse, parentContainer: JQuery<HTMLElement>): void {
    const div = HOME_DELIVERY_DIV(deliveryDates, (dateString: string) => this.formatDateToShopLocale(dateString), this.homeDeliveryDescription)
    parentContainer.append(div)
  }

  private setDeliveryDateInput(value: string): void {
    jQuery(DELIVERY_DATE_INPUT_SELECTOR).val(value);
  }

  private setLocationInput(value: string): void {
    jQuery(LOCATIONS_DROPDOWN_SELECTOR).val(value);
  }

  private selectMarker(marker: Element) {
    marker.classList.add(MARKER_SELECTED_CLASS);
  }

  private deselectMarkers() {
    for (const marker of document.getElementsByClassName(MARKER_SELECTED_CLASS)) {
      marker.classList.remove(MARKER_SELECTED_CLASS);
    }
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
