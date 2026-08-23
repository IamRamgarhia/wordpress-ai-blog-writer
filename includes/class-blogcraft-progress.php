<?php
/**
 * Watching a post being written, and deciding what happens to it.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * The screen that writes a post while you watch, then shows you the result.
 *
 * This replaces "Queued. The post will be written in the background." — a
 * sentence that was only true when WP-Cron fired, which on staging sites and
 * quiet blogs it often does not, and which even at its most accurate left the
 * reader with nothing to look at and no way to tell working from stuck.
 *
 * Two things change here. The work is driven from this page rather than from
 * cron, so it starts the moment you arrive and cannot silently fail to begin.
 * And the finished draft is shown in full — prose, score, every check — before
 * anything is written to the site, because whether a post is good enough to
 * publish is a judgement about somebody's own blog.
 */
class Blogcraft_Progress {

	/**
	 * Menu slug.
	 */
	const PAGE_SLUG = 'blogcraft-progress';

	/**
	 * Nonce action for the AJAX and approval handlers.
	 */
	const ACTION = 'blogcraft_progress';

	/**
	 * The stages a reader sees, in order, with what each one is doing.
	 *
	 * Named for what is happening rather than for the method that does it:
	 * "research" is a stage name, "Reading what is already out there" is an
	 * answer to "what is it doing right now".
	 *
	 * @return array
	 */
	public static function steps() {
		return array(
			'research' => __( 'Reading what is already out there', 'blogcraft' ),
			'outline'  => __( 'Planning the shape of the post', 'blogcraft' ),
			'draft'    => __( 'Writing the opening', 'blogcraft' ),
			'section'  => __( 'Writing each section', 'blogcraft' ),
			'faq'      => __( 'Answering the questions readers ask', 'blogcraft' ),
			'extras'   => __( 'Adding the extra sections', 'blogcraft' ),
			'critique' => __( 'Reading its own draft back critically', 'blogcraft' ),
			'revise'   => __( 'Rewriting what it found wrong', 'blogcraft' ),
			'verify'   => __( 'Checking links and scoring the result', 'blogcraft' ),
			'publish'  => __( 'Creating the post', 'blogcraft' ),
		);
	}

	/**
	 * Wire the screen.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_blogcraft_advance', array( __CLASS__, 'handle_advance' ) );
		add_action( 'admin_post_blogcraft_approve_draft', array( __CLASS__, 'handle_approve' ) );
	}

	/**
	 * Register the screen without putting it in the menu.
	 *
	 * It belongs to one job and is reached from the Write screen, so a
	 * permanent tab pointing at "whichever post was last written" would be a
	 * tab that is usually wrong.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			'',
			__( 'Writing a post', 'blogcraft' ),
			__( 'Writing a post', 'blogcraft' ),
			Blogcraft_Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Load the script that drives the work.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'blogcraft-admin',
			BLOGCRAFT_URL . 'assets/admin.css',
			array(),
			BLOGCRAFT_VERSION
		);

		wp_enqueue_script(
			'blogcraft-progress',
			BLOGCRAFT_URL . 'assets/progress.js',
			array(),
			BLOGCRAFT_VERSION,
			true
		);

		wp_localize_script(
			'blogcraft-progress',
			'blogcraftProgress',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::ACTION ),
				'job'     => self::current_job_id(),
				'working' => __( 'Working...', 'blogcraft' ),
				'failed'  => __( 'Something went wrong. The Activity screen has the details.', 'blogcraft' ),
			)
		);
	}

	/**
	 * The job this screen is showing.
	 *
	 * @return int
	 */
	private static function current_job_id() {
		// Read-only screen selection, not a state change: the nonce that
		// matters guards the AJAX advance and the approve handler.
		return isset( $_GET['job'] ) ? (int) $_GET['job'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Advance the job one stage and report where it got to.
	 *
	 * One stage per request rather than a loop, so a slow provider call cannot
	 * exceed PHP's execution limit and so the reader sees each step land.
	 *
	 * @return void
	 */
	public static function handle_advance() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'blogcraft' ) ), 403 );
		}

		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! Blogcraft_Request::verify( self::ACTION, $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'That page has expired. Reload it.', 'blogcraft' ) ), 403 );
		}

		$job_id = isset( $_POST['job'] ) ? (int) $_POST['job'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $job_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'No post to write.', 'blogcraft' ) ), 400 );
		}

		Blogcraft_Worker::run_job( $job_id );

		wp_send_json_success( self::state( $job_id ) );
	}

	/**
	 * Everything the screen needs to know about a job.
	 *
	 * @param int $job_id Job id.
	 * @return array
	 */
	public static function state( $job_id ) {
		$job = Blogcraft_Queue::find( (int) $job_id );

		if ( null === $job ) {
			return array(
				'status' => 'gone',
				'stage'  => '',
				'done'   => true,
			);
		}

		$finished = in_array( $job->status, array( 'complete', 'failed', 'cancelled', 'ready' ), true );

		return array(
			'status' => $job->status,
			'stage'  => $job->stage,
			'error'  => (string) $job->last_error,
			// "Done" means stop asking, not "it worked" — a failed job and a
			// draft waiting to be read both want the page to stop polling.
			'done'   => $finished,
			'ready'  => 'ready' === $job->status,
			'postId' => isset( $job->payload['post_id'] ) ? (int) $job->payload['post_id'] : 0,
		);
	}

	/**
	 * Let a held draft become a post.
	 *
	 * @return void
	 */
	public static function handle_approve() {
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::ACTION, $nonce );

		$job_id = isset( $_POST['job'] ) ? (int) $_POST['job'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $job_id > 0 && Blogcraft_Queue::approve( $job_id ) ) {
			Blogcraft_Worker::run_job( $job_id );

			$job = Blogcraft_Queue::find( $job_id );

			// Straight into the editor, which is where somebody who just
			// approved a draft actually wants to be.
			if ( null !== $job && ! empty( $job->payload['post_id'] ) ) {
				wp_safe_redirect( get_edit_post_link( (int) $job->payload['post_id'], 'redirect' ) );
				exit;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'job'  => $job_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			return;
		}

		$job_id = self::current_job_id();
		$job    = $job_id > 0 ? Blogcraft_Queue::find( $job_id ) : null;

		echo '<div class="wrap blogcraft-wrap">';
		Blogcraft_Nav::render();

		if ( null === $job ) {
			echo '<h1>' . esc_html__( 'Writing a post', 'blogcraft' ) . '</h1>';
			echo '<p>' . esc_html__( 'There is no post here. Start one from Write a post.', 'blogcraft' ) . '</p>';
			printf(
				'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=blogcraft-write' ) ),
				esc_html__( 'Write a post', 'blogcraft' )
			);
			echo '</div>';

			return;
		}

		$topic = isset( $job->payload['topic'] ) ? (string) $job->payload['topic'] : '';

		echo '<h1>' . esc_html( '' === $topic ? __( 'Writing a post', 'blogcraft' ) : $topic ) . '</h1>';

		self::render_steps( $job );

		if ( 'ready' === $job->status ) {
			self::render_review( $job );
		}

		if ( 'failed' === $job->status ) {
			self::render_failure( $job );
		}

		echo '</div>';
	}

	/**
	 * The stage list, with the current one marked.
	 *
	 * @param Blogcraft_Job $job Job being watched.
	 * @return void
	 */
	private static function render_steps( $job ) {
		$steps   = self::steps();
		$order   = array_keys( $steps );
		$current = array_search( $job->stage, $order, true );
		$current = ( false === $current ) ? 0 : (int) $current;
		$held    = ( 'ready' === $job->status );

		echo '<section class="blogcraft-card" id="blogcraft-progress-card"><header>';
		echo '<h2>' . esc_html__( 'What it is doing', 'blogcraft' ) . '</h2>';
		printf(
			'<p id="blogcraft-progress-note">%s</p>',
			esc_html(
				$held
					? __( 'Finished writing. Nothing has been added to your site yet.', 'blogcraft' )
					: __( 'This runs while the page is open. Leaving is safe — it picks up where it stopped.', 'blogcraft' )
			)
		);
		echo '</header><ol class="blogcraft-steps" id="blogcraft-progress-steps">';

		$index = 0;

		foreach ( $steps as $slug => $label ) {
			$state = 'is-todo';

			if ( $held || $index < $current ) {
				$state = 'is-done';
			} elseif ( $index === $current && ! $held ) {
				$state = 'is-now';
			}

			// Publish only counts as done once a post genuinely exists.
			if ( 'publish' === $slug && $held ) {
				$state = 'is-todo';
			}

			printf(
				'<li class="%1$s" data-step="%2$s"><span class="blogcraft-step-mark" aria-hidden="true"></span><span class="blogcraft-step-text"><strong>%3$s</strong></span></li>',
				esc_attr( $state ),
				esc_attr( $slug ),
				esc_html( $label )
			);

			++$index;
		}

		echo '</ol></section>';
	}

	/**
	 * The finished draft, its score, and the decision.
	 *
	 * @param Blogcraft_Job $job Held job.
	 * @return void
	 */
	private static function render_review( $job ) {
		$payload = $job->payload;
		$article = isset( $payload['article'] ) ? (array) $payload['article'] : array();
		$outline = isset( $payload['outline'] ) ? (array) $payload['outline'] : array();
		$quality = isset( $payload['quality'] ) ? (array) $payload['quality'] : array();
		$checks  = isset( $payload['checks'] ) ? (array) $payload['checks'] : array();
		$score   = isset( $quality['score'] ) ? (int) $quality['score'] : 0;

		self::render_score( $score, $checks );
		self::render_preview( $article, $outline );
		self::render_decision( $job, $score );
	}

	/**
	 * The score and every check behind it.
	 *
	 * @param int   $score  Score out of 100.
	 * @param array $checks Check results.
	 * @return void
	 */
	private static function render_score( $score, $checks ) {
		$threshold = (int) Blogcraft_Settings::get( 'quality_threshold' );

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'How it scored', 'blogcraft' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: score out of 100. 2: the threshold set in settings. */
					__( '%1$d out of 100. Your bar is %2$d.', 'blogcraft' ),
					$score,
					$threshold
				)
			)
		);
		echo '</header>';

		if ( empty( $checks ) ) {
			echo '<p>' . esc_html__( 'No checks were recorded for this draft.', 'blogcraft' ) . '</p></section>';

			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Check', 'blogcraft' ) . '</th>';
		echo '<th>' . esc_html__( 'Found', 'blogcraft' ) . '</th>';
		echo '<th>' . esc_html__( 'Wanted', 'blogcraft' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}

			printf(
				'<tr><td>%1$s %2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				empty( $check['pass'] ) ? '<span aria-hidden="true">✕</span>' : '<span aria-hidden="true">✓</span>',
				esc_html( isset( $check['label'] ) ? $check['label'] : '' ),
				esc_html( isset( $check['actual'] ) ? (string) $check['actual'] : '' ),
				esc_html( isset( $check['target'] ) ? (string) $check['target'] : '' )
			);
		}

		echo '</tbody></table></section>';
	}

	/**
	 * The draft itself, as it will read.
	 *
	 * @param array $article Article structure.
	 * @param array $outline Outline, for the title and meta description.
	 * @return void
	 */
	private static function render_preview( $article, $outline ) {
		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'The draft', 'blogcraft' ) . '</h2>';
		echo '<p>' . esc_html__( 'Read it before it becomes a post. Nothing here is on your site yet.', 'blogcraft' ) . '</p>';
		echo '</header>';

		if ( ! empty( $outline['title'] ) ) {
			echo '<h3>' . esc_html( (string) $outline['title'] ) . '</h3>';
		}

		if ( ! empty( $outline['meta_description'] ) ) {
			printf(
				'<p class="description"><strong>%1$s</strong> %2$s</p>',
				esc_html__( 'Search description:', 'blogcraft' ),
				esc_html( (string) $outline['meta_description'] )
			);
		}

		// Rendered through the same block renderer that builds the post, then
		// escaped down to a safe subset — so what is read here is what gets
		// created, not a separate preview that could drift from it.
		$rendered = Blogcraft_Blocks::render( $article );

		echo '<div class="blogcraft-preview">' . wp_kses_post( $rendered ) . '</div>';
		echo '</section>';
	}

	/**
	 * The buttons.
	 *
	 * @param Blogcraft_Job $job   Held job.
	 * @param int           $score Score out of 100.
	 * @return void
	 */
	private static function render_decision( $job, $score ) {
		$threshold = (int) Blogcraft_Settings::get( 'quality_threshold' );

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'What happens to it', 'blogcraft' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				$score < $threshold
					? __( 'This scored below your bar. Creating it anyway is fine — it lands as a draft you can edit.', 'blogcraft' )
					: __( 'Creating it adds it to your posts, using the editor your site is set up for.', 'blogcraft' )
			)
		);
		echo '</header>';

		printf(
			'<form method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="blogcraft_approve_draft" />';
		printf( '<input type="hidden" name="job" value="%d" />', (int) $job->id );
		Blogcraft_Request::nonce_field( self::ACTION );
		submit_button( __( 'Create the post', 'blogcraft' ), 'primary', 'submit', false );
		echo ' ';
		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=blogcraft-write' ) ),
			esc_html__( 'Leave it for now', 'blogcraft' )
		);
		echo '</form></section>';
	}

	/**
	 * What went wrong, when something did.
	 *
	 * @param Blogcraft_Job $job Failed job.
	 * @return void
	 */
	private static function render_failure( $job ) {
		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'It stopped', 'blogcraft' ) . '</h2>';
		echo '</header>';

		$error = trim( (string) $job->last_error );

		if ( '' !== $error ) {
			echo '<p><code>' . esc_html( $error ) . '</code></p>';
		}

		printf(
			'<p><a class="button" href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=blogcraft-activity' ) ),
			esc_html__( 'See the full log', 'blogcraft' )
		);
		echo '</section>';
	}
}
