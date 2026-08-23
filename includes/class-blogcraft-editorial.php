<?php
/**
 * Editorial checks that need more than the prose.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks the things a blueprint asks for but nothing was verifying.
 *
 * The blueprint has asked for statistics, citations and first-hand experience
 * since it was written, and none of the three was ever measured. A rule that is
 * requested but never verified is a rule the model can quietly ignore, and the
 * repair loop — the one thing this plugin does that the paid tools do not —
 * was blind to all of them.
 *
 * These sit apart from Blogcraft_Scorecard because they need context the prose
 * does not carry: the title, the meta description, and the research sources the
 * draft was written from. A check whose context is missing is skipped rather
 * than failed. The score is earned-over-offered, so skipping keeps it honest;
 * failing something that could not be assessed would not.
 *
 * On what is deliberately not attempted: nothing here claims to measure
 * "insight". Overlap with the sources is measurable and so is the density of
 * concrete data, and both correlate with what search engines now reward. Novel
 * understanding does not fall out of a regular expression, and a check that
 * pretended otherwise would reward a confident fabrication.
 */
class Blogcraft_Editorial {

	/**
	 * Openings that delay the answer.
	 *
	 * Every one of these is a sentence that could be deleted without losing
	 * anything, and they are the commonest tell that a page was generated
	 * rather than written. An answer-first opening is also the shape search
	 * engines lift into an answer panel.
	 *
	 * @return array
	 */
	public static function throat_clearing() {
		return array(
			'in today',
			'in the modern',
			'in the digital age',
			'in the ever',
			'in this article',
			'in this post',
			'in this guide',
			'in this blog',
			'this article will',
			'this guide will',
			'we will explore',
			'we will discuss',
			'let us explore',
			'let me explain',
			'have you ever',
			'are you tired',
			'are you looking',
			'when it comes to',
			'let\'s face it',
			'it is no secret',
			'it\'s no secret',
			'picture this',
			'imagine a world',
			'whether you are',
			'whether you\'re',
			'as we all know',
			'in the world of',
			'in a world where',
			'gone are the days',
		);
	}

	/**
	 * The prose before the first heading.
	 *
	 * @param string $content Rendered content.
	 * @return string
	 */
	public static function opening( $content ) {
		$content = (string) $content;
		$cut     = preg_split( '/<h[23][ >]/i', $content, 2 );

		return Blogcraft_Metrics::plain_text( isset( $cut[0] ) ? $cut[0] : $content );
	}

	/**
	 * The text of every top-level heading.
	 *
	 * @param string $content Rendered content.
	 * @return array
	 */
	public static function headings( $content ) {
		$out = array();

		if ( preg_match_all( '#<h2[^>]*>(.*?)</h2>#is', (string) $content, $matches ) ) {
			foreach ( $matches[1] as $heading ) {
				$text = trim( Blogcraft_Metrics::plain_text( $heading ) );

				if ( '' !== $text ) {
					$out[] = $text;
				}
			}
		}

		return $out;
	}

	/**
	 * Whether a phrase appears in a piece of text, loosely.
	 *
	 * Matched word by word rather than as a literal string, and matched on
	 * stems, so "cold brew coffee" is found in "coffee brewed cold". A heading
	 * that reads like English rather than like a keyword is what everyone
	 * writing for people produces, and failing it would push the model the
	 * wrong way — towards stuffing the exact phrase into a sentence that does
	 * not want it.
	 *
	 * Stem matching is a prefix comparison, applied only to words of four
	 * letters or more so that short words still have to match outright.
	 *
	 * @param string $text   Haystack.
	 * @param string $phrase Phrase to look for.
	 * @return bool
	 */
	public static function mentions( $text, $phrase ) {
		$wanted = Blogcraft_Metrics::words( self::fold( $phrase ) );

		if ( empty( $wanted ) ) {
			return false;
		}

		$present = Blogcraft_Metrics::words( self::fold( $text ) );
		$exact   = array_flip( $present );

		foreach ( $wanted as $word ) {
			if ( isset( $exact[ $word ] ) ) {
				continue;
			}

			if ( ! self::stem_match( $word, $present ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether any word in a list shares a stem with the wanted word.
	 *
	 * @param string $word    Wanted word, already folded.
	 * @param array  $present Words available, already folded.
	 * @return bool
	 */
	private static function stem_match( $word, $present ) {
		if ( strlen( $word ) < 4 ) {
			return false;
		}

		foreach ( $present as $candidate ) {
			if ( strlen( $candidate ) < 4 ) {
				continue;
			}

			if ( 0 === strpos( $candidate, $word ) || 0 === strpos( $word, $candidate ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Lowercase a string for comparison, without depending on mbstring.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function fold( $text ) {
		$text = (string) $text;

		// Smart punctuation folded to plain before anything is compared. Every
		// phrase list in this file is written with straight quotes, while a
		// model writing prose produces curly ones and WordPress converts what
		// is left — so "let's face it" was matched and "let’s face it" sailed
		// past, which is the version that actually gets written.
		$text = strtr(
			$text,
			array(
				"\xE2\x80\x98" => "'",
				"\xE2\x80\x99" => "'",
				"\xE2\x80\x9C" => '"',
				"\xE2\x80\x9D" => '"',
				"\xE2\x80\x93" => '-',
				"\xE2\x80\x94" => '-',
			)
		);

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Concrete data points in a piece of text.
	 *
	 * A percentage, a measurement, a sum of money, a year, or any other figure
	 * carrying a unit. Bare small integers are ignored: "three reasons" is
	 * prose, not evidence.
	 *
	 * @param string $text Plain text.
	 * @return array Distinct data points found.
	 */
	public static function data_points( $text ) {
		$text  = (string) $text;
		$found = array();

		$patterns = array(
			// 42%, 3.5 per cent.
			'/\d+(?:[.,]\d+)?\s*(?:%|per cent|percent)/iu',
			// $40, £1,200, €9.99.
			'/[$£€¥]\s?\d+(?:[.,]\d+)*(?:\s?(?:k|m|bn|billion|million|thousand))?/iu',
			// 12 hours, 3.5 kg, 40 mg, 250 ml, 18 months.
			'/\d+(?:[.,]\d+)?\s*(?:hours?|minutes?|seconds?|days?|weeks?|months?|years?|kg|kilograms?|g|grams?|mg|lbs?|pounds?|oz|ounces?|ml|l|litres?|liters?|km|kilometres?|kilometers?|miles?|m|cm|mm|ft|inches|degrees|°[cf]?|mph|kph|gb|mb|tb)\b/iu',
			// 1 in 5, 3 out of 4, 2x, 4 times.
			'/\d+(?:[.,]\d+)?\s*(?:in|out of)\s*\d+/iu',
			'/\b\d+(?:[.,]\d+)?\s*(?:x|times)\b/iu',
			// Years and large figures: 2019, 12,400, 3.2 million.
			'/\b(?:1[89]|20)\d{2}\b/u',
			'/\b\d{1,3}(?:,\d{3})+\b/u',
			'/\b\d+(?:[.,]\d+)?\s*(?:billion|million|thousand)\b/iu',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $text, $matches ) ) {
				foreach ( $matches[0] as $match ) {
					$key = self::fold( preg_replace( '/\s+/u', ' ', trim( $match ) ) );

					if ( '' !== $key ) {
						$found[ $key ] = $key;
					}
				}
			}
		}

		return array_values( $found );
	}

	/**
	 * Sections that state a figure without linking to where it came from.
	 *
	 * An unsupported number is the failure readers punish and search engines
	 * are now explicit about: a claim nobody can check is worth less than no
	 * claim. Measured per section rather than per article, because one link in
	 * the introduction does not vouch for a figure eight hundred words later.
	 *
	 * What this does not do is follow the link and confirm the figure is on the
	 * page at the other end. That is the shape most fabricated citations
	 * actually take — a real address, a plausible title, and a number that is
	 * not there — so this check narrows the problem rather than solving it. It
	 * is named accordingly.
	 *
	 * @param string $content Rendered content.
	 * @return array Headings of the offending sections.
	 */
	public static function unsupported_sections( $content ) {
		$content = (string) $content;
		$out     = array();
		$offsets = array();

		if ( preg_match_all( '/<h2[ >]/i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$offsets[] = (int) $match[1];
			}
		}

		// Everything before the first heading is a section too.
		array_unshift( $offsets, 0 );

		$total = count( $offsets );

		for ( $i = 0; $i < $total; $i++ ) {
			$start = $offsets[ $i ];
			$end   = ( $i + 1 < $total ) ? $offsets[ $i + 1 ] : strlen( $content );
			$chunk = substr( $content, $start, $end - $start );

			if ( '' === trim( $chunk ) ) {
				continue;
			}

			$figures = self::data_points( Blogcraft_Metrics::plain_text( $chunk ) );

			if ( empty( $figures ) ) {
				continue;
			}

			if ( preg_match( '/<a\s[^>]*href=/i', $chunk ) ) {
				continue;
			}

			$heading = self::headings( $chunk );
			$out[]   = empty( $heading ) ? __( 'the introduction', 'blogcraft' ) : $heading[0];
		}

		return $out;
	}

	/**
	 * How much first-hand experience the article claims.
	 *
	 * Counts the phrases that mark an account of something actually done. A
	 * blunt instrument: it cannot tell a real test from a claimed one. What it
	 * can tell is an article written from nowhere, which is the common case.
	 *
	 * @param string $text Plain text.
	 * @return int
	 */
	public static function experience_markers( $text ) {
		$patterns = array(
			'/\b(?:i|we)\s+(?:tested|tried|measured|built|ran|found|noticed|compared|spent|asked|switched|used)\b/iu',
			'/\bin (?:my|our) (?:experience|testing|case|work)\b/iu',
			'/\bwhen (?:i|we)\s+\w+/iu',
			'/\b(?:my|our) (?:own|results?|findings?|numbers?|data|team|clients?|site)\b/iu',
			'/\bwhat (?:i|we) (?:found|learned|noticed)\b/iu',
		);

		$count = 0;

		foreach ( $patterns as $pattern ) {
			$count += (int) preg_match_all( $pattern, (string) $text );
		}

		return $count;
	}

	/**
	 * Sentences that are near-copies of the research sources.
	 *
	 * Not a plagiarism detector — the sources are short excerpts, so this can
	 * only see what it was given. Within that, a sentence sharing almost all of
	 * its distinctive words with a source is a rewrite of that source, and a
	 * page of those is the definition of the content that no longer ranks.
	 *
	 * @param string $text    Plain article text.
	 * @param array  $sources Research sources, each with an excerpt.
	 * @return array Offending sentences.
	 */
	public static function borrowed_sentences( $text, $sources ) {
		$corpus = '';

		foreach ( (array) $sources as $source ) {
			if ( is_array( $source ) && ! empty( $source['excerpt'] ) ) {
				$corpus .= ' ' . (string) $source['excerpt'];
			}
		}

		$corpus = trim( $corpus );

		if ( '' === $corpus ) {
			return array();
		}

		$known = array_flip( Blogcraft_Metrics::words( self::fold( $corpus ) ) );
		$out   = array();

		foreach ( Blogcraft_Metrics::sentences( (string) $text ) as $sentence ) {
			$distinctive = array();

			foreach ( Blogcraft_Metrics::words( self::fold( $sentence ) ) as $word ) {
				// Short words are shared by every English sentence and would
				// make everything look borrowed.
				if ( strlen( $word ) >= 6 ) {
					$distinctive[] = $word;
				}
			}

			// Too few distinctive words to judge either way.
			if ( count( $distinctive ) < 4 ) {
				continue;
			}

			$shared = 0;

			foreach ( $distinctive as $word ) {
				if ( isset( $known[ $word ] ) ) {
					++$shared;
				}
			}

			if ( ( $shared / count( $distinctive ) ) >= 0.85 ) {
				$out[] = $sentence;
			}
		}

		return $out;
	}

	/**
	 * Build every check this class can make for a draft.
	 *
	 * @param string $content   Rendered content.
	 * @param array  $blueprint Blueprint the draft was written to.
	 * @param array  $context   Optional: title, meta_description, sources.
	 * @return array List of checks in the scorecard's shape.
	 */
	public static function checks( $content, $blueprint, $context = array() ) {
		$content = (string) $content;
		$context = is_array( $context ) ? $context : array();
		$text    = Blogcraft_Metrics::plain_text( $content );
		$keyword = isset( $blueprint['primary_keyword'] ) ? trim( (string) $blueprint['primary_keyword'] ) : '';

		$checks = array( self::answer_first( $content, $keyword ) );

		$title = isset( $context['title'] ) ? trim( (string) $context['title'] ) : '';

		if ( '' !== $title ) {
			$checks[] = self::title_length( $title, $blueprint );

			if ( '' !== $keyword ) {
				$checks[] = self::keyword_in_title( $title, $keyword );
			}
		}

		if ( array_key_exists( 'meta_description', $context ) ) {
			$checks[] = self::meta_description( (string) $context['meta_description'], $blueprint );
		}

		if ( '' !== $keyword ) {
			$checks[] = self::keyword_in_heading( $content, $keyword );
		}

		if ( ! empty( $blueprint['require_statistics'] ) ) {
			$checks[] = self::evidence( $text );
		}

		if ( ! empty( $blueprint['require_citations'] ) ) {
			$checks[] = self::support( $content );
		}

		if ( ! empty( $blueprint['require_experience'] ) ) {
			$checks[] = self::experience( $text );
		}

		if ( ! empty( $context['sources'] ) ) {
			$checks[] = self::originality( $text, (array) $context['sources'] );
		}

		if ( ! empty( $context['evidence'] ) ) {
			$checks[] = self::own_material( $text, (string) $context['evidence'] );
		}

		return $checks;
	}

	/**
	 * Figures the writer supplied that never reached the article.
	 *
	 * Someone who takes the trouble to type in their own numbers has given the
	 * post the one thing that cannot be generated. A model that then writes
	 * around them has thrown away the only reason this page beats the pages it
	 * was researched from, and nothing would have said so.
	 *
	 * @param string $text     Plain article text.
	 * @param string $evidence Material the writer supplied.
	 * @return array Data points that were supplied but not used.
	 */
	public static function unused_evidence( $text, $evidence ) {
		$supplied = self::data_points( (string) $evidence );

		if ( empty( $supplied ) ) {
			return array();
		}

		$used = array_flip( self::data_points( (string) $text ) );
		$out  = array();

		foreach ( $supplied as $point ) {
			if ( ! isset( $used[ $point ] ) ) {
				$out[] = $point;
			}
		}

		return $out;
	}

	/**
	 * Assemble one check in the scorecard's shape.
	 *
	 * @param string $key    Machine name.
	 * @param string $label  Human label.
	 * @param bool   $pass   Whether it passed.
	 * @param string $actual What was measured.
	 * @param string $target What was asked for.
	 * @param int    $weight Points.
	 * @param string $repair Instruction for the model when it failed.
	 * @return array
	 */
	private static function check( $key, $label, $pass, $actual, $target, $weight, $repair = '' ) {
		return array(
			'key'    => $key,
			'label'  => $label,
			'pass'   => (bool) $pass,
			'actual' => $actual,
			'target' => $target,
			'weight' => (int) $weight,
			'repair' => $pass ? '' : $repair,
		);
	}

	/**
	 * Does the opening answer, or clear its throat?
	 *
	 * @param string $content Rendered content.
	 * @param string $keyword Primary keyword, when set.
	 * @return array
	 */
	private static function answer_first( $content, $keyword ) {
		$opening   = self::opening( $content );
		$sentences = Blogcraft_Metrics::sentences( $opening );
		$first     = isset( $sentences[0] ) ? $sentences[0] : '';
		$lead      = trim( $first . ' ' . ( isset( $sentences[1] ) ? $sentences[1] : '' ) );
		$words     = count( Blogcraft_Metrics::words( $lead ) );

		$stalls = false;
		$folded = self::fold( $first );

		foreach ( self::throat_clearing() as $phrase ) {
			if ( 0 === strpos( $folded, $phrase ) ) {
				$stalls = true;
				break;
			}
		}

		$off_topic = ( '' !== $keyword && '' !== $lead && ! self::mentions( $lead, $keyword ) );
		$pass      = ( ! $stalls && ! $off_topic && $words >= 12 && $words <= 60 );

		$repair = 'Open by answering the question the title asks, in the first two sentences, before any context. ';

		if ( $stalls ) {
			$repair .= 'The article currently begins with a wind-up sentence; delete it and start with the answer.';
		} elseif ( $off_topic ) {
			$repair .= sprintf( 'Name the subject — %s — in those two sentences.', $keyword );
		} elseif ( $words > 60 ) {
			$repair .= sprintf( 'Those two sentences run %d words; get it under sixty.', $words );
		} else {
			$repair .= 'There is barely an opening at all; write two sentences that stand on their own.';
		}

		return self::check(
			'answer_first',
			__( 'Answer-first opening', 'blogcraft' ),
			$pass,
			$stalls ? __( 'starts with a wind-up', 'blogcraft' ) : sprintf( '%d words', $words ),
			__( '12–60 words, on subject', 'blogcraft' ),
			10,
			$repair
		);
	}

	/**
	 * How long a string is to a reader, rather than to the disk.
	 *
	 * Falls back to counting UTF-8 sequences by hand where mb_strlen() is
	 * absent, rather than to strlen(), which would quietly reintroduce the
	 * byte-counting bug on exactly the hosts that lack the extension.
	 *
	 * @param string $text Text to measure.
	 * @return int
	 */
	private static function characters( $text ) {
		$text = (string) $text;

		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $text, 'UTF-8' );
		}

		return (int) strlen( (string) preg_replace( '/[\x80-\xBF]/', '', $text ) );
	}

	/**
	 * Title length against the blueprint.
	 *
	 * @param string $title     Post title.
	 * @param array  $blueprint Blueprint.
	 * @return array
	 */
	private static function title_length( $title, $blueprint ) {
		$max = max( 20, (int) $blueprint['meta_title_max'] );

		// Characters, not bytes. The prompt asks for a limit in characters and
		// the setting is labelled in characters, so counting bytes failed a
		// title for using an accent, an em dash or a pound sign — and failed
		// every title in Greek, Hindi, Japanese or Arabic outright, since
		// those run two to four bytes per character and would blow a 60-byte
		// ceiling at roughly twenty letters.
		$length = self::characters( $title );
		$pass   = ( $length >= 20 && $length <= $max );

		return self::check(
			'meta_title',
			__( 'Title length', 'blogcraft' ),
			$pass,
			sprintf( '%d characters', $length ),
			sprintf( '20–%d', $max ),
			6,
			( $length > $max )
				? sprintf( 'The title is %1$d characters and will be cut off after about %2$d. Shorten it without dropping the subject.', $length, $max )
				: 'The title is too short to say what the article is about. Give it a subject and a promise.'
		);
	}

	/**
	 * Whether the title names the subject.
	 *
	 * @param string $title   Post title.
	 * @param string $keyword Primary keyword.
	 * @return array
	 */
	private static function keyword_in_title( $title, $keyword ) {
		$pass = self::mentions( $title, $keyword );

		return self::check(
			'keyword_in_title',
			__( 'Subject in the title', 'blogcraft' ),
			$pass,
			$pass ? __( 'present', 'blogcraft' ) : __( 'missing', 'blogcraft' ),
			$keyword,
			8,
			sprintf( 'The title never says "%s". Rewrite it so the subject is unmistakable at a glance.', $keyword )
		);
	}

	/**
	 * Meta description presence and length.
	 *
	 * @param string $description Meta description.
	 * @param array  $blueprint   Blueprint.
	 * @return array
	 */
	private static function meta_description( $description, $blueprint ) {
		$description = trim( (string) $description );
		$max         = max( 80, (int) $blueprint['meta_desc_max'] );
		$length      = self::characters( $description );
		$pass        = ( $length >= 70 && $length <= $max );

		if ( 0 === $length ) {
			$repair = 'There is no meta description. Write one sentence that says what the reader gets, and stop.';
		} elseif ( $length > $max ) {
			$repair = sprintf( 'The meta description is %1$d characters and will be truncated after about %2$d. Cut it.', $length, $max );
		} else {
			$repair = sprintf( 'The meta description is only %d characters. Say what the reader gets from the article.', $length );
		}

		return self::check(
			'meta_description',
			__( 'Meta description', 'blogcraft' ),
			$pass,
			sprintf( '%d characters', $length ),
			sprintf( '70–%d', $max ),
			6,
			$repair
		);
	}

	/**
	 * Whether the subject appears in a section heading.
	 *
	 * @param string $content Rendered content.
	 * @param string $keyword Primary keyword.
	 * @return array
	 */
	private static function keyword_in_heading( $content, $keyword ) {
		$headings = self::headings( $content );
		$found    = false;

		foreach ( $headings as $heading ) {
			if ( self::mentions( $heading, $keyword ) ) {
				$found = true;
				break;
			}
		}

		// Nothing to check when the article has no headings; the structure
		// checks already have plenty to say about that.
		$pass = ( empty( $headings ) || $found );

		return self::check(
			'keyword_in_heading',
			__( 'Subject in a heading', 'blogcraft' ),
			$pass,
			$pass ? __( 'present', 'blogcraft' ) : __( 'in no heading', 'blogcraft' ),
			$keyword,
			6,
			sprintf( 'No section heading mentions "%s". Rewrite one so a reader skimming the headings can see the article is about it.', $keyword )
		);
	}

	/**
	 * Concrete data, because the blueprint asked for it.
	 *
	 * @param string $text Plain article text.
	 * @return array
	 */
	private static function evidence( $text ) {
		$found = self::data_points( $text );
		$count = count( $found );
		$pass  = ( $count >= 3 );

		return self::check(
			'data_points',
			__( 'Concrete figures', 'blogcraft' ),
			$pass,
			sprintf( '%d', $count ),
			__( '3 or more', 'blogcraft' ),
			8,
			sprintf(
				'The article carries only %d specific figure. Replace vague quantities — "many", "significantly", "a lot" — with the actual number, duration, price or measurement, and only where you can support it.',
				$count
			)
		);
	}

	/**
	 * Figures nobody can check, because the blueprint asked for citations.
	 *
	 * @param string $content Rendered content.
	 * @return array
	 */
	private static function support( $content ) {
		$offenders = self::unsupported_sections( $content );
		$count     = count( $offenders );
		$pass      = ( 0 === $count );

		return self::check(
			'unsupported_claims',
			// Named for what it measures. "Figures with a source" claimed more
			// than this does: it finds a figure sitting in a section with no
			// link anywhere in it. It does not open the link and confirm the
			// number is on the other end, and a check whose name implies
			// verification it never performed is worse than no check, because
			// the first person to notice stops believing the other twenty-four.
			__( 'Figures with a link beside them', 'blogcraft' ),
			$pass,
			sprintf( '%d with nothing to check', $count ),
			__( 'none', 'blogcraft' ),
			8,
			sprintf(
				'These sections state a figure with nothing to check it against: %s. Link to where each number came from, or take the number out.',
				implode( ', ', array_slice( $offenders, 0, 4 ) )
			)
		);
	}

	/**
	 * First-hand account, because the blueprint asked for it.
	 *
	 * @param string $text Plain article text.
	 * @return array
	 */
	private static function experience( $text ) {
		$count = self::experience_markers( $text );
		$pass  = ( $count >= 2 );

		return self::check(
			'experience',
			__( 'First-hand experience', 'blogcraft' ),
			$pass,
			sprintf( '%d passages', $count ),
			__( '2 or more', 'blogcraft' ),
			6,
			'Nothing here reads as written by someone who has done it. Add at least two passages describing what actually happened when you did — what you tried, what it cost, what went wrong — and keep them specific.'
		);
	}

	/**
	 * Whether the writer's own material actually made it in.
	 *
	 * @param string $text     Plain article text.
	 * @param string $evidence Material the writer supplied.
	 * @return array
	 */
	private static function own_material( $text, $evidence ) {
		$missing = self::unused_evidence( $text, $evidence );
		$count   = count( $missing );
		$pass    = ( 0 === $count );

		return self::check(
			'own_material',
			__( 'Uses what you supplied', 'blogcraft' ),
			$pass,
			sprintf( '%d unused', $count ),
			__( 'none unused', 'blogcraft' ),
			12,
			sprintf(
				'These figures were supplied by the author and do not appear in the article: %s. They are the only part of this post that is not available anywhere else, so state them, exactly as given, and say they are the site\'s own.',
				implode( ', ', array_slice( $missing, 0, 5 ) )
			)
		);
	}

	/**
	 * How much of the draft is a rewrite of its sources.
	 *
	 * @param string $text    Plain article text.
	 * @param array  $sources Research sources.
	 * @return array
	 */
	private static function originality( $text, $sources ) {
		$borrowed = self::borrowed_sentences( $text, $sources );
		$count    = count( $borrowed );
		$pass     = ( $count <= 1 );

		return self::check(
			'source_overlap',
			__( 'Says something new', 'blogcraft' ),
			$pass,
			sprintf( '%d sentences', $count ),
			__( '1 or fewer', 'blogcraft' ),
			10,
			sprintf(
				'%d sentences repeat the research sources almost word for word. A page that restates what is already published has nothing to offer over the pages it copied. Rewrite them, and add what those sources do not say.',
				$count
			)
		);
	}
}
