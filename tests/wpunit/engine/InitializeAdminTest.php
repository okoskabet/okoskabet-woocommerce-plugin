<?php

namespace okoskabet_woocommerce_plugin\Tests\WPUnit;

class InitializeAdminTest extends \Codeception\TestCase\WPTestCase {
	/**
	 * @var string
	 */
	protected $root_dir;

	public function setUp(): void {
		parent::setUp();

		// your set up methods here
		$this->root_dir = dirname( dirname( dirname( __FILE__ ) ) );

		$user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		set_current_screen( 'edit.php' );
	}

	public function tearDown(): void {
		parent::tearDown();
	}

	/**
	 * @test
	 * it should be admin
	 */
	public function it_should_be_admin() {
		add_filter( 'wp_doing_ajax', '__return_false' );
		do_action('plugins_loaded');

		$classes   = array();
		$classes[] = 'okoskabet_woocommerce_plugin\Internals\PostTypes';
		$classes[] = 'okoskabet_woocommerce_plugin\Internals\Shortcode';
		$classes[] = 'okoskabet_woocommerce_plugin\Internals\Transient';
		$classes[] = 'okoskabet_woocommerce_plugin\Integrations\CMB';
		$classes[] = 'okoskabet_woocommerce_plugin\Integrations\Cron';
		$classes[] = 'okoskabet_woocommerce_plugin\Integrations\Template';
		$classes[] = 'okoskabet_woocommerce_plugin\Integrations\Widgets\My_Recent_Posts_Widget';
		$classes[] = 'okoskabet_woocommerce_plugin\Backend\ActDeact';
		$classes[] = 'okoskabet_woocommerce_plugin\Backend\Enqueue';
		$classes[] = 'okoskabet_woocommerce_plugin\Backend\ImpExp';
		$classes[] = 'okoskabet_woocommerce_plugin\Backend\Notices';
		$classes[] = 'okoskabet_woocommerce_plugin\Backend\Pointers';
		$classes[] = 'okoskabet_woocommerce_plugin\Backend\Settings_Page';

		$all_classes = get_declared_classes();
		foreach( $classes as $class ) {
			$this->assertTrue( in_array( $class, $all_classes ) );
		}
	}

	/**
	 * @test
	 * it should be ajax
	 */
	public function it_should_be_admin_ajax() {
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
