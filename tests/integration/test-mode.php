<?php
/**
 * Which of the two ways this site is set up, and what follows from it.
 *
 * The setting was read in one place and used to decide which cards appeared
 * on one screen. Everything else carried on as though the provider way were
 * the only way, so a site being driven by Claude still offered a Write a post
 * screen that calls a provider it has no key for.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Mode extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		delete_option( 'blogcraft_settings' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_a_site_that_was_never_asked_behaves_as_it_always_did() {
		// The provider way is what every existing install has been doing, so
		// an unanswered question must not change anything under them.
		$this->assertFalse( Blogcraft_Mode::chosen() );
		$this->assertTrue( Blogcraft_Mode::is_api() );
		$this->assertTrue( Blogcraft_Mode::allows( 'blogcraft-write' ) );
		$this->assertTrue( Blogcraft_Mode::allows( 'blogcraft-calendar' ) );
	}

	public function test_the_screens_that_cannot_work_are_not_offered() {
		// Writing here calls a provider; the calendar needs something running
		// while nobody is watching. Neither happens on a site an app drives.
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		$this->assertFalse( Blogcraft_Mode::allows( 'blogcraft-calendar' ) );

		// Write a post stays. It cannot do its usual job here, but
		// taking it away removed the answer to "where do I write?"
		// without putting one anywhere.
		$this->assertTrue( Blogcraft_Mode::allows( 'blogcraft-write' ) );

		// And everything else still is. Naming only the exceptions means a
		// screen added later is available until somebody decides otherwise,
		// which is the right way round.
		foreach ( array( 'blogcraft-blueprint', 'blogcraft-library', 'blogcraft-settings', 'blogcraft-help' ) as $slug ) {
			$this->assertTrue( Blogcraft_Mode::allows( $slug ), $slug . ' disappeared for no reason' );
		}
	}

	public function test_the_navigation_drops_what_the_mode_cannot_use() {
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		ob_start();
		Blogcraft_Nav::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'page=blogcraft-calendar', $html );
		$this->assertStringContainsString( 'page=blogcraft-settings', $html );

		// The first question anybody has is where to write, and the
		// navigation is where they look for it.
		$this->assertStringContainsString( 'page=blogcraft-write', $html );
	}

	public function test_a_bookmarked_screen_explains_itself_rather_than_breaking() {
		// Somebody who followed a link deserves to be told what changed, not
		// handed a form that cannot be submitted.
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		ob_start();
		Blogcraft_Calendar::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Not on this setup', $html );
	}

	public function test_every_screen_named_as_mode_only_is_a_screen_that_exists() {
		// A rule about a screen slug nobody registers does nothing, and would
		// go unnoticed for as long as it took somebody to rename a page.
		$known = array_keys( (array) Blogcraft_Nav::screens() );

		foreach ( array_keys( Blogcraft_Mode::screens() ) as $slug ) {
			$this->assertContains( $slug, $known, $slug . ' is gated but is not a screen' );
		}
	}

	public function test_the_overview_says_which_way_the_site_writes() {
		// Nothing outside the settings screen said which of the two was in
		// force, so the overview described a provider setup to sites that had
		// deliberately chosen the other.
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		ob_start();
		Blogcraft_Overview::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bc-mode-now', $html );
		$this->assertStringContainsString( esc_html( Blogcraft_Mode::label() ), $html );
	}

	public function test_the_overview_checklist_does_not_ask_for_the_other_path() {
		// Telling somebody who chose an AI client to add an API key is
		// telling them to undo the choice they just made.
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		ob_start();
		Blogcraft_Overview::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Connect a provider', $html );
		$this->assertStringContainsString( 'Connect an AI client', $html );
	}

	public function test_the_settings_screen_and_the_mode_never_disagree() {
		// Two copies of the same answer is how the rail and the cards came to
		// disagree about which step was which.
		foreach ( array( Blogcraft_Mode::API, Blogcraft_Mode::CLIENT ) as $path ) {
			Blogcraft_Settings::set( 'setup_path', $path );

			$this->assertSame(
				Blogcraft_Mode::current(),
				Blogcraft_Connection::path(),
				'the settings screen has its own idea of the mode'
			);
		}
	}

	public function test_no_screen_links_anywhere_this_mode_cannot_go() {
		// The rule, not the one example CI happened to catch. A button to
		// a screen this setup does not have is worse than no button: it
		// looks like the way forward and is a dead end. Asserted over every
		// screen that stays available, so the next link added to any of
		// them fails here.
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		$screens = array(
			'Blogcraft_Overview',
			'Blogcraft_Connection',
		);

		$barred = array();

		foreach ( array_keys( Blogcraft_Mode::screens() ) as $slug ) {
			if ( ! Blogcraft_Mode::allows( $slug ) ) {
				$barred[] = $slug;
			}
		}

		$this->assertNotEmpty( $barred, 'nothing is barred, so this proves nothing' );

		foreach ( $screens as $screen ) {
			ob_start();
			call_user_func( array( $screen, 'render' ) );
			$html = (string) ob_get_clean();

			foreach ( $barred as $slug ) {
				$this->assertStringNotContainsString(
					'page=' . $slug,
					$html,
					$screen . ' links to ' . $slug . ', which this setup does not have'
				);
			}
		}
	}

	public function test_the_write_screen_keeps_its_form_and_changes_where_it_goes() {
		// It briefly became a sentence to paste, which threw away the
		// topic, the angle and the evidence box — the fields that make a
		// post specific rather than generic.
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		ob_start();
		Blogcraft_Generate::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bc_topic', $html, 'the form is gone again' );
		$this->assertStringContainsString( 'value="blogcraft_save_brief"', $html );
		$this->assertStringContainsString( 'Read my brief and my writing rules', $html );
	}
}
