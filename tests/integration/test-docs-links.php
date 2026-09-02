<?php
/**
 * The documentation lives elsewhere now, so the links to it have to behave.
 *
 * Three faults found by walking a real install after the move: links that
 * took somebody off their own admin in the same tab, losing whatever they
 * were filling in; the old Help address answering with WordPress's
 * permissions error, which blames the reader for a page that moved; and a
 * section switcher that said which item was current without ever saying it
 * controlled anything.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Docs_Links extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		delete_option( 'blogcraft_settings' );
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Render one screen.
	 *
	 * @param string $screen Class to render.
	 * @return string
	 */
	private function screen( $screen ) {
		ob_start();
		call_user_func( array( $screen, 'render' ) );

		return (string) ob_get_clean();
	}

	/**
	 * Every anchor in some markup that points at the documentation.
	 *
	 * @param string $html Rendered screen.
	 * @return array Whole tags.
	 */
	private function docs_links( $html ) {
		$found = array();

		preg_match_all( '/<a\b[^>]*>/i', $html, $tags );

		foreach ( $tags[0] as $tag ) {
			if ( false !== strpos( $tag, 'dicecodes.com/ai-blog-writer' ) ) {
				$found[] = $tag;
			}
		}

		return $found;
	}

	public function test_a_link_that_leaves_wordpress_opens_a_new_tab() {
		// It is read while setting something up, and the half-filled form
		// behind it is the thing being come back to.
		foreach ( array( 'Blogcraft_Overview', 'Blogcraft_Generate', 'Blogcraft_Connection' ) as $screen ) {
			$links = $this->docs_links( $this->screen( $screen ) );

			$this->assertNotEmpty( $links, $screen . ' offers no way to the documentation' );

			foreach ( $links as $tag ) {
				$this->assertStringContainsString(
					'target="_blank"',
					$tag,
					$screen . ' sends somebody off their admin in the same tab: ' . $tag
				);

				$this->assertStringContainsString(
					'rel="noopener noreferrer"',
					$tag,
					$screen . ' opens a new tab without disowning it: ' . $tag
				);
			}
		}
	}

	public function test_a_link_that_stays_in_wordpress_does_not() {
		// The other half of the rule. Every admin link opening a tab is its
		// own kind of broken, and leaves() decides from the address.
		$this->assertFalse( Blogcraft_Docs::leaves( admin_url( 'admin.php?page=blogcraft' ) ) );
		$this->assertFalse( Blogcraft_Docs::leaves( '' ) );
		$this->assertTrue( Blogcraft_Docs::leaves( Blogcraft_Docs::site_url() ) );
	}

	public function test_the_old_help_address_says_where_the_documentation_went() {
		// It is in bookmarks and in browser history. Unregistered, WordPress
		// answers it with "you are not allowed to access this page", which is
		// its message for a capability failure and reads as an account fault.
		ob_start();
		Blogcraft_Docs::render_moved();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'moved', $html );
		$this->assertStringContainsString( Blogcraft_Docs::HOME, $html );
		$this->assertStringNotContainsString( 'not allowed', $html );
	}

	public function test_the_old_help_address_is_registered_but_not_in_the_menu() {
		// Reachable by address, absent from the navigation.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-docs.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( 'add_submenu_page', $source );
		$this->assertStringContainsString( 'remove_submenu_page', $source );

		ob_start();
		Blogcraft_Nav::render();
		$nav = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'page=blogcraft-help', $nav, 'the old screen is back in the navigation' );
	}

	public function test_the_section_switcher_says_it_controls_something() {
		// aria-current said which one was current. Nothing said the buttons
		// controlled panels, and switching pane changes which fields exist.
		ob_start();
		Blogcraft_Blueprint_Screen::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'role="tablist"', $html );
		$this->assertStringContainsString( 'role="tab"', $html );
		$this->assertStringContainsString( 'role="tabpanel"', $html );

		// Each tab names the panel it opens, and each panel names its tab.
		preg_match_all( '/aria-controls="(bc-pane-[a-z-]+)"/', $html, $controls );

		$this->assertNotEmpty( $controls[1], 'no tab points at a panel' );

		foreach ( array_unique( $controls[1] ) as $panel ) {
			$this->assertStringContainsString(
				'id="' . $panel . '"',
				$html,
				'a tab opens "' . $panel . '", which is not on the page'
			);
		}
	}

	public function test_exactly_one_section_starts_selected() {
		ob_start();
		Blogcraft_Blueprint_Screen::render();
		$html = (string) ob_get_clean();

		$this->assertSame(
			1,
			substr_count( $html, 'aria-selected="true"' ),
			'the switcher opens with something other than one section selected'
		);
	}
}
