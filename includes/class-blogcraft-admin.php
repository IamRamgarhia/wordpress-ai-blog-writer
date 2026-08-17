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
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'blogcraft' ) );
		}

		$statuses = array(
			'pending'  => __( 'Pending', 'blogcraft' ),
			'running'  => __( 'Running', 'blogcraft' ),
			'complete' => __( 'Complete', 'blogcraft' ),
			'failed'   => __( 'Failed', 'blogcraft' ),
		);

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Blogcraft', 'blogcraft' ) . '</h1>';
		echo '<p>' . esc_html__( 'Queue status', 'blogcraft' ) . '</p>';
		echo '<ul>';
		foreach ( $statuses as $status => $label ) {
			printf(
				'<li>%1$s: %2$d</li>',
				esc_html( $label ),
				(int) Blogcraft_Queue::count_by_status( $status )
			);
		}
		echo '</ul>';
		echo '</div>';
	}
}
