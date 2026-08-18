<?php
/**
 * Token usage accounting.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracks token consumption per calendar month.
 *
 * Usage is kept in a single option keyed by month rather than a table: the data
 * is tiny, it is only ever read on an admin screen, and one option means one
 * thing to remove on uninstall. Months accumulate rather than being pruned so a
 * site owner can see their history; the payload stays small because each month
 * is three integers.
 */
class Blogcraft_Cost {

	/**
	 * Option holding all recorded usage.
	 */
	const OPTION = 'blogcraft_cost';

	/**
	 * Current month key, e.g. 2026-08.
	 *
	 * @return string
	 */
	public static function current_month() {
		return gmdate( 'Y-m' );
	}

	/**
	 * All recorded usage, keyed by month.
	 *
	 * @return array
	 */
	private static function raw() {
		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Add one request's usage to the running total.
	 *
	 * @param string $provider          Provider id.
	 * @param string $model             Model id.
	 * @param int    $prompt_tokens     Prompt tokens.
	 * @param int    $completion_tokens Completion tokens.
	 * @return void
	 */
	public static function record( $provider, $model, $prompt_tokens, $completion_tokens ) {
		$month  = self::current_month();
		$stored = self::raw();

		if ( ! isset( $stored[ $month ] ) ) {
			$stored[ $month ] = array(
				'prompt'     => 0,
				'completion' => 0,
				'requests'   => 0,
			);
		}

		$stored[ $month ]['prompt']     += max( 0, (int) $prompt_tokens );
		$stored[ $month ]['completion'] += max( 0, (int) $completion_tokens );
		$stored[ $month ]['requests']   += 1;

		update_option( self::OPTION, $stored, false );
	}

	/**
	 * Totals for a month.
	 *
	 * @param string|null $month Month key, or null for the current month.
	 * @return array Keys: prompt, completion, requests.
	 */
	public static function month_totals( $month = null ) {
		$month  = ( null === $month ) ? self::current_month() : (string) $month;
		$stored = self::raw();

		if ( ! isset( $stored[ $month ] ) ) {
			return array(
				'prompt'     => 0,
				'completion' => 0,
				'requests'   => 0,
			);
		}

		return array(
			'prompt'     => (int) $stored[ $month ]['prompt'],
			'completion' => (int) $stored[ $month ]['completion'],
			'requests'   => (int) $stored[ $month ]['requests'],
		);
	}

	/**
	 * Whether this month's usage has reached the configured cap.
	 *
	 * A cap of zero means unlimited, so an unconfigured site is never blocked.
	 *
	 * @return bool
	 */
	public static function over_cap() {
		$cap = (int) Blogcraft_Settings::get( 'monthly_token_cap' );

		if ( $cap <= 0 ) {
			return false;
		}

		$totals = self::month_totals();

		return ( $totals['prompt'] + $totals['completion'] ) >= $cap;
	}

	/**
	 * Discard all recorded usage.
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::OPTION );
	}
}
