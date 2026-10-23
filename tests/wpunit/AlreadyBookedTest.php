<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * Økoskabet answering "I have this one already" is a booking, not a failure.
 *
 * `POST /shipments` answers 200 with the shipment when the reference is
 * already booked — a first attempt that went through while its answer timed
 * out on the way back. Read as a failure, a paid order was marked failed and
 * the checkout threw, for a delivery that was on its way.
 */
class AlreadyBookedTest extends \Codeception\TestCase\WPTestCase
{
	public function setUp(): void
	{
		parent::setUp();
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		Merchants::save_config(array(
			'default_merchant_id' => 'default',
			'merchants' => array('default' => array('id' => 'default', 'label' => 'D', 'api_key' => 'test-key', 'staging' => true)),
		));
	}

	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		parent::tearDown();
	}

	public function test_an_order_okoskabet_already_has_is_booked(): void
	{
		$this->okoskabet_answers(200, array('errors' => array(), 'shipment' => array(
			'status'             => 'registered',
			'shipment_reference' => 'x',
			'customer'           => array('email' => 'Kunde@Example.org'),
		)));

		$order = $this->an_order();
		$order->update_status('processing');

		$order = wc_get_order($order->get_id());
		$this->assertSame('processing', $order->get_status());
		$this->assertNotEmpty($order->get_meta('billing_okoskabet_done', true));
	}

	/**
	 * Somebody else's shipment under the same number is not this order's.
	 *
	 * A shop that renumbers can give a new order the number of an old delivery
	 * that is still live. Taking that as booked would tie the new order to a
	 * stranger's shipment and never send it.
	 */
	public function test_a_strangers_shipment_under_the_same_number_is_not_a_booking(): void
	{
		$this->okoskabet_answers(200, array('errors' => array(), 'shipment' => array(
			'status'             => 'fulfilled',
			'shipment_reference' => 'x',
			'customer'           => array('email' => 'someone-else@example.org'),
		)));

		$order = $this->an_order();
		try {
			$order->update_status('processing');
		} catch (\Exception $e) {
			// The checkout is meant to hear about it.
		}

		$order = wc_get_order($order->get_id());
		$this->assertSame('failed', $order->get_status());
		$this->assertEmpty($order->get_meta('billing_okoskabet_done', true));
	}

	/** A refusal is still a refusal. */
	public function test_a_refusal_still_fails_the_order(): void
	{
		$this->okoskabet_answers(422, array('errors' => array('delivery_date' => array('x')), 'shipment' => null, 'error_message' => 'Nej'));

		$order = $this->an_order();
		try {
			$order->update_status('processing');
		} catch (\Exception $e) {
			// The checkout is meant to hear about it.
		}

		$this->assertSame('failed', wc_get_order($order->get_id())->get_status());
	}

	/** @param array<string,mixed> $body */
	private function okoskabet_answers(int $code, array $body): void
	{
		add_filter('pre_http_request', static function ($preempt, $args, $url) use ($code, $body) {
			if (($args['method'] ?? '') === 'POST' && strpos($url, '/api/v1/shipments') !== false) {
				return array('response' => array('code' => $code), 'body' => wp_json_encode($body));
			}

			return array('response' => array('code' => 200), 'body' => '{}');
		}, 1, 3);
	}

	private function an_order(): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Rugbrød');
		$product->set_regular_price('45');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id('hey_okoskabet_shipping_home');
		$shipping->set_method_title('Hjemmelevering');
		$order->add_item($shipping);
		$order->set_billing_email('kunde@example.org');
		$order->set_shipping_address_1('Eksempelvej 12');
		$order->set_shipping_postcode('8330');
		$order->set_shipping_city('Beder');
		$order->update_meta_data('_billing_okoskabet_delivery_date', '2026-10-27');
		$order->calculate_totals(false);
		$order->save();

		return $order;
	}
}
