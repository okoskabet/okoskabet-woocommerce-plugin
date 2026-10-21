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

	/** One more than a batch, which is all it takes for one to be left behind. */
	public function test_every_order_is_rewritten_when_there_are_more_than_one_batch(): void {
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}

		$ids = array();
		for ( $i = 0; $i < 201; $i++ ) {
			$order = wc_create_order();
			$order->update_meta_data( Merchant_Router::ORDER_META_KEY, 'gammel' );
			$order->save();
			$ids[] = $order->get_id();
		}

		$merchants = ( new \ReflectionClass( Merchants::class ) )->newInstanceWithoutConstructor();
		$rewrite   = new \ReflectionMethod( Merchants::class, 'rewrite_order_meta_merchant' );
		$rewrite->setAccessible( true );
		$rewrite->invoke( $merchants, 'gammel', 'ny' );

		$left = array();
		foreach ( $ids as $id ) {
			if ( wc_get_order( $id )->get_meta( Merchant_Router::ORDER_META_KEY, true ) !== 'ny' ) {
				$left[] = $id;
			}
		}

		$this->assertSame( array(), $left, 'Orders still carrying the old merchant id.' );
	}

	/**
	 * One order whose save does not stick does not stop the rest.
	 *
	 * Here it is the first order of every batch, which is where a stuck order
	 * ends up: the batches are oldest first, and it never leaves the query.
	 */
	public function test_an_order_that_will_not_change_does_not_stop_the_rename(): void {
		if ( ! function_exists( 'wc_create_order' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded.' );
		}

		$ids = array();
		for ( $i = 0; $i < 201; $i++ ) {
			$order = wc_create_order();
			$order->update_meta_data( Merchant_Router::ORDER_META_KEY, 'gammel' );
			$order->save();
			$ids[] = $order->get_id();
		}
		$stuck = $ids[0];

		// Another plugin that puts the old id back on this one order.
		$put_back = static function ( $order ) use ( $stuck ) {
			if ( $order->get_id() === $stuck ) {
				$order->update_meta_data( Merchant_Router::ORDER_META_KEY, 'gammel' );
			}
		};
		add_action( 'woocommerce_before_order_object_save', $put_back );

		$merchants = ( new \ReflectionClass( Merchants::class ) )->newInstanceWithoutConstructor();
		$rewrite   = new \ReflectionMethod( Merchants::class, 'rewrite_order_meta_merchant' );
		$rewrite->setAccessible( true );
		$rewrite->invoke( $merchants, 'gammel', 'ny' );

		// Only ours: the plugin has its own hook on the same action.
		remove_action( 'woocommerce_before_order_object_save', $put_back );

		$left = array();
		foreach ( $ids as $id ) {
			if ( wc_get_order( $id )->get_meta( Merchant_Router::ORDER_META_KEY, true ) !== 'ny' ) {
				$left[] = $id;
			}
		}

		$this->assertSame( array( $stuck ), $left, 'Only the stuck order keeps the old id.' );
	}
}
