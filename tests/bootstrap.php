<?php
/**
 * PHPUnit bootstrap for Blogcraft.
 *
 * @package Blogcraft
 */

require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$blogcraft_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $blogcraft_tests_dir ) {
	$blogcraft_tests_dir = '/wordpress-phpunit';
}

require_once $blogcraft_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/blogcraft.php';
	}
);

require $blogcraft_tests_dir . '/includes/bootstrap.php';
