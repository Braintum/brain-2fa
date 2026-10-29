<?php
/**
 * Login Security: passkeys.
 *
 * The add-passkey element IDs are used by src/js/passkey-manage.js.
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

use Brain_2FA\Admin\LoginSecurityPage;

defined( 'ABSPATH' ) || exit;
?>
<section class="brain2fa-card brain2fa-passkeys-card" id="brain2fa-passkeys" aria-labelledby="brain2fa-passkeys-title">
	<h2 id="brain2fa-passkeys-title" class="brain2fa-card-title"><?php esc_html_e( 'Passkeys', 'brain-2fa' ); ?></h2>
	<p class="brain2fa-card-intro"><?php esc_html_e( 'Sign in without a password using your device screen lock (Face ID, Touch ID, Windows Hello), your phone, or a security key. A passkey sign-in also counts as two-factor authentication.', 'brain-2fa' ); ?></p>

	<?php if ( ! $passkeys_enabled ) : ?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'Passkeys need a secure (HTTPS) connection, which this site does not have yet. You can add passkeys once it does.', 'brain-2fa' ); ?></p>
		</div>
	<?php else : ?>
		<?php LoginSecurityPage::render_notices( 'passkeys' ); ?>

		<?php if ( empty( $passkeys ) ) : ?>
			<p class="brain2fa-empty-state"><?php esc_html_e( 'You have not added any passkeys yet.', 'brain-2fa' ); ?></p>
		<?php else : ?>
			<ul class="brain2fa-passkey-list">
				<?php foreach ( $passkeys as $brain2fa_passkey ) : ?>
					<?php $brain2fa_passkey_id = (int) $brain2fa_passkey->id; ?>
					<li class="brain2fa-passkey">
						<div class="brain2fa-passkey-details">
							<strong class="brain2fa-passkey-name"><?php echo esc_html( $brain2fa_passkey->name ); ?></strong>
							<?php if ( ! empty( $brain2fa_passkey->backed_up ) ) : ?>
								<span class="brain2fa-pill brain2fa-status-off"><?php esc_html_e( 'Synced across your devices', 'brain-2fa' ); ?></span>
							<?php endif; ?>
							<span class="brain2fa-passkey-meta">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: date the passkey was added, 2: date and time it was last used, or "never" */
										__( 'Added %1$s · Last used %2$s', 'brain-2fa' ),
										get_date_from_gmt( $brain2fa_passkey->created_at, get_option( 'date_format' ) ),
										$brain2fa_passkey->last_used_at
											? get_date_from_gmt( $brain2fa_passkey->last_used_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
											: __( 'never', 'brain-2fa' )
									)
								);
								?>
							</span>
						</div>

						<details class="brain2fa-disclosure"<?php echo esc_attr( LoginSecurityPage::open_attribute( 'passkey-' . $brain2fa_passkey_id ) ); ?>>
							<summary><?php esc_html_e( 'Rename or remove', 'brain-2fa' ); ?></summary>
							<div class="brain2fa-disclosure-body">
								<?php LoginSecurityPage::render_notices( 'passkey-' . $brain2fa_passkey_id ); ?>
								<form method="post" action="" class="brain2fa-inline-form">
									<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
									<input type="hidden" name="brain2fa_action" value="passkey_rename">
									<input type="hidden" name="brain2fa_passkey_id" value="<?php echo esc_attr( $brain2fa_passkey_id ); ?>">
									<div class="brain2fa-field">
										<label for="brain2fa_passkey_name_<?php echo esc_attr( $brain2fa_passkey_id ); ?>"><?php esc_html_e( 'Name', 'brain-2fa' ); ?></label>
										<input type="text" id="brain2fa_passkey_name_<?php echo esc_attr( $brain2fa_passkey_id ); ?>" name="brain2fa_passkey_name" class="regular-text" value="<?php echo esc_attr( $brain2fa_passkey->name ); ?>" maxlength="100" required>
										<button type="submit" class="<?php echo esc_attr( LoginSecurityPage::button_class() ); ?>"><?php esc_html_e( 'Rename', 'brain-2fa' ); ?></button>
									</div>
								</form>
								<form method="post" action="" class="brain2fa-inline-form">
									<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
									<input type="hidden" name="brain2fa_action" value="passkey_remove">
									<input type="hidden" name="brain2fa_passkey_id" value="<?php echo esc_attr( $brain2fa_passkey_id ); ?>">
									<div class="brain2fa-field">
										<label for="brain2fa_passkey_remove_password_<?php echo esc_attr( $brain2fa_passkey_id ); ?>"><?php esc_html_e( 'Confirm your password to remove this passkey', 'brain-2fa' ); ?></label>
										<input type="password" id="brain2fa_passkey_remove_password_<?php echo esc_attr( $brain2fa_passkey_id ); ?>" name="brain2fa_confirm_password" class="regular-text" autocomplete="current-password" required>
										<button type="submit" class="<?php echo esc_attr( LoginSecurityPage::button_class( 'danger' ) ); ?>"><?php esc_html_e( 'Remove passkey', 'brain-2fa' ); ?></button>
									</div>
									<p class="description"><?php esc_html_e( 'You will no longer be able to sign in with this passkey.', 'brain-2fa' ); ?></p>
								</form>
							</div>
						</details>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h3 class="brain2fa-subheading"><?php esc_html_e( 'Add a passkey', 'brain-2fa' ); ?></h3>
		<div id="brain2fa-passkey-register">
			<div class="brain2fa-field">
				<label for="brain2fa_passkey_new_name"><?php esc_html_e( 'Passkey name', 'brain-2fa' ); ?></label>
				<input type="text" id="brain2fa_passkey_new_name" class="regular-text" maxlength="100" placeholder="<?php esc_attr_e( 'For example: MacBook Touch ID', 'brain-2fa' ); ?>">
			</div>
			<div class="brain2fa-field">
				<label for="brain2fa_passkey_password"><?php esc_html_e( 'Confirm your password', 'brain-2fa' ); ?></label>
				<input type="password" id="brain2fa_passkey_password" class="regular-text" autocomplete="current-password">
			</div>
			<p>
				<button type="button" class="<?php echo esc_attr( LoginSecurityPage::button_class( 'primary' ) ); ?>" id="brain2fa-passkey-add"><?php esc_html_e( 'Add a passkey', 'brain-2fa' ); ?></button>
			</p>
			<div id="brain2fa-passkey-message" aria-live="polite"></div>
			<noscript><p class="description"><?php esc_html_e( 'Adding a passkey requires JavaScript.', 'brain-2fa' ); ?></p></noscript>
		</div>
	<?php endif; ?>
</section>
