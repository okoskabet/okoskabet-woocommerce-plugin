<script lang="ts">
	import type { Map, Marker } from 'mapbox-gl';
	import { onDestroy, onMount } from 'svelte';
	import type { Origin } from './types';

	export let map: Map;
	export let origin: Exclude<Origin, null>;

	let marker: Marker;

	onMount(() => {
		const lngLat = {
			lng: origin.longitude,
			lat: origin.latitude,
		};

		marker = new window.mapboxgl.Marker().setLngLat(lngLat).addTo(map);
	});

	onDestroy(() => {
		if (marker) {
			marker.remove();
		}
	});
</script>
