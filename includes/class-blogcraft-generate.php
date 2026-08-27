<?php
/**
 * Manual post generation screen.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lets a user queue a topic and watch it move through the pipeline.
 *
 * Generation is queued rather than run inline: a full pipeline is five provider
 * calls, which would exceed PHP's execution limit on most shared hosting if it
 * ran during the form submission.
 */
class Blogcraft_Generate {

	/**
	 * Submenu slug.
	 */
	const PAGE_SLUG = 'blogcraft-write';

	/**
	 * Nonce action for queueing a topic.
	 */
	const QUEUE_ACTION = 'blogcraft_queue_topic';

	/**
	 * Nonce action for running the queue on demand.
	 */
	const RUN_ACTION = 'blogcraft_run_queue_now';

	/**
	 * Nonce action for bulk topic import.
	 */
	const BULK_ACTION = 'blogcraft_bulk_topics';

	/**
	 * Nonce action for rolling a batch back.
	 */
	const ROLLBACK_ACTION = 'blogcraft_rollback';

	/**
	 * Transient prefix holding the last notice for one user.
	 */
	const NOTICE_TRANSIENT = 'blogcraft_write_notice_';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 15 );
		add_action( 'admin_post_blogcraft_queue_topic', array( __CLASS__, 'handle_queue' ) );
		add_action( 'admin_post_blogcraft_run_queue_now', array( __CLASS__, 'handle_run_now' ) );
		add_action( 'admin_post_blogcraft_bulk_topics', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_blogcraft_rollback', array( __CLASS__, 'handle_rollback' ) );
		add_action( 'wp_ajax_blogcraft_preview_post', array( __CLASS__, 'handle_preview' ) );
		add_action( 'wp_ajax_blogcraft_suggest_brief', array( __CLASS__, 'handle_suggest' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Load the composer's styling and behaviour, on this screen only.
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
			'blogcraft-compose',
			BLOGCRAFT_URL . 'assets/compose.js',
			array(),
			BLOGCRAFT_VERSION,
			true
		);

		wp_localize_script(
			'blogcraft-compose',
			'blogcraftCompose',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::QUEUE_ACTION ),
				'asking'   => __( 'Thinking about your topic...', 'blogcraft' ),
				'askAgain' => __( 'What should I write about this?', 'blogcraft' ),
				'noTopic'  => __( 'Write a topic first, then ask again.', 'blogcraft' ),
			)
		);
	}

	/**
	 * The outcome panel, reduced to markup that cannot carry script.
	 *
	 * Everything in outcome_html() is escaped as it is built, so this changes
	 * nothing today. It exists so that the front end can keep inserting the
	 * response as markup without that being one careless future edit away from
	 * an injection: the allowlist below has no script, no event attributes and
	 * no href, so there is nothing to exploit even if an unescaped value slips
	 * in upstream.
	 *
	 * @param array $blueprint Blueprint to describe.
	 * @return string
	 */
	private static function safe_outcome( $blueprint ) {
		return wp_kses(
			self::outcome_html( $blueprint ),
			array(
				'ol'   => array( 'class' => array() ),
				'li'   => array( 'class' => array() ),
				'div'  => array( 'class' => array() ),
				'p'    => array( 'class' => array() ),
				'span' => array( 'class' => array() ),
			)
		);
	}

	/**
	 * Recompute the outcome panel for an unsaved brief.
	 *
	 * Rendered server-side for the same reason the blueprint brief is: the panel
	 * claims to predict what will actually be produced, and a second copy of that
	 * arithmetic in script would drift from the one the pipeline uses.
	 *
	 * @return void
	 */
	public static function handle_preview() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'blogcraft' ) ), 403 );
		}

		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! Blogcraft_Request::verify( self::QUEUE_ACTION, $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'That form has expired. Reload the page.', 'blogcraft' ) ), 403 );
		}

		$raw = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- every field is sanitised by type in Blogcraft_Blueprint::normalise().

		$blueprint = Blogcraft_Blueprint::with_overrides(
			Blogcraft_Blueprint::get(),
			self::overrides_from( $raw )
		);

		$topic = isset( $raw['topic'] ) && ! is_array( $raw['topic'] ) ? sanitize_text_field( (string) $raw['topic'] ) : '';
		$clash = Blogcraft_Preview::clash( $topic );

		wp_send_json_success(
			array(
				'outcome' => self::safe_outcome( $blueprint ),
				'clash'   => ( '' === $clash )
					? ''
					: sprintf(
						/* translators: %s: the clashing topic. */
						__( 'This looks like a repeat of "%s". Queueing it anyway is allowed, but near-identical posts are what search engines penalise.', 'blogcraft' ),
						$clash
					),
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
			__( 'Write a post', 'blogcraft' ),
			__( 'Write a post', 'blogcraft' ),
			Blogcraft_Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
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

		$notice = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );

		echo '<div class="wrap blogcraft-page blogcraft-compose-page">';
		Blogcraft_Nav::render();
		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Write a post', 'blogcraft' ) . '</h1>';
		echo '<p>' . esc_html__( 'Give it a topic. It researches, drafts, critiques its own work, rewrites, then checks the result before anything reaches your site.', 'blogcraft' ) . '</p>';
		echo '</div>';

		if ( is_array( $notice ) ) {
			delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
			printf(
				'<div class="notice %s"><p>%s</p></div>',
				esc_attr( empty( $notice['ok'] ) ? 'notice-error' : 'notice-success' ),
				esc_html( (string) $notice['message'] )
			);
		}

		if ( ! Blogcraft_Provider_Registry::is_configured() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'No AI provider is configured yet. Set one up under Blogcraft → Settings first.', 'blogcraft' )
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="blogcraft-compose">';
		echo '<input type="hidden" name="action" value="blogcraft_queue_topic" />';
		echo '<input type="hidden" name="bc_compose" value="1" />';
		Blogcraft_Request::nonce_field( self::QUEUE_ACTION );

		self::render_composer();

		// The primary action, pinned so it is reachable from anywhere in a
		// form six tab-panels deep. It used to sit below all of them, so
		// typing a topic meant scrolling past every override to press the
		// button — on the one screen that exists to be used quickly.
		echo '<div class="bc-commit">';

		printf(
			'<p class="bc-commit-say">%s</p>',
			esc_html__( 'You are taken to a live progress screen while it writes, and shown the finished draft and its score before anything reaches your site.', 'blogcraft' )
		);

		if ( Blogcraft_Provider_Registry::is_configured() ) {
			printf(
				'<button type="submit" class="bc-save">%s</button>',
				// "Queue" described the old behaviour, where the post was
				// written by cron some minutes later and this screen could
				// only promise it. Pressing this now starts the writing.
				esc_html__( 'Write this post', 'blogcraft' )
			);
		} else {
			printf(
				'<a class="bc-compose-fix" href="%1$s">%2$s</a><button type="submit" class="bc-save" disabled>%3$s</button>',
				esc_url( admin_url( 'admin.php?page=blogcraft-settings#bc-card-provider' ) ),
				esc_html__( 'Connect a provider first', 'blogcraft' ),
				esc_html__( 'Write this post', 'blogcraft' )
			);
		}

		echo '</div>';

		self::render_confirm();

		echo '</form>';

		self::render_more();
		echo '</div>';
	}

	/**
	 * The parts a post can be built from, and what each one is for.
	 *
	 * Every one of these already exists as a blueprint field. What they
	 * did not have was a place anybody looks: they live on a Shape tab and
	 * five of them had no control at all, so the first most people learn
	 * of a Sources block or an FAQ is finding one in the finished draft.
	 *
	 * @return array Field name => array( label, why ).
	 */
	private static function parts() {
		return array(
			'takeaways'      => array(
				__( 'Key takeaways', 'blogcraft' ),
				__( 'A short list near the top. It is what gets quoted when a search engine answers the question without the click.', 'blogcraft' ),
			),
			'faq'            => array(
				__( 'Questions and answers', 'blogcraft' ),
				__( 'Built from what people actually ask about this subject, and marked up so it can appear as its own result.', 'blogcraft' ),
			),
			'toc'            => array(
				__( 'Contents list', 'blogcraft' ),
				__( 'Worth it on a long guide, clutter on a short answer.', 'blogcraft' ),
			),
			'tables'         => array(
				__( 'Tables', 'blogcraft' ),
				__( 'For anything with figures to compare. Prose that lists numbers is harder to read and harder to cite.', 'blogcraft' ),
			),
			'lists'          => array(
				__( 'Bulleted lists', 'blogcraft' ),
				__( 'Used where a list is genuinely a list, not as a way of avoiding paragraphs.', 'blogcraft' ),
			),
			'block_sources'  => array(
				__( 'The sources it was written from', 'blogcraft' ),
				__( 'The only real outbound links a post gets — they are the addresses research actually fetched, never ones the model wrote. Off means no citations, and the citation check is skipped rather than failed.', 'blogcraft' ),
			),
			'block_audience' => array(
				__( 'Who it is for', 'blogcraft' ),
				__( 'A line near the top saying who should read on, and who should not.', 'blogcraft' ),
			),
			'block_proscons' => array(
				__( 'What works and what does not', 'blogcraft' ),
				__( 'For reviews and comparisons. A page with no criticism in it reads as an advert.', 'blogcraft' ),
			),
			'block_figures'  => array(
				__( 'The numbers', 'blogcraft' ),
				__( 'A table of the figures the post rests on, so they can be checked rather than taken.', 'blogcraft' ),
			),
			'block_mistakes' => array(
				__( 'Mistakes worth avoiding', 'blogcraft' ),
				__( 'The part a writer who has actually done the thing can write and a summary of other pages cannot.', 'blogcraft' ),
			),
		);
	}

	/**
	 * The things that have never been decided, and what each costs.
	 *
	 * A default is what happens when nobody chose, so reading one back
	 * cannot tell you whether anybody did — which is why this asks
	 * was_chosen() rather than get(). Somebody who has never opened the
	 * voice settings and somebody who deliberately left them empty look
	 * identical to get(), and only one of them wants telling.
	 *
	 * Nothing here blocks anything. Every row is a link and a sentence,
	 * and the button underneath writes the post either way. A plugin that
	 * refuses to work until twenty fields are filled is one people
	 * uninstall on the first attempt.
	 *
	 * @return void
	 */
	private static function render_gaps() {
		$gaps = array();

		$niche    = trim( (string) Blogcraft_Settings::get( 'voice_niche' ) );
		$audience = trim( (string) Blogcraft_Settings::get( 'voice_audience' ) );

		if ( '' === $niche || '' === $audience ) {
			$gaps[] = array(
				'title' => __( 'Nobody has said who this blog is for', 'blogcraft' ),
				'why'   => __( 'This is sent with every request and it is the single biggest reason two blogs using the same model do not read the same. Without it the model writes for nobody in particular, which is what generic sounds like.', 'blogcraft' ),
				'url'   => admin_url( 'admin.php?page=blogcraft-settings#bc-card-voice' ),
				'link'  => __( 'Describe it', 'blogcraft' ),
			);
		}

		// Shape, tone and length all come from the blueprint. Untouched, it
		// is a sensible general-purpose brief rather than a wrong one — so
		// this says "never adjusted", not "broken".
		if ( ! Blogcraft_Blueprint::was_edited() ) {
			$gaps[] = array(
				'title' => __( 'The writing rules have never been adjusted', 'blogcraft' ),
				'why'   => __( 'Shape, length, tone, reading level and what counts as a finished post are all set on one screen, and it still holds the defaults. They are reasonable defaults, not right ones: a hands-on review and a definitive guide are not the same shape.', 'blogcraft' ),
				'url'   => admin_url( 'admin.php?page=blogcraft-blueprint' ),
				'link'  => __( 'Choose a shape', 'blogcraft' ),
			);
		}

		// Research is the difference between writing from current sources
		// and writing from memory, and it contacts nothing until asked.
		$researching = false;

		foreach ( array_keys( Blogcraft_Research::free_sources() ) as $source ) {
			if ( Blogcraft_Settings::get( $source ) ) {
				$researching = true;
			}
		}

		if ( ! $researching && ! Blogcraft_Research::has_search_provider() ) {
			$gaps[] = array(
				'title' => __( 'It has nothing to read but its own memory', 'blogcraft' ),
				'why'   => __( 'With research on, the model is handed current sources and the finished draft is checked against them. With everything off it writes from training data, which dates badly and can cite nothing. The two free sources need no account.', 'blogcraft' ),
				'url'   => admin_url( 'admin.php?page=blogcraft-settings#bc-card-research' ),
				'link'  => __( 'Switch one on', 'blogcraft' ),
			);
		}

		// Filled in on this screen, so it is checked in the browser rather
		// than here — but the row has to exist for the script to show it.
		printf(
			'<div class="bc-gap is-evidence" id="bc-gap-evidence" hidden>'
			. '<strong>%1$s</strong><span>%2$s</span>'
			. '<button type="button" class="button-link" id="bc-gap-evidence-go">%3$s</button>'
			. '</div>',
			esc_html__( 'You have not said anything only you know', 'blogcraft' ),
			esc_html__( 'The heaviest check on the finished post, and the one part a model cannot produce. Your own numbers, prices, results, or what went wrong when you tried it. One or two sentences is enough.', 'blogcraft' ),
			esc_html__( 'Go and add it', 'blogcraft' )
		);

		if ( empty( $gaps ) ) {
			return;
		}

		printf(
			'<h3 class="bc-confirm-section">%s</h3>',
			esc_html__( 'Worth setting first', 'blogcraft' )
		);

		foreach ( $gaps as $gap ) {
			printf(
				'<div class="bc-gap"><strong>%1$s</strong><span>%2$s</span>'
				. '<a href="%3$s">%4$s</a></div>',
				esc_html( $gap['title'] ),
				esc_html( $gap['why'] ),
				esc_url( $gap['url'] ),
				esc_html( $gap['link'] )
			);
		}
	}

	/**
	 * The last look before anything is written.
	 *
	 * Shown on every write until somebody turns it off, which is the whole
	 * point: the parts a post is made of are decided on a tab most people
	 * never open, and the first they hear of a Sources block is finding one
	 * at the bottom of a finished draft.
	 *
	 * The switches here are the same blueprint fields as the Shape tab, so
	 * this is a view of the brief rather than a second set of settings to
	 * keep in sync. It is rendered inside the form for the same reason:
	 * whatever is ticked here is what posts.
	 *
	 * @return void
	 */
	private static function render_confirm() {
		if ( ! Blogcraft_Settings::get( 'ask_before_writing' ) ) {
			return;
		}

		$bp = Blogcraft_Blueprint::get();

		echo '<div class="bc-confirm" id="bc-confirm" hidden>';
		echo '<div class="bc-confirm-sheet" role="dialog" aria-modal="true" aria-labelledby="bc-confirm-title">';

		echo '<div class="bc-confirm-head">';
		echo '<h2 id="bc-confirm-title">' . esc_html__( 'Before it writes', 'blogcraft' ) . '</h2>';
		echo '<p>' . esc_html__( 'Everything here is what the model is told before a word is written. It is the whole difference between a post that answers a question properly and one that reads like every other AI post — which is why it is worth thirty seconds rather than being buried in a settings tab.', 'blogcraft' ) . '</p>';
		echo '</div>';

		echo '<div class="bc-confirm-body">';

		self::render_gaps();

		printf(
			'<h3 class="bc-confirm-section">%s</h3>',
			esc_html__( 'What this post will include', 'blogcraft' )
		);
		printf(
			'<p class="bc-confirm-note">%s</p>',
			esc_html__( 'These change this post only. Your standing answers are already ticked.', 'blogcraft' )
		);

		// Five of these already have a switch on the Shape tab. Emitting a
		// second input with the same name would put two answers to one
		// question in the form, and whichever rendered last would silently
		// win. So those rows carry no name: they drive the tab's own switch
		// through data-for, and the form keeps exactly one input per field.
		$on_tab = array( 'takeaways', 'faq', 'toc', 'tables', 'lists' );

		foreach ( self::parts() as $field => $part ) {
			$proxy = in_array( $field, $on_tab, true );

			printf(
				'<label class="bc-confirm-part">'
				. '<input type="checkbox" %1$s value="1"%2$s />'
				. '<span><strong>%3$s</strong><span>%4$s</span></span>'
				. '</label>',
				// The o_ prefix is not decoration: overrides_from() looks for
				// exactly 'o_' . $key, so a bare name here would post a value
				// nothing reads — and, because an absent toggle counts as off,
				// every one of these would be switched off on every post.
				$proxy
					? 'data-for="bc_o_' . esc_attr( $field ) . '"'
					: 'name="o_' . esc_attr( $field ) . '"',
				checked( ! empty( $bp[ $field ] ), true, false ),
				esc_html( $part[0] ),
				esc_html( $part[1] )
			);
		}

		echo '</div>';

		echo '<div class="bc-confirm-foot">';

		printf(
			'<label class="bc-confirm-quiet"><input type="checkbox" name="stop_asking" value="1" /> %s</label>',
			esc_html__( 'Stop asking me this', 'blogcraft' )
		);

		echo '<div class="bc-confirm-buttons">';
		printf(
			'<button type="button" class="button" id="bc-confirm-back">%s</button>',
			esc_html__( 'Back to the brief', 'blogcraft' )
		);
		printf(
			'<button type="submit" class="bc-save">%s</button>',
			esc_html__( 'Write it now', 'blogcraft' )
		);
		echo '</div>';

		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * The jobs that are not writing one post.
	 *
	 * Queueing a list and trashing a batch used to sit open on this screen,
	 * below the composer, along with four raw queue counters that said the
	 * same thing as the library screen only worse. So the page you visit
	 * most often ended with a red button that trashes a day of posts.
	 *
	 * Folded away rather than removed: they are real jobs, they are just not
	 * this one, and somebody looking for them knows they exist.
	 *
	 * @return void
	 */
	private static function render_more() {
		$waiting = Blogcraft_Queue::count_by_status( 'pending' ) + Blogcraft_Queue::count_by_status( 'running' );

		echo '<details class="bc-more">';
		printf(
			'<summary><span>%1$s</span><span class="bc-more-hint">%2$s</span></summary>',
			esc_html__( 'More than one at a time', 'blogcraft' ),
			esc_html__( 'Queue a list, run a stuck job, undo a batch', 'blogcraft' )
		);

		echo '<div class="bc-more-body">';

		printf(
			'<p class="bc-more-count">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html(
				sprintf(
					/* translators: %d: how many posts are queued or being written. */
					_n( '%d post is queued or being written.', '%d posts are queued or being written.', $waiting, 'blogcraft' ),
					$waiting
				)
			),
			esc_url( admin_url( 'admin.php?page=' . Blogcraft_Library::PAGE_SLUG ) ),
			esc_html__( 'See everything written by AI', 'blogcraft' )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bc-more-block">';
		echo '<input type="hidden" name="action" value="blogcraft_bulk_topics" />';
		Blogcraft_Request::nonce_field( self::BULK_ACTION );
		printf(
			'<label for="blogcraft_topics"><strong>%1$s</strong></label>',
			esc_html__( 'Queue a list of topics', 'blogcraft' )
		);
		echo '<textarea class="large-text code" name="topics" id="blogcraft_topics" rows="5" placeholder="' . esc_attr__( 'One topic per line, or paste a CSV column', 'blogcraft' ) . '"></textarea>';
		echo '<p class="description">' . esc_html__( 'These use your standing rules, not the brief above, and are written unattended. Repeats are skipped, whether the post already exists or is only queued.', 'blogcraft' ) . '</p>';
		submit_button( __( 'Queue all of these', 'blogcraft' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bc-more-block">';
		echo '<input type="hidden" name="action" value="blogcraft_run_queue_now" />';
		Blogcraft_Request::nonce_field( self::RUN_ACTION );
		printf( '<p><strong>%s</strong></p>', esc_html__( 'Push the queue along', 'blogcraft' ) );
		echo '<p class="description">' . esc_html__( 'Posts written here run in your browser and need none of this. It is for a queued job that has stopped moving on a site where scheduled tasks do not fire.', 'blogcraft' ) . '</p>';
		submit_button( __( 'Run the queue now', 'blogcraft' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bc-more-block is-danger" onsubmit="return confirm(' . esc_attr( "'" . esc_js( __( 'Move recently generated posts to the trash?', 'blogcraft' ) ) . "'" ) . ');">';
		echo '<input type="hidden" name="action" value="blogcraft_rollback" />';
		Blogcraft_Request::nonce_field( self::ROLLBACK_ACTION );
		printf( '<p><strong>%s</strong></p>', esc_html__( 'Undo a batch', 'blogcraft' ) );
		echo '<p class="description">' . esc_html__( 'Trashes posts Blogcraft created in the last 24 hours. Anything you wrote yourself is left alone.', 'blogcraft' ) . '</p>';
		submit_button( __( 'Trash the last 24 hours', 'blogcraft' ), 'delete', 'submit', false );
		echo '</form>';

		echo '</div>';
		echo '</details>';
	}

	/**
	 * Open a card section.
	 *
	 * @param string $title       Card title.
	 * @param string $description One line on what it is for.
	 * @return void
	 */
	private static function card_open( $title, $description ) {
		printf(
			'<section class="blogcraft-card"><header><h2>%1$s</h2><p>%2$s</p></header>',
			esc_html( $title ),
			esc_html( $description )
		);
	}

	/**
	 * Read blueprint overrides out of the submitted form.
	 *
	 * @param array $source Unslashed request data.
	 * @return array Sparse field values.
	 */
	/**
	 * The blueprint fields the composer lets a post override.
	 *
	 * Named explicitly rather than inferred from the request. Toggles post
	 * nothing when switched off, so without a known list there is no way to
	 * tell "the user turned the FAQ off" from "the field was never rendered",
	 * and one of those must not silently become the other.
	 *
	 * @return array Keys: text, toggle, multi.
	 */
	private static function override_fields() {
		return array(
			'text'   => array(
				'word_target',
				'sections_min',
				'sections_max',
				'intro_style',
				'conclusion_style',
				'tone',
				'tone_custom',
				'point_of_view',
				'audience',
				'audience_custom',
				'reading_level',
				'sentence_max_words',
				'primary_keyword',
				'secondary_keywords',
				'required_terms',
				'external_links_target',
				'banned_phrases',
				'negative_keywords',
				'avoid_subjects',
				'images_target',
				'image_style',
				'image_mood',
				'image_subject',
				'image_shape',
				'image_palette',
				'image_extra',
				'image_avoid',
			),
			'toggle' => array(
				'takeaways',
				'faq',
				'toc',
				'tables',
				'lists',
				'sentence_variety',
				'allow_contractions',
				'allow_em_dash',
				'require_experience',
				'require_citations',
				'require_statistics',
				'image_describe',
				'image_allow_text',
				// The five block_* extras. These were absent for a long time,
				// and had to be: a toggle with no control is read as "absent,
				// so switched off" by overrides_from() below, which silently
				// forced every one of them to false on every post the composer
				// wrote, whatever the blueprint said.
				//
				// They have a control now — the panel that opens before writing
				// starts — so they can be overridden per post. If that panel is
				// ever removed, these five come out of this list with it.
				'block_sources',
				'block_audience',
				'block_proscons',
				'block_figures',
				'block_mistakes',
			),
			'multi'  => array( 'literary_devices' ),
		);
	}

	/**
	 * Read this post's brief out of the submitted form.
	 *
	 * @param array $source Unslashed request data.
	 * @return array Sparse blueprint field values.
	 */
	private static function overrides_from( $source ) {
		$fields = self::override_fields();
		$out    = array();

		// Only treat this as an override submission when the composer was the
		// thing that posted, so the bulk form cannot blank the brief.
		if ( ! isset( $source['bc_compose'] ) ) {
			return $out;
		}

		foreach ( $fields['text'] as $key ) {
			$field = 'o_' . $key;

			if ( ! isset( $source[ $field ] ) || is_array( $source[ $field ] ) ) {
				continue;
			}

			$value = trim( (string) $source[ $field ] );

			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		// A toggle absent from the post means the user switched it off.
		foreach ( $fields['toggle'] as $key ) {
			$out[ $key ] = isset( $source[ 'o_' . $key ] );
		}

		foreach ( $fields['multi'] as $key ) {
			$field  = 'o_' . $key;
			$chosen = isset( $source[ $field ] ) && is_array( $source[ $field ] ) ? $source[ $field ] : array();
			$clean  = array();

			foreach ( $chosen as $value ) {
				$value = sanitize_key( (string) $value );

				if ( '' !== $value ) {
					$clean[] = $value;
				}
			}

			$out[ $key ] = implode( ',', $clean );
		}

		return $out;
	}

	/*
	 * Blogcraft_Controls escapes every value as it builds its markup, and
	 * outcome_html() does the same. PHPCS cannot follow a string across a method
	 * boundary, so it reads every echo below as raw output. Scoped off here
	 * rather than annotated a dozen times, which would bury the one place that
	 * genuinely needs reading.
	 */
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

	/**
	 * The composer: topic, the full brief for this post, and what it will produce.
	 *
	 * This is where the work happens, so everything needed to decide "queue this
	 * or change something first" is on one screen. The panel on the right is the
	 * point: it shows the shape of the post, how the word budget divides, what
	 * the run will roughly cost, and whether the topic clashes with something
	 * already written — all before a token is spent.
	 *
	 * @return void
	 */
	private static function render_composer() {
		$blueprint = Blogcraft_Blueprint::get();

		echo '<div class="bc-compose">';
		echo '<div class="bc-compose-main">';

		self::render_topic();
		self::render_brief_tabs( $blueprint );

		echo '</div>';

		self::render_outcome( $blueprint );

		echo '</div>';
	}

	/**
	 * What this brief is missing, and what missing it costs.
	 *
	 * The scorecard judges the finished post, which is too late to change what
	 * went into it. This judges the brief, before anything is spent — and
	 * names the cost of each gap rather than demanding the field be filled,
	 * because a plugin that requires twenty inputs before it will do anything
	 * is one people uninstall on the first attempt.
	 *
	 * @return void
	 */
	private static function render_readiness() {
		// Assessed from what is stored rather than what is typed: the panel
		// re-renders on load, and the per-post fields start empty by design.
		$state = Blogcraft_Readiness::assess( '', '', '' );

		$missing = array();

		foreach ( $state['items'] as $item ) {
			if ( ! $item['ok'] && in_array( $item['key'], array( 'voice', 'research' ), true ) ) {
				$missing[] = $item;
			}
		}

		if ( empty( $missing ) ) {
			return;
		}

		echo '<aside class="bc-readiness">';
		echo '<h2>' . esc_html__( 'Before you write', 'blogcraft' ) . '</h2>';
		echo '<p>' . esc_html__( 'These are set once and used by every post afterwards. Skipping them is the difference between a post that sounds like your blog and one that sounds like every other AI blog.', 'blogcraft' ) . '</p>';
		echo '<ul>';

		foreach ( $missing as $item ) {
			printf(
				'<li><strong>%1$s</strong><span>%2$s</span></li>',
				esc_html( $item['label'] ),
				esc_html( $item['why'] )
			);
		}

		echo '</ul>';
		printf(
			'<p><a class="button button-small" href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=blogcraft-settings' ) ),
			esc_html__( 'Set them up', 'blogcraft' )
		);
		echo '</aside>';
	}

	/**
	 * Turn the topic into questions worth answering.
	 *
	 * @return void
	 */
	public static function handle_suggest() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'blogcraft' ) ), 403 );
		}

		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! Blogcraft_Request::verify( self::QUEUE_ACTION, $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'That form has expired. Reload the page.', 'blogcraft' ) ), 403 );
		}

		$topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === trim( $topic ) ) {
			wp_send_json_error( array( 'message' => __( 'Write a topic first.', 'blogcraft' ) ), 400 );
		}

		try {
			wp_send_json_success( Blogcraft_Readiness::suggest_for( $topic ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 200 );
		}
	}

	/**
	 * Say so before starting, when the provider is already refusing.
	 *
	 * A post costs eight to ten separate calls, so starting one into an
	 * exhausted quota does not fail cleanly at the door — it gets several
	 * stages in, spends whatever those cost, and then parks until the limit
	 * clears. Better to say it up front.
	 *
	 * Deliberately not phrased as "you have N requests left". No provider in
	 * the list exposes a remaining-quota figure, and the only place a free
	 * tier ever states its limit is inside the error after you exceed it, so
	 * any number here would be invented. What is known is that the provider
	 * refused, what it said, and when the waiting job resumes.
	 *
	 * @return void
	 */
	private static function render_rate_limit_notice() {
		$limit = Blogcraft_Queue::rate_limited_until();

		if ( empty( $limit ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning bc-quota-notice"><p><strong>%1$s</strong> %2$s</p>%3$s</div>',
			esc_html__( 'Your provider is rate limiting.', 'blogcraft' ),
			esc_html(
				sprintf(
					/* translators: %s: a clock time, such as "3:45 pm". */
					__( 'A post already being written resumes at about %s. Starting another now will most likely stop part-way too, and the stages it runs first still cost you.', 'blogcraft' ),
					wp_date( get_option( 'time_format' ), (int) $limit['resumes'] )
				)
			),
			'' === trim( $limit['reason'] )
				? ''
				: '<p><code>' . esc_html( $limit['reason'] ) . '</code></p>'
		);
	}

	/**
	 * The topic field and the things that belong beside it.
	 *
	 * @return void
	 */
	private static function render_topic() {
		self::render_rate_limit_notice();

		echo '<section class="bc-pane bc-pane-topic">';

		echo Blogcraft_Controls::row(
			__( 'Topic', 'blogcraft' ),
			__( 'A sentence works better than a keyword. Say what the post should actually answer.', 'blogcraft' ),
			'<input type="text" class="bc-text bc-text-lead" name="topic" id="bc_topic" value="" required autocomplete="off" placeholder="' . esc_attr__( 'How to choose a standing desk for a small home office', 'blogcraft' ) . '" /><p class="bc-only-this">' . esc_html__( 'This is the only thing you have to fill in. Everything below already has an answer, taken from your standing rules.', 'blogcraft' ) . '</p><p class="bc-clash" id="bc-clash" hidden></p>',
			'bc_topic'
		);

		echo Blogcraft_Controls::row(
			__( 'Angle for this post', 'blogcraft' ),
			__( 'Anything true of this one post only. This is what stops every post reading the same.', 'blogcraft' ),
			Blogcraft_Controls::area( 'instructions', '', __( 'Compare three price brackets and say which is worth it.', 'blogcraft' ), 2 ),
			'bc_instructions'
		);

		echo Blogcraft_Controls::row(
			__( 'What you know that nobody else does', 'blogcraft' ),
			__( 'Your own numbers, results, prices, or what happened when you tried it. This is the only part of a post a model cannot produce, and it is what separates a page worth reading from a summary of pages that already exist. Everything here is used as fact and never invented beyond.', 'blogcraft' ),
			Blogcraft_Controls::area(
				'evidence',
				'',
				__( 'We tested 9 desks over 4 months. The £220 bracket wobbled above 110cm; the £400 bracket did not. Our own returns rate on the cheapest was 3 in 9.', 'blogcraft' ),
				4
			),
			'bc_evidence'
		);

		// "What do you know that nobody else does" is a hard question asked
		// cold, and an empty answer is the single commonest reason a finished
		// post reads like every other AI post. Asked about a specific topic it
		// becomes easy, so this offers to ask it properly.
		printf(
			'<div class="bc-suggest">'
			. '<button type="button" class="button" id="blogcraft-suggest">%1$s</button>'
			. '<p class="description">%2$s</p>'
			. '<div class="bc-suggest-out" id="blogcraft-suggest-out" hidden>'
			. '<p class="bc-suggest-lead">%3$s</p><ul id="blogcraft-suggest-list"></ul></div>'
			. '</div>',
			esc_html__( 'What should I write about this?', 'blogcraft' ),
			esc_html__( 'Reads your topic and asks four questions only you can answer. It never answers them for you — invented facts are exactly what this field exists to avoid.', 'blogcraft' ),
			esc_html__( 'Answer any of these in the box above, in your own words:', 'blogcraft' )
		);

		echo Blogcraft_Controls::row(
			__( 'When it is finished', 'blogcraft' ),
			'',
			Blogcraft_Controls::segmented(
				'status',
				array(
					'draft'   => __( 'Save as a draft', 'blogcraft' ),
					'publish' => __( 'Publish it', 'blogcraft' ),
				),
				'draft'
			)
		);

		echo '</section>';
	}

	/**
	 * The full brief for this post, grouped behind tabs.
	 *
	 * Every field is prefixed so it overrides the saved blueprint for this post
	 * alone. Defaults are pre-filled from the blueprint rather than left blank,
	 * because a screen of empty fields hides what will actually happen.
	 *
	 * @param array $bp Resolved blueprint.
	 * @return void
	 */
	private static function render_brief_tabs( $bp ) {
		echo '<div class="bc-tabs-head">';
		echo '<h2>' . esc_html__( 'Everything else about this post', 'blogcraft' ) . '</h2>';
		echo '<p>' . esc_html__( 'These start from your standing rules and change this post only. Pictures and Publishing are here too.', 'blogcraft' ) . '</p>';
		echo '</div>';

		echo '<div class="bc-tabs" role="tablist">';

		$tabs = array(
			'shape'    => __( 'Shape', 'blogcraft' ),
			'voice'    => __( 'Voice', 'blogcraft' ),
			'seo'      => __( 'Search', 'blogcraft' ),
			'human'    => __( 'Sounding human', 'blogcraft' ),
			'pictures' => __( 'Pictures', 'blogcraft' ),
			'publish'  => __( 'Publishing', 'blogcraft' ),
		);

		$first = true;

		foreach ( $tabs as $slug => $label ) {
			printf(
				'<button type="button" class="bc-tab%1$s" data-tab="%2$s" role="tab" aria-selected="%3$s">%4$s</button>',
				$first ? ' is-active' : '',
				esc_attr( $slug ),
				$first ? 'true' : 'false',
				esc_html( $label )
			);
			$first = false;
		}

		echo '</div>';

		self::tab_shape( $bp );
		self::tab_voice( $bp );
		self::tab_seo( $bp );
		self::tab_human( $bp );
		self::tab_pictures( $bp );
		self::tab_publish();
	}

	/**
	 * Open one tab panel.
	 *
	 * @param string $slug   Tab slug.
	 * @param bool   $active Whether it starts visible.
	 * @return void
	 */
	private static function tab_open( $slug, $active = false ) {
		printf(
			'<section class="bc-pane bc-tabpanel%1$s" data-tab="%2$s" role="tabpanel"%3$s>',
			$active ? ' is-active' : '',
			esc_attr( $slug ),
			$active ? '' : ' hidden'
		);
	}

	/**
	 * Structure controls for this post.
	 *
	 * @param array $bp Blueprint.
	 * @return void
	 */
	private static function tab_shape( $bp ) {
		self::tab_open( 'shape', true );

		$rows = array(
			Blogcraft_Controls::row(
				__( 'Length', 'blogcraft' ),
				__( 'Measured on the finished draft.', 'blogcraft' ),
				Blogcraft_Controls::slider( 'o_word_target', 300, 4000, 50, $bp['word_target'], __( ' words', 'blogcraft' ) ),
				'bc_o_word_target'
			),
			Blogcraft_Controls::row(
				__( 'Fewest sections', 'blogcraft' ),
				'',
				Blogcraft_Controls::slider( 'o_sections_min', 1, 12, 1, $bp['sections_min'] ),
				'bc_o_sections_min'
			),
			Blogcraft_Controls::row(
				__( 'Most sections', 'blogcraft' ),
				'',
				Blogcraft_Controls::slider( 'o_sections_max', 1, 15, 1, $bp['sections_max'] ),
				'bc_o_sections_max'
			),
			Blogcraft_Controls::row(
				__( 'How it opens', 'blogcraft' ),
				'',
				Blogcraft_Controls::select( 'o_intro_style', Blogcraft_Blueprint::intro_styles(), $bp['intro_style'] ),
				'bc_o_intro_style'
			),
			Blogcraft_Controls::row(
				__( 'How it ends', 'blogcraft' ),
				'',
				Blogcraft_Controls::select( 'o_conclusion_style', Blogcraft_Blueprint::conclusion_styles(), $bp['conclusion_style'] ),
				'bc_o_conclusion_style'
			),
			Blogcraft_Controls::row(
				__( 'Include', 'blogcraft' ),
				'',
				Blogcraft_Controls::toggle( 'o_takeaways', $bp['takeaways'], __( 'Key takeaways', 'blogcraft' ) )
				. Blogcraft_Controls::toggle( 'o_faq', $bp['faq'], __( 'Questions and answers', 'blogcraft' ) )
				. Blogcraft_Controls::toggle( 'o_toc', $bp['toc'], __( 'Table of contents', 'blogcraft' ) )
				. Blogcraft_Controls::toggle( 'o_tables', $bp['tables'], __( 'Tables', 'blogcraft' ) )
				. Blogcraft_Controls::toggle( 'o_lists', $bp['lists'], __( 'Bulleted lists', 'blogcraft' ) )
			),
		);

		echo implode( '', $rows );
		echo '</section>';
	}

	/**
	 * Voice controls for this post.
	 *
	 * @param array $bp Blueprint.
	 * @return void
	 */
	private static function tab_voice( $bp ) {
		self::tab_open( 'voice' );

		$rows = array(
			Blogcraft_Controls::row(
				__( 'Tone', 'blogcraft' ),
				'',
				Blogcraft_Controls::select( 'o_tone', Blogcraft_Blueprint::tones(), $bp['tone'] ),
				'bc_o_tone'
			),
			Blogcraft_Controls::row(
				__( 'Describe the tone', 'blogcraft' ),
				__( 'Used only when the tone above is set to something else.', 'blogcraft' ),
				Blogcraft_Controls::text( 'o_tone_custom', $bp['tone_custom'], __( 'Dry, a little sceptical', 'blogcraft' ) ),
				'bc_o_tone_custom'
			),
			Blogcraft_Controls::row(
				__( 'Who is speaking', 'blogcraft' ),
				'',
				Blogcraft_Controls::segmented( 'o_point_of_view', Blogcraft_Blueprint::points_of_view(), $bp['point_of_view'] )
			),
			Blogcraft_Controls::row(
				__( 'Who is reading', 'blogcraft' ),
				'',
				Blogcraft_Controls::select( 'o_audience', Blogcraft_Blueprint::audiences(), $bp['audience'] ),
				'bc_o_audience'
			),
			Blogcraft_Controls::row(
				__( 'Describe the reader', 'blogcraft' ),
				'',
				Blogcraft_Controls::text( 'o_audience_custom', $bp['audience_custom'], __( 'People setting up a first home office', 'blogcraft' ) ),
				'bc_o_audience_custom'
			),
			Blogcraft_Controls::row(
				__( 'Reading level', 'blogcraft' ),
				__( 'Measured as a Flesch Reading Ease band.', 'blogcraft' ),
				Blogcraft_Controls::select( 'o_reading_level', self::reading_labels(), $bp['reading_level'] ),
				'bc_o_reading_level'
			),
			Blogcraft_Controls::row(
				__( 'Longest sentence', 'blogcraft' ),
				__( 'Measured.', 'blogcraft' ),
				Blogcraft_Controls::slider( 'o_sentence_max_words', 12, 50, 1, $bp['sentence_max_words'], __( ' words', 'blogcraft' ) ),
				'bc_o_sentence_max_words'
			),
		);

		echo implode( '', $rows );
		echo '</section>';
	}

	/**
	 * Reading level labels, without their bands.
	 *
	 * @return array
	 */
	private static function reading_labels() {
		$out = array();

		foreach ( Blogcraft_Blueprint::reading_levels() as $key => $spec ) {
			$out[ $key ] = $spec[0];
		}

		return $out;
	}

	/**
	 * Search controls for this post.
	 *
	 * @param array $bp Blueprint.
	 * @return void
	 */
	private static function tab_seo( $bp ) {
		self::tab_open( 'seo' );

		$rows = array(
			Blogcraft_Controls::row(
				__( 'Target phrase', 'blogcraft' ),
				__( 'Measured. Leave blank to let the topic speak for itself.', 'blogcraft' ),
				Blogcraft_Controls::text( 'o_primary_keyword', $bp['primary_keyword'], __( 'standing desk', 'blogcraft' ) ),
				'bc_o_primary_keyword'
			),
			Blogcraft_Controls::row(
				__( 'Also cover', 'blogcraft' ),
				__( 'One per line.', 'blogcraft' ),
				Blogcraft_Controls::area( 'o_secondary_keywords', $bp['secondary_keywords'], "adjustable desk\nsit stand desk" ),
				'bc_o_secondary_keywords'
			),
			Blogcraft_Controls::row(
				__( 'Must appear', 'blogcraft' ),
				__( 'One per line. Measured — a missing term is reported back and rewritten.', 'blogcraft' ),
				Blogcraft_Controls::area( 'o_required_terms', $bp['required_terms'], "ergonomics\nanti-fatigue mat" ),
				'bc_o_required_terms'
			),
			Blogcraft_Controls::row(
				__( 'Sources to cite', 'blogcraft' ),
				__( 'Measured.', 'blogcraft' ),
				Blogcraft_Controls::slider( 'o_external_links_target', 0, 10, 1, $bp['external_links_target'] ),
				'bc_o_external_links_target'
			),
		);

		echo implode( '', $rows );
		echo '</section>';
	}

	/**
	 * Authenticity controls for this post.
	 *
	 * @param array $bp Blueprint.
	 * @return void
	 */
	private static function tab_human( $bp ) {
		self::tab_open( 'human' );

		$rows = array(
			Blogcraft_Controls::row(
				__( 'Devices to use', 'blogcraft' ),
				'',
				Blogcraft_Controls::chips(
					'o_literary_devices',
					Blogcraft_Blueprint::literary_devices(),
					Blogcraft_Blueprint::chosen( $bp, 'literary_devices' )
				)
			),
			Blogcraft_Controls::row(
				__( 'Habits', 'blogcraft' ),
				'',
				Blogcraft_Controls::toggle( 'o_sentence_variety', $bp['sentence_variety'], __( 'Vary sentence length', 'blogcraft' ) )
				. Blogcraft_Controls::toggle( 'o_allow_contractions', $bp['allow_contractions'], __( 'Allow contractions', 'blogcraft' ) )
				. Blogcraft_Controls::toggle( 'o_allow_em_dash', $bp['allow_em_dash'], __( 'Allow em dashes', 'blogcraft' ) )
			),
			Blogcraft_Controls::row(
				__( 'Demand', 'blogcraft' ),
				'',
				Blogcraft_Controls::toggle( 'o_require_experience', $bp['require_experience'], __( 'First-hand, specific detail', 'blogcraft' ) )
				. Blogcraft_Controls::toggle( 'o_require_citations', $bp['require_citations'], __( 'A named source for claims', 'blogcraft' ) )
				. Blogcraft_Controls::toggle( 'o_require_statistics', $bp['require_statistics'], __( 'Concrete figures', 'blogcraft' ) )
			),
			Blogcraft_Controls::row(
				__( 'Never write', 'blogcraft' ),
				__( 'One per line. Measured.', 'blogcraft' ),
				Blogcraft_Controls::area( 'o_banned_phrases', $bp['banned_phrases'], "delve into\nin today's fast-paced world", 3 ),
				'bc_o_banned_phrases'
			),
			Blogcraft_Controls::row(
				__( 'Never mention', 'blogcraft' ),
				__( 'One per line. Competitors, brands, claims that must not appear at all. Measured, weighted heavily.', 'blogcraft' ),
				Blogcraft_Controls::area( 'o_negative_keywords', $bp['negative_keywords'], __( "a competitor's name", 'blogcraft' ), 3 ),
				'bc_o_negative_keywords'
			),
			Blogcraft_Controls::row(
				__( 'Steer clear of', 'blogcraft' ),
				__( 'One per line. Subjects to avoid even in passing.', 'blogcraft' ),
				Blogcraft_Controls::area( 'o_avoid_subjects', $bp['avoid_subjects'], __( 'medical advice', 'blogcraft' ), 3 ),
				'bc_o_avoid_subjects'
			),
		);

		echo implode( '', $rows );
		echo '</section>';
	}

	/**
	 * How the pictures for this post should look.
	 *
	 * The same controls as the blueprint's Pictures pane, so a post can be
	 * illustrated differently from the rest of the blog without editing the
	 * standing rules and remembering to put them back.
	 *
	 * @param array $bp Blueprint.
	 * @return void
	 */
	private static function tab_pictures( $bp ) {
		self::tab_open( 'pictures' );

		$rows = array(
			Blogcraft_Controls::row(
				__( 'Featured image', 'blogcraft' ),
				'',
				Blogcraft_Controls::toggle( 'o_image_describe', $bp['image_describe'], __( 'Let the model describe the picture for this post', 'blogcraft' ) )
				. self::pictures_note()
			),
			Blogcraft_Controls::row(
				__( 'Pictures in the body', 'blogcraft' ),
				__( 'One beneath each section heading, up to this many.', 'blogcraft' ),
				Blogcraft_Controls::slider( 'o_images_target', 0, 6, 1, $bp['images_target'] )
			),
			Blogcraft_Controls::row(
				__( 'Treatment', 'blogcraft' ),
				'',
				Blogcraft_Controls::select( 'o_image_style', Blogcraft_Art_Direction::styles(), $bp['image_style'] ),
				'bc_o_image_style'
			),
			Blogcraft_Controls::row(
				__( 'Mood', 'blogcraft' ),
				'',
				Blogcraft_Controls::select( 'o_image_mood', Blogcraft_Art_Direction::moods(), $bp['image_mood'] ),
				'bc_o_image_mood'
			),
			Blogcraft_Controls::row(
				__( 'What it shows', 'blogcraft' ),
				__( 'The angle every picture takes on its subject.', 'blogcraft' ),
				Blogcraft_Controls::select( 'o_image_subject', Blogcraft_Art_Direction::subjects(), $bp['image_subject'] ),
				'bc_o_image_subject'
			),
			Blogcraft_Controls::row(
				__( 'Shape', 'blogcraft' ),
				'',
				Blogcraft_Controls::segmented( 'o_image_shape', Blogcraft_Art_Direction::shapes(), $bp['image_shape'] )
			),
			Blogcraft_Controls::row(
				__( 'Colours', 'blogcraft' ),
				__( 'In words. Leave blank to let each picture suit its own subject.', 'blogcraft' ),
				Blogcraft_Controls::text( 'o_image_palette', $bp['image_palette'], __( 'muted greens, warm oak, off-white', 'blogcraft' ) ),
				'bc_o_image_palette'
			),
			Blogcraft_Controls::row(
				__( 'Anything else', 'blogcraft' ),
				__( 'Added to every image prompt as written.', 'blogcraft' ),
				Blogcraft_Controls::area( 'o_image_extra', $bp['image_extra'], __( 'shot from slightly above, shallow depth of field', 'blogcraft' ), 2 ),
				'bc_o_image_extra'
			),
			Blogcraft_Controls::row(
				__( 'Never show', 'blogcraft' ),
				__( 'Things that keep appearing and should not.', 'blogcraft' ),
				Blogcraft_Controls::area( 'o_image_avoid', $bp['image_avoid'], __( 'crowds, brand names, hands holding phones', 'blogcraft' ), 2 ),
				'bc_o_image_avoid'
			),
			Blogcraft_Controls::row(
				__( 'Words in the picture', 'blogcraft' ),
				__( 'Image models render lettering as convincing gibberish, so text is excluded by default.', 'blogcraft' ),
				Blogcraft_Controls::toggle( 'o_image_allow_text', $bp['image_allow_text'], __( 'Allow text in generated images', 'blogcraft' ) )
			),
		);

		echo implode( '', $rows );
		echo '</section>';
	}

	/**
	 * Which service is making these pictures, and a way to get there.
	 *
	 * These controls decide how a picture looks; a different screen decides who
	 * draws it. Naming that screen without linking to it was asking the reader
	 * to go and find a select they had never seen.
	 *
	 * @return string
	 */
	private static function pictures_note() {
		$link = admin_url( 'admin.php?page=blogcraft-settings#bc-card-pictures' );

		if ( ! Blogcraft_Settings::get( 'images_enabled' ) ) {
			return sprintf(
				'<p class="bc-hint">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html__( 'Pictures are switched off, so nothing here will run.', 'blogcraft' ),
				esc_url( $link ),
				esc_html__( 'Turn them on', 'blogcraft' )
			);
		}

		$providers = Blogcraft_Images::providers();
		$chosen    = (string) Blogcraft_Settings::get( 'image_provider' );
		$name      = isset( $providers[ $chosen ] ) ? $providers[ $chosen ] : $chosen;

		return sprintf(
			'<p class="bc-hint">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html(
				sprintf(
					/* translators: %s: the picture service currently chosen. */
					__( 'The article decides what the picture shows; these decide how it looks. Drawn right now by: %s.', 'blogcraft' ),
					$name
				)
			),
			esc_url( $link ),
			esc_html__( 'Change the service', 'blogcraft' )
		);
	}

	/**
	 * Where the finished post lands.
	 *
	 * Answers the question people ask first and the screen never used to: what
	 * happens to this when it is done, and where do I find it.
	 *
	 * @return void
	 */
	private static function tab_publish() {
		self::tab_open( 'publish' );

		$categories = wp_dropdown_categories(
			array(
				'name'              => 'post_category',
				'id'                => 'bc_post_category',
				'class'             => 'bc-select',
				'show_option_none'  => __( 'Whatever the site default is', 'blogcraft' ),
				'option_none_value' => 0,
				'hide_empty'        => false,
				'echo'              => false,
				'orderby'           => 'name',
			)
		);

		$authors = wp_dropdown_users(
			array(
				'name'              => 'post_author',
				'id'                => 'bc_post_author',
				'class'             => 'bc-select',
				'show_option_none'  => __( 'Me', 'blogcraft' ),
				'option_none_value' => 0,
				'echo'              => false,
				'capability'        => array( 'edit_posts' ),
			)
		);

		$rows = array(
			Blogcraft_Controls::row(
				__( 'Category', 'blogcraft' ),
				'',
				(string) $categories,
				'bc_post_category'
			),
			Blogcraft_Controls::row(
				__( 'Tags', 'blogcraft' ),
				__( 'Comma separated. Left blank, no tags are added.', 'blogcraft' ),
				Blogcraft_Controls::text( 'post_tags', '', __( 'cold brew, coffee gear', 'blogcraft' ) ),
				'bc_post_tags'
			),
			Blogcraft_Controls::row(
				__( 'Credited to', 'blogcraft' ),
				__( 'Whose byline appears on the post. A named author with stated credentials is a real trust signal, and it is published as structured data.', 'blogcraft' ),
				(string) $authors,
				'bc_post_author'
			),
			Blogcraft_Controls::row(
				__( 'Publish at', 'blogcraft' ),
				__( 'Leave blank to publish as soon as it is written. Only applies if you chose to publish rather than save a draft.', 'blogcraft' ),
				'<input type="datetime-local" class="bc-text" name="publish_at" id="bc_publish_at" value="" />',
				'bc_publish_at'
			),
		);

		echo implode( '', $rows );
		echo '</section>';
	}

	/**
	 * What this post will come out as.
	 *
	 * @param array $blueprint Blueprint.
	 * @return void
	 */
	private static function render_outcome( $blueprint ) {
		// One column, not two children of the grid. These were siblings, so
		// the readiness panel took the sidebar and the outcome dropped to the
		// next row of the first column — landing underneath the tab panels, a
		// screen away from the fields it claims to describe.
		echo '<div class="bc-compose-side">';

		self::render_readiness();

		echo '<aside class="bc-outcome" aria-labelledby="bc-outcome-title">';
		echo '<div class="bc-outcome-head">';
		echo '<h2 id="bc-outcome-title">' . esc_html__( 'What you will get', 'blogcraft' ) . '</h2>';
		echo '<p>' . esc_html__( 'The shape of the post, not its words. Updates as you change anything.', 'blogcraft' ) . '</p>';
		echo '</div>';

		printf(
			'<div class="bc-outcome-body" id="bc-outcome-body" aria-live="polite">%s</div>',
			self::outcome_html( $blueprint )
		);

		echo '</aside>';
		echo '</div>';
	}

	/**
	 * Render the predicted shape, budget and cost.
	 *
	 * @param array $blueprint Blueprint.
	 * @return string
	 */
	public static function outcome_html( $blueprint ) {
		$shape    = Blogcraft_Preview::shape( $blueprint );
		$warnings = Blogcraft_Preview::warnings( $blueprint, $shape );
		$tokens   = Blogcraft_Preview::tokens( $blueprint );

		$out = '<ol class="bc-shape">';

		foreach ( $shape as $block ) {
			$words = ( $block['words'] > 0 )
				? sprintf(
					'<span class="bc-shape-words">%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: approximate word count. */
							__( '~%d words', 'blogcraft' ),
							(int) $block['words']
						)
					)
				)
				: '';

			$note = ( '' === $block['note'] )
				? ''
				: sprintf( '<span class="bc-shape-note">%s</span>', esc_html( $block['note'] ) );

			$out .= sprintf(
				'<li class="bc-shape-%1$s"><span class="bc-shape-label">%2$s</span>%3$s%4$s</li>',
				esc_attr( $block['type'] ),
				esc_html( $block['label'] ),
				$note,
				$words
			);
		}

		$out .= '</ol>';

		$out .= '<div class="bc-outcome-figures">';
		$out .= sprintf(
			'<div><span class="bc-figure">%1$s</span><span class="bc-figure-label">%2$s</span></div>',
			esc_html( number_format_i18n( (int) $blueprint['word_target'] ) ),
			esc_html__( 'Words', 'blogcraft' )
		);
		$out .= sprintf(
			'<div><span class="bc-figure">%1$s</span><span class="bc-figure-label">%2$s</span></div>',
			esc_html( self::compact( $tokens['total'] ) ),
			esc_html__( 'Tokens, roughly', 'blogcraft' )
		);
		$out .= '</div>';

		$out .= sprintf(
			'<p class="bc-hint">%s</p>',
			esc_html__( 'Token estimates are deliberately generous. Your provider bills you, not us.', 'blogcraft' )
		);

		foreach ( $warnings as $warning ) {
			$out .= sprintf( '<p class="bc-warn">%s</p>', esc_html( $warning ) );
		}

		return $out;
	}

	/**
	 * Shorten a large number for display.
	 *
	 * @param int $value Number.
	 * @return string
	 */
	private static function compact( $value ) {
		$value = (int) $value;

		if ( $value < 1000 ) {
			return (string) $value;
		}

		return number_format_i18n( $value / 1000, 1 ) . 'k';
	}

	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

	/**
	 * Read where the finished post should land.
	 *
	 * Everything is validated again in the pipeline against what actually
	 * exists, because a term or a user can be deleted between queueing a post
	 * and writing it.
	 *
	 * @param array $source Raw request data.
	 * @return array
	 */
	private static function placement_from( $source ) {
		$out = array();

		if ( isset( $source['post_category'] ) ) {
			$out['category'] = (int) $source['post_category'];
		}

		if ( isset( $source['post_author'] ) ) {
			$out['author'] = (int) $source['post_author'];
		}

		if ( isset( $source['post_tags'] ) ) {
			$out['tags'] = sanitize_text_field( wp_unslash( $source['post_tags'] ) );
		}

		if ( isset( $source['publish_at'] ) ) {
			$out['publish_at'] = sanitize_text_field( wp_unslash( $source['publish_at'] ) );
		}

		return $out;
	}

	/**
	 * Queue a submitted topic.
	 *
	 * @return void
	 */
	public static function handle_queue() {
		// Read then verify; Blogcraft_Request performs the check PHPCS cannot follow statically.
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::QUEUE_ACTION, $nonce );

		// Verified above by Blogcraft_Request::verify_or_die(), which PHPCS cannot follow statically.
		$topic  = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft'; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( '' === $topic ) {
			self::back( false, __( 'Please enter a topic.', 'blogcraft' ) );
		}

		// Ticked inside the panel that asks what a post will include, so this
		// is the one place it can be switched off from. Settings can turn it
		// back on.
		if ( isset( $_POST['stop_asking'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			Blogcraft_Settings::set( 'ask_before_writing', false );
		}

		$instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$evidence     = isset( $_POST['evidence'] ) ? sanitize_textarea_field( wp_unslash( $_POST['evidence'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$overrides    = self::overrides_from( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- each field is sanitised by type in Blogcraft_Blueprint::normalise().
		$placement    = self::placement_from( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- every field is cast or sanitised inside placement_from().
		// Written by hand means somebody is sitting there waiting, so the draft
		// is shown to them before it becomes a post. Autopilot passes false —
		// unattended writing has the quality gate and the review queue instead.
		$job_id = Blogcraft_Pipeline::enqueue_topic( $topic, $status, $instructions, $overrides, $evidence, $placement, true );

		if ( $job_id <= 0 ) {
			$clash = Blogcraft_Settings::get( 'duplicate_check_enabled' )
				? Blogcraft_Backlinks::find_duplicate( $topic )
				: '';

			if ( '' !== $clash ) {
				self::back(
					false,
					sprintf(
						/* translators: %s: the existing topic this one duplicates. */
						__( 'Skipped: too similar to a post you already have about "%s".', 'blogcraft' ),
						$clash
					)
				);
			}

			self::back( false, __( 'The topic could not be queued.', 'blogcraft' ) );
		}

		// Straight to the screen that writes it, rather than a notice saying
		// something is happening somewhere else. "Queued, it will be written
		// in the background" is only true if cron fires, which on a staging
		// site or a quiet blog it frequently does not — and even when it is
		// true it leaves the reader with nothing to look at and no way to tell
		// working from stuck.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => Blogcraft_Progress::PAGE_SLUG,
					'job'  => (int) $job_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Drain the queue on demand.
	 *
	 * @return void
	 */
	public static function handle_run_now() {
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::RUN_ACTION, $nonce );

		Blogcraft_Queue::reclaim_stale();

		$before   = Blogcraft_Queue::count_with_errors();
		$executed = Blogcraft_Worker::run();
		$after    = Blogcraft_Queue::count_with_errors();

		$message = sprintf(
			/* translators: %d: number of pipeline steps that ran. */
			_n( '%d step ran.', '%d steps ran.', $executed, 'blogcraft' ),
			$executed
		);

		// Steps that ran and failed still count as steps, so saying only how
		// many ran would report a broken setup as a success.
		if ( $after > $before ) {
			self::back(
				false,
				$message . ' ' . __( 'Something went wrong. Blogcraft → Activity has the reason.', 'blogcraft' )
			);
		}

		self::back( true, $message );
	}

	/**
	 * Queue many topics at once.
	 *
	 * @return void
	 */
	public static function handle_bulk() {
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::BULK_ACTION, $nonce );

		$raw     = isset( $_POST['topics'] ) ? sanitize_textarea_field( wp_unslash( $_POST['topics'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$queued  = 0;
		$skipped = 0;

		foreach ( preg_split(
			'/[
]+/',
			$raw
		) as $line ) {
			// A pasted CSV column brings its commas with it; the first field is the topic.
			$topic = trim( (string) strtok( trim( (string) $line ), ',' ) );

			if ( '' === $topic ) {
				continue;
			}

			if ( Blogcraft_Pipeline::enqueue_topic( $topic ) > 0 ) {
				++$queued;
			} else {
				++$skipped;
			}
		}

		self::back(
			true,
			sprintf(
				/* translators: 1: number queued, 2: number skipped as duplicates. */
				__( '%1$d queued, %2$d skipped as too similar to a post you have or one already waiting.', 'blogcraft' ),
				$queued,
				$skipped
			)
		);
	}

	/**
	 * Trash generated posts from the last day.
	 *
	 * @return void
	 */
	public static function handle_rollback() {
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::ROLLBACK_ACTION, $nonce );

		// The generated-by-Blogcraft meta is the guard that keeps this away from
		// anything a human wrote.
		$posts = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => array( 'publish', 'draft', 'pending' ),
				'posts_per_page' => 100,
				'no_found_rows'  => true,
				'meta_key'       => '_blogcraft_generated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'date_query'     => array(
					array( 'after' => '24 hours ago' ),
				),
			)
		);

		$trashed = 0;

		foreach ( $posts as $post ) {
			if ( wp_trash_post( $post->ID ) ) {
				++$trashed;
			}
		}

		self::back(
			true,
			sprintf(
				/* translators: %d: number of posts moved to the trash. */
				_n( '%d post moved to the trash.', '%d posts moved to the trash.', $trashed, 'blogcraft' ),
				$trashed
			)
		);
	}

	/**
	 * Store a one-shot notice and return to the screen.
	 *
	 * @param bool   $ok      Whether the action succeeded.
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
