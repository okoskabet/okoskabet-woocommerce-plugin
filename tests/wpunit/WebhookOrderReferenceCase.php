<?php

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * A webhook acts on the order that was booked under its reference, and on no
 * other.
 *
 * Økoskabet knows an order by the `shipment_reference` we sent when booking
 * it, and that has always been the order *number* — what the shop prints. The
 * webhook looked the reference up as the order's internal *id*. Without a
 * numbering plugin the two are the same and nobody noticed. With one they are
 * not: a shop with padded numbers had its statuses come back 404 "Order not
 * found" (October 2026), and where some other order happened to have that id,
 * the webhook completed — or charged — that order instead.
 *
 * Numbering plugins work by filtering `woocommerce_order_number`, so that is
 * what these tests do. The filter below behaves the way the common ones do:
 * the number lives in `_order_number` meta, and an order without it falls back
 * to its id.
 *
 * Run twice, once per order storage — see the two subclasses. The posts table
 * and WooCommerce's own order tables answer meta queries differently, and the
 * lookup has to hold in both.
 */
abstract class WebhookOrderReferenceCase extends \Codeception\TestCase\WPTestCase {

	const SECRET = 'a-webhook-secret';

	/** True for WooCommerce's own order tables (HPOS), false for posts. */
	abstract protected function hpos(): bool;

	/** @var array<int,array{method:string,url:string,body:string}> */
	protected $requests = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// Before any test's transaction opens: creating tables would commit it.
		wc_get_container()->get( DataSynchronizer::class )->create_database_tables();
	}

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );

		update_option( CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, $this->hpos() ? 'yes' : 'no' );
		update_option( DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, 'no' );

		delete_option( OKO_SETTINGS_SAVED_OPTION );
		$settings                     = (array) get_option( O_TEXTDOMAIN . '-settings', array() );
		$settings['_webhook_enabled'] = 'on';
		update_option( O_TEXTDOMAIN . '-settings', $settings );

		delete_option( Merchants::OPTION_KEY );
		Merchants::purge_config_cache();
		Merchants::save_config(
			array(
				'default_merchant_id' => 'default',
				'merchants'           => array(
					'default' => array(
						'id'             => 'default',
						'label'          => 'Default',
						'api_key'        => 'test-key',
						'staging'        => true,
						'webhook_secret' => self::SECRET,
						'webhook_events' => array( 'fulfilled' ),
						'capture_events' => array(),
					),
				),
			)
		);

		add_filter( 'woocommerce_order_number', array( $this, 'numbering_plugin' ), 10, 2 );

		$this->requests = array();
		add_filter( 'pre_http_request', array( $this, 'okoskabet' ), 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'woocommerce_order_number', array( $this, 'numbering_plugin' ), 10 );
		remove_filter( 'pre_http_request', array( $this, 'okoskabet' ), 10 );
		delete_option( Merchants::OPTION_KEY );
		Merchants::purge_config_cache();
		parent::tearDown();
	}

	/** What a numbering plugin does: the number is its own, kept in meta. */
	public function numbering_plugin( $number, $order ) {
		$own = $order instanceof \WC_Abstract_Order ? (string) $order->get_meta( '_order_number', true ) : '';

		return $own !== '' ? $own : $number;
	}

	/** Økoskabet, accepting every booking. */
	public function okoskabet( $preempt, $args, $url ) {
		$method           = (string) ( $args['method'] ?? 'GET' );
		$this->requests[] = array( 'method' => $method, 'url' => (string) $url, 'body' => (string) ( $args['body'] ?? '' ) );

		$code = $method === 'POST' ? 201 : ( $method === 'DELETE' ? 204 : 200 );

		return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	}

	// ------------------------------------------------------------- helpers

	/** A paid order waiting for its parcel, numbered by the plugin if given a number. */
	protected function order( string $number = '' ): \WC_Order {
		$order = new \WC_Order();
		$order->set_status( 'processing' );
		$order->set_date_paid( time() );
		if ( $number !== '' ) {
			$order->update_meta_data( '_order_number', $number );
		}
		$order->save();

		return $order;
	}

	/** An order the way the checkout leaves it, ready to be booked with Økoskabet. */
	protected function order_to_book( string $number ): \WC_Order {
		$order = new \WC_Order();
		$order->update_meta_data( '_order_number', $number );
		$order->update_meta_data( '_billing_okoskabet_delivery_date', '2026-10-23' );
		$order->update_meta_data( '_billing_okoskabet_shed_id', '42' );

		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id( 'hey_okoskabet_shipping_shed' );
		$shipping->set_method_title( 'Skab' );
		$order->add_item( $shipping );

		$order->set_status( 'pending' );
		$order->save();

		return $order;
	}

	/** Pay for it, which is what books it. */
	protected function book( \WC_Order $order ): \WC_Order {
		$order->set_date_paid( time() );
		$order->update_status( 'processing' );

		return wc_get_order( $order->get_id() );
	}

	/** An id no order, post or anything else in the shop has. */
	protected function unused_id(): int {
		global $wpdb;

		$highest = max(
			(int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ),
			(int) $wpdb->get_var( "SELECT MAX(id) FROM {$wpdb->prefix}wc_orders" )
		);

		return $highest + 1000;
	}

	/**
	 * Økoskabet telling us the parcel has reached the customer.
	 *
	 * The handler is called directly rather than through `rest_do_request`:
	 * WPLoader boots WordPress past the point where the plugin registers its
	 * routes, so the route does not exist in the harness (see
	 * WebhookSignedBodyTest).
	 */
	protected function delivered( string $reference ) {
		$raw = wp_json_encode(
			array(
				'event'              => 'reservation_updated',
				'shipment_reference' => $reference,
				'changes'            => array( 'status' => array( 'value' => 'fulfilled' ) ),
			)
		);

		$request = new \WP_REST_Request( 'POST', '/wp/v2/okoskabet/webhook/default' );
		$request->set_url_params( array( 'merchant_id' => 'default' ) );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-hmac-sha256', hash_hmac( 'sha256', $raw, self::SECRET ) );
		$request->set_body( $raw );

		return ( new \okoskabet_woocommerce_plugin\Rest\OkoRest() )->handle_webhook( $request );
	}

	protected function status( $result ): int {
		if ( $result instanceof \WP_Error ) {
			$data = $result->get_error_data();

			return (int) ( is_array( $data ) ? ( $data['status'] ?? 0 ) : 0 );
		}

		return $result instanceof \WP_REST_Response ? (int) $result->get_status() : 200;
	}

	protected function status_of( \WC_Order $order ): string {
		return wc_get_order( $order->get_id() )->get_status();
	}

	/** @return array<int,array{method:string,url:string,body:string}> */
	protected function requests_by( string $method ): array {
		return array_values( array_filter( $this->requests, static fn( $r ) => $r['method'] === $method ) );
	}

	// --------------------------------------------------------------- tests

	public function test_the_storage_under_test_is_the_one_in_use() {
		$this->assertSame(
			$this->hpos(),
			\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
			'otherwise both runs test the same storage and one of them proves nothing'
		);
	}

	/**
	 * The booking says which reference it went out under, and the order keeps
	 * it. Everything after that — the webhook, a resend, a cancellation — asks
	 * for this, never for whatever the number happens to be today.
	 */
	public function test_booking_keeps_the_reference_it_was_sent_under() {
		$order = $this->book( $this->order_to_book( '000028712' ) );

		$posts = $this->requests_by( 'POST' );
		$this->assertCount( 1, $posts, 'the order is booked once' );
		$this->assertSame( '000028712', json_decode( $posts[0]['body'], true )['shipment_reference'] );
		$this->assertSame( '000028712', $order->get_meta( OKO_SHIPMENT_REFERENCE_META, true ) );
		$this->assertSame( $order->get_id(), (int) $order->get_meta( OKO_SHIPMENT_REFERENCE_FOR_META, true ), 'and says whose it is' );
	}

	/** As it happened: a padded number that is no order's id. */
	public function test_a_booked_order_is_found_by_its_reference() {
		$reference = sprintf( '%09d', $this->unused_id() );
		$order     = $this->book( $this->order_to_book( $reference ) );

		$response = $this->delivered( $reference );

		$this->assertSame( 200, $this->status( $response ), 'the order exists; Økoskabet must not be told otherwise' );
		$this->assertSame( 'completed', $this->status_of( $order ) );
	}

	/**
	 * The dangerous half. Another order's id is the reference, and it belongs
	 * to the same shop, so the merchant check waved it through. That order was
	 * completed — or charged — for a parcel that was never its own.
	 */
	public function test_an_order_whose_id_is_the_reference_is_left_alone() {
		$bystander = $this->order( 'B-' . wp_generate_password( 6, false ) );
		$booked    = $this->book( $this->order_to_book( (string) $bystander->get_id() ) );

		$response = $this->delivered( (string) $bystander->get_id() );

		$this->assertSame( 200, $this->status( $response ) );
		$this->assertSame( 'processing', $this->status_of( $bystander ), 'an order never booked under this reference must not be touched' );
		$this->assertSame( 'completed', $this->status_of( $booked ), 'and the order that was is the one acted on' );
	}

	/**
	 * Orders booked before the reference was kept have nothing stored. They are
	 * found by number — through the numbering plugin's own meta — and only
	 * when the order's number today is exactly the reference.
	 */
	public function test_an_order_booked_before_the_fix_is_found_by_its_number() {
		$reference = sprintf( '%09d', $this->unused_id() );
		$order     = $this->order( $reference );

		$response = $this->delivered( $reference );

		$this->assertSame( 200, $this->status( $response ) );
		$this->assertSame( 'completed', $this->status_of( $order ) );
	}

	/** The same, where the id lookup used to land on a stranger. */
	public function test_an_old_order_whose_id_is_the_reference_is_left_alone() {
		$bystander = $this->order( 'B-' . wp_generate_password( 6, false ) );
		$intended  = $this->order( (string) $bystander->get_id() );

		$response = $this->delivered( (string) $bystander->get_id() );

		$this->assertSame( 200, $this->status( $response ) );
		$this->assertSame( 'processing', $this->status_of( $bystander ) );
		$this->assertSame( 'completed', $this->status_of( $intended ) );
	}

	/** A reference that is some order's id, but nobody's number, is nobody's. */
	public function test_an_id_that_is_no_orders_number_is_not_found() {
		$bystander = $this->order( 'B-' . wp_generate_password( 6, false ) );

		$response = $this->delivered( (string) $bystander->get_id() );

		$this->assertSame( 404, $this->status( $response ) );
		$this->assertSame( 'processing', $this->status_of( $bystander ) );
	}

	/**
	 * A plugin that keeps its number under a key we have never heard of is
	 * still found, through the hook WooCommerce's own order tracking asks
	 * numbering plugins to answer.
	 */
	public function test_a_numbering_plugin_is_asked_through_the_order_tracking_hook() {
		$reference = 'AVO-' . $this->unused_id();
		$order     = new \WC_Order();
		$order->set_status( 'processing' );
		$order->set_date_paid( time() );
		$order->update_meta_data( '_some_plugins_own_key', $reference );
		$order->save();

		$own_number = static function ( $number, $o ) {
			$n = $o instanceof \WC_Abstract_Order ? (string) $o->get_meta( '_some_plugins_own_key', true ) : '';
			return $n !== '' ? $n : $number;
		};
		$tracking = static function ( $id ) use ( $reference, $order ) {
			return $id === $reference ? $order->get_id() : $id;
		};
		add_filter( 'woocommerce_order_number', $own_number, 20, 2 );
		add_filter( 'woocommerce_shortcode_order_tracking_order_id', $tracking, 5 );

		try {
			$response = $this->delivered( $reference );
		} finally {
			remove_filter( 'woocommerce_order_number', $own_number, 20 );
			remove_filter( 'woocommerce_shortcode_order_tracking_order_id', $tracking, 5 );
		}

		$this->assertSame( 200, $this->status( $response ) );
		$this->assertSame( 'completed', $this->status_of( $order ) );
	}

	/**
	 * The reference is what Økoskabet has. A number that changes after
	 * booking changes nothing there, so it must change nothing here either.
	 */
	public function test_the_reference_outlives_a_new_number() {
		$reference = 'OLD-' . $this->unused_id();
		$order     = $this->book( $this->order_to_book( $reference ) );

		$order->update_meta_data( '_order_number', 'NEW-' . $order->get_id() );
		$order->save();

		$this->assertSame( 404, $this->status( $this->delivered( 'NEW-' . $order->get_id() ) ), 'Økoskabet never heard of the new number' );
		$this->assertSame( 'processing', $this->status_of( $order ) );

		$this->assertSame( 200, $this->status( $this->delivered( $reference ) ) );
		$this->assertSame( 'completed', $this->status_of( $order ) );
	}

	/** A cancellation names the shipment Økoskabet has, not today's number. */
	public function test_a_cancellation_names_the_reference_that_was_booked() {
		$reference = 'OLD-' . $this->unused_id();
		$order     = $this->book( $this->order_to_book( $reference ) );

		$order->update_meta_data( '_order_number', 'NEW-' . $order->get_id() );
		$order->save();
		$this->requests = array();

		wc_get_order( $order->get_id() )->update_status( 'cancelled' );

		$deletes = $this->requests_by( 'DELETE' );
		$this->assertCount( 1, $deletes );
		$this->assertStringEndsWith( '/api/v1/shipments/' . $reference, $deletes[0]['url'] );
	}

	/** And so does an edit sent on afterwards. */
	public function test_a_resend_names_the_reference_that_was_booked() {
		$reference = 'OLD-' . $this->unused_id();
		$order     = $this->book( $this->order_to_book( $reference ) );

		$order->update_meta_data( '_order_number', 'NEW-' . $order->get_id() );
		$order->update_meta_data( '_billing_okoskabet_delivery_date', '2026-10-30' );
		$order->save();

		$puts = $this->requests_by( 'PUT' );
		$this->assertCount( 1, $puts, 'a new delivery date is an edit Økoskabet is told about' );
		$this->assertStringEndsWith( '/api/v1/shipments/' . $reference, $puts[0]['url'] );
		$this->assertSame( $reference, json_decode( $puts[0]['body'], true )['shipment_reference'] );
	}

	/** Copy an order's meta onto another, the way a duplicate-order plugin or a renewal does. */
	protected function copy_meta( \WC_Order $from, \WC_Order $to, array $keys ): \WC_Order {
		$from = wc_get_order( $from->get_id() );
		foreach ( $keys as $key ) {
			$to->update_meta_data( $key, $from->get_meta( $key, true ) );
		}
		$to->save();

		return $to;
	}

	/** A reference that is truly two orders' own cannot be settled by guessing. */
	public function test_a_reference_two_orders_claim_is_refused() {
		$reference = 'DUP-' . $this->unused_id();
		$first     = $this->order( 'A-' . wp_generate_password( 6, false ) );
		$second    = $this->order( 'B-' . wp_generate_password( 6, false ) );
		foreach ( array( $first, $second ) as $order ) {
			oko_keep_booked_reference( $order, $reference );
			$order->save();
		}

		$response = $this->delivered( $reference );

		$this->assertSame( 409, $this->status( $response ) );
		$this->assertSame( 'processing', $this->status_of( $first ) );
		$this->assertSame( 'processing', $this->status_of( $second ) );
	}

	/**
	 * A reference copied onto a second order — by a duplicate-order plugin, or
	 * a subscription renewal copying its parent's meta — is not the copy's. The
	 * webhook finds the order it was written for.
	 */
	public function test_a_copied_reference_does_not_make_the_copy_the_booked_order() {
		$reference = 'DUP-' . $this->unused_id();
		$original  = $this->book( $this->order_to_book( $reference ) );
		$copy      = $this->copy_meta( $original, $this->order( 'C-' . wp_generate_password( 6, false ) ), array( OKO_SHIPMENT_REFERENCE_META, OKO_SHIPMENT_REFERENCE_FOR_META ) );

		$response = $this->delivered( $reference );

		$this->assertSame( 200, $this->status( $response ) );
		$this->assertSame( 'completed', $this->status_of( $original ) );
		$this->assertSame( 'processing', $this->status_of( $copy ) );
	}

	/** Cancelling the copy must not cancel the original's shipment. */
	public function test_cancelling_a_copy_leaves_the_originals_shipment_alone() {
		$reference = 'ORIG-' . $this->unused_id();
		$original  = $this->book( $this->order_to_book( $reference ) );
		$number    = 'COPY-' . wp_generate_password( 6, false );
		$copy      = $this->copy_meta( $original, $this->order( $number ), array( OKO_SHIPMENT_REFERENCE_META, OKO_SHIPMENT_REFERENCE_FOR_META, 'billing_okoskabet_done' ) );
		$this->requests = array();

		wc_get_order( $copy->get_id() )->update_status( 'cancelled' );

		$deletes = $this->requests_by( 'DELETE' );
		$this->assertCount( 1, $deletes );
		$this->assertStringEndsWith( '/api/v1/shipments/' . $number, $deletes[0]['url'], 'the copy is only ever its own number' );
	}

	/** Nor may an edit to the copy be sent over the original's shipment. */
	public function test_editing_a_copy_does_not_overwrite_the_originals_shipment() {
		$reference = 'ORIG-' . $this->unused_id();
		$original  = $this->book( $this->order_to_book( $reference ) );
		$number    = 'COPY-' . wp_generate_password( 6, false );
		$this->requests = array();

		$copy = $this->copy_meta( $original, $this->order_to_book( $number ), array( OKO_SHIPMENT_REFERENCE_META, OKO_SHIPMENT_REFERENCE_FOR_META, 'billing_okoskabet_done', OKO_SENT_FINGERPRINT_META ) );
		$copy->update_meta_data( '_billing_okoskabet_delivery_date', '2026-10-30' );
		$copy->save();

		$puts = $this->requests_by( 'PUT' );
		$this->assertNotEmpty( $puts, 'the copied fingerprint is not the copy\'s, so it reads as an edit' );
		foreach ( $puts as $put ) {
			$this->assertStringEndsWith( '/api/v1/shipments/' . $number, $put['url'] );
			$this->assertSame( $number, json_decode( $put['body'], true )['shipment_reference'] );
		}
	}

	/**
	 * An order found by number is given the reference to keep, so it is found
	 * directly from then on — even after the number changes.
	 */
	public function test_an_old_order_found_by_number_keeps_the_reference() {
		$reference = 'OLD-' . $this->unused_id();
		$order     = $this->order( $reference );
		$order->update_meta_data( 'billing_okoskabet_done', true );
		$order->save();
		$order->update_status( 'on-hold' );

		$this->assertSame( 200, $this->status( $this->delivered( $reference ) ) );
		$this->assertSame( $reference, oko_booked_reference( wc_get_order( $order->get_id() ) ) );

		$order = wc_get_order( $order->get_id() );
		$order->update_meta_data( '_order_number', 'NEW-' . $order->get_id() );
		$order->update_status( 'processing' );

		$this->assertSame( 200, $this->status( $this->delivered( $reference ) ) );
		$this->assertSame( 'completed', $this->status_of( $order ) );
	}

	/** A trashed order is still found, as before, and left as it is. */
	public function test_a_trashed_order_is_found_and_left_alone() {
		$reference = sprintf( '%09d', $this->unused_id() );
		$order     = $this->order( $reference );
		$id        = $order->get_id();
		$order->delete( false ); // To the trash; this also zeroes the object's id.

		$response = $this->delivered( $reference );

		$this->assertSame( 200, $this->status( $response ), 'the order exists; Økoskabet must not be told otherwise' );
		$this->assertSame( 'trash', wc_get_order( $id )->get_status() );
	}

	/**
	 * A reference copied onto an order that has not been booked yet is not its
	 * own. Booking sends the order's own number and keeps that instead.
	 */
	public function test_booking_ignores_a_reference_copied_from_another_order() {
		$order = $this->order_to_book( 'MINE-1' );
		$order->update_meta_data( OKO_SHIPMENT_REFERENCE_META, 'SOMEONE-ELSES' );
		$order->save();

		$order = $this->book( $order );

		$posts = $this->requests_by( 'POST' );
		$this->assertCount( 1, $posts );
		$this->assertSame( 'MINE-1', json_decode( $posts[0]['body'], true )['shipment_reference'] );
		$this->assertSame( 'MINE-1', oko_booked_reference( $order ) );
	}

	/**
	 * Another order type sharing the id space — a subscription is a WC_Order
	 * too, and its number is its id. Stood in for here by a type of our own,
	 * since WooCommerce Subscriptions is not installed in the test site.
	 */
	public function test_an_order_of_another_type_is_never_the_booked_order() {
		if ( ! wc_get_order_type( 'shop_oko_test' ) ) {
			wc_register_order_type(
				'shop_oko_test',
				array(
					'public'     => false,
					'class_name' => 'Oko_Test_Other_Order',
				)
			);
		}

		$other = new \Oko_Test_Other_Order();
		$other->set_status( 'processing' );
		$other->set_date_paid( time() );
		$other->save();
		$this->assertInstanceOf( \Oko_Test_Other_Order::class, wc_get_order( $other->get_id() ), 'the stand-in has to load as its own type' );

		$booked = $this->order( (string) $other->get_id() );

		$response = $this->delivered( (string) $other->get_id() );

		$this->assertSame( 200, $this->status( $response ) );
		$this->assertSame( 'completed', $this->status_of( $booked ) );
		$this->assertSame( 'processing', wc_get_order( $other->get_id() )->get_status() );
	}

	/** A shop without a numbering plugin goes on as before: number and id are one. */
	public function test_a_shop_without_a_numbering_plugin_is_unchanged() {
		remove_filter( 'woocommerce_order_number', array( $this, 'numbering_plugin' ), 10 );
		$order = $this->order();

		$response = $this->delivered( (string) $order->get_id() );

		$this->assertSame( 200, $this->status( $response ) );
		$this->assertSame( 'completed', $this->status_of( $order ) );
	}
}

/** A WC_Order of another type, the way a subscription is one. */
class Oko_Test_Other_Order extends \WC_Order {
	public function get_type() {
		return 'shop_oko_test';
	}
}
