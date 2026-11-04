<?php

/**
 * Commission sales from okoskabet.dk, taken into the shop as its own orders.
 *
 * A customer buys a shop's goods on okoskabet.dk and pays Foodshipper. Each
 * shop's part is booked with Økoskabet as a shipment under `web-<id>`, and
 * until now the shop heard of it by mail and had to take the goods off its own
 * stock by hand. Marc, 4 November 2026: the order is to be in the shop's own
 * webshop, where it is packed and where the stock is.
 *
 * The plugin fetches them (Marc's choice: pull, with the key the shop already
 * has, rather than Økoskabet calling into WordPress). Every five minutes, per
 * account: `GET /api/v1/web_sale_orders` lists what has not been taken in
 * yet, each is made an order, and `POST .../{reference}/created` tells
 * Økoskabet which order it became.
 *
 * The shipment is Økoskabet's already, so the plugin's own booking never runs
 * on one of these: the order is marked booked under its reference before it is
 * paid. A cancellation goes the usual way and deletes `web-<id>`, which is how
 * Økoskabet hears to release the money and put the goods back on the shelf.
 *
 * @package okoskabet_woocommerce_plugin
 */

/** The Action Scheduler hook the fetch runs on. */
const OKO_WEB_SALE_HOOK = 'okoskabet_fetch_web_sale_orders';

/** The Action Scheduler group, so the shop can find it under Tools → Scheduled Actions. */
const OKO_WEB_SALE_GROUP = 'okoskabet';

/** Seconds between fetches. */
const OKO_WEB_SALE_INTERVAL = 300;

/** The reference a commission sale came under, on the order made from it. */
const OKO_WEB_SALE_REFERENCE_META = '_okoskabet_web_sale_reference';

/** Set when Økoskabet has the reference reported as another order (409). */
const OKO_WEB_SALE_CONFLICT_META = '_okoskabet_web_sale_conflict';

/** Held while a fetch runs, so two runs cannot each make the same order. */
const OKO_WEB_SALE_LOCK_OPTION = '_oko_web_sale_lock';

/** What a run that died is given before its hold counts as abandoned. */
const OKO_WEB_SALE_LOCK_SECONDS = 600;

/** The payment method on these orders. No gateway answers to it, and none should. */
const OKO_WEB_SALE_PAYMENT_METHOD = 'okoskabet_web_sale';

add_action('action_scheduler_init', 'oko_schedule_web_sale_orders');
add_action(OKO_WEB_SALE_HOOK, 'oko_fetch_web_sale_orders');
add_action('woocommerce_email', 'oko_web_sale_keeps_customer_emails_back');

/**
 * Keep the fetch scheduled while there is an account to fetch for.
 *
 * Asked on every load, which is what Action Scheduler asks of a recurring
 * action: a shop that clears its scheduled actions, or restores a database
 * without them, would otherwise stop taking orders in without a word.
 */
function oko_schedule_web_sale_orders(): void
{
	if (! function_exists('as_has_scheduled_action') || ! function_exists('as_schedule_recurring_action')) {
		return;
	}

	$scheduled = as_has_scheduled_action(OKO_WEB_SALE_HOOK, array(), OKO_WEB_SALE_GROUP);

	if (oko_web_sale_merchants() === array()) {
		if ($scheduled && function_exists('as_unschedule_all_actions')) {
			as_unschedule_all_actions(OKO_WEB_SALE_HOOK, array(), OKO_WEB_SALE_GROUP);
		}
		return;
	}

	if (! $scheduled) {
		// The sixth argument is `$unique` from Action Scheduler 3.6; older ones
		// take five and ignore it.
		as_schedule_recurring_action(time() + 60, OKO_WEB_SALE_INTERVAL, OKO_WEB_SALE_HOOK, array(), OKO_WEB_SALE_GROUP, true);
	}
}

/**
 * The accounts to fetch for: every one with a key, each key once.
 *
 * Two accounts on one key are one account at Økoskabet, and would list the
 * same orders twice.
 *
 * @return array<int,array<string,mixed>>
 */
function oko_web_sale_merchants(): array
{
	$merchants = class_exists('\\okoskabet_woocommerce_plugin\\Integrations\\Merchants')
		&& \okoskabet_woocommerce_plugin\Integrations\Merchants::has_any()
		? \okoskabet_woocommerce_plugin\Integrations\Merchants::get_all()
		: array(o_get_merchant());

	$seen = array();
	$with_key = array();
	foreach ($merchants as $merchant) {
		$key = (string) ($merchant['api_key'] ?? '');
		if ($key === '') {
			continue;
		}
		$account = o_merchant_api_url($merchant) . ' ' . $key;
		if (isset($seen[$account])) {
			continue;
		}
		$seen[$account] = true;
		$with_key[]     = $merchant;
	}

	return $with_key;
}

/**
 * The scheduled run: every account, one after the other.
 *
 * One account failing — a key Økoskabet refuses, a timeout — leaves the others
 * to be fetched.
 */
function oko_fetch_web_sale_orders(): void
{
	if (! oko_web_sale_take_lock()) {
		return;
	}

	try {
		foreach (oko_web_sale_merchants() as $merchant) {
			try {
				oko_fetch_web_sale_orders_for($merchant);
			} catch (\Throwable $e) {
				error_log('okoskabet_woocommerce_plugin: Fetching commission sales failed for merchant=' . (string) ($merchant['id'] ?? '?') . ': ' . $e->getMessage());
			}
		}
	} finally {
		delete_option(OKO_WEB_SALE_LOCK_OPTION);
	}
}

/**
 * Hold the fetch for this run.
 *
 * `add_option()` writes only when the row is not there, so of two runs that
 * start together one gets it. A hold older than a run could last is from a run
 * that died, and is taken over.
 */
function oko_web_sale_take_lock(): bool
{
	if (add_option(OKO_WEB_SALE_LOCK_OPTION, (string) time(), '', 'no')) {
		return true;
	}

	wp_cache_delete(OKO_WEB_SALE_LOCK_OPTION, 'options');
	$since = (int) get_option(OKO_WEB_SALE_LOCK_OPTION, 0);
	if ($since > time() - OKO_WEB_SALE_LOCK_SECONDS) {
		return false;
	}

	delete_option(OKO_WEB_SALE_LOCK_OPTION);

	return add_option(OKO_WEB_SALE_LOCK_OPTION, (string) time(), '', 'no');
}

/**
 * Fetch one account's commission sales, and take each one in.
 *
 * @param array<string,mixed> $merchant
 * @return string[] The references taken in and reported.
 */
function oko_fetch_web_sale_orders_for(array $merchant): array
{
	$merchant_id = (string) ($merchant['id'] ?? '?');
	$response    = oko_api_request($merchant, 'GET', '/api/v1/web_sale_orders');

	if (is_wp_error($response)) {
		error_log('okoskabet_woocommerce_plugin: Could not fetch commission sales for merchant=' . $merchant_id . ': ' . $response->get_error_message());
		return array();
	}

	$code = (int) wp_remote_retrieve_response_code($response);
	if ($code !== 200) {
		// 404 is an Økoskabet that does not have the endpoint yet. Said every
		// five minutes in every shop's log, it would bury everything else.
		if ($code !== 404) {
			error_log('okoskabet_woocommerce_plugin: Fetching commission sales for merchant=' . $merchant_id . ' answered ' . $code);
		}
		return array();
	}

	$body   = json_decode((string) wp_remote_retrieve_body($response), true);
	$orders = is_array($body) && is_array($body['orders'] ?? null) ? $body['orders'] : array();

	$done = array();
	foreach ($orders as $sale) {
		$reference = is_array($sale) ? (string) ($sale['reference'] ?? '') : '';
		try {
			if (oko_take_in_web_sale($merchant, is_array($sale) ? $sale : array())) {
				$done[] = $reference;
			}
		} catch (\Throwable $e) {
			// The reference only: the rest of the sale is a customer.
			error_log('okoskabet_woocommerce_plugin: Commission sale ' . ($reference !== '' ? $reference : '(no reference)') . ' for merchant=' . $merchant_id . ' was not taken in: ' . $e->getMessage());
		}
	}

	return $done;
}

/**
 * Make one commission sale an order, if it is not one already, and report it.
 *
 * Found first, by its reference: a run that died between making the order and
 * reporting it lists the sale again, and the order it made is reported again
 * rather than made twice.
 *
 * @param array<string,mixed> $merchant
 * @param array<string,mixed> $sale     One entry of the `orders` list.
 * @return bool Whether Økoskabet took the report.
 */
function oko_take_in_web_sale(array $merchant, array $sale): bool
{
	$reference = (string) ($sale['reference'] ?? '');

	// Only `web-`: a cancellation deletes the shipment under this reference,
	// and anything else could be one of the shop's own deliveries.
	if (! preg_match('/^web-[A-Za-z0-9_-]+$/', $reference)) {
		throw new \UnexpectedValueException('Not a commission sale reference');
	}

	$order = oko_web_sale_order($reference) ?? oko_create_web_sale_order($merchant, $sale);
	oko_finish_web_sale_order($order, $reference);

	return oko_report_web_sale_order($merchant, $order, $reference);
}

/**
 * The order made from a commission sale, if there is one. Trashed included:
 * the shop threw it away, but it was made.
 */
function oko_web_sale_order(string $reference): ?\WC_Order
{
	foreach (oko_orders_by_ids(oko_order_ids_with_meta(OKO_WEB_SALE_REFERENCE_META, $reference)) as $order) {
		if ((string) $order->get_meta(OKO_WEB_SALE_REFERENCE_META, true) === $reference) {
			return $order;
		}
	}

	return null;
}

/**
 * Whether the order came from a commission sale on okoskabet.dk.
 *
 * @param mixed $order
 */
function oko_is_web_sale_order($order): bool
{
	return $order instanceof \WC_Order && (string) $order->get_meta(OKO_WEB_SALE_REFERENCE_META, true) !== '';
}

/**
 * Build the order, unpaid, in one save.
 *
 * Marked booked in the same save, before it is ever paid: payment is the
 * status change the plugin books on, and this shipment is Økoskabet's already.
 *
 * @param array<string,mixed> $merchant
 * @param array<string,mixed> $sale
 */
function oko_create_web_sale_order(array $merchant, array $sale): \WC_Order
{
	$reference = (string) $sale['reference'];
	$lines     = is_array($sale['lines'] ?? null) ? $sale['lines'] : array();
	if ($lines === array()) {
		throw new \UnexpectedValueException('No lines');
	}

	$order = new \WC_Order();
	$order->set_created_via(OKO_WEB_SALE_PAYMENT_METHOD);
	$order->set_currency((string) ($sale['currency'] ?? '') !== '' ? (string) $sale['currency'] : get_woocommerce_currency());

	$placed = strtotime((string) ($sale['placed_at'] ?? ''));
	if ($placed !== false) {
		$order->set_date_created($placed);
		$order->set_date_paid($placed);
	}

	$order->set_payment_method(OKO_WEB_SALE_PAYMENT_METHOD);
	$order->set_payment_method_title(sprintf(
		/* translators: %s: the sale's mark, "Kommissionssalg Web". */
		__('Foodshipper – %s', O_TEXTDOMAIN),
		(string) ($sale['mark'] ?? '') !== '' ? (string) $sale['mark'] : 'Kommissionssalg Web'
	));
	$order->set_transaction_id($reference);

	oko_web_sale_customer($order, is_array($sale['customer'] ?? null) ? $sale['customer'] : array());

	foreach ($lines as $line) {
		oko_web_sale_line($order, is_array($line) ? $line : array());
	}

	$home = ($sale['shipment_type'] ?? '') === 'home_delivery';

	// The customer's freight stays with Foodshipper, which delivers (Marc,
	// 4 November 2026). The line says who delivers, at nothing, and the order
	// comes to what the shop sold. Økoskabet answers `shipping_price` "0.00"
	// and a `total` of the goods alone, and the freight is still taken off
	// below in case an answer ever carries it.
	$shipping = new \WC_Order_Item_Shipping();
	$shipping->set_method_id(OKO_WEB_SALE_PAYMENT_METHOD);
	$shipping->set_method_title(__('Delivered by Foodshipper', O_TEXTDOMAIN));
	$shipping->set_total(0);
	$order->add_item($shipping);

	$order->update_taxes();
	$order->calculate_totals(false);

	// The goods as Økoskabet priced them. Ours, reckoned back from prices
	// with VAT in them line by line, can be an øre off.
	$goods = 0.0;
	foreach ($lines as $line) {
		$goods += oko_web_sale_amount(is_array($line) ? ($line['unit_price'] ?? '0') : '0') * max(1, (int) (is_array($line) ? ($line['quantity'] ?? 1) : 1));
	}
	$goods = round($goods, 2);
	if (isset($sale['total']) && (string) $sale['total'] !== '') {
		$paid = oko_web_sale_amount($sale['total']) - oko_web_sale_amount($sale['shipping_price'] ?? '0');
		if (abs($paid - $goods) > 0.05) {
			error_log('okoskabet_woocommerce_plugin: Commission sale ' . $reference . ': lines come to ' . $goods . ', total less shipping is ' . $paid);
		}
	}
	$order->set_total($goods);

	$delivery_date = (string) ($sale['delivery_date'] ?? '');
	$shed_id       = $home ? '' : (string) ($sale['shed_id'] ?? '');
	if ($delivery_date !== '') {
		$order->update_meta_data('_billing_okoskabet_delivery_date', $delivery_date);
	}
	if ($shed_id !== '') {
		$order->update_meta_data('_billing_okoskabet_shed_id', $shed_id);
	}
	$order->update_meta_data(OKO_DELIVERY_SUMMARY_META, $home ? __('Home delivery', O_TEXTDOMAIN) : ($shed_id !== '' ? $shed_id : __('Shed', O_TEXTDOMAIN)));
	$order->update_meta_data(\okoskabet_woocommerce_plugin\Integrations\Merchant_Router::ORDER_META_KEY, (string) ($merchant['id'] ?? ''));
	$order->update_meta_data(OKO_WEB_SALE_REFERENCE_META, $reference);
	$order->update_meta_data('billing_okoskabet_done', true);

	$order->save();

	$order->add_order_note(sprintf(
		/* translators: 1: the reference, e.g. web-123, 2: delivery date, 3: shed or "Home delivery". */
		__('Commission sale from okoskabet.dk (%1$s). Paid to Foodshipper, and the customer has their receipt from Økoskabet. Shipping is paid to Foodshipper. Delivery %2$s, %3$s. Cancelling the order here cancels it at Økoskabet too.', O_TEXTDOMAIN),
		$reference,
		$delivery_date !== '' ? $delivery_date : __('without delivery date', O_TEXTDOMAIN),
		(string) $order->get_meta(OKO_DELIVERY_SUMMARY_META, true)
	));

	return $order;
}

/**
 * The rest of the way to a paid order, as often as it takes.
 *
 * Each step asks first whether it has been done, so a run that died halfway is
 * finished by the next one and nothing happens twice.
 */
function oko_finish_web_sale_order(\WC_Order $order, string $reference): void
{
	// Before the status change below, which is where the plugin books: an
	// order booked under its reference is one the booking leaves alone, and
	// one a cancellation deletes under that reference.
	if (oko_booked_reference($order) !== $reference) {
		oko_keep_booked_reference($order, $reference);
		$order->save();
	}

	if (! $order->has_status('pending')) {
		return;
	}

	// Asked here rather than left to the status change, which does the same
	// on most shops: the stock comes off whatever a shop has set up there. It
	// keeps its own note that it has, so the status change will not again.
	wc_reduce_stock_levels($order->get_id());

	$order = wc_get_order($order->get_id());
	$order->update_status('processing');
}

/**
 * Tell Økoskabet which order the sale became.
 *
 * @param array<string,mixed> $merchant
 */
function oko_report_web_sale_order(array $merchant, \WC_Order $order, string $reference): bool
{
	$response = oko_api_request(
		$merchant,
		'POST',
		'/api/v1/web_sale_orders/' . rawurlencode($reference) . '/created',
		(string) wp_json_encode(array(
			'order_id'     => (string) $order->get_id(),
			'order_number' => (string) $order->get_order_number(),
		))
	);

	if (is_wp_error($response)) {
		error_log('okoskabet_woocommerce_plugin: Could not report commission sale ' . $reference . ': ' . $response->get_error_message());
		return false;
	}

	$code = (int) wp_remote_retrieve_response_code($response);
	if ($code === 200) {
		return true;
	}

	if ($code === 409) {
		// Økoskabet has this sale as some other order: a copy of the shop on
		// the same key (a staging site), or an order made and then deleted for
		// good. This one is then a second order for the same goods, with the
		// stock taken twice. On hold for the shop to look at, and said once.
		error_log('okoskabet_woocommerce_plugin: Commission sale ' . $reference . ' is reported to Økoskabet as another order (409); order ' . $order->get_id() . ' put on hold.');
		if ($order->get_meta(OKO_WEB_SALE_CONFLICT_META, true) === '') {
			$order->update_meta_data(OKO_WEB_SALE_CONFLICT_META, (string) time());
			$order->save();
			$order->update_status('on-hold', sprintf(
				/* translators: %s: the reference, e.g. web-123. */
				__('Økoskabet already has commission sale %s as another order, so this one may be a second order for the same goods. Check before you pack it. Do not cancel it unless the sale itself is to be cancelled: cancelling cancels it at Økoskabet too.', O_TEXTDOMAIN),
				$reference
			));
		}
		return false;
	}

	error_log('okoskabet_woocommerce_plugin: Reporting commission sale ' . $reference . ' answered ' . $code);

	return false;
}

/**
 * The customer, as billing and as shipping.
 *
 * Only the country is checked for shape; an email WooCommerce will not take is
 * left out rather than costing the shop the order.
 *
 * @param array<string,mixed> $customer
 */
function oko_web_sale_customer(\WC_Order $order, array $customer): void
{
	// The names as Økoskabet has them, and the full name split at its first
	// space only where they are not given.
	$first = trim((string) ($customer['first_name'] ?? ''));
	$last  = trim((string) ($customer['last_name'] ?? ''));
	if ($first === '' && $last === '') {
		$parts = preg_split('/\s+/u', trim((string) ($customer['name'] ?? '')), 2) ?: array('');
		$first = (string) ($parts[0] ?? '');
		$last  = (string) ($parts[1] ?? '');
	}

	$order->set_billing_first_name($first);
	$order->set_billing_last_name($last);
	$order->set_shipping_first_name($first);
	$order->set_shipping_last_name($last);

	$email = sanitize_email((string) ($customer['email'] ?? ''));
	if ($email !== '' && is_email($email)) {
		$order->set_billing_email($email);
	}

	$phone = (string) ($customer['phone'] ?? '');
	$order->set_billing_phone($phone);
	if (method_exists($order, 'set_shipping_phone')) {
		$order->set_shipping_phone($phone);
	}

	$address = is_array($customer['address'] ?? null) ? $customer['address'] : array();
	$country = strtoupper((string) ($address['country'] ?? ''));
	$country = preg_match('/^[A-Z]{2}$/', $country) ? $country : 'DK';

	foreach (array('billing', 'shipping') as $kind) {
		$order->{"set_{$kind}_country"}($country);
		if ($address !== array()) {
			$order->{"set_{$kind}_address_1"}((string) ($address['street'] ?? ''));
			$order->{"set_{$kind}_postcode"}((string) ($address['postal_code'] ?? ''));
			$order->{"set_{$kind}_city"}((string) ($address['city'] ?? ''));
		}
	}
}

/**
 * One line of the sale: the shop's own product where Økoskabet names one that
 * is here, its title and price otherwise.
 *
 * A line without a product takes nothing off the stock, which is right: there
 * is nothing here to take it off.
 *
 * @param array<string,mixed> $line
 */
function oko_web_sale_line(\WC_Order $order, array $line): void
{
	$quantity = max(1, (int) ($line['quantity'] ?? 1));
	$gross    = oko_web_sale_amount($line['unit_price'] ?? '0') * $quantity;

	$product_id = (string) ($line['product_external_id'] ?? '');
	$product    = preg_match('/^\d+$/', $product_id) ? wc_get_product((int) $product_id) : null;
	// The id is the shop's product as its own order lines name it, so a
	// variable product says which product and not which of its variants.
	// Guessed, the stock would come off the wrong one; as a line of its own,
	// it comes off none, and the shop sees which it is from the title. Only a
	// product that is the thing sold — a simple one, or an id that is itself a
	// variation — is linked.
	if ($product instanceof \WC_Product
		&& ($product->get_status() === 'trash' || ! $product->is_type(array('simple', 'variation')))) {
		$product = null;
	}

	$tax_class = $product instanceof \WC_Product && $product->is_taxable() ? (string) $product->get_tax_class() : '';
	$taxes     = $product instanceof \WC_Product && ! $product->is_taxable() ? array() : oko_web_sale_taxes($gross, $tax_class);
	$net       = oko_web_sale_net($gross, $taxes);

	// Built here rather than by `add_product()`, which saves the line at once:
	// with the order not saved yet, a sale that then failed left lines behind
	// belonging to no order. Saved with the order, they are saved or not with it.
	$item = new \WC_Order_Item_Product();
	if ($product instanceof \WC_Product) {
		$item->set_product($product);
	} else {
		$item->set_name((string) ($line['title'] ?? '') !== '' ? (string) $line['title'] : __('Product', O_TEXTDOMAIN));
		$item->set_tax_class('');
	}
	$item->set_quantity($quantity);
	$item->set_subtotal($net);
	$item->set_total($net);
	$item->set_taxes(array('subtotal' => $taxes, 'total' => $taxes));
	$order->add_item($item);
}

/**
 * A price as Økoskabet writes it — kroner with VAT, as a string — as a number.
 *
 * @param mixed $amount
 */
function oko_web_sale_amount($amount): float
{
	return round((float) str_replace(',', '.', (string) $amount), 2);
}

/**
 * The VAT in an amount that has it in, at the shop's own base rates.
 *
 * Nothing on a shop that does not do taxes: the amount is then what it is.
 *
 * @return array<int|string,float>
 */
function oko_web_sale_taxes(float $gross, string $tax_class): array
{
	if ($gross <= 0 || ! wc_tax_enabled()) {
		return array();
	}

	return \WC_Tax::calc_tax($gross, \WC_Tax::get_base_tax_rates($tax_class), true);
}

/** @param array<int|string,float> $taxes */
function oko_web_sale_net(float $gross, array $taxes): float
{
	return $gross - array_sum($taxes);
}

/**
 * No WooCommerce mail to the customer about one of these: they have their
 * receipt from Økoskabet, and the shop's word that the order is being packed
 * would be about an order they never placed with the shop. The shop's own
 * mails — a new order — go as always.
 *
 * @param \WC_Emails $mailer
 */
function oko_web_sale_keeps_customer_emails_back($mailer): void
{
	foreach ((array) $mailer->get_emails() as $email) {
		if ($email instanceof \WC_Email && $email->is_customer_email()) {
			add_filter('woocommerce_email_enabled_' . $email->id, 'oko_web_sale_customer_email_enabled', 10, 2);
		}
	}
}

/**
 * @param bool  $enabled
 * @param mixed $object
 */
function oko_web_sale_customer_email_enabled($enabled, $object = null): bool
{
	return oko_is_web_sale_order($object) ? false : (bool) $enabled;
}
