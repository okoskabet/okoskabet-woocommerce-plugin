<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * Uninstalling removes what the plugin keeps for a while, and nothing a shop
 * set up (#85).
 *
 * The guide tells a shop on an old installation to delete the plugin and
 * install it again from a release, and promises the setup survives. So the
 * settings, the accounts and the migrations that have run stay; the answers
 * remembered from Økoskabet, the holds on additions and the last word on line
 * totals go.
 */
class UninstallTest extends \Codeception\TestCase\WPTestCase
{
	public function test_what_the_plugin_remembered_goes_and_the_setup_stays(): void
	{
		update_option(O_TEXTDOMAIN . '-settings', array('_api_key' => 'test-key'));
		update_option(Merchants::OPTION_KEY, array('default_merchant_id' => 'default'));
		update_option('okoskabet_completed_migrations', array('a_migration'));

		set_transient(o_configuration_transient_key('default'), array('order_cutoff' => array()), 300);
		set_transient(o_shipping_methods_transient_key('default'), array('shed'), 300);
		set_transient('okoskabet_feature_pricing_default', array('x'), 300);
		set_transient('okoskabet_delivery_location_options_abc', array('x'), 300);
		set_transient('oko_addr_rl_abc', 3, 300);
		add_option('_oko_addition_lock_88218', time() . ':hold', '', 'no');
		update_option('okoskabet_line_totals_last_said', array('default' => true), false);

		$this->uninstall();

		$this->assertSame(array('_api_key' => 'test-key'), get_option(O_TEXTDOMAIN . '-settings'));
		$this->assertSame(array('default_merchant_id' => 'default'), get_option(Merchants::OPTION_KEY));
		$this->assertSame(array('a_migration'), get_option('okoskabet_completed_migrations'));

		// Read from the table, not through the object cache.
		$left = $GLOBALS['wpdb']->get_col(
			"SELECT `option_name` FROM `{$GLOBALS['wpdb']->options}` WHERE `option_name` LIKE '%okoskabet%' OR `option_name` LIKE '%oko\\_%'"
		);
		foreach (array(O_TEXTDOMAIN . '-settings', Merchants::OPTION_KEY, 'okoskabet_completed_migrations') as $kept) {
			$this->assertContains($kept, $left, $kept . ' is the shop\'s setup.');
		}
		foreach ($left as $name) {
			$this->assertStringNotContainsString('_transient_', $name, $name . ' was left behind.');
			$this->assertStringNotContainsString('_oko_addition_lock_', $name, $name . ' was left behind.');
			$this->assertNotSame('okoskabet_line_totals_last_said', $name);
		}
	}

	/** uninstall.php as WordPress runs it: on its own, once. */
	private function uninstall(): void
	{
		if (! defined('WP_UNINSTALL_PLUGIN')) {
			define('WP_UNINSTALL_PLUGIN', 'okoskabet-woocommerce-plugin/okoskabet-woocommerce-plugin.php');
		}
		wp_cache_flush();
		require dirname(__DIR__, 2) . '/uninstall.php';
	}
}
