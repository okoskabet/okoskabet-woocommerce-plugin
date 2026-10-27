<?php

/**
 * An edit of the product, or a bad half minute at Økoskabet, is not an edit
 * of the order.
 *
 * Every line carries what the catalogue says about its goods — tags, picture,
 * categories, attributes — and its total when Økoskabet asks for one. All of
 * it went into the record of what was sent, so a new picture on a product made
 * the next save of every open order with it send the goods again, and a
 * configuration that could not be read took the totals off every order saved
 * meanwhile, and put them back once it could. A change Økoskabet had refused
 * rode along each time, and was refused and noted again.
 *
 * `oko_resend_shipment_on_update()` sends an order at most once a request, so
 * each test here is one request's worth, seeded with what the one before it
 * would have left.
 */
class CatalogueChangesAreNotEditsTest extends \Codeception\TestCase\WPTestCase
{
	use ConfiguresMerchant;

	/** @var array<int,string> Every PUT body. */
	private $puts = array();

	/** @var bool Whether Økoskabet answers `GET /configuration`. */
	private $answers = true;

	/** @var int What a PUT is answered with. */
	private $put_code = 200;

	/** @var string */
	private $put_body = '{}';

	public function setUp(): void
	{
		parent::setUp();
		$this->a_merchant_with_an_account();
		$this->puts     = array();
		$this->answers  = true;
		$this->put_code = 200;
		$this->put_body = '{}';

		add_filter('pre_http_request', function ($preempt, $args, $url) {
			if (($args['method'] ?? '') === 'PUT') {
				$this->puts[] = (string) ($args['body'] ?? '');

				return array('response' => array('code' => $this->put_code), 'body' => $this->put_body);
			}
			if (strpos((string) $url, '/api/v1/configuration') !== false) {
				return $this->answers
					? array('response' => array('code' => 200), 'body' => wp_json_encode(array('send_line_totals' => true)))
					: array('response' => array('code' => 503), 'body' => 'down');
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

	/** A product given a picture after the order was sent: nothing is sent. */
	public function test_a_new_picture_on_the_product_sends_nothing(): void
	{
		$product = $this->a_product();
		$order   = $this->an_order_at_okoskabet($product);

		$this->give_it_a_picture($product);
		$this->a_new_request();
		wc_get_order($order->get_id())->update_status('completed');

		$this->assertSame(array(), $this->puts);
	}

	/**
	 * Nor when the record is from before this, and still holds the catalogue
	 * as it was then — a tag, a picture and a category that have all moved.
	 */
	public function test_a_record_holding_another_catalogue_sends_nothing(): void
	{
		$order = $this->an_order_at_okoskabet($this->a_product());
		$sent  = $order->get_meta(OKO_SENT_META, true);
		$sent['line_items'][0]['tags']       = array('Frost');
		$sent['line_items'][0]['image']      = 'https://example.test/old.jpg';
		$sent['line_items'][0]['properties'] = array(array('key' => 'taxonomy:product_cat', 'label' => 'Category', 'values' => array('Kød')));
		$order->update_meta_data(OKO_SENT_META, $sent);
		$order->save_meta_data();

		$this->a_new_request();
		wc_get_order($order->get_id())->update_status('completed');

		$this->assertSame(array(), $this->puts);
	}

	/**
	 * But the catalogue goes with the goods whenever they are sent. The packing
	 * room reads the producer and the zone off these lines.
	 */
	public function test_the_goods_still_carry_the_catalogue_when_they_are_sent(): void
	{
		$product = $this->a_product();
		$order   = $this->an_order_at_okoskabet($product);
		$this->give_it_a_picture($product);

		$this->a_new_request();
		$order = wc_get_order($order->get_id());
		foreach ($order->get_items() as $item) {
			$item->set_name('Økologisk ribbensteg, dansk');
			$item->save();
		}
		$order->save();

		$this->assertCount(1, $this->puts);
		$line = json_decode($this->puts[0], true)['line_items'][0];
		$this->assertSame('Økologisk ribbensteg, dansk', $line['name']);
		$this->assertStringEndsWith('ribben.jpg', (string) $line['image']);
		$this->assertArrayHasKey('tags', $line);
		$this->assertArrayHasKey('properties', $line);
		$this->assertSame(398.0, (float) $line['total']);
	}

	/**
	 * Økoskabet cannot be asked about the totals, and the order is saved: the
	 * prices stay on the lines, so nothing is sent.
	 */
	public function test_a_configuration_that_cannot_be_read_keeps_the_prices(): void
	{
		$order = $this->an_order_at_okoskabet($this->a_product());

		$this->okoskabet_is_down();
		wc_get_order($order->get_id())->update_status('completed');

		$this->assertSame(array(), $this->puts);
		$this->assertTrue(oko_shop_sends_line_totals($this->merchant()), 'The last answer stands.');
	}

	/** And Økoskabet coming back sends nothing either. */
	public function test_okoskabet_coming_back_sends_nothing(): void
	{
		$order = $this->an_order_at_okoskabet($this->a_product());

		$this->okoskabet_is_down();
		wc_get_order($order->get_id())->update_status('on-hold');
		$this->okoskabet_is_back();
		wc_get_order($order->get_id())->update_status('completed');

		$this->assertSame(array(), $this->puts);
	}

	/** An account that has never answered sends no prices: no answer is not a yes either. */
	public function test_an_account_that_has_never_answered_sends_no_prices(): void
	{
		$this->okoskabet_is_down();

		$line = oko_order_line_items($this->an_order_of($this->a_product()), $this->merchant())[0];

		$this->assertArrayNotHasKey('total', $line);
	}

	/**
	 * A refused change stays held when the catalogue moves.
	 *
	 * Held back, a refusal is matched on its shape. The picture used to change
	 * the shape — the goods came along — so the refused phone was sent again,
	 * refused again, and the order got a second note about it.
	 */
	public function test_a_refused_change_stays_held_when_the_catalogue_moves(): void
	{
		$product = $this->a_product();
		$order   = $this->an_order_whose_phone_was_refused($product);

		$this->give_it_a_picture($product);
		$this->a_new_request();
		wc_get_order($order->get_id())->update_status('completed');

		$this->assertSame(array(), $this->puts);
		$this->assertSame(array(), $this->refusal_notes($order));
	}

	/** And while Økoskabet cannot be asked about the totals. */
	public function test_a_refused_change_stays_held_while_okoskabet_cannot_be_asked(): void
	{
		$order = $this->an_order_whose_phone_was_refused($this->a_product());

		$this->okoskabet_is_down();
		wc_get_order($order->get_id())->update_status('completed');

		$this->assertSame(array(), $this->puts);
		$this->assertSame(array(), $this->refusal_notes($order));
	}

	/** A new request: what this one was told is gone, what is cached stays. */
	private function a_new_request(): void
	{
		oko_forget_merchant_configurations();
	}

	private function okoskabet_is_down(): void
	{
		$this->answers = false;
		delete_transient(o_configuration_transient_key('default'));
		oko_forget_merchant_configurations();
	}

	private function okoskabet_is_back(): void
	{
		$this->answers = true;
		delete_transient(o_configuration_transient_key('default'));
		oko_forget_merchant_configurations();
	}

	private function give_it_a_picture(\WC_Product $product): void
	{
		$attachment = wp_insert_attachment(array('post_title' => 'ribben', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'), 'ribben.jpg');
		update_post_meta($attachment, '_wp_attached_file', '2026/10/ribben.jpg');

		$product = wc_get_product($product->get_id());
		$product->set_image_id($attachment);
		$product->save();
	}

	/** An order whose new phone Økoskabet refused, held back as the plugin holds it. */
	private function an_order_whose_phone_was_refused(\WC_Product $product): \WC_Order
	{
		$order = $this->an_order_at_okoskabet($product);

		add_filter('oko_resend_shipment_on_update', '__return_false');
		$order->set_billing_phone('+45000');
		$order->update_meta_data(OKO_REFUSED_CHANGE_META, oko_shipment_fingerprint(array('customer' => array('phone' => '+45000'))));
		$order->save();
		remove_filter('oko_resend_shipment_on_update', '__return_false');

		$this->put_code = 422;
		$this->put_body = wp_json_encode(array('error_code' => 'invalid', 'error_message' => 'Telefonnummeret er ugyldigt.'));

		return wc_get_order($order->get_id());
	}

	/** @return array<int,string> */
	private function refusal_notes(\WC_Order $order): array
	{
		$notes = array();
		foreach (wc_get_order_notes(array('order_id' => $order->get_id())) as $note) {
			if (strpos((string) $note->content, '422') !== false) {
				$notes[] = (string) $note->content;
			}
		}

		return $notes;
	}

	private function a_product(): \WC_Product
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Økologisk ribbensteg');
		$product->set_regular_price('199');
		$product->save();

		return $product;
	}

	/** Two of the product, delivered to the door. */
	private function an_order_of(\WC_Product $product): \WC_Order
	{
		$order = wc_create_order();
		$order->set_currency('DKK');
		$order->add_product($product, 2);
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
		$order->update_meta_data('_billing_okoskabet_delivery_date', gmdate('Y-m-d', strtotime('+7 days')));
		$order->set_status('processing');
		$order->calculate_totals(false);
		$order->save();

		return wc_get_order($order->get_id());
	}

	/** Sent with the totals, and recorded exactly as the plugin records a 2xx. */
	private function an_order_at_okoskabet(\WC_Product $product): \WC_Order
	{
		$order = $this->an_order_of($product);
		$order->update_meta_data('billing_okoskabet_done', true);
		$order->save();

		$order   = wc_get_order($order->get_id());
		$payload = oko_shipment_payload($order, $this->merchant());
		$this->assertArrayHasKey('total', $payload['line_items'][0], 'Sent with its total.');
		oko_remember_what_was_sent($order, $payload);
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
