<script lang="ts">
	import { callApi } from './api';
	import { formatDate } from './format-date';

	export let locale: string;
	export let description: string;
	export let address: string;
	export let postalCode: string;
	export let onSelectDeliveryDate: (selectedDate: string) => void;

	let selectedDeliveryDate: string | undefined;

	$: {
		if (selectedDeliveryDate) {
			onSelectDeliveryDate(selectedDeliveryDate);
		}
	}

	$: apiResponse = callApi('home-delivery', address, postalCode);
</script>

<div>
	<div class="description">
		{description}
	</div>

	{#await apiResponse}
		<span class="skeleton-loader"></span>
	{:then response}
		{#if response.delivery_dates.length === 0}
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
