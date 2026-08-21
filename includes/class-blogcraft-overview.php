<?php
/**
 * The overview screen.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * The first screen anyone sees, and the one that has to answer three questions.
 *
 * What do I still need to set up, what has this thing actually done, and what
 * needs me right now. The previous version answered none of them: it showed
 * four queue counters and a token total, which tells a new user nothing and a
 * returning user less.
 *
 * Everything here links somewhere. A dashboard that reports a problem without
 * offering the screen that fixes it is just a slower way of worrying.
 */
class Blogcraft_Overview {

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'blogcraft' ) );
		}

		echo '<div class="wrap blogcraft-page">';

		Blogcraft_Nav::render();

		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Blogcraft', 'blogcraft' ) . '</h1>';
		echo '<p>' . esc_html__( 'What is set up, what it has written, and what needs you.', 'blogcraft' ) . '</p>';
		echo '</div>';

		self::render_setup();
		self::render_attention();
		self::render_numbers();
		self::render_recent();

		echo '</div>';
	}

	/**
	 * The steps still standing between here and a written post.
	 *
	 * Shown only while something is genuinely outstanding. A permanent
	 * checklist of ticks is decoration.
	 *
	 * @return void
	 */
	private static function render_setup() {
		$steps = self::setup_steps();
		$done  = 0;

		foreach ( $steps as $step ) {
			if ( $step['done'] ) {
				++$done;
			}
		}

		if ( count( $steps ) === $done ) {
			return;
		}

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'Finish setting up', 'blogcraft' ) . '</h2>';
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: steps completed. 2: total steps. */
					__( '%1$d of %2$d done. Nothing gets written until the first one is.', 'blogcraft' ),
					$done,
					count( $steps )
				)
			)
		);
		echo '</header>';

		echo '<ol class="blogcraft-steps">';

		foreach ( $steps as $step ) {
			printf(
				'<li class="%1$s"><span class="blogcraft-step-mark" aria-hidden="true"></span><span class="blogcraft-step-text"><strong>%2$s</strong><span>%3$s</span></span>%4$s<span class="screen-reader-text">%5$s</span></li>',
				$step['done'] ? 'is-done' : 'is-todo',
				esc_html( $step['title'] ),
				esc_html( $step['detail'] ),
				$step['done'] ? '' : sprintf(
					'<a class="button button-small" href="%1$s">%2$s</a>',
					esc_url( $step['url'] ),
					esc_html( $step['action'] )
				),
				esc_html( $step['done'] ? __( 'Done', 'blogcraft' ) : __( 'Still to do', 'blogcraft' ) )
			);
		}

		echo '</ol>';
		echo '</section>';
	}

	/**
	 * The setup steps, in the order they actually have to happen.
	 *
	 * @return array
	 */
	private static function setup_steps() {
		$blueprint = Blogcraft_Blueprint::get();

		$described = ( '' !== trim( (string) $blueprint['audience_custom'] ) )
			|| ( '' !== trim( (string) Blogcraft_Settings::get( 'voice_niche' ) ) );

		$written = self::written_count() > 0;

		return array(
			array(
				'title'  => __( 'Connect a provider', 'blogcraft' ),
				'detail' => __( 'Your key, your account, your bill.', 'blogcraft' ),
				'done'   => Blogcraft_Provider_Registry::is_configured(),
				'url'    => admin_url( 'admin.php?page=blogcraft-settings' ),
				'action' => __( 'Set it up', 'blogcraft' ),
			),
			array(
				'title'  => __( 'Say who you write for', 'blogcraft' ),
				'detail' => __( 'Without this, posts read like every other tool\'s.', 'blogcraft' ),
				'done'   => $described,
				'url'    => admin_url( 'admin.php?page=blogcraft-blueprint' ),
				'action' => __( 'Describe it', 'blogcraft' ),
			),
			array(
				'title'  => __( 'Write one post', 'blogcraft' ),
				'detail' => __( 'Read it before you turn anything on a schedule.', 'blogcraft' ),
				'done'   => $written,
				'url'    => admin_url( 'admin.php?page=blogcraft-write' ),
				'action' => __( 'Write one', 'blogcraft' ),
			),
		);
	}

	/**
	 * Anything actively wrong or waiting on a person.
	 *
	 * @return void
	 */
	private static function render_attention() {
		$items   = array();
		$waiting = count( Blogcraft_Review::pending_posts() );
		$failed  = (int) Blogcraft_Queue::count_by_status( 'failed' );

		if ( $waiting > 0 ) {
			$items[] = array(
				'text' => sprintf(
					/* translators: %d: number of posts held for review. */
					_n( '%d post is waiting for you to read it.', '%d posts are waiting for you to read them.', $waiting, 'blogcraft' ),
					$waiting
				),
				'url'  => admin_url( 'admin.php?page=blogcraft-review' ),
				'link' => __( 'Review them', 'blogcraft' ),
				'kind' => 'wait',
			);
		}

		if ( $failed > 0 ) {
			$items[] = array(
				'text' => sprintf(
					/* translators: %d: number of failed jobs. */
					_n( '%d post could not be written.', '%d posts could not be written.', $failed, 'blogcraft' ),
					$failed
				),
				'url'  => admin_url( 'admin.php?page=blogcraft-activity' ),
				'link' => __( 'See why', 'blogcraft' ),
				'kind' => 'bad',
			);
		}

		if ( Blogcraft_Cost::over_cap() ) {
			$items[] = array(
				'text' => __( 'The monthly token cap has been reached, so nothing new is being written.', 'blogcraft' ),
				'url'  => admin_url( 'admin.php?page=blogcraft-settings#bc-card-provider' ),
				'link' => __( 'Raise it', 'blogcraft' ),
				'kind' => 'bad',
			);
		}

		if ( Blogcraft_Settings::get( 'autopilot_enabled' ) && array() === Blogcraft_Autopilot::days() ) {
			$items[] = array(
				'text' => __( 'Automatic writing is on, but no days are chosen, so nothing will ever run.', 'blogcraft' ),
				'url'  => admin_url( 'admin.php?page=blogcraft-settings#bc-card-automation' ),
				'link' => __( 'Choose days', 'blogcraft' ),
				'kind' => 'bad',
			);
		}

		// Half of the content search engines cite is under three months old, so
		// a shelf of ageing posts is a standing loss rather than a tidy-up job.
		// Refreshing one keeps the URL and everything it has earned; writing a
		// new post starts from nothing.
		$stale = count( Blogcraft_Refresh::find_stale( null, 20 ) );

		if ( $stale > 0 && ! Blogcraft_Settings::get( 'refresh_enabled' ) ) {
			$items[] = array(
				'text' => sprintf(
					/* translators: %d: number of posts that have not been updated recently. */
					_n(
						'%d post has not been updated in a long time. Refreshing it is usually worth more than writing a new one.',
						'%d posts have not been updated in a long time. Refreshing them is usually worth more than writing new ones.',
						$stale,
						'blogcraft'
					),
					$stale
				),
				'url'  => admin_url( 'admin.php?page=blogcraft-settings#bc-card-automation' ),
				'link' => __( 'Turn refreshing on', 'blogcraft' ),
				'kind' => 'wait',
			);
		}

		// A half-configured picture service is silent: the chain falls through to
		// a free one and the post still gets an image, so nothing looks wrong and
		// the model that was chosen is never used.
		$image_provider = (string) Blogcraft_Settings::get( 'image_provider' );

		if ( array_key_exists( $image_provider, Blogcraft_Image_Models::providers() ) && ! Blogcraft_Image_Models::is_configured() ) {
			$items[] = array(
				'text' => __( 'The picture service you chose is missing a key or a model name, so free images are being used instead.', 'blogcraft' ),
				'url'  => admin_url( 'admin.php?page=blogcraft-settings#bc-card-automation' ),
				'link' => __( 'Finish it', 'blogcraft' ),
				'kind' => 'wait',
			);
		}

		if ( empty( $items ) ) {
			return;
		}

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'Needs you', 'blogcraft' ) . '</h2>';
		echo '</header>';
		echo '<ul class="blogcraft-attention">';

		foreach ( $items as $item ) {
			printf(
				'<li class="is-%1$s">%2$s <a href="%3$s">%4$s</a></li>',
				esc_attr( $item['kind'] ),
				esc_html( $item['text'] ),
				esc_url( $item['url'] ),
				esc_html( $item['link'] )
			);
		}

		echo '</ul>';
		echo '</section>';
	}

	/**
	 * The figures worth glancing at, and what happens next.
	 *
	 * @return void
	 */
	private static function render_numbers() {
		$totals = Blogcraft_Cost::month_totals();

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'This month', 'blogcraft' ) . '</h2>';
		echo '<p>' . esc_html__( 'Tokens are billed by your provider, not by us.', 'blogcraft' ) . '</p>';
		echo '</header>';

		echo '<ul class="blogcraft-stats">';

		self::tile( (string) number_format_i18n( self::written_count() ), __( 'Posts written', 'blogcraft' ) );
		self::tile( (string) number_format_i18n( (int) Blogcraft_Queue::count_by_status( 'pending' ) ), __( 'Waiting', 'blogcraft' ) );
		self::tile( (string) number_format_i18n( (int) $totals['requests'] ), __( 'Requests', 'blogcraft' ) );
		self::tile(
			self::compact( (int) $totals['prompt'] + (int) $totals['completion'] ),
			__( 'Tokens', 'blogcraft' )
		);

		// Only shown once something has actually been billed for. A permanent
		// zero would be a tile about a feature nobody here is using.
		if ( (int) $totals['images'] > 0 ) {
			self::tile( (string) number_format_i18n( (int) $totals['images'] ), __( 'Paid images', 'blogcraft' ) );
		}

		echo '</ul>';

		$next = self::next_run();

		if ( '' !== $next ) {
			printf( '<p class="blogcraft-hint">%s</p>', esc_html( $next ) );
		}

		echo '<div class="blogcraft-actions">';
		printf(
			'<a class="button button-primary" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=blogcraft-write' ) ),
			esc_html__( 'Write a post', 'blogcraft' )
		);
		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=blogcraft-blueprint' ) ),
			esc_html__( 'How it writes', 'blogcraft' )
		);
		echo '</div>';
		echo '</section>';
	}

	/**
	 * When the next scheduled post is due, in plain words.
	 *
	 * @return string Empty when nothing is scheduled.
	 */
	private static function next_run() {
		if ( ! Blogcraft_Settings::get( 'autopilot_enabled' ) ) {
			return __( 'Automatic writing is off. Posts are written only when you ask for one.', 'blogcraft' );
		}

		$plan = Blogcraft_Autopilot::plan();

		if ( empty( $plan ) ) {
			return __( 'Automatic writing is on, but there are no topics queued for it.', 'blogcraft' );
		}

		$format = get_option( 'date_format', 'M j' ) . ' ' . get_option( 'time_format', 'H:i' );

		return sprintf(
			/* translators: 1: topic. 2: date and time. */
			__( 'Next up: "%1$s", due %2$s.', 'blogcraft' ),
			(string) $plan[0]['topic'],
			wp_date( $format, (int) $plan[0]['when'] )
		);
	}

	/**
	 * The last few posts this plugin wrote, with how they scored.
	 *
	 * @return void
	 */
	private static function render_recent() {
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 5,
				'no_found_rows'  => true,
				'meta_key'       => '_blogcraft_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'Recently written', 'blogcraft' ) . '</h2>';
		echo '</header>';

		if ( empty( $posts ) ) {
			echo '<p>' . esc_html__( 'Nothing yet. The first post you queue will appear here.', 'blogcraft' ) . '</p>';
			echo '</section>';

			return;
		}

		echo '<table class="widefat striped blogcraft-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Post', 'blogcraft' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'blogcraft' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Score', 'blogcraft' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Written', 'blogcraft' ) . '</th>';
		echo '</tr></thead><tbody>';

		$format = get_option( 'date_format', 'M j, Y' );

		foreach ( $posts as $post ) {
			$score = (int) get_post_meta( $post->ID, '_blogcraft_quality', true );

			echo '<tr>';
			printf(
				'<td><a href="%1$s">%2$s</a></td>',
				esc_url( (string) get_edit_post_link( $post->ID ) ),
				esc_html( get_the_title( $post ) )
			);
			printf(
				'<td><span class="blogcraft-badge is-%1$s">%2$s</span></td>',
				esc_attr( $post->post_status ),
				esc_html( self::status_word( $post->post_status ) )
			);
			printf(
				'<td>%s</td>',
				$score > 0 ? esc_html( sprintf( '%d / 100', $score ) ) : '&mdash;'
			);
			printf( '<td>%s</td>', esc_html( wp_date( $format, get_post_timestamp( $post ) ) ) );
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</section>';
	}

	/**
	 * A post status in the words a person would use.
	 *
	 * @param string $status WordPress status.
	 * @return string
	 */
	private static function status_word( $status ) {
		if ( 'publish' === $status ) {
			return __( 'Live', 'blogcraft' );
		}

		if ( 'pending' === $status ) {
			return __( 'Held for review', 'blogcraft' );
		}

		return __( 'Draft', 'blogcraft' );
	}

	/**
	 * How many posts this plugin has written.
	 *
	 * @return int
	 */
	private static function written_count() {
		$found = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_blogcraft_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			)
		);

		return count( $found );
	}

	/**
	 * Render one stat tile.
	 *
	 * @param string $value Figure.
	 * @param string $label What it counts.
	 * @return void
	 */
	private static function tile( $value, $label ) {
		printf(
			'<li><span class="blogcraft-stat-value">%1$s</span><span class="blogcraft-stat-label">%2$s</span></li>',
			esc_html( $value ),
			esc_html( $label )
		);
	}

	/**
	 * Shorten a large number for display.
	 *
	 * @param int $value Number.
	 * @return string
	 */
	private static function compact( $value ) {
		$value = (int) $value;

		if ( $value < 1000 ) {
			return (string) $value;
		}

		return number_format_i18n( $value / 1000, 1 ) . 'k';
	}
}
