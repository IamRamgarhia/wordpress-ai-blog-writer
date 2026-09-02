<?php
/**
 * The panel that says what you will get has to be right about it.
 *
 * It listed a featured image on every site, from the blueprint asking for
 * one — never checking whether the picture service that fetches it was
 * switched on. The row directly below it, for internal links, had always
 * checked its own switch. So the one panel whose entire job is to say what
 * the post will be was promising the most visible thing on it to sites
 * where none was ever coming.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Outcome_Honesty extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		delete_option( 'blogcraft_settings' );
		delete_option( 'blogcraft_blueprints' );

		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		delete_option( 'blogcraft_blueprints' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The labels the outline is built from.
	 *
	 * @return array
	 */
	private function outline() {
		return wp_list_pluck( Blogcraft_Preview::shape( Blogcraft_Blueprint::get() ), 'label' );
	}

	public function test_no_featured_image_is_promised_when_pictures_are_off() {
		Blogcraft_Settings::set( 'images_enabled', false );

		$labels = $this->outline();

		$this->assertNotContains( 'Featured image', $labels, 'a picture was promised that nothing will fetch' );
	}

	public function test_the_missing_picture_is_explained_rather_than_dropped() {
		// Silently leaving the row out would answer "will I get a picture?"
		// with nothing at all, which is the same question unanswered.
		Blogcraft_Settings::set( 'images_enabled', false );

		$this->assertContains( 'No featured image', $this->outline() );
	}

	public function test_the_picture_is_promised_once_it_can_actually_be_fetched() {
		Blogcraft_Settings::set( 'images_enabled', true );

		$labels = $this->outline();

		$this->assertContains( 'Featured image', $labels );
		$this->assertNotContains( 'No featured image', $labels );
	}

	public function test_the_confirmation_says_whether_there_will_be_a_picture() {
		// The question somebody has at the moment of agreeing, and the answer
		// lives on a settings screen two clicks away.
		Blogcraft_Settings::set( 'ask_before_writing', true );
		Blogcraft_Settings::set( 'images_enabled', false );

		ob_start();
		Blogcraft_Generate::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No pictures: the picture service is switched off', $html );

		Blogcraft_Settings::set( 'images_enabled', true );

		ob_start();
		Blogcraft_Generate::render();
		$on = (string) ob_get_clean();

		$this->assertStringContainsString( 'With pictures.', $on );
	}

	public function test_the_confirmation_says_where_the_post_will_land() {
		Blogcraft_Settings::set( 'ask_before_writing', true );

		ob_start();
		Blogcraft_Generate::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Saved as a draft', $html );
		$this->assertStringContainsString( 'words', $html );
	}

	public function test_only_the_provider_path_claims_to_know_what_it_reads() {
		// On the client path the application does the reading and brings its
		// own sources. This site has no idea what they were, so saying
		// "written from memory alone" there would be a guess stated as fact.
		Blogcraft_Settings::set( 'ask_before_writing', true );
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		ob_start();
		Blogcraft_Generate::render();
		$client = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'memory alone', $client );
		$this->assertStringNotContainsString( 'current sources', $client );

		// On the provider path it is this site doing the reading, so it can
		// say, and changes its mind once a source is switched on.
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::API );

		ob_start();
		Blogcraft_Generate::render();
		$api = (string) ob_get_clean();

		$this->assertStringContainsString( 'memory alone', $api );

		$sources = array_keys( Blogcraft_Research::free_sources() );
		Blogcraft_Settings::set( $sources[0], true );

		ob_start();
		Blogcraft_Generate::render();
		$after = (string) ob_get_clean();

		$this->assertStringContainsString( 'current sources', $after );
	}
}
