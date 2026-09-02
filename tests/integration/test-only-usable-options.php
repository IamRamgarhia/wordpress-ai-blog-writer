<?php
/**
 * Only what works on this setup should be on the screen.
 *
 * A control that cannot do anything is worse than a missing one: it gets
 * tried, and the failure reads as the plugin being broken rather than as the
 * control not applying here. Three of them had accumulated on the write
 * screen alone — asking the provider what to write when there is no provider,
 * choosing whether to publish when nothing here publishes, and estimating a
 * bill nobody receives.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Only_Usable_Options extends WP_UnitTestCase {

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
	 * @param string $mode   Which path.
	 * @param string $screen Class to render.
	 * @return string
	 */
	private function screen( $mode, $screen ) {
		Blogcraft_Settings::set( 'setup_path', $mode );

		ob_start();
		call_user_func( array( $screen, 'render' ) );

		return (string) ob_get_clean();
	}

	/**
	 * Controls that only work when this site calls a provider itself.
	 *
	 * Each is a marker in the markup and the reason it cannot work on the
	 * other path, so a failure says which control and why.
	 *
	 * @return array
	 */
	private function provider_only() {
		return array(
			'blogcraft-suggest' => 'asking what to write calls the provider, and there is none',
			'name="status"'     => 'nothing on this path publishes, so the choice is never read',
			'Tokens, roughly'   => 'an estimate of a bill that goes to a subscription, not to you',
		);
	}

	public function test_the_write_screen_offers_nothing_the_client_path_cannot_use() {
		$html = $this->screen( Blogcraft_Mode::CLIENT, 'Blogcraft_Generate' );

		foreach ( $this->provider_only() as $marker => $why ) {
			$this->assertStringNotContainsString(
				$marker,
				$html,
				'"' . $marker . '" is on the client path, where ' . $why
			);
		}
	}

	public function test_the_provider_path_still_has_all_of_them() {
		// The other half of the rule. Hiding a control on the path it belongs
		// to is the same fault the other way round, and a gate written the
		// wrong way round passes the test above perfectly.
		$html = $this->screen( Blogcraft_Mode::API, 'Blogcraft_Generate' );

		foreach ( array_keys( $this->provider_only() ) as $marker ) {
			$this->assertStringContainsString(
				$marker,
				$html,
				'"' . $marker . '" is missing from the path it works on'
			);
		}
	}

	public function test_the_settings_screen_shows_each_path_its_own_cards() {
		$client = $this->screen( Blogcraft_Mode::CLIENT, 'Blogcraft_Connection' );
		$api    = $this->screen( Blogcraft_Mode::API, 'Blogcraft_Connection' );

		$this->assertStringContainsString( 'bc-card-clients', $client );
		$this->assertStringNotContainsString( 'bc-card-provider', $client );
		$this->assertStringNotContainsString( 'bc-card-automation', $client );

		$this->assertStringContainsString( 'bc-card-provider', $api );
		$this->assertStringNotContainsString( 'bc-card-clients', $api );
	}

	public function test_the_status_strip_reports_only_what_applies() {
		// Research is the provider's; scheduled writing cannot happen on the
		// client path at all, and reporting it "off" implies it could be
		// turned on.
		$client = $this->screen( Blogcraft_Mode::CLIENT, 'Blogcraft_Connection' );

		$this->assertStringNotContainsString( 'Research', $client );
		$this->assertStringNotContainsString( 'Automation', $client );

		$api = $this->screen( Blogcraft_Mode::API, 'Blogcraft_Connection' );

		$this->assertStringContainsString( 'Research', $api );
		$this->assertStringContainsString( 'Automation', $api );
	}

	public function test_the_status_strip_names_what_is_in_use() {
		// "Provider connected" answers a question nobody standing here is
		// asking. Which provider, and which model, is the one they are.
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_model', 'gpt-5' );
		Blogcraft_Settings::set( 'provider_api_key', 'sk-something' );

		$html = $this->screen( Blogcraft_Mode::API, 'Blogcraft_Connection' );

		$this->assertStringContainsString( 'gpt-5', $html, 'the strip does not say which model is in use' );
	}

	public function test_the_client_strip_names_what_is_connected() {
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );
		Blogcraft_Mcp_Auth::issue( get_current_user_id(), 'Claude', array( 'client' => 'bc_claude' ) );

		$html = $this->screen( Blogcraft_Mode::CLIENT, 'Blogcraft_Connection' );

		$this->assertStringContainsString( 'Claude', $html );
	}
}
