<?php
/**
 * Settings tab: Emails (list and sender).
 *
 * Variables from EmailSettings::render_tab():
 *
 * @var array $definitions Email definitions keyed by email ID.
 * @var array $groups      Email groups keyed by group ID.
 * @var array $sender      Sender name and address to show.
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

use Brain_2FA\Admin\EmailSettings;
use Brain_2FA\Emails\EmailRegistry;

defined( 'ABSPATH' ) || exit;
?>
<p class="brain2fa-emails-intro"><?php esc_html_e( 'Change the text of the emails Brain 2FA sends to users. Emails are sent as plain text. Use placeholders such as {user_first_name} to personalize them.', 'brain-2fa' ); ?></p>

<?php foreach ( $groups as $brain2fa_group_id => $brain2fa_group ) : ?>
	<section class="brain2fa-card" aria-labelledby="brain2fa-email-group-<?php echo esc_attr( $brain2fa_group_id ); ?>">
		<h2 id="brain2fa-email-group-<?php echo esc_attr( $brain2fa_group_id ); ?>" class="brain2fa-card-title"><?php echo esc_html( $brain2fa_group['label'] ); ?></h2>
		<p class="brain2fa-card-intro"><?php echo esc_html( $brain2fa_group['description'] ); ?></p>
		<table class="widefat striped brain2fa-email-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Email', 'brain-2fa' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'brain-2fa' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Text', 'brain-2fa' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'brain-2fa' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $definitions as $brain2fa_email_id => $brain2fa_definition ) : ?>
					<?php
					if ( $brain2fa_definition['group'] !== $brain2fa_group_id ) {
						continue;
					}
					$brain2fa_template = EmailRegistry::get_template( $brain2fa_email_id );
					?>
					<tr>
						<td data-colname="<?php esc_attr_e( 'Email', 'brain-2fa' ); ?>">
							<a href="<?php echo esc_url( EmailSettings::get_edit_url( $brain2fa_email_id ) ); ?>"><strong><?php echo esc_html( $brain2fa_definition['label'] ); ?></strong></a>
							<span class="description"><?php echo esc_html( $brain2fa_definition['description'] ); ?></span>
						</td>
						<td data-colname="<?php esc_attr_e( 'Status', 'brain-2fa' ); ?>">
							<?php if ( ! $brain2fa_definition['can_disable'] ) : ?>
								<span class="brain2fa-pill brain2fa-status-on"><?php esc_html_e( 'Always on', 'brain-2fa' ); ?></span>
							<?php elseif ( $brain2fa_template['enabled'] ) : ?>
								<span class="brain2fa-pill brain2fa-status-on"><?php esc_html_e( 'On', 'brain-2fa' ); ?></span>
							<?php else : ?>
								<span class="brain2fa-pill brain2fa-status-off"><?php esc_html_e( 'Off', 'brain-2fa' ); ?></span>
							<?php endif; ?>
						</td>
						<td data-colname="<?php esc_attr_e( 'Text', 'brain-2fa' ); ?>">
							<?php echo $brain2fa_template['customized'] ? esc_html__( 'Customized', 'brain-2fa' ) : esc_html__( 'Default', 'brain-2fa' ); ?>
						</td>
						<td class="brain2fa-email-actions">
							<a class="button" href="<?php echo esc_url( EmailSettings::get_edit_url( $brain2fa_email_id ) ); ?>">
								<?php esc_html_e( 'Edit', 'brain-2fa' ); ?><span class="screen-reader-text"> <?php echo esc_html( $brain2fa_definition['label'] ); ?></span>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
<?php endforeach; ?>

<section class="brain2fa-card" aria-labelledby="brain2fa-email-sender">
	<h2 id="brain2fa-email-sender" class="brain2fa-card-title"><?php esc_html_e( 'Sender', 'brain-2fa' ); ?></h2>
	<p class="brain2fa-card-intro"><?php esc_html_e( 'Who Brain 2FA emails appear to come from. Leave a field empty to use the WordPress default. Other emails from your site are not affected.', 'brain-2fa' ); ?></p>
	<form method="post" action="" class="brain2fa-email-form">
		<?php EmailSettings::render_form_fields( 'save_sender' ); ?>
		<div class="brain2fa-field">
			<label for="brain2fa_from_name"><?php esc_html_e( 'From name', 'brain-2fa' ); ?></label>
			<input type="text" id="brain2fa_from_name" name="brain2fa_from_name" class="regular-text" maxlength="100" value="<?php echo esc_attr( $sender['from_name'] ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>">
		</div>
		<div class="brain2fa-field">
			<label for="brain2fa_from_address"><?php esc_html_e( 'From address', 'brain-2fa' ); ?></label>
			<input type="email" id="brain2fa_from_address" name="brain2fa_from_address" class="regular-text" value="<?php echo esc_attr( $sender['from_address'] ); ?>" aria-describedby="brain2fa_from_address_description<?php echo EmailSettings::has_field_error( 'from_address' ) ? ' brain2fa-error-from_address' : ''; ?>"<?php echo EmailSettings::has_field_error( 'from_address' ) ? ' aria-invalid="true"' : ''; ?>>
			<?php EmailSettings::render_field_errors( 'from_address' ); ?>
			<p class="description" id="brain2fa_from_address_description"><?php esc_html_e( 'Use an address on your own domain. Mail servers often reject or spam-filter mail from addresses your site is not allowed to send for (SPF and DMARC).', 'brain-2fa' ); ?></p>
		</div>
		<?php submit_button( __( 'Save Sender', 'brain-2fa' ), 'primary', 'submit', true ); ?>
	</form>
</section>
