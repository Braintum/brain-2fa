<?php
/**
 * Sidebar Template
 *
 * @package Brain_2FA\Admin
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="brain2fa-settings-sidebar">
	<div class="brain2fa-card">
		<h3><?php esc_html_e( 'About Brain 2FA', 'brain2fa' ); ?></h3>
		<p><?php esc_html_e( 'Brain 2FA adds an extra layer of security to your WordPress site by requiring users to verify their identity using a second factor.', 'brain2fa' ); ?></p>
		<p><strong><?php esc_html_e( 'Version:', 'brain2fa' ); ?></strong> <?php echo esc_html( BRAIN_2FA_VERSION ); ?></p>
	</div>

	<div class="brain2fa-card">
		<h3><?php esc_html_e( 'Getting Started', 'brain2fa' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Enable two-factor authentication above', 'brain2fa' ); ?></li>
			<li><?php esc_html_e( 'Choose which authentication methods to enable', 'brain2fa' ); ?></li>
			<li><?php esc_html_e( 'Users can set up 2FA in their profile settings', 'brain2fa' ); ?></li>
			<li><?php esc_html_e( 'Optionally force 2FA for specific user roles', 'brain2fa' ); ?></li>
		</ol>
	</div>

	<div class="brain2fa-card">
		<h3><?php esc_html_e( 'Quick Stats', 'brain2fa' ); ?></h3>
		<?php
		$users_with_2fa = get_users(
			array(
				'meta_key'   => 'brain2fa_enabled',
				'meta_value' => '1',
				'count_total' => true,
				'fields'      => 'ID',
			)
		);
		$total_users = count_users();
		?>
		<p>
			<strong><?php esc_html_e( 'Users with 2FA:', 'brain2fa' ); ?></strong> 
			<?php echo esc_html( count( $users_with_2fa ) ); ?> / <?php echo esc_html( $total_users['total_users'] ); ?>
		</p>
	</div>

	<div class="brain2fa-card">
		<h3><?php esc_html_e( 'Support', 'brain2fa' ); ?></h3>
		<p><?php esc_html_e( 'Need help? Check out our documentation or contact support.', 'brain2fa' ); ?></p>
		<p>
			<a href="https://www.braintum.com/" class="button button-secondary" target="_blank">
				<?php esc_html_e( 'Documentation', 'brain2fa' ); ?>
			</a>
		</p>
	</div>
</div>