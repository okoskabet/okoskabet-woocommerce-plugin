<?php

/**
 * Booking an order with Økoskabet the way the plugin does it.
 *
 * A webhook only ever names an order the plugin booked, and the webhook acts
 * on nothing else: an order found by its number has to carry the plugin's own
 * mark that it was booked. A test that sends a webhook therefore books its
 * order first, through the plugin's real booking path, with Økoskabet stubbed
 * to accept it. The order's status is left as the test made it.
 */
trait BooksWithOkoskabet {

	/** Booked by the plugin as it is now: the order keeps the reference it went out under. */
	protected function book_with_okoskabet( \WC_Order $order ): \WC_Order {
		$id = $order->get_id();
		$order->update_meta_data( '_billing_okoskabet_delivery_date', '2026-10-23' );
		$order->update_meta_data( '_billing_okoskabet_shed_id', '42' );
		$shipping = new \WC_Order_Item_Shipping();
		$shipping->set_method_id( 'hey_okoskabet_shipping_shed' );
		$shipping->set_method_title( 'Skab' );
		$order->add_item( $shipping );
		$order->save();

		$bookings  = 0;
		$okoskabet = static function ( $preempt, $args, $url ) use ( &$bookings ) {
			if ( strpos( (string) $url, '/api/v1/shipments' ) === false ) {
				return $preempt;
			}
			$method = (string) ( $args['method'] ?? 'GET' );
			if ( $method === 'POST' ) {
				++$bookings;
			}
			$code = $method === 'POST' ? 201 : ( $method === 'DELETE' ? 204 : 200 );

			return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null );
		};
		$api_key = static function ( $settings ) {
			$settings = (array) $settings;
			if ( empty( $settings['_api_key'] ) ) {
				$settings['_api_key'] = 'test-key';
			}

			return $settings;
		};

		add_filter( 'pre_http_request', $okoskabet, 1, 3 );
		// A shop on the legacy single-merchant settings books with its key from there.
		add_filter( 'o_get_settings', $api_key );
		try {
			// What `woocommerce_order_status_changed` calls when the order is paid.
			hey_after_order_placed( $id, $order->get_status(), 'processing', wc_get_order( $id ) );
		} finally {
			remove_filter( 'pre_http_request', $okoskabet, 1 );
			remove_filter( 'o_get_settings', $api_key );
		}

		$booked = wc_get_order( $id );
		\PHPUnit\Framework\Assert::assertSame( 1, $bookings, 'the plugin books the order with Økoskabet' );
		\PHPUnit\Framework\Assert::assertNotEmpty( $booked->get_meta( 'billing_okoskabet_done', true ), 'and marks it booked' );

		return $booked;
	}

	/**
	 * Booked by the plugin as it was before it kept the reference (1.5.2 and
	 * earlier): marked booked, and nothing else said about what it went out
	 * under — which was its number at the time.
	 */
	protected function book_before_the_reference_was_kept( \WC_Order $order ): \WC_Order {
		$booked = $this->book_with_okoskabet( $order );
		$booked->delete_meta_data( OKO_SHIPMENT_REFERENCE_META );
		$booked->delete_meta_data( OKO_SHIPMENT_REFERENCE_FOR_META );
		$booked->save();

		return wc_get_order( $booked->get_id() );
	}
}
