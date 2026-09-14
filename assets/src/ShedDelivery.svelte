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
	export let initialShedId: string | undefined = undefined;
	export let initialDeliveryDate: string | undefined = undefined;
	export let initialShowOptions = false;
	export let onToggleOptions: (open: boolean) => void = () => undefined;

	// Starting from the shed and date the customer already chose. Left empty,
	// both lists pick their own first option when they appear, and every
	// recalculation of the checkout would quietly move the customer to the
	// first shed and its soonest day. selectDeliveryDate() below already keeps
	// a date that the shed still offers, so the date only needs a start value.
	let selectedShedId: string | undefined = initialShedId;
	let selectedDeliveryDate: string | undefined = initialDeliveryDate;
	let showOptions = displayMode === 'inline' || initialShowOptions;

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

	// A shed chosen before the recalculation may not be offered any more — a
	// new postcode brings a different list. Fall back to the first shed, as the
	// list always did, so the date is then re-checked against that shed. With
	// no sheds at all both choices are cleared, so an order cannot go out to a
	// shed that was never offered for this address.
	$: keepChosenShedIfStillOffered(apiResponse);

	async function keepChosenShedIfStillOffered(response: typeof apiResponse) {
		let sheds: { id: string }[];
		try {
			({ sheds } = await response);
		} catch {
			return;
		}

		// A newer lookup has started since this one; let it decide.
		if (response !== apiResponse) {
			return;
		}

		if (selectedShedId && !sheds.some((shed) => shed.id === selectedShedId)) {
			selectedShedId = sheds[0]?.id;
			if (!selectedShedId) {
				onSelectShed('');
				selectedDeliveryDate = undefined;
				onSelectDeliveryDate('');
			}
		}
	}

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
		onToggleOptions(true);
	}

	function handleCloseModal(e: Event) {
		e.preventDefault();
		showOptions = false;
		onToggleOptions(false);
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
	{@const selectedShed =
		sheds.find((shed) => shed.id === selectedShedId) || sheds[0]}
	<div class={displayMode} class:hidden={!showOptions}>
		<div class="description">
			{description}
		</div>

		{#if selectedShed.delivery_dates.length === 0}
			<span>Ingen tilgængelige datoer.</span>
		{:else}
			<div class="oko-select-headline" style="font-size: 14px;">
				Økoskab
			</div>
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
				{#each selectedShed.delivery_dates as deliveryDate}
					<option value={deliveryDate}>
						{formatDate(deliveryDate, locale)}
					</option>
				{/each}
			</select>

			<Map {sheds} {origin} bind:selectedShedId />

			{#if displayMode === 'modal'}
				<div class="okoButtonModal okoButtonModalDone">
					<div class="okoButtonModalContent"></div>
					<a href={'#'} class="button" on:click={handleCloseModal}
						>Done</a
					>
				</div>
			{/if}
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
