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
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID ) {
			return false;
		}

		// Check if user has the capability through their roles.
		$has_capability = false;
		foreach ( (array) $user->roles as $role_name ) {
			$role = get_role( $role_name );
			if ( $role instanceof WP_Role && $role->has_cap( Blogcraft_Capabilities::MANAGE ) ) {
				$has_capability = true;
				break;
			}
		}

		if ( ! $has_capability ) {
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
				esc_html__( 'You are not allowed to perform this action.', 'blogcraft' ),
				esc_html__( 'Permission denied', 'blogcraft' ),
				array( 'response' => 403 )
			);
		}
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
