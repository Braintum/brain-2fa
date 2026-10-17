<?php
/**
 * Settings tab: Emails, editor for one email.
 *
 * Variables from EmailSettings::render_tab():
 *
 * @var string $email_id     Email ID.
 * @var array  $definition   Email definition.
 * @var array  $template     Saved template (enabled, subject, body, customized).
 * @var array  $current      Values shown in the form (the saved template, or a failed submission).
 * @var array  $placeholders Placeholder descriptions keyed by name, without braces.
 * @var array  $values       Sample replacements keyed by placeholder, braces included.
 * @var array  $preview      Rendered subject and body of $current with sample values.
 *
 * The placeholder buttons and live preview are handled by src/js/admin.js; without
 * JavaScript, the preview updates after saving.
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

use Brain_2FA\Admin\EmailSettings;

defined( 'ABSPATH' ) || exit;
?>
<p class="brain2fa-back-link">
	<a href="<?php echo esc_url( EmailSettings::get_list_url() ); ?>">&larr; <?php esc_html_e( 'All emails', 'brain-2fa' ); ?></a>
</p>

<div class="brain2fa-email-editor" data-brain2fa-placeholders="<?php echo esc_attr( wp_json_encode( $values ) ); ?>">
	<div class="brain2fa-email-main">
		<section class="brain2fa-card" aria-labelledby="brain2fa-email-title">
			<h2 id="brain2fa-email-title" class="brain2fa-card-title"><?php echo esc_html( $definition['label'] ); ?></h2>
			<p class="brain2fa-card-intro"><?php echo esc_html( $definition['description'] ); ?></p>

			<form method="post" action="" class="brain2fa-email-form" id="brain2fa-email-form">
				<?php EmailSettings::render_form_fields( 'save_template', $email_id ); ?>

				<?php if ( $definition['can_disable'] ) : ?>
					<div class="brain2fa-field">
						<label for="brain2fa_email_enabled">
							<input type="checkbox" id="brain2fa_email_enabled" name="brain2fa_email_enabled" value="1" <?php checked( $current['enabled'] ); ?>>
							<?php esc_html_e( 'Send this email', 'brain-2fa' ); ?>
						</label>
						<?php if ( 'passkey_clone_warning' === $email_id ) : ?>
							<p class="description"><?php esc_html_e( 'This is the only way users learn that a copy of their passkey may be in someone else\'s hands. Keep it on unless you alert users another way.', 'brain-2fa' ); ?></p>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<p class="brain2fa-method-status">
						<span class="brain2fa-pill brain2fa-status-on"><?php esc_html_e( 'Always on', 'brain-2fa' ); ?></span>
						<?php esc_html_e( 'Users need this email to sign in, so it cannot be turned off.', 'brain-2fa' ); ?>
					</p>
				<?php endif; ?>

				<div class="brain2fa-field">
					<label for="brain2fa_email_subject"><?php esc_html_e( 'Subject', 'brain-2fa' ); ?></label>
					<input type="text" id="brain2fa_email_subject" name="brain2fa_email_subject" class="large-text" maxlength="255" required value="<?php echo esc_attr( $current['subject'] ); ?>"<?php echo EmailSettings::has_field_error( 'subject' ) ? ' aria-invalid="true" aria-describedby="brain2fa-error-subject"' : ''; ?>>
					<?php EmailSettings::render_field_errors( 'subject' ); ?>
				</div>

				<div class="brain2fa-field">
					<label for="brain2fa_email_body"><?php esc_html_e( 'Email text', 'brain-2fa' ); ?></label>
					<textarea id="brain2fa_email_body" name="brain2fa_email_body" class="large-text code" rows="14" maxlength="10000" required<?php echo EmailSettings::has_field_error( 'body' ) ? ' aria-invalid="true" aria-describedby="brain2fa-error-body"' : ''; ?>><?php echo esc_textarea( $current['body'] ); ?></textarea>
					<?php EmailSettings::render_field_errors( 'body' ); ?>
					<p class="description"><?php esc_html_e( 'Plain text. HTML tags are removed. Line breaks are kept.', 'brain-2fa' ); ?></p>
				</div>

				<?php submit_button( __( 'Save Email', 'brain-2fa' ), 'primary', 'submit', false ); ?>
			</form>
		</section>

		<section class="brain2fa-card" aria-labelledby="brain2fa-email-tools">
			<h2 id="brain2fa-email-tools" class="brain2fa-card-title"><?php esc_html_e( 'Test and reset', 'brain-2fa' ); ?></h2>
			<form method="post" action="" class="brain2fa-inline-form">
				<?php EmailSettings::render_form_fields( 'send_test', $email_id ); ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: email address of the current administrator */
							__( 'Send the saved version of this email to %s, with sample values.', 'brain-2fa' ),
							wp_get_current_user()->user_email
						)
					);
					?>
				</p>
				<?php submit_button( __( 'Send Test Email', 'brain-2fa' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( $template['customized'] ) : ?>
				<details class="brain2fa-disclosure">
					<summary><?php esc_html_e( 'Reset to default text', 'brain-2fa' ); ?></summary>
					<div class="brain2fa-disclosure-body">
						<p><?php esc_html_e( 'Your changes to the subject and text are deleted, and the email follows the plugin\'s default text and its translations again. Whether the email is sent stays as it is.', 'brain-2fa' ); ?></p>
						<form method="post" action="">
							<?php EmailSettings::render_form_fields( 'reset_template', $email_id ); ?>
							<?php submit_button( __( 'Reset to Default', 'brain-2fa' ), 'secondary', 'submit', false ); ?>
						</form>
					</div>
				</details>
			<?php endif; ?>
		</section>
	</div>

	<aside class="brain2fa-email-side">
		<section class="brain2fa-card" aria-labelledby="brain2fa-email-placeholders">
			<h2 id="brain2fa-email-placeholders" class="brain2fa-card-title"><?php esc_html_e( 'Placeholders', 'brain-2fa' ); ?></h2>
			<p class="brain2fa-card-intro"><?php esc_html_e( 'Replaced with real values when the email is sent. Select one to insert it at the cursor.', 'brain-2fa' ); ?></p>
			<ul class="brain2fa-placeholder-list">
				<?php foreach ( $placeholders as $brain2fa_name => $brain2fa_description ) : ?>
					<li>
						<button type="button" class="brain2fa-placeholder" data-placeholder="<?php echo esc_attr( '{' . $brain2fa_name . '}' ); ?>">
							<code><?php echo esc_html( '{' . $brain2fa_name . '}' ); ?></code>
						</button>
						<span class="description"><?php echo esc_html( $brain2fa_description ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>

		<section class="brain2fa-card" aria-labelledby="brain2fa-email-preview-title">
			<h2 id="brain2fa-email-preview-title" class="brain2fa-card-title"><?php esc_html_e( 'Preview', 'brain-2fa' ); ?></h2>
			<p class="brain2fa-card-intro"><?php esc_html_e( 'With your account details and sample values.', 'brain-2fa' ); ?></p>
			<div class="brain2fa-email-preview">
				<p class="brain2fa-email-preview-subject"><strong id="brain2fa-preview-subject"><?php echo esc_html( $preview['subject'] ); ?></strong></p>
				<div class="brain2fa-email-preview-body" id="brain2fa-preview-body"><?php echo esc_html( $preview['body'] ); ?></div>
			</div>
		</section>
	</aside>
</div>
