<?php
/**
 * More than one provider, kept, and switched between.
 *
 * The thing that must not go wrong here is the key: it is copied from the
 * settings onto a shelf and back again, and either half getting the
 * encryption wrong leaves somebody with a provider that reports a bad key
 * for a key that is perfectly good.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Saved_Providers extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();

		delete_option( Blogcraft_Connections::OPTION );
		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		delete_option( Blogcraft_Connections::OPTION );
		delete_option( 'blogcraft_settings' );

		parent::tear_down();
	}

	/**
	 * Put a provider in the live settings.
	 *
	 * @param string $type  Provider type.
	 * @param string $model Model name.
	 * @param string $key   API key.
	 * @return void
	 */
	private function configure( $type, $model, $key ) {
		Blogcraft_Settings::set( 'provider_type', $type );
		Blogcraft_Settings::set( 'provider_model', $model );
		Blogcraft_Settings::set( 'provider_api_key', $key );
	}

	public function test_a_saved_provider_comes_back_exactly_as_it_went_in() {
		// The key is the part that matters. It is encrypted in the settings,
		// encrypted again on the shelf, and decrypted on the way back; a
		// mistake at any of those three points reads as a provider rejecting
		// a key that is perfectly good.
		$this->configure( 'openai', 'gpt-5', 'sk-first-key' );

		$id = Blogcraft_Connections::save( 'The good one' );

		$this->assertNotSame( '', $id );

		// Move to something else entirely, then come back.
		$this->configure( 'gemini', 'gemini-flash', 'sk-second-key' );

		$this->assertTrue( Blogcraft_Connections::activate( $id ) );

		$this->assertSame( 'openai', Blogcraft_Settings::get( 'provider_type' ) );
		$this->assertSame( 'gpt-5', Blogcraft_Settings::get( 'provider_model' ) );
		$this->assertSame( 'sk-first-key', Blogcraft_Settings::get( 'provider_api_key' ) );
	}

	public function test_a_key_is_never_on_the_shelf_in_plain_text() {
		// A second option holding the key in the clear would undo the
		// encryption the first one bothers with.
		$this->configure( 'openai', 'gpt-5', 'sk-a-very-secret-value' );

		Blogcraft_Connections::save( 'Saved' );

		$raw = wp_json_encode( get_option( Blogcraft_Connections::OPTION ) );

		$this->assertStringNotContainsString( 'sk-a-very-secret-value', (string) $raw );
	}

	public function test_switching_carries_the_whole_setup_and_not_just_the_key() {
		// A custom endpoint restored with its address and key but without
		// its request shape fails in a way that reads like a bad key.
		$this->configure( 'custom', 'my-model', 'sk-custom' );
		Blogcraft_Settings::set( 'provider_base_url', 'https://example.test/v1' );
		Blogcraft_Settings::set( 'provider_auth_header', 'X-Key' );
		Blogcraft_Settings::set( 'provider_text_path', 'output.0.text' );

		$id = Blogcraft_Connections::save( 'Local' );

		$this->configure( 'openai', 'gpt-5', 'sk-other' );
		Blogcraft_Settings::set( 'provider_base_url', '' );
		Blogcraft_Settings::set( 'provider_auth_header', '' );
		Blogcraft_Settings::set( 'provider_text_path', '' );

		Blogcraft_Connections::activate( $id );

		$this->assertSame( 'https://example.test/v1', Blogcraft_Settings::get( 'provider_base_url' ) );
		$this->assertSame( 'X-Key', Blogcraft_Settings::get( 'provider_auth_header' ) );
		$this->assertSame( 'output.0.text', Blogcraft_Settings::get( 'provider_text_path' ) );
	}

	public function test_every_setting_that_decides_a_provider_is_carried() {
		// The rule, not the three examples above. A provider setting added
		// to the schema later and not to the shelf would be silently dropped
		// on every switch, and the only sign would be a setup that behaves
		// differently after being restored.
		$schema  = array_keys( Blogcraft_Settings_Schema::all() );
		$carried = Blogcraft_Connections::fields();
		$missed  = array();

		foreach ( $schema as $key ) {
			if ( 0 !== strpos( $key, 'provider_' ) ) {
				continue;
			}

			if ( ! in_array( $key, $carried, true ) ) {
				$missed[] = $key;
			}
		}

		$this->assertSame(
			array(),
			$missed,
			'switching would drop these: ' . implode( ', ', $missed )
		);
	}

	public function test_the_one_in_use_is_the_one_marked_as_in_use() {
		$this->configure( 'openai', 'gpt-5', 'sk-key' );

		$id    = Blogcraft_Connections::save( 'Mine' );
		$saved = Blogcraft_Connections::all();

		$this->assertTrue( Blogcraft_Connections::is_live( $saved[ $id ] ) );

		// Edited by hand after being saved, so it is no longer that setup.
		Blogcraft_Settings::set( 'provider_model', 'gpt-5-mini' );

		$this->assertFalse( Blogcraft_Connections::is_live( $saved[ $id ] ) );
	}

	public function test_forgetting_one_leaves_the_others() {
		$this->configure( 'openai', 'gpt-5', 'sk-key' );
		$first = Blogcraft_Connections::save( 'First' );

		$this->configure( 'gemini', 'gemini-flash', 'sk-two' );
		$second = Blogcraft_Connections::save( 'Second' );

		$this->assertTrue( Blogcraft_Connections::remove( $first ) );

		$left = Blogcraft_Connections::all();

		$this->assertArrayNotHasKey( $first, $left );
		$this->assertArrayHasKey( $second, $left );
	}

	public function test_the_shelf_does_not_grow_without_end() {
		for ( $i = 0; $i < Blogcraft_Connections::LIMIT + 4; $i++ ) {
			$this->configure( 'openai', 'model-' . $i, 'sk-' . $i );
			Blogcraft_Connections::save( 'Setup ' . $i );
		}

		$this->assertCount( Blogcraft_Connections::LIMIT, Blogcraft_Connections::all() );

		// And it is the oldest that went, not the newest.
		$labels = wp_list_pluck( Blogcraft_Connections::all(), 'label' );

		$this->assertContains( 'Setup ' . ( Blogcraft_Connections::LIMIT + 3 ), $labels );
		$this->assertNotContains( 'Setup 0', $labels );
	}

	public function test_nothing_is_saved_when_nothing_is_configured() {
		$this->assertSame( '', Blogcraft_Connections::save( 'Empty' ) );
		$this->assertSame( array(), Blogcraft_Connections::all() );
	}
}
