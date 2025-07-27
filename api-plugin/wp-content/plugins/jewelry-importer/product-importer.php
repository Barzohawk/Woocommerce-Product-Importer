<?php
/**
 * Plugin Name: Universal Product Importer
 * Description: Import products from JSON data with customizable field mapping
 * Version: 2.0
 * Author: Barzohawk
 * 
 * This plugin provides a framework for importing any type of product from JSON files.
 * Customize the field mappings and post type to match your needs.
 */

// ===== CONFIGURATION START =====
// Customize these settings for your specific use case

// Define your custom post type
define('PRODUCT_POST_TYPE', 'product'); // Change to your post type (e.g., 'jewelry_product', 'book', 'property')

// Define plugin paths
define('IMPORTER_PLUGIN_PATH', __DIR__);
define('IMPORTER_LOG_FILE', IMPORTER_PLUGIN_PATH . '/logs/import.log');
define('IMPORTER_DATA_PATH', IMPORTER_PLUGIN_PATH . '/data/');

// ===== CONFIGURATION END =====

// Load the unified import system
require_once IMPORTER_PLUGIN_PATH . '/includes/universal-import.php';

// Register your custom post type
add_action('init', function () {
    // USER CUSTOMIZATION: Modify this post type registration for your products
    register_post_type(PRODUCT_POST_TYPE, [
        'labels' => [
            'name' => 'Products',           // Change to your product type plural
            'singular_name' => 'Product',   // Change to your product type singular
        ],
        'public' => true,
        'has_archive' => true,
        'rewrite' => ['slug' => 'products'], // Change URL slug
        'show_in_rest' => true,
        'show_in_menu' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'custom-fields'],
        'taxonomies' => ['category', 'post_tag'], // Add/remove taxonomies as needed
    ]);
    
    // USER CUSTOMIZATION: Register custom taxonomies if needed
    /*
    register_taxonomy('product_brand', PRODUCT_POST_TYPE, [
        'label' => 'Brands',
        'hierarchical' => true,
        'show_in_rest' => true,
    ]);
    
    register_taxonomy('product_category', PRODUCT_POST_TYPE, [
        'label' => 'Product Categories',
        'hierarchical' => true,
        'show_in_rest' => true,
    ]);
    */
});

// Admin interface for viewing logs and running imports
add_action('admin_menu', function () {
    add_menu_page(
        'Product Importer',
        'Product Import',
        'manage_options',
        'product-importer',
        'render_importer_admin_page',
        'dashicons-upload',
        30
    );
});

function render_importer_admin_page() {
    ?>
    <div class="wrap">
        <h1>Product Importer</h1>
        
        <div class="notice notice-info">
            <p><strong>How to use this importer:</strong></p>
            <ol>
                <li>Place your JSON file in the <code>data/</code> directory</li>
                <li>Configure field mappings in <code>includes/universal-import.php</code></li>
                <li>Run import via WP-CLI: <code>wp product-import run vendor-name</code></li>
                <li>Or set up CRON jobs to run automatically</li>
            </ol>
        </div>
        
        <?php if (isset($_GET['action']) && $_GET['action'] === 'view-sample'): ?>
            <h2>Sample JSON Structure</h2>
            <p>Here's an example of the expected JSON format:</p>
            <pre style="background: #f0f0f0; padding: 15px; overflow: auto;">
{
    "products": [
        {
            "sku": "PROD-001",
            "title": "Product Name",
            "description": "Product description",
            "price": "99.99",
            "category": "Category Name",
            "brand": "Brand Name",
            "images": ["image1.jpg", "image2.jpg"],
            "custom_field_1": "Value 1",
            "custom_field_2": "Value 2"
        }
    ]
}
            </pre>
            <p><a href="?page=product-importer" class="button">Back to Logs</a></p>
        <?php else: ?>
            <p>
                <a href="?page=product-importer&action=view-sample" class="button">View Sample JSON</a>
                <a href="<?php echo admin_url('edit.php?post_type=' . PRODUCT_POST_TYPE); ?>" class="button">View Products</a>
            </p>
            
            <h2>Import Log</h2>
            <?php
            if (file_exists(IMPORTER_LOG_FILE)) {
                $log_content = file_get_contents(IMPORTER_LOG_FILE);
                $log_lines = array_slice(explode("\n", $log_content), -100); // Last 100 lines
                ?>
                <textarea readonly style="width:100%; height:400px; font-family:monospace; font-size:12px;">
<?php echo esc_html(implode("\n", $log_lines)); ?>
                </textarea>
                <p>
                    <em>Showing last 100 lines. Full log: <?php echo esc_html(IMPORTER_LOG_FILE); ?></em>
                </p>
                <?php
            } else {
                echo '<p style="color:orange;">No import log found. Logs will appear after first import.</p>';
            }
            ?>
        <?php endif; ?>
    </div>
    <?php
}

// Register WP-CLI commands if available
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('product-import', 'Product_Import_Command');
    
    class Product_Import_Command {
        /**
         * Run product import
         * 
         * ## OPTIONS
         * 
         * <vendor>
         * : The vendor/source name (matches config in universal-import.php)
         * 
         * [--offset=<offset>]
         * : Start from this record number
         * ---
         * default: 0
         * ---
         * 
         * [--limit=<limit>]
         * : Number of products to import
         * ---
         * default: 100
         * ---
         * 
         * ## EXAMPLES
         * 
         *     wp product-import run vendor1
         *     wp product-import run vendor1 --offset=100 --limit=50
         * 
         * @when after_wp_load
         */
        public function run($args, $assoc_args) {
            $vendor = $args[0];
            $offset = isset($assoc_args['offset']) ? intval($assoc_args['offset']) : 0;
            $limit = isset($assoc_args['limit']) ? intval($assoc_args['limit']) : 100;
            
            WP_CLI::log("Starting import for: $vendor");
            WP_CLI::log("Offset: $offset, Limit: $limit");
            
            // Call the import function from universal-import.php
            $result = import_products_from_json($vendor, $offset, $limit);
            
            if ($result['success']) {
                WP_CLI::success("Import complete: {$result['imported']} imported, {$result['updated']} updated, {$result['errors']} errors");
            } else {
                WP_CLI::error($result['message']);
            }
        }
        
        /**
         * Test import with a single product
         * 
         * ## OPTIONS
         * 
         * <vendor>
         * : The vendor/source name
         * 
         * <sku>
         * : The SKU to test
         * 
         * ## EXAMPLES
         * 
         *     wp product-import test vendor1 PROD-001
         */
        public function test($args) {
            $vendor = $args[0];
            $sku = $args[1];
            
            WP_CLI::log("Testing import for SKU: $sku from $vendor");
            
            $result = test_single_product_import($vendor, $sku);
            
            if ($result['success']) {
                WP_CLI::success("Test complete. Check output above.");
            } else {
                WP_CLI::error($result['message']);
            }
        }
    }
}