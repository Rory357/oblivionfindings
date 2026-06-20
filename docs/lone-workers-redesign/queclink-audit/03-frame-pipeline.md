# Queclink INGEST → PROCESS pipeline (frame → action)

Audit for hooking **staff tracker panic/man-down → `LoneWorkerSignalService::emitEmergency` → Control Room**.
All paths relative to worktree root `C:/Users/steph/Herd/oblivionfindings/.claude/worktrees/frosty-spence-59899d`.

---

## 1. End-to-end flow (TCP byte stream → Control Room alert)

```
Queclink device (TCP)
  └─ TcpListener::serviceClient()                  app/Services/Queclink/Listener/TcpListener.php:136
       ├─ AtTrackProtocolParser::splitFrames()     (TcpListener.php:151)  -> list<string> frames
       └─ FrameRouter::handleInbound($raw,$state)  (TcpListener.php:154)
            ├─ AtTrackProtocolParser::parse()      app/Services/Queclink/Listener/FrameRouter.php:46  -> AtTrackFrame (payload has alarm/sos_flag/event_type)
            ├─ resolveDevice() upsert QueclinkDevice + bind session   FrameRouter.php:47,127
            ├─ logRaw() -> QueclinkRawFrame (debug console)           FrameRouter.php:58
            ├─ AckBuilder::serverAck() -> SACK bytes                  FrameRouter.php:69
            └─ if device->isPaired() && frame->isReport():
                 FleetTelemetryIngestService::ingest('queclink', $frame->payload)   *** FrameRouter.php:82 ***
```

**`FleetTelemetryIngestService::ingest()`** — `app/Services/Fleet/FleetTelemetryIngestService.php:27` — is THE processing site that turns a parsed frame into actions. It:

- normalises payload via `QueclinkAdapter::normalize()` (sets `sos_flag`, `tamper_flag`, `event_type`).
- resolves the **paired `AssetTracker`** by `vendor='queclink' + device_uid (=IMEI) + status='paired'` (lines 36-44). **No tracker → returns `404`, nothing happens.**
- inside one DB transaction (line 58): idempotency guard, creates `FleetTelemetryEvent`, upserts `AssetTelemetrySnapshot` (this writes **`sos_flag`** col, line 110), updates `AssetTracker.last_seen_at`, updates the canonical `Device` location/meta (incl. `meta['panic_active']`, `meta['last_safety_event']` from `normalisedSafetyEvent()` line 165/373), updates `FleetVehicleStateSnapshot`, broadcasts `FleetVehiclePositionUpdated`.
- **(a) UPDATE LOCATION**: device row `latitude/longitude/last_signal_at` + `meta[lat/lng/...]` set at lines 182-199; snapshot at 102-106; state snapshot at 216-219.
- **(b) RAISE ALERTS ON ALARM**: lines **244-310** — see §2.

There is **NO dedicated queue/Job between parser and ingest** — `FrameRouter` calls `ingest()` **synchronously** inside the listener loop (try/catch logs `queclink: ingest failed`, FrameRouter.php:83). Async work *downstream* of ingest is queued (see §2 outbox + `ReverseGeocodeFleetTelemetryEvent::dispatch()` line 327).

---

## 2. Existing alarm → Control Room paths (the resident-SOS precedent to MIRROR)

All emission goes through **`FleetSignalService::emit(array)`** — `app/Services/Fleet/FleetSignalService.php:13`:
`firstOrCreate FleetSignal` (idempotent) → create `FleetSignalOutbox(status=pending)` → **`DispatchFleetSignalOutbox::dispatch($outbox->id)`** (queued Job) → `event(FleetSignalEmitted)`.

`DispatchFleetSignalOutbox::handle()` — `app/Jobs/DispatchFleetSignalOutbox.php:27` — calls
`SignalProcessingService::ingestFromFleetSignal($signal)` then `->process()` → **`ControlRoomAlert`**.

### Where SOS / man-down is detected (FleetTelemetryIngestService.php):
```php
if (! empty($normalized['sos_flag'])) {                       // line 244  (TRUE for GTSOS, GTMAN, GTLBC)
    $this->signals->emit([... 'signal_type' => 'vehicle.sos', 'severity_hint' => 'critical' ...]);   // 245-258
    if ($this->isResidentSafetyTracker($asset)) {             // line 260
        $this->signals->emit([... 'signal_type' => 'resident.sos', 'severity_hint' => 'critical' ...]); // 261-276
    }
}
if (! empty($normalized['tamper_flag'])) {  ... 'device.tamper' ... }     // 279-292
if (($normalized['event_type'] ?? null) === 'battery_low') { ... 'device.low_battery' ... }  // 294-310
```

- `isResidentSafetyTracker(Asset)` — line **350** — TRUE when `asset->client_id` set **OR** `asset->category === 'personal_tracker'` (or categoryRef slug). This is the resident branch.
- `normalisedSafetyEvent()` — line **373** — returns `'man_down'` when `event_type==='man_down'`, else `'vehicle_sos'`; stored in device `meta['last_safety_event']`.
- The fleet `signal_type` becomes Control Room code `fleet_vehicle_sos` / `fleet_resident_sos` (dots→underscores, prefix `fleet_`) in `SignalProcessingService::ingestFromFleetSignal()` line **468**.
- **Alerts do NOT auto-create an incident.** `ControlRoomAlert` is the terminal artifact; incident creation is a separate Control-Room-operator action (SensorIncidentBridgeService), not part of this ingest path.

### Parser flags (already emitted — confirmed):
`app/Services/Queclink/AtTrackProtocolParser.php` `normalisePayload()` switch (lines 230-299):
- **`GTSOS`** (231): `alarm='sos'`, `event_type='vehicle_sos'`, `sos_flag=true`.
- **`GTMAN`** (282): `alarm='man_down'`, `event_type='man_down'`, `sos_flag=true`.  ← lone-worker man-down.
- **`GTLBC`** (287): `alarm='sos'`, `event_type='vehicle_sos'`, `sos_flag=true`.  ← panic via call.

`QueclinkAdapter::normalize()` (`app/Services/Fleet/Telemetry/QueclinkAdapter.php`): `sos_flag` recomputed line 32 (`payload.sos_flag OR alarm ∈ {sos,panic,emergency}`). **Caveat:** `event_type` is re-mapped by `mapEventType()` (line 75) — `alarm='man_down'` is NOT in its switch, so it falls through to `return $payload['event_type']` (line 97) which the parser already set to `'man_down'`. So `man_down` survives, but `sos_flag` is what actually fires the alert (NOT `event_type`). Good.

---

## 3. The TARGET: `LoneWorkerSignalService::emitEmergency` and the staff hook

**Signature** — `app/Services/HealthSafety/LoneWorkerSignalService.php:35`:
```php
public function emitEmergency(LoneWorkerSession $session, ?string $notes = null): void
```
It does NOT take an asset/IMEI/lat-lng — it reads **everything off the `LoneWorkerSession`** (user, site, client, `location_lat/location_lng`, activity). Internally → `SignalProcessingService::ingest()` + `->process()` → `ControlRoomAlert` with `signal_type_code='lone_worker_emergency'`, severity CRITICAL, idempotency = 15-min window keyed on session+user (`buildIdempotencyKey` line 170).

**This is a DIFFERENT pipeline from Fleet** — it goes straight into `SignalProcessingService` (synchronous, no FleetSignalOutbox job).

### Canonical "trigger emergency" sequence to replicate (the precedent):
`LoneWorkerController::triggerEmergency()` — `app/Http/Controllers/HealthSafety/LoneWorkerController.php:307-330`:
```php
$session->update(['status' => 'emergency', 'emergency_triggered_at' => now(), 'emergency_notes' => ...]);
$session->alerts()->create(['alert_type' => 'emergency', 'triggered_at' => now(), 'status' => 'active']); // legacy
app(LoneWorkerSignalService::class)->emitEmergency($session, $notes);   // canonical → Control Room
```
(Same trio also at `checkIn()` line 269-284 when check-in status === 'emergency'.)

### The MISSING LINK — device → staff → LoneWorkerSession
- `LoneWorkerSession` (`app/Models/LoneWorkerSession.php`) has `user_id`, `location_lat`, `location_lng`, `status`, `scopeActive()` (status='active'), `scopeEmergency()`. **No tracker/device/asset/imei column.** (BUILD: add a device link, or resolve session via `user_id`.)
- `Asset` (`app/Models/Asset.php`) has `client_id` + `primary_driver_user_id` (User FK) but **no lone-worker user link**; resident branch keys on `client_id`. (Vehicle drivers use `primary_driver_user_id`, but that is not a lone-worker-safety concept.)
- **Canonical device→person link ALREADY supports staff**: `App\Domain\SecurityDevices\Models\DeviceAssignment` defines `const TARGET_STAFF = 'staff'` → `User::find($assignable_id)` (`app/Domain/SecurityDevices/Models/DeviceAssignment.php:48,90`), alongside `TARGET_CLIENT`. `FleetDeviceRuntimeService::resolveConsentContext()` only resolves the **client** assignment today (`->where('assignable_type', TARGET_CLIENT)`, `FleetDeviceRuntimeService.php:135`) — a staff equivalent is the natural extension. The canonical `Device` (resolved at `FleetTelemetryIngestService.php:47` via `resolveCanonicalDevice()`) is already in scope inside `ingest()`.

---

## 4. EXACT hook recommendation

**Hook site:** `FleetTelemetryIngestService::ingest()`, inside the `if (! empty($normalized['sos_flag']))` block at **line 244**, as a *third* branch alongside the resident branch (line 260) — i.e. mirror `isResidentSafetyTracker()` with an `isLoneWorkerTracker()`/staff-assignment check.

Pseudocode for the staff branch (after the resident branch ~line 277):
```php
$staffUser = $this->resolveStaffForDevice($device, $asset);   // BUILD: DeviceAssignment TARGET_STAFF, or asset->lone_worker_user_id
if ($staffUser) {
    $session = LoneWorkerSession::query()
        ->where('user_id', $staffUser->id)
        ->whereIn('status', ['active','overdue'])
        ->latest('started_at')->first();
    // BUILD: if none, create one (status active) OR escalate existing → status 'emergency'
    if ($session) {
        $session->update(['status'=>'emergency','emergency_triggered_at'=>now(), 'location_lat'=>$normalized['latitude'], 'location_lng'=>$normalized['longitude']]);
        app(LoneWorkerSignalService::class)->emitEmergency($session, 'Tracker '.$normalized['event_type'].' ('.data_get($normalized,'raw_payload.command_word').')');
    }
}
```

Why here (not in FrameRouter / parser):
- This is the single place a **paired** device is resolved to an `Asset` + canonical `Device`, lat/lng are normalised, and idempotency already applies. The resident SOS already lives here — the staff variant is symmetric.
- The location update (device/snapshot/state) and `meta['panic_active']` already happen above this block, so "last-known location + Locate now" data is written regardless.

Notes / gotchas:
- `ingest()` is **synchronous** in the listener loop (FrameRouter try/catch swallows exceptions → log only). `emitEmergency` itself try/catches internally, so a failure won't crash the listener. Consider keeping the staff branch defensive.
- **Idempotency**: man-down can repeat every frame. `LoneWorkerSignalService` dedups emergencies in 15-min windows per session (`LoneWorkerSignalService.php:172`), so repeated `emitEmergency` calls are safe (won't spawn duplicate alerts). But the `$session->update(status=emergency)` will fire repeatedly — harmless but consider a guard.
- **Pairing prerequisite**: a tracker only reaches `ingest()`'s SOS block if there is a **`AssetTracker` row with status='paired'** for the IMEI (line 36). Staff trackers must be paired the same way resident trackers are (`AssetTracker` + an `Asset`, ideally `category='personal_tracker'`), OR the ingest tracker-resolution must be widened. The canonical staff link is `DeviceAssignment(TARGET_STAFF)`.
- **No incident is auto-created** by either resident or lone-worker path — both terminate at `ControlRoomAlert`. If staff panic must also raise an incident, that is additional scope (Control Room SensorIncidentBridgeService pattern), not part of this hook.

---

## 5. Consumers of parsed frame / `sos_flag` / `man_down` / `alarm` (outside the parser)

| Consumer | File:line | Role |
|---|---|---|
| `FrameRouter::handleInbound` | FrameRouter.php:46,82 | parses frame, forwards paired report → ingest |
| `FleetTelemetryIngestService::ingest` | FleetTelemetryIngestService.php:27 | **the processor**: location + alarm→FleetSignal |
| `QueclinkAdapter::normalize` | QueclinkAdapter.php:29-34 | recomputes `sos_flag`/`tamper_flag`/`event_type` from `alarm` |
| `FleetSignalService::emit` | FleetSignalService.php:13 | FleetSignal + outbox + `DispatchFleetSignalOutbox` job |
| `DispatchFleetSignalOutbox::handle` | DispatchFleetSignalOutbox.php:27 | outbox → `SignalProcessingService` → `ControlRoomAlert` |
| `SignalProcessingService::ingestFromFleetSignal` | SignalProcessingService.php:448 | maps `vehicle.sos`/`resident.sos` → `fleet_*` code, builds context |
| `AssetTelemetrySnapshot` | model; written FleetTelemetryIngestService.php:110 | persists `sos_flag` column |
| `WebhookReceiverController` | app/Http/Controllers/Api/WebhookReceiverController.php | alt HTTP ingest path → same `FleetTelemetryIngestService::ingest()` (vendor webhooks, not the TCP listener) |

---

## 6. REUSE vs BUILD (one-liner)

- **REUSE**: TcpListener/FrameRouter/parser (already emit man_down+sos_flag); `FleetTelemetryIngestService::ingest()` location-update + the `sos_flag` block as the hook; `LoneWorkerSignalService::emitEmergency(LoneWorkerSession)`; the `triggerEmergency` set-status-then-emit sequence; `DeviceAssignment::TARGET_STAFF` for staff↔device pairing; `LocateNowService` for "Locate now".
- **BUILD**: an `isLoneWorkerTracker()/resolveStaffForDevice()` (DeviceAssignment TARGET_STAFF or new `Asset.lone_worker_user_id` / `category='lone_worker'`); staff branch in `ingest()` ~line 277 that finds/creates a `LoneWorkerSession` for the user and calls `emitEmergency`; staff-tracker pairing UI/flow + (optionally) a `device_id`/`asset_tracker_id` link column on `LoneWorkerSession`; surface last-known location + "Locate now" on the lone-worker session detail.
