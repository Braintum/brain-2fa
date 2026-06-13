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
			<?php if ( $is_2fa_enabled && 'totp' === $current_method ) : ?>
				<!-- 2FA is Active - Show Deactivate Option -->
				<div class="brain2fa-card">
					<h2><?php esc_html_e( 'Two-Factor Authentication Status', 'brain2fa' ); ?></h2>
					<p>
						<span class="brain2fa-status brain2fa-status-enabled">
							<span class="dashicons dashicons-shield-alt"></span>
							<?php esc_html_e( 'Two-Factor Authentication is Active', 'brain2fa' ); ?>
						</span>
					</p>
					<p><?php esc_html_e( 'Your account is protected with TOTP (Time-based One-Time Password) authentication.', 'brain2fa' ); ?></p>
					<p class="description">
						<?php esc_html_e( 'You will need to enter a code from your authenticator app when logging in.', 'brain2fa' ); ?>
					</p>
					
					<hr style="margin: 20px 0;">
					
					<!-- Recovery Codes Section -->
					<div class="brain2fa-recovery-codes-section">
						<h3><?php esc_html_e( 'Recovery Codes', 'brain2fa' ); ?></h3>
						<p class="description">
							<?php
							printf(
								/* translators: %d: number of remaining recovery codes */
								esc_html__( 'You have %d recovery code(s) remaining. Recovery codes can be used to access your account if you lose access to your authenticator app.', 'brain2fa' ),
								esc_html( $recovery_codes_count )
							);
							?>
						</p>
						
						<?php if ( ! empty( $fresh_recovery_codes ) ) : ?>
							<!-- Display Fresh Recovery Codes -->
							<div class="brain2fa-recovery-codes-display" style="background: #f0f0f1; padding: 20px; border-radius: 4px; margin: 15px 0;">
								<p style="margin: 0 0 10px 0; color: #d63638; font-weight: 600;">
									<span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
									<?php esc_html_e( 'Save these codes now! They will not be shown again.', 'brain2fa' ); ?>
								</p>
								<div style="background: white; padding: 15px; border: 1px solid #dcdcde; border-radius: 4px; margin-top: 10px;">
									<div style="font-family: monospace; column-count: 2; column-gap: 20px;">
										<?php foreach ( $fresh_recovery_codes as $code ) : ?>
											<div style="margin-bottom: 8px; break-inside: avoid;"><?php echo esc_html( $code ); ?></div>
										<?php endforeach; ?>
									</div>
								</div>
								<button type="button"
									id="brain2fa-download-codes"
									class="button button-secondary"
									data-codes="<?php echo esc_attr( wp_json_encode( $fresh_recovery_codes ) ); ?>"
									data-site="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
									data-user="<?php echo esc_attr( $current_user->user_login ); ?>"
									data-email="<?php echo esc_attr( $current_user->user_email ); ?>"
									style="margin-top: 10px;">
									<span class="dashicons dashicons-download" style="margin-top: 3px;"></span>
									<?php esc_html_e( 'Download Recovery Codes', 'brain2fa' ); ?>
								</button>
							</div>
						<?php endif; ?>
						
						<form method="post" action="" style="margin-top: 15px;">
							<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
							<input type="hidden" name="brain2fa_action" value="regenerate_recovery_codes">
							<button type="submit" class="button button-secondary" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to regenerate recovery codes? This will invalidate all previous recovery codes.', 'brain2fa' ); ?>');">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Regenerate Recovery Codes', 'brain2fa' ); ?>
							</button>
						</form>
					</div>
					
					<hr style="margin: 20px 0;">
					
					<form method="post" action="">
						<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
						<input type="hidden" name="brain2fa_action" value="deactivate">
						
						<div class="brain2fa-warning" style="margin-bottom: 15px;">
							<p>
								<span class="dashicons dashicons-warning"></span>
								<strong><?php esc_html_e( 'Warning:', 'brain2fa' ); ?></strong>
								<?php esc_html_e( 'Deactivating 2FA will make your account less secure.', 'brain2fa' ); ?>
							</p>
						</div>
						
						<button type="submit" class="button button-secondary brain2fa-button-danger" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to deactivate Two-Factor Authentication?', 'brain2fa' ); ?>');">
							<span class="dashicons dashicons-shield" style="margin-top: 3px;"></span>
							<?php esc_html_e( 'Deactivate 2FA', 'brain2fa' ); ?>
						</button>
					</form>
				</div>
			<?php else : ?>
				<!-- 2FA is Not Active - Show Setup Form -->
				<div class="brain2fa-card">
					<h2><?php esc_html_e( 'Setup Two-Factor Authentication', 'brain2fa' ); ?></h2>
					<p><?php esc_html_e( 'Enhance your account security by enabling two-factor authentication using an authenticator app.', 'brain2fa' ); ?></p>
					
					<div class="brain2fa-instructions">
						<h4><?php esc_html_e( 'How to Set Up:', 'brain2fa' ); ?></h4>
						<ol>
							<li><?php esc_html_e( 'Install an authenticator app on your phone (Google Authenticator, Authy, Microsoft Authenticator, etc.)', 'brain2fa' ); ?></li>
							<li><?php esc_html_e( 'Scan the QR code below with your authenticator app', 'brain2fa' ); ?></li>
							<li><?php esc_html_e( 'Enter the 6-digit code from your app to verify and activate', 'brain2fa' ); ?></li>
						</ol>
					</div>
					
					<?php if ( ! empty( $setup_data ) ) : ?>
						<form method="post" action="">
							<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
							<input type="hidden" name="brain2fa_action" value="activate">
							<input type="hidden" name="brain2fa_secret" value="<?php echo esc_attr( $setup_data['secret'] ); ?>">
							
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row">
										<label><?php esc_html_e( 'QR Code', 'brain2fa' ); ?></label>
									</th>
									<td>
										<div class="brain2fa-qr-container">
											<img src="<?php echo esc_attr( $setup_data['qr_image'] ); ?>" alt="<?php esc_attr_e( 'TOTP QR Code', 'brain2fa' ); ?>" />
										</div>
										<p class="description">
											<?php esc_html_e( 'Scan this QR code with your authenticator app.', 'brain2fa' ); ?>
										</p>
									</td>
								</tr>
								<!--<tr>
									<th scope="row">
										<label><?php esc_html_e( 'Secret Key', 'brain2fa' ); ?></label>
									</th>
									<td>
										<div class="brain2fa-secret-code">
											<code><?php echo esc_html( $setup_data['secret'] ); ?></code>
										</div>
										<p class="description">
											<?php esc_html_e( 'If you cannot scan the QR code, manually enter this secret key into your authenticator app.', 'brain2fa' ); ?>
										</p>
									</td>
								</tr>-->
								<tr>
									<th scope="row">
										<label for="brain2fa_code"><?php esc_html_e( 'Verification Code', 'brain2fa' ); ?></label>
									</th>
									<td>
										<input type="text" 
											id="brain2fa_code" 
											name="brain2fa_code" 
											class="brain2fa-verification-input" 
											placeholder="000000" 
											maxlength="6" 
											pattern="[0-9]{6}" 
											required
											autocomplete="off">
										<p class="description">
											<?php esc_html_e( 'Enter the 6-digit code from your authenticator app to verify and activate 2FA.', 'brain2fa' ); ?>
										</p>
									</td>
								</tr>
							</table>
							
							<p class="submit">
								<button type="submit" class="button button-primary brain2fa-button-primary">
									<span class="dashicons dashicons-shield-alt" style="margin-top: 3px;"></span>
									<?php esc_html_e( 'Activate Two-Factor Authentication', 'brain2fa' ); ?>
								</button>
							</p>
						</form>
					<?php else : ?>
						<p class="notice notice-error">
							<?php esc_html_e( 'Unable to generate QR code. Please ensure the required libraries are installed.', 'brain2fa' ); ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>

	</div>
</div>