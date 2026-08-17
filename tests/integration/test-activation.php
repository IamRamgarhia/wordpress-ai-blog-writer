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
}
