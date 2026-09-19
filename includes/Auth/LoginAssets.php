<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Login Assets Manager
 *
 * @package Brain_2FA\Auth
 * @since 1.0.0
 */
namespace Brain_2FA\Auth;

use Brain_2FA\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Login Assets class for managing login page assets.
 *
 * @since 1.0.0
 */
class LoginAssets {

	/**
	 * Initialize login assets.
	 */
	public static function init(): void {
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_woocommerce_login' ) );
	}

	/**
	 * Enqueue login assets on the WooCommerce My Account login form.
	 *
	 * @return void
	 */
	public static function enqueue_woocommerce_login(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() || is_user_logged_in() ) {
			return;
		}

		self::enqueue();
	}

	/**
	 * Enqueue login page assets.
	 */
	public static function enqueue(): void {
		$asset_file = BRAIN_2FA_PLUGIN_DIR . 'assets/js/login.asset.php';
		$asset_data = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => BRAIN_2FA_VERSION,
		);

		wp_enqueue_script(
			'brain2fa-login',
			BRAIN_2FA_PLUGIN_URL . 'assets/js/login.js',
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);

		// Set script translations.
		wp_set_script_translations(
			'brain2fa-login',
			'brain2fa',
			BRAIN_2FA_PLUGIN_DIR . 'languages'
		);

		wp_localize_script(
			'brain2fa-login',
			'Brain2FA',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'brain2fa_login' ),
				'rememberDevice' => Utils::is_remember_device_enabled(),
			)
		);
	}
}
