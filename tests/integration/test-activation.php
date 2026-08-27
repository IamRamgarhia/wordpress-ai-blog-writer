<?php
/**
 * Activation lifecycle tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Activation extends WP_UnitTestCase {

	public function tear_down() {
		Blogcraft_Capabilities::remove();
		parent::tear_down();
	}

	public function test_activation_grants_capability_to_administrator() {
		Blogcraft_Activator::activate();
		$role = get_role( 'administrator' );
		$this->assertTrue( $role->has_cap( Blogcraft_Capabilities::MANAGE ) );
	}

	public function test_activation_does_not_grant_capability_to_subscriber() {
		Blogcraft_Activator::activate();
		$role = get_role( 'subscriber' );
		$this->assertFalse( $role->has_cap( Blogcraft_Capabilities::MANAGE ) );
	}

	public function test_activation_creates_tables() {
		global $wpdb;
		Blogcraft_Activator::activate();
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_remove_revokes_capability() {
		Blogcraft_Activator::activate();
		Blogcraft_Capabilities::remove();
		$role = get_role( 'administrator' );
		$this->assertFalse( $role->has_cap( Blogcraft_Capabilities::MANAGE ) );
	}

	public function test_a_second_copy_stands_aside_instead_of_fataling() {
		// Two copies in wp-content/plugins is easy to end up with, and
		// activating the second one was a fatal error: the require_once in the
		// plugin file takes an absolute path, a different one for each copy,
		// so it does not short-circuit and PHP refuses to declare the
		// autoloader class twice. WordPress rolls the activation back and
		// tells nobody why, which reads as "this plugin will not switch on".
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'blogcraft.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$guard = strpos( $source, "if ( defined( 'BLOGCRAFT_VERSION' ) ) {" );
		$load  = strpos( $source, 'class-blogcraft-autoloader.php' );
		$set   = strpos( $source, "define( 'BLOGCRAFT_VERSION'" );

		$this->assertNotFalse( $guard, 'nothing checks whether another copy is already running' );
		$this->assertNotFalse( $load );
		$this->assertNotFalse( $set );

		// Order is the whole fix. The check has to come before the constant
		// is defined and before anything is required, or it is checking a
		// value this very file just wrote and the class is already loaded.
		$this->assertLessThan( $set, $guard, 'the check runs after this copy defines the constant, so it can never be true' );
		$this->assertLessThan( $load, $guard, 'the check runs after the autoloader is required, which is the line that fatals' );
	}
}
