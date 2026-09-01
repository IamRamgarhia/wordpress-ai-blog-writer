<?php
/**
 * One description of the voice, not two that both reach the model.
 *
 * Blogcraft_Voice::system_prompt() built a system prompt out of the voice_*
 * settings; Blogcraft_Blueprint::voice_rules() built one out of the
 * blueprint; and Prompts::base_system() concatenated both into a single
 * message. Every request carried two versions of the tone, the reader, the
 * point of view, the reading level, the banned words and the avoided
 * subjects — set on two different screens, with nothing keeping them in
 * agreement.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_One_Voice extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		delete_option( 'blogcraft_blueprints' );
		delete_option( 'blogcraft_blueprints_migrated' );
		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		delete_option( 'blogcraft_blueprints' );
		delete_option( 'blogcraft_blueprints_migrated' );
		delete_option( 'blogcraft_settings' );
		Blogcraft_Capabilities::remove();

		parent::tear_down();
	}

	/**
	 * The whole system prompt, both halves, as the model receives it.
	 *
	 * @return string
	 */
	private function whole_prompt() {
		Blogcraft_Prompts::use_blueprint( Blogcraft_Blueprint::get() );

		$method = new ReflectionMethod( 'Blogcraft_Prompts', 'base_system' );
		$method->setAccessible( true );

		return (string) $method->invoke( null );
	}

	public function test_nothing_the_blueprint_owns_is_said_twice() {
		$blueprint                    = Blogcraft_Blueprint::get();
		$blueprint['tone']            = 'custom';
		$blueprint['tone_custom']     = 'Dry and sceptical';
		$blueprint['audience']        = 'custom';
		$blueprint['audience_custom'] = 'People buying a first desk';
		$blueprint['niche']           = 'Home office kit';

		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		$prompt = $this->whole_prompt();

		// Each of these is an instruction the model has to follow. Two of
		// any one of them is two answers to the same question.
		foreach ( array( 'Tone:', 'You are writing for:' ) as $instruction ) {
			$this->assertSame(
				1,
				substr_count( $prompt, $instruction ),
				'"' . $instruction . '" appears ' . substr_count( $prompt, $instruction ) . ' times in one prompt'
			);
		}
	}

	public function test_the_system_prompt_leaves_the_blueprint_its_own_subjects() {
		// Said one way here and another way there is still said twice, so
		// this half must not mention them at all.
		$blueprint                    = Blogcraft_Blueprint::get();
		$blueprint['tone']            = 'custom';
		$blueprint['tone_custom']     = 'Dry and sceptical';
		$blueprint['audience']        = 'custom';
		$blueprint['audience_custom'] = 'People buying a first desk';

		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		$half = Blogcraft_Voice::system_prompt();

		foreach ( array( 'Tone:', 'You are writing for:', 'Point of view:', 'Reading level:' ) as $theirs ) {
			$this->assertStringNotContainsString(
				$theirs,
				$half,
				$theirs . ' belongs to the blueprint and is being sent twice again'
			);
		}
	}

	public function test_what_the_blueprint_does_not_say_still_reaches_the_model() {
		// The three that moved here rather than being dropped.
		$blueprint                = Blogcraft_Blueprint::get();
		$blueprint['niche']       = 'Standing desks, tested not summarised';
		$blueprint['style_rules'] = "No em dashes\nShort paragraphs";
		$blueprint['experience']  = 'We have tested forty desks since 2019';

		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		$prompt = $this->whole_prompt();

		$this->assertStringContainsString( 'Standing desks, tested not summarised', $prompt );
		$this->assertStringContainsString( 'No em dashes', $prompt );
		$this->assertStringContainsString( 'forty desks since 2019', $prompt );
	}

	public function test_the_banned_list_comes_from_the_blueprint() {
		$blueprint                   = Blogcraft_Blueprint::get();
		$blueprint['banned_phrases'] = "delve into\nunlock the power of";

		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		$banned = Blogcraft_Voice::banned_words();

		$this->assertContains( 'delve into', $banned );

		// And the standing list is still underneath it.
		$this->assertNotEmpty( array_intersect( $banned, Blogcraft_Voice::default_banned_words() ) );
	}

	public function test_an_older_site_keeps_what_it_typed_into_the_old_screen() {
		// Somebody who described their voice before this moved must not have
		// to type it again.
		Blogcraft_Settings::set( 'voice_niche', 'Woodworking for beginners' );
		Blogcraft_Settings::set( 'voice_style_rules', "Never open with a question" );
		Blogcraft_Settings::set( 'voice_experience', 'Twenty years at a bench' );
		Blogcraft_Settings::set( 'voice_banned_topics', 'Competitor names' );

		Blogcraft_Blueprint::migrate_from_voice();

		$blueprint = Blogcraft_Blueprint::get();

		$this->assertSame( 'Woodworking for beginners', $blueprint['niche'] );
		$this->assertSame( 'Never open with a question', $blueprint['style_rules'] );
		$this->assertSame( 'Twenty years at a bench', $blueprint['experience'] );
		$this->assertSame( 'Competitor names', $blueprint['avoid_subjects'] );
	}

	public function test_a_second_pass_does_not_overwrite_what_was_edited_since() {
		// The first pass built from defaults, which was right when this was
		// new and empty. Doing that again over an edited blueprint would
		// throw the edits away.
		Blogcraft_Settings::set( 'voice_niche', 'The old answer' );
		Blogcraft_Blueprint::migrate_from_voice();

		$blueprint          = Blogcraft_Blueprint::get();
		$blueprint['niche'] = 'The answer I typed afterwards';
		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		// Pretend a later release adds another field and runs again.
		update_option( 'blogcraft_blueprints_migrated', 1 );
		Blogcraft_Blueprint::migrate_from_voice();

		$this->assertSame(
			'The answer I typed afterwards',
			Blogcraft_Blueprint::get()['niche'],
			'a later migration overwrote an answer somebody had given'
		);
	}

	public function test_learning_from_posts_fills_the_screen_that_owns_the_voice() {
		// The button moved with the fields. Answering in the old setting
		// names would fill nothing at all.
		// Posts to learn from. Without them suggest() answers with an empty
		// set and the loop below runs zero times, which is a test that
		// passes by not looking.
		for ( $i = 0; $i < 4; $i++ ) {
			self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_title'   => 'Choosing a grinder ' . $i,
					'post_content' => '<p>You will want a burr grinder. We tested nine of them over four months and the cheap one wandered.</p>'
					. '<p>Here is what we found. The burrs matter more than the motor, and the stepless ones are worth it.</p>',
				)
			);
		}

		$fields = array_keys( (array) Blogcraft_Learn::suggest()['fields'] );
		$owned  = array_keys( Blogcraft_Blueprint::fields() );

		$this->assertNotEmpty( $fields, 'nothing was learned, so nothing is being checked' );

		foreach ( $fields as $field ) {
			$this->assertContains(
				$field,
				$owned,
				'Learn answers in "' . $field . '", which is not a field on the blueprint form'
			);
		}
	}
}
