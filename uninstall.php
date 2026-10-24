<?php
/**
 * Removes all Brain 2FA data when the plugin is deleted from the Plugins screen.
 *
 * Deletes the settings and email options, transients and the passkeys table on every site,
 * and every user's two-factor data (secrets, recovery codes, trusted devices, passkey
 * settings). Deactivating the plugin keeps all data; only deleting it runs this file.
 *
 * To keep the data when the plugin is deleted, add this to wp-config.php:
 *
 *     define( 'BRAIN2FA_KEEP_DATA_ON_UNINSTALL', true );
 *
 * @package Brain_2FA
 * @since   1.1.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Run a callback on every site of the network, or on the only site.
 *
 * @since 1.2.0
 *
 * @param callable $callback Callback; runs with the site switched in.
 */
function brain2fa_uninstall_each_site( callable $callback ): void {
	if ( ! is_multisite() ) {
		$callback();
		return;
	}

	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $brain2fa_site_id ) {
		switch_to_blog( (int) $brain2fa_site_id );
		$callback();
		restore_current_blog();
	}
}

// Scheduled reminder scans would run code that is gone, so they are removed even when the data is kept.
brain2fa_uninstall_each_site(
	static function () {
		wp_clear_scheduled_hook( 'brain2fa_enrollment_reminders' );
		wp_clear_scheduled_hook( 'brain2fa_enrollment_reminders_continue' );
	}
);

if ( defined( 'BRAIN2FA_KEEP_DATA_ON_UNINSTALL' ) && BRAIN2FA_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

/**
 * Remove the plugin's options, transients, site-scoped user meta and passkeys table from the current site.
 *
 * @since 1.1.0
 */
function brain2fa_uninstall_site(): void {
	global $wpdb;

	foreach ( array( 'brain2fa_settings', 'brain2fa_emails', 'brain2fa_db_version', 'brain2fa_reminder_cursor' ) as $option ) {
		delete_option( $option );
	}

	// Setup reminder state is kept per site (user options), under the site's table prefix.
	delete_metadata( 'user', 0, $wpdb->get_blog_prefix() . 'brain2fa_reminder', '', true );

	// Transients and user locks have dynamic names (user IDs, hashed IPs and tokens), so they are matched by prefix.
	// Locks live in the main site's table and are normally gone already; one is left only by a request that died.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- One-time cleanup on uninstall.
	$wpdb->query(
		$wpdb->prepare(
			'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
			$wpdb->options,
			$wpdb->esc_like( '_transient_brain2fa_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_brain2fa_' ) . '%',
			$wpdb->esc_like( 'brain2fa_lock_' ) . '%'
		)
	);

	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'brain2fa_passkeys' ) );
	// phpcs:enable
}

brain2fa_uninstall_each_site( 'brain2fa_uninstall_site' );

// User meta is shared by all sites of a network, so it is removed once. Keep this list in sync with the meta keys in includes/.
$brain2fa_user_meta_keys = array(
	'brain2fa_enabled',
	'brain2fa_method',
	'brain2fa_secret',
	'brain2fa_recovery_codes',
	'brain2fa_totp_last_step',
	'brain2fa_grace_expires_at',
	'brain2fa_remembered_devices',
	'brain2fa_failed_attempts',
	'brain2fa_passkey_only',
	'brain2fa_webauthn_user_handle',
);

foreach ( $brain2fa_user_meta_keys as $brain2fa_meta_key ) {
	delete_metadata( 'user', 0, $brain2fa_meta_key, '', true );
}
