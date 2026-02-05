<?php
/**
 * Tests for SynthLoad_Calculator class.
 *
 * @package WP_SynthLoad
 */

/**
 * Test class for vCPU capacity calculator.
 */
class Test_Calculator extends WP_UnitTestCase {

	/**
	 * Test uniform traffic RPS calculation.
	 */
	public function test_uniform_traffic_rps(): void {
		// 1 million visitors, 5 pages each (default), 30% cached (default)
		// = 5M page views * 0.7 = 3,500,000 requests / month
		// = 3,500,000 / (30 * 24 * 3600) = 1.35 RPS.
		$rps = SynthLoad_Calculator::calculate_peak_rps(
			1000000,
			'uniform'
		);

		$this->assertEqualsWithDelta( 1.35, $rps, 0.1 );
	}

	/**
	 * Test business hours RPS is higher than uniform.
	 */
	public function test_business_hours_rps_higher_than_uniform(): void {
		$uniform_rps  = SynthLoad_Calculator::calculate_peak_rps( 1000000, 'uniform' );
		$business_rps = SynthLoad_Calculator::calculate_peak_rps( 1000000, 'business' );

		$this->assertGreaterThan( $uniform_rps, $business_rps );
	}

	/**
	 * Test flash sale RPS is higher than business hours.
	 */
	public function test_flash_sale_rps_higher_than_business(): void {
		$business_rps   = SynthLoad_Calculator::calculate_peak_rps( 1000000, 'business' );
		$flash_sale_rps = SynthLoad_Calculator::calculate_peak_rps( 1000000, 'flash_sale' );

		$this->assertGreaterThan( $business_rps, $flash_sale_rps );
	}

	/**
	 * Test concurrent connections calculation (Little's Law).
	 */
	public function test_concurrent_connections(): void {
		// 10 RPS with 500ms response time = 5 concurrent.
		$concurrent = SynthLoad_Calculator::calculate_concurrent_connections( 10.0, 500 );

		$this->assertEquals( 5.0, $concurrent );
	}

	/**
	 * Test concurrent connections with 1 second response time.
	 */
	public function test_concurrent_connections_one_second(): void {
		// 20 RPS with 1000ms response time = 20 concurrent.
		$concurrent = SynthLoad_Calculator::calculate_concurrent_connections( 20.0, 1000 );

		$this->assertEquals( 20.0, $concurrent );
	}

	/**
	 * Test minimum vCPU is always 1.
	 */
	public function test_minimum_vcpu_is_one(): void {
		// Very low traffic should still recommend at least 1 vCPU.
		$vcpus = SynthLoad_Calculator::calculate_vcpus( 0.1, 1.5 );

		$this->assertEquals( 1, $vcpus );
	}

	/**
	 * Test vCPU calculation with safety factor.
	 */
	public function test_vcpu_with_safety_factor(): void {
		// 4 concurrent connections / 2 per vCPU = 2.
		// With 1.5x safety = 3 vCPUs.
		$vcpus = SynthLoad_Calculator::calculate_vcpus( 4.0, 1.5 );

		$this->assertEquals( 3, $vcpus );
	}

	/**
	 * Test vCPU calculation without safety factor.
	 */
	public function test_vcpu_without_safety_factor(): void {
		// 4 concurrent connections / 2 per vCPU = 2 vCPUs.
		$vcpus = SynthLoad_Calculator::calculate_vcpus( 4.0, 1.0 );

		$this->assertEquals( 2, $vcpus );
	}

	/**
	 * Test vCPU rounds up.
	 */
	public function test_vcpu_rounds_up(): void {
		// 3 concurrent / 2 = 1.5, rounded up = 2.
		$vcpus = SynthLoad_Calculator::calculate_vcpus( 3.0, 1.0 );

		$this->assertEquals( 2, $vcpus );
	}

	/**
	 * Test full calculation returns expected structure.
	 */
	public function test_full_calculation_structure(): void {
		$result = SynthLoad_Calculator::get_full_calculation(
			100000,   // visitors.
			500,      // response time.
			'uniform',
			1.5
		);

		$this->assertArrayHasKey( 'inputs', $result );
		$this->assertArrayHasKey( 'assumptions', $result );
		$this->assertArrayHasKey( 'results', $result );

		$this->assertArrayHasKey( 'monthly_visitors', $result['inputs'] );
		$this->assertArrayHasKey( 'response_time_ms', $result['inputs'] );
		$this->assertArrayHasKey( 'traffic_shape', $result['inputs'] );
		$this->assertArrayHasKey( 'safety_factor', $result['inputs'] );

		$this->assertArrayHasKey( 'monthly_page_views', $result['results'] );
		$this->assertArrayHasKey( 'effective_requests', $result['results'] );
		$this->assertArrayHasKey( 'peak_rps', $result['results'] );
		$this->assertArrayHasKey( 'concurrent_connections', $result['results'] );
		$this->assertArrayHasKey( 'recommended_vcpus', $result['results'] );
	}

	/**
	 * Test full calculation values are reasonable.
	 */
	public function test_full_calculation_values(): void {
		$result = SynthLoad_Calculator::get_full_calculation(
			100000,   // 100k visitors.
			500,      // 500ms response.
			'uniform',
			1.5
		);

		// 100k visitors * 5 pages (default) = 500k page views.
		$this->assertEquals( 500000, $result['results']['monthly_page_views'] );

		// 500k * 0.7 (after 30% cache) = 350k effective.
		$this->assertEquals( 350000, $result['results']['effective_requests'] );

		// vCPUs should be at least 1.
		$this->assertGreaterThanOrEqual( 1, $result['results']['recommended_vcpus'] );
	}

	/**
	 * Test custom assumptions override defaults.
	 */
	public function test_custom_assumptions(): void {
		$custom = array( 'pages_per_visit' => 10 ); // Double the default of 5.

		$result_default = SynthLoad_Calculator::get_full_calculation(
			100000,
			500,
			'uniform',
			1.5
		);

		$result_custom = SynthLoad_Calculator::get_full_calculation(
			100000,
			500,
			'uniform',
			1.5,
			$custom
		);

		// More pages = more page views = higher RPS.
		$this->assertGreaterThan(
			$result_default['results']['peak_rps'],
			$result_custom['results']['peak_rps']
		);
	}

	/**
	 * Test custom cache hit rate affects results.
	 */
	public function test_custom_cache_hit_rate(): void {
		$low_cache  = array( 'cache_hit_rate' => 50 );
		$high_cache = array( 'cache_hit_rate' => 90 );

		$result_low_cache = SynthLoad_Calculator::get_full_calculation(
			100000,
			500,
			'uniform',
			1.5,
			$low_cache
		);

		$result_high_cache = SynthLoad_Calculator::get_full_calculation(
			100000,
			500,
			'uniform',
			1.5,
			$high_cache
		);

		// Lower cache = more server requests = higher RPS.
		$this->assertGreaterThan(
			$result_high_cache['results']['peak_rps'],
			$result_low_cache['results']['peak_rps']
		);
	}

	/**
	 * Test zero visitors returns minimum values.
	 */
	public function test_zero_visitors(): void {
		$result = SynthLoad_Calculator::get_full_calculation(
			0,
			500,
			'uniform',
			1.5
		);

		$this->assertEquals( 0, $result['results']['monthly_page_views'] );
		$this->assertEquals( 0, $result['results']['effective_requests'] );
		$this->assertEquals( 0, $result['results']['peak_rps'] );
		$this->assertEquals( 0, $result['results']['concurrent_connections'] );
		$this->assertEquals( 1, $result['results']['recommended_vcpus'] ); // Minimum 1.
	}

	/**
	 * Test get_defaults returns expected keys.
	 */
	public function test_get_defaults(): void {
		$defaults = SynthLoad_Calculator::get_defaults();

		$this->assertArrayHasKey( 'pages_per_visit', $defaults );
		$this->assertArrayHasKey( 'cache_hit_rate', $defaults );
		$this->assertArrayHasKey( 'connections_per_vcpu', $defaults );
		$this->assertArrayHasKey( 'peak_to_average_ratio', $defaults );
		$this->assertArrayHasKey( 'flash_spike_percent', $defaults );
	}

	/**
	 * Test get_traffic_shapes returns expected options.
	 */
	public function test_get_traffic_shapes(): void {
		$shapes = SynthLoad_Calculator::get_traffic_shapes();

		$this->assertArrayHasKey( 'uniform', $shapes );
		$this->assertArrayHasKey( 'business', $shapes );
		$this->assertArrayHasKey( 'flash_sale', $shapes );
	}

	/**
	 * Test get_safety_factors returns expected options.
	 */
	public function test_get_safety_factors(): void {
		$factors = SynthLoad_Calculator::get_safety_factors();

		$this->assertArrayHasKey( '1.0', $factors );
		$this->assertArrayHasKey( '1.5', $factors );
		$this->assertArrayHasKey( '2.0', $factors );
		$this->assertArrayHasKey( '3.0', $factors );
	}

	/**
	 * Test high traffic scenario produces reasonable results.
	 */
	public function test_high_traffic_scenario(): void {
		// 5 million visitors/month, 400ms response, business hours.
		$result = SynthLoad_Calculator::get_full_calculation(
			5000000,
			400,
			'business',
			1.5
		);

		// Should recommend multiple vCPUs.
		$this->assertGreaterThan( 1, $result['results']['recommended_vcpus'] );

		// Peak RPS should be significant.
		$this->assertGreaterThan( 1, $result['results']['peak_rps'] );
	}

	/**
	 * Test flash sale produces highest vCPU recommendation.
	 */
	public function test_flash_sale_highest_vcpus(): void {
		$visitors     = 500000;
		$response_ms  = 800;
		$safety       = 1.5;

		$uniform    = SynthLoad_Calculator::get_full_calculation( $visitors, $response_ms, 'uniform', $safety );
		$business   = SynthLoad_Calculator::get_full_calculation( $visitors, $response_ms, 'business', $safety );
		$flash_sale = SynthLoad_Calculator::get_full_calculation( $visitors, $response_ms, 'flash_sale', $safety );

		$this->assertGreaterThanOrEqual(
			$uniform['results']['recommended_vcpus'],
			$business['results']['recommended_vcpus']
		);

		$this->assertGreaterThanOrEqual(
			$business['results']['recommended_vcpus'],
			$flash_sale['results']['recommended_vcpus']
		);
	}

	/**
	 * Test get_presets returns expected site types.
	 */
	public function test_get_presets(): void {
		$presets = SynthLoad_Calculator::get_presets();

		$this->assertArrayHasKey( 'dynamic', $presets );
		$this->assertArrayHasKey( 'static', $presets );
	}

	/**
	 * Test dynamic preset has lower connections per vCPU than static.
	 */
	public function test_dynamic_preset_lower_connections_per_vcpu(): void {
		$presets = SynthLoad_Calculator::get_presets();

		$this->assertLessThan(
			$presets['static']['connections_per_vcpu'],
			$presets['dynamic']['connections_per_vcpu']
		);
	}

	/**
	 * Test static preset has higher cache hit rate than dynamic.
	 */
	public function test_static_preset_higher_cache_rate(): void {
		$presets = SynthLoad_Calculator::get_presets();

		$this->assertGreaterThan(
			$presets['dynamic']['cache_hit_rate'],
			$presets['static']['cache_hit_rate']
		);
	}

	/**
	 * Test get_preset returns correct preset.
	 */
	public function test_get_preset(): void {
		$dynamic = SynthLoad_Calculator::get_preset( 'dynamic' );
		$static  = SynthLoad_Calculator::get_preset( 'static' );

		$this->assertEquals( 2, $dynamic['connections_per_vcpu'] );
		$this->assertEquals( 8, $static['connections_per_vcpu'] );
	}

	/**
	 * Test get_preset returns defaults for unknown preset.
	 */
	public function test_get_preset_unknown_returns_defaults(): void {
		$unknown  = SynthLoad_Calculator::get_preset( 'unknown' );
		$defaults = SynthLoad_Calculator::get_defaults();

		$this->assertEquals( $defaults['connections_per_vcpu'], $unknown['connections_per_vcpu'] );
	}

	/**
	 * Test static site needs fewer vCPUs than dynamic for same traffic.
	 */
	public function test_static_needs_fewer_vcpus_than_dynamic(): void {
		$presets = SynthLoad_Calculator::get_presets();

		$dynamic_result = SynthLoad_Calculator::get_full_calculation(
			100000,
			500,
			'uniform',
			1.5,
			$presets['dynamic']
		);

		$static_result = SynthLoad_Calculator::get_full_calculation(
			100000,
			500,
			'uniform',
			1.5,
			$presets['static']
		);

		// Static site with better caching should need fewer vCPUs.
		$this->assertLessThanOrEqual(
			$dynamic_result['results']['recommended_vcpus'],
			$static_result['results']['recommended_vcpus']
		);
	}
}
