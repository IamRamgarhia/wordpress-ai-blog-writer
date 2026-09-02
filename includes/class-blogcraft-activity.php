<?php
/**
 * Activity screen: recent jobs and the event log.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows what the plugin has actually been doing.
 *
 * Without this screen a failing job is invisible. A job that errors drops back
 * to pending on its backoff, so the queue counts look identical to an idle
 * site, and the reason it failed only ever reached a database table nothing
 * rendered. Every provider misconfiguration — wrong model id, expired key, rate
 * limit — lands here first, so this is the screen that decides whether someone
 * can fix their own setup or simply gives up.
 */
class Blogcraft_Activity {

	/**
	 * Submenu slug.
	 */
	const PAGE_SLUG = 'blogcraft-activity';

	/**
	 * Nonce action for clearing the log.
	 */
	const CLEAR_ACTION = 'blogcraft_clear_log';

	/**
	 * Nonce action for retrying a job.
	 */
	const RETRY_ACTION = 'blogcraft_retry_job';

	/**
	 * Nonce action for stopping a queued job.
	 */
	const CANCEL_ACTION = 'blogcraft_cancel_job';

	/**
	 * Transient prefix for one-shot notices.
	 */
	const NOTICE_TRANSIENT = 'blogcraft_activity_notice_';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 19 );
		add_action( 'admin_post_blogcraft_clear_log', array( __CLASS__, 'handle_clear' ) );
		add_action( 'admin_post_blogcraft_retry_job', array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_post_blogcraft_cancel_job', array( __CLASS__, 'handle_cancel' ) );
	}

	/**
	 * Add the submenu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			Blogcraft_Admin::MENU_SLUG,
			__( 'Activity', 'dicecodes-ai-blog-writer' ),
			__( 'Activity', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'dicecodes-ai-blog-writer' ) );
		}

		echo '<div class="wrap blogcraft-page">';
		Blogcraft_Nav::render();
		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Activity', 'dicecodes-ai-blog-writer' ) . '</h1>';
		echo '<p>' . esc_html__( 'What the plugin has been doing, and why anything stopped.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</div>';

		$notice = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );

		if ( is_array( $notice ) ) {
			delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
			printf(
				'<div class="notice %s"><p>%s</p></div>',
				esc_attr( empty( $notice['ok'] ) ? 'notice-error' : 'notice-success' ),
				esc_html( (string) $notice['message'] )
			);
		}

		self::render_jobs();
		self::render_log();

		echo '</div>';
	}

	/**
	 * Turn a stored UTC timestamp into something local and readable.
	 *
	 * @param string $mysql_utc Datetime in UTC, MySQL format.
	 * @return string
	 */
	private static function local_time( $mysql_utc ) {
		$mysql_utc = (string) $mysql_utc;

		if ( '' === $mysql_utc ) {
			return '—';
		}

		$stamp = strtotime( $mysql_utc . ' UTC' );

		if ( ! $stamp ) {
			return '—';
		}

		return wp_date( 'M j, H:i', $stamp );
	}

	/**
	 * The most recent jobs, with whatever went wrong.
	 *
	 * @return void
	 */
	private static function render_jobs() {
		$jobs = Blogcraft_Queue::recent_jobs( 25 );

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'Recent jobs', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each post moves through the pipeline one step per run. A job that fails waits, then tries again.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</header>';

		if ( empty( $jobs ) ) {
			echo '<p>' . esc_html__( 'Nothing has been queued yet.', 'dicecodes-ai-blog-writer' ) . '</p>';
			echo '</section>';

			return;
		}

		echo '<table class="widefat striped blogcraft-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Job', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Topic', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Step', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Tries', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Updated', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Last problem', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $jobs as $job ) {
			$error  = trim( (string) $job['last_error'] );
			$status = (string) $job['status'];

			echo '<tr>';

			// A job still moving, or waiting for a decision, has a screen of its
			// own — and that screen was reachable only from the redirect that
			// created it. This table listed the job as Running and gave no way
			// to get back to watching it.
			if ( in_array( $status, array( 'pending', 'running', 'ready' ), true ) ) {
				printf(
					'<td><a href="%1$s">%2$d</a></td>',
					esc_url(
						add_query_arg(
							array(
								'page' => Blogcraft_Progress::PAGE_SLUG,
								'job'  => (int) $job['id'],
							),
							admin_url( 'admin.php' )
						)
					),
					(int) $job['id']
				);
			} else {
				printf( '<td>%d</td>', (int) $job['id'] );
			}

			printf( '<td>%s</td>', esc_html( self::topic_of( $job ) ) );
			printf( '<td>%s</td>', esc_html( str_replace( '_', ' ', (string) $job['stage'] ) ) );
			printf(
				'<td><span class="blogcraft-badge is-%1$s">%2$s</span></td>',
				esc_attr( $status ),
				esc_html( $status )
			);
			printf( '<td>%1$d / %2$d</td>', (int) $job['attempts'], (int) $job['max_attempts'] );
			printf( '<td>%s</td>', esc_html( self::local_time( $job['updated_at'] ) ) );

			echo '<td>';

			if ( '' === $error ) {
				echo '—';
			} else {
				printf( '<span class="blogcraft-error">%s</span>', esc_html( $error ) );
			}

			// A failed job has stopped retrying, so the only way back is by hand.
			if ( 'failed' === $status ) {
				self::retry_button( (int) $job['id'] );
			}

			// Queueing twenty topics from a pasted list and changing your mind
			// left no way out but the database.
			if ( in_array( $status, array( 'pending', 'deferred', 'failed' ), true ) ) {
				self::cancel_button( (int) $job['id'] );
			}

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</section>';
	}

	/**
	 * What a job is about.
	 *
	 * A list of numbered rows all reading "research" tells you nothing about
	 * which post is stuck.
	 *
	 * @param array $job Job row.
	 * @return string
	 */
	private static function topic_of( $job ) {
		$payload = isset( $job['payload'] ) ? json_decode( (string) $job['payload'], true ) : null;

		if ( ! is_array( $payload ) ) {
			return '—';
		}

		if ( ! empty( $payload['topic'] ) ) {
			return (string) $payload['topic'];
		}

		// A refresh job carries the post it is rewriting rather than a topic.
		if ( ! empty( $payload['post_id'] ) ) {
			$title = get_the_title( (int) $payload['post_id'] );

			return ( '' === $title ) ? '—' : $title;
		}

		return '—';
	}

	/**
	 * Render the retry control for one exhausted job.
	 *
	 * @param int $job_id Job to offer a retry for.
	 * @return void
	 */
	private static function retry_button( $job_id ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="blogcraft-inline-form">';
		echo '<input type="hidden" name="action" value="blogcraft_retry_job" />';
		printf( '<input type="hidden" name="job_id" value="%d" />', (int) $job_id );
		Blogcraft_Request::nonce_field( self::RETRY_ACTION );
		printf(
			'<button type="submit" class="button button-small" aria-label="%1$s">%2$s</button>',
			esc_attr(
				sprintf(
					/* translators: %d: job number. */
					__( 'Try job %d again', 'dicecodes-ai-blog-writer' ),
					(int) $job_id
				)
			),
			esc_html__( 'Try again', 'dicecodes-ai-blog-writer' )
		);
		echo '</form>';
	}

	/**
	 * A control for stopping a job that has not started.
	 *
	 * @param int $job_id Job to offer cancelling.
	 * @return void
	 */
	private static function cancel_button( $job_id ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="blogcraft-inline-form">';
		echo '<input type="hidden" name="action" value="blogcraft_cancel_job" />';
		printf( '<input type="hidden" name="job_id" value="%d" />', (int) $job_id );
		Blogcraft_Request::nonce_field( self::CANCEL_ACTION );
		printf(
			'<button type="submit" class="button button-small button-link-delete" aria-label="%1$s">%2$s</button>',
			esc_attr(
				sprintf(
					/* translators: %d: job number. */
					__( 'Stop job %d', 'dicecodes-ai-blog-writer' ),
					(int) $job_id
				)
			),
			esc_html__( 'Stop', 'dicecodes-ai-blog-writer' )
		);
		echo '</form>';
	}

	/**
	 * Stop a queued job.
	 *
	 * @return void
	 */
	public static function handle_cancel() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::CANCEL_ACTION, '_blogcraft_nonce' );

		$job_id = isset( $_POST['job_id'] ) ? (int) $_POST['job_id'] : 0;

		if ( $job_id > 0 && Blogcraft_Queue::cancel( $job_id ) ) {
			Blogcraft_Logger::info( 'A queued post was stopped.', array(), $job_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * The event log.
	 *
	 * @return void
	 */
	private static function render_log() {
		$entries = Blogcraft_Logger::recent( 100 );

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'Event log', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'The newest hundred entries. Older ones are trimmed automatically. API keys are never recorded here.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</header>';

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'Nothing logged yet.', 'dicecodes-ai-blog-writer' ) . '</p>';
			echo '</section>';

			return;
		}

		echo '<table class="widefat striped blogcraft-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'When', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Level', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Job', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'What happened', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $entries as $entry ) {
			$level = (string) $entry['level'];

			echo '<tr>';
			printf( '<td>%s</td>', esc_html( self::local_time( $entry['created_at'] ) ) );
			printf(
				'<td><span class="blogcraft-badge is-%1$s">%2$s</span></td>',
				esc_attr( $level ),
				esc_html( $level )
			);
			printf( '<td>%s</td>', null === $entry['job_id'] ? '—' : (int) $entry['job_id'] );

			echo '<td>' . esc_html( (string) $entry['message'] );

			if ( ! empty( $entry['context'] ) ) {
				printf(
					'<br /><span class="blogcraft-context">%s</span>',
					esc_html( self::context_line( (array) $entry['context'] ) )
				);
			}

			echo '</td></tr>';
		}

		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_clear_log" />';
		Blogcraft_Request::nonce_field( self::CLEAR_ACTION );
		submit_button( __( 'Clear the log', 'dicecodes-ai-blog-writer' ), 'secondary', 'submit', true );
		echo '</form>';

		echo '</section>';
	}

	/**
	 * Flatten a context array into one readable line.
	 *
	 * @param array $context Structured detail.
	 * @return string
	 */
	private static function context_line( $context ) {
		$parts = array();

		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$rendered = (string) $value;
			} else {
				$rendered = (string) wp_json_encode( $value );
			}

			if ( strlen( $rendered ) > 300 ) {
				$rendered = substr( $rendered, 0, 300 ) . '…';
			}

			$parts[] = $key . ': ' . $rendered;
		}

		return implode( '  ·  ', $parts );
	}

	/**
	 * Empty the log.
	 *
	 * @return void
	 */
	public static function handle_clear() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::CLEAR_ACTION, '_blogcraft_nonce' );

		Blogcraft_Logger::clear();

		self::back( true, __( 'Log cleared.', 'dicecodes-ai-blog-writer' ) );
	}

	/**
	 * Put one exhausted job back in the queue.
	 *
	 * @return void
	 */
	public static function handle_retry() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::RETRY_ACTION, '_blogcraft_nonce' );

		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;

		if ( $job_id > 0 && Blogcraft_Queue::requeue( $job_id ) ) {
			self::back( true, __( 'Queued again. It will run on the next step.', 'dicecodes-ai-blog-writer' ) );
		}

		self::back( false, __( 'That job could not be queued again.', 'dicecodes-ai-blog-writer' ) );
	}

	/**
	 * Store a one-shot notice and return to the screen.
	 *
	 * @param bool   $ok      Whether the action succeeded.
	 * @param string $message Message to show.
	 * @return void
	 */
	private static function back( $ok, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'ok'      => (bool) $ok,
				'message' => (string) $message,
			),
			60
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
