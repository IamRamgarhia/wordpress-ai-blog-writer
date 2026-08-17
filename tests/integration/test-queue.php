<?php
/**
 * Queue tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Queue extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		global $wpdb;
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		// TRUNCATE is DDL and forces an implicit commit, which would collapse
		// WP_UnitTestCase's transaction/savepoint rollback isolation between
		// tests. DELETE FROM is DML and keeps that isolation intact.
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_enqueue_returns_job_id() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array( 'topic' => 'coffee' ) );
		$this->assertGreaterThan( 0, $id );
	}

	public function test_enqueue_creates_pending_job() {
		Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_claim_returns_job_with_payload() {
		Blogcraft_Queue::enqueue( 'write_post', 'research', array( 'topic' => 'coffee' ) );
		$job = Blogcraft_Queue::claim();
		$this->assertInstanceOf( 'Blogcraft_Job', $job );
		$this->assertSame( 'write_post', $job->pipeline );
		$this->assertSame( 'research', $job->stage );
		$this->assertSame( array( 'topic' => 'coffee' ), $job->payload );
	}

	public function test_claim_marks_job_running() {
		Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'running' ) );
		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_claim_returns_null_when_queue_empty() {
		$this->assertNull( Blogcraft_Queue::claim() );
	}

	public function test_claim_does_not_return_an_already_claimed_job() {
		Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		$this->assertNull( Blogcraft_Queue::claim() );
	}

	public function test_complete_marks_job_complete() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		Blogcraft_Queue::complete( $id );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'complete' ) );
	}

	public function test_advance_moves_job_to_next_stage_and_requeues() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		Blogcraft_Queue::advance( $id, 'draft', array( 'sources' => 3 ) );

		$job = Blogcraft_Queue::claim();
		$this->assertSame( 'draft', $job->stage );
		$this->assertSame( array( 'sources' => 3 ), $job->payload );
	}

	public function test_fail_requeues_job_with_incremented_attempts() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		Blogcraft_Queue::fail( $id, 'network timeout' );

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_fail_marks_job_failed_after_max_attempts() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );

		for ( $i = 0; $i < 3; $i++ ) {
			global $wpdb;
			$table = Blogcraft_Migrator::table_name( 'jobs' );
			$wpdb->query( "UPDATE {$table} SET available_at = '2000-01-01 00:00:00'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			Blogcraft_Queue::claim();
			Blogcraft_Queue::fail( $id, 'network timeout' );
		}

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'failed' ) );
	}

	public function test_release_returns_job_to_pending() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		Blogcraft_Queue::release( $id );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	/**
	 * Backdate a job's locked_at so it looks stranded to reclaim_stale().
	 *
	 * @param int $job_id  Job id.
	 * @param int $seconds How many seconds in the past to set locked_at.
	 * @return void
	 */
	private function backdate_lock( $job_id, $seconds ) {
		global $wpdb;
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"UPDATE {$table} SET locked_at = %s WHERE id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s', time() - $seconds ),
				$job_id
			)
		);
	}

	public function test_reclaim_stale_returns_stranded_running_job_to_pending() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		$this->backdate_lock( $id, Blogcraft_Queue::RECLAIM_AFTER_SECONDS + 60 );

		$reclaimed = Blogcraft_Queue::reclaim_stale();

		$this->assertSame( 1, $reclaimed );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'running' ) );
	}

	public function test_reclaim_stale_increments_attempts() {
		global $wpdb;
		$id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();
		$this->backdate_lock( $id, Blogcraft_Queue::RECLAIM_AFTER_SECONDS + 60 );

		Blogcraft_Queue::reclaim_stale();

		$table    = Blogcraft_Migrator::table_name( 'jobs' );
		$attempts = $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, (int) $attempts );
	}

	public function test_reclaim_stale_leaves_recently_locked_jobs_alone() {
		Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim();

		$reclaimed = Blogcraft_Queue::reclaim_stale();

		$this->assertSame( 0, $reclaimed );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'running' ) );
	}

	public function test_reclaim_stale_fails_job_at_its_attempt_ceiling() {
		global $wpdb;
		$id    = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		// Default max_attempts is 3; starting at 2 means the reclaim's
		// increment to 3 lands exactly on the ceiling.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = %d WHERE id = %d", 2, $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Blogcraft_Queue::claim();
		$this->backdate_lock( $id, Blogcraft_Queue::RECLAIM_AFTER_SECONDS + 60 );

		$reclaimed = Blogcraft_Queue::reclaim_stale();

		$this->assertSame( 1, $reclaimed );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'failed' ) );
		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'pending' ) );
	}
}
