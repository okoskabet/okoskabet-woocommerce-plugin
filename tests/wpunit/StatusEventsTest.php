<?php

/**
 * The steps a shop can hang its own automation on.
 *
 * The choices used to be three names the plugin made up, and two of them lied:
 * "In Shed" fired when the customer had the goods, and "Order Delivered"
 * waited for a status Økoskabet has never sent — so a shop that chose it, and
 * it was the default, was never charged automatically at all. The list now
 * comes from the shop's own account, and what a shop already ticked has to
 * keep meaning what the shop thought it meant.
 */
class StatusEventsTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );
	}

	public function test_the_three_old_names_all_meant_the_customer_has_it() {
		$this->assertSame(
			array( 'fulfilled' ),
			oko_status_events_as_chosen( array( 'in_shed' ) ),
			'"In Shed" fired on fulfilled, whatever the label said'
		);

		$this->assertSame(
			array( 'fulfilled' ),
			oko_status_events_as_chosen( array( 'order_delivered' ) ),
			'and "Order Delivered" was waiting for a status that never comes'
		);

		$this->assertSame(
			array( 'fulfilled' ),
			oko_status_events_as_chosen( array( 'label_created' ) ),
			'the name before "In Shed" meant the same again'
		);
	}

	public function test_printing_the_label_is_left_alone() {
		$this->assertSame(
			array( 'label_printed' ),
			oko_status_events_as_chosen( array( 'label_printed' ) ),
			'it was the one choice that always did what it said'
		);
	}

	public function test_two_old_names_do_not_become_the_same_step_twice() {
		$this->assertSame(
			array( 'label_printed', 'fulfilled' ),
			oko_status_events_as_chosen( array( 'label_printed', 'in_shed', 'order_delivered' ) )
		);
	}

	/**
	 * A step Økoskabet adds after this release has to survive being saved.
	 * The whole point of reading the list from the account is lost if the
	 * plugin drops what it does not recognise.
	 */
	public function test_a_step_this_release_has_never_heard_of_is_kept() {
		$this->assertSame(
			array( 'ready_for_dispatch', 'collected_by_courier' ),
			oko_status_events_as_chosen( array( 'ready_for_dispatch', 'collected_by_courier' ) )
		);
	}

	/**
	 * An account whose Økoskabet does not publish the list yet still has to
	 * show a settings screen, so the steps that existed when this was written
	 * stand in for it.
	 */
	public function test_the_fallback_list_covers_an_older_okoskabet() {
		$statuses = oko_merchant_statuses( 'a-merchant-with-no-key' );

		$this->assertSame(
			array( 'registered', 'ready_for_dispatch', 'received', 'in_shed', 'fulfilled', 'removed' ),
			array_keys( $statuses )
		);

		$this->assertNotSame( '', $statuses['fulfilled'], 'every step needs something to call it' );
	}
}
