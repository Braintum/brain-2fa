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

		wp_enqueue_script(
			'brain-2fa-admin',
			BRAIN_2FA_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			BRAIN_2FA_VERSION,
			true
		);
	}
}
