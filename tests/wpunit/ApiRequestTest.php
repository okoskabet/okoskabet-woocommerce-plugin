<?php

/**
 * The one place that talks to a merchant's Økoskabet.
 *
 * Every call carried its own copy of the key, the timeout and the content
 * type. They now go through one function, which is a thing worth pinning down:
 * a header that quietly stops being sent is an order that quietly stops
 * arriving, and nothing in the shop says so.
 */
class ApiRequestTest extends \Codeception\TestCase\WPTestCase
{
	/** @var array<string,mixed>|null */
	private $sent = null;

	/** @var string */
	private $url = '';

	public function setUp(): void
	{
		parent::setUp();

		$sent = &$this->sent;
		$url  = &$this->url;

		add_filter('pre_http_request', static function ($preempt, $args, $requested) use (&$sent, &$url) {
			$sent = $args;
			$url  = $requested;

			return array('response' => array('code' => 200), 'body' => '{}');
		}, 10, 3);
	}

	public function tearDown(): void
	{
		remove_all_filters('pre_http_request');
		parent::tearDown();
	}

	/** The account's key goes on every call, whichever one it is. */
	public function test_the_key_is_sent(): void
	{
		oko_api_request($this->merchant(), 'GET', '/api/v1/configuration');

		$this->assertSame('test-key', $this->sent['headers']['Authorization']);
		$this->assertSame('GET', $this->sent['method']);
	}

	/** The path is hung off the account's own API root, not a fixed one. */
	public function test_the_path_is_under_the_accounts_api_root(): void
	{
		$merchant = $this->merchant();

		oko_api_request($merchant, 'GET', '/api/v1/shipments/87893');

		$this->assertSame(o_merchant_api_url($merchant) . '/api/v1/shipments/87893', $this->url);
	}

	/** A body is sent as JSON, and says so. */
	public function test_a_body_is_sent_as_json(): void
	{
		oko_api_request($this->merchant(), 'PUT', '/api/v1/shipments/87893', '{"line_items":[]}');

		$this->assertSame('PUT', $this->sent['method']);
		$this->assertSame('application/json', $this->sent['headers']['Content-Type']);
		$this->assertSame('{"line_items":[]}', $this->sent['body']);
	}

	/**
	 * And a call with no body does not claim to have one.
	 *
	 * `Content-Type: application/json` on a request carrying nothing is zero
	 * bytes announced as JSON. A strict parser at the other end is entitled to
	 * answer 400 to that, and a cancellation would stop going through.
	 */
	public function test_a_call_with_no_body_does_not_claim_one(): void
	{
		oko_api_request($this->merchant(), 'DELETE', '/api/v1/shipments/87893');

		$this->assertSame('DELETE', $this->sent['method']);
		$this->assertArrayNotHasKey('Content-Type', $this->sent['headers']);
		// WordPress fills the slot in itself before any of this is seen, so
		// what is asked is that nothing was put in it.
		$this->assertEmpty($this->sent['body'] ?? null);
	}

	/**
	 * The wait is the caller's to set.
	 *
	 * The lookups the checkout makes while the customer types wait ten
	 * seconds; sending an order waits fifteen, because that one cannot simply
	 * be dropped. They were two numbers written out at seven call sites.
	 */
	public function test_the_caller_sets_the_wait(): void
	{
		oko_api_request($this->merchant(), 'GET', '/api/v1/configuration', null, 10);
		$this->assertSame(10, $this->sent['timeout']);

		oko_api_request($this->merchant(), 'POST', '/api/v1/shipments/', '{}');
		$this->assertSame(15, $this->sent['timeout'], 'Fifteen seconds when the caller says nothing.');
	}

	/** @return array<string,mixed> */
	private function merchant(): array
	{
		return array('id' => 'default', 'api_key' => 'test-key', 'staging' => false);
	}
}
