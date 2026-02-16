<?php
/**
 * PHPUnit bootstrap for wp-theme-guard tests.
 *
 * Loads the WordPress test suite, then activates the plugin.
 */

// Load Composer autoloader for PHPUnit Polyfills.
$_plugin_dir = dirname( __DIR__ );
if ( file_exists( $_plugin_dir . '/vendor/autoload.php' ) ) {
	require_once $_plugin_dir . '/vendor/autoload.php';
}

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php." . PHP_EOL;
	exit( 1 );
}

require_once "{$_tests_dir}/includes/functions.php";

/**
 * Load the plugin via its main bootstrap file.
 */
function _manually_load_plugin(): void {
	require dirname( __DIR__ ) . '/wp-theme-guard.php';
}
tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require "{$_tests_dir}/includes/bootstrap.php";
