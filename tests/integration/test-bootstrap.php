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
}
