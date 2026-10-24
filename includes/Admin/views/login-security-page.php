<?php
/**
 * Login Security Page Template
 *
 * Rendered by LoginSecurityPage::render(), whose local variables are in scope here
 * and in the section templates under views/login-security/.
 *
 * @package Brain_2FA\Admin
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

$brain2fa_page_dir = __DIR__ . '/login-security/';
?>
<div class="wrap brain2fa-login-security">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'brain2fa_messages' ); ?>

	<?php if ( $must_enroll ) : ?>
		<div class="notice notice-warning inline brain2fa-enroll-notice">
			<p><strong><?php echo esc_html( \Brain_2FA\Auth\EnrollmentGuard::get_restriction_message() ); ?></strong></p>
			<p>
				<?php if ( $passkeys_enabled ) : ?>
					<a class="button button-primary" href="#brain2fa-passkeys"><?php esc_html_e( 'Add a passkey', 'brain-2fa' ); ?></a>
				<?php endif; ?>
				<?php if ( $code_setup_available ) : ?>
					<a class="button<?php echo $passkeys_enabled ? '' : ' button-primary'; ?>" href="#brain2fa-codes"><?php esc_html_e( 'Set up two-factor codes', 'brain-2fa' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
	<?php endif; ?>

	<?php
	require $brain2fa_page_dir . 'summary.php';

	if ( $passkeys_setting ) {
		require $brain2fa_page_dir . 'passkeys.php';
	}

	require $brain2fa_page_dir . 'codes.php';

	if ( $show_recovery ) {
		require $brain2fa_page_dir . 'recovery.php';
	}

	if ( $remember_enabled || $trusted_devices ) {
		require $brain2fa_page_dir . 'devices.php';
	}

	if ( $passkey_only_allowed && ! empty( $passkeys ) ) {
		require $brain2fa_page_dir . 'password.php';
	}
	?>
</div>
