<?php
/**
 * Vendor Configuration Examples
 * 
 * Copy these examples to customize for your specific vendors
 * Place in the configuration section of the fetch/import scripts
 */

// ===== JEWELRY STORE EXAMPLE =====
$jewelry_vendors = [
    'diamond_supplier' => [
        // Fetch configuration
        'name' => 'Diamond Supplier Co',
        'api_url' => 'https://api.diamondsupplier.com/products',
        'auth_type' => 'bearer',
        'auth_token' => 'YOUR_TOKEN_HERE',
        'customer_id' => 'YOUR_CUSTOMER_ID',
        'method' => 'POST',
        'pagination' => [
            'type' => 'page',
            'per_page' => 100,
            'page_param' => 'page',
            'data_path' => 'data',
            'meta_path' => 'meta.last_page'
        ],
        
        // Import configuration
        'data_file' => 'diamond-products.json',
        'id_field' => 'stylecode',
        'field_mapping' => [
            'stylecode' => 'vendor_sku',
            'itemNumber' => 'vendor_product_id',
            'name' => 'post_title',
            'description' => 'post_content',
            'price' => 'price',
            'type' => 'main_category',
            'collection' => 'collection',
            'metal' => 'metal_type',
            'metalColor' => 'metal_color',
            'centerStone' => 'center_stone_type',
            'centerStoneWeight' => 'stone_weight',
            'images' => 'image_url'
        ],
        'transforms' => [
            'type' => 'uppercase',
            'price' => 'numeric'
        ]
    ],
    
    'pearl_vendor' => [
        'name' => 'Pearl Masters',
        'api_url' => 'https://api.pearlmasters.com/v2/products',
        'auth_type' => 'basic',
        'auth_token' => 'username:password',
        'method' => 'GET',
        'pagination' => [
            'type' => 'offset',
            'per_page' => 50,
            'data_path' => 'products'
        ],
        'field_mapping' => [
            'sku' => 'vendor_sku',
            'title' => 'post_title',
            'description' => 'post_content',
            'pearlType' => 'pearl_type',
            'pearlSize' => 'pearl_size',
            'metalType' => 'metal_type',
            'retailPrice' => 'price'
        ]
    ]
];

// ===== ELECTRONICS STORE EXAMPLE =====
$electronics_vendors = [
    'tech_distributor' => [
        'name' => 'Tech Distributor Inc',
        'api_url' => 'https://api.techdist.com/products/list',
        'auth_type' => 'custom', // Uses API key header
        'auth_token' => 'YOUR_API_KEY',
        'method' => 'GET',
        'pagination' => [
            'type' => 'cursor',
            'per_page' => 200,
            'data_path' => 'items'
        ],
        'field_mapping' => [
            'model_number' => 'vendor_sku',
            'product_name' => 'post_title',
            'long_description' => 'post_content',
            'msrp' => 'price',
            'category' => 'product_category',
            'brand' => 'brand',
            'specifications' => 'tech_specs',
            'warranty' => 'warranty_info',
            'main_image' => 'image_url'
        ]
    ]
];

// ===== CLOTHING STORE EXAMPLE =====
$clothing_vendors = [
    'fashion_wholesale' => [
        'name' => 'Fashion Wholesale',
        'api_url' => 'https://wholesale.fashion.com/api/products',
        'auth_type' => 'bearer',
        'auth_token' => 'YOUR_TOKEN',
        'method' => 'GET',
        'field_mapping' => [
            'style_number' => 'vendor_sku',
            'product_name' => 'post_title',
            'description' => 'post_content',
            'wholesale_price' => 'price',
            'retail_price' => 'msrp',
            'category' => 'product_category',
            'subcategory' => 'product_subcategory',
            'color' => 'color',
            'sizes' => 'available_sizes',
            'material' => 'fabric',
            'care_instructions' => 'care_instructions',
            'images' => 'image_urls'
        ],
        'transforms' => [
            'category' => 'uppercase',
            'sizes' => 'custom' // Parse size array
        ]
    ]
];

// ===== CUSTOM AUTH HEADER EXAMPLE =====
// In get_auth_headers() function, add:
/*
case 'custom':
    // API Key in header
    $headers[] = 'X-API-Key: ' . $config['auth_token'];
    
    // Or multiple headers
    $headers[] = 'X-Customer-ID: ' . $config['customer_id'];
    $headers[] = 'X-API-Version: 2.0';
    break;
*/

// ===== CUSTOM TRANSFORM EXAMPLE =====
// In apply_transform() function, add:
/*
case 'custom':
    // Example: Parse size string "S,M,L,XL" to array
    if (is_string($value) && strpos($value, ',') !== false) {
        return array_map('trim', explode(',', $value));
    }
    
    // Example: Convert category codes to names
    $categoryMap = [
        'RNG' => 'Rings',
        'NCK' => 'Necklaces',
        'BRC' => 'Bracelets'
    ];
    return $categoryMap[$value] ?? $value;
*/

// ===== CUSTOM POST PROCESSING EXAMPLE =====
// After import_single_product(), add custom processing:
/*
// Set product taxonomies
if (isset($mapped['product_category'])) {
    wp_set_object_terms($postId, $mapped['product_category'], 'product_cat');
}

// Handle variations (for WooCommerce)
if (isset($data['variations'])) {
    foreach ($data['variations'] as $variation) {
        create_product_variation($postId, $variation);
    }
}

// Set custom product type
wp_set_object_terms($postId, 'simple', 'product_type');
*/

// ===== INCREMENTAL UPDATE EXAMPLE =====
// For APIs that support fetching only changed products:
/*
$lastUpdatedFile = __DIR__ . '/../data/vendor-last-updated.txt';
$lastUpdated = file_exists($lastUpdatedFile) ? file_get_contents($lastUpdatedFile) : '';

if ($lastUpdated) {
    $config['api_url'] .= '/changes?since=' . urlencode($lastUpdated);
}

// After successful import:
file_put_contents($lastUpdatedFile, date('Y-m-d\TH:i:s'));
*/