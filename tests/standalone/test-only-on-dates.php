<?php
/**
 * "Delivery only on a specific day", and where that day is shown.
 *
 * The section pins its products to one date and to no other. Whether the
 * customer meets that date in the normal checkout or behind the pre-order
 * button is not something the shop ticks: a date further ahead than the normal
 * number of delivery days is a pre-order, a nearer one is simply the only day
 * on offer. The rule restricts either way, which is what the section heading
 * has always promised.
 *
 * @package okoskabet_woocommerce_plugin
 */

// phpcs:disable

use okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions;

const OKO_ONLY_CAT_XMAS  = 31;
const OKO_ONLY_CAT_FRESH = 32;

const OKO_ONLY_PRODUCT_BOX  = 301;
const OKO_ONLY_PRODUCT_MILK = 302;

/** A Christmas box and an everyday product to stand beside it. */
function oko_only_catalogue(): void {
	oko_test_add_product( OKO_ONLY_PRODUCT_BOX, 'Julekasse', array( OKO_ONLY_CAT_XMAS ) );
	oko_test_add_product( OKO_ONLY_PRODUCT_MILK, 'Mælk', array( OKO_ONLY_CAT_FRESH ) );
}

/**
 * One only-on rule for the Christmas box.
 *
 * @param string $date  The single date the box may be delivered on.
 * @param bool   $extend Write the retired per-row tick into the stored config,
 *                       the way a shop that saved this rule before would have.
 */
function oko_only_rule( string $date, bool $extend = false ): void {
	oko_test_set_exceptions( array(
		'only_on_enabled' => true,
		'only_on'         => array(
			array(
				'label'      => 'Julelevering',
				'date'       => $date,
				'enabled'    => true,
				'extend'     => $extend,
				'flip'       => false,
				'categories' => array( OKO_ONLY_CAT_XMAS ),
				'tags'       => array(),
			),
		),
	) );
}

/** The dates a cart is offered, normally or behind the pre-order button. */
function oko_only_dates( array $product_ids, bool $pre_order ): array {
	return ( new Delivery_Exceptions() )->filter_dates_for_cart(
		oko_test_days_ahead( 40 ),
		$product_ids,
		$pre_order
	);
}

it( 'offers a near date as the only ordinary delivery day', function () {
	oko_only_catalogue();
	oko_test_set_merchant_days( 7 );
	$day = oko_test_date( 3 );
	oko_only_rule( $day );

	assert_same( array( $day ), oko_only_dates( array( OKO_ONLY_PRODUCT_BOX ), false ), 'that day and no other' );
	assert_same( array(), oko_only_dates( array( OKO_ONLY_PRODUCT_BOX ), true ), 'nothing to pre-order' );
	assert_false(
		Delivery_Exceptions::cart_has_pre_order_days( array( OKO_ONLY_PRODUCT_BOX ) ),
		'so no pre-order button'
	);
} );

it( 'keeps a far-off date out of the normal checkout and behind the button', function () {
	oko_only_catalogue();
	oko_test_set_merchant_days( 7 );
	$day = oko_test_date( 30 );
	oko_only_rule( $day );

	assert_same( array(), oko_only_dates( array( OKO_ONLY_PRODUCT_BOX ), false ), 'no ordinary day fits' );
	assert_same( array( $day ), oko_only_dates( array( OKO_ONLY_PRODUCT_BOX ), true ), 'the pre-order day' );
	assert_true(
		Delivery_Exceptions::cart_has_pre_order_days( array( OKO_ONLY_PRODUCT_BOX ) ),
		'so the button is there to press'
	);
} );

it( 'never lets the products travel on another day, however wide the window', function () {
	oko_only_catalogue();
	oko_test_set_merchant_days( 7 );
	$day = oko_test_date( 30 );
	// The section's own display override used to be how a far date was made
	// reachable at all. It must not turn the rule back into "this day too".
	oko_test_set_exceptions( array(
		'only_on_enabled'     => true,
		'only_on_limit_mode'  => 'special',
		'only_on_limit_value' => 100,
		'only_on'             => array(
			array( 'label' => 'Jul', 'date' => $day, 'enabled' => true, 'flip' => false, 'categories' => array( OKO_ONLY_CAT_XMAS ), 'tags' => array() ),
		),
	) );

	assert_same( array(), oko_only_dates( array( OKO_ONLY_PRODUCT_BOX ), false ), 'still no ordinary day' );
	assert_same( array( $day ), oko_only_dates( array( OKO_ONLY_PRODUCT_BOX ), true ), 'still the one day' );
} );

it( 'reads a rule saved with the retired tick as the restriction it always said it was', function () {
	oko_only_catalogue();
	oko_test_set_merchant_days( 7 );
	$day = oko_test_date( 30 );
	oko_only_rule( $day, true );

	assert_same( array(), oko_only_dates( array( OKO_ONLY_PRODUCT_BOX ), false ), 'the tick no longer opens the normal days' );
	assert_same( array( $day ), oko_only_dates( array( OKO_ONLY_PRODUCT_BOX ), true ), 'and the day is a pre-order' );
} );

it( 'leaves a basket of box and milk with no day they share', function () {
	oko_only_catalogue();
	oko_test_set_merchant_days( 7 );
	oko_only_rule( oko_test_date( 30 ) );

	// This is what puts the split offer in front of the customer: the milk
	// cannot wait for the box's date, and the box cannot come sooner.
	$cart = array( OKO_ONLY_PRODUCT_BOX, OKO_ONLY_PRODUCT_MILK );
	assert_same( array(), oko_only_dates( $cart, false ), 'nothing ordinary suits both' );
	assert_same( array( oko_test_date( 30 ) ), oko_only_dates( $cart, true ), 'and pre-ordering is the box\'s day' );

	// The milk on its own is untouched by a rule that never mentions it.
	assert_true( count( oko_only_dates( array( OKO_ONLY_PRODUCT_MILK ), false ) ) > 1, 'milk keeps its days' );
} );

it( 'turns around with the flip tick, which is the only tick left on a row', function () {
	oko_only_catalogue();
	oko_test_set_merchant_days( 7 );
	$day = oko_test_date( 3 );
	oko_test_set_exceptions( array(
		'only_on_enabled' => true,
		'only_on'         => array(
			array( 'label' => 'Alt andet end jul', 'date' => $day, 'enabled' => true, 'flip' => true, 'categories' => array( OKO_ONLY_CAT_XMAS ), 'tags' => array() ),
		),
	) );

	assert_same( array( $day ), oko_only_dates( array( OKO_ONLY_PRODUCT_MILK ), false ), 'the milk is the one pinned' );
	assert_true( count( oko_only_dates( array( OKO_ONLY_PRODUCT_BOX ), false ) ) > 1, 'and the box is left alone' );
} );

it( 'follows the shop display window when the shop has set one', function () {
	oko_only_catalogue();
	// A merchant window of 3 days would call day 10 a pre-order; the shop's own
	// display setting of 14 days says it is an ordinary delivery day.
	oko_test_set_merchant_days( 3 );
	$day = oko_test_date( 10 );
	oko_test_set_exceptions( array(
		'only_on_enabled' => true,
		'display_mode'    => 'window',
		'display_value'   => 14,
		'only_on'         => array(
			array( 'label' => 'Jul', 'date' => $day, 'enabled' => true, 'flip' => false, 'categories' => array( OKO_ONLY_CAT_XMAS ), 'tags' => array() ),
		),
	) );

	assert_same( array( $day ), oko_only_dates( array( OKO_ONLY_PRODUCT_BOX ), false ), 'inside the window, so ordinary' );
	assert_same( array(), oko_only_dates( array( OKO_ONLY_PRODUCT_BOX ), true ), 'and nothing to pre-order' );
} );
