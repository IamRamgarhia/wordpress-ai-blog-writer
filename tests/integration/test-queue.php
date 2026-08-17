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
}
