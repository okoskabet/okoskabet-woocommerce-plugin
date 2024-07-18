<script lang="ts">
	import { callApi } from './api';
	import { formatDate } from './format-date';
	import Map from './Map.svelte';

	export let displayMode: 'inline' | 'modal';
	export let locale: string;
	export let description: string;
	export let address: string;
	export let postalCode: string;
	export let onSelectShed: (selectedShedId: string) => void = () => undefined;
	export let onSelectDeliveryDate: (selectedDate: string) => void;

	let selectedShedId: string | undefined;
	let selectedDeliveryDate: string | undefined;
	let showOptions = displayMode === 'inline';

	$: {
		if (selectedShedId) {
			onSelectShed(selectedShedId);
			selectDeliveryDate();
		}
	}

	$: {
		if (selectedDeliveryDate) {
			onSelectDeliveryDate(selectedDeliveryDate);
		}
	}

	$: apiResponse = callApi('shed-delivery', address, postalCode);

	async function selectDeliveryDate() {
		if (selectedShedId) {
			const { sheds } = await apiResponse;
			const selectedShed = sheds.find(
				(shed) => shed.id === selectedShedId
			);
			const deliveryDates = selectedShed?.delivery_dates;

			if (
				!(
					selectedDeliveryDate &&
					selectedShed?.delivery_dates.includes(selectedDeliveryDate)
				)
			) {
				selectedDeliveryDate = deliveryDates && deliveryDates[0];
			}
		} else {
			selectedDeliveryDate = undefined;
		}
	}

	function handleOpenModal(e: Event) {
		e.preventDefault();
		showOptions = true;
	}

	function handleCloseModal(e: Event) {
		e.preventDefault();
		showOptions = false;
	}
</script>

{#await apiResponse}
	<div class="description">
		{description}
	</div>

	<div class="skeleton-container">
		<span class="skeleton-loader"></span>
	</div>
{:then { origin, sheds }}
	{@const selectedShed = sheds.find((shed) => shed.id === selectedShedId)}
	<div class={displayMode} class:hidden={!showOptions}>
		<div class="description">
			{description}
		</div>

		<div class="oko-select-headline" style="font-size: 14px;">Økoskab</div>
		<select
			bind:value={selectedShedId}
			name="okoLocations"
			id="locationsDropdown"
			style="width: 100%; margin-top: 0; margin-bottom: 20px;"
		>
			{#each sheds as shed}
				<option value={shed.id}>
					{shed.name}
				</option>
			{/each}
		</select>

		<div class="oko-select-headline" style="font-size: 14px;">
			Leveringsdato
		</div>
		<select
			bind:value={selectedDeliveryDate}
			name="okoDeliveryDates"
			style="width: 100%; margin-bottom: 20px;"
		>
			{#each selectedShed ? selectedShed.delivery_dates : sheds[0].delivery_dates as deliveryDate}
				<option value={deliveryDate}>
					{formatDate(deliveryDate, locale)}
				</option>
			{/each}
		</select>

		<Map {sheds} {origin} bind:selectedShedId />

		{#if displayMode === 'modal'}
			<div class="okoButtonModal okoButtonModalDone">
				<div class="okoButtonModalContent"></div>
				<a href={'#'} class="button" on:click={handleCloseModal}>Done</a
				>
			</div>
		{/if}
	</div>

	{#if displayMode === 'modal'}
		<div class="description">
			{description}
		</div>
		<div id="oko-shed-content">
			<div id="oko-shed-content-location">
				<span class="oko-shed-content-label">Økoskab: </span>
				<span class="oko-shed-content-value">{selectedShed?.name}</span>
			</div>
			<div id="oko-shed-content-date">
				<span class="oko-shed-content-label">Levering: </span>
				<span class="oko-shed-content-value">
					{selectedDeliveryDate &&
						formatDate(selectedDeliveryDate, locale)}
				</span>
			</div>
		</div>

		<a
			href={'#'}
			on:click={handleOpenModal}
			class="button"
			style="margin-bottom: 20px;"
		>
			Vælg Økoskab
		</a>
	{/if}
{/await}

<style>
	.modal {
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

	.hidden {
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

	.description {
		font-weight: normal;
		line-height: 1.1;
		font-size: 80%;
		margin-bottom: 20px;
	}

	.skeleton-container {
		margin-bottom: 16px;
	}

	.skeleton-loader {
		width: 100%;
		height: 48px;
		display: block;
		background: linear-gradient(
				to right,
				rgba(255, 255, 255, 0),
				rgba(255, 255, 255, 0.5) 50%,
				rgba(255, 255, 255, 0) 80%
			),
			gainsboro;
		background-repeat: repeat-y;
		background-size: 50px 500px;
		background-position: 0 0;
		animation: shine 1s infinite;
	}

	@keyframes shine {
		to {
			background-position:
				100% 0,
				/* move highlight to right */ 0 0;
		}
	}
</style>
