<?php
/**
 * Brain 2FA WP-CLI check: Uninstall cleanup.
 *
 * Backs up every row uninstall.php touches, runs it, checks that only Brain 2FA data was removed, then restores everything (the passkeys table is recreated). With "keep", defines BRAIN2FA_KEEP_DATA_ON_UNINSTALL first and checks that nothing is removed. Scheduled reminder scans are removed either way.
 *
 * Development sites only: it writes options and creates users while it runs.
 * Run all checks with `npm run test:wp`, or this one with
 * `php ../../../wp-cli.phar eval-file tests/wp-cli/uninstall.php [keep]` from the plugin directory.
 * Prints PASS/FAIL per check and exits 1 when any check fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
global $wpdb;
$GLOBALS['b2_fails'] = 0;
function b2_check( $label, $cond ) { echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $GLOBALS['b2_fails']++; } }

$keep    = in_array( 'keep', $args ?? array(), true );
$table   = $wpdb->prefix . 'brain2fa_passkeys';
$options = array( 'brain2fa_settings', 'brain2fa_emails', 'brain2fa_db_version', 'brain2fa_reminder_cursor' );
$state   = $wpdb->get_blog_prefix() . 'brain2fa_reminder';
$hooks   = array( 'brain2fa_enrollment_reminders', 'brain2fa_enrollment_reminders_continue' );
$bk_cron = array_filter( array_combine( $hooks, array_map( 'wp_get_scheduled_event', $hooks ) ) );
$like_t  = $wpdb->esc_like( '_transient_' ) . '%brain2fa%';

// Backup.
$bk_options = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ('" . implode( "','", $options ) . "') OR option_name LIKE '\\_transient\\_%brain2fa\\_%'", ARRAY_A );
$bk_meta    = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE 'brain2fa\\_%' OR meta_key = %s", $state ), ARRAY_A );
$bk_rows    = $wpdb->get_results( "SELECT * FROM `$table`", ARRAY_A );
echo 'Backed up: ' . count( $bk_options ) . ' options, ' . count( $bk_meta ) . ' meta, ' . count( $bk_rows ) . " passkeys\n";

$uid = 0;
try {
	// Test data.
	$uid = wp_insert_user( array( 'user_login' => 'b2_uninstall_' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => 'b2u' . wp_rand() . '@example.test' ) );
	foreach ( array( 'brain2fa_enabled' => 1, 'brain2fa_method' => 'totp', 'brain2fa_secret' => 'JBSWY3DPEHPK3PXP', 'brain2fa_recovery_codes' => array( 'x' ), 'brain2fa_totp_last_step' => 1, 'brain2fa_grace_expires_at' => time(), 'brain2fa_remembered_devices' => array(), 'brain2fa_failed_attempts' => array( 'count' => 1 ), 'brain2fa_passkey_only' => 1, 'brain2fa_webauthn_user_handle' => 'abc' ) as $k => $v ) {
		update_user_meta( $uid, $k, $v );
	}
	update_user_meta( $uid, 'other_plugin_meta', 'keep-me' );
	update_user_option( $uid, 'brain2fa_reminder', array( 'deadline' => time(), 'threshold' => 7 ) );
	update_option( 'brain2fa_reminder_cursor', 5, false );
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'brain2fa_enrollment_reminders' );
	wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'brain2fa_enrollment_reminders_continue' );
	update_option( 'brain2fa_settings', array( 'enable_2fa' => true ) );
	update_option( 'brain2fa_emails', array( 'from_name' => 'X' ), false );
	update_option( 'other_plugin_option', 'keep-me' );
	update_option( 'brain2fa_lookalike_not_transient', 'keep-me' );
	set_transient( 'brain2fa_backup_code_' . $uid, '123456', 600 );
	set_transient( 'brain2fa_ip_failures_abc', array( 'count' => 1 ), 600 );
	set_transient( 'other_plugin_transient', 'keep-me', 600 );
	$wpdb->insert( $table, array( 'user_id' => $uid, 'credential_hash' => hash( 'sha256', 'cred' ), 'credential_id' => 'cred', 'public_key' => 'pk', 'name' => 'Test', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	b2_check( 'setup: passkey row inserted', 1 <= (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" ) );

	if ( $keep ) {
		define( 'BRAIN2FA_KEEP_DATA_ON_UNINSTALL', true );
	}
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'brain-2fa/brain-2fa.php' );
	}
	include WP_PLUGIN_DIR . '/brain-2fa/uninstall.php';
	wp_cache_flush();

	$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	$meta_left    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE 'brain2fa\\_%'" );
	$trans_left   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%brain2fa\\_%'" );
	$state_left   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $state ) );

	b2_check( 'scheduled reminder scans removed', ! wp_next_scheduled( 'brain2fa_enrollment_reminders' ) && ! wp_next_scheduled( 'brain2fa_enrollment_reminders_continue' ) );

	if ( $keep ) {
		b2_check( 'keep: table kept', $table_exists );
		b2_check( 'keep: user meta kept', 10 <= $meta_left );
		b2_check( 'keep: options kept', false !== get_option( 'brain2fa_settings' ) && false !== get_option( 'brain2fa_emails' ) );
		b2_check( 'keep: transients kept', 4 <= $trans_left );
		b2_check( 'keep: reminder state and cursor kept', 1 === $state_left && 5 === (int) get_option( 'brain2fa_reminder_cursor' ) );
	} else {
		b2_check( 'passkeys table dropped', ! $table_exists );
		b2_check( 'all brain2fa user meta removed', 0 === $meta_left );
		b2_check( 'options removed', false === get_option( 'brain2fa_settings' ) && false === get_option( 'brain2fa_emails' ) && false === get_option( 'brain2fa_db_version' ) );
		b2_check( 'transients and timeouts removed', 0 === $trans_left );
		b2_check( 'reminder state and cursor removed', 0 === $state_left && false === get_option( 'brain2fa_reminder_cursor' ) );
		b2_check( 'user itself kept', (bool) get_userdata( $uid ) );
	}
	b2_check( 'other plugin meta untouched', 'keep-me' === get_user_meta( $uid, 'other_plugin_meta', true ) );
	b2_check( 'other options untouched', 'keep-me' === get_option( 'other_plugin_option' ) && 'keep-me' === get_option( 'brain2fa_lookalike_not_transient' ) );
	b2_check( 'other transients untouched', 'keep-me' === get_transient( 'other_plugin_transient' ) );
} finally {
	// Restore.
	if ( $uid ) { wp_delete_user( $uid ); }
	delete_option( 'other_plugin_option' );
	delete_option( 'brain2fa_lookalike_not_transient' );
	delete_transient( 'other_plugin_transient' );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name IN ('" . implode( "','", $options ) . "') OR option_name LIKE '\\_transient\\_%brain2fa\\_%'" );
	foreach ( $bk_options as $row ) { $wpdb->insert( $wpdb->options, $row ); }
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'brain2fa\\_%' OR meta_key = %s", $state ) );
	foreach ( $bk_meta as $row ) { $wpdb->insert( $wpdb->usermeta, $row ); }
	Brain_2FA\Install::create_tables();
	$wpdb->query( "DELETE FROM `$table`" );
	foreach ( $bk_rows as $row ) { $wpdb->insert( $table, $row ); }
	wp_cache_flush();
	foreach ( $hooks as $hook ) { wp_clear_scheduled_hook( $hook ); }
	foreach ( $bk_cron as $hook => $event ) {
		if ( $event->schedule ) { wp_schedule_event( $event->timestamp, $event->schedule, $hook ); } else { wp_schedule_single_event( $event->timestamp, $hook ); }
	}

	$ok = count( $bk_options ) === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ('" . implode( "','", $options ) . "') OR option_name LIKE '\\_transient\\_%brain2fa\\_%'" )
		&& count( $bk_meta ) === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE 'brain2fa\\_%' OR meta_key = %s", $state ) )
		&& count( $bk_rows ) === (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
	echo 'Restore: ' . ( $ok ? 'yes' : 'NO' ) . "\n";
}
$b2_failed = (int) $GLOBALS['b2_fails'];
echo $b2_failed ? $b2_failed . " FAILED\n" : "ALL PASSED\n";
if ( $b2_failed ) {
	WP_CLI::halt( 1 );
}
