<?php

use okoskabet_woocommerce_plugin\Integrations\Merchant_Router;
use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * An order of a merchant whose key is not filled in stays that merchant's.
 *
 * A shop with two accounts, the second one still being set up: its orders
 * used to be logged and left. Falling back to the default account's key
 * booked them in the other account's packing room instead.
 */
class MerchantWithoutKeyTest extends \Codeception\TestCase\WPTestCase
{
	/** @var array<int,string> */
	private $calls = array();

	public function setUp(): void
	{
		parent::setUp();
		$this->calls = array();

		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		Merchants::save_config(array(
			'default_merchant_id' => 'default',
			'merchants' => array(
				'default' => array('id' => 'default', 'label' => 'A', 'api_key' => 'key-a', 'staging' => true),
				'anden'   => array('id' => 'anden', 'label' => 'B', 'api_key' => '', 'staging' => true),
			),
		));

		add_filter('pre_http_request', function ($preempt, $args, $url) {
			$this->calls[] = ($args['method'] ?? 'GET') . ' ' . $url . ' key=' . ($args['headers']['Authorization'] ?? '');

			return array('response' => array('code' => 201), 'body' => wp_json_encode(array('shipment' => array())));
		}, 1, 3);
	}

	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		parent::tearDown();
	}

	public function test_the_merchant_is_the_routed_one_even_without_a_key(): void
	{
		$order = $this->an_order_of('anden');

		$this->assertSame('anden', oko_merchant_for_order($order)['id'] ?? null);
	}

	public function test_its_order_is_not_sent_under_the_default_accounts_key(): void
	{
		$order = $this->an_order_of('anden');
		$order->update_status('processing');

		$posts = array_filter($this->calls, static function (string $call): bool {
			return strpos($call, 'POST ') === 0;
		});
		$this->assertSame(array(), array_values($posts));
		$this->assertEmpty(wc_get_order($order->get_id())->get_meta('billing_okoskabet_done', true));
	}

	public function test_an_order_with_no_account_at_all_still_goes_to_the_default(): void
	{
		$order = $this->an_order_of('');

		$this->assertSame('default', oko_merchant_for_order($order)['id'] ?? null);
	}

	private function an_order_of(string $merchant_id): \WC_Order
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
		$order->set_shipping_address_1('Byagervej 194');
		$order->set_shipping_postcode('8330');
		$order->set_shipping_city('Beder');
		$order->set_transaction_id('t-1');
		$order->update_meta_data('_billing_okoskabet_delivery_date', '2026-10-27');
		if ($merchant_id !== '') {
			$order->update_meta_data(Merchant_Router::ORDER_META_KEY, $merchant_id);
		}
		$order->calculate_totals(false);
		$order->save();

		return $order;
	}
}
