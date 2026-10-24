<?php
/**
 * Brain 2FA WP-CLI check: Trusted devices.
 *
 * Storage (upgrade of 1.1.0 entries and cookies, IDs, labels, expiry, the 10-device cap, last use), revocation of one
 * or all devices, a revocation that a waiting last-use update must not undo (tests/wp-cli/workers/trusted-device.php),
 * and the Login Security card and its forget actions, including a forged device ID. Restores brain2fa_settings and
 * removes the test users.
 *
 * Development sites only: it writes options and creates users while it runs.
 * Run all checks with `npm run test:wp`, or this one with
 * `php ../../../wp-cli.phar eval-file tests/wp-cli/trusted-devices.php` from the plugin directory.
 * Prints PASS/FAIL per check and exits 1 when any check fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use Brain_2FA\Admin\LoginSecurityPage;
use Brain_2FA\Auth\TrustedDeviceRepository as R;
use Brain_2FA\Auth\UserLock;
use Brain_2FA\Utils;

$missing  = new stdClass();
$original = get_option( 'brain2fa_settings', $missing );
$users    = array();
$GLOBALS['b2_fails'] = 0;
$GLOBALS['title']    = 'Two-Factor Authentication';
set_error_handler( function ( $no, $str, $file, $line ) { if ( false !== strpos( $file, 'brain-2fa' ) ) { echo "PHP ISSUE: $str in $file:$line\n"; } return false; } );

function b2_check( $label, $cond ) { echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $GLOBALS['b2_fails']++; } }
function b2_settings( array $over ) { remove_all_filters( 'sanitize_option_brain2fa_settings' ); update_option( 'brain2fa_settings', array_merge( Utils::SETTINGS_DEFAULTS, array( 'enable_2fa' => true ), $over ) ); }
function b2_meta( $id ) { wp_cache_delete( $id, 'user_meta' ); return get_user_meta( $id, R::META_KEY, true ); }
function b2_cookie( $id, $value ) { if ( null === $value ) { unset( $_COOKIE[ 'brain2fa_remember_' . $id ] ); } else { $_COOKIE[ 'brain2fa_remember_' . $id ] = $value; } }
function b2_reset_page() { foreach ( array( 'notices' => array(), 'open' => '' ) as $prop => $value ) { $r = new ReflectionProperty( LoginSecurityPage::class, $prop ); $r->setAccessible( true ); $r->setValue( null, $value ); } }

/**
 * Submit a Login Security action through the load hook and render the page, as WordPress does.
 *
 * @param array $post POST fields; empty to only render.
 * @return string Page HTML.
 */
function b2_page( array $post = array() ) {
	b2_reset_page();
	$_POST = $_REQUEST = $post ? array_merge( array( 'brain2fa_setup_nonce' => wp_create_nonce( 'brain2fa_setup_action' ) ), $post ) : array();
	LoginSecurityPage::handle_device_actions();
	ob_start();
	LoginSecurityPage::render();
	$_POST = $_REQUEST = array();
	return ob_get_clean();
}

try {
	b2_settings( array( 'remember_device' => true ) );
	$mk = function () use ( &$users ) {
		$id      = wp_insert_user( array( 'user_login' => 'b2fa_d_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'b2fa-d-' . wp_generate_password( 6, false, false ) . '@example.test' ) );
		$users[] = $id;
		update_user_meta( $id, 'brain2fa_method', 'totp' );
		update_user_meta( $id, 'brain2fa_secret', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' );
		update_user_meta( $id, 'brain2fa_enabled', 1 );
		return $id;
	};
	$uid   = $mk();
	$other = $mk();
	$user  = get_userdata( $uid );

	// 1.1.0 entries: token hash and expiry only. An expired and a malformed entry are dropped.
	$legacy_token = wp_generate_password( 64, false, false );
	update_user_meta( $uid, R::META_KEY, array( array( 'token' => wp_hash_password( wp_generate_password( 64, false, false ) ), 'expires_at' => time() - 10 ), 'junk', array( 'token' => wp_hash_password( $legacy_token ), 'expires_at' => time() + DAY_IN_SECONDS ) ) );
	$devices = R::get_all( $uid );
	b2_check( 'legacy: one active entry kept, with an ID and unknown dates', 1 === count( $devices ) && 1 === preg_match( '/^[a-f0-9]{16}$/', $devices[0]['id'] ) && 0 === $devices[0]['created_at'] && 0 === $devices[0]['last_used_at'] );
	b2_check( 'legacy: upgraded list stored, ID stable', $devices === b2_meta( $uid ) && R::get_all( $uid )[0]['id'] === $devices[0]['id'] );
	b2_cookie( $uid, $legacy_token );
	b2_check( 'legacy: 1.1.0 cookie still trusted', Utils::is_remembered_device( $user ) && Utils::get_current_device_id( $uid ) === $devices[0]['id'] );
	$legacy_id = $devices[0]['id'];

	// New entries.
	$value = R::add( $uid, time() + DAY_IN_SECONDS, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0' );
	$id    = strtok( $value, '.' );
	$entry = wp_list_filter( R::get_all( $uid ), array( 'id' => $id ) );
	$entry = reset( $entry );
	b2_check( 'add: cookie value is id.token', 1 === preg_match( '/^[a-f0-9]{16}\.[A-Za-z0-9]{64}$/', $value ) );
	b2_check( 'add: token stored only as a hash', $entry && false === strpos( maybe_serialize( b2_meta( $uid ) ), substr( $value, 17 ) ) );
	b2_check( 'add: created now, not used yet', $entry && abs( $entry['created_at'] - time() ) < 5 && 0 === $entry['last_used_at'] );
	b2_check( 'add: coarse label, no user agent stored', $entry && 'Firefox on Windows' === R::get_label( $entry ) && false === strpos( maybe_serialize( b2_meta( $uid ) ), 'Gecko' ) );
	b2_check( 'label: browser and system, or a generic name', 'Edge on Windows' === R::get_label( array( 'browser' => 'Edge', 'os' => 'Windows' ) ) && 'Trusted browser' === R::get_label( array() ) );
	$iphone = R::add( $other, time() + DAY_IN_SECONDS, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/130.0 Mobile/15E148 Safari/604.1' );
	$label  = R::get_label( R::get_all( $other )[0] );
	b2_check( 'add: Chrome on iOS recognised', 'Chrome on iOS' === $label );

	b2_cookie( $uid, $value );
	b2_check( 'find: own cookie matches', Utils::get_current_device_id( $uid ) === $id );
	b2_check( 'find: preflight check records no use', Utils::is_remembered_device( $user ) && 0 === wp_list_filter( R::get_all( $uid ), array( 'id' => $id ) )[1]['last_used_at'] );
	b2_check( 'find: sign-in records the use', Utils::is_remembered_device( $user, true ) && wp_list_filter( R::get_all( $uid ), array( 'id' => $id ) )[1]['last_used_at'] > 0 );
	b2_cookie( $uid, $id . '.' . str_repeat( 'a', 64 ) );
	b2_check( 'find: wrong token rejected', '' === Utils::get_current_device_id( $uid ) && ! Utils::is_remembered_device( $user ) );
	b2_cookie( $uid, $legacy_id . '.' . substr( $value, 17 ) );
	b2_check( 'find: token under another entry ID rejected', '' === Utils::get_current_device_id( $uid ) );
	b2_cookie( $uid, $iphone );
	b2_check( 'find: another user\'s cookie rejected', '' === Utils::get_current_device_id( $uid ) );
	b2_cookie( $uid, '<script>' );
	b2_check( 'find: malformed cookie rejected', '' === Utils::get_current_device_id( $uid ) );

	b2_settings( array( 'remember_device' => false ) );
	b2_cookie( $uid, $value );
	b2_check( 'setting off: not trusted, but still identified for the list', ! Utils::is_remembered_device( $user ) && Utils::get_current_device_id( $uid ) === $id );
	b2_settings( array( 'remember_device' => true ) );

	// Expiry and the cap.
	$stored                    = b2_meta( $uid );
	$stored[0]['expires_at']   = time() - 1;
	update_user_meta( $uid, R::META_KEY, $stored );
	b2_check( 'expired entry dropped', 1 === count( R::get_all( $uid ) ) && R::get_all( $uid )[0]['id'] === $id );
	for ( $i = 0; $i < 10; $i++ ) {
		R::add( $uid, time() + DAY_IN_SECONDS, '' );
	}
	$all = R::get_all( $uid );
	b2_check( 'cap: 10 devices kept, oldest dropped', 10 === count( $all ) && ! wp_list_filter( $all, array( 'id' => $id ) ) );

	// Revocation.
	$other_id = R::get_all( $other )[0]['id'];
	b2_check( 'remove: another user\'s device ID matches nothing', ! R::remove( $uid, $other_id ) && 1 === count( R::get_all( $other ) ) );
	b2_check( 'remove: one device', R::remove( $uid, $all[0]['id'] ) && 9 === count( R::get_all( $uid ) ) );
	Utils::forget_remembered_devices( $uid );
	b2_check( 'remove all: list deleted', '' === b2_meta( $uid ) && array() === R::get_all( $uid ) );

	// A sign-in that read the device before it was revoked must not write it back.
	$value = R::add( $uid, time() + DAY_IN_SECONDS, '' );
	$keep  = R::add( $uid, time() + DAY_IN_SECONDS, '' );
	UserLock::acquire( $uid );
	$cmd    = array( PHP_BINARY, realpath( $_SERVER['argv'][0] ), 'eval-file', __DIR__ . '/workers/trusted-device.php', (string) $uid, $value, '--path=' . ABSPATH );
	$worker = proc_open( implode( ' ', array_map( 'escapeshellarg', $cmd ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
	sleep( 4 ); // Let the worker find the device and wait for the lock.
	R::remove( $uid, strtok( $value, '.' ) );
	UserLock::release( $uid );
	$out = (string) stream_get_contents( $pipes[1] );
	proc_close( $worker );
	$left = R::get_all( $uid );
	b2_check( 'concurrency: worker ran', false !== strpos( $out, 'RESULT:' ) );
	b2_check( 'concurrency: revoked device stays revoked', 1 === count( $left ) && $left[0]['id'] === strtok( $keep, '.' ) && 0 === $left[0]['last_used_at'] );

	// Password changes revoke trust; the unchanged-password rehash WordPress does at login keeps it.
	wp_set_password( 'B2fa-Known-1!', $other );
	R::add( $other, time() + DAY_IN_SECONDS, '' );
	wp_set_password( 'B2fa-Known-1!', $other );
	b2_check( 'password: rehash of the same password keeps devices', 1 === count( R::get_all( $other ) ) );
	wp_set_password( 'B2fa-Known-2!', $other );
	b2_check( 'password: reset revokes devices', array() === R::get_all( $other ) );
	R::add( $other, time() + DAY_IN_SECONDS, '' );
	wp_update_user( array( 'ID' => $other, 'user_pass' => 'B2fa-Known-3!' ) );
	b2_check( 'password: profile change revokes devices', array() === R::get_all( $other ) );
	$other_id = R::get_all( $other )[0]['id'] ?? '';
	$other_id = '' === $other_id ? strtok( R::add( $other, time() + DAY_IN_SECONDS, '' ), '.' ) : $other_id;

	// Login Security card.
	wp_set_current_user( $uid );
	$keep_id = strtok( $keep, '.' );
	b2_cookie( $uid, $keep );
	$h = b2_page();
	b2_check( 'card: listed with this browser marked', false !== strpos( $h, 'id="brain2fa-devices"' ) && false !== strpos( $h, 'This browser' ) && false !== strpos( $h, 'value="' . $keep_id . '"' ) );
	b2_check( 'card: no token or hash in the page', false === strpos( $h, substr( $keep, 17 ) ) && false === strpos( $h, b2_meta( $uid )[0]['token'] ) );
	b2_check( 'card: forget all hidden with one device', false === strpos( $h, 'forget_all_devices' ) );
	b2_cookie( $uid, null );
	b2_check( 'card: no marker without a cookie', false === strpos( b2_page(), 'This browser' ) );

	$h = b2_page( array( 'brain2fa_action' => 'forget_device', 'brain2fa_device_id' => $other_id ) );
	b2_check( 'action: forged device ID refused, other user untouched', false !== strpos( $h, 'That device is not in your list' ) && 1 === count( R::get_all( $other ) ) && 1 === count( R::get_all( $uid ) ) );
	$h = b2_page( array( 'brain2fa_action' => 'forget_device', 'brain2fa_device_id' => $keep_id ) );
	b2_check( 'action: forget one', false !== strpos( $h, 'The device was forgotten' ) && array() === R::get_all( $uid ) );
	b2_check( 'card: empty state', false !== strpos( $h, 'No browsers are trusted for your account' ) );

	R::add( $uid, time() + DAY_IN_SECONDS, '' );
	R::add( $uid, time() + DAY_IN_SECONDS, '' );
	$h = b2_page();
	b2_check( 'card: forget all offered with two devices', false !== strpos( $h, 'forget_all_devices' ) );
	$h = b2_page( array( 'brain2fa_action' => 'forget_all_devices' ) );
	b2_check( 'action: forget all', false !== strpos( $h, 'All trusted devices were forgotten' ) && array() === R::get_all( $uid ) );

	b2_settings( array( 'remember_device' => false ) );
	b2_check( 'card: hidden when the setting is off and nothing is stored', false === strpos( b2_page(), 'id="brain2fa-devices"' ) );
	R::add( $uid, time() + DAY_IN_SECONDS, '' );
	$h = b2_page();
	b2_check( 'card: shown with a notice when the setting is off but devices remain', false !== strpos( $h, 'id="brain2fa-devices"' ) && false !== strpos( $h, 'no longer lets browsers skip the second step' ) );

	// The page's other actions still run, and a missing nonce is refused.
	b2_reset_page();
	$_POST = $_REQUEST = array( 'brain2fa_action' => 'forget_all_devices', 'brain2fa_setup_nonce' => 'bad' );
	add_filter( 'wp_die_handler', function () { return function () { throw new RuntimeException( 'died' ); }; } );
	try {
		LoginSecurityPage::handle_device_actions();
		$died = false;
	} catch ( RuntimeException $e ) {
		$died = true;
	}
	$_POST = $_REQUEST = array();
	b2_check( 'action: bad nonce refused, nothing removed', $died && 1 === count( R::get_all( $uid ) ) );
} finally {
	foreach ( $users as $id ) {
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

if ( $GLOBALS['b2_fails'] ) {
	echo $GLOBALS['b2_fails'] . " FAILED\n";
	WP_CLI::halt( 1 );
}
echo "ALL PASSED\n";
