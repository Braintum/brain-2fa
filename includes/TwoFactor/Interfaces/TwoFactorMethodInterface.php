<?php // phpcs:disable PSR1.Files.SideEffects
/**
 * Two Factor Method Interface
 *
 * @package Brain_2FA
 */

namespace Brain_2FA\TwoFactor\Interfaces;

use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for two-factor authentication methods.
 */
interface TwoFactorMethodInterface {
	/**
	 * Get the method ID.
	 *
	 * @return string The method identifier.
	 */
	public function get_id(): string;

	/**
	 * Generate setup form HTML for user profile.
	 *
	 * @param WP_User $user The user object.
	 * @return string HTML for profile setup.
	 */
	public function setup_form( WP_User $user ): string;

	/**
	 * Save setup configuration.
	 *
	 * @param WP_User $user The user object.
	 * @param array   $data The form data.
	 * @return bool|array|WP_Error Returns true on successful deactivation,
	 *                    array with 'success' and 'recovery_codes' keys on activation,
	 *                    WP_Error on invalid code
	 */
	public function save_setup( WP_User $user, array $data );

	/**
	 * Get setup data for the user.
	 *
	 * @param WP_User $user The user object.
	 * @return array Setup data.
	 */
	public function get_setup_data( WP_User $user ): array;

	/**
	 * Send authentication challenge to user.
	 *
	 * @param WP_User $user    The user object.
	 * @param array   $context Additional context data.
	 * @return bool True on success, false on failure.
	 */
	public function send_challenge( WP_User $user, array $context = array() ): bool;

	/**
	 * Verify the provided token.
	 *
	 * @param WP_User $user    The user object.
	 * @param string  $token   The token to verify.
	 * @param array   $context Additional context data.
	 * @return bool True if valid, false otherwise.
	 */
	public function verify( WP_User $user, string $token, array $context = array() ): bool;

	/**
	 * Generate recovery codes for the user.
	 *
	 * @param WP_User $user The user object.
	 * @return array Array of recovery codes.
	 */
	public function generate_recovery_codes( WP_User $user ): array;

	/**
	 * Get the count of remaining recovery codes for the user.
	 *
	 * @param WP_User $user The user object.
	 * @return int Count of remaining recovery codes.
	 */
	public function get_recovery_codes_count( WP_User $user ): int;
}
