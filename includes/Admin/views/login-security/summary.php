<?php
/**
 * Login Security: protection summary.
 *
 * Distinguishes what the user has set up from what the site settings let them use.
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

$brain2fa_has_passkey  = $passkeys_enabled && ! empty( $passkeys );
$brain2fa_method_names = array(
	'totp'  => __( 'Authenticator app', 'brain-2fa' ),
	'email' => __( 'Email codes', 'brain-2fa' ),
);

$brain2fa_usable = array();
if ( $current_method_ready ) {
	$brain2fa_usable[] = 'totp' === $current_method ? __( 'a code from your authenticator app', 'brain-2fa' ) : __( 'an emailed code', 'brain-2fa' );
}
if ( $brain2fa_has_passkey ) {
	$brain2fa_usable[] = __( 'a passkey', 'brain-2fa' );
}

if ( $passkey_only ) {
	$brain2fa_label = __( 'Passkey only', 'brain-2fa' );
	$brain2fa_class = 'brain2fa-status-on';
	$brain2fa_text  = __( 'You sign in with a passkey. Password sign-in is off for your account.', 'brain-2fa' );
} elseif ( ! $site_2fa_enabled ) {
	$brain2fa_label = __( 'Not required', 'brain-2fa' );
	$brain2fa_class = 'brain2fa-status-off';
	$brain2fa_text  = $brain2fa_has_passkey
		? __( 'This site does not currently ask for a second step after your password. You can still sign in with a passkey, and methods you set up apply when an administrator turns two-factor authentication on.', 'brain-2fa' )
		: __( 'This site does not currently ask for a second step after your password. Methods you set up stay saved and apply when an administrator turns two-factor authentication on.', 'brain-2fa' );
} elseif ( $is_protected && $brain2fa_usable ) {
	$brain2fa_label = __( 'Protected', 'brain-2fa' );
	$brain2fa_class = 'brain2fa-status-on';
	$brain2fa_text  = sprintf(
		/* translators: %s: one or two sign-in methods, such as "a passkey" */
		__( 'Signing in with your password also needs %s.', 'brain-2fa' ),
		implode( __( ' or ', 'brain-2fa' ), $brain2fa_usable )
	);
} elseif ( $is_protected ) {
	$brain2fa_label = __( 'Action needed', 'brain-2fa' );
	$brain2fa_class = 'brain2fa-status-warning';
	$brain2fa_text  = __( 'Your two-factor method was turned off for this site, so you cannot use it to sign in. Turn it off below and set up another method.', 'brain-2fa' );
} elseif ( $must_enroll ) {
	$brain2fa_label = __( 'Setup required', 'brain-2fa' );
	$brain2fa_class = 'brain2fa-status-warning';
	$brain2fa_text  = __( 'Your role requires two-factor authentication. Finish setup to continue using this site.', 'brain-2fa' );
} elseif ( $setup_deadline > 0 ) {
	$brain2fa_label = __( 'Not set up', 'brain-2fa' );
	$brain2fa_class = 'brain2fa-status-warning';
	$brain2fa_text  = sprintf(
		/* translators: %s: date */
		__( 'Your role requires two-factor authentication. Set it up by %s.', 'brain-2fa' ),
		wp_date( get_option( 'date_format' ), $setup_deadline )
	);
} elseif ( $passkeys_enabled || $code_setup_available ) {
	$brain2fa_label = __( 'Not set up', 'brain-2fa' );
	$brain2fa_class = 'brain2fa-status-off';
	$brain2fa_text  = __( 'Add a passkey or set up two-factor codes to protect your account.', 'brain-2fa' );
} else {
	$brain2fa_label = __( 'Not available', 'brain-2fa' );
	$brain2fa_class = 'brain2fa-status-off';
	$brain2fa_text  = __( 'This site does not offer any sign-in protection methods right now.', 'brain-2fa' );
}

// Two-factor codes row.
if ( $is_2fa_enabled && isset( $brain2fa_method_names[ $current_method ] ) ) {
	$brain2fa_codes_value = $current_method_ready
		? $brain2fa_method_names[ $current_method ]
		: sprintf(
			/* translators: %s: method name, such as "Authenticator app" */
			__( '%s (turned off by the site)', 'brain-2fa' ),
			$brain2fa_method_names[ $current_method ]
		);
} else {
	$brain2fa_codes_value = $code_setup_available ? __( 'Not set up', 'brain-2fa' ) : __( 'Not offered on this site', 'brain-2fa' );
}

// Passkeys row.
$brain2fa_passkeys_value = '';
if ( $passkeys_enabled ) {
	$brain2fa_passkeys_value = $passkeys
		? sprintf(
			/* translators: %s: number of passkeys */
			_n( '%s passkey', '%s passkeys', count( $passkeys ), 'brain-2fa' ),
			number_format_i18n( count( $passkeys ) )
		)
		: __( 'None', 'brain-2fa' );
} elseif ( $passkeys_setting ) {
	$brain2fa_passkeys_value = __( 'Unavailable: this site needs HTTPS', 'brain-2fa' );
} elseif ( $stored_passkey_count > 0 ) {
	$brain2fa_passkeys_value = sprintf(
		/* translators: %s: number of passkeys */
		_n( '%s saved, turned off by the site', '%s saved, turned off by the site', $stored_passkey_count, 'brain-2fa' ),
		number_format_i18n( $stored_passkey_count )
	);
}
?>
<section class="brain2fa-card brain2fa-account-summary" aria-labelledby="brain2fa-summary-title">
	<h2 id="brain2fa-summary-title" class="brain2fa-card-title">
		<?php esc_html_e( 'Your sign-in protection', 'brain-2fa' ); ?>
		<span class="brain2fa-pill <?php echo esc_attr( $brain2fa_class ); ?>"><?php echo esc_html( $brain2fa_label ); ?></span>
	</h2>
	<p class="brain2fa-card-intro"><?php echo esc_html( $brain2fa_text ); ?></p>

	<dl class="brain2fa-summary-list">
		<div>
			<dt><?php esc_html_e( 'Two-factor codes', 'brain-2fa' ); ?></dt>
			<dd><?php echo esc_html( $brain2fa_codes_value ); ?></dd>
		</div>
		<?php if ( '' !== $brain2fa_passkeys_value ) : ?>
			<div>
				<dt><?php esc_html_e( 'Passkeys', 'brain-2fa' ); ?></dt>
				<dd><?php echo esc_html( $brain2fa_passkeys_value ); ?></dd>
			</div>
		<?php endif; ?>
		<?php if ( $show_recovery ) : ?>
			<div>
				<dt><?php esc_html_e( 'Recovery codes', 'brain-2fa' ); ?></dt>
				<dd>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of unused recovery codes */
							_n( '%s left', '%s left', $recovery_codes_count, 'brain-2fa' ),
							number_format_i18n( $recovery_codes_count )
						)
					);
					?>
				</dd>
			</div>
		<?php endif; ?>
		<?php if ( $passkey_only_allowed || $passkey_only ) : ?>
			<div>
				<dt><?php esc_html_e( 'Password sign-in', 'brain-2fa' ); ?></dt>
				<dd><?php echo $passkey_only ? esc_html__( 'Off (passkey only)', 'brain-2fa' ) : esc_html__( 'On', 'brain-2fa' ); ?></dd>
			</div>
		<?php endif; ?>
	</dl>
</section>
