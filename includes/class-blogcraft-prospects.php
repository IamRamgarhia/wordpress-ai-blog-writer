<?php
/**
 * What would stop this page ranking.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * The checkable reasons a finished post will not get found.
 *
 * This is deliberately not a prediction. Nobody can tell you whether a page
 * will rank: that depends on who else is competing for the query, what your
 * domain has earned, who links to you, and what Google's index looks like on
 * the day. None of it is knowable from the words on the page, and a plugin
 * that put a number on it would be inventing one.
 *
 * Inventing one would also cost more than it is worth. The moment a single
 * figure on a screen is guesswork, the reader has no way to tell it from the
 * nineteen measurements beside it that are real, so all twenty stop being
 * worth reading.
 *
 * What can honestly be said is narrower and more useful: there are a handful
 * of things that reliably stop a page from ranking, every one of them is
 * checkable on this site with no third service and no extra key, and every one
 * of them has something the writer can do about it. So this answers "what
 * would hold this back" rather than "where will this land".
 */
class Blogcraft_Prospects {

	/**
	 * How alike two posts must be before they are competing.
	 *
	 * Lower than the duplicate threshold on purpose. Two posts do not have to
	 * be near-copies to split the same query between them; they only have to
	 * be about the same thing.
	 */
	const RIVAL_SIMILARITY = 0.45;

	/**
	 * Everything working against this post being found.
	 *
	 * @param int   $post_id Post to judge, or 0 for a draft not yet created.
	 * @param array $article Article structure.
	 * @param array $checks  Scorecard results for the draft.
	 * @param array $payload Job payload, for the topic and the evidence.
	 * @return array List of array( key, title, detail, fix ).
	 */
	public static function blockers( $post_id, $article, $checks, $payload ) {
		$found = array();

		$topic = isset( $payload['topic'] ) ? (string) $payload['topic'] : '';
		$title = isset( $payload['outline']['title'] ) ? (string) $payload['outline']['title'] : '';

		$rival = self::competing_post( '' === $title ? $topic : $title, (int) $post_id );

		if ( '' !== $rival ) {
			$found[] = array(
				'key'    => 'cannibal',
				'title'  => __( 'You already have a post about this', 'dicecodes-ai-blog-writer' ),
				'detail' => sprintf(
					/* translators: %s: the title of the existing post. */
					__( '"%s" covers much the same ground. When two of your own pages answer one question, search engines usually pick one and it is not reliably the better one — and neither page gets the links or the attention that one page would.', 'dicecodes-ai-blog-writer' ),
					$rival
				),
				'fix'    => __( 'Either give this one a clearly different angle, or fold what is new here into the post you already have.', 'dicecodes-ai-blog-writer' ),
			);
		}

		if ( '' === trim( (string) self::evidence_of( $payload ) ) ) {
			$found[] = array(
				'key'    => 'nothing_new',
				'title'  => __( 'There is nothing here that is only yours', 'dicecodes-ai-blog-writer' ),
				'detail' => __( 'Every fact in this post can be found on the pages it was written from. A page that restates what is already ranking has no argument for being ranked above it, and nothing in it another writer would cite.', 'dicecodes-ai-blog-writer' ),
				'fix'    => __( 'Add what you measured, paid, tried or got wrong. One paragraph of it is worth more than a thousand words that are not yours.', 'dicecodes-ai-blog-writer' ),
			);
		}

		$orphan = self::links_in( $checks );

		if ( $orphan > 0 ) {
			$found[] = array(
				'key'    => 'orphan',
				'title'  => __( 'Almost nothing on your site points at it', 'dicecodes-ai-blog-writer' ),
				'detail' => __( 'A page nothing links to is one a crawler reaches last and a reader never reaches at all. This is not something rewriting the page can change.', 'dicecodes-ai-blog-writer' ),
				'fix'    => __( 'Write, or already have, a few posts on neighbouring subjects — links between them are added automatically once they exist.', 'dicecodes-ai-blog-writer' ),
			);
		}

		if ( self::thin( $checks ) ) {
			$found[] = array(
				'key'    => 'thin',
				'title'  => __( 'It is shorter than the question deserves', 'dicecodes-ai-blog-writer' ),
				'detail' => __( 'Length is not a ranking factor on its own, and padding does not help. But a page well under what it takes to answer the question tends to be the one that leaves the reader still looking.', 'dicecodes-ai-blog-writer' ),
				'fix'    => __( 'Either cover more of the question, or narrow the title to what this post actually answers.', 'dicecodes-ai-blog-writer' ),
			);
		}

		return $found;
	}

	/**
	 * The plainest statement of what this can and cannot tell you.
	 *
	 * Kept next to the findings on purpose. A list headed "what would stop this
	 * ranking" invites being read as "and otherwise it will", which is a claim
	 * nothing here supports.
	 *
	 * @return string
	 */
	public static function caveat() {
		return __( 'None of this predicts a position. Whether a page ranks depends on who else wants the same query and what your site has earned, and no plugin can see either. These are the things working against it that can actually be checked here.', 'dicecodes-ai-blog-writer' );
	}

	/**
	 * Another post of your own covering the same ground.
	 *
	 * @param string $title   Title or topic of the post being judged.
	 * @param int    $exclude Post id to leave out.
	 * @return string Title of the closest rival, or '' when there is none.
	 */
	private static function competing_post( $title, $exclude ) {
		$title = trim( $title );

		if ( '' === $title ) {
			return '';
		}

		// No 'exclude'. It becomes a NOT IN, which stops MySQL using the
		// index on a table that grows for ever, and this loop is already
		// visiting every row — so skipping one id here costs nothing and
		// keeps the query plain.
		$existing = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'future' ),
				'posts_per_page'   => 100,
				'suppress_filters' => false,
			)
		);

		$best  = '';
		$score = self::RIVAL_SIMILARITY;

		foreach ( $existing as $post ) {
			if ( $exclude > 0 && (int) $post->ID === (int) $exclude ) {
				continue;
			}

			$how_alike = Blogcraft_Backlinks::similarity( $title, $post->post_title );

			if ( $how_alike >= $score ) {
				$score = $how_alike;
				$best  = $post->post_title;
			}
		}

		return $best;
	}

	/**
	 * Whatever the writer supplied as their own material.
	 *
	 * @param array $payload Job payload.
	 * @return string
	 */
	private static function evidence_of( $payload ) {
		return isset( $payload['evidence'] ) ? (string) $payload['evidence'] : '';
	}

	/**
	 * How far short of the internal link target this post falls.
	 *
	 * @param array $checks Scorecard results.
	 * @return int Zero when the check passed or was not run.
	 */
	private static function links_in( $checks ) {
		foreach ( (array) $checks as $check ) {
			if ( is_array( $check ) && 'internal_links' === $check['key'] && empty( $check['pass'] ) ) {
				return max( 1, (int) $check['target'] - (int) $check['actual'] );
			}
		}

		return 0;
	}

	/**
	 * Whether the post came in under the length it was written to.
	 *
	 * Only under. Over-length is a tidiness problem; under-length is usually a
	 * question that did not get answered.
	 *
	 * @param array $checks Scorecard results.
	 * @return bool
	 */
	private static function thin( $checks ) {
		foreach ( (array) $checks as $check ) {
			if ( is_array( $check ) && 'word_count' === $check['key'] && empty( $check['pass'] ) ) {
				$actual = (int) $check['actual'];
				$wanted = (int) preg_replace( '/[^0-9].*$/', '', (string) $check['target'] );

				return ( $wanted > 0 && $actual < $wanted );
			}
		}

		return false;
	}
}
