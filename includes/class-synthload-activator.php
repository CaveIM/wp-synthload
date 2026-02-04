<?php
/**
 * Activation and deactivation handlers for WP Synthetic Load plugin.
 *
 * @package WP_SynthLoad
 */

// Security check - prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SynthLoad_Activator
 *
 * Handles plugin activation, deactivation, and upgrade procedures.
 */
class SynthLoad_Activator {

    /**
     * Current schema version.
     */
    const SCHEMA_VERSION = 1;

    /**
     * Run on plugin activation.
     */
    public static function activate(): void {
        // Check PHP version
        if ( version_compare( PHP_VERSION, '8.1.0', '<' ) ) {
            deactivate_plugins( plugin_basename( SYNTHLOAD_PLUGIN_DIR . 'wp-synthload.php' ) );
            wp_die(
                esc_html__( 'WP Synthetic Load requires PHP 8.1 or higher. Please upgrade your PHP version.', 'wp-synthload' ),
                esc_html__( 'Plugin Activation Error', 'wp-synthload' ),
                array( 'back_link' => true )
            );
        }

        // Check WordPress version
        if ( version_compare( get_bloginfo( 'version' ), '6.4', '<' ) ) {
            deactivate_plugins( plugin_basename( SYNTHLOAD_PLUGIN_DIR . 'wp-synthload.php' ) );
            wp_die(
                esc_html__( 'WP Synthetic Load requires WordPress 6.4 or higher. Please upgrade WordPress.', 'wp-synthload' ),
                esc_html__( 'Plugin Activation Error', 'wp-synthload' ),
                array( 'back_link' => true )
            );
        }

        // Create database table
        SynthLoad_Db::create_table();

        // Set default options if not already set
        if ( false === get_option( SynthLoad_Settings::OPTION_NAME ) ) {
            add_option( SynthLoad_Settings::OPTION_NAME, SynthLoad_Settings::get_defaults() );
        }

        // Set schema version
        update_option( 'synthload_schema_version', self::SCHEMA_VERSION );

        // Seed initial data for consistent reads
        self::seed_initial_data();

        // Register rewrite rules
        SynthLoad_Router::register_rewrites();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Run on plugin deactivation.
     */
    public static function deactivate(): void {
        // Flush rewrite rules to remove our rules
        flush_rewrite_rules();

        // Clear any scheduled events
        wp_clear_scheduled_hook( 'synthload_cleanup_events' );

        // Note: We intentionally do NOT delete options or table on deactivation
        // This preserves data for reactivation
    }

    /**
     * Check and run upgrades if needed.
     * Note: Call this during plugins_loaded - it only handles schema upgrades.
     */
    public static function maybe_upgrade(): void {
        $current_version = (int) get_option( 'synthload_schema_version', 0 );

        if ( $current_version < self::SCHEMA_VERSION ) {
            self::run_upgrades( $current_version );
            update_option( 'synthload_schema_version', self::SCHEMA_VERSION );
        }
    }

    /**
     * Check and flush rewrite rules if needed.
     * Note: Call this during init hook when $wp_rewrite is available.
     */
    public static function maybe_flush_rewrites(): void {
        if ( SynthLoad_Router::rules_need_flush() ) {
            SynthLoad_Router::register_rewrites();
            flush_rewrite_rules();
        }
    }

    /**
     * Run upgrade procedures.
     *
     * @param int $from_version Version upgrading from.
     */
    private static function run_upgrades( int $from_version ): void {
        // Ensure table exists and is up to date
        SynthLoad_Db::create_table();

        // Future upgrade logic would go here
        // Example:
        // if ( $from_version < 2 ) {
        //     self::upgrade_to_v2();
        // }
    }

    /**
     * Seed initial data for consistent read operations.
     */
    private static function seed_initial_data(): void {
        global $wpdb;

        $db = new SynthLoad_Db( $wpdb );

        // Only seed if table is empty or has very few records
        $current_count = $db->count_events();

        if ( $current_count >= 500 ) {
            return; // Already has enough data
        }

        $events_to_create = 500 - $current_count;

        for ( $i = 0; $i < $events_to_create; $i++ ) {
            $payload = wp_json_encode( array(
                'seed'       => true,
                'index'      => $i,
                'created_at' => gmdate( 'c' ),
                'random'     => wp_generate_password( 8, false ),
            ) );

            $db->insert_event( array(
                'payload' => $payload,
            ) );
        }
    }
}
