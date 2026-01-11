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
	 * Set an ephemeral token cookie for 2FA session.
	 *
	 * @param string $token       The ephemeral token to set in the cookie.
	 * @param int    $ttl_seconds Time to live for the cookie in seconds. Default is 300 seconds (5 minutes).
	 * @return void
	 */
	public static function set_ephemeral_token_cookie( string $token, int $ttl_seconds = 300 ) {
		// COOKIEPATH and COOKIE_DOMAIN are WP constants.
		setcookie( 'brain2fa_session', $token, time() + $ttl_seconds, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	}

	/**
	 * Clear the ephemeral token cookie.
	 *
	 * @return void
	 */
	public static function clear_ephemeral_token_cookie() {
		setcookie( 'brain2fa_session', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	}
}
