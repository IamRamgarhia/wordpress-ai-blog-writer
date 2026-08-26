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
				esc_html__( 'Set up', 'blogcraft' )
			),
			sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( admin_url( 'admin.php?page=blogcraft-settings' ) ),
				esc_html__( 'Settings', 'blogcraft' )
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
		add_menu_page(
			__( 'Blogcraft', 'blogcraft' ),
			__( 'Blogcraft', 'blogcraft' ),
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
