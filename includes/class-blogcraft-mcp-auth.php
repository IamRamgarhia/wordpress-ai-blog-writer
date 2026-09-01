<?php
/**
 * Who is allowed to drive this site over MCP.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Issues and verifies the credentials an MCP client connects with.
 *
 * A token here is not a convenience. It is a key to a route that can create
 * and publish posts, so it gets the same treatment as a provider key: stored
 * as a hash and never as itself, shown once at the moment it is created, and
 * revocable from the screen that made it.
 *
 * The token identifies a WordPress user rather than standing on its own.
 * Every call then re-checks that user's capability, so a token issued to
 * somebody who has since lost the capability stops working without anybody
 * having to remember to revoke it.
 */
class Blogcraft_Mcp_Auth {

	/**
	 * Where the issued tokens live.
	 */
	const OPTION = 'blogcraft_mcp_tokens';

	/**
	 * Bytes of randomness behind each token.
	 */
	const BYTES = 32;

	/**
	 * Issue a token for a user.
	 *
	 * Returns the secret exactly once. Nothing stores it, so a lost token is
	 * replaced rather than recovered — which is the property that makes the
	 * stored hash worth having.
	 *
	 * @param int    $user_id Owner.
	 * @param string $label   What the reader called it, for their own benefit.
	 * @return string The secret, or '' when it could not be issued.
	 */
	public static function issue( $user_id, $label = '' ) {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return '';
		}

		$secret = bin2hex( random_bytes( self::BYTES ) );
		$tokens = self::all();

		$tokens[ self::fingerprint( $secret ) ] = array(
			'user'    => $user_id,
			'label'   => sanitize_text_field( $label ),
			'created' => time(),
			'used'    => 0,
		);

		update_option( self::OPTION, $tokens, false );

		return $secret;
	}

	/**
	 * Every issued token, without the secrets.
	 *
	 * @return array Fingerprint => record.
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Forget one token.
	 *
	 * @param string $fingerprint Which one.
	 * @return bool Whether it existed.
	 */
	public static function revoke( $fingerprint ) {
		$tokens      = self::all();
		$fingerprint = (string) $fingerprint;

		if ( ! isset( $tokens[ $fingerprint ] ) ) {
			return false;
		}

		unset( $tokens[ $fingerprint ] );
		update_option( self::OPTION, $tokens, false );

		return true;
	}

	/**
	 * The lookup key for a secret.
	 *
	 * SHA-256 rather than a password hash: this is a 256-bit random string,
	 * not something a person chose, so there is no dictionary to slow down
	 * and the lookup has to be a single indexed read rather than a walk over
	 * every stored record calling password_verify().
	 *
	 * @param string $secret The token as presented.
	 * @return string
	 */
	private static function fingerprint( $secret ) {
		return hash( 'sha256', (string) $secret );
	}

	/**
	 * The Authorization header, wherever this server happens to keep it.
	 *
	 * Apache does not pass Authorization through to PHP by default, so
	 * $_SERVER['HTTP_AUTHORIZATION'] is simply absent and
	 * WP_REST_Request::get_header() has nothing to return. On the majority
	 * of real WordPress hosts that made every token look invalid, while a
	 * unit test — which sets the header on the request object directly —
	 * passed happily. It took calling the endpoint over HTTP to see it.
	 *
	 * Four places, in order of how much they can be trusted. getallheaders()
	 * is last because it does not exist on every server, and first among
	 * equals in that it is the only one that works on Apache.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string The header value, or ''.
	 */
	private static function presented_credential( $request ) {
		$header = (string) $request->get_header( 'authorization' );

		if ( '' !== $header ) {
			return $header;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a stored hash, never rendered or stored.
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return trim( (string) wp_unslash( $_SERVER[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( ! function_exists( 'getallheaders' ) ) {
			return '';
		}

		$all = array_change_key_case( (array) getallheaders() );

		return isset( $all['authorization'] ) ? trim( (string) $all['authorization'] ) : '';
	}
	/**
	 * The user a request is authenticated as, if any.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return int User id, or 0.
	 */
	public static function user_for( $request ) {
		$header = self::presented_credential( $request );

		if ( 0 !== stripos( $header, 'bearer ' ) ) {
			return 0;
		}

		$secret = trim( substr( $header, 7 ) );

		if ( '' === $secret ) {
			return 0;
		}

		$tokens = self::all();
		$key    = self::fingerprint( $secret );

		if ( ! isset( $tokens[ $key ] ) ) {
			return 0;
		}

		$user_id = (int) $tokens[ $key ]['user'];

		// The token names a user; the user still has to be allowed. A token
		// outliving the permission it was issued under is the failure this
		// prevents.
		if ( ! user_can( $user_id, Blogcraft_Capabilities::MANAGE ) ) {
			return 0;
		}

		$tokens[ $key ]['used'] = time();
		update_option( self::OPTION, $tokens, false );

		return $user_id;
	}

	/**
	 * Whether a request may reach the endpoint at all.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool
	 */
	public static function allows( $request ) {
		$user_id = self::user_for( $request );

		if ( $user_id <= 0 ) {
			return false;
		}

		// Everything downstream reads the current user — post creation, the
		// capability checks, the author on a draft. Setting it here means no
		// tool has to remember to.
		wp_set_current_user( $user_id );

		return true;
	}

	/**
	 * Remove every token belonging to a user.
	 *
	 * @param int $user_id Owner.
	 * @return void
	 */
	public static function revoke_all_for( $user_id ) {
		$tokens  = self::all();
		$user_id = (int) $user_id;
		$kept    = array();

		foreach ( $tokens as $key => $record ) {
			if ( (int) $record['user'] !== $user_id ) {
				$kept[ $key ] = $record;
			}
		}

		update_option( self::OPTION, $kept, false );
	}
}
