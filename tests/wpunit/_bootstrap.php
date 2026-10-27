<?php
// Here you can initialize variables that will be available to your tests

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;

// Which order storage this run uses. The default is WordPress's posts table;
// `OKO_TEST_HPOS=yes` runs the same tests on WooCommerce's own order tables
// (HPOS). CI runs the suite once each way — see .github/workflows/tests.yml.
// OrderStorageTest checks that the run is on the storage it says it is.
//
// Set here, before the first test opens its transaction: creating the tables
// would commit it. Sync stays off, so an order lives in one storage only and a
// lookup that works on just one of them cannot pass by reading the copy.
// A test that needs a particular storage still sets it itself — see
// WebhookOrderReferenceCase.
wc_get_container()->get( DataSynchronizer::class )->create_database_tables();
update_option( CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION, 'yes' === getenv( 'OKO_TEST_HPOS' ) ? 'yes' : 'no' );
update_option( DataSynchronizer::ORDERS_DATA_SYNC_ENABLED_OPTION, 'no' );
