/**
 * WM DSGVO Newsletter Suite — subscription form.
 *
 * Solves the proof-of-work challenge from GET wm-newsletter/v1/altcha-challenge and posts the form to
 * POST wm-newsletter/v1/subscribe. Until 2026-09-15 this file never requested a challenge, so with
 * the default setting (proof of work on) every submission was refused with 403; it also dropped the
 * honeypot fields and wrote server messages with innerHTML.
 */
(function () {
    'use strict';

    var data = window.wmNewsletterData || {};
    var strings = data.strings || {};

    // Hex SHA-256 through Web Crypto (available on HTTPS and localhost only).
    function sha256Hex(text) {
        return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(text)).then(function (buf) {
            return Array.prototype.map.call(new Uint8Array(buf), function (b) {
                return ('0' + b.toString(16)).slice(-2);
            }).join('');
        });
    }

    // Searches the number whose SHA-256(salt + number) equals the challenge; resolves null if none.
    async function solveChallenge(challenge) {
        var max = parseInt(challenge.maxnumber, 10) || 0;
        for (var n = 0; n <= max; n++) {
            if ((await sha256Hex(challenge.salt + n)) === challenge.challenge) {
                return n;
            }
        }
        return null;
    }

    async function altchaPayload() {
        var res = await fetch(data.restUrl + 'altcha-challenge', { credentials: 'same-origin', cache: 'no-store' });
        if (!res.ok) {
            throw new Error('challenge');
        }
        var challenge = await res.json();
        var number = await solveChallenge(challenge);
        if (number === null) {
            throw new Error('unsolved');
        }
        return window.btoa(JSON.stringify({
            algorithm: challenge.algorithm,
            challenge: challenge.challenge,
            number: number,
            salt: challenge.salt,
            signature: challenge.signature
        }));
    }

    function showFeedback(el, msg, type) {
        if (!el) {
            return;
        }
        el.className = 'wm-nl-feedback ' + type;
        el.textContent = msg;
        el.hidden = false;
    }

    function field(form, name) {
        var input = form.querySelector('[name="' + name + '"]');
        return input ? input.value : '';
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.wm-nl-form').forEach(function (form) {
            form.addEventListener('submit', async function (e) {
                e.preventDefault();

                var btn = form.querySelector('.wm-nl-btn');
                var label = form.querySelector('.wm-nl-btn-text');
                var feedback = form.querySelector('.wm-nl-feedback');
                var emailInput = form.querySelector('input[name="email"]');
                var consentInput = form.querySelector('input[name="consent"]');

                if (!emailInput || !emailInput.value || !emailInput.checkValidity()) {
                    emailInput && emailInput.reportValidity();
                    return;
                }
                if (consentInput && !consentInput.checked) {
                    showFeedback(feedback, strings.consent || '', 'error');
                    consentInput.focus();
                    return;
                }
                if (!window.crypto || !window.crypto.subtle) {
                    showFeedback(feedback, strings.noCrypto || strings.error || '', 'error');
                    return;
                }

                var originalText = label ? label.textContent : '';
                btn.disabled = true;
                if (label) {
                    label.textContent = strings.working || '';
                }

                try {
                    var altcha = await altchaPayload();
                    if (label) {
                        label.textContent = strings.submitting || '';
                    }

                    var response = await fetch(data.restUrl + 'subscribe', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            email: emailInput.value,
                            consent: consentInput ? consentInput.checked : false,
                            list_id: field(form, 'list_id'),
                            legal_basis: field(form, 'legal_basis'),
                            form_sig: field(form, 'form_sig'),
                            wm_hp_name: field(form, 'wm_hp_name'),
                            website_field: field(form, 'website_field'),
                            altcha: altcha
                        })
                    });
                    var body = await response.json();

                    if (response.ok && body.success) {
                        showFeedback(feedback, body.message || strings.success || '', 'success');
                        form.reset();
                    } else {
                        showFeedback(feedback, (body && body.message) || strings.error || '', 'error');
                    }
                } catch (err) {
                    showFeedback(feedback, strings.error || '', 'error');
                } finally {
                    btn.disabled = false;
                    if (label) {
                        label.textContent = originalText;
                    }
                }
            });
        });
    });
})();
