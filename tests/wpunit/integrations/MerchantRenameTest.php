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
