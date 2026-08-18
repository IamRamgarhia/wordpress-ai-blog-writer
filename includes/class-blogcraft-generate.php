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
	 * Nonce action for bulk topic import.
	 */
	const BULK_ACTION = 'blogcraft_bulk_topics';

	/**
	 * Nonce action for rolling a batch back.
	 */
	const ROLLBACK_ACTION = 'blogcraft_rollback';

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
		add_action( 'admin_post_blogcraft_bulk_topics', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_blogcraft_rollback', array( __CLASS__, 'handle_rollback' ) );
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

		echo '<div class="wrap blogcraft-page">';
		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Write a post', 'blogcraft' ) . '</h1>';
		echo '<p>' . esc_html__( 'Give it a topic. It researches, drafts, critiques its own work, rewrites, then checks the result before anything reaches your site.', 'blogcraft' ) . '</p>';
		echo '</div>';

		if ( is_array( $notice ) ) {
			delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
			printf(
				'<div class="notice %s"><p>%s</p></div>',
				esc_attr( empty( $notice['ok'] ) ? 'notice-error' : 'notice-success' ),
				esc_html( (string) $notice['message'] )
			);
		}

		if ( ! Blogcraft_Provider_Registry::is_configured() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'No AI provider is configured yet. Set one up under Blogcraft → Settings first.', 'blogcraft' )
			);
		}

		self::card_open( __( 'One post', 'blogcraft' ), __( 'Queue a single topic, with anything specific you want for this one.', 'blogcraft' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_queue_topic" />';
		Blogcraft_Request::nonce_field( self::QUEUE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="blogcraft_topic">' . esc_html__( 'Topic', 'blogcraft' ) . '</label></th><td>';
		echo '<input type="text" class="large-text" name="topic" id="blogcraft_topic" value="" required />';
		echo '<p class="description">' . esc_html__( 'What should the post be about? A sentence works better than a keyword.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="blogcraft_instructions">' . esc_html__( 'Extra instructions', 'blogcraft' ) . '</label></th><td>';
		echo '<textarea class="large-text" name="instructions" id="blogcraft_instructions" rows="2"></textarea>';
		echo '<p class="description">' . esc_html__( 'Optional. An angle, a target keyword, anything specific to this post. This is what stops every post reading the same.', 'blogcraft' ) . '</p>';
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

		echo '</section>';
		self::card_open( __( 'Queue', 'blogcraft' ), __( 'Posts are written in the background, one step per run.', 'blogcraft' ) );
		echo '<ul class="blogcraft-stats">';
		foreach ( array( 'pending', 'running', 'complete', 'failed' ) as $status ) {
			printf(
				'<li><span class="blogcraft-stat-value">%2$d</span><span class="blogcraft-stat-label">%1$s</span></li>',
				esc_html( $status ),
				(int) Blogcraft_Queue::count_by_status( $status )
			);
		}
		echo '</ul>';

		echo '<p class="description">' . esc_html__( 'A step runs on its own every few minutes. Run one by hand if you would rather not wait.', 'blogcraft' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_run_queue_now" />';
		Blogcraft_Request::nonce_field( self::RUN_ACTION );
		submit_button( __( 'Run the queue now', 'blogcraft' ), 'secondary' );
		echo '</form>';

		echo '</section>';
		self::card_open( __( 'Add many at once', 'blogcraft' ), __( 'One topic per line, or paste a CSV column.', 'blogcraft' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_bulk_topics" />';
		Blogcraft_Request::nonce_field( self::BULK_ACTION );
		echo '<textarea class="large-text code" name="topics" rows="6" placeholder="' . esc_attr__( 'One topic per line, or paste a CSV column', 'blogcraft' ) . '"></textarea>';
		echo '<p class="description">' . esc_html__( 'Anything already covered by an existing post is skipped rather than queued twice.', 'blogcraft' ) . '</p>';
		submit_button( __( 'Queue all of these', 'blogcraft' ), 'secondary', 'submit', true );
		echo '</form>';

		echo '</section>';
		self::card_open( __( 'Undo a batch', 'blogcraft' ), __( 'Only touches posts Blogcraft created. Anything you wrote is left alone.', 'blogcraft' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(' . esc_attr( "'" . esc_js( __( 'Move recently generated posts to the trash?', 'blogcraft' ) ) . "'" ) . ');">';
		echo '<input type="hidden" name="action" value="blogcraft_rollback" />';
		Blogcraft_Request::nonce_field( self::ROLLBACK_ACTION );
		submit_button( __( 'Trash the last 24 hours', 'blogcraft' ), 'delete', 'submit', false );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Open a card section.
	 *
	 * @param string $title       Card title.
	 * @param string $description One line on what it is for.
	 * @return void
	 */
	private static function card_open( $title, $description ) {
		printf(
			'<section class="blogcraft-card"><header><h2>%1$s</h2><p>%2$s</p></header>',
			esc_html( $title ),
			esc_html( $description )
		);
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

		$instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$job_id       = Blogcraft_Pipeline::enqueue_topic( $topic, $status, $instructions );

		if ( $job_id <= 0 ) {
			$clash = Blogcraft_Settings::get( 'duplicate_check_enabled' )
				? Blogcraft_Backlinks::find_duplicate( $topic )
				: '';

			if ( '' !== $clash ) {
				self::back(
					false,
					sprintf(
						/* translators: %s: the existing topic this one duplicates. */
						__( 'Skipped: too similar to a post you already have about "%s".', 'blogcraft' ),
						$clash
					)
				);
			}

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

		$before   = Blogcraft_Queue::count_with_errors();
		$executed = Blogcraft_Worker::run();
		$after    = Blogcraft_Queue::count_with_errors();

		$message = sprintf(
			/* translators: %d: number of pipeline steps that ran. */
			_n( '%d step ran.', '%d steps ran.', $executed, 'blogcraft' ),
			$executed
		);

		// Steps that ran and failed still count as steps, so saying only how
		// many ran would report a broken setup as a success.
		if ( $after > $before ) {
			self::back(
				false,
				$message . ' ' . __( 'Something went wrong. Blogcraft → Activity has the reason.', 'blogcraft' )
			);
		}

		self::back( true, $message );
	}

	/**
	 * Queue many topics at once.
	 *
	 * @return void
	 */
	public static function handle_bulk() {
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::BULK_ACTION, $nonce );

		$raw     = isset( $_POST['topics'] ) ? sanitize_textarea_field( wp_unslash( $_POST['topics'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$queued  = 0;
		$skipped = 0;

		foreach ( preg_split(
			'/[
]+/',
			$raw
		) as $line ) {
			// A pasted CSV column brings its commas with it; the first field is the topic.
			$topic = trim( (string) strtok( trim( (string) $line ), ',' ) );

			if ( '' === $topic ) {
				continue;
			}

			if ( Blogcraft_Pipeline::enqueue_topic( $topic ) > 0 ) {
				++$queued;
			} else {
				++$skipped;
			}
		}

		self::back(
			true,
			sprintf(
				/* translators: 1: number queued, 2: number skipped as duplicates. */
				__( '%1$d queued, %2$d skipped as too similar to existing posts.', 'blogcraft' ),
				$queued,
				$skipped
			)
		);
	}

	/**
	 * Trash generated posts from the last day.
	 *
	 * @return void
	 */
	public static function handle_rollback() {
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::ROLLBACK_ACTION, $nonce );

		// The generated-by-Blogcraft meta is the guard that keeps this away from
		// anything a human wrote.
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_key'       => '_blogcraft_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'date_query'     => array(
					array( 'after' => '24 hours ago' ),
				),
			)
		);

		$trashed = 0;

		foreach ( $posts as $post ) {
			if ( wp_trash_post( $post->ID ) ) {
				++$trashed;
			}
		}

		self::back(
			true,
			sprintf(
				/* translators: %d: number of posts moved to the trash. */
				_n( '%d post moved to the trash.', '%d posts moved to the trash.', $trashed, 'blogcraft' ),
				$trashed
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
