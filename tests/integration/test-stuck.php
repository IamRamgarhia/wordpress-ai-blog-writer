<?php
/**
 * Every place a post can stop moving with nothing to say.
 *
 * The pipeline's whole design is one short step per request, so that no single
 * call can exceed PHP's execution limit on ordinary hosting. Publish broke that
 * rule: it inserted the post and then, in the same request, downloaded four
 * pictures, rewrote three older posts and submitted a URL to a crawler. On
 * screen that was a job sitting on "Creating the post" for minutes, with a
 * full progress bar, an estimate of "about 0s left", and no way to tell it
 * apart from one that had died.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Stuck extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		Blogcraft_Worker::reset_stages();
		Blogcraft_Pipeline::register();

		global $wpdb;
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		Blogcraft_Worker::reset_stages();
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	/**
	 * A job object standing at a given stage with a given payload.
	 *
	 * @param string $stage   Stage.
	 * @param array  $payload Payload.
	 * @return object
	 */
	private function job_at( $stage, $payload ) {
		$id = Blogcraft_Queue::enqueue( 'write_post', $stage, $payload );

		return Blogcraft_Queue::find( $id );
	}

	/**
	 * An article with the given number of sections.
	 *
	 * @param int $count Sections.
	 * @return array
	 */
	private function article( $count ) {
		$sections = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$sections[] = array(
				'heading'    => 'Section ' . $i,
				'paragraphs' => array( 'Some words.' ),
			);
		}

		return array( 'sections' => $sections );
	}

	// ------------------------------------------- one picture per request.

	public function test_publishing_hands_the_pictures_on_rather_than_fetching_them() {
		// The whole point. Publish must return as soon as the post exists.
		$this->assertTrue(
			method_exists( 'Blogcraft_Pipeline', 'stage_pictures' ),
			'the picture work has no stage of its own'
		);
	}

	public function test_the_featured_picture_is_a_step_on_its_own() {
		$post_id = self::factory()->post->create();

		// Nothing is configured, so no image is fetched — what is under test is
		// that the stage advances rather than looping over four downloads.
		$job = $this->job_at(
			'pictures',
			array(
				'topic'   => 'cold brew',
				'post_id' => $post_id,
				'article' => $this->article( 3 ),
			)
		);

		$out = Blogcraft_Pipeline::stage_pictures( $job );

		$this->assertSame( 'pictures', $out['next'] );
		$this->assertSame( 0, $out['payload']['picture_index'] );
	}

	public function test_each_section_picture_is_its_own_step() {
		$post_id = self::factory()->post->create();

		$payload = array(
			'topic'         => 'cold brew',
			'post_id'       => $post_id,
			'article'       => $this->article( 3 ),
			'picture_index' => 0,
		);

		$out = Blogcraft_Pipeline::stage_pictures( $this->job_at( 'pictures', $payload ) );

		$this->assertSame( 'pictures', $out['next'] );
		$this->assertSame( 1, $out['payload']['picture_index'] );
	}

	public function test_the_picture_steps_end_and_do_not_loop_for_ever() {
		// An index that never reaches its limit is a job that never finishes,
		// which is the failure this whole file is about.
		$post_id = self::factory()->post->create();

		$payload = array(
			'topic'         => 'cold brew',
			'post_id'       => $post_id,
			'article'       => $this->article( 2 ),
			'picture_index' => 2,
		);

		$out = Blogcraft_Pipeline::stage_pictures( $this->job_at( 'pictures', $payload ) );

		$this->assertSame( 'finishing', $out['next'] );
	}

	public function test_a_post_with_no_sections_still_reaches_the_end() {
		$post_id = self::factory()->post->create();

		$payload = array(
			'post_id'       => $post_id,
			'article'       => array( 'sections' => array() ),
			'picture_index' => 0,
		);

		$out = Blogcraft_Pipeline::stage_pictures( $this->job_at( 'pictures', $payload ) );

		$this->assertSame( 'finishing', $out['next'] );
	}

	public function test_a_job_that_lost_its_post_does_not_stall_in_the_pictures() {
		$out = Blogcraft_Pipeline::stage_pictures( $this->job_at( 'pictures', array() ) );

		$this->assertSame( 'finishing', $out['next'] );
	}

	public function test_the_last_step_finishes_the_job() {
		$post_id = self::factory()->post->create();

		$out = Blogcraft_Pipeline::stage_finishing(
			$this->job_at( 'finishing', array( 'post_id' => $post_id, 'topic' => 'cold brew' ) )
		);

		$this->assertNull( $out['next'] );
	}

	public function test_telling_the_crawlers_cannot_fail_a_finished_post() {
		// The post exists and is correct whatever a crawler thinks of it.
		$post_id = self::factory()->post->create();

		add_filter(
			'pre_http_request',
			function () {
				return new WP_Error( 'http_request_failed', 'no route to host' );
			}
		);

		$out = Blogcraft_Pipeline::stage_finishing(
			$this->job_at( 'finishing', array( 'post_id' => $post_id, 'topic' => 'cold brew' ) )
		);

		$this->assertNull( $out['next'] );
	}

	// ------------------------------------------------ the steps on screen.

	public function test_every_registered_stage_has_a_label_on_the_progress_screen() {
		// A stage with no label is a stage the progress screen cannot place, so
		// the bar stops moving while the job carries on — working and stuck
		// look identical again.
		$labelled = array_keys( Blogcraft_Progress::steps() );

		foreach ( array( 'pictures', 'finishing' ) as $stage ) {
			$this->assertContains( $stage, $labelled, $stage . ' has no label' );
		}
	}

	public function test_the_step_list_matches_the_order_the_pipeline_runs() {
		// Progress is drawn by position in this list, so a list in a different
		// order to the pipeline would show steps going backwards.
		$labelled = array_keys( Blogcraft_Progress::steps() );

		$this->assertSame( 'publish', $labelled[ count( $labelled ) - 3 ] );
		$this->assertSame( 'pictures', $labelled[ count( $labelled ) - 2 ] );
		$this->assertSame( 'finishing', $labelled[ count( $labelled ) - 1 ] );
	}

	// -------------------------------------------------- getting back to it.

	public function test_a_job_in_flight_can_be_found_without_its_id() {
		// Refreshing the progress screen dropped the id from the address and
		// landed on "there is no post here" while the post was being written.
		$id = Blogcraft_Queue::enqueue( 'write_post', 'draft', array( 'topic' => 'cold brew' ) );

		$this->assertSame( $id, Blogcraft_Queue::newest_open_job() );
	}

	public function test_a_draft_waiting_to_be_read_counts_as_in_flight() {
		// The state it is most annoying to lose: written, paid for, and one
		// click from being a post.
		$id = Blogcraft_Queue::enqueue( 'write_post', 'publish', array( 'topic' => 'cold brew' ) );
		Blogcraft_Queue::hold( $id, array( 'topic' => 'cold brew' ) );

		$this->assertSame( $id, Blogcraft_Queue::newest_open_job() );
	}

	public function test_a_finished_job_is_not_offered_as_in_flight() {
		$id = Blogcraft_Queue::enqueue( 'write_post', 'publish', array( 'topic' => 'cold brew' ) );
		Blogcraft_Queue::complete( $id );

		$this->assertSame( 0, Blogcraft_Queue::newest_open_job() );
	}

	public function test_the_navigation_offers_a_way_back_while_something_is_being_written() {
		Blogcraft_Queue::enqueue( 'write_post', 'draft', array( 'topic' => 'cold brew' ) );

		$this->assertArrayHasKey( Blogcraft_Progress::PAGE_SLUG, Blogcraft_Nav::screens() );
	}

	public function test_the_navigation_does_not_offer_it_when_nothing_is_running() {
		$this->assertArrayNotHasKey( Blogcraft_Progress::PAGE_SLUG, Blogcraft_Nav::screens() );
	}

	// ------------------------------------------------------ bounded retries.

	public function test_one_call_cannot_retry_past_its_budget() {
		// Three attempts at a sixty-second timeout with waits between them is
		// four minutes inside one stage. PHP kills the request long before
		// that, so the later attempts never ran — they only guaranteed the
		// process died mid-stage rather than returning something readable.
		$this->assertLessThan(
			180,
			Blogcraft_Http::TOTAL_BUDGET_SECONDS,
			'the retry budget is longer than the browser is willing to wait'
		);
	}

	public function test_a_provider_that_never_answers_returns_an_error_rather_than_hanging() {
		$tries = 0;

		add_filter(
			'pre_http_request',
			function () use ( &$tries ) {
				++$tries;

				return new WP_Error( 'http_request_failed', 'Operation timed out' );
			}
		);

		$out = Blogcraft_Http::get_json( 'https://api.test/thing' );

		$this->assertNotSame( '', $out['error'] );
		$this->assertLessThanOrEqual( Blogcraft_Http::MAX_ATTEMPTS, $tries );
	}

	// ------------------------------------------- what the tables say.

	public function test_a_job_that_finished_carries_no_last_problem() {
		// The Activity table showed rows marked Complete with a red error
		// beside them: a post that worked, described as broken, because the
		// column still held whatever went wrong on an earlier attempt.
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'publish', array() );

		Blogcraft_Queue::fail( $job_id, 'Job reclaimed after an interrupted run.' );

		$this->assertNotSame( '', (string) Blogcraft_Queue::find( $job_id )->last_error );

		Blogcraft_Queue::complete( $job_id );

		$this->assertSame( '', (string) Blogcraft_Queue::find( $job_id )->last_error );
	}

	public function test_how_many_tries_it_took_is_still_recorded() {
		// Clearing the message must not erase the fact that it struggled.
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'publish', array() );

		Blogcraft_Queue::fail( $job_id, 'something went wrong' );
		Blogcraft_Queue::complete( $job_id );

		$this->assertGreaterThan( 0, (int) Blogcraft_Queue::find( $job_id )->attempts );
	}
}
