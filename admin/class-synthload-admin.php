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

        // Write new file if token exists
        if ( ! empty( $new_token_id ) ) {
            if ( self::write_loaderio_file( $new_token_id ) ) {
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
        ';

        wp_add_inline_style( 'common', $css );

        $script = "
        (function($) {
            var ajaxUrl = '" . esc_js( admin_url( 'admin-ajax.php' ) ) . "';

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

                    navigator.clipboard.writeText(textarea.value).then(function() {
                        status.show().delay(2000).fadeOut();
                    }).catch(function() {
                        // Fallback for older browsers
                        textarea.select();
                        document.execCommand('copy');
                        status.show().delay(2000).fadeOut();
                    });
                });

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

                    // Gather current form values
                    var data = {
                        action: 'synthload_test_workload',
                        nonce: $('#synthload_test_nonce').val(),
                        read_query_count: $('#synthload_read_query_count').val(),
                        write_op_count: $('#synthload_write_op_count').val(),
                        cpu_iterations: $('#synthload_cpu_iterations').val(),
                        bypass_object_cache: $('#synthload_bypass_object_cache').is(':checked') ? 'true' : 'false'
                    };

                    $.post(ajaxUrl, data, function(response) {
                        spinner.removeClass('is-active');
                        btn.prop('disabled', false);

                        if (response.success) {
                            $('#synthload_result_duration').text(response.data.duration_ms + ' ms');
                            $('#synthload_result_reads').text(response.data.db_reads.toLocaleString());
                            $('#synthload_result_writes').text(response.data.db_writes.toLocaleString());
                            $('#synthload_result_cpu').text(response.data.cpu_iterations.toLocaleString());
                            results.show();
                        } else {
                            $('#synthload_test_error_msg').text(response.data.message || 'Test failed.');
                            errorBox.show();
                        }
                    }).fail(function(xhr) {
                        spinner.removeClass('is-active');
                        btn.prop('disabled', false);
                        $('#synthload_test_error_msg').text('Request failed: ' + xhr.statusText);
                        errorBox.show();
                    });
                });
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

    /**
     * Handle AJAX test workload request.
     *
     * Executes a workload with the provided form settings and returns results.
     */
    public static function ajax_test_workload(): void {
        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) ||
             ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'synthload_test_workload' ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'wp-synthload' ) ), 403 );
        }

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wp-synthload' ) ), 403 );
        }

        // Build settings from POST data
        $test_settings = array(
            'read_query_count'      => isset( $_POST['read_query_count'] ) ? (int) $_POST['read_query_count'] : 100,
            'write_op_count'        => isset( $_POST['write_op_count'] ) ? (int) $_POST['write_op_count'] : 5,
            'cpu_iterations'        => isset( $_POST['cpu_iterations'] ) ? (int) $_POST['cpu_iterations'] : 100,
            'bypass_object_cache'   => isset( $_POST['bypass_object_cache'] ) && 'true' === $_POST['bypass_object_cache'],
            'debug_logging_enabled' => false, // Don't log during tests
        );

        // Sanitize through settings class to enforce limits
        $test_settings = SynthLoad_Settings::sanitize( $test_settings );

        // Merge with defaults for any missing keys
        $test_settings = array_merge( SynthLoad_Settings::get_defaults(), $test_settings );

        // Execute workload
        global $wpdb;
        $db       = new SynthLoad_Db( $wpdb );
        $workload = new SynthLoad_Workload( $db, $test_settings );
        $result   = $workload->execute();

        // Return results
        wp_send_json_success( array(
            'duration_ms'    => $result['execution']['duration_ms'],
            'db_reads'       => $result['execution']['db_reads'],
            'db_writes'      => $result['execution']['db_writes'],
            'cpu_iterations' => $result['execution']['cpu_iterations'],
            'request_id'     => $result['request_id'],
        ) );
    }
}
