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
	 * Add a job to the queue.
	 *
	 * @param string $pipeline Pipeline name.
	 * @param string $stage    Starting stage.
	 * @param array  $payload  Initial payload.
	 * @return int New job id.
	 */
	public static function enqueue( $pipeline, $stage, $payload = array() ) {
		global $wpdb;

		$now = current_time( 'mysql', true );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * Apply an update to one job, always stamping updated_at.
	 *
	 * @param int   $job_id Job id.
	 * @param array $data   Column => value pairs.
	 * @return void
	 */
	private static function update( $job_id, $data ) {
		global $wpdb;

		$data['updated_at'] = current_time( 'mysql', true );

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Blogcraft_Migrator::table_name( 'jobs' ),
			$data,
			array( 'id' => (int) $job_id )
		);
	}
}
