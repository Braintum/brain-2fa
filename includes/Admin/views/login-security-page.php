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

	<?php if ( $must_enroll ) : ?>
		<div class="notice notice-warning">
			<p><strong><?php echo esc_html( \Brain_2FA\Auth\EnrollmentGuard::get_restriction_message() ); ?></strong></p>
		</div>
	<?php endif; ?>

	<div class="brain2fa-settings-container">
		<div class="brain2fa-settings-main">
			<?php if ( $is_2fa_enabled && 'totp' === $current_method ) : ?>
				<!-- 2FA is Active - Show Deactivate Option -->
				<div class="brain2fa-card">
					<h2><?php esc_html_e( 'Two-Factor Authentication Status', 'brain-2fa' ); ?></h2>
					<p>
						<span class="brain2fa-status brain2fa-status-enabled">
							<span class="dashicons dashicons-shield-alt"></span>
							<?php esc_html_e( 'Two-Factor Authentication is Active', 'brain-2fa' ); ?>
						</span>
					</p>
					<p><?php esc_html_e( 'Your account is protected with TOTP (Time-based One-Time Password) authentication.', 'brain-2fa' ); ?></p>
					<p class="description">
						<?php esc_html_e( 'You will need to enter a code from your authenticator app when logging in.', 'brain-2fa' ); ?>
					</p>
					
					<hr style="margin: 20px 0;">
					
					<!-- Recovery Codes Section -->
					<div class="brain2fa-recovery-codes-section">
						<h3><?php esc_html_e( 'Recovery Codes', 'brain-2fa' ); ?></h3>
						<p class="description">
							<?php
							printf(
								/* translators: %d: number of remaining recovery codes */
								esc_html__( 'You have %d recovery code(s) remaining. Recovery codes can be used to access your account if you lose access to your authenticator app.', 'brain-2fa' ),
								esc_html( $recovery_codes_count )
							);
							?>
						</p>
						
						<?php if ( ! empty( $fresh_recovery_codes ) ) : ?>
							<!-- Display Fresh Recovery Codes -->
							<div class="brain2fa-recovery-codes-display" style="background: #f0f0f1; padding: 20px; border-radius: 4px; margin: 15px 0;">
								<p style="margin: 0 0 10px 0; color: #d63638; font-weight: 600;">
									<span class="dashicons dashicons-warning" style="vertical-align: middle;"></span>
									<?php esc_html_e( 'Save these codes now! They will not be shown again.', 'brain-2fa' ); ?>
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
									<?php esc_html_e( 'Download Recovery Codes', 'brain-2fa' ); ?>
								</button>
							</div>
						<?php endif; ?>
						
						<form method="post" action="" style="margin-top: 15px;">
							<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
							<input type="hidden" name="brain2fa_action" value="regenerate_recovery_codes">
							<p>
								<label for="brain2fa_confirm_password_regenerate"><?php esc_html_e( 'Confirm your password', 'brain-2fa' ); ?></label><br>
								<input type="password" id="brain2fa_confirm_password_regenerate" name="brain2fa_confirm_password" class="regular-text" autocomplete="current-password" required>
							</p>
							<button type="submit" class="button button-secondary" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to regenerate recovery codes? This will invalidate all previous recovery codes.', 'brain-2fa' ); ?>');">
								<span class="dashicons dashicons-update" style="margin-top: 3px;"></span>
								<?php esc_html_e( 'Regenerate Recovery Codes', 'brain-2fa' ); ?>
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
								<strong><?php esc_html_e( 'Warning:', 'brain-2fa' ); ?></strong>
								<?php esc_html_e( 'Deactivating 2FA will make your account less secure.', 'brain-2fa' ); ?>
							</p>
						</div>
						
						<?php if ( $role_requires_2fa ) : ?>
							<p class="description"><?php esc_html_e( 'Your role requires two-factor authentication. After deactivating, you must set up a new method before you can continue using the site.', 'brain-2fa' ); ?></p>
						<?php endif; ?>
						<p>
							<label for="brain2fa_confirm_password_deactivate_totp"><?php esc_html_e( 'Confirm your password', 'brain-2fa' ); ?></label><br>
							<input type="password" id="brain2fa_confirm_password_deactivate_totp" name="brain2fa_confirm_password" class="regular-text" autocomplete="current-password" required>
						</p>
						
						<button type="submit" class="button button-secondary brain2fa-button-danger" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to deactivate Two-Factor Authentication?', 'brain-2fa' ); ?>');">
							<span class="dashicons dashicons-shield" style="margin-top: 3px;"></span>
							<?php esc_html_e( 'Deactivate 2FA', 'brain-2fa' ); ?>
						</button>
					</form>
				</div>
			<?php elseif ( $is_2fa_enabled && 'email' === $current_method ) : ?>
				<div class="brain2fa-card">
					<h2><?php esc_html_e( 'Two-Factor Authentication Status', 'brain-2fa' ); ?></h2>
					<p>
						<span class="brain2fa-status brain2fa-status-enabled">
							<span class="dashicons dashicons-shield-alt"></span>
							<?php esc_html_e( 'Two-Factor Authentication is Active', 'brain-2fa' ); ?>
						</span>
					</p>
					<p><?php esc_html_e( 'Your account is protected with email authentication.', 'brain-2fa' ); ?></p>
					<p class="description">
						<?php esc_html_e( 'A verification code will be sent to your account email address when you log in.', 'brain-2fa' ); ?>
					</p>
					<form method="post" action="">
						<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
						<input type="hidden" name="brain2fa_action" value="deactivate">
						<?php if ( $role_requires_2fa ) : ?>
							<p class="description"><?php esc_html_e( 'Your role requires two-factor authentication. After deactivating, you must set up a new method before you can continue using the site.', 'brain-2fa' ); ?></p>
						<?php endif; ?>
						<p>
							<label for="brain2fa_confirm_password_deactivate_email"><?php esc_html_e( 'Confirm your password', 'brain-2fa' ); ?></label><br>
							<input type="password" id="brain2fa_confirm_password_deactivate_email" name="brain2fa_confirm_password" class="regular-text" autocomplete="current-password" required>
						</p>
						<button type="submit" class="button button-secondary brain2fa-button-danger" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to deactivate Two-Factor Authentication?', 'brain-2fa' ); ?>');">
							<span class="dashicons dashicons-shield" style="margin-top: 3px;"></span>
							<?php esc_html_e( 'Deactivate 2FA', 'brain-2fa' ); ?>
						</button>
					</form>
				</div>
			<?php elseif ( 'email' === $setup_method_id ) : ?>
				<div class="brain2fa-card">
					<h2><?php esc_html_e( 'Set Up Email Authentication', 'brain-2fa' ); ?></h2>
					<p><?php esc_html_e( 'Receive a verification code at your account email address whenever you log in.', 'brain-2fa' ); ?></p>
					<p>
						<strong><?php esc_html_e( 'Email address:', 'brain-2fa' ); ?></strong>
						<?php echo esc_html( $setup_data['email'] ?? $current_user->user_email ); ?>
					</p>
					<?php if ( $email_code_pending ) : ?>
						<form method="post" action="">
							<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
							<input type="hidden" name="brain2fa_action" value="activate">
							<p>
								<label for="brain2fa_email_setup_code"><?php esc_html_e( 'Verification Code', 'brain-2fa' ); ?></label><br>
								<input type="text"
									id="brain2fa_email_setup_code"
									name="brain2fa_code"
									class="brain2fa-verification-input"
									placeholder="000000"
									maxlength="6"
									pattern="[0-9]{6}"
									inputmode="numeric"
									autocomplete="one-time-code"
									required>
							</p>
							<p class="description"><?php esc_html_e( 'Enter the 6-digit code we emailed you to confirm that email delivery works.', 'brain-2fa' ); ?></p>
							<p class="submit">
								<button type="submit" class="button button-primary brain2fa-button-primary">
									<span class="dashicons dashicons-shield-alt" style="margin-top: 3px;"></span>
									<?php esc_html_e( 'Activate Email Authentication', 'brain-2fa' ); ?>
								</button>
							</p>
						</form>
						<form method="post" action="">
							<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
							<input type="hidden" name="brain2fa_action" value="resend_email_code">
							<button type="submit" class="button-link"><?php esc_html_e( 'Send a new code', 'brain-2fa' ); ?></button>
						</form>
					<?php else : ?>
						<form method="post" action="">
							<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
							<input type="hidden" name="brain2fa_action" value="activate">
							<p class="description"><?php esc_html_e( 'We will email you a verification code to confirm that email delivery works before turning this on.', 'brain-2fa' ); ?></p>
							<p class="submit">
								<button type="submit" class="button button-primary brain2fa-button-primary">
									<span class="dashicons dashicons-email" style="margin-top: 3px;"></span>
									<?php esc_html_e( 'Send Verification Code', 'brain-2fa' ); ?>
								</button>
							</p>
						</form>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<!-- 2FA is Not Active - Show Setup Form -->
				<div class="brain2fa-card">
					<h2><?php esc_html_e( 'Setup Two-Factor Authentication', 'brain-2fa' ); ?></h2>
					<p><?php esc_html_e( 'Enhance your account security by enabling two-factor authentication using an authenticator app.', 'brain-2fa' ); ?></p>
					
					<div class="brain2fa-instructions">
						<h4><?php esc_html_e( 'How to Set Up:', 'brain-2fa' ); ?></h4>
						<ol>
							<li><?php esc_html_e( 'Install an authenticator app on your phone (Google Authenticator, Authy, Microsoft Authenticator, etc.)', 'brain-2fa' ); ?></li>
							<li><?php esc_html_e( 'Scan the QR code below with your authenticator app', 'brain-2fa' ); ?></li>
							<li><?php esc_html_e( 'Enter the 6-digit code from your app to verify and activate', 'brain-2fa' ); ?></li>
						</ol>
					</div>
					
					<?php if ( ! empty( $setup_data ) ) : ?>
						<form method="post" action="">
							<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
							<input type="hidden" name="brain2fa_action" value="activate">
							<input type="hidden" name="brain2fa_secret" value="<?php echo esc_attr( $setup_data['secret'] ); ?>">
							
							<table class="form-table" role="presentation">
								<?php if ( ! empty( $setup_data['qr_image'] ) ) : ?>
								<tr>
									<th scope="row">
										<label><?php esc_html_e( 'QR Code', 'brain-2fa' ); ?></label>
									</th>
									<td>
										<div class="brain2fa-qr-container">
											<img src="<?php echo esc_attr( $setup_data['qr_image'] ); ?>" alt="<?php esc_attr_e( 'TOTP QR Code', 'brain-2fa' ); ?>" />
										</div>
										<p class="description">
											<?php esc_html_e( 'Scan this QR code with your authenticator app.', 'brain-2fa' ); ?>
										</p>
									</td>
								</tr>
								<?php endif; ?>
								<tr>
									<th scope="row">
										<label><?php esc_html_e( 'Secret Key', 'brain-2fa' ); ?></label>
									</th>
									<td>
										<div class="brain2fa-secret-code">
											<code><?php echo esc_html( $setup_data['secret'] ); ?></code>
										</div>
										<p class="description">
											<?php esc_html_e( 'If you cannot scan the QR code, manually enter this secret key into your authenticator app.', 'brain-2fa' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row">
										<label for="brain2fa_code"><?php esc_html_e( 'Verification Code', 'brain-2fa' ); ?></label>
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
											<?php esc_html_e( 'Enter the 6-digit code from your authenticator app to verify and activate 2FA.', 'brain-2fa' ); ?>
										</p>
									</td>
								</tr>
							</table>
							
							<p class="submit">
								<button type="submit" class="button button-primary brain2fa-button-primary">
									<span class="dashicons dashicons-shield-alt" style="margin-top: 3px;"></span>
									<?php esc_html_e( 'Activate Two-Factor Authentication', 'brain-2fa' ); ?>
								</button>
							</p>
						</form>
					<?php else : ?>
						<p class="notice notice-error">
							<?php esc_html_e( 'Unable to generate QR code. Please ensure the required libraries are installed.', 'brain-2fa' ); ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $passkeys_enabled ) : ?>
				<div class="brain2fa-card brain2fa-passkeys-card" id="brain2fa-passkeys">
					<h2><?php esc_html_e( 'Passkeys', 'brain-2fa' ); ?></h2>
					<p><?php esc_html_e( 'Sign in without a password using your device screen lock (Face ID, Touch ID, Windows Hello), your phone, or a security key. A passkey sign-in also counts as two-factor authentication.', 'brain-2fa' ); ?></p>

					<?php if ( empty( $passkeys ) ) : ?>
						<p class="description"><?php esc_html_e( 'You have not added any passkeys yet.', 'brain-2fa' ); ?></p>
					<?php else : ?>
						<table class="widefat striped brain2fa-passkeys-table">
							<thead>
								<tr>
									<th scope="col"><?php esc_html_e( 'Name', 'brain-2fa' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Added', 'brain-2fa' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Last used', 'brain-2fa' ); ?></th>
									<th scope="col"><?php esc_html_e( 'Manage', 'brain-2fa' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $passkeys as $passkey ) : ?>
									<tr>
										<td>
											<strong><?php echo esc_html( $passkey->name ); ?></strong>
											<?php if ( ! empty( $passkey->backed_up ) ) : ?>
												<br><span class="description"><?php esc_html_e( 'Synced across your devices', 'brain-2fa' ); ?></span>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( get_date_from_gmt( $passkey->created_at, get_option( 'date_format' ) ) ); ?></td>
										<td>
											<?php
											echo esc_html(
												$passkey->last_used_at
													? get_date_from_gmt( $passkey->last_used_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
													: __( 'Never', 'brain-2fa' )
											);
											?>
										</td>
										<td>
											<details>
												<summary><?php esc_html_e( 'Rename or remove', 'brain-2fa' ); ?></summary>
												<form method="post" action="">
													<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
													<input type="hidden" name="brain2fa_action" value="passkey_rename">
													<input type="hidden" name="brain2fa_passkey_id" value="<?php echo esc_attr( $passkey->id ); ?>">
													<p>
														<label for="brain2fa_passkey_name_<?php echo esc_attr( $passkey->id ); ?>"><?php esc_html_e( 'Name', 'brain-2fa' ); ?></label><br>
														<input type="text" id="brain2fa_passkey_name_<?php echo esc_attr( $passkey->id ); ?>" name="brain2fa_passkey_name" value="<?php echo esc_attr( $passkey->name ); ?>" maxlength="100" required>
														<button type="submit" class="button button-secondary"><?php esc_html_e( 'Rename', 'brain-2fa' ); ?></button>
													</p>
												</form>
												<form method="post" action="">
													<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
													<input type="hidden" name="brain2fa_action" value="passkey_remove">
													<input type="hidden" name="brain2fa_passkey_id" value="<?php echo esc_attr( $passkey->id ); ?>">
													<p>
														<label for="brain2fa_passkey_remove_password_<?php echo esc_attr( $passkey->id ); ?>"><?php esc_html_e( 'Confirm your password', 'brain-2fa' ); ?></label><br>
														<input type="password" id="brain2fa_passkey_remove_password_<?php echo esc_attr( $passkey->id ); ?>" name="brain2fa_confirm_password" autocomplete="current-password" required>
														<button type="submit" class="button button-secondary brain2fa-button-danger" onclick="return confirm('<?php esc_attr_e( 'Remove this passkey? You will no longer be able to sign in with it.', 'brain-2fa' ); ?>');"><?php esc_html_e( 'Remove', 'brain-2fa' ); ?></button>
													</p>
												</form>
											</details>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>

					<h3><?php esc_html_e( 'Add a passkey', 'brain-2fa' ); ?></h3>
					<div id="brain2fa-passkey-register">
						<p>
							<label for="brain2fa_passkey_new_name"><?php esc_html_e( 'Passkey name', 'brain-2fa' ); ?></label><br>
							<input type="text" id="brain2fa_passkey_new_name" class="regular-text" maxlength="100" placeholder="<?php esc_attr_e( 'For example: MacBook Touch ID', 'brain-2fa' ); ?>">
						</p>
						<p>
							<label for="brain2fa_passkey_password"><?php esc_html_e( 'Confirm your password', 'brain-2fa' ); ?></label><br>
							<input type="password" id="brain2fa_passkey_password" class="regular-text" autocomplete="current-password">
						</p>
						<p>
							<button type="button" class="button button-primary" id="brain2fa-passkey-add">
								<?php esc_html_e( 'Add a passkey', 'brain-2fa' ); ?>
							</button>
						</p>
						<div id="brain2fa-passkey-message" aria-live="polite"></div>
						<noscript><p class="description"><?php esc_html_e( 'Adding a passkey requires JavaScript.', 'brain-2fa' ); ?></p></noscript>
					</div>
				</div>
			<?php endif; ?>
		</div>

	</div>
</div>
