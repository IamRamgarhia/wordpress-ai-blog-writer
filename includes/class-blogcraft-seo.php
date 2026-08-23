<?php
/**
 * Internal linking and structured data.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds internal links and JSON-LD to generated posts.
 *
 * Link targets come from a WP_Query against the site's real posts, never from
 * the model. Asking a model for internal links produces confident-looking URLs
 * that 404, because it cannot know what the site actually published.
 */
class Blogcraft_Seo {

	/**
	 * Meta key marking a post as generated.
	 */
	const GENERATED_META = '_blogcraft_generated';

	/**
	 * Meta key holding a cached word count and a hash of the content it counted.
	 */
	const WORDS_META = '_blogcraft_words';

	/**
	 * Find published posts related to a topic.
	 *
	 * @param string $topic   Topic to match against.
	 * @param int    $limit   Maximum results.
	 * @param int    $exclude Post id to exclude.
	 * @return array List of array( 'id', 'title', 'url' ).
	 */
	public static function related_posts( $topic, $limit = 5, $exclude = 0 ) {
		$words = array_slice( Blogcraft_Voice::to_list( str_replace( ' ', ',', (string) $topic ) ), 0, 6 );
		$stop  = array( 'the', 'a', 'an', 'and', 'or', 'for', 'of', 'to', 'in', 'on', 'how', 'why', 'what', 'is', 'are' );
		$terms = array();

		foreach ( $words as $word ) {
			$word = strtolower( trim( $word ) );

			if ( strlen( $word ) > 3 && ! in_array( $word, $stop, true ) ) {
				$terms[] = $word;
			}
		}

		$args = array(
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => (int) $limit,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);

		if ( ! empty( $terms ) ) {
			$args['s'] = implode( ' ', $terms );
		}

		// Excluding by post__not_in makes the database do the filtering and gets
		// slower as a site grows, which is why it is flagged. Asking for one
		// more row and dropping the unwanted one here costs nothing and scales.
		if ( $exclude > 0 ) {
			++$args['posts_per_page'];
		}

		$out = self::run_query( $args );

		// WordPress search requires every term to match, so a multi-word topic often
		// finds nothing even on a site with plenty of related posts. Falling back to
		// recent posts keeps "Read next" useful instead of silently empty.
		if ( empty( $out ) && isset( $args['s'] ) ) {
			unset( $args['s'] );
			$out = self::run_query( $args );
		}

		return self::without( $out, (int) $exclude, (int) $limit );
	}

	/**
	 * Drop one post from a result set and trim it back to length.
	 *
	 * @param array $posts   Flattened posts.
	 * @param int   $exclude Post id to leave out, or 0 for none.
	 * @param int   $limit   How many to keep.
	 * @return array
	 */
	private static function without( $posts, $exclude, $limit ) {
		if ( $exclude > 0 ) {
			$kept = array();

			foreach ( $posts as $post ) {
				if ( (int) $post['id'] !== $exclude ) {
					$kept[] = $post;
				}
			}

			$posts = $kept;
		}

		return array_slice( $posts, 0, max( 1, $limit ) );
	}

	/**
	 * Run one WP_Query and flatten it to id/title/url triples.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	private static function run_query( $args ) {
		$query = new WP_Query( $args );
		$out   = array();

		foreach ( $query->posts as $post ) {
			$out[] = array(
				'id'    => (int) $post->ID,
				'title' => get_the_title( $post ),
				'url'   => get_permalink( $post ),
			);
		}

		wp_reset_postdata();

		return $out;
	}

	/**
	 * Render a "Read next" list of real internal links.
	 *
	 * @param array $related Output of related_posts().
	 * @return string Block markup, empty when there is nothing to link to.
	 */
	public static function render_related_block( $related ) {
		if ( empty( $related ) ) {
			return '';
		}

		$items = '';

		foreach ( $related as $item ) {
			if ( empty( $item['url'] ) || empty( $item['title'] ) ) {
				continue;
			}

			$items .= sprintf(
				"<!-- wp:list-item -->\n<li><a href=\"%s\">%s</a></li>\n<!-- /wp:list-item -->\n",
				esc_url( $item['url'] ),
				esc_html( $item['title'] )
			);
		}

		if ( '' === $items ) {
			return '';
		}

		return Blogcraft_Blocks::heading( __( 'Read next', 'blogcraft' ), 2 )
			. "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n" . $items . "</ul>\n<!-- /wp:list -->\n\n";
	}

	/**
	 * Phrases from a title worth using as link text, longest first.
	 *
	 * A link reading "standing desk height" inside a sentence is worth more
	 * than the same link sitting in a list at the bottom that nobody scrolls
	 * to. Leading question words and articles are dropped because "How to
	 * choose a standing desk" almost never appears verbatim in prose, while
	 * "choose a standing desk" often does.
	 *
	 * @param string $title Post title.
	 * @return array Candidate phrases, longest first.
	 */
	public static function anchor_phrases( $title ) {
		$title = trim( wp_strip_all_tags( html_entity_decode( (string) $title, ENT_QUOTES, 'UTF-8' ) ) );

		// Anything after a colon or dash is a subtitle, not the subject.
		$title = preg_split( '/\s+[:\x{2013}\x{2014}-]\s+/u', $title, 2 )[0];
		$words = preg_split( '/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY );

		if ( count( $words ) < 2 ) {
			return array();
		}

		$lead  = array( 'how', 'what', 'why', 'when', 'where', 'which', 'who', 'the', 'a', 'an', 'to', 'is', 'are', 'do', 'does', 'your', 'my', 'our', 'best', 'top' );
		$out   = array();
		$total = count( $words );

		// Whole title first, then progressively drop leading filler.
		for ( $start = 0; $start < $total - 1; $start++ ) {
			$phrase = implode( ' ', array_slice( $words, $start ) );

			if ( strlen( $phrase ) >= 12 ) {
				$out[] = $phrase;
			}

			$next = strtolower( preg_replace( '/[^\p{L}\p{N}]/u', '', $words[ $start ] ) );

			if ( ! in_array( $next, $lead, true ) ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Link phrases in the body to related posts, once each.
	 *
	 * Deliberately timid. Every complaint about automated internal linking is
	 * that it links the wrong words to the wrong page, so this only ever links
	 * text that matches a real post's title, only inside paragraphs, only in
	 * paragraphs that carry no link already, and only once per target. Fewer
	 * links that are all correct beats more links that are mostly noise.
	 *
	 * @param string $content Rendered post content.
	 * @param array  $related Related posts, each with url and title.
	 * @param int    $limit   Most links to add.
	 * @return array Keys: content, linked (list of post ids linked).
	 */
	public static function link_in_text( $content, $related, $limit = 3 ) {
		$content = (string) $content;
		$linked  = array();

		if ( empty( $related ) || $limit < 1 ) {
			return array(
				'content' => $content,
				'linked'  => $linked,
			);
		}

		$found = preg_match_all(
			'/<!--\s*wp:paragraph.*?<!--\s*\/wp:paragraph\s*-->/s',
			$content,
			$matches,
			PREG_OFFSET_CAPTURE
		);

		if ( ! $found ) {
			return array(
				'content' => $content,
				'linked'  => $linked,
			);
		}

		// Rebuilt back to front so earlier offsets stay valid.
		$blocks = array_reverse( $matches[0] );
		$taken  = array();

		foreach ( $blocks as $block ) {
			if ( count( $linked ) >= (int) $limit ) {
				break;
			}

			$markup = (string) $block[0];

			// A paragraph that already links somewhere is left alone.
			if ( preg_match( '/<a\s[^>]*href=/i', $markup ) ) {
				continue;
			}

			foreach ( $related as $item ) {
				if ( empty( $item['url'] ) || empty( $item['title'] ) ) {
					continue;
				}

				$id = isset( $item['id'] ) ? (int) $item['id'] : 0;

				if ( isset( $taken[ $item['url'] ] ) ) {
					continue;
				}

				$replaced = self::link_phrase( $markup, self::anchor_phrases( $item['title'] ), (string) $item['url'] );

				if ( '' === $replaced ) {
					continue;
				}

				$content = substr_replace( $content, $replaced, (int) $block[1], strlen( $markup ) );

				$taken[ $item['url'] ] = true;
				$linked[]              = ( $id > 0 ) ? $id : $item['url'];
				break;
			}
		}

		return array(
			'content' => $content,
			'linked'  => $linked,
		);
	}

	/**
	 * Wrap the first clean occurrence of any phrase in a link.
	 *
	 * @param string $markup   One paragraph block.
	 * @param array  $phrases  Candidate phrases, longest first.
	 * @param string $url      Where the link should go.
	 * @return string The rewritten block, or '' when nothing matched.
	 */
	private static function link_phrase( $markup, $phrases, $url ) {
		// Search the paragraph's own text only. Matching inside the opening
		// block comment would put an anchor tag in a place Gutenberg treats as
		// metadata, and quietly corrupt the block.
		$body = strpos( $markup, '<p' );

		if ( false === $body ) {
			return '';
		}

		$text = substr( $markup, $body );

		foreach ( $phrases as $phrase ) {
			$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/iu';

			if ( ! preg_match( $pattern, $text, $hit, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$at      = (int) $body + (int) $hit[0][1];
			$matched = (string) $hit[0][0];
			$anchor  = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $matched ) );

			return substr_replace( $markup, $anchor, $at, strlen( $matched ) );
		}

		return '';
	}

	/**
	 * Meta keys used by the SEO plugins that store their fields as post meta.
	 *
	 * All In One SEO is absent deliberately: it keeps titles and descriptions in
	 * its own table rather than post meta, so writing meta would silently do
	 * nothing. Better to leave its fields visibly unset than to appear filled.
	 *
	 * @return array Plugin id => array( title key, description key ).
	 */
	public static function seo_meta_keys() {
		return array(
			'yoast'    => array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ),
			'rankmath' => array( 'rank_math_title', 'rank_math_description' ),
			'seopress' => array( '_seopress_titles_title', '_seopress_titles_desc' ),
		);
	}

	/**
	 * Which supported SEO plugin is active, if any.
	 *
	 * @return string One of yoast, rankmath, seopress, aioseo, or '' for none.
	 */
	public static function active_seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}

		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return 'seopress';
		}

		if ( defined( 'AIOSEO_VERSION' ) ) {
			return 'aioseo';
		}

		return '';
	}

	/**
	 * Fill the active SEO plugin's title and description fields.
	 *
	 * @param int    $post_id     Post to write to.
	 * @param string $title       SEO title.
	 * @param string $description Meta description.
	 * @return bool Whether anything was written.
	 */
	public static function write_seo_meta( $post_id, $title, $description ) {
		$plugin = self::active_seo_plugin();
		$keys   = self::seo_meta_keys();

		if ( '' === $plugin || ! isset( $keys[ $plugin ] ) ) {
			return false;
		}

		$title       = sanitize_text_field( (string) $title );
		$description = sanitize_text_field( (string) $description );

		if ( '' !== $title ) {
			update_post_meta( (int) $post_id, $keys[ $plugin ][0], $title );
		}

		if ( '' !== $description ) {
			update_post_meta( (int) $post_id, $keys[ $plugin ][1], $description );
		}

		return true;
	}

	/**
	 * Build FAQPage structured data from an article's FAQ entries.
	 *
	 * @param array $article Article structure.
	 * @return array Empty when the article has no usable FAQ.
	 */
	public static function build_faq_schema( $article ) {
		if ( empty( $article['faq'] ) || ! is_array( $article['faq'] ) ) {
			return array();
		}

		$entities = array();

		foreach ( $article['faq'] as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['question'] ) || empty( $entry['answer'] ) ) {
				continue;
			}

			$entities[] = array(
				'@type'          => 'Question',
				'name'           => wp_strip_all_tags( (string) $entry['question'] ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wp_strip_all_tags( (string) $entry['answer'] ),
				),
			);
		}

		if ( empty( $entities ) ) {
			return array();
		}

		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);
	}

	/**
	 * Build a table of contents from an article's own headings.
	 *
	 * Anchors are not emitted: WordPress does not add heading ids by default, so
	 * linking to them would produce links that go nowhere. This is a readable
	 * outline, which is what a reader uses it for.
	 *
	 * @param array $article Article structure.
	 * @param bool  $enabled Whether the blueprint actually asked for one.
	 * @param int   $minimum Fewest sections worth a contents list.
	 * @return string Block markup, empty when not worth rendering.
	 */
	public static function render_toc( $article, $enabled, $minimum = 4 ) {
		if ( ! $enabled ) {
			return '';
		}

		if ( empty( $article['sections'] ) || ! is_array( $article['sections'] ) ) {
			return '';
		}

		$headings = array();

		foreach ( $article['sections'] as $section ) {
			if ( is_array( $section ) && ! empty( $section['heading'] ) ) {
				$headings[] = (string) $section['heading'];
			}
		}

		if ( count( $headings ) < $minimum ) {
			return '';
		}

		return Blogcraft_Blocks::heading( __( 'What is covered', 'blogcraft' ), 2 )
			. Blogcraft_Blocks::unordered_list( $headings );
	}

	/**
	 * Whether another plugin already outputs Article schema.
	 *
	 * Emitting a second competing graph is worse than emitting none, so this
	 * defers to Yoast, Rank Math, AIOSEO and SEOPress when any is active.
	 *
	 * @return bool
	 */
	public static function schema_handled_elsewhere() {
		return defined( 'WPSEO_VERSION' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'AIOSEO_VERSION' )
			|| defined( 'SEOPRESS_VERSION' );
	}

	/**
	 * Wire the front-end hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_schema' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_head_meta' ), 5 );
		add_filter( 'the_content', array( __CLASS__, 'append_author_box' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_author_box_styles' ), 6 );
	}

	/**
	 * The few rules the byline block needs to not look broken.
	 *
	 * Inline rather than a stylesheet, because a separate file would cost
	 * every visitor a request for roughly a dozen declarations. Deliberately
	 * minimal and colour-free: it inherits the theme's type and palette, so
	 * it reads as part of the page rather than as something bolted on.
	 *
	 * @return void
	 */
	public static function print_author_box_styles() {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post_id = get_the_ID();

		if ( ! $post_id || ! get_post_meta( $post_id, self::GENERATED_META, true ) ) {
			return;
		}

		if ( ! apply_filters( 'blogcraft_show_author_box', true, $post_id ) ) {
			return;
		}

		echo '<style id="blogcraft-author-box">'
			. '.blogcraft-author-box{margin:2.5em 0 0;padding:1.25em 0 0;border-top:1px solid currentColor;opacity:.85}'
			. '.blogcraft-author-box p{margin:0 0 .4em}'
			. '.blogcraft-author-name{font-weight:600}'
			. '.blogcraft-author-role{font-weight:400;opacity:.75}'
			. '.blogcraft-author-bio,.blogcraft-author-reviewer,.blogcraft-author-links{font-size:.9em;opacity:.8}'
			. '.blogcraft-author-links a{margin-right:.25em}'
			. '</style>';
	}

	/**
	 * Print the description and sharing tags when nothing else will.
	 *
	 * Blogcraft writes a meta description for every post it generates, and on
	 * a site with an SEO plugin that description goes into the plugin's own
	 * field and gets used. On a site without one it went nowhere at all: the
	 * text was written, stored, measured by the scorecard, and then never
	 * emitted, because WordPress itself outputs no description or social tags.
	 *
	 * Deliberately narrow. This covers posts Blogcraft generated, not the
	 * whole site — filling in head tags for every page is what an SEO plugin
	 * is for, and quietly becoming one would be both scope creep and a source
	 * of duplicate tags. Canonical is left alone for the same reason: core
	 * already emits it via rel_canonical().
	 *
	 * @return void
	 */
	public static function print_head_meta() {
		if ( ! is_singular( 'post' ) || self::schema_handled_elsewhere() ) {
			return;
		}

		$post_id = get_the_ID();

		if ( ! $post_id || ! get_post_meta( $post_id, self::GENERATED_META, true ) ) {
			return;
		}

		/**
		 * Whether Blogcraft should emit head tags for this post.
		 *
		 * A theme that already prints its own Open Graph tags can turn this
		 * off rather than ending up with two of everything.
		 *
		 * @param bool $enabled Whether to print.
		 * @param int  $post_id Post being rendered.
		 */
		if ( ! apply_filters( 'blogcraft_print_head_meta', true, $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$title       = wp_strip_all_tags( get_the_title( $post_id ) );
		$description = trim( wp_strip_all_tags( (string) $post->post_excerpt ) );
		$url         = get_permalink( $post_id );
		$image       = has_post_thumbnail( $post_id ) ? get_the_post_thumbnail_url( $post_id, 'full' ) : '';

		if ( '' !== $description ) {
			printf(
				'<meta name="description" content="%s" />' . "\n",
				esc_attr( $description )
			);
			printf(
				'<meta property="og:description" content="%s" />' . "\n",
				esc_attr( $description )
			);
			printf(
				'<meta name="twitter:description" content="%s" />' . "\n",
				esc_attr( $description )
			);
		}

		printf(
			'<meta property="og:title" content="%s" />' . "\n",
			esc_attr( $title )
		);
		printf(
			'<meta name="twitter:title" content="%s" />' . "\n",
			esc_attr( $title )
		);
		printf(
			'<meta property="og:type" content="article" />' . "\n"
		);
		printf(
			'<meta property="og:url" content="%s" />' . "\n",
			esc_url( $url )
		);
		printf(
			'<meta property="og:site_name" content="%s" />' . "\n",
			esc_attr( get_bloginfo( 'name' ) )
		);
		printf(
			'<meta property="article:published_time" content="%s" />' . "\n",
			esc_attr( get_post_time( 'c', true, $post ) )
		);
		printf(
			'<meta property="article:modified_time" content="%s" />' . "\n",
			esc_attr( get_post_modified_time( 'c', true, $post ) )
		);

		// A card with no picture is a link with a headline, which is what the
		// summary type is for. Claiming the large type without an image gets
		// the post rendered as a bare link anyway.
		printf(
			'<meta name="twitter:card" content="%s" />' . "\n",
			esc_attr( '' === $image ? 'summary' : 'summary_large_image' )
		);

		if ( '' !== $image ) {
			printf(
				'<meta property="og:image" content="%s" />' . "\n",
				esc_url( $image )
			);
			printf(
				'<meta name="twitter:image" content="%s" />' . "\n",
				esc_url( $image )
			);
		}
	}

	/**
	 * Print BlogPosting JSON-LD for generated posts.
	 *
	 * @return void
	 */
	public static function print_schema() {
		if ( ! is_singular( 'post' ) || self::schema_handled_elsewhere() ) {
			return;
		}

		$post_id = get_the_ID();

		if ( ! $post_id || ! get_post_meta( $post_id, self::GENERATED_META, true ) ) {
			return;
		}

		$graph = self::build_schema( $post_id );

		if ( empty( $graph ) ) {
			return;
		}

		$graphs = array( $graph );

		$crumbs = self::build_breadcrumbs( $post_id );

		if ( ! empty( $crumbs ) ) {
			$graphs[] = $crumbs;
		}

		// Kept although Google retired FAQ rich results in May 2026: the type
		// is still valid, still describes the page correctly, and is still read
		// by engines other than Google's result page. It is simply no longer
		// worth treating as an SEO feature.
		$faq = get_post_meta( $post_id, '_blogcraft_faq_schema', true );

		if ( is_array( $faq ) && ! empty( $faq ) ) {
			$graphs[] = $faq;
		}

		foreach ( $graphs as $entry ) {
			printf(
				'<script type="application/ld+json">%s</script>',
				wp_json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
	}

	/**
	 * Build the BlogPosting graph for a post.
	 *
	 * @param int $post_id Post id.
	 * @return array
	 */
	public static function build_schema( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return array();
		}

		$graph = array(
			'@context'         => 'https://schema.org',
			'@type'            => 'BlogPosting',
			'headline'         => wp_strip_all_tags( get_the_title( $post ) ),
			'datePublished'    => get_the_date( 'c', $post ),
			'dateModified'     => get_the_modified_date( 'c', $post ),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => get_permalink( $post ),
			),
		);

		$excerpt = trim( (string) $post->post_excerpt );

		if ( '' !== $excerpt ) {
			$graph['description'] = wp_strip_all_tags( $excerpt );
		}

		$graph['wordCount'] = self::word_count( $post );

		$categories = get_the_category( (int) $post->ID );

		if ( ! empty( $categories ) && isset( $categories[0]->name ) ) {
			$graph['articleSection'] = $categories[0]->name;
		}

		$author = get_the_author_meta( 'display_name', (int) $post->post_author );

		if ( '' !== (string) $author ) {
			// Credentials and a link out are what separate a byline from a
			// name. Both are what search engines and answer engines read as an
			// expertise signal, and neither costs anything to emit.
			$person = array(
				'@type' => 'Person',
				'name'  => $author,
				'url'   => get_author_posts_url( (int) $post->post_author ),
			);

			$credentials = trim( (string) Blogcraft_Settings::get( 'author_credentials' ) );

			if ( '' !== $credentials ) {
				$person['jobTitle'] = $credentials;
			}

			// Profiles elsewhere are how an entity is identified as the same
			// person across the web, which is the part a name on its own
			// cannot establish. Taken from the author's own WordPress profile
			// rather than a setting: the fields are already there, already
			// per-author, and already the place someone would think to look.
			$same_as = self::author_profiles( (int) $post->post_author );

			if ( ! empty( $same_as ) ) {
				$person['sameAs'] = $same_as;
			}

			$graph['author'] = $person;
		}

		$reviewer = trim( (string) Blogcraft_Settings::get( 'reviewer_name' ) );

		if ( '' !== $reviewer ) {
			// A second named expert who checked the piece. The strongest signal
			// available to a site publishing with AI help, and the one thing a
			// generated post cannot claim for itself.
			$checked = array(
				'@type' => 'Person',
				'name'  => $reviewer,
			);

			$reviewer_credentials = trim( (string) Blogcraft_Settings::get( 'reviewer_credentials' ) );

			if ( '' !== $reviewer_credentials ) {
				$checked['jobTitle'] = $reviewer_credentials;
			}

			$graph['reviewedBy'] = $checked;
		}

		$graph['publisher'] = self::publisher();

		$thumbnail = get_the_post_thumbnail_url( $post, 'full' );

		if ( $thumbnail ) {
			$graph['image'] = $thumbnail;
		}

		return $graph;
	}

	/**
	 * An author's profiles elsewhere on the web.
	 *
	 * WordPress ships a website field on every user, and most profile plugins
	 * add their own contact methods to the same place. Both are read here, so
	 * this works on a bare install and gets better on one where the site
	 * already keeps that information.
	 *
	 * @param int $author_id User id.
	 * @return array List of URLs.
	 */
	public static function author_profiles( $author_id ) {
		$out = array();

		$site = trim( (string) get_the_author_meta( 'user_url', $author_id ) );

		if ( '' !== $site ) {
			$out[] = $site;
		}

		foreach ( array_keys( wp_get_user_contact_methods( null ) ) as $field ) {
			$value = trim( (string) get_the_author_meta( $field, $author_id ) );

			// Contact methods hold handles as often as addresses, and a bare
			// handle is not something sameAs can point at.
			if ( '' !== $value && 0 === strpos( $value, 'http' ) ) {
				$out[] = $value;
			}
		}

		return array_values( array_unique( array_map( 'esc_url_raw', $out ) ) );
	}

	/**
	 * Append a byline block readers can actually see.
	 *
	 * The author markup above is real and correct, and it is also invisible:
	 * it speaks to parsers and says nothing to the person reading. Google's
	 * own guidance on helpful content asks whether a reader can tell who
	 * wrote something and find out about them — which is a question about the
	 * page, not about its JSON-LD.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function append_author_box( $content ) {
		if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();

		if ( ! $post_id || ! get_post_meta( $post_id, self::GENERATED_META, true ) ) {
			return $content;
		}

		/**
		 * Whether to append the byline block to a generated post.
		 *
		 * Themes that already print an author box want this off rather than
		 * two of them stacked.
		 *
		 * @param bool $enabled Whether to append.
		 * @param int  $post_id Post being rendered.
		 */
		if ( ! apply_filters( 'blogcraft_show_author_box', true, $post_id ) ) {
			return $content;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post ) {
			return $content;
		}

		$author_id = (int) $post->post_author;
		$name      = trim( (string) get_the_author_meta( 'display_name', $author_id ) );

		if ( '' === $name ) {
			return $content;
		}

		$bio         = trim( (string) get_the_author_meta( 'description', $author_id ) );
		$credentials = trim( (string) Blogcraft_Settings::get( 'author_credentials' ) );
		$reviewer    = trim( (string) Blogcraft_Settings::get( 'reviewer_name' ) );

		$box = '<div class="blogcraft-author-box">';

		$box .= '<p class="blogcraft-author-name">' . sprintf(
			/* translators: %s: author name. */
			esc_html__( 'Written by %s', 'blogcraft' ),
			'<a href="' . esc_url( get_author_posts_url( $author_id ) ) . '" rel="author">' . esc_html( $name ) . '</a>'
		);

		if ( '' !== $credentials ) {
			$box .= ' <span class="blogcraft-author-role">' . esc_html( $credentials ) . '</span>';
		}

		$box .= '</p>';

		if ( '' !== $bio ) {
			$box .= '<p class="blogcraft-author-bio">' . esc_html( $bio ) . '</p>';
		}

		if ( '' !== $reviewer ) {
			$reviewer_credentials = trim( (string) Blogcraft_Settings::get( 'reviewer_credentials' ) );

			$box .= '<p class="blogcraft-author-reviewer">' . esc_html(
				'' === $reviewer_credentials
					? sprintf(
						/* translators: %s: reviewer name. */
						__( 'Reviewed by %s', 'blogcraft' ),
						$reviewer
					)
					: sprintf(
						/* translators: 1: reviewer name. 2: their role or credentials. */
						__( 'Reviewed by %1$s, %2$s', 'blogcraft' ),
						$reviewer,
						$reviewer_credentials
					)
			) . '</p>';
		}

		$links = self::author_profiles( $author_id );

		if ( ! empty( $links ) ) {
			$rendered = array();

			foreach ( array_slice( $links, 0, 4 ) as $link ) {
				$host = wp_parse_url( $link, PHP_URL_HOST );

				$rendered[] = '<a href="' . esc_url( $link ) . '" rel="nofollow noopener me" target="_blank">'
					. esc_html( is_string( $host ) ? preg_replace( '/^www\./', '', $host ) : $link )
					. '</a>';
			}

			$box .= '<p class="blogcraft-author-links">' . implode( ' · ', $rendered ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$box .= '</div>';

		return $content . $box;
	}

	/**
	 * How many words a post has, without recounting them on every page view.
	 *
	 * This is the only Blogcraft code that runs for an ordinary visitor, and it
	 * was flattening the whole post with six regular expressions and then
	 * tokenising it, on every request, to produce one integer. Measured at
	 * roughly a third of a millisecond for a 2,000-word article — small, but
	 * paid by every reader on every view, forever, to compute something that
	 * only changes when the post is edited.
	 *
	 * Keyed on a hash of the content rather than on post_modified_gmt. The
	 * timestamp has one-second resolution, so two edits inside the same second
	 * leave it unchanged and the stale count is served as though it were
	 * current — which a test caught on the first run. Hashing costs a fraction
	 * of what the counting does and is exact.
	 *
	 * @param WP_Post $post Post to count.
	 * @return int
	 */
	public static function word_count( $post ) {
		$content = (string) $post->post_content;
		$key     = md5( $content );
		$stored  = get_post_meta( (int) $post->ID, self::WORDS_META, true );

		if ( is_array( $stored ) && isset( $stored['of'], $stored['words'] ) && $stored['of'] === $key ) {
			return (int) $stored['words'];
		}

		$words = count( Blogcraft_Metrics::words( Blogcraft_Metrics::plain_text( $content ) ) );

		update_post_meta(
			(int) $post->ID,
			self::WORDS_META,
			array(
				'of'    => $key,
				'words' => $words,
			)
		);

		return $words;
	}

	/**
	 * The site as an organisation.
	 *
	 * Organisation and Person markup is how an answer engine works out which
	 * entity a page belongs to, which is a different job from earning a rich
	 * result and outlasted several of the formats that did.
	 *
	 * @return array
	 */
	public static function publisher() {
		$publisher = array(
			'@type' => 'Organization',
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);

		$logo_id = (int) get_theme_mod( 'custom_logo' );

		if ( $logo_id > 0 ) {
			$logo = wp_get_attachment_image_url( $logo_id, 'full' );

			if ( $logo ) {
				$publisher['logo'] = array(
					'@type' => 'ImageObject',
					'url'   => $logo,
				);
			}
		}

		return $publisher;
	}

	/**
	 * Breadcrumbs for a post, as a graph.
	 *
	 * One of the few markup types that still earns a visible search result, and
	 * the plugin was not emitting it.
	 *
	 * @param int $post_id Post id.
	 * @return array Empty when there is nothing worth describing.
	 */
	public static function build_breadcrumbs( $post_id ) {
		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return array();
		}

		$items = array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => get_bloginfo( 'name' ),
				'item'     => home_url( '/' ),
			),
		);

		$categories = get_the_category( (int) $post->ID );

		if ( ! empty( $categories ) && isset( $categories[0]->term_id ) ) {
			$link = get_category_link( (int) $categories[0]->term_id );

			if ( $link ) {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => count( $items ) + 1,
					'name'     => $categories[0]->name,
					'item'     => $link,
				);
			}
		}

		$items[] = array(
			'@type'    => 'ListItem',
			'position' => count( $items ) + 1,
			'name'     => wp_strip_all_tags( get_the_title( $post ) ),
			'item'     => get_permalink( $post ),
		);

		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}
}
