<?php
/**
 * Every control has to do something.
 *
 * A field that is rendered, saved and then never consulted is a promise the
 * plugin does not keep, and it is invisible: nothing errors, the setting simply
 * has no effect. These tests read the field lists and assert that something
 * outside the form actually consumes each one, so a dead control fails the
 * build rather than shipping.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Wiring extends WP_UnitTestCase {

	/**
	 * Source of every plugin class, keyed by file.
	 *
	 * @param array $skip Files that only define or render fields.
	 * @return array
	 */
	private function sources( $skip = array() ) {
		$out = array();

		foreach ( (array) glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $path ) {
			$name = basename( $path );

			if ( in_array( $name, $skip, true ) ) {
				continue;
			}

			$out[ $name ] = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		return $out;
	}

	public function test_every_blueprint_field_is_read_by_something() {
		// The screens render them; the rules builders, the scorer and the
		// prompts are what has to consume them.
		$sources = $this->sources(
			array(
				'class-blogcraft-blueprint-screen.php',
				'class-blogcraft-generate.php',
				'class-blogcraft-archetypes.php',
				'class-blogcraft-emulate.php',
			)
		);

		$dead = array();

		foreach ( array_keys( Blogcraft_Blueprint::fields() ) as $field ) {
			$found = false;

			foreach ( $sources as $body ) {
				if ( false !== strpos( $body, "'" . $field . "'" ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$dead[] = $field;
			}
		}

		$this->assertSame( array(), $dead, 'blueprint fields nothing reads: ' . implode( ', ', $dead ) );
	}

	public function test_every_setting_is_read_by_something() {
		$sources = $this->sources(
			array(
				'class-blogcraft-settings-schema.php',
				'class-blogcraft-connection.php',
			)
		);

		$dead = array();

		foreach ( array_keys( Blogcraft_Settings_Schema::all() ) as $key ) {
			$found = false;

			foreach ( $sources as $body ) {
				if ( false !== strpos( $body, "'" . $key . "'" ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				$dead[] = $key;
			}
		}

		$this->assertSame( array(), $dead, 'settings nothing reads: ' . implode( ', ', $dead ) );
	}

	public function test_the_custom_endpoint_settings_reach_the_adapter() {
		// Six fields were rendered, filled in, saved, and never passed to the
		// adapter that reads them: every custom endpoint silently fell back to
		// Authorization, Bearer, and a default response path.
		Blogcraft_Settings::set( 'provider_type', 'custom' );
		Blogcraft_Settings::set( 'provider_base_url', 'https://example.test/v1/chat' );
		Blogcraft_Settings::set( 'provider_model', 'a-model' );
		Blogcraft_Settings::set( 'provider_api_key', 'k' );
		Blogcraft_Settings::set( 'provider_auth_header', 'X-Api-Key' );
		Blogcraft_Settings::set( 'provider_auth_prefix', 'Token ' );
		Blogcraft_Settings::set( 'provider_text_path', 'result.text' );
		// Without a template the adapter refuses before it reaches the network,
		// so leaving it out tests nothing.
		Blogcraft_Settings::set( 'provider_request_template', '{"model":"{{model}}","input":"{{prompt}}"}' );

		$sent = array();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$sent ) {
				$sent = $args;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'result' => array( 'text' => 'hello' ) ) ),
					'headers'  => array(),
				);
			},
			10,
			2
		);

		$provider = Blogcraft_Provider_Registry::from_settings();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		remove_all_filters( 'pre_http_request' );

		$this->assertArrayHasKey( 'X-Api-Key', $sent['headers'], 'the chosen auth header was ignored' );
		$this->assertSame( 'Token k', $sent['headers']['X-Api-Key'], 'the chosen auth prefix was ignored' );
		$this->assertSame( 'hello', $response->text, 'the chosen response path was ignored' );
		$this->assertStringContainsString( 'a-model', (string) $sent['body'], 'the request template was ignored' );
	}

	public function test_every_custom_config_key_has_a_setting_behind_it() {
		$settings = Blogcraft_Settings_Schema::all();

		foreach ( Blogcraft_Provider_Registry::custom_config_keys() as $key => $setting ) {
			$this->assertArrayHasKey(
				$setting,
				$settings,
				'the custom adapter reads ' . $key . ' but nothing stores it'
			);
		}
	}

	public function test_every_extra_section_can_actually_be_rendered() {
		// A switch that produces no markup is the same failure in a different
		// place, so each one is asserted to reach the page.
		$html = Blogcraft_Blocks::render(
			array(
				'intro'    => 'An opening.',
				'for_whom' => array( 'People who own a jar' ),
				'not_for'  => array( 'People who like it hot' ),
				'pros'     => array( 'Cheap' ),
				'cons'     => array( 'Slow' ),
				'mistakes' => array( 'Grinding too fine' ),
				'figures'  => array(
					array(
						'figure'  => '67%',
						'meaning' => 'Less acidity',
						'source'  => 'Our own testing',
					),
				),
				'sources'  => array(
					array(
						'title' => 'A source',
						'url'   => 'https://example.com/a',
					),
				),
			)
		);

		$this->assertStringContainsString( 'Who this is for', $html );
		$this->assertStringContainsString( 'Who it is not for', $html );
		$this->assertStringContainsString( 'What works', $html );
		$this->assertStringContainsString( 'Mistakes worth avoiding', $html );
		$this->assertStringContainsString( '<!-- wp:table -->', $html );
		$this->assertStringContainsString( '67%', $html );
		$this->assertStringContainsString( 'https://example.com/a', $html );
	}

	public function test_an_article_with_no_extras_renders_none_of_them() {
		$html = Blogcraft_Blocks::render( array( 'intro' => 'Just an opening.' ) );

		$this->assertStringNotContainsString( 'Who this is for', $html );
		$this->assertStringNotContainsString( '<!-- wp:table -->', $html );
		$this->assertStringNotContainsString( 'Sources', $html );
	}

	public function test_a_table_pads_a_ragged_row_rather_than_breaking() {
		$html = Blogcraft_Blocks::table(
			array( 'A', 'B', 'C' ),
			array( array( 'one' ), array( 'x', 'y', 'z', 'ignored' ) )
		);

		$this->assertSame( 2, substr_count( $html, '<tr><td>' ) );
		$this->assertStringNotContainsString( 'ignored', $html );
		$this->assertSame( 3, substr_count( $html, '<th>' ) );
	}

	public function test_a_table_with_nothing_in_it_renders_nothing() {
		$this->assertSame( '', Blogcraft_Blocks::table( array( 'A' ), array() ) );
		$this->assertSame( '', Blogcraft_Blocks::table( array(), array( array( '', '' ) ) ) );
	}

	public function test_a_numbered_list_is_marked_as_ordered() {
		$html = Blogcraft_Blocks::ordered_list( array( 'First', 'Second' ) );

		$this->assertStringContainsString( '"ordered":true', $html );
		$this->assertStringContainsString( '<ol', $html );
	}

	public function test_sources_are_built_from_urls_not_from_model_text() {
		// A model asked for a citation invents a plausible address that goes
		// nowhere, so an entry without a real url must produce no link.
		$html = Blogcraft_Blocks::render(
			array(
				'intro'   => 'x',
				'sources' => array( array( 'title' => 'Invented, no url' ) ),
			)
		);

		$this->assertStringNotContainsString( 'Invented', $html );
	}
}
