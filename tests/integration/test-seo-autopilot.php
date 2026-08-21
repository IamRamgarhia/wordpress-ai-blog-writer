<?php
/**
 * Internal linking, schema, image helpers, and autopilot tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Seo_Autopilot extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Autopilot::COUNTER_OPTION );

		// tick() only runs inside the configured window, and the defaults are
		// weekdays from 09:00. Left alone, these tests pass or fail on the hour
		// the suite happens to run at, which is how they were green locally and
		// red on an overnight CI run.
		Blogcraft_Settings::set( 'autopilot_days', '0,1,2,3,4,5,6' );
		Blogcraft_Settings::set( 'autopilot_hour', 0 );

		global $wpdb;
		$table = Blogcraft_Migrator::table_name( 'jobs' );
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Autopilot::COUNTER_OPTION );
		Blogcraft_Autopilot::unschedule();
		parent::tear_down();
	}

	// -------------------------------------------------------------- linking.

	public function test_related_posts_finds_published_posts() {
		self::factory()->post->create(
			array(
				'post_title'  => 'Cold brew extraction explained',
				'post_status' => 'publish',
			)
		);

		$related = Blogcraft_Seo::related_posts( 'cold brew coffee', 5 );

		$this->assertNotEmpty( $related );
		$this->assertArrayHasKey( 'url', $related[0] );
	}

	public function test_related_posts_excludes_the_current_post() {
		$id = self::factory()->post->create(
			array(
				'post_title'  => 'Cold brew extraction explained',
				'post_status' => 'publish',
			)
		);

		$related = Blogcraft_Seo::related_posts( 'cold brew', 5, $id );

		$ids = wp_list_pluck( $related, 'id' );
		$this->assertNotContains( $id, $ids );
	}

	public function test_related_posts_ignores_drafts() {
		self::factory()->post->create(
			array(
				'post_title'  => 'Unpublished cold brew notes',
				'post_status' => 'draft',
			)
		);

		$related = Blogcraft_Seo::related_posts( 'cold brew', 5 );

		$titles = wp_list_pluck( $related, 'title' );
		$this->assertNotContains( 'Unpublished cold brew notes', $titles );
	}

	public function test_related_block_renders_real_links() {
		$html = Blogcraft_Seo::render_related_block(
			array(
				array(
					'id'    => 1,
					'title' => 'A real post',
					'url'   => 'https://example.test/a-real-post/',
				),
			)
		);

		$this->assertStringContainsString( 'https://example.test/a-real-post/', $html );
		$this->assertStringContainsString( 'A real post', $html );
		$this->assertStringContainsString( '<!-- wp:list', $html );
	}

	public function test_related_block_is_empty_with_nothing_to_link() {
		$this->assertSame( '', Blogcraft_Seo::render_related_block( array() ) );
	}

	public function test_related_block_skips_entries_missing_a_url() {
		$html = Blogcraft_Seo::render_related_block( array( array( 'title' => 'No URL' ) ) );

		$this->assertSame( '', $html );
	}

	// --------------------------------------------------------------- schema.

	public function test_schema_describes_the_post() {
		$id = self::factory()->post->create(
			array(
				'post_title'   => 'How Cold Brew Works',
				'post_status'  => 'publish',
				'post_excerpt' => 'A short summary.',
			)
		);

		$graph = Blogcraft_Seo::build_schema( $id );

		$this->assertSame( 'BlogPosting', $graph['@type'] );
		$this->assertSame( 'How Cold Brew Works', $graph['headline'] );
		$this->assertSame( 'A short summary.', $graph['description'] );
		$this->assertArrayHasKey( 'datePublished', $graph );
	}

	public function test_schema_is_empty_for_a_missing_post() {
		$this->assertSame( array(), Blogcraft_Seo::build_schema( 999999 ) );
	}

	// --------------------------------------------------------------- images.

	public function test_image_url_encodes_the_prompt() {
		$url = Blogcraft_Images::source_url( 'cold brew & ice' );

		$this->assertStringContainsString( 'image.pollinations.ai', $url );
		$this->assertStringNotContainsString( ' ', $url );
	}

	public function test_image_filename_is_descriptive() {
		$this->assertSame( 'how-cold-brew-works.jpg', Blogcraft_Images::filename_for( 'How Cold Brew Works' ) );
	}

	public function test_image_filename_falls_back_when_title_is_unusable() {
		$this->assertSame( 'blogcraft-image.jpg', Blogcraft_Images::filename_for( '!!!' ) );
	}

	public function test_images_disabled_returns_no_attachment() {
		Blogcraft_Settings::set( 'images_enabled', false );
		$id = self::factory()->post->create();

		$this->assertSame( 0, Blogcraft_Images::attach_featured( $id, 'Title' ) );
	}

	// ------------------------------------------------------------ autopilot.

	public function test_tick_does_nothing_when_disabled() {
		Blogcraft_Settings::set( 'autopilot_enabled', false );
		Blogcraft_Settings::set( 'autopilot_topics', 'A topic' );

		$this->assertFalse( Blogcraft_Autopilot::tick() );
		$this->assertSame( 0, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_tick_queues_a_topic_when_enabled() {
		Blogcraft_Settings::set( 'autopilot_enabled', true );
		Blogcraft_Settings::set( 'autopilot_per_day', 5 );
		Blogcraft_Settings::set( 'autopilot_topics', "First topic\nSecond topic" );

		$this->assertTrue( Blogcraft_Autopilot::tick() );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_a_queued_topic_is_consumed() {
		Blogcraft_Settings::set( 'autopilot_enabled', true );
		Blogcraft_Settings::set( 'autopilot_per_day', 5 );
		Blogcraft_Settings::set( 'autopilot_topics', "First topic\nSecond topic" );

		Blogcraft_Autopilot::tick();

		$this->assertSame( array( 'Second topic' ), Blogcraft_Autopilot::topics() );
	}

	public function test_daily_cap_stops_further_generation() {
		Blogcraft_Settings::set( 'autopilot_enabled', true );
		Blogcraft_Settings::set( 'autopilot_per_day', 1 );
		Blogcraft_Settings::set( 'autopilot_topics', "One\nTwo\nThree" );

		$this->assertTrue( Blogcraft_Autopilot::tick() );
		$this->assertFalse( Blogcraft_Autopilot::tick() );
		$this->assertSame( 1, Blogcraft_Queue::count_by_status( 'pending' ) );
	}

	public function test_empty_topic_queue_does_nothing() {
		Blogcraft_Settings::set( 'autopilot_enabled', true );
		Blogcraft_Settings::set( 'autopilot_per_day', 5 );
		Blogcraft_Settings::set( 'autopilot_topics', '' );

		$this->assertFalse( Blogcraft_Autopilot::tick() );
	}

	public function test_token_cap_pauses_autopilot() {
		Blogcraft_Settings::set( 'autopilot_enabled', true );
		Blogcraft_Settings::set( 'autopilot_per_day', 5 );
		Blogcraft_Settings::set( 'autopilot_topics', 'A topic' );
		Blogcraft_Settings::set( 'monthly_token_cap', 10 );
		Blogcraft_Cost::record( 'openai', 'm', 20, 20 );

		$this->assertFalse( Blogcraft_Autopilot::tick() );

		Blogcraft_Cost::reset();
	}

	public function test_counter_resets_across_days() {
		update_option( Blogcraft_Autopilot::COUNTER_OPTION, '1999-01-01|99', false );

		$this->assertSame( 0, Blogcraft_Autopilot::generated_today() );
	}
}
