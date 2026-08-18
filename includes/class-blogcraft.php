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

		if ( is_admin() ) {
			Blogcraft_Admin::init();
			Blogcraft_Connection::init();
			Blogcraft_Generate::init();
			Blogcraft_Review::init();
			Blogcraft_Blueprint_Screen::init();
			Blogcraft_Calendar::init();
			Blogcraft_Activity::init();
		}
	}
}
