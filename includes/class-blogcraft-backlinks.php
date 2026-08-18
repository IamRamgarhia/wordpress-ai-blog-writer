<?php
/**
 * Backward internal linking and duplicate-topic detection.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Links older posts forward to a newly published one, and blocks repeats.
 *
 * Every competing tool links only forward: the new post points at old ones, and
 * the old ones never learn the new post exists. Editing the older posts to point
 * at the new one is the half agencies charge for, and it is what actually moves
 * authority around a site.
 *
 * The inserted block is delimited by HTML comments so it can be rewritten in
 * place on later runs. Without those markers a post would accumulate a new
 * "Related reading" list every time anything related was published.
 */
class Blogcraft_Backlinks {

	/**
	 * Opening marker for the managed block.
	 */
	const START = '<!-- blogcraft:related:start -->';

	/**
	 * Closing marker for the managed block.
	 */
	const END = '<!-- blogcraft:related:end -->';

	/**
	 * Meta key recording the topic a generated post came from.
	 */
	const TOPIC_META = '_blogcraft_topic';

	/**
	 * Strip the managed block from a post's content.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function strip_block( $content ) {
		$content = (string) $content;
		$start   = strpos( $content, self::START );

		if ( false === $start ) {
			return $content;
		}

		$end = strpos( $content, self::END, $start );

		if ( false === $end ) {
			return $content;
		}

		$before = substr( $content, 0, $start );
		$after  = substr( $content, $end + strlen( self::END ) );

		return rtrim( $before ) . $after;
	}

	/**
	 * Build the managed block for a set of links.
	 *
	 * @param array $links List of array( 'title', 'url' ).
	 * @return string Empty when there is nothing to link.
	 */
	public static function build_block( $links ) {
		$items = '';

		foreach ( (array) $links as $link ) {
			if ( empty( $link['url'] ) || empty( $link['title'] ) ) {
				continue;
			}

			$items .= sprintf(
				"<!-- wp:list-item -->\n<li><a href=\"%s\">%s</a></li>\n<!-- /wp:list-item -->\n",
				esc_url( $link['url'] ),
				esc_html( $link['title'] )
			);
		}

		if ( '' === $items ) {
			return '';
		}

		return "\n\n" . self::START . "\n"
			. Blogcraft_Blocks::heading( __( 'Related reading', 'blogcraft' ), 2 )
			. "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n" . $items . "</ul>\n<!-- /wp:list -->\n"
			. self::END;
	}

	/**
	 * Point older related posts at a newly published one.
	 *
	 * @param int    $new_post_id Post that was just created.
	 * @param string $topic       Topic it was generated from.
	 * @param int    $limit       How many older posts to update.
	 * @return int Number of posts updated.
	 */
	public static function link_back( $new_post_id, $topic, $limit = 3 ) {
		if ( ! Blogcraft_Settings::get( 'backlinks_enabled' ) ) {
			return 0;
		}

		$new_post = get_post( $new_post_id );

		// Only a published post is worth linking to; a draft would 404 for readers.
		if ( ! $new_post || 'publish' !== $new_post->post_status ) {
			return 0;
		}

		$targets = Blogcraft_Seo::related_posts( $topic, (int) $limit, (int) $new_post_id );
		$link    = array(
			array(
				'title' => get_the_title( $new_post ),
				'url'   => get_permalink( $new_post ),
			),
		);

		$block   = self::build_block( $link );
		$updated = 0;

		if ( '' === $block ) {
			return 0;
		}

		foreach ( $targets as $target ) {
			$post = get_post( $target['id'] );

			if ( ! $post ) {
				continue;
			}

			$stripped = self::strip_block( $post->post_content );

			$result = wp_update_post(
				array(
					'ID'           => (int) $post->ID,
					'post_content' => $stripped . $block,
				),
				true
			);

			if ( ! is_wp_error( $result ) ) {
				++$updated;
			}
		}

		if ( $updated > 0 ) {
			Blogcraft_Logger::info(
				'Linked older posts to a new one.',
				array(
					'new_post_id' => (int) $new_post_id,
					'updated'     => $updated,
				),
				null
			);
		}

		return $updated;
	}

	/**
	 * Reduce a topic to a comparable fingerprint.
	 *
	 * @param string $topic Topic text.
	 * @return string
	 */
	public static function fingerprint( $topic ) {
		$words = Blogcraft_Voice::to_list( str_replace( ' ', ',', strtolower( (string) $topic ) ) );
		$stop  = array( 'the', 'a', 'an', 'and', 'or', 'for', 'of', 'to', 'in', 'on', 'how', 'why', 'what', 'is', 'are', 'best', 'guide' );
		$keep  = array();

		foreach ( $words as $word ) {
			$word = preg_replace( '/[^a-z0-9]/', '', $word );

			if ( strlen( (string) $word ) > 2 && ! in_array( $word, $stop, true ) ) {
				$keep[] = self::stem( (string) $word );
			}
		}

		sort( $keep );

		return implode( ' ', array_unique( $keep ) );
	}

	/**
	 * Crudely reduce a word to its stem.
	 *
	 * Without this, "brewing" and "brew" read as unrelated and a near-identical
	 * topic slips past the duplicate check. A full stemmer is overkill here —
	 * trimming the three commonest English suffixes catches the cases that
	 * matter for topic comparison.
	 *
	 * @param string $word Lowercase word.
	 * @return string
	 */
	public static function stem( $word ) {
		foreach ( array( 'ing', 'ed', 'es', 's' ) as $suffix ) {
			$length = strlen( $suffix );

			if ( strlen( $word ) > $length + 3 && substr( $word, -$length ) === $suffix ) {
				return substr( $word, 0, -$length );
			}
		}

		return $word;
	}

	/**
	 * How much two topics overlap, from 0 to 1.
	 *
	 * @param string $a First topic.
	 * @param string $b Second topic.
	 * @return float
	 */
	public static function similarity( $a, $b ) {
		$left  = array_filter( explode( ' ', self::fingerprint( $a ) ) );
		$right = array_filter( explode( ' ', self::fingerprint( $b ) ) );

		if ( empty( $left ) || empty( $right ) ) {
			return 0.0;
		}

		$shared = count( array_intersect( $left, $right ) );
		$total  = count( array_unique( array_merge( $left, $right ) ) );

		return ( $total > 0 ) ? (float) ( $shared / $total ) : 0.0;
	}

	/**
	 * Whether a topic repeats one already sitting in the queue.
	 *
	 * @param string $topic     Candidate topic.
	 * @param float  $threshold Similarity above which it counts as a repeat.
	 * @return string The clashing topic, or '' when the candidate is fresh.
	 */
	public static function find_queued_duplicate( $topic, $threshold = 0.7 ) {
		foreach ( Blogcraft_Queue::pending_topics() as $queued ) {
			if ( self::similarity( $topic, $queued ) >= $threshold ) {
				return $queued;
			}
		}

		return '';
	}

	/**
	 * Whether a topic closely repeats something already generated.
	 *
	 * Repetitive near-identical posts are precisely what search engines treat as
	 * scaled content abuse, so catching this before generation protects the site
	 * and saves the tokens a duplicate would have cost.
	 *
	 * @param string $topic     Candidate topic.
	 * @param float  $threshold Similarity above which it counts as a duplicate.
	 * @return string The clashing topic, or '' when the candidate is fresh.
	 */
	public static function find_duplicate( $topic, $threshold = 0.7 ) {
		$existing = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'meta_key'       => self::TOPIC_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'no_found_rows'  => true,
			)
		);

		foreach ( $existing as $post_id ) {
			$previous = (string) get_post_meta( $post_id, self::TOPIC_META, true );

			if ( '' === $previous ) {
				continue;
			}

			if ( self::similarity( $topic, $previous ) >= $threshold ) {
				return $previous;
			}
		}

		return '';
	}
}
