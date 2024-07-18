<script lang="ts">
	import type { Map, Marker, Popup } from 'mapbox-gl';
	import { onDestroy, onMount } from 'svelte';
	import type { Shed } from './types';

	export let map: Map;
	export let shed: Shed;
	export let selectedShed: Shed;

	export let onClick: (popup: Popup) => void;
	export let onClickAway: () => void;

	let markerElement: HTMLElement;
	let popupElement: HTMLElement;

	let marker: Marker;

	onMount(() => {
		const lngLat = {
			lng: shed.address.longitude,
			lat: shed.address.latitude,
		};

		const popup = new window.mapboxgl.Popup({
			offset: 20,
			closeButton: false,
		})
			.setDOMContent(popupElement)
			.on('open', (e: any) => {
				onClick(e.target);
			})
			.on('close', onClickAway);

		marker = new window.mapboxgl.Marker({ element: markerElement })
			.setLngLat(lngLat)
			.setPopup(popup)
			.addTo(map);
	});

	onDestroy(() => {
		marker.remove();
	});
</script>

<div
	bind:this={markerElement}
	class="marker"
	class:selected={selectedShed && shed.id === selectedShed.id}
></div>

<div bind:this={popupElement} data-shed={shed.id}>
	<h6>{shed.name}</h6>
	<div>{shed.address.address}</div>
	<div>{shed.address.postal_code} {shed.address.city}</div>
</div>

<style>
	h6 {
		font-weight: bold;
		margin-bottom: 0;
	}

	.marker {
		background-image: url('images/map_marker.svg');
		background-size: contain;
		width: 50px;
		height: 50px;
		border-radius: 50%;
		cursor: pointer;
		z-index: 0;
	}

	.marker.selected {
		background-image: url('images/map_marker_selected.svg');
		z-index: 2;
	}
</style>
