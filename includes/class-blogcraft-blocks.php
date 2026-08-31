<?php
/**
 * Gutenberg block markup rendering.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a structured article array into native block markup.
 *
 * Most AI blogging plugins write raw HTML into post_content, which the block
 * editor then shows as a single unopenable "Classic" blob the user cannot edit
 * paragraph by paragraph. Emitting real block comments keeps every heading and
 * paragraph individually editable, which is the difference between a draft a
 * user can work with and one they have to fight.
 *
 * Every string is passed through wp_kses_post() before it reaches the markup,
 * so model output can never inject a script tag or an event handler.
 */
class Blogcraft_Blocks {

	/**
	 * Render a full article to block markup.
	 *
	 * @param array $article Keys: sections, key_takeaways, faq.
	 * @return string Block markup suitable for post_content.
	 */
	public static function render( $article ) {
		$out = '';

		if ( ! empty( $article['intro'] ) ) {
			$out .= self::paragraph( $article['intro'] );
		}

		if ( ! empty( $article['key_takeaways'] ) && is_array( $article['key_takeaways'] ) ) {
			$out .= self::heading( __( 'Key takeaways', 'dicecodes-ai-blog-writer' ), 2 );
			$out .= self::unordered_list( $article['key_takeaways'] );
		}

		// Early, because it lets a reader decide in five seconds whether the
		// rest of the article is for them. Saying who something is not for is
		// the part almost nobody writes and the part readers trust.
		$out .= self::two_lists(
			isset( $article['for_whom'] ) ? $article['for_whom'] : array(),
			isset( $article['not_for'] ) ? $article['not_for'] : array(),
			__( 'Who this is for', 'dicecodes-ai-blog-writer' ),
			__( 'Who it is not for', 'dicecodes-ai-blog-writer' )
		);

		if ( ! empty( $article['sections'] ) && is_array( $article['sections'] ) ) {
			foreach ( $article['sections'] as $section ) {
				if ( ! is_array( $section ) ) {
					continue;
				}

				if ( ! empty( $section['heading'] ) ) {
					$out .= self::heading( $section['heading'], 2 );
				}

				if ( ! empty( $section['paragraphs'] ) && is_array( $section['paragraphs'] ) ) {
					foreach ( $section['paragraphs'] as $paragraph ) {
						$out .= self::paragraph( $paragraph );
					}
				}

				if ( ! empty( $section['list'] ) && is_array( $section['list'] ) ) {
					$out .= self::unordered_list( $section['list'] );
				}
			}
		}

		$out .= self::two_lists(
			isset( $article['pros'] ) ? $article['pros'] : array(),
			isset( $article['cons'] ) ? $article['cons'] : array(),
			__( 'What works', 'dicecodes-ai-blog-writer' ),
			__( 'What does not', 'dicecodes-ai-blog-writer' )
		);

		$out .= self::figures( isset( $article['figures'] ) ? $article['figures'] : array() );

		if ( ! empty( $article['mistakes'] ) && is_array( $article['mistakes'] ) ) {
			$out .= self::heading( __( 'Mistakes worth avoiding', 'dicecodes-ai-blog-writer' ), 2 );
			$out .= self::unordered_list( $article['mistakes'] );
		}

		if ( ! empty( $article['faq'] ) && is_array( $article['faq'] ) ) {
			$out .= self::heading( __( 'Frequently asked questions', 'dicecodes-ai-blog-writer' ), 2 );

			foreach ( $article['faq'] as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['question'] ) ) {
					continue;
				}

				$out .= self::heading( $entry['question'], 3 );

				if ( ! empty( $entry['answer'] ) ) {
					$out .= self::paragraph( $entry['answer'] );
				}
			}
		}

		$out .= self::sources( isset( $article['sources'] ) ? $article['sources'] : array() );

		return trim( $out );
	}

	/**
	 * Two headed lists, rendered only when there is something in them.
	 *
	 * @param array  $first  Items for the first list.
	 * @param array  $second Items for the second list.
	 * @param string $head   Heading for the first.
	 * @param string $tail   Heading for the second.
	 * @return string
	 */
	private static function two_lists( $first, $second, $head, $tail ) {
		$out = '';

		if ( ! empty( $first ) && is_array( $first ) ) {
			$list = self::unordered_list( $first );

			if ( '' !== $list ) {
				$out .= self::heading( $head, 2 ) . $list;
			}
		}

		if ( ! empty( $second ) && is_array( $second ) ) {
			$list = self::unordered_list( $second );

			if ( '' !== $list ) {
				$out .= self::heading( $tail, 2 ) . $list;
			}
		}

		return $out;
	}

	/**
	 * The figures an article states, gathered into one table with their sources.
	 *
	 * A number a reader can check is worth more than one they cannot, and
	 * putting them together makes the ones without a source obvious.
	 *
	 * @param array $figures Each with figure, meaning and source.
	 * @return string
	 */
	private static function figures( $figures ) {
		if ( empty( $figures ) || ! is_array( $figures ) ) {
			return '';
		}

		$rows = array();

		foreach ( $figures as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['figure'] ) ) {
				continue;
			}

			$rows[] = array(
				isset( $entry['figure'] ) ? $entry['figure'] : '',
				isset( $entry['meaning'] ) ? $entry['meaning'] : '',
				isset( $entry['source'] ) ? $entry['source'] : '',
			);
		}

		$table = self::table(
			array(
				__( 'Figure', 'dicecodes-ai-blog-writer' ),
				__( 'What it means', 'dicecodes-ai-blog-writer' ),
				__( 'Where it came from', 'dicecodes-ai-blog-writer' ),
			),
			$rows
		);

		return ( '' === $table ) ? '' : self::heading( __( 'The numbers', 'dicecodes-ai-blog-writer' ), 2 ) . $table;
	}

	/**
	 * The sources an article was written from.
	 *
	 * Links are built from the url the research stage recorded, never from
	 * anything the model produced: a model asked for a citation will invent a
	 * plausible address that goes nowhere.
	 *
	 * @param array $sources Each with title and url.
	 * @return string
	 */
	private static function sources( $sources ) {
		if ( empty( $sources ) || ! is_array( $sources ) ) {
			return '';
		}

		$items = array();

		foreach ( $sources as $source ) {
			if ( ! is_array( $source ) || empty( $source['url'] ) ) {
				continue;
			}

			$url = esc_url( (string) $source['url'] );

			if ( '' === $url ) {
				continue;
			}

			$label = trim( wp_strip_all_tags( isset( $source['title'] ) ? (string) $source['title'] : '' ) );

			if ( '' === $label ) {
				$label = $url;
			}

			$items[] = '<a href="' . $url . '" rel="nofollow noopener" target="_blank">' . esc_html( $label ) . '</a>';
		}

		if ( empty( $items ) ) {
			return '';
		}

		return self::heading( __( 'Sources', 'dicecodes-ai-blog-writer' ), 2 ) . self::unordered_list( $items );
	}

	/**
	 * A paragraph block.
	 *
	 * @param string $text Paragraph text.
	 * @return string
	 */
	public static function paragraph( $text ) {
		$text = self::clean( $text );

		if ( '' === $text ) {
			return '';
		}

		return "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->\n\n";
	}

	/**
	 * A heading block.
	 *
	 * @param string $text  Heading text.
	 * @param int    $level Heading level, 2 to 4.
	 * @return string
	 */
	public static function heading( $text, $level = 2 ) {
		$text = self::clean( $text );

		if ( '' === $text ) {
			return '';
		}

		$level = min( 4, max( 2, (int) $level ) );

		return sprintf(
			"<!-- wp:heading {\"level\":%1\$d} -->\n<h%1\$d>%2\$s</h%1\$d>\n<!-- /wp:heading -->\n\n",
			$level,
			$text
		);
	}

	/**
	 * An unordered list block.
	 *
	 * @param array $items List items.
	 * @return string
	 */
	public static function unordered_list( $items ) {
		$rendered = '';

		foreach ( (array) $items as $item ) {
			$item = self::clean( $item );

			if ( '' === $item ) {
				continue;
			}

			$rendered .= "<!-- wp:list-item -->\n<li>" . $item . "</li>\n<!-- /wp:list-item -->\n";
		}

		if ( '' === $rendered ) {
			return '';
		}

		return "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n" . $rendered . "</ul>\n<!-- /wp:list -->\n\n";
	}

	/**
	 * A numbered list block.
	 *
	 * @param array $items List items.
	 * @return string Empty when nothing usable was supplied.
	 */
	public static function ordered_list( $items ) {
		$rendered = '';

		foreach ( (array) $items as $item ) {
			$item = self::clean( $item );

			if ( '' === $item ) {
				continue;
			}

			$rendered .= "<!-- wp:list-item -->\n<li>" . $item . "</li>\n<!-- /wp:list-item -->\n";
		}

		if ( '' === $rendered ) {
			return '';
		}

		return "<!-- wp:list {\"ordered\":true} -->\n<ol class=\"wp-block-list\">\n" . $rendered . "</ol>\n<!-- /wp:list -->\n\n";
	}

	/**
	 * A table block.
	 *
	 * The blueprint has offered a "use tables" switch since it was written and
	 * nothing could render one, so the setting produced a line in a prompt and
	 * a model politely writing a table out as prose. This is the renderer that
	 * was missing.
	 *
	 * @param array $head Column headings.
	 * @param array $rows Rows, each an array of cells.
	 * @return string Empty when there is nothing to lay out.
	 */
	public static function table( $head, $rows ) {
		$head  = array_values( array_filter( array_map( array( __CLASS__, 'clean' ), (array) $head ), 'strlen' ) );
		$body  = '';
		$width = count( $head );

		foreach ( (array) $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$cells = array_values( array_map( array( __CLASS__, 'clean' ), $row ) );

			if ( empty( array_filter( $cells, 'strlen' ) ) ) {
				continue;
			}

			// A ragged row renders as a broken table, so pad or trim it to the
			// width the headings promised.
			if ( $width > 0 ) {
				$cells = array_slice( array_pad( $cells, $width, '' ), 0, $width );
			}

			$line = '';

			foreach ( $cells as $cell ) {
				$line .= '<td>' . $cell . '</td>';
			}

			$body .= '<tr>' . $line . "</tr>\n";
		}

		if ( '' === $body ) {
			return '';
		}

		$header = '';

		if ( ! empty( $head ) ) {
			$cells = '';

			foreach ( $head as $cell ) {
				$cells .= '<th>' . $cell . '</th>';
			}

			$header = '<thead><tr>' . $cells . "</tr></thead>\n";
		}

		return "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table>\n"
			. $header . '<tbody>' . "\n" . $body . "</tbody>\n</table></figure>\n<!-- /wp:table -->\n\n";
	}

	/**
	 * Sanitise a model-supplied string for output.
	 *
	 * @param mixed $text Raw value from the model.
	 * @return string
	 */
	/**
	 * Rebuild valid block markup from edited HTML.
	 *
	 * The draft is edited in the ordinary WordPress editor, which returns
	 * plain HTML with no block delimiters. Saving that straight into a post on
	 * a block-editor site produces one enormous "Classic" block — Gutenberg
	 * shows it as unexpected content, none of it is individually editable, and
	 * the structure the scorer measured is gone as far as the editor is
	 * concerned.
	 *
	 * So the HTML is walked and each top-level element re-wrapped in the block
	 * comment that belongs to it, in exactly the shapes render() produces. The
	 * inner markup is preserved untouched, so an image the writer inserted
	 * with Add Media survives as a real image block.
	 *
	 * Anything unrecognised becomes a paragraph rather than being dropped:
	 * losing a writer's words to a tag this method has not met is a far worse
	 * failure than an over-plain block.
	 *
	 * @param string $html Edited HTML.
	 * @return string Block markup.
	 */
	public static function from_html( $html ) {
		$html = trim( (string) $html );

		if ( '' === $html ) {
			return '';
		}

		// Already block markup: leave it exactly as it is.
		if ( false !== strpos( $html, '<!-- wp:' ) ) {
			return $html;
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return self::paragraph( wp_strip_all_tags( $html ) );
		}

		$dom = new DOMDocument();

		// Loaded as UTF-8 without letting DOMDocument add its own html/body
		// scaffolding, and with its warnings about HTML5 tags suppressed —
		// they are noise, not failures.
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML(
			'<?xml encoding="UTF-8"><div id="bc-root">' . $html . '</div>',
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $dom->getElementById( 'bc-root' );

		if ( null === $root ) {
			return self::paragraph( wp_strip_all_tags( $html ) );
		}

		$out   = '';
		$loose = '';

		foreach ( $root->childNodes as $node ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own API.
			if ( XML_TEXT_NODE === $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own API.
				$loose .= $node->textContent; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own API.

				continue;
			}

			if ( XML_ELEMENT_NODE !== $node->nodeType ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own API.
				continue;
			}

			// Inline tags at the top level are part of a sentence somebody is
			// midway through writing, not a block of their own.
			if ( in_array( strtolower( $node->nodeName ), array( 'strong', 'em', 'b', 'i', 'a', 'code', 'span', 'br' ), true ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own API.
				$loose .= $dom->saveHTML( $node );

				continue;
			}

			if ( '' !== trim( wp_strip_all_tags( $loose ) ) ) {
				$out .= self::paragraph( $loose );
			}

			$loose = '';
			$out  .= self::wrap_node( $dom, $node );
		}

		if ( '' !== trim( wp_strip_all_tags( $loose ) ) ) {
			$out .= self::paragraph( $loose );
		}

		return trim( $out ) . "\n";
	}

	/**
	 * Wrap one element in the block comment that matches it.
	 *
	 * @param DOMDocument $dom  Owning document.
	 * @param DOMNode     $node Element to wrap.
	 * @return string
	 */
	private static function wrap_node( $dom, $node ) {
		$tag  = strtolower( $node->nodeName ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own API.
		$html = trim( (string) $dom->saveHTML( $node ) );

		if ( '' === trim( wp_strip_all_tags( $html ) ) && ! in_array( $tag, array( 'img', 'figure', 'hr' ), true ) ) {
			return '';
		}

		switch ( $tag ) {
			case 'p':
				return "<!-- wp:paragraph -->\n" . $html . "\n<!-- /wp:paragraph -->\n\n";

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr( $tag, 1 );

				// h1 belongs to the post title, so a heading that deep in the
				// body is demoted rather than left to compete with it.
				$level = ( $level < 2 ) ? 2 : $level;

				return sprintf( "<!-- wp:heading {\"level\":%d} -->\n%s\n<!-- /wp:heading -->\n\n", $level, $html );

			case 'ul':
			case 'ol':
				return self::wrap_list( $dom, $node, 'ol' === $tag );

			case 'blockquote':
				return "<!-- wp:quote -->\n" . $html . "\n<!-- /wp:quote -->\n\n";

			case 'table':
				return "<!-- wp:table -->\n<figure class=\"wp-block-table\">" . $html . "</figure>\n<!-- /wp:table -->\n\n";

			case 'figure':
				// An image the writer inserted with Add Media arrives already
				// wrapped in its figure; it only wants the delimiters.
				$inner = strtolower( $html );

				if ( false !== strpos( $inner, '<img' ) ) {
					return "<!-- wp:image -->\n" . $html . "\n<!-- /wp:image -->\n\n";
				}

				return "<!-- wp:group -->\n" . $html . "\n<!-- /wp:group -->\n\n";

			case 'img':
				return "<!-- wp:image -->\n<figure class=\"wp-block-image\">" . $html . "</figure>\n<!-- /wp:image -->\n\n";

			case 'hr':
				return "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->\n\n";

			case 'pre':
				return "<!-- wp:code -->\n" . $html . "\n<!-- /wp:code -->\n\n";

			default:
				return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->\n\n";
		}
	}

	/**
	 * Rebuild a list, including the list-item blocks inside it.
	 *
	 * A wp:list whose items are bare <li> is exactly what makes the block
	 * editor report unexpected content, so each item gets its own block.
	 *
	 * @param DOMDocument $dom     Owning document.
	 * @param DOMNode     $node    The ul or ol.
	 * @param bool        $ordered Whether it is ordered.
	 * @return string
	 */
	private static function wrap_list( $dom, $node, $ordered ) {
		$items = '';

		foreach ( $node->childNodes as $child ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own API.
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own API.
				continue;
			}

			$inner = '';

			foreach ( $child->childNodes as $part ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMDocument's own API.
				$inner .= $dom->saveHTML( $part );
			}

			$inner = trim( $inner );

			if ( '' === trim( wp_strip_all_tags( $inner ) ) ) {
				continue;
			}

			$items .= "<!-- wp:list-item -->\n<li>" . $inner . "</li>\n<!-- /wp:list-item -->\n";
		}

		if ( '' === $items ) {
			return '';
		}

		$tag  = $ordered ? 'ol' : 'ul';
		$open = $ordered ? '<!-- wp:list {"ordered":true} -->' : '<!-- wp:list -->';

		return $open . "\n<{$tag} class=\"wp-block-list\">\n" . $items . "</{$tag}>\n<!-- /wp:list -->\n\n";
	}

	/**
	 * Narrow model output to markup a post may safely contain.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function clean( $text ) {
		if ( ! is_scalar( $text ) ) {
			return '';
		}

		return trim( wp_kses_post( self::demarkdown( (string) $text ) ) );
	}

	/**
	 * Turn the markdown models emit anyway into real markup.
	 *
	 * Every drafting prompt says "plain text only, no markdown", and models
	 * still reach for **bold** when a sentence wants emphasis — which then
	 * reaches the page as literal asterisks, because nothing converted them
	 * and nothing stripped them. A reader sees `**human insight shapes it**`
	 * in the middle of a paragraph and correctly concludes the post was
	 * generated and never looked at.
	 *
	 * Converting beats stripping: the model marked that phrase for a reason,
	 * and <strong> is what it was reaching for. Deliberately narrow — bold,
	 * italic and inline code only. Headings and lists have their own blocks
	 * and a stray "## " in prose is a mistake to leave visible rather than
	 * quietly promote into structure.
	 *
	 * @param string $text Raw model output.
	 * @return string
	 */
	private static function demarkdown( $text ) {
		// Bold first: ** before * so the single-asterisk rule cannot eat half
		// of a bold marker and leave the other half stranded.
		$text = (string) preg_replace( '/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $text );
		$text = (string) preg_replace( '/__(?=\S)(.+?)(?<=\S)__/s', '<strong>$1</strong>', $text );

		// Single asterisks, but not the ones doing arithmetic or standing in
		// for a footnote marker: both sides have to hug a non-space.
		$text = (string) preg_replace( '/(?<![\w*])\*(?=\S)([^*\n]+?)(?<=\S)\*(?![\w*])/', '<em>$1</em>', $text );

		$text = (string) preg_replace( '/`(?=\S)([^`\n]+?)(?<=\S)`/', '<code>$1</code>', $text );

		return $text;
	}
}
