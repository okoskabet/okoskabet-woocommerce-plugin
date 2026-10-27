<?php

/**
 * Økoskabet's change, written back, moves the record of what it holds by that
 * change and nothing more.
 *
 * The write-back used to write the whole order down as what Økoskabet holds.
 * An edit of the shop's own that had not got through yet — a 5xx left to
 * retry, a key Økoskabet would not take, a refusal held back — was then
 * recorded as sent along with Økoskabet's change, and never sent again.
 *
 * `oko_resend_shipment_on_update()` sends an order at most once a request, so
 * the shop's edit is put on the order the way the earlier request would have
 * left it: on the order, and not in the record.
 */
class WriteBackLeavesUnsentEditsTest extends \Codeception\TestCase\WPTestCase
{
	use ConfiguresMerchant;

	/** @var array<int,string> Every PUT body. */
	private $puts = array();

	/** @var array<string,mixed> What `GET /shipments/{reference}` answers. */
	private $shipment = array();

	public function setUp(): void
	{
		parent::setUp();
		$this->a_merchant_with_an_account();
		$this->puts     = array();
		$this->shipment = array();

		add_filter('pre_http_request', function ($preempt, $args, $url) {
			if (($args['method'] ?? '') === 'PUT') {
				$this->puts[] = (string) ($args['body'] ?? '');

				return array('response' => array('code' => 200), 'body' => '{}');
			}
			if (strpos((string) $url, '/api/v1/shipments/') !== false) {
				return array('response' => array('code' => 200), 'body' => wp_json_encode($this->shipment));
			}

			return array('response' => array('code' => 200), 'body' => '{}');
		}, 1, 3);
	}

	public function tearDown(): void
	{
		$this->forget_the_merchant();
		parent::tearDown();
	}

	/**
	 * A phone the shop changed, still on its way, goes after Økoskabet moves
	 * the date — and the date does not go back with it.
	 */
	public function test_an_edit_still_on_its_way_is_sent_after_okoskabet_moves_the_date(): void
	{
		$order = $this->an_order_at_okoskabet();
		$this->an_edit_that_has_not_got_through($order, '+4599887766');

		$this->okoskabet_moves_it($order, array('delivery_date' => '2026-10-23'));

		$this->assertSame(array('{"customer":{"phone":"+4599887766"}}'), $this->puts);
	}

	/**
	 * A refusal held back is not written down as accepted.
	 *
	 * Økoskabet refused the phone, so it still holds the old one. The record
	 * keeps saying so, and the refusal stays held by its shape: nothing is
	 * sent for it, and nothing is noted twice.
	 */
	public function test_a_refused_edit_is_not_written_down_as_accepted(): void
	{
		$order = $this->an_order_at_okoskabet();
		$order->update_meta_data(OKO_REFUSED_CHANGE_META, oko_shipment_fingerprint(array('customer' => array('phone' => '+45000'))));
		$order->save_meta_data();
		$this->an_edit_that_has_not_got_through(wc_get_order($order->get_id()), '+45000');

		$this->okoskabet_moves_it($order, array('delivery_date' => '2026-10-23'));

		$record = wc_get_order($order->get_id())->get_meta(OKO_SENT_META, true);
		$this->assertSame('+4512345678', $record['customer']['phone'], 'Økoskabet still has the old phone.');
		$this->assertSame('2026-10-23', $record['delivery_date'], "And Økoskabet's own date.");
		$this->assertSame(array(), $this->puts);
	}

	/**
	 * Moved to a shed: the record takes the shed's shape, so the shop's own
	 * edit still goes as the one field it is, and not as the whole order.
	 */
	public function test_an_edit_still_on_its_way_survives_a_move_to_a_shed(): void
	{
		$order = $this->an_order_at_okoskabet();
		$this->an_edit_that_has_not_got_through($order, '+4599887766');

		$this->okoskabet_moves_it($order, array(
			'shipment_type'    => 'shed_reservation',
			'delivery_date'    => '2026-10-22',
			'shed_reservation' => array('shed' => array('id' => 'C2', 'name' => 'City 2')),
		));

		$this->assertSame(array('{"customer":{"phone":"+4599887766"}}'), $this->puts);
		$record = wc_get_order($order->get_id())->get_meta(OKO_SENT_META, true);
		$this->assertSame('C2', $record['reservation']['shed_id'] ?? null);
		$this->assertArrayNotHasKey('home_delivery', $record);
	}

	/**
	 * An address the shop corrected, still on its way, is not put back by
	 * Økoskabet moving the date.
	 *
	 * Økoskabet still holds the address we sent, so it did not change it. The
	 * write-back read the difference as Økoskabet's, wrote the old address
	 * over the correction with a note blaming Økoskabet, and the parcel went
	 * to the old address (#80's review). Now the correction stays, and goes.
	 */
	public function test_an_address_still_on_its_way_is_not_put_back_by_a_date_move(): void
	{
		$order = $this->an_order_at_okoskabet();
		add_filter('oko_resend_shipment_on_update', '__return_false');
		$order->set_shipping_address_1('Eksempelvej 14');
		$order->save();
		remove_filter('oko_resend_shipment_on_update', '__return_false');

		$this->okoskabet_moves_it($order, array('delivery_date' => '2026-10-23'));

		$fresh = wc_get_order($order->get_id());
		$this->assertSame('Eksempelvej 14', $fresh->get_shipping_address_1(), "The shop's correction stays.");
		$this->assertSame('2026-10-23', (string) $fresh->get_meta('_billing_okoskabet_delivery_date', true));
		$this->assertCount(1, $this->puts);
		$this->assertSame('Eksempelvej 14', json_decode($this->puts[0], true)['home_delivery']['address_1'] ?? null, 'And goes.');
	}

	/** And Økoskabet's own move, on an order with nothing waiting, sends nothing. */
	public function test_okoskabets_own_move_is_not_sent_back(): void
	{
		$order = $this->an_order_at_okoskabet();

		$this->okoskabet_moves_it($order, array(
			'delivery_date' => '2026-10-23',
			'home_delivery' => array(
				'recipient_name' => 'Pernille Testmodtager',
				'address'        => array(
					'street_name'  => 'Eksempelvej',
					'house_number' => '14',
					'city'         => 'Beder',
					'postal_code'  => '8330',
				),
			),
		));

		$this->assertSame(array(), $this->puts);
		$record = wc_get_order($order->get_id())->get_meta(OKO_SENT_META, true);
		$this->assertSame('Eksempelvej 14', $record['home_delivery']['address_1'] ?? null);
		$this->assertSame('Pernille Testmodtager', $record['customer']['recipient_name'] ?? null);
	}

	/** The shop's edit, saved in an earlier request that could not send it. */
	private function an_edit_that_has_not_got_through(\WC_Order $order, string $phone): void
	{
		add_filter('oko_resend_shipment_on_update', '__return_false');
		$order = wc_get_order($order->get_id());
		$order->set_billing_phone($phone);
		$order->save();
		remove_filter('oko_resend_shipment_on_update', '__return_false');
	}

	/** Økoskabet's backoffice changes the delivery, and its webhook says so. */
	private function okoskabet_moves_it(\WC_Order $order, array $delivery): void
	{
		$this->shipment = array_merge(array(
			'shipment_reference' => (string) $order->get_order_number(),
			'shipment_type'      => 'home_delivery',
			'delivery_date'      => '2026-10-20',
			'home_delivery'      => array(
				'recipient_name' => 'Pernille Hansen',
				'address'        => array(
					'street_name'  => 'Eksempelvej',
					'house_number' => '12',
					'city'         => 'Beder',
					'postal_code'  => '8330',
				),
			),
		), $delivery);

		$changed = oko_take_delivery_from_okoskabet((string) $order->get_order_number(), $this->merchant());
		$this->assertNotSame(array(), $changed, 'Økoskabet did change the delivery.');
	}

	/** A home delivery, booked, with the record of what was sent. */
	private function an_order_at_okoskabet(): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Økologisk ribbensteg');
		$product->set_regular_price('199');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id('hey_okoskabet_shipping_home');
		$shipping->set_method_title('Hjemmelevering');
		$order->add_item($shipping);
		$order->set_billing_first_name('Pernille');
		$order->set_billing_last_name('Hansen');
		$order->set_billing_phone('+4512345678');
		$order->set_billing_email('pernille@example.org');
		$order->set_shipping_first_name('Pernille');
		$order->set_shipping_last_name('Hansen');
		$order->set_shipping_address_1('Eksempelvej 12');
		$order->set_shipping_city('Beder');
		$order->set_shipping_postcode('8330');
		$order->update_meta_data('_billing_okoskabet_delivery_date', '2026-10-20');
		$order->update_meta_data('billing_okoskabet_done', true);
		$order->set_status('processing');
		$order->calculate_totals(false);
		$order->save();
		oko_keep_booked_reference($order, (string) $order->get_order_number());
		$order->save_meta_data();

		$order = wc_get_order($order->get_id());
		oko_remember_what_was_sent($order, oko_shipment_payload($order, $this->merchant()));
		$order->save_meta_data();
		$this->puts = array();

		return wc_get_order($order->get_id());
	}

	/** @return array<string,mixed> */
	private function merchant(): array
	{
		return array('id' => 'default', 'api_key' => 'test-key', 'staging' => true);
	}
}
