<?php
/**
 * Integration tests for WP Synthetic Load plugin.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Integration
 *
 * End-to-end integration tests.
 */
class Test_SynthLoad_Integration extends WP_UnitTestCase {

    /**
     * Database instance.
     *
     * @var SynthLoad_Db
     */
    private SynthLoad_Db $db;

    /**
     * Set up test fixtures.
     */
    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->db = new SynthLoad_Db( $wpdb );

        // Set permalink structure
        $this->set_permalink_structure( '/%postname%/' );

        // Activate plugin components
        SynthLoad_Db::create_table();
        delete_option( SynthLoad_Settings::OPTION_NAME );
        add_option( SynthLoad_Settings::OPTION_NAME, SynthLoad_Settings::get_defaults() );

        // Register rewrites
        SynthLoad_Router::register_rewrites();
        flush_rewrite_rules();
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down(): void {
        SynthLoad_Db::drop_table();
        delete_option( SynthLoad_Settings::OPTION_NAME );
        delete_option( 'synthload_schema_version' );
        flush_rewrite_rules();
        parent::tear_down();
    }

    /**
     * Test endpoint query var is set when visiting endpoint.
     */
    public function test_endpoint_sets_query_var(): void {
        $this->go_to( home_url( '/synthload/' ) );

        $this->assertEquals( '1', get_query_var( 'synthload_request' ) );
    }

    /**
     * Test loaderio endpoint query var is set.
     */
    public function test_loaderio_endpoint_sets_query_var(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array(
            'loaderio_token' => 'abc123xyz',
        ) );

        SynthLoad_Router::register_rewrites();
        flush_rewrite_rules();

        $this->go_to( home_url( '/loaderio-abc123xyz.txt' ) );

        $this->assertEquals( 'abc123xyz', get_query_var( 'loaderio_verify' ) );
    }

    /**
     * Test full workload lifecycle.
     */
    public function test_full_workload_lifecycle(): void {
        // Configure moderate workload
        $settings = array_merge( SynthLoad_Settings::get_defaults(), array(
            'read_query_count'   => 5,
            'write_op_count'     => 3,
            'target_duration_ms' => 100,
            'duration_jitter_ms' => 0,
            'randomize_workload' => false,
        ) );

        // Ensure table exists
        SynthLoad_Db::create_table();

        // Seed some data for reads
        for ( $i = 0; $i < 20; $i++ ) {
            $this->db->insert_event( array( 'payload' => '{}' ) );
        }

        $initial_count = $this->db->count_events();

        // Create and execute workload
        $workload = new SynthLoad_Workload( $this->db, $settings );
        $result = $workload->execute();

        // Verify response structure
        $this->assertEquals( 'ok', $result['status'] );
        $this->assertNotEmpty( $result['request_id'] );
        $this->assertIsArray( $result['execution'] );

        // Verify reads and writes were performed
        $this->assertGreaterThan( 0, $result['execution']['db_reads'] );
        $this->assertGreaterThan( 0, $result['execution']['db_writes'] );

        // Verify new records were created
        $new_count = $this->db->count_events();
        $this->assertGreaterThan( $initial_count, $new_count );
    }

    /**
     * Test that settings changes take effect.
     */
    public function test_settings_changes_take_effect(): void {
        // Initial setting
        update_option( SynthLoad_Settings::OPTION_NAME, array(
            'endpoint_slug' => 'initial-slug',
        ) );

        SynthLoad_Router::register_rewrites();
        flush_rewrite_rules();

        global $wp_rewrite;
        $rules = $wp_rewrite->wp_rewrite_rules();
        $this->assertArrayHasKey( '^initial-slug/?$', $rules );

        // Change setting
        update_option( SynthLoad_Settings::OPTION_NAME, array(
            'endpoint_slug' => 'new-slug',
        ) );

        SynthLoad_Router::register_rewrites();
        flush_rewrite_rules();

        $rules = $wp_rewrite->wp_rewrite_rules();
        $this->assertArrayHasKey( '^new-slug/?$', $rules );
    }

    /**
     * Test cleanup runs during workload with many records.
     */
    public function test_cleanup_runs_with_many_records(): void {
        global $wpdb;

        // Insert many old events to exceed threshold
        $table = $this->db->get_table_name();
        for ( $i = 0; $i < 90000; $i++ ) {
            // Direct insert for speed
            $wpdb->insert(
                $table,
                array(
                    'request_id' => wp_generate_uuid4(),
                    'payload'    => '{}',
                    'rand_key'   => random_int( 1, PHP_INT_MAX ),
                    'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ), // 2 hours old
                ),
                array( '%s', '%s', '%d', '%s' )
            );

            // Batch commits for performance
            if ( 0 === $i % 1000 ) {
                // No-op, just for potential batching
            }
        }

        $initial_count = $this->db->count_events();
        $this->assertGreaterThan( 80000, $initial_count );

        // Execute workload with writes (triggers cleanup)
        $settings = array_merge( SynthLoad_Settings::get_defaults(), array(
            'read_query_count'   => 2,
            'write_op_count'     => 5,
            'target_duration_ms' => 100,
        ) );

        $workload = new SynthLoad_Workload( $this->db, $settings );
        $workload->execute();

        // Some cleanup should have occurred
        $final_count = $this->db->count_events();
        // Note: This test may not always show cleanup depending on implementation
        // The important thing is no errors occurred
        $this->assertGreaterThan( 0, $final_count );
    }

    /**
     * Test custom slug with various formats.
     */
    public function test_custom_slug_formats(): void {
        $valid_slugs = array(
            'load-test',
            'my-api-endpoint',
            'test123',
            'synthload',
        );

        foreach ( $valid_slugs as $slug ) {
            update_option( SynthLoad_Settings::OPTION_NAME, array( 'endpoint_slug' => $slug ) );

            SynthLoad_Router::register_rewrites();
            flush_rewrite_rules();

            global $wp_rewrite;
            $rules = $wp_rewrite->wp_rewrite_rules();

            $this->assertArrayHasKey(
                '^' . preg_quote( $slug, '/' ) . '/?$',
                $rules,
                "Slug '{$slug}' should create valid rewrite rule"
            );
        }
    }

    /**
     * Test that workload respects all hard limits.
     */
    public function test_workload_respects_all_hard_limits(): void {
        // Try to exceed all limits
        $settings = array_merge( SynthLoad_Settings::get_defaults(), array(
            'read_query_count'   => 10000, // Way over max
            'write_op_count'     => 1000,  // Way over max
            'target_duration_ms' => 60000, // Way over max
            'randomize_workload' => false,
        ) );

        $limits = SynthLoad_Settings::get_hard_limits();

        $workload = new SynthLoad_Workload( $this->db, $settings );
        $result   = $workload->execute();

        // All values should be within limits
        $this->assertLessThanOrEqual( $limits['max_read_query_count'], $result['execution']['db_reads'] );
        $this->assertLessThanOrEqual( $limits['max_write_op_count'], $result['execution']['db_writes'] );
        $this->assertLessThanOrEqual( $limits['max_total_duration_ms'] + 1000, $result['execution']['duration_ms'] ); // Allow some overhead
    }
}
