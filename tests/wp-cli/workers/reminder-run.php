<?php
/**
 * Brain 2FA WP-CLI worker: run one setup reminder scan at a given moment.
 *
 * Started in parallel by tests/wp-cli/reminders.php so several scans overlap.
 * Arguments: start time (Unix time with microseconds), log file.
 * Mail is not sent: each email's recipient is appended to the log file, after a short pause that keeps
 * the scans overlapping. Prints RESULT:<sent count>, with LATE when the worker missed the start time.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}

list( $b2_start_at, $b2_log ) = $args;

add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) use ( $b2_log ) {
		usleep( 300000 );
		file_put_contents( $b2_log, $atts['to'] . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return true;
	},
	10,
	2
);

$b2_late = microtime( true ) > (float) $b2_start_at ? ' LATE' : '';
while ( microtime( true ) < (float) $b2_start_at ) {
	usleep( 200 );
}

$b2_result = Brain_2FA\Enrollment\ReminderScheduler::run();
echo 'RESULT:', (int) $b2_result['sent'], $b2_late, "\n";
