<?php
/**
 * Google Gemini provider adapter.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to the Google Gemini generateContent API.
 *
 * Gemini differs from the OpenAI shape in three ways this adapter has to
 * absorb: it authenticates via a `key` query parameter rather than a header,
 * it has no `assistant` role (uses `model` instead), and system prompts are a
 * top-level `system_instruction` field rather than a message in the list.
 * Config keys: base_url, api_key, model.
 */
class Blogcraft_Provider_Gemini extends Blogcraft_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'gemini';
	}

	/**
	 * Human label for the UI.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Google Gemini', 'dicecodes-ai-blog-writer' );
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

		$result = Blogcraft_Http::post_json( $this->endpoint( 'generateContent' ), $body, $this->auth_headers() );

		$api_error = $this->extract_error_message( $result['body'] );
		if ( '' !== $api_error ) {
			$response->error        = $this->format_api_error( $api_error, $result['code'] );
			$response->rate_limited = ( 429 === (int) $result['code'] );
			return $response;
		}

		if ( '' !== $result['error'] ) {
			$response->error = $result['error'];
			return $response;
		}

		$content = isset( $result['body']['candidates'][0]['content']['parts'][0]['text'] )
			? $result['body']['candidates'][0]['content']['parts'][0]['text']
			: null;

		if ( ! is_string( $content ) ) {
			$response->error = __( 'Unexpected response shape from provider.', 'dicecodes-ai-blog-writer' );
			return $response;
		}

		$response->text              = $content;
		$response->model             = (string) $this->config( 'model', '' );
		$response->finish_reason     = isset( $result['body']['candidates'][0]['finishReason'] )
			? (string) $result['body']['candidates'][0]['finishReason']
			: '';
		$response->prompt_tokens     = isset( $result['body']['usageMetadata']['promptTokenCount'] )
			? (int) $result['body']['usageMetadata']['promptTokenCount']
			: 0;
		$response->completion_tokens = isset( $result['body']['usageMetadata']['candidatesTokenCount'] )
			? (int) $result['body']['usageMetadata']['candidatesTokenCount']
			: 0;

		return $response;
	}

	/**
	 * Model ids this provider offers, best-effort.
	 *
	 * @return array Empty when discovery is unsupported or fails.
	 */
	public function list_models() {
		$result = Blogcraft_Http::get_json( $this->endpoint_base( 'models' ), $this->auth_headers() );

		if ( '' !== $result['error'] || empty( $result['body']['models'] ) || ! is_array( $result['body']['models'] ) ) {
			return array();
		}

		$ids = array();
		foreach ( $result['body']['models'] as $model ) {
			if ( is_array( $model ) && isset( $model['name'] ) && is_string( $model['name'] ) ) {
				$ids[] = preg_replace( '#^models/#', '', $model['name'] );
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
	 * Build the generateContent request body: contents[], optional
	 * system_instruction, and an optional generationConfig.
	 *
	 * @param array $messages Ordered array of array( 'role' => ..., 'content' => ... ).
	 * @param array $options  max_tokens, temperature, json_mode.
	 * @return array
	 */
	private function build_request_body( $messages, $options ) {
		$contents          = array();
		$system_paragraphs = array();

		foreach ( $messages as $message ) {
			$role    = isset( $message['role'] ) ? (string) $message['role'] : '';
			$content = isset( $message['content'] ) ? (string) $message['content'] : '';

			if ( 'system' === $role ) {
				$system_paragraphs[] = $content;
				continue;
			}

			$contents[] = array(
				'role'  => 'assistant' === $role ? 'model' : $role,
				'parts' => array( array( 'text' => $content ) ),
			);
		}

		$body = array( 'contents' => $contents );

		if ( ! empty( $system_paragraphs ) ) {
			$body['system_instruction'] = array(
				'parts' => array( array( 'text' => implode( "\n\n", $system_paragraphs ) ) ),
			);
		}

		$generation_config = array();

		if ( isset( $options['max_tokens'] ) ) {
			$generation_config['maxOutputTokens'] = $options['max_tokens'];
		}

		if ( isset( $options['temperature'] ) ) {
			$generation_config['temperature'] = $options['temperature'];
		}

		if ( ! empty( $options['json_mode'] ) ) {
			$generation_config['response_mime_type'] = 'application/json';
		}

		if ( ! empty( $generation_config ) ) {
			$body['generationConfig'] = $generation_config;
		}

		return $body;
	}

	/**
	 * Build the base endpoint URL (no auth) for a given path segment, e.g.
	 * 'models/gemini-1.5-pro:generateContent' or 'models'.
	 *
	 * @param string $path Path segment appended after the base_url, no leading slash.
	 * @return string
	 */
	private function endpoint_base( $path ) {
		return rtrim( (string) $this->config( 'base_url', '' ), '/' ) . '/' . $path;
	}

	/**
	 * Build the full generateContent endpoint (with auth) for the configured
	 * model and action, e.g. '.../models/gemini-1.5-pro:generateContent?key=...'.
	 *
	 * @param string $action API action suffixed after ':', e.g. 'generateContent'.
	 * @return string
	 */
	private function endpoint( $action ) {
		$model = rawurlencode( (string) $this->config( 'model', '' ) );

		return $this->endpoint_base( 'models/' . $model ) . ':' . $action;
	}

	/**
	 * The header Gemini accepts the key in.
	 *
	 * Gemini takes the key either as a `key` query parameter or as this
	 * header, and this adapter used the query string. A URL is the part of a
	 * request that gets written down: proxies, load balancers and access logs
	 * record it as a matter of course, so the key ended up in plain text in
	 * files nobody thinks of as holding secrets, on machines between here and
	 * Google. A header is not logged that way. The image route already made
	 * this choice and said why; the text route now agrees with it.
	 *
	 * @return array
	 */
	private function auth_headers() {
		return array( 'x-goog-api-key' => (string) $this->config( 'api_key', '' ) );
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
				__( 'HTTP %1$d: %2$s', 'dicecodes-ai-blog-writer' ),
				$code,
				$this->explain( $message )
			);
		}

		return $this->explain( $message );
	}
}
