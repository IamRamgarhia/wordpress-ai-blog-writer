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
}
