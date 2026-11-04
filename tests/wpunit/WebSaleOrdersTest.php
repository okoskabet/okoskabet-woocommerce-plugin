<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * Commission sales from okoskabet.dk come into the shop as its own orders.
 *
 * The plugin fetches them with the shop's key, makes each one a paid order —
 * the shop's own product where Økoskabet names one, a line with the title and
 * price otherwise — and reports which order it became. The shipment is
 * Økoskabet's already, so the plugin never books one of these; a cancellation
 * deletes it under `web-<id>` like any other.
 */
class WebSaleOrdersTest extends \Codeception\TestCase\WPTestCase
{
	/** @var array<int,array{method:string,url:string,body:string}> */
	private array $requests = array();

	/** @var array<int,array<string,mixed>> What Økoskabet lists. */
	private array $sales = array();

	/** What Økoskabet answers a report with. */
	private int $report_answer = 200;

	public function setUp(): void
	{
		parent::setUp();
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		Merchants::save_config(array(
			'default_merchant_id' => 'default',
			'merchants' => array('default' => array('id' => 'default', 'label' => 'D', 'api_key' => 'test-key', 'staging' => true)),
		));
		update_option('woocommerce_manage_stock', 'yes');
		delete_option(OKO_WEB_SALE_LOCK_OPTION);

		add_filter('pre_http_request', array($this, 'okoskabet'), 1, 3);
	}

	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		delete_option(Merchants::OPTION_KEY);
		delete_option(OKO_WEB_SALE_LOCK_OPTION);
		Merchants::purge_config_cache();
		parent::tearDown();
	}

	/** Økoskabet, as far as these tests talk to it. */
	public function okoskabet($preempt, $args, $url)
	{
		$method = (string) ($args['method'] ?? 'GET');
		$this->requests[] = array('method' => $method, 'url' => (string) $url, 'body' => (string) ($args['body'] ?? ''));

		if ($method === 'GET' && strpos($url, '/api/v1/web_sale_orders') !== false) {
			return $this->answer(200, array('orders' => $this->sales));
		}
		if ($method === 'POST' && preg_match('#/api/v1/web_sale_orders/[^/]+/created$#', $url)) {
			return $this->answer($this->report_answer, array());
		}
		if ($method === 'DELETE') {
			return $this->answer(204, null);
		}

		return $this->answer(201, array());
	}

	public function test_a_sale_becomes_a_paid_order_with_the_shops_product_and_a_line_of_its_own(): void
	{
		$product = $this->a_product_in_stock(10);
		$this->sales = array($this->a_sale('web-123', array(
			array('product_external_id' => (string) $product->get_id(), 'title' => 'Rugbrød', 'quantity' => 2, 'unit_price' => '45.00'),
			array('product_external_id' => null, 'title' => 'Æblemost fra gården', 'quantity' => 1, 'unit_price' => '160.00'),
		)));

		oko_fetch_web_sale_orders();

		$order = oko_web_sale_order('web-123');
		$this->assertInstanceOf(\WC_Order::class, $order);
		$this->assertSame('processing', $order->get_status());
		$this->assertTrue($order->is_paid());
		$this->assertNotNull($order->get_date_paid());
		$this->assertSame('Foodshipper – Kommissionssalg Web', $order->get_payment_method_title());
		// The goods alone: the freight stays with Foodshipper.
		$this->assertSame('250.00', number_format((float) $order->get_total(), 2, '.', ''));

		$lines = array_values($order->get_items());
		$this->assertCount(2, $lines);
		$this->assertSame($product->get_id(), $lines[0]->get_product_id());
		$this->assertSame(2, $lines[0]->get_quantity());
		$this->assertSame(0, $lines[1]->get_product_id());
		$this->assertSame('Æblemost fra gården', $lines[1]->get_name());
		$this->assertEqualsWithDelta(160.0, (float) $lines[1]->get_total() + (float) $lines[1]->get_total_tax(), 0.01);

		$shipping = array_values($order->get_items('shipping'));
		$this->assertCount(1, $shipping);
		$this->assertContains($shipping[0]->get_method_title(), array('Delivered by Foodshipper', 'Leveres af Foodshipper'));
		$this->assertEqualsWithDelta(0.0, (float) $shipping[0]->get_total() + (float) $shipping[0]->get_total_tax(), 0.001);

		// Off the stock for the shop's own product, and only that.
		$this->assertSame(8, wc_get_product($product->get_id())->get_stock_quantity());

		$this->assertSame('Karen Blixen', $order->get_billing_first_name());
		$this->assertSame('Hansen', $order->get_billing_last_name());
		$this->assertSame('kunde@example.org', $order->get_billing_email());
		$this->assertSame('Eksempelvej 12', $order->get_shipping_address_1());
		$this->assertSame('8330', $order->get_shipping_postcode());
		$this->assertSame('2026-11-10', $order->get_meta('_billing_okoskabet_delivery_date', true));

		$notes = implode("\n", wp_list_pluck(wc_get_order_notes(array('order_id' => $order->get_id())), 'content'));
		$this->assertStringContainsString('web-123', $notes);
		$this->assertStringContainsString('okoskabet.dk', $notes);
		$this->assertMatchesRegularExpression('/Shipping is paid to Foodshipper|Fragten er betalt til Foodshipper/', $notes);

		$reports = $this->requests_to('POST', '/api/v1/web_sale_orders/web-123/created');
		$this->assertCount(1, $reports);
		$this->assertSame(
			array('order_id' => (string) $order->get_id(), 'order_number' => (string) $order->get_order_number()),
			json_decode($reports[0]['body'], true)
		);
	}

	/**
	 * A variable product names no variant, so no stock is taken off any of
	 * them; a variation's own id names one, and its stock is taken.
	 */
	public function test_a_variable_product_is_a_line_of_its_own_and_a_variation_is_linked(): void
	{
		$parent = new \WC_Product_Variable();
		$parent->set_name('Æbler');
		$parent->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id($parent->get_id());
		$variation->set_regular_price('30');
		$variation->set_manage_stock(true);
		$variation->set_stock_quantity(10);
		$variation->save();
		$other = new \WC_Product_Variation();
		$other->set_parent_id($parent->get_id());
		$other->set_regular_price('30');
		$other->set_manage_stock(true);
		$other->set_stock_quantity(10);
		$other->save();

		$this->sales = array($this->a_sale('web-123', array(
			array('product_external_id' => (string) $parent->get_id(), 'title' => 'Æbler, 1 kg', 'quantity' => 2, 'unit_price' => '30.00'),
			array('product_external_id' => (string) $variation->get_id(), 'title' => 'Æbler, 2 kg', 'quantity' => 1, 'unit_price' => '60.00'),
		)));
		$this->sales[0]['total'] = '120.00';

		oko_fetch_web_sale_orders();

		$lines = array();
		foreach (oko_web_sale_order('web-123')->get_items() as $line) {
			$lines[$line->get_name()] = $line;
		}
		$this->assertCount(2, $lines);
		$this->assertSame(0, $lines['Æbler, 1 kg']->get_product_id());
		$linked = array_values(array_diff_key($lines, array('Æbler, 1 kg' => true)));
		$this->assertSame($variation->get_id(), $linked[0]->get_variation_id());

		$this->assertSame(9, wc_get_product($variation->get_id())->get_stock_quantity());
		$this->assertSame(10, wc_get_product($other->get_id())->get_stock_quantity());
	}

	/** An answer with the full name only is split at its first space. */
	public function test_a_full_name_alone_is_split(): void
	{
		$sale = $this->a_sale('web-123');
		unset($sale['customer']['first_name'], $sale['customer']['last_name']);
		$this->sales = array($sale);

		oko_fetch_web_sale_orders();

		$order = oko_web_sale_order('web-123');
		$this->assertSame('Karen', $order->get_billing_first_name());
		$this->assertSame('Blixen Hansen', $order->get_billing_last_name());
	}

	public function test_the_plugin_never_books_it_itself(): void
	{
		$this->sales = array($this->a_sale('web-123'));

		oko_fetch_web_sale_orders();

		$order = oko_web_sale_order('web-123');
		$this->assertSame(array(), $this->requests_to('POST', '/api/v1/shipments'));
		$this->assertSame('web-123', oko_booked_reference($order));
		$this->assertSame('web-123', oko_shipment_reference($order));
		$this->assertNotEmpty($order->get_meta('billing_okoskabet_done', true));
	}

	/**
	 * A run that made the order and never heard back from the report lists the
	 * sale again. The same order is reported again, not made twice.
	 */
	public function test_a_sale_listed_again_is_not_made_twice(): void
	{
		$product = $this->a_product_in_stock(10);
		$this->sales = array($this->a_sale('web-123', array(
			array('product_external_id' => (string) $product->get_id(), 'title' => 'Rugbrød', 'quantity' => 2, 'unit_price' => '45.00'),
		)));

		$this->report_answer = 500;
		oko_fetch_web_sale_orders();
		$this->report_answer = 200;
		oko_fetch_web_sale_orders();

		$this->assertCount(1, oko_order_ids_with_meta(OKO_WEB_SALE_REFERENCE_META, 'web-123'));
		$this->assertSame(8, wc_get_product($product->get_id())->get_stock_quantity());

		$reports = $this->requests_to('POST', '/api/v1/web_sale_orders/web-123/created');
		$this->assertCount(2, $reports);
		$this->assertSame($reports[0]['body'], $reports[1]['body']);
	}

	/** Cancelled in the shop, cancelled at Økoskabet, under the sale's reference. */
	public function test_cancelling_it_deletes_the_shipment_under_its_reference(): void
	{
		$this->sales = array($this->a_sale('web-123'));
		oko_fetch_web_sale_orders();

		oko_web_sale_order('web-123')->update_status('cancelled');

		$deletes = $this->requests_to('DELETE', '/api/v1/shipments/');
		$this->assertCount(1, $deletes);
		$this->assertStringEndsWith('/api/v1/shipments/web-123', $deletes[0]['url']);
	}

	/**
	 * Økoskabet has the sale as some other order. This one is then likely a
	 * second order for the same goods: on hold for the shop, said once.
	 */
	public function test_a_sale_reported_as_another_order_is_held_for_the_shop(): void
	{
		$this->sales = array($this->a_sale('web-123'));
		$this->report_answer = 409;

		oko_fetch_web_sale_orders();
		oko_fetch_web_sale_orders();

		$this->assertCount(1, oko_order_ids_with_meta(OKO_WEB_SALE_REFERENCE_META, 'web-123'));
		$order = oko_web_sale_order('web-123');
		$this->assertSame('on-hold', $order->get_status());
		$this->assertNotSame('', $order->get_meta(OKO_WEB_SALE_CONFLICT_META, true));

		// Held, not cancelled: a cancellation would release the customer's money.
		$this->assertSame(array(), $this->requests_to('DELETE', '/api/v1/shipments/'));

		$held = array_filter(
			wp_list_pluck(wc_get_order_notes(array('order_id' => $order->get_id())), 'content'),
			static fn(string $note): bool => strpos($note, 'another order') !== false || strpos($note, 'anden ordre') !== false
		);
		$this->assertCount(1, $held);
	}

	/** One sale that cannot be taken in leaves the rest to be. */
	public function test_one_bad_sale_does_not_stop_the_others(): void
	{
		$bad = $this->a_sale('web-1');
		$bad['lines'] = array();
		$this->sales = array($bad, $this->a_sale('not-a-web-sale'), $this->a_sale('web-2'));

		oko_fetch_web_sale_orders();

		$this->assertNull(oko_web_sale_order('web-1'));
		$this->assertInstanceOf(\WC_Order::class, oko_web_sale_order('web-2'));
		$this->assertCount(1, $this->requests_to('POST', '/created'));
	}

	/** The customer has their receipt from Økoskabet; the shop still hears of the order. */
	public function test_the_shop_is_mailed_and_the_customer_is_not(): void
	{
		$this->sales = array($this->a_sale('web-123'));

		oko_fetch_web_sale_orders();
		$order = oko_web_sale_order('web-123');

		// Asked of the mails themselves rather than of what was sent. WooCommerce
		// builds its mailer once a request and says so with `woocommerce_email`,
		// which is where the plugin hooks in. In this suite the mailer outlives
		// the test that built it, while every hook added during a test is taken
		// off after it — WooCommerce's own triggers as well as ours — so here no
		// mail goes at all, and ours are hooked in again as on a fresh request.
		do_action('woocommerce_email', WC()->mailer());
		$emails = WC()->mailer()->get_emails();
		foreach ($emails as $email) {
			if ($email->is_customer_email()) {
				$email->object = $order;
				$this->assertFalse($email->is_enabled(), $email->id . ' would mail the customer.');
			}
		}
		$emails['WC_Email_New_Order']->object = $order;
		$this->assertTrue($emails['WC_Email_New_Order']->is_enabled(), 'The shop hears of the order.');

		// And only for these: a customer of the shop's own is mailed as ever.
		$own = wc_create_order();
		$emails['WC_Email_Customer_Processing_Order']->object = $own;
		$this->assertTrue($emails['WC_Email_Customer_Processing_Order']->is_enabled());
	}

	/** @param array<int,array<string,mixed>>|null $lines */
	private function a_sale(string $reference, ?array $lines = null): array
	{
		return array(
			'reference'     => $reference,
			'placed_at'     => '2026-11-04T10:12:00Z',
			'delivery_date' => '2026-11-10',
			'shipment_type' => 'home_delivery',
			'shed_id'       => null,
			'customer'      => array(
				'name'       => 'Karen Blixen Hansen',
				'first_name' => 'Karen Blixen',
				'last_name'  => 'Hansen',
				'email'      => 'kunde@example.org',
				'phone'      => '12345678',
				'address'    => array('street' => 'Eksempelvej 12', 'postal_code' => '8330', 'city' => 'Beder', 'country' => 'DK'),
			),
			'lines'          => $lines ?? array(array('product_external_id' => null, 'title' => 'Rugbrød', 'quantity' => 2, 'unit_price' => '125.00')),
			'shipping_price' => '0.00',
			'total'          => '250.00',
			'currency'       => 'DKK',
			'mark'           => 'Kommissionssalg Web',
		);
	}

	private function a_product_in_stock(int $stock): \WC_Product
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Rugbrød');
		$product->set_regular_price('45');
		$product->set_manage_stock(true);
		$product->set_stock_quantity($stock);
		$product->save();

		return $product;
	}

	/** @return array<int,array{method:string,url:string,body:string}> */
	private function requests_to(string $method, string $path): array
	{
		return array_values(array_filter(
			$this->requests,
			static fn(array $request): bool => $request['method'] === $method && strpos($request['url'], $path) !== false
		));
	}

	/** @param mixed $body */
	private function answer(int $code, $body): array
	{
		return array(
			'headers'  => array(),
			'body'     => $body === null ? '' : (string) wp_json_encode($body),
			'response' => array('code' => $code, 'message' => ''),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
