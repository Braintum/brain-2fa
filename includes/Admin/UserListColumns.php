<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * User List Columns
 *
 * Handles 2FA status column in the WordPress users list table.
 *
 * @package Brain_2FA\Admin
 * @since 1.0.0
 */

namespace Brain_2FA\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * UserListColumns class for managing 2FA columns in users list.
 *
 * @since 1.0.0
 */
class UserListColumns {

	/**
	 * Singleton instance.
	 *
	 * @var UserListColumns|null
	 */
	protected static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return UserListColumns
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
		add_filter( 'manage_users_columns', array( $this, 'add_2fa_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_2fa_column' ), 10, 3 );
		add_filter( 'manage_users_sortable_columns', array( $this, 'make_2fa_column_sortable' ) );
		add_action( 'pre_get_users', array( $this, 'sort_by_2fa_status' ) );
	}

	/**
	 * Add 2FA status column to users table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_2fa_column( array $columns ): array {
		$columns['brain2fa_status'] = __( '2FA Status', 'brain2fa' );
		return $columns;
	}

	/**
	 * Render 2FA status column content.
	 *
	 * @param string $output      Custom column output. Default empty.
	 * @param string $column_name Column name.
	 * @param int    $user_id     User ID.
	 * @return string Column content.
	 */
	public function render_2fa_column( string $output, string $column_name, int $user_id ): string {
		if ( 'brain2fa_status' !== $column_name ) {
			return $output;
		}

		$is_enabled = get_user_meta( $user_id, 'brain2fa_enabled', true );

		if ( $is_enabled ) {

			return sprintf(
				'<span class="brain2fa-status brain2fa-status-enabled"><span class="dashicons dashicons-shield-alt"></span> %s</span>',
				esc_html__( 'Enabled', 'brain2fa' ),
			);
		}

		return sprintf(
			'<span class="brain2fa-status brain2fa-status-disabled"><span class="dashicons dashicons-shield"></span> %s</span>',
			esc_html__( 'Not Active', 'brain2fa' )
		);
	}

	/**
	 * Make 2FA status column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array Modified sortable columns.
	 */
	public function make_2fa_column_sortable( array $columns ): array {
		$columns['brain2fa_status'] = 'brain2fa_status';
		return $columns;
	}

	/**
	 * Handle sorting by 2FA status.
	 *
	 * @param \WP_User_Query $query User query object.
	 * @return void
	 */
	public function sort_by_2fa_status( $query ): void {
		if ( ! is_admin() ) {
			return;
		}

		$orderby = $query->get( 'orderby' );

		if ( 'brain2fa_status' === $orderby ) {
			$query->set( 'meta_key', 'brain2fa_enabled' );
			$query->set( 'orderby', 'meta_value' );
		}
	}
}
