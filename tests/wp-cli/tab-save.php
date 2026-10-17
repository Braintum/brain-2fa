<?php
/**
 * Brain 2FA WP-CLI check: Tab-scoped settings saves.
 *
 * Each settings tab saves only its own keys, including on a site that never saved settings, and the first save is idempotent. Restores brain2fa_settings.
 *
 * Development sites only: it writes options and creates users while it runs.
 * Run all checks with `npm run test:wp`, or this one with
 * `php ../../../wp-cli.phar eval-file tests/wp-cli/tab-save.php` from the plugin directory.
 * Prints PASS/FAIL per check and exits 1 when any check fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

use Brain_2FA\Admin\Settings;
use Brain_2FA\Utils;

$missing  = new stdClass();
$original = get_option( 'brain2fa_settings', $missing );
$settings = Settings::instance();
$settings->register_settings();

$GLOBALS['b2_fails'] = 0;
function b2_check( $label, $cond ) {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$GLOBALS['b2_fails'];
	}
}

try {
	// 1. Never-saved site: saving only Trusted Devices keeps both code methods on.
	delete_option( 'brain2fa_settings' );
	b2_check( 'never-saved: totp effective on', Utils::is_method_enabled( 'totp' ) );
	update_option( 'brain2fa_settings', array( '_tab' => 'trusted-devices', 'remember_device' => '1', 'remember_duration' => '7' ) );
	$s = get_option( 'brain2fa_settings' );
	b2_check( 'never-saved trusted-devices save: totp stays on', true === $s['enable_totp'] );
	b2_check( 'never-saved trusted-devices save: email stays on', true === $s['enable_email'] );
	b2_check( 'never-saved trusted-devices save: remember_device saved', true === $s['remember_device'] && 7 === $s['remember_duration'] );
	b2_check( 'no _tab key stored', ! array_key_exists( '_tab', $s ) );
	b2_check( 'all 11 keys stored', array() === array_diff_key( Utils::SETTINGS_DEFAULTS, $s ) );

	// 2. First-ever save equals a later save of the same input (double-sanitize is idempotent).
	$input = array( '_tab' => 'methods', 'enable_2fa' => '1', 'enable_totp' => '1', 'enable_passkeys' => '1', 'allow_passkey_only' => '1', 'default_method' => 'email', 'code_expiry' => '5' );
	delete_option( 'brain2fa_settings' );
	update_option( 'brain2fa_settings', $input );
	$first = get_option( 'brain2fa_settings' );
	update_option( 'brain2fa_settings', $input );
	$second = get_option( 'brain2fa_settings' );
	b2_check( 'first save equals later save', $first === $second );
	b2_check( 'methods: unchecked email cleared within tab', false === $first['enable_email'] );
	b2_check( 'methods: default falls back to totp when email off', 'totp' === $first['default_method'] );

	// 3. Each tab preserves other tabs' values, including passkey-only and unchecked booleans.
	update_option( 'brain2fa_settings', array( 'enable_2fa' => '1', 'enable_totp' => '1', 'enable_email' => '1', 'enable_passkeys' => '1', 'allow_passkey_only' => '1', 'default_method' => 'email', 'code_expiry' => '20', 'force_2fa_roles' => array( 'administrator' ), 'grace_period_days' => '3', 'remember_device' => '', 'remember_duration' => '45' ) );
	$before = get_option( 'brain2fa_settings' );
	update_option( 'brain2fa_settings', array( '_tab' => 'requirements', 'grace_period_days' => '0' ) );
	$after = get_option( 'brain2fa_settings' );
	b2_check( 'requirements: roles cleared (none submitted)', array() === $after['force_2fa_roles'] && 0 === $after['grace_period_days'] );
	b2_check( 'requirements: other tabs untouched', array_diff_key( $before, array_flip( Settings::TAB_FIELDS['requirements'] ) ) === array_diff_key( $after, array_flip( Settings::TAB_FIELDS['requirements'] ) ) );

	$before = $after;
	update_option( 'brain2fa_settings', array( '_tab' => 'trusted-devices', 'remember_device' => '1', 'remember_duration' => '10' ) );
	$after = get_option( 'brain2fa_settings' );
	b2_check( 'trusted-devices: other tabs untouched (passkey-only kept)', true === $after['allow_passkey_only'] && array_diff_key( $before, array_flip( Settings::TAB_FIELDS['trusted-devices'] ) ) === array_diff_key( $after, array_flip( Settings::TAB_FIELDS['trusted-devices'] ) ) );

	$before = $after;
	update_option( 'brain2fa_settings', array( '_tab' => 'methods', 'enable_2fa' => '1', 'enable_totp' => '1', 'code_expiry' => '15' ) );
	$after = get_option( 'brain2fa_settings' );
	b2_check( 'methods: other tabs untouched', array_diff_key( $before, array_flip( Settings::TAB_FIELDS['methods'] ) ) === array_diff_key( $after, array_flip( Settings::TAB_FIELDS['methods'] ) ) );
	b2_check( 'methods: unchecked passkeys cleared within tab', false === $after['enable_passkeys'] && false === $after['allow_passkey_only'] );

	// 4. Invalid tab identifiers cannot change settings.
	$before = $after;
	foreach ( array( 'bogus', array( 'methods' ), '' ) as $bad ) {
		update_option( 'brain2fa_settings', array( '_tab' => $bad, 'enable_2fa' => '', 'remember_duration' => '365' ) );
		b2_check( 'invalid tab ' . wp_json_encode( $bad ) . ' changes nothing', get_option( 'brain2fa_settings' ) === $before );
	}
	b2_check( 'invalid tab reports an error', (bool) wp_list_filter( get_settings_errors( 'brain2fa_messages' ), array( 'code' => 'brain2fa_invalid_tab' ) ) );

	// 5. Full-form path (no _tab) behaves as before.
	update_option( 'brain2fa_settings', array( 'enable_2fa' => '1' ) );
	$s = get_option( 'brain2fa_settings' );
	b2_check( 'full form: missing checkbox means off (unchanged behavior)', false === $s['enable_totp'] && 14 === $s['grace_period_days'] );
} finally {
	if ( $original === $missing ) {
		delete_option( 'brain2fa_settings' );
	} else {
		remove_all_filters( 'sanitize_option_brain2fa_settings' );
		update_option( 'brain2fa_settings', $original );
	}
	echo 'Restored original option: ' . ( get_option( 'brain2fa_settings', $missing ) == $original ? 'yes' : 'NO' ) . "\n";
}

$b2_failed = (int) $GLOBALS['b2_fails'];
echo $b2_failed ? $b2_failed . " FAILED\n" : "ALL PASSED\n";
if ( $b2_failed ) {
	WP_CLI::halt( 1 );
}
