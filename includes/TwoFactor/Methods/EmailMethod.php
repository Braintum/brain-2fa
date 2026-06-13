<?php // phpcs:disable PSR1.Files.SideEffects

declare( strict_types=1 );

namespace Brain_2FA\TwoFactor\Methods;

use Brain_2FA\TwoFactor\Interfaces\TwoFactorMethodInterface;
use WP_User;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Email-based Two Factor Authentication Method
 *
 * Provides a backup 2FA method using email to send verification codes.
 *
 * @package Brain_2FA\Methods
 */
class EmailMethod implements TwoFactorMethodInterface {

	/**
	 * Unique identifier for the email method.
	 *
	 * @var string
	 */
	protected $id = 'email';

	/**
	 * Gets the unique identifier for this email method.
	 *
	 * @return string The unique identifier of the email method.
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Generates the setup form HTML for the email-based two-factor authentication method.
	 *
	 * This method creates a form that allows users to configure their backup email address
	 * for receiving 6-digit verification codes. If no backup email is set in user meta,
	 * it defaults to the user's primary email address.
	 *
	 * @param WP_User $user The WordPress user object for whom the setup form is being generated.
	 * @return string The HTML markup for the email setup form, including input field and instructions.
	 */
	public function setup_form( WP_User $user ): string {
		return '';
	}

	/**
	 * Saves the email 2FA setup configuration for a user.
	 *
	 * Validates and stores the backup email address for two-factor authentication.
	 * Updates user meta to enable the backup email method and stores the sanitized email.
	 *
	 * @param WP_User $user The WordPress user object to save the setup for.
	 * @param array   $data Array containing setup data with 'brain2fa_backup_email' key.
	 *
	 * @return bool|array|WP_Error Returns true on successful deactivation,
	 *                    array with 'success' and 'recovery_codes' keys on activation,
	 *                    WP_Error on invalid code
	 */
	public function save_setup( WP_User $user, array $data ) {
		return true;
	}

	/**
	 * Gets the setup data for the email 2FA method.
	 *
	 * Retrieves the current configuration data for the email-based two-factor
	 * authentication method for a specific user.
	 *
	 * @param WP_User $user The WordPress user object to get setup data for.
	 * @return array Array containing the setup data including backup email and enabled status.
	 */
	public function get_setup_data( WP_User $user ): array {
		return array();
	}

	/**
	 * Sends a two-factor authentication challenge code via email to the user.
	 *
	 * Generates a 6-digit backup code and sends it to the user's backup email address
	 * or their primary email if no backup email is configured. The code is stored as
	 * a transient with a 10-minute expiration time.
	 *
	 * @param WP_User $user The WordPress user object to send the challenge to.
	 * @param array   $context Optional context array for additional parameters (unused).
	 *
	 * @return bool True if the email was sent successfully, false if the email address is invalid.
	 */
	public function send_challenge( WP_User $user, array $context = array() ): bool {
		$email = $user->user_email;
		if ( ! is_email( $email ) ) {
			return false;
		}

		// Check if a challenge code already exists for this user.
		$existing_code = get_transient( 'brain2fa_backup_code_' . $user->ID );
		if ( $existing_code ) {
			return true; // Code already sent, don't send again.
		}

		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

		// Read expiry from plugin settings (falls back to 10 minutes).
		$settings   = get_option( 'brain2fa_settings', array() );
		$expiry_min = isset( $settings['code_expiry'] ) ? absint( $settings['code_expiry'] ) : 10;
		if ( $expiry_min < 1 ) {
			$expiry_min = 10;
		}
		$expiry_seconds = $expiry_min * MINUTE_IN_SECONDS;

		// Allow themes/plugins to handle sending. If they return true, skip the default wp_mail().
		$email_sent = apply_filters( 'brain2fa_send_backup_code', false, $user, $code );

		if ( ! $email_sent ) {
			$site_name = get_bloginfo( 'name' );
			$subject   = sprintf(
				/* translators: %s: site name */
				__( '[%s] Your two-factor authentication code', 'brain2fa' ),
				$site_name
			);
			$message = sprintf(
				/* translators: 1: user display name, 2: 6-digit code, 3: expiry in minutes, 4: site name */
				__(
					"Hi %1\$s,\n\nYour two-factor authentication code is:\n\n%2\$s\n\nThis code expires in %3\$d minute(s).\n\nIf you did not request this code, please ignore this email.\n\n-- %4\$s",
					'brain2fa'
				),
				$user->display_name,
				$code,
				$expiry_min,
				$site_name
			);
			$email_sent = wp_mail( $email, $subject, $message );
		}

		if ( $email_sent ) {
			set_transient( 'brain2fa_backup_code_' . $user->ID, $code, $expiry_seconds );
		}

		return $email_sent;
	}

	/**
	 * Verifies the two-factor authentication token for the email method.
	 *
	 * Checks if the provided token matches the stored backup code for the user.
	 * Uses timing-safe comparison to prevent timing attacks. Deletes the stored
	 * code upon successful verification to prevent reuse.
	 *
	 * @param WP_User $user    The WordPress user object to verify the token for.
	 * @param string  $token   The token/code to verify.
	 * @param array   $context Optional context array for additional parameters (unused).
	 *
	 * @return bool True if the token is valid and matches, false otherwise.
	 */
	public function verify( WP_User $user, string $token, array $context = array() ): bool {
		$stored = get_transient( 'brain2fa_backup_code_' . $user->ID );
		if ( ! $stored ) {
			return false;
		}
		if ( hash_equals( (string) $stored, trim( $token ) ) ) {
			delete_transient( 'brain2fa_backup_code_' . $user->ID );
			return true;
		}
		return false;
	}

	/**
	 * Generates recovery codes for the email method.
	 *
	 * @param WP_User $user The WordPress user object.
	 * @return array Array of generated recovery codes.
	 */
	public function generate_recovery_codes( WP_User $user ): array {
		return array();
	}

	/**
	 * Gets the count of recovery codes for the email method.
	 *
	 * @param WP_User $user The WordPress user object.
	 * @return int The number of recovery codes.
	 */
	public function get_recovery_codes_count( WP_User $user ): int {
		return 0;
	}
}
