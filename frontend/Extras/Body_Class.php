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

namespace okoskabet_woocommerce_plugin\Frontend\Extras;

use okoskabet_woocommerce_plugin\Engine\Base;

/**
 * Add custom css class to <body>
 */
class Body_Class extends Base {

	/**
	 * Initialize the class.
	 *
	 * @return void|bool
	 */
	public function initialize() {
		parent::initialize();

		\add_filter( 'body_class', array( self::class, 'add_o_class' ), 10, 1 );
	}

	/**
	 * Add class in the body on the frontend
	 *
	 * @param array $classes The array with all the classes of the page.
	 * @since 1.0.0
	 * @return array
	 */
	public static function add_o_class( array $classes ) {
		$classes[] = O_TEXTDOMAIN;

		return $classes;
	}

}
