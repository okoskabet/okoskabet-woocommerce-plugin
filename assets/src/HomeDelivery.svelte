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

{#await apiResponse}
	<div>Loading...</div>
{:then { delivery_dates: deliveryDates }}
	<div>
		<div class="description">
			{description}
		</div>

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
	</div>
{/await}

<style>
	.description {
		font-weight: normal;
		line-height: 1.1;
		font-size: 80%;
		margin-bottom: 20px;
	}
</style>
