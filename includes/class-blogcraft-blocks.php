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
			$out .= self::heading( __( 'Key takeaways', 'blogcraft' ), 2 );
			$out .= self::unordered_list( $article['key_takeaways'] );
		}

		// Early, because it lets a reader decide in five seconds whether the
		// rest of the article is for them. Saying who something is not for is
		// the part almost nobody writes and the part readers trust.
		$out .= self::two_lists(
			isset( $article['for_whom'] ) ? $article['for_whom'] : array(),
			isset( $article['not_for'] ) ? $article['not_for'] : array(),
			__( 'Who this is for', 'blogcraft' ),
			__( 'Who it is not for', 'blogcraft' )
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
			__( 'What works', 'blogcraft' ),
			__( 'What does not', 'blogcraft' )
		);

		$out .= self::figures( isset( $article['figures'] ) ? $article['figures'] : array() );

		if ( ! empty( $article['mistakes'] ) && is_array( $article['mistakes'] ) ) {
			$out .= self::heading( __( 'Mistakes worth avoiding', 'blogcraft' ), 2 );
			$out .= self::unordered_list( $article['mistakes'] );
		}

		if ( ! empty( $article['faq'] ) && is_array( $article['faq'] ) ) {
			$out .= self::heading( __( 'Frequently asked questions', 'blogcraft' ), 2 );

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
				__( 'Figure', 'blogcraft' ),
				__( 'What it means', 'blogcraft' ),
				__( 'Where it came from', 'blogcraft' ),
			),
			$rows
		);

		return ( '' === $table ) ? '' : self::heading( __( 'The numbers', 'blogcraft' ), 2 ) . $table;
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

		return self::heading( __( 'Sources', 'blogcraft' ), 2 ) . self::unordered_list( $items );
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
	private static function clean( $text ) {
		if ( ! is_scalar( $text ) ) {
			return '';
		}

		return trim( wp_kses_post( (string) $text ) );
	}
}
