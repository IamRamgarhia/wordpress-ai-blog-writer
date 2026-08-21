<?php
/**
 * Structural check tests.
 *
 * These read rendered markup rather than prose, which is exactly where a check
 * can be wrong without looking wrong: an invalid pattern makes preg_* return
 * false, which reads as "nothing found", which reads as "passes". So every one
 * of them is asserted against markup built to fail as well as markup built to
 * pass.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Structure extends WP_UnitTestCase {

	/**
	 * A section long enough that nothing should call it thin.
	 *
	 * @return string
	 */
	private function long_section() {
		return '<p>' . str_repeat( 'The grind matters far more than the equipment does. ', 20 ) . '</p>';
	}

	// ------------------------------------------------------------- alt text.

	public function test_an_image_with_no_alt_attribute_is_counted() {
		$this->assertSame( 1, Blogcraft_Structure::images_without_alt( '<img src="a.jpg" />' ) );
	}

	public function test_an_empty_alt_attribute_counts_as_missing() {
		$this->assertSame( 1, Blogcraft_Structure::images_without_alt( '<img src="a.jpg" alt="" />' ) );
	}

	public function test_a_described_image_passes() {
		$this->assertSame( 0, Blogcraft_Structure::images_without_alt( '<img src="a.jpg" alt="A jar of cold brew" />' ) );
	}

	public function test_content_with_no_images_has_nothing_missing() {
		$this->assertSame( 0, Blogcraft_Structure::images_without_alt( '<p>No pictures here.</p>' ) );
	}

	// -------------------------------------------------------- heading order.

	public function test_h2_followed_by_h3_is_in_order() {
		$this->assertTrue( Blogcraft_Structure::heading_order_ok( '<h2>A</h2><h3>B</h3>' ) );
	}

	public function test_h2_followed_by_h4_skips_a_level() {
		$this->assertFalse( Blogcraft_Structure::heading_order_ok( '<h2>A</h2><h4>B</h4>' ) );
	}

	public function test_content_with_no_headings_is_in_order() {
		$this->assertTrue( Blogcraft_Structure::heading_order_ok( '<p>No headings here.</p>' ) );
	}

	// ------------------------------------------------------- thin sections.

	public function test_a_thin_prose_section_is_caught() {
		$content = '<h2>The chemistry</h2>' . $this->long_section()
			. '<h2>One more thing</h2><p>Not much to say here.</p>';

		// The heading's own words belong to the section it opens.
		$this->assertSame( 8, Blogcraft_Structure::thinnest_section( $content ) );
	}

	public function test_a_key_takeaways_list_is_not_a_thin_section() {
		// Takeaways are short on purpose and sit under an h2, so measuring them
		// flagged every post that used them.
		$content = '<p>Cold brew trades heat for time.</p>'
			. '<h2>Key takeaways</h2><ul><li>Steep for twelve hours.</li></ul>'
			. '<h2>The chemistry</h2>' . $this->long_section();

		$this->assertGreaterThan( 100, Blogcraft_Structure::thinnest_section( $content ) );
	}

	public function test_an_faq_is_not_a_thin_section() {
		$content = '<h2>The chemistry</h2>' . $this->long_section()
			. '<h2>Frequently asked questions</h2><h3>How long?</h3><p>Twelve hours.</p>';

		$this->assertGreaterThan( 100, Blogcraft_Structure::thinnest_section( $content ) );
	}

	public function test_content_with_no_sections_measures_nothing() {
		$this->assertSame( 0, Blogcraft_Structure::thinnest_section( '<p>Just prose.</p>' ) );
	}

	public function test_content_that_is_only_furniture_measures_nothing() {
		$this->assertSame( 0, Blogcraft_Structure::thinnest_section( '<h2>Key takeaways</h2><ul><li>One.</li></ul>' ) );
	}

	// ------------------------------------------------------------ scorecard.

	/**
	 * A blueprint the checks can read their targets from.
	 *
	 * @return array
	 */
	private function blueprint() {
		return Blogcraft_Blueprint::normalise(
			array(
				'word_target'  => 1200,
				'sections_max' => 6,
			)
		);
	}

	public function test_a_healthy_article_passes_every_structural_check() {
		$content = '<h2>The chemistry</h2>' . $this->long_section()
			. '<h2>The method</h2>' . $this->long_section();

		foreach ( Blogcraft_Structure::checks( $content, $this->blueprint() ) as $check ) {
			$this->assertTrue( (bool) $check['pass'], $check['key'] . ' failed on a healthy article' );
		}
	}

	public function test_every_failing_check_says_how_to_fix_it() {
		// A failed check with nothing to do about it is a deduction, not a
		// finding: the revise stage is handed these strings verbatim.
		$content = '<h2>A</h2><p>Short.</p><h4>B</h4><img src="x.jpg" />';

		foreach ( Blogcraft_Structure::checks( $content, $this->blueprint() ) as $check ) {
			$this->assertFalse( (bool) $check['pass'], $check['key'] . ' passed on broken markup' );
			$this->assertNotSame( '', trim( (string) $check['repair'] ), $check['key'] . ' has no repair text' );
		}
	}

	public function test_checks_arrive_in_the_shape_the_scorecard_merges() {
		$checks = Blogcraft_Structure::checks( '<h2>A</h2>' . $this->long_section(), $this->blueprint() );

		$this->assertCount( 3, $checks );

		foreach ( $checks as $check ) {
			foreach ( array( 'key', 'label', 'pass', 'actual', 'target', 'weight', 'repair' ) as $field ) {
				$this->assertArrayHasKey( $field, $check );
			}

			$this->assertGreaterThan( 0, (int) $check['weight'] );
		}
	}
}
