<?php
/**
 * Brain 2FA WP-CLI check: WooCommerce My Account Login Security tab.
 *
 * Endpoint and menu registration, who uses the tab (customers) and who keeps wp-admin (staff), every link that
 * points to a user's Login Security page (setup URL, login redirects, emails, recovery warning, dashboard status),
 * restricted mode on the storefront, the redirect from the wp-admin page, the tab markup, actions handled before
 * output (nonce, password, current user only) and the tab assets.
 * Restores brain2fa_settings and removes the test users.
 *
 * Development sites only: it writes options and creates users while it runs. Needs WooCommerce with a My Account page.
 * Run all checks with `npm run test:wp`, or this one with
 * `php ../../../wp-cli.phar eval-file tests/wp-cli/account-tab.php` from the plugin directory.
 * Prints PASS/FAIL per check and exits 1 when any check fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';

use Brain_2FA\Admin\LoginSecurityPage;
use Brain_2FA\Auth\EnrollmentGuard as G;
use Brain_2FA\Auth\RecoveryCodeWarning;
use Brain_2FA\Auth\TrustedDeviceRepository;
use Brain_2FA\Emails\Mailer;
use Brain_2FA\Enrollment\ReminderScheduler;
use Brain_2FA\Passkeys\PasskeyRepository;
use Brain_2FA\Utils;
use Brain_2FA\WooCommerce\LoginSecurityTab as T;

global $wpdb;
$missing  = new stdClass();
$original = get_option( 'brain2fa_settings', $missing );
$GLOBALS['b2_users'] = array();
$GLOBALS['b2_fails'] = 0;
set_error_handler( function ( $no, $str, $file, $line ) { if ( false !== strpos( $file, 'brain-2fa' ) ) { echo "PHP ISSUE: $str in $file:$line\n"; } return false; } );

/**
 * Thrown instead of redirecting or dying, so a check can see where a request would go.
 */
class B2_Stop extends Exception {}

function b2_check( $label, $cond ) { echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $GLOBALS['b2_fails']++; } }
function b2_settings( array $over ) { remove_all_filters( 'sanitize_option_brain2fa_settings' ); update_option( 'brain2fa_settings', array_merge( Utils::SETTINGS_DEFAULTS, array( 'enable_2fa' => true ), $over ) ); }

/**
 * Create a test user.
 *
 * @param string $role Role.
 * @param bool   $totp Whether the authenticator app is set up.
 * @return int User ID.
 */
function b2_user( $role, $totp = false ) {
	$id = wp_insert_user( array( 'user_login' => 'b2fa_t_' . wp_generate_password( 6, false, false ), 'user_pass' => 'b2-Pass-123', 'user_email' => 'b2fa-t-' . wp_generate_password( 6, false, false ) . '@example.test', 'role' => $role ) );
	$GLOBALS['b2_users'][] = $id;
	if ( $totp ) {
		update_user_meta( $id, 'brain2fa_method', 'totp' );
		update_user_meta( $id, 'brain2fa_enabled', 1 );
		update_user_meta( $id, 'brain2fa_secret', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' );
		update_user_meta( $id, 'brain2fa_recovery_codes', array( wp_hash_password( '11111111' ) ) );
	}
	return $id;
}

/**
 * Reset the per-request state of the Login Security page.
 *
 * @return void
 */
function b2_reset_page() {
	foreach ( array( 'notices' => array(), 'open' => '', 'outcome' => null, 'context' => LoginSecurityPage::CONTEXT_ADMIN ) as $prop => $value ) {
		$ref = new ReflectionProperty( LoginSecurityPage::class, $prop );
		$ref->setAccessible( true );
		$ref->setValue( null, $value );
	}
}

/**
 * Pretend the main query is (or is not) the tab or another My Account endpoint.
 *
 * @param string|null $endpoint Endpoint key, or null for an ordinary page.
 * @return void
 */
function b2_route( $endpoint ) {
	$GLOBALS['wp']->query_vars = array();
	if ( null !== $endpoint ) {
		$vars = WC()->query->get_query_vars();
		$GLOBALS['wp']->query_vars[ $vars[ $endpoint ] ?? $endpoint ] = '';
	}
}

/**
 * Run a callback and return where it redirected to, or '' when it did not.
 *
 * @param callable $fn Callback.
 * @return string Redirect location.
 */
function b2_redirect_of( callable $fn ) {
	try {
		$fn();
	} catch ( B2_Stop $stop ) {
		return $stop->getMessage();
	}
	return '';
}

/**
 * Render the tab for the current user.
 *
 * @return string HTML.
 */
function b2_render_tab() {
	ob_start();
	T::render();
	return (string) ob_get_clean();
}

/**
 * Submit a Login Security action on the tab as the current user.
 *
 * @param array $fields POST fields.
 * @param bool  $nonce  Whether to send a valid nonce.
 * @return string HTML of the tab after the action ('' when the request was stopped).
 */
function b2_post( array $fields, $nonce = true ) {
	b2_reset_page();
	b2_route( T::ENDPOINT );
	$_POST = $_REQUEST = $fields + array( 'brain2fa_setup_nonce' => $nonce ? wp_create_nonce( 'brain2fa_setup_action' ) : 'nope' );
	try {
		T::handle_request();
	} catch ( B2_Stop $stop ) {
		$_POST = $_REQUEST = array();
		return '';
	}
	$_POST = $_REQUEST = array();
	return b2_render_tab();
}

$stop = function ( $location ) { throw new B2_Stop( (string) $location ); };
add_filter( 'wp_redirect', $stop, 1 );
$die = function () { return function () { throw new B2_Stop( 'die' ); }; };
add_filter( 'wp_die_handler', $die, PHP_INT_MAX );

try {
	b2_settings( array() );

	$customer   = b2_user( 'customer' );
	$subscriber = b2_user( 'subscriber' );
	$editor     = b2_user( 'editor' );
	$manager    = b2_user( 'shop_manager' );
	$c          = get_userdata( $customer );
	$tab_url    = wc_get_account_endpoint_url( T::ENDPOINT );
	$admin_url  = admin_url( 'admin.php?page=' . G::SETUP_PAGE );

	// Registration.
	$vars = WC()->query->get_query_vars();
	b2_check( 'endpoint: registered with the default slug', 'login-security' === ( $vars[ T::ENDPOINT ] ?? '' ) );
	b2_check( 'endpoint: URL under My Account', 0 === strpos( $tab_url, wc_get_page_permalink( 'myaccount' ) ) && false !== strpos( $tab_url, 'login-security' ) && T::get_url() === $tab_url );
	b2_check( 'endpoint: rewrite rules flushed for the slug', 'login-security' === get_option( T::FLUSHED_OPTION ) );
	$slug = function () { return 'Two Factor!'; };
	add_filter( 'brain2fa_account_security_tab_slug', $slug );
	b2_check( 'endpoint: slug filter, sanitized', 'two-factor' === T::get_slug() );
	remove_filter( 'brain2fa_account_security_tab_slug', $slug );

	wp_set_current_user( $customer );
	$items = wc_get_account_menu_items();
	$keys  = array_keys( $items );
	b2_check( 'menu: Login Security before Log out', 'Login Security' === ( $items[ T::ENDPOINT ] ?? '' ) && array_search( T::ENDPOINT, $keys, true ) === array_search( 'customer-logout', $keys, true ) - 1 );
	b2_check( 'menu: page title', 'Login Security' === WC()->query->get_endpoint_title( T::ENDPOINT ) );

	// Who uses the tab.
	b2_check( 'customer uses the tab', T::is_users_page( $c ) );
	b2_check( 'subscriber uses the tab (also kept out of wp-admin)', T::is_users_page( get_userdata( $subscriber ) ) );
	b2_check( 'editor keeps wp-admin', ! T::is_users_page( get_userdata( $editor ) ) );
	b2_check( 'shop manager keeps wp-admin', ! T::is_users_page( get_userdata( $manager ) ) );
	b2_check( 'administrator keeps wp-admin', ! T::is_users_page( get_userdata( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ) ) );
	$no = '__return_false';
	add_filter( 'brain2fa_use_account_security_tab', $no );
	b2_check( 'per-user filter sends a customer to wp-admin', ! T::is_users_page( $c ) && $admin_url === G::get_setup_url( $c ) );
	remove_filter( 'brain2fa_use_account_security_tab', $no );
	add_filter( 'brain2fa_account_security_tab', $no );
	b2_check( 'tab turned off: no menu item, wp-admin for everyone', ! isset( wc_get_account_menu_items()[ T::ENDPOINT ] ) && $admin_url === G::get_setup_url( $c ) && ! isset( WC()->query->get_query_vars()[ T::ENDPOINT ] ) );
	remove_filter( 'brain2fa_account_security_tab', $no );

	// Links to the user's Login Security page.
	b2_check( 'setup URL: customer gets the tab', $tab_url === G::get_setup_url( $c ) );
	b2_check( 'setup URL: editor gets wp-admin', $admin_url === G::get_setup_url( get_userdata( $editor ) ) );
	b2_check( 'setup URL: defaults to the signed-in user', $tab_url === G::get_setup_url() );
	wp_set_current_user( 0 );
	b2_check( 'setup URL: signed out gets wp-admin', $admin_url === G::get_setup_url() );
	$values = Mailer::get_placeholder_values( ReminderScheduler::EMAIL_ID, $c );
	b2_check( 'email: {login_security_url} is the tab for a customer, even from cron', $tab_url === $values['{login_security_url}'] );
	$values = Mailer::get_placeholder_values( ReminderScheduler::EMAIL_ID, get_userdata( $editor ) );
	b2_check( 'email: {login_security_url} is wp-admin for an editor', $admin_url === $values['{login_security_url}'] );

	$totp_customer = b2_user( 'customer', true );
	wp_set_current_user( $totp_customer );
	b2_check( 'recovery warning links to the tab', $tab_url . '#brain2fa-recovery' === RecoveryCodeWarning::get_url() );
	ob_start();
	RecoveryCodeWarning::render_account_notice();
	G::render_my_account_status();
	$dashboard = (string) ob_get_clean();
	b2_check( 'dashboard: warning and status link to the tab', false !== strpos( $dashboard, esc_url( $tab_url . '#brain2fa-recovery' ) ) && false !== strpos( $dashboard, 'href="' . esc_url( $tab_url ) . '"' ) && false === strpos( $dashboard, 'wp-admin' ) );

	// Restricted mode: a customer in a required role after a 0-day grace period.
	b2_settings( array( 'force_2fa_roles' => array( 'customer', 'editor' ), 'grace_period_days' => 0 ) );
	$restricted = b2_user( 'customer' );
	$r          = get_userdata( $restricted );
	b2_check( 'restricted: customer must enroll', Utils::is_enrollment_required( $r ) );
	b2_check( 'restricted: WordPress login goes to the tab', $tab_url === G::filter_login_redirect( home_url( '/' ), '', $r ) );
	b2_check( 'restricted: WooCommerce login goes to the tab', $tab_url === G::filter_woocommerce_login_redirect( home_url( '/' ), $r ) );
	wp_set_current_user( $restricted );
	b2_route( null );
	b2_check( 'restricted: other front-end pages redirect to the tab', $tab_url === b2_redirect_of( array( G::class, 'restrict_front_end' ) ) );
	b2_route( 'orders' );
	b2_check( 'restricted: other My Account endpoints redirect to the tab', $tab_url === b2_redirect_of( array( G::class, 'restrict_front_end' ) ) );
	b2_route( T::ENDPOINT );
	b2_check( 'restricted: the tab itself is allowed', '' === b2_redirect_of( array( G::class, 'restrict_front_end' ) ) );
	b2_route( 'customer-logout' );
	b2_check( 'restricted: My Account log out is allowed', '' === b2_redirect_of( array( G::class, 'restrict_front_end' ) ) );
	$restricted_editor = b2_user( 'editor' );
	wp_set_current_user( $restricted_editor );
	b2_route( null );
	b2_check( 'restricted: an editor still goes to wp-admin', $admin_url === b2_redirect_of( array( G::class, 'restrict_front_end' ) ) );
	b2_route( T::ENDPOINT );
	b2_check( 'restricted: an editor may also set up in the tab', '' === b2_redirect_of( array( G::class, 'restrict_front_end' ) ) );

	// The wp-admin page sends customers to the tab.
	set_current_screen( 'toplevel_page_' . G::SETUP_PAGE );
	$GLOBALS['pagenow'] = 'admin.php';
	$_GET               = array( 'page' => G::SETUP_PAGE );
	wp_set_current_user( $customer );
	b2_check( 'wp-admin page: customer redirected to the tab', $tab_url === b2_redirect_of( array( G::class, 'redirect_to_account_tab' ) ) );
	wp_set_current_user( $restricted );
	b2_check( 'wp-admin page: restricted customer redirected to the tab', $tab_url === b2_redirect_of( array( G::class, 'redirect_to_account_tab' ) ) );
	b2_check( 'wp-admin: restricted customer elsewhere goes to the tab', $tab_url === b2_redirect_of( function () { $_GET = array(); G::restrict_admin(); } ) );
	$_GET = array( 'page' => G::SETUP_PAGE );
	wp_set_current_user( $editor );
	b2_check( 'wp-admin page: editor stays', '' === b2_redirect_of( array( G::class, 'redirect_to_account_tab' ) ) );
	$_GET               = array();
	$GLOBALS['pagenow'] = 'index.php';
	$GLOBALS['current_screen'] = null;
	b2_settings( array() );

	// Tab markup.
	wp_set_current_user( $customer );
	b2_reset_page();
	b2_route( T::ENDPOINT );
	$html = b2_render_tab();
	b2_check( 'markup: account wrapper, no wp-admin title or wrap', false !== strpos( $html, 'class="brain2fa-login-security brain2fa-account-security"' ) && false === strpos( $html, '<h1' ) && false === strpos( $html, 'class="wrap' ) );
	b2_check( 'markup: all sections render (summary, codes)', false !== strpos( $html, 'brain2fa-account-summary' ) && false !== strpos( $html, 'id="brain2fa-codes"' ) );
	b2_check( 'markup: primary buttons use the theme button classes', 1 === preg_match( '/<button type="submit" class="button( [a-z-]+)? alt">/', $html ) && false === strpos( $html, 'button-primary' ) );
	b2_check( 'markup: forms post back to the tab with the nonce', false !== strpos( $html, 'action=""' ) && false !== strpos( $html, 'name="brain2fa_setup_nonce"' ) );
	b2_reset_page();
	b2_check( 'admin context: unchanged button classes', 'button button-primary' === LoginSecurityPage::button_class( 'primary' ) && 'button brain2fa-button-danger' === LoginSecurityPage::button_class( 'danger' ) && 'button' === LoginSecurityPage::button_class() );

	// Actions, handled before output.
	wp_set_current_user( $totp_customer );
	$before = get_user_meta( $totp_customer, 'brain2fa_recovery_codes', true );
	$html   = b2_post( array( 'brain2fa_action' => 'regenerate_recovery_codes', 'brain2fa_confirm_password' => 'wrong' ) );
	b2_check( 'regenerate: wrong password refused, codes unchanged', false !== strpos( $html, 'notice-error' ) && $before === get_user_meta( $totp_customer, 'brain2fa_recovery_codes', true ) );
	delete_user_meta( $totp_customer, 'brain2fa_failed_attempts' );
	$html = b2_post( array( 'brain2fa_action' => 'regenerate_recovery_codes', 'brain2fa_confirm_password' => 'b2-Pass-123' ), false );
	b2_check( 'regenerate: bad nonce stops the request, codes unchanged', '' === $html && $before === get_user_meta( $totp_customer, 'brain2fa_recovery_codes', true ) );
	$html  = b2_post( array( 'brain2fa_action' => 'regenerate_recovery_codes', 'brain2fa_confirm_password' => 'b2-Pass-123' ) );
	$after = get_user_meta( $totp_customer, 'brain2fa_recovery_codes', true );
	b2_check( 'regenerate: new codes saved and shown once', $before !== $after && 10 <= count( (array) $after ) && false !== strpos( $html, 'brain2fa-recovery-codes-list' ) && false !== strpos( $html, 'id="brain2fa-download-codes"' ) );
	b2_reset_page();
	b2_check( 'regenerate: codes not shown on the next view', false === strpos( b2_render_tab(), 'brain2fa-recovery-codes-list' ) );

	b2_settings( array( 'remember_device' => true ) );
	// add() returns the cookie value, "{id}.{token}".
	$device = strtok( TrustedDeviceRepository::add( $totp_customer, time() + DAY_IN_SECONDS, 'Mozilla/5.0 Test' ), '.' );
	$other  = strtok( TrustedDeviceRepository::add( $customer, time() + DAY_IN_SECONDS, 'Mozilla/5.0 Test' ), '.' );
	b2_post( array( 'brain2fa_action' => 'forget_device', 'brain2fa_device_id' => $other ) );
	b2_check( 'forget device: another user\'s device is not touched', 1 === count( TrustedDeviceRepository::get_all( $customer ) ) && 1 === count( TrustedDeviceRepository::get_all( $totp_customer ) ) );
	$html = b2_post( array( 'brain2fa_action' => 'forget_device', 'brain2fa_device_id' => $device ) );
	b2_check( 'forget device: own device forgotten, result shown', 0 === count( TrustedDeviceRepository::get_all( $totp_customer ) ) && false !== strpos( $html, 'The device was forgotten.' ) );

	$html = b2_post( array( 'brain2fa_action' => 'deactivate', 'brain2fa_confirm_password' => 'b2-Pass-123' ) );
	b2_check( 'deactivate: password confirmed, method off', ! get_user_meta( $totp_customer, 'brain2fa_enabled', true ) && false !== strpos( $html, 'Two-factor codes are turned off.' ) );

	update_user_meta( $totp_customer, 'brain2fa_enabled', 1 );
	update_user_meta( $totp_customer, 'brain2fa_method', 'totp' );
	b2_reset_page();
	b2_route( null );
	$_POST = $_REQUEST = array( 'brain2fa_action' => 'deactivate', 'brain2fa_confirm_password' => 'b2-Pass-123', 'brain2fa_setup_nonce' => wp_create_nonce( 'brain2fa_setup_action' ) );
	T::handle_request();
	$_POST = $_REQUEST = array();
	b2_check( 'off the tab: actions are not handled', '1' === (string) get_user_meta( $totp_customer, 'brain2fa_enabled', true ) );

	// Assets.
	wp_set_current_user( $customer );
	b2_route( T::ENDPOINT );
	T::enqueue_assets();
	b2_check( 'assets: tab style and script on the tab', wp_style_is( 'brain-2fa-account' ) && wp_script_is( 'brain-2fa-account' ) && file_exists( BRAIN_2FA_PLUGIN_DIR . 'assets/css/account.css' ) && file_exists( BRAIN_2FA_PLUGIN_DIR . 'assets/js/account.js' ) );
	b2_check( 'assets: no passkey script without HTTPS', ! wp_script_is( 'brain2fa-passkey-manage' ) );
	wp_dequeue_style( 'brain-2fa-account' );
	wp_dequeue_script( 'brain-2fa-account' );
	b2_route( null );
	T::enqueue_assets();
	b2_check( 'assets: nothing elsewhere', ! wp_style_is( 'brain-2fa-account' ) && ! wp_script_is( 'brain-2fa-account' ) );
} finally {
	restore_error_handler();
	remove_filter( 'wp_redirect', $stop, 1 );
	remove_filter( 'wp_die_handler', $die, PHP_INT_MAX );
	$_GET = $_POST = $_REQUEST = array();
	b2_route( null );
	wp_set_current_user( 0 );
	foreach ( $GLOBALS['b2_users'] as $id ) { PasskeyRepository::delete_all_for_user( $id ); wp_delete_user( $id ); }
	remove_all_filters( 'sanitize_option_brain2fa_settings' );
	if ( $original === $missing ) { delete_option( 'brain2fa_settings' ); } else { update_option( 'brain2fa_settings', $original ); }
	echo 'Cleanup: users removed=' . ( ! array_filter( array_map( 'get_userdata', $GLOBALS['b2_users'] ) ) ? 'yes' : 'NO' ) . ', settings restored=' . ( get_option( 'brain2fa_settings', $missing ) == $original ? 'yes' : 'NO' ) . "\n";
}

$b2_failed = (int) $GLOBALS['b2_fails'];
echo $b2_failed ? "$b2_failed FAILED\n" : "ALL PASSED\n";
if ( $b2_failed ) {
	WP_CLI::halt( 1 );
}
