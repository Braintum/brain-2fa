<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Database installation and upgrades
 *
 * @package Brain_2FA
 * @since 1.1.0
 */

namespace Brain_2FA;

use Brain_2FA\Passkeys\PasskeyRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Install
 *
 * Creates and upgrades the plugin's database tables. Runs on activation and,
 * because plugin updates do not trigger activation, whenever the stored
 * database version differs from DB_VERSION.
 *
 * @since 1.1.0
 */
class Install {

	/**
	 * Current database schema version.
	 *
	 * @var string
	 */
	public const DB_VERSION = '1';

	/**
	 * Option storing the installed database schema version.
	 *
	 * @var string
	 */
	public const DB_VERSION_OPTION = 'brain2fa_db_version';

	/**
	 * Activation hook callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		self::create_tables();
	}

	/**
	 * Create or upgrade tables when the schema version changed.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION !== get_option( self::DB_VERSION_OPTION ) ) {
			self::create_tables();
		}
	}

	/**
	 * Create or update the plugin tables with dbDelta().
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = PasskeyRepository::table_name();
		$charset = $wpdb->get_charset_collate();

		// dbDelta() requires two spaces after PRIMARY KEY and one field per line.
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  credential_hash char(64) NOT NULL,
  credential_id text NOT NULL,
  public_key text NOT NULL,
  sign_count bigint(20) unsigned NOT NULL DEFAULT 0,
  transports varchar(255) NOT NULL DEFAULT '',
  aaguid char(36) NOT NULL DEFAULT '',
  backup_eligible tinyint(1) NOT NULL DEFAULT 0,
  backed_up tinyint(1) NOT NULL DEFAULT 0,
  name varchar(100) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  last_used_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY credential_hash (credential_hash),
  KEY user_id (user_id)
) {$charset};"
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}
}
