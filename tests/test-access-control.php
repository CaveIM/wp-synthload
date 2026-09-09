<?php
/**
 * Tests for SynthLoad_Router access control.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Access_Control
 */
class Test_SynthLoad_Access_Control extends WP_UnitTestCase {

	/** Set up test fixtures. */
	public function set_up(): void {
		parent::set_up();
		$_GET = array();
		unset( $_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] );
		delete_option( SynthLoad_Settings::OPTION_NAME );
	}

	/** Tear down test fixtures. */
	public function tear_down(): void {
		$_GET = array();
		unset( $_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] );
		delete_option( SynthLoad_Settings::OPTION_NAME );
		parent::tear_down();
	}

	/**
	 * Invoke the private access validator.
	 *
	 * @return bool Access validation result.
	 */
	private function validate_access(): bool {
		$router     = new SynthLoad_Router();
		$reflection = new ReflectionClass( $router );
		$method     = $reflection->getMethod( 'validate_access' );
		$method->setAccessible( true );

		return $method->invoke( $router );
	}

	/**
	 * Return a valid synthetic token without embedding credential-like text.
	 *
	 * @return string Test token.
	 */
	private function get_valid_token(): string {
		return str_repeat( 'a', 32 );
	}

	/** An empty configured token never grants access. */
	public function test_empty_configured_token_denies_access(): void {
		update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => '' ) );

		$this->assertFalse( $this->validate_access() );
	}

	/** The header grants access when it matches exactly. */
	public function test_correct_header_token_grants_access(): void {
		$token = $this->get_valid_token();
		update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => $token ) );
		$_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] = $token;

		$this->assertTrue( $this->validate_access() );
	}

	/** A wrong header token is rejected. */
	public function test_wrong_header_token_denies_access(): void {
		update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => $this->get_valid_token() ) );
		$_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] = 'wrongtoken';

		$this->assertFalse( $this->validate_access() );
	}

	/** A missing header is rejected. */
	public function test_missing_header_denies_access(): void {
		update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => $this->get_valid_token() ) );

		$this->assertFalse( $this->validate_access() );
	}

	/** Query-string credentials are never accepted. */
	public function test_query_token_is_rejected(): void {
		$token = $this->get_valid_token();
		update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => $token ) );
		$_GET['token'] = $token;

		$this->assertFalse( $this->validate_access() );
	}

	/** Header token comparisons are case-sensitive. */
	public function test_header_token_is_case_sensitive(): void {
		$token = $this->get_valid_token();
		update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => strtoupper( $token ) ) );
		$_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] = $token;

		$this->assertFalse( $this->validate_access() );
	}

	/** WordPress input sanitization trims surrounding whitespace. */
	public function test_header_token_handles_whitespace(): void {
		$token = $this->get_valid_token();
		update_option( SynthLoad_Settings::OPTION_NAME, array( 'access_token' => $token ) );
		$_SERVER['HTTP_X_SYNTHLOAD_TOKEN'] = ' ' . $token . ' ';

		$this->assertTrue( $this->validate_access() );
	}
}
