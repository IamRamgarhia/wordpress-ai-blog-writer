<?php
/**
 * Uninstall tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Uninstall extends WP_UnitTestCase {

	public function tear_down() {
		Blogcraft_Migrator::migrate();
		Blogcraft_Capabilities::remove();
		parent::tear_down();
	}

	public function test_cleanup_removes_tables() {
		global $wpdb;

		Blogcraft_Activator::activate();
		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';
		blogcraft_uninstall_cleanup();

		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_cleanup_removes_settings() {
		Blogcraft_Activator::activate();
		Blogcraft_Settings::set( 'queue_max_attempts', 9 );

		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';
		blogcraft_uninstall_cleanup();

		$this->assertFalse( get_option( 'blogcraft_settings' ) );
	}

	public function test_cleanup_removes_capability() {
		Blogcraft_Activator::activate();

		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';
		blogcraft_uninstall_cleanup();

		$role = get_role( 'administrator' );
		$this->assertFalse( $role->has_cap( Blogcraft_Capabilities::MANAGE ) );
	}

	public function test_cleanup_unschedules_cron() {
		Blogcraft_Activator::activate();

		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';
		blogcraft_uninstall_cleanup();

		$this->assertFalse( Blogcraft_Scheduler::is_scheduled() );
	}

	public function test_cleanup_removes_usage_options() {
		Blogcraft_Activator::activate();
		Blogcraft_Cost::record( 'openai', 'gpt-4', 10, 10 );

		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';
		blogcraft_uninstall_cleanup();

		$this->assertFalse( get_option( Blogcraft_Cost::OPTION ) );
		$this->assertSame( 0, Blogcraft_Cost::month_totals()['requests'] );
	}
}
