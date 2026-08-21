<?php
/**
 * In-text internal linking tests.
 *
 * The standing complaint about automated internal linking is that it links the
 * wrong words to the wrong page, so most of these assert restraint rather than
 * coverage: what it refuses to touch matters more than what it links.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Internal_Links extends WP_UnitTestCase {

	/**
	 * One paragraph block.
	 *
	 * @param string $text Paragraph text.
	 * @return string
	 */
	private function para( $text ) {
		return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->\n\n";
	}

	/**
	 * Two posts to link to.
	 *
	 * @return array
	 */
	private function related() {
		return array(
			array(
				'id'    => 7,
				'title' => 'How to choose a standing desk',
				'url'   => 'https://example.com/desks',
			),
			array(
				'id'    => 9,
				'title' => 'Monitor arms that actually hold',
				'url'   => 'https://example.com/arms',
			),
		);
	}

	// -------------------------------------------------------------- phrases.

	public function test_the_whole_title_is_tried_first() {
		$phrases = Blogcraft_Seo::anchor_phrases( 'How to choose a standing desk' );

		$this->assertSame( 'How to choose a standing desk', $phrases[0] );
	}

	public function test_leading_question_words_are_dropped_in_turn() {
		// "How to choose a standing desk" almost never appears verbatim in
		// prose; "choose a standing desk" often does.
		$phrases = Blogcraft_Seo::anchor_phrases( 'How to choose a standing desk' );

		$this->assertContains( 'choose a standing desk', $phrases );
	}

	public function test_it_stops_dropping_at_the_first_real_word() {
		$phrases = Blogcraft_Seo::anchor_phrases( 'How to choose a standing desk' );

		$this->assertNotContains( 'standing desk', $phrases );
	}

	public function test_a_subtitle_is_discarded() {
		$phrases = Blogcraft_Seo::anchor_phrases( 'Standing desks explained - our 2026 guide' );

		$this->assertSame( 'Standing desks explained', $phrases[0] );
	}

	public function test_a_title_too_short_to_use_yields_nothing() {
		$this->assertSame( array(), Blogcraft_Seo::anchor_phrases( 'Desks' ) );
		$this->assertSame( array(), Blogcraft_Seo::anchor_phrases( 'Buy now' ) );
	}

	// ---------------------------------------------------------------- links.

	public function test_a_matching_phrase_becomes_a_link() {
		$content = $this->para( 'When you choose a standing desk the height range matters most.' );
		$out     = Blogcraft_Seo::link_in_text( $content, $this->related(), 3 );

		$this->assertSame( array( 7 ), $out['linked'] );
		$this->assertStringContainsString(
			'<a href="https://example.com/desks">choose a standing desk</a>',
			$out['content']
		);
	}

	public function test_a_heading_is_never_turned_into_a_link() {
		$content = "<!-- wp:heading -->\n<h2>How to choose a standing desk</h2>\n<!-- /wp:heading -->\n\n"
			. $this->para( 'Body copy about monitor arms that actually hold up.' );

		$out = Blogcraft_Seo::link_in_text( $content, $this->related(), 3 );

		$this->assertStringContainsString( '<h2>How to choose a standing desk</h2>', $out['content'] );
	}

	public function test_a_paragraph_that_already_links_is_left_alone() {
		$content = $this->para( 'See <a href="https://elsewhere.test">this</a> on how to choose a standing desk.' );
		$out     = Blogcraft_Seo::link_in_text( $content, $this->related(), 3 );

		$this->assertSame( array(), $out['linked'] );
		$this->assertSame( $content, $out['content'] );
	}

	public function test_one_target_is_linked_once_not_in_every_paragraph() {
		$content = $this->para( 'You choose a standing desk once.' )
			. $this->para( 'Then you choose a standing desk again.' );

		$out = Blogcraft_Seo::link_in_text( $content, $this->related(), 3 );

		$this->assertCount( 1, $out['linked'] );
		$this->assertSame( 1, substr_count( $out['content'], '<a href' ) );
	}

	public function test_a_phrase_inside_a_longer_word_is_not_matched() {
		$content = $this->para( 'The standing deskbound life is hard.' );

		$this->assertSame( array(), Blogcraft_Seo::link_in_text( $content, $this->related(), 3 )['linked'] );
	}

	public function test_matching_ignores_case_but_the_anchor_keeps_it() {
		$content = $this->para( 'You should Choose A Standing Desk carefully.' );
		$out     = Blogcraft_Seo::link_in_text( $content, $this->related(), 3 );

		$this->assertCount( 1, $out['linked'] );
		$this->assertStringContainsString( '>Choose A Standing Desk</a>', $out['content'] );
	}

	public function test_the_paragraph_block_survives_intact() {
		// An anchor inserted into the opening block comment would corrupt the
		// block without any visible error.
		$content = $this->para( 'You should choose a standing desk carefully.' );
		$out     = Blogcraft_Seo::link_in_text( $content, $this->related(), 3 );

		$this->assertSame( 1, substr_count( $out['content'], '<!-- wp:paragraph -->' ) );
		$this->assertSame( 1, substr_count( $out['content'], '<!-- /wp:paragraph -->' ) );
	}

	// ------------------------------------------------------------- restraint.

	public function test_nothing_to_match_means_nothing_linked() {
		$content = $this->para( 'A paragraph about something else entirely.' );

		$this->assertSame( array(), Blogcraft_Seo::link_in_text( $content, $this->related(), 3 )['linked'] );
	}

	public function test_no_related_posts_means_nothing_linked() {
		$content = $this->para( 'You choose a standing desk once.' );

		$this->assertSame( array(), Blogcraft_Seo::link_in_text( $content, array(), 3 )['linked'] );
	}

	public function test_a_limit_of_zero_adds_nothing() {
		$content = $this->para( 'You choose a standing desk once.' );

		$this->assertSame( array(), Blogcraft_Seo::link_in_text( $content, $this->related(), 0 )['linked'] );
	}

	public function test_content_with_no_paragraphs_comes_back_untouched() {
		$content = '<h2>Only a heading</h2>';

		$this->assertSame( $content, Blogcraft_Seo::link_in_text( $content, $this->related(), 3 )['content'] );
	}
}
