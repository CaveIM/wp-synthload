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
     * Number of CPU iterations performed.
     *
     * @var int
     */
    private int $cpu_iterations_performed = 0;

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

        $this->log_debug( "Starting workload. Request ID: {$this->request_id}" );

        // Perform database reads
        $this->perform_reads();

        // Perform database writes
        $this->perform_writes();

        // Perform CPU work
        $this->perform_cpu_work();

        // Calculate final elapsed time
        $final_elapsed = $this->get_elapsed_ms();

        $this->log_debug( "Workload complete. Reads: {$this->reads_performed}, Writes: {$this->writes_performed}, CPU iterations: {$this->cpu_iterations_performed}, Duration: {$final_elapsed}ms" );

        return $this->build_response();
    }

    /**
     * Perform database reads.
     */
    private function perform_reads(): void {
        $count  = (int) $this->settings['read_query_count'];
        $limits = SynthLoad_Settings::get_hard_limits();

        // Apply hard limit
        $count = min( $count, $limits['max_read_query_count'] );

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
        global $wpdb;

        $bypass_cache = $this->settings['bypass_object_cache'];

        // Get pool of random option names, post IDs, and user IDs for this request
        $option_names = $this->get_random_option_names( max( 20, (int) ceil( $count / 3 ) ) );
        $post_ids     = $this->get_random_post_ids( max( 20, (int) ceil( $count / 3 ) ) );
        $user_ids     = $this->get_random_user_ids( max( 10, (int) ceil( $count / 6 ) ) );

        for ( $i = 0; $i < $count; $i++ ) {
            $operation = $i % 3;

            switch ( $operation ) {
                case 0:
                    // Read a random option
                    if ( ! empty( $option_names ) ) {
                        $option_key = $option_names[ array_rand( $option_names ) ];
                        $value      = get_option( $option_key );
                        $this->log_operation( 'read', 'get_option', array(
                            'option' => $option_key,
                            'found'  => false !== $value,
                        ) );
                    }
                    break;

                case 1:
                    // Query posts by random IDs (avoids expensive ORDER BY RAND())
                    if ( ! empty( $post_ids ) ) {
                        $random_ids = array_slice( $post_ids, 0, min( 5, count( $post_ids ) ) );
                        shuffle( $post_ids ); // Shuffle for next iteration

                        $args = array(
                            'post_type'      => 'any',
                            'post__in'       => $random_ids,
                            'posts_per_page' => count( $random_ids ),
                            'no_found_rows'  => true,
                            'orderby'        => 'post__in',
                        );

                        if ( $bypass_cache ) {
                            $args['cache_results']          = false;
                            $args['update_post_meta_cache'] = false;
                            $args['update_post_term_cache'] = false;
                        }

                        $posts = get_posts( $args );
                        $this->log_operation( 'read', 'get_posts', array(
                            'post_ids'     => $random_ids,
                            'count'        => count( $posts ),
                            'bypass_cache' => $bypass_cache,
                        ) );
                    }
                    break;

                case 2:
                    // Query random users
                    if ( ! empty( $user_ids ) ) {
                        $random_user_ids = array_slice( $user_ids, 0, min( 3, count( $user_ids ) ) );
                        shuffle( $user_ids ); // Shuffle for next iteration

                        $users = get_users( array(
                            'include' => $random_user_ids,
                            'fields'  => 'ID',
                        ) );
                        $this->log_operation( 'read', 'get_users', array(
                            'user_ids' => $random_user_ids,
                            'count'    => count( $users ),
                        ) );
                    }
                    break;
            }

            ++$this->reads_performed;
            $this->handle_db_error( 'wp_reads' );
        }
    }

    /**
     * Get random option names from the database.
     *
     * @param int $limit Maximum number of option names to return.
     * @return array Array of option names.
     */
    private function get_random_option_names( int $limit ): array {
        global $wpdb;

        // Use a random offset to get different options each request
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
        if ( $total < 1 ) {
            return array();
        }

        $offset = random_int( 0, max( 0, $total - $limit ) );

        $names = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        return $names ?: array();
    }

    /**
     * Get random post IDs from the database.
     *
     * @param int $limit Maximum number of post IDs to return.
     * @return array Array of post IDs.
     */
    private function get_random_post_ids( int $limit ): array {
        global $wpdb;

        // Use a random offset instead of ORDER BY RAND() for better performance
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish'"
        );
        if ( $total < 1 ) {
            return array();
        }

        $offset = random_int( 0, max( 0, $total - $limit ) );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        // Shuffle to randomize order
        if ( $ids ) {
            shuffle( $ids );
        }

        return $ids ?: array();
    }

    /**
     * Get random user IDs from the database.
     *
     * @param int $limit Maximum number of user IDs to return.
     * @return array Array of user IDs.
     */
    private function get_random_user_ids( int $limit ): array {
        global $wpdb;

        // Use a random offset
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
        if ( $total < 1 ) {
            return array();
        }

        $offset = random_int( 0, max( 0, $total - $limit ) );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        // Shuffle to randomize order
        if ( $ids ) {
            shuffle( $ids );
        }

        return $ids ?: array();
    }

    /**
     * Get random option IDs from the database.
     *
     * @param int $limit Maximum number of option IDs to return.
     * @return array Array of option IDs.
     */
    private function get_random_option_ids( int $limit ): array {
        global $wpdb;

        // Use a random offset
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
        if ( $total < 1 ) {
            return array();
        }

        $offset = random_int( 0, max( 0, $total - $limit ) );

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_id FROM {$wpdb->options} LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        // Shuffle to randomize order
        if ( $ids ) {
            shuffle( $ids );
        }

        return $ids ?: array();
    }

    /**
     * Perform direct SQL reads.
     *
     * @param int $count Number of reads to perform.
     */
    private function perform_direct_reads( int $count ): void {
        global $wpdb;

        // Get random IDs upfront for efficient querying
        $post_ids   = $this->get_random_post_ids( max( 20, (int) ceil( $count / 3 ) ) );
        $option_ids = $this->get_random_option_ids( max( 20, (int) ceil( $count / 3 ) ) );

        for ( $i = 0; $i < $count; $i++ ) {
            $operation = $i % 3;

            switch ( $operation ) {
                case 0:
                    // Read from posts table using random IDs
                    if ( ! empty( $post_ids ) ) {
                        $random_ids = array_slice( $post_ids, 0, min( 3, count( $post_ids ) ) );
                        shuffle( $post_ids );

                        $ids_placeholder = implode( ',', array_map( 'intval', $random_ids ) );
                        $posts           = $wpdb->get_results(
                            "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE ID IN ({$ids_placeholder})"
                        );
                        $this->log_operation( 'read', 'direct_sql_posts', array(
                            'table'    => $wpdb->posts,
                            'post_ids' => $random_ids,
                            'count'    => count( $posts ),
                        ) );
                    }
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
                    // Read from options table using random option IDs
                    if ( ! empty( $option_ids ) ) {
                        $random_ids = array_slice( $option_ids, 0, min( 10, count( $option_ids ) ) );
                        shuffle( $option_ids );

                        $ids_placeholder = implode( ',', array_map( 'intval', $random_ids ) );
                        $options         = $wpdb->get_results(
                            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_id IN ({$ids_placeholder})"
                        );
                        $this->log_operation( 'read', 'direct_sql_options', array(
                            'table'      => $wpdb->options,
                            'option_ids' => $random_ids,
                            'count'      => count( $options ),
                        ) );
                    }
                    break;
            }

            ++$this->reads_performed;
            $this->handle_db_error( 'direct_reads' );
        }
    }

    /**
     * Perform database writes.
     *
     * Each write is a complete lifecycle: INSERT → UPDATE → DELETE.
     * This keeps the table clean and ensures exact operation counts.
     */
    private function perform_writes(): void {
        $count  = (int) $this->settings['write_op_count'];
        $limits = SynthLoad_Settings::get_hard_limits();

        // Apply hard limit
        $count = min( $count, $limits['max_write_op_count'] );

        if ( $count < 1 ) {
            return;
        }

        $this->perform_write_cycles( $count );
    }

    /**
     * Perform complete write cycles (INSERT → UPDATE → DELETE).
     *
     * @param int $count Number of write cycles to perform.
     */
    private function perform_write_cycles( int $count ): void {
        global $wpdb;

        for ( $i = 0; $i < $count; $i++ ) {
            // 1. INSERT
            $payload          = wp_json_encode( $this->generate_random_payload( $i ) );
            $event_request_id = $this->request_id . '-' . $i . '-' . wp_generate_password( 8, false );

            $insert_id = $this->db->insert_event( array(
                'request_id' => $event_request_id,
                'payload'    => $payload,
            ) );

            $this->log_operation( 'write', 'insert', array(
                'table'      => $this->db->get_table_name(),
                'insert_id'  => $insert_id,
                'request_id' => $event_request_id,
            ) );
            ++$this->writes_performed;
            $this->handle_db_error( 'insert' );

            if ( ! $insert_id ) {
                continue; // Skip update/delete if insert failed
            }

            // 2. UPDATE
            $update_payload = wp_json_encode( array(
                'updated_at' => gmdate( 'c' ),
                'microtime'  => microtime( true ),
                'updated_by' => $this->request_id,
                'update_key' => wp_generate_password( 24, false ),
                'random_val' => random_int( 1, 999999 ),
            ) );

            $wpdb->update(
                $this->db->get_table_name(),
                array( 'payload' => $update_payload ),
                array( 'id' => $insert_id ),
                array( '%s' ),
                array( '%d' )
            );

            $this->log_operation( 'write', 'update', array(
                'table'    => $this->db->get_table_name(),
                'event_id' => $insert_id,
            ) );
            ++$this->writes_performed;
            $this->handle_db_error( 'update' );

            // 3. DELETE
            $wpdb->delete(
                $this->db->get_table_name(),
                array( 'id' => $insert_id ),
                array( '%d' )
            );

            $this->log_operation( 'write', 'delete', array(
                'table'    => $this->db->get_table_name(),
                'event_id' => $insert_id,
            ) );
            ++$this->writes_performed;
            $this->handle_db_error( 'delete' );
        }
    }

    /**
     * Generate a random payload with varying size and content.
     *
     * @param int $iteration The current iteration number.
     * @return array Random payload data.
     */
    private function generate_random_payload( int $iteration ): array {
        // Vary payload size randomly
        $extra_data_size = random_int( 50, 500 );

        return array(
            'timestamp'   => gmdate( 'c' ),
            'microtime'   => microtime( true ),
            'request_id'  => $this->request_id,
            'iteration'   => $iteration,
            'random_key'  => wp_generate_password( 32, false ),
            'random_int'  => random_int( 1, 1000000 ),
            'random_hash' => hash( 'sha256', uniqid( '', true ) . random_int( 0, PHP_INT_MAX ) ),
            'extra_data'  => wp_generate_password( $extra_data_size, false ),
        );
    }

    /**
     * Perform CPU-intensive work.
     *
     * Executes a fixed number of hash operations to generate consistent CPU load.
     * The actual time taken depends on server CPU performance, making this
     * useful for comparing server capabilities under load.
     */
    private function perform_cpu_work(): void {
        $iterations = (int) $this->settings['cpu_iterations'];
        $limits     = SynthLoad_Settings::get_hard_limits();

        // Apply hard limit
        $iterations = min( $iterations, $limits['max_cpu_iterations'] );

        if ( $iterations < 1 ) {
            return;
        }

        $cpu_start = $this->get_elapsed_ms();

        // CPU-intensive operations: hash computations
        for ( $i = 0; $i < $iterations; $i++ ) {
            $data = str_repeat( 'x', 1000 );
            $hash = hash( 'sha256', $data . $i );

            // Prevent optimizer from eliminating the work
            if ( 0 === $i % 10000 ) {
                wp_json_encode( array( 'hash' => $hash, 'iteration' => $i ) );
            }
        }

        $this->cpu_iterations_performed = $iterations;

        $this->log_operation( 'cpu', 'hash_work', array(
            'iterations'  => $iterations,
            'duration_ms' => $this->get_elapsed_ms() - $cpu_start,
        ) );
    }

    /**
     * Build response payload.
     *
     * @return array Response array.
     */
    private function build_response(): array {
        $duration_ms = $this->get_elapsed_ms();

        return array(
            'status'     => 'ok',
            'timestamp'  => gmdate( 'c' ),
            'request_id' => $this->request_id,
            'execution'  => array(
                'duration_ms'    => $duration_ms,
                'db_reads'       => $this->reads_performed,
                'db_writes'      => $this->writes_performed,
                'cpu_iterations' => $this->cpu_iterations_performed,
                'cache_hit'      => $this->cache_hit,
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
