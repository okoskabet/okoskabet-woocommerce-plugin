<script lang="ts">
	import { callApi } from './api';
	import { formatDate } from './format-date';
	import type { DateMode, HomeDeliveryResponse } from './types';

	export let locale: string;
	export let description: string;
	export let address: string;
	export let postalCode: string;
	export let initialDeliveryDate: string | undefined = undefined;
	export let dateMode: DateMode = 'required';
	export let onSelectDeliveryDate: (selectedDate: string) => void;

	// Starting from the date the customer already chose, rather than from
	// nothing. Left empty, the date list would pick its own first option the
	// moment it appears, and every recalculation of the checkout would quietly
	// move the delivery to the soonest day.
	let selectedDeliveryDate: string | undefined = initialDeliveryDate;

	$: {
		if (selectedDeliveryDate) {
			onSelectDeliveryDate(selectedDeliveryDate);
		}
	}

	// With no date at checkout there is nothing to ask Økoskabet; the order is
	// given a day by hand after it has been placed.
	$: apiResponse =
		dateMode === 'never'
			? Promise.resolve<HomeDeliveryResponse>({
					type: 'home-delivery',
					origin: null,
					delivery_dates: [],
				})
			: callApi('home-delivery', address, postalCode);

	$: if (dateMode === 'never') {
		selectedDeliveryDate = undefined;
		onSelectDeliveryDate('');
	}

	// A date that was on offer before the recalculation may not be any more —
	// a new postcode, a different cart. Keep it only while it still is, and
	// fall back to the soonest date otherwise, as the list always did. With no
	// dates at all the choice is cleared, so an order cannot go out on a day
	// that was never offered for this address.
	$: keepChosenDateIfStillOffered(apiResponse);

	async function keepChosenDateIfStillOffered(
		response: typeof apiResponse
	) {
		let deliveryDates: string[];
		try {
			({ delivery_dates: deliveryDates } = await response);
		} catch {
			return;
		}

		// A newer lookup has started since this one; let it decide.
		if (response !== apiResponse) {
			return;
		}

		if (selectedDeliveryDate && !deliveryDates.includes(selectedDeliveryDate)) {
			selectedDeliveryDate = deliveryDates[0];
			if (!selectedDeliveryDate) {
				onSelectDeliveryDate('');
			}
		}
	}
</script>

<div>
	<div class="description">
		{description}
	</div>

	{#await apiResponse}
		<span class="skeleton-loader"></span>
	{:then response}
		{#if response.delivery_dates.length === 0 && dateMode !== 'required'}
			<p class="oko-without-date">
				Leveringsdagen aftales efter bestillingen – vi kontakter dig.
			</p>
		{:else if response.delivery_dates.length === 0}
			{#if response.exceptions_explanation && response.exceptions_explanation.has_exceptions}
				<div class="oko-no-dates-explained">
					<p class="oko-no-dates-headline">{response.exceptions_explanation.summary}</p>
					<ul class="oko-no-dates-list">
						{#each response.exceptions_explanation.product_rules as pr}
							{#if pr.rules.length > 0}
								<li><strong>{pr.product_name}</strong> — {pr.rules.join('; ')}</li>
							{/if}
						{/each}
					</ul>
					<p class="oko-no-dates-help">Du kan fjerne en eller flere af de markerede varer fra kurven for at få flere leveringsmuligheder, eller kontakte os for hjælp.</p>
				</div>
			{:else}
				<span>Ingen tilgængelige datoer.</span>
			{/if}
		{:else}
			<div class="oko-select-headline" style="font-size: 14px;">
				Leveringsdato
			</div>
			<select
				bind:value={selectedDeliveryDate}
				name="okoDeliveryDates"
				style="width: 100%; margin-bottom: 20px;"
			>
				{#each response.delivery_dates as deliveryDate}
					<option value={deliveryDate}>
						{formatDate(deliveryDate, locale)}
					</option>
				{/each}
			</select>
		{/if}
	{/await}
</div>

<style>
	.description {
		font-weight: normal;
		line-height: 1.1;
		font-size: 80%;
		margin-bottom: 20px;
	}

	.oko-without-date {
		margin: 8px 0 16px;
	}

	.oko-no-dates-explained {
		background: #fff5f5;
		border: 1px solid #f0c0c0;
		border-left: 4px solid #c44;
		padding: 12px 14px;
		margin: 8px 0 16px;
		border-radius: 3px;
	}
	.oko-no-dates-headline {
		font-weight: 600;
		margin: 0 0 8px;
	}
	.oko-no-dates-list {
		margin: 0 0 8px;
		padding-left: 20px;
	}
	.oko-no-dates-list li {
		margin-bottom: 4px;
	}
	.oko-no-dates-help {
		margin: 8px 0 0;
		font-size: 0.9em;
		color: #555;
	}

	.skeleton-loader {
		width: 100%;
		height: 26px;
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
