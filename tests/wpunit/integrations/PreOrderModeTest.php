<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit\Integrations;

use okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions;
use okoskabet_woocommerce_plugin\Integrations\Merchants;
use okoskabet_woocommerce_plugin\Integrations\Split_Checkout;

/**
 * The pre-order mode as the shop runs it: the real resolver in
 * functions/functions.php, the real AJAX handlers, a real WooCommerce cart and
 * session. Only Økoskabet is stood in for, at the HTTP boundary.
 *
 * The standalone suite exercises a hand copy of oko_pre_order_checkout_requested()
 * and writes the move straight into the session, so it cannot see the resolver
 * or the move endpoint change. These tests can.
 */
class PreOrderModeTest extends \Codeception\TestCase\WPTestCase {

	/** @var bool Whether the stand-in Økoskabet answers at all. */
	private $api_up = true;

	/** @var callable */
	private $http_stub;

	public function setUp(): void {
		parent::setUp();
		$this->api_up = true;
		$this->http_stub = function ( $pre, $args, $url ) {
			if ( strpos( (string) $url, '/api/v1/home_delivery' ) === false ) {
				return $pre;
			}
			if ( ! $this->api_up ) {
				return new \WP_Error( 'http_request_failed', 'down' );
			}
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			$dates = array();
			for ( $i = 1; $i <= (int) ( $q['maximum_days_in_future'] ?? 3 ); $i++ ) {
				$dates[] = $this->day( $i );
			}
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'delivery_dates' => $dates ) ),
				'response' => array( 'code' => 200, 'message' => 'OK' ),
				'cookies'  => array(),
			);
		};
		add_filter( 'pre_http_request', $this->http_stub, 10, 3 );

		Merchants::save_config( array(
			'version'             => 1,
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array( 'id' => 'default', 'label' => 'Default', 'api_key' => 'k', 'webhook_secret' => 's', 'maximum_days_in_future' => 7 ),
			),
		) );
		Merchants::purge_config_cache();
		update_option( O_TEXTDOMAIN . '-settings', array( '_split_checkout_enabled' => 'on' ) );
		delete_option( Delivery_Exceptions::OPTION_KEY );
		Delivery_Exceptions::purge_rules_cache();

		WC()->frontend_includes();
		if ( ! WC()->session ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}
		WC()->session->__unset( Split_Checkout::MOVES_KEY );
		WC()->session->__unset( Split_Checkout::MOVES_MODE_KEY );
		WC()->customer = new \WC_Customer( 0, true );
		WC()->customer->set_shipping_postcode( '2791' );
		WC()->customer->set_billing_postcode( '2791' );
		if ( ! WC()->cart ) {
			WC()->cart = new \WC_Cart();
		}
		WC()->cart->empty_cart( false );
		$_GET = $_POST = $_REQUEST = array();
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', $this->http_stub, 10 );
		$_GET = $_POST = $_REQUEST = array();
		WC()->cart->empty_cart( false );
		delete_option( Delivery_Exceptions::OPTION_KEY );
		Delivery_Exceptions::purge_rules_cache();
		parent::tearDown();
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	private function day( int $offset ): string {
		$d = new \DateTime( 'today', wp_timezone() );
		$d->modify( sprintf( '%+d days', $offset ) );
		return $d->format( 'Y-m-d' );
	}

	private function category( string $slug ): int {
		$term = wp_insert_term( $slug . '-' . wp_generate_password( 6, false ), 'product_cat' );
		return (int) $term['term_id'];
	}

	private function product( string $name, array $cats = array() ): int {
		$p = new \WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( '10' );
		$p->set_category_ids( $cats );
		$p->save();
		return $p->get_id();
	}

	private function rules( array $config ): void {
		update_option( Delivery_Exceptions::OPTION_KEY, $config );
		Delivery_Exceptions::purge_rules_cache();
	}

	/** Ice tied to +30, cornflakes that can also wait until +40: two deliveries. */
	private function cornflakes_and_ice(): array {
		$is = $this->category( 'is' );
		$cf = $this->category( 'cf' );
		$this->rules( array(
			'only_on_enabled'    => true,
			'only_on'            => array( array( 'date' => $this->day( 30 ), 'enabled' => true, 'categories' => array( $is ), 'tags' => array() ) ),
			'from_until_enabled' => true,
			'from_until'         => array( array( 'from' => $this->day( 0 ), 'until' => $this->day( 40 ), 'enabled' => true, 'extend' => true, 'categories' => array( $cf ), 'tags' => array() ) ),
		) );
		$corn = $this->product( 'Cornflakes', array( $cf ) );
		$ice  = $this->product( 'Is', array( $is ) );

		return array(
			'corn' => WC()->cart->add_to_cart( $corn, 1 ),
			'ice'  => WC()->cart->add_to_cart( $ice, 1 ),
		);
	}

	/** Call a real wp_ajax handler the way admin-ajax.php would; return its JSON. */
	private function ajax( string $method, array $post ): array {
		$cid               = (string) WC()->session->get_customer_id();
		$_POST             = $post;
		$_POST['_wpnonce'] = wp_create_nonce( 'oko_split_' . ( $cid !== '' ? $cid : '0' ) );
		$_REQUEST          = $_POST;
		$die               = static function () {
			return static function () {
				throw new \RuntimeException( 'wp_die' );
			};
		};
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter( 'wp_die_ajax_handler', $die );
		ob_start();
		try {
			( new Split_Checkout() )->$method();
		} catch ( \RuntimeException $e ) {
			// wp_send_json ends here.
		}
		$out = (string) ob_get_clean();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_filter( 'wp_die_ajax_handler', $die );
		$_POST = $_REQUEST = array();

		return (array) json_decode( $out, true );
	}

	// ---------------------------------------------------------------------
	// Where an only-on date belongs
	// ---------------------------------------------------------------------

	/**
	 * The last ordinary day is still an ordinary day: no pre-order, no fee.
	 * The day after it is the first pre-order day.
	 */
	public function test_an_only_on_date_on_the_horizon_is_ordinary_and_the_next_day_a_pre_order(): void {
		$cat  = $this->category( 'lock' );
		$pid  = $this->product( 'Locked', array( $cat ) );
		$days = array();
		for ( $i = 1; $i <= 10; $i++ ) {
			$days[] = $this->day( $i );
		}
		$sut = new Delivery_Exceptions();

		foreach ( array( 5 => true, 6 => false ) as $offset => $ordinary ) {
			$this->rules( array(
				'display_mode'    => 'window',
				'display_value'   => 5,
				'only_on_enabled' => true,
				'only_on'         => array( array( 'date' => $this->day( $offset ), 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ) ),
			) );

			$this->assertSame( $ordinary ? array( $this->day( $offset ) ) : array(), $sut->filter_dates_for_cart( $days, array( $pid ), false ), "normal list, day +$offset" );
			$this->assertSame( $ordinary ? array() : array( $this->day( $offset ) ), $sut->filter_dates_for_cart( $days, array( $pid ), true ), "pre-order list, day +$offset" );
			$this->assertSame( ! $ordinary, Delivery_Exceptions::cart_is_pre_order_only( array( $pid ) ), "pre-order only, day +$offset" );
		}
	}

	/**
	 * With no display limit the ordinary days are the merchant's own window,
	 * and it is the merchant of THIS basket — a 14-day merchant's day +7 is an
	 * ordinary delivery day, not a pre-order with a fee.
	 */
	public function test_the_horizon_is_the_window_of_the_baskets_merchant(): void {
		Merchants::save_config( array(
			'version'             => 1,
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array( 'id' => 'default', 'label' => 'Default', 'api_key' => 'k', 'webhook_secret' => 's', 'maximum_days_in_future' => 14 ),
			),
		) );
		Merchants::purge_config_cache();
		$cat = $this->category( 'lock' );
		$pid = $this->product( 'Locked', array( $cat ) );
		$this->rules( array(
			'only_on_enabled' => true,
			'only_on'         => array( array( 'date' => $this->day( 7 ), 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ) ),
		) );

		$this->assertSame( array( $this->day( 7 ) ), oko_home_delivery_dates( '2791', array( $pid ), false ) );
		$this->assertSame( array(), oko_home_delivery_dates( '2791', array( $pid ), true ) );
		$this->assertFalse( Delivery_Exceptions::cart_is_pre_order_only( array( $pid ) ) );
	}

	// ---------------------------------------------------------------------
	// The resolver: oko_pre_order_checkout_requested()
	// ---------------------------------------------------------------------

	/** A basket held past the ordinary days opens as a pre-order even when the link says no. */
	public function test_a_pre_order_only_basket_is_a_pre_order_whatever_the_link_says(): void {
		$cat = $this->category( 'jul' );
		$this->rules( array(
			'display_mode'    => 'window',
			'display_value'   => 5,
			'only_on_enabled' => true,
			'only_on'         => array( array( 'date' => $this->day( 20 ), 'enabled' => true, 'categories' => array( $cat ), 'tags' => array() ) ),
		) );
		WC()->cart->add_to_cart( $this->product( 'Julekage', array( $cat ) ), 1 );
		$_GET['oko_pre_order'] = '0';

		$this->assertTrue( oko_pre_order_checkout_requested() );
	}

	// ---------------------------------------------------------------------
	// The move endpoint
	// ---------------------------------------------------------------------

	/** Only a move the page offers is obeyed; a hand-written one changes nothing. */
	public function test_the_move_endpoint_refuses_a_delivery_the_page_does_not_offer(): void {
		$keys                  = $this->cornflakes_and_ice();
		$_GET['oko_pre_order'] = '0';

		// The ice cannot travel on an ordinary day, so the ordinary delivery is
		// not on offer for it, however the request is written.
		$res = $this->ajax( 'ajax_move_split_item', array( 'key' => $keys['ice'], 'target' => 'normal|' . $this->day( 1 ), 'oko_pre_order' => '0' ) );

		$this->assertFalse( $res['success'] ?? null, wp_json_encode( $res ) );
		$this->assertSame( array(), (array) WC()->session->get( Split_Checkout::MOVES_KEY, array() ) );
		$this->assertNull( WC()->session->get( Split_Checkout::MOVES_MODE_KEY, null ) );
	}

	/**
	 * Moving the last ordinary item in with the pre-order settles the basket:
	 * the next checkout opens as a pre-order without anyone saying so.
	 */
	public function test_a_move_that_gathers_the_basket_settles_it_as_a_pre_order(): void {
		$keys                  = $this->cornflakes_and_ice();
		$_GET['oko_pre_order'] = '0';

		$res = $this->ajax( 'ajax_move_split_item', array( 'key' => $keys['corn'], 'target' => 'pre_order|' . $this->day( 30 ), 'oko_pre_order' => '0' ) );
		$this->assertTrue( $res['success'] ?? null, wp_json_encode( $res ) );

		$_GET = array();
		// Only the session here: oko_pre_order_checkout_requested() keeps its
		// fallback guess in a function static for the rest of the process, so
		// the first test to reach that guess decides it for every later one.
		// The test below is the one that has to reach it first.
		$this->assertTrue( Split_Checkout::settled_mode() );
	}

	/**
	 * The arrangement is what decides the next page, not a fresh guess: with
	 * Økoskabet unreachable the guess would be "ordinary", and the customer who
	 * just sent their basket to December would land in a checkout with no day.
	 */
	public function test_a_settled_arrangement_holds_when_okoskabet_cannot_be_asked(): void {
		$keys                  = $this->cornflakes_and_ice();
		$_GET['oko_pre_order'] = '0';
		$res                   = $this->ajax( 'ajax_move_split_item', array( 'key' => $keys['corn'], 'target' => 'pre_order|' . $this->day( 30 ), 'oko_pre_order' => '0' ) );
		$this->assertTrue( $res['success'] ?? null, wp_json_encode( $res ) );

		$_GET          = array();
		$this->api_up  = false;
		// A postcode not asked before, so no answer from earlier in the request is reused.
		WC()->customer->set_shipping_postcode( '8000' );

		$this->assertTrue( oko_pre_order_checkout_requested() );
	}

	/** The arrangement belongs to the basket it was made for. */
	public function test_a_move_is_forgotten_once_the_basket_changes(): void {
		$keys                  = $this->cornflakes_and_ice();
		$_GET['oko_pre_order'] = '0';
		$this->ajax( 'ajax_move_split_item', array( 'key' => $keys['corn'], 'target' => 'pre_order|' . $this->day( 30 ), 'oko_pre_order' => '0' ) );
		$_GET = array();
		$this->assertTrue( Split_Checkout::settled_mode() );

		WC()->cart->remove_cart_item( $keys['ice'] );

		$this->assertNull( Split_Checkout::settled_mode() );
	}
}
