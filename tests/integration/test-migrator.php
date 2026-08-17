<?php
/**
 * Migrator tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Migrator extends WP_UnitTestCase {

	public function test_table_name_is_prefixed() {
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'blogcraft_jobs', Blogcraft_Migrator::table_name( 'jobs' ) );
	}

	public function test_migrate_creates_jobs_table() {
		global $wpdb;
		Blogcraft_Migrator::migrate();
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertSame( $table, $found );
	}

	public function test_migrate_creates_log_table() {
		global $wpdb;
		Blogcraft_Migrator::migrate();
		$table = Blogcraft_Migrator::table_name( 'log' );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertSame( $table, $found );
	}

	public function test_migrate_records_db_version() {
		Blogcraft_Migrator::migrate();
		$this->assertSame( BLOGCRAFT_DB_VERSION, get_option( 'blogcraft_db_version' ) );
	}

	public function test_migrate_is_idempotent() {
		Blogcraft_Migrator::migrate();
		Blogcraft_Migrator::migrate();
		$this->assertSame( BLOGCRAFT_DB_VERSION, get_option( 'blogcraft_db_version' ) );
	}

	public function test_drop_tables_removes_tables_and_version() {
		global $wpdb;
		Blogcraft_Migrator::migrate();
		Blogcraft_Migrator::drop_tables();
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		$this->assertFalse( get_option( 'blogcraft_db_version' ) );
	}
}
