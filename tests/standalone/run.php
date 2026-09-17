<?php
/**
 * Every standalone test the plugin has. Run it with:
 *
 *     php tests/standalone/run.php
 *
 * No database, no WordPress, no WooCommerce — see bootstrap.php for why that
 * is possible and where the line is.
 *
 * @package okoskabet_woocommerce_plugin
 */

// phpcs:disable

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/test-delivery-exception-flip.php';
require_once __DIR__ . '/test-split-checkout.php';

exit( oko_test_summary() );
