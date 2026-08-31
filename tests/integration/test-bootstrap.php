<?php
/**
 * Bootstrap tests.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_Bootstrap extends WP_UnitTestCase {

	public function test_constants_are_defined() {
		$this->assertTrue( defined( 'BLOGCRAFT_VERSION' ) );
		$this->assertTrue( defined( 'BLOGCRAFT_FILE' ) );
		$this->assertTrue( defined( 'BLOGCRAFT_PATH' ) );
		$this->assertTrue( defined( 'BLOGCRAFT_URL' ) );
		$this->assertTrue( defined( 'BLOGCRAFT_DB_VERSION' ) );
	}

	public function test_path_constant_has_trailing_slash() {
		$this->assertSame( trailingslashit( BLOGCRAFT_PATH ), BLOGCRAFT_PATH );
	}

	public function test_autoloader_resolves_plugin_classes() {
		$this->assertTrue( class_exists( 'Blogcraft' ) );
	}

	public function test_instance_returns_singleton() {
		$this->assertSame( Blogcraft::instance(), Blogcraft::instance() );
	}

	// ------------------------------------------------------------ releases.

	/**
	 * Every version heading in a changelog, newest first as written.
	 *
	 * @param string $file readme.txt or changelog.txt.
	 * @return array
	 */
	private function changelog_versions( $file ) {
		$path = BLOGCRAFT_PATH . $file;

		$this->assertFileExists( $path );

		$body = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		preg_match_all( '/^= (\d+\.\d+\.\d+) =$/m', $body, $hits );

		return $hits[1];
	}

	/**
	 * The text of one named section of readme.txt.
	 *
	 * @param string $section Section title, without the equals signs.
	 * @return string
	 */
	private function readme_section( $section ) {
		$readme = (string) file_get_contents( BLOGCRAFT_PATH . 'readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$pattern = '/^== ' . preg_quote( $section, '/' ) . ' ==$(.*?)(?=^== |\z)/ms';

		if ( ! preg_match( $pattern, $readme, $hit ) ) {
			return '';
		}

		return $hit[1];
	}

	public function test_the_release_being_shipped_has_a_changelog_entry() {
		// The changelog stopped at 0.37.0 and the plugin reached 0.62.0, so
		// twenty-five releases went out with no record of what changed in
		// them. Nothing failed while that happened, which is why it ran for
		// twenty-five: the file parses, the plugin works, and the only person
		// who notices is a reviewer reading the listing.
		$versions = $this->changelog_versions( 'readme.txt' );

		$this->assertNotEmpty( $versions, 'readme.txt has no changelog at all' );

		$this->assertSame(
			BLOGCRAFT_VERSION,
			$versions[0],
			'the newest changelog entry is ' . $versions[0] . ', but this is version ' . BLOGCRAFT_VERSION
		);
	}

	public function test_the_stable_tag_is_the_version_being_shipped() {
		// wordpress.org serves whatever Stable tag names, so a stale one ships
		// an older plugin than the one that was uploaded.
		$readme = (string) file_get_contents( BLOGCRAFT_PATH . 'readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertMatchesRegularExpression(
			'/^Stable tag: ' . preg_quote( BLOGCRAFT_VERSION, '/' ) . '$/m',
			$readme,
			'Stable tag does not name version ' . BLOGCRAFT_VERSION
		);
	}

	public function test_the_readme_changelog_stays_under_the_parser_ceiling() {
		// The readme parser truncates any section over 5000 characters and
		// warns. Backfilling twenty-five releases put the changelog at 39,591,
		// so the listing would have shown a changelog cut off mid-sentence.
		// wordpress.org's own advice is to keep the current releases here and
		// put the history in changelog.txt, which is what this holds.
		$changelog = $this->readme_section( 'Changelog' );

		$this->assertNotSame( '', $changelog, 'readme.txt has no changelog section' );

		$this->assertLessThan(
			5000,
			strlen( $changelog ),
			'the readme changelog is ' . strlen( $changelog ) . ' characters and will be truncated at 5000'
		);

		$this->assertStringContainsString(
			'changelog.txt',
			$changelog,
			'the readme changelog does not say where the rest of the history is'
		);
	}

	public function test_the_full_history_runs_newest_first_with_no_release_missing() {
		// Two separate ways a changelog misleads: entries out of order, which
		// hides the newest change, and a version simply absent, which is what
		// happened here. Patch releases are sparse by nature, so the run
		// checked is the minor series.
		$versions = $this->changelog_versions( 'changelog.txt' );
		$sorted   = $versions;

		usort(
			$sorted,
			function ( $a, $b ) {
				return version_compare( $b, $a );
			}
		);

		$this->assertSame( $sorted, $versions, 'changelog.txt is not in order' );

		$this->assertSame(
			BLOGCRAFT_VERSION,
			$versions[0],
			'changelog.txt stops at ' . $versions[0] . ', but this is version ' . BLOGCRAFT_VERSION
		);

		$minors = array();

		foreach ( $versions as $version ) {
			$parts = explode( '.', $version );

			$minors[ (int) $parts[1] ] = true;
		}

		$highest = (int) explode( '.', BLOGCRAFT_VERSION )[1];

		for ( $minor = 1; $minor <= $highest; $minor++ ) {
			$this->assertArrayHasKey(
				$minor,
				$minors,
				'no changelog entry for any 0.' . $minor . '.x release'
			);
		}
	}

	public function test_everything_the_readme_promises_is_in_the_build() {
		// LICENSE was in the repository and in no release. GPLv2 requires the
		// licence text travel with the work, so every zip built until now was
		// a licence breach as well as a review finding — and the build script
		// skipped a missing entry in silence, so nothing said so.
		$build = (string) file_get_contents( BLOGCRAFT_PATH . 'bin/build.py' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		foreach ( array( 'LICENSE', 'changelog.txt', 'readme.txt', 'languages', 'data' ) as $entry ) {
			$this->assertStringContainsString(
				"'" . $entry . "'",
				$build,
				$entry . ' is not packed into the release zip'
			);
		}
	}

	// ---------------------------------------------------------- every entry.

	/**
	 * The body of one method, by brace matching.
	 *
	 * @param string $source PHP source.
	 * @param string $method Method name.
	 * @return string Empty when the method is not in this file.
	 */
	private function method_body( $source, $method ) {
		$at = strpos( $source, 'function ' . $method . '(' );

		if ( false === $at ) {
			return '';
		}

		$open  = strpos( $source, '{', $at );
		$depth = 0;

		for ( $i = $open; $i < strlen( $source ); $i++ ) {
			if ( '{' === $source[ $i ] ) {
				++$depth;
			} elseif ( '}' === $source[ $i ] ) {
				--$depth;

				if ( 0 === $depth ) {
					return substr( $source, $open, $i - $open );
				}
			}
		}

		return '';
	}

	public function test_every_form_and_ajax_entry_point_is_guarded() {
		// The commonest reason a plugin is turned away: a handler reachable by
		// anyone who can be made to load a URL. There are twenty-five of them
		// here and counting them by hand is exactly the check that rots, so
		// this reads the registrations out of the source and follows each one
		// to the method it names.
		//
		// Blogcraft_Request::verify() tests the capability and the nonce
		// together, which is why a handler satisfies this by calling one
		// helper rather than by repeating two checks twenty-five times.
		$guards  = '/verify_or_die|Blogcraft_Request::verify\(|check_admin_referer|check_ajax_referer/';
		$checked = 0;

		foreach ( glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! preg_match_all(
				"/add_action\(\s*'(wp_ajax_|admin_post_)(nopriv_)?([a-z_]+)'\s*,\s*array\([^,]+,\s*'([a-z_]+)'\s*\)/",
				$source,
				$hits,
				PREG_SET_ORDER
			) ) {
				continue;
			}

			foreach ( $hits as $hit ) {
				$hook   = $hit[1] . $hit[3];
				$method = $hit[4];

				// nopriv means logged-out visitors reach it. Nothing this
				// plugin does is for them, so the right number is zero and
				// the failure is worth catching at registration rather than
				// inside the handler.
				$this->assertSame( '', $hit[2], $hook . ' is registered for logged-out visitors' );

				$body = $this->method_body( $source, $method );

				$this->assertNotSame(
					'',
					$body,
					$hook . ' names ' . $method . '(), which is not in ' . basename( $file )
				);

				$this->assertMatchesRegularExpression(
					$guards,
					$body,
					$hook . ' reaches ' . $method . '() without verifying a nonce and a capability'
				);

				++$checked;
			}
		}

		// A regex that matches nothing passes every assertion above it, so the
		// count is asserted too — this has to have actually found the handlers.
		$this->assertGreaterThanOrEqual( 20, $checked, 'only ' . $checked . ' entry points were found to check' );
	}

	public function test_the_shared_guard_checks_both_things() {
		// The test above trusts one helper to do both. This is the assertion
		// that keeps that trust honest: if verify() ever stops checking the
		// capability, twenty-five handlers silently lose it at once.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'includes/class-blogcraft-request.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$body   = $this->method_body( $source, 'verify' );

		$this->assertStringContainsString( 'current_user_can', $body, 'verify() no longer checks a capability' );
		$this->assertStringContainsString( 'wp_verify_nonce', $body, 'verify() no longer checks a nonce' );
	}

	public function test_no_asset_is_loaded_from_somebody_elses_server() {
		// Guideline 8. Every script and stylesheet has to come from inside the
		// plugin — a CDN is a third party who can change what runs on the
		// reader's admin screens.
		foreach ( glob( BLOGCRAFT_PATH . 'includes/*.php' ) as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! preg_match_all( '/wp_enqueue_(?:script|style)\(\s*[^;]+?;/s', $source, $hits ) ) {
				continue;
			}

			foreach ( $hits[0] as $call ) {
				$this->assertDoesNotMatchRegularExpression(
					'#[\'"]https?://#',
					$call,
					basename( $file ) . ' enqueues an asset from another server'
				);
			}
		}
	}

	// ----------------------------------------------------------- the markup.

	/**
	 * Every shape of fragment this plugin builds and then prints.
	 *
	 * @return array Description => markup.
	 */
	private function assembled_fragments() {
		return array(
			'key control'  => '<label class="blogcraft-clear-key"><input type="checkbox" name="clear_provider_api_key" value="1" /> Remove this key</label>',
			'score pill'   => '<span class="bc-score-pill is-ok">88</span>',
			'unscored'     => '<span class="bc-score-pill is-none">not scored</span>',
			'nav count'    => '<span class="bc-nav-count">3</span>',
			'nav current'  => '<span class="bc-nav-item is-current" aria-current="page">Overview<span class="bc-nav-count">3</span></span>',
			'nav link'     => '<a class="bc-nav-item" href="http://example.org/wp-admin/admin.php?page=blogcraft">Overview</a>',
			'select'       => '<select name="a"><optgroup label="g"><option value="1">One</option></optgroup></select>',
			'textarea'     => '<textarea name="t" rows="6" class="large-text code">hi</textarea>',
			'number field' => '<input type="number" name="n" value="3" min="0" max="9" step="1" class="small-text" />',
			'hint'         => '<p class="description">Something <strong>bold</strong> and <code>code</code>.</p>',
		);
	}

	public function test_the_allowlist_does_not_eat_the_plugins_own_markup() {
		// Nine places printed assembled HTML behind a phpcs:ignore asserting
		// it was safe. They now run through wp_kses against a fixed list,
		// which turns the assertion into a rule — but a list that is too
		// narrow deletes markup silently, and a settings control that simply
		// stops appearing is a worse bug than the one this replaced.
		//
		// So the list is asserted to be an identity on everything the plugin
		// actually builds.
		$allowed = Blogcraft_Markup::allowed();

		foreach ( $this->assembled_fragments() as $name => $html ) {
			$this->assertSame(
				$html,
				wp_kses( $html, $allowed ),
				'the allowlist alters the ' . $name . ' fragment'
			);
		}
	}

	public function test_the_allowlist_still_removes_what_it_is_for() {
		// The other half. An identity test alone passes just as well against
		// a list that permits everything, which would make the whole change
		// decorative.
		$attacks = array(
			'script tag'    => '<script>alert(1)</script>',
			'iframe'        => '<iframe src="http://evil.example"></iframe>',
			'inline handler' => '<span onclick="alert(1)">x</span>',
			'style tag'     => '<style>body{display:none}</style>',
			'object'        => '<object data="x"></object>',
		);

		$allowed = Blogcraft_Markup::allowed();

		foreach ( $attacks as $name => $html ) {
			$out = wp_kses( $html, $allowed );

			$this->assertNotSame( $html, $out, 'the allowlist passes ' . $name . ' through unchanged' );
			$this->assertStringNotContainsString( '<script', $out, $name );
			$this->assertStringNotContainsString( '<iframe', $out, $name );
			$this->assertStringNotContainsString( 'onclick', $out, $name );
		}
	}

	public function test_no_output_is_printed_behind_a_suppression() {
		// "echo $html; // safe" is the pattern review distrusts most, and
		// rightly: the claim is only as good as the helper behind it, and
		// nobody can tell a true one from a false one without reading that
		// helper. There were nine. There should stay none.
		$files = array_merge(
			(array) glob( BLOGCRAFT_PATH . 'includes/*.php' ),
			array( BLOGCRAFT_PATH . 'blogcraft.php', BLOGCRAFT_PATH . 'uninstall.php' )
		);

		$found = array();

		foreach ( $files as $file ) {
			$body = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			foreach ( explode( "\n", $body ) as $number => $line ) {
				if ( false !== strpos( $line, 'phpcs:ignore' ) && false !== strpos( $line, 'EscapeOutput' ) ) {
					$found[] = basename( $file ) . ':' . ( $number + 1 );
				}
			}
		}

		$this->assertSame( array(), $found, 'output is being printed behind an escaping suppression' );
	}

	public function test_the_uninstall_guard_admits_only_a_real_uninstall() {
		// ABSPATH used to be accepted as the second half of an or-chain, and
		// ABSPATH is defined on every WordPress request — so the guard passed
		// whenever the file was reached at all, not only when the plugin was
		// being deleted.
		$source = (string) file_get_contents( BLOGCRAFT_PATH . 'uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString(
			"defined( 'WP_UNINSTALL_PLUGIN' ) || exit;",
			$source,
			'uninstall.php does not stop on anything but a real uninstall'
		);

		$this->assertStringNotContainsString(
			"defined( 'ABSPATH' ) || exit",
			$source,
			'uninstall.php still accepts ABSPATH as proof it is being uninstalled'
		);
	}
}
