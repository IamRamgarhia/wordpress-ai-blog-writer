<?php
/**
 * Anthropic Messages API provider adapter.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Anthropic Messages API.
 *
 * Two things this adapter has to absorb that the OpenAI shape does not:
 * system prompts are a top-level `system` string rather than a message in
 * the list, and `max_tokens` is a required field the API rejects requests
 * without — this adapter always sends one. Config keys: base_url, api_key,
 * model.
 */
class Blogcraft_Provider_Anthropic extends Blogcraft_Provider {

	/**
	 * Anthropic API version this adapter targets.
	 *
	 * @var string
	 */
	const API_VERSION = '2023-06-01';

	/**
	 * Default max_tokens sent when the caller omits one. The Messages API
	 * rejects a request that has no max_tokens at all, so this adapter must
	 * always supply a value.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_TOKENS = 4096;

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'anthropic';
	}

	/**
	 * Human label for the UI.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Anthropic', 'blogcraft' );
	}

	/**
	 * Generate a completion.
	 *
	 * @param array $messages Ordered array of array( 'role' => ..., 'content' => ... ).
	 * @param array $options  max_tokens, temperature, json_mode.
	 * @return Blogcraft_Provider_Response Never throws.
	 */
	public function complete( $messages, $options = array() ) {
		$response = new Blogcraft_Provider_Response();

		$body = $this->build_request_body( $messages, $options );

		$result = Blogcraft_Http::post_json( $this->endpoint( 'messages' ), $body, $this->headers() );

		$api_error = $this->extract_error_message( $result['body'] );
		if ( '' !== $api_error ) {
			$response->error = $this->format_api_error( $api_error, $result['code'] );
			return $response;
		}

		if ( '' !== $result['error'] ) {
			$response->error = $result['error'];
			return $response;
		}

		$content = isset( $result['body']['content'][0]['text'] )
			? $result['body']['content'][0]['text']
			: null;

		if ( ! is_string( $content ) ) {
			$response->error = __( 'Unexpected response shape from provider.', 'blogcraft' );
			return $response;
		}

		$response->text              = $content;
		$response->model             = isset( $result['body']['model'] ) ? (string) $result['body']['model'] : '';
		$response->finish_reason     = isset( $result['body']['stop_reason'] )
			? (string) $result['body']['stop_reason']
			: '';
		$response->prompt_tokens     = isset( $result['body']['usage']['input_tokens'] )
			? (int) $result['body']['usage']['input_tokens']
			: 0;
		$response->completion_tokens = isset( $result['body']['usage']['output_tokens'] )
			? (int) $result['body']['usage']['output_tokens']
			: 0;

		return $response;
	}

	/**
	 * Model ids this provider offers.
	 *
	 * Anthropic has no public model-discovery endpoint, so an empty array is
	 * the correct, permanent return value here — not a placeholder for a
	 * future implementation.
	 *
	 * @return array Always empty.
	 */
	public function list_models() {
		return array();
	}

	/**
	 * Declared capabilities.
	 *
	 * @return array
	 */
	public function capabilities() {
		$capabilities              = parent::capabilities();
		$capabilities['json_mode'] = false;

		return $capabilities;
	}

	/**
	 * Build the Messages API request body: a top-level `system` string
	 * (omitted when there are no system messages), `messages[]` carrying only
	 * user/assistant turns, and a required `max_tokens`.
	 *
	 * @param array $messages Ordered array of array( 'role' => ..., 'content' => ... ).
	 * @param array $options  max_tokens, temperature, json_mode.
	 * @return array
	 */
	private function build_request_body( $messages, $options ) {
		$turns             = array();
		$system_paragraphs = array();

		foreach ( $messages as $message ) {
			$role    = isset( $message['role'] ) ? (string) $message['role'] : '';
			$content = isset( $message['content'] ) ? (string) $message['content'] : '';

			if ( 'system' === $role ) {
				$system_paragraphs[] = $content;
				continue;
			}

			$turns[] = array(
				'role'    => $role,
				'content' => $content,
			);
		}

		$body = array(
			'model'      => $this->config( 'model' ),
			'messages'   => $turns,
			'max_tokens' => isset( $options['max_tokens'] ) ? $options['max_tokens'] : self::DEFAULT_MAX_TOKENS,
		);

		if ( ! empty( $system_paragraphs ) ) {
			$body['system'] = implode( "\n\n", $system_paragraphs );
		}

		if ( isset( $options['temperature'] ) ) {
			$body['temperature'] = $options['temperature'];
		}

		return $body;
	}

	/**
	 * Build a full endpoint URL from the configured base_url.
	 *
	 * @param string $path Path segment, no leading slash.
	 * @return string
	 */
	private function endpoint( $path ) {
		return rtrim( (string) $this->config( 'base_url', '' ), '/' ) . '/' . $path;
	}

	/**
	 * Build request headers: `x-api-key` (omitted entirely when no api_key is
	 * configured) and the fixed `anthropic-version`.
	 *
	 * @return array
	 */
	private function headers() {
		$headers = array( 'anthropic-version' => self::API_VERSION );

		$api_key = (string) $this->config( 'api_key', '' );
		if ( '' !== $api_key ) {
			$headers['x-api-key'] = $api_key;
		}

		return $headers;
	}

	/**
	 * Pull error.message out of a decoded response body, when present.
	 *
	 * @param array $body Decoded response body.
	 * @return string Empty when no API error message is present.
	 */
	private function extract_error_message( $body ) {
		if ( isset( $body['error']['message'] ) && is_string( $body['error']['message'] ) ) {
			return $body['error']['message'];
		}

		return '';
	}

	/**
	 * Prefix a provider-supplied error message with the HTTP status, when the
	 * status is a non-2xx failure, so the user gets both the category and the
	 * diagnosis.
	 *
	 * @param string $message API-supplied error message.
	 * @param int    $code    HTTP status code, 0 when unavailable (transport failure).
	 * @return string
	 */
	private function format_api_error( $message, $code ) {
		$code = (int) $code;

		if ( $code > 0 && ( $code < 200 || $code >= 300 ) ) {
			return sprintf(
				/* translators: 1: HTTP status code, 2: error message reported by the provider. */
				__( 'HTTP %1$d: %2$s', 'blogcraft' ),
				$code,
				$message
			);
		}

		return $message;
	}
}
