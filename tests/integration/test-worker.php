<?php
/**
 * Worker tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Worker extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		global $wpdb;
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		Blogcraft_Worker::reset_stages();
	}

	public function test_run_executes_a_registered_stage() {
		$ran = false;

		Blogcraft_Worker::register_stage(
			'demo',
			'only',
			static function ( $job ) use ( &$ran ) {
				$ran = true;
				return array( 'next' => null, 'payload' => array() );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'only', array() );
		Blogcraft_Worker::run();

		$this->assertTrue( $ran );
	}

	public function test_null_next_stage_completes_the_job() {
		Blogcraft_Worker::register_stage(
			'demo',
			'only',
			static function ( $job ) {
				return array( 'next' => null, 'payload' => array() );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'only', array() );
		Blogcraft_Worker::run();

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'complete' ) );
	}

	public function test_worker_runs_only_one_stage_per_job_per_tick() {
		$calls = 0;

		Blogcraft_Worker::register_stage(
			'demo',
			'first',
			static function ( $job ) use ( &$calls ) {
				$calls++;
				return array( 'next' => 'second', 'payload' => array( 'x' => 1 ) );
			}
		);
		Blogcraft_Worker::register_stage(
			'demo',
			'second',
			static function ( $job ) use ( &$calls ) {
				$calls++;
				return array( 'next' => null, 'payload' => array() );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'first', array() );
		Blogcraft_Worker::run( 0 );

		$this->assertSame( 1, $calls );
	}

	public function test_payload_carries_between_stages() {
		$seen = array();

		Blogcraft_Worker::register_stage(
			'demo',
			'first',
			static function ( $job ) {
				return array( 'next' => 'second', 'payload' => array( 'token' => 'abc' ) );
			}
		);
		Blogcraft_Worker::register_stage(
			'demo',
			'second',
			static function ( $job ) use ( &$seen ) {
				$seen = $job->payload;
				return array( 'next' => null, 'payload' => array() );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'first', array() );
		Blogcraft_Worker::run( 0 );
		Blogcraft_Worker::run( 0 );

		$this->assertSame( array( 'token' => 'abc' ), $seen );
	}

	public function test_thrown_exception_fails_the_job() {
		Blogcraft_Worker::register_stage(
			'demo',
			'boom',
			static function ( $job ) {
				throw new RuntimeException( 'stage exploded' );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'boom', array() );
		Blogcraft_Worker::run( 0 );

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'running' ) );
	}

	public function test_unregistered_stage_fails_the_job() {
		Blogcraft_Queue::enqueue( 'demo', 'missing', array() );
		Blogcraft_Worker::run( 0 );

		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'running' ) );
	}

	public function test_run_returns_zero_when_queue_is_empty() {
		$this->assertSame( 0, Blogcraft_Worker::run( 0 ) );
	}

	public function test_thrown_error_fails_the_job() {
		Blogcraft_Worker::register_stage(
			'demo',
			'boom',
			static function ( $job ) {
				throw new TypeError( 'stage exploded with a TypeError' );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'boom', array() );
		Blogcraft_Worker::run( 0 );

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'running' ) );
	}

	public function test_empty_string_next_stage_completes_the_job() {
		Blogcraft_Worker::register_stage(
			'demo',
			'only',
			static function ( $job ) {
				return array( 'next' => '', 'payload' => array() );
			}
		);

		Blogcraft_Queue::enqueue( 'demo', 'only', array() );
		Blogcraft_Worker::run();

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'complete' ) );
	}
}
