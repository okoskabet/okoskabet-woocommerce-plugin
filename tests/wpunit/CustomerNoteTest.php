<?php

/**
 * The note field belongs to the customer.
 *
 * Sending an order to Økoskabet used to write a line of our own in front of
 * whatever the customer had written ("ØKOSKABET 2026-10-01 Hjemmelevering").
 * It showed up on the customer's order confirmation, where it means nothing to
 * them, and it pushed the customer's own message out of sight on the packing
 * slip the shop prints. Orders placed back then still carry it, so reading a
 * note has to cope with both.
 */
class CustomerNoteTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );
	}

	private function order_with_note( string $note ): \WC_Order {
		$order = new \WC_Order();
		$order->set_customer_note( $note );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	public function test_a_note_the_customer_wrote_is_returned_untouched() {
		$order = $this->order_with_note( "Ring på, jeg er hjemme hele dagen" );

		$this->assertSame( 'Ring på, jeg er hjemme hele dagen', $order->get_customer_note() );
		$this->assertSame( 'Ring på, jeg er hjemme hele dagen', oko_customer_note_as_written( $order ) );
	}

	public function test_an_old_order_reads_as_the_customer_left_it() {
		$order = $this->order_with_note( "ØKOSKABET 2026-10-01 Hjemmelevering\nRing på" );

		$this->assertSame(
			'Ring på',
			$order->get_customer_note(),
			'the packing slip asks the order, so the order has to answer with the customer text'
		);
		$this->assertSame( 'Ring på', oko_customer_note_as_written( $order ) );
	}

	public function test_an_old_order_whose_customer_wrote_nothing_reads_empty() {
		$order = $this->order_with_note( 'ØKOSKABET 2026-10-01 Hjemmelevering' );

		$this->assertSame( '', $order->get_customer_note() );
	}

	public function test_a_note_that_merely_mentions_us_is_left_alone() {
		$order = $this->order_with_note( 'Stil den ved ØKOSKABET på hjørnet' );

		$this->assertSame(
			'Stil den ved ØKOSKABET på hjørnet',
			$order->get_customer_note(),
			'only our own line, at the very start, is ours to remove'
		);
	}

	/**
	 * WooCommerce reads the note in view context on its way to storage: the
	 * posts data store does it on every status change, HPOS does it when it
	 * syncs. A filter meant for the eye must not become a rewrite of the row.
	 */
	public function test_saving_an_order_does_not_rewrite_the_stored_note() {
		$order = $this->order_with_note( "ØKOSKABET 2026-10-01 Hjemmelevering\nRing på" );

		$order->update_status( 'completed' );

		$this->assertStringContainsString(
			'ØKOSKABET 2026-10-01',
			wc_get_order( $order->get_id() )->get_customer_note( 'edit' ),
			'a status change must not delete the old line for good'
		);
	}

	/**
	 * A customer who writes about the shed on the corner starts their note
	 * with the same word we did.
	 */
	public function test_a_customer_line_that_starts_with_our_word_is_kept() {
		$order = $this->order_with_note( "ØKOSKABET ved Netto er fint\nRing på" );

		$this->assertSame(
			"ØKOSKABET ved Netto er fint\nRing på",
			$order->get_customer_note(),
			'only the line we wrote looks like the line we wrote'
		);
	}

	/**
	 * The stored value is untouched, so nothing is lost: a shop that wants the
	 * old line back only has to stop filtering.
	 */
	public function test_nothing_is_thrown_away_on_the_way_in() {
		$order = $this->order_with_note( "ØKOSKABET 2026-10-01 Hjemmelevering\nRing på" );

		$this->assertStringContainsString(
			'ØKOSKABET 2026-10-01',
			$order->get_customer_note( 'edit' ),
			'the edit context reads past the filter, which is where the stored value shows'
		);
	}
}
