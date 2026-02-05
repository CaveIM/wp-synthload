<?php
/**
 * Settings management for WP Synthetic Load plugin.
 *
 * @package WP_SynthLoad
 */

// Security check - prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SynthLoad_Settings
 *
 * Manages plugin options storage, retrieval, sanitization, and defaults.
 */
class SynthLoad_Settings {

    /**
     * Option name in wp_options table.
     */
    const OPTION_NAME = 'synthload_settings';

    /**
     * Default configuration values.
     *
     * @var array
     */
    private static array $defaults = array(
        'loaderio_token'             => '',
        'endpoint_slug'              => 'synthload',
        'endpoint_enabled'           => true,
        'access_token'               => '',
        'read_query_count'           => 100,
        'write_op_count'             => 5,
        'cpu_iterations'             => 100, // Stored in thousands (100 = 100,000 iterations)
        'bypass_object_cache'        => false,
        'debug_logging_enabled'      => false,
        // Calculator assumptions (defaults for dynamic sites).
        'calc_pages_per_visit'       => 5,
        'calc_cache_hit_rate'        => 30,   // Percentage (0-100). Low for dynamic sites.
        'calc_connections_per_vcpu'  => 2,    // PHP-FPM workers per vCPU for dynamic sites.
        'calc_peak_to_average_ratio' => 2.5,  // For business hours traffic.
        'calc_flash_spike_percent'   => 15,   // Percentage of monthly traffic in spike.
    );

    /**
     * Hard safety limits that cannot be exceeded.
     *
     * @var array
     */
    private static array $hard_limits = array(
        'max_cpu_iterations'   => 10000, // In thousands (10000 = 10 million iterations)
        'max_read_query_count' => 10000,
        'max_write_op_count'   => 1000,
        'max_rows_to_keep'     => 100000,
    );

    /**
     * Reserved WordPress paths that cannot be used as endpoint slugs.
     *
     * @var array
     */
    private static array $reserved_slugs = array(
        'wp-admin',
        'wp-includes',
        'wp-content',
        'wp-json',
        'feed',
        'embed',
        'comments',
        'trackback',
        'page',
        'author',
        'search',
        'category',
        'tag',
    );

    /**
     * Get default configuration values.
     *
     * @return array Default settings array.
     */
    public static function get_defaults(): array {
        return self::$defaults;
    }

    /**
     * Get hard safety limits.
     *
     * @return array Hard limits array.
     */
    public static function get_hard_limits(): array {
        return self::$hard_limits;
    }

    /**
     * Get all settings merged with defaults.
     *
     * @return array All settings with defaults applied.
     */
    public static function get_all(): array {
        $stored = get_option( self::OPTION_NAME, array() );

        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        return array_merge( self::$defaults, $stored );
    }

    /**
     * Get a single setting value.
     *
     * @param string $key     The setting key to retrieve.
     * @param mixed  $default Default value if key not found.
     * @return mixed The setting value or default.
     */
    public static function get( string $key, mixed $default = null ): mixed {
        $settings = self::get_all();

        if ( array_key_exists( $key, $settings ) ) {
            return $settings[ $key ];
        }

        return $default;
    }

    /**
     * Update settings.
     *
     * @param array $values Settings to update.
     * @return bool True on success, false on failure.
     */
    public static function update( array $values ): bool {
        $sanitized = self::sanitize( $values );
        $current   = self::get_all();
        $merged    = array_merge( $current, $sanitized );

        return update_option( self::OPTION_NAME, $merged );
    }

    /**
     * Sanitize settings array for storage.
     *
     * Enforces hard caps and type validation.
     *
     * @param array $input Raw input values.
     * @return array Sanitized settings array.
     */
    public static function sanitize( array $input ): array {
        $sanitized = array();
        $limits    = self::$hard_limits;

        // Loader.io token - store as entered (alphanumeric and hyphens)
        if ( isset( $input['loaderio_token'] ) ) {
            $token = sanitize_text_field( $input['loaderio_token'] );
            // Allow alphanumeric and hyphens (supports "loaderio-xxxxx" format)
            $sanitized['loaderio_token'] = preg_replace( '/[^a-zA-Z0-9\-]/', '', $token );
        }

        // Endpoint slug - validate and sanitize
        if ( isset( $input['endpoint_slug'] ) ) {
            $slug = sanitize_title( $input['endpoint_slug'] );
            $slug = strtolower( $slug );

            if ( self::is_valid_slug( $slug ) ) {
                $sanitized['endpoint_slug'] = $slug;
            } else {
                $sanitized['endpoint_slug'] = self::$defaults['endpoint_slug'];
            }
        }

        // Endpoint enabled - boolean
        if ( isset( $input['endpoint_enabled'] ) ) {
            $sanitized['endpoint_enabled'] = self::to_bool( $input['endpoint_enabled'] );
        }

        // Access token - sanitize text
        if ( isset( $input['access_token'] ) ) {
            $sanitized['access_token'] = sanitize_text_field( $input['access_token'] );
        }

        // Read query count - integer, clamped to limits
        if ( isset( $input['read_query_count'] ) ) {
            $count                        = (int) $input['read_query_count'];
            $sanitized['read_query_count'] = self::clamp( $count, 0, $limits['max_read_query_count'] );
        }

        // Write operation count - integer, clamped to limits
        if ( isset( $input['write_op_count'] ) ) {
            $count                       = (int) $input['write_op_count'];
            $sanitized['write_op_count'] = self::clamp( $count, 0, $limits['max_write_op_count'] );
        }

        // CPU iterations - integer in thousands, clamped to limits (0 = disabled)
        if ( isset( $input['cpu_iterations'] ) ) {
            $iterations                   = (int) $input['cpu_iterations'];
            $sanitized['cpu_iterations'] = self::clamp( $iterations, 0, $limits['max_cpu_iterations'] );
        }

        // Cache bypass - boolean
        if ( isset( $input['bypass_object_cache'] ) ) {
            $sanitized['bypass_object_cache'] = self::to_bool( $input['bypass_object_cache'] );
        }

        // Debug logging - boolean
        if ( isset( $input['debug_logging_enabled'] ) ) {
            $sanitized['debug_logging_enabled'] = self::to_bool( $input['debug_logging_enabled'] );
        }

        // Calculator: Pages per visit - integer, 1-20.
        if ( isset( $input['calc_pages_per_visit'] ) ) {
            $pages                              = (int) $input['calc_pages_per_visit'];
            $sanitized['calc_pages_per_visit'] = self::clamp( $pages, 1, 20 );
        }

        // Calculator: Cache hit rate - integer percentage, 0-99.
        if ( isset( $input['calc_cache_hit_rate'] ) ) {
            $rate                               = (int) $input['calc_cache_hit_rate'];
            $sanitized['calc_cache_hit_rate'] = self::clamp( $rate, 0, 99 );
        }

        // Calculator: Connections per vCPU - integer, 5-200.
        if ( isset( $input['calc_connections_per_vcpu'] ) ) {
            $connections                              = (int) $input['calc_connections_per_vcpu'];
            $sanitized['calc_connections_per_vcpu'] = self::clamp( $connections, 5, 200 );
        }

        // Calculator: Peak-to-average ratio - float, 1.0-10.0.
        if ( isset( $input['calc_peak_to_average_ratio'] ) ) {
            $ratio                                    = (float) $input['calc_peak_to_average_ratio'];
            $sanitized['calc_peak_to_average_ratio'] = max( 1.0, min( 10.0, $ratio ) );
        }

        // Calculator: Flash spike percent - integer percentage, 1-50.
        if ( isset( $input['calc_flash_spike_percent'] ) ) {
            $percent                                = (int) $input['calc_flash_spike_percent'];
            $sanitized['calc_flash_spike_percent'] = self::clamp( $percent, 1, 50 );
        }

        return $sanitized;
    }

    /**
     * Validate endpoint slug format.
     *
     * @param string $slug The slug to validate.
     * @return bool True if valid, false otherwise.
     */
    public static function is_valid_slug( string $slug ): bool {
        // Must not be empty
        if ( empty( $slug ) ) {
            return false;
        }

        // Must be lowercase alphanumeric with hyphens only
        if ( ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
            return false;
        }

        // Must not be a reserved WordPress path
        if ( in_array( $slug, self::$reserved_slugs, true ) ) {
            return false;
        }

        // Must not start with 'wp-'
        if ( str_starts_with( $slug, 'wp-' ) ) {
            return false;
        }

        return true;
    }

    /**
     * Validate token format.
     *
     * @param string $token The token to validate.
     * @return bool True if valid, false otherwise.
     */
    public static function is_valid_token( string $token ): bool {
        // Empty is valid (token is optional)
        if ( empty( $token ) ) {
            return true;
        }

        // Allow "loaderio-xxxxx" or just "xxxxx" format
        return (bool) preg_match( '/^[a-zA-Z0-9\-]+$/', $token );
    }

    /**
     * Extract the token ID from a stored token value.
     *
     * Handles both "loaderio-xxxxx" and "xxxxx" formats,
     * returning just the "xxxxx" part for URL generation.
     *
     * @param string $token The stored token value.
     * @return string The token ID without prefix.
     */
    public static function extract_token_id( string $token ): string {
        // Remove "loaderio-" prefix if present
        if ( str_starts_with( strtolower( $token ), 'loaderio-' ) ) {
            return substr( $token, 9 ); // Remove 'loaderio-' (9 chars)
        }

        // Remove "loaderio" prefix without hyphen if present
        if ( str_starts_with( strtolower( $token ), 'loaderio' ) ) {
            return substr( $token, 8 ); // Remove 'loaderio' (8 chars)
        }

        return $token;
    }

    /**
     * Clamp a value between min and max.
     *
     * @param int $value The value to clamp.
     * @param int $min   Minimum allowed value.
     * @param int $max   Maximum allowed value.
     * @return int Clamped value.
     */
    private static function clamp( int $value, int $min, int $max ): int {
        return max( $min, min( $max, $value ) );
    }

    /**
     * Convert a value to boolean.
     *
     * @param mixed $value The value to convert.
     * @return bool Boolean representation.
     */
    private static function to_bool( mixed $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_string( $value ) ) {
            $value = strtolower( trim( $value ) );
            return in_array( $value, array( '1', 'true', 'yes', 'on' ), true );
        }

        return (bool) $value;
    }
}
