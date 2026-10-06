<?php
/**
 * Turning a delivery-exception rule around.
 *
 * A rule normally names the products it governs by category and tag. Ticked,
 * `flip` makes it govern exactly the others — which is how a shop says
 * "Wednesday is for everything that isn't frost" without listing the rest of
 * the catalogue. Every family that picks products has the tick, so every family
 * is checked here, including the one that matters most and is easiest to get
 * wrong: a rule with nothing picked at all.
 *
 * @package okoskabet_woocommerce_plugin
 */

// phpcs:disable

use okoskabet_woocommerce_plugin\Integrations\Delivery_Exceptions;

const OKO_CAT_FROST = 11;
const OKO_CAT_DRY   = 12;
const OKO_TAG_BULKY = 21;

const OKO_PRODUCT_FROZEN_PEAS = 101;
const OKO_PRODUCT_RYE_BREAD   = 102;

/** A frost product and a non-frost product, which is all these tests need. */
function oko_flip_catalogue(): void {
	oko_test_add_product( OKO_PRODUCT_FROZEN_PEAS, 'Frosne ærter', array( OKO_CAT_FROST ) );
	oko_test_add_product( OKO_PRODUCT_RYE_BREAD, 'Rugbrød', array( OKO_CAT_DRY ) );
}

/** The dates a single product could be delivered on, over the next fortnight. */
function oko_flip_dates_for( int $product_id ): array {
	$window = oko_test_days_ahead( 14 );
	return ( new Delivery_Exceptions() )->filter_dates_for_cart( $window, array( $product_id ) );
}

/** Are all the given dates a Wednesday? */
function oko_flip_all_wednesday( array $dates ): bool {
	foreach ( $dates as $date ) {
		if ( ( new DateTimeImmutable( $date, wp_timezone() ) )->format( 'w' ) !== '3' ) {
			return false;
		}
	}
	return ! empty( $dates );
}

describe( 'Delivery exceptions: "Gælder alle andre varer end de valgte"' );

// -------------------------------------------------------------------- weekdays

it( 'a weekday rule without the flip restricts only the chosen category', function () {
	oko_flip_catalogue();
	oko_test_set_exceptions( array(
		'weekdays_enabled' => true,
		'weekdays'         => array(
			3 => array( 'enabled' => true, 'flip' => false, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ),
		),
	) );

	assert_true( oko_flip_all_wednesday( oko_flip_dates_for( OKO_PRODUCT_FROZEN_PEAS ) ), 'frost is Wednesday-only' );
	assert_same( 14, count( oko_flip_dates_for( OKO_PRODUCT_RYE_BREAD ) ), 'bread is untouched' );
} );

it( 'a flipped weekday rule restricts everything BUT the chosen category', function () {
	oko_flip_catalogue();
	oko_test_set_exceptions( array(
		'weekdays_enabled' => true,
		'weekdays'         => array(
			3 => array( 'enabled' => true, 'flip' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ),
		),
	) );

	assert_same( 14, count( oko_flip_dates_for( OKO_PRODUCT_FROZEN_PEAS ) ), 'frost is untouched' );
	assert_true( oko_flip_all_wednesday( oko_flip_dates_for( OKO_PRODUCT_RYE_BREAD ) ), 'bread is Wednesday-only' );
} );

it( 'a flipped weekday rule matching on a tag spares the tagged products', function () {
	oko_test_add_product( OKO_PRODUCT_FROZEN_PEAS, 'Frosne ærter', array(), array( OKO_TAG_BULKY ) );
	oko_test_add_product( OKO_PRODUCT_RYE_BREAD, 'Rugbrød', array(), array() );
	oko_test_set_exceptions( array(
		'weekdays_enabled' => true,
		'weekdays'         => array(
			3 => array( 'enabled' => true, 'flip' => true, 'categories' => array(), 'tags' => array( OKO_TAG_BULKY ) ),
		),
	) );

	assert_same( 14, count( oko_flip_dates_for( OKO_PRODUCT_FROZEN_PEAS ) ), 'the tagged product is untouched' );
	assert_true( oko_flip_all_wednesday( oko_flip_dates_for( OKO_PRODUCT_RYE_BREAD ) ), 'the untagged product is Wednesday-only' );
} );

// --------------------------------------------------------------------- only_on

it( 'a flipped single-day rule pins everything BUT the chosen category to that day', function () {
	oko_flip_catalogue();
	$the_day = oko_test_date( 5 );
	oko_test_set_exceptions( array(
		'only_on_enabled' => true,
		'only_on'         => array(
			array( 'label' => 'Julelevering', 'date' => $the_day, 'enabled' => true, 'flip' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ),
		),
	) );

	assert_same( array( $the_day ), oko_flip_dates_for( OKO_PRODUCT_RYE_BREAD ), 'bread is pinned to the day' );
	assert_same( 14, count( oko_flip_dates_for( OKO_PRODUCT_FROZEN_PEAS ) ), 'frost is untouched' );
} );

// ------------------------------------------------------------------ from_until

it( 'a flipped from/until rule opens its window for everything BUT the chosen category', function () {
	oko_flip_catalogue();
	oko_test_set_exceptions( array(
		'from_until_enabled' => true,
		'from_until'         => array(
			array(
				'label'      => 'Sommervarer',
				'from'       => oko_test_date( 3 ),
				'until'      => oko_test_date( 6 ),
				'enabled'    => true,
				'extend'     => false,
				'flip'       => true,
				'categories' => array( OKO_CAT_FROST ),
				'tags'       => array(),
			),
		),
	) );

	assert_same(
		array( oko_test_date( 3 ), oko_test_date( 4 ), oko_test_date( 5 ), oko_test_date( 6 ) ),
		oko_flip_dates_for( OKO_PRODUCT_RYE_BREAD ),
		'bread is held to the window'
	);
	assert_same( 14, count( oko_flip_dates_for( OKO_PRODUCT_FROZEN_PEAS ) ), 'frost is untouched' );
} );

// ---------------------------------------------------------------------- cutoff

it( 'a flipped cutoff rule closes the near days for everything BUT the chosen category', function () {
	oko_flip_catalogue();
	// Three days' notice, and the deadline for each of those days passed at
	// 00:01 — so the next three days are gone for whatever the rule covers.
	oko_test_set_exceptions( array(
		'cutoff_enabled' => true,
		'cutoff_rules'   => array(
			array( 'label' => 'Friskvarer', 'days' => 3, 'time' => '00:01', 'enabled' => true, 'flip' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ),
		),
	) );

	$bread = oko_flip_dates_for( OKO_PRODUCT_RYE_BREAD );
	assert_false( in_array( oko_test_date( 1 ), $bread, true ), 'tomorrow is closed for bread' );
	assert_true( in_array( oko_test_date( 4 ), $bread, true ), 'the far days stay open for bread' );

	assert_same( 14, count( oko_flip_dates_for( OKO_PRODUCT_FROZEN_PEAS ) ), 'frost is untouched' );
} );

// ------------------------------------------------- the empty selection, flipped

it( 'a flipped rule with nothing chosen covers nothing, rather than the whole shop', function () {
	oko_flip_catalogue();
	oko_test_set_exceptions( array(
		'weekdays_enabled' => true,
		'weekdays'         => array(
			3 => array( 'enabled' => true, 'flip' => true, 'categories' => array(), 'tags' => array() ),
		),
	) );

	assert_same( 14, count( oko_flip_dates_for( OKO_PRODUCT_FROZEN_PEAS ) ), 'frost is untouched' );
	assert_same( 14, count( oko_flip_dates_for( OKO_PRODUCT_RYE_BREAD ) ), 'bread is untouched' );
} );

it( 'the same floor holds for a flipped single-day rule with nothing chosen', function () {
	oko_flip_catalogue();
	oko_test_set_exceptions( array(
		'only_on_enabled' => true,
		'only_on'         => array(
			array( 'label' => 'Tom regel', 'date' => oko_test_date( 5 ), 'enabled' => true, 'flip' => true, 'categories' => array(), 'tags' => array() ),
		),
	) );

	assert_same( 14, count( oko_flip_dates_for( OKO_PRODUCT_RYE_BREAD ) ), 'bread is untouched' );
} );

it( 'the same floor holds for a flipped from/until rule with nothing chosen', function () {
	oko_flip_catalogue();
	oko_test_set_exceptions( array(
		'from_until_enabled' => true,
		'from_until'         => array(
			array( 'label' => 'Tom regel', 'from' => oko_test_date( 3 ), 'until' => oko_test_date( 6 ), 'enabled' => true, 'extend' => false, 'flip' => true, 'categories' => array(), 'tags' => array() ),
		),
	) );

	assert_same( 14, count( oko_flip_dates_for( OKO_PRODUCT_RYE_BREAD ) ), 'bread is untouched' );
} );

it( 'the same floor holds for a flipped cutoff rule with nothing chosen', function () {
	oko_flip_catalogue();
	oko_test_set_exceptions( array(
		'cutoff_enabled' => true,
		'cutoff_rules'   => array(
			array( 'label' => 'Tom regel', 'days' => 3, 'time' => '00:01', 'enabled' => true, 'flip' => true, 'categories' => array(), 'tags' => array() ),
		),
	) );

	assert_true( in_array( oko_test_date( 1 ), oko_flip_dates_for( OKO_PRODUCT_RYE_BREAD ), true ), 'tomorrow is still open' );
} );

// ---------------------------------------------------------- the cart-wide trap

/** A fortnight of candidate dates. */
function oko_flip_window(): array {
	$window = array();
	return oko_test_days_ahead( 14 );
}

it( 'a flipped weekday rule bites when the cart holds ONE product outside the selection', function () {
	oko_flip_catalogue();
	oko_test_set_exceptions( array(
		'weekdays_enabled' => true,
		'weekdays'         => array(
			3 => array( 'enabled' => true, 'flip' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ),
		),
	) );

	// Asked about the pair, the flipped rule must still bite: the bread is
	// covered by it, and the frost in the basket does not buy the bread out.
	$both = ( new Delivery_Exceptions() )->filter_dates_for_cart(
		oko_flip_window(),
		array( OKO_PRODUCT_FROZEN_PEAS, OKO_PRODUCT_RYE_BREAD )
	);

	assert_true( oko_flip_all_wednesday( $both ), 'the basket is Wednesday-only' );
} );

it( 'a flipped single-day rule bites on a mixed cart, rather than being pooled away', function () {
	oko_flip_catalogue();
	$the_day = oko_test_date( 5 );
	oko_test_set_exceptions( array(
		'only_on_enabled' => true,
		'only_on'         => array(
			array( 'label' => 'Julelevering', 'date' => $the_day, 'enabled' => true, 'flip' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ),
		),
	) );

	// The single-day and from/until families ask whether a rule touches the
	// cart at all. Answering that from the cart's pooled categories — "does
	// this basket carry the frost category?" — inverts to "no product here is
	// outside frost", which is a different and wrong question: the frost in
	// the basket would buy the bread out of a rule aimed squarely at it.
	assert_same(
		array( $the_day ),
		( new Delivery_Exceptions() )->filter_dates_for_cart(
			oko_flip_window(),
			array( OKO_PRODUCT_FROZEN_PEAS, OKO_PRODUCT_RYE_BREAD )
		),
		'the basket is pinned to the day'
	);
} );

it( 'a flipped from/until rule bites on a mixed cart too', function () {
	oko_flip_catalogue();
	oko_test_set_exceptions( array(
		'from_until_enabled' => true,
		'from_until'         => array(
			array( 'from' => oko_test_date( 3 ), 'until' => oko_test_date( 6 ), 'enabled' => true, 'extend' => false, 'flip' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ),
		),
	) );

	assert_same(
		array( oko_test_date( 3 ), oko_test_date( 4 ), oko_test_date( 5 ), oko_test_date( 6 ) ),
		( new Delivery_Exceptions() )->filter_dates_for_cart(
			oko_flip_window(),
			array( OKO_PRODUCT_FROZEN_PEAS, OKO_PRODUCT_RYE_BREAD )
		),
		'the basket is held to the window'
	);
} );

// ------------------------------------------------------------ saved and reread

it( 'the flip survives a round trip through the stored configuration', function () {
	oko_test_set_exceptions( array(
		'weekdays_enabled' => true,
		'weekdays'         => array(
			3 => array( 'enabled' => true, 'flip' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ),
		),
		'only_on_enabled'    => true,
		'only_on'            => array( array( 'date' => oko_test_date( 5 ), 'enabled' => true, 'flip' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ) ),
		'from_until_enabled' => true,
		'from_until'         => array( array( 'from' => oko_test_date( 3 ), 'enabled' => true, 'flip' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ) ),
		'cutoff_enabled'     => true,
		'cutoff_rules'       => array( array( 'days' => 2, 'time' => '09:00', 'enabled' => true, 'flip' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ) ),
	) );

	$config = Delivery_Exceptions::get_config();
	assert_true( $config['weekdays'][3]['flip'], 'weekday flip' );
	assert_true( $config['only_on'][0]['flip'], 'only_on flip' );
	assert_true( $config['from_until'][0]['flip'], 'from_until flip' );
	assert_true( $config['cutoff_rules'][0]['flip'], 'cutoff flip' );
} );

it( 'a rule saved before the flip existed keeps meaning the chosen products', function () {
	oko_test_set_exceptions( array(
		'weekdays_enabled' => true,
		'weekdays'         => array(
			3 => array( 'enabled' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ),
		),
		'only_on_enabled'  => true,
		'only_on'          => array( array( 'date' => oko_test_date( 5 ), 'enabled' => true, 'categories' => array( OKO_CAT_FROST ), 'tags' => array() ) ),
	) );

	$config = Delivery_Exceptions::get_config();
	assert_false( $config['weekdays'][3]['flip'], 'weekday flip defaults off' );
	assert_false( $config['only_on'][0]['flip'], 'only_on flip defaults off' );
} );
