<?php
/**
 * Tests for SynthLoad_Router class.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Router
 *
 * Tests for router and URL routing functionality.
 */
class Test_SynthLoad_Router extends WP_UnitTestCase {

    /**
     * Set up test fixtures.
     */
    public function set_up(): void {
        parent::set_up();
        // Set permalink structure for rewrite rules
        $this->set_permalink_structure( '/%postname%/' );
        // Delete settings for clean state
        delete_option( SynthLoad_Settings::OPTION_NAME );
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down(): void {
        delete_option( SynthLoad_Settings::OPTION_NAME );
        flush_rewrite_rules();
        parent::tear_down();
    }

    /**
     * Test that register_rewrites adds endpoint rule.
     */
    public function test_register_rewrites_adds_endpoint_rule(): void {
        SynthLoad_Router::register_rewrites();
        flush_rewrite_rules();

        global $wp_rewrite;
        $rules = $wp_rewrite->wp_rewrite_rules();

        $this->assertArrayHasKey( '^synthload/?$', $rules );
    }

    /**
     * Test that register_rewrites adds Loader.io rule.
     */
    public function test_register_rewrites_adds_loaderio_rule(): void {
        SynthLoad_Router::register_rewrites();
        flush_rewrite_rules();

        global $wp_rewrite;
        $rules = $wp_rewrite->wp_rewrite_rules();

        // Check for loaderio pattern
        $has_loaderio_rule = false;
        foreach ( array_keys( $rules ) as $pattern ) {
            if ( strpos( $pattern, 'loaderio' ) !== false ) {
                $has_loaderio_rule = true;
                break;
            }
        }

        $this->assertTrue( $has_loaderio_rule );
    }

    /**
     * Test that register_rewrites uses custom slug.
     */
    public function test_register_rewrites_uses_custom_slug(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'endpoint_slug' => 'my-custom-load' ) );

        SynthLoad_Router::register_rewrites();
        flush_rewrite_rules();

        global $wp_rewrite;
        $rules = $wp_rewrite->wp_rewrite_rules();

        $this->assertArrayHasKey( '^my-custom-load/?$', $rules );
        $this->assertArrayNotHasKey( '^synthload/?$', $rules );
    }

    /**
     * Test that add_query_vars adds both vars.
     */
    public function test_add_query_vars_adds_both_vars(): void {
        $vars = SynthLoad_Router::add_query_vars( array() );

        $this->assertContains( 'synthload_request', $vars );
        $this->assertContains( 'loaderio_verify', $vars );
    }

    /**
     * Test that add_query_vars preserves existing vars.
     */
    public function test_add_query_vars_preserves_existing_vars(): void {
        $vars = SynthLoad_Router::add_query_vars( array( 'existing_var' ) );

        $this->assertContains( 'existing_var', $vars );
        $this->assertContains( 'synthload_request', $vars );
    }

    /**
     * Test that get_rewrite_rules returns expected rules.
     */
    public function test_get_rewrite_rules_returns_expected_rules(): void {
        $rules = SynthLoad_Router::get_rewrite_rules();

        $this->assertIsArray( $rules );
        $this->assertGreaterThanOrEqual( 2, count( $rules ) );
    }

    /**
     * Test that rules_need_flush returns true when rules missing.
     */
    public function test_rules_need_flush_returns_true_when_rules_missing(): void {
        // Clear rewrite rules
        delete_option( 'rewrite_rules' );

        global $wp_rewrite;
        $wp_rewrite->init();

        $this->assertTrue( SynthLoad_Router::rules_need_flush() );
    }

    /**
     * Test that rules_need_flush returns false when rules present.
     */
    public function test_rules_need_flush_returns_false_when_rules_present(): void {
        SynthLoad_Router::register_rewrites();
        flush_rewrite_rules();

        $this->assertFalse( SynthLoad_Router::rules_need_flush() );
    }

    /**
     * Test query var constants.
     */
    public function test_query_var_constants(): void {
        $this->assertEquals( 'synthload_request', SynthLoad_Router::QV_SYNTHLOAD );
        $this->assertEquals( 'loaderio_verify', SynthLoad_Router::QV_LOADERIO );
    }

    /**
     * Test that custom slug with hyphen works.
     */
    public function test_custom_slug_with_hyphen_works(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'endpoint_slug' => 'load-test-endpoint' ) );

        SynthLoad_Router::register_rewrites();
        flush_rewrite_rules();

        global $wp_rewrite;
        $rules = $wp_rewrite->wp_rewrite_rules();

        $this->assertArrayHasKey( '^load-test-endpoint/?$', $rules );
    }
}
