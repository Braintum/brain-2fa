<?php
/**
 * Brain 2FA WP-CLI check: Code consumption under concurrency, and hashed email codes.
 *
 * Submits the same TOTP code, recovery code or email code from several processes at once (tests/wp-cli/workers/verify-code.php)
 * and checks that exactly one is accepted, including the first TOTP use; that two different recovery codes used together are
 * both removed; that email codes are stored only as hashes; that a pending plaintext code from 1.1.0 is discarded and replaced;
 * and that the per-user lock fails closed while busy and recovers from an abandoned holder. Captures mail with pre_wp_mail and
 * removes the test user.
 *
 * Development sites only: it creates a user and starts parallel WP-CLI processes while it runs.
 * Run all checks with `npm run test:wp`, or this one with
 * `php ../../../wp-cli.phar eval-file tests/wp-cli/hardening.php` from the plugin directory.
 * Prints PASS/FAIL per check and exits 1 when any check fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/user.php';

use Brain_2FA\Auth\UserLock;

global $wpdb;
$GLOBALS['b2_fails'] = 0;
$GLOBALS['b2_mail']  = array();
function b2_check( $label, $cond ) { echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $GLOBALS['b2_fails']++; } }
function b2_totp( $secret, $step ) { return \OTPHP\TOTP::create( $secret, 30, clock: new Brain_2FA\TwoFactor\SystemClock() )->at( $step * 30 ); }
function b2_codes( $user_id ) { wp_cache_flush(); $codes = get_user_meta( $user_id, 'brain2fa_recovery_codes', true ); return is_array( $codes ) ? count( $codes ) : -1; }
function b2_lock_rows( $user_id ) { global $wpdb; return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $wpdb->base_prefix . 'options', UserLock::OPTION_PREFIX . $user_id ) ); }

/**
 * Submit codes from parallel WP-CLI processes that all start at the same moment.
 *
 * @param int      $user_id User ID.
 * @param string   $method  Method ID.
 * @param string[] $codes   One code per worker.
 * @return int Number of accepted codes, or -1 when a worker failed or started too late to race.
 */
function b2_race( $user_id, $method, array $codes ) {
	$wp_cli   = realpath( $_SERVER['argv'][0] );
	$start_at = sprintf( '%.6F', microtime( true ) + 5 );
	$workers  = array();
	foreach ( $codes as $code ) {
		$cmd       = array( PHP_BINARY, $wp_cli, 'eval-file', __DIR__ . '/workers/verify-code.php', (string) $user_id, $method, $code, $start_at, '--path=' . ABSPATH );
		$proc      = proc_open( implode( ' ', array_map( 'escapeshellarg', $cmd ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
		$workers[] = array( $proc, $pipes[1] );
	}

	$accepted = 0;
	$valid    = true;
	foreach ( $workers as list( $proc, $stdout ) ) {
		$out = (string) stream_get_contents( $stdout );
		proc_close( $proc );
		$accepted += substr_count( $out, 'RESULT:OK' );
		if ( false === strpos( $out, 'RESULT:' ) || false !== strpos( $out, 'LATE' ) ) {
			$valid = false;
		}
	}
	wp_cache_flush();

	if ( ! $valid ) {
		echo "Worker failed or started late; raise the start delay in b2_race().\n";
		return -1;
	}
	return $accepted;
}

$user_id = 0;
try {
	add_filter( 'pre_wp_mail', function ( $null, $atts ) { $GLOBALS['b2_mail'][] = $atts; return true; }, 10, 2 );

	$user_id = wp_insert_user( array( 'user_login' => 'b2fa_h_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'b2fa-h-' . wp_generate_password( 6, false, false ) . '@example.test' ) );
	$user    = get_userdata( $user_id );
	$totp    = brain_2fa()->manager->get_method( 'totp' );
	$email   = brain_2fa()->manager->get_method( 'email' );
	$secret  = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
	update_user_meta( $user_id, 'brain2fa_secret', $secret );
	update_user_meta( $user_id, 'brain2fa_method', 'totp' );
	update_user_meta( $user_id, 'brain2fa_enabled', 1 );

	// TOTP: the first use (no last step stored yet), then a later step.
	$step = intdiv( time(), 30 );
	b2_check( 'totp first use: 1 of 8 parallel submissions accepted', 1 === b2_race( $user_id, 'totp', array_fill( 0, 8, b2_totp( $secret, $step ) ) ) );
	b2_check( 'totp first use: step recorded', (int) get_user_meta( $user_id, 'brain2fa_totp_last_step', true ) === $step );
	b2_check( 'totp next step: 1 of 8 parallel submissions accepted', 1 === b2_race( $user_id, 'totp', array_fill( 0, 8, b2_totp( $secret, $step + 1 ) ) ) );
	b2_check( 'totp: older step rejected after a newer one', ! $totp->verify( $user, b2_totp( $secret, $step ) ) );

	// Recovery codes.
	$codes = $totp->generate_recovery_codes( $user );
	b2_check( 'recovery: 1 of 8 parallel submissions accepted', 1 === b2_race( $user_id, 'totp', array_fill( 0, 8, $codes[0] ) ) );
	b2_check( 'recovery: used code removed', 9 === b2_codes( $user_id ) );
	b2_check( 'recovery: two different codes at once both accepted', 2 === b2_race( $user_id, 'totp', array( $codes[1], $codes[2] ) ) );
	b2_check( 'recovery: both removed (no lost update)', 7 === b2_codes( $user_id ) );
	b2_check( 'recovery: used code rejected', ! $totp->verify( $user, $codes[1] ) );
	b2_check( 'recovery: unused code accepted without dash', $totp->verify( $user, str_replace( '-', '', $codes[3] ) ) && 6 === b2_codes( $user_id ) );

	// Email codes.
	update_user_meta( $user_id, 'brain2fa_method', 'email' );
	$GLOBALS['b2_mail'] = array();
	b2_check( 'email: code sent', $email->send_challenge( $user ) && 1 === count( $GLOBALS['b2_mail'] ) );
	$code  = preg_match( '/\b(\d{6})\b/', $GLOBALS['b2_mail'][0]['message'] ?? '', $m ) ? $m[1] : '';
	$wrong = str_pad( (string) ( ( (int) $code + 1 ) % 1000000 ), 6, '0', STR_PAD_LEFT );
	b2_check( 'email: code not stored in plain text', '' !== $code && false === strpos( (string) maybe_serialize( get_transient( 'brain2fa_backup_code_' . $user_id ) ), $code ) );
	b2_check( 'email: pending code reported', $email->has_pending_code( $user ) );
	b2_check( 'email: no second email while a code is pending', $email->send_challenge( $user ) && 1 === count( $GLOBALS['b2_mail'] ) );
	b2_check( 'email: wrong code rejected, code kept', ! $email->verify( $user, $wrong ) && $email->has_pending_code( $user ) );
	b2_check( 'email: 1 of 8 parallel submissions accepted', 1 === b2_race( $user_id, 'email', array_fill( 0, 8, $code ) ) );
	b2_check( 'email: code consumed', ! $email->has_pending_code( $user ) );

	$GLOBALS['b2_mail'] = array();
	$email->send_challenge( $user );
	$code = preg_match( '/\b(\d{6})\b/', $GLOBALS['b2_mail'][0]['message'] ?? '', $m ) ? $m[1] : '';
	b2_check( 'email: code with surrounding spaces accepted', '' !== $code && $email->verify( $user, ' ' . $code . ' ' ) );

	// A plaintext code stored by 1.1.0 before the upgrade is discarded, and a new code is sent instead.
	set_transient( 'brain2fa_backup_code_' . $user_id, '123456', 600 );
	b2_check( 'legacy code: not reported as pending', ! $email->has_pending_code( $user ) );
	b2_check( 'legacy code: rejected and discarded', ! $email->verify( $user, '123456' ) && false === get_transient( 'brain2fa_backup_code_' . $user_id ) );
	set_transient( 'brain2fa_backup_code_' . $user_id, '123456', 600 );
	$GLOBALS['b2_mail'] = array();
	b2_check( 'legacy code: replaced by a new emailed code', $email->send_challenge( $user ) && 1 === count( $GLOBALS['b2_mail'] ) && '123456' !== get_transient( 'brain2fa_backup_code_' . $user_id ) );

	// The lock.
	b2_check( 'lock: acquired', UserLock::acquire( $user_id, 0 ) );
	b2_check( 'lock: reentrant in the same request', UserLock::acquire( $user_id, 0 ) );
	UserLock::release( $user_id );
	b2_check( 'lock: still held after an inner release', 1 === b2_lock_rows( $user_id ) );
	update_user_meta( $user_id, 'brain2fa_method', 'totp' );
	$step = intdiv( time(), 30 ) + 1;
	b2_check( 'lock: a code is rejected while another request holds the lock', 0 === b2_race( $user_id, 'totp', array( b2_totp( $secret, $step ) ) ) );
	UserLock::release( $user_id );
	b2_check( 'lock: released', 0 === b2_lock_rows( $user_id ) );
	b2_check( 'lock: same code accepted once the lock is free', $totp->verify( $user, b2_totp( $secret, $step ) ) );
	$wpdb->insert( $wpdb->base_prefix . 'options', array( 'option_name' => UserLock::OPTION_PREFIX . $user_id, 'option_value' => ( time() - 3600 ) . ':abandoned', 'autoload' => 'no' ) );
	b2_check( 'lock: abandoned lock taken over', UserLock::acquire( $user_id, 0 ) );
	UserLock::release( $user_id );
	b2_check( 'lock: no lock rows left', 0 === b2_lock_rows( $user_id ) );
} finally {
	if ( $user_id ) {
		wp_delete_user( $user_id );
		$wpdb->delete( $wpdb->base_prefix . 'options', array( 'option_name' => UserLock::OPTION_PREFIX . $user_id ) );
		delete_transient( 'brain2fa_backup_code_' . $user_id );
		echo "Cleanup: test user removed\n";
	}
}

if ( $GLOBALS['b2_fails'] ) {
	echo $GLOBALS['b2_fails'] . " FAILED\n";
	WP_CLI::halt( 1 );
}
echo "ALL PASSED\n";
