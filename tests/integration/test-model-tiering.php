<?php
/**
 * Using a cheaper model for the parts that are execution, not judgement.
 *
 * Most of a post's words — and so most of its cost — are the section-by-section
 * writing, which carries out a plan the outline already made. The stages where
 * a weaker model actually changes the result are the ones that decide things:
 * the outline every later stage inherits, the opening the checks measure most
 * heavily, and the critique that has to notice what is wrong with prose that
 * reads fluently.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Model_Tiering extends WP_UnitTestCase {

	/**
	 * Model id seen on each outbound request, in order.
	 *
	 * @var array
	 */
	private $models = array();

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		Blogcraft_Worker::reset_stages();
		Blogcraft_Pipeline::register();
		Blogcraft_Cost::reset();

		global $wpdb;
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );

		$this->models = array();
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		Blogcraft_Worker::reset_stages();
		Blogcraft_Cost::reset();
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );
		parent::tear_down();
	}

	// ------------------------------------------------------------ routing.

	public function test_one_model_is_used_everywhere_when_no_cheaper_one_is_set() {
		// Blank is the default and has to stay a completely ordinary choice:
		// this is what every install did before tiering existed.
		$this->write_a_post( '' );

		$this->assertNotEmpty( $this->models );

		foreach ( $this->models as $model ) {
			$this->assertSame( 'main-model', $model );
		}
	}

	public function test_the_bulk_writing_uses_the_cheaper_model() {
		$this->write_a_post( 'cheap-model' );

		$this->assertContains( 'cheap-model', $this->models, 'the cheaper model was never used' );
		$this->assertContains( 'main-model', $this->models, 'the main model was never used' );
	}

	public function test_the_outline_and_opening_stay_on_the_main_model() {
		// The first two provider calls in a run are the outline and then the
		// opening. Both set up everything after them, so a weaker model there
		// is paid for in every section that follows.
		$this->write_a_post( 'cheap-model' );

		$this->assertSame( 'main-model', $this->models[0], 'the outline was written by the cheap model' );
		$this->assertSame( 'main-model', $this->models[1], 'the opening was written by the cheap model' );
	}

	public function test_the_critique_stays_on_the_main_model() {
		// The last call in this fixture is the critique — noticing what is
		// wrong with fluent prose is the hardest thing asked of the model
		// anywhere in the pipeline, and it drives the rewrite.
		$this->write_a_post( 'cheap-model' );

		$this->assertSame( 'main-model', end( $this->models ), 'the critique was done by the cheap model' );
	}

	public function test_sections_are_the_stage_that_changes() {
		// Named explicitly so that moving a stage in or out of the cheap set
		// is a deliberate edit with a failing test, not a quiet drift.
		$with    = $this->run_and_collect( 'cheap-model' );
		$without = $this->run_and_collect( '' );

		$this->assertSame( count( $without ), count( $with ), 'tiering changed how many calls a post costs' );

		$changed = array();

		foreach ( $with as $i => $model ) {
			if ( $model !== $without[ $i ] ) {
				$changed[] = $i;
			}
		}

		$this->assertNotEmpty( $changed, 'nothing was routed to the cheaper model' );

		// Position 2 is the section call in this fixture: outline, opening,
		// section, critique.
		$this->assertSame( array( 2 ), $changed );
	}

	// ----------------------------------------------------------- helpers.

	/**
	 * Run one post and keep the model ids it asked for.
	 *
	 * @param string $draft_model Cheaper model, or '' for none.
	 * @return array Model ids in call order.
	 */
	private function run_and_collect( $draft_model ) {
		$this->models = array();
		$this->write_a_post( $draft_model );

		return $this->models;
	}

	/**
	 * Write a post end to end against canned responses.
	 *
	 * @param string $draft_model Cheaper model, or '' for none.
	 * @return void
	 */
	private function write_a_post( $draft_model ) {
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_base_url', 'https://api.test/v1' );
		Blogcraft_Settings::set( 'provider_api_key', 'test-key' );
		Blogcraft_Settings::set( 'provider_model', 'main-model' );
		Blogcraft_Settings::set( 'provider_draft_model', $draft_model );
		Blogcraft_Settings::set( 'monthly_token_cap', 0 );
		Blogcraft_Settings::set( 'research_wikipedia', false );
		Blogcraft_Settings::set( 'research_community', false );

		$blueprint                          = Blogcraft_Blueprint::defaults();
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

		$queue = array(
			array(
				'title'            => 'How Cold Brew Coffee Actually Works',
				'slug'             => 'how-cold-brew-works',
				'meta_description' => 'Cold brew steeps in cold water for twelve hours, which pulls fewer bitter compounds and is why it tastes rounder.',
				'sections'         => array( array( 'heading' => 'The chemistry' ) ),
			),
			array(
				'intro' => 'Cold brew is steeped in cold water for twelve hours instead of being poured hot. '
					. 'That single change is what makes it taste rounder and less sharp.',
			),
			array( 'paragraphs' => array( $this->body() ) ),
			array( 'problems' => array() ),
		);

		$seen = &$this->models;

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$queue, &$seen ) {
				$body = json_decode( isset( $args['body'] ) ? (string) $args['body'] : '', true );

				if ( is_array( $body ) && isset( $body['model'] ) ) {
					$seen[] = (string) $body['model'];
				}

				$next = array_shift( $queue );

				if ( null === $next ) {
					return new WP_Error( 'http_request_failed', 'no canned response left' );
				}

				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
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
				);
			},
			10,
			2
		);

		$job_id = Blogcraft_Pipeline::enqueue_topic( 'cold brew ' . wp_generate_password( 6, false ), 'draft' );

		// Driven until the job stops moving rather than a fixed number of
		// turns. A hardcoded count has broken once for every stage added to
		// the pipeline, and the failure always reads "expected 1, got 0"
		// about a post that was two turns from existing.
		$this->drain_job( $job_id );

		remove_all_filters( 'pre_http_request' );
	}

	/**
	 * A body long enough to clear the lowest word target allowed.
	 *
	 * @return string
	 */
	private function body() {
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
	 * Run one job until it stops moving.
	 *
	 * @param int $job_id Job.
	 * @param int $cap    Most turns to take, so a stage that returns
	 *                    itself for ever fails rather than hangs.
	 * @return void
	 */
	private function drain_job( $job_id, $cap = 40 ) {
		for ( $i = 0; $i < $cap; $i++ ) {
			if ( ! Blogcraft_Worker::run_job( $job_id ) ) {
				return;
			}
		}

		$this->fail( 'job ' . $job_id . ' never settled after ' . $cap . ' turns' );
	}
}
