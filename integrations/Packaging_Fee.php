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
 * Packaging fee
 *
 * What merchants call an "emballagegebyr": the surcharge for the box, the
 * cool packs and the packing itself. It is deliberately NOT a shipping rate —
 * it has to survive a free-shipping coupon and it must not compete with the
 * delivery methods for the customer's choice — so it is added as a
 * WooCommerce cart fee.
 *
 * The fee is a LIST of rules, read top to bottom, and the FIRST rule that
 * matches the cart wins. That ordering is the whole design: a shop charges
 * one amount when the order needs an insulated box and a different amount
 * when it doesn't, which is a fallback chain, not a set of independent fees.
 * A rule with no categories, tags or delivery methods matches everything, so
 * it belongs last as the catch-all.
 *
 *   1. "Frost" — categories: Frost — 45 kr
 *   2. "Emballage" — nothing selected — 28 kr
 *
 * A cart with a frozen item pays 45; every other cart pays 28. Exactly one
 * fee is ever charged.
 *
 * A coupon can waive the fee: tick "Gratis emballage" on the coupon itself,
 * next to WooCommerce's own "Allow free shipping".
 *
 * Storage: a single wp_option row, same approach as Delivery_Exceptions —
 * this is configuration, not content.
 */
class Packaging_Fee extends Base {

	/** wp_option key holding the entire packaging-fee configuration. */
	const OPTION_KEY = 'okoskabet_packaging_fee';

	/** Capability required to manage the fee. */
	const CAPABILITY = 'manage_woocommerce';

	/** admin-post action for the settings form. */
	const ACTION_SAVE = 'okoskabet_save_packaging_fee';

	/** Coupon meta flag: this coupon makes the packaging free. */
	const COUPON_META = '_okoskabet_free_packaging';

	/**
	 * The Økoskabet delivery methods a rule can be limited to. Keyed by the
	 * WooCommerce shipping method id, which is what a chosen rate reports.
	 *
	 * @return array<string,string>
	 */
	private static function method_labels(): array {
		return array(
			'hey_okoskabet_shipping_shed'         => __( 'Økoskabet', O_TEXTDOMAIN ),
			'hey_okoskabet_shipping_home'         => __( 'Home delivery', O_TEXTDOMAIN ),
			'hey_okoskabet_shipping_store_pickup' => __( 'Store pickup', O_TEXTDOMAIN ),
		);
	}

	/**
	 * Everything a rule can be limited to: each delivery method as a whole,
	 * and each place a merchant has actually put one.
	 *
	 * The whole-method entries cover every copy of that method in every zone,
	 * which is what rules saved before this existed already mean. The
	 * individual entries exist because two copies of the same method are
	 * not the same delivery to a merchant — home delivery to the mainland and
	 * home delivery to Bornholm, or an ordinary delivery and a pre-order —
	 * and a merchant who charges more for one of them had no way to say so.
	 *
	 * Keyed the way WooCommerce reports a chosen rate: the method id, then a
	 * colon and the instance id for a specific copy.
	 *
	 * @return array<string,string>
	 */
	public static function method_choices(): array {
		$choices = array();

		foreach ( self::method_labels() as $method_id => $label ) {
			/* translators: %s = a delivery method, e.g. "Home delivery" */
			$choices[ $method_id ] = sprintf( __( '%s — everywhere', O_TEXTDOMAIN ), $label );
		}

		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return $choices;
		}

		$zones = \WC_Shipping_Zones::get_zones();
		// The catch-all zone is not in get_zones(), and a merchant can put
		// methods in it like any other.
		$zones[] = array( 'zone_id' => 0 );

		foreach ( $zones as $zone_row ) {
			$zone = new \WC_Shipping_Zone( (int) $zone_row['zone_id'] );

			foreach ( $zone->get_shipping_methods() as $method ) {
				$method_id = (string) $method->id;
				if ( ! isset( self::method_labels()[ $method_id ] ) ) {
					continue;
				}

				$key   = $method_id . ':' . (int) $method->get_instance_id();
				$title = trim( (string) $method->get_title() );

				$choices[ $key ] = sprintf(
					/* translators: 1: shipping zone, 2: the method's own title, 3: which kind of method it is */
					__( '%1$s — %2$s (%3$s)', O_TEXTDOMAIN ),
					$zone->get_zone_name(),
					$title !== '' ? $title : self::method_labels()[ $method_id ],
					self::method_labels()[ $method_id ]
				);
			}
		}

		return $choices;
	}

	/**
	 * Whether a chosen rate is one of the methods a rule names.
	 *
	 * A rule naming a whole method matches every copy of it; a rule naming a
	 * specific copy matches only that one.
	 *
	 * @param string   $rate_id  As WooCommerce reports it, e.g. `hey_okoskabet_shipping_home:5`.
	 * @param string[] $methods  What the rule is limited to.
	 */
	public static function method_matches( string $rate_id, array $methods ): bool {
		$parts     = explode( ':', $rate_id );
		$method_id = $parts[0];
		$instance  = isset( $parts[1] ) && $parts[1] !== '' ? $method_id . ':' . (int) $parts[1] : null;

		return in_array( $method_id, $methods, true )
			|| ( $instance !== null && in_array( $instance, $methods, true ) );
	}

	/**
	 * Whether a posted value is something a rule may be limited to: one of the
	 * Økoskabet methods, or a specific copy of one.
	 *
	 * Checked by shape rather than against the zones as they are right now, so
	 * a copy the merchant has since deleted survives a save — see the note in
	 * render_rule_row().
	 */
	public static function is_valid_method_key( $key ): bool {
		$key = (string) $key;

		if ( isset( self::method_labels()[ $key ] ) ) {
			return true;
		}

		$parts = explode( ':', $key );

		return count( $parts ) === 2
			&& isset( self::method_labels()[ $parts[0] ] )
			&& ctype_digit( $parts[1] )
			&& (int) $parts[1] > 0;
	}

	/** Request-level cache of the normalised configuration. */
	private static $config_cache = null;

	public function initialize() {
		parent::initialize();

		// Rendered as a panel on the main plugin settings page, below the
		// delivery exceptions (which hook the same action at 10).
		add_action( 'okoskabet_after_settings_form', array( $this, 'render_section' ), 15 );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( $this, 'handle_save' ) );

		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply' ) );

		// "Gratis emballage" sits on the coupon itself, right where a merchant
		// already goes to tick "Allow free shipping".
		add_action( 'woocommerce_coupon_options', array( $this, 'render_coupon_option' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon_option' ), 10, 2 );

		return true;
	}

	// =========================================================================
	// Configuration
	// =========================================================================

	/**
	 * The stored configuration, normalised so every caller can trust the shape.
	 *
	 * @return array{enabled:bool, include_tax:bool, taxable:bool, tax_class:string, rules:array<int,array>}
	 */
	public static function get_config(): array {
		if ( self::$config_cache !== null ) {
			return self::$config_cache;
		}

		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		if ( empty( $stored ) ) {
			$stored = self::config_from_legacy_settings();
		}

		self::$config_cache = self::normalise_config( $stored );

		return self::$config_cache;
	}

	/**
	 * Turn a raw stored configuration into the shape every caller can trust.
	 * Split out from get_config() so the rule engine can be exercised without
	 * a round trip through the options table.
	 *
	 * @param array $stored
	 * @return array{enabled:bool, include_tax:bool, taxable:bool, tax_class:string, rules:array<int,array>}
	 */
	public static function normalise_config( array $stored ): array {
		$tax_class = (string) ( $stored['tax_class'] ?? 'standard' );

		$rules = array();
		foreach ( (array) ( $stored['rules'] ?? array() ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$rules[] = self::normalise_rule( $rule );
		}

		return array(
			'enabled' => ! empty( $stored['enabled'] ),
			// The saved flag asks the inverse ("amounts are excl. VAT") on
			// purpose: an unticked checkbox is absent from the posted form, so
			// the safe reading of "absent" has to be the common case — the
			// merchant typed the price the customer pays.
			'include_tax' => empty( $stored['ex_tax'] ),
			'taxable'     => $tax_class !== 'none',
			'tax_class'   => $tax_class === 'standard' ? '' : $tax_class,
			'rules'       => $rules,
		);
	}

	/** Drop the request-level cache. Called on save, and available to tests. */
	public static function purge_config_cache(): void {
		self::$config_cache = null;
	}

	/**
	 * Normalise one stored rule.
	 *
	 * @param array $rule
	 * @return array{label:string, amount:float, tiers:array, categories:array<int,int>, tags:array<int,int>, methods:array<int,string>, enabled:bool}
	 */
	private static function normalise_rule( array $rule ): array {
		$label = trim( (string) ( $rule['label'] ?? '' ) );
		if ( $label === '' ) {
			$label = __( 'Packaging', O_TEXTDOMAIN );
		}

		return array(
			'label'      => $label,
			'amount'     => (float) str_replace( ',', '.', (string) ( $rule['amount'] ?? '0' ) ),
			'tiers'      => \oko_parse_shipping_tiers( (string) ( $rule['tiers'] ?? '' ) ),
			// The merchant's own selection, unexpanded. Descendants are pulled
			// in at match time instead, so the form keeps showing what they
			// ticked and a sub-category added later is covered automatically
			// rather than needing the rule re-saved.
			'categories' => self::clean_term_ids( $rule['categories'] ?? array() ),
			'tags'       => self::clean_term_ids( $rule['tags'] ?? array() ),
			'methods'    => array_values( array_filter( array_map( 'strval', (array) ( $rule['methods'] ?? array() ) ) ) ),
			// Charged only while the customer is placing a pre-order — pressed
			// the pre-order button at checkout. A rule saved when this was a
			// number of days ahead still counts as a pre-order rule.
			'pre_order_only' => ! empty( $rule['pre_order_only'] ) || (int) ( $rule['min_days_ahead'] ?? 0 ) > 0,
			'enabled'    => ! empty( $rule['enabled'] ),
		);
	}

	/**
	 * Term ids as the merchant picked them: positive integers, deduplicated.
	 *
	 * @param mixed $ids
	 * @return array<int,int>
	 */
	private static function clean_term_ids( $ids ): array {
		$clean = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$clean[ $id ] = $id;
			}
		}

		return array_values( $clean );
	}

	/**
	 * Carry a 1.4.6-style single fee (stored as CMB2 plugin settings) over into
	 * the rule list. Runs only while the new option row is still empty, so a
	 * merchant who set the fee up before this release keeps it without having
	 * to re-enter anything.
	 */
	private static function config_from_legacy_settings(): array {
		$legacy = \o_get_settings();
		if ( ! array_key_exists( '_packaging_fee_enabled', $legacy ) && ! array_key_exists( '_packaging_fee_amount', $legacy ) ) {
			return array();
		}

		return array(
			'enabled'   => ! empty( $legacy['_packaging_fee_enabled'] ),
			'ex_tax'    => ! empty( $legacy['_packaging_fee_ex_tax'] ),
			'tax_class' => (string) ( $legacy['_packaging_fee_tax_class'] ?? 'standard' ),
			'rules'     => array(
				array(
					'label'      => (string) ( $legacy['_packaging_fee_label'] ?? '' ),
					'amount'     => (string) ( $legacy['_packaging_fee_amount'] ?? '0' ),
					'tiers'      => (string) ( $legacy['_packaging_fee_tiers'] ?? '' ),
					'categories' => (array) ( $legacy['_packaging_fee_categories'] ?? array() ),
					'tags'       => (array) ( $legacy['_packaging_fee_tags'] ?? array() ),
					'methods'    => (array) ( $legacy['_packaging_fee_methods'] ?? array() ),
					'enabled'    => true,
				),
			),
		);
	}

	// =========================================================================
	// Matching
	// =========================================================================

	/**
	 * The first enabled rule whose conditions the cart satisfies, or null when
	 * none of them do (in which case no fee is charged at all).
	 *
	 * @return array|null
	 */
	public static function matching_rule( array $config, \WC_Cart $cart ): ?array {
		foreach ( $config['rules'] as $rule ) {
			if ( empty( $rule['enabled'] ) ) {
				continue;
			}
			if ( ! self::rule_matches_method( $rule ) ) {
				continue;
			}
			if ( ! self::rule_matches_cart_terms( $rule, $cart ) ) {
				continue;
			}
			if ( ! empty( $rule['pre_order_only'] ) && ! self::is_pre_order() ) {
				continue;
			}
			if ( ! empty( $rule['pre_order_only'] ) && self::pre_order_has_no_date() ) {
				continue;
			}
			return $rule;
		}

		return null;
	}

	/**
	 * Whether the customer is placing a pre-order. Only the checkout knows —
	 * the cart page never has the button — so anywhere else the answer is no.
	 */
	private static function is_pre_order(): bool {
		return \function_exists( 'oko_is_pre_order_checkout' ) && \oko_is_pre_order_checkout();
	}

	/**
	 * Whether this basket, as a pre-order, has no day it could be delivered on.
	 *
	 * A pre-order fee pays for holding goods until a date in the future. With
	 * no date there is nothing being held. A Gaardmester basket mixing
	 * pre-orderable and ordinary goods had no pre-order day at all, showed the
	 * customer no dates and no explanation, and still charged them 50 kr for it.
	 *
	 * Only a clear "there is no day" waives the fee. If Økoskabet cannot be
	 * asked, the fee is charged as it always was — a shop should not lose the
	 * charge to a timeout, and the checkout will not let the order through
	 * without a date anyway.
	 */
	private static function pre_order_has_no_date(): bool {
		$has_date = \function_exists( 'oko_pre_order_cart_has_date' ) ? \oko_pre_order_cart_has_date() : null;

		/**
		 * Whether the basket has a pre-order day: true, false, or null for
		 * "could not find out". Answering it here is what lets the rule be
		 * tested without an Økoskabet to ask.
		 *
		 * @param bool|null $has_date
		 */
		$has_date = \apply_filters( 'okoskabet_pre_order_cart_has_date', $has_date );

		return $has_date === false;
	}

	/**
	 * Whether a rule applies to the delivery the customer picked. An empty
	 * method list means "any delivery", which is what a merchant gets by
	 * leaving the boxes alone.
	 */
	private static function rule_matches_method( array $rule ): bool {
		if ( empty( $rule['methods'] ) ) {
			return true;
		}
		if ( ! WC()->session ) {
			return false;
		}
		foreach ( (array) WC()->session->get( 'chosen_shipping_methods', array() ) as $package_key => $rate_id ) {
			if ( self::method_matches( self::chosen_rate_key( $package_key, (string) $rate_id ), $rule['methods'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The chosen rate as `method:instance`, whatever its id happens to say.
	 *
	 * The session only holds the rate's id, and the Økoskabet methods register
	 * their rates under the bare method id — `hey_okoskabet_shipping_home`,
	 * with no instance after it. So home delivery to the mainland and home
	 * delivery to the islands arrive here looking identical, and a rule aimed
	 * at one of them matched neither.
	 *
	 * The rate object itself still knows which copy produced it, because
	 * WooCommerce records the instance when the rate is added. So the id is
	 * looked up in the calculated packages and the key is built from the rate.
	 * The ids are left as they are on purpose: the checkout script and the
	 * order validation compare against them, and renaming them would be a
	 * change to every merchant's checkout for the sake of a fee.
	 *
	 * Falls back to the id as given when there is nothing to look up, which is
	 * still right for any method whose ids already carry their instance.
	 *
	 * @param int|string $package_key
	 */
	private static function chosen_rate_key( $package_key, string $rate_id ): string {
		if ( ! function_exists( 'WC' ) || ! WC()->shipping() ) {
			return $rate_id;
		}

		$packages = WC()->shipping()->get_packages();
		$rate     = $packages[ $package_key ]['rates'][ $rate_id ] ?? null;

		if ( ! $rate instanceof \WC_Shipping_Rate ) {
			return $rate_id;
		}

		$instance = (int) $rate->get_instance_id();

		return $instance > 0
			? $rate->get_method_id() . ':' . $instance
			: $rate->get_method_id();
	}

	/**
	 * Whether the cart holds at least one product the rule is attached to.
	 *
	 * With no categories and no tags the rule is unconditional — that is the
	 * catch-all a fallback rule relies on. Once either list has an entry, one
	 * matching product anywhere in the cart is enough, because the box it
	 * needs gets packed for the whole order.
	 */
	private static function rule_matches_cart_terms( array $rule, \WC_Cart $cart ): bool {
		if ( empty( $rule['categories'] ) && empty( $rule['tags'] ) ) {
			return true;
		}

		// Expanded here rather than at save time: picking "Frost" and getting
		// nothing because the products actually sit in "Frost > Fisk" is the
		// kind of surprise a merchant discovers from a customer complaint.
		$cats = \oko_packaging_fee_term_ids( $rule['categories'], 'product_cat' );
		$tags = \oko_packaging_fee_term_ids( $rule['tags'], 'product_tag' );

		foreach ( $cart->get_cart() as $values ) {
			// Variations carry their terms on the parent product.
			$product_id = (int) ( $values['product_id'] ?? 0 );
			if ( $product_id <= 0 ) {
				continue;
			}
			if ( ! empty( $cats ) && has_term( $cats, 'product_cat', $product_id ) ) {
				return true;
			}
			if ( ! empty( $tags ) && has_term( $tags, 'product_tag', $product_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any applied coupon waives the fee.
	 *
	 * Deliberately separate from WooCommerce's free-shipping flag: a shop can
	 * hand out free delivery without also giving the box away.
	 */
	public static function coupon_waives_fee( \WC_Cart $cart ): bool {
		foreach ( $cart->get_applied_coupons() as $code ) {
			$coupon = new \WC_Coupon( $code );
			if ( $coupon->get_meta( self::COUPON_META ) === 'yes' ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The amount a rule charges for this cart, in whichever VAT mode the
	 * merchant typed.
	 *
	 * Subtotals are summed off the cart lines rather than read from
	 * `$cart->get_subtotal()` so a ladder compares against exactly the same
	 * numbers `oko_add_ladder_shipping_rate()` uses — the same ladder text has
	 * to mean the same thing in both places.
	 */
	public static function rule_amount( array $rule, array $config, \WC_Cart $cart ): float {
		if ( empty( $rule['tiers'] ) ) {
			return (float) $rule['amount'];
		}

		$ex   = 0.0;
		$incl = 0.0;
		foreach ( $cart->get_cart() as $values ) {
			$line  = (float) ( $values['line_total'] ?? 0 );
			$ex   += $line;
			$incl += $line + (float) ( $values['line_tax'] ?? 0 );
		}

		$cost = \oko_ladder_cost_for_subtotal( $rule['tiers'], $config['include_tax'] ? $incl : $ex );

		return $cost === null ? 0.0 : (float) $cost;
	}

	// =========================================================================
	// Checkout
	// =========================================================================

	/**
	 * Add the winning rule's fee to the cart.
	 *
	 * Note for split checkout: each part-order is its own cart, so each one
	 * carries its own fee. That matches how the packaging cost actually falls —
	 * two deliveries mean two boxes.
	 */
	public function apply( $cart = null ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Core always hands us the cart, but a few plugins re-fire this action
		// bare. Falling back beats a fatal on somebody else's checkout.
		$cart = $cart instanceof \WC_Cart ? $cart : WC()->cart;
		if ( ! $cart instanceof \WC_Cart || $cart->is_empty() ) {
			return;
		}

		$config = self::get_config();
		if ( empty( $config['enabled'] ) || empty( $config['rules'] ) ) {
			return;
		}

		if ( self::coupon_waives_fee( $cart ) ) {
			return;
		}

		$rule = self::matching_rule( $config, $cart );
		if ( $rule === null ) {
			return;
		}

		$amount = self::rule_amount( $rule, $config, $cart );
		if ( $amount <= 0 ) {
			return;
		}

		// WooCommerce expects the fee ex-VAT and adds tax on top. When the
		// merchant typed the price the customer should see, divide it back out
		// so the checkout total lands on exactly the figure they entered.
		if ( $config['taxable'] && $config['include_tax'] ) {
			$ratio = \oko_fee_tax_ratio( $config['tax_class'] );
			if ( $ratio > 0 ) {
				$amount /= ( 1 + $ratio );
			}
		}

		$cart->add_fee(
			$rule['label'],
			round( $amount, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 ),
			$config['taxable'],
			$config['tax_class']
		);
	}

	// =========================================================================
	// Coupon option
	// =========================================================================

	public function render_coupon_option( $coupon_id, $coupon = null ): void {
		$value = get_post_meta( (int) $coupon_id, self::COUPON_META, true );
		?>
		<div class="options_group">
			<?php
			woocommerce_wp_checkbox(
				array(
					'id'          => 'okoskabet_free_packaging',
					'label'       => __( 'Free packaging', O_TEXTDOMAIN ),
					'description' => __( 'Waive Økoskabet\'s packaging fee for orders using this coupon.', O_TEXTDOMAIN ),
					'value'       => $value === 'yes' ? 'yes' : 'no',
				)
			);
			?>
		</div>
		<?php
	}

	/**
	 * WooCommerce has already verified its own coupon-metabox nonce by the
	 * time this fires, and it saves the coupon itself right before — so we
	 * write the meta directly rather than re-saving the coupon object.
	 */
	public function save_coupon_option( $post_id, $coupon = null ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$value = ! empty( $_POST['okoskabet_free_packaging'] ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification
		update_post_meta( (int) $post_id, self::COUPON_META, $value );
	}

	// =========================================================================
	// Admin UI
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

		$saved = isset( $_GET['oko_fee_saved'] ) && $_GET['oko_fee_saved'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification

		$rows = $config['rules'];
		if ( empty( $rows ) ) {
			$rows = array( self::blank_rule() );
		}
		?>
		<div id="okoskabet-packaging-fee" style="margin-top:32px;">
			<h2><?php esc_html_e( 'Packaging fee', O_TEXTDOMAIN ); ?></h2>

			<p>
				<?php esc_html_e( 'An extra fee added to the order at checkout, for packaging, cool packs, boxes and the like. It is a cart fee, not shipping — a free-shipping coupon does not remove it, and it is charged once per order.', O_TEXTDOMAIN ); ?>
				<br>
				<?php esc_html_e( 'The rules are read from the top down and the FIRST one that fits the cart wins — only ever one fee. Put your specific rules first and leave a rule with nothing selected at the bottom as the catch-all.', O_TEXTDOMAIN ); ?>
			</p>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Packaging fee saved.', O_TEXTDOMAIN ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="oko-fee-form">
				<?php wp_nonce_field( self::ACTION_SAVE ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>" />

				<div class="oko-section<?php echo $config['enabled'] ? '' : ' is-disabled-body'; ?>">
					<label class="oko-master-toggle">
						<input type="checkbox" name="enabled" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?> />
						<?php esc_html_e( 'Add a packaging fee', O_TEXTDOMAIN ); ?>
					</label>

					<div class="oko-section-body">
						<div class="oko-row-row" style="margin-top:12px;">
							<label>
								<input type="checkbox" name="ex_tax" value="1" <?php checked( empty( $config['include_tax'] ) ); ?> />
								<?php esc_html_e( 'Amounts are excl. VAT', O_TEXTDOMAIN ); ?>
							</label>
							<label>
								<?php esc_html_e( 'VAT class', O_TEXTDOMAIN ); ?>:
								<?php $this->render_tax_class_select( $config ); ?>
							</label>
						</div>
						<p class="oko-help">
							<?php esc_html_e( 'Leave "excl. VAT" off if you typed the amount the customer should actually pay — the normal case. These two settings apply to every rule below.', O_TEXTDOMAIN ); ?>
						</p>

						<div id="oko_fee_rows">
							<?php foreach ( $rows as $i => $row ) : ?>
								<?php $this->render_rule_row( (string) $i, $row, $categories, $tags ); ?>
							<?php endforeach; ?>
						</div>
						<button type="button" class="button oko-add-btn oko-add-fee-rule">
							+ <?php esc_html_e( 'Add fee rule', O_TEXTDOMAIN ); ?>
						</button>
					</div>
				</div>

				<?php submit_button( __( 'Save packaging fee', O_TEXTDOMAIN ) ); ?>
			</form>
		</div>

		<style>
			/* Self-contained: the delivery-exceptions panel defines lookalike
			   rules, but this section must not depend on that one rendering. */
			#okoskabet-packaging-fee .oko-section { background:#fff; border:1px solid #c3c4c7; padding:16px 20px; margin:24px 0; }
			#okoskabet-packaging-fee .oko-row { border-top:1px solid #f0f0f1; padding:12px 0; }
			#okoskabet-packaging-fee .oko-row:first-of-type { border-top:0; }
			#okoskabet-packaging-fee .oko-row-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
			#okoskabet-packaging-fee .oko-row-row label { font-weight:600; }
			#okoskabet-packaging-fee .oko-help { color:#666; font-size:0.9em; margin:4px 0 0; }
			#okoskabet-packaging-fee .oko-master-toggle { font-weight:600; }
			#okoskabet-packaging-fee .oko-add-btn { margin-top:8px; }
			#okoskabet-packaging-fee .oko-section.is-disabled-body .oko-section-body { opacity:0.45; pointer-events:none; }
			#okoskabet-packaging-fee .oko-fee-terms { width:100%; min-height:80px; }
			#okoskabet-packaging-fee .oko-rule-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-top:8px; }
			#okoskabet-packaging-fee .oko-rule-order { color:#646970; font-weight:600; margin-right:4px; }
		</style>

		<script>
		(function(){
			var root = document.getElementById('okoskabet-packaging-fee');
			if (!root) return;

			root.addEventListener('change', function(e){
				if (e.target.matches('.oko-master-toggle input[type=checkbox]')) {
					var section = e.target.closest('.oko-section');
					if (section) section.classList.toggle('is-disabled-body', !e.target.checked);
				}
			});

			root.addEventListener('click', function(e){
				var btn = e.target.closest('button');
				if (!btn) return;

				if (btn.matches('.oko-add-fee-rule')) {
					e.preventDefault();
					var tpl = document.getElementById('oko-template-fee-rule');
					if (!tpl) return;
					var wrap = document.createElement('div');
					wrap.innerHTML = tpl.innerHTML;
					document.getElementById('oko_fee_rows').appendChild(wrap.firstElementChild);
					renumber();
				}
				if (btn.matches('.oko-remove-row')) {
					e.preventDefault();
					var row = btn.closest('.oko-row');
					if (row) row.remove();
					renumber();
				}
				if (btn.matches('.oko-move-up') || btn.matches('.oko-move-down')) {
					e.preventDefault();
					var r = btn.closest('.oko-row');
					if (!r) return;
					var sib = btn.matches('.oko-move-up') ? r.previousElementSibling : r.nextElementSibling;
					if (!sib) return;
					// Order is the rule engine's priority, so moving a row has
					// to move the real inputs, not just what the eye sees.
					if (btn.matches('.oko-move-up')) { r.parentNode.insertBefore(r, sib); }
					else { r.parentNode.insertBefore(sib, r); }
					renumber();
				}
			});

			// Rewrite every input's index so the posted array matches what is
			// on screen after adding, removing or reordering rows.
			function renumber() {
				var rows = document.querySelectorAll('#oko_fee_rows .oko-row');
				Array.prototype.forEach.call(rows, function(row, i){
					var label = row.querySelector('.oko-rule-order');
					if (label) label.textContent = (i + 1) + '.';
					Array.prototype.forEach.call(row.querySelectorAll('[name]'), function(el){
						el.name = el.name.replace(/^rules\[[^\]]*\]/, 'rules[' + i + ']');
					});
				});
			}

			renumber();
		})();
		</script>

		<?php
		echo '<script type="text/template" id="oko-template-fee-rule">';
		$this->render_rule_row( '__INDEX__', self::blank_rule(), $categories, $tags );
		echo '</script>';
	}

	/** The shape a fresh rule row starts from. */
	private static function blank_rule(): array {
		return array(
			'label'      => '',
			'amount'     => '0',
			'tiers'      => '',
			'categories' => array(),
			'tags'       => array(),
			'methods'    => array(),
			'pre_order_only' => false,
			'enabled'    => true,
		);
	}

	private function render_rule_row( $index, array $row, array $categories, array $tags ): void {
		// Looking the zones up once per page rather than once per rule.
		static $all_method_choices = null;
		if ( $all_method_choices === null ) {
			$all_method_choices = self::method_choices();
		}

		// A copy of a method the merchant has since deleted from its zone is
		// still listed, marked as gone, rather than dropped. Dropping it would
		// quietly widen the rule to "any delivery" the next time the page was
		// saved — and a fee that suddenly lands on every order is found by a
		// customer, not by the merchant.
		$method_choices = $all_method_choices;
		foreach ( array_map( 'strval', (array) ( $row['methods'] ?? array() ) ) as $saved ) {
			if ( $saved !== '' && ! isset( $method_choices[ $saved ] ) ) {
				/* translators: %s = the internal id of a delivery method that no longer exists */
				$method_choices[ $saved ] = sprintf( __( 'Removed delivery method (%s)', O_TEXTDOMAIN ), $saved );
			}
		}

		$amount = $row['amount'] ?? '0';
		// get_config() hands back floats; the blank row and the JS template
		// hand back strings. Print whatever came in without reformatting, so a
		// merchant's "28,50" survives a round trip.
		$amount = is_float( $amount ) ? rtrim( rtrim( number_format( $amount, 2, '.', '' ), '0' ), '.' ) : (string) $amount;
		$tiers  = $row['tiers'] ?? '';
		if ( is_array( $tiers ) ) {
			// Normalised config stores parsed tiers; render them back as text.
			$lines = array();
			foreach ( $tiers as $tier ) {
				$lines[] = $tier['from'] . ' = ' . $tier['cost'];
			}
			$tiers = implode( "\n", $lines );
		}
		?>
		<div class="oko-row">
			<div class="oko-row-row">
				<span class="oko-rule-order">1.</span>
				<label><?php esc_html_e( 'Name', O_TEXTDOMAIN ); ?>:
					<input type="text" name="rules[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( (string) ( $row['label'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'e.g. Packaging (frozen)', O_TEXTDOMAIN ); ?>" style="width:220px;" />
				</label>
				<label><?php esc_html_e( 'Amount', O_TEXTDOMAIN ); ?>:
					<input type="text" inputmode="decimal" name="rules[<?php echo esc_attr( $index ); ?>][amount]" value="<?php echo esc_attr( $amount ); ?>" style="width:90px;" />
				</label>
				<label title="<?php esc_attr_e( 'Charged only when the customer has pressed the pre-order button at checkout.', O_TEXTDOMAIN ); ?>">
					<input type="checkbox" name="rules[<?php echo esc_attr( $index ); ?>][pre_order_only]" value="1" <?php checked( ! empty( $row['pre_order_only'] ) ); ?> />
					<?php esc_html_e( 'Pre-orders only', O_TEXTDOMAIN ); ?>
				</label>
				<label>
					<input type="checkbox" name="rules[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> />
					<?php esc_html_e( 'Active', O_TEXTDOMAIN ); ?>
				</label>
				<button type="button" class="button-link oko-move-up" title="<?php esc_attr_e( 'Move up', O_TEXTDOMAIN ); ?>">▲</button>
				<button type="button" class="button-link oko-move-down" title="<?php esc_attr_e( 'Move down', O_TEXTDOMAIN ); ?>">▼</button>
				<button type="button" class="button-link oko-remove-row" style="color:#a00;">
					<?php esc_html_e( 'Remove', O_TEXTDOMAIN ); ?>
				</button>
			</div>

			<div class="oko-rule-grid">
				<div>
					<label><?php esc_html_e( 'Categories', O_TEXTDOMAIN ); ?></label>
					<?php $this->render_term_select( "rules[$index][categories][]", $categories, (array) ( $row['categories'] ?? array() ) ); ?>
					<p class="oko-help"><?php esc_html_e( 'Sub-categories count too. Leave empty for any product.', O_TEXTDOMAIN ); ?></p>
				</div>
				<div>
					<label><?php esc_html_e( 'Tags', O_TEXTDOMAIN ); ?></label>
					<?php $this->render_term_select( "rules[$index][tags][]", $tags, (array) ( $row['tags'] ?? array() ) ); ?>
					<p class="oko-help"><?php esc_html_e( 'One match in either list is enough.', O_TEXTDOMAIN ); ?></p>
				</div>
				<div>
					<label><?php esc_html_e( 'Delivery methods', O_TEXTDOMAIN ); ?></label>
					<select name="rules[<?php echo esc_attr( $index ); ?>][methods][]" multiple class="oko-fee-terms">
						<?php foreach ( $method_choices as $method_id => $method_label ) : ?>
							<option value="<?php echo esc_attr( $method_id ); ?>" <?php selected( in_array( $method_id, array_map( 'strval', (array) ( $row['methods'] ?? array() ) ), true ) ); ?>>
								<?php echo esc_html( $method_label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="oko-help"><?php esc_html_e( 'Leave empty for any delivery method.', O_TEXTDOMAIN ); ?></p>
				</div>
			</div>

			<div style="margin-top:8px;">
				<label><?php esc_html_e( 'Fee ladder (optional)', O_TEXTDOMAIN ); ?></label>
				<textarea name="rules[<?php echo esc_attr( $index ); ?>][tiers]" rows="3" style="width:100%;max-width:520px;" placeholder="0 = 28&#10;500 = 15&#10;1000 = 0"><?php echo esc_textarea( (string) $tiers ); ?></textarea>
				<p class="oko-help"><?php esc_html_e( 'Same format as the shipping fee ladder: one tier per line as "from amount = fee". The highest tier at or below the cart subtotal wins, and 0 means no fee. Leave empty to use the fixed amount above.', O_TEXTDOMAIN ); ?></p>
			</div>
		</div>
		<?php
	}

	private function render_tax_class_select( array $config ): void {
		$current = $config['taxable'] ? ( $config['tax_class'] === '' ? 'standard' : $config['tax_class'] ) : 'none';

		$options = array(
			'none'     => __( 'Not taxed', O_TEXTDOMAIN ),
			'standard' => __( 'Standard rate', O_TEXTDOMAIN ),
		);
		if ( class_exists( 'WC_Tax' ) ) {
			foreach ( \WC_Tax::get_tax_classes() as $name ) {
				$options[ sanitize_title( $name ) ] = $name;
			}
		}

		echo '<select name="tax_class">';
		foreach ( $options as $slug => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $slug ),
				selected( $current, $slug, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Render a multi-select for categories or tags. Defensive against any
	 * value type — selected IDs are normalised to strings for comparison.
	 */
	private function render_term_select( string $name, array $terms, array $selected ): void {
		$selected_str = array_map( 'strval', array_map( 'intval', $selected ) );
		echo '<select name="' . esc_attr( $name ) . '" multiple class="oko-fee-terms">';
		if ( empty( $terms ) ) {
			echo '<option disabled>' . esc_html__( '(none created)', O_TEXTDOMAIN ) . '</option>';
		} else {
			foreach ( $terms as $term ) {
				$id = (string) (int) $term->term_id;
				echo '<option value="' . esc_attr( $id ) . '"'
					. ( in_array( $id, $selected_str, true ) ? ' selected' : '' ) . '>'
					. esc_html( $term->name ) . '</option>';
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
		check_admin_referer( self::ACTION_SAVE );

		$posted = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$rules = array();
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label  = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$amount = sanitize_text_field( (string) ( $row['amount'] ?? '0' ) );
			$tiers  = sanitize_textarea_field( (string) ( $row['tiers'] ?? '' ) );

			// A row the merchant added and then left completely blank is not a
			// catch-all charging nothing — it is a row they never filled in.
			if ( $label === '' && $tiers === '' && (float) str_replace( ',', '.', $amount ) <= 0 ) {
				continue;
			}

			$rules[] = array(
				'label'      => $label,
				'amount'     => $amount,
				'tiers'      => $tiers,
				'categories' => self::clean_term_ids( $row['categories'] ?? array() ),
				'tags'       => self::clean_term_ids( $row['tags'] ?? array() ),
				'methods'    => array_values( array_filter(
					array_map( 'sanitize_text_field', (array) ( $row['methods'] ?? array() ) ),
					array( self::class, 'is_valid_method_key' )
				) ),
				'pre_order_only' => ! empty( $row['pre_order_only'] ),
				'enabled'    => ! empty( $row['enabled'] ),
			);
		}

		update_option(
			self::OPTION_KEY,
			array(
				'enabled'   => ! empty( $_POST['enabled'] ), // phpcs:ignore WordPress.Security.NonceVerification
				'ex_tax'    => ! empty( $_POST['ex_tax'] ), // phpcs:ignore WordPress.Security.NonceVerification
				'tax_class' => sanitize_text_field( (string) wp_unslash( $_POST['tax_class'] ?? 'standard' ) ), // phpcs:ignore WordPress.Security.NonceVerification
				'rules'     => $rules,
			)
		);

		self::purge_config_cache();

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => O_TEXTDOMAIN, 'oko_fee_saved' => '1' ),
				admin_url( 'admin.php' )
			) . '#okoskabet-packaging-fee'
		);
		exit;
	}
}
