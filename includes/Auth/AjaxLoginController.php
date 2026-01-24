<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * AJAX Login Controller
 *
 * @package Brain_2FA\Auth
 * @since 1.0.0
 */
namespace Brain_2FA\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX Login Controller
 *
 * Handles AJAX login requests with 2FA.
 *
 * @since 1.0.0
 */
class AjaxLoginController {

	/**
	 * Initialize AJAX handlers.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_nopriv_brain2fa_login', array( __CLASS__, 'handle_login' ) );
	}

	/**
	 * Handle AJAX login request.
	 */
	public static function handle_login(): void {

		check_ajax_referer( 'brain2fa_login', 'nonce' );

		$credential_keys = array(
			'log'      => 'pwd',
			'username' => 'password',
		);
		$username = null;
		$password = null;
		foreach ( $credential_keys as $username_key => $password_key ) {
			if ( array_key_exists( $username_key, $_POST ) && array_key_exists( $password_key, $_POST ) && is_string( $_POST[ $username_key ] ) && is_string( $_POST[ $password_key ] ) ) {
				$username = sanitize_text_field( wp_unslash( $_POST[ $username_key ] ) );
				$password = sanitize_text_field( wp_unslash( $_POST[ $password_key ] ) );
				break;
			}
		}

		if ( empty( $username ) || empty( $password ) ) {
			wp_send_json_error(
				array(
					'error' => wp_kses(
						sprintf(
							/* translators: %s is the lost password URL */
							__( '<strong>ERROR</strong>: A username and password must be provided. <a href="%s" title="Password Lost and Found">Lost your password</a>?', 'brain2fa' ),
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

		do_action_ref_array( 'wp_authenticate', array( &$username, &$password ) );

		// Prevents our auth filter from recursing.
		define( 'BRAIN_2FA_AUTHENTICATION_CHECK', true );

		$user = wp_authenticate( $username, $password );
		if ( is_object( $user ) && ( $user instanceof \WP_User ) ) {

			// Check if user has 2FA enabled.
			$is_2fa_enabled = get_user_meta( $user->ID, 'brain2fa_enabled', true );

			if ( ! $is_2fa_enabled ) {
				// Not enabled for this user, is whitelisted, has a valid remembered cookie, or has already provided a 2FA code via the password field pass the credentials on to the normal login flow.
				wp_send_json_success( array( 'login' => 1 ) );
			}
			wp_send_json_success(
				array(
					'login'        => 1,
					'requires_2fa' => true,
				)
			);
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
							__( '<strong>ERROR</strong>: The username or password you entered is incorrect. <a href="%s" title="Password Lost and Found">Lost your password</a>?', 'brain2fa' ),
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
					foreach ( $user->get_error_messages( $code ) as $error_message ) {
						if ( 'message' === $severity ) {
							$messages[] = $error_message;
						} else {
							$errors[] = $error_message;
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
				$messages = apply_filters( 'login_errors', $messages );
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
						__( '<strong>ERROR</strong>: The username or password you entered is incorrect. <a href="%s" title="Password Lost and Found">Lost your password</a>?', 'brain2fa' ),
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
}
