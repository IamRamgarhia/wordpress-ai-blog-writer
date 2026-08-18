<?php
/**
 * HTTP transport for outbound provider requests.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around wp_remote_* with explicit timeouts, bounded retry, and
 * JSON handling.
 *
 * WordPress's wp_remote_post() defaults to a 5-second timeout, which is too
 * short for real LLM generations; every call here sets one explicitly. Retries are limited to
 * 429, 5xx, and WP_Error — other 4xx responses are caller errors and retrying
 * them would just burn the caller's API quota. API keys travel only in request
 * headers and are never written into a log entry or a returned error string.
 */
class Blogcraft_Http {

	/**
	 * Maximum number of attempts (initial try plus retries) for one request.
	 *
	 * @var int
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * POST a JSON body and decode a JSON response.
	 *
	 * @param string $url     Target URL.
	 * @param array  $body    Payload, JSON-encoded before sending.
	 * @param array  $headers Additional headers merged over the JSON content type.
	 * @param int    $timeout Timeout in seconds.
	 * @return array array( 'code' => int, 'body' => array, 'error' => string ).
	 */
	public static function post_json( $url, $body, $headers = array(), $timeout = 60 ) {
		$args = array(
			'method'  => 'POST',
			'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
			'body'    => wp_json_encode( $body ),
			'timeout' => $timeout,
		);

		return self::request( $url, $args );
	}

	/**
	 * GET and decode a JSON response.
	 *
	 * @param string $url     Target URL.
	 * @param array  $headers Request headers.
	 * @param int    $timeout Timeout in seconds.
	 * @return array array( 'code' => int, 'body' => array, 'error' => string ).
	 */
	public static function get_json( $url, $headers = array(), $timeout = 30 ) {
		$args = array(
			'method'  => 'GET',
			'headers' => $headers,
			'timeout' => $timeout,
		);

		return self::request( $url, $args );
	}

	/**
	 * Run a request with bounded retry on transient failures.
	 *
	 * @param string $url  Target URL.
	 * @param array  $args wp_remote_request() args, already carrying an explicit timeout.
	 * @return array array( 'code' => int, 'body' => array, 'error' => string ).
	 */
	private static function request( $url, $args ) {
		$code  = 0;
		$error = '';

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				$code  = 0;
				$error = $response->get_error_message();

				Blogcraft_Logger::error(
					'HTTP request failed',
					array(
						'url'  => $url,
						'code' => $code,
					)
				);

				if ( $attempt >= self::MAX_ATTEMPTS ) {
					break;
				}

				self::wait_before_retry( array(), $attempt );
				continue;
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$body_raw = wp_remote_retrieve_body( $response );
			$decoded  = json_decode( $body_raw, true );
			$body_ok  = ( null !== $decoded || 'null' === trim( (string) $body_raw ) );
			$body     = $body_ok && is_array( $decoded ) ? $decoded : array();

			if ( $code >= 200 && $code < 300 ) {
				if ( ! $body_ok ) {
					return array(
						'code'  => $code,
						'body'  => array(),
						'error' => __( 'Invalid JSON response.', 'blogcraft' ),
					);
				}

				return array(
					'code'  => $code,
					'body'  => $body,
					'error' => '',
				);
			}

			$error = sprintf(
				/* translators: %d: HTTP status code. */
				__( 'Request failed with HTTP %d.', 'blogcraft' ),
				$code
			);

			Blogcraft_Logger::error(
				'HTTP request failed',
				array(
					'url'  => $url,
					'code' => $code,
				)
			);

			$retryable = ( 429 === $code || $code >= 500 );

			if ( ! $retryable || $attempt >= self::MAX_ATTEMPTS ) {
				return array(
					'code'  => $code,
					'body'  => $body,
					'error' => $error,
				);
			}

			self::wait_before_retry( wp_remote_retrieve_headers( $response ), $attempt );
		}

		return array(
			'code'  => $code,
			'body'  => array(),
			'error' => $error,
		);
	}

	/**
	 * Pause between attempts, honouring a numeric Retry-After header.
	 *
	 * @param array|object $headers Response headers, or empty when unavailable.
	 * @param int          $attempt Attempt number just completed (1-indexed).
	 * @return void
	 */
	private static function wait_before_retry( $headers, $attempt ) {
		$retry_after = is_array( $headers ) || is_object( $headers )
			? self::header_value( $headers, 'retry-after' )
			: '';

		if ( is_numeric( $retry_after ) ) {
			$seconds = max( 0, (int) $retry_after );
		} else {
			$seconds = $attempt; // 1s after the first attempt, 2s after the second.
		}

		if ( $seconds > 0 ) {
			sleep( $seconds );
		}
	}

	/**
	 * Read a header from a headers array or Requests case-insensitive dictionary.
	 *
	 * @param array|object $headers Response headers.
	 * @param string       $name    Header name, lower-case.
	 * @return string
	 */
	private static function header_value( $headers, $name ) {
		if ( is_array( $headers ) && array_key_exists( $name, $headers ) ) {
			return (string) $headers[ $name ];
		}

		if ( is_object( $headers ) && isset( $headers[ $name ] ) ) {
			return (string) $headers[ $name ];
		}

		return '';
	}
}
