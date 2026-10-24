<?php
/**
 * Settings tab: Enrollment Requirements.
 *
 * Owns force_2fa_roles, grace_period_days, enrollment_reminders and reminder_days (see Settings::TAB_FIELDS).
 *
 * @var array $settings             Stored settings with defaults filled in.
 * @var bool  $code_methods_enabled Whether the authenticator app or email codes are enabled.
 * @var array $role_names           Role names keyed by role slug.
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

use Brain_2FA\Admin\EmailSettings;
use Brain_2FA\Admin\Settings;
use Brain_2FA\Enrollment\ReminderScheduler;

defined( 'ABSPATH' ) || exit;
?>

<?php if ( empty( $settings['enable_2fa'] ) || ! $code_methods_enabled ) : ?>
	<div class="notice notice-warning inline">
		<p>
			<?php
			if ( empty( $settings['enable_2fa'] ) ) {
				esc_html_e( 'Two-factor authentication is off, so these requirements do not apply yet.', 'brain-2fa' );
			} else {
				esc_html_e( 'Authenticator app and email codes are both off, so setup deadlines are not enforced.', 'brain-2fa' );
			}
			?>
			<a href="<?php echo esc_url( Settings::get_tab_url( 'methods' ) ); ?>"><?php esc_html_e( 'Go to Authentication Methods', 'brain-2fa' ); ?></a>
		</p>
	</div>
<?php endif; ?>

<section class="brain2fa-card" aria-labelledby="brain2fa-card-roles">
	<h2 id="brain2fa-card-roles" class="brain2fa-card-title"><?php esc_html_e( 'Required roles', 'brain-2fa' ); ?></h2>
	<p class="brain2fa-card-intro"><?php esc_html_e( 'Users in these roles must set up two-factor authentication. A code method or a passkey meets the requirement.', 'brain-2fa' ); ?></p>
	<fieldset class="brain2fa-field brain2fa-role-list">
		<legend class="screen-reader-text"><?php esc_html_e( 'Roles that must use two-factor authentication', 'brain-2fa' ); ?></legend>
		<?php foreach ( $role_names as $brain2fa_role => $brain2fa_role_name ) : ?>
			<label for="brain2fa_role_<?php echo esc_attr( $brain2fa_role ); ?>">
				<input type="checkbox"
					id="brain2fa_role_<?php echo esc_attr( $brain2fa_role ); ?>"
					name="brain2fa_settings[force_2fa_roles][]"
					value="<?php echo esc_attr( $brain2fa_role ); ?>"
					<?php checked( in_array( $brain2fa_role, $settings['force_2fa_roles'], true ) ); ?>>
				<?php echo esc_html( translate_user_role( $brain2fa_role_name ) ); ?>
			</label>
		<?php endforeach; ?>
	</fieldset>
	<p class="description"><?php esc_html_e( 'Set up two-factor authentication for your own account before you require it for your role.', 'brain-2fa' ); ?></p>
</section>

<section class="brain2fa-card" aria-labelledby="brain2fa-card-deadline">
	<h2 id="brain2fa-card-deadline" class="brain2fa-card-title"><?php esc_html_e( 'Setup deadline', 'brain-2fa' ); ?></h2>
	<div class="brain2fa-field">
		<label for="brain2fa_grace_period_days"><?php esc_html_e( 'Days to set up', 'brain-2fa' ); ?></label>
		<input type="number" id="brain2fa_grace_period_days" name="brain2fa_settings[grace_period_days]" class="small-text" value="<?php echo esc_attr( $settings['grace_period_days'] ); ?>" min="0" max="365" step="1" aria-describedby="brain2fa_grace_period_description">
		<div class="description" id="brain2fa_grace_period_description">
			<p><?php esc_html_e( 'Users in required roles get this many days to set up a method, counted from when the requirement first applies to them. Set 0 to require setup at their next sign-in.', 'brain-2fa' ); ?></p>
			<p><?php esc_html_e( 'After the deadline, they can still sign in, but they can only use the Login Security page until they finish setup. You can extend a user\'s deadline from their profile.', 'brain-2fa' ); ?></p>
		</div>
	</div>
</section>

<section class="brain2fa-card" id="brain2fa-card-reminders" aria-labelledby="brain2fa-card-reminders-title">
	<h2 id="brain2fa-card-reminders-title" class="brain2fa-card-title"><?php esc_html_e( 'Setup reminders', 'brain-2fa' ); ?></h2>
	<div class="brain2fa-field">
		<label for="brain2fa_enrollment_reminders">
			<input type="checkbox" id="brain2fa_enrollment_reminders" name="brain2fa_settings[enrollment_reminders]" value="1" aria-controls="brain2fa-reminder-options" <?php checked( ! empty( $settings['enrollment_reminders'] ) ); ?>>
			<?php esc_html_e( 'Send setup reminder emails', 'brain-2fa' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Email users in required roles before their setup deadline, until they finish setup. A user\'s deadline starts when they first sign in after the requirement applies, so users who have not signed in since get no reminder.', 'brain-2fa' ); ?></p>
	</div>
	<div class="brain2fa-field brain2fa-dependent" id="brain2fa-reminder-options">
		<label for="brain2fa_reminder_days"><?php esc_html_e( 'Days before the deadline', 'brain-2fa' ); ?></label>
		<input type="text" id="brain2fa_reminder_days" name="brain2fa_settings[reminder_days]" class="regular-text" inputmode="numeric" pattern="[0-9 ,]*" value="<?php echo esc_attr( implode( ', ', ReminderScheduler::sanitize_lead_days( $settings['reminder_days'] ) ) ); ?>" aria-describedby="brain2fa_reminder_days_description">
		<div class="description" id="brain2fa_reminder_days_description">
			<p><?php esc_html_e( 'Separate several values with commas, for example 7, 1. Each user gets at most one reminder a day. If a reminder is late, or the deadline is closer than a value, only the most recent reminder is sent.', 'brain-2fa' ); ?></p>
			<p>
				<?php esc_html_e( 'Reminders are sent by WordPress scheduled tasks (WP-Cron), which run when someone visits the site, so they can arrive later on quiet sites.', 'brain-2fa' ); ?>
				<a href="<?php echo esc_url( EmailSettings::get_edit_url( ReminderScheduler::EMAIL_ID ) ); ?>"><?php esc_html_e( 'Edit the reminder email', 'brain-2fa' ); ?></a>
			</p>
		</div>
	</div>
</section>
