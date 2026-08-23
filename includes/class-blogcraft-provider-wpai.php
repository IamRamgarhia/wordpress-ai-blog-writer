<?php
/**
 * WordPress's own AI Client, when the site has one.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends prompts through WordPress rather than to a provider directly.
 *
 * WordPress 7.0 added wp_ai_client_prompt(): the site owner configures a
 * provider once, through WordPress, and every plugin uses it without asking
 * for its own key. Where that exists it is plainly the better path — nothing
 * to sign up for, nothing to paste, one bill.
 *
 * It does not replace the direct adapters, for three reasons. The plugin
 * supports WordPress 6.0, where none of this exists. The AI Client relies on
 * separate provider plugins, so a site can be on 7.0 and still have nothing
 * configured. And Blogcraft offers fourteen providers by name including local
 * models, which is the whole point of a bring-your-own-key tool; routing
 * everything through an abstraction that may not carry them would take that
 * away. So this is one more option in the same list, offered only when the
 * function is really there.
 *
 * Written defensively throughout. Every optional method is checked for before
 * it is called, because this adapter has to keep working across versions of an
 * interface this plugin does not control.
 */
class Blogcraft_Provider_Wpai extends Blogcraft_Provider {

	/**
	 * Whether this WordPress has an AI Client at all.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'wp_ai_client_prompt' );
	}

	/**
	 * Whether it is available and something is actually configured behind it.
	 *
	 * Availability and readiness are different questions: WordPress ships the
	 * client without any provider, so a site can have the function and no way
	 * to answer a prompt.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		if ( ! self::is_available() ) {
			return false;
		}

		try {
			$builder = wp_ai_client_prompt( 'ping' );

			if ( ! is_object( $builder ) || ! method_exists( $builder, 'is_supported_for_text_generation' ) ) {
				return false;
			}

			return (bool) $builder->is_supported_for_text_generation();
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * Machine id.
	 *
	 * @return string
	 */
	public function id() {
		return 'wpai';
	}

	/**
	 * Human label.
	 *
	 * @return string
	 */
	public function label() {
		return __( 'WordPress AI Client', 'blogcraft' );
	}

	/**
	 * Generate a completion.
	 *
	 * @param array $messages Ordered array of array( 'role' => ..., 'content' => ... ).
	 * @param array $options  Provider options: max_tokens, temperature, model.
	 * @return Blogcraft_Provider_Response Never throws.
	 */
	public function complete( $messages, $options = array() ) {
		$response = new Blogcraft_Provider_Response();

		if ( ! self::is_available() ) {
			$response->error = __( 'This WordPress has no AI Client. Choose a provider and enter a key instead.', 'blogcraft' );

			return $response;
		}

		$system = '';
		$user   = array();

		foreach ( (array) $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['content'] ) ) {
				continue;
			}

			if ( isset( $message['role'] ) && 'system' === $message['role'] ) {
				$system .= ( '' === $system ? '' : "\n\n" ) . (string) $message['content'];
				continue;
			}

			$user[] = (string) $message['content'];
		}

		try {
			$builder = wp_ai_client_prompt( implode( "\n\n", $user ) );

			if ( '' !== $system && method_exists( $builder, 'using_system_instruction' ) ) {
				$builder = $builder->using_system_instruction( $system );
			}

			$model = trim( (string) $this->config( 'model', '' ) );

			if ( '' !== $model && method_exists( $builder, 'using_model_preference' ) ) {
				$builder = $builder->using_model_preference( $model );
			}

			if ( isset( $options['temperature'] ) && method_exists( $builder, 'using_temperature' ) ) {
				$builder = $builder->using_temperature( (float) $options['temperature'] );
			}

			// Deliberately not passed unless asked for. A cap on output starves
			// a reasoning model, whose thinking comes out of the same
			// allowance, and that is what broke drafting here once already.
			if ( ! empty( $options['max_tokens'] ) && method_exists( $builder, 'using_max_tokens' ) ) {
				$builder = $builder->using_max_tokens( (int) $options['max_tokens'] );
			}

			$text = $builder->generate_text();
		} catch ( Throwable $e ) {
			$response->error = __( 'The WordPress AI Client could not answer.', 'blogcraft' );

			return $response;
		}

		if ( is_wp_error( $text ) ) {
			// A WP_Error message is written for a person and carries no
			// credentials, so it is safe to show as it stands.
			$response->error = $text->get_error_message();

			return $response;
		}

		$response->text  = is_string( $text ) ? $text : '';
		$response->model = ( '' === $model ) ? 'WordPress' : $model;

		if ( '' === trim( $response->text ) ) {
			$response->error = __( 'The WordPress AI Client returned nothing.', 'blogcraft' );
		}

		// Token counts are the host's to know, not ours. Reporting a guess
		// would make the monthly cap lie, so nothing is recorded and the cap
		// simply does not apply to this route.
		return $response;
	}

	/**
	 * Models on offer.
	 *
	 * The AI Client chooses for itself from a preference, so there is no list
	 * to show and inventing one would be worse than showing none.
	 *
	 * @return array
	 */
	public function list_models() {
		return array();
	}

	/**
	 * What this route can do.
	 *
	 * @return array
	 */
	public function capabilities() {
		return array(
			'text'      => self::is_ready(),
			'json_mode' => false,
		);
	}
}
