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

		if ( class_exists( 'Blogcraft_Capabilities' ) ) {
			Blogcraft_Capabilities::remove();
		}

		if ( class_exists( 'Blogcraft_Migrator' ) ) {
			Blogcraft_Migrator::drop_tables();
		}

		delete_option( 'blogcraft_settings' );
		delete_option( 'blogcraft_cron_heartbeat' );
		delete_option( 'blogcraft_activated_at' );

		delete_metadata( 'user', 0, 'blogcraft_dismissed_notices', '', true );
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
