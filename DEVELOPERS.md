# WM DSGVO Newsletter Suite — Developer Manual

> Every entry in this manual is measured against version `1.1.3` of the code in this repository.

---

## 1. Layout

| Path | Role |
| --- | --- |
| `wm-dsgvo-newsletter-suite.php` | Header (`1.1.3`), constants (`WM_NEWSLETTER_VERSION` / `_FILE` / `_PATH` / `_URL` / `_PLUGIN_URL` / `_PLUGIN_BASENAME`), PSR-4 autoloader for `WMCyber\Newsletter\` → `includes/`, activation, deactivation, `wp_initialize_site` Multisite provisioning when network-activated, hub registration on `wm_register_suite_module`, boot on `plugins_loaded`. |
| `includes/Core/Plugin.php` | Singleton holding repository, settings, mailer and queue; text domain on `init`, request check, REST routes, admin, form, ecosystem integrations, queue, WP-CLI command `wm-newsletter`, and `do_action( 'wm_newsletter_ready', $this )`. |
| `includes/Core/Activator.php` / `Deactivator.php` | Tables, default settings (`$autoload = false`) with both secrets, hourly cleanup event (per-site or across `get_sites()` on `$network_wide = true`) / unschedule both events. |
| `includes/Database/Schema.php` | Table names and `create_tables()` (`dbDelta`, guarded before require and call). |
| `includes/Database/DoiRepository.php` | Hashes, `unsubscribe_token()`, `cutoff_for()`, `create_pending_doi()`, `confirm_token_atomic()` (fires `wm_newsletter_confirmed`), `upsert_subscriber_local()`, `unsubscribe()`, `mark_synced()`, `mark_sync_failed()`, `get_pending_sync_queue()`, `purge_expired_pending()`, `delete_pending()`, `get_stats()`, `get_staging_records()`. |
| `includes/Admin/SettingsManager.php` | Option `wm_newsletter_settings` (written with `$autoload = false` in `update_all()`), opt-in flag `wm_newsletter_delete_data_on_uninstall` (`$autoload = false`), stored secrets, `PROVIDERS`, `ttl_hours()`, `missing_provider_settings()`. |
| `includes/REST/SubscriptionController.php` | `/subscribe`, `/altcha-challenge`, `/unsubscribe`, `check_public_subscription_access()` (transient-based IP HMAC-SHA256 rate limit, filter `wm_newsletter_rest_rate_limit`), `resolve_list_and_basis()`. |
| `includes/REST/WebhookController.php` | `/webhook`, `UNSUBSCRIBE_EVENTS`. |
| `includes/Mailer/DoiMailer.php` | Confirmation mail, `build_unsubscribe_url()`. |
| `includes/Queue/SyncQueue.php` | `SYNC_HOOK`, `CLEANUP_HOOK`, `SCHEDULE`, `register_schedule()`, `ensure_scheduled()`, `process_queue()` (filter `wm_newsletter_subscriber_payload`), `last_run()`, `hourly_cleanup()`. |
| `includes/Frontend/FormRenderer.php` | `[wm_dsgvo_newsletter]`, `form_signature()`, `consent_html()`, `consent_record()`, asset registration, confirmation page. |
| `includes/Frontend/EcosystemIntegrations.php` | WordPress 6.5+ Block Bindings source `wm-newsletter/stats` and GenerateBlocks 2.x Dynamic Tag `{{wm_newsletter_stat}}`. |
| `includes/Security/AltchaVerifier.php` | `create_challenge()`, `verify()`, `solve()`. |
| `includes/Security/RateLimiter.php` | Fixed window per action and IP (`check( action, limit, window, ip, now )`). |
| `includes/Security/RequestShield.php` | `rest_pre_dispatch` check for `wm-newsletter/v1`. |
| `includes/Security/ErrorSanitizer.php` | Incident reference for visitor-facing errors, details to the error log when `WP_DEBUG` is enabled. |
| `includes/Providers/*` | `NewsletterProviderInterface` (add, update, delete, status, test connection), `ProviderFactory`, local, Brevo, Rapidmail, CleverReach, Kit, Mailchimp. |
| `includes/Domain/*` | `SubscriberDTO` (row fields plus `confirmedAt` and `createdAt`), `ProviderResult`, enums `DoiState`, `SubscriberStatus`. |
| `includes/CLI/NewsletterCommand.php` | `sync`, `purge-expired`, `purge`, `stats`. |
| `includes/Admin/AdminDashboard.php` | Menu, `register_setting` for `wm_newsletter_delete_data_on_uninstall`, family sheet enqueue, four tabs, settings save, sync button, CSV export, dashboard widget. |
| `includes/Adapters/Newsletter_Spoke_Adapter.php` | WM Suite Hub contract; returns early when the hub's interface does not exist. |
| `assets/css/frontend-form.css` / `assets/js/frontend-form.js` | Form and confirmation page (tokens scoped to the plugin's boxes) / proof of work and submission. |
| `assets/css/wm-admin-*.css` | Copies of the hub's canonical sheets, pinned by `tests/test-ui-family.php`. |
| `uninstall.php` | Unschedules both WP-Cron events; preserves options and both tables unless `wm_newsletter_delete_data_on_uninstall` is enabled. |
| `bom.json` | CycloneDX 1.6 Software Bill of Materials (`GPL-2.0-or-later`, SHA-256 of `wm-dsgvo-newsletter-suite.php`). |

---

## 2. Storage

`wp_wm_newsletter_doi_staging`: `id`, `email`, `token_hash` (SHA-256, UNIQUE), `blinded_ip` (HMAC-SHA256 with `AUTH_SALT`), `consent_snapshot_hash` (SHA-256 of `consent_text`), `consent_text`, `attributes_json`, `tags_json`, `list_id`, `provider_adapter`, `legal_basis`, `form_id`, `status` (`PENDING_CONFIRMATION`, `CONFIRMED`, `SYNCED_TO_PROVIDER`, `SYNC_FAILED`, `EXPIRED`, `PURGED`), `created_at` (site time), `confirmed_at`, `synced_at`.

`wp_wm_newsletter_subscribers`: `id`, `email` (UNIQUE), `blinded_ip`, `status` (`subscribed`, `unsubscribed`, `cleaned`), `attributes_json`, `tags_json`, `list_id`, `provider_id`, `provider_adapter`, `consent_hash`, `consent_text`, `legal_basis`, `form_id`, `confirmed_at`, `unsubscribed_at`, `updated_at`.

| Setting / Option | Default | Read by |
| --- | --- | --- |
| `wm_newsletter_settings['active_provider']` | `local` | sign-up (provider stored with the row), queue fallback, adapter, admin |
| `wm_newsletter_settings['doi_ttl_hours']` | 48 | `ttl_hours()` → confirmation, mail, cleanup, CLI |
| `wm_newsletter_settings['sender_name']`, `['sender_email']`, `['doi_email_subject']` | site name, admin email, German subject | mailer |
| `wm_newsletter_settings['altcha_enabled']`, `['altcha_hmac_key']` | 1, generated 64 characters | sign-up, challenge route, adapter self test |
| `wm_newsletter_settings['enable_rfc8058']` | 1 | mailer |
| `wm_newsletter_settings['webhook_secret']` | generated 32 characters | webhook, settings tab |
| `wm_newsletter_settings['default_list_id']` | `default` | sign-up without a list, with an empty signed list or with the value `default` (`resolve_list_and_basis()`) |
| `wm_newsletter_settings['legal_notice_url']`, `['privacy_policy_url']` | `/impressum/`, `/datenschutz/` | mailer, form, consent record |
| `wm_newsletter_settings['brevo_api_key']`, `['rapidmail_username']`, `['rapidmail_password']`, `['cleverreach_token']`, `['kit_api_key']`, `['mailchimp_api_key']` | empty (overridable via `WM_NEWSLETTER_*` constants) | provider factory, `missing_provider_settings()` |
| `wm_newsletter_db_version` | `2` (`autoload = false`) | `Schema::maybe_upgrade()`, `uninstall.php` |
| `wm_newsletter_delete_data_on_uninstall` | `0` | `SettingsManager::delete_data_on_uninstall()`, `uninstall.php` |

Transients:
- `wm_nl_rl_rest_<32-hex-hmac>` (public REST access gate: `{start, count}`, 60-second window, HMAC-SHA256 of IP).
- `wm_nl_rl_<md5(action_ip)>` (route action limiter: `{start, count}`, 60-second window).
- `wm_nl_altcha_<challenge prefix>` (used challenge, until its expiry).

---

## 3. Sign-up and confirmation

| Step | Code | Behaviour |
| --- | --- | --- |
| REST Gate | `check_public_subscription_access()` | Bypasses for `manage_options`; otherwise enforces `wm_newsletter_rest_rate_limit` (default 60 / 60 s per HMAC-blinded IP), returning `WP_Error( 'wm_newsletter_rate_limited', …, [ 'status' => 429 ] )` when exceeded. |
| Challenge | `get_altcha_challenge()` | `salt = 24 hex + '?expires=' + (now + 600)`, `challenge = sha256(salt . n)` for a random `n ≤ 50000`, `signature = hmac_sha256(challenge, altcha_hmac_key)`; `Cache-Control: no-store`. |
| Sign-up | `handle_subscribe()` | Rate limit 5 per fixed 60 s window per IP (429) → honeypot (`wm_hp_name`, `website_field`: 200, nothing stored) → `AltchaVerifier::verify()` when enabled (403) → `is_email` (400) → `consent` truthy (400) → `resolve_list_and_basis()` (400 `invalid_form` without valid signature) → `create_pending_doi()` with settings provider and `consent_record()` (500 `storage_failed` if insert fails) → confirmation mail (500 + row deleted if mail fails). |
| Verify | `AltchaVerifier::verify()` | Signature (`hash_equals`), `expires` in the salt not past, `sha256(salt . number)` equals the challenge, challenge not used; then marks it used. |
| Confirm | `FormRenderer::handle_confirmation_page()` → `confirm_token_atomic( token, ttl_hours() )` | `START TRANSACTION` and `SELECT … FOR UPDATE` by token hash. Unknown → 404; `CONFIRMED`/`SYNCED_TO_PROVIDER`/`SYNC_FAILED` → 200 `already`; other non-pending → 410; `created_at < cutoff_for( ttl )` → `EXPIRED`, 410; else `CONFIRMED`, `upsert_subscriber_local()`, `COMMIT`, `do_action( 'wm_newsletter_confirmed', $email, $row )`. |
| Cleanup | `purge_expired_pending( ttl )` on `wm_newsletter_hourly_cleanup` | `DELETE … WHERE status IN ('PENDING_CONFIRMATION','EXPIRED') AND created_at < cutoff`. |

---

## 4. Provider push

`process_queue( $batchSize = 25 )` reads `get_pending_sync_queue()`: staging rows in `CONFIRMED` or `SYNC_FAILED`, `LEFT JOIN` subscribers on `email`, kept only when the subscriber row is missing or `subscribed`, oldest first. Outgoing `attributes`, `tags` and `list_id` pass through `apply_filters( 'wm_newsletter_subscriber_payload', $payload, $email, $providerId )` without modifying stored consent records. Success → `mark_synced( id, providerSubscriberId )`; failure → `mark_sync_failed( id )` and (under `WP_DEBUG`) `error_log( "[WM-NEWSLETTER-SYNC-FAIL] staging #id via provider: message (code)" )`.

| Adapter | `add_subscriber()` | Other methods |
| --- | --- | --- |
| `LocalDatabaseProvider` | success `local_<md5>`; no request | `update`/`delete`: failure `not_implemented`; status `UNKNOWN` |
| `BrevoAdapter` | `POST https://api.brevo.com/v3/contacts`, header `api-key`, `updateEnabled`, `listIds` when numeric; 201/204 or 400 "Contact already exist" → success | `PUT`/`DELETE /v3/contacts/{email}` with 2xx check; status from `GET` (`emailBlacklisted`); `test_connection()` `GET /v3/account` |
| `RapidmailAdapter` | `POST https://apiv3.rapidmail.de/v3/recipients`, basic auth, `recipientlist_id` required | failure `not_implemented`; status `UNKNOWN`; `test_connection()` |
| `CleverReachAdapter` | `POST https://rest.cleverreach.com/v3/groups.json/{id}/receivers`, bearer, group id required; `registered` = `createdAt`, `activated` = `confirmedAt` converted via `get_gmt_from_date( …, 'U' )` | failure `not_implemented`; status `UNKNOWN`; `test_connection()` |
| `KitAdapter` | `POST https://api.kit.com/v4/subscribers`, header `X-Kit-Api-Key` | failure `not_implemented`; status `UNKNOWN`; `test_connection()` |
| `MailchimpAdapter` | `PUT https://{dc}.api.mailchimp.com/3.0/lists/{id}/members/{md5}`, basic auth, data centre from the key suffix, audience id required | failure `not_implemented`; status `UNKNOWN`; `test_connection()` |

---

## 5. Hooks, Block Bindings & GenerateBlocks

### Actions
- `wm_newsletter_ready( Plugin $plugin )` — fired at the end of `Plugin::boot()`.
- `wm_newsletter_confirmed( string $email, array $stagingRow )` — fired once when a pending DOI token transitions to `CONFIRMED`.

### Filters
- `wm_newsletter_rest_rate_limit( int $limit, WP_REST_Request $request ): int` — filters the per-IP request limit (default `60` per 60 seconds) enforced by `SubscriptionController::check_public_subscription_access()`.
- `wm_newsletter_subscriber_payload( array $payload, string $email, string $providerId ): array` — filters outgoing `['attributes' => ..., 'tags' => ..., 'list_id' => ...]` right before provider sync without altering stored consent evidence.

### Block Bindings & Dynamic Tags
- **WordPress 6.5+ Block Bindings:** source `wm-newsletter/stats` (`source_args.key` in `active_subscribers`, `pending_doi`, `synced_to_provider`, `kept_local`, `waiting_for_sync`; default `active_subscribers`).
- **GenerateBlocks 2.x Dynamic Tag:** `{{wm_newsletter_stat}}` (`key` option selects the counter, escaped via `esc_html()`, with fallback on `generateblocks_dynamic_tag_output`).

---

## 6. Unsubscribe, webhook, request check

- `handle_unsubscribe()`: 10 per fixed 60 s window per IP (429), valid address (400), `hash_equals( unsubscribe_token( email ), token )` (403), then `unsubscribe()` (local row only). `DoiMailer::build_unsubscribe_url()` passes the address through `rawurlencode()`.
- `WebhookController::verify_webhook_permission()`: stored `webhook_secret` equals `key` or the bearer token (constant-time); `handle_remote_webhook()` reads `email` or `data.email` only when it is a non-empty string, and `event`/`type` (default `unsubscribe`); `UNSUBSCRIBE_EVENTS` = `unsubscribe`, `spam`, `contact_deleted`, `unsub`, `complaint`, `deleted`.
- `RequestShield::guard_rest_request()` on `rest_pre_dispatch`: only routes starting with `/wm-newsletter/v1/`, compared in lower case; refuses stream wrappers, NUL bytes, `%00`, `\0`, `base64_decode(`, `eval(`, `passthru(` with 400.

---

## 7. WP-CLI

| Command | Effect |
| --- | --- |
| `wp wm-newsletter sync [--batch-size=<n>]` | `process_queue( n )` (default 50); prints `last_run()` (external, local, failed) and the current `waiting_for_sync`, `sync_failed` and `held_unsubscribed` counts. |
| `wp wm-newsletter purge-expired` / `purge` | `purge_expired_pending( ttl_hours() )`; prints deleted rows. |
| `wp wm-newsletter stats` | `get_stats()` as a table. |

---

## 8. WM Suite Hub spoke

| Method | Behaviour |
| --- | --- |
| `get_health_status()` | `ERROR` when a table is missing; `NEEDS_CONFIG` when `missing_provider_settings()` is not empty; `DEGRADED` with `SYNC_FAILED` rows the queue will retry or no scheduled `SYNC_HOOK`; `HEALTHY` otherwise. |
| `get_telemetry_metrics()` | `tables_present`, `confirmed_subscribers`, `unsubscribed`, `pending_doi_requests`, `waiting_for_sync`, `failed_esp_syncs`, `synced_to_provider`, `kept_local`, `held_unsubscribed`, `active_esp_adapter`, `missing_settings`, `doi_ttl_hours`, `proof_of_work`, `rfc8058_header`, `sync_event_scheduled`. |
| `execute_action()` | `self_test`: challenge with `maxnumber` 2000, solved and verified without consuming, plus tables and schedule; `queue_health`: counts, success when no failures; other actions fail. |
| `get_sbom_data()` | One component (`GPL-2.0-or-later`), SHA-256 of `wm-dsgvo-newsletter-suite.php`, feature properties. |
| `get_settings_url()` | `admin.php?page=wm-newsletter-suite&tab=settings`. |

---

## 9. Tests

```bash
php tests/test-suite.php         # standalone, mocked wpdb, cron, mail and HTTP; 152 assertions
php tests/test-pot-freshness.php # every translatable string has an entry in languages/wm-dsgvo-newsletter-suite.pot
bash tests/run-mutations.sh      # 64 mutations from tests/mutations.php, control run first
python3 .github/scripts/security-audit.py
```

---

## 10. Security disclosure

See [SECURITY.md](SECURITY.md).
