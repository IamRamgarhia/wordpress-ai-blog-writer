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
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_blogcraft_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_blogcraft_test_connection', array( __CLASS__, 'handle_test' ) );
	}

	/**
	 * Load styling and behaviour on Blogcraft screens only.
	 *
	 * Everything is served from the plugin directory: Guideline 8 forbids CDN
	 * requests, so there are no web fonts and no external assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, Blogcraft_Admin::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'blogcraft-admin',
			BLOGCRAFT_URL . 'assets/admin.css',
			array(),
			BLOGCRAFT_VERSION
		);

		wp_enqueue_script(
			'blogcraft-admin',
			BLOGCRAFT_URL . 'assets/admin.js',
			array(),
			BLOGCRAFT_VERSION,
			true
		);
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
	 * Short voice fields.
	 *
	 * @return array
	 */
	private static function voice_text_fields() {
		return array(
			'voice_tone'          => __( 'Tone', 'blogcraft' ),
			'voice_point_of_view' => __( 'Point of view', 'blogcraft' ),
			'voice_reading_level' => __( 'Reading level', 'blogcraft' ),
		);
	}

	/**
	 * Long-form voice fields, with the hint shown beneath each.
	 *
	 * @return array
	 */
	private static function voice_area_fields() {
		return array(
			'voice_niche'         => array( __( 'What this blog is about', 'blogcraft' ), __( 'One or two sentences. The more specific, the less generic the writing.', 'blogcraft' ) ),
			'voice_audience'      => array( __( 'Who you write for', 'blogcraft' ), __( 'Who is reading, and what they already know.', 'blogcraft' ) ),
			'voice_style_rules'   => array( __( 'Style rules', 'blogcraft' ), __( 'One per line. For example: no em dashes. Short paragraphs. Never open with a question.', 'blogcraft' ) ),
			'voice_banned_words'  => array( __( 'Extra banned words', 'blogcraft' ), __( 'One per line. A list of common AI tells is already blocked by default.', 'blogcraft' ) ),
			'voice_banned_topics' => array( __( 'Never write about', 'blogcraft' ), __( 'One per line. Competitors, off-limits claims, anything legally sensitive.', 'blogcraft' ) ),
			'voice_experience'    => array( __( 'Your own experience', 'blogcraft' ), __( 'Anecdotes, opinions or data only you have. This is what AI writing structurally lacks.', 'blogcraft' ) ),
		);
	}

	/**
	 * Boolean feature toggles.
	 *
	 * @return array
	 */
	private static function toggle_fields() {
		return array(
			'images_enabled'          => __( 'Generate a featured image', 'blogcraft' ),
			'internal_links_enabled'  => __( 'Add links to your existing posts', 'blogcraft' ),
			'verify_links_enabled'    => __( 'Check that links resolve before publishing', 'blogcraft' ),
			'backlinks_enabled'       => __( 'Link older posts to each new one', 'blogcraft' ),
			'duplicate_check_enabled' => __( 'Refuse topics too similar to existing posts', 'blogcraft' ),
			'autopilot_enabled'       => __( 'Write posts automatically on a schedule', 'blogcraft' ),
			'refresh_enabled'         => __( 'Rewrite older posts when nothing new is queued', 'blogcraft' ),
		);
	}

	/**
	 * Render one checkbox row.
	 *
	 * @param string $name  Setting key.
	 * @param string $label Field label.
	 * @return void
	 */
	private static function checkbox_row( $name, $label ) {
		// The label belongs beside the checkbox, not also in the header cell —
		// printing it in both renders the text twice.
		printf(
			'<tr class="blogcraft-toggle-row"><th scope="row"></th><td><label for="blogcraft_%1$s"><input type="checkbox" name="%1$s" id="blogcraft_%1$s" value="1"%3$s /> %2$s</label></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			checked( (bool) Blogcraft_Settings::get( $name ), true, false )
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

		echo '<div class="wrap blogcraft-page">';
		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Blogcraft Settings', 'blogcraft' ) . '</h1>';
		echo '<p>' . esc_html__( 'Set it up once. Everything here shapes every post it writes.', 'blogcraft' ) . '</p>';
		echo '</div>';

		self::render_status();

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

		self::open_card( '01', __( 'Connect a provider', 'blogcraft' ), __( 'Your key, your account, your bill. Nothing is sent to us.', 'blogcraft' ) );
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
			'<input type="password" class="regular-text" name="provider_api_key" id="blogcraft_provider_api_key" value="" autocomplete="new-password" placeholder="%s" />',
			esc_attr( '' === $key ? __( 'Not set', 'blogcraft' ) : Blogcraft_Crypto::mask( $key ) )
		);
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved key.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';

		self::number_row( 'monthly_token_cap', __( 'Monthly token cap', 'blogcraft' ), __( 'Stops generation once this many tokens are used in a month. Zero means no limit.', 'blogcraft' ) );

		foreach ( self::custom_fields() as $name => $label ) {
			self::text_row( $name, $label, 'blogcraft-custom-only' );
		}

		echo '<tr class="blogcraft-custom-only"><th scope="row"><label for="blogcraft_provider_request_template">' . esc_html__( 'Request template (JSON)', 'blogcraft' ) . '</label></th><td>';
		printf(
			'<textarea name="provider_request_template" id="blogcraft_provider_request_template" rows="6" class="large-text code">%s</textarea>',
			esc_textarea( (string) Blogcraft_Settings::get( 'provider_request_template' ) )
		);
		echo '<p class="description">' . esc_html__( 'Custom provider only. Use {{prompt}} and {{model}} as placeholders.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		self::close_card();

		self::open_card(
			'02',
			__( 'Research', 'blogcraft' ),
			__( 'Optional but it is the biggest lever on quality. Without sources the model writes from memory, which is what search engines discount. With none configured it falls back to your own posts.', 'blogcraft' )
		);
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="blogcraft_research_provider">' . esc_html__( 'Search provider', 'blogcraft' ) . '</label></th><td>';
		echo '<select name="research_provider" id="blogcraft_research_provider">';
		foreach ( Blogcraft_Research::providers() as $id => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $id ),
				selected( (string) Blogcraft_Settings::get( 'research_provider' ), $id, false ),
				esc_html( $label )
			);
		}
		echo '</select></td></tr>';

		self::text_row( 'research_base_url', __( 'SearXNG URL', 'blogcraft' ) );

		echo '<tr><th scope="row"><label for="blogcraft_research_api_key">' . esc_html__( 'Search API key', 'blogcraft' ) . '</label></th><td>';
		$research_key = (string) Blogcraft_Settings::get( 'research_api_key' );
		printf(
			'<input type="password" class="regular-text" name="research_api_key" id="blogcraft_research_api_key" value="" autocomplete="new-password" placeholder="%s" />',
			esc_attr( '' === $research_key ? __( 'Not set', 'blogcraft' ) : Blogcraft_Crypto::mask( $research_key ) )
		);
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved key.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';

		self::textarea_row(
			'research_urls',
			__( 'Always read these URLs', 'blogcraft' ),
			__( 'One per line. Read for every post, whether or not a search provider is set.', 'blogcraft' )
		);

		echo '</tbody></table>';
		self::close_card();

		self::open_card( '03', __( 'Describe your voice', 'blogcraft' ), __( 'Sent with every request, so posts sound like your site instead of a template. The more specific, the less generic the writing.', 'blogcraft' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( self::voice_area_fields() as $name => $meta ) {
			self::textarea_row( $name, $meta[0], $meta[1] );
		}

		foreach ( self::voice_text_fields() as $name => $label ) {
			self::text_row( $name, $label );
		}

		echo '</tbody></table>';

		self::close_card();
		self::open_card( '04', __( 'Automation', 'blogcraft' ), __( 'Optional. Turn these on once the writing looks right to you.', 'blogcraft' ) );
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( self::toggle_fields() as $name => $label ) {
			self::checkbox_row( $name, $label );
		}

		self::textarea_row(
			'autopilot_topics',
			__( 'Topic queue', 'blogcraft' ),
			__( 'One topic per line. Each is used once, then removed from this list.', 'blogcraft' )
		);
		self::number_row(
			'quality_threshold',
			__( 'Hold posts scoring below', 'blogcraft' ),
			__( 'Out of 100. Anything lower is held for review instead of published, whatever you chose above.', 'blogcraft' )
		);
		echo '<tr><th scope="row"><label for="blogcraft_image_provider">' . esc_html__( 'Image source', 'blogcraft' ) . '</label></th><td>';
		echo '<select name="image_provider" id="blogcraft_image_provider">';
		foreach ( Blogcraft_Images::providers() as $id => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $id ),
				selected( (string) Blogcraft_Settings::get( 'image_provider' ), $id, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Whichever you pick, Blogcraft falls back through the others so a post is never left without an image.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';

		self::secret_row( 'pexels_api_key', __( 'Pexels API key', 'blogcraft' ) );
		self::secret_row( 'pixabay_api_key', __( 'Pixabay API key', 'blogcraft' ) );

		self::number_row(
			'refresh_after_days',
			__( 'Consider a post stale after', 'blogcraft' ),
			__( 'Days. Refreshing an existing post is usually worth more than publishing a new one, because the URL keeps whatever history it has earned.', 'blogcraft' )
		);
		self::number_row( 'autopilot_per_day', __( 'Maximum posts per day', 'blogcraft' ), __( 'A low number is safer. Publishing unreviewed posts at volume is what search engines penalise.', 'blogcraft' ) );

		echo '<tr><th scope="row"><label for="blogcraft_autopilot_status">' . esc_html__( 'Automatic posts should be', 'blogcraft' ) . '</label></th><td>';
		echo '<select name="autopilot_status" id="blogcraft_autopilot_status">';
		printf(
			'<option value="draft"%s>%s</option>',
			selected( 'publish' !== Blogcraft_Settings::get( 'autopilot_status' ), true, false ),
			esc_html__( 'Saved as drafts for review', 'blogcraft' )
		);
		printf(
			'<option value="publish"%s>%s</option>',
			selected( 'publish' === Blogcraft_Settings::get( 'autopilot_status' ), true, false ),
			esc_html__( 'Published immediately', 'blogcraft' )
		);
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Publishing unreviewed posts at volume is what search engines penalise. Drafts are safer.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '<div class="blogcraft-actions">';
		submit_button( __( 'Save settings', 'blogcraft' ), 'primary', 'submit', false );
		echo '</div>';
		self::close_card();
		echo '</form>';

		self::open_card( '05', __( 'Check it works', 'blogcraft' ), __( 'Sends one very short request and reports what the provider says back.', 'blogcraft' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_test_connection" />';
		Blogcraft_Request::nonce_field( self::TEST_ACTION );
		echo '<div class="blogcraft-actions">';
		submit_button( __( 'Test connection', 'blogcraft' ), 'secondary', 'submit', false );
		echo '<p class="blogcraft-hint">' . esc_html__( 'Save your settings first.', 'blogcraft' ) . '</p>';
		echo '</div>';
		echo '</form>';
		self::close_card();
		echo '</div>';
	}

	/**
	 * Open a numbered card.
	 *
	 * The numbering is not decoration: setup genuinely runs in this order, since
	 * nothing generates without a provider and automation only makes sense once
	 * the writing looks right.
	 *
	 * @param string $step        Step number.
	 * @param string $title       Card title.
	 * @param string $description One line on what the card is for.
	 * @return void
	 */
	private static function open_card( $step, $title, $description ) {
		printf(
			'<section class="blogcraft-card"><header><span class="blogcraft-step">%1$s</span><h2>%2$s</h2><p>%3$s</p></header>',
			esc_html( $step ),
			esc_html( $title ),
			esc_html( $description )
		);
	}

	/**
	 * Close the current card.
	 *
	 * @return void
	 */
	private static function close_card() {
		echo '</section>';
	}

	/**
	 * Show what is still missing, read from the real settings.
	 *
	 * @return void
	 */
	private static function render_status() {
		$states = array(
			__( 'Provider connected', 'blogcraft' ) => Blogcraft_Provider_Registry::is_configured(),
			__( 'Voice described', 'blogcraft' )    => Blogcraft_Voice::is_configured(),
			__( 'Automation on', 'blogcraft' )      => (bool) Blogcraft_Settings::get( 'autopilot_enabled' ),
		);

		echo '<ul class="blogcraft-status">';

		foreach ( $states as $label => $done ) {
			printf(
				'<li class="%1$s">%2$s</li>',
				$done ? 'is-done' : '',
				esc_html( $label )
			);
		}

		echo '</ul>';
	}

	/**
	 * Render one text input row.
	 *
	 * @param string $name      Setting key.
	 * @param string $label     Field label.
	 * @param string $row_class Optional class for the row.
	 * @return void
	 */
	private static function text_row( $name, $label, $row_class = '' ) {
		printf(
			'<tr class="%4$s"><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><input type="text" class="regular-text" name="%1$s" id="blogcraft_%1$s" value="%3$s" autocomplete="off" spellcheck="false" /></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) Blogcraft_Settings::get( $name ) ),
			esc_attr( $row_class )
		);
	}

	/**
	 * Render one textarea row with a description.
	 *
	 * @param string $name        Setting key.
	 * @param string $label       Field label.
	 * @param string $description Hint shown beneath.
	 * @return void
	 */
	private static function textarea_row( $name, $label, $description = '' ) {
		printf(
			'<tr><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><textarea name="%1$s" id="blogcraft_%1$s" rows="3" class="large-text">%3$s</textarea><p class="description">%4$s</p></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_textarea( (string) Blogcraft_Settings::get( $name ) ),
			esc_html( $description )
		);
	}

	/**
	 * Render one masked secret row.
	 *
	 * An empty submission always means "leave unchanged", because the field can
	 * only ever render a mask and treating blank as "clear" would wipe the value
	 * every time an unrelated field was saved.
	 *
	 * @param string $name  Setting key.
	 * @param string $label Field label.
	 * @return void
	 */
	private static function secret_row( $name, $label ) {
		$stored = (string) Blogcraft_Settings::get( $name );

		printf(
			'<tr><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><input type="password" class="regular-text" name="%1$s" id="blogcraft_%1$s" value="" autocomplete="new-password" placeholder="%3$s" /><p class="description">%4$s</p></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( '' === $stored ? __( 'Not set', 'blogcraft' ) : Blogcraft_Crypto::mask( $stored ) ),
			esc_html__( 'Leave blank to keep the saved key.', 'blogcraft' )
		);
	}

	/**
	 * Render one number input row.
	 *
	 * @param string $name        Setting key.
	 * @param string $label       Field label.
	 * @param string $description Hint shown beneath.
	 * @return void
	 */
	private static function number_row( $name, $label, $description = '' ) {
		printf(
			'<tr><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><input type="number" min="0" class="small-text" name="%1$s" id="blogcraft_%1$s" value="%3$s" /><p class="description">%4$s</p></td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) Blogcraft_Settings::get( $name ) ),
			esc_html( $description )
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
			array_keys( self::voice_text_fields() ),
			array_keys( self::voice_area_fields() ),
			array( 'provider_type', 'provider_request_template', 'autopilot_topics', 'autopilot_status', 'research_provider', 'research_base_url', 'research_urls', 'image_provider' )
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

		if ( isset( $_POST['autopilot_per_day'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			Blogcraft_Settings::set( 'autopilot_per_day', (int) $_POST['autopilot_per_day'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// An unchecked checkbox posts nothing, so absence means false.
		foreach ( array_keys( self::toggle_fields() ) as $toggle ) {
			Blogcraft_Settings::set( $toggle, isset( $_POST[ $toggle ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// An empty key field means "leave unchanged": the form renders a mask rather
		// than the real value, so treating blank as "clear" would wipe the stored key
		// every time an unrelated field was saved.
		foreach ( array( 'pexels_api_key', 'pixabay_api_key' ) as $secret ) {
			$value = isset( $_POST[ $secret ] ) ? trim( (string) wp_unslash( $_POST[ $secret ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing

			if ( '' !== $value ) {
				Blogcraft_Settings::set( $secret, $value );
			}
		}

		$research_key = isset( $_POST['research_api_key'] ) ? trim( (string) wp_unslash( $_POST['research_api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
		if ( '' !== $research_key ) {
			Blogcraft_Settings::set( 'research_api_key', $research_key );
		}

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
