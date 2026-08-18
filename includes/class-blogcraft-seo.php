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

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
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

		$author = get_the_author_meta( 'display_name', (int) $post->post_author );

		if ( '' !== (string) $author ) {
			$graph['author'] = array(
				'@type' => 'Person',
				'name'  => $author,
			);
		}

		$thumbnail = get_the_post_thumbnail_url( $post, 'full' );

		if ( $thumbnail ) {
			$graph['image'] = $thumbnail;
		}

		return $graph;
	}
}
