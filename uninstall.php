<?php
/**
 * Uninstall script for WP Synthetic Load plugin.
 *
 * This file is executed when the plugin is deleted from WordPress.
 * It removes all plugin data including options and database tables.
 *
 * @package WP_SynthLoad
 */

// Security check - only run if called from WordPress uninstall
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Delete Loader.io verification file before removing settings
$settings = get_option( 'synthload_settings', array() );
if ( ! empty( $settings['loaderio_token'] ) ) {
    $token = $settings['loaderio_token'];
    // Strip loaderio- prefix if present
    if ( strpos( strtolower( $token ), 'loaderio-' ) === 0 ) {
        $token = substr( $token, 9 );
    } elseif ( strpos( strtolower( $token ), 'loaderio' ) === 0 ) {
        $token = substr( $token, 8 );
    }
    $filepath = ABSPATH . 'loaderio-' . $token . '.txt';
    if ( file_exists( $filepath ) ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        unlink( $filepath );
    }
}

// Delete plugin options
delete_option( 'synthload_settings' );
delete_option( 'synthload_schema_version' );

// Drop custom database table
global $wpdb;

$table_name = $wpdb->prefix . 'synthload_events';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Clean up any transients
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_synthload_%'
    )
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
        '_transient_timeout_synthload_%'
    )
);

// Flush rewrite rules by deleting the option
// This forces WordPress to regenerate rules on next request
delete_option( 'rewrite_rules' );
