<?php
/**
 * Brand voice and style rules.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns the user's stored brand settings into prompt framing.
 *
 * This is the difference between output that reads like every other AI plugin
 * and output that sounds like the site it is published on. The market's loudest
 * complaint about auto-blogging tools is "same template, keyword swapped" — the
 * cure is giving the model the site's actual voice, audience and prohibitions
 * on every call, not a better one-off prompt.
 */
class Blogcraft_Voice {

	/**
	 * Words and phrases that mark text as machine-written.
	 *
	 * Shipped as a default so a user gets the benefit without having to know
	 * which tells to look for.
	 *
	 * @return array
	 */
	public static function default_banned_words() {
		return array(
			'delve',
			'tapestry',
			'in today\'s fast-paced world',
			'in the ever-evolving landscape',
			'it is important to note',
			'unlock the power',
			'game-changer',
			'dive deep',
			'navigate the complexities',
			'when it comes to',
			'in conclusion',
			'the world of',
			'testament to',
			'elevate your',
		);
	}

	/**
	 * Split a newline or comma separated setting into a clean list.
	 *
	 * @param string $raw Stored value.
	 * @return array
	 */
	public static function to_list( $raw ) {
		$parts = preg_split( '/[\r\n,]+/', (string) $raw );

		if ( ! is_array( $parts ) ) {
			return array();
		}

		$out = array();

		foreach ( $parts as $part ) {
			$part = trim( $part );

			if ( '' !== $part ) {
				$out[] = $part;
			}
		}

		return $out;
	}

	/**
	 * The banned list actually in force: defaults plus anything the user added.
	 *
	 * @return array
	 */
	public static function banned_words() {
		$custom = self::to_list( Blogcraft_Settings::get( 'voice_banned_words' ) );
		$merged = array_merge( self::default_banned_words(), $custom );

		return array_values( array_unique( $merged ) );
	}

	/**
	 * Whether the user has described their site at all.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( (string) Blogcraft_Settings::get( 'voice_niche' ) );
	}

	/**
	 * Build the brand framing appended to every system prompt.
	 *
	 * An unconfigured site still receives the banned-phrase list, because
	 * suppressing the usual AI tells is worth doing with zero setup. Only the
	 * brand-specific framing is omitted, so generation is never blocked behind
	 * a configuration wall.
	 *
	 * @return string
	 */
	public static function system_prompt() {
		$lines = array();

		$niche = trim( (string) Blogcraft_Settings::get( 'voice_niche' ) );
		if ( '' !== $niche ) {
			$lines[] = 'This blog is about: ' . $niche;
		}

		$audience = trim( (string) Blogcraft_Settings::get( 'voice_audience' ) );
		if ( '' !== $audience ) {
			$lines[] = 'You are writing for: ' . $audience;
		}

		$tone = trim( (string) Blogcraft_Settings::get( 'voice_tone' ) );
		if ( '' !== $tone ) {
			$lines[] = 'Tone: ' . $tone;
		}

		$pov = trim( (string) Blogcraft_Settings::get( 'voice_point_of_view' ) );
		if ( '' !== $pov ) {
			$lines[] = 'Point of view: ' . $pov;
		}

		$reading_level = trim( (string) Blogcraft_Settings::get( 'voice_reading_level' ) );
		if ( '' !== $reading_level ) {
			$lines[] = 'Reading level: ' . $reading_level;
		}

		$rules = self::to_list( Blogcraft_Settings::get( 'voice_style_rules' ) );
		if ( ! empty( $rules ) ) {
			$lines[] = 'Style rules you must follow:';

			foreach ( $rules as $rule ) {
				$lines[] = '- ' . $rule;
			}
		}

		$banned = self::banned_words();
		if ( ! empty( $banned ) ) {
			$lines[] = 'Never use these words or phrases: ' . implode( '; ', $banned ) . '.';
		}

		$avoid = self::to_list( Blogcraft_Settings::get( 'voice_banned_topics' ) );
		if ( ! empty( $avoid ) ) {
			$lines[] = 'Never write about: ' . implode( '; ', $avoid ) . '.';
		}

		$experience = trim( (string) Blogcraft_Settings::get( 'voice_experience' ) );
		if ( '' !== $experience ) {
			$lines[] = 'Where it fits naturally, draw on this first-hand experience rather than '
				. 'writing in generalities. Do not invent details beyond it: ' . $experience;
		}

		if ( empty( $lines ) ) {
			return '';
		}

		return "\n\n" . implode( "\n", $lines );
	}
}
