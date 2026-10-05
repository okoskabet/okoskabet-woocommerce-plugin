<?php

/**
 * What the shop knows about a product, sent so the packing slip can show it.
 *
 * A packing slip has to name the producer and the country the goods come from.
 * One shop keeps those as global attributes, the next writes them straight on
 * the product, a third has a brand taxonomy from some other plugin. Nothing is
 * assumed: everything the product carries goes, and the shop points at the two
 * fields that matter.
 */
class ProductPropertiesTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );
	}

	/**
	 * A global attribute, the kind WooCommerce stores as its own taxonomy.
	 *
	 * Registered by hand rather than through `wc_create_attribute()`, which
	 * lives in a WooCommerce include the test harness does not load, and the
	 * lookup table filled at init is written to directly for the same reason.
	 */
	private function global_attribute( string $label, string $slug, string $value ): string {
		$taxonomy = wc_attribute_taxonomy_name( $slug );

		register_taxonomy( $taxonomy, 'product', array(
			'hierarchical' => false,
			'public'       => true,
			'label'        => $label,
		) );
		wp_insert_term( $value, $taxonomy );

		global $wc_product_attributes;
		$wc_product_attributes[ $taxonomy ] = (object) array(
			'attribute_id'      => 1,
			'attribute_name'    => $slug,
			'attribute_label'   => $label,
			'attribute_type'    => 'select',
			'attribute_orderby' => 'menu_order',
			'attribute_public'  => 0,
		);

		return $taxonomy;
	}

	/** The same attribute, as it sits on a product. */
	private function attribute_on_product( string $taxonomy, array $values ): \WC_Product_Attribute {
		$attribute = new \WC_Product_Attribute();
		$attribute->set_id( 1 );
		$attribute->set_name( $taxonomy );
		$attribute->set_options( $values );
		$attribute->set_visible( true );

		return $attribute;
	}

	private function find( array $properties, string $key ): ?array {
		foreach ( $properties as $property ) {
			if ( $property['key'] === $key ) {
				return $property;
			}
		}

		return null;
	}

	public function test_a_global_attribute_travels_under_its_taxonomy() {
		$taxonomy = $this->global_attribute( 'Oprindelsesland', 'oprindelsesland', 'Danmark' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Æg' );
		$product->save();
		wp_set_object_terms( $product->get_id(), 'Danmark', $taxonomy );

		$product->set_attributes( array( $this->attribute_on_product( $taxonomy, array( 'Danmark' ) ) ) );
		$product->save();

		$properties = oko_product_properties( wc_get_product( $product->get_id() ) );
		$found      = $this->find( $properties, 'attribute:' . $taxonomy );

		$this->assertNotNull( $found, 'the key is built from the taxonomy, so renaming the field cannot move it' );
		$this->assertSame( 'Oprindelsesland', $found['label'] );
		$this->assertSame( array( 'Danmark' ), $found['values'] );
	}

	public function test_an_attribute_written_on_the_product_travels_too() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Mælk' );

		$attribute = new \WC_Product_Attribute();
		$attribute->set_name( 'Producent' );
		$attribute->set_options( array( 'Thise' ) );
		$attribute->set_visible( true );
		$product->set_attributes( array( $attribute ) );
		$product->save();

		$properties = oko_product_properties( wc_get_product( $product->get_id() ) );
		$found      = $this->find( $properties, 'attribute:producent' );

		$this->assertNotNull( $found );
		$this->assertSame( 'Producent', $found['label'] );
		$this->assertSame( array( 'Thise' ), $found['values'] );
	}

	public function test_a_taxonomy_from_some_other_plugin_is_offered_as_well() {
		register_taxonomy( 'product_brand', 'product', array( 'hierarchical' => false, 'public' => true, 'label' => 'Mærker' ) );
		wp_insert_term( 'Hindsholm', 'product_brand' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Grise' );
		$product->save();
		wp_set_object_terms( $product->get_id(), 'Hindsholm', 'product_brand' );

		$properties = oko_product_properties( wc_get_product( $product->get_id() ) );
		$found      = $this->find( $properties, 'taxonomy:product_brand' );

		$this->assertNotNull( $found, 'a shop may keep the producer somewhere we have never heard of' );
		$this->assertSame( array( 'Hindsholm' ), $found['values'] );
	}

	public function test_tags_are_not_offered_twice() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Is' );
		$product->save();
		wp_set_object_terms( $product->get_id(), 'frost', 'product_tag' );

		$properties = oko_product_properties( wc_get_product( $product->get_id() ) );

		$this->assertNull(
			$this->find( $properties, 'taxonomy:product_tag' ),
			'tags travel in their own field'
		);
	}

	/**
	 * WordPress keeps an ampersand in a term name as `&amp;`. Sent on as it is
	 * stored, the packing slip would print the entity itself.
	 */
	public function test_a_name_with_an_ampersand_arrives_as_the_shop_wrote_it() {
		register_taxonomy( 'product_brand', 'product', array( 'hierarchical' => false, 'public' => true, 'label' => 'Mærker' ) );
		wp_insert_term( 'Hansen & Søn', 'product_brand' );

		$product = new \WC_Product_Simple();
		$product->set_name( 'Pølser' );
		$product->save();
		wp_set_object_terms( $product->get_id(), 'Hansen & Søn', 'product_brand' );

		$properties = oko_product_properties( wc_get_product( $product->get_id() ) );
		$found      = $this->find( $properties, 'taxonomy:product_brand' );

		$this->assertNotNull( $found );
		$this->assertSame( array( 'Hansen & Søn' ), $found['values'] );
	}

	public function test_a_product_with_nothing_to_say_sends_nothing() {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Lakridsruller' );
		$product->save();

		$properties = oko_product_properties( wc_get_product( $product->get_id() ) );

		foreach ( $properties as $property ) {
			$this->assertNotEmpty( $property['values'], 'an empty field is nothing to choose between' );
		}
	}
}
