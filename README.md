![Økoskabet Logo](https://storage.googleapis.com/okoskabet-images/miscellaneous/github/os_wc.png)

# Økoskabet's WooCommerce Plugin

Connect your WooCommerce store to Økoskabet. Økoskabet is a Danish logistics
provider offering chilled pickup-point deliveries and home deliveries — a
turn-key solution for food deliveries that handles address validation, label
generation, and more. Read more at [https://okoskabet.dk](https://okoskabet.dk).

Published by **Foodshipper**.

#### Supported languages
* English
* Danish

#### Requirements
* WordPress 6.0+
* WooCommerce 7.0+
* PHP 8.1+
* HTTPS enabled (Økoskabet does not deliver webhooks over plain HTTP)

## Installation

1. Download the plugin ZIP — either from the
   [latest GitHub release](https://github.com/okoskabet/okoskabet-woocommerce-plugin/releases/latest)
   or, if you want the absolute newest commit, the
   [main branch archive](https://github.com/okoskabet/okoskabet-woocommerce-plugin/archive/refs/heads/main.zip).
2. In WP-Admin go to **Plugins → Add New → Upload Plugin** and select the ZIP.
3. Click **Install Now** and then **Activate**.
4. A new menu item **Økoskabet WooCommerce Plugin** appears in the sidebar.
   Enter your API key and webhook secret (provided by Økoskabet's backoffice
   team) and save.

For the full setup walkthrough — webhook configuration, delivery exception
rules, split checkout, day-to-day usage and troubleshooting — see
[**docs/Brugerguide.pdf**](docs/Brugerguide.pdf) (Danish).

### Updating from a previous version

The plugin uses [Yahnis Elsts' plugin update checker](https://github.com/YahnisElsts/plugin-update-checker)
pointed at this repository. When a new GitHub release is published, every
installed instance will offer the update inside WP-Admin → *Dashboard →
Updates* (checked roughly every 12 hours; admins can force a check with the
"Check again" link).

Settings, delivery rules, and split-order tokens are stored in the database
and survive an update — including a manual *Deactivate → Delete → Re-install*
cycle.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full list of changes between versions.

## License

GPL-3.0-or-later. See [LICENSE.txt](LICENSE.txt).
