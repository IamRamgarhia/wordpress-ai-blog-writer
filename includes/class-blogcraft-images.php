<?php
/**
 * Featured image generation.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches a featured image and attaches it to a generated post.
 *
 * Pollinations is the default because it needs no API key, so images work the
 * moment the plugin is installed rather than after a second signup. Failure is
 * always non-fatal: a post without an image is worth far more than a pipeline
 * that aborts because an image host was briefly down.
 */
class Blogcraft_Images {

	/**
	 * Build the source URL for a prompt.
	 *
	 * @param string $prompt Image prompt.
	 * @return string
	 */
	public static function source_url( $prompt ) {
		$base = 'https://image.pollinations.ai/prompt/' . rawurlencode( wp_strip_all_tags( (string) $prompt ) );

		return add_query_arg(
			array(
				'width'   => 1200,
				'height'  => 630,
				'nologo'  => 'true',
				'enhance' => 'true',
			),
			$base
		);
	}

	/**
	 * Turn a post title into a descriptive, hyphenated filename.
	 *
	 * @param string $title Post title.
	 * @return string
	 */
	public static function filename_for( $title ) {
		$slug = sanitize_title( (string) $title );

		if ( '' === $slug ) {
			$slug = 'blogcraft-image';
		}

		return substr( $slug, 0, 60 ) . '.jpg';
	}

	/**
	 * Providers a user can choose between.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'pollinations' => __( 'Pollinations — generated, no key needed', 'blogcraft' ),
			'fal'          => __( 'fal.ai — generated, hundreds of models, pay per image', 'blogcraft' ),
			'openai'       => __( 'OpenAI — generated, uses the key you already entered', 'blogcraft' ),
			'pexels'       => __( 'Pexels — real photos, free key', 'blogcraft' ),
			'pixabay'      => __( 'Pixabay — real photos, free key', 'blogcraft' ),
		);
	}

	/**
	 * Find a Pexels photo for a query.
	 *
	 * @param string $query Search terms.
	 * @return string Image URL, or '' when unavailable.
	 */
	private static function pexels_url( $query ) {
		$key = (string) Blogcraft_Settings::get( 'pexels_api_key' );

		if ( '' === $key ) {
			return '';
		}

		$result = Blogcraft_Http::get_json(
			add_query_arg(
				array(
					'query'       => rawurlencode( $query ),
					'per_page'    => 1,
					'orientation' => 'landscape',
				),
				'https://api.pexels.com/v1/search'
			),
			array( 'Authorization' => $key ),
			20
		);

		if ( '' !== $result['error'] || empty( $result['body']['photos'][0]['src']['large'] ) ) {
			return '';
		}

		return esc_url_raw( (string) $result['body']['photos'][0]['src']['large'] );
	}

	/**
	 * Find a Pixabay photo for a query.
	 *
	 * @param string $query Search terms.
	 * @return string Image URL, or '' when unavailable.
	 */
	private static function pixabay_url( $query ) {
		$key = (string) Blogcraft_Settings::get( 'pixabay_api_key' );

		if ( '' === $key ) {
			return '';
		}

		$result = Blogcraft_Http::get_json(
			add_query_arg(
				array(
					'key'         => $key,
					'q'           => rawurlencode( $query ),
					'per_page'    => 3,
					'orientation' => 'horizontal',
					'image_type'  => 'photo',
				),
				'https://pixabay.com/api/'
			),
			array(),
			20
		);

		if ( '' !== $result['error'] || empty( $result['body']['hits'][0]['largeImageURL'] ) ) {
			return '';
		}

		return esc_url_raw( (string) $result['body']['hits'][0]['largeImageURL'] );
	}

	/**
	 * Resolve an image URL, trying the chosen provider then falling back.
	 *
	 * Pollinations is always the last resort because it needs no key, so the
	 * chain can never run out of options and leave a post with no image.
	 *
	 * @param string $prompt Description of the wanted image.
	 * @return string
	 */
	public static function resolve_url( $prompt ) {
		$preferred = (string) Blogcraft_Settings::get( 'image_provider' );

		// A paid generator is only ever used when it is the one chosen. Falling
		// back *to* one would spend money the user did not ask to spend.
		$order = array( $preferred, 'pexels', 'pixabay', 'pollinations' );
		$seen  = array();

		foreach ( $order as $provider ) {
			if ( '' === $provider || isset( $seen[ $provider ] ) ) {
				continue;
			}

			$seen[ $provider ] = true;

			if ( 'pexels' === $provider ) {
				$url = self::pexels_url( $prompt );
			} elseif ( 'pixabay' === $provider ) {
				$url = self::pixabay_url( $prompt );
			} elseif ( 'fal' === $provider || 'openai' === $provider ) {
				$url = Blogcraft_Image_Models::generate( $prompt, Blogcraft_Blueprint::get() );
			} else {
				$url = self::source_url( $prompt );
			}

			if ( '' !== $url ) {
				return $url;
			}
		}

		return self::source_url( $prompt );
	}

	/**
	 * Build an image block for an attachment.
	 *
	 * @param int    $attachment_id Attachment to render.
	 * @param string $alt           Alt text.
	 * @return string Block markup, empty when the attachment has no URL.
	 */
	public static function image_block( $attachment_id, $alt ) {
		$url = wp_get_attachment_image_url( (int) $attachment_id, 'large' );

		if ( ! $url ) {
			return '';
		}

		return sprintf(
			"<!-- wp:image {\"id\":%1$d,\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"%2$s\" alt=\"%3$s\" class=\"wp-image-%1$d\"/></figure>\n<!-- /wp:image -->\n\n",
			(int) $attachment_id,
			esc_url( $url ),
			esc_attr( $alt )
		);
	}

	/**
	 * Sideload one image and return its attachment id.
	 *
	 * @param int    $post_id Post to attach to.
	 * @param string $prompt  What the image should show.
	 * @param string $alt     Alt text.
	 * @return int Attachment id, or 0 on any failure.
	 */
	private static function sideload( $post_id, $prompt, $alt ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( self::resolve_url( $prompt ), 45 );

		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => self::filename_for( $alt ),
				'tmp_name' => $tmp,
			),
			(int) $post_id,
			wp_strip_all_tags( $alt )
		);

		if ( is_wp_error( $attachment_id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			return 0;
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags( $alt ) );

		return (int) $attachment_id;
	}

	/**
	 * Where the heading block carrying this text ends.
	 *
	 * Previously this matched a literal string assembled from the exact markup
	 * the block renderer happened to emit. Any change to that markup — a class,
	 * an id, an attribute — stopped every section image appearing, with no
	 * error and nothing in the log: the post simply came out plainer. Matching
	 * the block wrapper and comparing the text inside survives all of that.
	 *
	 * @param string $content Rendered post content.
	 * @param string $heading Heading text to find.
	 * @return int Offset just past the heading block, or -1 when not found.
	 */
	public static function heading_ends_at( $content, $heading ) {
		$wanted = trim( wp_strip_all_tags( html_entity_decode( (string) $heading, ENT_QUOTES, 'UTF-8' ) ) );

		if ( '' === $wanted ) {
			return -1;
		}

		$found = preg_match_all(
			'/<!--\s*wp:heading.*?<!--\s*\/wp:heading\s*-->/s',
			(string) $content,
			$matches,
			PREG_OFFSET_CAPTURE
		);

		if ( ! $found ) {
			return -1;
		}

		foreach ( $matches[0] as $match ) {
			$text = trim( wp_strip_all_tags( html_entity_decode( (string) $match[0], ENT_QUOTES, 'UTF-8' ) ) );

			if ( $text === $wanted ) {
				return (int) $match[1] + strlen( (string) $match[0] );
			}
		}

		return -1;
	}

	/**
	 * Add one image beneath each section heading.
	 *
	 * Runs after the post exists so each attachment has a parent, and updates
	 * the content once rather than per image. A failure at any point leaves the
	 * post exactly as it was: an illustrated post is nice, a broken one is not.
	 *
	 * @param int   $post_id Post to illustrate.
	 * @param array $article Article structure.
	 * @param int   $limit   Most images to add.
	 * @return int Number added.
	 */
	public static function add_section_images( $post_id, $article, $limit = 3 ) {
		if ( ! Blogcraft_Settings::get( 'images_per_section' ) ) {
			return 0;
		}

		if ( empty( $article['sections'] ) || ! is_array( $article['sections'] ) ) {
			return 0;
		}

		$post = get_post( (int) $post_id );

		if ( ! $post ) {
			return 0;
		}

		$content = (string) $post->post_content;
		$added   = 0;

		foreach ( $article['sections'] as $section ) {
			if ( $added >= (int) $limit ) {
				break;
			}

			if ( ! is_array( $section ) || empty( $section['heading'] ) ) {
				continue;
			}

			$heading = (string) $section['heading'];
			$at      = self::heading_ends_at( $content, $heading );

			if ( $at < 0 ) {
				Blogcraft_Logger::info(
					'A section heading could not be found in the rendered post, so it was left without a picture.',
					array( 'heading' => $heading ),
					null
				);

				continue;
			}

			// Described the same way the featured image is, but told which
			// section it illustrates so the pictures do not all repeat the
			// article's headline back at slightly different angles.
			$described = Blogcraft_Art_Direction::prompt_for(
				get_the_title( (int) $post_id ),
				'',
				Blogcraft_Blueprint::get(),
				$heading
			);

			$attachment_id = self::sideload( (int) $post_id, $described, $heading );

			if ( 0 === $attachment_id ) {
				continue;
			}

			$block = self::image_block( $attachment_id, $heading );

			if ( '' === $block ) {
				continue;
			}

			// Located again rather than reused: an earlier insertion in this
			// same loop has already moved every offset after it.
			$at = self::heading_ends_at( $content, $heading );

			if ( $at < 0 ) {
				continue;
			}

			$content = substr( $content, 0, $at ) . "\n\n" . $block . substr( $content, $at );
			++$added;
		}

		if ( $added > 0 ) {
			wp_update_post(
				array(
					'ID'           => (int) $post_id,
					'post_content' => $content,
				)
			);
		}

		return $added;
	}

	/**
	 * Attach a generated featured image to a post.
	 *
	 * @param int    $post_id Post to attach to.
	 * @param string $title   Post title, used for the prompt and alt text.
	 * @param string $topic   Original topic, for extra prompt context.
	 * @return int Attachment id, or 0 when unavailable.
	 */
	public static function attach_featured( $post_id, $title, $topic = '' ) {
		if ( ! Blogcraft_Settings::get( 'images_enabled' ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Handing a bare headline to an image model is why so much AI blog art
		// looks like clip art of the title. Art_Direction asks the writing model
		// what the picture should show, then adds the standing look.
		$prompt = Blogcraft_Art_Direction::prompt_for( $title, $topic, Blogcraft_Blueprint::get() );
		$tmp    = download_url( self::resolve_url( $prompt ), 45 );

		if ( is_wp_error( $tmp ) ) {
			Blogcraft_Logger::error(
				'Featured image could not be fetched.',
				array( 'reason' => $tmp->get_error_message() ),
				null
			);

			return 0;
		}

		$file = array(
			'name'     => self::filename_for( $title ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, (int) $post_id, wp_strip_all_tags( $title ) );

		if ( is_wp_error( $attachment_id ) ) {
			// media_handle_sideload cleans up on success only.
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}

			Blogcraft_Logger::error(
				'Featured image could not be attached.',
				array( 'reason' => $attachment_id->get_error_message() ),
				null
			);

			return 0;
		}

		// Alt text is a real accessibility and image-SEO signal, and costs nothing here.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags( $title ) );
		set_post_thumbnail( (int) $post_id, (int) $attachment_id );

		return (int) $attachment_id;
	}
}
