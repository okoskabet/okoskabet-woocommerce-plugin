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
	/**
	 * A shop that has asked for the field. Every test below is about how it is
	 * drawn once asked for; whether it is asked for at all is its own test.
	 */
	public function setUp(): void
	{
		parent::setUp();
		add_filter('o_get_settings', static function ($settings) {
			return array_merge((array) $settings, array('_ask_for_floor' => 'on'));
		});
	}

	public function tearDown(): void
	{
		remove_all_filters('o_get_settings');
		parent::tearDown();
	}

	/**
	 * And a shop that has not asked is left exactly as it is.
	 *
	 * gaardmester.dk has no second address line in their checkout and delivers
	 * to blocks of flats anyway: their customers write the floor on the street
	 * line, and Økoskabet's address client lifts it out of there. A field they
	 * did not ask for repairs nothing and gives a customer a second place to
	 * write one thing — written in both, the register refuses the address.
	 */
	public function test_a_shop_that_has_not_asked_is_left_alone(): void
	{
		remove_all_filters('o_get_settings');

		$fields = custom_override_checkout_fields(array(
			'billing'  => array('billing_address_1' => array('label' => 'Gadenavn og nr')),
			'shipping' => array('shipping_address_1' => array('label' => 'Gadenavn og nr')),
		));

		$this->assertArrayNotHasKey('billing_address_2', $fields['billing']);
		$this->assertArrayNotHasKey('shipping_address_2', $fields['shipping']);
	}

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
	 * The shipping form gets it too, or the floor never reaches the delivery.
	 *
	 * WooCommerce copies billing to shipping only for the fields the shipping
	 * form has. gaardmester.dk removed the second line from both, so an order
	 * carried "3 th" on the invoice address and nothing on the delivery
	 * address — and the register refused the block of flats exactly as before.
	 * Order 87964 on their staging, measured: billing "3 th", shipping empty.
	 */
	public function test_the_shipping_form_is_given_one_as_well(): void
	{
		$fields = custom_override_checkout_fields(array(
			'billing'  => array('billing_address_1' => array('label' => 'Gadenavn og nr')),
			'shipping' => array('shipping_address_1' => array('label' => 'Gadenavn og nr')),
		));

		$this->assertArrayHasKey('shipping_address_2', $fields['shipping']);
	}

	/** The delivery address is what counts, when the customer gave one. */
	public function test_the_delivery_address_wins(): void
	{
		$order = new \WC_Order();
		$order->set_shipping_address_2('2. tv');
		$order->set_billing_address_2('3. th');
		$order->save();

		$this->assertSame('2. tv', oko_second_address_line($order));
	}

	/**
	 * But not when the parcel is going somewhere else entirely.
	 *
	 * The fallback is for one address written once, where only the invoice
	 * form had room for the floor. An order sent to a workplace across town is
	 * a different building: carrying "2. sal" over to it is a flat that is not
	 * there, which the register refuses — or, worse, one that is.
	 */
	public function test_the_invoice_floor_does_not_follow_to_another_address(): void
	{
		$order = new \WC_Order();
		$order->set_billing_address_1('Korsløkkevej 51');
		$order->set_billing_postcode('5220');
		$order->set_billing_address_2('2. sal');
		$order->set_shipping_address_1('Eksempelvej 12');
		$order->set_shipping_postcode('8330');
		$order->save();

		$this->assertSame('', oko_second_address_line($order));
	}

	/** Same street written a little differently is still the same street. */
	public function test_the_same_address_typed_loosely_still_answers(): void
	{
		$order = new \WC_Order();
		$order->set_billing_address_1('  Korsløkkevej   51 ');
		$order->set_billing_postcode('5220');
		$order->set_billing_address_2('3 th');
		$order->set_shipping_address_1('Korsløkkevej 51');
		$order->set_shipping_postcode('5220');
		$order->save();

		$this->assertSame('3 th', oko_second_address_line($order));
	}

	/**
	 * And the invoice address answers when there is no delivery one — which is
	 * every order in a shop whose checkout has no second shipping line.
	 */
	public function test_the_invoice_address_answers_when_there_is_no_delivery_one(): void
	{
		$order = new \WC_Order();
		$order->set_billing_address_2('3 th');
		$order->save();

		$this->assertSame('3 th', oko_second_address_line($order));
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
