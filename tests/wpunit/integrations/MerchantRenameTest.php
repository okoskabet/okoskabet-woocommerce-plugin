<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit\Integrations;

use okoskabet_woocommerce_plugin\Integrations\Merchant_Router;
use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * Renaming a merchant id rewrites the stamp on every one of its orders.
 *
 * The rewrite walks the orders in batches of 200. Every order it rewrites
 * stops matching the query, so asking for the second page skipped the orders
 * that had moved up into the first — and each order left behind answers its
 * webhooks with 403 from then on.
 */
class MerchantRenameTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}
	}

	/** One more than a batch, which is all it takes for one to be left behind. */
	public function test_every_order_is_rewritten_when_there_are_more_than_one_batch(): void {
		$this->orders_of( 'gammel', 201 );

		$this->rename( 'gammel', 'ny' );

		$this->assertSame( array(), $this->still_carrying( 'gammel' ), 'Orders still carrying the old merchant id.' );
	}

	/**
	 * One order whose save does not stick does not stop the rest.
	 *
	 * Here it is the first order of every batch, which is where a stuck order
	 * ends up: the batches are oldest first, and it never leaves the query.
	 */
	public function test_an_order_that_will_not_change_does_not_stop_the_rename(): void {
		$ids   = $this->orders_of( 'gammel', 201 );
		$stuck = $ids[0];

		// Another plugin that puts the old id back on this one order.
		$put_back = static function ( $order ) use ( $stuck ) {
			if ( $order->get_id() === $stuck ) {
				$order->update_meta_data( Merchant_Router::ORDER_META_KEY, 'gammel' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $put_back );

		$this->rename( 'gammel', 'ny' );

		// Only ours: the plugin has its own hook on the same action.
		remove_action( 'woocommerce_before_order_object_save', $put_back );

		$this->assertSame( array( $stuck ), $this->still_carrying( 'gammel' ), 'Only the stuck order keeps the old id.' );
	}

	/**
	 * The renamed account is saved before its orders are.
	 *
	 * Sean's review of 1.5.3 (#77): every order the rename rewrites is saved,
	 * and a save may resend the order. Rewritten before the config knew the
	 * new id, the resend found no such account and fell back to the default
	 * one — an open order booked again under the wrong account.
	 */
	public function test_the_new_id_is_known_when_the_orders_are_saved(): void {
		Merchants::save_config( array(
			'default_merchant_id' => 'standard',
			'merchants'           => array(
				'standard' => array( 'id' => 'standard', 'label' => 'Standard', 'api_key' => 'key-a' ),
				'gammel'   => array( 'id' => 'gammel', 'label' => 'Gammel', 'api_key' => 'key-b' ),
			),
		) );
		$this->orders_of( 'gammel', 1 );

		$known_when_saved = array();
		$record = static function ( $order ) use ( &$known_when_saved ) {
			if ( $order->get_meta( Merchant_Router::ORDER_META_KEY, true ) === 'ny' ) {
				$known_when_saved[] = isset( Merchants::get_config()['merchants']['ny'] );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $record );
		add_filter( 'wp_redirect', array( $this, 'stop_at_the_redirect' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST['merchant'] = array( 'original_id' => 'gammel', 'id' => 'ny', 'label' => 'Ny', 'api_key' => 'key-b' );
		$_POST['_wpnonce'] = wp_create_nonce( Merchants::ACTION_SAVE_MERCHANT );
		$_REQUEST          = $_POST;

		try {
			( new \ReflectionClass( Merchants::class ) )->newInstanceWithoutConstructor()->handle_save_merchant();
			$this->fail( 'The handler ends in a redirect.' );
		} catch ( \RuntimeException $redirected ) {
			$this->assertSame( 'redirect', $redirected->getMessage() );
		} finally {
			remove_action( 'woocommerce_before_order_object_save', $record );
			remove_filter( 'wp_redirect', array( $this, 'stop_at_the_redirect' ) );
			$_POST    = array();
			$_REQUEST = array();
		}

		$this->assertNotSame( array(), $known_when_saved, 'The order was rewritten.' );
		$this->assertNotContains( false, $known_when_saved, 'An order was saved under an id the config did not know yet.' );
	}

	/** `redirect_back()` exits; a test cannot. */
	public function stop_at_the_redirect(): void {
		throw new \RuntimeException( 'redirect' );
	}

	/** @return int[] */
	private function orders_of( string $merchant_id, int $count ): array {
		$ids = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$order = wc_create_order( array( 'status' => 'processing' ) );
			$order->update_meta_data( Merchant_Router::ORDER_META_KEY, $merchant_id );
			$order->save_meta_data();
			$ids[] = $order->get_id();
		}

		return $ids;
	}

	private function rename( string $old, string $new ): void {
		$merchants = ( new \ReflectionClass( Merchants::class ) )->newInstanceWithoutConstructor();
		$rewrite   = new \ReflectionMethod( Merchants::class, 'rewrite_order_meta_merchant' );
		$rewrite->setAccessible( true );
		$rewrite->invoke( $merchants, $old, $new );
	}

	/** @return int[] */
	private function still_carrying( string $merchant_id ): array {
		return array_map( 'intval', (array) wc_get_orders( array(
			'limit'      => -1,
			'return'     => 'ids',
			'meta_key'   => Merchant_Router::ORDER_META_KEY,
			'meta_value' => $merchant_id,
		) ) );
	}
}
