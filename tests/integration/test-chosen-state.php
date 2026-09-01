<?php
/**
 * A choice you made should still look chosen after you save it.
 *
 * The shapes on the blueprint screen marked themselves when pressed and
 * forgot the moment the page reloaded, because the mark was added by the
 * script and nothing wrote it down. Eight identical cards, and no sign of
 * which one built the rules in front of you.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Chosen_State extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		delete_option( 'blogcraft_blueprints' );
		delete_option( 'blogcraft_active_blueprint' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * The blueprint screen, rendered.
	 *
	 * @return string
	 */
	private function screen() {
		ob_start();
		Blogcraft_Blueprint_Screen::render();

		return (string) ob_get_clean();
	}

	public function test_the_shape_that_built_the_rules_is_still_marked_after_saving() {
		$shapes = array_keys( Blogcraft_Archetypes::all() );

		$this->assertNotEmpty( $shapes, 'there are no shapes to choose from' );

		$chosen = $shapes[1];

		$blueprint              = Blogcraft_Blueprint::get();
		$blueprint['archetype'] = $chosen;

		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		$html = $this->screen();

		$this->assertStringContainsString(
			'class="bc-shape is-chosen" data-shape="' . esc_attr( $chosen ) . '"',
			$html,
			'the shape the rules came from is not marked'
		);

		// And exactly one of them, or the mark says nothing.
		$this->assertSame(
			1,
			substr_count( $html, 'bc-shape is-chosen' ),
			'more than one shape claims to be the chosen one'
		);
	}

	public function test_nothing_is_marked_before_a_shape_is_picked() {
		$html = $this->screen();

		$this->assertStringNotContainsString( 'bc-shape is-chosen', $html );
	}

	public function test_the_choice_is_carried_by_the_form_that_saves_it() {
		// Without a field in the form, saving any other setting on the page
		// would quietly clear the choice — which is worse than never having
		// marked it, because it looks like the save went wrong.
		$html = $this->screen();

		$this->assertStringContainsString( 'name="archetype"', $html );
	}

	public function test_the_choice_survives_a_save_that_was_not_about_it() {
		$shapes = array_keys( Blogcraft_Archetypes::all() );
		$chosen = $shapes[0];

		$blueprint              = Blogcraft_Blueprint::get();
		$blueprint['archetype'] = $chosen;
		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		// Somebody edits one unrelated field and saves.
		$again          = Blogcraft_Blueprint::get();
		$again['label'] = 'Renamed';
		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $again );

		$this->assertSame( $chosen, Blogcraft_Blueprint::get()['archetype'] );
	}
}
