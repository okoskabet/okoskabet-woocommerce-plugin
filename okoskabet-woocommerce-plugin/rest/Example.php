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
 * Example class for REST
 */
class Example extends Base
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


		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => 'https://staging.okoskabet.dk/api/v1/sheds/',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'GET',
			CURLOPT_HTTPHEADER => array(
				'authorization: 317B6B7D2A01C154'
			),
		));

		$response = curl_exec($curl);

		curl_close($curl);

		return array('result' => json_decode($response));
	}
}
