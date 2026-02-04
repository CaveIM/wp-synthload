<?php
/**
 * Database operations for WP Synthetic Load plugin.
 *
 * @package WP_SynthLoad
 */

// Security check - prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SynthLoad_Db
 *
 * Manages plugin-owned database table and provides safe read/write operations.
 */
class SynthLoad_Db {

    /**
     * Table name without prefix.
     */
    const TABLE_NAME = 'synthload_events';

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private wpdb $wpdb;

    /**
     * Constructor.
     *
     * @param wpdb $wpdb WordPress database instance.
     */
    public function __construct( wpdb $wpdb ) {
        $this->wpdb = $wpdb;
    }

    /**
     * Get full table name with prefix.
     *
     * @return string Full table name.
     */
    public function get_table_name(): string {
        return $this->wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Get table schema SQL for dbDelta.
     *
     * @return string SQL CREATE TABLE statement.
     */
    public static function get_schema(): string {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        return "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            request_id char(36) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            payload longtext,
            rand_key bigint(20) unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_created_at (created_at),
            KEY idx_rand_key (rand_key)
        ) {$charset_collate};";
    }

    /**
     * Create the database table using dbDelta.
     *
     * @return bool True on success.
     */
    public static function create_table(): bool {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $schema = self::get_schema();
        dbDelta( $schema );

        return true;
    }

    /**
     * Drop the database table.
     *
     * @return bool True on success.
     */
    public static function drop_table(): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

        return true;
    }

    /**
     * Check if the table exists.
     *
     * @return bool True if table exists.
     */
    public function table_exists(): bool {
        $table_name = $this->get_table_name();
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

        return $result === $table_name;
    }

    /**
     * Insert a synthetic event record.
     *
     * @param array $data Event data with optional keys: request_id, payload, rand_key.
     * @return int|false Insert ID on success, false on failure.
     */
    public function insert_event( array $data ): int|false {
        $table_name = $this->get_table_name();

        // Generate request_id if not provided
        if ( empty( $data['request_id'] ) ) {
            $data['request_id'] = $this->generate_uuid();
        }

        // Generate rand_key if not provided
        if ( ! isset( $data['rand_key'] ) ) {
            $data['rand_key'] = $this->generate_random_bigint();
        }

        // Default payload to empty JSON object
        if ( ! isset( $data['payload'] ) ) {
            $data['payload'] = '{}';
        }

        $result = $this->wpdb->insert(
            $table_name,
            array(
                'request_id' => $data['request_id'],
                'payload'    => $data['payload'],
                'rand_key'   => $data['rand_key'],
            ),
            array( '%s', '%s', '%d' )
        );

        if ( false === $result ) {
            return false;
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Read events from the table.
     *
     * @param array $args Optional arguments: limit, offset, orderby, order.
     * @return array Array of event objects.
     */
    public function read_events( array $args = array() ): array {
        $table_name = $this->get_table_name();

        $defaults = array(
            'limit'   => 10,
            'offset'  => 0,
            'orderby' => 'created_at',
            'order'   => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        // Validate orderby to prevent SQL injection
        $allowed_columns = array( 'id', 'request_id', 'created_at', 'rand_key' );
        if ( ! in_array( $args['orderby'], $allowed_columns, true ) ) {
            $args['orderby'] = 'created_at';
        }

        // Validate order
        $args['order'] = strtoupper( $args['order'] );
        if ( ! in_array( $args['order'], array( 'ASC', 'DESC' ), true ) ) {
            $args['order'] = 'DESC';
        }

        $limit  = absint( $args['limit'] );
        $offset = absint( $args['offset'] );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} ORDER BY {$args['orderby']} {$args['order']} LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $this->wpdb->get_results( $sql );

        return is_array( $results ) ? $results : array();
    }

    /**
     * Read random events from the table.
     *
     * @param int $count Number of events to return.
     * @return array Array of event objects.
     */
    public function read_random_events( int $count ): array {
        $table_name = $this->get_table_name();
        $count      = absint( $count );

        if ( $count < 1 ) {
            return array();
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$table_name} ORDER BY RAND() LIMIT %d",
            $count
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $results = $this->wpdb->get_results( $sql );

        return is_array( $results ) ? $results : array();
    }

    /**
     * Count total events in the table.
     *
     * @return int Event count.
     */
    public function count_events(): int {
        $table_name = $this->get_table_name();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );

        return (int) $count;
    }

    /**
     * Clean up old events.
     *
     * @param int $max_age_seconds Maximum age of events to keep (default 1 hour).
     * @param int $limit           Maximum number of events to delete per call.
     * @return int Number of deleted rows.
     */
    public function cleanup_old_events( int $max_age_seconds = 3600, int $limit = 1000 ): int {
        $table_name = $this->get_table_name();
        $limit      = absint( $limit );

        if ( $max_age_seconds < 1 ) {
            return 0;
        }

        // Calculate the cutoff time
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $max_age_seconds );

        // Delete old events with limit
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $this->wpdb->prepare(
            "DELETE FROM {$table_name} WHERE created_at < %s ORDER BY created_at ASC LIMIT %d",
            $cutoff,
            $limit
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $this->wpdb->query( $sql );

        return (int) $this->wpdb->rows_affected;
    }

    /**
     * Generate a UUID v4.
     *
     * @return string UUID v4 string.
     */
    private function generate_uuid(): string {
        // Use WordPress function if available
        if ( function_exists( 'wp_generate_uuid4' ) ) {
            return wp_generate_uuid4();
        }

        // Manual generation
        $data    = random_bytes( 16 );
        $data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 ); // Version 4
        $data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 ); // Variant

        return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
    }

    /**
     * Generate a random bigint for rand_key.
     *
     * @return int Random bigint value.
     */
    private function generate_random_bigint(): int {
        // Generate a random number in the bigint range
        return random_int( 1, PHP_INT_MAX );
    }
}
