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
		$methods = array( 'server/discover', 'tools/list', 'tools/call', 'resources/list', 'resources/read', 'ping' );

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

	public function test_a_protocol_version_we_do_not_speak_is_refused_with_the_one_we_do() {
		$out = $this->rpc(
			'tools/list',
			array(
				'_meta' => array( 'io.modelcontextprotocol/protocolVersion' => '1999-01-01' ),
			)
		);

		$this->assertArrayHasKey( 'error', $out['body'] );
		$this->assertContains( Blogcraft_Mcp::PROTOCOL, $out['body']['error']['data']['supportedVersions'] );
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

	public function test_the_card_names_the_options_each_app_needs() {
		// Claude Desktop offers four ways to authenticate and three OAuth
		// arrangements. Picking the wrong one fails with a message about
		// client registration that says nothing about what to do instead,
		// so the card names the two choices that work.
		wp_set_current_user( $this->author );

		ob_start();
		Blogcraft_Connection::render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Claude Desktop', $html );
		$this->assertStringContainsString( 'ChatGPT', $html );
		$this->assertStringContainsString( 'Authorization', $html );
		$this->assertStringContainsString( 'Always required', $html, 'the card does not warn about the option that fails' );
	}
	public function test_the_card_says_what_a_client_cannot_do() {
		// Somebody who switches this on and then goes looking for
		// scheduled posts has been let down by this screen.
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
}
