<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit\Integrations;

use okoskabet_woocommerce_plugin\Integrations\Merchants;
use okoskabet_woocommerce_plugin\Integrations\Payment_Capture;
use okoskabet_woocommerce_plugin\Rest\OkoRest;

/**
 * Tests for Payment_Capture::capture(), which Økoskabet's capture webhook
 * calls when a parcel reaches the configured capture event.
 *
 * Capturing moves the order to processing, a paid status. That is right
 * for a card payment that was only authorised, and wrong for an offline
 * method (bank transfer, cheque, cash on delivery): there is nothing to
 * capture, and the order would be reported paid before any money arrived.
 */
class PaymentCaptureTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		add_filter( 'pre_wp_mail', '__return_false' );
		delete_option( Merchants::OPTION_KEY );
		delete_option( O_TEXTDOMAIN . '-settings' );
		Merchants::purge_config_cache();
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', '__return_false' );
		Merchants::purge_config_cache();
		parent::tearDown();
	}

	public function offline_methods(): array {
		return array(
			'bank transfer'    => array( 'bacs' ),
			'cheque'           => array( 'cheque' ),
			'cash on delivery' => array( 'cod' ),
		);
	}

	/**
	 * @test
	 * @dataProvider offline_methods
	 */
	public function an_unpaid_offline_order_on_hold_is_left_unpaid( string $method ) {
		$order = $this->order( $method, 'on-hold' );

		$result = Payment_Capture::capture( $order, 'auto' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
		$this->assertNull( $order->get_date_paid() );
		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'offline payment method', $result['message'] );
	}

	/**
	 * @test
	 */
	public function a_pending_bank_transfer_is_left_pending() {
		$order = $this->order( 'bacs', 'pending' );

		Payment_Capture::capture( $order, 'auto' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertNull( $order->get_date_paid() );
	}

	/**
	 * The merchant's gateway setting is a hint for which card gateway to
	 * capture through. It must not override the method the order was
	 * actually paid with.
	 *
	 * @test
	 */
	public function a_bank_transfer_is_left_unpaid_even_when_the_merchant_names_a_card_gateway() {
		$order = $this->order( 'bacs', 'on-hold' );

		Payment_Capture::capture( $order, 'quickpay' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * The shop marked the bank transfer paid by hand. Capture must neither
	 * undo that nor act on it again.
	 *
	 * @test
	 */
	public function a_bank_transfer_the_shop_already_marked_paid_is_left_alone() {
		$order      = $this->order( 'bacs', 'processing' );
		$date_paid  = $order->get_date_paid();
		$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$result = Payment_Capture::capture( $order, 'auto' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertNotNull( $date_paid );
		$this->assertEquals( $date_paid, $order->get_date_paid() );
		$this->assertCount( $note_count, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
		$this->assertSame( array( 'success' => true, 'message' => 'Order already paid' ), $result );
	}

	/**
	 * @test
	 */
	public function a_card_order_is_still_captured() {
		$order = $this->order( 'stripe', 'on-hold', 'pi_123' );

		$result = Payment_Capture::capture( $order, 'auto' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( array( 'success' => true, 'message' => 'Stripe capture triggered via status change' ), $result );
	}

	/**
	 * A gateway the plugin has no special case for falls through to the
	 * status change, which most gateways capture on.
	 *
	 * @test
	 */
	public function an_order_from_an_unlisted_online_gateway_is_still_captured() {
		$order = $this->order( 'some_card_gateway', 'on-hold', 'tx_1' );

		$result = Payment_Capture::capture( $order, 'auto' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( array( 'success' => true, 'message' => 'Capture triggered via order status change to processing' ), $result );
	}

	/**
	 * @test
	 */
	public function a_shop_can_name_another_gateway_as_offline() {
		$filter = function ( $methods ) {
			$methods[] = 'ean_invoice';
			return $methods;
		};
		add_filter( 'okoskabet_offline_payment_methods', $filter );
		$order = $this->order( 'ean_invoice', 'on-hold' );

		Payment_Capture::capture( $order, 'auto' );
		remove_filter( 'okoskabet_offline_payment_methods', $filter );

		$this->assertSame( 'on-hold', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * End to end through the webhook: the bank transfer stays on hold and
	 * the order notes say why.
	 *
	 * @test
	 */
	public function the_capture_webhook_leaves_a_bank_transfer_on_hold_and_says_why() {
		Merchants::save_config( array(
			'version'             => 1,
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array(
					'id'              => 'default',
					'label'           => 'Default',
					'api_key'         => 'KEY',
					'webhook_secret'  => 'SECRET',
					'payment_gateway' => 'auto',
					'capture_events'  => array( 'label_printed' ),
					'webhook_events'  => array( 'order_delivered' ),
				),
			),
		) );
		$settings                     = (array) get_option( O_TEXTDOMAIN . '-settings', array() );
		$settings['_webhook_enabled'] = 'on';
		update_option( O_TEXTDOMAIN . '-settings', $settings );

		$order = $this->order( 'bacs', 'on-hold' );
		$body  = wp_json_encode( array(
			'event'              => 'reservation_updated',
			'shipment_reference' => (string) $order->get_id(),
			'changes'            => array( 'parcels' => array( 'previous' => array(), 'value' => array( array( 'id' => 1 ) ) ) ),
		) );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/okoskabet/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-hmac-sha256', hash_hmac( 'sha256', $body, 'SECRET' ) );
		$request->set_body( $body );

		$response = ( new OkoRest() )->handle_webhook( $request );

		$this->assertIsArray( $response );
		$this->assertSame( 'on-hold', $response['new_status'] );
		$this->assertFalse( wc_get_order( $order->get_id() )->is_paid() );
		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'limit' => 1 ) );
		$this->assertStringContainsString( 'offline payment method', $notes[0]->content );
	}

	private function order( string $method, string $status, string $transaction_id = '' ): \WC_Order {
		$product = new \WC_Product_Simple();
		$product->set_name( 'Kasse' );
		$product->set_regular_price( '159' );
		$product->save();

		$order = wc_create_order();
		$order->add_product( $product, 1 );
		$order->set_payment_method( $method );
		if ( $transaction_id !== '' ) {
			$order->set_transaction_id( $transaction_id );
		}
		$order->calculate_totals();
		if ( $status !== 'pending' ) {
			$order->update_status( $status );
		}

		return wc_get_order( $order->get_id() );
	}
}
