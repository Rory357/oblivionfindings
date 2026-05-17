# Site Plan Builder — Architectural Door Icons

_Status: planning — implementation-ready for a fresh agent_
_Owner: Sites module_
_Reference: extends the shipped plan builder (see [plan-builder-improvements.md](plan-builder-improvements.md) and [plan-builder-improvements-v2.md](plan-builder-improvements-v2.md))_

> **For a fresh-context implementor:** the rest of the plan builder works. Doors are the only weak spot — right now they render as a flat 10-unit brown rectangle with no swing arc, no hinge indication, and no leaf. This plan replaces that placeholder with real architectural door symbols (single swing, double swing, sliding, pocket, bifold, folding, garage). Everything else in the builder stays as-is.

---

## 1. Problem

A house plan needs door symbols an electrician, builder, or fire inspector can read at a glance. The current implementation:

- Renders every door as a `<rect width={w * canvasWidth} height={10} fill="#92400e"/>` ([_canvas.tsx](../resources/js/pages/sites/plan/_canvas.tsx), in the "Doors" block).
- Ignores the existing `swing` field on `PlanDoor` ([_types.ts](../resources/js/pages/sites/plan/_types.ts)) — it's set to `'right'` on creation and never read.
- Does **not** render at all in the published PDF / SVG export (`SiteTypePlanService::renderLayoutSvg()` in [SiteTypePlanService.php](../app/Services/Sites/SiteTypePlanService.php) walks `walls` and `rooms` only — see line ~270 onward — doors are silently dropped).

Reference symbols (provided by the user, attached image — single & double hinged doors with quarter-circle swing arcs, plus a row of folding/accordion doors at the bottom).

---

## 2. Goal

Replace the brown rectangle with **NZS 4503-ish architectural symbols**, drawn with SVG `<path>` so they look clean at any zoom, with the same data round-tripping through draft → publish → PDF export.

Each door has:
- A **leaf** (the door itself — a single line at the open angle, usually 90°).
- An **arc** (the swing path — a quarter circle from leaf endpoint back to the opening start).
- An **opening** (the gap in the wall where the door sits — implied by leaf placement; we don't draw the wall break).
- Optional **hinge dot** at the pivot point.

---

## 3. Catalogue (subkinds)

Mirror the fire-extinguisher / smoke-alarm pattern: each subkind ships in [config/site_plan_taxonomy.php](../config/site_plan_taxonomy.php) and is selectable through a popover on placement and in the inspector.

| `subkind` | Label | Symbol |
|---|---|---|
| `single_swing` (default) | Single swing door | One leaf + quarter-circle arc |
| `double_swing` | Double doors (French) | Two mirrored leaves + two arcs meeting in the middle |
| `sliding` | Sliding door | Two parallel rectangles offset on a track |
| `pocket` | Pocket door | One leaf with hatched pocket section into the wall |
| `bifold` | Bifold door | Zigzag (two panels folding) |
| `folding` | Folding / accordion | Repeated chevrons (3–4 panels) — like the bottom row of the reference image |
| `garage` | Garage / roller | Rectangle with vertical hatch lines (representing the panels) |
| `revolving` | Revolving door | Circle with 4 cross spokes |

`single_swing`, `double_swing`, `sliding` cover ~95% of real residential plans. Implement those three completely; the rest can follow the same pattern but I've specified geometry for all so this plan doesn't fragment.

---

## 4. Data model

### 4.1 `PlanDoor` (frontend)

Extend [resources/js/pages/sites/plan/_types.ts](../resources/js/pages/sites/plan/_types.ts):

```ts
export type DoorSubkind =
    | 'single_swing'
    | 'double_swing'
    | 'sliding'
    | 'pocket'
    | 'bifold'
    | 'folding'
    | 'garage'
    | 'revolving';

export type DoorSwingSide = 'left' | 'right';
export type DoorSwingDirection = 'in' | 'out';

export type PlanDoor = {
    id: string;
    x: number;                     // hinge corner in normalised coords (kept for backward compat — see §4.3)
    y: number;
    width?: number;                // door opening width in normalised X
    rotation_deg?: number;         // already there; rotates the whole symbol around its centre
    // new — all optional with defaults applied in a normaliser
    subkind?: DoorSubkind;         // default 'single_swing'
    swing_side?: DoorSwingSide;    // default 'right' (hinge on the right of the opening, viewed normally)
    swing_direction?: DoorSwingDirection;  // default 'in' (arc swings downward in unrotated space)
    swing?: string;                // KEEP for backward compat (deprecated, see §4.3)
};
```

### 4.2 Defaults helper

Add a normaliser, also in `_types.ts`:

```ts
export function normaliseDoor(door: PlanDoor): Required<Pick<PlanDoor, 'subkind' | 'swing_side' | 'swing_direction' | 'width'>> & PlanDoor {
    return {
        ...door,
        subkind: door.subkind ?? 'single_swing',
        swing_side: door.swing_side ?? (door.swing === 'left' ? 'left' : 'right'),
        swing_direction: door.swing_direction ?? 'in',
        width: door.width ?? 0.06,
    };
}
```

### 4.3 Backward compatibility

- Old doors saved with `swing: 'right'` or `swing: 'left'` and no other fields: the normaliser maps them onto `swing_side` and assumes `subkind: 'single_swing'`, `swing_direction: 'in'`. They render correctly without a migration.
- New doors save both the new fields **and** keep writing `swing` so older deployed bundles don't break. Drop `swing` in a follow-up once everyone is on the new bundle.
- No database migration needed — layout is a JSON column.

### 4.4 Backend (PHP)

The current door schema in [SiteTypePlanService::seedDefaultLayout()](../app/Services/Sites/SiteTypePlanService.php) (around line 388–405) doesn't seed any doors — fine, leave the seed alone.

Add the same normaliser concept in PHP for the SVG renderer:

```php
private function normaliseDoor(array $door): array
{
    return array_merge([
        'subkind' => 'single_swing',
        'swing_side' => $door['swing'] === 'left' ? 'left' : 'right',
        'swing_direction' => 'in',
        'width' => 0.06,
        'rotation_deg' => 0,
    ], $door);
}
```

Call from `renderLayoutSvg()` (described in §6).

---

## 5. SVG geometry — exact path strings

All values below assume **unrotated, hinge-on-the-right, swing-inward (down)** as the canonical base case. Rotation is applied by wrapping the whole symbol in `<g transform="rotate(${rotation} ${cx} ${cy})">` where `(cx, cy)` is the centre of the opening (`x + w/2, y`). Mirroring for swing_side/swing_direction is applied **before** rotation (see §5.7).

Let:
- `x, y` = top-left of the opening (in SVG pixels — i.e. `door.x * canvasWidth`, `door.y * canvasHeight`).
- `w` = opening width in SVG pixels (`door.width * canvasWidth`).
- All `<path>` strings use `M` (move), `L` (line), `A` (arc), `Q` (quadratic).
- Stroke colour `#1f2937` (slate-800), stroke width `2`, fill `none`, `stroke-linecap="round"`.

### 5.1 `single_swing` (canonical)

Geometry:
- Opening line: from `(x, y)` to `(x + w, y)` — drawn **NOT solid**, just two short stubs at each end representing the wall break. Use 6-pixel "wall stop" lines on each side: `M x,y-3 L x,y+3` and `M x+w,y-3 L x+w,y+3`.
- Leaf: from the hinge `(x + w, y)` perpendicular to the opening, length = `w`. Endpoint at `(x + w, y + w)`.
- Arc: quarter circle from leaf endpoint `(x + w, y + w)` back to the open-side jamb `(x, y)`, radius `w`.

SVG paths:

```svg
<!-- wall stops at each end of the opening -->
<path d="M ${x},${y - 3} L ${x},${y + 3}" stroke="#1f2937" stroke-width="2"/>
<path d="M ${x + w},${y - 3} L ${x + w},${y + 3}" stroke="#1f2937" stroke-width="2"/>
<!-- leaf -->
<path d="M ${x + w},${y} L ${x + w},${y + w}" stroke="#1f2937" stroke-width="2" stroke-linecap="round"/>
<!-- swing arc -->
<path d="M ${x + w},${y + w} A ${w},${w} 0 0 1 ${x},${y}" stroke="#1f2937" stroke-width="1.5" fill="none"/>
<!-- hinge dot -->
<circle cx="${x + w}" cy="${y}" r="2" fill="#1f2937"/>
```

The arc command: `A rx,ry x-axis-rotation large-arc-flag sweep-flag x,y`. For our quarter circle: `rx=ry=w`, `large-arc-flag=0`, `sweep-flag=1` (clockwise in SVG's flipped-Y coordinate system, which renders as counter-clockwise visually — i.e. swinging "into the room").

### 5.2 Mirror for `swing_side = 'left'`

Hinge moves to `(x, y)`. Leaf goes down: endpoint `(x, y + w)`. Arc sweeps the other way: `sweep-flag=0`.

```svg
<path d="M ${x},${y - 3} L ${x},${y + 3}" .../>
<path d="M ${x + w},${y - 3} L ${x + w},${y + 3}" .../>
<path d="M ${x},${y} L ${x},${y + w}" .../>
<path d="M ${x},${y + w} A ${w},${w} 0 0 0 ${x + w},${y}" .../>
<circle cx="${x}" cy="${y}" r="2" fill="#1f2937"/>
```

### 5.3 Mirror for `swing_direction = 'out'`

Leaf goes **up** instead of down: endpoint `(x + w, y - w)` for right-hinge. Arc sweeps the opposite way.

For right-hinge, out-swing:
```svg
<path d="M ${x + w},${y} L ${x + w},${y - w}" .../>
<path d="M ${x + w},${y - w} A ${w},${w} 0 0 0 ${x},${y}" .../>
```

There are four combinations of `swing_side × swing_direction`. Encode as a tiny lookup table in the renderer rather than four if-blocks:

```ts
const SWING_PATHS = {
    'right-in':  { hinge: [w, 0], end: [w, w], arcSweep: 1 },
    'right-out': { hinge: [w, 0], end: [w, -w], arcSweep: 0 },
    'left-in':   { hinge: [0, 0], end: [0, w], arcSweep: 0 },
    'left-out':  { hinge: [0, 0], end: [0, -w], arcSweep: 1 },
};
```

Where each entry's offsets are added to `(x, y)`.

### 5.4 `double_swing` (French doors)

Each leaf is `w/2` wide. Hinges at both jambs, leaves meeting in the middle.

```svg
<!-- wall stops -->
<path d="M ${x},${y - 3} L ${x},${y + 3}" .../>
<path d="M ${x + w},${y - 3} L ${x + w},${y + 3}" .../>
<!-- left leaf -->
<path d="M ${x},${y} L ${x},${y + w/2}" .../>
<path d="M ${x},${y + w/2} A ${w/2},${w/2} 0 0 0 ${x + w/2},${y}" .../>
<circle cx="${x}" cy="${y}" r="2" fill="#1f2937"/>
<!-- right leaf -->
<path d="M ${x + w},${y} L ${x + w},${y + w/2}" .../>
<path d="M ${x + w},${y + w/2} A ${w/2},${w/2} 0 0 1 ${x + w/2},${y}" .../>
<circle cx="${x + w}" cy="${y}" r="2" fill="#1f2937"/>
```

`swing_side` is ignored for double doors (both sides are hinged). `swing_direction` flips both arcs together using the same negation as §5.3.

### 5.5 `sliding`

Two parallel rectangles offset by half their width, indicating the panels overlap on a track. No arc.

```svg
<!-- track line -->
<path d="M ${x},${y - 2} L ${x + w},${y - 2}" stroke="#1f2937" stroke-width="1"/>
<!-- back panel -->
<rect x="${x}" y="${y - 1}" width="${w * 0.55}" height="3" fill="#1f2937"/>
<!-- front panel (offset down for clarity) -->
<rect x="${x + w * 0.45}" y="${y + 3}" width="${w * 0.55}" height="3" fill="#1f2937"/>
<!-- direction arrow (right) -->
<path d="M ${x + w - 6},${y + 4.5} L ${x + w - 2},${y + 4.5} M ${x + w - 4},${y + 3} L ${x + w - 2},${y + 4.5} L ${x + w - 4},${y + 6}" stroke="#1f2937" stroke-width="1.2" fill="none"/>
```

### 5.6 `pocket`

A single leaf inside a hatched pocket. The pocket extends behind one wall.

```svg
<!-- wall stops -->
<path d="M ${x + w},${y - 3} L ${x + w},${y + 3}" .../>
<!-- pocket (hatched rectangle behind the wall on the hinge side) -->
<rect x="${x - w * 0.9}" y="${y - 4}" width="${w * 0.9}" height="8" fill="none" stroke="#1f2937" stroke-width="1" stroke-dasharray="3 3"/>
<!-- leaf (shown partially open inside the pocket) -->
<rect x="${x - w * 0.7}" y="${y - 1}" width="${w * 0.6}" height="3" fill="#1f2937"/>
```

### 5.7 `bifold`, `folding`, `garage`, `revolving`

- **`bifold`**: two panels at 45° meeting at a peak. Path: `M ${x},${y} L ${x + w/2},${y + w/2} L ${x + w},${y}`.
- **`folding`**: 4 panels alternating up/down (matches the bottom row of the reference image): `M ${x},${y} L ${x + w*0.25},${y + w*0.25} L ${x + w*0.5},${y} L ${x + w*0.75},${y + w*0.25} L ${x + w},${y}`.
- **`garage`**: filled rectangle with 5–6 vertical hatch lines representing the panel divisions. `<rect x y w 6>` plus loop of vertical lines.
- **`revolving`**: circle of radius `w/2` centred at `(x + w/2, y)` with two perpendicular spokes through the centre.

These can ship in the same PR or a follow-up; they all reuse the rotation-and-mirror machinery, so the marginal cost is small.

### 5.8 Rotation

After the subkind path is built, wrap in:

```svg
<g transform="rotate(${rotation_deg} ${x + w/2} ${y})">
  ... paths ...
</g>
```

Note the pivot is `(x + w/2, y)` — the centre of the opening. This matches the existing rotation-handle pivot for doors in `_canvas.tsx`.

---

## 6. Files to touch

### Frontend

| File | Change |
|---|---|
| [resources/js/pages/sites/plan/_types.ts](../resources/js/pages/sites/plan/_types.ts) | Extend `PlanDoor`; add `DoorSubkind`, `DoorSwingSide`, `DoorSwingDirection`, `normaliseDoor`. |
| `resources/js/pages/sites/plan/_door.tsx` **(new)** | Export `DoorSymbol` React component that takes `(door, canvasWidth, canvasHeight)` and returns the SVG group for the door (computed via §5). Also export `doorCentreNormalised(door)` and `doorHandlePivot(door, canvasWidth, canvasHeight)` so the canvas's rotation handle math doesn't have to know subkind geometry. |
| [resources/js/pages/sites/plan/_canvas.tsx](../resources/js/pages/sites/plan/_canvas.tsx) | In the **Doors** block (currently a flat `<rect>`), replace the rect with `<DoorSymbol door={door} canvasWidth={canvasWidth} canvasHeight={canvasHeight} selected={selected} pending={pending}/>`. Keep the existing pointer / context handlers. Rotation wrapper is still applied at the `<g>` outside `DoorSymbol`, but the door symbol's bounding box may exceed the old 10-unit-tall rect — make sure the pointer hit area still works (an invisible `<rect>` underlay sized to the symbol's bbox is the simplest fix). |
| [resources/js/pages/sites/plan/_thumbnail.tsx](../resources/js/pages/sites/plan/_thumbnail.tsx) | Use the same `DoorSymbol` for read-only previews. Currently the thumbnail also draws doors as flat brown rects — replace identically. |
| [resources/js/pages/sites/plan/_inspector.tsx](../resources/js/pages/sites/plan/_inspector.tsx) | In the `SelectionDetails` branch for `single.type === 'door'`, add three rows: subkind picker (`<select>` or popover with the eight options), swing side toggle (Left / Right), swing direction toggle (In / Out). All call `dispatch({ type: 'commit' })` then `dispatch({ type: 'update_door', id, patch: { subkind: …, swing_side: …, swing_direction: … } })`. Width row already implicit through resize — but a numeric metres input here would help. |
| [resources/js/pages/sites/plan/_tool-palette.tsx](../resources/js/pages/sites/plan/_tool-palette.tsx) | Make the Door tool tile a popover-trigger (like fire extinguisher) so the user can pick a subkind **before** placing. The active subkind is held in `state.activeSubkind`. On canvas click, the `__door` placement handler reads `activeSubkind` and writes it into the new door. |

### Backend

| File | Change |
|---|---|
| [app/Services/Sites/SiteTypePlanService.php](../app/Services/Sites/SiteTypePlanService.php) | Extend `renderLayoutSvg()` to draw doors. Currently the method walks `rooms` and `walls` only. Add a `foreach ($layout['doors'] ?? [] as $door)` block that calls a new private method `renderDoorSvg(array $door, int $width, int $height): string` which returns the same `<g>...</g>` markup as the frontend `DoorSymbol`. **Critical**: the published Emergency Plan PDF currently has no doors at all on its evacuation map. |
| `config/site_plan_taxonomy.php` *(optional)* | Add a `doors` section with `subkinds` for UI labels: `[ 'single_swing' => 'Single swing', 'double_swing' => 'Double doors (French)', 'sliding' => 'Sliding', 'pocket' => 'Pocket', 'bifold' => 'Bifold', 'folding' => 'Folding', 'garage' => 'Garage', 'revolving' => 'Revolving' ]`. Read by the frontend through the existing `taxonomy` payload so labels stay in sync. |

### Tests

| File | Change |
|---|---|
| `tests/Feature/Sites/SiteTypePlanTest.php` | New: `it('renders door symbols in the emergency PDF SVG')` — seed a draft with a door of each subkind, publish, hit `/sites/{id}/emergency-plan.pdf?paper=a4`, assert the response body contains the expected `<path>` substrings for arcs. |
| `tests/e2e/site-plan-builder.spec.ts` | Extend the existing test to place a door, switch subkind to `double_swing` in the inspector, screenshot-snapshot the canvas, then verify the SVG has two `<path>` arc commands. |

No database migration. No PHP model change.

---

## 7. Inspector — exact controls

Add inside `SelectionDetails` for `single.type === 'door'`, **before** the rotation field:

```tsx
<div className="grid grid-cols-3 items-center gap-2">
    <Label className="text-xs">Style</Label>
    <select
        value={door.subkind ?? 'single_swing'}
        onChange={(e) => {
            dispatch({ type: 'commit' });
            dispatch({ type: 'update_door', id: door.id, patch: { subkind: e.target.value as DoorSubkind } });
        }}
        className="col-span-2 h-7 rounded border bg-white px-1 text-xs"
        data-test="site-plan-door-subkind"
    >
        <option value="single_swing">Single swing</option>
        <option value="double_swing">Double (French)</option>
        <option value="sliding">Sliding</option>
        <option value="pocket">Pocket</option>
        <option value="bifold">Bifold</option>
        <option value="folding">Folding</option>
        <option value="garage">Garage</option>
        <option value="revolving">Revolving</option>
    </select>
</div>
{(door.subkind === 'single_swing' || door.subkind == null) && (
    <>
        <div className="grid grid-cols-3 items-center gap-2">
            <Label className="text-xs">Hinge</Label>
            <div className="col-span-2 flex gap-1">
                <Button variant={door.swing_side === 'left' ? 'default' : 'outline'} size="sm" className="h-7 flex-1 text-xs"
                    onClick={() => { dispatch({ type: 'commit' }); dispatch({ type: 'update_door', id: door.id, patch: { swing_side: 'left' } }); }}>
                    Left
                </Button>
                <Button variant={door.swing_side !== 'left' ? 'default' : 'outline'} size="sm" className="h-7 flex-1 text-xs"
                    onClick={() => { dispatch({ type: 'commit' }); dispatch({ type: 'update_door', id: door.id, patch: { swing_side: 'right' } }); }}>
                    Right
                </Button>
            </div>
        </div>
        <div className="grid grid-cols-3 items-center gap-2">
            <Label className="text-xs">Opens</Label>
            <div className="col-span-2 flex gap-1">
                <Button variant={door.swing_direction === 'out' ? 'default' : 'outline'} size="sm" className="h-7 flex-1 text-xs"
                    onClick={() => { dispatch({ type: 'commit' }); dispatch({ type: 'update_door', id: door.id, patch: { swing_direction: 'out' } }); }}>
                    Outward
                </Button>
                <Button variant={door.swing_direction !== 'out' ? 'default' : 'outline'} size="sm" className="h-7 flex-1 text-xs"
                    onClick={() => { dispatch({ type: 'commit' }); dispatch({ type: 'update_door', id: door.id, patch: { swing_direction: 'in' } }); }}>
                    Inward
                </Button>
            </div>
        </div>
    </>
)}
<div className="grid grid-cols-3 items-center gap-2">
    <Label className="text-xs">Width</Label>
    <Input
        type="number"
        step="0.05"
        min="0.4"
        value={Math.round(((door.width ?? 0.06) * (layout.canvas?.width ?? 1000)) * mpu * 100) / 100}
        onChange={(e) => {
            const m = Number.parseFloat(e.target.value);
            if (!Number.isFinite(m) || m <= 0) return;
            const w = m / (mpu * (layout.canvas?.width ?? 1000));
            dispatch({ type: 'commit' });
            dispatch({ type: 'update_door', id: door.id, patch: { width: w } });
        }}
        className="col-span-2 h-7 text-xs"
    />
</div>
<span className="text-[10px] text-muted-foreground">Metres</span>
```

The `Hinge` and `Opens` rows hide for kinds where they don't make sense (`sliding`, `bifold`, `folding`, `garage`, `revolving`).

---

## 8. Pointer hit area

The old `<rect>` was the click target for selection/drag/context menu. The new `DoorSymbol` is a `<g>` with multiple thin paths — much smaller hit area. Solution: render a transparent `<rect>` as a hit shield, **first**, sized to encompass the symbol's bounding box:

```tsx
<rect
    x={x - 4}
    y={Math.min(y, y - w) - 4}
    width={w + 8}
    height={w + 8}
    fill="transparent"
    onPointerDown={...}
    onClick={...}
    onContextMenu={...}
    onDoubleClick={...}
    style={{ cursor: selected ? 'grab' : 'move' }}
/>
```

The bounding box for `single_swing` spans the opening (`w` wide) and the arc (extends `w` away from the wall on whichever side the swing is). Account for both swing directions when sizing.

---

## 9. PHP renderer — exact methods

Inside `renderLayoutSvg()` in [SiteTypePlanService.php](../app/Services/Sites/SiteTypePlanService.php), insert after the walls loop and before the pins loop:

```php
foreach (($layout['doors'] ?? []) as $door) {
    $svg[] = $this->renderDoorSvg($this->normaliseDoor($door), $width, $height);
}
```

The `renderDoorSvg` method mirrors the React component's geometry exactly (§5). Keep colours and stroke widths identical so the PDF export matches the on-screen builder.

For the rotated wrapper:

```php
$cx = ($door['x'] + $door['width'] / 2) * $width;
$cy = $door['y'] * $height;
$rotation = (int) ($door['rotation_deg'] ?? 0);
$svg[] = $rotation
    ? sprintf('<g transform="rotate(%d %.2f %.2f)">', $rotation, $cx, $cy)
    : '<g>';
// ... emit paths ...
$svg[] = '</g>';
```

---

## 10. Migration & versioning

- **No backend migration.** The layout JSON column accepts the extra fields with no schema change.
- **No data backfill.** The `normaliseDoor` helper fills the gaps at read time.
- **Versioning.** `layout.schema_version` is currently `1` — leave it. The new fields are additive and backward compatible.
- **Existing plans.** Plans rendered before this PR show every door as `single_swing` swing-right swing-in — the same visual as the old brown rectangle gave readers (which was nothing) but architecturally meaningful.

---

## 11. Acceptance criteria

- [ ] A newly placed door defaults to `single_swing`, hinge right, opening inward, width ~0.6 m.
- [ ] The on-screen door renders as a leaf + quarter-circle arc + hinge dot + wall stops (not a flat rectangle).
- [ ] Inspector for a selected door shows Style / Hinge / Opens / Width rows; Hinge & Opens hide for non-swing subkinds.
- [ ] Switching `Style` between the eight subkinds updates the canvas symbol immediately.
- [ ] Switching `Hinge` left/right mirrors the leaf and arc horizontally.
- [ ] Switching `Opens` inward/outward mirrors the leaf and arc vertically.
- [ ] Existing rotation handle continues to work; the whole symbol rotates around the opening's centre.
- [ ] The pointer hit area covers the symbol's full bounding box, not just the leaf line.
- [ ] `/sites/{id}/emergency-plan.pdf?paper=a4` includes door arcs in the SVG payload (verified by feature test).
- [ ] Existing plans with `swing: 'right'` saved as a string render correctly without re-saving.
- [ ] `npx tsc --noEmit`, `npx vite build`, `php artisan test --filter=SiteTypePlanTest`, and `npx playwright test tests/e2e/site-plan-builder.spec.ts --project=chromium-desktop` all pass.

---

## 12. Out of scope

- Wall-snap: making doors auto-align to the nearest wall and orient themselves to the wall's angle. Useful but its own pass — would require either click-against-wall hit detection or a "place into wall" interaction mode.
- Door swing animation on hover (purely decorative).
- New backend table for doors. They stay inside `layout.doors[]` JSON.
- Stairs, elevators, ramps — not in the user's reference image; out of scope.

---

## 13. Suggested PR sequence

Single PR is fine because the scope is contained:

1. Add `DoorSymbol` component, extend `PlanDoor` types, ship the `single_swing` + `double_swing` + `sliding` subkinds (covers ~95% of real plans).
2. Wire inspector controls + tool palette popover.
3. Add backend SVG rendering in `renderLayoutSvg()`.
4. Add `pocket`, `bifold`, `folding`, `garage`, `revolving` — same component, more cases.
5. Tests.

If the diff feels too large, split (1–3) and (4–5) into two PRs.

---

## 14. Quick reference

| What | Where |
|---|---|
| Door type | [resources/js/pages/sites/plan/_types.ts](../resources/js/pages/sites/plan/_types.ts) — `PlanDoor` |
| Door rendering (canvas) | [resources/js/pages/sites/plan/_canvas.tsx](../resources/js/pages/sites/plan/_canvas.tsx) — search `Doors` |
| Door rendering (thumbnail) | [resources/js/pages/sites/plan/_thumbnail.tsx](../resources/js/pages/sites/plan/_thumbnail.tsx) — same `Doors` block |
| Inspector door panel | [resources/js/pages/sites/plan/_inspector.tsx](../resources/js/pages/sites/plan/_inspector.tsx) — `single.type === 'door'` branch (currently in `SelectionDetails`) |
| Tool palette door tile | [resources/js/pages/sites/plan/_tool-palette.tsx](../resources/js/pages/sites/plan/_tool-palette.tsx) — kind `__door` |
| PHP SVG renderer | [app/Services/Sites/SiteTypePlanService.php](../app/Services/Sites/SiteTypePlanService.php) — `renderLayoutSvg()` ~line 408+ |
| Taxonomy (subkinds shared with frontend) | [config/site_plan_taxonomy.php](../config/site_plan_taxonomy.php) |
| Tests | [tests/Feature/Sites/SiteTypePlanTest.php](../tests/Feature/Sites/SiteTypePlanTest.php), [tests/e2e/site-plan-builder.spec.ts](../tests/e2e/site-plan-builder.spec.ts) |
