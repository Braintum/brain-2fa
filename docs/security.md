# Security model

Brain 2FA runs inside the WordPress authentication path. This document lists what it protects, the decisions behind the current design, and the invariants changes must keep. The working checklist for agents is `.claude/skills/wp-security/SKILL.md`.

## What it protects against

- **Stolen or guessed passwords.** A correct password is not enough when the account has 2FA enabled, or when its role requires 2FA and the grace period has expired.
- **Bypassing the JavaScript flow.** Enforcement happens in the `authenticate` filter, so direct `wp-login.php` POSTs, XML-RPC and any other path that uses `wp_authenticate()` go through `verify_2fa()`.
- **Credential stuffing through the preflight endpoint.** `brain2fa_login` is rate limited to 5 attempts per minute per IP (hashed with `wp_hash()`).
- **Secret leakage.** QR codes are rendered on the server. The `otpauth://` URI, which contains the secret, is never sent to a third party.
- **Database disclosure of long-lived tokens.** Recovery codes and trusted-device tokens are stored only as `wp_hash_password()` hashes.
- **TOTP code replay.** Each TOTP time step is accepted once per user, and never after a later step (`brain2fa_totp_last_step`), so an intercepted code cannot be reused within its validity window.

## Out of scope

- An attacker who already has a valid auth cookie, server access or admin access.
- Mail interception for the email method. Email is the weaker method; TOTP is the default.
- Rate limiting of direct `wp-login.php` submissions. That is left to the host or a dedicated login-limiting plugin.

## Design decisions

| Decision | Reason |
| --- | --- |
| No terminating nonce check on `brain2fa_login` | Full-page caches serve `wp-login.php` with expired nonces, and a hard check made valid sign-ins fail with HTTP 403. The endpoint does not change state; the rate limit plus WordPress's own authentication cover abuse. |
| Grace-period check in both `handle_login()` and `verify_2fa()` | The first gives a clear message in the UI; the second is the actual enforcement. |
| Same error for unknown user and wrong password | Avoids user enumeration through the preflight endpoint. |
| Deactivation clears secret, recovery codes and trusted devices | Re-enabling must start from fresh credentials. |
| Trusted devices capped at 10 per user, with expiry | Limits the impact of stale cookies; tokens are hashed at rest. |
| `hash_equals()` for email and TOTP codes | Constant-time comparison. |
| TOTP steps checked explicitly instead of otphp's `verify()` | otphp 11's `verify()` takes a leeway in seconds (not periods) and keeps no record of used codes. `TotpMethod::verify_totp_code()` checks the current step ±1 and stores the last accepted step in user meta (not a transient, which an object cache could evict). |

## Invariants

1. Every policy rule is enforced in `verify_2fa()`, and it fails closed (returns `WP_Error`) on missing or disabled methods.
2. The preflight endpoint never authenticates a session.
3. No secret, code or recovery code is logged, sent to a remote service, or rendered anywhere except the owning user's Login Security page at setup time.
4. Long-lived tokens are random (`random_int`, `wp_generate_password`) and hashed at rest.
5. Every admin state change checks both a capability and a nonce. The Login Security page only acts on `wp_get_current_user()`.
6. All output is escaped at the point of output, and all input is unslashed and sanitized.

## Known gaps and hardening backlog

These were observed in the code on 2026-10-16. Re-check them before relying on this list.

- **Concurrent TOTP submissions.** The last-step check and update are not atomic, so two requests carrying the same code at the same instant could both pass. Exploiting this needs the password plus the code within the same few milliseconds as the real user.
- **No per-user limit on failed codes.** `verify_2fa()` does not count invalid codes per user. The password must already be correct, which limits this, but a per-user lockout or backoff would harden it.
- **Email codes are stored in plain text** in the `brain2fa_backup_code_{user_id}` transient for their lifetime (default 10 minutes).
- **TOTP secrets are stored in plain text** in `brain2fa_secret` user meta. This is common practice for WordPress 2FA plugins, but encrypting at rest with a key from `wp-config.php` would protect against database-only leaks.

## Reporting

Security issues: security@braintum.com. General support: https://www.braintum.com/support/
