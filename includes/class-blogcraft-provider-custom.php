<?php
/**
 * Custom JSON-path provider adapter.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Talks to an arbitrary JSON API described entirely by configuration.
 *
 * The escape hatch for providers with no dedicated adapter: the user
 * supplies a request template (JSON with {{prompt}}/{{model}} placeholders)
 * and dot paths locating the reply text and token counts in the response
 * body. Config keys: endpoint, api_key, model, auth_header (default
 * 'Authorization'), auth_prefix (default 'Bearer '), request_template,
 * text_path, prompt_tokens_path, completion_tokens_path.
 */
class Blogcraft_Provider_Custom extends Blogcraft_Provider {

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'custom';
	}

	/**
	 * Human label for the UI.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'Custom endpoint', 'blogcraft' );
	}

	/**
	 * Generate a completion.
	 *
	 * @param array $messages Ordered array of array( 'role' => ..., 'content' => ... ).
	 * @param array $options  Unused; a custom endpoint has no standard shape for these.
	 * @return Blogcraft_Provider_Response Never throws.
	 */
	public function complete( $messages, $options = array() ) {
		$response = new Blogcraft_Provider_Response();

		$template = (string) $this->config( 'request_template', '' );
		$decoded  = json_decode( $template, true );

		// Substituting into the decoded structure (rather than the raw JSON
		// string) is deliberate: a prompt containing a quote, newline, or
		// backslash would otherwise corrupt the JSON. Decoding first and
		// replacing only inside string values makes any prompt content safe
		// by construction, because Blogcraft_Http::post_json() re-encodes the
		// resulting array correctly.
		if ( ! is_array( $decoded ) ) {
			$response->error = __( 'Custom endpoint request template is not valid JSON.', 'blogcraft' );
			return $response;
		}

		$model = (string) $this->config( 'model', '' );
		$body  = $this->substitute( $decoded, $this->build_prompt( $messages ), $model );

		$result = Blogcraft_Http::post_json( (string) $this->config( 'endpoint', '' ), $body, $this->headers() );

		$api_error = $this->extract_error_message( $result['body'] );
		if ( '' !== $api_error ) {
			$response->error = $this->format_api_error( $api_error, $result['code'] );
			return $response;
		}

		if ( '' !== $result['error'] ) {
			$response->error = $result['error'];
			return $response;
		}

		$text = self::dig( $result['body'], (string) $this->config( 'text_path', '' ) );

		if ( ! is_string( $text ) || '' === $text ) {
			$response->error = __( 'Unexpected response shape from provider.', 'blogcraft' );
			return $response;
		}

		$response->text              = $text;
		$response->model             = $model;
		$response->prompt_tokens     = $this->dig_int( $result['body'], (string) $this->config( 'prompt_tokens_path', '' ) );
		$response->completion_tokens = $this->dig_int( $result['body'], (string) $this->config( 'completion_tokens_path', '' ) );

		return $response;
	}

	/**
	 * Model ids this provider offers.
	 *
	 * A custom endpoint has no standard discovery mechanism, so this always
	 * returns an empty list rather than guessing at one.
	 *
	 * @return array
	 */
	public function list_models() {
		return array();
	}

	/**
	 * Walk a dot-delimited path into a nested array and return the value
	 * found there, or null when any segment is missing.
	 *
	 * Numeric segments index into arrays, e.g. 'choices.0.message.content'.
	 * Never warns or errors on a missing path, a non-array intermediate
	 * value, or an empty path — all of those simply resolve to null.
	 *
	 * @param array  $data Decoded response body (or any nested array).
	 * @param string $path Dot-delimited path, e.g. 'choices.0.message.content'.
	 * @return mixed Null when the path cannot be resolved.
	 */
	public static function dig( array $data, string $path ) {
		if ( '' === $path ) {
			return null;
		}

		$current = $data;

		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $current ) || ! array_key_exists( $segment, $current ) ) {
				return null;
			}

			$current = $current[ $segment ];
		}

		return $current;
	}

	/**
	 * Resolve a dot path to an int, defaulting to 0 when the path is absent
	 * or the value found there is not numeric.
	 *
	 * @param array  $body Decoded response body.
	 * @param string $path Dot-delimited path, possibly empty.
	 * @return int
	 */
	private function dig_int( $body, $path ) {
		if ( '' === $path ) {
			return 0;
		}

		$value = self::dig( $body, $path );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Flatten the ordered message list into a single string, since a custom
	 * endpoint has no standard multi-message format: each message becomes
	 * "role: content", joined by blank lines.
	 *
	 * @param array $messages Ordered array of array( 'role' => ..., 'content' => ... ).
	 * @return string
	 */
	private function build_prompt( $messages ) {
		$lines = array();

		foreach ( (array) $messages as $message ) {
			$role    = isset( $message['role'] ) ? (string) $message['role'] : '';
			$content = isset( $message['content'] ) ? (string) $message['content'] : '';
			$lines[] = $role . ': ' . $content;
		}

		return implode( "\n\n", $lines );
	}

	/**
	 * Recursively replace {{prompt}} and {{model}} inside every string value
	 * of a decoded structure. Non-string values (numbers, bools, null,
	 * nested arrays) are walked or passed through untouched.
	 *
	 * @param mixed  $value  Decoded template value (array, string, or scalar).
	 * @param string $prompt Flattened prompt text.
	 * @param string $model  Configured model id.
	 * @return mixed
	 */
	private function substitute( $value, $prompt, $model ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ $key ] = $this->substitute( $item, $prompt, $model );
			}
			return $out;
		}

		if ( is_string( $value ) ) {
			return str_replace( array( '{{prompt}}', '{{model}}' ), array( $prompt, $model ), $value );
		}

		return $value;
	}

	/**
	 * Build request headers, including auth when an api_key is configured.
	 *
	 * The auth header is omitted entirely when no api_key is set, matching
	 * the other adapters — a local endpoint may need no auth at all.
	 *
	 * @return array
	 */
	private function headers() {
		$headers = array();

		$api_key = (string) $this->config( 'api_key', '' );

		if ( '' !== $api_key ) {
			$header_name             = (string) $this->config( 'auth_header', 'Authorization' );
			$prefix                  = (string) $this->config( 'auth_prefix', 'Bearer ' );
			$headers[ $header_name ] = $prefix . $api_key;
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
