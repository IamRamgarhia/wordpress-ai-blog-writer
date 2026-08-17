<?php
/**
 * Settings definitions.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for every setting: default, type, and secrecy.
 *
 * Later phases extend this map rather than inventing parallel option keys.
 */
class Blogcraft_Settings_Schema {

	/**
	 * All known settings.
	 *
	 * Types: 'int', 'bool', 'string', 'url'.
	 * Secrets are encrypted at rest and masked in the UI.
	 *
	 * @return array
	 */
	public static function all() {
		return array(
			'queue_max_attempts'         => array(
				'default' => 3,
				'type'    => 'int',
				'secret'  => false,
			),
			'queue_time_budget'          => array(
				'default' => 20,
				'type'    => 'int',
				'secret'  => false,
			),
			'cron_health_notice_enabled' => array(
				'default' => true,
				'type'    => 'bool',
				'secret'  => false,
			),
			'provider_base_url'          => array(
				'default' => '',
				'type'    => 'url',
				'secret'  => false,
			),
			'provider_api_key'           => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => true,
			),
		);
	}

	/**
	 * Look up one setting definition.
	 *
	 * @param string $key Setting key.
	 * @return array|null
	 */
	public static function get( $key ) {
		$all = self::all();

		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}
}
