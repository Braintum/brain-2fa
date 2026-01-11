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
	}

	/**
	 * Render 2FA settings in user profile.
	 *
	 * @param \WP_User $user User object.
	 * @return void
	 */
	public function render_user_2fa_settings( $user ): void {
		$plugin_settings = get_option( 'brain2fa_settings', array() );

		// Check if 2FA is enabled site-wide.
		if ( empty( $plugin_settings['enable_2fa'] ) ) {
			return;
		}

		$is_enabled = get_user_meta( $user->ID, 'brain2fa_enabled', true );
		wp_nonce_field( 'brain2fa_user_settings', 'brain2fa_nonce' );
		?>
		<h2><?php esc_html_e( 'Two-Factor Authentication', 'brain2fa' ); ?></h2>
		
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<?php esc_html_e( '2FA Status', 'brain2fa' ); ?>
				</th>
				<td>
					<?php if ( $is_enabled ) : ?>
						<span class="brain2fa-status brain2fa-status-enabled">
							<span class="dashicons dashicons-shield-alt"></span>
							<?php esc_html_e( 'Two-Factor Authentication is Active', 'brain2fa' ); ?>
						</span>
					<?php else : ?>
						<span class="brain2fa-status brain2fa-status-disabled">
							<span class="dashicons dashicons-shield"></span>
							<?php esc_html_e( 'Two-Factor Authentication is Inactive', 'brain2fa' ); ?>
						</span>
					<?php endif; ?>
					<p class="description">
						<?php esc_html_e( 'Add an extra layer of security to your account.', 'brain2fa' ); ?>
					</p>
					<p>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=brain-2fa-login-security' ) ); ?>" class="button button-secondary">
							<?php esc_html_e( 'Manage 2FA', 'brain2fa' ); ?>
						</a>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}
}
