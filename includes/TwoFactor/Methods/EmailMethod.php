<?php // phpcs:disable PSR1.Files.SideEffects

declare( strict_types=1 );

namespace Brain_2FA\TwoFactor\Methods;

use Brain_2FA\Emails\Mailer;
use Brain_2FA\Utils;
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
	 * Activation is a two-step process so email 2FA is only enabled once delivery
	 * works: without a code, a verification code is emailed; with a code, the code
	 * is verified and email authentication is enabled.
	 *
	 * @param WP_User $user The WordPress user object to save the setup for.
	 * @param array   $data Setup data: 'brain2fa_deactivate' to deactivate, or 'code' to verify.
	 *
	 * @return bool|array|WP_Error True on deactivation or successful activation,
	 *                             array with 'code_sent' => true after emailing a code,
	 *                             WP_Error when the email cannot be sent or the code is invalid.
	 */
	public function save_setup( WP_User $user, array $data ) {
		if ( ! empty( $data['brain2fa_deactivate'] ) ) {
			delete_user_meta( $user->ID, 'brain2fa_enabled' );
			Utils::forget_remembered_devices( $user->ID );
			return true;
		}

		if ( ! is_email( $user->user_email ) ) {
			return new WP_Error( 'invalid_email', __( 'A valid account email address is required for email authentication.', 'brain-2fa' ) );
		}

		$code = isset( $data['code'] ) ? preg_replace( '/[^0-9]/', '', (string) $data['code'] ) : '';

		if ( '' === $code ) {
			if ( ! $this->send_challenge( $user ) ) {
				return new WP_Error( 'email_send_failed', __( 'The verification email could not be sent. Check the site\'s email settings or contact an administrator.', 'brain-2fa' ) );
			}

			return array( 'code_sent' => true );
		}

		if ( ! $this->verify( $user, $code ) ) {
			return new WP_Error( 'invalid_code', __( 'Invalid or expired verification code.', 'brain-2fa' ) );
		}

		update_user_meta( $user->ID, 'brain2fa_enabled', 1 );

		return true;
	}

	/**
	 * Check whether an unused verification code is waiting for the user.
	 *
	 * @param WP_User $user User to check.
	 * @return bool True when a code has been sent and has not expired or been used.
	 *
	 * @since 1.0.4
	 */
	public function has_pending_code( WP_User $user ): bool {
		return '' !== $this->get_pending_hash( $user );
	}

	/**
	 * Discard the user's pending verification code so a new one can be sent.
	 *
	 * @param WP_User $user User whose code is discarded.
	 * @return void
	 *
	 * @since 1.0.4
	 */
	public function clear_pending_code( WP_User $user ): void {
		delete_transient( 'brain2fa_backup_code_' . $user->ID );
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
		return array(
			'email' => $user->user_email,
		);
	}

	/**
	 * Sends a two-factor authentication challenge code via email to the user.
	 *
	 * Generates a 6-digit backup code and sends it to the user's backup email address
	 * or their primary email if no backup email is configured. Only a keyed hash of the
	 * code is stored, as a transient that expires after the configured code lifetime.
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
		if ( $this->has_pending_code( $user ) ) {
			return true; // Code already sent, don't send again.
		}

		$code = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );

		// Read expiry from plugin settings (falls back to 10 minutes).
		$settings   = Utils::get_settings();
		$expiry_min = absint( $settings['code_expiry'] );
		if ( $expiry_min < 1 ) {
			$expiry_min = 10;
		}
		$expiry_seconds = $expiry_min * MINUTE_IN_SECONDS;

		// Allow themes/plugins to handle sending. If they return true, skip the default wp_mail().
		$email_sent = apply_filters( 'brain2fa_send_backup_code', false, $user, $code );

		if ( ! $email_sent ) {
			$email_sent = Mailer::send(
				'sign_in_code',
				$user,
				array(
					'code'           => $code,
					'expiry_minutes' => $expiry_min,
				)
			);
		}

		if ( $email_sent ) {
			set_transient( 'brain2fa_backup_code_' . $user->ID, array( 'hash' => $this->hash_code( $user, $code ) ), $expiry_seconds );
		}

		return $email_sent;
	}

	/**
	 * Get the hash of the user's pending code.
	 *
	 * A plaintext code stored by version 1.1.0 or earlier is discarded, so the next
	 * request sends a new code instead of keeping the plaintext one usable.
	 *
	 * @param WP_User $user User whose code is read.
	 * @return string Hash, or an empty string when no code is pending.
	 *
	 * @since 1.2.0
	 */
	private function get_pending_hash( WP_User $user ): string {
		$stored = get_transient( 'brain2fa_backup_code_' . $user->ID );
		if ( false === $stored ) {
			return '';
		}

		if ( ! is_array( $stored ) || empty( $stored['hash'] ) || ! is_string( $stored['hash'] ) ) {
			$this->clear_pending_code( $user );
			return '';
		}

		return $stored['hash'];
	}

	/**
	 * Hash a code for storage.
	 *
	 * Six digits allow only a million codes, so a plain or password hash could be reversed
	 * by trying them all. The HMAC key comes from the site's salts, which a database-only
	 * leak does not include when they are defined in wp-config.php.
	 *
	 * @param WP_User $user User the code belongs to.
	 * @param string  $code The code.
	 * @return string Hex HMAC-SHA256.
	 *
	 * @since 1.2.0
	 */
	private function hash_code( WP_User $user, string $code ): string {
		return hash_hmac( 'sha256', $user->ID . '|' . $code, wp_salt( 'auth' ) );
	}

	/**
	 * Verifies the two-factor authentication token for the email method.
	 *
	 * Checks if the provided token's hash matches the stored hash for the user.
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
		$stored = $this->get_pending_hash( $user );
		if ( '' === $stored ) {
			return false;
		}
		if ( hash_equals( $stored, $this->hash_code( $user, trim( $token ) ) ) ) {
			// Only the request that actually deletes the code may use it, so concurrent requests cannot both succeed.
			return delete_transient( 'brain2fa_backup_code_' . $user->ID );
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
