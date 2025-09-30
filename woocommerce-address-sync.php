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

// Load debug file if debug is enabled
if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
    require_once plugin_dir_path(__FILE__) . 'debug.php';
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
		add_action('woocommerce_admin_order_data_after_save', array($this, 'sync_addresses_on_order_admin_save'), 10, 1);
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
        
        // Add debug page if debug is enabled
        if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
            add_submenu_page(
                'woocommerce',
                __('Address Sync Debug', 'wc-address-sync'),
                __('Address Sync Debug', 'wc-address-sync'),
                'manage_woocommerce',
                'wc-address-sync-debug',
                array($this, 'debug_page')
            );
        }
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
     * Debug page
     */
    public function debug_page() {
        if (isset($_POST['clear_log']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_debug_log')) {
            WC_Address_Sync_Debug::clear_log();
            echo '<div class="notice notice-success"><p>Debug log cleared.</p></div>';
        }
        
        $log_content = WC_Address_Sync_Debug::get_log_content();
        ?>
        <div class="wrap">
            <h1><?php _e('Address Sync Debug', 'wc-address-sync'); ?></h1>
            
            <p><?php _e('To enable debug logging, add this to your wp-config.php:', 'wc-address-sync'); ?></p>
            <code>define('WC_ADDRESS_SYNC_DEBUG', true);</code>
            
            <hr>
            
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('clear_debug_log'); ?>
                <button type="submit" name="clear_log" class="button"><?php _e('Clear Debug Log', 'wc-address-sync'); ?></button>
            </form>
            
            <hr>
            
            <h2><?php _e('Debug Log', 'wc-address-sync'); ?></h2>
            <textarea readonly style="width: 100%; height: 500px; font-family: monospace;"><?php echo esc_textarea($log_content); ?></textarea>
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
	 * Update order address meta directly in postmeta table (classic storage)
	 */
	private function update_order_address_meta($order_id, $address_type, $field, $value) {
		$meta_key = '_' . $address_type . '_' . $field; // e.g. _billing_city, _shipping_address_1
		update_post_meta($order_id, $meta_key, $value);
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
            if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
                WC_Address_Sync_Debug::log("Order not found", array('order_id' => $order_id));
            }
            return false;
        }
        
        $options = get_option('wc_address_sync_options');
        $direction = isset($options['sync_direction']) ? $options['sync_direction'] : 'both';
        $auto_sync_enabled = isset($options['auto_sync_enabled']) ? $options['auto_sync_enabled'] : 1;
        
        if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
            WC_Address_Sync_Debug::log("Starting sync", array(
                'order_id' => $order_id,
                'direction' => $direction,
                'auto_sync_enabled' => $auto_sync_enabled
            ));
        }
        
        $billing_address = $order->get_address('billing');
        $shipping_address = $order->get_address('shipping');
        
        $updated = false;
        $fields_updated = array();
        
        $fields = array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country');
        
        // Sync based on direction setting - set only if target empty and source has value
        if ($direction === 'both' || $direction === 'billing_to_shipping') {
            foreach ($fields as $field) {
                $source_value = isset($billing_address[$field]) ? $billing_address[$field] : '';
                $target_value = isset($shipping_address[$field]) ? $shipping_address[$field] : '';
                
                if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
                    WC_Address_Sync_Debug::debug_field_update($order_id, $field, $source_value, $target_value, 'billing_to_shipping');
                }
                
                if (empty($target_value) && !empty($source_value)) {
                    $setter = 'set_shipping_' . $field;
                    if (method_exists($order, $setter)) {
                        $order->{$setter}($source_value);
                        $updated = true;
                        $fields_updated[] = "shipping_{$field}";
                    }
					// Ensure classic postmeta storage is updated
					$this->update_order_address_meta($order_id, 'shipping', $field, $source_value);
                }
            }
        }
        
        if ($direction === 'both' || $direction === 'shipping_to_billing') {
            foreach ($fields as $field) {
                $source_value = isset($shipping_address[$field]) ? $shipping_address[$field] : '';
                $target_value = isset($billing_address[$field]) ? $billing_address[$field] : '';
                
                if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
                    WC_Address_Sync_Debug::debug_field_update($order_id, $field, $source_value, $target_value, 'shipping_to_billing');
                }
                
                if (empty($target_value) && !empty($source_value)) {
                    $setter = 'set_billing_' . $field;
                    if (method_exists($order, $setter)) {
                        $order->{$setter}($source_value);
                        $updated = true;
                        $fields_updated[] = "billing_{$field}";
                    }
					// Ensure classic postmeta storage is updated
					$this->update_order_address_meta($order_id, 'billing', $field, $source_value);
                }
            }
        }
        
        if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
            WC_Address_Sync_Debug::debug_sync_process($order_id, $billing_address, $shipping_address, $direction, $fields_updated);
        }
        
        if ($updated) {
            $order->save();
            if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
                WC_Address_Sync_Debug::log("Order saved after sync", array('order_id' => $order_id, 'fields_updated' => $fields_updated));
            }
            return true;
        }
        
        if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
            WC_Address_Sync_Debug::log("No updates needed", array('order_id' => $order_id));
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
	 * Ensure sync runs after admin save as well
	 */
	public function sync_addresses_on_order_admin_save($order) {
		$options = get_option('wc_address_sync_options');
		$auto_sync_enabled = isset($options['auto_sync_enabled']) ? $options['auto_sync_enabled'] : 1;
		if (!$auto_sync_enabled) {
			return;
		}
		if ($order && is_a($order, 'WC_Order')) {
			$this->sync_order_addresses($order->get_id());
		}
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
