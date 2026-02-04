<?php
/**
 * PHPUnit bootstrap file for WordPress plugin tests.
 *
 * @package WP_SynthLoad
 */

// Define test constant
define( 'SYNTHLOAD_TESTING', true );

// Load Composer autoloader if present
$composer_autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $composer_autoload ) ) {
    require_once $composer_autoload;
}

// Find WordPress test library
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Check for test library
if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find WordPress test library at {$_tests_dir}\n";
    echo "Set WP_TESTS_DIR environment variable to point to the WordPress test library.\n";
    echo "\nYou can set up the test library using WP-CLI:\n";
    echo "  wp scaffold plugin-tests wp-synthload\n\n";
    echo "Or download and configure wordpress-develop manually.\n";
    exit( 1 );
}

// Give access to tests_add_filter() function
require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin(): void {
    require dirname( __DIR__ ) . '/wp-synthload.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

// Start up the WP testing environment
require $_tests_dir . '/includes/bootstrap.php';
