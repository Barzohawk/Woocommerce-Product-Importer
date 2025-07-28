<?php
/**
 * Universal Vendor Mapper Class
 * 
 * Handles field mappings between CSV columns and post meta fields for different vendors
 */

if (!defined('ABSPATH')) {
    exit;
}

class Universal_Vendor_Mapper {
    
    /**
     * Get available vendors for dropdown
     * 
     * @return array Vendor options
     */
    public function get_available_vendors() {
        // USER CUSTOMIZATION: Add your vendors here
        return array(
            'vendor1' => 'Vendor 1',
            'vendor2' => 'Vendor 2',
            'vendor3' => 'Vendor 3',
            'custom_vendor' => 'Custom Vendor',
            'generic' => 'Generic (Auto-map)'
        );
    }
    
    /**
     * Get field mappings for a specific vendor
     * 
     * @param string $vendor Vendor identifier
     * @return array Field mappings (CSV column => post meta key)
     */
    public function get_field_mappings($vendor) {
        switch ($vendor) {
            case 'vendor1':
                return $this->get_vendor1_mappings();
                
            case 'vendor2':
                return $this->get_vendor2_mappings();
                
            case 'vendor3':
                return $this->get_vendor3_mappings();
                
            case 'custom_vendor':
                return $this->get_custom_vendor_mappings();
                
            case 'generic':
            default:
                return $this->get_generic_mappings();
        }
    }
    
    /**
     * USER CUSTOMIZATION: Vendor 1 field mappings
     * Example: E-commerce vendor with standard fields
     */
    private function get_vendor1_mappings() {
        return array(
            // CSV Column => Post Meta Key
            'Product Name' => 'title',
            'SKU' => 'sku',
            'Description' => 'description',