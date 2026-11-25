# WM DSGVO Newsletter Suite

<p align="center">
  <a href="README.md"><img src="https://img.shields.io/badge/Language-English%20(Default)-blue.svg" alt="English Documentation" /></a>
  <a href="README.de.md"><img src="https://img.shields.io/badge/Sprache-Deutsch-lightgrey.svg" alt="Deutsche Dokumentation" /></a>
  <a href="README.es.md"><img src="https://img.shields.io/badge/Idioma-Espa%C3%B1ol-lightgrey.svg" alt="Documentación en Español" /></a>
  <a href="#translated-documentation"><img src="https://img.shields.io/badge/Translated%20READMEs-24%20(headings%20only)-lightgrey.svg" alt="24 translated READMEs, headings only" /></a>
</p>

<p align="center">
  <a href="https://wordpress.org"><img src="https://img.shields.io/badge/WordPress-6.4%20--%207.1+-21759b.svg?logo=wordpress&logoColor=white" alt="WordPress Version" /></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.2%20%7C%208.3-777bb4.svg?logo=php&logoColor=white" alt="PHP Version" /></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img src="https://img.shields.io/badge/License-GPLv2%20or%20later-brightgreen.svg" alt="License" /></a>
</p>

> **A double opt-in sign-up form for WordPress: a consent checkbox and a proof of work in the browser, a confirmation mail, one record per sign-up with the consent text, its hash and a keyed hash of the IP, and a server-side push of confirmed addresses to Brevo, Rapidmail, CleverReach, Kit or Mailchimp — it sends no newsletters, does not pass unsubscribes on to the provider and makes no legal assessment of consent text or mailing**  
> **Plugin page:** [https://arnoldwender.com/wordpress-plugins/wm-dsgvo-newsletter-suite/](https://arnoldwender.com/wordpress-plugins/wm-dsgvo-newsletter-suite/)  
> **Built with an agentic workflow:** developed with AI coding agents. Review and test it like any third-party code before you use it in production.  
> **Support & Liability:** Zero support — reproducible bugs on [GitHub Issues](https://github.com/arnoldwender/wm-wp-dsgvo-newsletter-suite/issues) only · no private support · AS IS / no liability ([SUPPORT.md](SUPPORT.md))

> Every statement in this README is measured against the code in this repository: shortcode `[wm_dsgvo_newsletter]`, WP-CLI `wp wm-newsletter`, REST routes `/subscribe`, `/altcha-challenge`, `/unsubscribe` and `/webhook` under `wm-newsletter/v1`, custom tables `wp_wm_newsletter_doi_staging` and `wp_wm_newsletter_subscribers`, WordPress 6.5+ Block Bindings source `wm-newsletter/stats`, GenerateBlocks 2.x Dynamic Tag `{{wm_newsletter_stat}}`, and developer hooks `wm_newsletter_ready`, `wm_newsletter_confirmed`, `wm_newsletter_rest_rate_limit` and `wm_newsletter_subscriber_payload`. Release history and bug-fix details are recorded in [CHANGELOG.md](CHANGELOG.md).

---

## Showcase

!WM DSGVO Newsletter Suite banner

The banner above and the live WordPress screenshots below live in `.wordpress-org/`; the old marketing mockups were archived outside the repository on 2026-11-20 (release 1.2.0).

| Admin Overview | Double Opt-In Form | Dashboard Widget |
|---|---|---|
| ![Admin overview with the local subscriber counts, the provider and the state of the push to it](.wordpress-org/screenshot-1.png) | ![Frontend double opt-in subscription form rendered via [wm_dsgvo_newsletter]](.wordpress-org/screenshot-2.png) | ![WordPress dashboard widget with the subscribed, unconfirmed and failed-push counts](.wordpress-org/screenshot-3.png) |

---

## What the plugin does

1. **Form.** `[wm_dsgvo_newsletter]` prints a section with a heading (`title`, default "Newsletter abonnieren"), an optional `description`, a labelled address field, a button (`button_text`, default "Anmelden"), a consent checkbox with a link to the privacy policy from the settings, two honeypot fields and a status line. `list_id` (empty unless set, which stands for the list in the settings) and `legal_basis` (`art_6_1_a` or `art_6_1_b`) travel in hidden fields together with an HMAC signature of both. Stylesheet and script load only on pages that print the form.
2. **Proof of work.** Before sending, the script fetches `GET /wp-json/wm-newsletter/v1/altcha-challenge` and searches the number whose SHA-256 with the salt matches the challenge (Web Crypto, up to 50,000 attempts). A challenge is valid for 10 minutes and accepted once. The check can be switched off in the settings. All three public REST endpoints (`/subscribe`, `/altcha-challenge`, `/unsubscribe`) run through `check_public_subscription_access()`, which enforces a transient-based IP HMAC-SHA256 rate limit (`wm_nl_rl_rest_*`, default 60 requests per 60-second window, filterable via `wm_newsletter_rest_rate_limit`, bypassed for `manage_options`, never storing raw IP addresses).
3. **Sign-up.** `POST /wp-json/wm-newsletter/v1/subscribe` additionally limits submissions to 5 requests per client IP in a fixed 60-second window that starts with the first request (refused requests do not extend it), answers a filled honeypot with the normal success message without storing anything, and requires a valid proof of work (403), a valid address (400) and the consent flag (400). List and legal basis are accepted only with the form's signature; without them the list from the settings and `art_6_1_a` apply, and an empty signed list or the value `default` also stands for the list from the settings. The provider comes from the settings, the consent text is the sentence the form prints plus the privacy-policy URL. A row in `wp_wm_newsletter_doi_staging` stores address, SHA-256 of a random token, HMAC-SHA256 of the IP (key `AUTH_SALT`), consent text and its SHA-256, list, provider, legal basis, form id and status `PENDING_CONFIRMATION`; once that row is stored, an older pending row for the same address is deleted. If the row cannot be stored the answer is 500 and no mail goes out; if the mail cannot be sent the row is deleted and the answer is 500.
4. **Confirmation mail.** HTML mail from the configured sender with the link `/?wm_action=confirm_newsletter&token=…`, a note that an unconfirmed address is deleted after the configured window, and links to legal notice and privacy policy. With the RFC 8058 setting on (default) it carries `List-Unsubscribe` and `List-Unsubscribe-Post` headers pointing at the unsubscribe route with the URL-encoded address and its token.
5. **Confirmation.** The link opens a page in the site theme. Inside the window (`doi_ttl_hours`, 1–168, default 48, measured in the site's time zone) the row becomes `CONFIRMED`, the address is written to `wp_wm_newsletter_subscribers` as `subscribed`, and `do_action( 'wm_newsletter_confirmed', $email, $row )` fires; a second click answers "already confirmed", also after a failed push; an expired link marks the row `EXPIRED` and answers 410 until the hourly deletion removes the row, after which it answers 404.
6. **Provider push.** A WP-Cron event `wm_newsletter_process_sync_queue` every five minutes takes up to 25 rows in `CONFIRMED` or `SYNC_FAILED` whose address is not unsubscribed locally, runs outgoing `attributes`, `tags` and `list_id` through `apply_filters( 'wm_newsletter_subscriber_payload', $payload, $email, $provider )` without modifying the stored consent record, and sends them to the provider stored with the row: nothing leaves the site for `local`, and such rows count as kept local, not as handed over; Brevo `POST /v3/contacts` (list id when numeric), Rapidmail `POST /v3/recipients` (needs a list id), CleverReach `POST /v3/groups.json/{id}/receivers` (needs a group id; `registered` and `activated` are the row's sign-up and confirmation times, and a row without them is not sent), Kit `POST /v4/subscribers`, Mailchimp `PUT /3.0/lists/{id}/members/{hash}` with status `subscribed` (needs an audience id). An accepted contact becomes `SYNCED_TO_PROVIDER`, and the subscriber row gets the id the provider returned or, when it returns none, the address (Brevo, Rapidmail, CleverReach, Kit), the member hash (Mailchimp) or `local_<md5>`; a refused one becomes `SYNC_FAILED` (and logs row id, provider and answer when `WP_DEBUG` is enabled). Confirmed and failed rows of an address unsubscribed locally are not sent and are counted as held, not as waiting or failed. The admin button and `wp wm-newsletter sync` run the same batch and report handed-over, kept-local and failed rows separately.
7. **Deletion.** An hourly event `wm_newsletter_hourly_cleanup` deletes rows in `PENDING_CONFIRMATION` or `EXPIRED` older than the window.
8. **Unsubscribe.** `GET|POST /wp-json/wm-newsletter/v1/unsubscribe?email=…&token=…` (10 requests per IP per 60-second window) requires the token `HMAC-SHA256(lower-case address, wp_salt('auth'))` and sets the local subscriber row to `unsubscribed`.
9. **Webhook.** `GET|POST /wp-json/wm-newsletter/v1/webhook` authenticates with the stored key as `?key=` or `Authorization: Bearer`; an event `unsubscribe`, `spam`, `contact_deleted` (Brevo's names, checked in its webhook documentation on 2026-09-15), `unsub`, `complaint` or `deleted`, or no event, with a string in `email` or `data.email` sets the local row to `unsubscribed`; any other value in those fields is ignored. The settings tab shows the URL with the key.
10. **Request check.** Parameters of requests to `wm-newsletter/v1`, in any letter case of the route, containing stream wrappers (`php://`, `data://` …), NUL bytes or the PHP function names `base64_decode`, `eval` or `passthru` followed by a parenthesis are refused with 400. Other requests to the site are not inspected.
11. **Admin.** Menu *DSGVO Newsletter* (position 32), page `wm-newsletter-suite`, German source strings with 25 compiled `.po`/`.mo` locale catalogs: *Übersicht*, *Anmeldungen* (last 50 rows with their provider, CSV export of up to 5,000 rows with cells that start a formula prefixed by an apostrophe), *Einstellungen* (provider, sender, subject, window, URLs, switches, provider credentials, list id, webhook URL, and explicit opt-in checkbox `wm_newsletter_delete_data_on_uninstall` for data deletion on uninstall) and *Shortcode*. A dashboard widget shows subscribed, unconfirmed and failed pushes. Family sheets of the WM Suite admin.
12. **WP-CLI.** `wp wm-newsletter sync [--batch-size=<n>]` (prints what the run handed over, kept local and failed, plus the waiting, failed and held counts), `wp wm-newsletter purge-expired` (alias `purge`), `wp wm-newsletter stats`.
13. **WM Suite Hub spoke.** `includes/Adapters/Newsletter_Spoke_Adapter.php` registers through `wm_register_suite_module`: health `ERROR` without the tables, `NEEDS_CONFIG` when the active provider lacks its key or list id, `DEGRADED` with failed pushes that the next run retries or no scheduled sync event, `HEALTHY` otherwise; telemetry with counts (kept-local and held rows included), provider, missing settings, window, switches and schedule; actions `self_test` (proof-of-work round trip, tables, schedule) and `queue_health`; CycloneDX 1.6 `bom.json` and spoke SBOM component.
14. **Extensibility & block integrations.** Fires `wm_newsletter_ready` after boot and `wm_newsletter_confirmed` on DOI confirmation; filters `wm_newsletter_rest_rate_limit` and `wm_newsletter_subscriber_payload`; registers WordPress 6.5+ Block Bindings source `wm-newsletter/stats` and GenerateBlocks 2.x Dynamic Tag `{{wm_newsletter_stat}}` for aggregate non-personal subscriber counters (`active_subscribers`, `pending_doi`, `synced_to_provider`, `kept_local`, `waiting_for_sync`).

### Not shipped

The plugin sends no newsletters and has no templates, campaigns or open/click tracking; an unsubscribe is not passed on to the provider, and contacts already pushed stay subscribed there; there is no subscriber CSV import, no standalone Gutenberg form block, no attempt counter or back-off for failed pushes, no cryptographic hash chain or signature over database rows, no PDF export, and no legal assessment of whether consent text, confirmation mail, legal basis or provider meet statutory requirements. Apart from Brevo, the provider adapters implement only adding a contact.

---

## Quick start

1. Install and activate the plugin (WordPress 6.4+, PHP 8.2+). The tables are created on activation; the sync event is scheduled on the next load.
2. Under **DSGVO Newsletter › Einstellungen**, set sender, privacy-policy and legal-notice URLs, the window and the provider with its credentials; leave the provider on "Nur lokal" to keep addresses on the site.
3. Put `[wm_dsgvo_newsletter]` on a page served over HTTPS (the proof of work needs Web Crypto).
4. Sign up with an address you control, follow the mailed link and check **Anmeldungen** and **Übersicht**.
5. Enter the webhook URL from the settings at the provider if it should report unsubscribes back.

---

## Developers

Classes, routes, hooks, tables, settings and the hub adapter: **[DEVELOPERS.md](DEVELOPERS.md)**.

```bash
php tests/test-suite.php         # standalone suite: providers, secrets, PoW, sign-up, window, queue, shield, form, adapter, admin, uninstall, hooks
php tests/test-pot-freshness.php # every string the code translates has an entry in the .pot (needs wp-cli)
bash tests/run-mutations.sh      # undoes each fix once; the suite must fail every time
# inside WordPress, on a disposable install only; without the variable it exits with code 2 before writing:
WM_NL_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-newsletter-suite/tests/test-newsletter-suite.php --allow-root
```

### Known issues

- Unsubscribing through the plugin changes only the local table; the provider keeps the contact until it is removed there or the provider's own webhook reports back.
- Rows in `SYNC_FAILED` are retried on every run without a limit, so a wrong key produces the same failed call every five minutes. Rows of addresses unsubscribed locally are not retried; they keep their status and count as held.
- Anyone can request a confirmation mail for any address; the limit is 5 subscribe requests per 60-second window per IP as PHP sees it (plus the 60 req/min REST access gate; behind a proxy that does not pass on the visitor's address, all visitors share that limit), and a new sign-up replaces the pending one for that address.
- Records are ordinary database rows: the consent hash shows which text was stored, not that the row was never edited.
- Unsubscribe tokens and IP hashes depend on the WordPress salts; changing them in `wp-config.php` invalidates the links in mails already sent.
- The wording of the consent sentence, the confirmation mail and the messages is defined in the code and translated across the 25 shipped `.po`/`.mo` locale catalogs in `languages/`.
- The provider adapters were not verified against live accounts of every provider; endpoints and fields follow the REST v3/v4 adapter implementations tested against deterministic HTTP fixtures.
- An authenticated webhook call with an address but without an event counts as an unsubscribe; a key passed as `?key=` can appear in server logs.
- Opening the confirmation link confirms at once (a plain GET, no second click), so a mail security scanner that fetches links can confirm an address.
- With the proof of work on, visitors on plain HTTP (other than localhost) cannot submit the form.
- Provider credentials are stored in plain text in the option `wm_newsletter_settings` and printed back into the password fields of the settings screen.
- By default, uninstalling preserves `wm_newsletter_settings` and both tables (`wp_wm_newsletter_doi_staging`, `wp_wm_newsletter_subscribers`) so consent records are not lost accidentally; administrators can opt into full deletion via `wm_newsletter_delete_data_on_uninstall` on the settings screen.
- The translated READMEs under `docs/translations/` translate section headings while preserving the reference body from this file.

---

## Translated documentation

Translated READMEs live in `docs/translations/README.<lang>.md`; their headings are translated and their bodies are generated from this file, which is the reference.

| Language (Native) | Locale Code | Language (Native) | Locale Code |
|---|---|---|---|
| [Български](docs/translations/README.bg.md) | `bg_BG` | [Magyar](docs/translations/README.hu.md) | `hu_HU` |
| [Čeština](docs/translations/README.cs.md) | `cs_CZ` | [Italiano](docs/translations/README.it.md) | `it_IT` |
| [Dansk](docs/translations/README.da.md) | `da_DK` | [Lietuvių](docs/translations/README.lt.md) | `lt_LT` |
| [Deutsch](README.de.md) | `de_DE` | [Latviešu](docs/translations/README.lv.md) | `lv_LV` |
| [Ελληνικά](docs/translations/README.el.md) | `el_GR` | [Malti](docs/translations/README.mt.md) | `mt_MT` |
| [English](README.md) | `en_GB` | [Nederlands](docs/translations/README.nl.md) | `nl_NL` |
| [Español](README.es.md) | `es_ES` | [Polski](docs/translations/README.pl.md) | `pl_PL` |
| [Eesti](docs/translations/README.et.md) | `et_EE` | [Português](docs/translations/README.pt.md) | `pt_PT` |
| [Suomi](docs/translations/README.fi.md) | `fi_FI` | [Română](docs/translations/README.ro.md) | `ro_RO` |
| [Français](docs/translations/README.fr.md) | `fr_FR` | [Slovenčina](docs/translations/README.sk.md) | `sk_SK` |
| [Gaeilge](docs/translations/README.ga.md) | `ga_IE` | [Slovenščina](docs/translations/README.sl.md) | `sl_SI` |
| [Hrvatski](docs/translations/README.hr.md) | `hr_HR` | [Svenska](docs/translations/README.sv.md) | `sv_SE` |

---

## Compliance

- **What the plugin establishes:** that someone with access to a mailbox followed a link after a form with a consent checkbox was sent from a client whose IP hash is stored, which consent text was stored for that sign-up, and when. It does not decide whether that text, the confirmation mail, the legal basis or the chosen provider meet the requirements of advertising or data protection law.
- **Outbound requests:** one confirmation mail per sign-up through `wp_mail()`; with a provider other than `local`, the address, the list id and any attributes of confirmed sign-ups (for CleverReach also the sign-up and confirmation times) to that provider's API. Nothing else leaves the site; the browser never talks to the provider.
- **Personal data:** addresses with consent text, keyed IP hash and timestamps in both tables; unconfirmed rows are deleted after the window, confirmed and unsubscribed rows stay until they are deleted by hand or the plugin is uninstalled with data deletion opted in. REST access rate-limit transients use an HMAC-SHA256 hash of the IP (`wm_nl_rl_rest_*`), action rate-limit transients carry an MD5 of action and IP in their name and live until the end of their 60-second window, and proof-of-work transients hold a challenge hash until the challenge expires (at most ten minutes). Without a persistent object cache they sit in `wp_options`, where WordPress removes an expired transient when it is read again or in its daily cleanup.

---

## Contribution & Security

- [CONTRIBUTING.md](CONTRIBUTING.md) · [SECURITY.md](SECURITY.md) · [CHANGELOG.md](CHANGELOG.md)

---

## License

Distributed under the **GNU General Public License v2 or later** (`GPL-2.0-or-later`).  
Copyright (C) 2026 Arnold Wender.  
