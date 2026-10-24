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

use Brain_2FA\Enrollment\EnrollmentStatus;
use Brain_2FA\Passkeys\PasskeyRepository;
use Brain_2FA\Utils;

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
	 * Whether passkey counts for the listed users were loaded.
	 *
	 * @var bool
	 */
	private $primed = false;

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
		add_action( 'pre_get_users', array( $this, 'filter_by_2fa_status' ) );
		add_action( 'restrict_manage_users', array( $this, 'render_status_filter' ) );
		add_action( 'admin_notices', array( $this, 'render_filter_notice' ) );
	}

	/**
	 * Add 2FA status column to users table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_2fa_column( array $columns ): array {
		$columns['brain2fa_status'] = __( '2FA Status', 'brain-2fa' );
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

		$this->prime_list();

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return $output;
		}

		// Read-only: listing users does not start anyone's setup deadline.
		$status = EnrollmentStatus::get_status( $user );
		$labels = EnrollmentStatus::get_labels();

		switch ( $status ) {
			case EnrollmentStatus::STATUS_PROTECTED:
				return self::format_status(
					'enabled',
					'shield-alt',
					Utils::is_code_method_enabled_for_user( $user_id ) && Utils::is_method_enabled( (string) get_user_meta( $user_id, 'brain2fa_method', true ) )
						? __( 'Enabled', 'brain-2fa' )
						: __( 'Enabled (passkeys)', 'brain-2fa' )
				);
			case EnrollmentStatus::STATUS_PENDING:
				return self::format_status(
					'disabled',
					'clock',
					sprintf(
						/* translators: %s: setup deadline */
						__( 'Setup due %s', 'brain-2fa' ),
						wp_date( get_option( 'date_format' ), EnrollmentStatus::get_deadline( $user_id ) )
					)
				);
			case EnrollmentStatus::STATUS_OVERDUE:
			case EnrollmentStatus::STATUS_METHOD_OFF:
				return self::format_status( 'disabled', 'warning', $labels[ $status ] );
			case EnrollmentStatus::STATUS_NOT_STARTED:
				return self::format_status( 'disabled', 'clock', $labels[ $status ] );
			case EnrollmentStatus::STATUS_UNAVAILABLE:
				return self::format_status( 'disabled', 'shield', $labels[ $status ] );
		}

		return self::format_status( 'disabled', 'shield', __( 'Not Active', 'brain-2fa' ) );
	}

	/**
	 * Build the status column markup.
	 *
	 * @param string $state enabled or disabled.
	 * @param string $icon  Dashicon name without the prefix.
	 * @param string $text  Plain-text label.
	 * @return string HTML.
	 */
	private static function format_status( string $state, string $icon, string $text ): string {
		return sprintf(
			'<span class="brain2fa-status brain2fa-status-%1$s"><span class="dashicons dashicons-%2$s" aria-hidden="true"></span> %3$s</span>',
			esc_attr( $state ),
			esc_attr( $icon ),
			esc_html( $text )
		);
	}

	/**
	 * Load the passkey counts of every listed user with one query.
	 *
	 * @return void
	 */
	private function prime_list(): void {
		global $wp_list_table;

		if ( $this->primed ) {
			return;
		}

		$this->primed = true;
		if ( $wp_list_table instanceof \WP_Users_List_Table && is_array( $wp_list_table->items ) ) {
			PasskeyRepository::prime_counts( wp_list_pluck( $wp_list_table->items, 'ID' ) );
		}
	}

	/**
	 * Get the 2FA filter requested for the Users list.
	 *
	 * @return array{0: string, 1: string}|null Query variable and value, or null when none is valid.
	 */
	private static function get_requested_filter(): ?array {
		$filters = array(
			EnrollmentStatus::QUERY_VAR        => EnrollmentStatus::get_labels(),
			EnrollmentStatus::METHOD_QUERY_VAR => EnrollmentStatus::get_method_labels(),
		);

		foreach ( $filters as $var => $labels ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; the value is checked against a fixed list.
			$value = isset( $_GET[ $var ] ) && is_string( $_GET[ $var ] ) ? sanitize_key( wp_unslash( $_GET[ $var ] ) ) : '';
			if ( isset( $labels[ $value ] ) ) {
				return array( $var, $value );
			}
		}

		return null;
	}

	/**
	 * Limit the Users list to a 2FA status or configured method.
	 *
	 * The condition is part of the SQL query, so counts and pagination match the filter.
	 *
	 * @param \WP_User_Query $query User query object.
	 * @return void
	 */
	public function filter_by_2fa_status( $query ): void {
		global $pagenow;

		if ( ! is_admin() || 'users.php' !== $pagenow ) {
			return;
		}

		$filter = self::get_requested_filter();
		if ( $filter && '' === (string) $query->get( $filter[0] ) ) {
			$query->set( $filter[0], $filter[1] );
		}
	}

	/**
	 * Render the 2FA status filter above the Users list.
	 *
	 * @param string $which Position of the extra table navigation: top or bottom.
	 * @return void
	 */
	public function render_status_filter( $which = 'top' ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$filter  = self::get_requested_filter();
		$current = $filter && EnrollmentStatus::QUERY_VAR === $filter[0] ? $filter[1] : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter; carried over unchanged.
		$role = isset( $_GET['role'] ) && is_string( $_GET['role'] ) ? sanitize_key( wp_unslash( $_GET['role'] ) ) : '';
		?>
		<div class="alignleft actions brain2fa-users-filter">
			<label class="screen-reader-text" for="brain2fa-status-filter"><?php esc_html_e( 'Filter by 2FA status', 'brain-2fa' ); ?></label>
			<select name="<?php echo esc_attr( EnrollmentStatus::QUERY_VAR ); ?>" id="brain2fa-status-filter">
				<option value=""><?php esc_html_e( 'All 2FA statuses', 'brain-2fa' ); ?></option>
				<?php foreach ( EnrollmentStatus::get_labels() as $status => $label ) : ?>
					<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $current, $status ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( '' !== $role ) : ?>
				<input type="hidden" name="role" value="<?php echo esc_attr( $role ); ?>">
			<?php endif; ?>
			<?php submit_button( __( 'Filter', 'brain-2fa' ), '', 'brain2fa_filter', false ); ?>
		</div>
		<?php
	}

	/**
	 * Say which 2FA filter the Users list shows, with a way back to all users.
	 *
	 * @return void
	 */
	public function render_filter_notice(): void {
		global $pagenow;

		if ( 'users.php' !== $pagenow ) {
			return;
		}

		$filter = self::get_requested_filter();
		if ( ! $filter ) {
			return;
		}

		$label = EnrollmentStatus::QUERY_VAR === $filter[0] ? EnrollmentStatus::get_labels()[ $filter[1] ] : EnrollmentStatus::get_method_labels()[ $filter[1] ];
		$text  = EnrollmentStatus::QUERY_VAR === $filter[0]
			/* translators: %s: 2FA status, such as "Setup pending" */
			? __( 'Showing users with the 2FA status "%s".', 'brain-2fa' )
			/* translators: %s: method, such as "Passkeys" */
			: __( 'Showing users who set up: %s.', 'brain-2fa' );
		?>
		<div class="notice notice-info">
			<p>
				<?php echo esc_html( sprintf( $text, $label ) ); ?>
				<a href="<?php echo esc_url( remove_query_arg( array( EnrollmentStatus::QUERY_VAR, EnrollmentStatus::METHOD_QUERY_VAR, 'brain2fa_filter', 'paged' ) ) ); ?>"><?php esc_html_e( 'Show all users', 'brain-2fa' ); ?></a>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
					&middot; <a href="<?php echo esc_url( OverviewPage::get_url() ); ?>"><?php esc_html_e( '2FA overview', 'brain-2fa' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
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
