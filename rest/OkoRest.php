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

namespace okoskabet_woocommerce_plugin\Rest;

use okoskabet_woocommerce_plugin\Engine\Base;

/**
 * OkoRest class for REST
 */
class OkoRest extends Base
{

	/**
	 * Initialize the class.
	 *
	 * @return void|bool
	 */
	public function initialize()
	{
		parent::initialize();

		\add_action('rest_api_init', array($this, 'add_routes'));
	}

	/**
	 * Examples
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_routes()
	{
		$this->add_custom_routes();
	}

	/**
	 * Routes
	 *
	 * @since 1.0.0
	 *
	 *  Make an instance of this class somewhere, then
	 *  call this method and test on the command line with
	 * `curl http://example.com/wp-json/wp/v2/calc?first=1&second=2`
	 * @return void
	 */
	public function add_custom_routes()
	{
		\register_rest_route(
			'wp/v2',
			'okoskabet/sheds',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'get_sheds'),
				'args'                => array(
					'address' => array(
						'required' => false,
					),
					'zip' => array(
						'required' => true,
					),
				),
			)
		);

		\register_rest_route(
			'wp/v2',
			'okoskabet/home_delivery',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'get_home_delivery'),
				'args'                => array(
					'zip' => array(
						'required' => true,
					),
				),
			)
		);

		\register_rest_route(
			'wp/v2',
			'okoskabet/webhook',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array($this, 'handle_webhook'),
				'args'                => array(
					'event' => array(
						'required' => true,
					),
					'shipment_reference' => array(
						'required' => true,
					),
					'data' => array(
						'required' => false,
					),
				),
			)
		);
	}

	/**
	 * Get available sheds based on zip code and address.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request<array> $request Values.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_sheds(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$params = $request->get_params();
		$settings = \o_get_settings();

		if (empty($settings['_api_key'])) {
			return new \WP_Error('missing_api_key', 'API key not configured', array('status' => 500));
		}

		$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';
		$maximum_days_in_future = $settings['_maximum_days_in_future'] ?? 21;

		$request_url = \add_query_arg(array(
			'delivery_dates'         => 'true',
			'maximum_days_in_future' => $maximum_days_in_future,
			'zipcode'                => $params['zip'],
			'address'                => $params['address'] ?? '',
		), $api_url . '/api/v1/sheds/');

		$response = \wp_remote_get($request_url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => $settings['_api_key'],
			),
		));

		if (\is_wp_error($response)) {
			return new \WP_Error('api_error', $response->get_error_message(), array('status' => 502));
		}

		$body = \wp_remote_retrieve_body($response);
		$output_content = \json_decode($body, true);

		$public_settings = array(
			'_display_option'                  => $settings['_display_option'] ?? '',
			'_description_shipping_okoskabet'  => $settings['_description_shipping_okoskabet'] ?? '',
			'_description_shipping_private'    => $settings['_description_shipping_private'] ?? '',
			'_maximum_days_in_future'          => $maximum_days_in_future,
		);

		return new \WP_REST_Response(array('settings' => $public_settings, 'results' => $output_content), 200);
	}

	/**
	 * Get home delivery dates based on zip code.
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request<array> $request Values.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_home_delivery(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$params = $request->get_params();
		$settings = \o_get_settings();

		if (empty($settings['_api_key'])) {
			return new \WP_Error('missing_api_key', 'API key not configured', array('status' => 500));
		}

		$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';
		$maximum_days_in_future = $settings['_maximum_days_in_future'] ?? 21;

		$request_url = \add_query_arg(array(
			'maximum_days_in_future' => $maximum_days_in_future,
			'postal_code'            => $params['zip'],
		), $api_url . '/api/v1/home_delivery');

		$response = \wp_remote_get($request_url, array(
			'timeout' => 15,
			'headers' => array(
				'Authorization' => $settings['_api_key'],
			),
		));

		if (\is_wp_error($response)) {
			return new \WP_Error('api_error', $response->get_error_message(), array('status' => 502));
		}

		$body = \wp_remote_retrieve_body($response);
		$output_content = \json_decode($body, true);

		$public_settings = array(
			'_display_option'                  => $settings['_display_option'] ?? '',
			'_description_shipping_okoskabet'  => $settings['_description_shipping_okoskabet'] ?? '',
			'_description_shipping_private'    => $settings['_description_shipping_private'] ?? '',
			'_maximum_days_in_future'          => $maximum_days_in_future,
		);

		return new \WP_REST_Response(array('settings' => $public_settings, 'results' => $output_content), 200);
	}

	/**
	 * Handle webhook requests from Okoskabet
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request<array> $request Values.
	 * @return array|\WP_Error
	 */
	public function handle_webhook(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$params = $request->get_params();
		$settings = \o_get_settings();

		// Check if webhook is enabled
		if (empty($settings['_webhook_enabled'])) {
			return new \WP_Error('webhook_disabled', 'Webhook functionality is disabled', array('status' => 403));
		}

		// Verify API key in authorization header
		$headers = $request->get_headers();
		if (empty($headers['authorization']) || empty($settings['_api_key'])) {
			return new \WP_Error('unauthorized', 'Invalid or missing authorization', array('status' => 401));
		}

		$auth_header = is_array($headers['authorization']) ? $headers['authorization'][0] : $headers['authorization'];
		if ($auth_header !== $settings['_api_key']) {
			return new \WP_Error('unauthorized', 'Invalid API key', array('status' => 401));
		}

		// Get webhook event and shipment reference
		$event = sanitize_text_field($params['event']);
		$shipment_reference = sanitize_text_field($params['shipment_reference']);

		// Get enabled webhook events
		$webhook_events = !empty($settings['_webhook_events']) ? $settings['_webhook_events'] : array();

		// Check if this event should trigger order completion
		if (!in_array($event, $webhook_events, true)) {
			return array(
				'success' => true,
				'message' => 'Event not configured for order completion',
				'event' => $event,
				'order_id' => $shipment_reference
			);
		}

		// Check if WooCommerce is available
		if (!function_exists('wc_get_order')) {
			return new \WP_Error('woocommerce_not_available', 'WooCommerce is not available', array('status' => 503));
		}

		// Find the WooCommerce order by order number
		$order = wc_get_order($shipment_reference); // phpcs:ignore

		if (!$order) {
			return new \WP_Error('order_not_found', 'Order not found', array('status' => 404));
		}

		// Check if order is already completed
		if ($order->get_status() === 'completed') {
			return array(
				'success' => true,
				'message' => 'Order already completed',
				'event' => $event,
				'order_id' => $shipment_reference
			);
		}

		// Update order status to completed
		$order->update_status('completed', sprintf(
			__('Order marked as completed via Økoskabet webhook. Event: %s', O_TEXTDOMAIN),
			$event
		));

		// Add order note
		$order->add_order_note(sprintf(
			__('Økoskabet webhook received: %s', O_TEXTDOMAIN),
			$event
		));

		// Log the webhook event
		error_log(sprintf(
			'Økoskabet webhook processed: Event=%s, Order=%s, Status=completed',
			$event,
			$shipment_reference
		));

		return array(
			'success' => true,
			'message' => 'Order status updated successfully',
			'event' => $event,
			'order_id' => $shipment_reference,
			'new_status' => 'completed'
		);
	}
}
