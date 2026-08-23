<?php
/**
 * Deactivation routine.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs when the plugin is deactivated.
 *
 * Deliberately leaves tables and settings intact — data removal belongs in
 * uninstall.php, so deactivating for troubleshooting is non-destructive.
 */
class Blogcraft_Deactivator {

	/**
	 * Tear down scheduled work.
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( class_exists( 'Blogcraft_Scheduler' ) ) {
			Blogcraft_Scheduler::unschedule();
		}

		// Both schedules, not just the queue one. WordPress keeps rescheduling
		// a recurring event whose callback no longer exists, so an autopilot
		// tick left behind here does not stop — it fires hourly, forever,
		// finds nothing registered, and does nothing, on a site whose owner
		// switched the plugin off precisely to make it stop.
		if ( class_exists( 'Blogcraft_Autopilot' ) ) {
			Blogcraft_Autopilot::unschedule();
		}
	}
}
