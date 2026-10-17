<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Login Controller
 *
 * @package Brain_2FA\Auth
 * @since 1.0.0
 */
namespace Brain_2FA\Auth;

use Brain_2FA\Passkeys\PasskeyLogin;
use Brain_2FA\Utils;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Login Controller
 *
 * Handles login requests with 2FA.
 *
 * @since 1.0.0
 */
class LoginController {

	/**
	 * Initializes the Brain 2FA plugin by setting up necessary actions and filters.
	 *
	 * This method hooks the 'brain2fa_login' action for unauthenticated users and
	 * adds a filter to the authentication process to verify the two-factor authentication.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_ajax_nopriv_brain2fa_login', array( __CLASS__, 'handle_login' ) );
		add_filter( 'authenticate', array( __CLASS__, 'verify_2fa' ), 40, 3 );
		add_action( 'login_form', array( __CLASS__, 'render_fallback_code_field' ) );
		add_action( 'woocommerce_login_form', array( __CLASS__, 'render_fallback_code_field' ) );
		add_filter( 'login_form_middle', array( __CLASS__, 'append_fallback_code_field' ) );
	}

	/**
	 * LoginController initialization or method definition
	 *
	 * Handles user authentication and login operations for the 2FA brain plugin.
	 * This class manages the login flow and validation processes.
	 *
	 * @package Brain_2FA\Auth
	 * @subpackage Auth
	 * @since 1.0.0
	 */
	public static function handle_login(): void {

		// Rate limiting: max 5 attempts per minute per IP.
		$ip_hash  = wp_hash( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$rate_key = 'brain2fa_rate_' . $ip_hash;
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 5 ) {
			wp_send_json_error( array( 'error' => esc_html__( 'Too many attempts. Please try again in a minute.', 'brain-2fa' ) ), 429 );
		}
		set_transient( $rate_key, $attempts + 1, MINUTE_IN_SECONDS );

		$credential_keys = array(
			'log'      => 'pwd',
			'username' => 'password',
		);

		$username = null;
		$password = null;
		// Cached wp-login.php pages can contain expired nonces, so credential preflight must not reject on nonce failure.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		foreach ( $credential_keys as $username_key => $password_key ) {
			if ( array_key_exists( $username_key, $_POST ) && array_key_exists( $password_key, $_POST ) && is_string( $_POST[ $username_key ] ) && is_string( $_POST[ $password_key ] ) ) {
				$username = sanitize_text_field( wp_unslash( $_POST[ $username_key ] ) );
				// Passwords must not be sanitized — sanitize_text_field strips tags and breaks some passwords.
				$password = wp_unslash( $_POST[ $password_key ] );
				break;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error(
				array(
					'error' => wp_kses(
						sprintf(
							/* translators: %s is the lost password URL */
							__( '<strong>ERROR</strong>: A username and password must be provided. <a href="%s" title="Password Lost and Found">Lost your password</a>?', 'brain-2fa' ),
							wp_lostpassword_url()
						),
						array(
							'strong' => array(),
							'a'      => array(
								'href'  => array(),
								'title' => array(),
							),
						)
					),
				)
			);
		}

		// Trigger the authentication process.
		do_action_ref_array( 'wp_authenticate', array( &$username, &$password ) );

		// Prevents our auth filter from recursing.
		if ( ! defined( 'BRAIN_2FA_AUTHENTICATION_CHECK' ) ) {
			define( 'BRAIN_2FA_AUTHENTICATION_CHECK', true );
		}

		$user = wp_authenticate( $username, $password );
		if ( is_object( $user ) && ( $user instanceof WP_User ) ) {
			$response = self::get_preflight_response( $user );

			if ( $response['success'] ) {
				wp_send_json_success( $response['data'] );
			}
			wp_send_json_error( $response['data'] );
		}

		if ( is_wp_error( $user ) ) {
			$errors   = array();
			$messages = array();
			$reset    = false;
			foreach ( $user->get_error_codes() as $code ) {
				if ( 'invalid_username' === $code || 'invalid_email' === $code || 'incorrect_password' === $code || 'authentication_failed' === $code ) {

					$errors[] = wp_kses(
						sprintf(
							/* translators: %s is the lost password URL */
							__( '<strong>ERROR</strong>: The username or password you entered is incorrect. <a href="%s" title="Password Lost and Found">Lost your password</a>?', 'brain-2fa' ),
							wp_lostpassword_url()
						),
						array(
							'strong' => array(),
							'a'      => array(
								'href'  => array(),
								'title' => array(),
							),
						)
					);
				} else {
					$severity = $user->get_error_data( $code );
					$allowed  = array(
						'strong' => array(),
						'a'      => array( 'href' => array() ),
					);
					foreach ( $user->get_error_messages( $code ) as $error_message ) {
						if ( 'message' === $severity ) {
							$messages[] = wp_kses( $error_message, $allowed );
						} else {
							$errors[] = wp_kses( $error_message, $allowed );
						}
					}
				}
			}

			if ( ! empty( $errors ) ) {
				$errors = implode( '<br>', $errors );
				$errors = apply_filters( 'login_errors', $errors );
				wp_send_json_error(
					array(
						'error' => $errors,
						'reset' => true,
					)
				);
			}

			if ( ! empty( $messages ) ) {
				$messages = implode( '<br>', $messages );
				wp_send_json_error(
					array(
						'message' => $messages,
						'reset'   => true,
					)
				);
			}
		}

		wp_send_json_error(
			array(
				'error' => wp_kses(
					sprintf(
						/* translators: %s is the lost password URL */
						__( '<strong>ERROR</strong>: The username or password you entered is incorrect. <a href="%s" title="Password Lost and Found">Lost your password</a>?', 'brain-2fa' ),
						wp_lostpassword_url()
					),
					array(
						'strong' => array(),
						'a'      => array(
							'href'  => array(),
							'title' => array(),
						),
					)
				),
			)
		);
	}

	/**
	 * Verify 2FA code during authentication.
	 *
	 * @param WP_User|WP_Error|null $user     The authenticated user or WP_Error on failure.
	 * @param string                $username The username.
	 * @param string                $password The password.
	 * @return WP_User|WP_Error The authenticated user or WP_Error on failure.
	 */
	public static function verify_2fa( $user, $username, $password ) { //phpcs:ignore

		if ( defined( 'BRAIN_2FA_AUTHENTICATION_CHECK' ) ) {
			return $user;
		}

		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		// A passkey with user verification is multi-factor on its own.
		if ( PasskeyLogin::is_passkey_authentication( $user ) ) {
			return $user;
		}

		// Passkey-only accounts have password sign-in turned off (independent of the 2FA switch).
		if ( Utils::is_passkey_only( $user ) ) {
			return new WP_Error( 'brain2fa_passkey_only', self::get_passkey_only_message() );
		}

		if ( ! Utils::is_2fa_enabled_sitewide() ) {
			return $user;
		}

		$has_code_method = Utils::is_code_method_enabled_for_user( $user->ID );
		$has_passkey     = Utils::user_has_passkey_factor( $user->ID );

		if ( ! $has_code_method && ! $has_passkey ) {
			// Interactive sign-ins continue into restricted setup mode (see EnrollmentGuard).
			// XML-RPC and REST have no setup page, so they stay blocked until the user enrolls.
			if ( Utils::is_enrollment_required( $user ) && self::is_non_interactive_request() ) {
				return new WP_Error(
					'brain2fa_setup_required',
					__( 'Two-factor authentication must be set up for this account. Sign in through the website to set it up.', 'brain-2fa' )
				);
			}

			return $user;
		}

		if ( Utils::is_remembered_device( $user ) ) {
			return $user;
		}

		// Checked before any code is evaluated, so locked-out attempts cost nothing and send no email.
		$locked_for = Utils::get_verification_lockout( $user );
		if ( $locked_for > 0 ) {
			return new WP_Error( 'brain2fa_too_many_attempts', Utils::get_lockout_message( $locked_for ) );
		}

		// Passkeys are this user's only second factor: the passkey sign-in (which needs JavaScript) completes it.
		if ( ! $has_code_method ) {
			return new WP_Error( 'brain2fa_passkey_required', __( 'Use your passkey to finish signing in.', 'brain-2fa' ) );
		}

		$method_id = get_user_meta( $user->ID, 'brain2fa_method', true );
		$method    = brain_2fa()->manager->get_method( $method_id );

		// Ensure 2FA code is provided.
		if ( ! isset( $_POST['brain2fa_code'] ) || empty( $_POST['brain2fa_code'] ) ) { //phpcs:ignore
			if ( 'email' === $method_id && Utils::is_method_enabled( $method_id ) && $method && $method->send_challenge( $user ) ) {
				return new WP_Error(
					'brain2fa_required',
					__( 'An email authentication code has been sent. Enter it to continue.', 'brain-2fa' )
				);
			}

			return new WP_Error(
				'brain2fa_required',
				__( 'Two-factor authentication required.', 'brain-2fa' )
			);
		}

		$code      = sanitize_text_field( wp_unslash( $_POST['brain2fa_code'] ) ); //phpcs:ignore

		if ( ! Utils::is_method_enabled( $method_id ) || ! $method || ! $method->verify( $user, $code ) ) {
			Utils::record_failed_verification( $user );
			return new WP_Error( 'invalid_code', __( 'Invalid verification code.', 'brain-2fa' ) );
		}

		Utils::clear_failed_verifications( $user );

		if ( isset( $_POST['brain2fa_remember_device'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			Utils::remember_device( $user );
		}

		return $user;
	}

	/**
	 * Decide the login preflight response for a user whose password was correct.
	 *
	 * Mirrors the policy in verify_2fa(), which remains the real enforcement point.
	 * The response tells login.js whether to submit the form directly or to ask
	 * for a second factor: a code ('method'), a passkey ('passkey' options), or both.
	 *
	 * @param WP_User $user User who entered a correct password.
	 * @return array{success:bool,data:array} Response for wp_send_json_success() or wp_send_json_error().
	 */
	public static function get_preflight_response( WP_User $user ): array {
		$error = static fn( string $message ) => array(
			'success' => false,
			'data'    => array(
				'error' => $message,
				'reset' => true,
			),
		);
		$login = array(
			'success' => true,
			'data'    => array( 'login' => 1 ),
		);

		if ( Utils::is_passkey_only( $user ) ) {
			return $error( self::get_passkey_only_message() );
		}

		if ( ! Utils::is_2fa_enabled_sitewide() ) {
			return $login;
		}

		$has_code_method = Utils::is_code_method_enabled_for_user( $user->ID );
		$has_passkey     = Utils::user_has_passkey_factor( $user->ID );

		// Not enrolled: sign in normally. If the grace period has expired, EnrollmentGuard
		// redirects the user to the setup page and restricts them there until they enroll.
		if ( ( ! $has_code_method && ! $has_passkey ) || Utils::is_remembered_device( $user ) ) {
			return $login;
		}

		$locked_for = Utils::get_verification_lockout( $user );
		if ( $locked_for > 0 ) {
			return $error( Utils::get_lockout_message( $locked_for ) );
		}

		$data = array(
			'login'        => 1,
			'requires_2fa' => true,
			'method'       => '',
		);

		if ( $has_code_method ) {
			$method_id = (string) get_user_meta( $user->ID, 'brain2fa_method', true );
			$method    = brain_2fa()->manager->get_method( $method_id );

			if ( ! Utils::is_method_enabled( $method_id ) || ! $method ) {
				if ( ! $has_passkey ) {
					return $error( __( 'Your configured two-factor authentication method is unavailable. Please contact an administrator.', 'brain-2fa' ) );
				}
			} elseif ( 'email' === $method_id && ! $method->send_challenge( $user ) ) {
				if ( ! $has_passkey ) {
					return $error( __( 'Unable to send your email authentication code. Please try again or contact an administrator.', 'brain-2fa' ) );
				}
			} else {
				$data['method'] = $method_id;
			}
		}

		if ( $has_passkey ) {
			$data['passkey'] = PasskeyLogin::start_second_step( $user );
		}

		return array(
			'success' => true,
			'data'    => $data,
		);
	}

	/**
	 * Get the message shown when a passkey-only account tries to use its password.
	 *
	 * @return string Translated message.
	 */
	public static function get_passkey_only_message(): string {
		return __( 'This account signs in with a passkey only. Use "Sign in with a passkey" instead of your password.', 'brain-2fa' );
	}

	/**
	 * Check whether the current request authenticates without a browser session (XML-RPC or REST).
	 *
	 * @return bool True for XML-RPC and REST API requests.
	 */
	private static function is_non_interactive_request(): bool {
		return ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	/**
	 * Render the server-side verification code field on login forms.
	 *
	 * The login script removes this field and shows its own after the credential
	 * check, so it only remains when JavaScript is unavailable or fails to load.
	 * Users then submit their username, password and code in one request, which
	 * verify_2fa() handles like any other login.
	 *
	 * @return void
	 */
	public static function render_fallback_code_field(): void {
		echo self::get_fallback_code_field(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in get_fallback_code_field().
	}

	/**
	 * Append the fallback code field to wp_login_form() output.
	 *
	 * @param string $content HTML already in the login_form_middle slot.
	 * @return string HTML with the fallback field appended.
	 */
	public static function append_fallback_code_field( $content ): string {
		return (string) $content . self::get_fallback_code_field();
	}

	/**
	 * Build the fallback verification code field.
	 *
	 * @return string Escaped HTML, or an empty string when 2FA is off site-wide.
	 */
	private static function get_fallback_code_field(): string {
		if ( ! Utils::is_2fa_enabled_sitewide() ) {
			return '';
		}

		return sprintf(
			'<p class="brain2fa-fallback-field"><label for="brain2fa_code_fallback">%1$s</label><input type="text" name="brain2fa_code" id="brain2fa_code_fallback" class="input" inputmode="numeric" autocomplete="one-time-code" size="20"><span class="description">%2$s</span></p>',
			esc_html__( 'Verification Code', 'brain-2fa' ),
			esc_html__( 'Only needed if two-factor authentication is enabled for your account. Email users: sign in once without a code to receive one.', 'brain-2fa' )
		);
	}
}
