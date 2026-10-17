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
| `TwoFactor\Methods\TotpMethod` | TOTP secret generation (otphp with `TwoFactor\SystemClock`, a PSR-20 clock; internal HOTP fallback), local QR rendering (bacon-qr-code + GD, logo in the centre), verification of the current time step ±1 with per-user replay protection, and hashed single-use recovery codes. |
| `TwoFactor\Methods\EmailMethod` | Six-digit email codes stored in a transient for the configured expiry. Sends them through `Emails\Mailer` (`sign_in_code`); sending is filterable. |
| `Emails\EmailRegistry` | The catalogue of plugin emails (ID, group, label, placeholders, required placeholders, whether it can be turned off, default subject and body) and the stored customizations in `brain2fa_emails`: read, validate, save and reset templates and the sender. |
| `Emails\Mailer` | Renders an email for a user (placeholder values, `strtr`) and sends it as plain text with `wp_mail()`, with the configured sender applied only to that one email. |
| `Utils` | Shared policy: site-wide and per-method enablement, default method, role enforcement, grace-period deadlines, trusted devices, verification lockouts and change notifications (`send_factor_change_notification()` sends an `alerts` email through `Emails\Mailer`). |
| `Admin\Settings` | The Brain 2FA menu and the site settings page. It registers the Login Security page and hands it to `Admin\LoginSecurityPage`. The settings page saves `brain2fa_settings` through the Settings API. It has four server-rendered tabs (`?tab=methods`, `requirements`, `trusted-devices` and `emails`; unknown values fall back to `methods`). The first three are separate forms rendered from `views/settings/tab-*.php` and saved on its own. Dependent fields hide with CSS `:has()` while their parent box is off, and stay in the form so their values are kept. The Authentication Methods tab counts the users of each code method who have no usable passkey (`PasskeyRepository::count_code_method_users()`). A method turned off for the site fails closed at sign-in, so these users would be unable to sign in with their password. The count appears as a warning while the method is on, and as an error if it is already off. `src/js/admin.js` adds the unsaved-changes warning, a confirmation before turning off a method that has such users, and a confirmation when more roles are made to require 2FA. |
| `Admin\EmailSettings` | The Emails tab. `handle_post()` runs on `load-toplevel_page_brain-2fa-settings` (before output) and handles saving a template, resetting it, sending a test to the current user and saving the sender, each behind `manage_options` and the `brain2fa_email_settings` nonce. Success redirects back with a notice; a validation error re-renders the editor with the submitted values and the error next to its field. `render_tab()` shows the email list (`views/settings/tab-emails.php`) or, with `?email=<id>`, the editor (`views/settings/email-edit.php`), where `src/js/admin.js` inserts placeholders at the cursor and updates a preview with sample values. |
| `Admin\LoginSecurityPage` | The per-user Login Security page. `handle_post()` runs the submitted action for the current user (code setup, password-confirmed deactivation and recovery-code regeneration, passkey rename and removal, passkey-only changes) and records each result for the page section it belongs to. The page then reads its state fresh and renders a protection summary and the Passkeys, Two-factor codes, Recovery codes and Password sign-in cards from `views/login-security/*.php`. A form that failed reopens its `<details>` disclosure with the error inside it. Newly generated recovery codes exist only in the POST response. After a wrong setup code, the authenticator secret that was already scanned is shown again (`TotpMethod::get_setup_data()` accepts it only if it is base32). |
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
                                        - passkey-only account → error + reset
                                        - verification locked out → error + reset
                                        - {requires_2fa, method (code, may be empty), passkey (options, if any)}
                                        - email method + passkey → {email_on_request: true}, no email sent
                                          (sent when the preflight repeats with brain2fa_send_email_code=1)
                                        - method unavailable / email failed → error, unless a passkey is offered
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

## Emails

The plugin sends ten emails, all plain text and all editable under **Brain 2FA > Settings > Emails**:

| ID | Group | When | Can be turned off |
| --- | --- | --- | --- |
| `sign_in_code` | Sign-in | An email code is requested at sign-in or during email setup. Must contain `{code}`. | No |
| `activated`, `deactivated` | Alerts | Two-factor authentication turned on or off. | Yes |
| `recovery_codes_regenerated` | Alerts | New recovery codes generated. | Yes |
| `passkey_added`, `passkey_removed` | Alerts | A passkey added or removed by the user. | Yes |
| `passkeys_revoked` | Alerts | An administrator removed all of the user's passkeys. | Yes |
| `passkey_only_enabled`, `passkey_only_disabled` | Alerts | Password sign-in turned off or back on. | Yes |
| `passkey_clone_warning` | Alerts | A passkey sign-in was blocked because the passkey appears to have been copied. | Yes (the editor warns against it) |

Every email gets the common placeholders `{user_display_name}`, `{user_first_name}` (falls back to the display name), `{user_login}`, `{user_email}`, `{site_name}`, `{site_url}`, `{login_url}`, `{login_security_url}` and `{date_time}` (site date and time formats). `sign_in_code` adds `{code}` and `{expiry_minutes}`; the passkey alerts that concern one passkey add `{passkey_name}`. Callers pass these extra values as `$context` to `Mailer::send( $id, $user, $context )`. Placeholders are replaced with `strtr()`, so a value is never scanned again for placeholders, and an unknown `{name}` is left as typed.

Only what differs from the default is stored, so an untouched subject or body keeps following the translation of the default. A template's subject is sanitized with `sanitize_text_field()` (single line, at most 255 characters) and its body with `sanitize_textarea_field()` (at most 10,000 characters), after normalizing line endings. A template that is missing a required placeholder is rejected. At send time the subject is forced onto one line again, after placeholders and the `brain2fa_email_content` filter, so a placeholder value or a filter cannot inject mail headers. The sender name and address apply through `wp_mail_from_name` / `wp_mail_from` at priority 999 and are removed after the email is sent; when empty, the site's mail settings apply.

## Passkeys (passwordless sign-in)

Off by default (`enable_passkeys`), and only active over HTTPS or on localhost (`WebAuthnServer::is_available()`), because browsers only allow WebAuthn in a secure context. A passkey sign-in requires user verification (biometric or PIN), so it satisfies 2FA without a code.

Passkeys are a second factor alongside the code methods, not a `TwoFactorMethodInterface` method: `Utils::is_2fa_enabled_for_user()` is true for a code method (`brain2fa_enabled`) **or** a usable passkey (`Utils::user_has_passkey_factor()`, which is false while passkeys are off or the site is not on HTTPS). So:

- **Enrollment:** adding a passkey enrolls a required-role user and clears their grace deadline; restricted users may call the passkey registration AJAX actions (`EnrollmentGuard::is_allowed_ajax_action()`); removing the last factor restricts them again.
- **Second step after a password:** `LoginController::get_preflight_response()` returns `passkey` options limited to the user's own passkeys (`PasskeyLogin::start_second_step()`), alongside `method` when the user also has a code method. The challenge records the user ID, and `verify_authentication()` rejects another account's passkey. A user whose only factor is passkeys gets `brain2fa_passkey_required` from `verify_2fa()` on a password-only submission. Each verification attempt consumes the challenge, so `LoginForm.js` re-runs the preflight (with the still-filled, hidden credentials) to get fresh options after a failed attempt or when the options are close to expiry. Email users who also have a passkey get their code only on request ("Email me a code instead").
- **Passkey-only accounts:** when the site allows it (`allow_passkey_only`), a user with a passkey can turn off password sign-in (`brain2fa_passkey_only` user meta, password-confirmed). `Utils::is_passkey_only()` is checked before the 2FA switch in both the preflight and `verify_2fa()`. It never applies while passkeys are unusable. The last passkey of a passkey-only account cannot be removed; an administrator can restore password sign-in from the profile, and revoking all passkeys restores it too.

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

**Option** `brain2fa_settings` (sanitized by `Settings::sanitize_settings()`). Read it through `Utils::get_settings()`, which fills missing keys from `Utils::SETTINGS_DEFAULTS`, the only place defaults are defined. Nothing is stored on install, so a site that has never saved settings runs on these defaults.

A save that carries `_tab` (`methods`, `requirements` or `trusted-devices`, see `Settings::TAB_FIELDS`) changes only that tab's keys. A key the tab owns but the input lacks counts as an unchecked box, and every other key keeps its stored value. An unknown tab changes nothing and adds a settings error. A save without `_tab` is treated as the full form, where every missing checkbox means off. Either way the sanitizer returns the complete array, so WordPress's second sanitize pass when it first adds the option changes nothing.

| Key | Type | Default | Notes |
| --- | --- | --- | --- |
| `enable_2fa` | bool | false | Site-wide master switch. |
| `force_2fa_roles` | string[] | `[]` | Validated against registered roles. |
| `grace_period_days` | int 0–365 | 14 | `0` puts unenrolled required users in restricted setup mode at their first sign-in. |
| `default_method` | `totp` \| `email` | `totp` | Falls back to an enabled method. The legacy value `email_backup` maps to `email`. |
| `enable_totp`, `enable_email` | bool | true | Method availability. |
| `enable_passkeys` | bool | false | Passkey registration, passwordless sign-in and passkeys as a second factor (needs HTTPS). |
| `allow_passkey_only` | bool | false | Users may turn off password sign-in for their account. |
| `code_expiry` | int 1–60 minutes | 10 | Email code lifetime. |
| `remember_device` | bool | false | Trusted devices on or off. |
| `remember_duration` | int 1–365 days | 30 | Trusted device lifetime. |

**Option** `brain2fa_emails` (not autoloaded, absent until something is customized): `from_name`, `from_address` and `templates`, keyed by email ID. Each template stores only `subject` and `body` when they differ from the default, and `enabled => false` when an alert is turned off. Read it through `EmailRegistry::get_stored()` and `EmailRegistry::get_template()`.

**User meta:** `brain2fa_enabled`, `brain2fa_method`, `brain2fa_secret` (TOTP, base32), `brain2fa_recovery_codes` (array of `wp_hash_password` hashes), `brain2fa_grace_expires_at` (Unix time, created lazily), `brain2fa_totp_last_step` (last accepted TOTP time step, for replay protection), `brain2fa_remembered_devices` (list of `{token: hash, expires_at}`, max 10), `brain2fa_failed_attempts` (`{count, last_failure, locked_until}`).

**Transients:** `brain2fa_rate_{wp_hash(ip)}` (preflight attempt counter, 60 s), `brain2fa_backup_code_{user_id}` (pending email code), `brain2fa_ip_failures_{wp_hash(ip)}` (failed verifications per IP, `{count, expires_at}`).

**Table** `{prefix}brain2fa_passkeys`: `user_id`, `credential_hash` (SHA-256 of the credential ID, unique), `credential_id` (base64url), `public_key` (PEM), `sign_count`, `transports`, `aaguid`, `backup_eligible`, `backed_up`, `name`, `created_at`, `last_used_at` (GMT). Rows are deleted with the user (`deleted_user`). Option `brain2fa_db_version` tracks the schema.

**Passkey user meta and transients:** `brain2fa_webauthn_user_handle` (base64url of 32 random bytes), `brain2fa_passkey_only` (password sign-in turned off), `brain2fa_webauthn_reg_{user_id}` and `brain2fa_webauthn_auth_{hash}` (challenges with the expected user ID, 5 min), `brain2fa_passkey_token_{hash}` (login tokens, 2 min), `brain2fa_passkey_opts_{ip hash}` (options rate limit).

**Cookies:** `brain2fa_remember_{user_id}`: random 64-character token, `httponly`, `secure` on SSL, `SameSite=Lax`. `brain2fa_passkey_{COOKIEHASH}`: random passkey session value binding challenges and tokens to the browser, `httponly`, `SameSite=Lax`, cleared after sign-in.

**Uninstall:** deleting the plugin runs `uninstall.php`, which removes the options above, every `brain2fa_` transient and the passkeys table on each site, then the user meta listed above. Deactivating keeps everything. Define `BRAIN2FA_KEEP_DATA_ON_UNINSTALL` as `true` in `wp-config.php` to keep the data on deletion as well. Cookies expire on their own.

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
| `brain2fa_send_change_notification` | filter | Return `false` to skip a security alert email. Args: `true`, `WP_User`, `string $event` (the email ID, such as `activated` or `passkey_added`). An alert turned off on the Emails tab is not sent, whatever this filter returns. |
| `brain2fa_email_placeholders` | filter | Placeholder values for an email, keyed by name without braces. Add a key to support a new placeholder. Args: `array $values`, `string $id`, `WP_User`, `array $context`. |
| `brain2fa_email_content` | filter | The rendered email just before sending: `array( 'subject' => ..., 'body' => ... )`. Args: `array $email`, `string $id`, `WP_User`, `array $context`. The subject is made single-line afterwards. |
| `login_errors` | filter (core) | Applied to preflight credential errors, as on `wp-login.php`. |

## Front-end build

- `src/js/` → webpack → `assets/js/{login,admin,passkey-login,passkey-manage}.js` (dependencies in `assets/assets.php`), using `@wordpress/dependency-extraction-webpack-plugin`. `@simplewebauthn/browser` is bundled into the passkey scripts.
- `src/scss/` → `assets/css/admin.css`.
- `assets/js/` and `assets/css/` are gitignored and produced by `npm run build`. Release builds include them.

## Release build

CI (`.github/workflows/ci.yml`) runs on every push to `master` and every pull request: PHP syntax on 8.1 to 8.4, `composer phpcs`, `npm run lint` and `npm run build`, `scripts/check-versions.sh`, POT freshness, and WordPress.org Plugin Check against `dist/brain-2fa` from `npm run package`.

`npm run dist` runs: webpack production build, then POT generation (`wp i18n make-pot`), then `composer install --no-dev`, then `scripts/build-dist.php` (skips `vendor/lbuchs/webauthn/_test`, a demo server; copies `assets`, `includes`, `languages`, `vendor`, `brain-2fa.php`, `uninstall.php`, `composer.json`, `composer.lock` and `readme.txt` into `dist/brain-2fa/` and zips it), then restores dev dependencies. `scripts/deploy.sh` publishes `dist/brain-2fa/` to SVN. See `docs/releasing.md`.
