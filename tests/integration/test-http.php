<?php
/**
 * HTTP transport tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Http extends WP_UnitTestCase {

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

	public function test_post_json_returns_decoded_body_on_success() {
		$this->fake_http( array( array( 'code' => 200, 'body' => '{"ok":true}' ) ) );
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array( 'a' => 1 ) );
		$this->assertSame( 200, $result['code'] );
		$this->assertSame( array( 'ok' => true ), $result['body'] );
		$this->assertSame( '', $result['error'] );
	}

	public function test_post_json_sets_an_explicit_timeout() {
		$this->fake_http( array( array( 'code' => 200, 'body' => '{}' ) ) );
		Blogcraft_Http::post_json( 'https://example.test/v1', array(), array(), 45 );
		$this->assertSame( 45, $this->requests[0]['args']['timeout'] );
	}

	public function test_post_json_sends_json_content_type_and_body() {
		$this->fake_http( array( array( 'code' => 200, 'body' => '{}' ) ) );
		Blogcraft_Http::post_json( 'https://example.test/v1', array( 'x' => 'y' ) );
		$args = $this->requests[0]['args'];
		$this->assertSame( 'application/json', $args['headers']['Content-Type'] );
		$this->assertSame( '{"x":"y"}', $args['body'] );
	}

	public function test_retries_on_429_then_succeeds() {
		$this->fake_http(
			array(
				array( 'code' => 429, 'body' => '{}', 'headers' => array( 'retry-after' => '0' ) ),
				array( 'code' => 200, 'body' => '{"ok":true}' ),
			)
		);
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertSame( 200, $result['code'] );
		$this->assertCount( 2, $this->requests );
	}

	public function test_retries_on_500_then_succeeds() {
		$this->fake_http(
			array(
				array( 'code' => 500, 'body' => 'server error' ),
				array( 'code' => 200, 'body' => '{"ok":true}' ),
			)
		);
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertSame( 200, $result['code'] );
	}

	public function test_does_not_retry_on_400() {
		$this->fake_http(
			array(
				array( 'code' => 400, 'body' => '{"error":{"message":"bad"}}' ),
				array( 'code' => 200, 'body' => '{"ok":true}' ),
			)
		);
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertSame( 400, $result['code'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_gives_up_after_max_attempts() {
		$this->fake_http(
			array(
				array( 'code' => 500, 'body' => 'a' ),
				array( 'code' => 500, 'body' => 'b' ),
				array( 'code' => 500, 'body' => 'c' ),
			)
		);
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertSame( 500, $result['code'] );
		$this->assertCount( Blogcraft_Http::MAX_ATTEMPTS, $this->requests );
		$this->assertNotSame( '', $result['error'] );
	}

	public function test_wp_error_is_reported_not_thrown() {
		$this->fake_http( array( array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ), array( 'wp_error' => 'dns failure' ) ) );
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertSame( 0, $result['code'] );
		$this->assertStringContainsString( 'dns failure', $result['error'] );
	}

	public function test_invalid_json_body_is_reported_as_error() {
		$this->fake_http( array( array( 'code' => 200, 'body' => 'not json' ) ) );
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( array(), $result['body'] );
	}

	public function test_bare_scalar_json_body_is_reported_as_error() {
		$this->fake_http( array( array( 'code' => 200, 'body' => '42' ) ) );
		$result = Blogcraft_Http::post_json( 'https://example.test/v1', array() );
		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( array(), $result['body'] );
	}

	public function test_retry_delay_clamps_a_large_retry_after() {
		$method = new ReflectionMethod( Blogcraft_Http::class, 'retry_delay' );
		$method->setAccessible( true );

		$this->assertSame( Blogcraft_Http::MAX_RETRY_AFTER_SECONDS, $method->invoke( null, '99999999', 1 ) );
		$this->assertSame( 2, $method->invoke( null, '2', 1 ) );
		$this->assertSame( 1, $method->invoke( null, '', 1 ) );
		$this->assertSame( 2, $method->invoke( null, '', 2 ) );
		$this->assertSame( 1, $method->invoke( null, 'not-a-number', 1 ) );
	}

	public function test_response_object_reports_error_state() {
		$ok = new Blogcraft_Provider_Response();
		$ok->text = 'hello';
		$this->assertFalse( $ok->is_error() );

		$bad = new Blogcraft_Provider_Response();
		$bad->error = 'boom';
		$this->assertTrue( $bad->is_error() );
	}

	public function test_response_totals_tokens() {
		$r                    = new Blogcraft_Provider_Response();
		$r->prompt_tokens     = 10;
		$r->completion_tokens = 5;
		$this->assertSame( 15, $r->total_tokens() );
	}
}
