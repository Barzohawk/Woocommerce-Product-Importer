<?php
/**
 * Universal CSV Importer Main Class
 * 
 * Handles the core CSV import functionality for any custom post type
 */

if (!defined('ABSPATH')) {
    exit;
}

class Universal_CSV_Importer {
    
    private $image_handler;
    private $vendor_mapper;
    
    public function __construct() {
        $this->image_handler = new Universal_Image_Handler();
        $this->vendor_mapper = new Universal_Vendor_Mapper();
    }
    
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_uci_process_import', array($this, 'ajax_process_import'));
        add_action('wp_ajax_uci_upload_csv', array($this, 'ajax_upload_csv'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=' . UCI_POST_TYPE,
            'CSV Import',
            'CSV Import',
            UCI_CAPABILITY,
            'csv-import',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'csv-import') === false) {
            return;
        }
        
        wp_enqueue_script('uci-admin', UCI_PLUGIN_URL . 'assets/admin.js', array('jquery'), UCI_VERSION, true);
        wp_enqueue_style('uci-admin', UCI_PLUGIN_URL . 'assets/admin.css', array(), UCI_VERSION);
        
        wp_localize_script('uci-admin', 'uci_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('uci_import_nonce'),
            'post_type_label' => UCI_POST_TYPE_LABEL
        ));
    }
    
    /**
     * Render admin page
     */
    public function admin_page() {
        $settings = get_option('uci_import_settings', array());
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(UCI_POST_TYPE_LABEL); ?> CSV Import</h1>
            
            <div class="uci-import-container">
                <!-- Upload Section -->
                <div class="uci-section">
                    <h2>Step 1: Upload CSV File</h2>
                    <form id="uci-upload-form" method="post" enctype="multipart/form-data">
                        <?php wp_nonce_field('uci_upload_nonce', 'uci_upload_nonce'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">CSV File</th>
                                <td>
                                    <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
                                    <p class="description">Select a CSV file to import. Maximum file size: <?php echo wp_max_upload_size(); ?> bytes.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Vendor/Source</th>
                                <td>
                                    <select name="vendor" id="vendor" required>
                                        <option value="">Select Vendor...</option>
                                        <?php
                                        // USER CUSTOMIZATION: Add your vendors here
                                        $vendors = $this->vendor_mapper->get_available_vendors();
                                        foreach ($vendors as $key => $label) {
                                            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
                                        }
                                        ?>
                                    </select>
                                    <p class="description">Select the vendor/source to apply correct field mappings.</p>
                                </td>
                            </tr>
                        </table>
                        <p class="submit">
                            <input type="submit" name="upload_csv" class="button-primary" value="Upload & Preview">
                        </p>
                    </form>
                </div>
                
                <!-- Preview Section -->
                <div id="uci-preview-section" class="uci-section" style="display:none;">
                    <h2>Step 2: Preview & Configure</h2>
                    <div id="uci-preview-content"></div>
                </div>
                
                <!-- Import Section -->
                <div id="uci-import-section" class="uci-section" style="display:none;">
                    <h2>Step 3: Import Progress</h2>
                    <div id="uci-progress-bar">
                        <div id="uci-progress-fill"></div>
                    </div>
                    <div id="uci-import-status"></div>
                    <div id="uci-import-log"></div>
                </div>
            </div>
        </div>
        
        <style>
        .uci-import-container { max-width: 1200px; }
        .uci-section { 
            background: #fff; 
            border: 1px solid #ccd0d4; 
            padding: 20px; 
            margin: 20px 0; 
            border-radius: 4px; 
        }
        #uci-progress-bar { 
            width: 100%; 
            height: 20px; 
            background: #f1f1f1; 
            border-radius: 10px; 
            overflow: hidden; 
            margin: 10px 0; 
        }
        #uci-progress-fill { 
            height: 100%; 
            background: #0073aa; 
            width: 0%; 
            transition: width 0.3s; 
        }
        .uci-field-mapping { 
            display: grid; 
            grid-template-columns: 1fr 1fr 1fr; 
            gap: 10px; 
            margin: 10px 0; 
            align-items: center; 
        }
        .uci-preview-table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 20px 0; 
        }
        .uci-preview-table th, .uci-preview-table td { 
            border: 1px solid #ddd; 
            padding: 8px; 
            text-align: left; 
        }
        .uci-preview-table th { 
            background-color: #f2f2f2; 
        }
        </style>
        <?php
    }
    
    /**
     * Handle CSV upload via AJAX
     */
    public function ajax_upload_csv() {
        check_ajax_referer('uci_import_nonce', 'nonce');
        
        if (!current_user_can(UCI_CAPABILITY)) {
            wp_die('Insufficient permissions');
        }
        
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('File upload failed');
        }
        
        $vendor = sanitize_text_field($_POST['vendor']);
        if (empty($vendor)) {
            wp_send_json_error('Please select a vendor');
        }
        
        // Move uploaded file to import directory
        $upload_dir = wp_upload_dir();
        $import_dir = $upload_dir['basedir'] . '/csv-imports';
        $filename = 'import_' . time() . '_' . $vendor . '.csv';
        $filepath = $import_dir . '/' . $filename;
        
        if (!move_uploaded_file($_FILES['csv_file']['tmp_name'], $filepath)) {
            wp_send_json_error('Failed to save uploaded file');
        }
        
        // Parse CSV and generate preview
        $preview_data = $this->parse_csv_preview($filepath, $vendor);
        
        wp_send_json_success(array(
            'filename' => $filename,
            'preview' => $preview_data,
            'total_rows' => $preview_data['total_rows'],
            'vendor' => $vendor
        ));
    }
    
    /**
     * Handle import processing via AJAX
     */
    public function ajax_process_import() {
        check_ajax_referer('uci_import_nonce', 'nonce');
        
        if (!current_user_can(UCI_CAPABILITY)) {
            wp_die('Insufficient permissions');
        }
        
        $filename = sanitize_file_name($_POST['filename']);
        $vendor = sanitize_text_field($_POST['vendor']);
        $batch_size = intval($_POST['batch_size']) ?: 10;
        $offset = intval($_POST['offset']) ?: 0;
        
        $upload_dir = wp_upload_dir();
        $filepath = $upload_dir['basedir'] . '/csv-imports/' . $filename;
        
        if (!file_exists($filepath)) {
            wp_send_json_error('CSV file not found');
        }
        
        $result = $this->process_csv_batch($filepath, $vendor, $offset, $batch_size);
        
        wp_send_json_success($result);
    }
    
    /**
     * Parse CSV file and generate preview
     */
    private function parse_csv_preview($filepath, $vendor) {
        $preview = array(
            'headers' => array(),
            'sample_rows' => array(),
            'total_rows' => 0,
            'field_mappings' => array()
        );
        
        if (($handle = fopen($filepath, 'r')) !== FALSE) {
            // Get headers
            $preview['headers'] = fgetcsv($handle);
            
            // Get first 5 rows for preview
            $row_count = 0;
            while (($data = fgetcsv($handle)) !== FALSE && $row_count < 5) {
                $preview['sample_rows'][] = $data;
                $row_count++;
            }
            
            // Count total rows
            while (fgetcsv($handle) !== FALSE) {
                $row_count++;
            }
            $preview['total_rows'] = $row_count;
            
            fclose($handle);
        }
        
        // Get field mappings for this vendor
        $preview['field_mappings'] = $this->vendor_mapper->get_field_mappings($vendor);
        
        return $preview;
    }
    
    /**
     * Process a batch of CSV rows
     */
    private function process_csv_batch($filepath, $vendor, $offset, $batch_size) {
        $processed = 0;
        $imported = 0;
        $updated = 0;
        $errors = array();
        
        if (($handle = fopen($filepath, 'r')) !== FALSE) {
            $headers = fgetcsv($handle); // Skip header row
            
            // Skip to offset
            for ($i = 0; $i < $offset; $i++) {
                if (fgetcsv($handle) === FALSE) break;
            }
            
            // Process batch
            while ($processed < $batch_size && ($data = fgetcsv($handle)) !== FALSE) {
                $row_data = array_combine($headers, $data);
                
                try {
                    $result = $this->import_single_product($row_data, $vendor);
                    if ($result['action'] === 'imported') {
                        $imported++;
                    } elseif ($result['action'] === 'updated') {
                        $updated++;
                    }
                } catch (Exception $e) {
                    $errors[] = 'Row ' . ($offset + $processed + 1) . ': ' . $e->getMessage();
                }
                
                $processed++;
            }
            
            fclose($handle);
        }
        
        return array(
            'processed' => $processed,
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors,
            'continue' => $processed === $batch_size
        );
    }
    
    /**
     * Import a single product from CSV row
     */
    private function import_single_product($row_data, $vendor) {
        // Get field mappings for this vendor
        $mappings = $this->vendor_mapper->get_field_mappings($vendor);
        
        // Extract mapped data
        $product_data = array();
        foreach ($mappings as $csv_field => $post_field) {
            if (isset($row_data[$csv_field])) {
                $product_data[$post_field] = $row_data[$csv_field];
            }
        }
        
        // USER CUSTOMIZATION: Modify required fields as needed
        $required_fields = array('title', 'sku');
        foreach ($required_fields as $field) {
            if (empty($product_data[$field])) {
                throw new Exception("Missing required field: $field");
            }
        }
        
        // Check if product already exists
        $existing_post = $this->find_existing_product($product_data['sku'], $vendor);
        
        $post_data = array(
            'post_title' => sanitize_text_field($product_data['title']),
            'post_content' => wp_kses_post($product_data['description'] ?? ''),
            'post_status' => 'publish',
            'post_type' => UCI_POST_TYPE
        );
        
        if ($existing_post) {
            $post_data['ID'] = $existing_post->ID;
            $post_id = wp_update_post($post_data);
            $action = 'updated';
        } else {
            $post_id = wp_insert_post($post_data);
            $action = 'imported';
        }
        
        if (is_wp_error($post_id)) {
            throw new Exception('Failed to save post: ' . $post_id->get_error_message());
        }
        
        // Save meta fields
        foreach ($product_data as $key => $value) {
            if (!in_array($key, array('title', 'description'))) {
                update_post_meta($post_id, $key, sanitize_text_field($value));
            }
        }
        
        // Save vendor info
        update_post_meta($post_id, 'vendor', $vendor);
        update_post_meta($post_id, 'import_date', current_time('mysql'));
        
        // Handle image import
        if (!empty($product_data['image'])) {
            $this->image_handler->process_product_image($post_id, $product_data['image']);
        }
        
        return array('action' => $action, 'post_id' => $post_id);
    }
    
    /**
     * Find existing product by SKU
     */
    private function find_existing_product($sku, $vendor) {
        $posts = get_posts(array(
            'post_type' => UCI_POST_TYPE,
            'meta_query' => array(
                array(
                    'key' => 'sku',
                    'value' => $sku,
                    'compare' => '='
                ),
                array(
                    'key' => 'vendor',
                    'value' => $vendor,
                    'compare' => '='
                )
            ),
            'posts_per_page' => 1
        ));
        
        return !empty($posts) ? $posts[0] : null;
    }
}