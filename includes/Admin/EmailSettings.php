<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Email settings
 *
 * The Emails tab of Brain 2FA > Settings: the email list, sender settings and the template editor.
 *
 * @package Brain_2FA\Admin
 * @since 1.1.0
 */

namespace Brain_2FA\Admin;

use Brain_2FA\Emails\EmailRegistry;
use Brain_2FA\Emails\Mailer;
use Brain_2FA\Utils;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Handles and renders the Emails settings tab.
 *
 * Form submissions are handled on the page's load hook, before output: a successful
 * action redirects back with a notice, and a failed one renders the form again with
 * the submitted values and the error beside the field.
 *
 * @since 1.1.0
 */
class EmailSettings {

	/**
	 * Nonce action shared by the Emails tab forms.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'brain2fa_email_settings';

	/**
	 * Validation errors from the current request.
	 *
	 * @var WP_Error|null
	 */
	private static $errors = null;

	/**
	 * Submitted values to show again after a failed save.
	 *
	 * @var array
	 */
	private static $draft = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'load-toplevel_page_brain-2fa-settings', array( __CLASS__, 'handle_post' ) );
	}

	/**
	 * Handle an Emails tab form submission.
	 *
	 * @return void
	 */
	public static function handle_post(): void {
		if ( ! isset( $_POST['brain2fa_email_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, 'brain2fa_email_nonce' );

		$action = sanitize_key( wp_unslash( $_POST['brain2fa_email_action'] ) );
		$id     = isset( $_POST['brain2fa_email_id'] ) ? sanitize_key( wp_unslash( $_POST['brain2fa_email_id'] ) ) : '';

		if ( 'save_sender' === $action ) {
			$name    = isset( $_POST['brain2fa_from_name'] ) ? sanitize_text_field( wp_unslash( $_POST['brain2fa_from_name'] ) ) : '';
			$address = isset( $_POST['brain2fa_from_address'] ) ? sanitize_text_field( wp_unslash( $_POST['brain2fa_from_address'] ) ) : '';
			$result  = EmailRegistry::save_sender( $name, $address );

			if ( is_wp_error( $result ) ) {
				self::$errors = $result;
				self::$draft  = array(
					'from_name'    => $name,
					'from_address' => $address,
				);
				return;
			}

			self::redirect( self::get_list_url(), 'sender_saved' );
		}

		if ( ! EmailRegistry::exists( $id ) ) {
			return;
		}

		switch ( $action ) {
			case 'save_template':
				$enabled = ! empty( $_POST['brain2fa_email_enabled'] );
				$subject = isset( $_POST['brain2fa_email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['brain2fa_email_subject'] ) ) : '';
				$body    = isset( $_POST['brain2fa_email_body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['brain2fa_email_body'] ) ) : '';
				$result  = EmailRegistry::save_template( $id, $enabled, $subject, $body );

				if ( is_wp_error( $result ) ) {
					self::$errors = $result;
					self::$draft  = array(
						'enabled' => $enabled,
						'subject' => $subject,
						'body'    => $body,
					);
					return;
				}

				self::redirect( self::get_edit_url( $id ), 'saved' );
				break;

			case 'reset_template':
				EmailRegistry::reset_template( $id );
				self::redirect( self::get_edit_url( $id ), 'reset' );
				break;

			case 'send_test':
				$user = wp_get_current_user();
				$sent = Mailer::deliver( $id, $user, Mailer::render( $id, $user, self::get_sample_context() ), self::get_sample_context() );
				self::redirect( self::get_edit_url( $id ), $sent ? 'test_sent' : 'test_failed' );
				break;
		}
	}

	/**
	 * Render the Emails tab: the list, or the editor for one email.
	 *
	 * @return void
	 */
	public static function render_tab(): void {
		$email_id = isset( $_GET['email'] ) && is_string( $_GET['email'] ) ? sanitize_key( wp_unslash( $_GET['email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation; the value is checked against the email definitions.

		self::render_notice();

		if ( EmailRegistry::exists( $email_id ) ) {
			$definition   = EmailRegistry::get_definitions()[ $email_id ];
			$template     = EmailRegistry::get_template( $email_id );
			$placeholders = EmailRegistry::get_placeholders_for( $email_id );
			$values       = Mailer::get_placeholder_values( $email_id, wp_get_current_user(), self::get_sample_context() );
			$draft        = self::$draft;
			$current      = array(
				'enabled' => $draft['enabled'] ?? $template['enabled'],
				'subject' => $draft['subject'] ?? $template['subject'],
				'body'    => $draft['body'] ?? $template['body'],
			);
			$preview      = Mailer::render( $email_id, wp_get_current_user(), self::get_sample_context(), $current );

			include BRAIN_2FA_PLUGIN_DIR . 'includes/Admin/views/settings/email-edit.php';
			return;
		}

		$definitions = EmailRegistry::get_definitions();
		$groups      = EmailRegistry::get_groups();
		$stored      = EmailRegistry::get_stored();
		$sender      = array(
			'from_name'    => self::$draft['from_name'] ?? $stored['from_name'],
			'from_address' => self::$draft['from_address'] ?? $stored['from_address'],
		);

		include BRAIN_2FA_PLUGIN_DIR . 'includes/Admin/views/settings/tab-emails.php';
	}

	/**
	 * Print the errors for one form field.
	 *
	 * @param string $field Field key: subject, body or from_address.
	 * @return void
	 */
	public static function render_field_errors( string $field ): void {
		if ( ! self::$errors ) {
			return;
		}

		foreach ( self::$errors->get_error_messages( $field ) as $message ) {
			printf( '<p class="brain2fa-field-error" id="brain2fa-error-%1$s">%2$s</p>', esc_attr( $field ), esc_html( $message ) );
		}
	}

	/**
	 * Check whether a field has an error, for aria-invalid.
	 *
	 * @param string $field Field key.
	 * @return bool True when the field failed validation.
	 */
	public static function has_field_error( string $field ): bool {
		return self::$errors && self::$errors->get_error_messages( $field );
	}

	/**
	 * Print the form fields every Emails tab form needs.
	 *
	 * @param string $action Form action.
	 * @param string $id     Email ID, or an empty string.
	 * @return void
	 */
	public static function render_form_fields( string $action, string $id = '' ): void {
		wp_nonce_field( self::NONCE_ACTION, 'brain2fa_email_nonce' );
		printf( '<input type="hidden" name="brain2fa_email_action" value="%s">', esc_attr( $action ) );
		if ( '' !== $id ) {
			printf( '<input type="hidden" name="brain2fa_email_id" value="%s">', esc_attr( $id ) );
		}
	}

	/**
	 * Get the URL of the email list.
	 *
	 * @return string Admin URL.
	 */
	public static function get_list_url(): string {
		return Settings::get_tab_url( 'emails' );
	}

	/**
	 * Get the URL of one email's editor.
	 *
	 * @param string $id Email ID.
	 * @return string Admin URL.
	 */
	public static function get_edit_url( string $id ): string {
		return add_query_arg( 'email', $id, self::get_list_url() );
	}

	/**
	 * Sample values for previews and test emails.
	 *
	 * @return array Placeholder context.
	 */
	private static function get_sample_context(): array {
		return array(
			'code'           => '123456',
			'expiry_minutes' => absint( Utils::get_settings()['code_expiry'] ),
			'passkey_name'   => __( 'MacBook Touch ID', 'brain-2fa' ),
			'setup_deadline' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() + 7 * DAY_IN_SECONDS ),
			'days_remaining' => 7,
		);
	}

	/**
	 * Show the result of the last redirected action.
	 *
	 * @return void
	 */
	private static function render_notice(): void {
		$notice   = isset( $_GET['brain2fa_email_notice'] ) && is_string( $_GET['brain2fa_email_notice'] ) ? sanitize_key( wp_unslash( $_GET['brain2fa_email_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only selects a fixed notice text.
		$messages = array(
			'saved'        => array( 'success', __( 'Email saved.', 'brain-2fa' ) ),
			'reset'        => array( 'success', __( 'The email was reset to its default text.', 'brain-2fa' ) ),
			'sender_saved' => array( 'success', __( 'Sender saved.', 'brain-2fa' ) ),
			/* translators: %s: email address */
			'test_sent'    => array( 'success', sprintf( __( 'A test email was sent to %s.', 'brain-2fa' ), wp_get_current_user()->user_email ) ),
			'test_failed'  => array( 'error', __( 'The test email could not be sent. Check that this site can send email, for example with an SMTP plugin.', 'brain-2fa' ) ),
		);

		if ( self::$errors ) {
			printf( '<div class="notice notice-error inline" role="alert"><p>%s</p></div>', esc_html__( 'The changes were not saved. Correct the highlighted fields and try again.', 'brain-2fa' ) );
		} elseif ( isset( $messages[ $notice ] ) ) {
			printf( '<div class="notice notice-%1$s inline is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $notice ][0] ), esc_html( $messages[ $notice ][1] ) );
		}
	}

	/**
	 * Redirect after a successful action, and stop.
	 *
	 * @param string $url    Destination.
	 * @param string $notice Notice key.
	 * @return void
	 */
	private static function redirect( string $url, string $notice ): void {
		wp_safe_redirect( add_query_arg( 'brain2fa_email_notice', $notice, $url ) );
		exit;
	}
}
