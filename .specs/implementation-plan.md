# Journeys — Implementation Plan

> Companion to [overview.md](./overview.md), [tech-details.md](./tech-details.md),
> [example-journeys.md](./example-journeys.md), and [tile-features.md](./tile-features.md). This
> plan turns those specs into phased, independently-verifiable work.

## Architecture decisions (locked)

| Concern | Decision |
| --- | --- |
| Journey & Stage **definitions** | Two custom post types: `journeys` and `journey_stages` (ordered, connected) |
| Per-record **progress** | Post **meta** on the contact/group record + a lightweight active-journey marker for dashboard queries |
| **Presets** (Seeker Path, Waha, T4T, Zume, …) | Bundled JSON, importable from the admin UI, then editable as normal records |
| **First shippable milestone** | Admin definition builder + a Journeys **tile** on the contact/group record |
| **Definition CRUD permission** | New `manage_journeys` capability (admins by default, delegatable via a `journeys_admin` support role); any user with `access_contacts` can view/progress journeys on records they can already view/update — see Phase 1 |
| **Modals** | The theme's real `<dt-modal>` web component, not `<dt-tile>` — used for both the Phase 3 stage pop-out and (going forward) any Phase 4/5 admin dialogs |
| **REST route shape** | Resource-first, action-last — `{post_type}/{post_id}/{action}` — matching `dt-posts/v2/{post_type}/{id}/{sub-resource}`. Any new custom endpoint in later phases should follow the same pattern |
| **CSS build** | None — this plugin's CSS is raw, browser-native CSS nesting, not Sass. Sass-style `&--modifier`/`&__element` bare-identifier concatenation is **silently dropped** by real browsers; only `&.explicit-class`, `&:pseudo-class`, and plain nested selectors work. Keep this in mind for any new CSS in Phase 4+ |

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
- `instructions` (textarea — long form / course material; rendered as plain text with line breaks
  preserved, not WYSIWYG)
- `links` (repeating `link` field) — external URLs; a value may use `[Label](url)` markdown syntax
  for a friendlier display name
- `attachments` (repeating `file_upload` field) — real uploaded files (PDFs/images/etc.), resolved
  to a downloadable URL via the theme's storage integration; kept distinct from `links`
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
4. Define capabilities/roles (**decided/built**): register a `manage_journeys` capability (via
   `dt_capabilities`) — granted to `manage_dt` (admin) roles by default, and to a new delegatable
   `journeys_admin` support role (mirrors the built-in `strategist` role pattern: any user can hold
   it alongside their normal role). `manage_journeys` gates full CRUD (`create`/`update`/`view_any`/
   `update_any`/`delete_any`) on both `journeys` and `journey_stages`. Separately, any role with
   `access_contacts` gets `access_journeys`/`view_any_journeys` — i.e. every standard user can browse
   and attach journeys, view-only, without needing `manage_journeys` at all (progressing a journey on
   a record they can already update is a record-permission question, not a journeys-definition one —
   see Phase 2/3's REST permission model). Note: `dt_setup_roles_and_permissions()` only runs on
   `wp-admin` page loads, so a fresh site needs one admin-panel visit before newly-granted
   capabilities actually sync to stored role capabilities.
5. Seed field definitions for `journey_category` and `display_type` key_selects. `journey_category`
   ended up as a freeform `tags` field (no fixed option list) rather than `key_select`, so it grows
   organically as journeys are created — Phase 4's admin UI should offer autocomplete against
   existing values to avoid the same category being typed inconsistently across journeys (e.g.
   "T4T" vs "t4t"). The Phase 3 tile already filters by these tags in its "Add a Journey" list.

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
   `add_post_comment()` note. Set comment type to Journeys so they can be filtered. **Built:** the
   comment text is prefixed with the stage's name (e.g. `"Reading Plan: <note>"`) and the
   journey/stage IDs are stored as comment meta (`journeys_journey_id`/`journeys_stage_id`), since a
   bare note would otherwise lose track of which stage it came from once it's just one comment among
   many on the record.
5. Enforce that a stage's `related_fields` edits go through `DT_Posts::update_post()` so normal
   field validation/permissions/logging apply. **Built:** the Phase 3 stage pop-out's quick-edit of
   these fields always renders them with `required` stripped, regardless of the field's real setting
   on the main record form — see [tile-features.md](./tile-features.md#stage-pop-out-dt-modal). Any
   future UI that quick-edits record fields outside the main form should follow the same rule.
6. **Built, not originally planned:** a stage added to a journey's definition *after* a record has
   already started that journey is automatically backfilled into the record's progress as
   `not_started` the next time progress is read (and persisted, not just returned in-memory) —
   except for journeys the record has already **completed**, which are left untouched so history
   doesn't shift under a later definition change. This matters for Phase 4: an admin can safely add
   a stage to a journey that's already in use without needing to touch existing records.
7. **Built, not originally planned:** a `remove_journey()` method that deletes a record's whole
   progress entry for a journey (distinct from completing it) — see Phase 3's "Remove Journey."
8. **Built, not originally planned:** DT's generic activity-feed formatter has no idea what this
   plugin's `dt_journeys_progress.*` meta_key means and would otherwise print a raw, unreadable line.
   Fixed via two hooks any future progress-mirroring action in this plugin should also use:
   - `dt_format_activity_message` — formats this plugin's own `dt_activity_insert()` entries
     (`field_type => 'journeys'`) into a clean, action-first sentence.
   - `dt_ignore_fields_logging` — suppresses DT's *separate* generic post-meta-change auto-logger
     for this plugin's own meta keys (`dt_journeys_progress`, `dt_journeys_active`), which would
     otherwise duplicate the same change as a second, raw-serialized entry right next to the clean
     one above.

**Verify**
- PHPUnit: status transitions, sequential completion cascade (remaining → skipped), non-sequential
  completion, marker meta stays consistent, activity-log entry created per change, activity message
  formatting, stage backfill, and journey removal.

---

## Phase 3 — Journeys tile on the record (FIRST MILESTONE, part A)

**Status: built, pending PR review.** Full as-built behavior is documented in
[tile-features.md](./tile-features.md) — treat that as the authoritative reference (including
several decisions/features below that weren't in the original task list) and this section as a
historical summary.

A web-component tile on the contact/group detail page, registered via `dt_details_additional_tiles`
and hydrated from REST (mirrors the theme's web-component + `ComponentService` approach).

**Tasks**
1. Register a `journeys` tile on `contacts` and `groups`.
2. REST endpoints (namespace `dt-journeys/v1`, see [tile-features.md](./tile-features.md#rest-api-dt-journeysv1)
   for the full route table): get a record's journeys+progress, set stage status, start journey,
   complete journey, **remove a journey instance** (built, not originally planned — see below), list
   available journeys to add (filtered by the record's applicable roles).
3. Tile UI:
   - **Sequential** journey → timeline; **non-sequential** → list, both flat (divider-row, not
     bordered-card) layouts. Collapsed by default with a **percent-complete progress bar** as the
     summary (built, not originally planned — replaces an earlier text-summary approach; works
     uniformly for sequential and non-sequential journeys, so there's no separate "what to show for
     non-sequential" question). Expand to see the full per-stage list. Small/mobile layout uses a
     CSS `@container` query (`dt-journeys` container name) rather than a viewport media query, so it
     responds to the tile's own column width; a wider layout variant can be added later as another
     `@container` rule alongside it.
   - Per-stage: name, short description, links, completion date; action buttons **Complete / Skip /
     Stalled**, color-coded via `--success-color`/`--gray-2`/`--alert-color` (built, not originally
     planned); allow skipping steps (don't force sequential completion, but visually encourage it).
   - Stage **pop-out** (`<dt-modal>`, not `<dt-tile>` — corrected mid-build): rich-text instructions;
     **Links** (supports optional `[Label](url)` markdown syntax for a friendlier display name, built
     — not originally planned, needed for stages with multiple distinctly-named links, e.g. T4T's
     "Teaching Guide"/"Lessons"); **Attachments** as a separate section for real uploaded files
     (built, not originally planned — resolves to a real download URL via the theme's storage
     integration, distinct from Links); a **Status** dropdown that can revisit/correct any stage's
     status at any time, not just while it's `not_started` (built, not originally planned); inline
     editing of the stage's `related_fields` (commits only on the pop-out's own Save, not
     auto-save-on-change, `required` never enforced here); optional note, saved as a stage-prefixed
     comment.
   - "Add a journey" (only offers journeys with **no existing progress**, active or completed —
     completed journeys don't reappear; filtered further by **category tag chips**, built, not
     originally planned) and "Mark journey complete" (end-of-journey) controls; on completion,
     prompt to start another (including `next_journey` if set). "**Remove Journey**" (built, not
     originally planned) fully deletes a journey instance's progress, distinct from completing it —
     for undoing a mistaken/test start.
4. Contact view also shows journeys for the **groups the contact belongs to** (read-only roll-up,
   resolved via existing contact↔group connections).

**Verify**
- Test on local site: attach a journey to a contact, advance/skip/stall stages, edit a related
  field from the pop-out, complete the journey and confirm the start-another prompt; confirm a
  group's journeys surface on its member contact; remove a journey instance and confirm it becomes
  available to start again; confirm activity-log entries read as clean sentences, not raw meta dumps.

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
3. REST endpoints for definition CRUD + stage reorder, all behind `manage_journeys`. Follow the
   Phase 3 route convention — resource-first, action-last (e.g. `/journeys/{id}/reorder-stages`) —
   for consistency across the plugin's whole REST surface.
4. Enqueue assets with `filemtime()` cache-busting and `wp_localize_script` for schema/i18n.

**Carried over from Phase 3 — apply these when building the admin UI:**
- **Links field editor**: the stage pop-out supports `[Label](url)` markdown syntax so a link can
  have a friendlier name than its raw URL (see [tile-features.md](./tile-features.md)). The admin
  editor for a stage's `links` should surface this (placeholder text, helper hint, or a live
  preview) rather than leaving authors to discover the syntax on their own.
- **Category field is freeform tags**, not a fixed option list — offer autocomplete against
  categories already used on other journeys so authors don't fragment the same category across
  slightly different spellings/capitalization.
- **Attachments are real file uploads** (`file_upload` type via the theme's storage integration),
  separate from Links (external URLs) — the admin UI needs an actual upload widget for these, not
  just a text field.
- **Adding a stage to a journey already in use is safe**: any record that already started that
  journey gets the new stage backfilled as `not_started` automatically (see Phase 2) — no manual
  migration needed when editing a live journey's stage list.
- Use `<dt-modal>` for any admin-side popups/dialogs (stage editor, reorder confirmation, etc.), not
  `<dt-tile>`.
- This plugin's CSS has no build step (raw native nesting) — avoid Sass-style `&--modifier`/
  `&__element` string concatenation; it silently fails in real browsers. Use `&.explicit-class` or
  plain nested selectors instead.

**Verify**
- A non-admin user granted `manage_journeys` can reach `/admin/journeys`, build a journey with
  ordered stages, and see it become attachable in the Phase 3 tile; a user without the cap is denied.

---

## Phase 5 — Importable presets

**Tasks**
1. Author bundled preset JSON from [example-journeys.md](./example-journeys.md): Media Follow-up /
   Seeker Path, Waha "Foundations 1", T4T "7 Lessons", Zume, Multiplying Hope "New Hope". T4T's
   multiple distinctly-named links per stage ("Teaching Guide", "Lessons") map directly onto the
   Phase 3 `[Label](url)` markdown-link syntax — use it in the bundled JSON rather than bare URLs.
2. Importer that converts a preset into `journeys` + `journey_stages` records (idempotent; safe to
   re-run; imported journeys are fully editable afterward). Presets should stick to **Links**, not
   **Attachments** — a bundled attachment (`file_upload`) would need an actual file uploaded to the
   site's storage during import, which is more moving parts than a portable JSON preset needs; users
   can add real file attachments locally afterward if they want them.
3. "Import preset" action in the Phase 4 admin UI with a preview of what will be created.

**Verify**
- Import T4T "7 Lessons" → 7 ordered stages with instructions/links created; re-import does not
  duplicate; the imported journey works in the record tile; a stage with two named links renders
  both with their custom labels, not the raw URLs.

---

## Phase 6 — Journey List / Dashboard

Cross-record dashboard, driven by the `dt_journeys_active` marker meta for performance.

**Tasks**
1. Frontend list page (all record types together: contacts, groups, …) with record name, status,
   and a visual progress indicator per active journey; completed journeys hidden. Reuse the Phase 3
   percent-complete progress bar visual (blue while active, green at 100%) for consistency rather
   than inventing a second progress indicator style.
2. Filters: All viewable to user, Assigned to user, Favorite, by Record Type (multi-select), by
   Journey (multi-select). Consider also **by Category** (multi-select) now that `journey_category`
   is a tags field in real use — not required by the original spec, but a near-free addition given
   the data already exists.
3. REST list endpoint that queries records via the marker meta, then hydrates progress; paginated.
4. **Open question, not decided:** Phase 3 added a per-record "Remove Journey" action (deletes a
   progress instance entirely). Decide whether the dashboard needs a bulk-remove/bulk-complete
   action across many records, or whether per-record removal via the tile is sufficient.

**Verify**
- Test on site: dashboard lists a contact and a group each with an active journey; each filter narrows the
  set correctly; completed journeys are excluded; progress indicators reflect stage completion.

---

## Phase 7 — Polish, integration & hardening

**Tasks**
1. i18n sweep (`dt-journeys` text domain), accessibility pass on tile/dashboard.
2. Notifications: optional prompt/notification when a journey completes to start the next.
3. Docs in `documentation/`; update `.specs` cross-links. Turn
   [tile-features.md](./tile-features.md) into user-facing documentation/release notes — it's
   already written as an as-built feature reference, not implementation notes.
4. Full suite green: PHPCS, ESLint/Prettier, PHPUnit (multisite), Vite build parity.
5. If any later phase adds its own progress-mirroring activity-log entries, apply the same
   `dt_format_activity_message` / `dt_ignore_fields_logging` pattern from Phase 2 rather than letting
   a new raw meta dump slip into the activity feed.

**Verify**
- (PHPCS + ESLint + PHPUnit) passes; manual smoke of the full flow end-to-end.

---

## Open items / risks to confirm during build (non-blocking)

1. **Dashboard vs. meta storage** — progress-in-meta means the dashboard relies on the
   `dt_journeys_active` marker. If cross-record reporting needs grow (e.g. metrics charts by stage),
   we may later add a read-model table. Flagging now; not required for the phases above. **Still
   open** — not addressed by Phase 3 (Phase 6 not yet built).
2. ~~**Rich-text editor**~~ — **Decided (Phase 3):** `instructions` is displayed as plain text with
   line breaks preserved (`\n` → `<br>`), no WYSIWYG rendering. Revisit only if a preset actually
   needs richer formatting than that.
3. ~~**Attachments**~~ — **Decided (Phase 3):** both, and they're kept distinct rather than merged —
   `links` (external URLs, optional `[Label](url)` markdown naming) and `attachments` (real
   `file_upload` uploads, resolved to a downloadable URL server-side) render as two separate sections
   in the stage pop-out.
4. ~~**"Related fields" scope**~~ — **Decided (Phase 3):** all renderable contacts/groups fields, not
   a curated subset (`get_related_field_options()` builds the picker's option list from every
   renderable field on both post types).
5. ~~**Roles model**~~ — **Decided (Phase 1/Phase 3):** `manage_journeys` is a new capability, granted
   to `manage_dt` (admin) roles by default and delegatable via a new `journeys_admin` support role
   (mirrors the built-in `strategist` pattern). Any `access_contacts` role can view/progress journeys
   without it.
6. **New, from Phase 3:** should the dashboard (Phase 6) support bulk-remove, given the per-record
   "Remove Journey" action? Not decided — see Phase 6.
