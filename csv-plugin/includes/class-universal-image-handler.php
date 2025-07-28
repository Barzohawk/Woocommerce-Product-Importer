<?php
/**
 * Universal Image Handler Class
 * 
 * Handles image processing for CSV imports - searches Media Library and handles URLs
 */

if (!defined('ABSPATH')) {
    exit;
}

class Universal_Image_Handler {
    
    /**
     * Process product image from CSV data
     * 
     * @param int $post_id The product post ID
     * @param string $image_data Image filename, URL, or path
     * @return bool Success status
     */
    public function process_product_image($post_id, $image_data) {
        if (empty($image_data)) {
            return false;
        }
        
        $image_data = trim($image_data);
        
        // Try different methods to find/import the image
        $attachment_id = false;
        
        // Method 1: Search Media Library by filename
        $attachment_id = $this->find_image_in_media_library($image_data);
        
        // Method 2: If it's a URL, try to import it
        if (!$attachment_id && filter_var($image_data, FILTER_VALIDATE_URL)) {
            $attachment_id = $this->import_image_from_url($image_data, $post_id);
        }
        
        // Method 3: Try as relative path in uploads directory
        if (!$attachment_id && !filter_var($image_data, FILTER_VALIDATE_URL)) {
            $attachment_id = $this->find_image_by_path($image_data);
        }
        
        // Set as featured image if found
        if ($attachment_id) {
            set_post_thumbnail($post_id, $attachment_id);
            
            // USER CUSTOMIZATION: Add to gallery meta field if needed
            $this->add_to_product_gallery($post_id, $attachment_id);
            
            return true;
        }
        
        // Log missing image
        $this->log_missing_image($post_id, $image_data);
        
        return false;
    }
    
    /**
     * Process multiple images for a product
     * 
     * @param int $post_id Product post ID
     * @param string $images_data Comma-separated image data
     * @return array Array of attachment IDs
     */
    public function process_product_images($post_id, $images_data) {
        $attachment_ids = array();
        
        if (empty($images_data)) {
            return $attachment_ids;
        }
        
        // Split by common delimiters
        $image_list = preg_split('/[,;|]/', $images_data);
        $image_list = array_map('trim', $image_list);
        $image_list = array_filter($image_list);
        
        $featured_set = false;
        
        foreach ($image_list as $image_data) {
            if (empty($image_data)) continue;
            
            $attachment_id = false;
            
            // Try to find/import the image
            $attachment_id = $this->find_image_in_media_library($image_data);
            
            if (!$attachment_id && filter_var($image_data, FILTER_VALIDATE_URL)) {
                $attachment_id = $this->import_image_from_url($image_data, $post_id);
            }
            
            if (!$attachment_id) {
                $attachment_id = $this->find_image_by_path($image_data);
            }
            
            if ($attachment_id) {
                $attachment_ids[] = $attachment_id;
                
                // Set first image as featured image
                if (!$featured_set) {
                    set_post_thumbnail($post_id, $attachment_id);
                    $featured_set = true;
                }
            } else {
                $this->log_missing_image($post_id, $image_data);
            }
        }
        
        // Save gallery
        if (!empty($attachment_ids)) {
            $this->save_product_gallery($post_id, $attachment_ids);
        }
        
        return $attachment_ids;
    }
    
    /**
     * Find image in Media Library by filename
     * 
     * @param string $filename Image filename
     * @return int|false Attachment ID or false
     */
    private function find_image_in_media_library($filename) {
        global $wpdb;
        
        // Clean filename - remove path and get just the filename
        $filename = basename($filename);
        
        // Try exact filename match first
        $attachment_id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wp_attached_file' 
            AND meta_value LIKE %s
        ", '%' . $wpdb->esc_like($filename)));
        
        if ($attachment_id) {
            return intval($attachment_id);
        }
        
        // Try without file extension
        $name_without_ext = pathinfo($filename, PATHINFO_FILENAME);
        
        $attachment_id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wp_attached_file' 
            AND meta_value LIKE %s
        ", '%' . $wpdb->esc_like($name_without_ext) . '.%'));
        
        if ($attachment_id) {
            return intval($attachment_id);
        }
        
        // Try by post title (alt method)
        $post = get_page_by_title($name_without_ext, OBJECT, 'attachment');
        if ($post) {
            return $post->ID;
        }
        
        return false;
    }
    
    /**
     * Find image by relative path in uploads directory
     * 
     * @param string $relative_path Relative path to image
     * @return int|false Attachment ID or false
     */
    private function find_image_by_path($relative_path) {
        $upload_dir = wp_upload_dir();
        $full_path = $upload_dir['basedir'] . '/' . ltrim($relative_path, '/');
        
        if (!file_exists($full_path)) {
            return false;
        }
        
        // Get the relative path for database lookup
        $relative_path = str_replace($upload_dir['basedir'] . '/', '', $full_path);
        
        global $wpdb;
        $attachment_id = $wpdb->get_var($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_wp_attached_file' 
            AND meta_value = %s
        ", $relative_path));
        
        return $attachment_id ? intval($attachment_id) : false;
    }
    
    /**
     * Import image from URL
     * 
     * @param string $url Image URL
     * @param int $post_id Associated post ID
     * @return int|false Attachment ID or false
     */
    private function import_image_from_url($url, $post_id) {
        // Include WordPress media functions
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        // Get the file
        $temp_file = download_url($url);
        
        if (is_wp_error($temp_file)) {
            return false;
        }
        
        // Prepare file array
        $file = array(
            'name' => basename($url),
            'type' => wp_check_filetype(basename($url))['type'],
            'tmp_name' => $temp_file,
            'error' => 0,
            'size' => filesize($temp_file),
        );
        
        // Import the file
        $attachment_id = media_handle_sideload($file, $post_id);
        
        // Clean up temp file
        if (file_exists($temp_file)) {
            unlink($temp_file);
        }
        
        if (is_wp_error($attachment_id)) {
            return false;
        }
        
        return $attachment_id;
    }
    
    /**
     * Add image to product gallery meta field
     * 
     * @param int $post_id Product post ID
     * @param int $attachment_id Image attachment ID
     */
    private function add_to_product_gallery($post_id, $attachment_id) {
        // USER CUSTOMIZATION: Modify gallery meta key as needed
        $gallery_meta_key = 'product_gallery'; // Change this to your gallery meta key
        
        $existing_gallery = get_post_meta($post_id, $gallery_meta_key, true);
        $gallery_ids = array();
        
        if (!empty($existing_gallery)) {
            if (is_string($existing_gallery)) {
                $gallery_ids = explode(',', $existing_gallery);
            } elseif (is_array($existing_gallery)) {
                $gallery_ids = $existing_gallery;
            }
        }
        
        $gallery_ids = array_map('intval', $gallery_ids);
        
        if (!in_array($attachment_id, $gallery_ids)) {
            $gallery_ids[] = $attachment_id;
        }
        
        // Save as comma-separated string (WooCommerce style) or array
        $gallery_value = implode(',', $gallery_ids); // Change to $gallery_ids for array format
        update_post_meta($post_id, $gallery_meta_key, $gallery_value);
    }
    
    /**
     * Save complete product gallery
     * 
     * @param int $post_id Product post ID
     * @param array $attachment_ids Array of attachment IDs
     */
    private function save_product_gallery($post_id, $attachment_ids) {
        // USER CUSTOMIZATION: Modify gallery meta key as needed
        $gallery_meta_key = 'product_gallery';
        
        $attachment_ids = array_map('intval', $attachment_ids);
        $attachment_ids = array_unique($attachment_ids);
        
        // Save as comma-separated string (WooCommerce style)
        $gallery_value = implode(',', $attachment_ids);
        update_post_meta($post_id, $gallery_meta_key, $gallery_value);
        
        // USER CUSTOMIZATION: Also save as array if needed
        // update_post_meta($post_id, $gallery_meta_key . '_array', $attachment_ids);
    }
    
    /**
     * Log missing image for debugging
     * 
     * @param int $post_id Product post ID
     * @param string $image_data Missing image data
     */
    private function log_missing_image($post_id, $image_data) {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/csv-imports/missing-images.log';
        
        $log_entry = sprintf(
            "[%s] Post ID: %d - Missing image: %s\n",
            current_time('Y-m-d H:i:s'),
            $post_id,
            $image_data
        );
        
        file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get image statistics for reporting
     * 
     * @return array Image processing statistics
     */
    public function get_image_statistics() {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/csv-imports/missing-images.log';
        
        $stats = array(
            'missing_images_count' => 0,
            'recent_missing' => array()
        );
        
        if (file_exists($log_file)) {
            $log_content = file_get_contents($log_file);
            $lines = explode("\n", trim($log_content));
            $stats['missing_images_count'] = count($lines);
            $stats['recent_missing'] = array_slice($lines, -10); // Last 10 entries
        }
        
        return $stats;
    }
    
    /**
     * Clean up old image logs
     */
    public function cleanup_image_logs() {
        $upload_dir = wp_upload_dir();
        $log_file = $upload_dir['basedir'] . '/csv-imports/missing-images.log';
        
        if (file_exists($log_file)) {
            // Keep only last 1000 lines
            $lines = file($log_file);
            if (count($lines) > 1000) {
                $recent_lines = array_slice($lines, -1000);
                file_put_contents($log_file, implode('', $recent_lines));
            }
        }
    }
}