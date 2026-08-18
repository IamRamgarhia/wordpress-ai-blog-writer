<?php
/**
 * Manual post generation screen.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets a user queue a topic and watch it move through the pipeline.
 *
 * Generation is queued rather than run inline: a full pipeline is five provider
 * calls, which would exceed PHP's execution limit on most shared hosting if it
 * ran during the form submission.
 */
class Blogcraft_Generate {

	/**
	 * Submenu slug.
	 */
	const PAGE_SLUG = 'blogcraft-write';

	/**
	 * Nonce action for queueing a topic.
	 */
	const QUEUE_ACTION = 'blogcraft_queue_topic';

	/**
	 * Nonce action for running the queue on demand.
	 */
	const RUN_ACTION = 'blogcraft_run_queue_now';

	/**
	 * Transient prefix holding the last notice for one user.
	 */
	const NOTICE_TRANSIENT = 'blogcraft_write_notice_';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 15 );
		add_action( 'admin_post_blogcraft_queue_topic', array( __CLASS__, 'handle_queue' ) );
		add_action( 'admin_post_blogcraft_run_queue_now', array( __CLASS__, 'handle_run_now' ) );
	}

	/**
	 * Add the submenu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			Blogcraft_Admin::MENU_SLUG,
			__( 'Write a post', 'blogcraft' ),
			__( 'Write a post', 'blogcraft' ),
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
			wp_die( esc_html__( 'You are not allowed to access this page.', 'blogcraft' ) );
		}

		$notice = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Write a post', 'blogcraft' ) . '</h1>';

		if ( is_array( $notice ) ) {
			delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
			printf(
				'<div class="notice %s"><p>%s</p></div>',
				esc_attr( empty( $notice['ok'] ) ? 'notice-error' : 'notice-success' ),
				esc_html( (string) $notice['message'] )
			);
		}

		if ( null === Blogcraft_Provider_Registry::from_settings() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'No AI provider is configured yet. Set one up under Blogcraft → Settings first.', 'blogcraft' )
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_queue_topic" />';
		Blogcraft_Request::nonce_field( self::QUEUE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="blogcraft_topic">' . esc_html__( 'Topic', 'blogcraft' ) . '</label></th><td>';
		echo '<input type="text" class="large-text" name="topic" id="blogcraft_topic" value="" required />';
		echo '<p class="description">' . esc_html__( 'What should the post be about? A sentence works better than a keyword.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="blogcraft_status">' . esc_html__( 'When finished', 'blogcraft' ) . '</label></th><td>';
		echo '<select name="status" id="blogcraft_status">';
		echo '<option value="draft">' . esc_html__( 'Save as draft for review', 'blogcraft' ) . '</option>';
		echo '<option value="publish">' . esc_html__( 'Publish immediately', 'blogcraft' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Reviewing drafts is strongly recommended.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Queue this post', 'blogcraft' ) );
		echo '</form>';

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Queue', 'blogcraft' ) . '</h2>';
		echo '<ul>';
		foreach ( array( 'pending', 'running', 'complete', 'failed' ) as $status ) {
			printf(
				'<li>%1$s: %2$d</li>',
				esc_html( $status ),
				(int) Blogcraft_Queue::count_by_status( $status )
			);
		}
		echo '</ul>';

		echo '<p>' . esc_html__( 'Queued posts are written in the background, one step at a time. You can also run a step now.', 'blogcraft' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_run_queue_now" />';
		Blogcraft_Request::nonce_field( self::RUN_ACTION );
		submit_button( __( 'Run the queue now', 'blogcraft' ), 'secondary' );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Queue a submitted topic.
	 *
	 * @return void
	 */
	public static function handle_queue() {
		// Read then verify; Blogcraft_Request performs the check PHPCS cannot follow statically.
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::QUEUE_ACTION, $nonce );

		// Verified above by Blogcraft_Request::verify_or_die(), which PHPCS cannot follow statically.
		$topic  = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $topic ) {
			self::back( false, __( 'Please enter a topic.', 'blogcraft' ) );
		}

		$job_id = Blogcraft_Pipeline::enqueue_topic( $topic, $status );

		if ( $job_id <= 0 ) {
			self::back( false, __( 'The topic could not be queued.', 'blogcraft' ) );
		}

		self::back( true, __( 'Queued. The post will be written in the background.', 'blogcraft' ) );
	}

	/**
	 * Drain the queue on demand.
	 *
	 * @return void
	 */
	public static function handle_run_now() {
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::RUN_ACTION, $nonce );

		Blogcraft_Queue::reclaim_stale();
		$executed = Blogcraft_Worker::run();

		self::back(
			true,
			sprintf(
				/* translators: %d: number of pipeline steps that ran. */
				_n( '%d step ran.', '%d steps ran.', $executed, 'blogcraft' ),
				$executed
			)
		);
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
