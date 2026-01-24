<?php // phpcs:disable PSR1.Files.SideEffects

declare( strict_types=1 );

namespace Brain_2FA\TwoFactor\Methods;

use Brain_2FA\TwoFactor\Interfaces\TwoFactorMethodInterface;
use WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TOTP-based Two Factor Authentication Method
 *
 * Implements Time-based One-Time Password (TOTP) for two-factor authentication.
 *
 * @package Brain_2FA\Methods
 */
class TotpMethod implements TwoFactorMethodInterface {
	/**
	 * The unique identifier for this two-factor authentication method.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	protected $id = 'totp';

	/**
	 * Gets the unique identifier for this two-factor authentication method.
	 *
	 * Returns the string identifier used to distinguish this TOTP method
	 * from other two-factor authentication methods in the system.
	 *
	 * @return string The method identifier ('totp').
	 *
	 * @since 1.0.0
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * Generates the setup form HTML for TOTP two-factor authentication.
	 *
	 * Displays either a deactivation form if 2FA is already enabled, or a setup form
	 * with QR code and manual secret for new users. The setup form allows users to
	 * scan a QR code with their authenticator app and verify the setup with a code.
	 *
	 * @param WP_User $user The WordPress user object to generate the form for.
	 *
	 * @return string HTML content for the setup form
	 *
	 * @since 1.0.0
	 */
	public function setup_form( WP_User $user ): string {
		return '';
	}

	/**
	 * Retrieves the setup data required for TOTP 2FA configuration.
	 *
	 * Generates a new TOTP secret and corresponding QR code image for the user
	 * to scan with their authenticator app during the setup process.
	 *
	 * @param WP_User $user The WordPress user object to generate setup data for.
	 *
	 * @return array An associative array containing:
	 *               - 'secret': The TOTP secret key.
	 *               - 'qr_image': Data URI of the QR code image.
	 *
	 * @since 1.0.0
	 */
	public function get_setup_data( WP_User $user ): array {
		// Show setup form for new users.
		$secret = get_user_meta( $user->ID, 'brain2fa_secret', true );
		if ( ! $secret ) {
			if ( class_exists( '\OTPHP\\TOTP' ) ) {
				$t      = \OTPHP\TOTP::create();
				$secret = $t->getSecret();
			} else {
				$secret = $this->random_base32( 16 );
			}
		}

		$issuer = get_bloginfo( 'name' );
		if ( ! empty( $user->user_email ) ) {
			$account = $user->user_email;
		} else {
			$account = $user->user_login;
		}
		$uri = sprintf(
			'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
			rawurlencode( $issuer ),
			rawurlencode( $account ),
			rawurlencode( $secret ),
			rawurlencode( $issuer )
		);

		// Generate QR code using endroid/qr-code.
		$qr_data_uri = $this->generate_qr_code( $uri );
		return array(
			'secret'   => $secret,
			'qr_image' => $qr_data_uri,
		);
	}

	/**
	 * Saves the TOTP 2FA setup configuration for a user.
	 *
	 * Handles both activation and deactivation of TOTP 2FA for a user account.
	 * When activating, validates the provided secret and verification code before
	 * storing the configuration. When deactivating, removes all stored 2FA data.
	 *
	 * @param WP_User $user The WordPress user object to configure 2FA for.
	 * @param array   $data Setup data containing:
	 *                      - 'brain2fa_deactivate' (optional): If present, deactivates 2FA.
	 *                      - 'brain2fa_secret' (string): The TOTP secret key for activation.
	 *                      - 'brain2fa_code' (string): The verification code to validate setup.
	 *
	 * @return bool|WP_Error Returns true on successful setup/deactivation,
	 *                       WP_Error with 'missing' code if required data is missing,
	 *                       WP_Error with 'invalid' code if verification fails
	 *
	 * @since 1.0.0
	 */
	public function save_setup( WP_User $user, array $data ) {

		if ( ! empty( $data['brain2fa_deactivate'] ) ) {
			delete_user_meta( $user->ID, 'brain2fa_secret' );
			delete_user_meta( $user->ID, 'brain2fa_enabled' );
			return true;
		}

		$secret = sanitize_text_field( $data['secret'] );
		$code   = preg_replace( '/[^0-9]/', '', $data['code'] );
		if ( $this->verify( $user, $code, array( 'secret' => $secret ) ) ) {
			update_user_meta( $user->ID, 'brain2fa_secret', $secret );
			update_user_meta( $user->ID, 'brain2fa_enabled', 1 );
			return true;
		}
		return new \WP_Error( 'invalid', 'Invalid code' );
	}

	/**
	 * Sends a TOTP challenge to the user.
	 *
	 * Since TOTP (Time-based One-Time Password) is generated locally on the user's device,
	 * there is no actual challenge to send. This method always returns true to indicate
	 * that the challenge process is complete.
	 *
	 * @param WP_User $user The WordPress user object for whom the challenge is being sent.
	 * @param array   $context Optional context data that may be used by other authentication methods.
	 *
	 * @return bool Always returns true as TOTP doesn't require server-side challenge sending.
	 */
	public function send_challenge( WP_User $user, array $context = array() ): bool {
		// TOTP is generated on device; nothing to send.
		return true;
	}

	/**
	 * Verifies a TOTP token for the given user.
	 *
	 * Validates a time-based one-time password token against the user's stored secret.
	 * Uses the OTPHP library if available, otherwise falls back to custom implementation.
	 *
	 * @param WP_User $user    The WordPress user object to verify the token for.
	 * @param string  $token   The TOTP token to verify.
	 * @param array   $context Optional context data that may contain a 'secret' key.
	 *
	 * @return bool True if the token is valid, false otherwise.
	 *
	 * @since 1.0.0
	 */
	public function verify( WP_User $user, string $token, array $context = array() ): bool {
		$secret = $context['secret'] ?? get_user_meta( $user->ID, 'brain2fa_secret', true );
		if ( ! $secret ) {
			return false;
		}

		if ( class_exists( '\OTPHP\\TOTP' ) ) {
			$t = \OTPHP\TOTP::create( $secret );
			return hash_equals( $t->now(), $token );
		}

		return $this->fallback_verify( $secret, $token );
	}

	/**
	 * Verifies a TOTP token using fallback implementation.
	 *
	 * When the OTPHP library is not available, this method provides a fallback
	 * verification using a custom HOTP implementation with time-based counter.
	 * Allows for a 30-second window tolerance (±1 time slice) to account for
	 * clock drift between client and server.
	 *
	 * @param string $secret The base32-encoded TOTP secret key.
	 * @param string $token  The TOTP token to verify.
	 *
	 * @return bool True if the token is valid within the time window, false otherwise.
	 *
	 * @since 1.0.0
	 */
	protected function fallback_verify( $secret, $token ) {
		$time_slice = floor( time() / 30 );
		for ( $i = -1; $i <= 1; $i++ ) {
			if ( $this->hotp( $secret, $time_slice + $i ) === $token ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Generates a QR code data URI for TOTP setup.
	 *
	 * Attempts to use the Endroid QR Code library to generate a QR code image.
	 * Falls back to Google Charts API if the library is unavailable or fails.
	 *
	 * @param string $data The data to encode in the QR code (typically otpauth URI).
	 *
	 * @return string Data URI of the QR code image or Google Charts API URL.
	 *
	 * @since 1.0.0
	 */
	protected function generate_qr_code( $data ): string {
		try {
			if ( class_exists( '\Endroid\QrCode\Writer\PngWriter' ) && class_exists( '\Endroid\QrCode\QrCode' ) ) {
				$writer  = new \Endroid\QrCode\Writer\PngWriter();
				$qr_code = \Endroid\QrCode\QrCode::create( $data )
					->setSize( 300 )
					->setMargin( 5 );

				// Add logo to the center of the QR code.
				$logo_path = BRAIN_2FA_PLUGIN_DIR . 'assets/images/logo.png';
				if ( file_exists( $logo_path ) && class_exists( '\Endroid\QrCode\Logo\Logo' ) ) {
					$logo = \Endroid\QrCode\Logo\Logo::create( $logo_path )
						->setResizeToWidth( 60 ) // Logo width (20% of QR code size).
						->setPunchoutBackground( true ); // Add white background behind logo for better contrast.
				} else {
					$logo = null;
				}

				$result = $writer->write( $qr_code, $logo );
				return $result->getDataUri();
			}
		} catch ( \Exception $e ) {
			// Log error for debugging if needed.
			error_log( 'Brain 2FA QR Code generation failed: ' . $e->getMessage() );
		}

		// Fallback to Google Charts API (legacy, but still works).
		return 'https://quickchart.io/qr?size=200x200&text=' . rawurlencode( $data );
	}

	/**
	 * Generates a random base32 string.
	 *
	 * Creates a cryptographically secure random string using the base32 alphabet.
	 * Used for generating TOTP secret keys when the OTPHP library is not available.
	 *
	 * @param int $length The length of the base32 string to generate. Default is 16.
	 *
	 * @return string A random base32-encoded string.
	 *
	 * @since 1.0.0
	 */
	protected function random_base32( $length = 16 ) {
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$s     = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$s .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $s;
	}

	/**
	 * Decodes a Base32 encoded string to binary data.
	 *
	 * This method converts a Base32 encoded string (RFC 4648) back to its original
	 * binary representation. Base32 encoding uses a 32-character alphabet consisting
	 * of uppercase letters A-Z and digits 2-7.
	 *
	 * @param string $b32 The Base32 encoded string to decode.
	 *
	 * @return string The decoded binary data as a string.
	 *
	 * @since 1.0.0
	 */
	protected function base32_decode( $b32 ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$b32      = strtoupper( $b32 );
		$l        = strlen( $b32 );
		$n        = 0;
		$j        = 0;
		$binary   = '';
		for ( $i = 0; $i < $l; $i++ ) {
			$n = ( $n << 5 ) + strpos( $alphabet, $b32[ $i ] );
			$j += 5;
			if ( $j >= 8 ) {
				$j      -= 8;
				$binary .= chr( ( $n & ( 0xFF << $j ) ) >> $j );
			}
		}
		return $binary;
	}

	/**
	 * Generates an HOTP (HMAC-based One-Time Password) token.
	 *
	 * Implements the HOTP algorithm as defined in RFC 4226. Uses HMAC-SHA1
	 * to generate a one-time password based on a shared secret and counter value.
	 *
	 * @param string $secret The base32-encoded shared secret key.
	 * @param int    $counter The counter value for HOTP generation.
	 * @param int    $digits The number of digits in the generated token. Default 6.
	 *
	 * @return string The generated HOTP token, zero-padded to specified digits.
	 *
	 * @since 1.0.0
	 */
	protected function hotp( $secret, $counter, $digits = 6 ) {
		$key           = $this->base32_decode( $secret );
		$counter_bytes = pack( 'N*', 0 ) . pack( 'N*', $counter );
		$hash          = hash_hmac( 'sha1', $counter_bytes, $key, true );
		$offset        = ord( $hash[19] ) & 0xf;
		$truncated     = substr( $hash, $offset, 4 );
		$value         = unpack( 'N', $truncated )[1] & 0x7fffffff;
		$mod           = pow( 10, $digits );
		return (string) str_pad( (string) ( $value % $mod ), $digits, '0', STR_PAD_LEFT );
	}
}
