# Architecture

Brain 2FA is a WordPress plugin (PHP 8.1+, WordPress 6.4+) that adds a second authentication factor to the WordPress and WooCommerce login forms.

## Bootstrap

1. `brain-2fa.php` defines `BRAIN_2FA_VERSION`, `BRAIN_2FA_PLUGIN_DIR` and `BRAIN_2FA_PLUGIN_URL`, loads Composer's autoloader and instantiates the `Brain_2FA\App` singleton through `brain_2fa()`.
2. `App` hooks `on_plugins_loaded()` to `plugins_loaded` at `PHP_INT_MAX`. That method:
   - registers `TotpMethod` and `EmailMethod` with `TwoFactor\Manager`;
   - calls `LoginController::init()` and `LoginAssets::init()` on every request;
   - in wp-admin only, instantiates `Settings`, `UserProfile`, `Assets` and `UserListColumns`;
   - fires the `brain_2fa_loaded` action.

## Components

| Class | Responsibility |
| --- | --- |
| `Auth\LoginController` | AJAX credential preflight (`wp_ajax_nopriv_brain2fa_login`) and the `authenticate` filter (`verify_2fa`, priority 40) that enforces 2FA. |
| `Install` | Creates the `brain2fa_passkeys` table with `dbDelta()` on activation and whenever `brain2fa_db_version` differs from `Install::DB_VERSION`. |
| `Passkeys\WebAuthnServer` | Wraps `lbuchs/webauthn`: RP ID and allowed origins, an exact origin check, WebAuthn option JSON, registration and assertion verification. |
| `Passkeys\PasskeyRepository` | CRUD for the `brain2fa_passkeys` table (lookup by credential-ID hash) and each user's random WebAuthn user handle. |
| `Passkeys\PasskeyRegistration` | Logged-in AJAX to add passkeys (password-confirmed), plus rename, remove and admin revoke. |
| `Passkeys\PasskeyLogin` | Passwordless sign-in: nopriv challenge and verify endpoints, single-use login tokens, and the `authenticate` filter at priority 15. |
| `Auth\EnrollmentGuard` | Restricted setup mode for users who must enroll (login redirects, admin/front-end/REST/AJAX restriction), WooCommerce access to the setup page, and the My Account 2FA status line. |
| `Auth\AccountEvents` | Revokes trusted devices when a password changes (`wp_set_password`, `profile_update`). |
| `Auth\LoginAssets` | Enqueues `assets/js/login.js` on `wp-login.php` and on the WooCommerce My Account login form, with localized `Brain2FA` data. |
| `TwoFactor\Manager` | Registry of methods keyed by ID (`totp`, `email`). |
| `TwoFactor\Interfaces\TwoFactorMethodInterface` | Contract for a method: setup data, save/deactivate, send challenge, verify, recovery codes. |
| `TwoFactor\Methods\TotpMethod` | TOTP secret generation (otphp, with an internal HOTP fallback), local QR rendering (bacon-qr-code + GD, logo in the centre), verification of the current time step ±1 with per-user replay protection, and hashed single-use recovery codes. |
| `TwoFactor\Methods\EmailMethod` | Six-digit email codes stored in a transient for the configured expiry. Sending is filterable. |
| `Utils` | Shared policy: site-wide and per-method enablement, default method, role enforcement, grace-period deadlines, trusted devices, verification lockouts and change notifications. |
| `Admin\Settings` | The Brain 2FA menu, the site settings page (Settings API, `brain2fa_settings`), and the per-user Login Security page (setup, and password-confirmed deactivate and recovery-code regeneration). |
| `Admin\UserProfile` | 2FA status on profile screens, the admin-only grace-period extension, and the Dashboard deadline notice. |
| `Admin\UserListColumns` | Sortable 2FA status column in Users, showing setup deadlines and expiry. |
| `Admin\Assets` | Admin CSS/JS for the plugin screens. |

## Login flow

```
Browser (login.js)                    WordPress / Brain 2FA
------------------                    ---------------------
submit username + password  ──AJAX──▶ LoginController::handle_login()
                                        - per-IP rate limit (5/min)
                                        - wp_authenticate() with BRAIN_2FA_AUTHENTICATION_CHECK defined
                                        - site 2FA off, user not enrolled, or trusted device
                                            → {login: 1}  (expired grace → restricted mode after login)
                                        - verification locked out → error + reset
                                        - method unavailable → error + reset
                                        - email method → send_challenge()
                                        - otherwise → {login: 1, requires_2fa: true, method}
show "Verification Code" field
submit the native login form  ──POST─▶ wp-login.php → authenticate filter
  with brain2fa_code                    LoginController::verify_2fa()  (the real gate)
  [+ brain2fa_remember_device]          - repeats the policy checks, including the lockout
                                        - verifies the code with the user's method
                                        - failure → Utils::record_failed_verification()
                                        - optionally Utils::remember_device()
                                        - returns WP_User or WP_Error
```

The AJAX step never sets auth cookies. Without JavaScript, or with a direct POST, `verify_2fa()` still enforces the same policy. With the email method, it also sends the code and returns a "code sent" error so the user can retry with the code.

**No-JavaScript fallback.** `LoginController` renders a `brain2fa_code` field (`.brain2fa-fallback-field`) on `wp-login.php` (`login_form`), the WooCommerce login form (`woocommerce_login_form`) and `wp_login_form()` (`login_form_middle`). `login.js` removes it on load, so it only remains when the script is unavailable; users then submit username, password and code together.

## Restricted setup mode

A user must enroll when their role requires 2FA, they have not enrolled, and their grace deadline has passed (`Utils::is_enrollment_required()`; never true when no method is enabled). They can still sign in interactively, and `EnrollmentGuard` then:

- redirects them to the Login Security page after login (`login_redirect`, `woocommerce_login_redirect`);
- redirects every other admin page (`admin_init`) and front-end page (`template_redirect`) there, including for sessions that began before the deadline;
- refuses admin-ajax (except Heartbeat) and cookie-authenticated REST requests with HTTP 403;
- lets WooCommerce customers open the setup page (`woocommerce_prevent_admin_access`).

XML-RPC and REST logins have no setup page, so `verify_2fa()` keeps rejecting them (`brain2fa_setup_required`) until the user enrolls. Enrolling deletes the grace deadline. A required user who deactivates 2FA gets a deadline of "now" and is restricted again, which is how method switching works for required roles.

## Email setup

Email authentication is enabled only after the user enters a code sent to their address: the first activation request sends a code (`save_setup()` returns `code_sent`), the second verifies it. "Send a new code" discards the pending code and sends another.

## Passkeys (passwordless sign-in)

Off by default (`enable_passkeys`), and only active over HTTPS or on localhost (`WebAuthnServer::is_available()`), because browsers only allow WebAuthn in a secure context. A passkey sign-in requires user verification (biometric or PIN), so it satisfies 2FA without a code. In this phase, passkeys do not count toward required-role enrollment, and password sign-ins are unchanged.

**Registering** (Login Security page, `passkey-manage.js`): the user enters a name and their password, and `brain2fa_passkey_register_options` confirms the password (`Utils::confirm_password()`) and returns creation options (`residentKey` and `userVerification` required, `attestation: none`, existing passkeys excluded). The challenge is stored in `brain2fa_webauthn_reg_{user_id}`. `brain2fa_passkey_register_verify` consumes the challenge, verifies the response and stores the passkey.

**Signing in** (`passkey-login.js` on wp-login.php and the WooCommerce form):

```
page load / button  ──AJAX──▶ brain2fa_passkey_auth_options
                              - per-IP limit (30/min)
                              - random session value → httponly cookie brain2fa_passkey_{COOKIEHASH}
                              - challenge stored under hash(session)
browser signs (autofill or modal)
                    ──AJAX──▶ brain2fa_passkey_auth_verify
                              - IP lockout check; consume challenge for this cookie
                              - credential ID → passkey row; userHandle must match the owner
                              - exact origin, RP ID hash, UV flag, signature, sign counter
                              - single-use login token (2 min) bound to hash(session)
fill username, add brain2fa_passkey_token, submit the form natively
                    ──POST──▶ wp_signon() → authenticate filter
                              PasskeyLogin::authenticate_with_token() (priority 15)
                              - consume token, check cookie binding → WP_User
                              verify_2fa() (priority 40) skips the code for this user
```

Because the final step is a normal form submission, `wp_signon()`, remember-me, `login_redirect`, WooCommerce redirects and `EnrollmentGuard` all apply as for a password sign-in. Tokens are ignored for XML-RPC and REST requests.

## Data model

**Option** `brain2fa_settings` (sanitized by `Settings::sanitize_settings()`):

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `enable_2fa` | bool | false | Site-wide master switch. |
| `force_2fa_roles` | string[] | `[]` | Validated against registered roles. |
| `grace_period_days` | int 0–365 | 14 | `0` puts unenrolled required users in restricted setup mode at their first sign-in. |
| `default_method` | `totp` \| `email` | `totp` | Falls back to an enabled method. The legacy value `email_backup` maps to `email`. |
| `enable_totp`, `enable_email` | bool | true when unset | Method availability. |
| `enable_passkeys` | bool | false | Passkey registration and passwordless sign-in (needs HTTPS). |
| `code_expiry` | int 1–60 minutes | 10 | Email code lifetime. |
| `remember_device` | bool | false | Trusted devices on or off. |
| `remember_duration` | int 1–365 days | 30 | Trusted device lifetime. |

**User meta:** `brain2fa_enabled`, `brain2fa_method`, `brain2fa_secret` (TOTP, base32), `brain2fa_recovery_codes` (array of `wp_hash_password` hashes), `brain2fa_grace_expires_at` (Unix time, created lazily), `brain2fa_totp_last_step` (last accepted TOTP time step, for replay protection), `brain2fa_remembered_devices` (list of `{token: hash, expires_at}`, max 10), `brain2fa_failed_attempts` (`{count, last_failure, locked_until}`).

**Transients:** `brain2fa_rate_{wp_hash(ip)}` (preflight attempt counter, 60 s), `brain2fa_backup_code_{user_id}` (pending email code), `brain2fa_ip_failures_{wp_hash(ip)}` (failed verifications per IP, `{count, expires_at}`).

**Table** `{prefix}brain2fa_passkeys`: `user_id`, `credential_hash` (SHA-256 of the credential ID, unique), `credential_id` (base64url), `public_key` (PEM), `sign_count`, `transports`, `aaguid`, `backup_eligible`, `backed_up`, `name`, `created_at`, `last_used_at` (GMT). Rows are deleted with the user (`deleted_user`). Option `brain2fa_db_version` tracks the schema.

**Passkey user meta and transients:** `brain2fa_webauthn_user_handle` (base64url of 32 random bytes), `brain2fa_webauthn_reg_{user_id}` and `brain2fa_webauthn_auth_{hash}` (challenges, 5 min), `brain2fa_passkey_token_{hash}` (login tokens, 2 min), `brain2fa_passkey_opts_{ip hash}` (options rate limit).

**Cookies:** `brain2fa_remember_{user_id}`: random 64-character token, `httponly`, `secure` on SSL, `SameSite=Lax`. `brain2fa_passkey_{COOKIEHASH}`: random passkey session value binding challenges and tokens to the browser, `httponly`, `SameSite=Lax`, cleared after sign-in.

## Extension points

| Hook | Type | Purpose |
| --- | --- | --- |
| `brain_2fa_loaded` | action | Fired after methods and controllers are registered. |
| `brain2fa_send_backup_code` | filter | Return `true` to send the email code yourself. Args: `false`, `WP_User`, `string $code`. |
| `brain2fa_recovery_codes_count` | filter | Number of recovery codes to generate (default 10). |
| `brain2fa_qr_code_generation_failed` | action | Receives the `Throwable` when QR rendering fails. |
| `brain2fa_max_failed_attempts` | filter | Failed codes or passwords before a user is locked out (default 5). |
| `brain2fa_max_failed_attempts_per_ip` | filter | Failed verifications per IP before that IP is locked out (default 20, `0` disables). |
| `brain2fa_lockout_duration` | filter | Lockout length and per-IP window in seconds (default 900). |
| `brain2fa_passkey_allowed_origins` | filter | Exact origins accepted for passkey ceremonies (default: the origins of `home_url()` and `site_url()`). |
| `brain2fa_passkey_options_per_minute` | filter | Passkey challenge requests allowed per IP per minute (default 30). |
| `brain2fa_send_change_notification` | filter | Return `false` to skip the email sent after 2FA is turned on or off or recovery codes are regenerated. Args: `true`, `WP_User`, `string $event`. |
| `login_errors` | filter (core) | Applied to preflight credential errors, as on `wp-login.php`. |

## Front-end build

- `src/js/` → webpack → `assets/js/{login,admin,passkey-login,passkey-manage}.js` (dependencies in `assets/assets.php`), using `@wordpress/dependency-extraction-webpack-plugin`. `@simplewebauthn/browser` is bundled into the passkey scripts.
- `src/scss/` → `assets/css/admin.css`.
- `assets/js/` and `assets/css/` are gitignored and produced by `npm run build`. Release builds include them.

## Release build

`npm run dist` runs: webpack production build, then POT generation (`wp i18n make-pot`), then `composer install --no-dev`, then `scripts/build-dist.php` (skips `vendor/lbuchs/webauthn/_test`, a demo server; copies `assets`, `includes`, `languages`, `vendor`, `brain-2fa.php`, `composer.json`, `composer.lock` and `readme.txt` into `dist/brain-2fa/` and zips it), then restores dev dependencies. `scripts/deploy.sh` publishes `dist/brain-2fa/` to SVN. See `docs/releasing.md`.
