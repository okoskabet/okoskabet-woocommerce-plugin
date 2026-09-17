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

function oko_split(): Split_Checkout {
	return new Split_Checkout();
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
		assert_contains( $option['date_label'], $option['text'], 'the date is in the sentence' );
		assert_contains( 'den ', $option['date_label'], 'a Danish date' );
		return;
	}
	fail( 'no option that gives up the milk' );
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
