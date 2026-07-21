# Journeys Tile — As-Built Feature Reference

> Companion to [overview.md](./overview.md) and [implementation-plan.md](./implementation-plan.md).
> [overview.md](./overview.md) is the original pre-build spec; this document describes what Phase 3
> actually shipped, including behavior decided during implementation that wasn't spelled out up
> front. Use this as the source when writing end-user documentation or release notes for Phase 3.

## Record view (contact/group tile)

- A record can have any number of journeys attached at once. Each one is **collapsed by default**
  and shows a single summary line so the tile stays compact with several journeys attached; click
  anywhere on a journey's header to expand/collapse it.
- The collapsed summary is a **percent-complete progress bar** (not text), which works the same way
  for sequential and non-sequential journeys alike: percent = stages marked `complete` ÷ total
  stages, except a journey whose overall status is `completed` always shows 100% — even if some
  stages ended up `skipped` rather than `complete` (see "Mark Journey Complete" below). The bar is
  blue while active and turns green once the journey is fully completed.
- Expanding a journey shows its full stage list:
  - **Sequential** journeys render as a vertical timeline (connected markers/line).
  - **Non-sequential** journeys render as a simple list (no connecting line).
  - Each stage shows its name, short description, and current status/date. A stage still
    `not_started` shows three quick-action buttons — **Complete** (green), **Skip** (gray/muted),
    **Stalled** (red) — using the theme's `--success-color` / `--gray-2` / `--alert-color` tokens.
    Once a status is set, the buttons give way to the status/date text — but see "Stage pop-out"
    below, the status is never actually locked in.
  - Clicking **Complete** opens the stage pop-out (below) with its status pre-set to Complete,
    rather than saving instantly — this is deliberate, so there's a chance to add a note or fill in
    a related field at the same time. **Skip** and **Stalled** save immediately without opening the
    pop-out.
  - Clicking a stage's name (regardless of its current status) always opens the pop-out.
- **Mark Journey Complete** button (shown while a journey is active): for sequential journeys, any
  stage not already `complete`/`skipped` is force-set to `skipped`. Non-sequential journeys are left
  as-is aside from the journey's own status. If the journey has a `next_journey` configured, the
  user is prompted to start it immediately after.
- **Remove Journey** button (shown regardless of status): deletes the journey's *entire* progress
  entry from the record — distinct from completing it. This is for undoing a mistaken or test
  "Start," not for finishing a journey; a confirmation prompt guards it since it can't be undone.
  Removing a journey makes it eligible to show up in "Add a Journey" again (see below).
- **Contacts also show a read-only roll-up** of journeys currently active on any group the contact
  belongs to (groups the user can't view are silently skipped). This roll-up doesn't expose the
  remove/complete/quick-action controls — it's informational only.

## Stage pop-out (`<dt-modal>`)

Opened by clicking a stage's name or its Complete button. Shows, in order:

1. **Instructions** — the stage's long-form rich text (line breaks preserved).
2. **Links** — the stage's `links` field, rendered as a plain list. A link's value can be a bare URL
   (shown as-is) or use **markdown link syntax** — `[Custom Label](https://example.com)` — to give it
   a friendlier display name than the raw URL. This directly supports course-material journeys (like
   T4T's "Teaching Guide" / "Lessons" links, see [example-journeys.md](./example-journeys.md)) where
   a stage needs more than one distinctly-named link.
3. **Attachments** — the stage's `attachments` field (real file uploads via the theme's storage
   integration), rendered as download links using each file's real name and a resolved URL. This is
   separate from Links — Attachments are uploaded files, Links are external URLs.
4. **Status** — a dropdown covering all six statuses (Not Started / Started / Paused / Stalled /
   Complete / Skipped), pre-selected to whatever's relevant (the stage's current status, or
   "Complete" if opened via the quick-action button). **This is the only way to change a status once
   it's already been set** — the tile's quick-action buttons only ever apply to a `not_started`
   stage.
5. **Related Fields** — an inline quick-edit of whichever of the record's own fields the stage
   definition lists as relevant (contact/group fields, not journey fields). These are quick-edit
   convenience fields, not the record's authoritative edit form:
   - A field marked `required` on the main record form is **never enforced as required here** — the
     field renders without the `required` flag so no validation error can block or flash during this
     quick edit. The field's real required-ness is untouched everywhere else.
   - Field values are **not** auto-saved on change; nothing commits until the pop-out's own **Save**
     button is clicked, matching the note field's behavior for a consistent single save action. Only
     fields the user actually edited are re-submitted on Save — an untouched field isn't resent just
     because Save was clicked (e.g. to record a note or revisit the status).
6. **Note** (optional) — free text, pre-filled with whatever was saved last time the stage was
   completed. If provided *and changed* from what's already stored, it's saved as a new comment on
   the record, **prefixed with the stage's name** (e.g. `"Reading Plan: Finished the first
   chapter"`) so its origin is never lost once it's just one comment among many; the journey/stage
   IDs are also stored as comment meta for anything that wants to query it later. Re-saving without
   editing the note (e.g. reopening a completed stage just to check its status) does **not** create
   a duplicate comment — only an actual change to the note text writes a new one.

Clicking **Save** commits the selected status, the note (if any), and any related-field edits
together, then closes the pop-out.

## "Add a Journey"

- Lists every journey definition **without any existing progress on this record** — active or
  completed. Once a journey is finished, its existing (now read-only-ish, but still viewable) entry
  is how you go back to it; it does not reappear in this list as if it could be started fresh. Use
  "Remove Journey" first if a genuine restart is needed.
- Filtered by `journey_roles` against the current user's roles (an admin/`manage_dt` user sees
  every journey regardless).
- **Category filter tags**: pill-style buttons ("All" + one per distinct `journey_category` tag
  value present across the available list) filter the list client-side. `journey_category` is a
  freeform tags field (no fixed option set), so the tag list is whatever's actually been used on the
  journeys created so far.
- Clicking **Start** on a journey begins it immediately and closes the modal.

## Activity log

Every progress change (start, stage status, complete, remove) writes an entry to the record's
activity/comments feed with a clean, readable, **action-first** sentence:

- `Journey Started: <name>`
- `Journey Completed: <name>`
- `Journey Removed: <name>`
- `Stage <Status>: <name>` — e.g. `Stage Completed: Reading Plan`, `Stage Skipped: ...`,
  `Stage Stalled: ...`

A stage-status entry is only logged when the status actually changes — re-saving the pop-out
without changing the status (e.g. reopening a completed stage just to add a note) does not log a
second "marked Complete" entry, and doesn't bump the stage's recorded date either.

Without this, DT's generic activity formatter has no idea what the plugin's own meta blob means and
would otherwise print a raw `dt_journeys_progress: a:1:{...}` line; the plugin also suppresses DT's
separate generic "field changed" auto-log entry for its own meta keys (`dt_journeys_progress`,
`dt_journeys_active`) since it would otherwise duplicate the same information as an unreadable
serialized dump right next to the clean entry above.

## Permissions

- Any user with `access_contacts` (i.e. any standard DT user) can **view and progress** journeys on
  records they can already view/update — start one, change stage status, add a note, remove a
  journey instance. This does not require any journeys-specific capability.
- Full CRUD on journey/stage **definitions** (creating/editing/deleting the journeys and stages
  themselves, as opposed to a record's progress through them) requires `manage_journeys` — held by
  admins (`manage_dt`) by default, and delegatable to any other role either via the `journeys_admin`
  support role or by toggling the capability directly on the roles/permissions screen.
- Deleting a journey/stage **definition** (not a record's progress instance — see "Remove Journey"
  above for that) uses the theme's standard "Admin Actions → Delete" menu on the journey/stage's own
  record page, gated on `manage_journeys` the same way as edits.

## REST API (`dt-journeys/v1`)

Routes are resource-first, action-last — `{post_type}/{post_id}/{action}` — matching the shape of
the theme's own `dt-posts/v2/{post_type}/{id}/{sub-resource}` endpoints. Any future custom endpoint
this plugin adds (admin definition CRUD in Phase 4, dashboard list in Phase 6, etc.) should follow
the same convention.

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/{post_type}/{post_id}` | The record's attached journeys + progress (+ group roll-up for contacts) |
| GET | `/{post_type}/{post_id}/available` | Journeys with no progress yet on this record, filtered by role |
| GET | `/{post_type}/{post_id}/stage-fields` | Pre-rendered HTML for a stage's `related_fields`, required stripped |
| POST | `/{post_type}/{post_id}/start` | Start a journey (`journey_id`) |
| POST | `/{post_type}/{post_id}/stage-status` | Set a stage's status (`journey_id`, `stage_id`, `status`, `note`) |
| POST | `/{post_type}/{post_id}/complete` | Mark a journey complete (`journey_id`, `force`) |
| DELETE | `/{post_type}/{post_id}/remove` | Remove a journey instance entirely (`journey_id`) |

`{post_type}` is `contacts` or `groups`. All routes require the same view/update permission as the
underlying record (`can_view`/`can_update`).
