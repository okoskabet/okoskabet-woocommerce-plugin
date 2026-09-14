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

	// What the customer had chosen before the checkout was last recalculated.
	export let initialDeliveryDate: string | undefined = undefined;
	export let initialShedId: string | undefined = undefined;
	export let initialShowOptions = false;

	export let onSelectShed: (selectedShedId: string) => void;
	export let onSelectDeliveryDate: (selectedDate: string) => void;
	export let onToggleOptions: (open: boolean) => void = () => undefined;
</script>

{#if shippingMethod === 'shed-delivery'}
	<ShedDelivery
		{displayMode}
		{locale}
		{address}
		{postalCode}
		{initialShedId}
		{initialDeliveryDate}
		{initialShowOptions}
		{onSelectShed}
		{onToggleOptions}
		{onSelectDeliveryDate}
		description={strings.shedDeliveryDescription}
	/>
{:else if shippingMethod === 'home-delivery'}
	<HomeDelivery
		{locale}
		{address}
		{postalCode}
		{initialDeliveryDate}
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
