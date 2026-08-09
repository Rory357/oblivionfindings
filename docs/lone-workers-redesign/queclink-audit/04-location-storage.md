# Queclink Audit 04 — Location Storage & Surfacing (last-known fix)

Scope: How a device's LAST-KNOWN GPS location is stored and surfaced, and the cleanest
way to read a **staff-paired** tracker's last fix for the Lone Worker session detail —
reusing the same source the resident sidebar uses.

Worktree: `C:/Users/steph/Herd/oblivionfindings/.claude/worktrees/frosty-spence-59899d`
Read-only audit. All paths absolute; line numbers from current tree.

---

## TL;DR (the one thing that matters)

The **last-known fix is stored on the canonical `devices` row** (`App\Domain\SecurityDevices\Models\Device`),
NOT on `queclink_devices`, and NOT read live from `queclink_raw_frames`. Both columns
(`devices.latitude`, `devices.longitude`, `devices.last_seen_at`, `devices.last_signal_at`,
`devices.battery_level`) AND a rich JSON blob (`devices.meta`) hold the latest fix +
telemetry. The resident sidebar and the client-profile Location tab BOTH read from this
single canonical Device row.

**For a staff-paired tracker the location source is byte-for-byte identical to residents.**
Pairing a Queclink device to staff already creates the full plumbing (Asset + canonical
Device + `AssetTracker status=paired` + staff `DeviceAssignment`), so the existing
telemetry ingest pipeline populates `devices.latitude/longitude/last_seen_at/meta` for a
staff tracker exactly as it does for a resident tracker. **No new location/ping table is
needed.** The lone-worker session detail just needs to (1) resolve the staff member's
tracking Device and (2) read the same fields `buildResidentPayload()` reads.

---

## 1. Where the last fix is stored

### 1a. `queclink_devices` — connection state only, NO coordinates
Migration `database/migrations/2026_05_11_120000_create_queclink_devices_table.php`
Model `app/Models/Queclink/QueclinkDevice.php`

Columns: `imei` (unique), `device_id` (FK→devices, nullOnDelete), `tenant_id`, `model_hint`,
`protocol_version`, `firmware_version`, `status` (pending|paired|rejected),
`pending_pairing_type` (vehicle|staff|client), `connection_state` (connected|disconnected),
`current_session_id`, `remote_address`, `first_seen_at`, `last_seen_at`, `last_frame_at`,
`last_count_number`, `notes`.

**There is NO latitude / longitude / location column here.** `last_seen_at` / `last_frame_at`
are heartbeat/connection timestamps written by the TCP listener (`FrameRouter::resolveDevice`,
lines 156-170), not GPS-fix times. Use this table only to know "is the tracker connected,
when did we last hear ANY frame, what IMEI."

### 1b. `queclink_raw_frames` — append-only debug log of every frame (parsed GPS lives in JSON)
Migration `database/migrations/2026_05_11_120001_create_queclink_raw_frames_table.php`
Model `app/Models/Queclink/QueclinkRawFrame.php` (note: `UPDATED_AT = null`, insert-only)

Columns: `queclink_device_id` (FK), `imei`, `tenant_id`, `direction` (inbound|outbound),
`frame_type` (RESP|ACK|SACK|BUFF|AT|unknown), `command_word`, `raw_frame` (text),
`parsed_payload` (**JSON** — cast `array`), `parse_ok`, `parse_error`, `session_id`,
`remote_address`, `created_at` (useCurrent; no updated_at).

This is the raw firehose for the debug console. The GPS lat/lng IS in `parsed_payload`,
but **nothing in the resident UI reads raw frames for last-location.** Indexes:
`(imei, created_at)`, `(created_at)`, `(command_word)`, `(tenant_id, created_at)`.
Treat as audit/replay, not as the "last fix" source of truth.

### 1c. `devices` — THE canonical last-known fix (source of truth for the UI)
Model `app/Domain/SecurityDevices/Models/Device.php`

Relevant columns + casts (Device.php lines 42-96):
- `latitude` cast `decimal:8`, `longitude` cast `decimal:8`  ← latest fix
- `last_seen_at` cast `datetime`  ← last contact
- `last_signal_at` cast `datetime`  ← set to the GPS fix's `occurred_at`
- `battery_level` (int), `battery_updated_at` (datetime)
- `meta` cast `array` (JSON)  ← rich latest-telemetry blob (see below)
- `health_status` (HealthStatus enum), `status` (DeviceStatus enum)
- identity: `domain` (`tracking` for trackers), `category` (`personal_tracker` for staff/client,
  `vehicle_tracker` for vehicles), `provider` (`queclink`), `imei`, `serial_number`,
  `device_uid`, `mac_address`, `model`, `manufacturer`, `firmware_version`.

The `meta` JSON is the de-facto telemetry record. Keys written on each fix (see ingest §2):
`lat`, `latitude`, `lng`, `longitude`, `speed`, `heading`, `accuracy`, `altitude`, `motion`,
`last_location_at`, `battery`, `battery_level`, `battery_status`, `battery_voltage_mv`,
`charging_status`, `external_power`, `power_event`, `last_safety_event`,
`last_safety_event_at`, `panic_active`, plus assorted network/cell keys.

### 1d. Other location tables (NOT the resident source — for reference)
- `fleet_telemetry_events` (Model `app/Models/FleetTelemetryEvent.php`): full event history,
  one row per fix. Columns incl. `device_id`, `latitude`/`longitude` (decimal:7), `speed_kph`,
  `heading_deg`, `altitude_m`, `accuracy_m`, `battery_pct`, `event_type`, `occurred_at`,
  `received_at`, `address`, `reverse_geocoded_at`, `consent_blocked`, `raw_payload`. This is the
  history feed (and the ONLY place a reverse-geocoded `address` string lives). The resident
  controller reads `address` from here via `latestAddressForDevice()` but reads the actual
  lat/lng from the canonical Device.
- `asset_telemetry_snapshots` (Model `AssetTelemetrySnapshot`): one upserted snapshot row per
  device for fleet-asset views; not used by the resident sidebar.
- `fleet_vehicle_state_snapshots`: per-asset current state (vehicles).
- `shift_gps_logs` (Model `app/Models/ShiftGpsLog.php`, migration
  `2026_03_23_001200_create_shift_gps_logs_table.php`): EVV / shift clock-in GPS breadcrumbs
  tied to `shifts`, NOT to Queclink hardware. Unrelated to tracker last-fix.
- `control_room/Device` (`app/Models/ControlRoom/Device.php`): a CR projection, not the fix store.

---

## 2. How the fix gets INTO the canonical Device (the write path)

Pipeline: Queclink TCP frame → `FrameRouter` → `FleetTelemetryIngestService` → canonical `Device`.

1. `app/Services/Queclink/Listener/FrameRouter.php::handleInbound()`
   - Persists raw frame to `queclink_raw_frames` (logRaw, lines 177-193).
   - Upserts `queclink_devices` runtime state (`resolveDevice`, lines 127-175): `last_seen_at`,
     `last_frame_at`, `connection_state`, `current_session_id`.
   - **Only if `queclinkDevice->isPaired() && frame->isReport()`** → calls
     `$this->ingest->ingest('queclink', $frame->payload)` (lines 80-90).

2. `app/Services/Fleet/Telemetry/QueclinkAdapter.php::normalize()` maps the AT-track payload →
   normalized array (`latitude`, `longitude`, `speed_kph`, `heading_deg`, `battery_pct`,
   `event_type`, `sos_flag`, `tamper_flag`, etc.). `sos_flag` true when alarm ∈
   {sos, panic, emergency}; man-down surfaces via `event_type`.

3. `app/Services/Fleet/FleetTelemetryIngestService.php::ingest()` — the write:
   - **GATE (lines 36-44):** looks up `AssetTracker` by `vendor='queclink' AND device_uid=<imei> AND status='paired'`.
     **If no paired AssetTracker → returns `['ok'=>false,'error'=>'tracker not found','status'=>404]` and writes nothing.**
     This is the single hard dependency that the tracker must have a paired `AssetTracker` row.
   - Resolves canonical Device via `FleetDeviceRuntimeService::resolveCanonicalDevice()`
     (`app/Services/Fleet/FleetDeviceRuntimeService.php` lines 37-81) — matches on
     `devices.imei` → `serial_number` → `device_uid` (scoped `domain='tracking' AND provider=vendor`),
     falling back to `legacy_asset_tracker_id`.
   - Writes `fleet_telemetry_events` + `asset_telemetry_snapshots` (history).
   - **Writes the canonical Device (lines 119-203):** sets `last_seen_at`, and when a fix is
     present + not consent-blocked sets `latitude`, `longitude`, `last_signal_at`, plus the
     `meta` keys listed in §1c (`meta['lat'|'lng'|'speed'|'heading'|'accuracy'|'altitude'|
     'motion'|'last_location_at'|'battery'|'battery_status'|...]`). Saved via
     `$device->forceFill($deviceUpdates)->save()` (line 203).
   - SOS handling (lines 244-277): emits `vehicle.sos` signal always; emits `resident.sos`
     additionally when `isResidentSafetyTracker($asset)` (asset has `client_id` OR
     `category==='personal_tracker'`). **NOTE for BUILD:** a STAFF personal_tracker asset has
     `primary_driver_user_id` and NO `client_id`, but `category==='personal_tracker'` → it WILL
     match `isResidentSafetyTracker` and emit `resident.sos`. That is mis-routed for staff and is
     the seam to redirect into the lone-worker emergency pipeline (see §5).

**Consent gate:** `consent_blocked` (lines 52-54) nulls lat/lng for client/non-vehicle assets
lacking a valid consent. `isFleetOwnedVehicle()` (lines 337-348) exempts fleet vehicles. A
**staff** personal_tracker asset has no `client_id` and is not category `vehicle`, so
`isFleetOwnedVehicle()` returns false → **consent_blocked would be TRUE unless a valid consent
resolves**, which would null the staff fix. This is a BUILD consideration: staff lone-worker
tracking is an employment-basis activity, not Privacy-Act client consent. Either (a) extend the
consent exemption to staff personal_trackers, or (b) attach a consent. Flag, don't fix here.

---

## 3. How the RESIDENT UI reads "last location + time" (the pattern to mirror)

### 3a. Resident tracking dashboard — `ResidentTrackingController::buildResidentPayload()`
`app/Http/Controllers/FleetAssets/ResidentTrackingController.php` lines 598-682. This is the
canonical reader. Key reads:
```php
$meta = $device->meta ?? [];
$lat = $device->latitude ?? $meta['lat'] ?? $meta['latitude'] ?? null;      // line 601
$lng = $device->longitude ?? $meta['lng'] ?? $meta['longitude'] ?? null;    // line 602
$address = ($lat!==null && $lng!==null) ? $this->latestAddressForDevice($device) : null; // 603
$coordinates = $this->formatCoordinates($lat,$lng);                          // sprintf %.6f,%.6f
// ...
'last_seen_at' => $device->last_seen_at?->toISOString(),                     // line 628
'display_location' => $address ?: $coordinates,                             // line 633
'last_location_at' => $meta['last_location_at'] ?? null,                    // line 670
'battery' => $this->resolveBatteryLevel($device,$meta),                      // device.battery_level ?? meta.battery
'panic_active' => (bool)($meta['panic_active'] ?? false),                    // line 644
'speed'/'heading'/'accuracy'/'altitude'/'motion' => $meta[...],            // lines 645-649
```
- Last-fix coordinates: `device.latitude/longitude` (fallback `meta.lat/lng`).
- Last-fix human time: `meta['last_location_at']` (the GPS `occurred_at`); last contact:
  `device.last_seen_at`.
- Address string (reverse-geocoded): `latestAddressForDevice()` (lines 515-526) →
  most-recent `fleet_telemetry_events.address` where `consent_blocked=false` and lat/lng/address
  not null, ordered `occurred_at desc, id desc`.
- `formatCoordinates()` (lines 528-535): `sprintf('%.6f, %.6f', lat, lng)`.

The device itself is resolved via the **DeviceRegistryService**, not raw queries:
`history()` line 321 and `locateNow()` line 410 use
`$this->registry->forClient($tenantId, $client->id)->where('domain','tracking')->first()`.

### 3b. Client profile Location tab — `ClientController`
`app/Http/Controllers/ClientController.php` lines ~2725-2800 duplicate the SAME read against the
same canonical Device (`$device->latitude ?? $meta['lat'] ...`, `last_seen_at`,
`display_location`, `panic_active`, speed/heading/accuracy/altitude, etc.), and exposes
`locate_now_url => route('operations.clients.location.locate-now', ['client'=>...])` (line 2778)
and `acknowledge_panic_url`. There's also an Asset-tracker-shaped read at lines 1326-1330
(`$a->tracker->meta['lat'|'lng'|'speed'|'battery']`) for a different (asset) widget — not the
canonical path; prefer the Device read.

### 3c. Frontend
Resident dashboard page: `resources/js/pages/fleet-assets/resident-tracking/index.tsx`
(plus `assign.tsx`, `history.tsx`). It consumes the `residents[]` payload above (`lat`, `lng`,
`display_location`, `last_seen_at`, `last_location_at`, `battery`, `panic_active`, ...). The
client-profile Location tab renders from the ClientController `location` payload. Mirror the
field names so a shared component / formatter can be reused.

---

## 4. "Locate now" (force a fresh fix) — already entity-agnostic

`app/Services/Queclink/LocateNowService.php`:
- `queueForDevice(Device $device, User $user)` (lines 18-41): resolves the `QueclinkDevice`
  from the canonical Device (by `device_id`/`imei`/`device_uid`), asserts `isPaired()`, builds a
  `GTRTO` location request via `CommandBuilder::requestLocation(family)`, and inserts a
  `QueclinkPendingCommand` (status QUEUED, expires +5min). FrameRouter dispatches it on the
  device's next frame.
- `familyFor()` (lines 72-90) **already understands staff trackers**: categories
  `personal_tracker`, `lone_worker_tracker`, `client_tracker` → GL30M family (lines 84-89).
- `queueForImei()` (lines 43-56) exists for IMEI-only callers.

Controller usage to copy: `ResidentTrackingController::locateNow()` (lines 404-424) and
`ClientController::locateNow()` (line 2924). Both: resolve Device via registry, call
`$locateNow->queueForDevice($device, $request->user())`, return `back()->with('success', ...)`.
For the lone-worker detail, the staff equivalent is `registry->forStaff($tenantId, $userId)`
(see §6) then the identical `queueForDevice` call. **No LocateNowService change required.**

---

## 5. PANIC / MAN-DOWN routing (where to splice the lone-worker emergency)

- Inbound SOS sets `meta['panic_active']=true` and `meta['last_safety_event']` in ingest
  (`FleetTelemetryIngestService` lines 165-174; `normalisedSafetyEvent()` lines 373-382 returns
  `man_down` for `event_type==='man_down'`, else `vehicle_sos`).
- Signals emitted (lines 244-277): `vehicle.sos` always; `resident.sos` when
  `isResidentSafetyTracker()` — which **incorrectly matches a staff personal_tracker** (no
  `client_id` but category `personal_tracker`). This is the splice point.
- Lone-worker emergency pipeline (the target): `app/Services/HealthSafety/LoneWorkerSignalService.php`
  - `emitEmergency(LoneWorkerSession $session, ?string $notes=null)` (lines 35-49) →
    `emit(TYPE_EMERGENCY=='lone_worker_emergency', $session, AlertSeverity::CRITICAL, ...)` →
    `SignalProcessingService::ingest()` + `::process()` → `ControlRoomAlert`.
  - The signal carries `lone_worker_session_id`, `worker_user_id`, `worker_name`, `site_id`,
    `client_id`, and the SESSION's `location`/`location_lat`/`location_lng` (lines 118-140).
- Current human entry points to `emitEmergency`:
  `app/Http/Controllers/HealthSafety/LoneWorkerController.php` line 284 (check-in with
  status=emergency) and line 328 (`triggerEmergency`).

**BUILD seam:** when a paired tracker fires SOS/man-down and it's a STAFF personal_tracker,
resolve the staff member's ACTIVE `LoneWorkerSession` and call
`LoneWorkerSignalService::emitEmergency($session, "Tracker panic/man-down")` instead of (or in
addition to) `resident.sos`. The cleanest hook is inside the ingest SOS block (replace the
`isResidentSafetyTracker` branch with a staff-vs-client discriminator using
`$asset->primary_driver_user_id` vs `$asset->client_id`), OR a thin observer on the new signal.
Note `emitEmergency` requires a `LoneWorkerSession`, so the splice must look up the active
session for `worker_user_id`. Detail belongs to the routing-audit doc; flagged here.

---

## 6. CLEANEST way to read a staff-paired tracker's last fix (the BUILD recipe)

Because staff pairing already creates Asset + canonical Device + `AssetTracker(status=paired)` +
staff `DeviceAssignment` (see §7), the last fix lands on the SAME canonical Device fields. So:

**Resolve the staff member's tracking Device** (mirror of `registry->forClient`):
```php
// app/Domain/SecurityDevices/Services/DeviceRegistryService.php lines 73-80 — ALREADY EXISTS
$device = app(DeviceRegistryService::class)
    ->forStaff($tenantId, $session->user_id)   // active staff DeviceAssignment, TARGET_STAFF
    ->where('domain', 'tracking')
    ->first();
```
**Read the last fix from the Device** using the exact resident formula:
```php
$meta = $device?->meta ?? [];
$lat  = $device?->latitude  ?? $meta['lat'] ?? $meta['latitude']  ?? null;
$lng  = $device?->longitude ?? $meta['lng'] ?? $meta['longitude'] ?? null;
$lastSeenAt     = $device?->last_seen_at?->toISOString();          // last contact
$lastLocationAt = $meta['last_location_at'] ?? null;               // last fix time
$battery        = $device?->battery_level ?? $meta['battery'] ?? null;
$panicActive    = (bool)($meta['panic_active'] ?? false);
$address        = ($lat!==null && $lng!==null)                     // reverse-geocoded string
    ? FleetTelemetryEvent::where('device_id',$device->id)
        ->where('consent_blocked',false)->whereNotNull('latitude')
        ->whereNotNull('longitude')->whereNotNull('address')
        ->orderByDesc('occurred_at')->orderByDesc('id')->value('address')
    : null;
$displayLocation = $address ?: ($lat!==null ? sprintf('%.6f, %.6f',$lat,$lng) : null);
```
**Locate-now button:** `app(LocateNowService::class)->queueForDevice($device, $request->user())`
(already handles the GL30M family for staff trackers). Add a controller action + route on the
lone-worker session, copying `ResidentTrackingController::locateNow()` 1:1 but resolving via
`forStaff`.

This makes the lone-worker session detail read the **identical source** the resident sidebar
uses (canonical `devices` row + `meta`), with zero new storage and zero new ingest code.

---

## 7. Why the staff path already populates the canonical Device (proof)

Pairing action: `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php`
`pairDevice()` (the `claim`/pair transaction), lines 170-247. For `pairing_type='staff'`:
- `ensurePersonalTrackerAsset(type:'staff', targetId:$userId)` (lines 1195-1219) → Asset
  `category='personal_tracker'`, `primary_driver_user_id=$userId`, `client_id=null`.
- `ensureCanonicalDevice($qd, $tenantId, 'staff')` (lines 1221-1266) → canonical Device
  `domain='tracking'`, `category='personal_tracker'`, `provider='queclink'`, `imei=$qd->imei`,
  name "Lone-worker tracker {imei}".
- `DeviceAssignment::create(['assignable_type'=>'staff','assignable_id'=>$userId,...])`
  (lines 208-216) — so `DeviceRegistryService::forStaff()` will find it.
- **`AssetTracker::updateOrCreate(['vendor'=>'queclink','device_uid'=>$imei],['asset_id'=>...,
  'status'=>'paired',...])` (lines 218-230)** — this is the row the ingest GATE (§2, lines 36-44)
  requires, so telemetry WILL ingest for a staff tracker → canonical Device lat/lng/meta WILL fill.
- `DeviceAssignment::TARGET_STAFF = 'staff'` and `assignable()` → `User::find()`
  (`app/Domain/SecurityDevices/Models/DeviceAssignment.php` lines 48, 90).
- `AppServiceProvider` morph map line 177: `'staff' => User::class`.

So the resident pattern is fully transferable to staff with NO new table and NO new ingest path.

---

## 8. REUSE vs BUILD

### REUSE (do not rebuild)
- `devices` row as the last-fix store: `latitude`, `longitude`, `last_seen_at`, `last_signal_at`,
  `battery_level`, `meta` JSON (keys `lat/lng/speed/heading/accuracy/altitude/motion/
  last_location_at/battery/battery_status/panic_active/...`). Source of truth for the UI.
- `DeviceRegistryService::forStaff($tenantId, $userId)->where('domain','tracking')->first()`
  to resolve the staff tracker (already exists, lines 73-80).
- The resident read formula in `ResidentTrackingController::buildResidentPayload()` (lines 598-682)
  and `latestAddressForDevice()` (lines 515-526) — copy verbatim for the staff session payload.
- `LocateNowService::queueForDevice()` — already staff-aware (GL30M family). Copy
  `ResidentTrackingController::locateNow()` (404-424) as the staff locate action.
- Full ingest pipeline (`FrameRouter` → `FleetTelemetryIngestService` → Device) — unchanged.
- Pairing scaffolding for staff (`QueclinkHubController` lines 170-247, 1195-1266) — already
  creates the AssetTracker the ingest gate needs.
- Emergency pipeline `LoneWorkerSignalService::emitEmergency()` (lines 35-49) → Control Room.

### BUILD (new work)
1. Lone-worker session detail surfacing: resolve `forStaff(tenant, session->user_id)` Device,
   build a `tracker`/`last_location` payload using the reused read formula, render last fix +
   time + battery + "Locate now" on `/health-safety/lone-workers`. (Backend payload + a small
   React panel — mirror the resident sidebar component/fields.)
2. Staff "Locate now" controller action + route (copy of resident `locateNow`, resolve via
   `forStaff`).
3. PANIC/MAN-DOWN routing into the lone-worker emergency pipeline: discriminate staff vs client
   in the ingest SOS block (use `$asset->primary_driver_user_id` vs `$asset->client_id`); for
   staff, look up the active `LoneWorkerSession` for that user and call
   `LoneWorkerSignalService::emitEmergency($session, ...)` instead of `resident.sos`.
   (Covered in the routing audit; noted here as it consumes the same Device fix data.)
4. Consent-gate decision for staff personal_trackers: today `consent_blocked` would NULL a staff
   fix because a staff personal_tracker is neither a fleet vehicle nor consent-backed
   (`FleetTelemetryIngestService` lines 52-54, 337-348). Extend the exemption to staff
   employment-basis tracking (or attach consent) so the fix is not nulled. DECISION/FLAG — verify
   live before changing.

### GOTCHAS
- `queclink_devices.last_seen_at/last_frame_at` are CONNECTION times, not GPS times. The GPS fix
  time is `devices.meta['last_location_at']` (and `devices.last_signal_at`).
- Do NOT read `queclink_raw_frames` for last-location; it's the raw debug log.
- The reverse-geocoded `address` STRING only exists in `fleet_telemetry_events.address`
  (populated async by `ReverseGeocodeFleetTelemetryEvent`, gated on
  `config('fleet.maps.reverse_geocode_enabled')`). The Device row holds lat/lng only.
- `isResidentSafetyTracker()` currently mis-classifies staff personal_trackers as resident SOS.
- A paired `AssetTracker` (status='paired') is mandatory for ANY ingest (the 404 gate) — staff
  pairing already creates it, but a tracker paired by some other path without it would be silent.
