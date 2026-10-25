<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * Additions to an earlier order are the shop's choice, and off until it makes it.
 *
 * 1.5.3 put »Tillæg til en tidligere ordre? Skriv ordrenummeret her« in every
 * shop's checkout with no way to take it out. Off means off for both fields:
 * ours is not drawn, and a shop's own `billing_order_id` is not read — no free
 * freight, no notice, no inherited delivery, no notes. Only the checkout asks;
 * an addition linked while it was on keeps riding along after it is turned off.
 */
class AdditionSettingTest extends \Codeception\TestCase\WPTestCase
{
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
		set_transient(o_configuration_transient_key('default'), array('order_cutoff' => array(
			'time' => '12:00:00', 'days_before_delivery' => 1, 'time_zone' => 'Europe/Copenhagen',
		)), 60);

		// Økoskabet still has every shipment, and takes every write.
		add_filter('pre_http_request', function ($preempt, $args, $url) {
			if (strpos($url, '/api/v1/shipments/') === false) {
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
	}

	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		remove_all_filters('o_get_settings');
		delete_transient(o_configuration_transient_key('default'));
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		unset($_POST['billing_okoskabet_addon_order'], $_POST['billing_order_id'], $_POST['billing_email']);
		parent::tearDown();
	}

	// --- Off, as every shop starts -----------------------------------------

	/** A shop that has not turned it on gets no field. */
	public function test_off_by_default_the_checkout_has_no_field(): void
	{
		$this->assertFalse(oko_shop_takes_additions(), 'Off until the shop turns it on.');

		$fields = custom_override_checkout_fields(array('billing' => array()));

		$this->assertArrayNotHasKey('billing_okoskabet_addon_order', $fields['billing']);
	}

	/**
	 * A shop's own field is the shop's: not made to recalculate the freight,
	 * and not emptied on the next visit — as it was before 1.5.3.
	 */
	public function test_off_the_shops_own_field_is_left_as_it_is(): void
	{
		$fields = custom_override_checkout_fields(array('billing' => array(
			'billing_order_id' => array('label' => 'Tillægsordre', 'class' => array('form-row-wide')),
		)));

		$this->assertSame(array('form-row-wide'), $fields['billing']['billing_order_id']['class']);
		$this->assertArrayNotHasKey('billing_okoskabet_addon_order', $fields['billing']);
		$this->assertSame('87893', apply_filters('woocommerce_checkout_get_value', '87893', 'billing_order_id'));
	}

	/**
	 * A number typed in either field rides along with nothing.
	 *
	 * The earlier order is one that would take an addition — the same order
	 * the "on" tests below put one on — so the setting is the only thing
	 * standing between this checkout and free freight.
	 *
	 * @dataProvider fields
	 */
	public function test_off_a_number_in_the_checkout_rides_along_with_nothing(string $field): void
	{
		$parent = $this->a_booked_order();
		$this->given_a_checkout_naming($parent, $field);

		$this->assertNull(oko_addon_in_checkout()['order']);
		$this->assertArrayNotHasKey('okoskabet_addon', oko_addon_in_the_package(array(array('contents' => array())))[0]);
		$this->assertSame('49', (string) $this->our_rate_after_the_filter()->get_cost(), 'Ordinary freight.');

		$order = $this->an_order_without_a_delivery();
		oko_addon_inherits_the_delivery($order, array());
		$this->assertSame('', (string) $order->get_meta(OKO_ADDON_PARENT_ID_META, true));
		$this->assertSame('', (string) $order->get_meta('_billing_okoskabet_delivery_date', true));
	}

	/** And a number nobody has says nothing in the checkout either. */
	public function test_off_the_checkout_says_nothing_about_a_number(): void
	{
		$_POST['billing_okoskabet_addon_order'] = '70000001';
		$_POST['billing_email']                 = 'kunde@example.com';

		$this->assertSame('', $this->notice());
	}

	/** No note calls an ordinary order an addition, on either order. */
	public function test_off_no_note_calls_an_order_an_addition(): void
	{
		$parent = $this->a_booked_order();
		$order  = $this->an_order_without_a_delivery();
		$order->update_meta_data('_billing_order_id', (string) $parent->get_order_number());
		$order->save();

		oko_link_addon_to_earlier_order($order->get_id(), array(), $order);

		$this->assertSame(array(), $this->notes_naming($order, (string) $parent->get_order_number()));
		$this->assertSame(array(), $this->notes_naming($parent, (string) $order->get_order_number()));
	}

	/**
	 * An addition linked while it was on still rides along, and still comes
	 * off when cancelled, after the shop has turned it off: its goods are
	 * already on that shipment.
	 */
	public function test_an_addition_linked_while_it_was_on_keeps_working_once_it_is_off(): void
	{
		$parent   = $this->a_booked_order();
		$addition = $this->an_order_without_a_delivery();
		$addition->update_meta_data(OKO_ADDON_PARENT_ID_META, (string) $parent->get_id());
		$addition->save();

		$this->assertFalse(oko_shop_takes_additions());

		$addition->update_status('processing');

		$this->assertCount(1, $this->writes, 'The addition put on the earlier order\'s shipment.');
		$this->assertStringStartsWith('PUT ', $this->writes[0]);
		$this->assertSame(array($addition->get_id()), oko_addition_ids(wc_get_order($parent->get_id())));

		wc_get_order($addition->get_id())->update_status('cancelled');

		$this->assertCount(2, $this->writes, 'And taken off it again.');
		$this->assertSame(array(), oko_addition_ids(wc_get_order($parent->get_id())));
	}

	// --- On -----------------------------------------------------------------

	/** Turned on, the checkout asks, and the field recalculates the freight. */
	public function test_on_the_checkout_asks(): void
	{
		$this->turn_additions_on();

		$fields = custom_override_checkout_fields(array('billing' => array()));

		$this->assertArrayHasKey('billing_okoskabet_addon_order', $fields['billing']);
		$this->assertContains('update_totals_on_change', $fields['billing']['billing_okoskabet_addon_order']['class']);
		$this->assertSame('', apply_filters('woocommerce_checkout_get_value', '87893', 'billing_order_id'));
	}

	/**
	 * Turned on, a number in either field rides along as before: free freight
	 * on our methods, the earlier order's delivery, and a note on both.
	 *
	 * @dataProvider fields
	 */
	public function test_on_a_number_in_the_checkout_rides_along(string $field): void
	{
		$this->turn_additions_on();
		$parent = $this->a_booked_order();
		$this->given_a_checkout_naming($parent, $field);

		$this->assertSame($parent->get_id(), oko_addon_in_checkout()['order']->get_id());
		$this->assertSame('1', oko_addon_in_the_package(array(array('contents' => array())))[0]['okoskabet_addon']);
		$this->assertSame('0', (string) $this->our_rate_after_the_filter()->get_cost(), 'No second freight.');

		$order = $this->an_order_without_a_delivery();
		oko_addon_inherits_the_delivery($order, array());
		$order->update_meta_data('_' . $field, (string) $parent->get_order_number());
		$order->save();
		$this->assertSame((string) $parent->get_id(), (string) $order->get_meta(OKO_ADDON_PARENT_ID_META, true));
		$this->assertSame(
			(string) $parent->get_meta('_billing_okoskabet_delivery_date', true),
			(string) $order->get_meta('_billing_okoskabet_delivery_date', true)
		);

		oko_link_addon_to_earlier_order($order->get_id(), array(), $order);
		$this->assertNotEmpty($this->notes_naming($order, (string) $parent->get_order_number()));
		$this->assertNotEmpty($this->notes_naming($parent, (string) $order->get_order_number()));
	}

	/** Turned on, a number nobody has is answered in the checkout. */
	public function test_on_the_checkout_answers_a_number_it_cannot_find(): void
	{
		$this->turn_additions_on();
		$_POST['billing_okoskabet_addon_order'] = '70000001';
		$_POST['billing_email']                 = 'kunde@example.com';

		$this->assertStringContainsString('okoskabet-addon-notice', $this->notice());
	}

	/** @return array<string,array{string}> */
	public function fields(): array
	{
		return array(
			'our own field'       => array('billing_okoskabet_addon_order'),
			'the shop\'s own field' => array('billing_order_id'),
		);
	}

	// --- Helpers ------------------------------------------------------------

	private function turn_additions_on(): void
	{
		add_filter('o_get_settings', static function ($settings) {
			return array_merge((array) $settings, array('_ask_for_addition_order' => 'on'));
		});
	}

	/** An earlier order, booked with Økoskabet, whose delivery can still take more. */
	private function a_booked_order(): \WC_Order
	{
		$order = $this->an_order_without_a_delivery();
		$order->set_billing_email('kunde@example.com');
		$order->update_meta_data('_billing_okoskabet_delivery_date', gmdate('Y-m-d', strtotime('+5 days')));
		$order->update_meta_data('billing_okoskabet_done', 'yes');
		oko_keep_booked_reference($order, (string) $order->get_order_number());
		$order->save();

		return $order;
	}

	/** An order with Økoskabet's home delivery and no day of its own yet. */
	private function an_order_without_a_delivery(): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Rugbrød');
		$product->set_regular_price('45');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id('hey_okoskabet_shipping_home');
		$shipping->set_method_title('Økoskabet');
		$order->add_item($shipping);
		$order->set_billing_email('kunde@example.com');
		$order->save();

		return $order;
	}

	private function given_a_checkout_naming(\WC_Order $parent, string $field): void
	{
		$_POST[$field]          = (string) $parent->get_order_number();
		$_POST['billing_email'] = 'kunde@example.com';
	}

	/** Our home-delivery rate at 49 kr, after the addition's rate filter. */
	private function our_rate_after_the_filter(): \WC_Shipping_Rate
	{
		$rate  = new \WC_Shipping_Rate('hey_okoskabet_shipping_home:1', 'Økoskabet', 49, array(), 'hey_okoskabet_shipping_home', 1);
		$rates = oko_addon_rides_along_for_free(array($rate->get_id() => $rate), array());

		return $rates[$rate->get_id()];
	}

	private function notice(): string
	{
		ob_start();
		oko_addon_say_if_it_is_too_late();

		return (string) ob_get_clean();
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
