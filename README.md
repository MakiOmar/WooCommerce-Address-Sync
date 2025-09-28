# WooCommerce Address Sync Plugin

A WordPress plugin that automatically synchronizes incomplete billing and shipping addresses in WooCommerce, solving the common issue where customers have complete information in one address type but incomplete in the other.

## Features

- **Automatic Sync**: Automatically syncs addresses during checkout and customer profile updates
- **Bulk Operations**: Sync all existing customers and orders with incomplete addresses
- **Flexible Direction**: Choose sync direction (billing to shipping, shipping to billing, or both ways)
- **Admin Interface**: Easy-to-use admin panel for managing address synchronization
- **Order Management**: Sync addresses for individual orders from the order edit page
- **Statistics**: View counts of customers and orders with incomplete addresses

## Installation

1. Upload the plugin files to `/wp-content/plugins/woocommerce-address-sync/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce > Address Sync to configure settings

## Usage

### Automatic Sync
The plugin automatically syncs addresses when:
- Customers complete checkout
- Customers update their address in their account
- Orders are processed

### Manual Sync
1. Go to **WooCommerce > Address Sync** in your WordPress admin
2. Configure your sync preferences:
   - **Enable Auto Sync**: Automatically sync addresses during checkout and profile updates
   - **Sync Direction**: Choose which direction to sync addresses
3. Use bulk operations to sync existing data:
   - **Sync All Customers**: Sync addresses for all customers with incomplete information
   - **Sync All Orders**: Sync addresses for all orders with incomplete information

### Individual Order Sync
- Go to any order in **WooCommerce > Orders**
- Click the "Sync Addresses" button to sync that specific order

## Settings

### Sync Direction Options
- **Both Ways (Billing ↔ Shipping)**: Syncs from complete address to incomplete address
- **Billing to Shipping Only**: Only syncs from billing to shipping addresses
- **Shipping to Billing Only**: Only syncs from shipping to billing addresses

## How It Works

The plugin identifies incomplete addresses by checking for missing required fields:
- First Name
- Last Name
- Address Line 1
- City
- State/Province
- Postal Code
- Country

When an incomplete address is found, the plugin copies the complete address information from the other address type, ensuring all shipping plugins have the necessary information regardless of which address type they use.

## Compatibility

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+

## Support

This plugin follows WordPress PHP Coding Standards (WPCS) and uses standard WordPress and WooCommerce hooks for maximum compatibility.

## Changelog

### Version 1.0.0
- Initial release
- Automatic address synchronization
- Bulk sync operations
- Admin interface
- Flexible sync directions
- Order management integration
