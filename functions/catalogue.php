<?php

/**
 * The shop's catalogue, sent to Økoskabet.
 *
 * Members of Økoskabet's club buy goods across the shops' own webshops, and
 * what they can buy is what the shop's webshop sends: name, price with VAT,
 * stock, picture, link, categories and the visible attributes
 * (`POST /api/v1/products`, docs/klubben-katalogvarer.md in delivery_backend).
 * Marc, 24 November 2026: stock is fetched live from the plugin where it can
 * be, and what is sent here is the fallback, so it has to be fresh — the whole
 * catalogue once a day, and a product as soon as it or its stock changes.
 *
 * Off until the shop ticks "Send product catalogue to Økoskabet". Off, nothing
 * is sent, nothing is scheduled, and the live lookup tells neither price nor
 * stock.
 *
 * What a product id *is* — for sale, gone, a variable product standing for its
 * variations — is decided when it is sent, never when it is noted. A product
 * deleted before the queue runs is then simply not there, and is sent as
 * `deleted`; a product changed five times in one save is sent once, as it ended.
 *
 * @package okoskabet_woocommerce_plugin
 */

/** The setting, in the plugin's settings option. */
const OKO_CATALOGUE_SETTING = '_catalogue_enabled';

/** The daily run, which starts a chain of pages. */
const OKO_CATALOGUE_HOOK = 'okoskabet_send_catalogue';

/** One page of the whole catalogue. Argument: the page number. */
const OKO_CATALOGUE_PAGE_HOOK = 'okoskabet_send_catalogue_page';

/** Products that changed. Argument: their ids. */
const OKO_CATALOGUE_PRODUCTS_HOOK = 'okoskabet_send_catalogue_products';

/** Seconds between whole catalogues. */
const OKO_CATALOGUE_INTERVAL = DAY_IN_SECONDS;

/** Products per page of the catalogue, and per call to Økoskabet (whose limit is 500). */
const OKO_CATALOGUE_BATCH = 100;

/** Held while a page is sent, so two chains cannot run over each other. */
const OKO_CATALOGUE_LOCK_OPTION = '_oko_catalogue_lock';

/** What a page that died is given before its hold counts as abandoned. */
const OKO_CATALOGUE_LOCK_SECONDS = 600;

/** Most category names per product; Økoskabet cuts off beyond it. */
const OKO_CATALOGUE_MAX_CATEGORIES = 20;

add_action('action_scheduler_init', 'oko_schedule_catalogue');
add_action(OKO_CATALOGUE_HOOK, 'oko_start_catalogue');
add_action(OKO_CATALOGUE_PAGE_HOOK, 'oko_send_catalogue_page');
add_action(OKO_CATALOGUE_PRODUCTS_HOOK, 'oko_send_catalogue_products');

// A product, or its stock, changed.
add_action('woocommerce_new_product', 'oko_catalogue_note_product');
add_action('woocommerce_update_product', 'oko_catalogue_note_product');
add_action('woocommerce_new_product_variation', 'oko_catalogue_note_product');
add_action('woocommerce_update_product_variation', 'oko_catalogue_note_product');
add_action('woocommerce_product_set_stock', 'oko_catalogue_note_product');
add_action('woocommerce_variation_set_stock', 'oko_catalogue_note_product');
add_action('woocommerce_product_set_stock_status', 'oko_catalogue_note_product');
add_action('woocommerce_variation_set_stock_status', 'oko_catalogue_note_product');

// A product going away: its variations are noted while they can still be found.
add_action('wp_trash_post', 'oko_catalogue_note_post_going');
add_action('before_delete_post', 'oko_catalogue_note_post_going');
add_action('woocommerce_before_delete_product_variation', 'oko_catalogue_note_product');
add_action('transition_post_status', 'oko_catalogue_note_status_change', 10, 3);

/** Whether the shop has ticked "Send product catalogue to Økoskabet". */
function oko_catalogue_enabled(): bool
{
	$settings = o_get_settings();

	return ! empty($settings[OKO_CATALOGUE_SETTING]);
}

/**
 * Keep the daily run scheduled while the setting is on, and nothing at all
 * while it is off.
 *
 * Asked where the commission-sale fetch is asked (oko_schedule_web_sale_orders),
 * for the same reasons: a shop that clears its scheduled actions would
 * otherwise stop sending without a word, and not on the shop's own pages.
 */
function oko_schedule_catalogue(): void
{
	if (! is_admin() && ! wp_doing_cron()) {
		return;
	}

	if (! function_exists('as_has_scheduled_action') || ! function_exists('as_unschedule_all_actions')) {
		return;
	}

	if (! oko_catalogue_enabled() || oko_web_sale_merchants() === array()) {
		oko_unschedule_catalogue();
		return;
	}

	if (! as_has_scheduled_action(OKO_CATALOGUE_HOOK, array(), OKO_WEB_SALE_GROUP)) {
		// A minute from now, so a shop that has just ticked the box sends its
		// catalogue straight away rather than tomorrow.
		as_schedule_recurring_action(time() + 60, OKO_CATALOGUE_INTERVAL, OKO_CATALOGUE_HOOK, array(), OKO_WEB_SALE_GROUP, true);
	}
}

/** Everything the catalogue has scheduled, gone. */
function oko_unschedule_catalogue(): void
{
	if (! function_exists('as_unschedule_all_actions')) {
		return;
	}

	// By hook alone: with a group and no arguments Action Scheduler cancels
	// only actions that have no arguments, and a page or a list of ids has
	// them. The hooks are this plugin's own.
	foreach (array(OKO_CATALOGUE_HOOK, OKO_CATALOGUE_PAGE_HOOK, OKO_CATALOGUE_PRODUCTS_HOOK) as $hook) {
		if (! function_exists('as_has_scheduled_action') || as_has_scheduled_action($hook)) {
			as_unschedule_all_actions($hook);
		}
	}
}

/**
 * The daily run: the first page, unless a chain is already on its way.
 *
 * A catalogue too big to send in a day would otherwise have a second chain
 * started behind the first, every day, each sending everything.
 */
function oko_start_catalogue(): void
{
	if (! oko_catalogue_enabled()) {
		return;
	}

	if (as_has_scheduled_action(OKO_CATALOGUE_PAGE_HOOK)) {
		return;
	}

	as_enqueue_async_action(OKO_CATALOGUE_PAGE_HOOK, array(1), OKO_WEB_SALE_GROUP);
}

/**
 * One page of the whole catalogue: a hundred products, sent, and the next page
 * scheduled while there is one.
 *
 * Each page is an action of its own, so a catalogue of thousands is never one
 * request that runs out of time halfway, and a page that fails is only that page.
 *
 * @param int|string $page As Action Scheduler hands it back from its arguments.
 */
function oko_send_catalogue_page($page = 1): void
{
	if (! oko_catalogue_enabled()) {
		return;
	}

	$page = max(1, (int) $page);

	$hold = time() . ':' . wp_generate_uuid4();
	if (! oko_take_hold(OKO_CATALOGUE_LOCK_OPTION, $hold, OKO_CATALOGUE_LOCK_SECONDS)) {
		// Another page is being sent. This one waits its turn.
		as_schedule_single_action(time() + 60, OKO_CATALOGUE_PAGE_HOOK, array($page), OKO_WEB_SALE_GROUP);
		return;
	}

	try {
		$ids = wc_get_products(array(
			'status'  => 'publish',
			'type'    => array('simple', 'variable'),
			'limit'   => OKO_CATALOGUE_BATCH,
			'page'    => $page,
			'orderby' => 'ID',
			'order'   => 'ASC',
			'return'  => 'ids',
		));
		$ids = array_map('intval', (array) $ids);

		if ($ids !== array()) {
			oko_send_catalogue_ids($ids);
		}

		// A full page means there may be more. An empty next page ends it.
		if (count($ids) === OKO_CATALOGUE_BATCH) {
			as_enqueue_async_action(OKO_CATALOGUE_PAGE_HOOK, array($page + 1), OKO_WEB_SALE_GROUP);
		}
	} finally {
		oko_let_go_of_hold(OKO_CATALOGUE_LOCK_OPTION, $hold);
	}
}

/**
 * The products noted as changed, sent. Scheduled by oko_catalogue_flush().
 *
 * @param mixed $ids
 */
function oko_send_catalogue_products($ids = array()): void
{
	if (! oko_catalogue_enabled()) {
		return;
	}

	$ids = array_values(array_filter(array_map('intval', (array) $ids), static fn(int $id): bool => $id > 0));
	if ($ids !== array()) {
		oko_send_catalogue_ids($ids);
	}
}

/**
 * Products by id, as Økoskabet is to have them, to each account they belong to.
 *
 * @param int[] $ids
 */
function oko_send_catalogue_ids(array $ids): void
{
	$merchants = oko_web_sale_merchants();
	if ($merchants === array()) {
		return;
	}

	$by_account = array();
	foreach (array_keys($merchants) as $index) {
		$by_account[$index] = array();
	}

	foreach (array_unique($ids) as $id) {
		foreach (oko_catalogue_entries_for($id) as $entry) {
			if (! empty($entry['entry']['deleted'])) {
				// Gone is said to every account: what the product was filed
				// under, and so whose it was, may be gone with it.
				foreach (array_keys($merchants) as $index) {
					$by_account[$index][] = $entry['entry'];
				}
				continue;
			}

			$index = oko_catalogue_account_index($entry['product'], $merchants);
			if ($index !== null) {
				$by_account[$index][] = $entry['entry'];
			}
		}
	}

	foreach ($by_account as $index => $entries) {
		foreach (array_chunk($entries, OKO_CATALOGUE_BATCH) as $chunk) {
			oko_post_catalogue($merchants[$index], $chunk);
		}
	}
}

/**
 * What one id is sent as: itself, its variations, or that it is gone.
 *
 * @return array<int,array{product:?\WC_Product,entry:array<string,mixed>}>
 */
function oko_catalogue_entries_for(int $id): array
{
	$product = wc_get_product($id);

	if (! $product instanceof \WC_Product) {
		return array(array('product' => null, 'entry' => oko_catalogue_gone($id)));
	}

	if ($product->is_type('variable')) {
		// The variable product is not sold itself; its variations are, each
		// under its own id. All of them, published or not, so one switched
		// off is said to be gone. And the product's own id is said to be gone
		// too: it may have been sold as a simple product until it became
		// variable, and Økoskabet ignores `deleted` for an id it never had.
		$entries = array(array('product' => $product, 'entry' => oko_catalogue_gone($id)));
		foreach (oko_catalogue_variation_ids($id) as $child_id) {
			$child     = wc_get_product((int) $child_id);
			$entries[] = $child instanceof \WC_Product
				? array('product' => $child, 'entry' => oko_catalogue_entry($child))
				: array('product' => null, 'entry' => oko_catalogue_gone((int) $child_id));
		}
		return $entries;
	}

	if (! $product->is_type(array('simple', 'variation'))) {
		// Grouped, external and the like are not sent — but one that was
		// simple until a moment ago is still for sale at Økoskabet, and only
		// `deleted` takes it off.
		return array(array('product' => $product, 'entry' => oko_catalogue_gone($id)));
	}

	return array(array('product' => $product, 'entry' => oko_catalogue_entry($product)));
}

/**
 * One product as Økoskabet is to have it, or as gone.
 *
 * @return array<string,mixed>
 */
function oko_catalogue_entry(\WC_Product $product): array
{
	$price = oko_catalogue_selling_price($product);
	if ($price === null) {
		return oko_catalogue_gone((int) $product->get_id());
	}

	return array(
		'id'             => (int) $product->get_id(),
		'name'           => oko_plain_text((string) $product->get_name()),
		'price'          => $price,
		'stock_quantity' => oko_catalogue_stock_quantity($product),
		'stock_status'   => (string) $product->get_stock_status(),
		'image'          => oko_product_image_url($product),
		'link'           => (string) $product->get_permalink(),
		'categories'     => oko_catalogue_categories($product),
		'properties'     => oko_product_properties($product),
	);
}

/**
 * The price a product is sold at, or null where it is not for sale.
 *
 * One rule for the catalogue and the live lookup alike. A variation without a
 * price cannot be put in the basket, so it is not for sale either — and is
 * said to be gone rather than left out: one that had a price yesterday would
 * otherwise stay for sale at Økoskabet at that price, since only `deleted`
 * takes a product off.
 */
function oko_catalogue_selling_price(\WC_Product $product): ?string
{
	if (! oko_catalogue_for_sale($product)) {
		return null;
	}

	$price = oko_catalogue_price($product);

	return $price === '' && $product->is_type('variation') ? null : $price;
}

/** @return array{id:int,deleted:true} */
function oko_catalogue_gone(int $id): array
{
	return array('id' => $id, 'deleted' => true);
}

/**
 * Whether the shop sells this to anybody who comes by.
 *
 * Published, not hidden from the shop, and without a password; for a
 * variation, its parent as well. Marc, 24 November 2026: hidden and
 * password-protected goods are not for sale.
 */
function oko_catalogue_for_sale(\WC_Product $product): bool
{
	if ($product->get_status() !== 'publish') {
		return false;
	}

	if ($product->is_type('variation')) {
		$parent = wc_get_product($product->get_parent_id());

		return $parent instanceof \WC_Product && oko_catalogue_for_sale($parent);
	}

	if ($product->get_catalog_visibility() === 'hidden') {
		return false;
	}

	return (string) $product->get_post_password() === '';
}

/**
 * What a guest pays now, with VAT, as "49.00"; an empty string for no price.
 *
 * `get_price()` is the sale price while a sale runs. The VAT is the shop's own,
 * at its own address, whoever is asking. Not `wc_get_price_including_tax()`:
 * that reckons at the current customer's address, and under cron there is no
 * customer, so a shop that keeps its prices without VAT, with tax worked out
 * by the customer's address (WooCommerce's default), sent them without any.
 */
function oko_catalogue_price(\WC_Product $product): string
{
	if ((string) $product->get_price() === '') {
		return '';
	}

	$price = (float) $product->get_price();
	if (wc_tax_enabled() && $product->is_taxable() && ! wc_prices_include_tax()) {
		$taxes  = \WC_Tax::calc_tax($price, \WC_Tax::get_base_tax_rates($product->get_tax_class('unfiltered')), false);
		$price += array_sum($taxes);
	}

	return number_format(round($price, 2), 2, '.', '');
}

/** The count on the shelf, or null where the shop does not count this one. */
function oko_catalogue_stock_quantity(\WC_Product $product): ?int
{
	// managing_stock() is true for a variation whose parent counts for it,
	// and get_stock_quantity() then answers the parent's count.
	if (! $product->managing_stock()) {
		return null;
	}

	$quantity = $product->get_stock_quantity();

	return $quantity === null ? null : (int) $quantity;
}

/**
 * The product's own categories by name, without the shop's default one.
 *
 * A variation has none of its own; WooCommerce files the parent.
 *
 * @return string[]
 */
function oko_catalogue_categories(\WC_Product $product): array
{
	$id      = $product->is_type('variation') ? (int) $product->get_parent_id() : (int) $product->get_id();
	$default = (int) get_option('default_product_cat', 0);

	$terms = wp_get_post_terms($id, 'product_cat');
	if (is_wp_error($terms)) {
		return array();
	}

	$names = array();
	foreach ((array) $terms as $term) {
		if (! $term instanceof \WP_Term || (int) $term->term_id === $default) {
			continue;
		}
		$name = oko_plain_text((string) $term->name);
		if ($name !== '') {
			$names[] = $name;
		}
	}

	return array_slice(array_values(array_unique($names)), 0, OKO_CATALOGUE_MAX_CATEGORIES);
}

/**
 * Which of the accounts a product is sent to.
 *
 * One account: that one. Several: the one the shop routes the product to at
 * checkout, by the parent for a variation, which is where the shop files it.
 *
 * @param array<int,array<string,mixed>> $merchants As oko_web_sale_merchants() has them.
 */
function oko_catalogue_account_index(?\WC_Product $product, array $merchants): ?int
{
	if (count($merchants) === 1) {
		return (int) array_key_first($merchants);
	}

	if (! $product instanceof \WC_Product || ! class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchant_Router')) {
		return null;
	}

	$owner_id = $product->is_type('variation') ? (int) $product->get_parent_id() : (int) $product->get_id();
	$owner    = \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::resolve_for_product($owner_id)['merchant'] ?? null;
	if (! is_array($owner)) {
		return null;
	}

	// The account by its key, as the list is: two records on one key are one
	// account at Økoskabet.
	$key = o_merchant_api_url($owner) . ' ' . (string) ($owner['api_key'] ?? '');
	foreach ($merchants as $index => $merchant) {
		if (o_merchant_api_url($merchant) . ' ' . (string) ($merchant['api_key'] ?? '') === $key) {
			return (int) $index;
		}
	}

	return null;
}

/**
 * One call to Økoskabet with up to a hundred products.
 *
 * A failure is logged and left: the next whole catalogue sends it again, and
 * Økoskabet counts stock older than 48 hours as unconfirmed meanwhile.
 *
 * @param array<string,mixed>            $merchant
 * @param array<int,array<string,mixed>> $entries
 */
function oko_post_catalogue(array $merchant, array $entries): void
{
	if ($entries === array()) {
		return;
	}

	$merchant_id = (string) ($merchant['id'] ?? '?');
	$response    = oko_api_request(
		$merchant,
		'POST',
		'/api/v1/products',
		(string) wp_json_encode(
			array(
				// Where to ask live; the order carries the same, see there.
				'webshop_products_url' => oko_webshop_products_url($merchant),
				'products'             => array_values($entries),
			)
		),
		30
	);

	if (is_wp_error($response)) {
		error_log('okoskabet_woocommerce_plugin: Could not send the catalogue for merchant=' . $merchant_id . ': ' . $response->get_error_message());
		return;
	}

	$code = (int) wp_remote_retrieve_response_code($response);
	// 404 is an Økoskabet without the endpoint; said once a day per page it
	// would bury everything else in the log.
	if ($code !== 200 && $code !== 404) {
		error_log('okoskabet_woocommerce_plugin: Sending the catalogue for merchant=' . $merchant_id . ' answered ' . $code);
	}
}

/**
 * The products noted in this request, by id, sent once at its end.
 *
 * @return array<int,int>
 */
function &oko_catalogue_noted(): array
{
	static $noted = array();

	return $noted;
}

/**
 * Note a product to be sent when this request is done.
 *
 * Never sent here: this runs inside a save or inside the checkout, and a call
 * to Økoskabet there is the customer's wait. Saving a product fires several of
 * the hooks this listens on, and an order of twenty lines fires twenty stock
 * changes; noted by id, each is sent once.
 *
 * @param mixed $product An id or a product, as the hooks give it.
 */
function oko_catalogue_note_product($product): void
{
	if (! oko_catalogue_enabled()) {
		return;
	}

	$id = $product instanceof \WC_Product ? (int) $product->get_id() : (int) $product;
	if ($id <= 0) {
		return;
	}

	$noted = &oko_catalogue_noted();
	if ($noted === array() && ! has_action('shutdown', 'oko_catalogue_flush')) {
		add_action('shutdown', 'oko_catalogue_flush');
	}
	$noted[$id] = $id;
}

/**
 * A product going to the trash or deleted for good: it, and its variations,
 * while they can still be listed.
 *
 * @param int|string $post_id
 */
function oko_catalogue_note_post_going($post_id): void
{
	if (! oko_catalogue_enabled()) {
		return;
	}

	$post_id = (int) $post_id;
	$type    = get_post_type($post_id);
	if ($type !== 'product' && $type !== 'product_variation') {
		return;
	}

	oko_catalogue_note_product($post_id);

	if ($type === 'product') {
		foreach (oko_catalogue_variation_ids($post_id) as $child_id) {
			oko_catalogue_note_product($child_id);
		}
	}
}

/**
 * A product published, or no longer published.
 *
 * @param string   $new
 * @param string   $old
 * @param \WP_Post $post
 */
function oko_catalogue_note_status_change($new, $old, $post): void
{
	if ($new === $old || ($new !== 'publish' && $old !== 'publish')) {
		return;
	}
	if (! $post instanceof \WP_Post || ! in_array($post->post_type, array('product', 'product_variation'), true)) {
		return;
	}

	oko_catalogue_note_post_going($post->ID);
}

/**
 * Every variation of a product, whatever its status. Asked of the database,
 * not of WooCommerce, which lists only the ones it will show.
 *
 * @return int[]
 */
function oko_catalogue_variation_ids(int $parent_id): array
{
	return array_map('intval', get_posts(array(
		'post_parent' => $parent_id,
		'post_type'   => 'product_variation',
		'post_status' => 'any',
		'numberposts' => -1,
		'fields'      => 'ids',
	)));
}

/**
 * What was noted in this request, handed to Action Scheduler: one action for
 * every hundred ids, run in a request of its own.
 */
function oko_catalogue_flush(): void
{
	$noted = &oko_catalogue_noted();
	$ids   = array_values($noted);
	$noted = array();

	if ($ids === array() || ! oko_catalogue_enabled() || ! function_exists('as_enqueue_async_action')) {
		return;
	}

	sort($ids);
	foreach (array_chunk($ids, OKO_CATALOGUE_BATCH) as $chunk) {
		as_enqueue_async_action(OKO_CATALOGUE_PRODUCTS_HOOK, array($chunk), OKO_WEB_SALE_GROUP);
	}
}

/**
 * Price and stock of up to a hundred products, for the live lookup.
 *
 * A product that is not here is left out, as the lookup always has; one that
 * is here but not for sale is said to be gone; a variable product is not sold
 * itself and is left out.
 *
 * @param int[] $ids
 * @return array<int,array<string,mixed>>
 */
function oko_catalogue_stock_answer(array $ids): array
{
	$answer = array();
	foreach ($ids as $id) {
		$product = wc_get_product((int) $id);
		if (! $product instanceof \WC_Product || ! $product->is_type(array('simple', 'variation'))) {
			continue;
		}

		$price = oko_catalogue_selling_price($product);
		if ($price === null) {
			$answer[] = oko_catalogue_gone((int) $id);
			continue;
		}

		$answer[] = array(
			'id'             => (int) $id,
			'price'          => $price,
			'stock_quantity' => oko_catalogue_stock_quantity($product),
			'stock_status'   => (string) $product->get_stock_status(),
		);
	}

	return $answer;
}
