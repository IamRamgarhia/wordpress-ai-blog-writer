<?php
/**
 * Edited HTML has to come back as blocks the editor accepts.
 *
 * The draft is edited in the ordinary WordPress editor, which returns plain
 * HTML with no block delimiters. Saved as-is into a post on a block-editor
 * site, that becomes one enormous Classic block: Gutenberg reports unexpected
 * content, nothing is individually editable, and the structure the scorer
 * measured is invisible to the editor.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Blocks_Roundtrip extends WP_UnitTestCase {

	// ------------------------------------------------------- the shapes.

	public function test_paragraphs_and_headings_become_their_own_blocks() {
		$blocks = Blogcraft_Blocks::from_html( '<p>An opening line.</p><h2>A heading</h2><p>And a second.</p>' );

		$this->assertSame( 3, substr_count( $blocks, '<!-- wp:' ), 'three elements should be three blocks' );
		$this->assertSame( 2, substr_count( $blocks, '<!-- wp:paragraph -->' ) );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $blocks );
	}

	public function test_a_list_gets_list_item_blocks_inside_it() {
		// A wp:list containing bare <li> is exactly what makes the block
		// editor report unexpected content.
		$blocks = Blogcraft_Blocks::from_html( '<ul><li>one</li><li>two</li></ul>' );

		$this->assertStringContainsString( '<!-- wp:list -->', $blocks );
		$this->assertSame( 2, substr_count( $blocks, '<!-- wp:list-item -->' ) );
		$this->assertSame( 2, substr_count( $blocks, '<!-- /wp:list-item -->' ) );
	}

	public function test_an_ordered_list_says_so_in_its_attributes() {
		$blocks = Blogcraft_Blocks::from_html( '<ol><li>first</li></ol>' );

		$this->assertStringContainsString( '<!-- wp:list {"ordered":true} -->', $blocks );
		$this->assertStringContainsString( '<ol', $blocks );
	}

	public function test_an_inserted_image_survives_as_an_image_block() {
		// What Add Media puts in the editor.
		$html = '<p>Before.</p><figure class="wp-block-image"><img src="https://example.com/a.jpg" alt="A photo" /></figure><p>After.</p>';

		$blocks = Blogcraft_Blocks::from_html( $html );

		$this->assertStringContainsString( '<!-- wp:image -->', $blocks );
		$this->assertStringContainsString( 'https://example.com/a.jpg', $blocks );
		$this->assertStringContainsString( 'alt="A photo"', $blocks );
	}

	public function test_a_bare_image_is_wrapped_in_a_figure() {
		$blocks = Blogcraft_Blocks::from_html( '<img src="https://example.com/b.jpg" alt="B" />' );

		$this->assertStringContainsString( '<!-- wp:image -->', $blocks );
		$this->assertStringContainsString( 'wp-block-image', $blocks );
	}

	public function test_a_table_keeps_the_figure_wrapper_the_block_expects() {
		$blocks = Blogcraft_Blocks::from_html( '<table><tbody><tr><td>a</td></tr></tbody></table>' );

		$this->assertStringContainsString( '<!-- wp:table -->', $blocks );
		$this->assertStringContainsString( 'wp-block-table', $blocks );
	}

	// ------------------------------------------------------- not losing text.

	public function test_loose_text_is_kept_rather_than_dropped() {
		// Somebody typing straight into the editor without a wrapping tag.
		$blocks = Blogcraft_Blocks::from_html( 'A sentence with no tag around it.' );

		$this->assertStringContainsString( 'A sentence with no tag around it.', $blocks );
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $blocks );
	}

	public function test_inline_formatting_stays_inside_its_paragraph() {
		$blocks = Blogcraft_Blocks::from_html( '<p>Some <strong>bold</strong> and a <a href="https://example.com">link</a>.</p>' );

		$this->assertSame( 1, substr_count( $blocks, '<!-- wp:paragraph -->' ) );
		$this->assertStringContainsString( '<strong>bold</strong>', $blocks );
		$this->assertStringContainsString( 'href="https://example.com"', $blocks );
	}

	public function test_an_unknown_tag_keeps_its_words() {
		// Losing somebody's writing to a tag this has not met would be a far
		// worse failure than an over-plain block.
		$blocks = Blogcraft_Blocks::from_html( '<section>Words that must survive.</section>' );

		$this->assertStringContainsString( 'Words that must survive.', $blocks );
	}

	public function test_block_markup_is_left_alone() {
		// Nothing was edited, so nothing should be rebuilt.
		$original = "<!-- wp:paragraph -->\n<p>Already blocks.</p>\n<!-- /wp:paragraph -->";

		$this->assertSame( $original, Blogcraft_Blocks::from_html( $original ) );
	}

	public function test_empty_input_produces_nothing() {
		$this->assertSame( '', Blogcraft_Blocks::from_html( '' ) );
		$this->assertSame( '', Blogcraft_Blocks::from_html( '   ' ) );
	}

	// ---------------------------------------------- every delimiter closes.

	public function test_every_opened_block_is_closed() {
		// An unbalanced delimiter is the single most direct route to Gutenberg
		// refusing to parse a post.
		$html = '<p>One.</p><h2>Two</h2><ul><li>a</li><li>b</li></ul>'
			. '<blockquote><p>Quoted.</p></blockquote>'
			. '<figure class="wp-block-image"><img src="https://example.com/c.jpg" alt="C" /></figure>'
			. '<table><tbody><tr><td>x</td></tr></tbody></table><p>End.</p>';

		$blocks = Blogcraft_Blocks::from_html( $html );

		preg_match_all( '/<!-- wp:([a-z-]+)/', $blocks, $opens );
		preg_match_all( '#<!-- /wp:([a-z-]+)#', $blocks, $closes );

		sort( $opens[1] );
		sort( $closes[1] );

		$this->assertSame( $opens[1], $closes[1], 'a block was opened and not closed' );
	}

	// ------------------------------------------------------- markdown leak.

	public function test_markdown_bold_becomes_real_markup() {
		// Every drafting prompt forbids markdown and models emit it anyway,
		// so it reached the page as literal asterisks.
		$html = Blogcraft_Blocks::paragraph( 'AI drafts, but **human insight** shapes it.' );

		$this->assertStringContainsString( '<strong>human insight</strong>', $html );
		$this->assertStringNotContainsString( '**', $html );
	}

	public function test_arithmetic_is_not_mistaken_for_emphasis() {
		$html = Blogcraft_Blocks::paragraph( 'Multiply 3 * 4 * 5 to get the answer.' );

		$this->assertStringContainsString( '3 * 4 * 5', $html );
		$this->assertStringNotContainsString( '<em>', $html );
	}
}
