<?php
/**
 * The five wiring defects that made the quality gate decorative.
 *
 * Each of these was found by a full read-only audit of the plugin, then
 * verified by hand against this exact working tree before any of them were
 * touched: the threshold setting was never saved, the table-of-contents
 * toggle had no effect at publish, the external-links check demanded
 * something every prompt forbids the model from producing, the internal-links
 * check always measured a document with no internal links because they were
 * woven in after scoring, and a per-post slider dragged to zero was silently
 * discarded. A gate that cannot be configured, cannot be satisfied by design,
 * and cannot measure the thing it claims to measure is not a gate.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Quality_Gate extends WP_UnitTestCase {

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
		Blogcraft_Worker::reset_stages();
		Blogcraft_Cost::reset();
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );
		parent::tear_down();
	}

	// -------------------------------------------------------------------- C1.

	public function test_the_quality_threshold_is_actually_saved() {
		// handle_save() itself ends in wp_safe_redirect() + exit, which cannot
		// be called through safely in a test process — apply_submitted_settings()
		// is the part that was split out so this could be pinned directly.
		$method = new ReflectionMethod( Blogcraft_Connection::class, 'apply_submitted_settings' );
		$method->setAccessible( true );

		$_POST['quality_threshold']  = '85';
		$_POST['refresh_after_days'] = '30';

		$method->invoke( null );

		unset( $_POST['quality_threshold'], $_POST['refresh_after_days'] );

		$this->assertSame( 85, Blogcraft_Settings::get( 'quality_threshold' ) );
		$this->assertSame( 30, Blogcraft_Settings::get( 'refresh_after_days' ) );
	}

	public function test_the_quality_threshold_is_clamped_to_a_real_score_range() {
		$method = new ReflectionMethod( Blogcraft_Connection::class, 'apply_submitted_settings' );
		$method->setAccessible( true );

		$_POST['quality_threshold'] = '500';
		$method->invoke( null );
		unset( $_POST['quality_threshold'] );

		$this->assertSame( 100, Blogcraft_Settings::get( 'quality_threshold' ) );
	}

	// -------------------------------------------------------------------- C2.

	public function test_the_toc_is_silent_unless_the_blueprint_actually_asked_for_one() {
		$article = array(
			'sections' => array(
				array( 'heading' => 'One' ),
				array( 'heading' => 'Two' ),
				array( 'heading' => 'Three' ),
				array( 'heading' => 'Four' ),
			),
		);

		$this->assertSame( '', Blogcraft_Seo::render_toc( $article, false ) );
		$this->assertStringContainsString( 'What is covered', Blogcraft_Seo::render_toc( $article, true ) );
	}

	// -------------------------------------------------------------------- C3.

	public function test_sources_are_on_by_default_because_nothing_else_can_satisfy_the_check() {
		// The sources block is the only place a real, non-invented citation
		// link can come from (Blocks::sources() builds hrefs from the research
		// stage's URLs, never from model text) — so if this defaults off, the
		// external-links check defaults to something the model has no way to
		// pass, since every drafting prompt forbids it from writing markup.
		$this->assertTrue( Blogcraft_Blueprint::defaults()['block_sources'] );
	}

	public function test_the_composer_does_not_silently_force_sources_off() {
		// block_sources has no checkbox on the write screen. It used to sit in
		// the same override list as toggles that do have one, and an absent
		// toggle is read as "the user switched it off" — so every single post
		// written through the composer forced it false regardless of what the
		// blueprint said, no matter what this test's sibling above asserts.
		$method = new ReflectionMethod( Blogcraft_Generate::class, 'override_fields' );
		$method->setAccessible( true );
		$fields = $method->invoke( null );

		foreach ( array( 'block_sources', 'block_audience', 'block_proscons', 'block_figures', 'block_mistakes' ) as $key ) {
			$this->assertNotContains( $key, $fields['toggle'], $key . ' has no control on the write screen and must not be overridden' );
		}
	}

	public function test_a_failing_external_links_check_carries_no_repair_instruction() {
		// A repair note here used to tell the model to "cite a reputable
		// source" — advice a model writing plain text with no links has no way
		// to act on. Silence is correct: this check is about a settings choice
		// (Sources on, and research finding something to cite), not the prose.
		$blueprint                          = Blogcraft_Blueprint::defaults();
		$blueprint['external_links_target'] = 3;

		$scorecard = Blogcraft_Scorecard::evaluate(
			'<!-- wp:paragraph --><p>' . str_repeat( 'word ', 300 ) . '</p><!-- /wp:paragraph -->',
			$blueprint,
			array()
		);

		$failed = null;

		foreach ( $scorecard['checks'] as $check ) {
			if ( 'external_links' === $check['key'] ) {
				$failed = $check;
			}
		}

		$this->assertNotNull( $failed );
		$this->assertFalse( $failed['pass'] );
		$this->assertSame( '', $failed['repair'] );
	}

	// -------------------------------------------------------------------- C4.

	public function test_the_scorecard_sees_the_internal_links_that_actually_get_published() {
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_base_url', 'https://api.test/v1' );
		Blogcraft_Settings::set( 'provider_api_key', 'test-key' );
		Blogcraft_Settings::set( 'provider_model', 'test-model' );
		Blogcraft_Settings::set( 'monthly_token_cap', 0 );
		Blogcraft_Settings::set( 'research_wikipedia', false );
		Blogcraft_Settings::set( 'research_community', false );
		Blogcraft_Settings::set( 'internal_links_enabled', true );

		$blueprint                          = Blogcraft_Blueprint::defaults();
		$blueprint['word_target']           = 20;
		$blueprint['word_tolerance']        = 60;
		$blueprint['sections_min']          = 1;
		$blueprint['sections_max']          = 12;
		$blueprint['sentence_max_words']    = 60;
		$blueprint['para_max_sentences']    = 12;
		$blueprint['reading_level']         = 'simple';
		$blueprint['external_links_target'] = 0;
		$blueprint['internal_links_target'] = 1;
		$blueprint['takeaways']             = false;
		$blueprint['faq']                   = false;
		$blueprint['banned_phrases']        = '';
		$blueprint['required_terms']        = '';
		$blueprint['primary_keyword']       = '';
		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		self::factory()->post->create(
			array(
				'post_title'  => 'How to choose a standing desk',
				'post_status' => 'publish',
			)
		);

		$queue = array(
			array(
				'title'            => 'Working At A Standing Desk All Day',
				'slug'             => 'standing-desk-all-day',
				'meta_description' => 'What actually happens when you work at a standing desk for a full day, and how to set one up so it is bearable.',
				'sections'         => array( array( 'heading' => 'The first week' ) ),
			),
			array(
				'intro' => 'Cold brew is steeped in cold water for twelve hours instead of being poured hot. '
					. 'That single change is what makes it taste rounder and less sharp.',
			),
			array(
				'paragraphs' => array(
					'Before anything else you have to choose a standing desk that suits the room. '
					. 'Cold water pulls fewer bitter compounds from the grounds than hot water does. '
					. 'That is the whole trick, and it is why the result tastes rounder. '
					. 'You need a coarse grind, a clean jar, and patience. '
					. 'Fill the jar with grounds and cold water. '
					. 'Leave it on the counter or in the fridge. '
					. 'Strain it through a filter when the time is up. '
					. 'The result keeps for about a week in a sealed bottle. '
					. 'Dilute it to taste, because the concentrate is strong. '
					. 'Most people use one part coffee to two parts water. '
					. 'Start there and adjust it until it suits you. '
					. 'A cheap jar works as well as any special brewer.',
				),
			),
			array( 'problems' => array() ),
		);

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$queue ) {
				$next = array_shift( $queue );

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

		Blogcraft_Pipeline::enqueue_topic( 'standing desks', 'draft' );

		for ( $i = 0; $i < 9; $i++ ) {
			Blogcraft_Worker::run( 0 );
		}

		$posts = get_posts(
			array(
				'post_status'    => 'draft',
				'posts_per_page' => 5,
				'meta_key'       => '_blogcraft_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$this->assertCount( 1, $posts );

		// The link is really there...
		$this->assertStringContainsString( 'choose a standing desk</a>', $posts[0]->post_content );

		// ...and the scorecard that decided publish-or-hold saw it too. Before
		// this fix, internal_links was always measured against a render with
		// no links woven in yet, so this check could never pass no matter how
		// many links the published post actually carried.
		$checks = get_post_meta( $posts[0]->ID, '_blogcraft_checks', true );
		$link_check = null;

		foreach ( (array) $checks as $check ) {
			if ( 'internal_links' === $check['key'] ) {
				$link_check = $check;
			}
		}

		$this->assertNotNull( $link_check );
		$this->assertTrue( $link_check['pass'], 'internal_links: ' . wp_json_encode( $link_check ) );
	}

	// -------------------------------------------------------------------- C5.

	public function test_a_slider_dragged_to_zero_is_not_thrown_away() {
		$blueprint = Blogcraft_Blueprint::defaults();
		$blueprint['images_target']         = 4;
		$blueprint['external_links_target'] = 4;

		$result = Blogcraft_Blueprint::with_overrides(
			$blueprint,
			array(
				'images_target'         => '0',
				'external_links_target' => '0',
			)
		);

		$this->assertSame( 0, $result['images_target'], 'the "no pictures this time" override was discarded' );
		$this->assertSame( 0, $result['external_links_target'], 'the "nothing to cite this time" override was discarded' );
	}

	public function test_other_number_fields_still_treat_zero_as_not_overridden() {
		// The general rule this plugin uses everywhere else — a blank number
		// field must not silently zero out a considered default — is still
		// correct for fields that only ever get to zero via an empty text box.
		// Only the two sliders above are the deliberate exception.
		$blueprint                 = Blogcraft_Blueprint::defaults();
		$blueprint['sections_min'] = 3;

		$result = Blogcraft_Blueprint::with_overrides( $blueprint, array( 'sections_min' => '0' ) );

		$this->assertSame( 3, $result['sections_min'] );
	}

	// -------------------------------------------------------------------- M5.

	public function test_picture_related_help_links_point_at_the_pictures_card() {
		Blogcraft_Settings::set( 'images_enabled', true );
		Blogcraft_Settings::set( 'image_provider', 'openai' );

		$method = new ReflectionMethod( Blogcraft_Generate::class, 'pictures_note' );
		$method->setAccessible( true );

		$this->assertStringContainsString( '#bc-card-pictures', $method->invoke( null ) );
	}
}
