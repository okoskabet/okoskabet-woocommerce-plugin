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

namespace okoskabet_woocommerce_plugin\Ajax;

use okoskabet_woocommerce_plugin\Engine\Base;

/**
 * AJAX in the public
 */
class Ajax extends Base {

	/**
	 * Initialize the class.
	 *
	 * @return void|bool
	 */
	public function initialize() {
		if ( !\apply_filters( 'okoskabet_woocommerce_plugin_o_ajax_initialize', true ) ) {
			return;
		}
	}

}
