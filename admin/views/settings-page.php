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
                        <label for="synthload_write_op_count"><?php esc_html_e( 'Database Writes', 'wp-synthload' ); ?></label>
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
                                /* translators: %d: maximum write operations */
                                esc_html__( 'Number of write operations per request (max: %d).', 'wp-synthload' ),
                                $limits['max_write_op_count']
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="synthload_target_duration_ms"><?php esc_html_e( 'Target Duration (ms)', 'wp-synthload' ); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="synthload_target_duration_ms"
                               name="target_duration_ms"
                               value="<?php echo esc_attr( $settings['target_duration_ms'] ); ?>"
                               min="100"
                               max="<?php echo esc_attr( $limits['max_total_duration_ms'] ); ?>"
                               step="100"
                               class="small-text" />
                        <p class="description">
                            <?php
                            printf(
                                /* translators: %d: maximum duration */
                                esc_html__( 'Target execution time in milliseconds (max: %d).', 'wp-synthload' ),
                                $limits['max_total_duration_ms']
                            );
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="synthload_duration_jitter_ms"><?php esc_html_e( 'Duration Jitter (ms)', 'wp-synthload' ); ?></label>
                    </th>
                    <td>
                        <input type="number"
                               id="synthload_duration_jitter_ms"
                               name="duration_jitter_ms"
                               value="<?php echo esc_attr( $settings['duration_jitter_ms'] ); ?>"
                               min="0"
                               max="5000"
                               step="50"
                               class="small-text" />
                        <p class="description">
                            <?php esc_html_e( 'Random variation in target duration (+/- this value).', 'wp-synthload' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Cache Behavior', 'wp-synthload' ); ?></th>
                    <td>
                        <fieldset>
                            <label for="synthload_use_object_cache">
                                <input type="checkbox"
                                       id="synthload_use_object_cache"
                                       name="use_object_cache"
                                       value="1"
                                       <?php checked( $settings['use_object_cache'] ); ?> />
                                <?php esc_html_e( 'Use cache-friendly operations', 'wp-synthload' ); ?>
                            </label>
                            <br>
                            <label for="synthload_bypass_object_cache">
                                <input type="checkbox"
                                       id="synthload_bypass_object_cache"
                                       name="bypass_object_cache"
                                       value="1"
                                       <?php checked( $settings['bypass_object_cache'] ); ?> />
                                <?php esc_html_e( 'Bypass object cache when possible', 'wp-synthload' ); ?>
                            </label>
                            <p class="description">
                                <?php esc_html_e( 'Control whether workload benefits from or bypasses object caching.', 'wp-synthload' ); ?>
                            </p>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Randomization', 'wp-synthload' ); ?></th>
                    <td>
                        <label for="synthload_randomize_workload">
                            <input type="checkbox"
                                   id="synthload_randomize_workload"
                                   name="randomize_workload"
                                   value="1"
                                   <?php checked( $settings['randomize_workload'] ); ?> />
                            <?php esc_html_e( 'Randomize workload parameters', 'wp-synthload' ); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Adds variation to reads, writes, and duration to prevent caching artifacts.', 'wp-synthload' ); ?>
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
                        /* translators: %d: max duration */
                        esc_html__( 'Maximum duration: %d ms', 'wp-synthload' ),
                        $limits['max_total_duration_ms']
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
