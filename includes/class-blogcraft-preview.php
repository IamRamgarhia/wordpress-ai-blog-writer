<?php
/**
 * Predicting the shape of a post before it is written.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out what a blueprint will actually produce, before any tokens are spent.
 *
 * This shows structure, not prose. Rendering invented sentences as a preview
 * would be dishonest, because the words are the one thing that genuinely cannot
 * be known until the model writes them. What can be known exactly is the shape:
 * which blocks appear, in what order, how the word budget divides between them,
 * and roughly what the run will cost. That is also the part people get wrong —
 * a 600-word target across eight sections gives 60-word sections, and seeing
 * that before queuing is worth more than a paragraph of fake text.
 */
class Blogcraft_Preview {

	/**
	 * Rough characters per token, for cost estimates.
	 *
	 * Four is the usual English approximation. Close enough to warn someone
	 * that a 4000-word target is expensive; not close enough to bill from.
	 */
	const CHARS_PER_TOKEN = 4;

	/**
	 * Average characters per word, including the following space.
	 */
	const CHARS_PER_WORD = 6;

	/**
	 * The blocks a blueprint will produce, in order.
	 *
	 * @param array $blueprint Blueprint.
	 * @return array List of array( type, label, words, note ).
	 */
	public static function shape( $blueprint ) {
		$sections = max( 1, (int) round( ( (int) $blueprint['sections_min'] + (int) $blueprint['sections_max'] ) / 2 ) );
		$total    = (int) $blueprint['word_target'];

		// Fixed-ish furniture first, so the sections divide what is left rather
		// than the target pretending the takeaways cost nothing.
		$intro     = (int) round( $total * 0.08 );
		$takeaways = (bool) $blueprint['takeaways'] ? ( (int) $blueprint['takeaways_count'] * 18 ) : 0;
		$faq       = (bool) $blueprint['faq'] ? ( (int) $blueprint['faq_count'] * 45 ) : 0;
		$ending    = ( 'none' === (string) $blueprint['conclusion_style'] ) ? 0 : (int) round( $total * 0.07 );

		$body      = max( 0, $total - $intro - $takeaways - $faq - $ending );
		$per_block = ( $sections > 0 ) ? (int) round( $body / $sections ) : 0;

		$out = array();

		if ( (bool) $blueprint['toc'] ) {
			$out[] = self::block( 'toc', __( 'Table of contents', 'blogcraft' ), 0, '' );
		}

		$intros = Blogcraft_Blueprint::intro_styles();
		$style  = (string) $blueprint['intro_style'];

		$out[] = self::block(
			'intro',
			__( 'Introduction', 'blogcraft' ),
			$intro,
			isset( $intros[ $style ] ) ? $intros[ $style ] : ''
		);

		if ( (bool) $blueprint['takeaways'] ) {
			$out[] = self::block(
				'takeaways',
				__( 'Key takeaways', 'blogcraft' ),
				$takeaways,
				sprintf(
					/* translators: %d: number of takeaway points. */
					_n( '%d point', '%d points', (int) $blueprint['takeaways_count'], 'blogcraft' ),
					(int) $blueprint['takeaways_count']
				)
			);
		}

		if ( (int) $blueprint['images_target'] > 0 ) {
			$out[] = self::block( 'image', __( 'Featured image', 'blogcraft' ), 0, '' );
		}

		for ( $i = 1; $i <= $sections; $i++ ) {
			$out[] = self::block(
				'section',
				sprintf(
					/* translators: %d: section number. */
					__( 'Section %d', 'blogcraft' ),
					$i
				),
				$per_block,
				( (bool) $blueprint['allow_h3'] && $per_block > 250 ) ? __( 'may use subheadings', 'blogcraft' ) : ''
			);
		}

		if ( $ending > 0 ) {
			$closes = Blogcraft_Blueprint::conclusion_styles();
			$close  = (string) $blueprint['conclusion_style'];

			$out[] = self::block(
				'ending',
				__( 'Ending', 'blogcraft' ),
				$ending,
				isset( $closes[ $close ] ) ? $closes[ $close ] : ''
			);
		}

		if ( (bool) $blueprint['faq'] ) {
			$out[] = self::block(
				'faq',
				__( 'Questions and answers', 'blogcraft' ),
				$faq,
				sprintf(
					/* translators: %d: number of questions. */
					_n( '%d question', '%d questions', (int) $blueprint['faq_count'], 'blogcraft' ),
					(int) $blueprint['faq_count']
				)
			);
		}

		if ( (int) $blueprint['internal_links_target'] > 0 && Blogcraft_Settings::get( 'internal_links_enabled' ) ) {
			$out[] = self::block( 'related', __( 'Links to your other posts', 'blogcraft' ), 0, '' );
		}

		return $out;
	}

	/**
	 * Assemble one block description.
	 *
	 * @param string $type  Block kind, for styling.
	 * @param string $label Human label.
	 * @param int    $words Word budget, zero when it carries none.
	 * @param string $note  Short qualifier.
	 * @return array
	 */
	private static function block( $type, $label, $words, $note ) {
		return array(
			'type'  => $type,
			'label' => $label,
			'words' => (int) $words,
			'note'  => (string) $note,
		);
	}

	/**
	 * Warnings about a blueprint that will produce something awkward.
	 *
	 * Cheap to check here and expensive to discover after a run: thin sections
	 * and impossible reading targets are the two that waste the most tokens.
	 *
	 * @param array $blueprint Blueprint.
	 * @param array $shape     Output of shape().
	 * @return array Warning strings.
	 */
	public static function warnings( $blueprint, $shape ) {
		$out      = array();
		$sections = array();

		foreach ( $shape as $block ) {
			if ( 'section' === $block['type'] ) {
				$sections[] = $block['words'];
			}
		}

		if ( ! empty( $sections ) && min( $sections ) < 90 ) {
			$out[] = sprintf(
				/* translators: %d: words available per section. */
				__( 'That leaves about %d words a section, which is too thin to say anything. Raise the length or cut the number of sections.', 'blogcraft' ),
				min( $sections )
			);
		}

		if ( ! empty( $sections ) && min( $sections ) > 600 ) {
			$out[] = __( 'Sections this long tend to wander. More sections usually reads better than longer ones.', 'blogcraft' );
		}

		$fixed = 0;

		foreach ( $shape as $block ) {
			if ( in_array( $block['type'], array( 'takeaways', 'faq' ), true ) ) {
				$fixed += $block['words'];
			}
		}

		if ( $fixed > (int) $blueprint['word_target'] * 0.4 ) {
			$out[] = __( 'The takeaways and questions take up most of the word budget, leaving little for the article itself.', 'blogcraft' );
		}

		if ( 'expert' === (string) $blueprint['reading_level'] && (int) $blueprint['sentence_max_words'] < 20 ) {
			$out[] = __( 'An expert reading level with a short sentence limit pulls in opposite directions. One of the two checks will usually fail.', 'blogcraft' );
		}

		if ( '' !== trim( (string) $blueprint['primary_keyword'] ) ) {
			$target = (int) $blueprint['word_target'];
			$least  = (float) $blueprint['density_min'];
			$needed = (int) ceil( ( $target * $least ) / 100 );

			if ( $needed > 0 && $needed < 2 ) {
				$out[] = __( 'At this length the target phrase only needs to appear once to pass. Consider raising the minimum.', 'blogcraft' );
			}
		}

		return $out;
	}

	/**
	 * A rough token estimate for one full run.
	 *
	 * Seven stages, but only four of them send or receive an article-sized
	 * payload. Deliberately an over-estimate: a surprise on a bill is worse
	 * than a pleasant one.
	 *
	 * @param array $blueprint Blueprint.
	 * @return array Keys: prompt, completion, total.
	 */
	public static function tokens( $blueprint ) {
		$words    = (int) $blueprint['word_target'];
		$article  = (int) round( ( $words * self::CHARS_PER_WORD ) / self::CHARS_PER_TOKEN );
		$overhead = 900; // Rules, reference material and JSON scaffolding per call.

		// draft writes one article; critique reads one; revise reads and writes.
		$completion = $article * 2;
		$prompt     = ( $article * 3 ) + ( $overhead * 5 );

		return array(
			'prompt'     => $prompt,
			'completion' => $completion,
			'total'      => $prompt + $completion,
		);
	}

	/**
	 * Whether a topic clashes with something already written or queued.
	 *
	 * @param string $topic Candidate topic.
	 * @return string The clashing topic, or '' when it is fresh.
	 */
	public static function clash( $topic ) {
		$topic = trim( (string) $topic );

		if ( '' === $topic || ! Blogcraft_Settings::get( 'duplicate_check_enabled' ) ) {
			return '';
		}

		$existing = Blogcraft_Backlinks::find_duplicate( $topic );

		if ( '' !== $existing ) {
			return $existing;
		}

		return Blogcraft_Backlinks::find_queued_duplicate( $topic );
	}
}
