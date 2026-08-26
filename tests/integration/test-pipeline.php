<?php
/**
 * Block rendering, prompt parsing, and end-to-end pipeline tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Pipeline extends WP_UnitTestCase {

	/**
	 * Queued fake HTTP responses.
	 *
	 * @var array
	 */
	private $queue = array();

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		Blogcraft_Worker::reset_stages();
		Blogcraft_Pipeline::register();
		Blogcraft_Cost::reset();

		global $wpdb;
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_base_url', 'https://api.test/v1' );
		Blogcraft_Settings::set( 'provider_api_key', 'test-key' );
		Blogcraft_Settings::set( 'provider_model', 'test-model' );
		Blogcraft_Settings::set( 'monthly_token_cap', 0 );

		// The keyless research sources are on by default and make real HTTP
		// calls. fake_completions() stubs every request with a canned
		// completion, so a Wikipedia lookup would eat the response the outline
		// stage was going to get and every fixture after it would be off by
		// one. These tests are about the pipeline's control flow; research has
		// its own.
		Blogcraft_Settings::set( 'research_wikipedia', false );
		Blogcraft_Settings::set( 'research_community', false );

		$this->use_permissive_blueprint();
	}

	/**
	 * Install a blueprint the short fixtures in this file can actually satisfy.
	 *
	 * The scorecard feeds measured faults into the critique, so a draft that
	 * misses a target is sent to revise. Fixtures here are three sentences long
	 * and would fail a 1200-word target every time, which would make every test
	 * in this file exercise the revise path whether it meant to or not.
	 *
	 * These tests are about the pipeline's control flow, not about scoring, so
	 * the targets are relaxed to let each one test what it says it tests. The
	 * scoring behaviour has its own tests.
	 *
	 * @return void
	 */
	private function use_permissive_blueprint() {
		$blueprint = Blogcraft_Blueprint::defaults();

		$blueprint['word_target']           = 20;
		$blueprint['word_tolerance']        = 60;
		$blueprint['sections_min']          = 1;
		$blueprint['sections_max']          = 12;
		$blueprint['sentence_max_words']    = 60;
		$blueprint['para_max_sentences']    = 12;
		$blueprint['reading_level']         = 'simple';
		$blueprint['external_links_target'] = 0;
		$blueprint['internal_links_target'] = 0;
		$blueprint['takeaways']             = false;
		$blueprint['faq']                   = false;
		$blueprint['banned_phrases']        = '';
		$blueprint['required_terms']        = '';
		$blueprint['primary_keyword']       = '';

		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		Blogcraft_Worker::reset_stages();
		Blogcraft_Cost::reset();
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );
		parent::tear_down();
	}

	/**
	 * A body long enough to satisfy the lowest word target a blueprint permits.
	 *
	 * normalise() floors word_target at 200 and caps tolerance at 60 percent, so
	 * the most forgiving band possible still demands 80 words. Fixtures shorter
	 * than that fail the length check, which sends every draft to revise and
	 * makes tests about control flow accidentally depend on scoring.
	 *
	 * @return string
	 */
	private function long_body() {
		return 'Cold water pulls fewer bitter compounds from the grounds than hot water does. '
			. 'That is the whole trick, and it is why the result tastes rounder. '
			. 'You need a coarse grind, a clean jar, and patience. '
			. 'Fill the jar with grounds and cold water. '
			. 'Leave it on the counter or in the fridge. '
			. 'Strain it through a filter when the time is up. '
			. 'The result keeps for about a week in a sealed bottle. '
			. 'Dilute it to taste, because the concentrate is strong. '
			. 'Most people use one part coffee to two parts water. '
			. 'Start there and adjust it until it suits you. '
			. 'A cheap jar works as well as any special brewer. '
			. 'The grind matters far more than the equipment does.';
	}

	/**
	 * An opening that satisfies the answer-first check.
	 *
	 * The check measures the first two sentences: no wind-up phrase, under
	 * sixty words, and standing on their own. A stub intro would send every
	 * test in this file down the revise path whether it meant to or not.
	 *
	 * @return string
	 */
	private function good_opening() {
		return 'Cold brew is steeped in cold water for twelve hours instead of being poured hot. '
			. 'That single change is what makes it taste rounder and less sharp.';
	}

	/**
	 * Queue OpenAI-shaped responses whose content is the given JSON payloads.
	 *
	 * @param array $payloads Ordered array of arrays to encode as message content.
	 * @return void
	 */
	private function fake_completions( $payloads ) {
		$this->queue = $payloads;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$next = array_shift( $this->queue );

				if ( null === $next ) {
					return new WP_Error( 'http_request_failed', 'no canned response left' );
				}

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'test-model',
							'choices' => array(
								array(
									'message'       => array( 'content' => wp_json_encode( $next ) ),
									'finish_reason' => 'stop',
								),
							),
							'usage'   => array(
								'prompt_tokens'     => 10,
								'completion_tokens' => 20,
							),
						)
					),
					'headers'  => array(),
				);
			},
			10,
			3
		);
	}

	// ---------------------------------------------------------------- blocks.

	public function test_paragraph_emits_block_markup() {
		$html = Blogcraft_Blocks::paragraph( 'Hello there.' );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $html );
		$this->assertStringContainsString( '<p>Hello there.</p>', $html );
	}

	public function test_heading_clamps_level_into_range() {
		$this->assertStringContainsString( '<h2>', Blogcraft_Blocks::heading( 'A', 1 ) );
		$this->assertStringContainsString( '<h4>', Blogcraft_Blocks::heading( 'A', 9 ) );
	}

	public function test_empty_text_produces_no_block() {
		$this->assertSame( '', Blogcraft_Blocks::paragraph( '   ' ) );
		$this->assertSame( '', Blogcraft_Blocks::unordered_list( array() ) );
	}

	public function test_script_tags_are_stripped_from_model_output() {
		$html = Blogcraft_Blocks::paragraph( 'Safe<script>alert(1)</script>' );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'Safe', $html );
	}

	public function test_render_produces_sections_faq_and_takeaways() {
		$html = Blogcraft_Blocks::render(
			array(
				'intro'         => 'An intro.',
				'key_takeaways' => array( 'One', 'Two' ),
				'sections'      => array(
					array(
						'heading'    => 'First section',
						'paragraphs' => array( 'Body copy.' ),
					),
				),
				'faq'           => array(
					array(
						'question' => 'Why?',
						'answer'   => 'Because.',
					),
				),
			)
		);

		$this->assertStringContainsString( 'An intro.', $html );
		$this->assertStringContainsString( 'First section', $html );
		$this->assertStringContainsString( '<li>One</li>', $html );
		$this->assertStringContainsString( 'Why?', $html );
		$this->assertStringContainsString( 'Because.', $html );
	}

	public function test_render_skips_malformed_sections_without_erroring() {
		$html = Blogcraft_Blocks::render(
			array(
				'sections' => array( 'not an array', array( 'heading' => 'Real' ) ),
			)
		);

		$this->assertStringContainsString( 'Real', $html );
	}

	// --------------------------------------------------------------- prompts.

	public function test_extract_json_parses_plain_json() {
		$this->assertSame( array( 'a' => 1 ), Blogcraft_Prompts::extract_json( '{"a":1}' ) );
	}

	public function test_extract_json_strips_code_fences() {
		$this->assertSame( array( 'a' => 1 ), Blogcraft_Prompts::extract_json( "```json\n{\"a\":1}\n```" ) );
	}

	public function test_extract_json_recovers_from_surrounding_prose() {
		$this->assertSame(
			array( 'a' => 1 ),
			Blogcraft_Prompts::extract_json( 'Sure! Here is the JSON: {"a":1} Hope that helps.' )
		);
	}

	public function test_extract_json_returns_empty_array_on_garbage() {
		$this->assertSame( array(), Blogcraft_Prompts::extract_json( 'no json at all' ) );
		$this->assertSame( array(), Blogcraft_Prompts::extract_json( '' ) );
	}

	// -------------------------------------------------------------- pipeline.

	public function test_enqueue_topic_creates_a_pending_job() {
		$id = Blogcraft_Pipeline::enqueue_topic( 'Cold brew coffee' );

		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_full_pipeline_creates_a_draft_post() {
		$this->fake_completions(
			array(
				// outline
				array(
					'title'            => 'How Cold Brew Coffee Actually Works',
					'slug'             => 'how-cold-brew-works',
					'meta_description' => 'Cold brew steeps in cold water for twelve hours, which pulls fewer bitter compounds and is why it tastes rounder.',
					'sections'         => array( array( 'heading' => 'The chemistry' ) ),
				),
				// draft: the opening only
				array(
					'intro'         => $this->good_opening(),
					'key_takeaways' => array( 'Steep for twelve hours.' ),
				),
				// section
				array( 'paragraphs' => array( $this->long_body() ) ),
				// The faq stage makes no call: use_permissive_blueprint() turns
				// questions off. A fixture here would silently feed the critique
				// stage the wrong response, and every stage after it.
				// critique
				array( 'problems' => array( 'The intro is vague.' ) ),
				// revise
				array(
					'intro'    => 'Cold brew trades heat for time, and that changes the flavour.',
					'sections' => array(
						array(
							'heading'    => 'The chemistry',
							'paragraphs' => array( $this->long_body() ),
						),
					),
				),
			)
		);

		Blogcraft_Pipeline::enqueue_topic( 'Cold brew coffee', 'draft' );

		// research, outline, draft, section, faq, extras, critique, revise,
		// verify, publish. Research, faq and extras cost no provider call here:
		// the blueprint turns questions off and no extra sections are asked for.
		// Driven until the queue stops rather than a fixed number of turns.
		// A hardcoded count has now broken three times, once for every stage
		// added to the pipeline, and each time the failure said "expected 1,
		// got 0" about a post that was two turns from existing.
		$this->drain();

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'complete' ) );

		$posts = get_posts(
			array(
				'post_status'    => 'draft',
				'posts_per_page' => 5,
				'meta_key'       => '_blogcraft_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$this->assertCount( 1, $posts );
		$this->assertSame( 'How Cold Brew Coffee Actually Works', $posts[0]->post_title );
		$this->assertStringContainsString( 'trades heat for time', $posts[0]->post_content );
		$this->assertStringContainsString( '<!-- wp:heading', $posts[0]->post_content );
	}

	public function test_pipeline_skips_revision_when_critique_finds_nothing() {
		$this->fake_completions(
			array(
				array(
					'title'            => 'A Clean Draft That Needs No Revision',
					'slug'             => 'clean-draft',
					'meta_description' => 'A draft written well enough the first time that the critique stage finds nothing at all to send back.',
					'sections'         => array( array( 'heading' => 'Only section' ) ),
				),
				array( 'intro' => $this->good_opening() ),
				array( 'paragraphs' => array( $this->long_body() ) ),
				array( 'problems' => array() ),
			)
		);

		Blogcraft_Pipeline::enqueue_topic( 'Anything', 'draft' );

		// research, outline, draft, section, faq, extras, critique, verify,
		// publish. Three of those cost no provider call.
		// Driven until the queue stops rather than a fixed number of turns.
		// A hardcoded count has now broken three times, once for every stage
		// added to the pipeline, and each time the failure said "expected 1,
		// got 0" about a post that was two turns from existing.
		$this->drain();

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'complete' ) );
	}

	public function test_the_extras_stage_costs_nothing_when_no_section_is_asked_for() {
		// Off is the default, and a stage that spent a request to add nothing
		// would be a tax on everybody who never turned one on.
		$this->fake_completions(
			array(
				array(
					'title'            => 'A Draft With No Extras At All',
					'slug'             => 'no-extras',
					'meta_description' => 'A post written with none of the optional extra sections switched on, to prove the stage costs nothing.',
					'sections'         => array( array( 'heading' => 'Only section' ) ),
				),
				array( 'intro' => $this->good_opening() ),
				array( 'paragraphs' => array( $this->long_body() ) ),
				array( 'problems' => array() ),
			)
		);

		Blogcraft_Pipeline::enqueue_topic( 'anything at all', 'draft' );

		// Driven until the queue stops rather than a fixed number of turns.
		// A hardcoded count has now broken three times, once for every stage
		// added to the pipeline, and each time the failure said "expected 1,
		// got 0" about a post that was two turns from existing.
		$this->drain();

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'complete' ) );

		// Four canned responses were queued and four were used. A fifth call
		// would have taken a WP_Error and failed the job.
		$this->assertSame( 4, Blogcraft_Cost::month_totals()['requests'] );
	}

	public function test_a_published_post_links_into_its_own_prose() {
		// A link inside a sentence is worth more than the same link in a list
		// at the bottom that nobody scrolls to.
		Blogcraft_Settings::set( 'internal_links_enabled', true );

		self::factory()->post->create(
			array(
				'post_title'  => 'How to choose a standing desk',
				'post_status' => 'publish',
			)
		);

		$this->fake_completions(
			array(
				array(
					'title'            => 'Working At A Standing Desk All Day',
					'slug'             => 'standing-desk-all-day',
					'meta_description' => 'What actually happens when you work at a standing desk for a full day, and how to set one up so it is bearable.',
					'sections'         => array( array( 'heading' => 'The first week' ) ),
				),
				array( 'intro' => $this->good_opening() ),
				array( 'paragraphs' => array( 'Before anything else you have to choose a standing desk that suits the room. ' . $this->long_body() ) ),
				array( 'problems' => array() ),
			)
		);

		Blogcraft_Pipeline::enqueue_topic( 'standing desks', 'draft' );

		// Driven until the queue stops rather than a fixed number of turns.
		// A hardcoded count has now broken three times, once for every stage
		// added to the pipeline, and each time the failure said "expected 1,
		// got 0" about a post that was two turns from existing.
		$this->drain();

		$posts = get_posts(
			array(
				'post_status'    => 'draft',
				'posts_per_page' => 5,
				'meta_key'       => '_blogcraft_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$this->assertCount( 1, $posts );
		$this->assertStringContainsString( 'choose a standing desk</a>', $posts[0]->post_content );
	}

	public function test_the_writers_own_figures_reach_the_prompt() {
		$job_id = Blogcraft_Pipeline::enqueue_topic(
			'standing desks',
			'draft',
			'',
			array(),
			'Our returns rate was 3 in 9.'
		);

		$this->assertGreaterThan( 0, $job_id );

		$rows    = Blogcraft_Queue::recent_jobs( 1 );
		$payload = json_decode( $rows[0]['payload'], true );

		$this->assertSame( 'Our returns rate was 3 in 9.', $payload['evidence'] );
	}

	public function test_pipeline_records_token_usage() {
		$this->fake_completions(
			array(
				array( 'title' => 'T', 'sections' => array( array( 'heading' => 'H' ) ) ),
			)
		);

		Blogcraft_Pipeline::enqueue_topic( 'Anything', 'draft' );

		// Research runs first and never calls the provider, so the second tick
		// is the earliest any tokens can have been spent.
		Blogcraft_Worker::run( 0 );
		Blogcraft_Worker::run( 0 );

		$totals = Blogcraft_Cost::month_totals();
		$this->assertSame( 10, $totals['prompt'] );
		$this->assertSame( 20, $totals['completion'] );
	}

	public function test_missing_provider_fails_the_job_rather_than_fataling() {
		Blogcraft_Settings::set( 'provider_type', 'nonsense' );

		Blogcraft_Pipeline::enqueue_topic( 'Anything', 'draft' );
		Blogcraft_Worker::run( 0 );

		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'running' ) );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_token_cap_stops_generation() {
		Blogcraft_Settings::set( 'monthly_token_cap', 5 );
		Blogcraft_Cost::record( 'openai', 'test-model', 10, 10 );

		Blogcraft_Pipeline::enqueue_topic( 'Anything', 'draft' );
		Blogcraft_Worker::run( 0 );

		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'complete' ) );
	}

	/**
	 * Run the queue until nothing is left to run.
	 *
	 * @param int $cap Most turns to take, so a stage that returns itself
	 *                 for ever fails the test rather than hanging it.
	 * @return void
	 */
	private function drain( $cap = 40 ) {
		for ( $i = 0; $i < $cap; $i++ ) {
			if ( 0 === Blogcraft_Worker::run( 0 ) ) {
				return;
			}
		}

		$this->fail( 'the queue never settled after ' . $cap . ' turns' );
	}
}
