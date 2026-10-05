<?php

/**
 * The webhook acts on the signed body, and on nothing else.
 *
 * The signature covers the raw body, but the fields used to be read with
 * `get_params()`, which merges the query string in on top. A body sent as
 * text/plain is not parsed as JSON at all, so the query string won outright.
 * Anyone holding a single captured signed request could keep the signature and
 * replace what it was meant to protect: point it at another order, say the
 * parcel was delivered, and have the order completed and the card charged.
 */
class WebhookSignedBodyTest extends \Codeception\TestCase\WPTestCase {

	const SECRET = 'a-webhook-secret';

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );
		do_action( 'rest_api_init' );

		$settings                     = (array) get_option( O_TEXTDOMAIN . '_options', array() );
		$settings['_webhook_enabled'] = 'on';
		$settings['_webhook_secret']  = self::SECRET;
		update_option( O_TEXTDOMAIN . '_options', $settings );
	}

	private function completed_order(): \WC_Order {
		$order = new \WC_Order();
		$order->set_status( 'processing' );
		$order->save();

		return $order;
	}

	/** A request signed over its body, the way Økoskabet sends one. */
	private function signed_request( array $body, array $query = array(), string $content_type = 'application/json' ): \WP_REST_Request {
		$raw = wp_json_encode( $body );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/okoskabet/webhook' );
		$request->set_header( 'content-type', $content_type );
		$request->set_header( 'x-hmac-sha256', hash_hmac( 'sha256', $raw, self::SECRET ) );
		$request->set_body( $raw );

		if ( ! empty( $query ) ) {
			$request->set_query_params( $query );
		}

		return $request;
	}

	public function test_a_signed_delivery_completes_the_order() {
		$order = $this->completed_order();

		$response = rest_do_request( $this->signed_request( array(
			'event'              => 'reservation_updated',
			'shipment_reference' => (string) $order->get_id(),
			'changes'            => array( 'status' => array( 'value' => 'delivered' ) ),
		) ) );

		$this->assertSame( 200, $response->get_status(), 'the ordinary path still works' );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * The attack, as reported: one captured signed request, replayed with the
	 * fields moved into the query string and a content type that stops the
	 * body being parsed.
	 */
	public function test_the_query_string_cannot_speak_for_the_signature() {
		$untouched = $this->completed_order();

		$response = rest_do_request( $this->signed_request(
			array( 'event' => 'shipment_created' ),
			array(
				'event'              => 'reservation_updated',
				'shipment_reference' => (string) $untouched->get_id(),
				'changes'            => array( 'status' => array( 'value' => 'delivered' ) ),
			),
			'text/plain'
		) );

		$this->assertNotSame(
			'completed',
			wc_get_order( $untouched->get_id() )->get_status(),
			'an order named only in the query string must not be touched'
		);

		$this->assertContains(
			$response->get_status(),
			array( 400, 422 ),
			'and the request is refused rather than quietly ignored'
		);
	}

	public function test_a_body_that_is_not_json_is_refused() {
		$raw     = 'event=reservation_updated';
		$request = new \WP_REST_Request( 'POST', '/wp/v2/okoskabet/webhook' );
		$request->set_header( 'content-type', 'text/plain' );
		$request->set_header( 'x-hmac-sha256', hash_hmac( 'sha256', $raw, self::SECRET ) );
		$request->set_body( $raw );

		$response = rest_do_request( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_wrong_signature_is_still_refused() {
		$order = $this->completed_order();

		$raw     = wp_json_encode( array(
			'event'              => 'reservation_updated',
			'shipment_reference' => (string) $order->get_id(),
			'changes'            => array( 'status' => array( 'value' => 'delivered' ) ),
		) );
		$request = new \WP_REST_Request( 'POST', '/wp/v2/okoskabet/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-hmac-sha256', hash_hmac( 'sha256', $raw, 'the-wrong-secret' ) );
		$request->set_body( $raw );

		$response = rest_do_request( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}
}
