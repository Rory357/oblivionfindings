# Site Plan Builder - v2 Production Readiness Plan

_Status: planning - implementation-ready for Codex_
_Owner: Sites module_
_Reference: extends [docs/plan-builder-improvements.md](plan-builder-improvements.md), which is the shipped v1 builder plan._

> **For agentic workers:** implement this plan task-by-task. Keep each phase small enough to review, use checkbox tracking, and do not rewrite v1 unless a task below explicitly says to replace a piece.

**Goal:** make the Site Plan Builder predictable, complete enough for production site safety workflows, and able to complete emergency-plan pins from the Emergency Plan tab without forking the data model.

**Architecture:** keep one source of truth: `SiteTypePlan.layout` for structural shapes and `SiteTypePlanPin` rows for pins. The main plan builder and emergency-plan workflow reuse the same editor components, but emergency mode is scoped so it can only edit emergency/fire/life-safety pins and cannot accidentally alter structural or unrelated pin data.

**Tech stack:** Laravel, Inertia, React, TypeScript, Radix/shadcn components, Lucide icons, Pest feature tests, Playwright e2e tests.

---

## 0. Audit Verdict

The original v2 draft was directionally good, but it needed several production-readiness corrections before implementation:

- **Emergency save scope was unsafe.** A full `POST /plan/pins` with `replace: true` and `mode=emergency` would either reject non-emergency pins or, worse, drop them when a draft is created from a published plan. Emergency mode must save only emergency pins while preserving all non-emergency pins.
- **Emergency mode should not create structure.** Allowing a Wall tool in the Emergency Plan tab makes the tab a second structural editor. The emergency tab should complete emergency/fire/life-safety pins against a read-only structural backdrop. If structure is missing, link to the full plan builder.
- **Select mode needs a real state model.** Adding a `__select` button without changing every `if (activeKind)` path would make the canvas try to place a pin named `__select`. The plan now calls out an explicit `isSelectMode()` helper.
- **Snap must be consistent.** The current worktree still defaults to grid size `20` in both PHP and TypeScript, and the SVG grid renders every `50` units regardless of snap size. The plan now updates defaults, seed layout, tests, and the visual grid together.
- **Server validation is too loose for production.** `subkind`, `device_id`, `path_points`, and emergency-mode pin replacement need server-side rules, not just client-side UI restraint.
- **Emergency rendering is hardcoded.** `resources/js/pages/sites/emergency-plan/index.tsx` and `resources/js/pages/sites/show.tsx` filter only a short hardcoded list of emergency pins. The plan now requires a shared allowed-kinds source from the backend/taxonomy.
- **Browser coverage is missing.** Existing coverage is mostly `tests/Feature/Sites/SiteTypePlanTest.php` and `tests/Feature/Sites/SiteEmergencyPlanTest.php`; there is no focused Playwright builder spec. The plan now adds one.

---

## 1. Current Repo Evidence

Use these live surfaces as the implementation baseline:

| Surface | Current evidence |
|---|---|
| Builder dialog | [resources/js/pages/sites/plan/_builder-dialog.tsx](../resources/js/pages/sites/plan/_builder-dialog.tsx) owns save/publish, keyboard shortcuts, calibration dialog, and passes state to canvas/palette/inspector. |
| Editor reducer | [resources/js/pages/sites/plan/_use-plan-editor.ts](../resources/js/pages/sites/plan/_use-plan-editor.ts) has selection, history, drawing modes, marquee, layers, and `update_pin` accepting `Partial<PlanPin>`. |
| Canvas | [resources/js/pages/sites/plan/_canvas.tsx](../resources/js/pages/sites/plan/_canvas.tsx) contains `snapToGrid`, wall/polyline/calibration previews, marquee, drag-to-move, resize handles, and pointer handlers. |
| Types | [resources/js/pages/sites/plan/_types.ts](../resources/js/pages/sites/plan/_types.ts) normalises layout with `grid.size = 20`. |
| Tool palette | [resources/js/pages/sites/plan/_tool-palette.tsx](../resources/js/pages/sites/plan/_tool-palette.tsx) is taxonomy-driven, but has no explicit Select tool or mode filter. |
| Inspector | [resources/js/pages/sites/plan/_inspector.tsx](../resources/js/pages/sites/plan/_inspector.tsx) can edit labels/subkinds/device links, but cannot change pin `kind`. |
| Backend plan service | [app/Services/Sites/SiteTypePlanService.php](../app/Services/Sites/SiteTypePlanService.php) normalises layout, clones/publishes drafts, surfaces inventory/taxonomy, and computes `hasEmergencyLayer()` from assembly point plus emergency exit. |
| Pin model | [app/Models/SiteTypePlanPin.php](../app/Models/SiteTypePlanPin.php) defines `KINDS` and `EMERGENCY_KINDS`; note `EMERGENCY_KINDS` includes fire/life-safety/custom markers, but not utilities, devices, or medication storage. |
| Pin controller | [app/Http/Controllers/Sites/SiteTypePlanPinController.php](../app/Http/Controllers/Sites/SiteTypePlanPinController.php) currently validates kind membership only and replaces all pins when `replace=true`. |
| Emergency controller | [app/Http/Controllers/Sites/SiteEmergencyPlanController.php](../app/Http/Controllers/Sites/SiteEmergencyPlanController.php) renders the standalone page without `typePlan`, and its `PUT /emergency-plan` path writes emergency pins directly on the published plan. |
| Emergency page | [resources/js/pages/sites/emergency-plan/index.tsx](../resources/js/pages/sites/emergency-plan/index.tsx) is read-only and filters a hardcoded subset of emergency pins. |
| Site show tab | [resources/js/pages/sites/show.tsx](../resources/js/pages/sites/show.tsx) already renders `SiteTypePlanBuilderDialog`, but has only `planBuilderOpen` and `planBuilderFocus`; it needs a builder mode state. |
| Tests | [tests/Feature/Sites/SiteTypePlanTest.php](../tests/Feature/Sites/SiteTypePlanTest.php) and [tests/Feature/Sites/SiteEmergencyPlanTest.php](../tests/Feature/Sites/SiteEmergencyPlanTest.php) cover core backend flows. No `tests/e2e/site-plan-builder.spec.ts` exists yet. |

---

## 2. Implementation Rules

1. **One source of truth.** Do not create a separate emergency-plan table or duplicate builder state.
2. **No second structural editor.** Emergency mode can edit emergency pins only. Structural rooms/walls/doors/windows/labels render as context and remain read-only.
3. **No full-pin data loss.** Any emergency-mode save must preserve every pin whose `kind` is not in `emergency_pin_kinds`.
4. **Server rules back up UI rules.** Client filtering is not enough; backend validation must reject invalid emergency-mode writes, invalid subkinds, and invalid device links.
5. **Keep v1 intact.** Selection, drag, resize, taxonomy, inventory links, undo/redo, and publish flow are reused.
6. **Prefer small helpers over scattered conditionals.** Add mode/kind helpers once and use them across palette, canvas, inspector, and tests.
7. **Do not add pan/zoom in this pass.** It is useful, but it is not required to close the four reported gaps.

---

## 3. Shared Concepts To Add First

These small concepts reduce risk across phases:

### Builder Mode

Add a shared type:

```ts
export type BuilderMode = 'full' | 'emergency';
export const SELECT_TOOL = '__select';
export function isSelectMode(kind: string | null): boolean {
    return kind === null || kind === SELECT_TOOL;
}
```

Preferred location: [resources/js/pages/sites/plan/_types.ts](../resources/js/pages/sites/plan/_types.ts). If import cycles appear, place UI-only helpers in a new [resources/js/pages/sites/plan/_builder-mode.ts](../resources/js/pages/sites/plan/_builder-mode.ts).

### Emergency Kind Source

Expose one backend-derived list instead of hardcoding emergency filters in several React files:

- Add `emergency_pin_kinds` to `SiteTypePlanService::summaryFor()`.
- Value should come from `SiteTypePlanPin::EMERGENCY_KINDS`.
- Frontend helper:

```ts
export function isEmergencyPlanKind(kind: string, emergencyKinds: string[]): boolean {
    return emergencyKinds.includes(kind);
}
```

Use it in `show.tsx`, `emergency-plan/index.tsx`, `_builder-dialog.tsx`, `_canvas.tsx`, and `_inspector.tsx`.

### Test Selectors

Add stable `data-test` selectors only where Playwright needs them:

- `site-plan-builder-dialog`
- `site-plan-canvas`
- `site-plan-select-tool`
- `site-plan-wall-tool`
- `site-plan-marquee-count`
- `site-plan-pin-kind-picker`
- `site-plan-emergency-checklist`
- `site-plan-emergency-mode-badge`

Do not add broad test-only props through the app.

---

## 4. Phase A - Snap Precision And Line Placement

**Goal:** the user can see exactly where a line will start, bypass snap temporarily, and understand the snap offset.

**Files:**

- Modify: [app/Services/Sites/SiteTypePlanService.php](../app/Services/Sites/SiteTypePlanService.php)
- Modify: [resources/js/pages/sites/plan/_types.ts](../resources/js/pages/sites/plan/_types.ts)
- Modify: [resources/js/pages/sites/plan/_use-plan-editor.ts](../resources/js/pages/sites/plan/_use-plan-editor.ts)
- Modify: [resources/js/pages/sites/plan/_canvas.tsx](../resources/js/pages/sites/plan/_canvas.tsx)
- Modify: [resources/js/pages/sites/plan/_inspector.tsx](../resources/js/pages/sites/plan/_inspector.tsx)
- Modify tests: [tests/Feature/Sites/SiteTypePlanTest.php](../tests/Feature/Sites/SiteTypePlanTest.php)
- Add/update e2e: [tests/e2e/site-plan-builder.spec.ts](../tests/e2e/site-plan-builder.spec.ts)

### Tasks

- [ ] Change default grid size from `20` to `10` in `SiteTypePlanService::normaliseLayout()`.
- [ ] Change `SiteTypePlanService::seedDefaultLayout()` so newly seeded plans explicitly use grid size `10`.
- [ ] Change `normaliseLayout()` in `_types.ts` to default `grid.size` to `10`.
- [ ] Update `sampleSitePlanLayout()` in `SiteTypePlanTest.php` only if assertions or fixtures depend on the old grid size.
- [ ] Add reducer action `{ type: 'set_grid_snap'; snap: boolean }` that updates `state.layout.grid.snap`.
- [ ] Add raw cursor support to interactions:
  - `drawing_wall`: `cursor` is snapped, `rawCursor` is unsnapped.
  - `drawing_polyline`: `cursor` is snapped, `rawCursor` is unsnapped.
  - `calibrating`: `secondPoint` is snapped, `rawSecondPoint` is unsnapped.
- [ ] Replace `snapToGrid(point, layout, canvas)` with a helper that accepts a boolean, for example `snapToGrid(point, layout, canvas, shouldSnap)`.
- [ ] Track `Alt` key state in `_canvas.tsx`; snap is active only when `layout.grid.snap !== false && !altHeld`.
- [ ] Apply the same snap decision to placement, wall drawing, calibration, endpoint dragging, group dragging, room resize, and polyline drawing.
- [ ] Render the SVG grid using `layout.grid.size` instead of a hardcoded `50`, so visual grid and snap target match.
- [ ] In wall/polyline/calibration preview, render:
  - a small grey crosshair at `rawCursor` or `rawSecondPoint`,
  - a blue dot at the snapped target,
  - a thin connector between them only when distance is visible.
- [ ] Add a "Snap to grid" `Switch` in the Inspector Scale card with helper text: `Hold Alt to place without snap.`
- [ ] Keep existing saved plans with `grid.size = 20` unchanged; only new/default layouts use `10`.

### Acceptance

- [ ] New plans default to `grid.size = 10` in both PHP and frontend normalisation.
- [ ] Existing saved plans with `grid.size = 20` still render and snap at `20`.
- [ ] Wall, scale, and evacuation-route previews show raw cursor plus snapped target while snap is active.
- [ ] Holding Alt bypasses snap for placement and drag operations without changing the saved snap setting.
- [ ] The inspector snap switch persists into `layout.grid.snap` and affects all placement paths.
- [ ] The visual grid spacing matches the active grid size.

### Verification

```powershell
npm run types
npm run build
php artisan test tests/Feature/Sites/SiteTypePlanTest.php
npx playwright test tests/e2e/site-plan-builder.spec.ts --project=chromium-desktop
```

---

## 5. Phase B - Explicit Select And Multi-Select UX

**Goal:** users can discover multi-select, see what the marquee will select before release, and understand group movement.

**Files:**

- Modify: [resources/js/pages/sites/plan/_types.ts](../resources/js/pages/sites/plan/_types.ts) or new `_builder-mode.ts`
- Modify: [resources/js/pages/sites/plan/_tool-palette.tsx](../resources/js/pages/sites/plan/_tool-palette.tsx)
- Modify: [resources/js/pages/sites/plan/_use-plan-editor.ts](../resources/js/pages/sites/plan/_use-plan-editor.ts)
- Modify: [resources/js/pages/sites/plan/_canvas.tsx](../resources/js/pages/sites/plan/_canvas.tsx)
- Modify: [resources/js/pages/sites/plan/_builder-dialog.tsx](../resources/js/pages/sites/plan/_builder-dialog.tsx)
- Modify: [resources/js/pages/sites/plan/_inspector.tsx](../resources/js/pages/sites/plan/_inspector.tsx)
- Add/update e2e: [tests/e2e/site-plan-builder.spec.ts](../tests/e2e/site-plan-builder.spec.ts)

### Tasks

- [ ] Add a client-only Select tool with value `__select`. Do not add it to `SiteTypePlanPin::KINDS`.
- [ ] Add a small "Selection" group above taxonomy groups in `_tool-palette.tsx` with a `MousePointer2` icon and `data-test="site-plan-select-tool"`.
- [ ] Default the reducer's `activeKind` to `__select`.
- [ ] When the dialog opens with no `focusTool`, set the tool to `__select`.
- [ ] Update Escape handling:
  - editing active: end editing,
  - interaction active: cancel interaction,
  - selection active: clear selection,
  - otherwise keep Select active instead of setting `activeKind` to `null`.
- [ ] Replace all `if (activeKind)` placement checks in `_canvas.tsx` with `if (!isSelectMode(activeKind))`.
- [ ] In select mode, background pointer-down starts marquee; background click clears selection.
- [ ] In select mode, hover cursor should communicate action:
  - empty canvas: `cursor-crosshair`,
  - item: `cursor-move`,
  - already selected item: `cursor-grab`.
- [ ] During marquee drag, compute pending refs on every cursor update with the same centre-point logic used on release.
- [ ] Render pending marquee refs with a dashed blue outline before pointer-up.
- [ ] Render a floating chip when `selection.length > 1`: `<count> items selected - drag any selected item to move all - Delete removes`.
- [ ] Track active group dragging with state, not only `dragRef`, so the dashed group bounding box reliably appears and disappears.
- [ ] Render a dashed bounding rectangle around the selected group while group dragging.

### Acceptance

- [ ] Opening the builder shows Select active.
- [ ] Clicking Select does not place a `__select` pin.
- [ ] Empty-canvas drag starts a marquee.
- [ ] Items inside the marquee highlight while dragging, before pointer-up.
- [ ] Releasing the marquee selects the highlighted items and shows the count chip.
- [ ] Dragging any selected item moves the whole selection and shows a dashed group bounds preview.
- [ ] Escape clears selection but leaves the builder in Select mode.

### Verification

```powershell
npm run types
npm run build
npx playwright test tests/e2e/site-plan-builder.spec.ts --project=chromium-desktop
```

---

## 6. Phase C - Change Pin Type After Placement

**Goal:** any placed pin can change kind/subkind without losing position, notes, non-default label, or valid links.

**Files:**

- Modify: [resources/js/pages/sites/plan/_tool-palette.tsx](../resources/js/pages/sites/plan/_tool-palette.tsx)
- Modify: [resources/js/pages/sites/plan/_inspector.tsx](../resources/js/pages/sites/plan/_inspector.tsx)
- Modify: [resources/js/pages/sites/plan/_canvas.tsx](../resources/js/pages/sites/plan/_canvas.tsx)
- Optional new component: [resources/js/pages/sites/plan/_pin-kind-picker.tsx](../resources/js/pages/sites/plan/_pin-kind-picker.tsx)
- Add/update e2e: [tests/e2e/site-plan-builder.spec.ts](../tests/e2e/site-plan-builder.spec.ts)

### Tasks

- [ ] Extract reusable grouped-kind rendering from the tool palette into a small component/helper, or add `_pin-kind-picker.tsx` if that keeps `_inspector.tsx` readable.
- [ ] In `SelectionDetails` for `single.type === 'pin'`, add a top "Kind" row above Label.
- [ ] The Kind row trigger shows the current kind's icon, label, and subkind if present.
- [ ] The picker groups kinds by taxonomy group and excludes shape tools (`__room`, `__wall`, `__door`, `__window`, `__label`, `__scale`, `__select`).
- [ ] On kind change:
  - dispatch `commit`,
  - update `kind`,
  - set `subkind: null`,
  - keep `x`, `y`, `notes`, `meta`, `rotation_deg`, `width`, `height`, and `path_points`,
  - preserve `label` unless it equals the previous kind's default label,
  - clear `device_id` when changing away from `device`.
- [ ] When changing to `device`, keep other fields and show the existing Device row with helper text: `Pick a site-assigned device below.`
- [ ] Make the subkind picker clearable through a visible "Clear type" action.
- [ ] Canvas icon/color should update immediately from taxonomy after the kind change.
- [ ] Do not implement right-click context menu in this phase unless the diff remains small; the inspector is the required path.

### Acceptance

- [ ] Select a pin and change it from `fire_extinguisher` to `smoke_alarm`; the icon, colour, label fallback, and subkind options update immediately.
- [ ] The pin keeps its position and notes.
- [ ] A device pin changed to a non-device kind clears `device_id`.
- [ ] A non-device pin changed to `device` shows the Device picker and does not invent a device link.
- [ ] Clearing subkind stores `subkind: null`.

### Verification

```powershell
npm run types
npm run build
npx playwright test tests/e2e/site-plan-builder.spec.ts --project=chromium-desktop
```

---

## 7. Phase D - Emergency Plan Workflow

**Goal:** users can complete emergency-plan pins from the Emergency Plan tab/page while preserving the normal plan model and draft/publish lifecycle.

**Files:**

- Modify: [app/Services/Sites/SiteTypePlanService.php](../app/Services/Sites/SiteTypePlanService.php)
- Modify: [app/Http/Controllers/Sites/SiteTypePlanPinController.php](../app/Http/Controllers/Sites/SiteTypePlanPinController.php)
- Modify: [app/Http/Controllers/Sites/SiteEmergencyPlanController.php](../app/Http/Controllers/Sites/SiteEmergencyPlanController.php)
- Modify: [resources/js/pages/sites/plan/_builder-dialog.tsx](../resources/js/pages/sites/plan/_builder-dialog.tsx)
- Modify: [resources/js/pages/sites/plan/_tool-palette.tsx](../resources/js/pages/sites/plan/_tool-palette.tsx)
- Modify: [resources/js/pages/sites/plan/_canvas.tsx](../resources/js/pages/sites/plan/_canvas.tsx)
- Modify: [resources/js/pages/sites/plan/_inspector.tsx](../resources/js/pages/sites/plan/_inspector.tsx)
- Add component: [resources/js/pages/sites/plan/_emergency-checklist.tsx](../resources/js/pages/sites/plan/_emergency-checklist.tsx)
- Modify: [resources/js/pages/sites/show.tsx](../resources/js/pages/sites/show.tsx)
- Modify: [resources/js/pages/sites/emergency-plan/index.tsx](../resources/js/pages/sites/emergency-plan/index.tsx)
- Modify: [resources/js/pages/sites/plan/index.tsx](../resources/js/pages/sites/plan/index.tsx)
- Modify tests: [tests/Feature/Sites/SiteTypePlanTest.php](../tests/Feature/Sites/SiteTypePlanTest.php)
- Modify tests: [tests/Feature/Sites/SiteEmergencyPlanTest.php](../tests/Feature/Sites/SiteEmergencyPlanTest.php)
- Add/update e2e: [tests/e2e/site-plan-builder.spec.ts](../tests/e2e/site-plan-builder.spec.ts)

### D.1 Builder Mode Props

- [ ] Add `mode?: BuilderMode` to `SiteTypePlanBuilderDialog`, defaulting to `'full'`.
- [ ] Thread `mode` to `ToolPalette`, `PlanCanvas`, and `PlanInspector`.
- [ ] Add `emergencyKinds` to dialog props from `typePlan.emergency_pin_kinds ?? []`.
- [ ] Title becomes `Edit emergency plan` in emergency mode.
- [ ] Show a small emergency-mode badge in the dialog header with `data-test="site-plan-emergency-mode-badge"`.

### D.2 Emergency Palette And Canvas Locking

- [ ] In emergency mode, the palette shows:
  - Select,
  - emergency group kinds,
  - fire group kinds,
  - `first_aid_kit`,
  - `defibrillator`,
  - `custom_marker`.
- [ ] In emergency mode, the palette does **not** show:
  - room/wall/door/window/label tools,
  - device,
  - medication storage,
  - utilities.
- [ ] In emergency mode, structural shapes render at reduced opacity and cannot be selected, moved, resized, edited, or deleted.
- [ ] In emergency mode, non-emergency pins are hidden by default. If product wants context later, add a read-only "Show other pins" toggle after the core workflow is green.
- [ ] Marquee selection in emergency mode only includes editable emergency pins.
- [ ] Delete in emergency mode only deletes editable emergency pins.

### D.3 Emergency Checklist

Add [resources/js/pages/sites/plan/_emergency-checklist.tsx](../resources/js/pages/sites/plan/_emergency-checklist.tsx).

The checklist reads current editor pins and `emergencyKinds`. It should render in the inspector instead of layer toggles when `mode === 'emergency'`.

Required rows:

| Requirement | Rule | Action |
|---|---|---|
| Assembly point | at least one `assembly_point` | activate `assembly_point` tool |
| Emergency exit | at least one `emergency_exit` | activate `emergency_exit` tool |

Recommended rows:

| Recommendation | Rule | Action |
|---|---|---|
| Secondary exit | two or more `emergency_exit` pins | activate `emergency_exit` tool |
| Evacuation route | at least one `evacuation_route` | activate `evacuation_route` tool |
| Fire extinguisher | at least one `fire_extinguisher` | activate `fire_extinguisher` tool |
| Smoke alarm | at least one `smoke_alarm` | activate `smoke_alarm` tool |
| First-aid kit | at least one `first_aid_kit` | activate `first_aid_kit` tool |
| You are here | at least one `you_are_here` | activate `you_are_here` tool |
| Defibrillator | at least one `defibrillator` | activate `defibrillator` tool |

Checklist actions call `dispatch({ type: 'set_tool', kind })`; the user then clicks the canvas to place the pin.

When hard requirements pass, show `Ready to publish`. The publish button remains the existing dialog footer Publish button; do not create a second publish path inside the checklist.

### D.4 Scoped Emergency Pin Save

This is the most important backend correction.

Current full builder save flow:

1. `POST /sites/{site}/plan/draft`
2. `POST /sites/{site}/plan/pins` with `replace: true` and all pins
3. optional `POST /sites/{site}/plan/publish`

Emergency mode must keep the same draft/publish lifecycle, but the pin write must be scoped:

- [ ] In `_builder-dialog.tsx`, when `mode === 'emergency'`, send only editable emergency pins:

```json
{
  "mode": "emergency",
  "replace": true,
  "pins": ["only emergency/fire/life-safety/custom emergency pins"]
}
```

- [ ] In `SiteTypePlanPinController::storeBatch()`, when `mode === 'emergency'`:
  - validate all submitted pins are in `SiteTypePlanPin::EMERGENCY_KINDS`,
  - ensure there is a draft before replacing pins,
  - if no draft exists and a published plan exists, call `SiteTypePlanService::cloneToDraft($site, $userId)` so non-emergency pins are preserved,
  - if no draft or published plan exists, create a seeded draft,
  - delete only existing pins where `kind` is in `EMERGENCY_KINDS`,
  - insert the submitted emergency pins,
  - return the full draft pin set and updated `typePlan`.
- [ ] In normal full mode, keep current `replace: true` behaviour for all pins.
- [ ] Refactor `PUT /sites/{site}/emergency-plan` to call the same scoped emergency-pin service on a draft. It must not mutate the published plan directly.

### D.5 Wire Emergency Tab And Page

- [ ] In `show.tsx`, add `const [planBuilderMode, setPlanBuilderMode] = useState<BuilderMode>('full')`.
- [ ] Full plan buttons set mode to `'full'`.
- [ ] Emergency tab "Edit emergency plan" button sets:
  - `planBuilderMode = 'emergency'`,
  - `planBuilderFocus = 'assembly_point'` when not ready,
  - `planBuilderOpen = true`.
- [ ] When dialog closes, reset focus and mode back to full.
- [ ] Replace hardcoded `publishedEmergencyPins` in `show.tsx` with `typePlanSummary.emergency_pin_kinds`.
- [ ] Extend `SiteTypePlanSummary` type in `show.tsx` to include `inventory`, `taxonomy`, and `emergency_pin_kinds`, matching the plan page type.
- [ ] In `SiteEmergencyPlanController::show()`, include `typePlan => $this->plans->summaryFor($site)` in Inertia props.
- [ ] In `emergency-plan/index.tsx`, add local builder dialog state and an "Edit emergency plan" button when `can.update` is true.
- [ ] In `emergency-plan/index.tsx`, pass taxonomy to `PlanThumbnail` so all fire/life-safety/custom icons render consistently.
- [ ] Replace hardcoded emergency filter in `emergency-plan/index.tsx` with `typePlan.emergency_pin_kinds`.

### Acceptance

- [ ] The Emergency Plan tab opens the builder in emergency mode.
- [ ] The standalone `/sites/{id}/emergency-plan` page opens the same emergency-mode builder.
- [ ] Emergency mode does not show structural tools.
- [ ] Existing rooms/walls/doors/windows/labels cannot be selected or moved in emergency mode.
- [ ] Saving emergency pins preserves medication/device/utility pins already on the draft or published source.
- [ ] If no draft exists, emergency save clones the published plan before replacing emergency pins.
- [ ] A malicious emergency-mode request containing `kind: device` returns 422 and does not alter existing pins.
- [ ] After adding assembly point and emergency exit, publishing flips the published emergency readiness from "Needs assembly point and exit" to "Ready to export".

### Verification

```powershell
npm run types
npm run build
php artisan test tests/Feature/Sites/SiteTypePlanTest.php tests/Feature/Sites/SiteEmergencyPlanTest.php
npx playwright test tests/e2e/site-plan-builder.spec.ts --project=chromium-desktop
npx playwright test tests/e2e/site-plan-builder.spec.ts --project=chromium-mobile
```

---

## 8. Phase E - Backend Validation And Error Surfacing

**Goal:** invalid builder data fails clearly and safely.

**Files:**

- Modify: [app/Http/Controllers/Sites/SiteTypePlanPinController.php](../app/Http/Controllers/Sites/SiteTypePlanPinController.php)
- Modify: [app/Services/Sites/SiteTypePlanService.php](../app/Services/Sites/SiteTypePlanService.php)
- Modify: [resources/js/pages/sites/plan/_builder-dialog.tsx](../resources/js/pages/sites/plan/_builder-dialog.tsx)
- Modify: [resources/js/pages/sites/plan/_canvas.tsx](../resources/js/pages/sites/plan/_canvas.tsx)
- Modify tests: [tests/Feature/Sites/SiteTypePlanTest.php](../tests/Feature/Sites/SiteTypePlanTest.php)
- Add/update e2e: [tests/e2e/site-plan-builder.spec.ts](../tests/e2e/site-plan-builder.spec.ts)

### Tasks

- [ ] Validate `subkind` against `config/site_plan_taxonomy.php` for the submitted `kind`. Null remains valid.
- [ ] Reject `subkind` when the selected kind has no subkind list.
- [ ] Validate `device_id` only when `kind === 'device'`.
- [ ] Reject `device_id` for non-device pins.
- [ ] For `kind === 'device'`, require the device to be assigned to the current site through `DeviceAssignment` with `assignable_type = site`, `assignable_id = $site->id`, and `released_at IS NULL`.
- [ ] Validate `path_points` as an array of points with numeric `x` and `y` between `0` and `1`.
- [ ] For `evacuation_route`, require at least two `path_points`.
- [ ] For non-polyline pins, allow `path_points` only when null/empty.
- [ ] Keep validation messages plain and field-specific enough for the frontend to map back to pins.
- [ ] In `_builder-dialog.tsx`, parse JSON 422 responses. For keys like `pins.3.subkind`, set an editor-level validation error for that pin instead of only showing raw JSON in a toast.
- [ ] In `_canvas.tsx`, render a red ring or warning badge on pins that have validation errors.
- [ ] In `_inspector.tsx`, show the selected pin's validation error above the edit rows.

### Acceptance

- [ ] `co2` is accepted for `fire_extinguisher`.
- [ ] `co2` is rejected for `smoke_alarm`.
- [ ] `device_id` on a non-device pin is rejected.
- [ ] A device not assigned to the site is rejected.
- [ ] An invalid pin response highlights the offending pin and shows a readable message.
- [ ] Existing valid plans still save and publish.

### Verification

```powershell
npm run types
npm run build
php artisan test tests/Feature/Sites/SiteTypePlanTest.php
npx playwright test tests/e2e/site-plan-builder.spec.ts --project=chromium-desktop
```

---

## 9. Phase F - Production Polish

**Goal:** small UX improvements that make the builder feel finished once P0/P1 safety work is green.

**Files:**

- Modify: [resources/js/pages/sites/plan/_canvas.tsx](../resources/js/pages/sites/plan/_canvas.tsx)
- Modify: [resources/js/pages/sites/plan/_tool-palette.tsx](../resources/js/pages/sites/plan/_tool-palette.tsx)
- Modify: [resources/js/pages/sites/plan/_use-plan-editor.ts](../resources/js/pages/sites/plan/_use-plan-editor.ts)

### Tasks

- [ ] Add hover ghost preview for the selected tool at the current snap target.
- [ ] Add keyboard shortcut chips and `title` attributes to tool buttons. Use existing shortcuts from `_builder-dialog.tsx`.
- [ ] Add reducer actions for z-order:
  - `{ type: 'bring_to_front'; ref: SelectionRef }`
  - `{ type: 'send_to_back'; ref: SelectionRef }`
- [ ] Add a small context menu only after the inspector path is stable:
  - Delete,
  - Change kind for pins,
  - Bring to front,
  - Send to back.
- [ ] Do not add pan/zoom, floorplan imports, multi-floor support, or real-time collaboration in this plan.

### Acceptance

- [ ] Hovering with a tool active shows a faded item preview at the target.
- [ ] Shortcut chips match actual keyboard behaviour.
- [ ] Bring-to-front/send-to-back update render order and persist through save/publish.
- [ ] Context menu actions duplicate existing safe actions; they do not introduce a second editing model.

---

## 10. Suggested PR Sequence

| PR | Phase | Scope | Risk |
|---|---|---|---|
| 1 | A | Snap precision, Alt bypass, raw/snap indicators, visual grid consistency. | Low |
| 2 | B | Explicit Select tool, live marquee highlight, group selection affordances. | Low |
| 3 | C + E partial | Pin kind picker plus server subkind/device validation. | Medium |
| 4 | D backend | Emergency kind source, scoped emergency pin replacement, controller tests. | Medium |
| 5 | D frontend | Emergency mode dialog, checklist, tab/page wiring, e2e coverage. | Medium |
| 6 | F | Ghost preview, shortcuts, z-order, optional context menu. | Low |

If implementation capacity is tight, ship PRs 1-5 first and defer PR 6.

---

## 11. Required Tests

### Feature Tests

Extend [tests/Feature/Sites/SiteTypePlanTest.php](../tests/Feature/Sites/SiteTypePlanTest.php):

- emergency-mode pin replacement preserves non-emergency pins,
- emergency-mode pin replacement clones published plan to draft before replacing emergency pins,
- emergency-mode write rejects non-emergency kinds,
- subkind validation accepts only taxonomy-allowed values,
- device pin validation requires a site-assigned device.

Extend [tests/Feature/Sites/SiteEmergencyPlanTest.php](../tests/Feature/Sites/SiteEmergencyPlanTest.php):

- emergency-plan page includes `typePlan`, `emergency_pin_kinds`, and `can.update`,
- emergency-plan page uses the same readiness rule as `SiteTypePlanService::hasEmergencyLayer()`,
- direct `PUT /emergency-plan` uses the scoped draft replacement path and does not mutate the published plan directly.

### Playwright Tests

Add [tests/e2e/site-plan-builder.spec.ts](../tests/e2e/site-plan-builder.spec.ts).

Use existing helpers from [tests/e2e/helpers.ts](../tests/e2e/helpers.ts):

- `loginAsStaff(page)`,
- `runLaravelPhp(code)`,
- `collectConsoleErrors(page)`,
- `expectNoConsoleErrors(errors)`.

Cover at least:

- open builder from `/sites/{id}`,
- Select tool is active by default,
- marquee selects multiple placed items and shows count,
- group drag moves selected items,
- wall placement shows snap target and Alt bypass,
- retype a pin from fire extinguisher to smoke alarm,
- open emergency builder from the Emergency Plan tab,
- emergency mode hides structural tools and locks structure,
- add assembly point and emergency exit, publish, and confirm readiness/export badge.

---

## 12. Verification Bundle

Run before each PR is considered done:

```powershell
npm run types
npm run build
php artisan test tests/Feature/Sites/SiteTypePlanTest.php tests/Feature/Sites/SiteEmergencyPlanTest.php
npx playwright test tests/e2e/site-plan-builder.spec.ts --project=chromium-desktop
```

Run before the final merge:

```powershell
npm run types
npm run build
php artisan test --filter=SiteTypePlan
php artisan test --filter=SiteEmergencyPlan
npx playwright test tests/e2e/site-plan-builder.spec.ts --project=chromium-desktop
npx playwright test tests/e2e/site-plan-builder.spec.ts --project=chromium-mobile
```

Manual browser verification should be against the correct worktree and host. Prefer `https://oblivionfindings.test` when Herd is serving the current checkout; otherwise use the Playwright server from `playwright.config.ts`. Do not claim browser verification from a `chrome-error://chromewebdata` page or a stale worktree.

---

## 13. Out Of Scope

- Pan/zoom.
- Floorplan image/PDF import.
- Multi-floor or multi-building plans.
- Replacing the plan data model.
- Real-time collaboration.
- A separate emergency-plan draft model.
- Rewriting the Sites show page or plan page shell.

If any of these become necessary, stop and update this plan before coding.

---

## 14. Quick Reference

| What | Where |
|---|---|
| Builder dialog | [resources/js/pages/sites/plan/_builder-dialog.tsx](../resources/js/pages/sites/plan/_builder-dialog.tsx) |
| Canvas | [resources/js/pages/sites/plan/_canvas.tsx](../resources/js/pages/sites/plan/_canvas.tsx) |
| Tool palette | [resources/js/pages/sites/plan/_tool-palette.tsx](../resources/js/pages/sites/plan/_tool-palette.tsx) |
| Inspector | [resources/js/pages/sites/plan/_inspector.tsx](../resources/js/pages/sites/plan/_inspector.tsx) |
| Editor reducer | [resources/js/pages/sites/plan/_use-plan-editor.ts](../resources/js/pages/sites/plan/_use-plan-editor.ts) |
| Types/helpers | [resources/js/pages/sites/plan/_types.ts](../resources/js/pages/sites/plan/_types.ts) |
| Read-only thumbnail | [resources/js/pages/sites/plan/_thumbnail.tsx](../resources/js/pages/sites/plan/_thumbnail.tsx) |
| Plan page | [resources/js/pages/sites/plan/index.tsx](../resources/js/pages/sites/plan/index.tsx) |
| Plan controller | [app/Http/Controllers/Sites/SiteTypePlanController.php](../app/Http/Controllers/Sites/SiteTypePlanController.php) |
| Pin controller | [app/Http/Controllers/Sites/SiteTypePlanPinController.php](../app/Http/Controllers/Sites/SiteTypePlanPinController.php) |
| Plan service | [app/Services/Sites/SiteTypePlanService.php](../app/Services/Sites/SiteTypePlanService.php) |
| Pin model | [app/Models/SiteTypePlanPin.php](../app/Models/SiteTypePlanPin.php) |
| Taxonomy config | [config/site_plan_taxonomy.php](../config/site_plan_taxonomy.php) |
| Emergency plan service | [app/Services/Sites/SiteEmergencyPlanService.php](../app/Services/Sites/SiteEmergencyPlanService.php) |
| Emergency plan controller | [app/Http/Controllers/Sites/SiteEmergencyPlanController.php](../app/Http/Controllers/Sites/SiteEmergencyPlanController.php) |
| Emergency plan page | [resources/js/pages/sites/emergency-plan/index.tsx](../resources/js/pages/sites/emergency-plan/index.tsx) |
| Site show page | [resources/js/pages/sites/show.tsx](../resources/js/pages/sites/show.tsx) |
| Feature tests | [tests/Feature/Sites/SiteTypePlanTest.php](../tests/Feature/Sites/SiteTypePlanTest.php), [tests/Feature/Sites/SiteEmergencyPlanTest.php](../tests/Feature/Sites/SiteEmergencyPlanTest.php) |
| E2E helpers | [tests/e2e/helpers.ts](../tests/e2e/helpers.ts) |
