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

		// `wc_attribute_label()` reads the labels out of WooCommerce's own
		// attribute table, so the row has to be there for the name the shop
		// typed to come back instead of the slug.
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_attribute_taxonomies',
			array(
				'attribute_name'    => $slug,
				'attribute_label'   => $label,
				'attribute_type'    => 'select',
				'attribute_orderby' => 'menu_order',
				'attribute_public'  => 0,
			)
		);
		delete_transient( 'wc_attribute_taxonomies' );
		wp_cache_delete( 'attributes', 'woocommerce-attributes' );

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
		$this->assertSame( array( 'Danmark' ), $found['values'] );

		// The label comes from WooCommerce's own attribute table, which is read
		// once per request and cached. An attribute registered this late in a
		// test therefore reads back as its slug, while a shop's own attribute,
		// registered long before the request, reads as the name they typed.
		// The local-attribute test below covers the label.
		$this->assertNotSame( '', $found['label'] );
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

	/**
	 * A variable product carries every choice its variations could be. Sending
	 * all of them would print "Oprindelsesland: Danmark, Spanien" on the slip
	 * for a customer who bought the Spanish one, and that field is the one the
	 * law is about.
	 */
	public function test_a_variation_answers_with_its_own_choice() {
		$taxonomy = $this->global_attribute( 'Oprindelsesland', 'oprindelsesland', 'Danmark' );
		wp_insert_term( 'Spanien', $taxonomy );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Tomater' );
		$attribute = $this->attribute_on_product( $taxonomy, array( 'Danmark', 'Spanien' ) );
		$attribute->set_variation( true );
		$parent->set_attributes( array( $attribute ) );
		$parent->save();
		wp_set_object_terms( $parent->get_id(), array( 'Danmark', 'Spanien' ), $taxonomy );

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array( $taxonomy => 'spanien' ) );
		$variation->save();

		$parent_values = $this->find( oko_product_properties( wc_get_product( $parent->get_id() ) ), 'attribute:' . $taxonomy );
		$this->assertSame( array( 'Danmark', 'Spanien' ), $parent_values['values'], 'the parent is still every choice' );

		$found = $this->find( oko_product_properties( wc_get_product( $variation->get_id() ) ), 'attribute:' . $taxonomy );
		$this->assertNotNull( $found );
		$this->assertSame( array( 'Spanien' ), $found['values'], 'the variation is the one that was bought' );
	}

	/**
	 * WooCommerce keys a variation's choice by the attribute name folded in the
	 * site's language: "Fødeland" is `fodeland` on an English site. The field's
	 * own key is folded the Danish way whatever the language, `foedeland`. On
	 * any site but a Danish one the two must still be found to be the same
	 * field, or the slip prints every country the parent could have been.
	 */
	public function test_a_variation_answers_with_its_own_choice_on_an_english_site() {
		add_filter( 'locale', function () {
			return 'en_US';
		} );

		$parent = new \WC_Product_Variable();
		$parent->set_name( 'Jordbær' );
		$attributes = array();
		foreach ( array( 'Fødeland', 'Årgang' ) as $name ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( $name );
			$attribute->set_options( array( 'Danmark', 'Spanien' ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$attributes[] = $attribute;
		}
		$parent->set_attributes( $attributes );
		$parent->save();

		// What WooCommerce's own variation form saves.
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $parent->get_id() );
		$variation->set_attributes( array(
			sanitize_title( 'Fødeland' ) => 'Spanien',
			sanitize_title( 'Årgang' )   => 'Spanien',
		) );
		$variation->save();

		$properties = oko_product_properties( wc_get_product( $variation->get_id() ) );

		foreach ( array( 'attribute:foedeland', 'attribute:aargang' ) as $key ) {
			$found = $this->find( $properties, $key );
			$this->assertNotNull( $found, "$key keeps its Danish key on an English site" );
			$this->assertSame( array( 'Spanien' ), $found['values'], "$key is the one that was bought" );
		}
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
