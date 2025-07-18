<?php
/**
 * Plugin Name: Simple WP Site Exporter
 * Description: Exports the site files and database as a zip archive.
 * Version: 1.8.1
 * Author: EngineScript
 * License: GPL v3 or later
 * Text Domain: Simple-WP-Site-Exporter
 *
 * @package Simple_WP_Site_Exporter
 */

// Prevent direct access. Note: Using return here instead of exit.
if ( ! defined( 'ABSPATH' ) ) {
    return; // Prevent direct access.
}

// Define plugin version.
if ( ! defined( 'ES_WP_SITE_EXPORTER_VERSION' ) ) {
    define( 'ES_WP_SITE_EXPORTER_VERSION', '1.8.1' );
}

/**
 * Load plugin textdomain for internationalization.
 *
 * @return void
 */
function sse_load_textdomain() {
    load_plugin_textdomain(
        'Simple-WP-Site-Exporter',
        false,
        dirname( plugin_basename( __FILE__ ) ) . '/languages/'
    );
}
add_action( 'plugins_loaded', 'sse_load_textdomain' );

/**
 * WordPress Core Classes Documentation
 *
 * This plugin uses WordPress core classes which are automatically available
 * in the WordPress environment. These classes don't require explicit imports
 * or use statements as they are part of WordPress core.
 *
 * Core classes used:
 *
 * @see WP_Error - WordPress error handling class
 * @see ZipArchive - PHP ZipArchive class
 * @see RecursiveIteratorIterator - PHP SPL iterator
 * @see RecursiveDirectoryIterator - PHP SPL directory iterator
 * @see SplFileInfo - PHP SPL file information class
 * @see Exception - PHP base exception class
 *
 * @SuppressWarnings(PHPMD.MissingImport)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) - Single file WordPress plugin architecture
 */

/**
 * Safely get client IP address.
 *
 * @return string Client IP address or 'unknown' if not available.
 */
function sse_get_client_ip() {
    // WordPress-style IP detection with validation.
    $client_ip = 'unknown';

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- $_SERVER['REMOTE_ADDR'] is safe for IP logging when properly sanitized
    if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
        $client_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
    }

    // Basic IP validation.
    if ( filter_var( $client_ip, FILTER_VALIDATE_IP ) !== false ) {
        return $client_ip;
    }

    return 'unknown';
} // end sse_get_client_ip()


/**
 * Stores important log messages in database for review.
 *
 * @param string $message The log message.
 * @param string $level   The log level.
 * @return void
 */
function sse_store_log_in_database( $message, $level ) {
    // Store last 20 important messages in an option.
    $logs   = get_option( 'sse_error_logs', [] );
    $logs[] = [
        'time'    => time(),
        'level'   => $level,
        'message' => $message,
        'user_id' => get_current_user_id(),
        'ip'      => sse_get_client_ip(),
    ];

    // Keep only the most recent 20 logs.
    if ( count( $logs ) > 20 ) {
        $logs = array_slice( $logs, -20 );
    }

    update_option( 'sse_error_logs', $logs );
} // end sse_store_log_in_database()


/**
 * Outputs log message to WordPress debug log or error_log.
 *
 * @param string $formatted_message The formatted log message.
 * @return void
 */
function sse_output_log_message( $formatted_message ) {
    // Use WordPress logging (wp_debug_log is available in WP 5.1+).
    if ( function_exists( 'wp_debug_log' ) ) {
        wp_debug_log( $formatted_message );
    }
} // end sse_output_log_message()


/**
 * Safely log plugin messages
 *
 * @param string $message The message to log.
 * @param string $level   The log level (error, warning, info).
 * @return void
 */
function sse_log( $message, $level = 'info' ) {
    // Check if WP_DEBUG is enabled.
    if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
        return;
    }

    // Format the message with a timestamp (using GMT to avoid timezone issues).
    $formatted_message = sprintf(
        '[%s] [%s] %s: %s',
        gmdate( 'Y-m-d H:i:s' ),
        'Simple WP Site Exporter',
        strtoupper( $level ),
        $message
    );

    // Only log if debug logging is enabled.
    if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
        return;
    }

    sse_output_log_message( $formatted_message );

    // Store logs in the database (errors and security events to prevent issues).
    if ( 'error' === $level || 'security' === $level ) {
        sse_store_log_in_database( $message, $level );
    }
} // end sse_log()

/**
 * Safely get the PHP execution time limit.
 *
 * @return int Current PHP execution time limit in seconds.
 */
function sse_get_execution_time_limit() {
    // Get the current execution time limit.
    $max_exec_time = ini_get( 'max_execution_time' );

    // Handle all possible return types from ini_get().
    if ( false === $max_exec_time ) {
        // Ini_get failed.
        return 30;
    }

    if ( '' === $max_exec_time ) {
        // Empty string returned.
        return 30;
    }

    if ( ! is_numeric( $max_exec_time ) ) {
        // Non-numeric value returned.
        return 30;
    }

    return (int) $max_exec_time;
} // end sse_get_execution_time_limit()

// --- Admin Menu ---
/**
 * Adds the Site Exporter page to the WordPress admin menu.
 *
 * @return void
 */
function sse_admin_menu() {
    add_management_page(
        esc_html__( 'Simple WP Site Exporter', 'Simple-WP-Site-Exporter' ), // Escaped title.
        esc_html__( 'Site Exporter', 'Simple-WP-Site-Exporter' ),       // Escaped menu title.
        'manage_options', // Capability required.
        'simple-wp-site-exporter',
        'sse_exporter_page_html'
    );
}
add_action( 'admin_menu', 'sse_admin_menu' );

// --- Exporter Page HTML ---
/**
 * Renders the exporter page HTML interface.
 *
 * @return void
 */
function sse_exporter_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    $upload_dir = wp_upload_dir();
    if ( empty( $upload_dir['basedir'] ) ) {
         wp_die( esc_html__( 'Could not determine the WordPress upload directory.', 'Simple-WP-Site-Exporter' ) );
    }
    $export_dir_name = 'simple-wp-site-exporter-exports';
    $export_dir_path = trailingslashit( $upload_dir['basedir'] ) . $export_dir_name;
    $display_path = str_replace( ABSPATH, '', $export_dir_path );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html() used for proper escaping ?></h1>
        <p><?php esc_html_e( 'Click the button below to generate a zip archive containing your WordPress files and a database dump (.sql file).', 'Simple-WP-Site-Exporter' ); ?></p>
        <p><strong><?php esc_html_e( 'Warning:', 'Simple-WP-Site-Exporter' ); ?></strong> <?php esc_html_e( 'This can take a long time and consume significant server resources, especially on large sites. Ensure your server has sufficient disk space and execution time.', 'Simple-WP-Site-Exporter' ); ?></p>
        <p style="margin-top: 15px;">
            <?php
            // printf is standard in WordPress for translatable strings with placeholders. All variables are escaped.
            printf(
                // translators: %s: directory path
                esc_html__( 'Exported .zip files will be saved in the following directory on the server: %s', 'Simple-WP-Site-Exporter' ),
                '<code>' . esc_html( $display_path ) . '</code>'
            );
            ?>
        </p>
        <form method="post" action="" style="margin-top: 15px;">
            <?php wp_nonce_field( 'sse_export_action', 'sse_export_nonce' ); ?>
            <input type="hidden" name="action" value="sse_export_site">
            <?php submit_button( esc_html__( 'Export Site', 'Simple-WP-Site-Exporter' ) ); ?>
        </form>
        <hr>
        <p>
            <?php esc_html_e( 'This plugin is part of the EngineScript project.', 'Simple-WP-Site-Exporter' ); ?>
            <a href="https://github.com/EngineScript/EngineScript" target="_blank" rel="noopener noreferrer">
                <?php esc_html_e( 'Visit the EngineScript GitHub page', 'Simple-WP-Site-Exporter' ); ?>
            </a>
        </p>
        <p style="color: #b94a48; font-weight: bold;">
            <?php esc_html_e( 'Important:', 'Simple-WP-Site-Exporter' ); ?>
            <?php esc_html_e( 'The exported zip file is publicly accessible while it remains in the above directory. For security, you should remove the exported file from the server once you are finished downloading it.', 'Simple-WP-Site-Exporter' ); ?>
        </p>
        <p style="color: #b94a48; font-weight: bold;">
            <?php esc_html_e( 'Security Notice:', 'Simple-WP-Site-Exporter' ); ?>
            <?php esc_html_e( 'For your protection, the exported zip file will be automatically deleted from the server 5 minutes after it is created.', 'Simple-WP-Site-Exporter' ); ?>
        </p>
    </div>
    <?php
}

// --- Handle Export Action ---
/**
 * Handles the site export process when the form is submitted.
 *
 * @return void
 */
function sse_handle_export() {
    if ( ! sse_validate_export_request() ) {
        return;
    }

    sse_prepare_execution_environment();

    $export_paths = sse_setup_export_directories();
    if ( is_wp_error( $export_paths ) ) {
        wp_die( esc_html( $export_paths->get_error_message() ) );
    }

    $database_file = sse_export_database( $export_paths['export_dir'] );
    if ( is_wp_error( $database_file ) ) {
        sse_show_error_notice( $database_file->get_error_message() );
        return;
    }

    $zip_result = sse_create_site_archive( $export_paths, $database_file );
    if ( is_wp_error( $zip_result ) ) {
        sse_cleanup_files( array( $database_file['filepath'] ) );
        sse_show_error_notice( $zip_result->get_error_message() );
        return;
    }

    sse_cleanup_files( array( $database_file['filepath'] ) );
    sse_schedule_export_cleanup( $zip_result['filepath'] );
    sse_show_success_notice( $zip_result );
}

/**
 * Validates the export request for security and permissions.
 *
 * @return bool True if request is valid, false otherwise.
 */
function sse_validate_export_request() { // phpcs:ignore WordPress.Security.NonceVerification.Missing
    $post_action = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification happens below
    if ( 'sse_export_site' !== $post_action ) {
        return false;
    }

    $post_nonce = isset( $_POST['sse_export_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sse_export_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This line retrieves nonce for verification
    if ( ! $post_nonce || ! wp_verify_nonce( $post_nonce, 'sse_export_action' ) ) {
        wp_die( esc_html__( 'Nonce verification failed! Please try again.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to perform this action.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    return true;
} // end sse_validate_export_request()


/**
 * Prepares the execution environment for export operations.
 *
 * @return void
 */
function sse_prepare_execution_environment() {
    $max_exec_time    = sse_get_execution_time_limit();
    $target_exec_time = 1800; // 30 minutes in seconds.

    if ( $max_exec_time > 0 && $max_exec_time < $target_exec_time ) {
        // Note: set_time_limit() is discouraged in WordPress plugins.
        // Users should configure execution time limits at the server level.
        sse_log( "Current execution time limit ({$max_exec_time}s) may be insufficient for large exports. Consider increasing server limits.", 'warning' );
        return;
    }

    sse_log( 'Execution time limit appears adequate for export operations', 'info' );
} // end sse_prepare_execution_environment()

/**
 * Sets up export directories and returns path information.
 *
 * @return array|WP_Error Array of paths on success, WP_Error on failure.
 */
function sse_setup_export_directories() {
    $upload_dir = wp_upload_dir();
    if ( empty( $upload_dir['basedir'] ) || empty( $upload_dir['baseurl'] ) ) {
        return new WP_Error( 'upload_dir_error', __( 'Could not determine the WordPress upload directory or URL.', 'Simple-WP-Site-Exporter' ) );
    }

    $export_dir_name = 'simple-wp-site-exporter-exports';
    $export_dir = trailingslashit( $upload_dir['basedir'] ) . $export_dir_name;
    $export_url = trailingslashit( $upload_dir['baseurl'] ) . $export_dir_name;

    wp_mkdir_p( $export_dir );
    sse_create_index_file( $export_dir );

    return array(
        'export_dir' => $export_dir,
        'export_url' => $export_url,
        'export_dir_name' => $export_dir_name,
    );
}

/**
 * Creates an index.php file in the export directory to prevent directory listing.
 *
 * @param string $export_dir The export directory path.
 */
function sse_create_index_file( $export_dir ) {
    $index_file_path = trailingslashit( $export_dir ) . 'index.php';
    if ( file_exists( $index_file_path ) ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_exists_file_exists -- Checking controlled export directory
        return;
    }

    global $wp_filesystem;
    if ( ! $wp_filesystem ) {
        require_once ABSPATH . 'wp-admin/includes/file.php'; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- WordPress core filesystem API
        if ( ! WP_Filesystem() ) {
            sse_log('Failed to initialize WordPress filesystem API', 'error');
            return;
        }
    }

    if ( $wp_filesystem && $wp_filesystem->is_writable( $export_dir ) ) {
        $wp_filesystem->put_contents(
            $index_file_path,
            '<?php // Silence is golden.',
            FS_CHMOD_FILE
        );
        return;
    }

    sse_log('Failed to write index.php file or directory not writable: ' . $export_dir, 'error');
}

/**
 * Exports the database and returns file information.
 *
 * @param string $export_dir The directory to save the database dump.
 * @return array|WP_Error Array with file info on success, WP_Error on failure.
 */
function sse_export_database( $export_dir ) {
    $site_name = sanitize_file_name( get_bloginfo( 'name' ) );
    $timestamp = gmdate( 'Y-m-d_H-i-s' );
    $db_filename = "db_dump_{$site_name}_{$timestamp}.sql";
    $db_filepath = trailingslashit( $export_dir ) . $db_filename;

    if ( ! function_exists( 'shell_exec' ) ) {
        return new WP_Error( 'shell_exec_disabled', __( 'shell_exec function is disabled on this server.', 'Simple-WP-Site-Exporter' ) );
    }

    // Enhanced WP-CLI path validation
    $wp_cli_path = sse_get_safe_wp_cli_path();
    if ( is_wp_error( $wp_cli_path ) ) {
        return $wp_cli_path;
    }

    $command = sprintf(
        '%s db export %s --path=%s --allow-root',
        escapeshellarg($wp_cli_path), // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.escapeshellarg_escapeshellarg -- Required for shell command security
        escapeshellarg($db_filepath), // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.escapeshellarg_escapeshellarg -- Required for shell command security
        escapeshellarg(ABSPATH) // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.escapeshellarg_escapeshellarg -- Required for shell command security
    );

    $output = shell_exec($command . ' 2>&1'); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Required for WP-CLI database export: all parameters are validated and escaped with escapeshellarg()


    if ( ! file_exists( $db_filepath ) || filesize( $db_filepath ) <= 0 ) { // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_exists_file_exists -- Validating WP-CLI export success
        $error_message = ! empty($output) ? trim($output) : 'WP-CLI command failed silently.';
        return new WP_Error( 'db_export_failed', $error_message );
    }

    sse_log("Database export successful", 'info');
    return array(
        'filename' => $db_filename,
        'filepath' => $db_filepath,
    );
}

/**
 * Creates a site archive with database and files.
 *
 * @param array $export_paths Export directory paths.
 * @param array $database_file Database file information.
 * @return array|WP_Error Archive info on success, WP_Error on failure.
 */
function sse_create_site_archive( $export_paths, $database_file ) {
    if ( ! class_exists( 'ZipArchive' ) ) {
        return new WP_Error( 'zip_not_available', __( 'ZipArchive class is not available on your server. Cannot create zip file.', 'Simple-WP-Site-Exporter' ) );
    }

    $site_name = sanitize_file_name( get_bloginfo( 'name' ) );
    $timestamp = gmdate( 'Y-m-d_H-i-s' );
    $random_str = substr( bin2hex( random_bytes(4) ), 0, 7 );
    $zip_filename = "site_export_sse_{$random_str}_{$site_name}_{$timestamp}.zip";
    $zip_filepath = trailingslashit( $export_paths['export_dir'] ) . $zip_filename;

    $zip = new ZipArchive();
    if ( $zip->open( $zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
        return new WP_Error(
            'zip_create_failed',
            sprintf(
                /* translators: %s: filename */
                __( 'Could not create zip file at %s', 'Simple-WP-Site-Exporter' ),
                basename( $zip_filepath ) // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_basename -- Safe usage: $zip_filepath is constructed from controlled inputs (WordPress upload dir + sanitized filename), not user input.
            )
        );
    }

    // Add database dump to zip
    if ( ! $zip->addFile( $database_file['filepath'], $database_file['filename'] ) ) {
        $zip->close();
        return new WP_Error( 'zip_db_add_failed', __( 'Failed to add database file to zip archive.', 'Simple-WP-Site-Exporter' ) );
    }

    $file_result = sse_add_wordpress_files_to_zip( $zip, $export_paths['export_dir'] );
    if ( is_wp_error( $file_result ) ) {
        $zip->close();
        return $file_result;
    }

    $zip_close_status = $zip->close();

    if ( ! $zip_close_status || ! file_exists( $zip_filepath ) ) {
        return new WP_Error( 'zip_finalize_failed', __( 'Failed to finalize or save the zip archive after processing files.', 'Simple-WP-Site-Exporter' ) );
    }

    sse_log("Site archive created successfully: " . $zip_filepath, 'info');
    return array(
        'filename' => $zip_filename,
        'filepath' => $zip_filepath,
    );
}

/**
 * Adds WordPress files to the zip archive.
 *
 * @param ZipArchive $zip The zip archive object.
 * @param string $export_dir The export directory to exclude.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_add_wordpress_files_to_zip( $zip, $export_dir ) {
    $source_path = realpath( ABSPATH );
    if ( ! $source_path ) {
        sse_log( "Could not resolve real path for ABSPATH. Using ABSPATH directly.", 'warning' );
        $source_path = ABSPATH;
    }

    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source_path, RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $files as $file_info ) {
            sse_process_file_for_zip( $zip, $file_info, $source_path, $export_dir );
        }
    } catch ( Exception $e ) {
        return new WP_Error(
            'file_iteration_failed',
            sprintf(
                /* translators: %s: error message */
                __( 'Error during file processing: %s', 'Simple-WP-Site-Exporter' ),
                $e->getMessage()
            )
        );
    }

    return true;
}

/**
 * Processes a single file for addition to the zip archive.
 *
 * @param ZipArchive $zip The zip archive object.
 * @param SplFileInfo $file_info File information object.
 * @param string $source_path The source path.
 * @param string $export_dir The export directory to exclude.
 * @return true|null True on success, null to continue, WP_Error on failure.
 */
/**
 * Process a single file for addition to ZIP archive.
 *
 * @param ZipArchive $zip ZIP archive object.
 * @param SplFileInfo $file_info File information object.
 * @param string $source_path Source directory path.
 * @param string $export_dir Export directory to exclude.
 * @return true|null True on success, null if skipped.
 */
function sse_process_file_for_zip( $zip, $file_info, $source_path, $export_dir ) {
    if ( ! $file_info->isReadable() ) {
        sse_log( "Skipping unreadable file/dir: " . $file_info->getPathname(), 'warning' );
        return null;
    }

    $file = $file_info->getRealPath();
    $pathname = $file_info->getPathname();
    $relative_path = ltrim( substr( $pathname, strlen( $source_path ) ), '/' );

    if ( empty($relative_path) ) {
        return null;
    }

    if ( sse_should_exclude_file( $pathname, $relative_path, $export_dir ) ) {
        return null;
    }

    return sse_add_file_to_zip( $zip, $file_info, $file, $pathname, $relative_path );
}

/**
 * Adds a file or directory to the zip archive.
 *
 * @param ZipArchive $zip The zip archive object.
 * @param SplFileInfo $file_info File information object.
 * @param string|false $file Real file path or false if getRealPath() failed.
 * @param string $pathname Original pathname.
 * @param string $relative_path Relative path in archive.
 * @return true
 */
function sse_add_file_to_zip( $zip, $file_info, $file, $pathname, $relative_path ) {
    if ( $file_info->isDir() ) {
        if ( ! $zip->addEmptyDir( $relative_path ) ) {
            sse_log( "Failed to add directory to zip: " . $relative_path, 'error' );
        }
        return true;
    }

    if ( $file_info->isFile() ) {
        // Use real path (getRealPath() must succeed for security)
        if ( false === $file ) {
            sse_log( "Skipping file with unresolvable real path: " . $pathname, 'warning' );
            return true; // Skip this file but continue processing
        }

        $file_to_add = $file;

        if ( ! $zip->addFile( $file_to_add, $relative_path ) ) {
            sse_log( "Failed to add file to zip: " . $relative_path . " (Source: " . $file_to_add . ")", 'error' );
        }
    }

    return true;
}

/**
 * Determines if a file should be excluded from the export.
 *
 * @param string $pathname The full pathname.
 * @param string $relative_path The relative path.
 * @param string $export_dir The export directory to exclude.
 * @return bool True if file should be excluded.
 */
function sse_should_exclude_file( $pathname, $relative_path, $export_dir ) {
    // Exclude export directory
    if ( strpos( $pathname, $export_dir ) === 0 ) {
        return true;
    }

    // Exclude cache and temporary directories
    if ( preg_match( '#^wp-content/(cache|upgrade|temp)/#', $relative_path ) ) {
        return true;
    }

    // Exclude version control and system files
    if ( preg_match( '#(^|/)\.(git|svn|hg|DS_Store|htaccess|user\.ini)$#i', $relative_path ) ) {
        return true;
    }

    return false;
}

/**
 * Shows an error notice to the user.
 *
 * @param string $message The error message to display.
 */
function sse_show_error_notice( $message ) {
    add_action( 'admin_notices', function() use ( $message ) {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html( $message ); ?></p>
        </div>
        <?php
    });
    sse_log( "Export error: " . $message, 'error' );
}

/**
 * Shows a success notice to the user.
 *
 * @param array $zip_result The zip file information.
 */
function sse_show_success_notice( $zip_result ) {
    add_action( 'admin_notices', function() use ( $zip_result ) {
        $download_url = add_query_arg(
            array(
                'sse_secure_download' => $zip_result['filename'],
                'sse_download_nonce' => wp_create_nonce('sse_secure_download')
            ),
            admin_url()
        );

        $delete_url = add_query_arg(
            array(
                'sse_delete_export' => $zip_result['filename'],
                'sse_delete_nonce' => wp_create_nonce('sse_delete_export')
            ),
            admin_url()
        );

        $display_zip_path = str_replace( ABSPATH, '[wp-root]/', $zip_result['filepath'] );
        $display_zip_path = preg_replace( '|/+|', '/', $display_zip_path );
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php esc_html_e( 'Site export successfully created!', 'Simple-WP-Site-Exporter' ); ?>
                <a href="<?php echo esc_url( $download_url ); ?>" class="button" style="margin-left: 10px;">
                    <?php esc_html_e( 'Download Export File', 'Simple-WP-Site-Exporter' ); ?>
                </a>
                <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-secondary" style="margin-left: 10px;" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this export file?', 'Simple-WP-Site-Exporter' ); ?>');">
                    <?php esc_html_e( 'Delete Export File', 'Simple-WP-Site-Exporter' ); ?>
                </a>
            </p>
            <p><small><?php
                printf(
                    /* translators: %s: file path */
                    esc_html__( 'File location: %s', 'Simple-WP-Site-Exporter' ),
                    '<code title="' . esc_attr__( 'Path is relative to WordPress root directory', 'Simple-WP-Site-Exporter' ) . '">' .
                    esc_html( $display_zip_path ) . '</code>'
                );
				?>
</small></p>
        </div>
        <?php
    });
    sse_log("Export successful. File saved to " . $zip_result['filepath'], 'info');
}

/**
 * Cleans up temporary files.
 *
 * @param array $files Array of file paths to delete.
 */
function sse_cleanup_files( $files ) {
    foreach ( $files as $file ) {
        if ( file_exists( $file ) ) {
            sse_safely_delete_file( $file );
            sse_log("Cleaned up temporary file: " . $file, 'info');
        }
    }
}

/**
 * Schedules cleanup of export files.
 *
 * @param string $zip_filepath The zip file path to schedule for deletion.
 */
function sse_schedule_export_cleanup( $zip_filepath ) {
    if ( ! wp_next_scheduled( 'sse_delete_export_file', array( $zip_filepath ) ) ) {
        wp_schedule_single_event( time() + (5 * 60), 'sse_delete_export_file', array( $zip_filepath ) );
    }
}
add_action( 'admin_init', 'sse_handle_export' );

// --- Scheduled Deletion Handler ---
function sse_delete_export_file_handler( $file ) {
    // Validate that this is actually an export file before deletion
    $filename = basename( $file );

    // Use the same validation as manual deletion for consistency
    $validation = sse_validate_export_file_for_deletion( $filename );
    if ( is_wp_error( $validation ) ) {
        sse_log( 'Scheduled deletion blocked - invalid file: ' . $file . ' - ' . $validation->get_error_message(), 'warning' );
        return;
    }

    if ( file_exists( $file ) ) {
        if ( sse_safely_delete_file( $file ) ) {
            sse_log( 'Scheduled deletion successful: ' . $file, 'info' );
            return;
        }
        sse_log( 'Scheduled deletion failed: ' . $file, 'error' );
    }
}
add_action( 'sse_delete_export_file', 'sse_delete_export_file_handler' );

/**
 * Safely delete a file using WordPress Filesystem API
 *
 * @param string $filepath Path to the file to delete
 * @return bool Whether the file was deleted successfully
 */
function sse_safely_delete_file( $filepath ) {
    global $wp_filesystem;

    // Initialize the WordPress filesystem
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( ! WP_Filesystem() ) {
            sse_log('Failed to initialize WordPress filesystem API', 'error');
            return false;
        }
    }

    if ( ! $wp_filesystem ) {
        sse_log('WordPress filesystem API not available', 'error');
        return false;
    }

    // Check if the file exists using WP Filesystem
    if ($wp_filesystem->exists($filepath)) {
        // Delete the file using WordPress Filesystem API
        return $wp_filesystem->delete($filepath, false, 'f');
    }

    return false;
}

/**
 * Validates a file path for directory traversal attempts.
 * 
 * @param string $normalized_file_path The normalized file path to check.
 * @return bool True if path is safe, false if contains traversal patterns.
 */
function sse_check_path_traversal( $normalized_file_path ) {
    // Block obvious directory traversal attempts.
    if ( strpos( $normalized_file_path, '..' ) !== false ||
         strpos( $normalized_file_path, '/./' ) !== false ||
         strpos( $normalized_file_path, '\\' ) !== false ) {
        return false;
    }
    return true;
}

/**
 * Resolves real file path, handling non-existent files securely.
 * 
 * @param string $normalized_file_path The normalized file path.
 * @return string|false Real file path on success, false on failure.
 */
function sse_resolve_file_path( $normalized_file_path ) {
    // Security: Only allow files with safe extensions
    if ( ! sse_validate_file_extension( $normalized_file_path ) ) {
        return false;
    }
    
    $real_file_path = realpath( $normalized_file_path );
    
    // If realpath fails for the file (doesn't exist), validate parent directory more securely
    if ($real_file_path === false) {
        return sse_resolve_nonexistent_file_path($normalized_file_path);
    }
    
    return $real_file_path;
}

/**
 * Validates file extension against allowed list.
 * 
 * @param string $file_path The file path to check.
 * @return bool True if extension is allowed, false otherwise.
 */
function sse_validate_file_extension( $file_path ) {
    $allowed_extensions = array( 'zip', 'sql' );
    $file_extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
    
    if ( ! in_array( $file_extension, $allowed_extensions, true ) ) {
        sse_log('Rejected file access - invalid extension: ' . $file_extension, 'security');
        return false;
    }
    
    return true;
}

/**
 * Validates and resolves parent directory for non-existent files.
 * 
 * @param string $normalized_file_path The normalized file path.
 * @return string|false Resolved file path or false on failure.
 */
function sse_resolve_nonexistent_file_path( $normalized_file_path ) {
    $upload_info = sse_get_upload_directory_info();
    if ($upload_info === false) {
        return false;
    }
    
    return sse_build_validated_file_path($normalized_file_path, $upload_info);
}

/**
 * Builds validated file path from components.
 * 
 * @param string $normalized_file_path The normalized file path.
 * @param array $upload_info Upload directory information.
 * @return string|false Real file path on success, false on failure.
 */
function sse_build_validated_file_path( $normalized_file_path, $upload_info ) {
    $parent_dir = dirname( $normalized_file_path );
    $filename = basename( $normalized_file_path );
    
    if ( ! sse_validate_parent_directory_safety( $parent_dir, $upload_info['basedir'] ) ) {
        return false;
    }
    
    return sse_construct_final_file_path($parent_dir, $filename, $upload_info['realpath']);
}

/**
 * Constructs final file path after validation.
 * 
 * @param string $parent_dir Parent directory path.
 * @param string $filename File name.
 * @param string $upload_real_path Upload directory real path.
 * @return string|false Final file path on success, false on failure.
 */
function sse_construct_final_file_path( $parent_dir, $filename, $upload_real_path ) {
    $real_parent_dir = sse_resolve_parent_directory($parent_dir, $upload_real_path);
    if ($real_parent_dir === false) {
        return false;
    }
    
    $sanitized_filename = sse_sanitize_filename($filename);
    if ($sanitized_filename === false) {
        return false;
    }
    
    return trailingslashit($real_parent_dir) . $sanitized_filename;
}

/**
 * Gets WordPress upload directory information with validation.
 * 
 * @return array|false Upload directory info array or false on failure.
 */
function sse_get_upload_directory_info() {
    $upload_dir = wp_upload_dir();
    if ( ! isset( $upload_dir['basedir'] ) || empty( $upload_dir['basedir'] ) ) {
        sse_log('Could not determine WordPress upload directory for validation', 'error');
        return false;
    }
    
    $wp_upload_dir = realpath( $upload_dir['basedir'] );
    if ( $wp_upload_dir === false ) {
        sse_log('Could not resolve WordPress upload directory real path', 'error');
        return false;
    }
    
    return array(
        'basedir' => $upload_dir['basedir'],
        'realpath' => $wp_upload_dir
    );
}

/**
 * Validates parent directory path safety.
 * 
 * @param string $parent_dir The parent directory path.
 * @param string $upload_dir The upload directory path.
 * @return bool True if safe, false otherwise.
 */
function sse_validate_parent_directory_safety( $parent_dir, $upload_dir ) {
    // Pre-validate that parent directory path looks safe
    if (strpos($parent_dir, '..') !== false || strpos($parent_dir, 'wp-config') !== false) {
        sse_log('Rejected unsafe parent directory path: ' . $parent_dir, 'security');
        return false;
    }
    
    // Ensure parent directory is within WordPress upload directory
    $norm_parent_dir = wp_normalize_path($parent_dir);
    $norm_upload_dir = wp_normalize_path($upload_dir);
    
    if (strpos($norm_parent_dir, $norm_upload_dir) !== 0) {
        sse_log('Parent directory not within WordPress upload directory: ' . $parent_dir, 'security');
        return false;
    }
    
    return true;
}

/**
 * Resolves and validates parent directory.
 * 
 * @param string $parent_dir The parent directory path.
 * @param string $upload_dir The upload directory path.
 * @return string|false Real parent directory path or false on failure.
 */
function sse_resolve_parent_directory( $parent_dir, $upload_dir ) {
    // Normalize and validate upload directory first
    $norm_upload_dir = wp_normalize_path($upload_dir);
    $real_upload_dir = realpath( $norm_upload_dir );
    if ($real_upload_dir === false) {
        sse_log('Upload directory cannot be resolved: ' . $upload_dir, 'security');
        return false;
    }
    
    // Normalize parent directory and perform basic validation
    $norm_parent_dir = wp_normalize_path($parent_dir);
    
    // Validate that normalized parent dir starts with normalized upload dir (before realpath)
    if (strpos($norm_parent_dir, $norm_upload_dir) !== 0) {
        sse_log('Parent directory not within normalized upload directory: ' . $parent_dir, 'security');
        return false;
    }
    
    // Now safe to resolve real path after validation - filesystem checks removed to prevent SSRF
    $real_parent_dir = realpath( $norm_parent_dir );
    if ($real_parent_dir === false) {
        sse_log('Parent directory resolution failed: ' . $parent_dir, 'security');
        return false;
    }
    
    // Final validation: ensure resolved path is still within upload directory
    if (strpos($real_parent_dir, $real_upload_dir) !== 0) {
        sse_log('Parent directory real path validation failed', 'security');
        return false;
    }
    
    return $real_parent_dir;
}

/**
 * Sanitizes filename to prevent directory traversal.
 * 
 * @param string $filename The filename to sanitize.
 * @return string|false Sanitized filename or false on failure.
 */
function sse_sanitize_filename( $filename ) {
    $filename = sanitize_file_name($filename);
    if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
        sse_log('Filename contains invalid characters: ' . $filename, 'security');
        return false;
    }
    
    return $filename;
}

/**
 * Checks if a file path is within the allowed base directory.
 * 
 * @param string|false $real_file_path The real file path or false if resolution failed.
 * @param string $real_base_dir The real base directory path.
 * @return bool True if file is within base directory, false otherwise.
 */
function sse_check_path_within_base( $real_file_path, $real_base_dir ) {
    // Ensure both paths are available for comparison
    if ($real_file_path === false) {
        return false;
    }
    
    // Ensure the file path starts with the base directory (with trailing slash)
    $real_base_dir = rtrim($real_base_dir, '/') . '/';
    $real_file_path = rtrim($real_file_path, '/') . '/';
    
    $is_within_base = strpos($real_file_path, $real_base_dir) === 0;
    
    if ( ! $is_within_base ) {
        sse_log('Path validation failed - path outside base directory. File: ' . $real_file_path . ', Base: ' . $real_base_dir, 'warning');
    }
    
    return $is_within_base;
}

/**
 * Validate that a file path is within the allowed directory
 * 
 * @param string $file_path The file path to validate
 * @param string $base_dir The base directory that the file should be within
 * @return bool True if the file path is safe, false otherwise
 */
function sse_validate_filepath($file_path, $base_dir) {
    // Sanitize and normalize paths to handle different separators and resolve . and ..
    $normalized_file_path = wp_normalize_path( wp_unslash( $file_path ) );
    $normalized_base_dir = wp_normalize_path( $base_dir );
    
    // Check for directory traversal attempts
    if ( ! sse_check_path_traversal( $normalized_file_path ) ) {
        return false;
    }
    
    // Resolve real paths to prevent directory traversal
    $real_file_path = sse_resolve_file_path($normalized_file_path);
    $real_base_dir = realpath( $normalized_base_dir );
    
    // Base directory must be resolvable for security
    if ($real_base_dir === false) {
        sse_log('Could not resolve base directory: ' . $normalized_base_dir, 'security');
        return false;
    }
    
    // Validate path is within base directory
    return sse_check_path_within_base($real_file_path, $real_base_dir);
}

/**
 * Validates export file for download operations.
 * 
 * @param string $filename The filename to validate.
 * @return array|WP_Error Result array with file data or WP_Error on failure.
 */
function sse_validate_export_file_for_download($filename) {
    $basic_validation = sse_validate_basic_export_file($filename);
    if (is_wp_error($basic_validation)) {
        return $basic_validation;
    }

    global $wp_filesystem;
    $file_path = $basic_validation['filepath'];

    // Check if file is readable
    if ( ! $wp_filesystem->is_readable( $file_path ) ) {
        return new WP_Error('file_not_readable', esc_html__('Export file not readable.', 'Simple-WP-Site-Exporter'));
    }
    
    // Get file size using WP Filesystem
    $file_size = $wp_filesystem->size($file_path);
    if ( ! $file_size ) {
        return new WP_Error('file_size_error', esc_html__('Could not determine file size.', 'Simple-WP-Site-Exporter'));
    }

    $basic_validation['filesize'] = $file_size;
    return $basic_validation;
}

/**
 * Validates export file for deletion operations.
 * 
 * @param string $filename The filename to validate.
 * @return array|WP_Error Result array with file data or WP_Error on failure.
 */
function sse_validate_export_file_for_deletion($filename) {
    return sse_validate_basic_export_file($filename);
}

/**
 * Performs basic validation common to both download and deletion operations.
 * 
 * @param string $filename The filename to validate.
 * @return array|WP_Error Result array with file data or WP_Error on failure.
 */
function sse_validate_basic_export_file($filename) {
    $basic_checks = sse_validate_filename_format($filename);
    if (is_wp_error($basic_checks)) {
        return $basic_checks;
    }
    
    $path_validation = sse_validate_export_file_path($filename);
    if (is_wp_error($path_validation)) {
        return $path_validation;
    }
    
    $existence_check = sse_validate_file_existence($path_validation['filepath']);
    if (is_wp_error($existence_check)) {
        return $existence_check;
    }
    
    $referer_check = sse_validate_request_referer();
    if (is_wp_error($referer_check)) {
        return $referer_check;
    }
    
    return $path_validation;
}

/**
 * Validates filename format and basic security checks.
 *
 * @param string $filename The filename to validate.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_filename_format($filename) {
    if (empty($filename)) {
        return new WP_Error('invalid_request', esc_html__('No file specified.', 'Simple-WP-Site-Exporter'));
    }
    
    // Prevent path traversal attacks
    if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
        return new WP_Error('invalid_filename', esc_html__('Invalid filename.', 'Simple-WP-Site-Exporter'));
    }
    
    // Validate that it's our export file format
    if ( ! preg_match( '/^site_export_sse_[a-f0-9]{7}_[a-zA-Z0-9_-]+_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $filename ) ) {
        return new WP_Error('invalid_format', esc_html__('Invalid export file format.', 'Simple-WP-Site-Exporter'));
    }
    
    return true;
}

/**
 * Validates export file path and directory security.
 *
 * @param string $filename The filename to validate.
 * @return array|WP_Error Result array with file data or WP_Error on failure.
 */
function sse_validate_export_file_path($filename) {
    // Get the full path to the file
    $upload_dir = wp_upload_dir();
    $export_dir = trailingslashit( $upload_dir['basedir'] ) . 'simple-wp-site-exporter-exports';
    $file_path = trailingslashit( $export_dir ) . $filename;
    
    // Validate the file path is within our export directory
    if ( ! sse_validate_filepath( $file_path, $export_dir ) ) {
        return new WP_Error('invalid_path', esc_html__('Invalid file path.', 'Simple-WP-Site-Exporter'));
    }
    
    return array(
        'filepath' => $file_path,
        'filename' => basename( $file_path ),
    );
}

/**
 * Validates file existence using WordPress filesystem.
 *
 * @param string $file_path The file path to check.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_file_existence($file_path) {
    global $wp_filesystem;
    
    // Initialize the WordPress filesystem
    if (empty($wp_filesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( ! WP_Filesystem() ) {
            sse_log('Failed to initialize WordPress filesystem API', 'error');
            return new WP_Error('filesystem_init_failed', esc_html__('Failed to initialize WordPress filesystem API.', 'Simple-WP-Site-Exporter'));
        }
    }
    
    // Check if file exists using WP Filesystem
    if ( ! $wp_filesystem->exists( $file_path ) ) {
        return new WP_Error('file_not_found', esc_html__('Export file not found.', 'Simple-WP-Site-Exporter'));
    }
    
    return true;
}

/**
 * Validates request referer for security.
 *
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_request_referer() {
    // Add referer check for request validation
    $referer = wp_get_referer();
    if ( ! $referer || strpos( $referer, admin_url() ) !== 0 ) {
        return new WP_Error('invalid_request_source', esc_html__('Invalid request source.', 'Simple-WP-Site-Exporter'));
    }
    
    return true;
}

/**
 * Validate export download request parameters
 * 
 * @param string $filename The filename to validate
 * @return array|WP_Error Result array with file path and size or WP_Error on failure
 */
function sse_validate_download_request($filename) {
    return sse_validate_export_file_for_download($filename);
}

/**
 * Validate file deletion request
 *
 * @param string $filename The filename to validate
 * @return array|WP_Error Result array with file path or WP_Error on failure
 */
function sse_validate_file_deletion($filename) {
    return sse_validate_export_file_for_deletion($filename);
}

// --- Secure Download Handler ---
/**
 * Handles secure download requests for export files.
 *
 * @return void
 */
function sse_handle_secure_download() { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! isset( $_GET['sse_secure_download'] ) || ! isset( $_GET['sse_download_nonce'] ) ) {
        return;
    }

    // Verify nonce.
    $nonce = sanitize_text_field( wp_unslash( $_GET['sse_download_nonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'sse_secure_download' ) ) {
        wp_die( esc_html__( 'Security check failed. Please try again.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    // Verify user capabilities.
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to download export files.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    $filename   = sanitize_file_name( wp_unslash( $_GET['sse_secure_download'] ) );
    $validation = sse_validate_download_request( $filename );

    if ( is_wp_error( $validation ) ) {
        wp_die( esc_html( $validation->get_error_message() ), 404 );
    }

    // Rate limiting check
    if ( ! sse_check_download_rate_limit() ) {
        wp_die( esc_html__( 'Too many download requests. Please wait before trying again.', 'Simple-WP-Site-Exporter' ), 429 );
    }

    sse_serve_file_download( $validation );
}
add_action( 'admin_init', 'sse_handle_secure_download' );

/**
 * Handles manual deletion of export files.
 *
 * @return void
 */
function sse_handle_export_deletion() { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( ! isset( $_GET['sse_delete_export'] ) || ! isset( $_GET['sse_delete_nonce'] ) ) {
        return;
    }

    // Verify nonce.
    $nonce = sanitize_text_field( wp_unslash( $_GET['sse_delete_nonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'sse_delete_export' ) ) {
        wp_die( esc_html__( 'Security check failed. Please try again.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    // Verify user capabilities.
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to delete export files.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    $filename   = sanitize_file_name( wp_unslash( $_GET['sse_delete_export'] ) );
    $validation = sse_validate_file_deletion( $filename );

    if ( is_wp_error( $validation ) ) {
        wp_die( esc_html( $validation->get_error_message() ), 404 );
    }

    if ( sse_safely_delete_file( $validation['filepath'] ) ) {
        add_action( 'admin_notices', function() {
            ?>
            <div class="notice notice-success is-dismissible">
                <p><?php esc_html_e( 'Export file successfully deleted.', 'Simple-WP-Site-Exporter' ); ?></p>
            </div>
            <?php
        });
        sse_log( 'Manual deletion of export file: ' . $validation['filepath'], 'info' );
        
        // Redirect back to the export page to prevent resubmission.
        wp_safe_redirect( admin_url( 'tools.php?page=simple-wp-site-exporter' ) );
        exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress standard: exit required after wp_safe_redirect.
    }
    
    add_action( 'admin_notices', function() {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php esc_html_e( 'Failed to delete export file.', 'Simple-WP-Site-Exporter' ); ?></p>
        </div>
        <?php
    });
    sse_log( 'Failed manual deletion of export file: ' . $validation['filepath'], 'error' );

    // Redirect back to the export page to prevent resubmission.
    wp_safe_redirect( admin_url( 'tools.php?page=simple-wp-site-exporter' ) );
    exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress standard: exit required after wp_safe_redirect.
}
add_action( 'admin_init', 'sse_handle_export_deletion' );

/**
 * Implements basic rate limiting for downloads.
 *
 * @return bool True if request is within rate limits, false otherwise.
 */
function sse_check_download_rate_limit() {
    $user_id = get_current_user_id();
    $rate_limit_key = 'sse_download_rate_limit_' . $user_id;
    $current_time = time();
    
    $last_download = get_transient( $rate_limit_key );
    
    // Allow one download per minute per user
    if ( false !== $last_download && is_numeric( $last_download ) && ( $current_time - $last_download ) < 60 ) {
        return false;
    }
    
    set_transient( $rate_limit_key, $current_time, 60 );
    return true;
}

/**
 * Validates file data for download operations.
 * 
 * @param array $fileData File data array to validate.
 * @return array Sanitized file data on success.
 * @throws Exception If validation fails.
 */
function sse_validate_download_file_data( $fileData ) {
    // Additional security validation
    if ( ! is_array( $fileData ) || 
         ! isset( $fileData['filepath'], $fileData['filename'], $fileData['filesize'] ) ) {
        sse_log( 'Invalid file data provided for download', 'error' );
        wp_die( esc_html__( 'Invalid file data.', 'Simple-WP-Site-Exporter' ) );
    }
    
    return array(
        'filepath' => sanitize_text_field( $fileData['filepath'] ),
        'filename' => sanitize_file_name( $fileData['filename'] ),
        'filesize' => absint( $fileData['filesize'] )
    );
}

/**
 * Validates file path and accessibility for download.
 * 
 * @param string $filepath The file path to validate.
 * @return void
 * @throws Exception If validation fails.
 */
function sse_validate_download_file_access( $filepath ) {
    // Security: Whitelist approach - only allow files in our controlled export directory
    $upload_dir = wp_upload_dir();
    $export_dir = trailingslashit( $upload_dir['basedir'] ) . 'simple-wp-site-exporter-exports';
    
    // Security: Additional validation to prevent SSRF attacks
    // Ensure file extension is in our allowed list
    $allowed_extensions = array( 'zip', 'sql' );
    $file_extension = strtolower( pathinfo( $filepath, PATHINFO_EXTENSION ) );
    if ( ! in_array( $file_extension, $allowed_extensions, true ) ) {
        sse_log( 'Security: Attempted access to file with disallowed extension: ' . $file_extension, 'security' );
        wp_die( esc_html__( 'Access denied - invalid file type.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Security: Ensure file path is within our controlled export directory (prevents SSRF)
    if ( ! sse_validate_filepath( $filepath, $export_dir ) ) {
        sse_log( 'Security: Attempted access to file outside allowed directory: ' . $filepath, 'security' );
        wp_die( esc_html__( 'Access denied.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Security: Final verification - file exists, is readable, and is a regular file (not symlink/device)
    if ( ! file_exists( $filepath ) || ! is_readable( $filepath ) || ! is_file( $filepath ) ) {
        sse_log( 'Security: File validation failed for: ' . $filepath, 'security' );
        wp_die( esc_html__( 'File not found.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Security: Additional check to prevent access to sensitive files
    $real_file_path = realpath( $filepath );
    if ( $real_file_path === false || $real_file_path !== $filepath ) {
        sse_log( 'Security: File path validation failed - potential symlink or path manipulation', 'security' );
        wp_die( esc_html__( 'Access denied.', 'Simple-WP-Site-Exporter' ) );
    }
}

/**
 * Sets appropriate headers for file download.
 * 
 * @param string $filename The filename for download.
 * @param int $filesize The file size in bytes.
 * @return void
 */
function sse_set_download_headers( $filename, $filesize ) {
    // Security: Set safe Content-Type based on file extension to prevent XSS
    $file_extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    switch ( $file_extension ) {
        case 'zip':
            $content_type = 'application/zip';
            break;
        case 'sql':
            $content_type = 'application/sql';
            break;
        default:
            // Security: Default to octet-stream for unknown types to prevent execution
            $content_type = 'application/octet-stream';
            break;
    }
    
    // Security: Set headers to prevent XSS and ensure proper download behavior
    header( 'Content-Type: ' . $content_type );
    header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
    header( 'Content-Length: ' . absint( $filesize ) );
    header( 'Cache-Control: no-cache, no-store, must-revalidate' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );
    header( 'X-Content-Type-Options: nosniff' ); // Security: Prevent MIME sniffing
    header( 'X-Frame-Options: DENY' ); // Security: Prevent framing
    
    // Disable output buffering for large files
    if ( ob_get_level() ) {
        ob_end_clean();
    }
}

/**
 * Validates file output security before serving download.
 * 
 * @param string $filepath The file path to validate.
 * @return bool True if file passes security checks, false otherwise.
 */
function sse_validate_file_output_security($filepath) {
    // Security: Final validation before file output to prevent SSRF
    $allowed_extensions = array( 'zip', 'sql' );
    $file_extension = strtolower( pathinfo( $filepath, PATHINFO_EXTENSION ) );
    if ( ! in_array( $file_extension, $allowed_extensions, true ) ) {
        sse_log( 'Security: Blocked attempt to serve file with invalid extension: ' . $file_extension, 'security' );
        wp_die( esc_html__( 'Access denied - invalid file type.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Security: Ensure file is within our controlled directory before serving
    $upload_dir = wp_upload_dir();
    $export_dir = trailingslashit( $upload_dir['basedir'] ) . 'simple-wp-site-exporter-exports';
    $real_export_dir = realpath( $export_dir );
    $real_file_path = realpath( $filepath );
    
    if ( $real_export_dir === false || $real_file_path === false || strpos( $real_file_path, $real_export_dir ) !== 0 ) {
        sse_log( 'Security: File not within controlled export directory: ' . $filepath, 'security' );
        wp_die( esc_html__( 'Access denied.', 'Simple-WP-Site-Exporter' ) );
    }
    
    return true;
}

/**
 * Outputs file content for download using WordPress filesystem.
 * 
 * @param string $filepath The validated file path.
 * @param string $filename The filename for logging.
 * @return void
 * @throws Exception If file cannot be served.
 */
function sse_output_file_content( $filepath, $filename ) {
    // Security: Validate file before output
    sse_validate_file_output_security($filepath);
    
    // Use readfile() for secure file download
    if ( function_exists( 'readfile' ) && is_readable( $filepath ) && is_file( $filepath ) ) {
        // Security: This readfile() call is safe - file path has been thoroughly validated.
        readfile( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Security validated export file download.
        sse_log( 'Secure file download served via readfile: ' . $filename, 'info' );
        exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Required to terminate script after file download.
    }
    
    sse_log( 'Failed to serve secure file download: ' . $filename, 'error' );
    wp_die( esc_html__( 'Unable to serve file download.', 'Simple-WP-Site-Exporter' ) );
}

/**
 * Serves a file download with enhanced security validation.
 *
 * @param array $fileData Validated file information array.
 * @return void
 */
function sse_serve_file_download( $fileData ) {
    // Validate and sanitize file data
    $sanitizedData = sse_validate_download_file_data( $fileData );
    
    // Validate file access permissions
    sse_validate_download_file_access( $sanitizedData['filepath'] );
    
    // Set download headers
    sse_set_download_headers( $sanitizedData['filename'], $sanitizedData['filesize'] );
    
    // Output file content
    sse_output_file_content( $sanitizedData['filepath'], $sanitizedData['filename'] );
}

/**
 * Safely get and validate WP-CLI path with enhanced security checks.
 *
 * @return string|WP_Error WP-CLI path on success, WP_Error on failure.
 */
function sse_get_safe_wp_cli_path() {
    // First try to get WP-CLI path
    $wp_cli_path = trim( shell_exec( 'which wp 2>/dev/null' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Required for WP-CLI path discovery: uses system 'which' command with constant parameters
    
    $basic_validation = sse_validate_wp_cli_path($wp_cli_path);
    if (is_wp_error($basic_validation)) {
        return $basic_validation;
    }
    
    $security_check = sse_validate_wp_cli_security($wp_cli_path);
    if (is_wp_error($security_check)) {
        return $security_check;
    }
    
    $binary_verification = sse_verify_wp_cli_binary($wp_cli_path);
    if (is_wp_error($binary_verification)) {
        return $binary_verification;
    }
    
    return $wp_cli_path;
}

/**
 * Validates basic WP-CLI path format.
 *
 * @param string $wp_cli_path The WP-CLI path to validate.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_wp_cli_path($wp_cli_path) {
    if ( empty( $wp_cli_path ) ) {
        return new WP_Error( 'wp_cli_not_found', __( 'WP-CLI not found on this server.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Validate path format (must be absolute path)
    if ( strpos( $wp_cli_path, '/' ) !== 0 && strpos( $wp_cli_path, '\\' ) !== 0 ) {
        return new WP_Error( 'wp_cli_not_absolute', __( 'WP-CLI path is not absolute.', 'Simple-WP-Site-Exporter' ) );
    }
    
    return true;
}

/**
 * Validates WP-CLI path for security issues.
 *
 * @param string $wp_cli_path The WP-CLI path to validate.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_wp_cli_security($wp_cli_path) {
    // Check if path looks suspicious (basic security check)
    if ( strpos( $wp_cli_path, ';' ) !== false || strpos( $wp_cli_path, '|' ) !== false || 
         strpos( $wp_cli_path, '&' ) !== false || strpos( $wp_cli_path, '$' ) !== false ) {
        return new WP_Error( 'wp_cli_suspicious', __( 'Suspicious characters detected in WP-CLI path.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Check if file exists and is executable
    if ( ! file_exists( $wp_cli_path ) ) {
        return new WP_Error( 'wp_cli_not_exists', __( 'WP-CLI executable not found at detected path.', 'Simple-WP-Site-Exporter' ) );
    }
    
    if ( ! is_executable( $wp_cli_path ) ) {
        return new WP_Error( 'wp_cli_not_executable', __( 'WP-CLI file is not executable.', 'Simple-WP-Site-Exporter' ) );
    }
    
    return true;
}

/**
 * Verifies that the binary is actually WP-CLI.
 *
 * @param string $wp_cli_path The WP-CLI path to verify.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_verify_wp_cli_binary($wp_cli_path) {
    // Additional security: verify it's actually WP-CLI by running --version
    $version_check = shell_exec( escapeshellarg( $wp_cli_path ) . ' --version 2>/dev/null' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- Required for WP-CLI binary verification: path is validated and escaped with escapeshellarg()
    if ( empty( $version_check ) || strpos( $version_check, 'WP-CLI' ) === false ) {
        return new WP_Error( 'wp_cli_invalid_binary', __( 'Detected file is not a valid WP-CLI executable.', 'Simple-WP-Site-Exporter' ) );
    }
    
    return true;
}