## Summary

A UI review of the Journeys list (`/admin/journeys/`) and journey detail pages at phone (375px) and tablet (768px portrait / 1024px landscape) sizes, with touch enabled. The main problems are:

- **Touch targets are too small.** Most controls are under the 44px minimum (Apple) / 48px (Material). Several are under 20px tall.
- **Key actions are hard to reach.** On phone, Stages start about 1,180px down the detail page, and destructive actions sit next to common ones.
- **Some interactions only work with a mouse.** Hidden values are in hover tooltips, and stages can only be reordered by dragging.
- **Navigation doesn't match mobile expectations.** Back and the system back gesture leave the page instead of closing the stage editor, and there's no unsaved-changes guard.

The "Proposed" images are static mockups that use real data from a test site. They show direction, not final visual design.

## 🐞 Bug: the Save-mode dropdown triggers Save on a near-miss

On `/admin/journeys/new/`, `toggle_save_dropdown()` checks `event.srcElement.id === "top-icon"`, so the menu only opens when the tap lands exactly on the 13.5×16px chevron icon. A tap 3px inside the toggle's edge was routed to **Save & Go Back** by Chrome's mobile touch adjustment:

```
click: BUTTON#save-btn.button split-main-btn
invalid: journey_name
```

With a filled-in form, this saves and navigates away. This was reproduced in Chrome's mobile emulation.

**Fix:** use `event.currentTarget` or `closest()`, make the toggle at least 44×44 with spacing from the main button, and de-duplicate the `#save-split-button` / `#save-btn` IDs. On phone, consider replacing the split button with a single sticky **Save** (see the new-journey mockup below).

## 1. List page — phone

![List page on phone: current vs proposed](IMG_BASE/compare-01-list-phone.png)

| Issue | Measured |
|---|---|
| Duplicate stacked about 4px above Delete. Duplicate gives no feedback. | 32×34 |
| Three filter inputs stack vertically and use about 145px | — |
| Only Name and Last Modified are visible, so you can't sort by anything else | — |
| "New Journey" button | 100×28 |
| "1 weeks ago" / "1 months ago" — ungrammatical and not translatable | — |

**Proposed**
- Below about 640px, show a card list. The whole card opens the journey, and a single **⋮** opens a bottom sheet with Open / Duplicate / Delete.
- One search field plus a **Filters** button that opens a sheet with Sort, Category and Roles. Show active filters as removable chips.
- After Duplicate, show a toast with **Open** and **Undo**.
- Use `Intl.RelativeTimeFormat` for dates.

## 2. List page — tablet

![List page on tablet: current vs proposed](IMG_BASE/compare-02-list-tablet.png)

| Issue | Measured |
|---|---|
| Edit/duplicate/delete icons | **30×18** (portrait), 40×18 (landscape) |
| Actions wrap 2 + 1 in landscape | — |
| The list of extra roles behind "+4 more" is only in a hover `title` | 50×20 |

**Proposed:** keep the table. Make rows at least 48px tall and use one ⋮ per row (tapping the row already opens the journey). Truncate roles to "Registered, Multiplier +4", with tap-to-expand instead of a hover tooltip.

## 3. Journey detail — phone

![Journey detail on phone: current vs proposed](IMG_BASE/compare-03-detail-phone.png)

| Issue | Measured |
|---|---|
| Stages section starts about 1,180px down the page | — |
| "Delete Journey" sits directly above Stages, in the scroll path | — |
| Stage edit/delete icons | 45×20 |
| Drag handle is the only way to reorder | 23×23 |
| "Links: 0 Attachments: 0 Related Fields: 0" — noisy and hard-coded English | — |
| Back link | 60×19 |

**Proposed**
- A **Details | Stages (n)** tab switch on phone and tablet portrait. Existing journeys open on Stages, new journeys on Details.
- Numbered stage cards: the whole card opens the editor, a ⋮ opens Edit / **Move up** / **Move down** / Delete, and a 44px drag handle. The non-drag option is required by WCAG 2.2 SC 2.5.7.
- Show icon + count badges for links, attachments and related fields, hiding zeros.
- Move "Delete journey" into the header ⋮ menu.

## 4. Stage editor — phone

![Stage editor on phone: current vs proposed](IMG_BASE/compare-04-stage-editor-phone.png)

**Current**
- The only exit is a small ✕. The "Back" link above it goes to the journeys list, not back to the journey.
- Opening the editor doesn't add a history entry, so the system back gesture leaves the page.
- Existing-journey edits auto-save, but nothing in the editor shows that. The checkmark appears next to the Stages heading, which is hidden while the editor is open.

**Proposed**
- Use `history.pushState`, or a `/stages/{id}` route, so Back closes the editor.
- Add a **‹ Stages** back button and a **Done** button.
- Show an inline "All changes saved" status.
- Add previous/next stage buttons for working through a journey.

## 5. Journey detail — tablet portrait

![Journey detail on tablet portrait: current vs proposed](IMG_BASE/compare-05-detail-tablet-portrait.png)

The fixed two-column grid gives about 190px per field inside a 427px panel, and the 299px Stages column wraps the counts onto three lines.

**Proposed:**
- Use the same tabs as on phone.
- Replace `grid-template-columns: repeat(2, 1fr)` with `repeat(auto-fit, minmax(280px, 1fr))`.
- Make Description, Instructions, Links and Roles span the full width (`grid-column: 1 / -1`).

## 6. Journey detail — tablet landscape

![Journey detail on tablet landscape: current vs proposed](IMG_BASE/compare-06-detail-tablet-landscape.png)

The side-by-side layout already works well here: the stage list stays visible while the editor slides in. Keep it. Add the larger targets, numbered and highlighted stages, and the **Saved** + **Done** editor header.

## 7. New journey — phone

![New journey on phone: current vs proposed](IMG_BASE/compare-07-new-journey-phone.png)

**Proposed**
- Use a sticky bottom bar with **Cancel** and **Save journey** instead of the split button. The current split button remembers its mode in `localStorage`, so its label and behaviour can change between visits without the user noticing.
- Add an unsaved-changes guard (`beforeunload` plus an in-app confirm). Stages on a new journey live only in `window.stages`, so Back or a nav tap currently discards them without warning.

## Touch-target summary

| Control | Phone | Tablet | Target |
|---|---|---|---|
| List row actions | 32×34 | 30–40×**18** | 44×44 |
| New Journey | 100×28 | 100×28 | ≥44 tall |
| "+N more" | — | 50×20 | ≥44 tall / whole cell |
| Back link | 60×19 | 60×19 | 44×44 |
| Add Stage | 85×30 | 85×30 | ≥44 tall |
| Stage edit/delete | 45×20 | 45×20 | 44×44 / whole card |
| Drag handle | 23×23 | 23×23 | 44×44 |
| Save-mode toggle (effective) | **13.5×16** | 13.5×16 | 44×44 |

## Suggested order

- [ ] Fix the split-button toggle (`currentTarget`, larger target, unique IDs)
- [ ] Enlarge touch targets; replace per-row icon clusters with a single ⋮
- [ ] Make the stage editor work with Back; add **Done** + inline save status; add an unsaved-changes guard on new journeys
- [ ] Add Details / Stages tabs on phone and tablet portrait; move Delete Journey to the header menu
- [ ] Switch the list to cards on phone, with a Filter & sort sheet
- [ ] Add non-drag reordering (Move up / down) and numbered stages
- [ ] Polish: `auto-fit` field grid, stage count badges, `Intl.RelativeTimeFormat`, translatable strings

**Out of scope / upstream:** on tablet portrait, the theme's top nav wraps the settings icon onto a second row (disciple-tools-theme). Role chip sizing belongs in disciple-tools-web-components.
