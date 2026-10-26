<?php

/**
 * When an addition may ride along with a delivery already booked.
 *
 * Two bounds, both Økoskabet's own: the shop's cutoff for that delivery day,
 * and whether the merchant still has the shipment. Both are asked over HTTP,
 * so both are answered here without one.
 */
class AddonRidesAlongTest extends \Codeception\TestCase\WPTestCase
{
	private const MERCHANT = 'default';

	/** @var string[] The accounts a test gave a configuration of their own. */
	private $configured = array();

	public function setUp(): void
	{
		parent::setUp();
		// A shop with a key: without one there is no account to ask, and every
		// answer below would be "no" for that reason rather than the one under
		// test.
		add_filter('o_get_settings', static function ($settings) {
			return array_merge((array) $settings, array('_api_key' => 'test-key'));
		});
		$this->given_cutoff('12:00:00', 1);
		$this->given_shipment_status('registered');
	}

	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		remove_all_filters('o_get_settings');
		delete_transient(o_configuration_transient_key(self::MERCHANT));
		foreach ($this->configured as $id) {
			delete_transient(o_configuration_transient_key($id));
		}
		parent::tearDown();
	}

	/** Well before the cutoff: the day is still open. */
	public function test_a_day_still_open_can_take_more(): void
	{
		$this->assertTrue($this->can_take_more($this->days_ahead(5)));
	}

	/** The cutoff is a day before delivery, so tomorrow's delivery has closed. */
	public function test_the_day_after_the_cutoff_cannot(): void
	{
		$this->assertFalse($this->can_take_more($this->days_ahead(0)));
	}

	/** A delivery already handled is gone, whatever the clock says. */
	public function test_a_shipment_that_has_left_the_merchant_cannot(): void
	{
		$this->given_shipment_status('received');

		$this->assertFalse($this->can_take_more($this->days_ahead(5)));
	}

	/**
	 * Økoskabet unreachable is a no. Saying yes would promise a free ride on a
	 * delivery we cannot see.
	 */
	public function test_an_unanswered_question_is_a_no(): void
	{
		remove_all_filters('pre_http_request');
		add_filter('pre_http_request', static function () {
			return new \WP_Error('http_request_failed', 'down');
		});

		$this->assertFalse($this->can_take_more($this->days_ahead(5)));
	}

	/**
	 * A configuration that came back without a cutoff at all is a no for the
	 * same reason: that is what a failed fetch leaves behind.
	 *
	 * Each case asks of an account of its own: the answer for one account is
	 * memoised for the rest of the request, so a second cutoff written over
	 * the first would never be read.
	 */
	public function test_a_configuration_without_a_cutoff_is_a_no(): void
	{
		$this->assertFalse(oko_before_cutoff_for($this->days_ahead(5), $this->merchant_configured('konto-uden-svar', array())));
	}

	/**
	 * A shop with no cutoff time is held to Økoskabet's logistics cutoff.
	 *
	 * Sean's review of 1.5.3 (#77): Økoskabet publishes such a shop's
	 * `order_cutoff.time` as null, and the plugin read the null as "never" —
	 * additions were silently off for the shop. Økoskabet itself still refuses
	 * a booking after the logistics cutoff the evening before, so that is
	 * where the shop's additions stop too.
	 */
	public function test_no_cutoff_time_stops_at_the_logistics_cutoff(): void
	{
		$merchant = $this->merchant_configured('konto-uden-cutoff', array(
			'order_cutoff' => array('time' => null, 'days_before_delivery' => null, 'time_zone' => 'Europe/Copenhagen'),
		));
		$today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Copenhagen'));

		$this->assertTrue(oko_before_cutoff_for($this->days_ahead(5), $merchant));
		$this->assertFalse(oko_before_cutoff_for($today->format('Y-m-d'), $merchant), 'Not on the day itself.');
	}

	/** The logistics cutoff falls at its hour the evening before. */
	public function test_the_logistics_cutoff_is_the_evening_before(): void
	{
		$zone     = new \DateTimeZone('Europe/Copenhagen');
		$merchant = $this->merchant_configured('konto-uden-cutoff-aften', array(
			'order_cutoff' => array('time' => null, 'days_before_delivery' => null, 'time_zone' => 'Europe/Copenhagen'),
		));
		$evening = new \DateTimeImmutable('2026-11-04 ' . OKO_LOGISTICS_CUTOFF, $zone);

		$this->assertTrue(oko_before_cutoff_for('2026-11-05', $merchant, $evening->modify('-1 minute')));
		$this->assertFalse(oko_before_cutoff_for('2026-11-05', $merchant, $evening->modify('+1 minute')));
	}

	/** A cutoff time with no number of days falls on the delivery day. */
	public function test_no_days_before_delivery_is_the_day_itself(): void
	{
		$merchant = $this->merchant_configured('konto-uden-dage', array(
			'order_cutoff' => array('time' => '00:00:00', 'days_before_delivery' => null, 'time_zone' => 'Europe/Copenhagen'),
		));
		$today = (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Copenhagen')));

		$this->assertTrue(oko_before_cutoff_for($today->modify('+1 day')->format('Y-m-d'), $merchant));
		$this->assertFalse(oko_before_cutoff_for($today->format('Y-m-d'), $merchant), 'Midnight today has passed.');
	}

	/**
	 * @param array<string,mixed> $configuration
	 * @return array<string,string>
	 */
	private function merchant_configured(string $id, array $configuration): array
	{
		set_transient(o_configuration_transient_key($id), $configuration, 60);
		$this->configured[] = $id;

		return array('id' => $id, 'api_key' => 'test-key');
	}

	/**
	 * The addition's goods go last and say what they are, and the shipment's
	 * own note says one arrived — a packer who picked this order yesterday has
	 * to be told, or the extra goods never leave the room.
	 */
	public function test_the_addition_is_marked_and_goes_last(): void
	{
		$sent = null;
		remove_all_filters('pre_http_request');
		add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$sent) {
			if (strpos($url, '/api/v1/shipments/') === false) {
				return $preempt;
			}
			if (($args['method'] ?? 'GET') === 'PUT') {
				$sent = json_decode($args['body'], true);
			}

			return array(
				'response' => array('code' => 200),
				'body'     => wp_json_encode(array(
					'shipment_reference' => '87893',
					'status'             => 'registered',
					'notes'              => 'Stil den ved carporten',
				)),
			);
		}, 10, 3);

		$parent = new \WC_Order();
		$parent->add_product(wc_get_product($this->a_product('Økologiske æg')), 2);
		// A delivery that is still open: the send asks the same question the
		// checkout did, because a bank transfer may be marked paid days later.
		$parent->update_meta_data('_billing_okoskabet_delivery_date', $this->days_ahead(5));
		$parent->save();

		$addition = new \WC_Order();
		$addition->add_product(wc_get_product($this->a_product('Rugbrød')), 1);
		$addition->save();

		oko_send_addition_to_its_order($addition, $parent->get_id(), array(
			'id' => self::MERCHANT,
			'api_key' => 'test-key',
			'staging' => false,
		));

		$this->assertNotNull($sent, 'The shipment should have been updated.');

		$names = array_column($sent['line_items'], 'name');
		$this->assertSame('Økologiske æg', $names[0], "The earlier order's goods come first, untouched.");
		$this->assertStringContainsString('Rugbrød', (string) end($names));
		$this->assertStringStartsWith('ADDITION', (string) end($names), 'The addition has to announce itself on the line.');

		$this->assertStringContainsString(
			'Stil den ved carporten',
			(string) $sent['notes'],
			"The customer's own message must survive."
		);
		$this->assertStringContainsString('ADDITION', (string) $sent['notes']);
	}

	/**
	 * A second addition does not delete the first one's goods.
	 *
	 * A PUT replaces the shipment's lines, so a list built from the parent and
	 * the addition being sent alone wiped every addition before it. A customer
	 * who adds twice to the same Christmas delivery paid for the first lot and
	 * would never have received it.
	 */
	public function test_an_earlier_addition_is_not_wiped_by_a_later_one(): void
	{
		$sent = null;
		$this->capture_put($sent);

		$parent = new \WC_Order();
		$parent->add_product(wc_get_product($this->a_product('Økologiske æg')), 2);
		// A delivery that is still open: the send asks the same question the
		// checkout did, because a bank transfer may be marked paid days later.
		$parent->update_meta_data('_billing_okoskabet_delivery_date', $this->days_ahead(5));
		$parent->save();

		$first = new \WC_Order();
		$first->add_product(wc_get_product($this->a_product('Rugbrød')), 1);
		$first->save();
		$parent->update_meta_data(OKO_ADDITIONS_META, array($first->get_id()));
		$parent->save();

		$second = new \WC_Order();
		$second->add_product(wc_get_product($this->a_product('Smør')), 1);
		$second->save();

		oko_send_addition_to_its_order(
			$second,
			$parent->get_id(),
			array('id' => self::MERCHANT, 'api_key' => 'test-key', 'staging' => false)
		);

		$names = array_column($sent['line_items'], 'name');
		$this->assertSame('Økologiske æg', $names[0], "The parent's goods stay first.");
		$this->assertContains('ADDITION — Rugbrød', $names, "The earlier addition's goods must still be there.");
		$this->assertSame('ADDITION — Smør', end($names), 'And the newest goes last.');
	}

	/** And a successful send records the addition, so the next one finds it. */
	public function test_a_successful_send_records_the_addition(): void
	{
		$parent = new \WC_Order();
		$parent->save();

		$addition = new \WC_Order();
		$addition->save();

		oko_remember_addition($parent, $addition);

		$this->assertContains(
			$addition->get_id(),
			array_map('intval', (array) $parent->get_meta(OKO_ADDITIONS_META, true))
		);
	}

	/**
	 * A cancelled addition takes its goods off the delivery again.
	 *
	 * An addition has no shipment of its own, so a DELETE under its own
	 * reference answers 404 and the goods stay on the order it rode along
	 * with — the shop refunds the customer for things the packing room still
	 * has on its list.
	 */
	public function test_a_cancelled_addition_is_taken_off_the_order(): void
	{
		$sent = null;
		$this->capture_put($sent);

		$parent = new \WC_Order();
		$parent->add_product(wc_get_product($this->a_product('Økologiske æg')), 2);
		$parent->save();

		$kept = new \WC_Order();
		$kept->add_product(wc_get_product($this->a_product('Rugbrød')), 1);
		$kept->save();

		$cancelled = new \WC_Order();
		$cancelled->add_product(wc_get_product($this->a_product('Smør')), 1);
		$cancelled->save();

		$parent->update_meta_data(OKO_ADDITIONS_META, array($kept->get_id(), $cancelled->get_id()));
		$parent->save();

		oko_take_addition_off_its_order(
			$cancelled,
			$parent->get_id(),
			array('id' => self::MERCHANT, 'api_key' => 'test-key', 'staging' => false)
		);

		$names = array_column($sent['line_items'], 'name');
		$this->assertContains('Økologiske æg', $names, "The order's own goods stay.");
		$this->assertContains('ADDITION — Rugbrød', $names, 'And so does the addition that was not cancelled.');
		$this->assertNotContains('ADDITION — Smør', $names, "The cancelled addition's goods are gone.");

		// A packer may have the slip in their hand already. Goods vanishing
		// off a list with nothing said is how the wrong box gets sent.
		$this->assertStringContainsString('ADDITION', (string) $sent['notes']);
		$this->assertStringContainsString(
			'Stil den ved carporten',
			(string) $sent['notes'],
			"And the customer's own message survives this write too."
		);

		$this->assertNotContains(
			$cancelled->get_id(),
			array_map('intval', (array) wc_get_order($parent->get_id())->get_meta(OKO_ADDITIONS_META, true)),
			'The order stops counting it, so the next addition does not put it back.'
		);
	}

	/**
	 * An addition the delivery never took is not written off it.
	 *
	 * A cancelled order that Økoskabet refused — or never reached — is not on
	 * any shipment. Rewriting one on the strength of its own meta alone is a
	 * call that changes nothing and a note telling the shop that goods were
	 * removed which were never there.
	 */
	public function test_an_addition_the_order_never_took_is_left_alone(): void
	{
		$sent = null;
		$this->capture_put($sent);

		$parent = new \WC_Order();
		$parent->add_product(wc_get_product($this->a_product('Økologiske æg')), 2);
		$parent->save();

		$refused = new \WC_Order();
		$refused->add_product(wc_get_product($this->a_product('Smør')), 1);
		$refused->save();

		oko_take_addition_off_its_order(
			$refused,
			$parent->get_id(),
			array('id' => self::MERCHANT, 'api_key' => 'test-key', 'staging' => false)
		);

		$this->assertNull($sent, 'Nothing should have been written to the shipment.');
	}

	/**
	 * A number that is nobody's shipment is not asked about again and again.
	 *
	 * The checkout recalculates as the customer types, and the field they type
	 * into holds an order number — so every keystroke used to cost a fresh
	 * request with a ten-second timeout, because only a good answer was ever
	 * remembered. With Økoskabet down, that is the checkout hanging on every
	 * letter.
	 */
	public function test_a_number_that_answers_nothing_is_only_asked_once(): void
	{
		$asked = 0;
		remove_all_filters('pre_http_request');
		add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$asked) {
			if (strpos($url, '/api/v1/shipments/') === false) {
				return $preempt;
			}
			$asked++;

			return array('response' => array('code' => 404), 'body' => '');
		}, 10, 3);

		$merchant = array('id' => self::MERCHANT, 'api_key' => 'test-key', 'staging' => false);

		$this->assertNull(oko_shipment_at_okoskabet('87893', $merchant));
		$this->assertNull(oko_shipment_at_okoskabet('87893', $merchant));
		$this->assertNull(oko_shipment_at_okoskabet('87893', $merchant));

		$this->assertSame(1, $asked, 'Økoskabet should have been asked once.');

		oko_forget_shipment('87893', $merchant);
	}

	/** @param array<string,mixed>|null $sent */
	private function capture_put(&$sent): void
	{
		remove_all_filters('pre_http_request');
		add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$sent) {
			if (strpos($url, '/api/v1/shipments/') === false) {
				return $preempt;
			}
			if (($args['method'] ?? 'GET') === 'PUT') {
				$sent = json_decode($args['body'], true);
			}

			return array(
				'response' => array('code' => 200),
				'body'     => wp_json_encode(array(
					'shipment_reference' => '87893',
					'status'             => 'registered',
					'notes'              => 'Stil den ved carporten',
				)),
			);
		}, 10, 3);
	}

	/**
	 * An addition too late for its delivery is put on hold for the shop.
	 *
	 * A bank transfer is marked paid days after it was ordered. By then the
	 * delivery may have closed, and the addition stayed in processing — paid,
	 * charged no freight and never sent, with only a note to say so. Whether
	 * the goods go some other way is the shop's to decide (Marc, 21/10).
	 */
	public function test_an_addition_too_late_for_its_delivery_is_held_for_the_shop(): void
	{
		$addition = $this->an_addition_to_a_delivery_on($this->days_ahead(0));

		$addition->update_status('processing');

		$addition = wc_get_order($addition->get_id());
		$this->assertSame('on-hold', $addition->get_status(), 'The shop has to see it is not on its way.');
		$this->assertCount(1, $this->hold_notes($addition), 'And be told why, once.');
		$this->assertSame('', (string) $addition->get_meta('billing_okoskabet_done', true));
	}

	/**
	 * The hold is not one more try. Only the shop moving the order on is: it
	 * asks again, and is told again when the answer is still no.
	 */
	public function test_only_the_shop_moving_it_on_asks_again(): void
	{
		$addition = $this->an_addition_to_a_delivery_on($this->days_ahead(0));
		$addition->update_status('processing');

		$addition = wc_get_order($addition->get_id());
		$addition->save();
		$this->assertCount(1, $this->hold_notes(wc_get_order($addition->get_id())), 'A save asks nothing.');

		$addition->update_status('processing');

		$addition = wc_get_order($addition->get_id());
		$this->assertSame('on-hold', $addition->get_status());
		$this->assertCount(2, $this->hold_notes($addition));
	}

	/**
	 * A card payment carries a transaction id, so nothing further up stops the
	 * hold's own change of status from asking once more. The mark does.
	 */
	public function test_a_card_paid_addition_is_held_once(): void
	{
		$addition = $this->an_addition_to_a_delivery_on($this->days_ahead(0), 'pending');
		$addition->set_payment_method('quickpay');
		$addition->set_transaction_id('qp-4711');
		$addition->save();

		$addition->update_status('processing');

		$addition = wc_get_order($addition->get_id());
		$this->assertSame('on-hold', $addition->get_status());
		$this->assertCount(1, $this->hold_notes($addition), 'The hold is not one more try.');
	}

	/** Once it gets on, it is an addition like any other and no longer held. */
	public function test_a_held_addition_that_gets_on_is_released(): void
	{
		$addition = $this->an_addition_to_a_delivery_on($this->days_ahead(5));
		$addition->update_meta_data(OKO_ADDITION_HELD_META, 'yes');
		$addition->save();

		$addition->update_status('processing');

		$addition = wc_get_order($addition->get_id());
		$this->assertSame('processing', $addition->get_status());
		$this->assertSame('yes', (string) $addition->get_meta('billing_okoskabet_done', true));
		$this->assertSame('', (string) $addition->get_meta(OKO_ADDITION_HELD_META, true));
		$this->assertCount(0, $this->hold_notes($addition));
	}

	/**
	 * An addition paid by bank transfer, as the customer leaves it: on hold
	 * with money owing, which the plugin does not send. The shop setting it to
	 * processing is the shop saying the money has arrived.
	 */
	private function an_addition_to_a_delivery_on(string $delivery_date, string $status = 'on-hold'): \WC_Order
	{
		$parent = new \WC_Order();
		$parent->add_product(wc_get_product($this->a_product('Økologiske æg')), 2);
		$parent->update_meta_data('_billing_okoskabet_delivery_date', $delivery_date);
		$parent->save();

		$addition = new \WC_Order();
		$addition->add_product(wc_get_product($this->a_product('Rugbrød')), 1);
		$addition->set_payment_method('bacs');
		$addition->calculate_totals();
		$addition->update_meta_data(OKO_ADDON_PARENT_ID_META, (string) $parent->get_id());
		$addition->set_status($status);
		$addition->save();

		return $addition;
	}

	/** @return array<int,\WP_Comment> */
	private function hold_notes(\WC_Order $order): array
	{
		$needle = __('The order is on hold for the shop to decide', O_TEXTDOMAIN);

		return array_values(array_filter(
			wc_get_order_notes(array('order_id' => $order->get_id())),
			static fn($note): bool => strpos((string) $note->content, $needle) !== false
		));
	}

	private function a_product(string $name): int
	{
		$product = new \WC_Product_Simple();
		$product->set_name($name);
		$product->set_regular_price('10');
		$product->save();

		return $product->get_id();
	}

	private function can_take_more(string $delivery_date): bool
	{
		$order = new \WC_Order();
		$order->set_billing_email('kunde@example.com');
		$order->update_meta_data('_billing_okoskabet_delivery_date', $delivery_date);
		$order->save();

		return oko_order_can_still_take_more($order, $delivery_date);
	}

	private function days_ahead(int $days): string
	{
		return gmdate('Y-m-d', strtotime('+' . $days . ' days'));
	}

	private function given_cutoff(string $time, int $days_before): void
	{
		set_transient(
			o_configuration_transient_key(self::MERCHANT),
			array('order_cutoff' => array(
				'time'                 => $time,
				'days_before_delivery' => $days_before,
				'time_zone'            => 'Europe/Copenhagen',
			)),
			60
		);
	}

	/**
	 * As `GET /shipments/{reference}` really answers: the shipment itself, not
	 * wrapped. Only `POST` and `PUT` put it under `shipment`. Reading only the
	 * wrapped shape made every lookup look like a shipment with no status, and
	 * every addition was refused as "already sent" — on Gaardmester's staging,
	 * against an order that was plainly registered.
	 */
	private function given_shipment_status(string $status): void
	{
		remove_all_filters('pre_http_request');
		add_filter('pre_http_request', static function ($preempt, $args, $url) use ($status) {
			if (strpos($url, '/api/v1/shipments/') === false) {
				return $preempt;
			}

			return array(
				'response' => array('code' => 200),
				'body'     => wp_json_encode(array(
					'shipment_reference' => '87893',
					'status'             => $status,
					'notes'              => 'Stil den ved carporten',
				)),
			);
		}, 10, 3);
	}
}
