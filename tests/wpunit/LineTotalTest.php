<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * What a line came to, sent with the order.
 *
 * An offer in the Track&Trace app can be charged a share of what it sold, and
 * Økoskabet reckons that share on the order lines' own amounts. Shopify and
 * the API send them; this plugin did not, so a Woo shop's percentage fee came
 * to nothing and the sale read as "pris ukendt" in admin.
 *
 * Only for a shop that uses offers, and Økoskabet says which: line prices are
 * kept for nothing else (Marc, 19 October 2026 — "priserne må kun bruges, når
 * butikken har sagt ja til tilbudsdelen").
 */
class LineTotalTest extends \Codeception\TestCase\WPTestCase
{
	public function setUp(): void
	{
		parent::setUp();
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		Merchants::save_config(array(
			'default_merchant_id' => 'default',
			'merchants' => array('default' => array(
				'id' => 'default', 'label' => 'D', 'api_key' => 'test-key', 'staging' => true,
			)),
		));
	}

	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		delete_transient(o_configuration_transient_key('default'));
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		remove_all_filters('wc_tax_enabled');
		remove_all_filters('woocommerce_prices_include_tax');
		parent::tearDown();
	}

	/** A shop Økoskabet has not asked sends no prices at all. */
	public function test_a_shop_without_offers_sends_no_prices(): void
	{
		$this->okoskabet_says(array('send_line_totals' => false));

		$line = oko_order_line_items($this->an_order_of_two_at_199())[0];

		$this->assertArrayNotHasKey('total', $line);
	}

	/**
	 * And neither does one talking to an Økoskabet that has never heard of
	 * the field — which is every Økoskabet until the offer work is released.
	 */
	public function test_an_okoskabet_that_says_nothing_is_read_as_no(): void
	{
		$this->okoskabet_says(array('statuses' => array()));

		$this->assertArrayNotHasKey('total', oko_order_line_items($this->an_order_of_two_at_199())[0]);
	}

	/** A shop that uses offers sends what the line came to. */
	public function test_a_shop_with_offers_sends_the_line_total(): void
	{
		$this->okoskabet_says(array('send_line_totals' => true));

		$line = oko_order_line_items($this->an_order_of_two_at_199())[0];

		$this->assertSame(398.0, $line['total']);
	}

	/**
	 * With VAT in it only where the shop's own prices have VAT in them.
	 *
	 * Økoskabet takes the amount as the shop reports money, not converted
	 * into a convention of ours.
	 */
	public function test_vat_follows_how_the_shop_prices(): void
	{
		$this->okoskabet_says(array('send_line_totals' => true));

		$order = $this->an_order_of_two_at_199();
		$item  = current($order->get_items('line_item'));
		$item->set_taxes(array(
			'total'    => array(1 => 99.50),
			'subtotal' => array(1 => 99.50),
		));
		$item->save();

		$this->assertSame(
			99.5,
			(float) current(wc_get_order($order->get_id())->get_items('line_item'))->get_total_tax(),
			'The line has to carry VAT for this test to say anything.'
		);

		// Through WooCommerce's own filters rather than its options:
		// `wc_prices_include_tax()` is "tax is on AND prices include it", so
		// setting the second option alone leaves it false and both halves of
		// this would pass for the wrong reason.
		add_filter('wc_tax_enabled', '__return_true');

		add_filter('woocommerce_prices_include_tax', '__return_false');
		$this->assertSame(398.0, oko_order_line_items(wc_get_order($order->get_id()))[0]['total']);
		remove_filter('woocommerce_prices_include_tax', '__return_false');

		add_filter('woocommerce_prices_include_tax', '__return_true');
		$this->assertSame(497.5, oko_order_line_items(wc_get_order($order->get_id()))[0]['total']);
		remove_filter('woocommerce_prices_include_tax', '__return_true');

		remove_filter('wc_tax_enabled', '__return_true');
	}

	/**
	 * What has been refunded is off the amount, as it is off the quantity.
	 *
	 * WooCommerce only lets a line be edited while an order is pending or on
	 * hold, so on an order already sent, refunding is how a shop takes goods
	 * off it. An amount that still counted them would have the shop charged
	 * for an offer on goods the customer got their money back for.
	 */
	public function test_a_refund_comes_off_the_amount(): void
	{
		$this->okoskabet_says(array('send_line_totals' => true));

		$order = $this->an_order_of_two_at_199();
		$item  = current($order->get_items('line_item'));

		wc_create_refund(array(
			'order_id'   => $order->get_id(),
			'amount'     => '199',
			'line_items' => array(
				$item->get_id() => array('qty' => 1, 'refund_total' => 199),
			),
		));

		$line = oko_order_line_items(wc_get_order($order->get_id()))[0];

		$this->assertSame(199.0, $line['total']);
		$this->assertSame(1, $line['quantity'], 'The quantity has always been read this way.');
	}

	/**
	 * A shop selling in another currency sends nothing.
	 *
	 * Økoskabet keeps the figure as an amount and nothing else — there is no
	 * currency beside it — so euros would be reckoned as kroner.
	 */
	public function test_another_currency_sends_no_price(): void
	{
		$this->okoskabet_says(array('send_line_totals' => true));

		$order = $this->an_order_of_two_at_199();
		$order->set_currency('EUR');
		$order->save();

		$this->assertArrayNotHasKey('total', oko_order_line_items(wc_get_order($order->get_id()))[0]);
	}

	/** Økoskabet, answering the configuration call with this and nothing else. */
	private function okoskabet_says(array $configuration): void
	{
		delete_transient(o_configuration_transient_key('default'));
		Merchants::purge_config_cache();
		remove_all_filters('pre_http_request');

		add_filter('pre_http_request', static function ($preempt, $args, $url) use ($configuration) {
			if (strpos($url, '/api/v1/configuration') === false) {
				return $preempt;
			}

			return array(
				'response' => array('code' => 200),
				'body'     => wp_json_encode($configuration),
			);
		}, 1, 3);
	}

	/** Two of a 199-kroner product, on an order of ours. */
	private function an_order_of_two_at_199(): \WC_Order
	{
		$product = new \WC_Product_Simple();
		$product->set_name('Økologisk ribbensteg');
		$product->set_regular_price('199');
		$product->save();

		$order = wc_create_order();
		$order->add_product($product, 2);
		$order->set_currency('DKK');
		$order->calculate_totals(false);
		$order->save();

		return wc_get_order($order->get_id());
	}
}
