<?php
/**
 * Plugin Name: WooCommerce Address Sync
 * Plugin URI: https://yourwebsite.com
 * Description: Automatically syncs incomplete billing and shipping addresses in WooCommerce
 * Version: 1.0.0
 * Author: Mohammed Oar
 * License: GPL v2 or later
 * Text Domain: wc-address-sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class WC_Address_Sync {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // WooCommerce hooks
        add_action('woocommerce_process_shop_order_meta', array($this, 'sync_addresses_on_order_save'), 10, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'add_sync_button_to_order'));
        add_action('wp_ajax_sync_single_order_addresses', array($this, 'sync_single_order_addresses'));
        add_action('wp_ajax_bulk_sync_addresses', array($this, 'bulk_sync_addresses'));
        
        // Add settings
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('wc_address_sync_settings', 'wc_address_sync_options');
        
        add_settings_section(
            'wc_address_sync_main',
            __('Address Sync Settings', 'wc-address-sync'),
            null,
            'wc_address_sync_settings'
        );
        
        add_settings_field(
            'auto_sync_enabled',
            __('Enable Auto Sync', 'wc-address-sync'),
            array($this, 'auto_sync_callback'),
            'wc_address_sync_settings',
            'wc_address_sync_main'
        );
        
        add_settings_field(
            'sync_direction',
            __('Sync Direction', 'wc-address-sync'),
            array($this, 'sync_direction_callback'),
            'wc_address_sync_settings',
            'wc_address_sync_main'
        );
    }
    
    /**
     * Auto sync enabled callback
     */
    public function auto_sync_callback() {
        $options = get_option('wc_address_sync_options');
        $enabled = isset($options['auto_sync_enabled']) ? $options['auto_sync_enabled'] : 1;
        echo '<input type="checkbox" name="wc_address_sync_options[auto_sync_enabled]" value="1" ' . checked(1, $enabled, false) . ' />';
		echo '<p class="description">' . __('Automatically sync addresses for orders only', 'wc-address-sync') . '</p>';
    }
    
    /**
     * Sync direction callback
     */
    public function sync_direction_callback() {
        $options = get_option('wc_address_sync_options');
        $direction = isset($options['sync_direction']) ? $options['sync_direction'] : 'both';
        ?>
        <select name="wc_address_sync_options[sync_direction]">
            <option value="both" <?php selected($direction, 'both'); ?>><?php _e('Both Ways (Billing ↔ Shipping)', 'wc-address-sync'); ?></option>
            <option value="billing_to_shipping" <?php selected($direction, 'billing_to_shipping'); ?>><?php _e('Billing to Shipping Only', 'wc-address-sync'); ?></option>
            <option value="shipping_to_billing" <?php selected($direction, 'shipping_to_billing'); ?>><?php _e('Shipping to Billing Only', 'wc-address-sync'); ?></option>
        </select>
        <p class="description"><?php _e('Choose which direction to sync addresses', 'wc-address-sync'); ?></p>
        <?php
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Address Sync', 'wc-address-sync'),
            __('Address Sync', 'wc-address-sync'),
            'manage_woocommerce',
            'wc-address-sync',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_scripts($hook) {
        if ($hook === 'woocommerce_page_wc-address-sync') {
            wp_enqueue_script('jquery');
            wp_enqueue_script('wc-address-sync-admin', plugin_dir_url(__FILE__) . 'admin.js', array('jquery'), '1.0.0', true);
            wp_localize_script('wc-address-sync-admin', 'wc_address_sync_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_address_sync_nonce')
            ));
        }
    }
    
    /**
     * Admin page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce Address Sync', 'wc-address-sync'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('wc_address_sync_settings');
                do_settings_sections('wc_address_sync_settings');
                submit_button();
                ?>
            </form>
            
            <hr>
            
            <h2><?php _e('Bulk Operations', 'wc-address-sync'); ?></h2>
            
			
            <div class="card">
                <h3><?php _e('Sync All Orders', 'wc-address-sync'); ?></h3>
                <p><?php _e('This will sync addresses for all orders with incomplete address information.', 'wc-address-sync'); ?></p>
                <button type="button" id="bulk-sync-orders" class="button button-primary">
                    <?php _e('Sync All Orders', 'wc-address-sync'); ?>
                </button>
                <div id="bulk-sync-orders-progress" style="display: none;">
                    <p><?php _e('Processing...', 'wc-address-sync'); ?> <span id="orders-progress-text">0</span></p>
                </div>
            </div>
            
        </div>
        <?php
    }
    
    /**
     * Check if address is complete
     */
    private function is_address_complete($address) {
        $required_fields = array('first_name', 'last_name', 'address_1', 'city', 'state', 'postcode', 'country');
        
        foreach ($required_fields as $field) {
            if (empty($address[$field])) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Sync addresses for a customer
     */
    // Removed: syncing customer meta is no longer supported; orders only
    
    /**
     * Sync addresses for an order
     */
    public function sync_order_addresses($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $options = get_option('wc_address_sync_options');
        $direction = isset($options['sync_direction']) ? $options['sync_direction'] : 'both';
        
        $billing_address = $order->get_address('billing');
        $shipping_address = $order->get_address('shipping');
        
        $updated = false;
        
        $fields = array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country');
        
        // Sync based on direction setting - set only if target empty and source has value
        if ($direction === 'both' || $direction === 'billing_to_shipping') {
            foreach ($fields as $field) {
                $source_value = isset($billing_address[$field]) ? $billing_address[$field] : '';
                $target_value = isset($shipping_address[$field]) ? $shipping_address[$field] : '';
                if (empty($target_value) && !empty($source_value)) {
                    $setter = 'set_shipping_' . $field;
                    if (method_exists($order, $setter)) {
                        $order->{$setter}($source_value);
                        $updated = true;
                    }
                }
            }
        }
        
        if ($direction === 'both' || $direction === 'shipping_to_billing') {
            foreach ($fields as $field) {
                $source_value = isset($shipping_address[$field]) ? $shipping_address[$field] : '';
                $target_value = isset($billing_address[$field]) ? $billing_address[$field] : '';
                if (empty($target_value) && !empty($source_value)) {
                    $setter = 'set_billing_' . $field;
                    if (method_exists($order, $setter)) {
                        $order->{$setter}($source_value);
                        $updated = true;
                    }
                }
            }
        }
        
        if ($updated) {
            $order->save();
            return true;
        }
        
        return false;
    }
    
    // Removed checkout and customer save sync handlers
    
    /**
     * Sync addresses when order is saved from admin dashboard
     */
    public function sync_addresses_on_order_save($post_id, $post) {
        // Check if auto sync is enabled
        $options = get_option('wc_address_sync_options');
        $auto_sync_enabled = isset($options['auto_sync_enabled']) ? $options['auto_sync_enabled'] : 1;
        
        if (!$auto_sync_enabled) {
            return;
        }
        
        // Only process shop orders
        if ($post->post_type !== 'shop_order') {
            return;
        }
        
        // Sync the order addresses
        $this->sync_order_addresses($post_id);
    }
    
    /**
     * Add sync button to order edit page
     */
    public function add_sync_button_to_order($order) {
        ?>
        <p class="form-field form-field-wide">
            <button type="button" class="button" id="sync-order-addresses" data-order-id="<?php echo $order->get_id(); ?>">
                <?php _e('Sync Addresses', 'wc-address-sync'); ?>
            </button>
        </p>
        <?php
    }
    
    /**
     * AJAX handler for syncing single order
     */
    public function sync_single_order_addresses() {
        check_ajax_referer('wc_address_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'wc-address-sync'));
        }
        
        $order_id = intval($_POST['order_id']);
        $result = $this->sync_order_addresses($order_id);
        
        wp_send_json(array(
            'success' => $result,
            'message' => $result ? __('Addresses synced successfully', 'wc-address-sync') : __('No sync needed or addresses already complete', 'wc-address-sync')
        ));
    }
    
    /**
     * AJAX handler for bulk sync
     */
    public function bulk_sync_addresses() {
        check_ajax_referer('wc_address_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'wc-address-sync'));
        }
        
        $type = sanitize_text_field($_POST['type']);
        $offset = intval($_POST['offset']);
        $limit = 10; // Process 10 at a time
        
        $synced = 0;
        
        if ($type === 'orders') {
            $orders = wc_get_orders(array(
                'limit' => $limit,
                'offset' => $offset,
                'status' => array('wc-processing', 'wc-completed', 'wc-pending', 'wc-on-hold')
            ));
            
            foreach ($orders as $order) {
                if ($this->sync_order_addresses($order->get_id())) {
                    $synced++;
                }
            }
            
            $total_orders = wp_count_posts('shop_order');
            $total_orders_count = array_sum((array) $total_orders);
            $has_more = ($offset + $limit) < $total_orders_count;
        } else {
            $has_more = false;
        }
        
        wp_send_json(array(
            'synced' => $synced,
            'has_more' => $has_more,
            'offset' => $offset + $limit
        ));
    }
    
}

// Initialize the plugin
new WC_Address_Sync();
