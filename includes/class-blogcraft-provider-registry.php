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
	 * Where each provider type talks by default.
	 *
	 * Without these a user who supplies only a key and a model gets a request
	 * to a relative path and an error reading "A valid URL was not provided",
	 * which says nothing about what to do. The three hosted providers have one
	 * obvious address each; the custom adapter deliberately has none, because
	 * choosing the address is the entire point of picking it.
	 *
	 * @param string $type Provider type id.
	 * @return string Empty when the type has no sensible default.
	 */
	public static function default_base_url( $type ) {
		$defaults = array(
			'openai'    => 'https://api.openai.com/v1',
			'gemini'    => 'https://generativelanguage.googleapis.com/v1beta',
			'anthropic' => 'https://api.anthropic.com/v1',
		);

		$type = (string) $type;

		return isset( $defaults[ $type ] ) ? $defaults[ $type ] : '';
	}

	/**
	 * Whether a provider is genuinely usable, not merely selected.
	 *
	 * The from_settings() method returns an object whenever a provider type is
	 * chosen, and
	 * the type defaults to openai, so it is never null and cannot answer whether
	 * setup is complete. A provider needs a model, plus either a key or a base URL
	 * for a local endpoint that needs no key.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		if ( '' === trim( (string) Blogcraft_Settings::get( 'provider_model' ) ) ) {
			return false;
		}

		$type = (string) Blogcraft_Settings::get( 'provider_type' );

		$has_key  = '' !== trim( (string) Blogcraft_Settings::get( 'provider_api_key' ) );
		$has_base = '' !== trim( (string) Blogcraft_Settings::get( 'provider_base_url' ) )
			|| '' !== self::default_base_url( $type );

		return $has_key || $has_base;
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

		$base_url = trim( (string) Blogcraft_Settings::get( 'provider_base_url' ) );

		if ( '' === $base_url ) {
			$base_url = self::default_base_url( $type );
		}

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
	 * When the monthly token cap has been reached, this returns an error
	 * response immediately without calling any provider at all — the cap
	 * exists to prevent spend, not to report on it after the fact. On a
	 * successful completion, usage is recorded via Blogcraft_Cost.
	 *
	 * @param Blogcraft_Provider[] $providers Providers to try, in order.
	 * @param array                $messages  Ordered array of array( 'role' => ..., 'content' => ... ).
	 * @param array                $options   Passed through to each provider's complete().
	 * @return Blogcraft_Provider_Response
	 */
	public static function complete_with_fallback( array $providers, $messages, $options = array() ) {
		if ( Blogcraft_Cost::over_cap() ) {
			$response        = new Blogcraft_Provider_Response();
			$response->error = sprintf(
				/* translators: %d: configured monthly token cap. */
				__( 'Monthly token cap of %d tokens has been reached; no request was sent.', 'blogcraft' ),
				(int) Blogcraft_Settings::get( 'monthly_token_cap' )
			);
			return $response;
		}

		$last        = new Blogcraft_Provider_Response();
		$last->error = __( 'No providers were configured.', 'blogcraft' );

		foreach ( $providers as $provider ) {
			if ( ! $provider instanceof Blogcraft_Provider ) {
				continue;
			}

			$response = $provider->complete( $messages, $options );

			if ( ! $response->is_error() ) {
				Blogcraft_Cost::record( $provider->id(), $response->model, $response->prompt_tokens, $response->completion_tokens );
				return $response;
			}

			$last = $response;
		}

		return $last;
	}

	/**
	 * Probe a provider for reachability, available models, and capabilities.
	 *
	 * Tries list_models() first; a non-empty result means the provider is
	 * reachable. When it returns empty, falls back to a minimal live
	 * completion (a single short message, max_tokens of 1) and treats a
	 * non-error response as reachable — Anthropic has no discovery endpoint
	 * and would otherwise always look unreachable. Never throws; the error
	 * field carries only the provider's own message, never its configuration.
	 *
	 * @param Blogcraft_Provider $provider Provider to probe.
	 * @return array array( 'reachable' => bool, 'models' => array, 'capabilities' => array, 'error' => string ).
	 */
	public static function probe( Blogcraft_Provider $provider ) {
		$result = array(
			'reachable'    => false,
			'models'       => array(),
			'capabilities' => array(),
			'error'        => '',
		);

		try {
			$result['capabilities'] = $provider->capabilities();
		} catch ( Throwable $e ) {
			$result['capabilities'] = array();
		}

		$models = array();
		try {
			$models = $provider->list_models();
		} catch ( Throwable $e ) {
			$models = array();
		}

		if ( is_array( $models ) && ! empty( $models ) ) {
			$result['models']    = $models;
			$result['reachable'] = true;
			return $result;
		}

		$probe_message = array(
			array(
				'role'    => 'user',
				'content' => 'ping',
			),
		);

		try {
			$response = $provider->complete( $probe_message, array( 'max_tokens' => 1 ) );
		} catch ( Throwable $e ) {
			$response = null;
		}

		if ( $response instanceof Blogcraft_Provider_Response && ! $response->is_error() ) {
			$result['reachable'] = true;
			return $result;
		}

		$result['error'] = ( $response instanceof Blogcraft_Provider_Response && '' !== $response->error )
			? $response->error
			: __( 'Provider could not be reached.', 'blogcraft' );

		return $result;
	}
}
