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
		$this->assertSame( Blogcraft_Image_Models::nothing(), Blogcraft_Image_Models::generate( 'a kettle', array() ) );
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

	public function test_alt_text_describes_the_picture_not_the_heading_above_it() {
		// The section heading used to be the alt text, so a screen reader read
		// the same words twice in a row — once as the heading, once as the
		// image — and learned nothing about the picture either time. The
		// brief's subject is the answer to "what does this picture show".
		$blueprint                   = $this->blueprint();
		$blueprint['image_describe'] = false;

		$brief = Blogcraft_Art_Direction::brief_for( 'How to season a pan', '', $blueprint, 'Why the first layer matters' );

		$this->assertSame( 'Why the first layer matters', $brief['subject'] );

		$attachment = self::factory()->attachment->create_object(
			array(
				'file'           => 'pan.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$block = Blogcraft_Images::image_block( $attachment, $brief['subject'] );

		$this->assertStringContainsString( 'alt="Why the first layer matters"', $block );

		// The format string lived in a double-quoted PHP string with its
		// dollars unescaped, so %1$d was read as "%1" plus the variable $d
		// and sprintf was handed an unknown specifier. On PHP 8 that threw,
		// and took the publish stage with it. Parsing the result is what
		// proves the markup is a block and not a string of text.
		$parsed = parse_blocks( $block );

		$this->assertSame( 'core/image', $parsed[0]['blockName'] );
		$this->assertSame( (int) $attachment, (int) $parsed[0]['attrs']['id'] );
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

	// ------------------------------------------------------ inline pictures.

	/**
	 * A real one-pixel PNG, base64 encoded.
	 *
	 * @return string
	 */
	private function a_png() {
		return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	}

	public function test_a_picture_can_arrive_as_bytes_rather_than_a_link() {
		// Google answers with the image inline. Everything downstream has to
		// cope with that as well as with a URL to fetch.
		$made = Blogcraft_Image_Models::from_response(
			array(
				'data' => array( array( 'b64_json' => $this->a_png() ) ),
			)
		);

		$this->assertSame( '', $made['url'] );
		$this->assertNotSame( '', $made['bytes'] );
		$this->assertSame( 'image/png', $made['mime'] );
	}

	public function test_a_payload_that_is_not_an_image_is_refused() {
		// A service having a bad day answers with an error page or a truncated
		// string. Writing that to disk and calling it a JPEG produces a broken
		// attachment nobody can explain later.
		$made = Blogcraft_Image_Models::from_response(
			array(
				'data' => array( array( 'b64_json' => base64_encode( 'not an image at all' ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building a deliberately invalid payload for the test.
			)
		);

		$this->assertSame( '', $made['bytes'] );
		$this->assertSame( '', $made['url'] );
	}

	public function test_a_url_is_preferred_when_one_is_offered() {
		$made = Blogcraft_Image_Models::from_response(
			array(
				'data' => array(
					array(
						'url'      => 'https://example.com/a.png',
						'b64_json' => $this->a_png(),
					),
				),
			)
		);

		$this->assertSame( 'https://example.com/a.png', $made['url'] );
		$this->assertSame( '', $made['bytes'] );
	}

	public function test_the_filename_extension_matches_the_bytes() {
		// WordPress checks the extension against the real type and rejects a
		// mismatch, so a PNG called .jpg never becomes an attachment.
		$this->assertStringEndsWith( '.png', Blogcraft_Images::filename_for( 'A title', 'image/png' ) );
		$this->assertStringEndsWith( '.webp', Blogcraft_Images::filename_for( 'A title', 'image/webp' ) );
		$this->assertStringEndsWith( '.jpg', Blogcraft_Images::filename_for( 'A title' ) );
		$this->assertStringEndsWith( '.jpg', Blogcraft_Images::filename_for( 'A title', 'image/jpeg' ) );
	}

	public function test_every_picture_service_names_the_settings_it_reads() {
		// A setting reached by building its name from a prefix is invisible to
		// a search of the source and to the test that checks nothing is dead.
		$schema = Blogcraft_Settings_Schema::all();

		foreach ( array_keys( Blogcraft_Image_Models::providers() ) as $service ) {
			$settings = Blogcraft_Image_Models::settings_for( $service );

			$this->assertNotSame( '', $settings['key'], $service . ' names no key setting' );
			$this->assertNotSame( '', $settings['model'], $service . ' names no model setting' );
			$this->assertArrayHasKey( $settings['key'], $schema );
			$this->assertArrayHasKey( $settings['model'], $schema );
		}
	}

	public function test_every_generated_service_is_offered_and_routable() {
		// Two lists meant adding a service in one place and having resolve()
		// never route to it.
		$offered = Blogcraft_Images::providers();

		foreach ( array_keys( Blogcraft_Image_Models::providers() ) as $service ) {
			$this->assertArrayHasKey( $service, $offered, $service . ' cannot be chosen' );
		}
	}

	public function test_a_service_with_no_key_makes_nothing() {
		foreach ( array( 'gemini', 'xai' ) as $service ) {
			Blogcraft_Settings::set( 'image_provider', $service );
			Blogcraft_Settings::set( 'provider_type', 'groq' );
			Blogcraft_Settings::set( 'image_key_' . $service, '' );
			Blogcraft_Settings::set( 'image_model_' . $service, 'a-model' );

			$this->assertFalse( Blogcraft_Image_Models::is_configured(), $service );
			$this->assertSame( '', Blogcraft_Image_Models::generate( 'a kettle', array() )['bytes'], $service );
		}
	}

	public function test_a_writing_key_is_shared_only_with_the_same_company() {
		Blogcraft_Settings::set( 'provider_type', 'gemini' );
		Blogcraft_Settings::set( 'provider_api_key', 'gemini-key' );
		Blogcraft_Settings::set( 'image_key_gemini', '' );
		Blogcraft_Settings::set( 'image_key_xai', '' );

		$this->assertSame( 'gemini-key', Blogcraft_Image_Models::key_for( 'gemini' ) );
		$this->assertSame( '', Blogcraft_Image_Models::key_for( 'xai' ) );
		$this->assertSame( '', Blogcraft_Image_Models::key_for( 'openai' ) );
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
