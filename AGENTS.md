# Brain 2FA Agent Guide

## Scope and Bootstrap

This directory is a WordPress plugin, not a standalone application. `brain-2fa.php` loads Composer and creates the `Brain_2FA\App` singleton. `App::on_plugins_loaded()` registers the authentication, asset, admin, and two-factor method classes after WordPress has loaded plugins.

Keep production changes inside this plugin. Do not modify WordPress core or `vendor/` for plugin behavior.

## Runtime Structure

- `includes/Auth/LoginController.php` owns the login preflight AJAX action and the native `authenticate` filter.
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

User metadata includes `brain2fa_enabled`, `brain2fa_method`, `brain2fa_secret`, `brain2fa_recovery_codes`, and `brain2fa_grace_expires_at`. A deadline is created lazily when an unenrolled user in a required role is first evaluated. A value of `0` grace days makes that deadline immediate. Administrators extend a deadline from the target user's profile; the extension adds days to an active deadline or starts from the current time after expiry.

Keep `Utils::is_grace_period_expired()` in both `LoginController::handle_login()` and `LoginController::verify_2fa()`. The former gives AJAX users a useful error; the latter protects native login requests. The Dashboard notice intentionally surfaces both an active deadline and an exceeded one for the current user.

## Assets and Localization

Edit JavaScript in `src/js/` and styles in `src/scss/`. WordPress loads generated files from `assets/js/` and `assets/css/`, so run `npm run build` after source changes and include the resulting assets when appropriate. Do not hand-edit compiled assets unless a build is unavailable and the matching source change is also made.

All user-visible PHP strings use the `brain2fa` text domain. Regenerate the POT file after changing strings with `npm run make-pot`.

## Validation

Use the narrowest applicable checks:

```sh
npm run lint
npm run build
php wp-cli.phar plugin status brain-2fa
php wp-cli.phar eval 'echo Brain_2FA\Utils::get_grace_period_days();'
```

There is no PHPUnit configuration in this plugin. For login changes, test a required user with no 2FA during the grace window, after expiry, and a user with active TOTP through both the normal `wp-login.php` form and the JavaScript-assisted path.