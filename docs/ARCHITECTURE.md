# Architecture — WM DSGVO Newsletter Suite
`wm-dsgvo-newsletter-suite` · version `1.1.1` from the plugin header · WordPress 6.4+ · PHP 8.2+ · text domain `wm-dsgvo-newsletter-suite`

Measured against `02d62b4`. Every figure below comes from the code, not from any document — the commands are given so they can be re-run.

```bash
# the inventory in this file
python3 the fleet's architecture inventory tool wm-dsgvo-newsletter-suite
```

---

## How a sign-up flows

```
[wm_dsgvo_newsletter]      FormRenderer prints the form; assets load ONLY here
                             └─ the browser solves a proof of work before it may submit

POST  wm-newsletter/v1     rest_pre_dispatch check runs for THIS namespace only
                             └─ row in the staging table, status pending
                                confirmation mail with a token (only its hash is stored)

visitor opens the link     status confirmed, row moves to the subscribers table

wp_cron, every 5 minutes   Queue pushes confirmed rows to the configured provider
                             └─ ProviderResult::success|failure decides retry

purge_expired_pending()    deletes unconfirmed sign-ups after the window —
                             the SAME window the mail promised, in the site's clock

GET  /unsubscribe?token    token-protected, no login
POST provider webhook      bounces and unsubscribes from the provider side
```

---

## Wiring — what to touch for a given change

| Change | Files, in order | Trap |
|---|---|---|
| Add a provider | a class in `includes/Providers/` implementing the contract in `includes/Contracts/` → register it in the settings list → assertion + mutation | **the signature is exact**: `ProviderResult::failure( message, int\|string\|null code, bool retryable, array raw )` and `success( ?string id, array raw, string message )`. The mismatch that lived until 2026-09-15 made *every* provider answer a TypeError |
| Change the deletion window | the value **and** the sentence in the confirmation mail, together | the mail is a promise; `purge_expired_pending()` and `cutoff_for()` are what keep it |
| Change the consent evidence | `FormRenderer::consent_record()` and `form_signature()` | the **server** decides provider, consent text, list and legal basis. Never take them from the request — test group 8 pins this |
| Add a REST route | `includes/REST/` under the `wm-newsletter/v1` namespace | the request check is bound to that namespace on `rest_pre_dispatch`; a route outside it is ungated |
| Add a CLI subcommand | `includes/CLI/` → the `Usage:` docblock → README | `wp help` prints the docblock |

---

## Fragile spots

1. **The server decides the evidence.** Provider from the settings, consent text from `consent_record()`, list and legal basis only through `form_signature()`. A consent record assembled from request fields is a record of what the submitter claimed, not of what was shown.
2. **Types must match the `ProviderResult` signature exactly** — see the table. This failed silently across every provider for a release.
3. **Promises in the mail are kept by the code.** The window stated in the confirmation mail is the window the purge applies, evaluated in the site's clock.
4. **Nothing site-wide.** The request check runs on `rest_pre_dispatch` for `wm-newsletter/v1` only, and assets load only where the form is printed. Widening either turns a newsletter plugin into a site-wide request filter.
5. **Nothing before `init` may call a translation function.** Source strings are German with real umlauts.
6. **`tests/test-newsletter-suite.php` writes to the install it runs in** — including a confirmed row a cron run could actually push to a provider. It refuses to start without `WM_NL_INTEGRATION_DISPOSABLE=1`. Do not set that flag on a live site.

---

<!-- wm-measured-inventory -->

## Measured inventory

Regenerated from the code, never hand-edited. Everything above this line is written by hand and stays.

```bash
python3 the fleet's architecture inventory tool wm-dsgvo-newsletter-suite --append-to docs/ARCHITECTURE.md
```

## Inventory by layer

**33 PHP files, 4040 lines** (excluding `tests/`, `vendor/`, `node_modules/`, `languages/`).

| Layer | Files | Lines |
|---|---:|---:|
| `(raíz)` | 2 | 169 |
| `includes/Adapters` | 1 | 194 |
| `includes/Admin` | 2 | 768 |
| `includes/CLI` | 1 | 84 |
| `includes/Contracts` | 1 | 25 |
| `includes/Core` | 3 | 187 |
| `includes/Database` | 2 | 580 |
| `includes/Domain` | 4 | 132 |
| `includes/Frontend` | 2 | 318 |
| `includes/Mailer` | 1 | 97 |
| `includes/Providers` | 7 | 629 |
| `includes/Queue` | 1 | 167 |
| `includes/REST` | 2 | 398 |
| `includes/Security` | 4 | 292 |

---

## Classes

| Class | File |
|---|---|
| `Activator` | [`includes/Core/Activator.php`](../includes/Core/Activator.php) |
| `AdminDashboard` | [`includes/Admin/AdminDashboard.php`](../includes/Admin/AdminDashboard.php) |
| `AltchaVerifier` | [`includes/Security/AltchaVerifier.php`](../includes/Security/AltchaVerifier.php) |
| `BrevoAdapter` | [`includes/Providers/BrevoAdapter.php`](../includes/Providers/BrevoAdapter.php) |
| `CleverReachAdapter` | [`includes/Providers/CleverReachAdapter.php`](../includes/Providers/CleverReachAdapter.php) |
| `Deactivator` | [`includes/Core/Deactivator.php`](../includes/Core/Deactivator.php) |
| `DoiMailer` | [`includes/Mailer/DoiMailer.php`](../includes/Mailer/DoiMailer.php) |
| `DoiRepository` | [`includes/Database/DoiRepository.php`](../includes/Database/DoiRepository.php) |
| `EcosystemIntegrations` | [`includes/Frontend/EcosystemIntegrations.php`](../includes/Frontend/EcosystemIntegrations.php) |
| `ErrorSanitizer` | [`includes/Security/ErrorSanitizer.php`](../includes/Security/ErrorSanitizer.php) |
| `FormRenderer` | [`includes/Frontend/FormRenderer.php`](../includes/Frontend/FormRenderer.php) |
| `KitAdapter` | [`includes/Providers/KitAdapter.php`](../includes/Providers/KitAdapter.php) |
| `LocalDatabaseProvider` | [`includes/Providers/LocalDatabaseProvider.php`](../includes/Providers/LocalDatabaseProvider.php) |
| `MailchimpAdapter` | [`includes/Providers/MailchimpAdapter.php`](../includes/Providers/MailchimpAdapter.php) |
| `NewsletterCommand` | [`includes/CLI/NewsletterCommand.php`](../includes/CLI/NewsletterCommand.php) |
| `Newsletter_Spoke_Adapter` | [`includes/Adapters/Newsletter_Spoke_Adapter.php`](../includes/Adapters/Newsletter_Spoke_Adapter.php) |
| `Plugin` | [`includes/Core/Plugin.php`](../includes/Core/Plugin.php) |
| `ProviderFactory` | [`includes/Providers/ProviderFactory.php`](../includes/Providers/ProviderFactory.php) |
| `ProviderResult` | [`includes/Domain/DTO/ProviderResult.php`](../includes/Domain/DTO/ProviderResult.php) |
| `RapidmailAdapter` | [`includes/Providers/RapidmailAdapter.php`](../includes/Providers/RapidmailAdapter.php) |
| `RateLimiter` | [`includes/Security/RateLimiter.php`](../includes/Security/RateLimiter.php) |
| `RequestShield` | [`includes/Security/RequestShield.php`](../includes/Security/RequestShield.php) |
| `Schema` | [`includes/Database/Schema.php`](../includes/Database/Schema.php) |
| `SettingsManager` | [`includes/Admin/SettingsManager.php`](../includes/Admin/SettingsManager.php) |
| `SubscriberDTO` | [`includes/Domain/DTO/SubscriberDTO.php`](../includes/Domain/DTO/SubscriberDTO.php) |
| `SubscriptionController` | [`includes/REST/SubscriptionController.php`](../includes/REST/SubscriptionController.php) |
| `SyncQueue` | [`includes/Queue/SyncQueue.php`](../includes/Queue/SyncQueue.php) |
| `WebhookController` | [`includes/REST/WebhookController.php`](../includes/REST/WebhookController.php) |

---

## Entry points

**REST routes:** `/altcha-challenge`, `/subscribe`, `/unsubscribe`, `/webhook`

**WP-CLI:** `wm-newsletter`

**Shortcodes:** `wm_dsgvo_newsletter`

---

## Persistence — who writes each key and who reads it

| Key | Written by | Read by |
|---|---|---|
| `wm_newsletter_delete_data_on_uninstall` | `includes/Admin/SettingsManager.php` | `includes/Admin/SettingsManager.php`, `uninstall.php` |
| `wm_newsletter_settings` | `includes/Core/Activator.php` | `includes/Core/Activator.php` |

Every key has exactly one writing layer and at least one reader.


**Tables** (prefixed with `$wpdb->prefix`): `wm_newsletter_doi_staging`, `wm_newsletter_subscribers`

---

## Gates

- `tests/mutations.php`
- `tests/run-mutations.sh`
- `tests/test-newsletter-suite.php`
- `tests/test-pot-freshness.php`
- `tests/test-suite.php`
- `tests/test-ui-family.php`
- `tests/wp-runtime-newsletter.php`
- `.github/workflows/ci.yml`

What CI actually runs is in the workflow files above — a test file that no workflow invokes is not a gate.

---

## Drift detected against the documentation

Nothing measurable: versions agree and every class named in the documents exists.

