<?php
/**
 * Prompt construction and model-output parsing.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the messages sent at each pipeline stage, and parses what comes back.
 *
 * Prompts live in one class so a later phase can expose them for editing without
 * hunting through stage handlers.
 */
class Blogcraft_Prompts {

	/**
	 * Blueprint applied to every prompt, or null for none.
	 *
	 * @var array|null
	 */
	private static $blueprint = null;

	/**
	 * Shared system framing for every stage.
	 *
	 * @return string
	 */
	private static function base_system() {
		$base = 'You are an experienced blog writer. You write clearly and specifically, '
			. 'avoid filler and cliche, and never pad. '
			. 'You always reply with valid JSON and nothing else — no prose before or after, no code fences.';

		// The site's own voice, audience and prohibitions ride on every call; without
		// this the output is indistinguishable from any other tool's.
		$prompt = $base . Blogcraft_Voice::system_prompt();

		if ( is_array( self::$blueprint ) ) {
			$rules = Blogcraft_Blueprint::voice_rules( self::$blueprint );

			if ( '' !== $rules ) {
				$prompt .= '

' . $rules;
			}
		}

		return $prompt;
	}

	/**
	 * The blueprint every prompt built from now on should obey.
	 *
	 * Set by the pipeline from the snapshot on the job, so a blueprint edited
	 * mid-write never changes the post already being written.
	 *
	 * @param array|null $blueprint Blueprint, or null to clear.
	 * @return void
	 */
	public static function use_blueprint( $blueprint ) {
		self::$blueprint = is_array( $blueprint ) ? $blueprint : null;
	}

	/**
	 * The structural rules for the current blueprint, as a prompt block.
	 *
	 * @return string Empty when no blueprint is active.
	 */
	private static function structure_block() {
		if ( ! is_array( self::$blueprint ) ) {
			return '';
		}

		$rules = Blogcraft_Blueprint::structure_rules( self::$blueprint );

		return ( '' === $rules ) ? '' : '

Rules for this article:
' . $rules;
	}

	/**
	 * One numeric limit from the active blueprint.
	 *
	 * @param string $key      Blueprint field.
	 * @param int    $fallback Value to use when no blueprint is active.
	 * @return int
	 */
	private static function limit( $key, $fallback ) {
		if ( ! is_array( self::$blueprint ) || empty( self::$blueprint[ $key ] ) ) {
			return (int) $fallback;
		}

		return (int) self::$blueprint[ $key ];
	}

	/**
	 * Messages asking for an article outline.
	 *
	 * @param string $topic   Topic to write about.
	 * @param array  $sources Reference material, possibly empty.
	 * @param string $instructions Optional guidance for this topic only.
	 * @return array
	 */
	public static function outline( $topic, $sources = array(), $instructions = '' ) {
		$reference = Blogcraft_Research::to_prompt_block( $sources );
		$user      = ( '' === $reference ? '' : $reference . '

' )
			. "Plan a blog post about: {$topic}\n\n"
			. "Reply with JSON of exactly this shape:\n"
			. '{"title":"","slug":"","meta_description":"","sections":[{"heading":""}]}' . "\n\n"
			. "Rules:\n"
			// Both limits are measured on the finished post, so they are taken
			// from the blueprint rather than hardcoded here. Asking for one
			// number and checking against another is how a setting comes to do
			// nothing.
			. sprintf( "- title: compelling, %d characters at most, no colon-subtitle pattern\n", self::limit( 'meta_title_max', 60 ) )
			. "- slug: lowercase, hyphenated, no stop words\n"
			. sprintf( "- meta_description: between 70 and %d characters, describing what the reader gains\n", self::limit( 'meta_desc_max', 155 ) )
			. '- sections: headings that build an argument, not a list of synonyms'
			. self::extra( $instructions )
			. self::structure_block();

		return array(
			array(
				'role'    => 'system',
				'content' => self::base_system(),
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}

	/**
	 * Messages asking for the full draft.
	 *
	 * @param string $topic   Topic.
	 * @param array  $outline Outline produced by the previous stage.
	 * @param array  $sources Reference material, possibly empty.
	 * @param string $instructions Optional guidance for this topic only.
	 * @return array
	 */
	public static function draft( $topic, $outline, $sources = array(), $instructions = '' ) {
		$headings = array();

		if ( ! empty( $outline['sections'] ) && is_array( $outline['sections'] ) ) {
			foreach ( $outline['sections'] as $section ) {
				if ( is_array( $section ) && ! empty( $section['heading'] ) ) {
					$headings[] = '- ' . $section['heading'];
				}
			}
		}

		$reference = Blogcraft_Research::to_prompt_block( $sources );

		$user = ( '' === $reference ? '' : $reference . '

' )
			. "Write the blog post.\n\nTopic: {$topic}\n"
			. 'Title: ' . ( isset( $outline['title'] ) ? $outline['title'] : $topic ) . "\n"
			. "Sections:\n" . implode( "\n", $headings ) . "\n\n"
			. "Reply with JSON of exactly this shape:\n"
			. '{"intro":"","key_takeaways":[""],"sections":[{"heading":"","paragraphs":[""]}],"faq":[{"question":"","answer":""}]}' . "\n\n"
			. "Rules:\n"
			. "- intro: one paragraph that answers the title's implicit question directly. No throat-clearing.\n"
			. "- key_takeaways: specific, useful points. Not a summary of the headings.\n"
			. "- Each section: enough paragraphs to cover it properly.\n"
			. "- faq: questions a reader would actually search for, with direct answers.\n"
			. '- Plain text only in every field. No markdown, no HTML.
'
			. '- Where reference material gives a figure or a fact, use it and name the source in the sentence. '
			. 'Where it shows what existing coverage already says, add what it leaves out rather than repeating it.'
			. self::extra( $instructions )
			. self::structure_block();

		return array(
			array(
				'role'    => 'system',
				'content' => self::base_system(),
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}

	/**
	 * Messages asking only for the opening of an article.
	 *
	 * Split out from the body because asking for a whole article in one strict
	 * JSON turn is what truncates: the response hits the token ceiling mid-string,
	 * json_decode fails, and the entire job dies having spent every token it was
	 * going to spend. Small turns cannot truncate.
	 *
	 * @param string $topic        Topic.
	 * @param array  $outline      Outline from the previous stage.
	 * @param array  $sources      Reference material, possibly empty.
	 * @param string $instructions Guidance for this topic only.
	 * @param int    $words        Word budget for the introduction.
	 * @param int    $takeaways    How many takeaways to write, zero for none.
	 * @return array
	 */
	public static function intro( $topic, $outline, $sources = array(), $instructions = '', $words = 120, $takeaways = 4 ) {
		$headings = array();

		if ( ! empty( $outline['sections'] ) && is_array( $outline['sections'] ) ) {
			foreach ( $outline['sections'] as $section ) {
				if ( is_array( $section ) && ! empty( $section['heading'] ) ) {
					$headings[] = '- ' . $section['heading'];
				}
			}
		}

		$reference = Blogcraft_Research::to_prompt_block( $sources );

		$shape = ( $takeaways > 0 )
			? '{"intro":"","key_takeaways":[""]}'
			: '{"intro":""}';

		$user = ( '' === $reference ? '' : $reference . "\n\n" )
			. "Write only the opening of this article. The sections come later.\n\n"
			. "Topic: {$topic}\n"
			. 'Title: ' . ( isset( $outline['title'] ) ? $outline['title'] : $topic ) . "\n"
			. "Sections that will follow:\n" . implode( "\n", $headings ) . "\n\n"
			. "Reply with JSON of exactly this shape:\n" . $shape . "\n\n"
			. "Rules:\n"
			. sprintf( "- intro: about %d words, answering the title's implicit question directly. No throat-clearing.\n", (int) $words )
			// The opening is measured, so the rule it is measured against is
			// stated rather than implied. It is also the passage an answer
			// panel lifts, which is why the first two sentences must stand
			// alone with the subject named in them.
			. "- The first two sentences must answer the question on their own, name the subject, and total under sixty words. Do not open with \"In today's\", \"In this article\", \"When it comes to\", \"Have you ever\" or any similar wind-up.\n"
			. ( $takeaways > 0
				? sprintf( "- key_takeaways: exactly %d specific, useful points. Not a summary of the headings.\n", (int) $takeaways )
				: '' )
			. '- Plain text only in every field. No markdown, no HTML.'
			. self::extra( $instructions )
			. self::structure_block();

		return array(
			array(
				'role'    => 'system',
				'content' => self::base_system(),
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}

	/**
	 * Messages asking for one section of an article.
	 *
	 * Carries what has been written so far as headings only, not full text: the
	 * model needs to know what it has already covered so it does not repeat
	 * itself, and sending the whole article back on every call would grow the
	 * prompt quadratically for no extra benefit.
	 *
	 * @param string $topic        Topic.
	 * @param string $heading      Heading for this section.
	 * @param array  $covered      Headings already written.
	 * @param array  $remaining    Headings still to come.
	 * @param array  $sources      Reference material, possibly empty.
	 * @param string $instructions Guidance for this topic only.
	 * @param int    $words        Word budget for this section.
	 * @return array
	 */
	public static function section( $topic, $heading, $covered, $remaining, $sources = array(), $instructions = '', $words = 200 ) {
		$reference = Blogcraft_Research::to_prompt_block( $sources );

		$context = '';

		if ( ! empty( $covered ) ) {
			$context .= "Already written, so do not repeat it:\n- " . implode( "\n- ", $covered ) . "\n\n";
		}

		if ( ! empty( $remaining ) ) {
			$context .= "Still to come, so leave these alone:\n- " . implode( "\n- ", $remaining ) . "\n\n";
		}

		$user = ( '' === $reference ? '' : $reference . "\n\n" )
			. "Write one section of an article about: {$topic}\n\n"
			. $context
			. "The section to write now is: {$heading}\n\n"
			. "Reply with JSON of exactly this shape:\n"
			. '{"paragraphs":[""]}' . "\n\n"
			. "Rules:\n"
			. sprintf( "- About %d words across however many paragraphs it needs.\n", (int) $words )
			. "- Open with the substance, not a restatement of the heading.\n"
			. "- Be specific. A number, an example or a named thing beats an adjective.\n"
			. '- Plain text only. No markdown, no HTML, no heading text.'
			. self::extra( $instructions )
			. self::structure_block();

		return array(
			array(
				'role'    => 'system',
				'content' => self::base_system(),
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}

	/**
	 * Messages asking for the questions and answers.
	 *
	 * @param string $topic   Topic.
	 * @param array  $covered Headings already written.
	 * @param int    $count   How many questions.
	 * @return array
	 */
	public static function faq( $topic, $covered, $count = 4 ) {
		$user = "Write the questions and answers for an article about: {$topic}\n\n"
			. ( empty( $covered ) ? '' : "The article already covers:\n- " . implode( "\n- ", $covered ) . "\n\n" )
			. "Reply with JSON of exactly this shape:\n"
			. '{"faq":[{"question":"","answer":""}]}' . "\n\n"
			. "Rules:\n"
			. sprintf( "- Exactly %d questions a reader would actually search for.\n", (int) $count )
			. "- Do not ask anything the article already answers in full.\n"
			. "- Answers of two or three sentences.\n"
			. '- Plain text only. No markdown, no HTML.'
			. self::structure_block();

		return array(
			array(
				'role'    => 'system',
				'content' => self::base_system(),
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}

	/**
	 * Messages asking the model to critique its own draft.
	 *
	 * @param array $article Draft article.
	 * @return array
	 */
	public static function critique( $article ) {
		$user = "Critique this draft honestly. Be specific and terse.\n\n"
			. wp_json_encode( $article ) . "\n\n"
			. "Reply with JSON of exactly this shape:\n"
			. '{"problems":[""]}' . "\n\n"
			. 'Look for: vague claims that say nothing, repetition between sections, filler sentences, '
			. 'cliche openings, paragraphs that restate their own heading, and any place a specific '
			. 'example or number would be worth more than the sentence that is there. '
			. 'If a section is genuinely fine, do not invent a problem for it.';

		return array(
			array(
				'role'    => 'system',
				'content' => self::base_system(),
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}

	/**
	 * Messages asking the model to apply its own critique.
	 *
	 * @param array $article  Draft article.
	 * @param array $problems Problems raised by the critique stage.
	 * @param array $outline  Outline the draft was written under, for title and meta fixes.
	 * @return array
	 */
	public static function revise( $article, $problems, $outline = array() ) {
		$list = '';

		foreach ( (array) $problems as $problem ) {
			if ( is_scalar( $problem ) ) {
				$list .= '- ' . $problem . "\n";
			}
		}

		// The title and the meta description are measured but live on the
		// outline, not the draft. Without this the revise stage could be handed
		// "the title is too long" and have no way to act on it, which is a
		// deduction dressed up as a finding.
		$headline = '';

		if ( is_array( $outline ) && ( ! empty( $outline['title'] ) || ! empty( $outline['meta_description'] ) ) ) {
			$headline = "\n\nThe title and meta description this draft was written under:\n"
				. 'Title: ' . ( isset( $outline['title'] ) ? $outline['title'] : '' ) . "\n"
				. 'Meta description: ' . ( isset( $outline['meta_description'] ) ? $outline['meta_description'] : '' ) . "\n\n"
				. 'If, and only if, one of the problems above concerns the title or the meta description, '
				. 'add a "title" or "meta_description" key to your reply with a corrected version. '
				. 'Leave them out otherwise.';
		}

		$user = "Rewrite this draft, fixing every problem listed.\n\nDraft:\n"
			. wp_json_encode( $article ) . "\n\nProblems to fix:\n" . $list
			. $headline . "\n"
			. 'Reply with JSON in exactly the same shape as the draft. Keep what works; '
			. 'change what was criticised. Do not add new sections.'
			. self::structure_block();

		return array(
			array(
				'role'    => 'system',
				'content' => self::base_system(),
			),
			array(
				'role'    => 'user',
				'content' => $user,
			),
		);
	}

	/**
	 * Render per-topic guidance as a prompt fragment.
	 *
	 * Carrying instructions for this one post is the direct answer to the
	 * commonest complaint about tools like this, which is that every post reads
	 * like the same template with a keyword swapped.
	 *
	 * @param string $instructions Guidance for this topic only.
	 * @return string Empty when there is none.
	 */
	private static function extra( $instructions ) {
		$instructions = trim( (string) $instructions );

		if ( '' === $instructions ) {
			return '';
		}

		return '

For this post specifically: ' . $instructions;
	}

	/**
	 * Pull a JSON object out of a model response.
	 *
	 * Models frequently ignore "no code fences" and wrap the payload, or add a
	 * sentence before it. Rather than failing the whole generation on that, this
	 * strips fences and falls back to the outermost brace pair.
	 *
	 * @param string $text Raw model output.
	 * @return array Empty array when nothing parseable is found.
	 */
	public static function extract_json( $text ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return array();
		}

		// Strip a leading ```json / ``` fence and its closing pair.
		$text = preg_replace( '/^```[a-zA-Z]*\s*/', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );
		$text = trim( (string) $text );

		$decoded = json_decode( $text, true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Fall back to the outermost brace pair, for responses wrapped in prose.
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );

		if ( false === $start || false === $end || $end <= $start ) {
			return array();
		}

		$decoded = json_decode( substr( $text, $start, $end - $start + 1 ), true );

		return is_array( $decoded ) ? $decoded : array();
	}
}
