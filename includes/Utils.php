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

}
