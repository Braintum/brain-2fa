# Security model

Brain 2FA runs inside the WordPress authentication path. This document lists what it protects, the decisions behind the current design, and the invariants changes must keep. The working checklist for agents is `.claude/skills/wp-security/SKILL.md`.

## What it protects against

- **Stolen or guessed passwords.** A correct password is not enough when the account has 2FA enabled. When a role requires 2FA and the grace period has expired, a password only reaches the setup page (restricted setup mode).
- **Bypassing the JavaScript flow.** Enforcement happens in the `authenticate` filter, so direct `wp-login.php` POSTs, XML-RPC and any other path that uses `wp_authenticate()` go through `verify_2fa()`.
- **Brute-forcing codes.** After 5 failed codes (or wrong passwords on the Login Security page), the account is locked out of verification for 15 minutes. Separately, 20 failures from one IP address within 15 minutes lock that IP out for every account. The lockout is checked before any code is evaluated, on both the preflight and the `authenticate` path.
- **Credential stuffing through the preflight endpoint.** `brain2fa_login` is rate limited to 5 attempts per minute per IP (hashed with `wp_hash()`).
- **Factor takeover from a hijacked session.** Turning 2FA off and regenerating recovery codes require the account password. A new factor cannot be activated over an active one. The owner is emailed after every change.
- **Secret leakage.** QR codes are rendered on the server. The `otpauth://` URI, which contains the secret, is never sent to a third party.
- **Database disclosure of long-lived tokens.** Recovery codes and trusted-device tokens are stored only as `wp_hash_password()` hashes. Pending email codes are stored only as a keyed HMAC.
- **Using one code twice at the same moment.** TOTP steps and recovery codes are consumed under a per-user lock, and email codes by `delete_transient()`, so concurrent requests carrying the same code cannot both pass.
- **TOTP code replay.** Each TOTP time step is accepted once per user, and never after a later step (`brain2fa_totp_last_step`), so an intercepted code cannot be reused within its validity window.
- **Stale trusted devices after account recovery.** Changing or resetting the password revokes all trusted devices.
- **Phishing and stolen passwords, for passkey users.** A passkey only signs challenges for this site's exact origin, and nothing reusable is sent to the server.

## Out of scope

- An attacker who already has server access or admin access.
- Mail interception for the email method. Email is the weaker method; TOTP is the default.
- Rate limiting of password guessing on `wp-login.php` (before the 2FA step). That is left to the host or a dedicated login-limiting plugin.

## Design decisions

| Decision | Reason |
| --- | --- |
| No terminating nonce check on `brain2fa_login` | Full-page caches serve `wp-login.php` with expired nonces, and a hard check made valid sign-ins fail with HTTP 403. The endpoint does not change state; the rate limit plus WordPress's own authentication cover abuse. |
| Lockout checks in both `handle_login()` and `verify_2fa()` | The first gives a clear message in the UI; the second is the actual enforcement. |
| Restricted setup mode instead of blocking expired users | Blocking left users no way to enroll (and a 0-day grace period locked everyone out immediately). Restricted users can sign in but reach nothing except the setup page; admin, front-end, REST and AJAX requests are redirected or refused. XML-RPC and REST logins stay blocked because they cannot show a setup page. |
| Required users who deactivate are restricted immediately | Prevents gaining a fresh grace period by deactivating, while still allowing a method switch. |
| Email 2FA enabled only after a code round-trip | Broken site mail would otherwise lock the user out at their next sign-in. |
| Server-rendered code field, removed by JavaScript | Users can sign in when the login script fails or is blocked, without showing a redundant field when it works. |
| Same error for unknown user and wrong password | Avoids user enumeration through the preflight endpoint. |
| Lockout keyed per user, with a looser per-IP limit | The per-user limit is what stops code guessing; it only counts after a correct password, so locking out the real user requires already knowing their password. The per-IP limit is generous (20) because sites behind a proxy may see one IP for all visitors. Tune both with `brain2fa_max_failed_attempts`, `brain2fa_max_failed_attempts_per_ip` (0 disables it) and `brain2fa_lockout_duration`. |
| Failure counter in user meta, IP counter in a transient | An attacker cannot evict user meta. The IP counter is advisory, so losing it to cache eviction is acceptable. |
| Password (not the current factor) confirms factor changes | The threat is a stolen session cookie, which does not carry the password. A password check works the same way for TOTP and email users, and lets a user who still has a session remove a lost authenticator. |
| Deactivation clears secret, recovery codes, last TOTP step and trusted devices | Re-enabling must start from fresh credentials. |
| Trusted devices revoked on password change, except an unchanged-password rehash | WordPress 6.8+ calls `wp_set_password()` to upgrade hashes at login; revoking devices then would be noise. `AccountEvents` compares the new password with the old hash (WordPress 6.7+ passes it) and also watches `profile_update`. |
| Trusted devices capped at 10 per user, with expiry | Limits the impact of stale cookies; tokens are hashed at rest. |
| Users can see and forget their trusted devices without their password | Forgetting only makes sign-in stricter, so a hijacked session gains nothing from it; the nonce stops cross-site requests. A device is looked up only in the current user's own list, so a forged ID matches nothing. Forgetting does not end sessions or remove passkeys, and the page says so. |
| Trusted-device cookie is `{id}.{token}` | The public ID selects one entry, so a sign-in needs one hash check instead of up to ten. The ID is random and is not derived from the token or its hash. |
| Device names from a fixed list of browsers and systems | Enough for a user to recognise a device, without storing the raw user agent, an IP address or a location. |
| Last use recorded only by `verify_2fa()` | The preflight also checks the cookie, but it does not sign anyone in. Every device write re-reads the list under `Auth\UserLock`, so a last-use update that waited for the lock cannot restore a device revoked meanwhile. |
| `hash_equals()` for email and TOTP codes | Constant-time comparison. |
| TOTP steps and recovery codes consumed under a per-user lock (`Auth\UserLock`) | The last-step check and the recovery-code removal are read-modify-write sequences, so without the lock two requests with the same code could both pass, and two different recovery codes used together could write each other back. The lock is a row inserted with `INSERT IGNORE` into the main options table (user meta is network-wide), the same approach as core's upgrader lock; it works on MySQL, MariaDB and SQLite. The slow work (TOTP HMACs, recovery-code hash checks) runs before the lock; inside it, the meta is re-read without the object cache and consumed. A busy lock fails verification closed. Deactivation and recovery-code regeneration take the lock too, but go ahead if it stays busy for 5 seconds, since that only happens when the holder died (an abandoned lock is taken over after 30 seconds). |
| Email codes stored as HMAC-SHA256 keyed with `wp_salt( 'auth' )` | A six-digit code has only a million values, so an unkeyed or password hash could be reversed by trying them all. A keyed hash cannot be reversed from a database-only leak when the salts are defined in `wp-config.php`. A plaintext code left by 1.1.0 is discarded on the next read, so the next request sends a new code instead of keeping a plaintext path alive. |
| Email code consumed by `delete_transient()` | Only the request whose delete succeeds may use the code, so concurrent submissions cannot both pass. |
| Passkeys require user verification | A passkey sign-in then proves possession plus a biometric or PIN, so it satisfies 2FA without a code. |
| Exact origin check before the library | lbuchs/webauthn accepts any origin whose host ends with the RP ID (for example `evilexample.com` for `example.com`). `WebAuthnServer::check_client_data()` compares origins exactly first. |
| Challenges and login tokens bound to an httponly cookie and consumed with `delete_transient()` | A challenge or token is useless in another browser, and concurrent requests cannot both use it. |
| Passkey sign-in ends with a normal form submission (token + `authenticate` filter at priority 15) | Core sign-in, redirects, remember-me, other security plugins and restricted mode behave exactly as for password sign-ins, with no separate session-creation code. |
| Adding or removing a passkey needs the password; admins can revoke; owners are emailed | A passkey is a permanent passwordless sign-in, so planting one is the most valuable thing a hijacked session could do. |
| A password reset does not delete passkeys | They are independent credentials. An administrator can revoke them after a compromise. |
| Setup reminders carry no secret and cannot be multiplied | The reminder contains only the deadline and the setup page link (`{login_security_url}` is required). Sending runs only from WP-Cron, never from a request a visitor controls, and is limited to one email per threshold and deadline and one a day per user and site. Atomic claims under `UserLock` stop overlapping scans from sending twice; failed sends are retried twice, then given up. Reminders are off by default. |
| Admin views never start a setup deadline | Listing users, the Overview and another user's profile read the stored deadline (`EnrollmentStatus`). Starting one there would shorten the grace period of users who have not signed in yet, and would make a view change state. Only the user's own session starts it; an administrator still extends a deadline explicitly from the profile (capability and nonce). The Overview needs `manage_options`, its role filter is checked against registered roles, its status and method filters against fixed lists, and its counts contain no per-user data beyond what the Users list shows. |
| Passkeys count as a second factor only while usable | If passkeys are turned off or the site loses HTTPS, they stop counting, so nobody is locked out by a setting change: passkey-only accounts can use their password again. Required users whose only factor was a passkey get no new grace period (`Utils::get_grace_period_expiry()` starts their deadline at "now" when they have stored passkeys), so they go straight to restricted setup mode. Users in other roles sign in with their password alone; the settings page warns with the number of users who rely only on passkeys before passkeys are turned off. |
| A code method turned off for the site fails closed | A user whose method is turned off cannot finish a password sign-in (recovery codes belong to the method, so they do not help either), unless they have a usable passkey or a trusted device. Falling back to password-only would let a settings change silently remove a user's second factor. Instead, the Authentication Methods tab shows how many users have no passkey to fall back on, and asks for confirmation before the method is turned off. The Login Security page flags the affected method as turned off by the site. |
| Email code sent only on request when a passkey is offered | The preflight for an email user who also has a passkey returns `email_on_request` instead of sending a code, and `login.js` re-runs the preflight with `brain2fa_send_email_code=1` when the user picks the code. The password is checked again on that request, and users who finish with their passkey get no email. A direct `wp-login.php` POST without a code still sends one from `verify_2fa()`. |
| Second-step challenges are bound to the user who entered the password | The options list only that user's passkeys, and an assertion from another account's passkey is rejected. |
| Passkey-only accounts: opt-in twice, password-confirmed, admin recovery only | The site must allow it and the user must turn it on. A password reset does **not** turn it off (that would let email access bypass it); an administrator restores password sign-in, and revoking all passkeys restores it too. The last passkey of such an account cannot be removed by the user. It is enforced before the 2FA switch, because it is about passwords, not second factors. |
| The per-user code lockout does not block passkey sign-in | A passkey cannot be guessed, and an attacker who knows the password should not be able to lock the owner out of their passkey. Failed assertions count toward the IP limit. |
| Signature counter regression blocks the sign-in and emails the owner | A counter going backwards suggests a copied authenticator. Synced passkeys report 0 and are not affected. |
| No AAGUID metadata download | Looking up authenticator names would contact a third party. Only the AAGUID is stored. |
| `vendor/lbuchs/webauthn/_test` is excluded from builds | It contains a web-reachable demo server. `deploy.sh` refuses a build that includes it. |
| Emails are plain text, and the subject is made single-line at send time | No HTML means nothing in a template, placeholder value or display name can run as markup in a mail client. Forcing the subject onto one line after placeholders and the `brain2fa_email_content` filter stops header injection through a user-controlled value such as a display name. |
| The sign-in code email cannot be turned off and must contain `{code}` | Turning it off or saving it without the code would silently stop email users from signing in. The editor rejects such a template. `EmailRegistry::get_template()` also reports the email as on whatever is stored, and falls back to the default body when a stored body lacks a required placeholder (for example after a direct database edit). |
| Security alerts can be turned off, per email | Some sites need that (for example during a bulk migration), but the alerts are what tells a user that a stolen session changed their sign-in settings. They are on by default, the list shows which ones are off, and the passkey clone warning says why it should stay on. |
| Placeholders expose only account and site details | No placeholder gives a secret, recovery code, token or password; `{code}` exists only in the sign-in code email, and a test email uses the sample code `123456`. Values are substituted with `strtr()`, so a display name containing `{code}` is not expanded. |
| Email settings need `manage_options` and their own nonce | The same rule as every other site setting. The sender address is validated as entered (`is_email()` and unchanged by `sanitize_email()`), so a typo is reported instead of silently falling back to the default. The sender filters apply at priority 999 to the plugin's own email only and are removed right after `wp_mail()`. |
| Deleting the plugin removes all of its data | TOTP secrets, recovery-code hashes, trusted-device hashes and passkey public keys should not stay in the database once the plugin is gone. `uninstall.php` only runs on deletion, never on deactivation, so turning the plugin off temporarily loses nothing. Sites that delete and reinstall on purpose can define `BRAIN2FA_KEEP_DATA_ON_UNINSTALL`. |
| Only customized fields are stored | Untouched defaults keep following translations and future wording fixes. |
| TOTP steps checked explicitly instead of otphp's `verify()` | otphp 11's `verify()` takes a leeway in seconds (not periods) and keeps no record of used codes. `TotpMethod::verify_totp_code()` checks the current step ±1 and stores the last accepted step in user meta (not a transient, which an object cache could evict). |

## Invariants

1. Every policy rule is enforced in `verify_2fa()`, and it fails closed (returns `WP_Error`) on missing or disabled methods.
2. The preflight endpoint never authenticates a session.
3. The lockout is checked before any code is evaluated or any email code is sent. Every failed code or password confirmation calls `Utils::record_failed_verification()`.
4. Passkey ceremonies require user verification, an exact origin match and a single-use, cookie-bound challenge; passkey login tokens are single-use, cookie-bound and only accepted on interactive form posts.
5. No secret, code or recovery code is logged, sent to a remote service, or rendered anywhere except the owning user's Login Security page at setup time.
6. Long-lived tokens are random (`random_int`, `wp_generate_password`) and hashed at rest.
7. Every admin state change checks both a capability and a nonce. The Login Security page only acts on `wp_get_current_user()`. Disabling a factor, regenerating recovery codes, and adding or removing a passkey also require the account password.
8. All output is escaped at the point of output, and all input is unslashed and sanitized.

## Known gaps and hardening backlog

These were observed in the code on 2026-10-16 and updated on 2026-10-24 (1.2.0 resolved concurrent code consumption and plaintext email codes). Re-check them before relying on this list.

**Security**

- **TOTP secrets are stored in plain text** in `brain2fa_secret` user meta. This is common practice for WordPress 2FA plugins, but encrypting at rest with a key from `wp-config.php` would protect against database-only leaks.

**Policy and reliability**

- **Restricted mode is enforced by redirects** on `admin_init` and `template_redirect`, plus REST and AJAX refusals. Code from other plugins that acts earlier (for example form handlers on `init`) still runs for restricted users.
- **Application passwords bypass 2FA.** WordPress core validates application passwords for REST requests without the `authenticate` filter, so Brain 2FA does not see them. This is core's intended design for API access; sites that do not need application passwords can disable them with the `wp_is_application_passwords_available` filter.
- **Custom login forms** other than `wp-login.php`, the WooCommerce form and `wp_login_form()` get no code field, and users with 2FA cannot sign in through them (this fails closed).

## Reporting

Security issues: security@braintum.com. General support: https://www.braintum.com/support/
