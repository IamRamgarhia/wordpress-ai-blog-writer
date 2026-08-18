<?php
/**
 * Custom JSON-path provider adapter tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Provider_Custom extends WP_UnitTestCase {

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
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
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
			'endpoint'                => 'https://example.test/api/generate',
			'api_key'                 => 'secret-key',
			'model'                   => 'custom-model',
			'request_template'        => wp_json_encode(
				array(
					'model' => '{{model}}',
					'input' => '{{prompt}}',
				)
			),
			'text_path'               => 'output',
			'prompt_tokens_path'      => 'usage.prompt_tokens',
			'completion_tokens_path'  => 'usage.completion_tokens',
		);
		return new Blogcraft_Provider_Custom( array_merge( $defaults, $config ) );
	}

	private function success_body() {
		return wp_json_encode(
			array(
				'output' => 'hello world',
				'usage'  => array(
					'prompt_tokens'     => 12,
					'completion_tokens' => 34,
				),
			)
		);
	}

	// -- dig() -----------------------------------------------------------

	public function test_dig_walks_nested_arrays() {
		$data = array( 'a' => array( 'b' => array( 'c' => 'value' ) ) );
		$this->assertSame( 'value', Blogcraft_Provider_Custom::dig( $data, 'a.b.c' ) );
	}

	public function test_dig_walks_numeric_indices() {
		$data = array(
			'choices' => array(
				array( 'message' => array( 'content' => 'hi' ) ),
			),
		);
		$this->assertSame( 'hi', Blogcraft_Provider_Custom::dig( $data, 'choices.0.message.content' ) );
	}

	public function test_dig_missing_path_returns_null() {
		$data = array( 'a' => array( 'b' => 'x' ) );
		$this->assertNull( Blogcraft_Provider_Custom::dig( $data, 'a.c' ) );
	}

	public function test_dig_non_array_intermediate_returns_null() {
		$data = array( 'a' => 'scalar' );
		$this->assertNull( Blogcraft_Provider_Custom::dig( $data, 'a.b' ) );
	}

	public function test_dig_empty_path_returns_null() {
		$data = array( 'a' => 'x' );
		$this->assertNull( Blogcraft_Provider_Custom::dig( $data, '' ) );
	}

	// -- placeholder substitution -----------------------------------------

	public function test_prompt_with_quote_newline_and_backslash_survives_substitution() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );

		$template = wp_json_encode(
			array(
				'model' => '{{model}}',
				'input' => 'Prefix: {{prompt}} :Suffix',
			)
		);
		$provider = $this->make_provider( array( 'request_template' => $template ) );

		$tricky = "She said \"hi\"\nLine2\\end";
		$provider->complete( array( array( 'role' => 'user', 'content' => $tricky ) ) );

		// The raw body sent over the wire must be valid JSON (wp_json_encode
		// would have produced invalid output if we had substituted into the
		// raw template string instead of the decoded structure).
		$sent_body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertIsArray( $sent_body );
		$this->assertSame( 'Prefix: user: ' . $tricky . ' :Suffix', $sent_body['input'] );
	}

	public function test_model_placeholder_is_substituted() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider( array( 'model' => 'my-custom-model-id' ) );
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$sent_body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( 'my-custom-model-id', $sent_body['model'] );
	}

	public function test_invalid_json_template_returns_error_response_without_throwing() {
		$provider = $this->make_provider( array( 'request_template' => '{not valid json' ) );
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( array(), $this->requests );
	}

	// -- headers ------------------------------------------------------------

	public function test_custom_auth_header_and_prefix_are_used() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider(
			array(
				'auth_header' => 'X-Api-Key',
				'auth_prefix' => 'Token ',
				'api_key'     => 'secret123',
			)
		);
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( 'Token secret123', $headers['X-Api-Key'] );
		$this->assertArrayNotHasKey( 'Authorization', $headers );
	}

	public function test_default_auth_header_and_prefix() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider( array( 'api_key' => 'sk-default' ) );
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( 'Bearer sk-default', $headers['Authorization'] );
	}

	public function test_no_auth_header_when_api_key_empty() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider( array( 'api_key' => '' ) );
		$provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertArrayNotHasKey( 'Authorization', $headers );
	}

	// -- response parsing -----------------------------------------------------

	public function test_text_extracted_via_text_path() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider( array( 'text_path' => 'output' ) );
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( 'hello world', $response->text );
		$this->assertSame( 'custom-model', $response->model );
	}

	public function test_text_extracted_via_nested_text_path() {
		$body = wp_json_encode(
			array(
				'result' => array(
					'output' => array( 'text' => 'nested hello' ),
				),
			)
		);
		$this->fake_http( array( array( 'code' => 200, 'body' => $body ) ) );
		$provider = $this->make_provider( array( 'text_path' => 'result.output.text' ) );
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( 'nested hello', $response->text );
	}

	public function test_missing_text_path_produces_unexpected_shape_error() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider( array( 'text_path' => 'does.not.exist' ) );
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( '', $response->text );
	}

	public function test_token_paths_extracted() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertSame( 12, $response->prompt_tokens );
		$this->assertSame( 34, $response->completion_tokens );
	}

	public function test_token_paths_default_to_zero_when_absent() {
		$this->fake_http( array( array( 'code' => 200, 'body' => $this->success_body() ) ) );
		$provider = $this->make_provider(
			array(
				'prompt_tokens_path'     => 'does.not.exist',
				'completion_tokens_path' => 'also.missing',
			)
		);
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertFalse( $response->is_error() );
		$this->assertSame( 0, $response->prompt_tokens );
		$this->assertSame( 0, $response->completion_tokens );
	}

	public function test_error_message_body_with_non_2xx_surfaces_provider_message() {
		$body = wp_json_encode( array( 'error' => array( 'message' => 'bad request shape' ) ) );
		$this->fake_http( array( array( 'code' => 400, 'body' => $body ) ) );
		$provider = $this->make_provider();
		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertSame( 'HTTP 400: bad request shape', $response->error );
	}

	public function test_id_and_label() {
		$provider = $this->make_provider();
		$this->assertSame( 'custom', $provider->id() );
		$this->assertNotSame( '', $provider->label() );
	}

	public function test_list_models_returns_empty_array() {
		$provider = $this->make_provider();
		$this->assertSame( array(), $provider->list_models() );
	}
}
