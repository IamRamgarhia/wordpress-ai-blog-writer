<?php
/**
 * Telling search engines a URL exists, rather than waiting to be found.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Announces newly published and freshly rewritten posts via IndexNow.
 *
 * IndexNow is an open protocol Microsoft runs and Bing, Yandex, Seznam and
 * Naver consume: one small request naming a URL, and the crawlers come
 * looking rather than arriving whenever they next happen to. Google has said
 * it is not participating, so this is worth having and not worth overselling.
 *
 * Off until switched on. It contacts a third party with your addresses, and
 * nobody chose that by installing a plugin.
 */
class Blogcraft_Indexnow {

	/**
	 * Where submissions go.
	 */
	const ENDPOINT = 'https://api.indexnow.org/indexnow';

	/**
	 * Query variable the key file is served through.
	 */
	const QUERY_VAR = 'blogcraft_indexnow_key';

	/**
	 * Wire the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_key_file' ) );
	}

	/**
	 * The key this site identifies itself with.
	 *
	 * Generated once and kept. The protocol's only authentication is that the
	 * same key is readable at a known address on the site being submitted,
	 * which is what proves whoever is submitting controls the domain.
	 *
	 * @return string
	 */
	public static function key() {
		$key = trim( (string) Blogcraft_Settings::get( 'indexnow_key' ) );

		if ( '' !== $key ) {
			return $key;
		}

		$key = str_replace( '-', '', wp_generate_uuid4() );

		Blogcraft_Settings::set( 'indexnow_key', $key );

		return $key;
	}

	/**
	 * Where the key file can be read.
	 *
	 * @return string
	 */
	public static function key_url() {
		return home_url( '/' . self::key() . '.txt' );
	}

	/**
	 * Route requests for the key file.
	 *
	 * A rewrite rule rather than a real file: writing to the web root is
	 * something a plugin should not assume it can do, and on many hosts it
	 * genuinely cannot.
	 *
	 * @return void
	 */
	public static function add_rewrite() {
		add_rewrite_rule( '^([a-f0-9]{32})\.txt$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Register the query variable the rewrite writes into.
	 *
	 * @param array $vars Known query variables.
	 * @return array
	 */
	public static function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Serve the key file when the key is asked for.
	 *
	 * @return void
	 */
	public static function serve_key_file() {
		$asked = get_query_var( self::QUERY_VAR );

		if ( ! $asked ) {
			return;
		}

		$key = trim( (string) Blogcraft_Settings::get( 'indexnow_key' ) );

		// Compared in constant time out of habit rather than necessity: the
		// key is public by design, since serving it is the whole point.
		if ( '' === $key || ! hash_equals( $key, (string) $asked ) ) {
			return;
		}

		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo esc_html( $key );
		exit;
	}

	/**
	 * Announce one URL, if announcing is switched on.
	 *
	 * @param int $post_id Post that was published or rewritten.
	 * @return bool Whether a submission was sent.
	 */
	public static function submit( $post_id ) {
		if ( ! Blogcraft_Settings::get( 'indexnow_enabled' ) ) {
			return false;
		}

		$post = get_post( (int) $post_id );

		// Only something a visitor could actually load. Submitting a draft
		// asks a crawler to come and read a 404.
		if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status ) {
			return false;
		}

		$url  = get_permalink( $post );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( ! $url || ! is_string( $host ) ) {
			return false;
		}

		// A site nobody can reach cannot be crawled, and submitting from one
		// spends a request to be told so.
		if ( 'localhost' === $host || false !== strpos( $host, '.local' ) || false !== strpos( $host, '.test' ) ) {
			return false;
		}

		$result = Blogcraft_Http::post_json(
			self::ENDPOINT,
			array(
				'host'        => $host,
				'key'         => self::key(),
				'keyLocation' => self::key_url(),
				'urlList'     => array( $url ),
			),
			array(),
			10
		);

		// Best-effort by design. A crawler declining to be told about a post
		// is not a reason to fail a job that has already published it.
		if ( '' !== $result['error'] || $result['code'] >= 400 ) {
			Blogcraft_Logger::info(
				'IndexNow did not accept the submission; the post is published either way.',
				array(
					'code'   => $result['code'],
					'reason' => $result['error'],
				),
				null
			);

			return false;
		}

		return true;
	}
}
