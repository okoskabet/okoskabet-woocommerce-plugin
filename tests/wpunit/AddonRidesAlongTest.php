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
	 * A shop with no cutoff published is a no for the same reason.
	 *
	 * Asked of an account of its own: the answer for one account is memoised
	 * for the rest of the request, so a second cutoff written over the first
	 * would never be read.
	 */
	public function test_no_published_cutoff_is_a_no(): void
	{
		$merchant = array('id' => 'konto-uden-cutoff', 'api_key' => 'test-key');
		set_transient(o_configuration_transient_key($merchant['id']), array('order_cutoff' => array()), 60);

		$this->assertFalse(oko_before_cutoff_for($this->days_ahead(5), $merchant));

		delete_transient(o_configuration_transient_key($merchant['id']));
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

		oko_send_addition_to_its_order($addition, (string) $parent->get_id(), (string) $parent->get_id(), array(
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
			(string) $parent->get_id(),
			(string) $parent->get_id(),
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
