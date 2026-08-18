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
			'conversational' => __( 'Conversational', 'blogcraft' ),
			'professional'   => __( 'Professional', 'blogcraft' ),
			'friendly'       => __( 'Friendly', 'blogcraft' ),
			'authoritative'  => __( 'Authoritative', 'blogcraft' ),
			'plain'          => __( 'Plain and direct', 'blogcraft' ),
			'witty'          => __( 'Witty', 'blogcraft' ),
			'empathetic'     => __( 'Empathetic', 'blogcraft' ),
			'journalistic'   => __( 'Journalistic', 'blogcraft' ),
			'academic'       => __( 'Academic', 'blogcraft' ),
			'enthusiastic'   => __( 'Enthusiastic', 'blogcraft' ),
			'custom'         => __( 'Something else — I will describe it', 'blogcraft' ),
		);
	}

	/**
	 * Narrative points of view.
	 *
	 * @return array
	 */
	public static function points_of_view() {
		return array(
			'second'       => __( 'Second person — you', 'blogcraft' ),
			'first_plural' => __( 'First person plural — we', 'blogcraft' ),
			'first_person' => __( 'First person — I', 'blogcraft' ),
			'third'        => __( 'Third person — they', 'blogcraft' ),
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
			'simple'   => array( __( 'Simple — anyone can follow it', 'blogcraft' ), 70, 100 ),
			'general'  => array( __( 'General — a wide audience', 'blogcraft' ), 60, 80 ),
			'informed' => array( __( 'Informed — familiar with the subject', 'blogcraft' ), 45, 65 ),
			'expert'   => array( __( 'Expert — assumes the vocabulary', 'blogcraft' ), 25, 55 ),
		);
	}

	/**
	 * How an article should open.
	 *
	 * @return array
	 */
	public static function intro_styles() {
		return array(
			'direct'    => __( 'Answer the question immediately', 'blogcraft' ),
			'hook'      => __( 'Open with a hook', 'blogcraft' ),
			'problem'   => __( 'Name the problem the reader has', 'blogcraft' ),
			'statistic' => __( 'Open with a figure', 'blogcraft' ),
			'story'     => __( 'Open with a short anecdote', 'blogcraft' ),
		);
	}

	/**
	 * How an article should close.
	 *
	 * @return array
	 */
	public static function conclusion_styles() {
		return array(
			'summary'    => __( 'Summarise the main points', 'blogcraft' ),
			'next_steps' => __( 'Give the reader next steps', 'blogcraft' ),
			'action'     => __( 'End on a call to action', 'blogcraft' ),
			'none'       => __( 'No conclusion section', 'blogcraft' ),
		);
	}

	/**
	 * Devices that make writing read as though a person wrote it.
	 *
	 * @return array
	 */
	public static function literary_devices() {
		return array(
			'analogy'  => __( 'Analogies', 'blogcraft' ),
			'example'  => __( 'Concrete examples', 'blogcraft' ),
			'anecdote' => __( 'Short anecdotes', 'blogcraft' ),
			'question' => __( 'Rhetorical questions', 'blogcraft' ),
			'contrast' => __( 'Before-and-after contrast', 'blogcraft' ),
			'aside'    => __( 'Brief asides', 'blogcraft' ),
		);
	}

	/**
	 * Audiences offered as a starting point.
	 *
	 * @return array
	 */
	public static function audiences() {
		return array(
			''              => __( 'Not specified', 'blogcraft' ),
			'beginners'     => __( 'Beginners', 'blogcraft' ),
			'enthusiasts'   => __( 'Enthusiasts', 'blogcraft' ),
			'professionals' => __( 'Professionals in the field', 'blogcraft' ),
			'buyers'        => __( 'People deciding what to buy', 'blogcraft' ),
			'owners'        => __( 'Small business owners', 'blogcraft' ),
			'developers'    => __( 'Developers', 'blogcraft' ),
			'students'      => __( 'Students', 'blogcraft' ),
			'custom'        => __( 'Someone else — I will describe them', 'blogcraft' ),
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
			'label'                 => array( 'string', __( 'Default', 'blogcraft' ) ),
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

			// SEO.
			'primary_keyword'       => array( 'string', '' ),
			'secondary_keywords'    => array( 'list', '' ),
			'density_min'           => array( 'float', 0.5 ),
			'density_max'           => array( 'float', 2.0 ),
			'required_terms'        => array( 'list', '' ),
			'meta_title_max'        => array( 'int', 60 ),
			'meta_desc_max'         => array( 'int', 155 ),
			'internal_links_target' => array( 'int', 3 ),
			'external_links_target' => array( 'int', 2 ),
			'images_target'         => array( 'int', 1 ),

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
	 * Apply per-post overrides on top of a blueprint.
	 *
	 * Empty strings and zeroes mean "not overridden" rather than "set to
	 * nothing", because a blank override field on the write screen is the
	 * normal state and must not wipe a considered default.
	 *
	 * @param array $blueprint Base blueprint.
	 * @param array $overrides Sparse field values.
	 * @return array
	 */
	public static function with_overrides( $blueprint, $overrides ) {
		if ( ! is_array( $overrides ) ) {
			return $blueprint;
		}

		$fields = self::fields();

		foreach ( $overrides as $key => $value ) {
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}

			if ( '' === $value || null === $value ) {
				continue;
			}

			if ( in_array( $fields[ $key ][0], array( 'int', 'float' ), true ) && 0 === (int) $value ) {
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
