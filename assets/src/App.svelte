<script lang="ts">
	import HomeDelivery from './HomeDelivery.svelte';
	import ShedDelivery from './ShedDelivery.svelte';
	import type { DisplayMode, ShippingMethod } from './types';

	interface Strings {
		shedDeliveryDescription: string;
		homeDeliveryDescription: string;
	}

	export let locale: string;
	export let displayMode: DisplayMode;
	export let strings: Strings;
	export let shippingMethod: ShippingMethod;
	export let address: string;
	export let postalCode: string;

	export let onSelectShed: (selectedShedId: string) => void;
	export let onSelectDeliveryDate: (selectedDate: string) => void;
</script>

{#if shippingMethod === 'shed-delivery'}
	<ShedDelivery
		{displayMode}
		{locale}
		{address}
		{postalCode}
		{onSelectShed}
		{onSelectDeliveryDate}
		description={strings.shedDeliveryDescription}
	/>
{:else if shippingMethod === 'home-delivery'}
	<HomeDelivery
		{locale}
		{address}
		{postalCode}
		{onSelectDeliveryDate}
		description={strings.homeDeliveryDescription}
	/>
{/if}

<style>
	:global(#billing_okoskabet_shed_id_field) {
		display: none;
	}

	:global(#billing_okoskabet_delivery_date_field) {
		display: none;
	}
</style>
