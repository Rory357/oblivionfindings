# Queclink GL30MEU Resident Safety Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Surface the GL30MEU as a resident safety tracker, including Locate Now actions, panic/SOS handling, battery warnings, and charging state on Resident Tracking and Client Location surfaces.

**Architecture:** Keep Queclink-specific protocol parsing in `app/Services/Queclink`, normalize device runtime data through fleet telemetry services, and expose small resident/client controller actions for UI commands. Store current device health on the canonical `devices` row (`battery_level`, `battery_updated_at`, `meta`) and keep raw Queclink frames as the audit source.

**Tech Stack:** Laravel, Inertia, React, TypeScript, shadcn UI, lucide-react, existing Queclink TCP listener and pending-command queue.

---

## Current Context

The live GL30MEU is already reporting to the direct TCP listener. Recent server work fixed consent masking so location now reaches resident tracking. Existing implementation already includes:

- Queclink frame parsing in `app/Services/Queclink/AtTrackProtocolParser.php`.
- GL30 command building in `app/Services/Queclink/CommandBuilder.php`.
- Device configuration snapshots from `+RESP:GTALM` in `app/Services/Queclink/ConfigurationSnapshotService.php`.
- Queclink command queueing in `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php`.
- Resident map UI in `resources/js/pages/fleet-assets/resident-tracking/index.tsx`.
- Client Location tab in `resources/js/components/client-location-tab.tsx`.

Use these vendor docs for exact GL30MEU command semantics:

- `C:\Users\steph\OneDrive\Desktop\Quecklink\GL30MEUR01_Develop_Suit_A02V18_Eng_(Doc_and_Tool)\Document\GL30MEUR01 @Track Air Interface Protocol V2.04.pdf`
- `C:\Users\steph\OneDrive\Desktop\Quecklink\GL30MEUR01_Develop_Suit_A02V18_Eng_(Doc_and_Tool)\Document\GL30MEUR01 User Manual V1.02.pdf`

---

## Device Details We Can Pull

### Identity and Firmware

- IMEI.
- Device name/model name, for example `GL30MEU`.
- Queclink protocol version.
- Tracker firmware version.
- Hardware version.
- BLE firmware version.
- BLE name and BLE MAC address.
- Remote IP/socket connection state from the live TCP listener.
- First seen, last seen, and last frame timestamps.
- Last raw command status, serial number, ACK status, and failure/expiry reason.

### Location and Movement

- Latitude.
- Longitude.
- Human-readable address for the latest coordinate when reverse geocoding is available.
- Coordinate fallback when no address has been resolved yet or no address can be found.
- GNSS UTC timestamp.
- Server received timestamp.
- Speed in km/h.
- Heading/course/azimuth.
- Altitude.
- GNSS accuracy/HDOP.
- Satellites in use when present in the frame.
- Motion status, derived from speed when the device does not report a richer value.
- MCC, MNC, LAC, Cell ID.
- Mileage/odometer when present in the report item mask.
- Event type that caused the location update, such as periodic location, SOS, man down, low battery, power on, power off, or tamper.

### Battery and Power Health

These are the fields to support for battery health:

- Battery percentage/capacity.
- Battery voltage in millivolts, from BSI/config readback when available.
- Charging status, for example `charging`, `not charging`, `stopped charging`, or `unknown`.
- External power/charge power status when reported by event frames or BSI readback.
- Battery low event from `GTBPL`.
- Battery low threshold from GL30 `GTCFG` field `battery_low_percentage`.
- Battery updated timestamp.
- Charging standby mode from GL30 `GTCFG`.
- Last power event, including power on (`GTPNA`) and power off (`GTPFA`).
- Low battery warning state, derived from live battery percentage <= configured threshold.
- Battery unknown state, when no battery value has been received yet.

### Network and Connectivity

- SIM status.
- ICCID.
- IMSI.
- Network type, for example 4G.
- CS/PS status.
- PDP status.
- Device IP address.
- Main DNS and backup DNS.
- RSRP/signal strength and BER.
- Band.
- MCC and MNC.
- Report and buffer counts.
- Current sending status.
- Queclink listener connection state.

### Safety Events

- Panic/SOS event from `GTSOS`.
- GL-series man-down event from `GTMAN`.
- Location-by-call/SOS event from `GTLBC`.
- Tamper/tow event from `GTTOW`.
- Battery-low event from `GTBPL`.
- Power on/off events from `GTPNA` and `GTPFA`.
- Heartbeat from `GTHBD`.
- Geofence enter/exit if the device sends `GTGEO`, `GTGIN`, or `GTGOT`.

### Configurable GL30MEU Settings

- Server registration: main host, main port, backup host, backup port, report mode, heartbeat interval, buffer mode, SACK, manual network registration, protocol format.
- Global tracking: device name, mode, continuous send interval, GNSS timeout, GNSS enable, AGPS mode, Wi-Fi report/fallback, report item mask, event mask.
- Safety: function button mode, SOS report mode, man-down/SOS event mask coverage.
- Power: battery low threshold, LED mode, charge standby mode, wakeup interval, scheduled wake time.

---

## Product Requirements

1. Add a button labeled exactly `Locate Now` on both pages:
   - Resident Tracking page: `resources/js/pages/fleet-assets/resident-tracking/index.tsx`.
   - Client Location tab: `resources/js/components/client-location-tab.tsx`.
2. `Locate Now` must queue the Queclink current location command for the resident's paired GL30MEU.
3. The UI must show clear pending/sent/acked/failed state for the most recent Locate Now command.
4. The GL30MEU hardware panic button must be configured through Device Settings, not by asking the operator to connect over USB.
5. Panic/SOS/man-down frames must create a visible safety alert and be easy to identify in the debug console and resident timeline/history.
6. Low battery must show a warning wherever the resident tracker is shown.
7. If the device is charging, the UI must say `Charging`.
8. Battery unknown must not look healthy. Show `Battery not reported` rather than a green state.
9. All device health fields must respect consent masking for location, but battery, charging, and connectivity can still be shown for an assigned tracker because they do not expose location coordinates.
10. Resident Tracking, Location History, map popups, CSV exports, and the Client Location tab must display a human-readable address when one is available.
11. If no address is available, the UI must fall back to coordinates rather than showing a blank location.
12. Address lookup must reuse the existing fleet reverse-geocoding configuration, cache, and rate limiting, and must not block TCP listener ingestion.

---

## Implementation Tasks

### Task 1: Extend Queclink Parsing for Power and Safety State

**Files:**

- Modify: `app/Services/Queclink/AtTrackProtocolParser.php`
- Modify: `app/Services/Fleet/Telemetry/QueclinkAdapter.php`
- Modify: `tests/Unit/Services/Queclink/AtTrackProtocolParserTest.php`

- [ ] Add parser tests for GL30MEU power/battery payloads.

Run:

```powershell
vendor\bin\pest.bat tests\Unit\Services\Queclink\AtTrackProtocolParserTest.php
```

Expected before implementation: tests fail because charging/power metadata is not normalized.

Test cases to add:

```php
it('normalizes GL30 low battery with percentage', function () {
    $frame = $this->parser->parse('+RESP:GTBPL,970204,861106050000000,GL30MEU,15,00,1,1,0.0,0,0.0,175.241655,-37.723657,20260518050806,0530,0001,0017E102,01,15,0.0,20260518050810,0463$');

    expect($frame->payload['event_type'])->toBe('battery_low')
        ->and($frame->payload['battery'])->toBe(15.0)
        ->and($frame->payload['alarm'])->toBe('battery_low');
});

it('normalizes GL30 SOS and man down as critical safety events', function () {
    $sos = $this->parser->parse('+RESP:GTSOS,970204,861106050000000,GL30MEU,,00,1,1,0.0,0,0.0,175.241655,-37.723657,20260518050806,0530,0001,0017E102,01,15,0.0,20260518050810,0464$');
    $manDown = $this->parser->parse('+RESP:GTMAN,970204,861106050000000,GL30MEU,,00,1,1,0.0,0,0.0,175.241655,-37.723657,20260518050806,0530,0001,0017E102,01,15,0.0,20260518050810,0465$');

    expect($sos->payload['sos_flag'])->toBeTrue()
        ->and($manDown->payload['sos_flag'])->toBeTrue();
});
```

- [ ] Update `AtTrackProtocolParser::normalisePayload()` to set normalized fields:

```php
$payload['battery'] = $this->numOrNull($fields[4] ?? null);
$payload['power_event'] = null;
$payload['charging_status'] = null;
```

For command-specific cases:

```php
case 'GTBPL':
    $payload['alarm'] = 'battery_low';
    $payload['event_type'] = 'battery_low';
    $payload['battery'] = $this->numOrNull($fields[4] ?? null);
    break;
case 'GTPNA':
    $payload['alarm'] = 'power_on';
    $payload['event_type'] = 'power_on';
    $payload['power_event'] = 'power_on';
    break;
case 'GTPFA':
    $payload['alarm'] = 'power_off';
    $payload['event_type'] = 'power_off';
    $payload['power_event'] = 'power_off';
    break;
case 'GTSOS':
case 'GTLBC':
    $payload['alarm'] = 'sos';
    $payload['event_type'] = 'vehicle_sos';
    $payload['sos_flag'] = true;
    break;
case 'GTMAN':
    $payload['alarm'] = 'man_down';
    $payload['event_type'] = 'man_down';
    $payload['sos_flag'] = true;
    break;
```

- [ ] Update `QueclinkAdapter::normalize()` to pass these through:

```php
'battery_pct' => $payload['battery'] ?? $payload['battery_pct'] ?? null,
'external_power' => $payload['external_power'] ?? null,
'raw_payload' => $payload,
```

- [ ] Run the unit tests again and commit:

```powershell
vendor\bin\pest.bat tests\Unit\Services\Queclink\AtTrackProtocolParserTest.php
git add app\Services\Queclink\AtTrackProtocolParser.php app\Services\Fleet\Telemetry\QueclinkAdapter.php tests\Unit\Services\Queclink\AtTrackProtocolParserTest.php
git commit -m "Improve GL30 power and safety frame parsing"
```

### Task 2: Store Device Health on Ingest

**Files:**

- Modify: `app/Services/Fleet/FleetTelemetryIngestService.php`
- Modify: `tests/Feature/FleetTelemetryIngestTest.php`

- [ ] Add failing tests that battery and charging state update the canonical device without requiring a location change.

Test shape:

```php
public function test_gl30_battery_low_updates_device_health_and_event(): void
{
    config(['services.telemetry.ingest_token' => 'test-token']);

    // Create client, valid tracking consent, personal tracker asset, AssetTracker, canonical Device, and DeviceAssignment.
    // Post payload with imei, alarm=battery_low, battery=15, lat/lng.
    // Assert Device battery_level is 15, meta.battery_status is low, and FleetTelemetryEvent event_type is battery_low.
}
```

- [ ] In `FleetTelemetryIngestService`, when a device exists, merge health metadata into `$deviceUpdates['meta']`:

```php
if ($normalized['battery_pct'] !== null) {
    $deviceUpdates['battery_level'] = $normalized['battery_pct'];
    $deviceUpdates['battery_updated_at'] = now();
    $meta['battery'] = $normalized['battery_pct'];
    $meta['battery_level'] = $normalized['battery_pct'];
}

$raw = $normalized['raw_payload'] ?? [];
foreach (['charging_status', 'battery_voltage_mv', 'power_event', 'external_power'] as $key) {
    if (array_key_exists($key, $raw)) {
        $meta[$key] = $raw[$key];
    }
}

$threshold = (int) ($meta['battery_low_threshold'] ?? data_get($raw, 'battery_low_threshold', 20));
$meta['battery_status'] = $normalized['battery_pct'] === null
    ? ($meta['battery_status'] ?? 'unknown')
    : ((int) $normalized['battery_pct'] <= $threshold ? 'low' : 'normal');
```

- [ ] Keep charging independent from battery percentage:

```php
if (($meta['charging_status'] ?? null) === 'charging' || ($meta['external_power'] ?? false)) {
    $meta['battery_status_label'] = 'Charging';
}
```

- [ ] Run:

```powershell
vendor\bin\pest.bat tests\Feature\FleetTelemetryIngestTest.php
git add app\Services\Fleet\FleetTelemetryIngestService.php tests\Feature\FleetTelemetryIngestTest.php
git commit -m "Store GL30 battery and charging health"
```

### Task 3: Add a Reusable Locate Now Service

**Files:**

- Create: `app/Services/Queclink/LocateNowService.php`
- Modify: `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php`
- Create or modify tests: `tests/Feature/Queclink/LocateNowTest.php`

- [ ] Create a small service that queues a `request_location` command for a canonical `Device`, client, or Queclink IMEI.

Service responsibilities:

```php
final class LocateNowService
{
    public function queueForDevice(Device $device, User $user): QueclinkPendingCommand
    {
        // Resolve QueclinkDevice by device_id or IMEI.
        // Validate paired status.
        // Build CommandBuilder::requestLocation(CommandBuilder::FAMILY_GL30M).
        // Queue command with status queued and expires_at now()->addMinutes(5).
    }
}
```

- [ ] Add tests:

```php
public function test_locate_now_queues_gl30_request_location_command(): void
{
    // Create paired QueclinkDevice linked to canonical Device.
    // Call LocateNowService.
    // Assert queclink_pending_commands has command_word GTRTO and raw_command starts AT+GTRTO=gl30,1.
}
```

- [ ] Run:

```powershell
vendor\bin\pest.bat tests\Feature\Queclink\LocateNowTest.php
git add app\Services\Queclink\LocateNowService.php tests\Feature\Queclink\LocateNowTest.php
git commit -m "Add Queclink Locate Now service"
```

### Task 4: Add Locate Now Routes for Resident and Client Pages

**Files:**

- Modify: `routes/fleet-assets.php`
- Modify: `routes/operations.php` or the route file that defines `/operations/clients/{client}/location/history`
- Modify: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php`
- Modify: `app/Http/Controllers/ClientController.php`
- Modify: `tests/Feature/SecurityDevices/ResidentTrackingRefactorTest.php` or add `tests/Feature/Queclink/LocateNowRoutesTest.php`

- [ ] Add routes:

```php
Route::post('/resident-tracking/{client}/locate-now', [ResidentTrackingController::class, 'locateNow'])
    ->whereNumber('client')
    ->name('fleet-assets.resident-tracking.locate-now');

Route::post('/operations/clients/{client}/location/locate-now', [ClientController::class, 'locateNow'])
    ->whereNumber('client')
    ->name('operations.clients.location.locate-now');
```

- [ ] Controller behavior:

```php
public function locateNow(Request $request, Client $client, LocateNowService $locateNow)
{
    $device = $this->registry
        ->forClient($client->tenant_id ?? 1, $client->id)
        ->where('domain', 'tracking')
        ->firstOrFail();

    $command = $locateNow->queueForDevice($device, $request->user());

    return back()->with('success', 'Locate Now queued. The tracker will report on its next connection.');
}
```

- [ ] Tests must prove:
  - Authorized user can queue Locate Now for Amelia.
  - No tracker returns a validation error.
  - Unpaired/non-Queclink tracker is rejected.
  - Queued command has a 5 minute expiry.

- [ ] Run:

```powershell
vendor\bin\pest.bat tests\Feature\Queclink\LocateNowRoutesTest.php
git add routes app\Http\Controllers tests\Feature\Queclink\LocateNowRoutesTest.php
git commit -m "Expose Locate Now on resident tracker routes"
```

### Task 5: Add Locate Now Buttons to Both UI Surfaces

**Files:**

- Modify: `resources/js/pages/fleet-assets/resident-tracking/index.tsx`
- Modify: `resources/js/components/client-location-tab.tsx`
- Modify or add: `resources/js/test/resident-tracking.test.tsx`
- Modify or add: `resources/js/test/client-location-tab.test.tsx`

- [ ] Extend resident props with:

```ts
type Resident = {
    id: number;
    client_id: number;
    locate_now_url?: string;
    last_command_status?: 'queued' | 'sent' | 'acked' | 'failed' | 'expired' | null;
};
```

- [ ] Add a `Locate Now` button to the resident list row/card, only when the resident has a tracker:

```tsx
<Button
    type="button"
    size="sm"
    variant="outline"
    onClick={() => router.post(resident.locate_now_url ?? `/fleet-assets/resident-tracking/${resident.client_id}/locate-now`, {}, {
        preserveScroll: true,
    })}
>
    <MapPin className="mr-1 h-4 w-4" />
    Locate Now
</Button>
```

- [ ] Add a `Locate Now` button to `ClientLocationTab` near the current location/map header:

```tsx
<Button
    type="button"
    size="sm"
    onClick={() => router.post(`/operations/clients/${clientId}/location/locate-now`, {}, {
        preserveScroll: true,
    })}
    disabled={!tracker}
>
    <MapPin className="mr-1 h-4 w-4" />
    Locate Now
</Button>
```

- [ ] Show command feedback:
  - `Queued` while waiting for the device to receive it.
  - `Sent` after outbound command is written.
  - `Acknowledged` after ACK/SACK comes back.
  - `Failed` or `Expired` if not delivered.

- [ ] Run:

```powershell
npm test -- resources/js/test/queclink-hub.test.tsx
npm test -- resources/js/test/client-location-tab.test.tsx
npm test -- resources/js/test/resident-tracking.test.tsx
git add resources/js
git commit -m "Add Locate Now controls to resident tracking"
```

### Task 6: Configure Panic Button and Safety Reporting from Device Settings

**Files:**

- Modify: `app/Services/Queclink/CommandBuilder.php`
- Modify: `app/Domain/SecurityDevices/Http/Controllers/Integrations/QueclinkHubController.php`
- Modify: `resources/js/pages/security-devices/integrations/queclink-hub.tsx`
- Modify: `tests/Unit/Services/Queclink/CommandBuilderTest.php`
- Modify: `tests/Feature/Queclink/QueclinkHubControllerTest.php`

- [ ] Confirm GL30 Device Settings exposes these fields:
  - `function_button_mode`
  - `sos_report_mode`
  - `battery_low_percentage`
  - `event_mask`
  - `report_item_mask`
  - `led_on`
  - `charge_standby_mode`

- [ ] Add a one-click preset called `Resident safety profile` in Device Settings. It should queue `GTCFG` with:

```php
[
    'continuous_send_interval_seconds' => 30,
    'battery_low_percentage' => 20,
    'function_button_mode' => 1,
    'sos_report_mode' => 1,
    'gnss_enable' => 1,
    'agps_mode' => 1,
    'wifi_report' => 2,
    'led_on' => 1,
    'charge_standby_mode' => 0,
]
```

- [ ] Preserve manual override fields for advanced testing. Do not hide raw values.

- [ ] Add tests that `Resident safety profile` builds `AT+GTCFG=gl30,...` with the panic/function button and SOS report fields enabled.

- [ ] Run:

```powershell
vendor\bin\pest.bat tests\Unit\Services\Queclink\CommandBuilderTest.php
vendor\bin\pest.bat tests\Feature\Queclink\QueclinkHubControllerTest.php
npm test -- resources/js/test/queclink-hub.test.tsx
git add app resources tests
git commit -m "Add GL30 resident safety profile"
```

### Task 7: Create Panic, Low Battery, and Charging UI States

**Files:**

- Modify: `app/Http/Controllers/FleetAssets/ResidentTrackingController.php`
- Modify: `app/Http/Controllers/ClientController.php`
- Modify: `resources/js/pages/fleet-assets/resident-tracking/index.tsx`
- Modify: `resources/js/components/client-location-tab.tsx`

- [ ] Extend resident/client location props with health fields:

```php
'battery_status' => $meta['battery_status'] ?? null,
'battery_voltage_mv' => $meta['battery_voltage_mv'] ?? null,
'charging_status' => $meta['charging_status'] ?? null,
'external_power' => $meta['external_power'] ?? null,
'last_power_event' => $meta['power_event'] ?? null,
'last_safety_event' => $meta['last_safety_event'] ?? null,
```

- [ ] UI copy rules:
  - If `charging_status === 'charging'` or `external_power === true`, show `Charging`.
  - If battery is <= threshold, show `Low battery`.
  - If battery is null, show `Battery not reported`.
  - If last safety event is `vehicle_sos`, show `SOS received`.
  - If last safety event is `man_down`, show `Man down alert`.

- [ ] Resident Tracking cards should use icon plus text, not color alone:
  - `BatteryLow` with `Low battery`.
  - `Battery` with `Charging`.
  - `ShieldAlert` with `SOS received` or `Man down alert`.

- [ ] Client Location tab should show the same battery/charging wording in the tracker status cards and marker popup.

- [ ] Run frontend tests and browser smoke:

```powershell
npm test -- resources/js/test/client-location-tab.test.tsx
npm test -- resources/js/test/resident-tracking.test.tsx
npm run types
npm run build
```

Then open:

- `/fleet-assets/resident-tracking`
- `/operations/clients/9012?tab=location`

Verify:

- Locate Now button is visible on both pages.
- Charging is shown when the device is charging.
- Low battery warning appears at or below threshold.
- Unknown battery does not show as healthy.

### Task 8: Create Operational Alerts for Safety and Battery Events

**Files:**

- Modify: `app/Services/Fleet/FleetTelemetryIngestService.php`
- Inspect/reuse: `app/Services/ControlRoom/SignalProcessingService.php`
- Modify or add tests: `tests/Feature/Queclink/FrameRouterTest.php`, `tests/Feature/FleetTelemetryIngestTest.php`

- [ ] When `sos_flag` is true, emit a resident safety signal with severity critical.

Expected payload:

```php
[
    'signal_type' => 'resident.sos',
    'severity_hint' => 'critical',
    'payload' => [
        'event_id' => $event->id,
        'vendor' => 'queclink',
        'command_word' => $normalized['raw_payload']['command_word'] ?? null,
    ],
]
```

- [ ] When `event_type === 'battery_low'`, emit a warning signal:

```php
[
    'signal_type' => 'device.low_battery',
    'severity_hint' => 'warning',
]
```

- [ ] Avoid repeated duplicate alerts by using event id/idempotency keys. Do not alert again for duplicate telemetry events.

- [ ] Run:

```powershell
vendor\bin\pest.bat tests\Feature\FleetTelemetryIngestTest.php
vendor\bin\pest.bat tests\Feature\Queclink\FrameRouterTest.php
git add app tests
git commit -m "Raise safety and battery alerts from GL30 events"
```

### Task 9: Reverse Geocode Resident Location Points

- [ ] Add nullable address fields to telemetry storage:

```text
database/migrations/YYYY_MM_DD_HHMMSS_add_address_to_fleet_telemetry_events.php
```

Suggested fields:
- `address` nullable string.
- `reverse_geocoded_at` nullable timestamp.
- `reverse_geocode_failed_at` nullable timestamp.

- [ ] Add a queued job:

```text
app/Jobs/ReverseGeocodeFleetTelemetryEvent.php
```

The job should:
- Load the telemetry event.
- Skip when latitude or longitude is missing.
- Use `app/Services/Fleet/ReverseGeocodeService.php`.
- Respect `fleet.maps.reverse_geocode_enabled`.
- Store `address` and `reverse_geocoded_at` when an address is returned.
- Store `reverse_geocode_failed_at` when no address can be resolved.
- Avoid throwing failures back into the TCP listener path.

- [ ] Update telemetry ingestion:

```text
app/Services/Fleet/FleetTelemetryIngestService.php
```

After creating a telemetry event with valid coordinates, dispatch `ReverseGeocodeFleetTelemetryEvent` asynchronously. Do not block GL30 TCP frame handling on reverse geocoding.

- [ ] Update resident/client location history mapping:

```text
app/Services/Integration/IntegrationEventHistoryService.php
```

Return both address and coordinate data for each point:

```php
'address' => $event->address,
'coordinates' => sprintf('%.6f, %.6f', (float) $event->latitude, (float) $event->longitude),
'display_location' => $event->address ?: sprintf('%.6f, %.6f', (float) $event->latitude, (float) $event->longitude),
```

For integration-event-derived points, use the same fallback rule from payload data if an address is not present.

- [ ] Update Location History UI:

```text
resources/js/pages/fleet-assets/resident-tracking/history.tsx
```

Timeline rows should show:
- Address first when available.
- Coordinates only when address is missing, pending, or failed.
- Coordinates in the details/metadata area when an address is shown, so operators can still verify exact location when needed.

Map marker popups should use the same address-first fallback rule.

- [ ] Update Resident Tracking and Client Location surfaces:

```text
resources/js/pages/fleet-assets/resident-tracking/index.tsx
resources/js/components/client-location-tab.tsx
```

Current-location cards, tracker popups, and movement history should show the human-readable address when available and fall back to coordinates when not.

- [ ] Update CSV export to include both:
  - `Address`.
  - `Latitude`.
  - `Longitude`.

- [ ] Add tests:

```text
tests/Feature/Queclink/ResidentLocationAddressTest.php
tests/Feature/FleetTelemetryIngestTest.php
```

Cover:
- Address present: history payload returns address and `display_location` equals the address.
- Address missing: history payload returns coordinates and `display_location` equals the coordinate fallback.
- Reverse geocoding disabled: ingest still succeeds and no lookup is queued.
- Reverse geocoding failure: ingest still succeeds and location history still displays coordinates.
- Consent masking still suppresses location/address data where required.

### Task 10: Full Verification and Deployment

- [ ] Run backend tests:

```powershell
vendor\bin\pest.bat tests\Unit\Services\Queclink
vendor\bin\pest.bat tests\Feature\Queclink
vendor\bin\pest.bat tests\Feature\FleetTelemetryIngestTest.php
```

- [ ] Run frontend checks:

```powershell
npm test -- resources/js/test/queclink-hub.test.tsx
npm run types
npm run build
vendor\bin\pint.bat --dirty --test
git diff --check
```

- [ ] Browser verify live-like flows:
  - Queclink Device Settings can queue `Read full config`.
  - Device Settings can queue the resident safety profile.
  - Resident Tracking shows Locate Now.
  - Client Location tab shows Locate Now.
  - Latest location still renders on both maps.
  - Location History timeline shows addresses when available.
  - Location History timeline falls back to coordinates when no address is available.
  - Map marker popups use address-first display with coordinate fallback.
  - Location CSV exports include address, latitude, and longitude.
  - Low battery fixture shows warning.
  - Charging fixture shows `Charging`.
  - SOS/man-down fixture creates a critical safety alert.

- [ ] Commit final verification changes:

```powershell
git status --short
git log --oneline -5
```

- [ ] Push and deploy only after all checks are green.

---

## Acceptance Criteria

- Operators no longer need USB access to request a current location or configure the panic button.
- `Locate Now` appears on Resident Tracking and Client Location.
- Locate Now queues a GL30 `GTRTO` request-location command and displays recent command state.
- Device Settings has a resident safety profile that configures the hardware panic/function button, SOS reporting, low battery threshold, GNSS, Wi-Fi fallback, LED, and charge standby behavior.
- GL30 SOS/man-down/panic frames produce critical safety alerts.
- Low battery frames and battery percentages at/below threshold produce warnings.
- Charging state is shown as `Charging`.
- Battery unknown is shown as `Battery not reported`.
- Location timelines, current-location cards, and map marker popups show addresses first when available.
- Coordinates are shown when address lookup is pending, failed, disabled, or returns no address.
- Reverse geocoding is queued and cached, and does not block GL30 TCP frame ingestion.
- CSV exports include address and coordinates.
- Existing consent masking remains intact for location coordinates.
- Existing Queclink debug console and configuration snapshot still work.

---

## Notes for the Fresh Context

- Start by reading this plan, then inspect the files listed in Current Context.
- Do not rework the Queclink integration UI from scratch. Extend the current hub and resident/client location surfaces.
- Use the current command queue model, not direct socket writes from the web request.
- Keep all commands asynchronous. The device receives queued commands only when it next checks in.
- Keep raw Queclink frames as the audit trail for debugging.
