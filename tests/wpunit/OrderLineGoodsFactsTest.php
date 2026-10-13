<?php

/**
 * The goods' own facts travel on the line, not fetched back afterwards.
 *
 * Økoskabet used to ask the shop for tags, properties and the picture once the
 * packing room opened the day. That call goes the other way — in to the shop —
 * and it can be stopped before WordPress ever sees it. At Gaardmester it is:
 * the lookup answers 403, so there is no producer, no country of origin and no
 * tag to sort the dispatch notes by, and nothing says why.
 *
 * Sent on the line, it cannot be stopped: the order is already on its way out.
 * The lookup stays, as the way to refresh what the order carried.
 */
class OrderLineGoodsFactsTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );
	}

	private function order_with( \WC_Product $product ): \WC_Order {
		$order = new \WC_Order();
		$order->add_product( $product, 1 );
		$order->save();

		return $order;
	}

	public function test_a_line_carries_the_tags_the_packing_room_sorts_by() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Juleand' );
		$product->save();

		wp_set_object_terms( $product->get_id(), array( 'frost', 'fjerkræ' ), 'product_tag' );

		$lines = oko_order_line_items( $this->order_with( $product ) );

		$this->assertCount( 1, $lines );
		sort( $lines[0]['tags'] );
		$this->assertSame( array( 'fjerkræ', 'frost' ), $lines[0]['tags'] );
	}

	/**
	 * Tags live on the product, never on the variation. Asking the variation
	 * answers nothing, and the line would fall out of its zone — which is the
	 * one thing tags are for.
	 */
	public function test_a_variation_carries_its_parents_tags() {
		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Hakkebøffer' );
		$parent->save();

		wp_set_object_terms( $parent->get_id(), array( 'frost' ), 'product_tag' );

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->save();

		$lines = oko_order_line_items( $this->order_with( $variation ) );

		$this->assertSame( array( 'frost' ), $lines[0]['tags'], 'read on the parent’s behalf' );
	}

	/** A variation without its own picture shows the parent's, as the shop does. */
	public function test_a_variation_without_a_picture_falls_back_to_its_parent() {
		$attachment = $this->factory()->attachment->create_object(
			array( 'file' => 'billede.jpg', 'post_mime_type' => 'image/jpeg' )
		);

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Æg' );
		$parent->set_image_id( $attachment );
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->save();

		$lines = oko_order_line_items( $this->order_with( $variation ) );

		$this->assertNotSame( '', $lines[0]['image'], 'the packing room sees what the customer saw' );
		$this->assertSame( wp_get_attachment_url( $attachment ), $lines[0]['image'] );
	}

	/** No picture is not an error: an empty string, never null. */
	public function test_a_product_without_a_picture_sends_an_empty_string() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Gulerødder' );
		$product->save();

		$lines = oko_order_line_items( $this->order_with( $product ) );

		$this->assertSame( '', $lines[0]['image'] );
	}

	/** Every line carries all three keys, so the other end never has to ask. */
	public function test_the_three_keys_are_always_there() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Mælk' );
		$product->save();

		$lines = oko_order_line_items( $this->order_with( $product ) );

		$this->assertArrayHasKey( 'tags', $lines[0] );
		$this->assertArrayHasKey( 'properties', $lines[0] );
		$this->assertArrayHasKey( 'image', $lines[0] );
		$this->assertIsArray( $lines[0]['tags'] );
		$this->assertIsArray( $lines[0]['properties'] );
		$this->assertIsString( $lines[0]['image'] );
	}
}
