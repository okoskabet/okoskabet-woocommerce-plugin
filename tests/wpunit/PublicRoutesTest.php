<?php

use okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions;
use okoskabet_woocommerce_plugin\Integrations\Merchants;
use okoskabet_woocommerce_plugin\Rest\OkoRest;

/**
 * The routes anyone can call say and pass on only what they are meant to.
 */
class PublicRoutesTest extends \Codeception\TestCase\WPTestCase
{
	/** @var array<int,string> */
	private $urls = array();

	public function setUp(): void
	{
		parent::setUp();
		$this->urls = array();

		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		Merchants::save_config(array(
			'default_merchant_id' => 'default',
			'merchants' => array(
				'default' => array('id' => 'default', 'label' => 'A', 'api_key' => 'key-a', 'webhook_secret' => 'secret-a', 'staging' => true),
				'anden'   => array('id' => 'anden', 'label' => 'B', 'api_key' => 'key-b', 'webhook_secret' => 'secret-b', 'staging' => true),
			),
		));
		$settings = (array) get_option(O_TEXTDOMAIN . '-settings', array());
		$settings['_webhook_enabled'] = 'on';
		update_option(O_TEXTDOMAIN . '-settings', $settings);

		add_filter('pre_http_request', function ($preempt, $args, $url) {
			$this->urls[] = $url;

			return array('response' => array('code' => 200), 'body' => wp_json_encode(array('delivery_dates' => array(), 'sheds' => array())));
		}, 1, 3);
	}

	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		parent::tearDown();
	}

	/** A postcode is one value on the call to Økoskabet, however it is written. */
	public function test_a_postcode_cannot_add_a_parameter_to_the_call_to_okoskabet(): void
	{
		$request = new \WP_REST_Request('GET', '/wp/v2/okoskabet/home_delivery');
		$request->set_param('zip', '8000&maximum_days_in_future=366');

		( new OkoRest() )->get_home_delivery($request);

		$this->assertNotEmpty($this->urls);
		parse_str((string) parse_url($this->urls[0], PHP_URL_QUERY), $query);
		$this->assertSame('8000&maximum_days_in_future=366', $query['postal_code'] ?? null);
		$this->assertNotSame('366', (string) ($query['maximum_days_in_future'] ?? ''));
	}

	/** A postcode sent as a list is no postcode, not "Array". */
	public function test_a_postcode_sent_as_a_list_is_not_read_as_text(): void
	{
		$request = new \WP_REST_Request('GET', '/wp/v2/okoskabet/home_delivery');
		$request->set_param('zip', array('8000'));

		( new OkoRest() )->get_home_delivery($request);

		$this->assertNotEmpty($this->urls);
		parse_str((string) parse_url($this->urls[0], PHP_URL_QUERY), $query);
		$this->assertSame('', $query['postal_code'] ?? null);
	}

	/** The webhook's merchant is the one in the URL, not one named in the query. */
	public function test_the_query_string_cannot_choose_the_merchant(): void
	{
		$raw = wp_json_encode(array('event' => 'reservation_created', 'shipment_reference' => '1'));

		$request = new \WP_REST_Request('POST', '/wp/v2/okoskabet/webhook/default');
		$request->set_url_params(array('merchant_id' => 'default'));
		$request->set_query_params(array('merchant_id' => 'anden'));
		$request->set_header('content-type', 'application/json');
		$request->set_header('x-hmac-sha256', strtoupper(hash_hmac('sha256', $raw, 'secret-b')));
		$request->set_body($raw);

		$result = ( new OkoRest() )->handle_webhook($request);

		$this->assertInstanceOf(\WP_Error::class, $result, "Signed with B's secret, sent to A's URL.");
		$this->assertSame('signature_invalid', $result->get_error_code());
	}

	/** A page that is not a product the shop sells is not read out by title. */
	public function test_only_published_products_are_named(): void
	{
		$draft = wp_insert_post(array('post_title' => 'Hemmelig kladde', 'post_status' => 'draft', 'post_type' => 'page'));

		$product = new \WC_Product_Simple();
		$product->set_name('Rugbrød');
		$product->set_status('publish');
		$product->save();

		$explained = Delivery_Exceptions::explanation_for_cart(array($draft, $product->get_id()));
		$names     = array_column($explained['product_rules'], 'product_name');

		$this->assertNotContains('Hemmelig kladde', $names);
		$this->assertContains('Rugbrød', $names);
	}
}
