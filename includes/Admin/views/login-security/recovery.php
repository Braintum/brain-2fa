<?php
/**
 * Login Security: recovery codes for the authenticator app.
 *
 * Newly generated codes exist only in this response, so they are shown here once.
 * The download button is handled by src/js/admin.js.
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

use Brain_2FA\Admin\LoginSecurityPage;

defined( 'ABSPATH' ) || exit;
?>
<section class="brain2fa-card" id="brain2fa-recovery" aria-labelledby="brain2fa-recovery-title">
	<h2 id="brain2fa-recovery-title" class="brain2fa-card-title"><?php esc_html_e( 'Recovery codes', 'brain-2fa' ); ?></h2>
	<p class="brain2fa-card-intro"><?php esc_html_e( 'If you lose access to your authenticator app, sign in with your password and one of these codes instead. Each code works once.', 'brain-2fa' ); ?></p>

	<?php if ( $passkey_only ) : ?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'While password sign-in is off, recovery codes cannot be used, because they are entered after your password. If you lose all your passkeys, an administrator restores password sign-in.', 'brain-2fa' ); ?></p>
		</div>
	<?php endif; ?>

	<?php LoginSecurityPage::render_notices( 'recovery' ); ?>

	<?php if ( ! empty( $fresh_recovery_codes ) ) : ?>
		<div class="brain2fa-recovery-codes-display">
			<p class="brain2fa-recovery-codes-warning"><strong><?php esc_html_e( 'Save these codes now. They will not be shown again.', 'brain-2fa' ); ?></strong></p>
			<ul class="brain2fa-recovery-codes-list">
				<?php foreach ( $fresh_recovery_codes as $brain2fa_code ) : ?>
					<li><code><?php echo esc_html( $brain2fa_code ); ?></code></li>
				<?php endforeach; ?>
			</ul>
			<button type="button"
				id="brain2fa-download-codes"
				class="button"
				data-codes="<?php echo esc_attr( wp_json_encode( $fresh_recovery_codes ) ); ?>"
				data-site="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
				data-user="<?php echo esc_attr( $current_user->user_login ); ?>"
				data-email="<?php echo esc_attr( $current_user->user_email ); ?>">
				<?php esc_html_e( 'Download recovery codes', 'brain-2fa' ); ?>
			</button>
		</div>
	<?php else : ?>
		<p class="<?php echo $recovery_codes_count < 3 ? 'brain2fa-low-codes' : ''; ?>">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of unused recovery codes */
					_n( 'You have %s unused recovery code.', 'You have %s unused recovery codes.', $recovery_codes_count, 'brain-2fa' ),
					number_format_i18n( $recovery_codes_count )
				)
			);
			if ( $recovery_codes_count < 3 ) {
				echo ' ' . esc_html__( 'Generate new codes so you do not run out.', 'brain-2fa' );
			}
			?>
		</p>
	<?php endif; ?>

	<details class="brain2fa-disclosure"<?php echo esc_attr( LoginSecurityPage::open_attribute( 'regenerate' ) ); ?>>
		<summary><?php esc_html_e( 'Generate new codes', 'brain-2fa' ); ?></summary>
		<div class="brain2fa-disclosure-body">
			<?php LoginSecurityPage::render_notices( 'regenerate' ); ?>
			<p><?php esc_html_e( 'Your current recovery codes stop working as soon as new ones are generated.', 'brain-2fa' ); ?></p>
			<form method="post" action="">
				<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
				<input type="hidden" name="brain2fa_action" value="regenerate_recovery_codes">
				<div class="brain2fa-field">
					<label for="brain2fa_confirm_password_regenerate"><?php esc_html_e( 'Confirm your password', 'brain-2fa' ); ?></label>
					<input type="password" id="brain2fa_confirm_password_regenerate" name="brain2fa_confirm_password" class="regular-text" autocomplete="current-password" required>
					<button type="submit" class="button"><?php esc_html_e( 'Generate new codes', 'brain-2fa' ); ?></button>
				</div>
			</form>
		</div>
	</details>
</section>
