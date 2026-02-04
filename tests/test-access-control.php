<?php
/**
 * Tests for SynthLoad_Router access control.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Access_Control
 *
 * Tests for access control functionality.
 */
class Test_SynthLoad_Access_Control extends WP_UnitTestCase {

    /**
     * Set up test fixtures.
     */
    public function set_up(): void {
        parent::set_up();
        // Clear request variables
        $_GET = array();
        unset( $_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] );
        // Delete settings for clean state
        delete_option( SynthLoad_Settings::OPTION_NAME );
    }

    /**
     * Tear down test fixtures.
     */
    public function tear_down(): void {
        $_GET = array();
        unset( $_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] );
        delete_option( SynthLoad_Settings::OPTION_NAME );
        parent::tear_down();
    }

    /**
     * Invoke the private validate_access method.
     *
     * @param SynthLoad_Router $router Router instance.
     * @return bool Access validation result.
     */
    private function invoke_validate_access( SynthLoad_Router $router ): bool {
        $reflection = new ReflectionClass( $router );
        $method     = $reflection->getMethod( 'validate_access' );
        $method->setAccessible( true );

        return $method->invoke( $router );
    }

    /**
     * Test that validate_access returns true when no token required.
     */
    public function test_validate_access_returns_true_when_no_token_required(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => '' ) );

        $router = new SynthLoad_Router();
        $this->assertTrue( $this->invoke_validate_access( $router ) );
    }

    /**
     * Test that validate_access returns true with correct query token.
     */
    public function test_validate_access_returns_true_with_correct_query_token(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => 'secret123' ) );
        $_GET['token'] = 'secret123';

        $router = new SynthLoad_Router();
        $this->assertTrue( $this->invoke_validate_access( $router ) );
    }

    /**
     * Test that validate_access returns true with correct header token.
     */
    public function test_validate_access_returns_true_with_correct_header_token(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => 'secret123' ) );
        $_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] = 'secret123';

        $router = new SynthLoad_Router();
        $this->assertTrue( $this->invoke_validate_access( $router ) );
    }

    /**
     * Test that validate_access returns false with wrong token.
     */
    public function test_validate_access_returns_false_with_wrong_token(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => 'secret123' ) );
        $_GET['token'] = 'wrongtoken';

        $router = new SynthLoad_Router();
        $this->assertFalse( $this->invoke_validate_access( $router ) );
    }

    /**
     * Test that validate_access returns false with no token when required.
     */
    public function test_validate_access_returns_false_with_no_token_when_required(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => 'secret123' ) );

        $router = new SynthLoad_Router();
        $this->assertFalse( $this->invoke_validate_access( $router ) );
    }

    /**
     * Test that validate_access prefers header over query.
     */
    public function test_validate_access_prefers_header_over_query(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => 'correcttoken' ) );
        $_GET['token']                     = 'wrongtoken';
        $_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] = 'correcttoken';

        $router = new SynthLoad_Router();
        $this->assertTrue( $this->invoke_validate_access( $router ) );
    }

    /**
     * Test that validate_access accepts query when header missing.
     */
    public function test_validate_access_accepts_query_when_header_missing(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => 'correcttoken' ) );
        $_GET['token'] = 'correcttoken';

        $router = new SynthLoad_Router();
        $this->assertTrue( $this->invoke_validate_access( $router ) );
    }

    /**
     * Test that validate_access is case-sensitive.
     */
    public function test_validate_access_is_case_sensitive(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => 'Secret123' ) );
        $_GET['token'] = 'secret123';

        $router = new SynthLoad_Router();
        $this->assertFalse( $this->invoke_validate_access( $router ) );
    }

    /**
     * Test that validate_access handles whitespace.
     */
    public function test_validate_access_handles_whitespace(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => 'secret123' ) );
        $_GET['token'] = ' secret123 ';

        $router = new SynthLoad_Router();
        // After sanitization, whitespace should be trimmed
        $this->assertTrue( $this->invoke_validate_access( $router ) );
    }

    /**
     * Test that validate_access handles empty header.
     */
    public function test_validate_access_handles_empty_header(): void {
        update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => 'secret123' ) );
        $_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] = '';
        $_GET['token']                     = 'secret123';

        $router = new SynthLoad_Router();
        // Empty header should fall back to query param
        $this->assertTrue( $this->invoke_validate_access( $router ) );
    }
}
