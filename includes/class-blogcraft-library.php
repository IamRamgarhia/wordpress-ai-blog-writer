<?php
/**
 * Everything this plugin has written, in one place.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * The record of what Blogcraft has produced, whatever became of it.
 *
 * Two kinds of thing end up here, and one of them had nowhere else to live.
 *
 * A draft that finished writing and was never approved exists only as a job
 * row: it cost real money to produce, it is complete, and no post exists for
 * it — so nothing in WordPress lists it, and closing the tab made it
 * effectively lost. Those come first, because they are the ones waiting on a
 * decision.
 *
 * Posts that were created are findable in the posts list already, but not
 * *as a set*, and not with the score they were judged by. Gathering them here
 * answers "what has this thing actually written for me", which is a question
 * the plugin could not previously answer at all.
 */
class Blogcraft_Library {

	/**
	 * Menu slug.
	 */
	const PAGE_SLUG = 'blogcraft-library';

	/**
	 * Nonce action for discarding a draft.
	 */
	const ACTION = 'blogcraft_library';

	/**
	 * Wire the screen.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_post_blogcraft_discard_draft', array( __CLASS__, 'handle_discard' ) );
	}

	/**
	 * Add the submenu entry.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			Blogcraft_Admin::MENU_SLUG,
			__( 'Written by AI', 'dicecodes-ai-blog-writer' ),
			__( 'Written by AI', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Posts this plugin created.
	 *
	 * @param int $limit Most to return.
	 * @return array WP_Post objects.
	 */
	public static function generated_posts( $limit = 100 ) {
		return get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => array( 'publish', 'draft', 'pending', 'future', 'private' ),
				'posts_per_page'   => (int) $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'no_found_rows'    => true,
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'         => Blogcraft_Seo::GENERATED_META,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'meta_value'       => '1',
			)
		);
	}

	/**
	 * Throw away a draft that was never wanted.
	 *
	 * @return void
	 */
	public static function handle_discard() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ACTION, '_blogcraft_nonce' );

		$job_id = isset( $_POST['job'] ) ? (int) $_POST['job'] : 0;

		if ( $job_id > 0 ) {
			// Cancelled rather than deleted: the row is the only record that
			// this topic was written and what it cost, and quietly removing
			// that would make the Activity log lie by omission.
			Blogcraft_Queue::cancel( $job_id );

			Blogcraft_Logger::info( 'A finished draft was discarded without being published.', array(), $job_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
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

		echo '<div class="wrap blogcraft-wrap">';
		Blogcraft_Nav::render();

		echo '<h1>' . esc_html__( 'Written by AI', 'dicecodes-ai-blog-writer' ) . '</h1>';
		echo '<p>' . esc_html__( 'Every post this plugin has written, and every draft still waiting on you.', 'dicecodes-ai-blog-writer' ) . '</p>';

		self::render_in_progress();
		self::render_waiting();
		self::render_published();

		echo '</div>';
	}

	/**
	 * Posts still being written, or paused part-way.
	 *
	 * The progress screen belongs to one job and is reached by writing a post,
	 * so closing that tab left no route back to it — a job paused by a rate
	 * limit was still working perfectly and looked, from the outside, like
	 * something that had been lost.
	 *
	 * @return void
	 */
	private static function render_in_progress() {
		$rows = array();

		foreach ( Blogcraft_Queue::recent_jobs( 25 ) as $row ) {
			if ( in_array( (string) $row['status'], array( 'pending', 'running', 'deferred' ), true ) ) {
				$rows[] = $row;
			}
		}

		if ( empty( $rows ) ) {
			return;
		}

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'Being written', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Open one to watch it, or to see why it paused.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</header>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Topic', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th>' . esc_html__( 'Step', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th>' . esc_html__( 'State', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		$steps = Blogcraft_Progress::steps();

		foreach ( $rows as $row ) {
			$payload = json_decode( isset( $row['payload'] ) ? (string) $row['payload'] : '', true );
			$payload = is_array( $payload ) ? $payload : array();
			$topic   = isset( $payload['topic'] ) ? (string) $payload['topic'] : '';
			$stage   = isset( $row['stage'] ) ? (string) $row['stage'] : '';

			$paused = self::pause_note( isset( $row['available_at'] ) ? (string) $row['available_at'] : '' );

			echo '<tr>';
			printf( '<td><strong>%s</strong></td>', esc_html( '' === $topic ? __( 'Untitled', 'dicecodes-ai-blog-writer' ) : $topic ) );
			printf( '<td>%s</td>', esc_html( isset( $steps[ $stage ] ) ? $steps[ $stage ] : $stage ) );
			printf(
				'<td>%s</td>',
				esc_html( '' === $paused ? __( 'Working', 'dicecodes-ai-blog-writer' ) : $paused )
			);
			printf(
				'<td><a class="button button-small" href="%1$s">%2$s</a></td>',
				esc_url(
					add_query_arg(
						array(
							'page' => Blogcraft_Progress::PAGE_SLUG,
							'job'  => (int) $row['id'],
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Open', 'dicecodes-ai-blog-writer' )
			);
			echo '</tr>';
		}

		echo '</tbody></table></section>';
	}

	/**
	 * Whether a job is waiting for a provider, and until when.
	 *
	 * @param string $available_at GMT datetime the job may next run.
	 * @return string Empty when it is not waiting.
	 */
	private static function pause_note( $available_at ) {
		$available_at = trim( $available_at );

		if ( '' === $available_at ) {
			return '';
		}

		$at = strtotime( $available_at . ' UTC' );

		if ( false === $at || $at <= time() + 60 ) {
			return '';
		}

		return sprintf(
			/* translators: %s: a clock time, such as "3:45 pm". */
			__( 'Paused until %s', 'dicecodes-ai-blog-writer' ),
			wp_date( get_option( 'time_format' ), $at )
		);
	}

	/**
	 * Drafts that finished writing and were never acted on.
	 *
	 * @return void
	 */
	private static function render_waiting() {
		$held = Blogcraft_Queue::held_jobs();

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'Waiting for you', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Finished, paid for, and not on your site yet. These stay here until you do something with them.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</header>';

		if ( empty( $held ) ) {
			echo '<p>' . esc_html__( 'Nothing waiting. Every draft has been dealt with.', 'dicecodes-ai-blog-writer' ) . '</p></section>';

			return;
		}

		echo '<table class="widefat striped blogcraft-table bc-library-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Topic', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Score', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Written', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'What now', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $held as $row ) {
			$payload = json_decode( isset( $row['payload'] ) ? (string) $row['payload'] : '', true );
			$payload = is_array( $payload ) ? $payload : array();

			$title = '';

			if ( ! empty( $payload['outline']['title'] ) ) {
				$title = (string) $payload['outline']['title'];
			} elseif ( ! empty( $payload['topic'] ) ) {
				$title = (string) $payload['topic'];
			}

			$score = isset( $payload['quality']['score'] ) ? (int) $payload['quality']['score'] : null;

			echo '<tr>';
			printf( '<td><strong>%s</strong></td>', esc_html( '' === $title ? __( 'Untitled', 'dicecodes-ai-blog-writer' ) : $title ) );
			// Not escaped again: score_pill() escapes everything it emits.
			echo '<td>' . wp_kses( self::score_pill( $score ), Blogcraft_Markup::allowed() ) . '</td>';
			printf( '<td>%s</td>', esc_html( self::when( isset( $row['updated_at'] ) ? $row['updated_at'] : '' ) ) );

			// A flex row rather than two siblings and a space. The discard
			// control is a form, which is block-level, so the two buttons
			// stacked one above the other in a narrow column.
			echo '<td><div class="bc-library-actions">';
			printf(
				'<a class="button button-primary button-small" href="%1$s">%2$s</a>',
				esc_url(
					add_query_arg(
						array(
							'page' => Blogcraft_Progress::PAGE_SLUG,
							'job'  => (int) $row['id'],
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Read it', 'dicecodes-ai-blog-writer' )
			);
			self::discard_button( (int) $row['id'] );
			echo '</div></td></tr>';
		}

		echo '</tbody></table></section>';
	}

	/**
	 * The control that throws a draft away.
	 *
	 * @param int $job_id Job id.
	 * @return void
	 */
	private static function discard_button( $job_id ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="blogcraft-inline-form">';
		echo '<input type="hidden" name="action" value="blogcraft_discard_draft" />';
		printf( '<input type="hidden" name="job" value="%d" />', (int) $job_id );
		Blogcraft_Request::nonce_field( self::ACTION );
		printf(
			'<button type="submit" class="button button-small button-link-delete" aria-label="%1$s">%2$s</button>',
			esc_attr(
				sprintf(
					/* translators: %d: job number. */
					__( 'Discard draft %d', 'dicecodes-ai-blog-writer' ),
					(int) $job_id
				)
			),
			esc_html__( 'Discard', 'dicecodes-ai-blog-writer' )
		);
		echo '</form>';
	}

	/**
	 * Posts that were created.
	 *
	 * @return void
	 */
	private static function render_published() {
		$posts = self::generated_posts();

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'On your site', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Posts this plugin wrote, with the score each one was judged by when it was written.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</header>';

		if ( empty( $posts ) ) {
			echo '<p>' . esc_html__( 'Nothing yet. The first post you create will appear here.', 'dicecodes-ai-blog-writer' ) . '</p></section>';

			return;
		}

		echo '<table class="widefat striped blogcraft-table bc-library-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Post', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Score', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Written', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $posts as $post ) {
			$score = get_post_meta( $post->ID, '_blogcraft_quality', true );

			echo '<tr>';
			printf(
				'<td><a href="%1$s"><strong>%2$s</strong></a></td>',
				esc_url( (string) get_edit_post_link( $post->ID ) ),
				esc_html( '' === $post->post_title ? __( 'Untitled', 'dicecodes-ai-blog-writer' ) : $post->post_title )
			);
			printf(
				'<td><span class="blogcraft-badge is-%1$s">%2$s</span></td>',
				esc_attr( $post->post_status ),
				esc_html( self::status_label( $post->post_status ) )
			);

			// Not escaped again: score_pill() escapes everything it emits.
			echo '<td>' . wp_kses( self::score_pill( '' === $score ? null : (int) $score ), Blogcraft_Markup::allowed() ) . '</td>';
			// post_date_gmt is only filled once a post is scheduled or
			// published. Every draft this plugin writes has it empty, and
			// the local date is the one that is always there.
			$written = ( '' === $post->post_date_gmt || 0 === strpos( $post->post_date_gmt, '0000-00-00' ) )
				? get_gmt_from_date( $post->post_date )
				: $post->post_date_gmt;

			printf( '<td>%s</td>', esc_html( self::when( $written ) ) );
			echo '</tr>';
		}

		echo '</tbody></table></section>';
	}

	/**
	 * A post status in words a reader recognises.
	 *
	 * @param string $status Post status.
	 * @return string
	 */
	private static function status_label( $status ) {
		switch ( (string) $status ) {
			case 'publish':
				return __( 'Published', 'dicecodes-ai-blog-writer' );
			case 'future':
				return __( 'Scheduled', 'dicecodes-ai-blog-writer' );
			case 'pending':
				return __( 'Held for review', 'dicecodes-ai-blog-writer' );
			case 'private':
				return __( 'Private', 'dicecodes-ai-blog-writer' );
			default:
				return __( 'Draft', 'dicecodes-ai-blog-writer' );
		}
	}

	/**
	 * A score, coloured against the bar it was judged by.
	 *
	 * A bare "65/100" in a table column tells you a number and not whether
	 * it is good. The threshold is the only thing that decides that, and
	 * it is a setting, so the colour has to come from the same place the
	 * review screen's dial comes from rather than a figure written in
	 * here.
	 *
	 * @param int|null $score Score out of 100, or null when none was recorded.
	 * @return string
	 */
	private static function score_pill( $score ) {
		if ( null === $score ) {
			return '<span class="bc-score-pill is-none">' . esc_html__( 'not scored', 'dicecodes-ai-blog-writer' ) . '</span>';
		}

		$bar = (int) Blogcraft_Settings::get( 'quality_threshold' );

		return sprintf(
			'<span class="bc-score-pill %1$s">%2$d</span>',
			esc_attr( (int) $score >= $bar ? 'is-ok' : 'is-under' ),
			(int) $score
		);
	}

	/**
	 * A timestamp as "how long ago", in the site's own timezone.
	 *
	 * @param string $gmt A GMT datetime string.
	 * @return string
	 */
	private static function when( $gmt ) {
		$gmt = trim( (string) $gmt );

		if ( '' === $gmt ) {
			return '—';
		}

		// A draft that was never scheduled carries 0000-00-00 as its GMT
		// date. strtotime reads that as the year zero, which is roughly
		// sixty-four billion seconds ago — so every draft on this screen
		// was labelled "2028 years ago".
		if ( 0 === strpos( $gmt, '0000-00-00' ) ) {
			return '—';
		}

		$stamp = strtotime( $gmt . ' UTC' );

		if ( false === $stamp || $stamp <= 0 ) {
			return '—';
		}

		return sprintf(
			/* translators: %s: a length of time, such as "2 hours". */
			__( '%s ago', 'dicecodes-ai-blog-writer' ),
			human_time_diff( $stamp, time() )
		);
	}
}
