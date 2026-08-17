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

	public function test_set_rejects_non_empty_secret_when_encryption_fails() {
		// Unit test the guard logic directly using ReflectionMethod.
		// Tests that is_encryption_failure() correctly identifies when encryption failed.

		$reflection = new ReflectionMethod( 'Blogcraft_Settings', 'is_encryption_failure' );
		$reflection->setAccessible( true );

		// Case 1: Non-empty plaintext, empty ciphertext = encryption failure (guard should block).
		$result = $reflection->invoke( null, 'sk-real-key', '' );
		$this->assertTrue( $result, 'Should detect encryption failure: non-empty input, empty output' );

		// Case 2: Non-empty plaintext, non-empty ciphertext = success (guard should allow).
		$result = $reflection->invoke( null, 'sk-real-key', 'bcv1:abc123' );
		$this->assertFalse( $result, 'Should not report failure when ciphertext is present' );

		// Case 3: Empty plaintext, empty ciphertext = legitimate clear (guard should allow).
		$result = $reflection->invoke( null, '', '' );
		$this->assertFalse( $result, 'Should allow empty plaintext with empty ciphertext' );

		// Case 4: Empty plaintext, non-empty ciphertext = odd but not a failure (guard should allow).
		$result = $reflection->invoke( null, '', 'bcv1:abc123' );
		$this->assertFalse( $result, 'Should not report failure when plaintext is empty' );
	}

	public function test_set_clears_secret_when_value_is_empty() {
		// Verify that setting a secret to empty string works (legitimate clear path).
		Blogcraft_Settings::set( 'provider_api_key', 'sk-original-secret' );
		$this->assertSame( 'sk-original-secret', Blogcraft_Settings::get( 'provider_api_key' ) );

		// Clear the secret.
		$result = Blogcraft_Settings::set( 'provider_api_key', '' );
		$this->assertTrue( $result, 'Setting secret to empty should succeed' );

		// After clearing, should return the schema default (empty string).
		$this->assertSame( '', Blogcraft_Settings::get( 'provider_api_key' ) );
	}
}
