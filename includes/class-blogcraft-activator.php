<?php
/**
 * Activation routine.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 */
class Blogcraft_Activator {

	/**
	 * Create schema and grant capabilities.
	 *
	 * @return void
	 */
	public static function activate() {
		Blogcraft_Migrator::migrate();

		// Carry existing voice settings into the default blueprint.
		Blogcraft_Blueprint::migrate_from_voice();
		Blogcraft_Capabilities::add();
		Blogcraft_Scheduler::schedule();
		Blogcraft_Autopilot::schedule();
		Blogcraft_Cron_Health::record_activation();

		// Only a site with nothing set up yet; reactivating a working install
		// should not send anybody back through the introduction.
		Blogcraft_Welcome::arm();
	}
}
