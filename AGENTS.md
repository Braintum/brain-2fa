# Brain 2FA Agent Guide

## Scope and Bootstrap

This directory is a WordPress plugin, not a standalone application. `brain-2fa.php` loads Composer and creates the `Brain_2FA\App` singleton. `App::on_plugins_loaded()` registers the authentication, asset, admin, and two-factor method classes after WordPress has loaded plugins.

Keep production changes inside this plugin. Do not modify WordPress core or `vendor/` for plugin behavior.

## Runtime Structure

- `includes/Auth/LoginController.php` owns the login preflight AJAX action and the native `authenticate` filter.
- `includes/Auth/AccountEvents.php` revokes trusted devices when a password changes.
- `includes/Auth/TrustedDeviceRepository.php` stores trusted devices; users list and forget them on the Login Security page.
- `includes/Auth/UserLock.php` is the per-user lock for single-use credential state (last TOTP step, recovery codes).
- `includes/Passkeys/` holds passkey (WebAuthn) support: `WebAuthnServer` (wraps lbuchs/webauthn), `PasskeyRepository` (the `brain2fa_passkeys` table), `PasskeyRegistration` and `PasskeyLogin`. `includes/Install.php` creates the table.
- `includes/Auth/EnrollmentGuard.php` restricts users who must enroll to the setup page, and decides each user's Login Security address (`get_setup_url( $user )`: the My Account tab for WooCommerce customers, wp-admin otherwise). Never build the setup URL by hand.
- `includes/WooCommerce/LoginSecurityTab.php` adds the Login Security tab to WooCommerce My Account (endpoint, menu, assets) and renders `LoginSecurityPage` in its `account` context; actions run on `template_redirect` before output.
- `includes/Auth/LoginAssets.php` loads the browser login script on `wp-login.php`.
- `includes/Auth/RecoveryCodeWarning.php` warns the current user about running low on recovery codes (admin notice and WooCommerce dashboard); read-only.
- `includes/TwoFactor/Methods/TotpMethod.php` owns TOTP setup, verification, and recovery codes.
- `includes/Admin/Settings.php` owns site-wide settings (three tabbed forms in `views/settings/`, plus the Emails tab owned by `includes/Admin/EmailSettings.php`). `includes/Admin/LoginSecurityPage.php` owns the per-user Login Security page (views in `views/login-security/`), shared by wp-admin and the My Account tab: use `LoginSecurityPage::button_class()` for buttons in its views so both contexts get the right classes.
- `includes/Admin/UserProfile.php` owns profile status, grace extensions, and Dashboard notices.
- `includes/Enrollment/EnrollmentStatus.php` computes each user's enrollment status (PHP and an equivalent SQL condition, used by the `brain2fa_status` / `brain2fa_method` user query variables); `EnrollmentReport.php` builds the cached Overview counts. Both are read-only: only the user's own session starts a setup deadline.
- `includes/Enrollment/ReminderScheduler.php` sends the optional setup reminder emails from an hourly WP-Cron scan (thresholds, per-site state in the `{blog prefix}brain2fa_reminder` user option, claims under `UserLock`, bounded retries). `brain-2fa.php` registers its deactivation hook, and `uninstall.php` always removes its scheduled events, even when data is kept.
- `includes/Admin/OverviewPage.php` is the Brain 2FA > Overview screen.
- `includes/Admin/UserListColumns.php` shows the read-only 2FA status in Users and filters the list by status.
- `includes/Emails/` holds every outgoing email: `EmailRegistry` (definitions, placeholders, default text, stored customizations in the `brain2fa_emails` option) and `Mailer` (renders and sends). Send plugin emails only through `Mailer::send( $id, $user, $context )` or, for alerts, `Utils::send_factor_change_notification()`; never call `wp_mail()` directly. A new email needs a definition in `EmailRegistry::get_definitions()` so admins can edit it.
- `tests/wp-cli/` holds WP-CLI integration checks (see Validation); they are not shipped.
- `uninstall.php` removes all plugin data when the plugin is deleted: per-site options, transients and the passkeys table (every site on multisite), then the user meta. It lists the user meta keys explicitly, so add any new `brain2fa_*` user meta key there. `BRAIN2FA_KEEP_DATA_ON_UNINSTALL` in `wp-config.php` keeps the data.
- `includes/Utils.php` is the shared policy layer for enablement, role enforcement, and grace deadlines.

The AJAX request validates credentials before showing the 2FA field; it does not sign the user in. The browser then submits the normal WordPress login form with `brain2fa_code`. Both paths must apply the same policy because a user can bypass the AJAX preflight by submitting `wp-login.php` directly.

The unauthenticated preflight is rate limited by IP. Do not add a terminating `check_ajax_referer()` gate to it: cached `wp-login.php` pages can carry an expired nonce and make valid sign-ins fail with HTTP 403.

## 2FA Policy and Grace Periods

Site settings are stored in the `brain2fa_settings` option. Relevant values are:

- `enable_2fa`: site-wide master switch.
- `force_2fa_roles`: role slugs subject to the setup policy.
- `grace_period_days`: `0` through `365`, defaulting to `14`.

User metadata includes `brain2fa_enabled`, `brain2fa_method`, `brain2fa_secret`, `brain2fa_recovery_codes`, `brain2fa_totp_last_step` (TOTP replay protection), `brain2fa_failed_attempts` (verification lockout), and `brain2fa_grace_expires_at`. A deadline is created lazily when an unenrolled user in a required role is first evaluated in their own session (sign-in, `EnrollmentGuard`, Dashboard notice); the Users list, the Overview and another user's profile never create one. A value of `0` grace days makes that deadline immediate. Administrators extend a deadline from the target user's profile; the extension adds days to an active deadline or starts from the current time after expiry.

After the deadline, a user is not blocked from signing in. `Utils::is_enrollment_required()` puts them in restricted setup mode instead: `EnrollmentGuard` sends them to their Login Security page after login (the My Account tab for WooCommerce customers) and redirects every other admin page, front-end page, REST and AJAX request (except Heartbeat and passkey registration) there until they enroll; on the front end only the tab and the My Account log out endpoint stay open. This also applies to sessions that started before the deadline. XML-RPC and REST logins stay blocked in `verify_2fa()` because they have no setup page. Restricted mode never applies when no method is enabled. Enrolling clears the deadline; a required user who deactivates is restricted again immediately (`Utils::require_enrollment_now()`). The Dashboard notice surfaces an active deadline for the current user.

## Passkeys

Passkeys are off by default (`enable_passkeys`) and only work over HTTPS (or localhost). They count as a second factor next to TOTP/email through `Utils::is_2fa_enabled_for_user()` (code method **or** usable passkey), can be the second step after a password (preflight `passkey` options, see `LoginController::get_preflight_response()`), and can replace the password entirely for users who turn on passkey-only sign-in (`allow_passkey_only` setting, `brain2fa_passkey_only` meta). A passkey sign-in must keep requiring user verification; it satisfies 2FA, so `verify_2fa()` skips the code when `PasskeyLogin::is_passkey_authentication()` is true for that user. The sign-in finishes with a normal form submission carrying a single-use, cookie-bound token that `PasskeyLogin::authenticate_with_token()` (priority 15) exchanges for the user, so do not add a separate session-creation path. Schema changes go through `Install::DB_VERSION`. Test passkey changes with a software authenticator (P-256 key, CBOR attestation, signed assertions) under HTTPS URL filters; browsers need real HTTPS, so use Chrome's virtual authenticator over local TLS for end-to-end checks.

## Assets and Localization

Edit JavaScript in `src/js/` and styles in `src/scss/`. WordPress loads generated files from `assets/js/` and `assets/css/`, so run `npm run build` after source changes and include the resulting assets when appropriate. Do not hand-edit compiled assets unless a build is unavailable and the matching source change is also made.

All user-visible strings use the `brain-2fa` text domain (PHP and `@wordpress/i18n`). Regenerate the POT file after changing strings with `npm run make-pot`.

## Validation

Use the narrowest applicable checks:

```sh
composer phpcs                  # WordPress-Extra + WordPress-Docs + PHPCompatibilityWP (PHP 8.1+), must stay clean
npm run lint                    # eslint-config-wordpress, must stay clean
npm run build
npm run make-pot                # after any string change; CI fails on a stale POT
bash scripts/check-versions.sh  # version, requirements and changelog agree
php wp-cli.phar plugin status brain-2fa
php wp-cli.phar eval 'echo Brain_2FA\Utils::get_grace_period_days();'
```

`wp-cli.phar` lives in the WordPress root (`../../..` from this plugin); a global `wp` also works.

CI (`.github/workflows/ci.yml`) runs these checks plus PHP syntax on 8.1–8.4 and WordPress.org Plugin Check on every push to `master` and every pull request. The plugin is live on WordPress.org, so keep it green.

There is no PHPUnit configuration in this plugin. `npm run test:wp` (`tests/run-wp-cli.sh`) runs the WP-CLI checks in `tests/wp-cli/` against the local site: tab-scoped settings saves, Login Security page states, the code-method lockout warning, editable emails, concurrent code consumption and hashed email codes (`hardening`, which races parallel WP-CLI workers from `tests/wp-cli/workers/`), trusted devices (`trusted-devices`), enrollment statuses, the Overview and Users filters (`enrollment`), setup reminders including overlapping scans (`reminders`), the low recovery code warning (`recovery-warning`), the WooCommerce My Account tab (`account-tab`), and uninstall. They create throwaway users, capture mail and restore every option they touch, but run them on development sites only. Run one with `bash tests/run-wp-cli.sh emails`. Add a check there when you change behaviour they cover: use `b2_`-prefixed helpers, `$GLOBALS` for counters (top-level variables are local inside `wp eval-file`), and end with `WP_CLI::halt( 1 )` on failure. Not everything can be checked this way. For login changes, test a required user with no 2FA during the grace window and after expiry (restricted mode, including a 0-day grace period), a user with active TOTP through both the JavaScript-assisted path and a no-JavaScript submission (the server-rendered `brain2fa_code_fallback` field), and a WooCommerce customer on the My Account login form. For email changes, capture mail with the `pre_wp_mail` filter and check the rendered subject, body, recipient and sender.

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
