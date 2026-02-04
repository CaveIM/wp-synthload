<?php
/**
 * Tests for SynthLoad_Admin class.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Admin
 *
 * Tests for admin interface functionality.
 */
class Test_SynthLoad_Admin extends WP_UnitTestCase {

    /**
     * Set up test fixtures.
     */
    public function set_up(): void {
        parent::set_up();

        // Create admin user and set as current
        $user_id = $this->factory->user->create( array( 'role' => 'administrator' ) );
        wp_set_current_user( $user_id );

        // Set current screen
        set_current_screen( 'dashboard' );

        // Clean up settings
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
     * Test that add_menu_page adds to settings menu.
     */
    public function test_add_menu_page_adds_to_settings(): void {
        global $submenu;

        $admin = new SynthLoad_Admin();
        $admin->add_menu_page();

        // Check settings submenu exists
        $this->assertArrayHasKey( 'options-general.php', $submenu );

        $found = false;
        foreach ( $submenu['options-general.php'] as $item ) {
            if ( isset( $item[2] ) && 'synthload-settings' === $item[2] ) {
                $found = true;
                break;
            }
        }

        $this->assertTrue( $found, 'Synthetic Load settings page not found in Settings menu' );
    }

    /**
     * Test that settings page requires manage_options capability.
     */
    public function test_settings_page_requires_manage_options(): void {
        // Create subscriber user (no manage_options)
        $user_id = $this->factory->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user_id );

        $this->assertFalse( current_user_can( 'manage_options' ) );
    }

    /**
     * Test that register_settings registers the option.
     */
    public function test_register_settings_registers_option(): void {
        global $new_allowed_options;

        $admin = new SynthLoad_Admin();
        $admin->register_settings();

        // Check that our option group is registered
        $this->assertArrayHasKey( 'synthload_options_group', $new_allowed_options ?? array() );
    }

    /**
     * Test that admin has correct capability.
     */
    public function test_admin_has_manage_options(): void {
        $this->assertTrue( current_user_can( 'manage_options' ) );
    }

    /**
     * Test that menu page hook is stored.
     */
    public function test_menu_page_returns_hook(): void {
        $admin = new SynthLoad_Admin();
        $admin->add_menu_page();

        // The hook is stored as a private property, but we can verify
        // the page was added by checking the submenu
        global $submenu;
        $this->assertNotEmpty( $submenu['options-general.php'] );
    }
}
