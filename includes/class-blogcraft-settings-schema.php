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
			'queue_max_attempts'              => array(
				'default' => 3,
				'type'    => 'int',
				'secret'  => false,
			),
			'queue_time_budget'               => array(
				'default' => 20,
				'type'    => 'int',
				'secret'  => false,
			),
			'cron_health_notice_enabled'      => array(
				'default' => true,
				'type'    => 'bool',
				'secret'  => false,
			),
			'provider_type'                   => array(
				'default' => 'openai',
				'type'    => 'string',
				'secret'  => false,
			),
			'provider_base_url'               => array(
				'default' => '',
				'type'    => 'url',
				'secret'  => false,
			),
			'provider_api_key'                => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => true,
			),
			'provider_model'                  => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'images_enabled'                  => array(
				'default' => true,
				'type'    => 'bool',
				'secret'  => false,
			),
			'internal_links_enabled'          => array(
				'default' => true,
				'type'    => 'bool',
				'secret'  => false,
			),
			'images_per_section'              => array(
				'default' => false,
				'type'    => 'bool',
				'secret'  => false,
			),
			'image_provider'                  => array(
				'default' => 'pollinations',
				'type'    => 'string',
				'secret'  => false,
			),
			'author_credentials'              => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'reviewer_name'                   => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'reviewer_credentials'            => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'monthly_image_cap'               => array(
				'default' => 0,
				'type'    => 'int',
				'secret'  => false,
			),
			'fal_api_key'                     => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => true,
			),
			'fal_model'                       => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'openai_image_key'                => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => true,
			),
			'openai_image_model'              => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'pexels_api_key'                  => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => true,
			),
			'pixabay_api_key'                 => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => true,
			),
			'refresh_enabled'                 => array(
				'default' => false,
				'type'    => 'bool',
				'secret'  => false,
			),
			'refresh_after_days'              => array(
				'default' => 180,
				'type'    => 'int',
				'secret'  => false,
			),
			'research_provider'               => array(
				'default' => 'none',
				'type'    => 'string',
				'secret'  => false,
			),
			'research_api_key'                => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => true,
			),
			'research_base_url'               => array(
				'default' => '',
				'type'    => 'url',
				'secret'  => false,
			),
			'research_urls'                   => array(
				'default' => '',
				'type'    => 'textarea',
				'secret'  => false,
			),
			'quality_threshold'               => array(
				'default' => 60,
				'type'    => 'int',
				'secret'  => false,
			),
			'verify_links_enabled'            => array(
				'default' => true,
				'type'    => 'bool',
				'secret'  => false,
			),
			'backlinks_enabled'               => array(
				'default' => true,
				'type'    => 'bool',
				'secret'  => false,
			),
			'duplicate_check_enabled'         => array(
				'default' => true,
				'type'    => 'bool',
				'secret'  => false,
			),
			'autopilot_enabled'               => array(
				'default' => false,
				'type'    => 'bool',
				'secret'  => false,
			),
			'autopilot_topics'                => array(
				'default' => '',
				'type'    => 'textarea',
				'secret'  => false,
			),
			'autopilot_per_day'               => array(
				'default' => 1,
				'type'    => 'int',
				'secret'  => false,
			),
			'autopilot_status'                => array(
				'default' => 'draft',
				'type'    => 'string',
				'secret'  => false,
			),
			// Weekdays as a comma-separated list, 0 for Sunday through 6 for
			// Saturday. Weekdays only by default: a blog that also posts at the
			// weekend reads as automated.
			'autopilot_days'                  => array(
				'default' => '1,2,3,4,5',
				'type'    => 'string',
				'secret'  => false,
			),
			'autopilot_hour'                  => array(
				'default' => 9,
				'type'    => 'int',
				'secret'  => false,
			),
			'voice_niche'                     => array(
				'default' => '',
				'type'    => 'textarea',
				'secret'  => false,
			),
			'voice_audience'                  => array(
				'default' => '',
				'type'    => 'textarea',
				'secret'  => false,
			),
			'voice_tone'                      => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'voice_point_of_view'             => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'voice_reading_level'             => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'voice_style_rules'               => array(
				'default' => '',
				'type'    => 'textarea',
				'secret'  => false,
			),
			'voice_banned_words'              => array(
				'default' => '',
				'type'    => 'textarea',
				'secret'  => false,
			),
			'voice_banned_topics'             => array(
				'default' => '',
				'type'    => 'textarea',
				'secret'  => false,
			),
			'voice_experience'                => array(
				'default' => '',
				'type'    => 'textarea',
				'secret'  => false,
			),
			'monthly_token_cap'               => array(
				'default' => 0,
				'type'    => 'int',
				'secret'  => false,
			),
			'provider_auth_header'            => array(
				'default' => 'Authorization',
				'type'    => 'string',
				'secret'  => false,
			),
			'provider_auth_prefix'            => array(
				'default' => 'Bearer ',
				'type'    => 'string',
				'secret'  => false,
			),
			'provider_request_template'       => array(
				'default' => '',
				'type'    => 'textarea',
				'secret'  => false,
			),
			'provider_text_path'              => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'provider_prompt_tokens_path'     => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'provider_completion_tokens_path' => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
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
