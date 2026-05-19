# Changelog

All notable changes to the Økoskabet WooCommerce Plugin will be documented in this file.

## 1.4.0 - 2026-05-15

= Multi-merchant support =

The plugin can now talk to more than one Økoskabet merchant from the
same WooCommerce installation, and decides which merchant fulfils each
order from cart contents — end to end.

### What's new

1. **Merchants registry.** A new "Økoskabet merchants" section in the
   plugin settings page lets shop managers create and manage any number
   of merchant records. Each merchant has its own API key, webhook
   secret, staging-flag, shipping descriptions, payment-gateway choice,
   capture/completion event lists, optional product-category and
   product-tag routing rules, and a numeric priority for tie-breaking.
   The first merchant is seeded automatically from the legacy settings
   the very first time WP-Admin is loaded after upgrading — your
   existing API key, webhook secret and event configuration move into a
   "Default merchant" record without you having to do anything.

2. **Cart-to-merchant routing.** A new `Merchant_Router` decides which
   merchant should handle each cart with simple, predictable rules:

   - **Per-product:** each product resolves to a merchant by checking
     (1) its own `_okoskabet_merchant` override, (2) per-merchant
     category/tag rules with priority tie-breaking, then (3) the
     default merchant.
   - **Per-cart:** if EVERY item in the cart resolves to the same
     merchant, that merchant handles the order. The moment a cart
     contains items from more than one merchant — for any reason —
     the whole cart falls back to the **default merchant**. Mixed
     carts are never blocked and never split across merchants.

   This makes additional merchants strictly opt-in: a non-default
   merchant only handles orders where the customer is shopping wholly
   within that merchant's product range. Everything else is delivered
   by the default merchant, exactly as before.

3. **End-to-end binding.** Once a cart resolves to a merchant, every
   downstream call uses that merchant's credentials and configuration:

   - Sheds, home-delivery dates and delivery-location options fetched
     for that merchant.
   - Shipping-method descriptions and "standard display window" come
     from that merchant.
   - Order submission (`POST /shipments/`) goes to that merchant's
     base URL with that merchant's API key.
   - Webhook callbacks come back in on a per-merchant URL (each
     merchant has its own signing secret) and are checked against the
     order's recorded merchant — a webhook for merchant A can never
     mutate an order owned by merchant B.

4. **Per-merchant webhook URLs.** Each merchant exposes a dedicated
   webhook URL at `…/wp-json/wp/v2/okoskabet/webhook/<merchant_id>`.
   The legacy `…/webhook` URL keeps working and is treated as an alias
   for the default merchant, so upgrading does not require you to
   reconfigure Økoskabet's webhook settings — but new merchants get
   their own URLs to keep secret-handling cleanly isolated.

5. **Cart-resolution endpoint.** A new read-only REST endpoint
   `GET /wp-json/wp/v2/okoskabet/cart_resolution` reports which
   merchant a cart routes to, plus informational `is_mixed` and
   `fell_back_to_default` flags so the frontend can show context like
   *"this cart will be handled by the default merchant because it
   contains items from multiple merchants"*. No sensitive data leaks.

### Backward compatibility

- Existing single-merchant installs are upgraded in-place via a
  one-shot migration (`seed_default_merchant_v1`) that runs on the
  first WP-Admin load after upgrade. The migration creates a "Default
  merchant" from your existing API key, webhook secret, staging flag,
  descriptions, payment-gateway choice and event lists. No manual
  reconfiguration is needed.
- The legacy webhook URL (`…/webhook` without a merchant ID) keeps
  routing to the default merchant indefinitely.
- Orders created before the upgrade have no recorded merchant; the
  webhook handler treats them as belonging to the default merchant
  for compatibility.
- **Single-merchant UX is preserved unchanged.** Sites with one
  Økoskabet merchant — the vast majority — see the same settings
  page as they did in v1.3.x: API key, webhook secret, staging
  flag, descriptions, payment gateway, and capture/completion
  events all on one CMB form at the top. These fields are a facade
  over the default merchant record; admins never see the multi-
  merchant management UI, the merchants table, or the "Default
  merchant"/"merchant ID"/"priority"/"routing rules" terminology
  unless they opt in by clicking *"+ Add another Økoskabet
  merchant"* or by configuring a second merchant. The datamodel
  is unchanged either way — the toggle is purely UX.
- Global fields (display option, delivery-location dropdown, webhook
  master switch, hide-WC-order-comments, split-checkout-enabled) stay
  in the legacy form regardless of mode.
- The existing `Split_Checkout` integration is unchanged from its
  pre-1.4.0 behavior: it splits a cart when items have conflicting
  delivery dates. Multi-merchant routing never adds a split.

### Security model

- Each merchant has its own webhook secret. The per-merchant URL
  identifies the merchant up front; only that merchant's secret is
  ever used for the HMAC comparison — the plugin never tries multiple
  secrets against an incoming webhook.
- Order-to-merchant binding is recorded in order meta at
  `woocommerce_checkout_create_order` time, so a later cart-meta or
  category-mapping change cannot accidentally re-route an existing
  order. Webhooks for an order are rejected with HTTP 403 if the
  merchant in the URL doesn't match the order's recorded merchant
  (defence-in-depth against cross-merchant tampering).
- Merchant IDs are strict `sanitize_key()` slugs (lowercase
  alphanumeric + `_` and `-`) and are validated against the registry
  before being interpolated into REST routes, HTML attributes or SQL
  queries.
- All admin actions are protected by nonces plus the
  `manage_woocommerce` capability. API keys and webhook secrets are
  rendered as `<input type="password">` and never echoed outside form
  values.
- Renaming a merchant ID rewrites every product-meta reference and
  every order-meta `_okoskabet_merchant_id` stamp so per-product
  routing overrides don't silently fall back to the default merchant
  and existing orders' webhooks don't start failing the
  merchant-mismatch check (HTTP 403). The order-meta rewrite is
  HPOS-aware: it goes through `wc_get_orders()` and
  `$order->update_meta_data()` rather than a raw `wp_postmeta`
  UPDATE, so it works correctly on stores that have HPOS enabled.
- Switching the default merchant surfaces an inline warning about
  the legacy `…/okoskabet/webhook` URL now verifying against a
  different merchant's secret — Økoskabet's webhook configuration
  needs to be updated to match (or moved to the per-merchant URL)
  to avoid 401 failures.

### Files added

- `integrations/Merchants.php` — registry, admin UI, AJAX test-connection
- `integrations/Merchant_Router.php` — routing pipeline
- `integrations/Product_Merchant_Meta.php` — per-product override metabox

### Files modified

- `rest/OkoRest.php` — every endpoint resolves a merchant first;
  per-merchant webhook route added; cart_resolution endpoint added
- `functions/functions.php` — order creation stamps the merchant on
  the order; shipment submission and cancellation use the order's
  merchant credentials; checkout JS payload exposes the merchant.
  The merchant-stamp hook falls back to the order's own line items
  when `WC()->cart` is empty (REST API, admin "Add order",
  subscription renewals).
- `integrations/Upgrades.php` — seed-default-merchant migration
- `backend/views/settings.php` — renders the legacy single-merchant
  CMB form by default and mirrors the default merchant's values
  into the option row before CMB reads it; only hides the
  merchant-scoped fields once the admin opts into multi-merchant
  mode (count > 1 or `?oko_show_merchants=1`)

### Performance

- `Merchants::get_config()` is now memoised per-request. Calls from
  `Merchant_Router` no longer pay the `merge_with_defaults()` cost
  on every product lookup; `save_config()` refreshes the cache;
  tests can call `purge_config_cache()` to reset between cases.

### Tests added

- `tests/wpunit/integrations/MerchantsTest.php` — normalisation,
  default-id fallback when the stored ID is stale, request-cache
  semantics, legacy-options mirror, single-↔-multi mode toggle,
  seed-default-merchant migration idempotency.
- `tests/wpunit/integrations/MerchantRouterTest.php` —
  `resolve_for_products` for empty / single / mixed carts, plus the
  per-product override path including the "override targets a
  merchant that doesn't exist" fall-through.

## 1.3.6 - 2026-05-08

= Security and code-review hardening =

Five fixes from the post-1.3.5 review:

1. **API key no longer leaks in the settings page.** The `_api_key`
   field is now `<input type="password">`, matching the existing
   webhook-secret field. The webhook-instructions panel no longer
   interpolates the configured API key into the rendered HTML at all
   (it previously appeared in `<code>` and `<pre>` blocks in plain
   text).

2. **Webhook docs rewritten to match the implementation.** The
   instructions now describe the actual auth scheme — HMAC-SHA256 of
   the raw request body, sent as the `X-HMAC-SHA256` header, signed
   with the Webhook Secret field — instead of the misleading
   "Authorization: <API key>" the previous text suggested. The 401
   response description was updated correspondingly.

3. **Checkout validation no longer mutates `$_POST`.** Clearing the
   stale `_billing_okoskabet_shed_id` for home-delivery orders now
   happens on `woocommerce_checkout_create_order` against the order
   object (HPOS-safe), not by writing to the superglobal mid-validation.

4. **Split-checkout AJAX nonce is bound to the WC session.** Generic
   nonces shared across all anonymous browsers are replaced by per-
   session nonces (action key `oko_split_<customer_id>`), closing a
   guest CSRF window on `wp_ajax_nopriv_oko_start_split` /
   `oko_resume_split`. Guest checkout flow is unchanged.

5. **HMAC mismatch log no longer prints the expected signature.** Only
   the received header is logged on rejection.

## 1.3.5 - 2026-05-07

= Better thank-you transition between split orders =

In 1.3.4 the "Book next delivery now" banner appeared below the order
details on the thank-you page. Customers had to scroll to find it,
which made it easy to miss the fact that they still had another order
to place.

This release:

1. **Moves the banner above the order details** by hooking
   `woocommerce_before_thankyou` (priority 5) instead of
   `woocommerce_thankyou` (priority 20). The banner is now the first
   thing the customer sees after placing their order.

2. **Promotes the banner visually**:
   - A "Step N of M completed" progress badge at the top
   - A larger, bolder headline
   - The next delivery's date and items shown in a contrasting white
     box for clarity
   - A full-width red "Book next delivery now" CTA button matching
     the conflict banner's styling on checkout
   - A subtle help line below: "You can also scroll down to see your
     order confirmation first" — so customers who want to verify
     their just-placed order know that's still possible

3. **Code review pass.** Manual review of the entire plugin codebase
   for common WordPress / WooCommerce mistakes. No issues found in
   any of the integrations modified by 1.3.x. One pre-existing dead
   code path in `backend/ImpExp.php` was noted (settings import/export
   never registered in the classmap, plus a `json_decode` without
   `, true`) — left alone since it's not currently active and a fix
   would risk activating untested code.

## 1.3.4 - 2026-05-07

= UI polish on conflict banner =

Three changes based on first-customer feedback:

1. **Hide the rest of the checkout form while the banner is up.**
   Previously the customer saw the banner AND a fully-rendered checkout
   form (billing/shipping fields, "Place order" button, the legacy
   "no available dates" notice in the order-review table). Now the form
   is hidden until the customer has continued — they see only the
   banner, with no distractions.

2. **Bigger, always-visible CTA button.** The 1.3.3 version disabled the
   button until the checkbox was ticked, which made it small and easy
   to miss. The button is now full-width, prominent, and always
   clickable. If the customer clicks without ticking the checkbox, the
   checkbox row gives a brief shake animation and an inline error
   appears under the button.

3. **Inline error messaging** instead of `alert()`. Errors from the
   server are shown in a small text under the button rather than as
   a popup that interrupts the flow.

## 1.3.3 - 2026-05-07

= Bugfix: split-banner button did nothing on click =

The split-banner's "Continue with delivery N of M" button silently
failed to do anything. Symptom: clicking the button after ticking the
checkbox produced no AJAX request, no error, no reload.

Root cause: the inline `<script>` attached event listeners directly to
the banner's elements (`#oko-split-ack`, `#oko-split-continue`).
WooCommerce re-renders the entire checkout form on every checkout-update
event (shipping change, address change, etc.), which removes the banner
markup AND its listeners. The listeners only worked on the very first
render before any WC update fired.

Fix: switch to event delegation on `document`. Listeners survive any
number of checkout re-renders. Also added `console.log` calls on the
client side so the next time something silently fails, the browser
console will show exactly what happened.

## 1.3.2 - 2026-05-07

= Bugfix for split checkout AJAX flow =

When the customer clicked "Continue with delivery 1 of N", the AJAX
endpoint did not always reduce the cart on the server side. This caused
the page reload to show the full cart still — with the conflicting items
intact and the "no available delivery dates" message still visible.

Three fixes:

1. **AJAX handler now force-initialises WC session and cart** before
   touching them. WordPress's AJAX endpoints don't always have
   `WC()->session` bootstrapped for guest users.
2. **Cart changes are now explicitly persisted** to the session before
   the AJAX response returns. WC's auto-save on shutdown was sometimes
   not firing reliably in AJAX context.
3. **Detailed debug logging** added on every step of the split flow
   (gated behind `WP_DEBUG`). When something goes wrong the next time,
   we'll have a clear trail in `debug.log` showing exactly where.

## 1.3.1 - 2026-05-07

= Polish for split checkout =

Fixes three issues found during initial testing of 1.3.0:

**Feature toggle.** Split-checkout is now opt-in via a new "Allow split
checkout" setting (default: OFF). When OFF, the plugin behaves exactly
like 1.2.18 — the legacy "remove an item to get more delivery options"
explanation is shown in the dates dropdown, and customers cannot place
the order until they've reduced the cart to a compatible set. When ON,
the new split-banner takes over and the legacy explanation is
suppressed (so the customer doesn't see two conflicting messages).

**Danish translations.** All split-checkout strings now have Danish
translations in `languages/okoskabet-woocommerce-plugin-da_DK.po`/`.mo`.

**Clarification on detection logic.** When a cart contains, say, milk
(only Mondays) and bread (any day), the algorithm correctly finds the
intersection (Mondays) and treats the cart as a SINGLE delivery group
— no split is suggested. Splits are only triggered when there is no
single date that satisfies every item's rules. This was already the
behaviour in 1.3.0; the changelog entry documents it explicitly because
testing revealed it was easy to misread the algorithm.

## 1.3.0 - 2026-05-07

= Split checkout (MVP) =

When a cart contains products whose delivery rules are mutually
incompatible (no single date works for all items), the customer is now
guided through N sequential orders — one per delivery date.

**How it works (customer perspective):**

1. On the checkout page, if a split is required, a red banner appears
   above the form listing the delivery groups with suggested dates.
2. The standard "Place order" button is disabled while the banner is
   open. The customer must tick an acknowledgment checkbox.
3. Clicking "Continue with delivery 1 of N" reduces the cart to
   only the items for delivery 1 and reloads the checkout. A green
   "you're ordering delivery 1 of N" banner takes the red one's place.
4. Customer completes a normal checkout for delivery 1 → pays → sees the
   thank-you page.
5. The thank-you page shows a yellow banner: "You have N-1 more
   deliveries to book — Book next delivery now". Clicking it restores
   the next group's items and redirects back to checkout.
6. Repeat until all groups are completed. The final thank-you page
   shows a green "All deliveries booked!" message.

**How it works (backend perspective):**

- Each split order is fully independent — no parent/child link, no
  payment coordination, no completion rollup.
- Each order is tagged with three post_meta values:
  - `_oko_split_token` — random hex string shared across orders in the
    same split (so an admin can find sibling orders by querying)
  - `_oko_split_step` — 1-based position of this order in the split
  - `_oko_split_total_steps` — total steps for the split
- The split state lives entirely in the WooCommerce session under the
  key `oko_split_state`. No new DB tables.
- Detection reuses `Delivery_Exceptions::collect_applicable_rules` and
  `Delivery_Exceptions::date_passes_rule` — no duplication of business
  logic.

**Out of scope for this MVP (see ROADMAP.md):**

- Email reminder if the customer abandons mid-flow
- Admin order-list UI showing split-membership
- Custom emails referencing the split
- Stock-rollback if step N fails after step N-1 succeeded
- Settings toggle to disable the feature (currently always-on)

## 1.2.18 - 2026-05-07

= Technical debt + performance =

Closes the two remaining items from the 1.2.16 code review (#6 and #8) and
adds two performance fixes for the checkout filter.

**#6 — One-shot upgrade routine for legacy `label_created` event name.**
Before 1.2.7 the plugin stored "label_created" in `_capture_events` and
`_webhook_events` settings; from 1.2.7 onwards the same event is called
"in_shed". Until now `OkoRest::handle_webhook` had to remap the legacy key
on every webhook call. A new `Integrations\Upgrades` class runs a single
migration on `admin_init` that rewrites stored settings once and records
completion in `okoskabet_completed_migrations`. The runtime remap is kept as
a defense-in-depth measure but no longer fires for any site that has loaded
the admin since 1.2.18.

**#8 — Refactor inline checkout JS to enqueued external file.**
The 300+ lines of inline JavaScript that lived in `functions/functions.php`
(delivery-exceptions overlay + delivery-location dropdown UI) have moved
into a new file `assets/build/checkout-helpers.js`. The file is enqueued
via `wp_register_script` / `wp_enqueue_script` so it is cacheable by
browsers and CDNs. PHP-controlled configuration and translatable strings
are passed via two `window` globals injected with `wp_add_inline_script`.

**Performance — per-request cache for `collect_applicable_rules`.**
During a single checkout render Svelte triggers many re-renders, which
previously caused the exceptions filter to re-query category and tag terms
for every product on every call. The result is now memoised per cart-shape
within a single PHP request.

**Performance — deduplicated exception filter logging.**
The same checkout render that triggered repeated rule collection also
emitted 30+ identical "Økoskabet exceptions: ..." lines into `debug.log`
within a few seconds. The log line is now de-duplicated per unique cart
shape per request, leaving the log readable.

## 1.2.17 - 2026-05-07

= Hotfix for 1.2.16 =

Fixes a fatal PHP parse error introduced in 1.2.16 that crashed the plugin's settings page.

The 1.2.16 build inadvertently shipped an orphan code fragment in `backend/views/settings.php` (lines 216-220) — leftover from an earlier cleanup that removed the opening of an `add_field()` call but didn't remove the body. PHP failed with `syntax error, unexpected token "=>"` whenever an admin tried to open the plugin's settings page.

No other changes vs 1.2.16. All 1.2.16 fixes (Payment_Capture in classmap, order-status gate on capture, i18n, JSON_HEX_TAG, sanitization, etc.) are retained.

## 1.2.16 - 2026-05-07

= Critical fixes from external code review =

This release addresses findings from a code review of the 1.2.2 → 1.2.15 changeset.

= Blocker: Payment_Capture missing from autoloader =

The Payment_Capture class — added back in 1.2.2 — was never registered in the optimised Composer classmap. As a result, every webhook attempting payment capture would hit a fatal class-not-found error in production. Both `vendor/composer/autoload_classmap.php` and `vendor/composer/autoload_static.php` are now updated.

= Bug: Capture protected against terminal order statuses =

`Payment_Capture::capture()` now refuses to capture orders that are already cancelled, refunded, or in another non-capturable state. Previously, a late webhook from Økoskabet could re-flip a cancelled order to "processing". The capturable status list defaults to `pending`, `on-hold`, `failed` and is filterable via `okoskabet_capturable_order_statuses`.

= Bug: Timezone-aware date arithmetic =

All `DateTime` constructions in the Delivery Exceptions module now use the WordPress site timezone (via `wp_timezone()`) rather than the server's PHP default. This eliminates a class of off-by-one-day bugs that could appear when the server's timezone (typically UTC) differs from the store's configured timezone (Europe/Copenhagen).

= Bug: Cache key for delivery location options =

The transient holding delivery location options now includes the API environment (production vs staging) and the site locale in its key. Previously, toggling the staging flag would serve stale options for up to 10 minutes.

= i18n regression repaired =

Plugin source strings have been reverted to English, with all Danish text moved into a `da_DK.po`/`.mo` translation file under `languages/`. The previous changes had hard-coded Danish into `__()` calls, which broke display in non-Danish locales and prevented standard translation workflows. Four `__()` calls in `functions.php` were also using the wrong text domain (`'woocommerce'`) — corrected to `O_TEXTDOMAIN`.

* Plugin display in any locale now respects the active language
* Translation can be extended via standard WordPress workflows (`.pot` template included)
* The Danish strings users see today are unchanged — they're just delivered through the proper translation pipeline now

= Security: JSON injection hardening =

The inline JSON payload injected into checkout (`window._okoskabet_checkout`) is now encoded with `JSON_HEX_TAG | JSON_HEX_AMP` flags. This prevents any future malicious or accidental `</script>` sequence in admin-controlled settings from breaking out of the script tag.

= Security: Webhook secret sanitization =

The `_webhook_secret` CMB2 field now declares `sanitize_text_field` as its sanitization callback and `esc_attr` as its escape callback. Previously the field had neither.

= Security & privacy: Webhook diagnostic logging =

The webhook diagnostic logger no longer dumps raw request bodies to `debug.log`. It now logs only a summary (body length, signature presence, event name, shipment reference) and emits even that only when `WP_DEBUG` is enabled. Sensitive headers (`x-hmac-sha256`, `authorization`) are redacted in the dump.

= Code quality =

* All informational `error_log()` calls in `OkoRest`, `Delivery_Exceptions` and `Payment_Capture` are now gated behind `WP_DEBUG`. Exception-path error logging in payment capture remains unconditional (since those represent genuine failures worth recording).
* Filter log line in `Delivery_Exceptions::filter_dates_for_cart` rewritten to be human-readable.
* Customer-facing strings in the explanation overlay JS are now injected from PHP (so they translate via the standard pipeline) instead of being hard-coded.

= Known remaining technical debt =

These were identified in the review and are tracked in `ROADMAP.md` for future releases:

* Inline JS in `functions/functions.php` should move to a properly enqueued file
* The legacy `label_created` event mapping should become a one-shot upgrade routine instead of being applied on every webhook
* No automated test suite yet
* Svelte bundle is hand-patched; needs build pipeline restored

## 1.2.15 - 2026-05-06

= Bedre fejlbesked når kurven har modstridende leveringsregler =

Tidligere viste plugin'et bare "Ingen tilgængelige datoer." når kurven indeholdt produkter med modstridende leveringsregler (fx en frostvare der kun leveres mandage og en almindelig vare der ikke har en mandag indenfor standardvinduet). Det var kryptisk for kunden — de havde ingen idé om hvilket produkt der var problemet eller hvad de skulle gøre.

Nu vises en forklaring der lister hvert problematisk produkt og fortæller hvilken regel der gælder for det:

> **Varerne i din kurv har modstridende leveringsregler så ingen dato passer til dem alle. Se nedenfor.**
> 
> * **bulderbasse** — kan kun leveres mandage
> * **(ingen titel)** — kan tidligst leveres fra den 15. maj 2026
> 
> *Du kan fjerne en eller flere af de markerede varer fra kurven for at få flere leveringsmuligheder, eller kontakte os for hjælp.*

Beskeden har samme funktionalitet for både hjemmelevering og skabsafhentning.

= Teknisk =

* Backend genererer en `exceptions_explanation`-blok med per-produkt forklaringer
* Frontend bruger en MutationObserver-overlay til at indsætte forklaringen i DOM uden at røre den kompilerede Svelte-bundle (mere robust end at patch'e minified kode)
* Forklaringen er på dansk og bruger naturlige formuleringer for ugedage og datoer
* Datoer formateres som "den 15. maj 2026" og ugedage som "mandage", "tirsdage og torsdage" osv

= Fremtid =

På sigt erstattes denne mellemstation af en ægte split-checkout-løsning hvor ordren automatisk kan splittes i flere leveringsdage. Indtil da hjælper denne forklaring i det mindste kunden med at forstå hvad der er galt.

## 1.2.14 - 2026-05-06

= Arkitektur-fix: Leveringsundtagelser virker uden afhængighed af cart-session =

I 1.2.13 forsøgte pluginnet at læse kurvens varer via `WC()->cart->get_cart()` i REST-context, men WooCommerce indlæser ikke session/cart automatisk for REST-kald, og frontend sendte heller ikke session-cookies med. Resultatet var at filteret altid så en tom kurv og sprang filtrering over.

Fixet er at sende kurvens produkt-IDs som query parameter direkte fra frontend:

* Plugin udskriver nu et skjult input `<input id="okoskabet-cart-product-ids">` på checkout, der indeholder en komma-separeret liste af produkt-IDs i kurven
* Svelte-frontend læser feltet og tilføjer `product_ids` som query parameter til både `/wp-json/wp/v2/okoskabet/home_delivery` og `/wp-json/wp/v2/okoskabet/sheds`
* REST-endpoints sender produkt-IDs videre til filteret som anden parameter
* Filteret er ikke længere afhængigt af WC-session-state — det fungerer på samme produkt-IDs uanset hvilken context kaldet kommer fra

Frontend sender også `credentials: 'same-origin'` så session-cookies inkluderes hvis nogen senere får brug for at læse dem.

= Nyt: Undtagelser kan udvide visningsvinduet =

Tidligere kunne undtagelser kun begrænse leveringsdage indenfor "Standard visningsvindue" (tidligere "Maximum dage frem"). Nu udvider `only_on` og `from_until` undtagelser automatisk visningen, så fx en juleudbringning den 24. december er synlig allerede i november — selvom standardvinduet kun viser 3 dage frem.

* Pluginnet beder Økoskabets API om et tilstrækkeligt bredt vindue (op til 365 dage) hvis der er undtagelses-datoer længere ude
* Datoerne udenfor standardvinduet vises kun hvis kunden har et matchende produkt i kurven
* `weekdays`-undtagelser udvider ikke vinduet (de begrænser kun) — i overensstemmelse med deres natur som "kun på faste dage"

= Mellemstation før split-checkout =

Hvis kunden har både en juleand (kun 24. dec) og en almindelig vare i kurven, vil dropdown'en vise 24. dec som leveringsmulighed, og hele ordren leveres på den dato. På sigt kommer en split-checkout-feature der splitter ordren i to leveringsdage hvis kunden hellere vil have det. Indtil da gælder "alt går samme dag"-modellen.

= Settings ændringer =

* "Maximum days into the future" omdøbt til "Standard visningsvindue (dage)"
* Default sænket fra 21 til 3 dage (mere intuitiv standard-værdi)
* Beskrivelsen forklarer nu at undtagelser kan udvide vinduet udover dette tal

= Frontend bundle =

Compiled bundle (`assets/build/plugin-public.js`) er manuelt patchet for at matche `assets/src/api.ts`. Udviklere bør køre den oprindelige Svelte build pipeline ved næste release for at sikre at source og bundle er i sync.

## 1.2.13 - 2026-05-06

= Fix: Leveringsundtagelser virker nu i checkout =

Selvom UI'et virkede og reglerne blev gemt korrekt i 1.2.12, blev de aldrig håndhævet i checkout: alle datoer blev returneret uændret. Årsagen var at WooCommerce ikke automatisk indlæser cart, customer og session for REST API-kald — kun for almindelige page-views. Pluginnets filtreringskode tjekker hvad der ligger i kurven for at afgøre hvilke regler der gælder, og når kurven opfattes som tom, springer filtreringen helt over.

Pluginnet indlæser nu manuelt WooCommerce's frontend-stack i REST-context (via `wc_load_cart()`) før det forsøger at læse kurven. Resultat: leveringsundtagelser begrænser nu de viste datoer korrekt på checkout.

= Debug-logging tilføjet =

Filteret skriver nu en linje til `wp-content/debug.log` hver gang det kører, der viser hvor mange datoer der kom ind, hvor mange der blev tilbage efter filtrering, og hvilke regler der blev anvendt. Brugbart hvis nogle datoer mangler eller dukker op uventet.

## 1.2.12 - 2026-05-06

= Fix: Leveringsundtagelser dukker faktisk op nu =

I 1.2.10 og 1.2.11 var Leveringsundtagelser-funktionaliteten bygget korrekt, men admin-UI'et blev aldrig vist fordi pluginnet bruger en statisk Composer classmap til at finde sine egne klasser, og den nye `Delivery_Exceptions`-klasse manglede i den liste.

Classmap-filerne (`vendor/composer/autoload_classmap.php` og `vendor/composer/autoload_static.php`) er nu opdateret manuelt så pluginnet finder den nye klasse. Hvis fremtidige klasser tilføjes manuelt skal de samme to filer opdateres — eller `composer dumpautoload -o` skal køres i pluginets root-mappe.

Som sidegevinst forklarer dette også hvorfor leveringsregler-systemet i 1.2.9 (kategori-niveau regler) heller aldrig virkede — den klasse manglede sandsynligvis også i classmap.

## 1.2.11 - 2026-05-06

= Fix: Leveringsundtagelser flyttet til hovedsiden =

I 1.2.10 forsøgte pluginnet at registrere "Leveringsundtagelser" som et separat undermenupunkt under plugin-menuen. Det virkede ikke pålideligt fordi timing af `admin_menu`-hooks gjorde at submenuen aldrig blev tilføjet i nogle WordPress-konfigurationer.

I stedet renderes hele undtagelses-administrationen nu direkte på den eksisterende plugin-settings-side, lige under "Export/Import"-sektionen. Du finder den under WP-admin → Økoskabet WooCommerce Plugin (alle indstillinger på samme side).

Funktionaliteten er den samme som 1.2.10 — kun placeringen er ændret.

## 1.2.10 - 2026-05-06

= Nyt: Centraliseret leveringsundtagelser-system =

Erstatter det gamle kategori/produkt-niveau regelsystem fra 1.2.9 med en samlet administrationsside hvor undtagelser oprettes ét sted og knyttes til de kategorier og tags de skal gælde for.

**Ny menupunkt:** WP-admin → Økoskabet WooCommerce Plugin → Leveringsundtagelser

= Tre slags undtagelser =

Hver familie har en master til/fra-knap øverst og kan slås helt fra:

* **Levering kun på faste ugedage** — én konfiguration pr ugedag (Mandag, Tirsdag, ...). Du tilknytter de kategorier og tags der KUN må leveres på den dag. Hvis et produkt matcher flere ugedage, må det leveres på alle de matchende dage
* **Levering kun på en bestemt dag** — opret flere navngivne undtagelser, hver med en specifik dato (fx "Juleudbringning" på 24. december). Hver undtagelse kan slås individuelt aktiv/inaktiv
* **Levering fra (og evt. indtil) en bestemt dag** — opret flere navngivne undtagelser med startdato og valgfri slutdato. Brugbart fx til sæsonprodukter eller kampagner med begrænset leveringsperiode

= Hvordan reglerne kombineres =

Når en kunde har varer i kurven samles ALLE undtagelser der matcher mindst ét produkt. En leveringsdato vises kun hvis ALLE matchende regler tillader den. Eksempel: et produkt matcher både "kun mandage" og "fra 15. maj" — kunden kan da kun vælge mandage på/efter 15. maj.

= Til/fra på individuel undtagelse =

Hver "kun på en bestemt dag"- og "fra/indtil"-undtagelse har sin egen aktiv-checkbox så du midlertidigt kan deaktivere fx en juleundtagelse uden at slette konfigurationen.

= Breaking change =

Det gamle system fra 1.2.9 (regler direkte på kategori, tag og produkt-niveau) er fjernet helt. Hvis du nåede at oprette regler i 1.2.9 vil de IKKE blive automatisk migreret — du skal sætte dem op i det nye Leveringsundtagelser-system. Den gamle data ligger stadig i wp_termmeta og wp_postmeta, men bruges ikke længere af pluginnet.

## 1.2.9 - 2026-05-06

= Leveringsdato-regler nu fuldt funktionelle =

Pluginnet havde indbygget logik for "Leveringsdato-regler" på kategori og produkt, men reglerne blev aldrig faktisk håndhævet i checkout — frontend kaldte ikke filtreringsendpointet. Det er fixet nu: datoer filtreres serverside i de eksisterende endpoints (`get_home_delivery` og `get_sheds`), så frontend automatisk får et renset sæt datoer uden at skulle ændres.

= Nye regel-typer og nye placeringer =

* **Tag-niveau regler** — udover kategori kan du nu sætte leveringsdato-regler på tags (Produkter → Tags → vælg tag → "Økoskabet: Leveringsdato-regel"). Logikken er den samme som kategori
* **"Faste ugedage"-regel** — ny regeltype hvor du kan vælge bestemte ugedage produktet kan leveres på (fx kun mandage og torsdage). Brugbart for friske varer der følger en leveringsplan

= Hvordan reglerne kombineres =

Når en kunde har flere varer i kurven, samles ALLE aktive regler og en dato vises kun hvis ALLE regler tillader det. For et enkelt produkt gælder:

1. Hvis produktet selv har en regel sat, bruges kun den (overskriver alle kategori- og tag-regler)
2. Ellers samles alle regler fra alle produktets kategorier og tags

Så hvis produktet "Æbler" har kategori "Frisk frugt" (regel: kun mandage) og tag "Forudbestilling" (regel: fra 15. maj), kan kunden kun vælge mandage fra 15. maj og frem.

= Nye meta-keys =

* `_okoskabet_date_rule_weekdays` — produktniveau, comma-separeret liste af ugedage (0=søn … 6=lør)
* `okoskabet_date_rule_weekdays` — taxonomy-niveau (samme format)

Eksisterende regler fra 1.2.8 og tidligere virker uændret.

## 1.2.8 - 2026-05-06

= Tre webhook-events i stedet for to =

Pluginnet skelner nu mellem tre forskellige steps i Økoskabets shipment-flow, så betalings-capture og ordre-completion kan udløses præcis hvor det giver mening:

* **Label Printed** — Webshoppen printer labelen og tilføjer en parcel via Økoskabets API. Webhook indeholder en `parcels`-ændring fra tom liste til en ny parcel
* **In Shed** — Økoskabet markerer shipment som "fulfilled" (pakken er lagt i skabet). Webhook indeholder `status: registered → fulfilled`
* **Order Delivered** — Kunden henter pakken. Webhook indeholder `status: fulfilled → delivered`

Settings-siden har nu tre tickboxes for hvert af felterne "Capture-events" og "Completion-events" så administratoren kan vælge præcis hvilket trin der skal trigge hvilken handling.

= Migration =

Eksisterende installationer der havde "Label Created" valgt i settings (fra 1.2.6/1.2.7) vil automatisk blive behandlet som "In Shed" — det er den ægte event det gamle "Label Created" lyttede på. Tjek dine settings og ret eventuelt til "Label Printed" hvis du ønsker capture tidligere i flowet.

## 1.2.7 - 2026-05-06

= Bug fixes =

* **Note-felt og leveringssted gemmes ikke længere samtidigt på samme ordre.** Hvis kunden valgte "Andet" på checkout, skrev en chauffør-besked, og derefter skiftede mening til en almindelig leveringssted-option (fx "Foran hoveddøren"), blev både leveringsstedet og den oprindelige fritekst-besked gemt på ordren. Nu sendes fritekst-beskeden kun videre når "Andet" er valgt; i alle andre tilfælde bruges kun leveringssted-værdien

## 1.2.6 - 2026-05-06

= Webhook fixes =

* **Korrekt HMAC-SHA256 verification af indkomne webhooks.** Pluginnet verificerer nu indkomne webhooks ved at beregne HMAC-SHA256 over request body med en webhook-secret som nøgle, og sammenligner med `x-hmac-sha256`-headeren. Den tidligere implementation tjekkede en API-key i Authorization-headeren — det matchede ikke hvordan Økoskabets backoffice faktisk signerer webhooks
* **Nyt setting "Webhook Secret"** under Webhook & Betaling. Indtast den secret-nøgle der står ud for din webhook-URL i Økoskabets backoffice
* **Korrekt event-mapping.** Pluginnet forventede event-navne som `label_created` og `order_delivered`, men Økoskabet sender `reservation_updated` med en `changes.status.value`-property. Pluginnet oversætter nu automatisk: `fulfilled` status → "Label Created", `delivered` status → "Order Delivered". Eksisterende settings-checkboxe virker uændret
* **Detaljeret logging** af indkomne webhooks (headers, body, parsed params, mapping-resultat) til `wp-content/debug.log` for nemmere fejlfinding

= Breaking change =

* Webhook-funktionalitet kræver nu at "Webhook Secret" er sat. Hvis det er tomt afvises alle indkomne webhooks med fejlbesked i debug.log

## 1.2.4 - 2026-05-06

= Improvements =

* Beskrivende tekst (tidligere "Label: Fritekstfelt") vises nu over Leveringsinfo-dropdownen i stedet for under, så kunden læser den før de vælger leveringssted
* Dropdown-valget for leveringssted (f.eks. "I garagen", "Foran hoveddøren") sendes nu til Økoskabets API som note, så det vises i backoffice's Notes-kolonne
* Fritekstfeltet vises kun ved valg af "Andet" eller når dropdown er slået fra (rettet uventet adfærd fra 1.2.3)

## 1.2.3 - 2026-05-06

= Improvements =

* Leveringsinfo-fritekstfeltet er nu altid synligt ved hjemmelevering, så kunden kan tilføje en besked uanset hvilket leveringssted der er valgt i dropdown'en
* Nyt setting "Skjul WooCommerce ordrenote" — skjuler WooCommerce's standard "Tilføj ordrenote"-felt på checkout, så kunden kun har ét sted at skrive leveringsbesked

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
