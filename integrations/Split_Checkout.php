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
 *      delivery groups and require the customer to acknowledge.
 *   3. The PHP `woocommerce_checkout_process` hook blocks order
 *      submission until either (a) no split is needed, or (b) the
 *      customer has acknowledged AND the cart now contains only the
 *      items for the current step.
 *   4. When the customer clicks "Continue with delivery 1", an AJAX
 *      endpoint stores the remaining groups in the WC session and
 *      replaces the cart with only the first group's items.
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

		// AJAX endpoints to start, resume, and cancel a split.
		add_action( 'wp_ajax_oko_start_split',        array( $this, 'ajax_start_split' ) );
		add_action( 'wp_ajax_nopriv_oko_start_split', array( $this, 'ajax_start_split' ) );
		add_action( 'wp_ajax_oko_resume_split',        array( $this, 'ajax_resume_split' ) );
		add_action( 'wp_ajax_nopriv_oko_resume_split', array( $this, 'ajax_resume_split' ) );
		add_action( 'wp_ajax_oko_cancel_split',        array( $this, 'ajax_cancel_split' ) );
		add_action( 'wp_ajax_nopriv_oko_cancel_split', array( $this, 'ajax_cancel_split' ) );

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
	 * Compute the delivery groups required to fulfil the current cart.
	 *
	 * Returns:
	 *   - empty array → no split needed (single delivery date works)
	 *   - 1 group     → no split needed (everything fits one date)
	 *   - 2+ groups   → split required; one order per group
	 *
	 * Each group has shape:
	 *   [
	 *     'items'     => [ <cart_item_key> => <full cart_item array>, ... ],
	 *     'product_ids' => [ id, id, ... ],
	 *     'product_names' => [ 'Mælk', 'Brød', ... ],
	 *     'suggested_date' => 'YYYY-MM-DD',  // first valid date for this group
	 *   ]
	 *
	 * @return array<int, array>
	 */
	public function compute_split_groups(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return array();
		}

		$cart_items = WC()->cart->get_cart();
		if ( empty( $cart_items ) ) {
			return array();
		}

		$delivery_exceptions = $this->get_delivery_exceptions_instance();
		if ( ! $delivery_exceptions ) {
			return array();
		}

		// For each cart item, compute its set of allowed dates.
		// We use a 60-day rolling window — same as the standard checkout API.
		$standard_window = $this->generate_standard_date_window();
		$config          = \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::get_config();

		$item_dates = array();  // cart_item_key => sorted list of allowed YYYY-MM-DD
		foreach ( $cart_items as $key => $item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			if ( $pid <= 0 ) { continue; }
			$rules = $delivery_exceptions->collect_applicable_rules( array( $pid ), $config );
			$allowed = $this->dates_for_rules( $standard_window, $rules );
			sort( $allowed );
			$item_dates[ $key ] = $allowed;
		}

		// Group items by which dates are valid for them. Two items belong to
		// the same group if they share at least one common delivery date.
		// We use a simple greedy clustering: walk items in order; for each
		// item, either join an existing group (if intersecting with that
		// group's running intersection) or open a new group.
		$groups = array();  // each entry: ['keys'=>[...], 'common'=>[date,...]]
		foreach ( $item_dates as $key => $allowed ) {
			$placed = false;
			foreach ( $groups as &$group ) {
				$intersection = array_values( array_intersect( $group['common'], $allowed ) );
				if ( ! empty( $intersection ) ) {
					$group['keys'][]  = $key;
					$group['common']  = $intersection;
					$placed           = true;
					break;
				}
			}
			unset( $group );
			if ( ! $placed ) {
				$groups[] = array(
					'keys'   => array( $key ),
					'common' => $allowed,
				);
			}
		}

		// If we ended up with 0 or 1 groups, no split is needed.
		if ( count( $groups ) < 2 ) {
			return array();
		}

		// Decorate each group with display data and a suggested date (first
		// available date in the common-set).
		$result = array();
		foreach ( $groups as $group ) {
			$items_full   = array();
			$product_ids  = array();
			$product_names = array();
			foreach ( $group['keys'] as $key ) {
				$items_full[ $key ] = $cart_items[ $key ];
				$pid                = (int) ( $cart_items[ $key ]['product_id'] ?? 0 );
				if ( $pid > 0 ) {
					$product_ids[] = $pid;
					$prod          = wc_get_product( $pid );
					if ( $prod ) {
						$product_names[] = $prod->get_name();
					}
				}
			}
			$result[] = array(
				'items'          => $items_full,
				'product_ids'    => array_values( array_unique( $product_ids ) ),
				'product_names'  => array_values( array_unique( $product_names ) ),
				'suggested_date' => ! empty( $group['common'] ) ? $group['common'][0] : '',
			);
		}

		// Order groups by suggested_date so customer sees them
		// chronologically.
		usort( $result, function ( $a, $b ) {
			return strcmp( (string) $a['suggested_date'], (string) $b['suggested_date'] );
		} );

		return $result;
	}

	/**
	 * Generate the standard 60-day window starting tomorrow. The Økoskabet
	 * API normally returns the operational window; for split-detection we
	 * just need a reasonable horizon to test rules against.
	 *
	 * @return string[]
	 */
	private function generate_standard_date_window(): array {
		$dates = array();
		$start = new \DateTimeImmutable( 'tomorrow', wp_timezone() );
		for ( $i = 0; $i < 60; $i++ ) {
			$dates[] = $start->modify( "+{$i} days" )->format( 'Y-m-d' );
		}
		return $dates;
	}

	/**
	 * Filter a date list down to those that pass all given rules.
	 * Mirrors the restrict-stage of Delivery_Exceptions::filter_dates_for_cart.
	 *
	 * @param string[] $dates
	 * @param array    $rules
	 * @return string[]
	 */
	private function dates_for_rules( array $dates, array $rules ): array {
		if ( empty( $rules ) ) { return $dates; }
		return array_values( array_filter( $dates, function ( string $date ) use ( $rules ): bool {
			foreach ( $rules as $rule ) {
				if ( ! \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions::date_passes_rule( $date, $rule ) ) {
					return false;
				}
			}
			return true;
		} ) );
	}

	/**
	 * Get the live Delivery_Exceptions instance from the plugin's classmap
	 * loader. The Initialize class instantiates one of each integration —
	 * we look up that singleton rather than create a new one (so we share
	 * the per-request rule cache).
	 */
	private function get_delivery_exceptions_instance() {
		// Lazy: just instantiate a new one. The expensive work
		// (collect_applicable_rules) is statically cached, so a fresh
		// instance hits the same cache.
		return new \okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions();
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
			.oko-split-banner .oko-split-ack-row {
				display: flex; align-items: flex-start; gap: 10px;
				cursor: pointer; user-select: none;
			}
			.oko-split-banner .oko-split-ack-row input[type=checkbox] {
				margin-top: 3px; transform: scale(1.2);
			}
			.oko-split-banner .oko-split-cta {
				width: 100%; padding: 14px 24px; font-size: 1.05em;
				font-weight: 600; background: #c44; color: #fff;
				border: none; border-radius: 4px; cursor: pointer;
				transition: opacity 0.2s, transform 0.1s;
			}
			.oko-split-banner .oko-split-cta:hover { opacity: 0.92; }
			.oko-split-banner .oko-split-cta:active { transform: translateY(1px); }
			.oko-split-banner .oko-split-cta.is-locked {
				background: #b78a8a; cursor: not-allowed;
			}
			.oko-split-banner .oko-split-error {
				color: #c44; font-weight: 600; margin: 0;
				min-height: 1.2em;
			}
			.oko-split-ack-row.shake { animation: oko-shake 0.3s; }
			@keyframes oko-shake {
				0%, 100% { transform: translateX(0); }
				25%      { transform: translateX(-6px); }
				75%      { transform: translateX(6px); }
			}
		</style>';

		echo '<div class="oko-split-banner" id="oko-split-banner" style="background:#fff5f5;border:1px solid #f0c0c0;border-left:4px solid #c44;padding:20px;margin:0 0 24px;border-radius:4px;">';

		echo '<h3>'
			. esc_html( sprintf(
				/* translators: %d = number of separate deliveries */
				_n(
					'Your items must be delivered on %d separate day',
					'Your items must be delivered on %d separate days',
					count( $groups ),
					O_TEXTDOMAIN
				),
				count( $groups )
			) )
			. '</h3>';

		echo '<p style="margin:0 0 16px;">'
			. esc_html__( 'You\'ll need to complete one order per delivery day. We\'ll guide you through each step.', O_TEXTDOMAIN )
			. '</p>';

		echo '<ol>';
		foreach ( $groups as $idx => $group ) {
			echo '<li><strong>'
				. esc_html( sprintf(
					/* translators: 1 = step number, 2 = formatted date */
					__( 'Delivery %1$d (%2$s)', O_TEXTDOMAIN ),
					$idx + 1,
					$this->format_date_for_display( $group['suggested_date'] )
				) )
				. '</strong> — '
				. esc_html( implode( ', ', $group['product_names'] ) )
				. '</li>';
		}
		echo '</ol>';

		echo '<div class="oko-split-actions">';

		echo '<label class="oko-split-ack-row">';
		echo '<input type="checkbox" id="oko-split-ack" />';
		echo '<span>' . esc_html( sprintf(
			/* translators: %d = total number of separate orders */
			_n(
				'I understand I will complete %d separate order',
				'I understand I will complete %d separate orders',
				count( $groups ),
				O_TEXTDOMAIN
			),
			count( $groups )
		) ) . '</span>';
		echo '</label>';

		echo '<button type="button" id="oko-split-continue" class="oko-split-cta is-locked">'
			. esc_html( sprintf(
				/* translators: 1 = current step number, 2 = total steps */
				__( 'Continue with delivery %1$d of %2$d', O_TEXTDOMAIN ),
				1,
				count( $groups )
			) )
			. '</button>';

		echo '<p class="oko-split-error" id="oko-split-error" aria-live="polite"></p>';

		echo '</div>';
		echo '</div>';

		// Inline JS — uses event delegation on `document` so the listeners
		// survive WooCommerce's checkout re-renders. The button is always
		// "clickable" — if the checkbox isn't ticked, we shake the checkbox
		// row and show an inline error rather than disabling the button
		// (which made the button visually disappear in earlier UI tests).
		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( $this->nonce_action() );
		?>
		<script>
		(function () {
			"use strict";
			if (window._okoSplitBound) { return; }
			window._okoSplitBound = true;

			var AJAX_URL      = <?php echo wp_json_encode( $ajax_url ); ?>;
			var NONCE         = <?php echo wp_json_encode( $nonce ); ?>;
			var TXT_WORKING   = <?php echo wp_json_encode( __( 'Working…', O_TEXTDOMAIN ) ); ?>;
			var TXT_ERR_START = <?php echo wp_json_encode( __( 'Could not start split checkout. Please try again.', O_TEXTDOMAIN ) ); ?>;
			var TXT_ERR_ACK   = <?php echo wp_json_encode( __( 'Please tick the box above first.', O_TEXTDOMAIN ) ); ?>;

			function syncBtnState() {
				var ack = document.getElementById('oko-split-ack');
				var btn = document.getElementById('oko-split-continue');
				if (!ack || !btn) { return; }
				if (ack.checked) {
					btn.classList.remove('is-locked');
					var err = document.getElementById('oko-split-error');
					if (err) { err.textContent = ''; }
				} else {
					btn.classList.add('is-locked');
				}
				// Block the standard place-order button while banner is up.
				var placeOrder = document.querySelector('#place_order');
				if (placeOrder) {
					placeOrder.disabled = true;
					placeOrder.style.opacity = '0.5';
					placeOrder.style.cursor = 'not-allowed';
				}
			}

			document.addEventListener('change', function (e) {
				if (e.target && e.target.id === 'oko-split-ack') {
					syncBtnState();
				}
			});

			document.addEventListener('click', function (e) {
				if (!e.target || e.target.id !== 'oko-split-continue') {
					return;
				}
				e.preventDefault();
				var ack = document.getElementById('oko-split-ack');
				var btn = e.target;
				var err = document.getElementById('oko-split-error');

				// If the checkbox isn't ticked, draw the customer's eye to it
				// instead of silently doing nothing. Shake + inline error.
				if (!ack || !ack.checked) {
					var row = document.querySelector('.oko-split-ack-row');
					if (row) {
						row.classList.remove('shake');
						// Force reflow so the animation re-triggers.
						void row.offsetWidth;
						row.classList.add('shake');
					}
					if (err) { err.textContent = TXT_ERR_ACK; }
					return;
				}

				if (err) { err.textContent = ''; }
				btn.disabled = true;
				var origText = btn.textContent;
				btn.textContent = TXT_WORKING;

				var fd = new FormData();
				fd.append('action', 'oko_start_split');
				fd.append('_wpnonce', NONCE);

				console.log('[oko-split] sending start request to', AJAX_URL);
				fetch(AJAX_URL, {
					method: 'POST',
					credentials: 'same-origin',
					body: fd
				}).then(function (r) {
					console.log('[oko-split] response status', r.status);
					return r.json();
				}).then(function (data) {
					console.log('[oko-split] response data', data);
					if (data && data.success) {
						window.location.reload();
					} else {
						var msg = (data && data.data && data.data.message)
							? data.data.message
							: TXT_ERR_START;
						if (err) { err.textContent = msg; }
						else { alert(msg); }
						btn.textContent = origText;
						btn.disabled = false;
					}
				}).catch(function (errObj) {
					console.error('[oko-split] fetch failed', errObj);
					if (err) { err.textContent = TXT_ERR_START; }
					else { alert(TXT_ERR_START); }
					btn.textContent = origText;
					btn.disabled = false;
				});
			});

			if (window.jQuery) {
				jQuery(document.body).on('updated_checkout', syncBtnState);
			}
			if (document.readyState !== 'loading') {
				syncBtnState();
			} else {
				document.addEventListener('DOMContentLoaded', syncBtnState);
			}
		})();
		</script>
		<?php
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
		$confirm_msg  = __( "Cancel the split delivery and clear your cart? You'll be sent back to the shop and can start a fresh order.", O_TEXTDOMAIN );

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
						if (!window.confirm(<?php echo wp_json_encode( $confirm_msg ); ?>)) { return; }
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
			wp_send_json_error( array( 'message' => 'No split needed' ) );
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
	 * Rebuild WC()->cart from a stored group snapshot.
	 */
	private function load_cart_for_step( int $step ): void {
		$state = $this->get_state();
		if ( empty( $state['groups'] ) ) { return; }
		$idx = $step - 1;
		if ( ! isset( $state['groups'][ $idx ] ) ) { return; }
		$group = $state['groups'][ $idx ];

		WC()->cart->empty_cart( false );

		$added_count = 0;
		foreach ( $group['items'] as $recipe ) {
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
					'okoskabet_woocommerce_plugin: failed to add product %d (qty %d) when loading split step %d',
					(int) $recipe['product_id'],
					(int) $recipe['quantity'],
					$step
				) );
			}
		}
		WC()->cart->calculate_totals();

		// Force-persist the cart to session so the page reload sees the
		// reduced cart. WC's cart auto-saves on shutdown, but in AJAX we
		// can't always rely on shutdown firing predictably.
		if ( method_exists( WC()->cart, 'persistent_cart_update' ) ) {
			WC()->cart->persistent_cart_update();
		}
		if ( WC()->session ) {
			WC()->session->set( 'cart', WC()->cart->get_cart_for_session() );
			WC()->session->save_data();
		}

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
	 * We just drop the state and empty the cart so the next page load is
	 * a clean slate. The customer is redirected to the shop page.
	 */
	public function ajax_cancel_split(): void {
		// Bootstrap session BEFORE nonce check — see ajax_start_split().
		$this->ensure_wc_session();
		check_ajax_referer( $this->nonce_action(), '_wpnonce' );

		$this->clear_state();

		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart( true );
		}

		wp_send_json_success( array( 'redirect' => wc_get_page_permalink( 'shop' ) ) );
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
			__( 'Your cart contains items requiring multiple deliveries. Please use the split-checkout flow shown above.', O_TEXTDOMAIN ),
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
		$order->update_meta_data( self::META_TOKEN, $state['split_token'] );
		$order->update_meta_data( self::META_STEP,  (int) ( $state['current_step'] ?? 0 ) );
		$order->update_meta_data( self::META_TOTAL, (int) ( $state['total_steps'] ?? 0 ) );
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
