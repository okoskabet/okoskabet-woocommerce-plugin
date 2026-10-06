<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit\Integrations;

use okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions;
use okoskabet_woocommerce_plugin\Integrations\Split_Checkout;

/**
 * What happens to the customer's coupons when a basket is split.
 *
 * Splitting rebuilds the cart, and WooCommerce's empty_cart() takes the
 * applied coupons with it. Before this was handled, a customer with 10 % off
 * pressed the split button and arrived at step one paying full price, with
 * nothing on the page saying the coupon had gone.
 *
 * These run against a real WooCommerce cart and WooCommerce's own coupon
 * rules: the point is what the customer is charged, not what a stand-in says.
 *
 * The basket: milk 100 (Mondays only), bread 200 (Wednesdays only) and
 * cornflakes 300 (either). Step one is Monday — milk and cornflakes, 400 —
 * and step two is Wednesday, bread alone, 200.
 */
class SplitCheckoutCouponsTest extends \Codeception\TestCase\WPTestCase {

	const MONDAY    = '2026-10-12';
	const WEDNESDAY = '2026-10-14';

	private $milk;
	private $bread;
	private $flakes;

	/** @var Split_Checkout */
	private $split;

	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'needs WooCommerce loaded' );
		}

		update_option( Delivery_Exceptions::OPTION_KEY, array( 'weekdays_enabled' => true ) );
		update_option( 'woocommerce_enable_coupons', 'yes' );
		update_option( 'woocommerce_calc_taxes', 'no' );

		$this->milk   = $this->product( 'Mælk', '100' );
		$this->bread  = $this->product( 'Brød', '200' );
		$this->flakes = $this->product( 'Cornflakes', '300' );

		$days = array(
			$this->milk   => array( self::MONDAY ),
			$this->bread  => array( self::WEDNESDAY ),
			$this->flakes => array( self::MONDAY, self::WEDNESDAY ),
		);
		$this->split = new class( $days ) extends Split_Checkout {
			private $days;
			public function __construct( array $days ) {
				$this->days = $days;
			}
			protected function delivery_days_for_product( int $product_id, bool $pre_order = false ): ?array {
				return $pre_order ? array() : ( $this->days[ $product_id ] ?? array() );
			}
		};

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );

		wc_load_cart();
		WC()->session->__unset( Split_Checkout::SESSION_KEY );
		WC()->cart->empty_cart();
		wc_clear_notices();
	}

	public function tearDown(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->session->__unset( Split_Checkout::SESSION_KEY );
			WC()->cart->empty_cart();
			wc_clear_notices();
		}
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', array( $this, 'die_handler' ) );
		unset( $_REQUEST['_wpnonce'], $_POST['_wpnonce'] );
		delete_option( Delivery_Exceptions::OPTION_KEY );
		parent::tearDown();
	}

	public function die_handler() {
		return function () {
			throw new \WPAjaxDieContinueException( 'handler finished' );
		};
	}

	// ------------------------------------------------------------- starting

	/**
	 * @test
	 * 10 % off the whole basket is still 10 % off the part being ordered now.
	 */
	public function a_percent_coupon_stays_on_the_first_delivery() {
		$this->basket_with_coupons( array( $this->coupon( 'ti-procent', array( 'discount_type' => 'percent', 'amount' => 10 ) ) ) );
		$this->assertSame( 60.0, (float) WC()->cart->get_discount_total(), 'the whole basket gets 10 %' );

		$this->assertTrue( $this->call( 'ajax_start_split' )['success'] );

		$this->assertSame( array( 'Cornflakes', 'Mælk' ), $this->names_in_cart(), 'step one is Monday' );
		$this->assertSame( array( 'ti-procent' ), WC()->cart->get_applied_coupons() );
		$this->assertSame( 40.0, (float) WC()->cart->get_discount_total(), '10 % of 400' );
		$this->assertSame( array(), $this->notices(), 'nothing to tell the customer: the coupon is where they left it' );
	}

	/**
	 * @test
	 * A minimum spend the whole basket met may not be met by the part being
	 * ordered now. WooCommerce decides that, and the customer is told the
	 * coupon is off — once, by name — rather than finding out from the total.
	 */
	public function a_coupon_the_first_delivery_does_not_qualify_for_is_taken_off_with_a_word() {
		$this->basket_with_coupons( array(
			$this->coupon( 'ti-procent', array( 'discount_type' => 'percent', 'amount' => 10 ) ),
			$this->coupon( 'over-500', array( 'discount_type' => 'fixed_cart', 'amount' => 50, 'minimum_amount' => 500 ) ),
		) );
		$this->assertSame( 110.0, (float) WC()->cart->get_discount_total(), 'the whole basket of 600 gets both' );

		$this->assertTrue( $this->call( 'ajax_start_split' )['success'] );

		$this->assertSame( array( 'ti-procent' ), WC()->cart->get_applied_coupons(), '400 is under the minimum spend' );
		$this->assertSame( 40.0, (float) WC()->cart->get_discount_total() );

		$notices = $this->notices();
		$this->assertCount( 1, $notices, 'one word about the coupon that went, and none about the one that stayed: ' . wp_json_encode( $notices ) );
		$this->assertSame( 'notice', $notices[0]['type'] );
		$this->assertStringContainsString( 'over-500', $notices[0]['text'] );
		$this->assertStringNotContainsString( 'ti-procent', $notices[0]['text'] );
	}

	/**
	 * @test
	 * A coupon for one product, when that product goes out on the other day,
	 * has nothing to discount now.
	 */
	public function a_product_coupon_follows_the_goods_it_was_written_for() {
		$this->basket_with_coupons( array(
			$this->coupon( 'broed', array( 'discount_type' => 'fixed_product', 'amount' => 50, 'product_ids' => array( $this->bread ) ) ),
		) );
		$this->assertSame( 50.0, (float) WC()->cart->get_discount_total() );

		$this->call( 'ajax_start_split' );

		// Nothing here for it to discount, so it comes off, and the customer is
		// told why rather than left to wonder where their discount went.
		$this->assertSame( array(), WC()->cart->get_applied_coupons() );
		$this->assertSame( 0.0, (float) WC()->cart->get_discount_total() );
		$notices = $this->notices();
		$this->assertCount( 1, $notices, wp_json_encode( $notices ) );
		$this->assertStringContainsString( 'broed', $notices[0]['text'] );

		$this->place_order();
		$this->call( 'ajax_resume_split' );

		// And here is the bread. A discount belongs where it applies.
		$this->assertSame( array( 'Brød' ), $this->names_in_cart() );
		$this->assertSame( array( 'broed' ), WC()->cart->get_applied_coupons() );
		$this->assertSame( 50.0, (float) WC()->cart->get_discount_total() );
	}

	/**
	 * @test
	 * Free shipping lives in two places: the coupon, and the shipping method
	 * the session has chosen. empty_cart() clears both, so putting the coupon
	 * back has to put the method back as well, or the customer pays 49 kr
	 * with a free-shipping coupon in plain sight.
	 */
	public function a_free_shipping_coupon_still_ships_the_first_delivery_for_free() {
		$this->shipping_zone();
		$this->basket_with_coupons( array( $this->coupon( 'fri-fragt', array( 'discount_type' => 'fixed_cart', 'amount' => 0, 'free_shipping' => true ) ) ) );
		$this->assertSame( 0.0, (float) WC()->cart->get_shipping_total(), 'free for the whole basket' );

		$this->call( 'ajax_start_split' );
		WC()->cart->calculate_totals();

		$this->assertSame( array( 'fri-fragt' ), WC()->cart->get_applied_coupons() );
		$this->assertSame( 0.0, (float) WC()->cart->get_shipping_total(), 'and for the first delivery' );
	}

	// ------------------------------------------------- the coupon is used once

	/**
	 * @test
	 * A coupon that may be used once is used once: on the first order. The
	 * second delivery is not offered it again, and WooCommerce would refuse it
	 * if the customer typed it in, because the first order has counted it.
	 */
	public function a_single_use_coupon_is_used_by_the_first_order_and_by_no_other() {
		$this->basket_with_coupons( array(
			$this->coupon( 'en-gang', array( 'discount_type' => 'percent', 'amount' => 10, 'usage_limit' => 1, 'usage_limit_per_user' => 1 ) ),
		) );

		$this->call( 'ajax_start_split' );
		$this->assertSame( array( 'en-gang' ), WC()->cart->get_applied_coupons() );

		$order = $this->place_order();
		$this->assertSame( array( 'en-gang' ), $order->get_coupon_codes() );
		$this->assertSame( 40.0, (float) $order->get_discount_total() );
		$this->assertSame( 1, ( new \WC_Coupon( 'en-gang' ) )->get_usage_count() );

		$this->call( 'ajax_resume_split' );

		$this->assertSame( array( 'Brød' ), $this->names_in_cart() );
		$this->assertSame( array(), WC()->cart->get_applied_coupons() );
		$this->assertSame( 0.0, (float) WC()->cart->get_discount_total() );
		$this->assertNotTrue( ( new \WC_Discounts( WC()->cart ) )->is_coupon_valid( new \WC_Coupon( 'en-gang' ) ) );
	}

	/**
	 * @test
	 * A code the shop put no limit on may be used again, so it is offered to
	 * the next delivery too. That is the shop's own doing: an unlimited 100 kr
	 * off is 100 kr off every order anyone places with it, split or not. A code
	 * meant for one use carries a usage limit, and the test above shows what
	 * happens to it.
	 */
	public function a_coupon_that_may_be_used_again_follows_to_the_next_delivery() {
		$this->basket_with_coupons( array( $this->coupon( 'hundrede', array( 'discount_type' => 'fixed_cart', 'amount' => 100 ) ) ) );

		$this->call( 'ajax_start_split' );
		$this->assertSame( array( 'hundrede' ), WC()->cart->get_applied_coupons(), 'step one has it' );

		$this->place_order();
		$this->call( 'ajax_resume_split' );

		$this->assertSame( array( 'Brød' ), $this->names_in_cart() );
		$this->assertSame( array( 'hundrede' ), WC()->cart->get_applied_coupons(), 'and so does step two' );
		$this->assertSame( array(), $this->notices(), 'nothing to explain' );
	}

	/**
	 * @test
	 * Splitting is our doing, not the customer's, and it must not quietly move
	 * their order to another way of getting it. empty_cart() forgets the chosen
	 * method, so it is put back.
	 */
	public function the_customer_keeps_the_delivery_they_chose() {
		$this->shipping_zone();
		$this->basket_with_coupons( array() );

		$chosen = WC()->session->get( 'chosen_shipping_methods' );
		$this->assertNotEmpty( $chosen, 'the customer had picked one' );

		$this->call( 'ajax_start_split' );

		$this->assertSame( $chosen, WC()->session->get( 'chosen_shipping_methods' ) );
	}

	// ------------------------------------------------------------ cancelling

	/**
	 * @test
	 * "Your basket is back as it was" has to include the coupons — the one
	 * still on step one and the one step one did not qualify for.
	 */
	public function cancelling_before_anything_is_ordered_brings_every_coupon_back() {
		$this->basket_with_coupons( array(
			$this->coupon( 'ti-procent', array( 'discount_type' => 'percent', 'amount' => 10 ) ),
			$this->coupon( 'over-500', array( 'discount_type' => 'fixed_cart', 'amount' => 50, 'minimum_amount' => 500 ) ),
		) );
		$this->call( 'ajax_start_split' );
		wc_clear_notices();

		$this->call( 'ajax_cancel_split' );

		$this->assertCount( 3, WC()->cart->get_cart() );
		$this->assertSame( array( 'ti-procent', 'over-500' ), WC()->cart->get_applied_coupons() );
		$this->assertSame( 110.0, (float) WC()->cart->get_discount_total() );
	}

	/**
	 * @test
	 * Once the first order is placed, its coupons are spent. Giving up on the
	 * rest brings back what is left of the basket, not the coupons the first
	 * order already used.
	 */
	public function cancelling_after_an_order_does_not_bring_back_the_coupons_it_used() {
		$this->basket_with_coupons( array( $this->coupon( 'hundrede', array( 'discount_type' => 'fixed_cart', 'amount' => 100 ) ) ) );
		$this->call( 'ajax_start_split' );
		$this->place_order();
		$this->call( 'ajax_resume_split' );

		$this->call( 'ajax_cancel_split' );

		$this->assertSame( array( 'Brød' ), $this->names_in_cart() );
		$this->assertSame( array(), WC()->cart->get_applied_coupons() );
	}

	// --------------------------------------------------------------- helpers

	private function product( string $name, string $price ): int {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		return $product->save();
	}

	private function coupon( string $code, array $props ): string {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_props( $props );
		$coupon->save();
		return $code;
	}

	private function basket_with_coupons( array $codes ): void {
		WC()->cart->add_to_cart( $this->milk, 1 );
		WC()->cart->add_to_cart( $this->bread, 1 );
		WC()->cart->add_to_cart( $this->flakes, 1 );
		WC()->cart->calculate_totals();
		foreach ( $codes as $code ) {
			$this->assertTrue( WC()->cart->apply_coupon( $code ), "$code applies to the whole basket" );
		}
		WC()->cart->calculate_totals();
		wc_clear_notices();
	}

	/** One of the split's AJAX handlers, called the way the banner calls it. */
	private function call( string $handler ): array {
		$action = new \ReflectionMethod( Split_Checkout::class, 'nonce_action' );
		$action->setAccessible( true );
		$_REQUEST['_wpnonce'] = $_POST['_wpnonce'] = wp_create_nonce( $action->invoke( $this->split ) );

		ob_start();
		try {
			$this->split->$handler();
		} catch ( \WPAjaxDieContinueException $e ) {
			// wp_send_json_* ends here.
		} finally {
			$out = (string) ob_get_clean();
		}
		WC()->cart->calculate_totals();

		return (array) json_decode( $out, true );
	}

	/** The current step placed the way the checkout places it, paid by cash on delivery. */
	private function place_order(): \WC_Order {
		update_option( 'woocommerce_cod_settings', array( 'enabled' => 'yes' ) );
		$order_id = WC()->checkout()->create_order( array(
			'payment_method'     => 'cod',
			'billing_email'      => 'kunde@example.com',
			'billing_first_name' => 'Kunde',
			'billing_country'    => 'DK',
		) );
		$this->assertIsInt( $order_id );
		$this->split->on_order_processed( $order_id );
		WC()->payment_gateways()->payment_gateways()['cod']->process_payment( $order_id );
		return wc_get_order( $order_id );
	}

	private function shipping_zone(): void {
		$zone = new \WC_Shipping_Zone( 0 );
		$flat = $zone->add_shipping_method( 'flat_rate' );
		update_option( 'woocommerce_flat_rate_' . $flat . '_settings', array( 'enabled' => 'yes', 'title' => 'Fragt', 'cost' => '49', 'tax_status' => 'none' ) );
		$free = $zone->add_shipping_method( 'free_shipping' );
		update_option( 'woocommerce_free_shipping_' . $free . '_settings', array( 'enabled' => 'yes', 'title' => 'Gratis fragt', 'requires' => 'coupon' ) );
		\WC_Cache_Helper::invalidate_cache_group( 'shipping_zones' );
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		WC()->shipping()->load_shipping_methods();
		WC()->customer->set_shipping_country( 'DK' );
		WC()->customer->set_billing_country( 'DK' );
	}

	/** @return string[] */
	private function names_in_cart(): array {
		$names = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$names[] = $item['data']->get_name();
		}
		sort( $names );
		return $names;
	}

	/** @return array<int, array{type:string, text:string}> */
	private function notices(): array {
		$out = array();
		foreach ( wc_get_notices() as $type => $list ) {
			foreach ( $list as $notice ) {
				$out[] = array( 'type' => $type, 'text' => wp_strip_all_tags( is_array( $notice ) ? $notice['notice'] : $notice ) );
			}
		}
		return $out;
	}
}
