<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Mailer
 *
 * Builds and sends the plugin's emails from their templates.
 *
 * @package Brain_2FA\Emails
 * @since 1.1.0
 */

namespace Brain_2FA\Emails;

use Brain_2FA\Auth\EnrollmentGuard;
use WP_User;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces placeholders and sends plugin emails as plain text.
 *
 * @since 1.1.0
 */
class Mailer {

	/**
	 * Send an email to a user.
	 *
	 * @param string  $id      Email ID from EmailRegistry::get_definitions().
	 * @param WP_User $user    Recipient.
	 * @param array   $context Values for email-specific placeholders, such as code or passkey_name.
	 * @return bool True when wp_mail() accepted the email.
	 */
	public static function send( string $id, WP_User $user, array $context = array() ): bool {
		if ( ! is_email( $user->user_email ) || ! EmailRegistry::is_enabled( $id ) ) {
			return false;
		}

		return self::deliver( $id, $user, self::render( $id, $user, $context ), $context );
	}

	/**
	 * Send an email to a user with a given subject and body, such as a test email.
	 *
	 * @param string  $id      Email ID.
	 * @param WP_User $user    Recipient.
	 * @param array   $email   Rendered email: subject and body.
	 * @param array   $context Placeholder context, passed to filters.
	 * @return bool True when wp_mail() accepted the email.
	 */
	public static function deliver( string $id, WP_User $user, array $email, array $context = array() ): bool {
		/**
		 * Filters a plugin email just before it is sent.
		 *
		 * @since 1.1.0
		 *
		 * @param array   $email   Subject and body (plain text).
		 * @param string  $id      Email ID.
		 * @param WP_User $user    Recipient.
		 * @param array   $context Placeholder context.
		 */
		$email   = (array) apply_filters( 'brain2fa_email_content', $email, $id, $user, $context );
		$subject = self::single_line( (string) ( $email['subject'] ?? '' ) );
		$body    = (string) ( $email['body'] ?? '' );

		if ( '' === $subject || '' === $body ) {
			return false;
		}

		$sender       = EmailRegistry::get_stored();
		$content_type = static function () {
			return 'text/plain';
		};
		$from_address = static function ( $address ) use ( $sender ) {
			return is_email( $sender['from_address'] ) ? $sender['from_address'] : $address;
		};
		$from_name    = static function ( $name ) use ( $sender ) {
			return '' !== $sender['from_name'] ? $sender['from_name'] : $name;
		};

		// Scoped to this email only, so other plugins' emails are unaffected.
		add_filter( 'wp_mail_content_type', $content_type, 999 );
		add_filter( 'wp_mail_from', $from_address, 999 );
		add_filter( 'wp_mail_from_name', $from_name, 999 );

		$sent = wp_mail( $user->user_email, $subject, $body );

		remove_filter( 'wp_mail_content_type', $content_type, 999 );
		remove_filter( 'wp_mail_from', $from_address, 999 );
		remove_filter( 'wp_mail_from_name', $from_name, 999 );

		return $sent;
	}

	/**
	 * Build an email's subject and body for a user.
	 *
	 * @param string     $id       Email ID.
	 * @param WP_User    $user     Recipient.
	 * @param array      $context  Values for email-specific placeholders.
	 * @param array|null $template Subject and body to use instead of the saved template (for previews).
	 * @return array{subject: string, body: string} Rendered email.
	 */
	public static function render( string $id, WP_User $user, array $context = array(), ?array $template = null ): array {
		$template = $template ?? EmailRegistry::get_template( $id ) ?? array(
			'subject' => '',
			'body'    => '',
		);
		$values   = self::get_placeholder_values( $id, $user, $context );

		return array(
			'subject' => self::single_line( strtr( (string) $template['subject'], $values ) ),
			'body'    => strtr( (string) $template['body'], $values ),
		);
	}

	/**
	 * Get the placeholder replacements for one email.
	 *
	 * @param string  $id      Email ID.
	 * @param WP_User $user    Recipient.
	 * @param array   $context Values for email-specific placeholders.
	 * @return array<string, string> Replacements keyed by placeholder, braces included.
	 */
	public static function get_placeholder_values( string $id, WP_User $user, array $context = array() ): array {
		$first_name = (string) get_user_meta( $user->ID, 'first_name', true );

		$values = array(
			'user_display_name'  => $user->display_name,
			'user_first_name'    => '' !== $first_name ? $first_name : $user->display_name,
			'user_login'         => $user->user_login,
			'user_email'         => $user->user_email,
			'site_name'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'           => home_url( '/' ),
			'login_url'          => wp_login_url(),
			'login_security_url' => EnrollmentGuard::get_setup_url( $user ),
			'date_time'          => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		);

		foreach ( array_keys( EmailRegistry::get_extra_placeholders() ) as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$values[ $key ] = (string) $context[ $key ];
			}
		}

		/**
		 * Filters the placeholder values for a plugin email.
		 *
		 * Keys are placeholder names without braces. Add a key to support a new placeholder.
		 *
		 * @since 1.1.0
		 *
		 * @param array<string, string> $values  Placeholder values.
		 * @param string                $id      Email ID.
		 * @param WP_User               $user    Recipient.
		 * @param array                 $context Placeholder context.
		 */
		$values = (array) apply_filters( 'brain2fa_email_placeholders', $values, $id, $user, $context );

		$replacements = array();
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) && is_scalar( $value ) ) {
				$replacements[ '{' . $key . '}' ] = (string) $value;
			}
		}

		return $replacements;
	}

	/**
	 * Remove line breaks so a value is safe to use as an email header.
	 *
	 * @param string $value Text.
	 * @return string Single-line text.
	 */
	private static function single_line( string $value ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', $value ) );
	}
}
