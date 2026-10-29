<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Login Security page
 *
 * The per-user page where people set up and manage their own passkeys,
 * two-factor codes, recovery codes and password sign-in.
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

namespace Brain_2FA\Admin;

use Brain_2FA\Auth\TrustedDeviceRepository;
use Brain_2FA\Passkeys\PasskeyLogin;
use Brain_2FA\Passkeys\PasskeyRegistration;
use Brain_2FA\Passkeys\PasskeyRepository;
use Brain_2FA\TwoFactor\Methods\EmailMethod;
use Brain_2FA\Utils;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the Login Security page actions and renders its sections.
 *
 * Every action works on the current user only; no user ID is taken from the request.
 * The same sections render in wp-admin and in the WooCommerce My Account tab.
 *
 * @since 1.1.0
 */
class LoginSecurityPage {

	/**
	 * Rendered as the wp-admin page.
	 *
	 * @var string
	 */
	public const CONTEXT_ADMIN = 'admin';

	/**
	 * Rendered in the WooCommerce My Account tab.
	 *
	 * @var string
	 */
	public const CONTEXT_ACCOUNT = 'account';

	/**
	 * Where the page is rendered.
	 *
	 * @var string
	 */
	private static $context = self::CONTEXT_ADMIN;

	/**
	 * Result of an action handled before rendering, or null when none was handled yet.
	 *
	 * @var array{recovery_codes: string[], totp_secret: string}|null
	 */
	private static $outcome = null;

	/**
	 * Feedback for this request, grouped by the page section it belongs to.
	 *
	 * Sections: codes, deactivate, recovery, regenerate, passkeys, passkey-{id}, password, devices.
	 *
	 * @var array<string, array<int, array{type: string, message: string}>>
	 */
	private static $notices = array();

	/**
	 * Disclosure to open on render, such as the one whose form just failed.
	 *
	 * @var string
	 */
	private static $open = '';

	/**
	 * Render the page.
	 *
	 * @param string $context Where it is rendered: CONTEXT_ADMIN or CONTEXT_ACCOUNT.
	 * @return void
	 */
	public static function render( string $context = self::CONTEXT_ADMIN ): void {
		if ( ! current_user_can( 'read' ) ) {
			return;
		}

		self::$context = self::CONTEXT_ACCOUNT === $context ? self::CONTEXT_ACCOUNT : self::CONTEXT_ADMIN;

		$current_user = wp_get_current_user();
		$outcome      = self::$outcome ?? self::handle_post( $current_user );
		$is_account   = self::CONTEXT_ACCOUNT === self::$context;

		// Read state after handling, so the page shows the result of the action.
		$is_2fa_enabled       = (bool) get_user_meta( $current_user->ID, 'brain2fa_enabled', true );
		$current_method       = (string) get_user_meta( $current_user->ID, 'brain2fa_method', true );
		$current_method_ready = $is_2fa_enabled && Utils::is_method_enabled( $current_method ) && brain_2fa()->manager->get_method( $current_method );
		$totp_method          = brain_2fa()->manager->get_method( 'totp' );
		$setup_method_id      = Utils::get_default_method();
		$setup_method         = brain_2fa()->manager->get_method( $setup_method_id );
		$code_setup_available = ! $is_2fa_enabled && $setup_method && Utils::is_method_enabled( $setup_method_id );
		$fresh_recovery_codes = $outcome['recovery_codes'];

		$setup_data = array();
		if ( $code_setup_available ) {
			$setup_data = 'totp' === $setup_method_id
				? $setup_method->get_setup_data( $current_user, $outcome['totp_secret'] )
				: $setup_method->get_setup_data( $current_user );
		}

		$show_recovery        = $is_2fa_enabled && 'totp' === $current_method && $totp_method;
		$recovery_codes_count = $show_recovery ? $totp_method->get_recovery_codes_count( $current_user ) : 0;
		$email_code_pending   = $code_setup_available && $setup_method instanceof EmailMethod && $setup_method->has_pending_code( $current_user );

		// Policy that applies to this user.
		$site_2fa_enabled  = Utils::is_2fa_enabled_sitewide();
		$role_requires_2fa = Utils::is_2fa_required_for_user( $current_user );
		$must_enroll       = Utils::is_enrollment_required( $current_user );
		$is_protected      = Utils::is_2fa_enabled_for_user( $current_user->ID );
		$setup_deadline    = $role_requires_2fa && ! $is_protected ? Utils::get_grace_period_expiry( $current_user ) : 0;

		// Passkeys: managed only when turned on and served over HTTPS.
		$passkeys_setting     = Utils::is_passkeys_enabled();
		$passkeys_enabled     = PasskeyLogin::is_enabled();
		$passkeys             = $passkeys_enabled ? PasskeyRepository::get_for_user( $current_user->ID ) : array();
		$stored_passkey_count = $passkeys_enabled ? count( $passkeys ) : PasskeyRepository::count_for_user( $current_user->ID );
		$passkey_only_allowed = $passkeys_enabled && Utils::is_passkey_only_allowed();
		$passkey_only         = Utils::is_passkey_only( $current_user );

		// Trusted devices: listed while the feature is on, and while devices from before it was turned off remain.
		$remember_enabled  = Utils::is_remember_device_enabled();
		$trusted_devices   = array_reverse( TrustedDeviceRepository::get_all( $current_user->ID ) );
		$current_device_id = $trusted_devices ? Utils::get_current_device_id( $current_user->ID ) : '';

		include BRAIN_2FA_PLUGIN_DIR . 'includes/Admin/views/login-security-page.php';
	}

	/**
	 * Handle any submitted action before the page is sent.
	 *
	 * Used where the page is rendered inside other output (the My Account tab), so every
	 * action runs before headers are sent. render() then shows the result.
	 *
	 * @return void
	 */
	public static function handle_request(): void {
		if ( ! current_user_can( 'read' ) ) {
			return;
		}

		self::handle_device_actions();
		self::$outcome = self::handle_post( wp_get_current_user() );
	}

	/**
	 * Get the CSS classes for a button in the current context.
	 *
	 * In My Account, buttons use the theme's WooCommerce button styles.
	 *
	 * @param string $variant Empty for a regular button, "primary" or "danger".
	 * @return string Space-separated classes.
	 */
	public static function button_class( string $variant = '' ): string {
		$classes = array( 'button' );

		if ( self::CONTEXT_ACCOUNT === self::$context ) {
			$element = function_exists( 'wc_wp_theme_get_element_class_name' ) ? wc_wp_theme_get_element_class_name( 'button' ) : '';
			if ( '' !== $element ) {
				$classes[] = $element;
			}
			if ( 'primary' === $variant ) {
				$classes[] = 'alt';
			}
		} elseif ( 'primary' === $variant ) {
			$classes[] = 'button-primary';
		}

		if ( 'danger' === $variant ) {
			$classes[] = 'brain2fa-button-danger';
		}

		return implode( ' ', $classes );
	}

	/**
	 * Handle the trusted-device actions before the page is sent.
	 *
	 * Runs on the page's load hook, before any output, so the trust cookie of the browser
	 * doing the revoking can be removed. Forgetting a device only makes sign-in stricter,
	 * so it needs the nonce but not the password. The result is shown when the page renders.
	 *
	 * @return void
	 */
	public static function handle_device_actions(): void {
		if ( ! isset( $_POST['brain2fa_action'] ) ) {
			return;
		}

		// Every action on this page uses this nonce, and handle_post() checks it the same way.
		check_admin_referer( 'brain2fa_setup_action', 'brain2fa_setup_nonce' );

		$action = is_string( $_POST['brain2fa_action'] ) ? sanitize_key( wp_unslash( $_POST['brain2fa_action'] ) ) : '';
		if ( ! in_array( $action, array( 'forget_device', 'forget_all_devices' ), true ) ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return;
		}

		$current_device_id = Utils::get_current_device_id( $user->ID );

		if ( 'forget_all_devices' === $action ) {
			Utils::forget_remembered_devices( $user->ID );
			if ( '' !== $current_device_id ) {
				Utils::clear_remembered_device_cookie( $user->ID );
			}
			self::add_notice( 'devices', 'success', __( 'All trusted devices were forgotten. Every browser asks for your second step at its next password sign-in.', 'brain-2fa' ) );
			return;
		}

		$device_id = isset( $_POST['brain2fa_device_id'] ) && is_string( $_POST['brain2fa_device_id'] ) ? sanitize_key( wp_unslash( $_POST['brain2fa_device_id'] ) ) : '';

		// Only the current user's list is searched, so another user's device ID matches nothing.
		if ( '' === $device_id || ! TrustedDeviceRepository::remove( $user->ID, $device_id ) ) {
			self::add_notice( 'devices', 'error', __( 'That device is not in your list. It may have expired or already been forgotten.', 'brain-2fa' ) );
			return;
		}

		if ( $device_id === $current_device_id ) {
			Utils::clear_remembered_device_cookie( $user->ID );
		}
		self::add_notice( 'devices', 'success', __( 'The device was forgotten. It asks for your second step at its next password sign-in.', 'brain-2fa' ) );
	}

	/**
	 * Print the notices for one page section.
	 *
	 * @param string $section Section identifier.
	 * @return void
	 */
	public static function render_notices( string $section ): void {
		foreach ( self::$notices[ $section ] ?? array() as $notice ) {
			printf(
				'<div class="notice notice-%1$s inline brain2fa-section-notice"%2$s><p>%3$s</p></div>',
				esc_attr( $notice['type'] ),
				'error' === $notice['type'] ? ' role="alert"' : '',
				esc_html( $notice['message'] )
			);
		}
	}

	/**
	 * Get the open attribute for a disclosure.
	 *
	 * @param string $disclosure Disclosure identifier.
	 * @return string The attribute, or an empty string.
	 */
	public static function open_attribute( string $disclosure ): string {
		return $disclosure === self::$open ? ' open' : '';
	}

	/**
	 * Record a notice for a section.
	 *
	 * @param string $section Section identifier.
	 * @param string $type    Notice type: success, error, warning or info.
	 * @param string $message Plain-text message.
	 * @return void
	 */
	private static function add_notice( string $section, string $type, string $message ): void {
		self::$notices[ $section ][] = array(
			'type'    => $type,
			'message' => $message,
		);
	}

	/**
	 * Record an error and keep its form open.
	 *
	 * @param string $section Section identifier, also the disclosure to open.
	 * @param string $message Plain-text message.
	 * @return void
	 */
	private static function add_error( string $section, string $message ): void {
		self::add_notice( $section, 'error', $message );
		self::$open = $section;
	}

	/**
	 * Handle a submitted action for the current user.
	 *
	 * @param WP_User $user Current user.
	 * @return array{recovery_codes: string[], totp_secret: string} Data the response must show once.
	 */
	private static function handle_post( WP_User $user ): array {
		$outcome = array(
			'recovery_codes' => array(),
			'totp_secret'    => '',
		);

		if ( ! isset( $_POST['brain2fa_action'] ) || ! check_admin_referer( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ) ) {
			return $outcome;
		}

		$action   = sanitize_key( wp_unslash( $_POST['brain2fa_action'] ) );
		$password = isset( $_POST['brain2fa_confirm_password'] ) && is_string( $_POST['brain2fa_confirm_password'] ) ? wp_unslash( $_POST['brain2fa_confirm_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Passwords must not be altered before checking.

		$is_2fa_enabled    = (bool) get_user_meta( $user->ID, 'brain2fa_enabled', true );
		$current_method    = (string) get_user_meta( $user->ID, 'brain2fa_method', true );
		$has_active_factor = $is_2fa_enabled && brain_2fa()->manager->get_method( $current_method );
		$setup_method_id   = Utils::get_default_method();
		$setup_method      = brain_2fa()->manager->get_method( $setup_method_id );

		switch ( $action ) {
			case 'deactivate':
				if ( ! $is_2fa_enabled ) {
					self::add_notice( 'codes', 'error', __( 'Two-factor codes are not active for your account.', 'brain-2fa' ) );
					break;
				}
				$confirmation = Utils::confirm_password( $user, $password );
				if ( is_wp_error( $confirmation ) ) {
					self::add_error( 'deactivate', $confirmation->get_error_message() );
					break;
				}
				self::deactivate( $user, $current_method );
				break;

			case 'regenerate_recovery_codes':
				$totp_method = brain_2fa()->manager->get_method( 'totp' );
				if ( ! $is_2fa_enabled || 'totp' !== $current_method || ! $totp_method ) {
					self::add_notice( 'recovery', 'error', __( 'Recovery codes are only available while the authenticator app is active.', 'brain-2fa' ) );
					break;
				}
				$confirmation = Utils::confirm_password( $user, $password );
				if ( is_wp_error( $confirmation ) ) {
					self::add_error( 'regenerate', $confirmation->get_error_message() );
					break;
				}
				$outcome['recovery_codes'] = $totp_method->generate_recovery_codes( $user );
				Utils::send_factor_change_notification( $user, 'recovery_codes_regenerated' );
				self::add_notice( 'recovery', 'success', __( 'New recovery codes were generated and your old codes no longer work. Save the new codes now.', 'brain-2fa' ) );
				break;

			case 'passkey_only':
				if ( ! PasskeyLogin::is_enabled() ) {
					break;
				}
				$enable = isset( $_POST['brain2fa_passkey_only'] ) && '1' === sanitize_key( wp_unslash( $_POST['brain2fa_passkey_only'] ) );
				$result = PasskeyRegistration::set_passkey_only( $user, $enable, $password );
				if ( is_wp_error( $result ) ) {
					self::add_error( 'password', $result->get_error_message() );
				} elseif ( $enable ) {
					self::add_notice( 'password', 'success', __( 'Password sign-in is now off. Sign in with your passkey from now on.', 'brain-2fa' ) );
				} else {
					self::add_notice( 'password', 'success', __( 'Password sign-in is turned back on.', 'brain-2fa' ) );
				}
				break;

			case 'passkey_remove':
			case 'passkey_rename':
				if ( ! PasskeyLogin::is_enabled() ) {
					break;
				}
				$passkey_id = isset( $_POST['brain2fa_passkey_id'] ) ? absint( wp_unslash( $_POST['brain2fa_passkey_id'] ) ) : 0;
				if ( 'passkey_remove' === $action ) {
					$result  = PasskeyRegistration::remove( $user, $passkey_id, $password );
					$success = __( 'The passkey was removed.', 'brain-2fa' );
				} else {
					$name    = isset( $_POST['brain2fa_passkey_name'] ) ? sanitize_text_field( wp_unslash( $_POST['brain2fa_passkey_name'] ) ) : '';
					$result  = PasskeyRegistration::rename( $user, $passkey_id, $name );
					$success = __( 'The passkey was renamed.', 'brain-2fa' );
				}
				if ( is_wp_error( $result ) ) {
					self::add_error( 'passkey-' . $passkey_id, $result->get_error_message() );
				} else {
					self::add_notice( 'passkeys', 'success', $success );
				}
				break;

			case 'resend_email_code':
			case 'activate':
				if ( $has_active_factor ) {
					self::add_notice( 'codes', 'error', __( 'Two-factor authentication is already active. Turn off the current method before setting up a new one.', 'brain-2fa' ) );
					break;
				}
				if ( ! $setup_method || ! Utils::is_method_enabled( $setup_method_id ) ) {
					break;
				}
				// A new email code replaces the pending one; activation then sends it.
				if ( 'resend_email_code' === $action ) {
					if ( ! $setup_method instanceof EmailMethod ) {
						break;
					}
					$setup_method->clear_pending_code( $user );
				}

				$code = isset( $_POST['brain2fa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['brain2fa_code'] ) ) : '';
				$data = array( 'code' => $code );
				if ( 'totp' === $setup_method_id ) {
					$data['secret'] = isset( $_POST['brain2fa_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['brain2fa_secret'] ) ) : '';
				}

				$result = $setup_method->save_setup( $user, $data );
				if ( is_wp_error( $result ) ) {
					self::add_notice( 'codes', 'error', $result->get_error_message() );
					// Keep the QR code the user already scanned.
					$outcome['totp_secret'] = $data['secret'] ?? '';
				} elseif ( is_array( $result ) && ! empty( $result['code_sent'] ) ) {
					self::add_notice(
						'codes',
						'info',
						sprintf(
							/* translators: %s: account email address */
							__( 'A verification code was sent to %s. Enter it below to finish setting up email codes.', 'brain-2fa' ),
							$user->user_email
						)
					);
				} elseif ( true === $result || ( is_array( $result ) && ! empty( $result['success'] ) ) ) {
					update_user_meta( $user->ID, 'brain2fa_method', $setup_method_id );
					Utils::clear_grace_period( $user->ID );
					Utils::send_factor_change_notification( $user, 'activated' );

					if ( is_array( $result ) && ! empty( $result['recovery_codes'] ) ) {
						$outcome['recovery_codes'] = $result['recovery_codes'];
						self::add_notice( 'codes', 'success', __( 'Two-factor authentication is active. Save your recovery codes below.', 'brain-2fa' ) );
					} else {
						self::add_notice( 'codes', 'success', __( 'Two-factor authentication is active.', 'brain-2fa' ) );
					}
				}
				break;
		}

		return $outcome;
	}

	/**
	 * Turn off the user's code method.
	 *
	 * @param WP_User $user           Current user.
	 * @param string  $current_method Method ID stored for the user.
	 * @return void
	 */
	private static function deactivate( WP_User $user, string $current_method ): void {
		$method = brain_2fa()->manager->get_method( $current_method );
		if ( $method ) {
			$method->save_setup( $user, array( 'brain2fa_deactivate' => '1' ) );
		}
		delete_user_meta( $user->ID, 'brain2fa_method' );
		Utils::send_factor_change_notification( $user, 'deactivated' );

		// Users whose role requires 2FA go straight back to setup instead of getting a new grace period,
		// unless their passkeys still count as a second factor.
		if ( Utils::is_2fa_required_for_user( $user ) && ! Utils::is_2fa_enabled_for_user( $user->ID ) ) {
			Utils::require_enrollment_now( $user );
			self::add_notice( 'codes', 'warning', __( 'Two-factor codes are turned off. Your role requires two-factor authentication, so set up a new method to continue using the site.', 'brain-2fa' ) );
		} else {
			self::add_notice( 'codes', 'success', __( 'Two-factor codes are turned off.', 'brain-2fa' ) );
		}
	}
}
