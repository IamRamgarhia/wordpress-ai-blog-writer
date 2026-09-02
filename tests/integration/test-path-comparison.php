<?php
/**
 * What each way of running this can do, after the choice has been made.
 *
 * The two paths were laid out side by side while the question was open and
 * then never again. Once one was picked the screen offered a switch button
 * and nothing to base the decision on — so anybody wondering whether the
 * other suited them better had to switch to find out.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Path_Comparison extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		delete_option( 'blogcraft_settings' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The settings screen on one path.
	 *
	 * @param string $mode Which path, or '' for not yet chosen.
	 * @return string
	 */
	private function screen( $mode ) {
		if ( '' !== $mode ) {
			Blogcraft_Settings::set( 'setup_path', $mode );
		}

		ob_start();
		Blogcraft_Connection::render();

		return (string) ob_get_clean();
	}

	public function test_both_ways_are_described_before_anything_is_chosen() {
		$html = $this->screen( '' );

		$this->assertStringContainsString( 'Inside WordPress', $html );
		$this->assertStringContainsString( 'From Claude or ChatGPT', $html );
	}

	public function test_both_ways_are_still_described_afterwards() {
		// The whole point. A switch button with nothing to weigh it against
		// is a decision offered without the facts.
		foreach ( array( Blogcraft_Mode::CLIENT, Blogcraft_Mode::API ) as $mode ) {
			$html = $this->screen( $mode );

			$this->assertStringContainsString(
				'Inside WordPress',
				$html,
				'the provider path is not described on the ' . $mode . ' path'
			);

			$this->assertStringContainsString(
				'From Claude or ChatGPT',
				$html,
				'the client path is not described on the ' . $mode . ' path'
			);
		}
	}

	public function test_the_comparison_names_what_each_one_cannot_do() {
		// The costs are the half that decides it, and the half a plugin is
		// tempted to leave out.
		$html = $this->screen( Blogcraft_Mode::CLIENT );

		$this->assertStringContainsString( 'No scheduled or unattended writing', $html );
		$this->assertStringContainsString( 'Needs an API key, or a local model', $html );
	}

	public function test_it_is_folded_away_rather_than_filling_the_screen() {
		$html = $this->screen( Blogcraft_Mode::CLIENT );

		$this->assertStringContainsString( 'bc-path-compare', $html );
		$this->assertStringContainsString( 'What each way can do', $html );
	}

	public function test_the_overview_links_to_where_the_switch_is() {
		// "Change" used to land at the top of a long screen with the answer
		// somewhere on it.
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		ob_start();
		Blogcraft_Overview::render();
		$overview = (string) ob_get_clean();

		$this->assertStringContainsString( '#bc-card-path', $overview );

		// And the thing it points at is on the settings screen on both
		// paths, chosen or not.
		foreach ( array( '', Blogcraft_Mode::CLIENT, Blogcraft_Mode::API ) as $mode ) {
			$this->assertStringContainsString(
				'id="bc-card-path"',
				$this->screen( $mode ),
				'the anchor is missing on "' . $mode . '"'
			);
		}
	}
}
