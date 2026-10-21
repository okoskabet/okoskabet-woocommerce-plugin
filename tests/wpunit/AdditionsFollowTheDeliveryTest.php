<?php

require_once __DIR__ . '/BooksWithOkoskabet.php';

/**
 * An addition goes the way of the delivery it rides on.
 *
 * It is its own WooCommerce order with its own payment, but it has no
 * shipment of its own, so no webhook ever names it. The step that completes
 * or charges the earlier order has to complete or charge its additions too —
 * or an addition paid by card is never captured, and stays "processing" long
 * after the box it was packed in has been delivered.
 */
class AdditionsFollowTheDeliveryTest extends \Codeception\TestCase\WPTestCase {

	use BooksWithOkoskabet;

	const SECRET = 'a-webhook-secret';

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );

		delete_option( OKO_SETTINGS_SAVED_OPTION );

		$settings = (array) get_option( O_TEXTDOMAIN . '-settings', array() );

		$settings['_webhook_enabled'] = 'on';
		$settings['_webhook_secret']  = self::SECRET;
		$settings['_webhook_events']  = array( 'fulfilled' );
		$settings['_capture_events']  = array();

		update_option( O_TEXTDOMAIN . '-settings', $settings );
	}

	/**
	 * What this request was told about the account is dropped again. The memo
	 * lasts the PHP process, which here is the whole suite, and a test that
	 * comes after would otherwise read the configuration this one never set.
	 */
	public function tearDown(): void {
		// Also drops the memo inside oko_merchant_configuration().
		\okoskabet_woocommerce_plugin\Integrations\Merchants::purge_config_cache();
		parent::tearDown();
	}

	public function test_a_delivered_box_completes_its_additions_too(): void {
		$parent   = $this->booked( 'processing' );
		$addition = $this->addition_to( $parent, 'processing' );

		$response = $this->delivered( $parent );

		$this->assertSame( 'completed', wc_get_order( $parent->get_id() )->get_status() );
		$this->assertSame( 'completed', wc_get_order( $addition->get_id() )->get_status() );
		$this->assertSame( array( (string) $addition->get_id() => 'completed' ), $response['additions'] ?? null );
	}

	/** The same guards as the order itself: an unpaid addition is not called finished. */
	public function test_an_unpaid_addition_is_not_completed(): void {
		$parent   = $this->booked( 'processing' );
		$addition = $this->addition_to( $parent, 'on-hold' );

		$this->delivered( $parent );

		$this->assertSame( 'on-hold', wc_get_order( $addition->get_id() )->get_status() );
	}

	/** An order with no additions answers as it always did. */
	public function test_an_order_without_additions_is_unchanged(): void {
		$parent = $this->booked( 'processing' );

		$response = $this->delivered( $parent );

		$this->assertSame( 'completed', wc_get_order( $parent->get_id() )->get_status() );
		$this->assertSame( array(), $response['additions'] ?? null );
	}

	private function booked( string $status ): \WC_Order {
		$order = new \WC_Order();
		$order->set_status( $status );
		$order->save();

		return $this->book_with_okoskabet( $order );
	}

	private function addition_to( \WC_Order $parent, string $status ): \WC_Order {
		$addition = new \WC_Order();
		$addition->set_status( $status );
		$addition->update_meta_data( OKO_ADDON_PARENT_ID_META, (string) $parent->get_id() );
		$addition->update_meta_data( 'billing_okoskabet_done', 'yes' );
		$addition->save();

		$parent = wc_get_order( $parent->get_id() );
		$parent->update_meta_data( OKO_ADDITIONS_META, array( $addition->get_id() ) );
		$parent->save();

		return $addition;
	}

	/** The signed `fulfilled` webhook for the earlier order's shipment. */
	private function delivered( \WC_Order $order ) {
		$raw = wp_json_encode( array(
			'event'              => 'reservation_updated',
			'shipment_reference' => oko_shipment_reference( wc_get_order( $order->get_id() ) ),
			'changes'            => array( 'status' => array( 'value' => 'fulfilled' ) ),
		) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/okoskabet/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-hmac-sha256', strtoupper( hash_hmac( 'sha256', $raw, self::SECRET ) ) );
		$request->set_body( $raw );

		return ( new \okoskabet_woocommerce_plugin\Rest\OkoRest() )->handle_webhook( $request );
	}
}
