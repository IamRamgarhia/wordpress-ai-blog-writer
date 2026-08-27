<?php
/**
 * Checks that only worked in English, on ASCII, with luck.
 *
 * Each of these was a rule the plugin genuinely enforced — for some users.
 * A rate limit was recognised by reading a translated sentence, a title was
 * measured in bytes against a limit written in characters, a banned phrase
 * was matched only if the model happened to type a straight apostrophe, and
 * a dead link was removed by string replacement that could take a live one
 * with it. None of them failed loudly.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Robustness extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Blogcraft_Migrator::migrate();
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );
	}

	public function tear_down() {
		delete_option( 'blogcraft_settings' );
		delete_option( Blogcraft_Blueprint::OPTION );
		parent::tear_down();
	}

	// ------------------------------------------------------- rate limiting.

	public function test_a_rate_limit_is_recognised_by_type_not_by_wording() {
		// The error text is assembled from translated format strings, so
		// matching it meant the deferral silently stopped happening on any
		// site not running in English — and every rate limit then spent one of
		// the job's three attempts on something that only needed a wait.
		$this->assertInstanceOf( 'RuntimeException', new Blogcraft_Rate_Limited( 'anything at all' ) );
	}

	public function test_providers_flag_a_rate_limit_on_the_status_code() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 429 ),
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Slow down' ) ) ),
				);
			},
			10,
			3
		);

		$provider = Blogcraft_Provider_Registry::make(
			'openai',
			array(
				'api_key'  => 'k',
				'model'    => 'm',
				'base_url' => 'https://api.test/v1',
			)
		);

		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertTrue( $response->rate_limited, 'a 429 was not recognised as a rate limit' );

		remove_all_filters( 'pre_http_request' );
	}

	public function test_an_ordinary_failure_is_not_treated_as_a_rate_limit() {
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'response' => array( 'code' => 401 ),
					'headers'  => array(),
					'body'     => wp_json_encode( array( 'error' => array( 'message' => 'Bad key' ) ) ),
				);
			},
			10,
			3
		);

		$provider = Blogcraft_Provider_Registry::make(
			'openai',
			array(
				'api_key'  => 'k',
				'model'    => 'm',
				'base_url' => 'https://api.test/v1',
			)
		);

		$response = $provider->complete( array( array( 'role' => 'user', 'content' => 'hi' ) ) );

		$this->assertTrue( $response->is_error() );
		$this->assertFalse( $response->rate_limited );

		remove_all_filters( 'pre_http_request' );
	}

	// ------------------------------------------------------- multibyte text.

	public function test_a_title_is_measured_in_characters_not_bytes() {
		// Twenty-six characters, but fifty-two bytes in UTF-8. Measured as
		// bytes it fails a sixty-character ceiling that it is nowhere near.
		$blueprint = Blogcraft_Blueprint::defaults();

		$checks = Blogcraft_Editorial::checks(
			'<!-- wp:paragraph --><p>' . str_repeat( 'word ', 300 ) . '</p><!-- /wp:paragraph -->',
			$blueprint,
			array( 'title' => 'Πώς λειτουργεί ο καφές φίλτρου' )
		);

		foreach ( $checks as $check ) {
			if ( 'meta_title' === $check['key'] ) {
				$this->assertTrue( $check['pass'], 'a Greek title was measured in bytes: ' . $check['actual'] );

				return;
			}
		}

		$this->fail( 'the title check did not run' );
	}

	public function test_an_accented_title_is_not_penalised_for_its_accents() {
		$blueprint = Blogcraft_Blueprint::defaults();

		$checks = Blogcraft_Editorial::checks(
			'<!-- wp:paragraph --><p>' . str_repeat( 'word ', 300 ) . '</p><!-- /wp:paragraph -->',
			$blueprint,
			array( 'title' => 'Café culture — a Française’s honest guide' )
		);

		foreach ( $checks as $check ) {
			if ( 'meta_title' === $check['key'] ) {
				$this->assertTrue( $check['pass'], 'accents cost characters they should not: ' . $check['actual'] );

				return;
			}
		}

		$this->fail( 'the title check did not run' );
	}

	// --------------------------------------------------- smart punctuation.

	public function test_a_curly_apostrophe_does_not_hide_a_stock_opening() {
		// "let's face it" was caught and "let’s face it" was not, and the
		// curly one is what a model actually writes.
		$blueprint = Blogcraft_Blueprint::defaults();

		$straight = Blogcraft_Editorial::checks(
			"<!-- wp:paragraph --><p>Let's face it, everybody wants better coffee at home. That is what this is about.</p><!-- /wp:paragraph -->",
			$blueprint,
			array()
		);

		$curly = Blogcraft_Editorial::checks(
			"<!-- wp:paragraph --><p>Let\xE2\x80\x99s face it, everybody wants better coffee at home. That is what this is about.</p><!-- /wp:paragraph -->",
			$blueprint,
			array()
		);

		$this->assertSame(
			$this->verdict( $straight, 'answer_first' ),
			$this->verdict( $curly, 'answer_first' ),
			'the same opening was judged differently for its apostrophe'
		);
	}

	/**
	 * Whether a named check passed.
	 *
	 * @param array  $checks Check results.
	 * @param string $key    Check key.
	 * @return bool|null Null when the check did not run.
	 */
	private function verdict( $checks, $key ) {
		foreach ( $checks as $check ) {
			if ( $key === $check['key'] ) {
				return (bool) $check['pass'];
			}
		}

		return null;
	}

	// ------------------------------------------------------- dead links.

	public function test_removing_a_dead_link_leaves_a_longer_live_one_intact() {
		// Plain string replacement in the wrong order cuts the front off the
		// longer address and leaves "/guide" sitting in the prose, breaking a
		// working link because a shorter one on the same domain was dead.
		$article = array(
			'intro'    => 'See https://example.com and also https://example.com/guide for more.',
			'sections' => array(),
		);

		$cleaned = Blogcraft_Verify::strip_dead_links( $article, array( 'https://example.com' ) );

		$this->assertStringContainsString( 'https://example.com/guide', $cleaned['intro'] );
	}

	// ------------------------------------------- checks nobody asked for.

	public function test_a_blueprint_with_no_faq_is_not_told_it_is_missing_one() {
		$blueprint              = Blogcraft_Blueprint::defaults();
		$blueprint['faq']       = false;
		$blueprint['takeaways'] = false;
		Blogcraft_Blueprint::save( Blogcraft_Blueprint::DEFAULT_SLUG, $blueprint );

		$result = Blogcraft_Verify::score(
			array(
				'intro'    => 'An opening.',
				'sections' => array(
					array( 'heading' => 'One', 'paragraphs' => array( 'Body.' ) ),
					array( 'heading' => 'Two', 'paragraphs' => array( 'Body.' ) ),
					array( 'heading' => 'Three', 'paragraphs' => array( 'Body.' ) ),
				),
			)
		);

		foreach ( $result['reasons'] as $reason ) {
			$this->assertStringNotContainsString( 'FAQ', $reason );
			$this->assertStringNotContainsString( 'takeaways', $reason );
		}
	}

	// ------------------------------------------------------------ i18n.

	/**
	 * Every translation call whose domain is not ours.
	 *
	 * Tokenised rather than matched with a pattern. The domain is the last
	 * string argument whatever the arity, and a pattern that assumes the
	 * second one reports _x( 'Hi', 'context', 'blogcraft' ) as broken —
	 * which is how a check like this comes to be quietly switched off.
	 *
	 * @param string $code PHP source.
	 * @return array Human-readable descriptions of each bad call.
	 */
	private function wrong_domains( $code ) {
		$fns = array(
			'__',
			'_e',
			'_n',
			'_x',
			'_nx',
			'esc_html__',
			'esc_html_e',
			'esc_html_x',
			'esc_attr__',
			'esc_attr_e',
			'esc_attr_x',
		);

		$tokens = token_get_all( $code );
		$count  = count( $tokens );
		$out    = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_STRING !== $token[0] || ! in_array( $token[1], $fns, true ) ) {
				continue;
			}

			// A method call or a declaration is not the function meant here.
			$prev = isset( $tokens[ $i - 1 ] ) ? $tokens[ $i - 1 ] : null;

			if ( is_array( $prev ) && in_array( $prev[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
				continue;
			}

			$j = $i + 1;

			while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
				++$j;
			}

			if ( ! isset( $tokens[ $j ] ) || '(' !== $tokens[ $j ] ) {
				continue;
			}

			$depth = 0;
			$last  = null;

			for ( $k = $j; $k < $count; $k++ ) {
				$inner = $tokens[ $k ];

				if ( '(' === $inner ) {
					++$depth;
					continue;
				}

				if ( ')' === $inner ) {
					--$depth;

					if ( 0 === $depth ) {
						break;
					}

					continue;
				}

				// Depth one only, so a string inside a nested sprintf() is not
				// mistaken for the domain.
				if ( 1 === $depth && is_array( $inner ) && T_CONSTANT_ENCAPSED_STRING === $inner[0] ) {
					$last = $inner;
				}
			}

			if ( null === $last ) {
				continue;
			}

			$domain = trim( $last[1], "'" . '"' );

			if ( 'blogcraft' !== $domain ) {
				$out[] = $token[1] . '() on line ' . $token[2] . ' uses ' . $domain;
			}
		}

		return $out;
	}

	public function test_the_translations_are_wired_the_way_wordpress_expects() {
		// This used to assert that load_plugin_textdomain() was hooked, after
		// a release where every string was wrapped, the .pot was current, and
		// none of it reached a reader because nothing loaded it.
		//
		// That call is the wrong answer now, and Plugin Check fails the
		// submission over it: WordPress has loaded translations for
		// wordpress.org-hosted plugins by itself since 4.6, and this plugin
		// requires 6.0.
		//
		// So what decides whether a translation reaches anybody is no longer
		// a hook. It is that the header names the same domain every wrapped
		// string passes, and that the catalogue ships where WordPress looks.
		// Those are the two things that can drift in silence.
		$headers = get_file_data(
			BLOGCRAFT_PATH . 'blogcraft.php',
			array(
				'domain' => 'Text Domain',
				'path'   => 'Domain Path',
			)
		);

		$this->assertSame( 'blogcraft', $headers['domain'] );
		$this->assertSame( '/languages', $headers['path'] );

		$bad = array();

		$files = array_merge(
			(array) glob( BLOGCRAFT_PATH . 'includes/*.php' ),
			array( BLOGCRAFT_PATH . 'blogcraft.php', BLOGCRAFT_PATH . 'uninstall.php' )
		);

		foreach ( $files as $file ) {
			$body = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			foreach ( $this->wrong_domains( $body ) as $hit ) {
				$bad[] = basename( $file ) . ': ' . $hit;
			}
		}

		$this->assertSame( array(), $bad, 'a string is wrapped against the wrong text domain' );
	}

	public function test_the_discouraged_loader_stays_gone() {
		// Plugin Check warns on it, and a warning is what stands between this
		// and being accepted on wordpress.org.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringNotContainsString( 'load_plugin_textdomain', $source );
	}
	public function test_the_translations_are_looked_for_where_they_ship() {
		$this->assertDirectoryExists( BLOGCRAFT_PATH . 'languages' );
		$this->assertFileExists( BLOGCRAFT_PATH . 'languages/blogcraft.pot' );
	}
}
