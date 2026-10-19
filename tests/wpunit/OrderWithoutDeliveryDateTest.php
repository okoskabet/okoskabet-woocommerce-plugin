<?php

/**
 * An order of ours that has lost its delivery date.
 *
 * The checkout will not submit a home delivery without a date, so an order
 * that reaches the send path without one was not born in the checkout — a
 * renewal, an import, an order typed in the admin. It used to be dropped at
 * the first gate, and the drop was silent: no note on the order, no line in
 * the log, nothing at Økoskabet. Gaardmester's order 88587 of 17 October 2026
 * went that way. Nobody could have found it; the customer asked where the food
 * was.
 *
 * It now goes without a date and lands among Økoskabet's unprocessable
 * shipments, where somebody gives it one.
 */
class OrderWithoutDeliveryDateTest extends \Codeception\TestCase\WPTestCase
{
	/** The whole point: an order of ours with no date is still sent. */
	public function test_our_order_without_a_date_is_still_sent(): void
	{
		$payload = oko_shipment_payload($this->order('hey_okoskabet_shipping_home'), $this->merchant());

		$this->assertNotNull($payload, 'An order of ours must not be dropped for want of a date.');
	}

	/**
	 * And it carries no date at all rather than an empty one.
	 *
	 * An empty string is a date as far as the other end is concerned, and it
	 * fails validation instead of landing where a person can fix it. Leaving
	 * the key out is what puts it among the unprocessable shipments.
	 */
	public function test_it_is_sent_with_no_date_rather_than_an_empty_one(): void
	{
		$payload = oko_shipment_payload($this->order('hey_okoskabet_shipping_home'), $this->merchant());

		$this->assertArrayNotHasKey('delivery_date', $payload);
	}

	/** A shed delivery that lost its date is ours just the same. */
	public function test_a_shed_delivery_without_a_date_is_sent_too(): void
	{
		$this->assertNotNull(
			oko_shipment_payload($this->order('hey_okoskabet_shipping_shed'), $this->merchant())
		);
	}

	/** As is a collection from the shop. */
	public function test_a_store_pickup_without_a_date_is_sent_too(): void
	{
		$this->assertNotNull(
			oko_shipment_payload($this->order('hey_okoskabet_shipping_store_pickup'), $this->merchant())
		);
	}

	/**
	 * An order the shop ships itself is left alone.
	 *
	 * This is the half that keeps the fix from being worse than the problem.
	 * Gaardmester runs their own Shipmondo beside us; forwarding those would
	 * fill the packing room's list with orders it must not touch, every one of
	 * them needing a human to throw it away again.
	 */
	public function test_an_order_the_shop_ships_itself_is_not_sent(): void
	{
		$this->assertNull(
			oko_shipment_payload($this->order('flat_rate'), $this->merchant()),
			"An order with none of our shipping methods is not ours to forward."
		);
	}

	/** An order with no shipping line at all is nobody's. */
	public function test_an_order_with_no_shipping_at_all_is_not_sent(): void
	{
		$this->assertNull(oko_shipment_payload($this->order(null), $this->merchant()));
	}

	/** A date, when there is one, is still what is sent. */
	public function test_a_date_is_sent_when_the_order_has_one(): void
	{
		$order = $this->order('hey_okoskabet_shipping_home');
		$order->update_meta_data('_billing_okoskabet_delivery_date', '2026-10-21');
		$order->save();

		$payload = oko_shipment_payload(wc_get_order($order->get_id()), $this->merchant());

		$this->assertSame('2026-10-21', $payload['delivery_date']);
	}

	/** @return array<string,mixed> */
	private function merchant(): array
	{
		return array('id' => 'default', 'api_key' => 'test-key', 'staging' => true);
	}

	/** An order with goods and one shipping line, and no delivery date on it. */
	private function order(?string $method_id): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Økologisk ribbensteg');
		$product->set_regular_price('199');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);

		if ($method_id !== null) {
			$shipping = new \WC_Order_Item_Shipping();
			$shipping->set_method_id($method_id);
			$shipping->set_method_title('Levering');
			$shipping->set_total('99');
			$order->add_item($shipping);
		}

		$order->set_billing_first_name('Birgitte');
		$order->set_billing_last_name('Bennedsen');
		$order->set_shipping_address_1('Kildeparken 98');
		$order->set_shipping_postcode('6000');
		$order->set_shipping_city('Kolding');
		$order->calculate_totals(false);
		$order->save();

		return wc_get_order($order->get_id());
	}
}
