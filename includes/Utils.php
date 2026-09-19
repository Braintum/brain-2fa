<?php // phpcs:disable PSR1.Files.SideEffects
/**
 * Utility functions for Brain 2FA plugin
 *
 * @package Brain_2FA
 */

namespace Brain_2FA;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Utils
 *
 * Provides utility functions for the Brain 2FA plugin.
 *
 * @package Brain_2FA
 */
class Utils {
	/**
	 * User meta key storing the Unix timestamp at which the setup grace period expires.
	 *
	 * @var string
	 */
	private const GRACE_PERIOD_EXPIRY_META_KEY = 'brain2fa_grace_expires_at';

	/**
	 * User meta key storing trusted-device token hashes and expiration timestamps.
	 *
	 * @var string
	 */
	private const REMEMBERED_DEVICES_META_KEY = 'brain2fa_remembered_devices';

	/**
	 * Check whether 2FA is enabled for a specific user.
	 *
	 * @param int|string $user_id User ID.
	 * @return bool True when user 2FA is enabled, false otherwise.
	 */
	public static function is_2fa_enabled_for_user( $user_id ): bool {
		$user_2fa_enabled = get_user_meta( $user_id, 'brain2fa_enabled', true );
		return ! empty( $user_2fa_enabled );
	}

	/**
	 * Check whether 2FA is enabled globally in plugin settings.
	 *
	 * @return bool True when sitewide 2FA is enabled, false otherwise.
	 */
	public static function is_2fa_enabled_sitewide(): bool {
		$plugin_settings = get_option( 'brain2fa_settings', array() );
		return ! empty( $plugin_settings['enable_2fa'] );
	}

	/**
	 * Check whether an authentication method is enabled site-wide.
	 *
	 * @param string $method_id Authentication method ID.
	 * @return bool True when the method is enabled.
	 */
	public static function is_method_enabled( string $method_id ): bool {
		$plugin_settings = get_option( 'brain2fa_settings', array() );

		if ( 'totp' === $method_id ) {
			return ! isset( $plugin_settings['enable_totp'] ) || ! empty( $plugin_settings['enable_totp'] );
		}

		if ( 'email' === $method_id ) {
			return ! isset( $plugin_settings['enable_email'] ) || ! empty( $plugin_settings['enable_email'] );
		}

		return false;
	}

	/**
	 * Get the active default authentication method.
	 *
	 * @return string Authentication method ID.
	 */
	public static function get_default_method(): string {
		$plugin_settings = get_option( 'brain2fa_settings', array() );
		$method          = $plugin_settings['default_method'] ?? 'totp';

		if ( 'email_backup' === $method ) {
			$method = 'email';
		}

		if ( self::is_method_enabled( $method ) ) {
			return $method;
		}

		return self::is_method_enabled( 'totp' ) ? 'totp' : 'email';
	}

	/**
	 * Check whether trusted devices are enabled site-wide.
	 *
	 * @return bool True when users may remember their devices.
	 */
	public static function is_remember_device_enabled(): bool {
		$plugin_settings = get_option( 'brain2fa_settings', array() );
		return ! empty( $plugin_settings['remember_device'] );
	}

	/**
	 * Get the number of days a trusted device remains valid.
	 *
	 * @return int Number of days, between 1 and 365.
	 */
	public static function get_remember_device_duration(): int {
		$plugin_settings = get_option( 'brain2fa_settings', array() );
		return min( 365, max( 1, absint( $plugin_settings['remember_duration'] ?? 30 ) ) );
	}

	/**
	 * Check whether the browser has a valid trusted-device token for a user.
	 *
	 * @param \WP_User $user User to evaluate.
	 * @return bool True when the device is trusted.
	 */
	public static function is_remembered_device( \WP_User $user ): bool {
		if ( ! self::is_remember_device_enabled() ) {
			return false;
		}

		$cookie_name = self::get_remembered_device_cookie_name( $user->ID );
		$token       = isset( $_COOKIE[ $cookie_name ] ) && is_string( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';
		$devices     = get_user_meta( $user->ID, self::REMEMBERED_DEVICES_META_KEY, true );
		$devices     = is_array( $devices ) ? $devices : array();
		$valid       = false;
		$now         = time();
		$active      = array();

		foreach ( $devices as $device ) {
			if ( ! is_array( $device ) || empty( $device['token'] ) || empty( $device['expires_at'] ) || $device['expires_at'] <= $now ) {
				continue;
			}

			$active[] = $device;
			if ( $token && wp_check_password( $token, $device['token'] ) ) {
				$valid = true;
			}
		}

		if ( $active !== $devices ) {
			update_user_meta( $user->ID, self::REMEMBERED_DEVICES_META_KEY, $active );
		}

		return $valid;
	}

	/**
	 * Remember the current browser after successful two-factor verification.
	 *
	 * @param \WP_User $user User whose device is being remembered.
	 * @return void
	 */
	public static function remember_device( \WP_User $user ): void {
		if ( ! self::is_remember_device_enabled() ) {
			return;
		}

		$expires_at = time() + ( self::get_remember_device_duration() * DAY_IN_SECONDS );
		$devices    = get_user_meta( $user->ID, self::REMEMBERED_DEVICES_META_KEY, true );
		$devices    = is_array( $devices ) ? $devices : array();
		$devices    = array_values(
			array_filter(
				$devices,
				static fn( $device ) => is_array( $device ) && ! empty( $device['token'] ) && ! empty( $device['expires_at'] ) && $device['expires_at'] > time()
			)
		);
		$token      = wp_generate_password( 64, false, false );
		$devices[]  = array(
			'token'      => wp_hash_password( $token ),
			'expires_at' => $expires_at,
		);
		$devices    = array_slice( $devices, -10 );

		update_user_meta( $user->ID, self::REMEMBERED_DEVICES_META_KEY, $devices );
		setcookie(
			self::get_remembered_device_cookie_name( $user->ID ),
			$token,
			array(
				'expires'  => $expires_at,
				'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Invalidate all remembered devices for a user.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function forget_remembered_devices( int $user_id ): void {
		delete_user_meta( $user_id, self::REMEMBERED_DEVICES_META_KEY );
	}

	/**
	 * Get the trusted-device cookie name for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string Cookie name.
	 */
	private static function get_remembered_device_cookie_name( int $user_id ): string {
		return 'brain2fa_remember_' . $user_id;
	}

	/**
	 * Check whether 2FA is required for a user because of their role.
	 *
	 * @param \WP_User $user User to evaluate.
	 * @return bool True when the user belongs to a role that must use 2FA.
	 */
	public static function is_2fa_required_for_user( \WP_User $user ): bool {
		if ( ! self::is_2fa_enabled_sitewide() ) {
			return false;
		}

		$plugin_settings = get_option( 'brain2fa_settings', array() );
		$required_roles  = $plugin_settings['force_2fa_roles'] ?? array();

		if ( empty( $required_roles ) || ! is_array( $required_roles ) ) {
			return false;
		}

		return (bool) array_intersect( $user->roles, $required_roles );
	}

	/**
	 * Get the number of days a user has to configure 2FA.
	 *
	 * @return int Number of grace-period days.
	 */
	public static function get_grace_period_days(): int {
		$plugin_settings = get_option( 'brain2fa_settings', array() );
		$days            = absint( $plugin_settings['grace_period_days'] ?? 14 );

		return min( 365, $days );
	}

	/**
	 * Get or create the grace-period expiry for an unenrolled required user.
	 *
	 * @param \WP_User $user User to evaluate.
	 * @return int Unix timestamp, or zero when no grace period applies.
	 */
	public static function get_grace_period_expiry( \WP_User $user ): int {
		if ( self::is_2fa_enabled_for_user( $user->ID ) || ! self::is_2fa_required_for_user( $user ) ) {
			return 0;
		}

		$expires_at = absint( get_user_meta( $user->ID, self::GRACE_PERIOD_EXPIRY_META_KEY, true ) );
		if ( $expires_at > 0 ) {
			return $expires_at;
		}

		$expires_at = time() + ( self::get_grace_period_days() * DAY_IN_SECONDS );
		update_user_meta( $user->ID, self::GRACE_PERIOD_EXPIRY_META_KEY, $expires_at );

		return $expires_at;
	}

	/**
	 * Check whether a required user's 2FA setup grace period has expired.
	 *
	 * @param \WP_User $user User to evaluate.
	 * @return bool True when the grace period has expired.
	 */
	public static function is_grace_period_expired( \WP_User $user ): bool {
		$expires_at = self::get_grace_period_expiry( $user );

		return $expires_at > 0 && $expires_at <= time();
	}

	/**
	 * Extend a required user's current grace period.
	 *
	 * @param \WP_User $user User whose grace period will be extended.
	 * @param int      $days Number of days to add.
	 * @return int Updated Unix expiry timestamp, or zero when no grace period applies.
	 */
	public static function extend_grace_period( \WP_User $user, int $days ): int {
		if ( $days < 1 || self::is_2fa_enabled_for_user( $user->ID ) || ! self::is_2fa_required_for_user( $user ) ) {
			return 0;
		}

		$expires_at = max( time(), self::get_grace_period_expiry( $user ) ) + ( $days * DAY_IN_SECONDS );
		update_user_meta( $user->ID, self::GRACE_PERIOD_EXPIRY_META_KEY, $expires_at );

		return $expires_at;
	}
}
