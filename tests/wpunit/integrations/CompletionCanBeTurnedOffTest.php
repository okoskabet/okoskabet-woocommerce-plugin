<?php

use okoskabet_woocommerce_plugin\Integrations\Merchants;

/**
 * A single-merchant shop saves the settings form with event boxes unticked.
 *
 * CMB2 stores nothing for an empty multicheck, so the key is absent from the
 * saved option. The webhook reads the default merchant record, not the option,
 * so the save has to carry "nothing ticked" into that record — otherwise the
 * old choice keeps completing orders (and, on Nexi, charging at completion).
 *
 * But a list the form never showed is absent for a different reason, and must
 * not be read as "nothing ticked": losing the capture events means orders are
 * never captured.
 */
class CompletionCanBeTurnedOffTest extends \Codeception\TestCase\WPTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'plugins_loaded' );
		delete_option( Merchants::OPTION_KEY );
		delete_option( O_TEXTDOMAIN . '-settings' );
		Merchants::purge_config_cache();

		Merchants::save_config( array(
			'default_merchant_id' => 'default',
			'merchants'           => array(
				'default' => array(
					'id'             => 'default',
					'label'          => 'Default merchant',
					'api_key'        => 'k',
					'webhook_secret' => 's',
					'capture_events' => array( 'label_printed' ),
					'webhook_events' => array( 'fulfilled' ),
				),
			),
		) );
	}

	/** The settings form as CMB2 hands it to the save hook, with these lists on it. */
	private function form_with( array $field_ids ): \CMB2 {
		$cmb = new \CMB2( array(
			'id'           => 'oko_test_options_' . wp_rand(),
			'object_types' => array( 'options-page' ),
			'option_key'   => O_TEXTDOMAIN . '-settings',
		) );
		foreach ( $field_ids as $field_id ) {
			$cmb->add_field( array(
				'id'      => $field_id,
				'type'    => 'multicheck',
				'options' => array( 'label_printed' => 'Label printed', 'fulfilled' => 'Fulfilled' ),
			) );
		}
		return $cmb;
	}

	private function save( array $option, \CMB2 $cmb ): void {
		update_option( O_TEXTDOMAIN . '-settings', $option );
		( new Merchants() )->handle_legacy_options_saved( O_TEXTDOMAIN . '-settings', true, $cmb );
		Merchants::purge_config_cache();
	}

	public function test_unticking_every_completion_box_turns_completion_off() {
		// What CMB2 leaves after a save with every completion box unticked.
		$this->save(
			array( '_api_key' => 'k', '_webhook_secret' => 's', '_capture_events' => array( 'label_printed' ) ),
			$this->form_with( array( '_capture_events', '_webhook_events' ) )
		);

		$this->assertSame(
			array(),
			Merchants::get_default()['webhook_events'],
			'nothing ticked must mean nothing completes the order'
		);
	}

	public function test_a_capture_list_the_form_left_out_keeps_its_events() {
		// The form showed no capture list (the gateway takes the money at
		// completion, or was briefly switched off), so the key is absent.
		$this->save(
			array( '_api_key' => 'k', '_webhook_secret' => 's', '_webhook_events' => array( 'fulfilled' ) ),
			$this->form_with( array( '_webhook_events' ) )
		);

		$this->assertSame(
			array( 'label_printed' ),
			Merchants::get_default()['capture_events'],
			'a list the shop never saw must not be emptied by saving the form'
		);
	}
}
