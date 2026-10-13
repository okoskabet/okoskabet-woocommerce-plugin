<?php

/**
 * Whether Økoskabet can reach this shop at all, as the shop can see it.
 *
 * The plugin never sees a call that is stopped in front of WordPress — at
 * Gaardmester the product lookup answers 403 from something before us, and
 * neither side notices. So the plugin cannot report the blockage. What it can
 * do is say when a call last got through, and let "never" speak for itself.
 */
class InboundContactTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );
		delete_option( OKO_INBOUND_SEEN_OPTION );
	}

	public function tearDown(): void {
		delete_option( OKO_INBOUND_SEEN_OPTION );
		parent::tearDown();
	}

	public function test_nothing_has_arrived_until_something_does() {
		$this->assertNull( oko_inbound_last_seen( 'webhook' ) );
		$this->assertNull( oko_inbound_last_seen( 'products' ) );
		$this->assertSame( 'never', oko_inbound_seen_label( null ) );
	}

	public function test_each_road_is_remembered_on_its_own() {
		oko_note_inbound_call( 'webhook' );

		$this->assertNotNull( oko_inbound_last_seen( 'webhook' ) );
		$this->assertNull(
			oko_inbound_last_seen( 'products' ),
			'a status message says nothing about whether the lookup gets through'
		);
	}

	/**
	 * A busy shop takes these calls all day. Writing the option every time
	 * would be a database write per call for a figure nobody reads that often.
	 */
	public function test_a_second_call_within_the_minute_is_not_written_again() {
		oko_note_inbound_call( 'products' );
		$first = oko_inbound_last_seen( 'products' );

		oko_note_inbound_call( 'products' );

		$this->assertSame( $first, oko_inbound_last_seen( 'products' ) );
	}

	public function test_an_older_mark_is_moved_forward() {
		update_option( OKO_INBOUND_SEEN_OPTION, array( 'products' => time() - HOUR_IN_SECONDS ), false );

		oko_note_inbound_call( 'products' );

		$this->assertGreaterThan(
			time() - MINUTE_IN_SECONDS,
			oko_inbound_last_seen( 'products' ),
			'the road was open again just now'
		);
	}

	public function test_the_label_reads_as_time_passed() {
		$this->assertStringContainsString(
			'ago',
			oko_inbound_seen_label( time() - ( 2 * HOUR_IN_SECONDS ) )
		);
	}
}
