<?php
/**
 * Whether a brief is strong enough to produce something worth reading.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Judges the brief, before a single token is spent on it.
 *
 * The scorecard judges the finished post, which is too late to change what
 * went into it. Almost everything that separates a post worth reading from a
 * generic one is decided here, in the brief — and the plugin was silent about
 * it, so a reader could type six words, press the button, and get exactly the
 * article those six words deserved without ever being told why.
 *
 * Deliberately not a gate. Nothing here blocks writing: a plugin that demands
 * twenty fields before it will do anything is one people uninstall on the
 * first attempt, and most of these fields have honest defaults. What it does
 * is name the cost of skipping each one, in the terms that actually matter —
 * "posts without this read like every other AI post" is true and useful;
 * "this field is required" is neither.
 */
class Blogcraft_Readiness {

	/**
	 * Assess a brief and say what is missing.
	 *
	 * @param string $topic     Topic as typed.
	 * @param string $angle     Per-post angle.
	 * @param string $evidence What the writer knows first-hand.
	 * @return array Keys: score (0-100), items (list of check arrays).
	 */
	public static function assess( $topic, $angle, $evidence ) {
		$items = array();

		$items[] = self::topic_check( $topic );
		$items[] = self::evidence_check( $evidence );
		$items[] = self::angle_check( $angle );
		$items[] = self::voice_check();
		$items[] = self::research_check();

		$earned = 0;
		$total  = 0;

		foreach ( $items as $item ) {
			$total += $item['weight'];

			if ( $item['ok'] ) {
				$earned += $item['weight'];
			}
		}

		return array(
			'score' => ( $total > 0 ) ? (int) round( ( $earned / $total ) * 100 ) : 100,
			'items' => $items,
		);
	}

	/**
	 * Build one assessment row.
	 *
	 * @param string $key    Machine name.
	 * @param bool   $ok     Whether it is satisfied.
	 * @param int    $weight How much it matters.
	 * @param string $label  What it is.
	 * @param string $why    What skipping it costs.
	 * @return array
	 */
	private static function item( $key, $ok, $weight, $label, $why ) {
		return array(
			'key'    => $key,
			'ok'     => (bool) $ok,
			'weight' => (int) $weight,
			'label'  => $label,
			'why'    => $why,
		);
	}

	/**
	 * Turn a bare topic into questions only the writer can answer.
	 *
	 * The gap this closes is the one that produces generic posts: somebody
	 * types a topic, has nothing in mind for the evidence field, and leaves it
	 * empty — not because they know nothing, but because "what do you know
	 * that nobody else does" is a hard question asked cold.
	 *
	 * Asked about a specific topic it becomes easy. So the model is used to
	 * ask better questions, never to answer them: it returns prompts, and the
	 * writer supplies the facts. Inventing plausible-sounding evidence here
	 * would be fabrication dressed as helpfulness, and it would poison the one
	 * check the whole quality system leans on.
	 *
	 * @param string $topic Topic as typed.
	 * @return array Keys: angle (string), questions (list of strings).
	 * @throws RuntimeException When the provider cannot be reached.
	 */
	public static function suggest_for( $topic ) {
		$topic = trim( wp_strip_all_tags( (string) $topic ) );

		if ( '' === $topic ) {
			return array(
				'angle'     => '',
				'questions' => array(),
			);
		}

		$described = Blogcraft_Blueprint::get();
		$niche     = trim( (string) $described['niche'] );
		$audience  = trim( (string) $described['audience_custom'] );

		$user = "A blogger is about to write a post and needs help planning it.\n\n"
			. 'Topic: ' . $topic . "\n"
			. ( '' === $niche ? '' : 'The blog is about: ' . $niche . "\n" )
			. ( '' === $audience ? '' : 'Written for: ' . $audience . "\n" )
			. "\nReply with JSON of exactly this shape:\n"
			. '{"angle":"","questions":["",""]}' . "\n\n"
			. "Rules:\n"
			. "- angle: one sentence proposing what would make this post different from the ones already published on this topic. Concrete, not \"a unique perspective\".\n"
			. "- questions: exactly four questions whose answers only this particular author could give. Ask about numbers they measured, prices they paid, what they tried that failed, how long something actually took, what surprised them.\n"
			. "- Ask for facts. Never invent them, never suggest an answer, never include an example answer.\n"
			. "- Every question must be specific to this topic. A question that would work for any topic is a wasted question.\n"
			. '- Plain text only in every field. No markdown, no HTML.';

		$result = Blogcraft_Pipeline::ask_model(
			array(
				array(
					'role'    => 'system',
					'content' => 'You help bloggers plan posts. You ask sharp, specific questions and never answer them yourself. You always reply with valid JSON and nothing else.',
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			)
		);

		$questions = array();

		if ( isset( $result['questions'] ) && is_array( $result['questions'] ) ) {
			foreach ( $result['questions'] as $question ) {
				$question = trim( wp_strip_all_tags( (string) $question ) );

				if ( '' !== $question ) {
					$questions[] = $question;
				}
			}
		}

		return array(
			'angle'     => isset( $result['angle'] ) ? trim( wp_strip_all_tags( (string) $result['angle'] ) ) : '',
			'questions' => array_slice( $questions, 0, 4 ),
		);
	}

	/**
	 * A topic that says what the post should answer.
	 *
	 * @param string $topic Topic as typed.
	 * @return array
	 */
	private static function topic_check( $topic ) {
		$words = str_word_count( wp_strip_all_tags( (string) $topic ) );

		return self::item(
			'topic',
			$words >= 4,
			2,
			__( 'A topic that says what to answer', 'dicecodes-ai-blog-writer' ),
			__( 'Two or three words is a category, not a question. A sentence gives the outline something to aim at, and outlines built from a bare keyword drift into whatever the model already knows about the subject.', 'dicecodes-ai-blog-writer' )
		);
	}

	/**
	 * The one thing a model cannot produce.
	 *
	 * @param string $evidence What the writer knows first-hand.
	 * @return array
	 */
	private static function evidence_check( $evidence ) {
		$words = str_word_count( wp_strip_all_tags( (string) $evidence ) );

		return self::item(
			'evidence',
			$words >= 12,
			5,
			__( 'Something only you know', 'dicecodes-ai-blog-writer' ),
			__( 'This is the heaviest check on the finished post, and the only part of a page a model genuinely cannot produce. A number you measured, a price you paid, what went wrong when you tried it. Without it the post can only restate what is already published, which is exactly the kind of page search engines discount.', 'dicecodes-ai-blog-writer' )
		);
	}

	/**
	 * An angle, so two posts on one subject differ.
	 *
	 * @param string $angle Per-post angle.
	 * @return array
	 */
	private static function angle_check( $angle ) {
		return self::item(
			'angle',
			'' !== trim( (string) $angle ),
			2,
			__( 'An angle for this one post', 'dicecodes-ai-blog-writer' ),
			__( 'Without it every post on a subject arrives at the same shape, because the same rules produced it. An angle is what makes this one yours rather than the default treatment.', 'dicecodes-ai-blog-writer' )
		);
	}

	/**
	 * A described voice, which every request carries.
	 *
	 * @return array
	 */
	private static function voice_check() {
		$described = Blogcraft_Blueprint::get();
		$niche     = trim( (string) $described['niche'] );
		$audience  = trim( (string) $described['audience_custom'] );

		return self::item(
			'voice',
			'' !== $niche && '' !== $audience,
			3,
			__( 'A described voice and reader', 'dicecodes-ai-blog-writer' ),
			__( 'Sent with every request. A button in Settings fills it in from your posts.', 'dicecodes-ai-blog-writer' )
		);
	}

	/**
	 * At least one source of current material.
	 *
	 * @return array
	 */
	private static function research_check() {
		$on = false;

		foreach ( array_keys( Blogcraft_Research::free_sources() ) as $source ) {
			if ( Blogcraft_Settings::get( $source ) ) {
				$on = true;
				break;
			}
		}

		if ( ! $on ) {
			$on = 'none' !== (string) Blogcraft_Settings::get( 'research_provider' );
		}

		return self::item(
			'research',
			$on,
			3,
			__( 'Somewhere to research from', 'dicecodes-ai-blog-writer' ),
			__( 'With research on, the model is handed current sources and writes from them. With everything off it writes from memory alone, which dates badly and cannot cite anything. Wikipedia and Hacker News need no key.', 'dicecodes-ai-blog-writer' )
		);
	}
}
