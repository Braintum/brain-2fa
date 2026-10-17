<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Enrollment Guard
 *
 * @package Brain_2FA\Auth
 * @since 1.0.4
 */

namespace Brain_2FA\Auth;

use Brain_2FA\Utils;
use WP_Error;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Enrollment Guard
 *
 * Restricts signed-in users who must set up 2FA (required role, grace period
 * expired, not enrolled) to the Login Security page until they enroll. This
 * replaces blocking their login, which left them no way to enroll.
 *
 * Also lets WooCommerce customers reach the setup page, which WooCommerce
 * otherwise blocks, and links to it from the My Account dashboard.
 *
 * @since 1.0.4
 */
class EnrollmentGuard {

	/**
	 * Menu slug of the Login Security (setup) page.
	 *
	 * @var string
	 */
	public const SETUP_PAGE = 'brain-2fa-login-security';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 100, 3 );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'filter_woocommerce_login_redirect' ), 100, 2 );
		add_action( 'admin_init', array( __CLASS__, 'restrict_admin' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'restrict_front_end' ), 1 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'restrict_rest' ), 101 );
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'allow_setup_page_for_woocommerce' ) );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render_my_account_status' ) );
	}

	/**
	 * Get the setup page URL.
	 *
	 * @return string Admin URL of the Login Security page.
	 */
	public static function get_setup_url(): string {
		return admin_url( 'admin.php?page=' . self::SETUP_PAGE );
	}

	/**
	 * Check whether the current signed-in user is restricted to setup.
	 *
	 * @return bool True when the current user must set up 2FA first.
	 */
	public static function current_user_must_enroll(): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		return Utils::is_enrollment_required( wp_get_current_user() );
	}

	/**
	 * Check whether the current request is for the setup page.
	 *
	 * @return bool True on admin.php?page=brain-2fa-login-security.
	 */
	public static function is_setup_page_request(): bool {
		global $pagenow;

		// Read-only routing check; no state changes depend on it.
		$page = isset( $_GET['page'] ) && is_string( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return is_admin() && 'admin.php' === $pagenow && self::SETUP_PAGE === $page;
	}

	/**
	 * Send users who must enroll to the setup page after a WordPress login.
	 *
	 * @param string           $redirect_to           Redirect destination.
	 * @param string           $requested_redirect_to Requested destination.
	 * @param WP_User|WP_Error $user                  User, or error on failed login.
	 * @return string Redirect destination.
	 */
	public static function filter_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
		if ( $user instanceof WP_User && Utils::is_enrollment_required( $user ) ) {
			return self::get_setup_url();
		}

		return $redirect_to;
	}

	/**
	 * Send users who must enroll to the setup page after a WooCommerce login.
	 *
	 * @param string  $redirect Redirect destination.
	 * @param WP_User $user     Signed-in user.
	 * @return string Redirect destination.
	 */
	public static function filter_woocommerce_login_redirect( $redirect, $user ) {
		if ( $user instanceof WP_User && Utils::is_enrollment_required( $user ) ) {
			return self::get_setup_url();
		}

		return $redirect;
	}

	/**
	 * Keep users who must enroll on the setup page inside wp-admin.
	 *
	 * AJAX requests are refused, except those in is_allowed_ajax_action().
	 *
	 * @return void
	 */
	public static function restrict_admin(): void {
		if ( ! self::current_user_must_enroll() ) {
			return;
		}

		if ( wp_doing_ajax() ) {
			// phpcs:ignore WordPress.Security.NonceVerification -- Read-only routing check; the request is refused either way.
			$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( ! self::is_allowed_ajax_action( $action ) ) {
				wp_send_json_error( array( 'message' => self::get_restriction_message() ), 403 );
			}
			return;
		}

		if ( self::is_setup_page_request() ) {
			return;
		}

		wp_safe_redirect( self::get_setup_url() );
		exit;
	}

	/**
	 * Check whether a restricted user may run an AJAX action.
	 *
	 * Allows the Heartbeat API (keeps the session alive) and passkey registration,
	 * which is a way to enroll.
	 *
	 * @param string $action AJAX action name.
	 * @return bool True when allowed.
	 */
	public static function is_allowed_ajax_action( string $action ): bool {
		return in_array( $action, array( 'heartbeat', 'brain2fa_passkey_register_options', 'brain2fa_passkey_register_verify' ), true );
	}

	/**
	 * Send users who must enroll from front-end pages to the setup page.
	 *
	 * @return void
	 */
	public static function restrict_front_end(): void {
		if ( ! self::current_user_must_enroll() ) {
			return;
		}

		wp_safe_redirect( self::get_setup_url() );
		exit;
	}

	/**
	 * Refuse cookie-authenticated REST requests from users who must enroll.
	 *
	 * @param WP_Error|null|true $result Result of earlier authentication checks.
	 * @return WP_Error|null|true Unchanged result, or an error for restricted users.
	 */
	public static function restrict_rest( $result ) {
		if ( ! empty( $result ) || ! self::current_user_must_enroll() ) {
			return $result;
		}

		return new WP_Error( 'brain2fa_enrollment_required', self::get_restriction_message(), array( 'status' => 403 ) );
	}

	/**
	 * Let WooCommerce customers open the setup page, which WooCommerce otherwise blocks.
	 *
	 * @param bool $prevent_access Whether WooCommerce will redirect the user away from wp-admin.
	 * @return bool False on the setup page, otherwise unchanged.
	 */
	public static function allow_setup_page_for_woocommerce( $prevent_access ) {
		return self::is_setup_page_request() ? false : $prevent_access;
	}

	/**
	 * Show 2FA status and a setup link on the WooCommerce My Account dashboard.
	 *
	 * @return void
	 */
	public static function render_my_account_status(): void {
		if ( ! Utils::is_2fa_enabled_sitewide() || ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();

		if ( Utils::is_2fa_enabled_for_user( $user->ID ) ) {
			$message = __( 'Two-factor authentication is active for your account.', 'brain-2fa' );
			$link    = __( 'Manage two-factor authentication', 'brain-2fa' );
		} elseif ( Utils::is_2fa_required_for_user( $user ) ) {
			$expires_at = Utils::get_grace_period_expiry( $user );
			$days       = max( 1, (int) ceil( ( $expires_at - time() ) / DAY_IN_SECONDS ) );
			$message    = sprintf(
				/* translators: %d: number of days left to set up two-factor authentication */
				_n( 'Your account requires two-factor authentication. You have %d day left to set it up.', 'Your account requires two-factor authentication. You have %d days left to set it up.', $days, 'brain-2fa' ),
				$days
			);
			$link = __( 'Set up two-factor authentication', 'brain-2fa' );
		} else {
			$message = __( 'Protect your account with two-factor authentication.', 'brain-2fa' );
			$link    = __( 'Set up two-factor authentication', 'brain-2fa' );
		}

		printf(
			'<p class="brain2fa-account-status">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html( $message ),
			esc_url( self::get_setup_url() ),
			esc_html( $link )
		);
	}

	/**
	 * Get the message shown to users who must enroll.
	 *
	 * @return string Translated message.
	 */
	public static function get_restriction_message(): string {
		return __( 'Your account must use two-factor authentication. Set it up to continue using this site.', 'brain-2fa' );
	}
}
