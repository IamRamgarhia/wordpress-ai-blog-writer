<?php
/**
 * Logger tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Logger extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		Blogcraft_Logger::clear();
	}

	public function test_info_writes_a_row() {
		Blogcraft_Logger::info( 'hello world' );
		$rows = Blogcraft_Logger::recent( 10 );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'hello world', $rows[0]['message'] );
		$this->assertSame( 'info', $rows[0]['level'] );
	}

	public function test_error_records_level_and_job_id() {
		Blogcraft_Logger::error( 'boom', array( 'code' => 500 ), 42 );
		$rows = Blogcraft_Logger::recent( 10 );
		$this->assertSame( 'error', $rows[0]['level'] );
		$this->assertSame( 42, $rows[0]['job_id'] );
		$this->assertSame( array( 'code' => 500 ), $rows[0]['context'] );
	}

	public function test_recent_returns_newest_first() {
		Blogcraft_Logger::info( 'first' );
		Blogcraft_Logger::info( 'second' );
		$rows = Blogcraft_Logger::recent( 10 );
		$this->assertSame( 'second', $rows[0]['message'] );
	}

	public function test_recent_respects_limit() {
		Blogcraft_Logger::info( 'a' );
		Blogcraft_Logger::info( 'b' );
		Blogcraft_Logger::info( 'c' );
		$this->assertCount( 2, Blogcraft_Logger::recent( 2 ) );
	}

	public function test_rotate_trims_to_keep_count() {
		for ( $i = 0; $i < 10; $i++ ) {
			Blogcraft_Logger::info( 'row ' . $i );
		}
		$deleted = Blogcraft_Logger::rotate( 4 );
		$this->assertSame( 6, $deleted );
		$this->assertCount( 4, Blogcraft_Logger::recent( 100 ) );
	}

	public function test_rotate_deletes_nothing_when_under_limit() {
		Blogcraft_Logger::info( 'only one' );
		$this->assertSame( 0, Blogcraft_Logger::rotate( 100 ) );
	}
}
