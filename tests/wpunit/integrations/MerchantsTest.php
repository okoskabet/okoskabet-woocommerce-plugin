<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit\Integrations;

use okoskabet_woocommerce_plugin\Integrations\Merchants;
use okoskabet_woocommerce_plugin\Integrations\Upgrades;

/**
 * Tests for the merchants registry — normalisation, default-merchant
 * fallback, the legacy-options mirror facade, and migration idempotency.
 *
 * These tests deliberately avoid WooCommerce dependencies and only
 * touch wp_option storage, so they run in the standard WPLoader
 * environment without needing the WC test suite installed.
 */
class MerchantsTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		delete_option( Merchants::OPTION_KEY );
		delete_option( O_TEXTDOMAIN . '-settings' );
		delete_option( Upgrades::COMPLETED_OPTION );
		Merchants::purge_config_cache();
	}

	public function tearDown(): void {
		Merchants::purge_config_cache();
		parent::tearDown();
	}

	/**
	 * @test
	 */
	public function it_returns_an_empty_merchants_list_when_no_config_exists() {
		$this->assertSame( array(), Merchants::get_all() );
		$this->assertFalse( Merchants::has_any() );
		$this->assertNull( Merchants::get_default() );
	}

	/**
	 * @test
	 */
	public function it_normalises_a_merchant_record_and_strips_unknown_payment_gateways() {
		$normalised = Merchants::normalise_merchant(
			'Hello World',
			array(
				'id'                    => 'My Merchant!',
				'label'                 => 'My Merchant',
				'api_key'               => 'k',
				'webhook_secret'        => 's',
				'staging'               => '1',
				'maximum_days_in_future' => 0,
				'payment_gateway'       => 'definitely-not-a-real-gateway',
				'capture_events'        => array( 'label_printed', 'BOGUS', 'label_created' ),
				'webhook_events'        => array( 'order_delivered' ),
				'product_categories'    => array( '10', 20, '0', 'x' ),
				'product_tags'          => array( 5 ),
			)
		);

		// sanitize_key downcases and strips bad chars.
		$this->assertSame( 'mymerchant', $normalised['id'] );
		$this->assertTrue( $normalised['staging'] );
		// max(1, 0) — min sensible value.
		$this->assertSame( 1, $normalised['maximum_days_in_future'] );
		// Unknown gateway → 'auto'.
		$this->assertSame( 'auto', $normalised['payment_gateway'] );
		// 'BOGUS' dropped; legacy 'label_created' rewritten to 'in_shed'.
		$this->assertSame( array( 'label_printed', 'in_shed' ), array_values( $normalised['capture_events'] ) );
		// Non-positive / non-numeric category IDs filtered out.
		$this->assertSame( array( 10, 20 ), $normalised['product_categories'] );
	}

	/**
	 * @test
	 */
	public function it_returns_null_when_normalising_a_merchant_without_an_id() {
		$this->assertNull( Merchants::normalise_merchant( '', array( 'label' => 'no id' ) ) );
	}

	/**
	 * @test
	 */
	public function it_normalises_shipping_zones_keeping_zero_and_dropping_negatives() {
		$normalised = Merchants::normalise_merchant( 'm', array(
			'id'             => 'm',
			'shipping_zones' => array( '3', 0, 5, -1, 'x', '5', null ),
		) );

		// 0 is a legitimate WC zone (Rest of the World) and must be kept.
		// Negative, non-numeric and duplicate values are dropped.
		$this->assertSame( array( 3, 0, 5 ), $normalised['shipping_zones'] );
	}

	/**
	 * @test
	 */
	public function it_falls_back_to_the_first_merchant_when_stored_default_id_is_stale() {
		update_option( Merchants::OPTION_KEY, array(
			'version'             => 1,
			'default_merchant_id' => 'does-not-exist',
			'merchants'           => array(
				'real-one' => array(
					'id'    => 'real-one',
					'label' => 'Real',
				),
			),
		) );

		$cfg = Merchants::get_config();
		$this->assertSame( 'real-one', $cfg['default_merchant_id'] );
		$this->assertSame( 'real-one', Merchants::default_id() );
		$this->assertNotNull( Merchants::get_default() );
	}

	/**
	 * @test
	 */
	public function it_caches_get_config_within_a_single_request() {
		update_option( Merchants::OPTION_KEY, array(
			'version'             => 1,
			'default_merchant_id' => 'a',
			'merchants'           => array(
				'a' => array( 'id' => 'a', 'label' => 'A' ),
			),
		) );

		$first = Merchants::get_config();
		// Mutate the option directly behind the cache's back — should
		// still see the cached value until purge.
		update_option( Merchants::OPTION_KEY, array(
			'version'             => 1,
			'default_merchant_id' => 'b',
			'merchants'           => array(
				'b' => array( 'id' => 'b', 'label' => 'B' ),
			),
		) );
		$second = Merchants::get_config();
		$this->assertSame( $first['default_merchant_id'], $second['default_merchant_id'] );

		Merchants::purge_config_cache();
		$third = Merchants::get_config();
		$this->assertSame( 'b', $third['default_merchant_id'] );
	}

	/**
	 * @test
	 */
	public function is_multi_merchant_mode_is_false_with_zero_or_one_merchant_and_true_with_two() {
		$this->assertFalse( Merchants::is_multi_merchant_mode() );

		Merchants::save_config( array(
			'version'             => 1,
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array( 'id' => 'default', 'label' => 'D' ),
			),
		) );
		$this->assertFalse( Merchants::is_multi_merchant_mode() );

		Merchants::save_config( array(
			'version'             => 1,
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array( 'id' => 'default', 'label' => 'D' ),
				'second'  => array( 'id' => 'second',  'label' => 'S' ),
			),
		) );
		$this->assertTrue( Merchants::is_multi_merchant_mode() );
	}

	/**
	 * @test
	 */
	public function mirror_default_to_options_keeps_the_simple_form_in_sync_with_the_default_merchant() {
		Merchants::save_config( array(
			'version'             => 1,
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array(
					'id'                              => 'default',
					'label'                           => 'Default',
					'api_key'                         => 'KEY-123',
					'webhook_secret'                  => 'SECRET-XYZ',
					'staging'                         => true,
					'description_shipping_okoskabet'  => 'shed desc',
					'description_shipping_private'    => 'home desc',
					'maximum_days_in_future'          => 7,
					'payment_gateway'                 => 'stripe',
					'capture_events'                  => array( 'label_printed' ),
					'webhook_events'                  => array( 'order_delivered' ),
				),
			),
		) );

		Merchants::mirror_default_to_options();

		$opts = (array) get_option( O_TEXTDOMAIN . '-settings', array() );
		$this->assertSame( 'KEY-123', $opts['_api_key'] );
		$this->assertSame( 'SECRET-XYZ', $opts['_webhook_secret'] );
		$this->assertSame( 'on', $opts['_staging_api'] );
		$this->assertSame( 'shed desc', $opts['_description_shipping_okoskabet'] );
		$this->assertSame( 7, $opts['_maximum_days_in_future'] );
		$this->assertSame( 'stripe', $opts['_payment_gateway'] );
	}

	/**
	 * @test
	 */
	public function seed_default_merchant_migration_is_idempotent() {
		update_option( O_TEXTDOMAIN . '-settings', array(
			'_api_key'        => 'KEY-LEGACY',
			'_webhook_secret' => 'SECRET-LEGACY',
			'_staging_api'    => 'on',
		) );

		$run = function () {
			$reflection = new \ReflectionClass( Upgrades::class );
			$method     = $reflection->getMethod( 'migrate_seed_default_merchant' );
			$method->setAccessible( true );
			$instance   = $reflection->newInstanceWithoutConstructor();
			$method->invoke( $instance );
			Merchants::purge_config_cache();
		};

		$run();
		$first = Merchants::get_default();
		$this->assertIsArray( $first );
		$this->assertSame( 'KEY-LEGACY', $first['api_key'] );

		// Manually mutate the seeded merchant and re-run — the migration
		// must NOT clobber a merchant that already exists.
		$cfg = Merchants::get_config();
		$cfg['merchants']['default']['api_key'] = 'KEY-CHANGED';
		Merchants::save_config( $cfg );

		$run();
		$second = Merchants::get_default();
		$this->assertSame( 'KEY-CHANGED', $second['api_key'], 'second migration run should not overwrite an existing merchant' );
	}
}
