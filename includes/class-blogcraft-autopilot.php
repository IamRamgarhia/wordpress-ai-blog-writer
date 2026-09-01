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
	 * Weekday numbers the user has allowed, 0 for Sunday.
	 *
	 * @return array Sorted, de-duplicated integers in 0..6.
	 */
	public static function days() {
		$raw  = (string) Blogcraft_Settings::get( 'autopilot_days' );
		$days = array();

		foreach ( explode( ',', $raw ) as $piece ) {
			$piece = trim( $piece );

			if ( '' === $piece || ! ctype_digit( $piece ) ) {
				continue;
			}

			$day = (int) $piece;

			if ( $day >= 0 && $day <= 6 ) {
				$days[ $day ] = $day;
			}
		}

		ksort( $days );

		return array_values( $days );
	}

	/**
	 * The hour of the day, in the site's timezone, to start from.
	 *
	 * @return int 0..23.
	 */
	public static function hour() {
		return max( 0, min( 23, (int) Blogcraft_Settings::get( 'autopilot_hour' ) ) );
	}

	/**
	 * Whether now is inside the window the user chose.
	 *
	 * Deliberately "at or after the hour" rather than "in that exact hour".
	 * WP-Cron only fires when someone loads a page, so an exact-hour test would
	 * silently skip the day on any site quiet between 09:00 and 10:00. The
	 * per-day cap is what limits volume; this only decides when to start.
	 *
	 * @param int|null $now Timestamp to test, defaults to now.
	 * @return bool
	 */
	public static function in_window( $now = null ) {
		$days = self::days();

		if ( empty( $days ) ) {
			return false;
		}

		$now = ( null === $now ) ? time() : (int) $now;

		// wp_date() renders in the site's timezone, which is the one the user
		// picked their schedule in.
		$weekday = (int) wp_date( 'w', $now );
		$hour    = (int) wp_date( 'G', $now );

		return in_array( $weekday, $days, true ) && $hour >= self::hour();
	}

	/**
	 * Project when each queued topic will be written.
	 *
	 * Nothing is stored: this walks the same rules tick() applies, so the
	 * calendar cannot drift out of step with what actually happens.
	 *
	 * @param int|null $from Timestamp to plan from, defaults to now.
	 * @return array List of array( topic, timestamp ).
	 */
	public static function plan( $from = null ) {
		$topics = self::topics();

		if ( empty( $topics ) ) {
			return array();
		}

		$days = self::days();

		if ( empty( $days ) ) {
			return array();
		}

		$from    = ( null === $from ) ? time() : (int) $from;
		$hour    = self::hour();
		$per_day = self::per_day();

		// Same reading of zero as tick(): none means none, so there is nothing
		// to draw. This used to read it as one and project a schedule that
		// would never happen.
		if ( $per_day < 1 ) {
			return array();
		}

		// Today's allowance is already partly spent.
		$remaining_today = self::in_window( $from ) ? max( 0, $per_day - self::generated_today() ) : 0;

		$plan   = array();
		$cursor = $from;
		$index  = 0;
		$total  = count( $topics );

		// One iteration per candidate day. Bounded so a pathological setting
		// cannot spin: 366 days is a year of lookahead, which is far more than
		// any usable topic list.
		for ( $offset = 0; $offset <= 366 && $index < $total; $offset++ ) {
			$day_start = strtotime( wp_date( 'Y-m-d', $cursor ) . ' 00:00:00 ' . wp_timezone_string() );

			if ( false === $day_start ) {
				break;
			}

			$slot_time = $day_start + ( $hour * HOUR_IN_SECONDS );

			if ( in_array( (int) wp_date( 'w', $cursor ), $days, true ) ) {
				$slots = ( 0 === $offset ) ? $remaining_today : $per_day;

				for ( $slot = 0; $slot < $slots && $index < $total; $slot++ ) {
					$plan[] = array(
						'topic' => $topics[ $index ],
						'when'  => ( 0 === $offset && $slot_time < $from ) ? $from : $slot_time,
					);
					++$index;
				}
			}

			$cursor += DAY_IN_SECONDS;
		}

		return $plan;
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
	 * The daily limit, as a number that means the same thing everywhere.
	 *
	 * @return int Posts allowed per day; zero means none.
	 */
	public static function per_day() {
		return max( 0, (int) Blogcraft_Settings::get( 'autopilot_per_day' ) );
	}

	/**
	 * Which day the counter is counting, in the site's own timezone.
	 *
	 * The schedule is chosen in site time — "Tuesdays from 9am" means 9am as
	 * the person setting it experiences it, and in_window() reads it that way.
	 * The counter used to roll over at UTC midnight regardless, so on a site
	 * far from UTC the daily allowance reset in the middle of the working day
	 * and the calendar's projection disagreed with what actually ran.
	 *
	 * @param int|null $now Timestamp, defaults to now.
	 * @return string
	 */
	private static function today( $now = null ) {
		return (string) wp_date( 'Y-m-d', ( null === $now ) ? time() : (int) $now );
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

		return ( self::today() === $day ) ? (int) $count : 0;
	}

	/**
	 * Record one more generation for today.
	 *
	 * @return void
	 */
	private static function increment_today() {
		update_option(
			self::COUNTER_OPTION,
			self::today() . '|' . ( self::generated_today() + 1 ),
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
		// A site set up to be driven by an AI client has nothing to run
		// unattended: the model lives in somebody else's application and
		// this site cannot open a conversation with it. The chooser says
		// so in as many words, and this is what makes it true rather than
		// a claim on a settings screen.
		if ( 'client' === (string) Blogcraft_Settings::get( 'setup_path' ) ) {
			return false;
		}

		if ( ! Blogcraft_Settings::get( 'autopilot_enabled' ) ) {
			return false;
		}

		if ( ! self::in_window() ) {
			return false;
		}

		// Zero means write nothing, which is what anyone typing 0 into a field
		// labelled "maximum posts per day" is asking for. It used to mean the
		// opposite here — the cap was skipped entirely, so 0 permitted a post
		// every hour — while plan() read the same 0 as 1 and drew a calendar
		// showing one. Nobody sets a maximum of zero hoping for twenty-four.
		if ( self::generated_today() >= self::per_day() ) {
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
