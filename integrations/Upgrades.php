<?php
/**
 * okoskabet_woocommerce_plugin
 *
 * @package   okoskabet_woocommerce_plugin
 * @author    Kim Frederiksen <kim@heyrobot.com>
 * @copyright 2024 HeyRobot.AI aps
 * @license   GPL 2.0+
 */

namespace okoskabet_woocommerce_plugin\Integrations;

use okoskabet_woocommerce_plugin\Engine\Base;

/**
 * Handles one-shot upgrade routines for plugin settings.
 *
 * Each migration is keyed by a string and recorded in wp_options under
 * 'okoskabet_completed_migrations'. A migration runs at most once per site.
 *
 * To add a new migration: pick a unique key, add a method named after it,
 * and reference it in $this->migrations.
 */
class Upgrades extends Base {

	const COMPLETED_OPTION = 'okoskabet_completed_migrations';

	/** @var array<string, string> Map of migration key → method name. */
	private $migrations = array(
		'rewrite_label_created_events_v1' => 'migrate_label_created_events',
	);

	/**
	 * Initialize.
	 */
	public function initialize() {
		parent::initialize();

		// Run on admin_init so the migration only fires inside the admin
		// (avoids running on every front-end request) but still happens
		// without the admin needing to do anything explicit.
		add_action( 'admin_init', array( $this, 'run_pending_migrations' ) );
	}

	/**
	 * Run any migrations that haven't been applied yet.
	 */
	public function run_pending_migrations(): void {
		$completed = (array) get_option( self::COMPLETED_OPTION, array() );
		$dirty     = false;

		foreach ( $this->migrations as $key => $method ) {
			if ( in_array( $key, $completed, true ) ) {
				continue;
			}
			if ( ! method_exists( $this, $method ) ) {
				continue;
			}
			try {
				$this->$method();
				$completed[] = $key;
				$dirty       = true;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'okoskabet_woocommerce_plugin: migration completed: ' . $key );
				}
			} catch ( \Throwable $e ) {
				error_log( 'okoskabet_woocommerce_plugin: migration failed: ' . $key . ' — ' . $e->getMessage() );
			}
		}

		if ( $dirty ) {
			update_option( self::COMPLETED_OPTION, array_values( array_unique( $completed ) ) );
		}
	}

	/**
	 * Migration: rewrite the legacy 'label_created' event name in stored
	 * settings to 'in_shed' (which is the new name for the same event).
	 *
	 * Background: plugin versions ≤ 1.2.6 stored 'label_created' as the
	 * event name in '_capture_events' and '_webhook_events'. From 1.2.7
	 * onwards the event is called 'in_shed'. Until this migration ran,
	 * OkoRest::handle_webhook had to remap the legacy key on every call.
	 * After this migration, the stored settings are clean and the runtime
	 * remap is no longer needed for any site that has ever activated 1.2.18+.
	 */
	private function migrate_label_created_events(): void {
		$option_key = O_TEXTDOMAIN . '_options';
		$settings   = get_option( $option_key, array() );

		if ( ! is_array( $settings ) ) {
			return;
		}

		$dirty = false;

		foreach ( array( '_capture_events', '_webhook_events' ) as $field ) {
			if ( ! isset( $settings[ $field ] ) || ! is_array( $settings[ $field ] ) ) {
				continue;
			}
			$rewritten = array();
			foreach ( $settings[ $field ] as $event ) {
				$rewritten[] = ( $event === 'label_created' ) ? 'in_shed' : $event;
			}
			$rewritten = array_values( array_unique( $rewritten ) );
			if ( $rewritten !== $settings[ $field ] ) {
				$settings[ $field ] = $rewritten;
				$dirty              = true;
			}
		}

		if ( $dirty ) {
			update_option( $option_key, $settings );
		}
	}
}
