<?php
/**
 * Uninstall routine.
 *
 * Removes every trace of Blogcraft when the plugin is deleted.
 *
 * @package Blogcraft
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'blogcraft_uninstall_cleanup' ) ) {

	/**
	 * Delete all Blogcraft data.
	 *
	 * @return void
	 */
	function blogcraft_uninstall_cleanup() {
		if ( class_exists( 'Blogcraft_Scheduler' ) ) {
			Blogcraft_Scheduler::unschedule();
		}

		if ( class_exists( 'Blogcraft_Autopilot' ) ) {
			Blogcraft_Autopilot::unschedule();
		}

		if ( class_exists( 'Blogcraft_Capabilities' ) ) {
			Blogcraft_Capabilities::remove();
		}

		if ( class_exists( 'Blogcraft_Migrator' ) ) {
			Blogcraft_Migrator::drop_tables();
		}

		delete_option( 'blogcraft_settings' );
		delete_option( 'blogcraft_cron_heartbeat' );
		delete_option( 'blogcraft_activated_at' );
		delete_option( 'blogcraft_cost' );
		delete_option( 'blogcraft_autopilot_counter' );

		delete_metadata( 'user', 0, 'blogcraft_dismissed_notices', '', true );

		// The posts themselves are the user's and stay. Everything Blogcraft
		// attached to them goes, because the note at the top of this file says
		// every trace is removed and it was not true of any of these.
		$post_meta = array(
			'_blogcraft_generated',
			'_blogcraft_words',
			'_blogcraft_quality',
			'_blogcraft_quality_reasons',
			'_blogcraft_checks',
			'_blogcraft_metrics',
			'_blogcraft_topic',
			'_blogcraft_faq_schema',
			'_blogcraft_refreshed',
		);

		foreach ( $post_meta as $key ) {
			delete_metadata( 'post', 0, $key, '', true );
		}
	}
}

if ( defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-blogcraft-autoloader.php';

	if ( ! defined( 'BLOGCRAFT_PATH' ) ) {
		define( 'BLOGCRAFT_PATH', plugin_dir_path( __FILE__ ) );
	}

	Blogcraft_Autoloader::register();
	blogcraft_uninstall_cleanup();
}
