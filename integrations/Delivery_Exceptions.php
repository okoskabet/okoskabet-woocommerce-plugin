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
		add_filter( 'okoskabet_filtered_delivery_dates', array( $this, 'filter_dates_for_cart' ), 10, 2 );
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

			// only_on: list of {label, date, enabled, categories, tags}.
			'only_on'   => array(),

			// from_until: list of {label, from, until, enabled, categories, tags}.
			'from_until' => array(),
		);
	}

	/**
	 * Load configuration with sane defaults.
	 */
	public static function get_config(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return self::merge_with_defaults( $stored );
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
					'categories' => array_map( 'intval', (array) ( $item['categories'] ?? array() ) ),
					'tags'       => array_map( 'intval', (array) ( $item['tags']       ?? array() ) ),
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
			<h2><?php esc_html_e( 'Leveringsundtagelser', 'okoskabet-woocommerce-plugin' ); ?></h2>

			<p>
				<?php esc_html_e( 'Definér undtagelser for leveringsdatoer baseret på kategorier og tags. Reglerne er undtagelser — produkter der ikke er tilknyttet en regel, har ingen begrænsning.', 'okoskabet-woocommerce-plugin' ); ?>
				<br>
				<?php esc_html_e( 'Hvis et produkt matcher flere regler, skal ALLE reglerne være opfyldt for at en dato vises.', 'okoskabet-woocommerce-plugin' ); ?>
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Leveringsundtagelser gemt.', 'okoskabet-woocommerce-plugin' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'okoskabet_save_exceptions' ); ?>
				<input type="hidden" name="action" value="okoskabet_save_exceptions" />

				<?php
				$this->render_weekdays_section( $config, $categories, $tags );
				$this->render_only_on_section( $config, $categories, $tags );
				$this->render_from_until_section( $config, $categories, $tags );
				?>

				<?php submit_button( __( 'Gem leveringsundtagelser', 'okoskabet-woocommerce-plugin' ) ); ?>
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
		})();
		</script>

		<?php
		// JS templates for new rows (uses __INDEX__ placeholder).
		echo '<script type="text/template" id="oko-template-only-on">';
		$this->render_only_on_row( '__INDEX__', array( 'label' => '', 'date' => '', 'enabled' => true, 'categories' => array(), 'tags' => array() ), $categories, $tags );
		echo '</script>';

		echo '<script type="text/template" id="oko-template-from-until">';
		$this->render_from_until_row( '__INDEX__', array( 'label' => '', 'from' => '', 'until' => '', 'enabled' => true, 'categories' => array(), 'tags' => array() ), $categories, $tags );
		echo '</script>';
	}

	private function render_weekdays_section( array $config, array $categories, array $tags ): void {
		$enabled    = ! empty( $config['weekdays_enabled'] );
		$weekdays   = $config['weekdays'];
		// Display weekdays in Mon→Sun order (Danish convention).
		$display_order = array( 1, 2, 3, 4, 5, 6, 0 );
		$labels = array(
			1 => __( 'Mandag', 'okoskabet-woocommerce-plugin' ),
			2 => __( 'Tirsdag', 'okoskabet-woocommerce-plugin' ),
			3 => __( 'Onsdag', 'okoskabet-woocommerce-plugin' ),
			4 => __( 'Torsdag', 'okoskabet-woocommerce-plugin' ),
			5 => __( 'Fredag', 'okoskabet-woocommerce-plugin' ),
			6 => __( 'Lørdag', 'okoskabet-woocommerce-plugin' ),
			0 => __( 'Søndag', 'okoskabet-woocommerce-plugin' ),
		);
		?>
		<div class="oko-section <?php echo $enabled ? '' : 'is-disabled'; ?>">
			<label class="oko-master-toggle">
				<input type="checkbox" name="weekdays_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Aktivér: Levering kun på faste ugedage', 'okoskabet-woocommerce-plugin' ); ?>
			</label>
			<p class="oko-help">
				<?php esc_html_e( 'Vælg under hver ugedag hvilke kategorier og/eller tags der KUN må leveres på den dag. Produkter uden tilknytning får ingen begrænsning.', 'okoskabet-woocommerce-plugin' ); ?>
			</p>
			<div class="oko-section-body">
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
								<label><?php esc_html_e( 'Kategorier', 'okoskabet-woocommerce-plugin' ); ?></label>
								<?php $this->render_term_select( "weekdays[$w][categories][]", $categories, (array) ( $entry['categories'] ?? array() ) ); ?>
							</div>
							<div>
								<label><?php esc_html_e( 'Tags', 'okoskabet-woocommerce-plugin' ); ?></label>
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
				<?php esc_html_e( 'Aktivér: Levering kun på en bestemt dag', 'okoskabet-woocommerce-plugin' ); ?>
			</label>
			<p class="oko-help">
				<?php esc_html_e( 'Opret én eller flere undtagelser hvor bestemte kategorier/tags KUN kan leveres på en specifik dato. Hver undtagelse kan slås individuelt til/fra.', 'okoskabet-woocommerce-plugin' ); ?>
			</p>
			<div class="oko-section-body">
				<div id="only_on_rows">
					<?php foreach ( $rows as $i => $row ) : ?>
						<?php $this->render_only_on_row( (string) $i, $row, $categories, $tags ); ?>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button oko-add-btn oko-add-only-on">
					+ <?php esc_html_e( 'Tilføj undtagelse', 'okoskabet-woocommerce-plugin' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	private function render_only_on_row( $index, array $row, array $categories, array $tags ): void {
		?>
		<div class="oko-row">
			<div class="oko-row-row">
				<label><?php esc_html_e( 'Navn', 'okoskabet-woocommerce-plugin' ); ?>:
					<input type="text" name="only_on[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'fx Juleudbringning', 'okoskabet-woocommerce-plugin' ); ?>" style="width:240px;" />
				</label>
				<label><?php esc_html_e( 'Dato', 'okoskabet-woocommerce-plugin' ); ?>:
					<input type="date" name="only_on[<?php echo esc_attr( $index ); ?>][date]" value="<?php echo esc_attr( $row['date'] ?? '' ); ?>" />
				</label>
				<label>
					<input type="checkbox" name="only_on[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> />
					<?php esc_html_e( 'Aktiv', 'okoskabet-woocommerce-plugin' ); ?>
				</label>
				<button type="button" class="button-link oko-remove-row" style="color:#a00;">
					<?php esc_html_e( 'Fjern', 'okoskabet-woocommerce-plugin' ); ?>
				</button>
			</div>
			<div class="oko-row-fields">
				<div>
					<label><?php esc_html_e( 'Kategorier', 'okoskabet-woocommerce-plugin' ); ?></label>
					<?php $this->render_term_select( "only_on[$index][categories][]", $categories, (array) ( $row['categories'] ?? array() ) ); ?>
				</div>
				<div>
					<label><?php esc_html_e( 'Tags', 'okoskabet-woocommerce-plugin' ); ?></label>
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
				<?php esc_html_e( 'Aktivér: Levering fra (og evt. indtil) en bestemt dag', 'okoskabet-woocommerce-plugin' ); ?>
			</label>
			<p class="oko-help">
				<?php esc_html_e( 'Opret undtagelser hvor bestemte kategorier/tags kun kan leveres mellem to datoer. Slut-dato er valgfri — undlad den hvis produktet skal kunne leveres ubegrænset efter start-datoen.', 'okoskabet-woocommerce-plugin' ); ?>
			</p>
			<div class="oko-section-body">
				<div id="from_until_rows">
					<?php foreach ( $rows as $i => $row ) : ?>
						<?php $this->render_from_until_row( (string) $i, $row, $categories, $tags ); ?>
					<?php endforeach; ?>
				</div>
				<button type="button" class="button oko-add-btn oko-add-from-until">
					+ <?php esc_html_e( 'Tilføj undtagelse', 'okoskabet-woocommerce-plugin' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	private function render_from_until_row( $index, array $row, array $categories, array $tags ): void {
		?>
		<div class="oko-row">
			<div class="oko-row-row">
				<label><?php esc_html_e( 'Navn', 'okoskabet-woocommerce-plugin' ); ?>:
					<input type="text" name="from_until[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'fx Sommerprodukter', 'okoskabet-woocommerce-plugin' ); ?>" style="width:240px;" />
				</label>
				<label><?php esc_html_e( 'Fra', 'okoskabet-woocommerce-plugin' ); ?>:
					<input type="date" name="from_until[<?php echo esc_attr( $index ); ?>][from]" value="<?php echo esc_attr( $row['from'] ?? '' ); ?>" />
				</label>
				<label><?php esc_html_e( 'Indtil (valgfri)', 'okoskabet-woocommerce-plugin' ); ?>:
					<input type="date" name="from_until[<?php echo esc_attr( $index ); ?>][until]" value="<?php echo esc_attr( $row['until'] ?? '' ); ?>" />
				</label>
				<label>
					<input type="checkbox" name="from_until[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> />
					<?php esc_html_e( 'Aktiv', 'okoskabet-woocommerce-plugin' ); ?>
				</label>
				<button type="button" class="button-link oko-remove-row" style="color:#a00;">
					<?php esc_html_e( 'Fjern', 'okoskabet-woocommerce-plugin' ); ?>
				</button>
			</div>
			<div class="oko-row-fields">
				<div>
					<label><?php esc_html_e( 'Kategorier', 'okoskabet-woocommerce-plugin' ); ?></label>
					<?php $this->render_term_select( "from_until[$index][categories][]", $categories, (array) ( $row['categories'] ?? array() ) ); ?>
				</div>
				<div>
					<label><?php esc_html_e( 'Tags', 'okoskabet-woocommerce-plugin' ); ?></label>
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
			echo '<option disabled>' . esc_html__( '(ingen oprettet)', 'okoskabet-woocommerce-plugin' ) . '</option>';
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
			wp_die( esc_html__( 'Du har ikke adgang.', 'okoskabet-woocommerce-plugin' ) );
		}
		check_admin_referer( 'okoskabet_save_exceptions' );

		$config = self::default_config();

		// Master toggles.
		$config['weekdays_enabled']   = ! empty( $_POST['weekdays_enabled'] );
		$config['only_on_enabled']    = ! empty( $_POST['only_on_enabled'] );
		$config['from_until_enabled'] = ! empty( $_POST['from_until_enabled'] );

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
				'categories' => $this->sanitize_id_list( $row['categories'] ?? array() ),
				'tags'       => $this->sanitize_id_list( $row['tags'] ?? array() ),
			);
		}

		update_option( self::OPTION_KEY, $config );

		// Send the user back to the main plugin settings page with a flag
		// that the section uses to display a "Saved" notice, plus an anchor
		// so the browser scrolls to where they were.
		wp_safe_redirect( add_query_arg(
			array(
				'page'           => 'okoskabet-woocommerce-plugin',
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
	public function filter_dates_for_cart( array $dates, $product_ids = array() ): array {
		$product_ids = is_array( $product_ids ) ? array_map( 'intval', $product_ids ) : array();
		$product_ids = array_values( array_filter( $product_ids, function ( $id ) { return $id > 0; } ) );

		if ( empty( $product_ids ) ) {
			return $dates;
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

		// Stage 2: extend — add explicitly-named dates from only_on /
		// from_until exceptions that were applicable to this cart but happen
		// to fall outside the API's standard window. We only add dates that
		// pass ALL the same rules, so combinations still hold (e.g. a
		// weekday rule still constrains an extension from another rule).
		$extra = array();
		foreach ( $applicable_rules as $rule ) {
			$candidates = self::extension_candidates_for_rule( $rule );
			foreach ( $candidates as $candidate ) {
				if ( in_array( $candidate, $result, true ) ) {
					continue; // already in the standard list
				}
				// Validate against ALL rules — combinations must still hold.
				$passes_all = true;
				foreach ( $applicable_rules as $r2 ) {
					if ( ! self::date_passes_rule( $candidate, $r2 ) ) {
						$passes_all = false;
						break;
					}
				}
				if ( $passes_all ) {
					$extra[] = $candidate;
				}
			}
		}
		if ( ! empty( $extra ) ) {
			$result = array_values( array_unique( array_merge( $result, $extra ) ) );
			sort( $result );
		}

		error_log( sprintf(
			'Økoskabet exceptions: %d input dates → %d after restriction, +%d extension(s) = %d final. Rules: %s',
			count( $dates ),
			count( $dates ) - max( 0, count( $dates ) - count( array_intersect( $dates, $result ) ) ),
			count( $extra ),
			count( $result ),
			wp_json_encode( $applicable_rules )
		) );

		return $result;
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
			$cat_ids = array();
			$tag_ids = array();
			foreach ( wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) ) as $tid ) { $cat_ids[ (int) $tid ] = true; }
			foreach ( wp_get_post_terms( $pid, 'product_tag', array( 'fields' => 'ids' ) ) as $tid ) { $tag_ids[ (int) $tid ] = true; }
			$product_meta[ $pid ] = array(
				'name'    => $post->post_title !== '' ? $post->post_title : sprintf( __( 'Vare #%d', 'okoskabet-woocommerce-plugin' ), $pid ),
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
						__( 'kan kun leveres %s', 'okoskabet-woocommerce-plugin' ),
						self::format_weekday_list( $matching_weekdays )
					);
				}
			}

			// only_on
			if ( ! empty( $config['only_on_enabled'] ) ) {
				foreach ( $config['only_on'] as $row ) {
					if ( empty( $row['enabled'] ) || empty( $row['date'] ) ) { continue; }
					if ( self::rule_matches_terms( $row, $cat_ids, $tag_ids ) ) {
						$descriptions[] = sprintf(
							__( 'kan kun leveres %s', 'okoskabet-woocommerce-plugin' ),
							self::format_date_human( $row['date'] )
						);
					}
				}
			}

			// from_until
			if ( ! empty( $config['from_until_enabled'] ) ) {
				foreach ( $config['from_until'] as $row ) {
					if ( empty( $row['enabled'] ) || empty( $row['from'] ) ) { continue; }
					if ( self::rule_matches_terms( $row, $cat_ids, $tag_ids ) ) {
						if ( ! empty( $row['until'] ) ) {
							$descriptions[] = sprintf(
								__( 'kan kun leveres mellem %1$s og %2$s', 'okoskabet-woocommerce-plugin' ),
								self::format_date_human( $row['from'] ),
								self::format_date_human( $row['until'] )
							);
						} else {
							$descriptions[] = sprintf(
								__( 'kan tidligst leveres fra %s', 'okoskabet-woocommerce-plugin' ),
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
			$out['summary'] = __( 'Varerne i din kurv har modstridende leveringsregler så ingen dato passer til dem alle. Se nedenfor.', 'okoskabet-woocommerce-plugin' );
		}

		return $out;
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
			0 => __( 'søndage', 'okoskabet-woocommerce-plugin' ),
			1 => __( 'mandage', 'okoskabet-woocommerce-plugin' ),
			2 => __( 'tirsdage', 'okoskabet-woocommerce-plugin' ),
			3 => __( 'onsdage', 'okoskabet-woocommerce-plugin' ),
			4 => __( 'torsdage', 'okoskabet-woocommerce-plugin' ),
			5 => __( 'fredage', 'okoskabet-woocommerce-plugin' ),
			6 => __( 'lørdage', 'okoskabet-woocommerce-plugin' ),
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
		return implode( ', ', $names ) . ' ' . __( 'eller', 'okoskabet-woocommerce-plugin' ) . ' ' . $last;
	}

	/** Format a Y-m-d date as a Danish "d. F Y" string. */
	private static function format_date_human( string $date ): string {
		try {
			$dt = new \DateTime( $date );
		} catch ( \Exception $e ) {
			return $date;
		}
		$months = array( 1=>'januar',2=>'februar',3=>'marts',4=>'april',5=>'maj',6=>'juni',7=>'juli',8=>'august',9=>'september',10=>'oktober',11=>'november',12=>'december' );
		return sprintf( 'den %d. %s %d', (int) $dt->format( 'j' ), $months[ (int) $dt->format( 'n' ) ], (int) $dt->format( 'Y' ) );
	}

	/**
	 * Return the list of specific dates that an exception rule would
	 * "introduce" if they aren't already in the API's standard window.
	 *
	 * - only_on rules contribute their single date.
	 * - from_until rules with a 'from' date contribute that single date
	 *   (we don't enumerate the entire range — just the boundary, so the
	 *   customer at least sees the earliest possible delivery day).
	 * - weekday rules contribute nothing (they restrict, never extend).
	 */
	private static function extension_candidates_for_rule( array $rule ): array {
		switch ( $rule['type'] ?? '' ) {
			case 'only_on':
				return ! empty( $rule['date'] ) ? array( $rule['date'] ) : array();
			case 'from_until':
				return ! empty( $rule['from'] ) ? array( $rule['from'] ) : array();
			default:
				return array();
		}
	}

	/**
	 * Inspect the configuration and the cart and tell the caller how many
	 * days into the future Økoskabet's API needs to be queried for, so the
	 * customer sees every applicable extension date — even those that fall
	 * outside the normal display window.
	 *
	 * Returns the larger of `$default_days` and the number of days from
	 * today to the furthest applicable only_on / from_until date.
	 */
	public static function effective_query_window( int $default_days, array $product_ids ): int {
		$product_ids = array_values( array_filter( array_map( 'intval', $product_ids ), function ( $i ) { return $i > 0; } ) );
		if ( empty( $product_ids ) ) {
			return $default_days;
		}

		$instance = new self();
		$config   = self::get_config();
		$rules    = $instance->collect_applicable_rules( $product_ids, $config );

		$today = new \DateTime( 'today' );
		$max   = $default_days;

		foreach ( $rules as $rule ) {
			$candidates = self::extension_candidates_for_rule( $rule );
			foreach ( $candidates as $date ) {
				try {
					$dt = new \DateTime( $date );
				} catch ( \Exception $e ) {
					continue;
				}
				$diff = (int) $today->diff( $dt )->format( '%r%a' );
				if ( $diff > $max ) {
					$max = $diff;
				}
			}
		}

		// Don't go beyond a hard cap of 365 days — Økoskabet won't reasonably
		// be planning a year out, and we don't want to cause unbounded API
		// load if a user mistypes a far-future date.
		return min( $max, 365 );
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
		// Pre-collect category and tag IDs for every cart product so we
		// don't repeat the term query for each rule.
		$cart_cat_ids = array();
		$cart_tag_ids = array();
		foreach ( $product_ids as $pid ) {
			$pid = (int) $pid;
			if ( $pid <= 0 ) { continue; }
			foreach ( wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) ) as $tid ) {
				$cart_cat_ids[ (int) $tid ] = true;
			}
			foreach ( wp_get_post_terms( $pid, 'product_tag', array( 'fields' => 'ids' ) ) as $tid ) {
				$cart_tag_ids[ (int) $tid ] = true;
			}
		}

		$applicable = array();

		// Weekdays family.
		if ( ! empty( $config['weekdays_enabled'] ) ) {
			foreach ( $config['weekdays'] as $weekday => $entry ) {
				if ( empty( $entry['enabled'] ) ) {
					continue;
				}
				if ( ! $this->terms_intersect( $entry, $cart_cat_ids, $cart_tag_ids ) ) {
					continue;
				}
				$applicable[] = array(
					'type'    => 'weekday_match',
					'weekday' => (int) $weekday,
				);
			}
		}

		// Only-on family.
		if ( ! empty( $config['only_on_enabled'] ) ) {
			foreach ( $config['only_on'] as $row ) {
				if ( empty( $row['enabled'] ) || empty( $row['date'] ) ) {
					continue;
				}
				if ( ! $this->terms_intersect( $row, $cart_cat_ids, $cart_tag_ids ) ) {
					continue;
				}
				$applicable[] = array(
					'type' => 'only_on',
					'date' => $row['date'],
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
					'type'  => 'from_until',
					'from'  => $row['from'],
					'until' => $row['until'] ?? '',
				);
			}
		}

		// Multiple weekday-match rules → merge into a single rule that lists
		// all permitted weekdays. Otherwise a customer with products
		// matching "Mondays only" AND "Thursdays only" would see ZERO valid
		// dates (each rule rejects the other's days), but logically the
		// allowed set is "Mondays OR Thursdays".
		$weekday_rules = array();
		$rest          = array();
		foreach ( $applicable as $r ) {
			if ( $r['type'] === 'weekday_match' ) {
				$weekday_rules[] = (int) $r['weekday'];
			} else {
				$rest[] = $r;
			}
		}
		if ( ! empty( $weekday_rules ) ) {
			$rest[] = array(
				'type'     => 'weekday_set',
				'weekdays' => array_values( array_unique( $weekday_rules ) ),
			);
		}
		return $rest;
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
			$dt = new \DateTime( $date );
		} catch ( \Exception $e ) {
			return true;
		}

		switch ( $rule['type'] ) {
			case 'weekday_set':
				$allowed = array_map( 'intval', (array) ( $rule['weekdays'] ?? array() ) );
				if ( empty( $allowed ) ) {
					return true;
				}
				return in_array( (int) $dt->format( 'w' ), $allowed, true );

			case 'only_on':
				return $dt->format( 'Y-m-d' ) === ( $rule['date'] ?? '' );

			case 'from_until':
				$from  = $rule['from'] ?? '';
				$until = $rule['until'] ?? '';
				if ( $from === '' ) {
					return true;
				}
				try {
					$from_dt = new \DateTime( $from );
				} catch ( \Exception $e ) {
					return true;
				}
				if ( $dt < $from_dt ) {
					return false;
				}
				if ( ! empty( $until ) ) {
					try {
						$until_dt = new \DateTime( $until );
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
