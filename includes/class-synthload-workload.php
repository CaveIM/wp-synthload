<?php
/**
 * Workload simulation engine for WP Synthetic Load plugin.
 *
 * @package WP_SynthLoad
 */

// Security check - prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SynthLoad_Workload
 *
 * Executes simulated workload with configurable parameters.
 */
class SynthLoad_Workload {

    /**
     * Database instance.
     *
     * @var SynthLoad_Db
     */
    private SynthLoad_Db $db;

    /**
     * Settings array.
     *
     * @var array
     */
    private array $settings;

    /**
     * Unique request identifier.
     *
     * @var string
     */
    private string $request_id;

    /**
     * Workload start time.
     *
     * @var float
     */
    private float $start_time = 0;

    /**
     * Number of reads performed.
     *
     * @var int
     */
    private int $reads_performed = 0;

    /**
     * Number of writes performed.
     *
     * @var int
     */
    private int $writes_performed = 0;

    /**
     * Whether cache was hit.
     *
     * @var bool
     */
    private bool $cache_hit = false;

    /**
     * Detailed operation log.
     *
     * @var array
     */
    private array $operations = array();

    /**
     * Constructor.
     *
     * @param SynthLoad_Db $db       Database instance.
     * @param array        $settings Settings array.
     */
    public function __construct( SynthLoad_Db $db, array $settings ) {
        $this->db         = $db;
        $this->settings   = $settings;
        $this->request_id = wp_generate_uuid4();
    }

    /**
     * Execute the synthetic workload and return results.
     *
     * @return array Execution results.
     */
    public function execute(): array {
        $this->start_time = microtime( true );

        // Check execution time limit
        $limits        = SynthLoad_Settings::get_hard_limits();
        $max_execution = $limits['max_total_duration_ms'] / 1000;
        $php_limit     = (int) ini_get( 'max_execution_time' );

        if ( $php_limit > 0 && $php_limit < ( $max_execution + 5 ) ) {
            return array(
                'status'    => 'error',
                'message'   => 'Insufficient execution time limit',
                'execution' => array(
                    'duration_ms' => 0,
                    'target_ms'   => 0,
                    'db_reads'    => 0,
                    'db_writes'   => 0,
                    'cache_hit'   => false,
                ),
            );
        }

        // Calculate target duration
        $target_duration = $this->calculate_target_duration();

        $this->log_debug( "Starting workload. Request ID: {$this->request_id}, Target: {$target_duration}ms" );

        // Perform reads
        $this->perform_reads();

        // Perform writes
        $this->perform_writes();

        // Calculate elapsed time
        $elapsed_ms = $this->get_elapsed_ms();

        // Burn remaining time if needed
        if ( $elapsed_ms < $target_duration ) {
            $this->burn_remaining_time( $target_duration, $elapsed_ms );
        }

        // Calculate final elapsed time
        $final_elapsed = $this->get_elapsed_ms();

        $this->log_debug( "Workload complete. Reads: {$this->reads_performed}, Writes: {$this->writes_performed}, Duration: {$final_elapsed}ms" );

        return $this->build_response( $target_duration );
    }

    /**
     * Calculate target duration with jitter.
     *
     * @return int Target duration in milliseconds.
     */
    private function calculate_target_duration(): int {
        $target = (int) $this->settings['target_duration_ms'];
        $jitter = (int) $this->settings['duration_jitter_ms'];
        $limits = SynthLoad_Settings::get_hard_limits();

        // Apply hard limit
        $target = min( $target, $limits['max_total_duration_ms'] );

        // Apply jitter if randomization enabled
        if ( $this->settings['randomize_workload'] && $jitter > 0 ) {
            $variation = $this->random_in_range( -$jitter, $jitter );
            $target   += $variation;
        }

        // Ensure minimum of 100ms
        return max( 100, $target );
    }

    /**
     * Perform database reads.
     */
    private function perform_reads(): void {
        $count  = (int) $this->settings['read_query_count'];
        $limits = SynthLoad_Settings::get_hard_limits();

        // Apply hard limit
        $count = min( $count, $limits['max_read_query_count'] );

        // Apply randomization
        if ( $this->settings['randomize_workload'] && $count > 0 ) {
            $variance = max( 1, (int) ( $count * 0.1 ) ); // 10% variance
            $count    = $this->random_in_range( $count - $variance, $count + $variance );
            $count    = min( $count, $limits['max_read_query_count'] );
        }

        if ( $count < 1 ) {
            return;
        }

        // Split between WP reads and direct reads
        $wp_read_count     = (int) ceil( $count / 2 );
        $direct_read_count = $count - $wp_read_count;

        $this->perform_wp_reads( $wp_read_count );
        $this->perform_direct_reads( $direct_read_count );
    }

    /**
     * Perform WordPress-level reads.
     *
     * @param int $count Number of reads to perform.
     */
    private function perform_wp_reads( int $count ): void {
        $bypass_cache = $this->settings['bypass_object_cache'];

        // Common options to read
        $options = array( 'blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'users_can_register', 'date_format', 'time_format' );

        for ( $i = 0; $i < $count; $i++ ) {
            $operation = $i % 3;

            switch ( $operation ) {
                case 0:
                    // Read an option
                    $option_key = $options[ array_rand( $options ) ];
                    $value      = get_option( $option_key );
                    $this->log_operation( 'read', 'get_option', array(
                        'option' => $option_key,
                        'found'  => false !== $value,
                    ) );
                    break;

                case 1:
                    // Query posts
                    $args = array(
                        'post_type'      => 'post',
                        'posts_per_page' => 5,
                        'orderby'        => 'rand',
                        'no_found_rows'  => true,
                    );

                    if ( $bypass_cache ) {
                        $args['cache_results']          = false;
                        $args['update_post_meta_cache'] = false;
                        $args['update_post_term_cache'] = false;
                    }

                    $posts = get_posts( $args );
                    $this->log_operation( 'read', 'get_posts', array(
                        'post_type'    => 'post',
                        'count'        => count( $posts ),
                        'bypass_cache' => $bypass_cache,
                    ) );
                    break;

                case 2:
                    // Query users (cache-friendly)
                    if ( ! $bypass_cache ) {
                        $users = get_users( array(
                            'number' => 3,
                            'fields' => 'ID',
                        ) );
                        $this->log_operation( 'read', 'get_users', array(
                            'count' => count( $users ),
                        ) );
                    } else {
                        // Fall back to option read when bypassing cache
                        $option_key = $options[ array_rand( $options ) ];
                        $value      = get_option( $option_key );
                        $this->log_operation( 'read', 'get_option', array(
                            'option' => $option_key,
                            'found'  => false !== $value,
                        ) );
                    }
                    break;
            }

            ++$this->reads_performed;
            $this->handle_db_error( 'wp_reads' );
        }
    }

    /**
     * Perform direct SQL reads.
     *
     * @param int $count Number of reads to perform.
     */
    private function perform_direct_reads( int $count ): void {
        global $wpdb;

        for ( $i = 0; $i < $count; $i++ ) {
            $operation = $i % 3;

            switch ( $operation ) {
                case 0:
                    // Read from posts table
                    $posts = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_status = 'publish' ORDER BY RAND() LIMIT %d",
                            3
                        )
                    );
                    $this->log_operation( 'read', 'direct_sql_posts', array(
                        'table' => $wpdb->posts,
                        'count' => count( $posts ),
                    ) );
                    break;

                case 1:
                    // Read from plugin events table
                    $events = $this->db->read_random_events( 5 );
                    $this->log_operation( 'read', 'direct_sql_events', array(
                        'table' => $this->db->get_table_name(),
                        'count' => count( $events ),
                    ) );
                    break;

                case 2:
                    // Read from options table
                    $options = $wpdb->get_results(
                        $wpdb->prepare(
                            "SELECT option_name, option_value FROM {$wpdb->options} WHERE autoload = 'yes' LIMIT %d",
                            10
                        )
                    );
                    $this->log_operation( 'read', 'direct_sql_options', array(
                        'table' => $wpdb->options,
                        'count' => count( $options ),
                    ) );
                    break;
            }

            ++$this->reads_performed;
            $this->handle_db_error( 'direct_reads' );
        }
    }

    /**
     * Perform database writes.
     */
    private function perform_writes(): void {
        $count  = (int) $this->settings['write_op_count'];
        $limits = SynthLoad_Settings::get_hard_limits();

        // Apply hard limit
        $count = min( $count, $limits['max_write_op_count'] );

        // Apply randomization
        if ( $this->settings['randomize_workload'] && $count > 0 ) {
            $variance = max( 1, (int) ( $count * 0.1 ) );
            $count    = $this->random_in_range( $count - $variance, $count + $variance );
            $count    = min( $count, $limits['max_write_op_count'] );
        }

        if ( $count < 1 ) {
            return;
        }

        // Split: 60% INSERT, 30% UPDATE, 10% DELETE
        $insert_count = (int) ceil( $count * 0.6 );
        $update_count = (int) ceil( $count * 0.3 );
        $delete_count = $count - $insert_count - $update_count;

        $this->perform_inserts( $insert_count );
        $this->perform_updates( $update_count );
        $this->perform_deletes( max( 0, $delete_count ) );
    }

    /**
     * Perform INSERT operations.
     *
     * @param int $count Number of inserts to perform.
     */
    private function perform_inserts( int $count ): void {
        for ( $i = 0; $i < $count; $i++ ) {
            $payload = wp_json_encode( array(
                'timestamp'  => gmdate( 'c' ),
                'request_id' => $this->request_id,
                'iteration'  => $i,
                'random'     => wp_generate_password( 16, false ),
            ) );

            $event_request_id = $this->request_id . '-' . $i;
            $insert_id        = $this->db->insert_event( array(
                'request_id' => $event_request_id,
                'payload'    => $payload,
            ) );

            $this->log_operation( 'write', 'insert', array(
                'table'      => $this->db->get_table_name(),
                'insert_id'  => $insert_id,
                'request_id' => $event_request_id,
            ) );

            ++$this->writes_performed;
            $this->handle_db_error( 'inserts' );
        }
    }

    /**
     * Perform UPDATE operations.
     *
     * @param int $count Number of updates to perform.
     */
    private function perform_updates( int $count ): void {
        global $wpdb;

        if ( $count < 1 ) {
            return;
        }

        // Get random events to update
        $events = $this->db->read_random_events( $count );

        foreach ( $events as $event ) {
            $new_payload = wp_json_encode( array(
                'updated_at' => gmdate( 'c' ),
                'updated_by' => $this->request_id,
            ) );

            $rows_affected = $wpdb->update(
                $this->db->get_table_name(),
                array( 'payload' => $new_payload ),
                array( 'id' => $event->id ),
                array( '%s' ),
                array( '%d' )
            );

            $this->log_operation( 'write', 'update', array(
                'table'         => $this->db->get_table_name(),
                'event_id'      => $event->id,
                'rows_affected' => $rows_affected,
            ) );

            ++$this->writes_performed;
            $this->handle_db_error( 'updates' );
        }
    }

    /**
     * Perform DELETE operations.
     *
     * @param int $count Number of deletes to perform.
     */
    private function perform_deletes( int $count ): void {
        $limits        = SynthLoad_Settings::get_hard_limits();
        $max_rows      = $limits['max_rows_to_keep'];
        $current_count = $this->db->count_events();

        // Only delete if we're over 80% of max
        if ( $current_count < ( $max_rows * 0.8 ) ) {
            $this->log_operation( 'write', 'delete_skipped', array(
                'reason'        => 'below_threshold',
                'current_count' => $current_count,
                'threshold'     => (int) ( $max_rows * 0.8 ),
            ) );
            return;
        }

        // Limit cleanup per request
        $delete_limit = min( $count, 100 );
        $deleted      = $this->db->cleanup_old_events( 3600, $delete_limit );

        $this->log_operation( 'write', 'delete', array(
            'table'        => $this->db->get_table_name(),
            'rows_deleted' => $deleted,
            'max_age_sec'  => 3600,
        ) );

        $this->writes_performed += $deleted;
        $this->handle_db_error( 'deletes' );
    }

    /**
     * Burn remaining time with CPU work.
     *
     * @param int $target_ms  Target duration in milliseconds.
     * @param int $elapsed_ms Already elapsed time in milliseconds.
     */
    private function burn_remaining_time( int $target_ms, int $elapsed_ms ): void {
        $remaining_ms = $target_ms - $elapsed_ms;

        if ( $remaining_ms <= 0 ) {
            return;
        }

        $burn_start = $this->get_elapsed_ms();
        $end_time   = microtime( true ) + ( $remaining_ms / 1000 );
        $counter    = 0;

        // CPU-intensive operations to burn time
        while ( microtime( true ) < $end_time ) {
            // Mix of operations to keep CPU busy
            $data = str_repeat( 'x', 1000 );
            $hash = hash( 'sha256', $data . $counter );
            wp_json_encode( array( 'hash' => $hash, 'counter' => $counter ) );

            ++$counter;

            // Check time every 100 iterations
            if ( 0 === $counter % 100 && microtime( true ) >= $end_time ) {
                break;
            }
        }

        $this->log_operation( 'cpu', 'burn_time', array(
            'target_burn_ms' => $remaining_ms,
            'actual_burn_ms' => $this->get_elapsed_ms() - $burn_start,
            'iterations'     => $counter,
        ) );
    }

    /**
     * Build response payload.
     *
     * @param int $target_ms Target duration used.
     * @return array Response array.
     */
    private function build_response( int $target_ms ): array {
        $duration_ms = $this->get_elapsed_ms();

        return array(
            'status'     => 'ok',
            'timestamp'  => gmdate( 'c' ),
            'request_id' => $this->request_id,
            'execution'  => array(
                'duration_ms' => $duration_ms,
                'target_ms'   => $target_ms,
                'db_reads'    => $this->reads_performed,
                'db_writes'   => $this->writes_performed,
                'cache_hit'   => $this->cache_hit,
            ),
            'operations' => $this->operations,
            'server'     => array(
                'php_version' => PHP_VERSION,
                'wp_version'  => get_bloginfo( 'version' ),
            ),
        );
    }

    /**
     * Log an operation.
     *
     * @param string $type    Operation type (read, write, cpu).
     * @param string $action  Specific action taken.
     * @param array  $details Optional details.
     */
    private function log_operation( string $type, string $action, array $details = array() ): void {
        $this->operations[] = array(
            'type'    => $type,
            'action'  => $action,
            'time_ms' => $this->get_elapsed_ms(),
            'details' => $details,
        );
    }

    /**
     * Get elapsed time in milliseconds.
     *
     * @return int Elapsed milliseconds.
     */
    private function get_elapsed_ms(): int {
        return (int) ( ( microtime( true ) - $this->start_time ) * 1000 );
    }

    /**
     * Generate random integer in range.
     *
     * @param int $min Minimum value.
     * @param int $max Maximum value.
     * @return int Random value.
     */
    private function random_in_range( int $min, int $max ): int {
        if ( $min > $max ) {
            $temp = $min;
            $min  = $max;
            $max  = $temp;
        }

        return random_int( $min, $max );
    }

    /**
     * Handle database errors.
     *
     * @param string $context Error context.
     */
    private function handle_db_error( string $context ): void {
        global $wpdb;

        if ( $wpdb->last_error ) {
            $this->log_debug( "DB Error in {$context}: " . $wpdb->last_error );
            // Clear the error
            $wpdb->last_error = '';
        }
    }

    /**
     * Log debug message.
     *
     * @param string $message Message to log.
     */
    private function log_debug( string $message ): void {
        if ( $this->settings['debug_logging_enabled'] ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( '[SynthLoad] ' . $message );
        }
    }
}
