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
	 * Writing the order fires the same save hook as a shop's edit. Without
	 * this the correction would go straight back to the account it came from.
	 */
	public function test_the_change_is_not_sent_back(): void
	{
		$order = $this->an_order_delivered_to_the_door();
		$order->update_meta_data(OKO_WRITING_BACK_META, 'yes');
		$order->save();

		$this->assertFalse(
			apply_filters('oko_resend_shipment_on_update', true, $order),
			'A write-back must not become a send.'
		);
	}

	/** An ordinary edit in the shop still goes to Økoskabet. */
	public function test_an_ordinary_edit_is_still_sent(): void
	{
		$this->assertTrue(
			apply_filters('oko_resend_shipment_on_update', true, $this->an_order_delivered_to_the_door())
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
