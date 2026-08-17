<?php
/**
 * Custom capability management.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Grants and revokes Blogcraft's own capability.
 *
 * A dedicated capability lets site owners delegate Blogcraft to an editor
 * without handing over full manage_options access.
 */
class Blogcraft_Capabilities {

	/**
	 * Capability required to manage Blogcraft.
	 */
	const MANAGE = 'manage_blogcraft';

	/**
	 * Roles that receive the capability on activation.
	 *
	 * @return array
	 */
	private static function default_roles() {
		return array( 'administrator' );
	}

	/**
	 * Grant the capability to default roles.
	 *
	 * @return void
	 */
	public static function add() {
		foreach ( self::default_roles() as $role_name ) {
			$role = get_role( $role_name );
			if ( $role instanceof WP_Role ) {
				$role->add_cap( self::MANAGE );
			}
		}
	}

	/**
	 * Revoke the capability from every role that has it.
	 *
	 * @return void
	 */
	public static function remove() {
		$roles = wp_roles();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role instanceof WP_Role ) {
				$role->remove_cap( self::MANAGE );
			}
		}
	}
}
