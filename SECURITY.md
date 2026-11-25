# Security Policy — WM DSGVO Newsletter Suite

## Supported Versions

| Version | Supported |
| --- | --- |
| `1.2.x` (`1.2.2`) | Current release branch |

---

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities, and **do not** send private inquiries or support emails to `arnoldwender.com`, email addresses, or social/direct messages (private channels are unmonitored for open-source plugins).

Submit security reports **exclusively** via GitHub Private Vulnerability Reporting:

- **Security Advisories:** [github.com/arnoldwender/wm-wp-dsgvo-newsletter-suite/security/advisories/new](https://github.com/arnoldwender/wm-wp-dsgvo-newsletter-suite/security/advisories/new)

> **Best-Effort Only / Zero Warranty / Zero Support:** This open-source repository carries **zero technical or user support** ([SUPPORT.md](SUPPORT.md)) and **zero response-time SLA**. Advisory reports may be reviewed at the author's sole discretion without obligation, timeline, or liability ("AS IS" under [LICENSE](LICENSE)).

### What to include

1. Reproduction steps or a proof of concept.
2. Affected component (REST routes `/subscribe`, `/altcha-challenge`, `/unsubscribe`, `/webhook`; shortcode; confirmation page; admin screen; WP-CLI; hub adapter).
3. Impact as you see it.

### Response

Reports are acknowledged as soon as they are read and handled by one person; there is no guaranteed response time. Reporters are credited unless they ask otherwise.

### Surfaces worth knowing

- `/subscribe`, `/altcha-challenge` and `/unsubscribe` are public by design and guarded by `SubscriptionController::check_public_subscription_access()` (transient-based IP HMAC-SHA256 rate limiting, default 60 requests/minute, filterable via `wm_newsletter_rest_rate_limit`, bypassed for `manage_options`); `/subscribe` (5/min) and `/unsubscribe` (10/min + per-address HMAC token) enforce additional per-action limits.
- `/webhook` accepts its key via `Authorization: Bearer` or as `?key=`, which can appear in server access logs when passed in the query string.
- Provider credentials are stored in plain text in the option `wm_newsletter_settings`.
