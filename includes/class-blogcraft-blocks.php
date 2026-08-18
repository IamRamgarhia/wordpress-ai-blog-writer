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

		return trim( $out );
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
