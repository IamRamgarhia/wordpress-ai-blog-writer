<?php
/**
 * Provider response value object.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * What every provider returns. Never thrown, always returned.
 *
 * A provider that fails sets $error and leaves $text empty; callers branch on
 * is_error() rather than catching, so one misbehaving provider cannot abort a
 * pipeline stage mid-flight.
 */
class Blogcraft_Provider_Response {

	/**
	 * Generated text.
	 *
	 * @var string
	 */
	public $text = '';

	/**
	 * Model that produced the response.
	 *
	 * @var string
	 */
	public $model = '';

	/**
	 * Prompt token count, 0 when the provider does not report it.
	 *
	 * @var int
	 */
	public $prompt_tokens = 0;

	/**
	 * Completion token count, 0 when the provider does not report it.
	 *
	 * @var int
	 */
	public $completion_tokens = 0;

	/**
	 * Why generation stopped, when reported.
	 *
	 * @var string
	 */
	public $finish_reason = '';

	/**
	 * Human-readable error, empty on success.
	 *
	 * @var string
	 */
	public $error = '';

	/**
	 * Whether this response represents a failure.
	 *
	 * @return bool
	 */
	public function is_error() {
		return '' !== $this->error;
	}

	/**
	 * Prompt plus completion tokens.
	 *
	 * @return int
	 */
	public function total_tokens() {
		return $this->prompt_tokens + $this->completion_tokens;
	}
}
