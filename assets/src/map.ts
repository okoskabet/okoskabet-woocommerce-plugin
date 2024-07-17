import type { Popup } from "mapbox-gl";
import type { Origin, Shed, ShedsResponse } from "./types";

const MARKER_SELECTED_CLASS = "okoIconSelected";

const MARKER_POPUP = (shed: Shed) => `
  <div id="oko-map-marker-popup" data-shed="${shed.id}">
    <h6 style='font-weight: bold; margin-bottom: 0;'>${shed.name}</h6>
    <div>${shed.address.address}</div>
    <div>${shed.address.postal_code} ${shed.address.city}</div>
  </div>
`

function markerIcon(shed: Shed, isSelected: boolean, onMarkerClick: (e: Event) => void) {
  const okoIcon = document.createElement('div');
  okoIcon.id = `marker-${shed.id}`;
  okoIcon.classList.add("marker");
  okoIcon.dataset.shedId = shed.id;

  if (isSelected) {
    okoIcon.classList.add(MARKER_SELECTED_CLASS);
  }
  okoIcon.addEventListener('click', onMarkerClick);

  return okoIcon;
}

export function initMap(container: string, sheds: Shed[], origin: Origin | null, onMarkerClick: (e: Event) => void, onPopupOpen: (e: Event) => void, onPopupClose: (popup: Popup) => void): mapboxgl.Map {
  const $ = jQuery;

  const map = new window.mapboxgl.Map({
    container, // container ID
    style: 'mapbox://styles/mapbox/streets-v12', // style URL
    center: origin ? [origin.longitude, origin.latitude] : [sheds[0].address.longitude, sheds[0].address.latitude], // starting position [lng, lat]
    zoom: 11, // starting zoom
  });

  const bounds = new window.mapboxgl.LngLatBounds();

  sheds.forEach((shed) => {
    const lngLat = { lng: shed.address.longitude, lat: shed.address.latitude };
    const okoIcon = markerIcon(shed, shed.id === sheds[0].id, onMarkerClick)

    const popup = new window.mapboxgl.Popup({
      offset: 20,
      closeButton: false
    })
      .setHTML(MARKER_POPUP(shed))
      .on('open', onPopupOpen)
      .on('close', onPopupClose);

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
  return map;
}

export function selectMarker(shed: Shed) {
  const marker = document.getElementById(`marker-${shed.id}`)
  if (marker) {
    marker.classList.add(MARKER_SELECTED_CLASS);
  }
}

export function deselectMarkers() {
  for (const marker of document.getElementsByClassName(MARKER_SELECTED_CLASS)) {
    marker.classList.remove(MARKER_SELECTED_CLASS);
  }
}
