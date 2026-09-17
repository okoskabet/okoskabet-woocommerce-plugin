<?php
/**
 * A WordPress-shaped room just big enough for the delivery rules to stand up in.
 *
 * The split-checkout grouping and the delivery-exception matching are ordinary
 * date and set arithmetic. They read the shop's configuration and the products'
 * categories, and that is the whole of their contact with WordPress — so they
 * can be exercised without a database, a WooCommerce install or a web server,
 * and this file is what lets that happen. `php tests/standalone/run.php` and you
 * have an answer in under a second, which is the difference between checking the
 * grouping while you are changing it and checking it at the end of the day.
 *
 * The wpunit suite still covers the parts that genuinely need WordPress — real
 * terms, a real cart, the packaging fee's hook. This is not a replacement for it.
 *
 * Nothing here is loaded by the plugin itself.
 *
 * @package okoskabet_woocommerce_plugin
 */

// phpcs:disable

define( 'O_TEXTDOMAIN', 'okoskabet-woocommerce-plugin' );
define( 'O_NAME', 'Økoskabet' );
define( 'ABSPATH', __DIR__ );

// ---------------------------------------------------------------------------
// The shop's own state
// ---------------------------------------------------------------------------

/** @var array<string,mixed> Stand-in for the wp_options table. */
$GLOBALS['oko_test_options'] = array();

/** @var array<int,array{name:string,cats:int[],tags:int[]}> Stand-in for the catalogue. */
$GLOBALS['oko_test_products'] = array();

/** @var array<string,mixed> Stand-in for the plugin's settings row. */
$GLOBALS['oko_test_settings'] = array();

/** @var array<int,array{hook:string,callback:mixed}> Every hook the code registers. */
$GLOBALS['oko_test_hooks'] = array();

/**
 * Put a product in the catalogue.
 *
 * @param int      $id
 * @param string   $name
 * @param int[]    $cats
 * @param int[]    $tags
 */
function oko_test_add_product( int $id, string $name, array $cats = array(), array $tags = array() ): void {
	$GLOBALS['oko_test_products'][ $id ] = array( 'name' => $name, 'cats' => $cats, 'tags' => $tags );
}

/** Forget everything between tests, including the classes' own static caches. */
function oko_test_reset(): void {
	$GLOBALS['oko_test_options']  = array();
	$GLOBALS['oko_test_products'] = array();
	$GLOBALS['oko_test_settings'] = array();
	$GLOBALS['oko_test_hooks']    = array();
	$GLOBALS['oko_test_notices']  = array();
	// A month of daily deliveries unless a test says otherwise. Tests about the
	// grouping want the rules to be the only thing narrowing the days; tests
	// about the dates themselves set a sparse, realistic calendar.
	$GLOBALS['oko_test_delivery_days'] = oko_test_days_ahead( 28 );
	oko_test_set_pre_order( false );
	\okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::purge_rules_cache();
	oko_test_set_cart( array() );
}

/**
 * The occurrence of a weekday in the week AFTER the next one.
 *
 * Tests about where dates come from use this rather than the next occurrence,
 * and the difference is the whole test: a shop's delivery days are a subset of
 * the days its rules allow, never the same set. Code that builds its own
 * calendar lands on the nearest allowed day, which is usually sooner than any
 * day the van actually comes — and a test whose offered days start at the
 * nearest one cannot tell the two apart.
 */
function oko_test_weekday_next_week( int $weekday ): string {
	return ( new DateTimeImmutable( oko_test_next_weekday( $weekday ), wp_timezone() ) )
		->modify( '+7 days' )
		->format( 'Y-m-d' );
}

/** Today's weekday, 0=Sun..6=Sat. */
function oko_test_today_weekday(): int {
	return (int) ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'w' );
}

/** Every date from today up to N days out. */
function oko_test_days_ahead( int $count ): array {
	$days = array();
	for ( $i = 0; $i < $count; $i++ ) {
		$days[] = oko_test_date( $i );
	}
	return $days;
}

// ---------------------------------------------------------------------------
// WordPress
// ---------------------------------------------------------------------------

function __( $text, $domain = null ) { return $text; }
function _e( $text, $domain = null ) { echo $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function esc_html_e( $text, $domain = null ) { echo $text; }
function esc_attr_e( $text, $domain = null ) { echo $text; }
function esc_html( $text ) { return $text; }
function esc_attr( $text ) { return $text; }
function esc_url( $url ) { return $url; }
function esc_textarea( $text ) { return $text; }
function _n( $single, $plural, $number, $domain = null ) { return $number === 1 ? $single : $plural; }
function wp_kses( $string, $allowed ) { return $string; }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_strip_all_tags( $text ) { return strip_tags( (string) $text ); }
function sanitize_text_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function sanitize_textarea_field( $str ) { return trim( strip_tags( (string) $str ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_rand( $min = 0, $max = 0 ) { return random_int( $min, $max ?: PHP_INT_MAX ); }
function wp_unslash( $value ) { return $value; }
function is_admin() { return false; }
function current_user_can( $cap ) { return true; }
function checked( $a, $b = true, $echo = true ) { return ''; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function wp_create_nonce( $action ) { return 'nonce'; }

function wp_timezone(): DateTimeZone {
	return new DateTimeZone( 'Europe/Copenhagen' );
}

function get_option( $name, $default = false ) {
	return $GLOBALS['oko_test_options'][ $name ] ?? $default;
}

function update_option( $name, $value ) {
	$GLOBALS['oko_test_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['oko_test_options'][ $name ] );
	return true;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['oko_test_hooks'][] = array( 'hook' => $hook, 'callback' => $callback );
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['oko_test_hooks'][] = array( 'hook' => $hook, 'callback' => $callback );
	return true;
}

function apply_filters( $hook, $value ) { return $value; }
function do_action( $hook ) {}

function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
	$product = $GLOBALS['oko_test_products'][ (int) $post_id ] ?? null;
	if ( $product === null ) {
		return array();
	}
	return $taxonomy === 'product_cat' ? $product['cats'] : $product['tags'];
}

function get_post( $id ) {
	$product = $GLOBALS['oko_test_products'][ (int) $id ] ?? null;
	return $product === null ? null : (object) array( 'post_title' => $product['name'] );
}

// ---------------------------------------------------------------------------
// WooCommerce
// ---------------------------------------------------------------------------

/** @var array<int,string> Customer-facing notices raised during a test. */
$GLOBALS['oko_test_notices'] = array();

function wc_add_notice( $message, $type = 'success' ) {
	$GLOBALS['oko_test_notices'][] = $message;
}

function wc_get_checkout_url() { return 'https://example.test/kassen'; }
function wc_get_cart_url() { return 'https://example.test/kurv'; }
function wc_get_page_permalink( $page ) { return 'https://example.test/' . $page; }
function wc_get_price_decimals() { return 2; }

function wc_get_product( $id ) {
	$product = $GLOBALS['oko_test_products'][ (int) $id ] ?? null;
	if ( $product === null ) {
		return null;
	}
	return new class( $product['name'] ) {
		private $name;
		public function __construct( string $name ) { $this->name = $name; }
		public function get_name() { return $this->name; }
	};
}

/** The plugin's own settings accessor. */
function o_get_settings() {
	return $GLOBALS['oko_test_settings'];
}

/** A cart that answers the handful of questions the code under test asks it. */
class Oko_Test_Cart {

	/** @var array<string,array> */
	public $contents;

	/** @var string[] Keys removed through remove_cart_item(). */
	public $removed = array();

	public function __construct( array $contents = array() ) {
		$this->contents = $contents;
	}

	public function is_empty() { return empty( $this->contents ); }
	public function get_cart() { return $this->contents; }
	public function get_cart_contents_count() { return count( $this->contents ); }
	public function get_applied_coupons() { return array(); }
	public function calculate_totals() {}
	public function get_cart_for_session() { return $this->contents; }

	public function remove_cart_item( $key ) {
		if ( isset( $this->contents[ $key ] ) ) {
			$this->removed[] = $key;
			unset( $this->contents[ $key ] );
		}
		return true;
	}

	public function empty_cart( $clear_persistent = true ) {
		$this->contents = array();
	}

	/** Enough of WooCommerce's signature for the split to rebuild a cart. */
	public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		$key = md5( $product_id . '|' . $variation_id . '|' . wp_json_encode( $variation ) );
		$this->contents[ $key ] = array_merge(
			array(
				'key'          => $key,
				'product_id'   => (int) $product_id,
				'quantity'     => (int) $quantity,
				'variation_id' => (int) $variation_id,
				'variation'    => (array) $variation,
				'line_total'   => 0.0,
				'line_tax'     => 0.0,
			),
			(array) $cart_item_data
		);
		return $key;
	}
}

/**
 * What an AJAX handler answered with. The real ones end the request; here we
 * unwind to the test with the payload, which is the same thing said politely.
 */
class Oko_Test_Json_Response extends Exception {

	/** @var bool */
	public $success;

	/** @var array */
	public $payload;

	public function __construct( bool $success, array $payload ) {
		parent::__construct( $success ? 'success' : 'error' );
		$this->success = $success;
		$this->payload = $payload;
	}
}

function check_ajax_referer( $action, $field = false, $die = true ) { return true; }
function wc_load_cart() {}

function wp_send_json_success( $data = array() ) {
	throw new Oko_Test_Json_Response( true, (array) $data );
}

function wp_send_json_error( $data = array() ) {
	throw new Oko_Test_Json_Response( false, (array) $data );
}

/** A session that remembers what it is given, like the real one. */
class Oko_Test_Session {

	/** @var array<string,mixed> */
	private $data = array();

	public function get( $key, $default = null ) { return $this->data[ $key ] ?? $default; }
	public function set( $key, $value ) { $this->data[ $key ] = $value; }
	public function __unset( $key ) { unset( $this->data[ $key ] ); }
	public function save_data() {}
	public function has_session() { return true; }
	public function get_customer_id() { return 'test-customer'; }
	public function set_customer_session_cookie( $set ) {}
}

class Oko_Test_WooCommerce {
	public $cart;
	public $session;
}

$GLOBALS['oko_test_wc']          = new Oko_Test_WooCommerce();
$GLOBALS['oko_test_wc']->cart    = new Oko_Test_Cart();
$GLOBALS['oko_test_wc']->session = new Oko_Test_Session();

function WC() {
	return $GLOBALS['oko_test_wc'];
}

/**
 * Put a cart together from a list of [cart_item_key, product_id] pairs.
 *
 * @param array<string,int> $items key => product id
 */
function oko_test_set_cart( array $items ): void {
	$contents = array();
	foreach ( $items as $key => $product_id ) {
		$contents[ $key ] = array(
			'key'          => $key,
			'product_id'   => (int) $product_id,
			'quantity'     => 1,
			'variation_id' => 0,
			'variation'    => array(),
			'line_total'   => 0.0,
			'line_tax'     => 0.0,
		);
	}
	$GLOBALS['oko_test_wc']->cart = new Oko_Test_Cart( $contents );
}

// ---------------------------------------------------------------------------
// The code under test
// ---------------------------------------------------------------------------

require_once dirname( __DIR__, 2 ) . '/engine/Base.php';
require_once dirname( __DIR__, 2 ) . '/integrations/Delivery_Exceptions.php';
require_once dirname( __DIR__, 2 ) . '/integrations/Split_Checkout.php';

/**
 * The days the shop drives on, as Økoskabet would answer for this address.
 *
 * Null stands for "Økoskabet could not be asked" — no postcode yet, or the API
 * unreachable. That is a different answer from "no days", and the banner has to
 * treat it differently.
 *
 * @var string[]|null
 */
$GLOBALS['oko_test_delivery_days'] = array();

/** Say which days the shop delivers on, or null for "cannot be asked". */
function oko_test_set_delivery_days( ?array $days ): void {
	$GLOBALS['oko_test_delivery_days'] = $days;
}

/** The days the source was told to offer, for tests that check against them. */
function oko_test_delivery_days(): ?array {
	return $GLOBALS['oko_test_delivery_days'];
}

/**
 * Split checkout with Økoskabet's delivery-day endpoint stood in for.
 *
 * The stand-in does what the real endpoint does, in the same order: Økoskabet
 * names the days it drives on, and the merchant's exception rules narrow that
 * list. What it will not do — and this is the whole point of it — is invent a
 * day the shop does not deliver on, so a test can hold every date the banner
 * shows against the days that were actually offered.
 */
class Oko_Test_Split_Checkout extends \okoskabet_woocommerce_plugin\Integrations\Split_Checkout {

	/** @var string[] Every product+mode the source was asked about. */
	public $asked = array();

	protected function delivery_days_for_product( int $product_id, bool $pre_order = false ): ?array {
		$this->asked[] = $product_id . '|' . ( $pre_order ? 'pre' : 'normal' );

		$days = $GLOBALS['oko_test_delivery_days'];
		if ( $days === null ) {
			return null;
		}

		return ( new \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions() )
			->filter_dates_for_cart( $days, array( $product_id ), $pre_order );
	}

	/** The banner's own wording for a group, which is otherwise internal. */
	public function heading_for( array $group, int $number ): string {
		return $this->group_heading( $group, $number );
	}
}

/** What the banner would call this group. */
function oko_split_heading( array $group, int $number ): string {
	return ( new Oko_Test_Split_Checkout() )->heading_for( $group, $number );
}

/** Whether the checkout is currently in pre-order mode, for the stubs below. */
$GLOBALS['oko_test_pre_order'] = false;

/** Put the test checkout into a pre-order, or back out of it. */
function oko_test_set_pre_order( bool $on ): void {
	$GLOBALS['oko_test_pre_order'] = $on;
	$_COOKIE['okoskabet_pre_order'] = $on ? '1' : '';
}

function oko_pre_order_checkout_requested(): bool {
	return (bool) $GLOBALS['oko_test_pre_order'];
}

function oko_is_pre_order_checkout(): bool {
	return (bool) $GLOBALS['oko_test_pre_order'];
}

// ---------------------------------------------------------------------------
// A test runner, as small as it can be and still say what broke
// ---------------------------------------------------------------------------

$GLOBALS['oko_test_passed'] = 0;
$GLOBALS['oko_test_failed'] = array();
$GLOBALS['oko_test_current'] = '';

function it( string $description, callable $body ): void {
	oko_test_reset();
	$GLOBALS['oko_test_current'] = $description;
	try {
		$body();
		$GLOBALS['oko_test_passed']++;
		echo "  ok   $description\n";
	} catch ( Throwable $e ) {
		$GLOBALS['oko_test_failed'][] = array( 'name' => $description, 'why' => $e->getMessage() );
		echo "  FAIL $description\n       " . $e->getMessage() . "\n";
	}
}

function describe( string $heading ): void {
	echo "\n$heading\n";
}

function fail( string $why ): void {
	throw new RuntimeException( $why );
}

function assert_same( $expected, $actual, string $what = '' ): void {
	if ( $expected !== $actual ) {
		fail( sprintf(
			'%sexpected %s, got %s',
			$what === '' ? '' : $what . ': ',
			var_export( $expected, true ),
			var_export( $actual, true )
		) );
	}
}

function assert_true( $actual, string $what = '' ): void {
	if ( $actual !== true ) {
		fail( ( $what === '' ? '' : $what . ': ' ) . 'expected true, got ' . var_export( $actual, true ) );
	}
}

function assert_false( $actual, string $what = '' ): void {
	if ( $actual !== false ) {
		fail( ( $what === '' ? '' : $what . ': ' ) . 'expected false, got ' . var_export( $actual, true ) );
	}
}

function assert_contains( string $needle, string $haystack, string $what = '' ): void {
	if ( strpos( $haystack, $needle ) === false ) {
		fail( sprintf(
			'%sexpected to find "%s" in "%s"',
			$what === '' ? '' : $what . ': ',
			$needle,
			$haystack
		) );
	}
}

function oko_test_summary(): int {
	$failed = $GLOBALS['oko_test_failed'];
	echo "\n";
	if ( empty( $failed ) ) {
		printf( "%d passed.\n", $GLOBALS['oko_test_passed'] );
		return 0;
	}
	printf( "%d passed, %d FAILED:\n", $GLOBALS['oko_test_passed'], count( $failed ) );
	foreach ( $failed as $failure ) {
		printf( "  - %s\n    %s\n", $failure['name'], $failure['why'] );
	}
	return 1;
}

// ---------------------------------------------------------------------------
// Shorthands for building a shop
// ---------------------------------------------------------------------------

/** Write the delivery-exceptions configuration and drop the caches. */
function oko_test_set_exceptions( array $config ): void {
	update_option( \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::OPTION_KEY, $config );
	\okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::purge_rules_cache();
}

/** Write the plugin settings row. */
function oko_test_set_settings( array $settings ): void {
	$GLOBALS['oko_test_settings'] = $settings;
}

/** A Y-m-d date this many days from today, in the shop's timezone. */
function oko_test_date( int $days_from_today ): string {
	$dt = new DateTimeImmutable( 'today', wp_timezone() );
	return $dt->modify( sprintf( '%+d days', $days_from_today ) )->format( 'Y-m-d' );
}

/** The next date on or after today that falls on the given weekday (0=Sun). */
function oko_test_next_weekday( int $weekday ): string {
	$dt = new DateTimeImmutable( 'today', wp_timezone() );
	for ( $i = 0; $i < 14; $i++ ) {
		if ( (int) $dt->format( 'w' ) === $weekday ) {
			return $dt->format( 'Y-m-d' );
		}
		$dt = $dt->modify( '+1 day' );
	}
	return $dt->format( 'Y-m-d' );
}
