<?php
/**
 * Settings storage.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes plugin settings as a single option.
 *
 * One option keeps autoloaded row count low and makes export/import trivial.
 * Values are sanitised on write according to the schema, so readers never
 * have to defend against bad types.
 */
class Blogcraft_Settings {

	/**
	 * Option key holding all settings.
	 */
	const OPTION = 'blogcraft_settings';

	/**
	 * Read the raw stored array.
	 *
	 * @return array
	 */
	private static function raw() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Coerce a value to its declared type.
	 *
	 * @param mixed  $value Incoming value.
	 * @param string $type  Declared type.
	 * @return mixed
	 */
	private static function sanitize( $value, $type ) {
		switch ( $type ) {
			case 'int':
				return (int) $value;
			case 'bool':
				return (bool) $value;
			case 'url':
				return esc_url_raw( trim( (string) $value ) );
			default:
				return sanitize_text_field( trim( (string) $value ) );
		}
	}

	/**
	 * Get a setting value, falling back to its schema default.
	 *
	 * @param string $key Setting key.
	 * @return mixed Null if the key is not in the schema.
	 */
	public static function get( $key ) {
		$definition = Blogcraft_Settings_Schema::get( $key );

		if ( null === $definition ) {
			return null;
		}

		$stored = self::raw();

		if ( ! array_key_exists( $key, $stored ) ) {
			return $definition['default'];
		}

		$value = $stored[ $key ];

		if ( $definition['secret'] ) {
			return Blogcraft_Crypto::decrypt( (string) $value );
		}

		return $value;
	}

	/**
	 * Write a setting value.
	 *
	 * Guard: If the setting is a secret and the incoming value is non-empty,
	 * but encrypt() returns empty string, do not overwrite the stored value
	 * and return false to signal failure.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 * @return bool False if the key is not in the schema, or if encryption fails.
	 */
	public static function set( $key, $value ) {
		$definition = Blogcraft_Settings_Schema::get( $key );

		if ( null === $definition ) {
			return false;
		}

		$stored = self::raw();

		if ( $definition['secret'] ) {
			$value_str = (string) $value;
			$encrypted = Blogcraft_Crypto::encrypt( $value_str );

			// Guard: If the value is non-empty but encryption resulted in empty,
			// that indicates an encryption failure (e.g., sodium unavailable).
			// Do not silently persist an empty ciphertext — return false to signal failure.
			if ( '' !== $value_str && '' === $encrypted ) {
				return false;
			}

			$stored[ $key ] = $encrypted;
		} else {
			$stored[ $key ] = self::sanitize( $value, $definition['type'] );
		}

		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Remove a stored value so the default applies again.
	 *
	 * @param string $key Setting key.
	 * @return bool False if the key is not in the schema.
	 */
	public static function delete( $key ) {
		if ( null === Blogcraft_Settings_Schema::get( $key ) ) {
			return false;
		}

		$stored = self::raw();
		unset( $stored[ $key ] );
		update_option( self::OPTION, $stored, false );

		return true;
	}

	/**
	 * Every setting resolved to its effective value.
	 *
	 * @return array
	 */
	public static function all() {
		$out = array();

		foreach ( array_keys( Blogcraft_Settings_Schema::all() ) as $key ) {
			$out[ $key ] = self::get( $key );
		}

		return $out;
	}
}
