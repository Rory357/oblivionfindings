# Fleet & Assets — Massive Audit Fix Plan (2026-07-03)

Branch: `claude/strange-bhaskara-ae2843`. Base: `27096344` (origin/main).
Process: implement waves → re-audit ×2 → fix → tsc/pest green → merge main → push → Chrome-verify on oblivionfindings.com.

## Audit synthesis (5 parallel explorers)

1. **Module is oversized**: 68 pages across 3 route layers. `/fleet-assets` (59 pages) is canonical; `/fleet-management` (6 pages, deprecated except trip playback) and `/assets` (3 pages, dead) are legacy.
2. **UX gaps vs gold standard**: every page uses bland static `PageHero`; 12 create/edit flows are full pages instead of WizardShell modals; no context menus; thin pages that should be tabs.
3. **Maps/geofencing**: LeafletMap consistent & healthy. Issues: dead `AssetGeofenceEvaluator` (opposite inside/outside defaults vs live `FleetGeofenceService`), dead `fleet-map.tsx` (Google Maps), orphaned `GeofenceZone` model, no shape validation on geofence store/update, consent-blocked devices keep stale lat/lng.
4. **HR↔Assets federation**: HR→Fleet link works; Fleet→HR back-link missing (`Asset::hrAsset()` inverse doesn't exist); no sidebar cross-link; 403 trap when user has `hr.assets.view` but not `assets.viewAny`; fleet incidents don't surface HR assignment context.
5. **Cross-module**: incidents fully wired (FLT-, HsEvent, control-room). Missing: FleetServiceSchedule → calendar/tasks; WOF/rego/service expiry user notifications; WO-/BK- reference numbers; My Day fleet surface.

## Waves

### Wave 1 (parallel)
- **A. Maps/geofence hardening** (backend): delete AssetGeofenceEvaluator (rewire SiteGeofenceTest to FleetGeofenceService), delete fleet-map.tsx + Google key usage, remove GeofenceZone model (+drop migration if table empty-safe), add shape validation in GeofenceController store/update (circle: center+radius; polygon: ≥3 pts), null device lat/lng when consent blocked.
- **B. HR↔Assets federation**: `Asset::hrAsset()` inverse; fleet asset show/index payload + "Also in HR Assets" back-link (permission-gated); sidebar cross-links both ways (gated); gate HR→Fleet link render on fleet perms to kill 403 trap; incident detail payload gets HR assignment context; HR asset show gets fleet incidents section (via linked asset).
- **C. Fleet hero kit**: new `resources/js/pages/fleet-assets/components/fleet-hero-kit.tsx` reusing hs-hero-kit primitives; command-centre heroes (KPI clusters, status pill, quick actions, compliance chips WOF/Rego/CoF/insurance) applied to dashboard, vehicles, assets, and map pages first.

### Wave 2 (single agent — owns routes + sidebar)
- **D. Dead code removal**: delete `resources/js/pages/assets/*` + dead routes in routes/assets.php (KEEP QR + telemetry ingest APIs); delete fleet-management pages/routes except trip playback → move playback to `/fleet-assets/trips/{trip}/playback` (301 old path); remove orphaned sidebar/nav references.

### Wave 3 (parallel by page group)
- **E. Heroes rollout**: bookings, drivers, maintenance dashboard, trips, fuel, compliance, incidents, reports, geofences, alerts.
- **F. Modal wizards** (WizardShell): assets create/edit → modal on index/show; bookings create → modal; work-order create → modal; mileage create → modal; inspection create → modal; handover create → modal; resident-tracking assign → modal. (Geofence create/edit stay full-page for map drawing; transports/outings create stay pages this round — too thick, restyle only.)
- **G. Tab consolidation**: driver scorecard → tab in drivers/show; devices consent → tab in devices/index; device show → drawer/dialog from devices/index; wandering alerts → tab in resident-tracking; compliance → tab under vehicles hub (keep route, add TabStrip). Update sidebar to match (fewer items).

### Wave 4
- **H. Cross-module wiring**: FleetMaintenanceObligationProvider covering FleetServiceSchedule + work orders (register everywhere calendar providers must be registered — check PPE memory: 4 places); /tasks provider for fleet maintenance; WOF/rego/service-due user notifications to fleet managers; HasReferenceNumber WO- (FleetWorkOrder) + BK- (FleetVehicleBooking) with backfill migration + display in UI.

### Wave 5 — audit round 2 → fixes → audit round 3 → fixes
### Wave 6 — verify: `npx tsc --noEmit`, scoped Pest (parent-repo caveat), build; merge main, push, Chrome test deployed.

## Gotchas (from memory)
- Worktree junctioned vendor: PHP tests may autoload PARENT app — verify in parent or copy vendor + dump-autoload.
- Herd php: use `~/.config/herd/bin/php84/php.exe`.
- New perms need grant migrations (seeders don't run on deploy).
- `<Select.Item value="">` crashes runtime — sentinel values.
- Empty string → null via ConvertEmptyStringsToNull on NOT NULL cols.
- Full-width app, web-only desktop. No mobile views.
- Pint: new files clean; don't pint shared files.
- Inertia+axios endpoints must content-negotiate.
