<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Enrollment report
 *
 * @package Brain_2FA\Enrollment
 * @since 1.2.0
 */

namespace Brain_2FA\Enrollment;

use Brain_2FA\Passkeys\PasskeyLogin;
use Brain_2FA\Utils;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Enrollment report
 *
 * Counts the current site's members per enrollment status and configured method,
 * optionally for one role. Every count is a WP_User_Query with the EnrollmentStatus
 * query variables, the same query the filtered Users list runs, so a count and the
 * list it links to agree. The number of queries does not grow with the number of users.
 *
 * Snapshots are cached in a transient for up to MAX_AGE seconds, never past the next
 * pending deadline, and are dropped when settings, roles, membership, methods,
 * passkeys or deadlines change.
 *
 * @since 1.2.0
 */
class EnrollmentReport {

	/**
	 * Transient holding the snapshots, keyed by role ('' for all roles).
	 *
	 * @var string
	 */
	public const CACHE_KEY = 'brain2fa_overview';

	/**
	 * Longest time a snapshot is reused, in seconds.
	 *
	 * @var int
	 */
	private const MAX_AGE = 15 * MINUTE_IN_SECONDS;

	/**
	 * User meta keys whose changes make snapshots stale.
	 *
	 * @var string[]
	 */
	private const WATCHED_META = array( 'brain2fa_enabled', 'brain2fa_method', 'brain2fa_grace_expires_at' );

	/**
	 * Register the hooks that drop stale snapshots.
	 *
	 * @return void
	 */
	public static function init(): void {
		foreach ( array( 'add_option_brain2fa_settings', 'update_option_brain2fa_settings', 'user_register', 'deleted_user', 'add_user_to_blog', 'remove_user_from_blog', 'set_user_role', 'add_user_role', 'remove_user_role', 'brain2fa_passkeys_changed' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'invalidate' ) );
		}

		foreach ( array( 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_meta_change' ), 10, 3 );
		}
	}

	/**
	 * Drop the cached snapshots.
	 *
	 * @return void
	 */
	public static function invalidate(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Drop the cached snapshots when a watched user meta value changes.
	 *
	 * @param int|int[] $meta_id  Meta ID or IDs.
	 * @param int       $user_id  User ID.
	 * @param string    $meta_key Meta key.
	 * @return void
	 */
	public static function on_meta_change( $meta_id, $user_id, $meta_key ): void {
		global $wpdb;

		if ( in_array( $meta_key, self::WATCHED_META, true ) || $wpdb->get_blog_prefix() . 'capabilities' === $meta_key ) {
			self::invalidate();
		}
	}

	/**
	 * Get a snapshot, from the cache when it is still fresh.
	 *
	 * @param string $role    Role slug, or '' for all roles.
	 * @param bool   $refresh Build a new snapshot even when a fresh one is cached.
	 * @return array{role: string, generated_at: int, stale_at: int, total: int, statuses: array<string, int>, methods: array<string, int>} Snapshot.
	 */
	public static function get_snapshot( string $role = '', bool $refresh = false ): array {
		$cache    = get_transient( self::CACHE_KEY );
		$cache    = is_array( $cache ) ? $cache : array();
		$snapshot = $cache[ $role ] ?? null;

		if ( $refresh || ! is_array( $snapshot ) || ( $snapshot['stale_at'] ?? 0 ) <= time() ) {
			$snapshot       = self::build( $role );
			$cache[ $role ] = $snapshot;
			set_transient( self::CACHE_KEY, $cache, HOUR_IN_SECONDS );
		}

		return $snapshot;
	}

	/**
	 * Get the policy facts the Overview explains next to the counts.
	 *
	 * @return array{enabled: bool, can_enforce: bool, required_roles: string[], passkeys_setting: bool, passkeys_usable: bool} Policy.
	 */
	public static function get_policy(): array {
		$roles = Utils::get_settings()['force_2fa_roles'] ?? array();

		return array(
			'enabled'          => Utils::is_2fa_enabled_sitewide(),
			'can_enforce'      => Utils::is_method_enabled( 'totp' ) || Utils::is_method_enabled( 'email' ),
			'required_roles'   => is_array( $roles ) ? array_values( array_filter( $roles, 'is_string' ) ) : array(),
			'passkeys_setting' => Utils::is_passkeys_enabled(),
			'passkeys_usable'  => PasskeyLogin::is_enabled(),
		);
	}

	/**
	 * Build a snapshot.
	 *
	 * @param string $role Role slug, or '' for all roles.
	 * @return array Snapshot; see get_snapshot().
	 */
	private static function build( string $role ): array {
		$now      = time();
		$snapshot = array(
			'role'         => $role,
			'generated_at' => $now,
			'stale_at'     => $now + self::MAX_AGE,
			'total'        => self::count( $role, array() ),
			'statuses'     => array(),
			'methods'      => array(),
		);

		foreach ( array_keys( EnrollmentStatus::get_labels() ) as $status ) {
			$snapshot['statuses'][ $status ] = self::count( $role, array( EnrollmentStatus::QUERY_VAR => $status ) );
		}

		foreach ( array_keys( EnrollmentStatus::get_method_labels() ) as $method ) {
			$snapshot['methods'][ $method ] = self::count( $role, array( EnrollmentStatus::METHOD_QUERY_VAR => $method ) );
		}

		// A pending user becomes overdue at their deadline, so the snapshot must not outlive the earliest one.
		if ( $snapshot['statuses'][ EnrollmentStatus::STATUS_PENDING ] > 0 ) {
			$next  = new WP_User_Query(
				self::query_args(
					$role,
					array(
						EnrollmentStatus::QUERY_VAR => EnrollmentStatus::STATUS_PENDING,
						'meta_key'                  => 'brain2fa_grace_expires_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Finds one row: the earliest deadline.
						'orderby'                   => 'meta_value_num',
						'order'                     => 'ASC',
						'count_total'               => false,
					)
				)
			);
			$first = $next->get_results();
			if ( $first ) {
				$snapshot['stale_at'] = min( $snapshot['stale_at'], max( $now + 1, EnrollmentStatus::get_deadline( (int) $first[0] ) ) );
			}
		}

		return $snapshot;
	}

	/**
	 * Count the members that match a filter.
	 *
	 * @param string $role   Role slug, or '' for all roles.
	 * @param array  $filter EnrollmentStatus query variables.
	 * @return int Number of users.
	 */
	private static function count( string $role, array $filter ): int {
		$query = new WP_User_Query( self::query_args( $role, $filter ) );

		return (int) $query->get_total();
	}

	/**
	 * Get the user query arguments for a count.
	 *
	 * @param string $role   Role slug, or '' for all roles.
	 * @param array  $filter Extra query arguments.
	 * @return array Query arguments.
	 */
	private static function query_args( string $role, array $filter ): array {
		$args = array_merge(
			array(
				'fields'      => 'ID',
				'number'      => 1,
				'count_total' => true,
			),
			$filter
		);

		if ( '' !== $role ) {
			$args['role'] = $role;
		}

		return $args;
	}
}
