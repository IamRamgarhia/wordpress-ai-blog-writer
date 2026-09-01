<?php
/**
 * Which of the two ways this site is set up.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * The answer to "how do you want to write?", and what follows from it.
 *
 * The setting was read in one place and used to decide which cards appeared
 * on one screen. Everything else carried on as though the provider way were
 * the only way — so a site being driven by Claude still offered a Write a
 * post screen that calls a provider it has no key for, and a Calendar for
 * scheduled writing that cannot happen on that path. Both fail at the moment
 * somebody tries them, which is the worst place to find out.
 *
 * So the question is asked here and every screen asks this rather than the
 * setting, and a screen that cannot work says so before it is opened.
 */
class Blogcraft_Mode {

	/**
	 * The plugin holds a key and calls a provider.
	 */
	const API = 'api';

	/**
	 * An application the reader already pays for connects and does the writing.
	 */
	const CLIENT = 'client';

	/**
	 * Which way this site is set up.
	 *
	 * Falls back to the provider way, which is what a site that has never
	 * been asked has always behaved as.
	 *
	 * @return string
	 */
	public static function current() {
		$stored = (string) Blogcraft_Settings::get( 'setup_path' );

		return ( self::CLIENT === $stored ) ? self::CLIENT : self::API;
	}

	/**
	 * Whether anybody has actually answered.
	 *
	 * @return bool
	 */
	public static function chosen() {
		return in_array(
			(string) Blogcraft_Settings::get( 'setup_path' ),
			array( self::API, self::CLIENT ),
			true
		);
	}

	/**
	 * Whether an application drives this site.
	 *
	 * @return bool
	 */
	public static function is_client() {
		return self::CLIENT === self::current();
	}

	/**
	 * Whether this site calls a provider itself.
	 *
	 * @return bool
	 */
	public static function is_api() {
		return self::API === self::current();
	}

	/**
	 * What to call the current way, in a sentence.
	 *
	 * @return string
	 */
	public static function label() {
		return self::is_client()
			? __( 'AI client', 'dicecodes-ai-blog-writer' )
			: __( 'API key', 'dicecodes-ai-blog-writer' );
	}

	/**
	 * One line describing how this site writes.
	 *
	 * @return string
	 */
	public static function summary() {
		return self::is_client()
			? __( 'Claude or ChatGPT connects to this site and writes. Nothing here calls a provider.', 'dicecodes-ai-blog-writer' )
			: __( 'This site calls a provider with your key and writes on its own.', 'dicecodes-ai-blog-writer' );
	}

	/**
	 * The screens that only make sense on one path.
	 *
	 * Everything absent from this list works on both. Naming only the
	 * exceptions means a screen added later is available until somebody
	 * decides otherwise, which is the right way round: a new screen that
	 * quietly disappeared on one path would be very hard to notice.
	 *
	 * @return array Screen slug => the only path it belongs to.
	 */
	public static function screens() {
		return array(
			// Calls a provider on the spot. With no key there is nothing for
			// it to call, and the page is a form that cannot be submitted.
			'blogcraft-write'    => self::API,

			// Scheduled and unattended writing, which needs something running
			// when nobody is watching. An application the reader opens is not
			// that.
			'blogcraft-calendar' => self::API,
		);
	}

	/**
	 * Whether a screen is any use on the current path.
	 *
	 * @param string $slug Screen slug.
	 * @return bool
	 */
	public static function allows( $slug ) {
		$only = self::screens();
		$slug = (string) $slug;

		if ( ! isset( $only[ $slug ] ) ) {
			return true;
		}

		return self::current() === $only[ $slug ];
	}

	/**
	 * Say why a screen is not available, and where to go instead.
	 *
	 * Rendered in place of the screen rather than as a redirect: somebody who
	 * followed a bookmark deserves to be told what changed, not bounced.
	 *
	 * @param string $slug Screen slug.
	 * @return void
	 */
	public static function render_unavailable( $slug ) {
		$writes = ( self::API === self::screens()[ $slug ] );

		echo '<div class="wrap blogcraft-page">';
		Blogcraft_Nav::render();

		echo '<div class="blogcraft-head">';
		printf( '<h1>%s</h1>', esc_html__( 'Not on this setup', 'dicecodes-ai-blog-writer' ) );
		printf( '<p>%s</p>', esc_html( self::summary() ) );
		echo '</div>';

		echo '<div class="blogcraft-card">';

		printf(
			'<p>%s</p>',
			esc_html(
				$writes
					? __( 'This screen writes by calling a provider, which this site is not set up to do. Ask your connected app to write instead.', 'dicecodes-ai-blog-writer' )
					: __( 'This screen is for writing on a schedule, which needs something running while nobody is watching. An app you open yourself cannot do that.', 'dicecodes-ai-blog-writer' )
			)
		);

		printf(
			'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=blogcraft-settings' ) ),
			esc_html__( 'Change how this site writes', 'dicecodes-ai-blog-writer' )
		);

		echo '</div>';
		echo '</div>';
	}
}
