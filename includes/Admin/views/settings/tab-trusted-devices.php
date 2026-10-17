<?php
/**
 * Settings tab: Trusted Devices.
 *
 * Owns remember_device and remember_duration (see Settings::TAB_FIELDS).
 *
 * @var array $settings Stored settings with defaults filled in.
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;
?>

<section class="brain2fa-card" aria-labelledby="brain2fa-card-remember">
	<h2 id="brain2fa-card-remember" class="brain2fa-card-title"><?php esc_html_e( 'Remember devices', 'brain-2fa' ); ?></h2>
	<div class="brain2fa-field">
		<label for="brain2fa_remember_device">
			<input type="checkbox" id="brain2fa_remember_device" name="brain2fa_settings[remember_device]" value="1" aria-controls="brain2fa-card-duration-section" <?php checked( ! empty( $settings['remember_device'] ) ); ?>>
			<?php esc_html_e( 'Let users skip the code on a trusted device', 'brain-2fa' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When entering a code, users can choose to trust the browser they are using. That browser then skips the code until the trust expires.', 'brain-2fa' ); ?></p>
	</div>
</section>

<section class="brain2fa-card brain2fa-dependent" id="brain2fa-card-duration-section" aria-labelledby="brain2fa-card-duration">
	<h2 id="brain2fa-card-duration" class="brain2fa-card-title"><?php esc_html_e( 'Trust duration', 'brain-2fa' ); ?></h2>
	<div class="brain2fa-field">
		<label for="brain2fa_remember_duration"><?php esc_html_e( 'Trust a device for (days)', 'brain-2fa' ); ?></label>
		<input type="number" id="brain2fa_remember_duration" name="brain2fa_settings[remember_duration]" class="small-text" value="<?php echo esc_attr( $settings['remember_duration'] ); ?>" min="1" max="365" step="1" aria-describedby="brain2fa_remember_duration_description">
		<p class="description" id="brain2fa_remember_duration_description"><?php esc_html_e( 'A code is needed again after this many days, after the user changes or resets their password, after they turn off their code method, and on any other browser.', 'brain-2fa' ); ?></p>
	</div>
</section>
