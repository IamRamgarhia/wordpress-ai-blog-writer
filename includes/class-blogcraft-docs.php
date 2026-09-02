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
