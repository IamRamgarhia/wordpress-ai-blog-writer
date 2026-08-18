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
		$tmp    = download_url( self::source_url( $prompt ), 45 );

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
