<?php

/**
 * The delivery date where the customer reads about the delivery.
 *
 * The order email, the thank-you page and the order view under My account are
 * all built from `get_order_item_totals()`, so the row is added once and shows
 * in all three. It belongs next to the shipping line, not in the note field
 * where it used to end up.
 */
class OrderTotalsDeliveryDateTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );
	}

	private function order_with_date( string $date ): \WC_Order {
		$order = new \WC_Order();
		$order->save();

		if ( $date !== '' ) {
			$order->update_meta_data( '_billing_okoskabet_delivery_date', $date );
			$order->save();
		}

		return wc_get_order( $order->get_id() );
	}

	public function test_the_date_follows_the_shipping_line() {
		$order = $this->order_with_date( '2026-10-01' );

		$rows = apply_filters(
			'woocommerce_get_order_item_totals',
			array(
				'cart_subtotal' => array( 'label' => 'Subtotal:', 'value' => '19,00 kr.' ),
				'shipping'      => array( 'label' => 'Forsendelse:', 'value' => 'Hjemmelevering' ),
				'order_total'   => array( 'label' => 'Total:', 'value' => '39,00 kr.' ),
			),
			$order
		);

		$this->assertSame(
			array( 'cart_subtotal', 'shipping', 'okoskabet_delivery_date', 'order_total' ),
			array_keys( $rows ),
			'the customer reads the date where they read how it arrives'
		);

		$this->assertStringContainsString( '2026', $rows['okoskabet_delivery_date']['value'] );
	}

	public function test_an_order_without_a_date_gets_no_row() {
		$order = $this->order_with_date( '' );

		$rows = apply_filters(
			'woocommerce_get_order_item_totals',
			array( 'order_total' => array( 'label' => 'Total:', 'value' => '39,00 kr.' ) ),
			$order
		);

		$this->assertSame( array( 'order_total' ), array_keys( $rows ) );
	}

	/**
	 * A shop that hides the shipping row — free delivery, or a layout of its
	 * own — must still get the date, and above the total rather than after it.
	 */
	public function test_the_date_lands_before_the_total_when_there_is_no_shipping_row() {
		$order = $this->order_with_date( '2026-10-01' );

		$rows = apply_filters(
			'woocommerce_get_order_item_totals',
			array(
				'cart_subtotal' => array( 'label' => 'Subtotal:', 'value' => '19,00 kr.' ),
				'order_total'   => array( 'label' => 'Total:', 'value' => '19,00 kr.' ),
			),
			$order
		);

		$this->assertSame(
			array( 'cart_subtotal', 'okoskabet_delivery_date', 'order_total' ),
			array_keys( $rows )
		);
	}

	public function test_the_row_is_not_added_twice() {
		$order = $this->order_with_date( '2026-10-01' );
		$rows  = array( 'order_total' => array( 'label' => 'Total:', 'value' => '19,00 kr.' ) );

		$once  = apply_filters( 'woocommerce_get_order_item_totals', $rows, $order );
		$twice = apply_filters( 'woocommerce_get_order_item_totals', $once, $order );

		$this->assertSame( array_keys( $once ), array_keys( $twice ) );
	}
}
