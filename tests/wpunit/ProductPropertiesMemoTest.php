<?php

/**
 * A product's properties are worked out once per request.
 *
 * The memo in oko_product_properties() was stored under the last attribute's
 * key rather than the product's, so a product with a visible attribute was
 * worked out again for every line — and a shipment's whole list of goods is
 * rebuilt each time an addition arrives.
 */
class ProductPropertiesMemoTest extends \Codeception\TestCase\WPTestCase
{
	public function test_a_product_with_an_attribute_is_worked_out_once(): void
	{
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name('Producent');
		$attribute->set_options(array('Gaardmester'));
		$attribute->set_visible(true);

		$product = new \WC_Product_Simple();
		$product->set_name('Rugbrød');
		$product->set_attributes(array($attribute));
		$product->save();

		$asked = 0;
		$count = static function ($terms) use (&$asked) {
			$asked++;

			return $terms;
		};
		add_filter('get_object_terms', $count);

		oko_product_properties($product);
		$first = $asked;
		oko_product_properties($product);

		remove_filter('get_object_terms', $count);

		$this->assertGreaterThan(0, $first, 'The first call works it out.');
		$this->assertSame($first, $asked, 'The second call is answered from the memo.');
	}
}
