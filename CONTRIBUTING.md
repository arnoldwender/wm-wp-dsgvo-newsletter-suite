# Contributing to WM DSGVO Newsletter Suite

Thank you for your interest in contributing to **WM DSGVO Newsletter Suite**.

---

## Zero Support & Issue Scope

**Zero technical, configuration, or private support is provided.** See [SUPPORT.md](SUPPORT.md):

- Report reproducible code defects **exclusively** via public [GitHub Issues](https://github.com/arnoldwender/wm-wp-dsgvo-newsletter-suite/issues).
- **Do not** contact the author privately via email, `arnoldwender.com`, or direct messages — private inquiries are never answered.
- Issues and pull requests carry no response or maintenance SLA, and the software is provided strictly **"AS IS"** without warranty or liability.

---

## Core Development Standards

1. **PHP 8.2+ with strict types:** every PHP file under `includes/` starts with `declare(strict_types=1);`. Check argument types against the signature of the method you call: a mismatch here once made every provider answer a TypeError.
2. **Self-hosted assets:** no CDNs, remote fonts or third-party scripts at runtime.
3. **The server decides the evidence:** provider, consent text, list and legal basis are never taken from a request without the form's signature.
4. **Measured statements only:** no legal verdicts ("DSGVO-konform", "rechtssicher"), no fixed figures, no emoji in the interface, mails or docs (the translated READMEs under `docs/translations/` keep the template's section emoji anchors). `README.md` and `DEVELOPERS.md` are measured against the code; keep them that way.
5. **Accessibility:** labelled fields, visible focus, live regions for results, touch targets of at least 44 px.

---

## Development Workflow

```bash
git clone https://github.com/arnoldwender/wm-wp-dsgvo-newsletter-suite.git
cd wm-dsgvo-newsletter-suite

php tests/test-suite.php        # standalone suite (CI)
bash tests/run-mutations.sh     # every fix must turn the suite red when undone
python3 .github/scripts/security-audit.py

# inside WordPress: read-only runtime check, and the integration test (disposable installs only)
wp eval-file wp-content/plugins/wm-dsgvo-newsletter-suite/tests/wp-runtime-newsletter.php --user=1
WM_NL_INTEGRATION_DISPOSABLE=1 wp eval-file wp-content/plugins/wm-dsgvo-newsletter-suite/tests/test-newsletter-suite.php --allow-root
```

A fix comes with a test that fails without it, and with a mutation in `tests/mutations.php`.

---

## Commit Conventions

- `[Feature] …`, `[Fix] …`, `[Security] …`, `[Docs] …`; the body explains why.
- User-visible changes get an entry under `[Unreleased]` in `CHANGELOG.md`. The header version is not bumped in a pull request.

For support and liability rules, read [SUPPORT.md](SUPPORT.md).
