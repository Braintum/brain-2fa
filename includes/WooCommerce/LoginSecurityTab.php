<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * WooCommerce My Account Login Security tab
 *
 * @package Brain_2FA\WooCommerce
 * @since 1.3.0
 */

namespace Brain_2FA\WooCommerce;

use Brain_2FA\Admin\Assets;
use Brain_2FA\Admin\LoginSecurityPage;
use Brain_2FA\Passkeys\PasskeyLogin;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a Login Security tab to WooCommerce My Account.
 *
 * The tab renders the same sections and handles the same actions as the wp-admin
 * Login Security page, always for the signed-in user. Customers (users WooCommerce keeps
 * out of wp-admin) are sent here instead of to wp-admin: setup redirects, restricted mode,
 * email links and notices all use the tab for them, see EnrollmentGuard::get_setup_url().
 *
 * @since 1.3.0
 */
class LoginSecurityTab {

	/**
	 * WooCommerce endpoint key. Hooks and wc_get_account_endpoint_url() use it.
	 *
	 * @var string
	 */
	public const ENDPOINT = 'brain2fa-login-security';

	/**
	 * Option holding the endpoint slug the rewrite rules were last flushed for.
	 *
	 * @var string
	 */
	public const FLUSHED_OPTION = 'brain2fa_account_endpoint';

	/**
	 * Register hooks when WooCommerce is active.
	 *
	 * The hooks check is_enabled() themselves, so a theme can still turn the tab off.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'maybe_flush_rewrite_rules' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'get_title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Check whether the tab is available on this site.
	 *
	 * @return bool True when WooCommerce has a My Account page and the tab is not turned off.
	 */
	public static function is_enabled(): bool {
		if ( ! function_exists( 'wc_get_page_id' ) || wc_get_page_id( 'myaccount' ) < 1 ) {
			return false;
		}

		/**
		 * Filters whether WooCommerce My Account gets a Login Security tab.
		 *
		 * @since 1.3.0
		 *
		 * @param bool $enabled Whether the tab is available. Default true.
		 */
		return (bool) apply_filters( 'brain2fa_account_security_tab', true );
	}

	/**
	 * Check whether a user manages login security in the tab instead of wp-admin.
	 *
	 * By default these are the users WooCommerce keeps out of wp-admin: those without
	 * edit_posts, manage_woocommerce and view_admin_dashboard. Anyone can still open the tab.
	 *
	 * @param WP_User $user User.
	 * @return bool True when the user's Login Security page is the tab.
	 */
	public static function is_users_page( WP_User $user ): bool {
		if ( ! $user->exists() || ! self::is_enabled() ) {
			return false;
		}

		$is_customer = ! user_can( $user, 'edit_posts' ) && ! user_can( $user, 'manage_woocommerce' ) && ! user_can( $user, 'view_admin_dashboard' );

		/**
		 * Filters whether a user is sent to the My Account Login Security tab instead of wp-admin.
		 *
		 * @since 1.3.0
		 *
		 * @param bool    $use_tab Whether the tab is the user's Login Security page. Default true for customers.
		 * @param WP_User $user    User.
		 */
		return (bool) apply_filters( 'brain2fa_use_account_security_tab', $is_customer, $user );
	}

	/**
	 * Get the tab URL.
	 *
	 * @return string URL.
	 */
	public static function get_url(): string {
		return wc_get_account_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Get the endpoint slug used in the URL.
	 *
	 * @return string Slug.
	 */
	public static function get_slug(): string {
		/**
		 * Filters the URL slug of the My Account Login Security tab.
		 *
		 * @since 1.3.0
		 *
		 * @param string $slug Endpoint slug. Default "login-security".
		 */
		$slug = sanitize_title( (string) apply_filters( 'brain2fa_account_security_tab_slug', 'login-security' ) );

		return '' !== $slug ? $slug : 'login-security';
	}

	/**
	 * Check whether the current request is for the tab.
	 *
	 * @return bool True on the tab.
	 */
	public static function is_tab_request(): bool {
		return self::is_enabled() && function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( self::ENDPOINT );
	}

	/**
	 * Register the endpoint with WooCommerce, which adds its rewrite endpoint.
	 *
	 * @param array<string, string> $query_vars Endpoint slugs keyed by endpoint.
	 * @return array<string, string> Query vars.
	 */
	public static function add_query_var( $query_vars ) {
		if ( is_array( $query_vars ) && self::is_enabled() ) {
			$query_vars[ self::ENDPOINT ] = self::get_slug();
		}

		return $query_vars;
	}

	/**
	 * Flush the rewrite rules once whenever the endpoint slug changes.
	 *
	 * @return void
	 */
	public static function maybe_flush_rewrite_rules(): void {
		$slug = self::is_enabled() ? self::get_slug() : '';
		if ( get_option( self::FLUSHED_OPTION ) === $slug ) {
			return;
		}

		update_option( self::FLUSHED_OPTION, $slug, true );
		flush_rewrite_rules( false );
	}

	/**
	 * Add the tab to the My Account menu, before Log out.
	 *
	 * @param array<string, string> $items Menu labels keyed by endpoint.
	 * @return array<string, string> Menu items.
	 */
	public static function add_menu_item( $items ) {
		if ( ! is_array( $items ) || ! self::is_enabled() ) {
			return $items;
		}

		$item = array( self::ENDPOINT => self::get_title() );

		if ( isset( $items['customer-logout'] ) ) {
			$position = (int) array_search( 'customer-logout', array_keys( $items ), true );

			return array_slice( $items, 0, $position, true ) + $item + array_slice( $items, $position, null, true );
		}

		return $items + $item;
	}

	/**
	 * Get the tab title, used for the menu item and the page title.
	 *
	 * @return string Translated title.
	 */
	public static function get_title(): string {
		return __( 'Login Security', 'brain-2fa' );
	}

	/**
	 * Handle a submitted action before the page is sent.
	 *
	 * Runs before output so the trust cookie can be cleared and a failed nonce check can
	 * stop the request cleanly. LoginSecurityPage checks the nonce and, where needed,
	 * the password, and acts on the current user only.
	 *
	 * @return void
	 */
	public static function handle_request(): void {
		if ( ! is_user_logged_in() || ! self::is_tab_request() ) {
			return;
		}

		LoginSecurityPage::handle_request();
	}

	/**
	 * Render the tab content.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		LoginSecurityPage::render( LoginSecurityPage::CONTEXT_ACCOUNT );
	}

	/**
	 * Load the tab styles and scripts.
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		if ( ! is_user_logged_in() || ! self::is_tab_request() ) {
			return;
		}

		wp_enqueue_style(
			'brain-2fa-account',
			BRAIN_2FA_PLUGIN_URL . 'assets/css/account.css',
			array(),
			BRAIN_2FA_VERSION
		);

		$asset_file = BRAIN_2FA_PLUGIN_DIR . 'assets/js/account.asset.php';
		$asset_data = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => BRAIN_2FA_VERSION,
		);

		wp_enqueue_script(
			'brain-2fa-account',
			BRAIN_2FA_PLUGIN_URL . 'assets/js/account.js',
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);

		wp_set_script_translations( 'brain-2fa-account', 'brain-2fa', BRAIN_2FA_PLUGIN_DIR . 'languages' );

		if ( PasskeyLogin::is_enabled() ) {
			Assets::enqueue_passkey_manager();
		}
	}
}
