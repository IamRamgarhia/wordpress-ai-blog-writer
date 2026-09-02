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

		// The two files at the root count. uninstall.php reads settings of its
		// own — whether deleting the plugin should delete what it stored — and
		// scanning only includes/ reported that one as dead code.
		$paths = array_merge(
			(array) glob( BLOGCRAFT_PATH . 'includes/*.php' ),
			array( BLOGCRAFT_PATH . 'blogcraft.php', BLOGCRAFT_PATH . 'uninstall.php' )
		);

		foreach ( $paths as $path ) {
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

	public function test_a_prefix_is_separated_from_the_key_however_it_was_typed() {
		// Settings are stored through sanitize_text_field(), which trims, so a
		// prefix typed as "Bearer " arrives as "Bearer" and would be glued to
		// the key as "Bearerabc123". Every spelling has to work.
		foreach ( array( 'Bearer', 'Bearer ', '  Bearer  ' ) as $typed ) {
			Blogcraft_Settings::set( 'provider_type', 'custom' );
			Blogcraft_Settings::set( 'provider_base_url', 'https://example.test/v1/chat' );
			Blogcraft_Settings::set( 'provider_model', 'm' );
			Blogcraft_Settings::set( 'provider_api_key', 'abc123' );
			Blogcraft_Settings::set( 'provider_auth_prefix', $typed );
			Blogcraft_Settings::set( 'provider_auth_header', 'Authorization' );
			Blogcraft_Settings::set( 'provider_request_template', '{"model":"{{model}}","input":"{{prompt}}"}' );

			$sent = array();

			add_filter(
				'pre_http_request',
				function ( $preempt, $args ) use ( &$sent ) {
					$sent = $args;

					return array(
						'response' => array( 'code' => 200 ),
						'body'     => wp_json_encode( array( 'text' => 'ok' ) ),
						'headers'  => array(),
					);
				},
				10,
				2
			);

			Blogcraft_Provider_Registry::from_settings()->complete(
				array(
					array(
						'role'    => 'user',
						'content' => 'hi',
					),
				)
			);

			remove_all_filters( 'pre_http_request' );

			$this->assertSame( 'Bearer abc123', $sent['headers']['Authorization'], 'prefix typed as "' . $typed . '"' );
		}
	}

	public function test_an_empty_header_name_falls_back_rather_than_sending_nothing() {
		Blogcraft_Settings::set( 'provider_type', 'custom' );
		Blogcraft_Settings::set( 'provider_base_url', 'https://example.test/v1/chat' );
		Blogcraft_Settings::set( 'provider_model', 'm' );
		Blogcraft_Settings::set( 'provider_api_key', 'abc123' );
		Blogcraft_Settings::set( 'provider_auth_header', '' );
		Blogcraft_Settings::set( 'provider_request_template', '{"input":"{{prompt}}"}' );

		$sent = array();

		add_filter(
			'pre_http_request',
			function ( $preempt, $args ) use ( &$sent ) {
				$sent = $args;

				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'text' => 'ok' ) ),
					'headers'  => array(),
				);
			},
			10,
			2
		);

		Blogcraft_Provider_Registry::from_settings()->complete(
			array(
				array(
					'role'    => 'user',
					'content' => 'hi',
				),
			)
		);

		remove_all_filters( 'pre_http_request' );

		$this->assertArrayHasKey( 'Authorization', $sent['headers'] );
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

	public function test_uninstall_names_every_post_meta_key_the_plugin_writes() {
		// The note at the top of uninstall.php promises every trace is removed.
		// It listed none of these until it was checked.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$written = array();

		foreach ( (array) glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $path ) {
			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( preg_match_all( "/'(_blogcraft_[a-z_]+)'/", $body, $hits ) ) {
				foreach ( $hits[1] as $key ) {
					$written[ $key ] = $key;
				}
			}
		}

		// The nonce field is request data, not something stored on a post.
		unset( $written['_blogcraft_nonce'] );

		$missed = array();

		foreach ( $written as $key ) {
			if ( false === strpos( $source, "'" . $key . "'" ) ) {
				$missed[] = $key;
			}
		}

		$this->assertSame( array(), $missed, 'uninstall leaves these behind: ' . implode( ', ', $missed ) );
	}

	public function test_uninstall_names_every_option_the_plugin_writes() {
		// The sibling above checked post meta and found four keys missing.
		// Nothing checked options, and four of those were missing too — the
		// blueprint store among them, so deleting the plugin and installing it
		// again handed the next owner the last one's writing rules.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$written = array();

		foreach ( (array) glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $path ) {
			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			// Matching how a name is used rather than how it looks. The plugin
			// is full of blogcraft_-prefixed strings that are not options —
			// nonce actions, admin-post actions, cron hooks, transient
			// prefixes — and a list of those to ignore would need extending
			// every time somebody adds a button. These two patterns are what
			// an option actually looks like: passed to the option functions,
			// or declared as the constant that will be.
			if ( preg_match_all( "/(?:get|update|add|delete)_option\(\s*'(blogcraft_[a-z_]+)'/", $body, $hits ) ) {
				foreach ( $hits[1] as $key ) {
					$written[ $key ] = $key;
				}
			}

			if ( preg_match_all( "/const\s+\w*OPTION\w*\s*=\s*'(blogcraft_[a-z_]+)'/", $body, $hits ) ) {
				foreach ( $hits[1] as $key ) {
					$written[ $key ] = $key;
				}
			}

			// The pattern above only sees a constant if somebody named it
			// OPTION, which makes the rule depend on the same care it exists
			// to check for — and it duly missed the OAuth client store,
			// declared as CLIENTS. So: any constant actually passed to an
			// option function, whatever it is called, resolved to what it
			// holds.
			$named = array();

			if ( preg_match_all( "/(?:get|update|add|delete)_option\(\s*(?:self::|static::)?([A-Z][A-Z0-9_]*)\b/", $body, $hits ) ) {
				$named = array_unique( $hits[1] );
			}

			foreach ( $named as $constant ) {
				if ( preg_match( "/const\s+" . preg_quote( $constant, "/" ) . "\s*=\s*'(blogcraft_[a-z_]+)'/", $body, $found ) ) {
					$written[ $found[1] ] = $found[1];
				}
			}
		}

		$missed = array();

		foreach ( $written as $key ) {
			if ( false === strpos( $source, "'" . $key . "'" ) ) {
				$missed[] = $key;
			}
		}

		sort( $missed );

		$this->assertSame( array(), $missed, 'uninstall leaves these options behind: ' . implode( ', ', $missed ) );
	}

	public function test_provider_addresses_come_from_the_data_file() {
		$text = Blogcraft_Endpoints::text();

		$this->assertArrayHasKey( 'openai', $text );
		$this->assertNotSame( '', $text['openai']['base_url'] );
		$this->assertNotSame( '', Blogcraft_Endpoints::image( 'fal' )['endpoint'] );
	}

	public function test_another_plugin_can_add_a_provider() {
		// Endpoints being data rather than literals is what makes this possible,
		// and a filter nobody can use is not a feature.
		$added = function ( $providers ) {
			$providers['acme'] = array(
				'adapter'  => 'openai',
				'base_url' => 'https://example.test/v1',
				'help'     => 'Acme',
				'key_url'  => 'https://example.test/keys',
				'docs_url' => 'https://example.test/models',
			);

			return $providers;
		};

		add_filter( 'blogcraft_providers', $added );

		$types = Blogcraft_Provider_Registry::types();

		remove_filter( 'blogcraft_providers', $added );

		$this->assertArrayHasKey( 'acme', $types );
	}

	public function test_a_missing_data_file_does_not_fatal() {
		// Every caller has to survive the file being absent or unreadable.
		$empty = function () {
			return array();
		};

		add_filter( 'blogcraft_providers', $empty, 99 );

		$types   = Blogcraft_Provider_Registry::types();
		$address = Blogcraft_Provider_Registry::default_base_url( 'openai' );

		// Asked while the filter is still on. Removing it first and then asking
		// tests the ordinary path, which is what the first version of this did.
		remove_filter( 'blogcraft_providers', $empty, 99 );

		$this->assertIsArray( $types );
		$this->assertSame( '', $address );
		$this->assertNotSame( '', Blogcraft_Provider_Registry::default_base_url( 'openai' ) );
	}

	/**
	 * The published documentation, as the page is built from.
	 *
	 * Deliberately not shipped with the plugin. It is read here so a
	 * help link pointing at a section the page does not have fails in
	 * the test suite rather than in somebody's browser.
	 *
	 * @return array Section id => section.
	 */
	private function docs() {
		$path = BLOGCRAFT_PATH . 'docs/content.json';

		if ( ! file_exists( $path ) ) {
			$this->fail( 'docs/content.json is missing, so no help link can be checked' );
		}

		$doc = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertIsArray( $doc, 'docs/content.json is not readable as JSON' );

		$by_id = array();

		foreach ( $doc['sections'] as $one ) {
			$by_id[ $one['id'] ] = $one;
		}

		return $by_id;
	}

	public function test_every_section_of_the_documentation_says_something() {
		$sections = $this->docs();

		$this->assertNotEmpty( $sections );

		foreach ( $sections as $id => $section ) {
			$this->assertNotSame( '', $section['title'], $id . ' has no title' );
			$this->assertNotEmpty( $section['lead'], $id . ' has no opening line' );

			$this->assertTrue(
				! empty( $section['steps'] ) || ! empty( $section['points'] ) || ! empty( $section['prose'] ),
				$id . ' has nothing under its opening line'
			);
		}
	}

	public function test_the_documentation_is_written_in_short_lines() {
		// Nobody reads documentation from the top. They arrive with a
		// question and scan, so this pins the shape rather than the
		// wording and the next thing added has to be scannable too.
		$sections = $this->docs();

		foreach ( $sections as $id => $section ) {
			$this->assertLessThanOrEqual(
				160,
				mb_strlen( (string) $section['lead'] ),
				$id . ' opens with a paragraph rather than a sentence'
			);

			foreach ( $section['steps'] as $step ) {
				$this->assertLessThanOrEqual(
					40,
					mb_strlen( (string) $step['title'] ),
					$id . ' has a step name long enough to be a sentence'
				);

				$this->assertLessThanOrEqual(
					150,
					mb_strlen( (string) $step['text'] ),
					$id . ' has a step nobody will read'
				);
			}

			foreach ( $section['points'] as $point ) {
				$this->assertLessThanOrEqual(
					200,
					mb_strlen( (string) $point ),
					$id . ' has a bullet that is really a paragraph'
				);
			}
		}
	}

	public function test_the_documentation_opens_with_the_steps() {
		// Somebody who has just installed this wants the order to do
		// things in, not a section on privacy.
		$sections = $this->docs();

		$this->assertSame( 'quickstart', (string) key( $sections ) );
		$this->assertNotEmpty( $sections['quickstart']['steps'] );
	}

	public function test_no_provider_is_chosen_for_you() {
		// The default was 'openai': a plugin whose whole point is that you
		// bring your own key opened with somebody else's company already
		// selected, and a paid, card-first one at that, sitting above every
		// route that costs nothing.
		delete_option( 'blogcraft_settings' );

		$this->assertSame( '', (string) Blogcraft_Settings::get( 'provider_type' ) );
		$this->assertFalse( Blogcraft_Provider_Registry::is_configured() );
		$this->assertNull( Blogcraft_Provider_Registry::from_settings() );
	}

	public function test_an_unchosen_provider_is_not_read_as_needing_no_key() {
		// help() falls back to the custom endpoint for an unknown type, and a
		// custom endpoint has no key_url — so the "runs on your machine, needs
		// no key" branch would have swallowed the empty case and opened the
		// model fields before there was anything to ask with.
		delete_option( 'blogcraft_settings' );
		Blogcraft_Settings::set( 'provider_base_url', 'https://example.com/v1' );

		$this->assertFalse(
			Blogcraft_Provider_Registry::is_configured(),
			'a typed-in address counted as a configured provider'
		);
	}

	public function test_every_help_panel_points_at_a_section_that_exists() {
		$sections = $this->docs();
		$source   = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-connection.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! preg_match_all( "/'anchor'\\s*=>\\s*'([a-z-]+)'/", $source, $hits ) ) {
			$this->fail( 'no help anchors found to check' );
		}

		foreach ( $hits[1] as $anchor ) {
			$this->assertArrayHasKey(
				$anchor,
				$sections,
				'a help panel links to "' . $anchor . '", which the documentation does not have'
			);
		}
	}

	public function test_a_word_count_is_not_recalculated_on_every_view() {
		$post_id = self::factory()->post->create(
			array( 'post_content' => '<p>' . str_repeat( 'One two three four five. ', 40 ) . '</p>' )
		);

		$post  = get_post( $post_id );
		$first = Blogcraft_Seo::word_count( $post );

		$this->assertGreaterThan( 100, $first );

		$stored = get_post_meta( $post_id, Blogcraft_Seo::WORDS_META, true );

		$this->assertSame( $first, (int) $stored['words'] );
		$this->assertSame( md5( $post->post_content ), $stored['of'] );
		$this->assertSame( $first, Blogcraft_Seo::word_count( $post ) );
	}

	public function test_two_edits_in_the_same_second_still_recount() {
		// post_modified_gmt has one-second resolution, so keying the cache on it
		// served a stale count for edits that landed together. Tests are fast
		// enough to hit that every time.
		$post_id = self::factory()->post->create( array( 'post_content' => '<p>Four words exactly here.</p>' ) );

		$before = Blogcraft_Seo::word_count( get_post( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p>' . str_repeat( 'Many more words now. ', 30 ) . '</p>',
			)
		);

		clean_post_cache( $post_id );

		$after = Blogcraft_Seo::word_count( get_post( $post_id ) );

		$this->assertNotSame( $before, $after );
		$this->assertGreaterThan( 50, $after );
	}

	public function test_editing_a_post_recounts_it() {
		$post_id = self::factory()->post->create( array( 'post_content' => '<p>Four words exactly here.</p>' ) );

		Blogcraft_Seo::word_count( get_post( $post_id ) );

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<p>' . str_repeat( 'Many more words now. ', 30 ) . '</p>',
			)
		);

		clean_post_cache( $post_id );

		$this->assertGreaterThan( 50, Blogcraft_Seo::word_count( get_post( $post_id ) ) );
	}

	public function test_both_picture_switches_are_reachable() {
		// images_per_section was read by the pipeline and rendered nowhere, so
		// there was no way to turn body images on at all.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-connection.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		foreach ( array( 'images_enabled', 'images_per_section' ) as $toggle ) {
			$this->assertStringContainsString( "'" . $toggle . "'", $source, $toggle . ' has no control' );
		}
	}

	public function test_every_anchor_the_plugin_links_to_exists_on_the_page() {
		// Every link from anywhere in the plugin, not only the settings
		// cards. A link into the middle of a page that has moved on lands
		// at the top of it, with nothing to say anything is wrong.
		$sections = $this->docs();
		$anchors  = array();

		// Two ways an anchor is written: passed to the link helpers directly,
		// or held in the map the settings cards look themselves up in. A
		// pattern for only one of them checks half the links and reports
		// nothing about the other half.
		$patterns = array(
			"/Blogcraft_Docs::(?:site_url|link)\\(\\s*(?:\\/\\/[^\\n]*\\n\\s*)?'([a-z-]+)'/",
			"/'anchor'\\s*=>\\s*'([a-z-]+)'/",
		);

		foreach ( (array) glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $path ) {
			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			foreach ( $patterns as $pattern ) {
				if ( preg_match_all( $pattern, $body, $hits ) ) {
					$anchors = array_merge( $anchors, $hits[1] );
				}
			}
		}

		$this->assertNotEmpty( $anchors, 'nothing in the plugin links to the documentation' );

		// One that is reached only through the map, so a pattern that stops
		// seeing the map fails here rather than passing on a smaller set.
		$this->assertContains( 'quickstart', $anchors, 'nothing points at the setup guide' );
		$this->assertContains( 'pictures', $anchors, 'the picture card explains nothing' );

		foreach ( array_unique( $anchors ) as $anchor ) {
			$this->assertArrayHasKey(
				$anchor,
				$sections,
				'the plugin links to "' . $anchor . '", which the documentation does not have'
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

	public function test_every_stage_handed_the_evidence_builds_a_prompt_that_carries_it() {
		// The pipeline calls use_evidence() before seven stages. Four of the
		// prompts those stages build never emitted it, so the value was set on
		// a static and dropped: the outline shaped a post with no room for the
		// author's own material, the critique could not tell whether it had
		// survived, and the rewrite — told to cut forty words — could
		// paraphrase a measured figure into "some" with nothing to notice.
		//
		// Setting it and not using it is the failure worth catching, because it
		// looks exactly like working code at every call site.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-prompts.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		preg_match_all( '/public static function ([a-z_]+)\(/', $source, $found, PREG_OFFSET_CAPTURE );

		$this->assertNotEmpty( $found[1] );

		// Builders that take no evidence by design: two setters, two helpers,
		// and the one that repairs a malformed reply rather than asking for
		// anything.
		$exempt = array( 'use_blueprint', 'use_evidence', 'limit', 'extra', 'extract_json' );

		$missing = array();
		$count   = count( $found[1] );

		for ( $i = 0; $i < $count; $i++ ) {
			$name = $found[1][ $i ][0];

			if ( in_array( $name, $exempt, true ) ) {
				continue;
			}

			$from = (int) $found[1][ $i ][1];
			$to   = ( $i + 1 < $count ) ? (int) $found[1][ $i + 1 ][1] : strlen( $source );
			$body = substr( $source, $from, $to - $from );

			if ( false === strpos( $body, 'self::evidence_block()' ) && false === strpos( $body, 'self::evidence_guard()' ) ) {
				$missing[] = $name;
			}
		}

		$this->assertSame( array(), $missing, 'these prompts are handed the evidence and never pass it on' );
	}

	public function test_no_query_asks_the_database_to_exclude_a_post() {
		// exclude and post__not_in become a NOT IN, which stops MySQL using
		// its index on a table that only ever grows. Plugin Check warns on it,
		// and a warning is what stands between this and being accepted.
		//
		// The plugin already had the right answer in the related-posts query:
		// ask for one more row than you need and drop the unwanted one in PHP.
		// A newer file simply did not follow it, which is what this catches.
		$found = array();

		foreach ( (array) glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $path ) {
			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			foreach ( explode( "
", $body ) as $number => $line ) {
				// Comments explaining why it is avoided are not uses of it.
				if ( preg_match( '/^\s*(\/\/|\*)/', $line ) ) {
					continue;
				}

				if ( preg_match( "/'(exclude|post__not_in)'\s*=>/", $line ) ) {
					$found[] = basename( $path ) . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame( array(), $found, 'these queries make the database do the excluding' );
	}

	public function test_the_documentation_address_is_written_down_once() {
		// Two links to the same page in two files is how they come to disagree,
		// and a documentation link that quietly 404s is worse than none: the
		// reader concludes the plugin is abandoned rather than that one string
		// is stale.
		//
		// The plugin header is the one unavoidable exception. WordPress reads
		// that as text before any code runs, so it cannot call anything.
		$loose = array();

		$files = array_merge(
			(array) glob( BLOGCRAFT_PATH . 'includes/*.php' ),
			array( BLOGCRAFT_PATH . 'blogcraft.php' )
		);

		foreach ( $files as $path ) {
			$name = basename( $path );
			$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			foreach ( explode( "
", $body ) as $number => $line ) {
				if ( false === strpos( $line, 'dicecodes.com/blogcraft' ) ) {
					continue;
				}

				// The helper itself, and the file header.
				if ( 'class-blogcraft-docs.php' === $name || false !== strpos( $line, 'Plugin URI' ) ) {
					continue;
				}

				$loose[] = $name . ':' . ( $number + 1 );
			}
		}

		$this->assertSame( array(), $loose, 'these write the documentation address out again instead of asking for it' );
	}

	public function test_the_helper_and_the_plugin_header_agree() {
		$headers = get_file_data( BLOGCRAFT_PATH . 'blogcraft.php', array( 'uri' => 'Plugin URI' ) );

		$this->assertSame(
			untrailingslashit( Blogcraft_Docs::site_url() ),
			untrailingslashit( $headers['uri'] ),
			'the header points somewhere other than the documentation the plugin links to'
		);
	}
}
