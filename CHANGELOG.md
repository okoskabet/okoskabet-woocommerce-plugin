# Changelog

## [1.2.0] - 2026-03-03

### Breaking Changes
- Minimum PHP version raised from 7.4 to 8.1 (PHP 7.4 has been EOL since November 2022)
- REST API responses for `/okoskabet/sheds` and `/okoskabet/home_delivery` no longer return the full plugin settings object — only public-facing settings are included

### Security
- **Fixed API key leaking to frontend**: REST endpoints (`get_sheds`, `get_home_delivery`) were returning the entire settings array, including the API key, in the response body. Now only a whitelist of safe settings is returned.
- **Fixed XSS in checkout JavaScript output**: Settings values were injected directly into an inline `<script>` tag without escaping. Now uses `wp_json_encode()` for safe serialization.

### Performance
- **Replaced all raw cURL calls with WordPress HTTP API** (`wp_remote_get`, `wp_remote_post`, `wp_remote_request`) across `functions.php` and `OkoRest.php`. This provides proper SSL verification, proxy support, timeout handling, and consistent error reporting via `WP_Error`.
- **Fixed zero-timeout on external API calls**: The shed and home delivery REST endpoints used `CURLOPT_TIMEOUT => 0`, meaning requests would hang indefinitely if the Økoskabet API was unresponsive. All external calls now have a 15-second timeout.
- **Cached WpContext determination**: `WpContext::determine()` was called on every `Context::request()` invocation (5–7 times during plugin init). Now cached after the first call.
- **Added proper asset versioning**: Checkout scripts and styles now use `O_VERSION` for cache-busting instead of `null`.
- **URL parameters properly encoded**: REST API proxy calls now use `add_query_arg()` instead of raw string concatenation, preventing issues with special characters in addresses.

### PHP 8.x Compatibility
- **Declared dynamic properties on shipping classes**: `WC_Hey_Okoskabet_Shipping_Method_Shed` and `WC_Hey_Okoskabet_Shipping_Method_Home` now declare `$cost_value`, `$cost_discount`, `$cost_discount_limit`, and `$cost_free_limit` as proper class properties. Dynamic properties are deprecated in PHP 8.2 and will throw an error in PHP 9.0.
- **Fixed PHP version comparison operator**: Changed `<=` to `<` so that PHP 8.1 itself is correctly accepted.
- **Replaced loose comparisons with strict**: All `==` comparisons changed to `===` throughout `functions.php` and `OkoRest.php`. `in_array()` calls now use strict mode.
- **Added return type declarations** to functions throughout the codebase.

### WooCommerce Compatibility
- **HPOS (High-Performance Order Storage) support**: Replaced `get_post_meta()` / `update_post_meta()` with `$order->get_meta()` / `$order->update_meta_data()` + `$order->save()` in `hey_after_order_placed()`. This is required for WooCommerce's HPOS feature which is now the default storage engine.
- **REST endpoints return proper types**: Endpoints now return `WP_REST_Response` / `WP_Error` objects instead of raw arrays or `false`.

### Bug Fixes
- **Fixed no-op statement**: `$customer_note ?: '';` was not assigning the result. Changed to `$customer_note = $order->get_customer_note() ?: '';`.
- **Used `o_get_settings()` consistently**: Replaced direct `get_option()` calls in REST handlers with the filterable `o_get_settings()` helper.
- **Fixed Mapbox style handle collision**: The Mapbox JS and CSS were both registered with the handle `mapbox-gl-js`. CSS now uses `mapbox-gl-css`.

## [1.1.42] - Previous Release
