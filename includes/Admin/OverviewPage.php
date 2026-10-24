<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Overview page
 *
 * @package Brain_2FA\Admin
 * @since 1.2.0
 */

namespace Brain_2FA\Admin;

use Brain_2FA\Enrollment\EnrollmentReport;
use Brain_2FA\Enrollment\EnrollmentStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Overview page
 *
 * Shows how the site's users are protected: counts per enrollment status and per
 * configured method, for all roles or one role, each linking to the matching
 * filtered Users list. Read-only: viewing it starts no setup deadline.
 *
 * @since 1.2.0
 */
class OverviewPage {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'brain-2fa-overview';

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$role_names = wp_roles()->get_names();
		$role       = isset( $_GET['role'] ) && is_string( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter; the value is checked against the site's roles.
		$role       = isset( $role_names[ $role ] ) ? $role : '';
		$refresh    = isset( $_GET['_wpnonce'] ) && is_string( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'brain2fa_overview_refresh' );

		$snapshot        = EnrollmentReport::get_snapshot( $role, (bool) $refresh );
		$policy          = EnrollmentReport::get_policy();
		$status_labels   = EnrollmentStatus::get_labels();
		$method_labels   = EnrollmentStatus::get_method_labels();
		$refresh_url     = wp_nonce_url( add_query_arg( array_filter( array( 'role' => $role ) ), self::get_url() ), 'brain2fa_overview_refresh' );
		$requirement_url = Settings::get_tab_url( 'requirements' );
		$methods_url     = Settings::get_tab_url( 'methods' );

		include BRAIN_2FA_PLUGIN_DIR . 'includes/Admin/views/overview.php';
	}

	/**
	 * Get the page URL.
	 *
	 * @return string URL.
	 */
	public static function get_url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Get the Users list URL for a status or method, keeping the role filter.
	 *
	 * @param string $role  Role slug, or '' for all roles.
	 * @param string $key   EnrollmentStatus::QUERY_VAR or EnrollmentStatus::METHOD_QUERY_VAR.
	 * @param string $value Status or method.
	 * @return string URL.
	 */
	public static function get_users_url( string $role, string $key, string $value ): string {
		return add_query_arg(
			array_filter(
				array(
					$key   => $value,
					'role' => $role,
				)
			),
			admin_url( 'users.php' )
		);
	}

	/**
	 * Get a one-line explanation of a status for administrators.
	 *
	 * @param string $status Status.
	 * @return string Translated explanation.
	 */
	public static function get_status_description( string $status ): string {
		$descriptions = array(
			EnrollmentStatus::STATUS_PROTECTED   => __( 'Have a method they can use: a code method this site offers, or a passkey.', 'brain-2fa' ),
			EnrollmentStatus::STATUS_PENDING     => __( 'Required to set up two-factor authentication, before a deadline that has not passed yet.', 'brain-2fa' ),
			EnrollmentStatus::STATUS_OVERDUE     => __( 'Missed their setup deadline. They can sign in, but only reach the setup page.', 'brain-2fa' ),
			EnrollmentStatus::STATUS_NOT_STARTED => __( 'Required, but have not signed in since. Their grace period starts at their next sign-in.', 'brain-2fa' ),
			EnrollmentStatus::STATUS_UNAVAILABLE => __( 'Required, but the site offers no code method, so setup cannot be enforced.', 'brain-2fa' ),
			EnrollmentStatus::STATUS_METHOD_OFF  => __( 'Set up a code method the site has since turned off, and have no passkey. They cannot finish a password sign-in.', 'brain-2fa' ),
			EnrollmentStatus::STATUS_OPTIONAL    => __( 'Not required and have not set up two-factor authentication.', 'brain-2fa' ),
			EnrollmentStatus::STATUS_INACTIVE    => __( 'Two-factor authentication is off for this site, so nobody is asked for a second step.', 'brain-2fa' ),
		);

		return $descriptions[ $status ] ?? '';
	}
}
