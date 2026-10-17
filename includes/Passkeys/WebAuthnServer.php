<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * WebAuthn server wrapper
 *
 * @package Brain_2FA\Passkeys
 * @since 1.1.0
 */

namespace Brain_2FA\Passkeys;

use lbuchs\WebAuthn\WebAuthn;
use lbuchs\WebAuthn\WebAuthnException;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * WebAuthn Server
 *
 * Builds WebAuthn options for the browser and verifies its responses. The
 * lbuchs/webauthn library performs the cryptographic checks; this class adds
 * an exact origin check (the library's origin check accepts any host that
 * merely ends with the RP ID) and keeps the library swappable.
 *
 * Passkeys always require user verification (biometric or PIN), so a passkey
 * sign-in is multi-factor on its own.
 *
 * @since 1.1.0
 */
class WebAuthnServer {

	/**
	 * Ceremony timeout in seconds, also the challenge lifetime.
	 *
	 * @var int
	 */
	public const TIMEOUT = 300;

	/**
	 * Transports the browser may report for a credential.
	 *
	 * @var string[]
	 */
	private const KNOWN_TRANSPORTS = array( 'usb', 'nfc', 'ble', 'hybrid', 'internal', 'smart-card' );

	/**
	 * Get the relying party ID (the site's host name).
	 *
	 * @return string RP ID.
	 */
	public static function get_rp_id(): string {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Get the exact origins passkey ceremonies may come from.
	 *
	 * @return string[] Origins such as https://example.com.
	 */
	public static function get_allowed_origins(): array {
		$origins = array();

		foreach ( array( home_url(), site_url() ) as $url ) {
			$parts = wp_parse_url( $url );
			if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				continue;
			}

			$origin = strtolower( $parts['scheme'] . '://' . $parts['host'] );
			if ( ! empty( $parts['port'] ) ) {
				$origin .= ':' . (int) $parts['port'];
			}
			$origins[] = $origin;
		}

		/**
		 * Filter the origins accepted for passkey registration and sign-in.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $origins Exact origins (scheme, host and optional port).
		 */
		return array_values( array_unique( (array) apply_filters( 'brain2fa_passkey_allowed_origins', $origins ) ) );
	}

	/**
	 * Check whether passkeys can work on this site.
	 *
	 * Browsers only allow WebAuthn in a secure context: HTTPS, or localhost.
	 *
	 * @return bool True when the site is served over HTTPS (or is localhost).
	 */
	public static function is_available(): bool {
		if ( 'localhost' === self::get_rp_id() ) {
			return true;
		}

		foreach ( array( home_url(), site_url() ) as $url ) {
			if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Generate a random challenge.
	 *
	 * @return string 32 random bytes.
	 */
	public static function generate_challenge(): string {
		return random_bytes( 32 );
	}

	/**
	 * Build PublicKeyCredentialCreationOptions for registering a passkey.
	 *
	 * @param WP_User  $user        User registering the passkey.
	 * @param string   $user_handle Binary WebAuthn user handle.
	 * @param string   $challenge   Binary challenge.
	 * @param string[] $exclude_ids Base64url IDs of the user's existing passkeys.
	 * @return array Options in WebAuthn JSON form.
	 */
	public static function get_creation_options( WP_User $user, string $user_handle, string $challenge, array $exclude_ids ): array {
		$algorithms = array( -7, -257 ); // ES256, RS256.
		if ( function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			array_unshift( $algorithms, -8 ); // EdDSA.
		}

		return array(
			'rp'                     => array(
				'name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'id'   => self::get_rp_id(),
			),
			'user'                   => array(
				'id'          => self::base64url_encode( $user_handle ),
				'name'        => $user->user_login,
				'displayName' => $user->display_name,
			),
			'challenge'              => self::base64url_encode( $challenge ),
			'pubKeyCredParams'       => array_map(
				static fn( int $alg ) => array(
					'type' => 'public-key',
					'alg'  => $alg,
				),
				$algorithms
			),
			'timeout'                => self::TIMEOUT * 1000,
			'excludeCredentials'     => array_map(
				static fn( string $id ) => array(
					'type' => 'public-key',
					'id'   => $id,
				),
				$exclude_ids
			),
			'authenticatorSelection' => array(
				'residentKey'        => 'required',
				'requireResidentKey' => true,
				'userVerification'   => 'required',
			),
			'attestation'            => 'none',
		);
	}

	/**
	 * Build PublicKeyCredentialRequestOptions for a passwordless sign-in.
	 *
	 * The allowCredentials list is empty, so the browser offers any passkey the
	 * user has for this site (discoverable credentials).
	 *
	 * @param string $challenge Binary challenge.
	 * @return array Options in WebAuthn JSON form.
	 */
	public static function get_request_options( string $challenge ): array {
		return array(
			'challenge'        => self::base64url_encode( $challenge ),
			'timeout'          => self::TIMEOUT * 1000,
			'rpId'             => self::get_rp_id(),
			'allowCredentials' => array(),
			'userVerification' => 'required',
		);
	}

	/**
	 * Verify a registration response from the browser.
	 *
	 * @param array  $credential RegistrationResponseJSON from the browser.
	 * @param string $challenge  Binary challenge issued for this ceremony.
	 * @return array|WP_Error Credential data to store, or an error.
	 */
	public static function verify_registration( array $credential, string $challenge ) {
		$client_data_json = self::base64url_decode( (string) ( $credential['response']['clientDataJSON'] ?? '' ) );
		$attestation      = self::base64url_decode( (string) ( $credential['response']['attestationObject'] ?? '' ) );

		$client_data_check = self::check_client_data( $client_data_json, 'webauthn.create' );
		if ( is_wp_error( $client_data_check ) ) {
			return $client_data_check;
		}

		try {
			// User verification required; attestation is not used for trust decisions.
			$data = self::library()->processCreate( $client_data_json, $attestation, $challenge, true, true, false, false );
		} catch ( WebAuthnException $e ) {
			return new WP_Error( 'brain2fa_passkey_invalid', __( 'The passkey could not be verified. Please try again.', 'brain-2fa' ) );
		}

		// The library returns camelCase properties.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$credential_id = self::base64url_encode( (string) $data->credentialId );
		if ( isset( $credential['id'] ) && ! hash_equals( $credential_id, (string) $credential['id'] ) ) {
			return new WP_Error( 'brain2fa_passkey_invalid', __( 'The passkey could not be verified. Please try again.', 'brain-2fa' ) );
		}

		$transports = isset( $credential['response']['transports'] ) && is_array( $credential['response']['transports'] ) ? $credential['response']['transports'] : array();

		return array(
			'credential_id'   => $credential_id,
			'public_key'      => (string) $data->credentialPublicKey,
			'sign_count'      => (int) $data->signatureCounter,
			'transports'      => array_values( array_intersect( self::KNOWN_TRANSPORTS, array_map( 'strval', $transports ) ) ),
			'aaguid'          => self::format_aaguid( (string) $data->AAGUID ),
			'backup_eligible' => (bool) $data->isBackupEligible,
			'backed_up'       => (bool) $data->isBackedUp,
		);
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	/**
	 * Verify a sign-in (assertion) response from the browser.
	 *
	 * @param array  $credential AuthenticationResponseJSON from the browser.
	 * @param string $challenge  Binary challenge issued for this ceremony.
	 * @param object $passkey    Stored passkey row for the credential ID.
	 * @return array|WP_Error New 'sign_count' and 'backed_up' values, or an error.
	 *                        The error code is 'brain2fa_passkey_cloned' when the
	 *                        signature counter went backwards.
	 */
	public static function verify_assertion( array $credential, string $challenge, object $passkey ) {
		$client_data_json   = self::base64url_decode( (string) ( $credential['response']['clientDataJSON'] ?? '' ) );
		$authenticator_data = self::base64url_decode( (string) ( $credential['response']['authenticatorData'] ?? '' ) );
		$signature          = self::base64url_decode( (string) ( $credential['response']['signature'] ?? '' ) );

		$client_data_check = self::check_client_data( $client_data_json, 'webauthn.get' );
		if ( is_wp_error( $client_data_check ) ) {
			return $client_data_check;
		}

		$library = self::library();

		try {
			$library->processGet( $client_data_json, $authenticator_data, $signature, (string) $passkey->public_key, $challenge, (int) $passkey->sign_count, true, true );
		} catch ( WebAuthnException $e ) {
			if ( WebAuthnException::SIGNATURE_COUNTER === $e->getCode() ) {
				return new WP_Error( 'brain2fa_passkey_cloned', __( 'This passkey was blocked because it may have been copied. Use another sign-in method and contact the site administrator.', 'brain-2fa' ) );
			}

			return new WP_Error( 'brain2fa_passkey_invalid', __( 'The passkey could not be verified. Please try again.', 'brain-2fa' ) );
		}

		// Flags byte follows the 32-byte RP ID hash; 0x10 is "backup state" (synced passkey).
		$flags = strlen( $authenticator_data ) > 32 ? ord( $authenticator_data[32] ) : 0;

		return array(
			'sign_count' => (int) $library->getSignatureCounter(),
			'backed_up'  => (bool) ( $flags & 0x10 ),
		);
	}

	/**
	 * Check the client data type and require an exact origin match.
	 *
	 * @param string $client_data_json Raw clientDataJSON.
	 * @param string $type             Expected type: webauthn.create or webauthn.get.
	 * @return true|WP_Error True when valid.
	 */
	private static function check_client_data( string $client_data_json, string $type ) {
		$client_data = json_decode( $client_data_json, true );

		if ( ! is_array( $client_data ) || ( $client_data['type'] ?? '' ) !== $type ) {
			return new WP_Error( 'brain2fa_passkey_invalid', __( 'The passkey could not be verified. Please try again.', 'brain-2fa' ) );
		}

		$origin = strtolower( (string) ( $client_data['origin'] ?? '' ) );
		if ( ! in_array( $origin, self::get_allowed_origins(), true ) || ! empty( $client_data['crossOrigin'] ) ) {
			return new WP_Error( 'brain2fa_passkey_origin', __( 'This passkey request came from an unexpected website.', 'brain-2fa' ) );
		}

		return true;
	}

	/**
	 * Create the WebAuthn library instance.
	 *
	 * @return WebAuthn Library configured for this site, with base64url JSON encoding.
	 */
	private static function library(): WebAuthn {
		return new WebAuthn( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), self::get_rp_id(), null, true );
	}

	/**
	 * Format a binary AAGUID as a UUID string.
	 *
	 * @param string $aaguid 16 binary bytes.
	 * @return string UUID, or an empty string for an invalid value.
	 */
	private static function format_aaguid( string $aaguid ): string {
		if ( 16 !== strlen( $aaguid ) ) {
			return '';
		}

		$hex = bin2hex( $aaguid );

		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/**
	 * Encode binary data as base64url without padding.
	 *
	 * @param string $data Binary data.
	 * @return string Base64url string.
	 */
	public static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- WebAuthn uses base64url encoding.
	}

	/**
	 * Decode a base64url string.
	 *
	 * @param string $data Base64url string, with or without padding.
	 * @return string Binary data, or an empty string when invalid.
	 */
	public static function base64url_decode( string $data ): string {
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- WebAuthn uses base64url encoding.

		return false === $decoded ? '' : $decoded;
	}
}
