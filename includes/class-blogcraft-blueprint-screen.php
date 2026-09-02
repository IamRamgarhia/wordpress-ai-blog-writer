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
		add_action( 'wp_ajax_blogcraft_shape', array( __CLASS__, 'handle_shape' ) );
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

		// The base stylesheet was never loaded here, and this screen renders
		// the shared navigation — which is styled in it. So the nav bar on
		// the two screens people use most had no background, no border and
		// no current-tab highlight, while every other screen had all three.
		//
		// It is also where the colour palette is declared. blueprint.css
		// names its own shades but resolves them against that palette, so
		// loading it alone leaves every var() empty.
		wp_enqueue_style(
			'blogcraft-admin',
			BLOGCRAFT_URL . 'assets/admin.css',
			array(),
			BLOGCRAFT_VERSION
		);

		wp_enqueue_style(
			'blogcraft-blueprint',
			BLOGCRAFT_URL . 'assets/blueprint.css',
			array( 'blogcraft-admin' ),
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
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'learning'     => __( 'Reading your posts...', 'dicecodes-ai-blog-writer' ),
				'failed'       => __( 'The brief could not be refreshed. Save to see it applied.', 'dicecodes-ai-blog-writer' ),
				'shapeSaved'   => __( 'Filled in on the other tabs. Nothing is saved until you press Save changes.', 'dicecodes-ai-blog-writer' ),
				'shapeReading' => __( 'Reading the article...', 'dicecodes-ai-blog-writer' ),
				'shapeNoUrl'   => __( 'Paste the address of an article first.', 'dicecodes-ai-blog-writer' ),
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
			__( 'How it writes', 'dicecodes-ai-blog-writer' ),
			__( 'How it writes', 'dicecodes-ai-blog-writer' ),
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
			'start'     => __( 'Start from', 'dicecodes-ai-blog-writer' ),
			'voice'     => __( 'Voice', 'dicecodes-ai-blog-writer' ),
			'structure' => __( 'Structure', 'dicecodes-ai-blog-writer' ),
			'seo'       => __( 'Search', 'dicecodes-ai-blog-writer' ),
			'human'     => __( 'Sounding human', 'dicecodes-ai-blog-writer' ),
			'pictures'  => __( 'Pictures', 'dicecodes-ai-blog-writer' ),
			'sections'  => __( 'Section briefs', 'dicecodes-ai-blog-writer' ),
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'dicecodes-ai-blog-writer' ) );
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
		self::pane_start( $blueprint );
		self::pane_voice( $blueprint );
		self::pane_structure( $blueprint );
		self::pane_seo( $blueprint );
		self::pane_human( $blueprint );
		self::pane_pictures( $blueprint );
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
		echo '<h1>' . esc_html__( 'How it writes', 'dicecodes-ai-blog-writer' ) . '</h1>';
		echo '<p>' . esc_html__( 'The brief every post is written to. Anything shown in monospace is measured on the finished draft, not merely requested.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</div>';
		printf(
			'<button type="submit" class="bc-save">%s</button>',
			esc_html__( 'Save changes', 'dicecodes-ai-blog-writer' )
		);
		echo '</div>';
	}

	/**
	 * Group navigation.
	 *
	 * @return void
	 */
	private static function render_rail() {
		echo '<nav class="bc-rail" aria-label="' . esc_attr__( 'Blueprint sections', 'dicecodes-ai-blog-writer' ) . '">';

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
		echo '<h2 id="bc-brief-title">' . esc_html__( 'The brief', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Exactly what the model is told. Updates as you change anything above.', 'dicecodes-ai-blog-writer' ) . '</p>';
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
	 * The image prompt these settings produce, with a stand-in subject.
	 *
	 * The real subject is written per post by the model that wrote the article,
	 * so it cannot be shown here. Everything around it can, and that is the part
	 * these controls actually decide.
	 *
	 * @param array $blueprint Blueprint.
	 * @return string
	 */
	public static function picture_text( $blueprint ) {
		return Blogcraft_Art_Direction::assemble(
			__( '[what this post is about, described by the model]', 'dicecodes-ai-blog-writer' ),
			$blueprint
		);
	}

	/**
	 * Somewhere to begin, rather than forty-eight fields at their defaults.
	 *
	 * Two ways in. A shape is a whole set of rules for a recognisable kind of
	 * post. Matching an article measures a real one and derives the rules from
	 * what it actually does, which is the honest version of "write like that
	 * site" — and unlike a preset named after somebody, it stays true when they
	 * change.
	 *
	 * Neither saves. Both fill the controls in, and everything stays editable.
	 *
	 * @param array $blueprint The saved rules.
	 * @return void
	 */
	private static function pane_start( $blueprint ) {
		self::pane_open( 'start', true );

		echo '<p class="bc-note">' . esc_html__( 'Both of these fill in the controls on the other tabs. Nothing is saved until you press Save changes, and every field stays yours to change.', 'dicecodes-ai-blog-writer' ) . '</p>';

		echo '<h3 class="bc-subhead">' . esc_html__( 'A shape', 'dicecodes-ai-blog-writer' ) . '</h3>';
		// What the saved rules were started from, so the card that built
		// them is still marked after a save and a reload. The class was
		// only ever added by the script, in the moment, and went the
		// instant the page was loaded again.
		$chosen = isset( $blueprint['archetype'] ) ? (string) $blueprint['archetype'] : '';

		printf(
			'<input type="hidden" name="archetype" id="bc_archetype" value="%s" />',
			esc_attr( $chosen )
		);

		echo '<div class="bc-shapes">';

		foreach ( Blogcraft_Archetypes::all() as $slug => $shape ) {
			$is = ( $slug === $chosen );

			printf(
				'<button type="button" class="bc-shape%4$s" data-shape="%1$s" aria-pressed="%5$s"><strong>%2$s</strong><span>%3$s</span></button>',
				esc_attr( $slug ),
				esc_html( $shape['label'] ),
				esc_html( $shape['blurb'] ),
				$is ? ' is-chosen' : '',
				$is ? 'true' : 'false'
			);
		}

		echo '</div>';

		echo '<h3 class="bc-subhead">' . esc_html__( 'Or match an article you like', 'dicecodes-ai-blog-writer' ) . '</h3>';

		self::row(
			__( 'Address', 'dicecodes-ai-blog-writer' ),
			__( 'Any published article, including one of your own. Dicecodes AI Blog Writer reads it and works out how long it runs, how it is sectioned, how long its sentences are, whether it uses tables, how heavily it links out, how many figures it states, and whether it says "I" or "you". Structure only: none of the wording is copied, kept, or shown to a model.', 'dicecodes-ai-blog-writer' ),
			'<input type="url" class="bc-text" id="bc-match-url" placeholder="https://example.com/their-best-post" autocomplete="off" />'
			. '<button type="button" class="button bc-match" id="bc-match-go">' . esc_html__( 'Read it and match', 'dicecodes-ai-blog-writer' ) . '</button>',
			'bc-match-url'
		);

		echo '<div class="bc-shape-notes" id="bc-shape-notes" hidden></div>';

		echo '</section>';
	}

	/**
	 * Work out a set of rules, from a shape or from a real article.
	 *
	 * @return void
	 */
	public static function handle_shape() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::SAVE_ACTION, '_blogcraft_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'That form has expired. Reload the page.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		// Every one of these asks the provider something. Without one the
		// old answer was whatever the HTTP layer said, which is true and
		// tells nobody what to do about it.
		Blogcraft_Request::require_provider();

		// Reading the site's own posts and describing them. It answers
		// in blueprint field names, and the script puts them into the
		// form the same way a shape does.
		if ( isset( $_POST['learn'] ) ) {
			wp_send_json_success( Blogcraft_Learn::suggest() );
		}

		$shape = isset( $_POST['shape'] ) ? sanitize_key( wp_unslash( $_POST['shape'] ) ) : '';

		if ( '' !== $shape ) {
			$all    = Blogcraft_Archetypes::all();
			$fields = Blogcraft_Archetypes::fields( $shape );

			if ( empty( $fields ) ) {
				wp_send_json_error( array( 'message' => __( 'That shape is not one of the ones offered.', 'dicecodes-ai-blog-writer' ) ), 400 );
			}

			wp_send_json_success(
				array(
					'fields' => $fields,
					'notes'  => array( $all[ $shape ]['blurb'] ),
				)
			);
		}

		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';

		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Give it a web address to read.', 'dicecodes-ai-blog-writer' ) ), 400 );
		}

		$study = Blogcraft_Emulate::study( $url );

		if ( ! $study['ok'] ) {
			wp_send_json_error( array( 'message' => $study['error'] ), 400 );
		}

		wp_send_json_success(
			array(
				'fields' => $study['fields'],
				'notes'  => array_merge(
					array(
						'' === $study['title']
							? __( 'Read that page.', 'dicecodes-ai-blog-writer' )
							: sprintf(
								/* translators: %s: the title of the article that was read. */
								__( 'Read "%s".', 'dicecodes-ai-blog-writer' ),
								$study['title']
							),
					),
					$study['notes']
				),
			)
		);
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
		echo wp_kses( $control, Blogcraft_Markup::allowed() );

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
		self::pane_open( 'voice' );

		printf(
			'<p class="bc-learn-row"><button type="button" class="button" id="bc-learn">%1$s</button> <span class="description">%2$s</span></p>',
			esc_html__( 'Learn from my posts', 'dicecodes-ai-blog-writer' ),
			esc_html__( 'Fills these in from what you have already published. Nothing is saved until you press save.', 'dicecodes-ai-blog-writer' )
		);

		self::row(
			__( 'What this blog is about', 'dicecodes-ai-blog-writer' ),
			__( 'One or two sentences on the subject and the angle.', 'dicecodes-ai-blog-writer' ),
			self::area( 'niche', $bp['niche'], __( 'Standing desks and home office kit, tested rather than summarised', 'dicecodes-ai-blog-writer' ), 3 ),
			'bc_niche'
		);

		self::row(
			__( 'Style rules', 'dicecodes-ai-blog-writer' ),
			__( 'One per line. Followed on every post.', 'dicecodes-ai-blog-writer' ),
			self::area( 'style_rules', $bp['style_rules'], "No em dashes\nShort paragraphs\nNever open with a question", 4 ),
			'bc_style_rules'
		);

		self::row(
			__( 'What you have done yourself', 'dicecodes-ai-blog-writer' ),
			__( 'Drawn on where it fits. Never invented beyond.', 'dicecodes-ai-blog-writer' ),
			self::area( 'experience', $bp['experience'], __( 'We have tested 40 desks since 2019 and run a workshop', 'dicecodes-ai-blog-writer' ), 3 ),
			'bc_experience'
		);

		self::row(
			__( 'Tone', 'dicecodes-ai-blog-writer' ),
			__( 'Pick the closest, or describe your own.', 'dicecodes-ai-blog-writer' ),
			self::select( 'tone', Blogcraft_Blueprint::tones(), $bp['tone'] ),
			'bc_tone'
		);

		self::row(
			__( 'Describe the tone', 'dicecodes-ai-blog-writer' ),
			__( 'Used only when the tone above is set to something else.', 'dicecodes-ai-blog-writer' ),
			self::text( 'tone_custom', $bp['tone_custom'], __( 'Dry, a little sceptical, never breathless', 'dicecodes-ai-blog-writer' ) ),
			'bc_tone_custom'
		);

		self::row(
			__( 'Who is speaking', 'dicecodes-ai-blog-writer' ),
			'',
			self::segmented( 'point_of_view', Blogcraft_Blueprint::points_of_view(), $bp['point_of_view'] )
		);

		self::row(
			__( 'Who is reading', 'dicecodes-ai-blog-writer' ),
			'',
			self::select( 'audience', Blogcraft_Blueprint::audiences(), $bp['audience'] ),
			'bc_audience'
		);

		self::row(
			__( 'Describe the reader', 'dicecodes-ai-blog-writer' ),
			__( 'What they already know, and what they are trying to do.', 'dicecodes-ai-blog-writer' ),
			self::text( 'audience_custom', $bp['audience_custom'], __( 'People setting up a first home office on a budget', 'dicecodes-ai-blog-writer' ) ),
			'bc_audience_custom'
		);

		self::row(
			__( 'Formality', 'dicecodes-ai-blog-writer' ),
			__( '1 is a message to a friend. 5 is a white paper.', 'dicecodes-ai-blog-writer' ),
			self::slider( 'formality', 1, 5, 1, $bp['formality'] ),
			'bc_formality'
		);

		self::row(
			__( 'Reading level', 'dicecodes-ai-blog-writer' ),
			__( 'Measured on the finished draft as a Flesch Reading Ease band.', 'dicecodes-ai-blog-writer' ),
			self::select( 'reading_level', self::level_labels(), $bp['reading_level'] ),
			'bc_reading_level'
		);

		self::row(
			__( 'Spelling', 'dicecodes-ai-blog-writer' ),
			'',
			self::segmented(
				'locale_spelling',
				array(
					'us' => __( 'American', 'dicecodes-ai-blog-writer' ),
					'uk' => __( 'British', 'dicecodes-ai-blog-writer' ),
				),
				$bp['locale_spelling']
			)
		);

		self::row(
			__( 'Language', 'dicecodes-ai-blog-writer' ),
			__( 'Leave blank to write in English.', 'dicecodes-ai-blog-writer' ),
			self::text( 'language', $bp['language'], __( 'Spanish', 'dicecodes-ai-blog-writer' ) ),
			'bc_language'
		);

		self::row(
			__( 'Spell these exactly', 'dicecodes-ai-blog-writer' ),
			__( 'One per line. Product names, your brand, anything that gets mangled.', 'dicecodes-ai-blog-writer' ),
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
			__( 'Length', 'dicecodes-ai-blog-writer' ),
			__( 'Measured. A draft outside the tolerance below is sent back to be rewritten.', 'dicecodes-ai-blog-writer' ),
			self::slider( 'word_target', 300, 4000, 50, $bp['word_target'], __( ' words', 'dicecodes-ai-blog-writer' ) ),
			'bc_word_target'
		);

		self::row(
			__( 'Tolerance', 'dicecodes-ai-blog-writer' ),
			__( 'How far either side of the target is acceptable.', 'dicecodes-ai-blog-writer' ),
			self::slider( 'word_tolerance', 5, 60, 5, $bp['word_tolerance'], '%' ),
			'bc_word_tolerance'
		);

		self::row(
			__( 'Fewest sections', 'dicecodes-ai-blog-writer' ),
			'',
			self::slider( 'sections_min', 1, 12, 1, $bp['sections_min'] ),
			'bc_sections_min'
		);

		self::row(
			__( 'Most sections', 'dicecodes-ai-blog-writer' ),
			__( 'Measured on the finished draft.', 'dicecodes-ai-blog-writer' ),
			self::slider( 'sections_max', 1, 15, 1, $bp['sections_max'] ),
			'bc_sections_max'
		);

		self::row(
			__( 'Longest sentence', 'dicecodes-ai-blog-writer' ),
			__( 'Measured. Every sentence over this is reported back for splitting.', 'dicecodes-ai-blog-writer' ),
			self::slider( 'sentence_max_words', 12, 50, 1, $bp['sentence_max_words'], __( ' words', 'dicecodes-ai-blog-writer' ) ),
			'bc_sentence_max_words'
		);

		self::row(
			__( 'Longest paragraph', 'dicecodes-ai-blog-writer' ),
			__( 'Measured.', 'dicecodes-ai-blog-writer' ),
			self::slider( 'para_max_sentences', 1, 8, 1, $bp['para_max_sentences'], __( ' sentences', 'dicecodes-ai-blog-writer' ) ),
			'bc_para_max_sentences'
		);

		self::row(
			__( 'How it opens', 'dicecodes-ai-blog-writer' ),
			'',
			self::select( 'intro_style', Blogcraft_Blueprint::intro_styles(), $bp['intro_style'] ),
			'bc_intro_style'
		);

		self::row(
			__( 'How it ends', 'dicecodes-ai-blog-writer' ),
			'',
			self::select( 'conclusion_style', Blogcraft_Blueprint::conclusion_styles(), $bp['conclusion_style'] ),
			'bc_conclusion_style'
		);

		self::row(
			__( 'Include', 'dicecodes-ai-blog-writer' ),
			'',
			self::toggle( 'takeaways', $bp['takeaways'], __( 'Key takeaways', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'faq', $bp['faq'], __( 'Questions and answers', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'toc', $bp['toc'], __( 'Table of contents', 'dicecodes-ai-blog-writer' ) )
		);

		self::row(
			__( 'Extra sections', 'dicecodes-ai-blog-writer' ),
			__( 'Each one is written after the article is finished, from the article, in a single extra request. Off by default because a post that has all of them bolted on reads like a form.', 'dicecodes-ai-blog-writer' ),
			self::toggle( 'block_audience', $bp['block_audience'], __( 'Who this is for, and who it is not', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'block_proscons', $bp['block_proscons'], __( 'What works and what does not', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'block_figures', $bp['block_figures'], __( 'A table of the figures, with sources', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'block_mistakes', $bp['block_mistakes'], __( 'Mistakes worth avoiding', 'dicecodes-ai-blog-writer' ) )
		);

		self::row(
			__( 'Sources', 'dicecodes-ai-blog-writer' ),
			__( 'Real links to what the article was actually researched from, listed at the end. On by default, unlike the extras above: nothing lets the model invent a citation link, so this is the only honest way the "Sources cited" check below can pass. Turn it off only if you also lower that target to 0.', 'dicecodes-ai-blog-writer' ),
			self::toggle( 'block_sources', $bp['block_sources'], __( 'The sources it was written from', 'dicecodes-ai-blog-writer' ) )
		);

		self::row(
			__( 'Allow', 'dicecodes-ai-blog-writer' ),
			'',
			self::toggle( 'lists', $bp['lists'], __( 'Bulleted lists', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'tables', $bp['tables'], __( 'Tables', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'allow_h3', $bp['allow_h3'], __( 'Sub-subheadings', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'bold_key_phrases', $bp['bold_key_phrases'], __( 'Bold key phrases', 'dicecodes-ai-blog-writer' ) )
		);

		self::row(
			__( 'How many takeaways', 'dicecodes-ai-blog-writer' ),
			'',
			self::slider( 'takeaways_count', 2, 10, 1, $bp['takeaways_count'] ),
			'bc_takeaways_count'
		);

		self::row(
			__( 'How many questions', 'dicecodes-ai-blog-writer' ),
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
			__( 'Target phrase', 'dicecodes-ai-blog-writer' ),
			__( 'Leave blank to let each topic speak for itself. Overridden per post.', 'dicecodes-ai-blog-writer' ),
			self::text( 'primary_keyword', $bp['primary_keyword'], __( 'standing desk', 'dicecodes-ai-blog-writer' ) ),
			'bc_primary_keyword'
		);

		self::row(
			__( 'Least often', 'dicecodes-ai-blog-writer' ),
			__( 'Measured as a share of all words.', 'dicecodes-ai-blog-writer' ),
			self::slider( 'density_min', 0, 3, 0.1, $bp['density_min'], '%' ),
			'bc_density_min'
		);

		self::row(
			__( 'Most often', 'dicecodes-ai-blog-writer' ),
			__( 'Measured. Above this reads as keyword stuffing.', 'dicecodes-ai-blog-writer' ),
			self::slider( 'density_max', 0.5, 6, 0.1, $bp['density_max'], '%' ),
			'bc_density_max'
		);

		self::row(
			__( 'Also cover', 'dicecodes-ai-blog-writer' ),
			__( 'One per line. Related phrases worth a mention.', 'dicecodes-ai-blog-writer' ),
			self::area( 'secondary_keywords', $bp['secondary_keywords'], "adjustable desk\nsit stand desk" ),
			'bc_secondary_keywords'
		);

		self::row(
			__( 'Work these out for me', 'dicecodes-ai-blog-writer' ),
			__( 'When you name no terms of your own, take them from the pages already covering the subject. Costs nothing extra: it reads the research this post already gathered.', 'dicecodes-ai-blog-writer' ),
			self::toggle( 'auto_terms', $bp['auto_terms'], __( 'Derive the terms from existing coverage', 'dicecodes-ai-blog-writer' ) )
		);

		self::row(
			__( 'Must appear', 'dicecodes-ai-blog-writer' ),
			__( 'One per line. Measured — every one of these is checked for, and a missing term is reported back.', 'dicecodes-ai-blog-writer' ),
			self::area( 'required_terms', $bp['required_terms'], "ergonomics\nanti-fatigue mat" ),
			'bc_required_terms'
		);

		self::row(
			__( 'Sources to cite', 'dicecodes-ai-blog-writer' ),
			__( 'Measured. Outbound links to reputable sources.', 'dicecodes-ai-blog-writer' ),
			self::slider( 'external_links_target', 0, 10, 1, $bp['external_links_target'] ),
			'bc_external_links_target'
		);

		self::row(
			__( 'Links to your own posts', 'dicecodes-ai-blog-writer' ),
			__( 'Added after writing, then counted.', 'dicecodes-ai-blog-writer' ),
			self::slider( 'internal_links_target', 0, 10, 1, $bp['internal_links_target'] ),
			'bc_internal_links_target'
		);

		self::row(
			__( 'Title length', 'dicecodes-ai-blog-writer' ),
			'',
			self::slider( 'meta_title_max', 40, 80, 1, $bp['meta_title_max'], __( ' characters', 'dicecodes-ai-blog-writer' ) ),
			'bc_meta_title_max'
		);

		self::row(
			__( 'Description length', 'dicecodes-ai-blog-writer' ),
			'',
			self::slider( 'meta_desc_max', 100, 200, 5, $bp['meta_desc_max'], __( ' characters', 'dicecodes-ai-blog-writer' ) ),
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
			__( 'Devices to use', 'dicecodes-ai-blog-writer' ),
			__( 'Where they genuinely help. Nothing here is forced into every section.', 'dicecodes-ai-blog-writer' ),
			self::chips(
				'literary_devices',
				Blogcraft_Blueprint::literary_devices(),
				Blogcraft_Blueprint::chosen( $bp, 'literary_devices' )
			)
		);

		self::row(
			__( 'Habits', 'dicecodes-ai-blog-writer' ),
			'',
			self::toggle( 'sentence_variety', $bp['sentence_variety'], __( 'Vary sentence length deliberately', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'allow_contractions', $bp['allow_contractions'], __( 'Allow contractions', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'allow_em_dash', $bp['allow_em_dash'], __( 'Allow em dashes', 'dicecodes-ai-blog-writer' ) )
		);

		self::row(
			__( 'Demand', 'dicecodes-ai-blog-writer' ),
			'',
			self::toggle( 'require_experience', $bp['require_experience'], __( 'First-hand, specific detail', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'require_citations', $bp['require_citations'], __( 'A named source for factual claims', 'dicecodes-ai-blog-writer' ) )
			. self::toggle( 'require_statistics', $bp['require_statistics'], __( 'Concrete figures where they exist', 'dicecodes-ai-blog-writer' ) )
		);

		self::row(
			__( 'Never write', 'dicecodes-ai-blog-writer' ),
			__( 'One per line. Measured — any of these found in a draft sends it back.', 'dicecodes-ai-blog-writer' ),
			self::area( 'banned_phrases', $bp['banned_phrases'], "in today's fast-paced world\ndelve into\nunlock the power of", 5 ),
			'bc_banned_phrases'
		);

		self::row(
			__( 'Never mention', 'dicecodes-ai-blog-writer' ),
			__( 'One per line. Measured, and weighted heavily. For competitors, brands and claims that must never appear at all.', 'dicecodes-ai-blog-writer' ),
			self::area( 'negative_keywords', $bp['negative_keywords'], "a competitor's name\nguaranteed results", 4 ),
			'bc_negative_keywords'
		);

		self::row(
			__( 'Steer clear of', 'dicecodes-ai-blog-writer' ),
			__( 'One per line. Subjects to avoid even in passing. Sent to the model rather than measured, because a subject is not a single word.', 'dicecodes-ai-blog-writer' ),
			self::area( 'avoid_subjects', $bp['avoid_subjects'], "medical advice\npolitics\nprice comparisons", 4 ),
			'bc_avoid_subjects'
		);

		echo '</section>';
	}

	/**
	 * How pictures for a post should look.
	 *
	 * Kept away from the writing controls because it is a different job: these
	 * decide treatment, and the article itself decides subject.
	 *
	 * @param array $bp Blueprint.
	 * @return void
	 */
	private static function pane_pictures( $bp ) {
		self::pane_open( 'pictures' );

		echo '<p class="bc-note">' . esc_html__( 'The article decides what the picture shows. These decide how it looks. Which service makes them is chosen under Settings.', 'dicecodes-ai-blog-writer' ) . '</p>';

		self::row(
			__( 'Describe the picture first', 'dicecodes-ai-blog-writer' ),
			__( 'Ask the writing model to describe a scene for this specific post, instead of handing the headline to an image model and hoping. This is the single biggest difference between a useful image and clip art of the title.', 'dicecodes-ai-blog-writer' ),
			self::toggle( 'image_describe', $bp['image_describe'], __( 'Write a proper description for each image', 'dicecodes-ai-blog-writer' ) )
		);

		self::row(
			__( 'Treatment', 'dicecodes-ai-blog-writer' ),
			'',
			self::select( 'image_style', Blogcraft_Art_Direction::styles(), $bp['image_style'] ),
			'bc_image_style'
		);

		self::row(
			__( 'Mood', 'dicecodes-ai-blog-writer' ),
			'',
			self::select( 'image_mood', Blogcraft_Art_Direction::moods(), $bp['image_mood'] ),
			'bc_image_mood'
		);

		self::row(
			__( 'What it shows', 'dicecodes-ai-blog-writer' ),
			__( 'The angle every picture takes on its subject.', 'dicecodes-ai-blog-writer' ),
			self::select( 'image_subject', Blogcraft_Art_Direction::subjects(), $bp['image_subject'] ),
			'bc_image_subject'
		);

		self::row(
			__( 'Shape', 'dicecodes-ai-blog-writer' ),
			'',
			self::segmented( 'image_shape', Blogcraft_Art_Direction::shapes(), $bp['image_shape'] )
		);

		self::row(
			__( 'Colours', 'dicecodes-ai-blog-writer' ),
			__( 'Describe them in words. Leave blank to let each picture suit its own subject.', 'dicecodes-ai-blog-writer' ),
			self::text( 'image_palette', $bp['image_palette'], __( 'muted greens, warm oak, off-white', 'dicecodes-ai-blog-writer' ) ),
			'bc_image_palette'
		);

		self::row(
			__( 'Anything else', 'dicecodes-ai-blog-writer' ),
			__( 'Added to every image prompt as written.', 'dicecodes-ai-blog-writer' ),
			self::area( 'image_extra', $bp['image_extra'], __( 'shot from slightly above, shallow depth of field', 'dicecodes-ai-blog-writer' ), 2 ),
			'bc_image_extra'
		);

		self::row(
			__( 'Never show', 'dicecodes-ai-blog-writer' ),
			__( 'Things that keep appearing and should not.', 'dicecodes-ai-blog-writer' ),
			self::area( 'image_avoid', $bp['image_avoid'], __( 'crowds, brand names, hands holding phones', 'dicecodes-ai-blog-writer' ), 2 ),
			'bc_image_avoid'
		);

		self::row(
			__( 'Words in the picture', 'dicecodes-ai-blog-writer' ),
			__( 'Image models render lettering as convincing gibberish, so text is excluded by default. Turn this on only with a model that handles typography.', 'dicecodes-ai-blog-writer' ),
			self::toggle( 'image_allow_text', $bp['image_allow_text'], __( 'Allow text in generated images', 'dicecodes-ai-blog-writer' ) )
		);

		echo '<h3 class="bc-subhead">' . esc_html__( 'What the image model is told', 'dicecodes-ai-blog-writer' ) . '</h3>';
		printf(
			'<pre class="bc-brief-body" id="bc-picture-prompt" aria-live="polite">%s</pre>',
			esc_html( self::picture_text( $bp ) )
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

		echo '<p class="bc-note">' . esc_html__( 'Instructions for one part of the article only. These are appended to the rules the model already has, so use them for the things that keep coming out wrong.', 'dicecodes-ai-blog-writer' ) . '</p>';

		self::row(
			__( 'The opening', 'dicecodes-ai-blog-writer' ),
			'',
			self::area( 'prompt_intro', $bp['prompt_intro'], __( 'Never open with a rhetorical question.', 'dicecodes-ai-blog-writer' ) ),
			'bc_prompt_intro'
		);

		self::row(
			__( 'Every section', 'dicecodes-ai-blog-writer' ),
			'',
			self::area( 'prompt_section', $bp['prompt_section'], __( 'Open each section with the answer, then explain it.', 'dicecodes-ai-blog-writer' ) ),
			'bc_prompt_section'
		);

		self::row(
			__( 'The ending', 'dicecodes-ai-blog-writer' ),
			'',
			self::area( 'prompt_conclusion', $bp['prompt_conclusion'], __( 'No summary of what was just said. End on what to do next.', 'dicecodes-ai-blog-writer' ) ),
			'bc_prompt_conclusion'
		);

		self::row(
			__( 'The questions', 'dicecodes-ai-blog-writer' ),
			'',
			self::area( 'prompt_faq', $bp['prompt_faq'], __( 'Answer in two sentences at most.', 'dicecodes-ai-blog-writer' ) ),
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
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::SAVE_ACTION, '_blogcraft_nonce' );

		$raw = map_deep( wp_unslash( $_POST ), 'sanitize_textarea_field' );

		$current = Blogcraft_Blueprint::get();
		$saved   = array_merge( $current, self::from_request( $raw ) );

		Blogcraft_Blueprint::save( Blogcraft_Blueprint::active_slug(), $saved );

		self::back( true, __( 'Saved. Every post from now on is written to this brief.', 'dicecodes-ai-blog-writer' ) );
	}

	/**
	 * Return the brief for an unsaved blueprint, for the live panel.
	 *
	 * @return void
	 */
	public static function handle_preview() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::SAVE_ACTION, '_blogcraft_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'That form has expired. Reload the page.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		$raw = map_deep( wp_unslash( $_POST ), 'sanitize_textarea_field' );

		$blueprint = Blogcraft_Blueprint::normalise(
			array_merge( Blogcraft_Blueprint::get(), self::from_request( $raw ) )
		);

		wp_send_json_success(
			array(
				'brief'   => self::brief_text( $blueprint ),
				'picture' => self::picture_text( $blueprint ),
			)
		);
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
