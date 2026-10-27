<?php

$env = parse_ini_file('tests/_envs/.env');

if ( isset($env["DB_NAME"]) ) {
	define('DB_NAME', $env["DB_NAME"]);
}

$dbuser = '';
if ( isset($env["WP_TESTS_DB_USER"]) ) {
	$dbuser = $env["WP_TESTS_DB_USER"];
}

if ( empty( $dbuser ) && isset( $env["DB_USER"] ) ) {
	$dbuser = $env["DB_USER"];
}

if ( isset($env["DB_USER"]) ) {
	define('DB_USER', $dbuser);
}

$dbpass = '';
if ( isset($env["WP_TESTS_DB_PASSWORD"]) ) {
	$dbpass = $env["WP_TESTS_DB_PASSWORD"];
}

if ( empty( $dbpass ) && isset( $env["DB_PASSWORD"] ) ) {
	$dbpass = $env["DB_PASSWORD"];
}

define('DB_PASSWORD', $dbpass);

if ( isset($env["DB_HOST"]) ) {
	define('DB_HOST', $env['DB_HOST']);
}

define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

$table_prefix = 'wp_';

define( 'DOING_AJAX', false );

// WP_DEBUG stays on (codeception.dist.yml), but nothing is printed. A notice
// written to stdout while WordPress boots counts as output, and every later
// setcookie() in the run then fails with "headers already sent" — the
// WooCommerce session cookie in SplitCheckoutCouponsTest and PreOrderModeTest.
// Since WP 6.7 there is such a notice at every boot: the update checker in
// okoskabet-woocommerce-plugin.php registers its cron before `init`, and
// WooCommerce's cron_schedules filter then asks for a translation too early.
// Turning the display off here touches only the tests; moving the update
// checker would change what every live shop runs. The notices still reach
// PHP's error log, and a test's own notices still fail that test.
define( 'WP_DEBUG_DISPLAY', false );
