<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit\Integrations;

use okoskabet_woocommerce_plugin\Integrations\Merchants;
use okoskabet_woocommerce_plugin\Integrations\Merchant_Router;

/**
 * Tests for cart-level merchant routing.
 *
 * The router has four interesting branches:
 *
 *   - empty cart                → falls back to default merchant
 *   - all items map to the same merchant → that merchant wins
 *   - items map to different merchants   → falls back to default
 *   - per-product `_okoskabet_merchant` meta override wins over rules
 *
 * We exercise each branch by setting per-product overrides (so the
 * test doesn't depend on WooCommerce's product_cat / product_tag
 * taxonomies being registered).
 */
class MerchantRouterTest extends \Codeception\TestCase\WPTestCase {

	private $product_a;
	private $product_b;
	private $product_c;

	public function setUp(): void {
		parent::setUp();

		delete_option( Merchants::OPTION_KEY );
		Merchants::purge_config_cache();

		Merchants::save_config( array(
			'version'             => 1,
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array(
					'id'    => 'default',
					'label' => 'Default',
				),
				'second'  => array(
					'id'    => 'second',
					'label' => 'Second',
				),
			),
		) );

		// `post` is the safest CPT to use here — it's always registered
		// regardless of which plugins the test environment loaded.
		$this->product_a = $this->factory()->post->create();
		$this->product_b = $this->factory()->post->create();
		$this->product_c = $this->factory()->post->create();

		update_post_meta( $this->product_a, Merchant_Router::PRODUCT_META_KEY, 'second' );
		update_post_meta( $this->product_b, Merchant_Router::PRODUCT_META_KEY, 'second' );
		// $this->product_c left without override — routes to default.
	}

	public function tearDown(): void {
		Merchants::purge_config_cache();
		parent::tearDown();
	}

	/**
	 * @test
	 */
	public function empty_cart_falls_back_to_the_default_merchant() {
		$resolved = Merchant_Router::resolve_for_products( array() );

		$this->assertSame( 'default', $resolved['merchant_id'] );
		$this->assertFalse( $resolved['is_mixed'] );
		$this->assertFalse( $resolved['fell_back_to_default'] );
	}

	/**
	 * @test
	 */
	public function single_merchant_cart_routes_to_that_merchant() {
		$resolved = Merchant_Router::resolve_for_products( array( $this->product_a, $this->product_b ) );

		$this->assertSame( 'second', $resolved['merchant_id'] );
		$this->assertFalse( $resolved['is_mixed'] );
		$this->assertFalse( $resolved['fell_back_to_default'] );
	}

	/**
	 * @test
	 */
	public function mixed_cart_falls_back_to_the_default_merchant() {
		$resolved = Merchant_Router::resolve_for_products( array( $this->product_a, $this->product_c ) );

		$this->assertSame( 'default', $resolved['merchant_id'] );
		$this->assertTrue( $resolved['is_mixed'] );
		$this->assertTrue( $resolved['fell_back_to_default'] );
		$this->assertContains( 'default', $resolved['merchant_ids'] );
		$this->assertContains( 'second', $resolved['merchant_ids'] );
	}

	/**
	 * @test
	 */
	public function per_product_override_to_unknown_merchant_id_is_ignored() {
		$ghost = $this->factory()->post->create();
		update_post_meta( $ghost, Merchant_Router::PRODUCT_META_KEY, 'merchant-that-does-not-exist' );

		// Should fall through to the default merchant via routing,
		// NOT take the bogus override.
		$resolved = Merchant_Router::resolve_for_products( array( $ghost ) );
		$this->assertSame( 'default', $resolved['merchant_id'] );
		$this->assertFalse( $resolved['is_mixed'] );
	}

	/**
	 * @test
	 */
	public function sanitize_id_strips_unsafe_input_and_special_keywords() {
		$this->assertSame( '', Merchant_Router::sanitize_id( 'auto' ) );
		$this->assertSame( '', Merchant_Router::sanitize_id( '' ) );
		$this->assertSame( 'good-id_2', Merchant_Router::sanitize_id( 'Good-ID_2' ) );
	}

	/**
	 * @test
	 */
	public function empty_shipping_zones_list_means_no_zone_restriction() {
		$this->assertTrue( Merchant_Router::merchant_matches_zone( array( 'shipping_zones' => array() ), 7 ) );
		$this->assertTrue( Merchant_Router::merchant_matches_zone( array(), 0 ) );
	}

	/**
	 * @test
	 */
	public function non_empty_shipping_zones_list_only_matches_listed_zones() {
		$merchant = array( 'shipping_zones' => array( 2, 5 ) );
		$this->assertTrue( Merchant_Router::merchant_matches_zone( $merchant, 2 ) );
		$this->assertTrue( Merchant_Router::merchant_matches_zone( $merchant, 5 ) );
		$this->assertFalse( Merchant_Router::merchant_matches_zone( $merchant, 1 ) );
		$this->assertFalse( Merchant_Router::merchant_matches_zone( $merchant, 0 ) );
	}

	/**
	 * @test
	 */
	public function zone_filter_falls_back_to_default_when_resolved_merchant_does_not_cover_zone() {
		// Restrict the secondary merchant to zone 99 only.
		Merchants::save_config( array(
			'version'             => 1,
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array( 'id' => 'default', 'label' => 'Default' ),
				'second'  => array(
					'id'             => 'second',
					'label'          => 'Second',
					'shipping_zones' => array( 99 ),
				),
			),
		) );

		// Cart with only a 'second'-routed product, shipping to zone 1 → falls back.
		$resolved = Merchant_Router::resolve_for_products( array( $this->product_a ), 1 );
		$this->assertSame( 'default', $resolved['merchant_id'] );
		$this->assertArrayHasKey( $this->product_a, $resolved['zone_filtered_out'] );

		// Same cart shipping to zone 99 → secondary handles it.
		$resolved = Merchant_Router::resolve_for_products( array( $this->product_a ), 99 );
		$this->assertSame( 'second', $resolved['merchant_id'] );
		$this->assertSame( array(), $resolved['zone_filtered_out'] );
	}

	/**
	 * @test
	 */
	public function null_zone_disables_filtering_and_keeps_pre_zone_behaviour() {
		Merchants::save_config( array(
			'version'             => 1,
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array( 'id' => 'default', 'label' => 'Default' ),
				'second'  => array(
					'id'             => 'second',
					'label'          => 'Second',
					'shipping_zones' => array( 99 ),
				),
			),
		) );

		// No zone passed → restriction ignored → secondary still wins.
		$resolved = Merchant_Router::resolve_for_products( array( $this->product_a ) );
		$this->assertSame( 'second', $resolved['merchant_id'] );
		$this->assertSame( array(), $resolved['zone_filtered_out'] );
	}
}
