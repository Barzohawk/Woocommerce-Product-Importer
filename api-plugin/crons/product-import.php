<?php
/**
 * Universal Product Importer
 * 
 * This script imports product data from JSON files into WordPress
 * Works with any custom post type and field structure
 * 
 * Usage: php product-import.php [vendor-name] [offset] [limit]
 * Example: php product-import.php vendor1 0 100
 */

// ===== CONFIGURATION START =====
// Customize these settings for your site and products

// Path to WordPress installation
define('WP_PATH', '/home/your-site/public_html/wp-load.php');

// Post type for your products
define('PRODUCT_POST_TYPE', 'product'); // Change to your custom post type

// Import configuration for each vendor
$vendors = [
    'vendor1' => [
        'name' => 'Vendor 1',
        'data_file' => 'vendor1-products.json',
        'id_field' => 'sku', // Field in JSON that uniquely identifies products
        'field_mapping' => [
            // Map JSON fields to WordPress meta fields
            // 'json_field' => 'wp_meta_field'
            'sku' => 'vendor_sku',
            'name' => 'post_title',
            'description' => 'post_content',
            'price' => 'price',
            'category' => 'product_category',
            // Add your custom fields here
        ],
        'image_handling' => [
            'enabled' => true,
            'json_field' => 'images', // Field containing image data
            'type' => 'filename', // Options: 'filename', 'url', 'array'
        ],
        'transforms' => [
            // Optional: Transform data during import
            'category' => 'uppercase', // Options: 'uppercase', 'lowercase', 'custom'
            'price' => 'numeric',
        ]
    ],
    
    // Add more vendors following the same structure
];

// ===== CONFIGURATION END =====

// Setup
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Force WP to show errors in CLI
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', true);

// Load WordPress
require_once WP_PATH;

// Get command line arguments
$vendorKey = $argv[1] ?? 'vendor1';
$offset = isset($argv[2]) ? (int)$argv[2] : 0;
$limit = isset($argv[3]) ? (int)$argv[3] : 100;

if (!isset($vendors[$vendorKey])) {
    die("Unknown vendor: $vendorKey\n");
}

$config = $vendors[$vendorKey];
$jsonPath = __DIR__ . '/../data/' . $config['data_file'];

echo "📦 Importing {$config['name']} products (offset: $offset, limit: $limit)\n";
echo "📄 From file: $jsonPath\n";

// Check if file exists
if (!file_exists($jsonPath)) {
    die("❌ Data file not found: $jsonPath\n");
}

// Load and parse JSON
$jsonContent = file_get_contents($jsonPath);
$products = json_decode($jsonContent, true);

if (!is_array($products)) {
    die("❌ Invalid JSON data\n");
}

$total = count($products);
echo "📊 Total products in file: $total\n";

// Slice for pagination
$products = array_slice($products, $offset, $limit);
$count = count($products);
echo "🔄 Processing $count products...\n";

// Import results
$results = [
    'imported' => 0,
    'updated' => 0,
    'errors' => 0
];

// Process each product
foreach ($products as $index => $productData) {
    $num = $offset + $index + 1;
    
    // Get unique identifier
    $uniqueId = $productData[$config['id_field']] ?? null;
    if (!$uniqueId) {
        echo "⚠️  Product #$num: Missing {$config['id_field']}, skipping\n";
        $results['errors']++;
        continue;
    }
    
    echo "Processing #$num: $uniqueId... ";
    
    try {
        $result = import_single_product($productData, $config);
        if ($result === 'imported') {
            $results['imported']++;
            echo "✅ Imported\n";
        } elseif ($result === 'updated') {
            $results['updated']++;
            echo "🔄 Updated\n";
        } else {
            $results['errors']++;
            echo "❌ Error: $result\n";
        }
    } catch (Exception $e) {
        $results['errors']++;
        echo "❌ Exception: " . $e->getMessage() . "\n";
    }
}

// Summary
echo "\n✅ Import complete!\n";
echo "📊 Results: {$results['imported']} imported, {$results['updated']} updated, {$results['errors']} errors\n";

// ===== IMPORT FUNCTIONS =====

function import_single_product($data, $config) {
    // Map fields
    $mapped = map_product_fields($data, $config);
    
    // Check if product exists
    $existingId = find_existing_product($mapped['vendor_sku']);
    
    // Prepare post data
    $postData = [
        'post_type' => PRODUCT_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => $mapped['post_title'] ?? 'Untitled Product',
        'post_content' => $mapped['post_content'] ?? '',
    ];
    
    if ($existingId) {
        $postData['ID'] = $existingId;
        $postId = wp_update_post($postData);
        $action = 'updated';
    } else {
        $postId = wp_insert_post($postData);
        $action = 'imported';
    }
    
    if (is_wp_error($postId)) {
        return $postId->get_error_message();
    }
    
    // Save meta fields
    foreach ($mapped as $key => $value) {
        if (!in_array($key, ['post_title', 'post_content'])) {
            update_post_meta($postId, $key, $value);
        }
    }
    
    // Handle images if enabled
    if ($config['image_handling']['enabled']) {
        handle_product_images($postId, $data, $config);
    }
    
    return $action;
}

function map_product_fields($data, $config) {
    $mapped = [];
    
    foreach ($config['field_mapping'] as $jsonField => $wpField) {
        if (isset($data[$jsonField])) {
            $value = $data[$jsonField];
            
            // Apply transforms if defined
            if (isset($config['transforms'][$jsonField])) {
                $value = apply_transform($value, $config['transforms'][$jsonField]);
            }
            
            $mapped[$wpField] = $value;
        }
    }
    
    // Always set vendor
    $mapped['vendor'] = $config['name'];
    
    return $mapped;
}

function apply_transform($value, $transform) {
    switch ($transform) {
        case 'uppercase':
            return strtoupper($value);
        case 'lowercase':
            return strtolower($value);
        case 'numeric':
            return floatval($value);
        case 'custom':
            // USER CUSTOMIZATION: Add your custom transformations here
            return $value;
        default:
            return $value;
    }
}

function find_existing_product($sku) {
    if (empty($sku)) return false;
    
    $args = [
        'post_type' => PRODUCT_POST_TYPE,
        'meta_key' => 'vendor_sku',
        'meta_value' => $sku,
        'posts_per_page' => 1,
        'fields' => 'ids'
    ];
    
    $query = new WP_Query($args);
    return $query->posts[0] ?? false;
}

function handle_product_images($postId, $data, $config) {
    $imageField = $config['image_handling']['json_field'];
    if (!isset($data[$imageField])) return;
    
    $imageData = $data[$imageField];
    
    switch ($config['image_handling']['type']) {
        case 'filename':
            // Handle single filename or comma-separated filenames
            $filenames = is_array($imageData) ? $imageData : explode(',', $imageData);
            attach_images_from_media_library($postId, $filenames);
            break;
            
        case 'url':
            // USER CUSTOMIZATION: Implement URL download if needed
            break;
            
        case 'array':
            // USER CUSTOMIZATION: Handle complex image arrays
            break;
    }
}

function attach_images_from_media_library($postId, $filenames) {
    $attachmentIds = [];
    
    foreach ($filenames as $filename) {
        $filename = trim($filename);
        if (empty($filename)) continue;
        
        // Find attachment in media library
        $attachmentId = find_attachment_by_filename($filename);
        if ($attachmentId) {
            $attachmentIds[] = $attachmentId;
            
            // Set first image as featured
            if (empty($attachmentIds)) {
                set_post_thumbnail($postId, $attachmentId);
            }
        }
    }
    
    // Save gallery if multiple images
    if (count($attachmentIds) > 1) {
        update_post_meta($postId, '_product_image_gallery', implode(',', array_slice($attachmentIds, 1)));
    }
}

function find_attachment_by_filename($filename) {
    global $wpdb;
    
    // Remove path if included
    $filename = basename($filename);
    
    $attachmentId = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM $wpdb->posts 
        WHERE post_type = 'attachment' 
        AND guid LIKE %s 
        LIMIT 1",
        '%' . $wpdb->esc_like($filename) . '%'
    ));
    
    return $attachmentId ? intval($attachmentId) : false;
}