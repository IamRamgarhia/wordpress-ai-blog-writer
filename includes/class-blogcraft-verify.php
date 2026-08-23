<?php
/**
 * Pre-publish quality checks.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scores a draft and verifies its links before anything reaches the site.
 *
 * A model will produce a confident, well-formed article that is thin, repetitive
 * or cites a URL that does not exist. Publishing that unchecked is what search
 * engines penalise as scaled content abuse, so a post that scores badly is held
 * for review rather than published, whatever the user asked for.
 */
class Blogcraft_Verify {

	/**
	 * Most links to check in one pass, so verification cannot stall a tick.
	 */
	const MAX_LINKS = 12;

	/**
	 * Pull every external URL out of an article's text.
	 *
	 * @param array $article Article structure.
	 * @return array Unique URLs.
	 */
	public static function collect_urls( $article ) {
		$text  = wp_json_encode( $article );
		$found = array();

		if ( ! is_string( $text ) ) {
			return array();
		}

		if ( preg_match_all( '#https?://[^\s"\'<>\\\\]+#i', $text, $matches ) ) {
			foreach ( $matches[0] as $url ) {
				$url = rtrim( $url, '.,);:' );

				if ( '' !== $url ) {
					$found[] = $url;
				}
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Check which of an article's URLs are reachable.
	 *
	 * @param array $article Article structure.
	 * @return array Keys: checked, dead. Both arrays of URLs.
	 */
	public static function check_links( $article ) {
		$result = array(
			'checked' => array(),
			'dead'    => array(),
		);

		if ( ! Blogcraft_Settings::get( 'verify_links_enabled' ) ) {
			return $result;
		}

		$urls = array_slice( self::collect_urls( $article ), 0, self::MAX_LINKS );

		foreach ( $urls as $url ) {
			$response = wp_remote_head(
				$url,
				array(
					'timeout'     => 8,
					'redirection' => 3,
				)
			);

			$result['checked'][] = $url;

			if ( is_wp_error( $response ) ) {
				$result['dead'][] = $url;
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			// Anything from 400 up is not worth publishing a link to. Some hosts
			// reject HEAD outright with 405, so that one is treated as alive.
			if ( $code >= 400 && 405 !== $code ) {
				$result['dead'][] = $url;
			}
		}

		return $result;
	}

	/**
	 * Remove dead URLs from an article's text.
	 *
	 * @param array $article Article structure.
	 * @param array $dead    URLs to strip.
	 * @return array Article with those URLs removed.
	 */
	public static function strip_dead_links( $article, $dead ) {
		if ( empty( $dead ) ) {
			return $article;
		}

		$encoded = wp_json_encode( $article );

		if ( ! is_string( $encoded ) ) {
			return $article;
		}

		// Longest first. These are plain string replacements, so removing
		// "https://example.com" before "https://example.com/guide" cuts the
		// front off the longer address and leaves "/guide" embedded in the
		// prose — corrupting a live link because a shorter one on the same
		// domain happened to be dead.
		$dead = array_values( array_unique( array_map( 'strval', $dead ) ) );

		usort(
			$dead,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		foreach ( $dead as $url ) {
			$encoded = str_replace( wp_json_encode( $url ), '""', $encoded );
			$encoded = str_replace( $url, '', $encoded );
		}

		$decoded = json_decode( $encoded, true );

		// A failed decode means the replacements produced something that is no
		// longer JSON, so the original is returned with its dead links intact.
		// That is the safe outcome, but the caller was logging "Removed links
		// that did not resolve" either way — so the one case where nothing was
		// removed is the case that claimed most loudly to have worked.
		return is_array( $decoded ) ? $decoded : $article;
	}

	/**
	 * Total words across an article's prose.
	 *
	 * @param array $article Article structure.
	 * @return int
	 */
	private static function word_count( $article ) {
		$words = 0;

		if ( ! empty( $article['intro'] ) ) {
			$words += str_word_count( wp_strip_all_tags( (string) $article['intro'] ) );
		}

		if ( ! empty( $article['sections'] ) && is_array( $article['sections'] ) ) {
			foreach ( $article['sections'] as $section ) {
				if ( empty( $section['paragraphs'] ) || ! is_array( $section['paragraphs'] ) ) {
					continue;
				}

				foreach ( $section['paragraphs'] as $paragraph ) {
					$words += str_word_count( wp_strip_all_tags( (string) $paragraph ) );
				}
			}
		}

		return $words;
	}

	/**
	 * Score an article out of 100, with reasons for anything lost.
	 *
	 * @param array $article Article structure.
	 * @return array Keys: score (int), reasons (array of strings).
	 */
	public static function score( $article ) {
		$score   = 100;
		$reasons = array();

		if ( empty( $article['intro'] ) ) {
			$score    -= 15;
			$reasons[] = __( 'No opening paragraph answering the title.', 'blogcraft' );
		}

		$sections = ( ! empty( $article['sections'] ) && is_array( $article['sections'] ) ) ? $article['sections'] : array();

		if ( count( $sections ) < 3 ) {
			$score    -= 20;
			$reasons[] = __( 'Fewer than three sections.', 'blogcraft' );
		}

		$words = self::word_count( $article );

		if ( $words < 300 ) {
			$score    -= 25;
			$reasons[] = __( 'Under 300 words. Thin content is the most penalised kind.', 'blogcraft' );
		} elseif ( $words < 600 ) {
			$score    -= 10;
			$reasons[] = __( 'Under 600 words.', 'blogcraft' );
		}

		// Only counted against a post that was supposed to have them. The
		// blueprint can switch both off — the opinion archetype does, on
		// purpose — and this docked five points each and told the reader the
		// post was missing a section they had deliberately removed. A check
		// that reports the absence of something nobody asked for teaches
		// people to stop reading the checks.
		$blueprint = Blogcraft_Blueprint::get();

		if ( ! empty( $blueprint['faq'] ) && empty( $article['faq'] ) ) {
			$score    -= 5;
			$reasons[] = __( 'No FAQ section.', 'blogcraft' );
		}

		if ( ! empty( $blueprint['takeaways'] ) && empty( $article['key_takeaways'] ) ) {
			$score    -= 5;
			$reasons[] = __( 'No key takeaways.', 'blogcraft' );
		}

		// A banned phrase surviving both the draft and the revise pass is a strong
		// signal the model ignored the voice instructions entirely.
		$haystack = strtolower( (string) wp_json_encode( $article ) );

		foreach ( Blogcraft_Voice::banned_words() as $banned ) {
			// Whole words. A bare substring search flagged "delve" inside
			// "delved" — which is arguably fine — but also inside "delveport"
			// or any surname containing it, and docked ten points for a word
			// nobody banned. Blogcraft_Metrics::phrase_count() already applies
			// the boundary rule the rest of the plugin uses, so this uses the
			// same one rather than inventing a second.
			if ( Blogcraft_Metrics::phrase_count( $haystack, $banned ) > 0 ) {
				$score -= 10;
				/* translators: %s: the banned word or phrase found in the draft. */
				$reasons[] = sprintf( __( 'Contains a banned phrase: "%s".', 'blogcraft' ), $banned );
				break;
			}
		}

		$headings = array();

		foreach ( $sections as $section ) {
			if ( ! empty( $section['heading'] ) ) {
				$headings[] = strtolower( trim( (string) $section['heading'] ) );
			}
		}

		if ( count( $headings ) !== count( array_unique( $headings ) ) ) {
			$score    -= 10;
			$reasons[] = __( 'Two sections share a heading.', 'blogcraft' );
		}

		return array(
			'score'   => max( 0, min( 100, $score ) ),
			'reasons' => $reasons,
		);
	}
}
