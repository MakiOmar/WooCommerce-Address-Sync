jQuery(document).ready(function($) {
    
    // Removed customer bulk sync
    
    // Bulk sync orders
    $('#bulk-sync-orders').on('click', function() {
        var button = $(this);
        var progress = $('#bulk-sync-orders-progress');
        var progressText = $('#orders-progress-text');
        
        button.prop('disabled', true);
        progress.show();
        
        var offset = 0;
        var totalSynced = 0;
        
        function processBatch() {
            $.ajax({
                url: wc_address_sync_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'bulk_sync_addresses',
                    type: 'orders',
                    offset: offset,
                    nonce: wc_address_sync_ajax.nonce
                },
                success: function(response) {
                    totalSynced += response.synced;
                    progressText.text(totalSynced + ' orders synced');
                    
                    if (response.has_more) {
                        offset = response.offset;
                        setTimeout(processBatch, 1000); // 1 second delay between batches
                    } else {
                        button.prop('disabled', false);
                        progress.hide();
                        alert('Bulk sync completed! ' + totalSynced + ' orders were synced.');
                    }
                },
                error: function() {
                    button.prop('disabled', false);
                    progress.hide();
                    alert('An error occurred during bulk sync.');
                }
            });
        }
        
        processBatch();
    });
    
    // Sync single order
    $('#sync-order-addresses').on('click', function() {
        var button = $(this);
        var orderId = button.data('order-id');
        
        button.prop('disabled', true).text('Syncing...');
        
        $.ajax({
            url: wc_address_sync_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'sync_single_order_addresses',
                order_id: orderId,
                nonce: wc_address_sync_ajax.nonce
            },
            success: function(response) {
                button.prop('disabled', false).text('Sync Addresses');
                alert(response.message);
            },
            error: function() {
                button.prop('disabled', false).text('Sync Addresses');
                alert('An error occurred while syncing addresses.');
            }
        });
    });
    
});
