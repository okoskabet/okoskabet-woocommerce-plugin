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
 * Per-product "Økoskabet merchant" override.
 *
 * Adds a panel to the WooCommerce product edit screen letting the shop
 * manager force a specific Økoskabet merchant for that product. When set,
 * the override beats category/tag routing rules — useful for one-off
 * "this item ships from our express partner" overrides.
 *
 * The post meta key (`_okoskabet_merchant`) is exposed publicly via
 * `Merchant_Router::PRODUCT_META_KEY` so other integrations (and the
 * router itself) can read it without coupling to this UI module.
 *
 * The value is sanitised on save — only existing merchant IDs are stored,
 * everything else is reduced to '' (auto-detect). This means renaming or
 * deleting a merchant cannot leave dangling references that would silently
 * route to a non-existent merchant.
 */
class Product_Merchant_Meta extends Base {

	const NONCE_FIELD = '_okoskabet_product_merchant_nonce';
	const NONCE_ACTION = 'okoskabet_save_product_merchant';

	public function initialize() {
		parent::initialize();

		add_action( 'add_meta_boxes', array( $this, 'register_metabox' ) );
		add_action( 'save_post_product', array( $this, 'save_metabox' ), 10, 2 );
	}

	public function register_metabox(): void {
		// Only show this UI to users who can edit shop products AND who
		// have at least one configured merchant. If there are no
		// merchants (fresh install, no migration yet), the dropdown
		// would have nothing to choose from anyway.
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		if ( ! Merchants::has_any() ) {
			return;
		}

		add_meta_box(
			'okoskabet-product-merchant',
			__( 'Økoskabet routing', O_TEXTDOMAIN ),
			array( $this, 'render_metabox' ),
			'product',
			'side',
			'default'
		);
	}

	public function render_metabox( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$current   = sanitize_key( (string) get_post_meta( $post->ID, Merchant_Router::PRODUCT_META_KEY, true ) );
		$merchants = Merchants::get_all();
		$resolved  = Merchant_Router::resolve_for_product( $post->ID );
		?>
		<p>
			<label for="okoskabet-product-merchant"><strong><?php esc_html_e( 'Use Økoskabet merchant', O_TEXTDOMAIN ); ?></strong></label>
			<select id="okoskabet-product-merchant" name="okoskabet_product_merchant" style="width:100%;">
				<option value="" <?php selected( $current, '' ); ?>><?php esc_html_e( 'Auto (use category/tag rules)', O_TEXTDOMAIN ); ?></option>
				<?php foreach ( $merchants as $m ) : ?>
					<option value="<?php echo esc_attr( $m['id'] ); ?>" <?php selected( $current, $m['id'] ); ?>>
						<?php echo esc_html( $m['label'] !== '' ? $m['label'] : $m['id'] ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<?php if ( $resolved['merchant_id'] !== '' ) :
			$label = $resolved['merchant']['label'] ?? $resolved['merchant_id'];
			$source = $resolved['source'];
			$source_labels = array(
				'product_override'     => __( 'because of this product\'s override', O_TEXTDOMAIN ),
				'category_or_tag_rule' => __( 'because of this product\'s category/tag', O_TEXTDOMAIN ),
				'default'              => __( 'as the fallback default merchant', O_TEXTDOMAIN ),
			);
			?>
			<p style="margin:0;color:#666;font-size:0.9em;">
				<?php
				echo esc_html( sprintf(
					/* translators: 1 = merchant label, 2 = explanation */
					__( 'Currently routed to %1$s %2$s.', O_TEXTDOMAIN ),
					$label,
					$source_labels[ $source ] ?? ''
				) );
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	public function save_metabox( int $post_id, \WP_Post $post ): void {
		// Standard save_post safety: avoid running on autosaves and
		// revisions, and require capability + nonce.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( $post->post_type !== 'product' ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return;
		}
		if ( ! isset( $_POST['okoskabet_product_merchant'] ) ) {
			return;
		}

		$raw      = sanitize_key( wp_unslash( $_POST['okoskabet_product_merchant'] ) );
		$existing = Merchants::get_all();

		// Only persist known merchant IDs. Unknown / empty / "auto"
		// collapses to a deleted meta — the router falls back to
		// category/tag/default behaviour.
		if ( $raw !== '' && isset( $existing[ $raw ] ) ) {
			update_post_meta( $post_id, Merchant_Router::PRODUCT_META_KEY, $raw );
		} else {
			delete_post_meta( $post_id, Merchant_Router::PRODUCT_META_KEY );
		}
	}
}
