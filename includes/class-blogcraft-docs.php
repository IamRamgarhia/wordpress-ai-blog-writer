<?php
/**
 * The documentation, inside the plugin.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * A help screen that ships with the plugin rather than living on a website.
 *
 * Every "how this works" panel used to end in a link to a page on the author's
 * site. That page did not exist, so the one control on the screen offering to
 * explain more returned a 404 — worse than no link at all. It is also the wrong
 * shape: documentation on somebody's marketing site goes stale, disappears when
 * a domain lapses, and is unreachable on an intranet or a laptop on a train.
 *
 * So the writing lives here, versioned with the code that it describes. Every
 * section carries an anchor the help panels already point at.
 */
class Blogcraft_Docs {

	/**
	 * Submenu slug.
	 */
	const PAGE_SLUG = 'blogcraft-help';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 24 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Load the styling, and the script the contents rail wants.
	 *
	 * This screen used to load neither, which is why its table of contents
	 * rendered as a stack of bare underlined links: the markup was there and
	 * nothing had ever been written to style it.
	 *
	 * admin.js is a series of independent blocks, each returning early when
	 * the elements it wants are absent, so the provider and model pieces do
	 * nothing here. The one that is wanted marks the section being read.
	 *
	 * @param string $hook Current admin screen.
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

		wp_enqueue_script(
			'blogcraft-admin',
			BLOGCRAFT_URL . 'assets/admin.js',
			array(),
			BLOGCRAFT_VERSION,
			true
		);
	}

	/**
	 * Where the guides live online.
	 *
	 * The documentation that ships with the plugin is always true of the
	 * version installed, which is why it is the first link offered anywhere.
	 * This is the second one: the same sections, written at length, with the
	 * walkthroughs and worked examples that would bloat an admin panel.
	 *
	 * The section names are deliberately identical at both ends, so a help
	 * button already holding an anchor needs nothing added to deep-link.
	 *
	 * @param string $anchor Section anchor, or '' for the top.
	 * @return string
	 */
	public static function site_url( $anchor = '' ) {
		$base = 'https://dicecodes.com/blogcraft/';

		return ( '' === $anchor ) ? $base : $base . '#' . sanitize_title( $anchor );
	}

	/**
	 * The address of one section of this screen.
	 *
	 * @param string $anchor Section anchor, or '' for the top.
	 * @return string
	 */
	public static function url( $anchor = '' ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return ( '' === $anchor ) ? $url : $url . '#' . sanitize_key( $anchor );
	}

	/**
	 * Add the submenu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			Blogcraft_Admin::MENU_SLUG,
			__( 'Help', 'blogcraft-ai-writer' ),
			__( 'Help', 'blogcraft-ai-writer' ),
			Blogcraft_Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Every section, in reading order.
	 *
	 * @return array Anchor => array( title, paragraphs ).
	 */
	public static function sections() {
		return array(
			'quickstart'        => array(
				'title' => __( 'Start here', 'blogcraft-ai-writer' ),
				'lead'  => __( 'Five steps, about ten minutes. Nothing is contacted until you finish the first one.', 'blogcraft-ai-writer' ),
				'steps' => array(
					array(
						__( 'Choose a provider', 'blogcraft-ai-writer' ),
						__( 'Settings, Connect a provider. The free ones are at the top. Nothing is chosen for you.', 'blogcraft-ai-writer' ),
					),
					array(
						__( 'Paste the key', 'blogcraft-ai-writer' ),
						__( 'The link beside the field goes to the right page. A model on your own machine needs no key. Save.', 'blogcraft-ai-writer' ),
					),
					array(
						__( 'Pick a model', 'blogcraft-ai-writer' ),
						__( 'Press "Show the models on my account". Never type an id from memory.', 'blogcraft-ai-writer' ),
					),
					array(
						__( 'Describe your voice', 'blogcraft-ai-writer' ),
						__( 'Two sentences on your subject and your reader. Or press "Learn from my posts".', 'blogcraft-ai-writer' ),
					),
					array(
						__( 'Write one post', 'blogcraft-ai-writer' ),
						__( 'Give it a topic and read the score before you publish anything.', 'blogcraft-ai-writer' ),
					),
				),
			),
			'providers'         => array(
				'title'  => __( 'Connecting a provider', 'blogcraft-ai-writer' ),
				'lead'   => __( 'Blogcraft has no AI of its own. It uses your account, and your provider bills you directly.', 'blogcraft-ai-writer' ),
				'steps'  => array(
					array(
						__( 'Provider', 'blogcraft-ai-writer' ),
						__( 'Grouped by what it costs, free first. Nothing is preselected.', 'blogcraft-ai-writer' ),
					),
					array(
						__( 'Key', 'blogcraft-ai-writer' ),
						__( 'Follow the link beside the field. A local model needs none.', 'blogcraft-ai-writer' ),
					),
					array(
						__( 'Model id', 'blogcraft-ai-writer' ),
						__( 'Take it from your own account, not from an example. Retired ids fail with an error that does not say so.', 'blogcraft-ai-writer' ),
					),
				),
				'points' => array(
					__( 'Free, no key, no account: Ollama, LM Studio, Jan, llama.cpp. They run on this machine and contact nobody.', 'blogcraft-ai-writer' ),
					__( 'Free tier, a key but no card: Google, Groq, Mistral, Hugging Face. On OpenRouter the free ids end in :free.', 'blogcraft-ai-writer' ),
					__( 'Nothing is held back on a free provider. There is no paid tier here to unlock.', 'blogcraft-ai-writer' ),
					__( 'On WordPress 7.0 and later, "WordPress AI Client" appears when a provider plugin is installed. No key here at all.', 'blogcraft-ai-writer' ),
					__( 'Leave the base URL blank unless you are pointing at a proxy of your own.', 'blogcraft-ai-writer' ),
				),
			),
			'research'          => array(
				'title'  => __( 'Research', 'blogcraft-ai-writer' ),
				'lead'   => __( 'The biggest single lever on whether a post is worth reading.', 'blogcraft-ai-writer' ),
				'points' => array(
					__( 'Off, the model writes from memory. On, it writes from current sources.', 'blogcraft-ai-writer' ),
					__( 'Everything starts switched off. Turning a source on is how you consent to it.', 'blogcraft-ai-writer' ),
					__( 'Wikipedia and Hacker News need no key. Tavily and SerpApi are paid and more current.', 'blogcraft-ai-writer' ),
					__( 'A SearXNG instance you host is free. You can also list URLs to be read for every post.', 'blogcraft-ai-writer' ),
					__( 'What is found also checks the draft: merely restating your sources costs points.', 'blogcraft-ai-writer' ),
				),
			),
			'voice'             => array(
				'title'  => __( 'Describing your voice', 'blogcraft-ai-writer' ),
				'lead'   => __( 'Sent with every request. The reason two blogs on the same model do not read alike.', 'blogcraft-ai-writer' ),
				'points' => array(
					__( 'Two sentences on your subject and your reader is enough to start.', 'blogcraft-ai-writer' ),
					__( '"Learn from my posts" measures how you actually write and fills the fields in. Nothing saves until you press Save.', 'blogcraft-ai-writer' ),
					__( 'Posts Blogcraft wrote are left out of that. Learning from its own output ends with every post sounding like the first.', 'blogcraft-ai-writer' ),
					__( 'The experience field is the one worth time. It is the only part of a post a model cannot produce.', 'blogcraft-ai-writer' ),
				),
			),
			'writing-a-post'    => array(
				'title'  => __( 'Writing a post', 'blogcraft-ai-writer' ),
				'lead'   => __( 'One required field. Everything else already has an answer.', 'blogcraft-ai-writer' ),
				'steps'  => array(
					array(
						__( 'Topic', 'blogcraft-ai-writer' ),
						__( 'A sentence beats a keyword: "how to choose a standing desk for a small office".', 'blogcraft-ai-writer' ),
					),
					array(
						__( 'What only you know', 'blogcraft-ai-writer' ),
						__( 'Your figures, prices and results. Used as fact, and checked for on the finished draft.', 'blogcraft-ai-writer' ),
					),
					array(
						__( 'The tabs beneath', 'blogcraft-ai-writer' ),
						__( 'Pictures holds the art direction. Publishing sets the category, tags, author and time.', 'blogcraft-ai-writer' ),
					),
				),
				'points' => array(
					__( 'Stuck on the second one? A button asks you four specific questions instead. It never answers them for you.', 'blogcraft-ai-writer' ),
				),
			),
			'how-it-writes'     => array(
				'title'  => __( 'How it writes', 'blogcraft-ai-writer' ),
				'lead'   => __( 'The standing brief every post is written to.', 'blogcraft-ai-writer' ),
				'points' => array(
					__( 'Start from a shape — guide, listicle, tutorial — and about twenty fields are set at once. All stay editable.', 'blogcraft-ai-writer' ),
					__( 'Or paste the address of an article you admire. Its structure is measured and becomes your rules.', 'blogcraft-ai-writer' ),
					__( 'Structure only. No wording is copied, kept, or shown to a model.', 'blogcraft-ai-writer' ),
					__( 'Anything shown in monospace is measured on the finished draft, not merely asked for.', 'blogcraft-ai-writer' ),
				),
			),
			'what-is-checked'   => array(
				'title'  => __( 'What is checked', 'blogcraft-ai-writer' ),
				'lead'   => __( 'Every draft is measured before it becomes a post, and each failure is written back into the rewrite as an instruction.', 'blogcraft-ai-writer' ),
				'points' => array(
					__( 'Does the opening answer the question in its first two sentences?', 'blogcraft-ai-writer' ),
					__( 'Does every section stating a figure carry a link beside it?', 'blogcraft-ai-writer' ),
					__( 'Does the draft say anything its sources do not already say?', 'blogcraft-ai-writer' ),
					__( 'Is the subject in the title, a heading, the address and the first hundred words?', 'blogcraft-ai-writer' ),
					__( 'Did the figures you supplied actually reach the page?', 'blogcraft-ai-writer' ),
					__( 'A check that cannot be assessed is skipped, not failed. The score is what was earned out of what was offered.', 'blogcraft-ai-writer' ),
					__( 'Two limits: the figure check does not open the link to confirm the number, and the originality check only sees the sources it was given. It is not a plagiarism service.', 'blogcraft-ai-writer' ),
					__( 'Anything below your threshold is held for review instead of published.', 'blogcraft-ai-writer' ),
				),
			),
			'pictures'          => array(
				'title'  => __( 'Pictures', 'blogcraft-ai-writer' ),
				'lead'   => __( 'The article decides what a picture shows. These controls decide how it looks.', 'blogcraft-ai-writer' ),
				'points' => array(
					__( 'The model that wrote the piece describes the scene — the difference between a useful image and clip art of the headline.', 'blogcraft-ai-writer' ),
					__( 'Pollinations needs no key. fal.ai, OpenAI, Gemini and Grok charge per picture and run only when you pick them.', 'blogcraft-ai-writer' ),
					__( 'Writing on OpenAI and pictures on OpenAI? Leave the image key blank and one key does both.', 'blogcraft-ai-writer' ),
					__( 'Text is kept out of generated images by default. Image models render lettering as convincing gibberish.', 'blogcraft-ai-writer' ),
				),
			),
			'automation'        => array(
				'title'  => __( 'Automation', 'blogcraft-ai-writer' ),
				'lead'   => __( 'Not needed to write a post by hand. Switch it on once the writing already looks right to you.', 'blogcraft-ai-writer' ),
				'points' => array(
					__( 'Automatic posts are saved as drafts unless you say otherwise.', 'blogcraft-ai-writer' ),
					__( 'A daily cap and a monthly token cap are both there to make a mistake cheap.', 'blogcraft-ai-writer' ),
					__( 'WordPress runs scheduled work only when somebody visits, so a quiet morning can push a post past the hour you chose.', 'blogcraft-ai-writer' ),
				),
			),
			'removal'           => array(
				'title'  => __( 'If you delete this plugin', 'blogcraft-ai-writer' ),
				'lead'   => __( 'Deleting Blogcraft leaves your settings, your writing rules and your posts exactly where they are.', 'blogcraft-ai-writer' ),
				'points' => array(
					__( 'Install it again and everything is as you left it.', 'blogcraft-ai-writer' ),
					__( 'Deliberate: WordPress asks whether you meant to delete the plugin, not whether you meant to delete the rest.', 'blogcraft-ai-writer' ),
					__( 'There is a box in Settings if you do want it all gone. Ticking it is the only confirmation there will be.', 'blogcraft-ai-writer' ),
					__( 'Your posts are never affected either way. They are ordinary WordPress posts from the moment they are created.', 'blogcraft-ai-writer' ),
				),
			),
			'checking-it-works' => array(
				'title'  => __( 'Checking it works', 'blogcraft-ai-writer' ),
				'lead'   => __( 'The test on the settings screen sends one short request and reports exactly what came back.', 'blogcraft-ai-writer' ),
				'points' => array(
					__( 'It costs a fraction of a penny, and it separates a wrong key from a wrong model id from a provider that is down.', 'blogcraft-ai-writer' ),
					__( 'Saving a key runs it automatically, so a mistake is caught when you make it.', 'blogcraft-ai-writer' ),
					__( 'If a post fails, Activity says why.', 'blogcraft-ai-writer' ),
					__( 'Rate limits are waited out rather than counted as failures.', 'blogcraft-ai-writer' ),
				),
			),
			'where-posts-go'    => array(
				'title'  => __( 'Where posts go', 'blogcraft-ai-writer' ),
				'lead'   => __( 'A finished post is a normal WordPress post.', 'blogcraft-ai-writer' ),
				'points' => array(
					__( 'Written as real blocks, so every paragraph and heading is editable in the editor.', 'blogcraft-ai-writer' ),
					__( 'Saved as a draft by default. You will find it under Posts.', 'blogcraft-ai-writer' ),
					__( 'Anything below your threshold is set to pending and listed under "Needs review".', 'blogcraft-ai-writer' ),
					__( 'Images are downloaded into your Media library, not hotlinked. Alt text comes from the heading each one illustrates.', 'blogcraft-ai-writer' ),
				),
			),
			'privacy'           => array(
				'title'  => __( 'What leaves your site', 'blogcraft-ai-writer' ),
				'lead'   => __( 'Blogcraft contacts no servers of its own and sends nothing to the plugin author.', 'blogcraft-ai-writer' ),
				'points' => array(
					__( 'No analytics, no telemetry, nothing phoning home.', 'blogcraft-ai-writer' ),
					__( 'Only the services you configure, and only while a post is being written.', 'blogcraft-ai-writer' ),
					__( 'Your topic, your style settings and any gathered research go to the provider you chose.', 'blogcraft-ai-writer' ),
					__( 'Keys are stored encrypted, never rendered back into the page, and stripped from every log line.', 'blogcraft-ai-writer' ),
					__( 'The full list of services, with their terms and privacy policies, is in the readme under External Services.', 'blogcraft-ai-writer' ),
				),
			),
		);
	}

	/**
	 * Render the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'blogcraft-ai-writer' ) );
		}

		$sections = self::sections();

		echo '<div class="wrap blogcraft-page blogcraft-docs">';
		Blogcraft_Nav::render();

		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Help', 'blogcraft-ai-writer' ) . '</h1>';
		echo '<p>' . esc_html__( 'Everything the plugin does, and why it does it that way. Shipped with the plugin, so it is never out of date with the version you have installed.', 'blogcraft-ai-writer' ) . '</p>';

		// Everything above this line ships with the plugin and is therefore
		// always true of the version installed. These two are online, so they
		// can carry the things a shipped page cannot: walkthroughs, and a
		// place to say something is broken.
		printf(
			'<p class="bc-docs-links"><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a> <span aria-hidden="true">&middot;</span> <a href="%3$s" target="_blank" rel="noopener noreferrer">%4$s</a></p>',
			esc_url( self::site_url() ),
			esc_html__( 'Guides and walkthroughs at DiceCodes', 'blogcraft-ai-writer' ),
			esc_url( 'https://github.com/IamRamgarhia/wordpress-ai-blog-writer/issues' ),
			esc_html__( 'Report a problem', 'blogcraft-ai-writer' )
		);
		echo '</div>';

		// Two columns, the same component the settings screen uses. The
		// contents were a stack of a dozen bare links sitting between the
		// heading and the first section, pushing what somebody came to read
		// below the fold and looking like markup nobody had got to yet.
		echo '<div class="bc-settings-shell bc-doc-shell">';
		echo '<div class="bc-settings-main">';

		foreach ( $sections as $anchor => $section ) {
			printf(
				'<section class="blogcraft-card" id="%1$s"><header><h2>%2$s</h2></header>',
				esc_attr( $anchor ),
				esc_html( $section['title'] )
			);

			self::render_section( $section );

			echo '</section>';
		}

		echo '</div>';

		self::render_rail( $sections );

		echo '</div>';
		echo '</div>';
	}

	/**
	 * One section's contents: a line, then steps, then points.
	 *
	 * These used to be four to seven full paragraphs each, which read as an
	 * essay about the plugin rather than instructions for using it. Nobody
	 * reads a help screen from the top; they arrive with a question and scan.
	 * So each section now leads with one sentence, then breaks into numbered
	 * steps where there is a real order and short lines where there is not.
	 *
	 * @param array $section One entry from sections().
	 * @return void
	 */
	private static function render_section( $section ) {
		if ( ! empty( $section['lead'] ) ) {
			printf( '<p class="bc-doc-lead">%s</p>', esc_html( $section['lead'] ) );
		}

		if ( ! empty( $section['steps'] ) ) {
			echo '<ol class="bc-doc-steps">';

			foreach ( $section['steps'] as $step ) {
				printf(
					'<li><span class="bc-doc-step-name">%1$s</span> <span class="bc-doc-step-line">%2$s</span></li>',
					esc_html( $step[0] ),
					esc_html( $step[1] )
				);
			}

			echo '</ol>';
		}

		if ( ! empty( $section['points'] ) ) {
			echo '<ul class="bc-doc-points">';

			foreach ( $section['points'] as $point ) {
				printf( '<li>%s</li>', esc_html( $point ) );
			}

			echo '</ul>';
		}
	}

	/**
	 * The contents, beside the writing rather than on top of it.
	 *
	 * @param array $sections Anchor => section.
	 * @return void
	 */
	private static function render_rail( $sections ) {
		echo '<div class="bc-jump-col">';
		printf(
			'<nav class="bc-jump" aria-label="%s">',
			esc_attr__( 'Sections on this page', 'blogcraft-ai-writer' )
		);
		printf( '<h2 class="bc-jump-title">%s</h2>', esc_html__( 'On this page', 'blogcraft-ai-writer' ) );

		$number = 0;

		foreach ( $sections as $anchor => $section ) {
			++$number;

			// data-target is what the scroll spy in admin.js watches for, so
			// the rail answers "where am I" as well as "where can I go".
			printf(
				'<a class="bc-jump-item" href="#%1$s" data-target="%1$s"><span class="bc-jump-step">%2$02d</span><span class="bc-jump-text"><span class="bc-jump-label">%3$s</span></span></a>',
				esc_attr( $anchor ),
				(int) $number,
				esc_html( $section['title'] )
			);
		}

		echo '</nav>';
		echo '</div>';
	}
}
