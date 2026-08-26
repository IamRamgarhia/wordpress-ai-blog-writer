<?php
/**
 * Whether the screens are actually wearing the design they were given.
 *
 * Two failures of this kind shipped, and neither was visible to any test: the
 * colour palette was declared for one wrapper class while three screens used
 * another, and the two screens people spend most of their time on never
 * loaded the base stylesheet at all — so the shared navigation had no
 * background, no border and no current-tab highlight on exactly the pages it
 * mattered on.
 *
 * Both are one-line mistakes that no amount of reading the CSS would catch,
 * because the CSS was right. What was wrong was which file arrived where.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Styling extends WP_UnitTestCase {

	/**
	 * The stylesheets, read once.
	 *
	 * @var array
	 */
	private static $css = array();

	public static function set_up_before_class() {
		parent::set_up_before_class();

		foreach ( array( 'admin', 'blueprint' ) as $name ) {
			self::$css[ $name ] = (string) file_get_contents( BLOGCRAFT_PATH . 'assets/' . $name . '.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
	}

	/**
	 * Every custom property this stylesheet declares.
	 *
	 * @param string $name Stylesheet.
	 * @return array
	 */
	private function declared_in( $name ) {
		preg_match_all( '/^\s*(--[a-z0-9-]+)\s*:/mi', self::$css[ $name ], $found );

		return array_unique( $found[1] );
	}

	/**
	 * Every custom property this stylesheet reads.
	 *
	 * @param string $name Stylesheet.
	 * @return array
	 */
	private function used_in( $name ) {
		preg_match_all( '/var\(\s*(--[a-z0-9-]+)/i', self::$css[ $name ], $found );

		return array_unique( $found[1] );
	}

	public function test_no_colour_is_read_without_being_declared_somewhere() {
		// --bc-surface-soft and --bc-ink-soft were read in a dozen rules and
		// declared nowhere. They only looked fine because every use carried a
		// fallback, so the token was doing nothing and the fallback was the
		// real value — in several slightly different shades.
		$declared = array_merge( $this->declared_in( 'admin' ), $this->declared_in( 'blueprint' ) );
		$used     = array_merge( $this->used_in( 'admin' ), $this->used_in( 'blueprint' ) );

		foreach ( $used as $token ) {
			$this->assertContains( $token, $declared, $token . ' is used but never declared' );
		}
	}

	public function test_the_palette_covers_every_wrapper_the_screens_use() {
		// The tokens were declared for .blogcraft-page only, and the progress
		// screen, the library and the welcome screen use .blogcraft-wrap. On
		// those three every var() fell through to nothing, which is why the
		// navigation lost its background and cards lost their edges.
		$wrappers = array();

		foreach ( glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( preg_match_all( '/class="wrap (blogcraft-[a-z-]+)/', $source, $found ) ) {
				$wrappers = array_merge( $wrappers, $found[1] );
			}
		}

		$wrappers = array_unique( $wrappers );

		$this->assertNotEmpty( $wrappers );

		// The block that declares the palette, whichever selectors it carries.
		$block = strstr( self::$css['admin'], '--bc-ink:', true );

		foreach ( $wrappers as $wrapper ) {
			$this->assertStringContainsString(
				'.' . $wrapper,
				(string) $block,
				$wrapper . ' has no colours, so every screen using it renders unstyled'
			);
		}
	}

	public function test_every_screen_that_draws_the_navigation_loads_the_file_that_styles_it() {
		// .bc-nav lives in admin.css. A screen that renders the navigation and
		// enqueues only its own stylesheet shows the nav as bare text links.
		$this->assertStringContainsString( '.bc-nav', self::$css['admin'] );

		foreach ( glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( false === strpos( $source, 'Blogcraft_Nav::render()' ) ) {
				continue;
			}

			// Screens with no enqueue of their own inherit nothing to check.
			if ( false === strpos( $source, 'wp_enqueue_style' ) ) {
				continue;
			}

			$this->assertStringContainsString(
				'assets/admin.css',
				$source,
				basename( $file ) . ' draws the navigation but never loads the stylesheet that paints it'
			);
		}
	}

	public function test_there_is_one_accent_colour_rather_than_two() {
		// There were two: a blue in admin.css and an indigo in blueprint.css,
		// for the same role. The navigation and the card directly beneath it
		// were different colours on the same screen.
		preg_match( '/--bc-accent:\s*([^;]+);/', self::$css['admin'], $base );
		preg_match( '/--bp-accent:\s*([^;]+);/', self::$css['blueprint'], $other );

		$this->assertNotEmpty( $base );
		$this->assertNotEmpty( $other );
		$this->assertStringContainsString( 'var(--bc-accent)', trim( $other[1] ) );
	}

	public function test_the_corners_come_from_a_scale_rather_than_being_guessed() {
		// Sixteen distinct radii between three and eighteen pixels is not a
		// hierarchy, it is noise, and it is why nothing on these screens looked
		// related to anything else.
		$loose = 0;

		foreach ( self::$css as $sheet ) {
			preg_match_all( '/border(?:-[a-z]+)*-radius:\s*([^;]+);/i', $sheet, $found );

			foreach ( $found[1] as $value ) {
				if ( false !== strpos( $value, '%' ) || false !== strpos( $value, 'var(' ) ) {
					continue;
				}

				++$loose;
			}
		}

		$this->assertSame( 0, $loose, $loose . ' corner radii are hand-written rather than taken from the scale' );
	}
}
