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

		load_plugin_textdomain( 'blogcraft', false, dirname( plugin_basename( BLOGCRAFT_FILE ) ) . '/languages' );
		Blogcraft_Scheduler::init();

		if ( is_admin() ) {
			Blogcraft_Admin::init();
		}
	}
}
