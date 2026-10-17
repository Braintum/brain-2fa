<?php
/**
 * Settings tab: Authentication Methods.
 *
 * Owns enable_2fa, enable_totp, enable_email, code_expiry, enable_passkeys,
 * allow_passkey_only and default_method (see Settings::TAB_FIELDS).
 *
 * @var array $settings           Stored settings with defaults filled in.
 * @var bool  $passkeys_available Whether the site address allows passkeys (HTTPS).
 * @var int   $passkey_only_users Users whose only second factor is a passkey.
 * @var array $method_impact      Per code method: users blocked without it, plus a notice.
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;
?>

<section class="brain2fa-card" aria-labelledby="brain2fa-card-2fa">
	<h2 id="brain2fa-card-2fa" class="brain2fa-card-title"><?php esc_html_e( 'Two-factor authentication', 'brain-2fa' ); ?></h2>
	<p class="brain2fa-card-intro"><?php esc_html_e( 'The site-wide switch for asking a second step after the password.', 'brain-2fa' ); ?></p>
	<div class="brain2fa-field">
		<label for="brain2fa_enable_2fa">
			<input type="checkbox" id="brain2fa_enable_2fa" name="brain2fa_settings[enable_2fa]" value="1" <?php checked( ! empty( $settings['enable_2fa'] ) ); ?>>
			<?php esc_html_e( 'Turn on two-factor authentication', 'brain-2fa' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When this is off, users sign in with their password alone and role requirements do not apply. Passkey sign-in and passkey-only accounts keep working, because they do not depend on this switch.', 'brain-2fa' ); ?></p>
	</div>
</section>

<section class="brain2fa-card" aria-labelledby="brain2fa-card-totp">
	<h2 id="brain2fa-card-totp" class="brain2fa-card-title"><?php esc_html_e( 'Authenticator app', 'brain-2fa' ); ?></h2>
	<div class="brain2fa-field">
		<label for="brain2fa_enable_totp">
			<input type="checkbox" id="brain2fa_enable_totp" name="brain2fa_settings[enable_totp]" value="1" data-brain2fa-blocked-users="<?php echo esc_attr( $method_impact['totp']['blocked'] ); ?>" <?php checked( ! empty( $settings['enable_totp'] ) ); ?>>
			<?php esc_html_e( 'Let users sign in with codes from an authenticator app', 'brain-2fa' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Works with Google Authenticator, Microsoft Authenticator, Authy, 1Password, Bitwarden and other TOTP apps. Users also receive recovery codes.', 'brain-2fa' ); ?></p>
	</div>
	<?php if ( '' !== $method_impact['totp']['message'] ) : ?>
		<div class="notice notice-<?php echo esc_attr( $method_impact['totp']['type'] ); ?> inline">
			<p><?php echo esc_html( $method_impact['totp']['message'] ); ?></p>
		</div>
	<?php endif; ?>
</section>

<section class="brain2fa-card" aria-labelledby="brain2fa-card-email">
	<h2 id="brain2fa-card-email" class="brain2fa-card-title"><?php esc_html_e( 'Email codes', 'brain-2fa' ); ?></h2>
	<div class="brain2fa-field">
		<label for="brain2fa_enable_email">
			<input type="checkbox" id="brain2fa_enable_email" name="brain2fa_settings[enable_email]" value="1" data-brain2fa-blocked-users="<?php echo esc_attr( $method_impact['email']['blocked'] ); ?>" aria-controls="brain2fa-email-options" <?php checked( ! empty( $settings['enable_email'] ) ); ?>>
			<?php esc_html_e( 'Let users receive sign-in codes by email', 'brain-2fa' ); ?>
		</label>
	</div>
	<?php if ( '' !== $method_impact['email']['message'] ) : ?>
		<div class="notice notice-<?php echo esc_attr( $method_impact['email']['type'] ); ?> inline">
			<p><?php echo esc_html( $method_impact['email']['message'] ); ?></p>
		</div>
	<?php endif; ?>
	<div class="brain2fa-field brain2fa-dependent" id="brain2fa-email-options">
		<label for="brain2fa_code_expiry"><?php esc_html_e( 'Code lifetime (minutes)', 'brain-2fa' ); ?></label>
		<input type="number" id="brain2fa_code_expiry" name="brain2fa_settings[code_expiry]" class="small-text" value="<?php echo esc_attr( $settings['code_expiry'] ); ?>" min="1" max="60" step="1" aria-describedby="brain2fa_code_expiry_description">
		<p class="description" id="brain2fa_code_expiry_description"><?php esc_html_e( 'How long an emailed code stays valid, from 1 to 60 minutes.', 'brain-2fa' ); ?></p>
	</div>
</section>

<section class="brain2fa-card" aria-labelledby="brain2fa-card-passkeys">
	<h2 id="brain2fa-card-passkeys" class="brain2fa-card-title"><?php esc_html_e( 'Passkeys', 'brain-2fa' ); ?></h2>
	<p class="brain2fa-card-intro"><?php esc_html_e( 'Users confirm with their device screen lock (Face ID, Touch ID, Windows Hello), a phone or a security key. Users add passkeys on their Login Security page.', 'brain-2fa' ); ?></p>
	<div class="brain2fa-field">
		<label for="brain2fa_enable_passkeys">
			<input type="checkbox" id="brain2fa_enable_passkeys" name="brain2fa_settings[enable_passkeys]" value="1" aria-controls="brain2fa-passkey-options" <?php checked( ! empty( $settings['enable_passkeys'] ) ); ?>>
			<?php esc_html_e( 'Turn on passkeys', 'brain-2fa' ); ?>
		</label>
	</div>

	<?php if ( ! $passkeys_available ) : ?>
		<div class="notice notice-error inline">
			<p><?php esc_html_e( 'Passkeys need HTTPS. Your site address does not use HTTPS, so passkeys stay hidden until it does.', 'brain-2fa' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( $passkey_only_users > 0 ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of users */
						_n(
							'%s user has a passkey as their only second factor. If you turn passkeys off, they sign in with their password alone, and users in roles that require 2FA must set up another method at their next sign-in.',
							'%s users have a passkey as their only second factor. If you turn passkeys off, they sign in with their password alone, and users in roles that require 2FA must set up another method at their next sign-in.',
							$passkey_only_users,
							'brain-2fa'
						),
						number_format_i18n( $passkey_only_users )
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="brain2fa-dependent" id="brain2fa-passkey-options">
		<dl class="brain2fa-definitions">
			<dt><?php esc_html_e( 'Passkey sign-in', 'brain-2fa' ); ?></dt>
			<dd><?php esc_html_e( 'Users sign in with a passkey instead of their username and password. This works even when two-factor authentication is off.', 'brain-2fa' ); ?></dd>
			<dt><?php esc_html_e( 'Passkey as a second factor', 'brain-2fa' ); ?></dt>
			<dd><?php esc_html_e( 'While two-factor authentication is on, a user who signs in with a password can confirm with a passkey instead of a code. A passkey also meets role requirements.', 'brain-2fa' ); ?></dd>
			<dt><?php esc_html_e( 'Passkey-only accounts', 'brain-2fa' ); ?></dt>
			<dd><?php esc_html_e( 'With the option below, users can turn off password sign-in for their own account.', 'brain-2fa' ); ?></dd>
		</dl>

		<div class="brain2fa-field">
			<label for="brain2fa_allow_passkey_only">
				<input type="checkbox" id="brain2fa_allow_passkey_only" name="brain2fa_settings[allow_passkey_only]" value="1" <?php checked( ! empty( $settings['allow_passkey_only'] ) ); ?>>
				<?php esc_html_e( 'Let users turn off password sign-in for their account (passkey-only)', 'brain-2fa' ); ?>
			</label>
			<p class="description"><?php esc_html_e( 'Passkey-only accounts cannot be phished with a password. If such a user loses all their passkeys, an administrator restores password sign-in from their profile.', 'brain-2fa' ); ?></p>
		</div>
	</div>
</section>

<section class="brain2fa-card" aria-labelledby="brain2fa-card-default-method">
	<h2 id="brain2fa-card-default-method" class="brain2fa-card-title"><?php esc_html_e( 'Default code method', 'brain-2fa' ); ?></h2>
	<div class="brain2fa-field">
		<label for="brain2fa_default_method"><?php esc_html_e( 'Code method users set up', 'brain-2fa' ); ?></label>
		<select id="brain2fa_default_method" name="brain2fa_settings[default_method]" aria-describedby="brain2fa_default_method_description">
			<option value="totp" <?php selected( $settings['default_method'], 'totp' ); ?>><?php esc_html_e( 'Authenticator app', 'brain-2fa' ); ?></option>
			<option value="email" <?php selected( in_array( $settings['default_method'], array( 'email', 'email_backup' ), true ) ); ?>><?php esc_html_e( 'Email codes', 'brain-2fa' ); ?></option>
		</select>
		<p class="description" id="brain2fa_default_method_description"><?php esc_html_e( 'Users set up this method on their Login Security page. If it is turned off, the other code method is used. Passkeys are added separately.', 'brain-2fa' ); ?></p>
	</div>
</section>
