<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Email registry
 *
 * Definitions, defaults and administrator overrides for every email the plugin sends.
 *
 * @package Brain_2FA\Emails
 * @since 1.1.0
 */

namespace Brain_2FA\Emails;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Knows which emails exist, their placeholders, and their current subject and body.
 *
 * Only fields an administrator changed are stored (option `brain2fa_emails`). Untouched
 * fields always come from the translated defaults, so they follow translations and
 * future default improvements.
 *
 * @since 1.1.0
 */
class EmailRegistry {

	/**
	 * Option that stores sender settings and customized templates.
	 *
	 * @var string
	 */
	public const OPTION = 'brain2fa_emails';

	/**
	 * Longest allowed subject, in characters.
	 *
	 * @var int
	 */
	public const MAX_SUBJECT_LENGTH = 255;

	/**
	 * Longest allowed body, in characters.
	 *
	 * @var int
	 */
	public const MAX_BODY_LENGTH = 10000;

	/**
	 * Get every email definition, keyed by email ID, in display order.
	 *
	 * @return array<string, array{group: string, label: string, description: string, can_disable: bool, placeholders: string[], required: string[], subject: string, body: string}>
	 */
	public static function get_definitions(): array {
		$alert_subject = __( '[{site_name}] Your two-factor authentication settings changed', 'brain-2fa' );

		$definitions = array(
			'sign_in_code' => array(
				'group'        => 'sign_in',
				'label'        => __( 'Sign-in code', 'brain-2fa' ),
				'description'  => __( 'Sent when a user signs in with email codes, and when they confirm email code setup.', 'brain-2fa' ),
				'can_disable'  => false,
				'placeholders' => array( 'code', 'expiry_minutes' ),
				'required'     => array( 'code' ),
				'subject'      => __( '[{site_name}] Your two-factor authentication code', 'brain-2fa' ),
				'body'         => __( "Hi {user_display_name},\n\nYour two-factor authentication code is:\n\n{code}\n\nThis code expires in {expiry_minutes} minutes.\n\nIf you did not request this code, please ignore this email.\n\n-- {site_name}", 'brain-2fa' ),
			),
		);

		$alerts = array(
			'activated'                  => array(
				__( 'Two-factor turned on', 'brain-2fa' ),
				__( 'Sent when a user turns on the authenticator app or email codes.', 'brain-2fa' ),
				__( 'Two-factor authentication was turned on for your account.', 'brain-2fa' ),
			),
			'deactivated'                => array(
				__( 'Two-factor turned off', 'brain-2fa' ),
				__( 'Sent when a user turns off their code method.', 'brain-2fa' ),
				__( 'Two-factor authentication was turned off for your account.', 'brain-2fa' ),
			),
			'recovery_codes_regenerated' => array(
				__( 'Recovery codes regenerated', 'brain-2fa' ),
				__( 'Sent when a user generates new recovery codes.', 'brain-2fa' ),
				__( 'New recovery codes were generated for your account. Your previous recovery codes no longer work.', 'brain-2fa' ),
			),
			'passkey_added'              => array(
				__( 'Passkey added', 'brain-2fa' ),
				__( 'Sent when a user adds a passkey.', 'brain-2fa' ),
				__( 'A new passkey, "{passkey_name}", was added to your account. It can be used to sign in without a password.', 'brain-2fa' ),
				array( 'passkey_name' ),
			),
			'passkey_removed'            => array(
				__( 'Passkey removed', 'brain-2fa' ),
				__( 'Sent when a user removes one of their passkeys.', 'brain-2fa' ),
				__( 'The passkey "{passkey_name}" was removed from your account.', 'brain-2fa' ),
				array( 'passkey_name' ),
			),
			'passkeys_revoked'           => array(
				__( 'Passkeys removed by an administrator', 'brain-2fa' ),
				__( 'Sent when an administrator removes all of a user\'s passkeys from their profile.', 'brain-2fa' ),
				__( 'An administrator removed the passkeys from your account.', 'brain-2fa' ),
			),
			'passkey_only_enabled'       => array(
				__( 'Password sign-in turned off', 'brain-2fa' ),
				__( 'Sent when a user switches their account to passkey-only sign-in.', 'brain-2fa' ),
				__( 'Password sign-in was turned off for your account. You can now only sign in with a passkey.', 'brain-2fa' ),
			),
			'passkey_only_disabled'      => array(
				__( 'Password sign-in turned back on', 'brain-2fa' ),
				__( 'Sent when a user or an administrator turns password sign-in back on.', 'brain-2fa' ),
				__( 'Password sign-in was turned back on for your account.', 'brain-2fa' ),
			),
			'passkey_clone_warning'      => array(
				__( 'Passkey sign-in blocked', 'brain-2fa' ),
				__( 'Sent when a sign-in is blocked because a passkey may have been copied.', 'brain-2fa' ),
				__( 'A sign-in with your passkey "{passkey_name}" was blocked because the passkey may have been copied. Remove it from your account and contact the site administrator.', 'brain-2fa' ),
				array( 'passkey_name' ),
			),
		);

		foreach ( $alerts as $id => $alert ) {
			$definitions[ $id ] = array(
				'group'        => 'alerts',
				'label'        => $alert[0],
				'description'  => $alert[1],
				'can_disable'  => true,
				'placeholders' => $alert[3] ?? array(),
				'required'     => array(),
				'subject'      => 'passkey_clone_warning' === $id ? __( '[{site_name}] A passkey sign-in was blocked', 'brain-2fa' ) : $alert_subject,
				'body'         => sprintf(
					/* translators: %s: one-sentence description of the change. Keep the {placeholders} as they are. */
					__( "Hi {user_display_name},\n\n%s\n\nTime: {date_time}\n\nIf you did not make this change, reset your password immediately and contact the site administrator.\n\n-- {site_name}\n{site_url}", 'brain-2fa' ),
					$alert[2]
				),
			);
		}

		return $definitions;
	}

	/**
	 * Get the email groups, in display order.
	 *
	 * @return array<string, array{label: string, description: string}> Groups keyed by group ID.
	 */
	public static function get_groups(): array {
		return array(
			'sign_in' => array(
				'label'       => __( 'Sign-in', 'brain-2fa' ),
				'description' => __( 'Needed to sign in, so it is always sent.', 'brain-2fa' ),
			),
			'alerts'  => array(
				'label'       => __( 'Security alerts', 'brain-2fa' ),
				'description' => __( 'Tell users when the sign-in protection on their account changes, so they notice changes they did not make.', 'brain-2fa' ),
			),
		);
	}

	/**
	 * Get the placeholders every email supports, with descriptions.
	 *
	 * @return array<string, string> Descriptions keyed by placeholder name (without braces).
	 */
	public static function get_common_placeholders(): array {
		return array(
			'user_display_name'  => __( 'The user\'s display name.', 'brain-2fa' ),
			'user_first_name'    => __( 'The user\'s first name, or their display name if no first name is set.', 'brain-2fa' ),
			'user_login'         => __( 'The user\'s username.', 'brain-2fa' ),
			'user_email'         => __( 'The user\'s email address.', 'brain-2fa' ),
			'site_name'          => __( 'The site title.', 'brain-2fa' ),
			'site_url'           => __( 'The site address.', 'brain-2fa' ),
			'login_url'          => __( 'The sign-in page address.', 'brain-2fa' ),
			'login_security_url' => __( 'The address of the user\'s Login Security page.', 'brain-2fa' ),
			'date_time'          => __( 'The date and time the email was sent, in the site\'s format.', 'brain-2fa' ),
		);
	}

	/**
	 * Get descriptions for the placeholders specific to some emails.
	 *
	 * @return array<string, string> Descriptions keyed by placeholder name (without braces).
	 */
	public static function get_extra_placeholders(): array {
		return array(
			'code'           => __( 'The 6-digit sign-in code. Required in this email.', 'brain-2fa' ),
			'expiry_minutes' => __( 'How many minutes the code stays valid.', 'brain-2fa' ),
			'passkey_name'   => __( 'The name of the passkey.', 'brain-2fa' ),
		);
	}

	/**
	 * Get the placeholders one email supports, with descriptions.
	 *
	 * @param string $id Email ID.
	 * @return array<string, string> Descriptions keyed by placeholder name (without braces).
	 */
	public static function get_placeholders_for( string $id ): array {
		$definition = self::get_definitions()[ $id ] ?? null;
		if ( ! $definition ) {
			return array();
		}

		$extra = array_intersect_key( self::get_extra_placeholders(), array_flip( $definition['placeholders'] ) );

		return $extra + self::get_common_placeholders();
	}

	/**
	 * Check whether an email ID exists.
	 *
	 * @param string $id Email ID.
	 * @return bool True when the email is defined.
	 */
	public static function exists( string $id ): bool {
		return isset( self::get_definitions()[ $id ] );
	}

	/**
	 * Get the stored option, normalized.
	 *
	 * @return array{from_name: string, from_address: string, templates: array<string, array>}
	 */
	public static function get_stored(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'from_name'    => isset( $stored['from_name'] ) && is_string( $stored['from_name'] ) ? $stored['from_name'] : '',
			'from_address' => isset( $stored['from_address'] ) && is_string( $stored['from_address'] ) ? $stored['from_address'] : '',
			'templates'    => isset( $stored['templates'] ) && is_array( $stored['templates'] ) ? $stored['templates'] : array(),
		);
	}

	/**
	 * Get the current template of an email.
	 *
	 * @param string $id Email ID.
	 * @return array{enabled: bool, subject: string, body: string, customized: bool}|null Template, or null for an unknown ID.
	 */
	public static function get_template( string $id ): ?array {
		$definition = self::get_definitions()[ $id ] ?? null;
		if ( ! $definition ) {
			return null;
		}

		$custom = self::get_stored()['templates'][ $id ] ?? array();
		$custom = is_array( $custom ) ? $custom : array();

		$subject = isset( $custom['subject'] ) && is_string( $custom['subject'] ) && '' !== $custom['subject'] ? $custom['subject'] : $definition['subject'];
		$body    = isset( $custom['body'] ) && is_string( $custom['body'] ) && '' !== $custom['body'] ? $custom['body'] : $definition['body'];

		// A stored body that lost a required placeholder (edited outside the form) must not stop users signing in.
		foreach ( $definition['required'] as $placeholder ) {
			if ( false === strpos( $body, '{' . $placeholder . '}' ) ) {
				$body = $definition['body'];
				break;
			}
		}

		return array(
			'enabled'    => ! $definition['can_disable'] || ! isset( $custom['enabled'] ) || ! empty( $custom['enabled'] ),
			'subject'    => $subject,
			'body'       => $body,
			'customized' => $subject !== $definition['subject'] || $body !== $definition['body'],
		);
	}

	/**
	 * Check whether an email is turned on.
	 *
	 * @param string $id Email ID.
	 * @return bool True when the email is sent.
	 */
	public static function is_enabled( string $id ): bool {
		$template = self::get_template( $id );

		return null !== $template && $template['enabled'];
	}

	/**
	 * Validate and store an administrator's changes to an email.
	 *
	 * Fields equal to the default are not stored, so they keep following translations.
	 *
	 * @param string $id      Email ID.
	 * @param bool   $enabled Whether the email is turned on (ignored for emails that cannot be turned off).
	 * @param string $subject Raw subject, already unslashed.
	 * @param string $body    Raw body, already unslashed.
	 * @return true|WP_Error True when saved; an error keyed by field otherwise.
	 */
	public static function save_template( string $id, bool $enabled, string $subject, string $body ) {
		$definition = self::get_definitions()[ $id ] ?? null;
		if ( ! $definition ) {
			return new WP_Error( 'brain2fa_email_unknown', __( 'That email does not exist.', 'brain-2fa' ) );
		}

		$subject = self::sanitize_subject( $subject );
		$body    = self::sanitize_body( $body );
		$errors  = new WP_Error();

		if ( '' === $subject ) {
			$errors->add( 'subject', __( 'Enter a subject.', 'brain-2fa' ) );
		} elseif ( mb_strlen( $subject ) > self::MAX_SUBJECT_LENGTH ) {
			/* translators: %d: maximum number of characters */
			$errors->add( 'subject', sprintf( __( 'The subject can be at most %d characters long.', 'brain-2fa' ), self::MAX_SUBJECT_LENGTH ) );
		}

		if ( '' === $body ) {
			$errors->add( 'body', __( 'Enter the email text.', 'brain-2fa' ) );
		} elseif ( mb_strlen( $body ) > self::MAX_BODY_LENGTH ) {
			/* translators: %d: maximum number of characters */
			$errors->add( 'body', sprintf( __( 'The email text can be at most %d characters long.', 'brain-2fa' ), self::MAX_BODY_LENGTH ) );
		}

		foreach ( $definition['required'] as $placeholder ) {
			if ( false === strpos( $body, '{' . $placeholder . '}' ) ) {
				/* translators: %s: placeholder such as {code} */
				$errors->add( 'body', sprintf( __( 'The email text must contain %s. Without it, users cannot sign in.', 'brain-2fa' ), '{' . $placeholder . '}' ) );
			}
		}

		if ( $errors->has_errors() ) {
			return $errors;
		}

		$custom = array();
		if ( $subject !== $definition['subject'] ) {
			$custom['subject'] = $subject;
		}
		if ( self::normalize_line_endings( $definition['body'] ) !== $body ) {
			$custom['body'] = $body;
		}
		if ( $definition['can_disable'] && ! $enabled ) {
			$custom['enabled'] = false;
		}

		$stored = self::get_stored();
		if ( $custom ) {
			$stored['templates'][ $id ] = $custom;
		} else {
			unset( $stored['templates'][ $id ] );
		}

		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Restore an email to its default subject and body. The on/off state is kept.
	 *
	 * @param string $id Email ID.
	 * @return void
	 */
	public static function reset_template( string $id ): void {
		$stored = self::get_stored();
		if ( ! isset( $stored['templates'][ $id ] ) ) {
			return;
		}

		$enabled = $stored['templates'][ $id ]['enabled'] ?? true;
		unset( $stored['templates'][ $id ] );
		if ( false === $enabled ) {
			$stored['templates'][ $id ] = array( 'enabled' => false );
		}

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Validate and store the sender used for plugin emails.
	 *
	 * @param string $name    Raw sender name, already unslashed. Empty keeps the WordPress default.
	 * @param string $address Raw sender address, already unslashed. Empty keeps the WordPress default.
	 * @return true|WP_Error True when saved.
	 */
	public static function save_sender( string $name, string $address ) {
		$name    = mb_substr( self::sanitize_subject( $name ), 0, 100 );
		$address = trim( $address );

		// Check what was typed: sanitize_email() turns an invalid address into an empty one.
		if ( '' !== $address && ( ! is_email( $address ) || sanitize_email( $address ) !== $address ) ) {
			return new WP_Error( 'from_address', __( 'Enter a valid email address, or leave it empty.', 'brain-2fa' ) );
		}

		$stored                 = self::get_stored();
		$stored['from_name']    = $name;
		$stored['from_address'] = $address;
		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Sanitize a single-line value such as a subject: no tags, no line breaks.
	 *
	 * @param string $value Raw value.
	 * @return string Sanitized value.
	 */
	public static function sanitize_subject( string $value ): string {
		return trim( sanitize_text_field( $value ) );
	}

	/**
	 * Sanitize a plain-text email body.
	 *
	 * @param string $value Raw value.
	 * @return string Sanitized value with \n line endings.
	 */
	public static function sanitize_body( string $value ): string {
		return trim( sanitize_textarea_field( self::normalize_line_endings( $value ) ) );
	}

	/**
	 * Convert CRLF and CR line endings to LF.
	 *
	 * @param string $value Text.
	 * @return string Text with \n line endings.
	 */
	private static function normalize_line_endings( string $value ): string {
		return str_replace( array( "\r\n", "\r" ), "\n", $value );
	}
}
