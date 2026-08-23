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
			'openai' => __( 'OpenAI — uses your OpenAI writing key if you have one', 'blogcraft' ),
			'gemini' => __( 'Google Gemini — uses your Gemini writing key if you have one', 'blogcraft' ),
			'xai'    => __( 'xAI Grok — uses your Grok writing key if you have one', 'blogcraft' ),
		);
	}

	/**
	 * Where to find each provider's current models and keys.
	 *
	 * @param string $provider Provider id.
	 * @return array Keys: label, key_url, models_url.
	 */
	public static function help( $provider ) {
		$provider = (string) $provider;
		$spec     = Blogcraft_Endpoints::image( '' === $provider ? 'fal' : $provider );

		return array(
			'label'      => (string) $spec['help'],
			'key_url'    => (string) $spec['key_url'],
			'models_url' => (string) $spec['docs_url'],
		);
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

		if ( in_array( $provider, array( 'gemini', 'xai' ), true ) ) {
			return '' !== self::key_for( $provider )
				&& '' !== trim( (string) Blogcraft_Settings::get( self::settings_for( $provider )['model'] ) );
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
		return self::key_for( 'openai' );
	}

	/**
	 * The key to use for pictures from one service.
	 *
	 * Falls back to the writing key, but only when the writing provider is the
	 * same company. A Gemini key will not make an OpenAI image, and treating one
	 * as though it might is how a setup screen comes to lie.
	 *
	 * @param string $service Image service id.
	 * @return string Empty when no usable key is stored.
	 */
	public static function key_for( $service ) {
		$settings = self::settings_for( (string) $service );

		if ( '' !== $settings['key'] ) {
			$own = trim( (string) Blogcraft_Settings::get( $settings['key'] ) );

			if ( '' !== $own ) {
				return $own;
			}
		}

		if ( (string) Blogcraft_Settings::get( 'provider_type' ) === (string) $service ) {
			return trim( (string) Blogcraft_Settings::get( 'provider_api_key' ) );
		}

		return '';
	}

	/**
	 * Which settings hold the key and the model for one picture service.
	 *
	 * Written out in full rather than built from a prefix. A key assembled by
	 * concatenation is invisible to a search of the source and to the test that
	 * checks every setting is read by something — which is how six settings on
	 * the provider screen went unnoticed, and how these two were caught doing
	 * exactly the same thing.
	 *
	 * @param string $service Service id.
	 * @return array Keys: key, model.
	 */
	public static function settings_for( $service ) {
		$map = array(
			'openai' => array(
				'key'   => 'openai_image_key',
				'model' => 'openai_image_model',
			),
			'gemini' => array(
				'key'   => 'image_key_gemini',
				'model' => 'image_model_gemini',
			),
			'xai'    => array(
				'key'   => 'image_key_xai',
				'model' => 'image_model_xai',
			),
			'fal'    => array(
				'key'   => 'fal_api_key',
				'model' => 'fal_model',
			),
		);

		$service = (string) $service;

		return isset( $map[ $service ] ) ? $map[ $service ] : array(
			'key'   => '',
			'model' => '',
		);
	}

	/**
	 * Nothing: the shape every generator answers with.
	 *
	 * Some services hand back an address to fetch and some hand back the image
	 * itself, base64 encoded. One shape covers both so that callers do not have
	 * to know which kind they asked.
	 *
	 * @return array
	 */
	public static function nothing() {
		return array(
			'url'   => '',
			'bytes' => '',
			'mime'  => '',
		);
	}

	/**
	 * Generate one image.
	 *
	 * @param string $prompt    Full image prompt.
	 * @param array  $blueprint Blueprint, for shape.
	 * @return array Keys: url, bytes, mime. All empty when nothing was made.
	 */
	public static function generate( $prompt, $blueprint ) {
		$provider = (string) Blogcraft_Settings::get( 'image_provider' );

		// These all bill per picture, so the cap is checked before the call
		// rather than after it. Returning nothing hands the post back to the
		// free providers, which is the right failure: an image, just not a
		// paid one.
		if ( Blogcraft_Cost::over_image_cap() ) {
			return self::nothing();
		}

		switch ( $provider ) {
			case 'fal':
				$made = self::fal( $prompt, $blueprint );
				break;
			case 'openai':
				$made = self::openai( $prompt, $blueprint );
				break;
			case 'gemini':
				$made = self::gemini( $prompt, $blueprint );
				break;
			case 'xai':
				$made = self::xai( $prompt, $blueprint );
				break;
			default:
				return self::nothing();
		}

		if ( '' !== $made['url'] || '' !== $made['bytes'] ) {
			Blogcraft_Cost::record_image();
		}

		return $made;
	}

	/**
	 * Read an image out of a response, however the service returned it.
	 *
	 * OpenAI-shaped services answer with data[0].url or data[0].b64_json
	 * depending on the model and the request, and which one arrives is not
	 * always the one asked for.
	 *
	 * @param array $body Decoded response body.
	 * @return array
	 */
	public static function from_response( $body ) {
		if ( ! empty( $body['data'][0]['url'] ) ) {
			return array(
				'url'   => esc_url_raw( (string) $body['data'][0]['url'] ),
				'bytes' => '',
				'mime'  => '',
			);
		}

		if ( ! empty( $body['data'][0]['b64_json'] ) ) {
			return self::decode( (string) $body['data'][0]['b64_json'], 'image/png' );
		}

		return self::nothing();
	}

	/**
	 * Turn base64 into bytes, refusing anything that is not an image.
	 *
	 * A service having a bad day can answer with an error page or a truncated
	 * string, and writing that to disk and calling it a JPEG produces a broken
	 * attachment in the media library that nobody can explain later.
	 *
	 * @param string $encoded Base64 payload.
	 * @param string $mime    Reported mime type.
	 * @return array
	 */
	private static function decode( $encoded, $mime ) {
		$bytes = base64_decode( $encoded, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding an image the configured service returned inline, not obfuscated code.

		if ( ! is_string( $bytes ) || '' === $bytes ) {
			return self::nothing();
		}

		$size = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a malformed payload is an expected answer here, not an exceptional one.

		if ( false === $size ) {
			return self::nothing();
		}

		return array(
			'url'   => '',
			'bytes' => $bytes,
			'mime'  => ( '' === $mime && isset( $size['mime'] ) ) ? (string) $size['mime'] : $mime,
		);
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
			return self::nothing();
		}

		if ( '' === (string) Blogcraft_Endpoints::image( 'fal' )['endpoint'] ) {
			return self::nothing();
		}

		$shape = isset( $blueprint['image_shape'] ) ? (string) $blueprint['image_shape'] : '16:9';
		$size  = Blogcraft_Art_Direction::dimensions( $shape );

		$result = Blogcraft_Http::post_json(
			Blogcraft_Endpoints::image( 'fal' )['endpoint'] . ltrim( $model, '/' ),
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
			return self::failed( $result['error'] );
		}

		// Most fal models answer with images[], a few with image{}.
		foreach ( array( $result['body']['images'][0]['url'] ?? '', $result['body']['image']['url'] ?? '' ) as $candidate ) {
			if ( ! empty( $candidate ) ) {
				return array(
					'url'   => esc_url_raw( (string) $candidate ),
					'bytes' => '',
					'mime'  => '',
				);
			}
		}

		return self::nothing();
	}

	/**
	 * Say why a picture could not be made, and answer with nothing.
	 *
	 * @param string $reason Provider's own message, already redacted upstream.
	 * @return array
	 */
	private static function failed( $reason ) {
		Blogcraft_Logger::error(
			'The image could not be generated.',
			array( 'reason' => $reason ),
			null
		);

		return self::nothing();
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
			return self::nothing();
		}

		$model = trim( (string) Blogcraft_Settings::get( 'openai_image_model' ) );

		if ( '' === $model ) {
			return self::nothing();
		}

		if ( '' === (string) Blogcraft_Endpoints::image( 'openai' )['endpoint'] ) {
			return self::nothing();
		}

		$shape = isset( $blueprint['image_shape'] ) ? (string) $blueprint['image_shape'] : '16:9';

		$result = Blogcraft_Http::post_json(
			Blogcraft_Endpoints::image( 'openai' )['endpoint'],
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
			return self::failed( $result['error'] );
		}

		return self::from_response( $result['body'] );
	}

	/**
	 * Google's image models.
	 *
	 * The only route here that answers with the picture itself rather than an
	 * address to fetch it from, which is why every caller deals in bytes as
	 * well as URLs. It is also the one most likely to cost a Gemini user
	 * nothing: the same key that writes the post draws the pictures.
	 *
	 * @param string $prompt    Image prompt.
	 * @param array  $blueprint Blueprint.
	 * @return array
	 */
	private static function gemini( $prompt, $blueprint ) {
		unset( $blueprint );

		$key   = self::key_for( 'gemini' );
		$model = trim( (string) Blogcraft_Settings::get( self::settings_for( 'gemini' )['model'] ) );
		$base  = (string) Blogcraft_Endpoints::image( 'gemini' )['endpoint'];

		if ( '' === $key || '' === $model || '' === $base ) {
			return self::nothing();
		}

		$result = Blogcraft_Http::post_json(
			$base . rawurlencode( $model ) . ':generateContent',
			array(
				'contents'         => array(
					array(
						'parts' => array( array( 'text' => $prompt ) ),
					),
				),
				'generationConfig' => array(
					'responseModalities' => array( 'IMAGE' ),
				),
			),
			// The key travels as a header rather than in the query string, so
			// it cannot be written into a server access log.
			array( 'x-goog-api-key' => $key ),
			120
		);

		if ( '' !== $result['error'] ) {
			return self::failed( $result['error'] );
		}

		$parts = isset( $result['body']['candidates'][0]['content']['parts'] )
			? (array) $result['body']['candidates'][0]['content']['parts']
			: array();

		foreach ( $parts as $part ) {
			// A response usually carries a line of prose alongside the picture.
			if ( ! is_array( $part ) || empty( $part['inlineData']['data'] ) ) {
				continue;
			}

			$made = self::decode(
				(string) $part['inlineData']['data'],
				isset( $part['inlineData']['mimeType'] ) ? (string) $part['inlineData']['mimeType'] : ''
			);

			if ( '' !== $made['bytes'] ) {
				return $made;
			}
		}

		return self::nothing();
	}

	/**
	 * Grok's image models.
	 *
	 * Speaks the OpenAI images protocol, so the response reading is shared.
	 * Size is not a parameter it accepts, so the shape chosen in the blueprint
	 * does not apply here and is deliberately not faked.
	 *
	 * @param string $prompt    Image prompt.
	 * @param array  $blueprint Blueprint.
	 * @return array
	 */
	private static function xai( $prompt, $blueprint ) {
		unset( $blueprint );

		$key      = self::key_for( 'xai' );
		$model    = trim( (string) Blogcraft_Settings::get( self::settings_for( 'xai' )['model'] ) );
		$endpoint = (string) Blogcraft_Endpoints::image( 'xai' )['endpoint'];

		if ( '' === $key || '' === $model || '' === $endpoint ) {
			return self::nothing();
		}

		$result = Blogcraft_Http::post_json(
			$endpoint,
			array(
				'model'           => $model,
				'prompt'          => $prompt,
				'n'               => 1,
				'response_format' => 'url',
			),
			array( 'Authorization' => 'Bearer ' . $key ),
			120
		);

		if ( '' !== $result['error'] ) {
			return self::failed( $result['error'] );
		}

		return self::from_response( $result['body'] );
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
