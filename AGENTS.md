# Brain 2FA Agent Guide

## Scope and Bootstrap

This directory is a WordPress plugin, not a standalone application. `brain-2fa.php` loads Composer and creates the `Brain_2FA\App` singleton. `App::on_plugins_loaded()` registers the authentication, asset, admin, and two-factor method classes after WordPress has loaded plugins.

Keep production changes inside this plugin. Do not modify WordPress core or `vendor/` for plugin behavior.

## Runtime Structure

- `includes/Auth/LoginController.php` owns the login preflight AJAX action and the native `authenticate` filter.
- `includes/Auth/AccountEvents.php` revokes trusted devices when a password changes.
- `includes/Passkeys/` holds passkey (WebAuthn) support: `WebAuthnServer` (wraps lbuchs/webauthn), `PasskeyRepository` (the `brain2fa_passkeys` table), `PasskeyRegistration` and `PasskeyLogin`. `includes/Install.php` creates the table.
- `includes/Auth/EnrollmentGuard.php` restricts users who must enroll to the setup page, and opens that page to WooCommerce customers.
- `includes/Auth/LoginAssets.php` loads the browser login script on `wp-login.php`.
- `includes/TwoFactor/Methods/TotpMethod.php` owns TOTP setup, verification, and recovery codes.
- `includes/Admin/Settings.php` owns site-wide settings and the Login Security page.
- `includes/Admin/UserProfile.php` owns profile status, grace extensions, and Dashboard notices.
- `includes/Admin/UserListColumns.php` shows 2FA, setup-deadline, and grace-expiry status in Users.
- `includes/Utils.php` is the shared policy layer for enablement, role enforcement, and grace deadlines.

The AJAX request validates credentials before showing the 2FA field; it does not sign the user in. The browser then submits the normal WordPress login form with `brain2fa_code`. Both paths must apply the same policy because a user can bypass the AJAX preflight by submitting `wp-login.php` directly.

The unauthenticated preflight is rate limited by IP. Do not add a terminating `check_ajax_referer()` gate to it: cached `wp-login.php` pages can carry an expired nonce and make valid sign-ins fail with HTTP 403.

## 2FA Policy and Grace Periods

Site settings are stored in the `brain2fa_settings` option. Relevant values are:

- `enable_2fa`: site-wide master switch.
- `force_2fa_roles`: role slugs subject to the setup policy.
- `grace_period_days`: `0` through `365`, defaulting to `14`.

User metadata includes `brain2fa_enabled`, `brain2fa_method`, `brain2fa_secret`, `brain2fa_recovery_codes`, `brain2fa_totp_last_step` (TOTP replay protection), `brain2fa_failed_attempts` (verification lockout), and `brain2fa_grace_expires_at`. A deadline is created lazily when an unenrolled user in a required role is first evaluated. A value of `0` grace days makes that deadline immediate. Administrators extend a deadline from the target user's profile; the extension adds days to an active deadline or starts from the current time after expiry.

After the deadline, a user is not blocked from signing in. `Utils::is_enrollment_required()` puts them in restricted setup mode instead: `EnrollmentGuard` sends them to the Login Security page after login and redirects every other admin page, front-end page, REST and AJAX request (except Heartbeat) there until they enroll. This also applies to sessions that started before the deadline. XML-RPC and REST logins stay blocked in `verify_2fa()` because they have no setup page. Restricted mode never applies when no method is enabled. Enrolling clears the deadline; a required user who deactivates is restricted again immediately (`Utils::require_enrollment_now()`). The Dashboard notice surfaces an active deadline for the current user.

## Passkeys

Passkeys are off by default (`enable_passkeys`) and only work over HTTPS (or localhost). They count as a second factor next to TOTP/email through `Utils::is_2fa_enabled_for_user()` (code method **or** usable passkey), can be the second step after a password (preflight `passkey` options, see `LoginController::get_preflight_response()`), and can replace the password entirely for users who turn on passkey-only sign-in (`allow_passkey_only` setting, `brain2fa_passkey_only` meta). A passkey sign-in must keep requiring user verification; it satisfies 2FA, so `verify_2fa()` skips the code when `PasskeyLogin::is_passkey_authentication()` is true for that user. The sign-in finishes with a normal form submission carrying a single-use, cookie-bound token that `PasskeyLogin::authenticate_with_token()` (priority 15) exchanges for the user, so do not add a separate session-creation path. Schema changes go through `Install::DB_VERSION`. Test passkey changes with a software authenticator (P-256 key, CBOR attestation, signed assertions) under HTTPS URL filters; browsers need real HTTPS, so use Chrome's virtual authenticator over local TLS for end-to-end checks.

## Assets and Localization

Edit JavaScript in `src/js/` and styles in `src/scss/`. WordPress loads generated files from `assets/js/` and `assets/css/`, so run `npm run build` after source changes and include the resulting assets when appropriate. Do not hand-edit compiled assets unless a build is unavailable and the matching source change is also made.

All user-visible strings use the `brain-2fa` text domain (PHP and `@wordpress/i18n`). Regenerate the POT file after changing strings with `npm run make-pot`.

## Validation

Use the narrowest applicable checks:

```sh
composer phpcs        # WordPress-Extra + WordPress-Docs, must stay clean
npm run lint          # eslint-config-wordpress, must stay clean
npm run build
php wp-cli.phar plugin status brain-2fa
php wp-cli.phar eval 'echo Brain_2FA\Utils::get_grace_period_days();'
```

`wp-cli.phar` lives in the WordPress root (`../../..` from this plugin); a global `wp` also works.

There is no PHPUnit configuration in this plugin. For login changes, test a required user with no 2FA during the grace window and after expiry (restricted mode, including a 0-day grace period), a user with active TOTP through both the JavaScript-assisted path and a no-JavaScript submission (the server-rendered `brain2fa_code_fallback` field), and a WooCommerce customer on the My Account login form.

## Coding Standards

Follow the WordPress Coding Standards; the PHPCS and ESLint baselines are clean and must stay that way. Key points: `brain2fa_` prefix for hooks/options/meta/transients, `Brain_2FA\` namespace, Yoda conditions, strict comparisons, long `array()` syntax, docblocks everywhere, escape late, `wp_unslash()` then sanitize. Do not silence security sniffs with `phpcs:ignore`; fix the code. Full guide: `.claude/skills/wp-coding-standards/SKILL.md`.

## Security

This plugin is in the authentication path. Before touching login, verification, secrets, recovery codes, trusted devices, settings, or any request handler, read `.claude/skills/wp-security/SKILL.md` and `docs/security.md`. Non-negotiables: enforce every rule in `LoginController::verify_2fa()` and fail closed; never authenticate from the AJAX preflight; capability + nonce on every state change; hash long-lived tokens; never send secrets to third parties; generic credential errors.

## Generated and Third-Party Files

Never hand-edit `vendor/`, `node_modules/`, `dist/`, `assets/js/`, `assets/css/`, or `languages/*.pot`. Change the source (`composer.json`, `src/js/`, `src/scss/`, PHP strings) and rebuild.

## Releasing

Releases go to WordPress.org SVN via `scripts/deploy.sh` (`npm run deploy:dry`, `npm run deploy`, `npm run deploy:assets`). The version lives in four places: the `brain-2fa.php` header, `BRAIN_2FA_VERSION`, `readme.txt` `Stable tag`, and `package.json`. Listing assets live in `.wordpress-org/`. Do not run a real deploy unless the user explicitly asks; SVN prompts for their password. Full process: `docs/releasing.md`.

## Documentation

- `docs/architecture.md`: bootstrap, components, login flow, data model, extension hooks, build.
- `docs/security.md`: threat model, design decisions, invariants, known gaps.
- `docs/releasing.md`: WordPress.org SVN release and asset process.

Update these docs in the same change when you alter the behavior they describe.
