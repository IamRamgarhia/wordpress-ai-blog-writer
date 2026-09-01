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
		$custom = self::to_list( Blogcraft_Blueprint::get()['banned_phrases'] );
		$merged = array_merge( self::default_banned_words(), $custom );

		return array_values( array_unique( $merged ) );
	}

	/**
	 * Whether the user has described their site at all.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return '' !== trim( (string) Blogcraft_Blueprint::get()['niche'] );
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
		// Everything here used to come from the voice_* settings, and
		// Prompts::base_system() then appended the blueprint's own voice
		// rules to it. Every request carried two versions of the tone, the
		// reader, the point of view, the reading level, the banned words
		// and the avoided subjects — set on two screens, with nothing
		// keeping them in agreement.
		//
		// Only what the blueprint does not already say belongs here. The
		// rest is its job, and it is the one with per-post overrides.
		$blueprint = Blogcraft_Blueprint::get();
		$lines     = array();

		$niche = trim( (string) $blueprint['niche'] );

		if ( '' !== $niche ) {
			$lines[] = 'This blog is about: ' . $niche;
		}

		$rules = self::to_list( $blueprint['style_rules'] );

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

		$experience = trim( (string) $blueprint['experience'] );

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
