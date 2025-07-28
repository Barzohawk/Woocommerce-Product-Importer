<?php
/**
 * Plugin Name: Universal CSV Importer
 * Plugin URI: https://yoursite.com
 * Description: Import products from CSV files with images from Media Library for any custom post type
 * Version: 1.0.0
 * Author: Barzohawk
 * License: GPL v2 or later
 * Text Domain: universal-csv-importer
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// ===== CONFIGURATION START =====
// USER CUSTOMIZATION: Define your custom post type here
define('UCI_POST_TYPE', 'product'); // Change to your post type (e.g., 'jewelry_product', 'book', 'property')
define('UCI_POST_TYPE_LABEL', 'Products'); // Plural label for your post type
define('UCI_CAPABILITY', 'import_products'); // Custom capability name
// ===== CONFIGURATION END =====

// Define plugin constants
define('UCI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('UCI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('UCI_VERSION', '1.0.0');

// Include required files
require_once UCI_PLUGIN_DIR . 'includes/class-universal-csv-importer.php';
require_once UCI_PLUGIN_DIR . 'includes/class-universal-image-handler.php';
require_once UCI_PLUGIN_DIR . 'includes/class-universal-vendor-mapper.php';

// Initialize the plugin
add_action('plugins_loaded', 'uci_init_plugin');
function uci_init_plugin() {
    $plugin = new Universal_CSV_Importer();
    $plugin->init();
}

// Activation hook
register_activation_hook(__FILE__, 'uci_activate');
function uci_activate() {
    // Create upload directory for imports
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/csv-imports';
    
    if (!file_exists($import_dir)) {
        wp_mkdir_p($import_dir);
    }
    
    // Add capability to administrators
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap(UCI_CAPABILITY);
    }
    
    // Schedule cleanup of old import files
    if (!wp_next_scheduled('uci_cleanup_imports')) {
        wp_schedule_event(time(), 'daily', 'uci_cleanup_imports');
    }
    
    // Set default options
    add_option('uci_import_settings', array(
        'post_type' => UCI_POST_TYPE,
        'default_image_handling' => 'media_library',
        'cleanup_days' => 7,
        'batch_size' => 50,
        'default_post_status' => 'publish'
    ));
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'uci_deactivate');
function uci_deactivate() {
    // Clear scheduled events
    wp_clear_scheduled_hook('uci_cleanup_imports');
}

// Uninstall hook
register_uninstall_hook(__FILE__, 'uci_uninstall');
function uci_uninstall() {
    // Remove options
    delete_option('uci_import_settings');
    
    // Remove capabilities
    $role = get_role('administrator');
    if ($role) {
        $role->remove_cap(UCI_CAPABILITY);
    }
    
    // Clean up import directory
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/csv-imports';
    if (file_exists($import_dir)) {
        // Remove all CSV files
        $files = glob($import_dir . '/*.csv');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($import_dir);
    }
}

// Add cleanup cron job
add_action('uci_cleanup_imports', 'uci_cleanup_old_imports');
function uci_cleanup_old_imports() {
    $settings = get_option('uci_import_settings', array());
    $cleanup_days = isset($settings['cleanup_days']) ? intval($settings['cleanup_days']) : 7;
    
    $upload_dir = wp_upload_dir();
    $import_dir = $upload_dir['basedir'] . '/csv-imports';
    
    if (!file_exists($import_dir)) {
        return;
    }
    
    $files = glob($import_dir . '/*.csv');
    $cutoff_time = time() - ($cleanup_days * 24 * 60 * 60);
    
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoff_time) {
            unlink($file);
        }
    }
}

/**
 * USER CUSTOMIZATION NOTES:
 * 
 * To customize this plugin for your specific use case:
 * 
 * 1. Change the UCI_POST_TYPE constant to match your custom post type
 * 2. Update UCI_POST_TYPE_LABEL for the admin interface
 * 3. Modify the capability name in UCI_CAPABILITY if needed
 * 4. Customize the field mappings in the included class files
 * 
 * The plugin will automatically:
 * - Create an admin menu for your post type
 * - Handle CSV uploads and processing
 * - Map images from your Media Library
 * - Provide vendor-specific field mapping
 * - Clean up old import files automatically
 * 
 * Example customizations:
 * - For books: UCI_POST_TYPE = 'book', UCI_POST_TYPE_LABEL = 'Books'
 * - For properties: UCI_POST_TYPE = 'property', UCI_POST_TYPE_LABEL = 'Properties'
 * - For events: UCI_POST_TYPE = 'event', UCI_POST_TYPE_LABEL = 'Events'
 */