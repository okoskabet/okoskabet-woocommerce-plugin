<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * The shop's own add-on method, through WooCommerce's own checkout.
 *
 * AddonOwnShippingMethodTest calls our hook with a shipping line it built
 * itself. This posts a form to `WC_Checkout::process_checkout()` with a real
 * local pickup rate chosen, so the key the settings page stores
 * ("method_id:instance_id", from `oko_all_shipping_method_choices()`) is
 * compared with the shipping line WooCommerce actually writes — and then
 * moves the order on to processing, so what reaches Økoskabet is what the
 * shop would see.
 *
 * Every case but the first must leave Økoskabet untouched: an order the shop
 * carries on its own 0 kr. method that is not linked to a delivery is the
 * shop's, and sending it would put it in the packing room as a delivery
 * nobody paid for.
 */
class AddonOwnShippingMethodCheckoutTest extends \Codeception\TestCase\WPTestCase
{
	/** @var int The shop's "Tillæg til en igangværende ordre", ticked. */
	private $addon_pickup = 0;

	/** @var int Another local pickup of the shop's, not ticked. */
	private $other_pickup = 0;

	/** @var array<int,string> Every write to Økoskabet, as "METHOD path". */
	private $writes = array();

	public function setUp(): void
	{
		parent::setUp();
		$this->writes = array();

		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		Merchants::save_config(array(
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array('id' => 'default', 'label' => 'A', 'api_key' => 'key-a', 'staging' => true),
			),
		));
		set_transient(o_configuration_transient_key('default'), array(
			'order_cutoff' => array('time' => '12:00:00', 'days_before_delivery' => 1, 'time_zone' => 'Europe/Copenhagen'),
		), 60);

		// Økoskabet has every shipment and takes every write; nothing leaves the test.
		add_filter('pre_http_request', function ($preempt, $args, $url) {
			if (strpos((string) $url, '/api/v1/') === false) {
				return $preempt;
			}
			$method = $args['method'] ?? 'GET';
			if ($method !== 'GET') {
				$this->writes[] = $method . ' ' . (string) wp_parse_url($url, PHP_URL_PATH);
			}

			return array(
				'response' => array('code' => 200),
				'body'     => wp_json_encode(array('shipment_reference' => 'x', 'status' => 'registered', 'notes' => '')),
			);
		}, 1, 3);

		// Two local pickups at 0 kr., in the zone every address falls into.
		$zone               = new \WC_Shipping_Zone(0);
		$this->addon_pickup = (int) $zone->add_shipping_method('local_pickup');
		$this->other_pickup = (int) $zone->add_shipping_method('local_pickup');
		foreach (array($this->addon_pickup => 'Tillæg til en igangværende ordre', $this->other_pickup => 'Afhentning på gården') as $id => $title) {
			update_option('woocommerce_local_pickup_' . $id . '_settings', array('title' => $title, 'cost' => '0', 'enabled' => 'yes'));
		}
		update_option('woocommerce_bacs_settings', array('enabled' => 'yes'));
		update_option('woocommerce_calc_taxes', 'no');

		// As the settings page stores it: the key oko_all_shipping_method_choices() offers.
		$this->assertArrayHasKey('local_pickup:' . $this->addon_pickup, oko_all_shipping_method_choices());
		$this->store_settings(array(
			'_ask_for_addition_order' => 'on',
			'_addon_shipping_methods' => array('local_pickup:' . $this->addon_pickup),
		));

		wc_load_cart();
		WC()->payment_gateways()->init();
		WC()->shipping()->load_shipping_methods();
		WC()->cart->empty_cart();
		WC()->session->set('order_awaiting_payment', 0);
		WC()->customer->set_billing_country('DK');
		WC()->customer->set_shipping_country('DK');
		WC()->customer->set_billing_postcode('8000');
		WC()->customer->set_shipping_postcode('8000');

		add_filter('wp_doing_ajax', '__return_true');
		add_filter('wp_die_ajax_handler', static function () {
			return static function ($message) {
				throw new \WPDieException(is_string($message) ? $message : '');
			};
		});
	}

	public function tearDown(): void
	{
		remove_all_filters('wp_doing_ajax');
		remove_all_filters('wp_die_ajax_handler');
		remove_all_filters('pre_http_request');
		$zone = new \WC_Shipping_Zone(0);
		$zone->delete_shipping_method($this->addon_pickup);
		$zone->delete_shipping_method($this->other_pickup);
		WC()->cart->empty_cart();
		delete_transient(o_configuration_transient_key('default'));
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		foreach (array_keys($_POST) as $key) {
			unset($_POST[$key], $_REQUEST[$key]);
		}
		parent::tearDown();
	}

	/** The ticked method and a number that counts: the goods go on that delivery. */
	public function test_the_ticked_method_with_a_good_number_rides_along(): void
	{
		$parent = $this->a_booked_order('+5 days');
		$order  = $this->checkout_posting($this->addon_pickup, (string) $parent->get_order_number());

		$this->assertSame((string) $parent->get_id(), (string) $order->get_meta(OKO_ADDON_PARENT_ID_META, true));
		$this->assertSame(
			(string) $parent->get_meta('_billing_okoskabet_delivery_date', true),
			(string) $order->get_meta('_billing_okoskabet_delivery_date', true)
		);

		$order->update_status('processing');

		$this->assertCount(1, $this->writes, 'Written onto the earlier order\'s shipment, and nothing else.');
		$this->assertStringStartsWith('PUT ', $this->writes[0]);
		$this->assertSame(array($order->get_id()), oko_addition_ids(wc_get_order($parent->get_id())));
	}

	/**
	 * The ticked method, but nothing that links it: an ordinary order of the
	 * shop's, never sent to Økoskabet.
	 *
	 * @dataProvider numbers_that_do_not_count
	 */
	public function test_the_ticked_method_without_a_number_that_counts_is_the_shops_own(string $case): void
	{
		$parent = $this->a_booked_order($case === 'too late' ? '-1 day' : '+5 days');
		$number = array(
			'no number'      => '',
			'unknown number' => '70000001',
			'too late'       => (string) $parent->get_order_number(),
		)[$case];
		$this->assertSame(
			array('no number' => '', 'unknown number' => 'unknown', 'too late' => 'too_late')[$case],
			oko_addon_parent_order($number, 'kunde@example.com')['reason'],
			'Refused for the reason this case is about.'
		);

		$order = $this->checkout_posting($this->addon_pickup, $number);

		$this->assert_the_shops_own($order, $parent);
	}

	/** @return array<string,array{string}> */
	public function numbers_that_do_not_count(): array
	{
		return array(
			'no number'      => array('no number'),
			'unknown number' => array('unknown number'),
			'too late'       => array('too late'),
		);
	}

	/** Another local pickup, not ticked, with a good number: the shop's own. */
	public function test_a_method_that_is_not_ticked_is_the_shops_own(): void
	{
		$parent = $this->a_booked_order('+5 days');
		$order  = $this->checkout_posting($this->other_pickup, (string) $parent->get_order_number());

		$this->assert_the_shops_own($order, $parent);
	}

	/** Additions turned off: the ticked method and a good number are the shop's own. */
	public function test_with_additions_off_the_ticked_method_is_the_shops_own(): void
	{
		$this->store_settings(array(
			'_ask_for_addition_order' => '',
			'_addon_shipping_methods' => array('local_pickup:' . $this->addon_pickup),
		));
		$parent = $this->a_booked_order('+5 days');
		$order  = $this->checkout_posting($this->addon_pickup, (string) $parent->get_order_number());

		$this->assert_the_shops_own($order, $parent);
	}

	/**
	 * Saved with nothing ticked and never saved at all are the same thing.
	 * CMB2 drops an empty multicheck from the option; an older save may have
	 * left an empty string.
	 *
	 * @dataProvider nothing_ticked
	 */
	public function test_nothing_ticked_is_the_shops_own($stored): void
	{
		$settings = array('_ask_for_addition_order' => 'on');
		if ($stored !== null) {
			$settings['_addon_shipping_methods'] = $stored;
		}
		$this->store_settings($settings);

		$parent = $this->a_booked_order('+5 days');
		$order  = $this->checkout_posting($this->addon_pickup, (string) $parent->get_order_number());

		$this->assert_the_shops_own($order, $parent);
	}

	/** @return array<string,array{mixed}> */
	public function nothing_ticked(): array
	{
		return array(
			'never saved'        => array(null),
			'saved empty'        => array(''),
			'saved as no ticks'  => array(array()),
		);
	}

	// --- Helpers ------------------------------------------------------------

	private function assert_the_shops_own(\WC_Order $order, \WC_Order $parent): void
	{
		$this->assertSame('', (string) $order->get_meta(OKO_ADDON_PARENT_ID_META, true), 'Not linked.');
		$this->assertSame('', (string) $order->get_meta('_billing_okoskabet_delivery_date', true), 'No delivery of ours.');

		$order->update_status('processing');

		$this->assertSame(array(), $this->writes, 'Nothing sent to Økoskabet: not booked, not added.');
		$this->assertSame('', (string) wc_get_order($order->get_id())->get_meta('billing_okoskabet_done', true));
		$this->assertSame(array(), oko_addition_ids(wc_get_order($parent->get_id())));
	}

	/** @param array<string,mixed> $values */
	private function store_settings(array $values): void
	{
		$settings = (array) get_option(O_TEXTDOMAIN . '-settings', array());
		unset($settings['_ask_for_addition_order'], $settings['_addon_shipping_methods']);
		update_option(O_TEXTDOMAIN . '-settings', array_merge($settings, $values));
	}

	/** Post a checkout with one of the local pickups chosen, and return the order. */
	private function checkout_posting(int $instance_id, string $number): \WC_Order
	{
		$this->a_basket();
		$rate_id = 'local_pickup:' . $instance_id;
		WC()->session->set('chosen_shipping_methods', array($rate_id));

		$form = array(
			'billing_first_name' => 'Kunde',
			'billing_last_name'  => 'Eksempel',
			'billing_address_1'  => 'Eksempelvej 1',
			'billing_city'       => 'Aarhus',
			'billing_postcode'   => '8000',
			'billing_country'    => 'DK',
			'billing_phone'      => '12345678',
			'billing_email'      => 'kunde@example.com',
			'billing_order_id'   => $number,
			'shipping_method'    => array($rate_id),
			'payment_method'     => 'bacs',
			'terms'              => '1',
			'terms-field'        => '1',
			'woocommerce-process-checkout-nonce' => wp_create_nonce('woocommerce-process_checkout'),
		);
		foreach ($form as $key => $value) {
			$_POST[$key]    = $value;
			$_REQUEST[$key] = $value;
		}

		ob_start();
		try {
			WC()->checkout()->process_checkout();
		} catch (\WPDieException $e) {
			// wp_send_json() at the end of a checkout.
		}
		$reply = (string) ob_get_clean();

		$this->assertSame(1, preg_match('/"order_id":(\d+)/', $reply, $match), 'The checkout made an order: ' . $reply);
		wc_clear_notices();

		$order = wc_get_order((int) $match[1]);
		$lines = array_values($order->get_shipping_methods());
		$this->assertSame('local_pickup', $lines[0]->get_method_id(), 'The pickup the form chose.');
		$this->assertSame($instance_id, (int) $lines[0]->get_instance_id());

		return $order;
	}

	private function a_basket(): void
	{
		WC()->cart->empty_cart();
		$product = new \WC_Product_Simple();
		$product->set_name('Rugbrød');
		$product->set_regular_price('45');
		$product->save();
		WC()->cart->add_to_cart($product->get_id(), 1);
	}

	/** An earlier order, booked with Økoskabet, delivered on the given day. */
	private function a_booked_order(string $day): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Æbler');
		$product->set_regular_price('30');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id('hey_okoskabet_shipping_home');
		$shipping->set_method_title('Økoskabet');
		$order->add_item($shipping);
		$order->set_billing_email('kunde@example.com');
		$order->update_meta_data('_billing_okoskabet_delivery_date', gmdate('Y-m-d', strtotime($day)));
		$order->update_meta_data('billing_okoskabet_done', 'yes');
		oko_keep_booked_reference($order, (string) $order->get_order_number());
		$order->save();

		return $order;
	}
}
