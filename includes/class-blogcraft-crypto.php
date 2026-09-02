<?php
/**
 * Secret encryption at rest.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts API keys before they are written to the options table.
 *
 * The key is derived from the site's SECURE_AUTH salt, so secrets are not
 * portable between installs and a database dump alone does not disclose them.
 * If the salt is rotated, existing ciphertext becomes undecryptable; decrypt()
 * returns an empty string and the UI prompts for re-entry.
 */
class Blogcraft_Crypto {

	/**
	 * Prefix marking a value as Blogcraft ciphertext.
	 */
	const PREFIX = 'bcv1:';

	/**
	 * Whether the sodium extension is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	/**
	 * Derive the 32-byte encryption key from WordPress salts.
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'secure_auth' ), true );
	}

	/**
	 * Encrypt a secret.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Prefixed base64 ciphertext, or '' on empty input or if sodium is not available.
	 */
	public static function encrypt( $plaintext ) {
		if ( '' === $plaintext || ! self::is_available() ) {
			return '';
		}

		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );

		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- making raw sodium output storable as text, not hiding anything: the plaintext is the reader's own API key and this is what encrypts it.
	}

	/**
	 * Decrypt a secret.
	 *
	 * @param string $ciphertext Value produced by encrypt().
	 * @return string Plaintext, or '' if the value cannot be decrypted.
	 */
	public static function decrypt( $ciphertext ) {
		if ( '' === $ciphertext || ! self::is_available() ) {
			return '';
		}

		if ( 0 !== strpos( $ciphertext, self::PREFIX ) ) {
			return '';
		}

		$raw = base64_decode( substr( $ciphertext, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading back what encrypt() wrote, with strict mode on so anything else is refused rather than guessed at.

		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return '';
		}

		$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );

		return false === $plain ? '' : $plain;
	}

	/**
	 * Produce a display-safe rendering of a secret.
	 *
	 * @param string $secret Secret to mask.
	 * @return string
	 */
	public static function mask( $secret ) {
		if ( '' === $secret ) {
			return '';
		}

		if ( strlen( $secret ) <= 4 ) {
			return '••••';
		}

		return '••••' . substr( $secret, -4 );
	}
}
