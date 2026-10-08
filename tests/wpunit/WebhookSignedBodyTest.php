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
 *
 * See GHSA-4qrw-5q76-hwp3.
 */
class WebhookSignedBodyTest extends \Codeception\TestCase\WPTestCase {

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

	private function order( string $status = 'processing' ): \WC_Order {
		$order = new \WC_Order();
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/** The body Økoskabet sends when a shipment reaches a step. */
	private function delivered_body( \WC_Order $order ): array {
		return array(
			'event'              => 'reservation_updated',
			'shipment_reference' => (string) $order->get_id(),
			'changes'            => array( 'status' => array( 'value' => 'fulfilled' ) ),
		);
	}

	/**
	 * A request signed over its body, the way Økoskabet sends one.
	 *
	 * The handler is called directly rather than through `rest_do_request`:
	 * WPLoader boots WordPress past the point where the plugin registers its
	 * routes, so the route does not exist in the harness. What the route adds
	 * is the URL; everything this test is about happens inside the handler.
	 */
	private function send( array $body, array $query = array(), string $content_type = 'application/json', string $secret = self::SECRET ) {
		$raw = wp_json_encode( $body );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/okoskabet/webhook' );
		$request->set_header( 'content-type', $content_type );
		$request->set_header( 'x-hmac-sha256', hash_hmac( 'sha256', $raw, $secret ) );
		$request->set_body( $raw );

		if ( ! empty( $query ) ) {
			$request->set_query_params( $query );
		}

		return ( new \okoskabet_woocommerce_plugin\Rest\OkoRest() )->handle_webhook( $request );
	}

	/**
	 * The HTTP status, whether the handler answered or refused. A plain array
	 * is the handler's ordinary answer; WordPress turns it into a 200.
	 */
	private function status( $result ): int {
		if ( $result instanceof \WP_Error ) {
			$data = $result->get_error_data();

			return (int) ( is_array( $data ) ? ( $data['status'] ?? 0 ) : 0 );
		}

		return $result instanceof \WP_REST_Response ? (int) $result->get_status() : 200;
	}

	private function status_of( \WC_Order $order ): string {
		return wc_get_order( $order->get_id() )->get_status();
	}

	public function test_a_signed_delivery_completes_the_order() {
		$order = $this->order();

		$response = $this->send( $this->delivered_body( $order ) );

		$this->assertSame( 200, $this->status( $response ), 'the ordinary path still works' );
		$this->assertSame( 'completed', $this->status_of( $order ) );
	}

	/**
	 * The attack, as reported: one captured signed request, replayed with the
	 * fields moved into the query string and a content type that stops the
	 * body being parsed. The signature still checks out — it covers the body,
	 * and the body is untouched — so only reading the body can stop it.
	 */
	public function test_the_query_string_cannot_speak_for_the_signature() {
		$signed_for = $this->order();
		$victim     = $this->order();

		$this->send(
			$this->delivered_body( $signed_for ),
			$this->delivered_body( $victim ),
			'text/plain'
		);

		$this->assertSame(
			'processing',
			$this->status_of( $victim ),
			'an order named only in the query string must not be touched'
		);
		$this->assertSame(
			'completed',
			$this->status_of( $signed_for ),
			'and the order the signature actually covers is the one acted on'
		);
	}

	public function test_a_body_that_is_not_json_is_refused() {
		$raw     = 'event=reservation_updated';
		$request = new \WP_REST_Request( 'POST', '/wp/v2/okoskabet/webhook' );
		$request->set_header( 'content-type', 'text/plain' );
		$request->set_header( 'x-hmac-sha256', hash_hmac( 'sha256', $raw, self::SECRET ) );
		$request->set_body( $raw );

		$response = ( new \okoskabet_woocommerce_plugin\Rest\OkoRest() )->handle_webhook( $request );

		$this->assertSame( 400, $this->status( $response ) );
	}

	public function test_a_wrong_signature_is_still_refused() {
		$order = $this->order();

		$response = $this->send( $this->delivered_body( $order ), array(), 'application/json', 'the-wrong-secret' );

		$this->assertSame( 401, $this->status( $response ) );
		$this->assertSame( 'processing', $this->status_of( $order ) );
	}
}
