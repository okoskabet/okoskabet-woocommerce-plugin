<?php

/**
 * @package   okoskabet_woocommerce_plugin
 * @author    Kim Frederiksen <kim@heyrobot.com>
 * @copyright 2024 HeyRobot.AI aps
 * @license   GPL 2.0+
 * @link      https://heyrobot.ai
 *
 * Plugin Name:		Økoskabet WooCommerce Plugin
 * Plugin URI:		https://github.com/okoskabet/okoskabet-woocommerce-plugin
 * Description:		Connect your WooCommerce store to Økoskabet
 * Version:         1.1.14
 * Author:          Kim Frederiksen
 * Author URI:      https://heyrobot.ai
 * Text Domain:     okoskabet-woocommerce-plugin
 * License:         GPL 2.0+
 * License URI:     http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path:     /languages
 * Requires PHP:    7.4
 * WordPress-Plugin-Boilerplate-Powered: v3.3.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	die('We\'re sorry, but you can not directly access this file.');
}

define('O_VERSION', '1.1.14');
define('O_TEXTDOMAIN', 'okoskabet-woocommerce-plugin');
define('O_NAME', 'Økoskabet WooCommerce Plugin');
define('O_PLUGIN_ROOT', plugin_dir_path(__FILE__));
define('O_PLUGIN_ROOT_URL', plugin_dir_url(__FILE__));
define('O_PLUGIN_ABSOLUTE', __FILE__);
define('O_MIN_PHP_VERSION', '7.4');
define('O_WP_VERSION', '5.3');

add_action(
	'init',
	static function () {
		load_plugin_textdomain(O_TEXTDOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
	}
);

if (version_compare(PHP_VERSION, O_MIN_PHP_VERSION, '<=')) {
	add_action(
		'admin_init',
		static function () {
			deactivate_plugins(plugin_basename(__FILE__));
		}
	);
	add_action(
		'admin_notices',
		static function () {
			echo wp_kses_post(
				sprintf(
					'<div class="notice notice-error"><p>%s</p></div>',
					__('"okoskabet-woocommerce-plugin" requires PHP 7.4 or newer.', O_TEXTDOMAIN)
				)
			);
		}
	);

	// Return early to prevent loading the plugin.
	return;
}

$okoskabet_woocommerce_plugin_libraries = require O_PLUGIN_ROOT . 'vendor/autoload.php'; //phpcs:ignore

require_once O_PLUGIN_ROOT . 'functions/functions.php';

// Add your new plugin on the wiki: https://github.com/WPBP/WordPress-Plugin-Boilerplate-Powered/wiki/Plugin-made-with-this-Boilerplate



// Documentation to integrate GitHub, GitLab or BitBucket https://github.com/YahnisElsts/plugin-update-checker/blob/master/README.md
Puc_v4_Factory::buildUpdateChecker('https://github.com/okoskabet/okoskabet-woocommerce-plugin', __FILE__, 'okoskabet-woocommerce-plugin');

if (!wp_installing()) {
	register_activation_hook(O_TEXTDOMAIN . '/' . O_TEXTDOMAIN . '.php', array(new \okoskabet_woocommerce_plugin\Backend\ActDeact, 'activate'));
	register_deactivation_hook(O_TEXTDOMAIN . '/' . O_TEXTDOMAIN . '.php', array(new \okoskabet_woocommerce_plugin\Backend\ActDeact, 'deactivate'));
	add_action(
		'plugins_loaded',
		static function () use ($okoskabet_woocommerce_plugin_libraries) {
			new \okoskabet_woocommerce_plugin\Engine\Initialize($okoskabet_woocommerce_plugin_libraries);
			new \okoskabet_woocommerce_plugin\Rest\OkoRest;
		}
	);
}
