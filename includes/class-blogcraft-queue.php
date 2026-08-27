<?php
/**
 * Job queue.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database-backed work queue with pessimistic locking.
 *
 * Claiming writes a random lock token in a conditional UPDATE and then reads
 * the row back by that token. Two concurrent cron runs therefore cannot claim
 * the same job: only one UPDATE can match the pending row.
 */
class Blogcraft_Queue {

	/**
	 * How long a job may sit in 'running' before it is considered stranded.
	 *
	 * A job this old is presumed to have died with a PHP fatal, an OOM kill,
	 * or a killed FPM worker, rather than merely being slow — so this has to
	 * sit above the slowest stage that can legitimately still be working.
	 *
	 * The old value of 600 did not. Adding up the worst case a single stage
	 * is allowed to reach, from the constants that actually govern it:
	 *
	 * - a provider call is 3 attempts at a 60s timeout, plus two waits capped
	 *   at 30s each (Blogcraft_Http::MAX_ATTEMPTS, MAX_RETRY_AFTER_SECONDS),
	 *   so 240s for one call
	 * - research adds a search on the same budget plus up to MAX_SOURCES page
	 *   fetches at 12s
	 * - verify HEADs up to MAX_LINKS addresses at 8s, so ~96s
	 * - publishing sideloads up to four pictures at a 45s download each,
	 *   after generating them
	 *
	 * Any one of those can pass 600s on a slow host without anything being
	 * wrong. Reclaiming then does not rescue a dead job: it starts a second
	 * copy of a live one, doubling what the provider is asked to bill and,
	 * before the guard in Pipeline::stage_publish, publishing the post twice.
	 * An hour is comfortably past every figure above, and the cost of waiting
	 * too long is a job that looks stuck for a while — visible in Activity,
	 * and recoverable — against a cost of being too eager that is silent.
	 */
	const RECLAIM_AFTER_SECONDS = 3600;

	/**
	 * Wpdb format specifier for each column update() may write.
	 *
	 * Without this, $wpdb->update() falls back to '%s' for every value,
	 * which round-trips integer columns like attempts as strings.
	 *
	 * @var array
	 */
	private static $column_formats = array(
		'pipeline'     => '%s',
		'stage'        => '%s',
		'status'       => '%s',
		'payload'      => '%s',
		'attempts'     => '%d',
		'max_attempts' => '%d',
		'available_at' => '%s',
		'locked_at'    => '%s',
		'lock_token'   => '%s',
		'last_error'   => '%s',
		'created_at'   => '%s',
		'updated_at'   => '%s',
	);

	/**
	 * Add a job to the queue.
	 *
	 * @param string $pipeline Pipeline name.
	 * @param string $stage    Starting stage.
	 * @param array  $payload  Initial payload.
	 * @return int New job id, or 0 if the insert failed.
	 */
	public static function enqueue( $pipeline, $stage, $payload = array() ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Blogcraft_Migrator::table_name( 'jobs' ),
			array(
				'pipeline'     => (string) $pipeline,
				'stage'        => (string) $stage,
				'status'       => 'pending',
				'payload'      => wp_json_encode( $payload ),
				'attempts'     => 0,
				'max_attempts' => (int) Blogcraft_Settings::get( 'queue_max_attempts' ),
				'available_at' => $now,
				'created_at'   => $now,
				'updated_at'   => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			// wpdb bails out before refreshing insert_id on failure, so it
			// would otherwise still hold the id of the last successful
			// insert on this connection — a plausible but wrong job id.
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Claim the next available job.
	 *
	 * @return Blogcraft_Job|null Null when nothing is ready to run.
	 */
	public static function claim() {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$token = wp_generate_password( 32, false );
		$now   = current_time( 'mysql', true );

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'running', lock_token = %s, locked_at = %s, updated_at = %s WHERE status = 'pending' AND available_at <= %s ORDER BY id ASC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token,
				$now,
				$now,
				$now
			)
		);

		if ( ! $updated ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE lock_token = %s LIMIT 1", $token ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? Blogcraft_Job::from_row( $row ) : null;
	}

	/**
	 * Claim one specific job, rather than whatever is next in line.
	 *
	 * The ordinary claim() takes the oldest pending job, which is right for a
	 * cron tick draining a queue. It is wrong for a person watching one post
	 * being written: they pressed a button about *this* post, and advancing
	 * somebody else's would leave their screen reporting progress that is not
	 * theirs. Same atomic conditional UPDATE, narrowed to one row.
	 *
	 * @param int $job_id Job to claim.
	 * @return Blogcraft_Job|null Null when it is not available to claim.
	 */
	public static function claim_job( $job_id ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$token = wp_generate_password( 32, false );
		$now   = current_time( 'mysql', true );

		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'running', lock_token = %s, locked_at = %s, updated_at = %s WHERE id = %d AND status = 'pending' AND available_at <= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$token,
				$now,
				$now,
				(int) $job_id,
				$now
			)
		);

		if ( ! $updated ) {
			return null;
		}

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE lock_token = %s LIMIT 1", $token ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? Blogcraft_Job::from_row( $row ) : null;
	}

	/**
	 * One job, whatever state it is in.
	 *
	 * @param int $job_id Job id.
	 * @return Blogcraft_Job|null
	 */
	public static function find( $job_id ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $job_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return is_array( $row ) ? Blogcraft_Job::from_row( $row ) : null;
	}

	/**
	 * Park a finished draft and wait for a person to look at it.
	 *
	 * The distinction this draws is the one the plugin was missing: a job can
	 * be *done writing* without being *done*. Everything up to and including
	 * verify has run, the article and its score are on the payload, and the
	 * only thing left is a decision nobody but the author can make. The stage
	 * is left pointing at publish, so approving is just letting it continue.
	 *
	 * @param int   $job_id  Job id.
	 * @param array $payload Payload carrying the finished article.
	 * @return void
	 */
	public static function hold( $job_id, $payload ) {
		self::update(
			$job_id,
			array(
				'status'     => 'ready',
				'stage'      => 'publish',
				'payload'    => wp_json_encode( $payload ),
				'lock_token' => null,
				'locked_at'  => null,
			)
		);
	}

	/**
	 * Let a held job finish.
	 *
	 * @param int $job_id Job id.
	 * @return bool Whether it was released.
	 */
	public static function approve( $job_id ) {
		$job = self::find( $job_id );

		if ( null === $job || 'ready' !== $job->status ) {
			return false;
		}

		self::update(
			$job_id,
			array(
				'status'       => 'pending',
				'available_at' => current_time( 'mysql', true ),
			)
		);

		return true;
	}

	/**
	 * Mark a job finished.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public static function complete( $job_id ) {
		self::update(
			$job_id,
			array(
				'status'     => 'complete',
				'lock_token' => null,
				'locked_at'  => null,
			)
		);
	}

	/**
	 * Move a job to its next stage and return it to the queue.
	 *
	 * @param int    $job_id     Job id.
	 * @param string $next_stage Stage to run next.
	 * @param array  $payload    Payload to carry forward.
	 * @return void
	 */
	public static function advance( $job_id, $next_stage, $payload = array() ) {
		self::update(
			$job_id,
			array(
				'status'       => 'pending',
				'stage'        => (string) $next_stage,
				'payload'      => wp_json_encode( $payload ),
				'attempts'     => 0,
				'lock_token'   => null,
				'locked_at'    => null,
				'available_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Write a job's payload without moving it off its current stage.
	 *
	 * Payloads are normally persisted once, when a stage returns. That is
	 * fine for work that can simply be redone, and wrong for anything a stage
	 * does to the outside world part-way through: if the stage dies after
	 * that and the job is later reclaimed, the re-run has no memory of what
	 * already happened. Publishing is the case that matters — it inserts a
	 * post and then spends minutes fetching images — so it records the post
	 * id here the moment it exists, rather than waiting to return.
	 *
	 * @param int   $job_id  Job id.
	 * @param array $payload Payload to store.
	 * @return void
	 */
	public static function save_payload( $job_id, $payload ) {
		self::update( $job_id, array( 'payload' => wp_json_encode( $payload ) ) );
	}

	/**
	 * Record a failed attempt, retrying with exponential backoff.
	 *
	 * @param int    $job_id Job id.
	 * @param string $error  Error message.
	 * @return void
	 */
	public static function fail( $job_id, $error ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT attempts, max_attempts FROM {$table} WHERE id = %d", $job_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return;
		}

		$attempts  = (int) $row['attempts'] + 1;
		$exhausted = $attempts >= (int) $row['max_attempts'];

		Blogcraft_Logger::error( $error, array( 'attempt' => $attempts ), (int) $job_id );

		if ( $exhausted ) {
			self::update(
				$job_id,
				array(
					'status'     => 'failed',
					'attempts'   => $attempts,
					'last_error' => (string) $error,
					'lock_token' => null,
					'locked_at'  => null,
				)
			);

			return;
		}

		// Backoff: 60s, 120s, 240s ...
		$delay = 60 * pow( 2, $attempts - 1 );

		self::update(
			$job_id,
			array(
				'status'       => 'pending',
				'attempts'     => $attempts,
				'last_error'   => (string) $error,
				'lock_token'   => null,
				'locked_at'    => null,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
			)
		);
	}

	/**
	 * Return a claimed job to the pending pool without counting an attempt.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	public static function release( $job_id ) {
		self::update(
			$job_id,
			array(
				'status'     => 'pending',
				'lock_token' => null,
				'locked_at'  => null,
			)
		);
	}

	/**
	 * Put a job aside for a while without spending an attempt.
	 *
	 * A rate limit is not a failure, it is a wait. Treating it as a failure
	 * burns all three attempts inside a couple of minutes on a quota that
	 * resets in hours, and throws away an article that was nearly finished.
	 *
	 * @param int    $job_id  Job to defer.
	 * @param int    $seconds How long to wait.
	 * @param string $reason  What to show on the Activity screen meanwhile.
	 * @return void
	 */
	public static function defer( $job_id, $seconds, $reason = '' ) {
		self::update(
			$job_id,
			array(
				'status'       => 'pending',
				'last_error'   => (string) $reason,
				'lock_token'   => null,
				'locked_at'    => null,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + max( 60, (int) $seconds ) ),
			)
		);
	}

	/**
	 * Return stranded 'running' jobs to the queue.
	 *
	 * Claim() locks a row by setting status = 'running' and stamping
	 * locked_at, but nothing previously read locked_at back — a job that
	 * survives a PHP fatal error (exceeding max_execution_time, an OOM
	 * kill, a killed FPM worker) rather than a thrown exception stranded in
	 * 'running' forever, since Blogcraft_Worker's own try/catch cannot see
	 * it. This finds jobs locked longer than the cutoff and either requeues
	 * them as 'pending' or, once they have exhausted their attempts, fails
	 * them outright.
	 *
	 * @param int|null $older_than_seconds Cutoff age; null uses RECLAIM_AFTER_SECONDS.
	 * @return int Number of jobs reclaimed.
	 */
	public static function reclaim_stale( $older_than_seconds = null ) {
		global $wpdb;

		if ( null === $older_than_seconds ) {
			$older_than_seconds = self::RECLAIM_AFTER_SECONDS;
		}

		$table  = Blogcraft_Migrator::table_name( 'jobs' );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) $older_than_seconds );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id, attempts, max_attempts FROM {$table} WHERE status = 'running' AND locked_at < %s", $cutoff ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return 0;
		}

		$message = __( 'Job reclaimed after an interrupted run.', 'blogcraft' );

		foreach ( $rows as $row ) {
			$job_id       = (int) $row['id'];
			$attempts     = (int) $row['attempts'] + 1;
			$max_attempts = (int) $row['max_attempts'];

			if ( $attempts >= $max_attempts ) {
				self::update(
					$job_id,
					array(
						'status'     => 'failed',
						'attempts'   => $attempts,
						'last_error' => $message,
						'lock_token' => null,
						'locked_at'  => null,
					)
				);
			} else {
				self::update(
					$job_id,
					array(
						'status'     => 'pending',
						'attempts'   => $attempts,
						'last_error' => $message,
						'lock_token' => null,
						'locked_at'  => null,
					)
				);
			}
		}

		Blogcraft_Logger::error( $message, array( 'count' => count( $rows ) ) );

		return count( $rows );
	}

	/**
	 * Count jobs in a given status.
	 *
	 * @param string $status Status value.
	 * @return int
	 */
	public static function count_by_status( $status ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Topics on jobs that have not finished yet.
	 *
	 * The duplicate check only ever compared a new topic against published
	 * posts, so pasting a list with the same line twice queued it twice, and
	 * nothing had been published yet to catch it. Two jobs then wrote two posts
	 * on one subject, which is the exact outcome the check exists to prevent.
	 *
	 * @return array Topic strings.
	 */
	public static function pending_topics() {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT payload FROM {$table} WHERE status IN ('pending', 'running')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		$topics = array();

		foreach ( (array) $rows as $payload ) {
			$decoded = json_decode( (string) $payload, true );

			if ( is_array( $decoded ) && ! empty( $decoded['topic'] ) ) {
				$topics[] = (string) $decoded['topic'];
			}
		}

		return $topics;
	}

	/**
	 * How many jobs are currently carrying an error.
	 *
	 * A failing job goes back to pending on its backoff, so a plain step count
	 * reads as success when nothing actually worked.
	 *
	 * @return int
	 */
	public static function count_with_errors() {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$table} WHERE last_error IS NOT NULL AND last_error != ''" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Put an exhausted job back in the pending pool.
	 *
	 * Resets the attempt counter, because the reason a job ran out of attempts
	 * is nearly always something the user has since changed — a corrected model
	 * id, a fresh key — and making them delete and retype the topic to get one
	 * more try would be the wrong trade.
	 *
	 * @param int $job_id Job to revive.
	 * @return bool Whether a failed job was found and requeued.
	 */
	public static function cancel( $job_id ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		$status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $job_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// Only something not yet being worked on. A job mid-stage holds a lock
		// and is part-way through spending money; stopping it there would leave
		// a half-written article and a lock nobody releases. Anything running
		// finishes, and the reader can cancel it on the next tick if they still
		// want to.
		//
		// 'ready' belongs here too: a finished draft waiting to be read holds
		// no lock and is spending nothing, and discarding one is the whole
		// point of the Discard button on the library screen. Leaving it out
		// made that button silently do nothing.
		if ( ! in_array( (string) $status, array( 'pending', 'deferred', 'failed', 'ready' ), true ) ) {
			return false;
		}

		self::update(
			$job_id,
			array(
				'status'     => 'cancelled',
				'last_error' => null,
				'lock_token' => null,
				'locked_at'  => null,
			)
		);

		return true;
	}

	/**
	 * Put a failed job back in the queue.
	 *
	 * @param int $job_id Job to retry.
	 * @return bool Whether it was requeued.
	 */
	/**
	 * Send a finished draft back for another pass.
	 *
	 * Only a held one. A job still working is already moving, and a
	 * completed one has a post on the site that this would not touch.
	 *
	 * @param int    $job_id Job to reopen.
	 * @param string $stage  Stage to resume at.
	 * @return bool Whether it was reopened.
	 */
	public static function reopen( $job_id, $stage ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		$status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $job_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( 'ready' !== $status ) {
			return false;
		}

		self::update(
			$job_id,
			array(
				'status'       => 'pending',
				'stage'        => (string) $stage,
				'attempts'     => 0,
				'last_error'   => null,
				'lock_token'   => null,
				'locked_at'    => null,
				'available_at' => current_time( 'mysql', true ),
			)
		);

		return true;
	}

	public static function requeue( $job_id ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		$status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $job_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( 'failed' !== $status ) {
			return false;
		}

		self::update(
			$job_id,
			array(
				'status'       => 'pending',
				'attempts'     => 0,
				'last_error'   => null,
				'lock_token'   => null,
				'locked_at'    => null,
				'available_at' => current_time( 'mysql', true ),
			)
		);

		return true;
	}

	/**
	 * The newest jobs, whatever their status.
	 *
	 * Feeds the Activity screen. A job that keeps failing is otherwise invisible:
	 * it drops back to pending on its backoff and the counts look idle.
	 *
	 * @param int $limit Maximum rows.
	 * @return array Rows as associative arrays, newest first.
	 */
	public static function recent_jobs( $limit = 25 ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, pipeline, stage, status, attempts, max_attempts, last_error, payload, available_at, created_at, updated_at FROM {$table} ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every draft that finished writing and is still waiting to be read.
	 *
	 * These are the ones with nowhere else to appear: the writing is paid for
	 * and complete, but no post exists yet, so nothing in WordPress lists them
	 * and closing the tab makes them effectively invisible. Newest first.
	 *
	 * @param int $limit Most to return.
	 * @return array Rows, newest first.
	 */
	public static function held_jobs( $limit = 50 ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, pipeline, stage, status, attempts, max_attempts, last_error, payload, created_at, updated_at FROM {$table} WHERE status = 'ready' ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				(int) $limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The newest job that has not finished with the reader yet.
	 *
	 * The progress screen is reached by an id in the address, and it has no
	 * menu entry of its own. So refreshing without that id, or coming back
	 * to the tab later, landed on "there is no post here" while the post was
	 * still being written — and nothing anywhere linked back to it.
	 *
	 * Ready counts as unfinished: the draft is written but it is waiting for
	 * somebody to decide, which is the state it is most annoying to lose.
	 *
	 * @return int Job id, or 0 when nothing is in flight.
	 */
	public static function newest_open_job() {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT id FROM {$table} WHERE status IN ( 'ready', 'running', 'pending' ) ORDER BY id DESC LIMIT 1" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return (int) $id;
	}
	/**
	 * The provider's own words, if it has recently asked us to slow down.
	 *
	 * There is no general way to ask a provider how much quota is left —
	 * most do not expose it, and the one place a free-tier limit is ever
	 * stated is inside the error returned after exceeding it. So rather than
	 * inventing a number, this reports the last real refusal and when the
	 * affected job resumes, which is the only quota fact actually in hand.
	 *
	 * @return array Empty when nothing is waiting on a limit; otherwise
	 *               keys: resumes (timestamp), reason (string).
	 */
	public static function rate_limited_until() {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT available_at, last_error FROM {$table} WHERE status = 'pending' AND available_at > %s ORDER BY available_at DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true )
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || empty( $row['available_at'] ) ) {
			return array();
		}

		$at = strtotime( (string) $row['available_at'] . ' UTC' );

		if ( false === $at || $at <= time() + 60 ) {
			return array();
		}

		return array(
			'resumes' => $at,
			'reason'  => isset( $row['last_error'] ) ? (string) $row['last_error'] : '',
		);
	}

	/**
	 * How many drafts are waiting to be read.
	 *
	 * @return int
	 */
	public static function held_count() {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'jobs' );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"SELECT COUNT(*) FROM {$table} WHERE status = 'ready'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Apply an update to one job, always stamping updated_at.
	 *
	 * @param int   $job_id Job id.
	 * @param array $data   Column => value pairs.
	 * @return void
	 */
	private static function update( $job_id, $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		$formats = array();

		foreach ( array_keys( $data ) as $column ) {
			$formats[] = isset( self::$column_formats[ $column ] ) ? self::$column_formats[ $column ] : '%s';
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Blogcraft_Migrator::table_name( 'jobs' ),
			$data,
			array( 'id' => (int) $job_id ),
			$formats,
			array( '%d' )
		);
	}
}
