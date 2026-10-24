<?php
/**
 * Brain 2FA WP-CLI check: Enrollment status, Overview and Users filters.
 *
 * The PHP status (EnrollmentStatus::get_status) and the SQL status (get_where_sql) agree for every test user across
 * a settings matrix, and every user is in exactly one status. The Users column, the Overview and another user's
 * profile start no setup deadline. Overview counts match the PHP statuses and the filtered Users list, the cache is
 * dropped on changes and never outlives the next deadline, and the number of queries does not grow with users.
 * Restores brain2fa_settings and removes the test users.
 *
 * Development sites only: it writes options and creates users while it runs.
 * Run all checks with `npm run test:wp`, or this one with
 * `php ../../../wp-cli.phar eval-file tests/wp-cli/enrollment.php` from the plugin directory.
 * Prints PASS/FAIL per check and exits 1 when any check fails.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit;
}
require_once ABSPATH . 'wp-admin/includes/template.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';

use Brain_2FA\Admin\OverviewPage;
use Brain_2FA\Admin\UserListColumns;
use Brain_2FA\Admin\UserProfile;
use Brain_2FA\Enrollment\EnrollmentReport as Report;
use Brain_2FA\Enrollment\EnrollmentStatus as S;
use Brain_2FA\Passkeys\PasskeyRepository as R;
use Brain_2FA\Utils;

$missing  = new stdClass();
$original = get_option( 'brain2fa_settings', $missing );
$https    = function ( $u ) { return preg_replace( '#^http://#', 'https://', $u ); };
$GLOBALS['b2_users'] = array();
$GLOBALS['b2_fails'] = 0;
$GLOBALS['title']    = 'Overview';
set_error_handler( function ( $no, $str, $file, $line ) { if ( false !== strpos( $file, 'brain-2fa' ) ) { echo "PHP ISSUE: $str in $file:$line\n"; } return false; } );

function b2_check( $label, $cond ) { echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $GLOBALS['b2_fails']++; } }
function b2_settings( array $over ) { remove_all_filters( 'sanitize_option_brain2fa_settings' ); update_option( 'brain2fa_settings', array_merge( Utils::SETTINGS_DEFAULTS, array( 'enable_2fa' => true, 'force_2fa_roles' => array( 'editor' ) ), $over ) ); }
function b2_deadline( $id ) { wp_cache_delete( $id, 'user_meta' ); return get_user_meta( $id, 'brain2fa_grace_expires_at', true ); }

/**
 * Create a test user.
 *
 * @param string[]    $roles    Roles.
 * @param string|null $method   Code method set up and enabled, or null.
 * @param bool        $passkey  Store a passkey.
 * @param int|null    $deadline Setup deadline, or null for none.
 * @return int User ID.
 */
function b2_user( array $roles, $method = null, $passkey = false, $deadline = null ) {
	$id = wp_insert_user( array( 'user_login' => 'b2fa_e_' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'b2fa-e-' . wp_generate_password( 6, false, false ) . '@example.test', 'role' => array_shift( $roles ) ) );
	$GLOBALS['b2_users'][] = $id;
	$user = get_userdata( $id );
	foreach ( $roles as $role ) { $user->add_role( $role ); }
	if ( $method ) { update_user_meta( $id, 'brain2fa_method', $method ); update_user_meta( $id, 'brain2fa_enabled', 1 ); }
	if ( $passkey ) { R::add( $id, array( 'credential_id' => 'e-' . wp_generate_password( 12, false ), 'public_key' => 'x', 'sign_count' => 0, 'transports' => array(), 'aaguid' => '', 'backup_eligible' => false, 'backed_up' => false, 'name' => 'k' ) ); }
	if ( null !== $deadline ) { update_user_meta( $id, 'brain2fa_grace_expires_at', $deadline ); }
	return $id;
}

/**
 * Get the statuses whose SQL condition matches a user.
 *
 * @param int $id User ID.
 * @return string[] Statuses.
 */
function b2_sql_statuses( $id ) {
	global $wpdb;
	$found = array();
	foreach ( array_keys( S::get_labels() ) as $status ) {
		$where = S::get_where_sql( $status );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE ID = %d AND ({$where})", $wpdb->users, $id ) ) ) { // phpcs:ignore
			$found[] = $status;
		}
	}
	return $found;
}

/**
 * Check that PHP and SQL agree for every user, and that the PHP statuses are the expected ones.
 *
 * @param string $label    Matrix entry.
 * @param array  $expected User ID => status.
 */
function b2_matrix( $label, array $expected ) {
	$bad = array();
	foreach ( $expected as $id => $status ) {
		clean_user_cache( $id );
		$php = S::get_status( get_userdata( $id ) );
		$sql = b2_sql_statuses( $id );
		if ( $php !== $status || array( $php ) !== $sql ) {
			$bad[] = "#$id expected $status, php $php, sql " . implode( '+', $sql );
		}
	}
	b2_check( "matrix $label: PHP and SQL agree, one status each" . ( $bad ? ' (' . implode( '; ', $bad ) . ')' : '' ), ! $bad );
}

/**
 * Run the Users list query as users.php does, with the plugin's request filter.
 *
 * @param array $get  Request query.
 * @param array $args Extra query arguments.
 * @return WP_User_Query Query.
 */
function b2_users_list( array $get, array $args = array() ) {
	$_GET = $get;
	$query = new WP_User_Query( array_merge( array( 'number' => 20, 'count_total' => true, 'fields' => 'ID' ), array_filter( array( 'role' => $get['role'] ?? '' ) ), $args ) );
	$_GET = array();
	return $query;
}

/**
 * Count every site user per PHP status.
 *
 * @param string $role Role, or '' for all.
 * @return array<string, int> Counts.
 */
function b2_php_counts( $role = '' ) {
	$counts = array_fill_keys( array_keys( S::get_labels() ), 0 );
	foreach ( get_users( array_filter( array( 'role' => $role, 'fields' => 'all' ) ) ) as $user ) {
		$counts[ S::get_status( $user ) ]++;
	}
	return $counts;
}

try {
	$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
	wp_set_current_user( $admin[0]->ID );
	b2_settings( array() );
	$now = time();

	$u = array(
		'totp_sub'      => b2_user( array( 'subscriber' ), 'totp' ),
		'email_ed'      => b2_user( array( 'editor' ), 'email' ),
		'pk_ed'         => b2_user( array( 'editor' ), null, true, $now + DAY_IN_SECONDS ),
		'totp_pk_ed'    => b2_user( array( 'editor' ), 'totp', true ),
		'new_ed'        => b2_user( array( 'editor' ) ),
		'pending_ed'    => b2_user( array( 'editor' ), null, false, $now + 3 * DAY_IN_SECONDS ),
		'overdue_ed'    => b2_user( array( 'editor' ), null, false, $now - 60 ),
		'zero_ed'       => b2_user( array( 'editor' ), null, false, $now ),
		'plain_sub'     => b2_user( array( 'subscriber' ) ),
		'multi_role'    => b2_user( array( 'subscriber', 'editor' ), null, false, $now + DAY_IN_SECONDS ),
		'author'        => b2_user( array( 'author' ) ),
		'disabled_code' => b2_user( array( 'editor' ) ),
	);
	update_user_meta( $u['disabled_code'], 'brain2fa_method', 'totp' );
	update_user_meta( $u['disabled_code'], 'brain2fa_enabled', '0' );
	$base = array(
		$u['totp_sub']      => S::STATUS_PROTECTED,
		$u['email_ed']      => S::STATUS_PROTECTED,
		$u['totp_pk_ed']    => S::STATUS_PROTECTED,
		$u['new_ed']        => S::STATUS_NOT_STARTED,
		$u['pending_ed']    => S::STATUS_PENDING,
		$u['overdue_ed']    => S::STATUS_OVERDUE,
		$u['zero_ed']       => S::STATUS_OVERDUE,
		$u['plain_sub']     => S::STATUS_OPTIONAL,
		$u['multi_role']    => S::STATUS_PENDING,
		$u['author']        => S::STATUS_OPTIONAL,
		$u['disabled_code'] => S::STATUS_NOT_STARTED,
	);

	// Passkeys off (or no HTTPS): a passkey is not protection.
	b2_matrix( 'defaults', $base + array( $u['pk_ed'] => S::STATUS_PENDING ) );

	b2_settings( array( 'enable_passkeys' => true ) );
	b2_matrix( 'passkeys on without HTTPS', $base + array( $u['pk_ed'] => S::STATUS_PENDING ) );

	add_filter( 'home_url', $https );
	add_filter( 'site_url', $https );
	b2_matrix( 'passkeys on with HTTPS', $base + array( $u['pk_ed'] => S::STATUS_PROTECTED ) );

	b2_settings( array( 'enable_passkeys' => true, 'enable_totp' => false ) );
	b2_matrix(
		'TOTP off',
		array(
			$u['totp_sub']   => S::STATUS_METHOD_OFF,
			$u['totp_pk_ed'] => S::STATUS_PROTECTED,
			$u['email_ed']   => S::STATUS_PROTECTED,
			$u['pk_ed']      => S::STATUS_PROTECTED,
		) + $base
	);
	remove_filter( 'home_url', $https );
	remove_filter( 'site_url', $https );

	b2_settings( array( 'enable_totp' => false, 'enable_email' => false ) );
	b2_matrix(
		'both code methods off',
		array(
			$u['totp_sub']   => S::STATUS_METHOD_OFF,
			$u['email_ed']   => S::STATUS_METHOD_OFF,
			$u['totp_pk_ed'] => S::STATUS_METHOD_OFF,
			$u['new_ed']     => S::STATUS_UNAVAILABLE,
			$u['pending_ed'] => S::STATUS_UNAVAILABLE,
			$u['overdue_ed'] => S::STATUS_UNAVAILABLE,
			$u['zero_ed']    => S::STATUS_UNAVAILABLE,
			$u['multi_role'] => S::STATUS_UNAVAILABLE,
			$u['pk_ed']      => S::STATUS_UNAVAILABLE,
			$u['disabled_code'] => S::STATUS_UNAVAILABLE,
		) + $base
	);

	b2_settings( array( 'force_2fa_roles' => array() ) );
	$optional = array_map( function ( $s ) { return S::STATUS_PROTECTED === $s ? $s : S::STATUS_OPTIONAL; }, $base );
	b2_matrix( 'no required role', $optional + array( $u['pk_ed'] => S::STATUS_OPTIONAL ) );

	b2_settings( array( 'force_2fa_roles' => array( 'editor', 'author' ) ) );
	b2_matrix( 'two required roles', array( $u['author'] => S::STATUS_NOT_STARTED ) + $base + array( $u['pk_ed'] => S::STATUS_PENDING ) );

	b2_settings( array( 'enable_2fa' => false ) );
	b2_matrix( '2FA off', array_fill_keys( array_keys( $base + array( $u['pk_ed'] => '' ) ), S::STATUS_INACTIVE ) );

	b2_settings( array() );
	b2_check( 'evaluation time: pending until the deadline, overdue from it', S::STATUS_PENDING === S::get_status( get_userdata( $u['pending_ed'] ), $now ) && S::STATUS_OVERDUE === S::get_status( get_userdata( $u['pending_ed'] ), $now + 3 * DAY_IN_SECONDS ) );
	b2_check( 'unknown status or method matches nobody', '1=0' === S::get_where_sql( 'nope' ) && '1=0' === S::get_method_where_sql( 'nope' ) );

	// Extending an expired deadline moves the user back to pending.
	Utils::extend_grace_period( get_userdata( $u['overdue_ed'] ), 2 );
	b2_matrix( 'after extending an expired deadline', array( $u['overdue_ed'] => S::STATUS_PENDING ) + $base + array( $u['pk_ed'] => S::STATUS_PENDING ) );
	update_user_meta( $u['overdue_ed'], 'brain2fa_grace_expires_at', $now - 60 );

	// Read-only views.
	set_current_screen( 'users' );
	$GLOBALS['pagenow'] = 'users.php';
	$columns = UserListColumns::instance();
	$html    = $columns->render_2fa_column( '', 'brain2fa_status', $u['new_ed'] );
	b2_check( 'column: not started, no deadline created', false !== strpos( $html, 'Required, not started' ) && '' === b2_deadline( $u['new_ed'] ) );
	b2_check( 'column: pending shows the date', false !== strpos( $columns->render_2fa_column( '', 'brain2fa_status', $u['pending_ed'] ), esc_html( wp_date( get_option( 'date_format' ), $now + 3 * DAY_IN_SECONDS ) ) ) );
	b2_check( 'column: code method shows Enabled', false !== strpos( $columns->render_2fa_column( '', 'brain2fa_status', $u['totp_sub'] ), '> Enabled</span>' ) );
	b2_check( 'column: overdue', false !== strpos( $columns->render_2fa_column( '', 'brain2fa_status', $u['overdue_ed'] ), 'Setup overdue' ) );
	b2_check( 'column: other columns untouched', 'x' === $columns->render_2fa_column( 'x', 'email', $u['new_ed'] ) );

	ob_start();
	OverviewPage::render();
	$overview = ob_get_clean();
	b2_check( 'overview: renders, no deadline created', false !== strpos( $overview, 'Users by status' ) && '' === b2_deadline( $u['new_ed'] ) );
	b2_check( 'overview: links to the filtered Users list', false !== strpos( $overview, 'users.php?brain2fa_status=pending' ) && false !== strpos( $overview, 'users.php?brain2fa_method=passkeys' ) );

	ob_start();
	UserProfile::instance()->render_user_2fa_settings( get_userdata( $u['new_ed'] ) );
	$profile = ob_get_clean();
	b2_check( 'other user\'s profile: not started, no deadline created', false !== strpos( $profile, 'next signs in' ) && '' === b2_deadline( $u['new_ed'] ) );
	b2_check( 'own session still starts the deadline', Utils::get_grace_period_expiry( get_userdata( $u['new_ed'] ) ) > $now && '' !== b2_deadline( $u['new_ed'] ) );
	delete_user_meta( $u['new_ed'], 'brain2fa_grace_expires_at' );

	wp_set_current_user( $admin[0]->ID );
	ob_start();
	OverviewPage::render();
	$_GET = array( 'role' => '<script>' );
	OverviewPage::render();
	$_GET = array();
	$bad_role = ob_get_clean();
	b2_check( 'overview: unknown role falls back to all roles, escaped', false === strpos( $bad_role, '<script>' ) );
	wp_set_current_user( 0 );
	ob_start();
	OverviewPage::render();
	b2_check( 'overview: nothing for users without manage_options', '' === ob_get_clean() );
	wp_set_current_user( $admin[0]->ID );

	// Overview counts.
	$snap = Report::get_snapshot( '', true );
	b2_check( 'overview: statuses partition all users', array_sum( $snap['statuses'] ) === $snap['total'] && $snap['total'] === (int) count_users()['total_users'] );
	b2_check( 'overview: counts equal PHP statuses', b2_php_counts() === $snap['statuses'] );
	$editor_snap = Report::get_snapshot( 'editor', true );
	b2_check( 'overview: role counts equal PHP statuses', b2_php_counts( 'editor' ) === $editor_snap['statuses'] && 'editor' === $editor_snap['role'] );

	$mismatch = array();
	foreach ( array( '' => $snap, 'editor' => $editor_snap ) as $role => $s ) {
		foreach ( $s['statuses'] as $status => $count ) {
			$total = (int) b2_users_list( array_filter( array( 'brain2fa_status' => $status, 'role' => $role ) ) )->get_total();
			if ( $total !== $count ) { $mismatch[] = "$role/$status $count vs $total"; }
		}
		foreach ( $s['methods'] as $method => $count ) {
			$total = (int) b2_users_list( array_filter( array( 'brain2fa_method' => $method, 'role' => $role ) ) )->get_total();
			if ( $total !== $count ) { $mismatch[] = "$role/$method $count vs $total"; }
		}
	}
	b2_check( 'Users list: filtered totals equal overview counts' . ( $mismatch ? ' (' . implode( ', ', $mismatch ) . ')' : '' ), ! $mismatch );

	$page1 = b2_users_list( array( 'brain2fa_status' => 'pending' ), array( 'number' => 1 ) );
	b2_check( 'Users list: filter applies before pagination', 1 === count( $page1->get_results() ) && $page1->get_total() === $snap['statuses']['pending'] && $snap['statuses']['pending'] >= 2 );
	b2_check( 'Users list: invalid filter value ignored', (int) b2_users_list( array( 'brain2fa_status' => 'nope' ) )->get_total() === $snap['total'] );
	$method_ids = array_map( 'intval', b2_users_list( array( 'brain2fa_method' => 'multiple' ), array( 'number' => 100 ) )->get_results() );
	b2_check( 'Users list: method filter', in_array( $u['totp_pk_ed'], $method_ids, true ) && ! in_array( $u['totp_sub'], $method_ids, true ) && ! in_array( $u['pk_ed'], $method_ids, true ) );

	$_GET = array( 'brain2fa_status' => 'overdue', 'role' => 'editor' );
	ob_start();
	$columns->render_status_filter( 'top' );
	$columns->render_status_filter( 'bottom' );
	$columns->render_filter_notice();
	$filter_html = ob_get_clean();
	$_GET = array();
	b2_check( 'Users list: filter control once, current value selected, role kept', 1 === substr_count( $filter_html, 'id="brain2fa-status-filter"' ) && (bool) preg_match( '/value="overdue"\s+selected/', $filter_html ) && false !== strpos( $filter_html, 'name="role" value="editor"' ) );
	b2_check( 'Users list: notice names the status and links back', false !== strpos( $filter_html, 'Setup overdue' ) && false !== strpos( $filter_html, 'Show all users' ) );

	// Cache.
	$cached = Report::get_snapshot();
	b2_check( 'cache: snapshot reused', $cached['generated_at'] === $snap['generated_at'] && $cached['statuses'] === $snap['statuses'] );
	update_user_meta( $u['new_ed'], 'brain2fa_grace_expires_at', $now + 5 * DAY_IN_SECONDS );
	b2_check( 'cache: dropped when a deadline starts', false === get_transient( Report::CACHE_KEY ) );
	$after = Report::get_snapshot();
	b2_check( 'cache: new counts', $after['statuses']['pending'] === $snap['statuses']['pending'] + 1 && $after['statuses']['not_started'] === $snap['statuses']['not_started'] - 1 );
	$changes = array(
		'settings change'  => function () { b2_settings( array( 'grace_period_days' => 7 ) ); },
		'role change'      => function () use ( $u ) { get_userdata( $u['plain_sub'] )->set_role( 'editor' ); },
		'method enabled'   => function () use ( $u ) { update_user_meta( $u['plain_sub'], 'brain2fa_enabled', 1 ); },
		'passkey added'    => function () use ( $u ) { R::add( $u['plain_sub'], array( 'credential_id' => 'e-' . wp_generate_password( 12, false ), 'public_key' => 'x', 'sign_count' => 0, 'transports' => array(), 'aaguid' => '', 'backup_eligible' => false, 'backed_up' => false, 'name' => 'k' ) ); },
		'passkeys removed' => function () use ( $u ) { R::delete_all_for_user( $u['plain_sub'] ); },
		'user registered'  => function () { b2_user( array( 'subscriber' ) ); },
	);
	foreach ( $changes as $label => $change ) {
		Report::get_snapshot();
		$change();
		b2_check( "cache: dropped on $label", false === get_transient( Report::CACHE_KEY ) );
	}
	b2_settings( array() );

	// WordPress caches user query results; a passkey change must not leave the filtered list stale.
	$has_passkey = function () use ( $u ) { return in_array( $u['author'], array_map( 'intval', b2_users_list( array( 'brain2fa_method' => 'passkeys' ), array( 'number' => 500 ) )->get_results() ), true ); };
	$before_add  = $has_passkey();
	$pk_id       = R::add( $u['author'], array( 'credential_id' => 'e-' . wp_generate_password( 12, false ), 'public_key' => 'x', 'sign_count' => 0, 'transports' => array(), 'aaguid' => '', 'backup_eligible' => false, 'backed_up' => false, 'name' => 'k' ) );
	$after_add   = $has_passkey();
	R::delete( $pk_id, $u['author'] );
	b2_check( 'Users list: passkey changes reach cached user queries', ! $before_add && $after_add && ! $has_passkey() );

	$deleted = b2_user( array( 'editor' ) );
	$before  = Report::get_snapshot();
	wp_delete_user( $deleted );
	$gone = Report::get_snapshot();
	b2_check( 'deleted user: cache dropped and no longer counted', $gone['total'] === $before['total'] - 1 && $gone['statuses']['not_started'] === $before['statuses']['not_started'] - 1 );

	update_user_meta( $u['pending_ed'], 'brain2fa_grace_expires_at', time() + 90 );
	$fresh = Report::get_snapshot();
	b2_check( 'freshness: snapshot expires at the earliest pending deadline', $fresh['stale_at'] <= time() + 90 && $fresh['stale_at'] > time() );
	set_transient( Report::CACHE_KEY, array( '' => array_merge( $fresh, array( 'stale_at' => time() - 1, 'total' => -5 ) ) ), HOUR_IN_SECONDS );
	b2_check( 'freshness: stale snapshot rebuilt', Report::get_snapshot()['total'] === $fresh['total'] );

	// Queries do not grow with the number of users.
	global $wpdb;
	$measure = function () use ( $wpdb ) { wp_cache_set_users_last_changed(); Report::invalidate(); $q = $wpdb->num_queries; Report::get_snapshot( '', true ); return $wpdb->num_queries - $q; };
	$small   = $measure();
	for ( $i = 0; $i < 15; $i++ ) { b2_user( array( 'editor' ), 0 === $i % 3 ? 'totp' : null, 0 === $i % 5, 0 === $i % 2 ? time() + DAY_IN_SECONDS : null ); }
	$large = $measure();
	b2_check( "overview: query count does not grow with users ($small vs $large)", $small === $large );

	$list_ids = array_slice( $GLOBALS['b2_users'], -15 );
	R::prime_counts( $list_ids );
	$q = $wpdb->num_queries;
	foreach ( $list_ids as $id ) { R::count_for_user( $id ); }
	b2_check( 'column: primed passkey counts need no queries', $wpdb->num_queries === $q );
} finally {
	restore_error_handler();
	$_GET = array();
	remove_filter( 'home_url', $https );
	remove_filter( 'site_url', $https );
	foreach ( $GLOBALS['b2_users'] as $id ) { R::delete_all_for_user( $id ); wp_delete_user( $id ); }
	remove_all_filters( 'sanitize_option_brain2fa_settings' );
	if ( $original === $missing ) { delete_option( 'brain2fa_settings' ); } else { update_option( 'brain2fa_settings', $original ); }
	delete_transient( Report::CACHE_KEY );
	echo 'Cleanup: users removed=' . ( ! array_filter( array_map( 'get_userdata', $GLOBALS['b2_users'] ) ) ? 'yes' : 'NO' ) . ', settings restored=' . ( get_option( 'brain2fa_settings', $missing ) == $original ? 'yes' : 'NO' ) . "\n";
}

$b2_failed = (int) $GLOBALS['b2_fails'];
echo $b2_failed ? "$b2_failed FAILED\n" : "ALL PASSED\n";
if ( $b2_failed ) {
	WP_CLI::halt( 1 );
}
