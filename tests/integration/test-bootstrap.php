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
	 * Every version heading in readme.txt, newest first as written.
	 *
	 * @return array
	 */
	private function changelog_versions() {
		$readme = (string) file_get_contents( BLOGCRAFT_PATH . 'readme.txt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		preg_match_all( '/^= (\d+\.\d+\.\d+) =$/m', $readme, $hits );

		return $hits[1];
	}

	public function test_the_release_being_shipped_has_a_changelog_entry() {
		// The changelog stopped at 0.37.0 and the plugin reached 0.62.0, so
		// twenty-five releases went out with no record of what changed in
		// them. Nothing failed while that happened, which is why it ran for
		// twenty-five: the file parses, the plugin works, and the only person
		// who notices is a reviewer reading the listing.
		$versions = $this->changelog_versions();

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

	public function test_the_changelog_runs_newest_first_with_no_release_missing() {
		// Two separate ways the file can mislead: entries out of order, which
		// reads as a typo and hides the newest change, and a version simply
		// absent, which is what happened here. Patch releases are sparse by
		// nature, so the run checked is the minor series.
		$versions = $this->changelog_versions();
		$sorted   = $versions;

		usort(
			$sorted,
			function ( $a, $b ) {
				return version_compare( $b, $a );
			}
		);

		$this->assertSame( $sorted, $versions, 'the changelog is not in order' );

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
}

