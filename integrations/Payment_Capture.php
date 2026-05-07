<?php

/**
 * okoskabet_woocommerce_plugin
 *
 * @package   okoskabet_woocommerce_plugin
 * @author    Kim Frederiksen <kim@heyrobot.com>
 * @copyright 2024 HeyRobot.AI aps
 * @license   GPL 2.0+
 * @link      https://heyrobot.ai
 */

namespace okoskabet_woocommerce_plugin\Integrations;

use okoskabet_woocommerce_plugin\Engine\Base;

/**
 * Handles delayed payment capture for multiple gateways.
 * Called when Økoskabet sends a webhook event that should trigger capture.
 */
class Payment_Capture extends Base {

	/**
	 * Initialize the class.
	 *
	 * @return void|bool
	 */
	public function initialize() {
		parent::initialize();
		// No hooks needed here — capture is called directly from OkoRest webhook handler.
	}

	/**
	 * Attempt to capture payment for a WooCommerce order.
	 * Detects the gateway used and calls the appropriate capture method.
	 *
	 * @param \WC_Order $order        The WooCommerce order.
	 * @param string    $gateway_hint Gateway slug from plugin settings (used as fallback hint).
	 * @return array{success: bool, message: string}
	 */
	public static function capture( \WC_Order $order, string $gateway_hint = 'auto' ): array {

		// Don't capture if already captured/paid.
		if ( $order->is_paid() ) {
			return array( 'success' => true, 'message' => 'Order already paid' );
		}

		// Detect which gateway was actually used for this order.
		$payment_method = $order->get_payment_method();

		// If gateway_hint is 'auto', use the order's actual payment method.
		$gateway = ( $gateway_hint === 'auto' || empty( $gateway_hint ) ) ? $payment_method : $gateway_hint;

		error_log( sprintf(
			'okoskabet_woocommerce_plugin: Attempting capture for order %s, gateway=%s, payment_method=%s',
			$order->get_order_number(), $gateway, $payment_method
		) );

		switch ( $gateway ) {

			case 'quickpay_gateway':
			case 'wc_quickpay':
			case 'quickpay':
				return self::capture_quickpay( $order );

			case 'stripe':
			case 'stripe_cc':
				return self::capture_stripe( $order );

			case 'nets_easy':
			case 'dibs_easy':
			case 'nexi_checkout':
				return self::capture_nets_easy( $order );

			case 'pensopay':
				return self::capture_pensopay( $order );

			default:
				// Fallback: setting the order to 'processing' triggers most gateways
				// to auto-capture via their own status-change hooks.
				return self::capture_via_status( $order );
		}
	}

	/**
	 * Capture via Quickpay (WooCommerce Quickpay plugin).
	 * Requires: WooCommerce Quickpay plugin active.
	 *
	 * @param \WC_Order $order
	 * @return array{success: bool, message: string}
	 */
	private static function capture_quickpay( \WC_Order $order ): array {
		// WooCommerce Quickpay stores the payment ID in order meta.
		$transaction_id = $order->get_transaction_id();

		if ( empty( $transaction_id ) ) {
			// No transaction yet — fall back to status change which may trigger capture.
			return self::capture_via_status( $order );
		}

		// The WooCommerce Quickpay plugin hooks into woocommerce_order_status_changed
		// and captures automatically when status moves to 'processing'.
		// We trigger that here.
		if ( $order->get_status() !== 'processing' ) {
			$order->update_status(
				'processing',
				__( 'Payment capture triggered by Økoskabet webhook.', 'okoskabet-woocommerce-plugin' )
			);
		}

		// Additionally call WC_QuickPay_Order capture if the class exists (plugin active).
		if ( class_exists( 'WC_QuickPay_Order' ) ) {
			try {
				$qp_order = new \WC_QuickPay_Order( $order->get_id() );
				if ( method_exists( $qp_order, 'capture' ) ) {
					$qp_order->capture();
					return array( 'success' => true, 'message' => 'Quickpay capture called successfully' );
				}
			} catch ( \Throwable $e ) {
				error_log( 'okoskabet_woocommerce_plugin: Quickpay capture error: ' . $e->getMessage() );
				return array( 'success' => false, 'message' => 'Quickpay capture error: ' . $e->getMessage() );
			}
		}

		return array( 'success' => true, 'message' => 'Quickpay capture triggered via status change' );
	}

	/**
	 * Capture via Stripe (WooCommerce Stripe plugin).
	 *
	 * @param \WC_Order $order
	 * @return array{success: bool, message: string}
	 */
	private static function capture_stripe( \WC_Order $order ): array {
		$transaction_id = $order->get_transaction_id();

		if ( empty( $transaction_id ) ) {
			return self::capture_via_status( $order );
		}

		// WooCommerce Stripe plugin: capturing is done by calling the gateway's
		// process_capture method or via status change to 'processing'.
		// The Stripe plugin hooks into woocommerce_order_status_processing.
		if ( $order->get_status() !== 'processing' ) {
			$order->update_status(
				'processing',
				__( 'Payment capture triggered by Økoskabet webhook (Stripe).', 'okoskabet-woocommerce-plugin' )
			);
		}

		// If WC_Stripe_Helper or WC_Gateway_Stripe is available, call capture directly.
		if ( class_exists( 'WC_Stripe_Helper' ) ) {
			try {
				$gateways = WC()->payment_gateways()->payment_gateways();
				$stripe   = $gateways['stripe'] ?? null;

				if ( $stripe && method_exists( $stripe, 'capture_payment' ) ) {
					$stripe->capture_payment( $order->get_id() );
					return array( 'success' => true, 'message' => 'Stripe capture called successfully' );
				}
			} catch ( \Throwable $e ) {
				error_log( 'okoskabet_woocommerce_plugin: Stripe capture error: ' . $e->getMessage() );
				return array( 'success' => false, 'message' => 'Stripe capture error: ' . $e->getMessage() );
			}
		}

		return array( 'success' => true, 'message' => 'Stripe capture triggered via status change' );
	}

	/**
	 * Capture via Nets Easy / DIBS Easy.
	 *
	 * @param \WC_Order $order
	 * @return array{success: bool, message: string}
	 */
	private static function capture_nets_easy( \WC_Order $order ): array {
		$transaction_id = $order->get_transaction_id();

		if ( empty( $transaction_id ) ) {
			return self::capture_via_status( $order );
		}

		// Nets Easy plugin captures on status change to 'processing' or 'completed'.
		if ( $order->get_status() !== 'processing' ) {
			$order->update_status(
				'processing',
				__( 'Payment capture triggered by Økoskabet webhook (Nets Easy).', 'okoskabet-woocommerce-plugin' )
			);
		}

		// Try direct API capture if Nets Easy SDK classes are available.
		if ( class_exists( 'Nexi\Checkout\Order\OrderCharge' ) || class_exists( 'WC_Dibs_Easy' ) ) {
			// The Nets Easy plugin hooks into the status change above and handles capture.
			// No additional direct call needed.
			return array( 'success' => true, 'message' => 'Nets Easy capture triggered via status change' );
		}

		return array( 'success' => true, 'message' => 'Nets Easy capture triggered via status change' );
	}

	/**
	 * Capture via Pensopay.
	 *
	 * @param \WC_Order $order
	 * @return array{success: bool, message: string}
	 */
	private static function capture_pensopay( \WC_Order $order ): array {
		$transaction_id = $order->get_transaction_id();

		if ( empty( $transaction_id ) ) {
			return self::capture_via_status( $order );
		}

		// Pensopay plugin captures on 'processing' status change.
		if ( $order->get_status() !== 'processing' ) {
			$order->update_status(
				'processing',
				__( 'Payment capture triggered by Økoskabet webhook (Pensopay).', 'okoskabet-woocommerce-plugin' )
			);
		}

		if ( class_exists( 'WC_PensoPay' ) ) {
			try {
				$gateways  = WC()->payment_gateways()->payment_gateways();
				$pensopay  = $gateways['pensopay'] ?? null;
				if ( $pensopay && method_exists( $pensopay, 'capture_payment' ) ) {
					$pensopay->capture_payment( $order->get_id() );
					return array( 'success' => true, 'message' => 'Pensopay capture called successfully' );
				}
			} catch ( \Throwable $e ) {
				error_log( 'okoskabet_woocommerce_plugin: Pensopay capture error: ' . $e->getMessage() );
				return array( 'success' => false, 'message' => 'Pensopay capture error: ' . $e->getMessage() );
			}
		}

		return array( 'success' => true, 'message' => 'Pensopay capture triggered via status change' );
	}

	/**
	 * Generic fallback: set order to 'processing'.
	 * Most gateways auto-capture on this status change via their own hooks.
	 *
	 * @param \WC_Order $order
	 * @return array{success: bool, message: string}
	 */
	private static function capture_via_status( \WC_Order $order ): array {
		if ( $order->get_status() !== 'processing' ) {
			$order->update_status(
				'processing',
				__( 'Payment capture triggered by Økoskabet webhook.', 'okoskabet-woocommerce-plugin' )
			);
		}
		return array( 'success' => true, 'message' => 'Capture triggered via order status change to processing' );
	}
}
