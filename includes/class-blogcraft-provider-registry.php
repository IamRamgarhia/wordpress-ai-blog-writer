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
	 * Every provider, and everything that differs between them.
	 *
	 * Most of these speak the OpenAI chat-completions protocol, so they need no
	 * adapter of their own — only an address and a link to where the keys and
	 * the model list live. Listing them by name rather than leaving one entry
	 * called "OpenAI-compatible" is the difference between a user finding their
	 * provider and assuming it is not supported.
	 *
	 * No model ids appear here. Providers retire them on their own schedule and
	 * this plugin has already shipped one dead model id in a hint; the links go
	 * to each provider's live catalogue instead.
	 *
	 * @return array Machine id => spec.
	 */
	public static function catalogue() {
		return array(
			'openai'     => array(
				'label'    => __( 'OpenAI — GPT', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'https://api.openai.com/v1',
				'help'     => 'OpenAI',
				'key_url'  => 'https://platform.openai.com/api-keys',
				'docs_url' => 'https://platform.openai.com/docs/models',
			),
			'anthropic'  => array(
				'label'    => __( 'Anthropic — Claude', 'blogcraft' ),
				'adapter'  => 'anthropic',
				'base_url' => 'https://api.anthropic.com/v1',
				'help'     => 'Anthropic Console',
				'key_url'  => 'https://console.anthropic.com/settings/keys',
				'docs_url' => 'https://docs.anthropic.com/en/docs/about-claude/models',
			),
			'gemini'     => array(
				'label'    => __( 'Google — Gemini', 'blogcraft' ),
				'adapter'  => 'gemini',
				'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
				'help'     => 'Google AI Studio',
				'key_url'  => 'https://aistudio.google.com/app/apikey',
				'docs_url' => 'https://ai.google.dev/gemini-api/docs/models',
			),
			'xai'        => array(
				'label'    => __( 'xAI — Grok', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'https://api.x.ai/v1',
				'help'     => 'xAI Console',
				'key_url'  => 'https://console.x.ai/',
				'docs_url' => 'https://docs.x.ai/docs/models',
			),
			'moonshot'   => array(
				'label'    => __( 'Moonshot — Kimi', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'https://api.moonshot.ai/v1',
				'help'     => 'Moonshot Platform',
				'key_url'  => 'https://platform.moonshot.ai/console/api-keys',
				'docs_url' => 'https://platform.moonshot.ai/docs/intro',
			),
			'deepseek'   => array(
				'label'    => __( 'DeepSeek', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'https://api.deepseek.com/v1',
				'help'     => 'DeepSeek Platform',
				'key_url'  => 'https://platform.deepseek.com/api_keys',
				'docs_url' => 'https://api-docs.deepseek.com/quick_start/pricing',
			),
			'groq'       => array(
				'label'    => __( 'Groq — fast, free tier', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'https://api.groq.com/openai/v1',
				'help'     => 'Groq Console',
				'key_url'  => 'https://console.groq.com/keys',
				'docs_url' => 'https://console.groq.com/docs/models',
			),
			'openrouter' => array(
				'label'    => __( 'OpenRouter — many models, one key', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'https://openrouter.ai/api/v1',
				'help'     => 'OpenRouter',
				'key_url'  => 'https://openrouter.ai/keys',
				'docs_url' => 'https://openrouter.ai/models',
			),
			'mistral'    => array(
				'label'    => __( 'Mistral', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'https://api.mistral.ai/v1',
				'help'     => 'Mistral Console',
				'key_url'  => 'https://console.mistral.ai/api-keys/',
				'docs_url' => 'https://docs.mistral.ai/getting-started/models/models_overview/',
			),
			'together'   => array(
				'label'    => __( 'Together AI', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'https://api.together.xyz/v1',
				'help'     => 'Together AI',
				'key_url'  => 'https://api.together.xyz/settings/api-keys',
				'docs_url' => 'https://docs.together.ai/docs/serverless-models',
			),
			'fireworks'  => array(
				'label'    => __( 'Fireworks AI', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'https://api.fireworks.ai/inference/v1',
				'help'     => 'Fireworks AI',
				'key_url'  => 'https://fireworks.ai/account/api-keys',
				'docs_url' => 'https://fireworks.ai/models',
			),
			'cerebras'   => array(
				'label'    => __( 'Cerebras', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'https://api.cerebras.ai/v1',
				'help'     => 'Cerebras Cloud',
				'key_url'  => 'https://cloud.cerebras.ai/',
				'docs_url' => 'https://inference-docs.cerebras.ai/models/overview',
			),
			'ollama'     => array(
				'label'    => __( 'Ollama — on this machine, no key', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'http://localhost:11434/v1',
				'help'     => 'Ollama',
				'key_url'  => '',
				'docs_url' => 'https://ollama.com/library',
			),
			'lmstudio'   => array(
				'label'    => __( 'LM Studio — on this machine, no key', 'blogcraft' ),
				'adapter'  => 'openai',
				'base_url' => 'http://localhost:1234/v1',
				'help'     => 'LM Studio',
				'key_url'  => '',
				'docs_url' => 'https://lmstudio.ai/docs/app/api/endpoints/openai',
			),
			'custom'     => array(
				'label'    => __( 'Anything else — enter the address yourself', 'blogcraft' ),
				'adapter'  => 'custom',
				'base_url' => '',
				'help'     => '',
				'key_url'  => '',
				'docs_url' => '',
			),
		);
	}

	/**
	 * Known provider types and their human labels.
	 *
	 * @return array Machine id => translatable label.
	 */
	public static function types() {
		$out = array();

		foreach ( self::catalogue() as $id => $spec ) {
			$out[ $id ] = $spec['label'];
		}

		return $out;
	}

	/**
	 * Build a provider instance for a given type.
	 *
	 * @param string $type   One of the ids from types().
	 * @param array  $config Provider-specific configuration.
	 * @return Blogcraft_Provider|null Null for an unrecognised type; never fatals.
	 */
	public static function make( $type, $config = array() ) {
		$config    = is_array( $config ) ? $config : array();
		$catalogue = self::catalogue();
		$type      = (string) $type;

		if ( ! isset( $catalogue[ $type ] ) ) {
			return null;
		}

		switch ( $catalogue[ $type ]['adapter'] ) {
			case 'gemini':
				return new Blogcraft_Provider_Gemini( $config );
			case 'anthropic':
				return new Blogcraft_Provider_Anthropic( $config );
			case 'custom':
				return new Blogcraft_Provider_Custom( $config );
			default:
				return new Blogcraft_Provider_Openai( $config );
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
		$catalogue = self::catalogue();
		$type      = (string) $type;

		return isset( $catalogue[ $type ] ) ? (string) $catalogue[ $type ]['base_url'] : '';
	}

	/**
	 * Where to get an API key for each provider.
	 *
	 * The commonest place to get stuck is not the plugin at all: it is finding
	 * the page that issues a key. Every provider buries it somewhere different.
	 *
	 * @param string $type Provider type id.
	 * @return array Keys: label, key_url, docs_url. Empty strings when unknown.
	 */
	public static function help( $type ) {
		$catalogue = self::catalogue();
		$type      = (string) $type;

		if ( ! isset( $catalogue[ $type ] ) ) {
			$type = 'custom';
		}

		return array(
			'label'    => (string) $catalogue[ $type ]['help'],
			'key_url'  => (string) $catalogue[ $type ]['key_url'],
			'docs_url' => (string) $catalogue[ $type ]['docs_url'],
		);
	}

	/**
	 * Every provider's default address, for the front end.
	 *
	 * @return array
	 */
	public static function base_url_map() {
		$out = array();

		foreach ( self::catalogue() as $id => $spec ) {
			$out[ $id ] = (string) $spec['base_url'];
		}

		return $out;
	}

	/**
	 * The same, for every provider at once, for the front end to switch between.
	 *
	 * @return array
	 */
	public static function help_map() {
		$out = array();

		foreach ( array_keys( self::types() ) as $type ) {
			$out[ $type ] = self::help( $type );
		}

		return $out;
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
