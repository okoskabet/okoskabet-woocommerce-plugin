<script lang="ts">
	import { formatDate } from './format-date';
	import Map from './map.svelte';
	import type { Origin, Shed } from './types';
	import type { Popup } from 'mapbox-gl';

	export let displayOption: 'inline' | 'modal';
	export let locale: string;
	export let description: string;
	export let onSelectShed: (selectedShed: Shed) => void = () => undefined;
	export let onSelectDeliveryDate: (selectedDate: string) => void;

	export let origin: Origin | null;
	export let sheds: Shed[] = [];
	export let deliveryDates = sheds[0] && sheds[0].delivery_dates;

	let selectedShed = sheds[0];
	let selectedDeliveryDate = deliveryDates && deliveryDates[0];
	let showOptions = displayOption === 'inline';

	$: {
		onSelectShed(selectedShed);
		selectDeliveryDate();
	}

	$: onSelectDeliveryDate(selectedDeliveryDate);

	function selectDeliveryDate() {
		deliveryDates = selectedShed.delivery_dates;

		if (
			!(
				selectedDeliveryDate &&
				selectedShed.delivery_dates.includes(selectedDeliveryDate)
			)
		) {
			selectedDeliveryDate = deliveryDates[0];
		}
	}

	function handleOpenModal() {
		showOptions = true;
	}

	function handleCloseModal() {
		showOptions = false;
	}
</script>

{#if displayOption === 'modal' && sheds.length > 0}
	<div id="oko-shed-custom-div-modal">
		<div id="oko-descrption" style="margin-bottom: 20px;">
			{description}
		</div>
		<div id="oko-shed-content">
			<div id="oko-shed-content-location">
				<span class="oko-shed-content-label">Økoskab: </span>
				<span class="oko-shed-content-value">{selectedShed.name}</span>
			</div>
			<div id="oko-shed-content-date">
				<span class="oko-shed-content-label">Levering: </span>
				<span class="oko-shed-content-value">
					{formatDate(selectedDeliveryDate, locale)}
				</span>
			</div>
		</div>

		<a
			href={'#'}
			on:click={handleOpenModal}
			class="button okoButtonModalOpen"
			style="margin-bottom: 20px;"
		>
			Vælg Økoskab
		</a>
	</div>
{/if}

<div id="oko-shed-custom-div" class={displayOption} class:hidden={!showOptions}>
	<div id="oko-descrption" style="margin-bottom: 20px;">
		{description}
	</div>

	{#if sheds.length > 0}
		<div class="oko-select-headline" style="font-size: 14px;">Økoskab</div>
		<select
			bind:value={selectedShed}
			name="okoLocations"
			id="locationsDropdown"
			style="width: 100%; margin-top: 0; margin-bottom: 20px;"
		>
			{#each sheds as shed}
				<option value={shed}>
					{shed.name}
				</option>
			{/each}
		</select>
	{/if}

	<div class="oko-select-headline" style="font-size: 14px;">
		Leveringsdato
	</div>
	<select
		bind:value={selectedDeliveryDate}
		name="okoDeliveryDates"
		style="width: 100%; margin-bottom: 20px;"
	>
		{#each deliveryDates as deliveryDate}
			<option value={deliveryDate}>
				{formatDate(deliveryDate, locale)}
			</option>
		{/each}
	</select>

	{#if sheds.length > 0}
		<Map {sheds} {origin} bind:selectedShed />

		{#if displayOption === 'modal'}
			<div class="okoButtonModal okoButtonModalDone">
				<div class="okoButtonModalContent"></div>
				<a href={'#'} class="button" on:click={handleCloseModal}>Done</a
				>
			</div>
		{/if}
	{/if}
</div>

<style>
	#oko-shed-custom-div.modal {
		position: fixed;
		top: 5%;
		left: 50%;
		background: white;
		padding: 20px;
		border: 1px solid rgba(0, 0, 0, 0.5);
		border-radius: 3px;
		transform: translateX(-50%);
		z-index: 9999;
		width: 480px;
		max-width: 94%;
		max-height: 90%;
		overflow: scroll;
	}

	#oko-shed-custom-div.hidden {
		visibility: hidden;
	}

	#oko-shed-content {
		line-height: 1.1;
		margin-top: 10px;
		margin-bottom: 10px;
		font-size: 80%;
	}

	.oko-shed-content-label {
		font-weight: bold;
	}

	.oko-shed-content-value {
		font-weight: normal;
	}

	#oko-descrption {
		font-weight: normal;
		line-height: 1.1;
		font-size: 80%;
	}
</style>
