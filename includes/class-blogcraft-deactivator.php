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
	}
}
