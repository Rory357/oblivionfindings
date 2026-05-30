# Queclink Hub — Complete Remote Configuration Plan

> **For agentic workers:** Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox syntax for tracking. This plan extends [docs/queclink-gl30meu-resident-safety-plan.md](queclink-gl30meu-resident-safety-plan.md) and [docs/resident-tracking-live-server-fixes-and-ux-plan.md](resident-tracking-live-server-fixes-and-ux-plan.md). When this plan is finished, an operator can configure every supported setting on a GL30MEU pendant (and every other Queclink family we support) from the web UI alone — no USB cable, no Queclink desktop tool, no AT commands typed by hand.

**Goal:** Make `https://oblivionfindings.com/security-devices/integrations/queclink` the single, complete, comfortable surface for managing Queclink trackers. Every read and every write should be one click in the web UI; the operator should never need to think about command words, field positions, or hex masks.

**Scope:** GL30MEU pendants (resident-safety) are the priority. GV500CG vehicle trackers and any other Queclink families already supported by `CommandBuilder` should benefit from the same UI patterns.

**Architecture:** All writes continue to flow through the existing `QueclinkPendingCommand` queue. All reads continue to use `AT+GTRTO=…,2,…` followed by a `+RESP:GTALM` callback parsed by `ConfigurationSnapshotService`. No new daemons or transport layers — just better composition and richer coverage in `CommandBuilder` + the controller + the React UI.

**Tech Stack:** Laravel, Inertia, React, TypeScript, shadcn UI, lucide-react, existing Queclink TCP listener and pending-command queue.

---

## 1. Current State (audit summary)

What ships today at `/security-devices/integrations/queclink`:

| Area | Status |
| --- | --- |
| Listener health + port settings | ✓ done |
| Pending devices tray + claim flow | ✓ done |
| Paired devices list + release | ✓ done |
| Per-device Server connection (SRI) form, 13 fields | ✓ done |
| Per-device Global tracking (CFG) form, 18 fields | ✓ done |
| "Read full config" (GTRTO sub=2 → GTALM) | ✓ done |
| Configuration snapshot parser (BSI/SRI/CFG visible; others ignored) | ✓ partial |
| Resident safety profile preset | ✓ done |
| Debug console with SSE stream + polling fallback | ✓ done |
| Raw AT command REPL | ✓ done |
| Locate Now / Reboot / Set Interval presets | ✓ done |
| Command status sidebar (last 12) | ✓ done |
| IMS Cloud key form | ✓ scaffold only |

What the GL30MEU supports that we **don't yet expose**:

| Command | Purpose | Currently |
| --- | --- | --- |
| GTBSI | Read battery state info (battery, voltage, charging) | Read on demand only via GTRTO RTO sub=2 BSI section |
| GTPIN | SIM PIN unlock | not exposed |
| GTDOG | Protocol watchdog (auto-reboot if no server contact) | not exposed |
| GTTMA | Time adjustment / timezone | not exposed |
| GTNMD | Non-movement detection settings | not exposed |
| GTPDS | Power saving / sleep schedule | not exposed |
| GTWIF / GTWFI | Wi-Fi positioning beacons | not exposed |
| GTGEO | On-device geo-fence (1–10 fence slots) | not exposed |
| GTBT / GTBTS | Bluetooth name + pairing | not exposed |
| GTBID | Paired BLE accessory list | not exposed |
| GTWLT | White-list of numbers allowed to call/SMS | not exposed |
| GTUPC | OTA firmware update URL trigger | not exposed |
| GTFVR | Firmware version readback | not exposed |
| GTSOS | SOS phone-number list + report mode | not exposed (Function Button Mode lives under GTCFG) |
| GTMAN | Man-down sensitivity / duration / report | not exposed |
| GTTOW | Tamper / tow alarm thresholds | not exposed |
| GTSPD | Speed alarm thresholds (vehicle) | not exposed |
| GTIDL | Idle alarm thresholds (vehicle) | not exposed |
| GTHBM | Harsh-behaviour thresholds (vehicle) | not exposed |
| GTBPL | Battery-low alarm thresholds (already triggers events) | event handled, no config UI |

Plus UI gaps the user has flagged:

- The settings page is one long scroll — hard to navigate.
- No side-by-side **current device value vs. queued change** (diff view).
- No per-section read button (always reads the whole config).
- No per-field tooltip explaining the underlying AT command.
- Only one preset (Resident safety). Need Vehicle / Lone-worker / blank-template / custom.
- No bulk operations (apply a preset to 12 pendants).
- No queue management (cancel a queued command, retry a failed one).
- No per-device detail page.
- Debug console filters are minimal (IMEI only, no command_word / event-type / parse-error filters).
- No audit trail of who changed what.
- No mobile-friendly layout.

---

## 2. Product Requirements

### R1 — Every GL30MEU setting is configurable from the UI

A reasonable operator should be able to open a pendant's record and change anything the GL30MEU can be told to do over the air. They should never have to open the Queclink desktop tool.

### R2 — Read and write are symmetric

Every section that we can write also has a one-click "Read just this section" button. The current device value sits next to each input as a muted label so the operator can see at a glance what they're about to change and what they're not.

### R3 — Presets cover the three real-world fleets

- **Resident safety** (GL30MEU pendants on people we care for) — exists today.
- **Vehicle tracker** (GV500CG on staff/fleet vehicles).
- **Lone worker** (pendant on staff working alone in the community).
- **Blank** (zero everything out — used after factory reset).
- Each preset is a JSON document; operators can save their own presets and re-apply.

### R4 — UI is fast, modern, and not overwhelming

- Sub-tabs inside Device Settings (Identity / Server / Tracking / Alarms / Power / Connectivity / Bluetooth / Firmware) instead of one long form.
- Each sub-tab opens to ~1 screen of fields, not 3.
- Per-field tooltip shows the underlying AT command (e.g. "Sets `AT+GTCFG` field `function_button_mode`").
- Inline diff: an input that differs from the device's known value is highlighted; the device's current value renders as a small muted chip below it with a "revert" button.
- Status pills auto-refresh every 10 s without a full page reload.
- Mobile: each sub-tab stacks vertically and stays usable on a phone (operators do half their work on tablets).

### R5 — Queue is observable and recoverable

- Pending command list is filterable, searchable, and per-device.
- Operator can **cancel** a queued command, **retry** a failed/expired one, and see the original raw AT string + the ACK response payload.
- Failures show the device's `ACK` reason (e.g. "expired", "rejected", "auth failed").
- A small "Last 24 h" chart at the top of the device page shows command throughput and failure rate.

### R6 — Per-device detail page

`/security-devices/devices/{device}` becomes a real page (not just the tracking-history view). Tabs:
- **Overview** — identity, status, current assignment, current geofence.
- **Configuration** — the full sub-tabbed config UI (Identity / Server / Tracking / Alarms / Power / Connectivity / Bluetooth / Firmware).
- **Telemetry** — last 24 h battery/voltage chart, location heatmap, frame counts by event type.
- **Activity** — chronological log of commands queued, frames received, configuration changes.
- **Audit** — who did what, when.

### R7 — Bulk operations

A "Bulk apply" button on the Devices tab lets the operator:
- Pick N devices (filter + checkbox).
- Pick a preset OR a single setting (e.g. "set heartbeat to 5 minutes on all 12 selected pendants").
- Confirm a diff preview ("12 devices will get GTCFG continuous_send_interval=30").
- Queue all commands at once.

### R8 — Audit log

Every configuration write records:
- `who` (user_id),
- `when`,
- `which device`,
- `which command`,
- `before / after` (raw bytes + parsed diff),
- `outcome` (queued → sent → acked / failed).

Surfaces on the device's Audit tab + a global `/security-devices/integrations/queclink/audit` page.

---

## 3. AT Command Coverage Matrix (target)

Each row is one AT command we should be able to read + write from the UI. "Write" means the operator can change the value without typing the command. "Read" means the operator can fetch the current device value with one click.

| Command | Read | Write | UI sub-tab | Notes |
| --- | --- | --- | --- | --- |
| GTSRI | ✓ today | ✓ today | Server | Already done |
| GTCFG | ✓ today | ✓ today | Tracking | Split: tracking subset vs. alarm subset (move panic button + battery low here) |
| GTBSI | section parser exists | not applicable | Identity (read-only metrics) | Live battery/voltage/charge on the Overview card |
| GTPIN | new | new | Identity → SIM card | Three sub-fields: PIN, PIN2, PUK |
| GTDOG | new | new | Server → Watchdog | Auto-reboot if no server contact for N hours |
| GTTMA | new | new | Identity → Time | Timezone offset, daylight savings |
| GTNMD | new | new | Tracking → Non-movement | Threshold seconds + report mode |
| GTPDS | new | new | Power | Sleep schedule (start mode, time-of-day, wake interval, sleep depth) |
| GTWIF | new | new | Connectivity → Wi-Fi | Up to 7 SSIDs the device uses for positioning fallback |
| GTGEO | new | new | Alarms → Geofence | 10 on-device fence slots (id 0–9), each: enabled, mode, lat, lng, radius, breach |
| GTBT | new | new | Bluetooth | BLE name, pairing PIN, BLE on/off |
| GTBTS | new | new | Bluetooth → Scan settings | Scan duration, RSSI floor |
| GTBID | new | new | Bluetooth → Accessories | List of paired BLE accessories (sensors, bracelets) |
| GTWLT | new | new | Alarms → White-list | Phone numbers allowed to call/SMS the device (SOS callees) |
| GTUPC | new | new | Firmware | Push OTA firmware URL + version check |
| GTFVR | new | not applicable | Firmware (read-only) | Current firmware/hardware versions |
| GTSOS | new | new | Alarms → SOS | Up to 3 SOS phone numbers, SOS report mode, SOS sound on/off |
| GTMAN | new | new | Alarms → Man-down | Sensitivity, duration, alert on/off |
| GTTOW | new | new | Alarms → Tamper | Threshold, alert on/off |
| GTSPD | new | new | Alarms → Speed | Min/max km/h, duration (vehicle only) |
| GTIDL | new | new | Alarms → Idle | Threshold minutes (vehicle only) |
| GTHBM | new | new | Alarms → Driving | Harsh accel/brake/corner thresholds (vehicle only) |
| GTBPL | section parser exists | derived from GTCFG | Alarms → Battery low | Already configurable via CFG |
| GTRTO sub=1 (Locate now) | n/a | ✓ today | toolbar | Already done |
| GTRTO sub=2 (Read config) | n/a | ✓ today | per-section read buttons | Add per-section variants |
| GTRTO sub=3 (Reboot) | n/a | ✓ today | toolbar | Already done |

---

## 4. Implementation Plan

### Phase A — Foundation upgrades (no new UI yet)

#### Task A1: Extend `CommandBuilder` to cover every command word

**Files:**
- Modify: `app/Services/Queclink/CommandBuilder.php`
- Modify: `tests/Unit/Services/Queclink/CommandBuilderTest.php`

- [ ] Add one builder method per command word from the matrix above. Each method signature:

```php
public function gl30Pin(array $args, ?string $password = null): array;
public function gl30Dog(array $args, ?string $password = null): array;
public function gl30Tma(array $args, ?string $password = null): array;
public function gl30Nmd(array $args, ?string $password = null): array;
public function gl30Pds(array $args, ?string $password = null): array;
public function gl30Wifi(array $args, ?string $password = null): array;
public function gl30Geo(int $slot, array $args, ?string $password = null): array;
public function gl30Bt(array $args, ?string $password = null): array;
public function gl30Bts(array $args, ?string $password = null): array;
public function gl30Bid(array $args, ?string $password = null): array;
public function gl30Wlt(array $args, ?string $password = null): array;
public function gl30Upc(array $args, ?string $password = null): array;
public function gl30Sos(array $args, ?string $password = null): array;
public function gl30Man(array $args, ?string $password = null): array;
public function gl30Tow(array $args, ?string $password = null): array;
```

- [ ] Add a generic `buildAny(string $family, string $command, array $args, ?string $password = null)` that all of the above call into for shape consistency.
- [ ] Field-order references live in [`docs/queclink-gl30meu-resident-safety-plan.md`](queclink-gl30meu-resident-safety-plan.md) and the GL30MEU @Track Air Interface Protocol PDF (cited there). Each new method needs a one-paragraph docblock with the field order and a link to the PDF section.
- [ ] Tests: one per new method, asserting the produced raw command exactly matches a known-good AT string. Use real values (e.g. `AT+GTSOS=gl30,1,1234567890,...$`).

#### Task A2: Extend `ConfigurationSnapshotService` to parse every section

**Files:**
- Modify: `app/Services/Queclink/ConfigurationSnapshotService.php`
- Modify: `tests/Unit/Services/Queclink/ConfigurationSnapshotServiceTest.php` (new if missing)

- [ ] Add `mapPin()`, `mapDog()`, `mapTma()`, `mapNmd()`, `mapPds()`, `mapWifi()`, `mapGeo()` (returns array keyed by slot 0–9), `mapBt()`, `mapBts()`, `mapBid()`, `mapWlt()`, `mapSos()`, `mapMan()`, `mapTow()`, `mapUpc()`, `mapFvr()` parsers.
- [ ] Update `latestForDevice()` so the returned `snapshot` array has one key per section, with field names matching the writable form fields exactly. The UI can then do `snapshot.pin.pin = '1234'` to display.
- [ ] Tests: cover at least three real-world `GTALM` payloads from the live debug console (with PII redacted).

#### Task A3: Generic configuration write controller method

**Files:**
- Modify: `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php`
- Modify: `routes/security-devices.php`

- [ ] Replace the per-section `updateXyzConfiguration()` methods with one signature:

```php
POST /integrations/queclink/devices/{id}/configuration/{section}
```

where `section ∈ {server, tracking, alarms, power, connectivity, bluetooth, firmware, identity}`. The body is a section-specific payload validated by a section-specific Form Request.

- [ ] One `app/Http/Requests/Queclink/UpdateSectionRequest.php` per section. Form-request approach lets us share validation rules, error messages, and authorization across the controller and any bulk endpoints.
- [ ] Keep `updateServerConfiguration` and `updateGlobalConfiguration` as thin aliases for back-compat; the new section route is the canonical one.

#### Task A4: Per-section read endpoint

**Files:**
- Modify: `QueclinkHubController.php`

- [ ] Add `POST /integrations/queclink/devices/{id}/configuration/{section}/read` that queues `AT+GTRTO=family,2,SECTION_CODE` for just that section. Use the section→code map from the GL30 protocol: `BSI`, `SRI`, `CFG`, `PIN`, `DOG`, `TMA`, `NMD`, `PDS`, `GEO`, `BTS`, `WFI`, `BID`, `UPC`, `WLT`, `FVR`.
- [ ] When the `GTALM` response arrives, parse only that section and broadcast a per-device WebSocket message so the UI updates without a full reload.

#### Task A5: Command queue management endpoints

**Files:**
- Modify: `QueclinkHubController.php`
- Modify: `app/Models/Queclink/QueclinkPendingCommand.php`

- [ ] `POST /integrations/queclink/commands/{command}/cancel` — only allowed while status = `queued`. Sets `status = 'cancelled'`, records `cancelled_at` + `cancelled_by_user_id`.
- [ ] `POST /integrations/queclink/commands/{command}/retry` — only allowed when status ∈ {`failed`, `expired`, `cancelled`}. Creates a NEW `QueclinkPendingCommand` row with the same `raw_command` and a fresh `expires_at`, then returns the new id.
- [ ] Add `cancelled`, `cancelled_at`, `cancelled_by_user_id` to the `queclink_pending_commands` migration (new migration).
- [ ] Add a scope `recentFor(QueclinkDevice $device, int $limit = 50)` that returns the last N commands ordered by `created_at DESC`.

#### Task A6: Audit log

**Files:**
- Create: `database/migrations/YYYY_MM_DD_create_queclink_audit_log_table.php`
- Create: `app/Models/Queclink/QueclinkAuditEvent.php`
- Modify: `QueclinkHubController.php` (write an event on every config change, claim, release, cancel, retry)

- [ ] Migration columns: id, tenant_id, queclink_device_id (nullable), imei, user_id (nullable for system events), event_type (`config_write` / `claim` / `release` / `cancel` / `retry` / `preset_apply` / `bulk_apply`), section (nullable), payload_before (JSON), payload_after (JSON), raw_command (nullable), notes, created_at.
- [ ] Indexes: `(tenant_id, created_at DESC)`, `(queclink_device_id, created_at DESC)`, `(user_id, created_at DESC)`.
- [ ] Helper `QueclinkAuditEvent::log()` writes a row from a single array; controller calls it after every write.

---

### Phase B — Per-device detail page (single source of truth)

#### Task B1: Inertia route + controller for `/security-devices/devices/{device}`

**Files:**
- Modify: `routes/security-devices.php`
- Modify: `app/Domain/SecurityDevices/Http/Controllers/DeviceDetailController.php` (new or extend existing)
- Create: `resources/js/pages/security-devices/devices/show.tsx`

- [ ] Route resolves the canonical `Device` model, eager-loads `activeAssignment.assignable`, the Queclink shadow (`queclinkDevice`), the latest configuration snapshot, the last 50 frames, the last 50 pending commands, and any audit events.
- [ ] Page layout: hero with identity + connection state + battery (matches the resident sidebar visual language) + tab nav: Overview / Configuration / Telemetry / Activity / Audit.
- [ ] Each tab is a small component file under `resources/js/components/security-devices/device-detail/`.

#### Task B2: Overview tab

- [ ] Live status: connection state, last seen, frames/hr, last frame command word.
- [ ] Linked target: client / vehicle / staff / room (with link to that record's page).
- [ ] Quick actions: Locate Now, Read Full Config, Reboot, Acknowledge Panic (if `meta.panic_active`).
- [ ] Live battery & power card (re-use `ResidentSidebar`'s Battery & Power block).
- [ ] Geofence preview map (the resident's house geofence + the device's last position).

#### Task B3: Configuration tab (sub-tabbed)

- [ ] Sub-tab nav: Identity / Server / Tracking / Alarms / Power / Connectivity / Bluetooth / Firmware.
- [ ] Each sub-tab is its own component (e.g. `device-detail/config-identity.tsx`).
- [ ] Sub-tabs share a common pattern:
  - Top-right "Read this section" button (POSTs to the per-section read endpoint).
  - One form per AT command in this section.
  - Each input shows `currentValue` next to it (from the snapshot) with a "Revert to device" button when the queued value differs.
  - Save button per form (queues the matching command).
  - Per-field tooltip with the underlying AT command name and a one-liner.

#### Task B4: Telemetry tab

- [ ] Battery percentage line chart, last 24 h, 7 d, 30 d toggle.
- [ ] Voltage line chart on the same panel.
- [ ] Speed / motion timeline.
- [ ] Frame-count by event type bar chart.
- [ ] Re-uses the existing chart components in `resources/js/components/fleet-charts.tsx`.

#### Task B5: Activity tab

- [ ] Unified chronological feed of: frames received, commands sent, ACKs, configuration changes.
- [ ] Filter by event type. Search across raw frames.
- [ ] Pagination (server-side, 50 per page).

#### Task B6: Audit tab

- [ ] Reads from `queclink_audit_events`. Shows who, when, what.
- [ ] Diff renderer for `payload_before` / `payload_after`.

---

### Phase C — Configuration sub-tabs (one section per task)

Each sub-task adds one sub-tab to `Configuration`. Same pattern; only the fields differ.

#### Task C1: Identity sub-tab

**Sections covered:** GTBSI (read), GTPIN (SIM), GTTMA (time/timezone), GTFVR (firmware versions).

- [ ] Identity panel (read-only): IMEI, ICCID, IMSI, MAC, BLE MAC, firmware, hardware, BLE firmware, model.
- [ ] SIM card form: PIN (3-digit or empty), PIN2, PUK.
- [ ] Time panel: timezone offset (HH:MM), daylight-savings toggle.
- [ ] Firmware (read-only): current GTFVR readback + last-seen-at.

#### Task C2: Server sub-tab

**Sections covered:** GTSRI (existing), GTDOG (watchdog).

- [ ] Move the existing 13 Server fields here unchanged.
- [ ] Add Watchdog form (GTDOG): max time without server contact (hours), action on timeout (`reboot` / `notify`).

#### Task C3: Tracking sub-tab

**Sections covered:** GTCFG (tracking subset), GTNMD (non-movement).

- [ ] Strip the alarm-related fields out of the existing CFG form (Function Button Mode, SOS Report Mode, Battery Low Percentage → move to Alarms sub-tab).
- [ ] Keep: Device name, Mode, Continuous send interval, GNSS enable, GNSS timeout, AGPS, Wi-Fi report, LED on, Charge standby, GSM report mask, Report item mask, Event mask.
- [ ] Add Non-movement form (GTNMD): threshold seconds, report mode.

#### Task C4: Alarms sub-tab

**Sections covered:** GTSOS, GTMAN, GTTOW, GTGEO, GTWLT, GTSPD, GTIDL, GTHBM, plus the alarm fields moved out of GTCFG.

- [ ] SOS form (GTSOS): up to 3 phone numbers, report mode (location-only / call / SMS / call+SMS), sound on/off. **Plus the `function_button_mode` + `sos_report_mode` from GTCFG** rendered on the same card.
- [ ] Man-down form (GTMAN): sensitivity (1–10), duration seconds, enable/disable, report mode.
- [ ] Tamper form (GTTOW): threshold, enable/disable.
- [ ] Geofence editor (GTGEO): 10 fence slots, each editable in a small map widget (re-use the existing `LeafletMap` + draw-circle behaviour from `_site-geofence-dialog.tsx`).
- [ ] White-list form (GTWLT): comma-separated list of phone numbers allowed to call/SMS.
- [ ] Battery-low form (derived from GTCFG): threshold percentage + report mode.
- [ ] Vehicle-only: Speed alarm (GTSPD) min/max + duration. Idle alarm (GTIDL) minutes. Harsh-behaviour (GTHBM) thresholds. These render only when the device's category is `vehicle_tracker`.

#### Task C5: Power sub-tab

**Sections covered:** GTPDS (power saving).

- [ ] Start mode dropdown: `first wakeup at time` / `specified time of day` / `interval` / `disabled`.
- [ ] Specified time of day (HHMM).
- [ ] Wakeup interval hours.
- [ ] Sleep depth dropdown: `light` / `deep`.
- [ ] Re-show the live battery & power chart here for context.

#### Task C6: Connectivity sub-tab

**Sections covered:** GTWIF (Wi-Fi positioning beacons), the network mask from GTCFG.

- [ ] Wi-Fi list (GTWIF): up to 7 SSIDs the device uses for positioning fallback. Each row: SSID, security type, password.
- [ ] Network mask (re-rendered from GTCFG `gsm_report` field).
- [ ] Diagnostic readout: last RSRP, band, MCC/MNC, LAC/Cell ID, network type, IP address.

#### Task C7: Bluetooth sub-tab

**Sections covered:** GTBT (BLE), GTBTS (scan settings), GTBID (paired accessories).

- [ ] BLE form: name, pairing PIN, enable/disable.
- [ ] Scan settings: scan duration seconds, RSSI floor.
- [ ] Paired accessories: list of MAC addresses + nicknames + last seen. "Add" button queues a pairing command.

#### Task C8: Firmware sub-tab

**Sections covered:** GTUPC (OTA), GTFVR (read).

- [ ] Current versions panel (read-only): firmware, hardware, BLE firmware.
- [ ] OTA push form: target firmware URL, expected version, confirm dialog with the device's IMEI.
- [ ] Update history: list of past OTA attempts with timestamps and outcomes (parsed from frames).

---

### Phase D — UX upgrades on top of the new structure

#### Task D1: Diff view — current vs. queued

**Files:**
- Modify: every sub-tab component
- Create: `resources/js/components/security-devices/device-detail/field-diff.tsx`

- [ ] `<FieldDiff currentValue=… queuedValue=… onRevert=… />` component renders the input plus a muted chip showing the device's current value when the form value differs. Click chip → revert to current. The chip shows "loading" while the section is being re-read.

#### Task D2: Per-field tooltips

**Files:**
- Create: `resources/js/components/security-devices/device-detail/at-command-tooltip.tsx`
- Modify: every sub-tab component (wrap each label)

- [ ] Each label wraps a tooltip showing the AT command word + a one-line explanation pulled from a single source of truth (a JSON file or constant map): `resources/js/lib/queclink-field-docs.ts`.
- [ ] Example: hovering "Function button mode" shows "GTCFG field 16 — 0=disabled, 1=power off only, 2=SOS only, 3=both".

#### Task D3: Preset library

**Files:**
- Create: `app/Domain/SecurityDevices/Services/Queclink/PresetLibraryService.php`
- Create: `database/migrations/YYYY_MM_DD_create_queclink_presets_table.php`
- Create: `app/Models/Queclink/QueclinkPreset.php`
- Create: `resources/js/pages/security-devices/integrations/queclink-presets.tsx`
- Modify: `QueclinkHubController.php` — add `applyPreset`, `savePreset`, `deletePreset` methods.

- [ ] Migration: `id, tenant_id, name, slug, description, target_category (personal_tracker/vehicle_tracker/all), payload (JSON — section→{field→value}), created_by_user_id, is_system (bool), timestamps`.
- [ ] Seed three system presets: `resident-safety`, `vehicle-tracker`, `lone-worker`. The resident-safety preset must reproduce the existing `gl30ResidentSafetyProfile()` output exactly.
- [ ] UI: a presets management page (`/security-devices/integrations/queclink/presets`) with CRUD; on the device Configuration tab, a "Apply preset" dropdown above the sub-tab nav.
- [ ] Apply-preset flow: diff preview ("12 fields will change"), confirm, queue commands per affected section.

#### Task D4: Bulk operations

**Files:**
- Modify: `resources/js/pages/security-devices/integrations/queclink-hub.tsx` (Devices tab)
- Modify: `QueclinkHubController.php` — add `bulkAction` method.
- Create: `app/Http/Requests/Queclink/BulkActionRequest.php`

- [ ] Add a checkbox column to the Devices table; "Bulk apply" button enables when ≥1 device selected.
- [ ] Modal flow: pick action (preset / single setting / read full config / reboot all), preview diff, confirm.
- [ ] Server batches into one transaction; rate-limits to N pending commands per device.

#### Task D5: Queue management UI

**Files:**
- Modify: the Command status sidebar in `queclink-hub.tsx` (and the Activity tab from B5).
- Modify: `QueclinkHubController.php` — cancel/retry endpoints from A5.

- [ ] Each queued/failed command gets a row-level action menu (Cancel / Retry / Inspect raw).
- [ ] Inspect: open a side sheet with the raw outbound command, the device's ACK frame (if any), and the parsed payload.

#### Task D6: Debug console upgrades

**Files:**
- Modify: the Debug Console tab in `queclink-hub.tsx`

- [ ] Add filters: command_word (multi-select), direction, parse-status (ok / parse_error), free-text search across raw frame.
- [ ] Per-frame action: "Resend this command" (only enabled for outbound frames), "Copy raw", "Open device".
- [ ] Highlight panic-related frames (`GTSOS` / `GTMAN` / `GTLBC`) with a red border. Highlight battery_low (`GTBPL`) amber.

#### Task D7: Mobile-friendly layout

**Files:**
- All sub-tab components

- [ ] At `sm`, collapse the sub-tab nav into a `Select` element.
- [ ] Each form column stacks vertically; readbacks shrink to a single muted line below each label.
- [ ] Floating "Save" bar sticks to the bottom of the viewport when there are unsaved changes.

#### Task D8: WebSocket / SSE live updates

**Files:**
- Modify: `app/Listeners/Queclink/BroadcastFrameProcessed.php` (new) — fired by the TCP listener after each parsed frame.
- Modify: the device-detail page subscribes via Laravel Echo / Reverb to `queclink.device.{id}` channel.

- [ ] Listener broadcasts a small payload: device_id, command_word, parsed_payload (excerpt), is_safety_event.
- [ ] UI updates the live status pill + battery card without polling.

#### Task D9: Confirmation guards for risky changes

**Files:**
- Create: `resources/js/components/security-devices/device-detail/danger-confirm.tsx`
- Modify: sub-tabs (Server, Power, Firmware)

- [ ] Reboot, change main server host, change report mode, push OTA — require type-to-confirm dialog showing the device's IMEI and the action.

---

### Phase E — Tests, docs, deploy

#### Task E1: Backend tests

- [ ] One Feature test per new section endpoint, covering: validation rules, command queuing, audit log write, RBAC.
- [ ] One Feature test per cancel/retry path.
- [ ] One Feature test for the bulk endpoint with 5 devices.
- [ ] One Unit test per new `CommandBuilder` method.
- [ ] One Unit test per new `ConfigurationSnapshotService` parser.

```powershell
vendor\bin\pest.bat tests\Feature\Queclink
vendor\bin\pest.bat tests\Unit\Services\Queclink
```

#### Task E2: Frontend tests

- [ ] One React Testing Library test per sub-tab covering the happy path of a save (mocks `router.post`).
- [ ] One test for the bulk-apply modal flow.

```powershell
npm test -- resources/js/test/security-devices
```

#### Task E3: Documentation

- [ ] Update [README.md](README.md) with the Queclink Hub feature list.
- [ ] Add `docs/queclink-field-reference.md` — for every field the UI exposes, document the AT command field index, allowed values, and a sentence on its meaning. This is the single source of truth that `queclink-field-docs.ts` is generated from.
- [ ] Add `docs/queclink-presets.md` — explain the system presets, how to write custom ones.

#### Task E4: Smoke test on the live server

- [ ] Open `https://oblivionfindings.com/security-devices/devices/{amelia_device}` and walk every sub-tab.
- [ ] Apply the Resident safety preset to Amelia's pendant. Confirm `+ACK:GTCFG` arrives in the debug console.
- [ ] Test SOS button (short-press) — confirm the `+RESP:GTSOS` frame, panic banner, and acknowledge flow all work end-to-end.
- [ ] Read just the Server section (per-section read button) — confirm only that section refreshes.
- [ ] Cancel a queued command — confirm status flips to `cancelled` and no further frames fire.
- [ ] Push a no-op firmware update with a dry-run URL — confirm `+ACK:GTUPC` arrives.

#### Task E5: Deploy

- [ ] Run migrations on the live server: `php artisan migrate --force`.
- [ ] Seed the system presets: `php artisan db:seed --class=QueclinkPresetsSeeder`.
- [ ] Restart the listener + queue: `sudo systemctl restart oblivion-queclink.service oblivionfindings-queue.service`.

---

## 5. File Manifest (target)

| Purpose | Path |
| --- | --- |
| Hub page | [resources/js/pages/security-devices/integrations/queclink-hub.tsx](resources/js/pages/security-devices/integrations/queclink-hub.tsx) |
| Hub controller | [app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php](app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php) |
| Command builder | [app/Services/Queclink/CommandBuilder.php](app/Services/Queclink/CommandBuilder.php) |
| Snapshot service | [app/Services/Queclink/ConfigurationSnapshotService.php](app/Services/Queclink/ConfigurationSnapshotService.php) |
| Locate Now service | [app/Services/Queclink/LocateNowService.php](app/Services/Queclink/LocateNowService.php) |
| Parser | [app/Services/Queclink/AtTrackProtocolParser.php](app/Services/Queclink/AtTrackProtocolParser.php) |
| Device detail page (new) | `resources/js/pages/security-devices/devices/show.tsx` |
| Device-detail sub-tabs (new) | `resources/js/components/security-devices/device-detail/` (8 files) |
| Field-diff component (new) | `resources/js/components/security-devices/device-detail/field-diff.tsx` |
| AT-command tooltip (new) | `resources/js/components/security-devices/device-detail/at-command-tooltip.tsx` |
| Field docs source (new) | `resources/js/lib/queclink-field-docs.ts` |
| Preset library service (new) | `app/Domain/SecurityDevices/Services/Queclink/PresetLibraryService.php` |
| Preset model + migration (new) | `app/Models/Queclink/QueclinkPreset.php`, migration |
| Preset UI (new) | `resources/js/pages/security-devices/integrations/queclink-presets.tsx` |
| Section form requests (new) | `app/Http/Requests/Queclink/UpdateSectionRequest.php` per section |
| Bulk request (new) | `app/Http/Requests/Queclink/BulkActionRequest.php` |
| Audit model + migration (new) | `app/Models/Queclink/QueclinkAuditEvent.php`, migration |
| Pending-command migration (new) | adds `cancelled_at`, `cancelled_by_user_id` |
| Broadcast listener (new) | `app/Listeners/Queclink/BroadcastFrameProcessed.php` |
| GL30MEU PDF (vendor, off-repo) | `C:\Users\steph\OneDrive\Desktop\Quecklink\GL30MEUR01_Develop_Suit_A02V18_Eng_(Doc_and_Tool)\Document\GL30MEUR01 @Track Air Interface Protocol V2.04.pdf` |
| Field reference doc (new) | `docs/queclink-field-reference.md` |
| Presets doc (new) | `docs/queclink-presets.md` |

---

## 6. Acceptance Criteria

- An operator can open any GL30MEU pendant on the live server, switch to the Configuration tab, and change any of the following without ever leaving the web UI:
  - device name, time zone, SIM PIN, watchdog timeout
  - server hosts/ports/heartbeat, report mode, SACK, PSM, protocol format
  - tracking mode, cadence, GNSS, AGPS, Wi-Fi fallback, LED, charge standby, non-movement
  - SOS phone numbers, SOS report mode, function button mode, man-down, tamper, battery-low, on-device geofences (up to 10), white-list
  - power-saving start mode + wake schedule + sleep depth
  - Wi-Fi positioning SSIDs
  - BLE name + pairing + scan settings + accessories
  - OTA firmware URL
- Every section has a "Read this section" button that refreshes only that section's snapshot.
- Every input shows the device's current value next to it; a queued change highlights and offers "Revert".
- The Resident safety preset still applies in one click; two more presets (Vehicle, Lone-worker) ship.
- The operator can save their own presets.
- The operator can bulk-apply a preset (or a single setting) to ≥10 devices in one action with a diff preview.
- The operator can cancel a queued command, retry a failed one, and inspect the raw outbound + ACK payloads.
- Every config write is audited with who/when/before/after.
- The new Per-Device detail page exists at `/security-devices/devices/{id}` with Overview / Configuration / Telemetry / Activity / Audit tabs.
- The Debug Console filters by command_word, direction, parse status, and free-text.
- Mobile (sm breakpoint) shows the same UI in a stacked layout; all critical actions are still one tap away.
- Risky actions (reboot, change main host, push OTA) require type-to-confirm.
- Live battery / panic / connection state updates without a page reload (WebSocket or SSE).

---

## 7. Notes for the Fresh Context

- Read this plan, then read [docs/queclink-gl30meu-resident-safety-plan.md](queclink-gl30meu-resident-safety-plan.md) (the prerequisite) and the audit summary at the top of this plan for the current code map.
- Reference the GL30MEU PDF for every new field's exact position and meaning. Field positions vary across firmware revisions — copy from the V2.04 PDF.
- Keep all writes asynchronous via the existing `QueclinkPendingCommand` queue.
- Keep all reads asynchronous: write `AT+GTRTO=…,2,SECTION` → wait for `+RESP:GTALM` → parse → broadcast.
- Do not invent new transports. The TCP listener already handles everything.
- Use shadcn UI components throughout. No bespoke form widgets unless absolutely necessary.
- Use NZ-English copy (per project conventions).
- All file paths in this plan are relative to the repository root `C:\Users\steph\Herd\oblivionfindings`.
- The Queclink desktop tool will remain a fallback for advanced debugging; the goal of this plan is that operators never *need* it for routine work.
