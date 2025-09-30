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
        $.ajax({
            url: wc_address_sync_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'get_order_addresses',
                order_id: orderId,
                nonce: wc_address_sync_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateFormFields(response.data);
                }
            },
            error: function() {
                console.log('Failed to refresh order addresses');
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
            var selector = 'input[name="_' + type + '_' + field + '"]';
            var $field = $(selector);
            
            if ($field.length) {
                $field.val(value);
                // Trigger change event to notify other scripts
                $field.trigger('change');
            }
        });
    }
    
    // Also refresh on page load if we detect the page was just saved
    if (window.location.search.indexOf('message=') !== -1) {
        setTimeout(function() {
            refreshOrderAddressFields();
        }, 2000);
    }
});
