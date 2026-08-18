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
