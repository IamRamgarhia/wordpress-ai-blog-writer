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
	 * Nonce action for sending a finished draft back for another pass.
	 */
	const IMPROVE_ACTION = 'blogcraft_improve_draft';

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
			'research'  => __( 'Reading what is already out there', 'blogcraft' ),
			'outline'   => __( 'Planning the shape of the post', 'blogcraft' ),
			'draft'     => __( 'Writing the opening', 'blogcraft' ),
			'section'   => __( 'Writing each section', 'blogcraft' ),
			'faq'       => __( 'Answering the questions readers ask', 'blogcraft' ),
			'extras'    => __( 'Adding the extra sections', 'blogcraft' ),
			'critique'  => __( 'Reading its own draft back critically', 'blogcraft' ),
			'revise'    => __( 'Rewriting what it found wrong', 'blogcraft' ),
			'verify'    => __( 'Checking links and scoring the result', 'blogcraft' ),
			'publish'   => __( 'Creating the post', 'blogcraft' ),
			'pictures'  => __( 'Finding the pictures', 'blogcraft' ),
			'finishing' => __( 'Linking it up and telling the crawlers', 'blogcraft' ),
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
		add_action( 'admin_post_blogcraft_improve_draft', array( __CLASS__, 'handle_improve' ) );
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
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::ACTION ),
				'job'       => self::current_job_id(),
				// The clock used to count from when the page loaded, so
				// refreshing a job that had been running four minutes said
				// it had been running none. Both of these are measured from
				// the job itself, so a reload picks up where it was.
				'elapsedAt' => self::elapsed_for( self::current_job_id() ),
				'stepsAt'   => self::steps_done_for( self::current_job_id() ),
				'working'   => __( 'Working...', 'blogcraft' ),
				'failed'    => __( 'Something went wrong. The Activity screen has the details.', 'blogcraft' ),
				'total'     => count( self::steps() ),
				/* translators: 1: step number reached. 2: steps in total. */
				'stepOf'    => __( 'Step %1$d of %2$d', 'blogcraft' ),
				/* translators: %s: a duration such as "40s" or "2m 10s". */
				'elapsed'   => __( '%s elapsed', 'blogcraft' ),
				/* translators: %s: a duration such as "40s" or "2m 10s". */
				'remaining' => __( 'about %s left', 'blogcraft' ),
			)
		);
	}

	/**
	 * How long this job has been going, in seconds.
	 *
	 * @param int $job_id Job.
	 * @return int Zero when there is no job or no usable timestamp.
	 */
	private static function elapsed_for( $job_id ) {
		$job = $job_id > 0 ? Blogcraft_Queue::find( (int) $job_id ) : null;

		if ( null === $job || empty( $job->created_at ) ) {
			return 0;
		}

		// Stored as UTC by the queue, which is why the suffix is needed:
		// read as site-local it would be hours out either way.
		$started = strtotime( (string) $job->created_at . ' UTC' );

		if ( false === $started ) {
			return 0;
		}

		return max( 0, time() - $started );
	}

	/**
	 * How many stages this job has already got through.
	 *
	 * The estimate divides elapsed time by steps finished. Counting only
	 * the steps this page happened to watch, against time measured from
	 * the job's start, would make the estimate nonsense after a reload.
	 *
	 * @param int $job_id Job.
	 * @return int
	 */
	private static function steps_done_for( $job_id ) {
		$job = $job_id > 0 ? Blogcraft_Queue::find( (int) $job_id ) : null;

		if ( null === $job ) {
			return 0;
		}

		$order = array_keys( self::steps() );
		$at    = array_search( $job->stage, $order, true );

		return ( false === $at ) ? 0 : (int) $at;
	}

	/**
	 * The job this screen is showing.
	 *
	 * @return int
	 */
	private static function current_job_id() {
		// Read-only screen selection, not a state change: the nonce that
		// matters guards the AJAX advance and the approve handler.
		$asked = isset( $_GET['job'] ) ? (int) $_GET['job'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $asked > 0 ) {
			return $asked;
		}

		// This screen is only ever reached by an id in the address, and it
		// has no menu entry. So refreshing the page, or coming back to the
		// tab, landed on "there is no post here" while the post was still
		// being written. Falling back to whatever is in flight makes the
		// bare address work.
		return Blogcraft_Queue::newest_open_job();
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

		// A rate-limited job is put back as pending with available_at in the
		// future, which is correct — it will resume on its own. But the page
		// cannot claim it meanwhile, so it polled, got nothing, and polled
		// again: working and waiting looked identical, and waiting looked
		// like broken.
		$waits_until = self::waiting_until( $job );

		if ( '' !== $waits_until ) {
			$finished = true;
		}

		$order = array_keys( self::steps() );
		$at    = array_search( $job->stage, $order, true );
		$at    = ( false === $at ) ? 0 : (int) $at;

		return array(
			'status'  => $job->status,
			'stage'   => $job->stage,
			'error'   => (string) $job->last_error,
			// "Done" means stop asking, not "it worked" — a failed job and a
			// draft waiting to be read both want the page to stop polling.
			'done'    => $finished,
			'ready'   => 'ready' === $job->status,
			'postId'  => isset( $job->payload['post_id'] ) ? (int) $job->payload['post_id'] : 0,
			// Position, so the bar and the counter move without the page
			// having to work it out from the stage name a second time.
			'step'    => $finished ? count( $order ) : $at,
			'total'   => count( $order ),
			'label'   => isset( self::steps()[ $job->stage ] ) ? self::steps()[ $job->stage ] : '',
			// What actually exists so far. A bar that fills tells you the
			// machine is alive; the title and the headings tell you whether
			// it is writing the post you asked for — which is the thing you
			// would otherwise wait several minutes to find out.
			'title'   => isset( $job->payload['outline']['title'] ) ? (string) $job->payload['outline']['title'] : '',
			'heads'   => self::headings_so_far( $job ),
			'written' => isset( $job->payload['article']['sections'] ) ? count( (array) $job->payload['article']['sections'] ) : 0,
			'waiting' => $waits_until,
		);
	}

	/**
	 * When a job is deliberately waiting, rather than working.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return string Empty when it is not waiting; otherwise a local time.
	 */
	private static function waiting_until( $job ) {
		if ( 'pending' !== $job->status || '' === $job->available_at ) {
			return '';
		}

		$at = strtotime( $job->available_at . ' UTC' );

		// A minute of slack: a job available "now" is not waiting, and clock
		// skew of a second or two should not be reported as a pause.
		if ( false === $at || $at <= time() + 60 ) {
			return '';
		}

		return (string) wp_date( get_option( 'time_format' ), $at );
	}

	/**
	 * The headings the outline settled on, with the written ones marked.
	 *
	 * @param Blogcraft_Job $job Current job.
	 * @return array
	 */
	private static function headings_so_far( $job ) {
		$planned = isset( $job->payload['outline']['sections'] ) ? (array) $job->payload['outline']['sections'] : array();
		$written = isset( $job->payload['article']['sections'] ) ? (array) $job->payload['article']['sections'] : array();

		$done = array();

		foreach ( $written as $section ) {
			if ( is_array( $section ) && ! empty( $section['heading'] ) ) {
				$done[ strtolower( trim( (string) $section['heading'] ) ) ] = true;
			}
		}

		$out = array();

		foreach ( $planned as $section ) {
			if ( ! is_array( $section ) || empty( $section['heading'] ) ) {
				continue;
			}

			$heading = (string) $section['heading'];

			$out[] = array(
				'text' => $heading,
				'done' => isset( $done[ strtolower( trim( $heading ) ) ] ),
			);
		}

		return $out;
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

		if ( $job_id > 0 ) {
			self::apply_edits( $job_id );
		}

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
	 * Carry the writer's edits into the job before it is published.
	 *
	 * The edited body arrives as plain HTML, because that is what the editor
	 * returns. Blogcraft_Blocks::from_html() puts the block delimiters back so
	 * a block-editor site gets real, individually editable blocks rather than
	 * one enormous Classic block reported as unexpected content.
	 *
	 * Written onto the payload as 'content', which stage_publish already
	 * prefers over re-rendering — so the edited version is what gets created,
	 * and the render path is not duplicated here.
	 *
	 * @param int $job_id Job being approved.
	 * @return void
	 */
	private static function apply_edits( $job_id ) {
		$job = Blogcraft_Queue::find( $job_id );

		if ( null === $job || 'ready' !== $job->status ) {
			return;
		}

		$payload = $job->payload;

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce verified by the caller; the markup is narrowed by wp_kses_post() below, which is the sanitiser for post content and the only one that can keep the writer's formatting.
		$body = isset( $_POST['draft_body'] ) ? (string) wp_unslash( $_POST['draft_body'] ) : '';

		if ( '' !== trim( wp_strip_all_tags( $body ) ) ) {
			// wp_kses_post first: this is writer-supplied markup arriving over
			// a form, so it is narrowed to what a post may contain before
			// anything is done with it.
			$payload['content'] = Blogcraft_Blocks::from_html( wp_kses_post( $body ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$title = isset( $_POST['draft_title'] ) ? sanitize_text_field( wp_unslash( $_POST['draft_title'] ) ) : '';

		if ( '' !== $title ) {
			$payload['outline']['title'] = $title;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the caller.
		$meta = isset( $_POST['draft_meta'] ) ? sanitize_textarea_field( wp_unslash( $_POST['draft_meta'] ) ) : '';

		if ( '' !== $meta ) {
			$payload['outline']['meta_description'] = $meta;
		}

		Blogcraft_Queue::save_payload( $job_id, $payload );
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

		// Read-only; the nonce that matters guarded the handler that set it.
		$outcome = isset( $_GET['improve'] ) ? sanitize_key( wp_unslash( $_GET['improve'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'nothing' === $outcome ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__( 'Nothing left that another writing pass can reach, so no request was made and you were not charged for one. What is still failing is about your site rather than these words.', 'blogcraft' )
			);
		}

		$topic = isset( $job->payload['topic'] ) ? (string) $job->payload['topic'] : '';

		echo '<h1>' . esc_html( '' === $topic ? __( 'Writing a post', 'blogcraft' ) : $topic ) . '</h1>';

		$waiting = self::waiting_until( $job );

		if ( '' !== $waiting ) {
			self::render_waiting( $job, $waiting );
		}

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
	 * Send a finished draft back to be rewritten against its own scorecard.
	 *
	 * The pipeline already does this once, unprompted: critique measures the
	 * draft, turns every failing check into an instruction, and revise acts
	 * on them. So this is a second helping of a pass that has already run,
	 * and the honest thing is to say so rather than imply a button nobody
	 * pressed yet was the difference between a good post and a bad one.
	 *
	 * It is worth offering anyway. A rewrite is not deterministic, the
	 * measurements are, and a second attempt at four specific numbered
	 * faults lands more often than not. What it must not do is charge for a
	 * pass that cannot change anything: if nothing failing carries a repair
	 * instruction, no request is made.
	 *
	 * @return void
	 */
	public static function handle_improve() {
		// Read then verify; Blogcraft_Request performs the check PHPCS cannot
		// follow statically.
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::IMPROVE_ACTION, $nonce );

		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'blogcraft' ) );
		}

		$job_id = isset( $_POST['job'] ) ? (int) $_POST['job'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$job    = $job_id > 0 ? Blogcraft_Queue::find( $job_id ) : null;

		if ( null === $job || 'ready' !== $job->status ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
			exit;
		}

		$payload = $job->payload;
		$checks  = isset( $payload['checks'] ) ? (array) $payload['checks'] : array();
		$fixable = Blogcraft_Scorecard::fixable( $checks );

		if ( empty( $fixable ) ) {
			// Nothing a rewrite owns. Spending a request here would cost real
			// money to produce the same draft and the same score.
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => self::PAGE_SLUG,
						'job'     => $job_id,
						'improve' => 'nothing',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$problems = array();

		foreach ( $fixable as $check ) {
			$problems[] = (string) $check['repair'];
		}

		$payload['problems'] = $problems;

		// Kept so the screen can say what the second pass actually bought,
		// rather than showing a new number with nothing to compare it to.
		$payload['score_before'] = isset( $payload['quality']['score'] ) ? (int) $payload['quality']['score'] : 0;

		Blogcraft_Queue::save_payload( $job_id, $payload );

		// Straight to revise. Going back to critique would pay for a second
		// opinion the plugin already has in writing.
		Blogcraft_Queue::reopen( $job_id, 'revise' );

		Blogcraft_Logger::info(
			'A finished draft was sent back for another pass.',
			array( 'to_fix' => count( $problems ) ),
			$job_id
		);

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

		$total = count( $steps );
		$done  = $held ? $total : $current;

		// Where "written but not yet a post" stops. Everything from here
		// on is work that only happens once somebody says yes.
		$after = array_search( 'publish', $order, true );
		$after = ( false === $after ) ? $total : (int) $after;

		echo '<section class="blogcraft-card" id="blogcraft-progress-card"><header>';
		echo '<h2>' . esc_html__( 'What it is doing', 'blogcraft' ) . '</h2>';

		printf(
			'<div class="bc-progress-bar"><span id="blogcraft-progress-fill" style="width:%d%%"></span></div>',
			(int) ( $total > 0 ? round( ( $done / $total ) * 100 ) : 0 )
		);

		echo '<div class="bc-progress-meta">';
		printf(
			'<span id="blogcraft-progress-count">%s</span>',
			esc_html(
				sprintf(
					/* translators: 1: steps finished. 2: steps in total. */
					__( 'Step %1$d of %2$d', 'blogcraft' ),
					(int) min( $done + ( $held ? 0 : 1 ), $total ),
					(int) $total
				)
			)
		);
		printf( '<span id="blogcraft-progress-clock"></span>' );
		echo '</div>';

		printf(
			'<p id="blogcraft-progress-note">%s</p>',
			esc_html(
				$held
					? __( 'Finished writing. Nothing has been added to your site yet.', 'blogcraft' )
					: __( 'This runs while the page is open. Leaving is safe — it picks up where it stopped.', 'blogcraft' )
			)
		);
		echo '</header>';

		// Once the draft is held the live panel is hidden, and a two-column
		// grid with one column gone leaves the steps stranded at half width
		// beside nothing.
		printf( '<div class="bc-run%s">', $held ? ' is-held' : '' );

		// Filled in by the script as the outline and the sections arrive. It
		// starts with a line saying so rather than as an empty panel: reserving
		// the space stopped the layout jumping when the first heading landed,
		// but an unexplained grey slab sitting there for the first minute reads
		// as something that has failed to load.
		printf(
			'<div class="bc-live" id="blogcraft-live"%1$s>'
			. '<p class="bc-live-wait" id="blogcraft-live-wait">%2$s</p>'
			. '<h3 id="blogcraft-live-title"></h3>'
			. '<ul class="bc-live-heads" id="blogcraft-live-heads"></ul>'
			. '</div>',
			$held ? ' hidden' : '',
			esc_html__( 'The title and the headings appear here as they are decided.', 'blogcraft' )
		);

		echo '<ol class="blogcraft-steps" id="blogcraft-progress-steps">';

		$index = 0;

		foreach ( $steps as $slug => $label ) {
			$state = 'is-todo';

			if ( $held || $index < $current ) {
				$state = 'is-done';
			} elseif ( $index === $current && ! $held ) {
				$state = 'is-now';
			}

			// Publish and everything after it only count as done once a
			// post genuinely exists. Naming publish alone was right when it
			// was the last step; splitting the pictures and the linking out
			// of it left those two showing as finished below a publish step
			// that was not — a filled tick, a hollow one, then two more
			// filled ticks, which reads as something having gone wrong.
			if ( $held && $index >= $after ) {
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

		echo '</ol>';
		echo '</div>';
		echo '</section>';
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

		self::render_score( $score, $checks, isset( $payload['score_before'] ) ? (int) $payload['score_before'] : 0 );
		self::render_prospects( $job, $article, $checks );
		self::render_preview( $article, $outline );
		self::render_decision( $job, $score );
	}

	/**
	 * What is working against this post being found.
	 *
	 * Not a prediction, and it says so on the screen. The list is only the
	 * blockers that can be checked on this site with nothing bought and no
	 * extra key — which is a narrower claim than a ranking estimate and the
	 * only one that would be true.
	 *
	 * @param Blogcraft_Job $job     Held job.
	 * @param array         $article Article structure.
	 * @param array         $checks  Scorecard results.
	 * @return void
	 */
	private static function render_prospects( $job, $article, $checks ) {
		$payload = $job->payload;
		$post_id = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;

		$blockers = Blogcraft_Prospects::blockers( $post_id, $article, $checks, $payload );

		echo '<section class="blogcraft-card bc-prospects-card">';
		echo '<div class="bc-prospects-head">';
		echo '<h2>' . esc_html__( 'What would hold this back', 'blogcraft' ) . '</h2>';
		printf( '<p>%s</p>', esc_html( Blogcraft_Prospects::caveat() ) );
		echo '</div>';

		if ( empty( $blockers ) ) {
			printf(
				'<p class="bc-prospects-clear">%s</p>',
				esc_html__( 'Nothing this plugin can see. It does not duplicate a post you already have, it says something that is yours, it is the length it was written to be, and your site points at it.', 'blogcraft' )
			);
			echo '</section>';

			return;
		}

		echo '<ul class="bc-prospects">';

		foreach ( $blockers as $blocker ) {
			printf(
				'<li><h3>%1$s</h3><p>%2$s</p><p class="bc-prospects-fix">%3$s</p></li>',
				esc_html( $blocker['title'] ),
				esc_html( $blocker['detail'] ),
				esc_html( $blocker['fix'] )
			);
		}

		echo '</ul>';
		echo '</section>';
	}
	/**
	 * The score and every check behind it.
	 *
	 * @param int   $score  Score out of 100.
	 * @param array $checks Check results.
	 * @param int   $before Score before a second pass, or 0 if there was none.
	 * @return void
	 */
	private static function render_score( $score, $checks, $before = 0 ) {
		$threshold = (int) Blogcraft_Settings::get( 'quality_threshold' );
		$clears    = ( $score >= $threshold );

		$failed = array();
		$passed = array();

		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) ) {
				continue;
			}

			if ( empty( $check['pass'] ) ) {
				$failed[] = $check;
			} else {
				$passed[] = $check;
			}
		}

		echo '<section class="blogcraft-card bc-score-card">';
		echo '<div class="bc-score-head">';

		printf(
			'<div class="bc-score-dial %1$s"><strong>%2$d</strong><span>%3$s</span></div>',
			esc_attr( $clears ? 'is-ok' : 'is-under' ),
			(int) $score,
			esc_html__( 'out of 100', 'blogcraft' )
		);

		echo '<div class="bc-score-words">';
		echo '<h2>' . esc_html__( 'How it scored', 'blogcraft' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				$clears
					? sprintf(
						/* translators: 1: number of checks passed. 2: checks in total. 3: the threshold set in settings. */
						__( 'Passed %1$d of %2$d checks, and clears your bar of %3$d.', 'blogcraft' ),
						count( $passed ),
						count( $passed ) + count( $failed ),
						$threshold
					)
					: sprintf(
						/* translators: 1: number of checks passed. 2: checks in total. 3: the threshold set in settings. */
						__( 'Passed %1$d of %2$d checks, which is under your bar of %3$d. You can still create it — it lands as a draft.', 'blogcraft' ),
						count( $passed ),
						count( $passed ) + count( $failed ),
						$threshold
					)
			)
		);
		// What the second pass bought. A new number on its own tells nobody
		// whether pressing the button was worth a request, and "it went down"
		// is a real outcome that has to be reportable — a rewrite is not
		// deterministic, and hiding the losses would make the wins a lie.
		if ( $before > 0 ) {
			$gap = (int) $score - (int) $before;

			if ( 0 === $gap ) {
				$line = sprintf(
					/* translators: %d: the score, unchanged. */
					__( 'The second pass left it at %d. Nothing measurable changed.', 'blogcraft' ),
					(int) $score
				);
			} elseif ( $gap > 0 ) {
				$line = sprintf(
					/* translators: 1: previous score. 2: how many points it gained. */
					__( 'Up from %1$d after another pass, so that one bought %2$d points.', 'blogcraft' ),
					(int) $before,
					(int) $gap
				);
			} else {
				$line = sprintf(
					/* translators: %d: the previous, higher score. */
					__( 'Down from %d. The rewrite made it worse, which happens — the earlier wording is in the editor history.', 'blogcraft' ),
					(int) $before
				);
			}

			printf(
				'<p class="bc-score-delta %1$s">%2$s</p>',
				esc_attr( $gap > 0 ? 'is-up' : ( $gap < 0 ? 'is-down' : 'is-flat' ) ),
				esc_html( $line )
			);
		}

		echo '</div></div>';

		if ( empty( $failed ) && empty( $passed ) ) {
			echo '<p>' . esc_html__( 'No checks were recorded for this draft.', 'blogcraft' ) . '</p></section>';

			return;
		}

		// Failures first and expanded, passes folded away. A flat list of
		// twenty-odd rows buries the four that need a decision among the
		// sixteen that do not.
		if ( ! empty( $failed ) ) {
			echo '<h3 class="bc-check-heading">' . esc_html__( 'Worth a look', 'blogcraft' ) . '</h3>';
			self::render_check_list( $failed, false );
		}

		if ( ! empty( $passed ) ) {
			printf(
				'<details class="bc-check-fold"><summary>%s</summary>',
				esc_html(
					sprintf(
						/* translators: %d: how many checks passed. */
						_n( '%d check passed', '%d checks passed', count( $passed ), 'blogcraft' ),
						count( $passed )
					)
				)
			);
			self::render_check_list( $passed, true );
			echo '</details>';
		}

		self::render_improve( $failed );

		echo '</section>';
	}

	/**
	 * The offer of a second pass, and what it cannot do.
	 *
	 * @param array $failed Failing checks.
	 * @return void
	 */
	private static function render_improve( $failed ) {
		if ( empty( $failed ) ) {
			return;
		}

		$fixable = Blogcraft_Scorecard::fixable( $failed );
		$theirs  = Blogcraft_Scorecard::needs_you( $failed );

		echo '<div class="bc-improve">';

		if ( ! empty( $fixable ) ) {
			printf(
				'<form method="post" action="%s" class="bc-improve-form">',
				esc_url( admin_url( 'admin-post.php' ) )
			);
			echo '<input type="hidden" name="action" value="blogcraft_improve_draft" />';
			printf( '<input type="hidden" name="job" value="%d" />', (int) self::current_job_id() );
			Blogcraft_Request::nonce_field( self::IMPROVE_ACTION );

			printf(
				'<button type="submit" class="button button-secondary">%s</button>',
				esc_html__( 'Have another go at these', 'blogcraft' )
			);

			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: how many failing checks a rewrite can act on. */
						_n(
							'One more writing pass, aimed at the %d fault below that a rewrite can reach. It costs one request, and the draft is measured again afterwards. The post has already been through this once, so treat it as a second attempt rather than a fix.',
							'One more writing pass, aimed at the %d faults below that a rewrite can reach. It costs one request, and the draft is measured again afterwards. The post has already been through this once, so treat it as a second attempt rather than a fix.',
							count( $fixable ),
							'blogcraft'
						),
						count( $fixable )
					)
				)
			);

			echo '</form>';
		}

		if ( ! empty( $theirs ) ) {
			$names = array();

			foreach ( $theirs as $check ) {
				$names[] = (string) $check['label'];
			}

			// Said plainly, because a button that quietly does nothing about
			// half the list is worse than no button. Internal links is the
			// usual one: it means the site has nothing else on the subject to
			// point at, and no rewrite invents three related posts.
			printf(
				'<p class="bc-improve-cannot">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: comma-separated names of checks a rewrite cannot fix. */
						__( 'Rewriting will not change %s. That one is about your site rather than these words.', 'blogcraft' ),
						implode( ', ', $names )
					)
				)
			);
		}

		echo '</div>';
	}

	/**
	 * One group of checks.
	 *
	 * @param array $checks Checks to show.
	 * @param bool  $passed Whether these are the passing ones.
	 * @return void
	 */
	private static function render_check_list( $checks, $passed ) {
		echo '<ul class="bc-checks">';

		foreach ( $checks as $check ) {
			printf(
				'<li class="%1$s"><span class="bc-check-mark" aria-hidden="true">%2$s</span>'
				. '<span class="bc-check-name">%3$s</span>'
				. '<span class="bc-check-values">%4$s</span></li>',
				esc_attr( $passed ? 'is-pass' : 'is-fail' ),
				$passed ? '&#10003;' : '&#10007;',
				esc_html( isset( $check['label'] ) ? $check['label'] : '' ),
				esc_html(
					sprintf(
						/* translators: 1: what the check measured. 2: what it wanted. */
						__( '%1$s — wanted %2$s', 'blogcraft' ),
						isset( $check['actual'] ) ? (string) $check['actual'] : '',
						isset( $check['target'] ) ? (string) $check['target'] : ''
					)
				)
			);
		}

		echo '</ul>';
	}

	/**
	 * The draft itself, as it will read.
	 *
	 * @param array $article Article structure.
	 * @param array $outline Outline, for the title and meta description.
	 * @return void
	 */
	private static function render_preview( $article, $outline ) {
		echo '<section class="blogcraft-card bc-draft-card"><header>';
		echo '<h2>' . esc_html__( 'The draft', 'blogcraft' ) . '</h2>';
		echo '<p>' . esc_html__( 'Edit anything here before it becomes a post. Use Add Media to put a picture between the paragraphs. Nothing is on your site until you create it.', 'blogcraft' ) . '</p>';
		echo '</header>';

		printf(
			'<p class="bc-field"><label for="blogcraft-draft-title"><strong>%1$s</strong></label>'
			. '<input type="text" id="blogcraft-draft-title" name="draft_title" class="large-text" value="%2$s" form="blogcraft-approve" /></p>',
			esc_html__( 'Title', 'blogcraft' ),
			esc_attr( isset( $outline['title'] ) ? (string) $outline['title'] : '' )
		);

		printf(
			'<p class="bc-field"><label for="blogcraft-draft-meta"><strong>%1$s</strong></label>'
			. '<textarea id="blogcraft-draft-meta" name="draft_meta" class="large-text" rows="2" form="blogcraft-approve">%2$s</textarea>'
			. '<span class="description">%3$s</span></p>',
			esc_html__( 'Search description', 'blogcraft' ),
			esc_textarea( isset( $outline['meta_description'] ) ? (string) $outline['meta_description'] : '' ),
			esc_html__( 'What shows under the title in search results.', 'blogcraft' )
		);

		// The same renderer that builds the post, so what is edited here is
		// what gets created — not a separate preview that could drift.
		$rendered = Blogcraft_Blocks::render( $article );

		// wp_editor rather than a textarea of markup: it is the editor this
		// person already knows, and its Add Media button is the whole image
		// story for free — the media library, uploads, and anything a stock
		// photo plugin has put there.
		echo '<div class="bc-draft-editor">';
		wp_editor(
			$rendered,
			'blogcraft_draft_body',
			array(
				'textarea_name' => 'draft_body',
				'textarea_rows' => 24,
				'media_buttons' => true,
				'teeny'         => false,
				'editor_class'  => 'bc-draft-body',
				'tinymce'       => array(
					'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,link,unlink,wp_add_media,undo,redo',
					'toolbar2' => '',
				),
			)
		);
		echo '</div>';
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

		echo '<section class="blogcraft-card bc-decision-card"><header>';
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
			'<form method="post" action="%s" id="blogcraft-approve">',
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
	 * A job pausing because the provider asked it to.
	 *
	 * Not a failure, and saying so matters: nothing is lost, nothing needs
	 * doing, and every stage already paid for is still on the job. The one
	 * thing a reader needs is when it resumes and why it stopped.
	 *
	 * @param Blogcraft_Job $job     Waiting job.
	 * @param string        $resumes Local time it may next run.
	 * @return void
	 */
	private static function render_waiting( $job, $resumes ) {
		echo '<section class="blogcraft-card bc-waiting-card"><header>';
		echo '<h2>' . esc_html__( 'Paused by your provider', 'blogcraft' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: a clock time, such as "3:45 pm". */
					__( 'Your provider is rate limiting, so this is waiting rather than failing. It carries on by itself at about %s, and everything written so far is kept.', 'blogcraft' ),
					$resumes
				)
			)
		);
		echo '</header>';

		$error = trim( (string) $job->last_error );

		if ( '' !== $error ) {
			printf( '<p class="bc-waiting-detail"><code>%s</code></p>', esc_html( $error ) );
		}

		printf(
			'<p>%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'You can close this page. Come back to it from', 'blogcraft' ),
			esc_url( admin_url( 'admin.php?page=' . Blogcraft_Library::PAGE_SLUG ) ),
			esc_html__( 'Written by AI', 'blogcraft' )
		);

		echo '</section>';
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
