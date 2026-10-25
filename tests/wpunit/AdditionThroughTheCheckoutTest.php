<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * The setting, through WooCommerce's own checkout: the cart's freight and the
 * order `WC_Checkout::process_checkout()` makes from a posted form.
 *
 * AdditionSettingTest asks each of our hooks in turn. This asks WooCommerce,
 * which is where the money is: a form posted with an earlier order's number
 * in it — in our field, in a shop's own `billing_order_id`, or in `post_data`
 * as the recalculation sends it — must leave the freight and the order alone
 * in a shop that has not turned additions on, whatever else is in the form.
 */
class AdditionThroughTheCheckoutTest extends \Codeception\TestCase\WPTestCase
{
	/** @var int */
	private $instance_id = 0;

	public function setUp(): void
	{
		parent::setUp();

		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		Merchants::save_config(array(
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array('id' => 'default', 'label' => 'A', 'api_key' => 'key-a', 'staging' => true),
			),
		));
		set_transient(o_configuration_transient_key('default'), array(
			'order_cutoff'     => array('time' => '12:00:00', 'days_before_delivery' => 1, 'time_zone' => 'Europe/Copenhagen'),
			'shipping_methods' => array(array('method_code' => 'home_delivery')),
		), 60);

		// Økoskabet still has every shipment; nothing leaves the test.
		add_filter('pre_http_request', static function ($preempt, $args, $url) {
			if (strpos((string) $url, 'okoskabet') === false && strpos((string) $url, '/api/v1/') === false) {
				return $preempt;
			}

			return array(
				'response' => array('code' => 200),
				'body'     => wp_json_encode(array('shipment_reference' => 'x', 'status' => 'registered', 'notes' => '')),
			);
		}, 1, 3);

		// Our home delivery at 49 kr, in the zone every address falls into.
		hey_okoskabet_shipping_method_home_init();
		$zone              = new \WC_Shipping_Zone(0);
		$this->instance_id = (int) $zone->add_shipping_method('hey_okoskabet_shipping_home');
		update_option(
			'woocommerce_hey_okoskabet_shipping_home_' . $this->instance_id . '_settings',
			array('title' => 'Økoskabet', 'cost' => '49')
		);
		update_option('woocommerce_bacs_settings', array('enabled' => 'yes'));
		update_option('woocommerce_calc_taxes', 'no');

		wc_load_cart();
		WC()->payment_gateways()->init();
		WC()->shipping()->load_shipping_methods();
		WC()->cart->empty_cart();
		WC()->session->set('chosen_shipping_methods', array('hey_okoskabet_shipping_home'));
		WC()->session->set('order_awaiting_payment', 0);
		WC()->customer->set_billing_country('DK');
		WC()->customer->set_shipping_country('DK');
		WC()->customer->set_billing_postcode('8000');
		WC()->customer->set_shipping_postcode('8000');

		// The checkout answers an AJAX post with wp_send_json(), which dies;
		// here dying is an exception the test catches.
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
		remove_all_filters('o_get_settings');
		$zone = new \WC_Shipping_Zone(0);
		$zone->delete_shipping_method($this->instance_id);
		WC()->cart->empty_cart();
		delete_transient(o_configuration_transient_key('default'));
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		foreach (array_keys($_POST) as $key) {
			unset($_POST[$key], $_REQUEST[$key]);
		}
		parent::tearDown();
	}

	/** @return array<string,array{string}> */
	public function fields(): array
	{
		return array(
			'our own field'         => array('billing_okoskabet_addon_order'),
			'the shop\'s own field' => array('billing_order_id'),
		);
	}

	/**
	 * Off: the cart charges the freight while the form is being filled in,
	 * the way the recalculation posts it.
	 *
	 * @dataProvider fields
	 */
	public function test_off_the_recalculation_charges_the_freight(string $field): void
	{
		$parent = $this->a_booked_order();
		$this->a_basket();
		$_POST['post_data'] = http_build_query(array(
			$field          => (string) $parent->get_order_number(),
			'billing_email' => 'kunde@example.com',
		));

		WC()->cart->calculate_totals();

		$this->assertSame(49.0, (float) WC()->cart->get_shipping_total());
	}

	/**
	 * On, the same recalculation drops it — so the case above is not passing
	 * for want of a rate the filter could have zeroed.
	 *
	 * @dataProvider fields
	 */
	public function test_on_the_recalculation_drops_the_freight(string $field): void
	{
		$this->turn_additions_on();
		$parent = $this->a_booked_order();
		$this->a_basket();
		$_POST['post_data'] = http_build_query(array(
			$field          => (string) $parent->get_order_number(),
			'billing_email' => 'kunde@example.com',
		));

		WC()->cart->calculate_totals();

		$this->assertSame(0.0, (float) WC()->cart->get_shipping_total());
	}

	/**
	 * Off: a form posted to the checkout with the number in it is an ordinary
	 * order — the freight paid, its own delivery, nothing linked, no notes.
	 *
	 * @dataProvider fields
	 */
	public function test_off_a_posted_number_makes_an_ordinary_order(string $field): void
	{
		$parent = $this->a_booked_order();
		$order  = $this->checkout_posting(array($field => (string) $parent->get_order_number()));

		$this->assertSame(49.0, (float) $order->get_shipping_total(), 'The freight is paid.');
		$this->assertSame('', (string) $order->get_meta(OKO_ADDON_PARENT_ID_META, true));
		$this->assertSame($this->own_day(), (string) $order->get_meta('_billing_okoskabet_delivery_date', true));
		$this->assertSame(array(), oko_addition_ids(wc_get_order($parent->get_id())));
		$this->assertSame(array(), $this->notes_naming($parent, (string) $order->get_order_number()));
	}

	/**
	 * On, the same form rides along: the test can see the difference the
	 * setting makes, so the "off" cases above are not passing for some other
	 * reason.
	 *
	 * @dataProvider fields
	 */
	public function test_on_the_same_posted_number_rides_along(string $field): void
	{
		$this->turn_additions_on();
		$parent = $this->a_booked_order();
		$order  = $this->checkout_posting(array($field => (string) $parent->get_order_number()));

		$this->assertSame(0.0, (float) $order->get_shipping_total(), 'No second freight.');
		$this->assertSame((string) $parent->get_id(), (string) $order->get_meta(OKO_ADDON_PARENT_ID_META, true));
		$this->assertSame(
			(string) $parent->get_meta('_billing_okoskabet_delivery_date', true),
			(string) $order->get_meta('_billing_okoskabet_delivery_date', true)
		);
	}

	// --- Helpers ------------------------------------------------------------

	private function turn_additions_on(): void
	{
		add_filter('o_get_settings', static function ($settings) {
			return array_merge((array) $settings, array('_ask_for_addition_order' => 'on'));
		});
	}

	/**
	 * Post a checkout form to `WC_Checkout::process_checkout()` and return the
	 * order it made.
	 *
	 * @param array<string,string> $extra Fields beyond the ordinary ones.
	 */
	private function checkout_posting(array $extra): \WC_Order
	{
		$this->a_basket();

		$form = array_merge(array(
			'billing_first_name'              => 'Kunde',
			'billing_last_name'               => 'Eksempel',
			'billing_address_1'               => 'Eksempelvej 1',
			'billing_city'                    => 'Aarhus',
			'billing_postcode'                => '8000',
			'billing_country'                 => 'DK',
			'billing_phone'                   => '12345678',
			'billing_email'                   => 'kunde@example.com',
			'billing_okoskabet_delivery_date' => $this->own_day(),
			'shipping_method'                 => array('hey_okoskabet_shipping_home'),
			'payment_method'                  => 'bacs',
			'terms'                           => '1',
			'terms-field'                     => '1',
			'woocommerce-process-checkout-nonce' => wp_create_nonce('woocommerce-process_checkout'),
		), $extra);
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

		// The first answer is the order's. WooCommerce's own catch around the
		// payment step then sees our dying as a failure and answers again.
		$this->assertSame(1, preg_match('/"order_id":(\d+)/', $reply, $match), 'The checkout made an order: ' . $reply);
		$order_id = (int) $match[1];
		wc_clear_notices();

		return wc_get_order($order_id);
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

	/** The day this checkout picks for itself, unlike the earlier order's. */
	private function own_day(): string
	{
		return gmdate('Y-m-d', strtotime('+9 days'));
	}

	/** An earlier order, booked with Økoskabet, whose delivery can still take more. */
	private function a_booked_order(): \WC_Order
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
		$order->update_meta_data('_billing_okoskabet_delivery_date', gmdate('Y-m-d', strtotime('+5 days')));
		$order->update_meta_data('billing_okoskabet_done', 'yes');
		oko_keep_booked_reference($order, (string) $order->get_order_number());
		$order->save();

		return $order;
	}

	/** @return array<int,\WP_Comment> */
	private function notes_naming(\WC_Order $order, string $needle): array
	{
		$notes = wc_get_order_notes(array('order_id' => $order->get_id()));

		return array_values(array_filter($notes, static function ($note) use ($needle) {
			return strpos((string) $note->content, $needle) !== false;
		}));
	}
}
