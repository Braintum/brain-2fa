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
			__( 'Brain 2FA Settings', 'brain2fa' ),
			__( 'Brain 2FA', 'brain2fa' ),
			'manage_options',
			'brain-2fa-settings',
			array( $this, 'render_settings_page' ),
			'dashicons-shield-alt',
			80
		);

		add_submenu_page(
			'brain-2fa-settings',
			__( '2FA Settings', 'brain2fa' ),
			__( 'Settings', 'brain2fa' ),
			'manage_options',
			'brain-2fa-settings'
		);

		add_submenu_page(
			'brain-2fa-settings',
			__( 'Two-Factor Authentication', 'brain2fa' ),
			__( 'Login Security', 'brain2fa' ),
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

		// General Settings Section.
		add_settings_section(
			'brain2fa_general_section',
			__( 'General Settings', 'brain2fa' ),
			array( $this, 'general_section_callback' ),
			'brain-2fa-settings'
		);

		add_settings_field(
			'enable_2fa',
			__( 'Enable Two-Factor Authentication', 'brain2fa' ),
			array( $this, 'enable_2fa_callback' ),
			'brain-2fa-settings',
			'brain2fa_general_section'
		);

		add_settings_field(
			'force_2fa',
			__( 'Force 2FA for Roles', 'brain2fa' ),
			array( $this, 'force_2fa_callback' ),
			'brain-2fa-settings',
			'brain2fa_general_section'
		);

		add_settings_field(
			'default_method',
			__( 'Default Authentication Method', 'brain2fa' ),
			array( $this, 'default_method_callback' ),
			'brain-2fa-settings',
			'brain2fa_general_section'
		);

		// Method Settings Section.
		add_settings_section(
			'brain2fa_methods_section',
			__( 'Authentication Methods', 'brain2fa' ),
			array( $this, 'methods_section_callback' ),
			'brain-2fa-settings'
		);

		add_settings_field(
			'enable_totp',
			__( 'Enable TOTP (Authenticator App)', 'brain2fa' ),
			array( $this, 'enable_totp_callback' ),
			'brain-2fa-settings',
			'brain2fa_methods_section'
		);

		add_settings_field(
			'enable_email',
			__( 'Enable Email Authentication', 'brain2fa' ),
			array( $this, 'enable_email_callback' ),
			'brain-2fa-settings',
			'brain2fa_methods_section'
		);

		// Advanced Settings Section.
		add_settings_section(
			'brain2fa_advanced_section',
			__( 'Advanced Settings', 'brain2fa' ),
			array( $this, 'advanced_section_callback' ),
			'brain-2fa-settings'
		);

		add_settings_field(
			'code_expiry',
			__( 'Email Code Expiry (minutes)', 'brain2fa' ),
			array( $this, 'code_expiry_callback' ),
			'brain-2fa-settings',
			'brain2fa_advanced_section'
		);

		add_settings_field(
			'remember_device',
			__( 'Remember Device', 'brain2fa' ),
			array( $this, 'remember_device_callback' ),
			'brain-2fa-settings',
			'brain2fa_advanced_section'
		);
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array $input Raw input data.
	 * @return array Sanitized settings.
	 */
	public function sanitize_settings( $input ): array {
		$sanitized = array();

		$sanitized['enable_2fa']        = ! empty( $input['enable_2fa'] );
		$valid_roles                     = array_keys( wp_roles()->roles );
		$raw_roles                       = isset( $input['force_2fa_roles'] ) && is_array( $input['force_2fa_roles'] ) ? $input['force_2fa_roles'] : array();
		$sanitized['force_2fa_roles']   = array_values( array_filter( $raw_roles, fn( $r ) => in_array( $r, $valid_roles, true ) ) );
		$sanitized['default_method']    = in_array( $input['default_method'] ?? 'totp', array( 'totp', 'email_backup' ), true ) ? $input['default_method'] : 'totp';
		$sanitized['enable_totp']       = ! empty( $input['enable_totp'] );
		$sanitized['enable_email']      = ! empty( $input['enable_email'] );
		$sanitized['code_expiry']       = absint( $input['code_expiry'] ?? 10 );
		$sanitized['remember_device']   = ! empty( $input['remember_device'] );
		$sanitized['remember_duration'] = absint( $input['remember_duration'] ?? 30 );

		return $sanitized;
	}

	/**
	 * Section callbacks.
	 */
	public function general_section_callback(): void {
		echo '<p>' . esc_html__( 'Configure the basic two-factor authentication settings.', 'brain2fa' ) . '</p>';
	}

	/**
	 * Section callbacks.
	 */
	public function methods_section_callback(): void {
		echo '<p>' . esc_html__( 'Enable or disable specific authentication methods.', 'brain2fa' ) . '</p>';
	}

	/**
	 * Section callbacks.
	 */
	public function advanced_section_callback(): void {
		echo '<p>' . esc_html__( 'Advanced configuration options for two-factor authentication.', 'brain2fa' ) . '</p>';
	}

	/**
	 * Field callbacks.
	 */
	public function enable_2fa_callback(): void {
		$options = get_option( 'brain2fa_settings', array() );
		$checked = ! empty( $options['enable_2fa'] );
		?>
		<label>
			<input type="checkbox" name="brain2fa_settings[enable_2fa]" value="1" <?php checked( $checked ); ?>>
			<?php esc_html_e( 'Enable two-factor authentication for the site', 'brain2fa' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Master switch to enable/disable 2FA functionality site-wide.', 'brain2fa' ); ?>
		</p>
		<?php
	}

	/**
	 * Field callbacks.
	 */
	public function force_2fa_callback(): void {
		$options        = get_option( 'brain2fa_settings', array() );
		$selected_roles = $options['force_2fa_roles'] ?? array();
		$roles          = wp_roles()->roles;
		?>
		<fieldset>
			<?php foreach ( $roles as $role_key => $role_data ) : ?>
				<label style="display: block; margin-bottom: 5px;">
					<input type="checkbox" 
						name="brain2fa_settings[force_2fa_roles][]" 
						value="<?php echo esc_attr( $role_key ); ?>"
						<?php checked( in_array( $role_key, $selected_roles, true ) ); ?>>
					<?php echo esc_html( $role_data['name'] ); ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
		<p class="description">
			<?php esc_html_e( 'Select user roles that are required to use 2FA.', 'brain2fa' ); ?>
		</p>
		<?php
	}

	/**
	 * Field callbacks.
	 */
	public function default_method_callback(): void {
		$options = get_option( 'brain2fa_settings', array() );
		$method  = $options['default_method'] ?? 'totp';
		?>
		<select name="brain2fa_settings[default_method]">
			<option value="totp" <?php selected( $method, 'totp' ); ?>>
				<?php esc_html_e( 'TOTP (Authenticator App)', 'brain2fa' ); ?>
			</option>
			<option value="email_backup" <?php selected( $method, 'email_backup' ); ?>>
				<?php esc_html_e( 'Email Backup', 'brain2fa' ); ?>
			</option>
		</select>
		<p class="description">
			<?php esc_html_e( 'Default authentication method for new users.', 'brain2fa' ); ?>
		</p>
		<?php
	}

	/**
	 * Field callbacks.
	 */
	public function enable_totp_callback(): void {
		$options = get_option( 'brain2fa_settings', array() );
		$checked = $options['enable_totp'] ?? true;
		?>
		<label>
			<input type="checkbox" name="brain2fa_settings[enable_totp]" value="1" <?php checked( $checked ); ?>>
			<?php esc_html_e( 'Allow users to use TOTP authenticator apps (Google Authenticator, Authy, etc.)', 'brain2fa' ); ?>
		</label>
		<?php
	}

	/**
	 * Field callbacks.
	 */
	public function enable_email_callback(): void {
		$options = get_option( 'brain2fa_settings', array() );
		$checked = $options['enable_email'] ?? true;
		?>
		<label>
			<input type="checkbox" name="brain2fa_settings[enable_email]" value="1" <?php checked( $checked ); ?>>
			<?php esc_html_e( 'Allow users to receive authentication codes via email', 'brain2fa' ); ?>
		</label>
		<?php
	}

	/**
	 * Field callbacks.
	 */
	public function code_expiry_callback(): void {
		$options = get_option( 'brain2fa_settings', array() );
		$expiry  = $options['code_expiry'] ?? 10;
		?>
		<input type="number" 
			name="brain2fa_settings[code_expiry]" 
			value="<?php echo esc_attr( $expiry ); ?>" 
			min="1" 
			max="60" 
			step="1">
		<p class="description">
			<?php esc_html_e( 'How long email codes remain valid (in minutes).', 'brain2fa' ); ?>
		</p>
		<?php
	}

	/**
	 * Field callbacks.
	 */
	public function remember_device_callback(): void {
		$options  = get_option( 'brain2fa_settings', array() );
		$checked  = $options['remember_device'] ?? false;
		$duration = $options['remember_duration'] ?? 30;
		?>
		<label>
			<input type="checkbox" name="brain2fa_settings[remember_device]" value="1" <?php checked( $checked ); ?>>
			<?php esc_html_e( 'Allow users to remember their device', 'brain2fa' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Users can skip 2FA on trusted devices.', 'brain2fa' ); ?>
		</p>
		<div style="margin-top: 10px;">
			<label>
				<?php esc_html_e( 'Remember for (days):', 'brain2fa' ); ?>
				<input type="number" 
					name="brain2fa_settings[remember_duration]" 
					value="<?php echo esc_attr( $duration ); ?>" 
					min="1" 
					max="365" 
					step="1">
			</label>
		</div>
		<?php
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

		// Check if settings were saved.
		if ( isset( $_GET['settings-updated'] ) ) {
			add_settings_error(
				'brain2fa_messages',
				'brain2fa_message',
				__( 'Settings saved successfully.', 'brain2fa' ),
				'updated'
			);
		}

		include BRAIN_2FA_PLUGIN_DIR . 'includes/Admin/views/settings-page.php';
	}

	/**
	 * Render two factor page.
	 *
	 * @return void
	 */
	public function render_login_security_page(): void {
		if ( ! current_user_can( 'read' ) ) {
			return;
		}

		// Get current user.
		$current_user = wp_get_current_user();

		// Check if 2FA is enabled for current user.
		$is_2fa_enabled = get_user_meta( $current_user->ID, 'brain2fa_enabled', true );
		$current_method = get_user_meta( $current_user->ID, 'brain2fa_method', true );

		// Get TOTP method instance.
		$totp_method = brain_2fa()->manager->get_method( 'totp' );

		// Get fresh recovery codes after regeneration.
		$fresh_recovery_codes = array();

		// Handle form submission.
		if ( isset( $_POST['brain2fa_action'] ) && check_admin_referer( 'brain2fa_setup_action', 'brain2fa_setup_nonce' ) ) {
			$action = sanitize_text_field( wp_unslash( $_POST['brain2fa_action'] ) );

			if ( 'activate' === $action && $totp_method ) {
				$data = array(
					'secret' => isset( $_POST['brain2fa_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['brain2fa_secret'] ) ) : '',
					'code'   => isset( $_POST['brain2fa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['brain2fa_code'] ) ) : '',
				);

				$result = $totp_method->save_setup( $current_user, $data );

				if ( true === $result || ( is_array( $result ) && ! empty( $result['success'] ) ) ) {
					update_user_meta( $current_user->ID, 'brain2fa_method', 'totp' );
					$is_2fa_enabled = true;
					$current_method = 'totp';

					// Capture fresh recovery codes if they were generated.
					if ( is_array( $result ) && ! empty( $result['recovery_codes'] ) ) {
						$fresh_recovery_codes = $result['recovery_codes'];
						echo '<div class="notice notice-success"><p>' . esc_html__( 'Two-Factor Authentication has been activated successfully! Please save your recovery codes below.', 'brain2fa' ) . '</p></div>';
					} else {
						echo '<div class="notice notice-success"><p>' . esc_html__( 'Two-Factor Authentication has been activated successfully!', 'brain2fa' ) . '</p></div>';
					}
				} elseif ( is_wp_error( $result ) ) {
					echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>'; // phpcs:ignore
				}
			} elseif ( 'deactivate' === $action && $totp_method ) {
				$deactivate_data = array( 'brain2fa_deactivate' => '1' );
				$totp_method->save_setup( $current_user, $deactivate_data );
				delete_user_meta( $current_user->ID, 'brain2fa_method' );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Two-Factor Authentication has been deactivated.', 'brain2fa' ) . '</p></div>';
				$is_2fa_enabled = false;
				$current_method = '';
			} elseif ( 'regenerate_recovery_codes' === $action && $totp_method && $is_2fa_enabled ) {
				$fresh_recovery_codes = $totp_method->generate_recovery_codes( $current_user );
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Recovery codes have been regenerated. Please save them securely.', 'brain2fa' ) . '</p></div>';
			}
		}

		// Get setup data if not enabled.
		$setup_data = array();
		if ( ! $is_2fa_enabled && $totp_method ) {
			$setup_data = $totp_method->get_setup_data( $current_user );
		}

		// Get recovery codes count.
		$recovery_codes_count = $is_2fa_enabled && $totp_method ? $totp_method->get_recovery_codes_count( $current_user ) : 0;

		include BRAIN_2FA_PLUGIN_DIR . 'includes/Admin/views/login-security-page.php';
	}
}
