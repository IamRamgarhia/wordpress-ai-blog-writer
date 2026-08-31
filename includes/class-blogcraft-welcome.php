<?php
/**
 * The first five minutes.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * What a new install sees before it is asked to write anything.
 *
 * The settings screen has six cards and about forty fields. That is the right
 * shape for somebody adjusting a working setup and the wrong shape for
 * somebody who has just activated the plugin — they cannot tell which three
 * of those forty decide whether the first post is any good, so they fill in a
 * key, type a topic, and get exactly the post that produces.
 *
 * This asks for those three, in order, and says why each one matters. Every
 * step can be skipped: an onboarding a reader cannot escape is a worse first
 * impression than no onboarding at all. It is shown once, and again only if
 * somebody goes looking for it.
 */
class Blogcraft_Welcome {

	/**
	 * Menu slug.
	 */
	const PAGE_SLUG = 'blogcraft-welcome';

	/**
	 * Option recording that the wizard has been finished or dismissed.
	 */
	const DONE_OPTION = 'blogcraft_welcomed';

	/**
	 * One-shot flag saying a fresh install has not been shown the wizard yet.
	 */
	const PENDING_OPTION = 'blogcraft_welcome_pending';

	/**
	 * Nonce action.
	 */
	const ACTION = 'blogcraft_welcome';

	/**
	 * Wire the screen.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_post_blogcraft_welcome_step', array( __CLASS__, 'handle_step' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Load the shared admin styling on this screen.
	 *
	 * @param string $hook Current admin page.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'blogcraft-admin',
			BLOGCRAFT_URL . 'assets/admin.css',
			array(),
			BLOGCRAFT_VERSION
		);
	}

	/**
	 * Register the screen without a menu entry.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			'',
			__( 'Welcome to Blogcraft', 'dicecodes-ai-blog-writer' ),
			__( 'Welcome to Blogcraft', 'dicecodes-ai-blog-writer' ),
			Blogcraft_Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Whether the wizard still has anything to offer.
	 *
	 * @return bool
	 */
	public static function is_done() {
		return (bool) get_option( self::DONE_OPTION, false );
	}

	/**
	 * Arm the one-time redirect, unless this install is plainly not new.
	 *
	 * @return void
	 */
	public static function arm() {
		if ( self::is_done() || Blogcraft_Provider_Registry::is_configured() ) {
			return;
		}

		update_option( self::PENDING_OPTION, 1, false );
	}

	/**
	 * Send a fresh install here once, on its first Blogcraft page load.
	 *
	 * The flag is consumed before the redirect, not after the wizard is
	 * finished. That matters: the first step tells people to go and set up a
	 * provider, and if this fired on every visit until somebody reached the
	 * last screen, following that instruction would bounce them straight back
	 * here — an onboarding that traps you on the way out is worse than none.
	 *
	 * @return void
	 */
	public static function maybe_redirect() {
		if ( wp_doing_ajax() || ! get_option( self::PENDING_OPTION, false ) ) {
			return;
		}

		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			return;
		}

		// Only from a Blogcraft screen. Hijacking an unrelated admin page
		// because a plugin was activated is the behaviour that makes people
		// distrust plugins, and it is what Guideline 11 is about.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 0 !== strpos( $page, 'blogcraft' ) || self::PAGE_SLUG === $page ) {
			return;
		}

		delete_option( self::PENDING_OPTION );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Save one step and move on.
	 *
	 * @return void
	 */
	public static function handle_step() {
		// Read then verify; Blogcraft_Request performs the check PHPCS cannot follow.
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::ACTION, $nonce );

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( 'voice' === $step ) {
			foreach ( array( 'voice_niche', 'voice_audience' ) as $field ) {
				if ( isset( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
					Blogcraft_Settings::set( $field, sanitize_textarea_field( wp_unslash( $_POST[ $field ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
				}
			}
		}

		if ( 'research' === $step ) {
			// Absence means no, which is the whole point of asking: a source
			// switched on here is switched on because somebody chose to.
			foreach ( array_keys( Blogcraft_Research::free_sources() ) as $source ) {
				Blogcraft_Settings::set( $source, isset( $_POST[ $source ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			}
		}

		if ( 'finish' === $step ) {
			update_option( self::DONE_OPTION, 1, false );
			delete_option( self::PENDING_OPTION );

			wp_safe_redirect( admin_url( 'admin.php?page=blogcraft-write' ) );
			exit;
		}

		$next = isset( $_POST['next'] ) ? sanitize_key( wp_unslash( $_POST['next'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'step' => '' === $next ? 'provider' : $next,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Render whichever step is being shown.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			return;
		}

		// Read-only step selection; the nonce guards the handler that writes.
		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'provider'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		echo '<div class="wrap blogcraft-wrap bc-welcome">';
		echo '<h1>' . esc_html__( 'Three things worth two minutes', 'dicecodes-ai-blog-writer' ) . '</h1>';
		echo '<p class="bc-welcome-lead">' . esc_html__( 'Almost everything that separates a post worth reading from filler is decided before any writing starts. These are the three that matter most. You can skip any of them and change all of them later.', 'dicecodes-ai-blog-writer' ) . '</p>';

		self::render_rail( $step );

		switch ( $step ) {
			case 'voice':
				self::step_voice();
				break;
			case 'research':
				self::step_research();
				break;
			case 'ready':
				self::step_ready();
				break;
			default:
				self::step_provider();
		}

		echo '</div>';
	}

	/**
	 * The step indicator.
	 *
	 * @param string $current Step being shown.
	 * @return void
	 */
	private static function render_rail( $current ) {
		$steps = array(
			'provider' => __( 'Connect a provider', 'dicecodes-ai-blog-writer' ),
			'voice'    => __( 'Say who you write for', 'dicecodes-ai-blog-writer' ),
			'research' => __( 'Choose what it may read', 'dicecodes-ai-blog-writer' ),
			'ready'    => __( 'Write something', 'dicecodes-ai-blog-writer' ),
		);

		$order = array_keys( $steps );
		$at    = array_search( $current, $order, true );
		$at    = ( false === $at ) ? 0 : (int) $at;

		echo '<ol class="bc-welcome-rail">';

		$i = 0;

		foreach ( $steps as $slug => $label ) {
			printf(
				'<li class="%1$s"><span>%2$d</span>%3$s</li>',
				esc_attr( $i < $at ? 'is-done' : ( $i === $at ? 'is-now' : 'is-todo' ) ),
				(int) ( $i + 1 ),
				esc_html( $label )
			);
			++$i;
		}

		echo '</ol>';
	}

	/**
	 * Open a step form.
	 *
	 * @param string $step This step's name.
	 * @param string $next The step to go to next.
	 * @return void
	 */
	private static function open_form( $step, $next ) {
		printf(
			'<form method="post" action="%s" class="blogcraft-card bc-welcome-card">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="blogcraft_welcome_step" />';
		printf( '<input type="hidden" name="step" value="%s" />', esc_attr( $step ) );
		printf( '<input type="hidden" name="next" value="%s" />', esc_attr( $next ) );
		Blogcraft_Request::nonce_field( self::ACTION );
	}

	/**
	 * The way out, offered on every step.
	 *
	 * @return void
	 */
	private static function skip_link() {
		printf(
			'<p class="bc-welcome-skip"><a href="%1$s">%2$s</a></p>',
			esc_url( admin_url( 'admin.php?page=blogcraft-write' ) ),
			esc_html__( 'Skip this and go to the Write screen', 'dicecodes-ai-blog-writer' )
		);
	}

	/**
	 * Step one: the only thing that is genuinely required.
	 *
	 * @return void
	 */
	private static function step_provider() {
		$ready = Blogcraft_Provider_Registry::is_configured();

		self::open_form( 'provider', 'voice' );

		echo '<h2>' . esc_html__( 'Connect a provider', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'Blogcraft has no AI of its own. It uses an account you own, with a key you paste in, and every request is billed to you by that provider rather than passing through anybody else.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '<p>' . esc_html__( 'If you have no account anywhere: Google and Groq both have free tiers big enough to write with, and Ollama runs a model on your own machine for nothing at all.', 'dicecodes-ai-blog-writer' ) . '</p>';

		if ( $ready ) {
			printf(
				'<p class="bc-welcome-ok">%s</p>',
				esc_html__( 'A provider is already set up. Nothing to do here.', 'dicecodes-ai-blog-writer' )
			);
		} else {
			// Marked so the settings screen can offer the way back. "Then
			// come back here" asked somebody to remember a page they had
			// been on for ten seconds and find it again in a sidebar of
			// twenty items, on the one screen where giving up is likeliest.
			printf(
				'<p><a class="button button-primary" href="%1$s">%2$s</a></p>',
				esc_url(
					add_query_arg(
						array(
							'page' => 'blogcraft-settings',
							'from' => 'welcome',
						),
						admin_url( 'admin.php' )
					) . '#bc-card-provider'
				),
				esc_html__( 'Set up a provider', 'dicecodes-ai-blog-writer' )
			);
			echo '<p class="description">' . esc_html__( 'There is a link back to this page at the top of that screen.', 'dicecodes-ai-blog-writer' ) . '</p>';
		}

		submit_button( __( 'Next', 'dicecodes-ai-blog-writer' ), 'secondary', 'submit', false );
		self::skip_link();
		echo '</form>';
	}

	/**
	 * Step two: the thing that stops posts sounding like everyone else's.
	 *
	 * @return void
	 */
	private static function step_voice() {
		self::open_form( 'voice', 'research' );

		echo '<h2>' . esc_html__( 'Say who you write for', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'This is sent with every request afterwards, and it is the single biggest reason two blogs using the same model do not read the same. Two sentences is enough.', 'dicecodes-ai-blog-writer' ) . '</p>';

		printf(
			'<p class="bc-field"><label for="bc_welcome_niche"><strong>%1$s</strong></label>'
			. '<textarea id="bc_welcome_niche" name="voice_niche" rows="2" class="large-text" placeholder="%2$s">%3$s</textarea></p>',
			esc_html__( 'What this blog is about', 'dicecodes-ai-blog-writer' ),
			esc_attr__( 'Home coffee equipment, tested properly rather than unboxed.', 'dicecodes-ai-blog-writer' ),
			esc_textarea( (string) Blogcraft_Settings::get( 'voice_niche' ) )
		);

		printf(
			'<p class="bc-field"><label for="bc_welcome_audience"><strong>%1$s</strong></label>'
			. '<textarea id="bc_welcome_audience" name="voice_audience" rows="2" class="large-text" placeholder="%2$s">%3$s</textarea></p>',
			esc_html__( 'Who is reading, and what they already know', 'dicecodes-ai-blog-writer' ),
			esc_attr__( 'People buying their first proper machine, who know what espresso is but not what a pressure profile is.', 'dicecodes-ai-blog-writer' ),
			esc_textarea( (string) Blogcraft_Settings::get( 'voice_audience' ) )
		);

		printf(
			'<p class="description">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'Already have posts published?', 'dicecodes-ai-blog-writer' ),
			esc_url( admin_url( 'admin.php?page=blogcraft-settings#bc-card-voice' ) ),
			esc_html__( 'Blogcraft can read them and fill this in from how you actually write.', 'dicecodes-ai-blog-writer' )
		);

		submit_button( __( 'Save and continue', 'dicecodes-ai-blog-writer' ), 'primary', 'submit', false );
		self::skip_link();
		echo '</form>';
	}

	/**
	 * Step three: current material, and the consent to fetch it.
	 *
	 * @return void
	 */
	private static function step_research() {
		self::open_form( 'research', 'ready' );

		echo '<h2>' . esc_html__( 'Choose what it may read', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'With research on, the model is handed current sources and writes from them, and the finished draft is checked against those same sources for whether it says anything they do not. With everything off it writes from memory, which dates badly and can cite nothing.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '<p>' . esc_html__( 'Nothing is contacted until you tick something here. Both of these are free and need no account.', 'dicecodes-ai-blog-writer' ) . '</p>';

		foreach ( Blogcraft_Research::free_sources() as $key => $label ) {
			printf(
				'<p><label><input type="checkbox" name="%1$s" value="1"%2$s /> %3$s</label></p>',
				esc_attr( $key ),
				checked( (bool) Blogcraft_Settings::get( $key ), true, false ),
				esc_html( $label )
			);
		}

		printf(
			'<p class="description">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'Paid search providers return more current results.', 'dicecodes-ai-blog-writer' ),
			esc_url( admin_url( 'admin.php?page=blogcraft-settings#bc-card-research' ) ),
			esc_html__( 'They are in Settings, and entirely optional.', 'dicecodes-ai-blog-writer' )
		);

		submit_button( __( 'Save and continue', 'dicecodes-ai-blog-writer' ), 'primary', 'submit', false );
		self::skip_link();
		echo '</form>';
	}

	/**
	 * The last screen: what actually makes a post good.
	 *
	 * @return void
	 */
	private static function step_ready() {
		self::open_form( 'finish', '' );

		echo '<h2>' . esc_html__( 'One thing before you write', 'dicecodes-ai-blog-writer' ) . '</h2>';
		echo '<p>' . esc_html__( 'On the Write screen there is a field asking what you know that nobody else does. It is the heaviest check on the finished post and the only part of a page a model genuinely cannot produce.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '<p>' . esc_html__( 'A number you measured. A price you paid. How long something actually took, or what went wrong when you tried it. One or two sentences is enough, and it is the difference between a page worth reading and a summary of pages that already exist.', 'dicecodes-ai-blog-writer' ) . '</p>';
		echo '<p>' . esc_html__( 'If nothing comes to mind, there is a button there that reads your topic and asks you four specific questions instead. It never answers them for you — invented facts are the one thing that would make every other check meaningless.', 'dicecodes-ai-blog-writer' ) . '</p>';

		printf(
			'<p class="description">%1$s <a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a></p>',
			esc_html__( 'Want the longer version first?', 'dicecodes-ai-blog-writer' ),
			esc_url( Blogcraft_Docs::site_url() ),
			esc_html__( 'Read the guides', 'dicecodes-ai-blog-writer' )
		);

		submit_button( __( 'Write my first post', 'dicecodes-ai-blog-writer' ), 'primary', 'submit', false );
		echo '</form>';
	}
}
