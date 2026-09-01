<?php
/**
 * The site, as something an AI client can drive.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * A Model Context Protocol server, served from WordPress.
 *
 * The plugin's other mode holds a provider key and calls a model. This is the
 * opposite arrangement: the model lives in whatever client the reader already
 * pays for — Claude, ChatGPT, Cursor, an editor — and this site supplies the
 * writing rules, the measurements and the publishing. Nothing here contacts
 * anybody. It is contacted.
 *
 * Written to the specification rather than to one client. MCP servers are
 * client-agnostic by design, and about ten applications now speak it; a
 * shortcut that happens to work in one of them is a bug in the other nine.
 *
 * Transport is Streamable HTTP over a REST route, which is the transport every
 * remote client supports. Requests are JSON-RPC 2.0 and the protocol is
 * stateless: each one carries its own version and capabilities, so nothing is
 * inferred from a previous call.
 */
class Blogcraft_Mcp {

	/**
	 * REST namespace, and the route inside it.
	 *
	 * Split rather than a namespace with a bare '/' route. That form
	 * registers the path with a trailing slash, and WordPress trims the
	 * trailing slash out of the ?rest_route= query argument — so on any
	 * site with plain permalinks the endpoint answered 404 while the
	 * namespace index cheerfully advertised it. Found by calling the
	 * thing over HTTP; the internal dispatch the tests use does not go
	 * through that normalisation and could not have shown it.
	 */
	const REST_NAMESPACE = 'dicecodes/mcp';

	/**
	 * The versioned route within that namespace.
	 */
	const REST_ROUTE = '/v1';

	/**
	 * The newest protocol version this server implements.
	 */
	const PROTOCOL = '2026-07-28';

	/**
	 * The version to answer with when a client asks for one we do not know.
	 *
	 * The most widely implemented revision rather than the newest, because
	 * an unknown version means the client is older than this server, not
	 * newer, and the older side is the one that cannot adapt.
	 */
	const FALLBACK = '2025-06-18';

	/**
	 * JSON-RPC: the request was not understood.
	 */
	const INVALID_REQUEST = -32600;

	/**
	 * JSON-RPC: no such method.
	 */
	const METHOD_NOT_FOUND = -32601;

	/**
	 * JSON-RPC: the parameters were wrong.
	 */
	const INVALID_PARAMS = -32602;

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * Whether the reader has switched this on.
	 *
	 * Off until chosen, like every other outward-facing thing in this plugin.
	 * An endpoint that can publish posts should not exist because somebody
	 * installed a writing plugin.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) Blogcraft_Settings::get( 'mcp_enabled' );
	}

	/**
	 * The address a client connects to.
	 *
	 * @return string
	 */
	public static function endpoint() {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/**
	 * Call our own endpoint the way a client will, and say what happened.
	 *
	 * Every fault this feature has had so far was invisible from inside PHP and
	 * obvious from one real request: a route registered at an address WordPress
	 * then normalised away, and an Authorization header that Apache never
	 * passes through. Both looked perfect in a unit test.
	 *
	 * So issuing a token runs this. Somebody who presses the button and is told
	 * it works has been told something worth knowing, and somebody whose server
	 * cannot answer finds out here rather than from an app that says only that
	 * it could not connect.
	 *
	 * @param string $token A token that should be accepted.
	 * @return array Keys: ok, unknown, message.
	 */
	public static function self_test( $token ) {
		$response = wp_remote_post(
			self::endpoint(),
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => array(
					'content-type'  => 'application/json',
					'authorization' => 'Bearer ' . $token,
				),
				'body'        => (string) wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'tools/list',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// The site could not reach itself. Usually a host that does not
			// route loopback requests, in which case an app on the internet
			// may still connect perfectly — so this is reported as unproven
			// rather than as a failure.
			return array(
				'ok'      => false,
				'unknown' => true,
				'message' => sprintf(
					/* translators: %s: the error this server reported. */
					__( 'Token issued, but this server could not call its own address to check it: %s. Plenty of hosts block that while still answering the outside world, so try connecting from your app before worrying.', 'dicecodes-ai-blog-writer' ),
					$response->get_error_message()
				),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 404 === $code ) {
			return array(
				'ok'      => false,
				'unknown' => false,
				'message' => __( 'The address came back as not found. Save this page with the box ticked and try again. If it keeps happening, something on this site is blocking the WordPress REST API.', 'dicecodes-ai-blog-writer' ),
			);
		}

		if ( 401 === $code ) {
			return array(
				'ok'      => false,
				'unknown' => false,
				'message' => __( 'The address answered but would not accept the token. That normally means your server removes the Authorization header before WordPress sees it, which is common on Apache. Adding the line CGIPassAuth On to your .htaccess usually fixes it; otherwise ask your host to pass Authorization through.', 'dicecodes-ai-blog-writer' ),
			);
		}

		if ( 200 !== $code || ! isset( $body['result']['tools'] ) ) {
			return array(
				'ok'      => false,
				'unknown' => false,
				'message' => sprintf(
					/* translators: %d: an HTTP status code. */
					__( 'The address answered with %d instead of the list of tools. Something between your app and WordPress is changing the response, and a security plugin or a firewall is the usual cause.', 'dicecodes-ai-blog-writer' ),
					$code
				),
			);
		}

		return array(
			'ok'      => true,
			'unknown' => false,
			'message' => sprintf(
				/* translators: %d: how many tools the site offers. */
				_n(
					'Tested and working. Your site answered with %d tool ready to use.',
					'Tested and working. Your site answered with %d tools ready to use.',
					count( $body['result']['tools'] ),
					'dicecodes-ai-blog-writer'
				),
				count( $body['result']['tools'] )
			),
		);
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => array( __CLASS__, 'permitted' ),
				),
				// Registered only so the answer is 405 rather than 404. A client
				// opening the optional server-to-client stream reads 404 as "no
				// MCP server here" and stops before it ever posts anything.
				array(
					'methods'             => 'GET, DELETE',
					'callback'            => array( __CLASS__, 'no_stream' ),
					'permission_callback' => array( __CLASS__, 'permitted' ),
				),
			)
		);
	}

	/**
	 * Whether this request may proceed.
	 *
	 * Two gates, and the order matters: a disabled server admits nobody at
	 * all, so a site that has never switched this on cannot be probed for
	 * valid tokens.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public static function permitted( $request ) {
		if ( ! self::is_enabled() ) {
			return new WP_Error(
				'blogcraft_mcp_disabled',
				__( 'This site is not accepting AI client connections.', 'dicecodes-ai-blog-writer' ),
				array( 'status' => 404 )
			);
		}

		if ( ! Blogcraft_Mcp_Auth::allows( $request ) ) {
			return new WP_Error(
				'blogcraft_mcp_unauthorised',
				__( 'That connection token is not valid for this site.', 'dicecodes-ai-blog-writer' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Answer one JSON-RPC request.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle( $request ) {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) || ! isset( $body['method'] ) ) {
			return self::error( null, self::INVALID_REQUEST, 'Not a JSON-RPC request.' );
		}

		$method = (string) $body['method'];
		$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

		// A notification is a request with no id, and it wants no answer.
		// The handshake sends one the moment initialize returns, and a
		// client that gets a JSON-RPC envelope back for it treats the
		// server as broken. Absence of the member is the definition, so
		// array_key_exists rather than isset: a null id is a malformed
		// request, which is a different thing and not this.
		if ( ! array_key_exists( 'id', $body ) ) {
			return new WP_REST_Response( null, 202 );
		}

		$id = $body['id'];

		switch ( $method ) {
			case 'initialize':
				return self::result( $id, self::hello( $params ) );

			case 'server/discover':
				return self::result( $id, self::discovery() );

			case 'tools/list':
				return self::result(
					$id,
					array(
						'resultType' => 'complete',
						'tools'      => Blogcraft_Mcp_Tools::definitions(),
					)
				);

			case 'tools/call':
				return self::call_tool( $id, $params );

			case 'resources/list':
				return self::result(
					$id,
					array(
						'resultType' => 'complete',
						'resources'  => Blogcraft_Mcp_Resources::definitions(),
					)
				);

			case 'resources/read':
				return self::read_resource( $id, $params );

			case 'ping':
				return self::result( $id, array( 'resultType' => 'complete' ) );
		}

		return self::error( $id, self::METHOD_NOT_FOUND, 'No such method: ' . $method );
	}

	/**
	 * Every revision whose shapes this server is compatible with.
	 *
	 * @return array
	 */
	public static function spoken() {
		return array( self::PROTOCOL, '2025-11-25', '2025-06-18', '2025-03-26' );
	}

	/**
	 * The version to conduct this conversation in.
	 *
	 * Negotiated, never refused. The client names what it speaks and the
	 * server answers with something it also speaks; refusing the whole
	 * request over a version string leaves the client nowhere to go.
	 *
	 * @param array $params Request params.
	 * @return string
	 */
	private static function negotiated( $params ) {
		$asked = self::requested_version( $params );

		return in_array( $asked, self::spoken(), true ) ? $asked : self::FALLBACK;
	}

	/**
	 * The handshake.
	 *
	 * The first thing every client sends, and until it is answered nothing
	 * else is attempted — which is why a server missing it reports as
	 * unreachable rather than as incomplete, and why the settings screen
	 * could call it healthy while Claude refused to connect. The draft this
	 * was first written against renamed it server/discover. No shipping
	 * client implements that name, so both are answered.
	 *
	 * @param array $params Request params.
	 * @return array
	 */
	private static function hello( $params ) {
		return array(
			'protocolVersion' => self::negotiated( $params ),
			'capabilities'    => array(
				'tools'     => array( 'listChanged' => false ),
				'resources' => array(
					'listChanged' => false,
					'subscribe'   => false,
				),
			),
			'serverInfo'      => array(
				'name'    => 'dicecodes-ai-blog-writer',
				'title'   => get_bloginfo( 'name' ),
				'version' => BLOGCRAFT_VERSION,
			),
			'instructions'    => self::instructions(),
		);
	}

	/**
	 * How a connected client should use this site.
	 *
	 * @return string
	 */
	private static function instructions() {
		return implode(
			' ',
			array(
				'This is a WordPress site you can write for.',
				'Read blogcraft://writing-rules first and follow it; it is the standing brief for this site.',
				'Call find_duplicate before writing, so you do not compete with a post that already exists.',
				'Call suggest_internal_links and weave those links into sentences rather than listing them.',
				'Save with create_draft, giving it a topic, a category, tags, and an seo_title that is not just the heading.',
				'Then loop: check_draft with the post_id, fix every failure it reports with update_draft, and check again.',
				'Keep looping until the score stops rising. A first score is a starting point, not a verdict, and most drafts gain twenty points in two passes.',
				'Only call publish_draft once you cannot raise the score further. It refuses anything under the site threshold anyway, and it adds the pictures, the search title and the internal links itself.',
				'If a conversation ends mid-draft, call list_drafts and read_draft to pick the work up rather than starting again.',
			)
		);
	}

	/**
	 * The answer to a request for the optional stream, which is not offered.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function no_stream( $request ) {
		unset( $request );

		$response = new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => array(
					'code'    => self::INVALID_REQUEST,
					'message' => 'This server does not open a stream. Send JSON-RPC over POST.',
				),
			),
			405
		);

		$response->header( 'Allow', 'POST' );

		return $response;
	}
	/**
	 * What this server is and what it can do.
	 *
	 * @return array
	 */
	private static function discovery() {
		return array(
			'resultType'        => 'complete',
			'supportedVersions' => array( self::PROTOCOL ),
			'capabilities'      => array(
				'tools'     => array( 'listChanged' => false ),
				'resources' => array(),
			),
			'_meta'             => array(
				'io.modelcontextprotocol/serverInfo' => array(
					'name'    => 'dicecodes-ai-blog-writer',
					'title'   => get_bloginfo( 'name' ),
					'version' => BLOGCRAFT_VERSION,
				),
			),
			// The tool list is fixed for a given release, but the writing
			// rules behind it are not, so this is short enough that a client
			// re-reads within a session.
			'ttlMs'             => 300000,
			'cacheScope'        => 'private',
		);
	}

	/**
	 * Run one tool.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param array $params Request params.
	 * @return WP_REST_Response
	 */
	private static function call_tool( $id, $params ) {
		if ( empty( $params['name'] ) ) {
			return self::error( $id, self::INVALID_PARAMS, 'No tool named.' );
		}

		$name = (string) $params['name'];
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] )
			? $params['arguments']
			: array();

		if ( ! Blogcraft_Mcp_Tools::exists( $name ) ) {
			return self::error( $id, self::INVALID_PARAMS, 'No such tool: ' . $name );
		}

		$outcome = Blogcraft_Mcp_Tools::call( $name, $args );

		// A tool that fails reports it inside the result rather than as a
		// transport error. The distinction matters to a client: a JSON-RPC
		// error means the call could not be made, and isError means it was
		// made and did not work — which is something the model can read and
		// act on rather than a protocol fault it can only give up over.
		return self::result(
			$id,
			array(
				'resultType' => 'complete',
				'content'    => array(
					array(
						'type' => 'text',
						'text' => (string) $outcome['text'],
					),
				),
				'isError'    => ! empty( $outcome['error'] ),
			)
		);
	}

	/**
	 * Read one resource.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param array $params Request params.
	 * @return WP_REST_Response
	 */
	private static function read_resource( $id, $params ) {
		if ( empty( $params['uri'] ) ) {
			return self::error( $id, self::INVALID_PARAMS, 'No resource named.' );
		}

		$uri  = (string) $params['uri'];
		$body = Blogcraft_Mcp_Resources::read( $uri );

		if ( null === $body ) {
			return self::error( $id, self::INVALID_PARAMS, 'No such resource: ' . $uri );
		}

		return self::result(
			$id,
			array(
				'resultType' => 'complete',
				'contents'   => array(
					array(
						'uri'      => $uri,
						'mimeType' => 'application/json',
						'text'     => $body,
					),
				),
			)
		);
	}

	/**
	 * The protocol version a request declares, if it declares one.
	 *
	 * @param array $params Request params.
	 * @return string
	 */
	private static function requested_version( $params ) {
		if ( ! empty( $params['protocolVersion'] ) ) {
			return (string) $params['protocolVersion'];
		}

		if ( ! empty( $params['_meta']['io.modelcontextprotocol/protocolVersion'] ) ) {
			return (string) $params['_meta']['io.modelcontextprotocol/protocolVersion'];
		}

		return '';
	}

	/**
	 * A JSON-RPC success.
	 *
	 * @param mixed $id     Request id.
	 * @param array $result Result payload.
	 * @return WP_REST_Response
	 */
	private static function result( $id, $result ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * A JSON-RPC failure.
	 *
	 * Answered with HTTP 200: the transport worked, and the failure is in the
	 * envelope where a JSON-RPC client looks for it.
	 *
	 * @param mixed  $id      Request id.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message What went wrong.
	 * @param array  $data    Anything the client can use.
	 * @return WP_REST_Response
	 */
	private static function error( $id, $code, $message, $data = array() ) {
		$error = array(
			'code'    => (int) $code,
			'message' => (string) $message,
		);

		if ( ! empty( $data ) ) {
			$error['data'] = $data;
		}

		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => $error,
			),
			200
		);
	}
}
