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

	private function given_shipment_status(string $status): void
	{
		remove_all_filters('pre_http_request');
		add_filter('pre_http_request', static function ($preempt, $args, $url) use ($status) {
			if (strpos($url, '/api/v1/shipments/') === false) {
				return $preempt;
			}

			return array(
				'response' => array('code' => 200),
				'body'     => wp_json_encode(array('shipment' => array('status' => $status))),
			);
		}, 10, 3);
	}
}
