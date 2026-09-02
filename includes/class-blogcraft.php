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

		// Without this, every one of the plugin's translatable strings stays
		// in English on any site not served translations by wordpress.org —
		// which includes anyone who installed a language pack by hand, and
		// anyone running it before it is listed there. The .pot file, the
		// translator comments and the _n() calls were all being maintained
		// for a file nothing ever loaded.

		Blogcraft_Scheduler::init();
		Blogcraft_Pipeline::register();
		Blogcraft_Refresh::register();
		Blogcraft_Cli::register();
		Blogcraft_Seo::init();
		Blogcraft_Autopilot::init();
		Blogcraft_Indexnow::init();

		// Registers a REST route, so it runs for every request rather than
		// only in the admin: the client connecting is an application, not a
		// browser, and it never loads an admin screen. The route itself
		// refuses everything until the reader switches it on.
		Blogcraft_Usage::init();
		Blogcraft_Mcp::init();

		// The other half of the same door: applications whose connector
		// dialog has no field for a header have to be signed in instead.
		Blogcraft_Mcp_Oauth::init();

		if ( is_admin() ) {
			// Schema changes used to arrive only through the activation hook,
			// which a one-click update never fires — so an update that needed
			// a new column would have run against a table without it, on
			// every site that updated the normal way. Checked on admin loads
			// rather than every request: it is one option read, and nothing
			// writes to the tables before an admin screen or a cron tick.
			add_action( 'admin_init', array( __CLASS__, 'migrate_if_needed' ) );

			// Schedules are armed at activation and never checked again, so a
			// cron event cleared by a migration, a staging copy, a security
			// plugin or a hosting panel stayed cleared — and the queue simply
			// stopped running, with nothing on any screen saying so. Both
			// helpers already no-op when the event exists.
			add_action( 'admin_init', array( __CLASS__, 'heal_schedules' ) );

			Blogcraft_Admin::init();
			Blogcraft_Connection::init();
			Blogcraft_Generate::init();
			Blogcraft_Review::init();
			Blogcraft_Blueprint_Screen::init();
			Blogcraft_Calendar::init();
			Blogcraft_Activity::init();
			Blogcraft_Progress::init();
			Blogcraft_Library::init();
			Blogcraft_Welcome::init();
			Blogcraft_Docs::init();
		}
	}

	/**
	 * Re-arm the scheduled events if something has cleared them.
	 *
	 * @return void
	 */
	public static function heal_schedules() {
		Blogcraft_Scheduler::schedule();

		// Only when the reader actually wants automatic writing. Arming this
		// on a site with it switched off would put an hourly event back that
		// they may well have cleared on purpose.
		if ( Blogcraft_Settings::get( 'autopilot_enabled' ) ) {
			Blogcraft_Autopilot::schedule();
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
