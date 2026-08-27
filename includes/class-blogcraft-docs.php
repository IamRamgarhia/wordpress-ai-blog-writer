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
			__( 'Help', 'blogcraft' ),
			__( 'Help', 'blogcraft' ),
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
			'providers'         => array(
				'title' => __( 'Connecting a provider', 'blogcraft' ),
				'body'  => array(
					__( 'Blogcraft has no AI of its own. It talks to a provider you choose, with a key from your account, and every request is billed to you by them. Nothing passes through the plugin author.', 'blogcraft' ),
					__( 'Three fields matter: the provider, the key, and the model id. Take the model id from the provider list linked beside the field rather than copying an example — providers retire model names without notice, and a retired name fails with an error that does not say so.', 'blogcraft' ),
					__( 'If you have no account anywhere, Groq and Google both have free tiers large enough to write with. Ollama and LM Studio run a model on your own machine for nothing at all, and need no key: pick one of those and leave the key blank.', 'blogcraft' ),
					__( 'On WordPress 7.0 and later, if a provider plugin is installed, "WordPress AI Client" appears at the top of the list. Choosing it means no key here at all: WordPress holds the credentials and routes the request. It only appears when it can actually work.', 'blogcraft' ),
					__( 'Leave the base URL blank unless you are pointing at a proxy or something of your own. The address shown in the empty field is what will be used.', 'blogcraft' ),
				),
			),
			'research'          => array(
				'title' => __( 'Research', 'blogcraft' ),
				'body'  => array(
					__( 'This is the single biggest lever on whether a post is worth reading. With research on, the model is handed current sources and writes from them. With it off it writes from memory, which is exactly the kind of page search engines now discount.', 'blogcraft' ),
					__( 'Everything here starts switched off, including the free sources. Blogcraft does not contact anybody you have not chosen: pasting a provider key is consent for that provider and for nothing else. Turning a source on is how you consent to it.', 'blogcraft' ),
					__( 'Wikipedia and Hacker News need no key, so a tick is all they take. Each is a different kind of material, which is the point: a reference work gives dates and definitions, a forum gives what actually happened to people who tried the thing.', 'blogcraft' ),
					__( 'Tavily and SerpApi are paid and return more current results. A SearXNG instance is free if you host one. You can also list URLs to be read for every post.', 'blogcraft' ),
					__( 'Whatever is found is also used to check the finished draft. If the article merely restates its sources, the score says so and the rewrite is told to fix it.', 'blogcraft' ),
				),
			),
			'voice'             => array(
				'title' => __( 'Describing your voice', 'blogcraft' ),
				'body'  => array(
					__( 'Everything on this card is sent with every request. It is the difference between posts that sound like your site and posts that sound like every other AI blog.', 'blogcraft' ),
					__( 'If you already have posts published, press "Learn from my posts". It measures how you actually write — sentence length, paragraph length, whether you use em dashes or contractions, whether you say "I" or "you" — and drafts the descriptions from your own titles. Nothing is saved until you press Save.', 'blogcraft' ),
					__( 'Posts Blogcraft wrote are left out of that. Learning a voice from your own output is a loop that ends with every post sounding like the first one it generated.', 'blogcraft' ),
					__( 'The experience field is the one worth spending time on. It is the only part of a post a model cannot produce.', 'blogcraft' ),
				),
			),
			'writing-a-post'    => array(
				'title' => __( 'Writing a post', 'blogcraft' ),
				'body'  => array(
					__( 'The topic is the only field you have to fill in. Everything else already has an answer, taken from your standing rules under "How it writes".', 'blogcraft' ),
					__( 'A sentence works better than a keyword. "How to choose a standing desk for a small home office" produces a better post than "standing desks".', 'blogcraft' ),
					__( 'The field asking what you know that nobody else does is the most valuable one on the screen. Your own figures, results and prices are used as fact, never invented beyond, and the finished draft is checked to make sure they actually reached the page.', 'blogcraft' ),
					__( 'The tabs beneath change this post only. Pictures holds the art direction; Publishing decides the category, tags, author and when it goes live.', 'blogcraft' ),
				),
			),
			'how-it-writes'     => array(
				'title' => __( 'How it writes', 'blogcraft' ),
				'body'  => array(
					__( 'This screen is the brief every post is written to. Start from a shape — a definitive guide, a listicle, a tutorial and so on — and each one sets around twenty fields at once, all of which stay editable.', 'blogcraft' ),
					__( 'Or paste the address of an article you admire. Blogcraft reads it and measures how it is built: length, sections, sentence and paragraph length, tables, lists, links out, how many figures it states, whether it says "I" or "you". Those measurements become your rules. Structure only — none of the wording is copied, kept, or shown to a model.', 'blogcraft' ),
					__( 'Anything shown in monospace is measured on the finished draft, not merely requested. The panel on the right shows the actual instructions the model receives.', 'blogcraft' ),
				),
			),
			'what-is-checked'   => array(
				'title' => __( 'What is checked, and why it matters', 'blogcraft' ),
				'body'  => array(
					__( 'Every draft is measured before it becomes a post, and every failed check is written back into the rewrite as an instruction rather than a number. That loop is the thing this plugin does that the others do not.', 'blogcraft' ),
					__( 'Among the checks: whether the opening answers the question in its first two sentences, whether every section that states a figure carries a link beside it, whether the draft says anything its sources do not already say, whether the subject appears in the title and in a heading, and whether the figures you supplied actually reached the page.', 'blogcraft' ),
					__( 'A check that cannot be assessed is skipped rather than failed. The score is what was earned out of what was offered, so a question that could not be asked costs nothing.', 'blogcraft' ),
					__( 'Two limits worth knowing. The figure check looks for a link in the same section as a number; it does not open that link and confirm the number is on the page at the other end, which is the shape most invented citations take. And the originality check compares the draft against the source excerpts it was given, so it can only see what it was shown — it is not a plagiarism service.', 'blogcraft' ),
					__( 'Anything scoring below your threshold is held for review instead of published, whatever you chose.', 'blogcraft' ),
				),
			),
			'pictures'          => array(
				'title' => __( 'Pictures', 'blogcraft' ),
				'body'  => array(
					__( 'The article decides what a picture shows; the Pictures controls decide how it looks. The model that wrote the piece describes the scene, which is the difference between a useful image and clip art of the headline.', 'blogcraft' ),
					__( 'Which service draws it is chosen under Settings. Pollinations needs no key. fal.ai and OpenAI charge per picture and are only ever used when you pick one of them, never as a fallback, so an image is never billed to you by accident.', 'blogcraft' ),
					__( 'If your writing provider is OpenAI and you also choose OpenAI for pictures, leave the image key blank and the same key does both. A key from any other company will not work, and the screen says which case you are in.', 'blogcraft' ),
					__( 'Text is kept out of generated images by default. Image models render lettering as convincing gibberish, and a thumbnail with misspelt words on it looks worse than one with none.', 'blogcraft' ),
				),
			),
			'automation'        => array(
				'title' => __( 'Automation', 'blogcraft' ),
				'body'  => array(
					__( 'None of this is needed to write a post by hand. Turn it on once the writing already looks right to you, not before.', 'blogcraft' ),
					__( 'Automatic posts are saved as drafts unless you say otherwise. The daily cap and the monthly token cap are both there to make a mistake cheap.', 'blogcraft' ),
					__( 'WordPress only runs scheduled work when somebody visits the site, so a quiet morning can push a post later than the hour you chose. That is WordPress, not Blogcraft.', 'blogcraft' ),
				),
			),
			'removal'           => array(
				'title' => __( 'If you delete this plugin', 'blogcraft' ),
				'body'  => array(
					__( 'Deleting Blogcraft leaves your settings, your writing rules and its record of every post it wrote exactly where they are. Install it again and everything is as you left it.', 'blogcraft' ),
					__( 'That is deliberate. WordPress asks whether you meant to delete the plugin; it has no way to ask whether you also meant to delete the rest, and dropping database tables cannot be undone. Deleting a plugin to reinstall it, to move hosts, or to clear a half-finished upload is an ordinary thing to do, and none of those mean the work should be thrown away.', 'blogcraft' ),
					__( 'There is a box on the settings screen if you do want it all gone. Ticking it is the only confirmation there will be, so it says so plainly next to it.', 'blogcraft' ),
					__( 'Your posts are never affected either way. They are ordinary WordPress posts from the moment they are created, and they stay whatever happens to this plugin.', 'blogcraft' ),
				),
			),
			'checking-it-works' => array(
				'title' => __( 'Checking it works', 'blogcraft' ),
				'body'  => array(
					__( 'The test on the settings screen sends one very short request and reports exactly what came back. It costs a fraction of a penny and it is the fastest way to tell a wrong key from a wrong model id from a provider that is simply down.', 'blogcraft' ),
					__( 'Saving a key runs it automatically, so a mistake is caught when you make it rather than on a scheduled run nobody is watching.', 'blogcraft' ),
					__( 'If a post fails, Activity says why. Rate limits are waited out rather than counted as failures, so a quota that resets in an hour does not burn a job.', 'blogcraft' ),
				),
			),
			'where-posts-go'    => array(
				'title' => __( 'Where posts go', 'blogcraft' ),
				'body'  => array(
					__( 'A finished post is a normal WordPress post. It is written as real blocks, so every paragraph and heading is editable in the editor rather than arriving as one unopenable lump.', 'blogcraft' ),
					__( 'By default it is saved as a draft, and you will find it under Posts. Anything that scored below your threshold is set to pending instead and listed under "Needs review", which only appears when something is actually waiting.', 'blogcraft' ),
					__( 'Images are downloaded into your Media library, not hotlinked. Alt text is set from the heading each one illustrates.', 'blogcraft' ),
				),
			),
			'privacy'           => array(
				'title' => __( 'What leaves your site', 'blogcraft' ),
				'body'  => array(
					__( 'Blogcraft contacts no servers of its own, collects no analytics, and sends nothing to the plugin author.', 'blogcraft' ),
					__( 'It contacts only the services you configure, and only when generating a post. Your topic, your style settings and any gathered research are sent to the AI provider you chose so the post can be written. The full list of services, with their terms and privacy policies, is in the readme under External Services.', 'blogcraft' ),
					__( 'API keys are stored encrypted where the site provides a key to encrypt with, are never rendered back into the page, and are stripped from every log line and error message.', 'blogcraft' ),
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
			wp_die( esc_html__( 'You are not allowed to access this page.', 'blogcraft' ) );
		}

		$sections = self::sections();

		echo '<div class="wrap blogcraft-page blogcraft-docs">';
		Blogcraft_Nav::render();

		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Help', 'blogcraft' ) . '</h1>';
		echo '<p>' . esc_html__( 'Everything the plugin does, and why it does it that way. Shipped with the plugin, so it is never out of date with the version you have installed.', 'blogcraft' ) . '</p>';
		echo '</div>';

		echo '<nav class="bc-doc-toc" aria-label="' . esc_attr__( 'Sections', 'blogcraft' ) . '"><ul>';

		foreach ( $sections as $anchor => $section ) {
			printf(
				'<li><a href="#%1$s">%2$s</a></li>',
				esc_attr( $anchor ),
				esc_html( $section['title'] )
			);
		}

		echo '</ul></nav>';

		foreach ( $sections as $anchor => $section ) {
			printf(
				'<section class="blogcraft-card" id="%1$s"><header><h2>%2$s</h2></header>',
				esc_attr( $anchor ),
				esc_html( $section['title'] )
			);

			foreach ( $section['body'] as $paragraph ) {
				printf( '<p class="bc-doc-line">%s</p>', esc_html( $paragraph ) );
			}

			echo '</section>';
		}

		echo '</div>';
	}
}
