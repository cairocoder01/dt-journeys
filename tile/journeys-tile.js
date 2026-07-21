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

    // Journeys are collapsed by default (only the summary line shows) since a
    // record can accumulate several of them and each one's full stage list is
    // tall. Expansion state is kept here, keyed by journey ID, so it survives
    // the re-renders that follow every data reload/toggle.
    var expandedJourneys = {};
    var lastData = null;

    function toggleJourney(journeyId) {
        expandedJourneys[journeyId] = !expandedJourneys[journeyId];
        if (lastData) {
            render(lastData);
        }
    }

    function render(data) {
        lastData = data;
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

    var STAGE_STATUS_KEYS = ['not_started', 'started', 'paused', 'incomplete', 'complete', 'skipped'];

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
        var expanded = !!expandedJourneys[journey.ID];

        var wrap = document.createElement('div');
        wrap.className = 'journey journey--' + displayMode + (readonly ? ' journey--readonly' : '');

        var header = document.createElement('div');
        header.className = 'header';
        header.addEventListener('click', function () {
            toggleJourney(journey.ID);
        });

        var titleGroup = document.createElement('div');
        titleGroup.className = 'title-group';

        var toggle = document.createElement('span');
        toggle.className = 'toggle';
        toggle.setAttribute('aria-hidden', 'true');
        toggle.textContent = expanded ? '▾' : '▸';
        titleGroup.appendChild(toggle);

        var title = document.createElement('strong');
        title.className = 'name';
        title.textContent = journey.name;
        titleGroup.appendChild(title);
        header.appendChild(titleGroup);

        var statusEl = document.createElement('span');
        statusEl.className = 'status' + (journey.status === 'completed' ? ' status--completed' : '');
        statusEl.textContent = journey.status === 'completed'
            ? t('completed_on', 'Completed') + ' ' + (journey.completed_date || '')
            : t('started_on', 'Started') + ' ' + (journey.started || '');
        header.appendChild(statusEl);
        wrap.appendChild(header);

        if (!expanded) {
            var progressBar = renderJourneyProgressBar(journey);
            if (progressBar) {
                wrap.appendChild(progressBar);
            }
            return wrap;
        }

        var stagesWrap = document.createElement('div');
        stagesWrap.className = 'stages';
        (journey.stages || []).forEach(function (stage) {
            stagesWrap.appendChild(renderStage(journey, stage, readonly));
        });
        wrap.appendChild(stagesWrap);

        if (!readonly) {
            var footerActions = document.createElement('div');
            footerActions.className = 'journey-actions';

            if (journey.status !== 'completed') {
                var completeBtn = document.createElement('button');
                completeBtn.type = 'button';
                completeBtn.className = 'button small complete-btn';
                completeBtn.textContent = t('mark_complete', 'Mark Journey Complete');
                completeBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    onMarkJourneyComplete(journey);
                });
                footerActions.appendChild(completeBtn);
            }

            // Removes the whole instance (not just marks it done) -- for
            // undoing a mistaken/test "Start", regardless of its status.
            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'button small hollow alert remove-btn';
            removeBtn.textContent = t('remove_journey', 'Remove Journey');
            removeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                onRemoveJourney(journey);
            });
            footerActions.appendChild(removeBtn);

            wrap.appendChild(footerActions);
        }

        return wrap;
    }

    // Collapsed-state summary: a single percent-complete bar works for both
    // sequential and non-sequential journeys alike (no need for a "next
    // stage" vs. "X of Y" split). A forced/cascaded completion can leave
    // sequential stages "skipped" rather than "complete", so a finished
    // journey is always shown full regardless of how it got there.
    function renderJourneyProgressBar(journey) {
        var stages = journey.stages || [];
        if (!stages.length) {
            return null;
        }

        var percent;
        if (journey.status === 'completed') {
            percent = 100;
        } else {
            var doneCount = stages.filter(function (stage) {
                return stage.status === 'complete';
            }).length;
            percent = Math.round((doneCount / stages.length) * 100);
        }

        var wrap = document.createElement('div');
        wrap.className = 'progress-bar' + (percent === 100 ? ' progress-bar--complete' : '');

        var track = document.createElement('div');
        track.className = 'progress-bar__track';
        var fill = document.createElement('div');
        fill.className = 'progress-bar__fill';
        fill.style.width = percent + '%';
        track.appendChild(fill);
        wrap.appendChild(track);

        var label = document.createElement('span');
        label.className = 'progress-bar__label';
        label.textContent = percent + '%';
        wrap.appendChild(label);

        return wrap;
    }

    function renderStage(journey, stage, readonly) {
        var row = document.createElement('div');
        row.className = 'stage stage--' + stage.status;

        var rail = document.createElement('div');
        rail.className = 'rail';
        var marker = document.createElement('span');
        marker.className = 'marker';
        rail.appendChild(marker);
        row.appendChild(rail);

        var content = document.createElement('div');
        content.className = 'content';

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
        content.appendChild(main);

        var statusEl = document.createElement('span');
        statusEl.className = 'status';
        statusEl.textContent = statusLabel(stage.status) + (stage.date ? ' – ' + stage.date : '');
        content.appendChild(statusEl);

        // Once a stage's status has been set, the action buttons give way to
        // just the status/date above -- only a not-yet-addressed stage shows
        // Complete/Skip/Stalled.
        if (!readonly && stage.status === 'not_started') {
            var actions = document.createElement('span');
            actions.className = 'actions';

            // Completing a stage opens the pop-out (preset to Complete) rather
            // than saving instantly, since that's typically when a note or a
            // related field also needs to be filled in. Skip/Stalled don't
            // carry that same expectation, so they stay a single click.
            var completeLabel = stage.success_action_label || t('complete', 'Complete');
            actions.appendChild(makeStageBtn(completeLabel, 'complete', function () {
                openStageModal(journey, stage, 'complete');
            }));
            actions.appendChild(makeStageBtn(t('skip', 'Skip'), 'skip', function () {
                setStageStatus(journey.ID, stage.ID, 'skipped');
            }));
            actions.appendChild(makeStageBtn(t('stalled', 'Stalled'), 'stalled', function () {
                setStageStatus(journey.ID, stage.ID, 'incomplete');
            }));

            content.appendChild(actions);
        }

        row.appendChild(content);

        return row;
    }

    function makeStageBtn(label, modifier, onClick) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button tiny stage-btn stage-btn--' + modifier;
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
        return apiFetch(recordPath)
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
        return apiFetch(recordPath + '/stage-status', { method: 'POST', body: JSON.stringify(body) })
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
        return apiFetch(recordPath + '/complete', {
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

    function onRemoveJourney(journey) {
        if (!window.confirm(t('confirm_remove', 'Remove this journey and its progress from this record? This cannot be undone.'))) {
            return;
        }
        removeJourney(journey.ID);
    }

    function removeJourney(journeyId) {
        return apiFetch(recordPath + '/remove', {
            method: 'DELETE',
            body: JSON.stringify({ journey_id: journeyId }),
        })
            .then(function () {
                return loadRecord();
            })
            .catch(function (err) {
                window.alert(err.message);
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

        apiFetch(recordPath + '/available').then(function (avail) {
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
        return apiFetch(recordPath + '/start', {
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
    // Both modals are the theme's real <dt-modal> web component, rendered
    // as static markup by the PHP tile (see custom-tile.php) rather than
    // built up here -- their shells never change, only the content each
    // wraps and (for the stage modal) the title. That also means they're
    // already in the DOM and fully rendered well before any open action can
    // happen, so there's no need to wait on Lit's async first render here.

    var addModal = document.getElementById('dt-journeys-add-modal');
    var addModalList = addModal ? addModal.querySelector('.available-list') : null;
    var addModalFilters = addModal ? addModal.querySelector('.category-filters') : null;
    var activeCategory = null;

    if (addModal) {
        addModal.title = t('add_journey', 'Add a Journey');
    }

    function categoryValue(cat) {
        return (cat && cat.value) || cat;
    }

    function renderCategoryFilters(journeys) {
        if (!addModalFilters) {
            return;
        }
        addModalFilters.innerHTML = '';

        var categories = [];
        journeys.forEach(function (journey) {
            (journey.category || []).forEach(function (cat) {
                var val = categoryValue(cat);
                if (val && categories.indexOf(val) === -1) {
                    categories.push(val);
                }
            });
        });
        if (!categories.length) {
            return;
        }
        categories.sort();

        function makeTag(value, label) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'tag-filter' + (activeCategory === value ? ' active' : '');
            btn.textContent = label;
            btn.addEventListener('click', function () {
                activeCategory = value;
                renderCategoryFilters(journeys);
                renderAvailableList(journeys);
            });
            return btn;
        }

        addModalFilters.appendChild(makeTag(null, t('all_categories', 'All')));
        categories.forEach(function (cat) {
            addModalFilters.appendChild(makeTag(cat, cat));
        });
    }

    function renderAvailableList(journeys) {
        var filtered = activeCategory
            ? journeys.filter(function (journey) {
                return (journey.category || []).some(function (cat) {
                    return categoryValue(cat) === activeCategory;
                });
            })
            : journeys;

        addModalList.innerHTML = '';
        if (!filtered.length) {
            addModalList.innerHTML = '<li>' + esc(t('no_available', 'No journeys available to add.')) + '</li>';
            return;
        }
        filtered.forEach(function (journey) {
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
    }

    function openAddJourneyModal() {
        activeCategory = null;
        if (addModalFilters) {
            addModalFilters.innerHTML = '';
        }
        addModalList.innerHTML = '<li>' + esc(t('loading', 'Loading…')) + '</li>';
        addModal.dispatchEvent(new CustomEvent('open'));

        apiFetch(recordPath + '/available').then(function (data) {
            var journeys = data.journeys || [];
            renderCategoryFilters(journeys);
            renderAvailableList(journeys);
        });
    }

    function closeAddModal() {
        if (addModal) {
            addModal.dispatchEvent(new CustomEvent('close'));
        }
    }

    if (addBtn && addModal) {
        addBtn.addEventListener('click', openAddJourneyModal);
    }

    // ── Stage pop-out modal ────────────────────────────────────────────────

    var stageModal = document.getElementById('dt-journeys-stage-modal');
    var stageModalBody = stageModal ? stageModal.querySelector('.body') : null;
    var stageModalSaveBtn = document.getElementById('dt-journeys-stage-save');
    var currentStageSave;

    if (stageModalSaveBtn) {
        stageModalSaveBtn.addEventListener('click', function () {
            if (currentStageSave) {
                currentStageSave().then(closeStageModal);
            }
        });
    }

    function closeStageModal() {
        if (stageModal) {
            stageModal.dispatchEvent(new CustomEvent('close'));
        }
    }

    // A link's raw value can optionally use markdown link syntax
    // (`[Label](https://...)`) to give it a friendlier name than the bare
    // URL; anything else is shown as-is with the URL as its own label.
    function parseMarkdownLink(str) {
        var match = /^\[([^\]]+)\]\((\S+)\)$/.exec((str || '').trim());
        return match ? { label: match[1], href: match[2] } : { label: str, href: str };
    }

    function renderLinkSection(className, heading, items) {
        if (!items.length) {
            return null;
        }
        var wrap = document.createElement('div');
        wrap.className = className;
        wrap.innerHTML = '<h4>' + esc(heading) + '</h4>';

        var ul = document.createElement('ul');
        items.forEach(function (item) {
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.href = item.href;
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
            a.textContent = item.label;
            li.appendChild(a);
            ul.appendChild(li);
        });
        wrap.appendChild(ul);
        return wrap;
    }

    function renderStageLinks(stage) {
        var items = (stage.links || [])
            .map(function (link) { return link && link.value; })
            .filter(Boolean)
            .map(parseMarkdownLink);
        return renderLinkSection('links', t('links', 'Links'), items);
    }

    function renderStageAttachments(stage) {
        var items = (stage.attachments || [])
            .filter(function (file) { return file && file.url; })
            .map(function (file) { return { label: file.name || file.url, href: file.url }; });
        return renderLinkSection('attachments', t('attachments', 'Attachments'), items);
    }

    function renderRelatedFields(journey, stage, body, fieldRefs) {
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
        apiFetch(recordPath + '/stage-fields?' + relatedFields.map(function (k) {
            return 'field_keys[]=' + encodeURIComponent(k);
        }).join('&')).then(function (data) {
            fieldsBody.innerHTML = '';
            Object.keys(data.fields || {}).forEach(function (key) {
                var el = document.createElement('div');
                el.className = 'field';
                el.innerHTML = data.fields[key];
                fieldsBody.appendChild(el);

                // These fields normally auto-save on change, but the note
                // field can't (it needs an explicit Save action), so for
                // consistency these are committed on Save too -- see
                // flushFieldChanges(), which reads the current value straight
                // off the element rather than tracking edits as they happen.
                var fieldEl = el.querySelector('[id="' + key + '"]');
                if (fieldEl) {
                    fieldRefs[key] = { el: fieldEl, original: fieldEl.value };
                }
            });
        });
    }

    // Commit each related field's current value (compared against the value
    // it had when the modal opened) through the theme's own change handler --
    // the same one auto-save uses -- so every field type serializes exactly
    // like it would during live auto-save.
    function flushFieldChanges(fieldRefs) {
        var componentService = window.componentService;
        var fieldKeys = Object.keys(fieldRefs || {}).filter(function (key) {
            // handleChangeEvent() has no such guard itself -- it always POSTs
            // an update -- so skip fields the user didn't touch, or every
            // Save would redundantly resubmit every related field's existing
            // value (and risk a spurious activity-log entry for field types
            // whose serialization isn't perfectly stable on resubmission).
            return fieldRefs[key].el.value !== fieldRefs[key].original;
        });
        if (!fieldKeys.length || !componentService) {
            return Promise.resolve();
        }
        return Promise.all(fieldKeys.map(function (key) {
            var ref = fieldRefs[key];
            return componentService.handleChangeEvent({
                target: ref.el,
                detail: { field: key, newValue: ref.el.value, oldValue: ref.original },
            });
        }));
    }

    function openStageModal(journey, stage, presetStatus) {
        if (!stageModal) {
            return;
        }
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

        var links = renderStageLinks(stage);
        if (links) {
            body.appendChild(links);
        }

        var attachments = renderStageAttachments(stage);
        if (attachments) {
            body.appendChild(attachments);
        }

        // Lets a status set earlier be revisited/corrected here, rather than
        // only being settable once via the tile's quick-action buttons (which
        // disappear once a stage is no longer not_started).
        var statusWrap = document.createElement('div');
        statusWrap.className = 'status-control';
        var statusLabelEl = document.createElement('label');
        statusLabelEl.textContent = t('status_label', 'Status');
        var statusSelect = document.createElement('select');
        statusSelect.className = 'status-select';
        STAGE_STATUS_KEYS.forEach(function (key) {
            var opt = document.createElement('option');
            opt.value = key;
            opt.textContent = statusLabel(key);
            if (key === (presetStatus || stage.status)) {
                opt.selected = true;
            }
            statusSelect.appendChild(opt);
        });
        statusWrap.appendChild(statusLabelEl);
        statusWrap.appendChild(statusSelect);
        body.appendChild(statusWrap);

        var fieldRefs = {};
        renderRelatedFields(journey, stage, body, fieldRefs);

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
            return flushFieldChanges(fieldRefs).then(function () {
                return setStageStatus(journey.ID, stage.ID, statusSelect.value, noteInput.value);
            });
        };

        stageModal.dispatchEvent(new CustomEvent('open'));
    }

    // ── Init ───────────────────────────────────────────────────────────────

    loadRecord();
})();
