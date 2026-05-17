<?php

/**
 * okoskabet_woocommerce_plugin
 *
 * @package   okoskabet_woocommerce_plugin
 * @author    Kim Frederiksen <kim@heyrobot.com>
 * @copyright 2024 HeyRobot.AI aps
 * @license   GPL 2.0+
 * @link      https://heyrobot.ai
 */

namespace okoskabet_woocommerce_plugin\Integrations;

use okoskabet_woocommerce_plugin\Engine\Base;

/**
 * Multi-merchant configuration store and admin UI.
 *
 * The plugin previously assumed a single Økoskabet merchant — one API key,
 * one webhook secret, one set of shipping descriptions. From 1.4.0 onwards
 * the plugin supports N merchants and routes each cart to whichever
 * merchant should fulfil it.
 *
 * Each merchant is an independent record with its own credentials,
 * webhook secret, payment gateway preference and a list of WooCommerce
 * product categories/tags whose items should be routed to it. A
 * "default" merchant always exists and is used when no rule matches AND
 * for any cart that contains items from more than one merchant — see
 * `Merchant_Router` for the cart-level decision logic.
 *
 * Storage: a single `wp_option` row `okoskabet_merchants_config` holding
 * the registry and the default merchant ID. We deliberately do NOT use a
 * Custom Post Type — merchants are configuration data, the volume is
 * tiny (typically 1–5 records) and a single option is trivially
 * exportable/importable.
 *
 * Security model:
 *   - All admin actions check `manage_woocommerce` and use nonces.
 *   - API keys and webhook secrets render as `<input type="password">`
 *     and are never echoed back into the page outside form values.
 *   - Merchant IDs are strict `sanitize_key()` slugs — alphanumeric +
 *     underscore + dash — so they're safe to interpolate into REST
 *     routes and HTML attributes.
 *   - Each merchant has its OWN webhook secret. We never try multiple
 *     secrets against an incoming webhook — the URL path identifies
 *     the merchant up-front, then the secret for that merchant alone
 *     is used for the HMAC comparison.
 */
class Merchants extends Base {

	/** wp_option key holding the entire multi-merchant configuration. */
	const OPTION_KEY = 'okoskabet_merchants_config';

	/** Capability required to manage merchants. */
	const CAPABILITY = 'manage_woocommerce';

	/** Form admin-post action names. */
	const ACTION_SAVE_GLOBAL = 'okoskabet_save_merchants_global';
	const ACTION_SAVE_MERCHANT = 'okoskabet_save_merchant';
	const ACTION_DELETE_MERCHANT = 'okoskabet_delete_merchant';
	const ACTION_TEST_CONNECTION = 'okoskabet_test_merchant_connection';

	/** Default merchant ID seeded at activation. */
	const DEFAULT_MERCHANT_ID = 'default';

	/** Query-string flag that opts a single-merchant install into the advanced multi-merchant UI. */
	const QUERY_SHOW_MERCHANTS = 'oko_show_merchants';

	/**
	 * Map of legacy settings option keys → merchant record fields. Used to
	 * bidirectionally sync the simple (single-merchant) CMB form on the
	 * main settings page with the default merchant record. See
	 * `mirror_default_to_options()` and `handle_legacy_options_saved()`.
	 *
	 * @var array<string, string>
	 */
	private static $legacy_option_to_merchant = array(
		'_api_key'                        => 'api_key',
		'_webhook_secret'                 => 'webhook_secret',
		'_staging_api'                    => 'staging',
		'_description_shipping_okoskabet' => 'description_shipping_okoskabet',
		'_description_shipping_private'   => 'description_shipping_private',
		'_maximum_days_in_future'         => 'maximum_days_in_future',
		'_payment_gateway'                => 'payment_gateway',
		'_capture_events'                 => 'capture_events',
		'_webhook_events'                 => 'webhook_events',
	);

	/**
	 * Request-level cache of the merge_with_defaults() output. Cleared by
	 * `save_config()` and by `purge_config_cache()` (which the tests can
	 * call). Avoids re-running merge_with_defaults() for every call inside
	 * a single request — meaningful for carts with many items where
	 * resolve_for_products() walks every merchant repeatedly.
	 *
	 * @var array|null
	 */
	private static $config_cache = null;

	/**
	 * Initialize.
	 */
	public function initialize() {
		parent::initialize();

		// Render the merchant admin UI as a panel inside the main plugin
		// settings page. We hook after Delivery_Exceptions (which uses
		// the same hook) so merchants appear above exceptions in the UI.
		add_action( 'okoskabet_after_settings_form', array( $this, 'render_section' ), 5 );

		add_action( 'admin_post_' . self::ACTION_SAVE_GLOBAL, array( $this, 'handle_save_global' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE_MERCHANT, array( $this, 'handle_save_merchant' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE_MERCHANT, array( $this, 'handle_delete_merchant' ) );
		add_action( 'admin_post_' . self::ACTION_TEST_CONNECTION, array( $this, 'handle_test_connection' ) );

		// Sync the simple CMB form on the main settings page (single-
		// merchant mode) back to the default merchant record. CMB2 emits
		// this action after persisting the form's option row.
		add_action(
			'cmb2_save_options-page_fields_' . O_TEXTDOMAIN . '_options',
			array( $this, 'handle_legacy_options_saved' ),
			10,
			3
		);
	}

	// =========================================================================
	// Storage
	// =========================================================================

	/**
	 * Returns a fresh empty configuration with the structure validators expect.
	 *
	 * @return array{version:int, default_merchant_id:string, merchants:array<string,array>}
	 */
	public static function default_config(): array {
		return array(
			'version'             => 1,
			'default_merchant_id' => self::DEFAULT_MERCHANT_ID,
			'merchants'           => array(),
		);
	}

	/**
	 * Shape of one merchant record (after normalisation).
	 *
	 * @return array
	 */
	public static function default_merchant(): array {
		return array(
			'id'                              => '',
			'label'                           => '',
			'api_key'                         => '',
			'webhook_secret'                  => '',
			'staging'                         => false,
			'description_shipping_okoskabet'  => '',
			'description_shipping_private'    => '',
			'maximum_days_in_future'          => 3,
			'payment_gateway'                 => 'auto',
			'capture_events'                  => array( 'label_printed' ),
			'webhook_events'                  => array( 'order_delivered' ),
			'product_categories'              => array(),
			'product_tags'                    => array(),
			'priority'                        => 10,
			'created_at'                      => '',
		);
	}

	/**
	 * Load the full multi-merchant configuration with sane defaults.
	 *
	 * Memoised per-request so a cart with many items doesn't pay the
	 * merge_with_defaults() cost on every lookup. The WordPress object
	 * cache already handles the DB roundtrip for `get_option`, but
	 * merge_with_defaults() does its own work on top.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		if ( self::$config_cache !== null ) {
			return self::$config_cache;
		}
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		self::$config_cache = self::merge_with_defaults( $stored );
		return self::$config_cache;
	}

	/**
	 * Persist the config.
	 *
	 * @param array $config
	 */
	public static function save_config( array $config ): void {
		$normalised = self::merge_with_defaults( $config );
		update_option( self::OPTION_KEY, $normalised );
		self::$config_cache = $normalised;
	}

	/**
	 * Drop the in-process cache. Mostly useful for tests; production
	 * callers don't need this — `save_config()` already refreshes the
	 * cache on its own.
	 */
	public static function purge_config_cache(): void {
		self::$config_cache = null;
	}

	/**
	 * Merge stored config over defaults so missing keys don't crash.
	 */
	private static function merge_with_defaults( array $stored ): array {
		$defaults = self::default_config();

		if ( isset( $stored['version'] ) ) {
			$defaults['version'] = (int) $stored['version'];
		}
		if ( isset( $stored['default_merchant_id'] ) ) {
			$defaults['default_merchant_id'] = sanitize_key( (string) $stored['default_merchant_id'] );
		}
		// Older 1.4.0-dev configs had a `conflict_strategy` field. It's
		// gone now — mixed carts always fall back to the default
		// merchant. We intentionally don't carry the value forward so the
		// option stays clean after the next save.

		if ( isset( $stored['merchants'] ) && is_array( $stored['merchants'] ) ) {
			foreach ( $stored['merchants'] as $id => $merchant ) {
				if ( ! is_array( $merchant ) ) {
					continue;
				}
				$normalised = self::normalise_merchant( $id, $merchant );
				if ( $normalised !== null ) {
					$defaults['merchants'][ $normalised['id'] ] = $normalised;
				}
			}
		}

		// Make sure default_merchant_id actually points at an existing
		// merchant. If not, fall back to the first one available, or to
		// the literal 'default' slug if there are no merchants at all (the
		// first-run case before the migration has fired).
		if ( ! isset( $defaults['merchants'][ $defaults['default_merchant_id'] ] ) ) {
			$existing_ids = array_keys( $defaults['merchants'] );
			$defaults['default_merchant_id'] = ! empty( $existing_ids )
				? (string) $existing_ids[0]
				: self::DEFAULT_MERCHANT_ID;
		}

		return $defaults;
	}

	/**
	 * Sanitise/normalise one merchant record. Returns null if it cannot
	 * be coerced into a valid shape (e.g. missing ID).
	 */
	public static function normalise_merchant( $id_hint, array $merchant ): ?array {
		$base = self::default_merchant();

		$id_candidate = isset( $merchant['id'] ) ? (string) $merchant['id'] : (string) $id_hint;
		$id           = sanitize_key( $id_candidate );
		if ( $id === '' ) {
			return null;
		}
		$base['id']                             = $id;
		$base['label']                          = sanitize_text_field( (string) ( $merchant['label']                          ?? $id ) );
		$base['api_key']                        = sanitize_text_field( (string) ( $merchant['api_key']                        ?? '' ) );
		$base['webhook_secret']                 = sanitize_text_field( (string) ( $merchant['webhook_secret']                 ?? '' ) );
		$base['staging']                        = (bool) ( $merchant['staging']                                                ?? false );
		$base['description_shipping_okoskabet'] = (string) ( $merchant['description_shipping_okoskabet']                       ?? '' );
		$base['description_shipping_private']   = (string) ( $merchant['description_shipping_private']                         ?? '' );
		$base['maximum_days_in_future']         = max( 1, (int) ( $merchant['maximum_days_in_future']                          ?? 3 ) );
		$pg                                     = sanitize_text_field( (string) ( $merchant['payment_gateway']                ?? 'auto' ) );
		$base['payment_gateway']                = in_array( $pg, array( 'auto', 'quickpay_gateway', 'stripe', 'nets_easy', 'pensopay', 'fallback' ), true ) ? $pg : 'auto';
		$base['capture_events']                 = self::sanitize_event_list( (array) ( $merchant['capture_events']            ?? array() ) );
		$base['webhook_events']                 = self::sanitize_event_list( (array) ( $merchant['webhook_events']            ?? array() ) );
		$base['product_categories']             = self::sanitize_id_list( (array) ( $merchant['product_categories']           ?? array() ) );
		$base['product_tags']                   = self::sanitize_id_list( (array) ( $merchant['product_tags']                 ?? array() ) );
		$base['priority']                       = (int) ( $merchant['priority']                                                ?? 10 );
		$base['created_at']                     = sanitize_text_field( (string) ( $merchant['created_at']                     ?? '' ) );
		if ( $base['created_at'] === '' ) {
			$base['created_at'] = gmdate( 'Y-m-d H:i:s' );
		}
		return $base;
	}

	private static function sanitize_event_list( array $list ): array {
		$allowed = array( 'label_printed', 'in_shed', 'order_delivered' );
		$out     = array();
		foreach ( $list as $event ) {
			$event = sanitize_text_field( (string) $event );
			if ( $event === 'label_created' ) {
				// Legacy event name still around in some stored payloads.
				$event = 'in_shed';
			}
			if ( in_array( $event, $allowed, true ) ) {
				$out[] = $event;
			}
		}
		return array_values( array_unique( $out ) );
	}

	private static function sanitize_id_list( array $list ): array {
		$out = array();
		foreach ( $list as $v ) {
			$n = (int) $v;
			if ( $n > 0 ) {
				$out[] = $n;
			}
		}
		return array_values( array_unique( $out ) );
	}

	// =========================================================================
	// Convenience accessors used across the plugin
	// =========================================================================

	/**
	 * @return array<string, array> All merchants keyed by id.
	 */
	public static function get_all(): array {
		return self::get_config()['merchants'];
	}

	/**
	 * Look up one merchant by ID.
	 *
	 * @param string $id
	 * @return array|null
	 */
	public static function get( string $id ): ?array {
		$config = self::get_config();
		$id     = sanitize_key( $id );
		return $config['merchants'][ $id ] ?? null;
	}

	/**
	 * The merchant used when no routing rule matches.
	 *
	 * @return array|null
	 */
	public static function get_default(): ?array {
		$config = self::get_config();
		return self::get( $config['default_merchant_id'] );
	}

	public static function default_id(): string {
		$config = self::get_config();
		return $config['default_merchant_id'];
	}

	/**
	 * True once at least one merchant exists (typically right after the
	 * migration creates the default). Callers can short-circuit gracefully
	 * when this is false — the plugin behaves like it always has.
	 */
	public static function has_any(): bool {
		return ! empty( self::get_all() );
	}

	/**
	 * True when the admin should see the full multi-merchant management UI
	 * (the merchants table, per-merchant edit form, routing rules etc.).
	 *
	 * For 95% of installs with exactly one Økoskabet account this returns
	 * `false` and the admin gets the legacy single-merchant CMB form
	 * unchanged. The advanced UI only kicks in when:
	 *
	 *   1. The site has more than one merchant configured, OR
	 *   2. The admin has explicitly opted into the advanced UI via the
	 *      `?oko_show_merchants=1` query string (the "+ Add another
	 *      Økoskabet merchant" link below the simple form sets this).
	 *
	 * Crucially, this is a UX-only flag — every routing/webhook/security
	 * path uses the merchants registry regardless. Toggling this flag
	 * does not affect the datamodel or webhook URLs.
	 */
	public static function is_multi_merchant_mode(): bool {
		if ( count( self::get_all() ) > 1 ) {
			return true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::QUERY_SHOW_MERCHANTS ] ) && (string) $_GET[ self::QUERY_SHOW_MERCHANTS ] === '1' ) {
			return true;
		}
		return false;
	}

	/**
	 * Mirror the default merchant's stored values into the legacy plugin
	 * settings option so the simple CMB form on the main settings page
	 * shows the current default-merchant values. Called from the settings
	 * view right before CMB2 reads the option.
	 *
	 * This keeps the simple form a true facade — the merchant record is
	 * always the source of truth, and the option row is rewritten from it
	 * on every render so admins switching back and forth between simple
	 * and advanced UI never see stale values.
	 *
	 * Skipped in multi-merchant mode (where the legacy fields aren't
	 * rendered anyway) and pre-migration (no default merchant yet).
	 */
	public static function mirror_default_to_options(): void {
		if ( self::is_multi_merchant_mode() ) {
			return;
		}
		$default = self::get_default();
		if ( ! $default ) {
			return;
		}

		$option_key = O_TEXTDOMAIN . '-settings';
		$option     = (array) get_option( $option_key, array() );
		$changed    = false;

		foreach ( self::$legacy_option_to_merchant as $opt_key => $merchant_key ) {
			$source = $default[ $merchant_key ] ?? null;
			// Translate bool→on/empty so CMB2 checkboxes render correctly.
			if ( $merchant_key === 'staging' ) {
				$source = ! empty( $source ) ? 'on' : '';
			}
			$current = array_key_exists( $opt_key, $option ) ? $option[ $opt_key ] : null;
			if ( $current !== $source ) {
				$option[ $opt_key ] = $source;
				$changed            = true;
			}
		}

		if ( $changed ) {
			update_option( $option_key, $option );
		}
	}

	/**
	 * After the simple CMB form is saved, copy the just-saved option values
	 * back into the default merchant record. The default merchant is the
	 * canonical source for all downstream routing/webhook code, so without
	 * this sync the form would appear to "save" but routing would still
	 * see the old credentials.
	 *
	 * Skipped in multi-merchant mode — the table-driven UI saves directly
	 * via `handle_save_merchant()` and we don't want a stray form
	 * submission on the main page to clobber a per-merchant value.
	 *
	 * @param int|string                  $object_id The option name.
	 * @param array<int|string,mixed>     $updated   List of CMB2 field IDs that changed.
	 * @param \CMB2|null                  $cmb       The CMB2 instance.
	 */
	public function handle_legacy_options_saved( $object_id, $updated, $cmb ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( self::is_multi_merchant_mode() ) {
			return;
		}

		$option = (array) get_option( O_TEXTDOMAIN . '-settings', array() );
		$config = self::get_config();
		$id     = $config['default_merchant_id'];

		if ( $id === '' ) {
			$id = self::DEFAULT_MERCHANT_ID;
		}

		$existing = $config['merchants'][ $id ] ?? self::default_merchant();
		$existing['id'] = $id;
		if ( empty( $existing['label'] ) ) {
			$existing['label'] = __( 'Default merchant', O_TEXTDOMAIN );
		}

		foreach ( self::$legacy_option_to_merchant as $opt_key => $merchant_key ) {
			if ( ! array_key_exists( $opt_key, $option ) ) {
				continue;
			}
			$value = $option[ $opt_key ];
			if ( $merchant_key === 'staging' ) {
				$value = ! empty( $value );
			}
			$existing[ $merchant_key ] = $value;
		}

		$normalised = self::normalise_merchant( $id, $existing );
		if ( $normalised === null ) {
			return;
		}
		$config['merchants'][ $id ]    = $normalised;
		$config['default_merchant_id'] = $id;
		self::save_config( $config );
	}

	/**
	 * Resolve a merchant's API base URL — staging vs production.
	 */
	public static function api_url_for( array $merchant ): string {
		return ! empty( $merchant['staging'] ) ? 'https://staging.okoskabet.dk' : 'https://okoskabet.dk';
	}

	// =========================================================================
	// Admin UI
	// =========================================================================

	public function render_section(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		// In single-merchant mode (the default for the vast majority of
		// installs) we deliberately render NOTHING merchant-related
		// except a small link to opt into the multi-merchant UI. The
		// simple CMB form on the main settings page already mirrors the
		// default merchant's values and is the only UI a single-merchant
		// admin should see.
		if ( ! self::is_multi_merchant_mode() ) {
			$show_url = add_query_arg(
				array( 'page' => O_TEXTDOMAIN, self::QUERY_SHOW_MERCHANTS => '1' ),
				admin_url( 'admin.php' )
			) . '#okoskabet-merchants';
			?>
			<div id="okoskabet-merchants" style="margin-top:24px;">
				<p>
					<a href="<?php echo esc_url( $show_url ); ?>">
						+ <?php esc_html_e( 'Add another Økoskabet merchant', O_TEXTDOMAIN ); ?>
					</a>
				</p>
			</div>
			<?php
			return;
		}

		$config    = self::get_config();
		$merchants = $config['merchants'];

		// Notice flags from query string.
		$saved   = isset( $_GET['oko_merchants_saved'] ) && $_GET['oko_merchants_saved'] === '1';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$deleted = isset( $_GET['oko_merchants_deleted'] ) && $_GET['oko_merchants_deleted'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tested  = isset( $_GET['oko_merchants_tested'] ) ? sanitize_text_field( wp_unslash( $_GET['oko_merchants_tested'] ) ) : '';  // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$editing_id = isset( $_GET['oko_merchant_edit'] ) ? sanitize_key( wp_unslash( $_GET['oko_merchant_edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$creating   = isset( $_GET['oko_merchant_new'] ) && $_GET['oko_merchant_new'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$tags       = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
		if ( is_wp_error( $categories ) ) { $categories = array(); }
		if ( is_wp_error( $tags ) )       { $tags       = array(); }

		?>
		<div id="okoskabet-merchants" style="margin-top:32px;">
			<h2><?php esc_html_e( 'Økoskabet merchants', O_TEXTDOMAIN ); ?></h2>
			<p>
				<?php esc_html_e( 'Configure one or more Økoskabet merchant accounts and decide which one fulfils each cart. The plugin reads cart contents at checkout and routes the order to the matching merchant — including its API credentials, webhook secret, delivery options and shipment submission endpoint.', O_TEXTDOMAIN ); ?>
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Merchant configuration saved.', O_TEXTDOMAIN ); ?></p></div>
			<?php endif; ?>
			<?php if ( $deleted ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Merchant deleted.', O_TEXTDOMAIN ); ?></p></div>
			<?php endif; ?>
			<?php if ( $tested === 'ok' ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Connection test succeeded.', O_TEXTDOMAIN ); ?></p></div>
			<?php elseif ( $tested !== '' ) : ?>
				<div class="notice notice-error is-dismissible"><p>
					<?php
					echo esc_html( sprintf(
						/* translators: %s = short error reason */
						__( 'Connection test failed: %s', O_TEXTDOMAIN ),
						$tested
					) );
					?>
				</p></div>
			<?php endif; ?>

			<?php $this->render_global_settings( $config ); ?>

			<?php
			// Always render the merchants table as context so admins can
			// see their existing merchants while creating/editing — the
			// "where did my first merchant go?" surprise is otherwise
			// genuinely disorienting in the create/edit flow.
			$this->render_merchant_list( $config );

			if ( $creating ) {
				$this->render_merchant_form( null, $categories, $tags );
			} elseif ( $editing_id !== '' && isset( $merchants[ $editing_id ] ) ) {
				$this->render_merchant_form( $merchants[ $editing_id ], $categories, $tags );
			}
			?>
		</div>

		<style>
			.oko-merchants-table { width:100%; border-collapse:collapse; margin-top:12px; background:#fff; }
			.oko-merchants-table th, .oko-merchants-table td { border:1px solid #c3c4c7; padding:10px 12px; text-align:left; vertical-align:top; }
			.oko-merchants-table th { background:#f6f7f7; }
			.oko-merchants-table .col-actions { width:280px; }
			.oko-merchants-table tr.is-default td:first-child::before { content:"\2605 "; color:#d4a017; }
			.oko-merchant-form { background:#fff; border:1px solid #c3c4c7; padding:20px 24px; margin:20px 0; }
			.oko-merchant-form table.form-table th { width:240px; }
			.oko-merchant-form input[type=text], .oko-merchant-form input[type=password], .oko-merchant-form input[type=number] { width:100%; max-width:520px; }
			.oko-merchant-form .oko-help { color:#666; font-size:0.9em; }
			.oko-section-divider { margin:24px 0; border:0; border-top:1px dashed #c3c4c7; }
			.oko-merchant-form .oko-multi { width:100%; max-width:520px; min-height:80px; }
			.oko-webhook-url { font-family:monospace; background:#f6f7f7; padding:6px 10px; border-radius:3px; display:inline-block; }
		</style>
		<?php
	}

	private function render_global_settings( array $config ): void {
		?>
		<div class="oko-merchant-form" style="margin-bottom:24px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Default merchant', O_TEXTDOMAIN ); ?></h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE_GLOBAL ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_GLOBAL ); ?>" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="oko-default-merchant"><?php esc_html_e( 'Default merchant', O_TEXTDOMAIN ); ?></label></th>
						<td>
							<select id="oko-default-merchant" name="default_merchant_id">
								<?php foreach ( $config['merchants'] as $m ) : ?>
									<option value="<?php echo esc_attr( $m['id'] ); ?>" <?php selected( $config['default_merchant_id'], $m['id'] ); ?>>
										<?php echo esc_html( $m['label'] !== '' ? $m['label'] : $m['id'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="oko-help">
								<?php esc_html_e( 'Used for any cart that isn\'t handled cleanly by another merchant. Additional merchants ONLY handle carts where every item matches that merchant\'s product categories, tags or per-product override. The moment a cart contains items from more than one merchant — for any reason — the cart routes to the default merchant. Mixed carts are never blocked and never split across merchants.', O_TEXTDOMAIN ); ?>
							</p>
							<p class="oko-help" style="color:#a44; font-weight:600;">
								<?php esc_html_e( 'Heads up: changing the default merchant also changes which merchant\'s webhook secret backs the legacy webhook URL (…/okoskabet/webhook without a merchant ID). Any pre-existing Økoskabet webhook still pointing at the legacy URL will start failing HMAC verification unless you also update Økoskabet\'s webhook secret to match the new default merchant — or, better, switch the webhook to the per-merchant URL listed for each merchant below.', O_TEXTDOMAIN ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save default merchant', O_TEXTDOMAIN ), 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	private function render_merchant_list( array $config ): void {
		$merchants    = $config['merchants'];
		$default_id   = $config['default_merchant_id'];
		$new_url      = add_query_arg(
			array(
				'page'                     => O_TEXTDOMAIN,
				self::QUERY_SHOW_MERCHANTS => '1',
				'oko_merchant_new'         => '1',
			),
			admin_url( 'admin.php' )
		) . '#okoskabet-merchants';
		$webhook_base = home_url( '/wp-json/wp/v2/okoskabet/webhook/' );

		?>
		<h3 style="margin-bottom:0;"><?php esc_html_e( 'Configured merchants', O_TEXTDOMAIN ); ?></h3>

		<?php if ( empty( $merchants ) ) : ?>
			<p><em><?php esc_html_e( 'No merchants configured yet.', O_TEXTDOMAIN ); ?></em></p>
		<?php else : ?>
			<table class="oko-merchants-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Merchant', O_TEXTDOMAIN ); ?></th>
						<th><?php esc_html_e( 'Environment', O_TEXTDOMAIN ); ?></th>
						<th><?php esc_html_e( 'Webhook URL', O_TEXTDOMAIN ); ?></th>
						<th><?php esc_html_e( 'Routes products with', O_TEXTDOMAIN ); ?></th>
						<th class="col-actions"><?php esc_html_e( 'Actions', O_TEXTDOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $merchants as $m ) :
						$is_default = $m['id'] === $default_id;
						$edit_url   = add_query_arg(
							array(
								'page'                     => O_TEXTDOMAIN,
								self::QUERY_SHOW_MERCHANTS => '1',
								'oko_merchant_edit'        => $m['id'],
							),
							admin_url( 'admin.php' )
						) . '#okoskabet-merchants';
						$webhook_url   = $webhook_base . rawurlencode( $m['id'] );
						$cat_names = $this->term_names( $m['product_categories'], 'product_cat' );
						$tag_names = $this->term_names( $m['product_tags'], 'product_tag' );
						?>
						<tr class="<?php echo $is_default ? 'is-default' : ''; ?>">
							<td>
								<strong><?php echo esc_html( $m['label'] !== '' ? $m['label'] : $m['id'] ); ?></strong>
								<br><code><?php echo esc_html( $m['id'] ); ?></code>
								<?php if ( $is_default ) : ?>
									<br><em><?php esc_html_e( 'Default merchant', O_TEXTDOMAIN ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php echo $m['staging']
									? '<span style="color:#d4a017;">' . esc_html__( 'Staging', O_TEXTDOMAIN ) . '</span>'
									: '<span style="color:#2a6a2a;">' . esc_html__( 'Production', O_TEXTDOMAIN ) . '</span>'; ?>
							</td>
							<td>
								<span class="oko-webhook-url"><?php echo esc_html( $webhook_url ); ?></span>
							</td>
							<td>
								<?php if ( ! empty( $cat_names ) ) : ?>
									<strong><?php esc_html_e( 'Categories:', O_TEXTDOMAIN ); ?></strong> <?php echo esc_html( implode( ', ', $cat_names ) ); ?><br>
								<?php endif; ?>
								<?php if ( ! empty( $tag_names ) ) : ?>
									<strong><?php esc_html_e( 'Tags:', O_TEXTDOMAIN ); ?></strong> <?php echo esc_html( implode( ', ', $tag_names ) ); ?>
								<?php endif; ?>
								<?php if ( empty( $cat_names ) && empty( $tag_names ) ) : ?>
									<em><?php esc_html_e( 'No category or tag rules — only routed to via default or per-product override.', O_TEXTDOMAIN ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<a class="button" href="<?php echo esc_url( $edit_url ); ?>">
									<?php esc_html_e( 'Edit', O_TEXTDOMAIN ); ?>
								</a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
									<?php wp_nonce_field( self::ACTION_TEST_CONNECTION ); ?>
									<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TEST_CONNECTION ); ?>" />
									<input type="hidden" name="merchant_id" value="<?php echo esc_attr( $m['id'] ); ?>" />
									<button class="button" type="submit"><?php esc_html_e( 'Test connection', O_TEXTDOMAIN ); ?></button>
								</form>
								<?php if ( ! $is_default ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete this merchant? Existing orders will keep working but new carts routed here will fall back to the default merchant.', O_TEXTDOMAIN ) ); ?>');">
										<?php wp_nonce_field( self::ACTION_DELETE_MERCHANT ); ?>
										<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE_MERCHANT ); ?>" />
										<input type="hidden" name="merchant_id" value="<?php echo esc_attr( $m['id'] ); ?>" />
										<button class="button-link" type="submit" style="color:#a00;"><?php esc_html_e( 'Delete', O_TEXTDOMAIN ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<p style="margin-top:16px;">
			<a class="button button-primary" href="<?php echo esc_url( $new_url ); ?>">
				+ <?php esc_html_e( 'Add merchant', O_TEXTDOMAIN ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render the create/edit form for a single merchant. Pass null to
	 * render the form pre-populated with default values for a NEW merchant.
	 *
	 * @param array|null $merchant
	 * @param \WP_Term[] $categories
	 * @param \WP_Term[] $tags
	 */
	private function render_merchant_form( ?array $merchant, array $categories, array $tags ): void {
		$is_new       = ( $merchant === null );
		$merchant     = $merchant ?? self::default_merchant();
		$back_url     = add_query_arg(
			array(
				'page'                     => O_TEXTDOMAIN,
				self::QUERY_SHOW_MERCHANTS => '1',
			),
			admin_url( 'admin.php' )
		) . '#okoskabet-merchants';
		// Render the webhook URL preview from the merchant's current slug,
		// or fall back to a placeholder when the slug is still empty (i.e.
		// a brand-new "Add merchant" form). A small inline JS below the
		// form keeps this in sync with whatever the admin types in the
		// Identifier field so they can copy the URL into Økoskabet's
		// webhook settings without having to save the merchant first.
		$webhook_base_url       = home_url( '/wp-json/wp/v2/okoskabet/webhook/' );
		$webhook_url_placeholder = __( '<your-identifier>', O_TEXTDOMAIN );
		$webhook_url            = $merchant['id'] !== ''
			? $webhook_base_url . rawurlencode( $merchant['id'] )
			: $webhook_base_url . $webhook_url_placeholder;

		?>
		<div class="oko-merchant-form">
			<h3 style="margin-top:0;"><?php echo $is_new
				? esc_html__( 'Add merchant', O_TEXTDOMAIN )
				: esc_html__( 'Edit merchant', O_TEXTDOMAIN ); ?></h3>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION_SAVE_MERCHANT ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_MERCHANT ); ?>" />
				<?php if ( ! $is_new ) : ?>
					<input type="hidden" name="merchant[original_id]" value="<?php echo esc_attr( $merchant['id'] ); ?>" />
				<?php endif; ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="merchant-label"><?php esc_html_e( 'Display name', O_TEXTDOMAIN ); ?></label></th>
						<td>
							<input type="text" id="merchant-label" name="merchant[label]" value="<?php echo esc_attr( $merchant['label'] ); ?>" required />
							<p class="oko-help"><?php esc_html_e( 'A human-readable name shown only inside this admin. Customers never see it directly.', O_TEXTDOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="merchant-id"><?php esc_html_e( 'Identifier (slug)', O_TEXTDOMAIN ); ?></label></th>
						<td>
							<input type="text" id="merchant-id" name="merchant[id]" value="<?php echo esc_attr( $merchant['id'] ); ?>" pattern="[a-z0-9_\-]+" required <?php disabled( ! $is_new && $merchant['id'] === self::DEFAULT_MERCHANT_ID ); ?> />
							<p class="oko-help"><?php esc_html_e( 'Lowercase letters, digits, dashes and underscores. Appears in the webhook URL — choose carefully because changing it later breaks any webhook URL already configured at Økoskabet.', O_TEXTDOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="merchant-api-key"><?php esc_html_e( 'API key', O_TEXTDOMAIN ); ?></label></th>
						<td>
							<input type="password" id="merchant-api-key" name="merchant[api_key]" value="<?php echo esc_attr( $merchant['api_key'] ); ?>" autocomplete="off" />
							<p class="oko-help"><?php esc_html_e( 'From Økoskabet\'s backoffice. Used for all API calls (sheds, dates, shipment creation).', O_TEXTDOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', O_TEXTDOMAIN ); ?></th>
						<td>
							<span class="oko-webhook-url" id="oko-webhook-url-preview"><?php echo esc_html( $webhook_url ); ?></span>
							<p class="oko-help"><?php esc_html_e( 'Configure this URL in Økoskabet\'s webhook settings for this merchant first — Økoskabet then gives you the webhook secret to paste below. Each merchant has its own URL so signatures can be verified against the correct secret.', O_TEXTDOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="merchant-webhook-secret"><?php esc_html_e( 'Webhook secret', O_TEXTDOMAIN ); ?></label></th>
						<td>
							<input type="password" id="merchant-webhook-secret" name="merchant[webhook_secret]" value="<?php echo esc_attr( $merchant['webhook_secret'] ); ?>" autocomplete="off" />
							<p class="oko-help"><?php esc_html_e( 'From Økoskabet\'s webhook configuration (generated when you register the webhook URL above). Used to verify incoming webhooks for this merchant only.', O_TEXTDOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Environment', O_TEXTDOMAIN ); ?></th>
						<td>
							<label><input type="checkbox" name="merchant[staging]" value="1" <?php checked( ! empty( $merchant['staging'] ) ); ?> /> <?php esc_html_e( 'Use staging API', O_TEXTDOMAIN ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="merchant-max-days"><?php esc_html_e( 'Standard display window (days)', O_TEXTDOMAIN ); ?></label></th>
						<td>
							<input type="number" id="merchant-max-days" name="merchant[maximum_days_in_future]" value="<?php echo esc_attr( $merchant['maximum_days_in_future'] ); ?>" min="1" max="60" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="merchant-desc-shed"><?php esc_html_e( 'Shed shipping description', O_TEXTDOMAIN ); ?></label></th>
						<td>
							<textarea id="merchant-desc-shed" name="merchant[description_shipping_okoskabet]" rows="2" style="width:100%;max-width:520px;"><?php echo esc_textarea( $merchant['description_shipping_okoskabet'] ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="merchant-desc-home"><?php esc_html_e( 'Home-delivery shipping description', O_TEXTDOMAIN ); ?></label></th>
						<td>
							<textarea id="merchant-desc-home" name="merchant[description_shipping_private]" rows="2" style="width:100%;max-width:520px;"><?php echo esc_textarea( $merchant['description_shipping_private'] ); ?></textarea>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="merchant-payment-gateway"><?php esc_html_e( 'Payment gateway', O_TEXTDOMAIN ); ?></label></th>
						<td>
							<select id="merchant-payment-gateway" name="merchant[payment_gateway]">
								<?php
								$gateways = array(
									'auto'             => __( 'Automatic (detect from order)', O_TEXTDOMAIN ),
									'quickpay_gateway' => __( 'Quickpay', O_TEXTDOMAIN ),
									'stripe'           => __( 'Stripe', O_TEXTDOMAIN ),
									'nets_easy'        => __( 'Nets Easy / DIBS Easy', O_TEXTDOMAIN ),
									'pensopay'         => __( 'Pensopay', O_TEXTDOMAIN ),
									'fallback'         => __( 'Other (change status to "processing")', O_TEXTDOMAIN ),
								);
								foreach ( $gateways as $key => $label ) :
									?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $merchant['payment_gateway'], $key ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Capture events', O_TEXTDOMAIN ); ?></th>
						<td>
							<?php
							$capture_options = array(
								'label_printed'   => __( 'Label Printed', O_TEXTDOMAIN ),
								'in_shed'         => __( 'In Shed', O_TEXTDOMAIN ),
								'order_delivered' => __( 'Order Delivered', O_TEXTDOMAIN ),
							);
							foreach ( $capture_options as $key => $label ) :
								?>
								<label style="display:block;"><input type="checkbox" name="merchant[capture_events][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $merchant['capture_events'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Completion events', O_TEXTDOMAIN ); ?></th>
						<td>
							<?php
							foreach ( $capture_options as $key => $label ) :
								?>
								<label style="display:block;"><input type="checkbox" name="merchant[webhook_events][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $merchant['webhook_events'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Route products in these categories', O_TEXTDOMAIN ); ?></th>
						<td>
							<select name="merchant[product_categories][]" multiple class="oko-multi">
								<?php $selected = array_map( 'strval', $merchant['product_categories'] ); ?>
								<?php foreach ( $categories as $cat ) :
									$cid = (string) (int) $cat->term_id; ?>
									<option value="<?php echo esc_attr( $cid ); ?>" <?php selected( in_array( $cid, $selected, true ) ); ?>>
										<?php echo esc_html( $cat->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="oko-help"><?php esc_html_e( 'Any product in any of these categories is routed to this merchant (unless a more specific per-product override is set).', O_TEXTDOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Route products with these tags', O_TEXTDOMAIN ); ?></th>
						<td>
							<select name="merchant[product_tags][]" multiple class="oko-multi">
								<?php $selected = array_map( 'strval', $merchant['product_tags'] ); ?>
								<?php foreach ( $tags as $tag ) :
									$tid = (string) (int) $tag->term_id; ?>
									<option value="<?php echo esc_attr( $tid ); ?>" <?php selected( in_array( $tid, $selected, true ) ); ?>>
										<?php echo esc_html( $tag->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="merchant-priority"><?php esc_html_e( 'Priority', O_TEXTDOMAIN ); ?></label></th>
						<td>
							<input type="number" id="merchant-priority" name="merchant[priority]" value="<?php echo esc_attr( $merchant['priority'] ); ?>" min="0" max="100" />
							<p class="oko-help"><?php esc_html_e( 'When a product matches multiple merchants (e.g. via both category and tag), the HIGHEST priority wins. Default 10. Use 50+ for "express" merchants that should always take precedence.', O_TEXTDOMAIN ); ?></p>
						</td>
					</tr>
				</table>

				<p>
					<?php submit_button( __( 'Save merchant', O_TEXTDOMAIN ), 'primary', 'submit', false ); ?>
					<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php esc_html_e( 'Cancel', O_TEXTDOMAIN ); ?></a>
				</p>
			</form>
		</div>
		<script>
			(function() {
				var idField = document.getElementById('merchant-id');
				var preview = document.getElementById('oko-webhook-url-preview');
				if (!idField || !preview) { return; }
				var baseUrl = <?php echo wp_json_encode( $webhook_base_url ); ?>;
				var placeholder = <?php echo wp_json_encode( $webhook_url_placeholder ); ?>;
				function update() {
					// Mirror the sanitize_key() PHP rules so the preview
					// matches what will actually be stored.
					var slug = idField.value.toLowerCase().replace(/[^a-z0-9_\-]/g, '');
					preview.textContent = baseUrl + (slug || placeholder);
				}
				idField.addEventListener('input', update);
				update();
			})();
		</script>
		<?php
	}

	private function term_names( array $term_ids, string $taxonomy ): array {
		$names = array();
		foreach ( $term_ids as $tid ) {
			$term = get_term( (int) $tid, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$names[] = $term->name;
			}
		}
		return $names;
	}

	// =========================================================================
	// Save handlers
	// =========================================================================

	public function handle_save_global(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have access.', O_TEXTDOMAIN ) );
		}
		check_admin_referer( self::ACTION_SAVE_GLOBAL );

		$config = self::get_config();
		$did    = isset( $_POST['default_merchant_id'] ) ? sanitize_key( wp_unslash( $_POST['default_merchant_id'] ) ) : '';

		if ( $did !== '' && isset( $config['merchants'][ $did ] ) ) {
			$config['default_merchant_id'] = $did;
		}

		self::save_config( $config );
		$this->redirect_back( array( 'oko_merchants_saved' => '1' ) );
	}

	public function handle_save_merchant(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have access.', O_TEXTDOMAIN ) );
		}
		check_admin_referer( self::ACTION_SAVE_MERCHANT );

		$raw = isset( $_POST['merchant'] ) && is_array( $_POST['merchant'] )
			? wp_unslash( $_POST['merchant'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			: array();

		$config      = self::get_config();
		$original_id = isset( $raw['original_id'] ) ? sanitize_key( (string) $raw['original_id'] ) : '';
		$is_default  = ( $original_id === $config['default_merchant_id'] );

		// Force the default merchant to keep its ID — we'd otherwise risk
		// breaking the migration's stable reference plus any webhook URL
		// already configured upstream.
		if ( $is_default ) {
			$raw['id'] = $config['default_merchant_id'];
		}

		// Translate single staging checkbox into bool.
		$raw['staging'] = ! empty( $raw['staging'] );

		$normalised = self::normalise_merchant( $raw['id'] ?? '', $raw );
		if ( $normalised === null ) {
			wp_die( esc_html__( 'Invalid merchant ID.', O_TEXTDOMAIN ) );
		}

		// Editing existing merchant whose ID changed? Move the record.
		if ( $original_id !== '' && $original_id !== $normalised['id'] ) {
			unset( $config['merchants'][ $original_id ] );
			if ( $config['default_merchant_id'] === $original_id ) {
				$config['default_merchant_id'] = $normalised['id'];
			}
			// Also rewrite any product meta that pointed at the old ID
			// so per-product routing doesn't silently fall back to default.
			$this->rewrite_product_meta_merchant( $original_id, $normalised['id'] );
			// And every order ever stamped with the old ID — otherwise
			// their webhooks would start failing the merchant-mismatch
			// check (HTTP 403) in OkoRest::handle_webhook.
			$this->rewrite_order_meta_merchant( $original_id, $normalised['id'] );
		}

		$config['merchants'][ $normalised['id'] ] = $normalised;
		self::save_config( $config );

		$this->redirect_back( array( 'oko_merchants_saved' => '1' ) );
	}

	public function handle_delete_merchant(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have access.', O_TEXTDOMAIN ) );
		}
		check_admin_referer( self::ACTION_DELETE_MERCHANT );

		$id     = isset( $_POST['merchant_id'] ) ? sanitize_key( wp_unslash( $_POST['merchant_id'] ) ) : '';
		$config = self::get_config();

		if ( $id !== '' && $id !== $config['default_merchant_id'] && isset( $config['merchants'][ $id ] ) ) {
			unset( $config['merchants'][ $id ] );
			self::save_config( $config );
		}

		$this->redirect_back( array( 'oko_merchants_deleted' => '1' ) );
	}

	public function handle_test_connection(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have access.', O_TEXTDOMAIN ) );
		}
		check_admin_referer( self::ACTION_TEST_CONNECTION );

		$id       = isset( $_POST['merchant_id'] ) ? sanitize_key( wp_unslash( $_POST['merchant_id'] ) ) : '';
		$merchant = $id !== '' ? self::get( $id ) : null;

		// `redirect_back()` itself calls exit. The explicit `return`s here
		// just keep static analysers happy that we aren't falling through
		// into the next branch.
		if ( ! $merchant ) {
			$this->redirect_back( array( 'oko_merchants_tested' => rawurlencode( __( 'Unknown merchant', O_TEXTDOMAIN ) ) ) );
			return;
		}
		if ( empty( $merchant['api_key'] ) ) {
			$this->redirect_back( array( 'oko_merchants_tested' => rawurlencode( __( 'API key not configured', O_TEXTDOMAIN ) ) ) );
			return;
		}

		$response = wp_remote_get( self::api_url_for( $merchant ) . '/api/v1/configuration', array(
			'timeout' => 10,
			'headers' => array(
				'Authorization' => $merchant['api_key'],
			),
		) );

		if ( is_wp_error( $response ) ) {
			$this->redirect_back( array( 'oko_merchants_tested' => rawurlencode( $response->get_error_message() ) ) );
			return;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$this->redirect_back( array( 'oko_merchants_tested' => rawurlencode( sprintf( 'HTTP %d', (int) $code ) ) ) );
			return;
		}

		// Bust the per-merchant shipping-methods cache so the next page
		// load sees the freshly-tested configuration.
		delete_transient( 'okoskabet_shipping_methods_' . $merchant['id'] );

		$this->redirect_back( array( 'oko_merchants_tested' => 'ok' ) );
	}

	/**
	 * Rewrite product_meta `_okoskabet_merchant` from $old to $new in a
	 * single SQL query — fast even on stores with many products.
	 *
	 * Products are still custom post types under WooCommerce (HPOS only
	 * moves orders out of the posts table), so a direct postmeta update
	 * is safe and correct here.
	 */
	private function rewrite_product_meta_merchant( string $old, string $new ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => $new ),
			array( 'meta_key' => '_okoskabet_merchant', 'meta_value' => $old ),
			array( '%s' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * Rewrite the per-order merchant stamp `_okoskabet_merchant_id` from
	 * $old → $new. Otherwise orders booked under the old ID would start
	 * failing the merchant-mismatch check on incoming webhooks (HTTP
	 * 403 in OkoRest::handle_webhook).
	 *
	 * HPOS-aware: we deliberately go through `wc_get_orders()` and
	 * `$order->update_meta_data()` rather than a raw postmeta UPDATE,
	 * because once HPOS is enabled the order data lives in
	 * `wp_wc_orders_meta` (or a custom table) and a postmeta UPDATE
	 * would silently miss those rows.
	 *
	 * Stores with N orders pay an O(N) cost here, but this only runs on
	 * the rare path where an admin renames a merchant ID — which the
	 * field's help text already warns about — so the cost is acceptable.
	 */
	private function rewrite_order_meta_merchant( string $old, string $new ): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$paged    = 1;
		$per_page = 200;

		// Defensive ceiling so a misconfigured callsite can never spin
		// forever; 200k orders is well beyond anything realistic for an
		// Økoskabet shop and we'd still complete this in a few minutes
		// at that size. Larger stores can re-run the rename — the loop
		// is idempotent.
		$safety_limit = 1000;

		while ( $paged <= $safety_limit ) {
			$orders = wc_get_orders( array(
				'limit'        => $per_page,
				'paged'        => $paged,
				'meta_key'     => Merchant_Router::ORDER_META_KEY,
				'meta_value'   => $old,
				'meta_compare' => '=',
				'return'       => 'objects',
				'orderby'      => 'ID',
				'order'        => 'ASC',
			) );

			if ( empty( $orders ) ) {
				break;
			}

			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					continue;
				}
				$order->update_meta_data( Merchant_Router::ORDER_META_KEY, $new );
				$order->save();
			}

			if ( count( $orders ) < $per_page ) {
				break;
			}
			$paged++;
		}
	}

	private function redirect_back( array $extra ): void {
		// Always preserve the multi-merchant UI flag so save/delete/test
		// handlers land the admin back on the merchants table rather than
		// silently falling out to the single-merchant simple form. (Doing
		// this unconditionally is safe: when count > 1 the flag is a no-op,
		// and these handlers are only ever reached from forms inside the
		// multi-merchant UI anyway.)
		$url = add_query_arg(
			array_merge(
				array(
					'page'                     => O_TEXTDOMAIN,
					self::QUERY_SHOW_MERCHANTS => '1',
				),
				$extra
			),
			admin_url( 'admin.php' )
		) . '#okoskabet-merchants';
		wp_safe_redirect( $url );
		exit;
	}
}
