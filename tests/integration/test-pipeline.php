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
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		Blogcraft_Worker::reset_stages();
		Blogcraft_Cost::reset();
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
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
				array(
					'title'            => 'How Cold Brew Works',
					'slug'             => 'how-cold-brew-works',
					'meta_description' => 'What actually happens during a long steep.',
					'sections'         => array( array( 'heading' => 'The chemistry' ) ),
				),
				array(
					'intro'         => 'Cold brew is a slow extraction.',
					'key_takeaways' => array( 'Time replaces heat' ),
					'sections'      => array(
						array(
							'heading'    => 'The chemistry',
							'paragraphs' => array( 'Low temperature changes which compounds dissolve.' ),
						),
					),
					'faq'           => array(
						array(
							'question' => 'How long?',
							'answer'   => 'Twelve to eighteen hours.',
						),
					),
				),
				array( 'problems' => array( 'The intro is vague.' ) ),
				array(
					'intro'    => 'Cold brew trades heat for time, and that changes the flavour.',
					'sections' => array(
						array(
							'heading'    => 'The chemistry',
							'paragraphs' => array( 'Low temperature changes which compounds dissolve.' ),
						),
					),
				),
			)
		);

		Blogcraft_Pipeline::enqueue_topic( 'Cold brew coffee', 'draft' );

		// One stage per tick: outline, draft, critique, revise, publish.
		for ( $i = 0; $i < 5; $i++ ) {
			Blogcraft_Worker::run( 0 );
		}

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
		$this->assertSame( 'How Cold Brew Works', $posts[0]->post_title );
		$this->assertStringContainsString( 'trades heat for time', $posts[0]->post_content );
		$this->assertStringContainsString( '<!-- wp:heading', $posts[0]->post_content );
	}

	public function test_pipeline_skips_revision_when_critique_finds_nothing() {
		$this->fake_completions(
			array(
				array(
					'title'    => 'Clean Draft',
					'slug'     => 'clean-draft',
					'sections' => array( array( 'heading' => 'Only section' ) ),
				),
				array(
					'sections' => array(
						array(
							'heading'    => 'Only section',
							'paragraphs' => array( 'Already good.' ),
						),
					),
				),
				array( 'problems' => array() ),
			)
		);

		Blogcraft_Pipeline::enqueue_topic( 'Anything', 'draft' );

		// outline, draft, critique, publish — four ticks, no revise.
		for ( $i = 0; $i < 4; $i++ ) {
			Blogcraft_Worker::run( 0 );
		}

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'complete' ) );
	}

	public function test_pipeline_records_token_usage() {
		$this->fake_completions(
			array(
				array( 'title' => 'T', 'sections' => array( array( 'heading' => 'H' ) ) ),
			)
		);

		Blogcraft_Pipeline::enqueue_topic( 'Anything', 'draft' );
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
}
