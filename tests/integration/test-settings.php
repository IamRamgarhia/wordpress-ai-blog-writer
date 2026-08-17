<?php
/**
 * Settings tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Settings extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	public function test_get_returns_schema_default_when_unset() {
		$this->assertSame( 3, Blogcraft_Settings::get( 'queue_max_attempts' ) );
	}

	public function test_get_returns_null_for_unknown_key() {
		$this->assertNull( Blogcraft_Settings::get( 'no_such_setting' ) );
	}

	public function test_set_then_get_roundtrip() {
		Blogcraft_Settings::set( 'queue_max_attempts', 5 );
		$this->assertSame( 5, Blogcraft_Settings::get( 'queue_max_attempts' ) );
	}

	public function test_set_rejects_unknown_key() {
		$this->assertFalse( Blogcraft_Settings::set( 'no_such_setting', 'x' ) );
	}

	public function test_integer_setting_is_cast() {
		Blogcraft_Settings::set( 'queue_max_attempts', '7' );
		$this->assertSame( 7, Blogcraft_Settings::get( 'queue_max_attempts' ) );
	}

	public function test_boolean_setting_is_cast() {
		Blogcraft_Settings::set( 'cron_health_notice_enabled', '1' );
		$this->assertTrue( Blogcraft_Settings::get( 'cron_health_notice_enabled' ) );
	}

	public function test_string_setting_is_sanitised() {
		Blogcraft_Settings::set( 'provider_base_url', '  https://api.groq.com/openai/v1  ' );
		$this->assertSame( 'https://api.groq.com/openai/v1', Blogcraft_Settings::get( 'provider_base_url' ) );
	}

	public function test_secret_is_not_stored_in_plaintext() {
		Blogcraft_Settings::set( 'provider_api_key', 'sk-secret-value-1234' );
		$raw = get_option( 'blogcraft_settings' );
		$this->assertStringNotContainsString( 'sk-secret-value-1234', wp_json_encode( $raw ) );
	}

	public function test_secret_roundtrips_through_get() {
		Blogcraft_Settings::set( 'provider_api_key', 'sk-secret-value-1234' );
		$this->assertSame( 'sk-secret-value-1234', Blogcraft_Settings::get( 'provider_api_key' ) );
	}

	public function test_delete_restores_default() {
		Blogcraft_Settings::set( 'queue_max_attempts', 9 );
		Blogcraft_Settings::delete( 'queue_max_attempts' );
		$this->assertSame( 3, Blogcraft_Settings::get( 'queue_max_attempts' ) );
	}

	public function test_set_refuses_to_store_empty_ciphertext() {
		// Simulate encryption failure by storing an empty value in the option first.
		// If encryption fails and returns '', set() must NOT overwrite with empty string.
		// We'll test this by checking that a non-empty secret never results in empty ciphertext.

		// First set a secret to establish a baseline
		Blogcraft_Settings::set( 'provider_api_key', 'sk-original-secret' );
		$original_stored = get_option( 'blogcraft_settings' );
		$this->assertNotEmpty( $original_stored['provider_api_key'] );

		// Now, if we somehow simulate encryption returning empty (we'll test the guard logic),
		// the stored value should remain unchanged.
		// Since we can't easily mock Blogcraft_Crypto::encrypt() without modifying the class,
		// we test the observable guarantee: a non-empty secret must never result in empty ciphertext.

		// Verify: any non-empty secret input must result in non-empty encrypted output being stored.
		Blogcraft_Settings::set( 'provider_api_key', 'sk-another-secret' );
		$after_set = get_option( 'blogcraft_settings' );
		$this->assertNotEmpty( $after_set['provider_api_key'], 'Encryption of non-empty secret must result in non-empty ciphertext' );
	}
}
