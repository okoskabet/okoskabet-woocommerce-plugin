<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * A shop's own shipping method for add-ons counts as one.
 *
 * Gaardmester asks customers who add to an earlier order to pick "Tillæg til
 * en igangværende ordre" — a local pickup method of its own, at 0 kr — and to
 * write the earlier order's number in the checkout. Only our own methods used
 * to count, so the goods were never put on the delivery and the shop did it by
 * hand. A method the shop names in the settings now counts; one it has not
 * named is still a courier of its own and is left alone.
 */
class AddonOwnShippingMethodTest extends \Codeception\TestCase\WPTestCase
{
	/** @var array<int,string> */
	private $puts = array();

	public function setUp(): void
	{
		parent::setUp();
		$this->puts = array();

		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		Merchants::save_config(array(
			'default_merchant_id' => 'default',
			'merchants' => array('default' => array('id' => 'default', 'label' => 'D', 'api_key' => 'test-key', 'staging' => true)),
		));
		set_transient(o_configuration_transient_key('default'), array('order_cutoff' => array(
			'time' => '23:59:00', 'days_before_delivery' => 1, 'time_zone' => 'Europe/Copenhagen',
		)), 60);

		$settings = (array) get_option(O_TEXTDOMAIN . '-settings', array());
		$settings['_addon_shipping_methods'] = array('local_pickup:45');
		update_option(O_TEXTDOMAIN . '-settings', $settings);

		add_filter('pre_http_request', function ($preempt, $args, $url) {
			if (strpos($url, '/api/v1/shipments/') === false) {
				return $preempt;
			}
			if (($args['method'] ?? 'GET') === 'PUT') {
				$this->puts[] = (string) ($args['body'] ?? '');
			}

			return array(
				'response' => array('code' => 200),
				'body'     => wp_json_encode(array('shipment_reference' => 'x', 'status' => 'registered', 'notes' => '')),
			);
		}, 1, 3);
	}

	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		delete_transient(o_configuration_transient_key('default'));
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		unset($_POST['billing_order_id'], $_POST['billing_email']);
		parent::tearDown();
	}

	/** Gaardmester's own add-on method, ticked: the goods go on the delivery. */
	public function test_the_shops_own_addon_method_puts_the_goods_on_the_delivery(): void
	{
		$parent   = $this->a_booked_order();
		$addition = $this->an_addition_to($parent, 'local_pickup', 45);

		$this->assertSame((string) $parent->get_id(), (string) $addition->get_meta(OKO_ADDON_PARENT_ID_META, true));
		$this->assertSame(
			(string) $parent->get_meta('_billing_okoskabet_delivery_date', true),
			(string) $addition->get_meta('_billing_okoskabet_delivery_date', true),
			'It rides on the earlier order\'s delivery.'
		);

		$addition->set_status('processing');
		$addition->save();

		$this->assertCount(1, $this->puts, 'The goods are written onto the earlier order\'s shipment.');
		$this->assertSame(array($addition->get_id()), oko_addition_ids(wc_get_order($parent->get_id())));
	}

	/** Another of the shop's methods, not ticked, is still the shop's own. */
	public function test_a_method_the_shop_has_not_named_is_left_alone(): void
	{
		$parent   = $this->a_booked_order();
		$addition = $this->an_addition_to($parent, 'local_pickup', 44);

		$this->assertSame('', (string) $addition->get_meta(OKO_ADDON_PARENT_ID_META, true));
	}

	/** Nothing named in the settings, nothing of the shop's counts. */
	public function test_without_the_setting_the_shops_methods_do_not_count(): void
	{
		$settings = (array) get_option(O_TEXTDOMAIN . '-settings', array());
		unset($settings['_addon_shipping_methods']);
		update_option(O_TEXTDOMAIN . '-settings', $settings);

		$parent   = $this->a_booked_order();
		$addition = $this->an_addition_to($parent, 'local_pickup', 45);

		$this->assertSame('', (string) $addition->get_meta(OKO_ADDON_PARENT_ID_META, true));
	}

	private function a_booked_order(): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Æbler');
		$product->set_regular_price('30');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);
		$order->set_billing_email('kunde@example.org');
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id('hey_okoskabet_shipping_home');
		$shipping->set_method_title('Hjemmelevering');
		$order->add_item($shipping);
		$order->update_meta_data('_billing_okoskabet_delivery_date', gmdate('Y-m-d', strtotime('+7 days')));
		$order->update_meta_data('billing_okoskabet_done', true);
		oko_keep_booked_reference($order, (string) $order->get_order_number());
		$order->save();

		return wc_get_order($order->get_id());
	}

	/** An addition as the checkout creates it: the shipping line is there before the hook runs. */
	private function an_addition_to(\WC_Order $parent, string $method_id, int $instance_id): \WC_Order
	{
		$_POST['billing_order_id'] = (string) $parent->get_order_number();
		$_POST['billing_email']    = 'kunde@example.org';

		$product = new \WC_Product_Simple();
		$product->set_name('Pærer');
		$product->set_regular_price('25');
		$product->save();

		$addition = wc_create_order();
		$addition->add_product($product, 1);
		$addition->set_billing_email('kunde@example.org');
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id($method_id);
		$shipping->set_instance_id($instance_id);
		$shipping->set_method_title('Tillæg til en igangværende ordre');
		$addition->add_item($shipping);

		oko_addon_inherits_the_delivery($addition, array());
		$addition->save();

		unset($_POST['billing_order_id'], $_POST['billing_email']);

		return wc_get_order($addition->get_id());
	}
}
