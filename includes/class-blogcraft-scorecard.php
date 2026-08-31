<?php
/**
 * Checking measurements against a blueprint.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns measurements into a score and, more usefully, into repair instructions.
 *
 * The plugin already scored drafts and threw the number away: it decided
 * publish-or-hold and was never shown to the model. That wastes the only
 * objective signal available. Every failed check here carries a sentence
 * written for the model — "the introduction runs 340 words against a 180 target;
 * cut it" — and those sentences are appended to the critique before the revise
 * stage runs. The same list renders for a human on the review screen, so both
 * audiences see the identical reasons.
 *
 * Weights are deliberately lopsided. Length and structure are cheap to fix and
 * heavily weighted; passive voice is an indicator and worth almost nothing, so
 * a draft is never held for it alone.
 */
class Blogcraft_Scorecard {

	/**
	 * Build the full list of checks for a measured draft.
	 *
	 * @param array $metrics   Output of Blogcraft_Metrics::measure().
	 * @param array $blueprint Blueprint the draft was written to.
	 * @return array List of checks.
	 */
	public static function checks( $metrics, $blueprint ) {
		$checks = array();

		$checks[] = self::word_count( $metrics, $blueprint );
		$checks[] = self::sections( $metrics, $blueprint );
		$checks[] = self::reading( $metrics, $blueprint );
		$checks[] = self::sentences( $metrics, $blueprint );
		$checks[] = self::paragraphs( $metrics, $blueprint );
		$checks[] = self::banned( $metrics );
		$checks[] = self::negative( $metrics );
		$checks[] = self::em_dashes( $metrics, $blueprint );

		if ( '' !== trim( (string) $blueprint['primary_keyword'] ) ) {
			$checks[] = self::keyword( $metrics, $blueprint );
		}

		if ( ! empty( $metrics['terms_covered'] ) || ! empty( $metrics['terms_missing'] ) ) {
			$checks[] = self::terms( $metrics );
		}

		// Only when there is a way to pass it. The Sources block is the
		// only place a real external link can come from — every drafting
		// prompt forbids the model from writing markup, so it cannot add
		// one by rewriting — and somebody who has switched that block off
		// has chosen a post with no citations. Marking them down five
		// points for taking an option the plugin offered, on a check
		// nothing can satisfy, is a score that punishes reading the
		// settings.
		if ( (int) $blueprint['external_links_target'] > 0 && ! empty( $blueprint['block_sources'] ) ) {
			$checks[] = self::external_links( $metrics, $blueprint );
		}

		if ( (int) $blueprint['internal_links_target'] > 0 ) {
			$checks[] = self::internal_links( $metrics, $blueprint );
		}

		$checks[] = self::passive( $metrics );

		return $checks;
	}

	/**
	 * Assemble one check.
	 *
	 * @param string $key    Machine name.
	 * @param string $label  Human label.
	 * @param bool   $pass   Whether it passed.
	 * @param string $actual What was measured, for display.
	 * @param string $target What was asked for, for display.
	 * @param int    $weight Points this check is worth.
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
			'repair' => $repair,
		);
	}

	/**
	 * Length against the target band.
	 *
	 * @param array $metrics   Metrics.
	 * @param array $blueprint Blueprint.
	 * @return array
	 */
	private static function word_count( $metrics, $blueprint ) {
		$target    = (int) $blueprint['word_target'];
		$tolerance = (int) $blueprint['word_tolerance'];
		$low       = (int) round( $target * ( 1 - ( $tolerance / 100 ) ) );
		$high      = (int) round( $target * ( 1 + ( $tolerance / 100 ) ) );
		$actual    = (int) $metrics['words'];
		$pass      = ( $actual >= $low && $actual <= $high );

		$repair = '';

		if ( ! $pass ) {
			$repair = ( $actual < $low )
				? sprintf( 'The article is %1$d words and needs to be at least %2$d. Expand the thinnest sections with specifics, not filler.', $actual, $low )
				: sprintf( 'The article is %1$d words and must come under %2$d. Cut repetition and throat-clearing rather than removing whole sections.', $actual, $high );
		}

		return self::check(
			'words',
			__( 'Length', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%d', $actual ),
			sprintf( '%1$d–%2$d', $low, $high ),
			20,
			$repair
		);
	}

	/**
	 * Section count against the blueprint.
	 *
	 * @param array $metrics   Metrics.
	 * @param array $blueprint Blueprint.
	 * @return array
	 */
	private static function sections( $metrics, $blueprint ) {
		$min = (int) $blueprint['sections_min'];
		$max = (int) $blueprint['sections_max'];

		// The true count when the caller knows it. Falling back to the
		// heading count keeps this working for anything measuring a page
		// it did not write, such as the article somebody asks the
		// blueprint screen to match.
		$actual = isset( $metrics['sections'] )
			? (int) $metrics['sections']
			: (int) $metrics['h2'];
		$pass   = ( $actual >= $min && $actual <= $max );

		$repair = '';

		if ( ! $pass ) {
			$repair = ( $actual < $min )
				? sprintf( 'There are %1$d main sections; there must be at least %2$d. Split the broadest one, or add a section the subject genuinely needs.', $actual, $min )
				: sprintf( 'There are %1$d main sections; keep it to %2$d. Merge the ones that overlap.', $actual, $max );
		}

		return self::check(
			'sections',
			__( 'Sections', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%d', $actual ),
			sprintf( '%1$d–%2$d', $min, $max ),
			12,
			$repair
		);
	}

	/**
	 * Reading ease against the chosen band.
	 *
	 * @param array $metrics   Metrics.
	 * @param array $blueprint Blueprint.
	 * @return array
	 */
	private static function reading( $metrics, $blueprint ) {
		list( $low, $high ) = Blogcraft_Blueprint::reading_band( $blueprint );

		$actual = (float) $metrics['reading_ease'];
		$pass   = ( $actual >= $low && $actual <= $high );

		$repair = '';

		if ( ! $pass ) {
			$repair = ( $actual < $low )
				? 'The writing is harder to read than intended. Shorten sentences, prefer common words, and break up any sentence carrying more than one idea.'
				: 'The writing is simpler than intended for this audience. Use the proper terms for things rather than explaining around them, and allow longer sentences where the idea needs them.';
		}

		return self::check(
			'reading',
			__( 'Reading ease', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%.1f', $actual ),
			sprintf( '%1$d–%2$d', $low, $high ),
			15,
			$repair
		);
	}

	/**
	 * Sentence length ceiling.
	 *
	 * @param array $metrics   Metrics.
	 * @param array $blueprint Blueprint.
	 * @return array
	 */
	private static function sentences( $metrics, $blueprint ) {
		$limit = (int) $blueprint['sentence_max_words'];
		$long  = isset( $metrics['long_sentences'] ) ? (array) $metrics['long_sentences'] : array();
		$pass  = empty( $long );

		$repair = '';

		if ( ! $pass ) {
			$repair = sprintf(
				'%1$d sentence(s) run past %2$d words. Split them. The first is: "%3$s"',
				count( $long ),
				$limit,
				$long[0]
			);
		}

		return self::check(
			'sentences',
			__( 'Sentence length', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%d over', count( $long ) ),
			sprintf( '%d max', $limit ),
			8,
			$repair
		);
	}

	/**
	 * Paragraph length ceiling.
	 *
	 * @param array $metrics   Metrics.
	 * @param array $blueprint Blueprint.
	 * @return array
	 */
	private static function paragraphs( $metrics, $blueprint ) {
		// A sentence cap times the sentence limit is the rough word ceiling.
		$limit  = (int) $blueprint['para_max_sentences'] * (int) $blueprint['sentence_max_words'];
		$actual = (int) $metrics['longest_para'];
		$pass   = ( 0 === $actual || $actual <= $limit );

		$repair = $pass ? '' : sprintf(
			'The longest paragraph is roughly %1$d words, past the %2$d-word ceiling. Break the long ones so no paragraph carries more than %3$d sentences.',
			$actual,
			$limit,
			(int) $blueprint['para_max_sentences']
		);

		return self::check(
			'paragraphs',
			__( 'Paragraph length', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%d words', $actual ),
			sprintf( '%d max', $limit ),
			8,
			$repair
		);
	}

	/**
	 * Banned phrases.
	 *
	 * @param array $metrics Metrics.
	 * @return array
	 */
	private static function banned( $metrics ) {
		$hits = isset( $metrics['banned_hits'] ) ? (array) $metrics['banned_hits'] : array();
		$pass = empty( $hits );

		$repair = $pass ? '' : sprintf(
			'Remove these phrases entirely and rewrite around them: %s.',
			implode( ', ', $hits )
		);

		return self::check(
			'banned',
			__( 'Banned phrases', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%d found', count( $hits ) ),
			__( 'none', 'dicecodes-ai-blog-writer' ),
			12,
			$repair
		);
	}

	/**
	 * Terms that were told never to appear.
	 *
	 * Weighted heavily. A banned phrase is a style slip; a negative keyword is
	 * usually a competitor, a legal risk or a claim the site must not make, and
	 * publishing one is a different order of mistake.
	 *
	 * @param array $metrics Metrics.
	 * @return array
	 */
	private static function negative( $metrics ) {
		$hits = isset( $metrics['negative_hits'] ) ? (array) $metrics['negative_hits'] : array();
		$pass = empty( $hits );

		$repair = $pass ? '' : sprintf(
			'These must not appear at all. Remove every mention and rewrite the sentences around them: %s.',
			implode( ', ', $hits )
		);

		return self::check(
			'negative',
			__( 'Excluded terms', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%d found', count( $hits ) ),
			__( 'none', 'dicecodes-ai-blog-writer' ),
			18,
			$repair
		);
	}

	/**
	 * Em dashes, when the blueprint forbids them.
	 *
	 * @param array $metrics   Metrics.
	 * @param array $blueprint Blueprint.
	 * @return array
	 */
	private static function em_dashes( $metrics, $blueprint ) {
		$allowed = (bool) $blueprint['allow_em_dash'];
		$actual  = (int) $metrics['em_dashes'];
		$pass    = ( $allowed || 0 === $actual );

		$repair = $pass ? '' : sprintf(
			'There are %d em dashes. Replace every one with a comma, a full stop, or a rewritten clause.',
			$actual
		);

		return self::check(
			'em_dashes',
			__( 'Em dashes', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%d', $actual ),
			$allowed ? __( 'allowed', 'dicecodes-ai-blog-writer' ) : __( 'none', 'dicecodes-ai-blog-writer' ),
			5,
			$repair
		);
	}

	/**
	 * Primary keyword density.
	 *
	 * @param array $metrics   Metrics.
	 * @param array $blueprint Blueprint.
	 * @return array
	 */
	private static function keyword( $metrics, $blueprint ) {
		$min    = (float) $blueprint['density_min'];
		$max    = (float) $blueprint['density_max'];
		$actual = (float) $metrics['keyword_density'];
		$phrase = (string) $blueprint['primary_keyword'];
		$pass   = ( $actual >= $min && $actual <= $max );

		$repair = '';

		if ( ! $pass ) {
			$repair = ( $actual < $min )
				? sprintf( 'The phrase "%1$s" appears too rarely (%2$.2f%%). Work it into a heading and a couple of sentences where it reads naturally. Do not force it.', $phrase, $actual )
				: sprintf( 'The phrase "%1$s" appears too often (%2$.2f%%). Replace most uses with pronouns or natural synonyms.', $phrase, $actual );
		}

		return self::check(
			'keyword',
			__( 'Keyword density', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%.2f%%', $actual ),
			sprintf( '%1$.1f–%2$.1f%%', $min, $max ),
			10,
			$repair
		);
	}

	/**
	 * Required term coverage.
	 *
	 * @param array $metrics Metrics.
	 * @return array
	 */
	private static function terms( $metrics ) {
		$covered = (array) $metrics['terms_covered'];
		$missing = (array) $metrics['terms_missing'];
		$total   = count( $covered ) + count( $missing );
		$pass    = empty( $missing );

		$repair = $pass ? '' : sprintf(
			'These terms were asked for and do not appear: %s. Cover each one where the subject genuinely calls for it.',
			implode( ', ', $missing )
		);

		return self::check(
			'terms',
			__( 'Required terms', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%1$d of %2$d', count( $covered ), $total ),
			sprintf( '%d', $total ),
			10,
			$repair
		);
	}

	/**
	 * External link count.
	 *
	 * @param array $metrics   Metrics.
	 * @param array $blueprint Blueprint.
	 * @return array
	 */
	private static function external_links( $metrics, $blueprint ) {
		$target = (int) $blueprint['external_links_target'];
		$actual = (int) $metrics['external_links'];
		$pass   = ( $actual >= $target );

		// No repair text: every prompt in this plugin forbids the model from
		// writing markdown or HTML, so it has no way to add a working link by
		// rewriting prose, and asking it to "cite a source" anyway just spends
		// a revise-pass instruction on something impossible to act on. The
		// only real link source is the Sources block, built from research
		// results rather than anything the model writes (see Blocks::sources())
		// — so a failing check here means "The sources it was written from" is
		// off, or research found fewer sources than this target, not that the
		// draft needs rewriting. That is exactly what showing 0 or a low count
		// against the target already tells whoever reads the scorecard.
		return self::check(
			'external_links',
			__( 'Sources cited', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%d', $actual ),
			sprintf( '%d+', $target ),
			5,
			''
		);
	}

	/**
	 * Internal link count.
	 *
	 * @param array $metrics   Metrics.
	 * @param array $blueprint Blueprint.
	 * @return array
	 */
	private static function internal_links( $metrics, $blueprint ) {
		$target = (int) $blueprint['internal_links_target'];
		$actual = (int) $metrics['internal_links'];
		$pass   = ( $actual >= $target );

		// Internal links are added after writing, so this is reported to the
		// person rather than asked of the model.
		return self::check(
			'internal_links',
			__( 'Internal links', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%d', $actual ),
			sprintf( '%d+', $target ),
			3,
			''
		);
	}

	/**
	 * Passive voice share.
	 *
	 * @param array $metrics Metrics.
	 * @return array
	 */
	private static function passive( $metrics ) {
		$actual = (float) $metrics['passive_share'];
		$pass   = ( $actual <= 20.0 );

		$repair = $pass ? '' : 'Much of the article is in the passive voice. Say who does what.';

		return self::check(
			'passive',
			__( 'Passive voice', 'dicecodes-ai-blog-writer' ),
			$pass,
			sprintf( '%.0f%%', $actual ),
			__( 'under 20%', 'dicecodes-ai-blog-writer' ),
			2,
			$repair
		);
	}

	/**
	 * Score a draft and return the checks behind the number.
	 *
	 * @param string $content   Rendered content.
	 * @param array  $blueprint Blueprint.
	 * @param array  $context   Optional: title, meta_description, sources.
	 * @return array Keys: score, checks, metrics.
	 */
	public static function evaluate( $content, $blueprint, $context = array() ) {
		$metrics = Blogcraft_Metrics::measure( $content, $blueprint );

		// How many sections the writer actually wrote, which is not the
		// same as how many <h2> the finished page carries. Key takeaways,
		// the questions, the numbers, the mistakes and the sources are all
		// h2 as well, so counting headings meant the heaviest check in the
		// scorecard was measuring furniture: a post asked for four to seven
		// sections, written to exactly that, and then marked down for the
		// three blocks the plugin appended to it afterwards.
		if ( isset( $context['sections'] ) ) {
			$metrics['sections'] = (int) $context['sections'];
		}

		$checks = self::checks( $metrics, $blueprint );

		// Structural checks read rendered markup rather than prose, so they
		// build their own verdicts and are merged in whole.
		$checks = array_merge( $checks, Blogcraft_Structure::checks( $content, $blueprint ) );

		// Editorial checks need the title, the meta description and the
		// research sources, none of which the rendered prose carries. Any of
		// them that is missing simply produces no check: the score is
		// earned-over-offered, so a question that could not be asked costs
		// nothing rather than costing marks.
		$checks = array_merge( $checks, Blogcraft_Editorial::checks( $content, $blueprint, $context ) );

		$earned = 0;
		$total  = 0;

		foreach ( $checks as $check ) {
			$total += $check['weight'];

			if ( $check['pass'] ) {
				$earned += $check['weight'];
			}
		}

		return array(
			'score'   => ( $total > 0 ) ? (int) round( ( $earned / $total ) * 100 ) : 100,
			'checks'  => $checks,
			'metrics' => $metrics,
		);
	}

	/**
	 * The failures a rewrite can actually do something about.
	 *
	 * Not every failing check is a writing problem. "Internal links: 1,
	 * wanted 3" means the site has nothing else on the subject to point
	 * at, and no amount of rewriting invents three related posts. Those
	 * checks carry no repair note on purpose, and the difference is what
	 * lets the plugin offer a second pass without promising to fix
	 * things it cannot reach.
	 *
	 * @param array $checks Check results.
	 * @return array Failing checks carrying a repair instruction.
	 */
	public static function fixable( $checks ) {
		$out = array();

		foreach ( (array) $checks as $check ) {
			if ( is_array( $check ) && empty( $check['pass'] ) && '' !== trim( (string) $check['repair'] ) ) {
				$out[] = $check;
			}
		}

		return $out;
	}

	/**
	 * The failures that need a person rather than another rewrite.
	 *
	 * @param array $checks Check results.
	 * @return array Failing checks with no repair instruction.
	 */
	public static function needs_you( $checks ) {
		$out = array();

		foreach ( (array) $checks as $check ) {
			if ( is_array( $check ) && empty( $check['pass'] ) && '' === trim( (string) $check['repair'] ) ) {
				$out[] = $check;
			}
		}

		return $out;
	}

	/**
	 * The repair instructions from every failed check, as prompt text.
	 *
	 * @param array $checks Checks from evaluate().
	 * @return string Empty when nothing needs fixing.
	 */
	public static function repair_notes( $checks ) {
		$lines = array();

		foreach ( (array) $checks as $check ) {
			if ( empty( $check['pass'] ) && '' !== trim( (string) $check['repair'] ) ) {
				$lines[] = '- ' . $check['repair'];
			}
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "These were measured on your draft and must be fixed:\n" . implode( "\n", $lines );
	}
}
