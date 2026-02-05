<?php
/**
 * Plugin Name: WP Synthetic Load
 * Plugin URI: https://example.com/wp-synthload
 * Description: Provides a synthetic load endpoint for Loader.io testing and load simulation
 * Version: 1.8.5
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: MightyBox
 * License: GPL v2 or later
 * Text Domain: wp-synthload
 */

// Security check - prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Define plugin constants
define( 'SYNTHLOAD_VERSION', '1.8.5' );
define( 'SYNTHLOAD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYNTHLOAD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SYNTHLOAD_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload plugin classes.
 *
 * @param string $class The class name to load.
 */
spl_autoload_register( function ( $class ) {
    $prefix = 'SynthLoad_';

    // Only handle our classes
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }

    // Convert class name to file name
    $relative_class = substr( $class, strlen( $prefix ) );
    $file = 'class-synthload-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';

    // Check in includes and admin directories
    $paths = array(
        SYNTHLOAD_PLUGIN_DIR . 'includes/' . $file,
        SYNTHLOAD_PLUGIN_DIR . 'admin/' . $file,
    );

    foreach ( $paths as $path ) {
        if ( file_exists( $path ) ) {
            require_once $path;
            return;
        }
    }
});

// Register activation hook
register_activation_hook( __FILE__, array( 'SynthLoad_Activator', 'activate' ) );

// Register deactivation hook
register_deactivation_hook( __FILE__, array( 'SynthLoad_Activator', 'deactivate' ) );

/**
 * Initialize the plugin.
 */
add_action( 'plugins_loaded', function () {
    // Check PHP version
    if ( version_compare( PHP_VERSION, '8.1.0', '<' ) ) {
        add_action( 'admin_notices', function () {
            printf(
                '<div class="error"><p>%s</p></div>',
                esc_html__( 'WP Synthetic Load requires PHP 8.1 or higher. Please upgrade your PHP version.', 'wp-synthload' )
            );
        });
        return;
    }

    // Check WordPress version
    if ( version_compare( get_bloginfo( 'version' ), '6.4', '<' ) ) {
        add_action( 'admin_notices', function () {
            printf(
                '<div class="error"><p>%s</p></div>',
                esc_html__( 'WP Synthetic Load requires WordPress 6.4 or higher. Please upgrade WordPress.', 'wp-synthload' )
            );
        });
        return;
    }

    // Run any necessary upgrades
    if ( class_exists( 'SynthLoad_Activator' ) ) {
        SynthLoad_Activator::maybe_upgrade();
    }

    // Register rewrite rules and check if flush needed
    add_action( 'init', array( 'SynthLoad_Router', 'register_rewrites' ), 10 );
    add_action( 'init', array( 'SynthLoad_Activator', 'maybe_flush_rewrites' ), 11 );

    // Add query vars
    add_filter( 'query_vars', array( 'SynthLoad_Router', 'add_query_vars' ), 10 );

    // Handle incoming requests
    add_action( 'template_redirect', function () {
        $router = new SynthLoad_Router();
        $router->handle_request();
    }, 1 );

    // Admin hooks - only load in admin context
    if ( is_admin() ) {
        $admin = new SynthLoad_Admin();
        add_action( 'admin_menu', array( $admin, 'add_menu_page' ) );
        add_action( 'admin_init', array( $admin, 'process_form_submission' ), 5 ); // Early priority for redirect
        add_action( 'admin_init', array( $admin, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
    }
}, 10 );
