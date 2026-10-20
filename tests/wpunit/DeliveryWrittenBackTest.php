<?php

/**
 * A delivery changed by hand in Økoskabet's backoffice, put back on the order.
 *
 * The packing room moves a delivery to a shed, or back to the customer's
 * door, or corrects an address. WooCommerce knew nothing of it, so it went on
 * holding what the customer ordered — and the next edit in the shop sent that
 * back over the correction. Avokadokontoret renamed a product on 17 October
 * 2026; the rename touched three orders, and two deliveries that had been
 * moved to a shed and back again by hand were overwritten.
 */
class DeliveryWrittenBackTest extends \Codeception\TestCase\WPTestCase
{
	/** @var array<int,array<string,mixed>> */
	private $sent = array();

	public function setUp(): void
	{
		parent::setUp();
		$this->sent = array();
	}

	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		parent::tearDown();
	}

	/** A webhook that moved nothing about the delivery is not acted on. */
	public function test_only_the_delivery_fields_count(): void
	{
		$this->assertTrue(oko_changes_touch_the_delivery(array('city' => array('value' => 'Kolding'))));
		$this->assertTrue(oko_changes_touch_the_delivery(array('shed_id' => array('value' => 3))));
		$this->assertTrue(oko_changes_touch_the_delivery(array('shipment_type' => array('value' => 'home_delivery'))));

		$this->assertFalse(
			oko_changes_touch_the_delivery(array('status' => array('value' => 'in_shed'))),
			'A step is not a delivery change; the shop already has its own choices for those.'
		);
		$this->assertFalse(oko_changes_touch_the_delivery(array('parcels' => array('value' => array()))));
	}

	/**
	 * Økoskabet's address lands on the order as WooCommerce keeps an address.
	 *
	 * The register holds the street, the number and the flat apart. Composed
	 * the way our own sending is read at the other end, so a write-back and a
	 * resend are a round trip rather than a slow drift.
	 */
	public function test_the_address_is_composed_the_way_it_is_sent(): void
	{
		$lines = oko_delivery_address_lines(array(
			'street_name'  => 'Byagervej',
			'house_number' => '194',
			'floor'        => '1.',
			'door'         => 'tv',
			'city'         => 'Beder',
			'postal_code'  => '8330',
		));

		$this->assertSame('Byagervej 194', $lines['shipping_address_1']);
		$this->assertSame('1. tv', $lines['shipping_address_2']);
		$this->assertSame('Beder', $lines['shipping_city']);
		$this->assertSame('8330', $lines['shipping_postcode']);
	}

	/**
	 * A place name between the street and the postcode goes on the first line.
	 *
	 * "Ærtebjerggårdvej 122, Kirkendrup, 5270 Odense N" is one of Gaardmester's.
	 * On the second line it would sit where the flat goes, and an order with
	 * both would read as a flat called Kirkendrup.
	 */
	public function test_a_supplementary_town_goes_with_the_street(): void
	{
		$lines = oko_delivery_address_lines(array(
			'street_name'        => 'Ærtebjerggårdvej',
			'house_number'       => '122',
			'supplementary_town' => 'Kirkendrup',
			'city'               => 'Odense N',
			'postal_code'        => '5270',
		));

		$this->assertSame('Ærtebjerggårdvej 122, Kirkendrup', $lines['shipping_address_1']);
		$this->assertSame('', $lines['shipping_address_2']);
	}

	/** A delivery moved to a shed is written on the order as one. */
	public function test_a_delivery_moved_to_a_shed_is_written_back(): void
	{
		$order = $this->an_order_delivered_to_the_door();

		$changed = oko_write_delivery_back($order, array(
			'shipment_reference' => (string) $order->get_order_number(),
			'shipment_type'      => 'shed_reservation',
			'delivery_date'      => '2026-10-22',
			'shed_reservation'   => array('shed' => array('id' => 7, 'name' => 'City 2')),
		));

		$this->assertSame('7', $order->get_meta('_billing_okoskabet_shed_id', true));
		$this->assertSame('2026-10-22', $order->get_meta('_billing_okoskabet_delivery_date', true));
		$this->assertNotEmpty($changed, 'The order note has to name what moved.');
	}

	/**
	 * And moved back to the door again, which is the case that was lost.
	 *
	 * Two of Avokadokontoret's orders went to a shed and back. Coming back is
	 * the half that has to clear the shed again: an order carrying both reads
	 * as a shed delivery wherever the shed is looked at first.
	 */
	public function test_a_delivery_moved_back_to_the_door_clears_the_shed(): void
	{
		$order = $this->an_order_delivered_to_the_door();
		$order->update_meta_data('_billing_okoskabet_shed_id', '7');
		$order->save();

		oko_write_delivery_back($order, array(
			'shipment_reference' => (string) $order->get_order_number(),
			'shipment_type'      => 'home_delivery',
			'delivery_date'      => '2026-10-22',
			'home_delivery'      => array('address' => array(
				'street_name'  => 'Kildeparken',
				'house_number' => '98',
				'city'         => 'Kolding',
				'postal_code'  => '6000',
			)),
		));

		$this->assertSame('', $order->get_meta('_billing_okoskabet_shed_id', true));
		$this->assertSame('Kildeparken 98', $order->get_shipping_address_1());
		$this->assertSame('Kolding', $order->get_shipping_city());
	}

	/**
	 * The note names an address, not our column names.
	 *
	 * Measured on Gaardmester's staging, where the first version wrote
	 * "shipping_address_1: Byagervej 194, shipping_address_2: 1 tv,
	 * shipping_city: Beder" into a note the shop's own people read.
	 */
	public function test_the_note_reads_as_an_address(): void
	{
		$order = $this->an_order_delivered_to_the_door();

		$changed = oko_write_delivery_back($order, array(
			'shipment_type' => 'home_delivery',
			'delivery_date' => '2026-10-20',
			'home_delivery' => array('address' => array(
				'street_name'  => 'Byagervej',
				'house_number' => '194',
				'floor'        => '1.',
				'door'         => 'tv',
				'city'         => 'Beder',
				'postal_code'  => '8330',
			)),
		));

		$this->assertContains('delivery address Byagervej 194 1. tv, 8330 Beder', $changed);
		$this->assertStringNotContainsString('shipping_address_1', implode(' ', $changed));
	}

	/**
	 * Nothing about money moves.
	 *
	 * Where a delivery goes is the packing room's business. Re-pricing an
	 * order the customer has already paid is not, and a shop whose totals
	 * changed by themselves would be right to stop trusting this.
	 */
	public function test_the_money_is_left_alone(): void
	{
		$order = $this->an_order_delivered_to_the_door();
		$total  = $order->get_total();
		$method = $order->get_shipping_method();

		oko_write_delivery_back($order, array(
			'shipment_type'    => 'shed_reservation',
			'delivery_date'    => '2026-10-22',
			'shed_reservation' => array('shed' => array('id' => 7, 'name' => 'City 2')),
		));

		$this->assertSame($total, $order->get_total());
		$this->assertSame($method, $order->get_shipping_method());
	}

	/** An unchanged delivery is not written, and leaves no note behind. */
	public function test_a_delivery_that_did_not_move_writes_nothing(): void
	{
		$order = $this->an_order_delivered_to_the_door();

		$changed = oko_write_delivery_back($order, array(
			'shipment_type' => 'home_delivery',
			'delivery_date' => '2026-10-20',
			'home_delivery' => array('address' => array(
				'street_name'  => 'Kildeparken',
				'house_number' => '98',
				'city'         => 'Kolding',
				'postal_code'  => '6000',
			)),
		));

		$this->assertSame(array(), $changed);
	}

	/**
	 * Økoskabet's own change is not pushed back at Økoskabet.
	 *
	 * Writing the order fires the same save hook as a shop's edit. The
	 * write-back records what it has just made the order say, so the resend
	 * builds the same payload, finds the fingerprint already matching, and
	 * stops. Without that, the correction goes straight back to the account it
	 * came from.
	 */
	public function test_the_change_is_not_sent_back(): void
	{
		$order = $this->an_order_delivered_to_the_door();
		$this->okoskabet_answers_with(array(
			'shipment_reference' => (string) $order->get_order_number(),
			'shipment_type'      => 'home_delivery',
			'delivery_date'      => '2026-10-22',
			'home_delivery'      => array('address' => array(
				'street_name'  => 'Byagervej',
				'house_number' => '194',
				'city'         => 'Beder',
				'postal_code'  => '8330',
			)),
		));

		$changed = oko_take_delivery_from_okoskabet(
			(string) $order->get_order_number(),
			$this->merchant()
		);

		$this->assertNotEmpty($changed, 'The delivery did move.');

		$fresh = wc_get_order($order->get_id());

		$this->assertSame(
			oko_shipment_fingerprint(oko_shipment_payload($fresh, $this->merchant())),
			(string) $fresh->get_meta(OKO_SENT_FINGERPRINT_META, true),
			'The write-back has to record what it made the order say, or the resend sends it straight back.'
		);
	}

	/** Økoskabet, answering with this shipment and nothing else. */
	private function okoskabet_answers_with(array $shipment): void
	{
		remove_all_filters('pre_http_request');
		add_filter('pre_http_request', static function ($preempt, $args, $url) use ($shipment) {
			if (strpos($url, '/api/v1/shipments/') === false) {
				return $preempt;
			}

			return array(
				'response' => array('code' => 200),
				'body'     => wp_json_encode($shipment),
			);
		}, 10, 3);
	}

	/** And a shop's own edit still differs from what was sent. */
	public function test_an_ordinary_edit_is_still_sent(): void
	{
		$order = $this->an_order_delivered_to_the_door();
		$order->update_meta_data(
			OKO_SENT_FINGERPRINT_META,
			oko_shipment_fingerprint(oko_shipment_payload($order, $this->merchant()))
		);
		$order->set_billing_phone('+4511223344');
		$order->save();

		$fresh = wc_get_order($order->get_id());

		$this->assertNotSame(
			(string) $fresh->get_meta(OKO_SENT_FINGERPRINT_META, true),
			oko_shipment_fingerprint(oko_shipment_payload($fresh, $this->merchant())),
			"A shop's own edit still has to reach Økoskabet."
		);
	}

	/**
	 * A shed the packing room moved the order into survives the next edit.
	 *
	 * The payload reads the kind of shipment off the shipping line the
	 * customer chose, which still says home delivery after Økoskabet moves the
	 * order to a shed. Without the shed meta counting too, the very next edit
	 * in the shop sent the order back as a home delivery and undid the move —
	 * the overwrite this whole change exists to prevent, in the other
	 * direction.
	 */
	public function test_a_shed_written_back_survives_the_next_send(): void
	{
		$order = $this->an_order_delivered_to_the_door();

		oko_write_delivery_back($order, array(
			'shipment_type'    => 'shed_reservation',
			'delivery_date'    => '2026-10-22',
			'shed_reservation' => array('shed' => array('id' => 'C2', 'name' => 'City 2')),
		));
		$order->save();

		$payload = oko_shipment_payload(wc_get_order($order->get_id()), $this->merchant());

		$this->assertSame('C2', $payload['reservation']['shed_id'] ?? null);
		$this->assertArrayNotHasKey('home_delivery', $payload, 'A shed delivery carries no address.');
	}

	/**
	 * And an addition's goods survive an edit to the order it rides on.
	 *
	 * A resend replaces the shipment's lines. Built from the order alone it
	 * deleted every addition — which is exactly what a product rename does:
	 * it touches the orders, and the orders resend.
	 */
	public function test_an_edit_to_the_order_keeps_the_additions_goods(): void
	{
		$order = $this->an_order_delivered_to_the_door();

		$extra = wc_create_order();
		$smoer = new \WC_Product_Simple();
		$smoer->set_name('Smør');
		$smoer->set_regular_price('30');
		$smoer->save();
		$extra->add_product($smoer, 1);
		$extra->save();

		$order->update_meta_data(OKO_ADDITIONS_META, array($extra->get_id()));
		$order->save();

		$names = array_column(
			oko_shipment_payload(wc_get_order($order->get_id()), $this->merchant())['line_items'],
			'name'
		);

		$this->assertContains('Økologisk ribbensteg', $names);
		$this->assertContains('ADDITION — Smør', $names, "The addition's goods must ride along on a resend too.");
	}

	/**
	 * A recipient renamed at Økoskabet is written onto the order.
	 *
	 * Measured on staging before it was: the backoffice could rename the
	 * recipient, the webhook said so, and the shop never heard — until the
	 * next edit in the shop sent the old name back and undid it.
	 */
	public function test_a_renamed_recipient_reaches_the_order(): void
	{
		$order = $this->an_order_delivered_to_the_door();
		$order->set_shipping_first_name('Marc');
		$order->set_shipping_last_name('Hansen');

		$changed = oko_write_delivery_back($order, array(
			'home_delivery' => array('recipient_name' => 'Pernille Testmodtager'),
		));
		$order->save();

		$fresh = wc_get_order($order->get_id());

		$this->assertSame('Pernille', $fresh->get_shipping_first_name());
		$this->assertSame('Testmodtager', $fresh->get_shipping_last_name());
		$this->assertNotSame(array(), $changed, 'A renamed recipient belongs in the order note.');
	}

	/**
	 * A name of three words is left exactly as the shop wrote it.
	 *
	 * Økoskabet holds one name and WooCommerce two, so "Marc Seerup Hansen"
	 * can be first "Marc Seerup" or first "Marc" — both compose back to the
	 * same name. Rewriting it would record a change nobody made, on every
	 * order with a middle name.
	 */
	public function test_the_same_name_split_differently_is_left_alone(): void
	{
		$order = $this->an_order_delivered_to_the_door();
		$order->set_shipping_first_name('Marc Seerup');
		$order->set_shipping_last_name('Hansen');

		$changed = oko_write_delivery_back($order, array(
			'home_delivery' => array('recipient_name' => 'Marc Seerup Hansen'),
		));

		$this->assertSame('Marc Seerup', $order->get_shipping_first_name());
		$this->assertSame(array(), $changed);
	}

	/** And the written-back name is what the next send carries. */
	public function test_the_written_back_recipient_survives_the_next_send(): void
	{
		$order = $this->an_order_delivered_to_the_door();
		$order->set_shipping_first_name('Marc');
		$order->set_shipping_last_name('Hansen');

		oko_write_delivery_back($order, array(
			'home_delivery' => array('recipient_name' => 'Pernille Testmodtager'),
		));
		$order->save();

		$payload = oko_shipment_payload(wc_get_order($order->get_id()), $this->merchant());

		$this->assertSame('Pernille Testmodtager', $payload['home_delivery']['recipient_name']);
	}

	/**
	 * A flat Økoskabet removed does not come back from the billing address.
	 *
	 * Gaardmester's checkout has no second shipping line, so the flat reaches
	 * us through `oko_second_address_line()`'s fallback to the billing
	 * address. When the backoffice then corrects the delivery to a house with
	 * no flat, the write-back clears the shipping line — and the fallback put
	 * the customer's old billing flat straight back into the next payload.
	 *
	 * Worse than a wrong line: it was written into the record of what
	 * Økoskabet accepted, so the two ends disagreed about the address with
	 * nothing to say so, and the shop's next address edit sent the flat back
	 * over the correction.
	 */
	public function test_a_flat_okoskabet_removed_does_not_return_from_billing(): void
	{
		// Gaardmester's checkout has no second shipping line at all; the flat
		// is only ever in the billing address.
		$order = $this->an_order_delivered_to_the_door();
		$order->set_billing_address_1('Kildeparken 98');
		$order->set_billing_postcode('6000');
		$order->set_billing_address_2('3 th');
		$order->save();

		$order = wc_get_order($order->get_id());

		$this->assertSame(
			'3 th',
			oko_second_address_line($order),
			'Until somebody corrects it, the flat reaches us through billing.'
		);

		// Økoskabet says the delivery is the house, with no flat.
		oko_write_delivery_back($order, array(
			'home_delivery' => array(
				'address' => array(
					'street_name'  => 'Kildeparken',
					'house_number' => '98',
					'floor'        => '',
					'door'         => '',
					'city'         => 'Kolding',
					'postal_code'  => '6000',
				),
			),
		));
		$order->save();

		$fresh = wc_get_order($order->get_id());

		$this->assertSame('', $fresh->get_shipping_address_2());
		$this->assertSame(
			'',
			oko_second_address_line($fresh),
			'The billing address is no longer a source once Økoskabet has given us the delivery.'
		);

		$payload = oko_shipment_payload($fresh, $this->merchant());
		$this->assertArrayNotHasKey(
			'address_2',
			$payload['home_delivery'],
			'And the record of what Økoskabet accepted must not carry a flat it rejected.'
		);
	}

	/**
	 * And the mark survives the request, through the entrance that really
	 * writes it.
	 *
	 * `oko_take_delivery_from_okoskabet()` returns early when nothing of the
	 * shop's own moved — which is exactly this case, a flat taken off a
	 * delivery whose shipping line was empty all along. Without a save on
	 * that path the mark lives in memory and dies with the request, and the
	 * billing flat is back on the next payload.
	 */
	public function test_the_mark_survives_the_request_it_was_made_in(): void
	{
		$order = $this->an_order_delivered_to_the_door();
		$order->set_billing_address_1('Kildeparken 98');
		$order->set_billing_postcode('6000');
		$order->set_billing_address_2('3 th');
		$order->save();

		$this->okoskabet_answers_with(array(
			'shipment_reference' => (string) $order->get_order_number(),
			'delivery_date'      => '2026-10-20',
			'home_delivery'      => array(
				'address' => array(
					'street_name'  => 'Kildeparken',
					'house_number' => '98',
					'floor'        => '',
					'door'         => '',
					'city'         => 'Kolding',
					'postal_code'  => '6000',
				),
			),
		));

		oko_take_delivery_from_okoskabet((string) $order->get_order_number(), $this->merchant());

		$fresh = wc_get_order($order->get_id());

		$this->assertNotEmpty(
			$fresh->get_meta(OKO_ADDRESS_FROM_OKOSKABET_META, true),
			'The mark has to be on the order after the request that made it.'
		);
		$this->assertSame('', oko_second_address_line($fresh));
	}

	/**
	 * A collection the packing room arranged survives the shop's next edit.
	 *
	 * The shed has had this guard since the first review round; the
	 * collection did not, so moving an order to Butiksafhentning in the
	 * backoffice was undone by the next save in the shop — the same bug, one
	 * delivery type along.
	 */
	public function test_a_collection_from_the_shop_survives_the_next_send(): void
	{
		$order = $this->an_order_delivered_to_the_door();

		oko_write_delivery_back($order, array(
			'shipment_type'      => 'store_pickup',
			'delivery_date'      => '2026-10-22',
			'pickup_location_id' => 7,
		));
		$order->save();

		$payload = oko_shipment_payload(wc_get_order($order->get_id()), $this->merchant());

		$this->assertSame('store_pickup', $payload['shipment_type'] ?? null);
		$this->assertSame(7, $payload['pickup_location_id'] ?? null);
		$this->assertArrayNotHasKey('home_delivery', $payload, 'A collection carries no address.');
		$this->assertArrayNotHasKey('reservation', $payload, 'And no shed.');
	}

	/**
	 * Both at once: the shop's checkout put the order in a shed, and a
	 * correction here made it a collection. The collection wins, because it is
	 * the later word — and the payload carries no shed beside it, which would
	 * be two delivery targets for one order.
	 */
	public function test_a_collection_beats_a_shed_on_the_same_order(): void
	{
		$order = $this->an_order_delivered_to_the_door();
		$order->update_meta_data('_billing_okoskabet_shed_id', 'SKAB-1');
		$order->update_meta_data('_billing_okoskabet_pickup_location_id', '7');
		$order->save();

		$payload = oko_shipment_payload(wc_get_order($order->get_id()), $this->merchant());

		$this->assertSame('store_pickup', $payload['shipment_type'] ?? null);
		$this->assertSame(7, $payload['pickup_location_id'] ?? null);
		$this->assertArrayNotHasKey('reservation', $payload, 'Not a shed as well.');
	}

	/** And an order moved away from a collection stops being one. */
	public function test_an_order_moved_off_a_collection_is_no_longer_one(): void
	{
		$order = $this->an_order_delivered_to_the_door();
		$order->update_meta_data('_billing_okoskabet_pickup_location_id', '7');
		$order->save();

		$order = wc_get_order($order->get_id());

		oko_write_delivery_back($order, array(
			'shipment_type' => 'home_delivery',
			'home_delivery' => array(
				'recipient_name' => 'Marc Seerup Hansen',
				'address'        => array(
					'street_name'  => 'Kildeparken',
					'house_number' => '98',
					'city'         => 'Kolding',
					'postal_code'  => '6000',
				),
			),
		));
		$order->save();

		$payload = oko_shipment_payload(wc_get_order($order->get_id()), $this->merchant());

		$this->assertArrayNotHasKey('shipment_type', $payload);
		$this->assertArrayHasKey('home_delivery', $payload);
	}

	/** @return array<string,mixed> */
	private function merchant(): array
	{
		return array('id' => 'default', 'api_key' => 'test-key', 'staging' => true);
	}

	/** An order delivered to Kildeparken 98 on 20 October. */
	private function an_order_delivered_to_the_door(): \WC_Order
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

		$order->set_shipping_address_1('Kildeparken 98');
		$order->set_shipping_city('Kolding');
		$order->set_shipping_postcode('6000');
		$order->update_meta_data('_billing_okoskabet_delivery_date', '2026-10-20');
		$order->calculate_totals(false);
		$order->save();

		return $order;
	}
}
