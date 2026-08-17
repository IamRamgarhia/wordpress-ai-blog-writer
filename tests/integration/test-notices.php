<?php
/**
 * Notice tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Notices extends WP_UnitTestCase {

	public function tear_down() {
		Blogcraft_Capabilities::remove();
		parent::tear_down();
	}

	public function test_notice_is_not_dismissed_by_default() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertFalse( Blogcraft_Notices::is_dismissed( 'cron_health', $user_id ) );
	}

	public function test_dismiss_persists_for_that_user() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		Blogcraft_Notices::dismiss( 'cron_health', $user_id );
		$this->assertTrue( Blogcraft_Notices::is_dismissed( 'cron_health', $user_id ) );
	}

	public function test_dismiss_does_not_affect_other_users() {
		$one = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$two = self::factory()->user->create( array( 'role' => 'administrator' ) );
		Blogcraft_Notices::dismiss( 'cron_health', $one );
		$this->assertFalse( Blogcraft_Notices::is_dismissed( 'cron_health', $two ) );
	}

	public function test_dismissing_one_notice_does_not_dismiss_another() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		Blogcraft_Notices::dismiss( 'cron_health', $user_id );
		$this->assertFalse( Blogcraft_Notices::is_dismissed( 'other_notice', $user_id ) );
	}

	public function test_admin_menu_slug_is_registered() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		Blogcraft_Capabilities::add();

		set_current_screen( 'dashboard' );
		Blogcraft_Admin::register_menu();

		global $admin_page_hooks;
		$this->assertArrayHasKey( Blogcraft_Admin::MENU_SLUG, $admin_page_hooks );
	}
}
