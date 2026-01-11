<?php
/**
 * Admin Settings Page Template
 *
 * @package Brain_2FA\Admin
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="wrap brain2fa-settings-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'brain2fa_messages' ); ?>

	<div class="brain2fa-settings-container">
		<div class="brain2fa-settings-main">
			<form action="options.php" method="post">
				<?php
				settings_fields( 'brain2fa_settings_group' );
				do_settings_sections( 'brain-2fa-settings' );
				submit_button( __( 'Save Settings', 'brain2fa' ) );
				?>
			</form>
		</div>
		<?php require 'sidebar.php'; ?>
	</div>
</div>
