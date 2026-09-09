<?php
/**
 * URL routing for WP Synthetic Load plugin.
 *
 * @package WP_SynthLoad
 */

// Security check - prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SynthLoad_Router
 *
 * Handles URL rewriting, query variable registration, and request routing.
 */
class SynthLoad_Router {

    /**
     * Query variable for synthetic load requests.
     */
    const QV_SYNTHLOAD = 'synthload_request';

    /**
     * Query variable for Loader.io verification.
     */
    const QV_LOADERIO = 'loaderio_verify';

    /**
     * Register rewrite rules for plugin endpoints.
     */
    public static function register_rewrites(): void {
        $settings = SynthLoad_Settings::get_all();
        $slug     = $settings['endpoint_slug'];

        // Synthetic load endpoint: /{slug}
        add_rewrite_rule(
            '^' . preg_quote( $slug, '/' ) . '/?$',
            'index.php?' . self::QV_SYNTHLOAD . '=1',
            'top'
        );

        // Loader.io verification: /loaderio-{token}.txt
        add_rewrite_rule(
            '^loaderio-([a-zA-Z0-9]+)\.txt$',
            'index.php?' . self::QV_LOADERIO . '=$matches[1]',
            'top'
        );

        // Loader.io verification: /loaderio-{token}.html
        add_rewrite_rule(
            '^loaderio-([a-zA-Z0-9]+)\.html$',
            'index.php?' . self::QV_LOADERIO . '=$matches[1]',
            'top'
        );

        // Loader.io verification: /loaderio-{token}/
        add_rewrite_rule(
            '^loaderio-([a-zA-Z0-9]+)/?$',
            'index.php?' . self::QV_LOADERIO . '=$matches[1]',
            'top'
        );
    }

    /**
     * Add custom query variables.
     *
     * @param array $vars Existing query variables.
     * @return array Modified query variables.
     */
    public static function add_query_vars( array $vars ): array {
        $vars[] = self::QV_SYNTHLOAD;
        $vars[] = self::QV_LOADERIO;

        return $vars;
    }

    /**
     * Handle incoming requests for plugin endpoints.
     */
    public function handle_request(): void {
        // Check for Loader.io verification request
        $loaderio_token = get_query_var( self::QV_LOADERIO );
        if ( ! empty( $loaderio_token ) ) {
            $this->serve_loaderio_token( $loaderio_token );
            return;
        }

        // Check for synthetic load request
        $synthload = get_query_var( self::QV_SYNTHLOAD );
        if ( empty( $synthload ) ) {
            return; // Not our request
        }

        // Get settings
        $settings = SynthLoad_Settings::get_all();

        // Check if endpoint is enabled
        if ( ! $settings['endpoint_enabled'] ) {
            $this->send_response( 404, 'Not Found' );
            return;
        }

        // The workload endpoint is never available without header authentication.
        if ( ! $this->validate_access() ) {
            $this->send_response( 403, 'Forbidden' );
            return;
        }

        // Authenticated HEAD requests return without executing workload.
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
            $this->send_response( 200, '' );
            return;
        }

        // Dispatch workload
        $this->dispatch_workload();
    }

    /**
     * Serve Loader.io verification token.
     *
     * @param string $requested_token The token requested in the URL.
     */
    private function serve_loaderio_token( string $requested_token ): void {
        $settings     = SynthLoad_Settings::get_all();
        $stored_token = $settings['loaderio_token'];

        // If no token configured, return 404
        if ( empty( $stored_token ) ) {
            $this->send_response( 404, 'Not Found' );
            return;
        }

        // Extract the token ID (without "loaderio-" prefix) for comparison
        $stored_token_id = SynthLoad_Settings::extract_token_id( $stored_token );

        // Verify the requested token matches the stored token ID
        if ( $requested_token !== $stored_token_id ) {
            $this->send_response( 404, 'Not Found' );
            return;
        }

        // Serve the token in Loader.io expected format
        http_response_code( 200 );
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Cache-Control: no-store' );
        header( 'X-Robots-Tag: noindex, nofollow' );

        echo 'loaderio-' . esc_html( $stored_token_id );
        exit;
    }

    /**
     * Validate access using token.
     *
     * @return bool True if access is valid.
     */
    private function validate_access(): bool {
        $settings     = SynthLoad_Settings::get_all();
        $access_token = $settings['access_token'];

        // Never allow an enabled endpoint without a configured token.
        if ( empty( $access_token ) || ! SynthLoad_Settings::is_valid_access_token( $access_token ) ) {
            return false;
        }

        // Check header first (preferred)
        $header_token = '';
        if ( isset( $_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] ) ) {
            $header_token = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] ) );
        }

        if ( ! empty( $header_token ) && hash_equals( $access_token, $header_token ) ) {
            return true;
        }

        return false;
    }

    /**
     * Disable all forms of caching for the current request.
     *
     * This method attempts to bypass caching at multiple levels:
     * - PHP session (most caches skip session requests)
     * - WordPress page caching plugins (DONOTCACHEPAGE constant)
     * - LiteSpeed Cache
     * - Nginx fastcgi_cache
     * - Varnish/proxy caches
     * - CDN caching (Cloudflare, etc.)
     */
    private function disable_caching(): void {
        // Start a PHP session - this simulates logged-in membership user behavior
        // and causes most caches to bypass (they skip requests with session cookies)
        if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
            // Use a non-blocking session to avoid lock contention under load
            session_cache_limiter( 'nocache' );
            session_start( array( 'read_and_close' => true ) );
        }

        // WordPress caching plugins check this constant
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }

        // WP Super Cache
        if ( ! defined( 'DONOTCACHEDB' ) ) {
            define( 'DONOTCACHEDB', true );
        }

        // Disable object caching for this request
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
            define( 'DONOTCACHEOBJECT', true );
        }

        // Batcache (WordPress.com VIP, Automattic)
        if ( function_exists( 'batcache_cancel' ) ) {
            batcache_cancel();
        }

        // WP Rocket
        if ( function_exists( 'rocket_clean_domain' ) ) {
            add_filter( 'do_rocket_generate_caching_files', '__return_false' );
        }

        // Disable WordPress HTTP cache
        add_filter( 'wp_headers', function( $headers ) {
            unset( $headers['ETag'] );
            unset( $headers['Last-Modified'] );
            return $headers;
        }, 9999 );

        // Send headers immediately to prevent caching
        if ( ! headers_sent() ) {
            // Standard cache prevention
            header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
            header( 'Pragma: no-cache' );
            header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );

            // LiteSpeed
            header( 'X-LiteSpeed-Cache-Control: no-cache' );

            // Cloudflare
            header( 'CF-Cache-Status: DYNAMIC' );

            // Nginx
            header( 'X-Accel-Expires: 0' );

            // Varnish
            header( 'X-Varnish-TTL: 0' );

            // Generic cache bypass
            header( 'Surrogate-Control: no-store' );

            // Vary header to prevent CDN caching
            header( 'Vary: *' );
        }
    }

    /**
     * Get workload settings with query parameter overrides.
     *
     * Allows the AJAX test to pass form values via query parameters
     * so tests run with the exact settings shown in the UI.
     *
     * @return array Settings array with any query param overrides applied.
     */
    private function get_workload_settings(): array {
        $settings = SynthLoad_Settings::get_all();
        $limits   = SynthLoad_Settings::get_hard_limits();

        // Check for query parameter overrides (used by AJAX test)
        // phpcs:disable WordPress.Security.NonceVerification.Recommended

        if ( isset( $_GET['read_query_count'] ) ) {
            $value = (int) $_GET['read_query_count'];
            $settings['read_query_count'] = max( 0, min( $value, $limits['max_read_query_count'] ) );
        }

        if ( isset( $_GET['write_op_count'] ) ) {
            $value = (int) $_GET['write_op_count'];
            $settings['write_op_count'] = max( 0, min( $value, $limits['max_write_op_count'] ) );
        }

        if ( isset( $_GET['cpu_iterations'] ) ) {
            $value = (int) $_GET['cpu_iterations'];
            $settings['cpu_iterations'] = max( 0, min( $value, $limits['max_cpu_iterations'] ) );
        }

        if ( isset( $_GET['bypass_object_cache'] ) ) {
            $settings['bypass_object_cache'] = in_array( $_GET['bypass_object_cache'], array( '1', 'true', 'yes' ), true );
        }

        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return $settings;
    }

    /**
     * Dispatch to workload handler.
     */
    private function dispatch_workload(): void {
        global $wpdb;

        // Disable all caching before workload execution
        $this->disable_caching();

        // Get settings and apply any query parameter overrides
        $settings = $this->get_workload_settings();

        // Create dependencies
        $db = new SynthLoad_Db( $wpdb );

        // Ensure table exists (safety check)
        if ( ! $db->table_exists() ) {
            SynthLoad_Db::create_table();
        }

        // Create and execute workload
        $workload = new SynthLoad_Workload( $db, $settings );

        try {
            $result = $workload->execute();

            // Check for error status
            if ( isset( $result['status'] ) && 'error' === $result['status'] ) {
                $message = $result['message'] ?? 'Error';
                $this->send_response( 500, $message );
                return;
            }

            // Determine response format
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( isset( $_GET['format'] ) && 'json' === $_GET['format'] ) {
                $this->send_json_response( 200, $result );
            } else {
                $this->send_response( 200, 'OK' );
            }
        } catch ( Exception $e ) {
            // Log error if debug enabled
            if ( $settings['debug_logging_enabled'] ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( '[SynthLoad] Workload error: ' . $e->getMessage() );
            }

            $this->send_response( 500, 'Internal Server Error' );
        }
    }

    /**
     * Send a plain text response.
     *
     * @param int    $status_code HTTP status code.
     * @param string $body        Response body.
     */
    private function send_response( int $status_code, string $body ): void {
        http_response_code( $status_code );

        // Required headers
        header( 'Content-Type: text/plain; charset=utf-8' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // Bypass LiteSpeed cache
        header( 'X-LiteSpeed-Cache-Control: no-cache' );

        header( 'X-Robots-Tag: noindex, nofollow' );

        // Security headers
        header( 'X-Content-Type-Options: nosniff' );

        echo esc_html( $body );
        exit;
    }

    /**
     * Send a JSON response.
     *
     * @param int   $status_code HTTP status code.
     * @param array $data        Response data.
     */
    private function send_json_response( int $status_code, array $data ): void {
        http_response_code( $status_code );

        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate, private' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // Bypass LiteSpeed cache
        header( 'X-LiteSpeed-Cache-Control: no-cache' );

        header( 'X-Robots-Tag: noindex, nofollow' );
        header( 'X-Content-Type-Options: nosniff' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
        echo json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        exit;
    }

    /**
     * Get rewrite rules this plugin uses.
     *
     * @return array Array of pattern => rewrite pairs.
     */
    public static function get_rewrite_rules(): array {
        $settings = SynthLoad_Settings::get_all();
        $slug     = $settings['endpoint_slug'];

        return array(
            '^' . preg_quote( $slug, '/' ) . '/?$' => 'index.php?' . self::QV_SYNTHLOAD . '=1',
            '^loaderio-([a-zA-Z0-9]+)\.txt$'       => 'index.php?' . self::QV_LOADERIO . '=$matches[1]',
        );
    }

    /**
     * Check if rewrite rules need to be flushed.
     *
     * @return bool True if rules need flush.
     */
    public static function rules_need_flush(): bool {
        global $wp_rewrite;

        // $wp_rewrite may not be initialized during plugins_loaded
        if ( ! $wp_rewrite instanceof WP_Rewrite ) {
            return false; // Can't check yet, defer to init
        }

        $current_rules = $wp_rewrite->wp_rewrite_rules();
        if ( ! is_array( $current_rules ) ) {
            return true;
        }

        $our_rules = self::get_rewrite_rules();

        foreach ( $our_rules as $pattern => $rewrite ) {
            if ( ! isset( $current_rules[ $pattern ] ) ) {
                return true;
            }
        }

        return false;
    }
}
