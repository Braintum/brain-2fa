<?php // phpcs:disable PSR1.Files.SideEffects
/**
 * Admin Settings Page
 *
 * Handles the admin settings page for Brain 2FA plugin.
 *
 * @package Brain_2FA\Admin
 * @since 1.0.0
 */

namespace Brain_2FA\Admin;

use Brain_2FA\Passkeys\PasskeyRepository;
use Brain_2FA\Passkeys\WebAuthnServer;
use Brain_2FA\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Settings class for admin interface.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Singleton instance.
	 *
	 * @var Settings|null
	 */
	protected static $instance = null;

	/**
	 * Settings keys owned by each settings tab.
	 *
	 * A tab save changes only its own keys and keeps every other stored value.
	 *
	 * @var array<string, string[]>
	 */
	public const TAB_FIELDS = array(
		'methods'         => array( 'enable_2fa', 'enable_totp', 'enable_email', 'code_expiry', 'enable_passkeys', 'allow_passkey_only', 'default_method' ),
		'requirements'    => array( 'force_2fa_roles', 'grace_period_days' ),
		'trusted-devices' => array( 'remember_device', 'remember_duration' ),
	);

	/**
	 * Get singleton instance.
	 *
	 * @return Settings
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
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Register admin menu.
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		add_menu_page(
			__( 'Brain 2FA Settings', 'brain-2fa' ),
			__( 'Brain 2FA', 'brain-2fa' ),
			'manage_options',
			'brain-2fa-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-shield-alt',
			80
		);

		add_submenu_page(
			'brain-2fa-settings',
			__( '2FA Settings', 'brain-2fa' ),
			__( 'Settings', 'brain-2fa' ),
			'manage_options',
			'brain-2fa-settings'
		);

		add_submenu_page(
			'brain-2fa-settings',
			__( 'Two-Factor Authentication', 'brain-2fa' ),
			__( 'Login Security', 'brain-2fa' ),
			'read',
			'brain-2fa-login-security',
			array( $this, 'render_login_security_page' )
		);
	}

	/**
	 * Register settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'brain2fa_settings_group',
			'brain2fa_settings',
			array( $this, 'sanitize_settings' )
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * Input carrying a `_tab` identifier is a tab save: only that tab's keys come
	 * from the input, where a missing key means an unchecked box, and all other keys
	 * keep their stored values. Input without one is treated as the full form.
	 * The result is always the complete settings array, so a second pass over it
	 * (WordPress sanitizes again when it adds a new option) changes nothing.
	 *
	 * @param array $input Raw input data.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ): array {
		$input = is_array( $input ) ? $input : array();

		if ( isset( $input['_tab'] ) ) {
			$tab = is_string( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';

			if ( ! isset( self::TAB_FIELDS[ $tab ] ) ) {
				add_settings_error(
					'brain2fa_messages',
					'brain2fa_invalid_tab',
					__( 'Settings were not saved because the settings section was not recognized.', 'brain-2fa' )
				);
				$input = Utils::get_settings();
			} else {
				$owned = array_flip( self::TAB_FIELDS[ $tab ] );
				$input = array_merge( array_diff_key( Utils::get_settings(), $owned ), array_intersect_key( $input, $owned ) );
			}
		}

		$sanitized = array();

		$sanitized['enable_2fa']         = ! empty( $input['enable_2fa'] );
		$valid_roles                     = array_keys( wp_roles()->roles );
		$raw_roles                       = isset( $input['force_2fa_roles'] ) && is_array( $input['force_2fa_roles'] ) ? $input['force_2fa_roles'] : array();
		$sanitized['force_2fa_roles']    = array_values( array_filter( $raw_roles, fn( $r ) => in_array( $r, $valid_roles, true ) ) );
		$sanitized['grace_period_days']  = min( 365, absint( $input['grace_period_days'] ?? 14 ) );
		$sanitized['enable_totp']        = ! empty( $input['enable_totp'] );
		$sanitized['enable_email']       = ! empty( $input['enable_email'] );
		$sanitized['enable_passkeys']    = ! empty( $input['enable_passkeys'] );
		$sanitized['allow_passkey_only'] = ! empty( $input['allow_passkey_only'] );
		$default_method                  = $input['default_method'] ?? 'totp';
		$default_method                  = 'email_backup' === $default_method ? 'email' : $default_method;
		if ( ! in_array( $default_method, array( 'totp', 'email' ), true ) || ( 'totp' === $default_method && ! $sanitized['enable_totp'] ) || ( 'email' === $default_method && ! $sanitized['enable_email'] ) ) {
			$default_method = $sanitized['enable_totp'] ? 'totp' : 'email';
		}
		$sanitized['default_method']    = $default_method;
		$sanitized['code_expiry']       = min( 60, max( 1, absint( $input['code_expiry'] ?? 10 ) ) );
		$sanitized['remember_device']   = ! empty( $input['remember_device'] );
		$sanitized['remember_duration'] = min( 365, max( 1, absint( $input['remember_duration'] ?? 30 ) ) );

		return $sanitized;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tabs       = self::get_tabs();
		$active_tab = self::get_active_tab();
		$settings   = Utils::get_settings();

		// Saving redirects back here; errors from the sanitizer replace the success notice.
		if ( isset( $_GET['settings-updated'] ) && ! get_settings_errors( 'brain2fa_messages' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This query parameter only controls an administrative notice.
			add_settings_error(
				'brain2fa_messages',
				'brain2fa_message',
				__( 'Settings saved.', 'brain-2fa' ),
				'updated'
			);
		}

		// Header summary: what is in effect right now, from the stored settings.
		$code_methods_enabled = Utils::is_method_enabled( 'totp' ) || Utils::is_method_enabled( 'email' );
		$passkeys_available   = WebAuthnServer::is_available();
		$role_names           = wp_roles()->get_names();
		$required_role_names  = array_map( 'translate_user_role', array_values( array_intersect_key( $role_names, array_flip( $settings['force_2fa_roles'] ) ) ) );

		// Only the Authentication Methods tab shows the passkey impact warning, so only it pays for the count.
		$passkey_only_users = 'methods' === $active_tab && ! empty( $settings['enable_passkeys'] ) ? PasskeyRepository::count_users_without_code_method() : 0;

		// Users of a code method that is turned off cannot sign in with their password, so warn before that happens.
		$method_impact = array();
		if ( 'methods' === $active_tab ) {
			$passkeys_usable = ! empty( $settings['enable_passkeys'] ) && $passkeys_available;
			foreach ( array( 'totp', 'email' ) as $method_id ) {
				$method_impact[ $method_id ] = self::get_method_impact( $method_id, ! empty( $settings[ 'enable_' . $method_id ] ), $passkeys_usable );
			}
		}

		include BRAIN_2FA_PLUGIN_DIR . 'includes/Admin/views/settings-page.php';
	}

	/**
	 * Describe what turning a code method off does to the users who rely on it.
	 *
	 * A method turned off for the site fails closed at sign-in, so its users can only
	 * sign in with a usable passkey (or from a trusted device).
	 *
	 * @param string $method_id       Code method ID: totp or email.
	 * @param bool   $enabled         Whether the method is turned on for the site.
	 * @param bool   $passkeys_usable Whether passkeys are on and served over HTTPS.
	 * @return array{blocked: int, type: string, message: string} Users who cannot sign in without the method, and a notice ('' when nobody is affected).
	 */
	private static function get_method_impact( string $method_id, bool $enabled, bool $passkeys_usable ): array {
		$users   = PasskeyRepository::count_code_method_users( $method_id, false );
		$blocked = $passkeys_usable ? PasskeyRepository::count_code_method_users( $method_id, true ) : $users;
		$impact  = array(
			'blocked' => $blocked,
			'type'    => '',
			'message' => '',
		);

		if ( 0 === $users ) {
			return $impact;
		}

		$count = number_format_i18n( $blocked );

		if ( ! $enabled && $blocked > 0 ) {
			$impact['type']    = 'error';
			$impact['message'] = 'totp' === $method_id
				/* translators: %s: number of users */
				? sprintf( _n( '%s user is set up with the authenticator app, which is turned off, and has no passkey to sign in with. They cannot sign in with their password until you turn it back on.', '%s users are set up with the authenticator app, which is turned off, and have no passkey to sign in with. They cannot sign in with their password until you turn it back on.', $blocked, 'brain-2fa' ), $count )
				/* translators: %s: number of users */
				: sprintf( _n( '%s user is set up with email codes, which are turned off, and has no passkey to sign in with. They cannot sign in with their password until you turn email codes back on.', '%s users are set up with email codes, which are turned off, and have no passkey to sign in with. They cannot sign in with their password until you turn email codes back on.', $blocked, 'brain-2fa' ), $count );
		} elseif ( $enabled && $blocked > 0 ) {
			$impact['type']    = 'warning';
			$impact['message'] = 'totp' === $method_id
				/* translators: %s: number of users */
				? sprintf( _n( '%s user relies on the authenticator app and has no passkey. If you turn it off, they cannot sign in with their password until it is turned back on. Recovery codes do not help, because they belong to this method.', '%s users rely on the authenticator app and have no passkey. If you turn it off, they cannot sign in with their password until it is turned back on. Recovery codes do not help, because they belong to this method.', $blocked, 'brain-2fa' ), $count )
				/* translators: %s: number of users */
				: sprintf( _n( '%s user relies on email codes and has no passkey. If you turn email codes off, they cannot sign in with their password until they are turned back on.', '%s users rely on email codes and have no passkey. If you turn email codes off, they cannot sign in with their password until they are turned back on.', $blocked, 'brain-2fa' ), $count );
		} elseif ( $enabled ) {
			$impact['type']    = 'info';
			$impact['message'] = 'totp' === $method_id
				/* translators: %s: number of users */
				? sprintf( _n( '%s user uses the authenticator app. They also have a passkey, so turning the app off leaves them signing in with their passkey.', '%s users use the authenticator app. They all have a passkey, so turning the app off leaves them signing in with their passkeys.', $users, 'brain-2fa' ), number_format_i18n( $users ) )
				/* translators: %s: number of users */
				: sprintf( _n( '%s user uses email codes. They also have a passkey, so turning email codes off leaves them signing in with their passkey.', '%s users use email codes. They all have a passkey, so turning email codes off leaves them signing in with their passkeys.', $users, 'brain-2fa' ), number_format_i18n( $users ) );
		}

		return $impact;
	}

	/**
	 * Get the settings tabs.
	 *
	 * @return array<string, string> Tab labels keyed by tab identifier, in display order.
	 */
	public static function get_tabs(): array {
		return array(
			'methods'         => __( 'Authentication Methods', 'brain-2fa' ),
			'requirements'    => __( 'Enrollment Requirements', 'brain-2fa' ),
			'trusted-devices' => __( 'Trusted Devices', 'brain-2fa' ),
			'emails'          => __( 'Emails', 'brain-2fa' ),
		);
	}

	/**
	 * Get the settings tab requested in the URL, or the first tab.
	 *
	 * @return string Allow-listed tab identifier.
	 */
	public static function get_active_tab(): string {
		$tab = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation; the value is checked against an allow-list.

		return isset( self::get_tabs()[ $tab ] ) ? $tab : 'methods';
	}

	/**
	 * Get the settings page URL for a tab.
	 *
	 * @param string $tab Tab identifier.
	 * @return string Admin URL.
	 */
	public static function get_tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => 'brain-2fa-settings',
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Render the Login Security page.
	 *
	 * @return void
	 */
	public function render_login_security_page(): void {
		LoginSecurityPage::render();
	}
}
