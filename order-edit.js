jQuery(document).ready(function($) {
    
    // Listen for order save events
    $(document).on('click', '#publish, #save-post', function() {
        // Add a small delay to allow the save to complete
        setTimeout(function() {
            refreshOrderAddressFields();
        }, 1000);
    });
    
    // Also listen for WooCommerce specific save events
    $(document).on('woocommerce_order_saved', function() {
        setTimeout(function() {
            refreshOrderAddressFields();
        }, 500);
    });
    
    function refreshOrderAddressFields() {
        // Get the order ID from the URL or form
        var orderId = getOrderId();
        if (!orderId) return;
        
        // Fetch updated order data via AJAX
        console.log('Fetching order addresses for order ID: ' + orderId);
        $.ajax({
            url: wc_address_sync_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'get_order_addresses',
                order_id: orderId,
                nonce: wc_address_sync_ajax.nonce
            },
            success: function(response) {
                console.log('AJAX response:', response);
                if (response.success) {
                    updateFormFields(response.data);
                } else {
                    console.log('AJAX error:', response.data);
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX failed:', status, error);
            }
        });
    }
    
    function getOrderId() {
        // Try to get order ID from various sources
        var orderId = null;
        
        // From URL parameter
        var urlParams = new URLSearchParams(window.location.search);
        orderId = urlParams.get('post');
        
        // From form field
        if (!orderId) {
            orderId = $('#post_ID').val();
        }
        
        // From data attribute
        if (!orderId) {
            orderId = $('body').data('order-id');
        }
        
        return orderId;
    }
    
    function updateFormFields(addressData) {
        // Update billing address fields
        if (addressData.billing) {
            updateAddressFields('billing', addressData.billing);
        }
        
        // Update shipping address fields
        if (addressData.shipping) {
            updateAddressFields('shipping', addressData.shipping);
        }
    }
    
    function updateAddressFields(type, address) {
        var fields = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country'];
        
        fields.forEach(function(field) {
            var value = address[field] || '';
            var selectors = [
                'input[name="_' + type + '_' + field + '"]',
                'input[name="' + type + '_' + field + '"]',
                '#' + type + '_' + field,
                'input[id*="' + type + '_' + field + '"]'
            ];
            
            var $field = null;
            for (var i = 0; i < selectors.length; i++) {
                $field = $(selectors[i]);
                if ($field.length) {
                    console.log('Found field for ' + type + '_' + field + ' with selector: ' + selectors[i]);
                    break;
                }
            }
            
            if ($field && $field.length) {
                console.log('Updating ' + type + '_' + field + ' to: ' + value);
                $field.val(value);
                // Trigger change event to notify other scripts
                $field.trigger('change');
            } else {
                console.log('Field not found for ' + type + '_' + field);
            }
        });
    }
    
    // Sync Addresses button
    $(document).on('click', '#sync-order-addresses', function() {
        var $button = $(this);
        var orderId = $button.data('order-id') || getOrderId();
        
        if (!orderId) {
            alert('Order ID not found');
            return;
        }
        
        $button.prop('disabled', true).text('Syncing...');
        console.log('Manual sync triggered for order: ' + orderId);
        
        $.ajax({
            url: wc_address_sync_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sync_single_order_addresses',
                order_id: orderId,
                nonce: wc_address_sync_ajax.nonce
            },
            success: function(response) {
                console.log('Sync response:', response);
                $button.prop('disabled', false).text('Sync Addresses');
                
                if (response.success) {
                    alert(response.message || 'Addresses synced successfully!');
                    // Refresh the form fields to show the synced data
                    setTimeout(function() {
                        refreshOrderAddressFields();
                    }, 500);
                } else {
                    alert(response.message || 'Failed to sync addresses');
                }
            },
            error: function(xhr, status, error) {
                console.log('Sync AJAX failed:', status, error);
                $button.prop('disabled', false).text('Sync Addresses');
                alert('Error syncing addresses. Check console for details.');
            }
        });
    });
    
    // Manual refresh button
    $(document).on('click', '#refresh-form-fields', function() {
        console.log('Manual refresh triggered');
        refreshOrderAddressFields();
    });
    
    // Also refresh on page load if we detect the page was just saved
    if (window.location.search.indexOf('message=') !== -1) {
        setTimeout(function() {
            refreshOrderAddressFields();
        }, 2000);
    }
});
