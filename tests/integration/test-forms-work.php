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
	 * Give the listing screens something to list.
	 *
	 * An empty Library draws no rows, and the form this is looking for is
	 * drawn once per row — so checking the screens without seeding them
	 * checks the sentence that says there is nothing here yet.
	 *
	 * @return void
	 */
	private function seed_rows() {
		foreach ( array( 'publish', 'draft', 'pending' ) as $status ) {
			$post_id = self::factory()->post->create(
				array(
					'post_status' => $status,
					'post_title'  => 'Seeded ' . $status,
				)
			);

			update_post_meta( $post_id, Blogcraft_Seo::GENERATED_META, 1 );
			update_post_meta( $post_id, '_blogcraft_generated', 1 );
			update_post_meta( $post_id, '_blogcraft_quality', 62 );
		}

		Blogcraft_Queue::enqueue( 'generate', 'writing', array( 'topic' => 'seeded' ) );

		// pending_posts() caches, and the cache was filled while empty.
		wp_cache_flush();
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

		preg_match_all( '#</?form\b#i', $html, $tags );

		foreach ( $tags[0] as $tag ) {
			if ( '/' === $tag[1] ) {
				$depth = max( 0, $depth - 1 );

				continue;
			}

			++$depth;

			if ( $depth > 1 ) {
				++$worst;
			}
		}

		return $worst;
	}

	/**
	 * Every screen that draws a form, and the paths it draws one on.
	 *
	 * The first four were the ones checked when this was written, because
	 * they were the ones the bug was found on. That is the wrong reason to
	 * stop: the fault was a screen calling a helper that opens a form of its
	 * own, and any screen here can do that.
	 *
	 * @return array
	 */
	private function screens_with_forms() {
		$both = array( Blogcraft_Mode::API, Blogcraft_Mode::CLIENT );

		return array(
			'Blogcraft_Connection'       => array( '', Blogcraft_Mode::API, Blogcraft_Mode::CLIENT ),
			'Blogcraft_Generate'         => $both,
			'Blogcraft_Overview'         => $both,
			'Blogcraft_Activity'         => $both,
			'Blogcraft_Blueprint_Screen' => $both,
			'Blogcraft_Library'          => $both,
			'Blogcraft_Calendar'         => $both,
			'Blogcraft_Progress'         => $both,
			'Blogcraft_Review'           => $both,
			'Blogcraft_Welcome'          => $both,
		);
	}

	public function test_no_screen_puts_a_form_inside_another_form() {
		$this->seed_rows();

		foreach ( $this->screens_with_forms() as $screen => $modes ) {
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

			preg_match_all( '/<(?:input|select|textarea)\b[^>]*\bname="([^"]+)"[^>]*>/i', $outside, $hits, PREG_SET_ORDER );

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

	public function test_no_screen_leaves_a_control_belonging_to_nothing() {
		$this->seed_rows();

		// The settings screen is checked field by field above, against the
		// one form that saves it. This asks the weaker question of every
		// other screen: a control that is inside no form and names no form
		// is not submitted by anything, whatever it looks like.
		foreach ( $this->screens_with_forms() as $screen => $modes ) {
			foreach ( $modes as $mode ) {
				$outside = self::strip_forms( $this->screen( $mode, $screen ) );

				preg_match_all(
					'/<(?:input|select|textarea|button)\b[^>]*\bname="([^"]+)"[^>]*>/i',
					$outside,
					$hits,
					PREG_SET_ORDER
				);

				foreach ( $hits as $hit ) {
					$this->assertMatchesRegularExpression(
						'/\bform="[^"]+"/',
						$hit[0],
						'"' . $hit[1] . '" on ' . $screen . ' "' . $mode . '" is in no form and names none, so nothing submits it'
					);
				}
			}
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
