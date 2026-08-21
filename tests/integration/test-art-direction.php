<?php
/**
 * Art direction tests.
 *
 * These are the parts of image generation that can be checked without spending
 * money: what the prompt says, and whether every control the screen offers
 * actually changes it. A control that contributes nothing to the prompt is
 * decoration, and decoration that looks like a setting is worse than no setting.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Art_Direction extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	/**
	 * A blueprint with every art field set, so nothing falls back silently.
	 *
	 * @return array
	 */
	private function blueprint() {
		return array(
			'image_describe'   => false,
			'image_style'      => 'editorial',
			'image_mood'       => 'warm',
			'image_subject'    => 'object',
			'image_shape'      => '3:2',
			'image_palette'    => 'muted greens, warm oak',
			'image_extra'      => 'shot from slightly above',
			'image_avoid'      => 'crowds, brand names',
			'image_allow_text' => false,
		);
	}

	public function test_treatment_includes_every_stated_preference() {
		$treatment = Blogcraft_Art_Direction::treatment( $this->blueprint() );

		$this->assertStringContainsString( 'editorial photograph', $treatment );
		$this->assertStringContainsString( 'warm tones', $treatment );
		$this->assertStringContainsString( 'colour palette: muted greens, warm oak', $treatment );
		$this->assertStringContainsString( 'shot from slightly above', $treatment );
	}

	public function test_text_is_excluded_unless_asked_for() {
		// Image models render lettering as convincing gibberish, so a thumbnail
		// with misspelt words on it is the default failure this prevents.
		$avoid = Blogcraft_Art_Direction::avoid( $this->blueprint() );

		$this->assertStringContainsString( 'no text', $avoid );
		$this->assertStringContainsString( 'crowds, brand names', $avoid );
	}

	public function test_allowing_text_removes_the_exclusion() {
		$blueprint                     = $this->blueprint();
		$blueprint['image_allow_text'] = true;

		$avoid = Blogcraft_Art_Direction::avoid( $blueprint );

		$this->assertStringNotContainsString( 'no text', $avoid );
		$this->assertStringContainsString( 'crowds', $avoid );
	}

	public function test_every_offered_style_contributes_words() {
		foreach ( array_keys( Blogcraft_Art_Direction::styles() ) as $style ) {
			$treatment = Blogcraft_Art_Direction::treatment( array( 'image_style' => $style ) );

			$this->assertNotSame( '', $treatment, $style . ' contributes nothing to the prompt' );
		}
	}

	public function test_every_offered_mood_contributes_words() {
		$plain = Blogcraft_Art_Direction::treatment( array( 'image_style' => 'photo' ) );

		foreach ( array_keys( Blogcraft_Art_Direction::moods() ) as $mood ) {
			if ( '' === $mood ) {
				continue;
			}

			$treatment = Blogcraft_Art_Direction::treatment(
				array(
					'image_style' => 'photo',
					'image_mood'  => $mood,
				)
			);

			$this->assertNotSame( $plain, $treatment, $mood . ' changes nothing' );
		}
	}

	public function test_every_offered_shape_has_dimensions() {
		foreach ( array_keys( Blogcraft_Art_Direction::shapes() ) as $shape ) {
			$size = Blogcraft_Art_Direction::dimensions( $shape );

			$this->assertGreaterThan( 0, $size[0] );
			$this->assertGreaterThan( 0, $size[1] );
		}
	}

	public function test_unknown_shape_falls_back_to_wide() {
		$this->assertSame( array( 1344, 768 ), Blogcraft_Art_Direction::dimensions( 'not-a-shape' ) );
	}

	public function test_an_empty_blueprint_still_produces_a_usable_prompt() {
		$prompt = Blogcraft_Art_Direction::assemble( 'A kettle', array() );

		$this->assertStringStartsWith( 'A kettle.', $prompt );
		$this->assertStringContainsString( 'photograph', $prompt );
	}

	public function test_prompt_falls_back_to_the_title_when_describing_is_off() {
		$blueprint = $this->blueprint();

		$this->assertSame(
			Blogcraft_Art_Direction::assemble( 'How to season a pan', $blueprint ),
			Blogcraft_Art_Direction::prompt_for( 'How to season a pan', 'cast iron', $blueprint )
		);
	}

	public function test_a_section_image_is_about_the_section_not_the_headline() {
		// Otherwise every picture in the post is the same idea at a slightly
		// different angle, which is exactly what it looks like.
		$blueprint = $this->blueprint();

		$this->assertSame(
			Blogcraft_Art_Direction::assemble( 'Choosing an oil', $blueprint ),
			Blogcraft_Art_Direction::prompt_for( 'How to season a pan', 'cast iron', $blueprint, 'Choosing an oil' )
		);
	}

	public function test_describing_never_takes_the_post_down_with_it() {
		// No provider is configured in tests, so the model call throws. An image
		// is worth less than the post, so that must come back as a fallback
		// rather than an exception.
		$blueprint                   = $this->blueprint();
		$blueprint['image_describe'] = true;

		$prompt = Blogcraft_Art_Direction::prompt_for( 'How to season a pan', 'cast iron', $blueprint );

		$this->assertStringContainsString( 'How to season a pan', $prompt );
	}

	public function test_generative_providers_are_not_used_until_configured() {
		Blogcraft_Settings::set( 'image_provider', 'fal' );
		Blogcraft_Settings::set( 'fal_api_key', '' );
		Blogcraft_Settings::set( 'fal_model', '' );

		$this->assertFalse( Blogcraft_Image_Models::is_configured() );
		$this->assertSame( '', Blogcraft_Image_Models::generate( 'a kettle', array() ) );
	}

	public function test_a_reached_image_cap_stops_paid_generation() {
		Blogcraft_Cost::reset();
		Blogcraft_Settings::set( 'image_provider', 'fal' );
		Blogcraft_Settings::set( 'monthly_image_cap', 2 );

		$this->assertFalse( Blogcraft_Cost::over_image_cap() );

		Blogcraft_Cost::record_image();
		Blogcraft_Cost::record_image();

		$this->assertTrue( Blogcraft_Cost::over_image_cap() );
		$this->assertSame( 2, Blogcraft_Cost::month_totals()['images'] );

		Blogcraft_Cost::reset();
	}

	public function test_no_image_cap_means_no_limit() {
		Blogcraft_Cost::reset();
		Blogcraft_Settings::set( 'monthly_image_cap', 0 );

		for ( $i = 0; $i < 5; $i++ ) {
			Blogcraft_Cost::record_image();
		}

		$this->assertFalse( Blogcraft_Cost::over_image_cap() );

		Blogcraft_Cost::reset();
	}

	public function test_months_recorded_before_images_existed_still_read() {
		// The stored option predates the images key, and month_totals() is read
		// on every Overview load, so a missing key must not be a notice.
		Blogcraft_Cost::reset();
		Blogcraft_Cost::record( 'openai', 'gpt', 100, 200 );

		$this->assertSame( 0, Blogcraft_Cost::month_totals()['images'] );

		Blogcraft_Cost::reset();
	}

	public function test_a_stock_library_gets_keywords_not_a_prompt() {
		// Pexels matches on all terms, so handing it the whole assembled
		// instruction returns nothing — and because a miss falls through to the
		// next provider, the post still got a picture and nobody noticed.
		$terms = Blogcraft_Art_Direction::search_terms(
			'A worn oak workbench with a glass jar of cold brew coffee beside it'
		);

		$this->assertLessThanOrEqual( 5, count( explode( ' ', $terms ) ) );
		$this->assertStringNotContainsString( 'with', $terms );
		$this->assertStringContainsString( 'workbench', $terms );
	}

	public function test_the_search_terms_drop_words_about_the_photograph_itself() {
		$terms = Blogcraft_Art_Direction::search_terms( 'A photograph showing a kettle in frame' );

		$this->assertStringNotContainsString( 'photograph', $terms );
		$this->assertStringNotContainsString( 'frame', $terms );
		$this->assertStringContainsString( 'kettle', $terms );
	}

	public function test_the_brief_carries_subject_prompt_and_search_together() {
		// Describing costs a provider call, so asking for these separately
		// would pay for the same sentence twice.
		$brief = Blogcraft_Art_Direction::brief_for( 'How to season a pan', 'cast iron', $this->blueprint() );

		$this->assertSame( 'How to season a pan', $brief['subject'] );
		$this->assertStringContainsString( 'How to season a pan', $brief['prompt'] );
		$this->assertStringContainsString( 'season', $brief['search'] );
		$this->assertStringNotContainsString( 'no watermarks', $brief['search'] );
	}

	public function test_one_openai_key_covers_writing_and_pictures() {
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_api_key', 'sk-writing' );
		Blogcraft_Settings::set( 'openai_image_key', '' );

		$this->assertSame( 'sk-writing', Blogcraft_Image_Models::openai_key() );
	}

	public function test_a_key_from_another_company_is_not_borrowed_for_pictures() {
		// A Gemini key will not make an OpenAI image, and pretending it might is
		// how a setup screen comes to say "ready" about something that silently
		// falls back to free pictures on every post.
		Blogcraft_Settings::set( 'provider_type', 'gemini' );
		Blogcraft_Settings::set( 'provider_api_key', 'gemini-key' );
		Blogcraft_Settings::set( 'openai_image_key', '' );

		$this->assertSame( '', Blogcraft_Image_Models::openai_key() );
	}

	public function test_a_separate_image_key_wins_over_the_writing_one() {
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_api_key', 'sk-writing' );
		Blogcraft_Settings::set( 'openai_image_key', 'sk-pictures' );

		$this->assertSame( 'sk-pictures', Blogcraft_Image_Models::openai_key() );
	}

	public function test_openai_pictures_are_not_ready_without_a_model() {
		Blogcraft_Settings::set( 'image_provider', 'openai' );
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_api_key', 'sk-writing' );
		Blogcraft_Settings::set( 'openai_image_model', '' );

		// generate() refuses without a model, so is_configured() must agree.
		$this->assertFalse( Blogcraft_Image_Models::is_configured() );

		Blogcraft_Settings::set( 'openai_image_model', 'gpt-image-1' );

		$this->assertTrue( Blogcraft_Image_Models::is_configured() );
	}

	public function test_openai_pictures_are_not_ready_on_another_writing_provider() {
		Blogcraft_Settings::set( 'image_provider', 'openai' );
		Blogcraft_Settings::set( 'provider_type', 'groq' );
		Blogcraft_Settings::set( 'provider_api_key', 'gsk-writing' );
		Blogcraft_Settings::set( 'openai_image_key', '' );
		Blogcraft_Settings::set( 'openai_image_model', 'gpt-image-1' );

		$this->assertFalse( Blogcraft_Image_Models::is_configured() );
	}

	public function test_every_generative_provider_says_where_to_get_a_key() {
		foreach ( array_keys( Blogcraft_Image_Models::providers() ) as $provider ) {
			$help = Blogcraft_Image_Models::help( $provider );

			$this->assertNotSame( '', $help['key_url'] );
			$this->assertNotSame( '', $help['models_url'] );
		}
	}

	public function test_generative_providers_are_offered_on_the_settings_screen() {
		$offered = Blogcraft_Images::providers();

		foreach ( array_keys( Blogcraft_Image_Models::providers() ) as $provider ) {
			$this->assertArrayHasKey( $provider, $offered );
		}
	}
}
