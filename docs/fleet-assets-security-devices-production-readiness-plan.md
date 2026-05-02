# Fleet / Assets / Security Devices — production-readiness plan

> Reference doc only. No code changes proposed beyond the surgical fixes
> enumerated below. Mirrors the structure of
> [`docs/control-room-readiness-plan.md`](control-room-readiness-plan.md),
> [`docs/rostering-clients-care-readiness-plan.md`](rostering-clients-care-readiness-plan.md),
> and [`docs/governance-privacy-consents-readiness-plan.md`](governance-privacy-consents-readiness-plan.md).
>
> Scope: the cluster GPT-5.5 flagged as "Fleet / Assets / Security Devices —
> Partial — Large modules with telemetry/control-room integrations." Covers the
> Asset, Fleet, Fleet-Assets unified shell, Security & Devices canonical
> registry, Control Room device projection, and the telemetry → signal →
> alert pipeline that ties them together. The Finance fixed-asset link is
> covered narrowly (operational asset → `fin_fixed_assets.linked_asset_id`).

## Verdict

**Partial because integration hardening is unfinished, not because pieces are
missing.** The repo evidence shows a substantially mature module:

- The canonical Security & Devices registry
  ([`app/Domain/SecurityDevices/Models/Device.php`](../app/Domain/SecurityDevices/Models/Device.php))
  is in place with `DeviceAssetLink`, `DeviceAssignment`, `DeviceRelationship`,
  `DeviceGroup`, `DeviceEvent`, `DeviceMaintenanceRecord`, `DeviceDocument`
  models; deprecated legacy models (`AssetTracker`, `LocationHardware`,
  `ControlRoom\Device`) are explicitly marked as bridges with retention
  rationale baked into their docblocks.
- Telemetry ingest is idempotent: `fleet_telemetry_events.idempotency_key`
  carries a UNIQUE index ([`migration:50`](../database/migrations/2026_02_03_000200_create_fleet_management_tables.php#L50));
  duplicates short-circuit inside a `DB::transaction` + `lockForUpdate`
  ([`FleetTelemetryIngestService.php:58-66`](../app/Services/Fleet/FleetTelemetryIngestService.php#L58)).
  Same pattern on `fleet_signals.idempotency_key` and
  `control_room_signals.idempotency_key`.
- Signal pipeline is wired end-to-end:
  `FleetTelemetryIngestService` → `FleetSignalService` → `FleetSignalOutbox` →
  `DispatchFleetSignalOutbox` job → `SignalProcessingService::ingestFromFleetSignal`
  → `ControlRoomAlert`. Device events take the parallel path:
  `DeviceEvent` → `DeviceEventObserver` (registered in
  [`AppServiceProvider:133`](../app/Providers/AppServiceProvider.php#L133)) →
  `SignalProcessingService::ingest/process` → `ControlRoomAlert`. Both paths
  emit `DeviceSignalPublished` / `FleetSignalEmitted` events as public extension
  hooks for cross-module listeners.
- Control Room device projection (`control_room_devices.canonical_device_id`)
  is documented as a projection-only bridge in
  [`app/Models/ControlRoom/Device.php:10-29`](../app/Models/ControlRoom/Device.php#L10),
  test-covered by [`ControlRoomDeviceProjectionTest`](../tests/Feature/SecurityDevices/ControlRoomDeviceProjectionTest.php)
  including the canonicalDevice() bridge round-trip.
- Asset alerts are explicitly archived with read-only enforcement
  ([`AssetAlertController.php:9-15`](../app/Http/Controllers/AssetAlertController.php#L9))
  and [`AssetAlertArchiveTest.php`](../tests/Feature/SecurityDevices/AssetAlertArchiveTest.php)
  guards the legacy-action 404s + replacement URL.
- Consent gating is enforced inside the ingest hot path: when
  `$consentValid === false`, lat/lng/speed/heading/altitude/accuracy are nulled
  on every event/snapshot/state row
  ([`FleetTelemetryIngestService.php:77-91, 102-114, 137-145`](../app/Services/Fleet/FleetTelemetryIngestService.php#L77))
  and broadcast skipped ([`:153`](../app/Services/Fleet/FleetTelemetryIngestService.php#L153)).
  `consent_blocked` is persisted on each row so audits can prove it.
- 28 SecurityDevices feature tests, 8 SecurityDevices unit tests, 23 ControlRoom
  feature tests, 3 ControlRoom unit tests, plus `AssetTelemetryIngestTest`,
  `FleetTelemetryIngestTest`, `AssetControllerTest`, and 3 FleetAssets feature
  tests already exist.

The remaining gaps are real but narrow: a confirmed status-string bug on the
Fleet alert bridge, two hardcoded `tenant_id => 1` calls in canonical Device
creation, one stale "coming soon" placeholder, missing test/e2e coverage for
the fleet alert bridge and the new fleet-assets device pages, an outbox
dead-letter visibility gap, and ambiguity over the parallel
`/fleet-management/*` and `/fleet-assets/*` surfaces.

**No redesign required, no schema changes required.** Six PR-sized phases
covering ~1.5 focused weeks of work, almost entirely in existing files.

---

## 1. Current State Map

### Routes (live)

| File | Surface | Permission gate |
|---|---|---|
| [`routes/assets.php`](../routes/assets.php) | `/assets`, `/sites`, asset CRUD, inspections, maintenance, QR codes, geofences, scan events, ownerships, assignments, telemetry ingest (token + staff) | `assets.*`, `sites.*` |
| [`routes/fleet.php`](../routes/fleet.php) | `/fleet-management` dashboard + `/fleet/*` (vehicles, trips, fuel, driver-sessions, reports, maps usage) | `fleet.viewAny`, `fleet.trips.manage`, `fleet.fuel.manage`, `fleet.driverSessions.manage`, `fleet.reports.view` |
| [`routes/fleet-assets.php`](../routes/fleet-assets.php) | `/fleet-assets/*` unified shell — dashboard, map, vehicles, drivers, bookings, devices, geofences, maintenance (work orders / checklists / schedules), inspections, keys, daily-check, resident-tracking, wandering-alerts, transports, handovers, incidents, outings, mileage, reports, alerts, settings, mobile dashboard. Includes `redirect /fleet-management → /fleet-assets`. | `fleet.viewAny`, `assets.viewAny`, `fleet.manage`, `assets.alerts.manage`, `fleet.maintenance.manage`, `fleet.outings.manage`, `fleet.bookings.approve`, `fleet.mileage.approve`, `fleet.medication.manage`, `fleet.incidents.manage`, plus delegated `assets.*` perms |
| [`routes/security-devices.php`](../routes/security-devices.php) | `/security-devices/*` — dashboard, device CRUD + inline patch, assignments, asset-links, topology relationships, documents, category pages (alarms / cctv / access-control / tracking-devices / smart-iot-healthcare / it-infrastructure / facilities), device groups + auto-rules, alerts-events, maintenance-health, integrations hub (UniFi live; Queclink + Milesight scaffold), reports CSV export | `securityDevices.*` (10 keys: viewAny, devices.{view,create,update,delete,assign}, groups.manage, events.view, maintenance.{view,manage}, integrations.{view,manage}, reports.view) |
| [`routes/control-room.php`](../routes/control-room.php) | `/control-room/devices/*` — index + show. Plus the full canonical Control Room surface (alerts, dashboard, escalations, incidents, integration-alerts, SLA, playbooks, evidence, broadcast, messaging, settings, my-tasks, stats, watchers, time-entries) | `controlRoom.*` |

### Truth sources (controllers / services / models)

**Asset core** ([`app/Http/Controllers/AssetController.php`](../app/Http/Controllers/AssetController.php),
[`AssetAssignmentController.php`](../app/Http/Controllers/AssetAssignmentController.php),
[`AssetGeofenceController.php`](../app/Http/Controllers/AssetGeofenceController.php),
[`AssetInspectionController.php`](../app/Http/Controllers/AssetInspectionController.php),
[`AssetMaintenanceController.php`](../app/Http/Controllers/AssetMaintenanceController.php),
[`AssetOwnershipController.php`](../app/Http/Controllers/AssetOwnershipController.php),
[`AssetQrController.php`](../app/Http/Controllers/AssetQrController.php),
[`AssetScanEventController.php`](../app/Http/Controllers/AssetScanEventController.php),
[`AssetTrackerController.php`](../app/Http/Controllers/AssetTrackerController.php),
[`AssetDocumentController.php`](../app/Http/Controllers/AssetDocumentController.php),
[`AssetReportController.php`](../app/Http/Controllers/AssetReportController.php))
— operational asset registry. The `AssetTrackerController` is explicitly
deprecated and acts as a back-fill bridge; pair/unpair from this surface keeps
the canonical `Device` + `DeviceAssetLink` view in sync via
`FleetDeviceRuntimeService::ensureCanonicalDeviceForTracker` and a
`DeviceAssetLink` insert ([`AssetTrackerController.php:65-85`](../app/Http/Controllers/AssetTrackerController.php#L65)).

**Fleet (legacy split)** ([`app/Http/Controllers/Fleet/`](../app/Http/Controllers/Fleet))
— eight controllers: `FleetDashboardController`, `FleetVehicleController`,
`FleetTripController`, `FleetFuelController`, `FleetDriverSessionController`,
`FleetReportController`, `FleetMapUsageController`,
`FleetMapUsageDashboardController`. Permission-gated at the route level.

**Fleet-Assets (unified shell)** ([`app/Http/Controllers/FleetAssets/`](../app/Http/Controllers/FleetAssets))
— 28 controllers covering the unified `/fleet-assets/*` surface. The two key
ones for this audit:
- [`FleetAssets/DeviceController.php`](../app/Http/Controllers/FleetAssets/DeviceController.php)
  reads from the canonical `Device` table (`domain='tracking'`) and the
  `device_asset_links` join. Pair / unpair go through `DeviceLinkService`,
  consent grant/revoke through `FleetDeviceRuntimeService::resolveConsentContext`
  with assignment-consent precedence over tracker-consent
  ([`:312-380`](../app/Http/Controllers/FleetAssets/DeviceController.php#L312)).
- [`FleetAssets/AlertController.php`](../app/Http/Controllers/FleetAssets/AlertController.php)
  presents `ControlRoomAlert` rows scoped to `source ∈ {fleet, asset, tracker, geofence}`
  alongside archived `AssetAlert` rows. **Has a confirmed bug — see Gap C1.**

**Security & Devices canonical registry** ([`app/Domain/SecurityDevices/`](../app/Domain/SecurityDevices)) —
Models: `Device`, `DeviceAssignment`, `DeviceAssetLink`, `DeviceRelationship`,
`DeviceGroup`, `DeviceGroupMember`, `DeviceEvent`, `DeviceMaintenanceRecord`,
`DeviceDocument`. Services: `DeviceRegistryService`, `DeviceLinkService`,
`DeviceAssignmentService`, `DeviceGroupAutoRuleService`. Enums:
`DeviceDomain`, `DeviceStatus`, `HealthStatus`, `AssignmentType`, `LinkType`,
`RelationshipType`. Controllers: `DashboardController`, `DeviceController`,
`DeviceAssignmentController`, `DeviceGroupController`, `CategoryPageController`,
`AlertsEventsController`, `MaintenanceHealthController`,
`IntegrationsHubController`, `Integrations\{UnifiController, QueclinkController, MilesightController}`,
`ReportsController`, `DeviceDocumentController`. The `MigrateDevicesCommand`
console command exists for legacy backfill.

**Telemetry / Signal pipeline** ([`app/Services/Fleet/`](../app/Services/Fleet),
[`app/Services/ControlRoom/`](../app/Services/ControlRoom),
[`app/Observers/DeviceEventObserver.php`](../app/Observers/DeviceEventObserver.php)) —

```
                 (vehicle telemetry)
HTTP POST /telemetry/ingest/{vendor}
   │   token OR assets.telemetry.ingest permission
   ▼
AssetTelemetryIngestController
   │
   ▼
FleetTelemetryIngestService::ingest
   │  • idempotency: hash(vendor, device_uid, vendor_message_id, event_type, occurred_at, lat, lng, payload)
   │  • DB::transaction + lockForUpdate on idempotency_key (race-safe)
   │  • consent gate: nulls lat/lng/speed/heading/altitude/accuracy when invalid
   │  • emits FleetVehiclePositionUpdated broadcast (consent-gated)
   │  • calls FleetGeofenceService::evaluate (consent-gated)
   │  • calls FleetTripService::handleTelemetry, FleetDrivingMetricsService::handleTelemetry
   │  • emits SOS / tamper signals on the spot
   ▼
FleetSignalService::emit
   │  • idempotency: firstOrCreate on idempotency_key
   │  • inserts FleetSignalOutbox row, dispatches DispatchFleetSignalOutbox
   ▼
DispatchFleetSignalOutbox (queued, $tries=3, backoff=[10,30,60])
   │
   ▼
SignalProcessingService::ingestFromFleetSignal → ::ingest → ::process
   │  • idempotency: unique constraint on idempotency_key with race fallback
   │  • severity normalisation via AlertSeverity::normalise
   │  • maintenance window suppression
   │  • SignalRule matching with deduplicate window
   ▼
ControlRoomAlert  (canonical operational alert)


                 (security/IoT device events)
DeviceEvent::create(...)  ── observed by DeviceEventObserver
   │  • heartbeat suppression (HEARTBEAT_FORWARD=false)
   │  • event_type → signal_type_code mapping (12 types + generic catch-all)
   │  • resolves CR Device projection via canonical_device_id
   ▼
SignalProcessingService::ingest → ::process
   ▼
ControlRoomAlert
   │
   ▼
DeviceSignalPublished event dispatch (cross-module listener hook)
```

### Tests (current state of the truth surfaces)

| Path | Files | Coverage |
|---|---|---|
| `tests/Feature/AssetControllerTest.php` | 1 | Asset CRUD, RBAC, validation, filter/search/pagination — solid |
| `tests/Feature/AssetTelemetryIngestTest.php` | 1 | Token-based ingest, snapshot writes |
| `tests/Feature/FleetTelemetryIngestTest.php` | 1 | Event creation, idempotency, geofence breach, trip start/stop, **consent masking** |
| `tests/Feature/Fleet/FleetManagementTest.php` | 1 | Fleet management dashboard / pages |
| `tests/Feature/FleetAssets/` | 3 | `FleetHandoverSiteIsolationTest`, `ResidentTransportMedicationTransitTest`, `VehiclePageContractTest` |
| `tests/Feature/SecurityDevices/` | 28 | Device CRUD + assignments + groups + asset-links + auto-rules + dashboard + maintenance + reports + alerts/events; **fleet/control-room/finance bridge tests**; canonical migration; consent precedence |
| `tests/Feature/ControlRoom/` | 23 | Alert lifecycle, escalation, SLA, evidence, playbook, settings, watchers, time-entries, reports, dashboard, broadcast, handover, incident |
| `tests/Unit/SecurityDevices/` | 8 | Device model, policy, taxonomy, registry, link, assignment, permissions, migrate command |
| `tests/Unit/ControlRoom/` | 3 | Signal processing (incl. transition), alert automation |
| `tests/Browser/Fleet/FleetTest.php` | 1 | 30+ Dusk tests asserting page-load only |
| `tests/Browser/Fleet/FleetPermissionsTest.php` | 1 | RBAC sanity for fleet pages |
| `tests/Browser/Assets/` | 2 | Asset show + inspection Dusk tests |
| `tests/e2e/control-room-*.spec.ts` | 3 | Smoke (incl. `/control-room/devices`), dashboard a11y, full alert lifecycle |

### Whether this is "partial because missing" or "partial because integration hardening is unfinished"

**The latter, with one important hardening item that is functionally a bug.**
Every truth surface in the cluster exists, is routed, has a controller, has at
least page-level tests, and is wired into the pipeline. The remaining gaps are
hardening: a confirmed bug on the cross-module alert bridge, two hardcoded
tenant IDs, missing browser/e2e workflow coverage for the new fleet-assets
device pages, an outbox dead-letter operator surface, one stale "coming soon"
note, and ambiguity around the parallel `/fleet-management/*` surface that
already has a top-level redirect but still serves children.

---

## 2. Production-Readiness Gap List

### Confirmed gaps (repo evidence cited)

#### C1 · Fleet alert acknowledge/resolve writes invalid status, bypasses canonical lifecycle

[`app/Http/Controllers/FleetAssets/AlertController.php:16-37, 135-158`](../app/Http/Controllers/FleetAssets/AlertController.php#L16)

```php
public function acknowledge(ControlRoomAlert $alert)
{
    $alert->update([
        'status' => 'acknowledged',          // ← invalid; canonical is 'ack'
        'acknowledged_at' => now(),
    ]);
    return back();
}

// bulkAction also writes 'acknowledged'
case 'acknowledge':
    $updateData = ['status' => 'acknowledged', 'acknowledged_at' => now()];
```

But [`app/Models/ControlRoomAlert.php:21-27`](../app/Models/ControlRoomAlert.php#L21):

```php
public const VALID_STATUSES = [
    self::STATUS_OPEN,        // 'open'
    self::STATUS_ACK,         // 'ack'      ← canonical
    self::STATUS_TRIAGING,
    self::STATUS_RESOLVED,
    self::STATUS_CLOSED,
];
```

The `creating` hook coerces invalid statuses, but the `updating` hook does
not — `update(['status' => 'acknowledged'])` writes the literal string `acknowledged`
to the DB, breaking every downstream consumer that expects `ack`. Concrete
fallout:
- [`ControlRoomDashboardController.php:128`](../app/Http/Controllers/ControlRoom/ControlRoomDashboardController.php#L128)
  counts `where('status', 'ack')` → KPI undercounts after a fleet ack.
- The status enum becomes split-brain: an alert acked on the fleet page can
  never be resolved through the canonical `ControlRoomAlertController::resolve`,
  because `canTransitionTo('resolved')` looks at
  `ALLOWED_TRANSITIONS['acknowledged']` which is `null`.
- `acknowledged_by_user_id` is never set, breaking attribution.
- SLA `recordAcknowledge()` is never called, breaking SLA accounting.
- `AuditLogger::log('controlRoom.alert.acknowledge', ...)` is never called,
  breaking the audit trail.

The `resolve()` method has the same skip-the-lifecycle problem: no
`resolved_by_user_id`, no SLA recording, no audit log, no `canTransitionTo`
validation, no required `resolution_notes`. Compare with
[`ControlRoomAlertController.php:670-694`](../app/Http/Controllers/ControlRoom/ControlRoomAlertController.php#L670)
which does it correctly.

**Severity:** P0 — silent state corruption + permission bypass on a
production-bound surface. Has zero feature-test coverage.

#### C2 · Fleet alert bridge skips the canonical permission and access guard

Routes [`routes/fleet-assets.php:86-91`](../routes/fleet-assets.php#L86):

```php
Route::middleware('permission:fleet.manage|assets.alerts.manage')->group(function () {
    Route::post('/alerts/bulk-action', [AlertController::class, 'bulkAction'])
    Route::post('/alerts/{alert}/acknowledge', ...)
    Route::post('/alerts/{alert}/resolve', ...)
});
```

But the canonical Control Room manage permission is `controlRoom.alerts.manage`
([`routes/control-room.php:87`](../routes/control-room.php#L87)), and the
canonical controller additionally enforces `assertCanAccessAlert($user, $alert)`
which scopes by site/tenant. The fleet bridge does neither. A user with
`fleet.manage` but no `controlRoom.*` can mutate a CR alert outside their
permitted scope.

**Severity:** P0 — RBAC lattice violation alongside C1.

#### C3 · Hardcoded `tenant_id => 1` in canonical Device + DeviceGroup creation

[`app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:427`](../app/Domain/SecurityDevices/Http/Controllers/DeviceController.php#L427)
and [`DeviceGroupController.php:139`](../app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php#L139):

```php
$validated['tenant_id'] = 1;
```

The Queclink integration controller resolves it correctly:
[`QueclinkController.php:240`](../app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkController.php#L240):

```php
return (int) ($user->tenant_id ?? $user->organization_id ?? 1);
```

If/when the system runs multi-tenant in production, every manually-created
device would land in tenant 1.

**Severity:** P1 — single-tenant deployment makes this latent today, but
silently breaks isolation the day a second tenant joins.

#### C4 · Stale "File uploads coming soon" placeholder in work-order create

[`resources/js/pages/fleet-assets/maintenance/work-orders/create.tsx:303-311`](../resources/js/pages/fleet-assets/maintenance/work-orders/create.tsx#L303):

```tsx
<div className="flex flex-col items-center justify-center rounded-lg border-2 border-dashed border-muted-foreground/25 py-8">
    <ImagePlus className="mb-2 h-8 w-8 text-muted-foreground/40" />
    <p className="text-sm text-muted-foreground">Drag and drop photos or files here</p>
    <p className="mt-1 text-xs text-muted-foreground/60">File uploads coming soon</p>
</div>
```

A non-functional drop zone is rendered on a user-facing form with no upstream
support. Either remove the section, or wire it to `DeviceDocumentController` /
a fleet-asset document service. **No feature test exists** that the work-order
create form actually accepts uploads.

**Severity:** P1 — false UI affordance; production users may try to drop a
photo and silently lose it.

#### C5 · No FleetAssets/AlertController feature tests

[`tests/Feature/FleetAssets/`](../tests/Feature/FleetAssets) contains only
`FleetHandoverSiteIsolationTest`, `ResidentTransportMedicationTransitTest`,
`VehiclePageContractTest`. There is no `AlertControllerTest` —
which is exactly how C1 and C2 made it past the existing suite. The bulk-action
acknowledge/resolve, the per-alert acknowledge/resolve, and the alert index's
two-list (canonical + archived) surface have zero coverage.

Same for [`FleetAssets/DeviceController`](../app/Http/Controllers/FleetAssets/DeviceController.php) —
the `pair`, `unpair`, `consentIndex`, `grantConsent`, `revokeConsent` actions
are partially covered by [`FleetDeviceRefactorTest`](../tests/Feature/SecurityDevices/FleetDeviceRefactorTest.php)
but several edge cases (concurrent pair, tenancy mismatch, consent revocation
with both assignment + tracker consents present, withdrawal email idempotency)
are untested.

**Severity:** P1 — the proximate cause of every gap above is missing test
coverage on a critical bridge.

#### C6 · Telemetry ingest authenticator is a single shared token, no rotation, no replay defence

[`app/Http/Controllers/AssetTelemetryIngestController.php:17-28`](../app/Http/Controllers/AssetTelemetryIngestController.php#L17):

```php
$token = $request->header('X-Telemetry-Token');
$expectedToken = config('services.telemetry.ingest_token');
$authorized = $user && $user->canDo('assets.telemetry.ingest');
if (!$authorized && $expectedToken && $token === $expectedToken) {
    $authorized = true;
}
```

[`config/services.php:58-60`](../config/services.php#L58):

```php
'telemetry' => [
    'ingest_token' => env('TELEMETRY_INGEST_TOKEN'),
],
```

A single global `TELEMETRY_INGEST_TOKEN` env variable secures the entire
public ingest endpoint. There is:
- no per-vendor / per-tenant key,
- no rotation surface,
- no IP allow-list option,
- no signature verification (HMAC of `(vendor, device_uid, occurred_at, body)`),
- no rate limit beyond `throttle:60,1` ([`routes/assets.php:28`](../routes/assets.php#L28)).

For NZ supported-living deployment this is acceptable as an MVP but should be
called out in the readiness checklist.

**Severity:** P1 — security-defence-in-depth gap for a public POST endpoint
that writes to several tables and triggers downstream alerting.

#### C7 · No outbox dead-letter operator surface

[`app/Jobs/DispatchFleetSignalOutbox.php:59-78`](../app/Jobs/DispatchFleetSignalOutbox.php#L59)
correctly handles permanent failure by setting `status=dead_letter` and
emitting a `Log::critical(...)` line. But there is **no admin page** to list
dead-lettered outbox rows, no retry button, no alert that fires when
dead-letter count grows. If a downstream signal-processor regression silently
fails, fleet alerts would stop creating and operators wouldn't know until they
manually checked the table.

**Severity:** P1 — observability/operability gap on a production-critical path.

#### C8 · Parallel `/fleet-management/*` legacy frontend surface still exists

[`resources/js/pages/fleet-management/`](../resources/js/pages/fleet-management/)
has six pages: `index.tsx`, `vehicle.tsx`, `trip.tsx`, `fuel.tsx`, `reports.tsx`,
`maps-usage.tsx`. These render via [`routes/fleet.php`](../routes/fleet.php)
at `/fleet-management`, `/fleet/vehicles/{asset}`, `/fleet/trips/{trip}`,
`/fleet/fuel`, `/fleet/reports`, `/fleet-management/maps-usage`. The
fleet-assets shell mounts a top-level redirect
([`routes/fleet-assets.php:311`](../routes/fleet-assets.php#L311)):

```php
Route::redirect('/fleet-management', '/fleet-assets');
```

…but only for the bare prefix. Children like `/fleet/vehicles/{asset}` and
`/fleet/trips/{trip}` still resolve via the standalone `Fleet*Controller`
classes ([`app/Http/Controllers/Fleet/`](../app/Http/Controllers/Fleet)),
parallel to the unified `FleetAssets/VehicleController` and
`FleetAssets/VehicleController::trips`. Two surfaces drift independently and
the question "where do I send users?" doesn't have an answer in the codebase.

**Severity:** P2 — duplication risk; not a bug today but a backlog of
divergence waiting to happen.

#### C9 · Stale stats expectation in FleetDeviceRefactorTest (low confidence — verify)

[`tests/Feature/SecurityDevices/FleetDeviceRefactorTest.php:103-108`](../tests/Feature/SecurityDevices/FleetDeviceRefactorTest.php#L103):

```php
$response->assertInertia(fn ($page) => $page
    ->where('stats.total', 4)
    ->where('stats.online', 3) // 2 active + 1 unpaired active + 1 paired active... wait, let me count
);
```

The inline comment ("wait, let me count") suggests the assertion was guessed,
not verified. With 3 active and 1 offline device the expectation `online: 3`
is plausible — but `stats.online` is computed in
[`FleetAssets/DeviceController.php:76`](../app/Http/Controllers/FleetAssets/DeviceController.php#L76)
as `where('status', DeviceStatus::Active->value)`, which would return 3. So
the assertion is likely correct, but the comment is not. This is a *code-hygiene
verification* item, not a confirmed bug.

**Severity:** Verification only — confirm this passes, then delete the inline
comment.

### Items needing verification (not yet confirmed gaps)

#### V1 · Fleet vehicles without a paired client get all telemetry consent-blocked

In [`FleetTelemetryIngestService.php:50-55`](../app/Services/Fleet/FleetTelemetryIngestService.php#L50)
the consent context is resolved through `FleetDeviceRuntimeService::resolveConsentContext`,
which finds a consent only if there's a `DeviceAssignment` to a client OR a
legacy `AssetTracker.consent_id`. For a vehicle that's just "the depot van"
(no client assignment, no tracker consent), the consent is `null`,
`$consentValid === false`, and lat/lng/etc. are nulled.

Verify this is the intended NZ IPP9 "fail-closed" posture, or whether assets
without a client should follow a different consent rule (e.g. an
`asset.requires_consent` flag, or operational vehicles being permanently
allowed). If "fail-closed" is intended, the **product decision should be
documented in the consent context** and a feature test should pin the
behaviour for assets with `client_id IS NULL`.

#### V2 · Stale `maps_usage` route + telemetry capture path

[`fleet-management/maps-usage.tsx`](../resources/js/pages/fleet-management/maps-usage.tsx)
plus `POST /fleet/maps/usage` ([`routes/fleet.php:45-46`](../routes/fleet.php#L45))
records map-tile usage to a backing table — verify the table is not unbounded,
verify retention is configured (likely covered by
`config/fleet.php:37 telemetry_days = 365` but that's for fleet telemetry, not
map usage).

#### V3 · `AssetGeofence.shape` polygon validation

`AssetGeofenceController::store` accepts arbitrary JSON for `shape` — the
[`FleetGeofenceService::pointInPolygon`](../app/Services/Fleet/FleetGeofenceService.php#L134)
silently drops malformed polygon vertices. A user submitting a degenerate
polygon (< 3 points, mismatched lat/lon keys) results in a geofence that
*never triggers*. Verify the controller validates `shape` structure on
create/update.

#### V4 · `device_uid` uniqueness conflict between canonical Device and CR Device

[`devices.device_uid UNIQUE`](../database/migrations/2026_04_14_000001_create_security_devices_tables.php#L24)
is per-table; `control_room_devices.device_uid UNIQUE`
([`migration:218`](../database/migrations/2026_02_04_000300_create_control_room_operations.php#L218))
is also per-table. The two tables have independent UID spaces. The CR Device
projection bridge `canonical_device_id` resolves the relationship cleanly, but
operators looking at "device foo" in the CR UI vs. Security & Devices UI may
see different `device_uid` strings. Verify operator workflow shows both UIDs
where it matters (linked detail, search).

#### V5 · Finance fixed-asset → operational asset link is one-way and unsynced

[`FixedAssetController.php:108`](../app/Domain/Finance/Http/Controllers/FixedAssetController.php#L108)
captures `linked_asset_id` (operational asset FK) on store. Verify:
1. There's no automatic sync when an operational asset is retired — the
   fixed-asset row keeps pointing at a deleted asset.
2. Disposal of a fixed asset doesn't prompt to retire the linked operational
   asset.
3. There's no "linked from finance" hint on the operational asset detail page
   ([`assets/show.tsx`](../resources/js/pages/assets/show.tsx)).

This is a wiring observation, not a bug — but worth confirming the desired
behaviour with finance ownership.

#### V6 · `AssetController::destroy` hard-deletes; no soft delete on Asset model

[`AssetController.php:372-384`](../app/Http/Controllers/AssetController.php#L372)
calls `$asset->delete()` and the `Asset` model does **not** use `SoftDeletes`
([`Asset.php:23`](../app/Models/Asset.php#L23)). A deleted asset takes its
QR token, scan history, telemetry snapshots, and finance link with it (or
cascades / orphans depending on FK rules). Verify whether this is the
intended retention posture or whether soft delete should be added before
production.

---

## 3. Minimal Implementation Plan

PR sizes use the same legend as other readiness plans: S ≤ 1d, M 1–3d, L 3–5d.

### Phase 1 — P0 fixes (the bridge bugs) [S]

#### PR FA1 — Make Fleet alert bridge use canonical lifecycle

**Goal:** Fleet alert acknowledge / resolve / bulk-action go through the
canonical `ControlRoomAlertController` lifecycle (proper status, transitions,
audit, SLA, permissions). Fixes C1 and C2.

**Approach (cheapest):** delete the local `acknowledge` / `resolve` /
`bulkAction` write path in `FleetAssets/AlertController` and redirect those
routes at the controller level to the canonical `ControlRoomAlertController`
methods. The fleet `index` page stays — it's the unified view — but mutation
goes through the canonical surface.

**Files likely to change:**
- [`app/Http/Controllers/FleetAssets/AlertController.php`](../app/Http/Controllers/FleetAssets/AlertController.php) —
  delete `acknowledge`, `resolve`, `bulkAction` methods or thin them to
  proxies that call the canonical controller's methods.
- [`routes/fleet-assets.php:86-91`](../routes/fleet-assets.php#L86) — change
  the permission gate from `fleet.manage|assets.alerts.manage` to
  `controlRoom.alerts.manage` (or keep dual gating for the fleet UX entry
  point but route through the canonical controller method).
- (Optional) [`resources/js/pages/fleet-assets/alerts/index.tsx`](../resources/js/pages/fleet-assets/alerts/index.tsx) —
  if the bulk action endpoints change, update fetch URLs.

**Acceptance criteria:**
- Acking an alert from `/fleet-assets/alerts` writes `status='ack'` (canonical),
  sets `acknowledged_by_user_id`, calls `sla?->recordAcknowledge()`, calls
  `AuditLogger::log('controlRoom.alert.acknowledge', ...)`.
- Resolving requires `resolution_notes`, sets `resolved_by_user_id`, calls
  `sla?->recordResolution()`, audit logs.
- A user without `controlRoom.alerts.manage` cannot mutate via fleet routes.
- Bulk action 422s with the canonical validation rules.
- Existing assets/fleet UX (the alerts list page) continues to render
  unchanged.

**Tests to add or run:**
- New `tests/Feature/FleetAssets/AlertControllerTest.php`:
  - acknowledge writes `'ack'` not `'acknowledged'`
  - acknowledge sets `acknowledged_by_user_id`
  - resolve requires `resolution_notes`
  - resolve sets `resolved_by_user_id`
  - bulk-action acknowledge writes `'ack'` to all targets
  - permission denial: `fleet.manage` alone is not sufficient
  - audit log entry is created
- Re-run [`tests/Feature/SecurityDevices/AssetAlertArchiveTest.php`](../tests/Feature/SecurityDevices/AssetAlertArchiveTest.php)
  to confirm the fleet alert index still mixes canonical + archived correctly.

**Risk:** Low. The bug today is silent corruption; routing through the
canonical controller restores correctness with no UX change. Mild risk: if
some user has `fleet.manage` without `controlRoom.alerts.manage`, the change
will surface as a 403 — accept this; the previous behaviour was a security
violation.

---

### Phase 2 — Hardening the canonical Device flow [S]

#### PR FA2 — Resolve `tenant_id` from the user instead of hardcoding `1`

**Goal:** Fix C3. Multi-tenant correctness.

**Files likely to change:**
- [`app/Domain/SecurityDevices/Http/Controllers/DeviceController.php:427`](../app/Domain/SecurityDevices/Http/Controllers/DeviceController.php#L427)
- [`app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php:139`](../app/Domain/SecurityDevices/Http/Controllers/DeviceGroupController.php#L139)
- (Optional) extract a `resolveTenantId(User)` helper trait, since
  `QueclinkController`, `MilesightController`, `UnifiController` already do
  the same `($user->tenant_id ?? $user->organization_id ?? 1)` pattern.

**Acceptance criteria:**
- Devices and device groups created via the UI use the authenticated user's
  tenant.
- Existing single-tenant tests (`Device::factory()` without tenant override)
  still pass — the factory uses `tenant_id = 1` by default which matches
  test users.

**Tests to add:**
- Append two assertions to [`DeviceControllerTest`](../tests/Feature/SecurityDevices/DeviceControllerTest.php)
  (and `DeviceGroupControllerTest`) verifying the created row's
  `tenant_id === $user->tenant_id`.

**Risk:** Low. The existing `User::tenant_id` column is the source-of-truth
in single-tenant; this just pushes it through.

---

### Phase 3 — Test + browser coverage uplift [M]

#### PR FA3 — FleetAssets feature tests for alerts, devices, consent

**Goal:** Close C5. The bridge points have no test coverage; this is what
allowed C1/C2 to ship.

**Files to add:**
- `tests/Feature/FleetAssets/AlertControllerTest.php` (depends on PR FA1)
- `tests/Feature/FleetAssets/DeviceControllerTest.php` covering:
  - `index` lists only `domain='tracking'` devices
  - `pair` rejects already-linked device, allows re-pair to same asset
  - `unpair` unlinks `device_asset_links` not `asset_trackers`
  - `consentIndex` prefers assignment consent over tracker consent
  - `grantConsent` requires a linked client
  - `grantConsent` supersedes prior consents via `superseded_by_consent_id`
  - `revokeConsent` writes withdrawn status to both consent rows when
    distinct
  - audit logger fires on each mutation

**Acceptance criteria:** Both files green; `php artisan test --filter=FleetAssets`
adds ≥ 14 new test methods.

**Tests to run:**
- `php artisan test --filter=FleetAssets`
- `php artisan test --filter=SecurityDevices`
- `php artisan test --filter=ControlRoom`

**Risk:** Low. Pure additive coverage.

#### PR FA4 — Playwright e2e for fleet-assets, security-devices, asset-tracker pairing

**Goal:** Modern e2e parity for the fleet/SD/CR-devices areas, replacing
read-only Dusk page-load smoke with workflow-level Playwright specs (matching
the precedent set by `tests/e2e/control-room-*.spec.ts`).

**Files to add (one spec per surface):**
- `tests/e2e/fleet-assets-smoke.spec.ts` — page-load + a11y for
  dashboard, map, vehicles, drivers, bookings, devices, geofences,
  maintenance dashboard, alerts, mobile dashboard, settings notifications.
- `tests/e2e/fleet-assets-device-pairing.spec.ts` — pair / unpair / consent
  grant / consent revoke against a seeded canonical Device + Asset.
- `tests/e2e/security-devices-smoke.spec.ts` — `/security-devices` dashboard,
  category pages, devices index/show, device-groups, alerts-events,
  maintenance-health, integrations hub, reports.
- `tests/e2e/security-devices-integrations.spec.ts` — UniFi credential save
  → test → rotate flow (mock the adapter at the registry level).

**Acceptance criteria:**
- `npm run visual:test -- --grep "fleet|security-devices"` passes on the
  desktop project; mobile project covers smoke only (mirrors the CR
  pattern).
- Each spec asserts no blocking axe violations on at least one page (the
  module dashboard) — same gate as `control-room-smoke`.

**Risk:** Medium. e2e specs can be flaky against a fresh DB; the existing
`tests/e2e/helpers.ts` `loginAsStaff` plus the `RbacSeeder` /
`SecurityDevicesPermissionsSeeder` should be sufficient. Mirror the
control-room-alert-lifecycle test's "create your own seed via API" pattern
where possible, to avoid coupling to demo data.

---

### Phase 4 — Operator surfaces for outbox + telemetry visibility [M]

#### PR FA5 — Outbox dead-letter admin surface + retry action

**Goal:** Close C7. Operators need to see when the fleet signal pipeline is
losing rows.

**Approach:** add a section to `/control-room/settings` (canonical settings
page) listing the last 100 outbox rows with `status IN ('failed', 'dead_letter')`,
plus a "Retry" button that re-dispatches `DispatchFleetSignalOutbox` for
that outbox row.

**Files likely to change:**
- [`app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php`](../app/Http/Controllers/ControlRoom/ControlRoomSettingsController.php) —
  add `outboxStatus` and `retryOutbox` actions.
- [`routes/control-room.php`](../routes/control-room.php) — add two routes
  inside the existing `controlRoom.alerts.manage` permission group.
- [`resources/js/pages/control-room/settings/`](../resources/js/pages/control-room/settings) —
  add a "Signal Outbox" tab.

**Acceptance criteria:**
- Failed / dead-letter rows are listed.
- Retry button re-runs the job; on success, the row flips to `sent`.
- An audit log entry records the retry.

**Tests to add:**
- `tests/Feature/ControlRoom/SignalOutboxControllerTest.php` — list returns
  failed rows, retry dispatches the job, permission check 403s for
  non-managers.

**Risk:** Low–Medium. The retry path runs the same code as the auto-retry,
so risk is bounded. Add a 5-minute throttle on retry per outbox row to
prevent operator hammering.

---

### Phase 5 — UI / UX cleanups [S]

#### PR FA6 — Remove "File uploads coming soon" + decide on `/fleet-management/*` legacy surfaces

**Goal:** Close C4 and C8.

**Files likely to change:**
- [`resources/js/pages/fleet-assets/maintenance/work-orders/create.tsx:303-311`](../resources/js/pages/fleet-assets/maintenance/work-orders/create.tsx#L303) —
  delete the placeholder section. (Wiring real uploads is out of scope; if
  product wants uploads, that's a separate PR.)
- [`routes/fleet.php`](../routes/fleet.php) — for each duplicated child
  surface (`/fleet/vehicles/{asset}`, `/fleet/trips/{trip}`, `/fleet/fuel`,
  `/fleet/reports`, `/fleet-management/maps-usage`), decide one of:
  (a) replace the route with a redirect to its `/fleet-assets/*` equivalent,
  (b) delete the route + remove the corresponding `fleet-management/*.tsx`,
  or (c) leave as-is and add an explicit comment that this is the
  authoritative version.
- [`resources/js/pages/fleet-management/`](../resources/js/pages/fleet-management/) —
  delete the pages whose routes were redirected/removed.

**Acceptance criteria:**
- No "coming soon" placeholders surface in any user-facing fleet/SD page
  (verify with grep on every file under `resources/js/pages/{assets,fleet-assets,security-devices,fleet-management,control-room/devices}`).
- Each `/fleet/*` child has either a redirect, a deletion, or a docblock
  declaring it canonical.

**Tests to run:**
- `php artisan route:list --path=fleet`
- `php artisan route:list --path=fleet-assets`
- `npm run build` to confirm no broken imports.
- Existing `tests/Browser/Fleet/FleetTest.php` should still pass (it
  exercises both paths today; if a route is redirected, update the Dusk
  assertion accordingly).

**Risk:** Low. This is consolidation work with no schema impact.

---

### Phase 6 — Telemetry hardening (optional, environment-dependent) [M]

#### PR FA7 — Per-tenant telemetry tokens + signature option

**Goal:** Close C6.

**Approach:**
- Add `telemetry_tenant_tokens` table: `(id, tenant_id, vendor, token_hash,
  token_last4, status, last_used_at, last_used_ip, rotated_at, created_by, created_at)`.
- New service `App\Services\Fleet\TelemetryAuthService` resolves token →
  `(tenant_id, vendor)`, falls back to the global `TELEMETRY_INGEST_TOKEN`
  for backward compatibility.
- Add an admin surface under `/security-devices/integrations` to list,
  create, rotate tokens.
- Optional second factor: HMAC signature on the request body, header
  `X-Telemetry-Signature`, secret per token. Adapter-side only — most
  vendors don't support it without bridge software.

**Acceptance criteria:**
- Existing `TELEMETRY_INGEST_TOKEN` continues to work (backward compat).
- New per-tenant tokens authenticate and scope ingest to that tenant's
  trackers.
- Rate limit applied per token, not just per IP.

**Tests to add:**
- `AssetTelemetryIngestTokenTest` — global token still works, per-tenant
  token works, mismatched-tenant token rejected, rotated token invalidated.

**Risk:** Medium. Adds a new auth path; protect with comprehensive tests
before flipping the doc baseline. **Defer until production deployment date
is set** — this is a hardening item, not a blocker for a controlled rollout.

---

## 4. What Not To Touch

These are the surfaces that are working and should be preserved as-is:

1. **Canonical Device + DeviceAssetLink + DeviceAssignment models and the
   bridge docblocks on `AssetTracker`, `LocationHardware`,
   `ControlRoom\Device`.** The bridges are *intentionally retained*; their
   docblocks call out exactly which paths still depend on them. Don't try
   to delete the legacy models in this readiness pass — that's a separate
   migration plan ([`security-devices-restructure-plan.md`](security-devices-restructure-plan.md)
   covers the longer-tail consolidation).
2. **The telemetry idempotency hash builder
   ([`FleetTelemetryIngestService::buildIdempotencyKey`](../app/Services/Fleet/FleetTelemetryIngestService.php#L208)).**
   The hash inputs are deliberate; changes break replay-safety. If you add
   a new field, change `:hash + version` not the hash content.
3. **`SignalProcessingService::ingest` race-condition fallback
   ([`:96-105`](../app/Services/ControlRoom/SignalProcessingService.php#L96)).**
   The unique-index + try/catch + re-fetch pattern is correct;
   refactoring it into a single `firstOrCreate` would lose the explicit
   race fallback.
4. **`DeviceEventObserver::HEARTBEAT_FORWARD = false`
   ([`:30`](../app/Observers/DeviceEventObserver.php#L30)).** Don't flip
   this without a load test — heartbeats are O(devices/minute) and would
   flood the signal pipeline.
5. **The Asset alert archive read-only mode
   ([`AssetAlertController.php`](../app/Http/Controllers/AssetAlertController.php),
   covered by [`AssetAlertArchiveTest`](../tests/Feature/SecurityDevices/AssetAlertArchiveTest.php)).**
   The legacy `/assets/alerts` page is correct as-is. Do not re-enable
   write actions on this surface; route mutation through `ControlRoomAlert`.
6. **Consent gate ordering in `FleetTelemetryIngestService`.** The check
   happens *after* tracker resolution and *before* the DB transaction so
   that even on consent block we still record `consent_blocked=1` rows for
   audit. Don't move the check.
7. **`fleet_signals.idempotency_key` uniqueness contract** — used by
   `FleetGeofenceService` to dedupe dwell signals via a synthetic key
   ([`FleetGeofenceService.php:80-85`](../app/Services/Fleet/FleetGeofenceService.php#L80)).

### Tempting rewrites to avoid

- **"Just merge `fleet.php` into `fleet-assets.php`."** The `/fleet-management`
  legacy surface has live customers in dev fixtures. Phase 5 (PR FA6) handles
  the consolidation by *redirecting child routes* one at a time, rather than
  by deleting the routes file outright.
- **"Decommission `AssetTracker` entirely."** It's still the lookup key for
  `FleetTelemetryIngestService::ingest` (`vendor + device_uid` index).
  Removing it requires a migration of the ingest service to canonical Device
  resolution first; the docblock at [`AssetTracker.php:9-27`](../app/Models/AssetTracker.php#L9)
  already lists the four remaining bridge consumers.
- **"Replace `AssetAlert` with `ControlRoomAlert` everywhere."** Already
  done — it's read-only archive. The remaining `AssetAlert` factory usages
  (in `AssetAlertArchiveTest` and similar) are intentional regression
  fixtures, not new writes.
- **"Replace `ControlRoom\Device` with the canonical `Device`."** It's a
  projection by design ([`Device.php:10-29`](../app/Models/ControlRoom/Device.php#L10));
  signals reference it via `device_id`, alerts reference it via `device_id`,
  and the canonicalDevice() bridge is documented and tested.
- **"Stop hardcoding `tenant_id=1` everywhere."** Yes — but limit it to PR
  FA2's two specific call sites. Don't try to refactor every consumer in one
  PR; the system runs single-tenant today and over-eager refactoring will
  break tests that pass `tenant_id=1` implicitly.

---

## 5. Verification Plan

### PHP — feature & unit

```bash
# Whole-area regression — all green required to ship the readiness work
php artisan test --filter=FleetAssets
php artisan test --filter=SecurityDevices
php artisan test --filter=ControlRoom
php artisan test --filter=AssetController
php artisan test --filter=AssetTelemetryIngest
php artisan test --filter=FleetTelemetryIngest
php artisan test --filter=Fleet/

# Spot-checks for the canonical bridges that should not have regressed
php artisan test --filter=DeviceEventSignalPipelineTest
php artisan test --filter=ControlRoomDeviceProjectionTest
php artisan test --filter=AssetAlertArchiveTest
php artisan test --filter=FleetDeviceRefactorTest
```

### TypeScript / build

```bash
npm run types
npm run build
```

### Browser — Dusk (existing) + Playwright (new in PR FA4)

```bash
# Existing Dusk page-load smoke
php artisan dusk --filter=FleetTest
php artisan dusk --filter=FleetPermissionsTest
php artisan dusk --filter=AssetTest
php artisan dusk --filter=AssetShowInspectionTest

# Playwright (existing CR + new fleet/SD specs after PR FA4)
npm run visual:test -- --grep "control room|fleet-assets|security-devices"
```

### Route surface checks

```bash
# Confirm every routed path resolves and is permission-gated
php artisan route:list --path=assets
php artisan route:list --path=fleet
php artisan route:list --path=fleet-assets
php artisan route:list --path=security-devices
php artisan route:list --path=control-room

# Quick drift check that no controller method is unrouted
php artisan route:list --columns=method,uri,name,middleware --json | jq '.[] | select(.uri | test("^(assets|fleet|fleet-assets|security-devices|control-room)"))' | wc -l
```

Expected: routes match the count of controller actions in
`app/Http/Controllers/{Fleet,FleetAssets}` and `app/Domain/SecurityDevices/Http/Controllers`
plus `app/Http/Controllers/Asset*.php`. (Spot-check after each phase rather
than as a hard test — the count drifts across feature work.)

### Diff sanity

```bash
git diff --check
git diff --stat
```

Confirm:
- No file outside the module surfaces touched (PR FA5 may touch
  `routes/control-room.php` and the CR settings controller; that's expected).
- No migration files added.
- No package.json / composer.json drift.

---

## 6. Final Recommendation

**Verdict: safe to harden incrementally. No rewrite, no schema change.**

Repo evidence supporting this verdict:

1. The canonical Security & Devices registry exists, is the documented source
   of truth, and is wired through the telemetry → signal → alert pipeline
   end-to-end with idempotency enforced at three places (DB unique index,
   service-level firstOrCreate, controller-level race fallback).
2. All known legacy bridges (`AssetTracker`, `LocationHardware`,
   `ControlRoom\Device`, `AssetAlert`) carry explicit deprecation docblocks
   naming the remaining live consumers — i.e. they are *retained on purpose*,
   not orphaned.
3. The cross-module observer (`DeviceEventObserver`) is registered, has
   heartbeat suppression, has 4 feature tests on its happy path and catch-all
   ([`DeviceEventSignalPipelineTest`](../tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php)),
   and emits a public extension event (`DeviceSignalPublished`).
4. Consent enforcement runs *inside* the ingest hot path with `consent_blocked`
   recorded for audit, plus a feature test (`FleetTelemetryIngestTest::test_consent_masking_blocks_location`).
5. Permissions are seeded ([`SecurityDevicesPermissionsSeeder`](../database/seeders/SecurityDevicesPermissionsSeeder.php)
   defines 10 keys; route files use them consistently).
6. The Control Room module already shipped its own readiness pass
   ([`docs/control-room-readiness-plan.md`](control-room-readiness-plan.md))
   covering the alert lifecycle, which is the most-coupled downstream surface.

The work that remains is six PR-sized phases:

| Phase | PR | Size | Closes |
|---|---|---|---|
| 1 | FA1 — Fleet alert bridge → canonical lifecycle | S | C1, C2 |
| 2 | FA2 — Resolve tenant_id from user | S | C3 |
| 3 | FA3 — FleetAssets feature tests | M | C5 |
| 3 | FA4 — Playwright e2e for fleet/SD/devices | M | (coverage) |
| 4 | FA5 — Outbox dead-letter operator surface | M | C7 |
| 5 | FA6 — Remove placeholder + reconcile fleet-management duplicates | S | C4, C8 |
| 6 | FA7 — Per-tenant telemetry tokens (defer) | M | C6 |

Phases 1 and 2 are P0/P1 fixes that should land before any rollout. Phase 3
is the regression net that prevents the next C1-style silent corruption.
Phases 4-5 are operator-side polish. Phase 6 is environment-dependent
hardening that should ride the production-deployment plan, not the readiness
plan.

The module is in good shape for incremental hardening. No deeper discovery
is required.
