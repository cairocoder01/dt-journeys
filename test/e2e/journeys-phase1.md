# E2E — Journeys Phase 1 (definition data model)

Browser checks for the `journeys` + `journey_stages` custom post types.
**Precondition:** the `dt-journeys` plugin is active on the target LocalWP site and
DT roles have been re-flushed (visit `/wp-admin/` once as admin after activation).

Run against the site as an admin (e.g. `admin`/`admin` on `dtplayground.local`).

## Checks

1. **Journeys nav item** — After login, the main DT desktop nav includes a
   "Journeys" tab linking to `/journeys/`.
2. **Journeys list page** — Navigate to `/journeys/`. Returns 200 (not 404) and
   shows the DT list UI with the "Journeys" heading and a "New Journey" affordance.
3. **journey_stages hidden** — The main nav does NOT contain a "Journey Stages"
   tab, and `/journey_stages/` is not linked from the nav (utility post type).
4. **Create a journey** — Navigate to `/journeys/new`. The create form shows the
   Name field plus Phase 1 fields: Category (key_select), Display Type
   (Timeline/List/Grid), Sequential (toggle). Create "E2E Test Journey" and
   confirm it saves and opens the record detail page.
5. **Journey record fields** — On the new journey's detail page, confirm the
   Category, Display Type, Sequential, and Stages fields are present in the
   Details tile.
6. **No console errors** — None of the above pages emit JS console errors related
   to journeys.

## Notes
- Stages are managed only in the context of a journey (Phase 4 admin UI); there is
  no standalone stage nav.
- `stage_order` sorting is covered by the PHPUnit test
  (`test/unit-test-journeys-post-type.php`); the tile display of ordered stages is
  a Phase 3 concern.
