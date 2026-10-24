<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Enrollment status
 *
 * @package Brain_2FA\Enrollment
 * @since 1.2.0
 */

namespace Brain_2FA\Enrollment;

use Brain_2FA\Passkeys\PasskeyLogin;
use Brain_2FA\Passkeys\PasskeyRepository;
use Brain_2FA\Utils;
use WP_User;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Enrollment status
 *
 * The one interpretation of the 2FA policy for reports: the Overview page, the Users
 * list column and filter, and setup reminders. Every site member has exactly one status.
 * get_status() evaluates one user in PHP; get_where_sql() expresses the same rules in SQL,
 * so filtered user queries count and paginate in the database. Keep the two in step.
 *
 * Everything here is read-only. Unlike Utils::get_grace_period_expiry(), nothing here
 * starts a setup deadline, so viewing a report does not start anyone's grace period.
 *
 * @since 1.2.0
 */
class EnrollmentStatus {

	/**
	 * At least one usable factor: an enabled code method the site offers, or a usable passkey.
	 */
	public const STATUS_PROTECTED = 'protected';

	/**
	 * A code method is set up but the site turned it off, and there is no usable passkey.
	 * The user cannot finish a password sign-in (this fails closed).
	 */
	public const STATUS_METHOD_OFF = 'method_off';

	/**
	 * Required and not enrolled, with a setup deadline in the future.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Required and not enrolled, with a passed deadline: restricted setup mode applies.
	 */
	public const STATUS_OVERDUE = 'overdue';

	/**
	 * Required and not enrolled, with no deadline yet. It starts at the user's next sign-in.
	 */
	public const STATUS_NOT_STARTED = 'not_started';

	/**
	 * Required and not enrolled, but the site offers no code method, so setup cannot be enforced.
	 */
	public const STATUS_UNAVAILABLE = 'setup_unavailable';

	/**
	 * Not required and not enrolled.
	 */
	public const STATUS_OPTIONAL = 'optional';

	/**
	 * Two-factor authentication is off for the site.
	 */
	public const STATUS_INACTIVE = 'inactive';

	/**
	 * Query variable for WP_User_Query that limits results to one status.
	 */
	public const QUERY_VAR = 'brain2fa_status';

	/**
	 * Query variable for WP_User_Query that limits results to users with a configured method.
	 */
	public const METHOD_QUERY_VAR = 'brain2fa_method';

	/**
	 * Register the user query variables.
	 *
	 * WordPress caches user query results until a user, user meta or role changes. The status
	 * conditions also read the passkeys table, so passkey changes must expire that cache too.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'pre_user_query', array( __CLASS__, 'filter_user_query' ) );
		add_action( 'brain2fa_passkeys_changed', 'wp_cache_set_users_last_changed' );
	}

	/**
	 * Get the statuses with their labels, in display order.
	 *
	 * @return array<string, string> Labels keyed by status.
	 */
	public static function get_labels(): array {
		return array(
			self::STATUS_PROTECTED   => __( 'Protected', 'brain-2fa' ),
			self::STATUS_PENDING     => __( 'Setup pending', 'brain-2fa' ),
			self::STATUS_OVERDUE     => __( 'Setup overdue', 'brain-2fa' ),
			self::STATUS_NOT_STARTED => __( 'Required, not started', 'brain-2fa' ),
			self::STATUS_UNAVAILABLE => __( 'Required, setup unavailable', 'brain-2fa' ),
			self::STATUS_METHOD_OFF  => __( 'Method turned off', 'brain-2fa' ),
			self::STATUS_OPTIONAL    => __( 'Not enrolled', 'brain-2fa' ),
			self::STATUS_INACTIVE    => __( 'Two-factor off for the site', 'brain-2fa' ),
		);
	}

	/**
	 * Get the configured methods that report filters accept, with their labels.
	 *
	 * @return array<string, string> Labels keyed by method filter.
	 */
	public static function get_method_labels(): array {
		return array(
			'totp'     => __( 'Authenticator app', 'brain-2fa' ),
			'email'    => __( 'Email codes', 'brain-2fa' ),
			'passkeys' => __( 'Passkeys', 'brain-2fa' ),
			'multiple' => __( 'A code method and a passkey', 'brain-2fa' ),
		);
	}

	/**
	 * Get a user's status.
	 *
	 * @param WP_User $user User.
	 * @param int     $now  Unix time to evaluate deadlines at; 0 for the current time.
	 * @return string One of the STATUS_* constants.
	 */
	public static function get_status( WP_User $user, int $now = 0 ): string {
		if ( ! Utils::is_2fa_enabled_sitewide() ) {
			return self::STATUS_INACTIVE;
		}

		$has_code    = Utils::is_code_method_enabled_for_user( $user->ID );
		$has_passkey = PasskeyLogin::is_enabled() && PasskeyRepository::count_for_user( $user->ID ) > 0;
		$code_usable = $has_code && in_array( (string) get_user_meta( $user->ID, 'brain2fa_method', true ), self::get_enabled_code_methods(), true );

		if ( $code_usable || $has_passkey ) {
			return self::STATUS_PROTECTED;
		}

		if ( $has_code ) {
			return self::STATUS_METHOD_OFF;
		}

		if ( ! Utils::is_2fa_required_for_user( $user ) ) {
			return self::STATUS_OPTIONAL;
		}

		if ( ! self::get_enabled_code_methods() ) {
			return self::STATUS_UNAVAILABLE;
		}

		$deadline = self::get_deadline( $user->ID );
		if ( 0 === $deadline ) {
			return self::STATUS_NOT_STARTED;
		}

		return $deadline > ( $now ? $now : time() ) ? self::STATUS_PENDING : self::STATUS_OVERDUE;
	}

	/**
	 * Get a user's stored setup deadline without creating one.
	 *
	 * @param int $user_id User ID.
	 * @return int Unix time, or 0 when no deadline has started.
	 */
	public static function get_deadline( int $user_id ): int {
		return absint( get_user_meta( $user_id, 'brain2fa_grace_expires_at', true ) );
	}

	/**
	 * Apply the status and method query variables to a user query.
	 *
	 * @param WP_User_Query $query User query, after WordPress built its SQL.
	 * @return void
	 */
	public static function filter_user_query( $query ): void {
		$status = $query->get( self::QUERY_VAR );
		if ( is_string( $status ) && '' !== $status ) {
			$query->query_where .= ' AND (' . self::get_where_sql( $status ) . ')';
		}

		$method = $query->get( self::METHOD_QUERY_VAR );
		if ( is_string( $method ) && '' !== $method ) {
			$query->query_where .= ' AND (' . self::get_method_where_sql( $method ) . ')';
		}
	}

	/**
	 * Get an SQL condition on the users table that matches one status.
	 *
	 * Mirrors get_status() for the current settings. An unknown status matches nothing.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @param int    $now    Unix time to evaluate deadlines at; 0 for the current time.
	 * @return string SQL condition.
	 */
	public static function get_where_sql( string $status, int $now = 0 ): string {
		global $wpdb;

		$now = $now ? $now : time();

		if ( ! Utils::is_2fa_enabled_sitewide() ) {
			return self::STATUS_INACTIVE === $status ? '1=1' : '1=0';
		}

		$sql         = self::get_condition_sql();
		$enrolled    = "({$sql['has_code']} OR {$sql['passkey']})";
		$unenrolled  = "{$sql['required']} AND NOT {$enrolled}";
		$can_enforce = (bool) self::get_enabled_code_methods();
		$deadline    = $wpdb->prepare( 'COALESCE((SELECT CAST(b2d.meta_value AS UNSIGNED) FROM %i b2d WHERE b2d.user_id = %i.ID AND b2d.meta_key = %s LIMIT 1), 0)', $wpdb->usermeta, $wpdb->users, 'brain2fa_grace_expires_at' );

		switch ( $status ) {
			case self::STATUS_PROTECTED:
				return "({$sql['code_usable']} OR {$sql['passkey']})";
			case self::STATUS_METHOD_OFF:
				return "{$sql['has_code']} AND NOT {$sql['code_usable']} AND NOT {$sql['passkey']}";
			case self::STATUS_OPTIONAL:
				return "NOT {$sql['required']} AND NOT {$enrolled}";
			case self::STATUS_UNAVAILABLE:
				return $can_enforce ? '1=0' : $unenrolled;
			case self::STATUS_NOT_STARTED:
				return $can_enforce ? "{$unenrolled} AND {$deadline} = 0" : '1=0';
			case self::STATUS_PENDING:
				return $can_enforce ? "{$unenrolled} AND {$deadline} > {$now}" : '1=0';
			case self::STATUS_OVERDUE:
				return $can_enforce ? "{$unenrolled} AND {$deadline} > 0 AND {$deadline} <= {$now}" : '1=0';
		}

		return '1=0';
	}

	/**
	 * Get an SQL condition on the users table for users with a configured method.
	 *
	 * Counts configured methods, usable or not; "passkeys" means stored passkeys.
	 *
	 * @param string $method totp, email, passkeys or multiple (a code method and a passkey).
	 * @return string SQL condition.
	 */
	public static function get_method_where_sql( string $method ): string {
		$sql = self::get_condition_sql();

		switch ( $method ) {
			case 'totp':
			case 'email':
				return $sql['has_code'] . ' AND ' . self::meta_exists( 'brain2fa_method', array( $method ) );
			case 'passkeys':
				return $sql['stored_passkey'];
			case 'multiple':
				return "{$sql['has_code']} AND {$sql['stored_passkey']}";
		}

		return '1=0';
	}

	/**
	 * Get the SQL building blocks shared by the status and method conditions.
	 *
	 * @return array<string, string> Conditions on the users table.
	 */
	private static function get_condition_sql(): array {
		global $wpdb;

		$methods        = self::get_enabled_code_methods();
		$roles          = self::get_required_roles();
		$has_code       = $wpdb->prepare( '(EXISTS (SELECT 1 FROM %i b2e WHERE b2e.user_id = %i.ID AND b2e.meta_key = %s AND b2e.meta_value NOT IN (%s, %s)))', $wpdb->usermeta, $wpdb->users, 'brain2fa_enabled', '', '0' );
		$stored_passkey = $wpdb->prepare( '(EXISTS (SELECT 1 FROM %i b2p WHERE b2p.user_id = %i.ID))', PasskeyRepository::table_name(), $wpdb->users );

		// The same test WP_User_Query uses for role__in: the role name in the serialized capabilities.
		$required = '(1=0)';
		if ( $roles ) {
			$likes = array();
			foreach ( $roles as $role ) {
				$likes[] = '%' . $wpdb->esc_like( '"' . $role . '"' ) . '%';
			}
			$required = self::meta_exists( $wpdb->get_blog_prefix() . 'capabilities', $likes, 'LIKE' );
		}

		return array(
			'has_code'       => $has_code,
			'code_usable'    => $methods ? "({$has_code} AND " . self::meta_exists( 'brain2fa_method', $methods ) . ')' : '(1=0)',
			'stored_passkey' => $stored_passkey,
			'passkey'        => PasskeyLogin::is_enabled() ? $stored_passkey : '(1=0)',
			'required'       => $required,
		);
	}

	/**
	 * Get an SQL condition: the user has a meta row with this key and one of these values.
	 *
	 * @param string   $meta_key Meta key.
	 * @param string[] $values   Accepted values, or LIKE patterns.
	 * @param string   $compare  '=' or 'LIKE'.
	 * @return string SQL condition.
	 */
	private static function meta_exists( string $meta_key, array $values, string $compare = '=' ): string {
		global $wpdb;

		$tests = array();
		foreach ( $values as $value ) {
			$tests[] = 'LIKE' === $compare ? $wpdb->prepare( 'b2um.meta_value LIKE %s', $value ) : $wpdb->prepare( 'b2um.meta_value = %s', $value );
		}

		return $wpdb->prepare( '(EXISTS (SELECT 1 FROM %i b2um WHERE b2um.user_id = %i.ID AND b2um.meta_key = %s AND (', $wpdb->usermeta, $wpdb->users, $meta_key ) . implode( ' OR ', $tests ) . ')))';
	}

	/**
	 * Get the code methods the site currently offers.
	 *
	 * @return string[] Method IDs.
	 */
	private static function get_enabled_code_methods(): array {
		return array_values( array_filter( array( 'totp', 'email' ), array( Utils::class, 'is_method_enabled' ) ) );
	}

	/**
	 * Get the roles that must use two-factor authentication.
	 *
	 * @return string[] Role slugs.
	 */
	private static function get_required_roles(): array {
		$roles = Utils::get_settings()['force_2fa_roles'] ?? array();

		return is_array( $roles ) ? array_values( array_filter( $roles, 'is_string' ) ) : array();
	}
}
