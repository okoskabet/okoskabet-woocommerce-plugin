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
 * The various Cron of this plugin
 */
class Cron extends Base {

	/**
	 * Initialize the class.
	 *
	 * @return void|bool
	 */
	public function initialize() {
		// No scheduled tasks currently needed.
		// To add a cron job, use CronPlus:
		//
		// $cronplus = new \CronPlus( array(
		//     'recurrence'       => 'hourly',
		//     'schedule'         => 'schedule',
		//     'name'             => 'hourly_cron',
		//     'cb'               => array( $this, 'hourly_cron' ),
		//     'plugin_root_file' => 'okoskabet-woocommerce-plugin.php',
		// ) );
		// $cronplus->schedule_event();
	}

}
