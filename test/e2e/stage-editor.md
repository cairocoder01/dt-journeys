# E2E — Stage Editor (journey details admin page)

Browser checks for the stage add/edit panel on `/admin/journeys/<id>/` and `/admin/journeys/new/`.

**Precondition:** `dt-journeys` active on the target LocalWP site; log in as an admin.
Use a journey that already has stages (e.g. "Foundations 1", journey 743 on dtplayground)
and the "new journey" page for the unsaved-journey flows.

**Last run:** 2026-10-06 against `1c064e2` on dtplayground — all checks below PASS except
where noted.

---

## A. Existing journey (`/admin/journeys/<id>/`)

1. **Journey detail page loads** — the page shows a "< Back" link, a Journey Details panel
   and a Stages panel listing each stage in `stage_order`.
2. **Edit panel opens populated** — clicking the pencil on a stage slides in the right-hand
   panel titled "Edit Stage" with that stage's Name, Description, Instructions, Attachments,
   Links, Related Fields and Success Action Label filled in.
3. **Existing-stage edits auto-save** — change the stage Name and blur. A
   `POST /wp-json/dt-posts/v2/journey_stages/<stage_id>` fires and returns 200; after a page
   reload the new name is shown. Existing stages have no Save button by design —
   `#stage-save-container` is `display: none` in this state.
4. **Stage list refreshes after an edit** — after the auto-save in check 3, closing the panel
   (X) updates the row in the left-hand list without a reload. The row is intentionally
   *not* live-updated on blur; `sync_stage_list()` runs on close.
5. **Add Stage panel** — "Add Stage" opens the panel titled "Add Stage" with every field
   empty plus Cancel / Save Stage buttons, and sets `componentService.postId = 0`.
6. **New stage saves and appears** — fill Name + Description, click Save Stage.
   `POST /wp-json/dt-journeys/v1/journeys/stage` returns 200 AND the new row appears in the
   list without a reload.
7. **Add form clears after a save** — reopening "Add Stage" after a save shows empty fields
   (every `DT-*` control is `.reset()` on open), so a second Save Stage cannot duplicate the
   previous stage.
8. **Stage delete** — the trash icon calls
   `DELETE /wp-json/dt-journeys/v1/journeys/stage/<id>`, returns 200, removes the row, and
   removes the entry from `window.stages`. **No console error** (regression guard: this
   previously threw `ReferenceError: idx is not defined`).
9. **Stage reorder** — drag-reordering calls
   `POST /wp-json/dt-journeys/v1/journeys/<id>/reorder-stages`, returns 200, and the new
   order survives a page reload.
10. **No duplicate form-field ids** — journey fields render as `journey_<key>`, stage fields
    as `stage_<key>`. No id is shared between `#journey-form` and `#stage-form`.
11. **No console errors** — no JS console errors while opening, editing, saving, deleting,
    reordering and closing the panel.

## B. New journey (`/admin/journeys/new/`)

12. **Empty-state message** — with no stages the list shows
    "No journey stages created. [Add Stage]", and it disappears once a stage is added.
13. **Add Stage creates no orphan** — `journeyId` is 0, so saving a stage fires **no** REST
    request; the stage is held in `window.stages` with a `temp_id` and rendered in the list.
14. **Unsaved stages are editable** — clicking the pencil on a locally-held stage shows the
    Cancel / Save Stage buttons (unlike an existing journey's stages) and keeps
    `componentService.postId = 0`, so no stray writes go to `dt-posts/v2/journey_stages/<temp_id>`.
15. **Cancel / X discards** — editing a locally-held stage and clicking Cancel (or the X)
    leaves `window.stages` and the list row unchanged.
16. **Save Stage commits** — the same edit followed by Save Stage updates `window.stages` and
    the list row.
17. **Journey save creates the journey and all held stages** — fill the journey Name and use
    the split Save button. `POST /wp-json/dt-journeys/v1/journeys` returns the new journey,
    every held stage is created and connected, "Save & Continue" lands on
    `/admin/journeys/<newId>/`, and the stages appear in the order they were added.
18. **New stages sort last** — a stage added to a journey is ordered after the existing ones
    after a reload, both when the journey has never been reordered (all `stage_order = 0`)
    and when it has.

## C. Validation and error handling

19. **Blank stage name is blocked** — click Save Stage with the Name empty: the form does not
    submit, no request fires, no row is added, and the `dt-text` renders
    "This field is required".
20. **A rejected save shows an error and keeps the panel open** — reproduce a server-side
    rejection by renaming the name field so the JS cannot find it:
    ```js
    const el = document.getElementById('stage_name');
    el.id = '_name'; el.setAttribute('name', '_name');
    ```
    Then Add Stage, fill the (renamed) Name, and Save Stage. The request goes out with a
    blank name, the API rejects it, and the UI must show "Error: title needed" in
    `#stage-detail-error`, keep the edit panel open, and add no row.
    > Note: the API currently returns **500** for this validation failure (`DT_Posts::create_post`
    > returns a `WP_Error` with no `status`). A 400 would be more correct, but the UI handles
    > it either way.
21. **Errors render in the right panel** — save/validation errors go to `#stage-detail-error`
    inside the edit panel (visible on mobile while the panel is shifted); delete errors go to
    `#stage-list-error` in the stages panel; journey save/delete errors go to
    `#journey-detail-error`.
    **PARTIAL** — reorder failures still only `console.error`; they should also surface in
    `#stage-list-error`.
22. **Switching stages mid-edit** — on `/admin/journeys/new/`, edit stage A, change its Name,
    then click the pencil on stage B without saving.
    **KNOWN GAP** — A's change is silently discarded with no warning
    (`edit_stage()` only calls `sync_stage_list()` when `journeyId > 0`). Consistent with
    Cancel discarding, but unsignalled.

## D. Known gaps not yet covered

- **Links help text** — the stage `links` field should hint the
  `[Label](https://example.com)` syntax. Deferred to a web-components PR
  (`dt-multi-text` help text), so there is nothing to assert here yet.
- **Duplicate ids outside the field forms** — on `/admin/journeys/new/` the split Save button
  is rendered twice, producing two `#save-btn` and two `#save-split-button`. Currently benign
  (the inline `onclick` calls `stopPropagation()`), but invalid HTML and the same class of
  defect the `journey_`/`stage_` prefixes fixed.

## Cleanup

Anything a run creates must be removed:
- Delete the test journey from its detail page ("Delete Journey") — this cascades to its stages.
- Restore any renamed stage and any reordered journey to its original name and order.
- Confirm no leftovers: `GET /wp-json/dt-posts/v2/journey_stages?limit=100` should contain no
  stages from the run.
