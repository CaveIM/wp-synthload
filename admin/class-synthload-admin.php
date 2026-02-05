<?php
/**
 * Admin settings page controller for WP Synthetic Load plugin.
 *
 * @package WP_SynthLoad
 */

// Security check - prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class SynthLoad_Admin
 *
 * Handles admin UI, settings page, and form processing.
 */
class SynthLoad_Admin {

    /**
     * Settings page hook suffix.
     *
     * @var string
     */
    private string $settings_page_hook = '';

    /**
     * Add menu page under Settings.
     */
    public function add_menu_page(): void {
        $this->settings_page_hook = add_options_page(
            __( 'Synthetic Load Settings', 'wp-synthload' ),
            __( 'Synthetic Load', 'wp-synthload' ),
            'manage_options',
            'synthload-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page(): void {
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-synthload' ) );
        }

        // Retrieve settings errors from transient (set during form submission redirect)
        $transient_errors = get_transient( 'settings_errors' );
        if ( $transient_errors ) {
            global $wp_settings_errors;
            $wp_settings_errors = array_merge( (array) $wp_settings_errors, $transient_errors );
            delete_transient( 'settings_errors' );
        }

        // Get current settings
        $settings = SynthLoad_Settings::get_all();

        // Include the view
        include SYNTHLOAD_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Process form submission early (on admin_init) before output starts.
     */
    public function process_form_submission(): void {
        // Only process on our settings page
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! isset( $_GET['page'] ) || 'synthload-settings' !== $_GET['page'] ) {
            return;
        }

        // Only process POST requests with our submit button
        if ( ! isset( $_POST['synthload_save_settings'] ) ) {
            return;
        }

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $this->handle_form_submit();
    }

    /**
     * Handle form submission.
     */
    public function handle_form_submit(): void {
        // Verify nonce
        if ( ! isset( $_POST['synthload_nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['synthload_nonce'] ) ), 'synthload_save_settings' ) ) {
            add_settings_error(
                'synthload_settings',
                'invalid_nonce',
                __( 'Security check failed. Please try again.', 'wp-synthload' ),
                'error'
            );
            return;
        }

        // Get the old settings for comparison
        $old_settings    = SynthLoad_Settings::get_all();
        $old_slug        = $old_settings['endpoint_slug'];
        $old_token_id    = SynthLoad_Settings::extract_token_id( $old_settings['loaderio_token'] );

        // Collect form data - start with current settings
        $new_settings = array();

        // Check for import config on the export tab
        $current_tab = isset( $_POST['synthload_tab'] ) ? sanitize_key( $_POST['synthload_tab'] ) : 'workload';
        if ( 'export' === $current_tab && ! empty( $_POST['import_config'] ) ) {
            $import_json = sanitize_textarea_field( wp_unslash( $_POST['import_config'] ) );
            $import_data = json_decode( $import_json, true );

            if ( json_last_error() === JSON_ERROR_NONE && is_array( $import_data ) ) {
                // Apply imported values
                if ( isset( $import_data['read_query_count'] ) ) {
                    $new_settings['read_query_count'] = (int) $import_data['read_query_count'];
                }
                if ( isset( $import_data['write_op_count'] ) ) {
                    $new_settings['write_op_count'] = (int) $import_data['write_op_count'];
                }
                if ( isset( $import_data['cpu_iterations'] ) ) {
                    $new_settings['cpu_iterations'] = (int) $import_data['cpu_iterations'];
                }
                if ( isset( $import_data['bypass_object_cache'] ) ) {
                    $new_settings['bypass_object_cache'] = (bool) $import_data['bypass_object_cache'];
                }
            } else {
                add_settings_error(
                    'synthload_settings',
                    'invalid_json',
                    __( 'Invalid JSON format in import configuration.', 'wp-synthload' ),
                    'error'
                );
            }
        }

        // Collect form data from other tabs (only if explicitly set in POST)
        if ( isset( $_POST['loaderio_token'] ) ) {
            $new_settings['loaderio_token'] = sanitize_text_field( wp_unslash( $_POST['loaderio_token'] ) );
        }
        if ( isset( $_POST['endpoint_slug'] ) ) {
            $new_settings['endpoint_slug'] = sanitize_text_field( wp_unslash( $_POST['endpoint_slug'] ) );
        }
        if ( 'settings' === $current_tab ) {
            $new_settings['endpoint_enabled'] = isset( $_POST['endpoint_enabled'] );
            $new_settings['debug_logging_enabled'] = isset( $_POST['debug_logging_enabled'] );
        }
        if ( isset( $_POST['access_token'] ) ) {
            $new_settings['access_token'] = sanitize_text_field( wp_unslash( $_POST['access_token'] ) );
        }
        if ( isset( $_POST['read_query_count'] ) ) {
            $new_settings['read_query_count'] = (int) $_POST['read_query_count'];
        }
        if ( isset( $_POST['write_op_count'] ) ) {
            $new_settings['write_op_count'] = (int) $_POST['write_op_count'];
        }
        if ( isset( $_POST['cpu_iterations'] ) ) {
            $new_settings['cpu_iterations'] = (int) $_POST['cpu_iterations'];
        }
        if ( 'workload' === $current_tab ) {
            $new_settings['bypass_object_cache'] = isset( $_POST['bypass_object_cache'] );
        }

        // Calculator assumptions (from calculator tab).
        if ( 'calculator' === $current_tab ) {
            if ( isset( $_POST['calc_pages_per_visit'] ) ) {
                $new_settings['calc_pages_per_visit'] = (int) $_POST['calc_pages_per_visit'];
            }
            if ( isset( $_POST['calc_cache_hit_rate'] ) ) {
                $new_settings['calc_cache_hit_rate'] = (int) $_POST['calc_cache_hit_rate'];
            }
            if ( isset( $_POST['calc_connections_per_vcpu'] ) ) {
                $new_settings['calc_connections_per_vcpu'] = (int) $_POST['calc_connections_per_vcpu'];
            }
            if ( isset( $_POST['calc_peak_to_average_ratio'] ) ) {
                $new_settings['calc_peak_to_average_ratio'] = (float) $_POST['calc_peak_to_average_ratio'];
            }
            if ( isset( $_POST['calc_flash_spike_percent'] ) ) {
                $new_settings['calc_flash_spike_percent'] = (int) $_POST['calc_flash_spike_percent'];
            }
        }

        // Update settings (returns false if no changes or error)
        SynthLoad_Settings::update( $new_settings );

        // Get updated settings for comparison
        $current_settings = SynthLoad_Settings::get_all();

        // Check if slug changed - flush rewrite rules if so
        if ( $old_slug !== $current_settings['endpoint_slug'] ) {
            SynthLoad_Router::register_rewrites();
            flush_rewrite_rules();
        }

        // Handle Loader.io verification file
        $new_token_id = SynthLoad_Settings::extract_token_id( $current_settings['loaderio_token'] );
        $file_message = '';

        // Delete old file if token changed
        if ( $old_token_id !== $new_token_id && ! empty( $old_token_id ) ) {
            self::delete_loaderio_file( $old_token_id );
        }

        // Write new file only if token exists and file doesn't already exist
        if ( ! empty( $new_token_id ) ) {
            if ( self::loaderio_file_exists( $new_token_id ) ) {
                // File already exists, no message needed.
            } elseif ( self::write_loaderio_file( $new_token_id ) ) {
                $file_message = ' ' . __( 'Verification file created.', 'wp-synthload' );
            } else {
                $file_message = ' ' . __( 'Warning: Could not create verification file. Check file permissions.', 'wp-synthload' );
            }
        }

        add_settings_error(
            'synthload_settings',
            'settings_updated',
            __( 'Settings saved.', 'wp-synthload' ) . $file_message,
            'success'
        );

        // Redirect back to the same tab
        $tab = isset( $_POST['synthload_tab'] ) ? sanitize_key( $_POST['synthload_tab'] ) : 'workload';
        $redirect_url = add_query_arg(
            array(
                'page' => 'synthload-settings',
                'tab'  => $tab,
            ),
            admin_url( 'options-general.php' )
        );

        // Store settings errors in transient so they persist through redirect
        set_transient( 'settings_errors', get_settings_errors(), 30 );

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( string $hook ): void {
        if ( $hook !== $this->settings_page_hook ) {
            return;
        }

        // Inline CSS for styling
        $css = '
            .nav-tab-wrapper {
                margin-bottom: 20px;
            }
            .synthload-url-preview {
                background: #f0f0f1;
                padding: 10px;
                border-radius: 4px;
                font-family: monospace;
                margin-top: 5px;
            }
            .synthload-section {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                margin-bottom: 20px;
            }
            .synthload-section h2 {
                margin-top: 0;
                padding-bottom: 10px;
                border-bottom: 1px solid #c3c4c7;
            }
            #synthload_test_results td {
                padding: 8px 12px;
            }
            #synthload_test_results td:last-child {
                font-family: monospace;
                text-align: right;
            }
            /* Calculator styles */
            .synthload-calc-results {
                display: flex;
                gap: 20px;
                flex-wrap: wrap;
            }
            .synthload-calc-result-box {
                background: #f0f0f1;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                text-align: center;
                min-width: 140px;
                flex: 1;
            }
            .synthload-calc-result-primary {
                background: #dff0d8;
                border-color: #3c763d;
            }
            .synthload-calc-label {
                display: block;
                font-size: 12px;
                color: #646970;
                margin-bottom: 8px;
                text-transform: uppercase;
            }
            .synthload-calc-value {
                display: block;
                font-size: 32px;
                font-weight: 600;
                color: #1d2327;
                line-height: 1.2;
            }
            .synthload-calc-result-primary .synthload-calc-value {
                color: #3c763d;
            }
            .synthload-calc-unit {
                display: block;
                font-size: 12px;
                color: #646970;
                margin-top: 4px;
            }
            .synthload-calc-breakdown {
                background: #f9f9f9;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                padding: 15px 20px;
                font-size: 13px;
                line-height: 1.8;
            }
            .synthload-calc-breakdown p {
                margin: 0 0 12px 0;
            }
            .synthload-calc-breakdown p:last-child {
                margin-bottom: 0;
            }
            .synthload-calc-breakdown strong {
                color: #1d2327;
            }
            .synthload-calc-breakdown code {
                background: #fff;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 12px;
            }
            /* Traffic shape mini charts */
            .synthload-shape-options {
                display: flex;
                flex-wrap: wrap;
                gap: 12px;
            }
            .synthload-shape-option {
                display: flex;
                flex-direction: column;
                align-items: flex-start;
                gap: 8px;
                padding: 12px 16px;
                background: #f9f9f9;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                cursor: pointer;
                transition: border-color 0.2s ease, background 0.2s ease;
                width: calc(50% - 6px);
                box-sizing: border-box;
            }
            .synthload-shape-option:hover {
                border-color: #c3c4c7;
                background: #f6f7f7;
            }
            .synthload-shape-option:has(input:checked) {
                border-color: #2271b1;
                background: #f0f6fc;
            }
            .synthload-shape-option input[type="radio"] {
                margin: 0;
            }
            .synthload-shape-label {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .synthload-shape-chart {
                display: flex;
                align-items: flex-end;
                gap: 2px;
                height: 28px;
                padding: 4px 6px;
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                min-width: 70px;
                margin-top: 6px;
            }
            .synthload-shape-bar {
                width: 4px;
                background: #2271b1;
                border-radius: 1px;
                transition: height 0.2s ease;
            }
            .synthload-shape-option input:checked ~ .synthload-shape-chart .synthload-shape-bar {
                background: #135e96;
            }
            /* Uniform: all bars same height */
            .synthload-chart-uniform .synthload-shape-bar {
                height: 12px;
            }
            /* Business hours: middle bars taller */
            .synthload-chart-business .synthload-shape-bar:nth-child(1),
            .synthload-chart-business .synthload-shape-bar:nth-child(2),
            .synthload-chart-business .synthload-shape-bar:nth-child(11),
            .synthload-chart-business .synthload-shape-bar:nth-child(12) {
                height: 4px;
            }
            .synthload-chart-business .synthload-shape-bar:nth-child(3),
            .synthload-chart-business .synthload-shape-bar:nth-child(10) {
                height: 8px;
            }
            .synthload-chart-business .synthload-shape-bar:nth-child(4),
            .synthload-chart-business .synthload-shape-bar:nth-child(5),
            .synthload-chart-business .synthload-shape-bar:nth-child(6),
            .synthload-chart-business .synthload-shape-bar:nth-child(7),
            .synthload-chart-business .synthload-shape-bar:nth-child(8),
            .synthload-chart-business .synthload-shape-bar:nth-child(9) {
                height: 20px;
            }
            /* Flash sale: one spike */
            .synthload-chart-flash .synthload-shape-bar {
                height: 6px;
            }
            .synthload-chart-flash .synthload-shape-bar:nth-child(8) {
                height: 22px;
                background: #d63638;
            }
            .synthload-shape-option input:checked ~ .synthload-chart-flash .synthload-shape-bar:nth-child(8) {
                background: #b32d2e;
            }
        ';

        wp_add_inline_style( 'common', $css );

        $settings = SynthLoad_Settings::get_all();
        $endpoint_url = home_url( '/' . $settings['endpoint_slug'] . '/' );

        $script = "
        (function($) {
            var endpointUrl = '" . esc_js( $endpoint_url ) . "';
            var accessToken = '" . esc_js( $settings['access_token'] ) . "';

            $(document).ready(function() {
                // Update URL preview when slug changes
                var slugInput = document.getElementById('synthload_endpoint_slug');
                var urlPreview = document.getElementById('synthload_url_preview');

                if (slugInput && urlPreview) {
                    slugInput.addEventListener('input', function() {
                        var baseUrl = '" . esc_js( home_url( '/' ) ) . "';
                        urlPreview.textContent = baseUrl + this.value + '/';
                    });
                }

                // Update CPU iterations display when value changes
                var cpuInput = document.getElementById('synthload_cpu_iterations');
                var cpuDisplay = document.getElementById('synthload_cpu_display');

                if (cpuInput && cpuDisplay) {
                    cpuInput.addEventListener('input', function() {
                        var val = parseInt(this.value, 10) || 0;
                        cpuDisplay.textContent = (val * 1000).toLocaleString();
                    });
                }

                // Config export - copy to clipboard
                $('#synthload_copy_config').on('click', function() {
                    var textarea = document.getElementById('synthload_export_config');
                    var status = $('#synthload_copy_status');

                    if (!textarea) return;

                    // Try modern clipboard API first (requires HTTPS)
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(textarea.value).then(function() {
                            status.show().delay(2000).fadeOut();
                        }).catch(function() {
                            fallbackCopy(textarea, status);
                        });
                    } else {
                        fallbackCopy(textarea, status);
                    }
                });

                // Fallback copy function for HTTP contexts
                function fallbackCopy(textarea, status) {
                    textarea.focus();
                    textarea.select();
                    try {
                        document.execCommand('copy');
                        status.show().delay(2000).fadeOut();
                    } catch (err) {
                        status.text('Copy failed - please select and copy manually').css('color', '#d63638').show();
                    }
                }

                // Config import - apply to form
                $('#synthload_import_btn').on('click', function() {
                    var importText = $('#synthload_import_config').val().trim();
                    var status = $('#synthload_import_status');

                    if (!importText) {
                        status.css('color', '#d63638').text('Please paste a configuration first.').show();
                        return;
                    }

                    try {
                        var config = JSON.parse(importText);

                        // Apply values to form fields
                        if (typeof config.read_query_count !== 'undefined') {
                            $('#synthload_read_query_count').val(parseInt(config.read_query_count, 10) || 100);
                        }
                        if (typeof config.write_op_count !== 'undefined') {
                            $('#synthload_write_op_count').val(parseInt(config.write_op_count, 10) || 5);
                        }
                        if (typeof config.cpu_iterations !== 'undefined') {
                            var cpuVal = parseInt(config.cpu_iterations, 10) || 100;
                            $('#synthload_cpu_iterations').val(cpuVal);
                            // Update display
                            if (cpuDisplay) {
                                cpuDisplay.textContent = (cpuVal * 1000).toLocaleString();
                            }
                        }
                        if (typeof config.bypass_object_cache !== 'undefined') {
                            $('#synthload_bypass_object_cache').prop('checked', !!config.bypass_object_cache);
                        }

                        status.css('color', '#00a32a').text('Configuration applied! Save to confirm changes.').show();
                        updateExportConfig();

                    } catch (e) {
                        status.css('color', '#d63638').text('Invalid JSON format. Please check the configuration.').show();
                    }
                });

                // Function to update export config from current form values
                function updateExportConfig() {
                    var exportConfig = {
                        read_query_count: parseInt($('#synthload_read_query_count').val(), 10) || 100,
                        write_op_count: parseInt($('#synthload_write_op_count').val(), 10) || 5,
                        cpu_iterations: parseInt($('#synthload_cpu_iterations').val(), 10) || 100,
                        bypass_object_cache: $('#synthload_bypass_object_cache').is(':checked')
                    };
                    $('#synthload_export_config').val(JSON.stringify(exportConfig, null, 2));
                }

                // Keep export config in sync when form values change
                $('#synthload_read_query_count, #synthload_write_op_count, #synthload_cpu_iterations, #synthload_bypass_object_cache').on('change input', updateExportConfig);

                // Test workload button handler
                $('#synthload_test_btn').on('click', function(e) {
                    e.preventDefault();

                    var btn = $(this);
                    var spinner = $('#synthload_test_spinner');
                    var results = $('#synthload_test_results');
                    var errorBox = $('#synthload_test_error');

                    // Disable button and show spinner
                    btn.prop('disabled', true);
                    spinner.addClass('is-active');
                    results.hide();
                    errorBox.hide();

                    // Build query parameters from form values
                    var params = new URLSearchParams();
                    params.set('format', 'json');
                    params.set('read_query_count', $('#synthload_read_query_count').val());
                    params.set('write_op_count', $('#synthload_write_op_count').val());
                    params.set('cpu_iterations', $('#synthload_cpu_iterations').val());
                    params.set('bypass_object_cache', $('#synthload_bypass_object_cache').is(':checked') ? '1' : '0');

                    // Add access token if configured
                    if (accessToken) {
                        params.set('token', accessToken);
                    }

                    var testUrl = endpointUrl + '?' + params.toString();

                    // Start timing the full request round-trip
                    var startTime = performance.now();

                    $.ajax({
                        url: testUrl,
                        method: 'GET',
                        dataType: 'json',
                        success: function(response) {
                            var endTime = performance.now();
                            var roundTripMs = Math.round(endTime - startTime);

                            spinner.removeClass('is-active');
                            btn.prop('disabled', false);

                            if (response && response.execution) {
                                $('#synthload_result_duration').text(roundTripMs + ' ms');
                                $('#synthload_result_reads').text(response.execution.db_reads.toLocaleString());
                                $('#synthload_result_writes').text(response.execution.db_writes.toLocaleString());
                                $('#synthload_result_cpu').text(response.execution.cpu_iterations.toLocaleString());
                                results.show();
                            } else {
                                $('#synthload_test_error_msg').text('Unexpected response format.');
                                errorBox.show();
                            }
                        },
                        error: function(xhr) {
                            spinner.removeClass('is-active');
                            btn.prop('disabled', false);
                            var msg = 'Request failed: ' + xhr.status;
                            if (xhr.status === 403) {
                                msg = 'Access denied. Check access token settings.';
                            } else if (xhr.status === 404) {
                                msg = 'Endpoint not found. Try saving settings to flush rewrite rules.';
                            }
                            $('#synthload_test_error_msg').text(msg);
                            errorBox.show();
                        }
                    });
                });

                // === vCPU Calculator ===
                var sitePresets = {
                    dynamic: {
                        pagesPerVisit: 5,
                        cacheHitRate: 30,
                        connectionsPerVcpu: 2,
                        peakToAverageRatio: 2.5,
                        flashSpikePercent: 15,
                        responseTime: 2500
                    },
                    static: {
                        pagesPerVisit: 2,
                        cacheHitRate: 85,
                        connectionsPerVcpu: 8,
                        peakToAverageRatio: 2.0,
                        flashSpikePercent: 10,
                        responseTime: 300
                    }
                };

                var calcDefaults = {
                    pagesPerVisit: " . (int) $settings['calc_pages_per_visit'] . ",
                    cacheHitRate: " . (int) $settings['calc_cache_hit_rate'] . ",
                    connectionsPerVcpu: " . (int) $settings['calc_connections_per_vcpu'] . ",
                    peakToAverageRatio: " . (float) $settings['calc_peak_to_average_ratio'] . ",
                    flashSpikePercent: " . (int) $settings['calc_flash_spike_percent'] . "
                };

                // Apply preset values to form fields
                function applyPreset(presetKey) {
                    var preset = sitePresets[presetKey] || sitePresets.dynamic;
                    $('#calc_pages_per_visit').val(preset.pagesPerVisit);
                    $('#calc_cache_hit_rate').val(preset.cacheHitRate);
                    $('#calc_connections_per_vcpu').val(preset.connectionsPerVcpu);
                    $('#calc_peak_to_average_ratio').val(preset.peakToAverageRatio);
                    $('#calc_flash_spike_percent').val(preset.flashSpikePercent);
                    $('#calc_response_time').val(preset.responseTime);
                    // Update working defaults
                    calcDefaults = Object.assign({}, preset);
                    calculateCapacity();
                }

                // Handle input type toggle (visitors vs pageviews)
                function updateInputType() {
                    var inputType = $('input[name=\"calc_input_type\"]:checked').val();
                    if (inputType === 'pageviews') {
                        $('#calc_traffic_label').text('" . esc_js( __( 'Monthly Page Views', 'wp-synthload' ) ) . "');
                        $('#calc_traffic_desc').text('" . esc_js( __( 'Total page views per month.', 'wp-synthload' ) ) . "');
                    } else {
                        $('#calc_traffic_label').text('" . esc_js( __( 'Monthly Visitors', 'wp-synthload' ) ) . "');
                        $('#calc_traffic_desc').text('" . esc_js( __( 'Expected unique visitors per month.', 'wp-synthload' ) ) . "');
                    }
                    calculateCapacity();
                }

                function calculateCapacity() {
                    var trafficCount = parseInt($('#calc_traffic_count').val(), 10) || 0;
                    var inputType = $('input[name=\"calc_input_type\"]:checked').val() || 'visitors';
                    var responseTime = parseInt($('#calc_response_time').val(), 10) || 500;
                    var trafficShape = $('input[name=\"calc_traffic_shape\"]:checked').val() || 'uniform';
                    var safetyFactor = parseFloat($('#calc_safety_factor').val()) || 1.5;

                    // Get assumptions (use form values if advanced is open, otherwise defaults)
                    var assumptions = getAssumptions();

                    // Step 1: Page views (depends on input type)
                    var pageViews;
                    var visitors;
                    if (inputType === 'pageviews') {
                        pageViews = trafficCount;
                        visitors = Math.round(trafficCount / assumptions.pagesPerVisit);
                    } else {
                        visitors = trafficCount;
                        pageViews = trafficCount * assumptions.pagesPerVisit;
                    }

                    // Step 2: Effective requests (after cache)
                    var cacheRate = assumptions.cacheHitRate / 100;
                    var effectiveRequests = pageViews * (1 - cacheRate);

                    // Step 3: Peak RPS based on traffic shape
                    var peakRps = calculatePeakRps(effectiveRequests, trafficShape, assumptions);

                    // Step 4: Concurrent connections (Little's Law)
                    var concurrent = peakRps * (responseTime / 1000);

                    // Step 5: vCPUs needed
                    var rawVcpus = concurrent / assumptions.connectionsPerVcpu;
                    var vcpus = Math.max(1, Math.ceil(rawVcpus * safetyFactor));

                    // Update display
                    $('#calc_result_rps').text(peakRps.toFixed(2));
                    $('#calc_result_concurrent').text(concurrent.toFixed(1));
                    $('#calc_result_vcpus').text(vcpus);

                    // Update breakdown
                    updateBreakdown(visitors, pageViews, effectiveRequests,
                                    peakRps, concurrent, vcpus,
                                    trafficShape, safetyFactor, assumptions, responseTime, inputType);
                }

                function calculatePeakRps(effectiveRequests, shape, assumptions) {
                    var secondsPerMonth;

                    switch(shape) {
                        case 'business':
                            secondsPerMonth = 8 * 22 * 3600;
                            return (effectiveRequests / secondsPerMonth) * assumptions.peakToAverageRatio;

                        case 'flash_sale':
                            var spikePercent = assumptions.flashSpikePercent / 100;
                            var spikeRequests = effectiveRequests * spikePercent;
                            return spikeRequests / 3600;

                        case 'uniform':
                        default:
                            secondsPerMonth = 30 * 24 * 3600;
                            return effectiveRequests / secondsPerMonth;
                    }
                }

                function getAssumptions() {
                    if ($('#calc_advanced_toggle').is(':checked')) {
                        return {
                            pagesPerVisit: parseInt($('#calc_pages_per_visit').val(), 10) || calcDefaults.pagesPerVisit,
                            cacheHitRate: parseInt($('#calc_cache_hit_rate').val(), 10) || calcDefaults.cacheHitRate,
                            connectionsPerVcpu: parseInt($('#calc_connections_per_vcpu').val(), 10) || calcDefaults.connectionsPerVcpu,
                            peakToAverageRatio: parseFloat($('#calc_peak_to_average_ratio').val()) || calcDefaults.peakToAverageRatio,
                            flashSpikePercent: parseInt($('#calc_flash_spike_percent').val(), 10) || calcDefaults.flashSpikePercent
                        };
                    }
                    return calcDefaults;
                }

                function getShapeLabel(shape) {
                    switch(shape) {
                        case 'business': return 'Business Hours';
                        case 'flash_sale': return 'Flash Sale';
                        default: return 'Uniform';
                    }
                }

                function getShapeFormula(shape, effective, rps, assumptions) {
                    switch(shape) {
                        case 'business':
                            return Math.round(effective).toLocaleString() + ' / (8h × 22d × 3600s) × ' +
                                   assumptions.peakToAverageRatio + ' peak ratio = ' + rps.toFixed(2) + ' RPS';
                        case 'flash_sale':
                            var spikePercent = assumptions.flashSpikePercent;
                            return Math.round(effective).toLocaleString() + ' × ' + spikePercent + '% / 3600s = ' + rps.toFixed(2) + ' RPS';
                        default:
                            return Math.round(effective).toLocaleString() + ' / (30d × 24h × 3600s) = ' + rps.toFixed(2) + ' RPS';
                    }
                }

                function updateBreakdown(visitors, pageViews, effective, rps, concurrent,
                                         vcpus, shape, safety, assumptions, responseTime, inputType) {
                    var html = '';

                    // Step 1 depends on input type
                    if (inputType === 'pageviews') {
                        html += '<p><strong>Step 1: Monthly page views (direct input)</strong><br>';
                        html += '<code>' + pageViews.toLocaleString() + ' page views</code></p>';
                    } else {
                        html += '<p><strong>Step 1: Monthly page views</strong><br>';
                        html += '<code>' + visitors.toLocaleString() + ' visitors × ' + assumptions.pagesPerVisit;
                        html += ' pages/visit = ' + pageViews.toLocaleString() + ' page views</code></p>';
                    }

                    html += '<p><strong>Step 2: Effective requests (after ' +
                            assumptions.cacheHitRate + '% cache)</strong><br>';
                    html += '<code>' + pageViews.toLocaleString() + ' × ' + ((100 - assumptions.cacheHitRate) / 100).toFixed(2);
                    html += ' = ' + Math.round(effective).toLocaleString() + ' requests hitting server</code></p>';

                    html += '<p><strong>Step 3: Peak RPS (' + getShapeLabel(shape) + ')</strong><br>';
                    html += '<code>' + getShapeFormula(shape, effective, rps, assumptions) + '</code></p>';

                    html += '<p><strong>Step 4: Concurrent connections (Little\\'s Law)</strong><br>';
                    html += '<code>' + rps.toFixed(2) + ' RPS × ' + (responseTime/1000).toFixed(2) + 's';
                    html += ' = ' + concurrent.toFixed(1) + ' connections</code></p>';

                    html += '<p><strong>Step 5: vCPUs (' + assumptions.connectionsPerVcpu +
                            ' connections/vCPU)</strong><br>';
                    html += '<code>' + concurrent.toFixed(1) + ' / ' + assumptions.connectionsPerVcpu;
                    html += ' × ' + safety.toFixed(1) + ' safety = ' + vcpus + ' vCPU(s)</code></p>';

                    $('#calc_breakdown').html(html);
                }

                // Site type preset change
                $('#calc_site_type').on('change', function() {
                    applyPreset($(this).val());
                });

                // Input type toggle
                $('input[name=\"calc_input_type\"]').on('change', updateInputType);

                // Bind calculator events
                $('#calc_traffic_count, #calc_response_time, #calc_safety_factor').on('input change', calculateCapacity);
                $('input[name=\"calc_traffic_shape\"]').on('change', calculateCapacity);

                // Advanced settings toggle
                $('#calc_advanced_toggle').on('change', function() {
                    $('#calc_advanced_settings').toggle(this.checked);
                    calculateCapacity();
                });

                // Advanced settings inputs
                $('#calc_pages_per_visit, #calc_cache_hit_rate, #calc_connections_per_vcpu, ' +
                  '#calc_peak_to_average_ratio, #calc_flash_spike_percent').on('input change', calculateCapacity);

                // Initial setup
                if ($('#calc_traffic_count').length) {
                    // Apply initial preset based on dropdown
                    applyPreset($('#calc_site_type').val());
                }
            });
        })(jQuery);
        ";

        wp_add_inline_script( 'jquery', $script );
    }

    /**
     * Register settings with WordPress Settings API.
     */
    public function register_settings(): void {
        register_setting(
            'synthload_options_group',
            SynthLoad_Settings::OPTION_NAME,
            array(
                'type'              => 'array',
                'sanitize_callback' => array( 'SynthLoad_Settings', 'sanitize' ),
            )
        );
    }

    /**
     * Write the Loader.io verification file to the web root.
     *
     * @param string $token_id The token ID (without loaderio- prefix).
     * @return bool True on success, false on failure.
     */
    public static function write_loaderio_file( string $token_id ): bool {
        if ( empty( $token_id ) ) {
            return false;
        }

        $filename = 'loaderio-' . $token_id . '.txt';
        $filepath = ABSPATH . $filename;
        $content  = 'loaderio-' . $token_id;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        $result = file_put_contents( $filepath, $content );

        return false !== $result;
    }

    /**
     * Delete a Loader.io verification file from the web root.
     *
     * @param string $token_id The token ID (without loaderio- prefix).
     * @return bool True on success or file didn't exist, false on failure.
     */
    public static function delete_loaderio_file( string $token_id ): bool {
        if ( empty( $token_id ) ) {
            return true;
        }

        $filename = 'loaderio-' . $token_id . '.txt';
        $filepath = ABSPATH . $filename;

        if ( ! file_exists( $filepath ) ) {
            return true;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        return unlink( $filepath );
    }

    /**
     * Check if the Loader.io verification file exists.
     *
     * @param string $token_id The token ID (without loaderio- prefix).
     * @return bool True if file exists, false otherwise.
     */
    public static function loaderio_file_exists( string $token_id ): bool {
        if ( empty( $token_id ) ) {
            return false;
        }

        $filename = 'loaderio-' . $token_id . '.txt';
        $filepath = ABSPATH . $filename;

        return file_exists( $filepath );
    }

    /**
     * Get the path to the Loader.io verification file.
     *
     * @param string $token_id The token ID (without loaderio- prefix).
     * @return string The full file path.
     */
    public static function get_loaderio_filepath( string $token_id ): string {
        return ABSPATH . 'loaderio-' . $token_id . '.txt';
    }

}
