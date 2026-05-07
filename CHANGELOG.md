# Changelog

All notable changes to the Økoskabet WooCommerce Plugin will be documented in this file.

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
