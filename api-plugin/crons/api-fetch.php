<?php
/**
 * Universal Product API Fetcher
 * 
 * This script fetches product data from various vendor APIs
 * Copy and customize for each vendor you need to integrate
 * 
 * Usage: php api-fetch.php [vendor-name]
 * Example: php api-fetch.php vendor1
 */

// ===== CONFIGURATION START =====
// Copy this entire file for each vendor and customize these settings

$vendors = [
    'vendor1' => [
        'name' => 'Vendor 1 Display Name',
        'api_url' => 'https://api.vendor1.com/products',
        'auth_type' => 'bearer', // Options: 'bearer', 'basic', 'custom'
        'auth_token' => 'YOUR_API_TOKEN_HERE',
        'customer_id' => 'YOUR_CUSTOMER_ID', // If needed
        'method' => 'GET', // GET or POST
        'pagination' => [
            'type' => 'page', // Options: 'page', 'offset', 'cursor', 'none'
            'per_page' => 100,
            'page_param' => 'page',
            'data_path' => 'data', // Path to products in response (e.g., 'data', 'products', 'items')
            'meta_path' => 'meta.last_page' // Path to pagination info
        ],
        'ssl_verify' => true, // Set to false if SSL certificate issues
        'timeout' => 120,
        'log_file' => 'vendor1-fetch.log',
        'data_file' => 'vendor1-products.json'
    ],
    
    // Add more vendors here following the same structure
    // 'vendor2' => [...],
];

// ===== CONFIGURATION END =====

// Setup
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Get vendor from command line
$vendorKey = $argv[1] ?? 'vendor1';
if (!isset($vendors[$vendorKey])) {
    die("Unknown vendor: $vendorKey\n");
}

$config = $vendors[$vendorKey];
$logFile = __DIR__ . '/../logs/' . $config['log_file'];
$dataFile = __DIR__ . '/../data/' . $config['data_file'];

// Ensure directories exist
@mkdir(__DIR__ . '/../logs', 0755, true);
@mkdir(__DIR__ . '/../data', 0755, true);

log_message("Starting {$config['name']} fetch...");

// ===== API FETCH LOGIC =====

$allProducts = [];

// Handle different API authentication types
function get_auth_headers($config) {
    $headers = ['Accept: application/json'];
    
    switch ($config['auth_type']) {
        case 'bearer':
            $headers[] = 'Authorization: Bearer ' . $config['auth_token'];
            break;
            
        case 'basic':
            $headers[] = 'Authorization: Basic ' . base64_encode($config['auth_token']);
            break;
            
        case 'custom':
            // USER CUSTOMIZATION: Add your custom auth headers here
            // Example for API key header:
            // $headers[] = 'X-API-Key: ' . $config['auth_token'];
            break;
    }
    
    if ($config['method'] === 'POST') {
        $headers[] = 'Content-Type: application/json';
    }
    
    return $headers;
}

// Fetch products based on pagination type
switch ($config['pagination']['type']) {
    case 'page':
        $allProducts = fetch_paginated_products($config);
        break;
        
    case 'offset':
        $allProducts = fetch_offset_products($config);
        break;
        
    case 'none':
        $allProducts = fetch_all_products($config);
        break;
        
    case 'cursor':
        // USER CUSTOMIZATION: Implement cursor-based pagination if needed
        log_message("Cursor pagination not implemented - customize as needed");
        break;
}

// Save the data
if (!empty($allProducts)) {
    file_put_contents($dataFile, json_encode($allProducts, JSON_PRETTY_PRINT));
    log_message("Saved " . count($allProducts) . " products");
} else {
    log_message("No products fetched!");
}

exit(0);

// ===== HELPER FUNCTIONS =====

function fetch_paginated_products($config) {
    $products = [];
    $page = 1;
    $lastPage = 9999;
    
    do {
        $url = $config['api_url'] . '?' . $config['pagination']['page_param'] . '=' . $page;
        if (isset($config['pagination']['per_page'])) {
            $url .= '&per_page=' . $config['pagination']['per_page'];
        }
        
        $response = make_api_request($url, $config);
        if (!$response) break;
        
        // Extract products from response
        $pageProducts = get_nested_value($response, $config['pagination']['data_path']);
        if (!is_array($pageProducts)) break;
        
        $products = array_merge($products, $pageProducts);
        
        // Check for last page
        if ($config['pagination']['meta_path']) {
            $lastPage = get_nested_value($response, $config['pagination']['meta_path']) ?: $lastPage;
        }
        
        log_message("Page $page: " . count($pageProducts) . " products");
        $page++;
        
    } while ($page <= $lastPage && count($pageProducts) > 0);
    
    return $products;
}

function fetch_offset_products($config) {
    $products = [];
    $offset = 0;
    $limit = $config['pagination']['per_page'] ?? 100;
    
    do {
        $url = $config['api_url'] . "?offset=$offset&limit=$limit";
        
        $response = make_api_request($url, $config);
        if (!$response) break;
        
        $pageProducts = get_nested_value($response, $config['pagination']['data_path']);
        if (!is_array($pageProducts) || empty($pageProducts)) break;
        
        $products = array_merge($products, $pageProducts);
        log_message("Offset $offset: " . count($pageProducts) . " products");
        
        $offset += $limit;
        
    } while (count($pageProducts) === $limit);
    
    return $products;
}

function fetch_all_products($config) {
    $response = make_api_request($config['api_url'], $config);
    if (!$response) return [];
    
    return get_nested_value($response, $config['pagination']['data_path']) ?: [];
}

function make_api_request($url, $config) {
    $ch = curl_init();
    
    // Base cURL options
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_HTTPHEADER => get_auth_headers($config),
        CURLOPT_SSL_VERIFYHOST => $config['ssl_verify'] ? 2 : 0,
        CURLOPT_SSL_VERIFYPEER => $config['ssl_verify'],
    ];
    
    // Handle POST requests
    if ($config['method'] === 'POST') {
        $options[CURLOPT_POST] = true;
        
        // USER CUSTOMIZATION: Add your POST body here
        $postData = [];
        if (isset($config['customer_id'])) {
            $postData['CustId'] = $config['customer_id'];
        }
        
        $options[CURLOPT_POSTFIELDS] = json_encode($postData);
    }
    
    curl_setopt_array($ch, $options);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($error) {
        log_message("cURL Error: $error");
        return null;
    }
    
    if ($code !== 200) {
        log_message("HTTP Error Code: $code");
        log_message("Response: " . substr($response, 0, 500));
        return null;
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        log_message("JSON Decode Error: " . json_last_error_msg());
        return null;
    }
    
    return $data;
}

function get_nested_value($data, $path) {
    $keys = explode('.', $path);
    $value = $data;
    
    foreach ($keys as $key) {
        if (!isset($value[$key])) {
            return null;
        }
        $value = $value[$key];
    }
    
    return $value;
}

function log_message($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    echo "$message\n";
}