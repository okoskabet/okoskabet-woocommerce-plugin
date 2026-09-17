<?php
/**
 * Økoskabet WooCommerce Plugin — Split Checkout integration.
 *
 * When a cart contains products whose delivery rules are mutually
 * incompatible (so no single delivery date works for all items), we
 * split checkout into N sequential orders — one per delivery date.
 *
 * Flow:
 *   1. On checkout page load, detect if a split is needed.
 *   2. If needed, render a banner above the checkout form listing the
 *      delivery groups and offering the customer two ways out:
 *        - "Opdel levering i to" — one order per delivery day (this class);
 *        - "Tøm fra kurven" — take the few items out of the basket that
 *          stand in the way, and everything else goes out together.
 *      Both button labels are the shop's to edit in the settings.
 *   3. The PHP `woocommerce_checkout_process` hook blocks order
 *      submission until either (a) no split is needed, or (b) the
 *      customer picked one of the two and the cart now holds only the
 *      items for the current step.
 *   4. When the customer picks the split, an AJAX endpoint stores the
 *      remaining groups in the WC session and replaces the cart with only
 *      the first group's items. When they pick the other button, the named
 *      items leave the cart and checkout carries on as usual.
 *   5. On the WooCommerce thank-you page, if there are pending groups
 *      in session, we show a "Order next delivery" banner. Clicking it
 *      restores the next group's items to the cart and redirects back
 *      to checkout.
 *   6. Each order is fully independent — no parent/child link, no
 *      payment coordination, no completion rollup. Each order
 *      gets a `_oko_split_token` post_meta (UUID v4) and a
 *      `_oko_split_step` (1, 2, 3...) so an admin can reconstruct
 *      the relationship by querying.
 *
 *      Independent also means each order pays its own shipping and its own
 *      packaging fee. That is intended, not an oversight: two deliveries are
 *      two vans and two boxes, and the second order is an ordinary
 *      WooCommerce order whose cart computes both from scratch. Nothing here
 *      suppresses the second charge, and nothing should start to.
 *
 * Out of scope for the MVP — see CHANGELOG.md and ROADMAP.md:
 *   - Email reminder if customer abandons mid-flow
 *   - Admin UI showing "this order is part of a split"
 *   - Custom emails that mention the split
 *   - Stock-rollback if step N fails after step N-1 succeeded
 *
 * @package okoskabet_woocommerce_plugin
 * @since   1.3.0
 */

namespace okoskabet_woocommerce_plugin\Integrations;

use okoskabet_woocommerce_plugin\Engine\Base;

class Split_Checkout extends Base {

	/** WC session key holding the active split state. */
	const SESSION_KEY = 'oko_split_state';

	/** Hidden checkbox field name on checkout form. */
	const ACK_FIELD = 'oko_split_acknowledged';

	/** Post meta keys on each split order. */
	const META_TOKEN = '_oko_split_token';
	const META_STEP  = '_oko_split_step';
	const META_TOTAL = '_oko_split_total_steps';
	const META_MODE  = '_oko_split_mode';

	/**
	 * The two kinds of order a group can be. A split can now run across them —
	 * a pre-order for what can be held until December, an ordinary delivery for
	 * the rest of the basket this week — and each part keeps its own kind, its
	 * own date and its own fee.
	 */
	const MODE_PRE_ORDER = 'pre_order';
	const MODE_NORMAL    = 'normal';

	public function initialize() {
		parent::initialize();

		if ( ! $this->is_feature_enabled() ) {
			return;
		}

		// Render the conflict banner on checkout.
		add_action( 'woocommerce_before_checkout_form', array( $this, 'maybe_render_banner' ), 5 );

		// Block submission until acknowledged + cart is reduced to current step.
		add_action( 'woocommerce_checkout_process', array( $this, 'maybe_block_submission' ) );

		// Tag the order with split-token meta when it's the active step.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'tag_order_with_split_meta' ), 10, 2 );

		// Land each step in the kind of order it is. Priority 20 so it runs
		// after oko_checkout_starts_without_last_orders_choice() has blanked
		// the field, which is the right default everywhere but mid-split.
		add_filter( 'woocommerce_checkout_get_value', array( $this, 'checkout_value_for_step' ), 20, 2 );

		// AJAX endpoints to start, resume, and cancel a split.
		add_action( 'wp_ajax_oko_start_split',        array( $this, 'ajax_start_split' ) );
		add_action( 'wp_ajax_nopriv_oko_start_split', array( $this, 'ajax_start_split' ) );
		add_action( 'wp_ajax_oko_resume_split',        array( $this, 'ajax_resume_split' ) );
		add_action( 'wp_ajax_nopriv_oko_resume_split', array( $this, 'ajax_resume_split' ) );
		add_action( 'wp_ajax_oko_cancel_split',        array( $this, 'ajax_cancel_split' ) );
		add_action( 'wp_ajax_nopriv_oko_cancel_split', array( $this, 'ajax_cancel_split' ) );
		add_action( 'wp_ajax_oko_reduce_split',        array( $this, 'ajax_reduce_split' ) );
		add_action( 'wp_ajax_nopriv_oko_reduce_split', array( $this, 'ajax_reduce_split' ) );

		// Show "next delivery" banner ABOVE the order details on thank-you
		// page. We hook woocommerce_before_thankyou which fires before WC's
		// own "Thank you, your order has been received" headline + order
		// summary table — so the customer sees our banner first and is not
		// distracted by order details for the order they just placed when
		// they still have another to book.
		add_action( 'woocommerce_before_thankyou', array( $this, 'maybe_render_thankyou_banner' ), 5 );

		// Advance state once an order from the current step is created.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_processed' ), 10, 1 );
	}

	// ---------------------------------------------------------------------
	// Detection
	// ---------------------------------------------------------------------

	/**
	 * Is the split-checkout feature enabled in the plugin settings?
	 *
	 * Default is OFF. Merchants must explicitly opt in. When OFF, this
	 * class does not register any hooks and the legacy
	 * `Delivery_Exceptions` overlay is what the customer sees instead.
	 *
	 * Exposed as static so the Delivery_Exceptions filter can also check
	 * it (and skip the explanation overlay when the split-banner will
	 * cover the same UX).
	 */
	public static function is_feature_enabled(): bool {
		$settings = function_exists( 'o_get_settings' ) ? o_get_settings() : array();
		if ( ! is_array( $settings ) ) { return false; }
		$flag = $settings['_split_checkout_enabled'] ?? '';
		return $flag === 'on';
	}

	/**
	 * The days Økoskabet will deliver a single product to this customer.
	 *
	 * Økoskabet decides which days exist for an address; the merchant's
	 * exception rules only ever narrow that list. Asking the delivery-day
	 * endpoint — the same one the date picker asks, through the same function —
	 * is therefore the only way to get a date this class may put in front of a
	 * customer.
	 *
	 * An earlier version built its own 365-day calendar and ran the exception
	 * rules over that. Every date it produced was a guess. On Gaardmester's
	 * staging it offered "Levering 1 (17. september)" — the day the page
	 * happened to be loaded, and not a day the shop drives on at all — because
	 * no rule happened to forbid it. The rules say which of the shop's days a
	 * product may use; they cannot conjure a day the shop does not deliver on,
	 * and neither may we.
	 *
	 * Null when the question cannot be answered at all, which is not the same
	 * as "no days" — see oko_home_delivery_dates().
	 *
	 * Protected so a test can stand in for Økoskabet.
	 *
	 * @return string[]|null Sorted Y-m-d dates, or null when unanswerable.
	 */
	protected function delivery_days_for_product( int $product_id, bool $pre_order = false ): ?array {
		if ( ! function_exists( 'oko_home_delivery_dates' ) ) {
			return null;
		}

		$postcode = $this->customer_postcode();
		if ( $postcode === '' ) {
			return null;
		}

		// One question per product per mode per request. The banner, the removal
		// options and the submission guard all ask the same thing during a
		// single checkout render, and every miss is a round trip to Økoskabet.
		static $cache = array();
		$key = $postcode . '|' . $product_id . '|' . ( $pre_order ? 'pre' : 'normal' );
		if ( ! array_key_exists( $key, $cache ) ) {
			$cache[ $key ] = \oko_home_delivery_dates( $postcode, array( $product_id ), $pre_order );
		}

		return $cache[ $key ];
	}

	/**
	 * Is the customer looking at pre-order days rather than ordinary ones?
	 *
	 * While a split is running, the step decides: step one may be the pre-order
	 * and step two the ordinary delivery, and each has to render as the kind of
	 * order it is regardless of which button the customer last pressed.
	 */
	private function is_pre_order_mode(): bool {
		$state = $this->get_state();
		if ( ! empty( $state['split_token'] ) ) {
			$mode = $state['groups'][ (int) ( $state['current_step'] ?? 0 ) - 1 ]['mode'] ?? null;
			if ( $mode !== null ) {
				return $mode === self::MODE_PRE_ORDER;
			}
		}

		return function_exists( 'oko_pre_order_checkout_requested' ) && \oko_pre_order_checkout_requested();
	}

	/** Where the customer is having this delivered, as far as we know yet. */
	private function customer_postcode(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->customer ) {
			return '';
		}
		$postcode = trim( (string) WC()->customer->get_shipping_postcode() );
		if ( $postcode === '' ) {
			$postcode = trim( (string) WC()->customer->get_billing_postcode() );
		}

		return $postcode;
	}

	/**
	 * Every cart line with the kind of order it would have to travel as, and
	 * the days available to it that way.
	 *
	 * In an ordinary checkout that is simply each line's delivery days. In a
	 * pre-order it is the more interesting question, and the one the checkout
	 * used to duck: a customer who presses Forudbestilling means the whole
	 * basket, but only some goods can be pre-ordered. Rather than leaving them
	 * with no dates and no explanation — which is what a Gaardmester basket of
	 * cornflakes, Pak Choi and nougat ispinde got, plus a 50 kr fee — we work
	 * out which lines can be pre-ordered and put the rest on their ordinary
	 * days. The split then runs across the two kinds of order, not just across
	 * two dates.
	 *
	 * Empty when there is nothing to work out, and empty too when we cannot
	 * find out: with no answer from Økoskabet there is no honest banner to
	 * draw, so we draw none and leave the date picker to tell the customer
	 * what it finds. A banner full of invented dates is worse than no banner.
	 *
	 * @return array<string, array{mode:string, dates:string[]}> keyed by cart item key
	 */
	private function lines_with_modes(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return array();
		}

		// With no delivery rules configured at all, every product can go on
		// every day Økoskabet offers, so the cart never needs splitting. Saying
		// so up front keeps the cost of this feature at zero for the shops that
		// have not switched any rule on — which is most of them, and who would
		// otherwise pay for a round trip per product on every checkout render.
		if ( ! \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::is_in_use() ) {
			return array();
		}

		$pre_order_mode = $this->is_pre_order_mode();
		$out            = array();

		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}

			if ( $pre_order_mode ) {
				$pre_days = $this->delivery_days_for_product( $pid, true );
				if ( $pre_days === null ) {
					return array();
				}
				if ( ! empty( $pre_days ) ) {
					$out[ $key ] = array( 'mode' => self::MODE_PRE_ORDER, 'dates' => $pre_days );
					continue;
				}
				// Nothing to pre-order here, so this line travels the ordinary
				// way — which is the whole reason the basket needs splitting.
			}

			$days = $this->delivery_days_for_product( $pid, false );
			if ( $days === null ) {
				return array();
			}
			$out[ $key ] = array( 'mode' => self::MODE_NORMAL, 'dates' => $days );
		}

		return $out;
	}

	/**
	 * Split the current cart into as few delivery days as it can be delivered in.
	 *
	 * Always returns what the cart needs, even when that is a single group —
	 * callers that only care about conflicts use compute_split_groups().
	 *
	 * The grouping is greedy by coverage: take the date that can carry the most
	 * of the remaining lines, give that date those lines, repeat. Ties go to the
	 * earlier date, so the answer is the same on every render — important,
	 * because the customer is shown these groups and then clicks a button that
	 * recomputes them.
	 *
	 * The obvious alternative — walk the lines and drop each into the first
	 * group it fits — is what this replaces. It gave different answers depending
	 * on cart order and routinely reported three deliveries where two would do,
	 * because a line that could have bridged two groups had already been spent
	 * on the first one it touched.
	 *
	 * Lines that have NO deliverable date at all (a from/until window that has
	 * closed, say) cannot be helped by any split. They are gathered into one
	 * final group with an empty date, which is what hides the split button:
	 * there is no day to book that group on. The "remove these" options still
	 * list them, which is the way out.
	 *
	 * Each group has shape:
	 *   [
	 *     'keys'           => [ <cart_item_key>, ... ],
	 *     'items'          => [ <cart_item_key> => <full cart_item array>, ... ],
	 *     'product_ids'    => [ id, id, ... ],
	 *     'product_names'  => [ 'Mælk', 'Brød', ... ],
	 *     'suggested_date' => 'YYYY-MM-DD',  // '' when nothing can carry it
	 *     'possible_dates' => [ 'YYYY-MM-DD', ... ],  // every day that would do
	 *     'mode'           => 'pre_order' | 'normal' | '',
	 *   ]
	 *
	 * @return array<int, array>
	 */
	public function compute_delivery_groups(): array {
		$lines = $this->lines_with_modes();
		if ( empty( $lines ) ) {
			return array();
		}

		// A pre-order and an ordinary delivery are different kinds of order, so
		// they are grouped apart even when they could fall on the same day. One
		// order cannot be half pre-ordered.
		$undeliverable = array();
		$by_mode       = array();
		foreach ( $lines as $key => $line ) {
			if ( empty( $line['dates'] ) ) {
				$undeliverable[] = $key;
				continue;
			}
			$by_mode[ $line['mode'] ][ $key ] = $line['dates'];
		}

		$groups = array();
		foreach ( $by_mode as $mode => $remaining ) {
			foreach ( $this->group_by_coverage( $remaining ) as $group ) {
				$groups[] = array(
					'keys'  => $group['keys'],
					'date'  => $group['date'],
					'dates' => $group['dates'],
					'mode'  => (string) $mode,
				);
			}
		}

		// Chronological, so the customer reads them in the order they happen.
		usort( $groups, function ( $a, $b ) {
			return strcmp( (string) $a['date'], (string) $b['date'] );
		} );

		if ( ! empty( $undeliverable ) ) {
			$groups[] = array( 'keys' => $undeliverable, 'date' => '', 'dates' => array(), 'mode' => '' );
		}

		return array_map( function ( array $group ): array {
			return $this->decorate_group( $group['keys'], (string) $group['date'], (string) $group['mode'], (array) $group['dates'] );
		}, $groups );
	}

	/**
	 * Pack lines into as few days as they will go: take the day that carries
	 * the most of what is left, give it those lines, repeat.
	 *
	 * Ties go to the earlier day, so the answer is the same on every render —
	 * which matters, because the customer is shown these groups and then
	 * presses a button that works them out again.
	 *
	 * @param  array<string, string[]> $remaining cart item key => its days
	 * @return array<int, array{keys:string[], date:string}>
	 */
	private function group_by_coverage( array $remaining ): array {
		$groups = array();

		while ( ! empty( $remaining ) ) {
			// How many of the remaining lines each candidate date can carry.
			$coverage = array();
			foreach ( $remaining as $dates ) {
				foreach ( $dates as $date ) {
					$coverage[ $date ] = ( $coverage[ $date ] ?? 0 ) + 1;
				}
			}

			// Best date: most lines carried, earliest date breaking the tie.
			// ksort first so the tie-break falls out of the walk order.
			ksort( $coverage );
			$best      = '';
			$best_hits = 0;
			foreach ( $coverage as $date => $hits ) {
				if ( $hits > $best_hits ) {
					$best      = (string) $date;
					$best_hits = $hits;
				}
			}

			$keys   = array();
			$shared = null;
			foreach ( $remaining as $key => $dates ) {
				if ( in_array( $best, $dates, true ) ) {
					$keys[] = $key;
					// Every day this whole group could go out on, not only the
					// one we picked. What the customer may be told about the
					// group depends on how many there turn out to be.
					$shared = $shared === null ? $dates : array_values( array_intersect( $shared, $dates ) );
					unset( $remaining[ $key ] );
				}
			}

			$groups[] = array( 'keys' => $keys, 'date' => $best, 'dates' => (array) $shared );
		}

		return $groups;
	}

	/**
	 * Attach the product names and ids a group needs to be shown and re-added.
	 *
	 * @param string[] $keys Cart item keys.
	 * @param string   $date Y-m-d, or '' when the group has no deliverable day.
	 * @param string   $mode Which kind of order this group would be.
	 * @param string[] $dates Every day the whole group could go out on.
	 */
	private function decorate_group( array $keys, string $date, string $mode = self::MODE_NORMAL, array $dates = array() ): array {
		$cart_items    = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart() : array();
		$items_full    = array();
		$product_ids   = array();
		$product_names = array();

		foreach ( $keys as $key ) {
			if ( ! isset( $cart_items[ $key ] ) ) {
				continue;
			}
			$items_full[ $key ] = $cart_items[ $key ];
			$pid                = (int) ( $cart_items[ $key ]['product_id'] ?? 0 );
			if ( $pid > 0 ) {
				$product_ids[] = $pid;
				$prod          = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
				if ( $prod ) {
					$product_names[] = $prod->get_name();
				}
			}
		}

		return array(
			'keys'           => array_values( $keys ),
			'items'          => $items_full,
			'product_ids'    => array_values( array_unique( $product_ids ) ),
			'product_names'  => array_values( array_unique( $product_names ) ),
			'suggested_date' => $date,
			'possible_dates' => array_values( $dates ),
			'mode'           => $mode,
		);
	}

	/**
	 * The delivery groups when — and only when — the cart needs more than one.
	 *
	 * Empty array means one delivery day covers everything, which is the normal
	 * case and the one where this whole feature stays out of the way.
	 *
	 * @return array<int, array>
	 */
	public function compute_split_groups(): array {
		$groups = $this->compute_delivery_groups();

		return count( $groups ) < 2 ? array() : $groups;
	}

	/**
	 * Can every group the cart needs actually be booked?
	 *
	 * False when a line has no deliverable day at all — splitting would then
	 * produce an order the customer cannot pick a date for, so we don't offer it.
	 *
	 * @param array<int, array> $groups
	 */
	/**
	 * Does this split cross the two kinds of order — part pre-order, part
	 * ordinary delivery? That is a different thing to explain than a basket
	 * that simply needs two days.
	 *
	 * @param array<int, array> $groups
	 */
	private function groups_mix_modes( array $groups ): bool {
		$modes = array();
		foreach ( $groups as $group ) {
			$mode = (string) ( $group['mode'] ?? '' );
			if ( $mode !== '' ) {
				$modes[ $mode ] = true;
			}
		}

		return count( $modes ) > 1;
	}

	private function groups_are_bookable( array $groups ): bool {
		foreach ( $groups as $group ) {
			if ( (string) ( $group['suggested_date'] ?? '' ) === '' ) {
				return false;
			}
		}

		return ! empty( $groups );
	}

	// ---------------------------------------------------------------------
	// "Remove these and the rest travels together"
	// ---------------------------------------------------------------------

	/**
	 * The ways to get the whole remaining cart onto ONE delivery day.
	 *
	 * One option per delivery day the cart's groups point at. For each of those
	 * days we ask the *whole* cart — not just that day's group — which lines can
	 * make it, because a line that the grouping already spent on an earlier day
	 * may well be deliverable on this one too, and asking again keeps the
	 * "remove" list as short as it honestly can be.
	 *
	 * Options are sorted by how much they cost the customer: fewest items
	 * removed first, then the earliest delivery. No two of them can ask for the
	 * same items: each day keeps at least its own group, the groups share no
	 * lines, so the lists differ by construction.
	 *
	 * Each option has shape:
	 *   [
	 *     'date'          => 'YYYY-MM-DD',
	 *     'date_label'    => 'den 22. september 2026',
	 *     'remove_keys'   => [ <cart_item_key>, ... ],
	 *     'remove_names'  => [ 'Mælk', ... ],
	 *     'keep_names'    => [ 'Brød', ... ],
	 *     'mode'          => 'pre_order' | 'normal',
	 *     'text'          => 'Fjern Mælk, så kan resten leveres sammen den …',
	 *   ]
	 *
	 * Only days of the kind of order the customer is actually placing are
	 * offered. In a pre-order that means the pre-order days alone: the customer
	 * pressed Forudbestilling, and "remove these two and the rest can be
	 * delivered on Wednesday" would quietly take them back out of it. The way
	 * back to an ordinary order is the ordinary-order button, not a line in
	 * this list.
	 *
	 * @return array<int, array>
	 */
	public function compute_removal_options(): array {
		$lines = $this->lines_with_modes();
		if ( empty( $lines ) ) {
			return array();
		}

		$wanted_mode = $this->is_pre_order_mode() ? self::MODE_PRE_ORDER : self::MODE_NORMAL;

		$candidate_dates = array();
		foreach ( $this->compute_delivery_groups() as $group ) {
			$date = (string) ( $group['suggested_date'] ?? '' );
			// A day belonging to the other kind of order needs no filtering out
			// here: a line only counts as kept below if it travels as the kind
			// the customer chose, so such a day keeps nothing and falls out as
			// an empty offer. Two guards for one rule is how the two drift.
			if ( $date !== '' ) {
				$candidate_dates[ $date ] = true;
			}
		}

		$options = array();
		foreach ( array_keys( $candidate_dates ) as $date ) {
			$keep   = array();
			$remove = array();
			foreach ( $lines as $key => $line ) {
				// A line only counts as kept if it can travel on this day AS
				// this kind of order. In a pre-order, a line that has ordinary
				// days but no pre-order day is precisely what has to go.
				if ( $line['mode'] === $wanted_mode && in_array( $date, $line['dates'], true ) ) {
					$keep[] = $key;
				} else {
					$remove[] = $key;
				}
			}

			// Nothing to remove means this day already carries the cart, and
			// nothing to keep means the option empties the basket. Neither is
			// an offer worth making.
			if ( empty( $remove ) || empty( $keep ) ) {
				continue;
			}

			// Every day the kept items could go out on together, so the offer
			// can promise a day only where there is exactly one to promise.
			$shared = null;
			foreach ( $keep as $key ) {
				$shared = $shared === null
					? $lines[ $key ]['dates']
					: array_values( array_intersect( $shared, $lines[ $key ]['dates'] ) );
			}

			$options[] = array(
				'date'           => (string) $date,
				'date_label'     => \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::format_date_human( (string) $date ),
				'possible_dates' => array_values( (array) $shared ),
				'mode'           => $wanted_mode,
				'remove_keys'    => $remove,
				'remove_names'   => $this->names_for_keys( $remove ),
				'keep_names'     => $this->names_for_keys( $keep ),
			);
		}

		// Cheapest first: fewest items given up, then the soonest delivery.
		usort( $options, function ( $a, $b ) {
			$by_cost = count( $a['remove_keys'] ) <=> count( $b['remove_keys'] );
			return $by_cost !== 0 ? $by_cost : strcmp( $a['date'], $b['date'] );
		} );

		foreach ( $options as $i => $option ) {
			$names     = self::format_name_list( $option['remove_names'] );
			$pre_order = $option['mode'] === self::MODE_PRE_ORDER;
			// What the offer can honestly promise is that the rest travels
			// together. Naming a day on top of that is only true when the kept
			// items have exactly one day left between them; otherwise the day
			// printed was the soonest of several and the customer still chooses.
			$one_day = count( $option['possible_dates'] ) === 1;

			if ( ! $one_day ) {
				$options[ $i ]['text'] = $pre_order
					/* translators: %s = product names ("Mælk og Brød") */
					? sprintf( __( 'Remove %s, and the rest can be pre-ordered together', O_TEXTDOMAIN ), $names )
					/* translators: %s = product names ("Mælk og Brød") */
					: sprintf( __( 'Remove %s, and the rest can be delivered together', O_TEXTDOMAIN ), $names );
				continue;
			}

			$options[ $i ]['text'] = $pre_order
				? sprintf(
					/* translators: 1 = product names ("Mælk og Brød"), 2 = date ("den 10. december 2026") */
					__( 'Remove %1$s, and the rest can be pre-ordered together for %2$s', O_TEXTDOMAIN ),
					$names,
					$option['date_label']
				)
				: sprintf(
					/* translators: 1 = product names ("Mælk og Brød"), 2 = date ("den 22. september 2026") */
					__( 'Remove %1$s, and the rest can be delivered together on %2$s', O_TEXTDOMAIN ),
					$names,
					$option['date_label']
				);
		}

		return $options;
	}

	/**
	 * Product names for a set of cart item keys, in cart order and de-duplicated.
	 *
	 * @param string[] $keys
	 * @return string[]
	 */
	private function names_for_keys( array $keys ): array {
		$cart_items = ( function_exists( 'WC' ) && WC()->cart ) ? WC()->cart->get_cart() : array();
		$names      = array();
		foreach ( $keys as $key ) {
			$pid = (int) ( $cart_items[ $key ]['product_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			$prod = function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : null;
			if ( $prod ) {
				$names[] = $prod->get_name();
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Join names the way a person writes a list: "Mælk, Brød og Smør".
	 *
	 * @param string[] $names
	 */
	public static function format_name_list( array $names ): string {
		$names = array_values( array_filter( $names, function ( $n ) { return (string) $n !== ''; } ) );
		if ( empty( $names ) ) {
			return '';
		}
		if ( count( $names ) === 1 ) {
			return $names[0];
		}
		$last = array_pop( $names );

		return implode( ', ', $names ) . ' ' . __( 'and', O_TEXTDOMAIN ) . ' ' . $last;
	}

	// ---------------------------------------------------------------------
	// Button wording (shop-editable)
	// ---------------------------------------------------------------------

	/** Plugin settings, or an empty array before the plugin is configured. */
	private static function settings(): array {
		$settings = function_exists( 'o_get_settings' ) ? o_get_settings() : array();

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * What the "split it up" button says.
	 *
	 * Two deliveries get their own wording because that is what nearly every
	 * conflicting cart needs and "i to" reads far better than a number. Three or
	 * more falls back to a counted phrase — promising "i to" when the shop is
	 * about to ask for three orders would be a lie the customer finds out about
	 * one order in.
	 *
	 * @param int $group_count How many deliveries the cart needs.
	 */
	public static function split_button_label( int $group_count ): string {
		$settings = self::settings();

		if ( $group_count <= 2 ) {
			$label = trim( (string) ( $settings['_split_button_split_label'] ?? '' ) );
			return $label !== '' ? $label : __( 'Split the delivery in two', O_TEXTDOMAIN );
		}

		$template = trim( (string) ( $settings['_split_button_split_label_many'] ?? '' ) );
		if ( $template === '' ) {
			/* translators: %d = number of separate deliveries */
			$template = __( 'Split into %d deliveries', O_TEXTDOMAIN );
		}

		// A shop that drops the %d gets its wording verbatim rather than a
		// PHP warning, so a typo in a settings field cannot break a checkout.
		return strpos( $template, '%d' ) === false ? $template : sprintf( $template, $group_count );
	}

	/** What the "take things out of the basket" button says. */
	public static function reduce_button_label(): string {
		$label = trim( (string) ( self::settings()['_split_button_reduce_label'] ?? '' ) );

		return $label !== '' ? $label : __( 'Empty from the basket', O_TEXTDOMAIN );
	}

	// ---------------------------------------------------------------------
	// State management
	// ---------------------------------------------------------------------

	private function get_state(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) { return array(); }
		$state = WC()->session->get( self::SESSION_KEY, array() );
		return is_array( $state ) ? $state : array();
	}

	private function set_state( array $state ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) { return; }
		WC()->session->set( self::SESSION_KEY, $state );
	}

	private function clear_state(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) { return; }
		WC()->session->__unset( self::SESSION_KEY );
	}

	private function is_split_active(): bool {
		$state = $this->get_state();
		return ! empty( $state['split_token'] );
	}

	private function generate_token(): string {
		// Random 16-byte hex; UUID would also work but adds no value.
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return md5( microtime( true ) . wp_rand( 0, PHP_INT_MAX ) );
		}
	}

	// ---------------------------------------------------------------------
	// Banner (checkout page, before customer has acknowledged)
	// ---------------------------------------------------------------------

	public function maybe_render_banner(): void {
		// If a split is already active, render the "you're in step N of M"
		// banner instead of the conflict banner.
		if ( $this->is_split_active() ) {
			$this->render_active_step_banner();
			return;
		}

		$groups = $this->compute_split_groups();
		if ( count( $groups ) < 2 ) { return; }

		$options      = $this->compute_removal_options();
		$can_split    = $this->groups_are_bookable( $groups );
		$group_count  = count( $groups );

		// Neither way out is available: no bookable split and nothing that
		// could be removed to rescue the rest. Say so plainly rather than
		// showing two buttons that do nothing.
		if ( ! $can_split && empty( $options ) ) {
			$this->render_dead_end_banner();
			return;
		}

		// CSS: hide the rest of the checkout form while the conflict banner
		// is showing — there's no point letting the customer fill in
		// billing/shipping fields when they need to make a different
		// decision first. The banner itself sits above the form (we hook
		// woocommerce_before_checkout_form), so we just hide the form.
		echo '<style>
			form.checkout.woocommerce-checkout { display: none !important; }
			.oko-split-banner h3 { margin: 0 0 12px; font-size: 1.4em; }
			.oko-split-banner ol { margin: 0 0 20px; padding-left: 24px; }
			.oko-split-banner ol li { margin-bottom: 8px; line-height: 1.5; }
			.oko-split-banner .oko-split-actions {
				display: flex; flex-direction: column; gap: 14px;
				background: #fff; padding: 16px; border-radius: 4px;
				margin-top: 16px;
			}
			.oko-split-banner .oko-split-choices {
				display: flex; flex-wrap: wrap; gap: 12px;
			}
			.oko-split-banner .oko-split-cta {
				flex: 1 1 220px; padding: 14px 24px; font-size: 1.05em;
				font-weight: 600; background: #c44; color: #fff;
				border: none; border-radius: 4px; cursor: pointer;
				transition: opacity 0.2s, transform 0.1s;
			}
			.oko-split-banner .oko-split-cta:hover { opacity: 0.92; }
			.oko-split-banner .oko-split-cta:active { transform: translateY(1px); }
			.oko-split-banner .oko-split-cta[disabled] {
				background: #b78a8a; cursor: wait;
			}
			.oko-split-banner .oko-split-cta.is-secondary {
				background: #fff; color: #444; border: 2px solid #c44;
			}
			.oko-split-banner .oko-split-cta.is-secondary[aria-expanded=true] {
				background: #f7e9e9;
			}
			.oko-split-banner .oko-split-remove-panel {
				margin-top: 4px; border-top: 1px solid #f0c0c0; padding-top: 14px;
			}
			.oko-split-banner .oko-split-remove-panel[hidden] { display: none; }
			.oko-split-banner .oko-split-remove-option {
				display: flex; align-items: flex-start; gap: 10px;
				padding: 10px 12px; border: 1px solid #e4d7d7;
				border-radius: 4px; margin-bottom: 8px; cursor: pointer;
				line-height: 1.45;
			}
			.oko-split-banner .oko-split-remove-option:hover { background: #fdf7f7; }
			.oko-split-banner .oko-split-remove-option input[type=radio] {
				margin-top: 4px; transform: scale(1.2); flex: 0 0 auto;
			}
			.oko-split-banner .oko-split-error {
				color: #c44; font-weight: 600; margin: 0;
				min-height: 1.2em;
			}
			.oko-split-banner .oko-split-leave {
				margin: 12px 0 0; font-size: 0.95em;
			}
			.oko-split-banner .oko-split-leave a {
				color: #6a4a4a; text-decoration: underline;
			}
		</style>';

		echo '<div class="oko-split-banner" id="oko-split-banner" style="background:#fff5f5;border:1px solid #f0c0c0;border-left:4px solid #c44;padding:20px;margin:0 0 24px;border-radius:4px;">';

		// A basket that has to cross the two kinds of order needs saying
		// differently: the customer asked to pre-order everything, and the
		// answer is that only part of it can be.
		$mixes_modes = $this->groups_mix_modes( $groups );

		echo '<h3>'
			. esc_html(
				$mixes_modes
					? __( 'Only part of your basket can be pre-ordered', O_TEXTDOMAIN )
					// The whole of it, in one line, and true without naming a
					// single day — which is what the list below no longer does.
					: __( 'Your items cannot all be delivered on the same day', O_TEXTDOMAIN )
			)
			. '</h3>';

		echo '<p style="margin:0 0 16px;">'
			. esc_html(
				$mixes_modes
					? __( 'You can split the order into a pre-order for the items that can be held, and an ordinary delivery for the rest — each with its own date and its own fee. Or take the items that cannot be pre-ordered out of the basket. Choose below.', O_TEXTDOMAIN )
					: __( 'You can split the order so each part is delivered on its own day, or take a few items out of the basket so everything else arrives together. Choose below.', O_TEXTDOMAIN )
			)
			. '</p>';

		echo '<ol>';
		foreach ( $groups as $idx => $group ) {
			echo '<li><strong>'
				. esc_html( $this->group_heading( $group, $idx + 1 ) )
				. '</strong> — '
				. esc_html( implode( ', ', $group['product_names'] ) )
				. '</li>';
		}
		echo '</ol>';

		echo '<div class="oko-split-actions">';

		echo '<div class="oko-split-choices">';

		if ( $can_split ) {
			echo '<button type="button" id="oko-split-continue" class="oko-split-cta">'
				. esc_html( self::split_button_label( $group_count ) )
				. '</button>';
		}

		if ( ! empty( $options ) ) {
			echo '<button type="button" id="oko-split-reduce-toggle" class="oko-split-cta is-secondary"'
				. ' aria-expanded="false" aria-controls="oko-split-remove-panel">'
				. esc_html( self::reduce_button_label() )
				. '</button>';
		}

		echo '</div>';

		if ( ! empty( $options ) ) {
			// Open by default when there is nothing else to click, so the
			// customer's only way forward isn't hidden behind a toggle.
			$this->render_removal_options( $options, ! $can_split );
		}

		// The way back out of a pre-order. The banner hides the checkout form,
		// and the ordinary-order button lives inside it, so without this the
		// customer who did not want a pre-order after all is stuck looking at
		// a choice between two ways of splitting one. Deliberately a link and
		// deliberately quiet: it is the third thing to consider, not the first,
		// and it works whether or not any script on the page does.
		if ( $this->is_pre_order_mode() ) {
			printf(
				'<p class="oko-split-leave"><a href="%s">%s</a></p>',
				esc_url( \oko_checkout_url_for_mode( false ) ),
				esc_html__( 'Choose an ordinary order instead', O_TEXTDOMAIN )
			);
		}

		echo '<p class="oko-split-error" id="oko-split-error" aria-live="polite"></p>';

		echo '</div>';
		echo '</div>';

		// Inline JS — uses event delegation on `document` so the listeners
		// survive WooCommerce's checkout re-renders.
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( $this->nonce_action() );
		?>
		<script>
		(function () {
			"use strict";
			if (window._okoSplitBound) { return; }
			window._okoSplitBound = true;

			var AJAX_URL       = <?php echo wp_json_encode( $ajax_url ); ?>;
			var NONCE          = <?php echo wp_json_encode( $nonce ); ?>;
			var TXT_WORKING    = <?php echo wp_json_encode( __( 'Working…', O_TEXTDOMAIN ) ); ?>;
			var TXT_ERR_START  = <?php echo wp_json_encode( __( 'Could not start split checkout. Please try again.', O_TEXTDOMAIN ) ); ?>;
			var TXT_ERR_REDUCE = <?php echo wp_json_encode( __( 'Could not remove the items. Please try again.', O_TEXTDOMAIN ) ); ?>;
			var TXT_ERR_PICK   = <?php echo wp_json_encode( __( 'Choose one of the options first.', O_TEXTDOMAIN ) ); ?>;

			function errorBox() { return document.getElementById('oko-split-error'); }

			function showError(message) {
				var err = errorBox();
				if (err) { err.textContent = message; } else { alert(message); }
			}

			function clearError() {
				var err = errorBox();
				if (err) { err.textContent = ''; }
			}

			// The banner is the decision; WooCommerce's own place-order button
			// must not offer a way around it.
			function lockPlaceOrder() {
				var placeOrder = document.querySelector('#place_order');
				if (placeOrder) {
					placeOrder.disabled = true;
					placeOrder.style.opacity = '0.5';
					placeOrder.style.cursor = 'not-allowed';
				}
			}

			function post(fields, button, fallbackMessage) {
				clearError();
				var original = button.textContent;
				button.disabled = true;
				button.textContent = TXT_WORKING;

				var fd = new FormData();
				fd.append('_wpnonce', NONCE);
				Object.keys(fields).forEach(function (name) {
					fd.append(name, fields[name]);
				});

				function restore() {
					button.textContent = original;
					button.disabled = false;
				}

				fetch(AJAX_URL, {
					method: 'POST',
					credentials: 'same-origin',
					body: fd
				}).then(function (r) {
					return r.json();
				}).then(function (data) {
					if (data && data.success) {
						window.location.reload();
						return;
					}
					showError((data && data.data && data.data.message) || fallbackMessage);
					restore();
				}).catch(function () {
					showError(fallbackMessage);
					restore();
				});
			}

			document.addEventListener('click', function (e) {
				var target = e.target;
				if (!target || !target.id) { return; }

				if (target.id === 'oko-split-continue') {
					e.preventDefault();
					post({ action: 'oko_start_split' }, target, TXT_ERR_START);
					return;
				}

				if (target.id === 'oko-split-reduce-toggle') {
					e.preventDefault();
					var panel = document.getElementById('oko-split-remove-panel');
					if (!panel) { return; }
					var open = panel.hasAttribute('hidden');
					if (open) { panel.removeAttribute('hidden'); } else { panel.setAttribute('hidden', ''); }
					target.setAttribute('aria-expanded', open ? 'true' : 'false');
					clearError();
					return;
				}

				if (target.id === 'oko-split-reduce-confirm') {
					e.preventDefault();
					var picked = document.querySelector('input[name="oko_split_remove_option"]:checked');
					if (!picked) {
						showError(TXT_ERR_PICK);
						return;
					}
					post(
						{ action: 'oko_reduce_split', date: picked.value },
						target,
						TXT_ERR_REDUCE
					);
				}
			});

			if (window.jQuery) {
				jQuery(document.body).on('updated_checkout', lockPlaceOrder);
			}
			if (document.readyState !== 'loading') {
				lockPlaceOrder();
			} else {
				document.addEventListener('DOMContentLoaded', lockPlaceOrder);
			}
		})();
		</script>
		<?php
	}

	/**
	 * The list of "remove these and the rest travels together" offers.
	 *
	 * @param array<int, array> $options From compute_removal_options().
	 * @param bool              $open    Whether the panel starts expanded.
	 */
	private function render_removal_options( array $options, bool $open ): void {
		printf(
			'<div class="oko-split-remove-panel" id="oko-split-remove-panel"%s>',
			$open ? '' : ' hidden'
		);

		echo '<p style="margin:0 0 10px;">'
			. esc_html__( 'Choose what you would rather do without this time. We take those items out of the basket, and everything else is delivered on the same day.', O_TEXTDOMAIN )
			. '</p>';

		foreach ( $options as $option ) {
			echo '<label class="oko-split-remove-option">';
			printf(
				'<input type="radio" name="oko_split_remove_option" value="%s" />',
				esc_attr( $option['date'] )
			);
			echo '<span>' . esc_html( $option['text'] ) . '</span>';
			echo '</label>';
		}

		echo '<button type="button" id="oko-split-reduce-confirm" class="oko-split-cta" style="margin-top:6px;">'
			. esc_html__( 'Remove the items and continue', O_TEXTDOMAIN )
			. '</button>';

		echo '</div>';
	}

	/**
	 * Shown when the cart cannot be delivered at all and neither button helps:
	 * no day can carry a group, and removing items would not rescue the rest.
	 * In practice this is a rule that has closed on a product already in the
	 * basket, so the honest answer is to send the customer to the shop.
	 */
	private function render_dead_end_banner(): void {
		echo '<style>form.checkout.woocommerce-checkout { display: none !important; }</style>';
		echo '<div class="oko-split-banner" style="background:#fff5f5;border:1px solid #f0c0c0;border-left:4px solid #c44;padding:20px;margin:0 0 24px;border-radius:4px;">';
		echo '<h3 style="margin:0 0 12px;">' . esc_html__( 'We cannot find a delivery day for your basket', O_TEXTDOMAIN ) . '</h3>';
		echo '<p style="margin:0;">'
			. esc_html__( 'One or more items in the basket cannot be delivered at the moment. Please go back to the basket and remove them, or contact the shop.', O_TEXTDOMAIN )
			. '</p>';
		printf(
			'<p style="margin:12px 0 0;"><a class="button" href="%s">%s</a></p>',
			esc_url( wc_get_cart_url() ),
			esc_html__( 'Back to the basket', O_TEXTDOMAIN )
		);
		echo '</div>';
	}

	/**
	 * Render the "you're in step N of M" banner shown after the customer
	 * has clicked "Continue with delivery 1" — at that point the cart
	 * is already reduced to the current step's items.
	 */
	private function render_active_step_banner(): void {
		$state = $this->get_state();
		if ( empty( $state ) ) { return; }
		$current = (int) ( $state['current_step'] ?? 0 );
		$total   = (int) ( $state['total_steps'] ?? 0 );
		if ( $current < 1 || $total < 2 ) { return; }

		$nonce        = wp_create_nonce( $this->nonce_action() );
		$ajax_url     = admin_url( 'admin-ajax.php' );
		$cancel_label = __( 'Cancel split delivery and start over', O_TEXTDOMAIN );

		echo '<div class="oko-split-active-banner" style="background:#eaf5ea;border:1px solid #b3d8b3;border-left:4px solid #4a8;padding:14px;margin:0 0 24px;border-radius:4px;">';
		echo '<strong>'
			. esc_html( sprintf(
				/* translators: 1 = current step, 2 = total steps */
				__( 'You\'re ordering delivery %1$d of %2$d', O_TEXTDOMAIN ),
				$current,
				$total
			) )
			. '</strong><br>';
		echo esc_html__( 'After you complete this order, we\'ll guide you to the next delivery.', O_TEXTDOMAIN );
		echo '<div style="margin-top:10px;font-size:0.9em;">';
		echo '<a href="#" class="oko-split-cancel" style="color:#a44;text-decoration:underline;">'
			. esc_html( $cancel_label )
			. '</a>';
		echo '</div>';
		echo '</div>';

		// Inline JS — kept small and dependency-free so it works even when
		// the rest of the checkout JS hasn't loaded yet.
		?>
		<script>
			(function () {
				var links = document.querySelectorAll('.oko-split-cancel');
				if (!links.length) { return; }
				links.forEach(function (link) {
					link.addEventListener('click', function (ev) {
						ev.preventDefault();
						var formData = new FormData();
						formData.append('action', 'oko_cancel_split');
						formData.append('_wpnonce', <?php echo wp_json_encode( $nonce ); ?>);
						fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
							method: 'POST',
							credentials: 'same-origin',
							body: formData
						}).then(function (r) {
							return r.json().catch(function () { return null; });
						}).then(function (data) {
							var target = (data && data.data && data.data.redirect) || <?php echo wp_json_encode( wc_get_page_permalink( 'shop' ) ); ?>;
							window.location.href = target;
						}).catch(function () {
							// Even on error, send the customer somewhere safe so
							// they're not trapped on a stale checkout screen.
							window.location.href = <?php echo wp_json_encode( wc_get_page_permalink( 'shop' ) ); ?>;
						});
					});
				});
			})();
		</script>
		<?php
	}

	// ---------------------------------------------------------------------
	// AJAX: start the split (called from banner button click)
	// ---------------------------------------------------------------------

	/**
	 * Bind the split-checkout nonce to the current WC customer session.
	 *
	 * WP nonces are tied to user ID + tick, so for guest checkout (uid 0)
	 * the action key is shared across all anonymous browsers in the nonce
	 * window. Mixing in WC()->session->get_customer_id() — which is unique
	 * per browser session even for guests — closes that gap.
	 */
	private function nonce_action(): string {
		$cid = '0';
		if ( function_exists( 'WC' ) && WC()->session ) {
			$candidate = WC()->session->get_customer_id();
			if ( is_string( $candidate ) && $candidate !== '' ) {
				$cid = $candidate;
			}
		}
		return 'oko_split_' . $cid;
	}

	/**
	 * Force-init WC session/cart for AJAX requests.
	 *
	 * AJAX requests don't necessarily have WC session/cart bootstrapped
	 * the same way a normal page request does, and WC()->cart can be null
	 * for guests until we do this.
	 */
	private function ensure_wc_session(): void {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		if ( WC()->session === null && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	public function ajax_start_split(): void {
		// Bootstrap session BEFORE nonce check — the action key is bound
		// to WC()->session->get_customer_id().
		$this->ensure_wc_session();
		check_ajax_referer( $this->nonce_action(), '_wpnonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			error_log( 'okoskabet_woocommerce_plugin: split start failed — WC()->cart unavailable' );
			wp_send_json_error( array( 'message' => 'WooCommerce cart not available' ) );
		}
		if ( $this->is_split_active() ) {
			wp_send_json_error( array( 'message' => 'A split is already in progress' ) );
		}

		$groups = $this->compute_split_groups();
		if ( count( $groups ) < 2 ) {
			error_log( sprintf(
				'okoskabet_woocommerce_plugin: split start failed — only %d group(s) detected for cart with %d item(s)',
				count( $groups ),
				WC()->cart->get_cart_contents_count()
			) );
			wp_send_json_error( array( 'message' => __( 'No split needed', O_TEXTDOMAIN ) ) );
		}

		// A group with no deliverable day would become an order the customer
		// cannot pick a date for. Removing those items is the only way out,
		// and the banner offers exactly that instead of this button.
		if ( ! $this->groups_are_bookable( $groups ) ) {
			wp_send_json_error( array(
				'message' => __( 'Some items have no delivery day, so the order cannot be split. Remove them instead.', O_TEXTDOMAIN ),
			) );
		}

		// Snapshot every group's items in a serialisable shape so we can
		// rebuild the cart later. We can't store WC's full cart_item array
		// (it has runtime data + closures); we store a minimal recreation
		// recipe per item.
		$snapshot_groups = array();
		foreach ( $groups as $g ) {
			$items_recipe = array();
			foreach ( $g['items'] as $key => $item ) {
				$items_recipe[] = array(
					'product_id'    => (int) ( $item['product_id'] ?? 0 ),
					'quantity'      => (int) ( $item['quantity'] ?? 1 ),
					'variation_id'  => (int) ( $item['variation_id'] ?? 0 ),
					'variation'     => is_array( $item['variation'] ?? null ) ? $item['variation'] : array(),
					// Persist any cart_item_data we don't recognise; it
					// might be needed by other plugins (e.g. add-ons).
					'cart_item_data' => $this->extract_cart_item_data( $item ),
				);
			}
			$snapshot_groups[] = array(
				'product_names'  => $g['product_names'],
				'suggested_date' => $g['suggested_date'],
				// Which kind of order this step is. Step one may be a pre-order
				// and step two an ordinary delivery, and the checkout has to be
				// put back into the right one when the customer returns to it.
				'mode'           => $g['mode'] ?? self::MODE_NORMAL,
				'items'          => $items_recipe,
			);
		}

		$state = array(
			'split_token'      => $this->generate_token(),
			'total_steps'      => count( $groups ),
			'current_step'     => 1,
			'groups'           => $snapshot_groups,
			'completed_orders' => array(),
			'created_at'       => time(),
		);
		$this->set_state( $state );

		// Reduce the cart to ONLY the items in group 1.
		$this->load_cart_for_step( 1 );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'okoskabet_woocommerce_plugin: split started, token=%s, total_steps=%d',
				$state['split_token'],
				$state['total_steps']
			) );
		}

		wp_send_json_success( array( 'redirect' => wc_get_checkout_url() ) );
	}

	/**
	 * Put the checkout into an ordinary order or a pre-order.
	 *
	 * The customer's choice lives in a cookie the pre-order button sets and in
	 * the `billing_okoskabet_pre_order` field. A split can cross the two — the
	 * ice held until December, the cornflakes delivered this week — so moving
	 * to the next step has to move the checkout with it, or step two renders as
	 * the kind of order step one was and offers the wrong days.
	 *
	 * The cookie is what the date picker and the packaging fee read; the field
	 * is pinned separately, in checkout_value_for_step().
	 */
	private function apply_order_mode( string $mode ): void {
		$wanted = $mode === self::MODE_PRE_ORDER ? '1' : '';

		// Keep this request's own reads honest too: a cookie sent now is not
		// readable until the next request, and the code after this one still
		// has to see the mode it just moved into.
		$_COOKIE['okoskabet_pre_order'] = $wanted;

		if ( defined( 'COOKIEPATH' ) && ! headers_sent() ) {
			setcookie( 'okoskabet_pre_order', $wanted, array(
				'expires'  => $wanted === '1' ? time() + DAY_IN_SECONDS : time() - DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			) );
		}
	}

	/**
	 * Pin the checkout's pre-order field to the kind of order the current step
	 * is, so a page load lands in the right mode.
	 *
	 * WooCommerce asks this filter for each field's starting value, and the
	 * plugin already answers it to stop last year's Christmas pre-order coming
	 * back. During a split the answer is not "empty" but "whatever this step
	 * is" — otherwise the customer arrives at the pre-order step looking at
	 * ordinary days.
	 *
	 * @param  mixed  $value
	 * @param  string $input
	 * @return mixed
	 */
	public function checkout_value_for_step( $value, $input ) {
		if ( $input !== 'billing_okoskabet_pre_order' || ! $this->is_split_active() ) {
			return $value;
		}

		$state = $this->get_state();
		$mode  = $state['groups'][ (int) ( $state['current_step'] ?? 0 ) - 1 ]['mode'] ?? null;
		if ( $mode === null ) {
			return $value;
		}

		return $mode === self::MODE_PRE_ORDER ? '1' : '';
	}

	/**
	 * Pull through any non-internal keys from a cart-item — these are
	 * usually plugin-added (Product Add-Ons, etc.). We strip the keys WC
	 * itself manages to avoid re-injecting outdated copies.
	 */
	private function extract_cart_item_data( array $item ): array {
		$internal = array(
			'key', 'product_id', 'variation_id', 'variation', 'quantity',
			'data', 'data_hash', 'line_tax_data', 'line_subtotal',
			'line_subtotal_tax', 'line_total', 'line_tax',
		);
		return array_diff_key( $item, array_flip( $internal ) );
	}

	/**
	 * Rebuild WC()->cart from a stored group snapshot, and put the checkout
	 * into the kind of order that step is.
	 */
	private function load_cart_for_step( int $step ): void {
		$state = $this->get_state();
		if ( empty( $state['groups'] ) ) { return; }
		$idx = $step - 1;
		if ( ! isset( $state['groups'][ $idx ] ) ) { return; }
		$group = $state['groups'][ $idx ];

		$this->apply_order_mode( (string) ( $group['mode'] ?? self::MODE_NORMAL ) );

		$added_count = $this->fill_cart_with( $group['items'], sprintf( 'split step %d', $step ) );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'okoskabet_woocommerce_plugin: loaded cart for split step %d — %d/%d items added, cart count now %d',
				$step,
				$added_count,
				count( $group['items'] ),
				WC()->cart->get_cart_contents_count()
			) );
		}
	}

	/**
	 * Replace the cart with the items these recipes describe, and make sure the
	 * change survives to the next page load.
	 *
	 * Returns how many of the recipes made it in: a product that has gone out
	 * of stock since the snapshot was taken is skipped, not fatal.
	 */
	private function fill_cart_with( array $recipes, string $context ): int {
		WC()->cart->empty_cart( false );

		$added_count = 0;
		foreach ( $recipes as $recipe ) {
			$result = WC()->cart->add_to_cart(
				$recipe['product_id'],
				$recipe['quantity'],
				$recipe['variation_id'],
				$recipe['variation'],
				$recipe['cart_item_data']
			);
			if ( $result !== false ) {
				$added_count++;
			} else {
				error_log( sprintf(
					'okoskabet_woocommerce_plugin: failed to add product %d (qty %d) when loading %s',
					(int) $recipe['product_id'],
					(int) $recipe['quantity'],
					$context
				) );
			}
		}
		WC()->cart->calculate_totals();

		// Force-persist the cart to session so the page reload sees the
		// changed cart. WC's cart auto-saves on shutdown, but in AJAX we
		// can't always rely on shutdown firing predictably.
		if ( method_exists( WC()->cart, 'persistent_cart_update' ) ) {
			WC()->cart->persistent_cart_update();
		}
		if ( WC()->session ) {
			WC()->session->set( 'cart', WC()->cart->get_cart_for_session() );
			WC()->session->save_data();
		}

		return $added_count;
	}

	// ---------------------------------------------------------------------
	// AJAX: resume to next step (called from thank-you banner button click)
	// ---------------------------------------------------------------------

	/**
	 * Abandon an active split-checkout flow.
	 *
	 * Without this, a customer who decides mid-flow that they don't want to
	 * continue with the planned multi-delivery order has no way out — the
	 * session-stored state lingers until the WC session itself expires
	 * (typically 48 hours), and any subsequent visit to checkout keeps
	 * surfacing the "you're ordering delivery N of N" banner.
	 *
	 * Starting over means starting over with the same basket, not with an empty
	 * one: the split took items out of the cart, and giving up on it has to put
	 * them back, or the way out of the flow costs the customer everything they
	 * had picked. We restore the steps that have not been ordered yet — a group
	 * already paid for is a placed order and must not come back — put the
	 * checkout into an ordinary order again, and send the customer back to the
	 * checkout with the whole remaining basket in front of them.
	 */
	public function ajax_cancel_split(): void {
		// Bootstrap session BEFORE nonce check — see ajax_start_split().
		$this->ensure_wc_session();
		check_ajax_referer( $this->nonce_action(), '_wpnonce' );

		$state   = $this->get_state();
		$current = max( 1, (int) ( $state['current_step'] ?? 1 ) );
		$groups  = is_array( $state['groups'] ?? null ) ? $state['groups'] : array();

		$this->clear_state();

		$restored = 0;
		if ( function_exists( 'WC' ) && WC()->cart ) {
			$recipes = array();
			foreach ( array_slice( $groups, $current - 1 ) as $group ) {
				foreach ( (array) ( $group['items'] ?? array() ) as $recipe ) {
					$recipes[] = $recipe;
				}
			}

			$this->apply_order_mode( self::MODE_NORMAL );
			$restored = $this->fill_cart_with( $recipes, 'cancelled split' );
		}

		if ( $restored === 0 ) {
			wp_send_json_success( array( 'redirect' => wc_get_page_permalink( 'shop' ) ) );
		}

		wc_add_notice(
			__( 'The split delivery is cancelled, and your basket is back as it was.', O_TEXTDOMAIN ),
			'notice'
		);

		wp_send_json_success( array( 'redirect' => wc_get_checkout_url() ) );
	}

	/**
	 * Take the items a chosen option names out of the cart, so the rest of the
	 * basket can go out on one delivery day.
	 *
	 * The client sends only the delivery date it picked; which items that costs
	 * is recomputed here from the live cart. Trusting a list of cart keys from
	 * the browser would let a stale banner — one rendered before the customer
	 * changed the basket in another tab — empty items the customer never agreed
	 * to give up.
	 */
	public function ajax_reduce_split(): void {
		// Bootstrap session BEFORE nonce check — see ajax_start_split().
		$this->ensure_wc_session();
		check_ajax_referer( $this->nonce_action(), '_wpnonce' );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'WooCommerce cart not available', O_TEXTDOMAIN ) ) );
		}
		if ( $this->is_split_active() ) {
			wp_send_json_error( array( 'message' => __( 'A split is already in progress', O_TEXTDOMAIN ) ) );
		}

		$date = isset( $_POST['date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['date'] ) ) : '';
		if ( $date === '' ) {
			wp_send_json_error( array( 'message' => __( 'No option chosen', O_TEXTDOMAIN ) ) );
		}

		$chosen = null;
		foreach ( $this->compute_removal_options() as $option ) {
			if ( $option['date'] === $date ) {
				$chosen = $option;
				break;
			}
		}
		if ( $chosen === null ) {
			// The basket, or the shop's rules, moved under an open checkout.
			// Refusing the stale choice is right — those are not the items the
			// customer agreed to give up any more — but refusing it and saying
			// "prøv igen" on a page still showing the old options is a dead
			// end: trying again does the same thing. Send them back to the
			// banner as it stands now, with a word about why it changed, so
			// "again" means something.
			wc_add_notice(
				__( 'Your basket has changed, so we have worked the options out again. Please choose once more.', O_TEXTDOMAIN ),
				'notice'
			);
			wp_send_json_success( array( 'redirect' => wc_get_checkout_url() ) );
		}

		foreach ( $chosen['remove_keys'] as $key ) {
			WC()->cart->remove_cart_item( $key );
		}
		WC()->cart->calculate_totals();

		if ( WC()->session ) {
			WC()->session->set( 'cart', WC()->cart->get_cart_for_session() );
			WC()->session->save_data();
		}

		wc_add_notice(
			sprintf(
				/* translators: %s = product names ("Mælk og Brød") */
				__( 'We took %s out of your basket. The rest is delivered together.', O_TEXTDOMAIN ),
				self::format_name_list( $chosen['remove_names'] )
			),
			'notice'
		);

		wp_send_json_success( array( 'redirect' => wc_get_checkout_url() ) );
	}

	public function ajax_resume_split(): void {
		// Bootstrap session BEFORE nonce check — see ajax_start_split().
		$this->ensure_wc_session();
		check_ajax_referer( $this->nonce_action(), '_wpnonce' );

		if ( ! $this->is_split_active() ) {
			wp_send_json_error( array( 'message' => 'No active split' ) );
		}
		$state = $this->get_state();
		$next  = (int) ( $state['current_step'] ?? 0 ) + 1;
		if ( $next > (int) ( $state['total_steps'] ?? 0 ) ) {
			// All steps done — clean up.
			$this->clear_state();
			wp_send_json_success( array( 'redirect' => wc_get_page_permalink( 'shop' ) ) );
		}

		$state['current_step'] = $next;
		$this->set_state( $state );

		$this->load_cart_for_step( $next );

		wp_send_json_success( array( 'redirect' => wc_get_checkout_url() ) );
	}

	// ---------------------------------------------------------------------
	// Order-time hooks
	// ---------------------------------------------------------------------

	/**
	 * Block submission if we have a split active but the customer somehow
	 * tries to submit while extra items are in the cart, or if a split is
	 * needed but the customer hasn't acknowledged.
	 */
	public function maybe_block_submission(): void {
		// If split is already active, the cart should already be reduced —
		// just let it through. We trust load_cart_for_step did its job.
		if ( $this->is_split_active() ) {
			return;
		}

		// Otherwise, see if a split would be required for the current cart.
		$groups = $this->compute_split_groups();
		if ( count( $groups ) < 2 ) {
			return;  // no split needed, normal flow
		}

		// A split IS needed but the customer isn't in split-flow yet.
		// They've bypassed the banner JS — block submission.
		wc_add_notice(
			__( 'Your basket needs more than one delivery day. Please choose one of the options above before you order.', O_TEXTDOMAIN ),
			'error'
		);
	}

	/**
	 * Tag the order being created with split-meta so admins can later
	 * reconstruct which orders belong to the same split.
	 */
	public function tag_order_with_split_meta( $order, $data ): void {
		$state = $this->get_state();
		if ( empty( $state['split_token'] ) ) { return; }
		$step = (int) ( $state['current_step'] ?? 0 );
		$order->update_meta_data( self::META_TOKEN, $state['split_token'] );
		$order->update_meta_data( self::META_STEP,  $step );
		$order->update_meta_data( self::META_TOTAL, (int) ( $state['total_steps'] ?? 0 ) );
		// Which kind of order this part was, so the shop can see at a glance why
		// one half of a split carries a pre-order fee and the other does not.
		$order->update_meta_data( self::META_MODE, (string) ( $state['groups'][ $step - 1 ]['mode'] ?? self::MODE_NORMAL ) );
	}

	/**
	 * After WooCommerce has processed the order, record its ID in our
	 * state so the thank-you banner knows what's been completed.
	 */
	public function on_order_processed( int $order_id ): void {
		if ( ! $this->is_split_active() ) { return; }
		$state = $this->get_state();
		$state['completed_orders'][] = $order_id;
		$this->set_state( $state );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'okoskabet_woocommerce_plugin: split step %d/%d completed as order #%d (token=%s)',
				(int) ( $state['current_step'] ?? 0 ),
				(int) ( $state['total_steps'] ?? 0 ),
				$order_id,
				$state['split_token'] ?? '?'
			) );
		}
	}

	// ---------------------------------------------------------------------
	// Thank-you banner
	// ---------------------------------------------------------------------

	public function maybe_render_thankyou_banner( $order_id ): void {
		if ( ! $this->is_split_active() ) { return; }
		$state = $this->get_state();
		$current = (int) ( $state['current_step'] ?? 0 );
		$total   = (int) ( $state['total_steps'] ?? 0 );

		if ( $current >= $total ) {
			// All steps done — clear state and show a celebratory banner.
			$this->clear_state();
			echo '<div class="oko-split-done-banner" style="background:#eaf5ea;border:2px solid #4a8;padding:20px 24px;margin:0 0 32px;border-radius:6px;text-align:center;">';
			echo '<h2 style="margin:0 0 8px;color:#2a6a2a;">' . esc_html__( 'All deliveries booked!', O_TEXTDOMAIN ) . '</h2>';
			echo '<p style="margin:0;font-size:1.05em;">' . esc_html__( 'You\'ve completed all the orders for your split delivery.', O_TEXTDOMAIN ) . '</p>';
			echo '</div>';
			return;
		}

		$next = $current + 1;
		if ( ! isset( $state['groups'][ $next - 1 ] ) ) {
			$this->clear_state();
			return;
		}
		$next_group = $state['groups'][ $next - 1 ];

		// Big, prominent banner ABOVE the order details — designed so the
		// customer cannot miss it. The CTA button is full-width and bold.
		echo '<style>
			.oko-split-next-banner {
				background: linear-gradient(135deg, #fff3cd 0%, #ffe69c 100%);
				border: 2px solid #d4a017;
				padding: 24px 28px;
				margin: 0 0 32px;
				border-radius: 6px;
				box-shadow: 0 2px 8px rgba(212,160,23,0.2);
			}
			.oko-split-next-banner h2 {
				margin: 0 0 12px;
				font-size: 1.5em;
				color: #6d4c00;
			}
			.oko-split-next-banner .oko-split-progress {
				display: inline-block;
				background: #d4a017;
				color: #fff;
				padding: 3px 10px;
				border-radius: 12px;
				font-size: 0.85em;
				font-weight: 600;
				margin-bottom: 10px;
			}
			.oko-split-next-banner .oko-split-summary {
				background: #fff;
				border-radius: 4px;
				padding: 14px 16px;
				margin: 16px 0;
				font-size: 1.05em;
			}
			.oko-split-next-banner .oko-split-summary .label {
				font-size: 0.85em;
				color: #6d4c00;
				font-weight: 600;
				text-transform: uppercase;
				letter-spacing: 0.5px;
				display: block;
				margin-bottom: 4px;
			}
			.oko-split-next-banner .oko-split-resume-cta {
				display: block;
				width: 100%;
				padding: 16px 24px;
				font-size: 1.1em;
				font-weight: 600;
				background: #c44;
				color: #fff;
				border: none;
				border-radius: 4px;
				cursor: pointer;
				transition: opacity 0.2s, transform 0.1s;
				margin-top: 8px;
			}
			.oko-split-next-banner .oko-split-resume-cta:hover { opacity: 0.92; }
			.oko-split-next-banner .oko-split-resume-cta:active { transform: translateY(1px); }
			.oko-split-next-banner .oko-split-resume-cta:disabled {
				background: #b78a8a;
				cursor: wait;
			}
			.oko-split-next-banner .oko-split-help {
				font-size: 0.9em;
				color: #6d4c00;
				margin: 8px 0 0;
				text-align: center;
			}
		</style>';

		echo '<div class="oko-split-next-banner">';
		echo '<span class="oko-split-progress">'
			. esc_html( sprintf(
				/* translators: 1 = current step (just completed), 2 = total */
				__( 'Step %1$d of %2$d completed', O_TEXTDOMAIN ),
				$current,
				$total
			) )
			. '</span>';

		echo '<h2>' . esc_html__( 'You have more deliveries to book', O_TEXTDOMAIN ) . '</h2>';

		echo '<p style="margin:0 0 4px;font-size:1.05em;">';
		echo esc_html( sprintf(
			/* translators: 1 = next step, 2 = total steps */
			__( 'Thanks for your order. You still need to book delivery %1$d of %2$d.', O_TEXTDOMAIN ),
			$next, $total
		) );
		echo '</p>';

		echo '<div class="oko-split-summary">';
		echo '<span class="label">'
			. esc_html( sprintf(
				/* translators: %d = step number */
				__( 'Next: delivery %d', O_TEXTDOMAIN ),
				$next
			) )
			. '</span>';
		echo '<strong>' . esc_html( $this->format_date_for_display( $next_group['suggested_date'] ) ) . '</strong>';
		echo ' — ' . esc_html( implode( ', ', $next_group['product_names'] ) );
		echo '</div>';

		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( $this->nonce_action() );
		echo '<button type="button" id="oko-split-resume" class="oko-split-resume-cta">'
			. esc_html__( 'Book next delivery now', O_TEXTDOMAIN )
			. '</button>';

		echo '<p class="oko-split-help">'
			. esc_html__( 'You can also scroll down to see your order confirmation first.', O_TEXTDOMAIN )
			. '</p>';

		echo '</div>';
		?>
		<script>
		(function () {
			"use strict";
			var btn = document.getElementById('oko-split-resume');
			if (!btn) { return; }
			btn.addEventListener('click', function () {
				btn.disabled = true;
				btn.textContent = <?php echo wp_json_encode( __( 'Working…', O_TEXTDOMAIN ) ); ?>;
				var fd = new FormData();
				fd.append('action', 'oko_resume_split');
				fd.append('_wpnonce', <?php echo wp_json_encode( $nonce ); ?>);
				fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					body: fd
				}).then(function (r) { return r.json(); }).then(function (data) {
					if (data && data.success && data.data && data.data.redirect) {
						window.location.href = data.data.redirect;
					} else {
						alert(<?php echo wp_json_encode( __( 'Could not resume split checkout. Please add the items to your cart manually.', O_TEXTDOMAIN ) ); ?>);
						btn.disabled = false;
					}
				}).catch(function () {
					alert(<?php echo wp_json_encode( __( 'Could not resume split checkout.', O_TEXTDOMAIN ) ); ?>);
					btn.disabled = false;
				});
			});
		})();
		</script>
		<?php
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * How one group is announced in the banner.
	 *
	 * A basket split across a pre-order and an ordinary delivery has to say
	 * which is which. "Levering 2 (10. december)" reads as a very late
	 * delivery; "Forudbestilling 2 (10. december)" reads as what it is, and
	 * explains on sight why that part carries its own fee and its own date.
	 *
	 * @param array $group  From compute_delivery_groups().
	 * @param int   $number Its place in the list, counting from one.
	 */
	protected function group_heading( array $group, int $number ): string {
		$date = (string) ( $group['suggested_date'] ?? '' );
		if ( $date === '' ) {
			return __( 'No delivery day available', O_TEXTDOMAIN );
		}

		$pre_order = ( $group['mode'] ?? self::MODE_NORMAL ) === self::MODE_PRE_ORDER;

		// A day is named only when it is the ONLY day this part could go out
		// on. Where several would do, the one shown was merely the soonest of
		// them, and printing it read as a decision — one the customer had not
		// made and we had not taken, since they pick the real day in the date
		// picker a moment later. What the banner is for is that the basket
		// cannot travel together, and that needs no date to say.
		//
		// A pre-order day usually is the only one, and there the date is a
		// fact rather than one option among several, so it stays.
		if ( count( (array) ( $group['possible_dates'] ?? array() ) ) !== 1 ) {
			return $pre_order
				/* translators: %d = its number in the list */
				? sprintf( __( 'Pre-order %d', O_TEXTDOMAIN ), $number )
				/* translators: %d = its number in the list */
				: sprintf( __( 'Delivery %d', O_TEXTDOMAIN ), $number );
		}

		$formatted = $this->format_date_for_display( $date );

		if ( $pre_order ) {
			return sprintf(
				/* translators: 1 = its number in the list, 2 = formatted date */
				__( 'Pre-order %1$d (%2$s)', O_TEXTDOMAIN ),
				$number,
				$formatted
			);
		}

		return sprintf(
			/* translators: 1 = its number in the list, 2 = formatted date */
			__( 'Delivery %1$d (%2$s)', O_TEXTDOMAIN ),
			$number,
			$formatted
		);
	}

	private function format_date_for_display( string $ymd ): string {
		if ( empty( $ymd ) ) { return ''; }
		try {
			$dt = new \DateTimeImmutable( $ymd, wp_timezone() );
			return wp_date( get_option( 'date_format', 'l, F j, Y' ), $dt->getTimestamp() );
		} catch ( \Throwable $e ) {
			return $ymd;
		}
	}
}
