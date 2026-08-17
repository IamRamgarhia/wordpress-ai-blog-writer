<?php
/**
 * Crypto tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Crypto extends WP_UnitTestCase {

	public function test_encrypt_decrypt_roundtrip() {
		$secret = 'sk-test-1234567890abcdef';
		$cipher = Blogcraft_Crypto::encrypt( $secret );
		$this->assertSame( $secret, Blogcraft_Crypto::decrypt( $cipher ) );
	}

	public function test_ciphertext_does_not_contain_plaintext() {
		$secret = 'sk-test-1234567890abcdef';
		$cipher = Blogcraft_Crypto::encrypt( $secret );
		$this->assertStringNotContainsString( $secret, $cipher );
	}

	public function test_encrypting_same_value_twice_yields_different_ciphertext() {
		$secret = 'sk-test-1234567890abcdef';
		$this->assertNotSame(
			Blogcraft_Crypto::encrypt( $secret ),
			Blogcraft_Crypto::encrypt( $secret )
		);
	}

	public function test_decrypt_returns_empty_string_on_garbage() {
		$this->assertSame( '', Blogcraft_Crypto::decrypt( 'not-valid-ciphertext' ) );
	}

	public function test_decrypt_returns_empty_string_on_empty_input() {
		$this->assertSame( '', Blogcraft_Crypto::decrypt( '' ) );
	}

	public function test_encrypt_returns_empty_string_on_empty_input() {
		$this->assertSame( '', Blogcraft_Crypto::encrypt( '' ) );
	}

	public function test_mask_reveals_only_last_four_characters() {
		$this->assertSame( '••••cdef', Blogcraft_Crypto::mask( 'sk-test-1234567890abcdef' ) );
	}

	public function test_mask_of_short_secret_reveals_nothing() {
		$this->assertSame( '••••', Blogcraft_Crypto::mask( 'abc' ) );
	}

	public function test_mask_of_empty_secret_is_empty() {
		$this->assertSame( '', Blogcraft_Crypto::mask( '' ) );
	}
}
