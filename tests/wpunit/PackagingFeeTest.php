<?php

use okoskabet_woocommerce_plugin\Integrations\Packaging_Fee;

/**
 * The packaging fee's decision-making: which rule wins, when a rule matches,
 * and how an amount is picked. The cart hook itself needs a live WooCommerce
 * session and is covered by manual checkout testing.
 */
class PackagingFeeTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );
		delete_option( Packaging_Fee::OPTION_KEY );
		delete_option( O_TEXTDOMAIN . '-settings' );
		Packaging_Fee::purge_config_cache();
	}

	public function tearDown(): void {
		delete_option( Packaging_Fee::OPTION_KEY );
		delete_option( O_TEXTDOMAIN . '-settings' );
		Packaging_Fee::purge_config_cache();
		parent::tearDown();
	}

	private function save_config( array $config ): void {
		update_option( Packaging_Fee::OPTION_KEY, $config );
		Packaging_Fee::purge_config_cache();
	}

	private function rule( array $overrides = array() ): array {
		return array_merge(
			array(
				'label'      => 'Emballage',
				'amount'     => '28',
				'tiers'      => '',
				'categories' => array(),
				'tags'       => array(),
				'methods'    => array(),
				'enabled'    => true,
			),
			$overrides
		);
	}

	// ---------------------------------------------------------------- config

	/**
	 * @test
	 */
	public function it_is_off_until_the_merchant_turns_it_on() {
		$this->assertFalse( Packaging_Fee::get_config()['enabled'] );

		$this->save_config( array( 'enabled' => true, 'rules' => array( $this->rule() ) ) );
		$this->assertTrue( Packaging_Fee::get_config()['enabled'] );
	}

	/**
	 * @test
	 * An unticked checkbox is absent from the posted form rather than false,
	 * so "absent" has to mean the common case: the merchant typed the price
	 * the customer pays.
	 */
	public function it_treats_amounts_as_incl_vat_unless_told_otherwise() {
		$this->save_config( array( 'enabled' => true, 'rules' => array() ) );
		$this->assertTrue( Packaging_Fee::get_config()['include_tax'] );

		$this->save_config( array( 'enabled' => true, 'ex_tax' => true, 'rules' => array() ) );
		$this->assertFalse( Packaging_Fee::get_config()['include_tax'] );
	}

	/**
	 * @test
	 */
	public function it_maps_the_standard_tax_class_to_woocommerces_empty_slug() {
		$this->save_config( array( 'tax_class' => 'standard', 'rules' => array() ) );
		$config = Packaging_Fee::get_config();
		$this->assertTrue( $config['taxable'] );
		$this->assertSame( '', $config['tax_class'] );

		$this->save_config( array( 'tax_class' => 'none', 'rules' => array() ) );
		$this->assertFalse( Packaging_Fee::get_config()['taxable'] );
	}

	/**
	 * @test
	 * Danish decimal commas get typed by hand into the amount field.
	 */
	public function it_accepts_a_comma_as_the_decimal_mark() {
		$this->save_config( array( 'rules' => array( $this->rule( array( 'amount' => '28,50' ) ) ) ) );
		$this->assertSame( 28.5, Packaging_Fee::get_config()['rules'][0]['amount'] );
	}

	/**
	 * @test
	 * A shop that configured the single fee before the rule list existed must
	 * not silently stop charging it.
	 */
	public function it_carries_a_pre_rules_single_fee_over() {
		update_option(
			O_TEXTDOMAIN . '-settings',
			array(
				'_packaging_fee_enabled' => 'on',
				'_packaging_fee_label'   => 'Emballage',
				'_packaging_fee_amount'  => '28',
			)
		);
		Packaging_Fee::purge_config_cache();

		$config = Packaging_Fee::get_config();

		$this->assertTrue( $config['enabled'] );
		$this->assertCount( 1, $config['rules'] );
		$this->assertSame( 'Emballage', $config['rules'][0]['label'] );
		$this->assertSame( 28.0, $config['rules'][0]['amount'] );
	}

	// --------------------------------------------------------------- matching

	/**
	 * @test
	 * The point of the whole rule list: a frozen order pays one amount and
	 * everything else falls through to the catch-all.
	 */
	public function the_first_matching_rule_wins_and_the_rest_fall_through() {
		$frozen = $this->make_category( 'Frost' );
		$pantry = $this->make_category( 'Tørvarer' );

		$config = Packaging_Fee::normalise_config(
			array(
				'enabled' => true,
				'rules'   => array(
					$this->rule( array( 'label' => 'Frost', 'amount' => '45', 'categories' => array( $frozen ) ) ),
					$this->rule( array( 'label' => 'Emballage', 'amount' => '28' ) ),
				),
			)
		);

		$frozen_cart = $this->cart_with( array( $this->make_product( array( $frozen ) ) ) );
		$plain_cart  = $this->cart_with( array( $this->make_product( array( $pantry ) ) ) );

		$this->assertSame( 'Frost', Packaging_Fee::matching_rule( $config, $frozen_cart )['label'] );
		$this->assertSame( 'Emballage', Packaging_Fee::matching_rule( $config, $plain_cart )['label'] );
	}

	/**
	 * @test
	 * Without a catch-all, a cart that fits no rule pays nothing at all —
	 * rather than falling back to some arbitrary rule.
	 */
	public function no_matching_rule_means_no_fee() {
		$frozen = $this->make_category( 'Frost' );
		$pantry = $this->make_category( 'Tørvarer' );

		$config = Packaging_Fee::normalise_config(
			array(
				'enabled' => true,
				'rules'   => array( $this->rule( array( 'categories' => array( $frozen ) ) ) ),
			)
		);

		$this->assertNull( Packaging_Fee::matching_rule( $config, $this->cart_with( array( $this->make_product( array( $pantry ) ) ) ) ) );
	}

	/**
	 * @test
	 */
	public function a_disabled_rule_is_skipped_entirely() {
		$config = Packaging_Fee::normalise_config(
			array(
				'enabled' => true,
				'rules'   => array(
					$this->rule( array( 'label' => 'Off', 'amount' => '99', 'enabled' => false ) ),
					$this->rule( array( 'label' => 'On', 'amount' => '28' ) ),
				),
			)
		);

		$this->assertSame( 'On', Packaging_Fee::matching_rule( $config, $this->cart_with( array( $this->make_product() ) ) )['label'] );
	}

	/**
	 * @test
	 * Picking a parent category has to cover the products filed under its
	 * children, or the rule silently misses most of what it was aimed at.
	 */
	public function a_rule_on_a_parent_category_matches_products_in_its_children() {
		$parent = $this->make_category( 'Frost' );
		$child  = wp_insert_term( 'Fisk', 'product_cat', array( 'parent' => $parent ) );

		$config = Packaging_Fee::normalise_config(
			array(
				'enabled' => true,
				'rules'   => array( $this->rule( array( 'categories' => array( $parent ) ) ) ),
			)
		);

		// The stored selection stays exactly what the merchant ticked...
		$this->assertSame( array( $parent ), $config['rules'][0]['categories'] );

		// ...but a product filed only under the child still matches.
		$in_child = $this->cart_with( array( $this->make_product( array( (int) $child['term_id'] ) ) ) );
		$this->assertNotNull( Packaging_Fee::matching_rule( $config, $in_child ) );
	}

	/**
	 * @test
	 */
	public function it_drops_junk_term_ids() {
		$this->assertSame( array(), oko_packaging_fee_term_ids( array( 0, '', 'abc', -5 ), 'product_cat' ) );
	}

	// ---------------------------------------------------------------- amounts

	/**
	 * @test
	 */
	public function it_uses_the_flat_amount_when_no_ladder_is_configured() {
		$config = array( 'include_tax' => true );
		$rule   = array( 'amount' => 28.0, 'tiers' => array() );

		$this->assertSame( 28.0, Packaging_Fee::rule_amount( $rule, $config, $this->cart_worth( 100.0 ) ) );
	}

	/**
	 * @test
	 */
	public function the_ladder_beats_the_flat_amount_and_can_reach_zero() {
		$config = array( 'include_tax' => true );
		$rule   = array(
			'amount' => 28.0,
			'tiers'  => oko_parse_shipping_tiers( "0 = 28\n500 = 15\n1000 = 0" ),
		);

		$this->assertSame( 28.0, Packaging_Fee::rule_amount( $rule, $config, $this->cart_worth( 100.0 ) ) );
		$this->assertSame( 15.0, Packaging_Fee::rule_amount( $rule, $config, $this->cart_worth( 700.0 ) ) );
		$this->assertSame( 0.0, Packaging_Fee::rule_amount( $rule, $config, $this->cart_worth( 1500.0 ) ) );
	}

	// ---------------------------------------------------------------- coupons

	/**
	 * @test
	 * A shop can hand out free delivery without also giving the box away, so
	 * this flag is deliberately separate from WooCommerce's free shipping.
	 */
	public function only_a_coupon_flagged_for_free_packaging_waives_the_fee() {
		$plain = $this->make_coupon( 'plainoff', false );
		$free  = $this->make_coupon( 'freebox', true );

		$this->assertFalse( Packaging_Fee::coupon_waives_fee( $this->cart_with_coupons( array( $plain ) ) ) );
		$this->assertTrue( Packaging_Fee::coupon_waives_fee( $this->cart_with_coupons( array( $plain, $free ) ) ) );
	}

	// ------------------------------------------------------- split deliveries

	/**
	 * @test
	 * Split checkout turns one basket into two ordinary orders, and each is
	 * charged for its own box. That is not a rounding error to be tidied away
	 * later: two deliveries really are two boxes, two lots of cool packs and
	 * two trips. So the fee has to be decided from the cart in front of it and
	 * nothing else — no memory of an earlier order, no "already paid".
	 */
	public function each_part_of_a_split_delivery_pays_its_own_packaging_fee() {
		$frozen = $this->make_category( 'Frost' );
		$pantry = $this->make_category( 'Tørvarer' );

		$config = Packaging_Fee::normalise_config(
			array(
				'enabled' => true,
				'rules'   => array(
					$this->rule( array( 'label' => 'Emballage (frost)', 'amount' => '45', 'categories' => array( $frozen ) ) ),
					$this->rule( array( 'label' => 'Emballage', 'amount' => '28' ) ),
				),
			)
		);

		// Step one goes out with the frozen goods, step two with the dry ones.
		$step_one = $this->cart_with( array( $this->make_product( array( $frozen ) ) ) );
		$step_two = $this->cart_with( array( $this->make_product( array( $pantry ) ) ) );

		$first  = Packaging_Fee::matching_rule( $config, $step_one );
		$second = Packaging_Fee::matching_rule( $config, $step_two );

		$this->assertNotNull( $first, 'the first order is charged' );
		$this->assertNotNull( $second, 'the second order is charged too' );
		$this->assertSame( 'Emballage (frost)', $first['label'] );
		$this->assertSame( 'Emballage', $second['label'] );

		$this->assertSame( 45.0, Packaging_Fee::rule_amount( $first, $config, $step_one ) );
		$this->assertSame( 28.0, Packaging_Fee::rule_amount( $second, $config, $step_two ) );
	}

	/**
	 * @test
	 * The same basket split down the middle pays the ordinary fee twice, not
	 * once halved. A shop reading its takings has to see 28 and 28.
	 */
	public function splitting_a_basket_charges_the_fee_on_both_halves() {
		$config = Packaging_Fee::normalise_config(
			array(
				'enabled' => true,
				'rules'   => array( $this->rule( array( 'amount' => '28' ) ) ),
			)
		);

		$halves = array(
			$this->cart_with( array( $this->make_product() ) ),
			$this->cart_with( array( $this->make_product() ) ),
		);

		$charged = 0.0;
		foreach ( $halves as $half ) {
			$rule = Packaging_Fee::matching_rule( $config, $half );
			$this->assertNotNull( $rule );
			$charged += Packaging_Fee::rule_amount( $rule, $config, $half );
		}

		$this->assertSame( 56.0, $charged, 'two deliveries, two boxes' );
	}

	// ---------------------------------------------------------------- helpers

	private function make_category( string $name ): int {
		$term = wp_insert_term( $name, 'product_cat' );
		return (int) $term['term_id'];
	}

	private function make_product( array $category_ids = array() ): int {
		$id = $this->factory()->post->create( array( 'post_type' => 'product' ) );
		if ( ! empty( $category_ids ) ) {
			wp_set_object_terms( $id, $category_ids, 'product_cat' );
		}
		return (int) $id;
	}

	private function make_coupon( string $code, bool $free_packaging ): string {
		$id = $this->factory()->post->create( array( 'post_type' => 'shop_coupon', 'post_title' => $code ) );
		update_post_meta( $id, Packaging_Fee::COUPON_META, $free_packaging ? 'yes' : 'no' );
		return $code;
	}

	/** A cart stand-in holding the given product ids. */
	private function cart_with( array $product_ids ) {
		$contents = array();
		foreach ( $product_ids as $i => $pid ) {
			$contents[ 'item' . $i ] = array( 'product_id' => $pid, 'line_total' => 100.0, 'line_tax' => 25.0 );
		}
		return $this->fake_cart( $contents, array() );
	}

	/** A cart stand-in with the given coupon codes applied. */
	private function cart_with_coupons( array $codes ) {
		return $this->fake_cart( array(), $codes );
	}

	/** A cart stand-in worth a given amount incl. VAT, for the ladder tests. */
	private function cart_worth( float $incl_vat ) {
		$ex = $incl_vat / 1.25;
		return $this->fake_cart( array( 'item' => array( 'line_total' => $ex, 'line_tax' => $incl_vat - $ex ) ), array() );
	}

	/**
	 * A stand-in for WC_Cart answering only get_cart() and
	 * get_applied_coupons(), which is all the code under test asks of it.
	 */
	private function fake_cart( array $contents, array $coupons ) {
		return new class( $contents, $coupons ) extends WC_Cart {
			private $contents;
			private $coupons;

			public function __construct( array $contents, array $coupons ) {
				$this->contents = $contents;
				$this->coupons  = $coupons;
			}

			public function get_cart() {
				return $this->contents;
			}

			public function get_applied_coupons() {
				return $this->coupons;
			}
		};
	}
}
