<?php
/**
 * Reading a site's existing posts to fill in its own settings.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out how a blog already writes, so its settings do not start empty.
 *
 * A screen of blank textareas asking someone to describe their own voice is
 * the point most people give up. The answers are already on the site: a
 * hundred posts saying what the subject is, how long the paragraphs run,
 * whether the writer says "I" or "you", whether they use em dashes.
 *
 * Two kinds of answer are produced, and they are kept apart deliberately.
 * Style rules are *measured* from the posts and are simply true. The prose
 * fields — what the blog is about, who reads it — cannot be measured, so they
 * are drafted by the model from real titles and excerpts, and only when a
 * provider is configured. Nothing is saved: everything lands in the form for
 * the person to correct before they press save. Guessing on someone's behalf
 * is helpful; guessing silently is not.
 *
 * Posts Blogcraft wrote itself are excluded. Learning a voice from your own
 * output is a feedback loop that ends with every post sounding like the first
 * one it ever generated.
 */
class Blogcraft_Learn {

	/**
	 * How many posts to read.
	 */
	const SAMPLE = 25;

	/**
	 * Published posts the site wrote itself, newest first.
	 *
	 * @param int $limit How many to read.
	 * @return array WP_Post objects.
	 */
	public static function sample( $limit = self::SAMPLE ) {
		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, (int) $limit ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_blogcraft_generated',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
	}

	/**
	 * What can be measured about how this blog already writes.
	 *
	 * @param array $posts Posts to read, or null to fetch a sample.
	 * @return array Keys: posts, words, sentence_words, para_sentences, em_dash,
	 *               contractions, person, subjects, titles, excerpts.
	 */
	public static function observe( $posts = null ) {
		$posts = ( null === $posts ) ? self::sample() : (array) $posts;

		$out = array(
			'posts'          => 0,
			'words'          => 0,
			'sentence_words' => 0,
			'para_sentences' => 0,
			'em_dash'        => 0,
			'contractions'   => 0,
			'person'         => '',
			'subjects'       => array(),
			'titles'         => array(),
			'excerpts'       => array(),
		);

		if ( empty( $posts ) ) {
			return $out;
		}

		$words      = array();
		$sentences  = array();
		$paragraphs = array();
		$dashes     = 0;
		$shortened  = 0;
		$first      = 0;
		$second     = 0;
		$subjects   = array();

		foreach ( $posts as $post ) {
			$text = Blogcraft_Metrics::plain_text( $post->post_content );

			if ( '' === trim( $text ) ) {
				continue;
			}

			++$out['posts'];

			$post_words     = Blogcraft_Metrics::words( $text );
			$post_sentences = Blogcraft_Metrics::sentences( $text );

			$words[] = count( $post_words );

			if ( ! empty( $post_sentences ) ) {
				$sentences[] = count( $post_words ) / count( $post_sentences );
			}

			$blocks = preg_split( '/\n{2,}/u', $text, -1, PREG_SPLIT_NO_EMPTY );

			if ( ! empty( $blocks ) ) {
				$paragraphs[] = count( $post_sentences ) / count( $blocks );
			}

			$dashes    += preg_match_all( '/\x{2014}|\s\x{2013}\s/u', $text );
			$shortened += preg_match_all( '/\b\w+[\x{2019}\']\w{1,2}\b/u', $text );
			$first     += preg_match_all( '/\b(?:i|we|our|my)\b/iu', $text );
			$second    += preg_match_all( '/\byou(?:r|rs)?\b/iu', $text );

			$out['titles'][] = wp_strip_all_tags( get_the_title( $post ) );

			$excerpt = trim( (string) $post->post_excerpt );

			if ( '' === $excerpt ) {
				$excerpt = wp_trim_words( $text, 30, '' );
			}

			$out['excerpts'][] = $excerpt;

			foreach ( (array) get_the_category( $post->ID ) as $term ) {
				if ( isset( $term->name ) && 'Uncategorized' !== $term->name ) {
					$subjects[ $term->name ] = isset( $subjects[ $term->name ] ) ? $subjects[ $term->name ] + 1 : 1;
				}
			}
		}

		if ( 0 === $out['posts'] ) {
			return $out;
		}

		$out['words']          = (int) round( array_sum( $words ) / count( $words ) );
		$out['sentence_words'] = empty( $sentences ) ? 0 : (int) round( array_sum( $sentences ) / count( $sentences ) );
		$out['para_sentences'] = empty( $paragraphs ) ? 0 : (int) round( array_sum( $paragraphs ) / count( $paragraphs ) );
		$out['em_dash']        = (int) $dashes;
		$out['contractions']   = (int) $shortened;

		if ( $first > ( $second * 1.5 ) ) {
			$out['person'] = 'first';
		} elseif ( $second > ( $first * 1.5 ) ) {
			$out['person'] = 'second';
		} else {
			$out['person'] = 'mixed';
		}

		arsort( $subjects );
		$out['subjects'] = array_slice( array_keys( $subjects ), 0, 6 );

		return $out;
	}

	/**
	 * Style rules derived from what the posts actually do.
	 *
	 * Only rules the measurements support. A rule invented to pad the list
	 * would be indistinguishable from one that was observed, and the whole
	 * value of this is that the reader can trust it came from their own work.
	 *
	 * @param array $seen Output of observe().
	 * @return array One rule per entry.
	 */
	public static function style_rules( $seen ) {
		$rules = array();

		if ( empty( $seen['posts'] ) ) {
			return $rules;
		}

		if ( $seen['sentence_words'] > 0 && $seen['sentence_words'] <= 16 ) {
			$rules[] = __( 'Keep sentences short. Most of ours run under sixteen words.', 'dicecodes-ai-blog-writer' );
		} elseif ( $seen['sentence_words'] >= 24 ) {
			$rules[] = __( 'Long sentences are fine here. Do not chop everything into fragments.', 'dicecodes-ai-blog-writer' );
		}

		if ( $seen['para_sentences'] > 0 && $seen['para_sentences'] <= 3 ) {
			$rules[] = __( 'Short paragraphs, two or three sentences each.', 'dicecodes-ai-blog-writer' );
		}

		// Under one per post across the sample is a writer who avoids them.
		if ( $seen['em_dash'] < $seen['posts'] ) {
			$rules[] = __( 'No em dashes.', 'dicecodes-ai-blog-writer' );
		}

		if ( $seen['contractions'] >= ( $seen['posts'] * 5 ) ) {
			$rules[] = __( 'Use contractions. Write the way people speak.', 'dicecodes-ai-blog-writer' );
		} elseif ( 0 === $seen['contractions'] ) {
			$rules[] = __( 'No contractions.', 'dicecodes-ai-blog-writer' );
		}

		if ( 'first' === $seen['person'] ) {
			$rules[] = __( 'Write in the first person. Say what we did and what we found.', 'dicecodes-ai-blog-writer' );
		} elseif ( 'second' === $seen['person'] ) {
			$rules[] = __( 'Address the reader directly as "you".', 'dicecodes-ai-blog-writer' );
		}

		return $rules;
	}

	/**
	 * Ask the model to describe the blog from its own titles and excerpts.
	 *
	 * Only the two fields that cannot be measured. Everything else in the
	 * result is observed, and mixing a guess in with measurements would make
	 * the whole set untrustworthy.
	 *
	 * @param array $seen Output of observe().
	 * @return array Keys: niche, audience. Empty strings when unavailable.
	 */
	public static function describe( $seen ) {
		$blank = array(
			'niche'    => '',
			'audience' => '',
		);

		if ( empty( $seen['titles'] ) || ! Blogcraft_Provider_Registry::is_configured() ) {
			return $blank;
		}

		$lines = '';
		$total = min( 20, count( $seen['titles'] ) );

		for ( $i = 0; $i < $total; $i++ ) {
			$lines .= '- ' . $seen['titles'][ $i ];

			if ( ! empty( $seen['excerpts'][ $i ] ) ) {
				$lines .= ': ' . $seen['excerpts'][ $i ];
			}

			$lines .= "\n";
		}

		$user = "Here are recent posts from a blog.\n\n" . $lines . "\n"
			. ( empty( $seen['subjects'] ) ? '' : 'Its categories are: ' . implode( ', ', $seen['subjects'] ) . "\n\n" )
			. "Reply with JSON of exactly this shape:\n"
			. '{"niche":"","audience":""}' . "\n\n"
			. "Rules:\n"
			. "- niche: one or two sentences on what this blog covers and the angle it takes.\n"
			. "- audience: one or two sentences on who reads it and what they already know.\n"
			. "- Describe only what these posts show. Do not flatter the blog and do not guess at anything they do not evidence.\n"
			. '- Write it as the blog owner would write it about themselves.';

		try {
			$result = Blogcraft_Pipeline::ask_model(
				array(
					array(
						'role'    => 'system',
						'content' => 'You summarise what a blog is about from its posts. You always reply with valid JSON and nothing else.',
					),
					array(
						'role'    => 'user',
						'content' => $user,
					),
				)
			);
		} catch ( Throwable $e ) {
			// Losing the two written fields still leaves every measured one, so
			// this stays a partial answer rather than a failure.
			return $blank;
		}

		return array(
			'niche'    => isset( $result['niche'] ) ? sanitize_textarea_field( (string) $result['niche'] ) : '',
			'audience' => isset( $result['audience'] ) ? sanitize_textarea_field( (string) $result['audience'] ) : '',
		);
	}

	/**
	 * Everything worth filling in, ready for the form.
	 *
	 * @return array Keys: found, fields, notes.
	 */
	public static function suggest() {
		$seen = self::observe();

		if ( empty( $seen['posts'] ) ) {
			return array(
				'found'  => 0,
				'fields' => array(),
				'notes'  => array( __( 'There are no posts here yet that Dicecodes AI Blog Writer did not write, so there is nothing to learn from.', 'dicecodes-ai-blog-writer' ) ),
			);
		}

		$written = self::describe( $seen );
		$rules   = self::style_rules( $seen );

		$fields = array();

		// Blueprint field names: the voice lives there now, and these
		// are put straight into that form by name.
		if ( '' !== $written['niche'] ) {
			$fields['niche'] = $written['niche'];
		}

		if ( '' !== $written['audience'] ) {
			$fields['audience']        = 'custom';
			$fields['audience_custom'] = $written['audience'];
		}

		if ( ! empty( $rules ) ) {
			$fields['style_rules'] = implode( "\n", $rules );
		}

		// A choice here, not a sentence. The old screen took free text
		// and this one has four buttons.
		if ( 'first' === $seen['person'] ) {
			$fields['point_of_view'] = 'first_plural';
		} elseif ( 'second' === $seen['person'] ) {
			$fields['point_of_view'] = 'second';
		}

		$notes = array(
			sprintf(
				/* translators: 1: number of posts read. 2: average word count. */
				__( 'Read %1$d of your posts. They average %2$d words.', 'dicecodes-ai-blog-writer' ),
				(int) $seen['posts'],
				(int) $seen['words']
			),
		);

		if ( '' === $written['niche'] ) {
			$notes[] = __( 'The written descriptions need a working AI provider, so only the measured style rules were filled in.', 'dicecodes-ai-blog-writer' );
		}

		$notes[] = __( 'Nothing has been saved. Correct anything that is wrong, then save.', 'dicecodes-ai-blog-writer' );

		return array(
			'found'  => (int) $seen['posts'],
			'fields' => $fields,
			'notes'  => $notes,
		);
	}
}
