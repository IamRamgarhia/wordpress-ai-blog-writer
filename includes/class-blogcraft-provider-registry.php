<?php
/**
 * Provider registry.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps provider type ids to adapter instances, and drives multi-provider
 * fallback.
 *
 * Every method here is static: the registry itself holds no state, it just
 * knows how to build the adapters that do.
 */
class Blogcraft_Provider_Registry {

	/**
	 * Known provider types and their human labels.
	 *
	 * @return array Machine id => translatable label.
	 */
	public static function types() {
		return array(
			'openai'    => __( 'OpenAI-compatible', 'blogcraft' ),
			'gemini'    => __( 'Google Gemini', 'blogcraft' ),
			'anthropic' => __( 'Anthropic', 'blogcraft' ),
			'custom'    => __( 'Custom endpoint', 'blogcraft' ),
		);
	}

	/**
	 * Build a provider instance for a given type.
	 *
	 * @param string $type   One of the ids from types().
	 * @param array  $config Provider-specific configuration.
	 * @return Blogcraft_Provider|null Null for an unrecognised type; never fatals.
	 */
	public static function make( $type, $config = array() ) {
		$config = is_array( $config ) ? $config : array();

		switch ( (string) $type ) {
			case 'openai':
				return new Blogcraft_Provider_Openai( $config );
			case 'gemini':
				return new Blogcraft_Provider_Gemini( $config );
			case 'anthropic':
				return new Blogcraft_Provider_Anthropic( $config );
			case 'custom':
				return new Blogcraft_Provider_Custom( $config );
			default:
				return null;
		}
	}

	/**
	 * Build the provider configured in plugin settings.
	 *
	 * @return Blogcraft_Provider|null Null when no provider type is stored, or the
	 *                                 stored type is not recognised.
	 */
	public static function from_settings() {
		$type = (string) Blogcraft_Settings::get( 'provider_type' );

		if ( '' === $type || ! array_key_exists( $type, self::types() ) ) {
			return null;
		}

		$base_url = Blogcraft_Settings::get( 'provider_base_url' );

		$config = array(
			// Adapters disagree on the config key for the request target:
			// the OpenAI/Gemini/Anthropic adapters read 'base_url', the
			// custom adapter reads 'endpoint'. Both are populated from the
			// same stored setting so from_settings() works for every type.
			'base_url' => $base_url,
			'endpoint' => $base_url,
			'api_key'  => Blogcraft_Settings::get( 'provider_api_key' ),
			'model'    => Blogcraft_Settings::get( 'provider_model' ),
		);

		return self::make( $type, $config );
	}

	/**
	 * Try each provider in order, returning the first non-error response.
	 *
	 * When every provider fails, the last error response is returned so the
	 * caller sees the most recent (and usually most relevant) diagnosis. A
	 * provider that succeeds means later providers in the list are never
	 * called.
	 *
	 * @param Blogcraft_Provider[] $providers Providers to try, in order.
	 * @param array                $messages  Ordered array of array( 'role' => ..., 'content' => ... ).
	 * @param array                $options   Passed through to each provider's complete().
	 * @return Blogcraft_Provider_Response
	 */
	public static function complete_with_fallback( array $providers, $messages, $options = array() ) {
		$last        = new Blogcraft_Provider_Response();
		$last->error = __( 'No providers were configured.', 'blogcraft' );

		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof Blogcraft_Provider ) {
				continue;
			}

			$response = $provider->complete( $messages, $options );

			if ( ! $response->is_error() ) {
				return $response;
			}

			$last = $response;
		}

		return $last;
	}
}
