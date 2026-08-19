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
		Blogcraft_Notices::init();
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
