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
			// Off until asked for. Turning it on picks an image service, and
			// picking one is the consent for contacting it. A fresh install
			// that quietly fetched a picture from a third party nobody chose
			// is exactly what Guideline 7 is about.
			'images_enabled'                  => array(
				'default' => false,
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
			'research_wikipedia'              => array(
				'default' => false,
				'type'    => 'bool',
				'secret'  => false,
			),
			'research_community'              => array(
				'default' => false,
				'type'    => 'bool',
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
			'image_key_gemini'                => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => true,
			),
			'image_model_gemini'              => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'image_key_xai'                   => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => true,
			),
			'image_model_xai'                 => array(
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
			// Blank means "use the main model for everything", which is what
			// every install did before this existed and what most should keep
			// doing. Filled in, it is used for the stages that are bulk
			// execution of a plan rather than judgement — see
			// Blogcraft_Pipeline::model_for().
			// Which provider the stored key was saved for. Keys live in one
			// shared setting, so switching provider used to leave the previous
			// provider's key sitting there looking saved — the screen showed a
			// mask, the model list failed against the wrong service, and
			// nothing said why.
			'provider_key_owner'              => array(
				'default' => '',
				'type'    => 'string',
				'secret'  => false,
			),
			'provider_draft_model'            => array(
				'default' => '',
				'type'    => 'string',
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
			// The last look before a post is written. On by default: the
			// parts a post is built from are chosen on a tab most people
			// never open, so the first they learn of a Sources block or a
			// FAQ is finding one in the finished draft.
			'ask_before_writing'              => array(
				'default' => true,
				'type'    => 'bool',
				'secret'  => false,
			),
			// Off, so that deleting the plugin keeps everything it wrote.
			// WordPress asks whether you meant to delete the plugin; it has
			// no way to ask whether you meant to delete years of settings,
			// blueprints and job history along with it, and there is no undo
			// once the tables are dropped. Somebody moving hosts, testing a
			// reinstall, or clearing a failed upload gets their work back.
			// Anybody who genuinely wants it all gone can say so here first.
			'purge_on_delete'                 => array(
				'default' => false,
				'type'    => 'bool',
				'secret'  => false,
			),
			// On, because Google asks for it in as many words: say that
			// automation was involved, say how, and say why it was useful.
			// A plugin that writes posts and stays quiet about it is asking
			// its users to fail a stated guideline on its behalf.
			'ai_disclosure'                   => array(
				'default' => true,
				'type'    => 'bool',
				'secret'  => false,
			),
			'ai_disclosure_text'              => array(
				'default' => '',
				'type'    => 'text',
				'secret'  => false,
			),
			// Off until asked for: switching it on is what tells Blogcraft it
			// may announce your URLs to Microsoft's indexing service.
			'indexnow_enabled'                => array(
				'default' => false,
				'type'    => 'bool',
				'secret'  => false,
			),
			'indexnow_key'                    => array(
				'default' => '',
				'type'    => 'string',
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
