<?php

/**
 * An order the shop sends with its own courier, still carrying what the
 * picker wrote.
 *
 * The picker writes a shed and a date the moment a customer picks Økoskabet,
 * and only the browser clears them again when the customer moves on to the
 * shop's own courier. An order that kept them used to be sent anyway — with a
 * shed as a shed delivery, without one as a home delivery. Slagter Bob has
 * only sheds with us and his own courier beside them ("Levering til din
 * hoveddør", a table rate); nine of his orders arrived as home deliveries
 * between February and May 2026, each moved to a shed by hand.
 *
 * The shipping method decides, as it already did for an order with no date.
 */
class OrderForTheShopsOwnCourierTest extends \Codeception\TestCase\WPTestCase
{
	/** Slagter Bob's own home delivery. */
	private const SHOPS_OWN = 'jem_table_rate_21_levering-til-din-hoveddoer';

	/** @var array<int,array{method:string,url:string}> */
	private array $calls = array();

	public function _before(): void
	{
		parent::_before();
		add_filter('pre_http_request', array($this, 'capture'), 10, 3);
		add_filter('o_get_settings', array($this, 'with_key'));
	}

	public function _after(): void
	{
		remove_filter('pre_http_request', array($this, 'capture'), 10);
		remove_filter('o_get_settings', array($this, 'with_key'));
		parent::_after();
	}

	/** The case that reached Økoskabet as a home delivery: a date and nothing else. */
	public function test_a_leftover_date_does_not_make_it_ours(): void
	{
		$order = $this->order(self::SHOPS_OWN, array('_billing_okoskabet_delivery_date' => '2026-10-27'));

		$this->assertNull(oko_shipment_payload($order, $this->merchant()));
	}

	/** Nor does a leftover shed, which used to go out as a shed delivery. */
	public function test_a_leftover_shed_does_not_make_it_ours(): void
	{
		$order = $this->order(self::SHOPS_OWN, array(
			'_billing_okoskabet_delivery_date' => '2026-10-27',
			'_billing_okoskabet_shed_id'       => 'C2',
		));

		$this->assertNull(oko_shipment_payload($order, $this->merchant()));
	}

	/** Nor a leftover collection point. */
	public function test_a_leftover_pickup_location_does_not_make_it_ours(): void
	{
		$order = $this->order(self::SHOPS_OWN, array(
			'_billing_okoskabet_delivery_date'       => '2026-10-27',
			'_billing_okoskabet_pickup_location_id'  => '7',
		));

		$this->assertNull(oko_shipment_payload($order, $this->merchant()));
	}

	/** Through the real caller: the order is paid, and nothing goes to Økoskabet. */
	public function test_paying_for_it_sends_nothing(): void
	{
		$order = $this->order(self::SHOPS_OWN, array(
			'_billing_okoskabet_delivery_date' => '2026-10-27',
			'_billing_okoskabet_shed_id'       => 'C2',
		));

		hey_after_order_placed($order->get_id(), 'pending', 'processing', $order);

		$this->assertSame(array(), $this->posts(), 'An order for the shop\'s own courier is not booked with Økoskabet.');
		$this->assertEmpty(wc_get_order($order->get_id())->get_meta('billing_okoskabet_done', true));
	}

	/**
	 * The other shops' orders are untouched: the guard only ever looks at the
	 * shipping method, and ours still send exactly as before.
	 */
	public function test_our_shed_delivery_is_still_booked_as_a_shed_delivery(): void
	{
		$order = $this->order('hey_okoskabet_shipping_shed', array(
			'_billing_okoskabet_delivery_date' => '2026-10-27',
			'_billing_okoskabet_shed_id'       => 'C2',
		));

		hey_after_order_placed($order->get_id(), 'pending', 'processing', $order);

		$posts = $this->posts();
		$this->assertCount(1, $posts);
		$this->assertSame('C2', $posts[0]['reservation']['shed_id'] ?? null);
	}

	/** Our home delivery likewise. */
	public function test_our_home_delivery_is_still_booked_as_a_home_delivery(): void
	{
		$order = $this->order('hey_okoskabet_shipping_home', array('_billing_okoskabet_delivery_date' => '2026-10-27'));

		hey_after_order_placed($order->get_id(), 'pending', 'processing', $order);

		$posts = $this->posts();
		$this->assertCount(1, $posts);
		$this->assertSame('6000', $posts[0]['home_delivery']['postal_code'] ?? null);
	}

	/** And our collection from the shop. */
	public function test_our_store_pickup_is_still_booked_as_a_store_pickup(): void
	{
		$order = $this->order('hey_okoskabet_shipping_store_pickup', array(
			'_billing_okoskabet_delivery_date'      => '2026-10-27',
			'_billing_okoskabet_pickup_location_id' => '7',
		));

		hey_after_order_placed($order->get_id(), 'pending', 'processing', $order);

		$posts = $this->posts();
		$this->assertCount(1, $posts);
		$this->assertSame('store_pickup', $posts[0]['shipment_type'] ?? null);
	}

	/** @internal Stands in for Økoskabet: records every call, accepts every booking. */
	public function capture($preempt, $args, $url)
	{
		if (strpos((string) $url, '/api/v1/shipments') === false) {
			return $preempt;
		}
		$method        = (string) ($args['method'] ?? 'GET');
		$this->calls[] = array('method' => $method, 'url' => (string) $url, 'body' => (string) ($args['body'] ?? ''));

		return array('headers' => array(), 'body' => '{}', 'response' => array('code' => $method === 'POST' ? 201 : 200, 'message' => ''), 'cookies' => array(), 'filename' => null);
	}

	/** @internal A shop on the legacy single-merchant settings books with its key from there. */
	public function with_key($settings): array
	{
		$settings = (array) $settings;
		if (empty($settings['_api_key'])) {
			$settings['_api_key'] = 'test-key';
		}

		return $settings;
	}

	/** @return array<int,array<string,mixed>> The bodies of the bookings sent. */
	private function posts(): array
	{
		$posts = array();
		foreach ($this->calls as $call) {
			if ($call['method'] === 'POST') {
				$posts[] = (array) json_decode($call['body'], true);
			}
		}

		return $posts;
	}

	/** @return array<string,mixed> */
	private function merchant(): array
	{
		return array('id' => 'default', 'api_key' => 'test-key', 'staging' => true);
	}

	/** @param array<string,string> $meta */
	private function order(string $method_id, array $meta): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Umami hollandaise');
		$product->set_regular_price('49');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id($method_id);
		$shipping->set_method_title('Levering');
		$shipping->set_total('89');
		$order->add_item($shipping);

		$order->set_billing_first_name('Birgitte');
		$order->set_billing_last_name('Bennedsen');
		$order->set_shipping_address_1('Kildeparken 98');
		$order->set_shipping_postcode('6000');
		$order->set_shipping_city('Kolding');
		foreach ($meta as $key => $value) {
			$order->update_meta_data($key, $value);
		}
		$order->set_transaction_id('txn-1');
		$order->calculate_totals(false);
		$order->save();

		return wc_get_order($order->get_id());
	}
}
