<?php
/**
 * Where the documentation is.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * The address of the published documentation, and nothing else.
 *
 * The plugin used to carry the whole of it: a Help screen of its own, and a
 * "How this works" panel folded into every settings card. That meant two
 * copies of every explanation, one of which was always the older, and a
 * screen nobody arrives at except from the plugin itself.
 *
 * It is one page on the web now. A reader gets screenshots and worked
 * examples that would never fit in an admin panel, corrections reach them
 * without waiting for a release, and there is exactly one copy to be right.
 * The cost is honest and worth stating: help needs a connection, and the
 * page has to stay where it is. Every link the plugin makes is built here,
 * from one address, so it can only move once.
 */
class Blogcraft_Docs {

	/**
	 * Where the documentation lives.
	 *
	 * Every help link in the plugin is this plus a section anchor, and the
	 * anchors are the section ids on that page. Nothing warns you when one
	 * of them moves, so the test suite checks the anchors the plugin uses
	 * against the ones the page actually has.
	 */
	const HOME = 'https://dicecodes.com/ai-blog-writer/';

	/**
	 * The screen this used to be, kept only to say where it went.
	 */
	const OLD_SLUG = 'blogcraft-help';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_moved' ), 24 );
	}

	/**
	 * Keep the old Help address answering, without a menu entry.
	 *
	 * The screen is gone, but the address is in people's bookmarks and in
	 * their browser history. Unregistered, WordPress answers it with "Sorry,
	 * you are not allowed to access this page" — which is its message for a
	 * capability failure, so it reads as an account problem rather than as a
	 * page that moved, and offers nowhere to go.
	 *
	 * Registered and then removed from the menu: reachable by address,
	 * absent from the navigation.
	 *
	 * @return void
	 */
	public static function register_moved() {
		add_submenu_page(
			Blogcraft_Admin::MENU_SLUG,
			__( 'Documentation', 'dicecodes-ai-blog-writer' ),
			__( 'Documentation', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Capabilities::MANAGE,
			self::OLD_SLUG,
			array( __CLASS__, 'render_moved' )
		);

		remove_submenu_page( Blogcraft_Admin::MENU_SLUG, self::OLD_SLUG );
	}

	/**
	 * Say where the documentation went.
	 *
	 * @return void
	 */
	public static function render_moved() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'dicecodes-ai-blog-writer' ) );
		}

		echo '<div class="wrap blogcraft-page">';

		Blogcraft_Nav::render();

		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'The documentation moved', 'dicecodes-ai-blog-writer' ) . '</h1>';
		echo '<p>' . esc_html__( 'It is one page on the web now, rather than a copy inside the plugin that was always the older of the two. Corrections reach you there without waiting for an update.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</div>';

		echo '<section class="blogcraft-card"><div class="blogcraft-actions">';

		printf(
			'<a class="button button-primary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( self::site_url() ),
			esc_html__( 'Read the documentation', 'dicecodes-ai-blog-writer' )
		);

		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( admin_url( 'admin.php?page=' . Blogcraft_Admin::MENU_SLUG ) ),
			esc_html__( 'Back to the overview', 'dicecodes-ai-blog-writer' )
		);

		echo '</div></section>';
		echo '</div>';
	}

	/**
	 * The address of one section of the documentation.
	 *
	 * @param string $anchor Section anchor, or '' for the top of the page.
	 * @return string
	 */
	public static function site_url( $anchor = '' ) {
		$anchor = sanitize_title( (string) $anchor );

		return ( '' === $anchor ) ? self::HOME : self::HOME . '#' . $anchor;
	}

	/**
	 * The attributes a link needs when it leaves WordPress.
	 *
	 * The rule, rather than four separate remembers: anything that takes
	 * somebody off their own admin opens a new tab, because they are reading
	 * it while setting something up and the half-filled form behind them is
	 * the thing they came back to.
	 *
	 * Answers with a fact rather than with markup, so a caller picks between
	 * two whole format strings instead of printing attributes built
	 * elsewhere. Nothing then reaches the page that has not been escaped
	 * where it is written.
	 *
	 * @param string $url Where the link goes.
	 * @return bool Whether it leaves this WordPress.
	 */
	public static function leaves( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );

		if ( ! $host ) {
			return false;
		}

		return wp_parse_url( admin_url(), PHP_URL_HOST ) !== $host;
	}

	/**
	 * Print a link to one section, away from the screen being worked on.
	 *
	 * A new tab deliberately: these are read while setting something up, and
	 * losing a half-filled form to go and read about it is how somebody ends
	 * up not reading about it.
	 *
	 * Printed rather than returned, and escaped here at the point of output,
	 * because a helper that hands back markup makes every caller either trust
	 * it or suppress the check that would have caught it.
	 *
	 * @param string $anchor Section anchor.
	 * @param string $label  Link text.
	 * @param string $style  Optional class for the anchor.
	 * @return void
	 */
	public static function link( $anchor, $label, $style = '' ) {
		printf(
			'<a class="%1$s" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
			esc_attr( $style ),
			esc_url( self::site_url( $anchor ) ),
			esc_html( $label )
		);
	}
}
