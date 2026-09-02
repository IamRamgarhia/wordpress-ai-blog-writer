<?php
/**
 * Whether anything is allowed to come and read what this site publishes.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * What this site lets search engines and AI assistants fetch.
 *
 * A post can do everything right — answered first, sourced, marked up, its
 * own figures in it — and still be invisible, because the site never let
 * anything come and read it. Two settings decide that, both of them outside
 * this plugin and neither of them mentioned on any screen it owns.
 *
 * The first is WordPress's own "Discourage search engines", which is on by
 * default on a great many staging sites and travels to production with the
 * database. The second is robots.txt, where the AI crawlers are increasingly
 * blocked by a copied-in snippet whose consequences nobody stated: an
 * assistant that cannot fetch the page cannot cite it, and citation is the
 * whole reason for writing to be read by one.
 *
 * This reports. It changes nothing: both settings are legitimate choices, and
 * a plugin that quietly unblocked crawlers on somebody's behalf would be
 * making a decision that is not its own.
 */
class Blogcraft_Crawlers {

	/**
	 * Where the parsed answer is kept, so a screen render is not a web request.
	 */
	const CACHE = 'blogcraft_crawler_access';

	/**
	 * How long an answer stands. Long, because robots.txt rarely moves.
	 */
	const CACHE_LIFE = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a failed read stands, so an unreachable site is not retried
	 * on every page load.
	 */
	const RETRY_LIFE = 1 * HOUR_IN_SECONDS;

	/**
	 * The crawlers worth naming, and who they answer for.
	 *
	 * Not every agent that exists: the ones whose absence a writer would
	 * actually notice, because they are what puts a page in front of
	 * somebody who asked an assistant a question.
	 *
	 * @return array Agent token => the product a reader would recognise.
	 */
	public static function agents() {
		return array(
			'GPTBot'          => __( 'ChatGPT', 'dicecodes-ai-blog-writer' ),
			'OAI-SearchBot'   => __( 'ChatGPT search', 'dicecodes-ai-blog-writer' ),
			'ClaudeBot'       => __( 'Claude', 'dicecodes-ai-blog-writer' ),
			'PerplexityBot'   => __( 'Perplexity', 'dicecodes-ai-blog-writer' ),
			'Google-Extended' => __( 'Gemini', 'dicecodes-ai-blog-writer' ),
		);
	}

	/**
	 * What this site currently allows.
	 *
	 * @param bool $fresh Skip the cache and read robots.txt again.
	 * @return array Keys: discouraged (bool), blocked (agent => name),
	 *               known (bool, false when robots.txt could not be read).
	 */
	public static function status( $fresh = false ) {
		// WordPress's own switch comes first and answers the whole question:
		// with it on, WordPress writes the blanket refusal into robots.txt
		// itself, so parsing that file would only report the symptom.
		if ( ! get_option( 'blog_public' ) ) {
			return array(
				'discouraged' => true,
				'blocked'     => self::agents(),
				'known'       => true,
			);
		}

		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$robots = self::read_robots();
		$status = array(
			'discouraged' => false,
			'blocked'     => array(),
			'known'       => null !== $robots,
		);

		if ( null !== $robots ) {
			$status['blocked'] = self::blocked_in( $robots );
		}

		set_transient(
			self::CACHE,
			$status,
			$status['known'] ? self::CACHE_LIFE : self::RETRY_LIFE
		);

		return $status;
	}

	/**
	 * Forget the cached answer.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_transient( self::CACHE );
	}

	/**
	 * This site's robots.txt, as a crawler would receive it.
	 *
	 * Fetched over HTTP rather than built from WordPress's own filters,
	 * because a static file, a security plugin or the server itself can all
	 * serve something other than what WordPress would have said — and what
	 * is served is what a crawler obeys.
	 *
	 * @return string|null Null when it could not be read at all.
	 */
	private static function read_robots() {
		$response = wp_remote_get(
			home_url( '/robots.txt' ),
			array(
				'timeout'    => 5,
				'user-agent' => 'Dicecodes AI Blog Writer/' . BLOGCRAFT_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// No robots.txt is not a failure to read one. A site without the file
		// allows everything, which is the answer.
		if ( 404 === $code ) {
			return '';
		}

		if ( 200 !== $code ) {
			return null;
		}

		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Which of the named crawlers are refused the site.
	 *
	 * Only the whole-site case is judged: a rule that keeps a crawler out of
	 * /wp-admin/ is ordinary and says nothing worth reporting, while
	 * "Disallow: /" is the one that makes everything written here
	 * unreachable. Anything subtler is a question for a robots.txt tester,
	 * and answering it badly here would be worse than not answering.
	 *
	 * @param string $robots The file as served.
	 * @return array Agent token => the product a reader would recognise.
	 */
	private static function blocked_in( $robots ) {
		$groups  = self::groups( $robots );
		$blocked = array();

		foreach ( self::agents() as $agent => $name ) {
			$key = strtolower( $agent );

			// Its own group if it has one, otherwise the catch-all. A named
			// group replaces the catch-all rather than adding to it, which is
			// how a crawler reads this too.
			if ( isset( $groups[ $key ] ) ) {
				$rules = $groups[ $key ];
			} elseif ( isset( $groups['*'] ) ) {
				$rules = $groups['*'];
			} else {
				continue;
			}

			// An explicit Allow of the root wins the tie, the way the longest
			// matching rule wins generally.
			if ( in_array( '/', $rules['allow'], true ) ) {
				continue;
			}

			if ( in_array( '/', $rules['disallow'], true ) ) {
				$blocked[ $agent ] = $name;
			}
		}

		return $blocked;
	}

	/**
	 * Split robots.txt into its groups.
	 *
	 * Consecutive User-agent lines share the rules that follow them, so the
	 * agents are collected until a rule appears and the group is then handed
	 * to all of them.
	 *
	 * @param string $robots The file as served.
	 * @return array Lowercased agent => array( allow, disallow ).
	 */
	private static function groups( $robots ) {
		$groups  = array();
		$agents  = array();
		$rules   = array(
			'allow'    => array(),
			'disallow' => array(),
		);
		$started = false;

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $robots ) as $line ) {
			// Everything from the first # is a comment, including on a rule
			// line. Cut rather than tokenise: strtok skips a leading
			// delimiter, so a commented-out rule came back as a live one.
			$hash = strpos( $line, '#' );

			if ( false !== $hash ) {
				$line = substr( $line, 0, $hash );
			}

			$line = trim( $line );

			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}

			list( $field, $value ) = explode( ':', $line, 2 );

			$field = strtolower( trim( $field ) );
			$value = trim( $value );

			if ( 'user-agent' === $field ) {
				// A User-agent line after a rule opens a new group.
				if ( $started ) {
					$groups  = self::close( $groups, $agents, $rules );
					$agents  = array();
					$rules   = array(
						'allow'    => array(),
						'disallow' => array(),
					);
					$started = false;
				}

				$agents[] = strtolower( $value );
				continue;
			}

			if ( 'allow' === $field || 'disallow' === $field ) {
				if ( empty( $agents ) ) {
					continue;
				}

				$started = true;

				// An empty Disallow means the opposite of a Disallow, and
				// recording it as a path would block exactly nothing while
				// looking as though it might.
				if ( '' !== $value ) {
					$rules[ $field ][] = $value;
				}
			}
		}

		return self::close( $groups, $agents, $rules );
	}

	/**
	 * Give a finished group to every agent that opened it.
	 *
	 * @param array $groups Groups so far.
	 * @param array $agents Agents sharing this group.
	 * @param array $rules  The group's rules.
	 * @return array
	 */
	private static function close( $groups, $agents, $rules ) {
		foreach ( $agents as $agent ) {
			if ( '' === $agent ) {
				continue;
			}

			if ( ! isset( $groups[ $agent ] ) ) {
				$groups[ $agent ] = array(
					'allow'    => array(),
					'disallow' => array(),
				);
			}

			$groups[ $agent ]['allow']    = array_merge( $groups[ $agent ]['allow'], $rules['allow'] );
			$groups[ $agent ]['disallow'] = array_merge( $groups[ $agent ]['disallow'], $rules['disallow'] );
		}

		return $groups;
	}

	/**
	 * What to tell somebody about it, in one line.
	 *
	 * @return string Empty when there is nothing worth saying.
	 */
	public static function line() {
		$status = self::status();

		if ( $status['discouraged'] ) {
			return __( 'This site asks search engines and AI assistants to stay away, under Settings, Reading. Nothing written here will be indexed or cited while that is on. That is right for a staging site and wrong for a live one.', 'dicecodes-ai-blog-writer' );
		}

		if ( empty( $status['blocked'] ) ) {
			return '';
		}

		return sprintf(
			/* translators: %s: a list of AI assistant names, already joined. */
			__( 'Your robots.txt refuses %s. They cannot cite a page they are not allowed to fetch, so posts written here will not appear in their answers.', 'dicecodes-ai-blog-writer' ),
			implode( ', ', $status['blocked'] )
		);
	}
}
