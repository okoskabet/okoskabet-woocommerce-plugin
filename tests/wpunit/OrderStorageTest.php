<?php

use Automattic\WooCommerce\Utilities\OrderUtil;

/**
 * The run is on the order storage it says it is.
 *
 * CI runs this suite twice: on the posts table, and with `OKO_TEST_HPOS=yes`
 * on WooCommerce's own order tables (tests/wpunit/_bootstrap.php). Should the
 * switch stop working — a WooCommerce update that moves the option, say — the
 * second run would quietly test the posts table again and still be green.
 * This fails instead.
 */
class OrderStorageTest extends \Codeception\TestCase\WPTestCase {

	public function test_orders_are_stored_where_the_run_says() {
		$hpos = 'yes' === getenv( 'OKO_TEST_HPOS' );

		$this->assertSame( $hpos, OrderUtil::custom_orders_table_usage_is_enabled() );

		$order = wc_create_order();

		$this->assertSame( $hpos ? 'shop_order_placehold' : 'shop_order', get_post_type( $order->get_id() ) );
	}
}
