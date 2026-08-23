<?php
/**
 * Job value object.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * A single unit of queued work.
 *
 * All properties are declared explicitly — dynamic properties are deprecated
 * as of PHP 8.2 and emit notices on 8.5.
 */
class Blogcraft_Job {

	/**
	 * Job id.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Pipeline name.
	 *
	 * @var string
	 */
	public $pipeline = '';

	/**
	 * Current stage name.
	 *
	 * @var string
	 */
	public $stage = '';

	/**
	 * Job status.
	 *
	 * @var string
	 */
	public $status = 'pending';

	/**
	 * Stage payload.
	 *
	 * @var array
	 */
	public $payload = array();

	/**
	 * Attempts made so far.
	 *
	 * @var int
	 */
	public $attempts = 0;

	/**
	 * Attempt ceiling.
	 *
	 * @var int
	 */
	public $max_attempts = 3;

	/**
	 * Why the last attempt stopped, when one did.
	 *
	 * Carried so a screen can say what went wrong without a second query. The
	 * column has always held this; the value object simply never read it, so
	 * anything wanting to show a reason had to go back to the database for it.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Build a job from a database row.
	 *
	 * @param array $row Associative row from the jobs table.
	 * @return Blogcraft_Job
	 */
	public static function from_row( $row ) {
		$job               = new self();
		$job->id           = isset( $row['id'] ) ? (int) $row['id'] : 0;
		$job->pipeline     = isset( $row['pipeline'] ) ? (string) $row['pipeline'] : '';
		$job->stage        = isset( $row['stage'] ) ? (string) $row['stage'] : '';
		$job->status       = isset( $row['status'] ) ? (string) $row['status'] : 'pending';
		$job->attempts     = isset( $row['attempts'] ) ? (int) $row['attempts'] : 0;
		$job->max_attempts = isset( $row['max_attempts'] ) ? (int) $row['max_attempts'] : 3;
		$job->last_error   = isset( $row['last_error'] ) ? (string) $row['last_error'] : '';

		$decoded      = isset( $row['payload'] ) ? json_decode( (string) $row['payload'], true ) : array();
		$job->payload = is_array( $decoded ) ? $decoded : array();

		return $job;
	}
}
