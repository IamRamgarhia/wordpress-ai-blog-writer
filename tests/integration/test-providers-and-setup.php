<?php
/**
 * Provider catalogue, learning from existing posts, and where a post lands.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Providers_And_Setup extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		parent::tear_down();
	}

	// ------------------------------------------------------------ providers.

	public function test_every_provider_is_complete() {
		foreach ( Blogcraft_Provider_Registry::catalogue() as $id => $spec ) {
			foreach ( array( 'label', 'adapter', 'base_url', 'help', 'key_url', 'docs_url' ) as $field ) {
				$this->assertArrayHasKey( $field, $spec, $id . ' is missing ' . $field );
			}

			$this->assertNotSame( '', $spec['label'], $id . ' has no label' );
			$this->assertNotSame( '', $spec['adapter'], $id . ' has no adapter' );
		}
	}

	public function test_every_provider_builds_an_adapter() {
		foreach ( array_keys( Blogcraft_Provider_Registry::catalogue() ) as $id ) {
			$provider = Blogcraft_Provider_Registry::make( $id, array( 'model' => 'x' ) );

			$this->assertInstanceOf( 'Blogcraft_Provider', $provider, $id . ' built nothing' );
		}
	}

	public function test_an_unknown_provider_builds_nothing() {
		$this->assertNull( Blogcraft_Provider_Registry::make( 'not-a-provider' ) );
	}

	public function test_the_named_providers_are_all_offered() {
		// Listing one entry called "OpenAI-compatible" is the difference between
		// a user finding their provider and assuming it is unsupported.
		$types = Blogcraft_Provider_Registry::types();

		foreach ( array( 'openai', 'anthropic', 'gemini', 'xai', 'moonshot', 'deepseek', 'groq', 'openrouter', 'ollama' ) as $id ) {
			$this->assertArrayHasKey( $id, $types );
		}
	}

	public function test_hosted_providers_say_where_to_get_a_key() {
		foreach ( Blogcraft_Provider_Registry::catalogue() as $id => $spec ) {
			// Local runtimes need no key, the custom endpoint is the user's
			// own, and the AI Client holds credentials in WordPress itself.
			if ( in_array( $id, array( 'custom', 'ollama', 'lmstudio', 'wpai' ), true ) ) {
				continue;
			}

			$help = Blogcraft_Provider_Registry::help( $id );

			$this->assertNotSame( '', $help['key_url'], $id . ' does not say where to get a key' );
			$this->assertNotSame( '', $help['docs_url'], $id . ' does not link its model list' );
		}
	}

	public function test_every_provider_but_the_custom_one_has_an_address() {
		foreach ( array_keys( Blogcraft_Provider_Registry::catalogue() ) as $id ) {
			// The AI Client has no address of its own: WordPress routes it.
			if ( in_array( $id, array( 'custom', 'wpai' ), true ) ) {
				continue;
			}

			$this->assertNotSame( '', Blogcraft_Provider_Registry::default_base_url( $id ), $id . ' has no default address' );
		}
	}

	public function test_the_wordpress_ai_client_is_only_offered_when_it_exists() {
		// An option that cannot work is worse than no option, so it appears in
		// the list only when the function really is there.
		$offered = array_key_exists( 'wpai', Blogcraft_Provider_Registry::types() );

		$this->assertSame( function_exists( 'wp_ai_client_prompt' ), $offered );
	}

	public function test_the_ai_client_adapter_is_safe_where_there_is_no_client() {
		// The plugin supports WordPress 6.0, where none of this exists, so the
		// adapter has to be constructible and has to answer rather than fatal.
		$provider = new Blogcraft_Provider_Wpai( array() );

		$this->assertInstanceOf( 'Blogcraft_Provider', $provider );
		$this->assertSame( 'wpai', $provider->id() );
		$this->assertSame( array(), $provider->list_models() );

		if ( Blogcraft_Provider_Wpai::is_available() ) {
			$this->markTestSkipped( 'This WordPress has an AI Client, so the absent case cannot be exercised.' );
		}

		$this->assertFalse( Blogcraft_Provider_Wpai::is_ready() );

		$response = $provider->complete(
			array(
				array(
					'role'    => 'user',
					'content' => 'hi',
				),
			)
		);

		$this->assertTrue( $response->is_error() );
		$this->assertStringContainsString( 'AI Client', $response->error );
	}

	public function test_a_local_runtime_needs_no_key_to_count_as_configured() {
		Blogcraft_Settings::set( 'provider_type', 'ollama' );
		Blogcraft_Settings::set( 'provider_model', 'llama3' );
		Blogcraft_Settings::set( 'provider_api_key', '' );

		$this->assertTrue( Blogcraft_Provider_Registry::is_configured() );
	}

	// --------------------------------------------------------- review tab.

	public function test_the_review_tab_is_hidden_when_nothing_is_waiting() {
		$this->assertFalse( Blogcraft_Review::has_pending() );
		$this->assertArrayNotHasKey( 'blogcraft-review', Blogcraft_Nav::screens() );
	}

	public function test_the_review_tab_appears_when_something_is_waiting() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'pending' ) );
		update_post_meta( $post_id, '_blogcraft_generated', 1 );

		$this->assertTrue( Blogcraft_Review::has_pending() );
		$this->assertArrayHasKey( 'blogcraft-review', Blogcraft_Nav::screens() );
	}

	// -------------------------------------------------------------- research.

	public function test_the_free_sources_gather_when_switched_on() {
		// What "on by default" used to be pinned to here now lives in
		// Test_Blogcraft_Consent, asserting the reverse: nothing is contacted
		// until somebody asks. This keeps the other half — that asking works.
		foreach ( array_keys( Blogcraft_Research::free_sources() ) as $key ) {
			Blogcraft_Settings::set( $key, true );

			$this->assertTrue( (bool) Blogcraft_Settings::get( $key ), $key . ' will not turn on' );
		}
	}

	public function test_turning_the_free_sources_off_gathers_nothing_from_them() {
		Blogcraft_Settings::set( 'research_wikipedia', false );
		Blogcraft_Settings::set( 'research_community', false );

		$this->assertSame( array(), Blogcraft_Research::free_material( 'anything' ) );
	}

	// ------------------------------------------------------------- learning.

	/**
	 * Publish a post with known prose.
	 *
	 * @param string $title Title.
	 * @param string $body  Body text.
	 * @return int
	 */
	private function publish( $title, $body ) {
		return self::factory()->post->create(
			array(
				'post_title'   => $title,
				'post_content' => $body,
				'post_status'  => 'publish',
			)
		);
	}

	public function test_nothing_to_learn_from_says_so() {
		$suggestion = Blogcraft_Learn::suggest();

		$this->assertSame( 0, $suggestion['found'] );
		$this->assertSame( array(), $suggestion['fields'] );
		$this->assertNotEmpty( $suggestion['notes'] );
	}

	public function test_posts_blogcraft_wrote_are_not_learned_from() {
		// Learning a voice from your own output is a feedback loop that ends
		// with every post sounding like the first one it generated.
		$post_id = $this->publish( 'Generated', '<p>' . str_repeat( 'Words here. ', 30 ) . '</p>' );
		update_post_meta( $post_id, '_blogcraft_generated', 1 );

		$this->assertSame( array(), Blogcraft_Learn::sample() );
	}

	public function test_it_measures_how_the_blog_actually_writes() {
		$this->publish(
			'Short and personal',
			'<p>I tried it. We liked it. It worked.</p><p>I did it again. It still worked.</p>'
		);

		$seen = Blogcraft_Learn::observe();

		$this->assertSame( 1, $seen['posts'] );
		$this->assertSame( 'first', $seen['person'] );
		$this->assertLessThan( 10, $seen['sentence_words'] );
	}

	public function test_it_notices_a_blog_that_addresses_the_reader() {
		$this->publish(
			'Second person',
			'<p>You should try it. Your results will vary. You will want to check yours.</p>'
		);

		$this->assertSame( 'second', Blogcraft_Learn::observe()['person'] );
	}

	public function test_the_rules_it_writes_follow_from_the_measurements() {
		$this->publish(
			'Short and personal',
			'<p>I tried it. We liked it. It worked.</p><p>I did it again. It still worked.</p>'
		);

		$rules = implode( "\n", Blogcraft_Learn::style_rules( Blogcraft_Learn::observe() ) );

		$this->assertStringContainsString( 'first person', $rules );
		$this->assertStringContainsString( 'em dashes', $rules );
	}

	public function test_it_invents_no_rules_it_did_not_observe() {
		$this->assertSame( array(), Blogcraft_Learn::style_rules( array( 'posts' => 0 ) ) );
	}

	public function test_it_suggests_without_saving_anything() {
		$this->publish( 'A post', '<p>I tried it. We liked it. It worked.</p>' );

		$before = (string) Blogcraft_Settings::get( 'voice_style_rules' );

		$suggestion = Blogcraft_Learn::suggest();

		$this->assertNotEmpty( $suggestion['fields']['voice_style_rules'] );
		$this->assertSame( $before, (string) Blogcraft_Settings::get( 'voice_style_rules' ), 'settings were changed without asking' );
	}

	// ------------------------------------------------------------ placement.

	public function test_where_a_post_lands_is_carried_on_the_job() {
		$job_id = Blogcraft_Pipeline::enqueue_topic(
			'anything',
			'draft',
			'',
			array(),
			'',
			array(
				'category' => 3,
				'tags'     => 'one, two',
				'author'   => 5,
			)
		);

		$this->assertGreaterThan( 0, $job_id );

		$rows    = Blogcraft_Queue::recent_jobs( 1 );
		$payload = json_decode( $rows[0]['payload'], true );

		$this->assertSame( 3, $payload['placement']['category'] );
		$this->assertSame( 'one, two', $payload['placement']['tags'] );
	}

	public function test_a_post_with_no_placement_still_queues() {
		$this->assertGreaterThan( 0, Blogcraft_Pipeline::enqueue_topic( 'something else', 'draft' ) );
	}

	// ------------------------------------------- the order of the asking.

	/**
	 * The provider card, rendered.
	 *
	 * @return string
	 */
	private function settings_html() {
		Blogcraft_Capabilities::add();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		Blogcraft_Connection::render();

		return (string) ob_get_clean();
	}

	public function test_the_key_is_asked_for_before_the_model() {
		// The model list is read from the account, so there is nothing to
		// offer until a key exists. Asking for the model first meant an empty
		// box above a button that could only fail, and typing an id from
		// memory is the commonest way to end up with a setup that looks
		// finished and errors on the first post.
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_api_key', 'a-real-key' );
		Blogcraft_Settings::set( 'provider_key_owner', 'openai' );

		$html = $this->settings_html();

		$key   = strpos( $html, 'blogcraft_provider_api_key' );
		$model = strpos( $html, 'blogcraft_provider_model' );

		$this->assertNotFalse( $key );
		$this->assertNotFalse( $model );
		$this->assertLessThan( $model, $key, 'the model is asked for above the key it depends on' );
	}

	public function test_no_model_field_is_offered_until_there_is_a_key() {
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_api_key', '' );

		$html = $this->settings_html();

		$this->assertStringNotContainsString( 'blogcraft_provider_model', $html );
		$this->assertStringContainsString( 'bc-await-key', $html, 'nothing explains why the model field is absent' );
	}

	public function test_a_keyless_provider_is_not_made_to_wait() {
		// Ollama and LM Studio run on your own machine and issue no keys, so
		// there is nothing to wait for.
		Blogcraft_Settings::set( 'provider_type', 'ollama' );
		Blogcraft_Settings::set( 'provider_api_key', '' );

		$html = $this->settings_html();

		$this->assertStringContainsString( 'blogcraft_provider_model', $html );
		$this->assertStringNotContainsString( 'bc-await-key', $html );
	}

	public function test_the_rest_of_the_screen_still_renders_without_a_key() {
		// Skipping the model rows must not skip the spending cap or any card
		// after this one.
		Blogcraft_Settings::set( 'provider_type', 'openai' );
		Blogcraft_Settings::set( 'provider_api_key', '' );

		$html = $this->settings_html();

		$this->assertStringContainsString( 'monthly_token_cap', $html );
		$this->assertStringContainsString( 'bc-card-research', $html );
		$this->assertStringContainsString( 'purge_on_delete', $html );
	}

	public function test_every_button_that_asks_the_provider_says_so_when_there_is_none() {
		// Four buttons across three screens call a provider: list the models
		// on this account, read my posts and describe my voice, read this
		// article and match its shape, and ask what I should write about this
		// topic. None of them checked first, so pressing one on a fresh
		// install returned whatever the HTTP layer said — "Request failed with
		// HTTP 401" — which is true, useless, and indistinguishable from the
		// plugin being broken.
		//
		// Named rather than detected, because three of the four reach the
		// provider through a helper and no honest static check can see that.
		$guarded = array(
			'class-blogcraft-connection.php'       => array( 'handle_list_models', 'handle_learn' ),
			'class-blogcraft-blueprint-screen.php' => array( 'handle_shape' ),
			'class-blogcraft-generate.php'         => array( 'handle_suggest' ),
		);

		foreach ( $guarded as $file => $handlers ) {
			$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			foreach ( $handlers as $handler ) {
				$at = strpos( $source, 'function ' . $handler . '(' );

				$this->assertNotFalse( $at, $handler . ' has gone; this list is stale' );

				$end  = strpos( $source, "
	}", $at );
				$body = substr( $source, $at, $end - $at );

				$this->assertStringContainsString(
					'Blogcraft_Request::require_provider()',
					$body,
					$handler . ' asks the provider without checking there is one'
				);
			}
		}
	}

	public function test_the_refusal_names_what_to_do_rather_than_an_http_code() {
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-request.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$at   = strpos( $source, 'function require_provider(' );
		$body = substr( $source, (int) $at );

		$this->assertStringContainsString( 'Connect a provider', $body, 'the refusal does not say where to go' );
	}
}
