<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit;
use Inpsyde\WpContext;

class InitializeTest extends \Codeception\TestCase\WPTestCase {
	/**
	 * @var string
	 */
	protected $root_dir;

	public function setUp(): void {
		parent::setUp();

		// your set up methods here
		$this->root_dir = dirname( dirname( dirname( __FILE__ ) ) );

		wp_set_current_user(0);
		wp_logout();
		wp_safe_redirect(wp_login_url());
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * @test
	 * it should be front
	 */
	public function it_should_be_front() {
		do_action('plugins_loaded');

		$classes   = array();
		$classes[] = 'okoskabet_woocommerce_plugin\Internals\PostTypes';
		$classes[] = 'okoskabet_woocommerce_plugin\Internals\Shortcode';
		$classes[] = 'okoskabet_woocommerce_plugin\Internals\Transient';
		$classes[] = 'okoskabet_woocommerce_plugin\Integrations\CMB';
		$classes[] = 'okoskabet_woocommerce_plugin\Integrations\Cron';
		$classes[] = 'okoskabet_woocommerce_plugin\Integrations\Template';
		$classes[] = 'okoskabet_woocommerce_plugin\Integrations\Widgets\My_Recent_Posts_Widget';
		$classes[] = 'okoskabet_woocommerce_plugin\Frontend\Enqueue';
		$classes[] = 'okoskabet_woocommerce_plugin\Frontend\Extras\Body_Class';

		$all_classes = get_declared_classes();
		foreach( $classes as $class ) {
			$this->assertTrue( in_array( $class, $all_classes ) );
		}
	}

	/**
	 * @test
	 * it should be ajax
	 */
	public function it_should_be_ajax() {
		add_filter( 'wp_doing_ajax', '__return_true' );
		do_action('plugins_loaded');

		$classes   = array();
		$classes[] = 'okoskabet_woocommerce_plugin\Ajax\Ajax';
		$classes[] = 'okoskabet_woocommerce_plugin\Ajax\Ajax_Admin';

		$all_classes = get_declared_classes();
		foreach( $classes as $class ) {
			$this->assertTrue( in_array( $class, $all_classes ) );
		}
	}
}
