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
 * Feature pricing
 *
 * Shows the merchant which Økoskabet features they have, whether each one is
 * on, and what it costs them — read from `GET /api/v1/configuration`.
 *
 * This panel computes nothing. Økoskabet's back office decides which features a
 * merchant can see, whether they are on, and what they cost, because the same
 * answer has to hold for WooCommerce, Shopify and merchants calling the API
 * directly. If the plugin worked any of it out for itself, three
 * implementations would start disagreeing and a merchant could edit their own
 * prices. So everything here is display.
 *
 * Two deliberate behaviours:
 *
 *   - **Stale beats empty.** The last successful answer is kept in an option,
 *     not just the five-minute transient. When Økoskabet cannot be reached the
 *     panel shows what it last knew, dated, instead of an empty table that
 *     reads as "you have nothing and it costs nothing".
 *   - **A feature with no price says so in words.** "Ingen ekstra pris" and
 *     "0,00 kr." mean very different things to someone deciding whether to
 *     switch something on.
 *
 * The back office does not serve the `features` block yet. Until it does, the
 * panel says prices are not available — which is the same path a real outage
 * takes, so the failure mode gets exercised from day one.
 */
class Feature_Pricing extends Base {

	/** Option holding the last answer we successfully got, per merchant. */
	const CACHE_OPTION = 'okoskabet_feature_pricing';

	/** Short-lived cache so opening the settings page repeatedly is cheap. */
	const TRANSIENT_PREFIX = 'okoskabet_feature_pricing_';

	const CAPABILITY = 'manage_woocommerce';

	/** How long a fetched answer is reused before asking again. */
	const TTL = 5 * MINUTE_IN_SECONDS;

	public function initialize() {
		parent::initialize();

		// Below the packaging fee, which hooks the same action at 15.
		add_action( 'okoskabet_after_settings_form', array( $this, 'render_section' ), 20 );

		return true;
	}

	// =========================================================================
	// Fetching
	// =========================================================================

	/**
	 * The billing block for a merchant, or null when we have never managed to
	 * fetch one.
	 *
	 * Deliberately its own request rather than sharing the checkout path's
	 * cache: `o_merchant_supports_method()` runs on every cart and keeps only
	 * the shipping methods, and this panel is not worth the risk of changing
	 * how checkout resolves its delivery methods. This one runs in wp-admin
	 * only, at most once every five minutes.
	 *
	 * @return array{features:array, billing:array, fetched_at:int, stale:bool}|null
	 */
	public static function get_pricing( string $merchant_id = 'default' ): ?array {
		$transient_key = self::TRANSIENT_PREFIX . sanitize_key( $merchant_id );

		$cached = get_transient( $transient_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$fresh = self::fetch( $merchant_id );

		if ( $fresh !== null ) {
			$stored                 = (array) get_option( self::CACHE_OPTION, array() );
			$stored[ $merchant_id ] = $fresh;
			update_option( self::CACHE_OPTION, $stored, false );

			set_transient( $transient_key, $fresh, self::TTL );

			return $fresh;
		}

		// Fell over. Show what we last knew rather than nothing, and say how
		// old it is so nobody quotes a stale price as gospel.
		$stored = (array) get_option( self::CACHE_OPTION, array() );
		if ( ! empty( $stored[ $merchant_id ] ) && is_array( $stored[ $merchant_id ] ) ) {
			$last          = $stored[ $merchant_id ];
			$last['stale'] = true;
			return $last;
		}

		return null;
	}

	/**
	 * One call to `/api/v1/configuration`, reduced to the billing parts.
	 *
	 * @return array{features:array, billing:array, fetched_at:int, stale:bool}|null
	 */
	private static function fetch( string $merchant_id ): ?array {
		$merchant = \o_get_merchant( $merchant_id );
		if ( empty( $merchant['api_key'] ) ) {
			return null;
		}

		$response = wp_remote_get(
			\o_merchant_api_url( $merchant ) . '/api/v1/configuration',
			array(
				'timeout' => 10,
				'headers' => array( 'Authorization' => $merchant['api_key'] ),
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return null;
		}

		// An older back office answers this endpoint without a features block.
		// That is not an error — there is simply nothing to price yet.
		return array(
			'features'   => self::normalise_features( $body['features'] ?? array() ),
			'billing'    => is_array( $body['billing'] ?? null ) ? $body['billing'] : array(),
			'fetched_at' => time(),
			'stale'      => false,
		);
	}

	/**
	 * Normalise the features array so the renderer can trust it.
	 *
	 * @param mixed $features
	 * @return array<int,array>
	 */
	private static function normalise_features( $features ): array {
		$out = array();

		foreach ( (array) $features as $feature ) {
			if ( ! is_array( $feature ) || empty( $feature['code'] ) ) {
				continue;
			}

			$price = null;
			if ( ! empty( $feature['price'] ) && is_array( $feature['price'] ) ) {
				$tiers = array();
				foreach ( (array) ( $feature['price']['tiers'] ?? array() ) as $tier ) {
					if ( ! is_array( $tier ) || ! isset( $tier['unit_price'] ) ) {
						continue;
					}
					$tiers[] = array(
						'from'       => isset( $tier['from'] ) ? (int) $tier['from'] : 1,
						// null means "and above" — the open-ended top tier.
						'to'         => isset( $tier['to'] ) && $tier['to'] !== null ? (int) $tier['to'] : null,
						'unit_price' => (string) $tier['unit_price'],
					);
				}

				$price = array(
					'unit'   => (string) ( $feature['price']['unit'] ?? '' ),
					'amount' => isset( $feature['price']['amount'] ) ? (string) $feature['price']['amount'] : null,
					'tiers'  => $tiers,
				);
			}

			$out[] = array(
				'code'          => (string) $feature['code'],
				'name'          => (string) ( $feature['name'] ?? $feature['code'] ),
				'description'   => (string) ( $feature['description'] ?? '' ),
				'enabled'       => ! empty( $feature['enabled'] ),
				'locked'        => ! empty( $feature['locked'] ),
				'locked_reason' => (string) ( $feature['locked_reason'] ?? '' ),
				'price'         => $price,
			);
		}

		return $out;
	}

	/** Drop the caches. Used on save elsewhere, and by the tests. */
	public static function purge( string $merchant_id = 'default' ): void {
		delete_transient( self::TRANSIENT_PREFIX . sanitize_key( $merchant_id ) );
	}

	// =========================================================================
	// Formatting
	// =========================================================================

	/**
	 * A price as a merchant reads it: "2,00 kr. pr. label", or one line per
	 * step when it is a ladder.
	 *
	 * @param array|null $price
	 * @param string     $currency
	 * @return array<int,string> Lines, empty when the feature has no price.
	 */
	public static function price_lines( ?array $price, string $currency ): array {
		if ( $price === null ) {
			return array();
		}

		$unit = self::unit_label( $price['unit'] ?? '' );

		if ( ! empty( $price['tiers'] ) ) {
			$lines = array();
			foreach ( $price['tiers'] as $tier ) {
				$range = $tier['to'] === null
					? sprintf(
						/* translators: %s = a quantity, e.g. "101" */
						__( '%s and above', O_TEXTDOMAIN ),
						number_format_i18n( $tier['from'] )
					)
					: sprintf(
						/* translators: 1: lower bound, 2: upper bound */
						__( '%1$s–%2$s', O_TEXTDOMAIN ),
						number_format_i18n( $tier['from'] ),
						number_format_i18n( $tier['to'] )
					);

				$lines[] = $range . ': ' . self::money( $tier['unit_price'], $currency ) . $unit;
			}
			return $lines;
		}

		if ( $price['amount'] !== null && $price['amount'] !== '' ) {
			return array( self::money( $price['amount'], $currency ) . $unit );
		}

		return array();
	}

	/** " pr. label" — or nothing when the back office did not name a unit. */
	private static function unit_label( string $unit ): string {
		if ( $unit === '' ) {
			return '';
		}

		$known = array(
			'label'    => __( 'per label', O_TEXTDOMAIN ),
			'shipment' => __( 'per shipment', O_TEXTDOMAIN ),
			'order'    => __( 'per order', O_TEXTDOMAIN ),
			'month'    => __( 'per month', O_TEXTDOMAIN ),
		);

		return ' ' . ( $known[ $unit ] ?? $unit );
	}

	/**
	 * Format an amount that arrived as a string.
	 *
	 * The back office sends decimal strings on purpose — JSON floats and money
	 * should not meet — so this only formats, and leaves anything it does not
	 * recognise alone rather than turning it into 0,00.
	 */
	private static function money( string $amount, string $currency ): string {
		if ( ! is_numeric( $amount ) ) {
			return $amount;
		}

		return number_format_i18n( (float) $amount, 2 ) . ' ' . ( $currency !== '' ? $currency : 'DKK' );
	}

	// =========================================================================
	// Admin UI
	// =========================================================================

	public function render_section(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$pricing  = self::get_pricing();
		$currency = (string) ( $pricing['billing']['currency'] ?? 'DKK' );
		?>
		<div id="okoskabet-feature-pricing" style="margin-top:32px;">
			<h2><?php esc_html_e( 'Your Økoskabet features and prices', O_TEXTDOMAIN ); ?></h2>

			<p>
				<?php esc_html_e( 'What Økoskabet charges you for the features you use. This is not the shipping your customers pay — it is your own agreement, and it is managed in Økoskabet\'s back office.', O_TEXTDOMAIN ); ?>
			</p>

			<?php if ( $pricing === null ) : ?>

				<div class="notice notice-warning inline" style="margin:0;">
					<p>
						<?php esc_html_e( 'Prices could not be fetched from Økoskabet right now, so none are shown. This does not affect your deliveries.', O_TEXTDOMAIN ); ?>
					</p>
				</div>

			<?php elseif ( empty( $pricing['features'] ) ) : ?>

				<div class="notice notice-info inline" style="margin:0;">
					<p><?php esc_html_e( 'Økoskabet has not set up any priced features for you yet.', O_TEXTDOMAIN ); ?></p>
				</div>

			<?php else : ?>

				<?php if ( ! empty( $pricing['stale'] ) ) : ?>
					<div class="notice notice-warning inline" style="margin:0 0 12px;">
						<p>
							<?php
							printf(
								/* translators: %s = a human-readable time difference, e.g. "2 hours" */
								esc_html__( 'Økoskabet could not be reached, so these prices are the last ones we saw, %s ago. They may have changed since.', O_TEXTDOMAIN ),
								esc_html( human_time_diff( (int) $pricing['fetched_at'] ) )
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<?php $this->render_period( $pricing['billing'] ); ?>

				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Feature', O_TEXTDOMAIN ); ?></th>
							<th style="width:180px;"><?php esc_html_e( 'Status', O_TEXTDOMAIN ); ?></th>
							<th style="width:280px;"><?php esc_html_e( 'Your price (excl. VAT)', O_TEXTDOMAIN ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $pricing['features'] as $feature ) : ?>
							<?php $this->render_feature_row( $feature, $currency ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description" style="margin-top:10px;">
					<?php
					// Said in the header and again here. These are B2B prices and
					// VAT is added on the invoice, so a merchant who multiplies a
					// price by their usage and budgets for it would otherwise be
					// short by a quarter.
					esc_html_e( 'All prices are excluding VAT. VAT is added on your invoice.', O_TEXTDOMAIN );
					?>
				</p>

				<p class="description">
					<?php esc_html_e( 'To switch a feature on or off, do it in Økoskabet\'s back office — that way it applies wherever you sell, not just in this shop.', O_TEXTDOMAIN ); ?>
				</p>

			<?php endif; ?>
		</div>
		<?php
	}

	private function render_period( array $billing ): void {
		$period = (string) ( $billing['period'] ?? '' );
		$start  = (string) ( $billing['period_start'] ?? '' );
		$end    = (string) ( $billing['period_end'] ?? '' );

		if ( $period === '' && $start === '' ) {
			return;
		}

		$names = array(
			'weekly'      => __( 'Weekly', O_TEXTDOMAIN ),
			'fortnightly' => __( 'Every two weeks', O_TEXTDOMAIN ),
			'monthly'     => __( 'Monthly', O_TEXTDOMAIN ),
		);
		?>
		<p>
			<strong><?php esc_html_e( 'Billing', O_TEXTDOMAIN ); ?>:</strong>
			<?php echo esc_html( $names[ $period ] ?? $period ); ?>
			<?php if ( $start !== '' && $end !== '' ) : ?>
				— <?php
					printf(
						/* translators: 1: period start date, 2: period end date */
						esc_html__( 'current period %1$s to %2$s', O_TEXTDOMAIN ),
						esc_html( $this->date( $start ) ),
						esc_html( $this->date( $end ) )
					);
				?>
			<?php endif; ?>
		</p>
		<?php
	}

	/** ISO date to the shop's own date format, left alone if unparseable. */
	private function date( string $iso ): string {
		$time = strtotime( $iso );
		return $time === false ? $iso : date_i18n( get_option( 'date_format' ), $time );
	}

	private function render_feature_row( array $feature, string $currency ): void {
		$lines = self::price_lines( $feature['price'], $currency );
		?>
		<tr>
			<td>
				<strong><?php echo esc_html( $feature['name'] ); ?></strong>
				<?php if ( $feature['description'] !== '' ) : ?>
					<br><span class="description"><?php echo esc_html( $feature['description'] ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $feature['enabled'] ) : ?>
					<span style="color:#007017;">&#10003; <?php esc_html_e( 'On', O_TEXTDOMAIN ); ?></span>
				<?php else : ?>
					<span style="color:#646970;"><?php esc_html_e( 'Off', O_TEXTDOMAIN ); ?></span>
				<?php endif; ?>

				<?php if ( $feature['locked'] ) : ?>
					<br>
					<span class="description">
						<?php
						// Say why, always. A feature that changes state with no
						// explanation becomes a support call.
						echo esc_html(
							$feature['locked_reason'] !== ''
								? $feature['locked_reason']
								: __( 'Locked by Økoskabet', O_TEXTDOMAIN )
						);
						?>
					</span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( empty( $lines ) ) : ?>
					<span class="description"><?php esc_html_e( 'No extra charge', O_TEXTDOMAIN ); ?></span>
				<?php else : ?>
					<?php foreach ( $lines as $line ) : ?>
						<div><?php echo esc_html( $line ); ?></div>
					<?php endforeach; ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
