<?php
/**
 * Navigation shared by every Blogcraft screen.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders one row of links to every Blogcraft screen.
 *
 * The submenu already lists these, but it collapses on narrow screens, sits
 * far from where the work happens, and gives no sense of how the screens
 * relate. Repeating them at the top of each page costs one row and removes the
 * commonest reason to go hunting in the sidebar.
 *
 * The current screen is a span rather than a link. A link to the page you are
 * already on is a small lie, and screen readers announce it as a destination.
 */
class Blogcraft_Nav {

	/**
	 * Every screen, in the order they are used.
	 *
	 * @return array Slug => label.
	 */
	public static function screens() {
		$screens = array(
			Blogcraft_Admin::MENU_SLUG    => __( 'Overview', 'dicecodes-ai-blog-writer' ),
			'blogcraft-write'             => __( 'Write a post', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Progress::PAGE_SLUG => __( 'Being written', 'dicecodes-ai-blog-writer' ),
			'blogcraft-blueprint'         => __( 'How it writes', 'dicecodes-ai-blog-writer' ),
			'blogcraft-calendar'          => __( 'Calendar', 'dicecodes-ai-blog-writer' ),
			'blogcraft-library'           => __( 'Written by AI', 'dicecodes-ai-blog-writer' ),
			'blogcraft-review'            => __( 'Needs review', 'dicecodes-ai-blog-writer' ),
			'blogcraft-activity'          => __( 'Activity', 'dicecodes-ai-blog-writer' ),
			'blogcraft-settings'          => __( 'Settings', 'dicecodes-ai-blog-writer' ),
		);

		// A tab for an empty queue is a tab that is never worth clicking. It
		// stays while it is the page being viewed, because removing the tab
		// you are standing on is worse than showing an empty one.
		if ( ! Blogcraft_Review::has_pending() && 'blogcraft-review' !== self::current() ) {
			unset( $screens['blogcraft-review'] );
		}

		// A screen that cannot work on this setup is worse than a missing
		// one: it fails at the moment somebody tries to use it, which is
		// the worst place to find out.
		foreach ( array_keys( $screens ) as $slug ) {
			if ( ! Blogcraft_Mode::allows( $slug ) ) {
				unset( $screens[ $slug ] );
			}
		}

		// The progress screen is reached by an id in the address and has no
		// menu entry, so refreshing or closing the tab left a post being
		// written with nothing anywhere pointing back at it. It appears only
		// while there is something to point at.
		if ( Blogcraft_Queue::newest_open_job() <= 0 && Blogcraft_Progress::PAGE_SLUG !== self::current() ) {
			unset( $screens[ Blogcraft_Progress::PAGE_SLUG ] );
		}

		return $screens;
	}

	/**
	 * The slug of the screen being viewed.
	 *
	 * @return string
	 */
	private static function current() {
		// Reading which tab to highlight is not a state change, so it needs no
		// nonce; the value is only ever compared against a known list.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only: which screen is open, so the tab for it can be marked current.

		return $page;
	}

	/**
	 * Render the navigation.
	 *
	 * @return void
	 */
	public static function render() {
		$current = self::current();
		$waiting = count( Blogcraft_Review::pending_posts() );

		echo '<nav class="bc-nav" aria-label="' . esc_attr__( 'Dicecodes AI Blog Writer screens', 'dicecodes-ai-blog-writer' ) . '">';

		foreach ( self::screens() as $slug => $label ) {
			$bubble = '';

			if ( 'blogcraft-review' === $slug && $waiting > 0 ) {
				$bubble = sprintf( '<span class="bc-nav-count">%d</span>', (int) $waiting );
			}

			// Built whole, then filtered once on the way out. Handing the
			// filtered piece to printf as an argument is the same thing, but
			// PHPCS cannot see through an argument list to know that — and a
			// rule that has to be argued with at every call site is one that
			// ends up suppressed instead.
			if ( $slug === $current ) {
				$item = sprintf(
					'<span class="bc-nav-item is-current" aria-current="page">%1$s%2$s</span>',
					esc_html( $label ),
					$bubble
				);

				echo wp_kses( $item, Blogcraft_Markup::allowed() );
				continue;
			}

			$item = sprintf(
				'<a class="bc-nav-item" href="%1$s">%2$s%3$s</a>',
				esc_url( admin_url( 'admin.php?page=' . $slug ) ),
				esc_html( $label ),
				$bubble
			);

			echo wp_kses( $item, Blogcraft_Markup::allowed() );
		}

		echo '</nav>';

		// Page content on our own screen rather than a dashboard notice.
		// It renders here because this is the one thing every Blogcraft
		// screen already calls.
		Blogcraft_Notices::render_cron_health_notice();
	}
}
