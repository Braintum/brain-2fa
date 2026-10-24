<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Trusted device storage
 *
 * @package Brain_2FA\Auth
 * @since 1.2.0
 */

namespace Brain_2FA\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Trusted device storage
 *
 * Stores the browsers a user allowed to skip the second step ("Remember this device")
 * in the brain2fa_remembered_devices user meta. Each entry holds a random public ID,
 * a wp_hash_password() hash of the browser's token, creation, last-use and expiry
 * times, and a coarse browser and operating system name. Raw user agents are not kept.
 *
 * Every write re-reads the list under the user's lock, so a concurrent prune or
 * last-use update cannot restore an entry that was just revoked.
 *
 * Entries stored by 1.1.0 and earlier have only a token hash and an expiry. They are
 * given an ID on first read and keep working; their unknown dates are stored as 0.
 *
 * @since 1.2.0
 */
class TrustedDeviceRepository {

	/**
	 * User meta key for the device list.
	 *
	 * @var string
	 */
	public const META_KEY = 'brain2fa_remembered_devices';

	/**
	 * Maximum number of trusted devices per user. Adding one more drops the oldest.
	 *
	 * @var int
	 */
	public const MAX_DEVICES = 10;

	/**
	 * Get a user's active trusted devices, oldest first.
	 *
	 * Expired and malformed entries are left out. When the stored list needs cleaning
	 * or upgrading, the cleaned list is saved, so entry IDs stay stable between requests.
	 *
	 * @param int $user_id User ID.
	 * @return array<int, array{id: string, token: string, created_at: int, last_used_at: int, expires_at: int, browser: string, os: string}> Devices.
	 */
	public static function get_all( int $user_id ): array {
		$stored = get_user_meta( $user_id, self::META_KEY, true );
		if ( '' === $stored ) {
			return array();
		}

		$devices = self::normalize( $stored );
		if ( $devices === $stored ) {
			return $devices;
		}

		// Legacy entries got new IDs above; save under the lock and use what was saved.
		$saved = self::update( $user_id, static fn() => null );

		return null === $saved ? $devices : $saved;
	}

	/**
	 * Find the device that a cookie value belongs to.
	 *
	 * Accepts the current "{id}.{token}" format, which needs one hash check, and the
	 * bare token of 1.1.0 cookies, which is checked against every entry.
	 *
	 * @param int    $user_id User ID.
	 * @param string $value   Cookie value.
	 * @return string Device ID, or an empty string when no active device matches.
	 */
	public static function find( int $user_id, string $value ): string {
		if ( 1 === preg_match( '/^([a-f0-9]{16})\.([A-Za-z0-9]{64})$/', $value, $matches ) ) {
			$id    = $matches[1];
			$token = $matches[2];
		} elseif ( 1 === preg_match( '/^[A-Za-z0-9]{64}$/', $value ) ) {
			$id    = '';
			$token = $value;
		} else {
			return '';
		}

		foreach ( self::get_all( $user_id ) as $device ) {
			if ( '' !== $id && $device['id'] !== $id ) {
				continue;
			}

			if ( wp_check_password( $token, $device['token'] ) ) {
				return $device['id'];
			}
		}

		return '';
	}

	/**
	 * Add a trusted device.
	 *
	 * @param int    $user_id    User ID.
	 * @param int    $expires_at Unix expiry time.
	 * @param string $user_agent The browser's user agent, reduced to a browser and system name.
	 * @return string Cookie value for the browser, or an empty string when the device could not be stored.
	 */
	public static function add( int $user_id, int $expires_at, string $user_agent ): string {
		$token  = wp_generate_password( 64, false, false );
		$id     = self::generate_id();
		$label  = self::describe_user_agent( $user_agent );
		$device = array(
			'id'           => $id,
			'token'        => wp_hash_password( $token ),
			'created_at'   => time(),
			'last_used_at' => 0,
			'expires_at'   => $expires_at,
			'browser'      => $label['browser'],
			'os'           => $label['os'],
		);

		$saved = self::update(
			$user_id,
			static function ( array &$devices ) use ( $device ) {
				$devices[] = $device;
			}
		);

		return null === $saved ? '' : $id . '.' . $token;
	}

	/**
	 * Record a successful sign-in from a trusted device.
	 *
	 * Does nothing when the device was revoked in the meantime.
	 *
	 * @param int    $user_id User ID.
	 * @param string $id      Device ID.
	 * @return void
	 */
	public static function touch( int $user_id, string $id ): void {
		self::update(
			$user_id,
			static function ( array &$devices ) use ( $id ) {
				foreach ( $devices as &$device ) {
					if ( $device['id'] === $id ) {
						$device['last_used_at'] = time();
					}
				}
			}
		);
	}

	/**
	 * Revoke one trusted device.
	 *
	 * @param int    $user_id User ID; only this user's list is searched.
	 * @param string $id      Device ID.
	 * @return bool True when the device was found and removed.
	 */
	public static function remove( int $user_id, string $id ): bool {
		$removed = false;

		self::update(
			$user_id,
			static function ( array &$devices ) use ( $id, &$removed ) {
				foreach ( $devices as $index => $device ) {
					if ( $device['id'] === $id ) {
						unset( $devices[ $index ] );
						$removed = true;
					}
				}
			}
		);

		return $removed;
	}

	/**
	 * Revoke all of a user's trusted devices.
	 *
	 * Takes the lock so an update already in progress cannot write the list back.
	 * The lock is only busy this long when its holder died, so the list is removed either way.
	 *
	 * @param int $user_id User ID.
	 * @return void
	 */
	public static function remove_all( int $user_id ): void {
		$locked = UserLock::acquire( $user_id );
		delete_user_meta( $user_id, self::META_KEY );
		if ( $locked ) {
			UserLock::release( $user_id );
		}
	}

	/**
	 * Get a display name for a device, such as "Firefox on Windows".
	 *
	 * @param array $device Device entry.
	 * @return string Translated name.
	 */
	public static function get_label( array $device ): string {
		$browser = (string) ( $device['browser'] ?? '' );
		$os      = (string) ( $device['os'] ?? '' );

		if ( '' !== $browser && '' !== $os ) {
			/* translators: 1: browser name, such as "Firefox", 2: operating system name, such as "Windows" */
			return sprintf( __( '%1$s on %2$s', 'brain-2fa' ), $browser, $os );
		}

		if ( '' !== $browser ) {
			return $browser;
		}

		if ( '' !== $os ) {
			/* translators: %s: operating system name, such as "Windows" */
			return sprintf( __( 'Browser on %s', 'brain-2fa' ), $os );
		}

		return __( 'Trusted browser', 'brain-2fa' );
	}

	/**
	 * Change a user's device list under the lock.
	 *
	 * @param int      $user_id User ID.
	 * @param callable $change  Receives the fresh, cleaned list by reference and changes it.
	 * @return array|null The saved list, or null when the lock stayed busy.
	 */
	private static function update( int $user_id, callable $change ): ?array {
		if ( ! UserLock::acquire( $user_id ) ) {
			return null;
		}

		try {
			wp_cache_delete( $user_id, 'user_meta' );
			$stored  = get_user_meta( $user_id, self::META_KEY, true );
			$devices = self::normalize( $stored );

			$change( $devices );
			$devices = array_slice( array_values( $devices ), -self::MAX_DEVICES );

			if ( ! $devices ) {
				delete_user_meta( $user_id, self::META_KEY );
			} elseif ( $devices !== $stored ) {
				update_user_meta( $user_id, self::META_KEY, $devices );
			}

			return $devices;
		} finally {
			UserLock::release( $user_id );
		}
	}

	/**
	 * Clean a stored device list: drop expired and malformed entries and fill in missing fields.
	 *
	 * @param mixed $stored Stored meta value.
	 * @return array Cleaned list, in a fixed field order so an unchanged list compares equal.
	 */
	private static function normalize( $stored ): array {
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$now     = time();
		$devices = array();

		foreach ( $stored as $device ) {
			if ( ! is_array( $device ) || empty( $device['token'] ) || ! is_string( $device['token'] ) || absint( $device['expires_at'] ?? 0 ) <= $now ) {
				continue;
			}

			$devices[] = array(
				'id'           => isset( $device['id'] ) && is_string( $device['id'] ) && 1 === preg_match( '/^[a-f0-9]{16}$/', $device['id'] ) ? $device['id'] : self::generate_id(),
				'token'        => $device['token'],
				'created_at'   => absint( $device['created_at'] ?? 0 ),
				'last_used_at' => absint( $device['last_used_at'] ?? 0 ),
				'expires_at'   => absint( $device['expires_at'] ),
				'browser'      => isset( $device['browser'] ) && is_string( $device['browser'] ) ? $device['browser'] : '',
				'os'           => isset( $device['os'] ) && is_string( $device['os'] ) ? $device['os'] : '',
			);
		}

		return array_slice( $devices, -self::MAX_DEVICES );
	}

	/**
	 * Generate a random public device ID.
	 *
	 * @return string 16 hexadecimal characters.
	 */
	private static function generate_id(): string {
		return bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Reduce a user agent to a browser and operating system name from fixed lists.
	 *
	 * @param string $user_agent User agent.
	 * @return array{browser: string, os: string} Names, empty when unknown.
	 */
	private static function describe_user_agent( string $user_agent ): array {
		// Order matters: several browsers also send "Chrome/" or "Safari/", and Android sends "Linux".
		$browsers = array(
			'Edg'            => 'Edge',
			'OPR/'           => 'Opera',
			'SamsungBrowser' => 'Samsung Internet',
			'Firefox/'       => 'Firefox',
			'FxiOS'          => 'Firefox',
			'CriOS'          => 'Chrome',
			'Chrome/'        => 'Chrome',
			'Safari/'        => 'Safari',
		);
		$systems  = array(
			'iPhone'    => 'iOS',
			'iPad'      => 'iPadOS',
			'Android'   => 'Android',
			'CrOS'      => 'ChromeOS',
			'Windows'   => 'Windows',
			'Macintosh' => 'macOS',
			'Linux'     => 'Linux',
		);

		$label = array(
			'browser' => '',
			'os'      => '',
		);

		foreach ( $browsers as $needle => $name ) {
			if ( str_contains( $user_agent, $needle ) ) {
				$label['browser'] = $name;
				break;
			}
		}

		foreach ( $systems as $needle => $name ) {
			if ( str_contains( $user_agent, $needle ) ) {
				$label['os'] = $name;
				break;
			}
		}

		return $label;
	}
}
