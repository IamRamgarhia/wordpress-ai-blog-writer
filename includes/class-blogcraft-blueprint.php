<?php
/**
 * Content blueprints: the full set of rules for how a post is written.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Defines, stores and resolves the rules that shape a generated post.
 *
 * The plugin previously carried five free-text voice fields and a pass/fail
 * quality number, which is far less control than any comparable tool offers and
 * left most of "write it the way I want" to a paragraph of prose the model was
 * free to ignore. A blueprint replaces that with named fields that each land
 * somewhere specific: in a prompt, or in a check the scorer performs.
 *
 * Two rules hold this together. Every field below is either sent to the model
 * or measured afterwards — a control that changes nothing is worse than no
 * control. And every field has a working default, so the plugin still writes a
 * good post for someone who never opens the editor.
 *
 * A resolved blueprint is snapshotted onto the job when work is queued, so
 * editing one never changes a post that is already part-written.
 */
class Blogcraft_Blueprint {

	/**
	 * Option holding every saved blueprint, keyed by slug.
	 */
	const OPTION = 'blogcraft_blueprints';

	/**
	 * Slug of the blueprint used when nothing else is chosen.
	 */
	const DEFAULT_SLUG = 'default';

	/**
	 * Tones offered as presets.
	 *
	 * @return array Machine value => label.
	 */
	public static function tones() {
		return array(
			'conversational' => __( 'Conversational', 'blogcraft-ai-writer' ),
			'professional'   => __( 'Professional', 'blogcraft-ai-writer' ),
			'friendly'       => __( 'Friendly', 'blogcraft-ai-writer' ),
			'authoritative'  => __( 'Authoritative', 'blogcraft-ai-writer' ),
			'plain'          => __( 'Plain and direct', 'blogcraft-ai-writer' ),
			'witty'          => __( 'Witty', 'blogcraft-ai-writer' ),
			'empathetic'     => __( 'Empathetic', 'blogcraft-ai-writer' ),
			'journalistic'   => __( 'Journalistic', 'blogcraft-ai-writer' ),
			'academic'       => __( 'Academic', 'blogcraft-ai-writer' ),
			'enthusiastic'   => __( 'Enthusiastic', 'blogcraft-ai-writer' ),
			'custom'         => __( 'Something else — I will describe it', 'blogcraft-ai-writer' ),
		);
	}

	/**
	 * Narrative points of view.
	 *
	 * @return array
	 */
	public static function points_of_view() {
		return array(
			'second'       => __( 'Second person — you', 'blogcraft-ai-writer' ),
			'first_plural' => __( 'First person plural — we', 'blogcraft-ai-writer' ),
			'first_person' => __( 'First person — I', 'blogcraft-ai-writer' ),
			'third'        => __( 'Third person — they', 'blogcraft-ai-writer' ),
		);
	}

	/**
	 * Reading levels, with the Flesch Reading Ease band each one targets.
	 *
	 * The band is what the scorer checks against, so this is not a label the
	 * model is merely asked to honour.
	 *
	 * @return array Value => array( label, min, max ).
	 */
	public static function reading_levels() {
		return array(
			'simple'   => array( __( 'Simple — anyone can follow it', 'blogcraft-ai-writer' ), 70, 100 ),
			'general'  => array( __( 'General — a wide audience', 'blogcraft-ai-writer' ), 60, 80 ),
			'informed' => array( __( 'Informed — familiar with the subject', 'blogcraft-ai-writer' ), 45, 65 ),
			'expert'   => array( __( 'Expert — assumes the vocabulary', 'blogcraft-ai-writer' ), 25, 55 ),
		);
	}

	/**
	 * How an article should open.
	 *
	 * @return array
	 */
	public static function intro_styles() {
		return array(
			'direct'    => __( 'Answer the question immediately', 'blogcraft-ai-writer' ),
			'hook'      => __( 'Open with a hook', 'blogcraft-ai-writer' ),
			'problem'   => __( 'Name the problem the reader has', 'blogcraft-ai-writer' ),
			'statistic' => __( 'Open with a figure', 'blogcraft-ai-writer' ),
			'story'     => __( 'Open with a short anecdote', 'blogcraft-ai-writer' ),
		);
	}

	/**
	 * How an article should close.
	 *
	 * @return array
	 */
	public static function conclusion_styles() {
		return array(
			'summary'    => __( 'Summarise the main points', 'blogcraft-ai-writer' ),
			'next_steps' => __( 'Give the reader next steps', 'blogcraft-ai-writer' ),
			'action'     => __( 'End on a call to action', 'blogcraft-ai-writer' ),
			'none'       => __( 'No conclusion section', 'blogcraft-ai-writer' ),
		);
	}

	/**
	 * Devices that make writing read as though a person wrote it.
	 *
	 * @return array
	 */
	public static function literary_devices() {
		return array(
			'analogy'  => __( 'Analogies', 'blogcraft-ai-writer' ),
			'example'  => __( 'Concrete examples', 'blogcraft-ai-writer' ),
			'anecdote' => __( 'Short anecdotes', 'blogcraft-ai-writer' ),
			'question' => __( 'Rhetorical questions', 'blogcraft-ai-writer' ),
			'contrast' => __( 'Before-and-after contrast', 'blogcraft-ai-writer' ),
			'aside'    => __( 'Brief asides', 'blogcraft-ai-writer' ),
		);
	}

	/**
	 * Audiences offered as a starting point.
	 *
	 * @return array
	 */
	public static function audiences() {
		return array(
			''              => __( 'Not specified', 'blogcraft-ai-writer' ),
			'beginners'     => __( 'Beginners', 'blogcraft-ai-writer' ),
			'enthusiasts'   => __( 'Enthusiasts', 'blogcraft-ai-writer' ),
			'professionals' => __( 'Professionals in the field', 'blogcraft-ai-writer' ),
			'buyers'        => __( 'People deciding what to buy', 'blogcraft-ai-writer' ),
			'owners'        => __( 'Small business owners', 'blogcraft-ai-writer' ),
			'developers'    => __( 'Developers', 'blogcraft-ai-writer' ),
			'students'      => __( 'Students', 'blogcraft-ai-writer' ),
			'custom'        => __( 'Someone else — I will describe them', 'blogcraft-ai-writer' ),
		);
	}

	/**
	 * Every field, with its default and type.
	 *
	 * Types: bool, int, float, string, text, list, choice, multi.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			// Voice.
			'label'                 => array( 'string', __( 'Default', 'blogcraft-ai-writer' ) ),
			'tone'                  => array( 'choice', 'conversational' ),
			'tone_custom'           => array( 'text', '' ),
			'point_of_view'         => array( 'choice', 'second' ),
			'audience'              => array( 'choice', '' ),
			'audience_custom'       => array( 'text', '' ),
			'formality'             => array( 'int', 3 ),
			'reading_level'         => array( 'choice', 'general' ),
			'language'              => array( 'string', '' ),
			'locale_spelling'       => array( 'choice', 'us' ),
			'brand_terms'           => array( 'list', '' ),
			'banned_phrases'        => array( 'list', '' ),
			'negative_keywords'     => array( 'list', '' ),
			'avoid_subjects'        => array( 'list', '' ),

			// Structure.
			'word_target'           => array( 'int', 1200 ),
			'word_tolerance'        => array( 'int', 20 ),
			'sections_min'          => array( 'int', 4 ),
			'sections_max'          => array( 'int', 7 ),
			'allow_h3'              => array( 'bool', true ),
			'para_max_sentences'    => array( 'int', 4 ),
			'sentence_max_words'    => array( 'int', 28 ),
			'intro_style'           => array( 'choice', 'direct' ),
			'conclusion_style'      => array( 'choice', 'summary' ),
			'takeaways'             => array( 'bool', true ),
			'takeaways_count'       => array( 'int', 4 ),
			'faq'                   => array( 'bool', true ),
			'faq_count'             => array( 'int', 4 ),
			'tables'                => array( 'bool', true ),
			'lists'                 => array( 'bool', true ),
			'bold_key_phrases'      => array( 'bool', true ),
			'toc'                   => array( 'bool', false ),
			'block_audience'        => array( 'bool', false ),
			'block_proscons'        => array( 'bool', false ),
			'block_figures'         => array( 'bool', false ),
			'block_mistakes'        => array( 'bool', false ),
			// On by default, unlike the other block_* extras: nothing lets the
			// model invent a citation link (see Blocks::sources()), so this is
			// the only honest way the external-links check can ever pass.
			'block_sources'         => array( 'bool', true ),

			// SEO.
			'primary_keyword'       => array( 'string', '' ),
			'secondary_keywords'    => array( 'list', '' ),
			'density_min'           => array( 'float', 0.5 ),
			'density_max'           => array( 'float', 2.0 ),
			'required_terms'        => array( 'list', '' ),
			'auto_terms'            => array( 'bool', true ),
			'meta_title_max'        => array( 'int', 60 ),
			'meta_desc_max'         => array( 'int', 155 ),
			'internal_links_target' => array( 'int', 3 ),
			'external_links_target' => array( 'int', 2 ),
			'images_target'         => array( 'int', 1 ),

			// How generated pictures should look.
			'image_describe'        => array( 'bool', true ),
			'image_style'           => array( 'choice', 'editorial' ),
			'image_mood'            => array( 'choice', '' ),
			'image_subject'         => array( 'choice', 'object' ),
			'image_shape'           => array( 'choice', '16:9' ),
			'image_palette'         => array( 'string', '' ),
			'image_extra'           => array( 'text', '' ),
			'image_avoid'           => array( 'text', '' ),
			'image_allow_text'      => array( 'bool', false ),

			// Authenticity.
			'literary_devices'      => array( 'multi', 'example,analogy' ),
			'allow_contractions'    => array( 'bool', true ),
			'allow_em_dash'         => array( 'bool', false ),
			'require_experience'    => array( 'bool', false ),
			'require_citations'     => array( 'bool', true ),
			'require_statistics'    => array( 'bool', false ),
			'sentence_variety'      => array( 'bool', true ),

			// Per-section instructions.
			'prompt_intro'          => array( 'text', '' ),
			'prompt_section'        => array( 'text', '' ),
			'prompt_conclusion'     => array( 'text', '' ),
			'prompt_faq'            => array( 'text', '' ),
		);
	}

	/**
	 * A blueprint with every field at its default.
	 *
	 * @return array
	 */
	public static function defaults() {
		$out = array();

		foreach ( self::fields() as $key => $spec ) {
			$out[ $key ] = $spec[1];
		}

		return $out;
	}

	/**
	 * Coerce one value to its declared type.
	 *
	 * @param string $type  Field type.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function cast( $type, $value ) {
		switch ( $type ) {
			case 'bool':
				return (bool) $value;
			case 'int':
				return (int) $value;
			case 'float':
				return round( (float) $value, 2 );
			case 'text':
			case 'list':
				return sanitize_textarea_field( (string) $value );
			case 'multi':
				if ( is_array( $value ) ) {
					$value = implode( ',', $value );
				}

				return sanitize_text_field( (string) $value );
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Fill in anything missing and coerce every field to its type.
	 *
	 * @param array $raw Partial blueprint.
	 * @return array
	 */
	public static function normalise( $raw ) {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array();

		foreach ( self::fields() as $key => $spec ) {
			$out[ $key ] = array_key_exists( $key, $raw )
				? self::cast( $spec[0], $raw[ $key ] )
				: $spec[1];
		}

		// A minimum above a maximum would silently make every check impossible.
		if ( $out['sections_min'] > $out['sections_max'] ) {
			$out['sections_max'] = $out['sections_min'];
		}

		if ( $out['density_min'] > $out['density_max'] ) {
			$out['density_max'] = $out['density_min'];
		}

		$out['word_target']    = max( 200, $out['word_target'] );
		$out['word_tolerance'] = max( 5, min( 60, $out['word_tolerance'] ) );
		$out['formality']      = max( 1, min( 5, $out['formality'] ) );

		return $out;
	}

	/**
	 * Whether the writing rules have ever been changed from the shipped ones.
	 *
	 * Not "does the option exist": activation writes one, so its presence
	 * proves nothing. This compares the active blueprint against the
	 * defaults, which is the only thing that answers "has anybody actually
	 * decided how this blog should read".
	 *
	 * @return bool
	 */
	public static function was_edited() {
		$active   = self::get();
		$defaults = self::defaults();

		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $key, $active ) ) {
				continue;
			}

			// Loose on purpose: these round-trip through form posts and the
			// options table, so a 1 comes back as '1' and an int as a string.
			// A strict comparison would report every install as edited.
			if ( is_scalar( $value ) && is_scalar( $active[ $key ] ) && (string) $value !== (string) $active[ $key ] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every stored blueprint, keyed by slug.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		if ( ! isset( $stored[ self::DEFAULT_SLUG ] ) ) {
			$stored[ self::DEFAULT_SLUG ] = self::defaults();
		}

		foreach ( $stored as $slug => $blueprint ) {
			$stored[ $slug ] = self::normalise( $blueprint );
		}

		return $stored;
	}

	/**
	 * One blueprint by slug, falling back to the default.
	 *
	 * @param string $slug Blueprint slug.
	 * @return array
	 */
	public static function get( $slug = '' ) {
		$all  = self::all();
		$slug = ( '' === $slug ) ? self::active_slug() : sanitize_key( $slug );

		return isset( $all[ $slug ] ) ? $all[ $slug ] : $all[ self::DEFAULT_SLUG ];
	}

	/**
	 * Slug of the blueprint new posts use.
	 *
	 * @return string
	 */
	public static function active_slug() {
		$slug = sanitize_key( (string) get_option( 'blogcraft_active_blueprint', self::DEFAULT_SLUG ) );
		$all  = self::all();

		return isset( $all[ $slug ] ) ? $slug : self::DEFAULT_SLUG;
	}

	/**
	 * Store one blueprint.
	 *
	 * @param string $slug      Blueprint slug.
	 * @param array  $blueprint Field values.
	 * @return void
	 */
	public static function save( $slug, $blueprint ) {
		$all          = self::all();
		$slug         = sanitize_key( $slug );
		$slug         = ( '' === $slug ) ? self::DEFAULT_SLUG : $slug;
		$all[ $slug ] = self::normalise( $blueprint );

		update_option( self::OPTION, $all, false );
	}

	/**
	 * Fields where the composer offers a slider rather than a text box.
	 *
	 * A slider always submits a real number — it cannot be left blank the way
	 * a text field can — so its far-left end, zero, is a deliberate choice
	 * ("no pictures this time", "nothing to cite this time") and must reach
	 * the blueprint rather than being read as "the field was empty."
	 *
	 * @return array
	 */
	private static function zero_is_meaningful_for() {
		return array( 'images_target', 'external_links_target' );
	}

	/**
	 * Apply per-post overrides on top of a blueprint.
	 *
	 * Empty strings and zeroes mean "not overridden" rather than "set to
	 * nothing", because a blank override field on the write screen is the
	 * normal state and must not wipe a considered default. The exception is
	 * the composer's sliders (see zero_is_meaningful_for()): those can't be
	 * blank, so their zero is not the "normal state", it's a choice.
	 *
	 * @param array $blueprint Base blueprint.
	 * @param array $overrides Sparse field values.
	 * @return array
	 */
	public static function with_overrides( $blueprint, $overrides ) {
		if ( ! is_array( $overrides ) ) {
			return $blueprint;
		}

		$fields  = self::fields();
		$sliders = self::zero_is_meaningful_for();

		foreach ( $overrides as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}

			if ( '' === $value || null === $value ) {
				continue;
			}

			$is_zero = in_array( $fields[ $key ][0], array( 'int', 'float' ), true ) && 0 === (int) $value;

			if ( $is_zero && ! in_array( $key, $sliders, true ) ) {
				continue;
			}

			$blueprint[ $key ] = self::cast( $fields[ $key ][0], $value );
		}

		return self::normalise( $blueprint );
	}

	/**
	 * The Flesch Reading Ease band a blueprint asks for.
	 *
	 * @param array $blueprint Blueprint.
	 * @return array array( min, max ).
	 */
	public static function reading_band( $blueprint ) {
		$levels = self::reading_levels();
		$key    = isset( $blueprint['reading_level'] ) ? (string) $blueprint['reading_level'] : 'general';

		if ( ! isset( $levels[ $key ] ) ) {
			$key = 'general';
		}

		return array( $levels[ $key ][1], $levels[ $key ][2] );
	}

	/**
	 * A blueprint field that holds a list, as an array.
	 *
	 * @param array  $blueprint Blueprint.
	 * @param string $key       Field name.
	 * @return array
	 */
	public static function list_of( $blueprint, $key ) {
		return Blogcraft_Voice::to_list( isset( $blueprint[ $key ] ) ? $blueprint[ $key ] : '' );
	}

	/**
	 * A multi-select field as an array of chosen values.
	 *
	 * @param array  $blueprint Blueprint.
	 * @param string $key       Field name.
	 * @return array
	 */
	public static function chosen( $blueprint, $key ) {
		$raw = isset( $blueprint[ $key ] ) ? (string) $blueprint[ $key ] : '';
		$out = array();

		foreach ( explode( ',', $raw ) as $piece ) {
			$piece = sanitize_key( trim( $piece ) );

			if ( '' !== $piece ) {
				$out[] = $piece;
			}
		}

		return $out;
	}

	/**
	 * Render the voice and authenticity rules as prompt text.
	 *
	 * Only fields that are actually set produce a line. A prompt padded with
	 * "audience: not specified" spends tokens telling the model nothing and
	 * dilutes the rules that do matter.
	 *
	 * @param array $blueprint Blueprint.
	 * @return string
	 */
	public static function voice_rules( $blueprint ) {
		$lines = array();

		$tones = self::tones();
		$tone  = (string) $blueprint['tone'];

		if ( 'custom' === $tone ) {
			$custom = trim( (string) $blueprint['tone_custom'] );

			if ( '' !== $custom ) {
				$lines[] = 'Tone: ' . $custom;
			}
		} elseif ( isset( $tones[ $tone ] ) ) {
			$lines[] = 'Tone: ' . $tones[ $tone ];
		}

		$povs = array(
			'second'       => 'Address the reader directly as "you". Never say "we".',
			'first_plural' => 'Write as "we", speaking for the publication.',
			'first_person' => 'Write in the first person singular, in one voice.',
			'third'        => 'Write in the third person. Do not address the reader as "you".',
		);

		$pov = (string) $blueprint['point_of_view'];

		if ( isset( $povs[ $pov ] ) ) {
			$lines[] = $povs[ $pov ];
		}

		$audience  = (string) $blueprint['audience'];
		$audiences = self::audiences();

		if ( 'custom' === $audience ) {
			$custom = trim( (string) $blueprint['audience_custom'] );

			if ( '' !== $custom ) {
				$lines[] = 'You are writing for: ' . $custom;
			}
		} elseif ( '' !== $audience && isset( $audiences[ $audience ] ) ) {
			$lines[] = 'You are writing for: ' . $audiences[ $audience ];
		}

		$formality = (int) $blueprint['formality'];

		if ( $formality <= 2 ) {
			$lines[] = 'Keep it informal. Contractions, short words, no corporate register.';
		} elseif ( $formality >= 4 ) {
			$lines[] = 'Keep it formal. Avoid slang and casual asides.';
		}

		$levels = self::reading_levels();
		$level  = (string) $blueprint['reading_level'];

		if ( isset( $levels[ $level ] ) ) {
			list( $low, $high ) = self::reading_band( $blueprint );

			$lines[] = sprintf(
				'Reading level: %1$s. This is measured, so aim for a Flesch Reading Ease between %2$d and %3$d.',
				$levels[ $level ][0],
				$low,
				$high
			);
		}

		$language = trim( (string) $blueprint['language'] );

		if ( '' !== $language ) {
			$lines[] = 'Write in ' . $language . '.';
		}

		$lines[] = ( 'uk' === (string) $blueprint['locale_spelling'] )
			? 'Use British spelling throughout.'
			: 'Use American spelling throughout.';

		$brand = self::list_of( $blueprint, 'brand_terms' );

		if ( ! empty( $brand ) ) {
			$lines[] = 'Spell these exactly as written: ' . implode( '; ', $brand ) . '.';
		}

		$banned = self::list_of( $blueprint, 'banned_phrases' );

		if ( ! empty( $banned ) ) {
			$lines[] = 'Never use these words or phrases: ' . implode( '; ', $banned ) . '.';
		}

		$negative = self::list_of( $blueprint, 'negative_keywords' );

		if ( ! empty( $negative ) ) {
			$lines[] = 'These must not appear anywhere, in any form: ' . implode( '; ', $negative )
				. '. Do not name them, hint at them, or work around them.';
		}

		$avoid = self::list_of( $blueprint, 'avoid_subjects' );

		if ( ! empty( $avoid ) ) {
			$lines[] = 'Do not cover these subjects, even in passing: ' . implode( '; ', $avoid )
				. '. If the topic seems to require one, write around it.';
		}

		$devices = self::chosen( $blueprint, 'literary_devices' );
		$names   = self::literary_devices();
		$wanted  = array();

		foreach ( $devices as $device ) {
			if ( isset( $names[ $device ] ) ) {
				$wanted[] = strtolower( $names[ $device ] );
			}
		}

		if ( ! empty( $wanted ) ) {
			$lines[] = 'Use these where they genuinely help: ' . implode( ', ', $wanted ) . '.';
		}

		if ( ! (bool) $blueprint['allow_contractions'] ) {
			$lines[] = 'Do not use contractions.';
		}

		if ( ! (bool) $blueprint['allow_em_dash'] ) {
			$lines[] = 'Never use em dashes. Use a comma, a full stop, or rewrite the clause.';
		}

		if ( (bool) $blueprint['sentence_variety'] ) {
			$lines[] = 'Vary sentence length deliberately. Uniform sentence length is the clearest sign of machine writing.';
		}

		if ( (bool) $blueprint['require_experience'] ) {
			$lines[] = 'Include specific, first-hand detail: what actually happens, what goes wrong, what it costs.';
		}

		if ( (bool) $blueprint['require_citations'] ) {
			$lines[] = 'Attribute factual claims to a named source.';
		}

		if ( (bool) $blueprint['require_statistics'] ) {
			$lines[] = 'Include concrete figures where they exist, with their source.';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Render the structural and SEO rules as prompt text.
	 *
	 * Anything the scorer will later check says so here. A model told a limit
	 * is measured treats it differently from one given a suggestion.
	 *
	 * @param array $blueprint Blueprint.
	 * @return string
	 */
	public static function structure_rules( $blueprint ) {
		$lines = array();

		$target    = (int) $blueprint['word_target'];
		$tolerance = (int) $blueprint['word_tolerance'];

		$lines[] = sprintf(
			'Target length: about %1$d words. This is measured, and anything under %2$d or over %3$d is rejected.',
			$target,
			(int) round( $target * ( 1 - ( $tolerance / 100 ) ) ),
			(int) round( $target * ( 1 + ( $tolerance / 100 ) ) )
		);

		$lines[] = sprintf(
			'Use between %1$d and %2$d main sections.',
			(int) $blueprint['sections_min'],
			(int) $blueprint['sections_max']
		);

		if ( ! (bool) $blueprint['allow_h3'] ) {
			$lines[] = 'Do not use sub-subheadings.';
		}

		$lines[] = sprintf(
			'No paragraph longer than %1$d sentences. No sentence longer than %2$d words.',
			(int) $blueprint['para_max_sentences'],
			(int) $blueprint['sentence_max_words']
		);

		$intros = self::intro_styles();
		$intro  = (string) $blueprint['intro_style'];

		if ( isset( $intros[ $intro ] ) ) {
			$lines[] = 'Introduction: ' . $intros[ $intro ] . '.';
		}

		$closes = self::conclusion_styles();
		$close  = (string) $blueprint['conclusion_style'];

		if ( isset( $closes[ $close ] ) ) {
			$lines[] = 'Ending: ' . $closes[ $close ] . '.';
		}

		$lines[] = (bool) $blueprint['takeaways']
			? sprintf( 'Include %d key takeaways.', (int) $blueprint['takeaways_count'] )
			: 'No key takeaways section.';

		$lines[] = (bool) $blueprint['faq']
			? sprintf( 'Include %d frequently asked questions with short answers.', (int) $blueprint['faq_count'] )
			: 'No FAQ section.';

		if ( ! (bool) $blueprint['lists'] ) {
			$lines[] = 'Do not use bulleted or numbered lists.';
		}

		if ( ! (bool) $blueprint['tables'] ) {
			$lines[] = 'Do not use tables.';
		}

		if ( (bool) $blueprint['bold_key_phrases'] ) {
			$lines[] = 'Bold the few phrases that carry the most meaning. Never bold whole sentences.';
		}

		$keyword = trim( (string) $blueprint['primary_keyword'] );

		if ( '' !== $keyword ) {
			$lines[] = sprintf(
				'Target phrase "%1$s". It must appear naturally between %2$.1f and %3$.1f percent of the time, including once in a heading. Do not force it.',
				$keyword,
				(float) $blueprint['density_min'],
				(float) $blueprint['density_max']
			);
		}

		$secondary = self::list_of( $blueprint, 'secondary_keywords' );

		if ( ! empty( $secondary ) ) {
			$lines[] = 'Also cover: ' . implode( '; ', $secondary ) . '.';
		}

		$required = self::list_of( $blueprint, 'required_terms' );

		if ( ! empty( $required ) ) {
			$lines[] = 'Each of these terms must appear at least once: ' . implode( '; ', $required ) . '.';
		}

		$external = (int) $blueprint['external_links_target'];

		if ( $external > 0 ) {
			$lines[] = sprintf( 'Link out to at least %d reputable sources.', $external );
		}

		$sections = array(
			'prompt_intro'      => 'For the introduction specifically: ',
			'prompt_section'    => 'For every main section: ',
			'prompt_conclusion' => 'For the ending: ',
			'prompt_faq'        => 'For the FAQ: ',
		);

		foreach ( $sections as $key => $prefix ) {
			$value = trim( (string) $blueprint[ $key ] );

			if ( '' !== $value ) {
				$lines[] = $prefix . $value;
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Carry the old voice settings into the default blueprint, once.
	 *
	 * Someone upgrading has already described their blog in the voice fields.
	 * Making them retype that into a new screen would be the wrong greeting.
	 *
	 * @return void
	 */
	public static function migrate_from_voice() {
		if ( get_option( 'blogcraft_blueprints_migrated' ) ) {
			return;
		}

		$blueprint = self::defaults();

		$tone = trim( (string) Blogcraft_Settings::get( 'voice_tone' ) );

		if ( '' !== $tone ) {
			$blueprint['tone']        = 'custom';
			$blueprint['tone_custom'] = $tone;
		}

		$audience = trim( (string) Blogcraft_Settings::get( 'voice_audience' ) );

		if ( '' !== $audience ) {
			$blueprint['audience']        = 'custom';
			$blueprint['audience_custom'] = $audience;
		}

		$banned = trim( (string) Blogcraft_Settings::get( 'voice_banned_words' ) );

		if ( '' !== $banned ) {
			$blueprint['banned_phrases'] = $banned;
		}

		self::save( self::DEFAULT_SLUG, $blueprint );
		update_option( 'blogcraft_blueprints_migrated', 1, false );
	}
}
