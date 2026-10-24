<?php
/**
 * Brain 2FA WP-CLI check: Setup reminder emails.
 *
 * Settings and scheduling (off by default, one recurring job, removed when turned off), lead-time thresholds,
 * who gets a reminder and who does not (not started, overdue, zero-day grace, enrolled, not required, deleted,
 * methods off), one reminder per threshold and per day (also across extensions), no catch-up burst, bounded
 * retries, claims and their expiry, the resumable cursor, the rendered email and its template rules, and
 * overlapping scans in parallel processes (tests/wp-cli/workers/reminder-run.php). Mail is captured, never sent.
 * Restores brain2fa_settings, brain2fa_emails, reminder state, the cursor and the schedule, and removes the test users.
 *
 * Development sites only: it writes options and creates users while it runs.
 * Run all checks with `npm run test:wp`, or this one with
 * `php ../../../wp-cli.phar eval-file tests/wp-cli/reminders.php` from the plugin directory.
 * Prints PASS/FAIL per check and exits 1 when any check fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/user.php';

use Brain_2FA\Emails\EmailRegistry;
use Brain_2FA\Emails\Mailer;
use Brain_2FA\Enrollment\EnrollmentStatus;
use Brain_2FA\Enrollment\ReminderScheduler as RS;
use Brain_2FA\Utils;

global $wpdb;
$missing   = new stdClass();
$original  = get_option( 'brain2fa_settings', $missing );
$emails    = get_option( EmailRegistry::OPTION, $missing );
$cursor    = get_option( RS::CURSOR_OPTION, $missing );
$state_key = $wpdb->get_blog_prefix() . RS::STATE_KEY;
$bk_state  = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $state_key ), ARRAY_A );
$bk_cron   = array_filter( array( RS::HOOK => wp_get_scheduled_event( RS::HOOK ), RS::CONTINUE_HOOK => wp_get_scheduled_event( RS::CONTINUE_HOOK ) ) );
$GLOBALS['b2_users'] = array();
$GLOBALS['b2_fails'] = 0;
$GLOBALS['b2_mail']  = array();
$GLOBALS['b2_fail_mail'] = false;
set_error_handler( function ( $no, $str, $file, $line ) { if ( false !== strpos( $file, 'brain-2fa' ) ) { echo "PHP ISSUE: $str in $file:$line\n"; } return false; } );

function b2_check( $label, $cond ) { echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $GLOBALS['b2_fails']++; } }
function b2_settings( array $over ) { remove_all_filters( 'sanitize_option_brain2fa_settings' ); update_option( 'brain2fa_settings', array_merge( Utils::SETTINGS_DEFAULTS, array( 'enable_2fa' => true, 'force_2fa_roles' => array( 'editor' ), 'enrollment_reminders' => true ), $over ) ); }
function b2_state( $id ) { wp_cache_delete( $id, 'user_meta' ); return RS::get_state( $id ); }
function b2_set_state( $id, array $over ) { update_user_option( $id, RS::STATE_KEY, array_merge( b2_state( $id ), $over ) ); }
function b2_deadline( $id, $offset ) { update_user_meta( $id, 'brain2fa_grace_expires_at', time() + $offset ); return time() + $offset; }
function b2_count_events( $hook ) { $n = 0; foreach ( _get_cron_array() as $events ) { $n += isset( $events[ $hook ] ) ? count( $events[ $hook ] ) : 0; } return $n; }

/**
 * Create a test user.
 *
 * @param string   $role   Role.
 * @param int|null $offset Deadline from now in seconds, or null for none.
 * @return int User ID.
 */
function b2_user( $role = 'editor', $offset = null ) {
	$id = wp_insert_user( array( 'user_login' => 'b2fa_r_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'b2fa-r-' . wp_generate_password( 8, false, false ) . '@example.test', 'role' => $role, 'display_name' => 'Rae' ) );
	$GLOBALS['b2_users'][] = $id;
	if ( null !== $offset ) { b2_deadline( $id, $offset ); }
	return $id;
}

/**
 * Run a scan and return the reminders sent to test users.
 *
 * @return array<int, array> Captured emails keyed by user ID; a user with two emails fails the count checks.
 */
function b2_run() {
	$GLOBALS['b2_mail'] = array();
	RS::run();
	$sent = array();
	foreach ( $GLOBALS['b2_mail'] as $mail ) {
		$user = get_user_by( 'email', $mail['to'] );
		if ( $user && in_array( $user->ID, $GLOBALS['b2_users'], true ) ) {
			$sent[ $user->ID ][] = $mail;
		}
	}
	return $sent;
}

/**
 * Save the reminder template with a body.
 *
 * @param string $body Body.
 * @return true|WP_Error Result.
 */
function b2_save( $body ) {
	return EmailRegistry::save_template( RS::EMAIL_ID, true, 'Reminder for {user_login}', $body );
}

try {
	add_filter( 'pre_wp_mail', function ( $null, $atts ) { $GLOBALS['b2_mail'][] = $atts; return ! $GLOBALS['b2_fail_mail']; }, 10, 2 );
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admin[0]->ID );

	// Settings.
	b2_check( 'defaults: reminders off, 7 and 1 days', false === Utils::SETTINGS_DEFAULTS['enrollment_reminders'] && array( 7, 1 ) === Utils::SETTINGS_DEFAULTS['reminder_days'] );
	b2_check( 'lead days: parsed, deduplicated, longest first', array( 7, 1 ) === RS::sanitize_lead_days( '1, 7,7 , abc, 0, 400, -3' ) && array( 3, 2 ) === RS::sanitize_lead_days( array( '2', 3 ) ) );
	b2_check( 'lead days: empty falls back to the defaults, at most five', array( 7, 1 ) === RS::sanitize_lead_days( '' ) && 5 === count( RS::sanitize_lead_days( '1,2,3,4,5,6,7' ) ) );

	b2_settings( array( 'enrollment_reminders' => false ) );
	Brain_2FA\Admin\Settings::instance()->register_settings();
	update_option( 'brain2fa_settings', array( '_tab' => 'requirements', 'force_2fa_roles' => array( 'editor' ), 'grace_period_days' => '14', 'enrollment_reminders' => '1', 'reminder_days' => '3, 10' ) );
	$saved = Utils::get_settings();
	b2_check( 'requirements tab saves reminders', true === $saved['enrollment_reminders'] && array( 10, 3 ) === $saved['reminder_days'] && true === $saved['enable_2fa'] );
	update_option( 'brain2fa_settings', array( '_tab' => 'trusted-devices', 'remember_duration' => '9' ) );
	b2_check( 'other tab save keeps reminder settings', true === Utils::get_settings()['enrollment_reminders'] && array( 10, 3 ) === Utils::get_settings()['reminder_days'] );

	// Scheduling.
	b2_settings( array( 'enrollment_reminders' => false ) );
	b2_check( 'off: nothing scheduled', ! wp_next_scheduled( RS::HOOK ) );
	b2_settings( array() );
	RS::sync_schedule();
	b2_check( 'on: one hourly scan scheduled', 1 === b2_count_events( RS::HOOK ) && 'hourly' === wp_get_scheduled_event( RS::HOOK )->schedule );
	b2_settings( array( 'enable_2fa' => false ) );
	b2_check( '2FA off: scan removed', ! wp_next_scheduled( RS::HOOK ) );
	b2_settings( array() );
	RS::deactivate();
	b2_check( 'deactivation removes the scan', ! wp_next_scheduled( RS::HOOK ) );
	RS::sync_schedule();
	b2_check( 'admin_init restores it once', 1 === b2_count_events( RS::HOOK ) );

	// Thresholds.
	$now = time();
	b2_check(
		'thresholds: the closest lead time crossed',
		0 === RS::get_due_threshold( $now + 8 * DAY_IN_SECONDS, $now, array( 7, 1 ) )
		&& 7 === RS::get_due_threshold( $now + 7 * DAY_IN_SECONDS, $now, array( 7, 1 ) )
		&& 7 === RS::get_due_threshold( $now + 2 * DAY_IN_SECONDS, $now, array( 7, 1 ) )
		&& 1 === RS::get_due_threshold( $now + HOUR_IN_SECONDS, $now, array( 7, 1 ) )
		&& 0 === RS::get_due_threshold( $now, $now, array( 7, 1 ) )
	);

	// Who gets a reminder.
	$five    = b2_user( 'editor', 5 * DAY_IN_SECONDS );
	$late    = b2_user( 'editor', 12 * HOUR_IN_SECONDS );
	$far     = b2_user( 'editor', 20 * DAY_IN_SECONDS );
	$fresh   = b2_user( 'editor' );
	$over    = b2_user( 'editor', -60 );
	$sub     = b2_user( 'subscriber', 3 * DAY_IN_SECONDS );
	$done    = b2_user( 'editor', 3 * DAY_IN_SECONDS );
	update_user_meta( $done, 'brain2fa_method', 'totp' );
	update_user_meta( $done, 'brain2fa_enabled', 1 );
	$zero    = b2_user( 'editor' );
	b2_settings( array( 'grace_period_days' => 0 ) );
	Utils::get_grace_period_expiry( get_userdata( $zero ) );
	b2_settings( array() );
	$bad_mail = b2_user( 'editor', 2 * DAY_IN_SECONDS );
	$wpdb->update( $wpdb->users, array( 'user_email' => 'not-an-address' ), array( 'ID' => $bad_mail ) );
	clean_user_cache( $bad_mail );

	$sent = b2_run();
	b2_check( 'pending within 7 days: one reminder', 1 === count( $sent[ $five ] ?? array() ) );
	b2_check( 'deadline in 12 hours with no reminder yet: one reminder, not two', 1 === count( $sent[ $late ] ?? array() ) && 1 === b2_state( $late )['threshold'] );
	b2_check( 'not sent: deadline beyond the longest lead time', ! isset( $sent[ $far ] ) );
	b2_check( 'not sent: not started, and no deadline created', ! isset( $sent[ $fresh ] ) && 0 === EnrollmentStatus::get_deadline( $fresh ) );
	b2_check( 'not sent: overdue', ! isset( $sent[ $over ] ) );
	b2_check( 'not sent: zero-day grace', ! isset( $sent[ $zero ] ) && EnrollmentStatus::STATUS_OVERDUE === EnrollmentStatus::get_status( get_userdata( $zero ) ) );
	b2_check( 'not sent: role not required', ! isset( $sent[ $sub ] ) );
	b2_check( 'not sent: already enrolled', ! isset( $sent[ $done ] ) );
	b2_check( 'not sent: invalid address, counted as a failed attempt', ! isset( $sent[ $bad_mail ] ) && 1 === b2_state( $bad_mail )['attempts'] );

	// The email.
	$mail = $sent[ $five ][0] ?? array( 'to' => '', 'subject' => '', 'message' => '' );
	b2_check( 'email: recipient and subject', get_userdata( $five )->user_email === $mail['to'] && false !== strpos( $mail['subject'], 'Please set up two-factor authentication' ) );
	b2_check( 'email: setup link and deadline in the body', false !== strpos( $mail['message'], Brain_2FA\Auth\EnrollmentGuard::get_setup_url() ) && false !== strpos( $mail['message'], wp_date( get_option( 'date_format' ), EnrollmentStatus::get_deadline( $five ) ) ) && false === strpos( $mail['message'], '{' ) );
	$state = b2_state( $five );
	b2_check( 'state: deadline, threshold and time recorded, claim released', EnrollmentStatus::get_deadline( $five ) === $state['deadline'] && 7 === $state['threshold'] && $state['last_sent'] >= $now && '' === $state['claim'] );
	b2_check( 'state: stored per site', (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s", $five, $state_key ) ) );

	$sent = b2_run();
	b2_check( 'repeated scan: nothing sent again', ! array_intersect_key( $sent, array_flip( array( $five, $late ) ) ) );

	// Next threshold, the daily limit and extensions.
	b2_deadline( $five, 20 * HOUR_IN_SECONDS );
	b2_set_state( $five, array( 'deadline' => EnrollmentStatus::get_deadline( $five ) ) );
	b2_check( '1-day reminder held back within 24 hours of the last one', ! isset( b2_run()[ $five ] ) );
	b2_set_state( $five, array( 'last_sent' => time() - DAY_IN_SECONDS - 60 ) );
	b2_check( '1-day reminder sent after 24 hours', 1 === count( b2_run()[ $five ] ?? array() ) && 1 === b2_state( $five )['threshold'] );

	Utils::extend_grace_period( get_userdata( $five ), 5 );
	b2_check( 'extension: new deadline, but still one reminder a day', ! isset( b2_run()[ $five ] ) );
	b2_set_state( $five, array( 'last_sent' => time() - DAY_IN_SECONDS - 60 ) );
	b2_check( 'extension: 7-day reminder for the new deadline', 1 === count( b2_run()[ $five ] ?? array() ) && 7 === b2_state( $five )['threshold'] );

	// Retries.
	$retry = b2_user( 'editor', 3 * DAY_IN_SECONDS );
	$GLOBALS['b2_fail_mail'] = true;
	b2_run();
	$state = b2_state( $retry );
	b2_check( 'failed send: one attempt, retry later', 1 === $state['attempts'] && $state['retry_at'] > time() && 0 === $state['threshold'] && '' === $state['claim'] );
	b2_run();
	b2_check( 'failed send: not retried before the delay', 1 === b2_state( $retry )['attempts'] );
	b2_set_state( $retry, array( 'retry_at' => time() - 1 ) );
	b2_run();
	b2_set_state( $retry, array( 'retry_at' => time() - 1 ) );
	b2_run();
	b2_check( 'failed send: given up after 3 attempts', 7 === b2_state( $retry )['threshold'] && 0 === b2_state( $retry )['attempts'] );
	$GLOBALS['b2_fail_mail'] = false;
	b2_check( 'given up: not retried', ! isset( b2_run()[ $retry ] ) );

	// Claims.
	$claimed = b2_user( 'editor', 3 * DAY_IN_SECONDS );
	b2_set_state( $claimed, array( 'claim' => 'other-worker', 'claim_expires' => time() + 300 ) );
	b2_check( 'claimed by another scan: skipped', ! isset( b2_run()[ $claimed ] ) );
	b2_set_state( $claimed, array( 'claim_expires' => time() - 1 ) );
	b2_check( 'expired claim: taken over and sent', 1 === count( b2_run()[ $claimed ] ?? array() ) && '' === b2_state( $claimed )['claim'] );

	// Stopping.
	$stop = array();
	foreach ( array( 'enrolled', 'role', 'deleted', 'expired' ) as $case ) { $stop[ $case ] = b2_user( 'editor', 3 * DAY_IN_SECONDS ); }
	update_user_meta( $stop['enrolled'], 'brain2fa_method', 'totp' );
	update_user_meta( $stop['enrolled'], 'brain2fa_enabled', 1 );
	get_userdata( $stop['role'] )->set_role( 'subscriber' );
	wp_delete_user( $stop['deleted'] );
	b2_deadline( $stop['expired'], -1 );
	b2_check( 'stopped: enrolled, role changed, deleted, expired', ! array_intersect_key( b2_run(), array_flip( $stop ) ) );

	$methods_off = b2_user( 'editor', 3 * DAY_IN_SECONDS );
	b2_settings( array( 'enable_totp' => false, 'enable_email' => false ) );
	b2_check( 'stopped: no code method to set up', ! isset( b2_run()[ $methods_off ] ) );
	b2_settings( array( 'enrollment_reminders' => false ) );
	b2_check( 'stopped: reminders turned off, scan removed', ! isset( b2_run()[ $methods_off ] ) && ! wp_next_scheduled( RS::HOOK ) );
	b2_check( 'turned off: email shows as off', ! EmailRegistry::is_enabled( RS::EMAIL_ID ) && false === Mailer::send( RS::EMAIL_ID, get_userdata( $methods_off ) ) );
	b2_settings( array() );
	b2_check( 'turned on: email shows as on', EmailRegistry::is_enabled( RS::EMAIL_ID ) );

	// Cursor.
	$low  = b2_user( 'editor', 3 * DAY_IN_SECONDS );
	$high = b2_user( 'editor', 3 * DAY_IN_SECONDS );
	update_option( RS::CURSOR_OPTION, $low, false );
	$sent = b2_run();
	b2_check( 'cursor: a resumed scan starts after it, then wraps', ! isset( $sent[ $low ] ) && isset( $sent[ $high ] ) && 0 === (int) get_option( RS::CURSOR_OPTION ) );
	b2_check( 'cursor: next scan covers the rest', isset( b2_run()[ $low ] ) );

	// Template rules.
	$custom = b2_save( 'Hi {user_first_name}, {days_remaining} days left until {setup_deadline}: {login_security_url}' );
	b2_check( 'template: saved with placeholders', true === $custom );
	b2_check( 'template: setup link required', is_wp_error( b2_save( 'No link here' ) ) );
	$custom_user = b2_user( 'editor', 3 * DAY_IN_SECONDS + HOUR_IN_SECONDS );
	$body        = b2_run()[ $custom_user ][0]['message'] ?? '';
	b2_check( 'template: custom body rendered', 0 === strpos( $body, 'Hi Rae, 4 days left until ' ) && false !== strpos( $body, Brain_2FA\Auth\EnrollmentGuard::get_setup_url() ) );
	$stored = EmailRegistry::get_stored();
	$stored['templates'][ RS::EMAIL_ID ]['body'] = 'Edited outside the form, no link';
	update_option( EmailRegistry::OPTION, $stored, false );
	b2_check( 'template: stored body without the link falls back to the default', false !== strpos( EmailRegistry::get_template( RS::EMAIL_ID )['body'], '{login_security_url}' ) );

	$locales = get_available_languages();
	if ( $locales ) {
		$loc_user = b2_user( 'editor', 2 * DAY_IN_SECONDS );
		update_user_meta( $loc_user, 'locale', $locales[0] );
		$seen = '';
		$probe = function ( $null ) use ( &$seen ) { $seen = determine_locale(); return $null; };
		add_filter( 'pre_wp_mail', $probe, 5 );
		b2_run();
		remove_filter( 'pre_wp_mail', $probe, 5 );
		b2_check( "locale: sent in the user's language ({$locales[0]}), then restored", $locales[0] === $seen && get_locale() === determine_locale() );
	} else {
		echo "SKIP locale: no installed languages\n";
	}

	// Overlapping scans.
	$racers = array();
	for ( $i = 0; $i < 6; $i++ ) { $racers[] = b2_user( 'editor', 3 * DAY_IN_SECONDS ); }
	$log      = wp_tempnam( 'b2-reminders' );
	$start_at = sprintf( '%.6F', microtime( true ) + 8 );
	$workers  = array();
	for ( $i = 0; $i < 4; $i++ ) {
		$cmd       = array( PHP_BINARY, realpath( $_SERVER['argv'][0] ), 'eval-file', __DIR__ . '/workers/reminder-run.php', $start_at, $log, '--path=' . ABSPATH );
		$proc      = proc_open( implode( ' ', array_map( 'escapeshellarg', $cmd ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes );
		$workers[] = array( $proc, $pipes[1] );
	}
	$valid = true;
	foreach ( $workers as list( $proc, $stdout ) ) {
		$out = (string) stream_get_contents( $stdout );
		proc_close( $proc );
		$valid = $valid && false !== strpos( $out, 'RESULT:' ) && false === strpos( $out, 'LATE' );
	}
	$lines = array_count_values( array_filter( explode( "\n", (string) file_get_contents( $log ) ) ) );
	wp_delete_file( $log );
	$counts = array();
	foreach ( $racers as $id ) { $counts[] = $lines[ get_userdata( $id )->user_email ] ?? 0; }
	if ( ! $valid ) { echo "FAIL race not run: a worker failed or started late; raise the start delay.\n"; $GLOBALS['b2_fails']++; }
	b2_check( '4 overlapping scans: each user emailed exactly once (' . implode( ',', $counts ) . ')', array( 1 ) === array_values( array_unique( $counts ) ) );
} finally {
	restore_error_handler();
	foreach ( $GLOBALS['b2_users'] as $id ) { if ( get_userdata( $id ) ) { wp_delete_user( $id ); } }
	remove_all_filters( 'sanitize_option_brain2fa_settings' );
	if ( $original === $missing ) { delete_option( 'brain2fa_settings' ); } else { update_option( 'brain2fa_settings', $original ); }
	if ( $emails === $missing ) { delete_option( EmailRegistry::OPTION ); } else { update_option( EmailRegistry::OPTION, $emails, false ); }
	if ( $cursor === $missing ) { delete_option( RS::CURSOR_OPTION ); } else { update_option( RS::CURSOR_OPTION, $cursor, false ); }
	$wpdb->delete( $wpdb->usermeta, array( 'meta_key' => $state_key ) );
	foreach ( $bk_state as $row ) { $wpdb->insert( $wpdb->usermeta, array( 'user_id' => $row['user_id'], 'meta_key' => $state_key, 'meta_value' => $row['meta_value'] ) ); }
	wp_cache_flush();
	RS::unschedule();
	foreach ( $bk_cron as $hook => $event ) {
		if ( $event->schedule ) { wp_schedule_event( $event->timestamp, $event->schedule, $hook ); } else { wp_schedule_single_event( $event->timestamp, $hook ); }
	}
	$restored = get_option( 'brain2fa_settings', $missing ) == $original && get_option( EmailRegistry::OPTION, $missing ) == $emails && count( $bk_state ) === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $state_key ) ) && (bool) wp_next_scheduled( RS::HOOK ) === isset( $bk_cron[ RS::HOOK ] );
	echo 'Cleanup: users removed=' . ( ! array_filter( array_map( 'get_userdata', $GLOBALS['b2_users'] ) ) ? 'yes' : 'NO' ) . ', options, state and schedule restored=' . ( $restored ? 'yes' : 'NO' ) . "\n";
}

$b2_failed = (int) $GLOBALS['b2_fails'];
echo $b2_failed ? "$b2_failed FAILED\n" : "ALL PASSED\n";
if ( $b2_failed ) {
	WP_CLI::halt( 1 );
}
