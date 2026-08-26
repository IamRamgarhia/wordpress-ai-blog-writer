<?php
/**
 * The first five minutes, and holding the outline to the shape asked for.
 *
 * Two problems that look unrelated and are not. A real run came back with ten
 * sections against a blueprint ceiling of seven, and the finished post was
 * shown to its author with that check marked failed — for a rule the plugin
 * had stated plainly in the prompt and then accepted the violation of.
 * Requesting is not enforcing. The wizard is the same idea earlier: the
 * settings screen asks for forty things and never says which three decide
 * whether the first post is worth reading.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Welcome_And_Outline extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );
		delete_option( Blogcraft_Welcome::DONE_OPTION );
		delete_option( Blogcraft_Welcome::PENDING_OPTION );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );
		delete_option( Blogcraft_Welcome::DONE_OPTION );
		delete_option( Blogcraft_Welcome::PENDING_OPTION );
		parent::tear_down();
	}

	// ------------------------------------------- holding the outline.

	/**
	 * Run the outline stage against a canned response.
	 *
	 * @param array $sections Sections the model is pretending to return.
	 * @return array
	 */
	private function outline_returning( $sections ) {
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_base_url', 'https://api.test/v1' );
		Blogcraft_Settings::set( 'provider_api_key', 'test-key' );
		Blogcraft_Settings::set( 'provider_key_owner', 'openai' );
		Blogcraft_Settings::set( 'provider_model', 'test-model' );
		Blogcraft_Settings::set( 'research_wikipedia', false );
		Blogcraft_Settings::set( 'research_community', false );

		$answer = array(
			'title'    => 'A title',
			'sections' => $sections,
		);

		add_filter(
			'pre_http_request',
			function () use ( $answer ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'model'   => 'test-model',
							'choices' => array(
								array(
									'message'       => array( 'content' => wp_json_encode( $answer ) ),
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

		$job = (object) array(
			'id'      => 0,
			'payload' => array( 'topic' => 'cold brew coffee' ),
		);

		$out = Blogcraft_Pipeline::stage_outline( $job );

		remove_all_filters( 'pre_http_request' );

		return $out['payload']['outline'];
	}

	/**
	 * Build a list of plausible sections.
	 *
	 * @param int $count How many.
	 * @return array
	 */
	private function sections( $count ) {
		$out = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$out[] = array(
				'heading' => 'Section ' . $i,
				'points'  => array( 'A point' ),
			);
		}

		return $out;
	}

	public function test_an_outline_longer_than_the_blueprint_allows_is_trimmed() {
		// The failure this exists for: ten sections against a ceiling of seven,
		// every one of them costing a provider call at the section stage, and
		// the scorecard marking the finished post down for having them.
		$blueprint                 = Blogcraft_Blueprint::defaults();
		$blueprint['sections_max'] = 7;
		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		$outline = $this->outline_returning( $this->sections( 10 ) );

		$this->assertCount( 7, $outline['sections'] );
	}

	public function test_trimming_keeps_the_opening_sections() {
		// Outlines are ordered by the argument they build, so the ones past the
		// ceiling are the tail. Dropping from the front would remove the setup
		// every later section depends on.
		$blueprint                 = Blogcraft_Blueprint::defaults();
		$blueprint['sections_max'] = 4;
		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		$outline = $this->outline_returning( $this->sections( 9 ) );

		$this->assertSame( 'Section 1', $outline['sections'][0]['heading'] );
		$this->assertSame( 'Section 4', $outline['sections'][3]['heading'] );
	}

	public function test_an_outline_within_the_range_is_left_alone() {
		$blueprint                 = Blogcraft_Blueprint::defaults();
		$blueprint['sections_max'] = 7;
		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		$outline = $this->outline_returning( $this->sections( 5 ) );

		$this->assertCount( 5, $outline['sections'] );
	}

	public function test_a_section_with_no_heading_is_dropped() {
		// A heading is what the section stage is given to write from, so an
		// entry without one produces a request for nothing and a block with an
		// empty <h2> in the finished post.
		$blueprint                 = Blogcraft_Blueprint::defaults();
		$blueprint['sections_max'] = 7;
		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		$sections   = $this->sections( 3 );
		$sections[] = array( 'points' => array( 'orphaned' ) );

		$outline = $this->outline_returning( $sections );

		$this->assertCount( 3, $outline['sections'] );
	}

	// --------------------------------------------- the first five minutes.

	public function test_activation_arms_the_introduction_on_a_bare_install() {
		Blogcraft_Welcome::arm();

		$this->assertTrue( (bool) get_option( Blogcraft_Welcome::PENDING_OPTION ) );
	}

	public function test_a_configured_site_is_not_sent_through_the_introduction() {
		// Deactivating and reactivating a working install is a routine thing to
		// do while debugging something else. It should not open a wizard.
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_api_key', 'a-key' );
		Blogcraft_Settings::set( 'provider_key_owner', 'openai' );

		Blogcraft_Welcome::arm();

		$this->assertFalse( (bool) get_option( Blogcraft_Welcome::PENDING_OPTION, false ) );
	}

	public function test_finishing_the_introduction_does_not_re_arm_it() {
		update_option( Blogcraft_Welcome::DONE_OPTION, 1, false );

		Blogcraft_Welcome::arm();

		$this->assertFalse( (bool) get_option( Blogcraft_Welcome::PENDING_OPTION, false ) );
	}

	public function test_the_introduction_asks_only_for_settings_that_exist() {
		// The wizard writes settings directly. A field renamed anywhere else
		// would leave this screen quietly saving into nothing, and the reader
		// would answer three questions that had no effect on their first post.
		$source = file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-welcome.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertNotFalse( $source );

		$known = Blogcraft_Settings_Schema::all();

		foreach ( array( 'voice_niche', 'voice_audience' ) as $key ) {
			$this->assertArrayHasKey( $key, $known, $key . ' is not a real setting' );
			$this->assertNotFalse( strpos( $source, $key ), $key . ' is no longer asked for' );
		}

		foreach ( array_keys( Blogcraft_Research::free_sources() ) as $key ) {
			$this->assertArrayHasKey( $key, $known, $key . ' is not a real setting' );
		}
	}
}
