<?php
/**
 * Gemini and Anthropic provider adapter tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Provider_Gemini_Anthropic extends WP_UnitTestCase {

	private $requests = array();

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		$this->requests = array();
	}

	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Queue canned responses returned in order.
	 *
	 * @param array $responses Each: array( 'code' => int, 'body' => string, 'headers' => array ).
	 * @return void
	 */
	private function fake_http( $responses ) {
		$queue = $responses;
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) use ( &$queue ) {
				$this->requests[] = array( 'url' => $url, 'args' => $args );
				$next = array_shift( $queue );
				if ( null === $next ) {
					return new WP_Error( 'http_request_failed', 'queue exhausted' );
				}
				if ( isset( $next['wp_error'] ) ) {
					return new WP_Error( 'http_request_failed', $next['wp_error'] );
				}
				return array(
					'response' => array( 'code' => $next['code'] ),
					'body'     => $next['body'],
					'headers'  => isset( $next['headers'] ) ? $next['headers'] : array(),
				);
			},
			10,
			3
		);
	}

	/*
	 * -----------------------------------------------------------------
	 * Gemini
	 * -----------------------------------------------------------------
	 */

	private function make_gemini( $config = array() ) {
		$defaults = array(
			'base_url' => 'https://example.test/v1beta',
			'api_key'  => 'gm-test-key',
			'model'    => 'gemini-1.5-pro',
		);
		return new Blogcraft_Provider_Gemini( array_merge( $defaults, $config ) );
	}

	private function gemini_success_body() {
		return wp_json_encode(
			array(
				'candidates'    => array(
					array(
						'content'      => array(
							'parts' => array( array( 'text' => 'hello world' ) ),
						),
						'finishReason' => 'STOP',
					),
				),
				'usageMetadata' => array(
					'promptTokenCount'     => 12,
					'candidatesTokenCount' => 34,
				),
			)
		);
	}

	public function test_gemini_successful_completion_maps_fields() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->gemini_success_body() ) ) );
		$provider = $this->make_gemini();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( 'hello world', $response->text );
		$this->assertSame( 'gemini-1.5-pro', $response->model );
		$this->assertSame( 'STOP', $response->finish_reason );
		$this->assertSame( 12, $response->prompt_tokens );
		$this->assertSame( 34, $response->completion_tokens );
	}

	public function test_gemini_key_travels_in_a_header_not_the_query_string() {
		// The reverse of what this test used to assert. Gemini accepts the key
		// either way, and the query string is the way that gets written down:
		// proxies, load balancers and access logs record a URL as a matter of
		// course, so the key ended up in plain text in files nobody thinks of
		// as holding secrets. The image route already made this choice.
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->gemini_success_body() ) ) );
		$provider = $this->make_gemini();
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertStringNotContainsString( 'gm-test-key', $this->requests[0]['url'] );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertArrayHasKey( 'x-goog-api-key', $headers );
		$this->assertSame( 'gm-test-key', $headers['x-goog-api-key'] );
	}

	public function test_gemini_endpoint_targets_generate_content_for_configured_model() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->gemini_success_body() ) ) );
		$provider = $this->make_gemini( array( 'base_url' => 'https://example.test/v1beta/' ) );
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$url = $this->requests[0]['url'];
		$this->assertSame( 'https://example.test/v1beta/models/gemini-1.5-pro:generateContent', $url );
	}

	public function test_gemini_assistant_role_maps_to_model() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->gemini_success_body() ) ) );
		$provider = $this->make_gemini();
		$provider->complete(
			array(
				array( 'role' => 'user', 'content' => 'hi' ),
				array( 'role' => 'assistant', 'content' => 'hello' ),
			)
		);

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 'user', $body['contents'][0]['role'] );
		$this->assertSame( 'model', $body['contents'][1]['role'] );
	}

	public function test_gemini_system_message_becomes_system_instruction_and_is_absent_from_contents() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->gemini_success_body() ) ) );
		$provider = $this->make_gemini();
		$provider->complete(
			array(
				array( 'role' => 'system', 'content' => 'be nice' ),
				array( 'role' => 'user', 'content' => 'hi' ),
			)
		);

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 'be nice', $body['system_instruction']['parts'][0]['text'] );
		$this->assertCount( 1, $body['contents'] );
		$this->assertSame( 'user', $body['contents'][0]['role'] );
	}

	public function test_gemini_multiple_system_messages_are_concatenated() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->gemini_success_body() ) ) );
		$provider = $this->make_gemini();
		$provider->complete(
			array(
				array( 'role' => 'system', 'content' => 'first' ),
				array( 'role' => 'system', 'content' => 'second' ),
				array( 'role' => 'user', 'content' => 'hi' ),
			)
		);

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( "first\n\nsecond", $body['system_instruction']['parts'][0]['text'] );
	}

	public function test_gemini_json_mode_sets_response_mime_type() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->gemini_success_body() ) ) );
		$provider = $this->make_gemini();
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ), array( 'json_mode' => true ) );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 'application/json', $body['generationConfig']['response_mime_type'] );
	}

	public function test_gemini_generation_config_omitted_when_no_options() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->gemini_success_body() ) ) );
		$provider = $this->make_gemini();
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'generationConfig', $body );
	}

	public function test_gemini_max_tokens_and_temperature_map_into_generation_config() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->gemini_success_body() ) ) );
		$provider = $this->make_gemini();
		$provider->complete(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			array(
				'max_tokens'  => 256,
				'temperature' => 0.5,
			)
		);

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 256, $body['generationConfig']['maxOutputTokens'] );
		$this->assertSame( 0.5, $body['generationConfig']['temperature'] );
	}

	public function test_gemini_error_message_body_surfaces_on_non_2xx() {
		// Non-2xx status proves the precedence: the provider's error.message
		// must win over Blogcraft_Http's generic "Request failed with HTTP N."
		$body = wp_json_encode( array( 'error' => array( 'message' => 'API key not valid' ) ) );
		$this->fake_http( array( array( 'code' => 400, 'body' => $body ) ) );
		$provider = $this->make_gemini();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'HTTP 400: API key not valid', $response->error );
	}

	public function test_gemini_error_does_not_leak_api_key() {
		$body = wp_json_encode( array( 'error' => array( 'message' => 'API key not valid' ) ) );
		$this->fake_http( array( array( 'code' => 400, 'body' => $body ) ) );
		$provider = $this->make_gemini();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertStringNotContainsString( 'gm-test-key', $response->error );
	}

	/**
	 * Regression test for the key-in-URL log leak: a failed Gemini request
	 * carries the API key in its query string, and Blogcraft_Http used to log
	 * the raw request URL on every failure. This drives a real failing
	 * request through the real Blogcraft_Http -> Blogcraft_Logger path (no
	 * mocking of the logger itself) and inspects what actually landed in the
	 * log table, because a unit test on the redaction helper alone would not
	 * catch a call site that forgot to use it.
	 */
	public function test_gemini_failed_request_does_not_leak_api_key_into_log_table() {
		$body = wp_json_encode( array( 'error' => array( 'message' => 'API key not valid' ) ) );
		$this->fake_http( array( array( 'code' => 400, 'body' => $body ) ) );
		$provider = $this->make_gemini( array( 'api_key' => 'SECRET-LIVE-KEY' ) );
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );

		$rows = Blogcraft_Logger::recent( 10 );
		$this->assertNotEmpty( $rows, 'Expected the failed request to have logged something.' );

		foreach ( $rows as $row ) {
			$this->assertStringNotContainsString( 'SECRET-LIVE-KEY', wp_json_encode( $row ) );
		}
	}

	public function test_gemini_unexpected_shape_produces_error_not_empty_success() {
		$body = wp_json_encode( array( 'unexpected' => 'shape' ) );
		$this->fake_http( array( array( 'code' => 200, 'body' => $body ) ) );
		$provider = $this->make_gemini();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( '', $response->text );
	}

	public function test_gemini_transport_error_is_reported_and_does_not_throw() {
		$this->fake_http( array( array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ) ) );
		$provider = $this->make_gemini();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertStringContainsString( 'dns failure', $response->error );
	}

	public function test_gemini_list_models_strips_models_prefix_and_sorts() {
		$body = wp_json_encode(
			array(
				'models' => array(
					array( 'name' => 'models/gemini-1.5-pro' ),
					array( 'name' => 'models/gemini-1.0-pro' ),
				),
			)
		);
		$this->fake_http( array( array( 'code' => 200, 'body' => $body ) ) );
		$provider = $this->make_gemini();
		$models   = $provider->list_models();

		$this->assertSame( array( 'gemini-1.0-pro', 'gemini-1.5-pro' ), $models );
	}

	public function test_gemini_list_models_key_travels_in_a_header() {
		$body = wp_json_encode( array( 'models' => array() ) );
		$this->fake_http( array( array( 'code' => 200, 'body' => $body ) ) );
		$provider = $this->make_gemini( array( 'base_url' => 'https://example.test/v1beta/' ) );
		$provider->list_models();

		$this->assertSame( 'https://example.test/v1beta/models', $this->requests[0]['url'] );
		$this->assertSame( 'gm-test-key', $this->requests[0]['args']['headers']['x-goog-api-key'] );
		$this->assertSame( 'GET', $this->requests[0]['args']['method'] );
	}

	public function test_gemini_list_models_returns_empty_array_on_failure() {
		$this->fake_http( array( array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ) ) );
		$provider = $this->make_gemini();
		$models   = $provider->list_models();

		$this->assertSame( array(), $models );
	}

	public function test_gemini_capabilities_reports_json_mode_true() {
		$provider     = $this->make_gemini();
		$capabilities = $provider->capabilities();

		$this->assertTrue( $capabilities['json_mode'] );
	}

	public function test_gemini_id_and_label() {
		$provider = $this->make_gemini();
		$this->assertSame( 'gemini', $provider->id() );
		$this->assertNotSame( '', $provider->label() );
	}

	/*
	 * -----------------------------------------------------------------
	 * Anthropic
	 * -----------------------------------------------------------------
	 */

	private function make_anthropic( $config = array() ) {
		$defaults = array(
			'base_url' => 'https://example.test/v1',
			'api_key'  => 'sk-ant-test',
			'model'    => 'claude-test',
		);
		return new Blogcraft_Provider_Anthropic( array_merge( $defaults, $config ) );
	}

	private function anthropic_success_body() {
		return wp_json_encode(
			array(
				'model'       => 'claude-test-actual',
				'content'     => array( array( 'text' => 'hello world' ) ),
				'stop_reason' => 'end_turn',
				'usage'       => array(
					'input_tokens'  => 12,
					'output_tokens' => 34,
				),
			)
		);
	}

	public function test_anthropic_successful_completion_maps_fields() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->anthropic_success_body() ) ) );
		$provider = $this->make_anthropic();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( 'hello world', $response->text );
		$this->assertSame( 'claude-test-actual', $response->model );
		$this->assertSame( 'end_turn', $response->finish_reason );
		$this->assertSame( 12, $response->prompt_tokens );
		$this->assertSame( 34, $response->completion_tokens );
	}

	public function test_anthropic_auth_and_version_headers_sent() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->anthropic_success_body() ) ) );
		$provider = $this->make_anthropic( array( 'api_key' => 'sk-ant-abc123' ) );
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( 'sk-ant-abc123', $headers['x-api-key'] );
		$this->assertSame( '2023-06-01', $headers['anthropic-version'] );
	}

	public function test_anthropic_no_auth_header_when_key_empty() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->anthropic_success_body() ) ) );
		$provider = $this->make_anthropic( array( 'api_key' => '' ) );
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertArrayNotHasKey( 'x-api-key', $headers );
	}

	public function test_anthropic_system_messages_land_in_top_level_field_not_messages() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->anthropic_success_body() ) ) );
		$provider = $this->make_anthropic();
		$provider->complete(
			array(
				array( 'role' => 'system', 'content' => 'be nice' ),
				array( 'role' => 'user', 'content' => 'hi' ),
			)
		);

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 'be nice', $body['system'] );
		$this->assertCount( 1, $body['messages'] );
		$this->assertSame( 'user', $body['messages'][0]['role'] );
	}

	public function test_anthropic_multiple_system_messages_are_concatenated() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->anthropic_success_body() ) ) );
		$provider = $this->make_anthropic();
		$provider->complete(
			array(
				array( 'role' => 'system', 'content' => 'first' ),
				array( 'role' => 'system', 'content' => 'second' ),
				array( 'role' => 'user', 'content' => 'hi' ),
			)
		);

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( "first\n\nsecond", $body['system'] );
	}

	public function test_anthropic_system_field_omitted_when_no_system_messages() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->anthropic_success_body() ) ) );
		$provider = $this->make_anthropic();
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'system', $body );
	}

	public function test_anthropic_max_tokens_defaults_to_4096_when_omitted() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->anthropic_success_body() ) ) );
		$provider = $this->make_anthropic();
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 4096, $body['max_tokens'] );
	}

	public function test_anthropic_max_tokens_overridden_when_supplied() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->anthropic_success_body() ) ) );
		$provider = $this->make_anthropic();
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ), array( 'max_tokens' => 512 ) );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 512, $body['max_tokens'] );
	}

	public function test_anthropic_temperature_forwarded_when_supplied() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->anthropic_success_body() ) ) );
		$provider = $this->make_anthropic();
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ), array( 'temperature' => 0.5 ) );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 0.5, $body['temperature'] );
	}

	public function test_anthropic_error_message_body_surfaces_on_non_2xx() {
		$body = wp_json_encode( array( 'error' => array( 'message' => 'invalid x-api-key' ) ) );
		$this->fake_http( array( array( 'code' => 401, 'body' => $body ) ) );
		$provider = $this->make_anthropic();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'HTTP 401: invalid x-api-key', $response->error );
	}

	public function test_anthropic_unexpected_shape_produces_error_not_empty_success() {
		$body = wp_json_encode( array( 'unexpected' => 'shape' ) );
		$this->fake_http( array( array( 'code' => 200, 'body' => $body ) ) );
		$provider = $this->make_anthropic();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( '', $response->text );
	}

	public function test_anthropic_transport_error_is_reported_and_does_not_throw() {
		$this->fake_http( array( array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ) ) );
		$provider = $this->make_anthropic();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertStringContainsString( 'dns failure', $response->error );
	}

	public function test_anthropic_list_models_returns_empty_array() {
		$provider = $this->make_anthropic();
		$this->assertSame( array(), $provider->list_models() );
	}

	public function test_anthropic_capabilities_reports_json_mode_false() {
		$provider     = $this->make_anthropic();
		$capabilities = $provider->capabilities();

		$this->assertFalse( $capabilities['json_mode'] );
	}

	public function test_anthropic_id_and_label() {
		$provider = $this->make_anthropic();
		$this->assertSame( 'anthropic', $provider->id() );
		$this->assertNotSame( '', $provider->label() );
	}
}
