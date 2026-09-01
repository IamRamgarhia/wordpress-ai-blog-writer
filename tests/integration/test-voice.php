<?php
/**
 * Brand voice tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Voice extends WP_UnitTestCase {
	/**
	 * Describe the voice where it now lives.
	 *
	 * @param array $values Blueprint fields to set.
	 * @return void
	 */
	private function describe( $values ) {
		Blogcraft_Blueprint::save(
			Blogcraft_Blueprint::DEFAULT_SLUG,
			array_merge( Blogcraft_Blueprint::get(), $values )
		);
	}


	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	public function test_unconfigured_site_still_gets_the_slop_word_ban() {
		$prompt = Blogcraft_Voice::system_prompt();

		// Banning the usual AI tells is worth doing even with zero configuration,
		// so this list is always in force.
		$this->assertStringContainsString( 'delve', $prompt );
	}

	public function test_unconfigured_site_has_no_brand_framing() {
		$prompt = Blogcraft_Voice::system_prompt();

		$this->assertStringNotContainsString( 'This blog is about', $prompt );
		$this->assertStringNotContainsString( 'You are writing for', $prompt );
		$this->assertStringNotContainsString( 'Tone:', $prompt );
	}

	public function test_is_configured_tracks_the_niche_field() {
		$this->assertFalse( Blogcraft_Voice::is_configured() );
		$this->describe( array( 'niche' => 'Specialty coffee brewing' ) );
		$this->assertTrue( Blogcraft_Voice::is_configured() );
	}

	public function test_to_list_splits_on_newlines_and_commas() {
		$this->assertSame(
			array( 'one', 'two', 'three' ),
			Blogcraft_Voice::to_list( "one\ntwo, three" )
		);
	}

	public function test_to_list_drops_blank_entries() {
		$this->assertSame( array( 'only' ), Blogcraft_Voice::to_list( "\n\n only \n,, \n" ) );
	}

	public function test_banned_words_include_defaults() {
		$this->assertContains( 'delve', Blogcraft_Voice::banned_words() );
	}

	public function test_banned_words_merge_user_additions_without_duplicates() {
		$this->describe( array( 'banned_phrases' => "synergy\ndelve" ) );
		$banned = Blogcraft_Voice::banned_words();

		$this->assertContains( 'synergy', $banned );
		$this->assertSame( 1, count( array_keys( $banned, 'delve', true ) ) );
	}

	public function test_system_prompt_includes_configured_fields() {
		$this->describe( array( 'niche' => 'Specialty coffee brewing' ) );
		$this->describe( array( 'audience' => 'custom', 'audience_custom' => 'Home baristas who already own a grinder' ) );
		$this->describe( array( 'tone' => 'custom', 'tone_custom' => 'Direct and practical' ) );
		$this->describe( array( 'style_rules' => "No em dashes\nShort paragraphs" ) );
		$this->describe( array( 'avoid_subjects' => 'Instant coffee' ) );
		$this->describe( array( 'experience' => 'I ran a cafe for six years.' ) );

		$prompt = Blogcraft_Voice::system_prompt();

		$this->assertStringContainsString( 'Specialty coffee brewing', $prompt );
		$this->assertStringContainsString( 'Home baristas', $prompt );
		$this->assertStringContainsString( 'Direct and practical', $prompt );
		$this->assertStringContainsString( 'No em dashes', $prompt );
		$this->assertStringContainsString( 'Instant coffee', $prompt );
		$this->assertStringContainsString( 'ran a cafe', $prompt );
	}

	public function test_voice_reaches_the_actual_prompts() {
		$this->describe( array( 'niche' => 'Specialty coffee brewing' ) );

		$messages = Blogcraft_Prompts::outline( 'Cold brew' );

		$this->assertSame( 'system', $messages[0]['role'] );
		$this->assertStringContainsString( 'Specialty coffee brewing', $messages[0]['content'] );
	}

	public function test_every_stage_carries_the_voice() {
		$this->describe( array( 'niche' => 'Specialty coffee brewing' ) );

		$article = array( 'sections' => array( array( 'heading' => 'H' ) ) );

		foreach (
			array(
				Blogcraft_Prompts::outline( 'T' ),
				Blogcraft_Prompts::draft( 'T', array() ),
				Blogcraft_Prompts::critique( $article ),
				Blogcraft_Prompts::revise( $article, array( 'vague' ) ),
			) as $messages
		) {
			$this->assertStringContainsString( 'Specialty coffee brewing', $messages[0]['content'] );
		}
	}
}
