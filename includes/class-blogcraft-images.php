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
	 * @param string $mime  Reported image type, so the extension can match it.
	 * @return string
	 */
	public static function filename_for( $title, $mime = '' ) {
		$slug = sanitize_title( (string) $title );

		if ( '' === $slug ) {
			$slug = 'blogcraft-image';
		}

		// The extension has to match the bytes. WordPress checks one against
		// the other and rejects a mismatch, so a PNG called .jpg does not
		// become an attachment at all — it just quietly fails.
		$types = array(
			'image/png'  => '.png',
			'image/webp' => '.webp',
			'image/gif'  => '.gif',
			'image/avif' => '.avif',
		);

		$mime = strtolower( trim( (string) $mime ) );

		return substr( $slug, 0, 60 ) . ( isset( $types[ $mime ] ) ? $types[ $mime ] : '.jpg' );
	}

	/**
	 * Providers a user can choose between.
	 *
	 * @return array
	 */
	public static function providers() {
		// The generators come from Blogcraft_Image_Models rather than being
		// listed again here. Two lists meant adding a service in one place and
		// having resolve() never route to it.
		return array( 'pollinations' => __( 'Pollinations — free, generated, no key needed', 'blogcraft' ) )
			+ Blogcraft_Image_Models::providers()
			+ array(
				'pexels'  => __( 'Pexels — free, real photos, free key', 'blogcraft' ),
				'pixabay' => __( 'Pixabay — free, real photos, free key', 'blogcraft' ),
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
	 * @param string $prompt Description of the wanted image, for the generators.
	 * @param string $query  A few keywords, for the libraries that search.
	 * @return string
	 */
	public static function resolve_url( $prompt, $query = '' ) {
		return self::resolve( $prompt, $query )['url'];
	}

	/**
	 * Find a picture, as an address or as the picture itself.
	 *
	 * Google answers with the image inline rather than a link to fetch, so one
	 * return type has to carry both. Everything downstream asks this for a temp
	 * file and never has to know which arrived.
	 *
	 * @param string $prompt Description of the wanted image, for the generators.
	 * @param string $query  A few keywords, for the libraries that search.
	 * @return array Keys: url, bytes, mime.
	 */
	public static function resolve( $prompt, $query = '' ) {
		// A generator takes a prompt and a library takes a query; they are not
		// the same string and treating them as one is a silent miss.
		$query     = ( '' === trim( (string) $query ) ) ? (string) $prompt : (string) $query;
		$preferred = (string) Blogcraft_Settings::get( 'image_provider' );
		$generated = array_keys( Blogcraft_Image_Models::providers() );

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
				$url = self::pexels_url( $query );
			} elseif ( 'pixabay' === $provider ) {
				$url = self::pixabay_url( $query );
			} elseif ( in_array( $provider, $generated, true ) ) {
				$made = Blogcraft_Image_Models::generate( $prompt, Blogcraft_Blueprint::get() );

				if ( '' !== $made['url'] || '' !== $made['bytes'] ) {
					return $made;
				}

				continue;
			} else {
				$url = self::source_url( $prompt );
			}

			if ( '' !== $url ) {
				return self::at( $url );
			}
		}

		return self::at( self::source_url( $prompt ) );
	}

	/**
	 * A picture that lives at an address.
	 *
	 * @param string $url Where it is.
	 * @return array
	 */
	private static function at( $url ) {
		return array(
			'url'   => (string) $url,
			'bytes' => '',
			'mime'  => '',
		);
	}

	/**
	 * Get a picture onto disk, however it arrived.
	 *
	 * @param array $made Output of resolve().
	 * @return string Temp file path, or '' when nothing could be written.
	 */
	private static function to_temp( $made ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( empty( $made['bytes'] ) ) {
			$tmp = download_url( (string) $made['url'], 45 );

			return is_wp_error( $tmp ) ? '' : $tmp;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			WP_Filesystem();
		}

		if ( ! $wp_filesystem ) {
			return '';
		}

		$tmp = wp_tempnam( 'blogcraft-image' );

		if ( ! $tmp || ! $wp_filesystem->put_contents( $tmp, $made['bytes'], FS_CHMOD_FILE ) ) {
			return '';
		}

		return $tmp;
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
	 * @param string $query   Keywords, for the libraries that search.
	 * @return int Attachment id, or 0 on any failure.
	 */
	private static function sideload( $post_id, $prompt, $alt, $query = '' ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$made = self::resolve( $prompt, $query );
		$tmp  = self::to_temp( $made );

		if ( '' === $tmp ) {
			return 0;
		}

		$attachment_id = media_handle_sideload(
			array(
				'name'     => self::filename_for( $alt, $made['mime'] ),
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

		// Publishing can be re-run after an interrupted attempt, and this
		// writes blocks into the post body rather than setting a field, so a
		// second pass would wedge a duplicate picture under every heading and
		// bill for each one. The marker below is what makes it safe to repeat.
		if ( get_post_meta( (int) $post_id, '_blogcraft_section_images', true ) ) {
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
			$brief = Blogcraft_Art_Direction::brief_for(
				get_the_title( (int) $post_id ),
				'',
				Blogcraft_Blueprint::get(),
				$heading
			);

			// Alt text describes the picture, not the section it sits under.
			// The heading was what got used, which meant a screen reader heard
			// the same words twice in a row — once as the heading, once as the
			// image — and learned nothing about the image either time. The
			// brief's subject is literally the answer to "what does this
			// picture show", so it is the sentence that belongs here.
			$alt = ( '' === trim( (string) $brief['subject'] ) ) ? $heading : $brief['subject'];

			$attachment_id = self::sideload( (int) $post_id, $brief['prompt'], $alt, $brief['search'] );

			if ( 0 === $attachment_id ) {
				continue;
			}

			$block = self::image_block( $attachment_id, $alt );

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

		// Set whatever happened, including when nothing could be placed: the
		// question this answers is "has this post been through here already",
		// and a run that found no home for a picture has still been through.
		update_post_meta( (int) $post_id, '_blogcraft_section_images', 1 );

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

		// A post that already has one does not need another. This matters
		// beyond tidiness: publishing can be re-run after an interrupted
		// attempt, and every generating picture service bills per image, so
		// without this a crash part-way through would be charged for twice.
		if ( has_post_thumbnail( (int) $post_id ) ) {
			return (int) get_post_thumbnail_id( (int) $post_id );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Handing a bare headline to an image model is why so much AI blog art
		// looks like clip art of the title. Art_Direction asks the writing model
		// what the picture should show, then adds the standing look.
		$brief = Blogcraft_Art_Direction::brief_for( $title, $topic, Blogcraft_Blueprint::get() );
		$made  = self::resolve( $brief['prompt'], $brief['search'] );
		$tmp   = self::to_temp( $made );

		if ( '' === $tmp ) {
			Blogcraft_Logger::error(
				'Featured image could not be fetched.',
				array(),
				null
			);

			return 0;
		}

		// Same reasoning as the in-body pictures: the title is already the
		// page's heading, so repeating it as the featured image's alt tells a
		// screen reader nothing it has not just been told.
		$alt = ( '' === trim( (string) $brief['subject'] ) ) ? $title : $brief['subject'];

		$file = array(
			'name'     => self::filename_for( $title, $made['mime'] ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file, (int) $post_id, wp_strip_all_tags( $alt ) );

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
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_strip_all_tags( $alt ) );
		set_post_thumbnail( (int) $post_id, (int) $attachment_id );

		return (int) $attachment_id;
	}
}
