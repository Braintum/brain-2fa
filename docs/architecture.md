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
| `Auth\LoginAssets` | Enqueues `assets/js/login.js` on `wp-login.php` and on the WooCommerce My Account login form, with localized `Brain2FA` data. |
| `TwoFactor\Manager` | Registry of methods keyed by ID (`totp`, `email`). |
| `TwoFactor\Interfaces\TwoFactorMethodInterface` | Contract for a method: setup data, save/deactivate, send challenge, verify, recovery codes. |
| `TwoFactor\Methods\TotpMethod` | TOTP secret generation (otphp, with an internal HOTP fallback), local QR rendering (bacon-qr-code + GD, logo in the centre), verification with ±1 period tolerance, and hashed single-use recovery codes. |
| `TwoFactor\Methods\EmailMethod` | Six-digit email codes stored in a transient for the configured expiry. Sending is filterable. |
| `Utils` | Shared policy: site-wide and per-method enablement, default method, role enforcement, grace-period deadlines, trusted devices. |
| `Admin\Settings` | The Brain 2FA menu, the site settings page (Settings API, `brain2fa_settings`), and the per-user Login Security page (setup, deactivate, regenerate recovery codes). |
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
                                        - site 2FA off, user not enrolled (grace OK), or trusted device
                                            → {login: 1}
                                        - grace expired / method unavailable → error + reset
                                        - email method → send_challenge()
                                        - otherwise → {login: 1, requires_2fa: true, method}
show "Verification Code" field
submit the native login form  ──POST─▶ wp-login.php → authenticate filter
  with brain2fa_code                    LoginController::verify_2fa()  (the real gate)
  [+ brain2fa_remember_device]          - repeats the policy checks
                                        - verifies the code with the user's method
                                        - optionally Utils::remember_device()
                                        - returns WP_User or WP_Error
```

The AJAX step never sets auth cookies. Without JavaScript, or with a direct POST, `verify_2fa()` still enforces the same policy. With the email method, it also sends the code and returns a "code sent" error so the user can retry with the code.

## Data model

**Option** `brain2fa_settings` (sanitized by `Settings::sanitize_settings()`):

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `enable_2fa` | bool | false | Site-wide master switch. |
| `force_2fa_roles` | string[] | `[]` | Validated against registered roles. |
| `grace_period_days` | int 0–365 | 14 | `0` makes the setup deadline immediate. |
| `default_method` | `totp` \| `email` | `totp` | Falls back to an enabled method. The legacy value `email_backup` maps to `email`. |
| `enable_totp`, `enable_email` | bool | true when unset | Method availability. |
| `code_expiry` | int minutes | 10 | Email code lifetime. |
| `remember_device` | bool | false | Trusted devices on or off. |
| `remember_duration` | int 1–365 days | 30 | Trusted device lifetime. |

**User meta:** `brain2fa_enabled`, `brain2fa_method`, `brain2fa_secret` (TOTP, base32), `brain2fa_recovery_codes` (array of `wp_hash_password` hashes), `brain2fa_grace_expires_at` (Unix time, created lazily), `brain2fa_remembered_devices` (list of `{token: hash, expires_at}`, max 10).

**Transients:** `brain2fa_rate_{wp_hash(ip)}` (preflight attempt counter, 60 s), `brain2fa_backup_code_{user_id}` (pending email code), `brain2fa_used_slice_{user_id}` (replay guard used only by the HOTP fallback).

**Cookie:** `brain2fa_remember_{user_id}`: random 64-character token, `httponly`, `secure` on SSL, `SameSite=Lax`.

## Extension points

| Hook | Type | Purpose |
| --- | --- | --- |
| `brain_2fa_loaded` | action | Fired after methods and controllers are registered. |
| `brain2fa_send_backup_code` | filter | Return `true` to send the email code yourself. Args: `false`, `WP_User`, `string $code`. |
| `brain2fa_recovery_codes_count` | filter | Number of recovery codes to generate (default 10). |
| `brain2fa_qr_code_generation_failed` | action | Receives the `Throwable` when QR rendering fails. |
| `login_errors` | filter (core) | Applied to preflight credential errors, as on `wp-login.php`. |

## Front-end build

- `src/js/` → webpack → `assets/js/{login,admin}.js` (+ `.asset.php`), using `@wordpress/dependency-extraction-webpack-plugin`.
- `src/scss/` → `assets/css/admin.css`.
- `assets/js/` and `assets/css/` are gitignored and produced by `npm run build`. Release builds include them.

## Release build

`npm run dist` runs: webpack production build, then POT generation (`wp i18n make-pot`), then `composer install --no-dev`, then `scripts/build-dist.php` (copies `assets`, `includes`, `languages`, `vendor`, `brain-2fa.php`, `composer.json`, `composer.lock` and `readme.txt` into `dist/brain-2fa/` and zips it), then restores dev dependencies. `scripts/deploy.sh` publishes `dist/brain-2fa/` to SVN. See `docs/releasing.md`.
