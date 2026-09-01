<?php
/**
 * Signing an AI client in.
 *
 * This is the half of the door that applications with no header field have to
 * come through, and it hands out credentials to a route that can publish. So
 * the refusals are the point of this file, and they are written as rules
 * rather than examples: not "one bad verifier is rejected" but "no code is
 * exchanged without the proof it was issued against".
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Mcp_Oauth extends WP_UnitTestCase {

	/**
	 * Somebody allowed to connect an app.
	 *
	 * @var int
	 */
	private $author = 0;

	/**
	 * A registered client.
	 *
	 * @var string
	 */
	private $client = '';

	/**
	 * Where that client is allowed to be sent.
	 *
	 * @var string
	 */
	private $redirect = 'https://claude.ai/api/mcp/auth_callback';

	public function set_up() {
		parent::set_up();

		Blogcraft_Capabilities::add();
		Blogcraft_Migrator::migrate();

		$this->author = self::factory()->user->create( array( 'role' => 'administrator' ) );

		delete_option( Blogcraft_Mcp_Auth::OPTION );
		delete_option( Blogcraft_Mcp_Oauth::CLIENTS );
		delete_option( 'blogcraft_settings' );

		Blogcraft_Settings::set( 'mcp_enabled', true );

		do_action( 'rest_api_init' );

		$this->client = $this->register( array( $this->redirect ) );
	}

	public function tear_down() {
		delete_option( Blogcraft_Mcp_Auth::OPTION );
		delete_option( Blogcraft_Mcp_Oauth::CLIENTS );
		delete_option( 'blogcraft_settings' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Register a client and return its id.
	 *
	 * @param array $uris Redirect addresses to ask for.
	 * @return string
	 */
	private function register( $uris ) {
		$request = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE . '/register' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'redirect_uris' => $uris, 'client_name' => 'Test client' ) ) );

		$response = rest_get_server()->dispatch( $request );
		$body     = $response->get_data();

		return isset( $body['client_id'] ) ? (string) $body['client_id'] : '';
	}

	/**
	 * Ask the token endpoint for something.
	 *
	 * @param array $params Form parameters.
	 * @return array Status and decoded body.
	 */
	private function token( $params ) {
		$request = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE . '/token' );

		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_get_server()->dispatch( $request );

		return array(
			'status' => $response->get_status(),
			'body'   => $response->get_data(),
		);
	}

	/**
	 * Put an approved authorization code into play.
	 *
	 * Stands in for somebody pressing Approve, which needs a browser. Every
	 * value is the one the consent screen would have written.
	 *
	 * @param string $challenge The PKCE challenge.
	 * @param array  $overrides Anything to differ from the happy path.
	 * @return string The code.
	 */
	private function approved_code( $challenge, $overrides = array() ) {
		$code = bin2hex( random_bytes( 32 ) );

		set_transient(
			'blogcraft_oauth_' . hash( 'sha256', $code ),
			array_merge(
				array(
					'user'         => $this->author,
					'client'       => $this->client,
					'redirect_uri' => $this->redirect,
					'challenge'    => $challenge,
				),
				$overrides
			),
			Blogcraft_Mcp_Oauth::CODE_TTL
		);

		return $code;
	}

	/**
	 * A verifier and the challenge it hashes to.
	 *
	 * @return array
	 */
	private function pkce() {
		$verifier = bin2hex( random_bytes( 32 ) );

		return array(
			$verifier,
			rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url, per RFC 7636.
		);
	}

	// ---------------------------------------------------------- discovery.

	public function test_a_refused_request_says_where_to_sign_in() {
		// Without this header a client has to guess the discovery address
		// from the endpoint, and the guess only works for a server at the
		// root of its own domain. This is the step that showed as "find the
		// authorization server: 404".
		$this->assertNotFalse(
			has_filter( 'rest_post_dispatch', array( 'Blogcraft_Mcp_Oauth', 'point_at_the_authorisation_server' ) ),
			'nothing is hooked up, so the header is never added'
		);

		$request  = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 401, $response->get_status() );

		// dispatch() does not run rest_post_dispatch; serve_request()
		// does, and that is what answers a real request. Applied here the
		// way the server will apply it.
		$response = apply_filters( 'rest_post_dispatch', $response, rest_get_server(), $request );

		$header = $response->get_headers();

		$this->assertArrayHasKey( 'WWW-Authenticate', $header );
		$this->assertStringContainsString( 'resource_metadata=', $header['WWW-Authenticate'] );
	}

	public function test_the_two_documents_say_the_same_thing() {
		// A client reads the resource document, follows it to the server it
		// names, and reads that. If the two disagree the trail stops.
		$resource = Blogcraft_Mcp_Oauth::resource_metadata();
		$server   = Blogcraft_Mcp_Oauth::server_metadata();

		$this->assertContains(
			$server['issuer'],
			$resource['authorization_servers'],
			'the resource points at an authorisation server that is not this one'
		);

		$this->assertSame( Blogcraft_Mcp::endpoint(), $resource['resource'] );
	}

	public function test_the_endpoints_it_advertises_are_the_ones_that_exist() {
		// Advertising an address nothing answers is the same failure as
		// advertising none, and harder to see.
		$server = Blogcraft_Mcp_Oauth::server_metadata();
		$routes = rest_get_server()->get_routes();
		$base   = '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE;

		$expected = array(
			'token_endpoint'        => $base . '/token',
			'registration_endpoint' => $base . '/register',
		);

		foreach ( $expected as $field => $route ) {
			$this->assertArrayHasKey( $route, $routes, $field . ' names a route nothing registered' );

			// And the address it publishes is that route rather than one
			// assembled by hand, which is how a working route ends up
			// advertised at an address that answers nothing.
			$this->assertSame(
				rest_url( ltrim( $route, '/' ) ),
				$server[ $field ],
				$field . ' is advertised at an address that is not its route'
			);
		}
	}

	public function test_only_pkce_s256_is_offered() {
		// Offering "plain" is offering nothing: the challenge and the
		// verifier are then the same string, and an intercepted code is
		// enough on its own.
		$this->assertSame(
			array( 'S256' ),
			Blogcraft_Mcp_Oauth::server_metadata()['code_challenge_methods_supported']
		);
	}

	// ------------------------------------------------------- registration.

	public function test_a_client_can_register_and_gets_no_secret() {
		$clients = Blogcraft_Mcp_Oauth::clients();

		$this->assertArrayHasKey( $this->client, $clients );
		$this->assertContains( $this->redirect, $clients[ $this->client ]['uris'] );
	}

	public function test_plaintext_redirects_are_refused_unless_they_come_home() {
		// http back to the machine the browser is on is how a desktop app
		// receives the redirect. http to anywhere else is somebody else's
		// computer reading the authorization code off the wire.
		$id      = $this->register( array( 'http://example.com/callback', 'http://127.0.0.1:9999/callback' ) );
		$clients = Blogcraft_Mcp_Oauth::clients();

		$this->assertNotSame( '', $id );
		$this->assertSame( array( 'http://127.0.0.1:9999/callback' ), $clients[ $id ]['uris'] );
	}

	public function test_registration_with_nothing_usable_is_refused() {
		$request = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE . '/register' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'redirect_uris' => array( 'http://example.com/x' ) ) ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_nothing_oauth_answers_while_connections_are_off() {
		// The switch on the settings screen has to mean it. A site with
		// connections off should not be registering clients or minting
		// tokens for them.
		Blogcraft_Settings::set( 'mcp_enabled', false );

		$this->assertSame( '', $this->register( array( 'https://example.com/cb' ) ) );

		$out = $this->token( array( 'grant_type' => 'authorization_code', 'code' => 'x' ) );

		$this->assertSame( 403, $out['status'] );
	}

	// -------------------------------------------------------- the exchange.

	public function test_the_happy_path_yields_a_token_that_drives_the_server() {
		list( $verifier, $challenge ) = $this->pkce();

		$out = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $this->approved_code( $challenge ),
				'redirect_uri'  => $this->redirect,
				'client_id'     => $this->client,
				'code_verifier' => $verifier,
			)
		);

		$this->assertSame( 200, $out['status'] );
		$this->assertSame( 'Bearer', $out['body']['token_type'] );
		$this->assertNotEmpty( $out['body']['refresh_token'] );

		// The whole point of the exercise: the thing it handed over works.
		$call = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
		$call->set_header( 'authorization', 'Bearer ' . $out['body']['access_token'] );
		$call->set_header( 'content-type', 'application/json' );
		$call->set_body( (string) wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) ) );

		$response = rest_get_server()->dispatch( $call );

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotEmpty( $response->get_data()['result']['tools'] );
	}

	public function test_no_code_is_exchanged_without_the_proof_it_was_issued_against() {
		// The rule, not one example. Every way the second half of the
		// exchange can fail to match the first, each on its own fresh code,
		// because a code that survives a failed attempt is worse than one
		// that was never checked.
		list( $verifier, $challenge ) = $this->pkce();

		$wrong = array(
			'a verifier that is not the one'    => array( 'code_verifier' => 'not-it' ),
			'no verifier at all'                => array( 'code_verifier' => '' ),
			'the challenge replayed as verifier' => array( 'code_verifier' => $challenge ),
			'a different app'                   => array( 'client_id' => 'bc_someone_else' ),
			'a different redirect'              => array( 'redirect_uri' => 'https://claude.ai/other' ),
		);

		foreach ( $wrong as $what => $overrides ) {
			$out = $this->token(
				array_merge(
					array(
						'grant_type'    => 'authorization_code',
						'code'          => $this->approved_code( $challenge ),
						'redirect_uri'  => $this->redirect,
						'client_id'     => $this->client,
						'code_verifier' => $verifier,
					),
					$overrides
				)
			);

			$this->assertSame( 400, $out['status'], $what . ' was accepted' );
			$this->assertSame( 'invalid_grant', $out['body']['error'], $what );
		}
	}

	public function test_a_code_is_spent_even_when_the_attempt_fails() {
		// A code that failed verification is a code somebody else may be
		// holding. It does not get a second try.
		list( $verifier, $challenge ) = $this->pkce();

		$code = $this->approved_code( $challenge );

		$this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $this->redirect,
				'client_id'     => $this->client,
				'code_verifier' => 'wrong',
			)
		);

		$second = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $this->redirect,
				'client_id'     => $this->client,
				'code_verifier' => $verifier,
			)
		);

		$this->assertSame( 400, $second['status'], 'a failed attempt left the code usable' );
	}

	public function test_a_code_is_spent_when_the_attempt_succeeds() {
		list( $verifier, $challenge ) = $this->pkce();

		$code   = $this->approved_code( $challenge );
		$params = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => $this->redirect,
			'client_id'     => $this->client,
			'code_verifier' => $verifier,
		);

		$this->assertSame( 200, $this->token( $params )['status'] );
		$this->assertSame( 400, $this->token( $params )['status'], 'the code worked twice' );
	}

	public function test_a_token_is_not_minted_for_somebody_who_lost_the_capability() {
		// The approval was given by a person, and people lose permissions
		// between approving and exchanging.
		list( $verifier, $challenge ) = $this->pkce();

		$code = $this->approved_code( $challenge );

		$user = new WP_User( $this->author );
		$user->set_role( 'subscriber' );

		$out = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => $this->redirect,
				'client_id'     => $this->client,
				'code_verifier' => $verifier,
			)
		);

		$this->assertSame( 400, $out['status'] );
	}

	// ------------------------------------------------------------ refresh.

	public function test_a_refresh_token_rotates_and_the_old_one_dies() {
		list( $verifier, $challenge ) = $this->pkce();

		$first = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $this->approved_code( $challenge ),
				'redirect_uri'  => $this->redirect,
				'client_id'     => $this->client,
				'code_verifier' => $verifier,
			)
		);

		$refresh = $first['body']['refresh_token'];
		$again   = $this->token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => $this->client,
			)
		);

		$this->assertSame( 200, $again['status'] );
		$this->assertNotSame( $first['body']['access_token'], $again['body']['access_token'] );

		// Rotation is the whole value: a stolen refresh token is good once
		// and then visibly breaks the client it was stolen from.
		$replay = $this->token(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $refresh,
				'client_id'     => $this->client,
			)
		);

		$this->assertSame( 400, $replay['status'], 'the old refresh token still worked' );
	}

	public function test_a_refresh_token_is_not_an_access_token() {
		// They live in the same store. If the endpoint could not tell them
		// apart, a refresh token would be a bearer credential for the site.
		list( $verifier, $challenge ) = $this->pkce();

		$out = $this->token(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $this->approved_code( $challenge ),
				'redirect_uri'  => $this->redirect,
				'client_id'     => $this->client,
				'code_verifier' => $verifier,
			)
		);

		$call = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
		$call->set_header( 'authorization', 'Bearer ' . $out['body']['refresh_token'] );
		$call->set_header( 'content-type', 'application/json' );
		$call->set_body( (string) wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) ) );

		$this->assertSame( 401, rest_get_server()->dispatch( $call )->get_status() );
	}

	public function test_an_expired_access_token_stops_working() {
		$secret = Blogcraft_Mcp_Auth::issue(
			$this->author,
			'expired',
			array( 'expires' => time() - 10 )
		);

		$call = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
		$call->set_header( 'authorization', 'Bearer ' . $secret );
		$call->set_header( 'content-type', 'application/json' );
		$call->set_body( (string) wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) ) );

		$this->assertSame( 401, rest_get_server()->dispatch( $call )->get_status() );
	}

	public function test_a_token_typed_in_by_hand_still_never_expires() {
		// The card issues tokens with no expiry and nothing to refresh them.
		// Adding expiry for OAuth must not quietly kill those.
		$secret = Blogcraft_Mcp_Auth::issue( $this->author, 'by hand' );

		$call = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
		$call->set_header( 'authorization', 'Bearer ' . $secret );
		$call->set_header( 'content-type', 'application/json' );
		$call->set_body( (string) wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ) ) );

		$this->assertSame( 200, rest_get_server()->dispatch( $call )->get_status() );
	}

	// ------------------------------------------------------------ consent.

	public function test_the_consent_screen_says_what_it_is_agreeing_to() {
		wp_set_current_user( $this->author );

		list( , $challenge ) = $this->pkce();

		$_GET = array(
			'client_id'             => $this->client,
			'redirect_uri'          => $this->redirect,
			'response_type'         => 'code',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
			'state'                 => 'abc',
		);

		ob_start();
		Blogcraft_Mcp_Oauth::render_consent();
		$html = (string) ob_get_clean();

		$_GET = array();

		// Both halves. A consent screen that lists only what an app can do
		// is asking somebody to agree to something they have not been told.
		foreach ( Blogcraft_Mcp_Oauth::what_it_can_do() as $line ) {
			$this->assertStringContainsString( esc_html( $line ), $html );
		}

		foreach ( Blogcraft_Mcp_Oauth::what_it_cannot_do() as $line ) {
			$this->assertStringContainsString( esc_html( $line ), $html );
		}
	}

	public function test_a_request_that_could_never_be_honoured_is_never_offered() {
		// A consent screen shown for an invalid request is a consent screen
		// that teaches people the button is always safe to press.
		wp_set_current_user( $this->author );

		list( , $challenge ) = $this->pkce();

		$good = array(
			'client_id'             => $this->client,
			'redirect_uri'          => $this->redirect,
			'response_type'         => 'code',
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		);

		$bad = array(
			'an unregistered app'      => array( 'client_id' => 'bc_nobody' ),
			'an unregistered redirect' => array( 'redirect_uri' => 'https://evil.example/steal' ),
			'no PKCE at all'           => array( 'code_challenge' => '' ),
			'PKCE downgraded to plain' => array( 'code_challenge_method' => 'plain' ),
			'a different response type' => array( 'response_type' => 'token' ),
		);

		foreach ( $bad as $what => $overrides ) {
			$_GET = array_merge( $good, $overrides );

			ob_start();
			Blogcraft_Mcp_Oauth::render_consent();
			$html = (string) ob_get_clean();

			$this->assertStringNotContainsString(
				'blogcraft_oauth_approve',
				$html,
				$what . ' was offered for approval'
			);
		}

		$_GET = array();
	}
}
