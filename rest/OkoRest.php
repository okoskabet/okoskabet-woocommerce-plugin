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
	 * Examples
	 *
	 * @since 1.0.0
	 * @param \WP_REST_Request<array> $request Values.
	 * @return array
	 */
	public function get_sheds(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$params = $request->get_params();

		$settings = get_option(O_TEXTDOMAIN . '-settings');

		$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';

		if (!empty($settings['_api_key'])) {
			$curl = curl_init();

			$maximum_days_in_future = $settings['_maximum_days_in_future'];

			curl_setopt_array($curl, array(
				CURLOPT_URL => $api_url . '/api/v1/sheds/?delivery_dates=true&maximum_days_in_future=' . $maximum_days_in_future . '&zipcode=' . $params['zip'] . '&address=' . $params['address'],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => '',
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => 'GET',
				CURLOPT_HTTPHEADER => array(
					'authorization: ' . $settings['_api_key']
				),
			));

			$response = curl_exec($curl);
			$output_content = json_decode($response, true);

			curl_close($curl);

			return array('settings' => $settings, 'results' => $output_content);
		} else {
			return false;
		}
	}

	public function get_home_delivery(\WP_REST_Request $request)
	{ // phpcs:ignore Squiz.Commenting.FunctionComment.IncorrectTypeHint

		$params = $request->get_params();

		$settings = get_option(O_TEXTDOMAIN . '-settings');

		$api_url = !empty($settings['_staging_api']) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';

		if (!empty($settings['_api_key'])) {
			$curl = curl_init();

			$maximum_days_in_future = $settings['_maximum_days_in_future'];

			curl_setopt_array($curl, array(
				CURLOPT_URL => $api_url . '/api/v1/home_delivery?maximum_days_in_future=' . $maximum_days_in_future . '&postal_code=' . $params['zip'],
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => '',
				CURLOPT_MAXREDIRS => 10,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => 'GET',
				CURLOPT_HTTPHEADER => array(
					'authorization: ' . $settings['_api_key']
				),
			));

			$response = curl_exec($curl);
			$output_content = json_decode($response, true);

			curl_close($curl);

			return array('settings' => $settings, 'results' => $output_content);
		} else {
			return false;
		}
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
		$settings = get_option(O_TEXTDOMAIN . '-settings');

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
		if (!in_array($event, $webhook_events)) {
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
