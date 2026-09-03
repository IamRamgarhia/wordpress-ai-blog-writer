<?php
/**
 * What a connected app sends becomes a post, so it is narrowed first.
 *
 * Blogcraft_Blocks::from_html() returns anything already carrying a block
 * delimiter exactly as it arrived. That is right for markup this plugin
 * wrote and round-trips — and it meant a body from an app that merely
 * began with "<!-- wp:" was written into post_content whole, script tags
 * and all, because the early return happens before any narrowing.
 *
 * The account behind an MCP token belongs to somebody who can already
 * publish, so this is not a way in that was otherwise shut. It matters
 * because of where the words come from: a language model, prompted with
 * pages this plugin fetched off the open web. Trusting the connection is
 * not the same as trusting the text that arrives over it, and the screen
 * that renders it is every reader's.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Client_Content_Is_Narrowed extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user );
	}

	public function tear_down() {
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The content a draft ends up holding, given a body from an app.
	 *
	 * Goes through the same helper the tool uses rather than the tool
	 * itself, so the assertion is about the narrowing and not about the
	 * MCP envelope around it.
	 *
	 * @param string $html What the app sent.
	 * @return string
	 */
	private function stored( $html ) {
		return Blogcraft_Blocks::from_html( wp_kses_post( $html ) );
	}

	public function test_a_script_hidden_behind_a_block_delimiter_does_not_survive() {
		// The delimiter is the whole trick: without it the body is parsed
		// and rebuilt, and the script never had a route through.
		$sent = "<!-- wp:paragraph -->\n<p>Ordinary opening.</p>\n<!-- /wp:paragraph -->"
			. '<script>alert(document.cookie)</script>';

		$out = $this->stored( $sent );

		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( 'alert(', $out );
		$this->assertStringContainsString( 'Ordinary opening.', $out );
	}

	public function test_an_event_attribute_does_not_survive() {
		$out = $this->stored( '<!-- wp:paragraph --><p onclick="steal()">Text.</p><!-- /wp:paragraph -->' );

		$this->assertStringNotContainsString( 'onclick', $out );
		$this->assertStringContainsString( 'Text.', $out );
	}

	public function test_a_javascript_link_does_not_survive() {
		$out = $this->stored( '<!-- wp:paragraph --><p><a href="javascript:alert(1)">click</a></p><!-- /wp:paragraph -->' );

		$this->assertStringNotContainsString( 'javascript:', $out );
	}

	public function test_an_iframe_does_not_survive() {
		$out = $this->stored( '<!-- wp:html --><iframe src="https://example.com/x"></iframe><!-- /wp:html -->' );

		$this->assertStringNotContainsString( '<iframe', $out );
	}

	public function test_ordinary_writing_still_comes_through_intact() {
		// The narrowing has to leave a normal post alone, or it has traded
		// one fault for a worse one.
		$out = $this->stored( '<h2>A heading</h2><p>Some <strong>bold</strong> words and a <a href="https://example.com">link</a>.</p>' );

		$this->assertStringContainsString( 'A heading', $out );
		$this->assertStringContainsString( '<strong>bold</strong>', $out );
		$this->assertStringContainsString( 'https://example.com', $out );
		$this->assertStringContainsString( '<!-- wp:', $out );
	}

	public function test_the_tool_itself_narrows_what_it_is_given() {
		// The helper above proves the narrowing; this proves the tool uses
		// it, which is the part that regressed.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-mcp-tools.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertSame(
			0,
			preg_match_all( '/from_html\(\s*\$html\s*\)/', $source ),
			'create_draft hands the app\'s body straight to from_html()'
		);

		$this->assertSame(
			0,
			preg_match_all( '/from_html\(\s*\(string\) \$args\[\'html\'\]\s*\)/', $source ),
			'update_draft hands the app\'s body straight to from_html()'
		);

		$this->assertSame(
			2,
			preg_match_all( '/from_html\(\s*wp_kses_post\(/', $source ),
			'both places an app supplies a body should narrow it first'
		);
	}
}
