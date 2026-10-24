<?php
/**
 * Brain 2FA WP-CLI worker: verify one code at a given moment.
 *
 * Started in parallel by tests/wp-cli/hardening.php to submit the same code from several processes at once.
 * Arguments: user ID, method ID (totp or email), code, start time (Unix time with microseconds).
 * Prints RESULT:OK when the code was accepted and RESULT:NO otherwise.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

list( $b2_user_id, $b2_method_id, $b2_code, $b2_start_at ) = $args;

$b2_user   = get_userdata( (int) $b2_user_id );
$b2_method = brain_2fa()->manager->get_method( $b2_method_id );

// A worker that arrives after the start time is not racing; report it so the check is not trusted.
$b2_late = microtime( true ) > (float) $b2_start_at ? ' LATE' : '';

// Wait for the shared start time, so every worker submits at the same instant.
while ( microtime( true ) < (float) $b2_start_at ) {
	usleep( 200 );
}

echo ( $b2_user && $b2_method && $b2_method->verify( $b2_user, $b2_code ) ) ? 'RESULT:OK' : 'RESULT:NO', $b2_late, "\n";
