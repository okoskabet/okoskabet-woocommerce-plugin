<?php

/**
 * Looking an order up by its number, from the checkout, changes nothing.
 *
 * The add-on field in the checkout asks "which order is this number" for
 * anybody filling it in, on every recalculation and before the email is
 * checked. The lookup used to stamp the reference onto every older order it
 * found that way — a write to somebody else's order on every keystroke.
 *
 * And an addition is never the order a number names: it is marked as sent,
 * but its goods went onto another order's shipment.
 */
class LookupWithoutSideEffectsTest extends \Codeception\TestCase\WPTestCase
{
	public function test_the_checkout_lookup_writes_nothing_on_the_order(): void
	{
		$order = $this->booked_before_references_were_kept('someone@example.com');

		oko_addon_parent_order((string) $order->get_order_number(), 'nobody@example.com');

		$this->assertSame('', (string) wc_get_order($order->get_id())->get_meta(OKO_SHIPMENT_REFERENCE_META, true));
	}

	/** The webhook, which is Økoskabet itself, still keeps the reference. */
	public function test_the_webhook_lookup_still_keeps_the_reference(): void
	{
		$order = $this->booked_before_references_were_kept('someone@example.com');

		$found = oko_order_for_shipment_reference((string) $order->get_order_number());

		$this->assertInstanceOf(\WC_Order::class, $found);
		$this->assertSame(
			(string) $order->get_order_number(),
			(string) wc_get_order($order->get_id())->get_meta(OKO_SHIPMENT_REFERENCE_META, true)
		);
	}

	public function test_an_addition_is_not_found_by_its_own_number(): void
	{
		$addition = $this->booked_before_references_were_kept('someone@example.com');
		$addition->update_meta_data(OKO_ADDON_PARENT_ID_META, '12345');
		$addition->save();

		$found = oko_order_for_shipment_reference((string) $addition->get_order_number());

		$this->assertInstanceOf(\WP_Error::class, $found);
	}

	/** Booked by 1.5.2 or older: marked as sent, no reference kept. */
	private function booked_before_references_were_kept(string $email): \WC_Order
	{
		$order = wc_create_order();
		$order->set_billing_email($email);
		$order->update_meta_data('billing_okoskabet_done', 'yes');
		$order->update_meta_data('_billing_okoskabet_delivery_date', gmdate('Y-m-d', strtotime('+5 days')));
		$order->save();

		return wc_get_order($order->get_id());
	}
}
