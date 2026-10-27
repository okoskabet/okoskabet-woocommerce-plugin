<?php

/**
 * A refund or an edit on an addition reaches the delivery it rides on.
 *
 * An addition has no shipment of its own; its goods are lines on the earlier
 * order's. It is never fingerprinted, so the resend stopped at it, and nothing
 * sent the earlier order again: a line refunded off an addition, or the whole
 * addition refunded, stayed on the packing list. Only a cancellation took the
 * goods off.
 */
class AnAdditionsRefundReachesTheDeliveryTest extends \Codeception\TestCase\WPTestCase
{
	use ConfiguresMerchant;

	/** @var array<int,array{0:string,1:array<string,mixed>}> Every PUT: where, and what. */
	private $puts = array();

	public function setUp(): void
	{
		parent::setUp();
		$this->a_merchant_with_an_account();
		$this->puts = array();

		add_filter('pre_http_request', function ($preempt, $args, $url) {
			if (($args['method'] ?? '') === 'PUT') {
				$this->puts[] = array((string) $url, json_decode((string) $args['body'], true));

				return array('response' => array('code' => 200), 'body' => '{}');
			}
			if (strpos((string) $url, '/api/v1/shipments/') !== false) {
				return array('response' => array('code' => 200), 'body' => wp_json_encode(array(
					'shipment_reference' => 'x',
					'status'             => 'registered',
					'notes'              => 'Stil den ved carporten',
				)));
			}
			if (strpos((string) $url, '/api/v1/configuration') !== false) {
				return array('response' => array('code' => 200), 'body' => wp_json_encode(array('order_cutoff' => array(
					'time' => '23:59:00', 'days_before_delivery' => 1, 'time_zone' => 'Europe/Copenhagen',
				))));
			}

			return array('response' => array('code' => 200), 'body' => '{}');
		}, 1, 3);
	}

	public function tearDown(): void
	{
		$this->forget_the_merchant();
		parent::tearDown();
	}

	/** One of two loaves refunded off the addition: the delivery carries one. */
	public function test_a_line_refunded_off_an_addition_comes_off_the_delivery(): void
	{
		list($parent, $addition) = $this->a_delivery_with_an_addition();

		$this->refund($addition, 1);

		$this->assertCount(1, $this->puts, 'The earlier order is sent again, once.');
		list($url, $body) = $this->puts[0];
		$this->assertStringEndsWith('/api/v1/shipments/' . rawurlencode(oko_shipment_reference($parent)), $url);
		$this->assertSame(array('line_items'), array_keys($body));
		$this->assertSame(
			array('Økologiske æg' => 2, 'ADDITION — Rugbrød' => 1),
			array_column($body['line_items'], 'quantity', 'name')
		);
	}

	/**
	 * Refunded in full, the addition comes off the way a cancelled one does:
	 * its goods off the list, a line on the shipment's note, and off the
	 * earlier order's own list. Once.
	 */
	public function test_an_addition_refunded_in_full_is_taken_off_the_delivery(): void
	{
		list($parent, $addition) = $this->a_delivery_with_an_addition();

		$this->refund($addition, 2);

		$this->assertSame('refunded', wc_get_order($addition->get_id())->get_status());
		$this->assertCount(1, $this->puts);
		$body = $this->puts[0][1];
		$this->assertSame(array('Økologiske æg'), array_column($body['line_items'], 'name'));
		$this->assertStringContainsString(
			sprintf(__('ADDITION: order %s was refunded. Its goods have been taken off the list.', O_TEXTDOMAIN), $addition->get_order_number()),
			(string) $body['notes']
		);
		$this->assertStringContainsString('Stil den ved carporten', (string) $body['notes'], "The customer's own message survives.");
		$this->assertSame(array(), oko_addition_ids(wc_get_order($parent->get_id())));
	}

	/** An addition edited while on hold sends the goods as they are now. */
	public function test_an_edited_addition_sends_its_goods_again(): void
	{
		list(, $addition) = $this->a_delivery_with_an_addition();

		$addition = wc_get_order($addition->get_id());
		foreach ($addition->get_items('line_item') as $item) {
			$item->set_quantity(3);
			$item->save();
		}
		$addition->save();

		$this->assertCount(1, $this->puts);
		$this->assertSame(
			array('Økologiske æg' => 2, 'ADDITION — Rugbrød' => 3),
			array_column($this->puts[0][1]['line_items'], 'quantity', 'name')
		);
	}

	/**
	 * A save that leaves the goods alone sends nothing. A webhook completes
	 * every addition along with the delivery it rode on.
	 */
	public function test_an_addition_saved_without_touching_its_goods_sends_nothing(): void
	{
		list(, $addition) = $this->a_delivery_with_an_addition();

		wc_get_order($addition->get_id())->update_status('completed');

		$this->assertSame(array(), $this->puts);
	}

	/** An addition the delivery never took has nothing on it to correct. */
	public function test_an_addition_not_on_the_delivery_is_left_alone(): void
	{
		list($parent, $addition) = $this->a_delivery_with_an_addition();
		$parent = wc_get_order($parent->get_id());
		$parent->update_meta_data(OKO_ADDITIONS_META, array());
		$parent->save_meta_data();

		$this->refund($addition, 1);

		$this->assertSame(array(), $this->puts);
	}

	/**
	 * Putting an addition on sends its goods once.
	 *
	 * The write keeps the earlier order's list of additions, which is a save
	 * of that order — and its resend, comparing against a record without the
	 * new goods, sent the whole list a second time.
	 */
	public function test_putting_an_addition_on_sends_its_goods_once(): void
	{
		list($parent) = $this->a_delivery_with_an_addition();
		$smoer        = $this->an_addition_to($parent, 'Smør');

		oko_send_addition_to_its_order($smoer, $parent->get_id(), array());

		// Said in full when it fails: it has, rarely and only in the whole
		// suite (#89), and what the second PUT carried is the clue.
		$this->assertCount(1, $this->puts, 'Sent again: ' . wp_json_encode(array_slice($this->puts, 1))
			. ' Record: ' . wp_json_encode(wc_get_order($parent->get_id())->get_meta(OKO_SENT_META, true)));
		$this->assertContains('ADDITION — Smør', array_column($this->puts[0][1]['line_items'], 'name'));
		$this->assertSame('yes', (string) wc_get_order($smoer->get_id())->get_meta('billing_okoskabet_done', true));
	}

	/**
	 * An earlier order at Økoskabet with one addition on it, and the record of
	 * what Økoskabet holds: the order's eggs and the addition's two loaves.
	 *
	 * @return array{0:\WC_Order,1:\WC_Order}
	 */
	private function a_delivery_with_an_addition(): array
	{
		$parent = wc_create_order();
		$parent->add_product($this->a_product('Økologiske æg'), 2);
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id('hey_okoskabet_shipping_home');
		$shipping->set_method_title('Hjemmelevering');
		$parent->add_item($shipping);
		$parent->set_billing_email('pernille@example.org');
		$parent->set_shipping_first_name('Pernille');
		$parent->set_shipping_last_name('Hansen');
		$parent->set_shipping_address_1('Eksempelvej 12');
		$parent->set_shipping_city('Beder');
		$parent->set_shipping_postcode('8330');
		$parent->update_meta_data('_billing_okoskabet_delivery_date', gmdate('Y-m-d', strtotime('+6 days')));
		$parent->update_meta_data('billing_okoskabet_done', true);
		$parent->set_status('processing');
		$parent->calculate_totals(false);
		$parent->save();
		oko_keep_booked_reference($parent, (string) $parent->get_order_number());
		$parent->save_meta_data();

		$addition = $this->an_addition_to($parent, 'Rugbrød', 2);
		$addition->update_meta_data('billing_okoskabet_done', 'yes');
		$addition->set_status('processing');
		$addition->save();

		$parent = wc_get_order($parent->get_id());
		$parent->update_meta_data(OKO_ADDITIONS_META, array($addition->get_id()));
		$parent->save_meta_data();

		$parent = wc_get_order($parent->get_id());
		oko_remember_what_was_sent($parent, oko_shipment_payload($parent, oko_merchant_for_order($parent)));
		$parent->save_meta_data();
		$this->puts = array();

		return array(wc_get_order($parent->get_id()), wc_get_order($addition->get_id()));
	}

	private function an_addition_to(\WC_Order $parent, string $product, int $quantity = 1): \WC_Order
	{
		$addition = wc_create_order();
		$addition->add_product($this->a_product($product), $quantity);
		$addition->set_currency('DKK');
		$addition->update_meta_data(OKO_ADDON_PARENT_ID_META, (string) $parent->get_id());
		$addition->calculate_totals(false);
		$addition->save();

		return wc_get_order($addition->get_id());
	}

	/** Refund this many loaves, at 10 kroner each, without touching the stock. */
	private function refund(\WC_Order $order, int $loaves): void
	{
		foreach ($order->get_items('line_item') as $item) {
			$refund = wc_create_refund(array(
				'order_id'       => $order->get_id(),
				'amount'         => 10 * $loaves,
				'line_items'     => array($item->get_id() => array('qty' => $loaves, 'refund_total' => 10 * $loaves)),
				'refund_payment' => false,
				'restock_items'  => false,
			));
			$this->assertNotWPError($refund);
		}
	}

	private function a_product(string $name): \WC_Product
	{
		$product = new \WC_Product_Simple();
		$product->set_name($name);
		$product->set_regular_price('10');
		$product->save();

		return $product;
	}
}
