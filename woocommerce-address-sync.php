<?php
/**
 * Plugin Name: WooCommerce Address Sync
 * Plugin URI: https://github.com/MakiOmar/WooCommerce-Address-Sync
 * Description: Automatically syncs incomplete billing and shipping addresses in WooCommerce
 * Version: 1.0.2
 * Author: Mohammed Omar
 * License: GPL v2 or later
 * Text Domain: wc-address-sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Include the Plugin Update Checker library
require_once plugin_dir_path(__FILE__) . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Initialize update checker
$wc_address_sync_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/MakiOmar/WooCommerce-Address-Sync/raw/main/update-info.json',
    __FILE__,
    'woocommerce-address-sync'
);

// Add custom headers to avoid rate limiting
if (method_exists($wc_address_sync_update_checker, 'addHttpRequestArgFilter')) {
    $wc_address_sync_update_checker->addHttpRequestArgFilter(function($options) {
        if (!isset($options['headers'])) {
            $options['headers'] = array();
        }
        
        $options['headers']['User-Agent'] = 'WooCommerce-Address-Sync/1.0.2';
        $options['headers']['Accept'] = 'application/vnd.github.v3+json';
        $options['headers']['X-Plugin-Name'] = 'WooCommerce Address Sync';
        $options['headers']['X-Plugin-Version'] = '1.0.2';
        $options['headers']['Cache-Control'] = 'no-cache';
        
        return $options;
    });
}

// Enable debug mode if WP_DEBUG is on
if (defined('WP_DEBUG') && WP_DEBUG) {
    add_filter('puc_manual_final_check-woocommerce-address-sync', '__return_true');
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
    
    private $synced_orders = array(); // Track synced orders to prevent duplicate syncs
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
		// WooCommerce hooks - use high priority to run AFTER WooCommerce saves the meta
		add_action('woocommerce_process_shop_order_meta', array($this, 'sync_addresses_on_order_save'), 60, 2);
		add_action('wpo_order_created', array($this, 'sync_addresses_on_wpo_order_created'), 60, 2);
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'add_sync_button_to_order'));
        add_action('wp_ajax_sync_single_order_addresses', array($this, 'sync_single_order_addresses'));
        add_action('wp_ajax_bulk_sync_addresses', array($this, 'bulk_sync_addresses'));
        add_action('wp_ajax_get_order_addresses', array($this, 'get_order_addresses_ajax'));
        
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
        
        // Add update checker debug page
        add_submenu_page(
            'woocommerce',
            __('Address Sync Updates', 'wc-address-sync'),
            __('Updates Check', 'wc-address-sync'),
            'manage_woocommerce',
            'wc-address-sync-updates',
            array($this, 'updates_debug_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_scripts($hook) {
        if ($hook === 'woocommerce_page_wc-address-sync') {
            wp_enqueue_script('jquery');
            wp_enqueue_script('wc-address-sync-admin', plugin_dir_url(__FILE__) . 'admin.js', array('jquery'), time(), true);
            wp_localize_script('wc-address-sync-admin', 'wc_address_sync_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_address_sync_nonce')
            ));
        }
        
        // Add script to order edit page to refresh form fields after save
        if (strpos($hook, 'post.php') !== false && isset($_GET['post']) && get_post_type($_GET['post']) === 'shop_order') {
            wp_enqueue_script('wc-address-sync-order-edit', plugin_dir_url(__FILE__) . 'order-edit.js', array('jquery'), time(), true);
            wp_localize_script('wc-address-sync-order-edit', 'wc_address_sync_ajax', array(
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
     * Updates debug page
     */
    public function updates_debug_page() {
        global $wc_address_sync_update_checker;
        
        // Handle clear cache action
        if (isset($_POST['clear_update_cache']) && wp_verify_nonce($_POST['_wpnonce'], 'clear_update_cache')) {
            delete_site_transient('update_plugins');
            delete_transient('puc_request_info-woocommerce-address-sync');
            
            if ($wc_address_sync_update_checker) {
                $wc_address_sync_update_checker->resetUpdateState();
            }
            
            echo '<div class="notice notice-success"><p>Update cache cleared! Click "Check for Updates" below.</p></div>';
        }
        
        // Handle force check action
        if (isset($_POST['force_check']) && wp_verify_nonce($_POST['_wpnonce'], 'force_check_updates')) {
            if ($wc_address_sync_update_checker) {
                $wc_address_sync_update_checker->checkForUpdates();
            }
            echo '<div class="notice notice-success"><p>Forced update check completed!</p></div>';
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Plugin Update Checker Debug', 'wc-address-sync'); ?></h1>
            
            <div class="card">
                <h2>Current Status</h2>
                <?php
                $plugin_file = WP_PLUGIN_DIR . '/woocommerce-address-sync/woocommerce-address-sync.php';
                if (!function_exists('get_plugin_data')) {
                    require_once ABSPATH . 'wp-admin/includes/plugin.php';
                }
                $plugin_data = get_plugin_data($plugin_file);
                $current_version = $plugin_data['Version'];
                ?>
                <p><strong>Installed Version:</strong> <?php echo esc_html($current_version); ?></p>
                <p><strong>Update Checker Status:</strong> <?php echo $wc_address_sync_update_checker ? 'Initialized ✓' : 'Not Initialized ✗'; ?></p>
                <p><strong>Update Info URL:</strong> <a href="https://github.com/MakiOmar/WooCommerce-Address-Sync/raw/main/update-info.json" target="_blank">View update-info.json</a></p>
                
                <?php if ($wc_address_sync_update_checker): ?>
                    <?php
                    $update = $wc_address_sync_update_checker->getUpdate();
                    ?>
                    <p><strong>Update Available:</strong> 
                        <?php if ($update): ?>
                            <span style="color: green;">Yes - Version <?php echo esc_html($update->version); ?></span>
                        <?php else: ?>
                            <span>No (up to date)</span>
                        <?php endif; ?>
                    </p>
                    
                    <?php if ($update): ?>
                        <div style="background: #e7f7e7; padding: 15px; border-left: 4px solid green; margin: 15px 0;">
                            <h3 style="margin-top: 0;">New Version Available: <?php echo esc_html($update->version); ?></h3>
                            <p><strong>Download URL:</strong> <?php echo esc_html($update->download_url); ?></p>
                            <?php if (!empty($update->tested)): ?>
                                <p><strong>Tested up to:</strong> WordPress <?php echo esc_html($update->tested); ?></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <div class="card" style="margin-top: 20px;">
                <h2>Actions</h2>
                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('clear_update_cache'); ?>
                    <button type="submit" name="clear_update_cache" class="button button-primary">Clear Update Cache</button>
                    <p class="description">Clears WordPress update cache and plugin update checker cache</p>
                </form>
                
                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('force_check_updates'); ?>
                    <button type="submit" name="force_check" class="button">Force Check for Updates</button>
                    <p class="description">Immediately checks for updates from GitHub</p>
                </form>
            </div>
            
            <div class="card" style="margin-top: 20px;">
                <h2>Troubleshooting</h2>
                <ol>
                    <li>Click "Clear Update Cache" to remove cached update data</li>
                    <li>Click "Force Check for Updates" to check for new versions immediately</li>
                    <li>Go to Dashboard → Updates to see if update appears</li>
                    <li>Verify update-info.json is accessible (click link above)</li>
                </ol>
                
                <h3>Cache Transients</h3>
                <ul>
                    <li>WordPress update_plugins: <?php echo get_site_transient('update_plugins') ? 'Cached' : 'Not cached'; ?></li>
                    <li>PUC request info: <?php echo get_transient('puc_request_info-woocommerce-address-sync') ? 'Cached' : 'Not cached'; ?></li>
                </ul>
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
	 * Update order address meta - compatible with both HPOS and classic storage
	 */
	private function update_order_address_meta($order_id, $address_type, $field, $value) {
		$meta_key = '_' . $address_type . '_' . $field; // e.g. _billing_city, _shipping_address_1
		
		// Check if HPOS is enabled
		$hpos_enabled = class_exists('Automattic\WooCommerce\Utilities\OrderUtil') 
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		
		if ($hpos_enabled) {
			// Use order object for HPOS
			$order = wc_get_order($order_id);
			if ($order) {
				$order->update_meta_data($meta_key, $value);
				$result = $order->save_meta_data();
			} else {
				$result = false;
			}
		} else {
			// Use postmeta for classic storage
			$result = update_post_meta($order_id, $meta_key, $value);
		}
		
		if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
			WC_Address_Sync_Debug::log("Meta update", array(
				'order_id' => $order_id,
				'meta_key' => $meta_key,
				'value' => $value,
				'hpos_enabled' => $hpos_enabled,
				'update_result' => $result,
				'current_meta_value' => $hpos_enabled ? ($order ? $order->get_meta($meta_key) : 'order not found') : get_post_meta($order_id, $meta_key, true)
			));
		}
	}
    
    /**
     * Sync addresses for a customer
     */
    // Removed: syncing customer meta is no longer supported; orders only
    
    /**
     * Sync addresses for an order
     */
    public function sync_order_addresses($order_id, $exclude_fields = array()) {
        // Prevent duplicate syncs in the same request
        if (isset($this->synced_orders[$order_id])) {
            if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
                WC_Address_Sync_Debug::log("Skipping duplicate sync", array('order_id' => $order_id));
            }
            return false;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
                WC_Address_Sync_Debug::log("Order not found", array('order_id' => $order_id));
            }
            return false;
        }
        
        // Mark this order as synced
        $this->synced_orders[$order_id] = true;
        
        $options = get_option('wc_address_sync_options');
        $direction = isset($options['sync_direction']) ? $options['sync_direction'] : 'both';
        $auto_sync_enabled = isset($options['auto_sync_enabled']) ? $options['auto_sync_enabled'] : 1;
        
        // Check HPOS status
        $hpos_enabled = class_exists('Automattic\WooCommerce\Utilities\OrderUtil') 
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        
        if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
            // Clear cache
            wp_cache_delete($order_id, 'post_meta');
            
            // Direct database query
            global $wpdb;
            $db_billing_city = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_billing_city'",
                $order_id
            ));
            
            WC_Address_Sync_Debug::log("Starting sync", array(
                'order_id' => $order_id,
                'direction' => $direction,
                'auto_sync_enabled' => $auto_sync_enabled,
                'hpos_enabled' => $hpos_enabled,
                'billing_city_postmeta' => get_post_meta($order_id, '_billing_city', true),
                'billing_city_order_meta' => $order->get_meta('_billing_city'),
                'billing_city_getter' => $order->get_billing_city(),
                'billing_city_db_direct' => $db_billing_city
            ));
        }
        
        $billing_address = $order->get_address('billing');
        $shipping_address = $order->get_address('shipping');
        
        $updated = false;
        $fields_updated = array();
        
        $fields = array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone');
        
        // Sync based on direction setting - set only if target empty and source has value
        if ($direction === 'both' || $direction === 'billing_to_shipping') {
            foreach ($fields as $field) {
                // Skip if this field was just submitted in the form (user is intentionally setting it)
                if (in_array("shipping_{$field}", $exclude_fields)) {
                    if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
                        WC_Address_Sync_Debug::log("Skipping field (user submitted)", array(
                            'field' => "shipping_{$field}"
                        ));
                    }
                    continue;
                }
                
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
                // Skip if this field was just submitted in the form (user is intentionally setting it)
                if (in_array("billing_{$field}", $exclude_fields)) {
                    if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
                        WC_Address_Sync_Debug::log("Skipping field (user submitted)", array(
                            'field' => "billing_{$field}"
                        ));
                    }
                    continue;
                }
                
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
                
                // Check addresses after save
                $order_after = wc_get_order($order_id);
                if ($order_after) {
                    // Clear cache to force fresh read
                    wp_cache_delete($order_id, 'post_meta');
                    
                    // Direct database query to verify
                    global $wpdb;
                    $db_billing_city = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_billing_city'",
                        $order_id
                    ));
                    $db_shipping_city = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_shipping_city'",
                        $order_id
                    ));
                    
                    $billing_after = $order_after->get_address('billing');
                    $shipping_after = $order_after->get_address('shipping');
                    WC_Address_Sync_Debug::log("Addresses after save", array(
                        'billing' => $billing_after,
                        'shipping' => $shipping_after,
                        'billing_city_postmeta' => get_post_meta($order_id, '_billing_city', true),
                        'billing_city_order_meta' => $order_after->get_meta('_billing_city'),
                        'billing_city_getter' => $order_after->get_billing_city(),
                        'billing_city_db_direct' => $db_billing_city,
                        'shipping_city_postmeta' => get_post_meta($order_id, '_shipping_city', true),
                        'shipping_city_order_meta' => $order_after->get_meta('_shipping_city'),
                        'shipping_city_getter' => $order_after->get_shipping_city(),
                        'shipping_city_db_direct' => $db_shipping_city
                    ));
                }
            }
            return true;
        }
        
        if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
            WC_Address_Sync_Debug::log("No updates needed", array(
                'order_id' => $order_id,
                'billing_city_postmeta' => get_post_meta($order_id, '_billing_city', true),
                'billing_city_order_meta' => $order->get_meta('_billing_city'),
                'billing_city_getter' => $order->get_billing_city(),
                'shipping_city_postmeta' => get_post_meta($order_id, '_shipping_city', true),
                'shipping_city_order_meta' => $order->get_meta('_shipping_city'),
                'shipping_city_getter' => $order->get_shipping_city()
            ));
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
        
        // Detect which fields were submitted in the form
        $exclude_fields = $this->get_submitted_fields_from_post();
        
        if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
            // Clear cache
            wp_cache_delete($post_id, 'post_meta');
            
            // Direct database query
            global $wpdb;
            $db_billing_city = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_billing_city'",
                $post_id
            ));
            
            WC_Address_Sync_Debug::log("Hook: woocommerce_process_shop_order_meta", array(
                'post_id' => $post_id,
                'post_type' => $post->post_type,
                'auto_sync_enabled' => $auto_sync_enabled,
                'billing_city_postmeta' => get_post_meta($post_id, '_billing_city', true),
                'billing_city_db_direct' => $db_billing_city,
                'billing_city_from_post' => isset($_POST['_billing_city']) ? $_POST['_billing_city'] : 'not set',
                'shipping_city_from_post' => isset($_POST['_shipping_city']) ? $_POST['_shipping_city'] : 'not set',
                'exclude_fields' => $exclude_fields
            ));
        }
        
        if (!$auto_sync_enabled) {
            return;
        }
        
        // Only process shop orders
        if ($post->post_type !== 'shop_order') {
            return;
        }
        
        // Sync the order addresses, excluding fields that were just submitted
        $this->sync_order_addresses($post_id, $exclude_fields);
    }

	/**
	 * Sync addresses when order is updated (more reliable hook)
	 * DISABLED: This hook was causing conflicts with manual saves
	 */
	/*
	public function sync_addresses_on_order_update($order_id) {
		$options = get_option('wc_address_sync_options');
		$auto_sync_enabled = isset($options['auto_sync_enabled']) ? $options['auto_sync_enabled'] : 1;
		
		if (!$auto_sync_enabled) {
			return;
		}
		
		// Detect which fields were submitted in the form
		$exclude_fields = $this->get_submitted_fields_from_post();
		
		if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
			// Clear cache
			wp_cache_delete($order_id, 'post_meta');
			
			// Direct database query
			global $wpdb;
			$db_billing_city = $wpdb->get_var($wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_billing_city'",
				$order_id
			));
			
			$order = wc_get_order($order_id);
			
			WC_Address_Sync_Debug::log("Hook: woocommerce_update_order", array(
				'order_id' => $order_id,
				'auto_sync_enabled' => $auto_sync_enabled,
				'billing_city_postmeta' => get_post_meta($order_id, '_billing_city', true),
				'billing_city_getter' => $order ? $order->get_billing_city() : 'n/a',
				'billing_city_db_direct' => $db_billing_city,
				'exclude_fields' => $exclude_fields
			));
		}
		
		$this->sync_order_addresses($order_id, $exclude_fields);
	}
	*/
	
	/**
	 * Sync addresses when order is created via WPO hook (PDF Invoices, etc.)
	 */
	public function sync_addresses_on_wpo_order_created($order, $cart_data) {
		$options = get_option('wc_address_sync_options');
		$auto_sync_enabled = isset($options['auto_sync_enabled']) ? $options['auto_sync_enabled'] : 1;
		
		if (!$auto_sync_enabled) {
			return;
		}
		
		$order_id = is_object($order) ? $order->get_id() : $order;
		
		if (defined('WC_ADDRESS_SYNC_DEBUG') && WC_ADDRESS_SYNC_DEBUG) {
			WC_Address_Sync_Debug::log("Hook: wpo_order_created", array(
				'order_id' => $order_id,
				'has_order_object' => is_object($order),
				'has_cart_data' => !empty($cart_data)
			));
		}
		
		// No need to exclude fields here since this is programmatic order creation
		$this->sync_order_addresses($order_id, array());
	}
	
	/**
	 * Get list of fields that were submitted in the form (should be excluded from sync)
	 */
	private function get_submitted_fields_from_post() {
		$exclude_fields = array();
		$fields = array('first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'phone');
		
		// Check billing fields
		foreach ($fields as $field) {
			$post_key = '_billing_' . $field;
			if (isset($_POST[$post_key]) && $_POST[$post_key] !== '') {
				$exclude_fields[] = 'billing_' . $field;
			}
		}
		
		// Check shipping fields
		foreach ($fields as $field) {
			$post_key = '_shipping_' . $field;
			if (isset($_POST[$post_key]) && $_POST[$post_key] !== '') {
				$exclude_fields[] = 'shipping_' . $field;
			}
		}
		
		return $exclude_fields;
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
            <button type="button" class="button" id="refresh-form-fields" data-order-id="<?php echo $order->get_id(); ?>">
                <?php _e('Refresh Form Fields', 'wc-address-sync'); ?>
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
    
    /**
     * AJAX handler for getting order addresses
     */
    public function get_order_addresses_ajax() {
        check_ajax_referer('wc_address_sync_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Insufficient permissions', 'wc-address-sync'));
        }
        
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if (!$order) {
            wp_send_json_error(__('Order not found', 'wc-address-sync'));
        }
        
        $billing_address = $order->get_address('billing');
        $shipping_address = $order->get_address('shipping');
        
        wp_send_json_success(array(
            'billing' => $billing_address,
            'shipping' => $shipping_address
        ));
    }
    
}

// Initialize the plugin
new WC_Address_Sync();
