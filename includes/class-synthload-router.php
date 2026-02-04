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

        // Handle HEAD requests - quick response for uptime checks
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === $_SERVER['REQUEST_METHOD'] ) {
            $this->send_response( 200, '' );
            return;
        }

        // Validate access if token is required
        if ( ! empty( $settings['access_token'] ) ) {
            if ( ! $this->validate_access() ) {
                $this->send_response( 403, 'Forbidden' );
                return;
            }
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

        // If no token required, allow access
        if ( empty( $access_token ) ) {
            return true;
        }

        // Check header first (preferred)
        $header_token = '';
        if ( isset( $_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] ) ) {
            $header_token = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] ) );
        }

        if ( ! empty( $header_token ) && hash_equals( $access_token, $header_token ) ) {
            return true;
        }

        // Check query parameter
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $query_token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

        if ( ! empty( $query_token ) && hash_equals( $access_token, $query_token ) ) {
            return true;
        }

        return false;
    }

    /**
     * Dispatch to workload handler.
     */
    private function dispatch_workload(): void {
        global $wpdb;

        // Get settings
        $settings = SynthLoad_Settings::get_all();

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
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // Informational headers
        if ( defined( 'SYNTHLOAD_VERSION' ) ) {
            header( 'X-SynthLoad-Version: ' . SYNTHLOAD_VERSION );
        }
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
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        if ( defined( 'SYNTHLOAD_VERSION' ) ) {
            header( 'X-SynthLoad-Version: ' . SYNTHLOAD_VERSION );
        }
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
