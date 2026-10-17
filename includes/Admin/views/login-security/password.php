<?php
/**
 * Login Security: password sign-in (passkey-only accounts).
 *
 * Shown only when the site allows passkey-only accounts and the user has a usable passkey.
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

use Brain_2FA\Admin\LoginSecurityPage;

defined( 'ABSPATH' ) || exit;
?>
<section class="brain2fa-card" id="brain2fa-password-sign-in" aria-labelledby="brain2fa-password-title">
	<h2 id="brain2fa-password-title" class="brain2fa-card-title"><?php esc_html_e( 'Password sign-in', 'brain-2fa' ); ?></h2>

	<?php LoginSecurityPage::render_notices( 'password' ); ?>

	<p class="brain2fa-method-status">
		<?php if ( $passkey_only ) : ?>
			<span class="brain2fa-pill brain2fa-status-on"><?php esc_html_e( 'Off', 'brain-2fa' ); ?></span>
			<?php esc_html_e( 'You sign in with a passkey only.', 'brain-2fa' ); ?>
		<?php else : ?>
			<span class="brain2fa-pill brain2fa-status-off"><?php esc_html_e( 'On', 'brain-2fa' ); ?></span>
			<?php esc_html_e( 'You can sign in with your password or a passkey.', 'brain-2fa' ); ?>
		<?php endif; ?>
	</p>

	<?php if ( $passkey_only ) : ?>
		<p><?php esc_html_e( 'Turning password sign-in back on lets you sign in with your password again, followed by your second step if you have one.', 'brain-2fa' ); ?></p>
	<?php else : ?>
		<p><?php esc_html_e( 'Turn off password sign-in so your account can only be accessed with a passkey. This protects you from password phishing and leaked passwords.', 'brain-2fa' ); ?></p>
		<ul class="brain2fa-consequences">
			<li><?php esc_html_e( 'Recovery codes and emailed codes cannot be used, because they are entered after your password.', 'brain-2fa' ); ?></li>
			<li><?php esc_html_e( 'If you lose all your passkeys, only an administrator can restore password sign-in.', 'brain-2fa' ); ?></li>
		</ul>
		<?php if ( count( $passkeys ) < 2 ) : ?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Tip: add a second passkey on another device first.', 'brain-2fa' ); ?></p>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<form method="post" action="">
		<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
		<input type="hidden" name="brain2fa_action" value="passkey_only">
		<input type="hidden" name="brain2fa_passkey_only" value="<?php echo $passkey_only ? '0' : '1'; ?>">
		<div class="brain2fa-field">
			<label for="brain2fa_passkey_only_password"><?php esc_html_e( 'Confirm your password', 'brain-2fa' ); ?></label>
			<input type="password" id="brain2fa_passkey_only_password" name="brain2fa_confirm_password" class="regular-text" autocomplete="current-password" required>
			<?php if ( $passkey_only ) : ?>
				<button type="submit" class="button"><?php esc_html_e( 'Turn password sign-in back on', 'brain-2fa' ); ?></button>
			<?php else : ?>
				<button type="submit" class="button brain2fa-button-danger"><?php esc_html_e( 'Turn off password sign-in', 'brain-2fa' ); ?></button>
			<?php endif; ?>
		</div>
	</form>
</section>
