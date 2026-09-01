<?php
/**
 * The mistakes that pass every static check.
 *
 * Each of these is a class of error that actually shipped, got as far as a
 * screen or as far as CI, and was found by somebody looking rather than by
 * anything automatic. `php -l` passes all of them, because all of them are
 * valid PHP. This is where they fail now.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Consistency extends WP_UnitTestCase {

	/**
	 * Every PHP file in the plugin.
	 *
	 * @return array
	 */
	private function sources() {
		$out = array();

		foreach ( (array) glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $path ) {
			$out[ basename( $path ) ] = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return $out;
	}

	public function test_no_sprintf_placeholder_is_escaped_in_a_single_quoted_string() {
		// A placeholder written as %1\$s inside single quotes is a literal
		// backslash, and sprintf throws on the whole format. Inside double
		// quotes the same escape is correct and necessary, which is why this
		// strips the double-quoted strings before looking.
		//
		// Three of these have shipped. php -l passes them, PHPCS passes them,
		// and the only thing that has ever caught one is running the code.
		$bad = array();

		foreach ( $this->sources() as $name => $body ) {
			foreach ( explode( "\n", $body ) as $number => $line ) {
				if ( false === strpos( $line, '\\$' ) ) {
					continue;
				}

				$without_double_quoted = preg_replace( '/"(?:[^"\\\\]|\\\\.)*"/', '""', $line );

				if ( preg_match( '/%\d*\\\\\$[sd]/', (string) $without_double_quoted ) ) {
					$bad[] = $name . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame( array(), $bad, 'escaped placeholders in single-quoted strings: ' . implode( ', ', $bad ) );
	}

	public function test_no_component_is_styled_in_two_separate_places() {
		// bc-shape was the class of both the outline preview and the shape
		// buttons, and bc-mcp-test was defined twice by two different people
		// months apart. Whichever block comes last wins, so it looks right
		// until somebody edits the one that does not.
		//
		// Media queries are skipped: an override inside one is the point of
		// having them.
		$known = $this->known_duplicates();

		foreach ( array( 'admin.css', 'blueprint.css' ) as $sheet ) {
			$css   = (string) file_get_contents( BLOGCRAFT_PATH . 'assets/' . $sheet ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$depth = 0;
			$seen  = array();
			$dupes = array();

			foreach ( explode( "\n", $css ) as $line ) {
				$trimmed = trim( $line );

				if ( 0 === $depth && preg_match( '/^(\.[a-z][a-z0-9-]*)\s*\{$/', $trimmed, $hit ) ) {
					if ( isset( $seen[ $hit[1] ] ) ) {
						$dupes[] = $hit[1];
					}

					$seen[ $hit[1] ] = true;
				}

				$depth += substr_count( $line, '{' ) - substr_count( $line, '}' );
				$depth  = max( 0, $depth );
			}

			$new = array_values( array_diff( array_unique( $dupes ), $known ) );

			$this->assertSame(
				array(),
				$new,
				$sheet . ' styles these in two places: ' . implode( ', ', $new )
			);
		}
	}

	/**
	 * Duplicates that predate this rule.
	 *
	 * Listed rather than fixed, because untangling them needs somebody
	 * looking at the screens they style. The list may shrink and must never
	 * grow.
	 *
	 * @return array
	 */
	private function known_duplicates() {
		return array(
			'.bc-jump',
			'.blogcraft-card',
			'.blogcraft-step',
			'.blogcraft-step-text',
			'.bc-progress-meta',
			'.bc-brief-body',
			'.bc-tabs',
		);
	}

	public function test_every_class_the_stylesheets_dress_is_one_the_plugin_uses() {
		// Fifty lines of rules for bc-mcp-steps survived the markup they
		// styled by two releases. Dead CSS is not harmful, it is just a
		// growing pile of things that look load-bearing.
		$markup = implode( ' ', $this->sources() );

		foreach ( (array) glob( BLOGCRAFT_PATH . 'assets/*.js' ) as $script ) {
			$markup .= ' ' . (string) file_get_contents( $script ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		$orphans = array();

		foreach ( array( 'admin.css', 'blueprint.css' ) as $sheet ) {
			$css = (string) file_get_contents( BLOGCRAFT_PATH . 'assets/' . $sheet ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			preg_match_all( '/^\.(bc-[a-z0-9-]+)\s*\{$/m', $css, $found );

			foreach ( array_unique( $found[1] ) as $class ) {
				if ( false === strpos( $markup, $class ) ) {
					$orphans[] = $sheet . ' ' . $class;
				}
			}
		}

		$this->assertSame( array(), $orphans, 'styled but never rendered: ' . implode( ', ', $orphans ) );
	}

	public function test_nothing_still_says_a_connected_app_cannot_use_pictures() {
		// It could not, then it could, and three separate sentences went on
		// saying otherwise — the card, the documentation and the help text,
		// each found one at a time by somebody reading the screen.
		$saying = $this->sources();

		unset( $saying['class-blogcraft-mcp-tools.php'] );

		foreach ( $saying as $name => $body ) {
			preg_match_all( "/'([^']{20,400})'/", $body, $strings );

			foreach ( $strings[1] as $line ) {
				$low = strtolower( $line );

				if ( false === strpos( $low, 'cannot' ) && false === strpos( $low, 'not able' ) ) {
					continue;
				}

				$this->assertStringNotContainsString(
					'picture',
					$low,
					$name . ' still says a client cannot use pictures: "' . $line . '"'
				);
			}
		}
	}

	public function test_every_admin_page_linked_to_is_a_page_that_exists() {
		// A button to a screen nothing registers looks like the way forward
		// and is a dead end.
		$registered = array();

		foreach ( $this->sources() as $body ) {
			if ( preg_match_all( "/add_(?:menu|submenu)_page\(.*?'(blogcraft[a-z-]*)'/s", $body, $hits ) ) {
				$registered = array_merge( $registered, $hits[1] );
			}
		}

		$this->assertNotEmpty( $registered, 'no admin pages were found at all' );

		$missing = array();

		foreach ( $this->sources() as $name => $body ) {
			if ( ! preg_match_all( '/page=(blogcraft[a-z-]*)/', $body, $hits ) ) {
				continue;
			}

			foreach ( array_unique( $hits[1] ) as $slug ) {
				if ( ! in_array( $slug, $registered, true ) ) {
					$missing[] = $name . ' -> ' . $slug;
				}
			}
		}

		$this->assertSame( array(), $missing, 'links to pages that do not exist: ' . implode( ', ', $missing ) );
	}

	public function test_a_screen_that_offers_a_copy_button_loads_the_script_that_runs_it() {
		// The button renders whether or not the script is there, and does
		// nothing at all without it.
		$missing = array();

		foreach ( $this->sources() as $name => $body ) {
			if ( false === strpos( $body, 'bc-copy-button' ) && false === strpos( $body, 'data-copy=' ) ) {
				continue;
			}

			// Either it enqueues the script itself, or it hands the markup to
			// another class that does.
			if ( false !== strpos( $body, 'assets/admin.js' ) ) {
				continue;
			}

			if ( false !== strpos( $body, 'Blogcraft_Connection::copyable' ) ) {
				continue;
			}

			$missing[] = $name;
		}

		$this->assertSame( array(), $missing, 'copy buttons with no script: ' . implode( ', ', $missing ) );
	}
}
