<?php
/**
 * Admin interface.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers Blogcraft's admin menu.
 */
class Blogcraft_Admin {

	/**
	 * Top-level menu slug.
	 */
	const MENU_SLUG = 'blogcraft';

	/**
	 * Wire admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BLOGCRAFT_FILE ), array( __CLASS__, 'action_links' ) );
		Blogcraft_Notices::init();
	}

	/**
	 * Add shortcuts to the plugin's row on the Plugins screen.
	 *
	 * Someone who has just activated the plugin is looking at that row, not at
	 * the sidebar, and "Blogcraft" under an editing icon is not an obvious
	 * next click when a dozen other plugins were installed the same afternoon.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$ours = array(
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=' . Blogcraft_Welcome::PAGE_SLUG ) ),
				esc_html__( 'Set up', 'dicecodes-ai-blog-writer' )
			),
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=blogcraft-settings' ) ),
				esc_html__( 'Settings', 'dicecodes-ai-blog-writer' )
			),
			// Where the longer explanations live. The plugin's own Help screen
			// covers using it; this is for the walkthroughs and the answers that
			// are too long to sit in an admin panel.
			sprintf(
				'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
				esc_url( Blogcraft_Docs::site_url() ),
				esc_html__( 'Docs', 'dicecodes-ai-blog-writer' )
			),
		);

		return array_merge( $ours, (array) $links );
	}

	/**
	 * Register the top-level menu page.
	 *
	 * @return void
	 */
	public static function register_menu() {
		// The first is the browser title, the second the sidebar label.
		// They do not have to match, and should not here: the admin menu
		// column is about 160px wide, so the full name wrapped onto two
		// lines and pushed every item below it out of line. Only the
		// readme heading and the Plugin Name header have to agree.
		add_menu_page(
			__( 'Dicecodes AI Blog Writer', 'dicecodes-ai-blog-writer' ),
			__( 'AI Blog Writer', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Capabilities::MANAGE,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-edit-large',
			30
		);
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public static function render_dashboard() {
		Blogcraft_Overview::render();
	}
}
