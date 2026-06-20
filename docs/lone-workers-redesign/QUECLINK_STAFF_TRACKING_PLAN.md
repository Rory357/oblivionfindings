# Queclink Staff / Lone-Worker Tracking — BUILD PLAN

Synthesised from the Queclink audit digests in `docs/lone-workers-redesign/queclink-audit/`
(`01-resident-tracking.md`, `02-device-pairing.md`, `03-frame-pipeline.md`, `05-placement-perms.md`).
*(There is no `04` digest on disk; the ingest/frame-pipeline content it would have covered lives in
`03-frame-pipeline.md`.)*

**Goal:** surface a lone worker's last-known tracker location + a "Locate now" action on the Lone
Worker session detail (`/health-safety/lone-workers`), and route a tracker PANIC / MAN-DOWN frame into
`LoneWorkerSignalService::emitEmergency()` → Control Room.

**Thesis (every audit agrees):** the device spine is **already staff-aware**. Pairing, telemetry
ingest, the Locate-now command, geofence eval, and the staff-device lookup all exist today. The net new
build is a **thin staff read/serialise layer + a panic→emergency branch** — *do not rebuild the device
domain.*

---

## 1. REUSE MAP — existing code vs new

### REUSE VERBATIM (no change)

| Concern | Existing code | Notes |
|---|---|---|
| Device → staff link | `DeviceAssignment::TARGET_STAFF='staff'` (`app/Domain/SecurityDevices/Models/DeviceAssignment.php:48`); `assignable()`→`User::find()` (:90) | Polymorphic `assignable_type`/`assignable_id`. `requiresConsent()` (:136) is **client-only** → staff needs no `consent_id`. |
| Resolve a worker's tracker | `DeviceRegistryService::forStaff($tenantId,$userId)` (`app/Domain/SecurityDevices/Services/DeviceRegistryService.php:73`) | Exact mirror of `forClient()` (:50). Returns `Device` rows w/ `latitude/longitude/last_seen_at/battery_level`. |
| Pair tracker → staff | `QueclinkHubController::claimDevice()` staff branch (`...Integrations/QueclinkHubController.php:169`, validates `pairing_type in:vehicle,staff,client` :175) + `POST .../queclink/devices/{queclinkDevice}/claim` (`routes/security-devices.php:254`) + `queclink-hub.tsx` staff picker | `ensureCanonicalDevice` already names it `"Lone-worker tracker {imei}"`, `category='personal_tracker'`, `domain='tracking'`. `QueclinkDevice::PAIRING_STAFF` + `pending_pairing_type` enum already include `staff`. |
| Queue "Locate now" | `LocateNowService::queueForDevice(Device,User)` (`app/Services/Queclink/LocateNowService.php`) | **Device-centric, not client-centric.** `familyFor()` (:85) maps `personal_tracker`/`lone_worker_tracker` → `FAMILY_GL30M`. Writes a `QueclinkPendingCommand` (GTRTO, QUEUED, 5-min TTL). |
| Assign/release | `DeviceAssignmentService::assign/release` | `validateConsent` (:124) skips consent for non-client targets. |
| Geofence eval | `GeofenceStatusService::evaluate($lat,$lng,?Geofence)` | Pass `null` for staff (no geofence) → `'unknown'`. Optional. |
| Movement history | `IntegrationEventHistoryService::forDevice($device,$filters,$bool)` | Device-centric; reuse for any staff history panel (defer for v1). |
| Frame → ingest | `TcpListener`→`FrameRouter`→`FleetTelemetryIngestService::ingest()` | Parser already emits `sos_flag`+`man_down` for `GTSOS`/`GTMAN`/`GTLBC` (`AtTrackProtocolParser.php:231/282/287`). Location + `meta['panic_active']` + `meta['last_safety_event']` written **above** the SOS block, so last-known-location data lands regardless of the new branch. |
| Emergency → Control Room | `LoneWorkerSignalService::emitEmergency(LoneWorkerSession,?notes)` (`app/Services/HealthSafety/LoneWorkerSignalService.php:35`) | Reads everything off the session; 15-min idempotency per session (:172) → repeated man-down frames are safe. Already called by `LoneWorkerController::triggerEmergency` (:307) / `checkIn` (:269). |
| UI: tracker card / Locate button | `resident-sidebar.tsx`, `panic-status-badge`, `resident-map`/`leaflet-map`, `resident-tracking/types.ts` (`Resident`/`Geofence`/`CommandStatus`) | `ResidentSidebar variant="profile-detail"` is the drop-in last-location + Locate Now + command-status card. |
| UI host | "Last-known location" card in `lone-worker-detail-dialog.tsx` `SessionOverview()` (:174–237) | Already renders lat/lng + Open-map; enrich in place. |
| Perms | `hazards.view`/`hazards.manage` (module gate); `securityDevices.integrations.manage` (pairing); `controlRoom.viewAny` (CR deep-link) | **No new permission keys.** |

### BUILD NEW (small, additive)

1. `buildWorkerTrackerPayload(Device,User)` — clone of `ResidentTrackingController::buildResidentPayload()` (:598); swap client fields → user fields, URLs → staff routes.
2. `locateNow(session)` + `acknowledgePanic(session)` controller actions on `LoneWorkerController` (clone of `ResidentTrackingController::locateNow` :404 / `acknowledgePanic` :426).
3. 2 routes in the existing `hazards.manage` group.
4. `tracker` block added to `sessionDetail()` payload + the `SessionDetail` TS type + detail-card enrichment.
5. **The headline:** a staff branch in `FleetTelemetryIngestService::ingest()` SOS block (~after line 277) → `isLoneWorkerTracker()` / `resolveStaffForDevice()` → active `LoneWorkerSession` → `emitEmergency()`.

**Explicitly NOT built:** no new pairing UI/backend, no new device model, no new permission key, no new ingest pipeline, no migration *unless* we choose the optional `LoneWorkerSession.device_id` link (see Step 1 — recommended **skip** for v1; resolve session by `user_id`).

---

## 2. ORDERED STEPS

### Step 0 — (Decision) Schema: staff device assignment
**No new pairing schema.** `device_assignments` already stores `assignable_type='staff'`/`assignable_id=<user id>` with an active-lookup index (`dev_assign_target_active_idx`). The only *optional* migration is a nullable `device_id` (or `asset_tracker_id`) FK on `lone_worker_sessions` to make session→tracker explicit.
- **Recommendation: SKIP for v1.** Resolve a worker's tracker on demand via `DeviceRegistryService::forStaff($tenantId, $session->user_id)`. Add the column only if profiling shows the lookup is hot or product wants a hard 1:1 session↔device pin.
- Files (only if adopted): new migration `…_add_device_id_to_lone_worker_sessions`; `LoneWorkerSession` `$fillable`. **Reuse target:** none — additive nullable column.

### Step 1 — Pairing (assign a tracker to a staff user)
**Reuse as-is — zero build.** Operators pair via the Queclink Hub pending tray (`pairing_type='staff'`). Confirm the staff target list populates and a claim writes a `DeviceAssignment(assignable_type='staff')`.
- Files to touch: **none** (verify only). Optional deferred polish: a "+ Pair tracker" deep-link from staff profile → Hub. **Reuse target:** `QueclinkHubController::claimDevice()`, `claim` route, `queclink-hub.tsx`.

### Step 2 — Session-detail "last-known location from tracker" (read/serialise)
Add a `tracker` block to the detail payload and render it.
- Files: `app/Http/Controllers/HealthSafety/LoneWorkerController.php` — add `buildWorkerTrackerPayload(Device,User)` + call it in `sessionDetail()` (:535, resolve device via `forStaff(...)->where('domain','tracking')->first()`); `resources/js/components/health-safety/lone-worker-types.ts` — extend `SessionDetail` with optional `tracker`; `resources/js/components/health-safety/lone-worker-detail-dialog.tsx` — enrich the "Last-known location" card (:202–228).
- **Reuse target:** `ResidentTrackingController::buildResidentPayload()` (:598) for shape; `ResidentSidebar variant="profile-detail"` (or its sub-parts) for render; `resident-tracking/types.ts`.

### Step 3 — "Locate now" (+ acknowledge panic) actions
Clone the two write actions, resolving the device by `forStaff`, gated `hazards.manage`.
- Files: `LoneWorkerController.php` — `locateNow(LoneWorkerSession $session)` → `LocateNowService::queueForDevice($device,$user)`; `acknowledgePanic(LoneWorkerSession $session)` → clear `device.meta['panic_active']` + ack CR alerts (`source='lone_worker'`, status open/triaging→ack); `routes/health-safety.php` — 2 routes in the `hazards.manage` group (:388); `lone-worker-detail-dialog.tsx` — wire `router.post(tracker.locate_url)` / `tracker.acknowledge_panic_url` + critical banner when `panic_active`.
- **Reuse target:** `ResidentTrackingController::locateNow` (:404) / `acknowledgePanic` (:426); `LocateNowService`; `client-location-tab.tsx` Locate handler (:315) for the async (poll/reload) UX pattern — **no synchronous coordinate return; the tracker reports on next connection.**

### Step 4 — Panic / man-down → `emitEmergency` routing (the headline)
Add a staff branch to the SOS block in ingest, symmetric to the resident branch.
- Files: `app/Services/Fleet/FleetTelemetryIngestService.php` — inside `if (! empty($normalized['sos_flag']))` (:244), after the resident branch (:260–277): `resolveStaffForDevice($device,$asset)` (active `DeviceAssignment` `TARGET_STAFF`, or `asset->primary_driver_user_id` with no `client_id`) → find latest active/overdue `LoneWorkerSession` for that user → set `status='emergency'` + stamp lat/lng → `app(LoneWorkerSignalService::class)->emitEmergency($session, "Tracker {$event_type} …")`. If no live session: fall back to a raw `FleetSignalService->emit(['signal_type'=>'lone_worker.sos','severity_hint'=>'critical', …])` so the alert is **never dropped**.
- **Critical correctness fix:** today `isResidentSafetyTracker($asset)` (:350) returns **true** for a staff `personal_tracker`, so a staff pendant SOS currently mislabels as `resident.sos`. The staff branch must be guarded so a staff tracker takes the lone-worker path **instead of** (not in addition to) the resident path.
- Guard `$session->update(status=emergency)` with an "already emergency" check (idempotency in `emitEmergency` already dedups the alert in a 15-min window; the status write is the only thing that repeats per frame).
- **Reuse target:** the resident-SOS emit block (:260–276) for structure; `LoneWorkerController::triggerEmergency` (:307) for the set-status-then-emit sequence; `LoneWorkerSignalService::emitEmergency`.

### Step 5 — Tests (see §4).

---

## 3. PERMS + ROUTES

**No new permission keys.** Reuse `hazards.*` (already seeded in `RbacSeeder`).

| Action | Route (new) | Gate |
|---|---|---|
| Read last-GPS in detail | *(none — part of `sessionDetail`)* | `hazards.view` (already gates the page) |
| Locate now (coordinator) | `POST /health-safety/lone-workers/sessions/{session}/locate` → `lone-workers.sessions.locate` | `permission:hazards.manage` |
| Acknowledge panic | `POST /health-safety/lone-workers/sessions/{session}/acknowledge-panic` → `lone-workers.sessions.acknowledge-panic` | `permission:hazards.manage` |
| Pair tracker → staff | `security-devices.integrations.queclink.claim` *(EXISTING)* | `securityDevices.integrations.manage` *(EXISTING)* |
| CR deep-link from card | *(existing link)* | `controlRoom.viewAny` *(existing `can.view_control_room`)* |

Both new routes go inside the existing `permission:hazards.manage` group (`routes/health-safety.php:388`), bound by `{session}` (not `{user}`) to match sibling `sessions.emergency`/`sessions.end`; resolve the worker's tracker inside the action via `forStaff($tenantId,$session->user_id)`. **3-actor model preserved:** coordinator operates the watch-tower (`hazards.manage`); the worker never touches the tracker UI (their only action is the auth-only My Day check-in); panic/man-down is an *inbound* signal, not a UI action. **Do not weaken pairing to H&S** — hardware provisioning stays IT/admin (`securityDevices.integrations.manage`). No RBAC migration on the lone-worker side.

---

## 4. TEST PLAN — unit/feature (simulated frames) vs HARDWARE

### Unit / feature-testable in CI (no hardware) — the bulk of coverage

- **Pairing (reuse, regression):** `claimDevice` with `pairing_type='staff'` creates a `DeviceAssignment(assignable_type='staff', consent_id=null)`, flips `QueclinkDevice→paired`, names device `"Lone-worker tracker …"`. Assert `DeviceRegistryService::forStaff()` then returns it.
- **`buildWorkerTrackerPayload`:** factory `Device` (lat/lng/battery/`meta.panic_active`) + active staff `DeviceAssignment` → assert payload shape, URLs, battery status, panic flag, `last_seen_at`.
- **`locateNow` action:** paired worker → asserts a `QueclinkPendingCommand` (GTRTO, QUEUED) is written via `LocateNowService`; **no tracker** → `ValidationException`; authz: non-`hazards.manage` → 403; ownership/tenant scoping.
- **`acknowledgePanic` action:** clears `device.meta['panic_active']`, acks matching `ControlRoomAlert` (`source='lone_worker'`).
- **Panic→emergency routing (SIMULATED FRAME — the key test):** call `FleetTelemetryIngestService::ingest('queclink', $payload)` with a synthetic normalised GTMAN/GTSOS payload (`sos_flag=true`, `event_type='man_down'`, lat/lng) for an IMEI paired to a **staff** asset. Assert: (a) a `ControlRoomAlert source='lone_worker'` / `signal_type_code='lone_worker_emergency'` is created; (b) the worker's active `LoneWorkerSession→status='emergency'` with lat/lng stamped; (c) **NO** `resident.sos` alert (the mislabel guard); (d) repeated frames within 15 min do **not** duplicate the alert (idempotency); (e) no live session → fallback `lone_worker.sos` signal still emitted.
- **Adapter/parser regression (already covered, assert survives):** `GTSOS/GTMAN/GTLBC`→`sos_flag=true`; `man_down` survives `QueclinkAdapter::mapEventType()` fall-through.
- **Frontend:** detail dialog renders tracker card when `tracker` present, hides when null, shows critical banner + Acknowledge button when `panic_active`.

Simulated frames are first-class: ingest is plain PHP taking a normalised array — feed it fixtures, no socket needed. `WebhookReceiverController` is an alternate HTTP entry to the same `ingest()` for an end-to-end-ish feature test.

### NEEDS PHYSICAL HARDWARE (flag clearly — cannot run in CI)

- **TCP transport:** real GL30 over `TcpListener` (frame splitting, SACK/ack, session binding) — only a real device (or a raw-socket TCP harness replaying captured byte frames) exercises this. CI tests start at `ingest()` and below, *not* the socket.
- **Real GTRTO round-trip latency:** Locate-now is async; confirming the device actually wakes, fixes GPS, and reports a fresh frame after a queued command needs the pendant. CI only asserts the command is *queued*.
- **Real SOS/man-down button + fall sensor:** physical pendant press / drop must produce GTSOS/GTMAN on the wire and flow end-to-end to a Control Room alert. CI simulates the resulting payload only.
- **Battery / signal / tamper telemetry** under real field conditions.

**Recommend a manual hardware acceptance checklist** (separate from the automated suite): pair pendant via Hub → see it in session detail → Locate now → confirm fresh GPS appears → press SOS → confirm `lone_worker_emergency` in Control Room → Acknowledge panic clears it.

---

## 5. RISKS

- **Resident/staff SOS mislabel (correctness, MUST-FIX):** `isResidentSafetyTracker()` is true for any `personal_tracker`, so without the Step-4 guard a staff pendant raises a *resident* alert. The staff branch must take precedence for staff-assigned devices. Highest-risk item; covered by the simulated-frame test (assertion (c)).
- **Device-domain churn / shared files:** `FleetTelemetryIngestService` is a hot, shared ingest path (also fleet + resident). Keep the staff branch additive and defensive (it runs synchronously in the listener loop; `FrameRouter` only logs on throw). Don't refactor the SOS block — append a branch.
- **No live session at panic time:** a paired worker may SOS with no active `LoneWorkerSession`. Mitigation: fallback `lone_worker.sos` signal so the alert is never dropped (decide later whether to auto-create a session — out of scope for v1).
- **Idempotency vs status thrash:** man-down repeats every frame. `emitEmergency` dedups alerts (15-min window) but the `status='emergency'` write repeats — guard it. Acceptable but noted.
- **Multi-tenant:** every lookup must be tenant-scoped. `forStaff($tenantId, …)` already takes `tenantId`; ingest resolves tenant from the paired tracker/asset. Authz on Locate/ack must confirm the session's worker belongs to the actor's tenant (mirror resident `abort_unless`). Don't leak a tracker across tenants via a bare `user_id`.
- **Payroll / SecurityDevices invariants:** pairing reuses the existing claim flow → the `AssetTracker`↔`Asset`↔`DeviceAssignment` mirror and `primary_driver_user_id` semantics stay intact; don't repurpose `primary_driver_user_id` (a vehicle-driver concept) as the lone-worker link — use `DeviceAssignment(TARGET_STAFF)` as the canonical staff↔device link. Avoid a parallel link column that could desync from the assignment.
- **Async UX expectation:** "Locate now" returns no coordinates synchronously; the UI must communicate "requested" and rely on poll/reload (mirror `client-location-tab.tsx` 30s reload + command-status badge), or users will read it as broken.
- **Missing `04` audit:** the ingest/frame digest is `03`; confirm no separate `04` concern (e.g. a distinct geocode/outbox area) was intended before treating coverage as complete.

---

## Net assessment
~2 controller methods + 2 routes + 1 payload helper + 1 ingest branch + 1 detail-card enrichment + 1 type extension. **No new migration (optional `device_id` deferred), no new permission, no new pairing UI.** Reuse is the dominant strategy; the device domain is not rebuilt.
