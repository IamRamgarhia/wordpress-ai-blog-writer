<?php
/**
 * Where the services Blogcraft can talk to actually live.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Loads provider addresses from data/providers.json.
 *
 * These were literals scattered through the registry and the image adapter.
 * Holding them as data has two effects worth having: the list can be extended
 * or repointed by another plugin through a filter, and it can change without a
 * code release. Nothing is concealed by the move — every service in that file
 * is named in readme.txt under External Services, which is where a reader
 * looks and where the plugin guidelines require it.
 *
 * Read once per request and kept, because a settings screen asks for the
 * catalogue a dozen times while rendering.
 */
class Blogcraft_Endpoints {

	/**
	 * Parsed file contents, or null before the first read.
	 *
	 * @var array|null
	 */
	private static $data = null;

	/**
	 * The whole file.
	 *
	 * @return array Keys: text, images.
	 */
	private static function data() {
		if ( null !== self::$data ) {
			return self::$data;
		}

		$path   = BLOGCRAFT_PATH . 'data/providers.json';
		$parsed = array();

		if ( is_readable( $path ) ) {
			$raw    = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a file shipped inside the plugin, not a remote request.
			$parsed = json_decode( (string) $raw, true );
		}

		self::$data = is_array( $parsed ) ? $parsed : array();

		return self::$data;
	}

	/**
	 * Every text provider's addresses.
	 *
	 * @return array Provider id => array( adapter, base_url, help, key_url, docs_url ).
	 */
	public static function text() {
		$data = self::data();
		$out  = isset( $data['text'] ) && is_array( $data['text'] ) ? $data['text'] : array();

		/**
		 * Filter the AI services Blogcraft offers.
		 *
		 * Adding an entry here makes it selectable in Settings. Each needs
		 * adapter, base_url, help, key_url and docs_url; adapter must be one
		 * the registry knows, which in practice means 'openai' for anything
		 * speaking the chat-completions protocol.
		 *
		 * @param array $out Provider id => spec.
		 */
		$out = apply_filters( 'blogcraft_providers', $out );

		return is_array( $out ) ? $out : array();
	}

	/**
	 * One text provider, or an empty spec.
	 *
	 * @param string $id Provider id.
	 * @return array
	 */
	public static function provider( $id ) {
		$all = self::text();
		$id  = (string) $id;

		if ( isset( $all[ $id ] ) && is_array( $all[ $id ] ) ) {
			return $all[ $id ];
		}

		return array(
			'adapter'  => 'custom',
			'base_url' => '',
			'help'     => '',
			'key_url'  => '',
			'docs_url' => '',
		);
	}

	/**
	 * One image service's addresses.
	 *
	 * @param string $id Service id.
	 * @return array Keys: endpoint, help, key_url, docs_url.
	 */
	public static function image( $id ) {
		$data = self::data();
		$all  = isset( $data['images'] ) && is_array( $data['images'] ) ? $data['images'] : array();

		/**
		 * Filter the picture services Blogcraft offers.
		 *
		 * @param array $all Service id => spec.
		 */
		$all = apply_filters( 'blogcraft_image_providers', $all );
		$id  = (string) $id;

		if ( is_array( $all ) && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ) {
			return $all[ $id ];
		}

		return array(
			'endpoint' => '',
			'help'     => '',
			'key_url'  => '',
			'docs_url' => '',
		);
	}

	/**
	 * Forget the parsed file. Test support.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$data = null;
	}
}
