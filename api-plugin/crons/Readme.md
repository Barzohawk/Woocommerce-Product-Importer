# Universal Product Import System Guide

## Overview

This system provides a flexible, vendor-agnostic solution for importing products from any API into WordPress. It consists of two main components:

1. **API Fetcher** - Retrieves product data from vendor APIs
2. **Product Importer** - Imports the data into WordPress

## Table of Contents

- [Directory Structure](#directory-structure)
- [Initial Setup](#initial-setup)
- [Configuration](#configuration)
- [Running the Scripts](#running-the-scripts)
- [Customization Examples](#customization-examples)
- [Cron Setup](#cron-setup)
- [Troubleshooting](#troubleshooting)
- [Security Best Practices](#security-best-practices)

## Directory Structure

```
wordpress-root/
├── crons/
│   ├── api-fetch.php          # Universal API fetcher
│   ├── product-import.php     # Universal product importer
│   └── vendor-configs/        # Optional: Separate config files
│       ├── vendor1.php
│       └── vendor2.php
├── data/                      # JSON data storage
│   ├── vendor1-products.json
│   ├── vendor1-last-update.txt
│   └── vendor2-products.json
└── logs/                      # Log files
    ├── vendor1-fetch.log
    ├── vendor1-import.log
    └── vendor2-fetch.log
```

## Initial Setup

### 1. Create Required Directories

```bash
mkdir -p crons data logs
chmod 755 crons data logs
```

### 2. Configure WordPress Path

In `product-import.php`, update the WordPress path:

```php
define('WP_PATH', '/home/your-site/public_html/wp-load.php');
```

### 3. Set Your Post Type

```php
define('PRODUCT_POST_TYPE', 'product'); // Change to your custom post type
```

## Configuration

### API Fetcher Configuration

Edit the `$vendors` array in `api-fetch.php`:

```php
$vendors = [
    'vendor1' => [
        'name' => 'My Vendor Name',
        'api_url' => 'https://api.vendor.com/products',
        'auth_type' => 'bearer', // Options: 'bearer', 'basic', 'custom'
        'auth_token' => 'YOUR_API_TOKEN',
        'customer_id' => 'YOUR_CUSTOMER_ID', // Optional
        'method' => 'GET', // or 'POST'
        'pagination' => [
            'type' => 'page', // Options: 'page', 'offset', 'cursor', 'none'
            'per_page' => 100,
            'page_param' => 'page',
            'data_path' => 'data', // Where products are in response
            'meta_path' => 'meta.last_page' // Pagination info path
        ],
        'ssl_verify' => true,
        'timeout' => 120,
        'log_file' => 'vendor1-fetch.log',
        'data_file' => 'vendor1-products.json'
    ]
];
```

### Product Importer Configuration

Edit the `$vendors` array in `product-import.php`:

```php
$vendors = [
    'vendor1' => [
        'name' => 'My Vendor',
        'data_file' => 'vendor1-products.json',
        'id_field' => 'sku', // Unique identifier in JSON
        'field_mapping' => [
            // JSON field => WordPress meta field
            'sku' => 'vendor_sku',
            'name' => 'post_title',
            'description' => 'post_content',
            'price' => 'price',
            'category' => 'product_category',
            'brand' => 'product_brand',
            'image' => 'image_url'
        ],
        'image_handling' => [
            'enabled' => true,
            'json_field' => 'image',
            'type' => 'filename' // or 'url', 'array'
        ],
        'transforms' => [
            'category' => 'uppercase',
            'price' => 'numeric'
        ]
    ]
];
```

## Running the Scripts

### Manual Execution

```bash
# Fetch products from API
php crons/api-fetch.php vendor1

# Import all products
php crons/product-import.php vendor1

# Import with pagination (offset, limit)
php crons/product-import.php vendor1 0 100    # First 100
php crons/product-import.php vendor1 100 100  # Next 100
```

### Testing

```bash
# Test with a small batch
php crons/product-import.php vendor1 0 5
```

## Customization Examples

### Custom Authentication

Add to `get_auth_headers()` in `api-fetch.php`:

```php
case 'custom':
    // API Key header
    $headers[] = 'X-API-Key: ' . $config['auth_token'];
    $headers[] = 'X-Customer-ID: ' . $config['customer_id'];
    break;

case 'oauth':
    // OAuth 2.0
    $headers[] = 'Authorization: OAuth ' . $config['auth_token'];
    break;
```

### Custom Field Transformations

Add to `apply_transform()` in `product-import.php`:

```php
case 'custom':
    // Parse sizes from string to array
    if (strpos($value, ',') !== false) {
        return array_map('trim', explode(',', $value));
    }
    
    // Map vendor categories to your categories
    $categoryMap = [
        'RINGS' => 'Rings & Bands',
        'NECKLACES' => 'Necklaces & Pendants',
        'BRACELETS' => 'Bracelets & Bangles'
    ];
    return $categoryMap[$value] ?? $value;
```

### Industry-Specific Examples

#### Jewelry Store

```php
'field_mapping' => [
    'stylecode' => 'vendor_sku',
    'name' => 'post_title',
    'description' => 'post_content',
    'msrp' => 'price',
    'metal_type' => 'metal_type',
    'metal_color' => 'metal_color',
    'stone_type' => 'primary_stone',
    'stone_weight' => 'carat_weight',
    'collection' => 'product_collection'
]
```

#### Electronics Store

```php
'field_mapping' => [
    'model_number' => 'vendor_sku',
    'product_name' => 'post_title',
    'specs' => 'technical_specifications',
    'warranty' => 'warranty_period',
    'dimensions' => 'product_dimensions',
    'weight' => 'shipping_weight'
]
```

#### Clothing Store

```php
'field_mapping' => [
    'style_code' => 'vendor_sku',
    'item_name' => 'post_title',
    'fabric' => 'material_composition',
    'sizes' => 'available_sizes',
    'colors' => 'color_options',
    'care' => 'care_instructions',
    'season' => 'seasonal_collection'
]
```

## Cron Setup

### Basic Cron Schedule

```bash
# Edit crontab
crontab -e

# Add these lines:
# Fetch products daily at 2 AM
0 2 * * * /usr/bin/php /path/to/crons/api-fetch.php vendor1 >> /path/to/logs/cron.log 2>&1

# Import products daily at 3 AM
0 3 * * * /usr/bin/php /path/to/crons/product-import.php vendor1 >> /path/to/logs/cron.log 2>&1
```

### Multiple Vendors

```bash
# Vendor 1 - Every day at 2 AM
0 2 * * * /usr/bin/php /path/to/crons/api-fetch.php vendor1
0 3 * * * /usr/bin/php /path/to/crons/product-import.php vendor1

# Vendor 2 - Every Monday at 4 AM
0 4 * * 1 /usr/bin/php /path/to/crons/api-fetch.php vendor2
0 5 * * 1 /usr/bin/php /path/to/crons/product-import.php vendor2
```

### Staggered Import for Large Datasets

```bash
# Import in batches throughout the day
0 3 * * * /usr/bin/php /path/to/crons/product-import.php vendor1 0 1000
0 4 * * * /usr/bin/php /path/to/crons/product-import.php vendor1 1000 1000
0 5 * * * /usr/bin/php /path/to/crons/product-import.php vendor1 2000 1000
```

## Troubleshooting

### Enable Debug Mode

Add to the top of your scripts:

```php
define('DEBUG_MODE', true);
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

### Common Issues and Solutions

#### Memory Limit Errors

```php
ini_set('memory_limit', '512M');
```

#### Timeout Issues

```php
set_time_limit(0); // No time limit
ini_set('max_execution_time', 0);
```

#### SSL Certificate Errors

```php
// In vendor config
'ssl_verify' => false,
```

#### Large JSON Files

```php
// Use streaming JSON parser
$parser = new JsonStreamingParser\Parser($stream, $listener);
$parser->parse();
```

### Logging

Check log files for detailed error information:

```bash
tail -f logs/vendor1-fetch.log
tail -f logs/vendor1-import.log
```

### Test Individual Products

```php
// Add debug function to product-import.php
function test_single_product($sku) {
    global $vendors;
    $config = $vendors['vendor1'];
    $products = json_decode(file_get_contents('../data/' . $config['data_file']), true);
    
    foreach ($products as $product) {
        if ($product[$config['id_field']] == $sku) {
            print_r($product);
            $result = import_single_product($product, $config);
            echo "Result: $result\n";
            break;
        }
    }
}
```

## Security Best Practices

### 1. Restrict CLI Access

Add to all scripts:

```php
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}
```

### 2. Use Environment Variables

Store sensitive data in `.env` file:

```bash
# .env file
VENDOR1_API_TOKEN=your_secret_token
VENDOR1_CUSTOMER_ID=your_customer_id
```

Load in PHP:

```php
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$vendors = [
    'vendor1' => [
        'auth_token' => $_ENV['VENDOR1_API_TOKEN'],
        'customer_id' => $_ENV['VENDOR1_CUSTOMER_ID'],
    ]
];
```

### 3. Validate and Sanitize Data

```php
// Sanitize text fields
$mapped['post_title'] = sanitize_text_field($data['title']);
$mapped['post_content'] = wp_kses_post($data['description']);

// Validate numeric fields
$mapped['price'] = abs(floatval($data['price']));

// Validate URLs
$mapped['image_url'] = esc_url_raw($data['image']);
```

### 4. Implement Rate Limiting

```php
// Add delay between API requests
sleep(1); // 1 second delay

// Or implement token bucket algorithm
$rateLimiter->consume(1);
```

### 5. Secure File Permissions

```bash
chmod 600 .env
chmod 700 crons/
chmod 755 logs/ data/
```

## Performance Optimization

### 1. Batch Database Operations

```php
// Use direct database queries for checking existing products
global $wpdb;
$existing_skus = $wpdb->get_col("
    SELECT meta_value 
    FROM $wpdb->postmeta 
    WHERE meta_key = 'vendor_sku'
");
```

### 2. Disable Unnecessary Hooks

```php
// During import
remove_action('save_post', 'your_heavy_function');
// ... import ...
add_action('save_post', 'your_heavy_function');
```

### 3. Use Transients for Caching

```php
$cache_key = 'vendor1_products_' . md5($api_url);
$cached = get_transient($cache_key);

if ($cached === false) {
    $products = fetch_from_api();
    set_transient($cache_key, $products, HOUR_IN_SECONDS);
} else {
    $products = $cached;
}
```

## Advanced Features

### Incremental Updates

Track and fetch only changed products:

```php
// Store last update time
$lastUpdateFile = __DIR__ . '/../data/vendor-last-update.txt';
$lastUpdate = file_exists($lastUpdateFile) ? file_get_contents($lastUpdateFile) : '';

// Add to API request
if ($lastUpdate) {
    $config['api_url'] .= '?modified_since=' . urlencode($lastUpdate);
}

// After successful import
file_put_contents($lastUpdateFile, date('Y-m-d\TH:i:s'));
```

### Product Variations

Handle variable products (sizes, colors, etc.):

```php
// In import_single_product()
if (isset($data['variations'])) {
    foreach ($data['variations'] as $variation) {
        $varData = [
            'post_parent' => $postId,
            'post_type' => 'product_variation',
            'post_title' => $mapped['post_title'] . ' - ' . $variation['size']
        ];
        
        $varId = wp_insert_post($varData);
        update_post_meta($varId, 'attribute_size', $variation['size']);
        update_post_meta($varId, 'price', $variation['price']);
    }
}
```

### Custom Taxonomies

```php
// Set product categories
if (isset($mapped['product_category'])) {
    wp_set_object_terms($postId, $mapped['product_category'], 'product_cat');
}

// Set product tags
if (isset($mapped['tags'])) {
    wp_set_object_terms($postId, $mapped['tags'], 'product_tag');
}
```

## Support and Maintenance

### Regular Maintenance Tasks

1. **Clean old log files** (monthly)
```bash
find logs/ -name "*.log" -mtime +30 -delete
```

2. **Backup data files** (weekly)
```bash
tar -czf backup-$(date +%Y%m%d).tar.gz data/
```

3. **Monitor import success** (daily)
```bash
grep -c "import complete" logs/*.log
```

### Health Checks

Create a monitoring script:

```php
// check-imports.php
$vendors = ['vendor1', 'vendor2'];

foreach ($vendors as $vendor) {
    $dataFile = __DIR__ . '/data/' . $vendor . '-products.json';
    $logFile = __DIR__ . '/logs/' . $vendor . '-import.log';
    
    // Check if data file exists and is recent
    if (file_exists($dataFile)) {
        $age = time() - filemtime($dataFile);
        if ($age > 86400) { // Older than 24 hours
            echo "WARNING: $vendor data is " . round($age/3600) . " hours old\n";
        }
    }
    
    // Check last import status
    $log = file_get_contents($logFile);
    if (strpos($log, 'import complete') === false) {
        echo "ERROR: Last $vendor import may have failed\n";
    }
}
```

## Conclusion

This universal import system provides a flexible foundation for importing products from any API into WordPress. Customize the configuration arrays to match your specific vendors and requirements.

For additional help or custom development, refer to the inline code comments marked with "USER CUSTOMIZATION" throughout the scripts.