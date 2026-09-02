<?php
/**
 * Whether something else on this site already marks posts up as articles.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds a second copy of the article structured data.
 *
 * The plugin stands down for the SEO plugins it can name, because each one
 * announces itself with a constant. A theme announces nothing. A great many
 * of them print their own BlogPosting and BreadcrumbList into the head, and
 * on those sites every post the plugin writes goes out carrying two Article
 * blocks that disagree about the same page — which is worth less to a search
 * engine than one, not more.
 *
 * There is no register of which themes do this, so it cannot be answered by
 * a list. It is answered by looking: fetch a post the plugin marked up and
 * count what is actually in the page.
 *
 * This reports. It changes nothing on its own — the switch it points at is
 * the reader's, because a plugin that silently dropped its own structured
 * data on a guess would be the more expensive mistake of the two.
 *
 * @see Blogcraft_Crawlers The same shape, for the same reason.
 */
class Blogcraft_Schema_Watch {

	/**
	 * Where the answer is kept, so a screen render is not a web request.
	 */
	const CACHE = 'blogcraft_schema_duplicate';

	/**
	 * How long an answer stands. Long, because a theme rarely changes.
	 */
	const CACHE_LIFE = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a failed read stands, so a site that cannot fetch itself is
	 * not retried on every admin page load.
	 */
	const RETRY_LIFE = 1 * HOUR_IN_SECONDS;

	/**
	 * The types that describe the page as an article.
	 *
	 * Two of these on one page is the duplicate worth reporting. Anything
	 * else — Organization, WebSite, Person, BreadcrumbList — is ordinary and
	 * expected alongside ours.
	 *
	 * @return array
	 */
	public static function article_types() {
		return array( 'Article', 'BlogPosting', 'NewsArticle', 'TechArticle' );
	}

	/**
	 * What the site is actually publishing.
	 *
	 * @param bool $fresh Skip the cache and fetch the page again.
	 * @return array Keys: known (bool, false when nothing could be read),
	 *               articles (int, how many article blocks the page carries),
	 *               ours (bool, whether one of them is this plugin's),
	 *               url (string, the page that was read).
	 */
	public static function status( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$post_id = self::a_marked_up_post();

		if ( 0 === $post_id ) {
			// Nothing published to look at yet. Not a failure, and not
			// something to retry quickly either.
			$answer = self::unknown( '' );

			set_transient( self::CACHE, $answer, self::RETRY_LIFE );

			return $answer;
		}

		$url  = (string) get_permalink( $post_id );
		$html = self::read( $url );

		if ( '' === $html ) {
			$answer = self::unknown( $url );

			set_transient( self::CACHE, $answer, self::RETRY_LIFE );

			return $answer;
		}

		$answer = array(
			'known'    => true,
			'articles' => self::count_articles( $html ),
			'ours'     => ! Blogcraft_Seo::schema_handled_elsewhere(),
			'url'      => $url,
		);

		set_transient( self::CACHE, $answer, self::CACHE_LIFE );

		return $answer;
	}

	/**
	 * Whether a second copy is going out.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return bool
	 */
	public static function is_doubled( $fresh = false ) {
		$status = self::status( $fresh );

		return $status['known'] && $status['articles'] > 1;
	}

	/**
	 * The warning, in plain words, or nothing to say.
	 *
	 * @return string
	 */
	public static function line() {
		$status = self::status();

		if ( ! $status['known'] || $status['articles'] < 2 ) {
			return '';
		}

		if ( ! $status['ours'] ) {
			// Two copies and neither is this plugin's: worth saying, because
			// it is still wrong, but not something this plugin can fix.
			return __( 'Your theme or another plugin is adding the article structured data twice. That is worth fixing, but it is not coming from here.', 'dicecodes-ai-blog-writer' );
		}

		return sprintf(
			/* translators: %d: how many copies of the article markup the page carries. */
			_n(
				'Your theme already adds article structured data, so each post now carries %d copy of it. Switch off "Add search-engine structured data to each post" in Settings to leave your theme to it.',
				'Your theme already adds article structured data, so each post now carries %d copies of it. Switch off "Add search-engine structured data to each post" in Settings to leave your theme to it.',
				(int) $status['articles'],
				'dicecodes-ai-blog-writer'
			),
			(int) $status['articles']
		);
	}

	/**
	 * Throw the cached answer away.
	 *
	 * Called when the switch is flipped, so the next screen reports on what
	 * the site does now rather than what it did this morning.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_transient( self::CACHE );
	}

	/**
	 * How many article blocks a page carries.
	 *
	 * @param string $html The fetched page.
	 * @return int
	 */
	public static function count_articles( $html ) {
		$found = array();
		$count = 0;

		preg_match_all(
			'#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
			$html,
			$found
		);

		foreach ( $found[1] as $block ) {
			$data = json_decode( trim( $block ), true );

			if ( ! is_array( $data ) ) {
				continue;
			}

			if ( self::names_an_article( $data ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Whether a decoded JSON-LD block describes the page as an article.
	 *
	 * Written for what is actually in the wild: a bare object, a list of
	 * them, an @graph, and a @type that is itself a list.
	 *
	 * @param array $data Decoded block.
	 * @return bool
	 */
	private static function names_an_article( $data ) {
		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			foreach ( $data['@graph'] as $entry ) {
				if ( is_array( $entry ) && self::names_an_article( $entry ) ) {
					return true;
				}
			}
		}

		if ( isset( $data['@type'] ) ) {
			$types = is_array( $data['@type'] ) ? $data['@type'] : array( $data['@type'] );

			foreach ( $types as $type ) {
				if ( in_array( (string) $type, self::article_types(), true ) ) {
					return true;
				}
			}
		}

		// A bare list of blocks rather than one object.
		foreach ( $data as $key => $entry ) {
			if ( is_int( $key ) && is_array( $entry ) && self::names_an_article( $entry ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * A published post this plugin marked up, newest first.
	 *
	 * Only one of ours: another plugin's post would answer a different
	 * question, and a page with no article markup expected on it says
	 * nothing about whether ours is doubled.
	 *
	 * @return int Post id, or 0 when there is none.
	 */
	private static function a_marked_up_post() {
		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- finding exactly these is the point.
				'meta_key'         => Blogcraft_Seo::GENERATED_META,
				'fields'           => 'ids',
			)
		);

		return empty( $posts ) ? 0 : (int) $posts[0];
	}

	/**
	 * Fetch one of this site's own pages.
	 *
	 * @param string $url The page.
	 * @return string The body, or '' when it could not be read.
	 */
	private static function read( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 10,
				'redirection' => 2,
				// The site asking itself a question about itself, the same
				// way core's own loopback check does. Staging and local
				// installs routinely have a certificate nothing trusts, and
				// refusing to read our own page over it would turn "your
				// theme also does this" into silence on exactly the sites
				// most likely to be trying a new theme.
				'sslverify'   => false,
				'headers'     => array( 'Accept' => 'text/html' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * The answer when the page could not be read.
	 *
	 * @param string $url What was tried.
	 * @return array
	 */
	private static function unknown( $url ) {
		return array(
			'known'    => false,
			'articles' => 0,
			'ours'     => false,
			'url'      => $url,
		);
	}
}
