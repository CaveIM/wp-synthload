<?php
/**
 * Settings page template for WP Synthetic Load plugin.
 *
 * @package WP_SynthLoad
 * @var array $settings Current settings array.
 */

// Security check - prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$limits = SynthLoad_Settings::get_hard_limits();
?>

<div class="wrap">
    <h1><?php esc_html_e( 'Synthetic Load Settings', 'wp-synthload' ); ?></h1>

    <?php settings_errors( 'synthload_settings' ); ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'synthload_save_settings', 'synthload_nonce' ); ?>

        <!-- Loader.io Verification Section -->
        <div class="synthload-section">
            <h2><?php esc_html_e( 'Loader.io Verification', 'wp-synthload' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="synthload_loaderio_token"><?php esc_html_e( 'Verification Token', 'wp-synthload' ); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="synthload_loaderio_token"
                               name="loaderio_token"
                               value="<?php echo esc_attr( $settings['loaderio_token'] ); ?>"
                               class="regular-text"
                               placeholder="abc123xyz" />
                        <p class="description">
                            <?php esc_html_e( 'Enter the alphanumeric token from Loader.io (without the "loaderio-" prefix).', 'wp-synthload' ); ?>
                        </p>
                        <?php if ( ! empty( $settings['loaderio_token'] ) ) :
                            $token_id    = SynthLoad_Settings::extract_token_id( $settings['loaderio_token'] );
                            $file_exists = SynthLoad_Admin::loaderio_file_exists( $token_id );
                        ?>
                            <p class="synthload-url-preview">
                                <strong><?php esc_html_e( 'Verification URL:', 'wp-synthload' ); ?></strong><br>
                                <?php echo esc_url( home_url( '/loaderio-' . $token_id . '.txt' ) ); ?>
                            </p>
                            <p class="synthload-file-status" style="margin-top: 8px;">
                                <?php if ( $file_exists ) : ?>
                                    <span style="color: #00a32a;">&#10003;</span>
                                    <?php esc_html_e( 'Verification file exists and is ready.', 'wp-synthload' ); ?>
                                <?php else : ?>
                                    <span style="color: #d63638;">&#10007;</span>
                                    <?php esc_html_e( 'Verification file not found. Save settings to create it.', 'wp-synthload' ); ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Endpoint Configuration Section -->
        <div class="synthload-section">
            <h2><?php esc_html_e( 'Endpoint Configuration', 'wp-synthload' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="synthload_endpoint_slug"><?php esc_html_e( 'Endpoint Slug', 'wp-synthload' ); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="synthload_endpoint_slug"
                               name="endpoint_slug"
                               value="<?php echo esc_attr( $settings['endpoint_slug'] ); ?>"
                               class="regular-text"
                               pattern="[a-z0-9\-]+"
                               required />
                        <p class="description">
                            <?php esc_html_e( 'URL path for the load endpoint. Use lowercase letters, numbers, and hyphens only.', 'wp-synthload' ); ?>
                        </p>
                        <p class="synthload-url-preview">
                            <strong><?php esc_html_e( 'Endpoint URL:', 'wp-synthload' ); ?></strong><br>
                            <span id="synthload_url_preview"><?php echo esc_url( home_url( '/' . $settings['endpoint_slug'] . '/' ) ); ?></span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable Endpoint', 'wp-synthload' ); ?></th>
                    <td>
                        <label for="synthload_endpoint_enabled">
                            <input type="checkbox"
                                   id="synthload_endpoint_enabled"
                                   name="endpoint_enabled"
                                   value="1"
                                   <?php checked( $settings['endpoint_enabled'] ); ?> />
                            <?php esc_html_e( 'Enable synthetic load endpoint', 'wp-synthload' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="synthload_access_token"><?php esc_html_e( 'Access Token', 'wp-synthload' ); ?></label>
                    </th>
                    <td>
                        <input type="text"
                               id="synthload_access_token"
                               name="access_token"
                               value="<?php echo esc_attr( $settings['access_token'] ); ?>"
                               class="regular-text" />
                        <p class="description">
                            <?php esc_html_e( 'Optional. If set, requests must include ?token=xxx or X-SynthLoad-Token header.', 'wp-synthload' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Workload Profile Section -->
        <div class="synthload-section">
            <h2><?php esc_html_e( 'Workload Profile', 'wp-synthload' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="synthload_profile"><?php esc_html_e( 'Profile', 'wp-synthload' ); ?></label>
                    </th>
                    <td>
                        <select id="synthload_profile" name="profile">
                            <option value="general" <?php selected( $settings['profile'], 'general' ); ?>>
                                <?php esc_html_e( 'General WP Page Load', 'wp-synthload' ); ?>
                            </option>
                            <option value="membership" <?php selected( $settings['profile'], 'membership' ); ?>>
                                <?php esc_html_e( 'Membership-style (heavier reads)', 'wp-synthload' ); ?>
                            </option>
                            <option value="ecommerce" <?php selected( $settings['profile'], 'ecommerce' ); ?>>
                                <?php esc_html_e( 'E-commerce-style (reads + writes)', 'wp-synthload' ); ?>
                            </option>
                        </select>
                        <button type="button" id="synthload_load_preset" class="button">
                            <?php esc_html_e( 'Load Profile Defaults', 'wp-synthload' ); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e( 'Select a preset profile, then click "Load Profile Defaults" to populate parameters.', 'wp-synthload' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Workload Parameters Section -->
        <div class="synthload-section">
            <h2><?php esc_html_e( 'Workload Parameters', 'wp-synthload' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="synthload_read_query_count"><?php esc_html_e( 'Database Reads', 'wp-synthload' ); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="synthload_read_query_count"
                               name="read_query_count"
                               value="<?php echo esc_attr( $settings['read_query_count'] ); ?>"
                               min="0"
                               max="<?php echo esc_attr( $limits['max_read_query_count'] ); ?>"
                               step="1"
                               class="small-text" />
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %d: maximum read queries */
                                esc_html__( 'Number of read queries per request (max: %d).', 'wp-synthload' ),
                                $limits['max_read_query_count']
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="synthload_write_op_count"><?php esc_html_e( 'Write Cycles', 'wp-synthload' ); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="synthload_write_op_count"
                               name="write_op_count"
                               value="<?php echo esc_attr( $settings['write_op_count'] ); ?>"
                               min="0"
                               max="<?php echo esc_attr( $limits['max_write_op_count'] ); ?>"
                               step="1"
                               class="small-text" />
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %d: maximum write cycles */
                                esc_html__( 'Each cycle: INSERT → UPDATE → DELETE (3 ops). Max: %d cycles.', 'wp-synthload' ),
                                $limits['max_write_op_count']
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="synthload_cpu_iterations"><?php esc_html_e( 'CPU Iterations (thousands)', 'wp-synthload' ); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="synthload_cpu_iterations"
                               name="cpu_iterations"
                               value="<?php echo esc_attr( $settings['cpu_iterations'] ); ?>"
                               min="1"
                               max="<?php echo esc_attr( $limits['max_cpu_iterations'] ); ?>"
                               step="1"
                               class="small-text" />
                        <span class="description" style="vertical-align: middle;">
                            <?php
                            printf(
                                /* translators: %s: formatted iterations */
                                esc_html__( '× 1,000 = %s hash operations', 'wp-synthload' ),
                                '<strong id="synthload_cpu_display">' . number_format( $settings['cpu_iterations'] * 1000 ) . '</strong>'
                            );
                            ?>
                        </span>
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %s: maximum CPU iterations in thousands */
                                esc_html__( 'Higher values = more CPU work. Max: %s (10 million).', 'wp-synthload' ),
                                number_format( $limits['max_cpu_iterations'] )
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Cache Bypass', 'wp-synthload' ); ?></th>
                    <td>
                        <label for="synthload_bypass_object_cache">
                            <input type="checkbox"
                                   id="synthload_bypass_object_cache"
                                   name="bypass_object_cache"
                                   value="1"
                                   <?php checked( $settings['bypass_object_cache'] ); ?> />
                            <?php esc_html_e( 'Force direct database queries', 'wp-synthload' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Skip object cache (Redis/Memcached) and query the database directly. Simulates uncached traffic.', 'wp-synthload' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Test Workload Section -->
        <div class="synthload-section">
            <h2><?php esc_html_e( 'Test Workload', 'wp-synthload' ); ?></h2>
            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e( 'Run a single workload request using the current form settings (without saving).', 'wp-synthload' ); ?>
            </p>
            <button type="button" id="synthload_test_btn" class="button button-secondary">
                <?php esc_html_e( 'Run Test', 'wp-synthload' ); ?>
            </button>
            <span id="synthload_test_spinner" class="spinner" style="float: none; margin-top: 0;"></span>
            <div id="synthload_test_results" style="display: none; margin-top: 15px;">
                <table class="widefat striped" style="max-width: 400px;">
                    <tbody>
                        <tr>
                            <td><strong><?php esc_html_e( 'Duration', 'wp-synthload' ); ?></strong></td>
                            <td id="synthload_result_duration">-</td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Database Reads', 'wp-synthload' ); ?></strong></td>
                            <td id="synthload_result_reads">-</td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'Database Writes', 'wp-synthload' ); ?></strong></td>
                            <td id="synthload_result_writes">-</td>
                        </tr>
                        <tr>
                            <td><strong><?php esc_html_e( 'CPU Iterations', 'wp-synthload' ); ?></strong></td>
                            <td id="synthload_result_cpu">-</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div id="synthload_test_error" class="notice notice-error inline" style="display: none; margin-top: 15px;">
                <p id="synthload_test_error_msg"></p>
            </div>
            <?php wp_nonce_field( 'synthload_test_workload', 'synthload_test_nonce' ); ?>
        </div>

        <!-- Debug Settings Section -->
        <div class="synthload-section">
            <h2><?php esc_html_e( 'Debug Settings', 'wp-synthload' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Debug Logging', 'wp-synthload' ); ?></th>
                    <td>
                        <label for="synthload_debug_logging_enabled">
                            <input type="checkbox"
                                   id="synthload_debug_logging_enabled"
                                   name="debug_logging_enabled"
                                   value="1"
                                   <?php checked( $settings['debug_logging_enabled'] ); ?> />
                            <?php esc_html_e( 'Enable debug logging', 'wp-synthload' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Logs workload events to PHP error log. Useful for troubleshooting.', 'wp-synthload' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Config Export/Import Section -->
        <div class="synthload-section">
            <h2><?php esc_html_e( 'Configuration Export/Import', 'wp-synthload' ); ?></h2>
            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e( 'Copy this configuration to replicate the same test parameters on another server.', 'wp-synthload' ); ?>
            </p>

            <?php
            // Build exportable config (workload settings only)
            $export_config = array(
                'profile'             => $settings['profile'],
                'read_query_count'    => $settings['read_query_count'],
                'write_op_count'      => $settings['write_op_count'],
                'cpu_iterations'      => $settings['cpu_iterations'],
                'bypass_object_cache' => $settings['bypass_object_cache'],
            );
            $export_json = wp_json_encode( $export_config, JSON_PRETTY_PRINT );
            ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="synthload_export_config"><?php esc_html_e( 'Export Config', 'wp-synthload' ); ?></label>
                    </th>
                    <td>
                        <textarea id="synthload_export_config"
                                  readonly
                                  rows="8"
                                  class="large-text code"
                                  onclick="this.select();"><?php echo esc_textarea( $export_json ); ?></textarea>
                        <p class="description">
                            <button type="button" id="synthload_copy_config" class="button button-small">
                                <?php esc_html_e( 'Copy to Clipboard', 'wp-synthload' ); ?>
                            </button>
                            <span id="synthload_copy_status" style="margin-left: 10px; color: #00a32a; display: none;">
                                <?php esc_html_e( 'Copied!', 'wp-synthload' ); ?>
                            </span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="synthload_import_config"><?php esc_html_e( 'Import Config', 'wp-synthload' ); ?></label>
                    </th>
                    <td>
                        <textarea id="synthload_import_config"
                                  rows="8"
                                  class="large-text code"
                                  placeholder="<?php esc_attr_e( 'Paste configuration JSON here...', 'wp-synthload' ); ?>"></textarea>
                        <p class="description">
                            <button type="button" id="synthload_import_btn" class="button button-small">
                                <?php esc_html_e( 'Apply to Form', 'wp-synthload' ); ?>
                            </button>
                            <span id="synthload_import_status" style="margin-left: 10px; display: none;"></span>
                        </p>
                        <p class="description">
                            <?php esc_html_e( 'Paste a configuration from another server to populate the form. Then save to apply.', 'wp-synthload' ); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Safety Limits Info -->
        <div class="synthload-section">
            <h2><?php esc_html_e( 'Safety Limits', 'wp-synthload' ); ?></h2>
            <p class="synthload-limits-info">
                <?php esc_html_e( 'The following hard limits are enforced to protect your server:', 'wp-synthload' ); ?>
            </p>
            <ul class="synthload-limits-info">
                <li>
                    <?php
                    printf(
                        /* translators: %s: max CPU iterations in thousands, %s: actual iterations */
                        esc_html__( 'Maximum CPU iterations: %s thousand (%s actual)', 'wp-synthload' ),
                        number_format( $limits['max_cpu_iterations'] ),
                        number_format( $limits['max_cpu_iterations'] * 1000 )
                    );
                    ?>
                </li>
                <li>
                    <?php
                    printf(
                        /* translators: %d: max reads */
                        esc_html__( 'Maximum reads per request: %d', 'wp-synthload' ),
                        $limits['max_read_query_count']
                    );
                    ?>
                </li>
                <li>
                    <?php
                    printf(
                        /* translators: %d: max writes */
                        esc_html__( 'Maximum writes per request: %d', 'wp-synthload' ),
                        $limits['max_write_op_count']
                    );
                    ?>
                </li>
                <li>
                    <?php
                    printf(
                        /* translators: %d: max rows */
                        esc_html__( 'Maximum events table rows: %d', 'wp-synthload' ),
                        number_format( $limits['max_rows_to_keep'] )
                    );
                    ?>
                </li>
            </ul>
        </div>

        <?php submit_button( __( 'Save Settings', 'wp-synthload' ), 'primary', 'synthload_save_settings' ); ?>
    </form>
</div>
