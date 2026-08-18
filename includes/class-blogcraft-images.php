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

		$prompt = trim( $title . '. ' . $topic );
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
