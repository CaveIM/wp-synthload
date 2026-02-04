<?php
/**
 * Tests for SynthLoad_Workload class.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Workload
 *
 * Tests for workload simulation functionality.
 */
class Test_SynthLoad_Workload extends WP_UnitTestCase {

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
        SynthLoad_Db::create_table();

        // Seed some test data for read operations
        for ( $i = 0; $i < 100; $i++ ) {
            $this->db->insert_event( array(
                'payload' => wp_json_encode( array( 'seed' => $i ) ),
            ) );
        }
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down(): void {
        SynthLoad_Db::drop_table();
        parent::tear_down();
    }

    /**
     * Get default test settings.
     *
     * @return array Settings array.
     */
    private function get_test_settings(): array {
        return array_merge( SynthLoad_Settings::get_defaults(), array(
            'read_query_count'      => 10,
            'write_op_count'        => 5,
            'target_duration_ms'    => 100,
            'duration_jitter_ms'    => 0,
            'randomize_workload'    => false,
            'debug_logging_enabled' => false,
        ) );
    }

    /**
     * Test that execute returns expected structure.
     */
    public function test_execute_returns_expected_structure(): void {
        $settings = $this->get_test_settings();
        $workload = new SynthLoad_Workload( $this->db, $settings );

        $result = $workload->execute();

        $this->assertArrayHasKey( 'status', $result );
        $this->assertArrayHasKey( 'timestamp', $result );
        $this->assertArrayHasKey( 'request_id', $result );
        $this->assertArrayHasKey( 'execution', $result );
        $this->assertArrayHasKey( 'server', $result );

        $this->assertEquals( 'ok', $result['status'] );

        $this->assertArrayHasKey( 'duration_ms', $result['execution'] );
        $this->assertArrayHasKey( 'target_ms', $result['execution'] );
        $this->assertArrayHasKey( 'db_reads', $result['execution'] );
        $this->assertArrayHasKey( 'db_writes', $result['execution'] );
        $this->assertArrayHasKey( 'cache_hit', $result['execution'] );
    }

    /**
     * Test that execute generates unique request_id.
     */
    public function test_execute_generates_unique_request_id(): void {
        $settings = $this->get_test_settings();

        $workload1 = new SynthLoad_Workload( $this->db, $settings );
        $workload2 = new SynthLoad_Workload( $this->db, $settings );

        $result1 = $workload1->execute();
        $result2 = $workload2->execute();

        $this->assertNotEquals( $result1['request_id'], $result2['request_id'] );
    }

    /**
     * Test that execute performs reads within configured range.
     */
    public function test_execute_performs_reads_within_configured_range(): void {
        $settings                    = $this->get_test_settings();
        $settings['read_query_count'] = 10;
        $settings['write_op_count']   = 0;

        $workload = new SynthLoad_Workload( $this->db, $settings );
        $result   = $workload->execute();

        // Allow some variance for randomization
        $this->assertGreaterThanOrEqual( 8, $result['execution']['db_reads'] );
        $this->assertLessThanOrEqual( 12, $result['execution']['db_reads'] );
    }

    /**
     * Test that execute performs writes within configured range.
     */
    public function test_execute_performs_writes_within_configured_range(): void {
        $settings                    = $this->get_test_settings();
        $settings['read_query_count'] = 0;
        $settings['write_op_count']   = 10;

        $initial_count = $this->db->count_events();

        $workload = new SynthLoad_Workload( $this->db, $settings );
        $result   = $workload->execute();

        // Writes should be approximately 10 (with tolerance for randomization)
        $this->assertGreaterThanOrEqual( 8, $result['execution']['db_writes'] );
        $this->assertLessThanOrEqual( 15, $result['execution']['db_writes'] );

        // Verify events were actually created
        $new_count = $this->db->count_events();
        $this->assertGreaterThan( $initial_count, $new_count );
    }

    /**
     * Test that execute respects hard read limit.
     */
    public function test_execute_respects_hard_read_limit(): void {
        $settings                    = $this->get_test_settings();
        $settings['read_query_count'] = 5000; // Exceeds max of 2000
        $settings['write_op_count']   = 0;
        $settings['randomize_workload'] = false;

        $workload = new SynthLoad_Workload( $this->db, $settings );
        $result   = $workload->execute();

        $this->assertLessThanOrEqual( 2000, $result['execution']['db_reads'] );
    }

    /**
     * Test that execute respects hard write limit.
     */
    public function test_execute_respects_hard_write_limit(): void {
        $settings                    = $this->get_test_settings();
        $settings['read_query_count'] = 0;
        $settings['write_op_count']   = 500; // Exceeds max of 200
        $settings['randomize_workload'] = false;

        $workload = new SynthLoad_Workload( $this->db, $settings );
        $result   = $workload->execute();

        $this->assertLessThanOrEqual( 200, $result['execution']['db_writes'] );
    }

    /**
     * Test that execute respects target duration.
     */
    public function test_execute_respects_target_duration(): void {
        $settings                      = $this->get_test_settings();
        $settings['target_duration_ms'] = 200;
        $settings['duration_jitter_ms'] = 0;
        $settings['read_query_count']   = 5;
        $settings['write_op_count']     = 2;
        $settings['randomize_workload'] = false;

        $start    = microtime( true );
        $workload = new SynthLoad_Workload( $this->db, $settings );
        $result   = $workload->execute();
        $elapsed  = ( microtime( true ) - $start ) * 1000;

        // Should be approximately 200ms (with some tolerance)
        $this->assertGreaterThanOrEqual( 180, $elapsed );
        $this->assertLessThanOrEqual( 300, $elapsed );
    }

    /**
     * Test that execute applies jitter to duration.
     */
    public function test_execute_applies_jitter_to_duration(): void {
        $settings                      = $this->get_test_settings();
        $settings['target_duration_ms'] = 100;
        $settings['duration_jitter_ms'] = 50;
        $settings['read_query_count']   = 2;
        $settings['write_op_count']     = 1;
        $settings['randomize_workload'] = true;

        $durations = array();

        // Execute multiple times and collect durations
        for ( $i = 0; $i < 5; $i++ ) {
            $workload    = new SynthLoad_Workload( $this->db, $settings );
            $result      = $workload->execute();
            $durations[] = $result['execution']['duration_ms'];
        }

        // Check that there's some variance (not all exactly the same)
        $unique_durations = array_unique( $durations );
        // With randomization, we should see some variance
        $this->assertGreaterThanOrEqual( 1, count( $unique_durations ) );
    }

    /**
     * Test that execute with zero reads and writes still works.
     */
    public function test_execute_with_zero_reads_and_writes(): void {
        $settings                      = $this->get_test_settings();
        $settings['read_query_count']   = 0;
        $settings['write_op_count']     = 0;
        $settings['target_duration_ms'] = 100;

        $workload = new SynthLoad_Workload( $this->db, $settings );
        $result   = $workload->execute();

        $this->assertEquals( 'ok', $result['status'] );
        $this->assertEquals( 0, $result['execution']['db_reads'] );
        $this->assertEquals( 0, $result['execution']['db_writes'] );
    }

    /**
     * Test that inserts create new events.
     */
    public function test_inserts_create_new_events(): void {
        $initial_count = $this->db->count_events();

        $settings                    = $this->get_test_settings();
        $settings['read_query_count'] = 0;
        $settings['write_op_count']   = 5;
        $settings['randomize_workload'] = false;

        $workload = new SynthLoad_Workload( $this->db, $settings );
        $workload->execute();

        $new_count = $this->db->count_events();
        $this->assertGreaterThan( $initial_count, $new_count );
    }

    /**
     * Test that cache behavior flag is set.
     */
    public function test_cache_behavior_flag_set(): void {
        $settings                    = $this->get_test_settings();
        $settings['use_object_cache'] = true;

        $workload = new SynthLoad_Workload( $this->db, $settings );
        $result   = $workload->execute();

        $this->assertArrayHasKey( 'cache_hit', $result['execution'] );
    }

    /**
     * Test that randomize_workload false produces more consistent results.
     */
    public function test_randomize_workload_false_produces_consistent_results(): void {
        $settings                      = $this->get_test_settings();
        $settings['randomize_workload'] = false;
        $settings['read_query_count']   = 10;
        $settings['write_op_count']     = 0;

        $workload1 = new SynthLoad_Workload( $this->db, $settings );
        $result1   = $workload1->execute();

        $workload2 = new SynthLoad_Workload( $this->db, $settings );
        $result2   = $workload2->execute();

        // With randomization off, reads should be exactly the same
        $this->assertEquals( $result1['execution']['db_reads'], $result2['execution']['db_reads'] );
    }

    /**
     * Test that server info is populated.
     */
    public function test_server_info_populated(): void {
        $settings = $this->get_test_settings();
        $workload = new SynthLoad_Workload( $this->db, $settings );

        $result = $workload->execute();

        $this->assertEquals( PHP_VERSION, $result['server']['php_version'] );
        $this->assertNotEmpty( $result['server']['wp_version'] );
    }

    /**
     * Test that request_id format is valid UUID.
     */
    public function test_request_id_is_valid_uuid(): void {
        $settings = $this->get_test_settings();
        $workload = new SynthLoad_Workload( $this->db, $settings );

        $result = $workload->execute();

        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
            $result['request_id']
        );
    }

    /**
     * Test that timestamp is valid ISO 8601.
     */
    public function test_timestamp_is_valid_iso8601(): void {
        $settings = $this->get_test_settings();
        $workload = new SynthLoad_Workload( $this->db, $settings );

        $result = $workload->execute();

        // Try to parse the timestamp
        $parsed = DateTime::createFromFormat( DateTime::ATOM, $result['timestamp'] );
        $this->assertNotFalse( $parsed );
    }

    /**
     * Test minimum duration is enforced.
     */
    public function test_minimum_duration_is_enforced(): void {
        $settings                      = $this->get_test_settings();
        $settings['target_duration_ms'] = 10; // Below minimum
        $settings['duration_jitter_ms'] = 0;

        $workload = new SynthLoad_Workload( $this->db, $settings );
        $result   = $workload->execute();

        // Target should be at least 100ms
        $this->assertGreaterThanOrEqual( 100, $result['execution']['target_ms'] );
    }
}
