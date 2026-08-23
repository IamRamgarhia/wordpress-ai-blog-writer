<?php
/**
 * Plugin bootstrap.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's collaborators to WordPress hooks.
 */
class Blogcraft {

	/**
	 * Singleton instance.
	 *
	 * @var Blogcraft|null
	 */
	private static $instance = null;

	/**
	 * Whether run() has already executed.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Private constructor: use instance().
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return Blogcraft
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function run() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		Blogcraft_Scheduler::init();
		Blogcraft_Pipeline::register();
		Blogcraft_Refresh::register();
		Blogcraft_Cli::register();
		Blogcraft_Seo::init();
		Blogcraft_Autopilot::init();
		Blogcraft_Indexnow::init();

		if ( is_admin() ) {
			// Schema changes used to arrive only through the activation hook,
			// which a one-click update never fires — so an update that needed
			// a new column would have run against a table without it, on
			// every site that updated the normal way. Checked on admin loads
			// rather than every request: it is one option read, and nothing
			// writes to the tables before an admin screen or a cron tick.
			add_action( 'admin_init', array( __CLASS__, 'migrate_if_needed' ) );

			Blogcraft_Admin::init();
			Blogcraft_Connection::init();
			Blogcraft_Generate::init();
			Blogcraft_Review::init();
			Blogcraft_Blueprint_Screen::init();
			Blogcraft_Calendar::init();
			Blogcraft_Activity::init();
			Blogcraft_Docs::init();
		}
	}

	/**
	 * Bring the database up to date when the plugin has been updated.
	 *
	 * The stored schema version is compared first, so this does nothing on the
	 * overwhelming majority of loads: dbDelta is idempotent but not free.
	 *
	 * @return void
	 */
	public static function migrate_if_needed() {
		if ( (string) get_option( Blogcraft_Migrator::VERSION_OPTION, '' ) === (string) BLOGCRAFT_DB_VERSION ) {
			return;
		}

		Blogcraft_Migrator::migrate();
	}
}
