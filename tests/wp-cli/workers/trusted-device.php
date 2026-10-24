<?php
/**
 * Brain 2FA WP-CLI worker: sign in from a trusted device.
 *
 * Started by tests/wp-cli/trusted-devices.php while it holds the user's lock, to check that a last-use update
 * that waits for the lock does not restore a device revoked in the meantime.
 * Arguments: user ID, cookie value. Prints RESULT:OK when the device was trusted and RESULT:NO otherwise.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

list( $b2_user_id, $b2_cookie ) = $args;

$_COOKIE[ 'brain2fa_remember_' . $b2_user_id ] = $b2_cookie;
$b2_user = get_userdata( (int) $b2_user_id );

echo ( $b2_user && Brain_2FA\Utils::is_remembered_device( $b2_user, true ) ) ? "RESULT:OK\n" : "RESULT:NO\n";
