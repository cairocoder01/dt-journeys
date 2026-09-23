# E2E — Stage Editor (journey details admin page)

Browser checks for the stage edit/add panel on `/admin/journeys/<id>/`.
**Precondition:** `dt-journeys` active on the target LocalWP site; log in as admin.
Use a journey that already has stages (e.g. "Foundations 1", journey 743 on dtplayground).

## Checks

1. **Journey detail page loads** — `/admin/journeys/<id>/` returns the "Edit Journey"
   page with a Journey Details panel and a Stages panel listing each stage.
2. **Edit panel opens** — Clicking the pencil on a stage slides in the right-hand
   panel titled "Edit Stage", populated with that stage's Name, Description,
   Instructions, Attachments, Links, Related Fields, Success Action Label.
3. **Existing-stage edits persist** — Change the stage Name and blur. A
   `POST /wp-json/dt-posts/v2/journey_stages/<stage_id>` fires and returns 200;
   after a page reload the new name is shown. (Existing stages auto-save via
   componentService; there is no Save button on these forms — by design.)
4. **Stage list refreshes after an edit** — After the auto-save in check 3, the
   stage row in the left-hand list shows the new name *without* a reload.
   **Currently FAILS** — the row keeps the stale name.
5. **Add Stage panel** — "Add Stage" opens the panel titled "Add Stage" with empty
   fields plus Cancel / Save Stage buttons.
6. **New stage saves and appears** — Fill Name + Description, click Save Stage.
   `POST /wp-json/dt-journeys/v1/journeys/stage` returns 200 AND the new stage
   appears in the list without a reload.
   **Currently FAILS** — it saves, but the list is not refreshed, so the save
   looks like it failed.
7. **Add form clears after save** — Reopening "Add Stage" after a save shows empty
   fields. **Currently FAILS** — the previous values are retained, so a second
   Save Stage click creates a duplicate.
8. **Stage delete** — Clicking the trash on a stage calls
   `DELETE /wp-json/dt-journeys/v1/journeys/stage/<id>` and returns 200, and the
   stage is gone after a reload.
   **Currently FAILS** — returns 500 `rest_invalid_handler` (the endpoint method
   is missing) while the JS removes the row anyway, showing a false success.
9. **Stage reorder** — Drag-reordering stages calls
   `POST /wp-json/dt-journeys/v1/journeys/<id>/reorder-stages` and returns 200.
   **Currently FAILS** — returns 500 `rest_invalid_handler`.
10. **Add Stage on the new-journey page** — On `/admin/journeys/new/` the journey
    does not exist yet (`journeyId` is 0). Saving a stage there must not create an
    orphan.
    **Currently FAILS** — the panel is fully usable and the save returns 200 with
    `"journey": []`, creating a stage attached to no journey.
11. **No duplicate DOM ids** — Field element ids (`name`, `description`,
    `instructions`, …) must be unique across the journey form, `#new-stage-form`
    and every `#stage-form-<id>`.
    **Currently FAILS** — `#name` and `#description` appear 6× on a journey with
    4 stages; the other stage field ids appear 5×.
12. **No console errors** — No JS console errors while opening, editing, saving
    and closing the panel.

## Cleanup
Stages created by a run must be removed:
`DELETE /wp-json/dt-posts/v2/journey_stages/<id>` (the dt-posts route works even
though the plugin's own delete route is broken), and any renamed stage restored.
