<?php

/**
 * Whether an order is a collection from the shop, and whose word makes it one.
 *
 * Three things can say so: the shipping line the customer chose, the place to
 * collect from that the checkout wrote, and Økoskabet's backoffice through the
 * write-back. 1.5.2 read the place for the store-pickup method only. The next
 * version read it on every order, so a place the browser had left behind made
 * a locker or a door delivery into a collection; and once Økoskabet had moved
 * a collection to the door, the shipping line kept it one, with nowhere to
 * collect from. Every combination is written out below, by hand.
 */
class WhenAnOrderIsAStorePickupTest extends \Codeception\TestCase\WPTestCase
{
	use ConfiguresMerchant;

	/** @var array<int,array<string,mixed>> Every PUT body, decoded. */
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
			if (strpos((string) $url, '/api/v1/shipments/') === false) {
				return array('response' => array('code' => 200), 'body' => '{}');
			}
			if (($args['method'] ?? 'GET') === 'PUT') {
				$this->puts[] = json_decode((string) $args['body'], true);

				return array('response' => array('code' => 200), 'body' => '{}');
			}

			return array('response' => array('code' => 200), 'body' => wp_json_encode($this->shipment));
		}, 1, 3);
	}

	public function tearDown(): void
	{
		$this->forget_the_merchant();
		parent::tearDown();
	}

	/**
	 * The shipping line, the place and the shed, and what is sent.
	 *
	 * `okoskabet` is a place the write-back wrote; `cleared` is one it took
	 * away, which is what Økoskabet moving a collection elsewhere looks like.
	 *
	 * @dataProvider every_combination
	 */
	public function test_the_shipping_line_and_okoskabets_word_decide(string $method, string $pickup, string $shed, string $sent): void
	{
		$order = $this->an_order_on($method, $pickup, $shed);

		$payload = oko_shipment_payload($order, $this->merchant());

		$this->assertSame($sent, $this->destination($payload));
		if (($payload['shipment_type'] ?? '') === 'store_pickup') {
			$this->assertArrayNotHasKey('reservation', $payload, 'A collection is not a shed as well.');
			$this->assertArrayNotHasKey('home_delivery', $payload, 'Nor a door.');
		}
	}

	/** @return array<string,array{0:string,1:string,2:string,3:string}> */
	public function every_combination(): array
	{
		$pickup = 'hey_okoskabet_shipping_store_pickup';
		$home   = 'hey_okoskabet_shipping_home';
		$shed   = 'hey_okoskabet_shipping_shed';

		return array(
			'pickup line, no place'                  => array($pickup, 'none', '', 'store_pickup:null'),
			'pickup line, no place, a shed'          => array($pickup, 'none', 'SKAB-1', 'store_pickup:null'),
			'pickup line, checkout place'            => array($pickup, 'checkout', '', 'store_pickup:7'),
			'pickup line, checkout place, a shed'    => array($pickup, 'checkout', 'SKAB-1', 'store_pickup:7'),
			'pickup line, Økoskabet place'           => array($pickup, 'okoskabet', '', 'store_pickup:7'),
			'pickup line, Økoskabet place, a shed'   => array($pickup, 'okoskabet', 'SKAB-1', 'store_pickup:7'),
			'pickup line, moved to the door'         => array($pickup, 'cleared', '', 'home'),
			'pickup line, moved to a shed'           => array($pickup, 'cleared', 'SKAB-1', 'shed:SKAB-1'),

			'home line, no place'                    => array($home, 'none', '', 'home'),
			'home line, no place, a shed'            => array($home, 'none', 'SKAB-1', 'shed:SKAB-1'),
			'home line, leftover place'              => array($home, 'checkout', '', 'home'),
			'home line, leftover place, a shed'      => array($home, 'checkout', 'SKAB-1', 'shed:SKAB-1'),
			'home line, Økoskabet place'             => array($home, 'okoskabet', '', 'store_pickup:7'),
			'home line, Økoskabet place, a shed'     => array($home, 'okoskabet', 'SKAB-1', 'store_pickup:7'),
			'home line, place taken off'             => array($home, 'cleared', '', 'home'),
			'home line, place taken off, a shed'     => array($home, 'cleared', 'SKAB-1', 'shed:SKAB-1'),

			// A shed line with no shed is sent to the door; that is how it
			// was, and nothing here changes it.
			'shed line, no place'                    => array($shed, 'none', '', 'home'),
			'shed line, no place, a shed'            => array($shed, 'none', 'SKAB-1', 'shed:SKAB-1'),
			'shed line, leftover place'              => array($shed, 'checkout', '', 'home'),
			'shed line, leftover place, a shed'      => array($shed, 'checkout', 'SKAB-1', 'shed:SKAB-1'),
			'shed line, Økoskabet place'             => array($shed, 'okoskabet', '', 'store_pickup:7'),
			'shed line, Økoskabet place, a shed'     => array($shed, 'okoskabet', 'SKAB-1', 'store_pickup:7'),
			'shed line, place taken off'             => array($shed, 'cleared', '', 'home'),
			'shed line, place taken off, a shed'     => array($shed, 'cleared', 'SKAB-1', 'shed:SKAB-1'),
		);
	}

	/**
	 * A collection Økoskabet moved to the door, and the shop's next edit.
	 *
	 * The shipping line still says store pickup. Sent as a collection with
	 * nowhere to collect from, the move was thrown away, and the record of what
	 * Økoskabet holds said a collection too — so the shop's next address edit
	 * found nothing to send. Seen in review of 1.5.3.
	 */
	public function test_a_collection_moved_to_the_door_sends_the_shops_next_address_edit(): void
	{
		$order = $this->a_collection_at_okoskabet();

		$this->shipment = array(
			'shipment_reference' => (string) $order->get_order_number(),
			'shipment_type'      => 'home_delivery',
			'delivery_date'      => '2026-10-20',
			'pickup_location_id' => null,
			'home_delivery'      => array('recipient_name' => 'Marc Hansen', 'address' => array(
				'street_name' => 'Eksempelvej', 'house_number' => '12', 'city' => 'Beder', 'postal_code' => '8330',
			)),
		);
		oko_take_delivery_from_okoskabet((string) $order->get_order_number(), $this->merchant());

		$fresh   = wc_get_order($order->get_id());
		$payload = oko_shipment_payload($fresh, $this->merchant());
		$this->assertArrayNotHasKey('shipment_type', $payload, 'No longer a collection.');
		$this->assertSame('Eksempelvej 12', $payload['home_delivery']['address_1'] ?? null);
		$this->assertSame(array(), $this->puts, "Økoskabet's own move is not sent back to it.");

		$fresh->set_shipping_address_1('Eksempelvej 14');
		$fresh->save();

		$this->assertCount(1, $this->puts, "The shop's correction has to reach Økoskabet.");
		$this->assertSame('Eksempelvej 14', $this->puts[0]['home_delivery']['address_1'] ?? null);
	}

	/** And one moved to a shed is a shed, whose next date edit sends the date. */
	public function test_a_collection_moved_to_a_shed_is_sent_as_one(): void
	{
		$order = $this->a_collection_at_okoskabet();

		$this->shipment = array(
			'shipment_reference' => (string) $order->get_order_number(),
			'shipment_type'      => 'shed',
			'delivery_date'      => '2026-10-20',
			'pickup_location_id' => null,
			'shed_reservation'   => array('shed' => array('id' => 'SKAB-1', 'name' => 'Skab 1')),
		);
		oko_take_delivery_from_okoskabet((string) $order->get_order_number(), $this->merchant());

		$fresh   = wc_get_order($order->get_id());
		$payload = oko_shipment_payload($fresh, $this->merchant());
		$this->assertArrayNotHasKey('shipment_type', $payload);
		$this->assertSame('SKAB-1', $payload['reservation']['shed_id'] ?? null);

		$fresh->update_meta_data('_billing_okoskabet_delivery_date', '2026-10-23');
		$fresh->save();

		$this->assertSame(array(array('delivery_date' => '2026-10-23')), $this->puts);
	}

	/**
	 * A collection changed to home delivery in the admin is sent as a home
	 * delivery. The place stays behind on the order, and read on its own it
	 * kept the order a collection: the address edit beside it went nowhere.
	 */
	public function test_a_collection_changed_to_home_delivery_in_the_admin_goes_to_the_door(): void
	{
		$order = $this->a_collection_at_okoskabet();

		foreach ($order->get_items('shipping') as $item) {
			$item->set_method_id('hey_okoskabet_shipping_home');
			$item->save();
		}
		$order = wc_get_order($order->get_id());
		$order->set_shipping_address_1('Prøvevej 99');
		$order->save();

		$this->assertCount(1, $this->puts);
		$this->assertArrayNotHasKey('shipment_type', $this->puts[0]);
		$this->assertSame('Prøvevej 99', $this->puts[0]['home_delivery']['address_1'] ?? null);
	}

	/**
	 * The checkout keeps a place to collect from only for a collection. The
	 * browser is the only other thing that empties it, and a checkout whose
	 * script did not run posts whatever the picker last wrote.
	 */
	public function test_the_checkout_keeps_a_place_only_for_a_collection(): void
	{
		$kept = array();
		foreach (array('hey_okoskabet_shipping_home', 'hey_okoskabet_shipping_shed', 'hey_okoskabet_shipping_store_pickup') as $method) {
			$order = new \WC_Order();
			$order->update_meta_data('_billing_okoskabet_pickup_location_id', '7');
			do_action('woocommerce_checkout_create_order', $order, array('shipping_method' => array($method)));
			$kept[$method] = (string) $order->get_meta('_billing_okoskabet_pickup_location_id', true);
		}

		$this->assertSame(array(
			'hey_okoskabet_shipping_home'         => '',
			'hey_okoskabet_shipping_shed'         => '',
			'hey_okoskabet_shipping_store_pickup' => '7',
		), $kept);
	}

	/** What a payload sends the goods to, as one word. */
	private function destination(?array $payload): string
	{
		if (($payload['shipment_type'] ?? '') === 'store_pickup') {
			return 'store_pickup:' . ($payload['pickup_location_id'] === null ? 'null' : (string) $payload['pickup_location_id']);
		}
		if (isset($payload['reservation'])) {
			return 'shed:' . $payload['reservation']['shed_id'];
		}

		return isset($payload['home_delivery']) ? 'home' : 'nothing';
	}

	/**
	 * An order on this shipping line, with the place and the shed as said.
	 *
	 * A place Økoskabet gave, or took away, goes through the write-back, which
	 * is the only thing that can say a place is Økoskabet's.
	 */
	private function an_order_on(string $method, string $pickup, string $shed): \WC_Order
	{
		$order = $this->an_order($method);

		if ($pickup === 'checkout' || $pickup === 'cleared') {
			$order->update_meta_data('_billing_okoskabet_pickup_location_id', '7');
		}
		if ($pickup === 'okoskabet' || $pickup === 'cleared') {
			$order->update_meta_data('_billing_okoskabet_shed_id', '');
			$shipment = array('pickup_location_id' => $pickup === 'okoskabet' ? 7 : null);
			if ($shed !== '') {
				$shipment['shed_reservation'] = array('shed' => array('id' => $shed, 'name' => $shed));
			}
			oko_write_delivery_back($order, $shipment);
		} elseif ($shed !== '') {
			$order->update_meta_data('_billing_okoskabet_shed_id', $shed);
		}
		$order->save();

		return wc_get_order($order->get_id());
	}

	/** A collection from place 7, booked, with Økoskabet holding what it says. */
	private function a_collection_at_okoskabet(): \WC_Order
	{
		$order = $this->an_order('hey_okoskabet_shipping_store_pickup');
		$order->update_meta_data('_billing_okoskabet_pickup_location_id', '7');
		$order->update_meta_data('billing_okoskabet_done', 'yes');
		$order->save();

		$order = wc_get_order($order->get_id());
		oko_remember_what_was_sent($order, oko_shipment_payload($order, $this->merchant()));
		$order->save_meta_data();
		$this->puts = array();

		return wc_get_order($order->get_id());
	}

	private function an_order(string $method): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Ribbensteg');
		$product->set_regular_price('199');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id($method);
		$shipping->set_method_title($method);
		$shipping->set_total('49');
		$order->add_item($shipping);
		$order->set_billing_first_name('Marc');
		$order->set_billing_last_name('Hansen');
		$order->set_shipping_first_name('Marc');
		$order->set_shipping_last_name('Hansen');
		$order->set_shipping_address_1('Prøvevej 98');
		$order->set_shipping_city('København N');
		$order->set_shipping_postcode('2200');
		$order->update_meta_data('_billing_okoskabet_delivery_date', '2026-10-20');
		$order->calculate_totals(false);
		$order->save();
		oko_keep_booked_reference($order, (string) $order->get_order_number());
		$order->save();

		return $order;
	}

	/** @return array<string,mixed> */
	private function merchant(): array
	{
		return array('id' => 'default', 'api_key' => 'test-key', 'staging' => true);
	}
}
