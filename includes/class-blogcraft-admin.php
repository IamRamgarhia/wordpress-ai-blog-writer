<?php
/**
 * Admin interface.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers Blogcraft's admin menu.
 */
class Blogcraft_Admin {

	/**
	 * Top-level menu slug.
	 */
	const MENU_SLUG = 'blogcraft';

	/**
	 * Wire admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		Blogcraft_Notices::init();
	}

	/**
	 * Register the top-level menu page.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Blogcraft', 'blogcraft' ),
			__( 'Blogcraft', 'blogcraft' ),
			Blogcraft_Capabilities::MANAGE,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-edit-large',
			30
		);
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'blogcraft' ) );
		}

		$totals     = Blogcraft_Cost::month_totals();
		$waiting    = count( Blogcraft_Review::pending_posts() );
		$configured = Blogcraft_Provider_Registry::is_configured();

		echo '<div class="wrap blogcraft-page">';
		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Blogcraft', 'blogcraft' ) . '</h1>';
		echo '<p>' . esc_html__( 'What is queued, what is waiting on you, and what it has cost.', 'blogcraft' ) . '</p>';
		echo '</div>';

		if ( ! $configured ) {
			printf(
				'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'No AI provider is connected yet.', 'blogcraft' ),
				esc_url( admin_url( 'admin.php?page=' . Blogcraft_Connection::PAGE_SLUG ) ),
				esc_html__( 'Set one up', 'blogcraft' )
			);
		}

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'Queue', 'blogcraft' ) . '</h2>';
		echo '<p>' . esc_html__( 'Each post moves through seven steps, one per run, so no single request has to finish the whole pipeline.', 'blogcraft' ) . '</p>';
		echo '</header>';

		echo '<ul class="blogcraft-stats">';

		$states = array(
			'pending'  => __( 'Waiting', 'blogcraft' ),
			'running'  => __( 'In progress', 'blogcraft' ),
			'complete' => __( 'Finished', 'blogcraft' ),
			'failed'   => __( 'Failed', 'blogcraft' ),
		);

		foreach ( $states as $state => $label ) {
			printf(
				'<li><span class="blogcraft-stat-value">%1$d</span><span class="blogcraft-stat-label">%2$s</span></li>',
				(int) Blogcraft_Queue::count_by_status( $state ),
				esc_html( $label )
			);
		}

		echo '</ul>';

		// A failure count with no route to the reason is a dead end, and this is
		// the first screen anyone lands on.
		$failed = (int) Blogcraft_Queue::count_by_status( 'failed' );

		if ( $failed > 0 ) {
			printf(
				'<p class="blogcraft-callout">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html(
					sprintf(
						/* translators: %d: number of failed jobs. */
						_n(
							'%d post could not be written.',
							'%d posts could not be written.',
							$failed,
							'blogcraft'
						),
						$failed
					)
				),
				esc_url( admin_url( 'admin.php?page=blogcraft-activity' ) ),
				esc_html__( 'See why, and try again', 'blogcraft' )
			);
		}

		echo '<div class="blogcraft-actions">';
		printf(
			'<a class="button button-primary" href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=blogcraft-write' ) ),
			esc_html__( 'Write a post', 'blogcraft' )
		);

		if ( $waiting > 0 ) {
			printf(
				'<a class="button" href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . Blogcraft_Review::PAGE_SLUG ) ),
				esc_html(
					sprintf(
						/* translators: %d: number of posts waiting for review. */
						_n( '%d post needs review', '%d posts need review', $waiting, 'blogcraft' ),
						$waiting
					)
				)
			);
		}

		echo '</div>';
		echo '</section>';

		echo '<section class="blogcraft-card"><header>';
		echo '<h2>' . esc_html__( 'This month', 'blogcraft' ) . '</h2>';
		echo '<p>' . esc_html__( 'Tokens are billed by your provider, not by us. A monthly cap is available in Settings.', 'blogcraft' ) . '</p>';
		echo '</header>';

		echo '<ul class="blogcraft-stats">';
		printf(
			'<li><span class="blogcraft-stat-value">%1$s</span><span class="blogcraft-stat-label">%2$s</span></li>',
			esc_html( number_format_i18n( $totals['requests'] ) ),
			esc_html__( 'Requests', 'blogcraft' )
		);
		printf(
			'<li><span class="blogcraft-stat-value">%1$s</span><span class="blogcraft-stat-label">%2$s</span></li>',
			esc_html( number_format_i18n( $totals['prompt'] ) ),
			esc_html__( 'Prompt tokens', 'blogcraft' )
		);
		printf(
			'<li><span class="blogcraft-stat-value">%1$s</span><span class="blogcraft-stat-label">%2$s</span></li>',
			esc_html( number_format_i18n( $totals['completion'] ) ),
			esc_html__( 'Completion tokens', 'blogcraft' )
		);
		echo '</ul>';
		echo '</section>';

		echo '</div>';
	}
}
