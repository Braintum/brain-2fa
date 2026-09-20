=== Brain 2FA ===
Contributors: braintum
Tags: two-factor authentication, 2fa, security, authenticator, totp
Requires at least: 6.4
Tested up to: 7.1.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Add TOTP and email-based two-factor authentication to WordPress accounts.

== Description ==

Brain 2FA helps protect WordPress accounts with a second authentication factor.

Features include:

* TOTP authenticator-app verification.
* Email verification codes.
* One-time recovery codes for TOTP users.
* Role-based two-factor authentication requirements and setup grace periods.
* Trusted-device support with expiring, password-hashed tokens.
* Support for the default WordPress login form and WooCommerce My Account login form.

== Installation ==

1. Upload the `brain-2fa` folder to the `/wp-content/plugins/` directory, or install the plugin through the WordPress Plugins screen.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open **Brain 2FA > Settings** and enable two-factor authentication.
4. Configure authentication methods and role requirements as needed.

== Frequently Asked Questions ==

= How do users set up two-factor authentication? =

Users can open **Brain 2FA > Login Security** from the WordPress admin area and follow the setup instructions for the configured default method.

= What happens if a user loses access to their authenticator app? =

TOTP users can use a recovery code. Site administrators can also manage setup grace periods from the user profile.

= Does the plugin support WooCommerce login? =

Yes. The plugin supports the standard WooCommerce My Account login form.

== Changelog ==

= 1.0.0 =

* Initial release.

== Upgrade Notice ==

= 1.0.0 =

Initial release.
