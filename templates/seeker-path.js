jQuery(document).ready(function($) {
    $('#stage-list').sortable({
        placeholder: "sortable-placeholder",
        forcePlaceholderSize: true,
        update: function(event, ui) {
            var stageOrder = [];
            $('#stage-list li').each(function(index) {
                stageOrder.push({
                    id: $(this).data('id'),
                    order: index
                });
            });

            $.ajax({
                url: seeker_path_js.ajax_url,
                type: 'POST',
                data: {
                    action: 'update_stage_order',
                    security: seeker_path_js.nonce,
                    new_order: stageOrder
                },
                success: function(response) {
                    if(response.success) {
                        console.log('Order updated successfully!');
                    } else {
                        console.error('Failed to update order.');
                    }
                }
            });
        }
    });
});