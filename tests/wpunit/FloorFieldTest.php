<?php

/**
 * The flat, floor and door a customer types, and the shops that have nowhere
 * to type it.
 *
 * gaardmester.dk's checkout has no second address line at all, so every
 * customer in a block of flats was sent to Økoskabet without a floor — which
 * the address register refuses outright, and the order never became a
 * shipment.
 */
class FloorFieldTest extends \Codeception\TestCase\WPTestCase
{
	/** The shop has no second address line: we ask for the floor ourselves. */
	public function test_a_checkout_without_an_address_line_2_is_given_our_own_field(): void
	{
		$fields = custom_override_checkout_fields(array('billing' => array(
			'billing_address_1' => array('label' => 'Gadenavn og nr'),
		)));

		$this->assertArrayHasKey(
			'billing_okoskabet_floor',
			$fields['billing'],
			'A checkout with nowhere to write the floor should be given our field.'
		);
	}

	/** The shop asks already: it keeps its own field and we add nothing. */
	public function test_a_checkout_with_an_address_line_2_is_left_alone(): void
	{
		$fields = custom_override_checkout_fields(array('billing' => array(
			'billing_address_1' => array('label' => 'Gadenavn og nr'),
			'billing_address_2' => array('label' => 'Lejlighed, etage og dør'),
		)));

		$this->assertArrayNotHasKey(
			'billing_okoskabet_floor',
			$fields['billing'],
			'A shop that already asks must not ask the customer the same thing twice.'
		);
	}

	/**
	 * Decided on the fields the customer will actually see.
	 *
	 * A shop that takes its second address line away at a later priority than
	 * ours used to leave the customer with neither: we saw it there, added
	 * nothing, and it was then removed. Run through the real filter, with such
	 * a shop, so the priority is what is being tested and not our own call.
	 */
	public function test_a_shop_that_removes_its_line_after_us_still_gets_ours(): void
	{
		add_filter('woocommerce_checkout_fields', static function (array $fields): array {
			unset($fields['billing']['billing_address_2']);
			return $fields;
		}, 20);

		$fields = apply_filters('woocommerce_checkout_fields', array('billing' => array(
			'billing_address_1' => array('label' => 'Gadenavn og nr'),
			'billing_address_2' => array('label' => 'Lejlighed, etage og dør'),
		)));

		$this->assertArrayNotHasKey('billing_address_2', $fields['billing'], 'The shop still removes its own.');
		$this->assertArrayHasKey(
			'billing_okoskabet_floor',
			$fields['billing'],
			'And ours takes its place, instead of the customer having neither.'
		);
	}

	/** The shop's own second line is where the customer wrote it. */
	public function test_the_shops_own_address_line_2_is_what_is_sent(): void
	{
		$order = new \WC_Order();
		$order->set_shipping_address_2('2. tv');
		$order->update_meta_data('_billing_okoskabet_floor', '3. th');
		$order->save();

		$this->assertSame('2. tv', oko_order_floor($order));
	}

	/**
	 * Without one, our own field answers. This is the whole point: remove the
	 * fallback and gaardmester.dk sends no floor at all.
	 */
	public function test_our_own_field_answers_when_the_shop_has_no_address_line_2(): void
	{
		$order = new \WC_Order();
		$order->update_meta_data('_billing_okoskabet_floor', '3. th');
		$order->save();

		$this->assertSame('3. th', oko_order_floor($order));
	}

	/** Neither: an empty string, which `array_filter` drops from the payload. */
	public function test_an_address_with_no_floor_at_all_stays_empty(): void
	{
		$order = new \WC_Order();
		$order->save();

		$this->assertSame('', oko_order_floor($order));
	}

	/** A customer's stray spaces are not part of the address. */
	public function test_surrounding_space_is_not_sent_on(): void
	{
		$order = new \WC_Order();
		$order->update_meta_data('_billing_okoskabet_floor', '  3. th  ');
		$order->save();

		$this->assertSame('3. th', oko_order_floor($order));
	}
}
