<?php
/**
 * Pipeline worker.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Executes queued pipeline stages within a wall-clock time budget.
 *
 * Each claimed job runs exactly one stage before being released or
 * requeued; the worker then loops, claiming and running further jobs
 * (which may be the same job's next stage) until the time budget is spent.
 * Because no single claim ever advances a job past one stage, a
 * multi-minute generation pipeline can still survive a 30-second PHP
 * max_execution_time on shared hosting: no individual request ever has to
 * finish the whole pipeline, even though one request may finish several
 * stages across several jobs.
 */
class Blogcraft_Worker {

	/**
	 * Registered stage handlers, keyed "pipeline:stage".
	 *
	 * @var array
	 */
	private static $stages = array();

	/**
	 * Register a handler for one pipeline stage.
	 *
	 * @param string   $pipeline Pipeline name.
	 * @param string   $stage    Stage name.
	 * @param callable $handler  Receives Blogcraft_Job, returns array with
	 *                           'next' (string|null) and 'payload' (array).
	 *                           If handler throws any Throwable, the job is failed.
	 * @return void
	 */
	public static function register_stage( $pipeline, $stage, $handler ) {
		self::$stages[ $pipeline . ':' . $stage ] = $handler;
	}

	/**
	 * Forget all registered stages. Test support.
	 *
	 * @return void
	 */
	public static function reset_stages() {
		self::$stages = array();
	}

	/**
	 * Drain the queue until the time budget is spent.
	 *
	 * The budget is checked only between stages, after each one completes —
	 * never while a stage is running. A stage that overruns can therefore
	 * push the actual elapsed time past $budget_seconds. Callers must set
	 * the budget well below PHP's max_execution_time to leave headroom for
	 * the last stage to finish before the process is killed.
	 *
	 * @param int|null $budget_seconds Wall-clock budget; null uses the setting.
	 * @return int Number of stages executed.
	 */
	public static function run( $budget_seconds = null ) {
		if ( null === $budget_seconds ) {
			$budget_seconds = (int) Blogcraft_Settings::get( 'queue_time_budget' );
		}

		// Recorded here rather than in the WP-Cron callback, because that was
		// only one of three ways the queue gets drained. The plugin's own
		// documentation recommends driving it from a real system cron via
		// `wp dicecodes run` — and on exactly that setup the heartbeat was
		// never written, so the health check decided the queue had stopped and
		// showed "Blogcraft has not processed its queue recently" forever,
		// while the queue was in fact being processed on schedule.
		Blogcraft_Cron_Health::record_heartbeat();

		$started  = time();
		$executed = 0;

		do {
			$job = Blogcraft_Queue::claim();

			if ( null === $job ) {
				break;
			}

			self::execute( $job );
			++$executed;

		} while ( ( time() - $started ) < $budget_seconds );

		return $executed;
	}

	/**
	 * Advance one named job by exactly one stage.
	 *
	 * For a person watching their post being written, rather than a cron tick
	 * draining whatever is queued. Runs in the browser's request, so it works
	 * on a site where WP-Cron never fires — which is most staging
	 * environments, and a good number of quiet live ones.
	 *
	 * @param int $job_id Job to advance.
	 * @return bool Whether a stage was executed.
	 */
	public static function run_job( $job_id ) {
		$job = Blogcraft_Queue::claim_job( (int) $job_id );

		if ( null === $job ) {
			return false;
		}

		Blogcraft_Cron_Health::record_heartbeat();
		self::execute( $job );

		return true;
	}

	/**
	 * Run one job's current stage.
	 *
	 * @param Blogcraft_Job $job Claimed job.
	 * @return void
	 */
	private static function execute( Blogcraft_Job $job ) {
		$key = $job->pipeline . ':' . $job->stage;

		if ( ! isset( self::$stages[ $key ] ) ) {
			Blogcraft_Queue::fail( $job->id, 'No handler registered for stage ' . $key );

			return;
		}

		try {
			$result = call_user_func( self::$stages[ $key ], $job );
		} catch ( Blogcraft_Rate_Limited $e ) {
			// A provider saying "too many requests" is asking us to come back,
			// not telling us the work is wrong. Spending attempts on it loses
			// whatever the job had already written. Recognised by type: this
			// used to search the message for "HTTP 429", which is assembled
			// from a translated format string and therefore stopped matching
			// on any site not running in English.
			Blogcraft_Queue::defer( $job->id, 30 * MINUTE_IN_SECONDS, $e->getMessage() );

			Blogcraft_Logger::info(
				'Provider is rate limiting; the job will wait rather than fail.',
				array( 'reason' => $e->getMessage() ),
				(int) $job->id
			);

			return true;
		} catch ( Throwable $e ) {
			Blogcraft_Queue::fail( $job->id, $e->getMessage() );

			return;
		}

		$next    = isset( $result['next'] ) ? $result['next'] : null;
		$payload = isset( $result['payload'] ) && is_array( $result['payload'] ) ? $result['payload'] : array();

		// A held job has already written its own row and is deliberately not
		// finished — it is waiting for a person. Marking it complete here
		// would erase that distinction and lose the draft.
		if ( ! empty( $result['held'] ) ) {
			return;
		}

		if ( null === $next || '' === $next ) {
			Blogcraft_Queue::complete( $job->id );

			return;
		}

		Blogcraft_Queue::advance( $job->id, $next, $payload );
	}
}
