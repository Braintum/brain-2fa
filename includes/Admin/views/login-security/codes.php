<?php
/**
 * Login Security: two-factor codes (authenticator app or email).
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

use Brain_2FA\Admin\LoginSecurityPage;

defined( 'ABSPATH' ) || exit;

$brain2fa_is_totp = 'totp' === $current_method;
?>
<section class="brain2fa-card" id="brain2fa-codes" aria-labelledby="brain2fa-codes-title">
	<h2 id="brain2fa-codes-title" class="brain2fa-card-title"><?php esc_html_e( 'Two-factor codes', 'brain-2fa' ); ?></h2>

	<?php LoginSecurityPage::render_notices( 'codes' ); ?>

	<?php if ( $is_2fa_enabled && in_array( $current_method, array( 'totp', 'email' ), true ) ) : ?>
		<p class="brain2fa-method-status">
			<?php if ( $current_method_ready ) : ?>
				<span class="brain2fa-pill brain2fa-status-on"><?php esc_html_e( 'Active', 'brain-2fa' ); ?></span>
			<?php else : ?>
				<span class="brain2fa-pill brain2fa-status-warning"><?php esc_html_e( 'Turned off by the site', 'brain-2fa' ); ?></span>
			<?php endif; ?>
			<strong><?php echo $brain2fa_is_totp ? esc_html__( 'Authenticator app', 'brain-2fa' ) : esc_html__( 'Email codes', 'brain-2fa' ); ?></strong>
		</p>
		<p>
			<?php
			if ( $brain2fa_is_totp ) {
				esc_html_e( 'After your password, you enter the 6-digit code from your authenticator app.', 'brain-2fa' );
			} else {
				echo esc_html(
					sprintf(
						/* translators: %s: account email address */
						__( 'After your password, you enter a code sent to %s.', 'brain-2fa' ),
						$current_user->user_email
					)
				);
			}
			?>
		</p>

		<?php if ( ! $current_method_ready ) : ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'An administrator turned this method off for the site, so it cannot be used to sign in. Turn it off below, then set up another method.', 'brain-2fa' ); ?></p>
			</div>
		<?php endif; ?>

		<details class="brain2fa-disclosure"<?php echo esc_attr( LoginSecurityPage::open_attribute( 'deactivate' ) ); ?>>
			<summary><?php esc_html_e( 'Turn off this method', 'brain-2fa' ); ?></summary>
			<div class="brain2fa-disclosure-body">
				<?php LoginSecurityPage::render_notices( 'deactivate' ); ?>
				<p>
					<?php
					if ( $brain2fa_is_totp ) {
						esc_html_e( 'Your account becomes less secure. Your recovery codes are deleted and trusted devices are forgotten.', 'brain-2fa' );
					} else {
						esc_html_e( 'Your account becomes less secure, and trusted devices are forgotten.', 'brain-2fa' );
					}
					?>
				</p>
				<?php if ( $role_requires_2fa && empty( $passkeys ) ) : ?>
					<p><strong><?php esc_html_e( 'Your role requires two-factor authentication. After turning this off, you must set up a new method before you can continue using the site.', 'brain-2fa' ); ?></strong></p>
				<?php endif; ?>
				<form method="post" action="">
					<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
					<input type="hidden" name="brain2fa_action" value="deactivate">
					<div class="brain2fa-field">
						<label for="brain2fa_confirm_password_deactivate"><?php esc_html_e( 'Confirm your password', 'brain-2fa' ); ?></label>
						<input type="password" id="brain2fa_confirm_password_deactivate" name="brain2fa_confirm_password" class="regular-text" autocomplete="current-password" required>
						<button type="submit" class="button brain2fa-button-danger"><?php esc_html_e( 'Turn off', 'brain-2fa' ); ?></button>
					</div>
				</form>
			</div>
		</details>

	<?php elseif ( ! $code_setup_available ) : ?>
		<p class="brain2fa-empty-state"><?php esc_html_e( 'This site does not offer two-factor codes right now.', 'brain-2fa' ); ?></p>

	<?php elseif ( 'email' === $setup_method_id ) : ?>
		<p class="brain2fa-card-intro">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: account email address */
					__( 'After your password, you enter a code sent to %s. First, confirm that email reaches you.', 'brain-2fa' ),
					$setup_data['email'] ?? $current_user->user_email
				)
			);
			?>
		</p>
		<ol class="brain2fa-steps">
			<li class="<?php echo $email_code_pending ? 'brain2fa-step-done' : 'brain2fa-step-current'; ?>">
				<span class="brain2fa-step-title"><?php esc_html_e( 'Send a verification code', 'brain-2fa' ); ?></span>
				<?php if ( $email_code_pending ) : ?>
					<form method="post" action="" class="brain2fa-inline-form">
						<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
						<input type="hidden" name="brain2fa_action" value="resend_email_code">
						<p><?php esc_html_e( 'Code sent.', 'brain-2fa' ); ?> <button type="submit" class="button-link"><?php esc_html_e( 'Send a new code', 'brain-2fa' ); ?></button></p>
					</form>
				<?php else : ?>
					<form method="post" action="">
						<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
						<input type="hidden" name="brain2fa_action" value="activate">
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Send verification code', 'brain-2fa' ); ?></button></p>
					</form>
				<?php endif; ?>
			</li>
			<li class="<?php echo $email_code_pending ? 'brain2fa-step-current' : 'brain2fa-step-pending'; ?>">
				<span class="brain2fa-step-title"><?php esc_html_e( 'Enter the code to turn on email codes', 'brain-2fa' ); ?></span>
				<?php if ( $email_code_pending ) : ?>
					<form method="post" action="">
						<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
						<input type="hidden" name="brain2fa_action" value="activate">
						<div class="brain2fa-field">
							<label for="brain2fa_email_setup_code"><?php esc_html_e( 'Verification code', 'brain-2fa' ); ?></label>
							<input type="text" id="brain2fa_email_setup_code" name="brain2fa_code" class="brain2fa-verification-input" placeholder="000000" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required>
						</div>
						<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Turn on email codes', 'brain-2fa' ); ?></button></p>
					</form>
				<?php endif; ?>
			</li>
		</ol>

	<?php else : ?>
		<p class="brain2fa-card-intro"><?php esc_html_e( 'After your password, you enter a 6-digit code from an authenticator app such as Google Authenticator, Microsoft Authenticator, Authy, 1Password or Bitwarden.', 'brain-2fa' ); ?></p>
		<form method="post" action="">
			<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
			<input type="hidden" name="brain2fa_action" value="activate">
			<input type="hidden" name="brain2fa_secret" value="<?php echo esc_attr( $setup_data['secret'] ); ?>">
			<ol class="brain2fa-steps">
				<li class="brain2fa-step-current">
					<span class="brain2fa-step-title"><?php esc_html_e( 'Add this account to your app', 'brain-2fa' ); ?></span>
					<?php if ( ! empty( $setup_data['qr_image'] ) ) : ?>
						<p><?php esc_html_e( 'Scan the QR code with your app.', 'brain-2fa' ); ?></p>
						<div class="brain2fa-qr-container">
							<img src="<?php echo esc_attr( $setup_data['qr_image'] ); ?>" alt="<?php esc_attr_e( 'QR code for your authenticator app', 'brain-2fa' ); ?>" />
						</div>
						<p><?php esc_html_e( 'Cannot scan it? Enter this key in your app instead:', 'brain-2fa' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Enter this key in your app:', 'brain-2fa' ); ?></p>
					<?php endif; ?>
					<div class="brain2fa-secret-code"><code><?php echo esc_html( $setup_data['secret'] ); ?></code></div>
				</li>
				<li class="brain2fa-step-current">
					<span class="brain2fa-step-title"><?php esc_html_e( 'Enter the code your app shows', 'brain-2fa' ); ?></span>
					<div class="brain2fa-field">
						<label for="brain2fa_code"><?php esc_html_e( 'Verification code', 'brain-2fa' ); ?></label>
						<input type="text" id="brain2fa_code" name="brain2fa_code" class="brain2fa-verification-input" placeholder="000000" maxlength="6" pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required>
					</div>
				</li>
				<li class="brain2fa-step-current">
					<span class="brain2fa-step-title"><?php esc_html_e( 'Turn it on', 'brain-2fa' ); ?></span>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Turn on authenticator app', 'brain-2fa' ); ?></button></p>
				</li>
			</ol>
		</form>
	<?php endif; ?>
</section>
