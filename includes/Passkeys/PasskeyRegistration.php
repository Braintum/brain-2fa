<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Passkey registration and management
 *
 * @package Brain_2FA\Passkeys
 * @since 1.1.0
 */

namespace Brain_2FA\Passkeys;

use Brain_2FA\Utils;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Passkey Registration
 *
 * Lets signed-in users add, rename and remove their own passkeys and turn
 * password sign-in off (passkey-only), and lets administrators revoke another
 * user's passkeys or restore their password sign-in.
 *
 * Adding or removing a passkey requires the account password: a passkey is a
 * permanent passwordless sign-in, so a hijacked session must not be able to
 * plant one. Each change emails the account owner.
 *
 * @since 1.1.0
 */
class PasskeyRegistration {

	/**
	 * Nonce action for the registration AJAX requests.
	 *
	 * @var string
	 */
	public const NONCE_ACTION = 'brain2fa_passkey_register';

	/**
	 * Maximum passkey name length.
	 *
	 * @var int
	 */
	private const MAX_NAME_LENGTH = 100;

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_brain2fa_passkey_register_options', array( __CLASS__, 'ajax_register_options' ) );
		add_action( 'wp_ajax_brain2fa_passkey_register_verify', array( __CLASS__, 'ajax_register_verify' ) );
	}

	/**
	 * AJAX: confirm the password and return registration options.
	 *
	 * @return void
	 */
	public static function ajax_register_options(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$password = isset( $_POST['password'] ) && is_string( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered before checking.
		$name     = isset( $_POST['name'] ) && is_string( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$result   = self::create_registration_options( wp_get_current_user(), $name, $password );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'options' => $result ) );
	}

	/**
	 * AJAX: verify the browser's registration response and store the passkey.
	 *
	 * @return void
	 */
	public static function ajax_register_verify(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$credential = isset( $_POST['credential'] ) && is_string( $_POST['credential'] ) ? json_decode( wp_unslash( $_POST['credential'] ), true ) : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON verified cryptographically; fields are decoded, never output.
		$result     = is_array( $credential ) ? self::verify_registration( wp_get_current_user(), $credential ) : new WP_Error( 'brain2fa_passkey_invalid', __( 'The passkey could not be verified. Please try again.', 'brain-2fa' ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}

		wp_send_json_success( array( 'id' => $result ) );
	}

	/**
	 * Confirm the password and create registration options for a user.
	 *
	 * @param WP_User $user     User adding a passkey.
	 * @param string  $name     Passkey name, already sanitized; a default is used when empty.
	 * @param string  $password Account password, unslashed and unsanitized.
	 * @return array|WP_Error WebAuthn creation options, or an error.
	 */
	public static function create_registration_options( WP_User $user, string $name, string $password ) {
		if ( ! $user->exists() || ! Utils::is_passkeys_enabled() || ! WebAuthnServer::is_available() ) {
			return new WP_Error( 'brain2fa_passkeys_unavailable', __( 'Passkeys are not available on this site.', 'brain-2fa' ) );
		}

		$confirmation = Utils::confirm_password( $user, $password );
		if ( is_wp_error( $confirmation ) ) {
			return $confirmation;
		}

		$name = '' !== $name ? mb_substr( $name, 0, self::MAX_NAME_LENGTH ) : sprintf(
			/* translators: %s: date the passkey was added */
			__( 'Passkey added %s', 'brain-2fa' ),
			wp_date( get_option( 'date_format' ) )
		);

		$challenge = WebAuthnServer::generate_challenge();
		set_transient(
			self::challenge_key( $user->ID ),
			array(
				'challenge' => WebAuthnServer::base64url_encode( $challenge ),
				'name'      => $name,
			),
			WebAuthnServer::TIMEOUT
		);

		return WebAuthnServer::get_creation_options(
			$user,
			PasskeyRepository::get_user_handle( $user->ID, true ),
			$challenge,
			wp_list_pluck( PasskeyRepository::get_for_user( $user->ID ), 'credential_id' )
		);
	}

	/**
	 * Verify a registration response and store the passkey.
	 *
	 * @param WP_User $user       User adding the passkey.
	 * @param array   $credential RegistrationResponseJSON from the browser.
	 * @return int|WP_Error New passkey ID, or an error.
	 */
	public static function verify_registration( WP_User $user, array $credential ) {
		$key     = self::challenge_key( $user->ID );
		$pending = get_transient( $key );

		// Only the request that deletes the challenge may use it.
		if ( ! is_array( $pending ) || ! delete_transient( $key ) ) {
			return new WP_Error( 'brain2fa_passkey_expired', __( 'The passkey request expired. Please try again.', 'brain-2fa' ) );
		}

		$data = WebAuthnServer::verify_registration( $credential, WebAuthnServer::base64url_decode( (string) $pending['challenge'] ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( null !== PasskeyRepository::find_by_credential_id( $data['credential_id'] ) ) {
			return new WP_Error( 'brain2fa_passkey_exists', __( 'This passkey is already registered.', 'brain-2fa' ) );
		}

		$data['name'] = (string) $pending['name'];
		$id           = PasskeyRepository::add( $user->ID, $data );
		if ( ! $id ) {
			return new WP_Error( 'brain2fa_passkey_save_failed', __( 'The passkey could not be saved. Please try again.', 'brain-2fa' ) );
		}

		// A passkey is a second factor, so it completes enrollment for required roles.
		Utils::clear_grace_period( $user->ID );

		Utils::send_factor_change_notification( $user, 'passkey_added', array( 'passkey_name' => $data['name'] ) );

		return $id;
	}

	/**
	 * Remove one of the user's passkeys after confirming their password.
	 *
	 * @param WP_User $user     Passkey owner.
	 * @param int     $id       Passkey ID.
	 * @param string  $password Account password, unslashed and unsanitized.
	 * @return true|WP_Error True when removed.
	 */
	public static function remove( WP_User $user, int $id, string $password ) {
		$confirmation = Utils::confirm_password( $user, $password );
		if ( is_wp_error( $confirmation ) ) {
			return $confirmation;
		}

		// Removing the last passkey of a passkey-only account would lock the user out.
		if ( Utils::is_passkey_only( $user ) && 1 === PasskeyRepository::count_for_user( $user->ID ) ) {
			return new WP_Error( 'brain2fa_passkey_last', __( 'This is your last passkey. Turn password sign-in back on before removing it.', 'brain-2fa' ) );
		}

		$passkey_name = '';
		foreach ( PasskeyRepository::get_for_user( $user->ID ) as $passkey ) {
			if ( (int) $passkey->id === $id ) {
				$passkey_name = (string) $passkey->name;
				break;
			}
		}

		if ( ! PasskeyRepository::delete( $id, $user->ID ) ) {
			return new WP_Error( 'brain2fa_passkey_not_found', __( 'That passkey was not found.', 'brain-2fa' ) );
		}

		Utils::send_factor_change_notification( $user, 'passkey_removed', array( 'passkey_name' => $passkey_name ) );
		self::restrict_if_unenrolled( $user );

		return true;
	}

	/**
	 * Turn password sign-in off (passkey-only) or back on for the user.
	 *
	 * Turning it off needs at least one passkey. Both directions need the account password.
	 *
	 * @param WP_User $user     User changing the setting.
	 * @param bool    $enable   True to turn password sign-in off, false to turn it back on.
	 * @param string  $password Account password, unslashed and unsanitized.
	 * @return true|WP_Error True when changed.
	 */
	public static function set_passkey_only( WP_User $user, bool $enable, string $password ) {
		if ( ! Utils::is_passkey_only_allowed() || ! PasskeyLogin::is_enabled() ) {
			return new WP_Error( 'brain2fa_passkeys_unavailable', __( 'Passkey-only sign-in is not available on this site.', 'brain-2fa' ) );
		}

		$confirmation = Utils::confirm_password( $user, $password );
		if ( is_wp_error( $confirmation ) ) {
			return $confirmation;
		}

		if ( $enable ) {
			if ( PasskeyRepository::count_for_user( $user->ID ) < 1 ) {
				return new WP_Error( 'brain2fa_passkey_required', __( 'Add a passkey before turning off password sign-in.', 'brain-2fa' ) );
			}

			update_user_meta( $user->ID, Utils::PASSKEY_ONLY_META_KEY, 1 );
			Utils::send_factor_change_notification( $user, 'passkey_only_enabled' );

			return true;
		}

		delete_user_meta( $user->ID, Utils::PASSKEY_ONLY_META_KEY );
		Utils::send_factor_change_notification( $user, 'passkey_only_disabled' );

		return true;
	}

	/**
	 * Turn password sign-in back on for a user, on an administrator's behalf.
	 *
	 * The caller must check capabilities and the nonce.
	 *
	 * @param WP_User $user User whose passkey-only setting is cleared.
	 * @return bool True when the setting was on and has been cleared.
	 */
	public static function restore_password_sign_in( WP_User $user ): bool {
		if ( empty( get_user_meta( $user->ID, Utils::PASSKEY_ONLY_META_KEY, true ) ) ) {
			return false;
		}

		delete_user_meta( $user->ID, Utils::PASSKEY_ONLY_META_KEY );
		Utils::send_factor_change_notification( $user, 'passkey_only_disabled' );

		return true;
	}

	/**
	 * Restrict a required-role user to setup when they no longer have any second factor.
	 *
	 * @param WP_User $user User who just lost a passkey.
	 * @return void
	 */
	private static function restrict_if_unenrolled( WP_User $user ): void {
		if ( Utils::is_2fa_required_for_user( $user ) && ! Utils::is_2fa_enabled_for_user( $user->ID ) ) {
			Utils::require_enrollment_now( $user );
		}
	}

	/**
	 * Rename one of the user's passkeys.
	 *
	 * @param WP_User $user Passkey owner.
	 * @param int     $id   Passkey ID.
	 * @param string  $name New name, already sanitized.
	 * @return true|WP_Error True when renamed.
	 */
	public static function rename( WP_User $user, int $id, string $name ) {
		$name = mb_substr( trim( $name ), 0, self::MAX_NAME_LENGTH );

		if ( '' === $name ) {
			return new WP_Error( 'brain2fa_passkey_name', __( 'Enter a name for the passkey.', 'brain-2fa' ) );
		}

		// update() reports 0 rows when the name is unchanged, so check ownership separately.
		$owned = in_array( $id, array_map( 'intval', wp_list_pluck( PasskeyRepository::get_for_user( $user->ID ), 'id' ) ), true );
		if ( ! $owned ) {
			return new WP_Error( 'brain2fa_passkey_not_found', __( 'That passkey was not found.', 'brain-2fa' ) );
		}

		PasskeyRepository::rename( $id, $user->ID, $name );

		return true;
	}

	/**
	 * Remove all of a user's passkeys on an administrator's behalf.
	 *
	 * The caller must check capabilities and the nonce.
	 *
	 * @param WP_User $user User whose passkeys are revoked.
	 * @return int Number of passkeys removed.
	 */
	public static function revoke_all( WP_User $user ): int {
		$removed = PasskeyRepository::delete_all_for_user( $user->ID );

		// Without passkeys, a passkey-only account must be able to use its password again.
		delete_user_meta( $user->ID, Utils::PASSKEY_ONLY_META_KEY );

		if ( $removed > 0 ) {
			Utils::send_factor_change_notification( $user, 'passkeys_revoked' );
			self::restrict_if_unenrolled( $user );
		}

		return $removed;
	}

	/**
	 * Get the transient key holding a user's pending registration challenge.
	 *
	 * @param int $user_id User ID.
	 * @return string Transient key.
	 */
	private static function challenge_key( int $user_id ): string {
		return 'brain2fa_webauthn_reg_' . $user_id;
	}
}
