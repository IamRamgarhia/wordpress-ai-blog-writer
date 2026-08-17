<?php
/**
 * Request verification tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Request extends WP_UnitTestCase {

	public function tear_down() {
		Blogcraft_Capabilities::remove();
		parent::tear_down();
	}

	public function test_verify_passes_for_capable_user_with_valid_nonce() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		Blogcraft_Capabilities::add();
		wp_set_current_user( $user_id );

		$nonce = wp_create_nonce( 'blogcraft_save' );

		$this->assertTrue( Blogcraft_Request::verify( 'blogcraft_save', $nonce ) );
	}

	public function test_verify_fails_for_invalid_nonce() {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		Blogcraft_Capabilities::add();
		wp_set_current_user( $user_id );

		$this->assertFalse( Blogcraft_Request::verify( 'blogcraft_save', 'bogus-nonce' ) );
	}

	public function test_verify_fails_for_user_without_capability() {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );

		$nonce = wp_create_nonce( 'blogcraft_save' );

		$this->assertFalse( Blogcraft_Request::verify( 'blogcraft_save', $nonce ) );
	}

	public function test_verify_fails_for_logged_out_user() {
		wp_set_current_user( 0 );

		$this->assertFalse( Blogcraft_Request::verify( 'blogcraft_save', 'anything' ) );
	}
}
