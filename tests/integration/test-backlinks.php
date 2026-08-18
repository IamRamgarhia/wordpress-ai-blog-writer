<?php
/**
 * Backward linking and duplicate-topic tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Backlinks extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	// ------------------------------------------------------- managed block.

	public function test_block_contains_the_link() {
		$block = Blogcraft_Backlinks::build_block(
			array(
				array(
					'title' => 'New post',
					'url'   => 'https://example.test/new/',
				),
			)
		);

		$this->assertStringContainsString( 'https://example.test/new/', $block );
		$this->assertStringContainsString( Blogcraft_Backlinks::START, $block );
		$this->assertStringContainsString( Blogcraft_Backlinks::END, $block );
	}

	public function test_block_is_empty_without_usable_links() {
		$this->assertSame( '', Blogcraft_Backlinks::build_block( array( array( 'title' => 'No URL' ) ) ) );
	}

	public function test_strip_removes_only_the_managed_block() {
		$original = 'Real content here.';
		$block    = Blogcraft_Backlinks::build_block(
			array(
				array(
					'title' => 'New post',
					'url'   => 'https://example.test/new/',
				),
			)
		);

		$stripped = Blogcraft_Backlinks::strip_block( $original . $block );

		$this->assertStringContainsString( 'Real content here.', $stripped );
		$this->assertStringNotContainsString( 'example.test', $stripped );
	}

	public function test_strip_leaves_untouched_content_alone() {
		$this->assertSame( 'Nothing managed here.', Blogcraft_Backlinks::strip_block( 'Nothing managed here.' ) );
	}

	public function test_repeated_linking_does_not_accumulate_blocks() {
		$content = 'Body.';

		for ( $i = 0; $i < 3; $i++ ) {
			$content = Blogcraft_Backlinks::strip_block( $content ) . Blogcraft_Backlinks::build_block(
				array(
					array(
						'title' => 'New post',
						'url'   => 'https://example.test/new/',
					),
				)
			);
		}

		$this->assertSame( 1, substr_count( $content, Blogcraft_Backlinks::START ) );
	}

	// -------------------------------------------------------- link_back().

	public function test_link_back_updates_an_older_post() {
		Blogcraft_Settings::set( 'backlinks_enabled', true );

		$old = self::factory()->post->create(
			array(
				'post_title'   => 'Cold brew basics',
				'post_status'  => 'publish',
				'post_content' => 'Original body.',
			)
		);
		$new = self::factory()->post->create(
			array(
				'post_title'  => 'Cold brew ratios',
				'post_status' => 'publish',
			)
		);

		$updated = Blogcraft_Backlinks::link_back( $new, 'cold brew', 3 );

		$this->assertGreaterThan( 0, $updated );
		$this->assertStringContainsString(
			Blogcraft_Backlinks::START,
			get_post( $old )->post_content
		);
	}

	public function test_link_back_skips_a_draft_target_post() {
		Blogcraft_Settings::set( 'backlinks_enabled', true );

		self::factory()->post->create(
			array(
				'post_title'  => 'Cold brew basics',
				'post_status' => 'publish',
			)
		);
		$new = self::factory()->post->create(
			array(
				'post_title'  => 'Cold brew ratios',
				'post_status' => 'draft',
			)
		);

		// A draft would 404 for readers, so nothing should link to it yet.
		$this->assertSame( 0, Blogcraft_Backlinks::link_back( $new, 'cold brew', 3 ) );
	}

	public function test_link_back_respects_the_setting() {
		Blogcraft_Settings::set( 'backlinks_enabled', false );

		$new = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->assertSame( 0, Blogcraft_Backlinks::link_back( $new, 'anything', 3 ) );
	}

	public function test_link_back_never_links_a_post_to_itself() {
		Blogcraft_Settings::set( 'backlinks_enabled', true );

		$new = self::factory()->post->create(
			array(
				'post_title'   => 'Cold brew ratios',
				'post_status'  => 'publish',
				'post_content' => 'Body.',
			)
		);

		Blogcraft_Backlinks::link_back( $new, 'cold brew', 3 );

		$this->assertStringNotContainsString(
			Blogcraft_Backlinks::START,
			get_post( $new )->post_content
		);
	}

	// ------------------------------------------------------- duplicates.

	public function test_fingerprint_ignores_order_and_filler() {
		$this->assertSame(
			Blogcraft_Backlinks::fingerprint( 'How to brew cold coffee' ),
			Blogcraft_Backlinks::fingerprint( 'Coffee brew cold, the best guide' )
		);
	}

	public function test_identical_topics_score_one() {
		$this->assertSame( 1.0, Blogcraft_Backlinks::similarity( 'cold brew coffee', 'coffee cold brew' ) );
	}

	public function test_unrelated_topics_score_zero() {
		$this->assertSame( 0.0, Blogcraft_Backlinks::similarity( 'cold brew coffee', 'kitchen tile grouting' ) );
	}

	public function test_empty_topics_score_zero() {
		$this->assertSame( 0.0, Blogcraft_Backlinks::similarity( '', 'anything' ) );
	}

	public function test_duplicate_is_detected_against_a_generated_post() {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $id, Blogcraft_Backlinks::TOPIC_META, 'How to brew cold coffee at home' );

		$clash = Blogcraft_Backlinks::find_duplicate( 'Brewing cold coffee at home' );

		$this->assertNotSame( '', $clash );
	}

	public function test_a_fresh_topic_is_not_flagged() {
		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $id, Blogcraft_Backlinks::TOPIC_META, 'How to brew cold coffee at home' );

		$this->assertSame( '', Blogcraft_Backlinks::find_duplicate( 'Repointing a brick wall' ) );
	}

	public function test_duplicate_topic_is_refused_at_enqueue() {
		Blogcraft_Settings::set( 'duplicate_check_enabled', true );

		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $id, Blogcraft_Backlinks::TOPIC_META, 'How to brew cold coffee at home' );

		$this->assertSame( 0, Blogcraft_Pipeline::enqueue_topic( 'Brewing cold coffee at home' ) );
	}

	public function test_duplicate_check_can_be_turned_off() {
		Blogcraft_Settings::set( 'duplicate_check_enabled', false );

		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $id, Blogcraft_Backlinks::TOPIC_META, 'How to brew cold coffee at home' );

		$this->assertGreaterThan( 0, Blogcraft_Pipeline::enqueue_topic( 'Brewing cold coffee at home' ) );
	}
}
