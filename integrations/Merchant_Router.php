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
 * Decides which Økoskabet merchant a given cart/order/product is routed to.
 *
 * The routing pipeline for a single product, in order of decreasing
 * specificity:
 *
 *   1. Per-product override via post meta `_okoskabet_merchant`. If the
 *      value matches a configured merchant ID, that merchant is used.
 *      Any other value (empty string, "auto", unknown ID) falls through.
 *
 *   2. Per-merchant routing rules — the product's categories and tags
 *      are matched against every merchant's `product_categories` and
 *      `product_tags` lists. If multiple merchants match, the one with
 *      the highest `priority` wins. Ties broken alphabetically by ID for
 *      a fully deterministic answer.
 *
 *   3. Default merchant.
 *
 * Cart-level decision:
 *
 *   Non-default merchants only handle "clean" carts where EVERY item
 *   resolves to the same merchant. The moment a cart contains items
 *   from two or more different merchants — for any reason — the entire
 *   cart falls back to the default merchant. Mixed carts are never
 *   blocked and never split across merchants; the default merchant
 *   handles them.
 *
 *   This keeps the customer experience simple (one cart = one order =
 *   one fulfilment partner) and makes additional merchants an opt-in
 *   feature that only kicks in when a customer is shopping wholly
 *   within that merchant's product range.
 */
class Merchant_Router extends Base {

	/** Product meta key holding the per-product merchant override. */
	const PRODUCT_META_KEY = '_okoskabet_merchant';

	/** Order meta key recording which merchant fulfilled the order. */
	const ORDER_META_KEY = '_okoskabet_merchant_id';

	public function initialize() {
		parent::initialize();
		// Pure resolver class — no WordPress hooks needed. Callers invoke
		// the static methods directly.
	}

	// =========================================================================
	// Public API
	// =========================================================================

	/**
	 * Resolve the merchant for a single product.
	 *
	 * @param int $product_id
	 * @return array{merchant_id:string, merchant:?array, source:string}
	 */
	public static function resolve_for_product( int $product_id ): array {
		$merchants = Merchants::get_all();
		if ( empty( $merchants ) ) {
			return array(
				'merchant_id' => '',
				'merchant'    => null,
				'source'      => 'no_merchants_configured',
			);
		}

		// 1. Per-product override.
		$override = self::sanitize_id( get_post_meta( $product_id, self::PRODUCT_META_KEY, true ) );
		if ( $override !== '' && isset( $merchants[ $override ] ) ) {
			return array(
				'merchant_id' => $override,
				'merchant'    => $merchants[ $override ],
				'source'      => 'product_override',
			);
		}

		// 2. Category/tag rules — choose the highest-priority merchant
		//    whose lists intersect with this product's terms.
		$cat_ids = self::product_term_ids( $product_id, 'product_cat' );
		$tag_ids = self::product_term_ids( $product_id, 'product_tag' );

		$matching = array();
		foreach ( $merchants as $merchant ) {
			if ( self::merchant_matches_terms( $merchant, $cat_ids, $tag_ids ) ) {
				$matching[] = $merchant;
			}
		}

		if ( ! empty( $matching ) ) {
			usort( $matching, array( __CLASS__, 'compare_priority' ) );
			$winner = $matching[0];
			return array(
				'merchant_id' => $winner['id'],
				'merchant'    => $winner,
				'source'      => 'category_or_tag_rule',
			);
		}

		// 3. Default merchant.
		$default_id = Merchants::default_id();
		return array(
			'merchant_id' => $default_id,
			'merchant'    => $merchants[ $default_id ] ?? null,
			'source'      => 'default',
		);
	}

	/**
	 * Resolve the merchant for a whole cart, given the product IDs in it.
	 *
	 * The rule: every product must resolve to the SAME merchant for that
	 * merchant to win. Otherwise the cart falls back to the default
	 * merchant. Empty carts also fall back to the default — there's no
	 * cart-specific routing signal to act on.
	 *
	 * @param int[] $product_ids
	 * @return array{
	 *   merchant_id: string,                 // the merchant that will handle this cart
	 *   merchant: ?array,                    // full merchant record, or null pre-migration
	 *   per_product: array<int,string>,      // per-product routing decision (informational)
	 *   merchant_ids: array<int,string>,     // unique list of merchants the products would have matched individually
	 *   is_mixed: bool,                      // true if the cart had items from >1 merchant
	 *   fell_back_to_default: bool,          // true if `is_mixed` AND we therefore routed to default
	 *   missing_default: bool                // true if no default merchant is configured (very early in setup)
	 * }
	 */
	public static function resolve_for_products( array $product_ids ): array {
		$product_ids = self::sanitize_product_id_list( $product_ids );

		$per_product = array();
		foreach ( $product_ids as $pid ) {
			$res = self::resolve_for_product( $pid );
			if ( $res['merchant_id'] === '' ) {
				continue;
			}
			$per_product[ $pid ] = $res['merchant_id'];
		}

		$merchant_ids = array_values( array_unique( array_values( $per_product ) ) );
		$is_mixed     = count( $merchant_ids ) > 1;
		$default      = Merchants::get_default();

		// Decision:
		//   - empty cart / per_product never populated → default merchant
		//   - exactly one distinct merchant across the whole cart → use it
		//   - more than one distinct merchant → fall back to default
		if ( count( $merchant_ids ) === 1 ) {
			$only_id  = $merchant_ids[0];
			$resolved = Merchants::get( $only_id );
			if ( ! $resolved ) {
				// Shouldn't happen — sanitize_id matches against the
				// registry — but guard against any race anyway.
				$resolved = $default;
			}
		} else {
			$resolved = $default;
		}

		return array(
			'merchant_id'          => $resolved['id'] ?? '',
			'merchant'             => $resolved,
			'per_product'          => $per_product,
			'merchant_ids'         => $merchant_ids,
			'is_mixed'             => $is_mixed,
			'fell_back_to_default' => $is_mixed,
			'missing_default'      => ( $default === null ),
		);
	}

	/**
	 * Resolve the merchant that fulfilled an existing order.
	 *
	 * Strategy:
	 *   1. Order meta `_okoskabet_merchant_id` if present and valid.
	 *   2. Resolve from the order's line items.
	 *
	 * @param \WC_Order $order
	 * @return array{merchant_id:string, merchant:?array}
	 */
	public static function resolve_for_order( \WC_Order $order ): array {
		$stored = self::sanitize_id( (string) $order->get_meta( self::ORDER_META_KEY, true ) );
		if ( $stored !== '' && Merchants::get( $stored ) !== null ) {
			return array(
				'merchant_id' => $stored,
				'merchant'    => Merchants::get( $stored ),
			);
		}

		$product_ids = array();
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product ) {
				$pid = (int) $item->get_product_id();
				if ( $pid > 0 ) {
					$product_ids[] = $pid;
				}
			}
		}
		$resolved = self::resolve_for_products( $product_ids );

		return array(
			'merchant_id' => $resolved['merchant_id'],
			'merchant'    => $resolved['merchant'],
		);
	}

	/**
	 * Resolve the merchant the live WooCommerce cart should be routed to.
	 *
	 * @return array same shape as `resolve_for_products`
	 */
	public static function resolve_for_cart(): array {
		$product_ids = array();
		if ( function_exists( 'WC' ) && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				$pid = (int) ( $cart_item['product_id'] ?? 0 );
				if ( $pid > 0 ) {
					$product_ids[] = $pid;
				}
			}
		}
		return self::resolve_for_products( $product_ids );
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Static per-request cache for term lookups so a cart with many items
	 * doesn't pound the DB.
	 *
	 * @var array<int, array<string, int[]>>
	 */
	private static $term_cache = array();

	private static function product_term_ids( int $product_id, string $taxonomy ): array {
		if ( isset( self::$term_cache[ $product_id ][ $taxonomy ] ) ) {
			return self::$term_cache[ $product_id ][ $taxonomy ];
		}
		$terms = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		$ids = array_map( 'intval', (array) $terms );
		self::$term_cache[ $product_id ][ $taxonomy ] = $ids;
		return $ids;
	}

	private static function merchant_matches_terms( array $merchant, array $cat_ids, array $tag_ids ): bool {
		$m_cats = array_map( 'intval', (array) ( $merchant['product_categories'] ?? array() ) );
		$m_tags = array_map( 'intval', (array) ( $merchant['product_tags'] ?? array() ) );

		if ( ! empty( $m_cats ) && array_intersect( $m_cats, $cat_ids ) ) {
			return true;
		}
		if ( ! empty( $m_tags ) && array_intersect( $m_tags, $tag_ids ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Sort by (priority DESC, id ASC) so the resolver is fully
	 * deterministic — two equal priorities always pick the same winner.
	 */
	private static function compare_priority( array $a, array $b ): int {
		$pa = (int) ( $a['priority'] ?? 0 );
		$pb = (int) ( $b['priority'] ?? 0 );
		if ( $pa !== $pb ) {
			return $pb <=> $pa;
		}
		return strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) );
	}

	/**
	 * Sanitise a merchant-ID string. Returns '' for anything that doesn't
	 * look like a slug.
	 */
	public static function sanitize_id( string $id ): string {
		$id = sanitize_key( $id );
		if ( $id === 'auto' ) {
			return '';
		}
		return $id;
	}

	private static function sanitize_product_id_list( array $list ): array {
		$out = array();
		foreach ( $list as $v ) {
			$n = (int) $v;
			if ( $n > 0 ) {
				$out[] = $n;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
