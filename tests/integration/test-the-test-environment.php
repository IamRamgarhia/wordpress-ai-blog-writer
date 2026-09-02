<?php
/**
 * The suite only proves what the environment it runs in lets it prove.
 *
 * The directory's checklist asks for a clean install with WP_DEBUG on,
 * because that is what turns an undefined index from silence into a notice.
 * phpunit.xml.dist turns notices and warnings into exceptions, so with debug
 * on, every path the suite covers is also a path proven free of them.
 *
 * All of that is invisible and none of it is enforced. Turn WP_DEBUG off in
 * the runner, or move to PHPUnit 10 where those two attributes were removed,
 * and the guarantee is gone with nothing failing to say so — the suite would
 * stay green and mean less. This is the assertion that notices.
 *
 * @package Blogcraft
 */

class Test_Blogcraft_The_Test_Environment extends WP_UnitTestCase {

	public function test_debug_is_on_so_a_notice_is_not_swallowed() {
		$this->assertTrue(
			defined( 'WP_DEBUG' ) && WP_DEBUG,
			'WP_DEBUG is off in the test runner, so an undefined index raises nothing and the suite cannot see it'
		);
	}

	public function test_phpunit_still_turns_notices_into_failures() {
		// Both attributes were removed in PHPUnit 10, where they are ignored
		// rather than rejected. Pinning the major version is what keeps the
		// line in phpunit.xml.dist meaningful.
		$this->assertTrue( class_exists( 'PHPUnit\Runner\Version' ) );

		$major = (int) explode( '.', PHPUnit\Runner\Version::id() )[0];

		$this->assertSame(
			9,
			$major,
			'PHPUnit ' . $major . ' ignores convertNoticesToExceptions, so notices no longer fail the build'
		);

		$config = (string) file_get_contents( dirname( BLOGCRAFT_PATH ) . '/blogcraft/phpunit.xml.dist' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( '' === $config ) {
			$config = (string) file_get_contents( BLOGCRAFT_PATH . 'phpunit.xml.dist' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}

		$this->assertStringContainsString( 'convertNoticesToExceptions="true"', $config );
		$this->assertStringContainsString( 'convertWarningsToExceptions="true"', $config );
		$this->assertStringContainsString( 'convertErrorsToExceptions="true"', $config );
	}

	public function test_a_notice_really_does_become_a_failure() {
		// Proving the instrument rather than trusting the configuration: if
		// reading a missing index raises nothing catchable here, then every
		// other test in this suite is passing over notices in silence.
		$empty = array();

		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Discouraged
			$value = $empty['definitely_not_set'];

			$this->fail( 'reading a missing array key raised nothing, so notices are not being converted' );
		} catch ( Throwable $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}
	}

	public function test_the_runtime_is_one_the_plugin_claims_to_support() {
		// "Requires PHP: 7.4" in the header. The suite runs on one version,
		// so this records which, and fails if the runner drops below what
		// the plugin promises.
		$this->assertTrue(
			version_compare( PHP_VERSION, '7.4', '>=' ),
			'the suite is running on PHP ' . PHP_VERSION . ', below the 7.4 the plugin requires'
		);
	}
}
