<?php // phpcs:disable PSR1.Files.SideEffects
/**
 * User Profile Integration
 *
 * Adds 2FA settings to user profile pages.
 *
 * @package Brain_2FA\Admin
 * @since 1.0.0
 */

namespace Brain_2FA\Admin;

use Brain_2FA\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * UserProfile class for managing 2FA in user profiles.
 *
 * @since 1.0.0
 */
class UserProfile {

	/**
	 * Singleton instance.
	 *
	 * @var UserProfile|null
	 */
	protected static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return UserProfile
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'show_user_profile', array( $this, 'render_user_2fa_settings' ) );
		add_action( 'edit_user_profile', array( $this, 'render_user_2fa_settings' ) );
		add_action( 'personal_options_update', array( $this, 'save_user_2fa_settings' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_user_2fa_settings' ) );
		add_action( 'admin_notices', array( $this, 'render_grace_period_notice' ) );
	}

	/**
	 * Render 2FA settings in user profile.
	 *
	 * @param \WP_User $user User object.
	 * @return void
	 */
	public function render_user_2fa_settings( $user ): void {
		// Check if 2FA is enabled site-wide.
		if ( ! Utils::is_2fa_enabled_sitewide() ) {
			return;
		}

		$is_enabled              = get_user_meta( $user->ID, 'brain2fa_enabled', true );
		$grace_period_expires_at = ! $is_enabled && Utils::is_2fa_required_for_user( $user ) ? Utils::get_grace_period_expiry( $user ) : 0;

		if ( $grace_period_expires_at > 0 && current_user_can( 'manage_options' ) ) {
			wp_nonce_field( 'brain2fa_extend_grace', 'brain2fa_grace_nonce' );
		}
		?>
		<h2><?php esc_html_e( 'Two-Factor Authentication', 'brain-2fa' ); ?></h2>
		
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<?php esc_html_e( '2FA Status', 'brain-2fa' ); ?>
				</th>
				<td>
					<?php if ( $is_enabled ) : ?>
						<span class="brain2fa-status brain2fa-status-enabled">
							<span class="dashicons dashicons-shield-alt"></span>
							<?php esc_html_e( 'Two-Factor Authentication is Active', 'brain-2fa' ); ?>
						</span>
					<?php else : ?>
						<span class="brain2fa-status brain2fa-status-disabled">
							<span class="dashicons dashicons-shield"></span>
							<?php esc_html_e( 'Two-Factor Authentication is Inactive', 'brain-2fa' ); ?>
						</span>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'Add an extra layer of security to your account.', 'brain-2fa' ); ?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=brain-2fa-login-security' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage 2FA', 'brain-2fa' ); ?>
						</a>
					</p>
				</td>
			</tr>
			<?php if ( $grace_period_expires_at > 0 ) : ?>
				<tr>
					<th scope="row">
						<?php esc_html_e( '2FA Setup Grace Period', 'brain-2fa' ); ?>
					</th>
					<td>
						<?php if ( Utils::is_grace_period_expired( $user ) ) : ?>
							<span class="brain2fa-status brain2fa-status-disabled">
								<span class="dashicons dashicons-warning"></span>
								<?php esc_html_e( 'Grace period expired', 'brain-2fa' ); ?>
							</span>
							<p class="description">
								<?php
								printf(
									/* translators: %s: grace period expiry date */
									esc_html__( '2FA setup was required by %s.', 'brain-2fa' ),
									esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $grace_period_expires_at ) )
								);
								?>
							</p>
						<?php else : ?>
							<span class="brain2fa-status brain2fa-status-enabled">
								<span class="dashicons dashicons-clock"></span>
								<?php esc_html_e( 'Grace period active', 'brain-2fa' ); ?>
							</span>
							<p class="description">
								<?php
								printf(
									/* translators: %s: grace period expiry date */
									esc_html__( 'Set up 2FA before %s.', 'brain-2fa' ),
									esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $grace_period_expires_at ) )
								);
								?>
							</p>
						<?php endif; ?>

						<?php if ( current_user_can( 'manage_options' ) ) : ?>
							<p>
								<label for="brain2fa_extend_grace_days"><?php esc_html_e( 'Extend grace period by (days)', 'brain-2fa' ); ?></label>
								<input type="number" id="brain2fa_extend_grace_days" name="brain2fa_extend_grace_days" min="1" max="365" step="1">
							</p>
							<p class="description">
								<?php esc_html_e( 'Enter the number of days to add, then update the profile.', 'brain-2fa' ); ?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
		</table>
		<?php
	}

	/**
	 * Save a grace-period extension from a user profile.
	 *
	 * @param int $user_id User ID being updated.
	 * @return void
	 */
	public function save_user_2fa_settings( int $user_id ): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['brain2fa_grace_nonce'] ) || ! is_string( $_POST['brain2fa_grace_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['brain2fa_grace_nonce'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! wp_verify_nonce( $nonce, 'brain2fa_extend_grace' ) ) {
			return;
		}

		$extension_days = isset( $_POST['brain2fa_extend_grace_days'] ) && is_string( $_POST['brain2fa_extend_grace_days'] ) ? absint( wp_unslash( $_POST['brain2fa_extend_grace_days'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $extension_days < 1 ) {
			return;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return;
		}

		Utils::extend_grace_period( $user, min( 365, $extension_days ) );
	}

	/**
	 * Render the current user's setup deadline on the WordPress dashboard.
	 *
	 * @return void
	 */
	public function render_grace_period_notice(): void {
		global $pagenow;

		if ( 'index.php' !== $pagenow ) {
			return;
		}

		$user = wp_get_current_user();
		if ( ! $user instanceof \WP_User || ! Utils::is_2fa_required_for_user( $user ) || Utils::is_2fa_enabled_for_user( $user->ID ) ) {
			return;
		}

		$grace_period_expires_at = Utils::get_grace_period_expiry( $user );
		if ( $grace_period_expires_at < 1 ) {
			return;
		}

		$setup_url = admin_url( 'admin.php?page=brain-2fa-login-security' );
		if ( Utils::is_grace_period_expired( $user ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<?php esc_html_e( 'Your grace period to set up two-factor authentication has expired.', 'brain-2fa' ); ?>
					<a href="<?php echo esc_url( $setup_url ); ?>"><?php esc_html_e( 'Set up 2FA now.', 'brain-2fa' ); ?></a>
				</p>
			</div>
			<?php
			return;
		}

		$days_remaining = max( 1, (int) ceil( ( $grace_period_expires_at - time() ) / DAY_IN_SECONDS ) );
		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %d: number of grace-period days remaining */
					esc_html( _n( 'You have %d day left to set up two-factor authentication.', 'You have %d days left to set up two-factor authentication.', $days_remaining, 'brain-2fa' ) ),
					absint( $days_remaining )
				);
				?>
				<a href="<?php echo esc_url( $setup_url ); ?>"><?php esc_html_e( 'Set up 2FA now.', 'brain-2fa' ); ?></a>
			</p>
		</div>
		<?php
	}
}
