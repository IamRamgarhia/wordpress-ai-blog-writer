<?php
/**
 * Class autoloader.
 *
 * @package Blogcraft
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maps Blogcraft_Foo_Bar to includes/class-blogcraft-foo-bar.php.
 */
class Blogcraft_Autoloader {

	/**
	 * Register the autoloader.
	 *
	 * @return void
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Load a class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function autoload( $class_name ) {
		if ( 'Blogcraft' !== $class_name && 0 !== strpos( $class_name, 'Blogcraft_' ) ) {
			return;
		}

		$file = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		$path = BLOGCRAFT_PATH . 'includes/' . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
