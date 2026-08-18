<?php
/**
 * Provider settings screen and connection test.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the provider settings form and runs the connection test.
 *
 * Keys are never rendered in plaintext. The key field shows a mask and an empty
 * submission means "leave unchanged" — without that, saving any unrelated field
 * would wipe the stored key, since the form cannot echo the real value back.
 */
class Blogcraft_Connection {

	/**
	 * Settings submenu slug.
	 */
	const PAGE_SLUG = 'blogcraft-settings';

	/**
	 * Nonce action for saving.
	 */
	const SAVE_ACTION = 'blogcraft_save_settings';

	/**
	 * Nonce action for the connection test.
	 */
	const TEST_ACTION = 'blogcraft_test_connection';

	/**
	 * Transient prefix holding the last result for one user.
	 */
	const RESULT_TRANSIENT = 'blogcraft_test_result_';

	/**
	 * Wire hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 20 );
		add_action( 'admin_post_blogcraft_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_blogcraft_test_connection', array( __CLASS__, 'handle_test' ) );
	}

	/**
	 * Add the Settings submenu.
	 *
	 * @return void
	 */
	public static function register_menu() {
		add_submenu_page(
			Blogcraft_Admin::MENU_SLUG,
			__( 'Blogcraft Settings', 'blogcraft' ),
			__( 'Settings', 'blogcraft' ),
			Blogcraft_Capabilities::MANAGE,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Fields shown for every provider type.
	 *
	 * @return array
	 */
	private static function common_fields() {
		return array(
			'provider_base_url' => __( 'Base URL', 'blogcraft' ),
			'provider_model'    => __( 'Model', 'blogcraft' ),
		);
	}

	/**
	 * Extra fields meaningful only to the custom provider.
	 *
	 * @return array
	 */
	private static function custom_fields() {
		return array(
			'provider_auth_header'            => __( 'Auth header name', 'blogcraft' ),
			'provider_auth_prefix'            => __( 'Auth value prefix', 'blogcraft' ),
			'provider_text_path'              => __( 'Response text path', 'blogcraft' ),
			'provider_prompt_tokens_path'     => __( 'Prompt tokens path', 'blogcraft' ),
			'provider_completion_tokens_path' => __( 'Completion tokens path', 'blogcraft' ),
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'blogcraft' ) );
		}

		$type   = (string) Blogcraft_Settings::get( 'provider_type' );
		$key    = (string) Blogcraft_Settings::get( 'provider_api_key' );
		$result = get_transient( self::RESULT_TRANSIENT . get_current_user_id() );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Blogcraft Settings', 'blogcraft' ) . '</h1>';

		if ( is_array( $result ) ) {
			delete_transient( self::RESULT_TRANSIENT . get_current_user_id() );
			printf(
				'<div class="notice %s"><p>%s</p></div>',
				esc_attr( empty( $result['ok'] ) ? 'notice-error' : 'notice-success' ),
				esc_html( (string) $result['message'] )
			);
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_save_settings" />';
		Blogcraft_Request::nonce_field( self::SAVE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="blogcraft_provider_type">' . esc_html__( 'Provider', 'blogcraft' ) . '</label></th><td>';
		echo '<select name="provider_type" id="blogcraft_provider_type">';
		foreach ( Blogcraft_Provider_Registry::types() as $id => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $id ),
				selected( $type, $id, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		foreach ( self::common_fields() as $name => $label ) {
			self::text_row( $name, $label );
		}

		echo '<tr><th scope="row"><label for="blogcraft_provider_api_key">' . esc_html__( 'API key', 'blogcraft' ) . '</label></th><td>';
		printf(
			'<input type="password" class="regular-text" name="provider_api_key" id="blogcraft_provider_api_key" value="" autocomplete="off" placeholder="%s" />',
			esc_attr( '' === $key ? __( 'Not set', 'blogcraft' ) : Blogcraft_Crypto::mask( $key ) )
		);
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved key.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';

		self::number_row( 'monthly_token_cap', __( 'Monthly token cap (0 = unlimited)', 'blogcraft' ) );

		foreach ( self::custom_fields() as $name => $label ) {
			self::text_row( $name, $label );
		}

		echo '<tr><th scope="row"><label for="blogcraft_provider_request_template">' . esc_html__( 'Request template (JSON)', 'blogcraft' ) . '</label></th><td>';
		printf(
			'<textarea name="provider_request_template" id="blogcraft_provider_request_template" rows="6" class="large-text code">%s</textarea>',
			esc_textarea( (string) Blogcraft_Settings::get( 'provider_request_template' ) )
		);
		echo '<p class="description">' . esc_html__( 'Custom provider only. Use {{prompt}} and {{model}} as placeholders.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'blogcraft' ) );
		echo '</form>';

		echo '<hr />';
		echo '<h2>' . esc_html__( 'Test connection', 'blogcraft' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sends one very short request to confirm the provider is reachable.', 'blogcraft' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_test_connection" />';
		Blogcraft_Request::nonce_field( self::TEST_ACTION );
		submit_button( __( 'Test connection', 'blogcraft' ), 'secondary' );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Render one text input row.
	 *
	 * @param string $name  Setting key.
	 * @param string $label Field label.
	 * @return void
	 */
	private static function text_row( $name, $label ) {
		printf(
			'<tr><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><input type="text" class="regular-text" name="%1$s" id="blogcraft_%1$s" value="%3$s" /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) Blogcraft_Settings::get( $name ) )
		);
	}

	/**
	 * Render one number input row.
	 *
	 * @param string $name  Setting key.
	 * @param string $label Field label.
	 * @return void
	 */
	private static function number_row( $name, $label ) {
		printf(
			'<tr><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><input type="number" min="0" class="small-text" name="%1$s" id="blogcraft_%1$s" value="%3$s" /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) Blogcraft_Settings::get( $name ) )
		);
	}

	/**
	 * Persist submitted settings.
	 *
	 * @return void
	 */
	public static function handle_save() {
		// The nonce is read here and verified on the next line by Blogcraft_Request, which PHPCS cannot follow statically.
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::SAVE_ACTION, $nonce );

		$plain = array_merge(
			array_keys( self::common_fields() ),
			array_keys( self::custom_fields() ),
			array( 'provider_type', 'provider_request_template' )
		);

		foreach ( $plain as $key ) {
			if ( isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				// Blogcraft_Settings::set() sanitises per the schema type for this key.
				Blogcraft_Settings::set( $key, wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
			}
		}

		if ( isset( $_POST['monthly_token_cap'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			Blogcraft_Settings::set( 'monthly_token_cap', (int) $_POST['monthly_token_cap'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// An empty key field means "leave unchanged": the form renders a mask rather
		// than the real value, so treating blank as "clear" would wipe the stored key
		// every time an unrelated field was saved.
		$submitted_key = isset( $_POST['provider_api_key'] ) ? trim( (string) wp_unslash( $_POST['provider_api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
		if ( '' !== $submitted_key ) {
			Blogcraft_Settings::set( 'provider_api_key', $submitted_key );
		}

		self::redirect_back( true, __( 'Settings saved.', 'blogcraft' ) );
	}

	/**
	 * Run a live connection test.
	 *
	 * @return void
	 */
	public static function handle_test() {
		// The nonce is read here and verified on the next line by Blogcraft_Request, which PHPCS cannot follow statically.
		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		Blogcraft_Request::verify_or_die( self::TEST_ACTION, $nonce );

		$provider = Blogcraft_Provider_Registry::from_settings();

		if ( null === $provider ) {
			self::redirect_back( false, __( 'No provider is configured yet.', 'blogcraft' ) );
		}

		$probe = Blogcraft_Provider_Registry::probe( $provider );

		if ( empty( $probe['reachable'] ) ) {
			self::redirect_back(
				false,
				sprintf(
					/* translators: %s: error reported by the provider. */
					__( 'Connection failed: %s', 'blogcraft' ),
					(string) $probe['error']
				)
			);
		}

		$models = isset( $probe['models'] ) ? (array) $probe['models'] : array();

		self::redirect_back(
			true,
			sprintf(
				/* translators: %d: number of models the provider reported. */
				_n(
					'Connection succeeded. %d model available.',
					'Connection succeeded. %d models available.',
					count( $models ),
					'blogcraft'
				),
				count( $models )
			)
		);
	}

	/**
	 * Store a one-shot result and return to the settings screen.
	 *
	 * @param bool   $ok      Whether the action succeeded.
	 * @param string $message Message to show.
	 * @return void
	 */
	private static function redirect_back( $ok, $message ) {
		set_transient(
			self::RESULT_TRANSIENT . get_current_user_id(),
			array(
				'ok'      => (bool) $ok,
				'message' => (string) $message,
			),
			60
		);

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}
}
