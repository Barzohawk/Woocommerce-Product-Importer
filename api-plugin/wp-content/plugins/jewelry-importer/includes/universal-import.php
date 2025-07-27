<?php
/**
 * Universal Product Import System
 * 
 * This file handles importing products from JSON files into WordPress.
 * To use: 
 * 1. Get a sample of your JSON data (do a test API pull)
 * 2. Look at the JSON structure
 * 3. Map your JSON fields to WordPress fields in the VENDOR CONFIGURATIONS section
 * 4. Run the import
 */

// ========================================
// VENDOR CONFIGURATIONS
// ========================================
// Add your vendor/source configurations here
// Each vendor should have its own configuration array

/**
 * EXAMPLE: Here's what a typical JSON product might look like:
 * 
 * {
 *   "sku": "RING-001",
 *   "name": "Diamond Engagement Ring",
 *   "description": "Beautiful 1ct diamond ring...",
 *   "price": "2999.99",
 *   "category": "Engagement Rings",
 *   "metal_type": "14k White Gold",
 *   "stone_details": {
 *     "center_stone": "Diamond",
 *     "carat_weight": "1.0",
 *     "clarity": "VS1",
 *     "color": "F"
 *   },
 *   "images": ["ring001-1.jpg", "ring001-2.jpg"],
 *   "in_stock": true,
 *   "vendor_specific_field": "some value"
 * }
 */

function get_vendor_configurations() {
    return [
        // ===== VENDOR 1 EXAMPLE =====
        'vendor1' => [
            'name' => 'Vendor 1 Display Name',
            'json_file' => 'vendor1-products.json',
            
            // Map JSON fields to WordPress fields
            // Left side: Your JSON field path (use dot notation for nested)
            // Right side: WordPress post field or meta key
            'field_mapping' => [
                // Required post fields
                'name' => 'post_title',              // JSON 'name' → post title
                'description' => 'post_content',      // JSON 'description' → post content
                
                // Meta fields (custom fields)
                'sku' => 'vendor_sku',               // JSON 'sku' → meta 'vendor_sku'
                'price' => 'price',                  // JSON 'price' → meta 'price'
                'category' => 'product_category',     // JSON 'category' → meta 'product_category'
                'metal_type' => 'metal_type',        // JSON 'metal_type' → meta 'metal_type'
                
                // Nested JSON fields (use dot notation)
                'stone_details.center_stone' => 'center_stone_type',
                'stone_details.carat_weight' => 'stone_weight',
                'stone_details.clarity' => 'stone_clarity',
                'stone_details.color' => 'stone_color',
                
                // Array fields
                'images' => 'product_images',        // Will be processed specially
                
                // Boolean fields
                'in_stock' => 'stock_status',        // true/false → 'instock'/'outofstock'
                
                // Fields that need transformation
                'vendor_specific_field' => 'custom_field_1',
            ],
            
            // Define how to handle special fields
            'special_handlers' => [
                'images' => 'handle_image_array',    // Custom function for images
                'in_stock' => 'handle_boolean_stock', // Convert boolean to string
                'price' => 'handle_price',           // Ensure proper decimal format
            ],
            
            // Define value transformations
            'transforms' => [
                'category' => 'uppercase',           // Transform to uppercase
                'price' => 'decimal',               // Ensure 2 decimal places
            ],
            
            // Which field uniquely identifies products (for updates)
            'unique_field' => 'sku',
            
            // Category/taxonomy mappings
            'taxonomy_mapping' => [
                'category' => 'product_cat',         // Meta field → taxonomy
                'brand' => 'product_brand',
            ],
        ],
        
        // ===== VENDOR 2 EXAMPLE (Different JSON Structure) =====
        'vendor2' => [
            'name' => 'Vendor 2 Display Name',
            'json_file' => 'vendor2-products.json',
            
            // Different JSON structure example
            'field_mapping' => [
                // Their JSON uses different field names
                'productCode' => 'vendor_sku',
                'productName' => 'post_title',
                'longDescription' => 'post_content',
                'retailPrice' => 'price',
                'productType' => 'product_category',
                
                // Maybe they structure data differently
                'attributes.metal' => 'metal_type',
                'attributes.mainStone.type' => 'center_stone_type',
                'attributes.mainStone.weight' => 'stone_weight',
                
                // Multiple image URLs in array
                'imageUrls' => 'product_images',
            ],
            
            'unique_field' => 'productCode',
        ],
        
        // ===== ADD YOUR VENDORS HERE =====
        // Copy one of the above examples and modify for your data
        
    ];
}

// ========================================
// IMPORT FUNCTIONS (Usually no need to modify below)
// ========================================

/**
 * Main import function
 * 
 * @param string $vendor_key The vendor configuration key
 * @param int $offset Start from this record
 * @param int $limit Number of records to process
 * @return array Result with success status and counts
 */
function import_products_from_json($vendor_key, $offset = 0, $limit = 100) {
    $configs = get_vendor_configurations();
    
    if (!isset($configs[$vendor_key])) {
        return [
            'success' => false,
            'message' => "Unknown vendor: $vendor_key. Check your configuration."
        ];
    }
    
    $config = $configs[$vendor_key];
    $json_file = IMPORTER_DATA_PATH . $config['json_file'];
    
    if (!file_exists($json_file)) {
        return [
            'success' => false,
            'message' => "JSON file not found: $json_file"
        ];
    }
    
    // Load and parse JSON
    $json_content = file_get_contents($json_file);
    $data = json_decode($json_content, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'message' => "JSON parse error: " . json_last_error_msg()
        ];
    }
    
    // Handle different JSON structures (array at root vs nested)
    $products = $data;
    if (isset($data['products'])) {
        $products = $data['products'];
    } elseif (isset($data['data'])) {
        $products = $data['data'];
    } elseif (isset($data['items'])) {
        $products = $data['items'];
    }
    
    // Slice for pagination
    $total = count($products);
    $products = array_slice($products, $offset, $limit);
    
    $results = [
        'success' => true,
        'imported' => 0,
        'updated' => 0,
        'errors' => 0,
        'total' => $total
    ];
    
    import_log("Starting import for {$config['name']}: $offset to " . ($offset + $limit) . " of $total");
    
    foreach ($products as $index => $product_data) {
        $result = import_single_product($product_data, $config);
        
        if ($result['status'] === 'imported') {
            $results['imported']++;
        } elseif ($result['status'] === 'updated') {
            $results['updated']++;
        } else {
            $results['errors']++;
            import_log("Error importing product at index $index: " . $result['message']);
        }
    }
    
    import_log("Import complete: {$results['imported']} imported, {$results['updated']} updated, {$results['errors']} errors");
    
    return $results;
}

/**
 * Import a single product
 */
function import_single_product($data, $config) {
    // Map the fields
    $mapped_data = map_product_fields($data, $config);
    
    // Check if product exists
    $unique_field = $config['unique_field'];
    $unique_value = get_nested_value($data, $unique_field);
    
    if (!$unique_value) {
        return [
            'status' => 'error',
            'message' => "Missing unique identifier: $unique_field"
        ];
    }
    
    $existing_id = find_product_by_meta('vendor_sku', $unique_value);
    
    // Prepare post data
    $post_data = [
        'post_type' => PRODUCT_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => $mapped_data['post_title'] ?? 'Untitled Product',
        'post_content' => $mapped_data['post_content'] ?? '',
    ];
    
    if ($existing_id) {
        $post_data['ID'] = $existing_id;
        $post_id = wp_update_post($post_data);
        $action = 'updated';
    } else {
        $post_id = wp_insert_post($post_data);
        $action = 'imported';
    }
    
    if (is_wp_error($post_id)) {
        return [
            'status' => 'error',
            'message' => $post_id->get_error_message()
        ];
    }
    
    // Save meta fields
    foreach ($mapped_data as $key => $value) {
        if (!in_array($key, ['post_title', 'post_content', 'post_status', 'post_type'])) {
            update_post_meta($post_id, $key, $value);
        }
    }
    
    // Handle taxonomies
    if (isset($config['taxonomy_mapping'])) {
        foreach ($config['taxonomy_mapping'] as $meta_field => $taxonomy) {
            if (isset($mapped_data[$meta_field])) {
                wp_set_object_terms($post_id, $mapped_data[$meta_field], $taxonomy);
            }
        }
    }
    
    // Handle images
    if (isset($mapped_data['product_images'])) {
        handle_product_images($post_id, $mapped_data['product_images']);
    }
    
    return [
        'status' => $action,
        'post_id' => $post_id,
        'message' => "Product $unique_value $action successfully"
    ];
}

/**
 * Map fields from JSON to WordPress
 */
function map_product_fields($data, $config) {
    $mapped = [];
    
    foreach ($config['field_mapping'] as $json_path => $wp_field) {
        $value = get_nested_value($data, $json_path);
        
        // Apply special handlers if defined
        if (isset($config['special_handlers'][$json_path])) {
            $handler = $config['special_handlers'][$json_path];
            if (function_exists($handler)) {
                $value = $handler($value);
            }
        }
        
        // Apply transforms if defined
        if (isset($config['transforms'][$json_path])) {
            $value = apply_transform($value, $config['transforms'][$json_path]);
        }
        
        if ($value !== null) {
            $mapped[$wp_field] = $value;
        }
    }
    
    // Always add vendor name
    $mapped['vendor'] = $config['name'];
    
    return $mapped;
}

/**
 * Get nested value from array using dot notation
 */
function get_nested_value($data, $path) {
    $keys = explode('.', $path);
    $value = $data;
    
    foreach ($keys as $key) {
        if (is_array($value) && isset($value[$key])) {
            $value = $value[$key];
        } else {
            return null;
        }
    }
    
    return $value;
}

/**
 * Apply transformations to values
 */
function apply_transform($value, $transform) {
    switch ($transform) {
        case 'uppercase':
            return strtoupper($value);
            
        case 'lowercase':
            return strtolower($value);
            
        case 'decimal':
            return number_format((float)$value, 2, '.', '');
            
        case 'integer':
            return intval($value);
            
        case 'boolean':
            return $value ? 'yes' : 'no';
            
        default:
            return $value;
    }
}

/**
 * Find product by meta value
 */
function find_product_by_meta($meta_key, $meta_value) {
    global $wpdb;
    
    $post_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta 
        WHERE meta_key = %s AND meta_value = %s 
        LIMIT 1",
        $meta_key,
        $meta_value
    ));
    
    return $post_id ? intval($post_id) : false;
}

/**
 * Handle product images
 */
function handle_product_images($post_id, $images) {
    if (!is_array($images)) {
        $images = [$images];
    }
    
    $attachment_ids = [];
    
    foreach ($images as $index => $image) {
        // Try to find image in media library
        $attachment_id = find_attachment_by_filename($image);
        
        if ($attachment_id) {
            $attachment_ids[] = $attachment_id;
            
            // Set first image as featured
            if ($index === 0) {
                set_post_thumbnail($post_id, $attachment_id);
            }
        }
    }
    
    // Save gallery
    if (count($attachment_ids) > 1) {
        update_post_meta($post_id, '_product_image_gallery', implode(',', array_slice($attachment_ids, 1)));
    }
}

/**
 * Find attachment by filename
 */
function find_attachment_by_filename($filename) {
    global $wpdb;
    
    $attachment_id = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM $wpdb->posts 
        WHERE post_type = 'attachment' 
        AND guid LIKE %s 
        LIMIT 1",
        '%' . $wpdb->esc_like($filename) . '%'
    ));
    
    return $attachment_id ? intval($attachment_id) : false;
}

/**
 * Write to import log
 */
function import_log($message) {
    $timestamp = date('[Y-m-d H:i:s] ');
    file_put_contents(IMPORTER_LOG_FILE, $timestamp . $message . "\n", FILE_APPEND);
}

// ========================================
// SPECIAL HANDLER FUNCTIONS
// ========================================
// Add your custom handler functions here

/**
 * Handle image array from JSON
 */
function handle_image_array($value) {
    if (is_string($value)) {
        return array_map('trim', explode(',', $value));
    }
    return $value;
}

/**
 * Handle boolean stock status
 */
function handle_boolean_stock($value) {
    return $value ? 'instock' : 'outofstock';
}

/**
 * Handle price formatting
 */
function handle_price($value) {
    return number_format((float)$value, 2, '.', '');
}

// ========================================
// TEST FUNCTIONS
// ========================================

/**
 * Test import for a single product by SKU
 */
function test_single_product_import($vendor_key, $sku) {
    $configs = get_vendor_configurations();
    
    if (!isset($configs[$vendor_key])) {
        return ['success' => false, 'message' => "Unknown vendor: $vendor_key"];
    }
    
    $config = $configs[$vendor_key];
    $json_file = IMPORTER_DATA_PATH . $config['json_file'];
    
    if (!file_exists($json_file)) {
        return ['success' => false, 'message' => "JSON file not found"];
    }
    
    $json_content = file_get_contents($json_file);
    $data = json_decode($json_content, true);
    
    // Find product with matching SKU
    $products = $data['products'] ?? $data['data'] ?? $data;
    $found = null;
    
    foreach ($products as $product) {
        if (get_nested_value($product, $config['unique_field']) === $sku) {
            $found = $product;
            break;
        }
    }
    
    if (!$found) {
        return ['success' => false, 'message' => "Product with SKU '$sku' not found"];
    }
    
    echo "\n=== ORIGINAL JSON DATA ===\n";
    print_r($found);
    
    echo "\n=== MAPPED DATA ===\n";
    $mapped = map_product_fields($found, $config);
    print_r($mapped);
    
    echo "\n=== IMPORT RESULT ===\n";
    $result = import_single_product($found, $config);
    print_r($result);
    
    return ['success' => true];
}