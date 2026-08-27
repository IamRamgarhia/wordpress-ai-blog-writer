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
		// Labels stay here because a translator needs them as literals; the
		// addresses live in data/providers.json so the list can be filtered and
		// changed without a release. Everything in that file is disclosed in
		// readme.txt under External Services.
		// A handful of these have a genuine, long-standing free tier or free
		// models and say so; the rest are marked paid rather than left silent,
		// so "does this cost anything" is never a guess. Exact limits are not
		// named here for the same reason no model id is: a quota changes on
		// the provider's own schedule, not this plugin's, and a number copied
		// in today is a number wrong by the time anyone reads it. The docs
		// link next to each provider on the settings screen always has the
		// current figure.
		$labels = array(
			'openai'      => __( 'OpenAI — GPT, paid', 'blogcraft' ),
			'anthropic'   => __( 'Anthropic — Claude, paid', 'blogcraft' ),
			'gemini'      => __( 'Google — Gemini, free tier', 'blogcraft' ),
			'xai'         => __( 'xAI — Grok, paid', 'blogcraft' ),
			'moonshot'    => __( 'Moonshot — Kimi, paid', 'blogcraft' ),
			'deepseek'    => __( 'DeepSeek, paid', 'blogcraft' ),
			'groq'        => __( 'Groq — fast, free tier', 'blogcraft' ),
			'openrouter'  => __( 'OpenRouter — many models, one key, some of them free', 'blogcraft' ),
			'mistral'     => __( 'Mistral, free tier', 'blogcraft' ),
			'together'    => __( 'Together AI, paid', 'blogcraft' ),
			'fireworks'   => __( 'Fireworks AI, paid', 'blogcraft' ),
			'cerebras'    => __( 'Cerebras — free credits to start, then paid', 'blogcraft' ),
			'huggingface' => __( 'Hugging Face — hundreds of open models, free tier', 'blogcraft' ),
			'ollama'      => __( 'Ollama — on this machine, free, no key', 'blogcraft' ),
			'lmstudio'    => __( 'LM Studio — on this machine, free, no key', 'blogcraft' ),
			'jan'         => __( 'Jan — on this machine, free, no key', 'blogcraft' ),
			'llamacpp'    => __( 'llama.cpp — on this machine, free, no key', 'blogcraft' ),
			'custom'      => __( 'Anything else — enter the address yourself', 'blogcraft' ),
		);

		$out = array();

		// Listed first when it exists, because it is the easiest route there
		// is: no signup, no key, no model id. Left out entirely when it does
		// not, since an option that cannot work is worse than no option.
		if ( Blogcraft_Provider_Wpai::is_available() ) {
			$out['wpai'] = array(
				// "Whatever WordPress is set up with" rather than free or paid:
				// this route hands the request to WordPress itself, which may
				// be pointed at a free local model or a paid account, and the
				// plugin genuinely cannot tell which from here.
				'label'    => __( 'WordPress AI Client — no key needed, free or paid depending on how WordPress is set up', 'blogcraft' ),
				'cost'     => 'varies',
				'adapter'  => 'wpai',
				'base_url' => '',
				'help'     => 'WordPress',
				'key_url'  => '',
				'docs_url' => 'https://developer.wordpress.org/plugins/ai/',
			);
		}

		foreach ( Blogcraft_Endpoints::text() as $id => $spec ) {
			if ( ! is_array( $spec ) ) {
				continue;
			}

			$out[ $id ] = array_merge(
				array(
					'label'    => isset( $labels[ $id ] ) ? $labels[ $id ] : $id,
					'adapter'  => 'openai',
					'base_url' => '',
					'help'     => '',
					'key_url'  => '',
					'docs_url' => '',
					// Anything added through the filter without one is grouped as
					// "depends" rather than assumed free. Guessing wrong in that
					// direction is the expensive way round.
					'cost'     => 'varies',
				),
				$spec
			);
		}

		return $out;
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
	 * The cost groups, in the order somebody spending nothing wants them.
	 *
	 * Every label already said free or paid, but in a flat list of nineteen
	 * that only helps a reader who reads all nineteen. Most read the first
	 * few and choose, which put OpenAI and Anthropic — both of which want a
	 * card before they will answer anything — in front of every route that
	 * costs nothing. Grouping changes none of what is offered. It changes
	 * which options somebody has seen by the time they decide.
	 *
	 * The order is deliberate: no key at all, then a key but no card, then
	 * credits that run out, then paid.
	 *
	 * @return array Cost class => translatable group heading.
	 */
	public static function groups() {
		return array(
			'local'     => __( 'Free — runs on your own machine, no key, no account', 'blogcraft' ),
			'free_tier' => __( 'Free tier — a key, but no card', 'blogcraft' ),
			'trial'     => __( 'Free credits to start, then paid', 'blogcraft' ),
			'paid'      => __( 'Paid', 'blogcraft' ),
			'varies'    => __( 'Depends on how you set it up', 'blogcraft' ),
		);
	}

	/**
	 * Every provider, arranged under those headings.
	 *
	 * A class this does not recognise goes under "depends" rather than
	 * being dropped, so a provider added through the filter stays
	 * selectable even when it says nothing about cost.
	 *
	 * @return array Cost class => array( provider id => label ).
	 */
	public static function grouped_types() {
		$out = array();

		foreach ( array_keys( self::groups() ) as $class_name ) {
			$out[ $class_name ] = array();
		}

		foreach ( self::catalogue() as $id => $spec ) {
			$cost = isset( $spec['cost'] ) ? (string) $spec['cost'] : 'varies';

			if ( ! isset( $out[ $cost ] ) ) {
				$cost = 'varies';
			}

			$out[ $cost ][ $id ] = $spec['label'];
		}

		return array_filter( $out );
	}

	/**
	 * Whether a provider can be used without paying anybody anything.
	 *
	 * @param string $type Provider id.
	 * @return bool
	 */
	public static function is_free( $type ) {
		$catalogue = self::catalogue();
		$type      = (string) $type;

		if ( ! isset( $catalogue[ $type ]['cost'] ) ) {
			return false;
		}

		return in_array(
			(string) $catalogue[ $type ]['cost'],
			array( 'local', 'free_tier' ),
			true
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
		$config    = is_array( $config ) ? $config : array();
		$catalogue = self::catalogue();
		$type      = (string) $type;

		if ( ! isset( $catalogue[ $type ] ) ) {
			return null;
		}

		switch ( $catalogue[ $type ]['adapter'] ) {
			case 'wpai':
				return new Blogcraft_Provider_Wpai( $config );
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
	 * The extra configuration only the custom adapter understands.
	 *
	 * Named here rather than assembled inline so the settings screen and the
	 * adapter cannot end up disagreeing about which fields exist, which is
	 * exactly how these six came to be rendered and never used.
	 *
	 * Written as literal setting names rather than assembled from a prefix, so
	 * that searching the source for a setting finds the place it is used. A
	 * key built by concatenation is invisible to any audit, which is part of
	 * how these six went unnoticed.
	 *
	 * @return array Adapter config key => setting name.
	 */
	public static function custom_config_keys() {
		return array(
			'auth_header'            => 'provider_auth_header',
			'auth_prefix'            => 'provider_auth_prefix',
			'request_template'       => 'provider_request_template',
			'text_path'              => 'provider_text_path',
			'prompt_tokens_path'     => 'provider_prompt_tokens_path',
			'completion_tokens_path' => 'provider_completion_tokens_path',
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
		$type = (string) Blogcraft_Settings::get( 'provider_type' );

		// The AI Client needs neither key nor model id: WordPress holds the
		// credentials and picks the model. What it does need is a provider
		// plugin actually installed behind it, which is a different question
		// from whether the function exists.
		if ( 'wpai' === $type ) {
			return Blogcraft_Provider_Wpai::is_ready();
		}

		if ( '' === trim( (string) Blogcraft_Settings::get( 'provider_model' ) ) ) {
			return false;
		}

		// A key saved for a different provider is not a key for this one.
		// Keys live in one shared setting, so switching provider leaves the
		// previous one's key behind — and counting it as configured means the
		// checklist says "ready", the first post starts, and it fails several
		// stages in with an authentication error nothing explains.
		$owner    = (string) Blogcraft_Settings::get( 'provider_key_owner' );
		$key_fits = ( '' === $owner || $owner === $type );

		$has_key = $key_fits && '' !== trim( (string) Blogcraft_Settings::get( 'provider_api_key' ) );

		// Whether this provider issues keys at all. Ollama and LM Studio run
		// on the machine and need none, so an address is the whole setup; a
		// custom endpoint is the user's own and may be either.
		$help    = self::help( $type );
		$keyless = ( '' === trim( (string) $help['key_url'] ) );

		if ( $keyless ) {
			return '' !== trim( (string) Blogcraft_Settings::get( 'provider_base_url' ) )
				|| '' !== self::default_base_url( $type );
		}

		// Every hosted provider has a default address, so accepting an address
		// as proof of setup meant they all counted as configured with no key
		// whatsoever — the checklist said ready before anything had been
		// pasted in at all.
		return $has_key;
	}


	/**
	 * Build the provider configured in plugin settings.
	 *
	 * @param string $model Model id to use instead of the configured one, for
	 *                      a stage that should run on something cheaper.
	 * @return Blogcraft_Provider|null Null when no provider type is stored, or the
	 *                                 stored type is not recognised.
	 */
	public static function from_settings( $model = '' ) {
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
			// Same key, same account, same address — only the model id
			// changes, so a caller asking for a cheaper model for one stage
			// does not need a second provider configured.
			'model'    => ( '' !== trim( (string) $model ) ) ? trim( (string) $model ) : Blogcraft_Settings::get( 'provider_model' ),
		);

		// The custom adapter reads six more keys, and nothing was passing them.
		// Six fields on the settings screen were being filled in, saved, and
		// then ignored — every custom endpoint fell back to Authorization,
		// Bearer, and a default response path, whatever the user had typed.
		if ( 'custom' === $type ) {
			foreach ( self::custom_config_keys() as $key => $setting ) {
				$config[ $key ] = Blogcraft_Settings::get( $setting );
			}
		}

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
