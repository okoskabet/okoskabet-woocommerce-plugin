<?php

/**
 * What the chosen steps actually do.
 *
 * `StatusEventsTest` covers the translation of the old names. This one covers
 * the behaviour that translation is in service of: that a step a shop ticks
 * today is the step that fires, that an order the shop has already settled is
 * left alone, and that "no step completes my orders" is an answer the settings
 * can hold.
 */
class StatusEventBehaviourTest extends \Codeception\TestCase\WPTestCase {

	const SECRET = 'a-webhook-secret';

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );

		delete_option( OKO_SETTINGS_SAVED_OPTION );
	}

	private function configure( array $webhook_events ): void {
		$settings = (array) get_option( O_TEXTDOMAIN . '-settings', array() );

		$settings['_webhook_enabled'] = 'on';
		$settings['_webhook_secret']  = self::SECRET;
		$settings['_webhook_events']  = $webhook_events;
		$settings['_capture_events']  = array();

		update_option( O_TEXTDOMAIN . '-settings', $settings );
	}

	private function order( string $status ): \WC_Order {
		$order = new \WC_Order();
		$order->set_status( $status );
		$order->save();

		return $order;
	}

	/**
	 * A status change, signed the way Økoskabet signs one.
	 *
	 * The handler is called directly rather than through `rest_do_request`:
	 * WPLoader boots WordPress past the point where the plugin registers its
	 * routes, so the route does not exist in the harness. What the route adds
	 * is the URL; everything this test is about happens inside the handler.
	 */
	private function send_status( \WC_Order $order, string $status ) {
		$raw = wp_json_encode( array(
			'event'              => 'reservation_updated',
			'shipment_reference' => (string) $order->get_id(),
			'changes'            => array( 'status' => array( 'value' => $status ) ),
		) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/okoskabet/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-hmac-sha256', hash_hmac( 'sha256', $raw, self::SECRET ) );
		$request->set_body( $raw );

		return ( new \okoskabet_woocommerce_plugin\Rest\OkoRest() )->handle_webhook( $request );
	}

	/**
	 * The step is called "I skabet" because it means the box is in the shed.
	 * Rewriting a freshly ticked choice to `fulfilled` made that step
	 * unreachable: it could be ticked, and it could never fire.
	 */
	public function test_a_shop_that_ticks_in_shed_gets_in_shed() {
		$this->configure( array( 'in_shed' ) );
		$order = $this->order( 'processing' );

		$this->send_status( $order, 'in_shed' );

		$this->assertSame(
			'completed',
			wc_get_order( $order->get_id() )->get_status(),
			'the step the shop chose is the step that fires'
		);
	}

	public function test_the_step_a_shop_did_not_tick_does_nothing() {
		$this->configure( array( 'in_shed' ) );
		$order = $this->order( 'processing' );

		$this->send_status( $order, 'fulfilled' );

		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * A parcel can turn up in a courier's feed long after the shop cancelled
	 * the order. Completing it then tells the customer their cancelled order
	 * is on its way, and on a gateway that charges at completion it asks for
	 * money with nothing behind it.
	 */
	public function test_a_cancelled_order_is_left_alone() {
		$this->configure( array( 'fulfilled' ) );
		$order = $this->order( 'cancelled' );

		$this->send_status( $order, 'fulfilled' );

		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_a_refunded_order_is_left_alone() {
		$this->configure( array( 'fulfilled' ) );
		$order = $this->order( 'refunded' );

		$this->send_status( $order, 'fulfilled' );

		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status() );
	}

	public function test_an_order_nobody_has_paid_for_is_not_finished() {
		$this->configure( array( 'fulfilled' ) );
		$order = $this->order( 'on-hold' );

		$this->send_status( $order, 'fulfilled' );

		$this->assertSame(
			'on-hold',
			wc_get_order( $order->get_id() )->get_status(),
			'a bank transfer nobody has confirmed is not a finished order'
		);
	}

	public function test_a_paid_order_is_still_completed() {
		$this->configure( array( 'fulfilled' ) );
		$order = $this->order( 'processing' );

		$this->send_status( $order, 'fulfilled' );

		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * CMB2 shows a default whenever nothing is stored, and a multicheck with
	 * every box unticked stores nothing. Without somewhere to record that the
	 * form has been saved, "no step completes my orders" could not be said.
	 */
	public function test_a_fresh_install_gets_the_suggested_step() {
		$this->assertSame( array( 'fulfilled' ), oko_event_default( array( 'fulfilled' ) ) );
	}

	public function test_a_shop_that_has_saved_can_leave_it_empty() {
		oko_remember_settings_were_saved();

		$this->assertSame(
			array(),
			oko_event_default( array( 'fulfilled' ) ),
			'once the shop has been through the form, empty means empty'
		);
	}
}
