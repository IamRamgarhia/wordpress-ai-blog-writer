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
		Blogcraft_Worker::register_stage( self::NAME, 'section', array( __CLASS__, 'stage_section' ) );
		Blogcraft_Worker::register_stage( self::NAME, 'faq', array( __CLASS__, 'stage_faq' ) );
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
	 * @param string $evidence     The writer's own figures and findings, used as fact.
	 * @param array  $placement    Where the finished post lands: category, tags, author, publish_at.
	 * @return int Job id, or 0 on failure.
	 */
	public static function enqueue_topic( $topic, $status = 'draft', $instructions = '', $overrides = array(), $evidence = '', $placement = array() ) {
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
				// The writer's own material: figures, results, prices, what
				// happened when they tried it. The only part of a post a model
				// cannot produce, so it is carried separately from guidance and
				// treated as fact rather than suggestion.
				'evidence'     => (string) $evidence,
				// Where the finished post lands. Carried on the job rather than
				// applied at queue time because the post does not exist yet.
				'placement'    => is_array( $placement ) ? $placement : array(),
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
	 * What the editorial checks need beyond the prose.
	 *
	 * The title and meta description live on the outline and the sources on the
	 * payload, so the scorecard cannot reach either from rendered content
	 * alone. Anything absent is simply absent: those checks then do not run.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	private static function context( $job ) {
		$outline = isset( $job->payload['outline'] ) && is_array( $job->payload['outline'] )
			? $job->payload['outline']
			: array();

		return array(
			'title'            => isset( $outline['title'] ) ? (string) $outline['title'] : '',
			'meta_description' => isset( $outline['meta_description'] ) ? (string) $outline['meta_description'] : '',
			'sources'          => isset( $job->payload['sources'] ) ? (array) $job->payload['sources'] : array(),
			'evidence'         => self::evidence( $job ),
		);
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
	 * The writer's own material for this post, if any.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return string
	 */
	private static function evidence( $job ) {
		return isset( $job->payload['evidence'] ) ? (string) $job->payload['evidence'] : '';
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

		// Terms the pages already covering this subject all mention. Derived
		// from research this stage has already fetched, so it costs nothing
		// extra, and it only fills in when the user has not named terms of
		// their own — an explicit list is a decision, not a gap to fill.
		$blueprint = self::blueprint( $job );

		if ( (bool) $blueprint['auto_terms'] && '' === trim( (string) $blueprint['required_terms'] ) ) {
			$terms = Blogcraft_Terms::extract( $sources, $topic );

			if ( ! empty( $terms ) ) {
				$blueprint['required_terms'] = implode(
					'
',
					$terms
				);
				$payload['blueprint']        = $blueprint;
				$payload['auto_terms_found'] = $terms;

				Blogcraft_Logger::info(
					'Derived the terms this subject is expected to cover.',
					array( 'terms' => implode( ', ', $terms ) ),
					(int) $job->id
				);
			}
		}

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
		Blogcraft_Prompts::use_evidence( self::evidence( $job ) );

		$outline = self::ask( Blogcraft_Prompts::outline( $topic, $sources, self::instructions( $job ) ) );

		$payload            = $job->payload;
		$payload['outline'] = $outline;

		return array(
			'next'    => 'draft',
			'payload' => $payload,
		);
	}

	/**
	 * Options for the calls that write prose.
	 *
	 * Deliberately empty. Capping output tokens looks like a sensible economy
	 * and is the opposite on a reasoning model: the thinking budget is drawn
	 * from the same allowance, so a low cap is spent before any answer is
	 * emitted and the reply comes back empty or cut off. Measured on Gemini
	 * 3.6 Flash, where the outline call carried no cap and parsed every time
	 * while every capped call failed to return usable JSON.
	 *
	 * Length is controlled by the blueprint and enforced by the scorer, which
	 * is a better lever anyway: it counts words rather than tokens, and it can
	 * ask for a rewrite instead of truncating mid-sentence.
	 *
	 * @return array
	 */
	private static function draft_options() {
		return array();
	}

	/**
	 * The headings the outline settled on.
	 *
	 * @param array $payload Job payload.
	 * @return array
	 */
	private static function headings( $payload ) {
		$outline = isset( $payload['outline'] ) ? $payload['outline'] : array();
		$out     = array();

		if ( ! empty( $outline['sections'] ) && is_array( $outline['sections'] ) ) {
			foreach ( $outline['sections'] as $section ) {
				if ( is_array( $section ) && ! empty( $section['heading'] ) ) {
					$out[] = (string) $section['heading'];
				}
			}
		}

		return $out;
	}

	/**
	 * How many words each section should carry.
	 *
	 * The furniture is subtracted first so the sections divide what is actually
	 * left, rather than the target pretending the takeaways and questions cost
	 * nothing.
	 *
	 * @param array $blueprint Blueprint.
	 * @param int   $sections  Number of sections.
	 * @return int
	 */
	private static function section_budget( $blueprint, $sections ) {
		$total = (int) $blueprint['word_target'];
		$intro = (int) round( $total * 0.08 );
		$extra = 0;

		if ( (bool) $blueprint['takeaways'] ) {
			$extra += (int) $blueprint['takeaways_count'] * 18;
		}

		if ( (bool) $blueprint['faq'] ) {
			$extra += (int) $blueprint['faq_count'] * 45;
		}

		$body = max( 120, $total - $intro - $extra );

		return ( $sections > 0 ) ? max( 90, (int) round( $body / $sections ) ) : $body;
	}

	/**
	 * Write the opening.
	 *
	 * The article used to be written in a single JSON turn, which is what makes
	 * long posts fail: the response hits the token ceiling part way through, the
	 * JSON will not parse, and the job dies having spent everything it was going
	 * to spend. Each stage below asks for one small piece instead, and a small
	 * piece cannot truncate.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_draft( $job ) {
		$payload   = $job->payload;
		$blueprint = self::blueprint( $job );

		Blogcraft_Prompts::use_blueprint( $blueprint );
		Blogcraft_Prompts::use_evidence( self::evidence( $job ) );

		$topic   = isset( $payload['topic'] ) ? $payload['topic'] : '';
		$outline = isset( $payload['outline'] ) ? $payload['outline'] : array();
		$sources = isset( $payload['sources'] ) ? (array) $payload['sources'] : array();

		$opening = self::ask(
			Blogcraft_Prompts::intro(
				$topic,
				$outline,
				$sources,
				self::instructions( $job ),
				(int) round( (int) $blueprint['word_target'] * 0.08 ),
				(bool) $blueprint['takeaways'] ? (int) $blueprint['takeaways_count'] : 0
			),
			self::draft_options()
		);

		$payload['article'] = array(
			'intro'         => isset( $opening['intro'] ) ? (string) $opening['intro'] : '',
			'key_takeaways' => isset( $opening['key_takeaways'] ) ? (array) $opening['key_takeaways'] : array(),
			'sections'      => array(),
			'faq'           => array(),
		);

		$payload['section_index'] = 0;

		// A model that returned no usable headings would otherwise loop forever
		// on a section stage with nothing to write.
		$next = empty( self::headings( $payload ) ) ? 'faq' : 'section';

		return array(
			'next'    => $next,
			'payload' => $payload,
		);
	}

	/**
	 * Write one section, then come back for the next.
	 *
	 * Returning its own name as the next stage keeps one provider call per tick,
	 * which is the same guarantee the rest of the pipeline relies on: no single
	 * request has to outlive PHP's execution limit however long the article is.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	public static function stage_section( $job ) {
		$payload   = $job->payload;
		$blueprint = self::blueprint( $job );

		Blogcraft_Prompts::use_blueprint( $blueprint );
		Blogcraft_Prompts::use_evidence( self::evidence( $job ) );

		$headings = self::headings( $payload );
		$index    = isset( $payload['section_index'] ) ? (int) $payload['section_index'] : 0;

		if ( ! isset( $headings[ $index ] ) ) {
			$payload['section_index'] = count( $headings );

			return array(
				'next'    => 'faq',
				'payload' => $payload,
			);
		}

		$heading = $headings[ $index ];

		$result = self::ask(
			Blogcraft_Prompts::section(
				isset( $payload['topic'] ) ? $payload['topic'] : '',
				$heading,
				array_slice( $headings, 0, $index ),
				array_slice( $headings, $index + 1 ),
				isset( $payload['sources'] ) ? (array) $payload['sources'] : array(),
				self::instructions( $job ),
				self::section_budget( $blueprint, count( $headings ) )
			),
			self::draft_options()
		);

		$paragraphs = isset( $result['paragraphs'] ) ? (array) $result['paragraphs'] : array();

		$payload['article']['sections'][] = array(
			'heading'    => $heading,
			'paragraphs' => $paragraphs,
		);

		$payload['section_index'] = $index + 1;

		return array(
			'next'    => ( $payload['section_index'] < count( $headings ) ) ? 'section' : 'faq',
			'payload' => $payload,
		);
	}

	/**
	 * Write the questions and answers, when the blueprint wants them.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 * @throws RuntimeException When the provider is rate limiting, so the worker can wait.
	 */
	public static function stage_faq( $job ) {
		$payload   = $job->payload;
		$blueprint = self::blueprint( $job );

		if ( ! (bool) $blueprint['faq'] ) {
			return array(
				'next'    => 'critique',
				'payload' => $payload,
			);
		}

		Blogcraft_Prompts::use_blueprint( $blueprint );
		Blogcraft_Prompts::use_evidence( self::evidence( $job ) );

		// The body is already written by this point. Questions are furniture,
		// so a failure here publishes the article without them rather than
		// throwing away everything the job has spent.
		try {
			$result = self::ask(
				Blogcraft_Prompts::faq(
					isset( $payload['topic'] ) ? $payload['topic'] : '',
					self::headings( $payload ),
					(int) $blueprint['faq_count']
				),
				self::draft_options()
			);

			$payload['article']['faq'] = isset( $result['faq'] ) ? (array) $result['faq'] : array();
		} catch ( RuntimeException $e ) {
			// A rate limit still has to reach the worker, which knows to wait
			// rather than fail. Only genuine content failures are shrugged off.
			if ( false !== strpos( $e->getMessage(), 'HTTP 429' )
				|| false !== stripos( $e->getMessage(), 'exceeded your current quota' ) ) {
				throw $e;
			}

			$payload['article']['faq'] = array();

			Blogcraft_Logger::error(
				'The questions could not be written; publishing the article without them.',
				array( 'reason' => $e->getMessage() ),
				(int) $job->id
			);
		}

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
		Blogcraft_Prompts::use_evidence( self::evidence( $job ) );

		$result   = self::ask( Blogcraft_Prompts::critique( $article ) );
		$problems = isset( $result['problems'] ) && is_array( $result['problems'] ) ? $result['problems'] : array();

		// The model's own opinion of its draft is worth having, but it is an
		// opinion. Measuring the rendered article against the blueprint gives
		// facts, and a fact the model can act on beats a score it never sees.
		$assessment = Blogcraft_Scorecard::evaluate(
			Blogcraft_Blocks::render( $article ),
			$blueprint,
			self::context( $job )
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
		$payload  = $job->payload;
		$outline  = isset( $payload['outline'] ) && is_array( $payload['outline'] ) ? $payload['outline'] : array();

		Blogcraft_Prompts::use_blueprint( self::blueprint( $job ) );
		Blogcraft_Prompts::use_evidence( self::evidence( $job ) );

		$revised = self::ask( Blogcraft_Prompts::revise( $article, $problems, $outline ), self::draft_options() );

		// The title and meta description are measured but belong to the
		// outline, so a correction for either comes back alongside the draft
		// and is moved to where it lives. Absent keys change nothing, which is
		// the common case: they are only asked for when something flagged them.
		foreach ( array( 'title', 'meta_description' ) as $field ) {
			if ( isset( $revised[ $field ] ) && '' !== trim( (string) $revised[ $field ] ) ) {
				$outline[ $field ] = sanitize_text_field( (string) $revised[ $field ] );
			}

			unset( $revised[ $field ] );
		}

		if ( ! empty( $outline ) ) {
			$payload['outline'] = $outline;
		}

		$payload['article'] = $revised;

		return array(
			'next'    => 'verify',
			'payload' => $payload,
		);
	}

	/**
	 * Apply the category, author, tags and publish time chosen for this post.
	 *
	 * A future publish date is honoured only for a post that was going to be
	 * published anyway: scheduling something the user asked to keep as a draft
	 * would publish it against their wishes, which is the one mistake here that
	 * cannot be taken back.
	 *
	 * @param array $postarr   Arguments for wp_insert_post().
	 * @param array $placement Choices made in the composer.
	 * @return array
	 */
	private static function apply_placement( $postarr, $placement ) {
		if ( empty( $placement ) ) {
			return $postarr;
		}

		$category = isset( $placement['category'] ) ? (int) $placement['category'] : 0;

		if ( $category > 0 && term_exists( $category, 'category' ) ) {
			$postarr['post_category'] = array( $category );
		}

		$author = isset( $placement['author'] ) ? (int) $placement['author'] : 0;

		if ( $author > 0 && get_userdata( $author ) ) {
			$postarr['post_author'] = $author;
		}

		$tags = isset( $placement['tags'] ) ? (string) $placement['tags'] : '';

		if ( '' !== trim( $tags ) ) {
			$postarr['tags_input'] = array_filter( array_map( 'trim', explode( ',', $tags ) ) );
		}

		$when = isset( $placement['publish_at'] ) ? (string) $placement['publish_at'] : '';

		if ( '' !== $when && 'publish' === $postarr['post_status'] ) {
			$stamp = strtotime( $when );

			if ( $stamp && $stamp > time() ) {
				$postarr['post_status']   = 'future';
				$postarr['post_date']     = gmdate( 'Y-m-d H:i:s', $stamp + ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
				$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $stamp );
			}
		}

		return $postarr;
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
		$scorecard  = Blogcraft_Scorecard::evaluate( Blogcraft_Blocks::render( $article ), $blueprint, self::context( $job ) );
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
			$related         = Blogcraft_Seo::related_posts( $topic_for_links, 4 );

			// A link inside a sentence is worth more than the same link in a
			// list at the bottom that nobody scrolls to. Whatever could not be
			// placed in the prose still gets listed, so nothing is lost.
			$woven   = Blogcraft_Seo::link_in_text( $content, $related, 3 );
			$content = $woven['content'];

			$leftover = array();

			foreach ( $related as $item ) {
				if ( ! in_array( (int) $item['id'], array_map( 'intval', $woven['linked'] ), true ) ) {
					$leftover[] = $item;
				}
			}

			$content .= Blogcraft_Seo::render_related_block( $leftover );
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

		$postarr = self::apply_placement( $postarr, isset( $payload['placement'] ) ? (array) $payload['placement'] : array() );

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
