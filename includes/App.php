<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin main class setup
 *
 * @package App
 * @since 1.0.0
 */

namespace Brain_2FA;

use Brain_2FA\Admin\UserListColumns;
use Brain_2FA\Admin\Settings;
use Brain_2FA\Admin\UserProfile;
use Brain_2FA\Admin\Assets;
use Brain_2FA\Auth\LoginController;
use Brain_2FA\TwoFactor\Manager;
use Brain_2FA\TwoFactor\Methods\EmailMethod;
use Brain_2FA\TwoFactor\Methods\TotpMethod;
use Brain_2FA\Auth\LoginAssets;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin main class.
 *
 * @class App
 *
 * @since 1.0.0
 */
final class App {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public $version = BRAIN_2FA_VERSION;

	/**
	 * Plugin slug
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $slug = 'brain-2fa';

	/**
	 * REST API version
	 *
	 * @var string
	 */
	public $rest_version = 'v2';

	/**
	 * The single instance of the class.
	 *
	 * @since 1.0.0
	 * @var App
	 */
	protected static $instance = null;

	/**
	 * App Instance.
	 *
	 * Ensures only one instance of App is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 * @static
	 * @return App - Main instance.
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Two Factor Manager instance.
	 *
	 * @since 1.0.0
	 *
	 * @var Manager
	 */
	public Manager $manager;

	/**
	 * Admin Settings instance.
	 *
	 * @since 1.0.0
	 *
	 * @var Settings
	 */
	protected $admin_settings;

	/**
	 * Admin User Profile instance.
	 *
	 * @since 1.0.0
	 *
	 * @var UserProfile
	 */
	protected $admin_user_profile;

	/**
	 * Admin Assets instance.
	 *
	 * @since 1.0.0
	 *
	 * @var Assets
	 */
	protected $admin_assets;

	/**
	 * Admin User List Columns instance.
	 *
	 * @since 1.0.0
	 *
	 * @var UserListColumns
	 */
	protected $admin_user_columns;

	/**
	 * Login Handler instance.
	 *
	 * @since 1.0.0
	 *
	 * @var Auth\LoginHandler
	 */
	protected $login_handler;

	/**
	 * App Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function __construct() {
		$this->manager = Manager::instance();
		$this->init_hooks();
	}

	/**
	 * Get the correct filename suffix for minified assets.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function minified_asset_suffix() {
		$ext = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		return $ext;
	}

	/**
	 * When WP has loaded all plugins, trigger the `brain_2fa_loaded` hook.
	 *
	 * This ensures `brain_2fa_loaded` is called only after all other plugins
	 * are loaded, to avoid issues caused by plugin directory naming changing
	 * the load order.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function on_plugins_loaded() {
		$this->manager->register_method( new TotpMethod() );
		$this->manager->register_method( new EmailMethod() );

		// Initialize login handler.
		LoginController::init();
		LoginAssets::init();

		// Initialize admin classes.
		if ( is_admin() ) {
			$this->admin_settings     = Settings::instance();
			$this->admin_user_profile = UserProfile::instance();
			$this->admin_assets       = Assets::instance();
			$this->admin_user_columns = UserListColumns::instance();
		}

		do_action( 'brain_2fa_loaded' );
	}

	/**
	 * Hook into actions and filters.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'on_plugins_loaded' ), PHP_INT_MAX );
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __clone() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Cloning is forbidden.', 'brain2fa' ), '1.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __wakeup() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Unserializing instances of this class is forbidden.', 'brain2fa' ), '1.0.0' );
	}
}
