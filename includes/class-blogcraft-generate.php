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
		add_action( 'admin_post_blogcraft_save_brief', array( __CLASS__, 'handle_brief' ) );
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

		// admin.js is a series of independent blocks that each return
		// early when their markup is absent, so loading it here costs
		// nothing and is what makes the copy buttons work.
		wp_enqueue_script(
			'blogcraft-admin',
			BLOGCRAFT_URL . 'assets/admin.js',
			array(),
			BLOGCRAFT_VERSION,
			true
		);

		wp_localize_script(
			'blogcraft-admin',
			'blogcraftProviders',
			array(
				'copied'     => __( 'Copied', 'dicecodes-ai-blog-writer' ),
				'copyFailed' => __( 'Press Ctrl+C to copy', 'dicecodes-ai-blog-writer' ),
			)
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
				'asking'   => __( 'Thinking about your topic...', 'dicecodes-ai-blog-writer' ),
				'askAgain' => __( 'What should I write about this?', 'dicecodes-ai-blog-writer' ),
				'noTopic'  => __( 'Write a topic first, then ask again.', 'dicecodes-ai-blog-writer' ),
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
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::QUEUE_ACTION, '_blogcraft_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'That form has expired. Reload the page.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		$raw = map_deep( wp_unslash( $_POST ), 'sanitize_textarea_field' );

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
						__( 'This looks like a repeat of "%s". Queueing it anyway is allowed, but near-identical posts are what search engines penalise.', 'dicecodes-ai-blog-writer' ),
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
			__( 'Write a post', 'dicecodes-ai-blog-writer' ),
			__( 'Write a post', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The one thing worth saying before the form rather than after it.
	 *
	 * This used to be a standing panel explaining what to do once the
	 * brief was saved, sitting above a form nobody had filled in yet.
	 * The instruction moved into the confirmation, where it arrives at
	 * the moment it is needed. What is left is the warning, which only
	 * shows when it is true.
	 *
	 * @return void
	 */
	private static function render_brief_hand_off() {
		if ( self::app_connected() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html__( 'No app is connected yet, so nothing will collect this brief.', 'dicecodes-ai-blog-writer' ),
			esc_url( admin_url( 'admin.php?page=blogcraft-settings#bc-card-clients' ) ),
			esc_html__( 'Connect one', 'dicecodes-ai-blog-writer' )
		);
	}

	/**
	 * Whether anything is listening for a brief.
	 *
	 * @return bool
	 */
	private static function app_connected() {
		return ! empty( Blogcraft_Mcp_Auth::all() ) || ! empty( Blogcraft_Mcp_Oauth::clients() );
	}

	/**
	 * The sentence to say in the app, shown once there is a brief for it.
	 *
	 * @return void
	 */
	private static function render_brief_saved() {
		if ( ! Blogcraft_Brief::waiting() ) {
			return;
		}

		echo '<div class="blogcraft-card bc-handoff">';

		printf(
			'<p><strong>%s</strong></p>',
			esc_html__( 'Now say this in your app:', 'dicecodes-ai-blog-writer' )
		);

		echo wp_kses(
			Blogcraft_Connection::copyable(
				__( 'Read my brief and my writing rules, then write that post.', 'dicecodes-ai-blog-writer' ),
				__( 'Copy this instruction', 'dicecodes-ai-blog-writer' ),
				true
			),
			Blogcraft_Markup::allowed()
		);

		echo '</div>';
	}
	/**
	 * Where the writing happens when an app does it.
	 *
	 * @return void
	 */
	private static function render_client_write() {
		echo '<div class="wrap blogcraft-page">';
		Blogcraft_Nav::render();

		echo '<div class="blogcraft-head">';
		printf( '<h1>%s</h1>', esc_html__( 'Write a post', 'dicecodes-ai-blog-writer' ) );
		printf(
			'<p>%s</p>',
			esc_html__( 'You write in Claude or ChatGPT. The post lands here.', 'dicecodes-ai-blog-writer' )
		);
		echo '</div>';

		echo '<section class="blogcraft-card">';

		echo '<ol class="bc-mcp-howto">';
		printf(
			'<li>%s</li>',
			esc_html__( 'Open the app you connected.', 'dicecodes-ai-blog-writer' )
		);
		printf(
			'<li>%s</li>',
			esc_html__( 'Say this, with your own subject in place of X:', 'dicecodes-ai-blog-writer' )
		);
		echo '</ol>';

		// The sentence itself, ready to copy. Anybody at this screen has
		// the other window open already.
		echo wp_kses(
			Blogcraft_Connection::copyable(
				__( 'Read my writing rules and write a post about X for my site.', 'dicecodes-ai-blog-writer' ),
				__( 'Copy this instruction', 'dicecodes-ai-blog-writer' ),
				true
			),
			Blogcraft_Markup::allowed()
		);

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'It reads your rules, drafts, scores itself against your checks, fixes what failed, and saves a draft here. Nothing is published until it clears your threshold.', 'dicecodes-ai-blog-writer' )
		);

		$connected = ! empty( Blogcraft_Mcp_Auth::all() ) || ! empty( Blogcraft_Mcp_Oauth::clients() );

		printf(
			'<p><a class="button%1$s" href="%2$s">%3$s</a> <a class="button" href="%4$s">%5$s</a></p>',
			$connected ? '' : ' button-primary',
			esc_url( admin_url( 'admin.php?page=blogcraft-settings#bc-card-clients' ) ),
			$connected
				? esc_html__( 'Connected apps', 'dicecodes-ai-blog-writer' )
				: esc_html__( 'Connect an app first', 'dicecodes-ai-blog-writer' ),
			esc_url( admin_url( 'admin.php?page=blogcraft-blueprint' ) ),
			esc_html__( 'How it writes', 'dicecodes-ai-blog-writer' )
		);

		echo '</section>';
		echo '</div>';
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

		$notice = get_transient( self::NOTICE_TRANSIENT . get_current_user_id() );

		echo '<div class="wrap blogcraft-page blogcraft-compose-page">';
		Blogcraft_Nav::render();
		echo '<div class="blogcraft-head">';
		$client = Blogcraft_Mode::is_client();

		echo '<h1>' . esc_html__( 'Write a post', 'dicecodes-ai-blog-writer' ) . '</h1>';
		printf(
			'<p>%s</p>',
			esc_html(
				$client
					? __( 'Fill this in, then ask your app to write. It collects the brief from here.', 'dicecodes-ai-blog-writer' )
					: __( 'Give it a topic. It researches, drafts, checks its own work and rewrites before anything reaches your site.', 'dicecodes-ai-blog-writer' )
			)
		);

		// Every field on this screen used to carry a paragraph explaining
		// itself, and several carried two. A screen you fill in is not a
		// screen you read. The explanations live in the documentation now,
		// which this page had no link to at all.
		printf(
			'<p class="bc-page-docs"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( Blogcraft_Docs::site_url( 'how-it-writes' ) ),
			esc_html__( 'How it writes, and what every field here does', 'dicecodes-ai-blog-writer' )
		);
		echo '</div>';

		if ( is_array( $notice ) ) {
			delete_transient( self::NOTICE_TRANSIENT . get_current_user_id() );
			printf(
				'<div class="notice %s"><p>%s</p></div>',
				esc_attr( empty( $notice['ok'] ) ? 'notice-error' : 'notice-success' ),
				esc_html( (string) $notice['message'] )
			);
		}

		if ( ! $client && ! Blogcraft_Provider_Registry::is_configured() ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'No AI provider is configured yet. Set one up under Dicecodes AI Blog Writer → Settings first.', 'dicecodes-ai-blog-writer' )
			);
		}

		if ( $client ) {
			self::render_brief_hand_off();
			self::render_brief_saved();
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="blogcraft-compose">';
		printf(
			'<input type="hidden" name="action" value="%s" />',
			esc_attr( $client ? 'blogcraft_save_brief' : 'blogcraft_queue_topic' )
		);
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
			esc_html__( 'You watch it write, and see the draft and its score before it lands.', 'dicecodes-ai-blog-writer' )
		);

		// On the client path there is no provider to be missing, and the
		// button does not start the writing — it hands the brief over.
		if ( Blogcraft_Mode::is_client() ) {
			printf(
				'<button type="submit" class="bc-save">%s</button>',
				esc_html__( 'Save this brief', 'dicecodes-ai-blog-writer' )
			);
		} elseif ( Blogcraft_Provider_Registry::is_configured() ) {
			printf(
				'<button type="submit" class="bc-save">%s</button>',
				// "Queue" described the old behaviour, where the post was
				// written by cron some minutes later and this screen could
				// only promise it. Pressing this now starts the writing.
				esc_html__( 'Write this post', 'dicecodes-ai-blog-writer' )
			);
		} else {
			printf(
				'<a class="bc-compose-fix" href="%1$s">%2$s</a><button type="submit" class="bc-save" disabled>%3$s</button>',
				esc_url( admin_url( 'admin.php?page=blogcraft-settings#bc-card-provider' ) ),
				esc_html__( 'Connect a provider first', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Write this post', 'dicecodes-ai-blog-writer' )
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
				__( 'Key takeaways', 'dicecodes-ai-blog-writer' ),
				__( 'A short list near the top. It is what gets quoted when a search engine answers the question without the click.', 'dicecodes-ai-blog-writer' ),
			),
			'faq'            => array(
				__( 'Questions and answers', 'dicecodes-ai-blog-writer' ),
				// This used to promise its own search result. Google retired
				// FAQ rich results for every site in May 2026, so that is a
				// promise the plugin can no longer keep. The section is still
				// worth writing, for the reason it was worth writing before
				// anybody marked it up.
				__( 'Built from what people actually ask about this subject, and answered where they will look for them. No longer its own search result: Google retired those in May 2026.', 'dicecodes-ai-blog-writer' ),
			),
			'toc'            => array(
				__( 'Contents list', 'dicecodes-ai-blog-writer' ),
				__( 'Worth it on a long guide, clutter on a short answer.', 'dicecodes-ai-blog-writer' ),
			),
			'tables'         => array(
				__( 'Tables', 'dicecodes-ai-blog-writer' ),
				__( 'For anything with figures to compare. Prose that lists numbers is harder to read and harder to cite.', 'dicecodes-ai-blog-writer' ),
			),
			'lists'          => array(
				__( 'Bulleted lists', 'dicecodes-ai-blog-writer' ),
				__( 'Used where a list is genuinely a list, not as a way of avoiding paragraphs.', 'dicecodes-ai-blog-writer' ),
			),
			'block_sources'  => array(
				__( 'The sources it was written from', 'dicecodes-ai-blog-writer' ),
				__( 'The only real outbound links a post gets — they are the addresses research actually fetched, never ones the model wrote. Off means no citations, and the citation check is skipped rather than failed.', 'dicecodes-ai-blog-writer' ),
			),
			'block_audience' => array(
				__( 'Who it is for', 'dicecodes-ai-blog-writer' ),
				__( 'A line near the top saying who should read on, and who should not.', 'dicecodes-ai-blog-writer' ),
			),
			'block_proscons' => array(
				__( 'What works and what does not', 'dicecodes-ai-blog-writer' ),
				__( 'For reviews and comparisons. A page with no criticism in it reads as an advert.', 'dicecodes-ai-blog-writer' ),
			),
			'block_figures'  => array(
				__( 'The numbers', 'dicecodes-ai-blog-writer' ),
				__( 'A table of the figures the post rests on, so they can be checked rather than taken.', 'dicecodes-ai-blog-writer' ),
			),
			'block_mistakes' => array(
				__( 'Mistakes worth avoiding', 'dicecodes-ai-blog-writer' ),
				__( 'The part a writer who has actually done the thing can write and a summary of other pages cannot.', 'dicecodes-ai-blog-writer' ),
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

		$brief    = Blogcraft_Blueprint::get();
		$niche    = trim( (string) $brief['niche'] );
		$audience = trim( (string) $brief['audience_custom'] );

		if ( '' === $niche || '' === $audience ) {
			$gaps[] = array(
				'title' => __( 'Nobody has said who this blog is for', 'dicecodes-ai-blog-writer' ),
				'why'   => __( 'This is sent with every request and it is the single biggest reason two blogs using the same model do not read the same. Without it the model writes for nobody in particular, which is what generic sounds like.', 'dicecodes-ai-blog-writer' ),
				'url'   => admin_url( 'admin.php?page=blogcraft-settings#bc-card-voice' ),
				'link'  => __( 'Describe it', 'dicecodes-ai-blog-writer' ),
			);
		}

		// Shape, tone and length all come from the blueprint. Untouched, it
		// is a sensible general-purpose brief rather than a wrong one — so
		// this says "never adjusted", not "broken".
		if ( ! Blogcraft_Blueprint::was_edited() ) {
			$gaps[] = array(
				'title' => __( 'The writing rules have never been adjusted', 'dicecodes-ai-blog-writer' ),
				'why'   => __( 'Shape, length, tone, reading level and what counts as a finished post are all set on one screen, and it still holds the defaults. They are reasonable defaults, not right ones: a hands-on review and a definitive guide are not the same shape.', 'dicecodes-ai-blog-writer' ),
				'url'   => admin_url( 'admin.php?page=blogcraft-blueprint' ),
				'link'  => __( 'Choose a shape', 'dicecodes-ai-blog-writer' ),
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

		// Only where this site does the reading. On the client path the
		// application brings its own sources, these settings are not even on
		// the screen, and the card this pointed at is not there to arrive at.
		if ( Blogcraft_Mode::is_api() && ! $researching && ! Blogcraft_Research::has_search_provider() ) {
			$gaps[] = array(
				'title' => __( 'It has nothing to read but its own memory', 'dicecodes-ai-blog-writer' ),
				'why'   => __( 'Without sources it writes from memory, which dates badly. Two free sources need no account.', 'dicecodes-ai-blog-writer' ),
				'url'   => admin_url( 'admin.php?page=blogcraft-settings#bc-card-research' ),
				'link'  => __( 'Switch one on', 'dicecodes-ai-blog-writer' ),
			);
		}

		// Filled in on this screen, so it is checked in the browser rather
		// than here — but the row has to exist for the script to show it.
		printf(
			'<div class="bc-gap is-evidence" id="bc-gap-evidence" hidden>'
			. '<strong>%1$s</strong><span>%2$s</span>'
			. '<button type="button" class="button-link" id="bc-gap-evidence-go">%3$s</button>'
				. '</div>',
			esc_html__( 'You have not said anything only you know', 'dicecodes-ai-blog-writer' ),
			esc_html__( 'The heaviest check on the finished post, and the one part a model cannot produce. Your own numbers, prices, results, or what went wrong when you tried it. One or two sentences is enough.', 'dicecodes-ai-blog-writer' ),
			esc_html__( 'Go and add it', 'dicecodes-ai-blog-writer' )
		);

		if ( empty( $gaps ) ) {
			return;
		}

		printf(
			'<h3 class="bc-confirm-section">%s</h3>',
			esc_html__( 'Worth setting first', 'dicecodes-ai-blog-writer' )
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
	 * What the post will actually come out as, in one line.
	 *
	 * The tick boxes above decide which parts a post is built from. These
	 * decide what it turns out to be, and three of the four are settings
	 * on other screens — so somebody agreeing to write a post had to
	 * remember whether the picture service was ever switched on.
	 *
	 * @return string
	 */
	private static function outcome_line() {
		$blueprint = Blogcraft_Blueprint::get();

		$researching = Blogcraft_Research::has_search_provider();

		foreach ( array_keys( Blogcraft_Research::free_sources() ) as $source ) {
			if ( Blogcraft_Settings::get( $source ) ) {
				$researching = true;
			}
		}

		$says = array();

		// Whether there is anything to read is only this site's answer to
		// give on the path where this site does the reading. A connected
		// application brings its own, and the plugin does not know what.
		if ( Blogcraft_Mode::is_client() ) {
			$says[] = sprintf(
				/* translators: %s: target length in words. */
				__( 'About %s words.', 'dicecodes-ai-blog-writer' ),
				number_format_i18n( (int) $blueprint['word_target'] )
			);
		}

		$says[] = Blogcraft_Mode::is_client() ? '' : sprintf(
			$researching
				/* translators: %s: target length in words. */
				? __( 'About %s words, written from current sources.', 'dicecodes-ai-blog-writer' )
				/* translators: %s: target length in words. */
				: __( 'About %s words, written from memory alone.', 'dicecodes-ai-blog-writer' ),
			number_format_i18n( (int) $blueprint['word_target'] )
		);

		$says = array_filter( $says );

		// The one people assume. Nothing is fetched until the picture service
		// is switched on, and that is two screens away from here.
		if ( (int) $blueprint['images_target'] > 0 ) {
			$says[] = Blogcraft_Settings::get( 'images_enabled' )
				? __( 'With pictures.', 'dicecodes-ai-blog-writer' )
				: __( 'No pictures: the picture service is switched off.', 'dicecodes-ai-blog-writer' );
		}

		// Where it lands. On the client path nothing here publishes, so the
		// answer is the same every time and worth saying anyway.
		$says[] = Blogcraft_Mode::is_client()
			? __( 'Saved as a draft for you to read.', 'dicecodes-ai-blog-writer' )
			: __( 'Saved as a draft unless you chose otherwise.', 'dicecodes-ai-blog-writer' );

		return implode( ' ', $says );
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
		// tabindex so focus can be put on the panel itself when it opens,
		// which is what makes a screen reader read the heading before the
		// first control rather than starting halfway down.
		echo '<div class="bc-confirm-sheet" role="dialog" aria-modal="true" aria-labelledby="bc-confirm-title" tabindex="-1">';

		echo '<div class="bc-confirm-head">';
		echo '<h2 id="bc-confirm-title">' . esc_html__( 'Before it writes', 'dicecodes-ai-blog-writer' ) . '</h2>';

		// It used to open with a paragraph arguing for its own existence.
		// Somebody who has just pressed the button is not reading an essay
		// about why they are being asked; they want to see what they are
		// agreeing to and get on.
		echo '<p>' . esc_html__( 'A last look at what this post will be, and what it is missing.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</div>';

		echo '<div class="bc-confirm-body">';

		self::render_gaps();

		printf(
			'<h3 class="bc-confirm-section">%s</h3>',
			esc_html__( 'What this post will include', 'dicecodes-ai-blog-writer' )
		);
		printf(
			'<p class="bc-confirm-note">%s</p>',
			esc_html__( 'These change this post only. Your standing answers are already ticked.', 'dicecodes-ai-blog-writer' )
		);

		// Five of these already have a switch on the Shape tab. Emitting a
		// second input with the same name would put two answers to one
		// question in the form, and whichever rendered last would silently
		// win. So those rows carry no name: they drive the tab's own switch
		// through data-for, and the form keeps exactly one input per field.
		$on_tab = array( 'takeaways', 'faq', 'toc', 'tables', 'lists' );

		echo '<div class="bc-confirm-parts">';

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
		echo '</div>';

		// What the tick boxes above cannot tell you. They decide which parts
		// a post is built from; these four decide what it actually comes out
		// as, and three of them are settings on other screens. Somebody
		// agreeing to write a post should not have to remember whether the
		// picture service was ever switched on.
		printf(
			'<p class="bc-confirm-summary">%s</p>',
			esc_html( self::outcome_line() )
		);

		echo '<div class="bc-confirm-foot">';

		printf(
			'<label class="bc-confirm-quiet"><input type="checkbox" name="stop_asking" value="1" /> %s</label>',
			esc_html__( 'Stop asking me this', 'dicecodes-ai-blog-writer' )
		);

		echo '<div class="bc-confirm-buttons">';
		printf(
			'<button type="button" class="button" id="bc-confirm-back">%s</button>',
			esc_html__( 'Back to the brief', 'dicecodes-ai-blog-writer' )
		);
		// This is the same submit as the button under the form, so it has to
		// say the same thing. On the client path pressing it writes nothing;
		// it hands the brief over for an app to collect.
		printf(
			'<button type="submit" class="bc-save">%s</button>',
			esc_html(
				Blogcraft_Mode::is_client()
					? __( 'Save this brief', 'dicecodes-ai-blog-writer' )
					: __( 'Write it now', 'dicecodes-ai-blog-writer' )
			)
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
		// Queueing a list and pushing the queue along are this site calling a
		// provider, over and over, unattended. There is no provider on the
		// client path, so a list queued there is a list that sits for ever
		// while the screen reports it as queued. Undoing a batch is neither:
		// it works off the generated-by-us mark, which a post written over
		// MCP carries too, so it stays on both paths.
		$client = Blogcraft_Mode::is_client();

		echo '<details class="bc-more">';
		printf(
			'<summary><span>%1$s</span><span class="bc-more-hint">%2$s</span></summary>',
			esc_html(
				$client
					? __( 'Undo a batch', 'dicecodes-ai-blog-writer' )
					: __( 'More than one at a time', 'dicecodes-ai-blog-writer' )
			),
			esc_html(
				$client
					? __( 'Trash what was written in the last day', 'dicecodes-ai-blog-writer' )
					: __( 'Queue a list, run a stuck job, undo a batch', 'dicecodes-ai-blog-writer' )
			)
		);

		echo '<div class="bc-more-body">';

		if ( ! $client ) {
			$waiting = Blogcraft_Queue::count_by_status( 'pending' ) + Blogcraft_Queue::count_by_status( 'running' );

			printf(
				'<p class="bc-more-count">%1$s <a href="%2$s">%3$s</a></p>',
				esc_html(
					sprintf(
						/* translators: %d: how many posts are queued or being written. */
						_n( '%d post is queued or being written.', '%d posts are queued or being written.', $waiting, 'dicecodes-ai-blog-writer' ),
						$waiting
					)
				),
				esc_url( admin_url( 'admin.php?page=' . Blogcraft_Library::PAGE_SLUG ) ),
				esc_html__( 'See everything written by AI', 'dicecodes-ai-blog-writer' )
			);

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bc-more-block">';
			echo '<input type="hidden" name="action" value="blogcraft_bulk_topics" />';
			Blogcraft_Request::nonce_field( self::BULK_ACTION );
			printf(
				'<label for="blogcraft_topics"><strong>%1$s</strong></label>',
				esc_html__( 'Queue a list of topics', 'dicecodes-ai-blog-writer' )
			);
			echo '<textarea class="large-text code" name="topics" id="blogcraft_topics" rows="5" placeholder="' . esc_attr__( 'One topic per line, or paste a CSV column', 'dicecodes-ai-blog-writer' ) . '"></textarea>';
			echo '<p class="description">' . esc_html__( 'These use your standing rules, not the brief above, and are written unattended. Repeats are skipped, whether the post already exists or is only queued.', 'dicecodes-ai-blog-writer' ) . '</p>';
			submit_button( __( 'Queue all of these', 'dicecodes-ai-blog-writer' ), 'secondary', 'submit', false );
			echo '</form>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bc-more-block">';
			echo '<input type="hidden" name="action" value="blogcraft_run_queue_now" />';
			Blogcraft_Request::nonce_field( self::RUN_ACTION );
			printf( '<p><strong>%s</strong></p>', esc_html__( 'Push the queue along', 'dicecodes-ai-blog-writer' ) );
			echo '<p class="description">' . esc_html__( 'Posts written here run in your browser and need none of this. It is for a queued job that has stopped moving on a site where scheduled tasks do not fire.', 'dicecodes-ai-blog-writer' ) . '</p>';
			submit_button( __( 'Run the queue now', 'dicecodes-ai-blog-writer' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bc-more-block is-danger" onsubmit="return confirm(' . esc_attr( "'" . esc_js( __( 'Move recently generated posts to the trash?', 'dicecodes-ai-blog-writer' ) ) . "'" ) . ');">';
		echo '<input type="hidden" name="action" value="blogcraft_rollback" />';
		Blogcraft_Request::nonce_field( self::ROLLBACK_ACTION );
		printf( '<p><strong>%s</strong></p>', esc_html__( 'Undo a batch', 'dicecodes-ai-blog-writer' ) );
		echo '<p class="description">' . esc_html__( 'Trashes posts Dicecodes AI Blog Writer created in the last 24 hours. Anything you wrote yourself is left alone.', 'dicecodes-ai-blog-writer' ) . '</p>';
		submit_button( __( 'Trash the last 24 hours', 'dicecodes-ai-blog-writer' ), 'delete', 'submit', false );
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

		// Where each of these is actually set, and what to call the way
		// there. One shared button pointing at Settings was wrong for the
		// voice from the moment the voice moved to its own screen: it landed
		// on a card whose only content is a link to How it writes.
		$where = array(
			'voice'    => array(
				admin_url( 'admin.php?page=blogcraft-blueprint' ),
				__( 'Describe your voice', 'dicecodes-ai-blog-writer' ),
			),
			'research' => array(
				admin_url( 'admin.php?page=blogcraft-settings#bc-card-research' ),
				__( 'Choose a source', 'dicecodes-ai-blog-writer' ),
			),
		);

		// Research belongs to the provider path. An application brings its
		// own, there is no research card on this setup's settings screen to
		// send anybody to, and telling somebody to go and switch on something
		// that does not apply to them is worse than saying nothing.
		if ( Blogcraft_Mode::is_client() ) {
			unset( $where['research'] );
		}

		$missing = array();

		foreach ( $state['items'] as $item ) {
			if ( ! $item['ok'] && isset( $where[ $item['key'] ] ) ) {
				$missing[] = $item;
			}
		}

		if ( empty( $missing ) ) {
			return;
		}

		echo '<aside class="bc-readiness">';
		echo '<h2>' . esc_html__( 'Before you write', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Set once, then used by every post.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '<ul>';

		// A link each, to the screen that actually holds it.
		foreach ( $missing as $item ) {
			printf(
				'<li><strong>%1$s</strong><span>%2$s</span>'
				. '<a class="button button-small" href="%3$s">%4$s</a></li>',
				esc_html( $item['label'] ),
				esc_html( $item['why'] ),
				esc_url( $where[ $item['key'] ][0] ),
				esc_html( $where[ $item['key'] ][1] )
			);
		}

		echo '</ul>';
		echo '</aside>';
	}

	/**
	 * Turn the topic into questions worth answering.
	 *
	 * @return void
	 */
	public static function handle_suggest() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::QUEUE_ACTION, '_blogcraft_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'That form has expired. Reload the page.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		// Every one of these asks the provider something. Without one the
		// old answer was whatever the HTTP layer said, which is true and
		// tells nobody what to do about it.
		Blogcraft_Request::require_provider();

		$topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';

		if ( '' === trim( $topic ) ) {
			wp_send_json_error( array( 'message' => __( 'Write a topic first.', 'dicecodes-ai-blog-writer' ) ), 400 );
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
			esc_html__( 'Your provider is rate limiting.', 'dicecodes-ai-blog-writer' ),
			esc_html(
				sprintf(
					/* translators: %s: a clock time, such as "3:45 pm". */
					__( 'A post already being written resumes at about %s. Starting another now will most likely stop part-way too, and the stages it runs first still cost you.', 'dicecodes-ai-blog-writer' ),
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
			__( 'Topic', 'dicecodes-ai-blog-writer' ),
			__( 'A sentence beats a keyword. Say what the post should answer.', 'dicecodes-ai-blog-writer' ),
			'<input type="text" class="bc-text bc-text-lead" name="topic" id="bc_topic" value="" required autocomplete="off" placeholder="' . esc_attr__( 'How to choose a standing desk for a small home office', 'dicecodes-ai-blog-writer' ) . '" /><p class="bc-only-this">' . esc_html__( 'The only field you have to fill in.', 'dicecodes-ai-blog-writer' ) . '</p><p class="bc-clash" id="bc-clash" hidden></p>',
			'bc_topic'
		);

		echo Blogcraft_Controls::row(
			__( 'Angle for this post', 'dicecodes-ai-blog-writer' ),
			__( 'Anything true of this post only. Stops them all reading the same.', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Controls::area( 'instructions', '', __( 'Compare three price brackets and say which is worth it.', 'dicecodes-ai-blog-writer' ), 2 ),
			'bc_instructions'
		);

		echo Blogcraft_Controls::row(
			__( 'What you know that nobody else does', 'dicecodes-ai-blog-writer' ),
			__( 'Your own numbers, prices, or what happened when you tried it. Used as fact, never invented beyond.', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Controls::area(
				'evidence',
				'',
				__( 'We tested 9 desks over 4 months. The £220 bracket wobbled above 110cm; the £400 bracket did not. Our own returns rate on the cheapest was 3 in 9.', 'dicecodes-ai-blog-writer' ),
				4
			),
			'bc_evidence'
		);

		// "What do you know that nobody else does" is a hard question asked
		// cold, and an empty answer is the single commonest reason a finished
		// post reads like every other AI post. Asked about a specific topic it
		// becomes easy, so this offers to ask it properly.
		//
		// Asking costs a call to the provider, so on the client path there is
		// nothing to ask. The app being written in can answer this better
		// anyway, since it is the one holding the conversation.
		if ( Blogcraft_Mode::is_api() ) {
			printf(
				'<div class="bc-suggest">'
				. '<button type="button" class="button" id="blogcraft-suggest">%1$s</button>'
				. '<p class="description">%2$s</p>'
				. '<div class="bc-suggest-out" id="blogcraft-suggest-out" hidden>'
				. '<p class="bc-suggest-lead">%3$s</p><ul id="blogcraft-suggest-list"></ul></div>'
				. '</div>',
				esc_html__( 'What should I write about this?', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Asks four questions only you can answer, for the box above.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Answer any of these in the box above, in your own words:', 'dicecodes-ai-blog-writer' )
			);
		}

		// Whether to publish is the provider path deciding what to do when it
		// finishes writing. A brief is not written by this site, so nothing
		// here reads this, and the app publishes only above the threshold.
		if ( Blogcraft_Mode::is_api() ) {
			echo Blogcraft_Controls::row(
				__( 'When it is finished', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::segmented(
					'status',
					array(
						'draft'   => __( 'Save as a draft', 'dicecodes-ai-blog-writer' ),
						'publish' => __( 'Publish it', 'dicecodes-ai-blog-writer' ),
					),
					'draft'
				)
			);
		}

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
		echo '<h2>' . esc_html__( 'Everything else about this post', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Changes this post only.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '</div>';

		echo '<div class="bc-tabs" role="tablist">';

		$tabs = array(
			'shape'    => __( 'Shape', 'dicecodes-ai-blog-writer' ),
			'voice'    => __( 'Voice', 'dicecodes-ai-blog-writer' ),
			'seo'      => __( 'Search', 'dicecodes-ai-blog-writer' ),
			'human'    => __( 'Sounding human', 'dicecodes-ai-blog-writer' ),
			'pictures' => __( 'Pictures', 'dicecodes-ai-blog-writer' ),
			'publish'  => __( 'Publishing', 'dicecodes-ai-blog-writer' ),
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
				__( 'Length', 'dicecodes-ai-blog-writer' ),
				__( 'Measured on the finished draft.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::slider( 'o_word_target', 300, 4000, 50, $bp['word_target'], __( ' words', 'dicecodes-ai-blog-writer' ) ),
				'bc_o_word_target'
			),
			Blogcraft_Controls::row(
				__( 'Fewest sections', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::slider( 'o_sections_min', 1, 12, 1, $bp['sections_min'] ),
				'bc_o_sections_min'
			),
			Blogcraft_Controls::row(
				__( 'Most sections', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::slider( 'o_sections_max', 1, 15, 1, $bp['sections_max'] ),
				'bc_o_sections_max'
			),
			Blogcraft_Controls::row(
				__( 'How it opens', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::select( 'o_intro_style', Blogcraft_Blueprint::intro_styles(), $bp['intro_style'] ),
				'bc_o_intro_style'
			),
			Blogcraft_Controls::row(
				__( 'How it ends', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::select( 'o_conclusion_style', Blogcraft_Blueprint::conclusion_styles(), $bp['conclusion_style'] ),
				'bc_o_conclusion_style'
			),
			Blogcraft_Controls::row(
				__( 'Include', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::toggle( 'o_takeaways', $bp['takeaways'], __( 'Key takeaways', 'dicecodes-ai-blog-writer' ) )
				. Blogcraft_Controls::toggle( 'o_faq', $bp['faq'], __( 'Questions and answers', 'dicecodes-ai-blog-writer' ) )
				. Blogcraft_Controls::toggle( 'o_toc', $bp['toc'], __( 'Table of contents', 'dicecodes-ai-blog-writer' ) )
				. Blogcraft_Controls::toggle( 'o_tables', $bp['tables'], __( 'Tables', 'dicecodes-ai-blog-writer' ) )
				. Blogcraft_Controls::toggle( 'o_lists', $bp['lists'], __( 'Bulleted lists', 'dicecodes-ai-blog-writer' ) )
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
				__( 'Tone', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::select( 'o_tone', Blogcraft_Blueprint::tones(), $bp['tone'] ),
				'bc_o_tone'
			),
			Blogcraft_Controls::row(
				__( 'Describe the tone', 'dicecodes-ai-blog-writer' ),
				__( 'Used only when the tone above is set to something else.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::text( 'o_tone_custom', $bp['tone_custom'], __( 'Dry, a little sceptical', 'dicecodes-ai-blog-writer' ) ),
				'bc_o_tone_custom'
			),
			Blogcraft_Controls::row(
				__( 'Who is speaking', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::segmented( 'o_point_of_view', Blogcraft_Blueprint::points_of_view(), $bp['point_of_view'] )
			),
			Blogcraft_Controls::row(
				__( 'Who is reading', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::select( 'o_audience', Blogcraft_Blueprint::audiences(), $bp['audience'] ),
				'bc_o_audience'
			),
			Blogcraft_Controls::row(
				__( 'Describe the reader', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::text( 'o_audience_custom', $bp['audience_custom'], __( 'People setting up a first home office', 'dicecodes-ai-blog-writer' ) ),
				'bc_o_audience_custom'
			),
			Blogcraft_Controls::row(
				__( 'Reading level', 'dicecodes-ai-blog-writer' ),
				__( 'Measured as a Flesch Reading Ease band.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::select( 'o_reading_level', self::reading_labels(), $bp['reading_level'] ),
				'bc_o_reading_level'
			),
			Blogcraft_Controls::row(
				__( 'Longest sentence', 'dicecodes-ai-blog-writer' ),
				__( 'Measured.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::slider( 'o_sentence_max_words', 12, 50, 1, $bp['sentence_max_words'], __( ' words', 'dicecodes-ai-blog-writer' ) ),
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
				__( 'Target phrase', 'dicecodes-ai-blog-writer' ),
				__( 'Measured. Leave blank to let the topic speak for itself.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::text( 'o_primary_keyword', $bp['primary_keyword'], __( 'standing desk', 'dicecodes-ai-blog-writer' ) ),
				'bc_o_primary_keyword'
			),
			Blogcraft_Controls::row(
				__( 'Also cover', 'dicecodes-ai-blog-writer' ),
				__( 'One per line.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::area( 'o_secondary_keywords', $bp['secondary_keywords'], "adjustable desk\nsit stand desk" ),
				'bc_o_secondary_keywords'
			),
			Blogcraft_Controls::row(
				__( 'Must appear', 'dicecodes-ai-blog-writer' ),
				__( 'One per line. Measured — a missing term is reported back and rewritten.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::area( 'o_required_terms', $bp['required_terms'], "ergonomics\nanti-fatigue mat" ),
				'bc_o_required_terms'
			),
			Blogcraft_Controls::row(
				__( 'Sources to cite', 'dicecodes-ai-blog-writer' ),
				__( 'Measured.', 'dicecodes-ai-blog-writer' ),
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
				__( 'Devices to use', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::chips(
					'o_literary_devices',
					Blogcraft_Blueprint::literary_devices(),
					Blogcraft_Blueprint::chosen( $bp, 'literary_devices' )
				)
			),
			Blogcraft_Controls::row(
				__( 'Habits', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::toggle( 'o_sentence_variety', $bp['sentence_variety'], __( 'Vary sentence length', 'dicecodes-ai-blog-writer' ) )
				. Blogcraft_Controls::toggle( 'o_allow_contractions', $bp['allow_contractions'], __( 'Allow contractions', 'dicecodes-ai-blog-writer' ) )
				. Blogcraft_Controls::toggle( 'o_allow_em_dash', $bp['allow_em_dash'], __( 'Allow em dashes', 'dicecodes-ai-blog-writer' ) )
			),
			Blogcraft_Controls::row(
				__( 'Demand', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::toggle( 'o_require_experience', $bp['require_experience'], __( 'First-hand, specific detail', 'dicecodes-ai-blog-writer' ) )
				. Blogcraft_Controls::toggle( 'o_require_citations', $bp['require_citations'], __( 'A named source for claims', 'dicecodes-ai-blog-writer' ) )
				. Blogcraft_Controls::toggle( 'o_require_statistics', $bp['require_statistics'], __( 'Concrete figures', 'dicecodes-ai-blog-writer' ) )
			),
			Blogcraft_Controls::row(
				__( 'Never write', 'dicecodes-ai-blog-writer' ),
				__( 'One per line. Measured.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::area( 'o_banned_phrases', $bp['banned_phrases'], "delve into\nin today's fast-paced world", 3 ),
				'bc_o_banned_phrases'
			),
			Blogcraft_Controls::row(
				__( 'Never mention', 'dicecodes-ai-blog-writer' ),
				__( 'One per line. Competitors, brands, claims that must not appear at all. Measured, weighted heavily.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::area( 'o_negative_keywords', $bp['negative_keywords'], __( "a competitor's name", 'dicecodes-ai-blog-writer' ), 3 ),
				'bc_o_negative_keywords'
			),
			Blogcraft_Controls::row(
				__( 'Steer clear of', 'dicecodes-ai-blog-writer' ),
				__( 'One per line. Subjects to avoid even in passing.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::area( 'o_avoid_subjects', $bp['avoid_subjects'], __( 'medical advice', 'dicecodes-ai-blog-writer' ), 3 ),
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
				__( 'Featured image', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::toggle( 'o_image_describe', $bp['image_describe'], __( 'Let the model describe the picture for this post', 'dicecodes-ai-blog-writer' ) )
				. self::pictures_note()
			),
			Blogcraft_Controls::row(
				__( 'Pictures in the body', 'dicecodes-ai-blog-writer' ),
				__( 'One beneath each section heading, up to this many.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::slider( 'o_images_target', 0, 6, 1, $bp['images_target'] )
			),
			Blogcraft_Controls::row(
				__( 'Treatment', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::select( 'o_image_style', Blogcraft_Art_Direction::styles(), $bp['image_style'] ),
				'bc_o_image_style'
			),
			Blogcraft_Controls::row(
				__( 'Mood', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::select( 'o_image_mood', Blogcraft_Art_Direction::moods(), $bp['image_mood'] ),
				'bc_o_image_mood'
			),
			Blogcraft_Controls::row(
				__( 'What it shows', 'dicecodes-ai-blog-writer' ),
				__( 'The angle every picture takes on its subject.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::select( 'o_image_subject', Blogcraft_Art_Direction::subjects(), $bp['image_subject'] ),
				'bc_o_image_subject'
			),
			Blogcraft_Controls::row(
				__( 'Shape', 'dicecodes-ai-blog-writer' ),
				'',
				Blogcraft_Controls::segmented( 'o_image_shape', Blogcraft_Art_Direction::shapes(), $bp['image_shape'] )
			),
			Blogcraft_Controls::row(
				__( 'Colours', 'dicecodes-ai-blog-writer' ),
				__( 'In words. Leave blank to let each picture suit its own subject.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::text( 'o_image_palette', $bp['image_palette'], __( 'muted greens, warm oak, off-white', 'dicecodes-ai-blog-writer' ) ),
				'bc_o_image_palette'
			),
			Blogcraft_Controls::row(
				__( 'Anything else', 'dicecodes-ai-blog-writer' ),
				__( 'Added to every image prompt as written.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::area( 'o_image_extra', $bp['image_extra'], __( 'shot from slightly above, shallow depth of field', 'dicecodes-ai-blog-writer' ), 2 ),
				'bc_o_image_extra'
			),
			Blogcraft_Controls::row(
				__( 'Never show', 'dicecodes-ai-blog-writer' ),
				__( 'Things that keep appearing and should not.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::area( 'o_image_avoid', $bp['image_avoid'], __( 'crowds, brand names, hands holding phones', 'dicecodes-ai-blog-writer' ), 2 ),
				'bc_o_image_avoid'
			),
			Blogcraft_Controls::row(
				__( 'Words in the picture', 'dicecodes-ai-blog-writer' ),
				__( 'Image models render lettering as convincing gibberish, so text is excluded by default.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::toggle( 'o_image_allow_text', $bp['image_allow_text'], __( 'Allow text in generated images', 'dicecodes-ai-blog-writer' ) )
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
				esc_html__( 'Pictures are switched off, so nothing here will run.', 'dicecodes-ai-blog-writer' ),
				esc_url( $link ),
				esc_html__( 'Turn them on', 'dicecodes-ai-blog-writer' )
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
					__( 'The article decides what the picture shows; these decide how it looks. Drawn right now by: %s.', 'dicecodes-ai-blog-writer' ),
					$name
				)
			),
			esc_url( $link ),
			esc_html__( 'Change the service', 'dicecodes-ai-blog-writer' )
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
				'show_option_none'  => __( 'Whatever the site default is', 'dicecodes-ai-blog-writer' ),
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
				'show_option_none'  => __( 'Me', 'dicecodes-ai-blog-writer' ),
				'option_none_value' => 0,
				'echo'              => false,
				'capability'        => array( 'edit_posts' ),
			)
		);

		$rows = array(
			Blogcraft_Controls::row(
				__( 'Category', 'dicecodes-ai-blog-writer' ),
				'',
				(string) $categories,
				'bc_post_category'
			),
			Blogcraft_Controls::row(
				__( 'Tags', 'dicecodes-ai-blog-writer' ),
				__( 'Comma separated. Left blank, no tags are added.', 'dicecodes-ai-blog-writer' ),
				Blogcraft_Controls::text( 'post_tags', '', __( 'cold brew, coffee gear', 'dicecodes-ai-blog-writer' ) ),
				'bc_post_tags'
			),
			Blogcraft_Controls::row(
				__( 'Credited to', 'dicecodes-ai-blog-writer' ),
				__( 'Whose byline appears on the post. A named author with stated credentials is a real trust signal, and it is published as structured data.', 'dicecodes-ai-blog-writer' ),
				(string) $authors,
				'bc_post_author'
			),
			Blogcraft_Controls::row(
				__( 'Publish at', 'dicecodes-ai-blog-writer' ),
				__( 'Leave blank to publish as soon as it is written. Only applies if you chose to publish rather than save a draft.', 'dicecodes-ai-blog-writer' ),
				'<input type="datetime-local" class="bc-text" name="publish_at" id="bc_publish_at" value="" />',
				'bc_publish_at'
			),
		);

		// Which model writes this one. The other path has no say: the model
		// is a setting inside whatever application is doing the writing.
		if ( Blogcraft_Mode::is_api() ) {
			$rows[] = Blogcraft_Controls::row(
				__( 'Model for this post', 'dicecodes-ai-blog-writer' ),
				__( 'Leave it on your usual one unless you are comparing two.', 'dicecodes-ai-blog-writer' ),
				self::model_choices(),
				'bc_post_model'
			);
		}

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
		echo '<h2 id="bc-outcome-title">' . esc_html__( 'What you will get', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'The shape of it, not the words.', 'dicecodes-ai-blog-writer' ) . '</p>';
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

		$out = '<ol class="bc-outline">';

		foreach ( $shape as $block ) {
			$words = ( $block['words'] > 0 )
				? sprintf(
					'<span class="bc-shape-words">%s</span>',
					esc_html(
						sprintf(
							/* translators: %d: approximate word count. */
							__( '~%d words', 'dicecodes-ai-blog-writer' ),
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
			esc_html__( 'Words', 'dicecodes-ai-blog-writer' )
		);
		// An estimate of what a provider will bill for. On the client path
		// the writing is paid for by a subscription somebody already has,
		// and a number here would be counting somebody else's money.
		if ( Blogcraft_Mode::is_api() ) {
			$out .= sprintf(
				'<div><span class="bc-figure">%1$s</span><span class="bc-figure-label">%2$s</span></div>',
				esc_html( self::compact( $tokens['total'] ) ),
				esc_html__( 'Tokens, roughly', 'dicecodes-ai-blog-writer' )
			);
		}

		$out .= '</div>';

		if ( Blogcraft_Mode::is_api() ) {
			$out .= sprintf(
				'<p class="bc-hint">%s</p>',
				esc_html__( 'Deliberately generous. Your provider bills you, not us.', 'dicecodes-ai-blog-writer' )
			);
		}

		foreach ( $warnings as $warning ) {
			$out .= sprintf( '<p class="bc-warn">%s</p>', esc_html( $warning ) );
		}

		return $out;
	}

	/**
	 * The models this site knows about, as something to pick from.
	 *
	 * Whatever is set up now, plus the cheaper one the bulk stages use if
	 * there is one, plus anything saved on the shelf of providers. Typing a
	 * model name from memory is how you find out an hour later that it was
	 * wrong by one character.
	 *
	 * @return string
	 */
	private static function model_choices() {
		$usual = trim( (string) Blogcraft_Settings::get( 'provider_model' ) );
		$known = array();

		foreach ( array( 'provider_model', 'provider_draft_model' ) as $setting ) {
			$one = trim( (string) Blogcraft_Settings::get( $setting ) );

			if ( '' !== $one ) {
				$known[ $one ] = $one;
			}
		}

		foreach ( Blogcraft_Connections::all() as $saved ) {
			$one = isset( $saved['values']['provider_model'] ) ? trim( (string) $saved['values']['provider_model'] ) : '';

			if ( '' !== $one ) {
				$known[ $one ] = $one;
			}
		}

		$out = '<select class="bc-select" name="post_model" id="bc_post_model">';

		$out .= sprintf(
			'<option value="">%s</option>',
			esc_html(
				'' === $usual
					? __( 'Whatever the settings say', 'dicecodes-ai-blog-writer' )
					: sprintf(
						/* translators: %s: the model named in the settings. */
						__( 'The usual one (%s)', 'dicecodes-ai-blog-writer' ),
						$usual
					)
			)
		);

		foreach ( $known as $one ) {
			if ( $one === $usual ) {
				continue;
			}

			$out .= sprintf( '<option value="%1$s">%2$s</option>', esc_attr( $one ), esc_html( $one ) );
		}

		$out .= '</select>';

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

		// Which model writes this one. Empty means the site default,
		// which is what every post used before there was a choice.
		if ( isset( $source['post_model'] ) ) {
			$out['model'] = sanitize_text_field( wp_unslash( $source['post_model'] ) );
		}

		return $out;
	}

	/**
	 * Queue a submitted topic.
	 *
	 * @return void
	 */
	/**
	 * Keep a brief for a connected app to collect.
	 *
	 * The same form as handle_queue reads, going somewhere else. The
	 * fields are identical because the brief is identical — the only
	 * difference is which side of the connection does the writing.
	 *
	 * @return void
	 */
	public static function handle_brief() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::QUEUE_ACTION, '_blogcraft_nonce' );

		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to write posts here.', 'dicecodes-ai-blog-writer' ) );
		}

		// Verified above by check_admin_referer().
		$topic = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';

		if ( '' === $topic ) {
			self::back( false, __( 'Please enter a topic.', 'dicecodes-ai-blog-writer' ) );
		}

		Blogcraft_Brief::save(
			array(
				'topic'     => $topic,
				'angle'     => isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '',
				'evidence'  => isset( $_POST['evidence'] ) ? sanitize_textarea_field( wp_unslash( $_POST['evidence'] ) ) : '',
				'overrides' => self::overrides_from( map_deep( wp_unslash( $_POST ), 'sanitize_textarea_field' ) ),
				'placement' => self::placement_from( map_deep( wp_unslash( $_POST ), 'sanitize_text_field' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is cast or sanitised inside placement_from().
			)
		);

		self::back(
			true,
			__( 'Saved. Ask your app to write it — it will collect this brief.', 'dicecodes-ai-blog-writer' )
		);
	}

	/**
	 * Start writing the post the form describes.
	 *
	 * @return void
	 */
	public static function handle_queue() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::QUEUE_ACTION, '_blogcraft_nonce' );

		// Verified above by check_admin_referer().
		$topic  = isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '';
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'draft';

		if ( '' === $topic ) {
			self::back( false, __( 'Please enter a topic.', 'dicecodes-ai-blog-writer' ) );
		}

		// Ticked inside the panel that asks what a post will include, so this
		// is the one place it can be switched off from. Settings can turn it
		// back on.
		if ( isset( $_POST['stop_asking'] ) ) {
			Blogcraft_Settings::set( 'ask_before_writing', false );
		}

		$instructions = isset( $_POST['instructions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instructions'] ) ) : '';
		$evidence     = isset( $_POST['evidence'] ) ? sanitize_textarea_field( wp_unslash( $_POST['evidence'] ) ) : '';
		$overrides    = self::overrides_from( map_deep( wp_unslash( $_POST ), 'sanitize_textarea_field' ) );
		$placement    = self::placement_from( map_deep( wp_unslash( $_POST ), 'sanitize_text_field' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is cast or sanitised inside placement_from().
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
						__( 'Skipped: too similar to a post you already have about "%s".', 'dicecodes-ai-blog-writer' ),
						$clash
					)
				);
			}

			self::back( false, __( 'The topic could not be queued.', 'dicecodes-ai-blog-writer' ) );
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
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::RUN_ACTION, '_blogcraft_nonce' );

		// Nothing queues work on the client path, so there is no queue to
		// push along and running the worker would only look like it failed.
		if ( Blogcraft_Mode::is_client() ) {
			self::back(
				false,
				__( 'There is no queue on this setup. Your app writes when you ask it to.', 'dicecodes-ai-blog-writer' )
			);
		}

		Blogcraft_Queue::reclaim_stale();

		$before   = Blogcraft_Queue::count_with_errors();
		$executed = Blogcraft_Worker::run();
		$after    = Blogcraft_Queue::count_with_errors();

		$message = sprintf(
			/* translators: %d: number of pipeline steps that ran. */
			_n( '%d step ran.', '%d steps ran.', $executed, 'dicecodes-ai-blog-writer' ),
			$executed
		);

		// Steps that ran and failed still count as steps, so saying only how
		// many ran would report a broken setup as a success.
		if ( $after > $before ) {
			self::back(
				false,
				$message . ' ' . __( 'Something went wrong. Dicecodes AI Blog Writer → Activity has the reason.', 'dicecodes-ai-blog-writer' )
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
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::BULK_ACTION, '_blogcraft_nonce' );

		// The form is not rendered on the client path, which is not the same
		// as the endpoint being shut. A job queued here would wait for a
		// provider that this site has deliberately not got.
		if ( Blogcraft_Mode::is_client() ) {
			self::back(
				false,
				__( 'Queueing a list of topics needs an API key. On this setup, ask your app to write.', 'dicecodes-ai-blog-writer' )
			);
		}

		$raw     = isset( $_POST['topics'] ) ? sanitize_textarea_field( wp_unslash( $_POST['topics'] ) ) : '';
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
				__( '%1$d queued, %2$d skipped as too similar to a post you have or one already waiting.', 'dicecodes-ai-blog-writer' ),
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
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::ROLLBACK_ACTION, '_blogcraft_nonce' );

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
				_n( '%d post moved to the trash.', '%d posts moved to the trash.', $trashed, 'dicecodes-ai-blog-writer' ),
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
