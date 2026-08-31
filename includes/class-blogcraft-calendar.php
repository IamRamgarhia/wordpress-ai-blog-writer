<?php
/**
 * Calendar screen: what is queued, and when it will be written.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shows the topic queue against the schedule it will actually run on.
 *
 * The autopilot topic list is a textarea, which answers "what" but never
 * "when". Someone who pastes forty topics has no way to tell whether that is
 * two months of posting or eight, and no way to move an urgent one forward
 * without cutting and pasting lines. This screen projects the same rules the
 * autopilot applies and lets the order be changed one row at a time.
 *
 * Nothing here is stored: the dates are recomputed from the settings on every
 * load, so the calendar cannot promise a date the autopilot will not honour.
 */
class Blogcraft_Calendar {

	/**
	 * Submenu slug.
	 */
	const PAGE_SLUG = 'blogcraft-calendar';

	/**
	 * Nonce action for reordering.
	 */
	const MOVE_ACTION = 'blogcraft_move_topic';

	/**
	 * Transient prefix for one-shot notices.
	 */
	const NOTICE_TRANSIENT = 'blogcraft_calendar_notice_';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 17 );
		add_action( 'admin_post_blogcraft_move_topic', array( __CLASS__, 'handle_move' ) );
	}

	/**
	 * Add the submenu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			Blogcraft_Admin::MENU_SLUG,
			__( 'Calendar', 'dicecodes-ai-blog-writer' ),
			__( 'Calendar', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Weekday names in the site's own week order, keyed by 0 for Sunday.
	 *
	 * @return array
	 */
	public static function weekday_names() {
		global $wp_locale;

		$names = array();

		for ( $day = 0; $day <= 6; $day++ ) {
			$names[ $day ] = ( $wp_locale instanceof WP_Locale )
				? $wp_locale->get_weekday( $day )
				: wp_date( 'l', strtotime( 'Sunday +' . $day . ' days' ) );
		}

		return $names;
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
		echo '<h1>' . esc_html__( 'Calendar', 'dicecodes-ai-blog-writer' ) . '</h1>';
		echo '<p>' . esc_html__( 'Your topic queue, against the schedule it will run on.', 'dicecodes-ai-blog-writer' ) . '</p>';
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

		self::render_schedule();
		self::render_plan();

		echo '</div>';
	}

	/**
	 * Summarise the schedule in the same words the user set it in.
	 *
	 * @return void
	 */
	private static function render_schedule() {
		$days  = Blogcraft_Autopilot::days();
		$names = self::weekday_names();

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'The schedule', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Set under Settings, in your site timezone. Shown here so the dates below make sense.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</header>';

		if ( ! Blogcraft_Settings::get( 'autopilot_enabled' ) ) {
			echo '<p class="blogcraft-callout">' . esc_html__( 'Automatic writing is off, so nothing below is scheduled yet. These are the dates it would use once you turn it on.', 'dicecodes-ai-blog-writer' ) . '</p>';
		}

		$chosen = array();

		foreach ( $days as $day ) {
			if ( isset( $names[ $day ] ) ) {
				$chosen[] = $names[ $day ];
			}
		}

		echo '<ul class="blogcraft-stats">';

		printf(
			'<li><span class="blogcraft-stat-value">%1$s</span><span class="blogcraft-stat-label">%2$s</span></li>',
			esc_html( empty( $chosen ) ? __( 'None', 'dicecodes-ai-blog-writer' ) : (string) count( $chosen ) ),
			esc_html__( 'Days a week', 'dicecodes-ai-blog-writer' )
		);

		printf(
			'<li><span class="blogcraft-stat-value">%1$s</span><span class="blogcraft-stat-label">%2$s</span></li>',
			esc_html( wp_date( get_option( 'time_format', 'H:i' ), self::hour_stamp() ) ),
			esc_html__( 'From', 'dicecodes-ai-blog-writer' )
		);

		printf(
			'<li><span class="blogcraft-stat-value">%1$d</span><span class="blogcraft-stat-label">%2$s</span></li>',
			(int) Blogcraft_Settings::get( 'autopilot_per_day' ),
			esc_html__( 'Posts a day', 'dicecodes-ai-blog-writer' )
		);

		echo '</ul>';

		if ( ! empty( $chosen ) ) {
			printf(
				'<p class="blogcraft-hint">%s</p>',
				esc_html( implode( ', ', $chosen ) )
			);
		}

		echo '</section>';
	}

	/**
	 * A timestamp at the configured hour today, for formatting only.
	 *
	 * @return int
	 */
	private static function hour_stamp() {
		$midnight = strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 ' . wp_timezone_string() );

		if ( false === $midnight ) {
			return time();
		}

		return $midnight + ( Blogcraft_Autopilot::hour() * HOUR_IN_SECONDS );
	}

	/**
	 * The projected queue.
	 *
	 * @return void
	 */
	private static function render_plan() {
		$plan  = Blogcraft_Autopilot::plan();
		$total = count( Blogcraft_Autopilot::topics() );

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'What is coming', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each topic is used once, then removed from the list. Move one up to write it sooner.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</header>';

		if ( 0 === $total ) {
			echo '<p>' . esc_html__( 'No topics queued. Add some under Settings, in the topic queue.', 'dicecodes-ai-blog-writer' ) . '</p>';
			echo '</section>';

			return;
		}

		if ( empty( $plan ) ) {
			echo '<p>' . esc_html__( 'There are topics waiting, but no weekdays are selected, so none of them have a date. Choose at least one day under Settings.', 'dicecodes-ai-blog-writer' ) . '</p>';
			echo '</section>';

			return;
		}

		$format = get_option( 'date_format', 'M j, Y' ) . ' ' . get_option( 'time_format', 'H:i' );

		echo '<table class="widefat striped blogcraft-table"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Planned', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Topic', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Order', 'dicecodes-ai-blog-writer' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $plan as $index => $entry ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( wp_date( $format, (int) $entry['when'] ) ) );
			printf( '<td>%s</td>', esc_html( (string) $entry['topic'] ) );

			echo '<td class="blogcraft-order">';

			$topic = (string) $entry['topic'];
			$last  = count( $plan ) - 1;

			if ( $index > 0 ) {
				self::move_button( $index, 'up', __( 'Move up', 'dicecodes-ai-blog-writer' ), $topic );
			}

			if ( $index < $last ) {
				self::move_button( $index, 'down', __( 'Move down', 'dicecodes-ai-blog-writer' ), $topic );
			}

			self::move_button( $index, 'remove', __( 'Remove', 'dicecodes-ai-blog-writer' ), $topic );

			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( count( $plan ) < $total ) {
			printf(
				'<p class="blogcraft-hint">%s</p>',
				esc_html(
					sprintf(
						/* translators: %d: number of topics with no projected date. */
						_n(
							'%d further topic is queued beyond the year shown here.',
							'%d further topics are queued beyond the year shown here.',
							$total - count( $plan ),
							'dicecodes-ai-blog-writer'
						),
						$total - count( $plan )
					)
				)
			);
		}

		echo '</section>';
	}

	/**
	 * One nonce-protected reorder control.
	 *
	 * @param int    $index Position in the topic list.
	 * @param string $verb  up, down or remove.
	 * @param string $label Button label.
	 * @param string $topic Topic the button acts on, for the accessible name.
	 * @return void
	 */
	private static function move_button( $index, $verb, $label, $topic ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="blogcraft-inline-form">';
		echo '<input type="hidden" name="action" value="blogcraft_move_topic" />';
		printf( '<input type="hidden" name="index" value="%d" />', (int) $index );
		printf( '<input type="hidden" name="verb" value="%s" />', esc_attr( $verb ) );
		Blogcraft_Request::nonce_field( self::MOVE_ACTION );
		printf(
			'<button type="submit" class="button button-small" aria-label="%1$s">%2$s</button>',
			esc_attr(
				sprintf(
					/* translators: 1: button action such as Move up. 2: the topic it applies to. */
					__( '%1$s: %2$s', 'dicecodes-ai-blog-writer' ),
					$label,
					$topic
				)
			),
			esc_html( $label )
		);
		echo '</form>';
	}

	/**
	 * Reorder or drop one topic.
	 *
	 * @return void
	 */
	public static function handle_move() {
		// Read then verify; Blogcraft_Request performs the check PHPCS cannot follow.
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::MOVE_ACTION, $nonce );

		$index = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$verb  = isset( $_POST['verb'] ) ? sanitize_key( wp_unslash( $_POST['verb'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$topics = Blogcraft_Autopilot::topics();

		if ( ! isset( $topics[ $index ] ) ) {
			self::back( false, __( 'That topic is no longer in the queue.', 'dicecodes-ai-blog-writer' ) );
		}

		if ( 'remove' === $verb ) {
			array_splice( $topics, $index, 1 );
			self::store( $topics );
			self::back( true, __( 'Removed.', 'dicecodes-ai-blog-writer' ) );
		}

		$target = ( 'up' === $verb ) ? $index - 1 : $index + 1;

		if ( 'up' !== $verb && 'down' !== $verb ) {
			self::back( false, __( 'Unknown action.', 'dicecodes-ai-blog-writer' ) );
		}

		if ( ! isset( $topics[ $target ] ) ) {
			self::back( false, __( 'That topic is already at the end of the queue.', 'dicecodes-ai-blog-writer' ) );
		}

		$swap              = $topics[ $target ];
		$topics[ $target ] = $topics[ $index ];
		$topics[ $index ]  = $swap;

		self::store( $topics );
		self::back( true, __( 'Moved.', 'dicecodes-ai-blog-writer' ) );
	}

	/**
	 * Write the topic list back.
	 *
	 * @param array $topics Topics in their new order.
	 * @return void
	 */
	private static function store( $topics ) {
		Blogcraft_Settings::set( 'autopilot_topics', implode( "\n", $topics ) );
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
