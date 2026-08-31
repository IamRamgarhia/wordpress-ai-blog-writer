<?php
/**
 * Does the autoloader still resolve every class the plugin defines?
 *
 * Standalone, because the failure it is checking for killed the PHPUnit
 * bootstrap before a single test could run — so the suite could not report it
 * as a failing assertion, only as a stack trace.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'BLOGCRAFT_PATH', $argv[1] . '/' );

require BLOGCRAFT_PATH . 'includes/class-blogcraft-autoloader.php';

Blogcraft_Autoloader::register();

$expected = array();

foreach ( glob( BLOGCRAFT_PATH . 'includes/class-blogcraft-*.php' ) as $file ) {
	$body = file_get_contents( $file );

	if ( preg_match( '/^class\s+(Blogcraft_\w+)/m', $body, $hit ) ) {
		$expected[] = $hit[1];
	}
}

$expected[] = 'Blogcraft';

$missing = array();

foreach ( $expected as $class ) {
	if ( ! class_exists( $class ) ) {
		$missing[] = $class;
	}
}

if ( $missing ) {
	echo 'FAIL - the autoloader cannot resolve: ' . implode( ', ', $missing ) . PHP_EOL;
	exit( 1 );
}

echo 'OK - all ' . count( $expected ) . ' classes resolve' . PHP_EOL;
