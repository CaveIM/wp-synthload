<?php
/**
 * vCPU Capacity Calculator for WP Synthetic Load plugin.
 *
 * @package WP_SynthLoad
 */

// Security check - prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SynthLoad_Calculator
 *
 * Calculates recommended vCPU requirements based on traffic patterns,
 * response times, and configurable assumptions.
 */
class SynthLoad_Calculator {

	/**
	 * Default assumption values.
	 *
	 * @var array
	 */
	const DEFAULTS = array(
		'pages_per_visit'       => 3,
		'cache_hit_rate'        => 70,    // Percentage (0-100).
		'connections_per_vcpu'  => 45,    // Optimized hosting baseline.
		'peak_to_average_ratio' => 2.5,   // For business hours traffic.
		'flash_spike_percent'   => 15,    // Percentage of monthly traffic in spike hour.
	);

	/**
	 * Traffic shape options.
	 *
	 * @var array
	 */
	const TRAFFIC_SHAPES = array(
		'uniform'    => 'Uniform (spread evenly 24/7)',
		'business'   => 'Business Hours (8h workday, 22 days/month)',
		'flash_sale' => 'Flash Sale (15% of traffic in 1 hour)',
	);

	/**
	 * Safety factor options.
	 *
	 * @var array
	 */
	const SAFETY_FACTORS = array(
		'1.0' => 'None (1.0x)',
		'1.5' => 'Standard (1.5x)',
		'2.0' => 'Conservative (2.0x)',
		'3.0' => 'High Availability (3.0x)',
	);

	/**
	 * Get default assumption values.
	 *
	 * @return array Default assumptions.
	 */
	public static function get_defaults(): array {
		return self::DEFAULTS;
	}

	/**
	 * Get traffic shape options.
	 *
	 * @return array Traffic shapes with labels.
	 */
	public static function get_traffic_shapes(): array {
		return self::TRAFFIC_SHAPES;
	}

	/**
	 * Get safety factor options.
	 *
	 * @return array Safety factors with labels.
	 */
	public static function get_safety_factors(): array {
		return self::SAFETY_FACTORS;
	}

	/**
	 * Calculate peak RPS from monthly visitors.
	 *
	 * @param int    $monthly_visitors Monthly visitor count.
	 * @param string $traffic_shape    Traffic distribution pattern.
	 * @param array  $assumptions      Override default assumptions.
	 * @return float Peak requests per second.
	 */
	public static function calculate_peak_rps(
		int $monthly_visitors,
		string $traffic_shape = 'uniform',
		array $assumptions = array()
	): float {
		$assumptions = self::merge_assumptions( $assumptions );

		// Step 1: Calculate page views.
		$page_views = $monthly_visitors * $assumptions['pages_per_visit'];

		// Step 2: Apply cache hit rate (only uncached requests hit server fully).
		$cache_rate         = $assumptions['cache_hit_rate'] / 100;
		$effective_requests = $page_views * ( 1 - $cache_rate );

		// Step 3: Calculate peak RPS based on traffic shape.
		switch ( $traffic_shape ) {
			case 'business':
				// 8 hours per day, 22 business days per month.
				$seconds_per_month = 8 * 22 * 3600;
				$base_rps          = $effective_requests / $seconds_per_month;
				return $base_rps * $assumptions['peak_to_average_ratio'];

			case 'flash_sale':
				// Spike percentage of traffic in 1 hour.
				$spike_percent  = $assumptions['flash_spike_percent'] / 100;
				$spike_requests = $effective_requests * $spike_percent;
				return $spike_requests / 3600;

			case 'uniform':
			default:
				// Spread evenly over entire month.
				$seconds_per_month = 30 * 24 * 3600;
				return $effective_requests / $seconds_per_month;
		}
	}

	/**
	 * Calculate concurrent connections from RPS using Little's Law.
	 *
	 * @param float $rps              Requests per second.
	 * @param int   $response_time_ms Response time in milliseconds.
	 * @return float Concurrent connections.
	 */
	public static function calculate_concurrent_connections(
		float $rps,
		int $response_time_ms
	): float {
		return $rps * ( $response_time_ms / 1000 );
	}

	/**
	 * Calculate recommended vCPUs.
	 *
	 * @param float $concurrent_connections Concurrent connections.
	 * @param float $safety_factor          Safety multiplier.
	 * @param array $assumptions            Override defaults.
	 * @return int Recommended vCPU count (minimum 1).
	 */
	public static function calculate_vcpus(
		float $concurrent_connections,
		float $safety_factor = 1.5,
		array $assumptions = array()
	): int {
		$assumptions = self::merge_assumptions( $assumptions );

		$raw_vcpus   = $concurrent_connections / $assumptions['connections_per_vcpu'];
		$with_safety = $raw_vcpus * $safety_factor;

		return max( 1, (int) ceil( $with_safety ) );
	}

	/**
	 * Get full calculation breakdown.
	 *
	 * @param int    $monthly_visitors Monthly visitors.
	 * @param int    $response_time_ms Response time in ms.
	 * @param string $traffic_shape    Traffic pattern.
	 * @param float  $safety_factor    Safety multiplier.
	 * @param array  $assumptions      Custom assumptions.
	 * @return array Calculation results and breakdown.
	 */
	public static function get_full_calculation(
		int $monthly_visitors,
		int $response_time_ms,
		string $traffic_shape = 'uniform',
		float $safety_factor = 1.5,
		array $assumptions = array()
	): array {
		$assumptions = self::merge_assumptions( $assumptions );

		// Step 1: Monthly page views.
		$page_views = $monthly_visitors * $assumptions['pages_per_visit'];

		// Step 2: Effective requests after cache.
		$cache_rate         = $assumptions['cache_hit_rate'] / 100;
		$effective_requests = $page_views * ( 1 - $cache_rate );

		// Step 3: Peak RPS.
		$peak_rps = self::calculate_peak_rps(
			$monthly_visitors,
			$traffic_shape,
			$assumptions
		);

		// Step 4: Concurrent connections.
		$concurrent = self::calculate_concurrent_connections(
			$peak_rps,
			$response_time_ms
		);

		// Step 5: vCPUs.
		$vcpus = self::calculate_vcpus(
			$concurrent,
			$safety_factor,
			$assumptions
		);

		return array(
			'inputs'      => array(
				'monthly_visitors' => $monthly_visitors,
				'response_time_ms' => $response_time_ms,
				'traffic_shape'    => $traffic_shape,
				'safety_factor'    => $safety_factor,
			),
			'assumptions' => $assumptions,
			'results'     => array(
				'monthly_page_views'     => $page_views,
				'effective_requests'     => round( $effective_requests ),
				'peak_rps'               => round( $peak_rps, 2 ),
				'concurrent_connections' => round( $concurrent, 1 ),
				'recommended_vcpus'      => $vcpus,
			),
		);
	}

	/**
	 * Merge custom assumptions with defaults.
	 *
	 * @param array $custom Custom assumption values.
	 * @return array Merged assumptions.
	 */
	private static function merge_assumptions( array $custom ): array {
		return array_merge( self::DEFAULTS, $custom );
	}

	/**
	 * Get traffic shape description for breakdown display.
	 *
	 * @param string $shape Traffic shape key.
	 * @return string Human-readable description.
	 */
	public static function get_traffic_shape_formula( string $shape ): string {
		switch ( $shape ) {
			case 'business':
				return '8 hours/day x 22 business days x peak ratio';
			case 'flash_sale':
				return 'spike % of monthly traffic in 1 hour';
			case 'uniform':
			default:
				return '30 days x 24 hours (spread evenly)';
		}
	}
}
