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
			'billing_address_2',
			$fields['billing'],
			'A checkout with nowhere to write the floor should be given our field.'
		);
	}

	/** The shop asks already: its own wording is left exactly as it is. */
	public function test_a_checkout_with_an_address_line_2_is_left_alone(): void
	{
		$fields = custom_override_checkout_fields(array('billing' => array(
			'billing_address_1' => array('label' => 'Gadenavn og nr'),
			'billing_address_2' => array('label' => 'Lejlighed, etage og dør'),
		)));

		$this->assertSame(
			'Lejlighed, etage og dør',
			$fields['billing']['billing_address_2']['label'],
			"A shop that already asks keeps its own wording."
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

		$this->assertArrayHasKey('billing_address_2', $fields['billing'], 'Ours is put back after theirs is removed.');
		$this->assertArrayHasKey(
			'billing_address_2',
			$fields['billing'],
			'And ours takes its place, instead of the customer having neither.'
		);
		$this->assertSame('Flat, floor and door', $fields['billing']['billing_address_2']['label']);
	}




}
