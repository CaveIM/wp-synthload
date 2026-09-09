<?php
/**
 * Tests for SynthLoad_Workload.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Workload
 */
class Test_SynthLoad_Workload extends WP_UnitTestCase {

	/** @var SynthLoad_Db */
	private SynthLoad_Db $db;

	/** Set up test fixtures. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->db = new SynthLoad_Db( $wpdb );
		SynthLoad_Db::create_table();

		for ( $i = 0; $i < 20; $i++ ) {
			$this->db->insert_event( array( 'payload' => wp_json_encode( array( 'seed' => $i ) ) ) );
		}
	}

	/** Tear down test fixtures. */
	public function tear_down(): void {
		SynthLoad_Db::drop_table();
		parent::tear_down();
	}

	/**
	 * Get lightweight test settings.
	 *
	 * @return array Settings array.
	 */
	private function get_test_settings(): array {
		return array_merge(
			SynthLoad_Settings::get_defaults(),
			array(
				'read_query_count' => 3,
				'write_op_count'    => 1,
				'cpu_iterations'    => 0,
			)
		);
	}

	/** Public results contain metrics without server or database details. */
	public function test_execute_returns_safe_response_structure(): void {
		$result = ( new SynthLoad_Workload( $this->db, $this->get_test_settings() ) )->execute();

		$this->assertSame( 'ok', $result['status'] );
		$this->assertArrayHasKey( 'timestamp', $result );
		$this->assertArrayHasKey( 'request_id', $result );
		$this->assertArrayHasKey( 'execution', $result );
		$this->assertArrayHasKey( 'duration_ms', $result['execution'] );
		$this->assertArrayHasKey( 'db_reads', $result['execution'] );
		$this->assertArrayHasKey( 'db_writes', $result['execution'] );
		$this->assertArrayHasKey( 'cpu_iterations', $result['execution'] );
		$this->assertArrayHasKey( 'cache_hit', $result['execution'] );
		$this->assertArrayNotHasKey( 'operations', $result );
		$this->assertArrayNotHasKey( 'server', $result );
	}

	/** Request IDs are unique UUIDs. */
	public function test_execute_generates_unique_request_ids(): void {
		$settings = $this->get_test_settings();
		$result_1 = ( new SynthLoad_Workload( $this->db, $settings ) )->execute();
		$result_2 = ( new SynthLoad_Workload( $this->db, $settings ) )->execute();

		$this->assertNotSame( $result_1['request_id'], $result_2['request_id'] );
		$this->assertMatchesRegularExpression(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
			$result_1['request_id']
		);
	}

	/** The configured number of reads is performed. */
	public function test_execute_performs_configured_reads(): void {
		$settings                     = $this->get_test_settings();
		$settings['read_query_count'] = 10;
		$settings['write_op_count']   = 0;

		$result = ( new SynthLoad_Workload( $this->db, $settings ) )->execute();

		$this->assertSame( 10, $result['execution']['db_reads'] );
	}

	/** Each write cycle performs an insert, update, and delete. */
	public function test_execute_performs_complete_write_cycles(): void {
		$settings                     = $this->get_test_settings();
		$settings['read_query_count'] = 0;
		$settings['write_op_count']   = 2;
		$initial_count                = $this->db->count_events();

		$result = ( new SynthLoad_Workload( $this->db, $settings ) )->execute();

		$this->assertSame( 6, $result['execution']['db_writes'] );
		$this->assertSame( $initial_count, $this->db->count_events() );
	}

	/** Direct callers cannot exceed the read cap. */
	public function test_execute_respects_hard_read_limit(): void {
		$settings                     = $this->get_test_settings();
		$settings['read_query_count'] = 5000;
		$settings['write_op_count']   = 0;

		$result = ( new SynthLoad_Workload( $this->db, $settings ) )->execute();

		$this->assertSame( 2000, $result['execution']['db_reads'] );
	}

	/** A zeroed workload still returns a successful result. */
	public function test_execute_with_zero_workload(): void {
		$settings                     = $this->get_test_settings();
		$settings['read_query_count'] = 0;
		$settings['write_op_count']   = 0;
		$settings['cpu_iterations']   = 0;

		$result = ( new SynthLoad_Workload( $this->db, $settings ) )->execute();

		$this->assertSame( 0, $result['execution']['db_reads'] );
		$this->assertSame( 0, $result['execution']['db_writes'] );
		$this->assertSame( 0, $result['execution']['cpu_iterations'] );
	}

	/** Timestamps use ISO 8601 format. */
	public function test_timestamp_is_valid_iso8601(): void {
		$result = ( new SynthLoad_Workload( $this->db, $this->get_test_settings() ) )->execute();

		$this->assertNotFalse( DateTime::createFromFormat( DateTime::ATOM, $result['timestamp'] ) );
	}
}
