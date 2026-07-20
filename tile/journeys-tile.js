(function () {
    'use strict';

    var cfg = window.dtJourneys || {};
    var i18n = cfg.i18n || {};

    var container = document.getElementById('dt-journeys-container');
    var addBtn = document.getElementById('dt-journeys-add-btn');
    if (!container || !cfg.rest_url || !cfg.post_id) {
        return;
    }

    var restBase = cfg.rest_url + 'dt-journeys/v1/';
    var recordPath = cfg.post_type + '/' + cfg.post_id;

    function t(key, fallback) {
        return i18n[key] || fallback;
    }

    function esc(str) {
        var div = document.createElement('div');
        div.textContent = str == null ? '' : String(str);
        return div.innerHTML;
    }

    function apiFetch(path, options) {
        options = options || {};
        options.headers = Object.assign({ 'X-WP-Nonce': cfg.nonce }, options.headers || {});
        if (options.body) {
            options.headers['Content-Type'] = 'application/json';
        }
        return fetch(restBase + path, options).then(function (r) {
            return r.json().then(function (data) {
                if (!r.ok) {
                    throw new Error((data && data.message) || t('error', 'Could not load journeys.'));
                }
                return data;
            });
        });
    }

    function setMsg(msg) {
        container.innerHTML = '';
        var p = document.createElement('p');
        p.className = 'msg';
        p.textContent = msg;
        container.appendChild(p);
    }

    // ── Rendering ──────────────────────────────────────────────────────────

    function render(data) {
        container.innerHTML = '';

        if (data.group_journeys && data.group_journeys.length) {
            container.appendChild(renderGroupRollup(data.group_journeys));
        }

        if (!data.journeys || !data.journeys.length) {
            var p = document.createElement('p');
            p.className = 'msg';
            p.textContent = t('no_journeys', 'No journeys started yet.');
            container.appendChild(p);
        } else {
            data.journeys.forEach(function (journey) {
                container.appendChild(renderJourney(journey, false));
            });
        }
    }

    function renderGroupRollup(groupJourneys) {
        var wrap = document.createElement('div');
        wrap.className = 'group-rollup';

        var h = document.createElement('h4');
        h.textContent = t('group_journeys', 'Journeys Active in Groups');
        wrap.appendChild(h);

        groupJourneys.forEach(function (g) {
            var gWrap = document.createElement('div');
            gWrap.className = 'group';

            var gTitle = document.createElement('div');
            gTitle.className = 'group-name';
            gTitle.textContent = g.group_name;
            gWrap.appendChild(gTitle);

            g.journeys.forEach(function (journey) {
                gWrap.appendChild(renderJourney(journey, true));
            });
            wrap.appendChild(gWrap);
        });

        return wrap;
    }

    function statusLabel(status) {
        var map = {
            not_started: t('not_started', 'Not Started'),
            started: t('started', 'Started'),
            paused: t('paused', 'Paused'),
            incomplete: t('stalled', 'Stalled'),
            complete: t('complete', 'Complete'),
            skipped: t('skip', 'Skipped'),
        };
        return map[status] || status;
    }

    function renderJourney(journey, readonly) {
        var displayMode = journey.is_sequential ? 'timeline' : journey.display_type || 'list';

        var wrap = document.createElement('div');
        wrap.className = 'journey journey--' + displayMode + (readonly ? ' journey--readonly' : '');

        var header = document.createElement('div');
        header.className = 'header';

        var title = document.createElement('strong');
        title.className = 'name';
        title.textContent = journey.name;
        header.appendChild(title);

        var statusEl = document.createElement('span');
        statusEl.className = 'status' + (journey.status === 'completed' ? ' status--completed' : '');
        statusEl.textContent = journey.status === 'completed'
            ? t('completed_on', 'Completed') + ' ' + (journey.completed_date || '')
            : t('started_on', 'Started') + ' ' + (journey.started || '');
        header.appendChild(statusEl);
        wrap.appendChild(header);

        var stagesWrap = document.createElement('div');
        stagesWrap.className = 'stages';
        (journey.stages || []).forEach(function (stage) {
            stagesWrap.appendChild(renderStage(journey, stage, readonly));
        });
        wrap.appendChild(stagesWrap);

        if (!readonly && journey.status !== 'completed') {
            var completeBtn = document.createElement('button');
            completeBtn.type = 'button';
            completeBtn.className = 'button small complete-btn';
            completeBtn.textContent = t('mark_complete', 'Mark Journey Complete');
            completeBtn.addEventListener('click', function () {
                onMarkJourneyComplete(journey);
            });
            wrap.appendChild(completeBtn);
        }

        return wrap;
    }

    function renderStage(journey, stage, readonly) {
        var row = document.createElement('div');
        row.className = 'stage stage--' + stage.status;

        var main = document.createElement('div');
        main.className = 'main';

        var name = document.createElement('span');
        name.className = 'name';
        name.textContent = stage.name;
        name.addEventListener('click', function () {
            openStageModal(journey, stage);
        });
        main.appendChild(name);

        if (stage.description) {
            var desc = document.createElement('span');
            desc.className = 'desc';
            desc.textContent = stage.description;
            main.appendChild(desc);
        }
        row.appendChild(main);

        var statusEl = document.createElement('span');
        statusEl.className = 'status';
        statusEl.textContent = statusLabel(stage.status) + (stage.date ? ' – ' + stage.date : '');
        row.appendChild(statusEl);

        if (!readonly) {
            var actions = document.createElement('span');
            actions.className = 'actions';

            var completeLabel = stage.success_action_label || t('complete', 'Complete');
            actions.appendChild(makeStageBtn(completeLabel, function () {
                setStageStatus(journey.ID, stage.ID, 'complete');
            }));
            actions.appendChild(makeStageBtn(t('skip', 'Skip'), function () {
                setStageStatus(journey.ID, stage.ID, 'skipped');
            }));
            actions.appendChild(makeStageBtn(t('stalled', 'Stalled'), function () {
                setStageStatus(journey.ID, stage.ID, 'incomplete');
            }));

            row.appendChild(actions);
        }

        return row;
    }

    function makeStageBtn(label, onClick) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button tiny';
        btn.textContent = label;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            onClick();
        });
        return btn;
    }

    // ── Data actions ───────────────────────────────────────────────────────

    function loadRecord() {
        setMsg(t('loading', 'Loading journeys…'));
        return apiFetch('record/' + recordPath)
            .then(function (data) {
                render(data);
                return data;
            })
            .catch(function () {
                setMsg(t('error', 'Could not load journeys.'));
            });
    }

    function setStageStatus(journeyId, stageId, status, note) {
        var body = { journey_id: journeyId, stage_id: stageId, status: status };
        if (note) {
            body.note = note;
        }
        return apiFetch('stage-status/' + recordPath, { method: 'POST', body: JSON.stringify(body) })
            .then(function () {
                return loadRecord();
            })
            .then(function (data) {
                maybePromptNextJourney(data, journeyId);
                return data;
            })
            .catch(function (err) {
                window.alert(err.message);
            });
    }

    function onMarkJourneyComplete(journey) {
        if (!window.confirm(t('confirm_complete', 'Mark this journey complete? Any remaining steps will be skipped.'))) {
            return;
        }
        completeJourney(journey.ID, true).then(function (data) {
            maybePromptNextJourney(data, journey.ID, journey.next_journey);
        });
    }

    function completeJourney(journeyId, force) {
        return apiFetch('complete/' + recordPath, {
            method: 'POST',
            body: JSON.stringify({ journey_id: journeyId, force: !!force }),
        })
            .then(function () {
                return loadRecord();
            })
            .catch(function (err) {
                window.alert(err.message);
                return null;
            });
    }

    function maybePromptNextJourney(data, completedJourneyId, nextJourneyIdHint) {
        if (!data || !data.journeys) {
            return;
        }
        var completed = data.journeys.filter(function (j) {
            return j.ID === completedJourneyId;
        })[0];
        if (!completed || completed.status !== 'completed') {
            return;
        }

        var nextId = nextJourneyIdHint || completed.next_journey;
        if (!nextId) {
            return;
        }

        apiFetch('available/' + recordPath).then(function (avail) {
            var next = (avail.journeys || []).filter(function (j) {
                return j.ID === nextId;
            })[0];
            if (!next) {
                return;
            }
            var msg = t('start_next', 'Start the next journey: %s?').replace('%s', next.name);
            if (window.confirm(msg)) {
                startJourney(nextId);
            }
        });
    }

    function startJourney(journeyId) {
        return apiFetch('start/' + recordPath, {
            method: 'POST',
            body: JSON.stringify({ journey_id: journeyId }),
        })
            .then(function () {
                return loadRecord();
            })
            .catch(function (err) {
                window.alert(err.message);
            });
    }

    // ── "Add a Journey" modal ──────────────────────────────────────────────
    //
    // Both modals use the theme's real <dt-modal> web component (already
    // loaded globally) rather than a hand-rolled overlay, so backdrop,
    // centering, header/title, and the close (×) button, Escape-to-close,
    // and click-outside-to-close all come from the same component every
    // other modal on the site uses.

    var addModal;
    var addModalList;

    function ensureAddModal() {
        if (addModal) {
            return addModal.updateComplete;
        }
        addModal = document.createElement('dt-modal');
        addModal.hideButton = true;
        addModal.title = t('add_journey', 'Add a Journey');

        var content = document.createElement('div');
        content.className = 'dt-journeys-modal-content';
        content.setAttribute('slot', 'content');
        addModalList = document.createElement('ul');
        addModalList.className = 'available-list';
        content.appendChild(addModalList);
        addModal.appendChild(content);

        document.body.appendChild(addModal);
        // Lit renders asynchronously -- the shadow DOM's <dialog> the 'open'
        // event opens doesn't exist until the element's first update completes.
        return addModal.updateComplete;
    }

    function openAddJourneyModal() {
        ensureAddModal().then(function () {
            addModalList.innerHTML = '<li>' + esc(t('loading', 'Loading…')) + '</li>';
            addModal.dispatchEvent(new CustomEvent('open'));

            apiFetch('available/' + recordPath).then(function (data) {
                addModalList.innerHTML = '';
                if (!data.journeys || !data.journeys.length) {
                    addModalList.innerHTML = '<li>' + esc(t('no_available', 'No journeys available to add.')) + '</li>';
                    return;
                }
                data.journeys.forEach(function (journey) {
                    var li = document.createElement('li');

                    var label = document.createElement('span');
                    label.textContent = journey.name;
                    li.appendChild(label);

                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'button tiny';
                    btn.textContent = t('start', 'Start');
                    btn.addEventListener('click', function () {
                        startJourney(journey.ID).then(closeAddModal);
                    });
                    li.appendChild(btn);

                    addModalList.appendChild(li);
                });
            });
        });
    }

    function closeAddModal() {
        if (addModal) {
            addModal.dispatchEvent(new CustomEvent('close'));
        }
    }

    if (addBtn) {
        addBtn.addEventListener('click', openAddJourneyModal);
    }

    // ── Stage pop-out modal ────────────────────────────────────────────────

    var stageModal;
    var stageModalBody;
    var stageModalSaveBtn;
    var currentStageSave;

    function ensureStageModal() {
        if (stageModal) {
            return stageModal.updateComplete;
        }
        stageModal = document.createElement('dt-modal');
        stageModal.hideButton = true;
        stageModal.submitButton = true;

        var content = document.createElement('div');
        content.className = 'dt-journeys-modal-content';
        content.setAttribute('slot', 'content');
        stageModalBody = document.createElement('div');
        stageModalBody.className = 'body';
        content.appendChild(stageModalBody);
        stageModal.appendChild(content);

        stageModalSaveBtn = document.createElement('button');
        stageModalSaveBtn.type = 'button';
        stageModalSaveBtn.className = 'button small';
        stageModalSaveBtn.setAttribute('slot', 'submit-button');
        stageModalSaveBtn.textContent = t('save_note', 'Save');
        stageModalSaveBtn.addEventListener('click', function () {
            if (currentStageSave) {
                currentStageSave().then(closeStageModal);
            }
        });
        stageModal.appendChild(stageModalSaveBtn);

        document.body.appendChild(stageModal);
        // Lit renders asynchronously -- the shadow DOM's <dialog> the 'open'
        // event opens doesn't exist until the element's first update completes.
        return stageModal.updateComplete;
    }

    function closeStageModal() {
        if (stageModal) {
            stageModal.dispatchEvent(new CustomEvent('close'));
        }
    }

    function renderStageAttachments(stage) {
        var items = (stage.links || []).concat(stage.attachments || []).filter(function (link) {
            return link && link.value;
        });
        if (!items.length) {
            return null;
        }

        var wrap = document.createElement('div');
        wrap.className = 'attachments';
        wrap.innerHTML = '<h4>' + esc(t('attachments', 'Attachments')) + '</h4>';

        var ul = document.createElement('ul');
        items.forEach(function (link) {
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.href = link.value;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = link.type || link.value;
            li.appendChild(a);
            ul.appendChild(li);
        });
        wrap.appendChild(ul);
        return wrap;
    }

    function renderRelatedFields(journey, stage, body) {
        var relatedFields = stage.related_fields || [];
        if (!relatedFields.length) {
            return;
        }

        var wrap = document.createElement('div');
        wrap.className = 'related-fields';
        wrap.innerHTML = '<h4>' + esc(t('related_fields', 'Related Fields')) + '</h4>' +
            '<div class="fields-body">' + esc(t('loading', 'Loading…')) + '</div>';
        body.appendChild(wrap);

        var fieldsBody = wrap.querySelector('.fields-body');
        apiFetch('stage-fields/' + recordPath + '?' + relatedFields.map(function (k) {
            return 'field_keys[]=' + encodeURIComponent(k);
        }).join('&')).then(function (data) {
            fieldsBody.innerHTML = '';
            Object.keys(data.fields || {}).forEach(function (key) {
                var el = document.createElement('div');
                el.className = 'field';
                el.innerHTML = data.fields[key];
                fieldsBody.appendChild(el);
            });
            if (window.componentService && typeof window.componentService.enableAutoSave === 'function') {
                window.componentService.enableAutoSave();
            }
        });
    }

    function openStageModal(journey, stage) {
        ensureStageModal().then(function () {
            stageModal.title = stage.name;

            var body = stageModalBody;
            body.innerHTML = '';

            if (stage.instructions) {
                var instr = document.createElement('div');
                instr.className = 'instructions';
                instr.innerHTML = '<h4>' + esc(t('instructions', 'Instructions')) + '</h4><div>' +
                    esc(stage.instructions).replace(/\n/g, '<br>') + '</div>';
                body.appendChild(instr);
            }

            var attachments = renderStageAttachments(stage);
            if (attachments) {
                body.appendChild(attachments);
            }

            renderRelatedFields(journey, stage, body);

            var noteWrap = document.createElement('div');
            noteWrap.className = 'note';
            var noteLabel = document.createElement('label');
            noteLabel.textContent = t('note_placeholder', 'Add a note (optional)…');
            var noteInput = document.createElement('textarea');
            noteInput.className = 'note-input';
            noteInput.value = stage.note || '';
            noteWrap.appendChild(noteLabel);
            noteWrap.appendChild(noteInput);
            body.appendChild(noteWrap);

            currentStageSave = function () {
                return setStageStatus(journey.ID, stage.ID, stage.status, noteInput.value);
            };

            stageModal.dispatchEvent(new CustomEvent('open'));
        });
    }

    // ── Init ───────────────────────────────────────────────────────────────

    loadRecord();
})();
