# Resident Tracking — Feature‑Complete Unification Plan

> **For agentic workers:** Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task‑by‑task. Steps use checkbox syntax for tracking. This plan extends — and depends on — `docs/queclink-gl30meu-resident-safety-plan.md` (Queclink ingest, Locate Now service, panic/battery normalisation already landed).

**Goal:** Make Resident Tracking and the Client Profile *one unified surface*. The Client Profile Location tab must mirror the Resident Tracking page (map + resident‑side sidebar, map slightly larger), with cross‑linking, complete device telemetry, panic state always visible, complete battery/charging state, and the correct geofence scope on each page (all active geofences on the fleet dashboard; the resident's specific house geofence in the profile).

**Architecture:** Reuse the canonical `Device` + `DeviceAssignment` + `AssetGeofence` models. No new schemas required for the unification work itself — only additive `meta` keys for panic state and a dedicated "house" geofence link. Keep Queclink protocol parsing inside `app/Services/Queclink`, and surface normalised health on the canonical `devices` row.

**Tech Stack:** Laravel, Inertia, React, TypeScript, shadcn UI, lucide‑react, Leaflet, existing fleet telemetry ingest pipeline.

---

## Current Context (Audit Findings)

### What works today

- **Resident Tracking dashboard** ([resources/js/pages/fleet-assets/resident-tracking/index.tsx](resources/js/pages/fleet-assets/resident-tracking/index.tsx)) — hero stat strip (Tracked / Online / Offline / In Zone / Outside Zone / Low Battery), Leaflet map (left, `3fr`) + resident sidebar (right, `2fr`) with All / Outside / Alerts tabs, search, photo + battery bar + zone badge + Locate Now button + safety badge per resident, and a bottom row of Recent Alerts / Active Outings / Safety Analytics cards. Auto‑refreshes every 30 s via `router.reload({ only: [...] })`.
- **Resident Tracking history** ([resources/js/pages/fleet-assets/resident-tracking/history.tsx](resources/js/pages/fleet-assets/resident-tracking/history.tsx)) — header cards (live point, history start, points in view, latest event), date filter, map with current marker + historical polyline, timeline list.
- **Client Profile Location tab** ([resources/js/components/client-location-tab.tsx](resources/js/components/client-location-tab.tsx)) — consent banner, 3 status cards (Battery / Last Seen / Consent), single full‑width map card, separate "Movement History" card with date filter + CSV export. Loaded by [resources/js/pages/operations/clients/show.tsx](resources/js/pages/operations/clients/show.tsx:7225) when `tab === 'location'`.
- **Backend wiring** — [ResidentTrackingController::index](app/Http/Controllers/FleetAssets/ResidentTrackingController.php:32) builds the fleet payload; [ClientController::buildLocationData](app/Http/Controllers/ClientController.php:1657) builds the per‑client payload. Both already query the same canonical `Device` + `DeviceAssignment` records.
- **Locate Now** — service at [app/Services/Queclink/LocateNowService.php](app/Services/Queclink/LocateNowService.php), routed on both surfaces (`fleet-assets.resident-tracking.locate-now`, `operations.clients.location.locate-now`).
- **Geofence model** — [app/Models/AssetGeofence.php](app/Models/AssetGeofence.php) supports `type` (circle | polygon), `scope` (resident | asset | house), `shape` JSON (circle: `{lat,lng,radius_m,color}`; polygon: `{coordinates:[…]}`), `is_active` toggle, optional `asset_id` and `site_id` foreign keys, and a `asset_geofence_assignments` pivot (many‑to‑many) that is not currently used by either controller.

### Gaps the user explicitly flagged

1. **Layouts diverge.** Resident Tracking is map+sidebar at `3fr_2fr`; Client Profile Location tab is full‑width map with status cards stacked above. The Client Profile should match — same split, map slightly larger.
2. **Panic state is event‑only, never neutral.** [resident-tracking/index.tsx:587](resources/js/pages/fleet-assets/resident-tracking/index.tsx:587) and [client-location-tab.tsx:426](resources/js/components/client-location-tab.tsx:426) only render a badge when `last_safety_event` is set. There is no "Panic not currently active" pill, so operators can't tell at a glance whether the device is reporting healthy. There is also no rendered timestamp for the last panic event.
3. **Battery often blank in the wild.** Live screenshot shows "Low battery 0%" with no charging/voltage detail. The data model has `battery_voltage_mv`, `charging_status`, `external_power`, `last_power_event`, `battery_low_threshold`, but the UI only shows the % bar; the other fields are passed through props but never displayed.
4. **Other device features hidden.** IMEI, firmware version, BLE MAC, RSRP/signal, SIM/ICCID, network type, GNSS satellites, accuracy, heading, odometer — all available from the Queclink ingest plan — are not surfaced anywhere outside the raw Security Devices detail page.
5. **Geofence scope is wrong on both pages.**
   - **Fleet page:** [ResidentTrackingController::index:67](app/Http/Controllers/FleetAssets/ResidentTrackingController.php:67) filters to `scope = resident` OR an asset typed `house`. Any other active geofence (perimeter, garden, kitchen no‑go, vehicle yard) is silently dropped. User wants **all active geofences**.
   - **Profile page:** [ClientController::buildLocationData:1771](app/Http/Controllers/ClientController.php:1771) returns every site‑level geofence. The profile must show **only the resident's specific house geofence**.
6. **No cross‑linking.** From the resident row on the fleet page you can click into history but not into the profile; from the profile there is one tiny "Fleet Dashboard" link with no jump‑to‑this‑resident behaviour.
7. **"In Zone" pill is missing from the profile.** The fleet page renders an In Zone / Outside / On Outing pill per resident; the profile only shows it implicitly via geofence colour on the map.

### What the database can already give us

From [docs/queclink-gl30meu-resident-safety-plan.md](docs/queclink-gl30meu-resident-safety-plan.md) and the canonical `Device` model:

- **Identity/hardware:** `device_uid`, `name`, `serial_number`, `mac_address`, `imei`, `model`, `manufacturer`, `firmware_version`, `provider`.
- **Live state:** `status`, `health_status`, `last_seen_at`, `last_signal_at`, `latitude`, `longitude`, `battery_level`, `battery_updated_at`.
- **Meta keys produced by `FleetTelemetryIngestService`:** `battery_status`, `battery_voltage_mv`, `battery_low_threshold`, `charging_status`, `external_power`, `power_event`, `last_safety_event`, `speed`, `heading`, `accuracy`, plus event/raw payload for richer drilldown.
- **Per‑report telemetry:** `fleet_telemetry_events.{latitude, longitude, accuracy_m, speed_kph, heading_deg, altitude_m, battery_pct, external_power, motion_status, ignition, event_type, address, vendor_message_id, raw_payload}`.

All the data is in place; the work below is composition + a small number of additive fields/routes/scopes.

---

## Product Requirements

### R1 — Mirror the Resident Tracking layout in the Client Profile

- Replace the existing single‑column ClientLocationTab with a two‑column grid: `lg:grid-cols-[3fr_2fr]`, same as the fleet dashboard.
- **Left (3fr):** Leaflet map showing the resident's live marker and the resident's house geofence only. Height parity with the fleet map (`height={520}`).
- **Right (2fr):** Resident detail sidebar (see R2 / R3 / R4 / R5). Card uses the same `flex flex-col` + scrollable content pattern as the fleet sidebar.
- Above the grid, render a compact hero strip with the resident's photo, name, house, tracker name/serial, status dot, and a "Back to Fleet Dashboard" link (deep link `?focus=<client_id>` — see R7).

### R2 — Panic state must always be present

- Add a **Panic** section to both the fleet resident row and the client profile sidebar.
- When `meta.last_safety_event` is *not* `sos`, `vehicle_sos`, or `man_down`, render a neutral pill: **"Panic not currently active"** (lucide `ShieldCheck`, `text-status-success`).
- When a panic event is or has been received, render the critical state with the event label, last event timestamp (`formatRelativeTime`), and resolved/active state — see R2.1.
- Track a separate `panic_active` boolean in `meta` (introduced below). An "active" panic is one whose event arrived more recently than the most recent operator acknowledgement on the corresponding `ControlRoomAlert`. When the alert is acknowledged or resolved, `panic_active` flips to `false` but `last_safety_event` and `last_safety_event_at` remain populated for history.

#### R2.1 — Always render last panic event

- Add `last_safety_event_at` (ISO string) to both Device props and the existing `meta` write path in [FleetTelemetryIngestService](app/Services/Fleet/FleetTelemetryIngestService.php). Persist it whenever a `GTSOS` / `GTLBC` / `GTMAN` frame is normalised.
- UI rule for both surfaces:
  - `panic_active === true` → red banner with the event label, `last_safety_event_at` relative timestamp, an "Acknowledge" button (operator with `fleet.manage`).
  - `panic_active === false` and `last_safety_event_at` present → neutral pill "Panic not currently active" plus muted secondary line "Last panic: `<event label>` · `<relative time>`".
  - `last_safety_event_at` null → neutral pill only, secondary line "No panic events recorded".

### R3 — Complete battery / power telemetry

- Introduce a dedicated **Battery & Power** card in the sidebar with all eight fields rendered from the Queclink ingest:
  - Percentage (`battery_level`).
  - State label: `Charging` | `Low battery` | `Battery not reported` | `<n>%`.
  - Voltage (`battery_voltage_mv`, formatted as `3.78 V`).
  - Battery low threshold (`battery_low_threshold`).
  - Charging status (`charging_status`).
  - External power flag (`external_power`).
  - Last power event (`last_power_event` with timestamp).
  - Last battery update (`battery_updated_at`).
- Show "—" when a field is null. Never show "Low battery 0 %" when battery is unknown — fall back to "Battery not reported" (existing `getBatteryState` already covers this; the bug is upstream — the ingest writes `battery_level = 0` when the frame has no battery value).
- **Fix the upstream bug:** in [FleetTelemetryIngestService](app/Services/Fleet/FleetTelemetryIngestService.php), only update `battery_level` when `normalized.battery_pct !== null`. When null, keep the previous value and `meta.battery_status = 'unknown'`.

### R4 — Complete device feature dump

- Add a collapsible **Device Details** card to the sidebar that surfaces everything the Queclink stack already records. Group:
  - **Identity:** name, model, manufacturer, IMEI, serial, MAC, BLE MAC, `device_uid`, provider, firmware, BLE firmware, hardware version.
  - **Connectivity:** SIM/ICCID, IMSI, network type (e.g. 4G), RSRP, BER, band, MCC/MNC, LAC, Cell ID, last frame at, current TCP session id.
  - **GNSS:** accuracy/HDOP, satellites in use, altitude, heading, speed, motion status.
  - **Configuration:** SOS report mode, function button mode, low‑battery percentage, GNSS enable, AGPS, Wi‑Fi report, LED on, charge standby mode (read from `meta.config_snapshot` populated by `ConfigurationSnapshotService`).
  - **Commands:** latest pending/sent/acked `QueclinkPendingCommand` for this device (word, status, timestamps, error reason).
- Use a `Collapsible` (shadcn) defaulting closed. Show "Open device console" link → `/security-devices/devices/{id}` for the full hub view.

### R5 — Geofence scope per surface

- **Fleet Resident Tracking page:** change [ResidentTrackingController::index:67](app/Http/Controllers/FleetAssets/ResidentTrackingController.php:67) to load **all `is_active = true` geofences** (drop the `scope = resident` and `asset_type = house` filter). Add an `applies_to` field per geofence in the payload describing scope (resident / asset / house / vehicle / perimeter / custom) so the map legend can categorise.
- **Client Profile Location tab:** restrict `buildLocationData` geofence query to **the resident's specific house geofence**. The "specific house geofence" is the `AssetGeofence` row where:
  1. `is_active = true`, AND
  2. Either `asset_id` is the client's site's house asset (preferred — see R5.1), OR
  3. `scope = 'house'` AND `site_id = client.site_id`.
- Add an explicit **"In Zone"** pill to the profile sidebar, mirroring the fleet badge. Use the same `geofence_status` computation that the fleet controller already runs (extract to a shared service — see Task 6).

#### R5.1 — House geofence linkage

- Add an optional `house_geofence_id` foreign key on the `clients` table referencing `asset_geofences.id` (nullable, indexed). This makes the "resident's specific house" lookup deterministic.
- Backfill: for each active client with a `site_id`, set `house_geofence_id` to the first matching `AssetGeofence` where `scope = 'house'` and `site_id = client.site_id`. Where none exists, leave null and emit a log warning.
- Add a UI affordance on the Sites & Locations module to manage house geofences per site (out of scope for this plan; track as a follow‑up).

### R6 — Unify the look & feel (one platform)

- Adopt a shared `ResidentSidebar` component used by both surfaces. The component renders the resident row layout (photo + status dot, name + zone pill + panic pill, battery + last seen, Locate Now button, expandable Battery & Power and Device Details cards). On the fleet dashboard it is rendered per resident inside the list; on the profile it is rendered once with the focused resident.
- Adopt a shared `ResidentMap` component wrapping `LeafletMap` with consistent height (520 px), clustering on/off via prop, geofence palette, marker click behaviour (history deep‑link), and live‑refresh badge.
- Use identical typography, spacing, badge colours, and lucide icons on both surfaces.

### R7 — Cross‑linking

- On the fleet dashboard, each resident row gets a secondary "Open Profile" affordance (icon button + tooltip) → `/operations/clients/{client_id}?tab=location`.
- On the profile, the "Fleet Dashboard" link becomes "Open in Fleet Dashboard" → `/fleet-assets/resident-tracking?focus={client_id}`. The dashboard reads `?focus=` on mount, scrolls the resident into view, and opens its detail row.
- Add breadcrumbs on the profile location tab: Fleet & Assets › Resident Tracking › `<name>` (only when entered with `?from=fleet`).
- Both surfaces share the same auto‑refresh cadence (30 s) and emit identical Inertia partial reloads — no full page navigations between them.

### R8 — Live indicators parity

- Both surfaces show:
  - 30 s auto‑refresh badge ("Updated `<time ago>`").
  - Locate Now status pill (`Queued` / `Sent` / `Acknowledged` / `Failed` / `Expired`).
  - Last panic event (per R2).
  - Geofence status (per R5).
  - Battery state with full power detail (per R3).

### R9 — Acceptance

- The screenshot scenario (Amelia, Harbour Respite, Unknown / Low battery 0 %) must render:
  - "Panic not currently active" pill.
  - "Last panic: —" subline (no event recorded).
  - "Battery not reported" rather than "Low battery 0 %".
  - Resident‑specific house geofence rendered as a coloured circle on the map.
  - "In Zone" pill if Amelia's last coordinate is inside that geofence.
  - Battery & Power card showing voltage, threshold (20 %), charging status (`—`), last power event (`—`), last update timestamp.
  - Device Details card collapsible, showing IMEI, firmware, model, BLE MAC, etc., as populated by the Queclink ingest.
  - On the client profile, the same panic pill, same geofence, same In Zone badge, same battery panel, with the map at `3fr` and the sidebar at `2fr`.

---

## Implementation Plan

### Phase 0 — Foundation (no UI changes)

#### Task 0.1: Fix the "0 % when unknown" battery regression

**Files:**
- Modify: `app/Services/Fleet/FleetTelemetryIngestService.php`
- Modify: `tests/Feature/FleetTelemetryIngestTest.php`

- [ ] Add a failing test: when a Queclink frame arrives with `battery_pct = null`, the device's `battery_level` must not be overwritten and `meta.battery_status` must become or remain `unknown`.
- [ ] Guard the `battery_level` write behind `if ($normalized['battery_pct'] !== null)`.
- [ ] When battery is null, set `$meta['battery_status'] = $meta['battery_status'] ?? 'unknown'`. Never let battery_status fall back to `low` purely because the existing value happens to be `0`.
- [ ] Run:

```powershell
vendor\bin\pest.bat tests\Feature\FleetTelemetryIngestTest.php
```

#### Task 0.2: Persist `last_safety_event_at` and `panic_active` in meta

**Files:**
- Modify: `app/Services/Fleet/FleetTelemetryIngestService.php`
- Modify: `app/Services/ControlRoom/SignalProcessingService.php` (or wherever `resident.sos` alerts are acknowledged)
- Modify: `tests/Feature/FleetTelemetryIngestTest.php`
- Modify: `tests/Feature/ControlRoom/SosAcknowledgementTest.php` (new if missing)

- [ ] On any SOS/man‑down/LBC frame, set `meta.last_safety_event = '<event>'`, `meta.last_safety_event_at = now()->toISOString()`, `meta.panic_active = true`.
- [ ] When the corresponding `ControlRoomAlert` is acknowledged or resolved, flip `meta.panic_active = false` on the device (keep the timestamp + event label for history).
- [ ] Tests:
  - SOS frame → device meta has `panic_active = true` and `last_safety_event_at` set.
  - Operator acknowledges alert → device meta has `panic_active = false`, `last_safety_event` and `last_safety_event_at` unchanged.

#### Task 0.3: Add `clients.house_geofence_id`

**Files:**
- Create: `database/migrations/YYYY_MM_DD_HHMMSS_add_house_geofence_to_clients.php`
- Modify: `app/Models/Client.php`
- Create: `database/seeders/BackfillHouseGeofenceSeeder.php` (or one‑off command)
- Modify: `tests/Feature/Clients/HouseGeofenceTest.php`

- [ ] Migration: nullable foreign key `house_geofence_id` on `clients`, indexed, `nullOnDelete()`.
- [ ] `Client::houseGeofence()` relation → `belongsTo(AssetGeofence::class)`.
- [ ] Backfill: for each active client where `house_geofence_id` is null, find the first `AssetGeofence` where `is_active = true`, `scope = 'house'`, `site_id = client.site_id`; set the FK. Log a warning per client without a match.
- [ ] Tests:
  - Backfill assigns the right geofence when one exists.
  - Backfill skips clients without a matching geofence.
  - Setting `house_geofence_id` to null is allowed.

#### Task 0.4: Extract `GeofenceStatusService`

**Files:**
- Create: `app/Services/Tracking/GeofenceStatusService.php`
- Modify: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php`
- Modify: `app/Http/Controllers/ClientController.php`
- Create: `tests/Unit/Services/Tracking/GeofenceStatusServiceTest.php`

- [ ] Move the circle haversine + polygon point‑in‑polygon (implement Ray casting — currently a TODO) logic out of the controller into the service.
- [ ] Public API:

```php
public function evaluate(?float $lat, ?float $lng, ?AssetGeofence $geofence): string;
// returns one of: 'in_zone' | 'outside_zone' | 'unknown'
```

- [ ] Replace both controller call sites with `$this->geofenceStatus->evaluate(...)`.
- [ ] Tests: circle in, circle out, polygon in, polygon out, null geofence → 'unknown', null lat/lng → 'unknown'.

---

### Phase 1 — Backend payload changes

#### Task 1.1: Fleet dashboard returns all active geofences

**Files:**
- Modify: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php`
- Modify: `tests/Feature/FleetAssets/ResidentTrackingPageTest.php` (or add one)

- [ ] Replace the filtered geofence query in `index()` with `AssetGeofence::where('is_active', true)->get()`.
- [ ] Extend `buildMapGeofences()` to pass `scope` (and a derived `applies_to` label) into the payload so the front‑end can colour them.
- [ ] When evaluating each resident's `geofence_status`, only consider the geofence linked via `client.house_geofence_id` (fallback: same logic as today, but log it as a deprecated path).
- [ ] Tests:
  - All active geofences are in the page payload.
  - A resident with `house_geofence_id` set evaluates against that geofence even if other geofences cover the same coordinate.
  - Inactive geofences are excluded.

#### Task 1.2: Client profile returns only the resident's house geofence

**Files:**
- Modify: `app/Http/Controllers/ClientController.php` (method `buildLocationData`)
- Modify: `tests/Feature/Clients/ClientLocationTabTest.php` (or add one)

- [ ] Replace the site‑wide query in `buildLocationData` with `$client->houseGeofence` (fallback to the legacy site+scope filter only if the FK is null).
- [ ] Return an additional payload field `geofenceStatus: 'in_zone' | 'outside_zone' | 'unknown'` computed via `GeofenceStatusService`.
- [ ] Tests:
  - Profile payload includes exactly one geofence (the resident's house) when `house_geofence_id` is set.
  - `geofenceStatus` is computed correctly against the live coordinate.
  - Profile payload returns no geofences when neither the FK nor the legacy filter matches.

#### Task 1.3: Surface complete device telemetry to both pages

**Files:**
- Modify: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php`
- Modify: `app/Http/Controllers/ClientController.php`
- Modify: front‑end types in [resources/js/pages/fleet-assets/resident-tracking/index.tsx](resources/js/pages/fleet-assets/resident-tracking/index.tsx) and [resources/js/components/client-location-tab.tsx](resources/js/components/client-location-tab.tsx)

- [ ] Extend the resident / tracker payload with:

```php
'imei' => $device->imei,
'mac' => $device->mac_address,
'model' => $device->model,
'manufacturer' => $device->manufacturer,
'firmware_version' => $device->firmware_version,
'hardware_version' => $meta['hardware_version'] ?? null,
'ble_firmware' => $meta['ble_firmware'] ?? null,
'ble_mac' => $meta['ble_mac'] ?? null,
'sim_iccid' => $meta['iccid'] ?? null,
'imsi' => $meta['imsi'] ?? null,
'network_type' => $meta['network_type'] ?? null,
'rsrp' => $meta['rsrp'] ?? null,
'ber' => $meta['ber'] ?? null,
'band' => $meta['band'] ?? null,
'mcc' => $meta['mcc'] ?? null,
'mnc' => $meta['mnc'] ?? null,
'cell_id' => $meta['cell_id'] ?? null,
'lac' => $meta['lac'] ?? null,
'gnss_accuracy_m' => $meta['accuracy'] ?? null,
'gnss_satellites' => $meta['satellites'] ?? null,
'altitude_m' => $meta['altitude'] ?? null,
'heading_deg' => $meta['heading'] ?? null,
'speed_kph' => $meta['speed'] ?? null,
'last_frame_at' => $meta['last_frame_at'] ?? null,
'panic_active' => (bool) ($meta['panic_active'] ?? false),
'last_safety_event' => $meta['last_safety_event'] ?? null,
'last_safety_event_at' => $meta['last_safety_event_at'] ?? null,
'battery_updated_at' => optional($device->battery_updated_at)->toISOString(),
'config_snapshot' => $meta['config_snapshot'] ?? null,
```

- [ ] Mirror the same payload extension in `buildLocationData` so the profile receives identical data shape.
- [ ] Update the front‑end `Resident` / `ClientLocationData.tracker` TypeScript types.
- [ ] No tests required for this task beyond the controller test asserting these keys are present.

#### Task 1.4: Add `focus` query‑param plumbing on the fleet page

**Files:**
- Modify: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php`
- Modify: `resources/js/pages/fleet-assets/resident-tracking/index.tsx`

- [ ] Controller passes `focus_client_id` to the page when `?focus=<id>` is present and the user is authorised to see the client.
- [ ] Front‑end: on mount, if `focus_client_id` is set, scroll the matching resident into view, set the sidebar's active resident, and pan the map to that marker.

---

### Phase 2 — Shared UI primitives

#### Task 2.1: Extract `ResidentSidebar` component

**Files:**
- Create: `resources/js/components/resident-tracking/resident-sidebar.tsx`
- Modify: `resources/js/pages/fleet-assets/resident-tracking/index.tsx`
- Modify: `resources/js/components/client-location-tab.tsx`
- Create: `resources/js/test/resident-sidebar.test.tsx`

- [ ] Public props:

```ts
type ResidentSidebarProps = {
    resident: Resident;
    onLocateNow?: () => void;
    onOpenProfile?: () => void;
    onOpenHistory?: () => void;
    variant: 'fleet-row' | 'profile-detail';
    canManage: boolean;
};
```

- [ ] Renders, in order:
  1. Photo + status dot + name + preferred name + house.
  2. Pills row: In Zone / Outside / On Outing + Panic (active or not‑active) + last command status.
  3. Last seen + last frame at.
  4. Location line (`display_location` with `coordinates` subline when both differ).
  5. **Battery & Power** card (battery state label, %, voltage, threshold, charging, external power, last power event, last update).
  6. **Device Details** collapsible (identity, connectivity, GNSS, configuration; collapsed by default in `fleet-row` variant, open by default in `profile-detail`).
  7. Action row: Locate Now, Open Profile (link), Open History (link), Open Device Console (link).
- [ ] Visual tone:
  - Critical panic → red banner inset; not‑active panic → muted green pill.
  - Low battery → red pulsing bar; unknown battery → amber "Battery not reported".
  - Charging → green pill with bolt icon + percentage subline.
- [ ] Tests:
  - All three panic states render the correct copy.
  - "Locate Now" button is disabled when no tracker present.
  - Device Details collapsible toggles open/close.
  - In `profile-detail` variant, Open Profile button is hidden; in `fleet-row` variant, it is shown.

#### Task 2.2: Extract `ResidentMap` component

**Files:**
- Create: `resources/js/components/resident-tracking/resident-map.tsx`
- Modify: `resources/js/pages/fleet-assets/resident-tracking/index.tsx`
- Modify: `resources/js/components/client-location-tab.tsx`

- [ ] Wrap `LeafletMap` with consistent props: `height = 520`, `clustering`, palette, polyline options.
- [ ] Accept `markers`, `geofences`, `polyline`, `center`, `zoom`, `onMarkerClick`.
- [ ] Render a small overlay "Updated `<n>` s ago" badge driven by an `updatedAt` prop.
- [ ] Render a legend (collapsed by default) listing visible geofences with their colour swatch and `applies_to` label.

#### Task 2.3: Extract `PanicStatusBadge` component

**Files:**
- Create: `resources/js/components/resident-tracking/panic-status-badge.tsx`
- Used by `resident-sidebar.tsx`.

- [ ] Props: `{ panicActive: boolean; lastSafetyEvent: string | null; lastSafetyEventAt: string | null; onAcknowledge?: () => void; canManage: boolean }`.
- [ ] Renders one of three states:
  - Active panic — red banner with the event label (mapping via the existing `safetyEventLabel`), "Triggered `<relative>` ago", Acknowledge button if `canManage`.
  - Cleared but historical — green "Panic not currently active" pill, subline "Last panic: `<label>` · `<relative>`".
  - Never panicked — green "Panic not currently active" pill, subline "No panic events recorded".

---

### Phase 3 — Rework the two surfaces

#### Task 3.1: Rebuild the Client Profile Location tab

**Files:**
- Modify: `resources/js/components/client-location-tab.tsx`
- Modify (only if Inertia data shape changes): `app/Http/Controllers/ClientController.php`
- Modify: `resources/js/test/client-location-tab.test.tsx`

- [ ] Replace the existing markup inside the `<div className="space-y-4 mt-4">` with:
  - Top hero strip (`flex items-center gap-3`): photo + name + house + status dot + breadcrumb back to Fleet Dashboard.
  - Consent banner (existing — keep).
  - "No tracker assigned" banner (existing — keep, but only when really null).
  - **Two‑column grid** `lg:grid-cols-[3fr_2fr]`:
    - **Left column:** `<ResidentMap … />` with the resident's marker + the resident's house geofence + (optional) polyline when "Show movement history" is on.
    - **Right column:** `<ResidentSidebar variant="profile-detail" resident={resident} … />`.
  - Below the grid: Movement History card (existing — keep, but in its own row, full width).
- [ ] Remove the old status cards (`Battery / Last Seen / Consent`) — their data is now inside the sidebar.
- [ ] Tests:
  - Sidebar renders the resident's panic state, battery & power card, and device details.
  - Map shows exactly one geofence and the resident marker.
  - Movement history still loads and exports CSV.

#### Task 3.2: Rebuild the Fleet Resident Tracking sidebar list

**Files:**
- Modify: `resources/js/pages/fleet-assets/resident-tracking/index.tsx`
- Modify: `resources/js/test/resident-tracking.test.tsx`

- [ ] Replace the inline resident row (lines ~546 – 640) with `<ResidentSidebar variant="fleet-row" … />`. Keep the existing list virtualisation/scroll behaviour.
- [ ] Add the "Open Profile" affordance per row (icon button → `/operations/clients/{client_id}?tab=location&from=fleet`).
- [ ] Add the `?focus=` deep‑linking on mount.
- [ ] Update the Recent Alerts card and Active Outings card so clicking an alert deep‑links into the relevant resident (scroll + focus).
- [ ] Tests:
  - The row renders panic state and battery & power.
  - Clicking Open Profile navigates with the right params.
  - `?focus=<id>` scrolls the resident into view.

#### Task 3.3: Add "Acknowledge panic" mutation

**Files:**
- Modify: `routes/fleet-assets.php`
- Modify: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php`
- Modify: `routes/operations.php`
- Modify: `app/Http/Controllers/ClientController.php`
- Modify: `tests/Feature/Queclink/PanicAcknowledgeTest.php` (new)

- [ ] Routes:
  - `POST /fleet-assets/resident-tracking/{client}/acknowledge-panic` → `acknowledgePanic`
  - `POST /operations/clients/{client}/location/acknowledge-panic` → `acknowledgePanic`
- [ ] Each controller method:
  - Authorises `fleet.manage`.
  - Resolves the latest open `ControlRoomAlert` with `source IN (tracker, resident_tracker)` and `category = 'panic'` (or matching alert_type) for the client.
  - Marks it acknowledged (existing `ControlRoomAlertService::acknowledge` if present, otherwise inline status update + `acknowledged_at` + `acknowledged_by_user_id`).
  - Flips `device.meta.panic_active = false`.
  - Returns to the same surface with a flash message.
- [ ] Tests:
  - Operator with `fleet.manage` can acknowledge.
  - Without permission → 403.
  - After acknowledgement, `panic_active` is false and the existing alert row is `ack`.

#### Task 3.4: Recent Alerts card shows panic prominently

**Files:**
- Modify: `resources/js/pages/fleet-assets/resident-tracking/index.tsx`

- [ ] In the bottom Recent Alerts card, render any open panic alert at the top, with a red border and a one‑click "Acknowledge" button. Clicking the resident's name opens the profile.

---

### Phase 4 — Tests & verification

#### Task 4.1: Browser smoke test the screenshot scenarios

- [ ] Start the dev server (`herd run dev` or equivalent) and load:
  - `https://oblivionfindings.test/fleet-assets/resident-tracking`
  - `https://oblivionfindings.test/operations/clients/9012?tab=location`
- [ ] Verify (Amelia / Harbour Respite):
  - Panic pill shows "Panic not currently active" on both surfaces.
  - Last panic line reads "No panic events recorded" (assuming none have happened on this fixture).
  - Battery panel renders "Battery not reported" rather than "Low battery 0 %".
  - Map shows only the resident's house geofence on the profile; all active geofences on the fleet page.
  - "In Zone" pill is visible on both surfaces with the same value.
  - Layout on the profile is `3fr` map / `2fr` sidebar at lg width.
  - Cross‑links work: profile → fleet (with focus=9012), fleet → profile (with tab=location).
- [ ] Trigger a Queclink GTSOS fixture (or replay a raw frame) and verify:
  - Critical panic state appears on both surfaces within 30 s.
  - Acknowledge button appears for `fleet.manage`, disappears after click, state flips to "Panic not currently active" + "Last panic: SOS received · just now".

#### Task 4.2: Run the full test suite

```powershell
vendor\bin\pest.bat tests\Feature\Queclink
vendor\bin\pest.bat tests\Feature\FleetTelemetryIngestTest.php
vendor\bin\pest.bat tests\Feature\FleetAssets\ResidentTrackingPageTest.php
vendor\bin\pest.bat tests\Feature\Clients\ClientLocationTabTest.php
vendor\bin\pest.bat tests\Unit\Services\Tracking\GeofenceStatusServiceTest.php
npm test -- resources/js/test/resident-tracking.test.tsx
npm test -- resources/js/test/client-location-tab.test.tsx
npm test -- resources/js/test/resident-sidebar.test.tsx
npm run types
npm run build
vendor\bin\pint.bat --dirty --test
git diff --check
```

#### Task 4.3: Migration & rollout

- [ ] Run the new migration on Herd local first; confirm seed data via `php artisan tinker`.
- [ ] Backfill `house_geofence_id` via `php artisan db:seed --class=BackfillHouseGeofenceSeeder`.
- [ ] Verify on the remote test server (`oblivionfindings.com`) before merging to main.

---

## File Manifest

| Purpose | Path |
| ------- | ---- |
| Fleet dashboard page | [resources/js/pages/fleet-assets/resident-tracking/index.tsx](resources/js/pages/fleet-assets/resident-tracking/index.tsx) |
| Fleet history page | [resources/js/pages/fleet-assets/resident-tracking/history.tsx](resources/js/pages/fleet-assets/resident-tracking/history.tsx) |
| Profile location tab | [resources/js/components/client-location-tab.tsx](resources/js/components/client-location-tab.tsx) |
| Profile host page | [resources/js/pages/operations/clients/show.tsx](resources/js/pages/operations/clients/show.tsx) |
| Fleet controller | [app/Http/Controllers/FleetAssets/ResidentTrackingController.php](app/Http/Controllers/FleetAssets/ResidentTrackingController.php) |
| Profile controller | [app/Http/Controllers/ClientController.php](app/Http/Controllers/ClientController.php) |
| Telemetry ingest | [app/Services/Fleet/FleetTelemetryIngestService.php](app/Services/Fleet/FleetTelemetryIngestService.php) |
| Locate Now service | [app/Services/Queclink/LocateNowService.php](app/Services/Queclink/LocateNowService.php) |
| Config snapshot | [app/Services/Queclink/ConfigurationSnapshotService.php](app/Services/Queclink/ConfigurationSnapshotService.php) |
| Geofence model | [app/Models/AssetGeofence.php](app/Models/AssetGeofence.php) |
| Device model | [app/Domain/SecurityDevices/Models/Device.php](app/Domain/SecurityDevices/Models/Device.php) |
| Existing Queclink plan | [docs/queclink-gl30meu-resident-safety-plan.md](docs/queclink-gl30meu-resident-safety-plan.md) |
| Shared map (existing) | [resources/js/components/leaflet-map.tsx](resources/js/components/leaflet-map.tsx) |
| Shared sidebar (new) | `resources/js/components/resident-tracking/resident-sidebar.tsx` |
| Shared map wrapper (new) | `resources/js/components/resident-tracking/resident-map.tsx` |
| Panic badge (new) | `resources/js/components/resident-tracking/panic-status-badge.tsx` |
| Geofence status service (new) | `app/Services/Tracking/GeofenceStatusService.php` |
| House geofence migration (new) | `database/migrations/*_add_house_geofence_to_clients.php` |
| Backfill seeder (new) | `database/seeders/BackfillHouseGeofenceSeeder.php` |

---

## Acceptance Criteria

- The Client Profile Location tab has the same map‑left / sidebar‑right layout as Resident Tracking, with the map at `3fr` and the sidebar at `2fr`.
- Both surfaces always render a Panic indicator. When no event is active, the indicator reads "Panic not currently active" and includes a "Last panic" subline (event label + relative time, or "No panic events recorded").
- An operator with `fleet.manage` can acknowledge an active panic from either surface; the device's `panic_active` flag flips to false and the indicator updates without a full page reload.
- Battery & Power is shown as a complete panel: state label, percentage, voltage, threshold, charging status, external power, last power event, last update. "Battery not reported" appears when no battery value is available; "Low battery 0 %" never appears falsely.
- Device Details (IMEI, firmware, MAC, BLE, SIM, network, GNSS, configuration) is reachable from both surfaces.
- The Fleet Resident Tracking map renders every active geofence (with a legend describing scope).
- The Client Profile Location map renders only the resident's specific house geofence, sourced from `clients.house_geofence_id`.
- The "In Zone" / "Outside Zone" pill appears on both surfaces and uses the same `GeofenceStatusService`.
- Clicking a resident on the fleet page opens their profile with `?tab=location&from=fleet`; clicking "Open in Fleet Dashboard" on the profile takes the operator back with `?focus=<id>` and scrolls the resident into view.
- Both surfaces auto‑refresh every 30 s using Inertia partial reloads (`only: […]`), with no full page navigation between fleet and profile.
- CSV export from the profile movement history still works and now includes the panic state at the time of each point if available.
- Existing Queclink consent masking is preserved on every payload.

---

## Notes for the Fresh Context

- Start by reading [docs/queclink-gl30meu-resident-safety-plan.md](docs/queclink-gl30meu-resident-safety-plan.md). It is the prerequisite plan; many of the fields used here are produced by its ingest changes.
- Do not introduce a new Queclink integration UI. Extend the existing hub + profile + fleet surfaces only.
- Keep all device commands asynchronous through the existing `QueclinkPendingCommand` queue.
- Preserve the existing consent gating: location coordinates remain masked when `client_consents` is not active; battery and connectivity may still display.
- Use NZ‑English copy ("In Zone", "Outside Zone", currency as NZD where it shows up — none here yet) per project conventions.
- All file paths in this plan are relative to the repository root `C:\Users\steph\Herd\oblivionfindings`.
