<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Assets Manager
 *
 * Handles enqueuing of admin assets (CSS and JavaScript).
 *
 * @package Brain_2FA\Admin
 * @since 1.0.0
 */

namespace Brain_2FA\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Assets class for managing admin assets.
 *
 * @since 1.0.0
 */
class Assets {

	/**
	 * Singleton instance.
	 *
	 * @var Assets|null
	 */
	protected static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Assets
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook ): void {
		// Enqueue on Brain 2FA pages and users page.
		if ( false === strpos( $hook, 'brain-2fa' ) && 'users.php' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'brain-2fa-admin',
			BRAIN_2FA_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			BRAIN_2FA_VERSION
		);

		$asset_file = BRAIN_2FA_PLUGIN_DIR . 'assets/js/admin.asset.php';
		$asset_data = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => BRAIN_2FA_VERSION,
		);

		wp_enqueue_script(
			'brain-2fa-admin',
			BRAIN_2FA_PLUGIN_URL . 'assets/js/admin.js',
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);

		// Set script translations.
		wp_set_script_translations(
			'brain-2fa-admin',
			'brain-2fa',
			BRAIN_2FA_PLUGIN_DIR . 'languages'
		);
		if ( false !== strpos( $hook, \Brain_2FA\Auth\EnrollmentGuard::SETUP_PAGE ) && \Brain_2FA\Passkeys\PasskeyLogin::is_enabled() ) {
			self::enqueue_passkey_manager();
		}
	}

	/**
	 * Enqueue the passkey registration script on the Login Security page.
	 *
	 * Also used by the WooCommerce My Account tab.
	 *
	 * @return void
	 */
	public static function enqueue_passkey_manager(): void {
		$asset_file = BRAIN_2FA_PLUGIN_DIR . 'assets/js/passkey-manage.asset.php';
		$asset_data = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => BRAIN_2FA_VERSION,
		);

		wp_enqueue_script(
			'brain2fa-passkey-manage',
			BRAIN_2FA_PLUGIN_URL . 'assets/js/passkey-manage.js',
			$asset_data['dependencies'],
			$asset_data['version'],
			true
		);

		wp_set_script_translations( 'brain2fa-passkey-manage', 'brain-2fa', BRAIN_2FA_PLUGIN_DIR . 'languages' );

		wp_localize_script(
			'brain2fa-passkey-manage',
			'Brain2FAPasskeyManager',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( \Brain_2FA\Passkeys\PasskeyRegistration::NONCE_ACTION ),
			)
		);
	}
}
