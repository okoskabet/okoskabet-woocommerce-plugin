<?php

use okoskabet_woocommerce_plugin\Rest\OkoRest;

/**
 * The shop's catalogue, sent to Økoskabet, and price and stock asked live.
 *
 * Off until the shop ticks "Send product catalogue to Økoskabet". On, the
 * whole catalogue goes once a day in pages of a hundred, and a product goes
 * again when it or its stock changes — noted during the request and sent from
 * Action Scheduler afterwards, never inside the save or the checkout. Nothing
 * here leaves the building: Økoskabet is `pre_http_request`.
 */
class CatalogueTest extends \Codeception\TestCase\WPTestCase
{
	use ConfiguresMerchant;

	/** @var array<int,array{method:string,url:string,body:string}> */
	private array $requests = array();

	/** What Økoskabet answers: a status code, or null for no answer at all. */
	private ?int $answer = 200;

	public function setUp(): void
	{
		parent::setUp();
		$this->a_merchant_with_an_account();
		$this->settings(array('_catalogue_enabled' => 'on', '_webhook_enabled' => 'on'));
		delete_option(OKO_CATALOGUE_LOCK_OPTION);
		$this->nothing_noted();
		oko_unschedule_catalogue();

		add_filter('pre_http_request', array($this, 'okoskabet'), 1, 3);
	}

	public function tearDown(): void
	{
		$this->nothing_noted();
		oko_unschedule_catalogue();
		remove_all_filters('wp_doing_cron');
		$this->forget_the_merchant();
		delete_option(O_TEXTDOMAIN . '-settings');
		delete_option(OKO_CATALOGUE_LOCK_OPTION);
		parent::tearDown();
	}

	/** Økoskabet, as far as these tests talk to it. */
	public function okoskabet($preempt, $args, $url)
	{
		$this->requests[] = array(
			'method' => (string) ($args['method'] ?? 'GET'),
			'url'    => (string) $url,
			'body'   => (string) ($args['body'] ?? ''),
		);

		if ($this->answer === -1) {
			throw new \RuntimeException('A page that dies');
		}
		if ($this->answer === null) {
			return new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out');
		}

		return array('response' => array('code' => $this->answer), 'body' => '{"stored":1}', 'headers' => array(), 'cookies' => array());
	}

	// --- The setting -------------------------------------------------------

	public function test_off_nothing_is_noted_scheduled_or_sent(): void
	{
		$this->settings(array('_webhook_enabled' => 'on'));
		add_filter('wp_doing_cron', '__return_true');
		$product = $this->a_product('Gulerødder', '25');

		oko_schedule_catalogue();
		$this->assertFalse(as_has_scheduled_action(OKO_CATALOGUE_HOOK));

		wc_update_product_stock($product, 3);
		$this->assertSame(array(), oko_catalogue_noted());
		$this->assertFalse(has_action('shutdown', 'oko_catalogue_flush'));

		oko_start_catalogue();
		oko_send_catalogue_page(0);
		oko_send_catalogue_products(array($product->get_id()));

		$this->assertFalse(as_has_scheduled_action(OKO_CATALOGUE_PAGE_HOOK));
		$this->assertSame(array(), $this->catalogue_calls());
	}

	public function test_on_the_daily_run_is_scheduled_and_off_again_it_is_gone(): void
	{
		add_filter('wp_doing_cron', '__return_true');

		oko_schedule_catalogue();
		$this->assertNotFalse(as_has_scheduled_action(OKO_CATALOGUE_HOOK, array(), OKO_WEB_SALE_GROUP));

		as_enqueue_async_action(OKO_CATALOGUE_PRODUCTS_HOOK, array(array(1, 2)), OKO_WEB_SALE_GROUP);
		$this->settings(array());
		oko_schedule_catalogue();

		$this->assertFalse(as_has_scheduled_action(OKO_CATALOGUE_HOOK));
		$this->assertFalse(as_has_scheduled_action(OKO_CATALOGUE_PRODUCTS_HOOK));
	}

	/** The commission-sale fetch shares the group, and is not taken down with it. */
	public function test_turning_it_off_leaves_the_commission_sale_fetch_alone(): void
	{
		as_schedule_recurring_action(time() + 60, 300, OKO_WEB_SALE_HOOK, array(), OKO_WEB_SALE_GROUP);
		as_enqueue_async_action(OKO_CATALOGUE_PRODUCTS_HOOK, array(array(1)), OKO_WEB_SALE_GROUP);

		oko_unschedule_catalogue();

		$this->assertNotFalse(as_has_scheduled_action(OKO_WEB_SALE_HOOK));
		as_unschedule_all_actions(OKO_WEB_SALE_HOOK);
	}

	// --- One product -------------------------------------------------------

	public function test_a_simple_product_as_okoskabet_has_it(): void
	{
		$this->prices_with_vat(true);
		$category = wp_insert_term('Frugt & grønt', 'product_cat');
		$product  = $this->a_product('Gulerødder, 1 kg', '25');
		$product->set_manage_stock(true);
		$product->set_stock_quantity(40);
		$product->set_category_ids(array((int) $category['term_id'], (int) get_option('default_product_cat')));
		$product->save();

		$entry = $this->sent_for($product->get_id());

		$this->assertSame($product->get_id(), $entry['id']);
		$this->assertSame('Gulerødder, 1 kg', $entry['name']);
		$this->assertSame('25.00', $entry['price']);
		$this->assertSame(40, $entry['stock_quantity']);
		$this->assertSame('instock', $entry['stock_status']);
		$this->assertSame(get_permalink($product->get_id()), $entry['link']);
		$this->assertSame('', $entry['image']);
		// The shop's default category is left out, and the name is as the
		// customer reads it.
		$this->assertSame(array('Frugt & grønt'), $entry['categories']);
		$this->assertSame(oko_product_properties($product), $entry['properties']);
		$this->assertArrayNotHasKey('size', $entry);
	}

	public function test_a_sale_is_sent_as_the_sale_price(): void
	{
		$this->prices_with_vat(true);
		$product = $this->a_product('Ost', '60');
		$product->set_sale_price('45');
		$product->save();

		$this->assertSame('45.00', $this->sent_for($product->get_id())['price']);
	}

	public function test_a_shop_whose_prices_are_without_vat_sends_them_with_it(): void
	{
		$this->prices_with_vat(false);
		$product = $this->a_product('Ost', '40');

		$this->assertSame('50.00', $this->sent_for($product->get_id())['price']);
	}

	public function test_stock_the_shop_does_not_count_is_null_with_its_status(): void
	{
		$product = $this->a_product('Brød', '30');
		$product->set_manage_stock(false);
		$product->set_stock_status('onbackorder');
		$product->save();

		$entry = $this->sent_for($product->get_id());
		$this->assertArrayHasKey('stock_quantity', $entry);
		$this->assertNull($entry['stock_quantity']);
		$this->assertSame('onbackorder', $entry['stock_status']);
	}

	public function test_a_simple_product_without_a_price_is_sent_with_none(): void
	{
		$product = $this->a_product('Ukendt', '');

		$this->assertSame('', $this->sent_for($product->get_id())['price']);
	}

	public function test_hidden_password_protected_and_draft_products_are_not_for_sale(): void
	{
		$hidden = $this->a_product('Skjult', '10');
		$hidden->set_catalog_visibility('hidden');
		$hidden->save();

		$locked = $this->a_product('Med kode', '10');
		$locked->set_post_password('hemmelig');
		$locked->save();

		$draft = $this->a_product('Kladde', '10');
		$draft->set_status('draft');
		$draft->save();

		foreach (array($hidden, $locked, $draft) as $product) {
			$this->assertSame(array('id' => $product->get_id(), 'deleted' => true), $this->sent_for($product->get_id()));
		}
	}

	// --- Variable products -------------------------------------------------

	public function test_a_variable_product_is_its_variations_each_under_its_own_id(): void
	{
		$this->prices_with_vat(true);
		$category = wp_insert_term('Rodfrugter', 'product_cat');
		list($parent, $one_kg, $two_kg, $no_price) = $this->a_variable_product();
		$parent->set_category_ids(array((int) $category['term_id']));
		$parent->save();

		oko_send_catalogue_ids(array($parent->get_id()));
		$all  = $this->sent_products();
		$sent = array_values(array_filter($all, static fn(array $entry): bool => empty($entry['deleted'])));

		$this->assertSame(array($one_kg->get_id(), $two_kg->get_id()), array_column($sent, 'id'));
		$this->assertSame(wc_get_product($one_kg->get_id())->get_name(), $sent[0]['name']);
		$this->assertStringContainsString('1 kg', $sent[0]['name']);
		$this->assertSame('30.00', $sent[0]['price']);
		$this->assertSame('55.00', $sent[1]['price']);
		$this->assertSame(10, $sent[0]['stock_quantity']);
		$this->assertSame(array('Rodfrugter'), $sent[0]['categories']);
		$this->assertSame(array('1 kg'), $sent[0]['properties'][0]['values'] ?? null);
		// The parent is not sold itself — it may have been, as a simple
		// product, before it became variable — and a variation without a
		// price cannot be bought: both are said to be gone.
		$this->assertContains(array('id' => $parent->get_id(), 'deleted' => true), $all);
		$this->assertContains(array('id' => $no_price->get_id(), 'deleted' => true), $all);
	}

	/** A variation whose price is taken away stops being for sale at Økoskabet. */
	/**
	 * Saving a variable product notes it and its variations together, and
	 * the variations come by both roads. Each is sent once: Økoskabet refuses
	 * a call that names a product twice (seen 24/11 against a local backend).
	 */
	public function test_a_variable_product_saved_with_its_variations_sends_each_once(): void
	{
		[$parent, $one, $two, $priceless] = $this->a_variable_product();

		$this->requests = array();
		oko_send_catalogue_ids(array($one->get_id(), $parent->get_id(), $two->get_id()));

		$ids = $this->sent_ids();
		$this->assertCount(1, $this->catalogue_calls());
		$this->assertSame(array_values(array_unique($ids)), $ids);
		$this->assertEqualsCanonicalizing(
			array($one->get_id(), $parent->get_id(), $two->get_id(), $priceless->get_id()),
			$ids
		);
	}

	/**
	 * With three attributes or more WooCommerce leaves the choices out of a
	 * variation's name, and the variations would come to Økoskabet under one
	 * name. The choices are added.
	 */
	public function test_a_variation_is_named_by_its_choices_however_many_there_are(): void
	{
		$attributes = array();
		foreach (array('Størrelse' => array('1 kg', '2 kg'), 'Farve' => array('Orange', 'Lilla'), 'Dyrkning' => array('Øko')) as $label => $options) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name($label);
			$attribute->set_options($options);
			$attribute->set_visible(true);
			$attribute->set_variation(true);
			$attributes[] = $attribute;
		}

		$parent = new \WC_Product_Variable();
		$parent->set_name('Gulerødder');
		$parent->set_status('publish');
		$parent->set_attributes($attributes);
		$parent->save();

		$variation = new \WC_Product_Variation();
		$variation->set_parent_id($parent->get_id());
		$variation->set_attributes(array(
			sanitize_title('Størrelse') => '1 kg',
			sanitize_title('Farve')     => 'Lilla',
			sanitize_title('Dyrkning')  => 'Øko',
		));
		$variation->set_regular_price('30');
		$variation->set_status('publish');
		$variation->save();

		$this->assertSame('Gulerødder', wc_get_product($variation->get_id())->get_name());
		$this->assertSame('Gulerødder - 1 kg, Lilla, Øko', $this->sent_for($variation->get_id())['name']);
	}

	public function test_a_variation_that_loses_its_price_is_sent_as_gone(): void
	{
		list(, $one_kg) = $this->a_variable_product();
		$this->assertSame('30.00', $this->sent_for($one_kg->get_id())['price']);

		$one_kg->set_regular_price('');
		$one_kg->save();

		$this->assertSame(array('id' => $one_kg->get_id(), 'deleted' => true), $this->sent_for($one_kg->get_id()));
	}

	public function test_deleting_a_variable_product_sends_its_variations_as_gone(): void
	{
		list($parent, $one_kg, $two_kg, $no_price) = $this->a_variable_product();
		$ids = array($parent->get_id(), $one_kg->get_id(), $two_kg->get_id(), $no_price->get_id());
		$this->nothing_noted();

		$parent->delete(true);
		$this->assertFalse(wc_get_product($ids[1]));

		oko_catalogue_flush();
		oko_send_catalogue_products($this->pending_product_ids()[0]);

		$gone = array();
		foreach ($this->sent_products() as $entry) {
			$this->assertSame(array('id', 'deleted'), array_keys($entry));
			$gone[] = $entry['id'];
		}
		foreach ($ids as $id) {
			$this->assertContains($id, $gone);
		}
	}

	public function test_trashing_a_variable_product_sends_its_variations_as_gone(): void
	{
		list($parent, $one_kg, $two_kg) = $this->a_variable_product();
		$this->nothing_noted();

		wp_trash_post($parent->get_id());
		oko_catalogue_flush();
		oko_send_catalogue_products($this->pending_product_ids()[0]);

		$sent = $this->sent_products();
		$this->assertContains(array('id' => $one_kg->get_id(), 'deleted' => true), $sent);
		$this->assertContains(array('id' => $two_kg->get_id(), 'deleted' => true), $sent);
	}

	// --- Changes, noted and sent afterwards --------------------------------

	public function test_many_stock_changes_in_one_request_are_one_action_with_each_id_once(): void
	{
		$a = $this->a_product('A', '10');
		$b = $this->a_product('B', '10');
		foreach (array($a, $b) as $product) {
			$product->set_manage_stock(true);
			$product->set_stock_quantity(100);
			$product->save();
		}
		$this->nothing_noted();

		for ($i = 0; $i < 20; $i++) {
			wc_update_product_stock($a, 50 - $i);
			wc_update_product_stock($b, 50 - $i);
			wc_update_product_stock_status($a->get_id(), 'instock');
		}
		$a->set_name('A, ny');
		$a->save();

		// Nothing is sent while the request runs.
		$this->assertSame(array(), $this->catalogue_calls());
		$this->assertNotFalse(has_action('shutdown', 'oko_catalogue_flush'));

		oko_catalogue_flush();

		$this->assertSame(array(array($a->get_id(), $b->get_id())), $this->pending_product_ids());
		$this->assertSame(array(), oko_catalogue_noted());
	}

	public function test_a_product_unpublished_is_noted_and_sent_as_gone(): void
	{
		$product = $this->a_product('Ost', '10');
		$this->nothing_noted();

		wp_update_post(array('ID' => $product->get_id(), 'post_status' => 'draft'));
		oko_catalogue_flush();
		oko_send_catalogue_products($this->pending_product_ids()[0]);

		$this->assertSame(array(array('id' => $product->get_id(), 'deleted' => true)), $this->sent_products());
	}

	// --- A hundred at a time -----------------------------------------------

	public function test_the_whole_catalogue_goes_in_pages_of_a_hundred(): void
	{
		for ($i = 0; $i < 150; $i++) {
			$this->a_product('Vare ' . $i, '10');
		}
		$this->nothing_noted();

		oko_start_catalogue();
		$this->assertSame(array(array(0)), $this->pending_pages());
		// One chain at a time.
		oko_start_catalogue();
		$this->assertCount(1, $this->pending_pages());

		$this->as_scheduled_page();
		$this->assertCount(1, $this->catalogue_calls());
		$this->assertCount(100, $this->sent_products());
		$sent = $this->sent_ids();
		$this->assertSame(array(array(end($sent))), $this->pending_pages());

		$this->requests = array();
		$this->as_scheduled_page();
		$this->assertCount(50, $this->sent_products());
		$this->assertSame(array(), $this->pending_pages());
	}

	/**
	 * A product taken off sale while the chain runs moves no other: the next
	 * page starts after the last product sent, not at an offset.
	 */
	public function test_a_product_taken_off_halfway_through_skips_no_other(): void
	{
		$products = array();
		for ($i = 0; $i < 150; $i++) {
			$products[] = $this->a_product('Vare ' . $i, '10');
		}
		$this->nothing_noted();
		oko_start_catalogue();
		$this->as_scheduled_page();

		wp_update_post(array('ID' => $products[10]->get_id(), 'post_status' => 'draft'));
		$this->nothing_noted();
		$this->requests = array();
		$this->as_scheduled_page();

		$this->assertSame(
			array_map(static fn(\WC_Product $p): int => $p->get_id(), array_slice($products, 100)),
			$this->sent_ids()
		);
	}

	/**
	 * A page is a hundred entries, not a hundred products: a variable product
	 * with a hundred variations is a page of its own, or the action holding it
	 * runs out of time in the same place every day and the chain never ends.
	 */
	public function test_a_page_counts_the_variations_it_sends(): void
	{
		$before = $this->a_product('Før', '10');
		$parent = new \WC_Product_Variable();
		$parent->set_name('Mange størrelser');
		$parent->set_status('publish');
		$parent->save();
		for ($i = 0; $i < 105; $i++) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id($parent->get_id());
			$variation->set_regular_price('10');
			$variation->set_status('publish');
			$variation->save();
		}
		$after = $this->a_product('Efter', '10');
		$this->nothing_noted();

		oko_start_catalogue();
		$this->as_scheduled_page();

		// The simple product and the variable one with its 105 variations and
		// its own id as gone: 107 entries, in two calls.
		$this->assertCount(107, $this->sent_products());
		$this->assertSame(array(100, 7), array_map(static fn(array $call): int => count(json_decode($call['body'], true)['products']), $this->catalogue_calls()));
		$this->assertContains($before->get_id(), $this->sent_ids());
		$this->assertNotContains($after->get_id(), $this->sent_ids());
		$this->assertSame(array(array($parent->get_id())), $this->pending_pages());

		$this->requests = array();
		$this->as_scheduled_page();
		$this->assertSame(array($after->get_id()), $this->sent_ids());
		$this->assertSame(array(), $this->pending_pages());
	}

	/** A page that dies is only that page: the next one was scheduled before it was sent. */
	public function test_a_page_that_dies_leaves_the_chain_going(): void
	{
		$products = array();
		for ($i = 0; $i < 150; $i++) {
			$products[] = $this->a_product('Vare ' . $i, '10');
		}
		$this->nothing_noted();
		$this->answer = -1;

		try {
			oko_send_catalogue_page(0);
			$this->fail('The page was meant to die.');
		} catch (\RuntimeException $e) {
			$this->assertSame('A page that dies', $e->getMessage());
		}

		$this->assertSame(array(array($products[99]->get_id())), $this->pending_pages());
		$this->assertFalse(get_option(OKO_CATALOGUE_LOCK_OPTION));
	}

	/** A page after the last product ends the chain, and schedules nothing. */
	public function test_the_page_after_the_last_product_ends_the_chain(): void
	{
		$product = $this->a_product('Ost', '10');
		$this->nothing_noted();

		oko_send_catalogue_page($product->get_id());

		$this->assertSame(array(), $this->catalogue_calls());
		$this->assertSame(array(), $this->pending_pages());
	}

	/** Grouped and external products are not sold through Økoskabet, and the day's run passes them by. */
	public function test_the_daily_run_passes_grouped_and_external_products_by(): void
	{
		$external = new \WC_Product_External();
		$external->set_name('Hos en anden');
		$external->set_regular_price('10');
		$external->set_status('publish');
		$external->save();
		$simple = $this->a_product('Ost', '10');
		$this->nothing_noted();

		oko_start_catalogue();
		$this->as_scheduled_page();

		$this->assertSame(array($simple->get_id()), $this->sent_ids());
	}

	// --- Økoskabet not there -----------------------------------------------

	/**
	 * A list of changes Økoskabet did not take is tried again, later each
	 * time: it may say a product is gone, and the daily run never says so
	 * again. Given up after five tries.
	 */
	public function test_changes_okoskabet_could_not_take_are_tried_again_later(): void
	{
		$product = $this->a_product('Ost', '10');
		$this->nothing_noted();
		$this->answer = 503;

		$before = time();
		oko_send_catalogue_products(array($product->get_id()));

		$retries = $this->pending_retries();
		$this->assertSame(array(array(array($product->get_id()), 1)), array_column($retries, 'args'));
		$this->assertGreaterThanOrEqual($before + OKO_CATALOGUE_RETRY_SECONDS, $retries[0]['at']);

		as_unschedule_all_actions(OKO_CATALOGUE_PRODUCTS_HOOK);
		$this->answer = null; // No answer at all.
		oko_send_catalogue_products(array($product->get_id()), 3);
		$retries = $this->pending_retries();
		$this->assertSame(array(array(array($product->get_id()), 4)), array_column($retries, 'args'));
		$this->assertGreaterThanOrEqual($before + OKO_CATALOGUE_RETRY_SECONDS * 8, $retries[0]['at']);

		as_unschedule_all_actions(OKO_CATALOGUE_PRODUCTS_HOOK);
		oko_send_catalogue_products(array($product->get_id()), 4);
		$this->assertSame(array(), $this->pending_retries());
	}

	/** A refusal is not tried again: it would be refused again. */
	public function test_changes_okoskabet_refused_are_not_tried_again(): void
	{
		$product = $this->a_product('Ost', '10');
		$this->nothing_noted();

		foreach (array(200, 401, 404, 422) as $code) {
			$this->answer = $code;
			oko_send_catalogue_products(array($product->get_id()));
			$this->assertSame(array(), $this->pending_retries(), 'answered ' . $code);
		}
	}

	/** A page is left to the next day's run, which sends it again anyway. */
	public function test_a_page_okoskabet_could_not_take_is_not_tried_again(): void
	{
		$this->a_product('Ost', '10');
		$this->nothing_noted();
		$this->answer = 503;

		oko_send_catalogue_page(0);

		$this->assertSame(array(), $this->pending_retries());
		$this->assertSame(array(), $this->pending_pages());
	}

	public function test_no_call_carries_more_than_a_hundred(): void
	{
		$ids = array();
		for ($i = 0; $i < 230; $i++) {
			$ids[] = 900000 + $i; // Not here: each is sent as gone.
		}

		oko_send_catalogue_ids($ids);

		$sizes = array_map(static fn(array $call): int => count(json_decode($call['body'], true)['products']), $this->catalogue_calls());
		$this->assertSame(array(100, 100, 30), $sizes);
	}

	public function test_a_page_waits_while_another_is_being_sent(): void
	{
		$this->a_product('Vare', '10');
		oko_take_hold(OKO_CATALOGUE_LOCK_OPTION, time() . ':other', OKO_CATALOGUE_LOCK_SECONDS);

		oko_send_catalogue_page(0);

		$this->assertSame(array(), $this->catalogue_calls());
		$this->assertSame(array(array(0)), $this->pending_pages());
	}

	// --- The live lookup ---------------------------------------------------

	public function test_the_live_lookup_answers_price_and_stock_only(): void
	{
		$this->prices_with_vat(true);
		$product = $this->a_product('Gulerødder', '25');
		$product->set_manage_stock(true);
		$product->set_stock_quantity(40);
		$product->save();
		$hidden = $this->a_product('Skjult', '10');
		$hidden->set_catalog_visibility('hidden');
		$hidden->save();

		$response = $this->lookup(array($product->get_id(), $hidden->get_id(), 999999), array('stock'));

		$this->assertInstanceOf(\WP_REST_Response::class, $response);
		$this->assertSame(
			array(
				array('id' => $product->get_id(), 'price' => '25.00', 'stock_quantity' => 40, 'stock_status' => 'instock'),
				array('id' => $hidden->get_id(), 'deleted' => true),
			),
			$response->get_data()
		);
	}

	public function test_the_live_lookup_tells_nothing_while_the_catalogue_is_off(): void
	{
		$this->settings(array('_webhook_enabled' => 'on'));
		$product = $this->a_product('Gulerødder', '25');

		$response = $this->lookup(array($product->get_id()), array('stock'));

		$this->assertInstanceOf(\WP_Error::class, $response);
		$this->assertSame('catalogue_disabled', $response->get_error_code());
		$this->assertSame(403, $response->get_error_data()['status']);
	}

	/** An Økoskabet that does not send `fields` gets the answer it always had, setting on or off. */
	public function test_without_fields_the_lookup_answers_as_it_always_has(): void
	{
		$product = $this->a_product('Gulerødder', '25');
		$product->set_tag_ids(array((int) wp_insert_term('Køl', 'product_tag')['term_id']));
		$product->save();
		$product = wc_get_product($product->get_id());

		$expected = (string) wp_json_encode(array(array(
			'id'         => $product->get_id(),
			'name'       => 'Gulerødder',
			'image'      => oko_product_image_url($product),
			'tags'       => oko_product_tags($product),
			'properties' => oko_product_properties($product),
		)));

		foreach (array(array('_catalogue_enabled' => 'on', '_webhook_enabled' => 'on'), array('_webhook_enabled' => 'on')) as $settings) {
			$this->settings($settings);
			$this->assertSame($expected, wp_json_encode($this->lookup(array($product->get_id()))->get_data()));
			$this->assertSame($expected, wp_json_encode($this->lookup(array($product->get_id()), array('name'))->get_data()));
		}
	}

	/** A simple product turned external is no longer sold here: it is said to be gone. */
	public function test_a_product_turned_into_a_type_not_sent_is_sent_as_gone(): void
	{
		$product = $this->a_product('Ost', '10');
		$id      = $product->get_id();
		wp_set_object_terms($id, 'external', 'product_type');
		\WC_Cache_Helper::invalidate_cache_group('product_' . $id);
		clean_post_cache($id);
		$this->assertTrue(wc_get_product($id)->is_type('external'));

		$this->assertSame(array('id' => $id, 'deleted' => true), $this->sent_for($id));
	}

	/** The live lookup says a variation without a price is gone, as the catalogue does. */
	public function test_the_live_lookup_says_a_variation_without_a_price_is_gone(): void
	{
		list(, $one_kg, , $no_price) = $this->a_variable_product();

		$answer = $this->lookup(array($one_kg->get_id(), $no_price->get_id()), array('stock'))->get_data();

		$this->assertSame(array($one_kg->get_id(), $no_price->get_id()), array_column($answer, 'id'));
		$this->assertSame(array('id' => $no_price->get_id(), 'deleted' => true), $answer[1]);
	}

	// --- Where to ask live ------------------------------------------------

	/**
	 * Every catalogue POST says where Økoskabet asks this shop live — the
	 * whole catalogue, a change and a deletion alike — and says it exactly as
	 * an order for the same account does. Økoskabet otherwise learns the
	 * address only from an order, and a shop that sends only its catalogue
	 * would never be asked.
	 */
	public function test_every_catalogue_post_says_where_to_ask_and_as_an_order_does(): void
	{
		$product = $this->a_product('Ost', '10');
		$gone    = $this->a_product('Smør', '10');
		$this->nothing_noted();

		oko_send_catalogue_page(0);                         // The whole catalogue.
		oko_send_catalogue_products(array($product->get_id())); // A change.
		$gone_id = $gone->get_id();
		$gone->delete(true);
		oko_send_catalogue_products(array($gone_id));           // A deletion (the object's id is 0 by now).

		$order = wc_create_order();
		$order->add_product($product, 1);
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id('hey_okoskabet_shipping_shed');
		$shipping->set_method_title('Skab');
		$order->add_item($shipping);
		$order->update_meta_data('_billing_okoskabet_delivery_date', '2026-12-01');
		$order->update_meta_data('_billing_okoskabet_shed_id', '42');
		$order->save();
		$payload = oko_shipment_payload($order, o_get_merchant());
		$this->assertIsArray($payload);
		$expected = $payload['webshop_products_url'];
		$this->assertStringEndsWith('/wp/v2/okoskabet/products/' . rawurlencode((string) o_get_merchant()['id']), $expected);

		$calls = $this->catalogue_calls();
		$this->assertCount(3, $calls);
		foreach ($calls as $call) {
			$body = json_decode($call['body'], true);
			$this->assertSame(array('webshop_products_url', 'products'), array_keys($body));
			$this->assertSame($expected, $body['webshop_products_url']);
		}
		$this->assertSame(array(array('id' => $gone_id, 'deleted' => true)), json_decode($calls[2]['body'], true)['products']);
	}

	// --- Helpers -----------------------------------------------------------

	/** @param array<string,string> $settings */
	// --- Stock from Økoskabet --------------------------------------------

	public function test_okoskabet_sets_the_whole_number_and_woocommerce_the_status(): void
	{
		$product = $this->a_product('Gulerødder', '25');
		$this->assertFalse($product->get_manage_stock());

		$response = $this->set_stock(array(array('id' => $product->get_id(), 'stock_quantity' => 3)));

		$this->assertSame(200, $response->get_status());
		$this->assertSame(array('set' => array($product->get_id()), 'missing' => array(), 'stale' => array()), $response->get_data());
		$product = wc_get_product($product->get_id());
		$this->assertTrue($product->get_manage_stock());
		$this->assertSame(3, $product->get_stock_quantity());
		$this->assertSame('instock', $product->get_stock_status());

		$this->set_stock(array(array('id' => $product->get_id(), 'stock_quantity' => 0)), time() + 1);
		$product = wc_get_product($product->get_id());
		$this->assertSame(0, $product->get_stock_quantity());
		$this->assertSame('outofstock', $product->get_stock_status());
	}

	public function test_a_number_counted_before_the_last_one_is_stale_and_the_same_one_again_is_fine(): void
	{
		$product = $this->a_product('Gulerødder', '25');
		$now     = time();

		$this->set_stock(array(array('id' => $product->get_id(), 'stock_quantity' => 7)), $now);
		$late  = $this->set_stock(array(array('id' => $product->get_id(), 'stock_quantity' => 2)), $now - 10);
		$again = $this->set_stock(array(array('id' => $product->get_id(), 'stock_quantity' => 7)), $now);

		$this->assertSame(array('set' => array(), 'missing' => array(), 'stale' => array($product->get_id())), $late->get_data());
		$this->assertSame(array($product->get_id()), $again->get_data()['set']);
		$this->assertSame(7, wc_get_product($product->get_id())->get_stock_quantity());
	}

	public function test_what_the_catalogue_does_not_send_is_missing_and_ids_come_back_as_asked(): void
	{
		[$parent] = $this->a_variable_product();
		$product  = $this->a_product('Gulerødder', '25');
		$binned   = $this->a_product('Kasseret', '5');
		wp_trash_post($binned->get_id());

		$response = $this->set_stock(array(
			array('id' => 'ABC-7', 'stock_quantity' => 0),
			array('id' => 999999, 'stock_quantity' => 1),
			array('id' => $parent->get_id(), 'stock_quantity' => 1),
			array('id' => $binned->get_id(), 'stock_quantity' => 1),
			array('id' => (string) $product->get_id(), 'stock_quantity' => 4),
		));

		$this->assertSame(
			array(
				'set'     => array((string) $product->get_id()),
				'missing' => array('ABC-7', 999999, $parent->get_id(), $binned->get_id()),
				'stale'   => array(),
			),
			$response->get_data()
		);
		$this->assertSame(4, wc_get_product($product->get_id())->get_stock_quantity());
		$this->assertFalse(wc_get_product($parent->get_id())->get_manage_stock());
	}

	public function test_a_variation_gets_its_own_stock_even_where_its_parent_kept_it(): void
	{
		[$parent, $small, $large] = $this->a_variable_product();
		$parent->set_manage_stock(true);
		$parent->set_stock_quantity(50);
		$parent->save();
		$large->set_manage_stock(false);
		$large->save();
		$this->assertSame('parent', wc_get_product($large->get_id())->get_manage_stock());

		$this->set_stock(array(
			array('id' => $small->get_id(), 'stock_quantity' => 2),
			array('id' => $large->get_id(), 'stock_quantity' => 5),
		));

		$this->assertSame(2, wc_get_product($small->get_id())->get_stock_quantity());
		$large = wc_get_product($large->get_id());
		$this->assertTrue($large->get_manage_stock());
		$this->assertSame(5, $large->get_stock_quantity());
		$this->assertSame(50, wc_get_product($parent->get_id())->get_stock_quantity());
	}

	public function test_stock_set_by_okoskabet_is_sent_back_as_any_change_is(): void
	{
		$product = $this->a_product('Gulerødder', '25');
		$this->nothing_noted();

		$this->set_stock(array(array('id' => $product->get_id(), 'stock_quantity' => 3)));

		$this->assertContains($product->get_id(), array_map('intval', array_keys(oko_catalogue_noted())));
	}

	public function test_a_shop_that_does_not_send_its_catalogue_takes_no_stock(): void
	{
		$this->settings(array('_webhook_enabled' => 'on'));
		$product = $this->a_product('Gulerødder', '25');

		$response = $this->set_stock(array(array('id' => $product->get_id(), 'stock_quantity' => 3)));

		$this->assertInstanceOf(\WP_Error::class, $response);
		$this->assertSame('catalogue_disabled', $response->get_error_code());
		$this->assertSame(403, $response->get_error_data()['status']);
		$this->assertFalse(wc_get_product($product->get_id())->get_manage_stock());
	}

	public function test_a_wrong_signature_or_an_old_timestamp_sets_nothing(): void
	{
		$product = $this->a_product('Gulerødder', '25');
		$entries = array(array('id' => $product->get_id(), 'stock_quantity' => 3));

		$forged = $this->signed(array('set_stock' => $entries, 'timestamp' => time()), 'en anden hemmelighed');
		$old    = $this->set_stock($entries, time() - 301);

		foreach (array($forged, $old) as $response) {
			$this->assertInstanceOf(\WP_Error::class, $response);
			$this->assertSame(401, $response->get_error_data()['status']);
		}
		$this->assertFalse(wc_get_product($product->get_id())->get_manage_stock());
	}

	/** Never with a 400: Økoskabet reads that as a plugin that does not know `set_stock`. */
	public function test_a_malformed_call_is_refused_whole_and_sets_nothing(): void
	{
		$product = $this->a_product('Gulerødder', '25');
		$good    = array('id' => $product->get_id(), 'stock_quantity' => 3);
		$hundred_and_one = array_fill(0, 101, $good);

		$calls = array(
			'too_many_products' => array('set_stock' => $hundred_and_one),
			'ids_and_set_stock' => array('set_stock' => array($good), 'ids' => array($product->get_id())),
			'invalid_set_stock' => array('set_stock' => array($good, array('id' => $product->get_id(), 'stock_quantity' => -1))),
		);
		$calls[] = array('set_stock' => array($good, array('id' => $product->get_id(), 'stock_quantity' => 1.5)));
		$calls[] = array('set_stock' => array($good, array('stock_quantity' => 1)));
		$calls[] = array('set_stock' => array('id' => $product->get_id(), 'stock_quantity' => 1));

		foreach ($calls as $code => $body) {
			$response = $this->signed($body + array('timestamp' => time()));
			$this->assertInstanceOf(\WP_Error::class, $response);
			$this->assertSame(422, $response->get_error_data()['status']);
			if (is_string($code)) {
				$this->assertSame($code, $response->get_error_code());
			}
		}
		$this->assertFalse(wc_get_product($product->get_id())->get_manage_stock());
	}

	public function test_a_hundred_at_once_are_taken(): void
	{
		$product = $this->a_product('Gulerødder', '25');

		$response = $this->set_stock(array_fill(0, 100, array('id' => $product->get_id(), 'stock_quantity' => 9)));

		$this->assertCount(100, $response->get_data()['set']);
		$this->assertSame(9, wc_get_product($product->get_id())->get_stock_quantity());
	}

	/**
	 * Through WordPress's own router, which used to insist on `ids`.
	 *
	 * WPLoader boots WordPress past the point where the plugin registers its
	 * routes, so they are registered here, on a server of the test's own.
	 */
	public function test_the_route_takes_set_stock_without_ids_and_still_wants_ids_for_a_lookup(): void
	{
		global $wp_rest_server;
		$wp_rest_server = null;
		$routes = array(new OkoRest(), 'add_custom_routes');
		add_action('rest_api_init', $routes);

		$product = $this->a_product('Gulerødder', '25');
		$this->signed(array('ids' => array(), 'timestamp' => time())); // Gives the merchant its secret.

		$dispatch = static function (array $body): \WP_REST_Response {
			$raw     = (string) wp_json_encode($body);
			$request = new \WP_REST_Request('POST', '/wp/v2/okoskabet/products/default');
			$request->set_header('content-type', 'application/json');
			$request->set_header('x-hmac-sha256', hash_hmac('sha256', $raw, 'hemmelighed'));
			$request->set_body($raw);

			return rest_do_request($request);
		};

		$set = $dispatch(array('set_stock' => array(array('id' => $product->get_id(), 'stock_quantity' => 6)), 'timestamp' => time()));
		$this->assertSame(200, $set->get_status());
		$this->assertSame(array($product->get_id()), $set->get_data()['set']);

		$neither = $dispatch(array('timestamp' => time()));
		$this->assertSame(400, $neither->get_status());

		remove_action('rest_api_init', $routes);
		$wp_rest_server = null;
	}

	private function settings(array $settings): void
	{
		update_option(O_TEXTDOMAIN . '-settings', $settings);
	}

	private function nothing_noted(): void
	{
		$noted = &oko_catalogue_noted();
		$noted = array();
		remove_action('shutdown', 'oko_catalogue_flush');
	}

	private function prices_with_vat(bool $included): void
	{
		update_option('woocommerce_calc_taxes', 'yes');
		update_option('woocommerce_default_country', 'DK');
		update_option('woocommerce_prices_include_tax', $included ? 'yes' : 'no');
		\WC_Tax::_insert_tax_rate(array(
			'tax_rate_country'  => 'DK',
			'tax_rate'          => '25.0000',
			'tax_rate_name'     => 'Moms',
			'tax_rate_priority' => 1,
			'tax_rate_compound' => 0,
			'tax_rate_shipping' => 1,
			'tax_rate_order'    => 1,
			'tax_rate_class'    => '',
		));
		\WC_Cache_Helper::invalidate_cache_group('taxes');
	}

	private function a_product(string $name, string $price): \WC_Product_Simple
	{
		$product = new \WC_Product_Simple();
		$product->set_name($name);
		$product->set_regular_price($price);
		$product->set_status('publish');
		$product->save();

		return $product;
	}

	/**
	 * A variable product with sizes 1 kg and 2 kg, and a third variation
	 * without a price.
	 *
	 * @return array{0:\WC_Product_Variable,1:\WC_Product_Variation,2:\WC_Product_Variation,3:\WC_Product_Variation}
	 */
	private function a_variable_product(): array
	{
		$attribute = new \WC_Product_Attribute();
		$attribute->set_name('Størrelse');
		$attribute->set_options(array('1 kg', '2 kg', '5 kg'));
		$attribute->set_visible(true);
		$attribute->set_variation(true);

		$parent = new \WC_Product_Variable();
		$parent->set_name('Gulerødder');
		$parent->set_status('publish');
		$parent->set_attributes(array($attribute));
		$parent->save();

		$variations = array();
		foreach (array('1 kg' => '30', '2 kg' => '55', '5 kg' => '') as $size => $price) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id($parent->get_id());
			$variation->set_attributes(array(sanitize_title('Størrelse') => $size));
			$variation->set_regular_price($price);
			$variation->set_manage_stock(true);
			$variation->set_stock_quantity(10);
			$variation->set_status('publish');
			$variation->save();
			$variations[] = $variation;
		}

		return array(wc_get_product($parent->get_id()), $variations[0], $variations[1], $variations[2]);
	}

	/** @return array<string,mixed> What was sent for one product, sent on its own. */
	private function sent_for(int $id): array
	{
		$this->requests = array();
		oko_send_catalogue_ids(array($id));
		$sent = $this->sent_products();
		$this->assertCount(1, $sent);

		return $sent[0];
	}

	/** @return array<int,array{method:string,url:string,body:string}> */
	private function catalogue_calls(): array
	{
		return array_values(array_filter($this->requests, static fn(array $r): bool => $r['method'] === 'POST' && substr($r['url'], -strlen('/api/v1/products')) === '/api/v1/products'));
	}

	/** @return array<int,array<string,mixed>> Every product in every call, in order. */
	private function sent_products(): array
	{
		$products = array();
		foreach ($this->catalogue_calls() as $call) {
			$products = array_merge($products, json_decode($call['body'], true)['products']);
		}

		return $products;
	}

	/** Run the one pending page of the chain, as Action Scheduler would. */
	private function as_scheduled_page(): void
	{
		$pages = $this->pending_pages();
		$this->assertCount(1, $pages);
		as_unschedule_all_actions(OKO_CATALOGUE_PAGE_HOOK);
		oko_send_catalogue_page(...$pages[0]);
	}

	/** @return array<int,array<int,int>> The arguments of each pending page. */
	private function pending_pages(): array
	{
		$actions = as_get_scheduled_actions(array(
			'hook'     => OKO_CATALOGUE_PAGE_HOOK,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		));

		return array_values(array_map(static fn($action): array => $action->get_args(), $actions));
	}

	/** @return array<int,array{args:array,at:int}> The tries again at changes, pending. */
	private function pending_retries(): array
	{
		$actions = as_get_scheduled_actions(array(
			'hook'     => OKO_CATALOGUE_PRODUCTS_HOOK,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
		));

		$retries = array();
		foreach ($actions as $action) {
			if (count($action->get_args()) > 1) {
				$retries[] = array('args' => $action->get_args(), 'at' => (int) $action->get_schedule()->get_date()->getTimestamp());
			}
		}

		return $retries;
	}

	/** @return int[] The ids sent, in order, gone or not. */
	private function sent_ids(): array
	{
		return array_map(static fn(array $product): int => (int) $product['id'], $this->sent_products());
	}

	/** @return array<int,array<int,int>> The ids of each pending send of changed products. */
	private function pending_product_ids(): array
	{
		$actions = as_get_scheduled_actions(array(
			'hook'     => OKO_CATALOGUE_PRODUCTS_HOOK,
			'status'   => \ActionScheduler_Store::STATUS_PENDING,
			'per_page' => -1,
			'orderby'  => 'action_id',
			'order'    => 'ASC',
		));

		return array_values(array_map(static fn($action): array => $action->get_args()[0], $actions));
	}

	/**
	 * The lookup as Økoskabet calls it, signed with the merchant's secret.
	 *
	 * @param int[]         $ids
	 * @param string[]|null $fields
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function lookup(array $ids, ?array $fields = null)
	{
		$config = get_option(\okoskabet_woocommerce_plugin\Integrations\Merchants::OPTION_KEY);
		$config['merchants']['default']['webhook_secret'] = 'hemmelighed';
		\okoskabet_woocommerce_plugin\Integrations\Merchants::save_config($config);

		$body = array('ids' => $ids, 'timestamp' => time());
		if ($fields !== null) {
			$body['fields'] = $fields;
		}
		$raw = (string) wp_json_encode($body);

		$request = new \WP_REST_Request('POST', '/wp/v2/okoskabet/products/default');
		$request->set_url_params(array('merchant_id' => 'default'));
		$request->set_header('content-type', 'application/json');
		$request->set_header('x-hmac-sha256', hash_hmac('sha256', $raw, 'hemmelighed'));
		$request->set_body($raw);

		return (new OkoRest())->handle_products($request);
	}

	/**
	 * Stock from Økoskabet, signed as it sends it.
	 *
	 * @param array<int,array<string,mixed>> $entries
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function set_stock(array $entries, ?int $timestamp = null)
	{
		return $this->signed(array('set_stock' => $entries, 'timestamp' => $timestamp ?? time()));
	}

	/**
	 * Any body to the products route, signed with a secret — by default the merchant's.
	 *
	 * @param array<string,mixed> $body
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function signed(array $body, string $secret = 'hemmelighed')
	{
		$config = get_option(\okoskabet_woocommerce_plugin\Integrations\Merchants::OPTION_KEY);
		$config['merchants']['default']['webhook_secret'] = 'hemmelighed';
		\okoskabet_woocommerce_plugin\Integrations\Merchants::save_config($config);

		$raw     = (string) wp_json_encode($body);
		$request = new \WP_REST_Request('POST', '/wp/v2/okoskabet/products/default');
		$request->set_url_params(array('merchant_id' => 'default'));
		$request->set_header('content-type', 'application/json');
		$request->set_header('x-hmac-sha256', hash_hmac('sha256', $raw, $secret));
		$request->set_body($raw);

		return (new OkoRest())->handle_products($request);
	}
}
