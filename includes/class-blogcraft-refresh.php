<?php
/**
 * Rewriting existing posts in place.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Finds posts that have gone stale and rewrites them without moving them.
 *
 * Refreshing an existing post is usually worth more than publishing a new one:
 * the URL already has whatever history it has earned, so improving it compounds
 * rather than starting over. Almost no tool in this category automates it.
 *
 * The post ID, slug and permalink are never changed. A refresh that moved the
 * URL would discard the very thing that made refreshing worthwhile.
 */
class Blogcraft_Refresh {

	/**
	 * Pipeline name.
	 */
	const NAME = 'refresh_post';

	/**
	 * Meta recording when a post was last refreshed.
	 */
	const REFRESHED_META = '_blogcraft_refreshed';

	/**
	 * Register the pipeline's stages.
	 *
	 * @return void
	 */
	public static function register() {
		Blogcraft_Worker::register_stage( self::NAME, 'rewrite', array( __CLASS__, 'stage_rewrite' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'save', array( __CLASS__, 'stage_save' ) );
	}

	/**
	 * Generated posts old enough to be worth revisiting.
	 *
	 * @param int $days How old, in days.
	 * @param int $limit Maximum returned.
	 * @return array WP_Post objects.
	 */
	public static function find_stale( $days = null, $limit = 20 ) {
		if ( null === $days ) {
			$days = (int) Blogcraft_Settings::get( 'refresh_after_days' );
		}

		$days = max( 1, (int) $days );

		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => (int) $limit,
				'orderby'        => 'modified',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'meta_key'       => '_blogcraft_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'date_query'     => array(
					array(
						'column' => 'post_modified_gmt',
						'before' => gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ),
					),
				),
			)
		);
	}

	/**
	 * Queue one post for rewriting.
	 *
	 * @param int $post_id Post to refresh.
	 * @return int Job id, or 0 when the post cannot be refreshed.
	 */
	public static function enqueue_post( $post_id ) {
		$post = get_post( (int) $post_id );

		// Only ever rewrite a post this plugin generated. Rewriting something a
		// human wrote would be a destructive surprise.
		if ( ! $post || ! get_post_meta( $post->ID, '_blogcraft_generated', true ) ) {
			return 0;
		}

		return Blogcraft_Queue::enqueue(
			self::NAME,
			'rewrite',
			array(
				'post_id' => (int) $post->ID,
				'topic'   => (string) get_post_meta( $post->ID, '_blogcraft_topic', true ),
			)
		);
	}

	/**
	 * Ask the model to improve the existing post.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 * @throws RuntimeException When the post has gone.
	 */
	public static function stage_rewrite( $job ) {
		$payload = $job->payload;
		$post    = get_post( isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0 );

		if ( ! $post ) {
			throw new RuntimeException( 'The post to refresh no longer exists.' );
		}

		$topic = ! empty( $payload['topic'] ) ? (string) $payload['topic'] : get_the_title( $post );

		$sources   = Blogcraft_Research::gather( $topic );
		$reference = Blogcraft_Research::to_prompt_block( $sources );

		$existing = wp_strip_all_tags( (string) $post->post_content );

		if ( strlen( $existing ) > 6000 ) {
			$existing = substr( $existing, 0, 6000 );
		}

		$user = ( '' === $reference ? '' : $reference . "\n\n" )
			. "This post already exists and needs improving, not replacing.\n\n"
			. 'Title: ' . get_the_title( $post ) . "\n\n"
			. "Current text:\n" . $existing . "\n\n"
			. "Reply with JSON of exactly this shape:\n"
			. '{"intro":"","key_takeaways":[""],"sections":[{"heading":"","paragraphs":[""]}],"faq":[{"question":"","answer":""}]}'
			. "\n\nRules:\n"
			. "- Keep what still works. Rewrite what is vague, dated or thin.\n"
			. "- Add anything the reference material shows is now missing.\n"
			. "- Do not change what the post is fundamentally about.\n"
			. '- Plain text only in every field. No markdown, no HTML.';

		$messages = array(
			array(
				'role'    => 'system',
				'content' => 'You are an experienced editor improving an existing article. '
					. 'You always reply with valid JSON and nothing else.'
					. Blogcraft_Voice::system_prompt(),
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);

		$article = Blogcraft_Pipeline::ask_model( $messages, array( 'max_tokens' => 4096 ) );

		$payload['article'] = $article;

		return array(
			'next'    => 'save',
			'payload' => $payload,
		);
	}

	/**
	 * Write the rewrite back over the original post.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 * @throws RuntimeException When the rewrite is unusable.
	 */
	public static function stage_save( $job ) {
		$payload = $job->payload;
		$post_id = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
		$article = isset( $payload['article'] ) ? $payload['article'] : array();

		$content = Blogcraft_Seo::render_toc( $article, ! empty( Blogcraft_Blueprint::get()['toc'] ) ) . Blogcraft_Blocks::render( $article );

		// Refusing to save an empty rewrite matters more here than anywhere else:
		// this overwrites a post that already existed and was working.
		if ( '' === trim( $content ) ) {
			throw new RuntimeException( 'The rewrite came back empty; the original was left alone.' );
		}

		$assessment = Blogcraft_Verify::score( $article );

		if ( $assessment['score'] < (int) Blogcraft_Settings::get( 'quality_threshold' ) ) {
			Blogcraft_Logger::error(
				'Refresh scored below the threshold; the original was left alone.',
				array( 'score' => $assessment['score'] ),
				(int) $job->id
			);

			return array(
				'next'    => null,
				'payload' => $payload,
			);
		}

		if ( Blogcraft_Settings::get( 'internal_links_enabled' ) ) {
			$content .= Blogcraft_Seo::render_related_block(
				Blogcraft_Seo::related_posts( isset( $payload['topic'] ) ? (string) $payload['topic'] : '', 4, $post_id )
			);
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			throw new RuntimeException( esc_html( $result->get_error_message() ) );
		}

		update_post_meta( $post_id, self::REFRESHED_META, time() );
		update_post_meta( $post_id, '_blogcraft_quality', (int) $assessment['score'] );

		Blogcraft_Logger::info(
			'Refreshed an existing post.',
			array(
				'post_id' => $post_id,
				'score'   => $assessment['score'],
			),
			(int) $job->id
		);

		return array(
			'next'    => null,
			'payload' => $payload,
		);
	}
}
