<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit\Integrations;

use okoskabet_woocommerce_plugin\Integrations\Merchants;
use okoskabet_woocommerce_plugin\Rest\OkoRest;

require_once __DIR__ . '/../BooksWithOkoskabet.php';

/**
 * The events do not arrive in the order the parcel moves.
 *
 * A shop picks one step to take the money and one to finish the order. With
 * the suggested pair — take it at `label_printed`, finish at `fulfilled` — the
 * two are far enough apart that they never cross. A shop that takes the money
 * at `received` is not so lucky: Økoskabet sends each step on its own and
 * tries again after a 5xx, so `fulfilled` can turn up before `received` has
 * got through. The order is not paid yet, so it is rightly not finished — and
 * when `received` then takes the money, nothing was left to finish it. The
 * parcel is in the customer's hands and the order sits in processing for good.
 *
 * What must hold, whatever the order: an order is finished once it is paid
 * and the finishing step has come, and never before both.
 */
class WebhookEventOrderTest extends \Codeception\TestCase\WPTestCase {

	use \BooksWithOkoskabet;

	const SECRET = 'SECRET';

	const STEPS = array( 'received', 'label_printed', 'fulfilled' );

	public function setUp(): void {
		parent::setUp();
		add_filter( 'pre_wp_mail', '__return_false' );
		delete_option( Merchants::OPTION_KEY );
		update_option( O_TEXTDOMAIN . '-settings', array( '_webhook_enabled' => 'on' ) );
		Merchants::purge_config_cache();
	}

	public function tearDown(): void {
		remove_filter( 'pre_wp_mail', '__return_false' );
		Merchants::purge_config_cache();
		if ( class_exists( 'WC_QuickPay_Order' ) && property_exists( 'WC_QuickPay_Order', 'refuse' ) ) {
			\WC_QuickPay_Order::$refuse = false;
		}
		parent::tearDown();
	}

	/**
	 * Stands in for the Quickpay plugin, whose capture can be told to refuse.
	 * It outlives the test, so it only refuses while a test asks it to and
	 * tearDown() puts it back.
	 */
	private function quickpay_refuses(): void {
		if ( ! class_exists( 'WC_QuickPay_Order' ) ) {
			class_alias(
				get_class( new class( 0 ) {
					public static $refuse = false;
					public function __construct( $order_id ) {}
					public function capture() {
						if ( self::$refuse ) {
							throw new \RuntimeException( 'Afvist' );
						}
					}
				} ),
				'WC_QuickPay_Order'
			);
		}
		\WC_QuickPay_Order::$refuse = true;
	}

	private function configure( array $capture_events, array $webhook_events ): void {
		Merchants::save_config( array(
			'version'             => 1,
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array(
					'id'              => 'default',
					'label'           => 'Default',
					'api_key'         => 'KEY',
					'webhook_secret'  => self::SECRET,
					'payment_gateway' => 'auto',
					'capture_events'  => $capture_events,
					'webhook_events'  => $webhook_events,
				),
			),
		) );
	}

	/**
	 * A card payment that has been authorised and waits to be taken, unless
	 * told otherwise. An unknown card gateway is captured by moving the order
	 * to processing, which is all the plugin can do for most of them.
	 */
	private function order( string $status = 'on-hold', string $method = 'some_card_gateway' ): \WC_Order {
		$order = new \WC_Order();
		$order->set_payment_method( $method );
		$order->set_status( $status );
		$order->save();

		return $this->book_with_okoskabet( $order );
	}

	/** One step, sent and signed the way Økoskabet sends it. */
	private function send( \WC_Order $order, string $step ) {
		$changes = $step === 'label_printed'
			? array( 'parcels' => array( 'previous' => array(), 'value' => array( array( 'id' => 1 ) ) ) )
			: array( 'status' => array( 'value' => $step ) );

		$body = wp_json_encode( array(
			'event'              => 'reservation_updated',
			'shipment_reference' => (string) $order->get_id(),
			'changes'            => $changes,
		) );

		$request = new \WP_REST_Request( 'POST', '/wp/v2/okoskabet/webhook' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-hmac-sha256', hash_hmac( 'sha256', $body, self::SECRET ) );
		$request->set_body( $body );

		return ( new OkoRest() )->handle_webhook( $request );
	}

	private function status( \WC_Order $order ): string {
		return wc_get_order( $order->get_id() )->get_status();
	}

	private function notes_containing( \WC_Order $order, string $needle ): int {
		$found = 0;
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			if ( strpos( $note->content, $needle ) !== false ) {
				$found++;
			}
		}

		return $found;
	}

	/**
	 * The case from the review of delivery_backend#1976: money at `received`,
	 * finished at `fulfilled`, and `received` held up by a retry.
	 */
	public function test_a_finish_that_came_before_the_money_is_used_when_the_money_is_taken() {
		$this->configure( array( 'received' ), array( 'fulfilled' ) );
		$order = $this->order();

		$this->send( $order, 'fulfilled' );
		$this->assertSame( 'on-hold', $this->status( $order ), 'nothing is finished before it is paid' );

		$this->send( $order, 'received' );
		$this->assertSame( 'completed', $this->status( $order ), 'the finish that came early is not lost' );
	}

	/**
	 * The early finish is remembered, not acted on. If taking the money comes
	 * to nothing — a bank transfer has nothing to take — the order stays
	 * unpaid and unfinished.
	 */
	public function test_a_capture_that_takes_nothing_finishes_nothing() {
		$this->configure( array( 'received' ), array( 'fulfilled' ) );
		$order = $this->order( 'on-hold', 'bacs' );

		$this->send( $order, 'fulfilled' );
		$this->send( $order, 'received' );

		$this->assertSame( 'on-hold', $this->status( $order ) );
		$this->assertSame(
			0,
			$this->notes_containing( $order, 'betalingen er ikke hævet endnu' ),
			'a bank transfer is not promised a finish that no capture can bring'
		);
	}

	/**
	 * A shop with no capture step takes its money some other way, so no
	 * capture event will ever come to finish the order.
	 */
	public function test_without_a_capture_step_nothing_is_promised() {
		$this->configure( array(), array( 'fulfilled' ) );
		$order = $this->order();

		$this->send( $order, 'fulfilled' );

		$this->assertSame( 'on-hold', $this->status( $order ) );
		$this->assertSame( 0, $this->notes_containing( $order, 'betalingen er ikke hævet endnu' ) );
	}

	/**
	 * The shop switched the finishing step off while an order was waiting.
	 * "No step completes my orders" holds for that order too.
	 */
	public function test_a_finishing_step_switched_off_while_waiting_finishes_nothing() {
		$this->configure( array( 'received' ), array( 'fulfilled' ) );
		$order = $this->order();

		$this->send( $order, 'fulfilled' );
		$this->configure( array( 'received' ), array() );
		$this->send( $order, 'received' );

		$this->assertSame( 'processing', $this->status( $order ) );
	}

	/**
	 * Quickpay moves the order to processing before it asks for the money, so
	 * a refused capture leaves an order that looks paid. The early finish
	 * must wait for a capture that went through — and the refusal ends the
	 * wait, or the next capture step would find the order looking paid and
	 * finish it without the money.
	 */
	public function test_a_refused_capture_finishes_nothing() {
		$this->quickpay_refuses();
		$this->configure( array( 'received', 'label_printed' ), array( 'fulfilled' ) );
		$order = $this->order( 'on-hold', 'quickpay' );
		$order->set_transaction_id( 'qp-1' );
		$order->save();

		$this->send( $order, 'fulfilled' );
		$this->send( $order, 'received' );
		$this->assertNotSame( 'completed', $this->status( $order ), 'not by the refused capture' );

		$this->send( $order, 'label_printed' );
		$this->assertNotSame( 'completed', $this->status( $order ), 'nor by the capture step after it' );
	}

	/**
	 * The shop moved the finishing step while an order was waiting. The old
	 * step no longer finishes anything, so the new one, when it comes before
	 * the money, has to be remembered in its place.
	 */
	public function test_a_newly_chosen_finishing_step_takes_the_place_of_the_old() {
		$this->configure( array( 'received' ), array( 'fulfilled' ) );
		$order = $this->order();

		$this->send( $order, 'fulfilled' );
		$this->configure( array( 'received' ), array( 'in_shed' ) );
		$this->send( $order, 'in_shed' );
		$this->send( $order, 'received' );

		$this->assertSame( 'completed', $this->status( $order ) );
	}

	/**
	 * Cancelled, reopened and sent again: the delivery that came before the
	 * cancellation is not the one that finishes the order now.
	 */
	public function test_a_cancelled_and_reopened_order_waits_for_its_new_delivery() {
		$this->configure( array( 'received' ), array( 'fulfilled' ) );
		$order = $this->order();

		$this->send( $order, 'fulfilled' );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		wc_get_order( $order->get_id() )->update_status( 'on-hold' );
		$this->send( $order, 'received' );

		$this->assertSame( 'processing', $this->status( $order ) );
	}

	/**
	 * Nothing but the finishing step finishes an order. A capture on its own
	 * leaves it in processing, however many other steps go by.
	 */
	public function test_without_the_finishing_step_the_order_is_only_paid() {
		$this->configure( array( 'received' ), array( 'fulfilled' ) );
		$order = $this->order();

		$this->send( $order, 'label_printed' );
		$this->send( $order, 'received' );
		$this->send( $order, 'label_printed' );

		$this->assertSame( 'processing', $this->status( $order ) );
	}

	/**
	 * A parcel can turn up in a courier's feed long after the shop cancelled
	 * the order. Neither step may bring it back, and the shop is not told the
	 * order will be finished.
	 */
	public function test_a_cancelled_order_stays_cancelled_whatever_the_order() {
		$this->configure( array( 'received' ), array( 'fulfilled' ) );
		$order = $this->order( 'cancelled' );

		$this->send( $order, 'fulfilled' );
		$this->send( $order, 'received' );

		$this->assertSame( 'cancelled', $this->status( $order ) );
		$this->assertSame(
			0,
			$this->notes_containing( $order, 'betalingen er ikke hævet endnu' ),
			'a cancelled order is not promised a finish'
		);
	}

	/**
	 * The finish came while the order was waiting for its money, and then the
	 * shop cancelled it. The capture step that follows must not bring it back.
	 */
	public function test_an_order_cancelled_while_it_waited_stays_cancelled() {
		$this->configure( array( 'received' ), array( 'fulfilled' ) );
		$order = $this->order();

		$this->send( $order, 'fulfilled' );
		wc_get_order( $order->get_id() )->update_status( 'cancelled' );
		$this->send( $order, 'received' );

		$this->assertSame( 'cancelled', $this->status( $order ) );
	}

	/**
	 * A failed payment can still be captured, so an early finish is kept for
	 * it as well and used once the capture goes through.
	 */
	public function test_a_failed_payment_that_is_captured_is_finished() {
		$this->configure( array( 'received' ), array( 'fulfilled' ) );
		$order = $this->order( 'failed' );

		$this->send( $order, 'fulfilled' );
		$this->assertSame( 'failed', $this->status( $order ) );

		$this->send( $order, 'received' );
		$this->assertSame( 'completed', $this->status( $order ) );
	}

	/**
	 * Retries are the reason this happens at all, so they come in twos and
	 * threes. The shop is told once that the order is waiting for its money,
	 * and the order is finished once.
	 */
	public function test_retries_are_told_and_finished_once() {
		$this->configure( array( 'received' ), array( 'fulfilled' ) );
		$order = $this->order();

		$this->send( $order, 'fulfilled' );
		$this->send( $order, 'fulfilled' );
		$this->send( $order, 'received' );
		$this->send( $order, 'received' );
		$this->send( $order, 'fulfilled' );

		$this->assertSame( 'completed', $this->status( $order ) );
		$this->assertSame( 1, $this->notes_containing( $order, 'betalingen er ikke hævet endnu' ) );
		$this->assertSame( 1, $this->notes_containing( $order, 'Ordre markeret som afsluttet' ) );
	}

	/**
	 * Every pairing of the two choices, every order the three steps can arrive
	 * in, from an order waiting for its money and from one the gateway already
	 * counts as paid. After each step the order must be exactly where the
	 * steps so far put it: finished once paid and the finishing step has come,
	 * paid once a capture step has come, and otherwise untouched.
	 *
	 * Each choice is one step or none, which is what shops pick, plus two
	 * pairings where one choice holds two steps. An order that is paid already
	 * never reaches a capture, so it is run once per finishing choice rather
	 * than once per pairing, and arrivals that differ only in steps neither
	 * choice names are run once. Every combination of every subset is 768
	 * runs and two minutes, and finds nothing these do not.
	 */
	public function test_every_setting_and_every_order_of_arrival() {
		$choices = array( array() );
		foreach ( self::STEPS as $step ) {
			$choices[] = array( $step );
		}

		$settings = array();
		foreach ( $choices as $capture ) {
			foreach ( $choices as $finish ) {
				$settings[] = array( $capture, $finish );
			}
		}
		$settings[] = array( array( 'received', 'label_printed' ), array( 'fulfilled' ) );
		$settings[] = array( array( 'label_printed' ), array( 'received', 'fulfilled' ) );

		$orders_of_arrival = array(
			array( 'received', 'label_printed', 'fulfilled' ),
			array( 'received', 'fulfilled', 'label_printed' ),
			array( 'label_printed', 'received', 'fulfilled' ),
			array( 'label_printed', 'fulfilled', 'received' ),
			array( 'fulfilled', 'received', 'label_printed' ),
			array( 'fulfilled', 'label_printed', 'received' ),
		);

		$wrong = array();
		foreach ( $settings as list( $capture, $finish ) ) {
			$this->configure( $capture, $finish );

			// A step neither choice names is ignored by the webhook, so only the
			// order of the named steps tells two arrivals apart.
			$named  = array_merge( $capture, $finish );
			$starts = empty( $capture ) ? array( 'on-hold', 'processing' ) : array( 'on-hold' );
			foreach ( $starts as $start ) {
				$tried = array();
				foreach ( $orders_of_arrival as $arrival ) {
					$key = implode( ',', array_intersect( $arrival, $named ) );
					if ( isset( $tried[ $key ] ) ) {
						continue;
					}
					$tried[ $key ] = true;

					$order = $this->order( $start );
					$paid  = $start === 'processing';
					$seen  = false;

					foreach ( $arrival as $i => $step ) {
						$this->send( $order, $step );

						$paid = $paid || in_array( $step, $capture, true );
						$seen = $seen || in_array( $step, $finish, true );

						$expected = $paid ? ( $seen ? 'completed' : 'processing' ) : 'on-hold';
						$actual   = $this->status( $order );

						if ( $actual !== $expected ) {
							$wrong[] = sprintf(
								'capture=[%s] finish=[%s] from %s, arrival %s: after %s expected %s, got %s',
								implode( ',', $capture ),
								implode( ',', $finish ),
								$start,
								implode( ' → ', $arrival ),
								implode( ' → ', array_slice( $arrival, 0, $i + 1 ) ),
								$expected,
								$actual
							);
							break;
						}
					}
				}
			}
		}

		$this->assertSame( array(), $wrong, count( $wrong ) . " scenarios went wrong:\n" . implode( "\n", $wrong ) );
	}
}
