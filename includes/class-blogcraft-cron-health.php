<?php
/**
 * WP-Cron health detection.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Detects when WP-Cron is not actually firing.
 *
 * WP-Cron only runs when someone loads a page. On a low-traffic site
 * scheduled work silently never happens — the single most common support
 * complaint for scheduled-publishing plugins. Recording a heartbeat each time
 * the queue runs lets the admin surface a real warning instead of leaving the
 * user to wonder why nothing published.
 */
class Blogcraft_Cron_Health {

	/**
	 * Option storing the last queue-run timestamp.
	 */
	const HEARTBEAT_OPTION = 'blogcraft_cron_heartbeat';

	/**
	 * Stamp the current time as the last successful run.
	 *
	 * @return void
	 */
	public static function record_heartbeat() {
		update_option( self::HEARTBEAT_OPTION, time(), false );
	}

	/**
	 * Timestamp of the last recorded run.
	 *
	 * @return int Zero if never recorded.
	 */
	public static function last_heartbeat() {
		return (int) get_option( self::HEARTBEAT_OPTION, 0 );
	}

	/**
	 * Whether the heartbeat is older than the threshold.
	 *
	 * @param int $threshold_seconds Age beyond which cron is considered broken.
	 * @return bool
	 */
	public static function is_stale( $threshold_seconds = 900 ) {
		$last = self::last_heartbeat();

		if ( 0 === $last ) {
			return true;
		}

		return ( time() - $last ) > $threshold_seconds;
	}
}
