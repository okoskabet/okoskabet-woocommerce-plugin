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

namespace okoskabet_woocommerce_plugin\Cli;

use okoskabet_woocommerce_plugin\Engine\Base;

if ( \defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * WP CLI commands
	 */
	class Example extends Base {

		/**
		 * Initialize the class.
		 *
		 * @return void|bool
		 */
		public function initialize() {
			if ( !\apply_filters( 'okoskabet_woocommerce_plugin_o_enqueue_admin_initialize', true ) ) {
				return;
			}

			parent::initialize();
		}

	}

}
