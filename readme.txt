=== WM DSGVO Newsletter Suite ===
Contributors: arnoldwender
Tags: newsletter, double opt-in, dsgvo, gdpr, brevo
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Double opt-in sign-up form with a consent record per sign-up and a server-side push of confirmed addresses to Brevo, Rapidmail, CleverReach, Kit or Mailchimp.

== Description ==

* **Form:** `[wm_dsgvo_newsletter]` prints an address field, a consent checkbox with a link to the privacy policy, honeypot fields and a proof of work computed in the browser (HTTPS needed).
* **Double opt-in:** a sign-up waits in a staging table with the consent text, its SHA-256 and an HMAC of the IP until the mailed link is followed; unconfirmed sign-ups are deleted after the configured window (default 48 hours).
* **Provider push:** every five minutes (WP-Cron) confirmed addresses go server-to-server to the configured provider, or stay local.
* **Unsubscribe:** `wm-newsletter/v1/unsubscribe` requires the address's own HMAC token (carried in the List-Unsubscribe header of the confirmation mail) and changes the local table only; a webhook lets the provider report unsubscribes.
* **Admin & CLI:** DSGVO Newsletter menu with overview, sign-ups and CSV export, settings (including opt-in data deletion on uninstall) and shortcode help; WP-CLI `wp wm-newsletter sync|purge-expired|stats`.
* **Extensibility & Integrations:** developer hooks (`wm_newsletter_ready`, `wm_newsletter_confirmed`, `wm_newsletter_rest_rate_limit`, `wm_newsletter_subscriber_payload`), WordPress 6.5+ Block Bindings (`wm-newsletter/stats`), GenerateBlocks 2.x Dynamic Tag (`{{wm_newsletter_stat}}`), and 25 translated locale catalogs.

The plugin sends no newsletters, does not pass unsubscribes on to the provider and makes no legal assessment of consent text or mailing. Every script and style ships inside the plugin.

**No Support & Zero Liability ("AS IS"):** This plugin is provided free of charge as personal open-source software with **zero support** and **no private support**. Do not contact the author via email, `arnoldwender.com`, or direct messages. Report reproducible defects exclusively at `https://github.com/arnoldwender/wm-wp-dsgvo-newsletter-suite/issues` (no SLA or response guarantee). Under GPLv2+ Sections 11-12 it is distributed **"AS IS"** without warranty, legal advice, or author liability.

== Installation ==

1. Upload the `wm-dsgvo-newsletter-suite` folder to `/wp-content/plugins/` and activate it.
2. Set sender, privacy-policy URL, window and provider under DSGVO Newsletter › Einstellungen.
3. Place `[wm_dsgvo_newsletter]` on a page served over HTTPS.

== Frequently Asked Questions ==

= Is technical or private support provided? =

No. This plugin comes with zero support (`0` support). Private inquiries sent via email, `arnoldwender.com`, or social/chat channels are not answered. Reproducible bugs belong solely on GitHub Issues (`https://github.com/arnoldwender/wm-wp-dsgvo-newsletter-suite/issues`) with no guarantee of reply or resolution. The software is provided **"AS IS"** at your sole risk with zero warranty or liability (see `SUPPORT.md` in the repository).

== Screenshots ==

1. Admin overview: local subscriber counts, the provider and the state of the push to it
2. Frontend double opt-in subscription form rendered via [wm_dsgvo_newsletter]
3. WordPress dashboard widget with the subscribed, unconfirmed and failed-push counts

== Changelog ==


= 1.2.2 =
* Fixed: Screenshot 1 showed the WM Suite menu from before WM Suite Hub 1.2.3, with entries the hub no longer has («Marketplace (Lösungspakete)», «Merkle-Audit-Protokoll», «CRA- & SBOM-Zentrale»). It is retaken with the current menu; the plugin's code is unchanged.

= 1.2.1 =
* The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.

= 1.2.0 =
* The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
* The `.wordpress-org/` banners show the current version.
* The screenshot descriptions name what the screens show; the first described a "consent-text SHA-256 chain" the plugin does not have.
* Removed: The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

= 1.1.4 =
* Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
* Added root `SUPPORT.md`, GitHub Issue templates, explicit zero-support & "AS IS" liability notices in READMEs and `readme.txt`, and normalized canonical GPLv2+ `arnoldwender.com` / `arnoldwender/wm-wp-dsgvo-newsletter-suite` links.
* Updated `.wordpress-org/` visual banners to personal `ARNOLD WENDER · OPEN SOURCE` branding.

= 1.1.3 =
* Add `.wordpress-org/` vector icons, banners (`772x250`, `1544x500`), live WordPress screenshots (`screenshot-1..3.png`) and `== Screenshots ==` descriptions.
* Update trilingual READMEs and `llms.txt` with live previews and direct plugin catalog link (`https://arnoldwender.com/wordpress-plugins/wm-dsgvo-newsletter-suite/`).

= 1.1.2 =
* Point Plugin URI to the official plugin page.

= 1.1.1 =
* Multisite: hook `wp_initialize_site` when network-activated to create tables, default settings and cleanup cron on newly added sites, and support `$network_wide = true` in `Activator::activate()`.
* Guard `dbDelta()` in `Schema::create_tables()` and pass `$autoload = false` on `wm_newsletter_settings` writes in `Activator` and `SettingsManager::update_all()`.

= 1.1.0 =
* Public REST routes (`/subscribe`, `/altcha-challenge`, `/unsubscribe`) enforce transient-based IP HMAC-SHA256 rate limiting via `check_public_subscription_access` (`wm_newsletter_rest_rate_limit` filter, default 60/min, bypassed for `manage_options`, no raw IP storage).
* Uninstall preserves settings, subscribers and DOI consent records by default unless `wm_newsletter_delete_data_on_uninstall` is explicitly enabled on the settings screen.
* Added developer actions `wm_newsletter_ready` and `wm_newsletter_confirmed`, filter `wm_newsletter_subscriber_payload`, WordPress 6.5+ Block Bindings source `wm-newsletter/stats`, and GenerateBlocks 2.x Dynamic Tag `{{wm_newsletter_stat}}`.
* Guarded debug error logging behind `WP_DEBUG`, simplified confirmation title escaping, added CycloneDX 1.6 `bom.json` and WordPress Playground blueprint, and translated all 25 locale catalogs.
* Fixed unsubscribe links for addresses with "+", default list resolution, local vs external provider counters, fixed-window rate limiting, case-insensitive REST route check, failed insert handling, CleverReach consent timestamps, and Brevo webhook events (`spam`, `contact_deleted`).
* Fixed provider result signatures, five-minute WP-Cron schedule registration, browser proof-of-work verification, server-decided consent records, and hourly deletion of expired pending sign-ups in the site time zone.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.2 =
Screenshot 1 retaken with the current WM Suite menu. No code, data or settings change.

= 1.2.1 =
Stylesheet update only (the family sheet of WM Suite Hub 1.2.5). No data or settings change.

= 1.2.0 =
Hub users: update WM Suite Hub to 1.2.0 together with this plugin. The hub contract moved to a new PHP namespace; next to an older hub the plugin keeps working, but it is not listed in the cockpit and its admin page has no menu entry until the hub is updated.
