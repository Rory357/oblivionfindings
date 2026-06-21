# Audit 01 — Resident Tracking feature (the mirror to copy for STAFF)

Goal: build a "lone worker / staff GPS-tracker" that mirrors RESIDENT tracking, but
targets a STAFF user (lone worker). Surface last-known location + "Locate now" on the
Lone Worker Safety detail (`/health-safety/lone-workers`), and route a tracker
PANIC / MAN-DOWN into `LoneWorkerSignalService::emitEmergency` → Control Room.

This document maps the existing resident tracking end-to-end so it can be copied.

---

## 0. Headline reuse finding — the device spine is ALREADY staff-aware

The whole device/assignment/Queclink layer already supports STAFF as a first-class
assignment target. **The staff lone-worker pairing path largely already exists.**

- `app/Domain/SecurityDevices/Models/DeviceAssignment.php:48` —
  `public const TARGET_STAFF = 'staff';` and it is in `VALID_TARGETS` (line 55).
  `assignable()` resolves `TARGET_STAFF => User::find($this->assignable_id)` (line 90).
- `app/Domain/SecurityDevices/Services/DeviceRegistryService.php:73` —
  `forStaff(int $tenantId, int $userId): Builder` **already implemented** (mirror of
  `forClient()` at line 50). Returns devices with an active assignment to a staff user.
- `app/Services/Queclink/LocateNowService.php:85` — `familyFor()` already special-cases
  category `lone_worker_tracker` / `personal_tracker` → `FAMILY_GL30M`. **LocateNowService
  is device-centric (`queueForDevice(Device, User)`), NOT client-centric — reuse as-is.**
- `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php`
  already pairs staff trackers: `claimDevice()` validates
  `pairing_type in:vehicle,staff,client` (line 175); `ensurePersonalTrackerAsset(type:'staff', ...)`
  keys on `Asset.primary_driver_user_id` (lines 1195-1219); `ensureCanonicalDevice()`
  names staff "Lone-worker tracker {imei}" with category `personal_tracker`, domain
  `tracking` (lines 1248-1257); and writes a `DeviceAssignment` with
  `assignable_type='staff'` (lines 208-216). **No consent_id required for staff** (consent
  gate is client-only — see §3).

Implication: most of the BUILD is a thin staff-facing read/serialise layer + a panic→
emergency routing branch. The hard infra (pairing, telemetry ingest, Locate-now command,
geofence eval) is shared.

---

## 1. The RESIDENT controller — `app/Http/Controllers/FleetAssets/ResidentTrackingController.php`

Constructor DI (line 28): `DeviceRegistryService $registry`, `DeviceAssignmentService $assignmentService`,
`GeofenceStatusService $geofenceStatus`. Plus `LocateNowService` injected per-action.

### Actions (every one)

| Method | Signature | Route | Perm | What it does |
|---|---|---|---|---|
| `index` | `(Request)` | GET `/fleet-assets/resident-tracking` `fleet-assets.resident-tracking.index` | `fleet.viewAny\|assets.viewAny` | Dashboard. Loads `Device::where('domain','tracking')->whereHas('assignments', active + assignable_type='client')`, filters to authorised clients, builds `residents[]` via `buildResidentPayload`, computes stats (tracked/online/offline/in_geofence/low_battery/safety_score/panic_active), recent CR alerts (`source in ['tracker','resident_tracker','geofence']`), active outings, map geofences, `focus_client_id`. Renders `fleet-assets/resident-tracking/index`. |
| `assignPage` | `(Request)` | GET `/resident-tracking/assign` `...assign` | `fleet.viewAny\|assets.viewAny` | Pairing UI data: available clients (+`is_tracked`), available trackers (unassigned tracking devices), currently-assigned trackers. Renders `fleet-assets/resident-tracking/assign`. |
| `assign` | `(Request)` | POST `/resident-tracking/assign` `...assign.store` | **`fleet.manage`** | Validates `tracker_id`, `client_id`, optional `consent_id`; resolves consent (latest given/non-withdrawn `ClientConsent`); calls `assignmentService->assign(device, 'client', client_id, userId, consentId)`. |
| `unassign` | `(Device $device)` | POST `/resident-tracking/{device}/unassign` `...unassign` | **`fleet.manage`** | `assignmentService->release($device, auth()->id())`. |
| `history` | `(Request, Client)` | GET `/resident-tracking/history/{client}` `...history` | `fleet.viewAny\|assets.viewAny` | Movement-history page. Resolves device via `registry->forClient()->where('domain','tracking')`. Range pills today/24h/7d/30d/custom (`resolveHistoryRange`). Calls `IntegrationEventHistoryService->forDevice($device, $filters, true)`. Renders `fleet-assets/resident-tracking/history`. |
| `locateNow` | `(Request, Client, LocateNowService)` | POST `/resident-tracking/{client}/locate-now` `...locate-now` | `fleet.viewAny\|assets.viewAny` (+ per-client authz `abort_unless`) | **The Locate-now action.** authz check via `getAuthorizedClientIds`; resolve device; if none → `ValidationException` "no paired Queclink tracker"; else `$locateNow->queueForDevice($device, $user)`; `back()->with('success', ...)`. |
| `acknowledgePanic` | `(Request, Client)` | POST `/resident-tracking/{client}/acknowledge-panic` `...acknowledge-panic` | **`fleet.manage`** (+ per-client authz) | Clears `device.meta.panic_active=false` + stamps ack; updates CR alerts (`source in ['tracker','resident_tracker']`, status open/triaging → `ack`). |

### Key private helpers (copy targets)
- `buildResidentPayload(Device $device, Client $client, array $activeOutingClientIds): array`
  (line 598) — **the canonical payload shape**. Pulls `lat/lng` from `device.latitude/longitude`
  or `meta.lat/lng`; `address` from `latestAddressForDevice` (latest `FleetTelemetryEvent` with
  coords+address, `consent_blocked=false`); battery (`resolveBatteryLevel` + threshold + status);
  geofence status via `geofenceStatus->evaluate($lat,$lng,$client->houseGeofence)`; status mapping
  (`active`→online); `panic_active`, `last_safety_event(_at)`; full device identity/connectivity
  block; **builds URLs**: `locate_now_url`, `acknowledge_panic_url` (named routes, `false` = path
  only), `profile_url`, `history_url`, `detail_url` (`/security-devices/devices/{id}`),
  `last_command_status` (`latestLocateCommandStatus`: latest `QueclinkPendingCommand` where
  `command_word='GTRTO'` for the device).
- `getAuthorizedClientIds($user): ?array` (line 465) — returns `null` (= see all) for
  admin/super-admin/manager OR `clients.viewAny`/`fleet.viewAny`/`assets.viewAny`; else union of
  `client_user` pivot + same-site clients. **Staff mirror = "which staff can this user track":**
  null for admins/managers/`fleet.viewAny`; otherwise typically just self.
- `serialiseGeofence`, `buildMapGeofences`, `geofenceAppliesTo`, `formatCoordinates`,
  `latestAddressForDevice`, `resolveBatteryLevel`, `isTruthy` — all reusable verbatim.

---

## 2. Routes

- **Fleet side** (`routes/fleet-assets.php:198-211`): under `prefix('fleet-assets')`,
  middleware `auth`. Reads gated `permission:fleet.viewAny|assets.viewAny`; writes
  (assign/unassign/acknowledge-panic) gated `permission:fleet.manage`. `{client}` and
  `{device}` are `->whereNumber(...)`.
- **Client-profile side** (`routes/operations.php:108-117`): under
  `permission:clients.viewAny|clients.viewAssigned`:
  - GET `/operations/clients/{client}/location/history` → `ClientController@locationHistory`
  - POST `/operations/clients/{client}/location/locate-now` → `ClientController@locateNow`
  - POST `/operations/clients/{client}/location/acknowledge-panic` → `ClientController@acknowledgePanic`
  (a portal variant exists: `routes/portal.php:117`.)

For STAFF, the analogous home is the **Lone Worker controller**
(`app/Http/Controllers/HealthSafety/LoneWorkerController.php`) and its routes (H&S group,
gated on `hazards.*` — see §6). Add `locate-now` / `loc-history` / `ack-panic` actions there,
OR add a tiny `StaffTrackingController` mirroring `ResidentTrackingController` under fleet-assets.

---

## 3. Supporting services (REUSE — already staff-capable)

- **`LocateNowService`** (`app/Services/Queclink/LocateNowService.php`) — `queueForDevice(Device, User)`
  resolves the `QueclinkDevice` (by `device_id`/imei/uid), asserts `isPaired()`, builds a
  `requestLocation` command via `CommandBuilder` (family from model hint / category), inserts a
  `QueclinkPendingCommand` (`command_word`, `status=QUEUED`, `expires_at=+5min`). **Device-centric
  → reuse unchanged for staff.** `queueForImei` also available.
- **`DeviceAssignmentService`** (`app/Domain/SecurityDevices/Services/DeviceAssignmentService.php`)
  — `assign(Device, assignableType, assignableId, assignedByUserId, AssignmentType, ..., consentId, notes)`.
  `validateTarget` accepts `'staff'`. `validateConsent` (line 124) **only requires consent for
  `TARGET_CLIENT` + domain tracking** → staff assignment needs NO consent_id. Releases prior active
  assignment first. `release()` / `transfer()` available.
- **`GeofenceStatusService`** (`app/Services/Tracking/GeofenceStatusService.php`) —
  `evaluate($lat,$lng,?AssetGeofence): 'in_zone'|'outside_zone'|'unknown'`. Circle (haversine) +
  polygon (ray-cast). Reuse as-is (staff geofence optional; pass `null` → `unknown`).
- **`DeviceRegistryService::forStaff($tenantId,$userId)`** — already exists; the staff equivalent of
  `forClient`.
- **`IntegrationEventHistoryService->forDevice($device, $filters, $bool)`** — device-centric history;
  reuse for staff movement history.

---

## 4. Telemetry ingest + PANIC path — `app/Services/Fleet/FleetTelemetryIngestService.php`

This is where inbound Queclink frames update the canonical `Device` and where SOS becomes a
Control-Room signal. **This is the file to extend for staff panic→emergency.**

- `ingest($vendor, $payload)`: normalises frame → resolves `AssetTracker` (status=paired) →
  resolves canonical `Device` (`deviceRuntime->resolveCanonicalDevice`) → consent gate
  (`consent_blocked`) → writes `FleetTelemetryEvent` + `AssetTelemetrySnapshot`.
- **Device meta write (lines 119-204)**: sets `last_seen_at`, `battery_level`/`battery_updated_at`,
  `meta.battery*`, and on SOS sets `meta.last_safety_event` (`man_down` | `vehicle_sos` via
  `normalisedSafetyEvent`, line 373), `meta.last_safety_event_at`, **`meta.panic_active = true`**;
  writes `latitude/longitude`/`meta.lat/lng/speed/heading/accuracy/...` when not consent-blocked.
  This is exactly what `buildResidentPayload` reads back — the staff payload reads the same fields.
- **SOS → signals (lines 244-277)**: when `sos_flag`, emits `vehicle.sos` (critical) via
  `FleetSignalService->emit([...])`; **and if `isResidentSafetyTracker($asset)` (line 350: true when
  `asset.client_id` set OR category `personal_tracker`) it ALSO emits `resident.sos`.**
  `tamper_flag` → `device.tamper`; `event_type='battery_low'` → `device.low_battery`.

### The staff gap (what to BUILD here)
A staff personal tracker is `Asset.category='personal_tracker'` with `primary_driver_user_id` set
(NOT `client_id`). Today `isResidentSafetyTracker()` would return **true** for it (category match),
so a staff pendant SOS currently mislabels as `resident.sos`. **Build a `lone_worker.sos` branch**:
detect staff trackers (asset has `primary_driver_user_id` and no `client_id`, or the device's active
`DeviceAssignment` is `assignable_type='staff'`), find the user's active `LoneWorkerSession`, and route
into the canonical lone-worker pipeline — ideally by calling
`LoneWorkerSignalService::emitEmergency($session, $notes)` (see §5) rather than emitting a raw
`resident.sos`. Prefer reusing the existing `LoneWorkerSignalService` so the alert lands as
`ControlRoomAlert source='lone_worker'` and shows in the existing Lone Worker "Alerts" tab.

---

## 5. The emergency pipeline target — `app/Services/HealthSafety/LoneWorkerSignalService.php`

- `emitEmergency(LoneWorkerSession $session, ?string $notes = null): void` (line 35) — the single
  entry point requested. Emits `TYPE_EMERGENCY = 'lone_worker_emergency'`, severity
  `AlertSeverity::CRITICAL`, title "Lone worker emergency: {worker name}", with
  `emergency_notes` + `emergency_triggered_at` context.
- Core `emit()` builds a signal (`signal_type_code`, idempotency key 15-min window for emergency,
  `site_id`, `client_id`, `severity_hint`, `normalized_data` carrying
  `lone_worker_session_id`/`worker_user_id`/`worker_name`/`location(_lat/_lng)`/timestamps) and runs
  `SignalProcessingService::ingest()` then `process()` → creates the `ControlRoomAlert`.
- Source `slug='lone_worker'` is auto-`firstOrCreate`d.
- Already called today by `LoneWorkerController::checkIn` (emergency check-in, line 284) and
  `triggerEmergency` (line 328). **For tracker panic, call the same method from the telemetry path**
  with the worker's active `LoneWorkerSession`.

Note: `emitEmergency` needs a `LoneWorkerSession`. From the telemetry path you have a `Device` →
its active staff `DeviceAssignment.assignable_id` (= user_id) → look up the user's active
`LoneWorkerSession` (`status in active/overdue/emergency`). If no live session, fall back to a
generic CR signal (raw `FleetSignalService->emit` with a `lone_worker.sos` type) so the alert is
never dropped.

---

## 6. The host page for "Locate now" — Lone Worker detail

- Controller `app/Http/Controllers/HealthSafety/LoneWorkerController.php`:
  - `index()` renders `health-safety/lone-workers/index`; `sessionDetail(int $id)` (line 535) builds
    the detail payload (`_type='session'`, session map + check-ins + alerts + shift). **Add a
    `tracker`/`location` block here** built from `DeviceRegistryService::forStaff($tenantId, $session->user_id)`
    + a staff version of `buildResidentPayload` (rename → `buildWorkerLocationPayload`).
  - `mapSession()` (line 508) already exposes `location/location_lat/location_lng` (manual session
    location) — distinct from live tracker GPS; keep both.
  - Perms on the page: `can.manage = canDo('hazards.manage')`, `can.view = canDo('hazards.view')`,
    `can.view_control_room = canDo('controlRoom.viewAny')` (lines 153-157). **Gate Locate-now on
    `hazards.manage`** (matches existing write actions like check-in/trigger-emergency, which are
    auth-only + `hazards.manage` checks).
- UI detail component: `resources/js/components/health-safety/lone-worker-detail-dialog.tsx`
  (`LoneWorkerDetailDialog`, branches on `detail._type === 'session'`). **This is where the tracker
  location card + "Locate now" button go.** The page is `resources/js/pages/health-safety/lone-workers/index.tsx`
  (renders the dialog at line 401).

---

## 7. UI to reuse — `resident-sidebar.tsx`, `client-location-tab.tsx`, types

- **`resources/js/components/resident-tracking/types.ts`** — `Resident`, `Geofence`, `GeofenceStatus`,
  `CommandStatus` types. The `Resident` shape == `buildResidentPayload`. Reusable for staff almost
  verbatim (the only client-specific fields are `client_id`, `preferred_name`, `on_outing`,
  `profile_url`). Consider a `TrackedSubject`/`Worker` type, or just feed staff data through `Resident`.
- **`resources/js/components/resident-tracking/resident-sidebar.tsx`** — `ResidentSidebar` props
  `{ resident: Resident; variant: 'fleet-row'|'profile-detail'; canManage; onLocateNow?; onAcknowledgePanic?; onOpenProfile?; isActive? }`.
  Renders status dot, zone badge, battery state (`getBatteryState`), `PanicStatusBadge`, **Locate Now
  button** (calls `onLocateNow`, disabled when no `locate_now_url`), command-status badge
  (`commandStatusLabel`/`Tone`), current-location block (speed/heading/accuracy/satellites), and a
  collapsible device-details panel. **This is the drop-in "Locate now" + last-location card.** Pair
  with `PanicStatusBadge` (`@/components/resident-tracking/panic-status-badge`) and `ResidentMap`
  (`@/components/resident-tracking/resident-map`, wraps `leaflet-map`).
- **`resources/js/components/client-location-tab.tsx`** — `ClientLocationTab` is the full
  profile-tab composition (the closest analog to what the Lone Worker detail card should do):
  consent banner, "no tracker assigned" CTA (links to `/fleet-assets/resident-tracking/assign`), map +
  `ResidentSidebar variant="profile-detail"`, and a movement-history panel with date range + CSV
  export. Auto-refreshes every 30s via `router.reload({ only:['location'] })`. **Locate-now handler
  (line 315):** `router.post(tracker.locate_now_url ?? '/operations/clients/{id}/location/locate-now', {}, {preserveScroll:true})`.
  History fetched from `/operations/clients/{id}/location/history` (JSON). **Mirror these endpoints
  for staff.** Shows result implicitly: the POST returns to the page with a flash + the next 30s
  reload (or immediate reload) re-pulls `last_command_status` ("Queued"/"Sent"/"Acked"/etc.) into the
  sidebar badge. There is no synchronous coordinate return — Locate-now is async (the tracker reports
  on next connection).

---

## 8. "How does Locate now actually work" (end-to-end, to replicate)

1. UI button (`ResidentSidebar` / `ClientLocationTab`) → `router.post(locate_now_url)`.
2. Controller action resolves the paired `Device` (resident: `forClient`; staff: `forStaff`),
   `abort_unless` authorised, then `LocateNowService->queueForDevice($device, $user)`.
3. Service builds a Queclink `GTRTO`-family location request and stores a `QueclinkPendingCommand`
   (`status=QUEUED`, 5-min TTL). The Queclink listener delivers it; the device replies with a frame.
4. Inbound frame → `FleetTelemetryIngestService::ingest` updates `Device.latitude/longitude` +
   `meta.*` + `last_seen_at`.
5. Next page load / 30s auto-reload re-runs the payload builder → fresh `lat/lng/display_location`
   and `last_command_status` reflects the command lifecycle.
   (No live websocket for the detail card; resident relies on `router.reload`. A `FleetVehiclePositionUpdated`
   broadcast fires in ingest for map clients but the sidebars poll.)

---

## 9. COPY-THIS-FOR-STAFF outline

Reuse verbatim (no change):
- `DeviceAssignment` (`TARGET_STAFF`), `DeviceRegistryService::forStaff`, `LocateNowService`,
  `DeviceAssignmentService::assign/release` (no consent for staff), `GeofenceStatusService`,
  `IntegrationEventHistoryService::forDevice`, `QueclinkPendingCommand`, the Queclink pairing hub
  (`claimDevice` `pairing_type='staff'`).
- React: `resident-sidebar.tsx`, `panic-status-badge`, `resident-map`, `leaflet-map`, and the
  `types.ts` `Resident`/`Geofence` shapes (optionally generalise names).

Build (small):
1. **Staff payload builder** — clone `buildResidentPayload` → `buildWorkerLocationPayload(Device, User, ?LoneWorkerSession)`;
   swap client fields for user fields; URLs point to new staff routes.
2. **Staff Locate-now + history + ack-panic actions** — clone `ResidentTrackingController::locateNow/
   history/acknowledgePanic` into `LoneWorkerController` (or a new `StaffTrackingController`), resolving
   the device via `forStaff` and authorising via a staff-scoped `getAuthorizedUserIds` (admins/managers/
   `hazards.manage` see all; workers see self). Gate writes on `hazards.manage`.
3. **Lone Worker detail wiring** — in `sessionDetail()`, attach `tracker`/`currentLocation`/`geofences`
   from the device; in `LoneWorkerDetailDialog`, render `ResidentSidebar variant="profile-detail"` (or a
   `WorkerTrackingCard`) with `onLocateNow`/`onAcknowledgePanic`.
4. **Panic→emergency routing (the headline)** — in `FleetTelemetryIngestService` SOS block, detect a
   STAFF tracker (asset `primary_driver_user_id` set / device's active assignment `assignable_type='staff'`),
   find the worker's live `LoneWorkerSession`, and call `LoneWorkerSignalService::emitEmergency($session, $notes)`
   (man-down/SOS) instead of `resident.sos`. Fallback to a generic `lone_worker.sos` signal when there's
   no live session. Add a `meta.panic_active` ack path (clone `acknowledgePanic`).
5. **(Optional) staff pairing UI** — clone `resident-tracking/assign.tsx` for staff, or rely on the
   existing Queclink hub which already supports `pairing_type='staff'`.

Permissions summary to mirror: resident reads = `fleet.viewAny|assets.viewAny`; resident writes =
`fleet.manage`; client-profile = `clients.view*`. **Staff equivalent should sit on the Lone Worker
H&S perms (`hazards.view` read / `hazards.manage` write)** to match the existing module, plus
`controlRoom.viewAny` for the CR deep-link.
