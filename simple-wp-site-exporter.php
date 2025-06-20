<?php
/*
Plugin Name: Simple WP Site Exporter
Description: Exports the site files and database as a zip archive.
Version: 1.7.0
Author: EngineScript
License: GPL v3 or later
Text Domain: Simple-WP-Site-Exporter
*/

// Prevent direct access. Note: Using return here instead of exit.
if ( ! defined( 'ABSPATH' ) ) {
    return; // Prevent direct access
}

// Define plugin version
if (!defined('ES_WP_SITE_EXPORTER_VERSION')) {
    define('ES_WP_SITE_EXPORTER_VERSION', '1.7.0');
}

/**
 * WordPress Core Classes Documentation
 * 
 * This plugin uses WordPress core classes which are automatically available
 * in the WordPress environment. These classes don't require explicit imports
 * or use statements as they are part of WordPress core.
 * 
 * Core classes used:
 * @see WP_Error - WordPress error handling class
 * @see ZipArchive - PHP ZipArchive class
 * @see RecursiveIteratorIterator - PHP SPL iterator
 * @see RecursiveDirectoryIterator - PHP SPL directory iterator
 * @see SplFileInfo - PHP SPL file information class
 * @see Exception - PHP base exception class
 * 
 * @SuppressWarnings(PHPMD.MissingImport)
 */

/**
 * Stores important log messages in database for review.
 * 
 * @param string $message The log message.
 * @param string $level The log level.
 * @return void
 */
function sse_store_log_in_database($message, $level) {
    // Store last 20 important messages in an option
    $logs = get_option('sse_error_logs', array());
    $logs[] = array(
        'time' => time(),
        'level' => $level,
        'message' => $message,
        'user_id' => get_current_user_id(),
        'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown'
    );
    
    // Keep only the most recent 20 logs
    if (count($logs) > 20) {
        $logs = array_slice($logs, -20);
    }
    
    update_option('sse_error_logs', $logs);
}

/**
 * Outputs log message to WordPress debug log or error_log.
 * 
 * @param string $formattedMessage The formatted log message.
 * @return void
 */
function sse_output_log_message($formattedMessage) {
    // Use WordPress logging (wp_debug_log is available in WP 5.1+)
    if (function_exists('wp_debug_log')) {
        wp_debug_log($formattedMessage);
    }
}

/**
 * Safely log plugin messages
 *
 * @param string $message The message to log
 * @param string $level   The log level (error, warning, info)
 */
function sse_log($message, $level = 'info') {
    // Check if WP_DEBUG is enabled
    if ( ! defined('WP_DEBUG') || ! WP_DEBUG ) {
        return;
    }

    // Format the message with a timestamp (using GMT to avoid timezone issues)
    $formattedMessage = sprintf(
        '[%s] [%s] %s: %s',
        gmdate('Y-m-d H:i:s'),
        'Simple WP Site Exporter',
        strtoupper($level),
        $message
    );
    
    // Only log if debug logging is enabled
    if ( ! defined('WP_DEBUG_LOG') || ! WP_DEBUG_LOG ) {
        return;
    }

    sse_output_log_message($formattedMessage);
        
    // Store logs in the database (errors and security events to prevent issues)
    if ($level === 'error' || $level === 'security') {
        sse_store_log_in_database($message, $level);
    }
}

/**
 * Safely get the PHP execution time limit
 * 
 * @return int Current PHP execution time limit in seconds
 */
function sse_get_execution_time_limit() {
    // Get the current execution time limit
    $maxExecTime = ini_get('max_execution_time');
    
    // Handle all possible return types from ini_get()
    if (false === $maxExecTime) {
        // ini_get failed
        return 30;
    }
    
    if ('' === $maxExecTime) {
        // Empty string returned
        return 30;
    }
    
    if (!is_numeric($maxExecTime)) {
        // Non-numeric value returned
        return 30;
    }
    
    return (int)$maxExecTime;
}

// --- Admin Menu ---
function sse_admin_menu() {
    add_management_page(
        esc_html__( 'Simple WP Site Exporter', 'Simple-WP-Site-Exporter' ), // Escaped title
        esc_html__( 'Site Exporter', 'Simple-WP-Site-Exporter' ),       // Escaped menu title
        'manage_options', // Capability required
        'simple-wp-site-exporter',
        'sse_exporter_page_html'
    );
}
add_action( 'admin_menu', 'sse_admin_menu' );

// --- Exporter Page HTML ---
function sse_exporter_page_html() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    $uploadDir = wp_upload_dir();
    if ( empty( $uploadDir['basedir'] ) ) {
         wp_die( esc_html__( 'Could not determine the WordPress upload directory.', 'Simple-WP-Site-Exporter' ) );
    }
    $exportDirName = 'simple-wp-site-exporter-exports';
    $exportDirPath = trailingslashit( $uploadDir['basedir'] ) . $exportDirName;
    $displayPath = str_replace( ABSPATH, '', $exportDirPath );
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        <p><?php esc_html_e( 'Click the button below to generate a zip archive containing your WordPress files and a database dump (.sql file).', 'Simple-WP-Site-Exporter' ); ?></p>
        <p><strong><?php esc_html_e( 'Warning:', 'Simple-WP-Site-Exporter' ); ?></strong> <?php esc_html_e( 'This can take a long time and consume significant server resources, especially on large sites. Ensure your server has sufficient disk space and execution time.', 'Simple-WP-Site-Exporter' ); ?></p>
        <p style="margin-top: 15px;">
            <?php
            // printf is standard in WordPress for translatable strings with placeholders. All variables are escaped.
            printf(
                /* translators: %s: directory path */
                esc_html__( 'Exported .zip files will be saved in the following directory on the server: %s', 'Simple-WP-Site-Exporter' ),
                '<code>' . esc_html( $displayPath ) . '</code>'
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
 */
function sse_handle_export() {
    if ( ! sse_validate_export_request() ) {
        return;
    }

    sse_prepare_execution_environment();
    
    $exportPaths = sse_setup_export_directories();
    if ( is_wp_error( $exportPaths ) ) {
        wp_die( esc_html( $exportPaths->get_error_message() ) );
    }

    $databaseFile = sse_export_database( $exportPaths['export_dir'] );
    if ( is_wp_error( $databaseFile ) ) {
        sse_show_error_notice( $databaseFile->get_error_message() );
        return;
    }

    $zipResult = sse_create_site_archive( $exportPaths, $databaseFile );
    if ( is_wp_error( $zipResult ) ) {
        sse_cleanup_files( array( $databaseFile['filepath'] ) );
        sse_show_error_notice( $zipResult->get_error_message() );
        return;
    }

    sse_cleanup_files( array( $databaseFile['filepath'] ) );
    sse_schedule_export_cleanup( $zipResult['filepath'] );
    sse_show_success_notice( $zipResult );
}

/**
 * Validates the export request for security and permissions.
 *
 * @return bool True if request is valid, false otherwise.
 */
function sse_validate_export_request() {
    $postAction = isset( $_POST['action'] ) ? sanitize_key( $_POST['action'] ) : '';
    if ( 'sse_export_site' !== $postAction ) {
        return false;
    }

    $postNonce = isset( $_POST['sse_export_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['sse_export_nonce'] ) ) : '';
    if ( ! $postNonce || ! wp_verify_nonce( $postNonce, 'sse_export_action' ) ) {
        wp_die( esc_html__( 'Nonce verification failed! Please try again.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to perform this action.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    return true;
}

/**
 * Prepares the execution environment for export operations.
 */
function sse_prepare_execution_environment() {
    $maxExecTime = sse_get_execution_time_limit();
    $targetExecTime = 1800; // 30 minutes in seconds
    
    if ($maxExecTime > 0 && $maxExecTime < $targetExecTime) {
        // Note: set_time_limit() is discouraged in WordPress plugins
        // Users should configure execution time limits at the server level
        sse_log("Current execution time limit ({$maxExecTime}s) may be insufficient for large exports. Consider increasing server limits.", 'warning');
    } else {
        sse_log("Execution time limit appears adequate for export operations", 'info');
    }
}

/**
 * Sets up export directories and returns path information.
 *
 * @return array|WP_Error Array of paths on success, WP_Error on failure.
 */
function sse_setup_export_directories() {
    $uploadDir = wp_upload_dir();
    if ( empty( $uploadDir['basedir'] ) || empty( $uploadDir['baseurl'] ) ) {
        return new WP_Error( 'upload_dir_error', __( 'Could not determine the WordPress upload directory or URL.', 'Simple-WP-Site-Exporter' ) );
    }

    $exportDirName = 'simple-wp-site-exporter-exports';
    $exportDir = trailingslashit( $uploadDir['basedir'] ) . $exportDirName;
    $exportUrl = trailingslashit( $uploadDir['baseurl'] ) . $exportDirName;
    
    wp_mkdir_p( $exportDir );
    sse_create_index_file( $exportDir );

    return array(
        'export_dir' => $exportDir,
        'export_url' => $exportUrl,
        'export_dir_name' => $exportDirName,
    );
}

/**
 * Creates an index.php file in the export directory to prevent directory listing.
 *
 * @param string $exportDir The export directory path.
 */
function sse_create_index_file( $exportDir ) {
    $indexFilePath = trailingslashit( $exportDir ) . 'index.php';
    if ( file_exists( $indexFilePath ) ) {
        return;
    }

    global $wpFilesystem;
    if ( ! $wpFilesystem ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( ! WP_Filesystem() ) {
            sse_log('Failed to initialize WordPress filesystem API', 'error');
            return;
        }
    }
    
    if ( $wpFilesystem && $wpFilesystem->is_writable( $exportDir ) ) {
        $wpFilesystem->put_contents(
            $indexFilePath,
            '<?php // Silence is golden.',
            FS_CHMOD_FILE
        );
        return;
    }
    
    sse_log('Failed to write index.php file or directory not writable: ' . $exportDir, 'error');
}

/**
 * Exports the database and returns file information.
 *
 * @param string $exportDir The directory to save the database dump.
 * @return array|WP_Error Array with file info on success, WP_Error on failure.
 */
function sse_export_database( $exportDir ) {
    $siteName = sanitize_file_name( get_bloginfo( 'name' ) );
    $timestamp = gmdate( 'Y-m-d_H-i-s' );
    $dbFilename = "db_dump_{$siteName}_{$timestamp}.sql";
    $dbFilepath = trailingslashit( $exportDir ) . $dbFilename;

    if ( ! function_exists('shell_exec') ) {
        return new WP_Error( 'shell_exec_disabled', __( 'shell_exec function is disabled on this server.', 'Simple-WP-Site-Exporter' ) );
    }

    // Enhanced WP-CLI path validation
    $wpCliPath = sse_get_safe_wp_cli_path();
    if ( is_wp_error( $wpCliPath ) ) {
        return $wpCliPath;
    }

    $command = sprintf(
        '%s db export %s --path=%s --allow-root',
        escapeshellarg($wpCliPath),
        escapeshellarg($dbFilepath),
        escapeshellarg(ABSPATH)
    );
    
    $output = shell_exec($command . ' 2>&1');
    
    if ( ! file_exists( $dbFilepath ) || filesize( $dbFilepath ) <= 0 ) {
        $errorMessage = ! empty($output) ? trim($output) : 'WP-CLI command failed silently.';
        return new WP_Error( 'db_export_failed', $errorMessage );
    }

    sse_log("Database export successful", 'info');
    return array(
        'filename' => $dbFilename,
        'filepath' => $dbFilepath,
    );
}

/**
 * Creates a site archive with database and files.
 *
 * @param array $exportPaths Export directory paths.
 * @param array $databaseFile Database file information.
 * @return array|WP_Error Archive info on success, WP_Error on failure.
 */
function sse_create_site_archive( $exportPaths, $databaseFile ) {
    if ( ! class_exists( 'ZipArchive' ) ) {
        return new WP_Error( 'zip_not_available', __( 'ZipArchive class is not available on your server. Cannot create zip file.', 'Simple-WP-Site-Exporter' ) );
    }

    $siteName = sanitize_file_name( get_bloginfo( 'name' ) );
    $timestamp = gmdate( 'Y-m-d_H-i-s' );
    $randomStr = substr( bin2hex( random_bytes(4) ), 0, 7 );
    $zipFilename = "site_export_sse_{$randomStr}_{$siteName}_{$timestamp}.zip";
    $zipFilepath = trailingslashit( $exportPaths['export_dir'] ) . $zipFilename;

    $zip = new ZipArchive();
    if ( $zip->open( $zipFilepath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== TRUE ) {
        return new WP_Error( 'zip_create_failed', sprintf( 
            /* translators: %s: filename */
            __( 'Could not create zip file at %s', 'Simple-WP-Site-Exporter' ), 
            basename($zipFilepath) 
        ) );
    }

    // Add database dump to zip
    if ( ! $zip->addFile( $databaseFile['filepath'], $databaseFile['filename'] ) ) {
        $zip->close();
        return new WP_Error( 'zip_db_add_failed', __( 'Failed to add database file to zip archive.', 'Simple-WP-Site-Exporter' ) );
    }

    $fileResult = sse_add_wordpress_files_to_zip( $zip, $exportPaths['export_dir'] );
    if ( is_wp_error( $fileResult ) ) {
        $zip->close();
        return $fileResult;
    }

    $zipCloseStatus = $zip->close();
    
    if ( ! $zipCloseStatus || ! file_exists( $zipFilepath ) ) {
        return new WP_Error( 'zip_finalize_failed', __( 'Failed to finalize or save the zip archive after processing files.', 'Simple-WP-Site-Exporter' ) );
    }

    sse_log("Site archive created successfully: " . $zipFilepath, 'info');
    return array(
        'filename' => $zipFilename,
        'filepath' => $zipFilepath,
    );
}

/**
 * Adds WordPress files to the zip archive.
 *
 * @param ZipArchive $zip The zip archive object.
 * @param string $exportDir The export directory to exclude.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_add_wordpress_files_to_zip( $zip, $exportDir ) {
    $sourcePath = realpath( ABSPATH );
    if ( ! $sourcePath ) {
        sse_log( "Could not resolve real path for ABSPATH. Using ABSPATH directly.", 'warning' );
        $sourcePath = ABSPATH;
    }

    try {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $sourcePath, RecursiveDirectoryIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $files as $fileInfo ) {
            sse_process_file_for_zip( $zip, $fileInfo, $sourcePath, $exportDir );
        }
    } catch ( Exception $e ) {
        return new WP_Error( 'file_iteration_failed', sprintf( 
            /* translators: %s: error message */
            __( 'Error during file processing: %s', 'Simple-WP-Site-Exporter' ), 
            $e->getMessage() 
        ) );
    }

    return true;
}

/**
 * Processes a single file for addition to the zip archive.
 *
 * @param ZipArchive $zip The zip archive object.
 * @param SplFileInfo $fileInfo File information object.
 * @param string $sourcePath The source path.
 * @param string $exportDir The export directory to exclude.
 * @return true|null True on success, null to continue, WP_Error on failure.
 */
/**
 * Process a single file for addition to ZIP archive.
 *
 * @param ZipArchive $zip ZIP archive object.
 * @param SplFileInfo $fileInfo File information object.
 * @param string $sourcePath Source directory path.
 * @param string $exportDir Export directory to exclude.
 * @return true|null True on success, null if skipped.
 */
function sse_process_file_for_zip( $zip, $fileInfo, $sourcePath, $exportDir ) {
    if ( ! $fileInfo->isReadable() ) {
        sse_log( "Skipping unreadable file/dir: " . $fileInfo->getPathname(), 'warning' );
        return null;
    }

    $file = $fileInfo->getRealPath();
    $pathname = $fileInfo->getPathname();
    $relativePath = ltrim( substr( $pathname, strlen( $sourcePath ) ), '/' );

    if ( empty($relativePath) ) {
        return null;
    }

    if ( sse_should_exclude_file( $pathname, $relativePath, $exportDir ) ) {
        return null;
    }

    return sse_add_file_to_zip( $zip, $fileInfo, $file, $pathname, $relativePath );
}

/**
 * Adds a file or directory to the zip archive.
 *
 * @param ZipArchive $zip The zip archive object.
 * @param SplFileInfo $fileInfo File information object.
 * @param string|false $file Real file path or false if getRealPath() failed.
 * @param string $pathname Original pathname.
 * @param string $relativePath Relative path in archive.
 * @return true
 */
function sse_add_file_to_zip( $zip, $fileInfo, $file, $pathname, $relativePath ) {
    if ( $fileInfo->isDir() ) {
        if ( ! $zip->addEmptyDir( $relativePath ) ) {
            sse_log( "Failed to add directory to zip: " . $relativePath, 'error' );
        }
        return true;
    }
    
    if ( $fileInfo->isFile() ) {
        // Use real path (getRealPath() must succeed for security)
        if ( false !== $file ) {
            $fileToAdd = $file;
        } else {
            sse_log( "Skipping file with unresolvable real path: " . $pathname, 'warning' );
            return true; // Skip this file but continue processing
        }
        
        if ( ! $zip->addFile( $fileToAdd, $relativePath ) ) {
            sse_log( "Failed to add file to zip: " . $relativePath . " (Source: " . $fileToAdd . ")", 'error' );
        }
    }
    
    return true;
}

/**
 * Determines if a file should be excluded from the export.
 *
 * @param string $pathname The full pathname.
 * @param string $relativePath The relative path.
 * @param string $exportDir The export directory to exclude.
 * @return bool True if file should be excluded.
 */
function sse_should_exclude_file( $pathname, $relativePath, $exportDir ) {
    // Exclude export directory
    if ( strpos( $pathname, $exportDir ) === 0 ) {
        return true;
    }

    // Exclude cache and temporary directories
    if ( preg_match( '#^wp-content/(cache|upgrade|temp)/#', $relativePath ) ) {
        return true;
    }

    // Exclude version control and system files
    if ( preg_match( '#(^|/)\.(git|svn|hg|DS_Store|htaccess|user\.ini)$#i', $relativePath ) ) {
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
 * @param array $zipResult The zip file information.
 */
function sse_show_success_notice( $zipResult ) {
    add_action( 'admin_notices', function() use ( $zipResult ) {
        $downloadUrl = add_query_arg(
            array(
                'sse_secure_download' => $zipResult['filename'],
                'sse_download_nonce' => wp_create_nonce('sse_secure_download')
            ),
            admin_url()
        );
        
        $deleteUrl = add_query_arg(
            array(
                'sse_delete_export' => $zipResult['filename'],
                'sse_delete_nonce' => wp_create_nonce('sse_delete_export')
            ),
            admin_url()
        );
        
        $displayZipPath = str_replace( ABSPATH, '[wp-root]/', $zipResult['filepath'] );
        $displayZipPath = preg_replace('|/+|', '/', $displayZipPath);
        ?>
        <div class="notice notice-success is-dismissible">
            <p>
                <?php esc_html_e( 'Site export successfully created!', 'Simple-WP-Site-Exporter' ); ?>
                <a href="<?php echo esc_url( $downloadUrl ); ?>" class="button" style="margin-left: 10px;">
                    <?php esc_html_e( 'Download Export File', 'Simple-WP-Site-Exporter' ); ?>
                </a>
                <a href="<?php echo esc_url( $deleteUrl ); ?>" class="button button-secondary" style="margin-left: 10px;" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this export file?', 'Simple-WP-Site-Exporter' ); ?>');">
                    <?php esc_html_e( 'Delete Export File', 'Simple-WP-Site-Exporter' ); ?>
                </a>
            </p>
            <p><small><?php
                printf(
                    /* translators: %s: file path */
                    esc_html__('File location: %s', 'Simple-WP-Site-Exporter'),
                    '<code title="' . esc_attr__('Path is relative to WordPress root directory', 'Simple-WP-Site-Exporter') . '">' . 
                    esc_html($displayZipPath) . '</code>'
                );
             ?></small></p>
        </div>
        <?php
    });
    sse_log("Export successful. File saved to " . $zipResult['filepath'], 'info');
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
 * @param string $zipFilepath The zip file path to schedule for deletion.
 */
function sse_schedule_export_cleanup( $zipFilepath ) {
    if ( ! wp_next_scheduled( 'sse_delete_export_file', array( $zipFilepath ) ) ) {
        wp_schedule_single_event( time() + (5 * 60), 'sse_delete_export_file', array( $zipFilepath ) );
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
function sse_safely_delete_file($filepath) {
    global $wpFilesystem;
    
    // Initialize the WordPress filesystem
    if (empty($wpFilesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( ! WP_Filesystem() ) {
            sse_log('Failed to initialize WordPress filesystem API', 'error');
            return false;
        }
    }
    
    if (!$wpFilesystem) {
        sse_log('WordPress filesystem API not available', 'error');
        return false;
    }
    
    // Check if the file exists using WP Filesystem
    if ($wpFilesystem->exists($filepath)) {
        // Delete the file using WordPress Filesystem API
        return $wpFilesystem->delete($filepath, false, 'f');
    }
    
    return false;
}

/**
 * Validates a file path for directory traversal attempts.
 * 
 * @param string $normalizedFilePath The normalized file path to check.
 * @return bool True if path is safe, false if contains traversal patterns.
 */
function sse_check_path_traversal($normalizedFilePath) {
    // Block obvious directory traversal attempts
    if ( strpos( $normalizedFilePath, '..' ) !== false || 
         strpos( $normalizedFilePath, '/./' ) !== false ||
         strpos( $normalizedFilePath, '\\' ) !== false ) {
        return false;
    }
    return true;
}

/**
 * Resolves real file path, handling non-existent files securely.
 * 
 * @param string $normalizedFilePath The normalized file path.
 * @return string|false Real file path on success, false on failure.
 */
function sse_resolve_file_path($normalizedFilePath) {
    // Security: Only allow files with safe extensions
    if (!sse_validate_file_extension($normalizedFilePath)) {
        return false;
    }
    
    $realFilePath = realpath($normalizedFilePath);
    
    // If realpath fails for the file (doesn't exist), validate parent directory more securely
    if ($realFilePath === false) {
        return sse_resolve_nonexistent_file_path($normalizedFilePath);
    }
    
    return $realFilePath;
}

/**
 * Validates file extension against allowed list.
 * 
 * @param string $filePath The file path to check.
 * @return bool True if extension is allowed, false otherwise.
 */
function sse_validate_file_extension($filePath) {
    $allowedExtensions = array('zip', 'sql');
    $fileExtension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    
    if (!in_array($fileExtension, $allowedExtensions, true)) {
        sse_log('Rejected file access - invalid extension: ' . $fileExtension, 'security');
        return false;
    }
    
    return true;
}

/**
 * Validates and resolves parent directory for non-existent files.
 * 
 * @param string $normalizedFilePath The normalized file path.
 * @return string|false Resolved file path or false on failure.
 */
function sse_resolve_nonexistent_file_path($normalizedFilePath) {
    $uploadInfo = sse_get_upload_directory_info();
    if ($uploadInfo === false) {
        return false;
    }
    
    return sse_build_validated_file_path($normalizedFilePath, $uploadInfo);
}

/**
 * Builds validated file path from components.
 * 
 * @param string $normalizedFilePath The normalized file path.
 * @param array $uploadInfo Upload directory information.
 * @return string|false Real file path on success, false on failure.
 */
function sse_build_validated_file_path($normalizedFilePath, $uploadInfo) {
    $parentDir = dirname($normalizedFilePath);
    $filename = basename($normalizedFilePath);
    
    if (!sse_validate_parent_directory_safety($parentDir, $uploadInfo['basedir'])) {
        return false;
    }
    
    return sse_construct_final_file_path($parentDir, $filename, $uploadInfo['realpath']);
}

/**
 * Constructs final file path after validation.
 * 
 * @param string $parentDir Parent directory path.
 * @param string $filename File name.
 * @param string $uploadRealPath Upload directory real path.
 * @return string|false Final file path on success, false on failure.
 */
function sse_construct_final_file_path($parentDir, $filename, $uploadRealPath) {
    $realParentDir = sse_resolve_parent_directory($parentDir, $uploadRealPath);
    if ($realParentDir === false) {
        return false;
    }
    
    $sanitizedFilename = sse_sanitize_filename($filename);
    if ($sanitizedFilename === false) {
        return false;
    }
    
    return trailingslashit($realParentDir) . $sanitizedFilename;
}

/**
 * Gets WordPress upload directory information with validation.
 * 
 * @return array|false Upload directory info array or false on failure.
 */
function sse_get_upload_directory_info() {
    $uploadDir = wp_upload_dir();
    if (!isset($uploadDir['basedir']) || empty($uploadDir['basedir'])) {
        sse_log('Could not determine WordPress upload directory for validation', 'error');
        return false;
    }
    
    $wpUploadDir = realpath($uploadDir['basedir']);
    if ($wpUploadDir === false) {
        sse_log('Could not resolve WordPress upload directory real path', 'error');
        return false;
    }
    
    return array(
        'basedir' => $uploadDir['basedir'],
        'realpath' => $wpUploadDir
    );
}

/**
 * Validates parent directory path safety.
 * 
 * @param string $parentDir The parent directory path.
 * @param string $uploadDir The upload directory path.
 * @return bool True if safe, false otherwise.
 */
function sse_validate_parent_directory_safety($parentDir, $uploadDir) {
    // Pre-validate that parent directory path looks safe
    if (strpos($parentDir, '..') !== false || strpos($parentDir, 'wp-config') !== false) {
        sse_log('Rejected unsafe parent directory path: ' . $parentDir, 'security');
        return false;
    }
    
    // Ensure parent directory is within WordPress upload directory
    $normalizedParentDir = wp_normalize_path($parentDir);
    $normalizedUploadDir = wp_normalize_path($uploadDir);
    
    if (strpos($normalizedParentDir, $normalizedUploadDir) !== 0) {
        sse_log('Parent directory not within WordPress upload directory: ' . $parentDir, 'security');
        return false;
    }
    
    return true;
}

/**
 * Resolves and validates parent directory.
 * 
 * @param string $parentDir The parent directory path.
 * @param string $uploadDir The upload directory path.
 * @return string|false Real parent directory path or false on failure.
 */
function sse_resolve_parent_directory($parentDir, $uploadDir) {
    // Normalize and validate upload directory first
    $normalizedUploadDir = wp_normalize_path($uploadDir);
    $realUploadDir = realpath($normalizedUploadDir);
    if ($realUploadDir === false) {
        sse_log('Upload directory cannot be resolved: ' . $uploadDir, 'security');
        return false;
    }
    
    // Normalize parent directory and perform basic validation
    $normalizedParentDir = wp_normalize_path($parentDir);
    
    // Validate that normalized parent dir starts with normalized upload dir (before realpath)
    if (strpos($normalizedParentDir, $normalizedUploadDir) !== 0) {
        sse_log('Parent directory not within normalized upload directory: ' . $parentDir, 'security');
        return false;
    }
    
    // Now safe to resolve real path after validation - filesystem checks removed to prevent SSRF
    $realParentDir = realpath($normalizedParentDir);
    if ($realParentDir === false) {
        sse_log('Parent directory resolution failed: ' . $parentDir, 'security');
        return false;
    }
    
    // Final validation: ensure resolved path is still within upload directory
    if (strpos($realParentDir, $realUploadDir) !== 0) {
        sse_log('Parent directory real path validation failed', 'security');
        return false;
    }
    
    return $realParentDir;
}

/**
 * Sanitizes filename to prevent directory traversal.
 * 
 * @param string $filename The filename to sanitize.
 * @return string|false Sanitized filename or false on failure.
 */
function sse_sanitize_filename($filename) {
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
 * @param string|false $realFilePath The real file path or false if resolution failed.
 * @param string $realBaseDir The real base directory path.
 * @return bool True if file is within base directory, false otherwise.
 */
function sse_check_path_within_base($realFilePath, $realBaseDir) {
    // Ensure both paths are available for comparison
    if ($realFilePath === false) {
        return false;
    }
    
    // Ensure the file path starts with the base directory (with trailing slash)
    $realBaseDir = rtrim($realBaseDir, '/') . '/';
    $realFilePath = rtrim($realFilePath, '/') . '/';
    
    $isWithinBase = strpos($realFilePath, $realBaseDir) === 0;
    
    if (!$isWithinBase) {
        sse_log('Path validation failed - path outside base directory. File: ' . $realFilePath . ', Base: ' . $realBaseDir, 'warning');
    }
    
    return $isWithinBase;
}

/**
 * Validate that a file path is within the allowed directory
 * 
 * @param string $filePath The file path to validate
 * @param string $baseDir The base directory that the file should be within
 * @return bool True if the file path is safe, false otherwise
 */
function sse_validate_filepath($filePath, $baseDir) {
    // Sanitize and normalize paths to handle different separators and resolve . and ..
    $normalizedFilePath = wp_normalize_path( wp_unslash( $filePath ) );
    $normalizedBaseDir = wp_normalize_path( $baseDir );
    
    // Check for directory traversal attempts
    if (!sse_check_path_traversal($normalizedFilePath)) {
        return false;
    }
    
    // Resolve real paths to prevent directory traversal
    $realFilePath = sse_resolve_file_path($normalizedFilePath);
    $realBaseDir = realpath($normalizedBaseDir);
    
    // Base directory must be resolvable for security
    if ($realBaseDir === false) {
        sse_log('Could not resolve base directory: ' . $normalizedBaseDir, 'security');
        return false;
    }
    
    // Validate path is within base directory
    return sse_check_path_within_base($realFilePath, $realBaseDir);
}

/**
 * Validates export file for download operations.
 * 
 * @param string $filename The filename to validate.
 * @return array|WP_Error Result array with file data or WP_Error on failure.
 */
function sse_validate_export_file_for_download($filename) {
    $basicValidation = sse_validate_basic_export_file($filename);
    if (is_wp_error($basicValidation)) {
        return $basicValidation;
    }

    global $wpFilesystem;
    $filePath = $basicValidation['filepath'];

    // Check if file is readable
    if (!$wpFilesystem->is_readable($filePath)) {
        return new WP_Error('file_not_readable', esc_html__('Export file not readable.', 'Simple-WP-Site-Exporter'));
    }
    
    // Get file size using WP Filesystem
    $fileSize = $wpFilesystem->size($filePath);
    if (!$fileSize) {
        return new WP_Error('file_size_error', esc_html__('Could not determine file size.', 'Simple-WP-Site-Exporter'));
    }

    $basicValidation['filesize'] = $fileSize;
    return $basicValidation;
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
    $basicChecks = sse_validate_filename_format($filename);
    if (is_wp_error($basicChecks)) {
        return $basicChecks;
    }
    
    $pathValidation = sse_validate_export_file_path($filename);
    if (is_wp_error($pathValidation)) {
        return $pathValidation;
    }
    
    $existenceCheck = sse_validate_file_existence($pathValidation['filepath']);
    if (is_wp_error($existenceCheck)) {
        return $existenceCheck;
    }
    
    $refererCheck = sse_validate_request_referer();
    if (is_wp_error($refererCheck)) {
        return $refererCheck;
    }
    
    return $pathValidation;
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
    if (!preg_match('/^site_export_sse_[a-f0-9]{7}_[a-zA-Z0-9_-]+_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.zip$/', $filename)) {
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
    $uploadDir = wp_upload_dir();
    $exportDir = trailingslashit( $uploadDir['basedir'] ) . 'simple-wp-site-exporter-exports';
    $filePath = trailingslashit( $exportDir ) . $filename;
    
    // Validate the file path is within our export directory
    if (!sse_validate_filepath($filePath, $exportDir)) {
        return new WP_Error('invalid_path', esc_html__('Invalid file path.', 'Simple-WP-Site-Exporter'));
    }
    
    return array(
        'filepath' => $filePath,
        'filename' => basename($filePath),
    );
}

/**
 * Validates file existence using WordPress filesystem.
 *
 * @param string $filePath The file path to check.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_file_existence($filePath) {
    global $wpFilesystem;
    
    // Initialize the WordPress filesystem
    if (empty($wpFilesystem)) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        if ( ! WP_Filesystem() ) {
            sse_log('Failed to initialize WordPress filesystem API', 'error');
            return new WP_Error('filesystem_init_failed', esc_html__('Failed to initialize WordPress filesystem API.', 'Simple-WP-Site-Exporter'));
        }
    }
    
    // Check if file exists using WP Filesystem
    if (!$wpFilesystem->exists($filePath)) {
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
    if (!$referer || strpos($referer, admin_url()) !== 0) {
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
 */
function sse_handle_secure_download() {
    if ( ! isset( $_GET['sse_secure_download'] ) || ! isset( $_GET['sse_download_nonce'] ) ) {
        return;
    }

    // Verify nonce
    $nonce = sanitize_text_field( wp_unslash( $_GET['sse_download_nonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'sse_secure_download' ) ) {
        wp_die( esc_html__( 'Security check failed. Please try again.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    // Verify user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to download export files.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    $filename = sanitize_file_name( wp_unslash( $_GET['sse_secure_download'] ) );
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
 */
function sse_handle_export_deletion() {
    if ( ! isset( $_GET['sse_delete_export'] ) || ! isset( $_GET['sse_delete_nonce'] ) ) {
        return;
    }

    // Verify nonce
    $nonce = sanitize_text_field( wp_unslash( $_GET['sse_delete_nonce'] ) );
    if ( ! wp_verify_nonce( $nonce, 'sse_delete_export' ) ) {
        wp_die( esc_html__( 'Security check failed. Please try again.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    // Verify user capabilities
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to delete export files.', 'Simple-WP-Site-Exporter' ), 403 );
    }

    $filename = sanitize_file_name( wp_unslash( $_GET['sse_delete_export'] ) );
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
        
        // Redirect back to the export page to prevent resubmission
        wp_safe_redirect( admin_url( 'tools.php?page=simple-wp-site-exporter' ) );
        exit;
    }
    
    add_action( 'admin_notices', function() {
        ?>
        <div class="notice notice-error is-dismissible">
            <p><?php esc_html_e( 'Failed to delete export file.', 'Simple-WP-Site-Exporter' ); ?></p>
        </div>
        <?php
    });
    sse_log( 'Failed manual deletion of export file: ' . $validation['filepath'], 'error' );

    // Redirect back to the export page to prevent resubmission
    wp_safe_redirect( admin_url( 'tools.php?page=simple-wp-site-exporter' ) );
    exit;
}
add_action( 'admin_init', 'sse_handle_export_deletion' );

/**
 * Implements basic rate limiting for downloads.
 *
 * @return bool True if request is within rate limits, false otherwise.
 */
function sse_check_download_rate_limit() {
    $userId = get_current_user_id();
    $rateLimitKey = 'sse_download_rate_limit_' . $userId;
    $currentTime = time();
    
    $lastDownload = get_transient( $rateLimitKey );
    
    // Allow one download per minute per user
    if ( false !== $lastDownload && is_numeric( $lastDownload ) && ( $currentTime - $lastDownload ) < 60 ) {
        return false;
    }
    
    set_transient( $rateLimitKey, $currentTime, 60 );
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
    if ( !is_array( $fileData ) || 
         !isset( $fileData['filepath'], $fileData['filename'], $fileData['filesize'] ) ) {
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
    $uploadDir = wp_upload_dir();
    $exportDir = trailingslashit( $uploadDir['basedir'] ) . 'simple-wp-site-exporter-exports';
    
    // Security: Additional validation to prevent SSRF attacks
    // Ensure file extension is in our allowed list
    $allowedExtensions = array('zip', 'sql');
    $fileExtension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedExtensions, true)) {
        sse_log( 'Security: Attempted access to file with disallowed extension: ' . $fileExtension, 'security' );
        wp_die( esc_html__( 'Access denied - invalid file type.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Security: Ensure file path is within our controlled export directory (prevents SSRF)
    if ( !sse_validate_filepath( $filepath, $exportDir ) ) {
        sse_log( 'Security: Attempted access to file outside allowed directory: ' . $filepath, 'security' );
        wp_die( esc_html__( 'Access denied.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Security: Final verification - file exists, is readable, and is a regular file (not symlink/device)
    if ( !file_exists( $filepath ) || !is_readable( $filepath ) || !is_file( $filepath ) ) {
        sse_log( 'Security: File validation failed for: ' . $filepath, 'security' );
        wp_die( esc_html__( 'File not found.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Security: Additional check to prevent access to sensitive files
    $realFilePath = realpath($filepath);
    if ($realFilePath === false || $realFilePath !== $filepath) {
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
    $fileExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    switch ($fileExtension) {
        case 'zip':
            $contentType = 'application/zip';
            break;
        case 'sql':
            $contentType = 'application/sql';
            break;
        default:
            // Security: Default to octet-stream for unknown types to prevent execution
            $contentType = 'application/octet-stream';
            break;
    }
    
    // Security: Set headers to prevent XSS and ensure proper download behavior
    header( 'Content-Type: ' . $contentType );
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
    $allowedExtensions = array('zip', 'sql');
    $fileExtension = strtolower(pathinfo($filepath, PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedExtensions, true)) {
        sse_log( 'Security: Blocked attempt to serve file with invalid extension: ' . $fileExtension, 'security' );
        wp_die( esc_html__( 'Access denied - invalid file type.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Security: Ensure file is within our controlled directory before serving
    $uploadDir = wp_upload_dir();
    $exportDir = trailingslashit( $uploadDir['basedir'] ) . 'simple-wp-site-exporter-exports';
    $realExportDir = realpath($exportDir);
    $realFilePath = realpath($filepath);
    
    if ($realExportDir === false || $realFilePath === false || strpos($realFilePath, $realExportDir) !== 0) {
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
        // Security: This readfile() call is safe - file path has been thoroughly validated
        readfile( $filepath ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Security validated export file download
        sse_log( 'Secure file download served via readfile: ' . $filename, 'info' );
        exit;
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
    $wpCliPath = trim( shell_exec( 'which wp 2>/dev/null' ) );
    
    $basicValidation = sse_validate_wp_cli_path($wpCliPath);
    if (is_wp_error($basicValidation)) {
        return $basicValidation;
    }
    
    $securityCheck = sse_validate_wp_cli_security($wpCliPath);
    if (is_wp_error($securityCheck)) {
        return $securityCheck;
    }
    
    $binaryVerification = sse_verify_wp_cli_binary($wpCliPath);
    if (is_wp_error($binaryVerification)) {
        return $binaryVerification;
    }
    
    return $wpCliPath;
}

/**
 * Validates basic WP-CLI path format.
 *
 * @param string $wpCliPath The WP-CLI path to validate.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_wp_cli_path($wpCliPath) {
    if ( empty( $wpCliPath ) ) {
        return new WP_Error( 'wp_cli_not_found', __( 'WP-CLI not found on this server.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Validate path format (must be absolute path)
    if ( strpos( $wpCliPath, '/' ) !== 0 && strpos( $wpCliPath, '\\' ) !== 0 ) {
        return new WP_Error( 'wp_cli_not_absolute', __( 'WP-CLI path is not absolute.', 'Simple-WP-Site-Exporter' ) );
    }
    
    return true;
}

/**
 * Validates WP-CLI path for security issues.
 *
 * @param string $wpCliPath The WP-CLI path to validate.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_validate_wp_cli_security($wpCliPath) {
    // Check if path looks suspicious (basic security check)
    if ( strpos( $wpCliPath, ';' ) !== false || strpos( $wpCliPath, '|' ) !== false || 
         strpos( $wpCliPath, '&' ) !== false || strpos( $wpCliPath, '$' ) !== false ) {
        return new WP_Error( 'wp_cli_suspicious', __( 'Suspicious characters detected in WP-CLI path.', 'Simple-WP-Site-Exporter' ) );
    }
    
    // Check if file exists and is executable
    if ( ! file_exists( $wpCliPath ) ) {
        return new WP_Error( 'wp_cli_not_exists', __( 'WP-CLI executable not found at detected path.', 'Simple-WP-Site-Exporter' ) );
    }
    
    if ( ! is_executable( $wpCliPath ) ) {
        return new WP_Error( 'wp_cli_not_executable', __( 'WP-CLI file is not executable.', 'Simple-WP-Site-Exporter' ) );
    }
    
    return true;
}

/**
 * Verifies that the binary is actually WP-CLI.
 *
 * @param string $wpCliPath The WP-CLI path to verify.
 * @return true|WP_Error True on success, WP_Error on failure.
 */
function sse_verify_wp_cli_binary($wpCliPath) {
    // Additional security: verify it's actually WP-CLI by running --version
    $versionCheck = shell_exec( escapeshellarg( $wpCliPath ) . ' --version 2>/dev/null' );
    if ( empty( $versionCheck ) || strpos( $versionCheck, 'WP-CLI' ) === false ) {
        return new WP_Error( 'wp_cli_invalid_binary', __( 'Detected file is not a valid WP-CLI executable.', 'Simple-WP-Site-Exporter' ) );
    }
    
    return true;
}