<?php
/**
 * More than one provider, kept, and switched between.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Saved provider setups, so choosing one is not retyping it.
 *
 * The settings hold exactly one provider: a type, a key, a model. Anybody
 * comparing two — a cheap model against a good one, an account they pay for
 * against a local model that costs nothing — had to retype the whole card to
 * look at the other, and paste the key back in from wherever they keep it.
 * Most people did it once and never went back.
 *
 * So: the active setup stays exactly where it was, in the settings, and every
 * consumer keeps reading it from there. This is a shelf beside it. Saving
 * copies the settings onto the shelf under a name; switching copies one back.
 * Nothing else in the plugin has to know this exists, which is the reason it
 * is built this way round.
 */
class Blogcraft_Connections {

	/**
	 * Where the shelf lives.
	 */
	const OPTION = 'blogcraft_saved_providers';

	/**
	 * The most to keep. A shelf is not an archive.
	 */
	const LIMIT = 10;

	/**
	 * Every setting that makes a provider what it is.
	 *
	 * The custom-provider fields are here too. Leaving them out meant
	 * switching to a saved custom endpoint restored its address and key but
	 * not the request shape, which fails in a way that reads like a broken
	 * key.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'provider_type',
			'provider_api_key',
			'provider_model',
			'provider_draft_model',
			'provider_base_url',
			'provider_key_owner',
			'provider_auth_header',
			'provider_auth_prefix',
			'provider_request_template',
			'provider_text_path',
			'provider_prompt_tokens_path',
			'provider_completion_tokens_path',
		);
	}

	/**
	 * Everything on the shelf.
	 *
	 * @return array Id => record, keys still encrypted.
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Put the current setup on the shelf under a name.
	 *
	 * @param string $label What to call it.
	 * @return string The id, or '' when there was nothing to save.
	 */
	public static function save( $label ) {
		$type = trim( (string) Blogcraft_Settings::get( 'provider_type' ) );

		if ( '' === $type ) {
			return '';
		}

		$record = array(
			'label'  => sanitize_text_field( $label ),
			'saved'  => time(),
			'values' => array(),
		);

		if ( '' === $record['label'] ) {
			$record['label'] = $type;
		}

		foreach ( self::fields() as $field ) {
			$value = Blogcraft_Settings::get( $field );

			// Stored the way the settings store it. A key sitting in plain
			// text in a second option would undo the encryption the first
			// one bothers with.
			if ( 'provider_api_key' === $field ) {
				$value = Blogcraft_Crypto::encrypt( (string) $value );
			}

			$record['values'][ $field ] = $value;
		}

		$saved = self::all();
		$id    = 'p' . bin2hex( random_bytes( 8 ) );

		$saved[ $id ] = $record;

		if ( count( $saved ) > self::LIMIT ) {
			uasort(
				$saved,
				static function ( $a, $b ) {
					return (int) $a['saved'] <=> (int) $b['saved'];
				}
			);

			$saved = array_slice( $saved, -self::LIMIT, null, true );
		}

		update_option( self::OPTION, $saved, false );

		return $id;
	}

	/**
	 * Make one of them the live setup.
	 *
	 * @param string $id Which one.
	 * @return bool Whether it existed.
	 */
	public static function activate( $id ) {
		$saved = self::all();
		$id    = (string) $id;

		if ( ! isset( $saved[ $id ] ) ) {
			return false;
		}

		foreach ( self::fields() as $field ) {
			if ( ! array_key_exists( $field, $saved[ $id ]['values'] ) ) {
				continue;
			}

			$value = $saved[ $id ]['values'][ $field ];

			if ( 'provider_api_key' === $field ) {
				$value = Blogcraft_Crypto::decrypt( (string) $value );
			}

			Blogcraft_Settings::set( $field, $value );
		}

		return true;
	}

	/**
	 * Take one off the shelf.
	 *
	 * @param string $id Which one.
	 * @return bool Whether it existed.
	 */
	public static function remove( $id ) {
		$saved = self::all();
		$id    = (string) $id;

		if ( ! isset( $saved[ $id ] ) ) {
			return false;
		}

		unset( $saved[ $id ] );
		update_option( self::OPTION, $saved, false );

		return true;
	}

	/**
	 * Whether a saved setup is the one currently live.
	 *
	 * Compared on what actually decides which model answers, rather than on
	 * an id written into the settings — so a setup edited by hand after being
	 * switched to stops claiming to be the saved one.
	 *
	 * @param array $record A saved record.
	 * @return bool
	 */
	public static function is_live( $record ) {
		foreach ( array( 'provider_type', 'provider_model', 'provider_base_url' ) as $field ) {
			$saved = isset( $record['values'][ $field ] ) ? (string) $record['values'][ $field ] : '';

			if ( trim( $saved ) !== trim( (string) Blogcraft_Settings::get( $field ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A short description of one saved setup, for the list.
	 *
	 * @param array $record A saved record.
	 * @return string
	 */
	public static function describe( $record ) {
		$type  = isset( $record['values']['provider_type'] ) ? (string) $record['values']['provider_type'] : '';
		$model = isset( $record['values']['provider_model'] ) ? (string) $record['values']['provider_model'] : '';

		if ( '' === $model ) {
			return $type;
		}

		return $type . ' · ' . $model;
	}
}
