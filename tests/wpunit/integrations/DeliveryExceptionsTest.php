<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit\Integrations;

use okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions;

/**
 * Tests for the delivery-date filtering pipeline.
 *
 * Covers four behaviours:
 *   - the "no past dates" floor (only future/today dates ever surface);
 *   - per-product weekday intersection across the cart (the cart can only
 *     ship on a day EVERY weekday-restricted product can ship);
 *   - the API query window widening enough for weekday + from-date combos
 *     without over-fetching;
 *   - the configurable display limit (count vs window) with per-section
 *     "special" overrides, smallest-wins.
 *
 * We register product_cat / product_tag against `post` ourselves so the
 * weekday tests don't depend on WooCommerce being loaded in the test env.
 */
class DeliveryExceptionsTest extends \Codeception\TestCase\WPTestCase {

	/** @var Delivery_Exceptions */
	private $sut;

	public function setUp(): void {
		parent::setUp();
		delete_option( Delivery_Exceptions::OPTION_KEY );
		Delivery_Exceptions::purge_rules_cache();
		$this->sut = new Delivery_Exceptions();

		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy( 'product_cat', 'post', array( 'hierarchical' => true ) );
		}
		if ( ! taxonomy_exists( 'product_tag' ) ) {
			register_taxonomy( 'product_tag', 'post', array( 'hierarchical' => false ) );
		}
	}

	public function tearDown(): void {
		delete_option( Delivery_Exceptions::OPTION_KEY );
		Delivery_Exceptions::purge_rules_cache();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/** Build a Y-m-d date offset from today in the site's timezone. */
	private function date_offset( int $days ): string {
		$dt = new \DateTime( 'today', wp_timezone() );
		if ( $days !== 0 ) {
			$dt->modify( sprintf( '%+d days', $days ) );
		}
		return $dt->format( 'Y-m-d' );
	}

	/** The Y-m-d of the next occurrence of a given weekday (0=Sun..6=Sat). */
	private function next_weekday( int $weekday ): string {
		$dt = new \DateTime( 'today', wp_timezone() );
		for ( $i = 0; $i < 14; $i++ ) {
			if ( (int) $dt->format( 'w' ) === $weekday ) {
				return $dt->format( 'Y-m-d' );
			}
			$dt->modify( '+1 day' );
		}
		return $dt->format( 'Y-m-d' );
	}

	private function make_term( string $taxonomy, string $name ): int {
		$res = wp_insert_term( $name, $taxonomy );
		return (int) $res['term_id'];
	}

	private function make_product( string $taxonomy, int $term_id ): int {
		$post = $this->factory()->post->create();
		wp_set_object_terms( $post, array( $term_id ), $taxonomy );
		return $post;
	}

	// ---------------------------------------------------------------------
	// #1 — no past dates
	// ---------------------------------------------------------------------

	public function test_past_dates_are_dropped(): void {
		$result = $this->sut->filter_dates_for_cart(
			array( $this->date_offset( -7 ), $this->date_offset( -1 ), $this->date_offset( 1 ) ),
			array()
		);
		$this->assertSame( array( $this->date_offset( 1 ) ), $result );
	}

	public function test_today_is_kept(): void {
		$result = $this->sut->filter_dates_for_cart(
			array( $this->date_offset( 0 ), $this->date_offset( 1 ) ),
			array()
		);
		$this->assertSame( array( $this->date_offset( 0 ), $this->date_offset( 1 ) ), $result );
	}

	// ---------------------------------------------------------------------
	// #4 — per-product weekday intersection
	// ---------------------------------------------------------------------

	public function test_weekday_intersection_across_products(): void {
		$cat_a = $this->make_term( 'product_cat', 'A_wed_fri' );
		$cat_b = $this->make_term( 'product_cat', 'B_fri' );

		// Wed (3) for A; Fri (5) for A and B.
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'weekdays_enabled' => true,
			'weekdays' => array(
				3 => array( 'enabled' => true, 'categories' => array( $cat_a ), 'tags' => array() ),
				5 => array( 'enabled' => true, 'categories' => array( $cat_a, $cat_b ), 'tags' => array() ),
			),
		) );

		$product_a = $this->make_product( 'product_cat', $cat_a ); // {Wed, Fri}
		$product_b = $this->make_product( 'product_cat', $cat_b ); // {Fri}

		$rules = $this->sut->collect_applicable_rules(
			array( $product_a, $product_b ),
			Delivery_Exceptions::get_config()
		);

		$weekday_rule = null;
		foreach ( $rules as $r ) {
			if ( $r['type'] === 'weekday_set' ) {
				$weekday_rule = $r;
			}
		}
		$this->assertNotNull( $weekday_rule, 'expected a weekday_set rule' );
		$this->assertSame( array( 5 ), array_values( $weekday_rule['weekdays'] ), 'cart should allow only Friday' );
	}

	public function test_disjoint_weekdays_block_all_dates(): void {
		$cat_a = $this->make_term( 'product_cat', 'A_wed' );
		$cat_b = $this->make_term( 'product_cat', 'B_fri' );

		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'weekdays_enabled' => true,
			'weekdays' => array(
				3 => array( 'enabled' => true, 'categories' => array( $cat_a ), 'tags' => array() ),
				5 => array( 'enabled' => true, 'categories' => array( $cat_b ), 'tags' => array() ),
			),
		) );

		$product_a = $this->make_product( 'product_cat', $cat_a ); // {Wed}
		$product_b = $this->make_product( 'product_cat', $cat_b ); // {Fri}

		$dates = array(
			$this->next_weekday( 3 ), // a Wednesday
			$this->next_weekday( 5 ), // a Friday
		);

		$result = $this->sut->filter_dates_for_cart( $dates, array( $product_a, $product_b ) );

		$this->assertSame( array(), $result, 'no common weekday → no dates → split/contact flow' );
	}

	public function test_unconstrained_product_does_not_narrow(): void {
		$cat_a = $this->make_term( 'product_cat', 'A_fri_only' );

		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'weekdays_enabled' => true,
			'weekdays' => array(
				5 => array( 'enabled' => true, 'categories' => array( $cat_a ), 'tags' => array() ),
			),
		) );

		$product_a = $this->make_product( 'product_cat', $cat_a );    // {Fri}
		$product_c = $this->factory()->post->create();               // no terms → any day

		$rules = $this->sut->collect_applicable_rules(
			array( $product_a, $product_c ),
			Delivery_Exceptions::get_config()
		);

		$weekday_rule = null;
		foreach ( $rules as $r ) {
			if ( $r['type'] === 'weekday_set' ) {
				$weekday_rule = $r;
			}
		}
		$this->assertNotNull( $weekday_rule );
		$this->assertSame( array( 5 ), array_values( $weekday_rule['weekdays'] ) );
	}

	public function test_empty_weekday_set_rejects_every_date(): void {
		$this->assertFalse(
			Delivery_Exceptions::date_passes_rule( $this->next_weekday( 3 ), array( 'type' => 'weekday_set', 'weekdays' => array() ) )
		);
		$this->assertTrue(
			Delivery_Exceptions::date_passes_rule( $this->next_weekday( 5 ), array( 'type' => 'weekday_set', 'weekdays' => array( 5 ) ) )
		);
	}

	// ---------------------------------------------------------------------
	// #2 — query window sizing
	// ---------------------------------------------------------------------

	public function test_window_mode_query_window_uses_configured_value(): void {
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'display_mode'  => 'window',
			'display_value' => 10,
		) );
		$this->assertSame( 10, Delivery_Exceptions::effective_query_window( 3, array() ) );
	}

	public function test_count_mode_query_window_widens_for_weekly_cadence(): void {
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'display_mode'  => 'count',
			'display_value' => 4,
		) );
		// 4 dates * 7 days + 7 buffer.
		$this->assertSame( 35, Delivery_Exceptions::effective_query_window( 3, array() ) );
	}

	public function test_query_window_falls_back_to_default_days_when_unconfigured(): void {
		$this->assertSame( 3, Delivery_Exceptions::effective_query_window( 3, array() ) );
	}

	public function test_only_on_window_includes_buffer_past_the_date(): void {
		// A date sitting exactly on the requested window boundary can be cut off
		// by the API's lead-time/cutoff offset, so the window must extend a week
		// past any rule-referenced date.
		$cat  = $this->make_term( 'product_cat', 'xmas_cat' );
		$date = $this->date_offset( 50 );

		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'only_on_enabled' => true,
			'only_on' => array(
				array( 'label' => 'Xmas', 'date' => $date, 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ),
			),
		) );

		$product = $this->make_product( 'product_cat', $cat );

		// default_days = 3 (window mode, unconfigured value) → base 3; the only_on
		// date is 50 days out → window must be 50 + 7 buffer = 57.
		$this->assertSame( 57, Delivery_Exceptions::effective_query_window( 3, array( $product ) ) );
	}

	// ---------------------------------------------------------------------
	// #3 — display limit (count / window) + smallest-wins specials
	// ---------------------------------------------------------------------

	public function test_count_mode_limits_number_of_dates(): void {
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'display_mode'  => 'count',
			'display_value' => 2,
		) );
		$product = $this->factory()->post->create(); // unconstrained
		$dates   = array( $this->date_offset( 1 ), $this->date_offset( 2 ), $this->date_offset( 3 ), $this->date_offset( 4 ) );

		$result = $this->sut->filter_dates_for_cart( $dates, array( $product ) );

		$this->assertSame( array( $this->date_offset( 1 ), $this->date_offset( 2 ) ), $result );
	}

	public function test_unconfigured_display_does_not_trim(): void {
		$product = $this->factory()->post->create();
		$dates   = array( $this->date_offset( 1 ), $this->date_offset( 30 ), $this->date_offset( 90 ) );

		$result = $this->sut->filter_dates_for_cart( $dates, array( $product ) );

		$this->assertSame( $dates, $result, 'no display config → legacy behaviour, no trimming' );
	}

	// ---------------------------------------------------------------------
	// Upgrade notice
	// ---------------------------------------------------------------------

	public function test_upgrade_notice_shows_then_hides_after_dismissal(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		delete_option( Delivery_Exceptions::UPGRADE_NOTICE_OPTION );

		ob_start();
		$this->sut->maybe_render_upgrade_notice();
		$before = ob_get_clean();
		$this->assertNotSame( '', trim( $before ), 'notice should render before dismissal' );

		update_option( Delivery_Exceptions::UPGRADE_NOTICE_OPTION, Delivery_Exceptions::UPGRADE_NOTICE_KEY );

		ob_start();
		$this->sut->maybe_render_upgrade_notice();
		$after = ob_get_clean();
		$this->assertSame( '', trim( $after ), 'notice should be suppressed after dismissal' );
	}

	public function test_weekday_special_limit_overrides_global_and_smallest_wins(): void {
		$cat = $this->make_term( 'product_cat', 'weekday_cat' );

		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'display_mode'         => 'count',
			'display_value'        => 4,
			'weekdays_enabled'     => true,
			'weekdays_limit_mode'  => 'special',
			'weekdays_limit_value' => 1,
			'weekdays' => array(
				// allow every weekday for this category so dates survive filtering
				0 => array( 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ),
				1 => array( 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ),
				2 => array( 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ),
				3 => array( 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ),
				4 => array( 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ),
				5 => array( 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ),
				6 => array( 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ),
			),
		) );

		$product = $this->make_product( 'product_cat', $cat );
		$dates   = array( $this->date_offset( 1 ), $this->date_offset( 2 ), $this->date_offset( 3 ), $this->date_offset( 4 ) );

		$result = $this->sut->filter_dates_for_cart( $dates, array( $product ) );

		$this->assertSame( array( $this->date_offset( 1 ) ), $result, 'weekday special (1) beats global (4)' );
	}

	// ---------------------------------------------------------------------
	// Per-rule cutoff (tag/category, days, time, independent rules)
	// ---------------------------------------------------------------------

	/** Configure one enabled cutoff rule targeting a single tag. */
	private function enable_cutoff_for_tag( int $tag_id, int $days, bool $enabled = true ): void {
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'cutoff_enabled' => $enabled,
			'cutoff_rules'   => array(
				array( 'label' => '', 'days' => $days, 'time' => '09:00', 'enabled' => true, 'categories' => array(), 'tags' => array( $tag_id ) ),
			),
		) );
	}

	public function test_cutoff_removes_dates_a_tagged_product_can_no_longer_make(): void {
		$tag = $this->make_term( 'product_tag', 'frisk-frugt-groent' );
		// A huge lead means no near date can be ordered, whatever "now" is.
		$this->enable_cutoff_for_tag( $tag, 3650 );
		$product = $this->make_product( 'product_tag', $tag );

		$dates  = array( $this->date_offset( 1 ), $this->date_offset( 2 ), $this->date_offset( 5 ) );
		$result = $this->sut->filter_dates_for_cart( $dates, array( $product ) );

		$this->assertSame( array(), $result, 'a tag closed far too early leaves no orderable date' );
	}

	public function test_cutoff_disabled_leaves_dates_untouched(): void {
		$tag = $this->make_term( 'product_tag', 'frisk-frugt-groent' );
		$this->enable_cutoff_for_tag( $tag, 3650, false ); // master toggle off
		$product = $this->make_product( 'product_tag', $tag );

		$dates  = array( $this->date_offset( 1 ), $this->date_offset( 2 ) );
		$result = $this->sut->filter_dates_for_cart( $dates, array( $product ) );

		$this->assertSame( $dates, $result );
	}

	public function test_cutoff_ignores_products_without_the_tag(): void {
		$tag = $this->make_term( 'product_tag', 'frisk-frugt-groent' );
		$this->enable_cutoff_for_tag( $tag, 3650 );
		$product = $this->factory()->post->create(); // no tag → not affected

		$dates  = array( $this->date_offset( 1 ), $this->date_offset( 2 ) );
		$result = $this->sut->filter_dates_for_cart( $dates, array( $product ) );

		$this->assertSame( $dates, $result );
	}

	public function test_cutoff_rule_can_target_a_category(): void {
		$cat = $this->make_term( 'product_cat', 'mejeri' );
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'cutoff_enabled' => true,
			'cutoff_rules'   => array(
				array( 'label' => 'Dairy', 'days' => 3650, 'time' => '09:00', 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ),
			),
		) );
		$product = $this->make_product( 'product_cat', $cat );

		$dates  = array( $this->date_offset( 1 ), $this->date_offset( 2 ) );
		$result = $this->sut->filter_dates_for_cart( $dates, array( $product ) );

		$this->assertSame( array(), $result, 'a category rule closes its products too' );
	}

	public function test_cutoff_strictest_rule_wins_when_several_apply(): void {
		$lenient = $this->make_term( 'product_tag', 'lenient' );
		$strict  = $this->make_term( 'product_tag', 'strict' );
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'cutoff_enabled' => true,
			'cutoff_rules'   => array(
				array( 'label' => 'Lenient', 'days' => 0,    'time' => '09:00', 'enabled' => true, 'categories' => array(), 'tags' => array( $lenient ) ),
				array( 'label' => 'Strict',  'days' => 3650, 'time' => '09:00', 'enabled' => true, 'categories' => array(), 'tags' => array( $strict ) ),
			),
		) );
		// One product carrying BOTH tags → both rules apply; the strict one wins.
		$product = $this->factory()->post->create();
		wp_set_object_terms( $product, array( $lenient, $strict ), 'product_tag' );

		$dates  = array( $this->date_offset( 1 ), $this->date_offset( 2 ) );
		$result = $this->sut->filter_dates_for_cart( $dates, array( $product ) );

		$this->assertSame( array(), $result, 'the earliest deadline among applicable rules wins' );
	}

	public function test_cutoff_legacy_config_is_migrated(): void {
		$tag = $this->make_term( 'product_tag', 'frisk-frugt-groent' );
		// Old-style stored config (global lead/time + per-tag offsets).
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'cutoff_enabled'   => true,
			'cutoff_lead_days' => 1,
			'cutoff_time'      => '09:00',
			'cutoff_tags'      => array(
				array( 'tag' => $tag, 'offset_days' => 3650, 'enabled' => true ),
			),
		) );
		$product = $this->make_product( 'product_tag', $tag );

		$dates  = array( $this->date_offset( 1 ), $this->date_offset( 2 ) );
		$result = $this->sut->filter_dates_for_cart( $dates, array( $product ) );

		$this->assertSame( array(), $result, 'legacy cutoff_tags config still filters after migration' );
	}
}
