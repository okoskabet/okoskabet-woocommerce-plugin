<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * One merchant with one account, and Økoskabet answering the configuration
 * call with whatever the test says.
 *
 * Written once because the clearing in front of it is the part that is easy
 * to get wrong: the answer is held in a transient *and* in a memo that lasts
 * the request, and a test that drops one but not the other passes against
 * configuration it never set. That is not hypothetical — it cost an
 * afternoon on 20 October 2026, where three tests of the same screen each
 * carried their own copy of this and one of them was a step short.
 */
trait ConfiguresMerchant
{
	/** The one account every test here sends under. */
	protected function a_merchant_with_an_account(): void
	{
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
		Merchants::save_config(array(
			'default_merchant_id' => 'default',
			'merchants' => array('default' => array(
				'id' => 'default', 'label' => 'D', 'api_key' => 'test-key', 'staging' => true,
			)),
		));
	}

	/** And the account put back as it was found. */
	protected function forget_the_merchant(): void
	{
		remove_all_filters('pre_http_request');
		delete_transient(o_configuration_transient_key('default'));
		delete_option(Merchants::OPTION_KEY);
		Merchants::purge_config_cache();
	}

	/**
	 * Økoskabet, answering `GET /configuration` with this and nothing else.
	 *
	 * At priority 1, ahead of anything an earlier test left behind, and with
	 * both caches dropped first — see the trait's own note.
	 *
	 * @param array<string,mixed> $configuration
	 */
	protected function okoskabet_configuration(array $configuration): void
	{
		delete_transient(o_configuration_transient_key('default'));
		Merchants::purge_config_cache();
		remove_all_filters('pre_http_request');

		add_filter('pre_http_request', static function ($preempt, $args, $url) use ($configuration) {
			if (strpos($url, '/api/v1/configuration') === false) {
				return $preempt;
			}

			return array(
				'response' => array('code' => 200),
				'body'     => wp_json_encode($configuration),
			);
		}, 1, 3);
	}
}
