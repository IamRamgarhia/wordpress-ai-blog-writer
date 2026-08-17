<?php
/**
 * Cron scheduling.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and tears down the recurring queue-processing event.
 */
class Blogcraft_Scheduler {

	/**
	 * Cron hook name.
	 */
	const HOOK = 'blogcraft_run_queue';

	/**
	 * Wire the cron callback.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_queue' ) );
	}

	/**
	 * Schedule the recurring event if it is not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', self::HOOK );
		}
	}

	/**
	 * Remove every scheduled instance of the event.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Whether the event is currently scheduled.
	 *
	 * @return bool
	 */
	public static function is_scheduled() {
		return (bool) wp_next_scheduled( self::HOOK );
	}

	/**
	 * Cron callback: drain the queue and record a heartbeat.
	 *
	 * @return void
	 */
	public static function run_queue() {
		Blogcraft_Cron_Health::record_heartbeat();
		Blogcraft_Worker::run();
		Blogcraft_Logger::rotate( 1000 );
	}
}
