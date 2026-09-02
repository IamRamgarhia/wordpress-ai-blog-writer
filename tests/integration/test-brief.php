<?php
/**
 * The next post, described here and written elsewhere.
 *
 * The Write a post screen had been reduced, on the client path, to a sentence
 * to paste. That threw away the topic field, the angle, the evidence box and
 * every per-post override — the things that make a post specific rather than
 * generic. The form is back and its answers are kept for a connected app to
 * collect.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Brief extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		delete_option( Blogcraft_Brief::OPTION );
		delete_option( 'blogcraft_settings' );

		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );
		Blogcraft_Settings::set( 'mcp_enabled', true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( Blogcraft_Brief::OPTION );
		delete_option( 'blogcraft_settings' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	public function test_the_client_path_still_has_the_whole_form() {
		// The fields are the point. A screen that asks only for a sentence to
		// paste has thrown away everything that makes a post specific.
		ob_start();
		Blogcraft_Generate::render();
		$html = (string) ob_get_clean();

		foreach ( array( 'bc_topic', 'name="instructions"', 'name="evidence"' ) as $field ) {
			$this->assertStringContainsString( $field, $html, $field . ' is missing from the form' );
		}

		// And it goes somewhere that exists on this path.
		$this->assertStringContainsString( 'value="blogcraft_save_brief"', $html );
		$this->assertStringNotContainsString( 'value="blogcraft_queue_topic"', $html );
	}

	public function test_the_provider_path_is_untouched() {
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::API );

		ob_start();
		Blogcraft_Generate::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="blogcraft_queue_topic"', $html );
		$this->assertStringNotContainsString( 'value="blogcraft_save_brief"', $html );
	}

	public function test_a_saved_brief_reaches_the_app_that_asks_for_it() {
		Blogcraft_Brief::save(
			array(
				'topic'     => 'Descaling a kettle',
				'angle'     => 'For somebody in a hard water area',
				'evidence'  => 'We tested 9 kettles over 4 months.',
				'overrides' => array(),
				'placement' => array(),
			)
		);

		$text = Blogcraft_Brief::as_text();

		$this->assertStringContainsString( 'Descaling a kettle', $text );
		$this->assertStringContainsString( 'hard water area', $text );
		$this->assertStringContainsString( 'We tested 9 kettles', $text );
	}

	public function test_only_the_choices_that_differ_are_carried() {
		// A brief that repeats every standing rule is a brief nobody reads to
		// the end of, and the rules are already a separate call.
		$standing = Blogcraft_Blueprint::get();

		Blogcraft_Brief::save(
			array(
				'topic'     => 'A topic',
				'angle'     => '',
				'evidence'  => '',
				'overrides' => array_merge( $standing, array( 'word_target' => 2400 ) ),
				'placement' => array(),
			)
		);

		$text = Blogcraft_Brief::as_text();

		$this->assertStringContainsString( 'word_target: 2400', $text );

		// Something left at its standing value is not repeated.
		$this->assertStringNotContainsString( 'point_of_view', $text );
	}

	public function test_a_number_left_alone_is_not_an_override_of_itself() {
		// The form posts text and the blueprint keeps numbers, so a strict
		// comparison called 2000 and "2000" different. Every brief ever
		// handed over announced the length, the sections and the sentence
		// limit as chosen for that post, on posts where nothing was touched.
		$standing = Blogcraft_Blueprint::get();
		$as_typed = array();

		// Exactly what the form sends: every value as a string.
		foreach ( $standing as $field => $value ) {
			$as_typed[ $field ] = is_array( $value ) ? $value : (string) $value;
		}

		Blogcraft_Brief::save(
			array(
				'topic'     => 'A topic',
				'angle'     => '',
				'evidence'  => '',
				'overrides' => $as_typed,
				'placement' => array(),
			)
		);

		$this->assertStringNotContainsString(
			'differ from the standing rules',
			Blogcraft_Brief::as_text(),
			'nothing was changed and the brief claims otherwise'
		);
	}

	public function test_a_number_actually_changed_is_still_carried() {
		// The other half. A comparison loose enough to stop crying wolf must
		// not go quiet about a real answer.
		$standing = Blogcraft_Blueprint::get();
		$as_typed = array();

		foreach ( $standing as $field => $value ) {
			$as_typed[ $field ] = is_array( $value ) ? $value : (string) $value;
		}

		$as_typed['word_target'] = '2400';

		Blogcraft_Brief::save(
			array(
				'topic'     => 'A topic',
				'angle'     => '',
				'evidence'  => '',
				'overrides' => $as_typed,
				'placement' => array(),
			)
		);

		$this->assertStringContainsString( 'word_target: 2400', Blogcraft_Brief::as_text() );
	}

	public function test_the_byline_chosen_on_the_brief_is_the_byline_on_the_post() {
		// The field took an answer, saved it, and was then read by nothing:
		// the text handed to the app never mentioned it and the app has no
		// parameter for it. This site applies it, the way the pipeline does.
		$writer = self::factory()->user->create( array( 'role' => 'author' ) );

		Blogcraft_Brief::save(
			array(
				'topic'     => 'Sharpening a chisel',
				'angle'     => '',
				'evidence'  => '',
				'overrides' => array(),
				'placement' => array( 'author' => $writer ),
			)
		);

		$out = Blogcraft_Mcp_Tools::call(
			'create_draft',
			array(
				'title' => 'Sharpening a chisel',
				'html'  => '<h2>How</h2><p>Words enough to be a paragraph about chisels.</p>',
			)
		);

		$hit = array();
		preg_match( '/draft (\d+)/', (string) $out['text'], $hit );
		$this->assertNotEmpty( $hit, (string) $out['text'] );

		$this->assertSame(
			$writer,
			(int) get_post_field( 'post_author', (int) $hit[1] ),
			'the byline chosen on the brief was not the one used'
		);
	}

	public function test_a_publishing_time_on_the_brief_reaches_the_call_that_publishes() {
		// Publishing happens in a later call than the one that knew about
		// it, and the brief is cleared in between, so the draft has to carry
		// it. Without this the field was a date nobody ever read.
		Blogcraft_Brief::save(
			array(
				'topic'     => 'Sharpening a chisel',
				'angle'     => '',
				'evidence'  => '',
				'overrides' => array(),
				'placement' => array( 'publish_at' => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ) ),
			)
		);

		$out = Blogcraft_Mcp_Tools::call(
			'create_draft',
			array(
				'title' => 'Sharpening a chisel',
				'html'  => '<h2>How</h2><p>Words enough to be a paragraph about chisels.</p>',
			)
		);

		$hit = array();
		preg_match( '/draft (\d+)/', (string) $out['text'], $hit );
		$this->assertNotEmpty( $hit, (string) $out['text'] );

		$post_id = (int) $hit[1];

		// Past the gate, because the date is what is under test.
		Blogcraft_Settings::set( 'quality_threshold', 0 );

		Blogcraft_Mcp_Tools::call( 'publish_draft', array( 'post_id' => $post_id ) );

		$this->assertSame(
			'future',
			get_post_status( $post_id ),
			'the time asked for on the brief was not honoured, so it published at once'
		);
	}

	public function test_nothing_is_waiting_until_something_is_asked_for() {
		$this->assertFalse( Blogcraft_Brief::waiting() );
		$this->assertSame( '', Blogcraft_Brief::as_text() );
	}

	public function test_the_tool_says_what_to_do_when_no_brief_is_waiting() {
		// Returning nothing would leave the app guessing whether the call
		// failed or the answer was empty.
		$out = Blogcraft_Mcp_Tools::call( 'get_brief', array() );
		$said = (string) $out['text'];

		$this->assertStringContainsString( 'No brief is waiting', $said );
		$this->assertStringContainsString( 'Ask what the post should be about', $said );
	}

	public function test_the_tool_hands_over_the_brief_that_is_waiting() {
		Blogcraft_Brief::save(
			array(
				'topic'     => 'Sharpening a chisel',
				'angle'     => '',
				'evidence'  => '',
				'overrides' => array(),
				'placement' => array(),
			)
		);

		$out = Blogcraft_Mcp_Tools::call( 'get_brief', array() );

		$this->assertStringContainsString( 'Sharpening a chisel', (string) $out['text'] );
	}

	public function test_writing_the_post_clears_the_brief() {
		// Leaving it would hand the next conversation a topic this site has
		// just covered, which is the duplicate find_duplicate exists to stop.
		Blogcraft_Brief::save(
			array(
				'topic'     => 'Sharpening a chisel',
				'angle'     => '',
				'evidence'  => '',
				'overrides' => array(),
				'placement' => array(),
			)
		);

		$this->assertTrue( Blogcraft_Brief::waiting() );

		Blogcraft_Mcp_Tools::call(
			'create_draft',
			array(
				'title' => 'Sharpening a chisel',
				'html'  => '<h2>How</h2><p>Words enough to be a paragraph about chisels.</p>',
			)
		);

		$this->assertFalse( Blogcraft_Brief::waiting(), 'the brief survived being acted on' );
	}

	public function test_the_screen_says_when_nothing_will_collect_the_brief() {
		// With no app connected the form is a dead end, and saying so is the
		// difference between a wait and a wait that never ends.
		delete_option( Blogcraft_Mcp_Auth::OPTION );
		delete_option( Blogcraft_Mcp_Oauth::CLIENTS );

		ob_start();
		Blogcraft_Generate::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'No app is connected yet', $html );
	}

	public function test_the_instruction_waits_until_there_is_a_brief_for_it() {
		// It was a standing panel above a form nobody had filled in yet,
		// telling them what to do after they had. Backwards, and unchanging,
		// so by the second visit it was furniture.
		Blogcraft_Mcp_Auth::issue( get_current_user_id(), 'an app' );

		ob_start();
		Blogcraft_Generate::render();
		$before = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Read my brief and my writing rules', $before );

		Blogcraft_Brief::save(
			array(
				'topic'     => 'Something to write',
				'angle'     => '',
				'evidence'  => '',
				'overrides' => array(),
				'placement' => array(),
			)
		);

		ob_start();
		Blogcraft_Generate::render();
		$after = (string) ob_get_clean();

		$this->assertStringContainsString( 'Read my brief and my writing rules', $after );
		$this->assertStringContainsString( 'data-copy=', $after, 'the instruction cannot be copied' );
	}
}
