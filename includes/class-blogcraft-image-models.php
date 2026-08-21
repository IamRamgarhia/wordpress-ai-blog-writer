<?php
/**
 * Generative image providers.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Makes a picture from a prompt, through whichever service is configured.
 *
 * Separate from Blogcraft_Images because that class finds existing photographs
 * and these ones create new pictures. The distinction matters to the user too:
 * a stock library gives you something real that thousands of other sites also
 * used, and a model gives you something nobody has seen and nothing verifies.
 *
 * Model names are typed rather than picked from a list. Providers retire them
 * on their own schedule, and this plugin has already shipped one dead model id
 * in a hint; a list baked in here would be wrong within months and wrong
 * silently. The settings screen links to each provider's live catalogue.
 */
class Blogcraft_Image_Models {

	/**
	 * Services that generate rather than search.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'fal'    => __( 'fal.ai — hundreds of models, pay per image', 'blogcraft' ),
			'openai' => __( 'OpenAI — uses the key you already entered', 'blogcraft' ),
		);
	}

	/**
	 * Where to find each provider's current models and keys.
	 *
	 * @param string $provider Provider id.
	 * @return array Keys: label, key_url, models_url.
	 */
	public static function help( $provider ) {
		$map = array(
			'fal'    => array(
				'label'      => 'fal.ai',
				'key_url'    => 'https://fal.ai/dashboard/keys',
				'models_url' => 'https://fal.ai/models?categories=text-to-image',
			),
			'openai' => array(
				'label'      => 'OpenAI',
				'key_url'    => 'https://platform.openai.com/api-keys',
				'models_url' => 'https://platform.openai.com/docs/guides/images',
			),
		);

		$provider = (string) $provider;

		return isset( $map[ $provider ] ) ? $map[ $provider ] : $map['fal'];
	}

	/**
	 * Whether a generative provider is ready to use.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$provider = (string) Blogcraft_Settings::get( 'image_provider' );

		if ( 'fal' === $provider ) {
			return '' !== trim( (string) Blogcraft_Settings::get( 'fal_api_key' ) )
				&& '' !== trim( (string) Blogcraft_Settings::get( 'fal_model' ) );
		}

		if ( 'openai' === $provider ) {
			// Asked the same way generate() asks it. These were two separate
			// pieces of logic and they disagreed: this said "configured"
			// whenever any provider key was stored, so someone writing with
			// Gemini and generating with OpenAI was told their setup was fine
			// while every picture silently fell back to a free service. It also
			// ignored the model id, which generate() requires.
			return '' !== self::openai_key()
				&& '' !== trim( (string) Blogcraft_Settings::get( 'openai_image_model' ) );
		}

		return false;
	}

	/**
	 * The OpenAI key to use for pictures, if there is one.
	 *
	 * Falls back to the writing key, but only when the writing provider really
	 * is OpenAI. A key issued by Groq or Anthropic will not make an image, and
	 * treating one as though it might is how a setup screen comes to lie.
	 *
	 * @return string Empty when no usable key is stored.
	 */
	public static function openai_key() {
		$key = trim( (string) Blogcraft_Settings::get( 'openai_image_key' ) );

		if ( '' !== $key ) {
			return $key;
		}

		if ( 'openai' === (string) Blogcraft_Settings::get( 'provider_type' ) ) {
			return trim( (string) Blogcraft_Settings::get( 'provider_api_key' ) );
		}

		return '';
	}

	/**
	 * Generate one image and return a URL to it.
	 *
	 * @param string $prompt    Full image prompt.
	 * @param array  $blueprint Blueprint, for shape.
	 * @return string URL, or '' when it could not be made.
	 */
	public static function generate( $prompt, $blueprint ) {
		$provider = (string) Blogcraft_Settings::get( 'image_provider' );

		// Both of these bill per picture, so the cap is checked before the call
		// rather than after it. Returning '' hands the post back to the free
		// providers, which is the right failure: an image, just not a paid one.
		if ( Blogcraft_Cost::over_image_cap() ) {
			return '';
		}

		if ( 'fal' === $provider ) {
			$url = self::fal( $prompt, $blueprint );
		} elseif ( 'openai' === $provider ) {
			$url = self::openai( $prompt, $blueprint );
		} else {
			return '';
		}

		if ( '' !== $url ) {
			Blogcraft_Cost::record_image();
		}

		return $url;
	}

	/**
	 * Generate through fal.ai.
	 *
	 * Its models all sit behind one address with the model id as the path, so
	 * a single adapter reaches every one of them and a new model is a settings
	 * change rather than a plugin release.
	 *
	 * @param string $prompt    Image prompt.
	 * @param array  $blueprint Blueprint.
	 * @return string
	 */
	private static function fal( $prompt, $blueprint ) {
		$key   = trim( (string) Blogcraft_Settings::get( 'fal_api_key' ) );
		$model = trim( (string) Blogcraft_Settings::get( 'fal_model' ) );

		if ( '' === $key || '' === $model ) {
			return '';
		}

		$shape = isset( $blueprint['image_shape'] ) ? (string) $blueprint['image_shape'] : '16:9';
		$size  = Blogcraft_Art_Direction::dimensions( $shape );

		$result = Blogcraft_Http::post_json(
			'https://fal.run/' . ltrim( $model, '/' ),
			array(
				'prompt'                => $prompt,
				'num_images'            => 1,
				'image_size'            => array(
					'width'  => $size[0],
					'height' => $size[1],
				),
				'enable_safety_checker' => true,
			),
			array( 'Authorization' => 'Key ' . $key ),
			120
		);

		if ( '' !== $result['error'] ) {
			Blogcraft_Logger::error(
				'The image could not be generated.',
				array( 'reason' => $result['error'] ),
				null
			);

			return '';
		}

		// Most fal models answer with images[], a few with image{}.
		if ( ! empty( $result['body']['images'][0]['url'] ) ) {
			return esc_url_raw( (string) $result['body']['images'][0]['url'] );
		}

		if ( ! empty( $result['body']['image']['url'] ) ) {
			return esc_url_raw( (string) $result['body']['image']['url'] );
		}

		return '';
	}

	/**
	 * OpenAI images.
	 *
	 * Falls back to the writing key, because someone who has already pasted an
	 * OpenAI key should not have to paste it twice to get pictures.
	 *
	 * @param string $prompt    Image prompt.
	 * @param array  $blueprint Blueprint.
	 * @return string
	 */
	private static function openai( $prompt, $blueprint ) {
		$key = self::openai_key();

		if ( '' === $key ) {
			return '';
		}

		$model = trim( (string) Blogcraft_Settings::get( 'openai_image_model' ) );

		if ( '' === $model ) {
			return '';
		}

		$shape = isset( $blueprint['image_shape'] ) ? (string) $blueprint['image_shape'] : '16:9';

		$result = Blogcraft_Http::post_json(
			'https://api.openai.com/v1/images/generations',
			array(
				'model'  => $model,
				'prompt' => $prompt,
				'n'      => 1,
				'size'   => self::openai_size( $shape ),
			),
			array( 'Authorization' => 'Bearer ' . $key ),
			120
		);

		if ( '' !== $result['error'] ) {
			Blogcraft_Logger::error(
				'The image could not be generated.',
				array( 'reason' => $result['error'] ),
				null
			);

			return '';
		}

		if ( ! empty( $result['body']['data'][0]['url'] ) ) {
			return esc_url_raw( (string) $result['body']['data'][0]['url'] );
		}

		return '';
	}

	/**
	 * The nearest size OpenAI accepts for a shape.
	 *
	 * @param string $shape Ratio key.
	 * @return string
	 */
	private static function openai_size( $shape ) {
		if ( '1:1' === $shape ) {
			return '1024x1024';
		}

		return '1536x1024';
	}
}
