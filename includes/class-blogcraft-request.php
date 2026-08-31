<?php
/**
 * Admin request verification.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single choke point for nonce and capability checks.
 *
 * Every state-changing admin action routes through here, so Plugin Check's
 * nonce and capability rules are satisfied by construction rather than by
 * remembering to add a check in each handler.
 */
class Blogcraft_Request {

	/**
	 * Verify capability and nonce together.
	 *
	 * @param string $action      Nonce action name.
	 * @param string $nonce_value Nonce value supplied by the request.
	 * @return bool
	 */
	public static function verify( $action, $nonce_value ) {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			return false;
		}

		return (bool) wp_verify_nonce( $nonce_value, $action );
	}

	/**
	 * Verify, or halt the request with a 403.
	 *
	 * @param string $action      Nonce action name.
	 * @param string $nonce_value Nonce value supplied by the request.
	 * @return void
	 */
	public static function verify_or_die( $action, $nonce_value ) {
		if ( ! self::verify( $action, $nonce_value ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Stop a request that needs a provider when there is not one.
	 *
	 * Five buttons on four screens call a provider: list the models on
	 * this account, read my posts and describe my voice, read this
	 * article and match its shape, ask what I should write about this
	 * topic, and preview a post. None of them checked first, so pressing
	 * any of them on a fresh install produced whatever the HTTP layer
	 * said — "Request failed with HTTP 401" — which is true, useless,
	 * and indistinguishable from the plugin being broken.
	 *
	 * Checked at the handler rather than only hidden in the markup,
	 * because a key can be cleared in another tab after the page that
	 * drew the button was loaded.
	 *
	 * @return void Sends a JSON error and exits when nothing is set up.
	 */
	public static function require_provider() {
		if ( Blogcraft_Provider_Registry::is_configured() ) {
			return;
		}

		wp_send_json_error(
			array(
				'message' => __( 'No AI provider is set up yet, so there is nothing to ask. Settings, then Connect a provider.', 'dicecodes-ai-blog-writer' ),
			),
			409
		);
	}

	/**
	 * Print a nonce field for a Blogcraft form.
	 *
	 * @param string $action Nonce action name.
	 * @return void
	 */
	public static function nonce_field( $action ) {
		wp_nonce_field( $action, '_blogcraft_nonce' );
	}
}
