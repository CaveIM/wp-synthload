<?php
/**
 * Tests for SynthLoad_Settings class.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Settings
 *
 * Tests for settings management functionality.
 */
class Test_SynthLoad_Settings extends WP_UnitTestCase {

    /**
     * Set up test fixtures.
     */
    public function set_up(): void {
        parent::set_up();
        // Delete the option to ensure clean state
        delete_option( SynthLoad_Settings::OPTION_NAME );
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down(): void {
        delete_option( SynthLoad_Settings::OPTION_NAME );
        parent::tear_down();
    }

    /**
     * Test that get_defaults returns a complete array with all expected keys.
     */
    public function test_get_defaults_returns_complete_array(): void {
        $defaults = SynthLoad_Settings::get_defaults();

        $expected_keys = array(
            'loaderio_token',
            'endpoint_slug',
            'endpoint_enabled',
            'access_token',
            'profile',
            'read_query_count',
            'write_op_count',
            'target_duration_ms',
            'duration_jitter_ms',
            'use_object_cache',
            'bypass_object_cache',
            'randomize_workload',
            'debug_logging_enabled',
        );

        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $defaults, "Missing key: {$key}" );
        }
    }

    /**
     * Test that default values are correct.
     */
    public function test_get_defaults_has_correct_default_values(): void {
        $defaults = SynthLoad_Settings::get_defaults();

        $this->assertEquals( 'synthload', $defaults['endpoint_slug'] );
        $this->assertEquals( 100, $defaults['read_query_count'] );
        $this->assertEquals( 5, $defaults['write_op_count'] );
        $this->assertEquals( 3000, $defaults['target_duration_ms'] );
        $this->assertEquals( 750, $defaults['duration_jitter_ms'] );
        $this->assertTrue( $defaults['endpoint_enabled'] );
        $this->assertTrue( $defaults['use_object_cache'] );
        $this->assertFalse( $defaults['bypass_object_cache'] );
        $this->assertTrue( $defaults['randomize_workload'] );
        $this->assertFalse( $defaults['debug_logging_enabled'] );
    }

    /**
     * Test that get_hard_limits returns all limit keys.
     */
    public function test_get_hard_limits_returns_all_limits(): void {
        $limits = SynthLoad_Settings::get_hard_limits();

        $expected_keys = array(
            'max_total_duration_ms',
            'max_read_query_count',
            'max_write_op_count',
            'max_rows_to_keep',
        );

        foreach ( $expected_keys as $key ) {
            $this->assertArrayHasKey( $key, $limits, "Missing limit key: {$key}" );
        }

        // Verify the actual limits
        $this->assertEquals( 15000, $limits['max_total_duration_ms'] );
        $this->assertEquals( 2000, $limits['max_read_query_count'] );
        $this->assertEquals( 200, $limits['max_write_op_count'] );
        $this->assertEquals( 100000, $limits['max_rows_to_keep'] );
    }

    /**
     * Test that get_all returns defaults when no option is set.
     */
    public function test_get_all_returns_defaults_when_no_option_set(): void {
        $settings = SynthLoad_Settings::get_all();
        $defaults = SynthLoad_Settings::get_defaults();

        $this->assertEquals( $defaults, $settings );
    }

    /**
     * Test that get_all merges stored values with defaults.
     */
    public function test_get_all_merges_with_defaults(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'endpoint_slug' => 'custom-slug' ) );

        $settings = SynthLoad_Settings::get_all();

        $this->assertEquals( 'custom-slug', $settings['endpoint_slug'] );
        $this->assertEquals( 100, $settings['read_query_count'] ); // Default value
    }

    /**
     * Test getting a single setting value.
     */
    public function test_get_single_setting(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'endpoint_slug' => 'test-slug' ) );

        $this->assertEquals( 'test-slug', SynthLoad_Settings::get( 'endpoint_slug' ) );
    }

    /**
     * Test that get returns default for missing key.
     */
    public function test_get_returns_default_for_missing_key(): void {
        $this->assertEquals( 'fallback', SynthLoad_Settings::get( 'nonexistent', 'fallback' ) );
    }

    /**
     * Test that update stores settings.
     */
    public function test_update_stores_settings(): void {
        SynthLoad_Settings::update( array( 'endpoint_slug' => 'new-slug' ) );

        $raw_option = get_option( SynthLoad_Settings::OPTION_NAME );
        $this->assertEquals( 'new-slug', $raw_option['endpoint_slug'] );
    }

    /**
     * Test that sanitize clamps read_query_count to max.
     */
    public function test_sanitize_clamps_read_query_count_to_max(): void {
        $sanitized = SynthLoad_Settings::sanitize( array( 'read_query_count' => 5000 ) );

        $this->assertEquals( 2000, $sanitized['read_query_count'] );
    }

    /**
     * Test that sanitize clamps write_op_count to max.
     */
    public function test_sanitize_clamps_write_op_count_to_max(): void {
        $sanitized = SynthLoad_Settings::sanitize( array( 'write_op_count' => 500 ) );

        $this->assertEquals( 200, $sanitized['write_op_count'] );
    }

    /**
     * Test that sanitize clamps target_duration_ms to max.
     */
    public function test_sanitize_clamps_target_duration_to_max(): void {
        $sanitized = SynthLoad_Settings::sanitize( array( 'target_duration_ms' => 30000 ) );

        $this->assertEquals( 15000, $sanitized['target_duration_ms'] );
    }

    /**
     * Test that sanitize clamps values to minimum.
     */
    public function test_sanitize_clamps_values_to_minimum(): void {
        $sanitized = SynthLoad_Settings::sanitize( array( 'read_query_count' => -10 ) );
        $this->assertEquals( 0, $sanitized['read_query_count'] );

        $sanitized = SynthLoad_Settings::sanitize( array( 'target_duration_ms' => 50 ) );
        $this->assertEquals( 100, $sanitized['target_duration_ms'] );
    }

    /**
     * Test that sanitize converts types correctly.
     */
    public function test_sanitize_converts_types(): void {
        $sanitized = SynthLoad_Settings::sanitize( array(
            'read_query_count' => '50',
            'endpoint_enabled' => '1',
            'randomize_workload' => 0,
        ) );

        $this->assertIsInt( $sanitized['read_query_count'] );
        $this->assertEquals( 50, $sanitized['read_query_count'] );

        $this->assertIsBool( $sanitized['endpoint_enabled'] );
        $this->assertTrue( $sanitized['endpoint_enabled'] );

        $this->assertIsBool( $sanitized['randomize_workload'] );
        $this->assertFalse( $sanitized['randomize_workload'] );
    }

    /**
     * Test that sanitize validates profile values.
     */
    public function test_sanitize_validates_profile(): void {
        $sanitized = SynthLoad_Settings::sanitize( array( 'profile' => 'invalid' ) );
        $this->assertEquals( 'general', $sanitized['profile'] );

        $sanitized = SynthLoad_Settings::sanitize( array( 'profile' => 'membership' ) );
        $this->assertEquals( 'membership', $sanitized['profile'] );

        $sanitized = SynthLoad_Settings::sanitize( array( 'profile' => 'ecommerce' ) );
        $this->assertEquals( 'ecommerce', $sanitized['profile'] );
    }

    /**
     * Test that sanitize removes invalid token characters.
     */
    public function test_sanitize_removes_invalid_token_characters(): void {
        $sanitized = SynthLoad_Settings::sanitize( array( 'loaderio_token' => 'abc-123!@#' ) );

        $this->assertEquals( 'abc123', $sanitized['loaderio_token'] );
    }

    /**
     * Test that is_valid_slug accepts valid slugs.
     */
    public function test_is_valid_slug_accepts_valid_slugs(): void {
        $this->assertTrue( SynthLoad_Settings::is_valid_slug( 'synthload' ) );
        $this->assertTrue( SynthLoad_Settings::is_valid_slug( 'my-load-test' ) );
        $this->assertTrue( SynthLoad_Settings::is_valid_slug( 'test123' ) );
        $this->assertTrue( SynthLoad_Settings::is_valid_slug( 'load-test-endpoint' ) );
    }

    /**
     * Test that is_valid_slug rejects invalid slugs.
     */
    public function test_is_valid_slug_rejects_invalid_slugs(): void {
        $this->assertFalse( SynthLoad_Settings::is_valid_slug( '' ) );
        $this->assertFalse( SynthLoad_Settings::is_valid_slug( 'has spaces' ) );
        $this->assertFalse( SynthLoad_Settings::is_valid_slug( 'special!chars' ) );
        $this->assertFalse( SynthLoad_Settings::is_valid_slug( 'UPPERCASE' ) );
        $this->assertFalse( SynthLoad_Settings::is_valid_slug( 'under_score' ) );
    }

    /**
     * Test that is_valid_slug rejects reserved WordPress paths.
     */
    public function test_is_valid_slug_rejects_reserved_paths(): void {
        $this->assertFalse( SynthLoad_Settings::is_valid_slug( 'wp-admin' ) );
        $this->assertFalse( SynthLoad_Settings::is_valid_slug( 'wp-includes' ) );
        $this->assertFalse( SynthLoad_Settings::is_valid_slug( 'wp-content' ) );
        $this->assertFalse( SynthLoad_Settings::is_valid_slug( 'wp-json' ) );
        $this->assertFalse( SynthLoad_Settings::is_valid_slug( 'feed' ) );
    }

    /**
     * Test that is_valid_token accepts valid tokens.
     */
    public function test_is_valid_token_accepts_valid_tokens(): void {
        $this->assertTrue( SynthLoad_Settings::is_valid_token( '' ) ); // Empty is valid (optional)
        $this->assertTrue( SynthLoad_Settings::is_valid_token( 'abc123' ) );
        $this->assertTrue( SynthLoad_Settings::is_valid_token( 'loaderio1234567890abcdef' ) );
        $this->assertTrue( SynthLoad_Settings::is_valid_token( 'ABC123xyz' ) );
    }

    /**
     * Test that is_valid_token rejects invalid tokens.
     */
    public function test_is_valid_token_rejects_invalid_tokens(): void {
        $this->assertFalse( SynthLoad_Settings::is_valid_token( 'has-hyphen' ) );
        $this->assertFalse( SynthLoad_Settings::is_valid_token( 'has space' ) );
        $this->assertFalse( SynthLoad_Settings::is_valid_token( 'special!@#' ) );
        $this->assertFalse( SynthLoad_Settings::is_valid_token( 'under_score' ) );
    }

    /**
     * Test that boolean conversion handles various truthy values.
     */
    public function test_sanitize_handles_truthy_values(): void {
        $truthy_values = array( '1', 'true', 'yes', 'on', 1, true );

        foreach ( $truthy_values as $value ) {
            $sanitized = SynthLoad_Settings::sanitize( array( 'endpoint_enabled' => $value ) );
            $this->assertTrue( $sanitized['endpoint_enabled'], "Failed for value: " . var_export( $value, true ) );
        }
    }

    /**
     * Test that boolean conversion handles various falsy values.
     */
    public function test_sanitize_handles_falsy_values(): void {
        $falsy_values = array( '0', 'false', 'no', 'off', 0, false, '' );

        foreach ( $falsy_values as $value ) {
            $sanitized = SynthLoad_Settings::sanitize( array( 'endpoint_enabled' => $value ) );
            $this->assertFalse( $sanitized['endpoint_enabled'], "Failed for value: " . var_export( $value, true ) );
        }
    }

    /**
     * Test that update preserves existing settings not in the update array.
     */
    public function test_update_preserves_existing_settings(): void {
        // Set initial settings
        SynthLoad_Settings::update( array(
            'endpoint_slug' => 'initial-slug',
            'read_query_count' => 50,
        ) );

        // Update only one setting
        SynthLoad_Settings::update( array( 'read_query_count' => 75 ) );

        $settings = SynthLoad_Settings::get_all();
        $this->assertEquals( 'initial-slug', $settings['endpoint_slug'] );
        $this->assertEquals( 75, $settings['read_query_count'] );
    }

    /**
     * Test that invalid endpoint slug reverts to default.
     */
    public function test_sanitize_invalid_slug_reverts_to_default(): void {
        $sanitized = SynthLoad_Settings::sanitize( array( 'endpoint_slug' => 'invalid slug!' ) );
        $this->assertEquals( 'synthload', $sanitized['endpoint_slug'] );

        $sanitized = SynthLoad_Settings::sanitize( array( 'endpoint_slug' => 'wp-admin' ) );
        $this->assertEquals( 'synthload', $sanitized['endpoint_slug'] );
    }

    /**
     * Test duration jitter is clamped correctly.
     */
    public function test_sanitize_clamps_duration_jitter(): void {
        $sanitized = SynthLoad_Settings::sanitize( array( 'duration_jitter_ms' => 10000 ) );
        $this->assertEquals( 5000, $sanitized['duration_jitter_ms'] );

        $sanitized = SynthLoad_Settings::sanitize( array( 'duration_jitter_ms' => -100 ) );
        $this->assertEquals( 0, $sanitized['duration_jitter_ms'] );
    }
}
