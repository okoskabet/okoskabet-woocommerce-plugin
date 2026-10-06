<?php

use okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions;
use okoskabet_woocommerce_plugin\Integrations\Packaging_Fee;

/**
 * The money paths, run against a real WC_Cart: what the customer is actually
 * charged, which copy of a delivery method a fee rule matches, where a ladder
 * step and a cutoff fall. Each test pins one decision that a wrong edit to the
 * code would change without any other test noticing.
 */
class PackagingFeeCartTest extends \Codeception\TestCase\WPTestCase {

	/** @var array */
	private $post_backup;

	public function setUp(): void {
		parent::setUp();
		$this->post_backup = $_POST;
		$_POST             = array();

		delete_option( Packaging_Fee::OPTION_KEY );
		delete_option( Delivery_Exceptions::OPTION_KEY );
		Packaging_Fee::purge_config_cache();
		Delivery_Exceptions::purge_rules_cache();

		update_option( 'woocommerce_default_country', 'DK' );
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_price_num_decimals', 2 );
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'DK',
				'tax_rate'          => '25.0000',
				'tax_rate_name'     => 'Moms',
				'tax_rate_priority' => 1,
				'tax_rate_compound' => 0,
				'tax_rate_shipping' => 1,
				'tax_rate_order'    => 0,
				'tax_rate_class'    => '',
			)
		);

		wc_load_cart();
		WC()->customer->set_billing_country( 'DK' );
		WC()->customer->set_shipping_country( 'DK' );
		WC()->cart->empty_cart();

		// Exactly one fee hook, whatever the plugin bootstrap did or did not register.
		remove_all_actions( 'woocommerce_cart_calculate_fees' );
		add_action( 'woocommerce_cart_calculate_fees', array( new Packaging_Fee(), 'apply' ) );
	}

	public function tearDown(): void {
		$_POST = $this->post_backup;
		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}
		if ( WC()->session ) {
			WC()->session->set( 'chosen_shipping_methods', array() );
		}
		WC()->shipping()->packages = array();
		delete_option( Packaging_Fee::OPTION_KEY );
		delete_option( Delivery_Exceptions::OPTION_KEY );
		Packaging_Fee::purge_config_cache();
		Delivery_Exceptions::purge_rules_cache();
		parent::tearDown();
	}

	// ------------------------------------------------------------- helpers

	private function save_fee( array $rules, array $extra = array() ): void {
		update_option( Packaging_Fee::OPTION_KEY, array_merge( array( 'enabled' => true, 'tax_class' => 'standard', 'rules' => $rules ), $extra ) );
		Packaging_Fee::purge_config_cache();
	}

	private function rule( array $overrides = array() ): array {
		return array_merge(
			array( 'label' => 'Emballage', 'amount' => '28', 'tiers' => '', 'categories' => array(), 'tags' => array(), 'methods' => array(), 'enabled' => true ),
			$overrides
		);
	}

	/** An untaxed, virtual product, so the cart total is the fee and nothing else. */
	private function add_product( array $category_ids = array(), string $price = '0' ): int {
		$p = new WC_Product_Simple();
		$p->set_name( 'Vare' );
		$p->set_regular_price( $price );
		$p->set_tax_status( 'none' );
		$p->set_virtual( true );
		if ( $category_ids ) {
			$p->set_category_ids( $category_ids );
		}
		$p->save();
		WC()->cart->add_to_cart( $p->get_id(), 1 );
		return $p->get_id();
	}

	/** @return array<string,array{amount:float,tax:float}> fee label => ex-VAT amount and VAT */
	private function fees(): array {
		WC()->cart->calculate_totals();
		$out = array();
		foreach ( WC()->cart->get_fees() as $fee ) {
			$out[ $fee->name ] = array( 'amount' => (float) $fee->amount, 'tax' => (float) $fee->tax );
		}
		return $out;
	}

	private function date_offset( int $days ): string {
		$dt = new DateTime( 'today', wp_timezone() );
		$dt->modify( sprintf( '%+d days', $days ) );
		return $dt->format( 'Y-m-d' );
	}

	// ------------------------------------------------------- VAT (mutant C)

	/**
	 * @test
	 * The merchant types the price the customer pays. WooCommerce adds VAT on
	 * top of a fee, so the plugin has to take it out first — or every fee is
	 * charged 25 % too high.
	 */
	public function a_fee_entered_incl_vat_is_what_the_customer_pays() {
		$this->add_product();
		$this->save_fee( array( $this->rule( array( 'amount' => '28' ) ) ) );

		$fees = $this->fees();

		$this->assertSame( 22.4, round( $fees['Emballage']['amount'], 2 ), 'fee is stored ex VAT' );
		$this->assertSame( 5.6, round( $fees['Emballage']['tax'], 2 ) );
		$this->assertSame( '28.00', number_format( (float) WC()->cart->get_total( 'edit' ), 2, '.', '' ), 'the customer pays what was typed' );
	}

	/**
	 * @test
	 * "Amounts are excl. VAT" means exactly that: the amount goes in as typed
	 * and VAT is added on top.
	 */
	public function a_fee_entered_excl_vat_gets_vat_on_top() {
		$this->add_product();
		$this->save_fee( array( $this->rule( array( 'amount' => '28' ) ) ), array( 'ex_tax' => true ) );

		$fees = $this->fees();

		$this->assertSame( 28.0, round( $fees['Emballage']['amount'], 2 ) );
		$this->assertSame( '35.00', number_format( (float) WC()->cart->get_total( 'edit' ), 2, '.', '' ) );
	}

	// ------------------------------------------------- pre-order (mutant A)

	/**
	 * @test
	 * A "pre-orders only" rule is skipped for an ordinary order, so the
	 * catch-all below it is what an ordinary order pays.
	 */
	public function a_pre_order_only_rule_is_skipped_for_an_ordinary_order() {
		$this->add_product();
		$this->save_fee(
			array(
				$this->rule( array( 'label' => 'Forudbestilling', 'amount' => '75', 'pre_order_only' => true ) ),
				$this->rule( array( 'label' => 'Emballage', 'amount' => '28' ) ),
			)
		);

		$this->assertSame( array( 'Emballage' ), array_keys( $this->fees() ) );
	}

	/**
	 * @test
	 * ...and charged when the date posted is a pre-order day, whatever the
	 * hidden pre-order field says.
	 */
	public function a_pre_order_only_rule_is_charged_for_a_pre_order_date_even_with_the_flag_emptied() {
		$cat = (int) wp_insert_term( 'Is', 'product_cat' )['term_id'];
		$this->add_product( array( $cat ) );
		$pre_day = $this->date_offset( 40 );
		update_option(
			Delivery_Exceptions::OPTION_KEY,
			array(
				'only_on_enabled' => true,
				'only_on'         => array( array( 'label' => 'Jul', 'date' => $pre_day, 'enabled' => true, 'extend' => true, 'categories' => array( $cat ), 'tags' => array() ) ),
			)
		);
		Delivery_Exceptions::purge_rules_cache();
		$this->save_fee(
			array(
				$this->rule( array( 'label' => 'Forudbestilling', 'amount' => '75', 'pre_order_only' => true ) ),
				$this->rule( array( 'label' => 'Emballage', 'amount' => '28' ) ),
			)
		);

		$_POST = array( 'billing_okoskabet_pre_order' => '', 'billing_okoskabet_delivery_date' => $pre_day );
		$this->assertSame( array( 'Forudbestilling' ), array_keys( $this->fees() ), 'pre-order day, flag emptied' );

		$_POST = array( 'billing_okoskabet_pre_order' => '1', 'billing_okoskabet_delivery_date' => $this->date_offset( 3 ) );
		$this->assertSame( array( 'Emballage' ), array_keys( $this->fees() ), 'ordinary day, flag forged to 1' );
	}

	// ------------------------------------ copies of one method (mutants B)

	/**
	 * Make the session say the customer chose `$rate_id`, and the calculated
	 * package say which copy of the method produced it — as WooCommerce does.
	 */
	private function choose_rate( string $rate_id, string $method_id, int $instance ): void {
		$rate = new WC_Shipping_Rate( $rate_id, 'Hjemmelevering', 0, array(), $method_id, $instance );
		WC()->shipping()->packages = array( 0 => array( 'rates' => array( $rate_id => $rate ) ) );
		WC()->session->set( 'chosen_shipping_methods', array( 0 => $rate_id ) );
	}

	/**
	 * @test
	 */
	public function a_rule_names_one_copy_of_a_method_or_every_copy() {
		$home = 'hey_okoskabet_shipping_home';

		$this->assertTrue( Packaging_Fee::method_matches( "$home:5", array( "$home:5" ) ), 'the copy the rule names' );
		$this->assertFalse( Packaging_Fee::method_matches( "$home:6", array( "$home:5" ) ), 'another copy' );
		$this->assertTrue( Packaging_Fee::method_matches( "$home:6", array( $home ) ), 'a whole-method rule covers every copy' );
		$this->assertFalse( Packaging_Fee::method_matches( 'hey_okoskabet_shipping_shed:5', array( "$home:5" ) ), 'another method' );
	}

	/**
	 * @test
	 * The Økoskabet rates carry the bare method id, so the copy has to be read
	 * off the rate object: mainland home delivery and island home delivery
	 * look identical in the session.
	 */
	public function the_fee_follows_the_copy_of_the_method_the_customer_chose() {
		$home = 'hey_okoskabet_shipping_home';
		$this->add_product();
		$config = Packaging_Fee::normalise_config(
			array(
				'enabled' => true,
				'rules'   => array(
					$this->rule( array( 'label' => 'Bornholm', 'amount' => '60', 'methods' => array( "$home:6" ) ) ),
					$this->rule( array( 'label' => 'Fastland', 'amount' => '28', 'methods' => array( "$home:5" ) ) ),
				),
			)
		);

		$this->choose_rate( $home, $home, 5 );
		$this->assertSame( 'Fastland', Packaging_Fee::matching_rule( $config, WC()->cart )['label'] ?? null );

		$this->choose_rate( $home, $home, 6 );
		$this->assertSame( 'Bornholm', Packaging_Fee::matching_rule( $config, WC()->cart )['label'] ?? null );
	}

	// ---------------------------------------------------- ladder (mutant E)

	/**
	 * @test
	 * "500 = 15" means from 500, not from just above it. The same ladder text
	 * prices shipping too.
	 */
	public function a_ladder_step_starts_exactly_at_its_threshold() {
		$tiers = oko_parse_shipping_tiers( "0 = 28\n500 = 15\n1000 = 0" );

		$this->assertSame( 28.0, oko_ladder_cost_for_subtotal( $tiers, 499.99 ) );
		$this->assertSame( 15.0, oko_ladder_cost_for_subtotal( $tiers, 500.0 ) );
		$this->assertSame( 0.0, oko_ladder_cost_for_subtotal( $tiers, 1000.0 ) );
	}

	/**
	 * @test
	 */
	public function a_cart_worth_exactly_a_threshold_pays_that_steps_fee() {
		$this->add_product( array(), '500' );
		$this->save_fee( array( $this->rule( array( 'tiers' => "0 = 28\n500 = 15" ) ) ) );

		$this->fees();
		$this->assertSame( '515.00', number_format( (float) WC()->cart->get_total( 'edit' ), 2, '.', '' ), '500 in the basket pays the 500 step' );
	}

	// ---------------------------------------------------- cutoff (mutant D)

	/**
	 * Put a cutoff rule's deadline for `$date` at `$deadline`, `$days` days before it.
	 *
	 * @return array{0:int,1:string} product id, delivery date
	 */
	private function cutoff_at( DateTime $deadline, int $days ): array {
		$tag = (int) wp_insert_term( 'frisk-' . wp_rand(), 'product_tag' )['term_id'];
		$pid = $this->factory()->post->create();
		wp_set_object_terms( $pid, array( $tag ), 'product_tag' );

		$date = ( clone $deadline )->modify( sprintf( '+%d days', $days ) )->format( 'Y-m-d' );
		update_option(
			Delivery_Exceptions::OPTION_KEY,
			array(
				'cutoff_enabled' => true,
				'cutoff_rules'   => array(
					array( 'label' => '', 'days' => $days, 'time' => $deadline->format( 'H:i' ), 'enabled' => true, 'categories' => array(), 'tags' => array( $tag ) ),
				),
			)
		);
		Delivery_Exceptions::purge_rules_cache();

		return array( $pid, $date );
	}

	/**
	 * @test
	 * A rule "1 day before at HH:MM" keeps the date until that minute and
	 * drops it after — not a day earlier, not a day later.
	 */
	public function a_cutoff_closes_a_date_at_its_deadline_and_not_a_day_before() {
		$sut = new Delivery_Exceptions();

		$soon = new DateTime( 'now', wp_timezone() );
		$soon->modify( '+3 minutes' );
		[ $pid, $date ] = $this->cutoff_at( $soon, 1 );
		$this->assertSame( array( $date ), $sut->filter_dates_for_cart( array( $date ), array( $pid ) ), 'deadline a few minutes away: still orderable' );

		$past = new DateTime( 'now', wp_timezone() );
		$past->modify( '-3 minutes' );
		[ $pid, $date ] = $this->cutoff_at( $past, 1 );
		$this->assertSame( array(), $sut->filter_dates_for_cart( array( $date ), array( $pid ) ), 'deadline a few minutes ago: closed' );
	}
}
