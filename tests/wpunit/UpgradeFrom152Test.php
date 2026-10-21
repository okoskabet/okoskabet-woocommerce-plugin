<?php

/**
 * The day a shop updates, its open orders are not sent to Økoskabet again.
 *
 * 1.5.2 kept a fingerprint of what it sent and nothing else. Today's payload
 * is shaped differently — more on each line, no `location` on the address,
 * the note outside the hash — so no 1.5.2 fingerprint ever matched it, and
 * the first save of every open order sent the whole order: over the address
 * the packing room had corrected, the name, the date. A webhook completing
 * the order was save enough. Seen on staging on 21 October 2026.
 *
 * The 1.5.2 payload is written out by hand here, the way 1.5.2 built it, so
 * the test does not lean on the code it is testing.
 */
class UpgradeFrom152Test extends \Codeception\TestCase\WPTestCase
{
	use ConfiguresMerchant;

	/** @var array<int,string> */
	private $puts = array();

	public function setUp(): void
	{
		parent::setUp();
		$this->a_merchant_with_an_account();
		$this->puts = array();

		add_filter('pre_http_request', function ($preempt, $args, $url) {
			if (($args['method'] ?? '') === 'PUT') {
				$this->puts[] = (string) ($args['body'] ?? '');
			}

			return array('response' => array('code' => 200), 'body' => '{}');
		}, 1, 3);
	}

	public function tearDown(): void
	{
		$this->forget_the_merchant();
		parent::tearDown();
	}

	/** Completed by a webhook, nothing else touched: nothing is sent. */
	public function test_an_untouched_order_from_1_5_2_is_not_sent_again(): void
	{
		$order = $this->an_order_sent_by_1_5_2();

		$order->update_status('completed');

		$this->assertSame(array(), $this->puts);
		$this->assertIsArray(
			wc_get_order($order->get_id())->get_meta(OKO_SENT_META, true),
			'The order is upgraded to a record of what was sent.'
		);
	}

	/** And the next real edit sends what it changed, and only that. */
	public function test_the_next_edit_sends_only_what_it_changed(): void
	{
		$order = $this->an_order_sent_by_1_5_2();
		$order->update_status('completed');

		$order = wc_get_order($order->get_id());
		$order->set_billing_phone('+4599887766');
		$order->save();

		$this->assertCount(1, $this->puts);
		$this->assertSame(array('customer' => array('phone' => '+4599887766')), json_decode($this->puts[0], true));
	}

	/** An order that did move since 1.5.2 sent it is still sent, whole, as before. */
	public function test_an_order_that_moved_since_is_still_sent(): void
	{
		$order = $this->an_order_sent_by_1_5_2();
		$order->set_shipping_address_1('Byagervej 196');
		$order->save();

		$this->assertCount(1, $this->puts);
		$this->assertSame('Byagervej 196', json_decode($this->puts[0], true)['home_delivery']['address_1'] ?? null);
	}

	/**
	 * A home delivery, booked by 1.5.2, with a driver's note and a customer note.
	 */
	private function an_order_sent_by_1_5_2(): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Økologisk ribbensteg');
		$product->set_regular_price('199');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id('hey_okoskabet_shipping_home');
		$shipping->set_method_title('Hjemmelevering');
		$order->add_item($shipping);

		$order->set_billing_first_name('Pernille');
		$order->set_billing_last_name('Hansen');
		$order->set_billing_phone('+4512345678');
		$order->set_billing_email('pernille@example.org');
		$order->set_shipping_first_name('Pernille');
		$order->set_shipping_last_name('Hansen');
		$order->set_shipping_address_1('Byagervej 194');
		$order->set_shipping_city('Beder');
		$order->set_shipping_postcode('8330');
		$order->set_customer_note('Ring på');
		$order->update_meta_data('_billing_okoskabet_delivery_date', '2026-10-27');
		$order->update_meta_data('_billing_okoskabet_delivery_location', 'In front of the door');
		$order->update_meta_data('_billing_okoskabet_delivery_note', 'Bag skuret');
		$order->update_meta_data('billing_okoskabet_done', true);
		$order->set_status('processing');
		$order->calculate_totals(false);
		$order->save();

		// What 1.5.2's oko_shipment_payload() built for it, less the two keys
		// its fingerprint left out (`locale` and `webshop_products_url`).
		$sent_by_1_5_2 = array(
			'allow_invalid'           => true,
			'shipment_reference'      => (string) $order->get_order_number(),
			'webshop_order_number'    => (string) $order->get_id(),
			'webshop_order_reference' => (string) $order->get_order_number(),
			'customer'                => array(
				'first_name'     => 'Pernille',
				'last_name'      => 'Hansen',
				'phone'          => '+4512345678',
				'email'          => 'pernille@example.org',
				'recipient_name' => 'Pernille Hansen',
			),
			'notes'                   => "In front of the door — Bag skuret\nRing på",
			'delivery_date'           => '2026-10-27',
			'line_items'              => array(array(
				'product_id'    => $product->get_id(),
				'variant_id'    => null,
				'name'          => 'Økologisk ribbensteg',
				'variant_title' => null,
				'sku'           => '',
				'quantity'      => 1,
			)),
			'home_delivery'           => array(
				'recipient_name' => 'Pernille Hansen',
				'address_1'      => 'Byagervej 194',
				'city'           => 'Beder',
				'postal_code'    => '8330',
				'location'       => 'In front of the door — Bag skuret',
			),
		);

		$order->update_meta_data(OKO_SENT_FINGERPRINT_META, md5((string) wp_json_encode($sent_by_1_5_2)));
		$order->save_meta_data();
		$this->puts = array();

		return wc_get_order($order->get_id());
	}
}
