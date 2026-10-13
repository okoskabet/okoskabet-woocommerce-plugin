<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit;

use okoskabet_woocommerce_plugin\Integrations\Merchants;
use okoskabet_woocommerce_plugin\Integrations\Merchant_Router;

/**
 * The picker's configuration has to reach the page, not the AJAX fragment.
 *
 * WooCommerce refreshes the review-order table over AJAX whenever the basket
 * or the shipping choice changes, and the hook that draws the delivery row
 * runs again in that request. Markup arrives fine that way; a script does
 * not — `wp_enqueue_script` and `wp_add_inline_script` do nothing once the
 * page is sent. So a checkout whose FIRST render did not draw the row shipped
 * the Svelte bundle with no configuration, the refresh drew the buttons, and
 * the customer got a delivery section with every control except the date.
 *
 * Seen live on gaardmester.dk on 13 October, where a brand-new visitor could
 * not order at all: no console error and no request, because nothing had
 * anything to start on.
 */
class CheckoutRuntimeTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );

		delete_option( Merchants::OPTION_KEY );
		Merchants::purge_config_cache();

		wc_load_cart();
		WC()->cart->empty_cart();

		// wp_scripts() outlives a single test, and the function reads it to
		// know whether it has already run. Deregistering alone leaves the
		// handle in the queue, which still reads as enqueued.
		wp_dequeue_script( 'okoskabet-checkout-helpers' );
		wp_deregister_script( 'okoskabet-checkout-helpers' );
	}

	public function tearDown(): void {
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}
		delete_option( Merchants::OPTION_KEY );
		Merchants::purge_config_cache();
		parent::tearDown();
	}

	/** @param array<string, array> $merchants */
	private function with_merchants( array $merchants, string $default_id ): void {
		Merchants::save_config( array(
			'version'             => 1,
			'default_merchant_id' => $default_id,
			'merchants'           => $merchants,
		) );
	}

	/** Everything the inline script carries, as one string. */
	private function inline_script(): string {
		$scripts = wp_scripts();
		$data    = $scripts->get_data( 'okoskabet-checkout-helpers', 'before' );

		return is_array( $data ) ? implode( "\n", $data ) : (string) $data;
	}

	public function test_the_configuration_is_enqueued_without_drawing_the_row() {
		$this->with_merchants(
			array( 'default' => array( 'id' => 'default', 'label' => 'Default', 'api_key' => 'a-key' ) ),
			'default'
		);

		// The page's own enqueue pass, and nothing else — no review-order
		// hook, no delivery row. This is the checkout a brand-new visitor
		// got, and the one that shipped a bundle with nothing to start on.
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		enqueue_checkout_scripts();
		remove_filter( 'woocommerce_is_checkout', '__return_true' );

		$inline = $this->inline_script();

		$this->assertStringContainsString( '_okoskabet_checkout', $inline, 'the picker gets its configuration' );
		$this->assertStringContainsString( '_okoskabet_pickup_strings', $inline, 'and the strings it shows' );
		$this->assertTrue( wp_script_is( 'okoskabet-checkout-helpers', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'okoskabet-shipping', 'enqueued' ), 'alongside the bundle it configures' );
	}

	public function test_a_merchant_without_a_key_falls_back_to_the_default() {
		// What the live shop had: the cart routes to a merchant that has not
		// been given its key. The slot is filled, so the old check — "is
		// there a merchant at all" — was satisfied, and the checkout drew
		// nothing.
		$this->with_merchants(
			array(
				'default' => array( 'id' => 'default', 'label' => 'Default', 'api_key' => 'the-key' ),
				'keyless' => array( 'id' => 'keyless', 'label' => 'Keyless' ),
			),
			'default'
		);

		// The basket is what the router reads, so the product has to be in
		// it — routing a loose product id would take the same branch no
		// matter what the checkout does.
		$product = new \WC_Product_Simple();
		$product->set_regular_price( 10 );
		$product->save();
		update_post_meta( $product->get_id(), Merchant_Router::PRODUCT_META_KEY, 'keyless' );

		WC()->cart->add_to_cart( $product->get_id() );

		$resolved = \okoskabet_woocommerce_plugin\Integrations\Merchant_Router::resolve_for_cart();
		$this->assertSame( 'keyless', (string) ( $resolved['merchant']['id'] ?? '' ), 'the basket does route there' );

		$this->assertTrue( oko_enqueue_checkout_runtime(), 'and the checkout still has a key to work with' );
		$this->assertStringContainsString( '_okoskabet_checkout', $this->inline_script() );
	}

	public function test_a_shop_with_no_key_at_all_enqueues_nothing() {
		$this->with_merchants(
			array( 'default' => array( 'id' => 'default', 'label' => 'Default' ) ),
			'default'
		);

		$this->assertFalse( oko_enqueue_checkout_runtime(), 'nothing to draw for' );
		$this->assertFalse( wp_script_is( 'okoskabet-checkout-helpers', 'enqueued' ) );
	}
}
