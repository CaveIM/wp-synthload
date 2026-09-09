<?php
/**
 * Tests for SynthLoad_Settings.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Settings
 */
class Test_SynthLoad_Settings extends WP_UnitTestCase {

	/** Set up test fixtures. */
	public function set_up(): void {
		parent::set_up();
		delete_option( SynthLoad_Settings::OPTION_NAME );
	}

	/** Tear down test fixtures. */
	public function tear_down(): void {
		delete_option( SynthLoad_Settings::OPTION_NAME );
		parent::tear_down();
	}

	/** Defaults are complete and safe. */
	public function test_defaults_are_complete_and_safe(): void {
		$defaults = SynthLoad_Settings::get_defaults();
		$keys     = array(
			'loaderio_token',
			'endpoint_slug',
			'endpoint_enabled',
			'access_token',
			'read_query_count',
			'write_op_count',
			'cpu_iterations',
			'bypass_object_cache',
			'debug_logging_enabled',
			'calc_pages_per_visit',
			'calc_cache_hit_rate',
			'calc_connections_per_vcpu',
			'calc_peak_to_average_ratio',
			'calc_flash_spike_percent',
		);

		$this->assertSame( $keys, array_keys( $defaults ) );
		$this->assertFalse( $defaults['endpoint_enabled'] );
		$this->assertSame( '', $defaults['access_token'] );
		$this->assertSame( 'synthload', $defaults['endpoint_slug'] );
		$this->assertSame( 100, $defaults['read_query_count'] );
		$this->assertSame( 5, $defaults['write_op_count'] );
		$this->assertSame( 100, $defaults['cpu_iterations'] );
	}

	/** Hard safety limits match the documented caps. */
	public function test_hard_limits_are_correct(): void {
		$this->assertSame(
			array(
				'max_cpu_iterations'   => 10000,
				'max_read_query_count' => 2000,
				'max_write_op_count'   => 200,
				'max_rows_to_keep'     => 100000,
			),
			SynthLoad_Settings::get_hard_limits()
		);
	}

	/** Stored values merge with defaults. */
	public function test_get_all_merges_stored_values_with_defaults(): void {
		update_option( SynthLoad_Settings::OPTION_NAME, array( 'endpoint_slug' => 'custom-slug' ) );

		$settings = SynthLoad_Settings::get_all();

		$this->assertSame( 'custom-slug', $settings['endpoint_slug'] );
		$this->assertSame( 100, $settings['read_query_count'] );
		$this->assertFalse( $settings['endpoint_enabled'] );
	}

	/** Updates preserve settings omitted from a partial update. */
	public function test_update_preserves_existing_settings(): void {
		SynthLoad_Settings::update(
			array(
				'endpoint_slug'    => 'initial-slug',
				'read_query_count' => 50,
			)
		);
		SynthLoad_Settings::update( array( 'read_query_count' => 75 ) );

		$settings = SynthLoad_Settings::get_all();
		$this->assertSame( 'initial-slug', $settings['endpoint_slug'] );
		$this->assertSame( 75, $settings['read_query_count'] );
	}

	/** Workload values are clamped to their hard limits. */
	public function test_sanitize_clamps_workload_values(): void {
		$sanitized = SynthLoad_Settings::sanitize(
			array(
				'read_query_count' => 5000,
				'write_op_count'    => 500,
				'cpu_iterations'    => 20000,
			)
		);

		$this->assertSame( 2000, $sanitized['read_query_count'] );
		$this->assertSame( 200, $sanitized['write_op_count'] );
		$this->assertSame( 10000, $sanitized['cpu_iterations'] );
	}

	/** Negative workload values are clamped to zero. */
	public function test_sanitize_clamps_workload_minimums(): void {
		$sanitized = SynthLoad_Settings::sanitize(
			array(
				'read_query_count' => -1,
				'write_op_count'    => -1,
				'cpu_iterations'    => -1,
			)
		);

		$this->assertSame( 0, $sanitized['read_query_count'] );
		$this->assertSame( 0, $sanitized['write_op_count'] );
		$this->assertSame( 0, $sanitized['cpu_iterations'] );
	}

	/** Boolean values are normalized. */
	public function test_sanitize_normalizes_booleans(): void {
		$sanitized = SynthLoad_Settings::sanitize(
			array(
				'endpoint_enabled'      => 'yes',
				'bypass_object_cache'   => '0',
				'debug_logging_enabled' => true,
			)
		);

		$this->assertTrue( $sanitized['endpoint_enabled'] );
		$this->assertFalse( $sanitized['bypass_object_cache'] );
		$this->assertTrue( $sanitized['debug_logging_enabled'] );
	}

	/** Slugs are normalized and reserved paths are rejected. */
	public function test_slug_validation(): void {
		$this->assertTrue( SynthLoad_Settings::is_valid_slug( 'load-test-endpoint' ) );
		$this->assertFalse( SynthLoad_Settings::is_valid_slug( 'wp-admin' ) );
		$this->assertFalse( SynthLoad_Settings::is_valid_slug( 'has spaces' ) );

		$sanitized = SynthLoad_Settings::sanitize( array( 'endpoint_slug' => 'wp-admin' ) );
		$this->assertSame( 'synthload', $sanitized['endpoint_slug'] );
	}

	/** Loader.io tokens are normalized for verification URLs. */
	public function test_loaderio_token_handling(): void {
		$sanitized = SynthLoad_Settings::sanitize( array( 'loaderio_token' => 'loaderio-abc123!@#' ) );

		$this->assertSame( 'loaderio-abc123', $sanitized['loaderio_token'] );
		$this->assertSame( 'abc123', SynthLoad_Settings::extract_token_id( $sanitized['loaderio_token'] ) );
	}

	/** Access tokens are either empty or at least 16 characters. */
	public function test_access_token_validation(): void {
		$this->assertTrue( SynthLoad_Settings::is_valid_access_token( '' ) );
		$this->assertFalse( SynthLoad_Settings::is_valid_access_token( 'too-short' ) );
		$this->assertTrue( SynthLoad_Settings::is_valid_access_token( '0123456789abcdef' ) );

		$sanitized = SynthLoad_Settings::sanitize( array( 'access_token' => 'too-short' ) );
		$this->assertSame( '', $sanitized['access_token'] );
	}

	/** Calculator assumptions stay within supported bounds. */
	public function test_calculator_assumptions_are_clamped(): void {
		$sanitized = SynthLoad_Settings::sanitize(
			array(
				'calc_pages_per_visit'       => 50,
				'calc_cache_hit_rate'        => 100,
				'calc_connections_per_vcpu'  => 0,
				'calc_peak_to_average_ratio' => 20,
				'calc_flash_spike_percent'   => 0,
			)
		);

		$this->assertSame( 20, $sanitized['calc_pages_per_visit'] );
		$this->assertSame( 99, $sanitized['calc_cache_hit_rate'] );
		$this->assertSame( 1, $sanitized['calc_connections_per_vcpu'] );
		$this->assertSame( 10.0, $sanitized['calc_peak_to_average_ratio'] );
		$this->assertSame( 1, $sanitized['calc_flash_spike_percent'] );
	}
}
