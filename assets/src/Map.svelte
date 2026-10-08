<script lang="ts">
	import type { Map, Popup } from 'mapbox-gl';
	import { onMount, onDestroy } from 'svelte';
	import type { Origin, Shed } from './types';
	import OriginMarker from './OriginMarker.svelte';
	import ShedMarker from './ShedMarker.svelte';

	export let origin: Origin | null;
	export let sheds: Shed[];

	export let selectedShedId: string | undefined;

	let map: Map | undefined;
	let mapContainer: any;
	let resizeObserver: ResizeObserver | undefined;
	let popup: { popup: Popup; shed: Shed } | undefined = undefined;

	const onClickMarker = (shed: Shed) => (p: Popup) => {
		selectedShedId = shed.id;
		popup = { popup: p, shed: shed };
	};

	const onClickAway = () => {
		popup = undefined;
	};

	$: {
		if (popup && popup.shed.id !== selectedShedId) {
			popup.popup.remove();
			onClickAway();
		}
	}

	onMount(() => {
		const mapCenter = origin
			? { lng: origin.longitude, lat: origin.latitude }
			: {
					lng: sheds[0].address.longitude,
					lat: sheds[0].address.latitude,
				};

		map = new window.mapboxgl.Map({
			container: mapContainer,
			style: 'mapbox://styles/mapbox/streets-v12',
			center: mapCenter,
			zoom: 11,
		});

		const bounds = new window.mapboxgl.LngLatBounds();
		sheds.forEach(({ address: { latitude: lat, longitude: lng } }) => {
			const lngLat = { lng, lat };
			bounds.extend(lngLat);
		});
		if (origin) {
			const lngLat = { lng: origin.longitude, lat: origin.latitude };
			bounds.extend(lngLat);
		}
		map.fitBounds(bounds, { padding: 5 });

		// Mapbox measures its container once, when it is built, and draws to
		// that size until told otherwise. A multi-step checkout builds the map
		// while its step is hidden, so it measured nothing: the tiles came out
		// in a small box, the markers spread across the full width around it,
		// and one sat below the map entirely. Watch the container and redraw
		// when its size changes.
		//
		// The view is fitted once more the first time the container has a
		// real size, because the first fit was to an invisible map. Only
		// once, so a customer who has panned or zoomed is not pulled back on
		// every later resize. A map built visible — the classic checkout — is
		// already fitted, and resize() at an unchanged size does nothing.
		let fittedWithSize =
			mapContainer.clientWidth > 0 && mapContainer.clientHeight > 0;

		if (typeof ResizeObserver !== 'undefined') {
			resizeObserver = new ResizeObserver(() => {
				if (!map) {
					return;
				}
				map.resize();
				if (
					!fittedWithSize &&
					mapContainer.clientWidth > 0 &&
					mapContainer.clientHeight > 0
				) {
					map.fitBounds(bounds, { padding: 5 });
					fittedWithSize = true;
				}
			});
			resizeObserver.observe(mapContainer);
		}
	});

	onDestroy(() => {
		if (resizeObserver) {
			resizeObserver.disconnect();
		}
		if (map) {
			map.remove();
		}
	});
</script>

<div class="map-wrap">
	<div class="map" bind:this={mapContainer}></div>
</div>
{#if map}
	{#each sheds as shed}
		<ShedMarker
			{map}
			{shed}
			{selectedShedId}
			{onClickAway}
			onClick={onClickMarker(shed)}
		/>
	{/each}
	{#if origin}
		<OriginMarker {origin} {map} />
	{/if}
{/if}

<style>
	.map-wrap {
		width: 100%;
		height: 450px;
		margin-bottom: 20px;
		position: relative;
	}

	.map {
		position: absolute;
		width: 100%;
		height: 100%;
	}

	:global(.marker) {
		background-image: url('images/map_marker.svg');
		background-size: contain;
		width: 50px;
		height: 50px;
		border-radius: 50%;
		cursor: pointer;
		z-index: 0;
	}

	:global(.okoIconSelected) {
		background-image: url('images/map_marker_selected.svg');
		z-index: 2;
	}
</style>
