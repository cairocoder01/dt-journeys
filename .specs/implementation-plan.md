# Journeys — Implementation Plan

> Companion to [overview.md](./overview.md), [tech-details.md](./tech-details.md), and
> [example-journeys.md](./example-journeys.md). This plan turns those specs into
> phased, independently-verifiable work.

## Architecture decisions (locked)

| Concern | Decision |
| --- | --- |
| Journey & Stage **definitions** | Two custom post types: `journeys` and `journey_stages` (ordered, connected) |
| Per-record **progress** | Post **meta** on the contact/group record + a lightweight active-journey marker for dashboard queries |
| **Presets** (Seeker Path, Waha, T4T, Zume, …) | Bundled JSON, importable from the admin UI, then editable as normal records |
| **First shippable milestone** | Admin definition builder + a Journeys **tile** on the contact/group record |

### Data model overview

**`journeys` (CPT — the template):**
- `name` (title)
- `journey_category` (key_select or tags) — grouping by ministry model (T4T, Zume, …)
- `journey_roles` (multi_select of DT role keys) — which roles this journey applies to; Dispatcher always has access
- `is_sequential` (boolean) — timeline vs. list/grid behavior
- `display_type` (key_select: `timeline` | `list` | `grid`)
- `next_journey` (connection → `journeys`, optional) — "when this ends, start that"
- connection: `journeys_to_stages` (journey ⇄ `journey_stages`)

**`journey_stages` (CPT — a step in a journey):**
- `name` (title)
- `description` (text, short)
- `instructions` (textarea, rich text — long form / course material)
- `attachments` (repeating link/label; PDFs/images via media library or URL)
- `related_fields` (multi_select of DT field keys on contacts/groups) — fields relevant to edit at this stage
- `success_action_label` (text, optional) — rename "Complete"
- `stage_order` (number) — ordering within the journey
- connection: `stages_to_journey` (reverse of above)

> Stages are a hidden/utility post type (not shown in the main DT nav); they are only
> managed through the Journeys admin UI and always in the context of a parent journey.

**Progress (post meta on contact/group):**
- Per record, a single structured meta blob `dt_journeys_progress` (JSON) keyed by journey id:
  ```
  {
    "<journey_id>": {
      "started": "2026-07-01",
      "status": "active|completed",
      "completed_date": null,
      "stages": {
        "<stage_id>": { "status": "not_started|started|paused|incomplete|complete|skipped", "date": "2026-07-05", "note": "" }
      }
    }
  }
  ```
- A flat marker meta `dt_journeys_active` (multi-value: journey ids currently active) — cheap to
  query for the dashboard so we never scan the JSON blob across all records.
- Every status change is mirrored to the DT **activity log** and (for notes) the **comments**
  system, so history/reporting works with existing tooling.

---

## Phase 0 — Rebrand the starter scaffolding

The plugin is still the untouched `disciple-tools-plugin-starter-template` (v0.1). Get to a clean,
correctly-named baseline before adding features.

**Tasks**
1. Rename the main plugin file → `dt-journeys.php`; update header (Name, Text Domain
   `dt-journeys`, version `0.1.0`, required DT theme version).
2. Rename the main class `Disciple_Tools_Plugin_Starter_Template` → `Disciple_Tools_Journeys`
   and update all `Disciple_Tools_Plugin_Starter_Template_*` prefixes across every module.
3. Remove the starter test content: the 25 demo fields and both demo tiles in `tile/`, the demo
   workflow, the placeholder REST endpoint. Keep the module loader structure.
4. Update `version-control.json`, `README.md`, `composer.json`, text-domain in `languages/`.

**Verify**
- Plugin activates on the local site with no PHP errors; `starter_post_type` no longer registered.
- `./tests/test_phpcs.sh` and ESLint pass; existing PHPUnit bootstrap still runs.

---

## Phase 1 — Definition data model (`journeys` + `journey_stages` CPTs)

**Tasks**
1. In `post-type/`, define the `journeys` post type module (extends `DT_Module_Base`, following the
   starter's `loader.php` + `module-base.php` pattern). Add fields listed above via
   `dt_custom_fields_settings`.
2. Add the `journey_stages` post type (hidden from main nav) with its fields.
3. Register the `journeys_to_stages` connection (p2p) so a journey owns an ordered set of stages.
4. Define capabilities/roles: a `journeys` access model plus a `manage_journeys` capability for the
   admin UI (grantable to non-admin roles); Dispatcher/admin get full access.
5. Seed field definitions for `journey_category` and `display_type` key_selects.

**Verify**
- Create a journey + 3 stages via `DT_Posts::create_post()` in a PHPUnit test; assert the
  connection links them and `stage_order` sorts correctly.
- REST round-trip: `POST /dt-posts/v2/journeys` then `GET` returns the journey with connected stages.

---

## Phase 2 — Progress service layer (meta)

**Tasks**
1. Create `DT_Journeys_Progress` service: `get_progress( $post_type, $post_id )`,
   `set_stage_status( $post_id, $journey_id, $stage_id, $status, $note = '' )`,
   `start_journey()`, `complete_journey()`.
2. Implement completion logic: when all stages complete → mark journey `completed` with date; for
   **sequential** journeys, "mark journey complete" sets remaining stages to `skipped`.
3. Maintain the `dt_journeys_active` marker meta in sync on start/complete.
4. On every status change, write a `dt_activity_log` entry and, when a note is supplied, an
   `add_post_comment()` note. Set comment type to Journeys so they can be filtered.
5. Enforce that a stage's `related_fields` edits go through `DT_Posts::update_post()` so normal
   field validation/permissions/logging apply.

**Verify**
- PHPUnit: status transitions, sequential completion cascade (remaining → skipped), non-sequential
  completion, marker meta stays consistent, activity-log entry created per change.

---

## Phase 3 — Journeys tile on the record (FIRST MILESTONE, part A)

A web-component tile on the contact/group detail page, registered via `dt_details_additional_tiles`
and hydrated from REST (mirrors the theme's web-component + `ComponentService` approach).

**Tasks**
1. Register a `journeys` tile on `contacts` and `groups`.
2. REST endpoints (namespace `dt-journeys/v1`): get a record's journeys+progress,
   set stage status, start journey, complete journey, list available journeys to add (filtered by
   the record's applicable roles).
3. Tile UI:
   - **Sequential** journey → timeline; **non-sequential** → list or icon/grid per `display_type`.
   - Per-stage: name, short description, links, completion date; action buttons **Complete / Skip /
     Stalled**; allow skipping steps (don't force sequential completion, but visually encourage it).
   - Stage **pop-out** (`dt-modal`): rich-text instructions + attachments + inline editing of the
     stage's `related_fields` using the matching `dt-*` form components.
   - "Add a journey" and "Mark journey complete" (end-of-journey) controls; on completion, prompt to
     start another (including `next_journey` if set).
4. Contact view also shows journeys for the **groups the contact belongs to** (read-only roll-up,
   resolved via existing contact↔group connections).

**Verify**
- Test on local site: attach a journey to a contact, advance/skip/stall stages, edit a related
  field from the pop-out, complete the journey and confirm the start-another prompt; confirm a
  group's journeys surface on its member contact.

---

## Phase 4 — Journeys admin UI at `/admin/journeys` (FIRST MILESTONE, part B)

Frontend admin page built like User Management (`dt_templates_for_urls` route + capability gate +
web-component UI over REST), so `manage_journeys` can be delegated to non-admins.

**Tasks**
1. Route `/admin/journeys` (+ detail routes) via `dt_templates_for_urls`; gate on `manage_journeys`
   (or `manage_dt`).
2. Definition builder UI: list journeys; create/edit a journey (name, category, roles, sequential,
   display type, next journey); add/reorder/edit/delete stages (drag-order → `stage_order`); edit
   stage instructions (rich text), attachments, and pick `related_fields`.
3. REST endpoints for definition CRUD + stage reorder, all behind `manage_journeys`.
4. Enqueue assets with `filemtime()` cache-busting and `wp_localize_script` for schema/i18n.

**Verify**
- A non-admin user granted `manage_journeys` can reach `/admin/journeys`, build a journey with
  ordered stages, and see it become attachable in the Phase 3 tile; a user without the cap is denied.

---

## Phase 5 — Importable presets

**Tasks**
1. Author bundled preset JSON from [example-journeys.md](./example-journeys.md): Media Follow-up /
   Seeker Path, Waha "Foundations 1", T4T "7 Lessons", Zume, Multiplying Hope "New Hope".
2. Importer that converts a preset into `journeys` + `journey_stages` records (idempotent; safe to
   re-run; imported journeys are fully editable afterward).
3. "Import preset" action in the Phase 4 admin UI with a preview of what will be created.

**Verify**
- Import T4T "7 Lessons" → 7 ordered stages with instructions/links created; re-import does not
  duplicate; the imported journey works in the record tile.

---

## Phase 6 — Journey List / Dashboard

Cross-record dashboard, driven by the `dt_journeys_active` marker meta for performance.

**Tasks**
1. Frontend list page (all record types together: contacts, groups, …) with record name, status,
   and a visual progress indicator per active journey; completed journeys hidden.
2. Filters: All viewable to user, Assigned to user, Favorite, by Record Type (multi-select), by
   Journey (multi-select).
3. REST list endpoint that queries records via the marker meta, then hydrates progress; paginated.

**Verify**
- Test on site: dashboard lists a contact and a group each with an active journey; each filter narrows the
  set correctly; completed journeys are excluded; progress indicators reflect stage completion.

---

## Phase 7 — Polish, integration & hardening

**Tasks**
1. i18n sweep (`dt-journeys` text domain), accessibility pass on tile/dashboard.
2. Notifications: optional prompt/notification when a journey completes to start the next.
3. Docs in `documentation/`; update `.specs` cross-links.
4. Full suite green: PHPCS, ESLint/Prettier, PHPUnit (multisite), Vite build parity.

**Verify**
- (PHPCS + ESLint + PHPUnit) passes; manual smoke of the full flow end-to-end.

---

## Open items / risks to confirm during build (non-blocking)

1. **Dashboard vs. meta storage** — progress-in-meta means the dashboard relies on the
   `dt_journeys_active` marker. If cross-record reporting needs grow (e.g. metrics charts by stage),
   we may later add a read-model table. Flagging now; not required for the phases above.
2. **Rich-text editor** — which editor for stage `instructions` (TinyMCE vs. a lightweight web-
   component editor). Default: reuse whatever the theme already ships for rich text.
3. **Attachments** — links only vs. WP media-library uploads for PDFs/images. Default: support both,
   links first.
4. **"Related fields" scope** — confirm the field picker should offer all contact/group fields or a
   curated subset.
5. **Roles model** — confirm `manage_journeys` is a new capability vs. reusing an existing one, and
   which default roles receive it.
