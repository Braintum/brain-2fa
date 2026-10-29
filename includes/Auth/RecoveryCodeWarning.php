<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Low recovery code warning
 *
 * @package Brain_2FA\Auth
 * @since 1.3.0
 */

namespace Brain_2FA\Auth;

use Brain_2FA\TwoFactor\Methods\TotpMethod;
use Brain_2FA\Utils;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Warns the signed-in user when they are running out of recovery codes.
 *
 * Shown on site admin screens (except Login Security, whose recovery card already says it)
 * and on the WooCommerce My Account dashboard, with a link to the recovery codes on the
 * Login Security page. Read-only: it changes no state, and it disappears once the user has
 * more codes than the threshold or stops being eligible.
 *
 * @since 1.3.0
 */
class RecoveryCodeWarning {

	/**
	 * Warn at this many remaining codes or fewer.
	 *
	 * @var int
	 */
	public const THRESHOLD = 2;

	/**
	 * Register the notices.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ) );
		add_action( 'woocommerce_account_dashboard', array( __CLASS__, 'render_account_notice' ), 20 );
	}

	/**
	 * Get how many recovery codes a user has left, when they should be warned about it.
	 *
	 * The user must use the authenticator app as their code method, the site must offer it,
	 * and password sign-in must work for them: recovery codes are entered after the password,
	 * so a passkey-only account cannot use them.
	 *
	 * @param WP_User $user User.
	 * @return int|null Remaining codes, or null when no warning applies.
	 */
	public static function get_remaining( WP_User $user ): ?int {
		if ( ! $user->exists() || ! Utils::is_2fa_enabled_sitewide() || ! Utils::is_method_enabled( 'totp' ) ) {
			return null;
		}

		if ( ! Utils::is_code_method_enabled_for_user( $user->ID ) || 'totp' !== get_user_meta( $user->ID, 'brain2fa_method', true ) || Utils::is_passkey_only( $user ) ) {
			return null;
		}

		$totp = brain_2fa()->manager->get_method( 'totp' );
		if ( ! $totp instanceof TotpMethod ) {
			return null;
		}

		/**
		 * Filters how few recovery codes trigger the low recovery code warning.
		 *
		 * @since 1.3.0
		 *
		 * @param int     $threshold Warn at this many remaining codes or fewer. Default 2; a negative value turns the warning off.
		 * @param WP_User $user      User.
		 */
		$threshold = (int) apply_filters( 'brain2fa_recovery_codes_warning_threshold', self::THRESHOLD, $user );
		$remaining = $totp->get_recovery_codes_count( $user );

		return $remaining <= $threshold ? $remaining : null;
	}

	/**
	 * Get the warning text for a number of remaining codes.
	 *
	 * @param int $remaining Remaining codes.
	 * @return string Translated message.
	 */
	public static function get_message( int $remaining ): string {
		if ( $remaining < 1 ) {
			return __( 'You have no recovery codes left. Generate new codes while you can still access your account.', 'brain-2fa' );
		}

		return sprintf(
			/* translators: %s: number of unused recovery codes */
			_n( 'You have %s recovery code left. Generate new codes before you run out.', 'You have %s recovery codes left. Generate new codes before you run out.', $remaining, 'brain-2fa' ),
			number_format_i18n( $remaining )
		);
	}

	/**
	 * Get the link to the recovery codes on the Login Security page.
	 *
	 * @return string URL.
	 */
	public static function get_url(): string {
		return EnrollmentGuard::get_setup_url() . '#brain2fa-recovery';
	}

	/**
	 * Show the warning on site admin screens.
	 *
	 * @return void
	 */
	public static function render_admin_notice(): void {
		if ( ! is_user_logged_in() || EnrollmentGuard::is_setup_page_request() ) {
			return;
		}

		$remaining = self::get_remaining( wp_get_current_user() );
		if ( null === $remaining ) {
			return;
		}

		printf(
			'<div class="notice %1$s brain2fa-recovery-warning"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
			$remaining < 1 ? 'notice-error' : 'notice-warning',
			esc_html( self::get_message( $remaining ) ),
			esc_url( self::get_url() ),
			esc_html__( 'Manage recovery codes', 'brain-2fa' )
		);
	}

	/**
	 * Show the warning on the WooCommerce My Account dashboard.
	 *
	 * @return void
	 */
	public static function render_account_notice(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$remaining = self::get_remaining( wp_get_current_user() );
		if ( null === $remaining ) {
			return;
		}

		printf(
			'<div class="%1$s brain2fa-recovery-warning" role="%2$s">%3$s <a href="%4$s">%5$s</a></div>',
			$remaining < 1 ? 'woocommerce-error' : 'woocommerce-info',
			$remaining < 1 ? 'alert' : 'status',
			esc_html( self::get_message( $remaining ) ),
			esc_url( self::get_url() ),
			esc_html__( 'Manage recovery codes', 'brain-2fa' )
		);
	}
}
