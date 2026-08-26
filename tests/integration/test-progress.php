<?php
/**
 * Writing a post while somebody watches, and letting them decide.
 *
 * The plugin used to answer "write this post" with "Queued. The post will be
 * written in the background." That sentence was only true when WP-Cron fired,
 * which on a staging site or a quiet blog it frequently does not — the job sat
 * at Pending with zero attempts and an empty log, indistinguishable from
 * broken. And when it did work, the post appeared on the site without anybody
 * having read it.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Progress extends WP_UnitTestCase {

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
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'blogcraft_use_block_markup' );
		Blogcraft_Worker::reset_stages();
		Blogcraft_Cost::reset();
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );
		parent::tear_down();
	}

	// ------------------------------------------------- driving one job.

	public function test_a_named_job_can_be_advanced_without_cron() {
		// The whole point: the browser drives this, so a site where WP-Cron
		// never fires still writes the post.
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'research', array( 'topic' => 'cold brew' ) );

		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
		$this->assertTrue( Blogcraft_Worker::run_job( $job_id ) );

		// Research never fails a job and always advances.
		$this->assertNotSame( 'research', Blogcraft_Queue::find( $job_id )->stage );
	}

	public function test_advancing_takes_the_job_you_asked_for() {
		// claim() takes the oldest pending job, which would report somebody
		// else's progress on a screen showing yours.
		$first  = Blogcraft_Queue::enqueue( 'write_post', 'research', array( 'topic' => 'first' ) );
		$second = Blogcraft_Queue::enqueue( 'write_post', 'research', array( 'topic' => 'second' ) );

		Blogcraft_Worker::run_job( $second );

		$this->assertSame( 'research', Blogcraft_Queue::find( $first )->stage, 'the wrong job was advanced' );
		$this->assertNotSame( 'research', Blogcraft_Queue::find( $second )->stage );
	}

	public function test_a_job_that_is_not_claimable_reports_so() {
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );
		Blogcraft_Queue::claim_job( $job_id );

		// Already running: a second worker must not get it too.
		$this->assertNull( Blogcraft_Queue::claim_job( $job_id ) );
		$this->assertFalse( Blogcraft_Worker::run_job( $job_id ) );
	}

	// ------------------------------------------- holding for a decision.

	public function test_a_watched_post_stops_before_it_reaches_the_site() {
		$job_id = $this->run_to_completion( true );

		$job = Blogcraft_Queue::find( $job_id );

		$this->assertSame( 'ready', $job->status, 'the draft did not wait to be read' );
		$this->assertSame( 'publish', $job->stage, 'the held job is not pointed at publish' );
		$this->assertSame( 0, $this->generated_post_count(), 'a post was created before anybody approved it' );

		// The finished article and its score have to survive the hold, or the
		// review screen has nothing to show.
		$this->assertNotEmpty( $job->payload['article'] );
		$this->assertArrayHasKey( 'score', $job->payload['quality'] );
		$this->assertNotEmpty( $job->payload['checks'] );
	}

	public function test_approving_a_held_draft_creates_the_post() {
		$job_id = $this->run_to_completion( true );

		$this->assertTrue( Blogcraft_Queue::approve( $job_id ) );

		$this->drain_job( $job_id );

		$this->assertSame( 1, $this->generated_post_count() );
		$this->assertSame( 'complete', Blogcraft_Queue::find( $job_id )->status );
	}

	public function test_only_a_held_job_can_be_approved() {
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'research', array() );

		$this->assertFalse( Blogcraft_Queue::approve( $job_id ), 'a job still working was approved' );
	}

	public function test_unattended_writing_still_publishes_on_its_own() {
		// Autopilot has the quality gate and the review queue; it must not
		// start parking drafts nobody is waiting to look at.
		$job_id = $this->run_to_completion( false );

		$this->assertSame( 'complete', Blogcraft_Queue::find( $job_id )->status );
		$this->assertSame( 1, $this->generated_post_count() );
	}

	// ------------------------------------------------ the editor in use.

	public function test_block_markup_is_kept_for_the_block_editor() {
		$job_id = $this->run_to_completion( false );
		$posts  = $this->generated_posts();

		$this->assertStringContainsString( '<!-- wp:', $posts[0]->post_content );
	}

	public function test_block_delimiters_are_stripped_for_the_classic_editor() {
		// The comments render fine on the front end either way; in the Classic
		// editor they are visible clutter between every paragraph.
		add_filter( 'blogcraft_use_block_markup', '__return_false' );

		$job_id = $this->run_to_completion( false );
		$posts  = $this->generated_posts();
		$body   = $posts[0]->post_content;

		$this->assertStringNotContainsString( '<!-- wp:', $body );
		$this->assertStringNotContainsString( '<!-- /wp:', $body );

		// The HTML inside the delimiters survives untouched — this is a
		// different wrapper, not different content.
		$this->assertStringContainsString( '<p>', $body );
		$this->assertStringContainsString( '<h2>', $body );
	}

	// ------------------------------------------------------ the screen.

	public function test_the_screen_reports_a_held_job_as_ready() {
		$job_id = $this->run_to_completion( true );

		$state = Blogcraft_Progress::state( $job_id );

		$this->assertTrue( $state['ready'] );
		$this->assertTrue( $state['done'], 'a held job must stop the page polling' );
	}

	public function test_the_screen_stops_asking_when_a_job_has_gone() {
		$state = Blogcraft_Progress::state( 999999 );

		$this->assertTrue( $state['done'] );
		$this->assertSame( 'gone', $state['status'] );
	}

	public function test_every_stage_has_something_to_show_the_reader() {
		// A stage with no label would render as a blank row, which reads as a
		// bug rather than as progress.
		$steps = Blogcraft_Progress::steps();

		foreach ( array( 'research', 'outline', 'draft', 'section', 'faq', 'extras', 'critique', 'revise', 'verify', 'publish' ) as $stage ) {
			$this->assertArrayHasKey( $stage, $steps );
			$this->assertNotSame( '', trim( $steps[ $stage ] ) );
		}
	}

	// ----------------------------------------------------------- helpers.

	/**
	 * Run a whole post through, with canned provider responses.
	 *
	 * @param bool $await_review Whether the draft should wait to be read.
	 * @return int Job id.
	 */
	private function run_to_completion( $await_review ) {
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_base_url', 'https://api.test/v1' );
		Blogcraft_Settings::set( 'provider_api_key', 'test-key' );
		Blogcraft_Settings::set( 'provider_model', 'test-model' );
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

		$this->fake_completions(
			array(
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
			)
		);

		$job_id = Blogcraft_Pipeline::enqueue_topic( 'cold brew', 'draft', '', array(), '', array(), $await_review );

		// Driven until the job stops moving rather than a fixed number of
		// turns. A hardcoded count has broken once for every stage added to
		// the pipeline, and the failure always reads "expected 1, got 0"
		// about a post that was two turns from existing.
		$this->drain_job( $job_id );

		return $job_id;
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
	 * Queue canned provider responses.
	 *
	 * @param array $payloads Ordered payloads to return.
	 * @return void
	 */
	private function fake_completions( $payloads ) {
		$queue = $payloads;

		add_filter(
			'pre_http_request',
			function () use ( &$queue ) {
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
			3
		);
	}

	/**
	 * Posts this plugin generated.
	 *
	 * @return array
	 */
	private function generated_posts() {
		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'       => '_blogcraft_generated',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'     => '1',
			)
		);
	}

	/**
	 * How many generated posts exist.
	 *
	 * @return int
	 */
	private function generated_post_count() {
		return count( $this->generated_posts() );
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
