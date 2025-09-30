<?php
/**
 * Debug file for WooCommerce Address Sync Plugin
 * Add this to your wp-config.php: define('WC_ADDRESS_SYNC_DEBUG', true);
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Address_Sync_Debug {
    
    private static $log_file;
    
    public static function init() {
        if (!defined('WC_ADDRESS_SYNC_DEBUG') || !WC_ADDRESS_SYNC_DEBUG) {
            return;
        }
        
        self::$log_file = WP_CONTENT_DIR . '/wc-address-sync-debug.log';
        
        // Add debug hooks
        add_action('woocommerce_process_shop_order_meta', array(__CLASS__, 'debug_order_save'), 5, 2);
        add_action('woocommerce_admin_order_data_after_save', array(__CLASS__, 'debug_admin_save'), 5, 1);
        add_action('wp_ajax_sync_single_order_addresses', array(__CLASS__, 'debug_ajax_sync'), 5);
    }
    
    public static function log($message, $data = null) {
        if (!defined('WC_ADDRESS_SYNC_DEBUG') || !WC_ADDRESS_SYNC_DEBUG) {
            return;
        }
        
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = "[{$timestamp}] {$message}";
        
        if ($data !== null) {
            $log_entry .= "\nData: " . print_r($data, true);
        }
        
        $log_entry .= "\n" . str_repeat('-', 50) . "\n";
        
        file_put_contents(self::$log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    public static function debug_order_save($post_id, $post) {
        self::log("Order save hook triggered", array(
            'post_id' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status
        ));
        
        if ($post->post_type === 'shop_order') {
            $order = wc_get_order($post_id);
            if ($order) {
                $billing = $order->get_address('billing');
                $shipping = $order->get_address('shipping');
                
                self::log("Order addresses before sync", array(
                    'billing' => $billing,
                    'shipping' => $shipping
                ));
            }
        }
    }
    
    public static function debug_admin_save($order) {
        self::log("Admin save hook triggered", array(
            'order_id' => $order ? $order->get_id() : 'null',
            'order_type' => $order ? get_class($order) : 'null'
        ));
        
        if ($order && is_a($order, 'WC_Order')) {
            $billing = $order->get_address('billing');
            $shipping = $order->get_address('shipping');
            
            self::log("Order addresses in admin save", array(
                'billing' => $billing,
                'shipping' => $shipping
            ));
        }
    }
    
    public static function debug_ajax_sync() {
        self::log("AJAX sync triggered", array(
            'order_id' => isset($_POST['order_id']) ? $_POST['order_id'] : 'not set'
        ));
    }
    
    public static function debug_sync_process($order_id, $billing_address, $shipping_address, $direction, $fields_updated) {
        self::log("Sync process details", array(
            'order_id' => $order_id,
            'direction' => $direction,
            'billing_address' => $billing_address,
            'shipping_address' => $shipping_address,
            'fields_updated' => $fields_updated
        ));
    }
    
    public static function debug_field_update($order_id, $field, $source_value, $target_value, $address_type) {
        self::log("Field update", array(
            'order_id' => $order_id,
            'field' => $field,
            'source_value' => $source_value,
            'target_value' => $target_value,
            'address_type' => $address_type,
            'will_update' => empty($target_value) && !empty($source_value)
        ));
    }
    
    public static function get_log_content() {
        if (!file_exists(self::$log_file)) {
            return "No debug log found.";
        }
        
        return file_get_contents(self::$log_file);
    }
    
    public static function clear_log() {
        if (file_exists(self::$log_file)) {
            unlink(self::$log_file);
        }
    }
}

// Initialize debug if enabled
WC_Address_Sync_Debug::init();
