document.addEventListener("DOMContentLoaded", function() {
    const el = document.getElementById('stage-list');

    if (el) {
        Sortable.create(el, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'blue-background-class',
            onEnd: function (evt) {
                const items = el.querySelectorAll('li');
                const stageOrder = [];

                items.forEach((item, index) => {
                    stageOrder.push({
                        id: item.getAttribute('data-id'),
                        order: index+1
                    });
                });

                const formData = new FormData();
                formData.append('action', 'update_stage_order');
                formData.append('security', journey_details_js.nonce);
                formData.append('journey_id', journey_details_js.journeyId);
                formData.append('new_order', JSON.stringify(stageOrder));

                fetch(journey_details_js.ajax_url, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Order updated successfully!');
                    } else {
                        console.error('Failed to update order.', data);
                    }
                })
                .catch(error => {
                    console.error('Fetch error:', error);
                });
            }
        });
    }
});