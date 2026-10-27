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

	/** The status Økoskabet answers a PUT with. */
	private $put_answer = 200;

	/** @var array<string,mixed>|null The shipment a GET answers with, when a test says. */
	private $shipment = null;

	/** Whether Økoskabet asks this shop for the line totals. */
	private $line_totals = false;

	public function setUp(): void
	{
		parent::setUp();
		$this->a_merchant_with_an_account();
		$this->puts       = array();
		$this->put_answer  = 200;
		$this->shipment    = null;
		$this->line_totals = false;

		add_filter('pre_http_request', function ($preempt, $args, $url) {
			if (($args['method'] ?? '') === 'PUT') {
				$this->puts[] = (string) ($args['body'] ?? '');

				return array('response' => array('code' => $this->put_answer), 'body' => '{}');
			}

			// The shipment is still with the merchant, and the account's day
			// is open — what an addition needs before it may ride along.
			if (($args['method'] ?? 'GET') === 'GET' && strpos($url, '/api/v1/shipments/') !== false) {
				return array('response' => array('code' => 200), 'body' => wp_json_encode(
					$this->shipment ?? array('shipment_reference' => 'x', 'status' => 'registered', 'notes' => '')
				));
			}
			if (strpos($url, '/api/v1/configuration') !== false) {
				return array('response' => array('code' => 200), 'body' => wp_json_encode(array(
					'order_cutoff'     => array('time' => '23:59:00', 'days_before_delivery' => 1, 'time_zone' => 'Europe/Copenhagen'),
					'send_line_totals' => $this->line_totals,
				)));
			}

			return array('response' => array('code' => 200), 'body' => '{}');
		}, 1, 3);
	}

	public function tearDown(): void
	{
		$this->forget_the_merchant();
		delete_option(OKO_LINE_TOTALS_SAID_OPTION);
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

	/**
	 * An addition placed after the update does not send the 1.5.2 order whole.
	 *
	 * The addition's goods go onto the shipment in one write. The earlier
	 * order is saved once they are on it, and its 1.5.2 fingerprint has to be
	 * asked about its own goods only — counting the addition's made it look
	 * changed, and the whole order was sent over the packing room's
	 * corrections. Seen on staging on 23 October 2026, on a home delivery and
	 * on a shed.
	 */
	public function test_an_addition_to_a_1_5_2_order_does_not_send_it_whole(): void
	{
		$order = $this->an_order_sent_by_1_5_2();

		$addition = wc_create_order();
		$addition->add_product(wc_get_product($this->product_on($order)), 1);
		$addition->save();

		oko_send_addition_to_its_order($addition, $order->get_id(), array());

		$this->assertCount(1, $this->puts, 'Only the write that puts the addition on the shipment.');
		$this->assertSame(array('line_items', 'notes'), array_keys(json_decode($this->puts[0], true)));
		$sent = wc_get_order($order->get_id())->get_meta(OKO_SENT_META, true);
		$this->assertIsArray($sent, 'The order is upgraded to a record of what was sent.');
		// Less what the catalogue says about the goods, which the record never
		// keeps: a new picture on a product is not an edit of the order.
		$written = array_map(static function (array $line): array {
			unset($line['tags'], $line['properties'], $line['image']);

			return $line;
		}, json_decode($this->puts[0], true)['line_items']);
		$this->assertSame(
			$written,
			$sent['line_items'],
			'The record holds what the addition just wrote, its goods included.'
		);
	}

	/**
	 * A shed, booked by 1.5.2, takes an addition: nothing but the addition is
	 * sent. The second shape seen on staging on 23 October 2026 — no
	 * recipient, no address, the note without the driver's part.
	 */
	public function test_an_addition_to_a_1_5_2_shed_order_does_not_send_it_whole(): void
	{
		$order = $this->an_order_sent_by_1_5_2(array('shed' => 'shed-42'));

		$this->an_addition_to($order);

		$this->assertCount(1, $this->puts, 'Only the write that puts the addition on the shipment.');
		$this->assertSame(array('line_items', 'notes'), array_keys(json_decode($this->puts[0], true)));
		$this->assertIsArray(wc_get_order($order->get_id())->get_meta(OKO_SENT_META, true));
	}

	/**
	 * The goods 1.5.2 hashed are the order's lines as 1.5.2 read them: a
	 * variation under its parent's name, a refunded unit off the count, no
	 * packaging fee — and a second address line as typed. An addition on such
	 * an order still sends nothing but itself.
	 */
	public function test_an_addition_to_a_1_5_2_order_of_many_shapes_does_not_send_it_whole(): void
	{
		$order = $this->an_order_sent_by_1_5_2(array('more_goods' => true, 'address_2' => '2. tv'));

		$this->an_addition_to($order);

		$this->assertCount(1, $this->puts, 'Only the write that puts the addition on the shipment.');
		$this->assertSame(array('line_items', 'notes'), array_keys(json_decode($this->puts[0], true)));
		$this->assertIsArray(wc_get_order($order->get_id())->get_meta(OKO_SENT_META, true));
	}

	/**
	 * A 1.5.2 order that really did move is still sent, addition or not.
	 *
	 * Økoskabet holds the old address — 1.5.2's resend of the new one never
	 * got through. The addition must not be what quietly settles it: the
	 * order goes whole, the way any moved 1.5.2 order does, with the
	 * addition's goods on it.
	 */
	public function test_a_1_5_2_order_that_moved_is_still_sent_when_it_takes_an_addition(): void
	{
		$order = $this->an_order_sent_by_1_5_2(array('sent_address_1' => 'Eksempelvej 10'));

		$this->an_addition_to($order);

		$this->assertCount(2, $this->puts, 'The addition, and then the order that moved.');
		$whole = json_decode($this->puts[1], true);
		$this->assertSame('Eksempelvej 12', $whole['home_delivery']['address_1'] ?? null);
		$this->assertSame(
			json_decode($this->puts[0], true)['line_items'],
			$whole['line_items'],
			'The whole order carries the addition\'s goods, or the PUT would take them off.'
		);
	}

	/** And the same when it is the date that moved. */
	public function test_a_1_5_2_order_whose_date_moved_is_still_sent_when_it_takes_an_addition(): void
	{
		$order = $this->an_order_sent_by_1_5_2(array('sent_date' => gmdate('Y-m-d', strtotime('+8 days'))));

		$this->an_addition_to($order);

		$this->assertCount(2, $this->puts, 'The addition, and then the order that moved.');
		$this->assertSame(
			(string) $order->get_meta('_billing_okoskabet_delivery_date', true),
			json_decode($this->puts[1], true)['delivery_date'] ?? null
		);
	}

	/**
	 * An addition Økoskabet refused is not in the record.
	 *
	 * The record is what Økoskabet holds. Writing the addition's goods into it
	 * after a refusal would make the shop's next save compare against goods
	 * that never arrived — and never send them.
	 */
	public function test_a_refused_addition_is_not_recorded_as_sent(): void
	{
		$order = $this->an_order_sent_by_1_5_2();

		$this->put_answer = 500;
		$this->an_addition_to($order);
		$this->put_answer = 200;

		$this->assertCount(1, $this->puts, 'The addition was tried once.');

		$order = wc_get_order($order->get_id());
		$order->update_status('completed');

		$this->assertCount(1, $this->puts, 'An untouched order is still not sent.');
		$sent = wc_get_order($order->get_id())->get_meta(OKO_SENT_META, true);
		$this->assertIsArray($sent);
		$this->assertCount(1, $sent['line_items'], 'Only the order\'s own goods: the addition never arrived.');
	}

	/** An addition of one of the order's own products, sent onto it. */
	private function an_addition_to(\WC_Order $order): \WC_Order
	{
		$addition = wc_create_order();
		$addition->add_product(wc_get_product($this->product_on($order)), 1);
		$addition->save();

		oko_send_addition_to_its_order($addition, $order->get_id(), array());

		return $addition;
	}

	private function product_on(\WC_Order $order): int
	{
		foreach ($order->get_items() as $item) {
			return (int) $item->get_product_id();
		}

		return 0;
	}

	/**
	 * A flat 1.5.2 never sent reaches Økoskabet with the next edit.
	 *
	 * A checkout with no second shipping line keeps the flat in billing.
	 * 1.5.2 sent the shipping line as typed, so the flat never went; today's
	 * payload sends it from billing. The 1.5.2 fingerprint still matches such
	 * an order, and writing today's payload down then recorded the flat as
	 * sent — so no edit ever sent it.
	 */
	public function test_a_flat_1_5_2_never_sent_goes_with_the_next_edit(): void
	{
		$order = $this->an_order_sent_by_1_5_2(array('billing_address_2' => '2. tv'));
		$this->assertSame('2. tv', oko_second_address_line($order), 'Today the flat is sent from billing.');

		$order->update_status('completed');
		$this->assertSame(array(), $this->puts, 'Nothing has moved since 1.5.2 sent it.');

		$order = wc_get_order($order->get_id());
		$order->set_billing_phone('+4599887766');
		$order->save();

		$this->assertCount(1, $this->puts);
		$sent = json_decode($this->puts[0], true);
		$this->assertSame(array('phone' => '+4599887766'), $sent['customer'] ?? null);
		$this->assertSame('2. tv', $sent['home_delivery']['address_2'] ?? null, 'The flat goes now, with the rest of the address.');
	}

	/**
	 * At a shop Økoskabet asks for line totals, an open 1.5.2 order is not
	 * sent for them on its own.
	 *
	 * 1.5.2 never sent a total. Written down as missing, the second save of
	 * every such order — even one that changed nothing — sent all its goods to
	 * add them, and one Økoskabet had locked by then got a note about an edit
	 * nobody made. The totals go with the goods the next time those change.
	 */
	public function test_a_1_5_2_order_at_a_shop_that_sends_line_totals_is_not_sent_for_them(): void
	{
		$this->line_totals = true;
		$order             = $this->an_order_sent_by_1_5_2(array('dkk' => true));
		$this->assertArrayHasKey(
			'total',
			oko_shipment_payload($order, oko_merchant_for_order($order))['line_items'][0],
			'Today the line goes with its total.'
		);

		$order->update_status('completed');
		$order = wc_get_order($order->get_id());
		$order->update_meta_data('_another_plugins_meta', 'x');
		$order->save();

		$this->assertSame(array(), $this->puts);
	}

	/**
	 * But a recipient with no last name is not sent again for the space 1.5.2
	 * left after it. Økoskabet has the same name either way, and the address
	 * would go with it, over whatever was corrected while the shop ran 1.5.2.
	 */
	public function test_a_recipient_with_no_last_name_is_not_sent_again_for_a_space(): void
	{
		$order = $this->an_order_sent_by_1_5_2(array('no_last_name' => true));
		$order->update_status('completed');

		$order = wc_get_order($order->get_id());
		$order->set_billing_phone('+4599887766');
		$order->save();

		$this->assertSame(array('{"customer":{"phone":"+4599887766"}}'), $this->puts);
	}

	/**
	 * Økoskabet moving the date of an untouched 1.5.2 order is not sent back,
	 * and the order gets the record it would have had on its first save.
	 */
	public function test_okoskabets_move_of_an_untouched_1_5_2_order_is_not_sent_back(): void
	{
		$order = $this->an_order_sent_by_1_5_2();

		$this->okoskabet_moves_the_date_of($order);

		$this->assertSame(array(), $this->puts);
		$record = wc_get_order($order->get_id())->get_meta(OKO_SENT_META, true);
		$this->assertIsArray($record);
		$this->assertSame('2026-12-24', $record['delivery_date'] ?? null);
	}

	/**
	 * A 1.5.2 order that moved since it was sent is still sent whole when
	 * Økoskabet moves its date. Nothing says what Økoskabet holds of it, so
	 * the write-back cannot record that, and writing the order down instead
	 * would mark the shop's move as sent: the address here never went.
	 */
	public function test_a_1_5_2_order_that_moved_is_still_sent_after_okoskabet_moves_its_date(): void
	{
		$order = $this->an_order_sent_by_1_5_2(array('sent_address_1' => 'Eksempelvej 10'));

		$this->okoskabet_moves_the_date_of($order);

		$this->assertCount(1, $this->puts);
		$whole = json_decode($this->puts[0], true);
		$this->assertSame('Eksempelvej 12', $whole['home_delivery']['address_1'] ?? null);
		$this->assertSame('2026-12-24', $whole['delivery_date'] ?? null, "Økoskabet's own date, which is the order's now.");
	}

	/** Økoskabet's backoffice moves the delivery to Christmas Eve, and says so. */
	private function okoskabet_moves_the_date_of(\WC_Order $order): void
	{
		$this->shipment = array(
			'shipment_reference' => (string) $order->get_order_number(),
			'shipment_type'      => 'home_delivery',
			'delivery_date'      => '2026-12-24',
			'home_delivery'      => array(
				'recipient_name' => 'Pernille Hansen',
				'address'        => array(
					'street_name'  => 'Eksempelvej',
					'house_number' => '12',
					'city'         => 'Beder',
					'postal_code'  => '8330',
				),
			),
		);

		$changed = oko_take_delivery_from_okoskabet(
			(string) $order->get_order_number(),
			array('id' => 'default', 'api_key' => 'test-key', 'staging' => true)
		);
		$this->assertSame(array('delivery date 2026-12-24'), $changed);
	}

	/** An order that did move since 1.5.2 sent it is still sent, whole, as before. */
	public function test_an_order_that_moved_since_is_still_sent(): void
	{
		$order = $this->an_order_sent_by_1_5_2();
		$order->set_shipping_address_1('Eksempelvej 14');
		$order->save();

		$this->assertCount(1, $this->puts);
		$this->assertSame('Eksempelvej 14', json_decode($this->puts[0], true)['home_delivery']['address_1'] ?? null);
	}

	/**
	 * An order booked by 1.5.2, and the fingerprint 1.5.2 took of it.
	 *
	 * A home delivery with a driver's note and a customer note, unless the
	 * shape says otherwise:
	 *   - `shed`           a shed reservation instead;
	 *   - `address_2`      a second address line;
	 *   - `dkk`            sold in kroner, the only money line totals go in;
	 *   - `billing_address_2` a flat on the billing address only, the same
	 *                      street — which 1.5.2 did not send;
	 *   - `no_last_name`   a recipient of one word, which 1.5.2 sent with a
	 *                      space after it;
	 *   - `more_goods`     a variation, a packaging fee and a refunded unit
	 *                      beside the simple product;
	 *   - `sent_address_1` / `sent_date`  what 1.5.2 sent, where the order has
	 *                      moved since and the resend never got through.
	 *
	 * @param array<string,mixed> $shape
	 */
	private function an_order_sent_by_1_5_2(array $shape = array()): \WC_Order
	{
		$shed = (string) ($shape['shed'] ?? '');

		$product = new \WC_Product_Simple();
		$product->set_name('Økologisk ribbensteg');
		$product->set_regular_price('199');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);
		if (! empty($shape['dkk'])) {
			$order->set_currency('DKK');
		}

		$lines = array(array(
			'product_id'    => $product->get_id(),
			'variant_id'    => null,
			'name'          => 'Økologisk ribbensteg',
			'variant_title' => null,
			'sku'           => '',
			'quantity'      => 1,
		));

		$variation = null;
		if (! empty($shape['more_goods'])) {
			$parent = new \WC_Product_Variable();
			$parent->set_name('Økologiske hakkebøffer, 8 stk.');
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name('Størrelse');
			$attribute->set_options(array('3 pakker'));
			$attribute->set_visible(true);
			$attribute->set_variation(true);
			$parent->set_attributes(array($attribute));
			$parent->save();

			$variation = new \WC_Product_Variation();
			$variation->set_parent_id($parent->get_id());
			$variation->set_attributes(array('storrelse' => '3 pakker'));
			$variation->set_regular_price('50');
			$variation->set_sku('HAK-3');
			$variation->save();

			$order->add_product($variation, 3);

			$fee = new \WC_Order_Item_Fee();
			$fee->set_name('Emballage');
			$fee->set_total('10');
			$order->add_item($fee);

			// One of the three refunded below: 1.5.2 sent two.
			$lines[] = array(
				'product_id'    => $parent->get_id(),
				'variant_id'    => $variation->get_id(),
				'name'          => 'Økologiske hakkebøffer, 8 stk.',
				'variant_title' => '3 pakker',
				'sku'           => 'HAK-3',
				'quantity'      => 2,
			);
		}

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id($shed !== '' ? 'hey_okoskabet_shipping_shed' : 'hey_okoskabet_shipping_home');
		$shipping->set_method_title($shed !== '' ? 'Økoskab' : 'Hjemmelevering');
		$order->add_item($shipping);

		$last = empty($shape['no_last_name']) ? 'Hansen' : '';

		$order->set_billing_first_name('Pernille');
		$order->set_billing_last_name('Hansen');
		$order->set_billing_phone('+4512345678');
		$order->set_billing_email('pernille@example.org');
		if (isset($shape['billing_address_2'])) {
			$order->set_billing_address_1('Eksempelvej 12');
			$order->set_billing_postcode('8330');
			$order->set_billing_city('Beder');
			$order->set_billing_address_2((string) $shape['billing_address_2']);
		}
		$order->set_shipping_first_name('Pernille');
		$order->set_shipping_last_name($last);
		$order->set_shipping_address_1('Eksempelvej 12');
		if (isset($shape['address_2'])) {
			$order->set_shipping_address_2((string) $shape['address_2']);
		}
		$order->set_shipping_city('Beder');
		$order->set_shipping_postcode('8330');
		$order->set_customer_note('Ring på');
		// A week ahead, so the day is still open for an addition whenever the
		// suite runs.
		$delivery_date = gmdate('Y-m-d', strtotime('+7 days'));
		$order->update_meta_data('_billing_okoskabet_delivery_date', $delivery_date);
		if ($shed !== '') {
			$order->update_meta_data('_billing_okoskabet_shed_id', $shed);
		} else {
			$order->update_meta_data('_billing_okoskabet_delivery_location', 'In front of the door');
			$order->update_meta_data('_billing_okoskabet_delivery_note', 'Bag skuret');
		}
		$order->update_meta_data('billing_okoskabet_done', true);
		$order->set_status('processing');
		$order->calculate_totals(false);
		$order->save();

		if ($variation instanceof \WC_Product_Variation) {
			foreach ($order->get_items('line_item') as $item) {
				if ((int) $item->get_variation_id() === $variation->get_id()) {
					$refund = wc_create_refund(array(
						'order_id'       => $order->get_id(),
						'amount'         => 50,
						'line_items'     => array($item->get_id() => array('qty' => 1, 'refund_total' => 50)),
						'refund_payment' => false,
						'restock_items'  => false,
					));
					$this->assertNotWPError($refund);
				}
			}
			$order = wc_get_order($order->get_id());
		}

		// What 1.5.2's oko_shipment_payload() built for it, less the two keys
		// its fingerprint left out (`locale` and `webshop_products_url`).
		// 1.5.2 did not trim the recipient.
		$recipient = 'Pernille ' . $last;
		$customer  = array(
			'first_name' => 'Pernille',
			'last_name'  => 'Hansen',
			'phone'      => '+4512345678',
			'email'      => 'pernille@example.org',
		);
		if ($shed === '') {
			$customer['recipient_name'] = $recipient;
		}

		$sent_by_1_5_2 = array(
			'allow_invalid'           => true,
			'shipment_reference'      => (string) $order->get_order_number(),
			'webshop_order_number'    => (string) $order->get_id(),
			'webshop_order_reference' => (string) $order->get_order_number(),
			'customer'                => $customer,
			'notes'                   => $shed !== '' ? 'Ring på' : "In front of the door — Bag skuret\nRing på",
			'delivery_date'           => (string) ($shape['sent_date'] ?? $delivery_date),
			'line_items'              => $lines,
		);

		if ($shed !== '') {
			$sent_by_1_5_2['reservation'] = array(
				'shed_id'           => $shed,
				'max_duration_days' => 1,
			);
		} else {
			// The second line is the shipping line as typed: 1.5.2 never read
			// the billing address.
			$sent_by_1_5_2['home_delivery'] = array_filter(array(
				'recipient_name' => $recipient,
				'address_1'      => (string) ($shape['sent_address_1'] ?? 'Eksempelvej 12'),
				'address_2'      => (string) ($shape['address_2'] ?? ''),
				'city'           => 'Beder',
				'postal_code'    => '8330',
				'location'       => 'In front of the door — Bag skuret',
			));
		}

		$order->update_meta_data(OKO_SENT_FINGERPRINT_META, md5((string) wp_json_encode($sent_by_1_5_2)));
		$order->save_meta_data();
		$this->puts = array();

		return wc_get_order($order->get_id());
	}
}
