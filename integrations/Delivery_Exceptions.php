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
 * Delivery Exceptions
 *
 * Lets the merchant define delivery-date exceptions and attach product
 * categories or tags to each exception. The exceptions are evaluated when a
 * customer goes through checkout, and they restrict which delivery dates the
 * customer can pick.
 *
 * Three exception types are supported, each can hold many entries:
 *
 *   1. weekdays   — products in the listed cats/tags can only be delivered on
 *                   the listed weekdays. One configuration block per weekday.
 *   2. only_on    — products in the listed cats/tags can only be delivered on
 *                   one specific date. Multiple "only_on" exceptions allowed.
 *   3. from_until — products in the listed cats/tags can only be delivered
 *                   between a start date and (optional) an end date. Multiple
 *                   "from_until" exceptions allowed.
 *
 * Combination rule: when a product matches multiple exceptions, ALL of them
 * must allow the date. Example: a product matched by "only mondays" + "from
 * 15 May" can only be delivered on a Monday on/after 15 May.
 *
 * Storage: a single wp_option row 'okoskabet_delivery_exceptions' containing
 * a JSON-serialised structure. We avoid Custom Post Types because exceptions
 * are configuration data, not content.
 */
class Delivery_Exceptions extends Base {

	/** wp_option key holding the entire exceptions configuration. */
	const OPTION_KEY = 'okoskabet_delivery_exceptions';

	/** Capability required to manage exceptions. */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Identifies the one-time upgrade notice that tells merchants to review
	 * their delivery-day setup after the rules engine changed. Bump this only
	 * if a future change warrants showing the notice again. Stored (when
	 * dismissed) in the option below.
	 */
	const UPGRADE_NOTICE_KEY    = 'delivery-days-v1';
	const UPGRADE_NOTICE_OPTION = 'okoskabet_delivery_notice_dismissed';

	/**
	 * The exception families that carry a per-section display-limit override,
	 * mapped to the rule `type` collect_applicable_rules() emits for each. This
	 * is the single source of truth for the section list — config defaults,
	 * merge, save, and limit-resolution all derive from it.
	 */
	const LIMIT_SECTIONS = array(
		'weekdays'   => 'weekday_set',
		'only_on'    => 'only_on',
		'from_until' => 'from_until',
	);

	/**
	 * A week of head-room added past any rule-referenced date when sizing the
	 * API query window, so a date on the window boundary isn't cut off by the
	 * API's lead-time/cutoff offset (and the first matching weekday after a
	 * "from" date is fetched).
	 */
	const QUERY_WINDOW_BUFFER_DAYS = 7;

	/**
	 * Initialize.
	 */
	public function initialize() {
		parent::initialize();

		// Render the exceptions UI as a panel inside the existing plugin
		// settings page (instead of a separate submenu page). The hook fires
		// at the bottom of the admin.php template.
		add_action( 'okoskabet_after_settings_form', array( $this, 'render_section' ) );

		// Form submissions from the panel POST to admin-post.php with this action.
		add_action( 'admin_post_okoskabet_save_exceptions', array( $this, 'handle_save' ) );

		// Hook into the date-filter pipeline that already exists in OkoRest.
		// Filter signature: ($dates, $product_ids).
		add_filter( 'okoskabet_filtered_delivery_dates', array( $this, 'filter_dates_for_cart' ), 10, 3 );

		// One-time upgrade notice: after the delivery-rules change, remind
		// merchants to double-check their delivery-day setup in BOTH the
		// plugin and the Økoskabet back office. Only wire up the hooks while the
		// notice is still pending, so a dismissed notice costs nothing on every
		// later admin page load.
		if ( is_admin() && get_option( self::UPGRADE_NOTICE_OPTION ) !== self::UPGRADE_NOTICE_KEY ) {
			add_action( 'admin_notices', array( $this, 'maybe_render_upgrade_notice' ) );
			add_action( 'admin_init', array( $this, 'maybe_dismiss_upgrade_notice' ) );
		}
	}

	/**
	 * Render the "review your delivery-day setup" notice, unless it has been
	 * dismissed. Dismissible and persists across page loads (the dismiss link
	 * stores a flag server-side; the native is-dismissible X only hides it for
	 * the current view).
	 */
	public function maybe_render_upgrade_notice(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( get_option( self::UPGRADE_NOTICE_OPTION ) === self::UPGRADE_NOTICE_KEY ) {
			return;
		}
		// Only a shop that has delivery rules set up has anything to review.
		// Everyone else would read "please review" as a job they don't have.
		if ( ! self::is_in_use() ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=' . O_TEXTDOMAIN . '#okoskabet-delivery-exceptions' );
		$dismiss_url  = wp_nonce_url(
			add_query_arg( 'okoskabet_dismiss_delivery_notice', '1' ),
			'okoskabet_dismiss_delivery_notice'
		);
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php echo esc_html( O_NAME ); ?>:</strong>
				<?php esc_html_e( 'The delivery-day logic has been updated. Please review your delivery-day setup so dates show correctly in checkout — settings now need to match in BOTH the Økoskabet plugin and the Økoskabet back office.', O_TEXTDOMAIN ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Open delivery settings', O_TEXTDOMAIN ); ?>
				</a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button">
					<?php esc_html_e( 'Got it, dismiss', O_TEXTDOMAIN ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Whether the shop has set up any delivery rules at all: a section or the
	 * cutoff switched on, a display limit, or the old cutoff tag list that is
	 * carried over into rules. Read from what is stored, not the defaults.
	 */
	public static function is_in_use(): bool {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) || empty( $stored ) ) {
			return false;
		}
		foreach ( array( 'weekdays_enabled', 'only_on_enabled', 'from_until_enabled', 'cutoff_enabled' ) as $k ) {
			if ( ! empty( $stored[ $k ] ) ) {
				return true;
			}
		}

		return (int) ( $stored['display_value'] ?? 0 ) > 0 || ! empty( $stored['cutoff_tags'] );
	}

	/**
	 * Persist dismissal of the upgrade notice when the merchant clicks the
	 * dismiss link, then redirect to a clean URL.
	 */
	public function maybe_dismiss_upgrade_notice(): void {
		if ( empty( $_GET['okoskabet_dismiss_delivery_notice'] ) ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		check_admin_referer( 'okoskabet_dismiss_delivery_notice' );

		update_option( self::UPGRADE_NOTICE_OPTION, self::UPGRADE_NOTICE_KEY );

		wp_safe_redirect( remove_query_arg( array( 'okoskabet_dismiss_delivery_notice', '_wpnonce' ) ) );
		exit;
	}

	// =========================================================================
	// Storage
	// =========================================================================

	/**
	 * Default empty configuration.
	 *
	 * @return array
	 */
	public static function default_config(): array {
		return array(
			// Master toggles for each exception family.
			'weekdays_enabled'   => false,
			'only_on_enabled'    => false,
			'from_until_enabled' => false,

			// How many delivery days to show in checkout.
			//   display_mode  'window' = within N calendar days, 'count' = first N dates.
			//   display_value 0 = not configured (legacy: no display trimming;
			//                 the API query window still uses the merchant's
			//                 maximum_days_in_future).
			'display_mode'  => 'window',
			'display_value' => 0,

			// Per-section "special" overrides of the display value. mode is
			// 'global' (use display_value) or 'special' (use *_limit_value).
			// When several apply to one cart, the smallest value wins.
			'weekdays_limit_mode'    => 'global',
			'weekdays_limit_value'   => 0,
			'only_on_limit_mode'     => 'global',
			'only_on_limit_value'    => 0,
			'from_until_limit_mode'  => 'global',
			'from_until_limit_value' => 0,

			// Weekdays family: 7 entries (0=Sun…6=Sat in PHP date('w')).
			// Each entry has its own enabled flag and lists of cat/tag IDs.
			'weekdays' => array(
				0 => array( 'enabled' => false, 'categories' => array(), 'tags' => array() ), // Sun
				1 => array( 'enabled' => false, 'categories' => array(), 'tags' => array() ), // Mon
				2 => array( 'enabled' => false, 'categories' => array(), 'tags' => array() ), // Tue
				3 => array( 'enabled' => false, 'categories' => array(), 'tags' => array() ), // Wed
				4 => array( 'enabled' => false, 'categories' => array(), 'tags' => array() ), // Thu
				5 => array( 'enabled' => false, 'categories' => array(), 'tags' => array() ), // Fri
				6 => array( 'enabled' => false, 'categories' => array(), 'tags' => array() ), // Sat
			),

			// only_on: list of {label, date, enabled, extend, categories, tags}.
			'only_on'   => array(),

			// What the checkout's pre-order button says, in each direction.
			// Empty means the built-in wording.
			'pre_order_label'    => '',
			'normal_order_label' => '',

			// A note shown above the buttons while a pre-order is chosen, e.g.
			// what cannot be pre-ordered. Off until the shop turns it on.
			'pre_order_notice_enabled' => false,
			'pre_order_notice'         => '',

			// from_until: list of {label, from, until, enabled, categories, tags}.
			'from_until' => array(),

			// Per-rule cutoff. Each rule closes ordering for products carrying a
			// chosen category and/or tag a set number of days before delivery, at
			// a set time of day. Rules are independent, so (say) fresh produce and
			// dairy can each have their own days/time. A cart's delivery date is
			// dropped when ANY applicable rule's cutoff moment has passed. Cutoff
			// is enforced here because the API doesn't expose the merchant's cutoff
			// yet; if it starts to, prefer that source (see backend PR #1412).
			'cutoff_enabled' => false,
			// list of { label:string, days:int, time:string, enabled:bool, categories:int[], tags:int[] }
			'cutoff_rules'   => array(),
		);
	}

	/**
	 * Load configuration with sane defaults.
	 */
	public static function get_config(): array {
		// Read once per request: the date filter runs for every shed and every
		// pickup location, and each call would otherwise rebuild the whole
		// default config and re-merge it. Cleared by purge_rules_cache().
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
	 * Merge stored config over defaults so missing keys don't crash.
	 */
	private static function merge_with_defaults( array $stored ): array {
		$defaults = self::default_config();

		// Top-level scalar keys.
		foreach ( array( 'weekdays_enabled', 'only_on_enabled', 'from_until_enabled' ) as $k ) {
			if ( isset( $stored[ $k ] ) ) {
				$defaults[ $k ] = (bool) $stored[ $k ];
			}
		}

		foreach ( array( 'pre_order_label', 'normal_order_label' ) as $k ) {
			if ( isset( $stored[ $k ] ) ) {
				$defaults[ $k ] = sanitize_text_field( (string) $stored[ $k ] );
			}
		}
		$defaults['pre_order_notice_enabled'] = ! empty( $stored['pre_order_notice_enabled'] );
		if ( isset( $stored['pre_order_notice'] ) ) {
			$defaults['pre_order_notice'] = sanitize_textarea_field( (string) $stored['pre_order_notice'] );
		}

		// Display settings.
		if ( isset( $stored['display_mode'] ) ) {
			$defaults['display_mode'] = ( $stored['display_mode'] === 'count' ) ? 'count' : 'window';
		}
		if ( isset( $stored['display_value'] ) ) {
			$defaults['display_value'] = max( 0, (int) $stored['display_value'] );
		}
		foreach ( array_keys( self::LIMIT_SECTIONS ) as $section ) {
			$mode_key  = $section . '_limit_mode';
			$value_key = $section . '_limit_value';
			if ( isset( $stored[ $mode_key ] ) ) {
				$defaults[ $mode_key ] = ( $stored[ $mode_key ] === 'special' ) ? 'special' : 'global';
			}
			if ( isset( $stored[ $value_key ] ) ) {
				$defaults[ $value_key ] = max( 0, (int) $stored[ $value_key ] );
			}
		}

		// Weekdays.
		if ( isset( $stored['weekdays'] ) && is_array( $stored['weekdays'] ) ) {
			foreach ( $defaults['weekdays'] as $w => $entry ) {
				if ( isset( $stored['weekdays'][ $w ] ) && is_array( $stored['weekdays'][ $w ] ) ) {
					$defaults['weekdays'][ $w ] = array(
						'enabled'    => (bool) ( $stored['weekdays'][ $w ]['enabled']    ?? false ),
						'categories' => array_map( 'intval', (array) ( $stored['weekdays'][ $w ]['categories'] ?? array() ) ),
						'tags'       => array_map( 'intval', (array) ( $stored['weekdays'][ $w ]['tags']       ?? array() ) ),
					);
				}
			}
		}

		// only_on entries.
		if ( isset( $stored['only_on'] ) && is_array( $stored['only_on'] ) ) {
			$defaults['only_on'] = array();
			foreach ( $stored['only_on'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$defaults['only_on'][] = array(
					'label'      => sanitize_text_field( (string) ( $item['label']   ?? '' ) ),
					'date'       => sanitize_text_field( (string) ( $item['date']    ?? '' ) ),
					'enabled'    => (bool) ( $item['enabled'] ?? true ),
					// Off unless ticked, as for from/until: a rule saved before
					// this existed means "only on this day", and stays that.
					'extend'     => (bool) ( $item['extend'] ?? false ),
					'categories' => array_map( 'intval', (array) ( $item['categories'] ?? array() ) ),
					'tags'       => array_map( 'intval', (array) ( $item['tags']       ?? array() ) ),
				);
			}
		}

		// from_until entries.
		if ( isset( $stored['from_until'] ) && is_array( $stored['from_until'] ) ) {
			$defaults['from_until'] = array();
			foreach ( $stored['from_until'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$defaults['from_until'][] = array(
					'label'      => sanitize_text_field( (string) ( $item['label']   ?? '' ) ),
					'from'       => sanitize_text_field( (string) ( $item['from']    ?? '' ) ),
					'until'      => sanitize_text_field( (string) ( $item['until']   ?? '' ) ),
					'enabled'    => (bool) ( $item['enabled'] ?? true ),
					// Off unless a merchant ticks it. Rules saved before this
					// existed are restrictions, and turning every one of them
					// into an extension would, for a year-round rule, put a
					// year of dates in front of that merchant's customers.
					'extend'     => (bool) ( $item['extend'] ?? false ),
					'categories' => array_map( 'intval', (array) ( $item['categories'] ?? array() ) ),
					'tags'       => array_map( 'intval', (array) ( $item['tags']       ?? array() ) ),
				);
			}
		}

		// Per-rule cutoff.
		if ( isset( $stored['cutoff_enabled'] ) ) {
			$defaults['cutoff_enabled'] = (bool) $stored['cutoff_enabled'];
		}
		if ( isset( $stored['cutoff_rules'] ) && is_array( $stored['cutoff_rules'] ) ) {
			$defaults['cutoff_rules'] = array();
			foreach ( $stored['cutoff_rules'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$cats = array_map( 'intval', (array) ( $item['categories'] ?? array() ) );
				$tags = array_map( 'intval', (array) ( $item['tags'] ?? array() ) );
				if ( empty( $cats ) && empty( $tags ) ) {
					continue; // a rule with no target does nothing.
				}
				$defaults['cutoff_rules'][] = array(
					'label'      => sanitize_text_field( (string) ( $item['label'] ?? '' ) ),
					'days'       => max( 0, (int) ( $item['days'] ?? 1 ) ),
					'time'       => preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $item['time'] ?? '' ) ) ? (string) $item['time'] : '09:00',
					'enabled'    => (bool) ( $item['enabled'] ?? true ),
					'categories' => $cats,
					'tags'       => $tags,
				);
			}
		} elseif ( isset( $stored['cutoff_tags'] ) && is_array( $stored['cutoff_tags'] ) ) {
			// Legacy migration: the old model was a global lead + time plus a list
			// of per-tag offsets. Fold each into a self-contained rule so saved
			// settings keep working after the upgrade.
			$legacy_lead = max( 0, (int) ( $stored['cutoff_lead_days'] ?? 1 ) );
			$legacy_time = preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $stored['cutoff_time'] ?? '' ) ) ? (string) $stored['cutoff_time'] : '09:00';
			$defaults['cutoff_rules'] = array();
			foreach ( $stored['cutoff_tags'] as $item ) {
				if ( ! is_array( $item ) || empty( $item['tag'] ) ) {
					continue;
				}
				$defaults['cutoff_rules'][] = array(
					'label'      => '',
					'days'       => $legacy_lead + max( 0, (int) ( $item['offset_days'] ?? 0 ) ),
					'time'       => $legacy_time,
					'enabled'    => (bool) ( $item['enabled'] ?? true ),
					'categories' => array(),
					'tags'       => array( (int) $item['tag'] ),
				);
			}
		}

		return $defaults;
	}

	// =========================================================================
	// Section rendering (mounted inside the main plugin settings page)
	// =========================================================================

	public function render_section(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$config     = self::get_config();
		$categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$tags       = get_terms( array( 'taxonomy' => 'product_tag', 'hide_empty' => false ) );
		if ( is_wp_error( $categories ) ) { $categories = array(); }
		if ( is_wp_error( $tags ) )       { $tags       = array(); }

		// Notice on save.
		$saved = isset( $_GET['oko_exc_saved'] ) && $_GET['oko_exc_saved'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification

		?>
		<div id="okoskabet-delivery-exceptions" style="margin-top:32px;">
			<h2><?php esc_html_e( 'Delivery Exceptions', O_TEXTDOMAIN ); ?></h2>

			<p>
				<?php esc_html_e( 'Define exceptions for delivery dates based on categories and tags. The rules are exceptions — products not associated with a rule have no restriction.', O_TEXTDOMAIN ); ?>
				<br>
				<?php esc_html_e( 'If a product matches multiple rules, ALL rules must be satisfied for a date to be shown.', O_TEXTDOMAIN ); ?>
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Delivery exceptions saved.', O_TEXTDOMAIN ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'okoskabet_save_exceptions' ); ?>
				<input type="hidden" name="action" value="okoskabet_save_exceptions" />

				<?php
				$this->render_display_section( $config );
				$this->render_weekdays_section( $config, $categories, $tags );
				$this->render_only_on_section( $config, $categories, $tags );
				$this->render_from_until_section( $config, $categories, $tags );
				$this->render_cutoff_section( $config, $categories, $tags );
				?>

				<?php submit_button( __( 'Save delivery exceptions', O_TEXTDOMAIN ) ); ?>
			</form>
		</div>

		<style>
			.oko-section { background:#fff; border:1px solid #c3c4c7; padding:16px 20px; margin:24px 0; }
			.oko-section h2 { margin-top:0; }
			.oko-section.is-disabled .oko-section-body { opacity:0.45; pointer-events:none; }
			.oko-row { border-top:1px solid #f0f0f1; padding:12px 0; }
			.oko-row:first-of-type { border-top:0; }
			.oko-row-header { font-weight:600; margin-bottom:6px; }
			.oko-row-fields { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
			.oko-row-actions { margin-top:8px; }
			.oko-multi { width:100%; min-height:80px; }
			.oko-help { color:#666; font-size:0.9em; margin-top:4px; }
			.oko-master-toggle { font-weight:600; font-size:1em; }
			.oko-add-btn { margin-top:8px; }
			.oko-row-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:8px; }
			.oko-row-row label { font-weight:600; }
		</style>

		<script>
		(function(){
			document.addEventListener('change', function(e){
				if (e.target.matches('.oko-master-toggle input[type=checkbox]')) {
					var section = e.target.closest('.oko-section');
					if (section) {
						section.classList.toggle('is-disabled', !e.target.checked);
					}
				}
			});

			document.addEventListener('click', function(e){
				if (e.target.matches('.oko-remove-row')) {
					e.preventDefault();
					var row = e.target.closest('.oko-row');
					if (row) row.remove();
				}
				if (e.target.matches('.oko-add-only-on')) {
					e.preventDefault();
					addRow('only_on_rows', buildOnlyOnRow(nextIndex('only_on_rows')));
				}
				if (e.target.matches('.oko-add-from-until')) {
					e.preventDefault();
					addRow('from_until_rows', buildFromUntilRow(nextIndex('from_until_rows')));
				}
				if (e.target.matches('.oko-add-cutoff')) {
					e.preventDefault();
					addRow('cutoff_rows', buildCutoffRow(nextIndex('cutoff_rows')));
				}
			});

			function nextIndex(containerId) {
				var c = document.getElementById(containerId);
				if (!c) return 0;
				return c.querySelectorAll('.oko-row').length;
			}

			function addRow(containerId, html) {
				var c = document.getElementById(containerId);
				if (!c) return;
				var wrap = document.createElement('div');
				wrap.innerHTML = html;
				c.appendChild(wrap.firstElementChild);
			}

			function buildOnlyOnRow(i) {
				var tpl = document.getElementById('oko-template-only-on');
				if (!tpl) return '';
				return tpl.innerHTML.replace(/__INDEX__/g, i);
			}
			function buildFromUntilRow(i) {
				var tpl = document.getElementById('oko-template-from-until');
				if (!tpl) return '';
				return tpl.innerHTML.replace(/__INDEX__/g, i);
			}
			function buildCutoffRow(i) {
				var tpl = document.getElementById('oko-template-cutoff');
				if (!tpl) return '';
				return tpl.innerHTML.replace(/__INDEX__/g, i);
			}
		})();
		</script>

		<?php
		// JS templates for new rows (uses __INDEX__ placeholder).
		echo '<script type="text/template" id="oko-template-only-on">';
		$this->render_only_on_row( '__INDEX__', array( 'label' => '', 'date' => '', 'enabled' => true, 'extend' => false, 'categories' => array(), 'tags' => array() ), $categories, $tags );
		echo '</script>';

		echo '<script type="text/template" id="oko-template-from-until">';
		$this->render_from_until_row( '__INDEX__', array( 'label' => '', 'from' => '', 'until' => '', 'enabled' => true, 'extend' => false, 'categories' => array(), 'tags' => array() ), $categories, $tags );
		echo '</script>';

		echo '<script type="text/template" id="oko-template-cutoff">';
		$this->render_cutoff_row( '__INDEX__', array( 'label' => '', 'days' => 1, 'time' => '09:00', 'enabled' => true, 'categories' => array(), 'tags' => array() ), $categories, $tags );
		echo '</script>';
	}

	/**
	 * Per-rule cutoff section. Each rule closes ordering for products in a chosen
	 * category and/or tag a number of days before delivery, at a set time.
	 */
	private function render_cutoff_section( array $config, array $categories, array $tags ): void {
		$enabled = ! empty( $config['cutoff_enabled'] );
		$rows    = (array) ( $config['cutoff_rules'] ?? array() );
		?>
		<div class="oko-section <?php echo $enabled ? '' : 'is-disabled'; ?>">
			<label class="oko-master-toggle">
				<input type="checkbox" name="cutoff_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Enable: Earlier cutoff rules', O_TEXTDOMAIN ); ?>
			</label>
			<p class="oko-help">
				<?php esc_html_e( 'Each rule closes ordering for the chosen categories and/or tags a number of days before delivery, at a set time — so e.g. fresh produce and dairy can each have their own deadline. If several rules apply to the cart, the earliest deadline wins.', O_TEXTDOMAIN ); ?>
			</p>
			<div class="oko-section-body">
				<div id="cutoff_rows">
					<?php foreach ( $rows as $i => $row ) : ?>
						<?php $this->render_cutoff_row( (string) $i, $row, $categories, $tags ); ?>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button oko-add-btn oko-add-cutoff">
					+ <?php esc_html_e( 'Add cutoff rule', O_TEXTDOMAIN ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	private function render_cutoff_row( $index, array $row, array $categories, array $tags ): void {
		?>
		<div class="oko-row">
			<div class="oko-row-row">
				<label><?php esc_html_e( 'Navn', O_TEXTDOMAIN ); ?>:
					<input type="text" name="cutoff_rules[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Fresh produce', O_TEXTDOMAIN ); ?>" style="width:200px;" />
				</label>
				<label><?php esc_html_e( 'Days before delivery', O_TEXTDOMAIN ); ?>:
					<input type="number" min="0" step="1" name="cutoff_rules[<?php echo esc_attr( $index ); ?>][days]" value="<?php echo esc_attr( (string) (int) ( $row['days'] ?? 1 ) ); ?>" style="width:70px;" />
				</label>
				<label><?php esc_html_e( 'Cutoff time', O_TEXTDOMAIN ); ?>:
					<input type="time" name="cutoff_rules[<?php echo esc_attr( $index ); ?>][time]" value="<?php echo esc_attr( (string) ( $row['time'] ?? '09:00' ) ); ?>" />
				</label>
				<label>
					<input type="checkbox" name="cutoff_rules[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> />
					<?php esc_html_e( 'Aktiv', O_TEXTDOMAIN ); ?>
				</label>
				<button type="button" class="button-link oko-remove-row" style="color:#a00;">
					<?php esc_html_e( 'Fjern', O_TEXTDOMAIN ); ?>
				</button>
			</div>
			<div class="oko-row-fields">
				<div>
					<label><?php esc_html_e( 'Kategorier', O_TEXTDOMAIN ); ?></label>
					<?php $this->render_term_select( "cutoff_rules[$index][categories][]", $categories, (array) ( $row['categories'] ?? array() ) ); ?>
				</div>
				<div>
					<label><?php esc_html_e( 'Tags', O_TEXTDOMAIN ); ?></label>
					<?php $this->render_term_select( "cutoff_rules[$index][tags][]", $tags, (array) ( $row['tags'] ?? array() ) ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Global "how many delivery days to show" control. Two modes: a number of
	 * delivery dates, or a calendar window in days.
	 */
	private function render_display_section( array $config ): void {
		$mode  = $config['display_mode'] ?? 'window'; // already normalised by get_config()
		$value = (int) ( $config['display_value'] ?? 0 );
		?>
		<div class="oko-section">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Delivery days shown in checkout', O_TEXTDOMAIN ); ?></h3>
			<p class="oko-help">
				<?php esc_html_e( 'Choose how many delivery days the customer sees. Leave the number at 0 to keep the existing behaviour (the merchant\'s "maximum days in future" setting).', O_TEXTDOMAIN ); ?>
			</p>
			<div class="oko-row-row">
				<label>
					<input type="radio" name="display_mode" value="count" <?php checked( $mode, 'count' ); ?> />
					<?php esc_html_e( 'Number of delivery days', O_TEXTDOMAIN ); ?>
				</label>
				<label>
					<input type="radio" name="display_mode" value="window" <?php checked( $mode, 'window' ); ?> />
					<?php esc_html_e( 'Number of calendar days ahead', O_TEXTDOMAIN ); ?>
				</label>
				<label>
					<?php esc_html_e( 'Value', O_TEXTDOMAIN ); ?>:
					<input type="number" min="0" step="1" name="display_value" value="<?php echo esc_attr( (string) $value ); ?>" style="width:80px;" />
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Per-section override of the global display value. Lets a merchant say,
	 * e.g., "globally show 4 days, but for weekday products show only 1".
	 *
	 * @param array  $config
	 * @param string $section weekdays | only_on | from_until
	 */
	private function render_section_limit_control( array $config, string $section ): void {
		$mode  = ( ( $config[ $section . '_limit_mode' ] ?? 'global' ) === 'special' ) ? 'special' : 'global';
		$value = (int) ( $config[ $section . '_limit_value' ] ?? 0 );
		?>
		<div class="oko-row" style="background:#f6f7f7;">
			<div class="oko-row-row">
				<label class="oko-limit-toggle">
					<input type="checkbox" name="<?php echo esc_attr( $section ); ?>_limit_mode" value="special" <?php checked( $mode, 'special' ); ?> />
					<?php esc_html_e( 'Show a special number of delivery days for products in this section', O_TEXTDOMAIN ); ?>
				</label>
				<label>
					<?php esc_html_e( 'Value', O_TEXTDOMAIN ); ?>:
					<input type="number" min="0" step="1" name="<?php echo esc_attr( $section ); ?>_limit_value" value="<?php echo esc_attr( (string) $value ); ?>" style="width:80px;" />
				</label>
			</div>
			<p class="oko-help">
				<?php esc_html_e( 'Uses the same mode (number of days / calendar days) as the global setting. When a cart mixes limits, the smallest wins.', O_TEXTDOMAIN ); ?>
			</p>
		</div>
		<?php
	}

	private function render_weekdays_section( array $config, array $categories, array $tags ): void {
		$enabled    = ! empty( $config['weekdays_enabled'] );
		$weekdays   = $config['weekdays'];
		// Display weekdays in Mon→Sun order (Danish convention).
		$display_order = array( 1, 2, 3, 4, 5, 6, 0 );
		$labels = array(
			1 => __( 'Monday', O_TEXTDOMAIN ),
			2 => __( 'Tuesday', O_TEXTDOMAIN ),
			3 => __( 'Wednesday', O_TEXTDOMAIN ),
			4 => __( 'Thursday', O_TEXTDOMAIN ),
			5 => __( 'Friday', O_TEXTDOMAIN ),
			6 => __( 'Saturday', O_TEXTDOMAIN ),
			0 => __( 'Sunday', O_TEXTDOMAIN ),
		);
		?>
		<div class="oko-section <?php echo $enabled ? '' : 'is-disabled'; ?>">
			<label class="oko-master-toggle">
				<input type="checkbox" name="weekdays_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Enable: Delivery only on fixed weekdays', O_TEXTDOMAIN ); ?>
			</label>
			<p class="oko-help">
				<?php esc_html_e( 'Under each weekday, choose which categories and/or tags may ONLY be delivered on that day. Products without an association have no restriction.', O_TEXTDOMAIN ); ?>
			</p>
			<div class="oko-section-body">
				<?php $this->render_section_limit_control( $config, 'weekdays' ); ?>
				<?php foreach ( $display_order as $w ) : ?>
					<?php
					$entry = $weekdays[ $w ] ?? array( 'enabled' => false, 'categories' => array(), 'tags' => array() );
					?>
					<div class="oko-row">
						<div class="oko-row-row">
							<label>
								<input type="checkbox" name="weekdays[<?php echo (int) $w; ?>][enabled]" value="1" <?php checked( ! empty( $entry['enabled'] ) ); ?> />
								<?php echo esc_html( $labels[ $w ] ); ?>
							</label>
						</div>
						<div class="oko-row-fields">
							<div>
								<label><?php esc_html_e( 'Categories', O_TEXTDOMAIN ); ?></label>
								<?php $this->render_term_select( "weekdays[$w][categories][]", $categories, (array) ( $entry['categories'] ?? array() ) ); ?>
							</div>
							<div>
								<label><?php esc_html_e( 'Tags', O_TEXTDOMAIN ); ?></label>
								<?php $this->render_term_select( "weekdays[$w][tags][]", $tags, (array) ( $entry['tags'] ?? array() ) ); ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	private function render_only_on_section( array $config, array $categories, array $tags ): void {
		$enabled = ! empty( $config['only_on_enabled'] );
		$rows    = $config['only_on'];
		?>
		<div class="oko-section <?php echo $enabled ? '' : 'is-disabled'; ?>">
			<label class="oko-master-toggle">
				<input type="checkbox" name="only_on_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Enable: Delivery only on a specific day', O_TEXTDOMAIN ); ?>
			</label>
			<p class="oko-help">
				<?php esc_html_e( 'Create one or more exceptions where specific categories/tags can ONLY be delivered on a specific date. Each exception can be enabled/disabled individually.', O_TEXTDOMAIN ); ?>
			</p>
			<div class="oko-section-body">
				<p class="oko-help">
					<?php esc_html_e( 'A day marked as a pre-order is not offered in the normal checkout. The customer presses the pre-order button under Shipping and then sees only the pre-order days.', O_TEXTDOMAIN ); ?>
				</p>
				<div class="oko-row-row" style="margin-bottom:12px;">
					<label><?php esc_html_e( 'Pre-order button', O_TEXTDOMAIN ); ?>:
						<input type="text" name="pre_order_label" value="<?php echo esc_attr( $config['pre_order_label'] ); ?>" placeholder="<?php echo esc_attr( self::pre_order_label( array() ) ); ?>" style="width:200px;" />
					</label>
					<label><?php esc_html_e( 'Back to normal order', O_TEXTDOMAIN ); ?>:
						<input type="text" name="normal_order_label" value="<?php echo esc_attr( $config['normal_order_label'] ); ?>" placeholder="<?php echo esc_attr( self::normal_order_label( array() ) ); ?>" style="width:200px;" />
					</label>
				</div>
				<div style="margin-bottom:12px;">
					<label>
						<input type="checkbox" name="pre_order_notice_enabled" value="1" <?php checked( ! empty( $config['pre_order_notice_enabled'] ) ); ?> />
						<?php esc_html_e( 'Show a note above the buttons while a pre-order is chosen', O_TEXTDOMAIN ); ?>
					</label><br />
					<textarea name="pre_order_notice" rows="2" style="width:100%;max-width:520px;margin-top:6px;" placeholder="<?php esc_attr_e( 'e.g. Fresh vegetables and dairy cannot be pre-ordered.', O_TEXTDOMAIN ); ?>"><?php echo esc_textarea( $config['pre_order_notice'] ); ?></textarea>
				</div>
				<?php $this->render_section_limit_control( $config, 'only_on' ); ?>
				<div id="only_on_rows">
					<?php foreach ( $rows as $i => $row ) : ?>
						<?php $this->render_only_on_row( (string) $i, $row, $categories, $tags ); ?>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button oko-add-btn oko-add-only-on">
					+ <?php esc_html_e( 'Add exception', O_TEXTDOMAIN ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	private function render_only_on_row( $index, array $row, array $categories, array $tags ): void {
		?>
		<div class="oko-row">
			<div class="oko-row-row">
				<label><?php esc_html_e( 'Name', O_TEXTDOMAIN ); ?>:
					<input type="text" name="only_on[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Christmas delivery', O_TEXTDOMAIN ); ?>" style="width:240px;" />
				</label>
				<label><?php esc_html_e( 'Date', O_TEXTDOMAIN ); ?>:
					<input type="date" name="only_on[<?php echo esc_attr( $index ); ?>][date]" value="<?php echo esc_attr( $row['date'] ?? '' ); ?>" />
				</label>
				<label>
					<input type="checkbox" name="only_on[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> />
					<?php esc_html_e( 'Active', O_TEXTDOMAIN ); ?>
				</label>
				<label title="<?php esc_attr_e( 'Offers this date for these products even past the normal number of days, while the normal days stay open. For pre-orders: the soonest days as usual, and this day as well.', O_TEXTDOMAIN ); ?>">
					<input type="checkbox" name="only_on[<?php echo esc_attr( $index ); ?>][extend]" value="1" <?php checked( ! empty( $row['extend'] ) ); ?> />
					<?php esc_html_e( 'Pre-order: offer this date on top of the normal ones', O_TEXTDOMAIN ); ?>
				</label>
				<button type="button" class="button-link oko-remove-row" style="color:#a00;">
					<?php esc_html_e( 'Remove', O_TEXTDOMAIN ); ?>
				</button>
			</div>
			<div class="oko-row-fields">
				<div>
					<label><?php esc_html_e( 'Kategorier', O_TEXTDOMAIN ); ?></label>
					<?php $this->render_term_select( "only_on[$index][categories][]", $categories, (array) ( $row['categories'] ?? array() ) ); ?>
				</div>
				<div>
					<label><?php esc_html_e( 'Tags', O_TEXTDOMAIN ); ?></label>
					<?php $this->render_term_select( "only_on[$index][tags][]", $tags, (array) ( $row['tags'] ?? array() ) ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function render_from_until_section( array $config, array $categories, array $tags ): void {
		$enabled = ! empty( $config['from_until_enabled'] );
		$rows    = $config['from_until'];
		?>
		<div class="oko-section <?php echo $enabled ? '' : 'is-disabled'; ?>">
			<label class="oko-master-toggle">
				<input type="checkbox" name="from_until_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Enable: Delivery from (and optionally until) a specific day', O_TEXTDOMAIN ); ?>
			</label>
			<p class="oko-help">
				<?php esc_html_e( 'Create exceptions where specific categories/tags can only be delivered between two dates. The end date is optional — leave it out if the product should be deliverable indefinitely after the start date.', O_TEXTDOMAIN ); ?>
			</p>
			<div class="oko-section-body">
				<?php $this->render_section_limit_control( $config, 'from_until' ); ?>
				<div id="from_until_rows">
					<?php foreach ( $rows as $i => $row ) : ?>
						<?php $this->render_from_until_row( (string) $i, $row, $categories, $tags ); ?>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button oko-add-btn oko-add-from-until">
					+ <?php esc_html_e( 'Add exception', O_TEXTDOMAIN ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	private function render_from_until_row( $index, array $row, array $categories, array $tags ): void {
		?>
		<div class="oko-row">
			<div class="oko-row-row">
				<label><?php esc_html_e( 'Navn', O_TEXTDOMAIN ); ?>:
					<input type="text" name="from_until[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. Summer products', O_TEXTDOMAIN ); ?>" style="width:240px;" />
				</label>
				<label><?php esc_html_e( 'From', O_TEXTDOMAIN ); ?>:
					<input type="date" name="from_until[<?php echo esc_attr( $index ); ?>][from]" value="<?php echo esc_attr( $row['from'] ?? '' ); ?>" />
				</label>
				<label><?php esc_html_e( 'Until (optional)', O_TEXTDOMAIN ); ?>:
					<input type="date" name="from_until[<?php echo esc_attr( $index ); ?>][until]" value="<?php echo esc_attr( $row['until'] ?? '' ); ?>" />
				</label>
				<label>
					<input type="checkbox" name="from_until[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> />
					<?php esc_html_e( 'Aktiv', O_TEXTDOMAIN ); ?>
				</label>
				<label title="<?php esc_attr_e( 'Shows these products every date up to the until date, even past the normal number of days. For pre-orders: the soonest days as usual, and Christmas as well.', O_TEXTDOMAIN ); ?>">
					<input type="checkbox" name="from_until[<?php echo esc_attr( $index ); ?>][extend]" value="1" <?php checked( ! empty( $row['extend'] ) ); ?> />
					<?php esc_html_e( 'Pre-order: offer these dates on top of the normal ones', O_TEXTDOMAIN ); ?>
				</label>
				<button type="button" class="button-link oko-remove-row" style="color:#a00;">
					<?php esc_html_e( 'Fjern', O_TEXTDOMAIN ); ?>
				</button>
			</div>
			<div class="oko-row-fields">
				<div>
					<label><?php esc_html_e( 'Kategorier', O_TEXTDOMAIN ); ?></label>
					<?php $this->render_term_select( "from_until[$index][categories][]", $categories, (array) ( $row['categories'] ?? array() ) ); ?>
				</div>
				<div>
					<label><?php esc_html_e( 'Tags', O_TEXTDOMAIN ); ?></label>
					<?php $this->render_term_select( "from_until[$index][tags][]", $tags, (array) ( $row['tags'] ?? array() ) ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a multi-select for categories or tags. Defensive against any
	 * value type — selected IDs are normalised to strings for comparison.
	 */
	private function render_term_select( string $name, array $terms, array $selected ): void {
		$selected_str = array_map( 'strval', array_map( 'intval', $selected ) );
		echo '<select name="' . esc_attr( $name ) . '" multiple class="oko-multi">';
		if ( empty( $terms ) ) {
			echo '<option disabled>' . esc_html__( '(none created)', O_TEXTDOMAIN ) . '</option>';
		} else {
			foreach ( $terms as $term ) {
				$id = (string) (int) $term->term_id;
				$is_selected = in_array( $id, $selected_str, true ) ? ' selected' : '';
				echo '<option value="' . esc_attr( $id ) . '"' . $is_selected . '>' . esc_html( $term->name ) . '</option>';
			}
		}
		echo '</select>';
	}

	// =========================================================================
	// Save handler
	// =========================================================================

	public function handle_save(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have access.', O_TEXTDOMAIN ) );
		}
		check_admin_referer( 'okoskabet_save_exceptions' );

		$config = self::default_config();

		// Master toggles.
		$config['weekdays_enabled']   = ! empty( $_POST['weekdays_enabled'] );
		$config['only_on_enabled']    = ! empty( $_POST['only_on_enabled'] );
		$config['from_until_enabled'] = ! empty( $_POST['from_until_enabled'] );

		// Pre-order button wording.
		foreach ( array( 'pre_order_label', 'normal_order_label' ) as $k ) {
			$config[ $k ] = isset( $_POST[ $k ] ) ? sanitize_text_field( (string) wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore
		}
		$config['pre_order_notice_enabled'] = ! empty( $_POST['pre_order_notice_enabled'] );
		$config['pre_order_notice']         = isset( $_POST['pre_order_notice'] ) ? sanitize_textarea_field( (string) wp_unslash( $_POST['pre_order_notice'] ) ) : ''; // phpcs:ignore

		// Display settings.
		$posted_display_mode = isset( $_POST['display_mode'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['display_mode'] ) ) : ''; // phpcs:ignore
		$config['display_mode']  = ( $posted_display_mode === 'count' ) ? 'count' : 'window';
		$config['display_value'] = isset( $_POST['display_value'] ) ? max( 0, (int) $_POST['display_value'] ) : 0;
		foreach ( array_keys( self::LIMIT_SECTIONS ) as $section ) {
			$posted_limit_mode = isset( $_POST[ $section . '_limit_mode' ] ) ? sanitize_text_field( (string) wp_unslash( $_POST[ $section . '_limit_mode' ] ) ) : ''; // phpcs:ignore
			$config[ $section . '_limit_mode' ]  = ( $posted_limit_mode === 'special' ) ? 'special' : 'global';
			$config[ $section . '_limit_value' ] = isset( $_POST[ $section . '_limit_value' ] ) ? max( 0, (int) $_POST[ $section . '_limit_value' ] ) : 0;
		}

		// Weekdays.
		$posted_weekdays = isset( $_POST['weekdays'] ) && is_array( $_POST['weekdays'] ) ? wp_unslash( $_POST['weekdays'] ) : array(); // phpcs:ignore
		foreach ( $config['weekdays'] as $w => $entry ) {
			$src = $posted_weekdays[ $w ] ?? array();
			$config['weekdays'][ $w ] = array(
				'enabled'    => ! empty( $src['enabled'] ),
				'categories' => $this->sanitize_id_list( $src['categories'] ?? array() ),
				'tags'       => $this->sanitize_id_list( $src['tags'] ?? array() ),
			);
		}

		// only_on rows.
		$config['only_on'] = array();
		$posted_only_on = isset( $_POST['only_on'] ) && is_array( $_POST['only_on'] ) ? wp_unslash( $_POST['only_on'] ) : array(); // phpcs:ignore
		foreach ( $posted_only_on as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$date  = sanitize_text_field( (string) ( $row['date']  ?? '' ) );
			// Skip totally empty rows.
			if ( $label === '' && $date === '' && empty( $row['categories'] ) && empty( $row['tags'] ) ) {
				continue;
			}
			$config['only_on'][] = array(
				'label'      => $label,
				'date'       => $date,
				'enabled'    => ! empty( $row['enabled'] ),
				'extend'     => ! empty( $row['extend'] ),
				'categories' => $this->sanitize_id_list( $row['categories'] ?? array() ),
				'tags'       => $this->sanitize_id_list( $row['tags'] ?? array() ),
			);
		}

		// from_until rows.
		$config['from_until'] = array();
		$posted_from_until = isset( $_POST['from_until'] ) && is_array( $_POST['from_until'] ) ? wp_unslash( $_POST['from_until'] ) : array(); // phpcs:ignore
		foreach ( $posted_from_until as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$from  = sanitize_text_field( (string) ( $row['from']  ?? '' ) );
			$until = sanitize_text_field( (string) ( $row['until'] ?? '' ) );
			if ( $label === '' && $from === '' && $until === '' && empty( $row['categories'] ) && empty( $row['tags'] ) ) {
				continue;
			}
			$config['from_until'][] = array(
				'label'      => $label,
				'from'       => $from,
				'until'      => $until,
				'enabled'    => ! empty( $row['enabled'] ),
				'extend'     => ! empty( $row['extend'] ),
				'categories' => $this->sanitize_id_list( $row['categories'] ?? array() ),
				'tags'       => $this->sanitize_id_list( $row['tags'] ?? array() ),
			);
		}

		// Per-rule cutoff.
		$config['cutoff_enabled'] = ! empty( $_POST['cutoff_enabled'] );
		$config['cutoff_rules']   = array();
		$posted_cutoff = isset( $_POST['cutoff_rules'] ) && is_array( $_POST['cutoff_rules'] ) ? wp_unslash( $_POST['cutoff_rules'] ) : array(); // phpcs:ignore
		foreach ( $posted_cutoff as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cats = $this->sanitize_id_list( $row['categories'] ?? array() );
			$tags = $this->sanitize_id_list( $row['tags'] ?? array() );
			// A rule with no target does nothing — drop it.
			if ( empty( $cats ) && empty( $tags ) ) {
				continue;
			}
			$posted_time = sanitize_text_field( (string) ( $row['time'] ?? '' ) );
			$config['cutoff_rules'][] = array(
				'label'      => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
				'days'       => max( 0, (int) ( $row['days'] ?? 1 ) ),
				'time'       => preg_match( '/^\d{1,2}:\d{2}$/', $posted_time ) ? $posted_time : '09:00',
				'enabled'    => ! empty( $row['enabled'] ),
				'categories' => $cats,
				'tags'       => $tags,
			);
		}

		update_option( self::OPTION_KEY, $config );
		self::purge_rules_cache();

		// Send the user back to the main plugin settings page with a flag
		// that the section uses to display a "Saved" notice, plus an anchor
		// so the browser scrolls to where they were.
		wp_safe_redirect( add_query_arg(
			array(
				'page'           => O_TEXTDOMAIN,
				'oko_exc_saved'  => '1',
			),
			admin_url( 'admin.php' )
		) . '#okoskabet-delivery-exceptions' );
		exit;
	}

	private function sanitize_id_list( $list ): array {
		if ( ! is_array( $list ) ) {
			return array();
		}
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
	// Filtering pipeline (consumed by OkoRest's filter)
	// =========================================================================

	/**
	 * Filter the list of delivery dates returned from Økoskabet's API.
	 *
	 * Two stages:
	 *
	 *   1. **Restriction:** drop dates that are forbidden by an exception
	 *      matching one of the cart's products (e.g. "frost only on Mondays").
	 *
	 *   2. **Extension:** add back dates that an `only_on` or `from_until`
	 *      exception explicitly mentions, so a far-future date (such as
	 *      24 December for a julemiddag product) shows up even if it falls
	 *      outside the standard display window the API returned. `weekdays`
	 *      exceptions never extend the window — they only restrict.
	 *
	 * @param string[] $dates       Dates from Økoskabet's API.
	 * @param int[]    $product_ids Product IDs in the customer's cart, sent
	 *                              by the frontend as a query parameter.
	 *                              Empty array means we don't know what's in
	 *                              the cart — in that case we skip filtering
	 *                              and return the input untouched.
	 * @return string[]
	 */
	public function filter_dates_for_cart( array $dates, $product_ids = array(), $pre_order = false ): array {
		$product_ids = is_array( $product_ids ) ? array_map( 'intval', $product_ids ) : array();
		$product_ids = array_values( array_filter( $product_ids, function ( $id ) { return $id > 0; } ) );

		if ( empty( $product_ids ) ) {
			return self::strip_past_dates( $dates );
		}

		$config           = self::get_config();
		$applicable_rules = $this->collect_applicable_rules( $product_ids, $config );

		// Stage 1: restrict — drop any date that fails a rule.
		$result = empty( $applicable_rules ) ? $dates : array_values( array_filter( $dates, function ( string $date ) use ( $applicable_rules ): bool {
			foreach ( $applicable_rules as $rule ) {
				if ( ! self::date_passes_rule( $date, $rule ) ) {
					return false;
				}
			}
			return true;
		} ) );

		// Økoskabet's API is the single source of truth for which dates are
		// possible — the plugin only ever filters that list down, it never
		// invents dates. A wide-enough query window (see effective_query_window)
		// guarantees the API already returned every date our rules might keep,
		// including the first matching weekday on/after a "from" date.

		// No past dates. The API excludes the past via the cutoff, so this is a
		// belt-and-suspenders guard against a "from" boundary set in the past.
		// Today itself is kept — a zero cutoff allows same-day delivery.
		$result = self::strip_past_dates( $result );
		sort( $result );

		// Per-rule cutoff: if the cart holds a product that a rule closes earlier
		// than normal, drop the soonest dates it can no longer make.
		$result = self::apply_cutoff( $result, $product_ids, $config );

		// A pre-order shows its own days and nothing else; a normal order shows
		// the normal days and nothing else. Mixing them put two months of
		// dates in front of a customer who only wanted next week.
		if ( $pre_order ) {
			$ranges = self::pre_order_ranges( $applicable_rules );
			$result = array_values( array_filter( $result, function ( string $date ) use ( $ranges ): bool {
				return self::date_in_ranges( $date, $ranges );
			} ) );
		} else {
			// Apply the configured display limit (a number of delivery days, or
			// a calendar horizon) now that the cart's available dates are known.
			$result = self::apply_display_limit( $result, $applicable_rules, $config );
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// De-duplicate the log line per unique product-set per request.
			// The filter fires many times during a single Svelte checkout
			// render; logging every call buries useful information.
			static $logged_cart_keys = array();
			$cart_key = implode( ',', $product_ids );
			if ( ! isset( $logged_cart_keys[ $cart_key ] ) ) {
				$logged_cart_keys[ $cart_key ] = true;
				error_log( sprintf(
					'Økoskabet exceptions: %d input dates → %d shown. Rules: %s',
					count( $dates ),
					count( $result ),
					wp_json_encode( $applicable_rules )
				) );
			}
		}

		return $result;
	}

	/**
	 * Drop the soonest dates the cart can no longer be ordered for. Each enabled
	 * cutoff rule closes ordering for its categories/tags `days` before delivery
	 * at `time`; a rule constrains this cart when any cart product matches it. A
	 * date D is kept only if, for every constraining rule, now is on/before
	 * (D − days) at that rule's time. When several rules apply the earliest
	 * deadline wins — a date fails as soon as one rule's cutoff has passed.
	 *
	 * Runs only when a rule actually matches the cart, so unmatched carts are
	 * never re-filtered (the API already applied the normal cutoff) and can't
	 * drift from the back office's own cutoff logic.
	 *
	 * @param string[] $dates       Sorted Y-m-d delivery dates.
	 * @param int[]    $product_ids Cart product IDs.
	 * @param array    $config      Exceptions config.
	 * @return string[]
	 */
	private static function apply_cutoff( array $dates, array $product_ids, array $config ): array {
		if ( empty( $config['cutoff_enabled'] ) || empty( $product_ids ) || empty( $dates ) ) {
			return $dates;
		}

		$rules = array();
		foreach ( (array) ( $config['cutoff_rules'] ?? array() ) as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( empty( $rule['categories'] ) && empty( $rule['tags'] ) ) {
				continue;
			}
			$rules[] = $rule;
		}
		if ( empty( $rules ) ) {
			return $dates;
		}

		// Keep only the rules that actually match a product in this cart.
		$applicable = array();
		foreach ( $product_ids as $pid ) {
			$terms   = self::product_terms( (int) $pid );
			$cat_ids = $terms['cats'];
			$tag_ids = $terms['tags'];
			foreach ( $rules as $i => $rule ) {
				if ( ! isset( $applicable[ $i ] ) && self::rule_matches_terms( $rule, $cat_ids, $tag_ids ) ) {
					$applicable[ $i ] = $rule;
				}
			}
		}
		if ( empty( $applicable ) ) {
			return $dates;
		}

		try {
			$now = self::wp_datetime( 'now' );
		} catch ( \Exception $e ) {
			return $dates;
		}

		$deadlines = array();
		foreach ( $applicable as $rule ) {
			$deadlines[] = array(
				'days' => max( 0, (int) ( $rule['days'] ?? 0 ) ),
				'time' => preg_match( '/^\d{1,2}:\d{2}$/', (string) ( $rule['time'] ?? '' ) ) ? $rule['time'] : '09:00',
			);
		}

		return array_values( array_filter( $dates, function ( $date ) use ( $now, $deadlines ): bool {
			if ( ! is_string( $date ) || $date === '' ) {
				return false;
			}
			foreach ( $deadlines as [ 'days' => $days, 'time' => $time ] ) {
				try {
					$cutoff = self::wp_datetime( $date . ' ' . $time );
				} catch ( \Exception $e ) {
					continue; // unparseable — this rule can't constrain the date.
				}
				$cutoff->modify( sprintf( '-%d days', $days ) );
				if ( $now > $cutoff ) {
					return false; // this rule's deadline has passed.
				}
			}
			return true;
		} ) );
	}

	/**
	 * Limit which of the cart's available dates are shown, per the merchant's
	 * display configuration. Two modes:
	 *
	 *   - 'count':  show the first N dates (soonest first).
	 *   - 'window': show every date within N calendar days from today.
	 *
	 * When no display value is configured the dates pass through untrimmed, so
	 * existing installs keep their current behaviour until a merchant opts in.
	 * A per-section "special" override (weekdays / only_on / from_until) can
	 * tighten the value; when several apply to one cart the MOST RESTRICTIVE
	 * (smallest) value wins.
	 *
	 * @param string[] $dates           Sorted, past-stripped delivery dates.
	 * @param array    $applicable_rules Rules collect_applicable_rules() returned for this cart.
	 * @param array    $config          Exceptions config.
	 * @return string[]
	 */
	private static function apply_display_limit( array $dates, array $applicable_rules, array $config ): array {
		$limit = self::resolve_display_limit( $applicable_rules, $config );
		if ( $limit === null || $limit['value'] <= 0 ) {
			return $dates; // not configured → no display trimming (legacy behaviour).
		}

		if ( $limit['mode'] === 'count' ) {
			return array_slice( $dates, 0, $limit['value'] );
		}

		// 'window': keep dates on/before today + value days. Y-m-d sorts
		// lexically, so compare strings rather than parsing every date.
		$horizon = self::wp_datetime( 'today' );
		$horizon->modify( sprintf( '+%d days', (int) $limit['value'] ) );
		$horizon_ymd = $horizon->format( 'Y-m-d' );
		return array_values( array_filter( $dates, function ( $date ) use ( $horizon_ymd ): bool {
			return is_string( $date ) && $date !== '' && $date <= $horizon_ymd;
		} ) );
	}

	/**
	 * Resolve the effective display limit for a cart: the global mode plus the
	 * smallest applicable value across the global setting and any per-section
	 * "special" overrides whose section actually applies to this cart.
	 *
	 * Returns null when nothing is configured (so callers keep legacy behaviour).
	 *
	 * @param array $applicable_rules
	 * @param array $config
	 * @return array{mode:string,value:int}|null
	 */
	private static function resolve_display_limit( array $applicable_rules, array $config ): ?array {
		// display_mode is normalised by get_config()/merge_with_defaults(), so
		// readers can trust it. The display limit only kicks in when a merchant
		// has explicitly set a value — we deliberately do NOT fall back to the
		// legacy per-merchant `maximum_days_in_future` here (that governs the API
		// query window, not display trimming; falling back would hide exception
		// dates that existing installs currently show).
		$mode  = $config['display_mode'] ?? 'window';
		$value = (int) ( $config['display_value'] ?? 0 );

		// Which exception sections are in play for this cart?
		$present = array();
		foreach ( $applicable_rules as $rule ) {
			$section = array_search( $rule['type'] ?? '', self::LIMIT_SECTIONS, true );
			if ( $section !== false ) {
				$present[ $section ] = true;
			}
		}

		$candidates = array();
		if ( $value > 0 ) {
			$candidates[] = $value;
		}
		foreach ( array_keys( self::LIMIT_SECTIONS ) as $section ) {
			if ( empty( $present[ $section ] ) ) {
				continue;
			}
			if ( ( $config[ $section . '_limit_mode' ] ?? 'global' ) !== 'special' ) {
				continue;
			}
			$special = (int) ( $config[ $section . '_limit_value' ] ?? 0 );
			if ( $special > 0 ) {
				$candidates[] = $special;
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}
		return array( 'mode' => $mode, 'value' => min( $candidates ) );
	}

	/**
	 * Build a human-readable explanation of which exception rules apply to
	 * which cart products. Used by the REST endpoints to surface a clearer
	 * "no available dates" message when the filter eliminates all dates.
	 *
	 * @param int[] $product_ids
	 * @return array{
	 *   has_exceptions: bool,
	 *   product_rules: array<int, array{product_id:int, product_name:string, rules:array<int,string>}>,
	 *   summary: string
	 * }
	 */
	public static function explanation_for_cart( array $product_ids ): array {
		$product_ids = array_values( array_filter( array_map( 'intval', $product_ids ), function ( $i ) { return $i > 0; } ) );
		$out = array(
			'has_exceptions' => false,
			'product_rules'  => array(),
			'summary'        => '',
		);
		if ( empty( $product_ids ) ) {
			return $out;
		}

		// If the merchant has opted in to split-checkout, the new banner at
		// the top of the checkout page will guide the customer through
		// multiple orders — we don't want to ALSO show this "remove an item"
		// explanation in the dates dropdown area, which would conflict with
		// the new flow's messaging. The split-banner is the authoritative UI
		// in that case.
		if ( class_exists( '\\okoskabet_woocommerce_plugin\\Integrations\\Split_Checkout' )
			&& \okoskabet_woocommerce_plugin\Integrations\Split_Checkout::is_feature_enabled() ) {
			return $out;
		}

		$config = self::get_config();

		// Pre-collect cat/tag IDs and names per product, since we need to
		// match each product individually to identify which one triggers
		// each rule.
		$product_meta = array();
		foreach ( $product_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post ) {
				continue;
			}
			$terms   = self::product_terms( (int) $pid );
			$cat_ids = $terms['cats'];
			$tag_ids = $terms['tags'];
			$product_meta[ $pid ] = array(
				'name'    => $post->post_title !== '' ? $post->post_title : sprintf( __( 'Item #%d', O_TEXTDOMAIN ), $pid ),
				'cat_ids' => $cat_ids,
				'tag_ids' => $tag_ids,
				'rules'   => array(), // populated below
			);
		}

		// Helper: which rule families apply to this single product?
		$inspect_product = function ( $cat_ids, $tag_ids ) use ( $config ) {
			$descriptions = array();

			// Weekdays
			if ( ! empty( $config['weekdays_enabled'] ) ) {
				$matching_weekdays = array();
				foreach ( $config['weekdays'] as $w => $entry ) {
					if ( empty( $entry['enabled'] ) ) { continue; }
					if ( self::rule_matches_terms( $entry, $cat_ids, $tag_ids ) ) {
						$matching_weekdays[] = (int) $w;
					}
				}
				if ( ! empty( $matching_weekdays ) ) {
					$descriptions[] = sprintf(
						__( 'can only be delivered on %s', O_TEXTDOMAIN ),
						self::format_weekday_list( $matching_weekdays )
					);
				}
			}

			// only_on
			if ( ! empty( $config['only_on_enabled'] ) ) {
				foreach ( $config['only_on'] as $row ) {
					if ( empty( $row['enabled'] ) || empty( $row['date'] ) || ! empty( $row['extend'] ) ) { continue; }
					if ( self::rule_matches_terms( $row, $cat_ids, $tag_ids ) ) {
						$descriptions[] = sprintf(
							__( 'can only be delivered on %s', O_TEXTDOMAIN ),
							self::format_date_human( $row['date'] )
						);
					}
				}
			}

			// from_until
			if ( ! empty( $config['from_until_enabled'] ) ) {
				foreach ( $config['from_until'] as $row ) {
					if ( empty( $row['enabled'] ) || empty( $row['from'] ) || ! empty( $row['extend'] ) ) { continue; }
					if ( self::rule_matches_terms( $row, $cat_ids, $tag_ids ) ) {
						if ( ! empty( $row['until'] ) ) {
							$descriptions[] = sprintf(
								__( 'can only be delivered between %1$s and %2$s', O_TEXTDOMAIN ),
								self::format_date_human( $row['from'] ),
								self::format_date_human( $row['until'] )
							);
						} else {
							$descriptions[] = sprintf(
								__( 'can be delivered no earlier than %s', O_TEXTDOMAIN ),
								self::format_date_human( $row['from'] )
							);
						}
					}
				}
			}

			return $descriptions;
		};

		$any = false;
		foreach ( $product_meta as $pid => &$meta ) {
			$meta['rules'] = $inspect_product( $meta['cat_ids'], $meta['tag_ids'] );
			if ( ! empty( $meta['rules'] ) ) {
				$any = true;
			}
		}
		unset( $meta );

		$out['has_exceptions'] = $any;
		foreach ( $product_meta as $pid => $meta ) {
			$out['product_rules'][] = array(
				'product_id'   => $pid,
				'product_name' => $meta['name'],
				'rules'        => $meta['rules'],
			);
		}

		// One-line summary used by the frontend headline.
		if ( $any ) {
			$out['summary'] = __( 'The items in your cart have conflicting delivery rules, so no single date works for all of them. See below.', O_TEXTDOMAIN );
		}

		return $out;
	}

	/**
	 * The category and tag ids a product carries, as lookup maps.
	 *
	 * @param int $product_id
	 * @return array{cats:array<int,bool>,tags:array<int,bool>}
	 */
	private static function product_terms( int $product_id ): array {
		if ( isset( self::$product_terms_cache[ $product_id ] ) ) {
			return self::$product_terms_cache[ $product_id ];
		}
		$cats = array();
		$tags = array();
		foreach ( wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) ) as $tid ) {
			$cats[ (int) $tid ] = true;
		}
		foreach ( wp_get_post_terms( $product_id, 'product_tag', array( 'fields' => 'ids' ) ) as $tid ) {
			$tags[ (int) $tid ] = true;
		}
		self::$product_terms_cache[ $product_id ] = array( 'cats' => $cats, 'tags' => $tags );
		return self::$product_terms_cache[ $product_id ];
	}

	/** Does a single rule's category/tag list overlap with one product? */
	private static function rule_matches_terms( array $rule, array $cat_ids, array $tag_ids ): bool {
		$rule_cats = (array) ( $rule['categories'] ?? array() );
		$rule_tags = (array) ( $rule['tags'] ?? array() );
		if ( empty( $rule_cats ) && empty( $rule_tags ) ) {
			return false;
		}
		foreach ( $rule_cats as $cid ) {
			if ( ! empty( $cat_ids[ (int) $cid ] ) ) { return true; }
		}
		foreach ( $rule_tags as $tid ) {
			if ( ! empty( $tag_ids[ (int) $tid ] ) ) { return true; }
		}
		return false;
	}

	/** Format weekday numbers (0=Sun…6=Sat) as a Danish phrase. */
	private static function format_weekday_list( array $weekdays ): string {
		$labels = array(
			0 => __( 'Sundays', O_TEXTDOMAIN ),
			1 => __( 'Mondays', O_TEXTDOMAIN ),
			2 => __( 'Tuesdays', O_TEXTDOMAIN ),
			3 => __( 'Wednesdays', O_TEXTDOMAIN ),
			4 => __( 'Thursdays', O_TEXTDOMAIN ),
			5 => __( 'Fridays', O_TEXTDOMAIN ),
			6 => __( 'Saturdays', O_TEXTDOMAIN ),
		);
		// Sort so Mondays come first (Danish week-order).
		usort( $weekdays, function ( $a, $b ) {
			$order = array( 1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 0 => 7 );
			return ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 );
		} );
		$names = array();
		foreach ( $weekdays as $w ) {
			$names[] = $labels[ (int) $w ] ?? '';
		}
		$names = array_filter( $names );
		if ( count( $names ) === 1 ) {
			return $names[0];
		}
		$last = array_pop( $names );
		return implode( ', ', $names ) . ' ' . __( 'or', O_TEXTDOMAIN ) . ' ' . $last;
	}

	/**
	 * Build a DateTime in the WordPress site's configured timezone.
	 *
	 * Without this, all DateTime constructions fall back to the server's
	 * default timezone (typically UTC on managed hosts), which can produce
	 * off-by-one-day errors in weekday and range comparisons when the store
	 * operates in a different timezone (Europe/Copenhagen for our case).
	 *
	 * @param string $when 'now', 'today', 'YYYY-MM-DD' etc. — anything
	 *                     DateTime accepts.
	 * @return \DateTime
	 * @throws \Exception if $when is unparseable
	 */
	private static function wp_datetime( string $when ): \DateTime {
		// The site timezone can't change within a request, so resolve the
		// DateTimeZone once instead of on every call (this runs many times per
		// checkout render).
		static $tz = null;
		if ( $tz === null ) {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		}
		return new \DateTime( $when, $tz );
	}

	/** Today's date as a Y-m-d string in the site's timezone. */
	private static function today_ymd(): string {
		return self::wp_datetime( 'today' )->format( 'Y-m-d' );
	}

	/**
	 * Drop any delivery date that falls before today (in the site's
	 * timezone). "Today" is preserved — a zero cutoff legitimately allows
	 * same-day delivery. The API returns zero-padded Y-m-d, which sorts
	 * lexically, so a plain string comparison is correct and avoids parsing
	 * every date into a DateTime on this hot path.
	 *
	 * @param string[] $dates Y-m-d delivery dates
	 * @return string[]
	 */
	private static function strip_past_dates( array $dates ): array {
		$today = self::today_ymd();
		return array_values( array_filter( $dates, function ( $date ) use ( $today ): bool {
			return is_string( $date ) && $date !== '' && $date >= $today;
		} ) );
	}

	/** Format a Y-m-d date as a Danish "den d. F YYYY" string. */
	private static function format_date_human( string $date ): string {
		try {
			$dt = self::wp_datetime( $date );
		} catch ( \Exception $e ) {
			return $date;
		}
		$months = array( 1=>'januar',2=>'februar',3=>'marts',4=>'april',5=>'maj',6=>'juni',7=>'juli',8=>'august',9=>'september',10=>'oktober',11=>'november',12=>'december' );
		return sprintf( 'den %d. %s %d', (int) $dt->format( 'j' ), $months[ (int) $dt->format( 'n' ) ], (int) $dt->format( 'Y' ) );
	}

	/**
	 * Return the specific date(s) a rule references, used only to widen the
	 * API query window so those dates are actually fetched (the API stays the
	 * source of truth — we never inject these dates into the result ourselves).
	 *
	 * - only_on rules contribute their single date.
	 * - from_until rules contribute their 'from' boundary, and their 'until'
	 *   too when marked as a pre-order — otherwise a rule starting in the past
	 *   widens nothing, and the days it is meant to open are never fetched.
	 * - weekday rules contribute nothing (they restrict, never extend).
	 */
	private static function extension_candidates_for_rule( array $rule ): array {
		switch ( $rule['type'] ?? '' ) {
			case 'only_on':
				return ! empty( $rule['date'] ) ? array( $rule['date'] ) : array();
			case 'from_until':
				$candidates = ! empty( $rule['from'] ) ? array( $rule['from'] ) : array();
				if ( ! empty( $rule['extend'] ) && ! empty( $rule['until'] ) ) {
					$candidates[] = $rule['until'];
				}
				return $candidates;
			default:
				return array();
		}
	}

	/**
	 * The days pre-order rules open for this cart, as [from, until] pairs of
	 * Y-m-d strings. A single-day rule opens that day. A from/until rule
	 * without a from date opens everything up to its until date; one without
	 * an until date opens nothing, since there would be no end to it.
	 *
	 * @param array $applicable_rules As collect_applicable_rules() returns them.
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function pre_order_ranges( array $applicable_rules ): array {
		$ranges = array();

		foreach ( $applicable_rules as $rule ) {
			if ( empty( $rule['extend'] ) ) {
				continue;
			}
			if ( ( $rule['type'] ?? '' ) === 'only_on' && ! empty( $rule['date'] ) ) {
				$ranges[] = array( (string) $rule['date'], (string) $rule['date'] );
			} elseif ( ( $rule['type'] ?? '' ) === 'from_until' && ! empty( $rule['until'] ) ) {
				$ranges[] = array( (string) ( $rule['from'] ?? '' ), (string) $rule['until'] );
			}
		}

		return $ranges;
	}

	/**
	 * Whether a Y-m-d date falls inside any of the given [from, until] pairs.
	 *
	 * @param array<int,array{0:string,1:string}> $ranges
	 */
	private static function date_in_ranges( string $date, array $ranges ): bool {
		foreach ( $ranges as [ $from, $until ] ) {
			if ( $date !== '' && $date >= $from && $date <= $until ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether anything in this cart can be pre-ordered — which is when the
	 * checkout offers the pre-order button at all.
	 *
	 * @param int[] $product_ids
	 */
	public static function cart_has_pre_order_days( array $product_ids ): bool {
		$product_ids = array_values( array_filter( array_map( 'intval', $product_ids ) ) );
		if ( empty( $product_ids ) ) {
			return false;
		}
		$instance = new self();

		return ! empty( self::pre_order_ranges( $instance->collect_applicable_rules( $product_ids, self::get_config() ) ) );
	}

	/** The pre-order button's wording, as the shop set it or built in. */
	public static function pre_order_label( ?array $config = null ): string {
		$config = $config ?? self::get_config();
		return ( $config['pre_order_label'] ?? '' ) !== '' ? (string) $config['pre_order_label'] : __( 'Pre-order', O_TEXTDOMAIN );
	}

	/** The note shown while a pre-order is chosen, or '' when it is off. */
	public static function pre_order_notice(): string {
		$config = self::get_config();
		return ! empty( $config['pre_order_notice_enabled'] ) ? trim( (string) $config['pre_order_notice'] ) : '';
	}

	/** The wording of the button back to a normal order. */
	public static function normal_order_label( ?array $config = null ): string {
		$config = $config ?? self::get_config();
		return ( $config['normal_order_label'] ?? '' ) !== '' ? (string) $config['normal_order_label'] : __( 'Normal order', O_TEXTDOMAIN );
	}

	/**
	 * Tell the caller how many days into the future Økoskabet's API needs to
	 * be queried for, so the customer sees every date the cart's rules might
	 * keep — without over-fetching. The API is the source of truth; we only
	 * need to fetch a wide-enough slice of it.
	 *
	 * The window is the largest of:
	 *   - the configured display window (or the legacy `$default_days`); in
	 *     "count" mode we widen to ~a week per requested date so weekday-only
	 *     products can still yield that many dates;
	 *   - the distance to the furthest applicable only_on / from_until date,
	 *     plus a week of head-room past a "from" boundary so the first
	 *     matching weekday on/after it is included (fixes weekday + from-date
	 *     combinations that previously returned nothing).
	 *
	 * Capped at 365 days so a mistyped far-future date can't trigger an
	 * unbounded query.
	 */
	public static function effective_query_window( int $default_days, array $product_ids, bool $pre_order = false ): int {
		$config        = self::get_config();
		$display_value = (int) ( $config['display_value'] ?? 0 );
		$mode          = $config['display_mode'] ?? 'window'; // normalised by get_config()
		$base          = $display_value > 0 ? $display_value : max( 1, $default_days );
		// In count mode, widen to ~a week per requested date (plus head-room) so
		// even a once-a-week product can still yield that many dates.
		$window        = ( $mode === 'count' ) ? ( $base * 7 + self::QUERY_WINDOW_BUFFER_DAYS ) : $base;

		$product_ids = array_values( array_filter( array_map( 'intval', $product_ids ), function ( $i ) { return $i > 0; } ) );
		if ( empty( $product_ids ) ) {
			return min( max( $window, $default_days ), 365 );
		}

		$instance = new self();
		$rules    = $instance->collect_applicable_rules( $product_ids, $config );

		$today = self::wp_datetime( 'today' );

		// A week of head-room past any rule-referenced date. For from_until it
		// guarantees the first matching weekday after a "from" date is fetched;
		// for only_on it absorbs the lead-time / cutoff offset that shifts the
		// API's window, so an exact date sitting on the window boundary isn't
		// cut off by a day (the API counts its window from the first deliverable
		// day, not from today).
		foreach ( $rules as $rule ) {
			// A normal order never shows pre-order days, so it needn't fetch them.
			if ( ! empty( $rule['extend'] ) && ! $pre_order ) {
				continue;
			}
			$candidates = self::extension_candidates_for_rule( $rule );
			foreach ( $candidates as $date ) {
				try {
					$dt = self::wp_datetime( $date );
				} catch ( \Exception $e ) {
					continue;
				}
				$diff = (int) $today->diff( $dt )->format( '%r%a' ) + self::QUERY_WINDOW_BUFFER_DAYS;
				if ( $diff > $window ) {
					$window = $diff;
				}
			}
		}

		return min( $window, 365 );
	}

	/**
	 * Per-request cache for collect_applicable_rules. Keyed by sorted product
	 * IDs joined with commas. The same cart will hit the filter multiple
	 * times during a single checkout render (Svelte triggers many re-renders);
	 * caching avoids re-querying terms for every call.
	 *
	 * @var array<string, array>
	 */
	private static $rules_cache = array();

	/**
	 * Per-product category/tag ids, memoised for the request.
	 *
	 * The date filter runs once per shed and once per pickup location, and
	 * three separate places need the same term lists, so without this a
	 * ten-shed shop with a five-item cart pays a hundred term queries where
	 * ten would do.
	 *
	 * @var array<int,array{cats:array<int,bool>,tags:array<int,bool>}>
	 */
	private static $product_terms_cache = array();

	/** @var array<string,mixed>|null */
	private static $config_cache = null;

	/**
	 * Clear the per-request applicable-rules cache. Primarily for tests, where
	 * the static cache would otherwise persist across cases in one process.
	 */
	public static function purge_rules_cache(): void {
		self::$rules_cache         = array();
		self::$product_terms_cache = array();
		self::$config_cache        = null;
	}

	/**
	 * Walk through the configuration, returning every rule that applies to
	 * at least one product in $product_ids.
	 *
	 * @param int[] $product_ids
	 * @param array $config       Config from get_config()
	 * @return array<int, array>  Each entry: ['type'=>..., ...rule fields...]
	 */
	public function collect_applicable_rules( array $product_ids, array $config ): array {
		// Normalise + cache key based on sorted product IDs.
		$normalised = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ), function ( $id ) { return $id > 0; } ) ) );
		sort( $normalised );
		$cache_key = implode( ',', $normalised );

		if ( isset( self::$rules_cache[ $cache_key ] ) ) {
			return self::$rules_cache[ $cache_key ];
		}

		// Pre-collect category and tag IDs per product (and a cart-wide union)
		// so we don't repeat the term query for each rule. The per-product map
		// is what lets us compute weekday availability product-by-product
		// before intersecting across the cart.
		$product_terms = array();
		$cart_cat_ids  = array();
		$cart_tag_ids  = array();
		foreach ( $normalised as $pid ) {
			$terms = self::product_terms( (int) $pid );
			foreach ( array_keys( $terms['cats'] ) as $tid ) {
				$cart_cat_ids[ (int) $tid ] = true;
			}
			foreach ( array_keys( $terms['tags'] ) as $tid ) {
				$cart_tag_ids[ (int) $tid ] = true;
			}
			$product_terms[ $pid ] = $terms;
		}

		$applicable = array();

		// Weekdays family — computed PER PRODUCT, then intersected across the
		// cart. A product's allowed weekdays = the union of every enabled
		// weekday rule that matches it (it can ship on any of those days). The
		// cart can only ship on a day where EVERY weekday-restricted product
		// can ship — i.e. the intersection of those per-product sets. Products
		// that match no weekday rule are unrestricted and don't narrow it.
		//
		// Example: product A "Wed or Fri", product B "Fri only" → {Fri}. If the
		// intersection is empty (e.g. A "Wed only" + B "Fri only") we emit a
		// weekday_set with an empty list, which rejects every date — the
		// checkout then surfaces the split-order / contact-shop flow.
		if ( ! empty( $config['weekdays_enabled'] ) ) {
			$cart_weekdays = null; // null = no weekday-restricted product seen yet.
			foreach ( $normalised as $pid ) {
				$pcats   = $product_terms[ $pid ]['cats'];
				$ptags   = $product_terms[ $pid ]['tags'];
				$allowed = array();
				$matched = false;
				foreach ( $config['weekdays'] as $weekday => $entry ) {
					if ( empty( $entry['enabled'] ) ) {
						continue;
					}
					if ( ! $this->terms_intersect( $entry, $pcats, $ptags ) ) {
						continue;
					}
					$matched   = true;
					$allowed[] = (int) $weekday;
				}
				if ( $matched ) {
					$allowed       = array_values( array_unique( $allowed ) );
					$cart_weekdays = ( $cart_weekdays === null )
						? $allowed
						: array_values( array_intersect( $cart_weekdays, $allowed ) );
				}
			}
			if ( $cart_weekdays !== null ) {
				$applicable[] = array(
					'type'     => 'weekday_set',
					'weekdays' => array_values( $cart_weekdays ),
				);
			}
		}

		// Only-on family. A rule applies if any cart product matches it; the
		// resulting date filter is AND'd with every other rule.
		if ( ! empty( $config['only_on_enabled'] ) ) {
			foreach ( $config['only_on'] as $row ) {
				if ( empty( $row['enabled'] ) || empty( $row['date'] ) ) {
					continue;
				}
				if ( ! $this->terms_intersect( $row, $cart_cat_ids, $cart_tag_ids ) ) {
					continue;
				}
				$applicable[] = array(
					'type'   => 'only_on',
					'date'   => $row['date'],
					'extend' => ! empty( $row['extend'] ),
				);
			}
		}

		// From-until family.
		if ( ! empty( $config['from_until_enabled'] ) ) {
			foreach ( $config['from_until'] as $row ) {
				if ( empty( $row['enabled'] ) || empty( $row['from'] ) ) {
					continue;
				}
				if ( ! $this->terms_intersect( $row, $cart_cat_ids, $cart_tag_ids ) ) {
					continue;
				}
				$applicable[] = array(
					'type'   => 'from_until',
					'from'   => $row['from'],
					'until'  => $row['until'] ?? '',
					'extend' => ! empty( $row['extend'] ),
				);
			}
		}

		self::$rules_cache[ $cache_key ] = $applicable;
		return $applicable;
	}

	/**
	 * Does the rule's category or tag list overlap with the cart's
	 * categories and tags? An empty rule (no cats AND no tags) is treated
	 * as not matching anything.
	 */
	private function terms_intersect( array $rule, array $cart_cat_ids, array $cart_tag_ids ): bool {
		$rule_cats = (array) ( $rule['categories'] ?? array() );
		$rule_tags = (array) ( $rule['tags'] ?? array() );
		if ( empty( $rule_cats ) && empty( $rule_tags ) ) {
			return false;
		}
		foreach ( $rule_cats as $cid ) {
			if ( ! empty( $cart_cat_ids[ (int) $cid ] ) ) {
				return true;
			}
		}
		foreach ( $rule_tags as $tid ) {
			if ( ! empty( $cart_tag_ids[ (int) $tid ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Does the given delivery date pass a single rule?
	 *
	 * @param string $date  Y-m-d delivery date
	 * @param array  $rule  Rule with 'type' set to weekday_set | only_on | from_until
	 */
	public static function date_passes_rule( string $date, array $rule ): bool {
		try {
			$dt = self::wp_datetime( $date );
		} catch ( \Exception $e ) {
			return true;
		}

		switch ( $rule['type'] ) {
			case 'weekday_set':
				// An empty allowed-set means the cart's weekday-restricted
				// products share no common delivery day — reject every date so
				// the checkout falls through to the split / contact-shop flow.
				// (collect_applicable_rules only emits this rule when at least
				// one product is weekday-restricted, so empty is meaningful.)
				$allowed = array_map( 'intval', (array) ( $rule['weekdays'] ?? array() ) );
				if ( empty( $allowed ) ) {
					return false;
				}
				return in_array( (int) $dt->format( 'w' ), $allowed, true );

			case 'only_on':
				// A pre-order day is offered on top of the normal days, never
				// instead of them; apply_display_limit() lets it through.
				if ( ! empty( $rule['extend'] ) ) {
					return true;
				}
				return $dt->format( 'Y-m-d' ) === ( $rule['date'] ?? '' );

			case 'from_until':
				// A pre-order opens days, it never closes them: the same ice
				// cream is still for sale next week. Which far-off days it
				// opens is decided by apply_display_limit().
				if ( ! empty( $rule['extend'] ) ) {
					return true;
				}
				$from  = $rule['from'] ?? '';
				$until = $rule['until'] ?? '';
				if ( $from === '' ) {
					return true;
				}
				try {
					$from_dt = self::wp_datetime( $from );
				} catch ( \Exception $e ) {
					return true;
				}
				if ( $dt < $from_dt ) {
					return false;
				}
				if ( ! empty( $until ) ) {
					try {
						$until_dt = self::wp_datetime( $until );
					} catch ( \Exception $e ) {
						return true;
					}
					if ( $dt > $until_dt ) {
						return false;
					}
				}
				return true;

			default:
				return true;
		}
	}
}
