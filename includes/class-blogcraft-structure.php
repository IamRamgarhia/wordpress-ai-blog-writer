<?php
/**
 * Structural checks on a finished draft.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Measures the parts of published SEO guidance that can actually be checked.
 *
 * Kept apart from Blogcraft_Metrics deliberately. These read rendered markup
 * rather than prose, and each one both measures and states its own verdict, so
 * a caller cannot pick up a number and forget what it was supposed to mean.
 *
 * Every check here corresponds to something Google documents: alt text on
 * images, heading levels that describe a real hierarchy, and sections that
 * carry enough substance to be worth a heading at all. Nothing here is a
 * ranking promise; they are the checkable subset of published guidance.
 */
class Blogcraft_Structure {

	/**
	 * Images carrying no usable alt text.
	 *
	 * @param string $content Rendered post content.
	 * @return int
	 */
	public static function images_without_alt( $content ) {
		$found = preg_match_all( '/<img[^>]*>/i', (string) $content, $matches );

		if ( ! $found ) {
			return 0;
		}

		$missing = 0;

		foreach ( $matches[0] as $tag ) {
			$has_alt = preg_match( '/alt=("[^"]*"|\'[^\']*\')/i', $tag, $alt );

			if ( ! $has_alt ) {
				++$missing;
				continue;
			}

			// Strip the surrounding quotes and see whether anything is left.
			if ( '' === trim( substr( $alt[1], 1, -1 ) ) ) {
				++$missing;
			}
		}

		return $missing;
	}

	/**
	 * Heading levels in the order they appear.
	 *
	 * @param string $content Rendered post content.
	 * @return array List of integers.
	 */
	public static function heading_levels( $content ) {
		$found = preg_match_all( '/<h([1-6])[ >]/i', (string) $content, $matches );

		if ( ! $found ) {
			return array();
		}

		$out = array();

		foreach ( $matches[1] as $level ) {
			$out[] = (int) $level;
		}

		return $out;
	}

	/**
	 * Whether headings descend without skipping a level.
	 *
	 * An H2 followed by an H4 hands a parser a hierarchy that does not match
	 * the one a reader sees on the page.
	 *
	 * @param string $content Rendered post content.
	 * @return bool
	 */
	public static function heading_order_ok( $content ) {
		$levels   = self::heading_levels( $content );
		$previous = 0;

		foreach ( $levels as $level ) {
			if ( $previous > 0 && $level > $previous + 1 ) {
				return false;
			}

			$previous = $level;
		}

		return true;
	}

	/**
	 * Words in the thinnest top-level section.
	 *
	 * A total word count that passes can still hide one forty-word section,
	 * and a thin section is exactly what reads as padding.
	 *
	 * @param string $content Rendered post content.
	 * @return int Zero when there are no sections to measure.
	 */
	public static function thinnest_section( $content ) {
		$content = (string) $content;
		$offsets = array();

		if ( preg_match_all( '/<h2[ >]/i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[0] as $match ) {
				$offsets[] = (int) $match[1];
			}
		}

		if ( count( $offsets ) < 1 ) {
			return 0;
		}

		$counts = array();
		$total  = count( $offsets );

		for ( $i = 0; $i < $total; $i++ ) {
			$start    = $offsets[ $i ];
			$end      = ( $i + 1 < $total ) ? $offsets[ $i + 1 ] : strlen( $content );
			$chunk    = substr( $content, $start, $end - $start );
			$counts[] = count( Blogcraft_Metrics::words( Blogcraft_Metrics::plain_text( $chunk ) ) );
		}

		return empty( $counts ) ? 0 : min( $counts );
	}

	/**
	 * The checks these measurements support, ready for the scorecard.
	 *
	 * Returned in the scorecard's own shape so the caller only has to merge
	 * them in, and so the weights live beside the reasoning for them.
	 *
	 * @param string $content   Rendered post content.
	 * @param array  $blueprint Blueprint the draft was written to.
	 * @return array List of checks.
	 */
	public static function checks( $content, $blueprint ) {
		$out = array();

		$missing = self::images_without_alt( $content );

		$out[] = array(
			'key'    => 'alt_text',
			'label'  => __( 'Image alt text', 'blogcraft' ),
			'pass'   => ( 0 === $missing ),
			'actual' => sprintf( '%d missing', $missing ),
			'target' => __( 'none missing', 'blogcraft' ),
			'weight' => 6,
			'repair' => '',
		);

		$ordered = self::heading_order_ok( $content );

		$out[] = array(
			'key'    => 'heading_order',
			'label'  => __( 'Heading order', 'blogcraft' ),
			'pass'   => $ordered,
			'actual' => $ordered ? __( 'in order', 'blogcraft' ) : __( 'a level is skipped', 'blogcraft' ),
			'target' => __( 'no skipped levels', 'blogcraft' ),
			'weight' => 6,
			'repair' => $ordered
				? ''
				: 'Heading levels skip a step. Use a sub-heading only directly beneath a main heading, and never jump two levels at once.',
		);

		$thinnest = self::thinnest_section( $content );

		// Two fifths of an even split is where a section stops saying anything,
		// and it scales with the length actually asked for rather than a
		// number that would be wrong for a 400-word post and a 4000-word one.
		$sections = max( 1, (int) $blueprint['sections_max'] );
		$floor    = max( 60, (int) round( ( (int) $blueprint['word_target'] / $sections ) * 0.4 ) );
		$thin_ok  = ( 0 === $thinnest || $thinnest >= $floor );

		$out[] = array(
			'key'    => 'thin_section',
			'label'  => __( 'Thinnest section', 'blogcraft' ),
			'pass'   => $thin_ok,
			'actual' => sprintf( '%d words', $thinnest ),
			'target' => sprintf( '%d+', $floor ),
			'weight' => 10,
			'repair' => $thin_ok
				? ''
				: sprintf(
					'One section runs only %1$d words against a %2$d floor. Give it the same depth as the others, or fold it into the section beside it.',
					$thinnest,
					$floor
				),
		);

		return $out;
	}
}
