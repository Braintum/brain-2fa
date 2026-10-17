<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Passwordless sign-in with passkeys
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
 * Passkey Login
 *
 * Flow:
 * 1. The login page requests options (brain2fa_passkey_auth_options). A random
 *    session value is set in an httponly cookie and the challenge is stored
 *    under its hash, so the challenge only works in this browser.
 * 2. The browser signs the challenge; brain2fa_passkey_auth_verify checks the
 *    assertion and returns a single-use login token, also bound to the cookie.
 * 3. The login form is submitted normally with the token. authenticate_with_token()
 *    (authenticate filter, priority 15) turns the token into the user, so
 *    wp_signon(), redirects, remember-me and other plugins behave as for a
 *    password login. verify_2fa() skips the code step for passkey sign-ins,
 *    because a passkey with user verification is multi-factor on its own.
 *
 * @since 1.1.0
 */
class PasskeyLogin {

	/**
	 * Login token lifetime in seconds.
	 *
	 * @var int
	 */
	private const TOKEN_TTL = 120;

	/**
	 * Options requests allowed per IP address per minute.
	 *
	 * @var int
	 */
	private const OPTIONS_PER_MINUTE = 30;

	/**
	 * ID of the user authenticated by passkey in this request, or 0.
	 *
	 * @var int
	 */
	private static $authenticated_user_id = 0;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		foreach ( array( 'wp_ajax_nopriv_', 'wp_ajax_' ) as $prefix ) {
			add_action( $prefix . 'brain2fa_passkey_auth_options', array( __CLASS__, 'ajax_auth_options' ) );
			add_action( $prefix . 'brain2fa_passkey_auth_verify', array( __CLASS__, 'ajax_auth_verify' ) );
		}

		add_filter( 'authenticate', array( __CLASS__, 'authenticate_with_token' ), 15, 3 );
	}

	/**
	 * AJAX: create a sign-in challenge.
	 *
	 * No nonce: the login page may be cached, and the endpoint only issues a
	 * random challenge bound to this browser's cookie.
	 *
	 * @return void
	 */
	public static function ajax_auth_options(): void {
		if ( ! self::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Passkeys are not available on this site.', 'brain-2fa' ) ), 400 );
		}

		if ( ! self::allow_options_request() ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again in a minute.', 'brain-2fa' ) ), 429 );
		}

		$session = wp_generate_password( 43, false, false );
		self::set_session_cookie( $session, time() + WebAuthnServer::TIMEOUT );

		wp_send_json_success( array( 'options' => self::create_authentication_options( $session ) ) );
	}

	/**
	 * AJAX: verify a passkey assertion and return a login token.
	 *
	 * @return void
	 */
	public static function ajax_auth_verify(): void {
		if ( ! self::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Passkeys are not available on this site.', 'brain-2fa' ) ), 400 );
		}

		$credential = isset( $_POST['credential'] ) && is_string( $_POST['credential'] ) ? json_decode( wp_unslash( $_POST['credential'] ), true ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Bound to the session cookie and verified cryptographically.
		$result     = self::verify_authentication( is_array( $credential ) ? $credential : array(), self::get_session_cookie() );

		if ( is_wp_error( $result ) ) {
			$status = 'brain2fa_too_many_attempts' === $result->get_error_code() ? 429 : 400;
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $status );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Create sign-in options and store the challenge for a browser session.
	 *
	 * @param string $session Random session value from the browser's cookie.
	 * @return array WebAuthn request options.
	 */
	public static function create_authentication_options( string $session ): array {
		$challenge = WebAuthnServer::generate_challenge();
		set_transient( self::challenge_key( $session ), WebAuthnServer::base64url_encode( $challenge ), WebAuthnServer::TIMEOUT );

		return WebAuthnServer::get_request_options( $challenge );
	}

	/**
	 * Verify a passkey assertion for a browser session.
	 *
	 * @param array  $credential AuthenticationResponseJSON from the browser.
	 * @param string $session    Session value from the browser's cookie.
	 * @return array|WP_Error Array with 'token' and 'login' on success, or an error.
	 */
	public static function verify_authentication( array $credential, string $session ) {
		$locked_for = Utils::get_ip_lockout();
		if ( $locked_for > 0 ) {
			return new WP_Error( 'brain2fa_too_many_attempts', Utils::get_lockout_message( $locked_for ) );
		}

		$key       = self::challenge_key( $session );
		$challenge = '' !== $session ? get_transient( $key ) : false;

		// Only the request that deletes the challenge may use it.
		if ( ! is_string( $challenge ) || ! delete_transient( $key ) ) {
			return new WP_Error( 'brain2fa_passkey_expired', __( 'The passkey request expired. Please try again.', 'brain-2fa' ) );
		}

		$credential_id = isset( $credential['id'] ) && is_string( $credential['id'] ) ? $credential['id'] : '';
		$passkey       = '' !== $credential_id ? PasskeyRepository::find_by_credential_id( $credential_id ) : null;
		$user          = $passkey ? get_userdata( (int) $passkey->user_id ) : false;

		if ( ! $passkey || ! $user instanceof WP_User ) {
			Utils::record_failed_ip_attempt();
			return new WP_Error( 'brain2fa_passkey_unknown', __( 'This passkey is not registered on this site. It may have been removed.', 'brain-2fa' ) );
		}

		// The user handle returned by the authenticator must belong to the passkey's owner.
		$user_handle = WebAuthnServer::base64url_decode( (string) ( $credential['response']['userHandle'] ?? '' ) );
		$owner       = PasskeyRepository::get_user_handle( $user->ID );
		if ( '' === $owner || ! hash_equals( $owner, $user_handle ) ) {
			Utils::record_failed_ip_attempt();
			return new WP_Error( 'brain2fa_passkey_invalid', __( 'The passkey could not be verified. Please try again.', 'brain-2fa' ) );
		}

		$verified = WebAuthnServer::verify_assertion( $credential, WebAuthnServer::base64url_decode( $challenge ), $passkey );
		if ( is_wp_error( $verified ) ) {
			Utils::record_failed_ip_attempt();
			if ( 'brain2fa_passkey_cloned' === $verified->get_error_code() ) {
				Utils::send_factor_change_notification( $user, 'passkey_clone_warning' );
			}
			return $verified;
		}

		PasskeyRepository::record_use( (int) $passkey->id, $verified['sign_count'], $verified['backed_up'] );

		$token = wp_generate_password( 43, false, false );
		set_transient(
			self::token_key( $token ),
			array(
				'user_id' => $user->ID,
				'session' => hash( 'sha256', $session ),
			),
			self::TOKEN_TTL
		);

		return array(
			'token' => $token,
			'login' => $user->user_login,
		);
	}

	/**
	 * Authenticate filter: sign in with a passkey login token.
	 *
	 * Runs before WordPress's username/password check (priority 20), which would
	 * otherwise reject the empty password.
	 *
	 * @param WP_User|WP_Error|null $user     Result of earlier authentication filters.
	 * @param string                $username Submitted username.
	 * @param string                $password Submitted password.
	 * @return WP_User|WP_Error|null The passkey's user, an error, or the unchanged input.
	 */
	public static function authenticate_with_token( $user, $username, $password ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Read-only check; the token itself is the proof, bound to the session cookie.
		$token = isset( $_POST['brain2fa_passkey_token'] ) && is_string( $_POST['brain2fa_passkey_token'] ) ? sanitize_text_field( wp_unslash( $_POST['brain2fa_passkey_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $user instanceof WP_User || '' === $token || ! self::is_enabled() || self::is_non_interactive_request() ) {
			return $user;
		}

		$key  = self::token_key( $token );
		$data = get_transient( $key );

		// Single use: only the request that deletes the token may sign in with it.
		if ( ! is_array( $data ) || ! delete_transient( $key ) ) {
			return new WP_Error( 'brain2fa_passkey_expired', __( 'Your passkey sign-in expired. Please try again.', 'brain-2fa' ) );
		}

		$session = self::get_session_cookie();
		if ( '' === $session || ! hash_equals( (string) $data['session'], hash( 'sha256', $session ) ) ) {
			return new WP_Error( 'brain2fa_passkey_expired', __( 'Your passkey sign-in expired. Please try again.', 'brain-2fa' ) );
		}

		$passkey_user = get_userdata( (int) $data['user_id'] );
		if ( ! $passkey_user instanceof WP_User ) {
			return new WP_Error( 'brain2fa_passkey_expired', __( 'Your passkey sign-in expired. Please try again.', 'brain-2fa' ) );
		}

		self::$authenticated_user_id = $passkey_user->ID;
		self::set_session_cookie( '', time() - HOUR_IN_SECONDS );

		return $passkey_user;
	}

	/**
	 * Check whether a user signed in with a passkey during this request.
	 *
	 * @param WP_User $user User being authenticated.
	 * @return bool True for a passkey sign-in.
	 */
	public static function is_passkey_authentication( WP_User $user ): bool {
		return 0 !== self::$authenticated_user_id && self::$authenticated_user_id === $user->ID;
	}

	/**
	 * Check whether passkey sign-in is enabled and usable on this site.
	 *
	 * @return bool True when enabled and served over HTTPS.
	 */
	public static function is_enabled(): bool {
		return Utils::is_passkeys_enabled() && WebAuthnServer::is_available();
	}

	/**
	 * Count options requests per IP and enforce the per-minute limit.
	 *
	 * @return bool True when the request is allowed.
	 */
	private static function allow_options_request(): bool {
		$key   = 'brain2fa_passkey_opts_' . Utils::get_client_ip_hash();
		$count = (int) get_transient( $key );

		if ( $count >= (int) apply_filters( 'brain2fa_passkey_options_per_minute', self::OPTIONS_PER_MINUTE ) ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Check whether the request authenticates without a browser session (XML-RPC or REST).
	 *
	 * @return bool True for XML-RPC and REST API requests.
	 */
	private static function is_non_interactive_request(): bool {
		return ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Get the session cookie name.
	 *
	 * @return string Cookie name, unique per site.
	 */
	private static function cookie_name(): string {
		return 'brain2fa_passkey_' . ( defined( 'COOKIEHASH' ) ? COOKIEHASH : md5( home_url() ) );
	}

	/**
	 * Read the session value from the browser's cookie.
	 *
	 * @return string Session value, or an empty string.
	 */
	private static function get_session_cookie(): string {
		$name = self::cookie_name();

		return isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
	}

	/**
	 * Set or clear the session cookie.
	 *
	 * @param string $value   Session value, or an empty string to clear.
	 * @param int    $expires Expiry timestamp.
	 * @return void
	 */
	private static function set_session_cookie( string $value, int $expires ): void {
		if ( headers_sent() ) {
			return;
		}

		setcookie(
			self::cookie_name(),
			$value,
			array(
				'expires'  => $expires,
				'path'     => '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Get the transient key for a session's challenge.
	 *
	 * @param string $session Session value.
	 * @return string Transient key.
	 */
	private static function challenge_key( string $session ): string {
		return 'brain2fa_webauthn_auth_' . hash( 'sha256', $session );
	}

	/**
	 * Get the transient key for a login token.
	 *
	 * @param string $token Login token.
	 * @return string Transient key.
	 */
	private static function token_key( string $token ): string {
		return 'brain2fa_passkey_token_' . hash( 'sha256', $token );
	}
}
