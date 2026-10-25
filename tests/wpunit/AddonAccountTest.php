<?php

use okoskabet_woocommerce_plugin\Integrations\Merchant_Router;
use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * An addition is written onto the earlier order's shipment under that order's
 * account, and only when Økoskabet is carrying the addition at all.
 */
class AddonAccountTest extends \Codeception\TestCase\WPTestCase
{
	/** @var array<int,string> */
	private $keys = array();

	public function setUp(): void
	{
		parent::setUp();
		$this->keys = array();

		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		Merchants::save_config(array(
			'default_merchant_id' => 'default',
			'merchants' => array(
				'default' => array('id' => 'default', 'label' => 'A', 'api_key' => 'key-a', 'staging' => true),
				'gaard'   => array('id' => 'gaard', 'label' => 'B', 'api_key' => 'key-b', 'staging' => true),
				'tom'     => array('id' => 'tom', 'label' => 'C', 'api_key' => '', 'staging' => true),
			),
		));
		foreach (array('default', 'gaard') as $id) {
			set_transient(o_configuration_transient_key($id), array('order_cutoff' => array(
				'time' => '12:00:00', 'days_before_delivery' => 1, 'time_zone' => 'Europe/Copenhagen',
			)), 60);
		}

		add_filter('pre_http_request', function ($preempt, $args, $url) {
			if (strpos($url, '/api/v1/shipments/') === false) {
				return $preempt;
			}
			$this->keys[] = ($args['method'] ?? 'GET') . ' ' . ($args['headers']['Authorization'] ?? '');

			return array(
				'response' => array('code' => 200),
				'body'     => wp_json_encode(array('shipment_reference' => 'x', 'status' => 'registered', 'notes' => '')),
			);
		}, 1, 3);
	}

	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		foreach (array('default', 'gaard') as $id) {
			delete_transient(o_configuration_transient_key($id));
		}
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		unset($_POST['billing_okoskabet_addon_order'], $_POST['billing_email']);
		remove_all_filters('o_get_settings');
		parent::tearDown();
	}

	/** The earlier order is B's; the addition's own basket would route to A. */
	public function test_the_addition_is_written_under_the_earlier_orders_account(): void
	{
		$parent   = $this->an_order('gaard', 'hey_okoskabet_shipping_home');
		$addition = $this->an_order('', 'hey_okoskabet_shipping_home');

		oko_send_addition_to_its_order($addition, $parent->get_id(), Merchants::get('default'));

		$this->assertContains('PUT key-b', $this->keys);
		$this->assertNotContains('PUT key-a', $this->keys);
	}

	/** And taken off again under the same account. */
	public function test_a_cancelled_addition_is_taken_off_under_the_earlier_orders_account(): void
	{
		$parent   = $this->an_order('gaard', 'hey_okoskabet_shipping_home');
		$addition = $this->an_order('', 'hey_okoskabet_shipping_home');
		$parent->update_meta_data(OKO_ADDITIONS_META, array($addition->get_id()));
		$parent->save();
		$this->keys = array();

		oko_take_addition_off_its_order($addition, $parent->get_id(), Merchants::get('default'));

		$this->assertContains('PUT key-b', $this->keys);
		$this->assertNotContains('PUT key-a', $this->keys);
	}

	/**
	 * The addition's own basket routing to an account without a key does not
	 * stop it: the delivery it rides on is the earlier order's, under that
	 * order's account.
	 */
	public function test_an_addition_is_sent_even_when_its_own_account_has_no_key(): void
	{
		$parent   = $this->an_order('gaard', 'hey_okoskabet_shipping_home');
		$addition = $this->an_order('tom', 'hey_okoskabet_shipping_home');
		$addition->update_meta_data(OKO_ADDON_PARENT_ID_META, (string) $parent->get_id());
		$addition->save();
		$this->keys = array();

		$addition->update_status('processing');

		$this->assertContains('PUT key-b', $this->keys);
		$this->assertSame('yes', wc_get_order($addition->get_id())->get_meta('billing_okoskabet_done', true));
	}

	/** A customer who picked the shop's own courier is not put on our delivery. */
	public function test_an_addition_sent_with_another_courier_is_not_linked(): void
	{
		$parent = $this->an_order('', 'hey_okoskabet_shipping_home');
		$parent->set_billing_email('kunde@example.com');
		$parent->update_meta_data('billing_okoskabet_done', true);
		oko_keep_booked_reference($parent, (string) $parent->get_order_number());
		$parent->save();

		// A shop that takes additions, or the courier would not be what
		// keeps it off our delivery: with the setting off nothing is linked.
		add_filter('o_get_settings', static function ($settings) {
			return array_merge((array) $settings, array('_ask_for_addition_order' => 'on'));
		});
		$_POST['billing_okoskabet_addon_order'] = (string) $parent->get_order_number();
		$_POST['billing_email']                 = 'kunde@example.com';

		$addition = $this->an_order('', 'flat_rate');
		oko_addon_inherits_the_delivery($addition, array());

		$this->assertSame('', (string) $addition->get_meta(OKO_ADDON_PARENT_ID_META, true));
	}

	private function an_order(string $merchant_id, string $method): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Rugbrød');
		$product->set_regular_price('45');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id($method);
		$shipping->set_method_title($method);
		$order->add_item($shipping);
		$order->update_meta_data('_billing_okoskabet_delivery_date', gmdate('Y-m-d', strtotime('+5 days')));
		if ($merchant_id !== '') {
			$order->update_meta_data(Merchant_Router::ORDER_META_KEY, $merchant_id);
		}
		$order->save();

		return $order;
	}
}
