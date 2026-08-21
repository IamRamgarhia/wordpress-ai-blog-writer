<?php
/**
 * Recognisable shapes a blog post can take.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Starting points, each a whole set of rules rather than one setting.
 *
 * Forty-eight fields is a lot to face from defaults, and most posts are one of
 * a handful of recognisable shapes. A definitive guide is long, sectioned,
 * carries a contents list and answers the obvious questions at the end. A news
 * explainer is short, fast and has none of that. Choosing the shape sets twenty
 * fields at once, correctly, and every one of them stays editable afterwards.
 *
 * These are shapes, not imitations of particular publications. A preset named
 * after somebody else's site would be claiming to reproduce work that is theirs
 * and would go stale the moment they changed how they write. Blogcraft_Emulate
 * does that job properly: point it at any article and it measures the real one.
 */
class Blogcraft_Archetypes {

	/**
	 * Every shape on offer.
	 *
	 * @return array Slug => array( label, blurb, fields ).
	 */
	public static function all() {
		return array(
			'guide'      => array(
				'label'  => __( 'Definitive guide', 'blogcraft' ),
				'blurb'  => __( 'Long, thoroughly sectioned, with a contents list and the obvious questions answered at the end. The shape that ranks for a broad subject.', 'blogcraft' ),
				'fields' => array(
					'word_target'           => 2200,
					'sections_min'          => 6,
					'sections_max'          => 10,
					'toc'                   => true,
					'faq'                   => true,
					'faq_count'             => 5,
					'takeaways'             => true,
					'takeaways_count'       => 5,
					'tables'                => true,
					'lists'                 => true,
					'intro_style'           => 'direct',
					'conclusion_style'      => 'summary',
					'external_links_target' => 5,
					'internal_links_target' => 3,
					'require_citations'     => true,
					'require_statistics'    => true,
					'images_target'         => 4,
				),
			),
			'listicle'   => array(
				'label'  => __( 'Numbered list, with a verdict', 'blogcraft' ),
				'blurb'  => __( 'One item per section, each covered the same way, and an actual recommendation rather than "it depends".', 'blogcraft' ),
				'fields' => array(
					'word_target'           => 1800,
					'sections_min'          => 5,
					'sections_max'          => 12,
					'toc'                   => true,
					'faq'                   => false,
					'takeaways'             => true,
					'takeaways_count'       => 3,
					'tables'                => true,
					'lists'                 => true,
					'intro_style'           => 'direct',
					'conclusion_style'      => 'action',
					'external_links_target' => 4,
					'require_experience'    => true,
					'images_target'         => 4,
				),
			),
			'tutorial'   => array(
				'label'  => __( 'Step by step', 'blogcraft' ),
				'blurb'  => __( 'One step per section, in order, with what to expect at each. Short sentences, no throat-clearing.', 'blogcraft' ),
				'fields' => array(
					'word_target'           => 1400,
					'sections_min'          => 4,
					'sections_max'          => 9,
					'toc'                   => true,
					'faq'                   => true,
					'faq_count'             => 3,
					'takeaways'             => false,
					'lists'                 => true,
					'tables'                => false,
					'sentence_max_words'    => 22,
					'para_max_sentences'    => 3,
					'reading_level'         => 'simple',
					'intro_style'           => 'direct',
					'conclusion_style'      => 'next_steps',
					'require_experience'    => true,
					'external_links_target' => 2,
					'images_target'         => 5,
				),
			),
			'comparison' => array(
				'label'  => __( 'This against that', 'blogcraft' ),
				'blurb'  => __( 'Both sides covered on the same criteria, a table, and a straight answer about who each one suits.', 'blogcraft' ),
				'fields' => array(
					'word_target'           => 1600,
					'sections_min'          => 5,
					'sections_max'          => 8,
					'toc'                   => true,
					'faq'                   => true,
					'faq_count'             => 4,
					'takeaways'             => true,
					'tables'                => true,
					'lists'                 => true,
					'intro_style'           => 'direct',
					'conclusion_style'      => 'action',
					'require_statistics'    => true,
					'require_citations'     => true,
					'external_links_target' => 4,
					'images_target'         => 3,
				),
			),
			'study'      => array(
				'label'  => __( 'Data study', 'blogcraft' ),
				'blurb'  => __( 'Built around figures, with the method stated and every number sourced. The shape most likely to be cited by other people.', 'blogcraft' ),
				'fields' => array(
					'word_target'           => 2000,
					'sections_min'          => 5,
					'sections_max'          => 9,
					'toc'                   => true,
					'faq'                   => false,
					'takeaways'             => true,
					'takeaways_count'       => 5,
					'tables'                => true,
					'lists'                 => false,
					'intro_style'           => 'direct',
					'conclusion_style'      => 'summary',
					'require_statistics'    => true,
					'require_citations'     => true,
					'require_experience'    => true,
					'external_links_target' => 8,
					'images_target'         => 3,
				),
			),
			'opinion'    => array(
				'label'  => __( 'An argued opinion', 'blogcraft' ),
				'blurb'  => __( 'A position held from the first line and defended. First person, longer sentences, no hedging.', 'blogcraft' ),
				'fields' => array(
					'word_target'           => 1200,
					'sections_min'          => 3,
					'sections_max'          => 6,
					'toc'                   => false,
					'faq'                   => false,
					'takeaways'             => false,
					'tables'                => false,
					'lists'                 => false,
					'point_of_view'         => 'first_person',
					'sentence_max_words'    => 34,
					'para_max_sentences'    => 5,
					'intro_style'           => 'problem',
					'conclusion_style'      => 'action',
					'require_experience'    => true,
					'external_links_target' => 3,
					'images_target'         => 1,
				),
			),
			'explainer'  => array(
				'label'  => __( 'Quick explainer', 'blogcraft' ),
				'blurb'  => __( 'Answers in the first two sentences and stops when it is finished. For something people are searching for today.', 'blogcraft' ),
				'fields' => array(
					'word_target'           => 800,
					'sections_min'          => 3,
					'sections_max'          => 5,
					'toc'                   => false,
					'faq'                   => true,
					'faq_count'             => 3,
					'takeaways'             => true,
					'takeaways_count'       => 3,
					'tables'                => false,
					'lists'                 => true,
					'sentence_max_words'    => 24,
					'para_max_sentences'    => 3,
					'reading_level'         => 'simple',
					'intro_style'           => 'direct',
					'conclusion_style'      => 'none',
					'require_citations'     => true,
					'external_links_target' => 3,
					'images_target'         => 1,
				),
			),
			'review'     => array(
				'label'  => __( 'Hands-on review', 'blogcraft' ),
				'blurb'  => __( 'Written from having used the thing. Specific about what went wrong as well as what worked.', 'blogcraft' ),
				'fields' => array(
					'word_target'           => 1700,
					'sections_min'          => 5,
					'sections_max'          => 9,
					'toc'                   => true,
					'faq'                   => true,
					'faq_count'             => 4,
					'takeaways'             => true,
					'tables'                => true,
					'lists'                 => true,
					'intro_style'           => 'direct',
					'conclusion_style'      => 'action',
					'require_experience'    => true,
					'require_statistics'    => true,
					'external_links_target' => 2,
					'images_target'         => 5,
				),
			),
		);
	}

	/**
	 * One shape's blueprint fields, ready to merge.
	 *
	 * Only fields the blueprint actually has. A preset naming a field that no
	 * longer exists would set nothing and say nothing, which is the quiet kind
	 * of wrong.
	 *
	 * @param string $slug Shape slug.
	 * @return array Empty when the slug is unknown.
	 */
	public static function fields( $slug ) {
		$all  = self::all();
		$slug = (string) $slug;

		if ( ! isset( $all[ $slug ] ) ) {
			return array();
		}

		$known   = Blogcraft_Blueprint::fields();
		$choices = self::choice_values();
		$out     = array();

		foreach ( $all[ $slug ]['fields'] as $key => $value ) {
			if ( ! isset( $known[ $key ] ) ) {
				continue;
			}

			// A value outside the offered list would be stored and then ignored
			// by every control that reads it, so the shape would silently do
			// less than it said.
			if ( isset( $choices[ $key ] ) && ! in_array( $value, $choices[ $key ], true ) ) {
				continue;
			}

			$out[ $key ] = $value;
		}

		return $out;
	}

	/**
	 * The values each choice field will actually accept.
	 *
	 * @return array Field => list of valid values.
	 */
	public static function choice_values() {
		return array(
			'intro_style'      => array_keys( Blogcraft_Blueprint::intro_styles() ),
			'conclusion_style' => array_keys( Blogcraft_Blueprint::conclusion_styles() ),
			'point_of_view'    => array_keys( Blogcraft_Blueprint::points_of_view() ),
			'reading_level'    => array_keys( Blogcraft_Blueprint::reading_levels() ),
		);
	}

	/**
	 * Labels only, for a select.
	 *
	 * @return array
	 */
	public static function choices() {
		$out = array( '' => __( 'Start from my own rules', 'blogcraft' ) );

		foreach ( self::all() as $slug => $shape ) {
			$out[ $slug ] = $shape['label'];
		}

		return $out;
	}
}
