<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * What the packing room is told an order contains once money has gone back
 * to the customer. A refunded product line is goods taken off the order; a
 * refunded fee is money and nothing else. Each test pins one of those, run
 * through wc_create_refund the way a shop makes a refund.
 */
class OrderLineItemsTest extends \Codeception\TestCase\WPTestCase {

	/** @var array<int,array{method:string,url:string,body:string}> */
	private $requests = array();

	public function setUp(): void {
		parent::setUp();
		delete_option( Merchants::OPTION_KEY );
		Merchants::purge_config_cache();
		Merchants::save_config(
			array(
				'default_merchant_id' => 'default',
				'merchants'           => array(
					'default' => array( 'id' => 'default', 'label' => 'Default', 'api_key' => 'test-key', 'staging' => true ),
				),
			)
		);

		$this->requests = array();
		add_filter( 'pre_http_request', array( $this, 'capture' ), 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture' ), 10 );
		delete_option( Merchants::OPTION_KEY );
		Merchants::purge_config_cache();
		parent::tearDown();
	}

	public function capture( $preempt, $args, $url ) {
		$this->requests[] = array( 'method' => (string) ( $args['method'] ?? 'GET' ), 'url' => (string) $url, 'body' => (string) ( $args['body'] ?? '' ) );
		return array( 'headers' => array(), 'body' => '{"ok":true}', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}

	// ------------------------------------------------------------- helpers

	/**
	 * An order Økoskabet already has: two apples, the packaging fee and
	 * delivery to a shed, with the fingerprint of what was sent on it.
	 */
	private function sent_order(): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( 'Æbler' );
		$product->set_regular_price( '10' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 2 );

		$fee = new WC_Order_Item_Fee();
		$fee->set_name( 'Emballage' );
		$fee->set_total( '22.40' );
		$order->add_item( $fee );

		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_id( 'hey_okoskabet_shipping_shed' );
		$shipping->set_method_title( 'Skab' );
		$shipping->set_total( '39' );
		$order->add_item( $shipping );

		$order->update_meta_data( '_billing_okoskabet_delivery_date', '2026-10-09' );
		$order->update_meta_data( '_billing_okoskabet_shed_id', '42' );
		$order->calculate_totals( false );
		$order->set_status( 'processing' );
		$order->save();

		$order->update_meta_data( 'billing_okoskabet_done', 'yes' );
		$order->update_meta_data( OKO_SENT_FINGERPRINT_META, oko_shipment_fingerprint( oko_shipment_payload( $order, o_get_merchant() ) ) );
		$order->save();

		$this->requests = array();
		return $order;
	}

	private function item_of_type( WC_Order $order, string $type ): WC_Order_Item {
		$items = $order->get_items( $type );
		return reset( $items );
	}

	private function refund( WC_Order $order, WC_Order_Item $item, int $qty, float $total ): void {
		$refund = wc_create_refund(
			array(
				'order_id'       => $order->get_id(),
				'amount'         => $total,
				'line_items'     => array( $item->get_id() => array( 'qty' => $qty, 'refund_total' => $total ) ),
				'refund_payment' => false,
				'restock_items'  => false,
			)
		);
		$this->assertNotWPError( $refund );
	}

	/** @return array<string,int> line name => quantity sent */
	private function lines( WC_Order $order ): array {
		$out = array();
		foreach ( oko_order_line_items( wc_get_order( $order->get_id() ) ) as $line ) {
			$out[ $line['name'] ] = $line['quantity'];
		}
		return $out;
	}

	// ------------------------------------------------------------- refunds

	/**
	 * @test
	 * WooCommerce gives a fee a quantity of 1, refund lines included, so
	 * counting a refunded fee the way a refunded product is counted adds one
	 * where it should take one off. The packing room was sent the fee twice.
	 */
	public function a_refunded_fee_was_never_a_line_to_begin_with() {
		$order = $this->sent_order();

		$this->refund( $order, $this->item_of_type( $order, 'fee' ), 0, 22.40 );

		// The packing room is told what to put in the box, and a packaging fee
		// is not something anyone packs. It is never sent, so refunding it
		// cannot change what is (Marc, 6 October).
		$this->assertSame( array( 'Æbler' => 2 ), $this->lines( $order ) );
	}

	/**
	 * @test
	 * Two partial refunds of the same fee: one fee, not three.
	 */
	public function a_fee_refunded_twice_still_never_reaches_the_packing_room() {
		$order = $this->sent_order();
		$fee   = $this->item_of_type( $order, 'fee' );

		$this->refund( $order, $fee, 0, 10.00 );
		$this->refund( $order, $fee, 0, 5.00 );

		$this->assertSame( array( 'Æbler' => 2 ), $this->lines( $order ) );
	}

	/**
	 * @test
	 * Refunding only the fee takes no goods off the order, so there is
	 * nothing new to tell the packing room.
	 */
	public function refunding_the_fee_sends_no_new_lines() {
		$order = $this->sent_order();

		$this->refund( $order, $this->item_of_type( $order, 'fee' ), 0, 22.40 );

		$puts = array_values( array_filter( $this->requests, function ( $r ) { return $r['method'] === 'PUT'; } ) );
		$this->assertSame( array(), $puts, 'a fee refund leaves the shipment as it was' );
	}

	/**
	 * @test
	 * A refunded product is how a shop takes goods off an order that has
	 * been sent: one of two apples refunded, one apple packed and the
	 * shipment updated to say so.
	 */
	public function a_refunded_product_is_taken_off_the_shipment() {
		$order = $this->sent_order();

		$this->refund( $order, $this->item_of_type( $order, 'line_item' ), 1, 10.00 );

		$this->assertSame( array( 'Æbler' => 1 ), $this->lines( $order ) );

		$puts = array_values( array_filter( $this->requests, function ( $r ) { return $r['method'] === 'PUT'; } ) );
		$this->assertCount( 1, $puts );
		$sent = array();
		foreach ( json_decode( $puts[0]['body'], true )['line_items'] as $line ) {
			$sent[ $line['name'] ] = $line['quantity'];
		}
		$this->assertSame( array( 'Æbler' => 1 ), $sent );
	}

	/**
	 * @test
	 * Every apple refunded: the line goes, the fee stays.
	 */
	public function a_fully_refunded_product_leaves_the_shipment() {
		$order = $this->sent_order();

		$this->refund( $order, $this->item_of_type( $order, 'line_item' ), 2, 20.00 );

		// Nothing left to pack. The packaging fee is still on the order and
		// still charged; it is simply not something the packing room is shown.
		$this->assertSame( array(), $this->lines( $order ) );
	}
}
