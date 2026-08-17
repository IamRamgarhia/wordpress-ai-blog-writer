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
	 * Custom cron recurrence name.
	 */
	const RECURRENCE = 'blogcraft_five_minutes';

	/**
	 * Custom cron recurrence interval, in seconds.
	 *
	 * Three times this interval is exactly Blogcraft_Cron_Health's default
	 * staleness threshold (900s) — the cadence and the health check are
	 * derived from the same number so they cannot drift apart again.
	 */
	const RECURRENCE_SECONDS = 300;

	/**
	 * Wire the cron callback and register the custom recurrence.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_recurrence' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- interval is self::RECURRENCE_SECONDS (300s), verified in tests.
		add_action( self::HOOK, array( __CLASS__, 'run_queue' ) );
	}

	/**
	 * Add the five-minute recurrence to WP-Cron's schedule list.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function register_recurrence( $schedules ) {
		$schedules[ self::RECURRENCE ] = array(
			'interval' => self::RECURRENCE_SECONDS,
			'display'  => esc_html__( 'Every 5 minutes (Blogcraft)', 'blogcraft' ),
		);

		return $schedules;
	}

	/**
	 * Schedule the recurring event if it is not already scheduled.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, self::RECURRENCE, self::HOOK );
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
		Blogcraft_Queue::reclaim_stale();
		Blogcraft_Worker::run();
		Blogcraft_Logger::rotate( 1000 );
	}
}
