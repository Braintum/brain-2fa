<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Passkey storage
 *
 * @package Brain_2FA\Passkeys
 * @since 1.1.0
 */

namespace Brain_2FA\Passkeys;

defined( 'ABSPATH' ) || exit;

// Every query here targets the plugin's own table, which the WordPress object cache does not cover.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Passkey Repository
 *
 * Stores passkeys (WebAuthn credentials) in the brain2fa_passkeys table and
 * each user's random WebAuthn user handle in user meta.
 *
 * Credential IDs are base64url strings and can be up to 1,364 characters, so
 * lookups use the SHA-256 hash of the ID, which carries the unique index.
 *
 * @since 1.1.0
 */
class PasskeyRepository {

	/**
	 * User meta key for the WebAuthn user handle (base64url of 32 random bytes).
	 *
	 * @var string
	 */
	private const USER_HANDLE_META_KEY = 'brain2fa_webauthn_user_handle';

	/**
	 * Per-request passkey counts, keyed by user ID.
	 *
	 * Enrollment checks run on every page load for signed-in users, so counts are cached.
	 *
	 * @var array<int,int>
	 */
	private static $count_cache = array();

	/**
	 * Register cleanup hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'deleted_user', array( __CLASS__, 'delete_all_for_user' ) );
	}

	/**
	 * Get the passkeys table name.
	 *
	 * @return string Table name with the site prefix.
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'brain2fa_passkeys';
	}

	/**
	 * Get a user's WebAuthn user handle, optionally creating it.
	 *
	 * The handle is random and never derived from the user ID, so it reveals
	 * nothing about the account.
	 *
	 * @param int  $user_id User ID.
	 * @param bool $create  Create the handle when the user has none.
	 * @return string Binary handle, or an empty string when none exists.
	 */
	public static function get_user_handle( int $user_id, bool $create = false ): string {
		$stored = get_user_meta( $user_id, self::USER_HANDLE_META_KEY, true );

		if ( is_string( $stored ) && '' !== $stored ) {
			return WebAuthnServer::base64url_decode( $stored );
		}

		if ( ! $create ) {
			return '';
		}

		$handle = random_bytes( 32 );
		update_user_meta( $user_id, self::USER_HANDLE_META_KEY, WebAuthnServer::base64url_encode( $handle ) );

		return $handle;
	}

	/**
	 * Find a passkey by its credential ID.
	 *
	 * @param string $credential_id Base64url credential ID.
	 * @return object|null Passkey row, or null when unknown.
	 */
	public static function find_by_credential_id( string $credential_id ): ?object {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE credential_hash = %s', self::table_name(), hash( 'sha256', $credential_id ) )
		);

		return $row && hash_equals( (string) $row->credential_id, $credential_id ) ? $row : null;
	}

	/**
	 * Get all passkeys for a user, newest first.
	 *
	 * @param int $user_id User ID.
	 * @return object[] Passkey rows.
	 */
	public static function get_for_user( int $user_id ): array {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY created_at DESC, id DESC', self::table_name(), $user_id )
		);
	}

	/**
	 * Count a user's passkeys.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of passkeys.
	 */
	public static function count_for_user( int $user_id ): int {
		global $wpdb;

		if ( ! isset( self::$count_cache[ $user_id ] ) ) {
			self::$count_cache[ $user_id ] = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', self::table_name(), $user_id )
			);
		}

		return self::$count_cache[ $user_id ];
	}

	/**
	 * Store a new passkey.
	 *
	 * @param int   $user_id User ID.
	 * @param array $data    Verified credential data from WebAuthnServer::verify_registration(), plus 'name'.
	 * @return int New passkey ID, or 0 on failure.
	 */
	public static function add( int $user_id, array $data ): int {
		global $wpdb;

		unset( self::$count_cache[ $user_id ] );

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'user_id'         => $user_id,
				'credential_hash' => hash( 'sha256', $data['credential_id'] ),
				'credential_id'   => $data['credential_id'],
				'public_key'      => $data['public_key'],
				'sign_count'      => (int) $data['sign_count'],
				'transports'      => implode( ',', $data['transports'] ),
				'aaguid'          => $data['aaguid'],
				'backup_eligible' => $data['backup_eligible'] ? 1 : 0,
				'backed_up'       => $data['backed_up'] ? 1 : 0,
				'name'            => $data['name'],
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Record a successful sign-in with a passkey.
	 *
	 * @param int  $id         Passkey ID.
	 * @param int  $sign_count New signature counter.
	 * @param bool $backed_up  Whether the authenticator reports the passkey as synced.
	 * @return void
	 */
	public static function record_use( int $id, int $sign_count, bool $backed_up ): void {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array(
				'sign_count'   => $sign_count,
				'backed_up'    => $backed_up ? 1 : 0,
				'last_used_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Rename one of a user's passkeys.
	 *
	 * @param int    $id      Passkey ID.
	 * @param int    $user_id Owner's user ID.
	 * @param string $name    New name, already sanitized.
	 * @return bool True when the passkey was renamed.
	 */
	public static function rename( int $id, int $user_id, string $name ): bool {
		global $wpdb;

		return (bool) $wpdb->update(
			self::table_name(),
			array( 'name' => $name ),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Delete one of a user's passkeys.
	 *
	 * @param int $id      Passkey ID.
	 * @param int $user_id Owner's user ID.
	 * @return bool True when the passkey was deleted.
	 */
	public static function delete( int $id, int $user_id ): bool {
		global $wpdb;

		unset( self::$count_cache[ $user_id ] );

		return (bool) $wpdb->delete(
			self::table_name(),
			array(
				'id'      => $id,
				'user_id' => $user_id,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Delete all of a user's passkeys.
	 *
	 * @param int $user_id User ID.
	 * @return int Number of passkeys deleted.
	 */
	public static function delete_all_for_user( $user_id ): int {
		global $wpdb;

		unset( self::$count_cache[ (int) $user_id ] );

		return (int) $wpdb->delete( self::table_name(), array( 'user_id' => (int) $user_id ), array( '%d' ) );
	}
}
