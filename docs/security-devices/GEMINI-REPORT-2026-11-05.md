# Security & Devices — Gemini Handover Report (3–5 Nov 2026)

- **Branch:** `gemini/sd-continue` (worktree `.claude/worktrees/gemini-sd`, branched from `fda7c3273`, S&D base `3eade3c34`)
- **Remote / server:** Untouched (`oblivionfindings.com` never accessed; nothing pushed or merged)

---

## Task 1 — Card inner padding (`18px`) + `DESIGN.md` anti-pattern

- **Status:** Done
- **What changed:**
  - Added `p-[18px]` inner padding (matching `Alerts` page `Surface` cards) to flush `Surface` card bodies across Security & Devices, keeping edge-to-edge rules/tables visually aligned while giving card headings, toolbars, empty states, and rows proper `18px` breathing room.
  - Added `"Content placed straight in a Surface without inner padding"` to the named anti-patterns table in `DESIGN.md`.
- **Files:**
  - `DESIGN.md`
  - `resources/js/pages/security-devices/overview/index.tsx`
  - `resources/js/pages/security-devices/overview/_activity.tsx`
  - `resources/js/pages/security-devices/sites/show.tsx`
  - `resources/js/pages/security-devices/sites/_logbook.tsx`
  - `resources/js/pages/security-devices/devices/_device-ports.tsx`
  - `resources/js/pages/security-devices/devices/_device-history.tsx`
- **Tests:**
  - Verified existing frontend and feature suites covering Overview, Sites, and Switch tabs (`resources/js/pages/security-devices/sites/show.test.tsx`, `resources/js/pages/security-devices/devices/device-switch-tabs.test.tsx`, `tests/Feature/SecurityDevices/OverviewAndUpkeepTest.php`, `tests/Feature/SecurityDevices/SitePageTabsTest.php`, `tests/Feature/SecurityDevices/SwitchPortsHistorySettingsTest.php`) — all pass.
- **Screenshots:**
  - `.board/shots/01-overview-activity-light.png`
  - `.board/shots/01-overview-activity-dark.png`
  - `.board/shots/04a-site-logbook-light.png`
  - `.board/shots/04a-site-logbook-dark.png`
  - `.board/shots/06b-switch-ports-columns-3d-light.png`
  - `.board/shots/06b-switch-ports-columns-3d-dark.png`
  - `.board/shots/06c-switch-history-light.png`
  - `.board/shots/06c-switch-history-dark.png`
- **Anything omitted:** None.

---

## Task 2 — 15g: 6:00 am morning summary and quiet hours for device alerts

- **Status:** Done
- **What changed:**
  - Extended `SecurityDevicesSettings` with `summary_channels` (`in_app`, `email`, `push` — with `push` locked off per board 15g) and `quiet_hours` (`enabled`, `start` default `22:00`, `end` default `06:00` in NZ time `Pacific/Auckland`), audited via `SetupHistory`.
  - Updated `DeviceAlertEngine`: non-urgent device and hub alerts raised during overnight quiet hours still open immediately on the `Alerts` screen (`notified_at = null`, timeline note explaining notifications wait for the 6:00 am summary) while **safety alerts, care rules, emergencies, and public MQTT exposure checks never wait**.
  - Added the 6:00 am NZ morning summary pass inside `DeviceAlertEngine::sendDueReminders()` using `SecurityDeviceMorningSummaryNotification` (one Site-scoped summary per recipient per morning, idempotent via `summary_last_sent_date`, covering overnight held alerts, sensors/trackers below 20% battery, and hub update outcomes from `SetupHubs` / 15p; marks held alerts notified so critical 1-hour escalation resumes cleanly after quiet hours end).
  - Updated `Setup › Alerts & checks` (`_alerts.tsx`) with the `Daily summary` channel row and `Quiet hours` card (`TimePicker compact` start/end pickers widened to `w-44` so `10:00 PM` / `06:00 AM` stay on one line), and wired live quiet-hours status into `Alerts › On call` (`OnCallPresenter`).
- **Files:**
  - `app/Domain/Monitoring/Services/MonitoringAlertNotificationDispatcher.php`
  - `app/Domain/SecurityDevices/Alerts/DeviceAlertEngine.php`
  - `app/Domain/SecurityDevices/Screens/Alerts/AlertsPresenter.php`
  - `app/Domain/SecurityDevices/Screens/Alerts/OnCallPresenter.php`
  - `app/Domain/SecurityDevices/Screens/Setup/SetupHistory.php`
  - `app/Domain/SecurityDevices/Screens/Setup/SetupHubs.php`
  - `app/Domain/SecurityDevices/Setup/SecurityDevicesSettings.php`
  - `app/Notifications/SecurityDeviceMorningSummaryNotification.php`
  - `resources/js/pages/security-devices/alerts/_on-call.tsx`
  - `resources/js/pages/security-devices/alerts/_types.ts`
  - `resources/js/pages/security-devices/setup/_alerts.tsx`
  - `resources/js/pages/security-devices/setup/_settings.tsx`
  - `resources/js/pages/security-devices/setup/_types.ts`
  - `resources/js/pages/security-devices/setup/index.test.tsx`
  - `tests/Feature/SecurityDevices/DeviceAlertsTest.php`
- **Tests added & result:**
  - `tests/Feature/SecurityDevices/DeviceAlertsTest.php` — `test_quiet_hours_hold_device_alerts_without_delaying_safety_alerts_and_send_one_morning_summary_per_recipients_sites` (**PASS**)
  - `resources/js/pages/security-devices/setup/index.test.tsx` — `shows the 6:00 am daily summary channels and overnight quiet hours without claiming printer toner` (**PASS**)
- **Screenshots:**
  - `.board/shots/02-setup-alerts-15g-light.png`
  - `.board/shots/02-setup-alerts-15g-dark.png`
- **Anything omitted:** Printer toner was intentionally excluded from the `Daily summary` copy per approved board 15g review note ("low batteries, overnight alerts and hub updates, at 6:00 am").

---

## Task 3 — 13d On call extras: week day-strip, backup-person card, and All handovers page

- **Status:** Done
- **What changed:**
  - Extended `OnCallPresenter` to compute the 7-day `Mon`–`Sun` day-strip (`done` / `now` / `upcoming` in NZ time) on the active on-call card and detect overlapping secondary Rostering shifts (`ItOnCall`) as the `Backup` person card (`If <Primary> doesn’t answer`).
  - Added the read-only `All handovers` page (`GET /security-devices/alerts/on-call/handovers` → `security-devices/alerts/handovers`), gated by the same permission as the `On call` tab (`securityDevices.viewAny` + `securityDevices.events.view`) and Site-scoped so handovers belonging exclusively to other Sites' staff never leak.
  - Added `.ob-person-days` / `.ob-person-day` styles in `resources/css/signature-blocks.css` and `PersonCard` day-strip support in `resources/js/components/signature/blocks.tsx`.
- **Files:**
  - `app/Domain/SecurityDevices/Screens/Alerts/OnCallPresenter.php`
  - `app/Domain/SecurityDevices/Screens/Http/AlertsController.php`
  - `resources/css/signature-blocks.css`
  - `resources/js/components/signature/blocks.tsx`
  - `resources/js/pages/security-devices/alerts/_on-call.tsx`
  - `resources/js/pages/security-devices/alerts/_types.ts`
  - `resources/js/pages/security-devices/alerts/handovers.tsx`
  - `resources/js/pages/security-devices/alerts/on-call.test.tsx`
  - `routes/security-devices.php`
  - `tests/Feature/SecurityDevices/AlertsQuietTimesOnCallTest.php`
- **Tests added & result:**
  - `tests/Feature/SecurityDevices/AlertsQuietTimesOnCallTest.php` — `test_on_call_shows_the_week_day_strip_and_backup_person_from_overlapping_rostering_shifts` (**PASS**)
  - `tests/Feature/SecurityDevices/AlertsQuietTimesOnCallTest.php` — `test_all_handovers_page_lists_notes_newest_first_enforces_permissions_and_scopes_sites` (**PASS**)
  - `resources/js/pages/security-devices/alerts/on-call.test.tsx` — `renders the week day-strip, backup-person card, and All handovers link` (**PASS**)
  - `resources/js/pages/security-devices/alerts/on-call.test.tsx` — `renders the read-only All handovers page with notes and back link` (**PASS**)
- **Screenshots:**
  - `.board/shots/03a-alerts-on-call-13d-light.png`
  - `.board/shots/03a-alerts-on-call-13d-dark.png`
  - `.board/shots/03b-alerts-all-handovers-13d-light.png`
  - `.board/shots/03b-alerts-all-handovers-13d-dark.png`
- **Anything omitted:** None.

---

## Task 4 — 12i Site Logbook Export (PDF or CSV, single day or date range, audited with reason)

- **Status:** Done
- **What changed:**
  - Added `SiteLogbookExport` and `POST /security-devices/sites/{site}/logbook/export` (`SitesController::exportLogbook`), requiring `securityDevices.events.view`, Site visibility (404 direct-object denial for other Sites), and a mandatory `reason` (`min:3`, `max:500`).
  - Records an `AuditLog` entry (`security_devices.logbook.exported`) with the user's ID, `actor_name`, Site ID/name, date range, filter, row count, format (`pdf` / `csv`), and stated reason.
  - Enforces identical resident-name and door-event privacy rules (`AlertWords` + `AccessEventWords`) to the on-screen Logbook, neutralises spreadsheet formula prefixes (`=`, `+`, `-`, `@`, tab/CR) in CSV cells, and renders clean print PDFs via Dompdf (`resources/views/pdf/security-devices-site-logbook.blade.php`).
  - Added the `Export` button and `PopupForm` modal on `Site › Logbook` (`_logbook.tsx`).
- **Files:**
  - `app/Domain/SecurityDevices/Screens/Http/SitesController.php`
  - `app/Domain/SecurityDevices/Screens/Sites/SiteLogbookExport.php`
  - `app/Domain/SecurityDevices/Screens/Sites/SitePagePresenter.php`
  - `app/Domain/SecurityDevices/Screens/Sites/SiteStream.php`
  - `resources/js/pages/security-devices/sites/_logbook.tsx`
  - `resources/js/pages/security-devices/sites/show.test.tsx`
  - `resources/views/pdf/security-devices-site-logbook.blade.php`
  - `routes/security-devices.php`
  - `tests/Feature/SecurityDevices/SitePageTabsTest.php`
- **Tests added & result:**
  - `tests/Feature/SecurityDevices/SitePageTabsTest.php` — `test_logbook_export_requires_events_view_and_a_reason_and_audits_the_person_and_reason` (**PASS**)
  - `tests/Feature/SecurityDevices/SitePageTabsTest.php` — `test_logbook_export_applies_resident_and_door_name_privacy_rules` (**PASS**)
  - `resources/js/pages/security-devices/sites/show.test.tsx` — `exports the logbook only with a reason, sending the Site, date and format (12i)` (**PASS**)
- **Screenshots:**
  - `.board/shots/04a-site-logbook-light.png`
  - `.board/shots/04a-site-logbook-dark.png`
  - `.board/shots/04b-site-logbook-export-12i-light.png`
  - `.board/shots/04b-site-logbook-export-12i-dark.png`
- **Anything omitted:** None.

---

## Task 5 — 12j "Place them": place unplaced devices on the Site map by hand

- **Status:** Done
- **What changed:**
  - Extended `SiteLinksFound` to expose Site-scoped `unplaced.items` and `unplaced.parents` (prioritising gateways, sensor gateways, switches, Wi-Fi APs, and hubs) when the viewer holds `securityDevices.devices.update`, and to surface manually placed relationships (`by: 'Placed by hand'`, `sure: null`, `map.state: 'on'`) in the links table so they can also be taken off the map.
  - Added `POST /security-devices/sites/{site}/network/place` (`SitesController::placeDevices`), enforcing `securityDevices.devices.update` and strict same-Site ownership on both parent and child devices (404 on cross-Site IDs, 422 on self-links), creating reviewed manual `DeviceRelationship` rows (`ConnectedTo`, `port` default `Radio`), and auditing each link under `security_devices.relationship.created`.
  - Added the `Place them` button and `PopupForm` modal in `Site › Network` (`_links.tsx`) and wired `Take off the map` through the existing audited relationship unlink endpoint.
- **Files:**
  - `app/Domain/SecurityDevices/Screens/Http/SitesController.php`
  - `app/Domain/SecurityDevices/Screens/Sites/SiteLinksFound.php`
  - `resources/js/pages/security-devices/sites/_links.tsx`
  - `resources/js/pages/security-devices/sites/_types.ts`
  - `resources/js/pages/security-devices/sites/show.test.tsx`
  - `resources/js/pages/security-devices/sites/show.tsx`
  - `routes/security-devices.php`
  - `tests/Feature/SecurityDevices/SitePageTabsTest.php`
- **Tests added & result:**
  - `tests/Feature/SecurityDevices/SitePageTabsTest.php` — `test_unplaced_devices_can_be_placed_on_the_map_by_hand_audited_and_taken_off_again` (**PASS**)
  - `resources/js/pages/security-devices/sites/show.test.tsx` — `lets device editors place unplaced radio devices on the map by hand and take manual links off (12j)` (**PASS**)
- **Screenshots:**
  - `.board/shots/05a-site-network-links-12j-light.png`
  - `.board/shots/05a-site-network-links-12j-dark.png`
  - `.board/shots/05b-site-network-place-popup-12j-light.png`
  - `.board/shots/05b-site-network-place-popup-12j-dark.png`
- **Anything omitted:** None.

---

## Task 6 — 3f Switch Settings extras: update notice, Looked after by, and IT-admin-only SSH user

- **Status:** Done
- **What changed:**
  - Added per-device settings on `DeviceSettingsTab` and `PATCH /security-devices/devices/{device}/settings` (`DevicePageController::updateSettings`):
    - **Tell IT when an update is ready** (`config.settings.tell_it_update_ready`, default `true`), integrated into `DeviceAlertEngine`'s 6:00 am morning summary so network devices with available firmware updates and this toggle on are included for that Site's IT recipients.
    - **Looked after by** (`config.settings.looked_after_by_user_id`), scoped to active staff associated with the device's Site (`HrEmployeeProfile`).
    - **SSH user** (`config.settings.ssh_user`), strictly hidden from Inertia props and rejected (`403`) on write unless the user holds `securityDevices.terminal.use` (IT admins), validated as a username token (`^[A-Za-z0-9._-]+$`, max 64 chars — never a password), and preferred as a fallback username in `CommandCredentialLeaseService` when a saved credential secret has no explicit username.
  - Audited every change to `AuditLog` (`security_devices.device.settings_updated` with changed keys and before/after values, omitting `ssh_user` values for non-terminal viewers in `DeviceLogbook`).
- **Files:**
  - `app/Domain/SecurityDevices/Alerts/DeviceAlertEngine.php`
  - `app/Domain/SecurityDevices/Credentials/Services/CommandCredentialLeaseService.php`
  - `app/Domain/SecurityDevices/Screens/Devices/Pages/DeviceLogbook.php`
  - `app/Domain/SecurityDevices/Screens/Devices/Pages/DeviceSettingsTab.php`
  - `app/Domain/SecurityDevices/Screens/Http/DevicePageController.php`
  - `resources/js/pages/security-devices/devices/_device-page-types.ts`
  - `resources/js/pages/security-devices/devices/_device-settings.tsx`
  - `resources/js/pages/security-devices/devices/device-switch-tabs.test.tsx`
  - `routes/security-devices.php`
  - `tests/Feature/SecurityDevices/SwitchPortsHistorySettingsTest.php`
- **Tests added & result:**
  - `tests/Feature/SecurityDevices/SwitchPortsHistorySettingsTest.php` — `test_settings_extras_save_audit_and_gate_ssh_user_to_it_admins` (**PASS**)
  - `resources/js/pages/security-devices/devices/device-switch-tabs.test.tsx` — `saves update summary, looked-after person and IT-admin-only SSH user without exposing SSH user to others` (**PASS**)
- **Screenshots:**
  - `.board/shots/06d-switch-settings-3f-light.png`
  - `.board/shots/06d-switch-settings-3f-dark.png`
- **Anything omitted:** None.

---

## Task 7 — 3d Switch Ports extras: Columns menu (and PoE budget check)

- **Status:** Done
- **What changed:**
  - Added the `Columns` dropdown menu (`DropdownMenuCheckboxItem`) to the Switch `Ports` toolbar (`_device-ports.tsx`) so viewers can toggle optional columns (`Network`, `Speed`, `Power (PoE)`, `Traffic`, `Errors`) while keeping `Port` and `Plugged in` pinned.
  - Inspected `UnifiOperationalBridgeService` and `NetworkCheckKinds` (`switch_ports`) for stored PoE budget data: `provider_observed_state` and `switch_ports` snapshots store per-port PoE draw (`poe_w`) and total active draw (`Power to devices`), **not** the switch's total PoE power budget (`total_max_power`).
- **Files:**
  - `resources/js/pages/security-devices/devices/_device-ports.tsx`
  - `resources/js/pages/security-devices/devices/device-switch-tabs.test.tsx`
- **Tests added & result:**
  - `resources/js/pages/security-devices/devices/device-switch-tabs.test.tsx` — `hides optional port columns chosen off in Columns while keeping Port and Plugged in` (**PASS**)
- **Screenshots:**
  - `.board/shots/06b-switch-ports-columns-3d-light.png`
  - `.board/shots/06b-switch-ports-columns-3d-dark.png`
- **Anything omitted:** **PoE budget** was intentionally left out per the handover instruction (*"and the PoE budget **only if** the UniFi data already stored has it (`provider_observed_state`). If it isn't stored, leave the PoE budget out and say so. Don't invent a feed."*).

---

## Task 8 — E6 sensors (boards 5–6, views 7b & 7c) + configurable alert limits per sensor

- **Status:** Done
- **What changed:**
  - **7b Bed sensor `Nights` view (`SensorNightsPresenter` + `sensor.tsx`):** Added the `Nights` primary tab for resident-linked falls/bed sensors (`DevicePersonLink::PURPOSE_FALLS`), showing the 4-meter header (`Right now`, `Last night`, `Alerts in 7 nights`, `Battery`), care banner, **Last night** timeline log, **Minutes out of bed · last 7 nights** bar chart with dashed rule line (from stored `SensorReadingHistory` occupancy buckets + `DeviceAlert` history), and the **4-check readiness ring** (`Sensor online`, `Battery healthy`, `Linked to care plan`, `Night rule active`). Enforced strict resident-name privacy (`ClientAccess`: viewers without client access see `"Resident"` / `"a person"` and `href: null`, never the resident's name).
  - **7c Hot-water scald meters (`SensorPagePresenter` + `sensor.tsx`):** Added the 7c 4-meter header (`At the tap now`, `Hottest today` from stored 24 h `SensorReadingHistory`, `H&S hazard` showing `None` / `Ready to raise` / `Raised`, and `Battery`), `Too hot` header badge when ≥ 45 °C, and the critical scald hazard banner with one-click `Raise H&S hazard` when permitted. Also added `'care'` and `'nights'` to `SensorTrackerPageController::SENSOR_PROPS`.
  - **Configurable per-sensor alert limits (`SensorAlertRules` + `PUT /security-devices/devices/{device}/alert-limits`):** Replaced hard-coded `READING_LIMITS` in `SensorPagePresenter` and `MqttSensorIntakeService` with `SensorAlertRules::forDevice($device)` (`config.alert_limits`: `temperature` low/high, `humidity` low/high, `co2` warn/critical, `battery` low), gated by `securityDevices.automations.manage` (`SensorTrackerPageController::canManageRules`), audited under `security_devices.sensor.alert_limits.updated`, and editable via the `Edit limits` `PopupForm` on `sensor.tsx`.
- **Files:**
  - `app/Domain/SecurityDevices/Alerts/DeviceAlertEngine.php`
  - `app/Domain/SecurityDevices/Iot/Mqtt/MqttSensorIntakeService.php`
  - `app/Domain/SecurityDevices/Iot/Readings/SensorAlertRules.php`
  - `app/Domain/SecurityDevices/Screens/Devices/Pages/SensorNightsPresenter.php`
  - `app/Domain/SecurityDevices/Screens/Devices/Pages/SensorPagePresenter.php`
  - `app/Domain/SecurityDevices/Screens/Http/CareLinkController.php`
  - `app/Domain/SecurityDevices/Screens/Http/SensorTrackerPageController.php`
  - `resources/js/pages/security-devices/devices/_sensor-tracker-types.ts`
  - `resources/js/pages/security-devices/devices/sensor.test.tsx`
  - `resources/js/pages/security-devices/devices/sensor.tsx`
  - `routes/security-devices.php`
  - `tests/Feature/SecurityDevices/CareLinksAndScaldTest.php`
  - `tests/Feature/SecurityDevices/SensorTrackerPagesTest.php`
- **Tests added & result:**
  - `tests/Feature/SecurityDevices/CareLinksAndScaldTest.php` — `renders the 7b Nights view, 4-check readiness ring, 7-night bars, and hides the resident’s name without client access` (**PASS**)
  - `tests/Feature/SecurityDevices/CareLinksAndScaldTest.php` — `shows the 7c tap temperature, hottest today and H&S hazard meters on a scald probe` (**PASS**)
  - `tests/Feature/SecurityDevices/SensorTrackerPagesTest.php` — `test_sensor_alert_limits_are_configurable_permission_checked_and_audited` (**PASS**)
  - `resources/js/pages/security-devices/devices/sensor.test.tsx` — `renders the bed sensor Nights view with last night log, 7-night bars and 4-check readiness ring (pg-sdr-7b)` (**PASS**)
  - `resources/js/pages/security-devices/devices/sensor.test.tsx` — `renders hot-water scald meters and hazard banner (pg-sdr-7c), and opens Edit limits when permitted` (**PASS**)
- **Screenshots:**
  - `.board/shots/08a-sensor-readings-7-light.png`
  - `.board/shots/08a-sensor-readings-7-dark.png`
  - `.board/shots/08b-sensor-limits-popup-7-light.png`
  - `.board/shots/08b-sensor-limits-popup-7-dark.png`
  - `.board/shots/08c-bed-sensor-nights-7b-light.png`
  - `.board/shots/08c-bed-sensor-nights-7b-dark.png`
  - `.board/shots/08d-tap-probe-scald-7c-light.png`
  - `.board/shots/08d-tap-probe-scald-7c-dark.png`
- **Anything omitted:** None.

---

## Task 9 — Local-only demo UniFi switch command for screen checks

- **Status:** Done
- **What changed:**
  - Added `php artisan security-devices:seed-demo-unifi-switch` (`SeedDemoUnifiSwitchCommand`), guarded by triple environment safety checks: refuses to run unless `app()->environment(['local', 'testing'])`, refuses if `config('app.url')` contains `oblivionfindings.com`, and refuses if the default database host points to `oblivionfindings.com`. Never registered in `DatabaseSeeder`.
  - Idempotently creates or refreshes one `USW-24-PoE` UniFi switch (`demo-unifi-usw-24-poe`) with 24 ports (uplink, PoE cameras, APs, sensor gateway, hub, spare/disabled ports, traffic, PoE draw, and error counters), downstream `DeviceRelationship` links, ping + `switch_ports` `Monitor`s, sample alerts, quiet-time window, and audit history so `Overview`, `Ports`, `History`, and `Settings` tabs render realistically for local browser checks.
- **Files:**
  - `app/Console/Commands/SeedDemoUnifiSwitchCommand.php`
  - `tests/Feature/SecurityDevices/DemoUnifiSwitchCommandTest.php`
- **Tests added & result:**
  - `tests/Feature/SecurityDevices/DemoUnifiSwitchCommandTest.php` — `refuses to run outside local/testing or when app.url or database host points to oblivionfindings.com` (**PASS**)
  - `tests/Feature/SecurityDevices/DemoUnifiSwitchCommandTest.php` — `is never registered in DatabaseSeeder` (**PASS**)
  - `tests/Feature/SecurityDevices/DemoUnifiSwitchCommandTest.php` — `idempotently seeds one demo UniFi switch whose Ports, History and Settings tabs render cleanly` (**PASS**)
- **Screenshots:**
  - `.board/shots/06a-switch-overview-demo-light.png`
  - `.board/shots/06a-switch-overview-demo-dark.png`
  - `.board/shots/06b-switch-ports-columns-3d-light.png`
  - `.board/shots/06b-switch-ports-columns-3d-dark.png`
  - `.board/shots/06c-switch-history-light.png`
  - `.board/shots/06c-switch-history-dark.png`
  - `.board/shots/06d-switch-settings-3f-light.png`
  - `.board/shots/06d-switch-settings-3f-dark.png`
- **Anything omitted:** None. (The temporary local database used for taking screenshots was dropped after browser verification.)

---

## Task 10 — Site-scope and resident-privacy tests for uncovered S&D screens (plus 2 privacy fixes)

- **Status:** Done
- **What changed:**
  - Audited Security & Devices controllers and presenters for missing test coverage and added `UncoveredScreensScopeAndPrivacyTest.php` covering four previously untested S&D surfaces:
    1. `SensorGatewayOverview` (`/security-devices/devices/{gateway}`)
    2. `WorkerOverviewPresenter` (`/security-devices` support-worker view)
    3. `FoundDevicesController` (`/security-devices/setup/discovery/runs`, candidate matching, and range CIDR scans)
    4. `ListExportsController` (`/security-devices/exports/devices`, `/events`, `/maintenance-health` CSV downloads)
  - Fixed two real Site-scope / resident-privacy bugs uncovered while writing these tests:
    - **`SensorGatewayOverview::recent()`:** Now scopes child-sensor alerts to visible Sites (`whereIn('site_id', $siteIds)`) and applies `AlertWords::listRow()` so resident names on falls/wandering sensors linked to a gateway are redacted (`"A person out of bed…"`) when the viewer lacks client access, and prior-Site alerts on moved gateways never leak.
    - **`WorkerOverviewPresenter`:** Now checks `DevicePersonLink` (in addition to `DeviceAssignment::TARGET_CLIENT`) when excluding resident-linked care sensors from `around` ("Around the house"), and applies `AlertWords::listRow()` when rendering open house alerts so support workers without client access never see a resident's name.
- **Files:**
  - `app/Domain/SecurityDevices/Screens/Devices/Pages/SensorGatewayOverview.php`
  - `app/Domain/SecurityDevices/Screens/Overview/WorkerOverviewPresenter.php`
  - `tests/Feature/SecurityDevices/UncoveredScreensScopeAndPrivacyTest.php`
- **Tests added & result:**
  - `tests/Feature/SecurityDevices/UncoveredScreensScopeAndPrivacyTest.php` — `hides another Sites gateway and redacts resident names on SensorGatewayOverview without client access` (**PASS**)
  - `tests/Feature/SecurityDevices/UncoveredScreensScopeAndPrivacyTest.php` — `leaves resident-linked sensors out of WorkerOverview around-the-house and redacts resident names without client access` (**PASS**)
  - `tests/Feature/SecurityDevices/UncoveredScreensScopeAndPrivacyTest.php` — `scopes FoundDevicesController scan runs, candidate matching and range checks strictly to visible Sites` (**PASS**)
  - `tests/Feature/SecurityDevices/UncoveredScreensScopeAndPrivacyTest.php` — `scopes ListExportsController CSV downloads to visible Sites and temporal event custody` (**PASS**)
- **Screenshots:** Covered by existing S&D screen suite (backend/presenter & CSV export privacy enforcement).
- **Anything omitted:** None.

---

## Gate

All five steps were run **one at a time** through `~/.claude/heavy-lock.sh` using Git Bash (`C:\Program Files\Git\bin\bash.exe`) and PHP 8.4 (`C:\Users\steph\.config\herd\bin\php84`).

1. **Pest (`public/build` set aside during Pest, as standard):**
   - **Command:** `~/.claude/heavy-lock.sh gemini-sd-pest bash -lc 'export PATH="/c/Users/steph/.config/herd/bin/php84:$PATH"; TEST_PROCESSES=2 composer test:fast'`
   - **Summary:** `Tests: 117 failed, 3 risky, 14 notices, 162 skipped, 26043 passed (335693 assertions)` in `9726.85s` (**0 Security & Devices regressions**; round-3 baseline had `25,747 passed, 117 failed`).
   - **All 117 failures also fail on `main` / this Windows PC environment** (matching `.board/notes/sd-merge-request-r3.md`):
     - *Windows OpenSSL config missing (`configuration file routines::no such file`, 36):* `Tests\Unit\Push\WebPushSenderTest` (25), `Tests\Unit\Push\VapidKeysTest` (5), `Tests\Feature\PushNotificationsWebPushTest` (4), `Tests\Unit\Push\WebPushEncryptionTest` (2).
     - *Same pre-existing `main` failures as 2–3 Nov gate (81):* `Tests\Unit\Chat\ChatBrowserPushTransportTest` (24), `Tests\Feature\ControlRoom\DismissedAlertScopeTest` (6), `Tests\Feature\ShiftControlRoomSignalPipelineTest` (5), `Tests\Feature\Rostering\WorkforceEligibilityAsyncRuntimeTest` (4), `Tests\Unit\Referrals\DocumentRequestResponsesTest` (3), `Tests\Feature\Sites\AddSiteModalStoreTest` (2), `Tests\Feature\Monitoring\MonitoringRecoveryOperatorWorkflowTest` (2), `Tests\Feature\RestrictedIndependentAuthoritySeedingTest` (2), `Tests\Architecture\ItSecuritySingleTenantBoundaryTest` (2), `Tests\Feature\Compliance\ComplianceDashboardSiteScopeTest` (1), `Tests\Feature\HealthSafety\FirstAidDerivedWorkPrivacyTest` (1), `Tests\Feature\MedicationsSafetyOverrideAuthorizationTest` (1), `Tests\Feature\Reporting\OperationalReportsTest` (1), `Tests\Feature\Sites\AddSiteModalE2ETest` (1), `Tests\Feature\Timesheets\TimesheetPayrollAdjustmentIntegrityTest` (1), `Tests\Feature\Hr\RecognitionFeedShoutoutSeamTest` (1), `Tests\Feature\Hr\StaffOnboardingShiftEligibilityTest` (1), `Tests\Feature\It\ItApiCanonicalLifecycleTest` (1), `Tests\Unit\Operations\ShiftReportingServiceTest` (1), `Tests\Unit\Services\ShiftStaffEligibilityServiceTest` (1), `Tests\Feature\Emar\WitnessPinReminderTest` (1), `Tests\Feature\Rostering\WorkforceAcceptedWorkloadIntegrityTest` (1), `Tests\Feature\Rostering\WorkforceShiftSaveCommandIntegrityTest` (1), `Tests\Feature\SecurityAccessControlTest` (1), `Tests\Feature\Sites\AddSiteModalValidationTest` (1), `Tests\Feature\Sites\SiteComplianceWorkflowTest` (1), `Tests\Feature\SettingsControllerTest` (1), `Tests\Unit\CompanyHub\HubDraftActorTest` (1), `Tests\Unit\EndpointActionAuthorityTest` (1), `Tests\Unit\SecurityDevices\EndpointCommandSignerTest` (1), `Tests\Feature\CompanyHub\HubCampaignTest` (1), `Tests\Feature\CompanyHub\HubInsightsLinksTest` (1), `Tests\Feature\Hr\HrCalendarFeedTest` (1), `Tests\Feature\Hr\HrMessageOnlyRecognitionTest` (1), `Tests\Feature\MedicationOverdueAlertsTest` (1), `Tests\Feature\Monitoring\MonitoringSchemaTest` (1), `Tests\Feature\Monitoring\NetworkMonitoringThresholdsAndRoutingTest` (1), `Tests\Feature\Settings\ProfileUpdateTest` (1), `Tests\Feature\Testing\SeedReplayTest` (1), `Tests\Architecture\IdentityPublicationMutexBoundaryTest` (1).

2. **Vitest:**
   - **Command:** `~/.claude/heavy-lock.sh gemini-sd-vitest npx vitest run --maxWorkers=2`
   - **Summary:** `Test Files 4 failed | 1164 passed | 9 skipped (1177)` / `Tests 10 failed | 9146 passed | 17 skipped (9173)` in `1954.73s` (**0 regressions**).
   - **All 10 failures are identical to the `main` / round-3 baseline:**
     - `resources/js/test/device-management-section.test.tsx` (6)
     - `resources/js/pages/security-devices/devices/device-profile-header.test.tsx` (2)
     - `resources/js/test/client-profile-phase-one-ui.test.tsx` (1)
     - `resources/js/test/site-profile-accessibility.test.tsx` (1)

3. **ESLint:**
   - **Command:** `~/.claude/heavy-lock.sh gemini-sd-eslint npx eslint .`
   - **Summary:** `0 errors, 0 warnings` (exit `0`).

4. **TypeScript (`tsc`):**
   - **Command:** `~/.claude/heavy-lock.sh gemini-sd-tsc bash -lc 'NODE_OPTIONS=--max-old-space-size=8192 npx tsc --noEmit'`
   - **Summary:** `0 errors` (exit `0`).

5. **Production Vite build:**
   - **Command:** `~/.claude/heavy-lock.sh gemini-sd-build bash -lc 'NODE_OPTIONS=--max-old-space-size=8192 npm run build'`
   - **Summary:** `✓ built in 5m 53s` (re-verified after the `w-44` `TimePicker` width polish in `5m 42s`, exit `0`).

---

## Questions for Stephan

1. **Switch PoE budget feed (3d):** `UnifiOperationalBridgeService` currently persists per-port PoE watts (`poe_w`) and total active draw (`Power to devices`), not the switch's maximum PoE budget (`total_max_power`). Should we extend the UniFi operational bridge to store `total_max_power` when the UniFi API reports it so the `PoE budget` meter on `Ports` can be enabled?
2. **6:00 am summary recipients for overnight device alerts (15g / 13d):** Right now the 6:00 am summary goes to every recipient for that Site who has `Daily summary` enabled (so the incoming shift / wider IT team sees what waited overnight alongside low batteries and hub/switch updates). Should overnight held warning-level alerts stay in everyone's 6:00 am summary, or go only to the on-call person?
3. **Bed sensor "Nights" window boundaries (7b):** `SensorNightsPresenter` uses each link's configured overnight rule window (`rule.from` → `rule.to`, defaulting to `22:00`–`06:00` NZ time) to bucket `Last night` and `Minutes out of bed · last 7 nights`. Should residents without explicit `from`/`to` hours always use `22:00`–`06:00`, or the Site's House mode Night schedule when one is set?

---

## Things noticed but not touched

- **`SensorTrackerPageController::SENSOR_PROPS` on `main` omitted `'care'`:** Prior to Task 8, `SensorPagePresenter` built `'care'`, `'readings'`, `'rules'`, `'where'`, and `'alerts'`, but `SENSOR_PROPS` omitted `'care'`, preventing care cards from reaching Inertia on `sensor.tsx`. Fixed as part of Task 8 (`'care'` and `'nights'` added).
- **Windows OpenSSL config in local PHP test runs:** On this PC, PHP's OpenSSL extension has no `OPENSSL_CONF` set by default, causing the 36 `WebPush` / `VapidKeys` tests (and `EndpointCommandSignerTest`) to fail with `configuration file routines::no such file` unless `OPENSSL_CONF` points to Herd/Git's `openssl.cnf`. Left `phpunit.xml` untouched per house rules.
- **Full-page screenshot stitching of sticky bottom bars:** On `Setup › Alerts & checks` (`02-setup-alerts-15g-*.png`), `StickySaveBar` uses `sticky bottom-3`, which sits cleanly at the bottom of the viewport during normal browsing; Playwright's `fullPage: true` capture stitches it at the first viewport height above the scrolled `Quiet hours` card.

---

## Commits (`git log --oneline 3eade3c34..HEAD`)

Note: `gemini/sd-continue` was branched from `fda7c3273` per handover setup; the Security & Devices commits from this session are `981cdb616..HEAD` on top of `fda7c3273` (plus the commit adding this report right on top of `00c46da6d`).

```text
00c46da6d fix(security-devices): widen compact quiet-hours TimePicker wrappers so AM/PM stays on one line
6a8760772 fix(security-devices): align PopupForm props and router.post test mock for tsc
bff992032 test(security-devices): add Site-scope and resident-privacy tests for uncovered screens
17ff0feb4 feat(security-devices): add local-only demo UniFi switch command for screen checks
130e18e21 feat(security-devices): add sensor Nights view, scald meters and configurable alert limits (7b, 7c)
a3e5c6d43 feat(security-devices): add Columns menu to switch Ports tab (3d)
443cf18b0 feat(security-devices): add switch Settings update notice, custodian and SSH user (3f)
652c3c4f4 feat(security-devices): place unplaced devices on Site map by hand (12j)
96101849b feat(security-devices): add Site Logbook PDF and CSV export (12i)
5beaf1ad0 feat(security-devices): add On call day-strip, backup card and All handovers page (13d)
72668d50c feat(security-devices): add 6:00 am morning summary and quiet hours (15g)
ebc373a40 fix(security-devices): add 18px inner padding to Surface card bodies
981cdb616 docs(security-devices): add 2026-11-03 Gemini handover
fda7c3273 Merge latest main (08fcb8a2e) into the C2.6 message model
08fcb8a2e Merge pull request #49 from Rory357/claude/oblivion-relay-build-ea1d70
d844d30e8 Merge latest main (4aaac4379) into the Oblivion Relay build
4aaac4379 Merge pull request #51 from Rory357/claude/voice-checker-signoff-49310d
e0d3ae428 Merge latest main (a3700f5f6) into the voice checker branch
30fd8a9a7 test(chat): Oblivion Relay guard refuses the module loader; provenance lists the PROXY protocol source
5108082f3 docs(chat): C4.3 clean-room check - no copied code; Oblivion Relay signed off
1fb6dc1b5 feat(chat): Oblivion Relay control key ids reload on SIGHUP (user's choice)
4f4654690 feat(chat): app side of Oblivion Relay: credentials, instant cut-off and Service health
d7ca3233b feat(chat): Oblivion Relay, Oblivion's own STUN and TURN server for Chat calls (C4.3)
04dc4093e docs(chat): C2.5 signed off - F5 and F6 fixed, rechecked by a separate checker
0bcefedfe feat(chat): C2.6 message model — formatted documents, threads, priority, polls, stickers, scheduled send, link cache
56986b99b Merge branch 'origin/claude/eager-mahavira-c57cd4' (73d29cdde, F5/F6 fix) into claude/voice-checker-signoff-49310d
73d29cdde docs(chat): C2.5 F5 and F6 fix tested with Pest, with and without ffprobe
f5f41798a Merge the Chat base (32b39b76c, with main 0a66dafe2) into the voice checker branch
68f707db4 WIP fix(chat): MP4 voice checks stay fast and small on hostile files (C2.5 F5, F6)
2847eb6cb docs(chat): C2.5 recheck - F1 to F4 fixed; sign-off still held for two MP4 findings
3f98929db Merge branch 'refs/heads/claude/eager-mahavira-c57cd4' into claude/voice-checker-signoff-49310d
98ed39a95 fix(chat): voice checker refuses Vorbis and closes the checker's findings (C2.5)
47e0b65c2 docs(chat): C2.5 clean-room check - no copied code; sign-off held for three findings
39f868e17 feat(chat): Oblivion's own voice-note checker; ffprobe becomes a second opinion (C2.5)
8367cdb85 Merge branch 'claude/chat-module-audit-plan-704fd7' into claude/eager-mahavira-c57cd4
```
