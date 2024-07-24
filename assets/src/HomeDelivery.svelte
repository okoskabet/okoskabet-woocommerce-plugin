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

	<div class="oko-select-headline" style="font-size: 14px;">
		Leveringsdato
	</div>
	{#await apiResponse}
		<span class="skeleton-loader"></span>
	{:then { delivery_dates: deliveryDates }}
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
	{/await}
</div>

<style>
	.description {
		font-weight: normal;
		line-height: 1.1;
		font-size: 80%;
		margin-bottom: 20px;
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
