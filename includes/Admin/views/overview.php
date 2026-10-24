<?php
/**
 * Overview page template
 *
 * Rendered by OverviewPage::render(), whose local variables are in scope here.
 *
 * @package Brain_2FA\Admin
 * @since 1.2.0
 */

use Brain_2FA\Admin\OverviewPage;
use Brain_2FA\Enrollment\EnrollmentStatus;

defined( 'ABSPATH' ) || exit;

$brain2fa_highlights = array( EnrollmentStatus::STATUS_PROTECTED, EnrollmentStatus::STATUS_PENDING, EnrollmentStatus::STATUS_OVERDUE, EnrollmentStatus::STATUS_NOT_STARTED );
$brain2fa_statuses   = array_filter(
	$snapshot['statuses'],
	static fn( $count, $status ) => $count > 0 || in_array( $status, $brain2fa_highlights, true ),
	ARRAY_FILTER_USE_BOTH
);
?>
<div class="wrap brain2fa-overview">
	<h1><?php esc_html_e( 'Overview', 'brain-2fa' ); ?></h1>
	<p class="brain2fa-overview-intro">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: date and time the counts were made */
				__( 'How your users are protected. Counts from %s.', 'brain-2fa' ),
				wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $snapshot['generated_at'] )
			)
		);
		?>
		<a href="<?php echo esc_url( $refresh_url ); ?>"><?php esc_html_e( 'Refresh', 'brain-2fa' ); ?></a>
	</p>

	<?php if ( ! $policy['enabled'] ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e( 'Two-factor authentication is off for this site. Nobody is asked for a second step and no setup deadlines apply. Methods your users set up stay saved, and passkey sign-in keeps working.', 'brain-2fa' ); ?>
				<a href="<?php echo esc_url( $methods_url ); ?>"><?php esc_html_e( 'Authentication methods', 'brain-2fa' ); ?></a>
			</p>
		</div>
	<?php elseif ( ! $policy['required_roles'] ) : ?>
		<div class="notice notice-info inline">
			<p>
				<?php esc_html_e( 'No role is required to use two-factor authentication, so nobody has a setup deadline.', 'brain-2fa' ); ?>
				<a href="<?php echo esc_url( $requirement_url ); ?>"><?php esc_html_e( 'Enrollment requirements', 'brain-2fa' ); ?></a>
			</p>
		</div>
	<?php elseif ( ! $policy['can_enforce'] ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<?php esc_html_e( 'The authenticator app and email codes are both turned off, so users in required roles cannot be made to set up two-factor authentication.', 'brain-2fa' ); ?>
				<a href="<?php echo esc_url( $methods_url ); ?>"><?php esc_html_e( 'Authentication methods', 'brain-2fa' ); ?></a>
			</p>
		</div>
	<?php endif; ?>

	<?php if ( $policy['passkeys_setting'] && ! $policy['passkeys_usable'] ) : ?>
		<div class="notice notice-info inline">
			<p><?php esc_html_e( 'Passkeys are turned on but need a secure (HTTPS) connection. Stored passkeys do not count as protection until the site uses HTTPS.', 'brain-2fa' ); ?></p>
		</div>
	<?php endif; ?>

	<form method="get" class="brain2fa-overview-filter">
		<input type="hidden" name="page" value="<?php echo esc_attr( OverviewPage::SLUG ); ?>">
		<label for="brain2fa-overview-role"><?php esc_html_e( 'Role', 'brain-2fa' ); ?></label>
		<select id="brain2fa-overview-role" name="role">
			<option value=""><?php esc_html_e( 'All roles', 'brain-2fa' ); ?></option>
			<?php foreach ( $role_names as $brain2fa_role => $brain2fa_role_name ) : ?>
				<option value="<?php echo esc_attr( $brain2fa_role ); ?>" <?php selected( $role, $brain2fa_role ); ?>><?php echo esc_html( translate_user_role( $brain2fa_role_name ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button"><?php esc_html_e( 'Show', 'brain-2fa' ); ?></button>
		<span class="brain2fa-overview-total">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of users */
					_n( '%s user', '%s users', $snapshot['total'], 'brain-2fa' ),
					number_format_i18n( $snapshot['total'] )
				)
			);
			?>
		</span>
	</form>

	<?php if ( $policy['enabled'] ) : ?>
		<ul class="brain2fa-overview-highlights">
			<?php foreach ( $brain2fa_highlights as $brain2fa_status ) : ?>
				<li class="brain2fa-card brain2fa-overview-stat brain2fa-overview-stat-<?php echo esc_attr( $brain2fa_status ); ?>">
					<a href="<?php echo esc_url( OverviewPage::get_users_url( $role, EnrollmentStatus::QUERY_VAR, $brain2fa_status ) ); ?>">
						<span class="brain2fa-overview-count"><?php echo esc_html( number_format_i18n( $snapshot['statuses'][ $brain2fa_status ] ) ); ?></span>
						<span class="brain2fa-overview-label"><?php echo esc_html( $status_labels[ $brain2fa_status ] ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>

	<section class="brain2fa-card" aria-labelledby="brain2fa-overview-status-title">
		<h2 id="brain2fa-overview-status-title" class="brain2fa-card-title"><?php esc_html_e( 'Users by status', 'brain-2fa' ); ?></h2>
		<p class="brain2fa-card-intro"><?php esc_html_e( 'Each user is counted once, under the status that applies to them now.', 'brain-2fa' ); ?></p>
		<table class="widefat striped brain2fa-overview-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Status', 'brain-2fa' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Users', 'brain-2fa' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Meaning', 'brain-2fa' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $brain2fa_statuses as $brain2fa_status => $brain2fa_count ) : ?>
					<?php
					if ( ! $policy['enabled'] && EnrollmentStatus::STATUS_INACTIVE !== $brain2fa_status ) {
						continue;
					}
					?>
					<tr>
						<th scope="row"><?php echo esc_html( $status_labels[ $brain2fa_status ] ); ?></th>
						<td class="num"><a href="<?php echo esc_url( OverviewPage::get_users_url( $role, EnrollmentStatus::QUERY_VAR, $brain2fa_status ) ); ?>"><?php echo esc_html( number_format_i18n( $brain2fa_count ) ); ?></a></td>
						<td><?php echo esc_html( OverviewPage::get_status_description( $brain2fa_status ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<section class="brain2fa-card" aria-labelledby="brain2fa-overview-methods-title">
		<h2 id="brain2fa-overview-methods-title" class="brain2fa-card-title"><?php esc_html_e( 'Methods set up', 'brain-2fa' ); ?></h2>
		<p class="brain2fa-card-intro"><?php esc_html_e( 'Users with more than one method appear in each of their rows. These counts include methods the site has turned off and passkeys that cannot be used right now.', 'brain-2fa' ); ?></p>
		<table class="widefat striped brain2fa-overview-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Method', 'brain-2fa' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Users', 'brain-2fa' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $snapshot['methods'] as $brain2fa_method => $brain2fa_count ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $method_labels[ $brain2fa_method ] ?? $brain2fa_method ); ?></th>
						<td class="num"><a href="<?php echo esc_url( OverviewPage::get_users_url( $role, EnrollmentStatus::METHOD_QUERY_VAR, $brain2fa_method ) ); ?>"><?php echo esc_html( number_format_i18n( $brain2fa_count ) ); ?></a></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
</div>
