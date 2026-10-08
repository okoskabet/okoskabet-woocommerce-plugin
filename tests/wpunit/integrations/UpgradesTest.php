<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit\Integrations;

use okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions;
use okoskabet_woocommerce_plugin\Integrations\Upgrades;

/**
 * The pensioned "on top of the normal days" tick on only-on rows becomes a
 * one-day from/until window (only_on_extend_to_window_v1).
 *
 * The migration rewrites a shop's stored rules once, so what matters is that
 * the rules that bit before it still bite after it, and the ones that did
 * nothing still do nothing. Each test seeds the option as a pre-release build
 * stored it, runs the real migration runner, and asks the rule collector which
 * rules now apply to a product the rows target.
 */
class UpgradesTest extends \Codeception\TestCase\WPTestCase {

	/** @var int */
	private $product;

	/** @var int */
	private $cat;

	public function setUp(): void {
		parent::setUp();
		delete_option( Delivery_Exceptions::OPTION_KEY );
		// Only the migration under test is pending.
		update_option( Upgrades::COMPLETED_OPTION, array( 'rewrite_label_created_events_v1', 'seed_default_merchant_v1' ) );
		Delivery_Exceptions::purge_rules_cache();

		if ( ! taxonomy_exists( 'product_cat' ) ) {
			register_taxonomy( 'product_cat', 'post', array( 'hierarchical' => true ) );
		}
		$this->cat     = (int) wp_insert_term( 'Is', 'product_cat' )['term_id'];
		$this->product = $this->factory()->post->create();
		wp_set_object_terms( $this->product, array( $this->cat ), 'product_cat' );
	}

	public function tearDown(): void {
		delete_option( Delivery_Exceptions::OPTION_KEY );
		delete_option( Upgrades::COMPLETED_OPTION );
		Delivery_Exceptions::purge_rules_cache();
		parent::tearDown();
	}

	private function date_offset( int $days ): string {
		$dt = new \DateTime( 'today', wp_timezone() );
		$dt->modify( sprintf( '%+d days', $days ) );
		return $dt->format( 'Y-m-d' );
	}

	/** An only-on row saved with the pensioned tick. */
	private function ticked_row( int $days, ?bool $enabled = true ): array {
		$row = array(
			'label'      => 'Juleis',
			'date'       => $this->date_offset( $days ),
			'enabled'    => $enabled,
			'extend'     => true,
			'flip'       => false,
			'categories' => array( $this->cat ),
			'tags'       => array(),
		);
		if ( $enabled === null ) {
			unset( $row['enabled'] );
		}
		return $row;
	}

	/** An ordinary from/until restriction on the same product. */
	private function window_row( int $from, int $until ): array {
		return array(
			'label'      => 'Sommer',
			'from'       => $this->date_offset( $from ),
			'until'      => $this->date_offset( $until ),
			'enabled'    => true,
			'extend'     => false,
			'flip'       => false,
			'all'        => false,
			'categories' => array( $this->cat ),
			'tags'       => array(),
		);
	}

	private function migrate(): void {
		( new \ReflectionClass( Upgrades::class ) )->newInstanceWithoutConstructor()->run_pending_migrations();
		Delivery_Exceptions::purge_rules_cache();
	}

	/** The from/until rules that apply to the product, as "extend from..until". */
	private function live_windows(): array {
		$rules = ( new Delivery_Exceptions() )->collect_applicable_rules( array( $this->product ), Delivery_Exceptions::get_config() );
		$out   = array();
		foreach ( $rules as $rule ) {
			if ( $rule['type'] === 'from_until' ) {
				$out[] = ( empty( $rule['extend'] ) ? 'restrict ' : 'extend ' ) . $rule['from'] . '..' . $rule['until'];
			}
		}
		sort( $out );
		return $out;
	}

	public function test_a_row_from_a_switched_off_section_stays_off(): void {
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'only_on_enabled'    => false,
			'from_until_enabled' => false,
			'only_on'            => array( $this->ticked_row( 10 ) ),
		) );

		$this->migrate();

		$this->assertSame( array(), $this->live_windows(), 'a disabled rule gets enabled: the only-on section was off, so the row did nothing before the upgrade' );
		$this->assertFalse( get_option( Delivery_Exceptions::OPTION_KEY )['from_until_enabled'], 'nothing live moved in, so the from/until switch stays as the shop left it' );
		$this->assertTrue( in_array( 'only_on_extend_to_window_v1', (array) get_option( Upgrades::COMPLETED_OPTION ), true ) );
	}

	public function test_a_row_from_a_switched_off_section_stays_off_in_a_live_from_until_section(): void {
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'only_on_enabled'    => false,
			'from_until_enabled' => true,
			'only_on'            => array( $this->ticked_row( 10 ) ),
		) );

		$this->migrate();

		$this->assertSame( array(), $this->live_windows(), 'a disabled rule gets enabled: the row came from a section that was off' );
	}

	public function test_a_dormant_from_until_rule_stays_dormant_when_a_live_row_moves_in(): void {
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'only_on_enabled'    => true,
			'from_until_enabled' => false,
			'only_on'            => array( $this->ticked_row( 10 ) ),
			'from_until'         => array( $this->window_row( 20, 25 ) ),
		) );

		$this->migrate();

		$day = $this->date_offset( 10 );
		$this->assertSame(
			array( "extend $day..$day" ),
			$this->live_windows(),
			'a disabled rule gets enabled: the from/until section was off, so its +20..+25 restriction did nothing before the upgrade'
		);
	}

	public function test_a_live_row_stays_live(): void {
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'only_on_enabled'    => true,
			'from_until_enabled' => false,
			'only_on'            => array( $this->ticked_row( 10 ), $this->ticked_row( 11, false ) ),
		) );

		$this->migrate();

		$day = $this->date_offset( 10 );
		$this->assertSame( array( "extend $day..$day" ), $this->live_windows() );
	}

	public function test_a_row_saved_without_its_enabled_key_stays_live(): void {
		// The reader has always taken a missing per-row flag as on.
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'only_on_enabled'    => true,
			'from_until_enabled' => false,
			'only_on'            => array( $this->ticked_row( 10, null ) ),
		) );

		$this->migrate();

		$day = $this->date_offset( 10 );
		$this->assertSame( array( "extend $day..$day" ), $this->live_windows(), 'a live rule gets disabled: a row without the enabled key was on' );
	}

	public function test_running_it_again_changes_nothing(): void {
		update_option( Delivery_Exceptions::OPTION_KEY, array(
			'only_on_enabled'    => true,
			'from_until_enabled' => false,
			'only_on'            => array( $this->ticked_row( 10 ), $this->ticked_row( 11, false ) ),
			'from_until'         => array( $this->window_row( 20, 25 ) ),
		) );

		$this->migrate();
		$once = get_option( Delivery_Exceptions::OPTION_KEY );

		// Recorded, so the runner skips it.
		$this->migrate();
		$this->assertSame( $once, get_option( Delivery_Exceptions::OPTION_KEY ) );

		// And the migration itself has nothing left to convert.
		update_option( Upgrades::COMPLETED_OPTION, array( 'rewrite_label_created_events_v1', 'seed_default_merchant_v1' ) );
		$this->migrate();
		$this->assertSame( $once, get_option( Delivery_Exceptions::OPTION_KEY ) );
	}
}
