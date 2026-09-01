<?php
/**
 * The MCP server.
 *
 * This is the one route in the plugin that a stranger can reach, and it can
 * create and publish posts. So the security cases come first and are written
 * as the rule rather than the example: not "a bad token is refused" but "no
 * request without a valid token reaches any tool".
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Mcp extends WP_UnitTestCase {

	/**
	 * A user who is allowed to write.
	 *
	 * @var int
	 */
	private $author = 0;

	/**
	 * Their connection token.
	 *
	 * @var string
	 */
	private $token = '';

	public function set_up() {
		parent::set_up();

		// Capability first, then the user: WP_User caches role capabilities
		// at construction, so a user made before the capability exists never
		// sees it.
		Blogcraft_Capabilities::add();

		// The navigation counts open jobs, so rendering the settings screen
		// queries a table that will not exist otherwise. The errors are
		// harmless and that is the problem: noise in the output is where a
		// real failure goes unnoticed.
		Blogcraft_Migrator::migrate();

		$this->author = self::factory()->user->create( array( 'role' => 'administrator' ) );

		delete_option( Blogcraft_Mcp_Auth::OPTION );
		delete_option( 'blogcraft_settings' );

		Blogcraft_Settings::set( 'mcp_enabled', true );

		$this->token = Blogcraft_Mcp_Auth::issue( $this->author, 'test' );

		do_action( 'rest_api_init' );
	}

	public function tear_down() {
		delete_option( Blogcraft_Mcp_Auth::OPTION );
		delete_option( 'blogcraft_settings' );
		Blogcraft_Capabilities::remove();
		wp_set_current_user( 0 );

		parent::tear_down();
	}

	/**
	 * Send one JSON-RPC request.
	 *
	 * @param string $method JSON-RPC method.
	 * @param array  $params Params.
	 * @param string $token  Token to present, or '' for none.
	 * @return array Decoded response body.
	 */
	private function rpc( $method, $params = array(), $token = null ) {
		$token = ( null === $token ) ? $this->token : $token;

		$request = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );

		if ( '' !== $token ) {
			$request->set_header( 'authorization', 'Bearer ' . $token );
		}

		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => $method,
					'params'  => $params,
				)
			)
		);

		$response = rest_get_server()->dispatch( $request );

		return array(
			'status' => $response->get_status(),
			'body'   => $response->get_data(),
		);
	}

	// ----------------------------------------------------------- the gate.

	public function test_no_method_is_reachable_without_a_token() {
		// The rule, not one example of it. Every method the server answers is
		// tried with no credential, so a method added later without a thought
		// for authentication fails here rather than in the wild.
		$methods = array( 'initialize', 'server/discover', 'tools/list', 'tools/call', 'resources/list', 'resources/read', 'ping' );

		foreach ( $methods as $method ) {
			$out = $this->rpc( $method, array(), '' );

			$this->assertSame( 401, $out['status'], $method . ' answered without a token' );
		}
	}

	public function test_the_advertised_address_is_the_one_that_answers() {
		// The bug this exists for: the route was registered under a
		// namespace with a bare '/' route, which lands it at a path ending
		// in a slash — and WordPress trims that slash out of the
		// ?rest_route= argument, so every site on plain permalinks got a
		// 404 from the address the settings screen told them to paste.
		//
		// The earlier tests built their own path and so agreed with the
		// mistake. This one takes the address the plugin advertises and
		// asks the server whether it serves it.
		$advertised = Blogcraft_Mcp::endpoint();
		$path       = (string) wp_parse_url( $advertised, PHP_URL_PATH );
		$route      = '/' . ltrim( str_replace( rest_get_url_prefix(), '', $path ), '/' );

		$this->assertStringNotContainsString( '//', ltrim( $route, '/' ), 'the advertised address has an empty path segment' );
		$this->assertSame( $route, untrailingslashit( $route ), 'the advertised address ends in a slash, which ?rest_route= will strip' );

		$registered = array_keys( rest_get_server()->get_routes() );

		$this->assertContains(
			'/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE,
			$registered,
			'nothing is registered at the address the plugin tells people to use'
		);
	}

	public function test_a_wrong_token_is_refused() {
		$out = $this->rpc( 'tools/list', array(), str_repeat( 'a', 64 ) );

		$this->assertSame( 401, $out['status'] );
	}

	public function test_nothing_answers_while_the_server_is_switched_off() {
		Blogcraft_Settings::set( 'mcp_enabled', false );

		$out = $this->rpc( 'server/discover' );

		// 404 rather than 401: a site that never switched this on should not
		// even confirm the route exists, let alone that a token was wrong.
		$this->assertSame( 404, $out['status'] );
	}

	public function test_a_revoked_token_stops_working() {
		$tokens = Blogcraft_Mcp_Auth::all();
		$this->assertCount( 1, $tokens );

		Blogcraft_Mcp_Auth::revoke( key( $tokens ) );

		$this->assertSame( 401, $this->rpc( 'tools/list' )['status'] );
	}

	public function test_a_token_dies_with_the_capability_that_issued_it() {
		// The failure this prevents: somebody is demoted, and a token they
		// generated months ago keeps publishing.
		$user = new WP_User( $this->author );
		$user->remove_role( 'administrator' );
		$user->add_role( 'subscriber' );

		$this->assertSame( 401, $this->rpc( 'tools/list' )['status'] );
	}

	public function test_the_stored_token_is_not_the_token() {
		$stored = (string) wp_json_encode( Blogcraft_Mcp_Auth::all() );

		$this->assertStringNotContainsString( $this->token, $stored, 'the token is stored in the clear' );
	}

	// ------------------------------------------------------- the protocol.

	public function test_discovery_names_the_protocol_and_the_server() {
		$out = $this->rpc( 'server/discover' );

		$this->assertSame( 200, $out['status'] );
		$this->assertSame( '2.0', $out['body']['jsonrpc'] );
		$this->assertContains( Blogcraft_Mcp::PROTOCOL, $out['body']['result']['supportedVersions'] );
		$this->assertArrayHasKey( 'tools', $out['body']['result']['capabilities'] );
	}

	public function test_a_version_we_do_not_know_still_gets_a_working_answer() {
		// This used to assert the opposite: an unrecognised version got
		// the whole request refused. That is the behaviour a client has
		// no answer to, and the test made it look deliberate.
		$out = $this->rpc(
			'tools/list',
			array(
				'_meta' => array( 'io.modelcontextprotocol/protocolVersion' => '1999-01-01' ),
			)
		);

		$this->assertArrayNotHasKey( 'error', $out['body'] );
		$this->assertNotEmpty( $out['body']['result']['tools'] );
	}

	public function test_an_unknown_method_is_a_json_rpc_error_not_a_crash() {
		$out = $this->rpc( 'nonsense/method' );

		$this->assertSame( 200, $out['status'] );
		$this->assertSame( Blogcraft_Mcp::METHOD_NOT_FOUND, $out['body']['error']['code'] );
	}

	public function test_every_tool_is_listed_with_a_schema() {
		$out   = $this->rpc( 'tools/list' );
		$tools = $out['body']['result']['tools'];

		$this->assertNotEmpty( $tools );

		foreach ( $tools as $tool ) {
			$this->assertNotSame( '', $tool['name'] );
			$this->assertNotSame( '', $tool['description'], $tool['name'] . ' has no description for the model to read' );
			$this->assertSame( 'object', $tool['inputSchema']['type'], $tool['name'] );
		}
	}

	// ----------------------------------------------------------- the work.

	public function test_the_scorecard_is_reachable_and_reports_failures() {
		$out = $this->rpc(
			'tools/call',
			array(
				'name'      => 'check_draft',
				'arguments' => array(
					'html'  => '<p>Too short to pass anything.</p>',
					'title' => 'A test post',
				),
			)
		);

		$text = $out['body']['result']['content'][0]['text'];

		$this->assertStringContainsString( 'Score:', $text );
		$this->assertStringContainsString( 'FAIL', $text, 'a three-word draft passed every check' );
		$this->assertStringContainsString( 'Fix these:', $text, 'failures came back with no instruction' );
	}

	public function test_a_draft_can_be_created_and_is_not_published() {
		$out = $this->rpc(
			'tools/call',
			array(
				'name'      => 'create_draft',
				'arguments' => array(
					'title' => 'Written over MCP',
					'html'  => '<h2>A heading</h2><p>Some words in a paragraph.</p>',
				),
			)
		);

		$this->assertFalse( $out['body']['result']['isError'] );

		$posts = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'draft',
				'title'       => 'Written over MCP',
			)
		);

		$this->assertCount( 1, $posts );
		$this->assertSame( 'draft', $posts[0]->post_status, 'a tool published something on its own' );

		// Real blocks, not a wall of HTML in one Classic block.
		$this->assertStringContainsString( '<!-- wp:heading', $posts[0]->post_content );
	}

	public function test_publishing_is_refused_below_the_quality_bar() {
		Blogcraft_Settings::set( 'quality_threshold', 95 );

		$created = $this->rpc(
			'tools/call',
			array(
				'name'      => 'create_draft',
				'arguments' => array(
					'title' => 'Thin',
					'html'  => '<p>Nowhere near long enough.</p>',
				),
			)
		);

		preg_match( '/draft (\d+)/', $created['body']['result']['content'][0]['text'], $hit );
		$post_id = (int) $hit[1];

		$this->assertGreaterThan( 0, $post_id );

		$out = $this->rpc(
			'tools/call',
			array(
				'name'      => 'publish_draft',
				'arguments' => array( 'post_id' => $post_id ),
			)
		);

		$this->assertTrue( $out['body']['result']['isError'] );
		$this->assertSame( 'draft', get_post_status( $post_id ), 'it published anyway' );
	}

	public function test_a_post_this_connection_did_not_create_cannot_be_touched() {
		// Somebody else's post, or one written by hand. A tool that can
		// rewrite anything on the site is a far larger promise than the one
		// being made here.
		$theirs = self::factory()->post->create(
			array(
				'post_title'  => 'Written by a person',
				'post_status' => 'draft',
			)
		);

		foreach ( array( 'update_draft', 'publish_draft' ) as $tool ) {
			$out = $this->rpc(
				'tools/call',
				array(
					'name'      => $tool,
					'arguments' => array(
						'post_id' => $theirs,
						'html'    => '<p>Replaced.</p>',
					),
				)
			);

			$this->assertTrue( $out['body']['result']['isError'], $tool . ' touched a post it did not create' );
		}

		$this->assertSame( 'draft', get_post_status( $theirs ) );
		$this->assertSame( 'Written by a person', get_post( $theirs )->post_title );
	}

	public function test_the_rules_a_client_reads_are_the_rules_the_pipeline_uses() {
		// Two descriptions of one blueprint would drift, and the one tuned on
		// the Blueprint screen would be the one that stopped being obeyed.
		$blueprint = Blogcraft_Blueprint::get();
		$expected  = trim( (string) Blogcraft_Blueprint::structure_rules( $blueprint ) );

		$out  = $this->rpc( 'tools/call', array( 'name' => 'get_writing_rules' ) );
		$text = $out['body']['result']['content'][0]['text'];

		$this->assertNotSame( '', $expected );
		$this->assertStringContainsString( $expected, $text );
	}

	public function test_resources_read_back_as_json() {
		$listed = $this->rpc( 'resources/list' );

		$this->assertNotEmpty( $listed['body']['result']['resources'] );

		foreach ( $listed['body']['result']['resources'] as $resource ) {
			$out = $this->rpc( 'resources/read', array( 'uri' => $resource['uri'] ) );

			$this->assertArrayHasKey( 'contents', $out['body']['result'], $resource['uri'] );

			$decoded = json_decode( $out['body']['result']['contents'][0]['text'], true );

			$this->assertIsArray( $decoded, $resource['uri'] . ' did not return JSON' );
		}
	}

	// -------------------------------------------------------- the screen.

	public function test_the_settings_screen_offers_the_endpoint_and_a_token() {
		Blogcraft_Settings::set( 'setup_path', 'client' );
		wp_set_current_user( $this->author );

		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bc-card-clients', $html, 'the card is not on the screen' );
		$this->assertStringContainsString( esc_attr( Blogcraft_Mcp::endpoint() ), $html, 'the address to paste is not shown' );
		$this->assertStringContainsString( 'blogcraft_mcp_issue', $html, 'there is no way to issue a token' );
	}

	public function test_the_card_is_usable_without_saving_first() {
		// It used to show a sentence telling you to tick a box and save
		// before it would show you anything — so connecting took a tick, a
		// save, and then a hunt for the button that had appeared. The
		// address, the steps and the token control are all on the card from
		// the first visit now, and issuing a token is what switches
		// connections on.
		Blogcraft_Settings::set( 'mcp_enabled', false );
		Blogcraft_Settings::set( 'setup_path', 'client' );
		wp_set_current_user( $this->author );

		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( esc_attr( Blogcraft_Mcp::endpoint() ), $html, 'the address is hidden until something is saved' );
		$this->assertStringContainsString( 'blogcraft_mcp_issue', $html, 'the token control is hidden until something is saved' );
	}

	public function test_the_server_still_refuses_while_it_is_switched_off() {
		// Showing the card is not the same as accepting connections. The
		// screen explains itself to anybody; the endpoint answers nobody
		// until it is on.
		Blogcraft_Settings::set( 'mcp_enabled', false );

		$this->assertSame( 404, $this->rpc( 'server/discover' )['status'] );
	}

	public function test_issuing_a_token_switches_connections_on() {
		// The step that used to be a separate tick-and-save.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-connection.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$at     = strpos( $source, 'function handle_mcp_issue(' );
		$next   = strpos( $source, 'public static function', $at + 10 );
		$body   = substr( $source, $at, ( false === $next ) ? null : $next - $at );

		$this->assertStringContainsString( "set( 'mcp_enabled', true )", $body );
	}

	public function test_issuing_a_token_tests_the_connection() {
		// Both faults this feature has had were invisible from inside PHP
		// and obvious from one real request, so issuing a token makes one.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-connection.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$at     = strpos( $source, 'function handle_mcp_issue(' );
		$next   = strpos( $source, 'public static function', $at + 10 );
		$body   = substr( $source, $at, ( false === $next ) ? null : $next - $at );

		$this->assertStringContainsString( 'self_test', $body );
	}

	public function test_every_app_offered_has_steps_behind_it() {
		// A name in the picker is a promise that choosing it shows you
		// something. The rule rather than a sample, so an app added later
		// without steps fails here instead of on a blank panel.
		Blogcraft_Settings::set( 'setup_path', 'client' );
		wp_set_current_user( $this->author );

		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$tabs = array();
		preg_match_all( '#id="bc-app-tab-([a-z]+)" aria-controls="bc-app-panel-([a-z]+)"#', $html, $tabs, PREG_SET_ORDER );

		$this->assertNotEmpty( $tabs, 'the picker offers nothing' );

		foreach ( $tabs as $tab ) {
			$this->assertSame(
				$tab[1],
				$tab[2],
				'a tab points at a panel that is not its own'
			);

			$panel = array();
			preg_match( '#<div class="bc-app-steps" id="bc-app-panel-' . $tab[1] . '".*?</ol>#s', $html, $panel );

			$this->assertNotEmpty(
				$panel,
				$tab[1] . ' is offered in the picker but has no steps'
			);
		}
	}

	public function test_only_one_set_of_steps_is_on_the_screen() {
		// The card printed a general four-step list and then a different
		// set for every app it knows, all at once. Two lists of
		// instructions on one screen is not thoroughness, it is a
		// question about which one to follow.
		Blogcraft_Settings::set( 'setup_path', 'client' );
		wp_set_current_user( $this->author );

		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString(
			'bc-mcp-steps',
			$html,
			'the general step list is back alongside the per-app ones'
		);
	}
	public function test_everything_worth_copying_has_a_button() {
		// The address and the command both have to arrive in another
		// window exactly right. Selecting a long one by hand and losing
		// the last character produces an error that blames the server.
		Blogcraft_Settings::set( 'setup_path', 'client' );
		wp_set_current_user( $this->author );

		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$copiable = array();
		preg_match_all( '#data-copy="([^"]*)"#', $html, $copiable );

		$this->assertNotEmpty( $copiable[1], 'nothing on the card can be copied' );

		$joined = implode( ' ', $copiable[1] );

		$this->assertStringContainsString(
			esc_attr( Blogcraft_Mcp::endpoint() ),
			$joined,
			'the address itself has no copy button'
		);
	}
	public function test_the_card_says_what_a_client_cannot_do() {
		// Somebody who switches this on and then goes looking for
		// scheduled posts has been let down by this screen.
		Blogcraft_Settings::set( 'setup_path', 'client' );
		wp_set_current_user( $this->author );

		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'bc-cannot', $html );
		$this->assertStringContainsString( 'schedule', $html );
	}

	public function test_issuing_and_revoking_are_guarded() {
		// Both are entry points that change a credential, so both are
		// held to the same rule as every other one in the plugin.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-connection.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		foreach ( array( 'handle_mcp_issue', 'handle_mcp_revoke' ) as $method ) {
			$at = strpos( $source, 'function ' . $method . '(' );

			$this->assertNotFalse( $at, $method . ' is gone' );
			$this->assertStringContainsString(
				'verify_or_die',
				substr( $source, $at, 600 ),
				$method . ' changes a credential without verifying anything'
			);
		}
	}

	public function test_a_token_is_never_put_in_a_url() {
		// A secret in an address is written into every server log along
		// the way and into the browser's own history. The plugin already
		// fixed exactly this for the Gemini key.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-connection.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$at     = strpos( $source, 'function handle_mcp_issue(' );
		$next   = strpos( $source, 'public static function', $at + 10 );
		$body   = substr( $source, $at, ( false === $next ) ? null : $next - $at );

		$this->assertStringContainsString( 'set_transient', $body );
		$this->assertStringNotContainsString( 'add_query_arg', $body );
	}

	public function test_an_unknown_tool_or_resource_is_refused() {
		$tool = $this->rpc( 'tools/call', array( 'name' => 'drop_database' ) );
		$this->assertSame( Blogcraft_Mcp::INVALID_PARAMS, $tool['body']['error']['code'] );

		$resource = $this->rpc( 'resources/read', array( 'uri' => 'blogcraft://nothing' ) );
		$this->assertSame( Blogcraft_Mcp::INVALID_PARAMS, $resource['body']['error']['code'] );
	}

	// ------------------------------------------------- the handshake.

	public function test_initialize_is_answered() {
		// The method every shipping client sends first. This server was
		// written against a draft that renamed it server/discover, so it
		// answered every question a client asked except the one it asks
		// before all the others — and reported as unreachable, not as
		// incomplete. Nothing in the suite noticed, because the suite
		// spoke the same dialect back to it.
		$out = $this->rpc( 'initialize', array(),
			$this->token
		);

		$this->assertSame( 200, $out['status'] );
		$this->assertArrayNotHasKey( 'error', $out['body'] );

		$result = $out['body']['result'];

		$this->assertArrayHasKey( 'protocolVersion', $result );
		$this->assertArrayHasKey( 'capabilities', $result );
		$this->assertArrayHasKey( 'serverInfo', $result );
		$this->assertSame( 'dicecodes-ai-blog-writer', $result['serverInfo']['name'] );
		$this->assertSame( BLOGCRAFT_VERSION, $result['serverInfo']['version'] );
	}

	public function test_every_capability_it_claims_is_one_it_answers() {
		// Claiming a capability is a promise that the matching list
		// method works. A client that believes the claim and gets
		// method-not-found treats the server as broken, so the claim and
		// the behaviour are asserted together rather than separately.
		$claimed = $this->rpc( 'initialize' )['body']['result']['capabilities'];

		foreach ( array_keys( $claimed ) as $capability ) {
			$out = $this->rpc( $capability . '/list' );

			$this->assertArrayNotHasKey(
				'error',
				$out['body'],
				$capability . ' is advertised but ' . $capability . '/list is not answered'
			);
		}
	}

	public function test_the_version_is_negotiated_and_never_refused() {
		// Refusing the request over a version string leaves the client
		// nowhere to go. Whatever it asks for, it gets a version back and
		// decides for itself.
		$asked = array( '2025-03-26', '2025-06-18', '2025-11-25', Blogcraft_Mcp::PROTOCOL, '1999-01-01', '' );

		foreach ( $asked as $version ) {
			$out = $this->rpc( 'initialize', array( 'protocolVersion' => $version ) );

			$this->assertArrayNotHasKey( 'error', $out['body'], $version . ' was refused' );

			$answered = $out['body']['result']['protocolVersion'];

			$this->assertContains(
				$answered,
				Blogcraft_Mcp::spoken(),
				'answered with a version it does not speak'
			);

			if ( in_array( $version, Blogcraft_Mcp::spoken(), true ) ) {
				$this->assertSame( $version, $answered, 'did not echo a version it speaks' );
			}
		}
	}

	public function test_a_notification_gets_no_answer() {
		// notifications/initialized arrives the moment initialize returns
		// and carries no id. A JSON-RPC envelope sent back for it is a
		// protocol error on our side.
		$request = new WP_REST_Request( 'POST', '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
		$request->set_header( 'authorization', 'Bearer ' . $this->token );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' )
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 202, $response->get_status() );
		$this->assertNull( $response->get_data() );
	}

	public function test_asking_for_the_stream_is_not_a_missing_route() {
		// A client opening the optional server-to-client stream reads 404
		// as "there is no MCP server at this address" and stops before it
		// posts anything — which is what Claude reported while every POST
		// in this file passed. 405 says the address is right and the
		// method is not.
		foreach ( array( 'GET', 'DELETE' ) as $method ) {
			$request = new WP_REST_Request( $method, '/' . Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE );
			$request->set_header( 'authorization', 'Bearer ' . $this->token );

			$response = rest_get_server()->dispatch( $request );

			$this->assertSame( 405, $response->get_status(), $method . ' was not routed' );
			$this->assertSame( 'POST', $response->get_headers()['Allow'] );
		}
	}

	public function test_a_client_can_complete_the_opening_exchange() {
		// The four calls a client makes before it will show the server as
		// connected, in the order it makes them. Each assertion here has
		// an equivalent above; the sequence is what none of them covered.
		$hello = $this->rpc( 'initialize', array( 'protocolVersion' => '2025-06-18' ) );
		$this->assertSame( '2025-06-18', $hello['body']['result']['protocolVersion'] );

		$tools = $this->rpc( 'tools/list' );
		$this->assertNotEmpty( $tools['body']['result']['tools'] );

		$resources = $this->rpc( 'resources/list' );
		$this->assertNotEmpty( $resources['body']['result']['resources'] );

		$ping = $this->rpc( 'ping' );
		$this->assertArrayNotHasKey( 'error', $ping['body'] );
	}

	// ------------------------------------ everything a finished post has.

	/**
	 * Make a draft through the tools and return its id.
	 *
	 * @param array $extra Anything beyond a title and a body.
	 * @return int
	 */
	private function draft( $extra = array() ) {
		wp_set_current_user( $this->author );

		$out = $this->rpc(
			'tools/call',
			array(
				'name'      => 'create_draft',
				'arguments' => array_merge(
					array(
						'title' => 'A post about kettles',
						'html'  => '<h2>First</h2><p>Words about kettles that go on for a while.</p><h2>Second</h2><p>More of them.</p>',
					),
					$extra
				),
			)
		);

		$said = (string) $out['body']['result']['content'][0]['text'];
		$hit  = array();

		preg_match( '/draft (\\d+)/', $said, $hit );

		$this->assertNotEmpty( $hit, 'no draft was created: ' . $said );

		return (int) $hit[1];
	}

	public function test_a_draft_lands_somewhere_rather_than_in_uncategorised() {
		// Every post written over MCP used to land in Uncategorised with
		// no tags, because create_draft took a title and a body and
		// nothing else.
		$post_id = $this->draft(
			array(
				'category' => 'Kitchen',
				'tags'     => array( 'kettles', 'descaling' ),
			)
		);

		$categories = wp_get_post_terms( $post_id, 'category', array( 'fields' => 'names' ) );
		$tags       = wp_get_post_terms( $post_id, 'post_tag', array( 'fields' => 'names' ) );

		$this->assertContains( 'Kitchen', $categories );
		$this->assertContains( 'kettles', $tags );
		$this->assertContains( 'descaling', $tags );
	}

	public function test_a_draft_can_be_found_and_read_back_later() {
		// A conversation that ended mid-draft used to lose it: nothing
		// could list what had been written, so the next conversation
		// started the post over.
		$post_id = $this->draft();

		$listed = $this->rpc( 'tools/call', array( 'name' => 'list_drafts', 'arguments' => array() ) );
		$text   = (string) $listed['body']['result']['content'][0]['text'];

		$this->assertStringContainsString( (string) $post_id, $text, 'the draft is not in the list' );

		$read = $this->rpc(
			'tools/call',
			array( 'name' => 'read_draft', 'arguments' => array( 'post_id' => $post_id ) )
		);

		$body = (string) $read['body']['result']['content'][0]['text'];

		$this->assertStringContainsString( 'A post about kettles', $body );
		$this->assertStringContainsString( 'kettles', $body );
	}

	public function test_a_draft_can_be_scored_by_id() {
		// Scoring the saved post rather than a string somebody sent means
		// the number is about the post as it actually stands.
		$post_id = $this->draft();

		$out = $this->rpc(
			'tools/call',
			array( 'name' => 'check_draft', 'arguments' => array( 'post_id' => $post_id ) )
		);

		$this->assertArrayNotHasKey( 'error', $out['body'] );
		$this->assertStringContainsString(
			'out of 100',
			(string) $out['body']['result']['content'][0]['text']
		);
	}

	public function test_nothing_reaches_a_draft_that_is_not_ours() {
		// The rule, over every tool that takes a post_id: a connected app
		// may only touch what it wrote. A tool added later that forgets
		// to check fails here.
		wp_set_current_user( $this->author );

		$theirs = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		foreach ( array( 'read_draft', 'add_pictures', 'update_draft', 'publish_draft', 'check_draft' ) as $tool ) {
			$out = $this->rpc(
				'tools/call',
				array( 'name' => $tool, 'arguments' => array( 'post_id' => $theirs ) )
			);

			$this->assertStringContainsString(
				'not a draft this connection created',
				(string) $out['body']['result']['content'][0]['text'],
				$tool . ' reached a post it did not write'
			);
		}
	}

	public function test_publishing_finishes_the_post_the_way_the_rest_of_the_plugin_does() {
		// The gap this closes: a post published over MCP went out with no
		// featured image, no search title, nothing linking to it and no
		// submission to anybody — a draft that happened to be public.
		//
		// Asserted against the pipeline rather than a list written here,
		// so a finishing step added to mode A later and not to this one
		// fails here instead of going unnoticed.
		$finishers = array(
			'Blogcraft_Seo::write_seo_meta',
			'Blogcraft_Images::attach_featured',
			'Blogcraft_Images::add_section_images',
			'Blogcraft_Backlinks::link_back',
			'Blogcraft_Indexnow::submit',
		);

		$pipeline = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-pipeline.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$tools    = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-mcp-tools.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		foreach ( $finishers as $call ) {
			$this->assertStringContainsString(
				$call,
				$pipeline,
				$call . ' is not a finishing step any more; this test is out of date'
			);

			$this->assertStringContainsString(
				$call,
				$tools,
				'mode A calls ' . $call . ' when finishing a post and the MCP tools do not'
			);
		}
	}

	public function test_publishing_can_be_scheduled_instead() {
		$post_id = $this->draft();

		// Past the quality gate, because scheduling is what is under test.
		Blogcraft_Settings::set( 'quality_threshold', 0 );

		$out = $this->rpc(
			'tools/call',
			array(
				'name'      => 'publish_draft',
				'arguments' => array(
					'post_id'    => $post_id,
					'publish_at' => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
				),
			)
		);

		$this->assertSame( 'future', get_post_status( $post_id ), (string) $out['body']['result']['content'][0]['text'] );
	}

	public function test_every_tool_offered_can_actually_be_called() {
		// A tool in the list that the dispatcher does not know is a
		// promise the server breaks the first time somebody takes it up.
		wp_set_current_user( $this->author );

		foreach ( Blogcraft_Mcp_Tools::definitions() as $tool ) {
			$out = $this->rpc(
				'tools/call',
				array( 'name' => $tool['name'], 'arguments' => array() )
			);

			$this->assertStringNotContainsString(
				'No such tool',
				(string) $out['body']['result']['content'][0]['text'],
				$tool['name'] . ' is offered but not dispatched'
			);
		}
	}
}
