# Universal Product Import Plugin - Setup Guide

## Quick Start

This plugin allows you to import any type of product from JSON files into WordPress. Follow these steps:

### 1. Get Your JSON Data

First, do a test pull from your data source (API, export, etc.) to see the JSON structure:

```bash
# Example: Get sample data from your API
curl https://your-api.com/products?limit=5 > sample-data.json
```

### 2. Analyze Your JSON Structure

Open your JSON file and identify:
- What fields contain product information
- Which field uniquely identifies each product (SKU, ID, etc.)
- How images are stored
- Any nested data structures

Example JSON:
```json
{
  "products": [
    {
      "sku": "PROD-001",
      "title": "Amazing Product",
      "description": "This is a great product...",
      "price": "99.99",
      "category": "Electronics",
      "specs": {
        "weight": "2.5kg",
        "dimensions": "10x20x5cm"
      },
      "images": ["prod001-1.jpg", "prod001-2.jpg"]
    }
  ]
}
```

### 3. Configure Your Import

Edit `includes/universal-import.php` and add your vendor configuration:

```php
'my_vendor' => [
    'name' => 'My Vendor Name',
    'json_file' => 'my-vendor-products.json',
    
    'field_mapping' => [
        // Map YOUR JSON fields to WordPress fields
        'sku' => 'vendor_sku',                    // Unique identifier
        'title' => 'post_title',                  // Product name
        'description' => 'post_content',          // Product description
        'price' => 'price',                       // Price
        'category' => 'product_category',         // Category
        
        // Nested fields use dot notation
        'specs.weight' => 'product_weight',       
        'specs.dimensions' => 'product_dimensions',
        
        // Arrays
        'images' => 'product_images',
    ],
    
    'unique_field' => 'sku',  // Field that identifies each product
],
```

### 4. Run Your Import

```bash
# Import first 10 products as a test
wp product-import run my_vendor --limit=10

# Import all products
wp product-import run my_vendor --limit=1000

# Import with offset (for resuming)
wp product-import run my_vendor --offset=100 --limit=100
```

## Detailed Configuration Guide

### Basic Field Mapping

Map your JSON fields to WordPress post fields or meta fields:

```php
'field_mapping' => [
    // Post fields (standard WordPress fields)
    'product_name' => 'post_title',      // Product title
    'description' => 'post_content',     // Main content
    'excerpt' => 'post_excerpt',         // Short description
    
    // Meta fields (custom fields)
    'sku' => 'vendor_sku',              // Stored as post meta
    'price' => 'regular_price',         // Stored as post meta
    'brand' => 'product_brand',         // Stored as post meta
]
```

### Nested JSON Fields

Use dot notation for nested data:

```php
// JSON: { "details": { "color": "red", "size": "large" } }
'field_mapping' => [
    'details.color' => 'product_color',
    'details.size' => 'product_size',
]
```

### Array Fields

Handle arrays with special handlers:

```php
// JSON: { "images": ["img1.jpg", "img2.jpg"] }
'field_mapping' => [
    'images' => 'product_images',
],
'special_handlers' => [
    'images' => 'handle_image_array',
]
```

### Custom Transformations

Transform data during import:

```php
'transforms' => [
    'category' => 'uppercase',      // ELECTRONICS
    'price' => 'decimal',          // 99.99
    '