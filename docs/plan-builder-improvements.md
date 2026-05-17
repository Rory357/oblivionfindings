# Site Plan Builder — Gaps & Improvement Plan

_Status: planning_
_Owner: Sites module_
_Touches: `resources/js/pages/sites/plan/*`, `app/Http/Controllers/Sites/SiteTypePlan*`, `app/Services/Sites/SiteTypePlanService.php`, `app/Models/SiteTypePlan*`_

The current builder (added in commit `1720a582`) is a **click-to-place v0**: it puts a fixed-size shape or pin wherever you click and never lets you touch it again. This document captures what is missing, answers the room-naming question, and lays out a phased plan to make the builder genuinely usable.

---

## 1. What the builder is today

- **Dialog:** [resources/js/pages/sites/plan/_builder-dialog.tsx](resources/js/pages/sites/plan/_builder-dialog.tsx) — opens from `/sites/{site}/plan`.
- **Canvas:** [resources/js/pages/sites/plan/_canvas.tsx](resources/js/pages/sites/plan/_canvas.tsx) wraps `PlanThumbnail`, a static SVG renderer in [_thumbnail.tsx](resources/js/pages/sites/plan/_thumbnail.tsx). The "canvas" is the same SVG used for read-only thumbnails — there are no event handlers beyond `onClick`.
- **Tool palette:** [_tool-palette.tsx](resources/js/pages/sites/plan/_tool-palette.tsx) — 10 fixed tools (room, wall, door, window, label, medication, assembly, exit, fire, device).
- **Inspector:** [_inspector.tsx](resources/js/pages/sites/plan/_inspector.tsx) — read-only list, plus a trash button on each pin.
- **Persistence:** `SiteTypePlan.layout` (JSON: `rooms`, `walls`, `doors`, `windows`, `labels`) + `SiteTypePlanPin` rows (everything else). Coordinates are normalised `0..1` against a 1000×700 virtual canvas.
- **Pin kinds** (16, see `SiteTypePlanPin::KINDS`): `device`, `medication_storage`, `emergency_exit`, `evacuation_route`, `assembly_point`, `you_are_here`, `fire_extinguisher`, `fire_blanket`, `first_aid_kit`, `smoke_alarm`, `defibrillator`, `gas_shutoff`, `water_shutoff`, `electrical_panel`, `custom_marker`.

The schema is reasonable; the **UI doesn't use most of it**.

---

## 2. Gaps mapped to your feedback

### 2.1 "Cannot move or resize rooms"
- `_builder-dialog.tsx:87–98` writes rooms with hardcoded `width: 0.18, height: 0.14`. After placement there is no pointer-down handler, no selection state, no resize handle.
- `_thumbnail.tsx:99–106` renders rooms as a plain `<rect>` inside a click-through `<g>`; clicks bubble to the SVG `onCanvasClick`, which simply places **another** item.
- **Missing:** selection state on the canvas, drag handler with pointer capture, eight resize handles per room, snap-to-grid (the `grid.snap` flag exists at [_thumbnail.tsx:64](resources/js/pages/sites/plan/_thumbnail.tsx) but nothing reads it).

### 2.2 "Cannot pull a long line"
- `_builder-dialog.tsx:101–111` writes a wall with a fixed `±0.08` span around the click point — every wall is the same short stub.
- `PlanLayout.walls[].points` already supports an arbitrary polyline ([_thumbnail.tsx:19](resources/js/pages/sites/plan/_thumbnail.tsx)), so the **data model is fine** — the UI needs a two-click / click-drag drawing mode.
- **Missing:** drawing mode with a "first point set, awaiting second point" intermediate state, optional 15°/45°/90° angle constraint while holding Shift, multi-segment walls (click to add vertex, double-click / Esc to finish).

### 2.3 "Cannot move the items placed on the plan"
- Same root cause as 2.1 — no pointer handlers on placed elements. Pins, doors, windows, labels, walls, rooms are all immobile once placed.

### 2.4 "Devices assigned from assets are not pulling in"
- The dialog never fetches devices. There is no prop, no `useEffect`, no API call.
- The backend supports it: `SiteTypePlanPin` has a `device_id` FK to `devices` and a validation rule `exists:devices,id` at [SiteTypePlanPinController.php:65](app/Http/Controllers/Sites/SiteTypePlanPinController.php). The polymorphic `DeviceAssignment` table maps devices to sites via `assignable_type='site'`.
- Clicking the **Device** tool produces a pin with no `device_id` set — so the pin is rendered but is never tied to a real device. That's why "devices assigned from assets" don't appear.
- **Missing:** site-scoped device list passed into the dialog as a prop, a device picker (combobox) shown after selecting the Device tool, and a render path on the canvas that uses the device's actual name/category.

### 2.5 "Rooms should show actual rooms or communal spaces"
- The builder writes `layout.rooms[]` with labels `Room 1`, `Room 2` (incrementing counter at [_builder-dialog.tsx:93](resources/js/pages/sites/plan/_builder-dialog.tsx)).
- Actual rooms live in three tables:
  - `site_house_rooms` — resident bedrooms (with `assigned_client_id`, `is_active`, `is_assignable`).
  - `site_ho_resources` — head-office communal spaces (boardroom, training room, meeting room).
  - `site_facility_zones` — facility zones.
- These are unified by `SiteRoom` (polymorphic) and surfaced under `/sites/{site}/{rooms|resources|zones}`.
- The schema even anticipates this: `site_type_plan_pins.room_ref_type` and `room_ref_id` columns exist for polymorphic room refs — unused today.
- **Missing:** an inventory prop on the dialog, a room picker on the Room tool, and an extension of `layout.rooms[]` to carry `room_ref_type` + `room_ref_id`. Free-form rooms should still be allowed for plans that haven't built the inventory yet.

### 2.6 "All types of fire, all types of devices"
- The tool palette has one `fire` button — really `fire_extinguisher`. The pin model already supports more kinds (`fire_blanket`, `smoke_alarm`, `defibrillator`, etc.) and has a `subkind` text column (max 64 chars) that's never written by the UI.
- For **fire**, we need a tiered picker: kind → subkind. e.g.:
  - `fire_extinguisher` × `dry_powder | co2 | foam | water | wet_chemical | class_d` (NZS 4503 classes A/B/C/D/E/F)
  - `fire_blanket`
  - `smoke_alarm` × `photoelectric | ionisation | dual_sensor | heat_detector`
  - `sprinkler_head`, `fire_hose_reel`, `fire_panel`, `manual_call_point`, `fire_door`, `hydrant` (new kinds — schema migration)
- For **devices**, the existing `Device` model carries `category` and `subcategory` plus `name`, `manufacturer`, `model`. The picker should let staff pick from devices already assigned to the site and pin the chosen one — no need to enumerate "types" in the palette.

### 2.7 "When renaming a room in builder it actually changes the room's name — does this make sense?"
**Short answer: today it doesn't, and that's part of the confusion.**

- Current code: typing in the room field updates `layout.rooms[].label` (a JSON string). Nothing touches `site_house_rooms.name`.
- But the **label looks like an authoritative room name**, which is exactly what's confusing — the builder presents a "Rooms" list that looks like a registry, yet it lives only in the plan JSON, divorced from the real rooms tab.
- Two coherent options (recommendation in §4):
  - **(A) Link mode (recommended).** Drag a room shape, then assign it to an existing `SiteHouseRoom` / `SiteHoResource` / `SiteFacilityZone`. Label is read-only and reflects the source room's name. Renaming happens in `/sites/{id}/rooms` and propagates everywhere.
  - **(B) Detached mode.** Keep the label free-form on the plan and treat the plan room as decorative. Rename the column header in the builder from "Rooms" to "Room labels" or "Zones" to make it clear it isn't the registry.
- The right answer is (A) with (B) as a fallback for plans where no `SiteRoom` exists yet. Both can coexist via the `room_ref_*` columns already in the schema.

---

## 3. Other gaps worth fixing while we're here

- **No undo redo asymmetry.** `Undo` exists but no redo, and the history stack drops the future on every edit. Cheap to fix when we touch the dialog.
- **`grid.snap` ignored.** Persisted but never applied to placed coordinates.
- **No pan / zoom.** A 1000×700 canvas at one fixed zoom is fine for thumbnails but cramped when placing 20+ pins on a real site. Add wheel-zoom and space-drag pan.
- **No keyboard.** No tool shortcuts (R/W/D/L/Esc/Del), no Ctrl+Z.
- **No layer toggles.** Emergency Plan readers can't toggle the medication or device layers off.
- **No rotation handle** despite `rotation_deg` existing.
- **`path_points` unused.** Evacuation routes are persisted as point pins instead of polylines, even though the column is JSON and ready.
- **Empty error states.** If the backend rejects a pin (e.g., `device_id` validation fails) the dialog only toasts a generic message — users have no way to see which pin failed.

---

## 4. Improvement plan — phased

Each phase is independently shippable. Total surface area is large; splitting keeps PRs reviewable.

### Phase 1 — Selection, move, resize, real wall drawing (UI only, no schema)

**Goal:** make the builder feel like a builder.

- Switch from `onClick={addAt}` to a pointer-event state machine on `_canvas.tsx`:
  - `idle` — clicks place a new item if a tool is active; clicks on existing items select them.
  - `dragging` — pointer captured, item moves with cursor, snap to grid if `layout.grid.snap`.
  - `resizing` — corner/edge handle drag, with min-size clamp.
  - `drawing-line` — for the wall tool: click sets first point, second click finalises; Shift constrains to 0/45/90°; Esc cancels.
- Move shape state into a single store (`useReducer`) — current `pushHistory(layout, pins)` works but quickly becomes awkward when individual fields change. Each pointer-move shouldn't push a history entry; commit on pointer-up only.
- Add full undo + redo. Cap history at 50.
- Render selection ring + handles in `_thumbnail.tsx` behind a `selectable` / `selectedId` prop. The thumbnail used on the index page passes neither — it stays read-only.
- Keyboard: `Esc` deselect / cancel draw, `Delete` remove selection, `R/W/D/L/M/F/A/X` activate tools, `Ctrl+Z` / `Ctrl+Shift+Z`.

**Files:**
- `resources/js/pages/sites/plan/_canvas.tsx` — rewrite as the interactive surface.
- `resources/js/pages/sites/plan/_thumbnail.tsx` — split into `<PlanScene>` (pure render, used here and for the read-only thumbnail) + selection chrome.
- `resources/js/pages/sites/plan/_builder-dialog.tsx` — switch from `useState` to `useReducer` for `{layout, pins, selectedId}`; add redo.
- New: `resources/js/pages/sites/plan/_use-plan-editor.ts` — the reducer + pointer state machine.

### Phase 2 — Real rooms & devices wired into the builder

**Goal:** stop fabricating "Room 1" — pick real rooms and real devices.

Backend:
- `SiteTypePlanService::summaryFor()` ([app/Services/Sites/SiteTypePlanService.php:162](app/Services/Sites/SiteTypePlanService.php)) returns a new `inventory` block:
  ```php
  'inventory' => [
      'rooms' => $this->siteRooms($site),   // [{id, name, type: house_room|ho_resource|facility_zone, is_assigned, sort_order}]
      'devices' => $this->siteDevices($site), // [{id, name, category, subcategory, status, room_id}]
  ]
  ```
- `siteRooms()` reads `SiteRoom` (polymorphic) for the site; `siteDevices()` reads `DeviceAssignment` where `assignable_type='site' AND assignable_id=$site->id AND released_at IS NULL`. Both scoped by tenant.
- Extend `validatePins()` to accept already-validated `room_ref_type` values (`'house_room' | 'ho_resource' | 'facility_zone'`) instead of free text.

Frontend:
- Pass the new `inventory` block down to `_builder-dialog.tsx` via `typePlan.inventory`.
- Room tool: after click, open a small popover — "Use existing room" (combobox of `inventory.rooms` filtered to unplaced) vs "Free-form label". On commit, write `layout.rooms[].room_ref_type` + `room_ref_id` (extend type in `_thumbnail.tsx:5–13`).
- Device tool: same flow against `inventory.devices`. Pin writes `device_id` + `kind: 'device'`.
- Inspector room rows show the linked name read-only, with a "Renames live in /sites/{id}/rooms" caption and a link.
- If a layout has rooms with `room_ref_id` that no longer exist (room deleted later), surface an "unlinked" badge.

Layout type extension:
```ts
type PlanRoom = {
  id: string;
  shape?: 'rect' | 'polygon';
  x: number; y: number; width: number; height: number;
  // new:
  room_ref_type?: 'house_room' | 'ho_resource' | 'facility_zone' | null;
  room_ref_id?: number | null;
  label?: string | null; // only used when room_ref_id is null
};
```

This is a **non-breaking JSON addition** — existing plans keep their `label`-only rooms and render fine.

### Phase 3 — Expand the catalogue (fire types, life-safety items, utilities)

**Goal:** cover the items NZ Supported Living sites actually need to mark for audit (NZS 4503, Fire and Emergency NZ, HealthCERT).

Backend (one migration, one model change):
- Add new pin kinds: `sprinkler_head`, `fire_hose_reel`, `fire_panel`, `manual_call_point`, `fire_door`, `hydrant`, `evacuation_diagram`. Update `SiteTypePlanPin::KINDS` and `EMERGENCY_KINDS`.
- Document permitted `subkind` values per kind in a single source — recommend a `SiteTypePlanPin::SUBKINDS` array keyed by kind, validated server-side.

Suggested taxonomy:

| Kind | Subkinds |
|---|---|
| `fire_extinguisher` | `dry_powder`, `co2`, `foam`, `water`, `wet_chemical`, `class_d` |
| `fire_blanket` | _(none)_ |
| `smoke_alarm` | `photoelectric`, `ionisation`, `dual_sensor`, `heat_detector` |
| `sprinkler_head` | `pendant`, `upright`, `sidewall`, `concealed` |
| `fire_hose_reel` | _(none)_ |
| `fire_panel` | `conventional`, `addressable` |
| `manual_call_point` | _(none)_ |
| `fire_door` | `fd30`, `fd60`, `fd90`, `fd120` |
| `hydrant` | `wall`, `pillar`, `underground` |
| `defibrillator` | `aed_semi_auto`, `aed_fully_auto` |
| `first_aid_kit` | `workplace`, `vehicle`, `outdoor` |

Frontend:
- Restructure the tool palette into **grouped categories** (Structure / Emergency / Life-safety / Utilities / Devices / Annotation). Today's flat 10-button grid doesn't scale to ~25 items.
- For tools with subkinds, the click flow is: pick tool → click canvas → small inline popover to pick subkind → pin commits with `subkind` set. Pin label defaults to a human-readable string ("Fire extinguisher — CO₂").
- Use the existing "Send Kudos-style type tile picker" pattern (per project UI patterns memory) for the grouped tool palette.

### Phase 4 — Polylines, areas, rotation, layer toggles

**Goal:** the things `path_points`, `width`, `height`, `rotation_deg` were always meant for.

- **Evacuation routes** become polylines (use the existing `path_points` JSON column). Click to add vertex, double-click to finish.
- **Areas** (e.g., assembly point footprint, no-go zone): rooms-without-label drawn with `width`/`height`; allow polygon for non-rectangular areas via a future `shape: 'polygon'` with `points[]`.
- **Rotation**: handle on selected pin, snaps to 15°.
- **Layer visibility toggles** in the inspector: Structure / Emergency / Devices / Medication / Annotations. Persisted in `localStorage` per user.

### Phase 5 — Pan, zoom, snap, polish

- Wheel-zoom (Ctrl/Cmd + wheel zooms, plain wheel scrolls), space-drag pan.
- Apply `grid.snap` to pointer-up coordinates.
- Rulers + measurement readout (X/Y of selection, W×H of room).
- Inline rename on a room rectangle (double-click the label) — only allowed for free-form rooms; linked rooms show a tooltip directing to inventory.
- Per-pin error toast surfaces the failing pin index/label.
- Auto-save draft every N seconds when dirty.

---

## 5. Decisions needed before Phase 2 starts

1. **Room linking: hard requirement or soft?**
   - Recommend soft: builder allows both linked and free-form rooms. Linked rooms surface the canonical name; free-form is for plans drawn before the inventory is filled.
2. **Renaming a linked room from inside the builder — block or allow?**
   - Recommend block, with a link to `/sites/{id}/rooms`. Otherwise the builder becomes a second registry and divergence is inevitable.
3. **Devices not yet assigned to the site — surface them or hide them?**
   - Recommend hide. Pinning a device that isn't assigned to the site is almost always a mistake. Offer "Assign a device" → deep-link to the assignment screen.
4. **Subkind taxonomy — codify in PHP or in a config file?**
   - Recommend `config/site_plan_taxonomy.php`. Easier to add an item without a migration; frontend reads the same source via an Inertia share.
5. **Pin schema migration vs. JSON `meta`?**
   - For new kinds we'll need `KINDS` updated. For subkind, the column already exists. No data migration required for either.

---

## 6. Suggested PR sequence

1. **PR 1** (Phase 1) — Interactive canvas + selection + drag + resize + real wall drawing + undo/redo. UI-only, no API changes. **Biggest UX win per LOC.**
2. **PR 2** (Phase 2 backend) — `inventory` payload in `summaryFor()`, validation accepts `room_ref_type` enum, `siteRooms()` / `siteDevices()` helpers.
3. **PR 3** (Phase 2 frontend) — Room & device pickers, layout type extension, inspector updates.
4. **PR 4** (Phase 3) — Fire/life-safety/utility kinds, subkind taxonomy, grouped tool palette.
5. **PR 5** (Phase 4) — Evacuation polylines, rotation, layer toggles.
6. **PR 6** (Phase 5) — Pan/zoom/snap/polish.

Each PR should have:
- A migration test if applicable (Phase 3 only).
- A controller test covering validation of the new fields.
- A frontend smoke test (Playwright / Dusk) for the interaction added in that phase — drag a room, draw a wall, pin a device.

---

## 7. Out of scope (for now)

- Importing a floorplan image / PDF as a tracing background. Useful but its own project.
- Multi-floor / multi-building plans. Schema supports it via separate `SiteTypePlan` rows per `site_type`, but no UI today.
- Real-time collaborative editing. Single-editor with optimistic concurrency (`version` column on `SiteTypePlan`) is enough.
- Print/PDF redesign — the existing `SiteEmergencyPlanController::download()` keeps working off published pins.

---

## 8. Quick reference

| What | Where |
|---|---|
| Builder dialog | [resources/js/pages/sites/plan/_builder-dialog.tsx](resources/js/pages/sites/plan/_builder-dialog.tsx) |
| Canvas wrapper | [resources/js/pages/sites/plan/_canvas.tsx](resources/js/pages/sites/plan/_canvas.tsx) |
| SVG renderer + types | [resources/js/pages/sites/plan/_thumbnail.tsx](resources/js/pages/sites/plan/_thumbnail.tsx) |
| Tool palette | [resources/js/pages/sites/plan/_tool-palette.tsx](resources/js/pages/sites/plan/_tool-palette.tsx) |
| Inspector | [resources/js/pages/sites/plan/_inspector.tsx](resources/js/pages/sites/plan/_inspector.tsx) |
| Plan page | [resources/js/pages/sites/plan/index.tsx](resources/js/pages/sites/plan/index.tsx) |
| Plan controller | [app/Http/Controllers/Sites/SiteTypePlanController.php](app/Http/Controllers/Sites/SiteTypePlanController.php) |
| Pin controller (validation, kinds) | [app/Http/Controllers/Sites/SiteTypePlanPinController.php](app/Http/Controllers/Sites/SiteTypePlanPinController.php) |
| Plan service (summary, inventory hook) | [app/Services/Sites/SiteTypePlanService.php](app/Services/Sites/SiteTypePlanService.php) |
| Pin model + kinds | [app/Models/SiteTypePlanPin.php](app/Models/SiteTypePlanPin.php) |
| Plan model | [app/Models/SiteTypePlan.php](app/Models/SiteTypePlan.php) |
| Site rooms (polymorphic) | `app/Models/SiteRoom.php`, `SiteHouseRoom.php`, `SiteHoResource.php`, `SiteFacilityZone.php` |
| Device + assignment | `app/Domain/SecurityDevices/Models/Device.php`, `DeviceAssignment.php` |
