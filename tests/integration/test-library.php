<?php
/**
 * Nothing the plugin wrote should be able to disappear.
 *
 * A draft that finished writing and was never approved exists only as a job
 * row. It cost real money, it is complete, and no post exists for it — so
 * nothing in WordPress lists it, and closing the tab made it unreachable.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Library extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();

		global $wpdb;
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	// ------------------------------------------------------ held drafts.

	public function test_a_draft_nobody_acted_on_is_still_findable() {
		$job_id = $this->held_draft( 'Cold brew coffee', 72 );

		$held = Blogcraft_Queue::held_jobs();

		$this->assertCount( 1, $held );
		$this->assertSame( $job_id, (int) $held[0]['id'] );
	}

	public function test_held_drafts_are_counted() {
		$this->held_draft( 'One', 60 );
		$this->held_draft( 'Two', 80 );

		$this->assertSame( 2, Blogcraft_Queue::held_count() );
	}

	public function test_jobs_still_working_are_not_listed_as_waiting() {
		Blogcraft_Queue::enqueue( 'write_post', 'research', array( 'topic' => 'in progress' ) );

		$this->assertSame( 0, Blogcraft_Queue::held_count() );
	}

	public function test_the_newest_draft_comes_first() {
		$older = $this->held_draft( 'Older', 50 );
		$newer = $this->held_draft( 'Newer', 90 );

		$held = Blogcraft_Queue::held_jobs();

		$this->assertSame( $newer, (int) $held[0]['id'] );
		$this->assertSame( $older, (int) $held[1]['id'] );
	}

	public function test_discarding_a_draft_keeps_the_record_of_it() {
		// Cancelled rather than deleted: the row is the only record that this
		// topic was written and what it cost, and removing it would make the
		// Activity log lie by omission.
		$job_id = $this->held_draft( 'Unwanted', 40 );

		// A held draft holds no lock and is spending nothing, so it is safe to
		// cancel — but cancel() only listed the three statuses that existed
		// before drafts could be held, which made the Discard button a no-op.
		$this->assertTrue( Blogcraft_Queue::cancel( $job_id ), 'a held draft could not be discarded' );

		$this->assertSame( 0, Blogcraft_Queue::held_count() );
		$this->assertNotNull( Blogcraft_Queue::find( $job_id ), 'the job row was destroyed' );
		$this->assertSame( 'cancelled', Blogcraft_Queue::find( $job_id )->status );
	}

	// ---------------------------------------------------- created posts.

	public function test_posts_the_plugin_wrote_are_gathered() {
		$mine = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $mine, '_blogcraft_generated', 1 );

		// Somebody else's post, which must not appear.
		self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$found = Blogcraft_Library::generated_posts();

		$this->assertCount( 1, $found );
		$this->assertSame( $mine, $found[0]->ID );
	}

	public function test_drafts_and_held_posts_are_gathered_too() {
		// A post held for review is exactly the one somebody is looking for.
		foreach ( array( 'publish', 'draft', 'pending', 'future' ) as $status ) {
			$id = self::factory()->post->create(
				array(
					'post_status' => $status,
					'post_date'   => '2030-01-01 00:00:00',
				)
			);
			update_post_meta( $id, '_blogcraft_generated', 1 );
		}

		$this->assertCount( 4, Blogcraft_Library::generated_posts() );
	}

	// ------------------------------------------------- paused, not broken.

	public function test_a_rate_limited_job_is_reported_as_waiting_not_working() {
		// A 429 puts the job back as pending with available_at in the future.
		// The progress screen polled, could not claim it, got nothing, and
		// polled again — so waiting looked exactly like broken.
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'critique', array( 'topic' => 'grok' ) );
		Blogcraft_Queue::defer( $job_id, 30 * MINUTE_IN_SECONDS, 'HTTP 429: quota exceeded' );

		$state = Blogcraft_Progress::state( $job_id );

		$this->assertNotSame( '', $state['waiting'], 'a deferred job was not reported as waiting' );
		$this->assertTrue( $state['done'], 'the page would keep polling a job it cannot claim' );
	}

	public function test_a_job_merely_queued_is_not_reported_as_waiting() {
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'research', array( 'topic' => 'ordinary' ) );

		$state = Blogcraft_Progress::state( $job_id );

		$this->assertSame( '', $state['waiting'] );
		$this->assertFalse( $state['done'] );
	}

	public function test_the_write_screen_can_tell_a_limit_is_in_force() {
		$this->assertSame( array(), Blogcraft_Queue::rate_limited_until() );

		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'draft', array( 'topic' => 'anything' ) );
		Blogcraft_Queue::defer( $job_id, 30 * MINUTE_IN_SECONDS, 'HTTP 429: quota exceeded' );

		$limit = Blogcraft_Queue::rate_limited_until();

		$this->assertNotEmpty( $limit );
		$this->assertGreaterThan( time(), $limit['resumes'] );
		$this->assertStringContainsString( '429', $limit['reason'] );
	}

	/**
	 * A job parked at the review stage.
	 *
	 * @param string $title Draft title.
	 * @param int    $score Score out of 100.
	 * @return int Job id.
	 */
	private function held_draft( $title, $score ) {
		$job_id = Blogcraft_Queue::enqueue( 'write_post', 'verify', array( 'topic' => $title ) );

		Blogcraft_Queue::hold(
			$job_id,
			array(
				'topic'   => $title,
				'outline' => array( 'title' => $title ),
				'quality' => array(
					'score'   => $score,
					'reasons' => array(),
				),
				'article' => array( 'intro' => 'An opening.' ),
			)
		);

		return $job_id;
	}
}
