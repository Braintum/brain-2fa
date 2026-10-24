<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Setup reminder scheduler
 *
 * @package Brain_2FA\Enrollment
 * @since 1.2.0
 */

namespace Brain_2FA\Enrollment;

use Brain_2FA\Auth\UserLock;
use Brain_2FA\Emails\Mailer;
use Brain_2FA\Utils;
use WP_User;
use WP_User_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Emails users in required roles before their setup deadline.
 *
 * A recurring WP-Cron job scans the site's pending users (EnrollmentStatus::STATUS_PENDING) whose
 * deadline is within the longest lead time, in batches by user ID, and resumes from a cursor when a
 * run stops early. Lead times are thresholds: a user is due for the closest one their remaining time
 * has crossed, so a late run or a short grace period sends one reminder, never a backlog.
 *
 * Per-site state lives in user meta (`{blog prefix}brain2fa_reminder`): the deadline and threshold
 * last handled, when a reminder was last sent (at most one per DAY_IN_SECONDS), failed attempts, and
 * the claim of the worker sending right now. Claims are taken and settled under UserLock and expire
 * after LEASE seconds, so overlapping runs do not send twice and a crashed run does not block a user.
 * Nothing here creates a deadline; eligibility is checked again just before sending.
 *
 * @since 1.2.0
 */
class ReminderScheduler {

	/**
	 * Recurring scan.
	 *
	 * @var string
	 */
	public const HOOK = 'brain2fa_enrollment_reminders';

	/**
	 * One-off follow-up that continues a scan that stopped early.
	 *
	 * @var string
	 */
	public const CONTINUE_HOOK = 'brain2fa_enrollment_reminders_continue';

	/**
	 * Option holding the last user ID a stopped scan reached.
	 *
	 * @var string
	 */
	public const CURSOR_OPTION = 'brain2fa_reminder_cursor';

	/**
	 * User option (site-scoped user meta) holding a user's reminder state.
	 *
	 * @var string
	 */
	public const STATE_KEY = 'brain2fa_reminder';

	/**
	 * Email ID.
	 *
	 * @var string
	 */
	public const EMAIL_ID = 'enrollment_reminder';

	/**
	 * Users loaded per query.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Batches per run before the scan continues in a follow-up event.
	 *
	 * @var int
	 */
	private const MAX_BATCHES = 5;

	/**
	 * Seconds a run may spend before it stops and continues in a follow-up event.
	 *
	 * @var int
	 */
	private const TIME_BUDGET = 20;

	/**
	 * Seconds a claim stays valid.
	 *
	 * @var int
	 */
	private const LEASE = 10 * MINUTE_IN_SECONDS;

	/**
	 * Failed sends of one reminder before it is given up.
	 *
	 * @var int
	 */
	private const MAX_ATTEMPTS = 3;

	/**
	 * Register the cron callbacks and keep the schedule in line with the settings.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( self::CONTINUE_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'add_option_brain2fa_settings', array( __CLASS__, 'sync_schedule' ) );
		add_action( 'update_option_brain2fa_settings', array( __CLASS__, 'sync_schedule' ) );
		add_action( 'admin_init', array( __CLASS__, 'sync_schedule' ) );
	}

	/**
	 * Whether reminders are on and could apply.
	 *
	 * @return bool True when reminders should be sent.
	 */
	public static function is_enabled(): bool {
		return Utils::is_2fa_enabled_sitewide() && ! empty( Utils::get_settings()['enrollment_reminders'] );
	}

	/**
	 * Get the lead times, longest first.
	 *
	 * @return int[] Days before the deadline.
	 */
	public static function get_lead_days(): array {
		return self::sanitize_lead_days( Utils::get_settings()['reminder_days'] ?? array() );
	}

	/**
	 * Sanitize lead times from a list or a comma-separated string.
	 *
	 * @param mixed $value Raw value.
	 * @return int[] Unique days from 1 to 365, longest first, at most five; the defaults when none is valid.
	 */
	public static function sanitize_lead_days( $value ): array {
		$values = is_array( $value ) ? $value : explode( ',', is_scalar( $value ) ? (string) $value : '' );
		$days   = array();

		foreach ( $values as $day ) {
			$day = is_scalar( $day ) ? trim( (string) $day ) : '';
			if ( '' !== $day && ctype_digit( $day ) && (int) $day >= 1 && (int) $day <= 365 ) {
				$days[] = (int) $day;
			}
		}

		$days = array_unique( $days );
		rsort( $days );

		return $days ? array_slice( $days, 0, 5 ) : Utils::SETTINGS_DEFAULTS['reminder_days'];
	}

	/**
	 * Schedule the recurring scan while reminders are on, and remove it otherwise.
	 *
	 * @return void
	 */
	public static function sync_schedule(): void {
		if ( self::is_enabled() ) {
			if ( ! wp_next_scheduled( self::HOOK ) ) {
				wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::HOOK );
			}
			return;
		}

		self::unschedule();
	}

	/**
	 * Remove the scheduled scans of the current site.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
		wp_clear_scheduled_hook( self::CONTINUE_HOOK );
	}

	/**
	 * Remove the scheduled scans when the plugin is deactivated.
	 *
	 * @param bool $network_wide Whether the plugin was network-deactivated.
	 * @return void
	 */
	public static function deactivate( $network_wide = false ): void {
		if ( ! is_multisite() || ! $network_wide ) {
			self::unschedule();
			return;
		}

		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( (int) $site_id );
			self::unschedule();
			restore_current_blog();
		}
	}

	/**
	 * Run a scan: send the reminders that are due, within the batch and time limits.
	 *
	 * @return array{checked: int, sent: int, complete: bool} What the run did.
	 */
	public static function run(): array {
		$result = array(
			'checked'  => 0,
			'sent'     => 0,
			'complete' => true,
		);

		if ( ! self::is_enabled() ) {
			self::unschedule();
			return $result;
		}

		$lead_days = self::get_lead_days();
		$started   = time();
		$cursor    = absint( get_option( self::CURSOR_OPTION, 0 ) );

		for ( $batch = 0; $batch < self::MAX_BATCHES; $batch++ ) {
			$ids = self::get_candidates( $cursor, $started + max( $lead_days ) * DAY_IN_SECONDS );

			foreach ( $ids as $user_id ) {
				++$result['checked'];
				if ( self::process_user( (int) $user_id, $lead_days ) ) {
					++$result['sent'];
				}
			}

			if ( count( $ids ) < self::BATCH_SIZE ) {
				$cursor = 0;
				break;
			}

			$cursor = (int) end( $ids );
			if ( time() - $started >= self::TIME_BUDGET || self::MAX_BATCHES - 1 === $batch ) {
				$result['complete'] = false;
				break;
			}
		}

		update_option( self::CURSOR_OPTION, $cursor, false );

		if ( ! $result['complete'] && ! wp_next_scheduled( self::CONTINUE_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CONTINUE_HOOK );
		}

		return $result;
	}

	/**
	 * Get the closest lead time a deadline has crossed.
	 *
	 * @param int   $deadline  Setup deadline.
	 * @param int   $now       Current time.
	 * @param int[] $lead_days Lead times in days.
	 * @return int Days of the reminder that is due; 0 when none is.
	 */
	public static function get_due_threshold( int $deadline, int $now, array $lead_days ): int {
		$remaining = $deadline - $now;
		if ( $remaining <= 0 ) {
			return 0;
		}

		$due = 0;
		foreach ( $lead_days as $days ) {
			if ( $remaining <= $days * DAY_IN_SECONDS && ( 0 === $due || $days < $due ) ) {
				$due = (int) $days;
			}
		}

		return $due;
	}

	/**
	 * Get a user's reminder state on the current site.
	 *
	 * @param int $user_id User ID.
	 * @return array{deadline: int, threshold: int, last_sent: int, attempts: int, retry_at: int, claim: string, claim_expires: int} State.
	 */
	public static function get_state( int $user_id ): array {
		$state = get_user_option( self::STATE_KEY, $user_id );
		$state = is_array( $state ) ? $state : array();

		return array(
			'deadline'      => absint( $state['deadline'] ?? 0 ),
			'threshold'     => absint( $state['threshold'] ?? 0 ),
			'last_sent'     => absint( $state['last_sent'] ?? 0 ),
			'attempts'      => absint( $state['attempts'] ?? 0 ),
			'retry_at'      => absint( $state['retry_at'] ?? 0 ),
			'claim'         => isset( $state['claim'] ) && is_string( $state['claim'] ) ? $state['claim'] : '',
			'claim_expires' => absint( $state['claim_expires'] ?? 0 ),
		);
	}

	/**
	 * Get the IDs of the next users to check.
	 *
	 * @param int $after       Only users with a higher ID.
	 * @param int $deadline_by Only deadlines up to this time.
	 * @return int[] User IDs, ascending.
	 */
	private static function get_candidates( int $after, int $deadline_by ): array {
		$after_id = static function ( $query ) use ( $after ) {
			global $wpdb;
			$query->query_where .= $wpdb->prepare( ' AND %i.ID > %d', $wpdb->users, $after );
		};

		add_action( 'pre_user_query', $after_id );
		$query = new WP_User_Query(
			array(
				'fields'                    => 'ID',
				'number'                    => self::BATCH_SIZE,
				'orderby'                   => 'ID',
				'order'                     => 'ASC',
				'count_total'               => false,
				EnrollmentStatus::QUERY_VAR => EnrollmentStatus::STATUS_PENDING,
				'meta_query'                => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Limits the scan to deadlines within the longest lead time.
					array(
						'key'     => 'brain2fa_grace_expires_at',
						'value'   => $deadline_by,
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		remove_action( 'pre_user_query', $after_id );

		return array_map( 'intval', $query->get_results() );
	}

	/**
	 * Send a user the reminder that is due, if any.
	 *
	 * @param int   $user_id   User ID.
	 * @param int[] $lead_days Lead times in days.
	 * @return bool True when a reminder was sent.
	 */
	private static function process_user( int $user_id, array $lead_days ): bool {
		if ( ! UserLock::acquire( $user_id, 0.0 ) ) {
			return false;
		}

		$token = wp_generate_password( 20, false, false );

		try {
			$checked = self::check_eligibility( $user_id, $lead_days );
			if ( ! $checked ) {
				return false;
			}

			list( $user, $deadline, $due ) = $checked;
			$now                           = time();
			$state                         = self::get_state( $user_id );

			if ( ! self::is_due( $state, $deadline, $due, $now ) ) {
				return false;
			}

			if ( $state['deadline'] !== $deadline ) {
				$state = array_merge(
					$state,
					array(
						'deadline'  => $deadline,
						'threshold' => 0,
						'attempts'  => 0,
						'retry_at'  => 0,
					)
				);
			}

			$state['claim']         = $token;
			$state['claim_expires'] = $now + self::LEASE;
			update_user_option( $user_id, self::STATE_KEY, $state );
		} finally {
			UserLock::release( $user_id );
		}

		$sent = self::send( $user, $deadline );

		UserLock::acquire( $user_id );
		try {
			wp_cache_delete( $user_id, 'user_meta' );
			$state = self::get_state( $user_id );

			if ( $sent ) {
				$state = array_merge(
					$state,
					array(
						'deadline'  => $deadline,
						'threshold' => $due,
						'last_sent' => time(),
						'attempts'  => 0,
						'retry_at'  => 0,
					)
				);
			} elseif ( $state['claim'] === $token ) {
				++$state['attempts'];
				$state['retry_at'] = time() + $state['attempts'] * HOUR_IN_SECONDS;
				if ( $state['attempts'] >= self::MAX_ATTEMPTS ) {
					// Give this reminder up; a closer lead time is tried again later.
					$state['threshold'] = $due;
					$state['attempts']  = 0;
					$state['retry_at']  = 0;
				}
			}

			if ( $state['claim'] === $token ) {
				$state['claim']         = '';
				$state['claim_expires'] = 0;
			}

			update_user_option( $user_id, self::STATE_KEY, $state );
		} finally {
			UserLock::release( $user_id );
		}

		return $sent;
	}

	/**
	 * Check, from fresh data, that a user should get a reminder now.
	 *
	 * @param int   $user_id   User ID.
	 * @param int[] $lead_days Lead times in days.
	 * @return array{0: WP_User, 1: int, 2: int}|null User, deadline and due lead time; null when not eligible.
	 */
	private static function check_eligibility( int $user_id, array $lead_days ): ?array {
		clean_user_cache( $user_id );
		wp_cache_delete( $user_id, 'user_meta' );

		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! is_user_member_of_blog( $user_id ) || ! self::is_enabled() ) {
			return null;
		}

		if ( EnrollmentStatus::STATUS_PENDING !== EnrollmentStatus::get_status( $user ) ) {
			return null;
		}

		$deadline = EnrollmentStatus::get_deadline( $user_id );
		$due      = self::get_due_threshold( $deadline, time(), $lead_days );

		return $due ? array( $user, $deadline, $due ) : null;
	}

	/**
	 * Whether the due reminder may be sent, given what was already sent or tried.
	 *
	 * @param array $state    Reminder state.
	 * @param int   $deadline Current deadline.
	 * @param int   $due      Due lead time in days.
	 * @param int   $now      Current time.
	 * @return bool True when the reminder may be sent.
	 */
	private static function is_due( array $state, int $deadline, int $due, int $now ): bool {
		if ( '' !== $state['claim'] && $state['claim_expires'] > $now ) {
			return false;
		}

		// At most one reminder a day, also across deadline extensions.
		if ( $state['last_sent'] && $now - $state['last_sent'] < DAY_IN_SECONDS ) {
			return false;
		}

		if ( $state['deadline'] !== $deadline ) {
			return true;
		}

		// This lead time or a closer one was already handled for this deadline.
		if ( $state['threshold'] && $state['threshold'] <= $due ) {
			return false;
		}

		return ! $state['attempts'] || $state['retry_at'] <= $now;
	}

	/**
	 * Send the reminder in the recipient's language.
	 *
	 * @param WP_User $user     Recipient.
	 * @param int     $deadline Setup deadline.
	 * @return bool True when the mail system accepted the email.
	 */
	private static function send( WP_User $user, int $deadline ): bool {
		$switched = switch_to_user_locale( $user->ID );

		try {
			return Mailer::send(
				self::EMAIL_ID,
				$user,
				array(
					'setup_deadline' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $deadline ),
					'days_remaining' => max( 1, (int) ceil( ( $deadline - time() ) / DAY_IN_SECONDS ) ),
				)
			);
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}
}
