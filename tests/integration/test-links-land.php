<?php
/**
 * Every link in the plugin lands somewhere that exists, on both paths.
 *
 * Written after a button labelled "Set them up" was found sending people to
 * Settings for a voice that had moved to How it writes, on a panel that also
 * offered to set up research on the one path where research does not apply.
 * None of that is visible in a diff: each piece was right when it was
 * written and went wrong when something else moved.
 *
 * So this walks the screens instead of the source, in both modes, and
 * checks three things about every link: the page is one the plugin
 * registers, the anchor is one that screen actually has, and the copy does
 * not name a screen the thing is no longer on.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Links_Land extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		delete_option( 'blogcraft_settings' );
		delete_option( 'blogcraft_blueprints' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		delete_option( 'blogcraft_blueprints' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The screens, and the class that draws each one.
	 *
	 * @return array
	 */
	private function screens() {
		return array(
			'blogcraft'            => 'Blogcraft_Overview',
			'blogcraft-write'      => 'Blogcraft_Generate',
			'blogcraft-blueprint'  => 'Blogcraft_Blueprint_Screen',
			'blogcraft-settings'   => 'Blogcraft_Connection',
			'blogcraft-library'    => 'Blogcraft_Library',
			'blogcraft-activity'   => 'Blogcraft_Activity',
		);
	}

	/**
	 * Draw one screen on one path.
	 *
	 * @param string $mode  Which path.
	 * @param string $class Screen class.
	 * @return string
	 */
	private function draw( $mode, $class ) {
		Blogcraft_Settings::set( 'setup_path', $mode );

		ob_start();
		call_user_func( array( $class, 'render' ) );

		return (string) ob_get_clean();
	}

	/**
	 * Every page slug the plugin will answer to.
	 *
	 * @return array
	 */
	private function known_pages() {
		$pages = array_keys( $this->screens() );

		// Registered without a menu entry, so it is reachable but not listed.
		$pages[] = Blogcraft_Docs::OLD_SLUG;
		$pages[] = 'blogcraft-calendar';

		return $pages;
	}

	public function test_every_internal_link_points_at_a_screen_that_exists() {
		foreach ( array( Blogcraft_Mode::CLIENT, Blogcraft_Mode::API ) as $mode ) {
			foreach ( $this->screens() as $slug => $class ) {
				$html = $this->draw( $mode, $class );

				preg_match_all( '/page=(blogcraft[a-z-]*)/', $html, $hits );

				foreach ( array_unique( $hits[1] ) as $target ) {
					$this->assertContains(
						$target,
						$this->known_pages(),
						$slug . ' on the ' . $mode . ' path links to "' . $target . '", which is not a screen'
					);
				}
			}
		}
	}

	public function test_every_anchor_lands_on_something_that_is_there() {
		// A link into the middle of a screen that no longer has that card
		// scrolls to the top with nothing to say it missed. The settings
		// screen shows different cards on each path, so this is checked on
		// the path the link is offered on.
		foreach ( array( Blogcraft_Mode::CLIENT, Blogcraft_Mode::API ) as $mode ) {
			$targets = array();

			foreach ( $this->screens() as $slug => $class ) {
				$html = $this->draw( $mode, $class );

				preg_match_all( '/page=(blogcraft[a-z-]*)#(bc-[a-z-]+)/', $html, $hits, PREG_SET_ORDER );

				foreach ( $hits as $hit ) {
					$targets[] = array( $slug, $hit[1], $hit[2] );
				}
			}

			foreach ( $targets as $target ) {
				list( $from, $page, $anchor ) = $target;

				$class = $this->screens();

				if ( ! isset( $class[ $page ] ) ) {
					continue;
				}

				$landing = $this->draw( $mode, $class[ $page ] );

				$this->assertStringContainsString(
					'id="' . $anchor . '"',
					$landing,
					$from . ' on the ' . $mode . ' path sends somebody to #' . $anchor
						. ' on ' . $page . ', which has no such thing there'
				);
			}
		}
	}

	public function test_nothing_tells_the_client_path_to_set_up_research() {
		// Research is the provider path's: an application connected over MCP
		// brings its own, and the settings screen carries no research card
		// here to send anybody to.
		$html = $this->draw( Blogcraft_Mode::CLIENT, 'Blogcraft_Generate' );

		$this->assertStringNotContainsString( 'Somewhere to research from', $html );
		$this->assertStringNotContainsString( 'bc-card-research', $html );
	}

	public function test_the_copy_does_not_name_a_screen_the_thing_has_left() {
		// The voice moved to its own screen and two sentences went on saying
		// it was in Settings. Wording that names a screen has to be checked
		// against where the thing actually is, and nothing else will.
		$source = '';

		foreach ( (array) glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $path ) {
			$source .= (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		$this->assertStringNotContainsString(
			'A button in Settings fills it in',
			$source,
			'the Learn button is on How it writes, not Settings'
		);
	}

	public function test_the_before_you_write_panel_sends_each_thing_to_its_own_screen() {
		// One button for two destinations is right for at most one of them.
		$html = $this->draw( Blogcraft_Mode::CLIENT, 'Blogcraft_Generate' );

		if ( false === strpos( $html, 'A described voice and reader' ) ) {
			$this->markTestSkipped( 'the voice is described, so the panel is not shown' );
		}

		$this->assertStringContainsString(
			'page=blogcraft-blueprint',
			$html,
			'the voice is set on How it writes and the panel does not go there'
		);
	}
}
