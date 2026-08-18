<?php
/**
 * Scheduled generation from a topic queue.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a list of topics into posts on a schedule.
 *
 * Volume defaults are deliberately conservative. Publishing unreviewed content
 * at scale is what search engines penalise as scaled content abuse, so the
 * daily cap exists to make the safe path the default one rather than an option
 * a user has to discover.
 */
class Blogcraft_Autopilot {

	/**
	 * Cron hook that queues the next topic.
	 */
	const HOOK = 'blogcraft_autopilot_tick';

	/**
	 * Option holding the day's generation count, as "Y-m-d|count".
	 */
	const COUNTER_OPTION = 'blogcraft_autopilot_counter';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
	}

	/**
	 * Schedule the daily check.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	/**
	 * Remove the scheduled check.
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Remaining topics, in order.
	 *
	 * @return array
	 */
	public static function topics() {
		return Blogcraft_Voice::to_list( Blogcraft_Settings::get( 'autopilot_topics' ) );
	}

	/**
	 * How many posts have already been queued today.
	 *
	 * @return int
	 */
	public static function generated_today() {
		$raw = (string) get_option( self::COUNTER_OPTION, '' );

		if ( '' === $raw || false === strpos( $raw, '|' ) ) {
			return 0;
		}

		list( $day, $count ) = explode( '|', $raw, 2 );

		return ( gmdate( 'Y-m-d' ) === $day ) ? (int) $count : 0;
	}

	/**
	 * Record one more generation for today.
	 *
	 * @return void
	 */
	private static function increment_today() {
		update_option(
			self::COUNTER_OPTION,
			gmdate( 'Y-m-d' ) . '|' . ( self::generated_today() + 1 ),
			false
		);
	}

	/**
	 * Queue the oldest stale post for a rewrite, if refreshing is on.
	 *
	 * @return bool Whether anything was queued.
	 */
	private static function maybe_refresh() {
		if ( ! Blogcraft_Settings::get( 'refresh_enabled' ) ) {
			return false;
		}

		$stale = Blogcraft_Refresh::find_stale( null, 1 );

		if ( empty( $stale ) ) {
			return false;
		}

		$job_id = Blogcraft_Refresh::enqueue_post( $stale[0]->ID );

		if ( $job_id <= 0 ) {
			return false;
		}

		self::increment_today();

		Blogcraft_Logger::info(
			'Autopilot queued a refresh.',
			array( 'post_id' => (int) $stale[0]->ID ),
			(int) $job_id
		);

		return true;
	}

	/**
	 * Remove a topic from the stored list once it has been queued.
	 *
	 * @param string $topic Topic to drop.
	 * @return void
	 */
	private static function consume_topic( $topic ) {
		$remaining = array();

		foreach ( self::topics() as $candidate ) {
			if ( $candidate !== $topic ) {
				$remaining[] = $candidate;
			}
		}

		Blogcraft_Settings::set( 'autopilot_topics', implode( "\n", $remaining ) );
	}

	/**
	 * Queue the next topic if everything allows it.
	 *
	 * @return bool Whether a topic was queued.
	 */
	public static function tick() {
		if ( ! Blogcraft_Settings::get( 'autopilot_enabled' ) ) {
			return false;
		}

		$cap = (int) Blogcraft_Settings::get( 'autopilot_per_day' );

		if ( $cap > 0 && self::generated_today() >= $cap ) {
			return false;
		}

		if ( Blogcraft_Cost::over_cap() ) {
			Blogcraft_Logger::error( 'Autopilot paused: monthly token cap reached.', array(), null );

			return false;
		}

		$topics = self::topics();

		if ( empty( $topics ) ) {
			// Nothing new to write is the right moment to improve something old.
			return self::maybe_refresh();
		}

		$topic  = $topics[0];
		$status = ( 'publish' === Blogcraft_Settings::get( 'autopilot_status' ) ) ? 'publish' : 'draft';
		$job_id = Blogcraft_Pipeline::enqueue_topic( $topic, $status );

		if ( $job_id <= 0 ) {
			return false;
		}

		self::consume_topic( $topic );
		self::increment_today();

		Blogcraft_Logger::info( 'Autopilot queued a topic.', array( 'topic' => $topic ), (int) $job_id );

		return true;
	}
}
