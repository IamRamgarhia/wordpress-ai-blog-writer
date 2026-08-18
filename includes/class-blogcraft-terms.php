<?php
/**
 * Deriving the terms a topic is expected to cover.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out which terms the pages already ranking for a topic all mention.
 *
 * The research stage already fetches the top results and their text. Until now
 * that material only reached the model as background prose, which it was free
 * to ignore. A term that appears across most of the ranking pages is the
 * closest honest signal available to "readers of this subject expect this to be
 * covered", and it is the thing tools like Surfer and NEURONwriter charge for.
 *
 * Everything below is derived from text this plugin already has. Nothing new is
 * fetched, so this costs no extra request and no extra token.
 *
 * Deliberately not called a ranking factor. It measures what existing coverage
 * discusses; that is a coverage check, not a promise about Google.
 */
class Blogcraft_Terms {

	/**
	 * Words too common to carry meaning.
	 *
	 * Kept deliberately short. An aggressive stop list starts removing the
	 * domain words that make a subject what it is.
	 *
	 * @return array
	 */
	private static function stopwords() {
		return array(
			'the',
			'and',
			'for',
			'are',
			'but',
			'not',
			'you',
			'your',
			'with',
			'that',
			'this',
			'from',
			'they',
			'have',
			'has',
			'had',
			'was',
			'were',
			'will',
			'can',
			'all',
			'any',
			'our',
			'out',
			'get',
			'how',
			'why',
			'what',
			'when',
			'who',
			'its',
			'it',
			'their',
			'them',
			'there',
			'here',
			'more',
			'most',
			'some',
			'such',
			'than',
			'then',
			'these',
			'those',
			'into',
			'over',
			'about',
			'also',
			'been',
			'being',
			'because',
			'each',
			'other',
			'only',
			'just',
			'very',
			'much',
			'many',
			'one',
			'two',
			'use',
			'used',
			'using',
			'make',
			'makes',
			'made',
			'need',
			'needs',
			'want',
			'like',
			'best',
			'good',
			'better',
			'well',
			'know',
			'help',
			'read',
			'see',
			'may',
			'might',
			'should',
			'would',
			'could',
			'even',
			'still',
			'while',
			'where',
			'which',
			'both',
			'does',
			'did',
			'don',
			'doesn',
			'isn',
			'aren',
			'wasn',
			'won',
			'let',
			'lot',
			'way',
			'ways',
			'thing',
			'things',
			'time',
			'times',
			'year',
			'years',
			'day',
			'days',
			'new',
			'now',
			'off',
			'per',
			'via',
			'yet',
			'own',
			'too',
			'far',
			'few',
			'top',
			'end',
		);
	}

	/**
	 * Reduce text to comparable lowercase words.
	 *
	 * @param string $text Source text.
	 * @return array
	 */
	private static function words( $text ) {
		$text = strtolower( wp_strip_all_tags( (string) $text ) );

		preg_match_all( '/[a-z][a-z0-9\-]{2,}/', $text, $matches );

		return isset( $matches[0] ) ? $matches[0] : array();
	}

	/**
	 * Whether a word is worth considering at all.
	 *
	 * @param string $word Candidate.
	 * @return bool
	 */
	private static function usable( $word ) {
		if ( strlen( $word ) < 4 || strlen( $word ) > 30 ) {
			return false;
		}

		return ! in_array( $word, self::stopwords(), true );
	}

	/**
	 * Two-word phrases, which carry far more meaning than single words.
	 *
	 * "anti fatigue" and "fatigue mat" are useful; "fatigue" alone is not.
	 *
	 * @param array $words       Ordered words from one document.
	 * @param array $topic_words The topic's own words, to skip.
	 * @return array
	 */
	private static function pairs( $words, $topic_words = array() ) {
		$out   = array();
		$count = count( $words );

		for ( $i = 0; $i < $count - 1; $i++ ) {
			if ( ! self::usable( $words[ $i ] ) || ! self::usable( $words[ $i + 1 ] ) ) {
				continue;
			}

			// A pair made only of the topic's own words is the topic restated,
			// and asking the writer to include the thing they are writing about
			// is not a coverage target.
			if ( isset( $topic_words[ $words[ $i ] ] ) && isset( $topic_words[ $words[ $i + 1 ] ] ) ) {
				continue;
			}

			$out[] = $words[ $i ] . ' ' . $words[ $i + 1 ];
		}

		return $out;
	}

	/**
	 * The topic's own words, with crude plural and singular forms.
	 *
	 * Without this, a topic of "standing desk" happily suggests "standing
	 * desks" back as something the article must cover.
	 *
	 * @param string $topic Topic.
	 * @return array Word => true.
	 */
	private static function topic_words( $topic ) {
		$out = array();

		foreach ( self::words( $topic ) as $word ) {
			$out[ $word ] = true;

			if ( 's' === substr( $word, -1 ) ) {
				$out[ substr( $word, 0, -1 ) ] = true;
			} else {
				$out[ $word . 's' ] = true;
			}
		}

		return $out;
	}

	/**
	 * Terms that recur across the pages already covering a topic.
	 *
	 * A term has to appear in more than one source to count. Anything found on
	 * a single page is that page's own angle, not something the subject
	 * demands, and asking a writer to include it would be copying rather than
	 * covering.
	 *
	 * @param array  $sources Output of Blogcraft_Research::gather().
	 * @param string $topic   The topic, so its own words are not suggested back.
	 * @param int    $limit   Most terms to return.
	 * @return array Terms, commonest first.
	 */
	public static function extract( $sources, $topic = '', $limit = 8 ) {
		$sources = is_array( $sources ) ? $sources : array();

		if ( count( $sources ) < 2 ) {
			return array();
		}

		$topic_words = self::topic_words( $topic );

		$documents = 0;
		$seen      = array();

		foreach ( $sources as $source ) {
			$text = isset( $source['excerpt'] ) ? (string) $source['excerpt'] : '';
			$text = trim( ( isset( $source['title'] ) ? $source['title'] . ' ' : '' ) . $text );

			if ( '' === $text ) {
				continue;
			}

			++$documents;

			$words = self::words( $text );
			$here  = array();

			foreach ( $words as $word ) {
				if ( self::usable( $word ) && ! isset( $topic_words[ $word ] ) ) {
					$here[ $word ] = true;
				}
			}

			foreach ( self::pairs( $words, $topic_words ) as $pair ) {
				$here[ $pair ] = true;
			}

			// Counted once per document, so one page repeating a word forty
			// times does not outvote four pages mentioning it once.
			foreach ( array_keys( $here ) as $term ) {
				$seen[ $term ] = isset( $seen[ $term ] ) ? $seen[ $term ] + 1 : 1;
			}
		}

		if ( $documents < 2 ) {
			return array();
		}

		$threshold = max( 2, (int) ceil( $documents * 0.5 ) );
		$kept      = array();

		foreach ( $seen as $term => $count ) {
			if ( $count >= $threshold ) {
				$kept[ $term ] = $count;
			}
		}

		arsort( $kept );

		// Prefer phrases: drop a single word that only ever appears inside a
		// phrase that also survived, since asking for both is asking twice.
		$terms = array_keys( $kept );
		$out   = array();

		foreach ( $terms as $term ) {
			if ( false !== strpos( $term, ' ' ) ) {
				$out[] = $term;
				continue;
			}

			$inside = false;

			foreach ( $terms as $other ) {
				if ( false !== strpos( $other, ' ' ) && false !== strpos( ' ' . $other . ' ', ' ' . $term . ' ' ) ) {
					$inside = true;
					break;
				}
			}

			if ( ! $inside ) {
				$out[] = $term;
			}
		}

		return array_slice( $out, 0, max( 1, (int) $limit ) );
	}
}
