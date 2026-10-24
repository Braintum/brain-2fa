<?php
/**
 * Login Security: trusted devices.
 *
 * Browsers where the user chose "Remember this device", newest first. Forgetting
 * works without JavaScript and is handled by LoginSecurityPage::handle_device_actions().
 *
 * @package Brain_2FA\Admin
 * @since 1.2.0
 */

use Brain_2FA\Admin\LoginSecurityPage;
use Brain_2FA\Auth\TrustedDeviceRepository;

defined( 'ABSPATH' ) || exit;

$brain2fa_date_format = get_option( 'date_format' );
?>
<section class="brain2fa-card" id="brain2fa-devices" aria-labelledby="brain2fa-devices-title">
	<h2 id="brain2fa-devices-title" class="brain2fa-card-title"><?php esc_html_e( 'Trusted devices', 'brain-2fa' ); ?></h2>
	<p class="brain2fa-card-intro"><?php esc_html_e( 'Browsers where you chose "Remember this device" skip the second step after your password until they expire.', 'brain-2fa' ); ?></p>

	<?php if ( ! $remember_enabled ) : ?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'This site no longer lets browsers skip the second step, so these devices are not trusted right now. You can still forget them; they also expire on their own.', 'brain-2fa' ); ?></p>
		</div>
	<?php endif; ?>

	<?php LoginSecurityPage::render_notices( 'devices' ); ?>

	<?php if ( empty( $trusted_devices ) ) : ?>
		<p class="brain2fa-empty-state"><?php esc_html_e( 'No browsers are trusted for your account. To trust one, choose "Remember this device" when you enter a code at sign-in.', 'brain-2fa' ); ?></p>
	<?php else : ?>
		<ul class="brain2fa-device-list">
			<?php foreach ( $trusted_devices as $brain2fa_device ) : ?>
				<?php
				$brain2fa_device_label = TrustedDeviceRepository::get_label( $brain2fa_device );
				$brain2fa_is_legacy    = 0 === $brain2fa_device['created_at'];
				if ( $brain2fa_is_legacy ) {
					$brain2fa_last_used = __( 'unknown', 'brain-2fa' );
				} elseif ( $brain2fa_device['last_used_at'] ) {
					$brain2fa_last_used = wp_date( $brain2fa_date_format . ' ' . get_option( 'time_format' ), $brain2fa_device['last_used_at'] );
				} else {
					$brain2fa_last_used = __( 'not yet', 'brain-2fa' );
				}
				?>
				<li class="brain2fa-device">
					<div class="brain2fa-device-details">
						<strong class="brain2fa-device-name"><?php echo esc_html( $brain2fa_device_label ); ?></strong>
						<?php if ( $brain2fa_device['id'] === $current_device_id ) : ?>
							<span class="brain2fa-pill brain2fa-status-on"><?php esc_html_e( 'This browser', 'brain-2fa' ); ?></span>
						<?php endif; ?>
						<span class="brain2fa-device-meta">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: date the device was trusted, or "unknown", 2: date and time of its last sign-in, "not yet" or "unknown", 3: expiry date */
									__( 'Trusted %1$s · Last used %2$s · Expires %3$s', 'brain-2fa' ),
									$brain2fa_is_legacy ? __( 'unknown', 'brain-2fa' ) : wp_date( $brain2fa_date_format, $brain2fa_device['created_at'] ),
									$brain2fa_last_used,
									wp_date( $brain2fa_date_format, $brain2fa_device['expires_at'] )
								)
							);
							?>
						</span>
					</div>
					<form method="post" action="">
						<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
						<input type="hidden" name="brain2fa_action" value="forget_device">
						<input type="hidden" name="brain2fa_device_id" value="<?php echo esc_attr( $brain2fa_device['id'] ); ?>">
						<button type="submit" class="button">
							<?php esc_html_e( 'Forget', 'brain-2fa' ); ?>
							<span class="screen-reader-text"><?php echo esc_html( $brain2fa_device_label ); ?></span>
						</button>
					</form>
				</li>
			<?php endforeach; ?>
		</ul>

		<p class="description brain2fa-device-note"><?php esc_html_e( 'A forgotten device asks for your second step at its next password sign-in. Forgetting does not sign that browser out or remove a passkey.', 'brain-2fa' ); ?></p>

		<?php if ( count( $trusted_devices ) > 1 ) : ?>
			<form method="post" action="">
				<?php wp_nonce_field( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ); ?>
				<input type="hidden" name="brain2fa_action" value="forget_all_devices">
				<button type="submit" class="button brain2fa-button-danger"><?php esc_html_e( 'Forget all trusted devices', 'brain-2fa' ); ?></button>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</section>
