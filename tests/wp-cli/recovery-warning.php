<?php
/**
 * Brain 2FA WP-CLI check: Low recovery code warning.
 *
 * Who is warned (authenticator app users with password sign-in, at 2 codes or fewer) and who is not (3+ codes,
 * email codes, not enrolled, signed out, 2FA or the authenticator app turned off, effective passkey-only), the
 * message and severity, the admin notice (any site screen except Login Security, only about the current user) and
 * the WooCommerce dashboard notice, the threshold filter, and that rendering changes nothing.
 * Restores brain2fa_settings and removes the test users.
 *
 * Development sites only: it writes options and creates users while it runs.
 * Run all checks with `npm run test:wp`, or this one with
 * `php ../../../wp-cli.phar eval-file tests/wp-cli/recovery-warning.php` from the plugin directory.
 * Prints PASS/FAIL per check and exits 1 when any check fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';

use Brain_2FA\Auth\RecoveryCodeWarning as W;
use Brain_2FA\Passkeys\PasskeyRepository;
use Brain_2FA\Utils;

global $wpdb;
$missing  = new stdClass();
$original = get_option( 'brain2fa_settings', $missing );
$https    = function ( $u ) { return preg_replace( '#^http://#', 'https://', $u ); };
$GLOBALS['b2_users'] = array();
$GLOBALS['b2_fails'] = 0;
$GLOBALS['b2_mail']  = 0;
set_error_handler( function ( $no, $str, $file, $line ) { if ( false !== strpos( $file, 'brain-2fa' ) ) { echo "PHP ISSUE: $str in $file:$line\n"; } return false; } );

function b2_check( $label, $cond ) { echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $GLOBALS['b2_fails']++; } }
function b2_settings( array $over ) { remove_all_filters( 'sanitize_option_brain2fa_settings' ); update_option( 'brain2fa_settings', array_merge( Utils::SETTINGS_DEFAULTS, array( 'enable_2fa' => true ), $over ) ); }
function b2_codes( $id, $n ) { update_user_meta( $id, 'brain2fa_recovery_codes', array_map( 'wp_hash_password', array_map( 'strval', range( 1, max( 1, $n ) ) ) ) ); if ( 0 === $n ) { update_user_meta( $id, 'brain2fa_recovery_codes', array() ); } }
function b2_remaining( $id ) { wp_cache_delete( $id, 'user_meta' ); return W::get_remaining( get_userdata( $id ) ); }

/**
 * Create a test user.
 *
 * @param string|null $method Code method set up, or null.
 * @param int         $codes  Recovery codes left.
 * @return int User ID.
 */
function b2_user( $method = 'totp', $codes = 2 ) {
	$id = wp_insert_user( array( 'user_login' => 'b2fa_w_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'b2fa-w-' . wp_generate_password( 6, false, false ) . '@example.test', 'role' => 'editor' ) );
	$GLOBALS['b2_users'][] = $id;
	if ( $method ) {
		update_user_meta( $id, 'brain2fa_method', $method );
		update_user_meta( $id, 'brain2fa_enabled', 1 );
		update_user_meta( $id, 'brain2fa_secret', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' );
		b2_codes( $id, $codes );
	}
	return $id;
}

/**
 * Render a notice for a user on a screen.
 *
 * @param int    $id     Current user ID (0 for signed out).
 * @param string $screen Admin screen ID, or 'account' for the WooCommerce dashboard.
 * @param array  $get    Request query.
 * @return string HTML.
 */
function b2_render( $id, $screen = 'dashboard', array $get = array() ) {
	wp_set_current_user( $id );
	$_GET = $get;
	ob_start();
	if ( 'account' === $screen ) {
		W::render_account_notice();
	} else {
		set_current_screen( $screen );
		$GLOBALS['pagenow'] = isset( $get['page'] ) ? 'admin.php' : 'index.php';
		W::render_admin_notice();
	}
	$_GET = array();
	return ob_get_clean();
}

try {
	add_filter( 'pre_wp_mail', function () { $GLOBALS['b2_mail']++; return true; } );
	b2_settings( array() );

	// Who is warned.
	$u = b2_user( 'totp', 2 );
	b2_check( 'authenticator app, 2 codes: warned', 2 === b2_remaining( $u ) );
	b2_codes( $u, 1 );
	b2_check( 'authenticator app, 1 code: warned', 1 === b2_remaining( $u ) );
	b2_codes( $u, 0 );
	b2_check( 'authenticator app, no codes: warned', 0 === b2_remaining( $u ) );
	delete_user_meta( $u, 'brain2fa_recovery_codes' );
	b2_check( 'authenticator app, codes meta missing: warned as none left', 0 === b2_remaining( $u ) );
	b2_codes( $u, 3 );
	b2_check( 'authenticator app, 3 codes: not warned', null === b2_remaining( $u ) );

	$email = b2_user( 'email', 0 );
	b2_check( 'email codes: not warned', null === b2_remaining( $email ) );
	$none = b2_user( null );
	b2_check( 'not enrolled: not warned', null === b2_remaining( $none ) );
	$off = b2_user( 'totp', 0 );
	update_user_meta( $off, 'brain2fa_enabled', '' );
	b2_check( 'authenticator app set up but turned off for the user: not warned', null === b2_remaining( $off ) );

	$low = b2_user( 'totp', 1 );
	b2_settings( array( 'enable_2fa' => false ) );
	b2_check( '2FA off for the site: not warned', null === b2_remaining( $low ) );
	b2_settings( array( 'enable_totp' => false ) );
	b2_check( 'authenticator app off for the site: not warned', null === b2_remaining( $low ) );

	// Passkeys.
	$pk = b2_user( 'totp', 1 );
	PasskeyRepository::add( $pk, array( 'credential_id' => 'w-' . wp_generate_password( 12, false ), 'public_key' => 'x', 'sign_count' => 0, 'transports' => array(), 'aaguid' => '', 'backup_eligible' => false, 'backed_up' => false, 'name' => 'k' ) );
	add_filter( 'home_url', $https );
	add_filter( 'site_url', $https );
	b2_settings( array( 'enable_passkeys' => true, 'allow_passkey_only' => true ) );
	b2_check( 'authenticator app and a passkey, password sign-in on: warned', 1 === b2_remaining( $pk ) );
	update_user_meta( $pk, Utils::PASSKEY_ONLY_META_KEY, 1 );
	b2_check( 'passkey-only account: not warned (recovery codes cannot be used)', null === b2_remaining( $pk ) );
	remove_filter( 'home_url', $https );
	remove_filter( 'site_url', $https );
	b2_check( 'passkey-only flag while passkeys are unusable: password works again, warned', 1 === b2_remaining( $pk ) );
	b2_settings( array( 'enable_passkeys' => true ) );
	b2_check( 'passkey-only flag while passkey-only is not allowed: warned', 1 === b2_remaining( $pk ) );
	b2_settings( array() );

	// Messages.
	b2_check( 'message: plural', 'You have 2 recovery codes left. Generate new codes before you run out.' === W::get_message( 2 ) );
	b2_check( 'message: singular', 'You have 1 recovery code left. Generate new codes before you run out.' === W::get_message( 1 ) );
	b2_check( 'message: none left, without claiming a lockout', 'You have no recovery codes left. Generate new codes while you can still access your account.' === W::get_message( 0 ) );
	b2_check( 'link: recovery codes on Login Security', admin_url( 'admin.php?page=brain-2fa-login-security#brain2fa-recovery' ) === W::get_url() );

	// Admin notice.
	b2_codes( $u, 2 );
	$h = b2_render( $u );
	b2_check( 'admin: warning on the Dashboard with the link', false !== strpos( $h, 'notice notice-warning brain2fa-recovery-warning' ) && false !== strpos( $h, 'You have 2 recovery codes left.' ) && false !== strpos( $h, 'page=brain-2fa-login-security#brain2fa-recovery">Manage recovery codes</a>' ) );
	b2_check( 'admin: also on other screens', false !== strpos( b2_render( $u, 'edit-post' ), 'brain2fa-recovery-warning' ) );
	b2_check( 'admin: not on Login Security (its recovery card says it)', '' === b2_render( $u, 'toplevel_page_brain-2fa-login-security', array( 'page' => 'brain-2fa-login-security' ) ) );
	b2_codes( $u, 0 );
	b2_check( 'admin: none left is an error notice', false !== strpos( b2_render( $u ), 'notice notice-error brain2fa-recovery-warning' ) );
	b2_check( 'admin: nothing when signed out', '' === b2_render( 0 ) );
	b2_codes( $u, 10 );
	$viewed = b2_user( 'totp', 1 );
	b2_check( 'admin: another user\'s profile does not show their count', '' === b2_render( $u, 'user-edit', array( 'user_id' => (string) $viewed ) ) );
	b2_check( 'admin: an ineligible user sees nothing', '' === b2_render( $email ) && '' === b2_render( $none ) );

	// WooCommerce dashboard.
	b2_codes( $u, 1 );
	$h = b2_render( $u, 'account' );
	b2_check( 'account: info notice with the link', false !== strpos( $h, 'class="woocommerce-info brain2fa-recovery-warning" role="status"' ) && false !== strpos( $h, 'You have 1 recovery code left.' ) && false !== strpos( $h, '#brain2fa-recovery">Manage recovery codes</a></div>' ) );
	b2_codes( $u, 0 );
	b2_check( 'account: none left is an error notice', false !== strpos( b2_render( $u, 'account' ), 'class="woocommerce-error brain2fa-recovery-warning" role="alert"' ) );
	b2_check( 'account: nothing for ineligible or signed-out users', '' === b2_render( $email, 'account' ) && '' === b2_render( 0, 'account' ) );
	wp_set_current_user( $u );
	ob_start();
	do_action( 'woocommerce_account_dashboard' );
	$dash = ob_get_clean();
	$status_at  = strpos( $dash, 'brain2fa-account-status' );
	$warning_at = strpos( $dash, 'brain2fa-recovery-warning' );
	b2_check( 'account: shown after the existing 2FA status line', false !== $status_at && false !== $warning_at && $status_at < $warning_at );

	// Threshold filter.
	b2_codes( $u, 2 );
	$zero = function () { return 0; };
	add_filter( 'brain2fa_recovery_codes_warning_threshold', $zero );
	b2_check( 'filter: threshold 0 warns only when none are left', null === b2_remaining( $u ) );
	remove_filter( 'brain2fa_recovery_codes_warning_threshold', $zero );
	$never = function () { return -1; };
	b2_codes( $u, 0 );
	add_filter( 'brain2fa_recovery_codes_warning_threshold', $never );
	b2_check( 'filter: a negative threshold turns the warning off', null === b2_remaining( $u ) );
	remove_filter( 'brain2fa_recovery_codes_warning_threshold', $never );

	// Read-only.
	$required = b2_user( 'totp', 1 );
	b2_settings( array( 'force_2fa_roles' => array( 'editor' ) ) );
	$meta_sql = $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id = %d ORDER BY umeta_id", $required );
	$before   = $wpdb->get_results( $meta_sql, ARRAY_A );
	$mail     = $GLOBALS['b2_mail'];
	b2_render( $required );
	b2_render( $required, 'account' );
	b2_check( 'rendering changes no user data and sends no email', $before === $wpdb->get_results( $meta_sql, ARRAY_A ) && $mail === $GLOBALS['b2_mail'] );
	b2_settings( array() );

	$codes = brain_2fa()->manager->get_method( 'totp' )->generate_recovery_codes( get_userdata( $u ) );
	b2_check( 'new codes generated: warning gone', 10 === count( $codes ) && null === b2_remaining( $u ) && '' === b2_render( $u ) );
} finally {
	restore_error_handler();
	$_GET = array();
	remove_filter( 'home_url', $https );
	remove_filter( 'site_url', $https );
	wp_set_current_user( 0 );
	foreach ( $GLOBALS['b2_users'] as $id ) { PasskeyRepository::delete_all_for_user( $id ); wp_delete_user( $id ); }
	remove_all_filters( 'sanitize_option_brain2fa_settings' );
	if ( $original === $missing ) { delete_option( 'brain2fa_settings' ); } else { update_option( 'brain2fa_settings', $original ); }
	echo 'Cleanup: users removed=' . ( ! array_filter( array_map( 'get_userdata', $GLOBALS['b2_users'] ) ) ? 'yes' : 'NO' ) . ', settings restored=' . ( get_option( 'brain2fa_settings', $missing ) == $original ? 'yes' : 'NO' ) . "\n";
}

$b2_failed = (int) $GLOBALS['b2_fails'];
echo $b2_failed ? "$b2_failed FAILED\n" : "ALL PASSED\n";
if ( $b2_failed ) {
	WP_CLI::halt( 1 );
}
