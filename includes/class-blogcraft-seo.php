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

		if ( $exclude > 0 ) {
			$args['post__not_in'] = array( (int) $exclude );
		}

		$out = self::run_query( $args );

		// WordPress search requires every term to match, so a multi-word topic often
		// finds nothing even on a site with plenty of related posts. Falling back to
		// recent posts keeps "Read next" useful instead of silently empty.
		if ( empty( $out ) && isset( $args['s'] ) ) {
			unset( $args['s'] );
			$out = self::run_query( $args );
		}

		return $out;
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
	 * @param int   $minimum Fewest sections worth a contents list.
	 * @return string Block markup, empty when not worth rendering.
	 */
	public static function render_toc( $article, $minimum = 4 ) {
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

		$graph['wordCount'] = count( Blogcraft_Metrics::words( Blogcraft_Metrics::plain_text( $post->post_content ) ) );

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
