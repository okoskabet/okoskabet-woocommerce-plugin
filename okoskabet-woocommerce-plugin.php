<?php

/**
 * @package   okoskabet_woocommerce_plugin
 * @author    Foodshipper <kontakt@okoskabet.dk>
 * @copyright 2026 Foodshipper
 * @license   GPL 2.0+
 * @link      https://okoskabet.dk
 *
 * Plugin Name:		Økoskabet WooCommerce Plugin
 * Plugin URI:		https://github.com/okoskabet/okoskabet-woocommerce-plugin
 * Description:		Connect your WooCommerce store to Økoskabet
 * Version:         1.4.2
 * Author:          Foodshipper
 * Author URI:      https://okoskabet.dk
 * Text Domain:     okoskabet-woocommerce-plugin
 * License:         GPL 2.0+
 * License URI:     http://www.gnu.org/licenses/gpl-3.0.txt
 * Domain Path:     /languages
 * Requires PHP:    8.1
 * WordPress-Plugin-Boilerplate-Powered: v3.3.0
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
	die('We\'re sorry, but you can not directly access this file.');
}

define('O_VERSION', '1.4.2');
define('O_TEXTDOMAIN', 'okoskabet-woocommerce-plugin');
define('O_NAME', 'Økoskabet WooCommerce Plugin');
define('O_PLUGIN_ROOT', plugin_dir_path(__FILE__));
define('O_PLUGIN_ROOT_URL', plugin_dir_url(__FILE__));
define('O_PLUGIN_ABSOLUTE', __FILE__);
define('O_MIN_PHP_VERSION', '8.1');
define('O_WP_VERSION', '6.0');
define('O_MIN_WC_VERSION', '7.0');

add_action(
	'init',
	static function () {
		load_plugin_textdomain(O_TEXTDOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
	}
);

/**
 * Render a plugin-disabled admin notice + deactivate the plugin.
 *
 * Used by every pre-flight check below so a customer with a broken
 * setup (wrong PHP/WP version, no WooCommerce, etc.) sees a clear
 * actionable error in wp-admin instead of a plugin that just
 * silently does nothing.
 */
function okoskabet_woocommerce_plugin_show_blocking_notice( $message ) {
	add_action(
		'admin_init',
		static function () {
			deactivate_plugins( plugin_basename( O_PLUGIN_ABSOLUTE ) );
		}
	);
	add_action(
		'admin_notices',
		static function () use ( $message ) {
			echo wp_kses_post(
				sprintf(
					'<div class="notice notice-error"><p><strong>%s:</strong> %s</p></div>',
					esc_html( O_NAME ),
					$message
				)
			);
		}
	);
}

if ( version_compare( PHP_VERSION, O_MIN_PHP_VERSION, '<' ) ) {
	okoskabet_woocommerce_plugin_show_blocking_notice( sprintf(
		/* translators: 1 = required PHP version, 2 = currently running PHP version */
		__( 'requires PHP %1$s or newer. You are running PHP %2$s. Ask your hosting provider to upgrade PHP, then re-activate the plugin.', O_TEXTDOMAIN ),
		O_MIN_PHP_VERSION,
		PHP_VERSION
	) );
	return;
}

if ( version_compare( get_bloginfo( 'version' ), O_WP_VERSION, '<' ) ) {
	okoskabet_woocommerce_plugin_show_blocking_notice( sprintf(
		/* translators: 1 = required WP version, 2 = currently running WP version */
		__( 'requires WordPress %1$s or newer. You are running WordPress %2$s. Update WordPress, then re-activate the plugin.', O_TEXTDOMAIN ),
		O_WP_VERSION,
		get_bloginfo( 'version' )
	) );
	return;
}

// WooCommerce detection runs at `plugins_loaded` because WC may load
// after this main file is parsed (depending on plugin load order). We
// defer the check and run it once all plugins are present.
add_action( 'plugins_loaded', static function () {
	if ( ! class_exists( 'WooCommerce' ) ) {
		okoskabet_woocommerce_plugin_show_blocking_notice( wp_kses(
			__( 'requires the <a href="https://wordpress.org/plugins/woocommerce/">WooCommerce</a> plugin to be installed and active. Install WooCommerce, then re-activate Økoskabet.', O_TEXTDOMAIN ),
			array( 'a' => array( 'href' => array() ) )
		) );
		return;
	}

	if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, O_MIN_WC_VERSION, '<' ) ) {
		okoskabet_woocommerce_plugin_show_blocking_notice( sprintf(
			/* translators: 1 = required WC version, 2 = currently running WC version */
			__( 'requires WooCommerce %1$s or newer. You are running WooCommerce %2$s. Update WooCommerce, then re-activate Økoskabet.', O_TEXTDOMAIN ),
			O_MIN_WC_VERSION,
			WC_VERSION
		) );
		return;
	}
}, 5 ); // priority 5 so the notice is registered BEFORE other plugin init at priority 10.

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
			// Don't even attempt to boot when WooCommerce isn't here —
			// most integrations call WC functions during their initialize()
			// step and would PHP-fatal. The blocking notice was already
			// registered at priority 5 above.
			if ( ! class_exists( 'WooCommerce' ) ) {
				return;
			}
			new \okoskabet_woocommerce_plugin\Engine\Initialize($okoskabet_woocommerce_plugin_libraries);
			new \okoskabet_woocommerce_plugin\Rest\OkoRest;
		}
	);
}
