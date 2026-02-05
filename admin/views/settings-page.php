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

$limits      = SynthLoad_Settings::get_hard_limits();
$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'workload';
?>

<div class="wrap">
    <h1><?php esc_html_e( 'Synthetic Load Settings', 'wp-synthload' ); ?></h1>

    <?php settings_errors( 'synthload_settings' ); ?>

    <nav class="nav-tab-wrapper">
        <a href="?page=synthload-settings&tab=workload"
           class="nav-tab <?php echo 'workload' === $current_tab ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Workload', 'wp-synthload' ); ?>
        </a>
        <a href="?page=synthload-settings&tab=export"
           class="nav-tab <?php echo 'export' === $current_tab ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Export / Import', 'wp-synthload' ); ?>
        </a>
        <a href="?page=synthload-settings&tab=calculator"
           class="nav-tab <?php echo 'calculator' === $current_tab ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Calculator', 'wp-synthload' ); ?>
        </a>
        <a href="?page=synthload-settings&tab=settings"
           class="nav-tab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">
            <?php esc_html_e( 'Settings', 'wp-synthload' ); ?>
        </a>
    </nav>

    <form method="post" action="">
        <?php wp_nonce_field( 'synthload_save_settings', 'synthload_nonce' ); ?>
        <input type="hidden" name="synthload_tab" value="<?php echo esc_attr( $current_tab ); ?>" />

        <?php if ( 'workload' === $current_tab ) : ?>

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
                                <?php esc_html_e( 'Number of read queries per request.', 'wp-synthload' ); ?>
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
                                <?php esc_html_e( 'Each cycle: INSERT, UPDATE, DELETE (3 ops).', 'wp-synthload' ); ?>
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
                                   min="0"
                                   max="<?php echo esc_attr( $limits['max_cpu_iterations'] ); ?>"
                                   step="1"
                                   class="small-text" />
                            <span class="description" style="vertical-align: middle;">
                                <?php
                                printf(
                                    /* translators: %s: formatted iterations */
                                    esc_html__( '= %s hash operations', 'wp-synthload' ),
                                    '<strong id="synthload_cpu_display">' . number_format( $settings['cpu_iterations'] * 1000 ) . '</strong>'
                                );
                                ?>
                            </span>
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
                                <?php esc_html_e( 'Force direct database queries (skip object cache)', 'wp-synthload' ); ?>
                            </label>
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
            </div>

        <?php elseif ( 'export' === $current_tab ) : ?>

            <!-- Config Export/Import Section -->
            <div class="synthload-section">
                <h2><?php esc_html_e( 'Export Configuration', 'wp-synthload' ); ?></h2>
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e( 'Copy this configuration to replicate the same test parameters on another server.', 'wp-synthload' ); ?>
                </p>

                <?php
                // Build exportable config (workload settings only)
                $export_config = array(
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
                            <label for="synthload_export_config"><?php esc_html_e( 'Current Config', 'wp-synthload' ); ?></label>
                        </th>
                        <td>
                            <textarea id="synthload_export_config"
                                      readonly
                                      rows="6"
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
                </table>
            </div>

            <div class="synthload-section">
                <h2><?php esc_html_e( 'Import Configuration', 'wp-synthload' ); ?></h2>
                <p class="description" style="margin-bottom: 15px;">
                    <?php esc_html_e( 'Paste a configuration JSON to apply those settings.', 'wp-synthload' ); ?>
                </p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="synthload_import_config"><?php esc_html_e( 'Paste Config', 'wp-synthload' ); ?></label>
                        </th>
                        <td>
                            <textarea id="synthload_import_config"
                                      name="import_config"
                                      rows="6"
                                      class="large-text code"
                                      placeholder="<?php esc_attr_e( 'Paste configuration JSON here...', 'wp-synthload' ); ?>"></textarea>
                            <p class="description">
                                <?php esc_html_e( 'Paste JSON and click Save Settings to apply the configuration.', 'wp-synthload' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

        <?php elseif ( 'calculator' === $current_tab ) : ?>

            <!-- vCPU Calculator Section -->
            <div class="synthload-section">
                <h2><?php esc_html_e( 'vCPU Capacity Calculator', 'wp-synthload' ); ?></h2>
                <p class="description" style="margin-bottom: 20px;">
                    <?php esc_html_e( 'Estimate server resource requirements based on your expected traffic patterns.', 'wp-synthload' ); ?>
                </p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="calc_site_type"><?php esc_html_e( 'Site Type', 'wp-synthload' ); ?></label>
                        </th>
                        <td>
                            <select id="calc_site_type" class="regular-text">
                                <option value="dynamic"><?php esc_html_e( 'Dynamic (e-commerce, membership, forums)', 'wp-synthload' ); ?></option>
                                <option value="static"><?php esc_html_e( 'Static/Cached (blogs, landing pages, marketing)', 'wp-synthload' ); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Loads recommended assumptions for your site type.', 'wp-synthload' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Traffic Input', 'wp-synthload' ); ?></th>
                        <td>
                            <fieldset>
                                <label style="display: inline-block; margin-right: 20px;">
                                    <input type="radio" name="calc_input_type" value="visitors" checked />
                                    <?php esc_html_e( 'Monthly Visitors', 'wp-synthload' ); ?>
                                </label>
                                <label style="display: inline-block;">
                                    <input type="radio" name="calc_input_type" value="pageviews" />
                                    <?php esc_html_e( 'Monthly Page Views', 'wp-synthload' ); ?>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr id="calc_traffic_row">
                        <th scope="row">
                            <label for="calc_traffic_count" id="calc_traffic_label"><?php esc_html_e( 'Monthly Visitors', 'wp-synthload' ); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="calc_traffic_count"
                                   value="100000"
                                   min="0"
                                   max="100000000"
                                   step="1000"
                                   class="regular-text" />
                            <p class="description" id="calc_traffic_desc">
                                <?php esc_html_e( 'Expected unique visitors per month.', 'wp-synthload' ); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="calc_response_time"><?php esc_html_e( 'Response Time', 'wp-synthload' ); ?></label>
                        </th>
                        <td>
                            <input type="number"
                                   id="calc_response_time"
                                   value="2500"
                                   min="50"
                                   max="30000"
                                   step="50"
                                   class="small-text" />
                            <span class="description"><?php esc_html_e( 'milliseconds (average page load)', 'wp-synthload' ); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Traffic Shape', 'wp-synthload' ); ?></th>
                        <td>
                            <fieldset class="synthload-shape-options">
                                <label class="synthload-shape-option">
                                    <span class="synthload-shape-label">
                                        <input type="radio" name="calc_traffic_shape" value="uniform" checked />
                                        <span><?php esc_html_e( 'Uniform (spread evenly 24/7)', 'wp-synthload' ); ?></span>
                                    </span>
                                    <span class="synthload-shape-chart synthload-chart-uniform">
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                    </span>
                                </label>
                                <label class="synthload-shape-option">
                                    <span class="synthload-shape-label">
                                        <input type="radio" name="calc_traffic_shape" value="business" />
                                        <span><?php esc_html_e( 'Business Hours (8h workday, 22 days/month)', 'wp-synthload' ); ?></span>
                                    </span>
                                    <span class="synthload-shape-chart synthload-chart-business">
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                    </span>
                                </label>
                                <label class="synthload-shape-option">
                                    <span class="synthload-shape-label">
                                        <input type="radio" name="calc_traffic_shape" value="flash_sale" />
                                        <span><?php esc_html_e( 'Flash Sale (spike traffic in 1 hour)', 'wp-synthload' ); ?></span>
                                    </span>
                                    <span class="synthload-shape-chart synthload-chart-flash">
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                        <span class="synthload-shape-bar"></span>
                                    </span>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="calc_safety_factor"><?php esc_html_e( 'Safety Factor', 'wp-synthload' ); ?></label>
                        </th>
                        <td>
                            <select id="calc_safety_factor" class="regular-text">
                                <option value="1.0"><?php esc_html_e( 'None (1.0x)', 'wp-synthload' ); ?></option>
                                <option value="1.5" selected><?php esc_html_e( 'Standard (1.5x)', 'wp-synthload' ); ?></option>
                                <option value="2.0"><?php esc_html_e( 'Conservative (2.0x)', 'wp-synthload' ); ?></option>
                                <option value="3.0"><?php esc_html_e( 'High Availability (3.0x)', 'wp-synthload' ); ?></option>
                            </select>
                            <p class="description">
                                <?php esc_html_e( 'Headroom multiplier for unexpected traffic spikes.', 'wp-synthload' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Calculator Results Section -->
            <div class="synthload-section">
                <h2><?php esc_html_e( 'Results', 'wp-synthload' ); ?></h2>

                <div id="calc_results" class="synthload-calc-results">
                    <div class="synthload-calc-result-box">
                        <span class="synthload-calc-label"><?php esc_html_e( 'Peak RPS', 'wp-synthload' ); ?></span>
                        <span id="calc_result_rps" class="synthload-calc-value">-</span>
                        <span class="synthload-calc-unit"><?php esc_html_e( 'requests/sec', 'wp-synthload' ); ?></span>
                    </div>
                    <div class="synthload-calc-result-box">
                        <span class="synthload-calc-label"><?php esc_html_e( 'Concurrent', 'wp-synthload' ); ?></span>
                        <span id="calc_result_concurrent" class="synthload-calc-value">-</span>
                        <span class="synthload-calc-unit"><?php esc_html_e( 'connections', 'wp-synthload' ); ?></span>
                    </div>
                    <div class="synthload-calc-result-box synthload-calc-result-primary">
                        <span class="synthload-calc-label"><?php esc_html_e( 'Recommended', 'wp-synthload' ); ?></span>
                        <span id="calc_result_vcpus" class="synthload-calc-value">-</span>
                        <span class="synthload-calc-unit"><?php esc_html_e( 'vCPU(s)', 'wp-synthload' ); ?></span>
                    </div>
                </div>

                <div id="calc_breakdown" class="synthload-calc-breakdown" style="margin-top: 20px;">
                    <!-- Breakdown populated by JavaScript -->
                </div>
            </div>

            <!-- Advanced Assumptions Section -->
            <div class="synthload-section">
                <h2>
                    <label for="calc_advanced_toggle" style="cursor: pointer;">
                        <input type="checkbox" id="calc_advanced_toggle" style="margin-right: 8px;" />
                        <?php esc_html_e( 'Advanced Assumptions', 'wp-synthload' ); ?>
                    </label>
                </h2>

                <div id="calc_advanced_settings" style="display: none;">
                    <p class="description" style="margin-bottom: 15px;">
                        <?php esc_html_e( 'Customize the calculation assumptions. These values are saved when you click Save Settings.', 'wp-synthload' ); ?>
                    </p>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="calc_pages_per_visit"><?php esc_html_e( 'Pages per Visit', 'wp-synthload' ); ?></label>
                            </th>
                            <td>
                                <input type="number"
                                       id="calc_pages_per_visit"
                                       name="calc_pages_per_visit"
                                       value="<?php echo esc_attr( $settings['calc_pages_per_visit'] ); ?>"
                                       min="1"
                                       max="20"
                                       step="1"
                                       class="small-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Average pages viewed per visitor session.', 'wp-synthload' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="calc_cache_hit_rate"><?php esc_html_e( 'Cache Hit Rate', 'wp-synthload' ); ?></label>
                            </th>
                            <td>
                                <input type="number"
                                       id="calc_cache_hit_rate"
                                       name="calc_cache_hit_rate"
                                       value="<?php echo esc_attr( $settings['calc_cache_hit_rate'] ); ?>"
                                       min="0"
                                       max="99"
                                       step="1"
                                       class="small-text" /> %
                                <p class="description">
                                    <?php esc_html_e( 'Percentage of requests served from page cache (reduces server load).', 'wp-synthload' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="calc_connections_per_vcpu"><?php esc_html_e( 'Connections per vCPU', 'wp-synthload' ); ?></label>
                            </th>
                            <td>
                                <input type="number"
                                       id="calc_connections_per_vcpu"
                                       name="calc_connections_per_vcpu"
                                       value="<?php echo esc_attr( $settings['calc_connections_per_vcpu'] ); ?>"
                                       min="1"
                                       max="200"
                                       step="1"
                                       class="small-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Concurrent connections a single vCPU can handle. Higher for optimized hosting.', 'wp-synthload' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="calc_peak_to_average_ratio"><?php esc_html_e( 'Peak-to-Average Ratio', 'wp-synthload' ); ?></label>
                            </th>
                            <td>
                                <input type="number"
                                       id="calc_peak_to_average_ratio"
                                       name="calc_peak_to_average_ratio"
                                       value="<?php echo esc_attr( $settings['calc_peak_to_average_ratio'] ); ?>"
                                       min="1.0"
                                       max="10.0"
                                       step="0.1"
                                       class="small-text" />
                                <p class="description">
                                    <?php esc_html_e( 'Multiplier for business hours traffic peaks vs. average.', 'wp-synthload' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="calc_flash_spike_percent"><?php esc_html_e( 'Flash Spike Percentage', 'wp-synthload' ); ?></label>
                            </th>
                            <td>
                                <input type="number"
                                       id="calc_flash_spike_percent"
                                       name="calc_flash_spike_percent"
                                       value="<?php echo esc_attr( $settings['calc_flash_spike_percent'] ); ?>"
                                       min="1"
                                       max="50"
                                       step="1"
                                       class="small-text" /> %
                                <p class="description">
                                    <?php esc_html_e( 'Percentage of monthly traffic occurring during a flash sale spike hour.', 'wp-synthload' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

        <?php else : ?>

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

        <?php endif; ?>

        <?php submit_button( __( 'Save Settings', 'wp-synthload' ), 'primary', 'synthload_save_settings' ); ?>
    </form>
</div>
