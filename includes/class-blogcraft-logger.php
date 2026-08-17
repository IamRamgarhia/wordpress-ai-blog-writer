<?php
/**
 * Structured logging.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes plugin events to a rotated custom table.
 *
 * A dedicated table rather than error_log() keeps diagnostics visible to the
 * site owner in wp-admin, and rotation stops it growing without bound.
 * error_log() is additionally forbidden by Plugin Check.
 */
class Blogcraft_Logger {

	/**
	 * Record an event.
	 *
	 * @param string   $level   One of 'info', 'warning', 'error'.
	 * @param string   $message Human-readable message.
	 * @param array    $context Structured detail.
	 * @param int|null $job_id  Related job, if any.
	 * @return void
	 */
	public static function log( $level, $message, $context = array(), $job_id = null ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Blogcraft_Migrator::table_name( 'log' ),
			array(
				'job_id'     => $job_id,
				'level'      => substr( (string) $level, 0, 20 ),
				'message'    => (string) $message,
				'context'    => empty( $context ) ? null : wp_json_encode( $context ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Record an informational event.
	 *
	 * @param string   $message Message.
	 * @param array    $context Context.
	 * @param int|null $job_id  Job id.
	 * @return void
	 */
	public static function info( $message, $context = array(), $job_id = null ) {
		self::log( 'info', $message, $context, $job_id );
	}

	/**
	 * Record an error event.
	 *
	 * @param string   $message Message.
	 * @param array    $context Context.
	 * @param int|null $job_id  Job id.
	 * @return void
	 */
	public static function error( $message, $context = array(), $job_id = null ) {
		self::log( 'error', $message, $context, $job_id );
	}

	/**
	 * Fetch the most recent entries, newest first.
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public static function recent( $limit = 50 ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'log' );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		foreach ( $rows as $index => $row ) {
			$rows[ $index ]['id']      = (int) $row['id'];
			$rows[ $index ]['job_id']  = null === $row['job_id'] ? null : (int) $row['job_id'];
			$rows[ $index ]['context'] = null === $row['context'] ? array() : (array) json_decode( $row['context'], true );
		}

		return $rows;
	}

	/**
	 * Delete all but the newest entries.
	 *
	 * @param int $keep Number of rows to retain.
	 * @return int Rows deleted.
	 */
	public static function rotate( $keep = 1000 ) {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'log' );

		$cutoff = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d", $keep ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( null === $cutoff ) {
			return 0;
		}

		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "DELETE FROM {$table} WHERE id <= %d", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Remove every log entry.
	 *
	 * @return void
	 */
	public static function clear() {
		global $wpdb;

		$table = Blogcraft_Migrator::table_name( 'log' );
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
