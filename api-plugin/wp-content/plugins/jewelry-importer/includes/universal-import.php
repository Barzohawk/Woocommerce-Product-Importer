<?php
/**
 * Universal Import System Template
 * 
 * This is a clean template for importing products from JSON data.
 * Customize the field mappings and vendor logic for your specific needs.
 * 
 * Based on structure from: import-zeghani.php, import-ashi.php, import-benchmark.php, import-simong.php
 */

// Ensure directories exist
if (!is_dir(IMPORTER_PLUGIN_PATH . '/logs')) {
    wp_mkdir_p(IMPORTER_PLUGIN_PATH . '/logs');
}
if (!is_dir(IMPORTER_DATA_PATH)) {
    wp_mkdir_p(IMPORTER_DATA_PATH);
}

/**
 * Main import function - customize this for your vendors
 */
function import_products_from_json($vendor, $offset = 0, $limit = 100) {
    import_log("Starting import for vendor: $vendor (offset: $offset, limit: $limit)");
    
    // USER CUSTOMIZATION: Map your vendor names to JSON files
    $vendor_files = [
        'vendor1' => IMPORTER_DATA_PATH . 'vendor1-products.json',
        'vendor2' => IMPORTER_DATA_PATH . 'vendor2-products.json',
        // Add more vendors as needed
    ];
    
    if (!isset($vendor_files[$vendor])) {
        return ['success' => false, 'message' => "Unknown vendor: $vendor"];
    }
    
    $json_path = $vendor_files[$vendor];
    
    if (!file_exists($json_path)) {
        return ['success' => false, 'message' => "JSON file not found: $json_path"];
    }
    
    $products = json_decode(file_get_contents($json_path), true);
    if (!is_array($products)) {
        return ['success' => false, 'message' => "Invalid JSON format"];
    }
    
    // USER CUSTOMIZATION: Handle vendor-specific data structures
    // Example: if ($vendor === 'ashi' && isset($products['data'])) { $products = $products['data']; }
    
    $chunk = array_slice($products, $offset, $limit);
    $imported = 0;
    $updated = 0;
    $errors = 0;
    
    foreach ($chunk as $raw_item) {
        try {
            // USER CUSTOMIZATION: Extract the SKU based on your data structure
            $sku = extract_product_sku($raw_item, $vendor);
            
            if (empty($sku)) {
                import_log("Skipping item with no SKU from vendor: $vendor");
                $errors++;
                continue;
            }
            
            // Check for existing product
            $existing = get_posts([
                'post_type' => PRODUCT_POST_TYPE,
                'meta_query' => [
                    [
                        'key' => 'vendor_sku',
                        'value' => $sku,
                        'compare' => '='
                    ],
                    [
                        'key' => 'vendor',
                        'value' => $vendor,
                        'compare' => '='
                    ]
                ],
                'posts_per_page' => 1,
                'fields' => 'ids'
            ]);
            
            // USER CUSTOMIZATION: Extract product data based on your structure
            $product_data = extract_product_data($raw_item, $vendor);
            
            $post_data = [
                'post_title' => $product_data['title'],
                'post_type' => PRODUCT_POST_TYPE,
                'post_status' => 'publish',
                'post_content' => $product_data['description']
            ];
            
            if (!empty($existing)) {
                $post_data['ID'] = $existing[0];
                $post_id = wp_update_post($post_data);
                $updated++;
                import_log("Updated: {$product_data['title']} (SKU: $sku)");
            } else {
                $post_id = wp_insert_post($post_data);
                $imported++;
                import_log("Created: {$product_data['title']} (SKU: $sku)");
            }
            
            if (!$post_id || is_wp_error($post_id)) {
                import_log("Failed to save product for SKU: $sku");
                $errors++;
                continue;
            }
            
            // Save vendor info
            update_post_meta($post_id, 'vendor', $vendor);
            update_post_meta($post_id, 'vendor_sku', $sku);
            
            // USER CUSTOMIZATION: Save your specific meta fields
            save_product_meta($post_id, $product_data);
            
            // USER CUSTOMIZATION: Set tags and categories as needed
            if (!empty($product_data['tags'])) {
                wp_set_post_tags($post_id, $product_data['tags'], false);
            }
            
        } catch (Exception $e) {
            import_log("Error processing item: " . $e->getMessage());
            $errors++;
        }
    }
    
    import_log("Import complete for $vendor: $imported imported, $updated updated, $errors errors");
    
    return [
        'success' => true,
        'imported' => $imported,
        'updated' => $updated,
        'errors' => $errors
    ];
}

/**
 * USER CUSTOMIZATION: Extract SKU from raw product data
 * 
 * Reference patterns from your files:
 * - Some vendors use: $item['id']
 * - Others use: $item['ITEM_CD'] or $item['ITEM_NUMBER']
 * - Some need base SKU extraction from variations
 * - Multiple fallback fields: $item['style_number'] ?? $item['sku'] ?? $item['id']
 */
function extract_product_sku($raw_item, $vendor) {
    switch ($vendor) {
        case 'vendor1':
            return $raw_item['sku'] ?? $raw_item['id'] ?? '';
            
        case 'vendor2':
            return $raw_item['product_id'] ?? '';
            
        // Add more vendors as needed
        default:
            return $raw_item['sku'] ?? $raw_item['id'] ?? '';
    }
}

/**
 * USER CUSTOMIZATION: Extract and clean product data
 * 
 * Reference patterns from your files:
 * - Title: Various fields like 'type_name', 'ITEM_NAME', 'title', 'name'
 * - Price: Often needs cleaning with preg_replace('/[^0-9.]/', '', $price)
 * - Images: Usually arrays like $item['images'][0]
 * - Variants: Many vendors have $item['variants'][0] structure
 */
function extract_product_data($raw_item, $vendor) {
    switch ($vendor) {
        case 'vendor1':
            return [
                'title' => $raw_item['name'] ?? $raw_item['title'] ?? 'Untitled Product',
                'description' => $raw_item['description'] ?? '',
                'price' => clean_price($raw_item['price'] ?? ''),
                'image_url' => $raw_item['image'] ?? '',
                'category' => $raw_item['category'] ?? '',
                'brand' => $raw_item['brand'] ?? '',
                'tags' => array_filter([
                    $raw_item['category'] ?? '',
                    $raw_item['brand'] ?? '',
                    $vendor
                ])
            ];
            
        case 'vendor2':
            // Example with variant structure (like Zeghani/Simon G)
            $variant = $raw_item['variants'][0] ?? [];
            return [
                'title' => $raw_item['product_name'] ?? 'Untitled Product',
                'description' => $raw_item['description'] ?? '',
                'price' => clean_price($variant['price'] ?? ''),
                'image_url' => $raw_item['images'][0] ?? '',
                'metal_color' => $variant['metal_color'] ?? '',
                'tags' => array_filter([
                    $raw_item['type'] ?? '',
                    $variant['metal_color'] ?? '',
                    $vendor
                ])
            ];
            
        // Add more vendors as needed
        default:
            return [
                'title' => $raw_item['name'] ?? $raw_item['title'] ?? 'Untitled Product',
                'description' => $raw_item['description'] ?? '',
                'price' => clean_price($raw_item['price'] ?? ''),
                'image_url' => $raw_item['image'] ?? '',
                'tags' => [$vendor]
            ];
    }
}

/**
 * USER CUSTOMIZATION: Save product-specific meta fields
 * 
 * Reference patterns from your files:
 * - Always save: vendor, vendor_sku, price, image_url
 * - Product-specific: metal_color, metal_karat, weight, dimensions, etc.
 * - Hidden fields: vendor_product_id, vendor_product_name
 */
function save_product_meta($post_id, $product_data) {
    // Save common fields
    foreach ($product_data as $key => $value) {
        if (!in_array($key, ['title', 'description', 'tags']) && !empty($value)) {
            update_post_meta($post_id, $key, $value);
        }
    }
    
    // USER CUSTOMIZATION: Add any additional meta field logic here
    // Example: update_post_meta($post_id, 'custom_field', $some_value);
}

/**
 * Helper function to clean price values
 * Pattern from your files: preg_replace('/[^0-9.]/', '', $price)
 */
function clean_price($price) {
    if (empty($price)) return '';
    return preg_replace('/[^0-9.]/', '', $price);
}

/**
 * Log messages to file
 */
function import_log($message) {
    if (!file_exists(IMPORTER_LOG_FILE)) {
        // Create log file if it doesn't exist
        wp_mkdir_p(dirname(IMPORTER_LOG_FILE));
        touch(IMPORTER_LOG_FILE);
    }
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(IMPORTER_LOG_FILE, "[$timestamp] $message\n", FILE_APPEND | LOCK_EX);
}

/**
 * Test single product import for debugging
 */
function test_single_product_import($vendor, $sku) {
    import_log("Testing single product import: $vendor - $sku");
    
    // Load the JSON file
    $vendor_files = [
        'vendor1' => IMPORTER_DATA_PATH . 'vendor1-products.json',
        'vendor2' => IMPORTER_DATA_PATH . 'vendor2-products.json',
    ];
    
    if (!isset($vendor_files[$vendor])) {
        return ['success' => false, 'message' => "Unknown vendor: $vendor"];
    }
    
    $json_path = $vendor_files[$vendor];
    if (!file_exists($json_path)) {
        return ['success' => false, 'message' => "JSON file not found"];
    }
    
    $products = json_decode(file_get_contents($json_path), true);
    if (!is_array($products)) {
        return ['success' => false, 'message' => "Invalid JSON format"];
    }
    
    // Find the specific product
    $found_product = null;
    foreach ($products as $product) {
        if (extract_product_sku($product, $vendor) === $sku) {
            $found_product = $product;
            break;
        }
    }
    
    if (!$found_product) {
        return ['success' => false, 'message' => "Product not found with SKU: $sku"];
    }
    
    // Log the raw and processed data
    import_log("Raw product data: " . json_encode($found_product, JSON_PRETTY_PRINT));
    $processed = extract_product_data($found_product, $vendor);
    import_log("Processed data: " . json_encode($processed, JSON_PRETTY_PRINT));
    
    return ['success' => true, 'message' => 'Test complete - check logs for details'];
}

/**
 * USER CUSTOMIZATION NOTES:
 * 
 * To create imports for your specific vendors, modify these functions:
 * 
 * 1. extract_product_sku() - Add your vendor's SKU field mapping
 * 2. extract_product_data() - Add your vendor's data structure mapping  
 * 3. save_product_meta() - Add any vendor-specific meta fields
 * 
 * Common patterns from your reference files:
 * 
 * PATTERN A (Simple structure):
 * - SKU: $item['id'] or $item['sku']
 * - Title: $item['type_name'] or $item['name']
 * - Variants: $item['variants'][0] for price/variant data
 * - Images: $item['images'][0]
 * 
 * PATTERN B (Enterprise structure):
 * - SKU: $item['ITEM_CD'] or $item['ITEM_NUMBER']
 * - Title: $item['ITEM_NAME']
 * - All caps field names: PRICE, COLOR, etc.
 * - Nested data: $products['data'] array
 * 
 * PATTERN C (Variation consolidation):
 * - SKU needs base extraction from variations
 * - Multiple variations need consolidation
 * - Price in nested structure: $item['prices'] array
 * - Multiple types: $item['types'] or $item['categories'] arrays
 * 
 * Remember to:
 * - Always validate URLs with filter_var($url, FILTER_VALIDATE_URL)
 * - Clean prices with preg_replace('/[^0-9.]/', '', $price)
 * - Handle missing/empty fields gracefully
 * - Log progress for debugging
 */