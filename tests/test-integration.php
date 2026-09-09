<?php
/**
 * Integration tests for WP Synthetic Load.
 *
 * @package WP_SynthLoad
 */

/**
 * Class Test_SynthLoad_Integration
 */
class Test_SynthLoad_Integration extends WP_UnitTestCase {

	/** @var SynthLoad_Db */
	private SynthLoad_Db $db;

	/** Set up test fixtures. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$this->db = new SynthLoad_Db( $wpdb );
		$this->set_permalink_structure( '/%postname%/' );
		SynthLoad_Db::create_table();
		delete_option( SynthLoad_Settings::OPTION_NAME );
		add_option( SynthLoad_Settings::OPTION_NAME, SynthLoad_Settings::get_defaults() );
		SynthLoad_Router::register_rewrites();
		flush_rewrite_rules();
	}

	/** Tear down test fixtures. */
	public function tear_down(): void {
		SynthLoad_Db::drop_table();
		delete_option( SynthLoad_Settings::OPTION_NAME );
		delete_option( 'synthload_schema_version' );
		flush_rewrite_rules();
		parent::tear_down();
	}

	/** The default workload route is registered while remaining disabled. */
	public function test_default_endpoint_route_is_registered_but_disabled(): void {
		$this->go_to( home_url( '/synthload/' ) );

		$this->assertSame( '1', get_query_var( 'synthload_request' ) );
		$this->assertFalse( SynthLoad_Settings::get( 'endpoint_enabled' ) );
	}

	/** Loader.io verification routes use the configured token. */
	public function test_loaderio_endpoint_sets_query_var(): void {
		SynthLoad_Settings::update( array( 'loaderio_token' => 'abc123xyz' ) );
		SynthLoad_Router::register_rewrites();
		flush_rewrite_rules();

		$this->go_to( home_url( '/loaderio-abc123xyz.txt' ) );

		$this->assertSame( 'abc123xyz', get_query_var( 'loaderio_verify' ) );
	}

	/** A complete workload returns only safe metrics and leaves no cycle rows. */
	public function test_full_workload_lifecycle(): void {
		$settings = array_merge(
			SynthLoad_Settings::get_defaults(),
			array(
				'read_query_count' => 5,
				'write_op_count'    => 3,
				'cpu_iterations'    => 0,
			)
		);

		for ( $i = 0; $i < 20; $i++ ) {
			$this->db->insert_event( array( 'payload' => '{}' ) );
		}
		$initial_count = $this->db->count_events();
		$result        = ( new SynthLoad_Workload( $this->db, $settings ) )->execute();

		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( 5, $result['execution']['db_reads'] );
		$this->assertSame( 9, $result['execution']['db_writes'] );
		$this->assertSame( $initial_count, $this->db->count_events() );
		$this->assertArrayNotHasKey( 'operations', $result );
		$this->assertArrayNotHasKey( 'server', $result );
	}

	/** Updating the endpoint slug changes the registered route. */
	public function test_settings_changes_take_effect(): void {
		SynthLoad_Settings::update( array( 'endpoint_slug' => 'new-slug' ) );
		SynthLoad_Router::register_rewrites();
		flush_rewrite_rules();

		global $wp_rewrite;
		$this->assertArrayHasKey( '^new-slug/?$', $wp_rewrite->wp_rewrite_rules() );
	}
}
