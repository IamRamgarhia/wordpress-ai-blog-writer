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
		add_action( 'wp_ajax_blogcraft_learn_voice', array( __CLASS__, 'handle_learn' ) );
		add_action( 'wp_ajax_blogcraft_list_models', array( __CLASS__, 'handle_list_models' ) );
	}

	/**
	 * Ask the configured provider which models this account can actually use.
	 *
	 * Every adapter has been able to do this since they were written, and
	 * nothing ever called it — so the model id was a free-text box, and a
	 * free-text box asking for "the model id exactly as your provider writes
	 * it" invites the name of the key, the name of the product, or a model
	 * that was retired last year. All three fail identically at generation
	 * time, hours later, with an error from the provider that does not say
	 * "you typed the wrong kind of thing here".
	 *
	 * Asking the provider is also the only approach that stays correct: no
	 * list is bundled, so nothing needs updating when models change.
	 *
	 * @return void
	 */
	public static function handle_list_models() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'blogcraft' ) ), 403 );
		}

		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! Blogcraft_Request::verify( self::SAVE_ACTION, $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'That form has expired. Reload the page.', 'blogcraft' ) ), 403 );
		}

		// Read from the form rather than from storage: the reader may be
		// typing a key right now and have saved nothing yet, which is exactly
		// the moment they want to see the list.
		$type = isset( $_POST['provider_type'] ) ? sanitize_text_field( wp_unslash( $_POST['provider_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$key  = isset( $_POST['api_key'] ) ? trim( (string) wp_unslash( $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
		$base = isset( $_POST['base_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['base_url'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- esc_url_raw is the sanitiser for a URL.

		if ( '' === $key ) {
			$key = (string) Blogcraft_Settings::get( 'provider_api_key' );
		}

		if ( '' === $base ) {
			$base = trim( (string) Blogcraft_Settings::get( 'provider_base_url' ) );
		}

		if ( '' === $base ) {
			$base = Blogcraft_Provider_Registry::default_base_url( $type );
		}

		$provider = Blogcraft_Provider_Registry::make(
			$type,
			array(
				'base_url' => $base,
				'endpoint' => $base,
				'api_key'  => $key,
				'model'    => '',
			)
		);

		if ( null === $provider ) {
			wp_send_json_error( array( 'message' => __( 'Choose a provider first.', 'blogcraft' ) ), 400 );
		}

		$models = array();

		try {
			$models = (array) $provider->list_models();
		} catch ( Throwable $e ) {
			$models = array();
		}

		if ( empty( $models ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Could not read a model list. Check the key is right, or type the model id yourself — the link beside this field goes to your provider\'s list.', 'blogcraft' ),
				),
				200
			);
		}

		wp_send_json_success( array( 'models' => array_values( $models ) ) );
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

		wp_localize_script(
			'blogcraft-admin',
			'blogcraftProviders',
			array(
				'help'      => Blogcraft_Provider_Registry::help_map(),
				'bases'     => Blogcraft_Provider_Registry::base_url_map(),
				/* translators: %s: default API address for the selected provider. */
				'baseText'  => __( 'Leave blank to use %s.', 'blogcraft' ),
				'baseNone'  => __( 'Required for a custom endpoint. There is no default to fall back to.', 'blogcraft' ),
				'baseTail'  => __( 'Point it at a proxy, a self-hosted model, or any compatible service.', 'blogcraft' ),
				/* translators: %s: provider name, such as OpenAI. */
				'keyText'   => __( 'Get a key from %s', 'blogcraft' ),
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::SAVE_ACTION ),
				'learning'  => __( 'Reading your posts...', 'blogcraft' ),
				'learned'   => __( 'Learn from my posts', 'blogcraft' ),
				'failed'    => __( 'Your posts could not be read. Fill the fields in yourself.', 'blogcraft' ),
				'asking'    => __( 'Asking your provider...', 'blogcraft' ),
				'askModel'  => __( 'Show the models on my account', 'blogcraft' ),
				/* translators: %d: how many models the provider returned. */
				'gotModels' => __( '%d models on your account. Pick one and it fills the box above.', 'blogcraft' ),
				'pickModel' => __( 'Pick a model...', 'blogcraft' ),
			)
		);
	}

	/**
	 * Fill the voice fields in from the site's existing posts.
	 *
	 * Returns values for the form rather than saving them. A settings screen
	 * that rewrites itself without asking is one nobody trusts twice.
	 *
	 * @return void
	 */
	public static function handle_learn() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'blogcraft' ) ), 403 );
		}

		$nonce = isset( $_POST['_blogcraft_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_blogcraft_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( ! Blogcraft_Request::verify( self::SAVE_ACTION, $nonce ) ) {
			wp_send_json_error( array( 'message' => __( 'That form has expired. Reload the page.', 'blogcraft' ) ), 403 );
		}

		wp_send_json_success( Blogcraft_Learn::suggest() );
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
			'provider_base_url' => array(
				__( 'Base URL', 'blogcraft' ),
				self::base_url_hint(),
			),
			'provider_model'    => array(
				__( 'Model', 'blogcraft' ),
				__( 'The model id exactly as your provider writes it. Model names get retired regularly, so take the current one from the provider list linked below rather than copying an example. Nothing runs until this is filled in.', 'blogcraft' ),
			),
		);
	}

	/**
	 * Where to get a key for the chosen provider.
	 *
	 * Finding the page that issues a key is the commonest place to get stuck,
	 * and it is somewhere different for every provider. The link is swapped by
	 * script when the provider changes, and rendered server-side first so it is
	 * right before any script runs.
	 *
	 * @param string $type Provider type.
	 * @return void
	 */
	private static function render_provider_help( $type ) {
		$help = Blogcraft_Provider_Registry::help( $type );

		echo '<p class="blogcraft-provider-help" id="blogcraft-provider-help"';

		if ( '' === $help['key_url'] ) {
			echo ' hidden';
		}

		echo '>';

		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer" data-role="key">%2$s</a>',
			esc_url( $help['key_url'] ),
			esc_html(
				sprintf(
					/* translators: %s: provider name, such as OpenAI. */
					__( 'Get a key from %s', 'blogcraft' ),
					$help['label']
				)
			)
		);

		printf(
			' <a href="%1$s" target="_blank" rel="noopener noreferrer" data-role="docs">%2$s</a>',
			esc_url( $help['docs_url'] ),
			esc_html__( 'See their model names', 'blogcraft' )
		);

		printf(
			'<span class="screen-reader-text"> %s</span>',
			esc_html__( '(opens in a new tab)', 'blogcraft' )
		);

		echo '</p>';
	}

	/**
	 * Explain what leaving the base URL blank will actually do.
	 *
	 * Naming the address the plugin falls back to is the difference between a
	 * field someone can leave alone with confidence and one they guess at.
	 *
	 * @return string
	 */
	private static function base_url_hint() {
		$default = Blogcraft_Provider_Registry::default_base_url(
			(string) Blogcraft_Settings::get( 'provider_type' )
		);

		$local = __( 'Point it at a proxy, a self-hosted model, or any compatible service — http://localhost:11434/v1 for Ollama, for example.', 'blogcraft' );

		if ( '' === $default ) {
			return __( 'Required for a custom endpoint. There is no default to fall back to.', 'blogcraft' ) . ' ' . $local;
		}

		return sprintf(
			/* translators: %s: default API address for the selected provider. */
			__( 'Leave blank to use %s.', 'blogcraft' ),
			$default
		) . ' ' . $local;
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
			'voice_niche'         => array( __( 'What this blog is about', 'blogcraft' ), __( 'One or two sentences on the subject and the angle.', 'blogcraft' ) ),
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
	private static function picture_toggles() {
		return array(
			'images_enabled'     => __( 'Give each post a featured image', 'blogcraft' ),
			'images_per_section' => __( 'Also put a picture under each section heading', 'blogcraft' ),
		);
	}

	/**
	 * Boolean feature toggles.
	 *
	 * @return array
	 */
	private static function toggle_fields() {
		return array(
			'internal_links_enabled'  => __( 'Add links to your existing posts', 'blogcraft' ),
			'verify_links_enabled'    => __( 'Check that links resolve before publishing', 'blogcraft' ),
			'backlinks_enabled'       => __( 'Link older posts to each new one', 'blogcraft' ),
			'duplicate_check_enabled' => __( 'Refuse topics too similar to existing posts', 'blogcraft' ),
			'autopilot_enabled'       => __( 'Write posts automatically on a schedule', 'blogcraft' ),
			'refresh_enabled'         => __( 'Rewrite older posts when nothing new is queued', 'blogcraft' ),
			'indexnow_enabled'        => __( 'Tell Bing and Yandex about each post as it goes live', 'blogcraft' ),
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
		Blogcraft_Nav::render();
		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Blogcraft Settings', 'blogcraft' ) . '</h1>';
		echo '<p>' . esc_html__( 'Set it up once. Everything here shapes every post it writes.', 'blogcraft' ) . '</p>';
		echo '</div>';

		self::render_status();

		echo '<div class="bc-settings-shell">';
		echo '<div class="bc-settings-main">';

		if ( is_array( $result ) ) {
			delete_transient( self::RESULT_TRANSIENT . get_current_user_id() );
			printf(
				'<div class="notice %s"><p>%s</p></div>',
				esc_attr( empty( $result['ok'] ) ? 'notice-error' : 'notice-success' ),
				esc_html( (string) $result['message'] )
			);
		}

		echo '<form method="post" id="blogcraft-settings-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_save_settings" />';
		Blogcraft_Request::nonce_field( self::SAVE_ACTION );

		self::open_card( '01', __( 'Connect a provider', 'blogcraft' ), __( 'Your key, your account, your bill. Nothing is sent to us.', 'blogcraft' ), 'provider' );
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
		echo '</select>';
		printf(
			'<p class="bc-hint">%s</p>',
			esc_html__( '"Free tier" or "some free" means the provider itself gives away some usage at no cost, not that Blogcraft has changed anything. Limits move on their schedule, not this plugin\'s, so check the provider\'s own page below for the current number rather than trusting a figure written into a plugin.', 'blogcraft' )
		);
		echo '</td></tr>';

		$default_base = Blogcraft_Provider_Registry::default_base_url(
			(string) Blogcraft_Settings::get( 'provider_type' )
		);

		foreach ( self::common_fields() as $name => $field ) {
			self::text_row(
				$name,
				$field[0],
				'',
				$field[1],
				'provider_base_url' === $name ? $default_base : ''
			);

			// The model field gets a way to ask the provider what this account
			// can actually use, because "type the id exactly" is an invitation
			// to type the name of the key instead — and that failure only
			// surfaces hours later as an error from the provider.
			if ( 'provider_model' === $name ) {
				self::render_model_picker();
				self::render_draft_model_row();
			}
		}

		echo '<tr><th scope="row"><label for="blogcraft_provider_api_key">' . esc_html__( 'API key', 'blogcraft' ) . '</label></th><td>';
		printf(
			'<input type="password" class="regular-text" name="provider_api_key" id="blogcraft_provider_api_key" value="" autocomplete="new-password" placeholder="%s" />',
			esc_attr( '' === $key ? __( 'Not set', 'blogcraft' ) : Blogcraft_Crypto::mask( $key ) )
		);
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved key.', 'blogcraft' ) . '</p>';
		echo self::clear_key_control( 'provider_api_key', $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		self::render_provider_help( $type );
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
			__( 'Connect a picture service', 'blogcraft' ),
			__( 'Pictures come from a different kind of service than the writing does, so switching them on is how you tell Blogcraft it may contact one. Nothing here runs until you do. The default service is free and needs no key.', 'blogcraft' ),
			'pictures'
		);
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( self::picture_toggles() as $name => $label ) {
			self::checkbox_row( $name, $label );
		}

		echo '<tr><th scope="row"><label for="blogcraft_image_provider">' . esc_html__( 'Who draws them', 'blogcraft' ) . '</label></th><td>';
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

		self::number_row(
			'monthly_image_cap',
			__( 'Most paid images per month', 'blogcraft' ),
			__( 'Only counts pictures made by a service that charges. Zero means no limit. Past the limit, posts fall back to the free image sources rather than stopping.', 'blogcraft' )
		);

		self::image_model_rows();

		self::secret_row( 'pexels_api_key', __( 'Pexels API key', 'blogcraft' ) );
		self::secret_row( 'pixabay_api_key', __( 'Pixabay API key', 'blogcraft' ) );

		echo '</tbody></table>';
		self::close_card();

		self::open_card(
			'03',
			__( 'Research', 'blogcraft' ),
			__( 'Optional but it is the biggest lever on quality. Without sources the model writes from memory, which is what search engines discount. With none configured it falls back to your own posts.', 'blogcraft' ),
			'research'
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

		foreach ( Blogcraft_Research::free_sources() as $name => $label ) {
			self::checkbox_row( $name, $label );
		}

		self::text_row( 'research_base_url', __( 'SearXNG URL', 'blogcraft' ) );

		echo '<tr><th scope="row"><label for="blogcraft_research_api_key">' . esc_html__( 'Search API key', 'blogcraft' ) . '</label></th><td>';
		$research_key = (string) Blogcraft_Settings::get( 'research_api_key' );
		printf(
			'<input type="password" class="regular-text" name="research_api_key" id="blogcraft_research_api_key" value="" autocomplete="new-password" placeholder="%s" />',
			esc_attr( '' === $research_key ? __( 'Not set', 'blogcraft' ) : Blogcraft_Crypto::mask( $research_key ) )
		);
		echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved key.', 'blogcraft' ) . '</p>';
		echo self::clear_key_control( 'research_api_key', $research_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</td></tr>';

		self::textarea_row(
			'research_urls',
			__( 'Always read these URLs', 'blogcraft' ),
			__( 'One per line. Read for every post, whether or not a search provider is set.', 'blogcraft' )
		);

		echo '</tbody></table>';
		self::close_card();

		self::open_card( '04', __( 'Describe your voice', 'blogcraft' ), __( 'Sent with every request, so posts sound like your site instead of a template. The more specific, the less generic the writing.', 'blogcraft' ), 'voice' );
		if ( Blogcraft_Learn::sample( 1 ) ) {
			printf(
				'<p class="bc-learn-row"><button type="button" class="button bc-learn" id="blogcraft-learn">%1$s</button> <span class="description">%2$s</span></p><div class="bc-learn-notes" id="blogcraft-learn-notes" hidden></div>',
				esc_html__( 'Learn from my posts', 'blogcraft' ),
				esc_html__( 'Fills these in from what you have already published. Nothing is saved until you press save.', 'blogcraft' )
			);
		}

		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( self::voice_area_fields() as $name => $meta ) {
			self::textarea_row( $name, $meta[0], $meta[1] );
		}

		foreach ( self::voice_text_fields() as $name => $label ) {
			self::text_row( $name, $label );
		}

		self::text_row(
			'author_credentials',
			__( 'What the author does', 'blogcraft' ),
			'',
			__( 'The role or qualification of whoever posts are credited to, for example "Head barista, twelve years". Published as an expertise signal alongside the byline.', 'blogcraft' )
		);

		self::text_row(
			'reviewer_name',
			__( 'Reviewed by', 'blogcraft' ),
			'',
			__( 'A second, named person who checks posts before they go out. This is the strongest signal available to a site publishing with AI help, and the one thing a generated post cannot claim for itself. Leave blank if nobody does.', 'blogcraft' )
		);

		self::text_row(
			'reviewer_credentials',
			__( 'What the reviewer does', 'blogcraft' ),
			'',
			__( 'Their role or qualification.', 'blogcraft' )
		);

		echo '</tbody></table>';

		self::close_card();
		self::open_card( '05', __( 'Automation', 'blogcraft' ), __( 'Optional. Turn these on once the writing looks right to you.', 'blogcraft' ), 'automation' );
		echo '<table class="form-table" role="presentation"><tbody>';

		foreach ( self::toggle_fields() as $name => $label ) {
			self::checkbox_row( $name, $label );
		}

		printf(
			'<tr><th scope="row"></th><td><p class="description">%s</p></td></tr>',
			esc_html__( 'Announcing a post sends its address to IndexNow, which is Microsoft\'s open service — Bing, Yandex, Seznam and Naver read it. Nothing is sent until you tick that box, and only the address is sent, never the post. Google has said it does not take part, so this does nothing for Google either way.', 'blogcraft' )
		);

		self::textarea_row(
			'autopilot_topics',
			__( 'Topic queue', 'blogcraft' ),
			__( 'One topic per line. Each is used once, then removed from this list. Blogcraft, Calendar shows when each one will be written.', 'blogcraft' )
		);
		self::weekday_row();
		self::hour_row();
		self::number_row(
			'quality_threshold',
			__( 'Hold posts scoring below', 'blogcraft' ),
			__( 'Out of 100. Anything lower is held for review instead of published, whatever you chose above.', 'blogcraft' )
		);
		self::number_row(
			'refresh_after_days',
			__( 'Consider a post stale after', 'blogcraft' ),
			__( 'Days. Refreshing an existing post is usually worth more than publishing a new one, because the URL keeps whatever history it has earned.', 'blogcraft' )
		);
		self::number_row( 'autopilot_per_day', __( 'Maximum posts per day', 'blogcraft' ), __( 'A low number is safer. Volume without review is what search engines penalise. Zero writes nothing, which is a way to pause automatic posts without losing the schedule.', 'blogcraft' ) );

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
		echo '<p class="description">' . esc_html__( 'Drafts are safer. Nothing goes live until you have read it.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '<div class="blogcraft-actions">';
		submit_button( __( 'Save settings', 'blogcraft' ), 'primary', 'submit', false );
		echo '</div>';
		self::close_card();
		echo '</form>';

		self::open_card( '06', __( 'Check it works', 'blogcraft' ), __( 'Sends one very short request and reports what the provider says back.', 'blogcraft' ), 'test' );
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
		self::render_jump();
		echo '</div>';

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
	 * @param string $slug        Anchor slug, so the rail can link to it.
	 * @return void
	 */
	private static function open_card( $step, $title, $description, $slug = '' ) {
		// Numbered steps read as five things you must do. Only the first one is,
		// and saying so is the difference between a screen someone finishes and
		// one they abandon half way down.
		$needed = ( 'provider' === $slug );

		printf(
			'<section class="blogcraft-card" id="%4$s"><header><span class="blogcraft-step">%1$s</span><span class="bc-need%5$s">%6$s</span><h2>%2$s</h2><p>%3$s</p>',
			esc_html( $step ),
			esc_html( $title ),
			esc_html( $description ),
			esc_attr( '' === $slug ? '' : 'bc-card-' . $slug ),
			$needed ? ' is-needed' : '',
			esc_html( $needed ? __( 'Required', 'blogcraft' ) : __( 'Optional', 'blogcraft' ) )
		);

		self::render_help( $slug );

		echo '</header>';
	}

	/**
	 * What each card is for, in more words than its subtitle allows.
	 *
	 * Folded away by default. A settings screen that explains everything up
	 * front is unreadable, and one that explains nothing sends people to a
	 * search engine; a control that opens the explanation in place is the only
	 * arrangement that serves both the person who knows and the person who
	 * does not.
	 *
	 * @return array Slug => array( paragraphs, docs anchor ).
	 */
	private static function help_text() {
		return array(
			'provider'   => array(
				'anchor' => 'providers',
				'lines'  => array(
					__( 'Blogcraft has no AI of its own. It talks to a provider you choose, using a key from your account, and every request is billed to you by them and never passes through us.', 'blogcraft' ),
					__( 'Pick the provider you already have an account with. If you have none, Groq and Google both have free tiers large enough to write with, and Ollama runs a model on your own machine for nothing at all.', 'blogcraft' ),
					__( 'Three fields matter: the provider, the key, and the model id. Take the model id from the provider list linked here rather than copying an example, because these get retired without notice. Leave the base URL blank unless you are pointing at something of your own.', 'blogcraft' ),
				),
			),
			'pictures'   => array(
				'anchor' => 'pictures',
				'lines'  => array(
					__( 'Pictures come from a different kind of service than the writing does, which is why they get their own card. Nothing here is required, and nothing here runs until you switch pictures on — that switch is how you tell Blogcraft it may contact a picture service. Pollinations needs no key and is the one it starts on.', 'blogcraft' ),
					__( 'The article decides what a picture shows — the model that wrote the post describes the scene — and the Pictures controls under "How it writes" decide how it looks.', 'blogcraft' ),
					__( 'fal.ai, OpenAI, Gemini and Grok charge per picture. They are only ever used when you pick one of them, never as a fallback, so an image is never billed to you by accident. If you already write with OpenAI, Google or xAI, choosing the same one here uses the key you have already entered.', 'blogcraft' ),
					__( 'Pexels and Pixabay search real photographs rather than drawing anything. Their keys are free.', 'blogcraft' ),
				),
			),
			'research'   => array(
				'anchor' => 'research',
				'lines'  => array(
					__( 'This is the single biggest lever on whether a post is worth reading. With research on, the model is handed current sources and writes from them. With it off, it writes from memory, which is exactly the kind of page search engines now discount.', 'blogcraft' ),
					__( 'Every source starts off. Wikipedia and Hacker News need no key, so switching one on is all they need. Tavily and SerpApi are paid but return more current results. A SearXNG instance is free if you host one.', 'blogcraft' ),
					__( 'Anything found here is also used to check the finished draft: if the article merely restates its sources, the score says so and the rewrite is told to fix it.', 'blogcraft' ),
				),
			),
			'voice'      => array(
				'anchor' => 'voice',
				'lines'  => array(
					__( 'Everything here is sent with every request. It is the difference between posts that sound like your site and posts that sound like every other AI blog.', 'blogcraft' ),
					__( 'If you already have posts published, use "Learn from my posts". It measures how you actually write — sentence length, paragraph length, whether you use em dashes or contractions, whether you say "I" or "you" — and drafts the descriptions from your own titles. Nothing is saved until you press save.', 'blogcraft' ),
					__( 'The experience field is the one worth spending time on. It is the only part of a post a model cannot produce, and it is what stops the writing being a summary of pages that already exist.', 'blogcraft' ),
				),
			),
			'automation' => array(
				'anchor' => 'automation',
				'lines'  => array(
					__( 'None of this is needed to write a post by hand. Turn it on once the writing already looks right to you, not before.', 'blogcraft' ),
					__( 'Automatic posts are saved as drafts unless you say otherwise, and anything scoring below your threshold is held for review whatever you chose. The daily cap and the monthly token cap are both there to make a mistake cheap.', 'blogcraft' ),
					__( 'Pictures are optional. Pollinations needs no key. fal.ai and OpenAI charge per image and are only ever used when you pick one of them, never as a fallback.', 'blogcraft' ),
				),
			),
			'test'       => array(
				'anchor' => 'checking-it-works',
				'lines'  => array(
					__( 'Sends one very short request and reports exactly what came back. It costs a fraction of a penny and it is the fastest way to tell a wrong key from a wrong model id from a provider that is simply down.', 'blogcraft' ),
					__( 'Saving a key runs this automatically, so a mistake is caught at the moment you make it rather than on a cron tick nobody is watching.', 'blogcraft' ),
				),
			),
		);
	}

	/**
	 * The help control and its folded panel.
	 *
	 * @param string $slug Card slug.
	 * @return void
	 */
	private static function render_help( $slug ) {
		$all  = self::help_text();
		$slug = (string) $slug;

		if ( ! isset( $all[ $slug ] ) ) {
			return;
		}

		$id = 'bc-help-' . $slug;

		// A bare question mark in a corner is a control nobody finds. It says
		// what it does.
		printf(
			'<button type="button" class="bc-help-toggle" aria-expanded="false" aria-controls="%1$s"><span aria-hidden="true">?</span>%2$s</button>',
			esc_attr( $id ),
			esc_html__( 'How this works', 'blogcraft' )
		);

		printf( '<div class="bc-help" id="%s" hidden>', esc_attr( $id ) );

		foreach ( $all[ $slug ]['lines'] as $line ) {
			printf( '<p>%s</p>', esc_html( $line ) );
		}

		// The documentation ships with the plugin. It used to link to a page on
		// a website that did not exist, so the one control offering to explain
		// more returned a 404.
		printf(
			'<p class="bc-help-more"><a href="%1$s">%2$s</a></p>',
			esc_url( Blogcraft_Docs::url( $all[ $slug ]['anchor'] ) ),
			esc_html__( 'Read the full documentation', 'blogcraft' )
		);

		echo '</div>';
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
	 * Jump links to each section of this screen.
	 *
	 * Settings runs to five long cards, and the space to the right of them was
	 * empty on any normal monitor. Plain anchors rather than script: they work
	 * before JavaScript loads, survive it failing, and can be opened in a new
	 * tab or bookmarked, which a click handler cannot.
	 *
	 * @return void
	 */
	private static function render_jump() {
		$sections = array(
			'provider'   => array( __( 'Connect a provider', 'blogcraft' ), __( 'Key, model, spending cap', 'blogcraft' ) ),
			'pictures'   => array( __( 'Connect a picture service', 'blogcraft' ), __( 'Who draws them, and what it costs', 'blogcraft' ) ),
			'research'   => array( __( 'Research', 'blogcraft' ), __( 'Where facts come from', 'blogcraft' ) ),
			'voice'      => array( __( 'Describe your voice', 'blogcraft' ), __( 'Subject, reader, style', 'blogcraft' ) ),
			'automation' => array( __( 'Automation', 'blogcraft' ), __( 'Schedule, images, links, quality', 'blogcraft' ) ),
			'test'       => array( __( 'Check it works', 'blogcraft' ), __( 'One short live request', 'blogcraft' ) ),
		);

		echo '<div class="bc-jump-col">';
		echo '<nav class="bc-jump" aria-label="' . esc_attr__( 'Sections on this page', 'blogcraft' ) . '">';
		printf( '<h2 class="bc-jump-title">%s</h2>', esc_html__( 'On this page', 'blogcraft' ) );

		$step = 1;

		foreach ( $sections as $slug => $parts ) {
			printf(
				'<a class="bc-jump-item" href="#bc-card-%1$s" data-target="bc-card-%1$s"><span class="bc-jump-step">%2$02d</span><span class="bc-jump-text"><span class="bc-jump-label">%3$s</span><span class="bc-jump-sub">%4$s</span></span></a>',
				esc_attr( $slug ),
				(int) $step,
				esc_html( $parts[0] ),
				esc_html( $parts[1] )
			);
			++$step;
		}

		echo '</nav>';

		// The rail sits outside the form so it can stay put while the page
		// scrolls, so the button names the form it belongs to.
		printf(
			'<button type="submit" form="blogcraft-settings-form" class="bc-jump-save">%s</button>',
			esc_html__( 'Save settings', 'blogcraft' )
		);

		echo '</div>';
	}

	/**
	 * Show what is still missing, read from the real settings.
	 *
	 * @return void
	 */
	private static function render_status() {
		// Each state carries both wordings. A grey dot beside "Provider connected"
		// reads as a claim that it is connected, so the label has to change too:
		// colour alone is not something every reader can act on.
		$states = array(
			array(
				'done' => Blogcraft_Provider_Registry::is_configured(),
				'yes'  => __( 'Provider connected', 'blogcraft' ),
				'no'   => self::missing_label(),
			),
			array(
				'done' => Blogcraft_Voice::is_configured(),
				'yes'  => __( 'Voice described', 'blogcraft' ),
				'no'   => __( 'Voice not described', 'blogcraft' ),
			),
			array(
				'done' => (bool) Blogcraft_Settings::get( 'autopilot_enabled' )
					&& array() !== Blogcraft_Autopilot::days(),
				'yes'  => __( 'Automation on', 'blogcraft' ),
				'no'   => (bool) Blogcraft_Settings::get( 'autopilot_enabled' )
					? __( 'Automation has no days', 'blogcraft' )
					: __( 'Automation off', 'blogcraft' ),
			),
		);

		echo '<ul class="blogcraft-status">';

		foreach ( $states as $state ) {
			printf(
				'<li class="%1$s">%2$s</li>',
				$state['done'] ? 'is-done' : '',
				esc_html( $state['done'] ? $state['yes'] : $state['no'] )
			);
		}

		echo '</ul>';
	}

	/**
	 * Name the one thing still missing from the provider setup.
	 *
	 * "No provider yet" is true but unhelpful when the key is saved and only
	 * the model name is blank: it sends someone back to re-check a key that was
	 * never the problem.
	 *
	 * @return string
	 */
	private static function missing_label() {
		$has_key   = '' !== trim( (string) Blogcraft_Settings::get( 'provider_api_key' ) );
		$has_model = '' !== trim( (string) Blogcraft_Settings::get( 'provider_model' ) );

		if ( $has_key && ! $has_model ) {
			return __( 'Model name missing', 'blogcraft' );
		}

		if ( ! $has_key && $has_model ) {
			return __( 'API key missing', 'blogcraft' );
		}

		return __( 'No provider yet', 'blogcraft' );
	}

	/**
	 * Render the weekday picker.
	 *
	 * Checkboxes rather than a free-text list: the stored value is a numeric
	 * CSV, and asking anyone to type "1,2,3,4,5" for weekdays is how a setting
	 * ends up wrong without ever looking wrong.
	 *
	 * @return void
	 */
	private static function weekday_row() {
		$chosen = Blogcraft_Autopilot::days();
		$names  = Blogcraft_Calendar::weekday_names();
		$start  = (int) get_option( 'start_of_week', 1 );

		echo '<tr><th scope="row">' . esc_html__( 'Write on', 'blogcraft' ) . '</th><td>';
		echo '<fieldset class="blogcraft-days">';
		printf(
			'<legend class="screen-reader-text">%s</legend>',
			esc_html__( 'Days of the week to write on', 'blogcraft' )
		);

		for ( $offset = 0; $offset <= 6; $offset++ ) {
			$day = ( $start + $offset ) % 7;

			printf(
				'<label class="blogcraft-day"><input type="checkbox" name="autopilot_days[]" value="%1$d"%2$s /> %3$s</label>',
				(int) $day,
				checked( in_array( $day, $chosen, true ), true, false ),
				esc_html( isset( $names[ $day ] ) ? $names[ $day ] : (string) $day )
			);
		}

		echo '</fieldset>';

		// Automation switched on with no days chosen looks configured and does
		// nothing at all, which is the worst of both.
		if ( empty( $chosen ) && Blogcraft_Settings::get( 'autopilot_enabled' ) ) {
			echo '<p class="blogcraft-callout">' . esc_html__( 'Automatic writing is switched on, but no days are ticked, so nothing will ever be written. Choose at least one day.', 'blogcraft' ) . '</p>';
		}

		echo '<p class="description">' . esc_html__( 'In your site timezone. Posting every single day, weekends included, is one of the clearer signs of an unattended blog.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Render the start-hour picker.
	 *
	 * @return void
	 */
	private static function hour_row() {
		$chosen = Blogcraft_Autopilot::hour();
		$format = (string) get_option( 'time_format', 'H:i' );
		$today  = strtotime( wp_date( 'Y-m-d' ) . ' 00:00:00 ' . wp_timezone_string() );

		echo '<tr><th scope="row"><label for="blogcraft_autopilot_hour">' . esc_html__( 'Starting at', 'blogcraft' ) . '</label></th><td>';
		echo '<select name="autopilot_hour" id="blogcraft_autopilot_hour">';

		for ( $hour = 0; $hour <= 23; $hour++ ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				(int) $hour,
				selected( $chosen, $hour, false ),
				esc_html(
					false === $today
						? sprintf( '%02d:00', $hour )
						: wp_date( $format, $today + ( $hour * HOUR_IN_SECONDS ) )
				)
			);
		}

		echo '</select>';
		echo '<p class="description">' . esc_html__( 'The earliest a post is started. WordPress only runs scheduled work when someone visits the site, so a quiet morning can push it later.', 'blogcraft' ) . '</p>';
		echo '</td></tr>';
	}

	/**
	 * Render one text input row.
	 *
	 * @param string $name        Setting key.
	 * @param string $label       Field label.
	 * @param string $row_class   Optional class for the row.
	 * @param string $description Optional hint shown beneath.
	 * @param string $placeholder Optional text shown in the empty field.
	 * @return void
	 */
	private static function text_row( $name, $label, $row_class = '', $description = '', $placeholder = '' ) {
		printf(
			'<tr class="%4$s"><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><input type="text" class="regular-text" name="%1$s" id="blogcraft_%1$s" value="%3$s" placeholder="%6$s" autocomplete="off" spellcheck="false" />%5$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( (string) Blogcraft_Settings::get( $name ) ),
			esc_attr( $row_class ),
			'' === $description
				? ''
				: '<p class="description" id="' . esc_attr( 'blogcraft_' . $name . '_hint' ) . '">' . esc_html( $description ) . '</p>',
			esc_attr( $placeholder )
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
	 * @param string $name      Setting key.
	 * @param string $label     Field label.
	 * @param string $row_class Class on the row, so it can be shown conditionally.
	 * @return void
	 */
	private static function secret_row( $name, $label, $row_class = '' ) {
		$stored = (string) Blogcraft_Settings::get( $name );

		printf(
			'<tr class="%5$s"><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><input type="password" class="regular-text" name="%1$s" id="blogcraft_%1$s" value="" autocomplete="new-password" placeholder="%3$s" /><p class="description">%4$s</p>%6$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( '' === $stored ? __( 'Not set', 'blogcraft' ) : Blogcraft_Crypto::mask( $stored ) ),
			esc_html__( 'Leave blank to keep the saved key.', 'blogcraft' ),
			esc_attr( $row_class ),
			self::clear_key_control( $name, $stored ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * The optional cheaper model for the bulk of the writing.
	 *
	 * Its own row rather than a line in the main model's description, because
	 * leaving it blank has to be an obviously fine choice: blank means one
	 * model does everything, which is what every install did before this
	 * existed and what most should keep doing.
	 *
	 * @return void
	 */
	private static function render_draft_model_row() {
		self::text_row(
			'provider_draft_model',
			__( 'Cheaper model for the bulk', 'blogcraft' ),
			'',
			__( 'Optional, and the same key and provider — only the model id differs. Most of a post\'s words are the section-by-section writing, which is carrying out a plan the outline already made. Naming a cheaper model here uses it for those sections, the questions and the extra blocks, while the outline, the opening, the critique and the rewrite stay on the model above, because those are the steps where judgement changes the result. Leave it blank to use one model for everything.', 'blogcraft' )
		);
	}

	/**
	 * A control that fills the model field from the provider's own list.
	 *
	 * Deliberately additive rather than a replacement: the text field stays,
	 * because a custom endpoint, a self-hosted runtime, or a model too new to
	 * appear in a list all need somewhere to type. This just means nobody
	 * *has* to.
	 *
	 * @return void
	 */
	private static function render_model_picker() {
		printf(
			'<tr class="bc-model-picker-row"><th scope="row"></th><td>'
			. '<button type="button" class="button" id="blogcraft-fetch-models">%1$s</button> '
			. '<select id="blogcraft-model-choices" class="bc-model-choices" hidden><option value="">%2$s</option></select>'
			. '<p class="description" id="blogcraft-model-status">%3$s</p>'
			. '</td></tr>',
			esc_html__( 'Show the models on my account', 'blogcraft' ),
			esc_html__( 'Pick a model…', 'blogcraft' ),
			esc_html__( 'Asks your provider which models your key can use, and fills the box above. Nothing is bundled, so this list is never out of date.', 'blogcraft' )
		);
	}

	/**
	 * The control that removes a stored key.
	 *
	 * Shown only when there is something to remove. A blank field means "keep
	 * what is there", which is right — the field can only render a mask, so
	 * blank-means-clear would wipe a key every time an unrelated setting was
	 * saved. That left no way to remove one at all, which is this.
	 *
	 * @param string $name   Setting key.
	 * @param string $stored Currently stored value.
	 * @return string
	 */
	private static function clear_key_control( $name, $stored ) {
		if ( '' === $stored ) {
			return '';
		}

		return sprintf(
			'<label class="blogcraft-clear-key"><input type="checkbox" name="clear_%1$s" value="1" /> %2$s</label>',
			esc_attr( $name ),
			esc_html__( 'Remove this key', 'blogcraft' )
		);
	}

	/**
	 * Keys and model ids for the services that generate pictures.
	 *
	 * Model ids are typed rather than picked from a list. Providers retire
	 * models on their own schedule and this plugin has already shipped one dead
	 * model id in a hint; a list baked in here would go stale silently. The
	 * links go to each provider's live catalogue instead.
	 *
	 * @return void
	 */
	private static function image_model_rows() {
		$fal    = Blogcraft_Image_Models::help( 'fal' );
		$openai = Blogcraft_Image_Models::help( 'openai' );

		self::secret_row( 'fal_api_key', __( 'fal.ai API key', 'blogcraft' ), 'blogcraft-image-fal' );
		self::provider_link_row(
			__( 'Where to get it', 'blogcraft' ),
			$fal['key_url'],
			__( 'Create a fal.ai key', 'blogcraft' ),
			'',
			'blogcraft-image-fal'
		);

		self::text_row( 'fal_model', __( 'fal.ai model', 'blogcraft' ), 'blogcraft-image-fal' );
		self::provider_link_row(
			__( 'Which model', 'blogcraft' ),
			$fal['models_url'],
			__( 'Browse text-to-image models', 'blogcraft' ),
			__( 'Paste the id exactly as the model page shows it, for example fal-ai/flux/schnell. Schnell is the cheapest and fastest. A pro FLUX model looks better and costs more. Ideogram is the one to pick if you need legible words in the picture.', 'blogcraft' ),
			'blogcraft-image-fal'
		);

		foreach ( array( 'gemini', 'xai' ) as $service ) {
			$spec  = Blogcraft_Image_Models::help( $service );
			$class = 'blogcraft-image-' . $service;

			self::secret_row(
				'image_key_' . $service,
				sprintf(
					/* translators: %s: the service name, such as Google AI Studio. */
					__( '%s image key', 'blogcraft' ),
					$spec['label']
				),
				$class
			);

			self::text_row(
				'image_model_' . $service,
				__( 'Image model', 'blogcraft' ),
				$class
			);

			self::provider_link_row(
				__( 'Which model', 'blogcraft' ),
				$spec['models_url'],
				__( 'See the image models', 'blogcraft' ),
				(string) Blogcraft_Settings::get( 'provider_type' ) === $service
					? __( 'You are already writing with this provider, so leave the key blank and the same one draws the pictures. You still need to name an image model.', 'blogcraft' )
					: __( 'Your writing provider is a different company, so a key for this one is needed above.', 'blogcraft' ),
				$class
			);
		}

		self::secret_row( 'openai_image_key', __( 'OpenAI image key', 'blogcraft' ), 'blogcraft-image-openai' );
		self::text_row( 'openai_image_model', __( 'OpenAI image model', 'blogcraft' ), 'blogcraft-image-openai' );
		self::provider_link_row(
			__( 'Which model', 'blogcraft' ),
			$openai['models_url'],
			__( 'OpenAI image guide', 'blogcraft' ),
			// Whether one key covers both depends entirely on who wrote the
			// key, so say which case the reader is actually in rather than
			// making them work it out.
			'openai' === (string) Blogcraft_Settings::get( 'provider_type' )
				? __( 'You are writing with OpenAI, so leave the key above blank and the same key makes the pictures. One key, one bill. You still need to name an image model here.', 'blogcraft' )
				: __( 'Your writing provider is not OpenAI, so a separate OpenAI key is needed above. A key from one company will not work at another.', 'blogcraft' ),
			'blogcraft-image-openai'
		);
	}

	/**
	 * A row that is only a link out to a provider.
	 *
	 * @param string $label       Row label.
	 * @param string $url         Destination.
	 * @param string $link_text   Link text.
	 * @param string $description Extra explanation.
	 * @param string $row_class   Class on the row, so it can be shown conditionally.
	 * @return void
	 */
	private static function provider_link_row( $label, $url, $link_text, $description = '', $row_class = '' ) {
		printf(
			'<tr class="%5$s"><th scope="row">%1$s</th><td><a href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>%4$s</td></tr>',
			esc_html( $label ),
			esc_url( $url ),
			esc_html( $link_text ),
			'' === $description ? '' : '<p class="description">' . esc_html( $description ) . '</p>',
			esc_attr( $row_class )
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

		list( $was_usable, $submitted_key, $failed ) = self::apply_submitted_settings();

		if ( ! empty( $failed ) ) {
			self::redirect_back(
				false,
				__( 'Your keys could not be stored. Blogcraft encrypts them before saving, and that needs PHP\'s sodium extension, which this server does not have. Ask your host to enable it — nothing else on this screen is affected.', 'blogcraft' )
			);
		}

		self::redirect_back( true, self::save_message( '' !== $submitted_key, $was_usable ) );
	}

	/**
	 * Read every settings-screen field out of $_POST and store it.
	 *
	 * Split out from handle_save() so the actual writing can be exercised
	 * directly: handle_save() ends in wp_safe_redirect() + exit, which a test
	 * cannot call through safely. This is the part worth pinning — it is
	 * exactly the part that silently dropped two fields for as long as it did,
	 * because nothing could reach it to notice.
	 *
	 * @return array array( $was_usable, $submitted_key ) — whether the provider
	 *               already worked before this save, and the raw provider key
	 *               submitted (empty string when the masked field was left
	 *               alone), both needed for the save notice.
	 */
	private static function apply_submitted_settings() {
		$was_usable = Blogcraft_Provider_Registry::is_configured();

		$plain = array_merge(
			array_keys( self::common_fields() ),
			array_keys( self::custom_fields() ),
			array_keys( self::voice_text_fields() ),
			array_keys( self::voice_area_fields() ),
			array( 'provider_type', 'provider_draft_model', 'provider_request_template', 'autopilot_topics', 'autopilot_status', 'research_provider', 'research_base_url', 'research_urls', 'image_provider', 'fal_model', 'openai_image_model', 'image_model_gemini', 'image_model_xai', 'author_credentials', 'reviewer_name', 'reviewer_credentials' )
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

		if ( isset( $_POST['monthly_image_cap'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			Blogcraft_Settings::set( 'monthly_image_cap', max( 0, (int) $_POST['monthly_image_cap'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( isset( $_POST['autopilot_per_day'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			Blogcraft_Settings::set( 'autopilot_per_day', (int) $_POST['autopilot_per_day'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// Both are plain number inputs rendered by number_row() alongside the
		// fields above, but neither was ever in a list this method reads —
		// the value the user typed was shown back to them as "Settings saved."
		// and then thrown away. The threshold in particular is load-bearing:
		// it is what "held for review instead of published" means.
		if ( isset( $_POST['quality_threshold'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			Blogcraft_Settings::set( 'quality_threshold', max( 0, min( 100, (int) $_POST['quality_threshold'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( isset( $_POST['refresh_after_days'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			Blogcraft_Settings::set( 'refresh_after_days', max( 1, (int) $_POST['refresh_after_days'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		if ( isset( $_POST['autopilot_hour'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			Blogcraft_Settings::set( 'autopilot_hour', max( 0, min( 23, (int) $_POST['autopilot_hour'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// Checkboxes post an array, and post nothing at all when none are ticked.
		// Storing an empty string is correct there: no days means no automatic
		// writing, which is exactly what unticking every box asks for.
		$days = array();

		if ( isset( $_POST['autopilot_days'] ) && is_array( $_POST['autopilot_days'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( wp_unslash( $_POST['autopilot_days'] ) as $day ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing
				$day = (int) $day;

				if ( $day >= 0 && $day <= 6 ) {
					$days[ $day ] = $day;
				}
			}
		}

		ksort( $days );
		Blogcraft_Settings::set( 'autopilot_days', implode( ',', $days ) );

		// An unchecked checkbox posts nothing, so absence means false.
		foreach ( array_keys( Blogcraft_Research::free_sources() ) as $toggle ) {
			Blogcraft_Settings::set( $toggle, isset( $_POST[ $toggle ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		foreach ( array_keys( self::picture_toggles() ) as $toggle ) {
			Blogcraft_Settings::set( $toggle, isset( $_POST[ $toggle ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		foreach ( array_keys( self::toggle_fields() ) as $toggle ) {
			Blogcraft_Settings::set( $toggle, isset( $_POST[ $toggle ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		// An empty key field means "leave unchanged": the form renders a mask
		// rather than the real value, so treating blank as "clear" would wipe
		// the stored key every time an unrelated field was saved. Removing one
		// is done with the tick beside it, which is the only way to say so
		// deliberately — and without it there was no way to remove a key at
		// all, short of editing the database.
		$failed = array();

		foreach ( self::secret_fields() as $secret ) {
			if ( isset( $_POST[ 'clear_' . $secret ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				Blogcraft_Settings::delete( $secret );

				continue;
			}

			$value = isset( $_POST[ $secret ] ) ? trim( (string) wp_unslash( $_POST[ $secret ] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing

			if ( '' === $value ) {
				continue;
			}

			// set() returns false when a secret could not be encrypted, which
			// is what happens on a host without the sodium extension. That
			// result was discarded, so the screen said "Settings saved." over
			// a key that had not been stored, and the next run failed to
			// authenticate for no reason anybody could see.
			if ( ! Blogcraft_Settings::set( $secret, $value ) ) {
				$failed[] = $secret;
			}
		}

		$submitted_key = isset( $_POST['provider_api_key'] ) ? trim( (string) wp_unslash( $_POST['provider_api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing

		return array( $was_usable, $submitted_key, $failed );
	}

	/**
	 * Every setting held as an encrypted secret.
	 *
	 * @return array
	 */
	private static function secret_fields() {
		return array(
			'provider_api_key',
			'research_api_key',
			'fal_api_key',
			'openai_image_key',
			'image_key_gemini',
			'image_key_xai',
			'pexels_api_key',
			'pixabay_api_key',
		);
	}

	/**
	 * Confirm the saved setup actually works, and say so in the save notice.
	 *
	 * Saving a key and being told "Settings saved" teaches nothing: the key is
	 * only ever exercised on the next generation run, which happens on a cron
	 * tick nobody watches. A wrong key then looks like a plugin that quietly
	 * does nothing. One short request at save time turns that into an answer.
	 *
	 * Only runs when a key was actually submitted, so saving an unrelated
	 * field does not spend a request every time.
	 *
	 * @param bool $key_changed Whether a new key was submitted.
	 * @param bool $was_usable  Whether the setup already worked before this save.
	 * @return string
	 */
	private static function save_message( $key_changed, $was_usable ) {
		$saved = __( 'Settings saved.', 'blogcraft' );

		// Check on a new key, and on the save that first completes the setup.
		// Someone who pastes a key one day and adds the model the next never
		// submits both at once, and that is exactly when they need telling.
		$now_usable = Blogcraft_Provider_Registry::is_configured();

		if ( ! $key_changed && ! ( $now_usable && ! $was_usable ) ) {
			return $saved;
		}

		if ( '' === trim( (string) Blogcraft_Settings::get( 'provider_model' ) ) ) {
			return $saved . ' ' . __( 'Add a model name before it can write anything.', 'blogcraft' );
		}

		$provider = Blogcraft_Provider_Registry::from_settings();

		if ( null === $provider ) {
			return $saved;
		}

		$probe = Blogcraft_Provider_Registry::probe( $provider );

		if ( empty( $probe['reachable'] ) ) {
			return $saved . ' ' . sprintf(
				/* translators: %s: reason the provider gave. */
				__( 'The key did not work: %s', 'blogcraft' ),
				self::shorten( (string) $probe['error'] )
			);
		}

		return $saved . ' ' . __( 'The key works.', 'blogcraft' );
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

		// Testing with nothing filled in spends a round trip to be told what we
		// already know, and returns the provider's own wording for it, which
		// talks about Authorization headers this plugin sets for you.
		if ( ! Blogcraft_Provider_Registry::is_configured() ) {
			self::redirect_back(
				false,
				__( 'Fill in a model and an API key first, then save, then test.', 'blogcraft' )
			);
		}

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
					self::shorten( (string) $probe['error'] )
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
	 * Trim a provider's error down to something a person will read.
	 *
	 * Providers answer a bad key with several sentences of setup advice aimed
	 * at someone writing raw HTTP. The first sentence carries the meaning; the
	 * rest describes work this plugin already does.
	 *
	 * @param string $error Raw error text.
	 * @return string
	 */
	private static function shorten( $error ) {
		$error = trim( preg_replace( '/\s+/', ' ', $error ) );

		if ( '' === $error ) {
			return __( 'the provider gave no reason.', 'blogcraft' );
		}

		if ( preg_match( '/^(.{20,180}?[.!?])\s/u', $error, $matches ) ) {
			return $matches[1];
		}

		if ( strlen( $error ) > 200 ) {
			return substr( $error, 0, 200 ) . '…';
		}

		return $error;
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
