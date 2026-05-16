# Site-Type Plan, Emergency Plan & Hardware Pinning — Implementation Plan

**Status:** Planning only. No code changes in this PR.
**Target implementer:** Codex.
**Audience:** Single Codex session that will land the feature in sequenced PRs.

---

## 0. Goal summary

Add a **SiteTypePlan** — a saveable layout/floor plan for every Site — and build on top of it:

1. A dynamic tab on the site detail page labelled **House Plan / Office Plan / Facility Plan** (depending on `site.type`), opened as a popup builder.
2. An **Emergency Plan** experience that uses the published SiteTypePlan as its base and exports A3 / A4 / A5 PDFs matching `Emergency Plan Download PDF Example.png`.
3. **Hardware pin placement** (cameras, APs, sensors, etc.) on the SiteTypePlan, sourced from the canonical Security & Devices registry — not a new device system.
4. **Medication Storage** as a placeable pin/zone on the SiteTypePlan, backwards-compatible with the existing `medication_storage_location` text.
5. An updated **Overview → Safety & Medication** card that surfaces plan/emergency/medication status with quick navigation.

Critical user requirement: **SiteTypePlan must exist first**. Emergency Plan generation and Hardware pinning depend on it.

---

## 1. Current-state findings (verified)

| Concern | File / table | Notes |
|---|---|---|
| Site detail page | [resources/js/pages/sites/show.tsx](resources/js/pages/sites/show.tsx) (~6.5k LOC) | `activeTab` state in `useState`; tab list scrolls horizontally; type-specific tab label switches Rooms/Resources/Zones at [show.tsx:1533-1547](resources/js/pages/sites/show.tsx:1533). |
| Tabs in DOM | [show.tsx:1742-1958](resources/js/pages/sites/show.tsx:1742) | Sequence: overview, readiness, clients, assets, contacts, documents, calendar, checklists, hazards, fleet, More → financials, vendors-credentials, hardware, type-specific, staff-requirements, shift-coverage, service-contexts. |
| Overview → Safety card | [show.tsx:2192-2287](resources/js/pages/sites/show.tsx:2192) | Plain text `emergency_plan_location` + `medication_storage_location`; edit opens `EditSafetyDialog`. |
| Edit Safety dialog | [resources/js/pages/sites/_overview-dialogs.tsx:597-692](resources/js/pages/sites/_overview-dialogs.tsx:597) | PATCH `/sites/{site}/safety` → `SiteController::updateSafety` (text-only). |
| Safety route | [routes/assets.php:69-71](routes/assets.php:69) | `sites.safety.update` already exists. |
| Site model | [app/Models/Site.php](app/Models/Site.php) | Fillable includes `emergency_plan_location`, `medication_storage_location`. SoftDeletes + AuditableChanges traits. Relations: `houseRooms`, `hoResources`, `facilityZones`, `siteRooms`, `locationHardware` (deprecated), `geofences`, `serviceContexts`, etc. |
| House rooms | `site_house_rooms` / [app/Models/SiteHouseRoom.php](app/Models/SiteHouseRoom.php) + [SiteRoomController](app/Http/Controllers/Sites/SiteRoomController.php) | Bedrooms + communal rooms for `house`/`residential`. `is_assignable`, `assigned_client_id`, history. Routes: `/sites/{site}/rooms`. |
| Head-office resources | `site_ho_resources` / `SiteHoResource` + `SiteResourceController` | Routes: `/sites/{site}/resources`. |
| Facility zones | `site_facility_zones` / `SiteFacilityZone` + `SiteZoneController` | Routes: `/sites/{site}/zones`. |
| Hardware-placement room registry | `site_rooms` / [app/Models/SiteRoom.php](app/Models/SiteRoom.php) | Lightweight rooms used as `DeviceAssignment.assignable_type='room'` targets. Polymorphic `linked_room_type`/`linked_room_id` already bridges to `house_room` / `ho_resource` / `facility_zone`. |
| Hardware tab | [app/Http/Controllers/Sites/SiteHardwareController.php](app/Http/Controllers/Sites/SiteHardwareController.php) + [resources/js/pages/sites/hardware/index.tsx](resources/js/pages/sites/hardware/index.tsx) | Read-only context view. Source of truth is `DeviceRegistryService::forSite($tenantId,$siteId)` ([app/Domain/SecurityDevices/Services/DeviceRegistryService.php:25-45](app/Domain/SecurityDevices/Services/DeviceRegistryService.php:25)) which already returns devices assigned to the site OR to any `SiteRoom` belonging to the site. CRUD lives in Security & Devices. `assign-room` and `manageRooms` endpoints remain in Sites. |
| Device taxonomy | [app/Domain/SecurityDevices/Config/DeviceTaxonomy.php](app/Domain/SecurityDevices/Config/DeviceTaxonomy.php) | Domains: `security` (alarm, cctv, access_control, perimeter), `tracking`, `iot_healthcare` (incl. medication / fridge_temp_sensor / nurse_call), `it_infrastructure` (servers, network, voice), `facilities` (leak/gas/cold_chain/building_safety incl. fire_panel + emergency_lighting). |
| DeviceAssignment | [app/Domain/SecurityDevices/Models/DeviceAssignment.php](app/Domain/SecurityDevices/Models/DeviceAssignment.php) | Targets: site, room, vehicle, staff, client. Active scope = `released_at IS NULL`. |
| Readiness | [app/Services/Sites/SiteReadinessService.php](app/Services/Sites/SiteReadinessService.php) | 7 critical items: phone, email, site lead, after-hours, **emergency_plan**, **med_storage**, emergency_contact. Each checks `filled($site->emergency_plan_location)` / `filled($site->medication_storage_location)`. Score uses `critical*2 + recommended`. |
| PDF stack | `barryvdh/laravel-dompdf ^3.1` ([composer.json](composer.json)) — pattern in [EmarPdfController.php](app/Http/Controllers/Emar/EmarPdfController.php): `Pdf::loadView('pdf.x', [...])->setPaper('A4','landscape')->download(...)`. Existing PDF blades in `resources/views/pdf/`: `mar-chart`, `controlled-drug-register`, `round-sheet`. | A3/A4/A5 supported by DomPDF — pass paper presets or `[0,0,w,h]` in points (A3=842×1191, A4=595×842, A5=420×595). |
| DnD | `@dnd-kit/{core,sortable,utilities}` already in [package.json](package.json); used in [resources/js/pages/sites/rooms/index.tsx](resources/js/pages/sites/rooms/index.tsx) for room reorder. No canvas/SVG drawing library in repo — geofences use Leaflet inside `geofence-draw-map`. |
| Hero patterns | [resources/js/components/fleet-hero.tsx](resources/js/components/fleet-hero.tsx) and the inline gradient hero at [show.tsx:1602](resources/js/pages/sites/show.tsx:1602) — `bg-gradient-to-br from-primary/90 via-primary to-primary/80`. Use `FleetHero` for any *new standalone page*, with a `backHref` back button. |
| Dialog sizing | `DialogContent` uses `max-w-md/lg/xl/2xl/sm:max-w-[...]`. For the builder use `max-w-[min(1400px,95vw)] h-[min(900px,90vh)]` (no existing helper — declare directly). |
| Permissions | `sites.viewAny`, `sites.update`, `siteHardware.view`, `siteHardware.manage`, `securityDevices.devices.assign`, etc. ([RbacSeeder.php](database/seeders/RbacSeeder.php)). |
| Tenancy / branding | `Site` has `tenant_id`. There is **no fleshed-out `Tenant` or `Organization` model** with branding fields — `app/Models/Organization.php` is an empty stub. Branding for PDF must use `config('app.name')` + an org-level logo source — see §6.7. |

---

## 2. Naming & terminology

| Concept | Code name | UI label |
|---|---|---|
| The plan record (PHP + DB) | `SiteTypePlan` (table `site_type_plans`) | n/a |
| Tab on site detail (dynamic by `site.type`) | `tab.value = "type-plan"` (replaces `type-specific`) | `house` → **House Plan**, `head_office` → **Office Plan**, `facility` → **Facility Plan** |
| Builder modal | `SiteTypePlanBuilderDialog` | "Build House Plan" / "Edit Office Plan" etc. |
| Emergency Plan tab | `tab.value = "emergency-plan"` (new) | **Emergency Plan** |
| Pin record (FK to plan) | `SiteTypePlanPin` (table `site_type_plan_pins`) | n/a |

The existing per-type management screens (`/sites/{site}/rooms`, `/resources`, `/zones`) **stay where they are**. The new Plan tab links to them — it does NOT replace them. Rooms/resources/zones are the *inventory*; the plan is the *spatial overlay*.

---

## 3. Proposed data model

### 3.1 Tables

**`site_type_plans`** — one *current draft* and zero-or-one *current published* per site.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `tenant_id` | bigint, indexed | mirror of `sites.tenant_id` |
| `site_id` | bigint, FK→sites, indexed | |
| `site_type` | string(32) | snapshot of `sites.type` at creation for type-specific rendering |
| `status` | enum(`draft`,`published`,`archived`) | only one row per `(site_id, status='draft')` and per `(site_id, status='published')` enforced by composite unique partial index |
| `version` | unsigned int default 1 | increments on each publish |
| `layout` | json | structured schema — see §3.2 |
| `notes` | text nullable | free notes for the draft |
| `published_at` | timestamp nullable | set on publish |
| `published_by_user_id` | bigint nullable | FK→users |
| `created_by_user_id` | bigint nullable | FK→users |
| `archived_at` | timestamp nullable | superseded published versions move here |
| timestamps + softDeletes | | |

Indexes: `(site_id, status)`, `(tenant_id, site_id)`.

**`site_type_plan_pins`** — placeable items overlaid on a plan.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `tenant_id` | bigint, indexed | |
| `site_type_plan_id` | bigint, FK→site_type_plans, cascade | |
| `kind` | enum | `device`, `medication_storage`, `emergency_exit`, `evacuation_route`, `assembly_point`, `you_are_here`, `fire_extinguisher`, `fire_blanket`, `first_aid_kit`, `smoke_alarm`, `defibrillator`, `gas_shutoff`, `water_shutoff`, `electrical_panel`, `custom_marker` |
| `subkind` | string(64) nullable | e.g. for `kind='device'` mirror device `category`/`subcategory` for fast filtering |
| `device_id` | bigint nullable, FK→devices | required when `kind='device'`; null otherwise |
| `room_ref_type` | string nullable | `house_room` \| `ho_resource` \| `facility_zone` \| `site_room` — informational link |
| `room_ref_id` | bigint nullable | |
| `label` | string(120) nullable | display label override |
| `notes` | text nullable | for medication storage etc. (access instructions, locked / CD storage) |
| `meta` | json nullable | flags: `is_controlled_drug`, `is_locked`, route-line waypoints, color overrides, rotation, etc. |
| `x` | decimal(10,4) | 0..1 normalised on plan width |
| `y` | decimal(10,4) | 0..1 normalised on plan height |
| `rotation_deg` | smallint default 0 | |
| `width` | decimal(10,4) nullable | only for shape/zone pins (medication zone, evacuation route bounding) |
| `height` | decimal(10,4) nullable | |
| `path_points` | json nullable | for `evacuation_route` polylines: `[{x,y},...]` |
| `sort_order` | int default 0 | render order |
| timestamps | | |

Indexes: `(site_type_plan_id, kind)`, `(device_id)`, `(tenant_id, kind)`.

> **Why normalise coordinates 0..1 and store layout dimensions in `layout` JSON?** Resolution-independent — the same coords render correctly at A3/A4/A5 export and on the live builder regardless of zoom. Avoids drift if the canvas size changes.

### 3.2 `layout` JSON schema (versioned)

```jsonc
{
  "schema_version": 1,
  "canvas": { "width": 1000, "height": 700, "unit": "rel" },
  "grid": { "enabled": true, "size": 20, "snap": true },
  "scale": { "metres_per_unit": 0.05, "label": "1:50" }, // optional
  "walls": [
    { "id": "w1", "points": [{"x":0.1,"y":0.1},{"x":0.9,"y":0.1}], "thickness": 4 }
  ],
  "rooms": [
    {
      "id": "r1",
      "label": "Bedroom 1",
      "shape": "rect",                // rect | polygon
      "x": 0.05, "y": 0.10, "width": 0.30, "height": 0.25,
      "polygon": null,
      "room_ref": { "type": "house_room", "id": 42 }  // links to existing inventory
    }
  ],
  "doors":   [{ "id":"d1","x":0.20,"y":0.10,"width":0.08,"swing":"right" }],
  "windows": [{ "id":"win1","x":0.50,"y":0.10,"width":0.10 }],
  "labels":  [{ "id":"l1","x":0.50,"y":0.50,"text":"Hallway","size":12 }]
}
```

Plan-level structures live in `layout` JSON; *placeable* items (pins, routes, assembly point, emergency markers, medication storage, devices) live in `site_type_plan_pins`. This split makes pin reuse / audit / device-reference simpler than embedding device IDs in JSON.

### 3.3 Lifecycle / versioning

- A new plan starts as a single row with `status='draft'`.
- `Save Draft` updates the existing draft row.
- `Finalise / Publish` does:
  1. Move any existing `status='published'` row to `status='archived'` and stamp `archived_at`.
  2. Promote the draft to `status='published'`, increment `version`, stamp `published_at`/`published_by_user_id`.
  3. Pins remain associated with the same `site_type_plan_id` — they are copied across by *not changing the row id*. (We only flip the status field — see below.)
- `Edit Published` creates a brand-new `status='draft'` row by **cloning** the current published row's `layout` and pins. The draft is editable without disturbing the live plan; publishing the draft archives the previous live plan. This is the "duplicate to new draft" path called out in the requirements — safer than mutating a live plan.

> Decision: only the **current draft** is editable. Archived rows are kept for audit + readiness lineage but are immutable.

### 3.4 Migrations

Single migration `create_site_type_plans_and_pins.php`:
- Creates both tables above.
- Adds **no destructive changes** to `sites.emergency_plan_location` or `sites.medication_storage_location` — they remain the text fallback (see §5.2).

---

## 4. Routes / controllers / services

### 4.1 Routes (append to [routes/sites.php](routes/sites.php))

All under the existing `Route::prefix('sites/{site}')->middleware('permission:sites.viewAny')` group, except where noted.

```
GET    /sites/{site}/plan                      → SiteTypePlanController@show     (perm: sites.viewAny)
POST   /sites/{site}/plan/draft                → SiteTypePlanController@storeDraft   (perm: sites.update)
PUT    /sites/{site}/plan/draft                → SiteTypePlanController@updateDraft  (perm: sites.update)
POST   /sites/{site}/plan/publish              → SiteTypePlanController@publish      (perm: sites.update)
POST   /sites/{site}/plan/duplicate-to-draft   → SiteTypePlanController@duplicate    (perm: sites.update)
DELETE /sites/{site}/plan/draft                → SiteTypePlanController@discardDraft (perm: sites.update)

# Pin CRUD — single endpoint for upsert/delete from builder
POST   /sites/{site}/plan/pins                 → SiteTypePlanPinController@storeBatch (perm: sites.update)
PUT    /sites/{site}/plan/pins/{pin}           → SiteTypePlanPinController@update     (perm: sites.update)
DELETE /sites/{site}/plan/pins/{pin}           → SiteTypePlanPinController@destroy    (perm: sites.update)

# Emergency Plan
GET    /sites/{site}/emergency-plan            → SiteEmergencyPlanController@show     (perm: sites.viewAny)
PUT    /sites/{site}/emergency-plan            → SiteEmergencyPlanController@update   (perm: sites.update)
GET    /sites/{site}/emergency-plan.pdf        → SiteEmergencyPlanController@download (perm: sites.viewAny; query: ?paper=a3|a4|a5)

# Hardware pin convenience (operates on the current draft if exists, else current published)
POST   /sites/{site}/hardware/{device}/pin     → SiteHardwareController@pinDevice     (perm: siteHardware.manage)
DELETE /sites/{site}/hardware/{device}/pin     → SiteHardwareController@unpinDevice   (perm: siteHardware.manage)
```

Conventions:
- `{pin}` resolves via `SiteTypePlanPin` model binding and is verified to belong to a plan of `{site}` via controller guard.
- All write routes preserve existing `permission:` middleware idioms.
- Existing `/sites/{site}/safety` PATCH stays — see §5.2 fallback.

### 4.2 Controllers

| Controller | Path | Purpose |
|---|---|---|
| `App\Http\Controllers\Sites\SiteTypePlanController` | new | show / storeDraft / updateDraft / publish / duplicate / discardDraft. Reads `currentDraft()` and `currentPublished()`. Returns Inertia payload for the Plan tab and raw JSON for builder save endpoints (form requests return back with flash). |
| `App\Http\Controllers\Sites\SiteTypePlanPinController` | new | batch upsert + delete. `storeBatch` accepts `{pins: [{id?, kind, ...}]}` and reconciles in a single transaction. |
| `App\Http\Controllers\Sites\SiteEmergencyPlanController` | new | reads published plan + pins, returns Inertia page (`sites/emergency-plan/index.tsx`) or PDF via DomPDF. Refuses PDF if no published plan. |
| `SiteHardwareController` | existing | add `pinDevice` / `unpinDevice`. Pinning a device creates / updates a `site_type_plan_pins` row with `kind='device'`, `device_id=$id`, attached to the **current draft if present, else the current published plan**. |
| `SiteController` | existing | extend `show()` Inertia payload to include `typePlan` summary (status, version, published_at, has_emergency_layer, has_medication_pin, thumbnail) and pin counts. Extend `updateSafety` to keep accepting the legacy text but also accept an optional `medication_pin_id` to keep them in sync. |

### 4.3 Services

| Service | Responsibility |
|---|---|
| `App\Services\Sites\SiteTypePlanService` | `currentDraft(Site)`, `currentPublished(Site)`, `cloneToDraft(Site)`, `publishDraft(Site, User)`, `archive(SiteTypePlan)`, `summaryFor(Site)`. All mutating ops wrapped in a DB transaction; emit `SiteTypePlanPublished` event (for downstream consumers). |
| `App\Services\Sites\SiteEmergencyPlanService` | derives final emergency-plan layer from pins, validates "ready to export" (has assembly point + at least one exit + you-are-here), assembles the PDF view-model (site, organisation, contacts, procedures, support notes, scale, layout). |
| `App\Services\Sites\SiteTypePlanPdfService` | thin wrapper around DomPDF: `download(plan, paper='a4', orientation='portrait')`. Renders `resources/views/pdf/site-type-plan/emergency.blade.php` with the model above. Paper presets: a3, a4, a5; orientation derived from `canvas.width >= canvas.height`. |
| `SiteReadinessService` (existing) | **extend** to treat readiness items `emergency_plan` and `med_storage` as `done` when *either* (a) the legacy text is filled, or (b) a published `SiteTypePlan` exists with an assembly point + ≥1 exit (for emergency_plan) / a `medication_storage` pin (for med_storage). Keeps score formula unchanged. |

---

## 5. UI flow by tab

### 5.1 Plan tab (House Plan / Office Plan / Facility Plan)

**Location:** the existing dynamic type-specific tab at [show.tsx:1533-1547](resources/js/pages/sites/show.tsx:1533) is re-labelled:

```ts
const typePlanTab = {
  value: 'type-plan',
  label: site.type === 'head_office' ? 'Office Plan'
       : site.type === 'facility'    ? 'Facility Plan'
       : 'House Plan',
  icon:  site.type === 'head_office' ? Building2
       : site.type === 'facility'    ? LayoutGrid
       : Home,
};
```

(Move the current Rooms/Resources/Zones inventory under a "Manage rooms/resources/zones" link that opens the existing `/sites/{site}/rooms` etc. page — do not duplicate inventory inside the plan tab.)

**Empty state (no plan yet):**
- Hero illustration + heading "No plan yet for {site.name}".
- Primary CTA `<Button>Build {type} Plan</Button>` (perm: `sites.update`) → opens `SiteTypePlanBuilderDialog` with a fresh draft.
- Secondary: "Use a starter template" — drop a default rectangle + 3 bedrooms (house) / open zones (office/facility). Backed by `SiteTypePlanService::seedDefaultLayout($type)`.

**Plan exists state:**
- Big SVG thumbnail of the published plan (rendered client-side from `layout` + pins).
- Status badges: "Draft" (yellow), "Published v{n}" (success), "Draft over Published" (info).
- Buttons:
  - `Continue Draft` (when draft exists)
  - `Edit Published` (when only published exists) → triggers `POST /plan/duplicate-to-draft` then opens builder
  - `Publish` (when draft exists, requires `sites.update`)
  - `View Emergency Plan` → switches `activeTab` to `emergency-plan`
  - `Manage rooms` / `resources` / `zones` → routes to existing pages
- Side panel listing pins grouped by kind (Devices N, Medication storage N, Emergency exits N, etc.).

**Builder dialog (`SiteTypePlanBuilderDialog`):**
- Component file: `resources/js/pages/sites/plan/_builder-dialog.tsx`.
- `Dialog` with `DialogContent className="max-w-[min(1400px,95vw)] h-[min(900px,90vh)] p-0 gap-0 overflow-hidden"`.
- Three regions: left **tool palette**, centre **SVG canvas**, right **inspector panel** (selected pin/room properties).
- Tools: select / pan-zoom / wall / room (rect) / door / window / label / pin (sub-menu: medication, exit, assembly, you-are-here, fire-extinguisher, fire-blanket, first-aid-kit, smoke-alarm, defibrillator, gas-shutoff, water-shutoff, electrical-panel, custom) / evacuation-route (polyline) / delete.
- Canvas implementation: **plain SVG** — no new dependency. Use `<svg viewBox="0 0 1000 700">` and absolute-positioned overlays for pin icons (`lucide-react` icons in `<foreignObject>` or as `<image>` data-URI). Mouse pos transformed via `getScreenCTM` for snap-to-grid.
- Drag: keep `@dnd-kit/core` for sortable lists in the inspector; for canvas drag use raw pointer events on the SVG (simpler than wiring @dnd-kit into SVG hit-testing).
- Footer: `Save Draft` (left) · `Cancel` (right) · `Publish` (right, primary, disabled until validation passes). Validation = at least one room/wall, otherwise inline warning.
- Confirmation on Publish via existing `<ConfirmAction>` ([resources/js/pages/sites/_confirm-action.tsx](resources/js/pages/sites/_confirm-action.tsx)) — no `window.confirm`.

> **Decision: SVG, not Konva/Fabric/tldraw.** Reasons: (a) zero new deps, (b) easy to render server-side into the DomPDF blade by emitting the same SVG markup, (c) the user explicitly said "do not overbuild a CAD tool". The trade-off is no built-in undo stack — implement a simple ring-buffer `useReducer` over `layout`+`pins` (last 25 states).

### 5.2 Overview → Safety & Medication card

Replace [show.tsx:2192-2287](resources/js/pages/sites/show.tsx:2192) with a richer version:

```
┌─ Safety & Medication ─────────────────[ Edit ]
│ ┌─ Plan status ────────────────┐
│ │ [thumbnail SVG] Published v3 │
│ │   updated 12 Mar 2026 by …   │
│ │   [ Open House Plan ]        │
│ └──────────────────────────────┘
│
│ ┌─ Emergency Plan ─────────────┐
│ │ ✓ Ready  · Assembly: Front car park
│ │   3 exits · 2 routes pinned
│ │   [ Open Emergency Plan ] [ Download A4 PDF ]
│ └──────────────────────────────┘
│
│ ┌─ Medication Storage ─────────┐
│ │ ✓ Pinned in "Kitchen — locked cabinet"
│ │   Controlled drugs: yes
│ │   [ Open in plan ] [ Edit notes ]
│ └──────────────────────────────┘
│
│ (Risk Information block unchanged when high_risk/high_needs)
└──────────────────────────────────────────────────
```

Empty states still show the existing `MissingFieldButton` style — keep the legacy "Add emergency plan" / "Add medication storage" CTAs pointing at the new Plan flow but **gracefully fall back** to the existing text dialog (`EditSafetyDialog`) when the user prefers a quick text note.

Backwards compatibility:
- `sites.emergency_plan_location` / `sites.medication_storage_location` remain — they're shown as a description line below the structured status when set.
- Readiness service treats either the text OR a corresponding plan element as `done` (see §4.3).

### 5.3 Emergency Plan tab (new)

- `tab.value = 'emergency-plan'`; insert in the More dropdown and in the 2xl visible row, just after the Plan tab.
- Disabled with tooltip "Build the {House/Office/Facility} Plan first" when there's no published `SiteTypePlan`.
- When present:
  - Top banner: status + last updated.
  - Centre: read-only render of the published plan layout overlaid with emergency-class pins. Hover pin to see label/notes.
  - Right side: form/inspector to add/edit emergency pins (exits, routes, assembly point, fire-extinguisher, fire-blanket, first-aid-kit, smoke-alarm, defibrillator). Saving uses the pin batch endpoint scoped to `kind IN (emergency_exit, evacuation_route, assembly_point, you_are_here, fire_extinguisher, fire_blanket, first_aid_kit, smoke_alarm, defibrillator, custom_marker)`.
  - "Export PDF" dropdown: A3 / A4 / A5. Each is a direct GET to `/sites/{site}/emergency-plan.pdf?paper=a4`. Disabled unless plan has assembly point + ≥1 exit.

### 5.4 Hardware tab — pinning extension

- No re-architecture. Keep current device table at [resources/js/pages/sites/hardware/index.tsx](resources/js/pages/sites/hardware/index.tsx).
- Add a column **Plan Pin** with three states per row:
  - `—` (no plan exists) — render link "Build plan first".
  - `Pin on plan` button → opens a small dialog showing the plan thumbnail; click to drop a pin, then "Save".
  - `Pinned (Bedroom 1)` chip + `[ Edit ] [ Remove ]`.
- A second top-of-page button "Open Hardware Layer in Plan" jumps to the Plan tab with a `?layer=devices` query, scrolling devices to the front of the inspector.
- Pin coordinates persist via `POST /sites/{site}/hardware/{device}/pin` → writes to `site_type_plan_pins` (kind='device'). Released DeviceAssignment is handled by the existing flow — when a device's active site assignment goes to null, the pin is soft-detached (kept for history but flagged `meta.stale=true`).

### 5.5 Readiness tab interaction

- `handleReadinessAction` at [show.tsx:1424-1467](resources/js/pages/sites/show.tsx:1424) currently routes both `add_emergency_plan` and `add_med_storage` to `setSafetyOpen(true)`. After the change:
  - `add_emergency_plan` → switch to `emergency-plan` tab (or open builder if no plan yet).
  - `add_med_storage` → if no plan: open builder pre-armed to drop a medication-storage pin; else switch to Plan tab with `?focus=medication`.

---

## 6. Sequenced phases

> Land in **separate PRs** in this order. Each phase must be green on its own. Do not merge phases together.

### P0 — Foundation: SiteTypePlan data model + read-only Plan tab

- Migration: `site_type_plans` + `site_type_plan_pins`.
- Models: `SiteTypePlan`, `SiteTypePlanPin` (with `forSite` scope, `currentPublished` / `currentDraft` static helpers).
- Service: `SiteTypePlanService` (read paths only + `cloneToDraft`).
- Controller: `SiteTypePlanController@show` returning Inertia `sites/plan/index` page.
- Show.tsx: re-label the dynamic tab, render a *placeholder* "Build Plan" empty state, and a thumbnail when a published plan exists.
- New empty React page `resources/js/pages/sites/plan/index.tsx` (used when navigating directly).
- Tests: model factory, service unit, controller smoke test.

### P1 — Builder modal

- Controllers: `storeDraft`, `updateDraft`, `discardDraft`, `duplicate`.
- Pin controller: `storeBatch` + `update` + `destroy`.
- React: `SiteTypePlanBuilderDialog` with SVG canvas, tool palette, inspector, undo ring buffer. Wired to the Plan tab CTA.
- Auto-save the draft on `Save Draft` only — no continuous autosave (avoids merge conflicts).
- Tests: feature test posting a layout + pins; e2e test opening the modal and drawing one room.

### P1 — Publish + readiness wiring

- `SiteTypePlanController@publish` + archive logic.
- Extend `SiteReadinessService` to treat plan presence as `done` for emergency_plan / med_storage. Backfill `slim()`.
- Update Overview Safety & Medication card with the structured layout.
- Feature tests: readiness flips to green when a plan with assembly point + 1 exit is published.

### P2 — Emergency Plan tab + PDF export

- Controller + service + blade `resources/views/pdf/site-type-plan/emergency.blade.php`.
- React page `sites/emergency-plan/index.tsx`.
- PDF view-model includes:
  - Organisation name (from `config('app.name')` or new `tenants.brand_name` if added later — keep behind a helper `OrgBranding::name()` so it's a one-line swap when org branding is built out)
  - Logo (helper `OrgBranding::logoUrl()` returning `null` for now — DomPDF blade hides the block when null)
  - Site name + address + phone (from `Site`)
  - Floor layout: emit the same SVG generated by `SiteTypePlanService::renderLayoutSvg($plan)` (server-side stringification — DomPDF does *not* support all SVG features, so the helper should produce a constrained SVG: `<rect>`, `<line>`, `<polyline>`, `<text>`, no filters/gradients).
  - Legend: derived from pins kinds present.
  - Assembly point block (right column in the example image).
  - Emergency Contacts: pull from `Site::contacts()` filtered by type IN (`emergency`, `manager`, `site_lead`, `after_hours`) + the hard-coded NZ emergency number (**111**, not 999 — the example image uses 999 because it's UK; localise to NZ per `project_nz_context`).
  - Standard procedures: 6-step list (raise alarm, call 111, evacuate, assemble, account, do-not-reenter) sourced from a constant in `SiteEmergencyPlanService::standardProcedures()` — keep editable later via an admin screen.
  - Resident support notes: blank lines + any saved free text from the published plan's `notes`.
  - Footer: "Generated from {OrgBranding::name()} – {tab label}".
- Paper handling: A3 portrait/landscape, A4 portrait/landscape, A5 portrait. Default A4 portrait. UI surfaces the three sizes the user requested.
- Tests: feature test exporting a PDF returns 200 with `application/pdf`; readiness blocks export when no plan exists.

### P2 — Hardware pinning

- `SiteHardwareController@pinDevice/@unpinDevice`.
- Hardware table column + small pin dialog.
- Pin scoping: a device pin is anchored to the current published plan's id (or the draft if one is open at pin time). Re-publishing a draft preserves device pins because pins live by `site_type_plan_id` and `duplicateToDraft` copies them.
- Tests: feature tests for pin/unpin permissions; UI smoke test that pin chip appears.

### P2 — Overview Safety & Medication evolution

- Replace card content. Keep the existing `EditSafetyDialog` as the "Edit notes" path (text fallback). Add `MedicationStorageDialog` for editing the pin's notes (label, locked, controlled-drug flag, access instructions).

> **Sequencing rule:** Hardware pinning (P2) and Emergency Plan (P2) can ship in either order after the foundation. They both depend on P0 + P1.

---

## 7. Acceptance criteria

P0
- [ ] Visiting `/sites/{site}` on a House shows the tab labelled **House Plan** (Office Plan / Facility Plan for the other types). The previous Rooms/Resources/Zones label is gone from this tab.
- [ ] Existing `/sites/{site}/rooms` (and `/resources`, `/zones`) pages still load and function exactly as before.
- [ ] With no plan, the tab shows an empty state with a single primary "Build {type} Plan" CTA (hidden without `sites.update`).
- [ ] DB migration creates `site_type_plans` and `site_type_plan_pins` with the indexes listed in §3.1 and no other table is altered.

P1 builder
- [ ] `Build {type} Plan` opens a modal (NOT a navigation) on top of the site detail page.
- [ ] User can drop rooms / walls / doors / windows / labels / pins; pins include medication storage and the emergency markers listed in §3.1.
- [ ] `Save Draft` posts a single batch and the modal stays open; toast confirms success.
- [ ] Closing without saving discards changes (existing `ConfirmAction` confirms).
- [ ] `Publish` archives the previous published row, increments `version`, and is gated behind `sites.update`.
- [ ] `Edit Published` clones to a fresh draft; the live plan continues to render until the new draft is published.

P1 readiness
- [ ] On a site with no `emergency_plan_location` text AND no published plan, `readiness.critical` shows `emergency_plan.done=false`.
- [ ] After publishing a plan with ≥1 assembly point + ≥1 exit, `emergency_plan.done=true` even when the legacy text is empty.
- [ ] Same logic for `med_storage` when a `medication_storage` pin exists.

P2 emergency plan
- [ ] Emergency Plan tab is disabled with tooltip when no published plan exists.
- [ ] After publishing, the tab renders the layout + emergency pins.
- [ ] Three PDF downloads work (A3/A4/A5); each returns Content-Type `application/pdf`, downloads as `{slug-of-site-name}-emergency-plan-{paper}.pdf`.
- [ ] The PDF visually contains: org name, site name + address + phone, floor layout, legend, assembly point, 6-step procedure list, emergency contacts (incl. NZ 111), resident support notes block, generated-by footer.

P2 hardware
- [ ] Hardware table shows a Plan Pin column.
- [ ] Pinning a device persists to `site_type_plan_pins` and shows up in the Plan and Emergency Plan tabs.
- [ ] Removing a device's site assignment does not delete its pin — pin is marked stale.
- [ ] Pinning requires `siteHardware.manage`.

Overview
- [ ] Safety & Medication card shows the new three-block layout when a plan exists.
- [ ] Buttons link to the right tabs and PDF download endpoint.
- [ ] Legacy text-only flow still works on sites without a plan.

Cross-cutting
- [ ] No `window.alert` / `window.confirm`. All confirmations via existing `ConfirmAction` / `AlertDialog`.
- [ ] All new standalone pages (if any) use `FleetHero` with a `backHref`.
- [ ] No new heavy frontend dependencies. Allowed: built-in React, existing `@dnd-kit`, lucide icons, existing UI components.

---

## 8. Test plan

### 8.1 PHP feature tests (Pest, RefreshDatabase)

New file `tests/Feature/Sites/SiteTypePlanTest.php`:
- creates a draft → loads tab → publishes → asserts row counts and statuses
- publishing twice archives the first publish and increments `version`
- duplicating to draft clones layout + pin rows but flips status
- pin batch endpoint reconciles inserts/updates/deletes
- permission guards on every write endpoint
- DeviceAssignment removal flags pin `meta.stale=true`

New file `tests/Feature/Sites/SiteEmergencyPlanTest.php`:
- GET `/sites/{site}/emergency-plan` 404s without published plan
- GET `/sites/{site}/emergency-plan.pdf?paper=a4` returns 200 + `application/pdf` when ready
- A3 / A4 / A5 each render
- PDF refuses to render without assembly point or exit pin
- contacts list contains site contacts + the "111" hard-coded line

Extend `tests/Feature/Sites/SiteOperationalReadinessTest.php`:
- adds a test: publishing a plan flips `emergency_plan.done` to `true` even when `emergency_plan_location` is null
- equivalent for `med_storage`

Extend `tests/Feature/SecurityDevices/SiteHardwareRefactorTest.php`:
- `pinDevice` writes a `site_type_plan_pins` row with `kind='device'`, `device_id`
- `unpinDevice` deletes it (or soft-marks if behind a strict-history flag)

### 8.2 TypeScript / build checks

- `npm run typecheck` + `npm run build` clean.
- Add a tiny vitest (if vitest is wired — otherwise rely on type-check) for the SVG coordinate helpers in `_builder-dialog.tsx`.

### 8.3 Playwright e2e

New `tests/e2e/site-type-plan.spec.ts`:
- seeds a house site, logs in, opens detail
- asserts the tab reads "House Plan"
- clicks `Build House Plan`, expects modal
- draws a room (mouse down/up on canvas), saves draft, reloads, sees the room
- publishes, sees green badge
- switches to Emergency Plan tab, adds an assembly point + exit, publishes
- clicks Download A4 PDF, asserts the response is a PDF

New `tests/e2e/site-emergency-plan-pdf.spec.ts`:
- minimal API-level check: hit `/sites/{id}/emergency-plan.pdf` and assert content-type/length.

Extend `tests/e2e/sites-overview-contacts.spec.ts`-style coverage with `tests/e2e/sites-overview-safety.spec.ts`:
- with plan absent, "Add emergency plan" CTA still visible
- with plan present, structured status block renders

---

## 9. Risks / open questions

| Risk | Mitigation |
|---|---|
| SVG drawing UX is harder than a Konva canvas. | Constrain feature set (rect rooms only in P1, polygon in a later phase). Provide a "Use template" starter to skip the blank canvas. |
| DomPDF SVG support is partial — gradients/filters/feComponentTransfer don't render. | `SiteTypePlanService::renderLayoutSvg` emits a constrained dialect (flat fills, no filters). Verified locally before P2 ships. |
| Branding/org logo doesn't exist in repo yet. | Hide the logo block when `OrgBranding::logoUrl()` returns null; use `config('app.name')` as fallback. Open a follow-up to wire a real Organization model later. |
| Multiple users editing the same draft simultaneously could clobber each other. | Add `version` integer to the draft row; on save, `WHERE id=? AND version=?` — if mismatch, return 409 and let the UI reload. Single-user case is unaffected. |
| Pin coordinates drift if canvas dimensions change. | Coordinates are 0..1 normalised; `layout.canvas` carries the absolute size for renderers. |
| Existing `SiteRoom` (hardware-placement registry) vs the new `room_ref` link could confuse readers. | Document the distinction in the Plan controller's PHPDoc and in the new model class comments. `SiteRoom` is *unchanged*. |
| Emergency localisation: the reference image uses 999. NZ uses 111. | Hard-code 111 in `standardProcedures()`; expose via a config constant for later i18n. (Per `project_nz_context`.) |
| Performance: very pin-heavy plans (100+ pins). | Add a composite index `(site_type_plan_id, kind)`. UI virtualises the pin list panel above 50 pins. |
| **Open question — multiple plans per site?** | Out of scope. Current spec: one current draft + one current published per site, plus archived history. Multi-floor support is a follow-up. |
| **Open question — pin permissions for emergency markers vs device pins.** | Device pins gated by `siteHardware.manage`. All other pins gated by `sites.update`. Reflected in route middleware in §4.1. |
| **Open question — publishing affects pin IDs?** | No. Publishing only flips `status`. Pin rows are NOT duplicated. `duplicateToDraft` is the only path that copies pins (and it inserts new ids). |

---

## 10. What NOT to change

- Do **not** alter `site_house_rooms`, `site_ho_resources`, `site_facility_zones`, `site_rooms` schemas. The Plan model *references* these, never replaces them.
- Do **not** add device CRUD inside Sites. `SiteHardwareController` stays a read view + pin/unpin convenience.
- Do **not** mutate fields on `App\Domain\SecurityDevices\Models\Device` to store plan coordinates. Pins live in `site_type_plan_pins`.
- Do **not** remove the existing `/sites/{site}/safety` route or the `EditSafetyDialog`. They remain the text-only fallback.
- Do **not** introduce Konva, Fabric, tldraw, react-flow, or any other canvas/diagram dependency. Plain SVG only.
- Do **not** change the `Tabs` ordering for tabs unrelated to Plan/Emergency. The only ordering change is replacing the type-specific tab label and inserting the Emergency Plan tab adjacent to it.
- Do **not** change `SiteReadinessService` score weighting. Only the `done` predicates for `emergency_plan` and `med_storage` change.
- Do **not** break the existing Playwright fixture in `tests/e2e/sites-overview-contacts.spec.ts`; its `emergency_plan_location='Kitchen folder'` seed must still produce `readiness.emergency_plan.done=true` via the legacy path.
- Do **not** add `window.alert`, `window.confirm`, `prompt`, or browser-native dialogs.
- Do **not** create new top-level documentation files unless asked — this plan is the only allowed companion document.

---

## 11. File summary (for the implementer)

New
- `database/migrations/{ts}_create_site_type_plans_and_pins.php`
- `app/Models/SiteTypePlan.php`
- `app/Models/SiteTypePlanPin.php`
- `app/Http/Controllers/Sites/SiteTypePlanController.php`
- `app/Http/Controllers/Sites/SiteTypePlanPinController.php`
- `app/Http/Controllers/Sites/SiteEmergencyPlanController.php`
- `app/Services/Sites/SiteTypePlanService.php`
- `app/Services/Sites/SiteEmergencyPlanService.php`
- `app/Services/Sites/SiteTypePlanPdfService.php`
- `app/Support/OrgBranding.php` (helper: `name()`, `logoUrl()`)
- `resources/views/pdf/site-type-plan/emergency.blade.php`
- `resources/js/pages/sites/plan/index.tsx`
- `resources/js/pages/sites/plan/_builder-dialog.tsx`
- `resources/js/pages/sites/plan/_canvas.tsx`
- `resources/js/pages/sites/plan/_inspector.tsx`
- `resources/js/pages/sites/plan/_tool-palette.tsx`
- `resources/js/pages/sites/plan/_thumbnail.tsx` (shared between Plan tab + Overview card)
- `resources/js/pages/sites/emergency-plan/index.tsx`
- `tests/Feature/Sites/SiteTypePlanTest.php`
- `tests/Feature/Sites/SiteEmergencyPlanTest.php`
- `tests/e2e/site-type-plan.spec.ts`
- `tests/e2e/site-emergency-plan-pdf.spec.ts`
- `tests/e2e/sites-overview-safety.spec.ts`

Modified
- [routes/sites.php](routes/sites.php) — add the routes in §4.1
- [app/Http/Controllers/SiteController.php](app/Http/Controllers/SiteController.php) — extend `show()` Inertia payload with `typePlan` summary
- [app/Http/Controllers/Sites/SiteHardwareController.php](app/Http/Controllers/Sites/SiteHardwareController.php) — add `pinDevice` / `unpinDevice`
- [app/Services/Sites/SiteReadinessService.php](app/Services/Sites/SiteReadinessService.php) — extend `emergency_plan` + `med_storage` `done` predicates
- [resources/js/pages/sites/show.tsx](resources/js/pages/sites/show.tsx) — re-label type-specific tab, insert Emergency Plan tab, replace Safety & Medication card content
- [resources/js/pages/sites/_overview-dialogs.tsx](resources/js/pages/sites/_overview-dialogs.tsx) — keep `EditSafetyDialog`, add a small `MedicationStoragePinDialog`
- [resources/js/pages/sites/hardware/index.tsx](resources/js/pages/sites/hardware/index.tsx) — add Plan Pin column
- [tests/Feature/Sites/SiteOperationalReadinessTest.php](tests/Feature/Sites/SiteOperationalReadinessTest.php) — extend with plan-based readiness assertions
- [tests/Feature/SecurityDevices/SiteHardwareRefactorTest.php](tests/Feature/SecurityDevices/SiteHardwareRefactorTest.php) — add pin/unpin coverage

Unchanged (called out explicitly so the implementer doesn't touch them)
- `site_house_rooms`, `site_ho_resources`, `site_facility_zones`, `site_rooms` tables and their controllers
- `App\Domain\SecurityDevices\*` — Device, DeviceAssignment, DeviceRegistryService all stay as-is
- Existing `/sites/{site}/safety` PATCH route + `SiteController::updateSafety`

---

*End of plan.*
