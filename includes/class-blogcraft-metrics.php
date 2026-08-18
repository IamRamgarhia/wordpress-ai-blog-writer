<?php
/**
 * Measuring a draft.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Computes the numbers a blueprint can be checked against.
 *
 * Everything here is measured from the rendered article rather than asked of
 * the model, because a model asked for "about 1200 words at a general reading
 * level" will confidently report having done so whatever it actually wrote.
 * These are the figures the scorer turns into repair instructions.
 *
 * No library, no network: syllable counting is approximate but stable, which is
 * all a target band needs.
 */
class Blogcraft_Metrics {

	/**
	 * Strip block markup and HTML down to readable prose.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function plain_text( $content ) {
		$content = (string) $content;

		// Block delimiters are comments, so they survive wp_strip_all_tags.
		$content = preg_replace( '/<!--.*?-->/s', ' ', $content );

		// Stripping tags without this runs the last sentence of one paragraph
		// into the first of the next, which corrupts the sentence split and
		// leaves paragraph length measured across the whole article.
		$content = preg_replace( '#</(?:p|h[1-6]|li|blockquote|figcaption|td|th|div)>#i', "\n\n", (string) $content );
		$content = preg_replace( '#<br\s*/?>#i', "\n", (string) $content );

		$content = wp_strip_all_tags( (string) $content, false );
		$content = html_entity_decode( (string) $content, ENT_QUOTES, 'UTF-8' );
		$content = preg_replace( '/[ \t]+/', ' ', (string) $content );

		// Nested markup closes several tags at once, so collapse the runs that
		// would otherwise read as extra empty paragraphs.
		$content = preg_replace( '/[ \t]*\n[ \t]*/', "\n", (string) $content );
		$content = preg_replace( '/\n{3,}/', "\n\n", (string) $content );

		return trim( (string) $content );
	}

	/**
	 * Words in a piece of text.
	 *
	 * @param string $text Plain text.
	 * @return array
	 */
	public static function words( $text ) {
		preg_match_all( "/[\p{L}\p{N}'’-]+/u", (string) $text, $matches );

		return isset( $matches[0] ) ? $matches[0] : array();
	}

	/**
	 * Sentences in a piece of text.
	 *
	 * @param string $text Plain text.
	 * @return array
	 */
	public static function sentences( $text ) {
		$parts = preg_split( '/(?<=[.!?])\s+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();

		foreach ( (array) $parts as $part ) {
			$part = trim( (string) $part );

			if ( '' !== $part ) {
				$out[] = $part;
			}
		}

		return $out;
	}

	/**
	 * Approximate syllables in one word.
	 *
	 * Vowel-group counting with the common silent-e correction. Wrong on
	 * individual words, close enough in aggregate for a reading-ease band.
	 *
	 * @param string $word A single word.
	 * @return int At least 1.
	 */
	public static function syllables( $word ) {
		$word = strtolower( preg_replace( '/[^a-z]/i', '', (string) $word ) );

		if ( '' === $word ) {
			return 0;
		}

		if ( strlen( $word ) <= 3 ) {
			return 1;
		}

		$word  = preg_replace( '/(?:es|ed|[^aeiouy]e)$/', '', $word );
		$count = preg_match_all( '/[aeiouy]+/', (string) $word );

		return max( 1, (int) $count );
	}

	/**
	 * Flesch Reading Ease, 0 (dense) to 100 (very easy).
	 *
	 * @param string $text Plain text.
	 * @return float
	 */
	public static function reading_ease( $text ) {
		$words     = self::words( $text );
		$sentences = self::sentences( $text );

		if ( empty( $words ) || empty( $sentences ) ) {
			return 0.0;
		}

		$syllables = 0;

		foreach ( $words as $word ) {
			$syllables += self::syllables( $word );
		}

		$per_sentence = count( $words ) / count( $sentences );
		$per_word     = $syllables / count( $words );
		$score        = 206.835 - ( 1.015 * $per_sentence ) - ( 84.6 * $per_word );

		return round( max( 0.0, min( 100.0, $score ) ), 1 );
	}

	/**
	 * Flesch-Kincaid grade level.
	 *
	 * @param string $text Plain text.
	 * @return float
	 */
	public static function grade_level( $text ) {
		$words     = self::words( $text );
		$sentences = self::sentences( $text );

		if ( empty( $words ) || empty( $sentences ) ) {
			return 0.0;
		}

		$syllables = 0;

		foreach ( $words as $word ) {
			$syllables += self::syllables( $word );
		}

		$grade = ( 0.39 * ( count( $words ) / count( $sentences ) ) )
			+ ( 11.8 * ( $syllables / count( $words ) ) ) - 15.59;

		return round( max( 0.0, $grade ), 1 );
	}

	/**
	 * How often a phrase appears, as a percentage of total words.
	 *
	 * @param string $text   Plain text.
	 * @param string $phrase Phrase to count.
	 * @return float
	 */
	public static function density( $text, $phrase ) {
		$phrase = trim( (string) $phrase );
		$words  = self::words( $text );

		if ( '' === $phrase || empty( $words ) ) {
			return 0.0;
		}

		$hits  = self::phrase_count( $text, $phrase );
		$terms = max( 1, count( self::words( $phrase ) ) );

		return round( ( ( $hits * $terms ) / count( $words ) ) * 100, 2 );
	}

	/**
	 * Occurrences of a phrase, case and whitespace insensitive.
	 *
	 * @param string $text   Plain text.
	 * @param string $phrase Phrase to count.
	 * @return int
	 */
	public static function phrase_count( $text, $phrase ) {
		$phrase = trim( (string) $phrase );

		if ( '' === $phrase ) {
			return 0;
		}

		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu';
		$pattern = preg_replace( '/\\\\ /', '\\s+', $pattern );

		return (int) preg_match_all( $pattern, (string) $text );
	}

	/**
	 * Which of a list of terms appear, and which do not.
	 *
	 * @param string $text  Plain text.
	 * @param array  $terms Terms to look for.
	 * @return array Keys: covered, missing.
	 */
	public static function term_coverage( $text, $terms ) {
		$covered = array();
		$missing = array();

		foreach ( (array) $terms as $term ) {
			$term = trim( (string) $term );

			if ( '' === $term ) {
				continue;
			}

			if ( self::phrase_count( $text, $term ) > 0 ) {
				$covered[] = $term;
			} else {
				$missing[] = $term;
			}
		}

		return array(
			'covered' => $covered,
			'missing' => $missing,
		);
	}

	/**
	 * Sentences longer than a limit.
	 *
	 * @param string $text  Plain text.
	 * @param int    $limit Word limit.
	 * @return array Offending sentences, trimmed for display.
	 */
	public static function long_sentences( $text, $limit ) {
		$out = array();

		foreach ( self::sentences( $text ) as $sentence ) {
			if ( count( self::words( $sentence ) ) > (int) $limit ) {
				$out[] = ( strlen( $sentence ) > 120 ) ? substr( $sentence, 0, 120 ) . '…' : $sentence;
			}
		}

		return $out;
	}

	/**
	 * A rough share of sentences written in the passive voice.
	 *
	 * Looks for a form of "to be" followed by a past participle, which catches
	 * the common cases and misses irregular ones. Reported as an indicator, not
	 * a verdict, and never on its own the reason a draft is held.
	 *
	 * @param string $text Plain text.
	 * @return float Percentage.
	 */
	public static function passive_share( $text ) {
		$sentences = self::sentences( $text );

		if ( empty( $sentences ) ) {
			return 0.0;
		}

		$passive = 0;

		foreach ( $sentences as $sentence ) {
			if ( preg_match( '/\b(?:is|are|was|were|be|been|being)\s+(?:\w+ly\s+)?\w+(?:ed|en)\b/i', $sentence ) ) {
				++$passive;
			}
		}

		return round( ( $passive / count( $sentences ) ) * 100, 1 );
	}

	/**
	 * Count headings of a given level in block content.
	 *
	 * @param string $content Post content.
	 * @param int    $level   Heading level.
	 * @return int
	 */
	public static function heading_count( $content, $level ) {
		return (int) preg_match_all( '/<h' . (int) $level . '\b/i', (string) $content );
	}

	/**
	 * Links in the content, split by whether they point at this site.
	 *
	 * @param string $content Post content.
	 * @return array Keys: internal, external.
	 */
	public static function link_counts( $content ) {
		preg_match_all( '/<a\b[^>]*href=["\']([^"\']+)["\']/i', (string) $content, $matches );

		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$internal = 0;
		$external = 0;

		foreach ( ( isset( $matches[1] ) ? $matches[1] : array() ) as $href ) {
			$href = trim( $href );

			if ( '' === $href || 0 === strpos( $href, '#' ) ) {
				continue;
			}

			$link_host = wp_parse_url( $href, PHP_URL_HOST );

			if ( ! $link_host || $link_host === $host ) {
				++$internal;
			} else {
				++$external;
			}
		}

		return array(
			'internal' => $internal,
			'external' => $external,
		);
	}

	/**
	 * Measure everything about one piece of content.
	 *
	 * @param string $content   Rendered post content.
	 * @param array  $blueprint Blueprint the content was written to.
	 * @return array
	 */
	public static function measure( $content, $blueprint ) {
		$text  = self::plain_text( $content );
		$words = self::words( $text );
		$links = self::link_counts( $content );

		$paragraphs = array_filter( array_map( 'trim', preg_split( '/\n\s*\n/', $text ) ) );
		$para_words = array();

		foreach ( $paragraphs as $paragraph ) {
			$para_words[] = count( self::words( $paragraph ) );
		}

		$banned = array();

		foreach ( Blogcraft_Blueprint::list_of( $blueprint, 'banned_phrases' ) as $phrase ) {
			if ( self::phrase_count( $text, $phrase ) > 0 ) {
				$banned[] = $phrase;
			}
		}

		$required = self::term_coverage( $text, Blogcraft_Blueprint::list_of( $blueprint, 'required_terms' ) );

		return array(
			'words'           => count( $words ),
			'sentences'       => count( self::sentences( $text ) ),
			'paragraphs'      => count( $paragraphs ),
			'longest_para'    => empty( $para_words ) ? 0 : max( $para_words ),
			'reading_ease'    => self::reading_ease( $text ),
			'grade_level'     => self::grade_level( $text ),
			'long_sentences'  => self::long_sentences( $text, (int) $blueprint['sentence_max_words'] ),
			'passive_share'   => self::passive_share( $text ),
			'h2'              => self::heading_count( $content, 2 ),
			'h3'              => self::heading_count( $content, 3 ),
			'images'          => (int) preg_match_all( '/<img\b/i', (string) $content ),
			'internal_links'  => $links['internal'],
			'external_links'  => $links['external'],
			'em_dashes'       => substr_count( (string) $content, '—' ),
			'keyword_density' => self::density( $text, (string) $blueprint['primary_keyword'] ),
			'banned_hits'     => $banned,
			'terms_covered'   => $required['covered'],
			'terms_missing'   => $required['missing'],
		);
	}
}
