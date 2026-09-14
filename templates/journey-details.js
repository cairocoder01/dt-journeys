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

                // Inside the Sortable onEnd callback in journey-details.js
                const payload = {
                    journey_id: journey_details_js.journeyId,
                    new_order: stageOrder
                };

                fetch(journey_details_js.rest_endpoint + 'journeys/reorder-stages', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': window.wpApiShare.nonce // Matches the auth pattern used elsewhere in the template
                    },
                    body: JSON.stringify(payload)
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Order updated successfully!');
                    } else {
                        console.log(data);
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