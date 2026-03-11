# Changelog

All notable changes to the Økoskabet WooCommerce Plugin will be documented in this file.

## 1.2.2 - 2026-03-05

= Housekeeping =

* Removed unused boilerplate AJAX endpoints that were publicly accessible
* Removed unused boilerplate WP-CLI command
* Removed demo metabox that was registered on a non-existent post type
* Removed unused enqueue stubs and a boilerplate second settings tab
* Cleaned up commented-out demo code from the settings page

## 1.2.1 - 2026-03-05

= Bug Fixes =

* Fixed a fatal error (TypeError) caused by an unused boilerplate cron task on PHP 8.1+

## 1.2.0 - 2026-03-03

= Important =

* Minimum PHP version is now 8.1 (previously 7.4, which has been end-of-life since November 2022)

= Security =

* Fixed an issue where the API key was exposed in REST API responses to the browser
* Fixed a potential cross-site scripting (XSS) issue in the checkout page

= Performance =

* Replaced all direct cURL calls with the WordPress HTTP API for better reliability and error handling
* Fixed a critical issue where API calls to Økoskabet had no timeout, which could cause the site to hang if the API was unresponsive
* Improved plugin startup performance by caching request context detection
* Added proper version numbers to checkout scripts and styles for correct cache busting

= Compatibility =

* Added support for WooCommerce High-Performance Order Storage (HPOS)
* Fixed PHP 8.2 deprecation warnings for dynamic class properties on shipping methods
* REST API endpoints now return proper response objects instead of raw arrays
* Fixed the PHP version check so that the minimum version itself is correctly accepted

= Bug Fixes =

* Fixed order customer note not being saved correctly in some cases
* Fixed special characters in addresses not being properly encoded in API requests
* Fixed a style handle name collision between Mapbox JS and CSS assets
* Settings are now loaded consistently through the filterable helper function

## 1.1.42

* Previous release
