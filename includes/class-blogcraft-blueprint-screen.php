<?php
/**
 * The blueprint editor.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Screen for editing the rules a post is written to.
 *
 * Treated as an editorial brief rather than a settings page, because that is
 * what it is: someone specifying how a writer they will never meet should
 * sound. The panel on the right renders the actual instructions the model will
 * receive and updates as controls change, so forty-eight abstract fields stay
 * legible and nobody has to trust that a toggle does something.
 *
 * Anything the scorer measures is set in monospace. That is not decoration —
 * it separates what a person is asking for from what a machine will check.
 */
class Blogcraft_Blueprint_Screen {

	/**
	 * Submenu slug.
	 */
	const PAGE_SLUG = 'blogcraft-blueprint';

	/**
	 * Nonce action for saving.
	 */
	const SAVE_ACTION = 'blogcraft_save_blueprint';

	/**
	 * Transient prefix for one-shot notices.
	 */
	const NOTICE_TRANSIENT = 'blogcraft_blueprint_notice_';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 16 );
		add_action( 'admin_post_blogcraft_save_blueprint', array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_ajax_blogcraft_preview_brief', array( __CLASS__, 'handle_preview' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Load this screen's own styling and behaviour, and nowhere else.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'blogcraft-blueprint',
			BLOGCRAFT_URL . 'assets/blueprint.css',
			array(),
			BLOGCRAFT_VERSION
		);

		wp_enqueue_script(
			'blogcraft-blueprint',
			BLOGCRAFT_URL . 'assets/blueprint.js',
			array(),
			BLOGCRAFT_VERSION,
			true
		);

		wp_localize_script(
			'blogcraft-blueprint',
			'blogcraftBlueprint',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'failed'  => __( 'The brief could not be refreshed. Save to see it applied.', 'blogcraft' ),
			)
		);
	}

	/**
	 * Add the submenu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			Blogcraft_Admin::MENU_SLUG,
			__( 'How it writes', 'blogcraft' ),
			__( 'How it writes', 'blogcraft' ),
			Blogcraft_Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The groups the controls are divided into.
	 *
	 * Not numbered: they can be edited in any order, so a sequence would be a
	 * lie about how the screen works.
	 *
	 * @return array
	 */
	public static function groups() {
		return array(
			'voice'     => __( 'Voice', 'blogcraft' ),
			'structure' => __( 'Structure', 'blogcraft' ),
			'seo'       => __( 'Search', 'blogcraft' ),
			'human'     => __( 'Sounding human', 'blogcraft' ),
			'sections'  => __( 'Section briefs', 'blogcraft' ),
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'blogcraft' ) );
		}

		$blueprint = Blogcraft_Blueprint::get();

		echo '<div class="wrap blogcraft-page blogcraft-blueprint">';

		Blogcraft_Nav::render();

		$notice = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );

		if ( is_array( $notice ) ) {
			delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
			printf(
				'<div class="notice %s"><p>%s</p></div>',
				esc_attr( empty( $notice['ok'] ) ? 'notice-error' : 'notice-success' ),
				esc_html( (string) $notice['message'] )
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="blogcraft-blueprint-form">';
		echo '<input type="hidden" name="action" value="blogcraft_save_blueprint" />';
		Blogcraft_Request::nonce_field( self::SAVE_ACTION );

		self::render_bar();

		echo '<div class="bc-shell">';
		self::render_rail();

		echo '<div class="bc-panes">';
		self::pane_voice( $blueprint );
		self::pane_structure( $blueprint );
		self::pane_seo( $blueprint );
		self::pane_human( $blueprint );
		self::pane_sections( $blueprint );
		echo '</div>';

		self::render_brief( $blueprint );
		echo '</div>';

		echo '</form>';
		echo '</div>';
	}

	/**
	 * Sticky heading and save control.
	 *
	 * @return void
	 */
	private static function render_bar() {
		echo '<div class="bc-bar">';
		echo '<div class="bc-bar-text">';
		echo '<h1>' . esc_html__( 'How it writes', 'blogcraft' ) . '</h1>';
		echo '<p>' . esc_html__( 'The brief every post is written to. Anything shown in monospace is measured on the finished draft, not merely requested.', 'blogcraft' ) . '</p>';
		echo '</div>';
		printf(
			'<button type="submit" class="bc-save">%s</button>',
			esc_html__( 'Save changes', 'blogcraft' )
		);
		echo '</div>';
	}

	/**
	 * Group navigation.
	 *
	 * @return void
	 */
	private static function render_rail() {
		echo '<nav class="bc-rail" aria-label="' . esc_attr__( 'Blueprint sections', 'blogcraft' ) . '">';

		$first = true;

		foreach ( self::groups() as $slug => $label ) {
			printf(
				'<button type="button" class="bc-rail-item%1$s" data-pane="%2$s" aria-current="%3$s">%4$s</button>',
				$first ? ' is-active' : '',
				esc_attr( $slug ),
				$first ? 'true' : 'false',
				esc_html( $label )
			);
			$first = false;
		}

		echo '</nav>';
	}

	/**
	 * The live brief panel.
	 *
	 * @param array $blueprint Current blueprint.
	 * @return void
	 */
	private static function render_brief( $blueprint ) {
		echo '<aside class="bc-brief" aria-labelledby="bc-brief-title">';
		echo '<div class="bc-brief-head">';
		echo '<h2 id="bc-brief-title">' . esc_html__( 'The brief', 'blogcraft' ) . '</h2>';
		echo '<p>' . esc_html__( 'Exactly what the model is told. Updates as you change anything above.', 'blogcraft' ) . '</p>';
		echo '</div>';

		printf(
			'<pre class="bc-brief-body" id="bc-brief-body" aria-live="polite">%s</pre>',
			esc_html( self::brief_text( $blueprint ) )
		);

		echo '</aside>';
	}

	/**
	 * Render the blueprint as the instruction text the model receives.
	 *
	 * @param array $blueprint Blueprint.
	 * @return string
	 */
	public static function brief_text( $blueprint ) {
		$voice     = Blogcraft_Blueprint::voice_rules( $blueprint );
		$structure = Blogcraft_Blueprint::structure_rules( $blueprint );

		return trim( $voice . "\n\n" . $structure );
	}

	/**
	 * Open one pane.
	 *
	 * @param string $slug   Group slug.
	 * @param bool   $active Whether it starts visible.
	 * @return void
	 */
	private static function pane_open( $slug, $active = false ) {
		printf(
			'<section class="bc-pane%1$s" data-pane="%2$s"%3$s>',
			$active ? ' is-active' : '',
			esc_attr( $slug ),
			$active ? '' : ' hidden'
		);
	}

	/**
	 * A field row.
	 *
	 * @param string $label   Field label.
	 * @param string $hint    Explanation beneath.
	 * @param string $control Rendered control markup.
	 * @param string $label_for Id the label points at, if any.
	 * @return void
	 */
	private static function row( $label, $hint, $control, $label_for = '' ) {
		echo '<div class="bc-row">';

		if ( '' === $label_for ) {
			printf( '<span class="bc-label">%s</span>', esc_html( $label ) );
		} else {
			printf( '<label class="bc-label" for="%1$s">%2$s</label>', esc_attr( $label_for ), esc_html( $label ) );
		}

		echo '<div class="bc-control">';
		echo $control; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped pieces by the helpers below.

		if ( '' !== $hint ) {
			printf( '<p class="bc-hint">%s</p>', esc_html( $hint ) );
		}

		echo '</div></div>';
	}

	/**
	 * A segmented choice control, for small option sets.
	 *
	 * @param string $name    Field name.
	 * @param array  $options Value => label.
	 * @param string $current Current value.
	 * @return string
	 */
	private static function segmented( $name, $options, $current ) {
		$out = '<div class="bc-seg" role="radiogroup">';

		foreach ( $options as $value => $label ) {
			$id   = 'bc_' . $name . '_' . sanitize_key( (string) $value );
			$out .= sprintf(
				'<input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s /><label for="%1$s">%5$s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				checked( (string) $current, (string) $value, false ),
				esc_html( $label )
			);
		}

		return $out . '</div>';
	}

	/**
	 * A dropdown, for larger option sets.
	 *
	 * @param string $name    Field name.
	 * @param array  $options Value => label.
	 * @param string $current Current value.
	 * @return string
	 */
	private static function select( $name, $options, $current ) {
		$out = sprintf( '<select class="bc-select" name="%1$s" id="bc_%1$s">', esc_attr( $name ) );

		foreach ( $options as $value => $label ) {
			$out .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $value ),
				selected( (string) $current, (string) $value, false ),
				esc_html( $label )
			);
		}

		return $out . '</select>';
	}

	/**
	 * A slider with a live read-out.
	 *
	 * @param string $name    Field name.
	 * @param int    $min     Minimum.
	 * @param int    $max     Maximum.
	 * @param int    $step    Step.
	 * @param mixed  $current Current value.
	 * @param string $unit    Suffix shown beside the value.
	 * @return string
	 */
	private static function slider( $name, $min, $max, $step, $current, $unit = '' ) {
		return sprintf(
			'<div class="bc-slider"><input type="range" id="bc_%1$s" name="%1$s" min="%2$s" max="%3$s" step="%4$s" value="%5$s" data-unit="%6$s" /><output class="bc-value" for="bc_%1$s">%5$s%6$s</output></div>',
			esc_attr( $name ),
			esc_attr( (string) $min ),
			esc_attr( (string) $max ),
			esc_attr( (string) $step ),
			esc_attr( (string) $current ),
			esc_attr( $unit )
		);
	}

	/**
	 * A toggle.
	 *
	 * @param string $name    Field name.
	 * @param bool   $current Whether it is on.
	 * @param string $label   Text beside the switch.
	 * @return string
	 */
	private static function toggle( $name, $current, $label ) {
		return sprintf(
			'<label class="bc-toggle"><input type="checkbox" name="%1$s" id="bc_%1$s" value="1"%2$s /><span class="bc-track" aria-hidden="true"></span><span class="bc-toggle-text">%3$s</span></label>',
			esc_attr( $name ),
			checked( (bool) $current, true, false ),
			esc_html( $label )
		);
	}

	/**
	 * A chip multi-select.
	 *
	 * @param string $name    Field name.
	 * @param array  $options Value => label.
	 * @param array  $chosen  Chosen values.
	 * @return string
	 */
	private static function chips( $name, $options, $chosen ) {
		$out = '<div class="bc-chips">';

		foreach ( $options as $value => $label ) {
			$id   = 'bc_' . $name . '_' . sanitize_key( (string) $value );
			$out .= sprintf(
				'<input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s"%4$s /><label for="%1$s">%5$s</label>',
				esc_attr( $id ),
				esc_attr( $name ),
				esc_attr( (string) $value ),
				checked( in_array( (string) $value, $chosen, true ), true, false ),
				esc_html( $label )
			);
		}

		return $out . '</div>';
	}

	/**
	 * A single-line text field.
	 *
	 * @param string $name        Field name.
	 * @param string $current     Current value.
	 * @param string $placeholder Placeholder text.
	 * @return string
	 */
	private static function text( $name, $current, $placeholder = '' ) {
		return sprintf(
			'<input type="text" class="bc-text" name="%1$s" id="bc_%1$s" value="%2$s" placeholder="%3$s" autocomplete="off" />',
			esc_attr( $name ),
			esc_attr( (string) $current ),
			esc_attr( $placeholder )
		);
	}

	/**
	 * A multi-line field.
	 *
	 * @param string $name        Field name.
	 * @param string $current     Current value.
	 * @param string $placeholder Placeholder text.
	 * @param int    $rows        Row count.
	 * @return string
	 */
	private static function area( $name, $current, $placeholder = '', $rows = 3 ) {
		return sprintf(
			'<textarea class="bc-area" name="%1$s" id="bc_%1$s" rows="%4$d" placeholder="%3$s">%2$s</textarea>',
			esc_attr( $name ),
			esc_textarea( (string) $current ),
			esc_attr( $placeholder ),
			(int) $rows
		);
	}

	/**
	 * Voice controls.
	 *
	 * @param array $bp Blueprint.
	 * @return void
	 */
	private static function pane_voice( $bp ) {
		self::pane_open( 'voice', true );

		self::row(
			__( 'Tone', 'blogcraft' ),
			__( 'Pick the closest, or describe your own.', 'blogcraft' ),
			self::select( 'tone', Blogcraft_Blueprint::tones(), $bp['tone'] ),
			'bc_tone'
		);

		self::row(
			__( 'Describe the tone', 'blogcraft' ),
			__( 'Used only when the tone above is set to something else.', 'blogcraft' ),
			self::text( 'tone_custom', $bp['tone_custom'], __( 'Dry, a little sceptical, never breathless', 'blogcraft' ) ),
			'bc_tone_custom'
		);

		self::row(
			__( 'Who is speaking', 'blogcraft' ),
			'',
			self::segmented( 'point_of_view', Blogcraft_Blueprint::points_of_view(), $bp['point_of_view'] )
		);

		self::row(
			__( 'Who is reading', 'blogcraft' ),
			'',
			self::select( 'audience', Blogcraft_Blueprint::audiences(), $bp['audience'] ),
			'bc_audience'
		);

		self::row(
			__( 'Describe the reader', 'blogcraft' ),
			__( 'What they already know, and what they are trying to do.', 'blogcraft' ),
			self::text( 'audience_custom', $bp['audience_custom'], __( 'People setting up a first home office on a budget', 'blogcraft' ) ),
			'bc_audience_custom'
		);

		self::row(
			__( 'Formality', 'blogcraft' ),
			__( '1 is a message to a friend. 5 is a white paper.', 'blogcraft' ),
			self::slider( 'formality', 1, 5, 1, $bp['formality'] ),
			'bc_formality'
		);

		self::row(
			__( 'Reading level', 'blogcraft' ),
			__( 'Measured on the finished draft as a Flesch Reading Ease band.', 'blogcraft' ),
			self::select( 'reading_level', self::level_labels(), $bp['reading_level'] ),
			'bc_reading_level'
		);

		self::row(
			__( 'Spelling', 'blogcraft' ),
			'',
			self::segmented(
				'locale_spelling',
				array(
					'us' => __( 'American', 'blogcraft' ),
					'uk' => __( 'British', 'blogcraft' ),
				),
				$bp['locale_spelling']
			)
		);

		self::row(
			__( 'Language', 'blogcraft' ),
			__( 'Leave blank to write in English.', 'blogcraft' ),
			self::text( 'language', $bp['language'], __( 'Spanish', 'blogcraft' ) ),
			'bc_language'
		);

		self::row(
			__( 'Spell these exactly', 'blogcraft' ),
			__( 'One per line. Product names, your brand, anything that gets mangled.', 'blogcraft' ),
			self::area( 'brand_terms', $bp['brand_terms'], "Dicecodes\nBlogcraft" ),
			'bc_brand_terms'
		);

		echo '</section>';
	}

	/**
	 * Reading level labels without the bands, for the dropdown.
	 *
	 * @return array
	 */
	private static function level_labels() {
		$out = array();

		foreach ( Blogcraft_Blueprint::reading_levels() as $key => $spec ) {
			$out[ $key ] = $spec[0];
		}

		return $out;
	}

	/**
	 * Structure controls.
	 *
	 * @param array $bp Blueprint.
	 * @return void
	 */
	private static function pane_structure( $bp ) {
		self::pane_open( 'structure' );

		self::row(
			__( 'Length', 'blogcraft' ),
			__( 'Measured. A draft outside the tolerance below is sent back to be rewritten.', 'blogcraft' ),
			self::slider( 'word_target', 300, 4000, 50, $bp['word_target'], __( ' words', 'blogcraft' ) ),
			'bc_word_target'
		);

		self::row(
			__( 'Tolerance', 'blogcraft' ),
			__( 'How far either side of the target is acceptable.', 'blogcraft' ),
			self::slider( 'word_tolerance', 5, 60, 5, $bp['word_tolerance'], '%' ),
			'bc_word_tolerance'
		);

		self::row(
			__( 'Fewest sections', 'blogcraft' ),
			'',
			self::slider( 'sections_min', 1, 12, 1, $bp['sections_min'] ),
			'bc_sections_min'
		);

		self::row(
			__( 'Most sections', 'blogcraft' ),
			__( 'Measured on the finished draft.', 'blogcraft' ),
			self::slider( 'sections_max', 1, 15, 1, $bp['sections_max'] ),
			'bc_sections_max'
		);

		self::row(
			__( 'Longest sentence', 'blogcraft' ),
			__( 'Measured. Every sentence over this is reported back for splitting.', 'blogcraft' ),
			self::slider( 'sentence_max_words', 12, 50, 1, $bp['sentence_max_words'], __( ' words', 'blogcraft' ) ),
			'bc_sentence_max_words'
		);

		self::row(
			__( 'Longest paragraph', 'blogcraft' ),
			__( 'Measured.', 'blogcraft' ),
			self::slider( 'para_max_sentences', 1, 8, 1, $bp['para_max_sentences'], __( ' sentences', 'blogcraft' ) ),
			'bc_para_max_sentences'
		);

		self::row(
			__( 'How it opens', 'blogcraft' ),
			'',
			self::select( 'intro_style', Blogcraft_Blueprint::intro_styles(), $bp['intro_style'] ),
			'bc_intro_style'
		);

		self::row(
			__( 'How it ends', 'blogcraft' ),
			'',
			self::select( 'conclusion_style', Blogcraft_Blueprint::conclusion_styles(), $bp['conclusion_style'] ),
			'bc_conclusion_style'
		);

		self::row(
			__( 'Include', 'blogcraft' ),
			'',
			self::toggle( 'takeaways', $bp['takeaways'], __( 'Key takeaways', 'blogcraft' ) )
			. self::toggle( 'faq', $bp['faq'], __( 'Questions and answers', 'blogcraft' ) )
			. self::toggle( 'toc', $bp['toc'], __( 'Table of contents', 'blogcraft' ) )
		);

		self::row(
			__( 'Allow', 'blogcraft' ),
			'',
			self::toggle( 'lists', $bp['lists'], __( 'Bulleted lists', 'blogcraft' ) )
			. self::toggle( 'tables', $bp['tables'], __( 'Tables', 'blogcraft' ) )
			. self::toggle( 'allow_h3', $bp['allow_h3'], __( 'Sub-subheadings', 'blogcraft' ) )
			. self::toggle( 'bold_key_phrases', $bp['bold_key_phrases'], __( 'Bold key phrases', 'blogcraft' ) )
		);

		self::row(
			__( 'How many takeaways', 'blogcraft' ),
			'',
			self::slider( 'takeaways_count', 2, 10, 1, $bp['takeaways_count'] ),
			'bc_takeaways_count'
		);

		self::row(
			__( 'How many questions', 'blogcraft' ),
			'',
			self::slider( 'faq_count', 2, 12, 1, $bp['faq_count'] ),
			'bc_faq_count'
		);

		echo '</section>';
	}

	/**
	 * Search controls.
	 *
	 * @param array $bp Blueprint.
	 * @return void
	 */
	private static function pane_seo( $bp ) {
		self::pane_open( 'seo' );

		self::row(
			__( 'Target phrase', 'blogcraft' ),
			__( 'Leave blank to let each topic speak for itself. Overridden per post.', 'blogcraft' ),
			self::text( 'primary_keyword', $bp['primary_keyword'], __( 'standing desk', 'blogcraft' ) ),
			'bc_primary_keyword'
		);

		self::row(
			__( 'Least often', 'blogcraft' ),
			__( 'Measured as a share of all words.', 'blogcraft' ),
			self::slider( 'density_min', 0, 3, 0.1, $bp['density_min'], '%' ),
			'bc_density_min'
		);

		self::row(
			__( 'Most often', 'blogcraft' ),
			__( 'Measured. Above this reads as keyword stuffing.', 'blogcraft' ),
			self::slider( 'density_max', 0.5, 6, 0.1, $bp['density_max'], '%' ),
			'bc_density_max'
		);

		self::row(
			__( 'Also cover', 'blogcraft' ),
			__( 'One per line. Related phrases worth a mention.', 'blogcraft' ),
			self::area( 'secondary_keywords', $bp['secondary_keywords'], "adjustable desk\nsit stand desk" ),
			'bc_secondary_keywords'
		);

		self::row(
			__( 'Work these out for me', 'blogcraft' ),
			__( 'When you name no terms of your own, take them from the pages already covering the subject. Costs nothing extra: it reads the research this post already gathered.', 'blogcraft' ),
			self::toggle( 'auto_terms', $bp['auto_terms'], __( 'Derive the terms from existing coverage', 'blogcraft' ) )
		);

		self::row(
			__( 'Must appear', 'blogcraft' ),
			__( 'One per line. Measured — every one of these is checked for, and a missing term is reported back.', 'blogcraft' ),
			self::area( 'required_terms', $bp['required_terms'], "ergonomics\nanti-fatigue mat" ),
			'bc_required_terms'
		);

		self::row(
			__( 'Sources to cite', 'blogcraft' ),
			__( 'Measured. Outbound links to reputable sources.', 'blogcraft' ),
			self::slider( 'external_links_target', 0, 10, 1, $bp['external_links_target'] ),
			'bc_external_links_target'
		);

		self::row(
			__( 'Links to your own posts', 'blogcraft' ),
			__( 'Added after writing, then counted.', 'blogcraft' ),
			self::slider( 'internal_links_target', 0, 10, 1, $bp['internal_links_target'] ),
			'bc_internal_links_target'
		);

		self::row(
			__( 'Title length', 'blogcraft' ),
			'',
			self::slider( 'meta_title_max', 40, 80, 1, $bp['meta_title_max'], __( ' characters', 'blogcraft' ) ),
			'bc_meta_title_max'
		);

		self::row(
			__( 'Description length', 'blogcraft' ),
			'',
			self::slider( 'meta_desc_max', 100, 200, 5, $bp['meta_desc_max'], __( ' characters', 'blogcraft' ) ),
			'bc_meta_desc_max'
		);

		echo '</section>';
	}

	/**
	 * Authenticity controls.
	 *
	 * @param array $bp Blueprint.
	 * @return void
	 */
	private static function pane_human( $bp ) {
		self::pane_open( 'human' );

		self::row(
			__( 'Devices to use', 'blogcraft' ),
			__( 'Where they genuinely help. Nothing here is forced into every section.', 'blogcraft' ),
			self::chips(
				'literary_devices',
				Blogcraft_Blueprint::literary_devices(),
				Blogcraft_Blueprint::chosen( $bp, 'literary_devices' )
			)
		);

		self::row(
			__( 'Habits', 'blogcraft' ),
			'',
			self::toggle( 'sentence_variety', $bp['sentence_variety'], __( 'Vary sentence length deliberately', 'blogcraft' ) )
			. self::toggle( 'allow_contractions', $bp['allow_contractions'], __( 'Allow contractions', 'blogcraft' ) )
			. self::toggle( 'allow_em_dash', $bp['allow_em_dash'], __( 'Allow em dashes', 'blogcraft' ) )
		);

		self::row(
			__( 'Demand', 'blogcraft' ),
			'',
			self::toggle( 'require_experience', $bp['require_experience'], __( 'First-hand, specific detail', 'blogcraft' ) )
			. self::toggle( 'require_citations', $bp['require_citations'], __( 'A named source for factual claims', 'blogcraft' ) )
			. self::toggle( 'require_statistics', $bp['require_statistics'], __( 'Concrete figures where they exist', 'blogcraft' ) )
		);

		self::row(
			__( 'Never write', 'blogcraft' ),
			__( 'One per line. Measured — any of these found in a draft sends it back.', 'blogcraft' ),
			self::area( 'banned_phrases', $bp['banned_phrases'], "in today's fast-paced world\ndelve into\nunlock the power of", 5 ),
			'bc_banned_phrases'
		);

		self::row(
			__( 'Never mention', 'blogcraft' ),
			__( 'One per line. Measured, and weighted heavily. For competitors, brands and claims that must never appear at all.', 'blogcraft' ),
			self::area( 'negative_keywords', $bp['negative_keywords'], "a competitor's name\nguaranteed results", 4 ),
			'bc_negative_keywords'
		);

		self::row(
			__( 'Steer clear of', 'blogcraft' ),
			__( 'One per line. Subjects to avoid even in passing. Sent to the model rather than measured, because a subject is not a single word.', 'blogcraft' ),
			self::area( 'avoid_subjects', $bp['avoid_subjects'], "medical advice\npolitics\nprice comparisons", 4 ),
			'bc_avoid_subjects'
		);

		echo '</section>';
	}

	/**
	 * Per-section instruction controls.
	 *
	 * @param array $bp Blueprint.
	 * @return void
	 */
	private static function pane_sections( $bp ) {
		self::pane_open( 'sections' );

		echo '<p class="bc-note">' . esc_html__( 'Instructions for one part of the article only. These are appended to the rules the model already has, so use them for the things that keep coming out wrong.', 'blogcraft' ) . '</p>';

		self::row(
			__( 'The opening', 'blogcraft' ),
			'',
			self::area( 'prompt_intro', $bp['prompt_intro'], __( 'Never open with a rhetorical question.', 'blogcraft' ) ),
			'bc_prompt_intro'
		);

		self::row(
			__( 'Every section', 'blogcraft' ),
			'',
			self::area( 'prompt_section', $bp['prompt_section'], __( 'Open each section with the answer, then explain it.', 'blogcraft' ) ),
			'bc_prompt_section'
		);

		self::row(
			__( 'The ending', 'blogcraft' ),
			'',
			self::area( 'prompt_conclusion', $bp['prompt_conclusion'], __( 'No summary of what was just said. End on what to do next.', 'blogcraft' ) ),
			'bc_prompt_conclusion'
		);

		self::row(
			__( 'The questions', 'blogcraft' ),
			'',
			self::area( 'prompt_faq', $bp['prompt_faq'], __( 'Answer in two sentences at most.', 'blogcraft' ) ),
			'bc_prompt_faq'
		);

		echo '</section>';
	}

	/**
	 * Read a submitted blueprint out of the request.
	 *
	 * @param array $source Raw request data, already unslashed.
	 * @return array
	 */
	private static function from_request( $source ) {
		$fields = Blogcraft_Blueprint::fields();
		$out    = array();

		foreach ( $fields as $key => $spec ) {
			if ( 'bool' === $spec[0] ) {
				// An unchecked box posts nothing, so absence is false.
				$out[ $key ] = isset( $source[ $key ] );
				continue;
			}

			if ( 'multi' === $spec[0] ) {
				$chosen = isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : array();
				$clean  = array();

				foreach ( $chosen as $value ) {
					$value = sanitize_key( (string) $value );

					if ( '' !== $value ) {
						$clean[] = $value;
					}
				}

				$out[ $key ] = implode( ',', $clean );
				continue;
			}

			if ( isset( $source[ $key ] ) && ! is_array( $source[ $key ] ) ) {
				$out[ $key ] = $source[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Save the blueprint.
	 *
	 * @return void
	 */
	public static function handle_save() {
		// Read then verify; Blogcraft_Request performs the check PHPCS cannot follow.
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::SAVE_ACTION, $nonce );

		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- every field is sanitised by type in from_request() and Blogcraft_Blueprint::normalise().

		$current = Blogcraft_Blueprint::get();
		$saved   = array_merge( $current, self::from_request( $raw ) );

		Blogcraft_Blueprint::save( Blogcraft_Blueprint::active_slug(), $saved );

		self::back( true, __( 'Saved. Every post from now on is written to this brief.', 'blogcraft' ) );
	}

	/**
	 * Return the brief for an unsaved blueprint, for the live panel.
	 *
	 * @return void
	 */
	public static function handle_preview() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'blogcraft' ) ), 403 );
		}

		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! Blogcraft_Request::verify( self::SAVE_ACTION, $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'That form has expired. Reload the page.', 'blogcraft' ) ), 403 );
		}

		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- every field is sanitised by type in from_request() and Blogcraft_Blueprint::normalise().

		$blueprint = Blogcraft_Blueprint::normalise(
			array_merge( Blogcraft_Blueprint::get(), self::from_request( $raw ) )
		);

		wp_send_json_success( array( 'brief' => self::brief_text( $blueprint ) ) );
	}

	/**
	 * Store a one-shot notice and return to the screen.
	 *
	 * @param bool   $ok      Whether it succeeded.
	 * @param string $message Message to show.
	 * @return void
	 */
	private static function back( $ok, $message ) {
		set_transient(
			self::NOTICE_TRANSIENT . get_current_user_id(),
			array(
				'ok'      => (bool) $ok,
				'message' => (string) $message,
			),
			60
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
