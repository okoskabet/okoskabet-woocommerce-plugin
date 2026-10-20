<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * An edit in the shop sends what the edit changed, and nothing else.
 *
 * Økoskabet writes the fields it is given and leaves the rest as they stand.
 * Sending the whole order at every save therefore made every edit in the shop
 * an overwrite of everything the packing room had corrected: Avokadokontoret
 * renamed a product on 17 October 2026, the rename touched three orders, and
 * two deliveries that had been moved to a shed and back by hand went with it.
 *
 * Measured on staging on 19 October 2026, before this: the customer's name,
 * phone and mail were put back by the shop's next save, every time. A rename
 * now sends the goods, an address edit sends the address, and what nobody
 * touched is not sent at all.
 */
class OnlyWhatChangedTest extends \Codeception\TestCase\WPTestCase
{
	use ConfiguresMerchant;

	/** @var array<int,string> Every body we sent to Økoskabet. */
	private $bodies = array();

	public function setUp(): void
	{
		parent::setUp();
		$this->a_merchant_with_an_account();
		$this->bodies = array();
	}

	public function tearDown(): void
	{
		$this->forget_the_merchant();
		parent::tearDown();
	}

	/** An order nobody has touched is not sent again. */
	public function test_an_order_that_has_not_moved_is_not_sent(): void
	{
		$payload = $this->payload();

		$this->assertSame(array(), oko_payload_changes($payload, $payload));
	}

	/**
	 * A product rename sends the goods, and leaves the delivery alone.
	 *
	 * This is the Avokadokontoret case written down. The rename touches the
	 * order, the order resends, and the only thing that moved is a line.
	 */
	public function test_a_renamed_product_sends_the_goods_and_nothing_else(): void
	{
		$before = $this->payload();
		$after  = $this->payload();
		$after['line_items'][0]['name'] = 'Økologisk ribbensteg, dansk';

		$changes = oko_payload_changes($before, $after);

		$this->assertSame(array('line_items'), array_keys($changes));
	}

	/** A changed phone sends that one field of the customer. */
	public function test_a_changed_phone_sends_the_phone_alone(): void
	{
		$before = $this->payload();
		$after  = $this->payload();
		$after['customer']['phone'] = '+4531223344';

		$changes = oko_payload_changes($before, $after);

		$this->assertSame(array('customer'), array_keys($changes));
		$this->assertSame(array('phone' => '+4531223344'), $changes['customer']);
	}

	/**
	 * And the recipient the backoffice renamed is not carried along with it.
	 *
	 * The staging run of 19 October: the backoffice renamed the recipient, the
	 * shop then changed something else entirely, and the old name went back.
	 */
	public function test_a_changed_phone_does_not_carry_the_recipient(): void
	{
		$before = $this->payload();
		$after  = $this->payload();
		$after['customer']['phone'] = '+4531223344';

		$changes = oko_payload_changes($before, $after);

		$this->assertArrayNotHasKey('recipient_name', $changes['customer']);
		$this->assertArrayNotHasKey('home_delivery', $changes);
	}

	/** An address edit sends the address, and only the address. */
	public function test_an_address_edit_sends_the_address(): void
	{
		$before = $this->payload();
		$after  = $this->payload();
		$after['home_delivery']['address_1'] = 'Kildeparken 99';

		$changes = oko_payload_changes($before, $after);

		$this->assertSame(array('home_delivery'), array_keys($changes));
	}

	/**
	 * And it sends the whole address, not the line that moved.
	 *
	 * Økoskabet washes the two composed lines into a street, a number and a
	 * flat together, and only runs the wash when the first line is there.
	 * Measured on Gaardmester staging on 19 October 2026: an order whose
	 * second line alone had moved was accepted, changed nothing, and said
	 * nothing. The address travels whole or not at all.
	 */
	public function test_a_changed_flat_carries_the_whole_address(): void
	{
		$before = $this->payload();
		$after  = $this->payload();
		$after['home_delivery']['address_2'] = '1 tv';

		$changes = oko_payload_changes($before, $after);

		$this->assertSame($after['home_delivery'], $changes['home_delivery']);
		$this->assertArrayHasKey(
			'address_1',
			$changes['home_delivery'],
			'Without the first line Økoskabet does not look the address up at all.'
		);
	}

	/**
	 * A second address line the shop has deleted is a change, and sends the
	 * address.
	 *
	 * The shop builds the address with `array_filter`, so a deleted line is a
	 * missing key rather than an empty one, and the address goes without it.
	 * What Økoskabet then makes of an address with no flat is its own wash's
	 * business — this pins that the shop sends the address at all, which is
	 * what it failed to do when the lines were compared one at a time.
	 */
	public function test_a_deleted_address_line_sends_the_address(): void
	{
		$before = $this->payload();
		$after  = $this->payload();
		unset($after['home_delivery']['address_2']);

		$changes = oko_payload_changes($before, $after);

		$this->assertArrayHasKey('home_delivery', $changes);
		$this->assertSame('Kildeparken 98', $changes['home_delivery']['address_1']);
		$this->assertArrayNotHasKey('address_2', $changes['home_delivery']);
	}

	/**
	 * A field the shop has emptied inside the customer is sent as empty.
	 *
	 * Inside an object a missing key reads as "unchanged", exactly as it does
	 * at the top, so leaving it out would leave the old value standing.
	 */
	public function test_an_emptied_customer_field_is_sent_as_empty(): void
	{
		$before = $this->payload();
		$after  = $this->payload();
		unset($after['customer']['recipient_name']);

		$changes = oko_payload_changes($before, $after);

		$this->assertSame(array('recipient_name' => ''), $changes['customer']);
	}

	/**
	 * A delivery that changes shape is sent whole.
	 *
	 * Moving from the door to a shed is not a field edit: `home_delivery`
	 * gives way to `reservation`, and a difference cannot say "this key is
	 * gone" — leaving it out means "unchanged" at the other end.
	 */
	public function test_a_move_to_a_shed_sends_the_whole_order(): void
	{
		$before = $this->payload();

		$after = $this->payload();
		unset($after['home_delivery'], $after['customer']['recipient_name']);
		$after['reservation'] = array('shed_id' => 'C2', 'max_duration_days' => 1);

		$changes = oko_payload_changes($before, $after);

		$this->assertArrayHasKey('reservation', $changes);
		$this->assertArrayHasKey('customer', $changes);
		$this->assertArrayHasKey('line_items', $changes);
		$this->assertArrayHasKey('delivery_date', $changes);
	}

	/** And so is a move the other way. */
	public function test_a_move_back_to_the_door_sends_the_whole_order(): void
	{
		$before = $this->payload();
		unset($before['home_delivery'], $before['customer']['recipient_name']);
		$before['reservation'] = array('shed_id' => 'C2', 'max_duration_days' => 1);

		$changes = oko_payload_changes($before, $this->payload());

		$this->assertArrayHasKey('home_delivery', $changes);
		$this->assertArrayHasKey('line_items', $changes);
	}

	/**
	 * What describes the request rather than the order is never a reason to
	 * send, and never sent.
	 */
	public function test_the_request_fields_are_left_out_of_both_sides(): void
	{
		$before = $this->payload();
		$after  = $this->payload();
		$after['locale']               = 'en_GB';
		$after['webshop_products_url'] = 'https://example.test/other';
		$after['notes']                = 'Noget pakkeriet har skrevet';
		$after['allow_invalid']        = false;

		$this->assertSame(array(), oko_payload_changes($before, $after));
	}

	/** The note is not sent even when something else is. */
	public function test_the_note_is_not_carried_along_with_an_edit(): void
	{
		$before = $this->payload();
		$after  = $this->payload();
		$after['notes']             = 'Stil den et andet sted';
		$after['customer']['phone'] = '+4531223344';

		$this->assertArrayNotHasKey('notes', oko_payload_changes($before, $after));
	}

	/**
	 * The whole way through: an order is sent, then its phone is changed.
	 *
	 * The comparison above is the rule; this is the rule reached through the
	 * save hook a shop actually triggers, which is where it has to hold.
	 */
	public function test_an_edited_order_sends_only_the_edit(): void
	{
		$this->okoskabet_accepts_everything();

		$order = $this->an_order_already_at_okoskabet();

		$order->set_billing_phone('+4531223344');
		$order->save();

		$this->assertCount(1, $this->bodies, 'The edit has to reach Økoskabet.');

		$sent = json_decode($this->bodies[0], true);

		$this->assertSame(array('customer'), array_keys($sent));
		$this->assertSame(array('phone' => '+4531223344'), $sent['customer']);
	}

	/** And an order nothing has happened to is not sent at all. */
	public function test_a_saved_order_with_no_edit_sends_nothing(): void
	{
		$this->okoskabet_accepts_everything();

		$order = $this->an_order_already_at_okoskabet();
		$order->save();

		$this->assertSame(array(), $this->bodies);
	}

	/**
	 * A refusal does not move the record of what Økoskabet holds.
	 *
	 * Moving it would lose the refused field for good: the next edit would
	 * compare against an order Økoskabet never accepted, find that field
	 * unchanged, leave it out, and the two ends would disagree about it
	 * forever with nothing said.
	 */
	public function test_a_refused_change_does_not_count_as_sent(): void
	{
		$this->okoskabet_answers(422, '{"error_message":"nope"}');

		$order = $this->an_order_already_at_okoskabet();
		$held  = $order->get_meta(OKO_SENT_META, true);

		$order->set_billing_phone('+4531223344');
		$order->save();

		$fresh = wc_get_order($order->get_id());

		$this->assertCount(1, $this->bodies);
		$this->assertSame($held, $fresh->get_meta(OKO_SENT_META, true));
		$this->assertNotSame('', (string) $fresh->get_meta(OKO_REFUSED_CHANGE_META, true));
	}

	/**
	 * A refusal is a verdict, so it is said on the order.
	 *
	 * Until now it was a line in `debug.log` and nothing else: the shop
	 * corrected an address, the screen said the edit was saved, Økoskabet
	 * never heard of it, and the change is recorded as dealt with and never
	 * sent again. Measured on Gaardmester staging 2026-10-20 (order 88091),
	 * where a `home_delivery` sent to a collection was refused 422 and lost
	 * with nothing said anywhere a person looks.
	 */
	public function test_a_refusal_is_written_on_the_order(): void
	{
		$this->okoskabet_answers(422, '{"error_message":"Adressen er ugyldig"}');

		$order = $this->an_order_already_at_okoskabet();
		$order->set_billing_phone('+4531223344');
		$order->save();

		$note = $this->last_note($order);

		$this->assertStringContainsString('422', $note);
		$this->assertStringContainsString('Adressen er ugyldig', $note);
		// One sentence ending, not two: Økoskabet's message brings its own.
		$this->assertStringNotContainsString('..', $note);
	}

	/** The reason Økoskabet gives, when it gives none. */
	public function test_a_refusal_without_a_reason_still_says_so(): void
	{
		$this->okoskabet_answers(404, '');

		$order = $this->an_order_already_at_okoskabet();
		$order->set_billing_phone('+4531223355');
		$order->save();

		$note = $this->last_note($order);

		$this->assertStringContainsString('404', $note);
		// Through `__()`, so the assertion moves with the translation instead
		// of pinning a Danish shop to the English msgid.
		$this->assertStringContainsString(__('no reason given', O_TEXTDOMAIN), $note);
	}

	/**
	 * An error page is not a note. A server in front of Økoskabet can answer
	 * HTML, and a note is rendered in the admin.
	 */
	public function test_a_refusal_carries_no_markup_and_is_cut_short(): void
	{
		// Under a code that is a verdict: a 5xx retries, so a gateway page
		// there would never reach a note at all.
		$this->okoskabet_answers(400, '<p>Bad request</p>' . str_repeat('x', 400));

		$order = $this->an_order_already_at_okoskabet();
		$order->set_billing_phone('+4531223366');
		$order->save();

		$note = $this->last_note($order);

		$this->assertStringNotContainsString('<p>', $note);
		$this->assertStringContainsString('Bad request', $note);
		$this->assertStringContainsString('…', $note);
	}

	/** A locked order says why, because the shop cannot fix this one. */
	public function test_a_locked_order_says_a_parcel_has_been_received(): void
	{
		$this->okoskabet_answers(422, '{"error_code":"' . OKO_ERROR_CODE_LOCKED . '","error_message":"Laast"}');

		$order = $this->an_order_already_at_okoskabet();
		$order->set_billing_phone('+4531223377');
		$order->save();

		$note = $this->last_note($order);

		$this->assertSame(
			__('The change did not reach Økoskabet: the order can no longer be changed there, because a parcel has been received. This and later edits stay in the shop.', O_TEXTDOMAIN),
			$note,
			'The locked note, not the one every other refusal gets.'
		);
		$this->assertTrue((bool) wc_get_order($order->get_id())->get_meta(OKO_SHIPMENT_LOCKED_META, true));
	}

	/** A refused key needs a person, so it reaches one. */
	public function test_a_refused_key_tells_the_shop_to_check_it(): void
	{
		$this->okoskabet_answers(401, 'Unauthorized');

		$order = $this->an_order_already_at_okoskabet();
		$order->set_billing_phone('+4531223388');
		$order->save();

		$note = $this->last_note($order);

		$this->assertSame(
			sprintf(
				__('The change did not reach Økoskabet: the API key was refused (%d). Check the key under Økoskabet settings — until then, edits to this order stay in the shop. It is tried again next time the order is saved.', O_TEXTDOMAIN),
				401
			),
			$note
		);
	}

	/**
	 * And a failure that retries says nothing. The edit is not lost — the
	 * fingerprint has not moved, so the next save sends it again — and a note
	 * per bad minute at Økoskabet would be noise about a thing that fixes
	 * itself.
	 */
	public function test_a_failure_that_will_be_retried_writes_no_note(): void
	{
		$this->okoskabet_answers(503, 'Service Unavailable');

		$order = $this->an_order_already_at_okoskabet();
		$before = count(wc_get_order_notes(array('order_id' => $order->get_id())));

		$order->set_billing_phone('+4531223399');
		$order->save();

		$this->assertCount(1, $this->bodies);
		$this->assertCount(
			$before,
			wc_get_order_notes(array('order_id' => $order->get_id())),
			'A 5xx retries on the next save, so there is nothing to tell yet.'
		);
		$this->assertSame('', (string) wc_get_order($order->get_id())->get_meta(OKO_REFUSED_CHANGE_META, true));
	}

	/** And a reason that brings no full stop of its own is given one. */
	public function test_a_reason_without_a_full_stop_gets_one(): void
	{
		$this->okoskabet_answers(400, 'Bad request');

		$order = $this->an_order_already_at_okoskabet();
		$order->set_billing_phone('+4531224400');
		$order->save();

		$note = $this->last_note($order);

		$this->assertStringContainsString('Bad request.', $note);
		$this->assertStringNotContainsString('request..', $note);
	}

	/** The newest note on the order, as a string. */
	private function last_note(\WC_Order $order): string
	{
		$notes = wc_get_order_notes(array('order_id' => $order->get_id()));

		$this->assertNotEmpty($notes, 'The order was expected to carry a note.');

		return (string) $notes[0]->content;
	}

	/** Økoskabet, taking whatever it is sent, and keeping the body. */
	private function okoskabet_accepts_everything(): void
	{
		$this->okoskabet_answers(200, '{}');
	}

	/** Økoskabet, answering every send the same way. */
	private function okoskabet_answers(int $code, string $body): void
	{
		remove_all_filters('pre_http_request');

		$bodies = &$this->bodies;
		add_filter('pre_http_request', static function ($preempt, $args, $url) use (&$bodies, $code, $body) {
			if (strpos($url, '/api/v1/shipments/') === false || ($args['method'] ?? '') !== 'PUT') {
				return $preempt;
			}

			$bodies[] = (string) ($args['body'] ?? '');

			return array('response' => array('code' => $code), 'body' => $body);
		}, 10, 3);
	}

	/** @return array<string,mixed> */
	private function merchant(): array
	{
		return array('id' => 'default', 'api_key' => 'test-key', 'staging' => true);
	}

	/** A payload of the shape the shop builds for a home delivery. */
	private function payload(): array
	{
		return array(
			'locale'                  => 'da_DK',
			'allow_invalid'           => true,
			'shipment_reference'      => '88016',
			'webshop_order_number'    => '88016',
			'webshop_order_reference' => '88016',
			'customer'                => array(
				'first_name'     => 'Marc Seerup',
				'last_name'      => 'Hansen',
				'phone'          => '+4520600078',
				'email'          => 'marc@example.test',
				'recipient_name' => 'Pernille Testmodtager',
			),
			'notes'                   => 'Stil den ved carporten, tak',
			'delivery_date'           => '2026-10-22',
			'line_items'              => array(
				array('name' => 'Økologisk ribbensteg', 'quantity' => 1),
			),
			'home_delivery'           => array(
				'recipient_name' => 'Pernille Testmodtager',
				'address_1'      => 'Kildeparken 98',
				'address_2'      => '3 th',
				'city'           => 'Kolding',
				'postal_code'    => '6000',
			),
			'webshop_products_url'    => 'https://example.test/products',
		);
	}

	/** An order the shop has already sent, with the record of what was sent. */
	private function an_order_already_at_okoskabet(): \WC_Order
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
		$shipping->set_total('99');
		$order->add_item($shipping);

		$order->set_billing_first_name('Marc Seerup');
		$order->set_billing_last_name('Hansen');
		$order->set_billing_phone('+4520600078');
		$order->set_billing_email('marc@example.test');
		$order->set_shipping_first_name('Marc Seerup');
		$order->set_shipping_last_name('Hansen');
		$order->set_shipping_address_1('Kildeparken 98');
		$order->set_shipping_city('Kolding');
		$order->set_shipping_postcode('6000');
		$order->update_meta_data('_billing_okoskabet_delivery_date', '2026-10-22');
		$order->update_meta_data('billing_okoskabet_done', true);
		$order->calculate_totals(false);
		$order->save();

		$fresh   = wc_get_order($order->get_id());
		$payload = oko_shipment_payload($fresh, $this->merchant());

		$fresh->update_meta_data(OKO_SENT_FINGERPRINT_META, oko_shipment_fingerprint($payload));
		$fresh->update_meta_data(OKO_SENT_META, oko_comparable_payload($payload));
		$fresh->save();

		return wc_get_order($fresh->get_id());
	}
}
