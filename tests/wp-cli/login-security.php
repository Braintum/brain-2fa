<?php
/**
 * Brain 2FA WP-CLI check: Login Security page states.
 *
 * Renders the Login Security page for throwaway users in each state (unenrolled, TOTP setup, recovery codes, passkeys, passkey-only, restricted setup, method turned off). Suppresses mail, restores brain2fa_settings, removes the users. Optional argument: a directory to dump the rendered HTML into.
 *
 * Development sites only: it writes options and creates users while it runs.
 * Run all checks with `npm run test:wp`, or this one with
 * `php ../../../wp-cli.phar eval-file tests/wp-cli/login-security.php [html-dump-dir]` from the plugin directory.
 * Prints PASS/FAIL per check and exits 1 when any check fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use Brain_2FA\Admin\LoginSecurityPage;
use Brain_2FA\Passkeys\PasskeyRepository;

$dir      = $args[0] ?? '';
$missing  = new stdClass();
$original = get_option( 'brain2fa_settings', $missing );
$GLOBALS['b2_fails']    = 0;
$users    = array();
$https    = function ( $url ) { return preg_replace( '#^http://#', 'https://', $url ); };

add_filter( 'pre_wp_mail', '__return_true' );
set_error_handler( function ( $no, $str, $file, $line ) { if ( false !== strpos( $file, 'brain-2fa' ) ) { echo "PHP ISSUE: $str in $file:$line\n"; } return false; } );
$GLOBALS['title'] = 'Two-Factor Authentication';

function b2_check( $label, $cond ) {
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$GLOBALS['b2_fails'];
	}
}

function b2_settings( array $over ) {
	remove_all_filters( 'sanitize_option_brain2fa_settings' );
	update_option( 'brain2fa_settings', array_merge( Brain_2FA\Utils::SETTINGS_DEFAULTS, $over ) );
}

function b2_page( $label, array $post = array() ) {
	global $dir;
	foreach ( array( 'notices' => array(), 'open' => '' ) as $prop => $value ) {
		$r = new ReflectionProperty( LoginSecurityPage::class, $prop );
		$r->setAccessible( true );
		$r->setValue( null, $value );
	}
	$_POST = $_REQUEST = array();
	if ( $post ) {
		$_POST = $_REQUEST = array_merge( array( 'brain2fa_setup_nonce' => wp_create_nonce( 'brain2fa_setup_action' ) ), $post );
	}
	ob_start();
	LoginSecurityPage::render();
	$html = ob_get_clean();
	if ( $dir ) {
		file_put_contents( "$dir/ls-$label.html", $html );
	}
	return $html;
}

function b2_has( $html, $needle ) {
	return false !== strpos( $html, $needle );
}

function b2_details_open( $html, $summary ) {
	return (bool) preg_match( '#<details class="brain2fa-disclosure" open>\s*<summary>' . preg_quote( $summary, '#' ) . '#', $html );
}

try {
	$base = array( 'enable_2fa' => true, 'enable_totp' => true, 'enable_email' => true, 'enable_passkeys' => true, 'allow_passkey_only' => true, 'default_method' => 'totp' );
	b2_settings( $base );
	add_filter( 'home_url', $https );
	add_filter( 'site_url', $https );

	$pass = 'Test-pass-' . wp_generate_password( 8, false );
	$uid  = wp_create_user( 'b2fa_test_' . wp_generate_password( 6, false, false ), $pass, 'b2fa-test-' . wp_generate_password( 6, false, false ) . '@example.test' );
	$users[] = $uid;
	( new WP_User( $uid ) )->set_role( 'editor' );
	wp_set_current_user( $uid );

	// 1. Unenrolled.
	$h = b2_page( '1-unenrolled' );
	b2_check( 'unenrolled: summary Not set up', b2_has( $h, '>Not set up<' ) );
	b2_check( 'unenrolled: TOTP steps and QR', b2_has( $h, 'brain2fa-steps' ) && b2_has( $h, 'data:image' ) );
	b2_check( 'unenrolled: passkeys empty state + add form ids', b2_has( $h, 'You have not added any passkeys yet.' ) && b2_has( $h, 'id="brain2fa-passkey-add"' ) && b2_has( $h, 'id="brain2fa_passkey_password"' ) && b2_has( $h, 'id="brain2fa-passkey-message"' ) );
	b2_check( 'unenrolled: no recovery / password cards', ! b2_has( $h, 'id="brain2fa-recovery"' ) && ! b2_has( $h, 'id="brain2fa-password-sign-in"' ) );
	b2_check( 'card order: summary, passkeys, codes', strpos( $h, 'brain2fa-summary-title' ) < strpos( $h, 'id="brain2fa-passkeys"' ) && strpos( $h, 'id="brain2fa-passkeys"' ) < strpos( $h, 'id="brain2fa-codes"' ) );
	preg_match( '/name="brain2fa_secret" value="([A-Z2-7]+)"/', $h, $m );
	$secret = $m[1];

	// 2. Wrong code keeps the same secret.
	$h = b2_page( '2-wrong-code', array( 'brain2fa_action' => 'activate', 'brain2fa_secret' => $secret, 'brain2fa_code' => '000000' ) );
	b2_check( 'wrong code: error in codes card', (bool) preg_match( '#id="brain2fa-codes".*notice-error.*Invalid verification code#s', $h ) );
	b2_check( 'wrong code: same secret kept', b2_has( $h, 'name="brain2fa_secret" value="' . $secret . '"' ) );
	$h = b2_page( '2b-forged-secret', array( 'brain2fa_action' => 'activate', 'brain2fa_secret' => 'not base32!', 'brain2fa_code' => '000000' ) );
	b2_check( 'invalid posted secret is not reused', ! b2_has( $h, 'value="not base32!"' ) );

	// 3. Correct code activates and shows recovery codes once.
	$h = b2_page( '3-activate', array( 'brain2fa_action' => 'activate', 'brain2fa_secret' => $secret, 'brain2fa_code' => \OTPHP\TOTP::createFromSecret( $secret, new Brain_2FA\TwoFactor\SystemClock() )->now() ) );
	b2_check( 'activate: success notice', b2_has( $h, 'Two-factor authentication is active. Save your recovery codes below.' ) );
	b2_check( 'activate: fresh codes + download', 10 === substr_count( $h, '<li><code>' ) && b2_has( $h, 'id="brain2fa-download-codes"' ) );
	b2_check( 'activate: summary Protected', b2_has( $h, '>Protected<' ) );
	$h = b2_page( '4-after' );
	b2_check( 'reload: codes not shown again, count shown', ! b2_has( $h, '<li><code>' ) && b2_has( $h, 'You have 10 unused recovery codes.' ) );
	b2_check( 'reload: deactivate + regenerate collapsed', ! b2_details_open( $h, 'Turn off this method' ) && ! b2_details_open( $h, 'Generate new codes' ) && b2_has( $h, 'Turn off this method' ) );

	// 5. Wrong password keeps the form open with the error beside it.
	$h = b2_page( '5-deactivate-wrong', array( 'brain2fa_action' => 'deactivate', 'brain2fa_confirm_password' => 'wrong' ) );
	b2_check( 'deactivate wrong password: details open with error', b2_details_open( $h, 'Turn off this method' ) && (bool) preg_match( '#Turn off this method</summary>\s*<div class="brain2fa-disclosure-body">\s*<div class="notice notice-error#', $h ) );
	b2_check( 'deactivate wrong password: still active', (bool) get_user_meta( $uid, 'brain2fa_enabled', true ) );

	// 6. Regenerate.
	$h = b2_page( '6-regenerate', array( 'brain2fa_action' => 'regenerate_recovery_codes', 'brain2fa_confirm_password' => $pass ) );
	b2_check( 'regenerate: new codes shown', 10 === substr_count( $h, '<li><code>' ) && b2_has( $h, 'New recovery codes were generated' ) );
	$h = b2_page( '6b-regenerate-wrong', array( 'brain2fa_action' => 'regenerate_recovery_codes', 'brain2fa_confirm_password' => 'wrong' ) );
	b2_check( 'regenerate wrong password: details open', b2_details_open( $h, 'Generate new codes' ) && ! b2_has( $h, '<li><code>' ) );

	// 7. Passkey present: list + password card.
	$pk = PasskeyRepository::add( $uid, array( 'credential_id' => 'test-' . wp_generate_password( 12, false ), 'public_key' => 'x', 'sign_count' => 0, 'transports' => array(), 'aaguid' => '', 'backup_eligible' => true, 'backed_up' => true, 'name' => 'Test key' ) );
	$h = b2_page( '7-passkey' );
	b2_check( 'passkey: listed with synced badge', b2_has( $h, 'Test key' ) && b2_has( $h, 'Synced across your devices' ) );
	b2_check( 'passkey: password sign-in card shown, second-passkey tip', b2_has( $h, 'id="brain2fa-password-sign-in"' ) && b2_has( $h, 'add a second passkey' ) );
	b2_check( 'passkey: summary lists both factors', b2_has( $h, 'a code from your authenticator app or a passkey' ) );
	$h = b2_page( '7b-remove-wrong', array( 'brain2fa_action' => 'passkey_remove', 'brain2fa_passkey_id' => $pk, 'brain2fa_confirm_password' => 'wrong' ) );
	b2_check( 'passkey remove wrong password: that passkey open with error', b2_details_open( $h, 'Rename or remove' ) && (bool) preg_match( '#Rename or remove</summary>\s*<div class="brain2fa-disclosure-body">\s*<div class="notice notice-error#', $h ) );
	$h = b2_page( '7c-rename', array( 'brain2fa_action' => 'passkey_rename', 'brain2fa_passkey_id' => $pk, 'brain2fa_passkey_name' => 'Renamed key' ) );
	b2_check( 'passkey rename: success at card level', b2_has( $h, 'The passkey was renamed.' ) && b2_has( $h, 'Renamed key' ) );

	// 8. Passkey-only.
	$h = b2_page( '8-passkey-only', array( 'brain2fa_action' => 'passkey_only', 'brain2fa_passkey_only' => '1', 'brain2fa_confirm_password' => $pass ) );
	b2_check( 'passkey-only: summary + recovery note', b2_has( $h, '>Passkey only<' ) && b2_has( $h, 'recovery codes cannot be used' ) && b2_has( $h, 'Turn password sign-in back on' ) );
	b2_page( '8b-back-on', array( 'brain2fa_action' => 'passkey_only', 'brain2fa_passkey_only' => '0', 'brain2fa_confirm_password' => $pass ) );
	b2_check( 'passkey-only: turned back off', ! Brain_2FA\Utils::is_passkey_only( new WP_User( $uid ) ) );

	// 9. Site turns the authenticator app off.
	b2_settings( array_merge( $base, array( 'enable_totp' => false ) ) );
	$h = b2_page( '9-totp-off-with-passkey' );
	b2_check( 'totp off + passkey: Protected via passkey, method flagged', b2_has( $h, '>Protected<' ) && b2_has( $h, 'Signing in with your password also needs a passkey.' ) && b2_has( $h, 'Turned off by the site' ) );
	PasskeyRepository::delete_all_for_user( $uid );
	$h = b2_page( '9b-totp-off-no-passkey' );
	b2_check( 'totp off, no passkey: Action needed', b2_has( $h, '>Action needed<' ) );

	// 10. Passkeys need HTTPS.
	remove_filter( 'home_url', $https );
	remove_filter( 'site_url', $https );
	$h = b2_page( '10-no-https' );
	b2_check( 'no HTTPS: passkeys card explains, no add form', b2_has( $h, 'Passkeys need a secure (HTTPS) connection' ) && ! b2_has( $h, 'id="brain2fa-passkey-add"' ) );
	b2_check( 'no HTTPS: summary row', b2_has( $h, 'Unavailable: this site needs HTTPS' ) );

	// 11. Restricted enrollment with email as the default method.
	b2_settings( array_merge( $base, array( 'enable_passkeys' => false, 'default_method' => 'email', 'force_2fa_roles' => array( 'author' ), 'grace_period_days' => 0 ) ) );
	$uid2    = wp_create_user( 'b2fa_test_' . wp_generate_password( 6, false, false ), $pass, 'b2fa-test-' . wp_generate_password( 6, false, false ) . '@example.test' );
	$users[] = $uid2;
	( new WP_User( $uid2 ) )->set_role( 'author' );
	wp_set_current_user( $uid2 );
	$h = b2_page( '11-restricted' );
	b2_check( 'restricted: prominent notice with setup link', b2_has( $h, 'brain2fa-enroll-notice' ) && b2_has( $h, 'href="#brain2fa-codes"' ) && ! b2_has( $h, 'href="#brain2fa-passkeys"' ) );
	b2_check( 'restricted: summary Setup required', b2_has( $h, '>Setup required<' ) );
	b2_check( 'restricted: email step 1 current', b2_has( $h, 'brain2fa-step-current">' . "\n\t\t\t\t" . '<span class="brain2fa-step-title">Send a verification code' ) || (bool) preg_match( '#brain2fa-step-current">\s*<span class="brain2fa-step-title">Send a verification code#', $h ) );
	$h = b2_page( '11b-email-sent', array( 'brain2fa_action' => 'activate' ) );
	b2_check( 'email: code sent, step 2 form', b2_has( $h, 'A verification code was sent to' ) && b2_has( $h, 'id="brain2fa_email_setup_code"' ) && b2_has( $h, 'Send a new code' ) );

	// 12. Site-wide 2FA off.
	b2_settings( array_merge( $base, array( 'enable_2fa' => false ) ) );
	$h = b2_page( '12-site-off' );
	b2_check( 'site 2FA off: Not required', b2_has( $h, '>Not required<' ) );

	// 13. No code methods at all.
	b2_settings( array_merge( $base, array( 'enable_totp' => false, 'enable_email' => false, 'enable_passkeys' => false ) ) );
	$h = b2_page( '13-no-methods' );
	b2_check( 'no methods: codes card says not offered', b2_has( $h, 'This site does not offer two-factor codes right now.' ) && ! b2_has( $h, 'Unable to generate QR code' ) );
} finally {
	foreach ( $users as $id ) {
		PasskeyRepository::delete_all_for_user( $id );
		wp_delete_user( $id );
	}
	remove_all_filters( 'sanitize_option_brain2fa_settings' );
	if ( $original === $missing ) {
		delete_option( 'brain2fa_settings' );
	} else {
		update_option( 'brain2fa_settings', $original );
	}
	echo 'Cleanup: users removed=' . ( $users && ! array_filter( array_map( 'get_userdata', $users ) ) ? 'yes' : 'NO' ) . ', settings restored=' . ( get_option( 'brain2fa_settings', $missing ) == $original ? 'yes' : 'NO' ) . "\n";
}

$b2_failed = (int) $GLOBALS['b2_fails'];
echo $b2_failed ? $b2_failed . " FAILED\n" : "ALL PASSED\n";
if ( $b2_failed ) {
	WP_CLI::halt( 1 );
}
