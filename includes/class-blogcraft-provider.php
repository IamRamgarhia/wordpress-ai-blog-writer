<?php
/**
 * Provider contract.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class every LLM provider extends.
 *
 * An abstract class rather than an interface so the existing autoloader
 * (Blogcraft_Foo -> class-blogcraft-foo.php) resolves it without a special case.
 */
abstract class Blogcraft_Provider {

	/**
	 * Provider configuration.
	 *
	 * @var array
	 */
	protected $config = array();

	/**
	 * Build a provider.
	 *
	 * @param array $config Keys vary by provider; typically base_url, api_key, model.
	 */
	public function __construct( $config = array() ) {
		$this->config = is_array( $config ) ? $config : array();
	}

	/**
	 * Read a config value.
	 *
	 * @param string $key      Config key.
	 * @param mixed  $fallback Value to return when the key is absent.
	 * @return mixed
	 */
	protected function config( $key, $fallback = null ) {
		return array_key_exists( $key, $this->config ) ? $this->config[ $key ] : $fallback;
	}

	/**
	 * Machine id, e.g. 'openai'.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Human label for the UI.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Generate a completion.
	 *
	 * @param array $messages Ordered array of array( 'role' => 'system|user|assistant', 'content' => string ).
	 * @param array $options  max_tokens, temperature, json_mode.
	 * @return Blogcraft_Provider_Response Never throws.
	 */
	abstract public function complete( $messages, $options = array() );

	/**
	 * Model ids this provider offers, best-effort.
	 *
	 * @return array Empty when discovery is unsupported or fails.
	 */
	abstract public function list_models();

	/**
	 * Add a plain-language hint to a provider error, where one helps.
	 *
	 * Providers report a wrong model id accurately and unhelpfully: Gemini
	 * says "GenerateContentRequest.model: unexpected model name format",
	 * OpenAI says the model "does not exist". Both are correct and neither
	 * tells you the likeliest cause, which is that the field next to the API
	 * key ended up holding the *name* of the API key — the two sit side by
	 * side in every provider's console, and the model field asks for an id
	 * "exactly as your provider writes it", which reads like an instruction
	 * to copy the thing you just made.
	 *
	 * The failure also arrives late: nothing validates the id at save time,
	 * so the first sign is a job burning all three attempts hours later.
	 *
	 * @param string $message Error text from the provider.
	 * @return string
	 */
	protected function explain( $message ) {
		$message = (string) $message;
		$lower   = strtolower( $message );

		$model_trouble = (
			false !== strpos( $lower, 'model name format' )
			|| false !== strpos( $lower, 'model not found' )
			|| ( false !== strpos( $lower, 'model' ) && false !== strpos( $lower, 'does not exist' ) )
			|| ( false !== strpos( $lower, 'model' ) && false !== strpos( $lower, 'invalid' ) )
		);

		if ( ! $model_trouble ) {
			return $message;
		}

		return $message . ' — ' . __( 'That usually means the Model field holds something other than a model id (the name of your API key is the common one). Open Settings and use "Show the models on my account" to pick a real one.', 'blogcraft-ai-writer' );
	}

	/**
	 * Declared capabilities, so callers degrade rather than fail.
	 *
	 * @return array
	 */
	public function capabilities() {
		return array(
			'json_mode'  => false,
			'streaming'  => false,
			'vision'     => false,
			'max_tokens' => 4096,
		);
	}
}
