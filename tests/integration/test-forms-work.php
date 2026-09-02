<?php
/**
 * Every control on a screen belongs to a form that will carry it.
 *
 * The settings screen wrapped everything in one form and then drew nine more
 * inside it — the mode switch, issuing a token, disconnecting an app, testing
 * a key, saving a provider. HTML has no nested form: the browser drops the
 * inner opening tag and lets the inner closing tag end the outer one. So the
 * settings form finished at the first of them, seventy-odd controls after it
 * belonged to nothing, and the only fields left inside were the mode
 * switch's — which meant pressing "Save settings" switched how the site
 * writes and saved nothing.
 *
 * It rendered perfectly and read correctly. Only submitting it showed
 * anything wrong, which is why this asks the question of the markup.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Forms_Work extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Mcp_Auth::OPTION );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Mcp_Auth::OPTION );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Render one screen on one path.
	 *
	 * @param string $mode   Which path, or '' for not yet chosen.
	 * @param string $screen Class to render.
	 * @return string
	 */
	private function screen( $mode, $screen ) {
		if ( '' === $mode ) {
			delete_option( 'blogcraft_settings' );
		} else {
			Blogcraft_Settings::set( 'setup_path', $mode );
		}

		ob_start();
		call_user_func( array( $screen, 'render' ) );

		return (string) ob_get_clean();
	}

	/**
	 * Whether any form opens while another is still open.
	 *
	 * @param string $html Rendered screen.
	 * @return int How many forms were opened inside another.
	 */
	private function nested_forms( $html ) {
		$depth = 0;
		$worst = 0;

		preg_match_all( '/<form\b|<\/form>/i', $html, $tags, PREG_OFFSET_CAPTURE );

		foreach ( $tags[0] as $tag ) {
			if ( '<' === $tag[0][1] || 0 === strpos( strtolower( $tag[0] ), '<form' ) ) {
				// Opening tag.
				if ( 0 !== strpos( strtolower( $tag[0] ), '</' ) ) {
					++$depth;

					if ( $depth > 1 ) {
						++$worst;
					}

					continue;
				}
			}

			$depth = max( 0, $depth - 1 );
		}

		return $worst;
	}

	public function test_no_screen_puts_a_form_inside_another_form() {
		$screens = array(
			'Blogcraft_Connection' => array( '', Blogcraft_Mode::API, Blogcraft_Mode::CLIENT ),
			'Blogcraft_Generate'   => array( Blogcraft_Mode::API, Blogcraft_Mode::CLIENT ),
			'Blogcraft_Overview'   => array( Blogcraft_Mode::API, Blogcraft_Mode::CLIENT ),
			'Blogcraft_Activity'   => array( Blogcraft_Mode::API, Blogcraft_Mode::CLIENT ),
		);

		foreach ( $screens as $screen => $modes ) {
			foreach ( $modes as $mode ) {
				$this->assertSame(
					0,
					$this->nested_forms( $this->screen( $mode, $screen ) ),
					$screen . ' on "' . $mode . '" opens a form inside another, which ends the outer one'
				);
			}
		}
	}

	public function test_every_setting_belongs_to_the_form_that_saves_it() {
		foreach ( array( '', Blogcraft_Mode::API, Blogcraft_Mode::CLIENT ) as $mode ) {
			$html = $this->screen( $mode, 'Blogcraft_Connection' );

			// Every control that carries a name, minus the ones inside one of
			// the screen's other forms, has to name the settings form.
			$outside = self::strip_forms( $html );

			preg_match_all( '/<(?:input|select|textarea)\b[^>]*\bname="([a-z_]+)"[^>]*>/i', $outside, $hits, PREG_SET_ORDER );

			$this->assertNotEmpty( $hits, 'no settings controls found on "' . $mode . '"' );

			foreach ( $hits as $hit ) {
				$this->assertStringContainsString(
					'form="' . Blogcraft_Connection::FORM_ID . '"',
					$hit[0],
					'"' . $hit[1] . '" on "' . $mode . '" is in no form, so saving cannot carry it'
				);
			}
		}
	}

	public function test_the_mode_switch_is_not_swept_into_the_settings_form() {
		// The one that did the damage. If the switch's hidden field is
		// claimed by the settings form, pressing Save changes how the site
		// writes.
		foreach ( array( Blogcraft_Mode::API, Blogcraft_Mode::CLIENT ) as $mode ) {
			$outside = self::strip_forms( $this->screen( $mode, 'Blogcraft_Connection' ) );

			$this->assertStringNotContainsString(
				'name="path"',
				$outside,
				'the mode switch field is loose on "' . $mode . '", so Save would switch the mode'
			);
		}
	}

	public function test_the_save_button_names_the_form_it_submits() {
		$html = $this->screen( Blogcraft_Mode::CLIENT, 'Blogcraft_Connection' );

		$this->assertStringContainsString( 'form="' . Blogcraft_Connection::FORM_ID . '"', $html );
		$this->assertStringContainsString( 'id="' . Blogcraft_Connection::FORM_ID . '"', $html );
	}

	/**
	 * The markup with every form and its contents removed.
	 *
	 * What is left is everything that has to name its form for itself.
	 *
	 * @param string $html Rendered screen.
	 * @return string
	 */
	private static function strip_forms( $html ) {
		return (string) preg_replace( '/<form\b.*?<\/form>/is', '', $html );
	}
}
