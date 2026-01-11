<?php
namespace Brain_2FA\Auth;

use Brain_2FA\TwoFactor\Manager;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

class LoginHandler {

	private static $instance;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	private function __construct() {
		add_filter( 'authenticate', [ $this, 'intercept_login' ], 30, 3 );
		add_filter( 'authenticate', [ $this, 'verify_2fa' ], 40, 3 );
	}

	/**
	 * Step 1: Intercept password login
	 */
	public function intercept_login( $user, $username, $password ) {

		if ( ! $user instanceof WP_User ) {
			return $user;
		}

		// Skip non-interactive logins
		if ( defined( 'XMLRPC_REQUEST' ) || wp_doing_ajax() || wp_doing_cron() ) {
			return $user;
		}

		if ( ! get_user_meta( $user->ID, 'brain2fa_enabled', true ) ) {
			return $user;
		}

		$token = TwoFASession::create( $user->ID );

		return new WP_Error(
			'brain2fa_required',
			__( 'Two-factor authentication required.', 'brain2fa' ),
			array( 'token' => $token )
		);
	}

	/**
	 * Step 2: Verify 2FA code
	 */
	public function verify_2fa( $user, $username, $password ) {

		if ( ! isset( $_POST['brain2fa_token'], $_POST['brain2fa_code'] ) ) {
			return $user;
		}

		if (
			! isset( $_POST['brain2fa_nonce'] ) ||
			! wp_verify_nonce( $_POST['brain2fa_nonce'], 'brain2fa_login' )
		) {
			return new WP_Error( 'invalid_nonce', __( 'Security check failed.', 'brain2fa' ) );
		}

		$token   = sanitize_text_field( wp_unslash( $_POST['brain2fa_token'] ) );
		$user_id = TwoFASession::get_user_id( $token );

		if ( ! $user_id ) {
			return new WP_Error( 'expired', __( 'Session expired. Please log in again.', 'brain2fa' ) );
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', __( 'Invalid user.', 'brain2fa' ) );
		}

		$code      = sanitize_text_field( wp_unslash( $_POST['brain2fa_code'] ) );
		$method_id = get_user_meta( $user->ID, 'brain2fa_method', true );

		$method = Manager::instance()->get_method( $method_id );

		if ( ! $method || ! $method->verify( $user, $code ) ) {
			return new WP_Error( 'invalid_code', __( 'Invalid verification code.', 'brain2fa' ) );
		}

		TwoFASession::destroy( $token );

		return $user;
	}
}
