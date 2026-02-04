<?php
/**
 * Tests for SynthLoad_Activator class.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Activation
 *
 * Tests for activation and deactivation functionality.
 */
class Test_SynthLoad_Activation extends WP_UnitTestCase {

    /**
     * Set up test fixtures.
     */
    public function set_up(): void {
        parent::set_up();
        // Set permalink structure for rewrite rules
        $this->set_permalink_structure( '/%postname%/' );
        // Clean up any existing plugin data
        delete_option( SynthLoad_Settings::OPTION_NAME );
        delete_option( 'synthload_schema_version' );
        SynthLoad_Db::drop_table();
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down(): void {
        delete_option( SynthLoad_Settings::OPTION_NAME );
        delete_option( 'synthload_schema_version' );
        SynthLoad_Db::drop_table();
        parent::tear_down();
    }

    /**
     * Test that activate creates the database table.
     */
    public function test_activate_creates_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'synthload_events';

        // Ensure table doesn't exist
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

        SynthLoad_Activator::activate();

        // Check table exists
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
        ) === $table_name;

        $this->assertTrue( $exists );
    }

    /**
     * Test that activate sets default options.
     */
    public function test_activate_sets_default_options(): void {
        delete_option( SynthLoad_Settings::OPTION_NAME );

        SynthLoad_Activator::activate();

        $options = get_option( SynthLoad_Settings::OPTION_NAME );

        $this->assertIsArray( $options );
        $this->assertArrayHasKey( 'endpoint_slug', $options );
        $this->assertArrayHasKey( 'read_query_count', $options );
    }

    /**
     * Test that activate sets schema version.
     */
    public function test_activate_sets_schema_version(): void {
        delete_option( 'synthload_schema_version' );

        SynthLoad_Activator::activate();

        $version = get_option( 'synthload_schema_version' );
        $this->assertEquals( SynthLoad_Activator::SCHEMA_VERSION, $version );
    }

    /**
     * Test that activate seeds initial data.
     */
    public function test_activate_seeds_initial_data(): void {
        global $wpdb;
        $db = new SynthLoad_Db( $wpdb );

        // Ensure table is empty
        SynthLoad_Db::drop_table();
        SynthLoad_Db::create_table();

        $this->assertEquals( 0, $db->count_events() );

        SynthLoad_Activator::activate();

        // Should have seeded data
        $count = $db->count_events();
        $this->assertGreaterThanOrEqual( 500, $count );
    }

    /**
     * Test that activate registers rewrite rules.
     */
    public function test_activate_registers_rewrite_rules(): void {
        SynthLoad_Activator::activate();

        global $wp_rewrite;
        $rules = $wp_rewrite->wp_rewrite_rules();

        // Check our rules exist
        $has_synthload_rule = false;
        foreach ( array_keys( $rules ) as $pattern ) {
            if ( strpos( $pattern, 'synthload' ) !== false ) {
                $has_synthload_rule = true;
                break;
            }
        }

        $this->assertTrue( $has_synthload_rule );
    }

    /**
     * Test that deactivate does not delete options.
     */
    public function test_deactivate_does_not_delete_options(): void {
        // Set some custom settings
        SynthLoad_Settings::update( array( 'endpoint_slug' => 'custom-test' ) );

        SynthLoad_Activator::deactivate();

        // Options should still exist
        $options = get_option( SynthLoad_Settings::OPTION_NAME );
        $this->assertNotFalse( $options );
        $this->assertEquals( 'custom-test', $options['endpoint_slug'] );
    }

    /**
     * Test that deactivate does not drop table.
     */
    public function test_deactivate_does_not_drop_table(): void {
        global $wpdb;

        // Ensure table exists
        SynthLoad_Db::create_table();

        $db = new SynthLoad_Db( $wpdb );
        $this->assertTrue( $db->table_exists() );

        SynthLoad_Activator::deactivate();

        // Table should still exist
        $this->assertTrue( $db->table_exists() );
    }

    /**
     * Test that maybe_upgrade runs on version mismatch.
     */
    public function test_maybe_upgrade_runs_on_version_mismatch(): void {
        // Set old schema version
        update_option( 'synthload_schema_version', 0 );

        // Drop table to simulate upgrade needing to recreate
        SynthLoad_Db::drop_table();

        SynthLoad_Activator::maybe_upgrade();

        // Schema version should be updated
        $version = get_option( 'synthload_schema_version' );
        $this->assertEquals( SynthLoad_Activator::SCHEMA_VERSION, $version );
    }

    /**
     * Test that maybe_upgrade does nothing when up to date.
     */
    public function test_maybe_upgrade_does_nothing_when_current(): void {
        // Set current schema version
        update_option( 'synthload_schema_version', SynthLoad_Activator::SCHEMA_VERSION );

        // This should not cause errors
        SynthLoad_Activator::maybe_upgrade();

        $version = get_option( 'synthload_schema_version' );
        $this->assertEquals( SynthLoad_Activator::SCHEMA_VERSION, $version );
    }

    /**
     * Test that activation preserves existing options.
     */
    public function test_activation_preserves_existing_options(): void {
        // Set some options before activation
        add_option( SynthLoad_Settings::OPTION_NAME, array(
            'endpoint_slug' => 'my-custom-slug',
            'read_query_count' => 50,
        ) );

        SynthLoad_Activator::activate();

        $options = get_option( SynthLoad_Settings::OPTION_NAME );

        // Should preserve custom values
        $this->assertEquals( 'my-custom-slug', $options['endpoint_slug'] );
        $this->assertEquals( 50, $options['read_query_count'] );
    }
}
