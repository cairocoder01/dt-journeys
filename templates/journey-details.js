const journeyId = Number(journey_details_js.journeyId) || 0;

document.addEventListener('DOMContentLoaded', function() {
    const el = document.getElementById('stage-list');

    function renderStageLoadingIcon(stageId) {
        const nameContainer = document.getElementById(`stage-list-header`);
        if (!nameContainer) return;

        const existingIcon = nameContainer.querySelector('.icon-overlay');
        if (existingIcon) {
            existingIcon.remove();
        }

        const icon = document.createElement('dt-spinner');
        icon.className = 'icon-overlay';

        nameContainer.appendChild(icon);
    }

    function renderStageSavedIcon(stageId) {
        const nameContainer = document.getElementById(`stage-list-header`);
        if (!nameContainer) return;

        const existingIcon = nameContainer.querySelector('.icon-overlay');
        if (existingIcon) {
            existingIcon.remove();
        }

        const icon = document.createElement('dt-checkmark');
        icon.className = 'icon-overlay success fade-out';

        nameContainer.appendChild(icon);
    }

    if (el) {
        Sortable.create(el, {
            animation: 150,
            handle: '.drag-handle',
            ghostClass: 'blue-background-class',
            onStart: function (evt) {
                const existingIcon = evt.item.querySelector('.icon-overlay');
                if (existingIcon) {
                    existingIcon.remove();
                }
            },
            onEnd: function (evt) {
                const draggedStageId = evt.item.getAttribute('data-id');

                renderStageLoadingIcon(draggedStageId);

                const items = el.querySelectorAll('li');
                const stageOrder = [];

                items.forEach((item, index) => {
                    stageOrder.push({
                        id: item.getAttribute('data-id'),
                        order: index+1
                    });
                });

                if (journey_details_js.journeyId) {
                    const payload = {
                        journey_id: journey_details_js.journeyId,
                        new_order: stageOrder
                    };

                    fetch(journey_details_js.rest_endpoint + `journeys/${journey_details_js.journeyId}/reorder-stages`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': window.wpApiShare.nonce
                        },
                        body: JSON.stringify(payload)
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            console.log('Order updated successfully!');
                            renderStageSavedIcon(draggedStageId);

                        } else {
                            console.log(data);
                            console.error('Failed to update order.', data);
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                    });
                } else {
                    if (window.stages && Array.isArray(window.stages)) {
                        items.forEach((item, index) => {
                            const stageId = item.getAttribute('data-id');
                            const stage = window.stages.find(s => (s.ID == stageId || s.temp_id == stageId));
                            
                            if (stage) {
                                stage.stage_order = index + 1;
                            }
                        });

                        window.stages.sort((a, b) => (a.stage_order || 0) - (b.stage_order || 0));
                    }

                    renderStageSavedIcon(draggedStageId);
                }
            }
        });
    }

    updateSaveButtonUI();
    toggleEmptyStageText();

    const currentPostType = 'journeys'; // Hardcoded since this is the Journeys admin page
    
    const apiNonce = window.wpApiSettings ? window.wpApiSettings.nonce : (window.wpApiShare ? window.wpApiShare.nonce : '');
    const apiRoot = window.wpApiSettings ? window.wpApiSettings.root : (window.wpApiShare ? window.wpApiShare.root : '/wp-json/');

    if (window.DtWebComponents && window.DtWebComponents.ComponentService) {
        const service = new window.DtWebComponents.ComponentService(
            currentPostType,
            journeyId,
            apiNonce,
            apiRoot
        );
        
        service.initialize();
        
        window.componentService = service;
    }

    const stageForm = document.getElementById('stage-form');
    if (stageForm) {
        stageForm.addEventListener('change', function(e) {
            if (window.componentService && window.componentService.postId === 0) {
                e.stopImmediatePropagation();
            }
        }, true);
    }

});

const SAVE_MODES = dtJourneyData.i18n;
const journeysBaseUrl = dtJourneyData.journeysBaseUrl;
const stageFields = dtJourneyData.stageFields;
const journeyFields = dtJourneyData.journeyFields;

let currentSaveMode = localStorage.getItem('dt_journey_save_action') || 'go_back';
let stageTempId = 1;
let currentStageId = null;

// Initialize stages from the localized data
window.stages = dtJourneyData.stages || [];
let stages = window.stages;

function updateSaveButtonUI() {
    let labelEl = document.getElementById('top-btn-label');
    if (labelEl && SAVE_MODES[currentSaveMode]) {
        labelEl.innerHTML = SAVE_MODES[currentSaveMode];
    }
    labelEl = document.getElementById('bottom-btn-label');
    if (labelEl && SAVE_MODES[currentSaveMode]) {
        labelEl.innerHTML = SAVE_MODES[currentSaveMode];
    }
}

function toggle_save_dropdown(event) {
    const id = event.srcElement.id;
    event.stopPropagation();
    let menu = document.getElementById('save-dropdown-top');
    if (menu && id === "top-icon") {
        menu.classList.toggle('show');
    }
    menu = document.getElementById('save-dropdown-bottom');
    if (menu && id === "bottom-icon") {
        menu.classList.toggle('show');
    }
}

function select_save_mode(mode) {
    if (SAVE_MODES[mode]) {
        currentSaveMode = mode;
        localStorage.setItem('dt_journey_save_action', mode);
        updateSaveButtonUI();
    }
    let menu = document.getElementById('save-dropdown-top');
    if (menu) {
        menu.classList.remove('show');
    }
    menu = document.getElementById('save-dropdown-bottom');
    if (menu) {
        menu.classList.remove('show');
    }
}

// Close split dropdown on outside clicks
document.addEventListener('click', function(e) {
    const wrapper = document.getElementById('save-split-button');
    let menu = document.getElementById('save-dropdown-top');
    if (menu && wrapper && !wrapper.contains(e.target)) {
        menu.classList.remove('show');
    }
    menu = document.getElementById('save-dropdown-bottom');
    if (menu && wrapper && !wrapper.contains(e.target)) {
        menu.classList.remove('show');
    }
});

function go_back() {
    window.location.href = journeysBaseUrl;
}

async function save_stage(event) {
    hideError();

    event.preventDefault();

    const activeForm = event.target.closest('#stage-form');

    if (!activeForm) {
        console.error("Could not find the active stage form.");
        return;
    }

    const isUpdating = journeyId == 0 && currentStageId !== null && currentStageId !== 0;

    const payload = {};

    if (isUpdating) {
        payload.ID = currentStageId;
    } else {
        let nextStageOrder = 0;
        if (stages && stages.length > 0) {
            const currentOrders = stages.map(stage => parseInt(stage.stage_order || 0));
            nextStageOrder = Math.max(...currentOrders) + 1;
        }
        payload['stage_order'] = nextStageOrder;
    }

    for ( const [fieldKey, fieldType] of Object.entries(stageFields) ) {
        const el = activeForm.querySelector(`[id="stage_${fieldKey}"]`);
        if (fieldKey === 'journey') {
            payload[fieldKey] = [
                {
                    "id": journeyId
                }
            ];
        }
        if ( ! el ) continue;
        if (fieldKey === 'attachments' && !el.value) {
            continue
        }
        payload[fieldKey] = el.value;
    }

    if (journeyId > 0) {
        try {
            let response = await fetch(window.journey_details_js.rest_endpoint + `journeys/stage`, {
                method: 'POST',
                headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.wpApiShare.nonce,
                },
                body: JSON.stringify(payload)
            });

            if (response.ok) {
                let result = await response.json();

                const newId = result.id || result.ID;
                if (newId) {
                    render_new_stage(newId, payload);
                }
                close_edit();
            } else {
                let result = await response.json();

                showError(result.message || 'Failed to save stage', 'stage-detail-error');
            }
        } catch (error) {
            console.error("Create/Update Stage Error:", error);
            showError('An error occurred while saving the stage. Please try again.', 'stage-detail-error');
        }
    } else if (!isUpdating) {
        payload.temp_id = stageTempId;
        stageTempId += 1;
        render_new_stage(payload.temp_id, payload);
        close_edit();
    } else {
        close_edit(isUpdating);
    }
}

function edit_stage(stage_id) {
    if (journeyId > 0 && currentStageId !== null && currentStageId !== stage_id) {
        sync_stage_list();
    }

    const titleEl = document.getElementById('edit-section-title');
    const activeForm = document.getElementById('stage-form');
    currentStageId = stage_id || null;

    document.querySelectorAll('.stage-edit-form').forEach(function(form) {
        form.style.display = 'none';
    });

    if (activeForm) {
        activeForm.querySelectorAll('[post-id]').forEach(el => {
            el.setAttribute('post-id', currentStageId);
        });

        for (const formField of activeForm) {
            if ( formField.tagName.startsWith('DT-') ) {
                formField.reset();
            }
        }
    }

    if (stage_id) {
        if (journeyId == 0) {
            document.querySelector('#stage-save-container').style.display = 'flex';
        } else {
            document.querySelector('#stage-save-container').style.display = 'none';
        }
        if (titleEl) titleEl.innerText = 'Edit Stage';
        const stageData = stages.find(s => s.ID == stage_id);

        if (activeForm) {
            for (const formField of activeForm) {
                if (formField.name) {
                    formField.value = stageData[formField.name] !== undefined ? stageData[formField.name] : '';
                }
            }
            activeForm.style.display = 'grid';
        }

        if (window.componentService) {
            window.componentService.postType = 'journey_stages';
            window.componentService.postId = journeyId > 0 ? stage_id : 0;
        }

    } else {
        document.querySelector('#stage-save-container').style.display = 'flex';
        if (titleEl) titleEl.innerText = 'Add Stage';

        if (activeForm) activeForm.style.display = 'grid';

        if (window.componentService) {
            window.componentService.postType = 'journey_stages';
            window.componentService.postId = 0;
        }
    }

    document.getElementById('slider-track').classList.add('shift-left');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function close_edit(isUpdating = false) {
    if (isUpdating) {
        sync_stage_list();
    }

    currentStageId = null;
    document.getElementById('slider-track').classList.remove('shift-left');

    if (window.componentService) {
        window.componentService.postType = 'journeys';
        window.componentService.postId = journeyId;
    }

    window.scrollTo({ top: 0, behavior: 'smooth' });
}

async function delete_stage(stage_id) {
    hideError();
    if (currentStageId === stage_id) {
        close_edit();
    }

    const stageRow = document.getElementById(`stage-${stage_id}`);

    if (journeyId === 0) {
        const index = stages.findIndex(stage => stage.ID === stage_id || stage.temp_id === stage_id);

        if (index !== -1) {
            stages.splice(index, 1);
        }

        if (stageRow) {
            stageRow.remove();
        }

    } else {
        try {
            let response = await fetch(window.journey_details_js.rest_endpoint + `journeys/stage/${stage_id}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': window.wpApiShare.nonce,
                },
            });

            if (response.ok) {
                let result = await response.json();

                if (stageRow) {
                    stageRow.remove();
                }

                const index = stages.findIndex(stage => stage.ID == stage_id);
                if (index !== -1) {
                    stages.splice(index, 1);
                }
            } else {
                let result = await response.json();

                showError(result.message || 'Failed to delete stage', 'stage-list-error');
            }
        } catch (error) {
            console.error("Delete Stage Error:", error);
            showError('An error occurred while deleting the stage. Please try again.', 'stage-list-error');
        }
    }

    toggleEmptyStageText();
}

async function delete_journey(journey_id) {

    try {
        let response = await fetch(window.journey_details_js.rest_endpoint + `journeys/${journey_id}`, {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.wpApiShare.nonce,
            },
        });

        if (response.ok) {
            window.location.href = journeysBaseUrl;
        } else {
            let result = await response.json();
            showError(result.message || 'Failed to delete journey', 'journey-detail-error');
        }
    } catch (error) {
        console.error("Delete Journey Error:", error);
        showError('An error occurred while deleting the journey. Please try again.', 'journey-detail-error');
    }
}

async function save_journey(event) {
    hideError();

    event.preventDefault();

    const payload = {};

    for ( const [fieldKey, fieldType] of Object.entries(journeyFields) ) {
        const el = document.getElementById(`journey_${fieldKey}`);
        if ( ! el ) continue;
        
        payload[fieldKey] = el.value;
    }

    payload['stages'] = window.stages;

    try {
        let response = await fetch(window.journey_details_js.rest_endpoint + `journeys`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': window.wpApiShare.nonce,
            },
            body: JSON.stringify(payload)
        });

        if (response.ok) {

            let result = await response.json();

            const newId = result.ID || '';

            if (currentSaveMode === 'continue' && newId) {
                window.location.href = `${journeysBaseUrl}${newId}/`;
            } else if (currentSaveMode === 'add_new') {
                window.location.href = `${journeysBaseUrl}new/`;
            } else {
                // Default: 'go_back'
                window.location.href = journeysBaseUrl;
            }
        } else {
            let result = await response.json();

            showError(result.message || 'Failed to save journey', 'journey-detail-error');
        }
    } catch (error) {
        console.error("Create Journey Error:", error);
        showError('An error occurred while saving the journey. Please try again.', 'journey-detail-error');
    }
}

function toggleEmptyStageText() {
    const emptyMsg = document.getElementById('no-stages-text');
    const listItems = document.querySelectorAll('#stage-list li');

    if (emptyMsg) {
        if (listItems.length > 0) {
            emptyMsg.style.display = 'none';
        } else {
            emptyMsg.style.display = 'block';
        }
    }
}

function sync_stage_list() {
    if (currentStageId !== null && currentStageId !== 0) {
        const activeForm = document.getElementById('stage-form');

        const newName = activeForm.querySelector('[id="stage_name"]')?.value || '';
        const newDesc = activeForm.querySelector('[id="stage_description"]')?.value || '';

        const links = activeForm.querySelector('[id="stage_links"]')?.value || [];
        const validLinks = Array.isArray(links) ? links.filter(item => item.value && item.value.trim() !== '') : [];
        const attachments = activeForm.querySelector('[id="stage_attachments"]')?.value || [];
        const related_fields = activeForm.querySelector('[id="stage_related_fields"]')?.value || [];

        const linksCount = Array.isArray(validLinks) ? validLinks.length : 0;
        const attachmentsCount = Array.isArray(attachments) ? attachments.length : 0;
        const relatedCount = Array.isArray(related_fields) ? related_fields.length : 0;

        const nameEl = document.getElementById(`stage-name-${currentStageId}`);
        if (nameEl) nameEl.textContent = newName;

        const descEl = document.getElementById(`stage-description-${currentStageId}`);
        if (descEl) descEl.textContent = newDesc;

        const stageFieldsEl = document.getElementById(`stage-fields-${currentStageId}`);
        if (stageFieldsEl) stageFieldsEl.textContent = `Links: ${linksCount} Attachments: ${attachmentsCount} Related Fields: ${relatedCount}`;

        stages = stages.map(stage => {
            if (stage.ID == currentStageId) {
                const newStage = { ...stage };

                for (const [key, item] of Object.entries(stage)) {
                    const el = activeForm.querySelector(`[id="stage_${key}"]`);

                    if (el && el.value !== undefined) {
                        newStage[key] = el.value;
                    }
                }

                return newStage;
            }
            return stage;
        });

        window.stages = stages;
    }
}

function render_new_stage(stageId, payload) {
    const template = document.getElementById('stage-row-template');
    const clone = template.content.cloneNode(true); // true means clone all children

    const li = clone.querySelector('li');
    li.id = `stage-${stageId}`;
    li.setAttribute('data-id', stageId);

    const nameEl = clone.querySelector('.stage-name');
    nameEl.id = `stage-name-${stageId}`;
    nameEl.textContent = payload.name || 'New Stage';

    const descEl = clone.querySelector('.stage-description');
    descEl.id = `stage-description-${stageId}`;
    descEl.textContent = payload.description || '';

    const links = payload.links?.value ?? payload.links ?? [];
    const validLinks = Array.isArray(links) ? links.filter(item => item.value && item.value.trim() !== '') : [];
    const linksCount = Array.isArray(validLinks) ? validLinks.length : 0;
    const attachmentsCount = Array.isArray(payload.attachments) ? payload.attachments.length : 0;
    const relatedCount = Array.isArray(payload.related_fields) ? payload.related_fields.length : 0;

    const fieldsEl = clone.querySelector('.stage-fields');
    fieldsEl.id = `stage-fields-${stageId}`;
    fieldsEl.textContent = `Links: ${linksCount} Attachments: ${attachmentsCount} Related Fields: ${relatedCount}`;

    clone.querySelector('.edit-btn').setAttribute('onclick', `edit_stage(${stageId})`);
    clone.querySelector('.delete-btn').setAttribute('onclick', `delete_stage(${stageId})`);

    document.getElementById('stage-list').appendChild(clone);

    toggleEmptyStageText();

    payload.ID = stageId;
    stages.push(payload);
}

function showError(message, element) {
    const errorMessage = document.getElementById(element);
    if (errorMessage) {
        errorMessage.innerText = 'Error: ' + message;
        errorMessage.style.display = 'block';
    }
}

function hideError() {
    const journeyDetailError = document.getElementById('journey-detail-error');
    if (journeyDetailError) journeyDetailError.style.display = 'none';
    const listError = document.getElementById('stage-list-error');
    if (listError) listError.style.display = 'none';
    const stageDetailError = document.getElementById('stage-detail-error');
    if (stageDetailError) stageDetailError.style.display = 'none';
}
