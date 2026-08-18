<?php
/**
 * The post-writing pipeline.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers the write_post pipeline's stages with the worker.
 *
 * Each stage makes exactly one provider call and hands its result to the next
 * through the job payload. Because the worker runs one stage per claim, a
 * generation that takes minutes never needs a single request to outlive PHP's
 * execution limit.
 */
class Blogcraft_Pipeline {

	/**
	 * Pipeline name, as stored on the job row.
	 */
	const NAME = 'write_post';

	/**
	 * Register every stage handler.
	 *
	 * @return void
	 */
	public static function register() {
		Blogcraft_Worker::register_stage( self::NAME, 'research', array( __CLASS__, 'stage_research' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'outline', array( __CLASS__, 'stage_outline' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'draft', array( __CLASS__, 'stage_draft' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'critique', array( __CLASS__, 'stage_critique' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'revise', array( __CLASS__, 'stage_revise' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'verify', array( __CLASS__, 'stage_verify' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'publish', array( __CLASS__, 'stage_publish' ) );
	}

	/**
	 * Queue a new post for generation.
	 *
	 * @param string $topic        Topic to write about.
	 * @param string $status       Post status to create: draft or publish.
	 * @param string $instructions Optional per-topic guidance.
	 * @param array  $overrides    Blueprint fields to change for this post only.
	 * @return int Job id, or 0 on failure.
	 */
	public static function enqueue_topic( $topic, $status = 'draft', $instructions = '', $overrides = array() ) {
		// Near-identical posts are what search engines treat as scaled content
		// abuse, so a repeat is refused before it costs any tokens.
		if ( Blogcraft_Settings::get( 'duplicate_check_enabled' ) ) {
			if ( '' !== Blogcraft_Backlinks::find_duplicate( $topic ) ) {
				return 0;
			}

			// Also against what is already waiting. Nothing has been published
			// yet at this point, so the check above cannot see a repeat that
			// arrived in the same pasted list.
			if ( '' !== Blogcraft_Backlinks::find_queued_duplicate( $topic ) ) {
				return 0;
			}
		}

		return Blogcraft_Queue::enqueue(
			self::NAME,
			'research',
			array(
				'topic'        => (string) $topic,
				'status'       => ( 'publish' === $status ) ? 'publish' : 'draft',
				'instructions' => (string) $instructions,
				// Snapshot rather than reference: editing a blueprint must not
				// change the rules a post already part-written is judged by.
				'blueprint'    => Blogcraft_Blueprint::with_overrides(
					Blogcraft_Blueprint::get(),
					is_array( $overrides ) ? $overrides : array()
				),
			)
		);
	}

	/**
	 * The blueprint a job is being written to.
	 *
	 * Falls back to the current default for jobs queued before blueprints
	 * existed, so an upgrade does not strand whatever is mid-flight.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	private static function blueprint( $job ) {
		$stored = isset( $job->payload['blueprint'] ) ? $job->payload['blueprint'] : null;

		return is_array( $stored )
			? Blogcraft_Blueprint::normalise( $stored )
			: Blogcraft_Blueprint::get();
	}

	/**
	 * Per-topic guidance carried on the job, if any.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return string
	 */
	private static function instructions( $job ) {
		return isset( $job->payload['instructions'] ) ? (string) $job->payload['instructions'] : '';
	}

	/**
	 * Run one provider call from another pipeline.
	 *
	 * The refresh pipeline needs the same cap check, cost accounting and JSON
	 * recovery as generation, and duplicating them would let the two drift.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Provider options.
	 * @return array Parsed payload.
	 */
	public static function ask_model( $messages, $options = array() ) {
		return self::ask( $messages, $options );
	}

	/**
	 * Run one provider call and return the parsed JSON.
	 *
	 * @param array $messages Chat messages.
	 * @param array $options  Provider options.
	 * @return array Parsed payload.
	 * @throws RuntimeException When no provider is configured, the cap is hit, or the call fails.
	 */
	private static function ask( $messages, $options = array() ) {
		if ( Blogcraft_Cost::over_cap() ) {
			throw new RuntimeException( 'Monthly token cap reached; generation paused.' );
		}

		// from_settings() returns an object as soon as a type is chosen, and the
		// type defaults to openai, so this alone never catches an empty setup.
		if ( ! Blogcraft_Provider_Registry::is_configured() ) {
			throw new RuntimeException( 'No AI provider is set up yet. Add a model and an API key under Blogcraft, Settings.' );
		}

		$provider = Blogcraft_Provider_Registry::from_settings();

		if ( null === $provider ) {
			throw new RuntimeException( 'No AI provider is configured.' );
		}

		$options  = array_merge( array( 'json_mode' => true ), $options );
		$response = $provider->complete( $messages, $options );

		if ( $response->is_error() ) {
			throw new RuntimeException( esc_html( $response->error ) );
		}

		Blogcraft_Cost::record(
			$provider->id(),
			$response->model,
			$response->prompt_tokens,
			$response->completion_tokens
		);

		$parsed = Blogcraft_Prompts::extract_json( $response->text );

		if ( empty( $parsed ) ) {
			throw new RuntimeException( 'The model did not return usable JSON.' );
		}

		return $parsed;
	}

	/**
	 * Gather source material before anything is written.
	 *
	 * This stage never fails the job. With no search provider configured it
	 * falls back to the site's own coverage, and with nothing at all it passes
	 * an empty set through so the post is still written, just unaided.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_research( $job ) {
		$topic   = isset( $job->payload['topic'] ) ? (string) $job->payload['topic'] : '';
		$sources = array();

		try {
			$sources = Blogcraft_Research::gather( $topic );
		} catch ( Throwable $e ) {
			Blogcraft_Logger::error(
				'Research failed; writing without sources.',
				array( 'reason' => $e->getMessage() ),
				(int) $job->id
			);
		}

		$payload            = $job->payload;
		$payload['sources'] = $sources;

		return array(
			'next'    => 'outline',
			'payload' => $payload,
		);
	}

	/**
	 * Plan the post.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_outline( $job ) {
		$topic   = isset( $job->payload['topic'] ) ? $job->payload['topic'] : '';
		$sources = isset( $job->payload['sources'] ) ? (array) $job->payload['sources'] : array();
		Blogcraft_Prompts::use_blueprint( self::blueprint( $job ) );

		$outline = self::ask( Blogcraft_Prompts::outline( $topic, $sources, self::instructions( $job ) ) );

		$payload            = $job->payload;
		$payload['outline'] = $outline;

		return array(
			'next'    => 'draft',
			'payload' => $payload,
		);
	}

	/**
	 * Write the first draft.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_draft( $job ) {
		$topic   = isset( $job->payload['topic'] ) ? $job->payload['topic'] : '';
		$outline = isset( $job->payload['outline'] ) ? $job->payload['outline'] : array();
		$sources = isset( $job->payload['sources'] ) ? (array) $job->payload['sources'] : array();
		Blogcraft_Prompts::use_blueprint( self::blueprint( $job ) );

		$article = self::ask( Blogcraft_Prompts::draft( $topic, $outline, $sources, self::instructions( $job ) ), array( 'max_tokens' => 4096 ) );

		$payload            = $job->payload;
		$payload['article'] = $article;

		return array(
			'next'    => 'critique',
			'payload' => $payload,
		);
	}

	/**
	 * Critique the draft.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_critique( $job ) {
		$article   = isset( $job->payload['article'] ) ? $job->payload['article'] : array();
		$blueprint = self::blueprint( $job );

		Blogcraft_Prompts::use_blueprint( $blueprint );

		$result   = self::ask( Blogcraft_Prompts::critique( $article ) );
		$problems = isset( $result['problems'] ) && is_array( $result['problems'] ) ? $result['problems'] : array();

		// The model's own opinion of its draft is worth having, but it is an
		// opinion. Measuring the rendered article against the blueprint gives
		// facts, and a fact the model can act on beats a score it never sees.
		$assessment = Blogcraft_Scorecard::evaluate(
			Blogcraft_Blocks::render( $article ),
			$blueprint
		);

		foreach ( $assessment['checks'] as $check ) {
			if ( empty( $check['pass'] ) && '' !== trim( (string) $check['repair'] ) ) {
				$problems[] = $check['repair'];
			}
		}

		$payload                = $job->payload;
		$payload['problems']    = $problems;
		$payload['draft_score'] = (int) $assessment['score'];

		Blogcraft_Logger::info(
			'Draft measured against the blueprint.',
			array(
				'score'  => (int) $assessment['score'],
				'to_fix' => count( $problems ),
			),
			(int) $job->id
		);

		// Nothing to fix means the revise pass would only burn tokens.
		return array(
			'next'    => empty( $problems ) ? 'verify' : 'revise',
			'payload' => $payload,
		);
	}

	/**
	 * Apply the critique.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_revise( $job ) {
		$article  = isset( $job->payload['article'] ) ? $job->payload['article'] : array();
		$problems = isset( $job->payload['problems'] ) ? $job->payload['problems'] : array();
		Blogcraft_Prompts::use_blueprint( self::blueprint( $job ) );

		$revised = self::ask( Blogcraft_Prompts::revise( $article, $problems ), array( 'max_tokens' => 4096 ) );

		$payload            = $job->payload;
		$payload['article'] = $revised;

		return array(
			'next'    => 'verify',
			'payload' => $payload,
		);
	}

	/**
	 * Decide the status a finished post should take.
	 *
	 * @param array $payload Job payload.
	 * @return string
	 */
	private static function resolve_status( $payload ) {
		// A draft that failed the quality bar is held for review even when the
		// user asked for immediate publication.
		if ( ! empty( $payload['needs_review'] ) ) {
			return 'pending';
		}

		return ( isset( $payload['status'] ) && 'publish' === $payload['status'] ) ? 'publish' : 'draft';
	}

	/**
	 * Check the draft before it becomes a post.
	 *
	 * A draft that scores below the threshold is still published as a post, but
	 * forced to pending review regardless of what the user asked for. Silently
	 * discarding the work would waste the tokens already spent; publishing it
	 * unreviewed is what search engines penalise.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_verify( $job ) {
		$payload = $job->payload;
		$article = isset( $payload['article'] ) ? $payload['article'] : array();

		$links = Blogcraft_Verify::check_links( $article );

		if ( ! empty( $links['dead'] ) ) {
			$article            = Blogcraft_Verify::strip_dead_links( $article, $links['dead'] );
			$payload['article'] = $article;

			Blogcraft_Logger::info(
				'Removed links that did not resolve.',
				array( 'count' => count( $links['dead'] ) ),
				(int) $job->id
			);
		}

		// Score against the blueprint rather than the old generic heuristic, so
		// the number that decides publish-or-hold is the same one measured
		// during critique, against the rules this post was actually written to.
		$blueprint  = self::blueprint( $job );
		$scorecard  = Blogcraft_Scorecard::evaluate( Blogcraft_Blocks::render( $article ), $blueprint );
		$assessment = Blogcraft_Verify::score( $article );

		$reasons = array();

		foreach ( $scorecard['checks'] as $check ) {
			if ( empty( $check['pass'] ) ) {
				$reasons[] = sprintf(
					/* translators: 1: check name. 2: measured value. 3: the value asked for. */
					__( '%1$s: %2$s, wanted %3$s', 'blogcraft' ),
					$check['label'],
					$check['actual'],
					$check['target']
				);
			}
		}

		// Keep whatever the older link and structure checks flagged; they cover
		// things the scorecard does not measure.
		if ( ! empty( $assessment['reasons'] ) ) {
			$reasons = array_merge( $reasons, (array) $assessment['reasons'] );
		}

		$payload['quality'] = array(
			'score'   => (int) $scorecard['score'],
			'reasons' => $reasons,
		);

		$payload['checks']       = $scorecard['checks'];
		$payload['metrics']      = $scorecard['metrics'];
		$payload['needs_review'] = $scorecard['score'] < (int) Blogcraft_Settings::get( 'quality_threshold' );

		return array(
			'next'    => 'publish',
			'payload' => $payload,
		);
	}

	/**
	 * Create the WordPress post.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 * @throws RuntimeException When the post cannot be created.
	 */
	public static function stage_publish( $job ) {
		$payload = $job->payload;
		$article = isset( $payload['article'] ) ? $payload['article'] : array();
		$outline = isset( $payload['outline'] ) ? $payload['outline'] : array();

		$title = '';

		if ( ! empty( $outline['title'] ) ) {
			$title = sanitize_text_field( (string) $outline['title'] );
		}

		if ( '' === $title ) {
			$title = sanitize_text_field( isset( $payload['topic'] ) ? (string) $payload['topic'] : __( 'Untitled', 'blogcraft' ) );
		}

		$content = Blogcraft_Seo::render_toc( $article ) . Blogcraft_Blocks::render( $article );

		if ( '' === $content ) {
			throw new RuntimeException( 'The generated article was empty.' );
		}

		// Link targets come from the site's real posts, never from the model, which
		// would confidently invent URLs that 404.
		if ( Blogcraft_Settings::get( 'internal_links_enabled' ) ) {
			$topic_for_links = isset( $payload['topic'] ) ? (string) $payload['topic'] : $title;
			$content        .= Blogcraft_Seo::render_related_block(
				Blogcraft_Seo::related_posts( $topic_for_links, 4 )
			);
		}

		$postarr = array(
			'post_title'   => $title,
			'post_content' => $content,
			'post_status'  => self::resolve_status( $payload ),
			'post_type'    => 'post',
		);

		if ( ! empty( $outline['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( (string) $outline['slug'] );
		}

		if ( ! empty( $outline['meta_description'] ) ) {
			$postarr['post_excerpt'] = sanitize_text_field( (string) $outline['meta_description'] );
		}

		$post_id = wp_insert_post( $postarr, true );

		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( esc_html( $post_id->get_error_message() ) );
		}

		update_post_meta( $post_id, '_blogcraft_generated', 1 );

		$faq_schema = Blogcraft_Seo::build_faq_schema( $article );

		if ( ! empty( $faq_schema ) ) {
			update_post_meta( (int) $post_id, '_blogcraft_faq_schema', $faq_schema );
		}

		Blogcraft_Seo::write_seo_meta(
			(int) $post_id,
			$title,
			isset( $outline['meta_description'] ) ? (string) $outline['meta_description'] : ''
		);

		if ( isset( $payload['quality'] ) ) {
			update_post_meta( $post_id, '_blogcraft_quality', (int) $payload['quality']['score'] );
			update_post_meta( $post_id, '_blogcraft_quality_reasons', (array) $payload['quality']['reasons'] );

			if ( ! empty( $payload['checks'] ) ) {
				update_post_meta( $post_id, '_blogcraft_checks', (array) $payload['checks'] );
			}

			if ( ! empty( $payload['metrics'] ) ) {
				update_post_meta( $post_id, '_blogcraft_metrics', (array) $payload['metrics'] );
			}
		}

		// A missing image must never fail a finished post, so this is best-effort.
		Blogcraft_Images::add_section_images( (int) $post_id, $article, 3 );

		Blogcraft_Images::attach_featured(
			(int) $post_id,
			$title,
			isset( $payload['topic'] ) ? (string) $payload['topic'] : ''
		);
		update_post_meta( $post_id, '_blogcraft_topic', isset( $payload['topic'] ) ? (string) $payload['topic'] : '' );

		// Point older related posts at this one. Every competing tool links only
		// forward, leaving existing content unaware the new post exists.
		Blogcraft_Backlinks::link_back(
			(int) $post_id,
			isset( $payload['topic'] ) ? (string) $payload['topic'] : $title,
			3
		);

		Blogcraft_Logger::info(
			'Generated post created.',
			array( 'post_id' => (int) $post_id ),
			(int) $job->id
		);

		$payload['post_id'] = (int) $post_id;

		return array(
			'next'    => null,
			'payload' => $payload,
		);
	}
}
