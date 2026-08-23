<?php
/**
 * The things that go wrong when nobody is watching.
 *
 * Everything here is about unattended operation: a cron tick at four in the
 * morning, a job that dies half way, a plugin switched off months ago. None
 * of it is visible while you are sitting at the screen driving the plugin by
 * hand, which is exactly why it needs tests rather than testing.
 *
 * The duplicate-post case in particular is not hypothetical — a competing
 * plugin carries a public review reading "flooded my blog with hundreds of
 * duplicate posts", and the mechanism that produces it is the one guarded
 * against here.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Reliability extends WP_UnitTestCase {

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
		delete_option( 'blogcraft_autopilot_counter' );
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		Blogcraft_Worker::reset_stages();
		Blogcraft_Cost::reset();
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );
		delete_option( 'blogcraft_autopilot_counter' );
		parent::tear_down();
	}

	// ------------------------------------------------- publishing only once.

	public function test_a_reclaimed_publish_does_not_write_a_second_post() {
		// The exact sequence: publish inserts the post, then spends minutes on
		// pictures, then the process dies. The job is still marked running, so
		// reclaim_stale() eventually returns it to the queue and publish runs
		// again — against a payload that never got the post id, because a
		// payload is only saved when a stage returns.
		$job_id = Blogcraft_Queue::enqueue(
			'write_post',
			'publish',
			array(
				'topic'   => 'cold brew',
				'article' => array(
					'intro'    => 'An intro that is long enough to be a real one.',
					'sections' => array(
						array(
							'heading'    => 'The chemistry',
							'paragraphs' => array( 'Cold water pulls fewer bitter compounds from the grounds.' ),
						),
					),
				),
				'outline' => array(
					'title' => 'How Cold Brew Works',
					'slug'  => 'how-cold-brew-works',
				),
			)
		);

		$job = Blogcraft_Queue::claim();
		$this->assertNotNull( $job );

		Blogcraft_Pipeline::stage_publish( $job );

		$this->assertSame( 1, $this->generated_post_count() );

		// Put it back and claim it again: that reloads the row from the
		// database, which is exactly what reclaim_stale() leaves behind for
		// the next worker to pick up.
		Blogcraft_Queue::release( $job_id );
		$reloaded = Blogcraft_Queue::claim();
		$this->assertNotNull( $reloaded );

		Blogcraft_Pipeline::stage_publish( $reloaded );

		$this->assertSame( 1, $this->generated_post_count(), 'publishing twice created a second post' );
	}

	public function test_the_post_is_found_again_even_if_the_job_row_lost_it() {
		// The belt-and-braces half. If the write to the job row was itself
		// what failed, the payload comes back with no post id at all — so the
		// post carries the job id too, and the posts table is asked.
		$job_id = Blogcraft_Queue::enqueue(
			'write_post',
			'publish',
			array(
				'topic'   => 'cold brew',
				'article' => array(
					'intro'    => 'An intro that is long enough to be a real one.',
					'sections' => array( array( 'heading' => 'One', 'paragraphs' => array( 'Body.' ) ) ),
				),
				'outline' => array( 'title' => 'How Cold Brew Works' ),
			)
		);

		$job = Blogcraft_Queue::claim();
		Blogcraft_Pipeline::stage_publish( $job );
		$this->assertSame( 1, $this->generated_post_count() );

		// Wipe the post id back out of the payload, leaving only the post meta.
		Blogcraft_Queue::save_payload(
			$job_id,
			array(
				'topic'   => 'cold brew',
				'article' => array(
					'intro'    => 'An intro that is long enough to be a real one.',
					'sections' => array( array( 'heading' => 'One', 'paragraphs' => array( 'Body.' ) ) ),
				),
				'outline' => array( 'title' => 'How Cold Brew Works' ),
			)
		);

		Blogcraft_Queue::release( $job_id );
		Blogcraft_Pipeline::stage_publish( Blogcraft_Queue::claim() );

		$this->assertSame( 1, $this->generated_post_count(), 'the post meta guard did not hold' );
	}

	/**
	 * How many generated posts exist right now.
	 *
	 * @return int
	 */
	private function generated_post_count() {
		return count(
			get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'       => '_blogcraft_generated',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value'     => '1',
				)
			)
		);
	}

	// ------------------------------------------------ not reclaiming too soon.

	public function test_the_reclaim_window_outlasts_the_slowest_legitimate_stage() {
		// Reclaiming a job that is merely slow does not rescue anything: it
		// starts a second copy of a live one, which spends the provider's
		// money twice. The window has to sit above the worst case the plugin's
		// own timeout constants permit, so this derives that worst case from
		// those constants rather than restating a number.
		$one_provider_call = ( Blogcraft_Http::MAX_ATTEMPTS * 60 )
			+ ( ( Blogcraft_Http::MAX_ATTEMPTS - 1 ) * Blogcraft_Http::MAX_RETRY_AFTER_SECONDS );

		$link_checking = Blogcraft_Verify::MAX_LINKS * 8;

		$this->assertGreaterThan(
			$one_provider_call + $link_checking,
			Blogcraft_Queue::RECLAIM_AFTER_SECONDS,
			'a stage can legitimately run longer than the reclaim window'
		);
	}

	// ------------------------------------------------------- the daily cap.

	public function test_a_daily_maximum_of_zero_writes_nothing() {
		// It used to mean the opposite: the cap was skipped entirely, so zero
		// permitted a post every hour, while the calendar read the same zero
		// as one. Nobody types 0 into "maximum posts per day" wanting 24.
		Blogcraft_Settings::set( 'autopilot_enabled', true );
		Blogcraft_Settings::set( 'autopilot_per_day', 0 );
		Blogcraft_Settings::set( 'autopilot_days', '0,1,2,3,4,5,6' );
		Blogcraft_Settings::set( 'autopilot_hour', 0 );
		Blogcraft_Settings::set( 'autopilot_topics', "first topic\nsecond topic" );

		$this->assertFalse( Blogcraft_Autopilot::tick() );
		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_the_calendar_agrees_with_the_cap() {
		// plan() and tick() reading the same setting differently is how a
		// calendar comes to show posts that will never be written.
		Blogcraft_Settings::set( 'autopilot_enabled', true );
		Blogcraft_Settings::set( 'autopilot_per_day', 0 );
		Blogcraft_Settings::set( 'autopilot_days', '0,1,2,3,4,5,6' );
		Blogcraft_Settings::set( 'autopilot_topics', "first topic\nsecond topic" );

		$this->assertSame( array(), Blogcraft_Autopilot::plan() );
	}

	public function test_the_daily_counter_rolls_over_in_the_sites_own_timezone() {
		// The schedule is chosen in site time and in_window() reads it that
		// way, so a counter rolling at UTC midnight reset the allowance in the
		// middle of the working day on any site far from UTC.
		$original = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Pacific/Kiritimati' );

		$stored = (string) wp_date( 'Y-m-d' ) . '|3';
		update_option( 'blogcraft_autopilot_counter', $stored, false );

		$this->assertSame( 3, Blogcraft_Autopilot::generated_today() );

		// The same count stamped with the UTC day is a different day here, and
		// must therefore read as zero rather than as today's tally.
		if ( gmdate( 'Y-m-d' ) !== wp_date( 'Y-m-d' ) ) {
			update_option( 'blogcraft_autopilot_counter', gmdate( 'Y-m-d' ) . '|3', false );
			$this->assertSame( 0, Blogcraft_Autopilot::generated_today() );
		}

		update_option( 'timezone_string', false === $original ? '' : $original );
	}

	// ---------------------------------------------------------- the schedules.

	public function test_deactivating_clears_both_schedules() {
		Blogcraft_Scheduler::schedule();
		Blogcraft_Autopilot::schedule();

		$this->assertNotFalse( wp_next_scheduled( Blogcraft_Scheduler::HOOK ) );
		$this->assertNotFalse( wp_next_scheduled( Blogcraft_Autopilot::HOOK ) );

		Blogcraft_Deactivator::deactivate();

		// WordPress reschedules a recurring event whose callback is gone, so
		// an autopilot tick left behind here fires hourly forever on a site
		// whose owner switched the plugin off to make it stop.
		$this->assertFalse( wp_next_scheduled( Blogcraft_Scheduler::HOOK ) );
		$this->assertFalse( wp_next_scheduled( Blogcraft_Autopilot::HOOK ), 'the autopilot tick outlived deactivation' );
	}

	// ----------------------------------------------------------- the heartbeat.

	public function test_any_route_into_the_queue_records_a_heartbeat() {
		// The plugin's own docs recommend driving the queue from a real system
		// cron via `wp blogcraft run`, and on exactly that setup the heartbeat
		// was never written — so the health check reported the queue had
		// stopped, forever, while it was running on schedule.
		delete_option( 'blogcraft_cron_heartbeat' );

		Blogcraft_Worker::run( 0 );

		$this->assertNotEmpty(
			get_option( 'blogcraft_cron_heartbeat' ),
			'draining the queue directly left the health check thinking cron had stopped'
		);
	}
}
