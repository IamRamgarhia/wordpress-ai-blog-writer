<?php
/**
 * Matching the shape of an article that already exists.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads a published article and works out the rules it was written to.
 *
 * "Write like that one" is the request everybody actually has, and no plugin in
 * this category answers it. Presets named after well-known blogs would be a
 * pretence: they would claim to reproduce somebody else's work, and they would
 * be wrong the week that blog changed. Measuring the real page is honest and
 * stays true.
 *
 * What is copied is *form*, never words. Length, how many sections, how long a
 * sentence and a paragraph run, whether it uses tables and lists, how deeply it
 * nests headings, how many figures it states, how heavily it links out, whether
 * it says "I" or "you". All of that is public structure and none of it is
 * anybody's intellectual property. The article's text is read to count things
 * and then thrown away — it is never stored, never sent to a model, and never
 * used as source material for writing.
 */
class Blogcraft_Emulate {

	/**
	 * Most bytes to read from a page.
	 */
	const MAX_BYTES = 600000;

	/**
	 * Fetch a page and reduce it to the article.
	 *
	 * Navigation, scripts, styles, headers, footers, forms and comments are all
	 * removed first, because counting them as prose makes a 900-word article
	 * measure 3,000 and every rule derived from it wrong.
	 *
	 * @param string $url Address to read.
	 * @return array Keys: ok, error, title, html.
	 */
	public static function fetch( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'That does not look like a web address.', 'blogcraft' ),
				'title' => '',
				'html'  => '',
			);
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'Mozilla/5.0 (compatible; Blogcraft/' . BLOGCRAFT_VERSION . '; +' . home_url( '/' ) . ')',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'That page could not be read.', 'blogcraft' ),
				'title' => '',
				'html'  => '',
			);
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'That page did not answer with an article.', 'blogcraft' ),
				'title' => '',
				'html'  => '',
			);
		}

		$body = substr( (string) wp_remote_retrieve_body( $response ), 0, self::MAX_BYTES );

		return array(
			'ok'    => true,
			'error' => '',
			'title' => self::title_of( $body ),
			'html'  => self::article_of( $body ),
		);
	}

	/**
	 * The page title.
	 *
	 * @param string $html Raw page.
	 * @return string
	 */
	public static function title_of( $html ) {
		if ( preg_match( '#<h1[^>]*>(.*?)</h1>#is', (string) $html, $hit ) ) {
			return trim( wp_strip_all_tags( $hit[1] ) );
		}

		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', (string) $html, $hit ) ) {
			return trim( wp_strip_all_tags( $hit[1] ) );
		}

		return '';
	}

	/**
	 * Strip a page down to what a reader would call the article.
	 *
	 * @param string $html Raw page.
	 * @return string
	 */
	public static function article_of( $html ) {
		$html = (string) $html;

		// Everything that is on the page but is not the piece of writing.
		$html = preg_replace( '#<(script|style|noscript|svg|iframe|form|template)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<(nav|header|footer|aside)\b[^>]*>.*?</\1>#is', ' ', $html );
		$html = preg_replace( '#<!--.*?-->#s', ' ', (string) $html );

		// Prefer a real article container when the page offers one.
		if ( preg_match( '#<article\b[^>]*>(.*?)</article>#is', (string) $html, $hit ) ) {
			return $hit[1];
		}

		if ( preg_match( '#<main\b[^>]*>(.*?)</main>#is', (string) $html, $hit ) ) {
			return $hit[1];
		}

		if ( preg_match( '#<body\b[^>]*>(.*?)</body>#is', (string) $html, $hit ) ) {
			return $hit[1];
		}

		return (string) $html;
	}

	/**
	 * Count how many of a tag appear.
	 *
	 * @param string $html Article markup.
	 * @param string $tag  Tag name.
	 * @return int
	 */
	private static function count_tag( $html, $tag ) {
		return (int) preg_match_all( '#<' . preg_quote( $tag, '#' ) . '\b#i', (string) $html );
	}

	/**
	 * Everything measurable about an article.
	 *
	 * @param string $html Article markup.
	 * @param string $url  Where it came from, so its own links can be told apart.
	 * @return array
	 */
	public static function measure( $html, $url = '' ) {
		$html = (string) $html;
		$text = Blogcraft_Metrics::plain_text( $html );

		$words     = Blogcraft_Metrics::words( $text );
		$sentences = Blogcraft_Metrics::sentences( $text );
		$blocks    = preg_split( '/\n{2,}/u', $text, -1, PREG_SPLIT_NO_EMPTY );

		$host  = wp_parse_url( (string) $url, PHP_URL_HOST );
		$links = self::links( $html, is_string( $host ) ? $host : '' );

		$first  = (int) preg_match_all( '/\b(?:i|we|our|my)\b/iu', $text );
		$second = (int) preg_match_all( '/\byou(?:r|rs)?\b/iu', $text );

		return array(
			'words'          => count( $words ),
			'sections'       => self::count_tag( $html, 'h2' ),
			'subsections'    => self::count_tag( $html, 'h3' ),
			'sentence_words' => empty( $sentences ) ? 0 : (int) round( count( $words ) / count( $sentences ) ),
			'para_sentences' => empty( $blocks ) ? 0 : max( 1, (int) round( count( $sentences ) / count( $blocks ) ) ),
			'reading_ease'   => (int) round( Blogcraft_Metrics::reading_ease( $text ) ),
			'lists'          => self::count_tag( $html, 'ul' ) + self::count_tag( $html, 'ol' ),
			'tables'         => self::count_tag( $html, 'table' ),
			'images'         => self::count_tag( $html, 'img' ),
			'quotes'         => self::count_tag( $html, 'blockquote' ),
			'external_links' => $links['external'],
			'internal_links' => $links['internal'],
			'data_points'    => count( Blogcraft_Editorial::data_points( $text ) ),
			'experience'     => Blogcraft_Editorial::experience_markers( $text ),
			'em_dash'        => (int) preg_match_all( '/\x{2014}|\s\x{2013}\s/u', $text ),
			'contractions'   => (int) preg_match_all( '/\b\w+[\x{2019}\']\w{1,2}\b/u', $text ),
			'person'         => self::person( $first, $second ),
			'faq'            => self::looks_like_faq( $html ),
		);
	}

	/**
	 * Which way the writing faces.
	 *
	 * @param int $first  First-person mentions.
	 * @param int $second Second-person mentions.
	 * @return string
	 */
	private static function person( $first, $second ) {
		if ( $first > ( $second * 1.5 ) ) {
			return 'first';
		}

		if ( $second > ( $first * 1.5 ) ) {
			return 'second';
		}

		return 'mixed';
	}

	/**
	 * Links out, and links back into the same site.
	 *
	 * @param string $html Article markup.
	 * @param string $host The article's own hostname.
	 * @return array Keys: internal, external.
	 */
	private static function links( $html, $host ) {
		$out = array(
			'internal' => 0,
			'external' => 0,
		);

		if ( ! preg_match_all( '/<a\s[^>]*href=("[^"]*"|\'[^\']*\')/i', (string) $html, $hits ) ) {
			return $out;
		}

		foreach ( $hits[1] as $raw ) {
			$href = trim( substr( $raw, 1, -1 ) );

			if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) ) {
				continue;
			}

			$link_host = wp_parse_url( $href, PHP_URL_HOST );

			if ( ! is_string( $link_host ) || '' === $host || $link_host === $host ) {
				++$out['internal'];
				continue;
			}

			++$out['external'];
		}

		return $out;
	}

	/**
	 * Whether the article ends with a run of questions.
	 *
	 * @param string $html Article markup.
	 * @return bool
	 */
	private static function looks_like_faq( $html ) {
		if ( ! preg_match_all( '#<h[23][^>]*>(.*?)</h[23]>#is', (string) $html, $hits ) ) {
			return false;
		}

		$questions = 0;

		foreach ( $hits[1] as $heading ) {
			if ( false !== strpos( wp_strip_all_tags( $heading ), '?' ) ) {
				++$questions;
			}
		}

		return $questions >= 2;
	}

	/**
	 * Turn measurements into blueprint rules.
	 *
	 * Only what was actually observed. A field the page says nothing about is
	 * left alone rather than guessed at, because the value of this is that
	 * everything it sets came off a real article.
	 *
	 * @param array $seen Output of measure().
	 * @return array Sparse blueprint values.
	 */
	public static function to_blueprint( $seen ) {
		$out = array();

		if ( empty( $seen['words'] ) ) {
			return $out;
		}

		$out['word_target'] = max( 200, (int) round( $seen['words'] / 50 ) * 50 );

		if ( $seen['sections'] > 0 ) {
			$out['sections_min'] = max( 1, $seen['sections'] - 1 );
			$out['sections_max'] = $seen['sections'] + 2;
		}

		if ( $seen['sentence_words'] > 0 ) {
			// A ceiling, not an average: the target is "nothing longer than
			// this", and setting it to the mean would fail half the article it
			// was copied from.
			$out['sentence_max_words'] = min( 60, max( 12, $seen['sentence_words'] + 10 ) );
		}

		if ( $seen['para_sentences'] > 0 ) {
			$out['para_max_sentences'] = min( 12, max( 1, $seen['para_sentences'] + 1 ) );
		}

		$out['reading_level'] = self::band_for( (int) $seen['reading_ease'] );
		$out['tables']        = ( $seen['tables'] > 0 );
		$out['lists']         = ( $seen['lists'] > 0 );
		$out['faq']           = ! empty( $seen['faq'] );
		$out['toc']           = ( $seen['sections'] >= 5 );

		$out['external_links_target'] = min( 12, (int) $seen['external_links'] );
		$out['internal_links_target'] = min( 12, (int) $seen['internal_links'] );
		$out['images_target']         = min( 8, (int) $seen['images'] );

		$out['require_statistics'] = ( $seen['data_points'] >= 3 );
		$out['require_experience'] = ( $seen['experience'] >= 2 );
		$out['require_citations']  = ( $seen['external_links'] >= 2 );

		$out['allow_em_dash']      = ( $seen['em_dash'] > 0 );
		$out['allow_contractions'] = ( $seen['contractions'] > 2 );

		if ( 'first' === $seen['person'] ) {
			$out['point_of_view'] = 'first_plural';
		} elseif ( 'second' === $seen['person'] ) {
			$out['point_of_view'] = 'second';
		}

		return $out;
	}

	/**
	 * The reading band a score falls in.
	 *
	 * @param int $ease Flesch reading ease.
	 * @return string
	 */
	public static function band_for( $ease ) {
		$best     = 'general';
		$distance = PHP_INT_MAX;

		foreach ( Blogcraft_Blueprint::reading_levels() as $slug => $band ) {
			$middle = ( $band[1] + $band[2] ) / 2;
			$gap    = abs( $middle - (int) $ease );

			if ( $gap < $distance ) {
				$distance = $gap;
				$best     = $slug;
			}
		}

		return $best;
	}

	/**
	 * Read an article and say, in words, what it found.
	 *
	 * @param string $url Address to read.
	 * @return array Keys: ok, error, title, notes, fields.
	 */
	public static function study( $url ) {
		$page = self::fetch( $url );

		if ( ! $page['ok'] ) {
			return array(
				'ok'     => false,
				'error'  => $page['error'],
				'title'  => '',
				'notes'  => array(),
				'fields' => array(),
			);
		}

		$seen = self::measure( $page['html'], $url );

		if ( $seen['words'] < 200 ) {
			return array(
				'ok'     => false,
				'error'  => __( 'There was not enough writing on that page to measure. Link to the article itself rather than a category or a home page.', 'blogcraft' ),
				'title'  => $page['title'],
				'notes'  => array(),
				'fields' => array(),
			);
		}

		return array(
			'ok'     => true,
			'error'  => '',
			'title'  => $page['title'],
			'notes'  => self::notes( $seen ),
			'fields' => self::to_blueprint( $seen ),
		);
	}

	/**
	 * What was measured, said plainly.
	 *
	 * @param array $seen Output of measure().
	 * @return array
	 */
	public static function notes( $seen ) {
		$notes = array(
			sprintf(
				/* translators: 1: word count. 2: number of sections. 3: average sentence length. */
				__( '%1$s words across %2$d sections, averaging %3$d words a sentence.', 'blogcraft' ),
				number_format_i18n( (int) $seen['words'] ),
				(int) $seen['sections'],
				(int) $seen['sentence_words']
			),
			sprintf(
				/* translators: 1: outbound links. 2: figures stated. 3: images. */
				__( '%1$d links out, %2$d specific figures, %3$d images.', 'blogcraft' ),
				(int) $seen['external_links'],
				(int) $seen['data_points'],
				(int) $seen['images']
			),
		);

		if ( 'first' === $seen['person'] ) {
			$notes[] = __( 'Written in the first person.', 'blogcraft' );
		} elseif ( 'second' === $seen['person'] ) {
			$notes[] = __( 'Addresses the reader as "you".', 'blogcraft' );
		}

		if ( ! empty( $seen['faq'] ) ) {
			$notes[] = __( 'Ends with a run of questions.', 'blogcraft' );
		}

		$notes[] = __( 'Structure only. None of the wording was copied, kept, or shown to a model.', 'blogcraft' );

		return $notes;
	}
}
