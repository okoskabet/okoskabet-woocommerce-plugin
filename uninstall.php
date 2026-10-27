<?php

/**
 * okoskabet_woocommerce_plugin
 *
 * Fired when the plugin is uninstalled.
 *
 * When populating this file, consider the following flow
 * of control:
 *
 * - This method should be static
 * - Check if the $_REQUEST content actually is the plugin name
 * - Run an admin referrer check to make sure it goes through authentication
 * - Verify the output of $_GET makes sense
 * - Repeat with other user roles. Best directly by using the links/query string parameters.
 * - Repeat things for multisite. Once for a single site in the network, once sitewide.
 *
 * @package   okoskabet_woocommerce_plugin
 * @author    Kim Frederiksen <kim@heyrobot.com>
 * @copyright 2024 HeyRobot.AI aps
 * @license   GPL 2.0+
 * @link      https://heyrobot.ai
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Loop for uninstall
 *
 * @return void
 */
function o_uninstall_multisite()
{
	if (is_multisite()) {
		/** @var array<\WP_Site> $blogs */
		$blogs = get_sites();

		if (!empty($blogs)) {
			foreach ($blogs as $blog) {
				switch_to_blog((int) $blog->blog_id);
				o_uninstall();
				restore_current_blog();
			}

			return;
		}
	}

	o_uninstall();
}

/**
 * What uninstalling removes: what the plugin keeps for a while, and nothing a
 * shop set up.
 *
 * The shop's settings, its Økoskabet accounts and the list of migrations that
 * have run stay. The guide in Økoskabet's backoffice tells a shop on an old
 * installation to delete the plugin and install it again from a release, and
 * promises its setup survives that (»Skift fra en gammel installation«); a
 * shop that followed it would otherwise lose its API key and every setting.
 * The orders keep their Økoskabet meta too: they go on existing in
 * WooCommerce after the plugin does, and say which delivery they had.
 *
 * Removed: the answers remembered from Økoskabet (configuration, shipping
 * methods, shipments, delivery locations, feature prices, the address-lookup
 * rate limit), holds on an order's additions, and the last word on line
 * totals. Each is rebuilt by asking again.
 *
 * @return void
 */
function o_uninstall()
{
	global $wpdb;

	$transients = array(
		'okoskabet-woocommerce-plugin_',
		'okoskabet_feature_pricing_',
		'okoskabet_delivery_location_options_',
		'oko_addr_rl_',
	);

	foreach ($transients as $prefix) {
		foreach (array('_transient_', '_transient_timeout_') as $kind) {
			$wpdb->query($wpdb->prepare(
				"DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE %s",
				$wpdb->esc_like($kind . $prefix) . '%'
			));
		}
	}

	$wpdb->query($wpdb->prepare(
		"DELETE FROM `{$wpdb->options}` WHERE `option_name` LIKE %s",
		$wpdb->esc_like('_oko_addition_lock_') . '%'
	));

	delete_option('okoskabet_line_totals_last_said');
}

o_uninstall_multisite();
