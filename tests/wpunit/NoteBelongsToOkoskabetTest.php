<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * Who owns the note on a shipment once the order has been sent.
 *
 * Økoskabet's backoffice does, and only it: WooCommerce has no field for it.
 * Checked on Gaardmester's order screen, on an open order — the only note box
 * a shop has is the one in the sidebar, which is WooCommerce's own log and
 * has never been sent. So a resend must not carry the shop's old wording back
 * over whatever the backoffice says now.
 */
class NoteBelongsToOkoskabetTest extends \Codeception\TestCase\WPTestCase
{
	/** @var array<int,array{method:string,body:string}> */
	private $requests = array();

	public function setUp(): void
	{
		parent::setUp();
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		Merchants::save_config(array(
			'default_merchant_id' => 'default',
			'merchants' => array('default' => array(
				'id' => 'default', 'label' => 'D', 'api_key' => 'k', 'staging' => true,
			)),
		));
		$this->requests = array();
		add_filter('pre_http_request', array($this, 'capture'), 10, 3);
	}

	public function tearDown(): void
	{
		remove_filter('pre_http_request', array($this, 'capture'), 10);
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		parent::tearDown();
	}

	public function capture($preempt, $args, $url)
	{
		$this->requests[] = array(
			'method' => (string) ($args['method'] ?? 'GET'),
			'body'   => (string) ($args['body'] ?? ''),
		);

		return array(
			'headers' => array(), 'body' => '{"ok":true}', 'cookies' => array(), 'filename' => null,
			'response' => array('code' => 200, 'message' => 'OK'),
		);
	}

	/** The order as it is created still carries what the customer wrote. */
	public function test_the_note_is_sent_when_the_order_is_created(): void
	{
		$payload = oko_shipment_payload($this->an_order(), o_get_merchant());

		$this->assertSame('ring på', $payload['notes']);
	}

	/**
	 * But an edit in the shop does not send it again.
	 *
	 * Økoskabet's backoffice is the only place it can be changed, so a resend
	 * carrying the shop's copy would undo whatever was written there.
	 */
	public function test_an_edit_does_not_send_the_note_again(): void
	{
		$order = $this->a_sent_order();

		$order->set_billing_phone('+4511223344');
		$order->save();

		$puts = array_values(array_filter($this->requests, static function (array $r): bool {
			return $r['method'] === 'PUT';
		}));

		$this->assertCount(1, $puts, 'The edit still has to reach Økoskabet.');

		$sent = json_decode($puts[0]['body'], true);
		$this->assertArrayNotHasKey('notes', $sent, 'An absent key is what leaves the note as it stands.');
		$this->assertSame('+4511223344', $sent['customer']['phone'], 'And the edit itself still goes.');
	}

	/**
	 * And a note changed in the shop is not a reason to resend at all.
	 *
	 * It would be a call that carries nothing, since the note is stripped.
	 */
	public function test_a_changed_note_alone_sends_nothing(): void
	{
		$order = $this->a_sent_order();

		$order->update_meta_data('_billing_okoskabet_delivery_note', 'noget andet');
		$order->save();

		$this->assertSame(array(), array_values(array_filter($this->requests, static function (array $r): bool {
			return $r['method'] === 'PUT';
		})));
	}

	/** An order with a message for the driver, not yet sent. */
	private function an_order(): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Vare');
		$product->set_regular_price('10');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 1);

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id('hey_okoskabet_shipping_home');
		$shipping->set_method_title('Hjemmelevering');
		$shipping->set_total('99');
		$order->add_item($shipping);

		$order->set_shipping_address_1('Prøvevej 98');
		$order->set_shipping_city('København N');
		$order->set_shipping_postcode('2200');
		$order->update_meta_data('_billing_okoskabet_delivery_date', '2026-10-20');
		$order->update_meta_data('_billing_okoskabet_delivery_note', 'ring på');
		$order->calculate_totals(false);
		$order->save();

		return wc_get_order($order->get_id());
	}

	/** The same, once Økoskabet has it. */
	private function a_sent_order(): \WC_Order
	{
		$order = $this->an_order();
		$order->set_status('processing');
		$order->update_meta_data('billing_okoskabet_done', 'yes');
		$order->update_meta_data(
			OKO_SENT_FINGERPRINT_META,
			oko_shipment_fingerprint(oko_shipment_payload($order, o_get_merchant()))
		);
		$order->save();

		$this->requests = array();

		return wc_get_order($order->get_id());
	}
}
