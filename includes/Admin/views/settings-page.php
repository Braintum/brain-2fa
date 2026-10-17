<?php
/**
 * Admin Settings Page Template
 *
 * Variables from Settings::render_settings_page():
 *
 * @var array    $tabs                 Tab labels keyed by tab identifier.
 * @var string   $active_tab           Allow-listed active tab identifier.
 * @var array    $settings             Stored settings with defaults filled in.
 * @var bool     $code_methods_enabled Whether the authenticator app or email codes are enabled.
 * @var bool     $passkeys_available   Whether the site address allows passkeys (HTTPS).
 * @var array    $role_names           Role names keyed by role slug.
 * @var string[] $required_role_names  Translated names of the roles that require 2FA.
 * @var int      $passkey_only_users   Users whose only second factor is a passkey.
 *
 * @package Brain_2FA\Admin
 * @since 1.0.0
 */

use Brain_2FA\Admin\Settings;

defined( 'ABSPATH' ) || exit;

if ( empty( $settings['enable_2fa'] ) ) {
	$brain2fa_status_label = __( 'Off', 'brain-2fa' );
	$brain2fa_status_class = 'brain2fa-status-off';
	$brain2fa_status_text  = __( 'Users sign in with their password alone and role requirements do not apply.', 'brain-2fa' );
} elseif ( ! $code_methods_enabled ) {
	$brain2fa_status_label = __( 'On, no code method', 'brain-2fa' );
	$brain2fa_status_class = 'brain2fa-status-warning';
	$brain2fa_status_text  = __( 'Authenticator app and email codes are both off. Only users with a passkey get a second step, and setup deadlines are not enforced.', 'brain-2fa' );
} else {
	$brain2fa_status_label = __( 'On', 'brain-2fa' );
	$brain2fa_status_class = 'brain2fa-status-on';
	$brain2fa_status_text  = empty( $required_role_names )
		? __( 'Users who set up a method use it at every sign-in. No role is required to set one up.', 'brain-2fa' )
		: sprintf(
			/* translators: %s: comma-separated list of role names */
			__( 'Users who set up a method use it at every sign-in. Required for: %s.', 'brain-2fa' ),
			implode( wp_get_list_item_separator(), $required_role_names )
		);
}

if ( empty( $settings['enable_passkeys'] ) ) {
	$brain2fa_passkey_label = __( 'Off', 'brain-2fa' );
	$brain2fa_passkey_class = 'brain2fa-status-off';
	$brain2fa_passkey_text  = __( 'Users cannot add or use passkeys.', 'brain-2fa' );
} elseif ( ! $passkeys_available ) {
	$brain2fa_passkey_label = __( 'Unavailable', 'brain-2fa' );
	$brain2fa_passkey_class = 'brain2fa-status-warning';
	$brain2fa_passkey_text  = __( 'Turned on, but the site address does not use HTTPS, so passkeys stay hidden.', 'brain-2fa' );
} else {
	$brain2fa_passkey_label = __( 'Available', 'brain-2fa' );
	$brain2fa_passkey_class = 'brain2fa-status-on';
	$brain2fa_passkey_text  = __( 'Passkey sign-in works whether or not two-factor authentication is on.', 'brain-2fa' );
}
?>

<div class="wrap brain2fa-settings-wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php settings_errors( 'brain2fa_messages' ); ?>

	<div class="brain2fa-summary">
		<div class="brain2fa-summary-item">
			<span class="brain2fa-summary-name"><?php esc_html_e( 'Two-factor authentication', 'brain-2fa' ); ?></span>
			<span class="brain2fa-pill <?php echo esc_attr( $brain2fa_status_class ); ?>"><?php echo esc_html( $brain2fa_status_label ); ?></span>
			<p><?php echo esc_html( $brain2fa_status_text ); ?></p>
		</div>
		<div class="brain2fa-summary-item">
			<span class="brain2fa-summary-name"><?php esc_html_e( 'Passkeys', 'brain-2fa' ); ?></span>
			<span class="brain2fa-pill <?php echo esc_attr( $brain2fa_passkey_class ); ?>"><?php echo esc_html( $brain2fa_passkey_label ); ?></span>
			<p><?php echo esc_html( $brain2fa_passkey_text ); ?></p>
		</div>
	</div>

	<nav class="nav-tab-wrapper brain2fa-tabs" aria-label="<?php esc_attr_e( 'Settings sections', 'brain-2fa' ); ?>">
		<?php foreach ( $tabs as $brain2fa_tab => $brain2fa_tab_label ) : ?>
			<a href="<?php echo esc_url( Settings::get_tab_url( $brain2fa_tab ) ); ?>"
				class="nav-tab<?php echo $brain2fa_tab === $active_tab ? ' nav-tab-active' : ''; ?>"
				<?php echo $brain2fa_tab === $active_tab ? 'aria-current="page"' : ''; ?>>
				<?php echo esc_html( $brain2fa_tab_label ); ?>
			</a>
		<?php endforeach; ?>
	</nav>

	<form action="options.php" method="post" class="brain2fa-settings-form" data-brain2fa-tab="<?php echo esc_attr( $active_tab ); ?>">
		<?php settings_fields( 'brain2fa_settings_group' ); ?>
		<input type="hidden" name="brain2fa_settings[_tab]" value="<?php echo esc_attr( $active_tab ); ?>">

		<?php
		if ( 'requirements' === $active_tab ) {
			require __DIR__ . '/settings/tab-requirements.php';
		} elseif ( 'trusted-devices' === $active_tab ) {
			require __DIR__ . '/settings/tab-trusted-devices.php';
		} else {
			require __DIR__ . '/settings/tab-methods.php';
		}
		?>

		<?php submit_button( __( 'Save Changes', 'brain-2fa' ) ); ?>
	</form>

	<p class="brain2fa-settings-footer">
		<a href="https://www.braintum.com/support/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'brain-2fa' ); ?></a>
		<span aria-hidden="true">&middot;</span>
		<a href="https://wordpress.org/support/plugin/brain-2fa/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support forum', 'brain-2fa' ); ?></a>
		<span aria-hidden="true">&middot;</span>
		<?php
		/* translators: %s: plugin version number */
		echo esc_html( sprintf( __( 'Version %s', 'brain-2fa' ), BRAIN_2FA_VERSION ) );
		?>
	</p>
</div>
