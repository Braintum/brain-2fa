---
name: wp-security
description: Security rules for Brain 2FA, a WordPress two-factor authentication plugin. Use before changing anything that touches login, authentication, 2FA verification, secrets, recovery codes, trusted devices, AJAX or form handlers, user meta, settings, or output escaping, and when reviewing such changes.
---

# Security for Brain 2FA

This plugin sits in the WordPress login path. A bug here either locks users out or lets attackers skip the second factor. Read `docs/security.md` for the full threat model; this skill is the working checklist.

## Plugin-specific invariants (never break these)

1. **Two login paths, one policy.** `LoginController::handle_login()` (AJAX preflight, `wp_ajax_nopriv_brain2fa_login`) only improves UX. `LoginController::verify_2fa()` (the `authenticate` filter, priority 40) is the real enforcement point, because anyone can POST to `wp-login.php` directly. Any policy change (grace period, method availability, trusted devices, new methods) must land in **both**, and `verify_2fa()` must fail closed.
2. **The preflight never signs anyone in.** It defines `BRAIN_2FA_AUTHENTICATION_CHECK` so `verify_2fa()` does not recurse, then returns JSON. Do not call `wp_set_auth_cookie()` or `wp_signon()` from it.
3. **No terminating nonce check on the preflight.** Cached `wp-login.php` pages carry stale nonces. Brute force is limited by the per-IP transient rate limit (5 per minute) instead. Keep that limit before any credential work.
4. **Generic credential errors.** Unknown user and wrong password both return the same "username or password you entered is incorrect" message. Never add messages that reveal whether an account exists or whether it has 2FA before the password is verified.
5. **Secrets never leave the server.** The `otpauth://` URI contains the TOTP secret, so QR codes are rendered locally with bacon/bacon-qr-code. Never add a remote QR or API fallback, analytics or logging that includes secrets, codes or recovery codes.
6. **Store only hashes of long-lived tokens.** Recovery codes and trusted-device tokens go through `wp_hash_password()` and are checked with `wp_check_password()`. A recovery code is single-use: remove it after a match. Compare short-lived codes with `hash_equals()`.
7. **Use cryptographic randomness:** `random_int()`, `wp_generate_password( 64, false, false )`, or otphp. Never `rand()`, `mt_rand()`, `uniqid()` or `md5( time() )`.
8. **Deactivating 2FA clears everything:** secret, enabled flag, recovery codes, the last TOTP step and trusted devices (`Utils::forget_remembered_devices()`). Any new per-user credential must be cleared here too.
9. **Trusted-device cookies** are `httponly`, `secure` on SSL, `SameSite=Lax`, bound to a user ID, expiring, and capped at 10 per user.
10. **TOTP codes are single-use.** `TotpMethod::verify_totp_code()` accepts a code only for a time step later than `brain2fa_totp_last_step`. Do not replace it with otphp's `TOTP::verify()`: in otphp 11 its leeway is in seconds and it does not track used codes.
11. **Verification is rate limited.** `Utils::get_verification_lockout()` is checked before any code is evaluated or emailed, in both `handle_login()` and `verify_2fa()`. Every failed code or password confirmation calls `Utils::record_failed_verification()`, and only a successful login code calls `clear_failed_verifications()`. New verification paths must do the same.
12. **Factor changes need the password.** Deactivating and regenerating recovery codes go through `Utils::confirm_password()` (as do adding and removing a passkey and passkey-only changes); activation is refused while a factor is active; each change calls `Utils::send_factor_change_notification()`.
13. **Password changes revoke trusted devices** (`Auth\AccountEvents`).
14. **Expired users are restricted, not blocked.** `Utils::is_enrollment_required()` users may sign in interactively, but `Auth\EnrollmentGuard` must keep them on the setup page: any new entry point (admin page, front-end route, REST route, AJAX action) must stay covered by its redirects and refusals. Keep XML-RPC and REST logins blocked for them in `verify_2fa()`.
15. **Passkeys (WebAuthn).** Always require user verification. Compare `clientDataJSON.origin` exactly in `WebAuthnServer::check_client_data()` before trusting the library (its own origin check is a loose suffix match). Challenges and login tokens stay single-use (`delete_transient()` must return true) and bound to the `brain2fa_passkey_{COOKIEHASH}` cookie. Adding or removing a passkey requires `Utils::confirm_password()` and sends a notification. Never ship `vendor/lbuchs/webauthn/_test`. Passkeys count as a factor only through `Utils::user_has_passkey_factor()` (false while passkeys are unusable); second-step challenges carry the expected user ID; `Utils::is_passkey_only()` is checked before the 2FA switch in both login paths, and a passkey-only account's last passkey cannot be removed.

## General WordPress rules

- **Authorization and intent on every state change.** Check capabilities with `current_user_can()` (`manage_options` for site settings and other users; `edit_user` for the target user) and verify a nonce with `check_admin_referer()`, `wp_verify_nonce()` or `check_ajax_referer()`. A nonce proves intent, not permission. You always need both.
- **Users act only on themselves** on the Login Security page. Never take a user ID from the request there; use `wp_get_current_user()`.
- **Sanitize on input and escape on output** (see the wp-coding-standards skill). Validate settings against allow-lists, as `Settings::sanitize_settings()` does for roles and methods.
- **SQL:** use WordPress APIs or `$wpdb->prepare()`. Never concatenate request data into SQL.
- **Redirects:** `wp_safe_redirect()` followed by `exit`.
- **Files:** no `include` or `require` built from request data. No `eval`, `create_function` or `unserialize()` on user input.
- **Timing-safe comparison** with `hash_equals()` for any secret or code.
- **Errors:** do not expose stack traces, paths or exception messages to users. Use the `brain2fa_qr_code_generation_failed` action pattern for diagnostics.

## WordPress.org guideline constraints

No phoning home, tracking or remote assets without explicit opt-in. No obfuscated code. Everything must be GPL-compatible. Do not load executable code from third-party servers.

## Review checklist

Before you finish a change in this area, answer each one:

- [ ] Does a direct `wp-login.php` POST still enforce the change? (Check `verify_2fa()`.)
- [ ] Does it fail closed when the method, secret or setting is missing or invalid?
- [ ] Are new request inputs unslashed, sanitized, validated, and covered by a nonce and a capability check?
- [ ] Is all new output escaped at the point of output?
- [ ] Are new secrets or tokens hashed at rest, random enough and removed on deactivation?
- [ ] Could the change reveal whether an account exists or has 2FA?
- [ ] Did `composer phpcs` stay clean without new `phpcs:ignore` on security sniffs?

## Known hardening gaps (verify before relying on them)

See the backlog in `docs/security.md`. In short: TOTP-step and recovery-code consumption are not atomic; email codes and TOTP secrets are stored in plain text; restricted mode is enforced by redirects (code running on `init` is not covered); application passwords bypass 2FA by core design.

If you change any of these, update this section and `docs/security.md`.
