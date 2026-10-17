# Brain 2FA

Two-factor authentication and passwordless passkey sign-in for WordPress. Users confirm their sign-in with a code from an authenticator app or their inbox, or skip the password entirely with a passkey. Everything runs on your own server: no third-party services.

[![CI](https://github.com/Braintum/brain-2fa/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/Braintum/brain-2fa/actions/workflows/ci.yml)
[![WordPress.org](https://img.shields.io/wordpress/plugin/v/brain-2fa.svg)](https://wordpress.org/plugins/brain-2fa/)
[![License: GPLv2 or later](https://img.shields.io/badge/license-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

Install it from the [WordPress.org plugin directory](https://wordpress.org/plugins/brain-2fa/). This repository holds the development source.

## Features

- **Passkeys:** passwordless sign-in with Face ID, Touch ID, Windows Hello, a phone or a security key; also usable as the second step after a password, and optionally as the only way into an account. Requires HTTPS; off by default.
- **Authenticator apps (TOTP):** any TOTP app, set up with a QR code or a secret key, with one-time recovery codes.
- **Email codes:** a one-time code sent to the account address, with a configurable expiry.
- **Role-based enforcement:** require 2FA for selected roles, with a setup grace period. After the deadline, users can sign in but only reach the setup page.
- **Trusted devices:** optional, expiring, hashed "remember this device" tokens.
- **Editable emails:** every email the plugin sends (sign-in code and nine security alerts) can be edited with placeholders, previewed, test-sent and reset under **Brain 2FA > Settings > Emails**.
- **WooCommerce:** the My Account login form is protected, and customers manage 2FA from their dashboard.
- **Works without JavaScript**, and is fully translatable.

## Requirements

- WordPress 6.4 or later
- PHP 8.1 or later
- For development: Composer, Node.js (version in `.nvmrc`) and WP-CLI

## Using the plugin

1. Activate the plugin, then open **Brain 2FA > Settings**.
2. **Authentication Methods:** turn on two-factor authentication and choose the methods (authenticator app, email codes, passkeys).
3. **Enrollment Requirements:** choose the roles that must use 2FA and their grace period.
4. **Trusted Devices:** allow "remember this device" and set how long it lasts.
5. **Emails:** adjust the wording of the emails and the sender, if you like.

Each user sets up their own sign-in protection under **Brain 2FA > Login Security** (WooCommerce customers use the link on their My Account dashboard). Administrators can extend setup deadlines, remove a user's passkeys and turn password sign-in back on from the user's profile.

### Email placeholders

All emails support `{user_display_name}`, `{user_first_name}`, `{user_login}`, `{user_email}`, `{site_name}`, `{site_url}`, `{login_url}`, `{login_security_url}` and `{date_time}`. The sign-in code email adds `{code}` (required) and `{expiry_minutes}`; passkey alerts add `{passkey_name}`. Unknown placeholders are left as typed.

Developers can add placeholders with `brain2fa_email_placeholders` and change a rendered email with `brain2fa_email_content`. See [docs/architecture.md](docs/architecture.md#extension-points) for all hooks.

## Development

```sh
git clone https://github.com/Braintum/brain-2fa.git
cd brain-2fa
composer install
npm ci
```

| Command | What it does |
| --- | --- |
| `npm run dev` | Rebuild `assets/` from `src/` on every change |
| `npm run build` | Production build of `src/js` and `src/scss` into `assets/` |
| `npm run lint` / `npm run lint:fix` | ESLint (`eslint-config-wordpress`) |
| `composer phpcs` / `composer phpcbf` | WordPress Coding Standards (WordPress-Extra + WordPress-Docs) and PHP 8.1+ compatibility |
| `npm run make-pot` | Regenerate `languages/brain-2fa.pot` after changing strings |
| `bash scripts/check-versions.sh` | Check that the version, requirements and changelog agree |
| `npm run package` | Build the production plugin in `dist/brain-2fa` |

Edit sources only: `src/js/`, `src/scss/` and the PHP in `includes/`. `assets/`, `languages/*.pot`, `vendor/` and `dist/` are generated.

### Project structure

```
brain-2fa/
├── brain-2fa.php        # Plugin header and bootstrap
├── includes/            # PHP, PSR-4 namespace Brain_2FA\
│   ├── Admin/           # Settings page, Login Security page, user profile and list
│   ├── Auth/            # Login flow, enrollment guard, account events
│   ├── Emails/          # Email templates, placeholders and the mailer
│   ├── Passkeys/        # WebAuthn registration, sign-in and storage
│   ├── TwoFactor/       # TOTP and email code methods
│   ├── App.php          # Wires everything up
│   └── Utils.php        # Shared 2FA policy
├── src/                 # JavaScript and SCSS sources
├── assets/              # Compiled assets (generated)
├── languages/           # POT file (generated)
├── docs/                # Architecture, security model, release process
└── scripts/             # Build, deploy and check scripts
```

### Documentation

- [docs/architecture.md](docs/architecture.md): components, login flow, data model, extension hooks and build.
- [docs/security.md](docs/security.md): threat model, design decisions and invariants.
- [docs/releasing.md](docs/releasing.md): WordPress.org release process.
- [README-JAVASCRIPT.md](README-JAVASCRIPT.md): JavaScript build details.
- [AGENTS.md](AGENTS.md): conventions for contributors and coding agents.

### Continuous integration

Every push to `master` and every pull request runs [`.github/workflows/ci.yml`](.github/workflows/ci.yml): PHP syntax on 8.1 to 8.4, PHPCS, ESLint and the production build, the version check, POT freshness and WordPress.org [Plugin Check](https://wordpress.org/plugins/plugin-check/) on the production package. Pull requests must be green.

## Contributing

1. Fork the repository and create a branch from `master`.
2. Follow the WordPress Coding Standards; `composer phpcs` and `npm run lint` must stay clean. Do not silence security sniffs with `phpcs:ignore`.
3. Run `npm run build` and `npm run make-pot` when you change sources or strings.
4. Update the docs and the `readme.txt` changelog in the same change.
5. Open a pull request.

## Security

This plugin sits in the authentication path. Please report vulnerabilities privately to security@braintum.com rather than opening a public issue.

## Support

- WordPress.org support forum: <https://wordpress.org/support/plugin/brain-2fa/>
- Braintum support: <https://www.braintum.com/support/>

## License

GPLv2 or later. Copyright (C) 2026 Braintum.

Built with [spomky-labs/otphp](https://github.com/Spomky-Labs/otphp), [bacon/bacon-qr-code](https://github.com/Bacon/BaconQrCode) and [lbuchs/webauthn](https://github.com/lbuchs/WebAuthn).
