<?php
/**
 * Uninstall tests.
 *
 * @package Blogcraft
 */

// WordPress defines this before it includes uninstall.php, and that file
// now exits without it — correctly, since it drops tables and the old
// guard also accepted ABSPATH, which is defined on every request.
//
// Defining it here is not a workaround for the guard. It is the test
// finally doing what WordPress does, instead of relying on the hole.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'blogcraft/blogcraft.php' );
}

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

	public function test_cleanup_forgets_that_the_introduction_was_seen() {
		// Otherwise deleting the plugin and installing it again would skip the
		// introduction entirely, and the second owner of the site would never
		// be shown it.
		Blogcraft_Activator::activate();
		update_option( Blogcraft_Welcome::DONE_OPTION, 1, false );

		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';
		blogcraft_uninstall_cleanup();

		$this->assertFalse( get_option( Blogcraft_Welcome::DONE_OPTION, false ) );
		$this->assertFalse( get_option( Blogcraft_Welcome::PENDING_OPTION, false ) );
	}

	// ------------------------------------ whether any of that runs at all.

	public function test_deleting_the_plugin_keeps_the_data_by_default() {
		// Deleting a plugin to reinstall it, to move hosts, or to clear a
		// half-finished upload is an ordinary thing to do, and none of those
		// mean "throw away every setting and drop the tables". WordPress asks
		// whether you meant to delete the plugin and has no way to ask about
		// the rest, so the answer has to be given in advance — and the safe
		// one has to be the default, because dropping tables has no undo.
		Blogcraft_Activator::activate();

		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';

		$this->assertFalse( blogcraft_should_purge() );
	}

	public function test_it_purges_only_when_that_was_asked_for() {
		Blogcraft_Activator::activate();
		Blogcraft_Settings::set( 'purge_on_delete', true );

		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';

		$this->assertTrue( blogcraft_should_purge() );
	}

	public function test_unreadable_settings_mean_keep_rather_than_delete() {
		// Absent, malformed or unreadable all have to mean keep. A settings
		// row that failed to load must never be the reason somebody's work is
		// erased.
		require_once dirname( dirname( __DIR__ ) ) . '/uninstall.php';

		delete_option( 'blogcraft_settings' );
		$this->assertFalse( blogcraft_should_purge(), 'no settings at all was read as permission to delete' );

		update_option( 'blogcraft_settings', 'not an array' );
		$this->assertFalse( blogcraft_should_purge(), 'a malformed settings row was read as permission to delete' );

		delete_option( 'blogcraft_settings' );
	}

	public function test_the_choice_has_a_control_on_the_settings_screen() {
		// A setting that only exists in code is one nobody can answer, which
		// would make the default permanent.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-connection.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( 'purge_on_delete', $source );
	}
}
