<?php
/**
 * OpenAI-compatible chat completions provider adapter.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to any provider that speaks the OpenAI Chat Completions API shape.
 *
 * Covers OpenAI itself plus Groq, OpenRouter, DeepSeek, Together, Mistral,
 * Cerebras, xAI, Azure OpenAI, and local runtimes such as Ollama, LM Studio,
 * and vLLM — all of them accept the same request/response shape and differ
 * only in base_url (and sometimes headers). Config keys: base_url, api_key,
 * model, headers (optional array of extra headers merged into the request).
 */
class Blogcraft_Provider_Openai extends Blogcraft_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'openai';
	}

	/**
	 * Human label for the UI.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'OpenAI-compatible', 'blogcraft' );
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

		$body = array(
			'model'    => $this->config( 'model' ),
			'messages' => $messages,
		);

		if ( isset( $options['max_tokens'] ) ) {
			$body['max_tokens'] = $options['max_tokens'];
		}

		if ( isset( $options['temperature'] ) ) {
			$body['temperature'] = $options['temperature'];
		}

		if ( ! empty( $options['json_mode'] ) ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$result = Blogcraft_Http::post_json( $this->endpoint( 'chat/completions' ), $body, $this->headers() );

		if ( '' !== $result['error'] ) {
			$response->error = $result['error'];
			return $response;
		}

		$api_error = $this->extract_error_message( $result['body'] );
		if ( '' !== $api_error ) {
			$response->error = $api_error;
			return $response;
		}

		$content = isset( $result['body']['choices'][0]['message']['content'] )
			? $result['body']['choices'][0]['message']['content']
			: null;

		if ( ! is_string( $content ) ) {
			$response->error = __( 'Unexpected response shape from provider.', 'blogcraft' );
			return $response;
		}

		$response->text              = $content;
		$response->model             = isset( $result['body']['model'] ) ? (string) $result['body']['model'] : '';
		$response->finish_reason     = isset( $result['body']['choices'][0]['finish_reason'] )
			? (string) $result['body']['choices'][0]['finish_reason']
			: '';
		$response->prompt_tokens     = isset( $result['body']['usage']['prompt_tokens'] )
			? (int) $result['body']['usage']['prompt_tokens']
			: 0;
		$response->completion_tokens = isset( $result['body']['usage']['completion_tokens'] )
			? (int) $result['body']['usage']['completion_tokens']
			: 0;

		return $response;
	}

	/**
	 * Model ids this provider offers, best-effort.
	 *
	 * @return array Empty when discovery is unsupported or fails.
	 */
	public function list_models() {
		$result = Blogcraft_Http::get_json( $this->endpoint( 'models' ), $this->headers() );

		if ( '' !== $result['error'] || empty( $result['body']['data'] ) || ! is_array( $result['body']['data'] ) ) {
			return array();
		}

		$ids = array();
		foreach ( $result['body']['data'] as $model ) {
			if ( is_array( $model ) && isset( $model['id'] ) ) {
				$ids[] = (string) $model['id'];
			}
		}

		sort( $ids );

		return $ids;
	}

	/**
	 * Declared capabilities.
	 *
	 * @return array
	 */
	public function capabilities() {
		$capabilities              = parent::capabilities();
		$capabilities['json_mode'] = true;

		return $capabilities;
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
	 * Build request headers, including auth and any user-supplied extras.
	 *
	 * The Authorization header is omitted entirely when no api_key is
	 * configured — local runtimes such as Ollama and LM Studio reject an
	 * empty bearer token outright.
	 *
	 * @return array
	 */
	private function headers() {
		$headers = array();

		$api_key = (string) $this->config( 'api_key', '' );
		if ( '' !== $api_key ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$extra = $this->config( 'headers', array() );
		if ( is_array( $extra ) ) {
			$headers = array_merge( $headers, $extra );
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
}
