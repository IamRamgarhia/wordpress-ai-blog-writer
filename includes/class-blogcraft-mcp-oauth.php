<?php
/**
 * Signing an AI client in, without anybody copying a token.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * An OAuth 2.1 authorisation server, just large enough for MCP.
 *
 * The pasted token works in applications that let you set a request header.
 * Claude's web connectors do not: the dialog offers no header field at all.
 * What it does instead is ask the endpoint, read the 401, and go looking for
 * an authorisation server — and when it finds nothing it says the URL is not
 * a valid MCP server, which is the one explanation that is not true.
 *
 * So this is the missing half. It is deliberately the smallest thing that is
 * still correct:
 *
 * - Public clients only. There is no secret to keep, because a desktop or
 *   browser application cannot keep one, and pretending otherwise is how
 *   secrets end up in configuration files.
 * - PKCE with S256, required rather than offered. Without it an intercepted
 *   authorization code is enough on its own.
 * - Registration is open, which sounds worse than it is: registering yields
 *   nothing but a client id. Every authorisation still goes through a
 *   WordPress login and a consent screen, and the resulting token carries
 *   the capabilities of the person who approved it and no more.
 *
 * The token this ends up minting is the same kind of token the card issues
 * by hand, in the same store, checked by the same code. Two ways in, one
 * thing to get right.
 */
class Blogcraft_Mcp_Oauth {

	/**
	 * Where registered clients live.
	 */
	const CLIENTS = 'blogcraft_mcp_clients';

	/**
	 * The scope this server understands. One is enough: the token carries a
	 * WordPress user, and WordPress capabilities decide the rest.
	 */
	const SCOPE = 'mcp';

	/**
	 * How long an authorization code is worth anything.
	 *
	 * The spec says ten minutes at the outside. It is a single redirect and
	 * an immediate exchange, so two is generous.
	 */
	const CODE_TTL = 120;

	/**
	 * How long an access token lasts before the client refreshes it.
	 */
	const TOKEN_TTL = 2592000;

	/**
	 * The most clients to remember before dropping the oldest.
	 */
	const MAX_CLIENTS = 50;

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'serve_metadata' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_menu', array( __CLASS__, 'register_consent_screen' ) );
		add_action( 'admin_post_blogcraft_oauth_approve', array( __CLASS__, 'handle_approval' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'point_at_the_authorisation_server' ), 10, 3 );
	}

	// ------------------------------------------------------------ discovery.

	/**
	 * Answer the two discovery documents.
	 *
	 * Served from parse_request rather than a rewrite rule because the paths
	 * are fixed by specification and a rewrite rule has to be flushed to take
	 * effect — which means an update that adds one leaves every existing site
	 * broken until somebody saves their permalinks. This needs no flush.
	 *
	 * Both documents are matched by prefix. A client is entitled to ask for
	 * the resource metadata with the resource's own path appended, and does.
	 *
	 * @return void
	 */
	public static function serve_metadata() {
		$path = self::requested_path();

		if ( 0 === strpos( $path, '/.well-known/oauth-protected-resource' ) ) {
			self::send( self::resource_metadata() );
		}

		if ( 0 === strpos( $path, '/.well-known/oauth-authorization-server' ) ) {
			self::send( self::server_metadata() );
		}
	}

	/**
	 * What this site is, as a protected resource. RFC 9728.
	 *
	 * @return array
	 */
	public static function resource_metadata() {
		return array(
			'resource'                 => Blogcraft_Mcp::endpoint(),
			'resource_name'            => get_bloginfo( 'name' ),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => array( self::SCOPE ),
			'bearer_methods_supported' => array( 'header' ),
		);
	}

	/**
	 * What this site is, as an authorisation server. RFC 8414.
	 *
	 * @return array
	 */
	public static function server_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_url(),
			'token_endpoint'                        => rest_url( Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE . '/token' ),
			'registration_endpoint'                 => rest_url( Blogcraft_Mcp::REST_NAMESPACE . Blogcraft_Mcp::REST_ROUTE . '/register' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array( self::SCOPE ),
		);
	}

	/**
	 * Tell a refused request where to go and sign in.
	 *
	 * Without this header a client has to guess the discovery address from
	 * the endpoint, and the guess is only right for a server living at the
	 * root of its own domain. WordPress rarely is.
	 *
	 * @param WP_HTTP_Response $response Outgoing response.
	 * @param WP_REST_Server   $server   Server instance.
	 * @param WP_REST_Request  $request  The request it answers.
	 * @return WP_HTTP_Response
	 */
	public static function point_at_the_authorisation_server( $response, $server, $request ) {
		unset( $server );

		if ( ! ( $response instanceof WP_HTTP_Response ) || 401 !== $response->get_status() ) {
			return $response;
		}

		if ( 0 !== strpos( (string) $request->get_route(), '/' . Blogcraft_Mcp::REST_NAMESPACE ) ) {
			return $response;
		}

		$response->header(
			'WWW-Authenticate',
			sprintf(
				'Bearer realm="%1$s", resource_metadata="%2$s"',
				esc_attr( get_bloginfo( 'name' ) ),
				home_url( '/.well-known/oauth-protected-resource' )
			)
		);

		return $response;
	}

	// --------------------------------------------------------- registration.

	/**
	 * The routes a client talks to without a token in hand.
	 *
	 * @return void
	 */
	public static function register_routes() {
		$base = Blogcraft_Mcp::REST_ROUTE;

		register_rest_route(
			Blogcraft_Mcp::REST_NAMESPACE,
			$base . '/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_registration' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			Blogcraft_Mcp::REST_NAMESPACE,
			$base . '/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_token' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Register a client. RFC 7591.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_registration( $request ) {
		if ( ! Blogcraft_Mcp::is_enabled() ) {
			return self::oauth_error( 'invalid_request', 'This site is not accepting AI client connections.', 403 );
		}

		$body = (array) $request->get_json_params();
		$uris = isset( $body['redirect_uris'] ) ? (array) $body['redirect_uris'] : array();
		$kept = array();

		foreach ( $uris as $uri ) {
			$uri = esc_url_raw( (string) $uri, array( 'https', 'http' ) );

			// http is allowed only back to the machine the browser is on,
			// which is how desktop applications receive the redirect. Any
			// other plaintext address is somebody else's computer.
			if ( '' === $uri || ( 0 === strpos( $uri, 'http://' ) && ! self::is_loopback( $uri ) ) ) {
				continue;
			}

			$kept[] = $uri;
		}

		if ( empty( $kept ) ) {
			return self::oauth_error( 'invalid_redirect_uri', 'No usable redirect_uris were given.', 400 );
		}

		$client_id = 'bc_' . bin2hex( random_bytes( 16 ) );
		$clients   = self::clients();

		$clients[ $client_id ] = array(
			'name'    => sanitize_text_field( isset( $body['client_name'] ) ? (string) $body['client_name'] : '' ),
			'uris'    => array_values( array_unique( $kept ) ),
			'created' => time(),
		);

		// Registration is open, so the store is capped. Oldest first, because
		// a client that has not been back in fifty registrations is not one
		// anybody is waiting on.
		if ( count( $clients ) > self::MAX_CLIENTS ) {
			uasort(
				$clients,
				static function ( $a, $b ) {
					return (int) $a['created'] <=> (int) $b['created'];
				}
			);

			$clients = array_slice( $clients, -self::MAX_CLIENTS, null, true );
		}

		update_option( self::CLIENTS, $clients, false );

		return new WP_REST_Response(
			array(
				'client_id'                  => $client_id,
				'client_id_issued_at'        => time(),
				'redirect_uris'              => $clients[ $client_id ]['uris'],
				'client_name'                => $clients[ $client_id ]['name'],
				'token_endpoint_auth_method' => 'none',
				'grant_types'                => array( 'authorization_code', 'refresh_token' ),
				'response_types'             => array( 'code' ),
				'scope'                      => self::SCOPE,
			),
			201
		);
	}

	// ------------------------------------------------------------- consent.

	/**
	 * The screen somebody approves a connection on.
	 *
	 * Registered as an admin page on purpose. WordPress already knows how to
	 * send a signed-out visitor to the login form and back again afterwards,
	 * and an authorisation endpoint that reimplements that is an
	 * authorisation endpoint with its own login bugs.
	 *
	 * @return void
	 */
	public static function register_consent_screen() {
		add_submenu_page(
			'',
			__( 'Connect an app', 'dicecodes-ai-blog-writer' ),
			__( 'Connect an app', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Capabilities::MANAGE,
			'blogcraft-connect-app',
			array( __CLASS__, 'render_consent' )
		);
	}

	/**
	 * Where a client sends somebody to approve a connection.
	 *
	 * @return string
	 */
	public static function authorize_url() {
		return admin_url( 'admin.php?page=blogcraft-connect-app' );
	}

	/**
	 * Ask the question, having checked it is worth asking.
	 *
	 * @return void
	 */
	public static function render_consent() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- an inbound OAuth redirect carries no nonce of ours; every value is validated below and nothing is written until the form on this page is submitted.
		$ask = array(
			'client_id'             => isset( $_GET['client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['client_id'] ) ) : '',
			'redirect_uri'          => isset( $_GET['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_uri'] ) ) : '',
			'state'                 => isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '',
			'code_challenge'        => isset( $_GET['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge'] ) ) : '',
			'code_challenge_method' => isset( $_GET['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_GET['code_challenge_method'] ) ) : '',
			'response_type'         => isset( $_GET['response_type'] ) ? sanitize_text_field( wp_unslash( $_GET['response_type'] ) ) : '',
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$refusal = self::why_not( $ask );

		echo '<div class="wrap blogcraft-page">';

		if ( '' !== $refusal ) {
			printf( '<h1>%s</h1>', esc_html__( 'That request cannot be approved', 'dicecodes-ai-blog-writer' ) );
			printf( '<p>%s</p>', esc_html( $refusal ) );
			echo '</div>';

			return;
		}

		$clients = self::clients();
		$name    = $clients[ $ask['client_id'] ]['name'];
		$name    = ( '' === $name ) ? __( 'An application', 'dicecodes-ai-blog-writer' ) : $name;
		$user    = wp_get_current_user();

		printf( '<h1>%s</h1>', esc_html__( 'Connect an app to this site', 'dicecodes-ai-blog-writer' ) );

		echo '<div class="blogcraft-card bc-consent">';

		printf(
			'<p class="bc-consent-lead">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: application name, 2: WordPress display name. */
					__( '%1$s is asking to connect to this site as %2$s.', 'dicecodes-ai-blog-writer' ),
					$name,
					$user->display_name
				)
			)
		);

		printf( '<p><strong>%s</strong></p>', esc_html__( 'If you approve, it will be able to:', 'dicecodes-ai-blog-writer' ) );

		echo '<ul class="bc-consent-can">';
		foreach ( self::what_it_can_do() as $line ) {
			printf( '<li>%s</li>', esc_html( $line ) );
		}
		echo '</ul>';

		printf( '<p><strong>%s</strong></p>', esc_html__( 'It will not be able to:', 'dicecodes-ai-blog-writer' ) );

		echo '<ul class="bc-consent-cannot">';
		foreach ( self::what_it_cannot_do() as $line ) {
			printf( '<li>%s</li>', esc_html( $line ) );
		}
		echo '</ul>';

		printf(
			'<p class="bc-consent-revoke">%s</p>',
			esc_html__( 'You can disconnect it at any time from Settings, Connect an AI client.', 'dicecodes-ai-blog-writer' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_oauth_approve" />';

		foreach ( $ask as $field => $value ) {
			printf( '<input type="hidden" name="%1$s" value="%2$s" />', esc_attr( $field ), esc_attr( $value ) );
		}

		Blogcraft_Request::nonce_field( 'blogcraft_oauth_approve' );

		echo '<p class="bc-consent-buttons">';
		submit_button( __( 'Approve', 'dicecodes-ai-blog-writer' ), 'primary', 'submit', false );
		printf(
			' <button type="submit" name="deny" value="1" class="button">%s</button>',
			esc_html__( 'Cancel', 'dicecodes-ai-blog-writer' )
		);
		echo '</p>';
		echo '</form>';

		echo '</div>';
		echo '</div>';
	}

	/**
	 * What a connected app can do, in the words of somebody deciding.
	 *
	 * @return array
	 */
	public static function what_it_can_do() {
		return array(
			__( 'Read your writing rules, your voice and your recent posts', 'dicecodes-ai-blog-writer' ),
			__( 'Create and edit drafts, which it writes itself', 'dicecodes-ai-blog-writer' ),
			__( 'Score a draft against your quality checks', 'dicecodes-ai-blog-writer' ),
			__( 'Publish a draft it wrote, once it clears your threshold', 'dicecodes-ai-blog-writer' ),
		);
	}

	/**
	 * And what it cannot, which is the half people assume.
	 *
	 * @return array
	 */
	public static function what_it_cannot_do() {
		return array(
			__( 'Touch a post it did not write', 'dicecodes-ai-blog-writer' ),
			__( 'Write anything on a schedule, or while you are away', 'dicecodes-ai-blog-writer' ),
			__( 'Read or change your settings, keys, users or plugins', 'dicecodes-ai-blog-writer' ),
		);
	}

	/**
	 * Why this authorisation request cannot proceed, if it cannot.
	 *
	 * Everything is checked before anything is shown, because a consent
	 * screen for a request that was never valid is a consent screen that
	 * teaches people to click Approve.
	 *
	 * @param array $ask The request, already sanitised.
	 * @return string Empty when the request is fine.
	 */
	private static function why_not( $ask ) {
		if ( ! Blogcraft_Mcp::is_enabled() ) {
			return __( 'This site is not accepting AI client connections.', 'dicecodes-ai-blog-writer' );
		}

		if ( 'code' !== $ask['response_type'] ) {
			return __( 'The app asked for a kind of sign-in this site does not offer.', 'dicecodes-ai-blog-writer' );
		}

		$clients = self::clients();

		if ( '' === $ask['client_id'] || ! isset( $clients[ $ask['client_id'] ] ) ) {
			return __( 'That app is not registered with this site. Ask it to connect again from the start.', 'dicecodes-ai-blog-writer' );
		}

		// Exact match, not a prefix and not a host comparison. A redirect
		// target that is merely similar to a registered one is the whole of
		// how authorization codes get stolen.
		if ( ! in_array( $ask['redirect_uri'], (array) $clients[ $ask['client_id'] ]['uris'], true ) ) {
			return __( 'The app asked to be sent somewhere it did not register.', 'dicecodes-ai-blog-writer' );
		}

		if ( 'S256' !== $ask['code_challenge_method'] || '' === $ask['code_challenge'] ) {
			return __( 'The app did not protect the sign-in properly, so it was refused.', 'dicecodes-ai-blog-writer' );
		}

		return '';
	}

	/**
	 * Turn an approval into a code and send the browser back.
	 *
	 * @return void
	 */
	public static function handle_approval() {
		// Read here, verified on the next line by Blogcraft_Request, which PHPCS cannot follow statically.
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( 'blogcraft_oauth_approve', $nonce );

		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to connect apps to this site.', 'dicecodes-ai-blog-writer' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$ask = array(
			'client_id'             => isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '',
			'redirect_uri'          => isset( $_POST['redirect_uri'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_uri'] ) ) : '',
			'state'                 => isset( $_POST['state'] ) ? sanitize_text_field( wp_unslash( $_POST['state'] ) ) : '',
			'code_challenge'        => isset( $_POST['code_challenge'] ) ? sanitize_text_field( wp_unslash( $_POST['code_challenge'] ) ) : '',
			'code_challenge_method' => isset( $_POST['code_challenge_method'] ) ? sanitize_text_field( wp_unslash( $_POST['code_challenge_method'] ) ) : '',
			'response_type'         => isset( $_POST['response_type'] ) ? sanitize_text_field( wp_unslash( $_POST['response_type'] ) ) : '',
		);

		$denied = isset( $_POST['deny'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Re-checked rather than trusted. The form carried these across a
		// round trip through a browser, so as far as this is concerned they
		// arrived from outside for the first time.
		$refusal = self::why_not( $ask );

		if ( '' !== $refusal ) {
			wp_die( esc_html( $refusal ) );
		}

		if ( $denied ) {
			self::bounce( $ask['redirect_uri'], array( 'error' => 'access_denied' ), $ask['state'] );
		}

		$code = bin2hex( random_bytes( 32 ) );

		set_transient(
			self::code_key( $code ),
			array(
				'user'         => get_current_user_id(),
				'client'       => $ask['client_id'],
				'redirect_uri' => $ask['redirect_uri'],
				'challenge'    => $ask['code_challenge'],
			),
			self::CODE_TTL
		);

		self::bounce( $ask['redirect_uri'], array( 'code' => $code ), $ask['state'] );
	}

	// --------------------------------------------------------------- tokens.

	/**
	 * Exchange a code, or refresh a token.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_token( $request ) {
		if ( ! Blogcraft_Mcp::is_enabled() ) {
			return self::oauth_error( 'invalid_request', 'This site is not accepting AI client connections.', 403 );
		}

		$grant = (string) $request->get_param( 'grant_type' );

		if ( 'refresh_token' === $grant ) {
			return self::refresh( $request );
		}

		if ( 'authorization_code' !== $grant ) {
			return self::oauth_error( 'unsupported_grant_type', 'That grant type is not supported.', 400 );
		}

		$code     = (string) $request->get_param( 'code' );
		$verifier = (string) $request->get_param( 'code_verifier' );
		$client   = (string) $request->get_param( 'client_id' );
		$key      = self::code_key( $code );
		$stored   = get_transient( $key );

		// One use, whatever happens next. A code that failed verification is
		// a code somebody else may be holding.
		delete_transient( $key );

		if ( ! is_array( $stored ) ) {
			return self::oauth_error( 'invalid_grant', 'That sign-in has expired. Start again.', 400 );
		}

		if ( $stored['client'] !== $client ) {
			return self::oauth_error( 'invalid_grant', 'That code belongs to a different app.', 400 );
		}

		if ( esc_url_raw( (string) $request->get_param( 'redirect_uri' ) ) !== $stored['redirect_uri'] ) {
			return self::oauth_error( 'invalid_grant', 'The redirect address does not match the one the code was issued for.', 400 );
		}

		if ( ! self::verifier_matches( $verifier, (string) $stored['challenge'] ) ) {
			return self::oauth_error( 'invalid_grant', 'The proof of possession did not check out.', 400 );
		}

		if ( ! user_can( (int) $stored['user'], Blogcraft_Capabilities::MANAGE ) ) {
			return self::oauth_error( 'invalid_grant', 'That account is no longer allowed to connect apps.', 400 );
		}

		return self::mint( (int) $stored['user'], $client );
	}

	/**
	 * Trade a refresh token for a new pair.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	private static function refresh( $request ) {
		$presented = (string) $request->get_param( 'refresh_token' );
		$record    = Blogcraft_Mcp_Auth::record_for( $presented, 'refresh' );

		if ( empty( $record ) ) {
			return self::oauth_error( 'invalid_grant', 'That refresh token is not valid.', 400 );
		}

		if ( ! user_can( (int) $record['user'], Blogcraft_Capabilities::MANAGE ) ) {
			return self::oauth_error( 'invalid_grant', 'That account is no longer allowed to connect apps.', 400 );
		}

		// Rotated, not reused: the old one stops working the moment a new one
		// is handed out, so a stolen refresh token is good for one use and
		// then visibly breaks the client it was stolen from.
		Blogcraft_Mcp_Auth::revoke( $record['fingerprint'] );

		return self::mint( (int) $record['user'], (string) $record['client'] );
	}

	/**
	 * Issue an access token and the refresh token that outlives it.
	 *
	 * @param int    $user_id Who the token acts as.
	 * @param string $client  Which app asked.
	 * @return WP_REST_Response
	 */
	private static function mint( $user_id, $client ) {
		$clients = self::clients();
		$label   = isset( $clients[ $client ]['name'] ) ? (string) $clients[ $client ]['name'] : '';
		$label   = ( '' === $label ) ? __( 'Connected app', 'dicecodes-ai-blog-writer' ) : $label;

		$access = Blogcraft_Mcp_Auth::issue(
			$user_id,
			$label,
			array(
				'client'  => $client,
				'expires' => time() + self::TOKEN_TTL,
			)
		);

		$refresh = Blogcraft_Mcp_Auth::issue(
			$user_id,
			$label,
			array(
				'client'  => $client,
				'kind'    => 'refresh',
				'expires' => 0,
			)
		);

		$response = new WP_REST_Response(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => self::TOKEN_TTL,
				'refresh_token' => $refresh,
				'scope'         => self::SCOPE,
			),
			200
		);

		// A token in a shared cache is a token somebody else can use.
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	// ---------------------------------------------------------------- parts.

	/**
	 * Whether a verifier hashes to the challenge that was registered.
	 *
	 * @param string $verifier  What the client now presents.
	 * @param string $challenge What it committed to earlier.
	 * @return bool
	 */
	private static function verifier_matches( $verifier, $challenge ) {
		if ( '' === $verifier || '' === $challenge ) {
			return false;
		}

		$computed = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url is what RFC 7636 specifies.

		return hash_equals( $challenge, $computed );
	}

	/**
	 * Send the browser back where the app is waiting.
	 *
	 * @param string $redirect_uri Where to.
	 * @param array  $args         What to carry.
	 * @param string $state        The app's own value, returned untouched.
	 * @return void
	 */
	private static function bounce( $redirect_uri, $args, $state ) {
		if ( '' !== $state ) {
			$args['state'] = $state;
		}

		// Not wp_safe_redirect: the destination is somebody else's
		// application by design, and it was checked against the addresses
		// that application registered, which is the stronger test.
		wp_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $redirect_uri ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- validated against the client's registered redirect_uris in why_not().
		exit;
	}

	/**
	 * Every registered client.
	 *
	 * @return array
	 */
	public static function clients() {
		$stored = get_option( self::CLIENTS, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Who this authorisation server says it is.
	 *
	 * @return string
	 */
	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	/**
	 * The transient name for one authorization code.
	 *
	 * Hashed, so the code itself is never a key anybody can read out of the
	 * options table while it is still live.
	 *
	 * @param string $code The code as presented.
	 * @return string
	 */
	private static function code_key( $code ) {
		return 'blogcraft_oauth_' . hash( 'sha256', (string) $code );
	}

	/**
	 * Whether a redirect address points back at the machine it came from.
	 *
	 * @param string $uri The address.
	 * @return bool
	 */
	private static function is_loopback( $uri ) {
		$host = (string) wp_parse_url( $uri, PHP_URL_HOST );

		return in_array( $host, array( '127.0.0.1', '::1', 'localhost' ), true );
	}

	/**
	 * The path being requested, without the query.
	 *
	 * @return string
	 */
	private static function requested_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against fixed literals, never stored or rendered.

		return (string) strtok( $uri, '?' );
	}

	/**
	 * Send a discovery document and stop.
	 *
	 * @param array $document What to send.
	 * @return void
	 */
	private static function send( $document ) {
		// Discovery is public by specification: it names endpoints, and the
		// endpoints defend themselves.
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: max-age=300' );

		echo wp_json_encode( $document );
		exit;
	}

	/**
	 * An error in the shape RFC 6749 asks for.
	 *
	 * @param string $code    Machine-readable reason.
	 * @param string $message Human-readable reason.
	 * @param int    $status  HTTP status.
	 * @return WP_REST_Response
	 */
	private static function oauth_error( $code, $message, $status ) {
		return new WP_REST_Response(
			array(
				'error'             => $code,
				'error_description' => $message,
			),
			$status
		);
	}
}
