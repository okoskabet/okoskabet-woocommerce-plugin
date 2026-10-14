<?php

/**
 * An order placed as an addition to an earlier one.
 *
 * Gaardmester's customers order something extra for a delivery already booked,
 * and write the earlier order's number in the checkout. Nothing connected the
 * two: the addition arrived at Økoskabet as an order of its own, and in the
 * shop it was a loose order that their own shipping integration picked up and
 * sent as a separate parcel.
 */
class AddonOrderTest extends \Codeception\TestCase\WPTestCase
{
	/** The shop asks already: it keeps its own field. */
	public function test_a_checkout_that_already_asks_is_left_alone(): void
	{
		$fields = custom_override_checkout_fields(array('billing' => array(
			'billing_order_id' => array('label' => 'Tillægsordre'),
		)));

		$this->assertArrayNotHasKey('billing_okoskabet_addon_order', $fields['billing']);
	}

	/** A shop without one is given ours, so the question is asked everywhere. */
	public function test_a_checkout_that_does_not_is_given_our_field(): void
	{
		$fields = custom_override_checkout_fields(array('billing' => array()));

		$this->assertArrayHasKey('billing_okoskabet_addon_order', $fields['billing']);
	}

	/**
	 * A fresh checkout starts without the last order's number.
	 *
	 * WooCommerce gives a custom checkout field back on the customer's next
	 * visit, which for an address is a kindness and for this one is a trap: the
	 * next ordinary order would open with the last one's number already in it
	 * and ride along with a delivery nobody meant to add to, for no freight.
	 * Seen on Gaardmester's staging, where a fresh checkout opened with 87893.
	 */
	public function test_a_fresh_checkout_does_not_remember_the_last_addition(): void
	{
		foreach (array('billing_order_id', 'billing_okoskabet_addon_order') as $field) {
			$this->assertSame(
				'',
				apply_filters('woocommerce_checkout_get_value', '87893', $field),
				$field . ' should not come back on the next checkout.'
			);
		}
	}

	/** An address, though, is worth remembering. */
	public function test_a_fresh_checkout_still_remembers_the_address(): void
	{
		$this->assertSame(
			'Korsløkkevej 51',
			apply_filters('woocommerce_checkout_get_value', 'Korsløkkevej 51', 'billing_address_1')
		);
	}

	/** The shop's own field is read. */
	public function test_the_shops_own_field_is_read(): void
	{
		$order = new \WC_Order();
		$order->update_meta_data('_billing_order_id', '88311');
		$order->save();

		$this->assertSame('88311', oko_order_addon_of($order));
	}

	/** And ours, for the shops without one. */
	public function test_our_own_field_is_read(): void
	{
		$order = new \WC_Order();
		$order->update_meta_data('_billing_okoskabet_addon_order', '88311');
		$order->save();

		$this->assertSame('88311', oko_order_addon_of($order));
	}

	/** An ordinary order says nothing. */
	public function test_an_ordinary_order_is_not_an_addition(): void
	{
		$order = new \WC_Order();
		$order->save();

		$this->assertSame('', oko_order_addon_of($order));
	}

	/**
	 * Both orders are written on, so either one shows the other. This is what
	 * the shop sees; remove it and an addition is a loose order again.
	 */
	public function test_both_orders_are_written_on(): void
	{
		$earlier = new \WC_Order();
		$earlier->save();

		$addition = new \WC_Order();
		$addition->update_meta_data('_billing_order_id', (string) $earlier->get_id());
		$addition->save();

		oko_link_addon_to_earlier_order($addition->get_id(), array(), $addition);

		$this->assertNotEmpty(
			$this->notes_matching($addition, (string) $earlier->get_id()),
			'The addition should say which order it belongs to.'
		);
		$this->assertNotEmpty(
			$this->notes_matching($earlier, (string) $addition->get_id()),
			'The earlier order should say an addition belongs to it.'
		);
	}

	/** A number that matches nothing still leaves the customer's word on the order. */
	public function test_an_unknown_number_is_still_written_down(): void
	{
		$addition = new \WC_Order();
		$addition->update_meta_data('_billing_order_id', '99999999');
		$addition->save();

		oko_link_addon_to_earlier_order($addition->get_id(), array(), $addition);

		$this->assertNotEmpty($this->notes_matching($addition, '99999999'));
	}

	/** @return array<int,\WP_Comment> */
	private function notes_matching(\WC_Order $order, string $needle): array
	{
		$notes = wc_get_order_notes(array('order_id' => $order->get_id()));

		return array_values(array_filter($notes, static function ($note) use ($needle) {
			return strpos((string) $note->content, $needle) !== false;
		}));
	}
}
