<?php
/**
 * What an AI client can read about this site.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read-only context offered over MCP.
 *
 * The difference between these and the tools is who decides when to look. A
 * tool is called because the model chose to; a resource is something a client
 * can attach to a conversation up front, so the rules are in front of the
 * model before it starts drafting rather than after it has guessed.
 *
 * Everything here is already visible to anybody who can read the site, or is
 * the reader's own configuration. Nothing exposes a key, a token, or another
 * user's material.
 */
class Blogcraft_Mcp_Resources {

	/**
	 * Every resource, in the shape resources/list returns.
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			array(
				'uri'         => 'blogcraft://writing-rules',
				'name'        => 'writing-rules',
				'title'       => __( 'Writing rules', 'dicecodes-ai-blog-writer' ),
				'description' => 'The standing brief every post on this site is written to: length, structure, voice, and what must never appear.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'blogcraft://recent-posts',
				'name'        => 'recent-posts',
				'title'       => __( 'Recent posts', 'dicecodes-ai-blog-writer' ),
				'description' => 'Titles and addresses of what this site has already published, so a new article can link to them and avoid repeating them.',
				'mimeType'    => 'application/json',
			),
			array(
				'uri'         => 'blogcraft://quality-bar',
				'name'        => 'quality-bar',
				'title'       => __( 'What counts as finished', 'dicecodes-ai-blog-writer' ),
				'description' => 'The score a post must reach before this site will publish it, and what each check measures.',
				'mimeType'    => 'application/json',
			),
		);
	}

	/**
	 * Read one resource.
	 *
	 * @param string $uri Which one.
	 * @return string|null JSON, or null when there is no such resource.
	 */
	public static function read( $uri ) {
		switch ( (string) $uri ) {
			case 'blogcraft://writing-rules':
				return self::encode( self::writing_rules() );

			case 'blogcraft://recent-posts':
				return self::encode( self::recent_posts() );

			case 'blogcraft://quality-bar':
				return self::encode( self::quality_bar() );
		}

		return null;
	}

	/**
	 * The blueprint, as the pipeline states it.
	 *
	 * @return array
	 */
	private static function writing_rules() {
		$blueprint = Blogcraft_Blueprint::get();

		return array(
			'structure' => trim( (string) Blogcraft_Blueprint::structure_rules( $blueprint ) ),
			'voice'     => trim( (string) Blogcraft_Blueprint::voice_rules( $blueprint ) ),
			'format'    => 'Plain HTML: h2, h3, p, ul, ol, table, strong, em, a. No markdown.',
		);
	}

	/**
	 * What this site has already published.
	 *
	 * @return array
	 */
	private static function recent_posts() {
		$posts = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => 40,
				'suppress_filters' => false,
			)
		);

		$out = array();

		foreach ( $posts as $post ) {
			$out[] = array(
				'title' => $post->post_title,
				'url'   => get_permalink( $post ),
				'date'  => get_the_date( 'Y-m-d', $post ),
			);
		}

		return array(
			'count' => count( $out ),
			'posts' => $out,
			'note'  => 'Link to these from a new article where they are genuinely relevant. Do not invent internal links to pages not in this list.',
		);
	}

	/**
	 * The bar a post has to clear.
	 *
	 * @return array
	 */
	private static function quality_bar() {
		$blueprint = Blogcraft_Blueprint::get();

		// Measured on an empty draft purely to enumerate which checks this
		// site runs — the verdicts are meaningless and are thrown away. It
		// beats keeping a second hand-written list of check names that would
		// drift the first time one was added.
		$sample = Blogcraft_Scorecard::evaluate( '<p>.</p>', $blueprint, array() );
		$checks = array();

		foreach ( (array) $sample['checks'] as $check ) {
			$checks[] = array(
				'name'   => $check['label'],
				'wanted' => $check['target'],
				'weight' => (int) $check['weight'],
			);
		}

		return array(
			'threshold' => (int) Blogcraft_Settings::get( 'quality_threshold' ),
			'checks'    => $checks,
			'note'      => 'Anything below the threshold is held for review rather than published. Score a draft with the check_draft tool.',
		);
	}

	/**
	 * Encode for the wire.
	 *
	 * @param array $data Anything.
	 * @return string
	 */
	private static function encode( $data ) {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG );
	}
}
