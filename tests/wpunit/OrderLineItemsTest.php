<?php

/**
 * The contents we send Økoskabet with a shipment.
 *
 * The packing room prints a label from these lines, and the shop decides
 * whether the variation belongs on it. That decision is only possible when the
 * product's name and the variation arrive apart — so a variable product must
 * send the parent's name in `name` and the chosen variation in `variant_title`,
 * never the two run together the way WooCommerce writes them on the line.
 */
class OrderLineItemsTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );
	}

	/** A variable product with one attribute and one variation of it. */
	private function variable_product( string $name, string $attribute, string $value, string $sku ): array {
		$parent = new \WC_Product_Variable();
		$parent->set_name( $name );

		$attr = new \WC_Product_Attribute();
		$attr->set_name( $attribute );
		$attr->set_options( array( $value ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$parent->set_attributes( array( $attr ) );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( sanitize_title( $attribute ) => $value ) );
		$variation->set_sku( $sku );
		$variation->save();

		return array( $parent, $variation );
	}

	private function order_with( array $products ): \WC_Order {
		$order = new \WC_Order();
		foreach ( $products as $product ) {
			$order->add_product( $product, 1 );
		}
		$order->save();

		return $order;
	}

	public function test_a_variation_sends_the_parent_name_and_the_variation_apart() {
		list( , $variation ) = $this->variable_product( 'Økologiske hakkebøffer, 8 stk.', 'Størrelse', '3 pakker', 'HAK-3' );

		$lines = oko_order_line_items( $this->order_with( array( $variation ) ) );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'Økologiske hakkebøffer, 8 stk.', $lines[0]['name'], 'the goods are the parent product' );
		$this->assertSame( '3 pakker', $lines[0]['variant_title'], 'and the choice is beside it' );
		$this->assertSame( 'HAK-3', $lines[0]['sku'], 'the SKU still belongs to the variation' );
	}

	public function test_a_simple_product_sends_no_variation_at_all() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Lakridsruller' );
		$product->set_sku( 'LR2' );
		$product->save();

		$lines = oko_order_line_items( $this->order_with( array( $product ) ) );

		$this->assertCount( 1, $lines );
		$this->assertSame( 'Lakridsruller', $lines[0]['name'] );
		$this->assertNull( $lines[0]['variant_title'], 'nothing was chosen, so nothing is sent' );
	}

	public function test_a_line_whose_variation_is_gone_keeps_the_name_it_was_sold_under() {
		list( , $variation ) = $this->variable_product( 'Frugtkasse', 'Størrelse', 'Stor', 'FK-S' );
		$order = $this->order_with( array( $variation ) );

		// The catalogue moves on; the order does not.
		$sold_as = $order->get_items()[ array_key_first( $order->get_items() ) ]->get_name();
		$variation->delete( true );

		$lines = oko_order_line_items( wc_get_order( $order->get_id() ) );

		$this->assertCount( 1, $lines );
		$this->assertSame( $sold_as, $lines[0]['name'], 'the line still says what the customer bought' );
		$this->assertNull( $lines[0]['variant_title'] );
	}
}
