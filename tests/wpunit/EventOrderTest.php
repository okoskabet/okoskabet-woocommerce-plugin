<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * The steps a shop picks from, in the order an order passes them.
 *
 * "Label printed" used to be written in front of the whole list, which put it
 * before "Registered" and read as though a label were printed before the
 * order existed (Marc, 20 October 2026).
 */
class EventOrderTest extends \Codeception\TestCase\WPTestCase
{
	use ConfiguresMerchant;

	public function setUp(): void
	{
		parent::setUp();
		$this->a_merchant_with_an_account();
	}

	public function tearDown(): void
	{
		$this->forget_the_merchant();
		parent::tearDown();
	}

	/** Printing the label comes after the order is registered. */
	public function test_the_label_comes_after_the_order_is_registered(): void
	{
		$this->okoskabet_publishes(array(
			array('code' => 'registered', 'label' => 'Registreret'),
			array('code' => 'ready_for_dispatch', 'label' => 'Klar til afsendelse'),
			array('code' => 'received', 'label' => 'Modtaget'),
			array('code' => 'in_shed', 'label' => 'I skabet'),
			array('code' => 'fulfilled', 'label' => 'Udleveret'),
			array('code' => 'removed', 'label' => 'Fjernet'),
		));

		$this->assertSame(
			array('registered', 'label_printed', 'ready_for_dispatch', 'received', 'in_shed', 'fulfilled', 'removed'),
			array_keys(oko_merchant_events('default'))
		);
	}

	/**
	 * A step Økoskabet adds later keeps its place.
	 *
	 * The list is read off the merchant's own account, so it can grow without
	 * a plugin release; the label goes after `registered` wherever that is,
	 * not at a counted position.
	 */
	public function test_a_new_step_from_okoskabet_keeps_its_place(): void
	{
		$this->okoskabet_publishes(array(
			array('code' => 'registered', 'label' => 'Registreret'),
			array('code' => 'sorted', 'label' => 'Sorteret'),
			array('code' => 'fulfilled', 'label' => 'Udleveret'),
		));

		$this->assertSame(
			array('registered', 'label_printed', 'sorted', 'fulfilled'),
			array_keys(oko_merchant_events('default'))
		);
	}

	/** And a list without it keeps the label first, rather than losing it. */
	public function test_a_list_without_registered_keeps_the_label(): void
	{
		$this->okoskabet_publishes(array(
			array('code' => 'received', 'label' => 'Modtaget'),
			array('code' => 'fulfilled', 'label' => 'Udleveret'),
		));

		$this->assertSame(
			array('label_printed', 'received', 'fulfilled'),
			array_keys(oko_merchant_events('default'))
		);
	}

	/** @param array<int,array<string,string>> $statuses */
	private function okoskabet_publishes(array $statuses): void
	{
		$this->okoskabet_configuration(array('statuses' => $statuses));
	}

}
