<?php
/**
 * Saying which parts of the brief have never been answered.
 *
 * The defaults produce a perfectly ordinary post, which is the problem: a
 * site can be set up, connected and writing before anybody notices that the
 * length, the shape, the reading level and the voice are all still the ones
 * the plugin shipped with. Nothing said so, and finding out meant opening
 * all seven sections and comparing them with a memory of the defaults.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Untouched extends WP_UnitTestCase {

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
	 * Change one field and store it.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value New value.
	 * @return void
	 */
	private function change( $field, $value ) {
		$blueprint            = Blogcraft_Blueprint::get();
		$blueprint[ $field ] = $value;

		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );
	}

	public function test_a_fresh_site_has_answered_nothing() {
		$this->assertFalse( Blogcraft_Blueprint::was_edited() );
		$this->assertSame( array(), Blogcraft_Blueprint::changed_fields() );

		// Every section that holds fields is still at its defaults.
		$holding = array();

		foreach ( Blogcraft_Blueprint_Screen::group_fields() as $slug => $fields ) {
			if ( ! empty( $fields ) ) {
				$holding[] = $slug;
			}
		}

		$this->assertSame( $holding, Blogcraft_Blueprint_Screen::untouched_groups() );
	}

	public function test_changing_one_field_marks_only_its_own_section() {
		$this->change( 'word_target', 2400 );

		$this->assertContains( 'word_target', Blogcraft_Blueprint::changed_fields() );

		$untouched = Blogcraft_Blueprint_Screen::untouched_groups();

		$this->assertNotContains( 'structure', $untouched, 'the section holding the changed field is still called default' );
		$this->assertContains( 'voice', $untouched, 'a section nobody touched stopped being marked' );
	}

	public function test_the_screen_marks_the_sections_nobody_has_been_into() {
		ob_start();
		Blogcraft_Blueprint_Screen::render();
		$fresh = (string) ob_get_clean();

		$this->assertStringContainsString( 'bc-rail-default', $fresh );
		$this->assertStringContainsString( 'Nothing here has been answered yet', $fresh );
	}

	public function test_the_notice_goes_once_something_has_been_answered() {
		$this->change( 'niche', 'Coffee equipment, tested not summarised' );

		ob_start();
		Blogcraft_Blueprint_Screen::render();
		$after = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Nothing here has been answered yet', $after );
	}

	public function test_the_write_screen_says_the_rules_are_still_default() {
		Blogcraft_Settings::set( 'setup_path', Blogcraft_Mode::CLIENT );

		ob_start();
		Blogcraft_Generate::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'The writing rules, answered once', $html );

		// And sends people to the screen that holds them.
		$this->assertStringContainsString( 'page=blogcraft-blueprint', $html );
	}

	public function test_every_field_belongs_to_exactly_one_section() {
		// The map is written by hand, so this is what stops it rotting: a
		// field added to a pane and not to the map would be a field the
		// "still default" marker silently stopped watching.
		$mapped = array();

		foreach ( Blogcraft_Blueprint_Screen::group_fields() as $slug => $fields ) {
			foreach ( $fields as $field ) {
				$this->assertArrayNotHasKey(
					$field,
					$mapped,
					'"' . $field . '" is claimed by two sections'
				);

				$mapped[ $field ] = $slug;
			}
		}

		$real = Blogcraft_Blueprint::fields();

		foreach ( array_keys( $mapped ) as $field ) {
			$this->assertArrayHasKey( $field, $real, '"' . $field . '" is not a field on the blueprint' );
		}

		// The handful the screen does not offer: the archetype and the label
		// are the plugin's own bookkeeping, and the tolerance is derived.
		$not_on_screen = array( 'archetype', 'label', 'word_tolerance' );

		foreach ( array_keys( $real ) as $field ) {
			if ( in_array( $field, $not_on_screen, true ) ) {
				continue;
			}

			$this->assertArrayHasKey(
				$field,
				$mapped,
				'"' . $field . '" is a blueprint field that no section claims, so nothing watches whether it has been answered'
			);
		}
	}
}
