<?php
/**
 * Starting points: named shapes, and matching a real article.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Shapes extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	// ------------------------------------------------------------- shapes.

	public function test_every_shape_is_complete() {
		foreach ( Blogcraft_Archetypes::all() as $slug => $shape ) {
			$this->assertNotSame( '', $shape['label'], $slug . ' has no label' );
			$this->assertNotSame( '', $shape['blurb'], $slug . ' does not say what it is for' );
			$this->assertNotEmpty( $shape['fields'], $slug . ' sets nothing' );
		}
	}

	public function test_every_shape_sets_only_fields_that_exist() {
		$known = Blogcraft_Blueprint::fields();

		foreach ( array_keys( Blogcraft_Archetypes::all() ) as $slug ) {
			foreach ( array_keys( Blogcraft_Archetypes::fields( $slug ) ) as $field ) {
				$this->assertArrayHasKey( $field, $known, $slug . ' sets an unknown field: ' . $field );
			}
		}
	}

	public function test_every_shape_sets_only_values_the_controls_accept() {
		// A value outside the offered list is stored and then ignored by every
		// control that reads it, so the shape silently does less than it says.
		$choices = Blogcraft_Archetypes::choice_values();

		foreach ( Blogcraft_Archetypes::all() as $slug => $shape ) {
			foreach ( $shape['fields'] as $field => $value ) {
				if ( ! isset( $choices[ $field ] ) ) {
					continue;
				}

				$this->assertContains(
					$value,
					$choices[ $field ],
					$slug . ' sets ' . $field . ' to a value nothing offers: ' . $value
				);
			}
		}
	}

	public function test_a_shape_survives_normalising() {
		$blueprint = Blogcraft_Blueprint::normalise(
			array_merge( Blogcraft_Blueprint::defaults(), Blogcraft_Archetypes::fields( 'guide' ) )
		);

		$this->assertSame( 2200, $blueprint['word_target'] );
		$this->assertTrue( (bool) $blueprint['faq'] );
		$this->assertTrue( (bool) $blueprint['toc'] );
	}

	public function test_the_shapes_are_actually_different_from_each_other() {
		$seen = array();

		foreach ( array_keys( Blogcraft_Archetypes::all() ) as $slug ) {
			$key = wp_json_encode( Blogcraft_Archetypes::fields( $slug ) );

			$this->assertNotContains( $key, $seen, $slug . ' is a duplicate of another shape' );
			$seen[] = $key;
		}
	}

	public function test_an_unknown_shape_sets_nothing() {
		$this->assertSame( array(), Blogcraft_Archetypes::fields( 'not-a-shape' ) );
	}

	// ------------------------------------------------------------- matching.

	/**
	 * A page shaped like a real article, with the usual furniture round it.
	 *
	 * @return string
	 */
	private function a_page() {
		$body = '<p>You can pick a standing desk in ten minutes if you know the two numbers that matter. '
			. 'Height range and wobble at full extension are the whole decision.</p>';

		for ( $i = 1; $i <= 4; $i++ ) {
			$body .= "<h2>Section {$i}</h2><p>" . str_repeat( 'The frame matters far more than the desktop does. ', 12 ) . '</p>';
		}

		$body .= '<ul><li>One</li><li>Two</li></ul><table><tr><td>x</td></tr></table>'
			. '<p>We measured 42% less wobble at 110cm, and it cost $400 over 6 months.</p>'
			. '<p>We tested nine desks. What we found was that our own returns rate was 3 in 9.</p>'
			. '<p><a href="https://elsewhere.test/a">out</a> <a href="https://elsewhere.test/b">out</a> '
			. '<a href="https://example.com/other">mine</a> <a href="#top">anchor</a></p>'
			. '<img src="a.jpg" alt="" /><img src="b.jpg" alt="" />'
			. '<h2>How long do they last?</h2><p>Years.</p><h2>Are they worth it?</h2><p>Yes.</p>';

		return '<html><head><title>Ignore me</title></head><body>'
			. '<nav>' . str_repeat( 'menu words here ', 200 ) . '</nav>'
			. '<header><h1>How To Choose A Standing Desk</h1></header>'
			. '<article>' . $body . '</article>'
			. '<footer>' . str_repeat( 'footer words here ', 200 ) . '</footer>'
			. '<script>var junk = 1;</script></body></html>';
	}

	public function test_the_furniture_round_an_article_is_not_counted_as_prose() {
		// Counting the navigation makes a 400-word article measure 3,000, and
		// every rule derived from it is then wrong.
		$article = Blogcraft_Emulate::article_of( $this->a_page() );

		$this->assertStringNotContainsString( 'menu words', $article );
		$this->assertStringNotContainsString( 'footer words', $article );
		$this->assertStringNotContainsString( 'var junk', $article );
		$this->assertStringContainsString( 'Section 1', $article );
	}

	public function test_the_headline_is_preferred_over_the_browser_title() {
		$this->assertSame( 'How To Choose A Standing Desk', Blogcraft_Emulate::title_of( $this->a_page() ) );
	}

	public function test_it_measures_the_structure() {
		$seen = Blogcraft_Emulate::measure(
			Blogcraft_Emulate::article_of( $this->a_page() ),
			'https://example.com/post'
		);

		$this->assertSame( 6, $seen['sections'] );
		$this->assertSame( 1, $seen['tables'] );
		$this->assertSame( 2, $seen['images'] );
		$this->assertGreaterThan( 250, $seen['words'] );
		$this->assertLessThan( 800, $seen['words'], 'the navigation inflated the count' );
	}

	public function test_it_tells_links_out_from_links_home() {
		$seen = Blogcraft_Emulate::measure(
			Blogcraft_Emulate::article_of( $this->a_page() ),
			'https://example.com/post'
		);

		$this->assertSame( 2, $seen['external_links'] );
		$this->assertSame( 1, $seen['internal_links'], 'a bare anchor is not a link anywhere' );
	}

	public function test_it_notices_a_run_of_questions() {
		$seen = Blogcraft_Emulate::measure( Blogcraft_Emulate::article_of( $this->a_page() ) );

		$this->assertTrue( $seen['faq'] );
	}

	public function test_the_rules_bracket_what_was_seen() {
		$seen   = Blogcraft_Emulate::measure( Blogcraft_Emulate::article_of( $this->a_page() ), 'https://example.com/post' );
		$fields = Blogcraft_Emulate::to_blueprint( $seen );

		$this->assertLessThanOrEqual( $seen['sections'], $fields['sections_min'] );
		$this->assertGreaterThanOrEqual( $seen['sections'], $fields['sections_max'] );

		// A ceiling, not an average: the mean would fail half the article it
		// was copied from.
		$this->assertGreaterThan( $seen['sentence_words'], $fields['sentence_max_words'] );
	}

	public function test_the_rules_carry_over_what_the_article_does() {
		$fields = Blogcraft_Emulate::to_blueprint(
			Blogcraft_Emulate::measure( Blogcraft_Emulate::article_of( $this->a_page() ), 'https://example.com/post' )
		);

		$this->assertTrue( $fields['tables'] );
		$this->assertTrue( $fields['lists'] );
		$this->assertTrue( $fields['faq'] );
		$this->assertTrue( $fields['require_statistics'] );
		$this->assertTrue( $fields['require_experience'] );
		$this->assertSame( 'first_plural', $fields['point_of_view'] );
	}

	public function test_an_article_addressing_the_reader_is_matched_that_way() {
		$html   = '<p>' . str_repeat( 'You will want to check your own setup first. ', 40 ) . '</p>';
		$fields = Blogcraft_Emulate::to_blueprint( Blogcraft_Emulate::measure( $html ) );

		$this->assertSame( 'second', $fields['point_of_view'] );
	}

	public function test_nothing_measured_means_nothing_set() {
		$this->assertSame( array(), Blogcraft_Emulate::to_blueprint( array( 'words' => 0 ) ) );
	}

	public function test_the_derived_rules_survive_normalising() {
		$fields = Blogcraft_Emulate::to_blueprint(
			Blogcraft_Emulate::measure( Blogcraft_Emulate::article_of( $this->a_page() ), 'https://example.com/post' )
		);

		$blueprint = Blogcraft_Blueprint::normalise( array_merge( Blogcraft_Blueprint::defaults(), $fields ) );

		$this->assertGreaterThanOrEqual( 200, $blueprint['word_target'] );
		$this->assertLessThanOrEqual( $blueprint['sections_max'], $blueprint['sections_min'] );
	}

	public function test_a_reading_score_maps_to_the_nearest_band() {
		$this->assertSame( 'simple', Blogcraft_Emulate::band_for( 90 ) );
		$this->assertSame( 'expert', Blogcraft_Emulate::band_for( 30 ) );
	}

	public function test_it_says_that_no_wording_was_taken() {
		// The whole defence of this feature is that it copies form and not
		// words, so the screen has to say so.
		$notes = Blogcraft_Emulate::notes(
			Blogcraft_Emulate::measure( Blogcraft_Emulate::article_of( $this->a_page() ), 'https://example.com/post' )
		);

		$this->assertStringContainsString( 'None of the wording was copied', implode( ' ', $notes ) );
	}

	public function test_a_page_that_is_not_a_url_is_refused() {
		$page = Blogcraft_Emulate::fetch( 'not a url' );

		$this->assertFalse( $page['ok'] );
		$this->assertNotSame( '', $page['error'] );
	}
}
