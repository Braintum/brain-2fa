<?php
/**
 * Brain 2FA WP-CLI check: Code-method lockout warning.
 *
 * Counts users who could no longer sign in if a code method were turned off, as shown on the Authentication Methods tab. Restores brain2fa_settings, removes the users.
 *
 * Development sites only: it writes options and creates users while it runs.
 * Run all checks with `npm run test:wp`, or this one with
 * `php ../../../wp-cli.phar eval-file tests/wp-cli/method-impact.php` from the plugin directory.
 * Prints PASS/FAIL per check and exits 1 when any check fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
use Brain_2FA\Passkeys\PasskeyRepository as R;

$GLOBALS['b2_fails'] = 0;
function b2_check( $label, $cond ) { echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $GLOBALS['b2_fails']++; } }
function b2_settings( array $over ) { remove_all_filters( 'sanitize_option_brain2fa_settings' ); update_option( 'brain2fa_settings', array_merge( Brain_2FA\Utils::SETTINGS_DEFAULTS, array( 'enable_2fa' => true ), $over ) ); }
function b2_render() { $_GET['tab'] = 'methods'; ob_start(); Brain_2FA\Admin\Settings::instance()->render_settings_page(); return ob_get_clean(); }
function b2_attr( $html, $m ) { return preg_match( '/id="brain2fa_enable_' . $m . '"[^>]*data-brain2fa-blocked-users="(\d+)"/', $html, $x ) ? (int) $x[1] : -1; }

$missing  = new stdClass();
$original = get_option( 'brain2fa_settings', $missing );
$https    = function ( $u ) { return preg_replace( '#^http://#', 'https://', $u ); };
$users    = array();
$GLOBALS['title'] = 'Brain 2FA Settings';
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
wp_set_current_user( $admin[0]->ID );

try {
	$base_totp_all = R::count_code_method_users( 'totp', false );
	$base_totp_np  = R::count_code_method_users( 'totp', true );
	$base_email_np = R::count_code_method_users( 'email', true );
	$mk = function ( $method, $enabled, $passkey ) use ( &$users ) {
		$id = wp_insert_user( array( 'user_login' => 'b2fa_t_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'b2fa-' . wp_generate_password( 6, false, false ) . '@example.test' ) );
		$users[] = $id;
		update_user_meta( $id, 'brain2fa_method', $method );
		update_user_meta( $id, 'brain2fa_enabled', $enabled );
		if ( $passkey ) { R::add( $id, array( 'credential_id' => 'c-' . wp_generate_password( 12, false ), 'public_key' => 'x', 'sign_count' => 0, 'transports' => array(), 'aaguid' => '', 'backup_eligible' => false, 'backed_up' => false, 'name' => 'k' ) ); }
	};
	$mk( 'totp', 1, false );
	$mk( 'totp', 1, true );
	$mk( 'email', 1, false );
	$mk( 'totp', 0, false ); // inactive, must not count

	b2_check( 'count totp all +2', R::count_code_method_users( 'totp', false ) === $base_totp_all + 2 );
	b2_check( 'count totp without passkey +1', R::count_code_method_users( 'totp', true ) === $base_totp_np + 1 );
	b2_check( 'count email without passkey +1', R::count_code_method_users( 'email', true ) === $base_email_np + 1 );

	add_filter( 'home_url', $https );
	add_filter( 'site_url', $https );
	b2_settings( array( 'enable_passkeys' => true ) );
	$h = b2_render();
	b2_check( 'totp on: warning + blocked attr', b2_attr( $h, 'totp' ) === $base_totp_np + 1 && false !== strpos( $h, 'rely on the authenticator app and have no passkey' ) || false !== strpos( $h, 'relies on the authenticator app and has no passkey' ) );
	b2_check( 'email on: warning', false !== strpos( $h, 'email codes and has no passkey' ) || false !== strpos( $h, 'email codes and have no passkey' ) );

	b2_settings( array( 'enable_passkeys' => true, 'enable_totp' => false ) );
	$h = b2_render();
	b2_check( 'totp off: error notice', (bool) preg_match( '#notice-error inline">\s*<p>\d+ users? (is|are) set up with the authenticator app, which is turned off#', $h ) );

	b2_settings( array( 'enable_passkeys' => false ) );
	$h = b2_render();
	b2_check( 'passkeys off: passkey holder counts as blocked too', b2_attr( $h, 'totp' ) === $base_totp_all + 2 );
} finally {
	foreach ( $users as $id ) { R::delete_all_for_user( $id ); wp_delete_user( $id ); }
	remove_all_filters( 'sanitize_option_brain2fa_settings' );
	if ( $original === $missing ) { delete_option( 'brain2fa_settings' ); } else { update_option( 'brain2fa_settings', $original ); }
	echo 'Cleanup: users removed=' . ( ! array_filter( array_map( 'get_userdata', $users ) ) ? 'yes' : 'NO' ) . ', settings restored=' . ( get_option( 'brain2fa_settings', $missing ) == $original ? 'yes' : 'NO' ) . "\n";
}
$b2_failed = (int) $GLOBALS['b2_fails'];
echo $b2_failed ? $b2_failed . " FAILED\n" : "ALL PASSED\n";
if ( $b2_failed ) {
	WP_CLI::halt( 1 );
}
