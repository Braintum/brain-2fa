<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Per-user lock for credential state
 *
 * @package Brain_2FA\Auth
 * @since 1.2.0
 */

namespace Brain_2FA\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Per-user lock for credential state
 *
 * Serializes read-modify-write changes to a user's single-use credentials (the last
 * accepted TOTP step and the recovery codes), so two requests carrying the same code
 * cannot both pass, and one request cannot write back a code another request removed.
 *
 * The lock is a row in the options table, created with INSERT IGNORE on its unique
 * option name, the same approach as WordPress core's upgrader lock. User meta is shared
 * by every site of a multisite network, so the lock always lives in the main options
 * table. A lock older than STALE_AFTER seconds belongs to a request that died and is
 * taken over. Locks are reentrant within a request.
 *
 * @since 1.2.0
 */
class UserLock {

	/**
	 * Option name prefix for lock rows, followed by the user ID.
	 *
	 * @var string
	 */
	public const OPTION_PREFIX = 'brain2fa_lock_';

	/**
	 * Seconds after which a lock is considered abandoned.
	 *
	 * @var int
	 */
	private const STALE_AFTER = 30;

	/**
	 * Microseconds to sleep between attempts while the lock is busy.
	 *
	 * @var int
	 */
	private const RETRY_INTERVAL = 25000;

	/**
	 * Locks held by this request: lock value and nesting depth, keyed by user ID.
	 *
	 * @var array<int, array{value: string, depth: int}>
	 */
	private static $held = array();

	/**
	 * Acquire the lock for a user, waiting while another request holds it.
	 *
	 * @param int   $user_id User ID.
	 * @param float $wait    Seconds to wait for a busy lock. Zero tries once.
	 * @return bool True when the lock is held; false when it stayed busy.
	 */
	public static function acquire( int $user_id, float $wait = 5.0 ): bool {
		global $wpdb;

		if ( isset( self::$held[ $user_id ] ) ) {
			++self::$held[ $user_id ]['depth'];
			return true;
		}

		$name     = self::OPTION_PREFIX . $user_id;
		$deadline = microtime( true ) + $wait;

		do {
			$value = time() . ':' . wp_generate_password( 12, false, false );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic insert is the lock; a cached read would defeat it.
			if ( $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i ( option_name, option_value, autoload ) VALUES ( %s, %s, 'no' )", self::get_table(), $name, $value ) ) ) {
				self::$held[ $user_id ] = array(
					'value' => $value,
					'depth' => 1,
				);
				return true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the current lock row, which must not come from a cache.
			$current = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', self::get_table(), $name ) );
			if ( '' !== $current && absint( strtok( $current, ':' ) ) < time() - self::STALE_AFTER ) {
				// Remove the abandoned lock only if it is still the same one, then try again at once.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete on the lock row.
				$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', self::get_table(), $name, $current ) );
				continue;
			}

			if ( microtime( true ) >= $deadline ) {
				return false;
			}

			usleep( self::RETRY_INTERVAL );
		} while ( true );
	}

	/**
	 * Release a lock acquired with acquire().
	 *
	 * Only the lock this request holds is removed, so a lock that was taken over after
	 * going stale is left alone.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function release( int $user_id ): void {
		global $wpdb;

		if ( ! isset( self::$held[ $user_id ] ) ) {
			return;
		}

		if ( --self::$held[ $user_id ]['depth'] > 0 ) {
			return;
		}

		$value = self::$held[ $user_id ]['value'];
		unset( self::$held[ $user_id ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete on the lock row.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', self::get_table(), self::OPTION_PREFIX . $user_id, $value ) );
	}

	/**
	 * Get the options table that holds lock rows.
	 *
	 * @return string Table name.
	 */
	private static function get_table(): string {
		global $wpdb;

		return $wpdb->base_prefix . 'options';
	}
}
