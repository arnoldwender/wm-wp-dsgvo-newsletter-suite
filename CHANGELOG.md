# Changelog — WM DSGVO Newsletter Suite

All notable changes to **WM DSGVO Newsletter Suite** are documented in this file in adherence to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.2.1] - 2026-11-21

### Changed
- The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.
- The translation catalogs are regenerated from the code: the `.pot` header names the current version, the Author URI entry follows the plugin header (https://arnoldwender.com instead of the www address), and the Plugin URI entry, empty until now, is filled in all 25 locales.

## [1.2.0] - 2026-11-20

### Changed
- The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
- The `.wordpress-org/` banners show the current version.
- The screenshot descriptions (`readme.txt` and the three READMEs) name what the screens show. The first described a "consent-text SHA-256 chain", which the plugin does not have (the README says so under *Not shipped*); the third called the widget counts a "verified opt-in ledger".

### Removed
- The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

## [1.1.4] - 2026-11-19

- Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.

- Added root `SUPPORT.md`, `.github/ISSUE_TEMPLATE/{config.yml,bug_report.yml}`, and explicit zero-support / "AS IS" liability notices across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `SECURITY.md`, and `llms.txt`.
- Migrated plugin author/catalog/repository metadata to personal open-source distribution (`Arnold Wender`, `https://arnoldwender.com/wordpress-plugins/wm-dsgvo-newsletter-suite/`, `https://github.com/arnoldwender/wm-wp-dsgvo-newsletter-suite`), normalized FSF GPL-2.0 `LICENSE` metadata where applicable, and updated `.wordpress-org/` banner branding to `ARNOLD WENDER · OPEN SOURCE`.

## [Unreleased]

## [1.1.3] - 2026-11-19

### Added
- Official `.wordpress-org/` directory visual kit (`icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`) and live Playwright screenshots (`screenshot-1.png` through `screenshot-3.png`) with `== Screenshots ==` in `readme.txt`.
- Visual previews and direct link to `https://arnoldwender.com/wordpress-plugins/wm-dsgvo-newsletter-suite/` in `README.md`, `README.de.md`, `README.es.md`, and `llms.txt`.

### Changed
- Archived superseded early banners and screenshots non-destructively in `versionen/visuals-pre-2026-11-19/` and excluded `versionen/` from distribution packages via `.distignore`.
- Updated release metadata and SBOM (`bom.json`) to `1.1.3`.

---

## [1.1.2] - 2026-11-19

### Changed
- Point `Plugin URI` to `https://arnoldwender.com/wordpress-plugins/wm-dsgvo-newsletter-suite/` and align release metadata at `v1.1.2`.

---

## [1.1.1] - 2026-11-18

### Fixed & Changed
- **Multisite Activation & New-Site Provisioning:** Hooked `wp_initialize_site` in `wm-dsgvo-newsletter-suite.php` to provision newly created Multisite blogs via `switch_to_blog()`, `Core\Activator::activate()`, and `restore_current_blog()` inside `try/finally` when network-activated; updated `Activator::activate( bool $network_wide = false )` to loop over `get_sites()` on network-wide activation and `Schema::create_tables()` to guard `dbDelta()` cleanly before invocation.
- **Option Autoload Hygiene:** Passed explicit `$autoload = false` when writing `wm_newsletter_settings` in `Activator::activate()` and `SettingsManager::update_all()`.
- **Verification & Metadata:** Added standalone Multisite/autoload unit tests (`tests/test-suite.php`, 152 assertions total) and 7 mutation checks (`tests/mutations.php`, 64 mutations total); updated version metadata (`1.1.1`) and `bom.json` SHA-256.

---

## [1.1.0] - 2026-11-16

### Added
- **REST Public Access Rate Limiting:** Replaced `__return_true` on `/subscribe`, `/altcha-challenge`, and `/unsubscribe` with `SubscriptionController::check_public_subscription_access()`, enforcing transient-based IP HMAC-SHA256 rate limiting (`wm_nl_rl_rest_*`, default 60 requests per 60-second window, filterable via `wm_newsletter_rest_rate_limit`, bypassed for `manage_options`, never storing raw IP addresses).
- **Opt-In Data Deletion on Uninstall:** Updated `uninstall.php` to preserve `wm_newsletter_settings`, subscribers, and DOI consent records by default unless the site administrator explicitly enables `wm_newsletter_delete_data_on_uninstall` on the settings screen (registered via `register_setting`).
- **Developer Hooks & Ecosystem Integrations:** Added actions `wm_newsletter_ready` and `wm_newsletter_confirmed`, filters `wm_newsletter_rest_rate_limit` and `wm_newsletter_subscriber_payload`, WordPress 6.5+ Block Bindings source `wm-newsletter/stats`, and GenerateBlocks 2.x Dynamic Tag `{{wm_newsletter_stat}}`.
- **Full Multilingual Catalogs, SBOM & Playground Blueprint:** Regenerated `languages/wm-dsgvo-newsletter-suite.pot` and compiled complete translations across all 25 shipped `.po`/`.mo` locale catalogs, added CycloneDX 1.6 `bom.json`, `.license-allowlist.yml`, `.wordpress-org/blueprints/blueprint.json`, and symlinked `AGENTS.md` to `CLAUDE.md`.

### Changed & Fixed
- **Escaping & Debug Logging Hygiene:** Consolidated confirmation page heading escaping onto a single `esc_html()` expression in `FormRenderer.php` and guarded diagnostic `error_log()` calls in `ErrorSanitizer.php`, `RequestShield.php`, and `SyncQueue.php` behind `WP_DEBUG`.
- **Translation Catalog Freshness (2026-09-20):** Extracted all translatable strings into `languages/wm-dsgvo-newsletter-suite.pot` via `wp i18n make-pot` and gated freshness in `tests/test-pot-freshness.php` and CI.
- **UI Family Sheets (2026-09-16):** Synced `assets/css/wm-admin-tokens.css` and `assets/css/wm-admin-ui.css` with canonical hub copies pinned by `tests/test-ui-family.php`.
- **Reader Pass D1–D12 (2026-09-15):**
  - `DoiMailer::build_unsubscribe_url()` URL-encodes plus addresses (`rawurlencode()`) so `add_query_arg()` preserves `+` and token verification succeeds (D1).
  - Empty shortcode `list_id` (and legacy cached `default`) resolves to `default_list_id` from settings (`SubscriptionController::resolve_list_and_basis()`) (D2).
  - `DoiRepository::get_stats()` separates `synced_to_provider`, `kept_local`, and `held_unsubscribed` so unsubscribed rows no longer hold hub health on `DEGRADED` and local-only rows are reported accurately (D3, D4).
  - `RateLimiter::check()` uses a fixed window starting from the first request so steady visitors are not locked out and refused requests do not extend the window (D5).
  - `RequestShield::guard_rest_request()` compares the `/wm-newsletter/v1/` prefix case-insensitively (D6).
  - `DoiRepository::create_pending_doi()` verifies insert success before deleting older pending rows and returns id 0 on storage failure so no dead confirmation mail is sent (D7).
  - Re-clicking a confirmation link whose provider push is in `SYNC_FAILED` reports "already confirmed" instead of 410 (D8).
  - `CleverReachAdapter` sends `registered` and `activated` timestamps converted from the staging row's site-clock `created_at` and `confirmed_at` (D9).
  - `tests/test-newsletter-suite.php` requires `WM_NL_INTEGRATION_DISPOSABLE=1` before writing to a WordPress install (D10).
  - Removed unused `ProviderType` enum, `DoiState` colour helpers, and uncalled provider metadata methods (D11).
  - `WebhookController` validates scalar string email inputs and handles Brevo `spam` and `contact_deleted` events (D12).
- **Runtime & Security Pass (2026-09-15):**
  - Aligned `ProviderResult::success()` and `ProviderResult::failure()` signatures with all provider adapters and excluded email addresses from failure log lines.
  - Registered the `every_five_minutes` WP-Cron schedule on `cron_schedules` and scheduled sync and cleanup hooks on `init`.
  - Implemented Web Crypto proof-of-work solving in `assets/js/frontend-form.js`, single-use challenge consumption with expiry in `AltchaVerifier`, and `textContent` status rendering.
  - Enforced server-side resolution of active provider, consent text (`FormRenderer::consent_record()`), HMAC-signed list and legal basis, and mandatory consent flag on `/subscribe`.
  - Implemented hourly deletion of expired pending rows in the site's time zone (`cutoff_for()`), persistent `webhook_secret` and `altcha_hmac_key` generation in `SettingsManager`, route-scoped `RequestShield`, CWE-1236 CSV formula neutralization in `AdminDashboard::csv_cell()`, and token-verified one-click unsubscribe (`DoiRepository::unsubscribe_token()`).
- **Documentation & Verification:** Updated `README.md`, `README.de.md`, `README.es.md`, translated documentation under `docs/translations/`, `DEVELOPERS.md`, `SECURITY.md`, and `readme.txt`; expanded standalone suite (`tests/test-suite.php`) to 143 assertions and `tests/mutations.php` to 57 mutations.

---

## [1.0.0] — 2026-08-25

### Initial Release
- Initial architecture for double opt-in newsletter sign-ups, staging and subscriber tables, provider adapters (`local`, Brevo, Rapidmail, CleverReach, Kit, Mailchimp), admin dashboard, WP-CLI commands (`wp wm-newsletter`), and WM Suite Hub spoke integration.
