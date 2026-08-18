<?php
/**
 * WP-CLI commands.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drives Blogcraft from the command line.
 *
 * Useful beyond convenience: a real system cron running `wp blogcraft run` is
 * the reliable way to drive the queue, because WP-Cron only fires when someone
 * loads a page and a quiet site therefore never publishes.
 */
class Blogcraft_Cli {

	/**
	 * Register the commands when WP-CLI is present.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		WP_CLI::add_command( 'blogcraft generate', array( __CLASS__, 'generate' ) );
		WP_CLI::add_command( 'blogcraft run', array( __CLASS__, 'run' ) );
		WP_CLI::add_command( 'blogcraft status', array( __CLASS__, 'status' ) );
		WP_CLI::add_command( 'blogcraft refresh', array( __CLASS__, 'refresh' ) );
	}

	/**
	 * Queue a topic.
	 *
	 * ## OPTIONS
	 *
	 * <topic>
	 * : What the post should be about.
	 *
	 * [--publish]
	 * : Publish when finished instead of saving a draft.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function generate( $args, $assoc_args ) {
		$topic = isset( $args[0] ) ? (string) $args[0] : '';

		if ( '' === $topic ) {
			WP_CLI::error( 'Give me a topic.' );
		}

		$status = isset( $assoc_args['publish'] ) ? 'publish' : 'draft';
		$job_id = Blogcraft_Pipeline::enqueue_topic( $topic, $status );

		if ( $job_id <= 0 ) {
			WP_CLI::error( 'Not queued. It may be too similar to a post you already have.' );
		}

		WP_CLI::success( sprintf( 'Queued as job %d. Run `wp blogcraft run` to work through it.', $job_id ) );
	}

	/**
	 * Work through the queue.
	 *
	 * ## OPTIONS
	 *
	 * [--budget=<seconds>]
	 * : How long to keep going. Defaults to the configured time budget.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function run( $args, $assoc_args ) {
		$budget = isset( $assoc_args['budget'] ) ? (int) $assoc_args['budget'] : null;

		Blogcraft_Queue::reclaim_stale();
		$executed = Blogcraft_Worker::run( $budget );

		WP_CLI::success( sprintf( '%d step(s) ran.', $executed ) );
	}

	/**
	 * Show the queue and this month's usage.
	 *
	 * @return void
	 */
	public static function status() {
		foreach ( array( 'pending', 'running', 'complete', 'failed' ) as $state ) {
			WP_CLI::line( sprintf( '%-10s %d', $state, Blogcraft_Queue::count_by_status( $state ) ) );
		}

		$totals = Blogcraft_Cost::month_totals();

		WP_CLI::line( '' );
		WP_CLI::line(
			sprintf(
				'tokens    %d prompt, %d completion, across %d request(s)',
				$totals['prompt'],
				$totals['completion'],
				$totals['requests']
			)
		);
	}

	/**
	 * Queue stale posts for rewriting.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : How many to queue. Defaults to 1.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public static function refresh( $args, $assoc_args ) {
		$limit = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 1;
		$stale = Blogcraft_Refresh::find_stale( null, $limit );

		if ( empty( $stale ) ) {
			WP_CLI::success( 'Nothing is stale yet.' );

			return;
		}

		$queued = 0;

		foreach ( $stale as $post ) {
			if ( Blogcraft_Refresh::enqueue_post( $post->ID ) > 0 ) {
				++$queued;
			}
		}

		WP_CLI::success( sprintf( '%d post(s) queued for rewriting.', $queued ) );
	}
}
