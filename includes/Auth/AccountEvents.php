<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Account Events
 *
 * @package Brain_2FA\Auth
 * @since 1.0.3
 */

namespace Brain_2FA\Auth;

use Brain_2FA\Utils;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Account Events
 *
 * Revokes trusted devices when a user's password changes, so a copied
 * trusted-device cookie stops working after account recovery.
 *
 * @since 1.0.3
 */
class AccountEvents {

	/**
	 * Register account event hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_set_password', array( __CLASS__, 'on_password_set' ), 10, 3 );
		add_action( 'profile_update', array( __CLASS__, 'on_profile_update' ), 10, 2 );
	}

	/**
	 * Revoke trusted devices when wp_set_password() changes a password.
	 *
	 * Covers password resets and programmatic changes. WordPress also calls
	 * wp_set_password() to rehash an unchanged password at login, so devices are
	 * kept when the new password matches the old hash.
	 *
	 * @param string       $password      The new plaintext password.
	 * @param int          $user_id       User ID.
	 * @param WP_User|null $old_user_data User data before the change (WordPress 6.7+).
	 * @return void
	 */
	public static function on_password_set( $password, $user_id, $old_user_data = null ): void {
		if ( $old_user_data instanceof WP_User && is_string( $password ) && wp_check_password( $password, $old_user_data->user_pass, (int) $user_id ) ) {
			return;
		}

		Utils::forget_remembered_devices( (int) $user_id );
	}

	/**
	 * Revoke trusted devices when a profile update changes the password hash.
	 *
	 * Covers password changes from the profile screen and wp_update_user().
	 *
	 * @param int     $user_id       User ID.
	 * @param WP_User $old_user_data User data before the update.
	 * @return void
	 */
	public static function on_profile_update( $user_id, $old_user_data ): void {
		$user = get_userdata( (int) $user_id );

		if ( $user instanceof WP_User && $old_user_data instanceof WP_User && $old_user_data->user_pass !== $user->user_pass ) {
			Utils::forget_remembered_devices( (int) $user_id );
		}
	}
}
