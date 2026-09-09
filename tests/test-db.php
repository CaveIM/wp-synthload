<?php
/**
 * Tests for SynthLoad_Db class.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Db
 *
 * Tests for database operations functionality.
 */
class Test_SynthLoad_Db extends WP_UnitTestCase {

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
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down(): void {
        SynthLoad_Db::drop_table();
        parent::tear_down();
    }

    /**
     * Test that create_table creates the table.
     */
	public function test_create_table_creates_table(): void {
		SynthLoad_Db::create_table();
		$this->assertTrue( $this->db->table_exists() );
	}

    /**
     * Test that get_table_name includes the prefix.
     */
    public function test_get_table_name_includes_prefix(): void {
        global $wpdb;
        $table_name = $this->db->get_table_name();

        $this->assertStringStartsWith( $wpdb->prefix, $table_name );
        $this->assertStringEndsWith( 'synthload_events', $table_name );
    }

    /**
     * Test that table_exists returns false when table doesn't exist.
     */
	public function test_table_exists_returns_false_when_not_exists(): void {
		$wpdb         = $this->createMock( wpdb::class );
		$wpdb->prefix = 'wp_';
		$wpdb->method( 'prepare' )->willReturn( "SHOW TABLES LIKE 'wp_synthload_events'" );
		$wpdb->method( 'get_var' )->willReturn( null );

		$this->assertFalse( ( new SynthLoad_Db( $wpdb ) )->table_exists() );
    }

    /**
     * Test that insert_event returns an ID.
     */
    public function test_insert_event_returns_id(): void {
        $id = $this->db->insert_event( array(
            'request_id' => 'test-uuid-1234',
            'payload'    => '{"test": "data"}',
        ) );

        $this->assertIsInt( $id );
        $this->assertGreaterThan( 0, $id );
    }

    /**
     * Test that insert_event auto-generates request_id.
     */
    public function test_insert_event_auto_generates_request_id(): void {
        $id = $this->db->insert_event( array( 'payload' => '{}' ) );

        $events = $this->db->read_events( array( 'limit' => 1 ) );

        $this->assertNotEmpty( $events );
        $this->assertNotEmpty( $events[0]->request_id );
        // Check UUID format (8-4-4-4-12)
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
            $events[0]->request_id
        );
    }

    /**
     * Test that insert_event auto-generates rand_key.
     */
    public function test_insert_event_auto_generates_rand_key(): void {
        $id = $this->db->insert_event( array( 'request_id' => 'test-123' ) );

        $events = $this->db->read_events( array( 'limit' => 1 ) );

        $this->assertNotEmpty( $events );
        $this->assertNotNull( $events[0]->rand_key );
        $this->assertIsNumeric( $events[0]->rand_key );
    }

    /**
     * Test that insert_event stores payload.
     */
    public function test_insert_event_stores_payload(): void {
        $payload = '{"test": "data", "number": 42}';
        $this->db->insert_event( array(
            'request_id' => 'test-payload',
            'payload'    => $payload,
        ) );

        $events = $this->db->read_events( array( 'limit' => 1 ) );

        $this->assertNotEmpty( $events );
        $this->assertEquals( $payload, $events[0]->payload );
    }

    /**
     * Test that read_events returns an array.
     */
    public function test_read_events_returns_array(): void {
        // Insert 3 events
        for ( $i = 0; $i < 3; $i++ ) {
            $this->db->insert_event( array( 'request_id' => "event-{$i}" ) );
        }

        $events = $this->db->read_events();

        $this->assertIsArray( $events );
        $this->assertCount( 3, $events );
    }

    /**
     * Test that read_events respects limit.
     */
    public function test_read_events_respects_limit(): void {
        // Insert 10 events
        for ( $i = 0; $i < 10; $i++ ) {
            $this->db->insert_event( array( 'request_id' => "event-{$i}" ) );
        }

        $events = $this->db->read_events( array( 'limit' => 5 ) );

        $this->assertCount( 5, $events );
    }

    /**
     * Test that read_events respects offset.
     */
    public function test_read_events_respects_offset(): void {
        // Insert 10 events with identifiable request_ids
        for ( $i = 0; $i < 10; $i++ ) {
            $this->db->insert_event( array( 'request_id' => "event-{$i}" ) );
        }

        $first_batch  = $this->db->read_events( array( 'limit' => 5, 'offset' => 0 ) );
        $second_batch = $this->db->read_events( array( 'limit' => 5, 'offset' => 5 ) );

        $this->assertCount( 5, $first_batch );
        $this->assertCount( 5, $second_batch );

        // Ensure batches are different
        $first_ids  = array_map( fn( $e ) => $e->request_id, $first_batch );
        $second_ids = array_map( fn( $e ) => $e->request_id, $second_batch );

        $this->assertEmpty( array_intersect( $first_ids, $second_ids ) );
    }

    /**
     * Test that read_events orders by created_at DESC by default.
     */
    public function test_read_events_orders_by_created_at_desc_by_default(): void {
        global $wpdb;
        $table = $this->db->get_table_name();

        // Insert event A with older timestamp
        $wpdb->insert(
            $table,
            array(
                'request_id' => 'event-a',
                'payload'    => '{}',
                'rand_key'   => 1,
                'created_at' => gmdate( 'Y-m-d H:i:s', time() - 100 ),
            ),
            array( '%s', '%s', '%d', '%s' )
        );

        // Insert event B with newer timestamp
        $wpdb->insert(
            $table,
            array(
                'request_id' => 'event-b',
                'payload'    => '{}',
                'rand_key'   => 2,
                'created_at' => gmdate( 'Y-m-d H:i:s', time() ),
            ),
            array( '%s', '%s', '%d', '%s' )
        );

        $events = $this->db->read_events( array( 'limit' => 2 ) );

        $this->assertEquals( 'event-b', $events[0]->request_id ); // Most recent first
        $this->assertEquals( 'event-a', $events[1]->request_id );
    }

    /**
     * Test that read_random_events returns requested count.
     */
    public function test_read_random_events_returns_requested_count(): void {
        // Insert 20 events
        for ( $i = 0; $i < 20; $i++ ) {
            $this->db->insert_event( array( 'request_id' => "event-{$i}" ) );
        }

        $events = $this->db->read_random_events( 5 );

        $this->assertCount( 5, $events );
    }

    /**
     * Test that read_random_events returns all if fewer exist.
     */
    public function test_read_random_events_returns_all_if_fewer_exist(): void {
        // Insert 3 events
        for ( $i = 0; $i < 3; $i++ ) {
            $this->db->insert_event( array( 'request_id' => "event-{$i}" ) );
        }

        $events = $this->db->read_random_events( 10 );

        $this->assertCount( 3, $events );
    }

    /**
     * Test that count_events returns zero when empty.
     */
    public function test_count_events_returns_zero_when_empty(): void {
        // Drop and recreate to ensure empty
        SynthLoad_Db::drop_table();
        SynthLoad_Db::create_table();

        $this->assertEquals( 0, $this->db->count_events() );
    }

    /**
     * Test that count_events returns correct count.
     */
    public function test_count_events_returns_correct_count(): void {
        // Insert 7 events
        for ( $i = 0; $i < 7; $i++ ) {
            $this->db->insert_event( array( 'request_id' => "event-{$i}" ) );
        }

        $this->assertEquals( 7, $this->db->count_events() );
    }

    /**
     * Test that cleanup_old_events deletes old records.
     */
    public function test_cleanup_old_events_deletes_old_records(): void {
        global $wpdb;
        $table = $this->db->get_table_name();

        // Insert old event (2 hours ago)
        $wpdb->insert(
            $table,
            array(
                'request_id' => 'old-event',
                'payload'    => '{}',
                'rand_key'   => 1,
                'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
            ),
            array( '%s', '%s', '%d', '%s' )
        );

        // Insert new event (now)
        $this->db->insert_event( array( 'request_id' => 'new-event' ) );

        // Cleanup events older than 1 hour
        $deleted = $this->db->cleanup_old_events( 3600 );

        $this->assertEquals( 1, $deleted );
        $this->assertEquals( 1, $this->db->count_events() );
    }

    /**
     * Test that cleanup_old_events respects limit.
     */
    public function test_cleanup_old_events_respects_limit(): void {
        global $wpdb;
        $table = $this->db->get_table_name();

        // Insert 10 old events
        for ( $i = 0; $i < 10; $i++ ) {
            $wpdb->insert(
                $table,
                array(
                    'request_id' => "old-event-{$i}",
                    'payload'    => '{}',
                    'rand_key'   => $i,
                    'created_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
                ),
                array( '%s', '%s', '%d', '%s' )
            );
        }

        // Cleanup with limit of 5
        $deleted = $this->db->cleanup_old_events( 3600, 5 );

        $this->assertEquals( 5, $deleted );
        $this->assertEquals( 5, $this->db->count_events() );
    }

    /**
     * Test that cleanup_old_events returns zero when nothing to delete.
     */
    public function test_cleanup_old_events_returns_zero_when_nothing_to_delete(): void {
        // Insert fresh event
        $this->db->insert_event( array( 'request_id' => 'fresh-event' ) );

        $deleted = $this->db->cleanup_old_events( 3600 );

        $this->assertEquals( 0, $deleted );
    }

    /**
     * Test that drop_table removes the table.
     */
	public function test_drop_table_removes_table(): void {
		global $wpdb;
		$original_wpdb   = $wpdb;
		$mock_wpdb       = $this->createMock( wpdb::class );
		$mock_wpdb->prefix = 'wp_';
		$mock_wpdb->expects( $this->once() )
			->method( 'query' )
			->with( 'DROP TABLE IF EXISTS wp_synthload_events' )
			->willReturn( 0 );

		try {
			$wpdb = $mock_wpdb;
			$this->assertTrue( SynthLoad_Db::drop_table() );
		} finally {
			$wpdb = $original_wpdb;
		}
	}

    /**
     * Test that insert_event handles missing payload.
     */
    public function test_insert_event_handles_missing_payload(): void {
        $id = $this->db->insert_event( array( 'request_id' => 'no-payload' ) );

        $this->assertIsInt( $id );

        $events = $this->db->read_events( array( 'limit' => 1 ) );
        $this->assertEquals( '{}', $events[0]->payload );
    }

    /**
     * Test that read_events validates orderby parameter.
     */
    public function test_read_events_validates_orderby(): void {
        // Insert events
        $this->db->insert_event( array( 'request_id' => 'event-1' ) );

        // Try to use invalid orderby - should default to created_at
        $events = $this->db->read_events( array( 'orderby' => 'DROP TABLE;' ) );

        // Should not throw error and return results
        $this->assertIsArray( $events );
    }

    /**
     * Test that read_events validates order parameter.
     */
    public function test_read_events_validates_order(): void {
        // Insert events
        $this->db->insert_event( array( 'request_id' => 'event-1' ) );

        // Try to use invalid order - should default to DESC
        $events = $this->db->read_events( array( 'order' => 'INVALID' ) );

        // Should not throw error and return results
        $this->assertIsArray( $events );
    }

    /**
     * Test multiple inserts have unique IDs.
     */
    public function test_multiple_inserts_have_unique_ids(): void {
        $ids = array();

        for ( $i = 0; $i < 5; $i++ ) {
            $ids[] = $this->db->insert_event( array( 'payload' => "{\"num\": {$i}}" ) );
        }

        $unique_ids = array_unique( $ids );
        $this->assertCount( 5, $unique_ids );
    }
}
