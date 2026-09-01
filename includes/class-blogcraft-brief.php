<?php
/**
 * The next post, described here and written elsewhere.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * A brief filled in on this site for a connected app to collect.
 *
 * On the provider path, the Write a post screen fills in a form and the
 * plugin goes and writes it. On the client path there is nothing here to do
 * the writing, so the screen had been reduced to a sentence to paste — which
 * threw away the topic field, the angle, the evidence box and every per-post
 * override, all of which are exactly what makes a post specific rather than
 * generic.
 *
 * So the form stays and its answers are kept here instead. The app asks for
 * them the moment it is told to write, and gets the same brief the pipeline
 * would have been given.
 */
class Blogcraft_Brief {

	/**
	 * Where the waiting brief lives.
	 */
	const OPTION = 'blogcraft_pending_brief';

	/**
	 * Keep the brief.
	 *
	 * @param array $brief Topic, angle, evidence, overrides and placement.
	 * @return void
	 */
	public static function save( $brief ) {
		update_option(
			self::OPTION,
			array(
				'topic'      => sanitize_text_field( (string) $brief['topic'] ),
				'angle'      => sanitize_textarea_field( (string) $brief['angle'] ),
				'evidence'   => sanitize_textarea_field( (string) $brief['evidence'] ),
				'overrides'  => is_array( $brief['overrides'] ) ? $brief['overrides'] : array(),
				'placement'  => is_array( $brief['placement'] ) ? $brief['placement'] : array(),
				'written_at' => time(),
			),
			false
		);
	}

	/**
	 * The brief that is waiting, if one is.
	 *
	 * @return array Empty when nothing is waiting.
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || empty( $stored['topic'] ) ) {
			return array();
		}

		return $stored;
	}

	/**
	 * Whether anything is waiting to be written.
	 *
	 * @return bool
	 */
	public static function waiting() {
		return ! empty( self::get() );
	}

	/**
	 * Forget it, once something has been written from it.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * The brief as instructions an application can act on.
	 *
	 * The overrides are blueprint fields, which mean nothing to a reader of
	 * the tool output unless they are named. Only the ones that differ from
	 * the standing rules are listed: a brief that repeats every setting is a
	 * brief nobody reads to the end of.
	 *
	 * @return string
	 */
	public static function as_text() {
		$brief = self::get();

		if ( empty( $brief ) ) {
			return '';
		}

		$lines = array( 'Topic: ' . $brief['topic'] );

		if ( '' !== trim( (string) $brief['angle'] ) ) {
			$lines[] = 'Angle for this post: ' . $brief['angle'];
		}

		if ( '' !== trim( (string) $brief['evidence'] ) ) {
			$lines[] = '';
			$lines[] = 'What the author knows that nobody else does. Use every one of these as fact, and do not invent beyond them:';
			$lines[] = $brief['evidence'];
		}

		$standing = Blogcraft_Blueprint::get();
		$changed  = array();

		foreach ( (array) $brief['overrides'] as $field => $value ) {
			if ( ! array_key_exists( $field, $standing ) ) {
				continue;
			}

			if ( wp_json_encode( $standing[ $field ] ) === wp_json_encode( $value ) ) {
				continue;
			}

			$changed[] = $field . ': ' . ( is_array( $value ) ? implode( ', ', $value ) : (string) $value );
		}

		if ( ! empty( $changed ) ) {
			$lines[] = '';
			$lines[] = 'For this post only, these differ from the standing rules:';
			$lines[] = implode( "\n", $changed );
		}

		$category = isset( $brief['placement']['category'] ) ? (int) $brief['placement']['category'] : 0;

		if ( $category > 0 ) {
			$term = get_term( $category, 'category' );

			if ( $term instanceof WP_Term ) {
				$lines[] = '';
				$lines[] = 'Category: ' . $term->name;
			}
		}

		$tags = isset( $brief['placement']['tags'] ) ? trim( (string) $brief['placement']['tags'] ) : '';

		if ( '' !== $tags ) {
			$lines[] = 'Tags: ' . $tags;
		}

		return implode( "\n", $lines );
	}
}
