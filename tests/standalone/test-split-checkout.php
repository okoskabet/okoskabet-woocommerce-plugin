<?php
/**
 * Split checkout: detecting the conflict, grouping the basket, and working out
 * what the customer would have to give up to keep one delivery.
 *
 * @package okoskabet_woocommerce_plugin
 */

// phpcs:disable

use okoskabet_woocommerce_plugin\Integrations\Split_Checkout;

const OKO_SPLIT_CAT_MON = 31;
const OKO_SPLIT_CAT_WED = 32;
const OKO_SPLIT_CAT_FRI = 33;

const OKO_SPLIT_MILK   = 201; // Monday
const OKO_SPLIT_BREAD  = 202; // Wednesday
const OKO_SPLIT_CHEESE = 203; // Friday
const OKO_SPLIT_APPLES = 204; // no rules at all

/**
 * A shop where three categories each have their own delivery weekday, plus one
 * product with no rules on it that can therefore travel on any of them.
 */
function oko_split_weekday_shop(): void {
	oko_test_add_product( OKO_SPLIT_MILK, 'Mælk', array( OKO_SPLIT_CAT_MON ) );
	oko_test_add_product( OKO_SPLIT_BREAD, 'Brød', array( OKO_SPLIT_CAT_WED ) );
	oko_test_add_product( OKO_SPLIT_CHEESE, 'Ost', array( OKO_SPLIT_CAT_FRI ) );
	oko_test_add_product( OKO_SPLIT_APPLES, 'Æbler', array() );

	oko_test_set_exceptions( array(
		'weekdays_enabled' => true,
		'weekdays'         => array(
			1 => array( 'enabled' => true, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_MON ), 'tags' => array() ),
			3 => array( 'enabled' => true, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_WED ), 'tags' => array() ),
			5 => array( 'enabled' => true, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_FRI ), 'tags' => array() ),
		),
	) );
}

function oko_split(): Oko_Test_Split_Checkout {
	return new Oko_Test_Split_Checkout();
}

/** The product names in a group, sorted so the assertion does not mind order. */
function oko_split_names( array $group ): array {
	$names = $group['product_names'];
	sort( $names );
	return $names;
}

describe( 'Split checkout: detection and grouping' );

it( 'leaves a basket alone when one day carries all of it', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_APPLES ) );

	assert_same( array(), oko_split()->compute_split_groups(), 'no split' );
	assert_same( 1, count( oko_split()->compute_delivery_groups() ), 'one delivery group' );
} );

it( 'does no work at all when the shop has no delivery rules', function () {
	oko_test_add_product( OKO_SPLIT_MILK, 'Mælk', array( OKO_SPLIT_CAT_MON ) );
	oko_test_add_product( OKO_SPLIT_BREAD, 'Brød', array( OKO_SPLIT_CAT_WED ) );
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	assert_same( array(), oko_split()->compute_delivery_groups(), 'nothing to group' );
} );

it( 'splits a basket whose items share no delivery day', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	$groups = oko_split()->compute_split_groups();
	assert_same( 2, count( $groups ), 'two deliveries' );
	assert_same( array( 'Mælk' ), oko_split_names( $groups[0] ) );
	assert_same( array( 'Brød' ), oko_split_names( $groups[1] ) );
} );

it( 'puts the groups in the order they will happen', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_BREAD, 'b' => OKO_SPLIT_MILK ) );

	$groups = oko_split()->compute_split_groups();
	assert_true(
		$groups[0]['suggested_date'] < $groups[1]['suggested_date'],
		'the earlier delivery comes first'
	);
} );

it( 'offers each group the soonest day it can have, not just any day that fits', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	// Every Monday in the year ahead suits the milk equally well as far as the
	// rules are concerned. The customer is being shown one of them and asked to
	// commit, so it has to be the next one — a date eleven months out fits the
	// rules and is no use to anybody.
	$dates = array();
	foreach ( oko_split()->compute_split_groups() as $group ) {
		$dates[] = $group['suggested_date'];
	}
	sort( $dates );

	$expected = array( oko_test_next_weekday( 1 ), oko_test_next_weekday( 3 ) );
	sort( $expected );

	assert_same( $expected, $dates, 'the next Monday and the next Wednesday' );
} );

it( 'names a day each group can actually be delivered on', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	foreach ( oko_split()->compute_split_groups() as $group ) {
		$weekday = ( new DateTimeImmutable( $group['suggested_date'], wp_timezone() ) )->format( 'w' );
		$expected = $group['product_names'] === array( 'Mælk' ) ? '1' : '3';
		assert_same( $expected, $weekday, 'the group lands on its own weekday' );
	}
} );

it( 'uses as few deliveries as the basket allows, whatever order it was filled in', function () {
	oko_split_weekday_shop();

	// Æbler can travel on any day, so it must join one of the two existing
	// groups rather than opening a third — no matter where in the basket it
	// sits. Dropping each line into the first group it touches used to report
	// three deliveries here, depending on the order.
	foreach ( array(
		array( 'a' => OKO_SPLIT_MILK,  'b' => OKO_SPLIT_BREAD, 'c' => OKO_SPLIT_APPLES ),
		array( 'a' => OKO_SPLIT_APPLES, 'b' => OKO_SPLIT_MILK, 'c' => OKO_SPLIT_BREAD ),
		array( 'a' => OKO_SPLIT_MILK,  'b' => OKO_SPLIT_APPLES, 'c' => OKO_SPLIT_BREAD ),
	) as $i => $cart ) {
		oko_test_set_cart( $cart );
		assert_same( 2, count( oko_split()->compute_split_groups() ), "cart arrangement #$i" );
	}
} );

it( 'gives the same answer every time it is asked', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD, 'c' => OKO_SPLIT_CHEESE, 'd' => OKO_SPLIT_APPLES ) );

	$first = oko_split()->compute_split_groups();
	assert_same( $first, oko_split()->compute_split_groups(), 'stable across calls' );
	assert_same( 3, count( $first ), 'three weekdays, three deliveries' );
} );

it( 'gathers items with no delivery day at all into a group that has no date', function () {
	oko_test_add_product( OKO_SPLIT_MILK, 'Mælk', array( OKO_SPLIT_CAT_MON ) );
	oko_test_add_product( OKO_SPLIT_BREAD, 'Brød', array( OKO_SPLIT_CAT_WED ) );
	oko_test_set_exceptions( array(
		'weekdays_enabled'   => true,
		'weekdays'           => array(
			1 => array( 'enabled' => true, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_MON ), 'tags' => array() ),
		),
		// A window that closed yesterday: nothing in that category can go out.
		'from_until_enabled' => true,
		'from_until'         => array(
			array( 'from' => oko_test_date( -30 ), 'until' => oko_test_date( -1 ), 'enabled' => true, 'extend' => false, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_WED ), 'tags' => array() ),
		),
	) );
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	$groups = oko_split()->compute_split_groups();
	assert_same( 2, count( $groups ), 'two groups' );
	$last = $groups[ count( $groups ) - 1 ];
	assert_same( '', $last['suggested_date'], 'the undeliverable group has no date' );
	assert_same( array( 'Brød' ), oko_split_names( $last ) );
} );

describe( 'Split checkout: what to take out of the basket instead' );

it( 'offers one way out per delivery day, naming the products and the date', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	$options = oko_split()->compute_removal_options();
	assert_same( 2, count( $options ), 'one option per day' );

	$by_removed = array();
	foreach ( $options as $option ) {
		$by_removed[ implode( ',', $option['remove_names'] ) ] = $option;
	}

	assert_true( isset( $by_removed['Mælk'] ), 'an option that gives up the milk' );
	assert_true( isset( $by_removed['Brød'] ), 'an option that gives up the bread' );

	// Giving up the milk must leave a Wednesday, which is the bread's day.
	$without_milk = $by_removed['Mælk'];
	assert_same( '3', ( new DateTimeImmutable( $without_milk['date'], wp_timezone() ) )->format( 'w' ) );
	assert_same( array( 'Brød' ), $without_milk['keep_names'] );
} );

it( 'writes the offer the way a person would say it', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	foreach ( oko_split()->compute_removal_options() as $option ) {
		if ( $option['remove_names'] !== array( 'Mælk' ) ) {
			continue;
		}
		assert_contains( 'Remove Mælk,', $option['text'] );
		assert_contains( 'rest can be delivered together', $option['text'], 'it promises what is true' );

		// The bread has every Wednesday to choose from, so the offer must not
		// pick one of them on the customer's behalf. What it promises is that
		// the rest travels together, which is the part we actually know.
		assert_true( count( $option['possible_dates'] ) > 1, 'several days would do' );
		assert_false( strpos( $option['text'], $option['date_label'] ) !== false, 'so no day is named' );
		assert_false( strpos( $option['text'], 'den ' ) !== false, 'no Danish date either' );
		return;
	}
	fail( 'no option that gives up the milk' );
} );

it( 'names the day in an offer only when exactly one day is left', function () {
	// Each item pinned to a single day, and no other day will do — so the day
	// the offer names is a fact, not the soonest of several.
	oko_test_add_product( OKO_SPLIT_MILK, 'Mælk', array( OKO_SPLIT_CAT_MON ) );
	oko_test_add_product( OKO_SPLIT_BREAD, 'Brød', array( OKO_SPLIT_CAT_WED ) );
	$milk_day  = oko_test_date( 4 );
	$bread_day = oko_test_date( 6 );
	oko_test_set_exceptions( array(
		'only_on_enabled' => true,
		'only_on'         => array(
			array( 'date' => $milk_day, 'enabled' => true, 'extend' => false, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_MON ), 'tags' => array() ),
			array( 'date' => $bread_day, 'enabled' => true, 'extend' => false, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_WED ), 'tags' => array() ),
		),
	) );
	oko_test_set_delivery_days( array( $milk_day, $bread_day, oko_test_date( 8 ) ) );
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	$options = oko_split()->compute_removal_options();
	assert_true( count( $options ) > 0, 'there are offers' );

	foreach ( $options as $option ) {
		assert_same( 1, count( $option['possible_dates'] ), 'exactly one day left' );
		assert_contains( $option['date_label'], $option['text'], 'so the day is named' );
	}
} );

it( 'leaves the day out of a group heading when several days would do', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	// The day printed beside a group was only one of the days that would work,
	// and printing it read as a decision nobody had made.
	foreach ( oko_split()->compute_split_groups() as $i => $group ) {
		assert_true( count( $group['possible_dates'] ) > 1, 'several days would do' );

		// Exactly the dateless form and nothing else. Asking only that some
		// particular date spelling is absent lets any other spelling through,
		// and the heading formats dates however the shop has WordPress set up.
		assert_same( 'Delivery ' . ( $i + 1 ), oko_split_heading( $group, $i + 1 ) );
	}
} );

it( 'says the basket cannot travel together, without naming a day', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	// With the days gone from the list, the headline is what has to carry the
	// meaning — otherwise the banner never says what is actually wrong.
	assert_contains( 'cannot all be delivered on the same day', oko_split_render_banner() );
} );

it( 'counts an item that could travel either way as kept, not removed', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD, 'c' => OKO_SPLIT_APPLES ) );

	// Æbler has no rules, so every option must keep it: it is never in the way.
	foreach ( oko_split()->compute_removal_options() as $option ) {
		assert_false( in_array( 'Æbler', $option['remove_names'], true ), 'the apples are never the problem' );
		assert_true( in_array( 'Æbler', $option['keep_names'], true ), 'the apples always travel' );
	}
} );

it( 'puts the offer that costs the customer least first', function () {
	oko_split_weekday_shop();
	// Two Monday items against one Wednesday item: giving up the bread costs
	// one item, giving up the milk costs two.
	oko_test_add_product( 205, 'Smør', array( OKO_SPLIT_CAT_MON ) );
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => 205, 'c' => OKO_SPLIT_BREAD ) );

	$options = oko_split()->compute_removal_options();
	assert_same( array( 'Brød' ), $options[0]['remove_names'], 'the cheapest option first' );
	assert_same( 2, count( $options[1]['remove_names'] ), 'the dearer option second' );
} );

it( 'has nothing to offer when the basket already fits one day', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_APPLES ) );

	// There is a delivery day here, and it carries everything — so the only
	// "offer" that could be built would be to remove nothing, which reads as
	// "Fjern , så kan resten leveres sammen…" and is not a sentence.
	assert_same( array(), oko_split()->compute_removal_options() );
} );

it( 'never offers to empty the whole basket', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	foreach ( oko_split()->compute_removal_options() as $option ) {
		assert_true( count( $option['keep_names'] ) > 0, 'something always survives' );
		assert_true( count( $option['remove_names'] ) > 0, 'something is always given up' );
	}
} );

it( 'offers a way out even when a group can never be delivered', function () {
	oko_test_add_product( OKO_SPLIT_MILK, 'Mælk', array( OKO_SPLIT_CAT_MON ) );
	oko_test_add_product( OKO_SPLIT_BREAD, 'Brød', array( OKO_SPLIT_CAT_WED ) );
	oko_test_set_exceptions( array(
		'weekdays_enabled'   => true,
		'weekdays'           => array(
			1 => array( 'enabled' => true, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_MON ), 'tags' => array() ),
		),
		'from_until_enabled' => true,
		'from_until'         => array(
			array( 'from' => oko_test_date( -30 ), 'until' => oko_test_date( -1 ), 'enabled' => true, 'extend' => false, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_WED ), 'tags' => array() ),
		),
	) );
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	$options = oko_split()->compute_removal_options();
	assert_same( 1, count( $options ), 'the one Monday option' );
	assert_same( array( 'Brød' ), $options[0]['remove_names'], 'the undeliverable item is what goes' );
} );

it( 'sends the customer back to fresh options when the basket moved underneath them', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	// A day that is no longer one of the options — the basket or the shop's
	// rules changed under an open checkout. Refusing the stale choice is right:
	// those are not the items the customer agreed to give up any more.
	$_POST['date'] = oko_test_date( 300 );

	try {
		oko_split()->ajax_reduce_split();
		fail( 'the handler should have answered' );
	} catch ( Oko_Test_Json_Response $answer ) {
		// But refusing it with "prøv igen" on a page still showing the old
		// options is a dead end: trying again does the very same thing. Send
		// them back to the banner as it stands now, so "again" means something.
		assert_true( $answer->success, 'the customer is sent somewhere, not stopped' );
		assert_contains( 'kassen', (string) ( $answer->payload['redirect'] ?? '' ), 'back to the checkout' );
	}

	assert_same( array(), WC()->cart->removed, 'and nothing was taken out of the basket' );
	assert_true( count( $GLOBALS['oko_test_notices'] ) > 0, 'with a word about why it changed' );

	unset( $_POST['date'] );
} );

it( 'takes exactly the chosen option out of the basket', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD, 'c' => OKO_SPLIT_APPLES ) );

	$split   = oko_split();
	$options = $split->compute_removal_options();

	$chosen = null;
	foreach ( $options as $option ) {
		if ( $option['remove_names'] === array( 'Mælk' ) ) {
			$chosen = $option;
		}
	}
	assert_true( $chosen !== null, 'found the option that gives up the milk' );

	foreach ( $chosen['remove_keys'] as $key ) {
		WC()->cart->remove_cart_item( $key );
	}

	assert_same( array( 'a' ), WC()->cart->removed, 'only the milk left the basket' );
	assert_same( array(), $split->compute_split_groups(), 'the rest now fits one day' );
} );

describe( 'Split checkout: every date comes from Økoskabet, none from us' );

/**
 * Every date the customer would be shown — in the group list and in the
 * "remove these" offers alike.
 *
 * @return string[]
 */
function oko_split_shown_dates( $split ): array {
	$dates = array();
	foreach ( $split->compute_delivery_groups() as $group ) {
		if ( (string) $group['suggested_date'] !== '' ) {
			$dates[] = $group['suggested_date'];
		}
	}
	foreach ( $split->compute_removal_options() as $option ) {
		$dates[] = $option['date'];
		// The sentence the customer reads has to carry the same date as the
		// option behind it, or the banner and the button disagree.
		assert_contains( $option['date_label'], $option['text'], 'the offer names its own date' );
	}
	return $dates;
}

/** Nothing shown may be a day the delivery-day source did not offer. */
function oko_split_assert_dates_are_real( $split ): array {
	$offered = oko_test_delivery_days();
	$shown   = oko_split_shown_dates( $split );

	foreach ( $shown as $date ) {
		if ( ! in_array( $date, $offered, true ) ) {
			fail( sprintf(
				'the banner shows %s, which Økoskabet did not offer (it offered %s)',
				$date,
				implode( ', ', $offered )
			) );
		}
	}

	return $shown;
}

it( 'shows no date the shop does not actually deliver on', function () {
	oko_split_weekday_shop();

	// A real shop drives on a handful of days, not every day, and the soonest
	// day its rules allow is usually not one of them — lead time, a full van,
	// a holiday. Deliberately a week out, so code that builds its own calendar
	// and takes the nearest allowed day lands somewhere the van never goes.
	oko_test_set_delivery_days( array(
		oko_test_weekday_next_week( 1 ),
		oko_test_weekday_next_week( 3 ),
		oko_test_weekday_next_week( 5 ),
	) );
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD, 'c' => OKO_SPLIT_CHEESE ) );

	$shown = oko_split_assert_dates_are_real( oko_split() );
	assert_true( count( $shown ) > 0, 'the banner did show some dates' );
} );

it( 'never shows today just because no rule forbids it', function () {
	// Gaardmester's staging, Thursday 17 September 2026. The ice was
	// Thursday-only, so a rules-only calendar found today allowed and offered
	// "Levering 1 (17. september)" — a day the shop does not drive on. Pinning
	// one product's rule to today's own weekday reproduces that whatever day
	// the tests are run on.
	$today_weekday = oko_test_today_weekday();
	$other_weekday = ( $today_weekday + 2 ) % 7;

	oko_test_add_product( OKO_SPLIT_MILK, 'Mælk', array( OKO_SPLIT_CAT_MON ) );
	oko_test_add_product( OKO_SPLIT_BREAD, 'Brød', array( OKO_SPLIT_CAT_WED ) );
	oko_test_set_exceptions( array(
		'weekdays_enabled' => true,
		'weekdays'         => array(
			$today_weekday => array( 'enabled' => true, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_MON ), 'tags' => array() ),
			$other_weekday => array( 'enabled' => true, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_WED ), 'tags' => array() ),
		),
	) );

	// The shop's own days start next week, so today is allowed by the rules and
	// still not a delivery day.
	oko_test_set_delivery_days( array(
		oko_test_weekday_next_week( $today_weekday ),
		oko_test_weekday_next_week( $other_weekday ),
	) );
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	foreach ( oko_split_shown_dates( oko_split() ) as $date ) {
		assert_false( $date === oko_test_date( 0 ), 'today is not offered as a delivery day' );
	}
	oko_split_assert_dates_are_real( oko_split() );
} );

it( 'reproduces the Gaardmester basket: grønt on Tuesday, is on Thursday', function () {
	// Two weekday rules with no day in common, against the shop's real week.
	oko_test_add_product( OKO_SPLIT_MILK, 'Danske økologiske oxheart gulerødder', array( OKO_SPLIT_CAT_MON ) );
	oko_test_add_product( OKO_SPLIT_BREAD, 'Økologisk rabarber isvafler', array( OKO_SPLIT_CAT_WED ) );
	oko_test_set_exceptions( array(
		'weekdays_enabled' => true,
		'weekdays'         => array(
			2 => array( 'enabled' => true, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_MON ), 'tags' => array() ), // Tue
			4 => array( 'enabled' => true, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_WED ), 'tags' => array() ), // Thu
		),
	) );

	$tuesday  = oko_test_weekday_next_week( 2 );
	$thursday = oko_test_weekday_next_week( 4 );
	oko_test_set_delivery_days( array( $tuesday, oko_test_weekday_next_week( 3 ), $thursday, oko_test_weekday_next_week( 5 ) ) );
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	$groups = oko_split()->compute_split_groups();
	assert_same( 2, count( $groups ), 'two deliveries' );

	$by_date = array();
	foreach ( $groups as $group ) {
		$by_date[ $group['suggested_date'] ] = $group['product_names'];
	}
	assert_same( array( 'Danske økologiske oxheart gulerødder' ), $by_date[ $tuesday ] ?? null, 'the veg goes on the Tuesday' );
	assert_same( array( 'Økologisk rabarber isvafler' ), $by_date[ $thursday ] ?? null, 'the ice goes on the Thursday' );

	oko_split_assert_dates_are_real( oko_split() );
} );

it( 'draws no banner at all when Økoskabet cannot be asked', function () {
	oko_split_weekday_shop();
	oko_test_set_delivery_days( null ); // no postcode yet, or the API is down
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	// Better a checkout with no banner than a banner full of invented dates.
	assert_same( array(), oko_split()->compute_delivery_groups(), 'no groups' );
	assert_same( array(), oko_split()->compute_removal_options(), 'no offers' );
} );

it( 'says a group has no day rather than inventing one', function () {
	oko_split_weekday_shop();
	// The shop drives on Mondays and Wednesdays. Nothing the cheese is allowed
	// on (Fridays) is among them, so its group genuinely has no day.
	oko_test_set_delivery_days( array( oko_test_weekday_next_week( 1 ), oko_test_weekday_next_week( 3 ) ) );
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_CHEESE ) );

	$groups = oko_split()->compute_split_groups();
	$last   = $groups[ count( $groups ) - 1 ];
	assert_same( '', $last['suggested_date'], 'no date is made up for it' );
	assert_same( array( 'Ost' ), oko_split_names( $last ) );

	oko_split_assert_dates_are_real( oko_split() );
} );

it( 'asks the delivery-day source once per product, however often it is consulted', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	// Each miss is an HTTP round trip to Økoskabet, and one checkout render
	// consults this three times over.
	$split = oko_split();
	$split->compute_delivery_groups();
	$split->compute_removal_options();
	$split->compute_split_groups();

	assert_same( 2, count( array_unique( $split->asked ) ), 'two distinct products' );
} );

describe( 'Split checkout: a basket only half of which can be pre-ordered' );

const OKO_SPLIT_CAT_ICE = 34;

const OKO_SPLIT_CORNFLAKES = 211;
const OKO_SPLIT_ICE        = 212;
const OKO_SPLIT_PAK_CHOI   = 213;

/**
 * Gaardmester's basket. Cornflakes and Pak Choi are ordinary goods; the nougat
 * ispinde can be held until December. In an ordinary order all three share a
 * day, so nothing is wrong. Press Forudbestilling and only the ice has a day —
 * which is the case the checkout used to answer with an empty page and a fee.
 *
 * @return array{pre_order_day:string, normal_days:string[]}
 */
function oko_split_pre_order_shop(): array {
	$december = oko_test_date( 80 );

	oko_test_add_product( OKO_SPLIT_CORNFLAKES, 'Cornflakes', array() );
	oko_test_add_product( OKO_SPLIT_ICE, 'Nougat ispinde', array( OKO_SPLIT_CAT_ICE ) );
	oko_test_add_product( OKO_SPLIT_PAK_CHOI, 'Pak Choi', array( OKO_SPLIT_CAT_WED ) );

	oko_test_set_exceptions( array(
		// The ice is the only thing that can be pre-ordered, for one day.
		'only_on_enabled'  => true,
		'only_on'          => array(
			array( 'label' => 'Julelevering', 'date' => $december, 'enabled' => true, 'extend' => true, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_ICE ), 'tags' => array() ),
		),
		// Pak Choi only travels on Wednesdays, as it does on staging.
		'weekdays_enabled' => true,
		'weekdays'         => array(
			3 => array( 'enabled' => true, 'flip' => false, 'categories' => array( OKO_SPLIT_CAT_WED ), 'tags' => array() ),
		),
	) );

	$normal_days = array(
		oko_test_weekday_next_week( 2 ),
		oko_test_weekday_next_week( 3 ),
		oko_test_weekday_next_week( 5 ),
	);
	oko_test_set_delivery_days( array_merge( $normal_days, array( $december ) ) );

	return array( 'pre_order_day' => $december, 'normal_days' => $normal_days );
}

it( 'leaves an ordinary order alone when every item shares a day', function () {
	oko_split_pre_order_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_ICE, 'c' => OKO_SPLIT_PAK_CHOI ) );

	// Nothing is wrong with this basket until the customer asks to pre-order it.
	assert_same( array(), oko_split()->compute_split_groups(), 'no banner in an ordinary order' );
} );

it( 'raises the buttons when only part of the basket can be pre-ordered', function () {
	$shop = oko_split_pre_order_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_ICE, 'c' => OKO_SPLIT_PAK_CHOI ) );
	oko_test_set_pre_order( true );

	$groups = oko_split()->compute_split_groups();
	assert_same( 2, count( $groups ), 'a pre-order and an ordinary delivery' );

	$by_mode = array();
	foreach ( $groups as $group ) {
		$by_mode[ $group['mode'] ] = $group;
	}

	assert_true( isset( $by_mode['pre_order'] ), 'one part is a pre-order' );
	assert_true( isset( $by_mode['normal'] ), 'one part is an ordinary delivery' );

	assert_same( array( 'Nougat ispinde' ), $by_mode['pre_order']['product_names'], 'only the ice can be held' );
	assert_same( $shop['pre_order_day'], $by_mode['pre_order']['suggested_date'], 'on its pre-order day' );

	$ordinary = oko_split_names( $by_mode['normal'] );
	assert_same( array( 'Cornflakes', 'Pak Choi' ), $ordinary, 'the rest goes the ordinary way' );
	assert_true(
		in_array( $by_mode['normal']['suggested_date'], $shop['normal_days'], true ),
		'on one of the shop\'s ordinary days'
	);
} );

it( 'offers to remove exactly the items that cannot be pre-ordered', function () {
	$shop = oko_split_pre_order_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_ICE, 'c' => OKO_SPLIT_PAK_CHOI ) );
	oko_test_set_pre_order( true );

	$options = oko_split()->compute_removal_options();
	assert_same( 1, count( $options ), 'one way to keep the pre-order' );

	$names = $options[0]['remove_names'];
	sort( $names );
	assert_same( array( 'Cornflakes', 'Pak Choi' ), $names, 'the two that cannot be held' );
	assert_same( array( 'Nougat ispinde' ), $options[0]['keep_names'] );
	assert_same( $shop['pre_order_day'], $options[0]['date'] );

	// And it says pre-order, not delivery — the customer is choosing to keep a
	// pre-order, not to be delivered on 10 December.
	assert_contains( 'pre-ordered together for', $options[0]['text'] );
	assert_contains( $options[0]['date_label'], $options[0]['text'] );
} );

it( 'does not offer to quietly drop the customer out of the pre-order', function () {
	oko_split_pre_order_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_ICE, 'c' => OKO_SPLIT_PAK_CHOI ) );
	oko_test_set_pre_order( true );

	// "Remove the ice and the rest can be delivered on Wednesday" would be true
	// and would take the customer back out of the pre-order they asked for. The
	// way back is the ordinary-order button, not a line in this list.
	foreach ( oko_split()->compute_removal_options() as $option ) {
		assert_same( 'pre_order', $option['mode'] );
		assert_false( in_array( 'Nougat ispinde', $option['remove_names'], true ), 'the pre-orderable item is never the one to give up' );
	}
} );

it( 'names which part is a pre-order and which is an ordinary delivery', function () {
	oko_split_pre_order_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_ICE, 'c' => OKO_SPLIT_PAK_CHOI ) );
	oko_test_set_pre_order( true );

	$headings = array();
	foreach ( oko_split()->compute_split_groups() as $i => $group ) {
		$headings[ $group['mode'] ] = oko_split_heading( $group, $i + 1 );
	}

	assert_contains( 'Pre-order', $headings['pre_order'] ?? '', 'the held part says so' );
	assert_contains( 'Delivery', $headings['normal'] ?? '', 'the ordinary part says so' );
} );

it( 'keeps the day in a group heading when that is the only day', function () {
	// A pre-order is the obvious case: one day, and it is a fact.
	oko_split_pre_order_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_ICE, 'c' => OKO_SPLIT_PAK_CHOI ) );
	oko_test_set_pre_order( true );

	foreach ( oko_split()->compute_split_groups() as $i => $group ) {
		if ( $group['mode'] !== 'pre_order' ) {
			continue;
		}
		assert_same( 1, count( $group['possible_dates'] ), 'the one day it can be held for' );

		// The heading formats the day the way the shop has WordPress set up,
		// so what matters is that it carries one at all, not which wording.
		$heading = oko_split_heading( $group, $i + 1 );
		assert_contains( '(', $heading, 'so the heading names it' );
		assert_false( $heading === 'Pre-order ' . ( $i + 1 ), 'not the dateless form' );
		return;
	}
	fail( 'no pre-order group' );
} );

it( 'keeps every pre-order date inside the days Økoskabet offered', function () {
	oko_split_pre_order_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_ICE, 'c' => OKO_SPLIT_PAK_CHOI ) );
	oko_test_set_pre_order( true );

	oko_split_assert_dates_are_real( oko_split() );
} );

it( 'carries each step\'s kind of order through the split', function () {
	oko_split_pre_order_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_ICE, 'c' => OKO_SPLIT_PAK_CHOI ) );
	oko_test_set_pre_order( true );

	$groups = oko_split()->compute_split_groups();

	// Each order keeps its own kind, which is what decides its days and its
	// fee. A step that forgot it would offer the customer the wrong calendar.
	$modes = array();
	foreach ( $groups as $group ) {
		$modes[] = $group['mode'];
	}
	sort( $modes );
	assert_same( array( 'normal', 'pre_order' ), $modes );
} );

it( 'starts the split with the right items and the right kind in each step', function () {
	$shop = oko_split_pre_order_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_ICE, 'c' => OKO_SPLIT_PAK_CHOI ) );
	oko_test_set_pre_order( true );

	$split = oko_split();
	try {
		$split->ajax_start_split();
		fail( 'the handler should have answered' );
	} catch ( Oko_Test_Json_Response $answer ) {
		assert_true( $answer->success, 'the split started' );
	}

	$state = WC()->session->get( 'oko_split_state' );
	assert_same( 2, (int) $state['total_steps'], 'two steps' );
	assert_same( 1, (int) $state['current_step'], 'starting at the first' );

	// What is stored is what each step will be rebuilt from, so the kind of
	// order has to survive the round trip. Without it, step two would render
	// as whatever step one was and offer the wrong days.
	$steps = array();
	foreach ( $state['groups'] as $group ) {
		$steps[ $group['mode'] ] = $group;
	}

	assert_true( isset( $steps['pre_order'] ), 'a pre-order step was stored' );
	assert_true( isset( $steps['normal'] ), 'an ordinary step was stored' );
	assert_same( array( 'Nougat ispinde' ), $steps['pre_order']['product_names'] );
	assert_same( $shop['pre_order_day'], $steps['pre_order']['suggested_date'] );

	$ordinary = $steps['normal']['product_names'];
	sort( $ordinary );
	assert_same( array( 'Cornflakes', 'Pak Choi' ), $ordinary );

	// And the cart is now only what the first step is for.
	$in_cart = array();
	foreach ( WC()->cart->get_cart() as $line ) {
		$in_cart[] = (int) $line['product_id'];
	}
	sort( $in_cart );

	$expected = array();
	foreach ( $state['groups'][0]['items'] as $recipe ) {
		$expected[] = (int) $recipe['product_id'];
	}
	sort( $expected );

	assert_same( $expected, $in_cart, 'the cart holds step one and nothing else' );
} );

describe( 'Split checkout: a pre-order is a choice about this visit' );

it( 'starts a returning visitor in an ordinary order, whatever the old cookie says', function () {
	// Gaardmester, staging. The customer had chosen Forudbestilling on some
	// earlier visit and the cookie was still set. They came back with a
	// different basket — a galia melon and some rabarber isvafler — went to the
	// checkout, and landed straight in "kun en del af din kurv kan
	// forudbestilles", with the form hidden behind the banner and no way back.
	oko_split_pre_order_shop();
	oko_test_add_product( 214, 'Galia melon', array() );
	oko_test_set_cart( array( 'a' => 214, 'b' => OKO_SPLIT_ICE ) );

	oko_test_set_stale_pre_order_cookie();

	assert_false( oko_pre_order_checkout_requested(), 'a remembered cookie starts nothing' );
	assert_same( array(), oko_split()->compute_split_groups(), 'and so there is no banner' );

	// The same basket still splits when the customer asks on this page.
	oko_test_set_pre_order( true );
	assert_same( 2, count( oko_split()->compute_split_groups() ), 'asking on this page still works' );
} );

it( 'ignores a pre-order for a basket that has nothing to pre-order', function () {
	oko_split_pre_order_shop();
	// No ice: nothing here can be held, so the button is not even offered.
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_PAK_CHOI ) );

	oko_test_set_pre_order( true );

	assert_false( oko_pre_order_checkout_requested(), 'not a state this basket can be in' );
	assert_same( array(), oko_split()->compute_split_groups(), 'and no banner about it' );
} );

it( 'offers a way back out of the pre-order, to a checkout that needs no split', function () {
	oko_split_pre_order_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_ICE, 'c' => OKO_SPLIT_PAK_CHOI ) );

	oko_test_set_pre_order( true );
	assert_same( 2, count( oko_split()->compute_split_groups() ), 'the banner is up' );

	// The link the banner shows goes to the checkout without the pre-order.
	$way_back = oko_checkout_url_for_mode( false );
	assert_false( strpos( $way_back, 'oko_pre_order' ) !== false, 'it carries no pre-order' );

	// Following it: an ordinary order, and this basket fits one day, so the
	// banner is gone and the customer has their checkout form back.
	oko_test_set_pre_order( false );
	assert_false( oko_pre_order_checkout_requested(), 'out of the pre-order' );
	assert_same( array(), oko_split()->compute_split_groups(), 'and no banner left' );
	assert_same( 1, count( oko_split()->compute_delivery_groups() ), 'one ordinary delivery covers it' );
} );

it( 'puts that way back in the banner itself, where the form is hidden', function () {
	oko_split_pre_order_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_CORNFLAKES, 'b' => OKO_SPLIT_ICE, 'c' => OKO_SPLIT_PAK_CHOI ) );
	oko_test_set_pre_order( true );

	// The banner hides the checkout form, and the ordinary-order button lives
	// inside it. If the banner does not carry the way out, there is none.
	$banner = oko_split_render_banner();

	// The wording, not the class name: the stylesheet mentions the class on
	// every render, so looking for that would pass without any link at all.
	assert_contains( 'Choose an ordinary order instead', $banner, 'the banner offers a way out' );
	assert_contains( 'href="' . oko_checkout_url_for_mode( false ) . '"', $banner, 'pointing at an ordinary order' );
} );

it( 'does not offer that way out of an ordinary order it was never in', function () {
	oko_split_weekday_shop();
	oko_test_set_cart( array( 'a' => OKO_SPLIT_MILK, 'b' => OKO_SPLIT_BREAD ) );

	$banner = oko_split_render_banner();

	assert_contains( 'oko-split-banner', $banner, 'the banner is there' );
	assert_false( strpos( $banner, 'Choose an ordinary order instead' ) !== false, 'but nothing to leave' );
} );

describe( 'Split checkout: the wording on the buttons' );

it( 'says "i to" for two deliveries and counts honestly beyond that', function () {
	assert_same( 'Split the delivery in two', Split_Checkout::split_button_label( 2 ) );
	assert_same( 'Split into 3 deliveries', Split_Checkout::split_button_label( 3 ) );
	assert_same( 'Split into 4 deliveries', Split_Checkout::split_button_label( 4 ) );
} );

it( 'lets the shop write its own wording', function () {
	oko_test_set_settings( array(
		'_split_button_split_label'      => 'Del min levering op',
		'_split_button_split_label_many' => 'Del op i %d gange',
		'_split_button_reduce_label'     => 'Fjern varer i stedet',
	) );

	assert_same( 'Del min levering op', Split_Checkout::split_button_label( 2 ) );
	assert_same( 'Del op i 3 gange', Split_Checkout::split_button_label( 3 ) );
	assert_same( 'Fjern varer i stedet', Split_Checkout::reduce_button_label() );
} );

it( 'falls back to the built-in wording when the shop clears a field', function () {
	oko_test_set_settings( array(
		'_split_button_split_label'  => '   ',
		'_split_button_reduce_label' => '',
	) );

	assert_same( 'Split the delivery in two', Split_Checkout::split_button_label( 2 ) );
	assert_same( 'Empty from the basket', Split_Checkout::reduce_button_label() );
} );

it( 'survives a shop that drops the number from the wording', function () {
	oko_test_set_settings( array( '_split_button_split_label_many' => 'Flere leveringer' ) );

	assert_same( 'Flere leveringer', Split_Checkout::split_button_label( 3 ) );
} );

it( 'joins product names the way Danish reads', function () {
	assert_same( 'Mælk', Split_Checkout::format_name_list( array( 'Mælk' ) ) );
	assert_same( 'Mælk and Brød', Split_Checkout::format_name_list( array( 'Mælk', 'Brød' ) ) );
	assert_same( 'Mælk, Brød and Ost', Split_Checkout::format_name_list( array( 'Mælk', 'Brød', 'Ost' ) ) );
	assert_same( '', Split_Checkout::format_name_list( array() ) );
} );

describe( 'Split checkout: each part-order pays its own way' );

it( 'never touches shipping or fees, so a second order is charged like a first', function () {
	oko_test_set_settings( array( '_split_checkout_enabled' => 'on' ) );
	$split = new Split_Checkout();
	$split->initialize();

	// Each part-order is an ordinary WooCommerce order, and its cart works the
	// shipping and the packaging fee out from scratch. That is the point: two
	// deliveries are two vans and two boxes. If this class ever starts
	// listening to the hooks that decide those amounts, it has grown the power
	// to waive the second charge — which is the bug this test exists to catch.
	$forbidden = array(
		'woocommerce_cart_calculate_fees',
		'woocommerce_package_rates',
		'woocommerce_cart_needs_shipping',
		'woocommerce_cart_shipping_packages',
		'woocommerce_shipping_free_shipping_is_available',
		'woocommerce_calculated_total',
		'woocommerce_cart_get_total',
	);

	foreach ( $GLOBALS['oko_test_hooks'] as $registered ) {
		if ( in_array( $registered['hook'], $forbidden, true ) ) {
			fail( 'split checkout hooks ' . $registered['hook'] . ', which decides what an order is charged' );
		}
	}

	assert_true( count( $GLOBALS['oko_test_hooks'] ) > 0, 'the class did register its own hooks' );
} );

it( 'stays out of the way entirely until the shop switches it on', function () {
	oko_test_set_settings( array() );
	( new Split_Checkout() )->initialize();

	assert_same( array(), $GLOBALS['oko_test_hooks'], 'no hooks while the feature is off' );
	assert_false( Split_Checkout::is_feature_enabled() );
} );
