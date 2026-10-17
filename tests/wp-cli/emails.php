<?php
/**
 * Brain 2FA WP-CLI check: Editable emails.
 *
 * Rendering, placeholders, validation, sender, on/off, reset, test email and the Emails tab handlers. Captures mail with pre_wp_mail, restores brain2fa_emails and brain2fa_settings, removes the test user.
 *
 * Development sites only: it writes options and creates users while it runs.
 * Run all checks with `npm run test:wp`, or this one with
 * `php ../../../wp-cli.phar eval-file tests/wp-cli/emails.php` from the plugin directory.
 * Prints PASS/FAIL per check and exits 1 when any check fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

use Brain_2FA\Emails\EmailRegistry as R;
use Brain_2FA\Emails\Mailer;
use Brain_2FA\Admin\EmailSettings;

$GLOBALS['b2_fails'] = 0;
$GLOBALS['b2_mail']  = array();
function b2_check( $label, $cond ) { echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $GLOBALS['b2_fails']++; } }
function b2_last() { return end( $GLOBALS['b2_mail'] ) ?: array(); }

add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	$atts['content_type'] = apply_filters( 'wp_mail_content_type', 'text/html' );
	$atts['from']         = apply_filters( 'wp_mail_from', 'wordpress@example.test' );
	$atts['from_name']    = apply_filters( 'wp_mail_from_name', 'WordPress' );
	$GLOBALS['b2_mail'][] = $atts;
	return true;
}, 10, 2 );
set_error_handler( function ( $no, $str, $file, $line ) { if ( false !== strpos( $file, 'brain-2fa' ) && false === strpos( $file, 'vendor' ) ) { echo "PHP ISSUE: $str in $file:$line\n"; } return false; } );

$missing   = new stdClass();
$orig_e    = get_option( R::OPTION, $missing );
$orig_s    = get_option( 'brain2fa_settings', $missing );
$uid       = 0;
$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

try {
	delete_option( R::OPTION );
	$uid  = wp_insert_user( array( 'user_login' => 'b2fa_m_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'b2fa-mail@example.test', 'display_name' => "Dana\r\nBcc: evil@example.test", 'first_name' => 'Dana' ) );
	$user = new WP_User( $uid );

	// 1. Default sign-in code.
	delete_transient( 'brain2fa_backup_code_' . $uid );
	brain_2fa()->manager->get_method( 'email' )->send_challenge( $user );
	$m    = b2_last();
	$code = get_transient( 'brain2fa_backup_code_' . $uid );
	b2_check( 'code email: default subject', "[$site_name] Your two-factor authentication code" === $m['subject'] );
	b2_check( 'code email: contains stored code and expiry', $code && false !== strpos( $m['message'], "\n\n$code\n\n" ) && false !== strpos( $m['message'], 'expires in ' . Brain_2FA\Utils::get_settings()['code_expiry'] . ' minutes' ) );
	b2_check( 'code email: no unreplaced placeholders', ! preg_match( '/\{[a-z_]+\}/', $m['subject'] . $m['message'] ) );
	b2_check( 'plain text forced during send, filter removed after', 'text/plain' === $m['content_type'] && 'text/html' === apply_filters( 'wp_mail_content_type', 'text/html' ) );

	// 2. Default alert with passkey name.
	Brain_2FA\Utils::send_factor_change_notification( $user, 'passkey_added', array( 'passkey_name' => 'YubiKey 5' ) );
	$m = b2_last();
	b2_check( 'alert: default subject', "[$site_name] Your two-factor authentication settings changed" === $m['subject'] );
	b2_check( 'alert: passkey name, time and site url', false !== strpos( $m['message'], '"YubiKey 5"' ) && false !== strpos( $m['message'], 'Time: ' ) && false !== strpos( $m['message'], home_url( '/' ) ) );
	$count = count( $GLOBALS['b2_mail'] );
	Brain_2FA\Utils::send_factor_change_notification( $user, 'not_an_event' );
	Brain_2FA\Utils::send_factor_change_notification( $user, 'sign_in_code' );
	b2_check( 'unknown event and non-alert IDs are not sent as alerts', count( $GLOBALS['b2_mail'] ) === $count );

	// 3. Customize.
	$r = R::save_template( 'activated', true, 'Hello {user_first_name} from {site_name}', "Line one {user_login}\r\nLine two <b>bold</b>" );
	b2_check( 'save custom: ok', true === $r );
	Brain_2FA\Utils::send_factor_change_notification( $user, 'activated' );
	$m = b2_last();
	b2_check( 'custom subject with first name', "Hello Dana from $site_name" === $m['subject'] );
	b2_check( 'custom body: CRLF normalized, tags stripped', "Line one {$user->user_login}\nLine two bold" === $m['message'] );
	b2_check( 'list shows customized', R::get_template( 'activated' )['customized'] );

	// 4. Saving the default stores nothing.
	$d = R::get_definitions()['deactivated'];
	R::save_template( 'deactivated', true, $d['subject'], str_replace( "\n", "\r\n", $d['body'] ) );
	b2_check( 'default text is not stored', ! isset( R::get_stored()['templates']['deactivated'] ) );

	// 5. {code} is required.
	$r = R::save_template( 'sign_in_code', true, 'Code', 'No code here' );
	b2_check( 'sign-in code without {code} refused', is_wp_error( $r ) && $r->get_error_messages( 'body' ) && ! isset( R::get_stored()['templates']['sign_in_code'] ) );
	b2_check( 'empty subject refused', is_wp_error( R::save_template( 'activated', true, "  \n ", 'x' ) ) );
	b2_check( 'too long body refused', is_wp_error( R::save_template( 'activated', true, 'x', str_repeat( 'a', 10001 ) ) ) );

	// 6. Header injection.
	R::save_template( 'deactivated', true, "Hi {user_display_name}\r\nBcc: x@example.test", '{user_display_name}' );
	Brain_2FA\Utils::send_factor_change_notification( $user, 'deactivated' );
	$m = b2_last();
	b2_check( 'subject is single line even with newline in display name', false === strpos( $m['subject'], "\n" ) && false === strpos( $m['subject'], "\r" ) );

	// 7. On/off.
	R::save_template( 'passkey_removed', false, R::get_definitions()['passkey_removed']['subject'], R::get_definitions()['passkey_removed']['body'] );
	$count = count( $GLOBALS['b2_mail'] );
	Brain_2FA\Utils::send_factor_change_notification( $user, 'passkey_removed', array( 'passkey_name' => 'k' ) );
	b2_check( 'turned-off alert not sent', count( $GLOBALS['b2_mail'] ) === $count );
	R::save_template( 'sign_in_code', false, R::get_definitions()['sign_in_code']['subject'], R::get_definitions()['sign_in_code']['body'] );
	b2_check( 'sign-in code cannot be turned off', R::is_enabled( 'sign_in_code' ) );
	$before = $stored = R::get_stored();
	$stored['templates']['sign_in_code'] = array( 'enabled' => false, 'subject' => 'Tampered', 'body' => 'No code here' );
	update_option( R::OPTION, $stored, false );
	$t = R::get_template( 'sign_in_code' );
	b2_check( 'stored code body without {code} falls back to default, still on', $t['enabled'] && R::get_definitions()['sign_in_code']['body'] === $t['body'] && 'Tampered' === $t['subject'] );
	update_option( R::OPTION, $before, false );

	// 8. Reset keeps on/off.
	R::save_template( 'passkey_removed', false, 'Custom', 'Custom body' );
	R::reset_template( 'passkey_removed' );
	$t = R::get_template( 'passkey_removed' );
	b2_check( 'reset: default text back, still off', ! $t['customized'] && ! $t['enabled'] );
	R::reset_template( 'activated' );
	b2_check( 'reset: activated default again', ! R::get_template( 'activated' )['customized'] );

	// 9. Existing veto filter.
	add_filter( 'brain2fa_send_change_notification', '__return_false' );
	$count = count( $GLOBALS['b2_mail'] );
	Brain_2FA\Utils::send_factor_change_notification( $user, 'activated' );
	b2_check( 'brain2fa_send_change_notification veto still works', count( $GLOBALS['b2_mail'] ) === $count );
	remove_filter( 'brain2fa_send_change_notification', '__return_false' );

	// 10. Sender.
	b2_check( 'invalid sender address refused', is_wp_error( R::save_sender( 'Security', 'not-an-email' ) ) );
	R::save_sender( 'Site Security', 'security@example.test' );
	Brain_2FA\Utils::send_factor_change_notification( $user, 'activated' );
	$m = b2_last();
	b2_check( 'sender applied to plugin email', 'security@example.test' === $m['from'] && 'Site Security' === $m['from_name'] );
	b2_check( 'sender not applied outside plugin emails', 'wordpress@example.test' === apply_filters( 'wp_mail_from', 'wordpress@example.test' ) );

	// 11. Placeholder and content filters.
	$f = function ( $v ) { $v['user_first_name'] = 'Filtered'; $v['custom_token'] = 'XYZ'; return $v; };
	add_filter( 'brain2fa_email_placeholders', $f );
	$r = Mailer::render( 'activated', $user, array(), array( 'subject' => '{user_first_name} {custom_token} {unknown}', 'body' => 'b' ) );
	b2_check( 'placeholder filter + unknown placeholder kept', 'Filtered XYZ {unknown}' === $r['subject'] );
	remove_filter( 'brain2fa_email_placeholders', $f );

	// 12. Admin tab rendering.
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admin[0]->ID );
	$GLOBALS['title'] = 'Brain 2FA Settings';
	R::save_template( 'activated', true, 'Custom subject', 'Custom body {user_login}' );
	$_GET = array( 'tab' => 'emails' );
	ob_start(); Brain_2FA\Admin\Settings::instance()->render_settings_page(); $h = ob_get_clean();
	b2_check( 'list: all 10 emails with edit links', 10 === substr_count( $h, 'tab=emails&#038;email=' ) / 2 || 20 === substr_count( $h, 'email=' ) );
	b2_check( 'list: customized + always on + sender form', preg_match( '#<td data-colname="Text">\s*Customized\s*</td>#', $h ) && false !== strpos( $h, 'Always on' ) && false !== strpos( $h, 'name="brain2fa_from_address"' ) );
	b2_check( 'list: Emails tab active, no options.php form', false !== strpos( $h, 'aria-current="page">' ) && false === strpos( $h, 'action="options.php"' ) );
	$_GET = array( 'tab' => 'emails', 'email' => 'sign_in_code' );
	ob_start(); Brain_2FA\Admin\Settings::instance()->render_settings_page(); $h = ob_get_clean();
	b2_check( 'editor: {code} placeholder, always on, preview with sample code', false !== strpos( $h, 'data-placeholder="{code}"' ) && false !== strpos( $h, 'cannot be turned off' ) && false !== strpos( $h, '123456' ) );
	b2_check( 'editor: no reset for default email', false === strpos( $h, 'Reset to default text' ) );
	$_GET = array( 'tab' => 'emails', 'email' => 'bogus' );
	ob_start(); Brain_2FA\Admin\Settings::instance()->render_settings_page(); $h = ob_get_clean();
	b2_check( 'unknown email id falls back to list', false !== strpos( $h, 'name="brain2fa_from_address"' ) );

	// 13. Form handling: a failed save keeps the text; a successful one redirects.
	$_GET  = array( 'tab' => 'emails', 'email' => 'sign_in_code' );
	$_POST = $_REQUEST = array(
		'brain2fa_email_action' => 'save_template',
		'brain2fa_email_id'     => 'sign_in_code',
		'brain2fa_email_subject' => 'My subject',
		'brain2fa_email_body'   => 'Forgot the code',
		'brain2fa_email_nonce'  => wp_create_nonce( 'brain2fa_email_settings' ),
	);
	EmailSettings::handle_post();
	ob_start(); EmailSettings::render_tab(); $h = ob_get_clean();
	b2_check( 'failed save: error beside field, text kept', false !== strpos( $h, 'brain2fa-error-body' ) && false !== strpos( $h, 'Forgot the code</textarea>' ) && false !== strpos( $h, 'value="My subject"' ) );

	$redirect = '';
	$catch    = function ( $location ) use ( &$redirect ) { $redirect = $location; throw new RuntimeException( 'redirect' ); };
	add_filter( 'wp_redirect', $catch );
	$_POST['brain2fa_email_body'] = "Your code: {code}";
	$_REQUEST = $_POST;
	try { EmailSettings::handle_post(); } catch ( RuntimeException $e ) {}
	b2_check( 'successful save redirects with notice', false !== strpos( $redirect, 'brain2fa_email_notice=saved' ) && 'Your code: {code}' === R::get_template( 'sign_in_code' )['body'] );
	$_POST = $_REQUEST = array( 'brain2fa_email_action' => 'send_test', 'brain2fa_email_id' => 'sign_in_code', 'brain2fa_email_nonce' => wp_create_nonce( 'brain2fa_email_settings' ) );
	$redirect = '';
	try { EmailSettings::handle_post(); } catch ( RuntimeException $e ) {}
	$m = b2_last();
	b2_check( 'test email to current admin with sample code', 'test_sent' === wp_parse_args( wp_parse_url( $redirect, PHP_URL_QUERY ) )['brain2fa_email_notice'] && $admin[0]->user_email === $m['to'] && 'Your code: 123456' === $m['message'] );
	remove_filter( 'wp_redirect', $catch );

	wp_set_current_user( $uid );
	$_POST = $_REQUEST = array( 'brain2fa_email_action' => 'reset_template', 'brain2fa_email_id' => 'sign_in_code', 'brain2fa_email_nonce' => wp_create_nonce( 'brain2fa_email_settings' ) );
	EmailSettings::handle_post();
	b2_check( 'non-admin cannot change emails', 'Your code: {code}' === R::get_template( 'sign_in_code' )['body'] );
} finally {
	$_POST = $_REQUEST = $_GET = array();
	if ( $uid ) { wp_delete_user( $uid ); }
	foreach ( array( R::OPTION => $orig_e, 'brain2fa_settings' => $orig_s ) as $opt => $orig ) {
		remove_all_filters( 'sanitize_option_' . $opt );
		if ( $orig === $missing ) { delete_option( $opt ); } else { update_option( $opt, $orig ); }
	}
	echo 'Cleanup: user removed=' . ( $uid && ! get_userdata( $uid ) ? 'yes' : 'NO' ) . ', options restored=' . ( get_option( R::OPTION, $missing ) == $orig_e && get_option( 'brain2fa_settings', $missing ) == $orig_s ? 'yes' : 'NO' ) . "\n";
}
$b2_failed = (int) $GLOBALS['b2_fails'];
echo $b2_failed ? $b2_failed . " FAILED\n" : "ALL PASSED\n";
if ( $b2_failed ) {
	WP_CLI::halt( 1 );
}
