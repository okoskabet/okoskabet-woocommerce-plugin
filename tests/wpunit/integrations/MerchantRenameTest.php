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
}
