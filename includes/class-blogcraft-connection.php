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
	 * The form every setting on this screen belongs to.
	 *
	 * Named because the controls say so rather than because of where they sit.
	 * This screen carries nine forms — the mode switch, issuing a token,
	 * disconnecting an app, testing a key, saving a provider — and a form
	 * inside a form is not something HTML has. The browser drops the inner
	 * opening tag and lets the inner closing tag end the outer one, so the
	 * settings form used to finish at the first of them and every control
	 * after that belonged to nothing at all.
	 */
	const FORM_ID = 'blogcraft-settings-form';

	/**
	 * Nonce action for the connection test.
	 */
	const TEST_ACTION = 'blogcraft_test_connection';
	/**
	 * Nonce action for issuing a connection token.
	 */
	const MCP_ISSUE_ACTION = 'blogcraft_mcp_issue';

	/**
	 * Nonce action for choosing or switching the setup path.
	 */
	const PATH_ACTION = 'blogcraft_choose_path';

	/**
	 * Nonce action for saving, switching or forgetting a provider.
	 */
	const SAVED_ACTION = 'blogcraft_saved_provider';

	/**
	 * Nonce action for revoking one.
	 */
	const MCP_REVOKE_ACTION = 'blogcraft_mcp_revoke';

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
		add_action( 'admin_post_blogcraft_choose_path', array( __CLASS__, 'handle_choose_path' ) );
		add_action( 'admin_post_blogcraft_saved_provider', array( __CLASS__, 'handle_saved_provider' ) );
		add_action( 'admin_post_blogcraft_mcp_issue', array( __CLASS__, 'handle_mcp_issue' ) );
		add_action( 'admin_post_blogcraft_mcp_test_seen', array( __CLASS__, 'handle_mcp_test_seen' ) );
		add_action( 'admin_post_blogcraft_mcp_revoke', array( __CLASS__, 'handle_mcp_revoke' ) );
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
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::SAVE_ACTION, '_blogcraft_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'That form has expired. Reload the page.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		// Every one of these asks the provider something. Without one the
		// old answer was whatever the HTTP layer said, which is true and
		// tells nobody what to do about it.
		Blogcraft_Request::require_provider();

		// Read from the form rather than from storage: the reader may be
		// typing a key right now and have saved nothing yet, which is exactly
		// the moment they want to see the list.
		$type = isset( $_POST['provider_type'] ) ? sanitize_text_field( wp_unslash( $_POST['provider_type'] ) ) : '';
		$key  = isset( $_POST['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) ) : '';
		$base = isset( $_POST['base_url'] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST['base_url'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw is the sanitiser for a URL.

		if ( '' === $key ) {
			$owner = (string) Blogcraft_Settings::get( 'provider_key_owner' );

			// Only fall back to the stored key when it belongs to the provider
			// being asked. Sending Gemini's key to Anthropic produces an
			// authentication error that reads like a broken plugin.
			if ( '' === $owner || $owner === $type ) {
				$key = (string) Blogcraft_Settings::get( 'provider_api_key' );
			} else {
				wp_send_json_error(
					array( 'message' => __( 'The saved key belongs to a different provider. Paste a key for this one, then try again.', 'dicecodes-ai-blog-writer' ) ),
					200
				);
			}
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
			wp_send_json_error( array( 'message' => __( 'Choose a provider first.', 'dicecodes-ai-blog-writer' ) ), 400 );
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
					'message' => __( 'Could not read a model list. Check the key is right, or type the model id yourself — the link beside this field goes to your provider\'s list.', 'dicecodes-ai-blog-writer' ),
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
				'help'       => Blogcraft_Provider_Registry::help_map(),
				'bases'      => Blogcraft_Provider_Registry::base_url_map(),
				/* translators: %s: default API address for the selected provider. */
				'baseText'   => __( 'Leave blank to use %s.', 'dicecodes-ai-blog-writer' ),
				'baseNone'   => __( 'Required for a custom endpoint. There is no default to fall back to.', 'dicecodes-ai-blog-writer' ),
				'baseTail'   => __( 'Point it at a proxy, a self-hosted model, or any compatible service.', 'dicecodes-ai-blog-writer' ),
				/* translators: %s: provider name, such as OpenAI. */
				'keyText'    => __( 'Get a key from %s', 'dicecodes-ai-blog-writer' ),
				'copied'     => __( 'Copied', 'dicecodes-ai-blog-writer' ),
				'copyFailed' => __( 'Press Ctrl+C to copy', 'dicecodes-ai-blog-writer' ),
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( self::SAVE_ACTION ),
				'learning'   => __( 'Reading your posts...', 'dicecodes-ai-blog-writer' ),
				'learned'    => __( 'Learn from my posts', 'dicecodes-ai-blog-writer' ),
				'failed'     => __( 'Your posts could not be read. Fill the fields in yourself.', 'dicecodes-ai-blog-writer' ),
				'asking'     => __( 'Asking your provider...', 'dicecodes-ai-blog-writer' ),
				'askModel'   => __( 'Show the models on my account', 'dicecodes-ai-blog-writer' ),
				/* translators: %d: how many models the provider returned. */
				'gotModels'  => __( '%d models on your account. Pick one and it fills the box above.', 'dicecodes-ai-blog-writer' ),
				'pickModel'  => __( 'Pick a model...', 'dicecodes-ai-blog-writer' ),
				// Which provider the saved key belongs to, so the field can
				// stop claiming a key the moment the provider changes. The
				// key itself is never sent to the browser.
				'keyOwner'   => (string) Blogcraft_Settings::get( 'provider_key_owner' ),
				'keyMask'    => '' === (string) Blogcraft_Settings::get( 'provider_api_key' )
					? __( 'Not set', 'dicecodes-ai-blog-writer' )
					: Blogcraft_Crypto::mask( (string) Blogcraft_Settings::get( 'provider_api_key' ) ),
				'keyNone'    => __( 'Not set', 'dicecodes-ai-blog-writer' ),
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
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::SAVE_ACTION, '_blogcraft_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'That form has expired. Reload the page.', 'dicecodes-ai-blog-writer' ) ), 403 );
		}

		// Reading your posts back needs the provider as much as writing one
		// does, and this button sits on the same screen as the empty key field.
		Blogcraft_Request::require_provider();

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
			__( 'Dicecodes AI Blog Writer settings', 'dicecodes-ai-blog-writer' ),
			__( 'Settings', 'dicecodes-ai-blog-writer' ),
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
				__( 'Base URL', 'dicecodes-ai-blog-writer' ),
				self::base_url_hint(),
			),
			'provider_model'    => array(
				__( 'Model', 'dicecodes-ai-blog-writer' ),
				__( 'The model id exactly as your provider writes it. Model names get retired regularly, so take the current one from the provider list linked below rather than copying an example. Nothing runs until this is filled in.', 'dicecodes-ai-blog-writer' ),
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
					__( 'Get a key from %s', 'dicecodes-ai-blog-writer' ),
					$help['label']
				)
			)
		);

		printf(
			' <a href="%1$s" target="_blank" rel="noopener noreferrer" data-role="docs">%2$s</a>',
			esc_url( $help['docs_url'] ),
			esc_html__( 'See their model names', 'dicecodes-ai-blog-writer' )
		);

		printf(
			'<span class="screen-reader-text"> %s</span>',
			esc_html__( '(opens in a new tab)', 'dicecodes-ai-blog-writer' )
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

		$local = __( 'Point it at a proxy, a self-hosted model, or any compatible service — http://localhost:11434/v1 for Ollama, for example.', 'dicecodes-ai-blog-writer' );

		if ( '' === $default ) {
			return __( 'Required for a custom endpoint. There is no default to fall back to.', 'dicecodes-ai-blog-writer' ) . ' ' . $local;
		}

		return sprintf(
			/* translators: %s: default API address for the selected provider. */
			__( 'Leave blank to use %s.', 'dicecodes-ai-blog-writer' ),
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
			'provider_auth_header'            => __( 'Auth header name', 'dicecodes-ai-blog-writer' ),
			'provider_auth_prefix'            => __( 'Auth value prefix', 'dicecodes-ai-blog-writer' ),
			'provider_text_path'              => __( 'Response text path', 'dicecodes-ai-blog-writer' ),
			'provider_prompt_tokens_path'     => __( 'Prompt tokens path', 'dicecodes-ai-blog-writer' ),
			'provider_completion_tokens_path' => __( 'Completion tokens path', 'dicecodes-ai-blog-writer' ),
		);
	}

	/**
	 * Short voice fields.
	 *
	 * @return array
	 */
	private static function voice_text_fields() {
		return array(
			'voice_tone'          => __( 'Tone', 'dicecodes-ai-blog-writer' ),
			'voice_point_of_view' => __( 'Point of view', 'dicecodes-ai-blog-writer' ),
			'voice_reading_level' => __( 'Reading level', 'dicecodes-ai-blog-writer' ),
		);
	}

	/**
	 * Long-form voice fields, with the hint shown beneath each.
	 *
	 * @return array
	 */
	private static function voice_area_fields() {
		return array(
			'voice_niche'         => array( __( 'What this blog is about', 'dicecodes-ai-blog-writer' ), __( 'One or two sentences on the subject and the angle.', 'dicecodes-ai-blog-writer' ) ),
			'voice_audience'      => array( __( 'Who you write for', 'dicecodes-ai-blog-writer' ), __( 'Who is reading, and what they already know.', 'dicecodes-ai-blog-writer' ) ),
			'voice_style_rules'   => array( __( 'Style rules', 'dicecodes-ai-blog-writer' ), __( 'One per line. For example: no em dashes. Short paragraphs. Never open with a question.', 'dicecodes-ai-blog-writer' ) ),
			'voice_banned_words'  => array( __( 'Extra banned words', 'dicecodes-ai-blog-writer' ), __( 'One per line. A list of common AI tells is already blocked by default.', 'dicecodes-ai-blog-writer' ) ),
			'voice_banned_topics' => array( __( 'Never write about', 'dicecodes-ai-blog-writer' ), __( 'One per line. Competitors, off-limits claims, anything legally sensitive.', 'dicecodes-ai-blog-writer' ) ),
			'voice_experience'    => array( __( 'Your own experience', 'dicecodes-ai-blog-writer' ), __( 'Anecdotes, opinions or data only you have. This is what AI writing structurally lacks.', 'dicecodes-ai-blog-writer' ) ),
		);
	}

	/**
	 * Boolean feature toggles.
	 *
	 * @return array
	 */
	private static function picture_toggles() {
		return array(
			'images_enabled'     => __( 'Give each post a featured image', 'dicecodes-ai-blog-writer' ),
			'images_per_section' => __( 'Also put a picture under each section heading', 'dicecodes-ai-blog-writer' ),
		);
	}

	/**
	 * Boolean feature toggles.
	 *
	 * @return array
	 */
	private static function toggle_fields() {
		return array(
			'internal_links_enabled'  => __( 'Add links to your existing posts', 'dicecodes-ai-blog-writer' ),
			'verify_links_enabled'    => __( 'Check that links resolve before publishing', 'dicecodes-ai-blog-writer' ),
			'backlinks_enabled'       => __( 'Link older posts to each new one', 'dicecodes-ai-blog-writer' ),
			'duplicate_check_enabled' => __( 'Refuse topics too similar to existing posts', 'dicecodes-ai-blog-writer' ),
			'ask_before_writing'      => __( 'Ask what each post will include before writing it', 'dicecodes-ai-blog-writer' ),
			'ai_disclosure'           => __( 'Say on each post that AI helped write it', 'dicecodes-ai-blog-writer' ),
			'schema_enabled'          => __( 'Add search-engine structured data to each post', 'dicecodes-ai-blog-writer' ),

			'autopilot_enabled'       => __( 'Write posts automatically on a schedule', 'dicecodes-ai-blog-writer' ),
			'refresh_enabled'         => __( 'Rewrite older posts when nothing new is queued', 'dicecodes-ai-blog-writer' ),
			'indexnow_enabled'        => __( 'Tell Bing and Yandex about each post as it goes live', 'dicecodes-ai-blog-writer' ),

			'mcp_enabled'             => __( 'Let an AI client connect to this site', 'dicecodes-ai-blog-writer' ),
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
			'<tr class="blogcraft-toggle-row"><th scope="row"></th><td><label for="blogcraft_%1$s"><input form="blogcraft-settings-form" type="checkbox" name="%1$s" id="blogcraft_%1$s" value="1"%3$s /> %2$s</label></td></tr>',
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
			wp_die( esc_html__( 'You are not allowed to access this page.', 'dicecodes-ai-blog-writer' ) );
		}

		$type   = (string) Blogcraft_Settings::get( 'provider_type' );
		$key    = (string) Blogcraft_Settings::get( 'provider_api_key' );
		$result = get_transient( self::RESULT_TRANSIENT . get_current_user_id() );

		echo '<div class="wrap blogcraft-page">';
		Blogcraft_Nav::render();
		echo '<div class="blogcraft-head">';
		echo '<h1>' . esc_html__( 'Dicecodes AI Blog Writer settings', 'dicecodes-ai-blog-writer' ) . '</h1>';
		echo '<p>' . esc_html__( 'Set it up once. Everything here shapes every post it writes.', 'dicecodes-ai-blog-writer' ) . '</p>';

		// Sent here mid-introduction. Without this the first step of the
		// wizard is a one-way door: it asks somebody to set up a provider and
		// then leaves them to find their own way back to a page they had been
		// looking at for ten seconds.
		$from = isset( $_GET['from'] ) ? sanitize_key( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only: decides whether to offer a way back to the introduction, and nothing else.

		if ( 'welcome' === $from ) {
			printf(
				'<p class="bc-back-to-setup"><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . Blogcraft_Welcome::PAGE_SLUG ) ),
				esc_html__( 'Back to setting up', 'dicecodes-ai-blog-writer' )
			);
		}

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

		// Short, standalone, and closed before any card is drawn. What used to
		// wrap the whole screen ended at the first card that had a form of its
		// own, which on every setup was the mode switch — so Save submitted
		// that instead, and pressing it changed how the site writes.
		printf(
			'<form method="post" id="%1$s" action="%2$s">',
			esc_attr( self::FORM_ID ),
			esc_url( admin_url( 'admin-post.php' ) )
		);
		echo '<input type="hidden" name="action" value="blogcraft_save_settings" />';
		Blogcraft_Request::nonce_field( self::SAVE_ACTION );
		echo '</form>';

		// Asked before anything else, because the answer decides what the
		// rest of this screen is for.
		self::render_path_chooser();

		if ( self::shows( 'provider' ) ) {
			self::open_card_for( 'provider' );
			self::render_saved_providers();
			echo '<table class="form-table" role="presentation"><tbody>';

			echo '<tr><th scope="row"><label for="blogcraft_provider_type">' . esc_html__( 'Provider', 'dicecodes-ai-blog-writer' ) . '</label></th><td>';
			$groups = Blogcraft_Provider_Registry::groups();
			$chosen = ( '' !== $type );

			echo '<select form="blogcraft-settings-form" name="provider_type" id="blogcraft_provider_type">';

			// A real first option rather than a default. Nineteen providers
			// and one of them preselected is a decision made on the reader's
			// behalf, and it was OpenAI: paid, card first, and above every
			// route that costs nothing.
			printf(
				'<option value=""%1$s>%2$s</option>',
				selected( $chosen, false, false ),
				esc_html__( 'Choose a provider…', 'dicecodes-ai-blog-writer' )
			);

			// Grouped, free first. The labels always said which were free; in
			// a flat list of nineteen that only helped somebody who read all
			// nineteen, and the two sitting at the top of it both want a card
			// before they will answer anything.
			foreach ( Blogcraft_Provider_Registry::grouped_types() as $class_name => $members ) {
				printf(
					'<optgroup label="%s">',
					esc_attr( isset( $groups[ $class_name ] ) ? $groups[ $class_name ] : $class_name )
				);

				foreach ( $members as $id => $label ) {
					printf(
						'<option value="%s"%s>%s</option>',
						esc_attr( $id ),
						selected( $type, $id, false ),
						esc_html( $label )
					);
				}

				echo '</optgroup>';
			}

			echo '</select>';
			printf(
				'<p class="bc-hint">%s</p>',
				esc_html__( 'Spending nothing is a supported way to use this plugin, not a trial of it. The first group runs a model on this machine and contacts nobody; the second gives away usage for a key with no card attached. Everything works the same either way — there is no paid tier here to unlock.', 'dicecodes-ai-blog-writer' )
			);
			printf(
				'<p class="bc-hint">%s</p>',
				esc_html__( 'The groups describe what the provider charges, not what this plugin charges. Allowances move on their schedule, not this plugin\'s, so the link under each choice goes to their own page for the current figure rather than a number written into a plugin.', 'dicecodes-ai-blog-writer' )
			);
			echo '</td></tr>';

			// A key saved for a different provider is not a key you have. Showing
			// its mask made the field claim otherwise, and the model list then
			// failed against the wrong service with nothing explaining why.
			$owner    = (string) Blogcraft_Settings::get( 'provider_key_owner' );
			$key_fits = ( '' !== $key ) && ( '' === $owner || $owner === $type );

			echo '<tr><th scope="row"><label for="blogcraft_provider_api_key">' . esc_html__( 'API key', 'dicecodes-ai-blog-writer' ) . '</label></th><td>';
			printf(
				'<input form="blogcraft-settings-form" type="password" class="regular-text" name="provider_api_key" id="blogcraft_provider_api_key" value="" autocomplete="new-password" placeholder="%s" />',
				esc_attr( $key_fits ? Blogcraft_Crypto::mask( $key ) : __( 'Not set', 'dicecodes-ai-blog-writer' ) )
			);

			if ( '' !== $key && ! $key_fits ) {
				$types = Blogcraft_Provider_Registry::types();

				printf(
					'<p class="bc-key-mismatch" id="blogcraft-key-mismatch">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: the provider the saved key belongs to. */
							__( 'The key you have saved is for %s. Paste one for the provider you just chose — the link below goes to the right place.', 'dicecodes-ai-blog-writer' ),
							isset( $types[ $owner ] ) ? $types[ $owner ] : $owner
						)
					)
				);
			} else {
				echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved key.', 'dicecodes-ai-blog-writer' ) . '</p>';
			}

			echo wp_kses( self::clear_key_control( 'provider_api_key', $key ), Blogcraft_Markup::allowed() );
			self::render_provider_help( $type );
			echo '</td></tr>';

			$default_base = Blogcraft_Provider_Registry::default_base_url( $type );

			// A provider that issues no keys — the ones running on this machine
			// — has nothing to wait for, so it gets the model fields straight
			// away. An unchosen provider is not one of those: help() falls back
			// to the custom endpoint, whose key_url is empty, so without the
			// first test an empty select would read as "needs no key" and open
			// the model fields before there was anything to ask.
			$help    = Blogcraft_Provider_Registry::help( $type );
			$keyless = $chosen && ( '' === trim( (string) $help['key_url'] ) );

			if ( ! $key_fits && ! $keyless ) {
				// Asking which model to use before there is a key to ask with was
				// the wrong way round. The list of models comes from the account,
				// so until a key is saved the only thing this screen could offer
				// was an empty box and a button that fails — and typing a model id
				// from memory is the commonest way to end up with a setup that
				// looks finished and errors on the first post.
				printf(
					'<tr><th scope="row"></th><td><p class="bc-await-key">%s</p></td></tr>',
					esc_html(
						$chosen
							? __( 'Paste your key above and press Save settings. The model list is read from your own account, so it can only be offered once there is a key to ask with — and it appears here as soon as there is.', 'dicecodes-ai-blog-writer' )
							: __( 'Choose a provider above and press Save settings. Which key to paste, which address to use and which models exist all follow from that one choice, so it is the only thing this screen asks for first.', 'dicecodes-ai-blog-writer' )
					)
				);
			}

			// Skipped rather than returned early: the spending cap below and every
			// card after this one are still worth showing to somebody who has not
			// pasted a key yet.
			foreach ( ( $key_fits || $keyless ) ? self::common_fields() : array() as $name => $field ) {
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

			self::number_row( 'monthly_token_cap', __( 'Monthly token cap', 'dicecodes-ai-blog-writer' ), __( 'Stops generation once this many tokens are used in a month. Zero means no limit.', 'dicecodes-ai-blog-writer' ) );

			foreach ( self::custom_fields() as $name => $label ) {
				self::text_row( $name, $label, 'blogcraft-custom-only' );
			}

			echo '<tr class="blogcraft-custom-only"><th scope="row"><label for="blogcraft_provider_request_template">' . esc_html__( 'Request template (JSON)', 'dicecodes-ai-blog-writer' ) . '</label></th><td>';
			printf(
				'<textarea form="blogcraft-settings-form" name="provider_request_template" id="blogcraft_provider_request_template" rows="6" class="large-text code">%s</textarea>',
				esc_textarea( (string) Blogcraft_Settings::get( 'provider_request_template' ) )
			);
			echo '<p class="description">' . esc_html__( 'Custom provider only. Use {{prompt}} and {{model}} as placeholders.', 'dicecodes-ai-blog-writer' ) . '</p>';
			echo '</td></tr>';

			echo '</tbody></table>';

			self::close_card();
		}

		if ( self::shows( 'clients' ) ) {
			self::render_client_card();
		}

		if ( self::shows( 'pictures' ) ) {
			self::open_card_for( 'pictures' );
			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( self::picture_toggles() as $name => $label ) {
				self::checkbox_row( $name, $label );
			}

			echo '<tr><th scope="row"><label for="blogcraft_image_provider">' . esc_html__( 'Who draws them', 'dicecodes-ai-blog-writer' ) . '</label></th><td>';
			echo '<select form="blogcraft-settings-form" name="image_provider" id="blogcraft_image_provider">';
			foreach ( Blogcraft_Images::providers() as $id => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $id ),
					selected( (string) Blogcraft_Settings::get( 'image_provider' ), $id, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Whichever you pick, Dicecodes AI Blog Writer falls back through the others so a post is never left without an image.', 'dicecodes-ai-blog-writer' ) . '</p>';
			echo '</td></tr>';

			self::number_row(
				'monthly_image_cap',
				__( 'Most paid images per month', 'dicecodes-ai-blog-writer' ),
				__( 'Only counts pictures made by a service that charges. Zero means no limit. Past the limit, posts fall back to the free image sources rather than stopping.', 'dicecodes-ai-blog-writer' )
			);

			self::image_model_rows();

			self::secret_row( 'pexels_api_key', __( 'Pexels API key', 'dicecodes-ai-blog-writer' ) );
			self::secret_row( 'pixabay_api_key', __( 'Pixabay API key', 'dicecodes-ai-blog-writer' ) );

			echo '</tbody></table>';
			self::close_card();
		}

		if ( self::shows( 'research' ) ) {
			self::open_card_for( 'research' );
			echo '<table class="form-table" role="presentation"><tbody>';

			echo '<tr><th scope="row"><label for="blogcraft_research_provider">' . esc_html__( 'Search provider', 'dicecodes-ai-blog-writer' ) . '</label></th><td>';
			echo '<select form="blogcraft-settings-form" name="research_provider" id="blogcraft_research_provider">';
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

			self::text_row( 'research_base_url', __( 'SearXNG URL', 'dicecodes-ai-blog-writer' ) );

			echo '<tr><th scope="row"><label for="blogcraft_research_api_key">' . esc_html__( 'Search API key', 'dicecodes-ai-blog-writer' ) . '</label></th><td>';
			$research_key = (string) Blogcraft_Settings::get( 'research_api_key' );
			printf(
				'<input form="blogcraft-settings-form" type="password" class="regular-text" name="research_api_key" id="blogcraft_research_api_key" value="" autocomplete="new-password" placeholder="%s" />',
				esc_attr( '' === $research_key ? __( 'Not set', 'dicecodes-ai-blog-writer' ) : Blogcraft_Crypto::mask( $research_key ) )
			);
			echo '<p class="description">' . esc_html__( 'Leave blank to keep the saved key.', 'dicecodes-ai-blog-writer' ) . '</p>';
			echo wp_kses( self::clear_key_control( 'research_api_key', $research_key ), Blogcraft_Markup::allowed() );
			echo '</td></tr>';

			self::textarea_row(
				'research_urls',
				__( 'Always read these URLs', 'dicecodes-ai-blog-writer' ),
				__( 'One per line. Read for every post, whether or not a search provider is set.', 'dicecodes-ai-blog-writer' )
			);

			echo '</tbody></table>';
			self::close_card();
		}

		if ( self::shows( 'voice' ) ) {
			self::open_card_for( 'voice' );

			// The fields were here and on How it writes, and both were sent to
			// the model on every request — two descriptions of one voice, kept
			// on two screens with nothing holding them in agreement. That
			// screen won: it is the one with per-post overrides, and the one
			// the connected tools already read.
			printf(
				'<p>%1$s</p><p><a class="button" href="%2$s">%3$s</a></p>',
				esc_html__( 'Your subject, your reader, your style rules and the words to avoid all live on How it writes.', 'dicecodes-ai-blog-writer' ),
				esc_url( admin_url( 'admin.php?page=blogcraft-blueprint#voice' ) ),
				esc_html__( 'Describe your voice', 'dicecodes-ai-blog-writer' )
			);

			self::close_card();
		}
		if ( self::shows( 'automation' ) ) {
			self::open_card_for( 'automation' );
			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( self::toggle_fields() as $name => $label ) {
				self::checkbox_row( $name, $label );
			}

			self::text_row(
				'ai_disclosure_text',
				__( 'Wording of that line', 'dicecodes-ai-blog-writer' ),
				'',
				__( 'Leave blank for the default, which says the post was drafted with AI from the listed sources and then checked. Google asks for three things: that automation was involved, how, and why it helped — so if you write your own, keep those in it.', 'dicecodes-ai-blog-writer' )
			);

			printf(
				'<tr><th scope="row"></th><td><p class="description">%s</p></td></tr>',
				esc_html__( 'Announcing a post sends its address to IndexNow, which is Microsoft\'s open service — Bing, Yandex, Seznam and Naver read it. Nothing is sent until you tick that box, and only the address is sent, never the post. Google has said it does not take part, so this does nothing for Google either way.', 'dicecodes-ai-blog-writer' )
			);

			self::textarea_row(
				'autopilot_topics',
				__( 'Topic queue', 'dicecodes-ai-blog-writer' ),
				__( 'One topic per line. Each is used once, then removed from this list. Dicecodes AI Blog Writer, Calendar shows when each one will be written.', 'dicecodes-ai-blog-writer' )
			);
			self::weekday_row();
			self::hour_row();
			self::number_row(
				'quality_threshold',
				__( 'Hold posts scoring below', 'dicecodes-ai-blog-writer' ),
				__( 'Out of 100. Anything lower is held for review instead of published, whatever you chose above.', 'dicecodes-ai-blog-writer' )
			);
			self::number_row(
				'refresh_after_days',
				__( 'Consider a post stale after', 'dicecodes-ai-blog-writer' ),
				__( 'Days. Refreshing an existing post is usually worth more than publishing a new one, because the URL keeps whatever history it has earned.', 'dicecodes-ai-blog-writer' )
			);
			self::number_row( 'autopilot_per_day', __( 'Maximum posts per day', 'dicecodes-ai-blog-writer' ), __( 'A low number is safer. Volume without review is what search engines penalise. Zero writes nothing, which is a way to pause automatic posts without losing the schedule.', 'dicecodes-ai-blog-writer' ) );

			echo '<tr><th scope="row"><label for="blogcraft_autopilot_status">' . esc_html__( 'Automatic posts should be', 'dicecodes-ai-blog-writer' ) . '</label></th><td>';
			echo '<select form="blogcraft-settings-form" name="autopilot_status" id="blogcraft_autopilot_status">';
			printf(
				'<option value="draft"%s>%s</option>',
				selected( 'publish' !== Blogcraft_Settings::get( 'autopilot_status' ), true, false ),
				esc_html__( 'Saved as drafts for review', 'dicecodes-ai-blog-writer' )
			);
			printf(
				'<option value="publish"%s>%s</option>',
				selected( 'publish' === Blogcraft_Settings::get( 'autopilot_status' ), true, false ),
				esc_html__( 'Published immediately', 'dicecodes-ai-blog-writer' )
			);
			echo '</select>';
			echo '<p class="description">' . esc_html__( 'Drafts are safer. Nothing goes live until you have read it.', 'dicecodes-ai-blog-writer' ) . '</p>';
			echo '</td></tr>';

			echo '</tbody></table>';
			echo '<div class="blogcraft-actions">';
			// Named, like every other control here: this button sits among the
			// cards rather than inside the short form that saves them.
			submit_button(
				__( 'Save settings', 'dicecodes-ai-blog-writer' ),
				'primary',
				'submit',
				false,
				array( 'form' => self::FORM_ID )
			);
			echo '</div>';
			self::close_card();
		}

		if ( self::shows( 'removal' ) ) {
			self::open_card_for( 'removal' );

			printf(
				'<p class="bc-removal-lead">%s</p>',
				esc_html__( 'Deleting the plugin leaves your settings, your writing rules and its record of every post it wrote exactly where they are. Install it again and everything is as you left it. This is the safe default because deleting a plugin to reinstall it, to move hosts, or to clear a half-finished upload is an ordinary thing to do, and none of those mean you wanted the work thrown away.', 'dicecodes-ai-blog-writer' )
			);

			echo '<table class="form-table" role="presentation"><tbody>';
			self::checkbox_row( 'purge_on_delete', __( 'Delete all of it instead, when the plugin is deleted', 'dicecodes-ai-blog-writer' ) );
			echo '</tbody></table>';

			printf(
				'<p class="bc-removal-warn">%s</p>',
				esc_html__( 'With that ticked, deleting the plugin drops its database tables and removes every setting. There is no undo and no confirmation beyond this box — WordPress asks whether you meant to delete the plugin, and has no way to ask whether you also meant to delete the rest. Your posts themselves are never touched either way: they are WordPress posts and they stay.', 'dicecodes-ai-blog-writer' )
			);

			self::close_card();
		}
		if ( self::shows( 'test' ) ) {
			self::open_card_for( 'test' );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="blogcraft_test_connection" />';
			Blogcraft_Request::nonce_field( self::TEST_ACTION );
			echo '<div class="blogcraft-actions">';
			submit_button( __( 'Test connection', 'dicecodes-ai-blog-writer' ), 'secondary', 'submit', false );
			echo '<p class="blogcraft-hint">' . esc_html__( 'Save your settings first.', 'dicecodes-ai-blog-writer' ) . '</p>';
			echo '</div>';
			echo '</form>';
			self::close_card();
		}

		echo '</div>';
		self::render_jump();
		echo '</div>';

		echo '</div>';
	}

	/**
	 * Every card on this screen, in order, and which path it belongs to.
	 *
	 * One list, because there were two: the cards and the rail beside them each
	 * kept their own copy, so inserting a card renumbered one and left the
	 * other saying "02 Connect a picture service" beside a card that said
	 * "02 Connect an AI client". Numbering now comes from position in this
	 * array and the rail is built from the same entries the screen renders.
	 *
	 * @return array Slug => title, rail subtitle, card description, paths.
	 */
	private static function cards() {
		return array(
			'provider'   => array(
				'title' => __( 'Connect a provider', 'dicecodes-ai-blog-writer' ),
				'sub'   => __( 'Key, model, spending cap', 'dicecodes-ai-blog-writer' ),
				'desc'  => __( 'Your key, your account, your bill. Nothing is sent to us.', 'dicecodes-ai-blog-writer' ),
				'paths' => array( 'api' ),
			),
			'clients'    => array(
				'title' => __( 'Connect an AI client', 'dicecodes-ai-blog-writer' ),
				'sub'   => __( 'Claude, ChatGPT, your editor', 'dicecodes-ai-blog-writer' ),
				'desc'  => __( 'Use the subscription you already pay for. No API key.', 'dicecodes-ai-blog-writer' ),
				'paths' => array( 'client' ),
			),
			// Both paths. A connected app asks this site for the pictures
			// rather than making them, so add_pictures and publishing both
			// go through the same service — and with this card on the
			// provider path only, a client site had the feature working,
			// the composer offering to describe a picture, and the tool
			// answering "the owner turns them on under Settings", with no
			// card there to turn them on with.
			'pictures'   => array(
				'title' => __( 'Connect a picture service', 'dicecodes-ai-blog-writer' ),
				'sub'   => __( 'Who draws them, and what it costs', 'dicecodes-ai-blog-writer' ),
				'desc'  => __( 'Nothing contacts a picture service until you switch one on. The default is free.', 'dicecodes-ai-blog-writer' ),
				'paths' => array( 'api', 'client' ),
			),
			'research'   => array(
				'title' => __( 'Research', 'dicecodes-ai-blog-writer' ),
				'sub'   => __( 'Where facts come from', 'dicecodes-ai-blog-writer' ),
				'desc'  => __( 'Optional, and the biggest single lever on quality.', 'dicecodes-ai-blog-writer' ),
				'paths' => array( 'api' ),
			),
			'voice'      => array(
				'title' => __( 'Describe your voice', 'dicecodes-ai-blog-writer' ),
				'sub'   => __( 'Subject, reader, style', 'dicecodes-ai-blog-writer' ),
				'desc'  => __( 'Sent with every request, so posts sound like your site.', 'dicecodes-ai-blog-writer' ),
				'paths' => array( 'api', 'client' ),
			),
			'automation' => array(
				'title' => __( 'Automation', 'dicecodes-ai-blog-writer' ),
				'sub'   => __( 'Schedule, images, links, quality', 'dicecodes-ai-blog-writer' ),
				'desc'  => __( 'Optional. Turn these on once the writing looks right to you.', 'dicecodes-ai-blog-writer' ),
				'paths' => array( 'api' ),
			),
			'removal'    => array(
				'title' => __( 'If you delete this plugin', 'dicecodes-ai-blog-writer' ),
				'sub'   => __( 'What happens to your settings', 'dicecodes-ai-blog-writer' ),
				'desc'  => __( 'What happens to everything it has stored.', 'dicecodes-ai-blog-writer' ),
				'paths' => array( 'api', 'client' ),
			),
			'test'       => array(
				'title' => __( 'Check it works', 'dicecodes-ai-blog-writer' ),
				'sub'   => __( 'One short live request', 'dicecodes-ai-blog-writer' ),
				'desc'  => __( 'Sends one short request and reports what came back.', 'dicecodes-ai-blog-writer' ),
				'paths' => array( 'api' ),
			),
		);
	}

	/**
	 * Which path this site is set up on.
	 *
	 * @return string 'api' or 'client'.
	 */
	public static function path() {
		// Answered in one place now. This screen decides what to show,
		// but it is not the only screen the answer changes.
		return Blogcraft_Mode::current();
	}

	/**
	 * Whether the reader has ever answered the question.
	 *
	 * @return bool
	 */
	private static function path_chosen() {
		return Blogcraft_Mode::chosen();
	}

	/**
	 * The cards belonging to the current path, in order.
	 *
	 * @return array
	 */
	private static function visible_cards() {
		$path = self::path();
		$out  = array();

		foreach ( self::cards() as $slug => $card ) {
			if ( in_array( $path, $card['paths'], true ) ) {
				$out[ $slug ] = $card;
			}
		}

		return $out;
	}

	/**
	 * Whether one card belongs on the screen right now.
	 *
	 * @param string $slug Card slug.
	 * @return bool
	 */
	private static function shows( $slug ) {
		return isset( self::visible_cards()[ $slug ] );
	}

	/**
	 * Open a card, numbered by where it falls among the visible ones.
	 *
	 * @param string $slug Card slug.
	 * @return void
	 */
	private static function open_card_for( $slug ) {
		$visible = self::visible_cards();
		$step    = array_search( $slug, array_keys( $visible ), true ) + 1;

		self::open_card(
			sprintf( '%02d', (int) $step ),
			$visible[ $slug ]['title'],
			$visible[ $slug ]['desc'],
			$slug
		);
	}

	/**
	 * The question this screen opens with, and the way back to it.
	 *
	 * Asked first because the answer decides what the rest of the screen is
	 * for. Two ways to supply a model is not a detail to discover half way
	 * down a settings page — and somebody who picks one and finds the other
	 * suited them better should not have to work out how to undo it, so the
	 * way back is always on the screen.
	 *
	 * @return void
	 */
	private static function render_path_chooser() {
		if ( self::path_chosen() ) {
			self::render_path_switch();

			return;
		}

		echo '<section class="blogcraft-card bc-path-ask" id="bc-card-path">';
		printf( '<h2>%s</h2>', esc_html__( 'How do you want to write?', 'dicecodes-ai-blog-writer' ) );
		printf(
			'<p class="bc-path-lead">%s</p>',
			esc_html__( 'Two ways to do this. You can switch whenever you like.', 'dicecodes-ai-blog-writer' )
		);

		self::render_path_comparison();
		echo '</section>';
	}

	/**
	 * The two ways of running this, beside each other.
	 *
	 * One list, used both when the question is first asked and when
	 * somebody comes back to ask it again. Written twice it would be two
	 * answers to one question within a release or two.
	 *
	 * @param string $wrapper Class for the row, so the open question keeps a
	 *                        marker of its own.
	 * @return void
	 */
	private static function render_path_comparison( $wrapper = 'bc-path-options' ) {
		printf( '<div class="%s">', esc_attr( $wrapper ) );

		self::render_path_option(
			'api',
			__( 'Inside WordPress', 'dicecodes-ai-blog-writer' ),
			__( 'The plugin writes, using an API key from your account.', 'dicecodes-ai-blog-writer' ),
			array(
				__( 'Everything the plugin can do', 'dicecodes-ai-blog-writer' ),
				__( 'Writes on a schedule, unattended', 'dicecodes-ai-blog-writer' ),
				__( 'Research sources for its facts', 'dicecodes-ai-blog-writer' ),
				__( 'Free with a local model', 'dicecodes-ai-blog-writer' ),
			),
			array(
				__( 'Needs an API key, or a local model', 'dicecodes-ai-blog-writer' ),
				__( 'A paid provider bills per post', 'dicecodes-ai-blog-writer' ),
			)
		);

		self::render_path_option(
			'client',
			__( 'From Claude or ChatGPT', 'dicecodes-ai-blog-writer' ),
			__( 'Claude or ChatGPT writes, using the subscription you already pay for.', 'dicecodes-ai-blog-writer' ),
			array(
				__( 'Nothing to pay beyond your subscription', 'dicecodes-ai-blog-writer' ),
				__( 'The same checks and quality gate', 'dicecodes-ai-blog-writer' ),
				__( 'Your rules, pictures, categories and tags', 'dicecodes-ai-blog-writer' ),
				__( 'Nothing leaves your site', 'dicecodes-ai-blog-writer' ),
			),
			array(
				__( 'No scheduled or unattended writing', 'dicecodes-ai-blog-writer' ),
				__( 'No research sources — the app uses its own', 'dicecodes-ai-blog-writer' ),
				__( 'Needs a site on public HTTPS', 'dicecodes-ai-blog-writer' ),
			)
		);

		echo '</div>';

		self::render_path_table();
	}

	/**
	 * The same question again, row by row.
	 *
	 * The two cards say what each way is for. This says what each one does,
	 * in the words somebody uses to ask: can it put a picture on the post,
	 * does it cost anything, will it write while I am asleep. Rows rather
	 * than two lists, because "which of these does that" is answered by
	 * reading across and not by holding both lists in your head.
	 *
	 * @return void
	 */
	private static function render_path_table() {
		$yes = '<span class="bc-has is-yes">' . esc_html__( 'Yes', 'dicecodes-ai-blog-writer' ) . '</span>';
		$no  = '<span class="bc-has is-no">' . esc_html__( 'No', 'dicecodes-ai-blog-writer' ) . '</span>';

		$rows = array(
			array(
				__( 'Write a post whenever you ask', 'dicecodes-ai-blog-writer' ),
				$yes,
				$yes,
			),
			array(
				__( 'Write on a schedule while you are away', 'dicecodes-ai-blog-writer' ),
				$yes,
				$no,
			),
			array(
				__( 'Queue a list of topics in one go', 'dicecodes-ai-blog-writer' ),
				$yes,
				$no,
			),
			array(
				__( 'Put a picture on the post', 'dicecodes-ai-blog-writer' ),
				$yes,
				$yes,
			),
			array(
				__( 'Pictures without paying for them', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Yes, the free service needs no key', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Yes, the same free service', 'dicecodes-ai-blog-writer' ),
			),
			array(
				__( 'Reads current sources before writing', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Yes, from the sources you switch on', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Whatever your app can reach', 'dicecodes-ai-blog-writer' ),
			),
			array(
				__( 'Writes to your rules and your voice', 'dicecodes-ai-blog-writer' ),
				$yes,
				$yes,
			),
			array(
				__( 'Scores the draft and holds a bad one back', 'dicecodes-ai-blog-writer' ),
				$yes,
				$yes,
			),
			array(
				__( 'Sets the category, tags and byline', 'dicecodes-ai-blog-writer' ),
				$yes,
				$yes,
			),
			array(
				__( 'Points your older posts at the new one', 'dicecodes-ai-blog-writer' ),
				$yes,
				$yes,
			),
			array(
				__( 'Writes the search title and description', 'dicecodes-ai-blog-writer' ),
				$yes,
				$yes,
			),
			array(
				__( 'What each post costs you', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Your provider bills you, or nothing on a local model', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Nothing beyond the subscription you have', 'dicecodes-ai-blog-writer' ),
			),
			array(
				__( 'What you need before it works', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'An API key, or a model on your own computer', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'A Claude or ChatGPT subscription, and a site on public HTTPS', 'dicecodes-ai-blog-writer' ),
			),
		);

		echo '<div class="bc-path-table-wrap"><table class="bc-path-table">';
		echo '<thead><tr>';
		printf( '<th scope="col">%s</th>', esc_html__( 'What you get', 'dicecodes-ai-blog-writer' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'Inside WordPress', 'dicecodes-ai-blog-writer' ) );
		printf( '<th scope="col">%s</th>', esc_html__( 'From Claude or ChatGPT', 'dicecodes-ai-blog-writer' ) );
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr><th scope="row">%1$s</th><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( $row[0] ),
				wp_kses( $row[1], Blogcraft_Markup::allowed() ),
				wp_kses( $row[2], Blogcraft_Markup::allowed() )
			);
		}

		echo '</tbody></table></div>';
	}

	/**
	 * One of the two choices.
	 *
	 * @param string $path    Path key.
	 * @param string $title   What it is called.
	 * @param string $lead    One sentence on how it works.
	 * @param array  $gets    What you get.
	 * @param array  $lacks   What you do not.
	 * @return void
	 */
	private static function render_path_option( $path, $title, $lead, $gets, $lacks ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bc-path-option">';
		echo '<input type="hidden" name="action" value="blogcraft_choose_path" />';
		printf( '<input type="hidden" name="path" value="%s" />', esc_attr( $path ) );
		Blogcraft_Request::nonce_field( self::PATH_ACTION );

		printf( '<h3>%s</h3>', esc_html( $title ) );
		printf( '<p class="bc-path-how">%s</p>', esc_html( $lead ) );

		echo '<ul class="bc-path-gets">';
		foreach ( $gets as $line ) {
			printf( '<li>%s</li>', esc_html( $line ) );
		}
		echo '</ul>';

		echo '<ul class="bc-path-lacks">';
		foreach ( $lacks as $line ) {
			printf( '<li>%s</li>', esc_html( $line ) );
		}
		echo '</ul>';

		submit_button( __( 'Set this up', 'dicecodes-ai-blog-writer' ), 'primary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The line that says which way this site is set up, and offers the other.
	 *
	 * @return void
	 */
	private static function render_path_switch() {
		$is_api = ( 'api' === self::path() );

		echo '<div class="bc-path-now" id="bc-card-path">';

		printf(
			'<p class="bc-path-current"><strong>%1$s</strong> %2$s</p>',
			esc_html( $is_api ? __( 'Writing inside WordPress.', 'dicecodes-ai-blog-writer' ) : __( 'Writing from an AI client.', 'dicecodes-ai-blog-writer' ) ),
			esc_html(
				$is_api
					? __( 'The plugin calls a provider with your key.', 'dicecodes-ai-blog-writer' )
					: __( 'An app you already pay for connects here and does the writing.', 'dicecodes-ai-blog-writer' )
			)
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_choose_path" />';
		printf( '<input type="hidden" name="path" value="%s" />', esc_attr( $is_api ? 'client' : 'api' ) );
		Blogcraft_Request::nonce_field( self::PATH_ACTION );
		printf(
			'<button type="submit" class="button">%s</button>',
			esc_html( $is_api ? __( 'Switch to an AI client', 'dicecodes-ai-blog-writer' ) : __( 'Switch to an API key', 'dicecodes-ai-blog-writer' ) )
		);
		echo '</form>';

		printf(
			'<p class="bc-path-keep">%s</p>',
			esc_html__( 'Switching only changes what is shown here. Nothing is deleted.', 'dicecodes-ai-blog-writer' )
		);

		// The comparison of the two ways was shown while the question was
		// open and then never again, so anybody wondering whether the other
		// one suited them better had a switch button and nothing to base the
		// decision on. Folded away rather than gone: it is read once or
		// twice, and the strip above answers the everyday question.
		echo '<details class="bc-path-compare">';
		printf(
			'<summary>%s</summary>',
			esc_html__( 'What each way can do', 'dicecodes-ai-blog-writer' )
		);

		self::render_path_comparison( 'bc-path-review' );

		echo '</details>';

		echo '</div>';
	}

	/**
	 * Record which way this site is set up.
	 *
	 * @return void
	 */
	public static function handle_choose_path() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::PATH_ACTION, '_blogcraft_nonce' );

		$path = isset( $_POST['path'] ) ? sanitize_key( wp_unslash( $_POST['path'] ) ) : '';

		if ( in_array( $path, array( 'api', 'client' ), true ) ) {
			Blogcraft_Settings::set( 'setup_path', $path );
		}

		wp_safe_redirect( self::settings_url() );
		exit;
	}

	/**
	 * The providers already set up here, and the way between them.
	 *
	 * One provider in the settings meant comparing two was retyping the
	 * card, key and all, every time you wanted the other. Most people
	 * did that once.
	 *
	 * @return void
	 */
	private static function render_saved_providers() {
		$saved = Blogcraft_Connections::all();

		if ( ! empty( $saved ) ) {
			echo '<ul class="bc-saved">';

			foreach ( $saved as $id => $record ) {
				$live = Blogcraft_Connections::is_live( $record );

				printf( '<li class="bc-saved-item%s">', $live ? ' is-live' : '' );

				printf(
					'<span class="bc-saved-name">%1$s</span><span class="bc-saved-what">%2$s</span>',
					esc_html( $record['label'] ),
					esc_html( Blogcraft_Connections::describe( $record ) )
				);

				if ( $live ) {
					printf( '<span class="bc-saved-live">%s</span>', esc_html__( 'In use', 'dicecodes-ai-blog-writer' ) );
				} else {
					self::saved_button( $id, 'use', __( 'Use this', 'dicecodes-ai-blog-writer' ), 'button' );
				}

				self::saved_button( $id, 'forget', __( 'Forget', 'dicecodes-ai-blog-writer' ), 'button-link' );

				echo '</li>';
			}

			echo '</ul>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bc-saved-add">';
		echo '<input type="hidden" name="action" value="blogcraft_saved_provider" />';
		echo '<input type="hidden" name="do" value="save" />';
		Blogcraft_Request::nonce_field( self::SAVED_ACTION );
		printf(
			'<input type="text" name="label" class="regular-text" placeholder="%s" />',
			esc_attr__( 'Name this setup — "GPT-5", "my local model"', 'dicecodes-ai-blog-writer' )
		);
		printf(
			'<button type="submit" class="button">%s</button>',
			esc_html__( 'Save this one', 'dicecodes-ai-blog-writer' )
		);
		echo '</form>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Saves the key and model below, so you can switch back without retyping them.', 'dicecodes-ai-blog-writer' )
		);
	}

	/**
	 * One button that acts on one saved provider.
	 *
	 * A form each rather than links: switching providers and forgetting a
	 * key are both things a crawler should not be able to do by following
	 * a URL.
	 *
	 * @param string $id    Which saved setup.
	 * @param string $does  save, use or forget.
	 * @param string $label What the button says.
	 * @param string $style Button class.
	 * @return void
	 */
	private static function saved_button( $id, $does, $label, $style ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="blogcraft_saved_provider" />';
		printf( '<input type="hidden" name="do" value="%s" />', esc_attr( $does ) );
		printf( '<input type="hidden" name="id" value="%s" />', esc_attr( $id ) );
		Blogcraft_Request::nonce_field( self::SAVED_ACTION );
		printf(
			'<button type="submit" class="%1$s">%2$s</button>',
			esc_attr( $style ),
			esc_html( $label )
		);
		echo '</form>';
	}

	/**
	 * Save, switch to, or forget a provider.
	 *
	 * @return void
	 */
	public static function handle_saved_provider() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::SAVED_ACTION, '_blogcraft_nonce' );

		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to change providers on this site.', 'dicecodes-ai-blog-writer' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$does  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$id    = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'save' === $does ) {
			Blogcraft_Connections::save( $label );
		} elseif ( 'use' === $does ) {
			Blogcraft_Connections::activate( $id );
		} elseif ( 'forget' === $does ) {
			Blogcraft_Connections::remove( $id );
		}

		wp_safe_redirect( self::settings_url() );
		exit;
	}
	/**
	 * The card that lets an AI client drive this site.
	 *
	 * Its own renderer rather than a few rows in the provider card, because it
	 * is the other half of the same question — where the model comes from —
	 * and the two answers are not variations of each other. One spends a key
	 * you own; this one spends a subscription you already pay for, and moves
	 * the writing into an app outside WordPress.
	 *
	 * @return void
	 */
	private static function render_client_card() {
		self::open_card_for( 'clients' );

		printf(
			'<p class="bc-client-lead">%s</p>',
			esc_html__( 'An app you already pay for connects here and writes. Your rules, the checks and the publishing stay on this site.', 'dicecodes-ai-blog-writer' )
		);

		self::render_mcp_test_result();
		self::render_mcp_steps();
		self::render_mcp_tokens();
		self::render_mcp_limits();

		// Below the steps rather than above them: issuing a token switches
		// connections on, so this is here to turn them off again, not to be
		// found before anything else will appear.
		echo '<table class="form-table" role="presentation"><tbody>';
		self::checkbox_row( 'mcp_enabled', __( 'Accept connections from AI clients', 'dicecodes-ai-blog-writer' ) );
		echo '</tbody></table>';

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Cuts every connection off. Tokens are kept.', 'dicecodes-ai-blog-writer' )
		);

		self::close_card();
	}

	/**
	 * Where the last self-test result is kept for one reader.
	 *
	 * User meta rather than a transient read once and thrown away. The
	 * result explaining why a connection failed is the single most useful
	 * thing on this screen, and it used to disappear on the first page
	 * load after it appeared — including the load that happened when
	 * somebody scrolled up and refreshed to read it again.
	 */
	const MCP_TEST_META = 'blogcraft_mcp_test';

	/**
	 * Nonce action for dismissing that result.
	 */
	const MCP_TEST_ACTION = 'blogcraft_mcp_test_seen';

	/**
	 * The result of the check that ran when the token was issued.
	 *
	 * @return void
	 */
	private static function render_mcp_test_result() {
		$test = get_user_meta( get_current_user_id(), self::MCP_TEST_META, true );

		if ( ! is_array( $test ) || empty( $test ) ) {
			return;
		}

		$state = 'is-bad';

		if ( ! empty( $test['ok'] ) ) {
			$state = 'is-good';
		} elseif ( ! empty( $test['unknown'] ) ) {
			$state = 'is-unsure';
		}

		printf( '<div class="bc-mcp-test %s">', esc_attr( $state ) );
		printf( '<p>%s</p>', esc_html( (string) $test['message'] ) );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bc-mcp-test-dismiss">';
		echo '<input type="hidden" name="action" value="blogcraft_mcp_test_seen" />';
		Blogcraft_Request::nonce_field( self::MCP_TEST_ACTION );
		printf(
			'<button type="submit" class="button-link">%s</button>',
			esc_html__( 'Dismiss', 'dicecodes-ai-blog-writer' )
		);
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Forget it, once somebody says they have read it.
	 *
	 * @return void
	 */
	public static function handle_mcp_test_seen() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::MCP_TEST_ACTION, '_blogcraft_nonce' );

		delete_user_meta( get_current_user_id(), self::MCP_TEST_META );

		wp_safe_redirect( self::settings_url() );
		exit;
	}
	/**
	 * The address, and the steps for whichever app is being used.
	 *
	 * @return void
	 */
	private static function render_mcp_steps() {
		$endpoint = Blogcraft_Mcp::endpoint();

		printf( '<h3 class="bc-client-heading">%s</h3>', esc_html__( 'Connect your app', 'dicecodes-ai-blog-writer' ) );

		printf(
			'<p class="bc-client-lead">%s</p>',
			esc_html__( 'Paste this into your app. It sends you back here to approve.', 'dicecodes-ai-blog-writer' )
		);

		echo wp_kses( self::copyable( $endpoint, __( 'Copy address', 'dicecodes-ai-blog-writer' ) ), Blogcraft_Markup::allowed() );

		self::render_permalink_warning();
		self::render_mcp_clients( $endpoint );
	}

	/**
	 * Say so when signing in cannot work on this site.
	 *
	 * The discovery documents live at fixed addresses under /.well-known/,
	 * and on plain permalinks the web server never hands those to
	 * WordPress at all. The app then reports that the address is not an
	 * MCP server, which is both wrong and impossible to act on.
	 *
	 * @return void
	 */
	private static function render_permalink_warning() {
		if ( '' !== (string) get_option( 'permalink_structure' ) ) {
			return;
		}

		printf(
			'<p class="bc-mcp-warn">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html__( 'Signing in will not work while this site uses plain permalinks. Any other setting fixes it.', 'dicecodes-ai-blog-writer' ),
			esc_url( admin_url( 'options-permalink.php' ) ),
			esc_html__( 'Change it', 'dicecodes-ai-blog-writer' )
		);
	}

	/**
	 * A value with a button that copies it.
	 *
	 * Every one of these is a string somebody has to get into another
	 * window exactly right. Selecting a long address by hand and missing
	 * the last character produces an error that blames the server.
	 *
	 * @param string $value What to copy.
	 * @param string $label What the button says.
	 * @param bool   $block Whether it is long enough to want its own line.
	 * @return string Markup, already escaped.
	 */
	public static function copyable( $value, $label, $block = false ) {
		return sprintf(
			'<span class="bc-copy%4$s"><input type="text" class="large-text code" readonly="readonly" value="%1$s" /><button type="button" class="button bc-copy-button" data-copy="%1$s" aria-label="%3$s">%2$s</button><span class="bc-copy-said" role="status" aria-live="polite"></span></span>',
			esc_attr( $value ),
			esc_html__( 'Copy', 'dicecodes-ai-blog-writer' ),
			esc_attr( $label ),
			$block ? ' is-block' : ''
		);
	}

	/**
	 * Which app, and then that app only.
	 *
	 * This used to print every set of steps at once, under a general set
	 * of steps that said something slightly different. Nobody uses four
	 * applications. They use one, and the other three are there to make
	 * them wonder whether they are reading the right list.
	 *
	 * The panels are all in the markup and the script hides the ones not
	 * asked for, so a browser running no script shows everything rather
	 * than nothing.
	 *
	 * @param string $endpoint The address to connect to.
	 * @return void
	 */
	private static function render_mcp_clients( $endpoint ) {
		$guides = self::client_guides( $endpoint );

		printf(
			'<h3 class="bc-client-heading" id="bc-app-ask">%s</h3>',
			esc_html__( 'Which app are you using?', 'dicecodes-ai-blog-writer' )
		);

		// A list rather than a row of buttons: four of them ran the width
		// of the card and read as four things to do rather than one
		// question with four answers.
		echo '<div class="bc-app-pick" role="tablist" aria-labelledby="bc-app-ask">';

		foreach ( $guides as $slug => $guide ) {
			printf(
				'<button type="button" class="bc-app-tab" role="tab" id="bc-app-tab-%1$s" aria-controls="bc-app-panel-%1$s" aria-selected="false"><span class="bc-app-name">%2$s</span><span class="bc-app-needs">%3$s</span></button>',
				esc_attr( $slug ),
				esc_html( $guide['name'] ),
				esc_html( $guide['needs'] )
			);
		}

		echo '</div>';

		foreach ( $guides as $slug => $guide ) {
			printf(
				'<div class="bc-app-steps" id="bc-app-panel-%1$s" role="tabpanel" aria-labelledby="bc-app-tab-%1$s">',
				esc_attr( $slug )
			);

			echo '<ol class="bc-mcp-howto">';
			foreach ( $guide['steps'] as $step ) {
				printf( '<li>%s</li>', esc_html( $step ) );
			}
			echo '</ol>';

			if ( '' !== $guide['copy'] ) {
				// Already escaped by copyable(); kses keeps PHPCS satisfied.
				echo wp_kses( $guide['copy'], Blogcraft_Markup::allowed() );
			}

			if ( '' !== $guide['note'] ) {
				printf( '<p class="bc-app-note">%s</p>', esc_html( $guide['note'] ) );
			}

			echo '</div>';
		}
	}

	/**
	 * The apps worth naming, and the shortest true set of steps for each.
	 *
	 * @param string $endpoint The address to connect to.
	 * @return array
	 */
	private static function client_guides( $endpoint ) {
		return array(
			'claude'  => array(
				'name'  => __( 'Claude', 'dicecodes-ai-blog-writer' ),
				'needs' => __( 'The address only', 'dicecodes-ai-blog-writer' ),
				'steps' => array(
					__( 'Settings, then Connectors, then Add custom connector.', 'dicecodes-ai-blog-writer' ),
					__( 'Paste the address. The name is yours to choose.', 'dicecodes-ai-blog-writer' ),
					__( 'Press Add. It sends you to this site.', 'dicecodes-ai-blog-writer' ),
					__( 'Sign in if asked, then press Approve.', 'dicecodes-ai-blog-writer' ),
				),
				'copy'  => '',
				'note'  => __( 'Which model it writes with is Claude\'s own setting, not this site\'s.', 'dicecodes-ai-blog-writer' ),
			),
			'chatgpt' => array(
				'name'  => __( 'ChatGPT', 'dicecodes-ai-blog-writer' ),
				'needs' => __( 'The address only', 'dicecodes-ai-blog-writer' ),
				'steps' => array(
					__( 'Settings, Connectors, Advanced, then turn on Developer mode.', 'dicecodes-ai-blog-writer' ),
					__( 'Press Create and paste the address.', 'dicecodes-ai-blog-writer' ),
					__( 'Choose OAuth when it asks how to authenticate.', 'dicecodes-ai-blog-writer' ),
					__( 'Approve the connection when it sends you here.', 'dicecodes-ai-blog-writer' ),
				),
				'copy'  => '',
				'note'  => '',
			),
			'editor'  => array(
				'name'  => __( 'Claude Code, Cursor, VS Code', 'dicecodes-ai-blog-writer' ),
				'needs' => __( 'One command', 'dicecodes-ai-blog-writer' ),
				'steps' => array(
					__( 'Run this in your terminal.', 'dicecodes-ai-blog-writer' ),
					__( 'Approve the connection in the browser that opens.', 'dicecodes-ai-blog-writer' ),
				),
				'copy'  => self::copyable( 'claude mcp add --transport http dicecodes ' . $endpoint, __( 'Copy command', 'dicecodes-ai-blog-writer' ), true ),
				'note'  => '',
			),
			'other'   => array(
				'name'  => __( 'Something else', 'dicecodes-ai-blog-writer' ),
				'needs' => __( 'A token', 'dicecodes-ai-blog-writer' ),
				'steps' => array(
					__( 'Issue a token below and copy it.', 'dicecodes-ai-blog-writer' ),
					__( 'Set that app\'s authentication to None.', 'dicecodes-ai-blog-writer' ),
					__( 'Add a header named Authorization, with the value Bearer and then your token.', 'dicecodes-ai-blog-writer' ),
				),
				'copy'  => '',
				'note'  => __( 'For apps that cannot sign in.', 'dicecodes-ai-blog-writer' ),
			),
		);
	}
	/**
	 * The tokens issued for this site, and a way to make another.
	 *
	 * @return void
	 */
	private static function render_mcp_tokens() {
		printf( '<h3 class="bc-client-heading">%s</h3>', esc_html__( 'Connected apps', 'dicecodes-ai-blog-writer' ) );

		// Shown exactly once, and never from the address bar: a secret in a
		// URL is written into every server log and the browser's history,
		// which is the same mistake this plugin already fixed for the Gemini
		// key.
		$fresh = get_transient( 'blogcraft_mcp_new_' . get_current_user_id() );

		if ( is_string( $fresh ) && '' !== $fresh ) {
			delete_transient( 'blogcraft_mcp_new_' . get_current_user_id() );

			printf(
				'<div class="bc-token-fresh"><p><strong>%1$s</strong></p>%4$s<p class="description">%3$s</p></div>',
				esc_html__( 'Copy this now. It is not shown again.', 'dicecodes-ai-blog-writer' ),
				'',
				esc_html__( 'Only a fingerprint of it is stored here, so it cannot be looked up later. Lose it and issue another.', 'dicecodes-ai-blog-writer' ),
				wp_kses( self::copyable( $fresh, __( 'Copy token', 'dicecodes-ai-blog-writer' ) ), Blogcraft_Markup::allowed() )
			);
		}

		$connections = Blogcraft_Mcp_Auth::connections();

		if ( empty( $connections ) ) {
			printf(
				'<p class="bc-hint">%s</p>',
				esc_html__( 'Nothing is connected yet. Paste the address above into your app, or issue a token for one that cannot sign in.', 'dicecodes-ai-blog-writer' )
			);
		} else {
			echo '<table class="widefat striped bc-token-table"><thead><tr>';
			printf( '<th>%s</th>', esc_html__( 'App', 'dicecodes-ai-blog-writer' ) );
			printf( '<th>%s</th>', esc_html__( 'Status', 'dicecodes-ai-blog-writer' ) );
			printf( '<th>%s</th>', esc_html__( 'How', 'dicecodes-ai-blog-writer' ) );
			printf( '<th>%s</th>', esc_html__( 'Connected', 'dicecodes-ai-blog-writer' ) );
			printf( '<th>%s</th>', esc_html__( 'Last used', 'dicecodes-ai-blog-writer' ) );
			printf( '<th>%s</th>', esc_html__( 'Disconnect', 'dicecodes-ai-blog-writer' ) );
			echo '</tr></thead><tbody>';

			foreach ( $connections as $id => $one ) {
				$label = self::connection_name( $one );

				echo '<tr>';
				printf( '<td><strong>%s</strong></td>', esc_html( $label ) );

				// The word as well as the colour: a green dot beside nothing
				// is not something every reader can act on.
				printf(
					'<td><span class="bc-conn-state is-%1$s">%2$s</span></td>',
					esc_attr( $one['state'] ),
					esc_html( self::state_label( $one['state'] ) )
				);

				// Which way it got in. Somebody deciding whether to end a
				// connection wants to know whether ending it means the app has
				// to sign in again or somebody has to be handed a new token.
				printf(
					'<td>%s</td>',
					esc_html(
						$one['signed_in']
							? __( 'Signed in', 'dicecodes-ai-blog-writer' )
							: __( 'Token', 'dicecodes-ai-blog-writer' )
					)
				);

				printf(
					'<td>%s</td>',
					esc_html( wp_date( get_option( 'date_format' ), (int) $one['created'] ) )
				);

				printf(
					'<td>%s</td>',
					esc_html(
						empty( $one['used'] )
							? __( 'Never', 'dicecodes-ai-blog-writer' )
							: wp_date( get_option( 'date_format' ), (int) $one['used'] )
					)
				);

				echo '<td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				echo '<input type="hidden" name="action" value="blogcraft_mcp_revoke" />';
				printf( '<input type="hidden" name="connection" value="%s" />', esc_attr( $id ) );
				Blogcraft_Request::nonce_field( self::MCP_REVOKE_ACTION );
				printf(
					'<button type="submit" class="button-link delete">%s</button>',
					esc_html__( 'Disconnect', 'dicecodes-ai-blog-writer' )
				);
				echo '</form>';
				echo '</td></tr>';
			}

			echo '</tbody></table>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="bc-token-issue">';
		echo '<input type="hidden" name="action" value="blogcraft_mcp_issue" />';
		Blogcraft_Request::nonce_field( self::MCP_ISSUE_ACTION );
		printf(
			'<input type="text" name="label" class="regular-text" placeholder="%s" />',
			esc_attr__( 'What is it for — "my laptop", "Claude Desktop"', 'dicecodes-ai-blog-writer' )
		);
		echo ' ';
		submit_button( __( 'Issue a token', 'dicecodes-ai-blog-writer' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * How to describe the state of one connection.
	 *
	 * @param string $state One of never, idle, active.
	 * @return string
	 */
	private static function state_label( $state ) {
		if ( 'active' === $state ) {
			return __( 'Active', 'dicecodes-ai-blog-writer' );
		}

		if ( 'idle' === $state ) {
			return __( 'Idle', 'dicecodes-ai-blog-writer' );
		}

		return __( 'Never used', 'dicecodes-ai-blog-writer' );
	}

	/**
	 * What to call one connection.
	 *
	 * A token issued by hand carries whatever name it was given, which may
	 * be nothing. Two screens describing the same connection two different
	 * ways is how one of them ends up printing an empty string.
	 *
	 * @param array $one One connection.
	 * @return string
	 */
	private static function connection_name( $one ) {
		$label = isset( $one['label'] ) ? trim( (string) $one['label'] ) : '';

		return ( '' === $label ) ? __( 'Unnamed', 'dicecodes-ai-blog-writer' ) : $label;
	}

	/**
	 * What a connected client can and cannot do.
	 *
	 * On the card rather than in the documentation. Somebody who switches this
	 * on and then goes looking for scheduled posts has been let down by this
	 * screen, not by the plugin.
	 *
	 * @return void
	 */
	private static function render_mcp_limits() {
		printf( '<h3 class="bc-client-heading">%s</h3>', esc_html__( 'What a connected client can do', 'dicecodes-ai-blog-writer' ) );

		$can = array(
			__( 'Read your rules and published posts', 'dicecodes-ai-blog-writer' ),
			__( 'Score a draft and be told what to fix', 'dicecodes-ai-blog-writer' ),
			__( 'Create, revise and re-read drafts here', 'dicecodes-ai-blog-writer' ),
			__( 'Publish above your threshold, with pictures, tags and links added', 'dicecodes-ai-blog-writer' ),
		);

		$cannot = array(
			__( 'Write on a schedule — that needs an API key', 'dicecodes-ai-blog-writer' ),
			__( 'Touch a post it did not write', 'dicecodes-ai-blog-writer' ),
			__( 'Use your research sources — it uses its own', 'dicecodes-ai-blog-writer' ),
			__( 'Read anything a visitor could not already see', 'dicecodes-ai-blog-writer' ),
		);

		echo '<div class="bc-client-limits">';

		echo '<ul class="bc-can">';
		foreach ( $can as $line ) {
			printf( '<li>%s</li>', esc_html( $line ) );
		}
		echo '</ul>';

		echo '<ul class="bc-cannot">';
		foreach ( $cannot as $line ) {
			printf( '<li>%s</li>', esc_html( $line ) );
		}
		echo '</ul>';

		echo '</div>';
	}

	/**
	 * Issue a connection token.
	 *
	 * @return void
	 */
	public static function handle_mcp_issue() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::MCP_ISSUE_ACTION, '_blogcraft_nonce' );

		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		// Pressing this button is not an ambiguous statement of intent, so it
		// is also the switch. Requiring a tick, a save, and then a second
		// visit to find the button was the whole reason this screen felt like
		// work.
		Blogcraft_Settings::set( 'mcp_enabled', true );
		$secret = Blogcraft_Mcp_Auth::issue( get_current_user_id(), $label );

		if ( '' === $secret ) {
			self::redirect_back( false, __( 'The token could not be created.', 'dicecodes-ai-blog-writer' ) );
		}

		// Held for one minute, for one user, and deleted on first render.
		// Long enough to survive the redirect, short enough that it is not
		// sitting in the options table afterwards.
		set_transient( 'blogcraft_mcp_new_' . get_current_user_id(), $secret, MINUTE_IN_SECONDS );

		// Prove it works before telling anybody it does. A token that looks
		// fine and an address that answers are two different claims, and
		// every fault this feature has had was only visible from a real
		// request.
		$test = Blogcraft_Mcp::self_test( $secret );

		update_user_meta( get_current_user_id(), self::MCP_TEST_META, $test );

		wp_safe_redirect( self::settings_url( 'bc-card-clients' ) );
		exit;
	}

	/**
	 * Revoke one.
	 *
	 * @return void
	 */
	public static function handle_mcp_revoke() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::MCP_REVOKE_ACTION, '_blogcraft_nonce' );

		// A connection, not a credential. An app that signed itself in holds
		// two tokens, and taking away one of them is a disconnection that
		// appears to work and then does not: it renews itself and carries on.
		$connection = isset( $_POST['connection'] ) ? sanitize_text_field( wp_unslash( $_POST['connection'] ) ) : '';

		if ( '' !== $connection ) {
			Blogcraft_Mcp_Auth::disconnect( $connection );
		}

		wp_safe_redirect( self::settings_url( 'bc-card-clients' ) );
		exit;
	}

	/**
	 * This screen, optionally at one of its cards.
	 *
	 * @param string $anchor Card id, or ''.
	 * @return string
	 */
	private static function settings_url( $anchor = '' ) {
		$url = admin_url( 'admin.php?page=blogcraft-settings' );

		return ( '' === $anchor ) ? $url : $url . '#' . $anchor;
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
			esc_html( $needed ? __( 'Required', 'dicecodes-ai-blog-writer' ) : __( 'Optional', 'dicecodes-ai-blog-writer' ) )
		);

		self::render_help( $slug );

		echo '</header>';
	}

	/**
	 * Which section of the documentation explains each card.
	 *
	 * The paragraphs that used to sit here, folded away behind a control
	 * on every card, are on the documentation page now. Two copies of an
	 * explanation means one of them is the older, and it was this one:
	 * shipping a correction meant shipping a release.
	 *
	 * @return array Card slug => documentation anchor.
	 */
	private static function help_text() {
		return array(
			'provider'   => array( 'anchor' => 'providers' ),
			'clients'    => array( 'anchor' => 'clients' ),
			'pictures'   => array( 'anchor' => 'pictures' ),
			'research'   => array( 'anchor' => 'research' ),
			'voice'      => array( 'anchor' => 'voice' ),
			'removal'    => array( 'anchor' => 'removal' ),
			'automation' => array( 'anchor' => 'automation' ),
			'test'       => array( 'anchor' => 'checking-it-works' ),
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

		// A bare question mark in a corner is a control nobody finds, so it
		// says what it does. It opens the section for this card, away from
		// the form being filled in.
		Blogcraft_Docs::link(
			$all[ $slug ]['anchor'],
			__( 'How this works', 'dicecodes-ai-blog-writer' ),
			'bc-help-toggle'
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
		$sections = self::visible_cards();

		echo '<div class="bc-jump-col">';
		echo '<nav class="bc-jump" aria-label="' . esc_attr__( 'Sections on this page', 'dicecodes-ai-blog-writer' ) . '">';
		printf( '<h2 class="bc-jump-title">%s</h2>', esc_html__( 'On this page', 'dicecodes-ai-blog-writer' ) );

		$step = 1;

		foreach ( $sections as $slug => $parts ) {
			printf(
				'<a class="bc-jump-item" href="#bc-card-%1$s" data-target="bc-card-%1$s"><span class="bc-jump-step">%2$02d</span><span class="bc-jump-text"><span class="bc-jump-label">%3$s</span><span class="bc-jump-sub">%4$s</span></span></a>',
				esc_attr( $slug ),
				(int) $step,
				esc_html( $parts['title'] ),
				esc_html( $parts['sub'] )
			);
			++$step;
		}

		echo '</nav>';

		// The rail sits outside the form so it can stay put while the page
		// scrolls, so the button names the form it belongs to.
		printf(
			'<button type="submit" form="blogcraft-settings-form" class="bc-jump-save">%s</button>',
			esc_html__( 'Save settings', 'dicecodes-ai-blog-writer' )
		);

		echo '</div>';
	}

	/**
	 * Show what is still missing, read from the real settings.
	 *
	 * @return void
	 */
	private static function render_status() {
		// Each item names the thing in use rather than reporting that one
		// exists. "Provider connected" answers a question nobody standing
		// here is asking; "OpenAI, gpt-5" answers the one they are.
		//
		// Both wordings are carried because colour alone is not something
		// every reader can act on: a grey dot beside "Pictures on" reads as
		// a claim that they are.
		echo '<ul class="blogcraft-status">';

		foreach ( self::status_items() as $item ) {
			printf(
				'<li class="%1$s"><span class="bc-status-what">%2$s</span> <span class="bc-status-is">%3$s</span></li>',
				$item['done'] ? 'is-done' : '',
				esc_html( $item['what'] ),
				esc_html( $item['is'] )
			);
		}

		echo '</ul>';
	}

	/**
	 * What is set up on this site, and what it is set to.
	 *
	 * @return array
	 */
	private static function status_items() {
		$items = array();

		if ( Blogcraft_Mode::is_client() ) {
			$connected = Blogcraft_Mcp_Auth::connections();

			// Named the same way the table below names them. Plucking the raw
			// labels put an empty one in the list, and a token issued by hand
			// has no label, so the strip read "Writing , Claude".
			$named = array();

			foreach ( $connected as $one ) {
				$named[] = self::connection_name( $one );
			}

			$items[] = array(
				'what' => __( 'Writing', 'dicecodes-ai-blog-writer' ),
				'is'   => empty( $named )
					? __( 'no app connected', 'dicecodes-ai-blog-writer' )
					: implode( ', ', $named ),
				'done' => ! empty( $named ),
			);
		} else {
			$type  = (string) Blogcraft_Settings::get( 'provider_type' );
			$types = Blogcraft_Provider_Registry::types();
			$model = trim( (string) Blogcraft_Settings::get( 'provider_model' ) );
			$ready = Blogcraft_Provider_Registry::is_configured();

			$named = isset( $types[ $type ] ) ? $types[ $type ] : $type;

			$items[] = array(
				'what' => __( 'Writing', 'dicecodes-ai-blog-writer' ),
				'is'   => $ready
					? trim( $named . ( '' === $model ? '' : ', ' . $model ) )
					: self::missing_label(),
				'done' => $ready,
			);
		}

		$blueprint = Blogcraft_Blueprint::get();
		$described = '' !== trim( (string) $blueprint['niche'] );

		$items[] = array(
			'what' => __( 'Voice', 'dicecodes-ai-blog-writer' ),
			'is'   => $described
				? __( 'described', 'dicecodes-ai-blog-writer' )
				: __( 'not described', 'dicecodes-ai-blog-writer' ),
			'done' => $described,
		);

		// Research is the provider path only: an app brings its own.
		if ( Blogcraft_Mode::is_api() ) {
			$sources = array();

			foreach ( Blogcraft_Research::free_sources() as $setting => $label ) {
				if ( Blogcraft_Settings::get( $setting ) ) {
					// The setting labels explain themselves at length; the
					// strip has room for the name and nothing after it.
					$sources[] = trim( strtok( $label, chr( 226 ) . '-' ) );
				}
			}

			$paid = trim( (string) Blogcraft_Settings::get( 'research_provider' ) );

			if ( '' !== $paid ) {
				$sources[] = $paid;
			}

			$items[] = array(
				'what' => __( 'Research', 'dicecodes-ai-blog-writer' ),
				'is'   => empty( $sources )
					? __( 'writing from memory', 'dicecodes-ai-blog-writer' )
					: implode( ', ', $sources ),
				'done' => ! empty( $sources ),
			);
		}

		$pictures = (bool) Blogcraft_Settings::get( 'images_enabled' );
		$service  = trim( (string) Blogcraft_Settings::get( 'image_provider' ) );

		$items[] = array(
			'what' => __( 'Pictures', 'dicecodes-ai-blog-writer' ),
			'is'   => $pictures
				? ( '' === $service ? __( 'on', 'dicecodes-ai-blog-writer' ) : $service )
				: __( 'off', 'dicecodes-ai-blog-writer' ),
			'done' => $pictures,
		);

		// Scheduled writing cannot happen on the client path at all, so
		// reporting it off there would imply it could be turned on.
		if ( Blogcraft_Mode::is_api() ) {
			$on   = (bool) Blogcraft_Settings::get( 'autopilot_enabled' );
			$days = Blogcraft_Autopilot::days();

			$items[] = array(
				'what' => __( 'Automation', 'dicecodes-ai-blog-writer' ),
				'is'   => self::automation_label( $on, $days ),
				'done' => $on && array() !== $days,
			);
		}

		return $items;
	}

	/**
	 * How to describe the state of scheduled writing.
	 *
	 * @param bool  $on   Whether it is switched on.
	 * @param array $days The days it is set to run.
	 * @return string
	 */
	private static function automation_label( $on, $days ) {
		if ( ! $on ) {
			return __( 'off', 'dicecodes-ai-blog-writer' );
		}

		if ( array() === $days ) {
			return __( 'on, but no days chosen', 'dicecodes-ai-blog-writer' );
		}

		return sprintf(
			/* translators: %d: how many days a week posts are written. */
			_n( 'on, %d day a week', 'on, %d days a week', count( $days ), 'dicecodes-ai-blog-writer' ),
			count( $days )
		);
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
			return __( 'Model name missing', 'dicecodes-ai-blog-writer' );
		}

		if ( ! $has_key && $has_model ) {
			return __( 'API key missing', 'dicecodes-ai-blog-writer' );
		}

		return __( 'No provider yet', 'dicecodes-ai-blog-writer' );
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

		echo '<tr><th scope="row">' . esc_html__( 'Write on', 'dicecodes-ai-blog-writer' ) . '</th><td>';
		echo '<fieldset class="blogcraft-days">';
		printf(
			'<legend class="screen-reader-text">%s</legend>',
			esc_html__( 'Days of the week to write on', 'dicecodes-ai-blog-writer' )
		);

		for ( $offset = 0; $offset <= 6; $offset++ ) {
			$day = ( $start + $offset ) % 7;

			printf(
				'<label class="blogcraft-day"><input form="blogcraft-settings-form" type="checkbox" name="autopilot_days[]" value="%1$d"%2$s /> %3$s</label>',
				(int) $day,
				checked( in_array( $day, $chosen, true ), true, false ),
				esc_html( isset( $names[ $day ] ) ? $names[ $day ] : (string) $day )
			);
		}

		echo '</fieldset>';

		// Automation switched on with no days chosen looks configured and does
		// nothing at all, which is the worst of both.
		if ( empty( $chosen ) && Blogcraft_Settings::get( 'autopilot_enabled' ) ) {
			echo '<p class="blogcraft-callout">' . esc_html__( 'Automatic writing is switched on, but no days are ticked, so nothing will ever be written. Choose at least one day.', 'dicecodes-ai-blog-writer' ) . '</p>';
		}

		echo '<p class="description">' . esc_html__( 'In your site timezone. Posting every single day, weekends included, is one of the clearer signs of an unattended blog.', 'dicecodes-ai-blog-writer' ) . '</p>';
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

		echo '<tr><th scope="row"><label for="blogcraft_autopilot_hour">' . esc_html__( 'Starting at', 'dicecodes-ai-blog-writer' ) . '</label></th><td>';
		echo '<select form="blogcraft-settings-form" name="autopilot_hour" id="blogcraft_autopilot_hour">';

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
		echo '<p class="description">' . esc_html__( 'The earliest a post is started. WordPress only runs scheduled work when someone visits the site, so a quiet morning can push it later.', 'dicecodes-ai-blog-writer' ) . '</p>';
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
			'<tr class="%4$s"><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><input form="blogcraft-settings-form" type="text" class="regular-text" name="%1$s" id="blogcraft_%1$s" value="%3$s" placeholder="%6$s" autocomplete="off" spellcheck="false" />%5$s</td></tr>',
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
			'<tr><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><textarea form="blogcraft-settings-form" name="%1$s" id="blogcraft_%1$s" rows="3" class="large-text">%3$s</textarea><p class="description">%4$s</p></td></tr>',
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
			'<tr class="%5$s"><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><input form="blogcraft-settings-form" type="password" class="regular-text" name="%1$s" id="blogcraft_%1$s" value="" autocomplete="new-password" placeholder="%3$s" /><p class="description">%4$s</p>%6$s</td></tr>',
			esc_attr( $name ),
			esc_html( $label ),
			esc_attr( '' === $stored ? __( 'Not set', 'dicecodes-ai-blog-writer' ) : Blogcraft_Crypto::mask( $stored ) ),
			esc_html__( 'Leave blank to keep the saved key.', 'dicecodes-ai-blog-writer' ),
			esc_attr( $row_class ),
			wp_kses( self::clear_key_control( $name, $stored ), Blogcraft_Markup::allowed() )
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
			__( 'Cheaper model for the bulk', 'dicecodes-ai-blog-writer' ),
			'',
			__( 'Optional, and the same key and provider — only the model id differs. Most of a post\'s words are the section-by-section writing, which is carrying out a plan the outline already made. Naming a cheaper model here uses it for those sections, the questions and the extra blocks, while the outline, the opening, the critique and the rewrite stay on the model above, because those are the steps where judgement changes the result. Leave it blank to use one model for everything.', 'dicecodes-ai-blog-writer' )
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
			esc_html__( 'Show the models on my account', 'dicecodes-ai-blog-writer' ),
			esc_html__( 'Pick a model…', 'dicecodes-ai-blog-writer' ),
			esc_html__( 'Asks your provider which models your key can use, and fills the box above. Nothing is bundled, so this list is never out of date.', 'dicecodes-ai-blog-writer' )
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
			'<label class="blogcraft-clear-key"><input form="blogcraft-settings-form" type="checkbox" name="clear_%1$s" value="1" /> %2$s</label>',
			esc_attr( $name ),
			esc_html__( 'Remove this key', 'dicecodes-ai-blog-writer' )
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

		self::secret_row( 'fal_api_key', __( 'fal.ai API key', 'dicecodes-ai-blog-writer' ), 'blogcraft-image-fal' );
		self::provider_link_row(
			__( 'Where to get it', 'dicecodes-ai-blog-writer' ),
			$fal['key_url'],
			__( 'Create a fal.ai key', 'dicecodes-ai-blog-writer' ),
			'',
			'blogcraft-image-fal'
		);

		self::text_row( 'fal_model', __( 'fal.ai model', 'dicecodes-ai-blog-writer' ), 'blogcraft-image-fal' );
		self::provider_link_row(
			__( 'Which model', 'dicecodes-ai-blog-writer' ),
			$fal['models_url'],
			__( 'Browse text-to-image models', 'dicecodes-ai-blog-writer' ),
			__( 'Paste the id exactly as the model page shows it, for example fal-ai/flux/schnell. Schnell is the cheapest and fastest. A pro FLUX model looks better and costs more. Ideogram is the one to pick if you need legible words in the picture.', 'dicecodes-ai-blog-writer' ),
			'blogcraft-image-fal'
		);

		foreach ( array( 'gemini', 'xai' ) as $service ) {
			$spec  = Blogcraft_Image_Models::help( $service );
			$class = 'blogcraft-image-' . $service;

			self::secret_row(
				'image_key_' . $service,
				sprintf(
					/* translators: %s: the service name, such as Google AI Studio. */
					__( '%s image key', 'dicecodes-ai-blog-writer' ),
					$spec['label']
				),
				$class
			);

			self::text_row(
				'image_model_' . $service,
				__( 'Image model', 'dicecodes-ai-blog-writer' ),
				$class
			);

			self::provider_link_row(
				__( 'Which model', 'dicecodes-ai-blog-writer' ),
				$spec['models_url'],
				__( 'See the image models', 'dicecodes-ai-blog-writer' ),
				(string) Blogcraft_Settings::get( 'provider_type' ) === $service
					? __( 'You are already writing with this provider, so leave the key blank and the same one draws the pictures. You still need to name an image model.', 'dicecodes-ai-blog-writer' )
					: __( 'Your writing provider is a different company, so a key for this one is needed above.', 'dicecodes-ai-blog-writer' ),
				$class
			);
		}

		self::secret_row( 'openai_image_key', __( 'OpenAI image key', 'dicecodes-ai-blog-writer' ), 'blogcraft-image-openai' );
		self::text_row( 'openai_image_model', __( 'OpenAI image model', 'dicecodes-ai-blog-writer' ), 'blogcraft-image-openai' );
		self::provider_link_row(
			__( 'Which model', 'dicecodes-ai-blog-writer' ),
			$openai['models_url'],
			__( 'OpenAI image guide', 'dicecodes-ai-blog-writer' ),
			// Whether one key covers both depends entirely on who wrote the
			// key, so say which case the reader is actually in rather than
			// making them work it out.
			'openai' === (string) Blogcraft_Settings::get( 'provider_type' )
				? __( 'You are writing with OpenAI, so leave the key above blank and the same key makes the pictures. One key, one bill. You still need to name an image model here.', 'dicecodes-ai-blog-writer' )
				: __( 'Your writing provider is not OpenAI, so a separate OpenAI key is needed above. A key from one company will not work at another.', 'dicecodes-ai-blog-writer' ),
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
			'<tr><th scope="row"><label for="blogcraft_%1$s">%2$s</label></th><td><input form="blogcraft-settings-form" type="number" min="0" class="small-text" name="%1$s" id="blogcraft_%1$s" value="%3$s" /><p class="description">%4$s</p></td></tr>',
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
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::SAVE_ACTION, '_blogcraft_nonce' );

		list( $was_usable, $submitted_key, $failed ) = self::apply_submitted_settings();

		if ( ! empty( $failed ) ) {
			self::redirect_back(
				false,
				__( 'Your keys could not be stored. Dicecodes AI Blog Writer encrypts them before saving, and that needs PHP\'s sodium extension, which this server does not have. Ask your host to enable it — nothing else on this screen is affected.', 'dicecodes-ai-blog-writer' )
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
		// Checked here as well as in the caller. A private helper that
		// reads $_POST on the strength of a check made somewhere else is
		// one refactor away from reading it on no check at all, and
		// nothing at this line says which.
		check_admin_referer( self::SAVE_ACTION, '_blogcraft_nonce' );

		$was_usable = Blogcraft_Provider_Registry::is_configured();

		$plain = array_merge(
			array_keys( self::common_fields() ),
			array_keys( self::custom_fields() ),
			array_keys( self::voice_text_fields() ),
			array_keys( self::voice_area_fields() ),
			array( 'provider_type', 'provider_draft_model', 'provider_request_template', 'autopilot_topics', 'autopilot_status', 'research_provider', 'research_base_url', 'research_urls', 'image_provider', 'fal_model', 'openai_image_model', 'image_model_gemini', 'image_model_xai', 'author_credentials', 'reviewer_name', 'reviewer_credentials' )
		);

		foreach ( $plain as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				// Blogcraft_Settings::set() sanitises per the schema type for this key.
				Blogcraft_Settings::set( $key, sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		if ( isset( $_POST['monthly_token_cap'] ) ) {
			Blogcraft_Settings::set( 'monthly_token_cap', (int) $_POST['monthly_token_cap'] );
		}

		if ( isset( $_POST['monthly_image_cap'] ) ) {
			Blogcraft_Settings::set( 'monthly_image_cap', max( 0, (int) $_POST['monthly_image_cap'] ) );
		}

		if ( isset( $_POST['autopilot_per_day'] ) ) {
			Blogcraft_Settings::set( 'autopilot_per_day', (int) $_POST['autopilot_per_day'] );
		}

		// Both are plain number inputs rendered by number_row() alongside the
		// fields above, but neither was ever in a list this method reads —
		// the value the user typed was shown back to them as "Settings saved."
		// and then thrown away. The threshold in particular is load-bearing:
		// it is what "held for review instead of published" means.
		if ( isset( $_POST['quality_threshold'] ) ) {
			Blogcraft_Settings::set( 'quality_threshold', max( 0, min( 100, (int) $_POST['quality_threshold'] ) ) );
		}

		if ( isset( $_POST['refresh_after_days'] ) ) {
			Blogcraft_Settings::set( 'refresh_after_days', max( 1, (int) $_POST['refresh_after_days'] ) );
		}

		if ( isset( $_POST['autopilot_hour'] ) ) {
			Blogcraft_Settings::set( 'autopilot_hour', max( 0, min( 23, (int) $_POST['autopilot_hour'] ) ) );
		}

		// Checkboxes post an array, and post nothing at all when none are ticked.
		// Storing an empty string is correct there: no days means no automatic
		// writing, which is exactly what unticking every box asks for.
		$days = array();

		if ( isset( $_POST['autopilot_days'] ) && is_array( $_POST['autopilot_days'] ) ) {
			foreach ( map_deep( wp_unslash( $_POST['autopilot_days'] ), 'sanitize_key' ) as $day ) {
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
			Blogcraft_Settings::set( $toggle, isset( $_POST[ $toggle ] ) );
		}

		foreach ( array_keys( self::picture_toggles() ) as $toggle ) {
			Blogcraft_Settings::set( $toggle, isset( $_POST[ $toggle ] ) );
		}

		foreach ( array_keys( self::toggle_fields() ) as $toggle ) {
			Blogcraft_Settings::set( $toggle, isset( $_POST[ $toggle ] ) );
		}

		// One of those toggles decides whether this plugin prints structured
		// data at all, and the answer to "is there a second copy" is cached
		// for half a day. Without this, switching it off leaves the warning
		// about it on screen until the cache expires.
		Blogcraft_Schema_Watch::forget();

		// An empty key field means "leave unchanged": the form renders a mask
		// rather than the real value, so treating blank as "clear" would wipe
		// the stored key every time an unrelated field was saved. Removing one
		// is done with the tick beside it, which is the only way to say so
		// deliberately — and without it there was no way to remove a key at
		// all, short of editing the database.
		$failed = array();

		foreach ( self::secret_fields() as $secret ) {
			if ( isset( $_POST[ 'clear_' . $secret ] ) ) {
				Blogcraft_Settings::delete( $secret );

				continue;
			}

			$value = isset( $_POST[ $secret ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $secret ] ) ) ) : '';

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

			// Remember whose key this is, so switching provider can say
			// honestly that nothing is saved for the new one.
			if ( 'provider_api_key' === $secret ) {
				Blogcraft_Settings::set( 'provider_key_owner', (string) Blogcraft_Settings::get( 'provider_type' ) );
			}
		}

		$submitted_key = isset( $_POST['provider_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['provider_api_key'] ) ) ) : '';

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
		$saved = __( 'Settings saved.', 'dicecodes-ai-blog-writer' );

		// Check on a new key, and on the save that first completes the setup.
		// Someone who pastes a key one day and adds the model the next never
		// submits both at once, and that is exactly when they need telling.
		$now_usable = Blogcraft_Provider_Registry::is_configured();

		if ( ! $key_changed && ! ( $now_usable && ! $was_usable ) ) {
			return $saved;
		}

		if ( '' === trim( (string) Blogcraft_Settings::get( 'provider_model' ) ) ) {
			return $saved . ' ' . __( 'Add a model name before it can write anything.', 'dicecodes-ai-blog-writer' );
		}

		$provider = Blogcraft_Provider_Registry::from_settings();

		if ( null === $provider ) {
			return $saved;
		}

		$probe = Blogcraft_Provider_Registry::probe( $provider );

		if ( empty( $probe['reachable'] ) ) {
			return $saved . ' ' . sprintf(
				/* translators: %s: reason the provider gave. */
				__( 'The key did not work: %s', 'dicecodes-ai-blog-writer' ),
				self::shorten( (string) $probe['error'] )
			);
		}

		return $saved . ' ' . __( 'The key works.', 'dicecodes-ai-blog-writer' );
	}

	/**
	 * Run a live connection test.
	 *
	 * @return void
	 */
	public static function handle_test() {
		if ( ! current_user_can( Blogcraft_Capabilities::MANAGE ) ) {
			wp_die(
				esc_html__( 'You are not allowed to perform this action.', 'dicecodes-ai-blog-writer' ),
				esc_html__( 'Permission denied', 'dicecodes-ai-blog-writer' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::TEST_ACTION, '_blogcraft_nonce' );

		// Testing with nothing filled in spends a round trip to be told what we
		// already know, and returns the provider's own wording for it, which
		// talks about Authorization headers this plugin sets for you.
		if ( ! Blogcraft_Provider_Registry::is_configured() ) {
			self::redirect_back(
				false,
				__( 'Fill in a model and an API key first, then save, then test.', 'dicecodes-ai-blog-writer' )
			);
		}

		$provider = Blogcraft_Provider_Registry::from_settings();

		if ( null === $provider ) {
			self::redirect_back( false, __( 'No provider is configured yet.', 'dicecodes-ai-blog-writer' ) );
		}

		$probe = Blogcraft_Provider_Registry::probe( $provider );

		if ( empty( $probe['reachable'] ) ) {
			self::redirect_back(
				false,
				sprintf(
					/* translators: %s: error reported by the provider. */
					__( 'Connection failed: %s', 'dicecodes-ai-blog-writer' ),
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
					'dicecodes-ai-blog-writer'
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
			return __( 'the provider gave no reason.', 'dicecodes-ai-blog-writer' );
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
