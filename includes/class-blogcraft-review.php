<?php
/**
 * Review queue for drafts held back by the quality check.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lists generated posts awaiting review and lets one be approved or rejected.
 *
 * A post lands here when it scored below the quality threshold. Showing the
 * score alone would be a number without a reason, so each entry carries the
 * specific findings that cost it points and the user decides.
 */
class Blogcraft_Review {

	/**
	 * Submenu slug.
	 */
	const PAGE_SLUG = 'blogcraft-review';

	/**
	 * Nonce action for approving or rejecting.
	 */
	const ACTION = 'blogcraft_review_action';

	/**
	 * Transient prefix holding the last notice for one user.
	 */
	const NOTICE_TRANSIENT = 'blogcraft_review_notice_';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 18 );
		add_action( 'admin_post_blogcraft_review_action', array( __CLASS__, 'handle_action' ) );
	}

	/**
	 * Add the submenu, with a count bubble when anything is waiting.
	 *
	 * @return void
	 */
	public static function register_menu() {
		$waiting = count( self::pending_posts() );
		$label   = __( 'Needs review', 'blogcraft' );

		if ( $waiting > 0 ) {
			$label .= sprintf( ' <span class="update-plugins count-%1$d"><span class="update-count">%1$d</span></span>', $waiting );
		}

		add_submenu_page(
			Blogcraft_Admin::MENU_SLUG,
			__( 'Needs review', 'blogcraft' ),
			$label,
			Blogcraft_Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Generated posts currently held for review.
	 *
	 * @return array WP_Post objects.
	 */
	public static function pending_posts() {
		return get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'pending',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
				'meta_key'       => '_blogcraft_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
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
		$posts  = self::pending_posts();

		echo '<div class="wrap blogcraft-page">';
		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Needs review', 'blogcraft' ) . '</h1>';
		echo '<p>' . esc_html__( 'Posts held back because they scored below your quality threshold.', 'blogcraft' ) . '</p>';
		echo '</div>';

		if ( is_array( $notice ) ) {
			delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
			printf(
				'<div class="notice %s"><p>%s</p></div>',
				esc_attr( empty( $notice['ok'] ) ? 'notice-error' : 'notice-success' ),
				esc_html( (string) $notice['message'] )
			);
		}

		if ( empty( $posts ) ) {
			echo '<section class="blogcraft-card"><header>';
			echo '<h2>' . esc_html__( 'Nothing waiting', 'blogcraft' ) . '</h2>';
			echo '<p>' . esc_html__( 'Everything generated so far cleared the quality bar.', 'blogcraft' ) . '</p>';
			echo '</header></section>';
			echo '</div>';

			return;
		}

		foreach ( $posts as $post ) {
			self::render_entry( $post );
		}

		echo '</div>';
	}

	/**
	 * Render one waiting post.
	 *
	 * @param WP_Post $post Post awaiting review.
	 * @return void
	 */
	private static function render_entry( $post ) {
		$score   = (int) get_post_meta( $post->ID, '_blogcraft_quality', true );
		$reasons = get_post_meta( $post->ID, '_blogcraft_quality_reasons', true );
		$checks  = get_post_meta( $post->ID, '_blogcraft_checks', true );

		echo '<section class="blogcraft-card"><header>';
		printf(
			'<span class="blogcraft-step">%s</span>',
			esc_html(
				sprintf(
					/* translators: %d: quality score out of 100. */
					__( 'Scored %d of 100', 'blogcraft' ),
					$score
				)
			)
		);
		printf( '<h2>%s</h2>', esc_html( get_the_title( $post ) ) );
		echo '</header>';

		// The scorecard says what was measured and what was asked for, which is
		// what someone needs to decide whether to publish anyway. Fall back to
		// the plain reason list for posts written before checks were recorded.
		if ( is_array( $checks ) && ! empty( $checks ) ) {
			self::render_checks( $checks );
		} elseif ( is_array( $reasons ) && ! empty( $reasons ) ) {
			echo '<ul class="blogcraft-reasons">';

			foreach ( $reasons as $reason ) {
				printf( '<li>%s</li>', esc_html( (string) $reason ) );
			}

			echo '</ul>';
		}

		echo '<div class="blogcraft-actions">';

		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( (string) get_edit_post_link( $post->ID ) ),
			esc_html__( 'Read it', 'blogcraft' )
		);

		self::action_button( $post->ID, 'approve', __( 'Approve and publish', 'blogcraft' ), 'button-primary' );
		self::action_button( $post->ID, 'reject', __( 'Move to trash', 'blogcraft' ), 'button-link-delete' );

		echo '</div>';
		echo '</section>';
	}

	/**
	 * Render the measured checks behind a score.
	 *
	 * Failures first: the reason a post is being held is the thing worth
	 * reading, and a list that opens with eight passes buries it.
	 *
	 * @param array $checks Stored checks.
	 * @return void
	 */
	private static function render_checks( $checks ) {
		$failed = array();
		$passed = array();

		foreach ( $checks as $check ) {
			if ( ! is_array( $check ) || ! isset( $check['label'] ) ) {
				continue;
			}

			if ( empty( $check['pass'] ) ) {
				$failed[] = $check;
			} else {
				$passed[] = $check;
			}
		}

		echo '<ul class="blogcraft-checks">';

		foreach ( array_merge( $failed, $passed ) as $check ) {
			printf(
				'<li class="%1$s"><span class="blogcraft-check-mark" aria-hidden="true"></span><span class="blogcraft-check-label">%2$s</span><span class="blogcraft-check-figures"><span class="blogcraft-check-actual">%3$s</span> <span class="blogcraft-check-target">%4$s</span></span><span class="screen-reader-text">%5$s</span></li>',
				empty( $check['pass'] ) ? 'is-failed' : 'is-passed',
				esc_html( (string) $check['label'] ),
				esc_html( (string) $check['actual'] ),
				esc_html(
					sprintf(
						/* translators: %s: the value the blueprint asked for. */
						__( 'wanted %s', 'blogcraft' ),
						(string) $check['target']
					)
				),
				esc_html( empty( $check['pass'] ) ? __( 'Failed', 'blogcraft' ) : __( 'Passed', 'blogcraft' ) )
			);
		}

		echo '</ul>';

		if ( ! empty( $failed ) ) {
			printf(
				'<p class="blogcraft-hint">%s</p>',
				esc_html__( 'These were measured on the finished draft. The model was told about each one and rewrote once before this.', 'blogcraft' )
			);
		}
	}

	/**
	 * Render one nonce-protected action button.
	 *
	 * @param int    $post_id Post to act on.
	 * @param string $verb    approve or reject.
	 * @param string $label   Button label.
	 * @param string $variant Extra button class.
	 * @return void
	 */
	private static function action_button( $post_id, $verb, $label, $variant ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_review_action" />';
		printf( '<input type="hidden" name="post_id" value="%d" />', (int) $post_id );
		printf( '<input type="hidden" name="verb" value="%s" />', esc_attr( $verb ) );
		Blogcraft_Request::nonce_field( self::ACTION );
		printf(
			'<button type="submit" class="button %s">%s</button>',
			esc_attr( $variant ),
			esc_html( $label )
		);
		echo '</form>';
	}

	/**
	 * Approve or reject a held post.
	 *
	 * @return void
	 */
	public static function handle_action() {
		// Read then verify; Blogcraft_Request performs the check PHPCS cannot follow.
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::ACTION, $nonce );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$verb    = isset( $_POST['verb'] ) ? sanitize_key( wp_unslash( $_POST['verb'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post    = $post_id ? get_post( $post_id ) : null;

		// Only ever act on a post this plugin generated and is holding.
		if ( ! $post || 'pending' !== $post->post_status || ! get_post_meta( $post_id, '_blogcraft_generated', true ) ) {
			self::back( false, __( 'That post is no longer waiting for review.', 'blogcraft' ) );
		}

		if ( 'approve' === $verb ) {
			wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'publish',
				)
			);

			self::back( true, __( 'Published.', 'blogcraft' ) );
		}

		if ( 'reject' === $verb ) {
			wp_trash_post( $post_id );

			self::back( true, __( 'Moved to trash.', 'blogcraft' ) );
		}

		self::back( false, __( 'Unknown action.', 'blogcraft' ) );
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
