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
8. **Deactivating 2FA clears everything:** secret, enabled flag, recovery codes and trusted devices (`Utils::forget_remembered_devices()`). Any new per-user credential must be cleared here too.
9. **Trusted-device cookies** are `httponly`, `secure` on SSL, `SameSite=Lax`, bound to a user ID, expiring, and capped at 10 per user.

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

- When otphp is available (it always is in release builds), `TotpMethod::verify()` calls `TOTP::verify( $token, null, 1 )`. otphp does **not** track used codes, so a TOTP code can be replayed within its ±1 window. Only `fallback_verify()` stores the last used slice. The code comment there says otphp has built-in replay protection; that is inaccurate.
- Email codes are stored in a plain transient (`brain2fa_backup_code_{user_id}`), and `verify_2fa()` does not limit failed code attempts per user. The password must already be correct, which limits exposure.
- TOTP secrets are stored in plain user meta (`brain2fa_secret`), as in most WordPress 2FA plugins.

If you change any of these, update this section and `docs/security.md`.
