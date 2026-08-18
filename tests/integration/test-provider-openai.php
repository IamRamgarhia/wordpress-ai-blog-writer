<?php
/**
 * OpenAI-compatible provider adapter tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Provider_Openai extends WP_UnitTestCase {

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

	private function make_provider( $config = array() ) {
		$defaults = array(
			'base_url' => 'https://example.test/v1',
			'api_key'  => 'sk-test',
			'model'    => 'gpt-test',
		);
		return new Blogcraft_Provider_Openai( array_merge( $defaults, $config ) );
	}

	private function success_body() {
		return wp_json_encode(
			array(
				'model'   => 'gpt-test-actual',
				'choices' => array(
					array(
						'message'       => array( 'content' => 'hello world' ),
						'finish_reason' => 'stop',
					),
				),
				'usage'   => array(
					'prompt_tokens'     => 12,
					'completion_tokens' => 34,
				),
			)
		);
	}

	public function test_successful_completion_maps_fields() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( 'hello world', $response->text );
		$this->assertSame( 'gpt-test-actual', $response->model );
		$this->assertSame( 'stop', $response->finish_reason );
		$this->assertSame( 12, $response->prompt_tokens );
		$this->assertSame( 34, $response->completion_tokens );
	}

	public function test_missing_usage_yields_zero_tokens_and_no_error() {
		$body = wp_json_encode(
			array(
				'model'   => 'gpt-test-actual',
				'choices' => array(
					array(
						'message'       => array( 'content' => 'hello' ),
						'finish_reason' => 'stop',
					),
				),
			)
		);
		$this->fake_http( array( array( 'code' => 200, 'body' => $body ) ) );
		$provider = $this->make_provider();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( 0, $response->prompt_tokens );
		$this->assertSame( 0, $response->completion_tokens );
	}

	public function test_endpoint_has_exactly_one_slash_with_trailing_slash_base() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider( array( 'base_url' => 'https://example.test/v1/' ) );
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertSame( 'https://example.test/v1/chat/completions', $this->requests[0]['url'] );
	}

	public function test_endpoint_has_exactly_one_slash_without_trailing_slash_base() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider( array( 'base_url' => 'https://example.test/v1' ) );
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertSame( 'https://example.test/v1/chat/completions', $this->requests[0]['url'] );
	}

	public function test_authorization_header_sent_when_key_present() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider( array( 'api_key' => 'sk-abc123' ) );
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( 'Bearer sk-abc123', $headers['Authorization'] );
	}

	public function test_no_authorization_header_when_key_empty() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider( array( 'api_key' => '' ) );
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertArrayNotHasKey( 'Authorization', $headers );
	}

	public function test_custom_headers_are_merged() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider( array( 'headers' => array( 'HTTP-Referer' => 'https://myapp.test' ) ) );
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( 'https://myapp.test', $headers['HTTP-Referer'] );
	}

	public function test_json_mode_adds_response_format() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider();
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ), array( 'json_mode' => true ) );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( array( 'type' => 'json_object' ), $body['response_format'] );
	}

	public function test_response_format_absent_when_json_mode_not_requested() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider();
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'response_format', $body );
	}

	public function test_max_tokens_and_temperature_forwarded_when_supplied() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider();
		$provider->complete(
			array( array( 'role' => 'user', 'content' => 'hi' ) ),
			array(
				'max_tokens'  => 256,
				'temperature' => 0.5,
			)
		);

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 256, $body['max_tokens'] );
		$this->assertSame( 0.5, $body['temperature'] );
	}

	public function test_max_tokens_and_temperature_absent_when_not_supplied() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider();
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertArrayNotHasKey( 'max_tokens', $body );
		$this->assertArrayNotHasKey( 'temperature', $body );
	}

	public function test_error_message_body_produces_error_without_throwing() {
		// A 200 status carrying an application-level error object exercises the
		// "body contains error.message" precedence branch in isolation, since a
		// non-2xx status would already carry a non-empty Blogcraft_Http error
		// (a higher-precedence branch covered by test_http_level_error_takes_precedence_over_body_error_message).
		$body = wp_json_encode( array( 'error' => array( 'message' => 'invalid key' ) ) );
		$this->fake_http( array( array( 'code' => 200, 'body' => $body ) ) );
		$provider = $this->make_provider();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'invalid key', $response->error );
	}

	public function test_http_level_error_takes_precedence_over_body_error_message() {
		$body = wp_json_encode( array( 'error' => array( 'message' => 'invalid key' ) ) );
		$this->fake_http( array( array( 'code' => 401, 'body' => $body ) ) );
		$provider = $this->make_provider();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertStringContainsString( '401', $response->error );
	}

	public function test_unexpected_shape_200_produces_error_not_empty_success() {
		$body = wp_json_encode( array( 'unexpected' => 'shape' ) );
		$this->fake_http( array( array( 'code' => 200, 'body' => $body ) ) );
		$provider = $this->make_provider();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( '', $response->text );
	}

	public function test_transport_error_is_reported_and_does_not_throw() {
		$this->fake_http( array( array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ) ) );
		$provider = $this->make_provider();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertStringContainsString( 'dns failure', $response->error );
	}

	public function test_list_models_returns_ids_from_data() {
		$body = wp_json_encode(
			array(
				'data' => array(
					array( 'id' => 'model-b' ),
					array( 'id' => 'model-a' ),
				),
			)
		);
		$this->fake_http( array( array( 'code' => 200, 'body' => $body ) ) );
		$provider = $this->make_provider();
		$models   = $provider->list_models();

		$this->assertSame( array( 'model-a', 'model-b' ), $models );
	}

	public function test_list_models_get_request_targets_models_endpoint() {
		$body = wp_json_encode( array( 'data' => array() ) );
		$this->fake_http( array( array( 'code' => 200, 'body' => $body ) ) );
		$provider = $this->make_provider( array( 'base_url' => 'https://example.test/v1/' ) );
		$provider->list_models();

		$this->assertSame( 'https://example.test/v1/models', $this->requests[0]['url'] );
		$this->assertSame( 'GET', $this->requests[0]['args']['method'] );
	}

	public function test_list_models_returns_empty_array_on_failure() {
		$this->fake_http( array( array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ) ) );
		$provider = $this->make_provider();
		$models   = $provider->list_models();

		$this->assertSame( array(), $models );
	}

	public function test_capabilities_reports_json_mode_true() {
		$provider     = $this->make_provider();
		$capabilities = $provider->capabilities();

		$this->assertTrue( $capabilities['json_mode'] );
	}

	public function test_id_and_label() {
		$provider = $this->make_provider();
		$this->assertSame( 'openai', $provider->id() );
		$this->assertNotSame( '', $provider->label() );
	}
}
