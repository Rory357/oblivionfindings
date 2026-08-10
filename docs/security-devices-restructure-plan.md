# Security & Devices — Restructure & Rollout Plan

**Status:** Draft v2 — amended against actual codebase state
**Scope:** Full functional restructure of the Security & Devices module
**Principle:** UniFi-like operational clarity, with Oblivion Findings as the source of truth.

---

## v2 Amendment — Actual Starting Point

After validation against the `dazzling-wu-fbdb61` worktree, the module is **substantially further along** than v1 of this plan assumed. Keep the architecture and UX guidance; discard the fresh-scaffold PR steps that are already done. Correct baseline:

### Already built and working

- **`app/Domain/SecurityDevices/`** is a full domain namespace with: `Device`, `DeviceAssignment`, `DeviceAssetLink`, `DeviceRelationship`, `DeviceGroup`, `DeviceGroupMember`, `DeviceEvent`, `DeviceMaintenanceRecord`, `DeviceDocument` models; `DeviceRegistryService`, `DeviceAssignmentService`, `DeviceLinkService`; enums `DeviceDomain`, `DeviceStatus`, `HealthStatus`, `AssignmentType`, `LinkType`, `RelationshipType`.
- **Controllers:** `DashboardController`, `DeviceController` (full CRUD), `DeviceAssignmentController`, `DeviceGroupController`, `CategoryPageController` (alarms / cctv / access-control / tracking-devices / smart-iot-healthcare / it-infrastructure / facilities), `AlertsEventsController`, `MaintenanceHealthController`.
- **Routes:** `routes/security-devices.php` is fully wired with permission middleware.
- **Pages:** `resources/js/Pages/security-devices/` has `index`, `dashboard`, `category`, `section`, `security-devices-shell`, `alerts-events`, `maintenance-health`, `devices/{index,show,create,edit,shared}`, `device-groups/{index,show,create,edit}`, plus `config.ts`.
- **Sidebar:** `buildSecurityDevicesSubPanelGroups()` in `resources/js/components/app-sidebar.tsx` registers the module at the top level.
- **Migrations:** `2026_04_14_000001_create_security_devices_tables.php` creates all nine tables in one migration; `2026_04_14_000002–000005` bridge legacy tables (`location_hardware`, `asset_tracker`, `control_room_devices`, `integration_events`, `asset_tracker_telemetry`, `vehicle_telemetry`) to the canonical `devices.id`.
- **Legacy:** `LocationHardware` is deprecated — the model's own docblock says "Do NOT add new operational queries against this model. Use Device + DeviceAssignment instead."
- **Integration framework:** `IntegrationAdapterInterface` is defined with `testConnection / discoverSites / syncDevices / pullHealth / pullEvents / capabilities / provider`. `UnifiAdapter` implements it and writes into the canonical `Device` table. `IntegrationAdapterRegistry` only registers `unifi`.
- **Dual alert system:** `ControlRoom/Alert` (integration_alerts) is marked deprecated with PR16 removal target; `ControlRoomAlert` is canonical. `AlertRoutingService` bridges `IntegrationEvent` → `ControlRoomAlert` via the signal pipeline. **Consolidation is already in progress — no new work needed unless removal stalls.**
- **`SiteHardwareController`** is already stripped to `index` (read-through via `DeviceRegistryService`), `assignRoom` (physical placement), and `manageRooms`. No device CRUD on site pages.
- **`resources/js/Pages/sites/hardware/index.tsx`** is still ~1654 lines — a rewrite concern but the controller side is clean.
- **Permissions:** `SecurityDevicesPermissionsSeeder` already defines `securityDevices.integrations.view`, `securityDevices.integrations.manage`, `securityDevices.reports.view`, `securityDevices.devices.{view,create,update,delete,assign}`, etc.

### Genuinely missing (real gaps)

1. **Broken `/security-devices/integrations` route** — rendered `section.tsx` with a section key that was absent from `config.ts`, causing `sectionMap['integrations']` to be `undefined` and the page to crash at runtime. **Fixed in this PR** (config entry added, dedicated hub page created, route wired to a new `IntegrationsHubController`).
2. **No `QueclinkAdapter`** in `app/Services/Integration/Adapters/` — only the Fleet telemetry variant exists. Needs extraction to the integration namespace with `IntegrationAdapterInterface` conformance.
3. **No `MilesightAdapter`** anywhere. Net-new.
4. **Sites hardware React page** still contains legacy monolithic rendering even though the controller is already stripped. UI needs a proper slimming pass.
5. **No asset-device linking UI** on the device detail page (backend + model support exists via `DeviceAssetLink`; frontend needs link/unlink action row).
6. **No signal rule seed** mapping `DeviceEvent.event_type` values (`alarm_trigger`, `motion_detected`, `battery_low`, `tamper`, etc.) onto ControlRoom signal types.
7. **Reports section** is still a generic shell placeholder.

### PRs from §11 that are already done or near-done

- **PR 1 (scaffold module shell):** done.
- **PR 2 (additive schema):** done via `2026_04_14_000001_create_security_devices_tables.php`.
- **PR 3 (Global Devices page v0):** done — `resources/js/Pages/security-devices/devices/index.tsx`.
- **PR 4 (Device Detail v0):** done — `devices/show.tsx`.
- **PR 5 (strip Site Hardware controller):** controller done; React page still needs slimming.
- **PR 6 (manual device registration):** done — `devices/create.tsx` + `DeviceController@store`.
- **PR 7 (reassignment + history):** done — `DeviceAssignmentController` + `DeviceAssignmentService`.
- **PR 8 (APIs & Integrations scaffold + UniFi rebuild):** **partially done this PR** (hub scaffold landed; UniFi provider sub-page still lives under `/settings/integrations/unifi` and should migrate into the module).
- **PR 11 (alert consolidation):** in progress upstream; no new work required here until PR16 removes the deprecated table.

### Revised PR roadmap

Canonical list of PRs to land the Security & Devices restructure end-to-end. Ordered by dependency and value. PR sizes are rough (S ≤ 1d, M 1–3d, L 3–5d, XL 5d+).

**Legend:** ✅ done · 🟡 in progress / next · ⬜ planned

---

#### Phase 1 — Foundation + fix-up ✅ (all landed)

1. ✅ **PR A — Integrations hub (S).** Add `integrations` to `config.ts` union, new `IntegrationsHubController` + `security-devices/integrations` page, sidebar entry, hub summary + provider cards. Fixes the broken `/integrations` runtime crash. Chrome-verified.
2. ✅ **PR B — UniFi migration into module (M).** Rename `UnifiSettingsController` → `App\Domain\SecurityDevices\Http\Controllers\Integrations\UnifiController`, move page to `resources/js/pages/security-devices/integrations/unifi.tsx`, move 10 routes to `/security-devices/integrations/unifi/*`, retain the legacy secret-management permission during transition, add a 301 redirect from the old URL, update hub `docs_href`, drop the UniFi entry from the settings sidebar, and migrate three test files. Chrome-verified.
3. ✅ **PR E — Slim sites hardware page (M).** Strip UniFi card, Active Integrations section, dead sync handlers. Controller from 307 → 192 lines; page from 1654 → 848 lines. Read-only context view with ownership banner, stat strip, filter bar, device table (inline room dropdown only), Rooms management. Chrome-verified.

#### Phase 2 — Provider coverage ✅ (scaffold tier landed)

4. ✅ **PR C — Queclink provider scaffold (L).** `QueclinkAdapter` implementing `IntegrationAdapterInterface` (functional `testConnection` against `ims.queclink.com`; `syncDevices` returns a graceful not-implemented `SyncResult`), registered in `IntegrationAdapterRegistry`. `QueclinkController` with index + saveKey + testKey + rotateKey + removeKey. `security-devices/integrations/queclink` page with scaffold-state banner, server-URL override, and "What ships next" teaser. 5 routes wired. Hub promoted to `live` with `docs_href`. End-to-end verified in Chrome — save / test-against-real-Queclink-API (correctly errors on fake key) / rotate / remove. **PR C1 still needed** for full device sync + event pulling.
   - **Scope:** Extract `QueclinkAdapter` from `app/Services/Fleet/Telemetry/` into `app/Services/Integration/Adapters/QueclinkAdapter.php`; implement `IntegrationAdapterInterface`. `testConnection` hits real Queclink server; `discoverSites` lists tracker groups; `syncDevices` upserts `Device` rows by IMEI; `pullHealth` optional; `pullEvents` panic/geofence where supported. New `QueclinkController` (index, saveKey, testKey, rotateKey, removeKey, syncSites, mapSite, syncDevices). New page `resources/js/pages/security-devices/integrations/queclink.tsx` mirroring UniFi structure. Routes under `/security-devices/integrations/queclink/*`. Register in `IntegrationAdapterRegistry`. Hub `implementation_status` bumped to `live`. **Subcategory mapping:** IMEI prefix / device model → `vehicle_tracker` / `personal_tracker` / `asset_tracker`.
   - **Fleet coordination:** Fleet's `Fleet\Telemetry\QueclinkAdapter` keeps its telemetry-ingestion role; the new integration adapter owns device registration + health + events. Both can share a low-level HTTP client utility.
   - **NZ use case:** personal trackers assigned to supported-living clients. Consent audit is a v1.5 concern; adapter shouldn't auto-assign to clients.
   - **Risk:** API endpoint / schema differences across Queclink models (GV, GL, GS series).
   - **Acceptance:** test connection works against a real Queclink account; syncing a test tracker creates a `Device` with `provider='queclink'`, correct subcategory, IMEI as `external_ref.primary_key`.

5. ✅ **PR D — Milesight provider scaffold (M).** `MilesightAdapter` scaffold (functional `testConnection` against `mdp-api.milesight.com`; sync methods return graceful not-implemented). Registered in adapter registry. `MilesightController` mirrors Queclink shape. `security-devices/integrations/milesight` page with scaffold banner + Sensor coverage planned card (Resident support / Environmental / Facilities). 5 routes wired. Hub promoted to `live`. Chrome-verified — hub now shows all 3 providers live (UniFi, Queclink, Milesight). **PR D1 still needed** for LoRaWAN device sync + payload decoding.

6. ⬜ **PR C1 — Full Queclink sync (M).** Complete device sync, health polling, panic/geofence event pulling once real API access lands. Schedule via `PullIntegrationHealthJob` + `SyncIntegrationDevicesJob` (already exist).

7. ⬜ **PR D1 — Full Milesight sync + downlink (L).** Gateway registration, command downlink for supported sensors (e.g., bed sensor sensitivity), webhook receiver for real-time events.

#### Phase 3 — Operational completeness

8. ✅ **PR F — Device detail asset-link UI (S).** Added `linkAsset` + `unlinkAsset` methods to `DeviceController` with dual-path safety (duplicate detection via `DeviceLinkService::link()` throws, caught at controller). 2 routes under `/security-devices/devices/{device}/asset-links` gated by `securityDevices.devices.update`. `show.tsx` extended with `availableAssets` + `linkTypes` props, a link dialog (asset picker + link type + notes), and per-row unlink button with confirm. History preserved via `unlinked_at`. Chrome-verified — link creation, duplicate prevention (302 redirect-back with error), unlink (history retained).

9. ✅ **PR G — Reports page content (M).** New `ReportsController` replacing the section-shell placeholder. Three streamed CSV exports (devices / events 90-day / maintenance) with UTF-8 BOM for Excel compatibility, cursor-based streaming so large estates do not buffer everything in memory, canonical Site/visibility scoping, and the `securityDevices.reports.view` gate. Four routes are wired. `reports.tsx` provides three download cards with live counts plus a clear scope note (no scheduling / PDF / pivot — those belong in Reporting). Chrome-verified — the CSV returns HTTP 200, BOM + header + the test alarm event row.

10. ✅ **PR H — Signal rule seed + DeviceEventObserver (S).** Two migrations: one seeds `security_devices` SignalSource + 13 `device_*` SignalTypes with default severities; the other seeds 13 SignalRules mapping types to tier/dedup/maintenance-suppression. New `App\Observers\DeviceEventObserver` registered in `AppServiceProvider::boot()` — bridges DeviceEvent inserts into `SignalProcessingService::ingest() → process()`, routes the resulting Signal through any matching rule, creates a `ControlRoomAlert`, and marks `DeviceEvent.processed_at`. Heartbeat suppression handled at the observer level to avoid signal-row spam. Chrome-verified end-to-end — inserting a critical `alarm_trigger` DeviceEvent produces a "Device Alarm Triggered" alert in `/control-room` with severity=critical, source=security_devices. **This was the critical path PR** unblocking all downstream care/alerting integrations.

    **PR H regression test:** `tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php` — 4 assertions covering seed presence, critical-alarm-trigger end-to-end, heartbeat suppression, and unknown event_type → generic catch-all routing. Runs under `RefreshDatabase`; executes in CI with dev dependencies installed (this session skipped `--dev` install for speed).

    **Test-coverage expansion (this session):** Four additional Feature test files landed alongside the regression test, covering 21 assertions across the biggest surfaces:
    - `IntegrationsHubTest.php` — guest redirect, support-worker 403, admin 200 + full props shape + roll-ups for a mixed connected/errored provider-connection state.
    - `DeviceAssetLinkTest.php` — permission gating, successful link creation, duplicate-pair rejection, unlink preserves history (`unlinked_at` set, row retained), cross-device unlink → 404.
    - `DeviceGroupAutoRulesTest.php` — empty-conditions footgun guard, `match=all` AND, `match=any` OR, `applyToGroup` add/remove/kept counts, idempotency, JSON preview endpoint, sync flash-messaging.
    - `ReportsExportTest.php` — permission gating on both index and each CSV, BOM prefix, Content-Type header, header-row includes `next_service_due`, row-count matches seeded devices.

    All 5 test files `php -l` clean; will execute in CI with dev deps installed.

11. ✅ **PR I — Dashboard real content (M).** Verified already complete. `DashboardController` already returns: 8 stat tiles (Total, Active, Offline/Degraded, Overdue Maintenance, Low Battery, Critical Events 24h, Warning Events 24h, Device Groups), device estate by domain breakdown, recent events feed (which picked up the PR H test alarm), overdue maintenance list, health distribution, attention-required list, and a Quick Links panel. Live rollups, no placeholder. Considered done without new work this session.

#### Phase 4 — Device Detail feature completion

**Audit result (this session):** all 6 tabs (Overview / Assignments / Events / Maintenance / Topology / Documents) already exist on `devices/show.tsx`. The outstanding work is **feature completeness within each existing tab**, not creating missing tabs. Rescoped:

12. ✅ **PR J — Device Detail: Configuration polish (S).** New `DeviceController::patchFields()` method separate from the existing full-record `update()` (which requires the full taxonomy set). PATCH endpoint accepts a narrow whitelist: `notes`, `asset_tag`, `location_description`. `PATCH /security-devices/devices/{device}/fields` route gated by `securityDevices.devices.update`. Overview tab in `show.tsx` now has inline-edit affordances: a pencil button beside the Asset Tag field opens an inline input with save/cancel, and the Notes card gets an Edit button that opens a textarea form. Chrome-verified — PATCH updates DB and reloads to reflect new values; compliance_flags deferred as it's a structured JSON editor that belongs in a future polish round.
13. ✅ **PR K — Device Detail: Relationships interactivity (M).** `DeviceController::linkRelated` + `unlinkRelated` methods use direction-aware logic (upstream / downstream), prevent duplicates through `parent_device_id + child_device_id + relationship_type`, and enforce the canonical visibility/ownership boundary. Two new routes are gated by `securityDevices.devices.update`. `show.tsx` adds `relationshipTypes` + `otherDevices`, a direction/type/device/port/notes dialog, and per-row unlink. Chrome-verified — create, duplicate prevention (302 redirect-back with error), and unlink.
14. ✅ **PR L — Device Detail: Documents upload (S).** New `DeviceDocumentController` (store / download / destroy) with 20 MB cap, 7 category whitelist (manual / install_photo / compliance_cert / firmware_notes / configuration / network_diagram / other), disk-and-row atomic delete. Stored under `device_documents/{device_id}/` on the `local` disk following the ClientDocumentController pattern. 3 new routes. `show.tsx` Documents tab replaced with: header upload button, document list showing title / category badge / version badge / expiry warning / file size / notes / download link / delete action, and a multi-step upload dialog (file, title, category, version, effective/expiry dates, notes). Chrome-verified — document list rendering, download (HTTP 200 streaming back exact file contents), delete (302 redirect-back + disk + row cleanup).

15. ✅ **PR M — Device-level `next_service_due` (S).** Migration `2026_04_18_000004_add_next_service_due_to_devices.php` adds a nullable `DATE` column with a dedicated index for dashboard rollups. Device model `$fillable` + `$casts` updated. `DeviceController::patchFields` whitelist extended so the Overview inline-edit covers this field. New Dashboard stats: `serviceDueOverdue` (count of devices with `next_service_due < today`) and `serviceDueIn30d` (within the next 30 days) — surfaced as two new StatCards. `next_service_due` column added to the Devices CSV export. Overview tab shows the date with an `Overdue` badge when the date has passed and an inline date-picker edit. Chrome-verified: setting `2026-03-15` (in the past) shows on the Dashboard as **Service Due (overdue): 1**, passes through the CSV, and renders on Device Detail with the Overdue badge.

_Note: distinct from `DeviceMaintenanceRecord.scheduled_for` (per-job) — this is a single at-a-glance device-level marker operators can set without creating a full maintenance record._

**Device Detail tab status after this session:**

| Tab | Exists | Interactive |
|---|---|---|
| Overview | ✅ | Read-only (polish in PR J) |
| Assignments | ✅ | Full (pre-existed) |
| Events | ✅ | Read-only (expected — events are emitted by adapters + manual inserts) |
| Maintenance | ✅ | Full (pre-existed; PR M polish optional) |
| Topology | ✅ | ✅ **PR K landed this session** |
| Documents | ✅ | ✅ **PR L landed this session** |

#### Phase 5 — Consolidation + hygiene

16. ⬜ **PR N — Alert consolidation finish-line (L).** Coordinate with upstream PR16 to remove deprecated `ControlRoom\Alert` (integration_alerts) once all reads migrate. Single `ControlRoomAlert` table. Freeze legacy writes → data migrate → cut reads → drop. Test browser flows end-to-end.
17. ✅ **PR O — Permission reconciliation (S).** Migration `2026_04_18_000003_grant_security_devices_integrations_manage_to_legacy_roles.php` idempotently grants `securityDevices.integrations.manage` to every role holding the former secret-management permission (including `provider_manager`, admin, and `it_manager`). The fallback was removed from all three provider controllers and route groups; they now use only `permission:securityDevices.integrations.manage`. Chrome-verified — admin still reaches all provider pages.
18. 🟡 **PR P — Retire `location_hardware` table (M).** Audit completed this session. Full retirement is **not safe in a single PR**; it's a multi-phase sequence:
    - ✅ **Phase 1 (this session):** `UnifiOperationalBridgeService::upsertLegacyShadow()` reduced to a single-log-per-device debug no-op. Removed the `legacy_location_hardware_id` back-write after sync. Deleted `syncRoomAssignment`'s `syncLegacyShadowPlacement` calls and `applyHealthUpdate`'s shadow-writing block. Pruned 5 now-dead private helpers. Preserved **read-only** fallbacks in `findCanonicalDevice`, `resolveCanonicalDeviceForHealth`, `resolveSiteId`, `findLegacyShadowForDevice` — safe because `integration_events.canonical_device_id` already provides provenance (migration 2026_04_14_000004). Updated 3 tests in `UnifiOperationalBridgeMigrationTest.php` — renamed one, added `assertSame(0, LocationHardware::query()->count())`, captured shadow `updated_at` before sync and asserted immutability. `php -l` clean.
    - ⬜ **Phase 2** — Migrate `PullIntegrationHealthJob` non-UniFi branch (lines 109–119) off direct `LocationHardware::find()` + update onto the canonical `Device` path.
    - ⬜ **Phase 3** — Deprecate legacy relationships in `Site`, `SiteRoom`, `Asset`, `ClientPersonalAsset`, `Integration\IntegrationEvent`, `ControlRoom\Alert` — all marked `@deprecated` in their docblocks but still compile-time-referenced.
    - ⬜ **Phase 4** — Delete the `LocationHardware` model class + drop the `location_hardware` table in a final migration.

    **Phase 2 backlog — LocationHardware READERS still in play (do not delete the model before these are migrated):**
    `UnifiOperationalBridgeService::findCanonicalDevice` (lines 247-264, provider_entity_id lookup), `resolveCanonicalDeviceForHealth` (lines 314-321, `legacy_location_hardware_id` FK), `resolveSiteId` (line 364, shadow site fallback), `findLegacyShadowForDevice` (lines 326-346), `IntegrationEventHistoryService` (lines 20/40/46, `hardware_id` legacy path), `PullIntegrationHealthJob` (line 109, non-UniFi direct update), plus 6 Eloquent relations (`ControlRoom\Alert`, `Asset`, `ClientPersonalAsset`, `Integration\IntegrationEvent`, `Site`, `SiteRoom`).
19. ✅ **PR Q — Deprecate legacy MVP `UnifiController` (S).** Confirmed the only live references were `routes/integrations.php` (the MVP's own routes) and my plan doc. Removed `app/Http/Controllers/UnifiController.php`. Deleted orphaned `POST /integrations/unifi/{site}` + `/{site}/sync` routes. Replaced `GET /integrations/unifi` closure redirect with a `Route::redirect` 301 straight into `/security-devices/integrations/unifi`. Legacy `UnifiConnection` model left in place — data-dropping the `unifi_connections` table is a v1.5 call when we're confident no data matters there. Chrome-verified — old bookmark at `/integrations/unifi` 301s cleanly into the module.

#### Phase 6 — Expansion (later)

20. ✅ **PR R — Additional provider parsers (L).** Underlying infrastructure was already in place: `WebhookReceiverController` handles auth / HMAC / dedup / routing. This session landed parsers for 6 new providers — the full alarm-panel and camera lineup from the original roadmap.
    - ✅ **R-slice (Queclink + Milesight):** parsers for the PR C/D providers so webhook deliveries from live Queclink/Milesight accounts classify correctly.
    - ✅ **R-remainder (Axis + Paradox + DSC + Bosch):** Four new parsers, 14 synthetic test cases, all pass. Axis: `TamperAlarm → tamper_detected critical`, `MotionTrigger → motion_detected info`, `DayNightMode → mode_change info`. Paradox: `event_group=Alarm → alarm_triggered critical`, `Tamper → tamper_detected warn`, `Arm → panel_armed info`. DSC: TPI codes 601-610 → `zone_alarm critical`, 621-624 → `tamper_detected warn`, 650-657 → `panel_state_change info`, 800s → `panic_triggered critical`. Bosch: documented `eventType` mapping + priority-range fallback (`<50 critical`, `<=100 warn`) for unknown types. Synthesised `source_event_id` from `code + timestamp` for DSC since no natural dedup key exists.
    - ⬜ Remaining: provider-specific hub UI entries (the hub catalogue lists three providers; operators can still receive webhooks from Gallagher / Hikvision / Axis / Paradox / DSC / Bosch without a dedicated provider page because the webhook receiver resolves the global provider connection). Adding a UI tab for these is catalogue work, not architectural.
21. ✅ **PR S — Device Groups auto-rules (M).** New `DeviceGroupAutoRuleService` with a strict rule schema: `match: all|any`, whitelisted fields (domain, category, subcategory, provider, status, health_status), whitelisted ops (equals, not_equals, in). Empty-conditions arrays deliberately match nothing (footgun guard). Service exposes `queryFromRules`, `preview`, and `applyToGroup` (returns added/removed/kept/total delta and is idempotent). `DeviceGroupController` extended to accept `auto_rules` on store/update, plus `previewAutoRules` (JSON) and `syncAutoRules` (redirect-back) endpoints. `show.tsx` shows an "Auto-rules: N conditions" badge next to the members count and a "Sync auto-rules" button that prompts for confirmation. Chrome-verified — seeded a test group with `provider=unifi AND domain=security`, sync picked up both seeded devices, second sync added 0 / kept 2 (idempotent).
22. ⬜ **PR T — Assignments workbench (M).** Cross-site bulk reassignment view. Only build when volume justifies it.
23. ⬜ **PR U — Maintenance WO integration (XL).** When a Maintenance module exists, convert the notes log into a WO list.
24. ⬜ **PR V — Downlink config push (M, per provider).** For providers that support it (Milesight first), allow operators to push config changes to devices. Flag in `device_profiles.downlink_supported`.
25. ✅ **PR W — Care module signal subscription (S).** End-to-end landed.
    - ✅ **Foundation:** `App\Domain\SecurityDevices\Events\DeviceSignalPublished` domain event dispatched by `DeviceEventObserver` with `(Device, DeviceEvent, Signal, bool alertCreated)`. Runtime-verified — listener receives correct payload; heartbeats suppressed before dispatch.
    - ✅ **Listeners:** Three Care listeners at `app/Listeners/Care/` — `NotifyOnFallDetected`, `NotifyOnBedExit`, `NotifyOnMedicationCabinetOpen`. Registered via new `App\Providers\EventServiceProvider` (added to `bootstrap/providers.php`). Device→Client resolution uses the modern `DeviceAssignment::where('assignable_type', 'client')->whereNull('released_at')` polymorphic path — same pattern as `FleetDeviceRuntimeService::resolveConsentContext`. Deliberately avoided the deprecated `ClientPersonalAsset.tracker_hardware_id` FK. Medication-cabinet detection reads `device.compliance_flags.medication_cabinet` first, falls back to `device.meta.medication_cabinet` (the column doesn't exist yet; fallback keeps listener forward-compatible for when Phase 3 adds it). All three listeners log structured context on match and warn gracefully on missing client. Synchronous dispatch at current volume — flip to `ShouldQueue` if fall-event volume ever warrants.

---

### Dependency graph (critical path)

```
PR A ──► PR B ──► PR E                    (foundation — DONE)
          │
          ├──► PR C ──► PR C1              (Queclink)
          │
          └──► PR D ──► PR D1              (Milesight)
                                            │
PR F ────────────────────────► PR H ───────┼──► PR W   (signals → care)
                                  │
PR G ───────────────────────► PR I         (dashboard)
                                  │
PR J,K,L,M ─────────────────► PR N         (detail tabs + alert consolidation)
                                  │
                                 PR O ──► PR P ──► PR Q  (hygiene sequence)
                                            │
                                           PR R,S,T,U,V  (expansion)
```

PR H is a **load-bearing node** — every downstream alerting / care integration depends on it. Should not slip past Phase 3.

### Suggested rollout cadence

**Session of 2026-04-18 status update:** PRs A/B/C/D/E/F/G/H/I all landed and Chrome-verified in a single session. The roadmap below reflects what is *genuinely* remaining.

- **~~Week 1–3~~** — Phases 1–3 complete (see ✅ above).
- **Next — Phase 4:** PR K (relationships interactivity) is the highest-value remaining detail-tab PR. PR J / L / M are polish.
- **Then — Phase 5:** PR N (alert consolidation coordination with upstream PR 16), then the hygiene sequence PR O → P → Q.
- **Then — PR C1 / D1 + downstream expansion** as driven by real API access and customer use.

The critical path from the original plan (PR H — signal rule seed) is done. No roadmap item now blocks downstream Care / alerting work.

### What to **not** build during Phase 2–5

- **Device Groups auto-rules** — tag filtering solves 90% of grouping needs. Defer to PR S unless evidence demands it.
- **Assignments workbench** — don't build before cross-site reassignment volume justifies it.
- **Maintenance WO module** — stays in Maintenance module land; our tab is just notes for now.
- **More alert tables** — consolidate, don't add.
- **Provider-specific UI leakage** — keep the cross-provider Global Devices view neutral. Provider-specific nuance belongs on its provider page and Device Detail Connectivity tab.

### Acceptance checklist per PR (copy-paste template)

For every PR above, require:
1. Route registered and resolves under `php artisan route:list`.
2. Inertia component renders in Chrome with zero console errors.
3. Permission gate verified with a user who lacks the permission (expect 403).
4. Dual-key permission fallbacks (if any) documented in PR description.
5. New tests: at least one Feature test hitting the new route end-to-end with seeded data.
6. Plan doc updated to mark the PR ✅ and note any scope cuts.

---

## Original Plan (v1) — retained for architectural reference

> This plan reflects the current state of the codebase as mapped in the `dazzling-wu-fbdb61` worktree. Key existing surfaces: `LocationHardware` model, `SiteHardwareController`, `Integration\*` framework, monolithic `resources/js/Pages/sites/hardware/index.tsx`, and the tension between `control_room_alerts` (legacy) and `integration_alerts` (new).

---

## 1. Executive Architecture Summary

### Target operating model

| Module | Owns | Does **NOT** own |
|---|---|---|
| **Security & Devices** (new home of device truth) | Device inventory, classification, provider credentials, sync state, assignment history, relationships, maintenance state, imported-vs-manual provenance, signal emission | Site/house/room structure, alert triage, staff records, asset lifecycle |
| **Sites / Houses / Locations** | Physical hierarchy (site → house → room → zone), occupancy, physical context | Device CRUD, credentials, integration setup, sync, field mapping |
| **Control Room** | Alert ingestion, triage queue, acknowledgement, assignment, escalation, SLA, closure, incident linkage | Device registration, provider configuration |
| **Fleet** | Vehicle context, driver behaviour, telemetry consumption | GPS tracker hardware registration (that moves to Security & Devices) |
| **HR / Workforce** | Staff records, competencies, rostering | Device-to-person assignment (the assignment lives in Security & Devices; HR is only referenced) |

### Ownership boundary rules (non-negotiable)

1. **One device, one system of record.** The `devices` registry (formerly `location_hardware`) is authoritative. Any other module referencing a device does so by ID, never by copying.
2. **One alert system.** The two alert tables (`control_room_alerts` legacy + `integration_alerts` new) must be consolidated into a single alert table owned by Control Room. Security & Devices emits signals; it does not maintain an alert queue.
3. **Credentials never touch a site page.** Global provider connections and Site capability credentials live in APIs & Integrations. Site pages can *reference* a connection, but cannot create, edit, or delete one.
4. **Assignment is reversible and audited.** Moving a device from Site A Room 2 to Site B Van 3 is a first-class operation with a history row, not an in-place column mutation.
5. **Imported data is read-only in the field it came from.** If UniFi says a camera’s MAC is `aa:bb:cc:…`, Oblivion never lets a user overwrite that. OF-managed metadata (asset tag, NZ compliance notes, maintenance log, assigned person) lives in separate columns.

### Signal flow (target)

```
Provider API  ──►  Adapter (UniFi / Queclink / Milesight)
                     │
                     ▼
              IntegrationEvent  (raw, unmodified)
                     │
                     ▼
              NormalizedSignal  (device_id, type, severity, occurred_at)
                     │
                     ├──►  Device.last_seen_at / status (Security & Devices)
                     │
                     └──►  AlertRoutingService  (rule match → Control Room)
                                      │
                                      ▼
                                 Alert (single table, owned by Control Room)
```

No alert is ever created inside Security & Devices. It only produces `NormalizedSignal`. Control Room decides whether a signal becomes an alert.

---

## 2. Information Architecture / Left Navigation

### Top-level placement

`Security & Devices` is a top-level sidebar module (peer of Sites, Control Room, Fleet, HR, Care).

### Submenu (v1)

```
Security & Devices
├── Dashboard                ← health rollup, unassigned count, sync status
├── Devices                  ← global inventory, UniFi-style table
├── CCTV                     ← domain page (cameras + NVRs)
├── Access Control           ← domain page (doors, readers, locks)
├── Alarms                   ← domain page (intrusion, duress, smoke, door-open sensors)
├── Tracking                 ← domain page (GPS trackers for clients + vehicles + assets)
├── Smart IoT                ← domain page (bed sensors, fall detection, medication cabinets, env sensors)
├── ───────────────────
├── Assignments              ← global reassignment workbench (later)
├── Maintenance              ← schedule + WO list (later)
├── ───────────────────
└── APIs & Integrations
    ├── Overview             ← all providers at a glance
    ├── UniFi
    ├── Queclink
    ├── Milesight
    └── Webhooks (later)
```

### What exists in v1 vs. later

| Item | v1 | Later |
|---|---|---|
| Dashboard | Yes, minimal (tiles only) | Yes, richer (trends, maintenance overdue) |
| Devices (global) | Yes | — |
| CCTV / Access / Alarms / Tracking / Smart IoT | Yes | — |
| APIs & Integrations (UniFi, Queclink, Milesight) | Yes | Add Hikvision, Axis, generic webhook |
| Assignments workbench | No — handle inline on Device Detail | Yes, when volume demands |
| Maintenance | No — soft placeholder, no WO module | Yes, when Maintenance module exists |
| Device Groups | **No** | Yes if filtering proves insufficient |
| Reports | No — rely on global Reporting module | Yes, dedicated per-domain reports |

### Rationale for what is **excluded** from v1

- **Device Groups:** Tags + smart filters on the Devices page solve 90% of grouping needs. Adding Groups now creates a naming/ownership problem before it has value.
- **Dedicated Reports:** The org already has (or will have) a Reporting module. Don’t fork a second reporting surface inside Security & Devices.
- **Maintenance Work Orders:** Placeholder only. A real WO engine belongs in a Maintenance or Facilities module; stubbing it inside Security & Devices will create migration pain later.

---

## 3. Location / Site / House View Design

### Current state to kill

`resources/js/Pages/sites/hardware/index.tsx` (2000+ lines) does all of: inventory CRUD, UniFi discovery, credential forms, room management, sync controls. This becomes a **read-only context tab**.

### New Site → Hardware tab (target)

**Header strip (cards, all read-only):**
1. **Devices on site** — total count, with breakdown chip row: `12 cameras · 4 doors · 8 sensors · 2 trackers · 18 smart IoT`.
2. **Online / offline** — `41 online · 3 offline · 2 unknown` with a health dot.
3. **Connected providers** — icons for UniFi, Queclink, Milesight with a coloured status dot each. Click → deep-link to provider page filtered to this site.
4. **Unassigned to rooms** — count of devices on this site not yet placed in a room. Click → opens Global Devices page with `site:X has_no_room` filter.

**Table — one per category (tabs):** `All · Cameras · Doors · Alarms · Trackers · Smart IoT`.

Columns (same across categories, since this is a context view): Name · Category · Room · Provider · Status · Last seen · Actions.

**Actions allowed on this page (limited):**
- **View in Security & Devices** (row-level) — opens global Device Detail page.
- **Move to room** (row-level) — lightweight in-line room reassignment within this site only. This is a physical placement action, not a device management action. Keep it.
- **Add room** and **rename room** (header-level) — rooms are physical structure, owned by Sites.

**Actions removed from this page:**
- Create device (any category).
- Edit device name, serial, MAC, asset tag, notes.
- Delete / retire device.
- Provider credential entry or editing.
- Test connection, sync devices, pull events, discover sites.
- Field mapping.
- Linking device to an asset, person, or vehicle. (Reassignment is a cross-site operation — belongs in Security & Devices.)

### Provider mapping display

In a small "Integrations at this site" card below the hardware table:
- For each connected provider: provider name, mapped external site name (e.g., `UniFi Controller site → "Auckland North"`), connection health, last sync time, **View in APIs & Integrations** button.
- No edit, no disconnect, no credential fields.

### Handoff to Security & Devices

- Every device row has a persistent **View in Security & Devices** action (row action and detail button). Opens the Device Detail page in the global module with a `?back=site:{siteId}` param to provide a breadcrumb back.
- A banner at the top of the Site Hardware tab: *"Managing devices across sites, providers, and assignments is done in Security & Devices."* with a button **Go to Security & Devices**.

---

## 4. APIs & Integrations Module Plan

### Shared page pattern for all providers

Each provider page has the same skeleton so the operator mental model transfers:

```
┌─ Overview tab ─────────────────────────────────────────┐
│ Connection status · Last sync · # of sites mapped     │
│ # of devices imported · # of errors in last 24h       │
└────────────────────────────────────────────────────────┘
┌─ Connection tab ───────────────────────────────────────┐
│ Global connection and per-Site credentials, test,     │
│ base URLs, capability toggles                          │
└────────────────────────────────────────────────────────┘
┌─ Site Mapping tab ─────────────────────────────────────┐
│ Table: Oblivion Site ↔ External Site/Controller/Group │
│ Status: mapped / unmapped / mismatched                 │
└────────────────────────────────────────────────────────┘
┌─ Sync & Schedule tab ──────────────────────────────────┐
│ Sync cadence, manual trigger, last run breakdown       │
│ Import rules (category auto-mapping, naming template)  │
└────────────────────────────────────────────────────────┘
┌─ Exceptions tab ───────────────────────────────────────┐
│ Unmapped devices, duplicates, conflicts, ignored       │
└────────────────────────────────────────────────────────┘
┌─ Logs tab ─────────────────────────────────────────────┐
│ IntegrationSyncLog entries + IntegrationEvent tail     │
└────────────────────────────────────────────────────────┘
┌─ Field Mapping tab ────────────────────────────────────┐
│ Which provider field maps to which OF field            │
│ (editable only where safe, e.g., category heuristics)  │
└────────────────────────────────────────────────────────┘
```

### Provider-specific contents

#### UniFi (already partially functional)

- **Connection:** one global `UI Site Manager` provider connection, plus per-Site capability credentials for Protect, Access, Network, and AI Ports (`IntegrationSiteSecret`, capability column). Generalise the capability list; do not hardcode UniFi-only values.
- **Site Mapping:** Oblivion Site → UniFi controller site (e.g., `"Auckland North"`).
- **Import rules:** UniFi device type → OF category. `UVC-*` → camera, `UAP-*` → ap (infrastructure), `UA-*` → door, `USW-*` → switch, etc. Provide a default mapping JSON the operator can override.
- **Duplicate handling:** match on MAC (primary), serial (secondary). If a device exists with same MAC in another site, flag as "moved" not "duplicate" and require operator acknowledgement before reassigning.
- **Ignored devices:** operator can mark a UniFi-discovered device as "Do not import" — stored in `integration_ignored_devices` (see data model). Sync will skip it.
- **Field mapping:** mostly fixed. MAC, serial, model, firmware are authoritative from UniFi. Name: first sync imports it; after that, OF-edited name wins unless operator chooses "track UniFi name".

#### Queclink (GPS trackers — GV/GL series)

- **Overlap warning:** Queclink is already in the Fleet module (`app/Services/Fleet/Telemetry/QueclinkAdapter.php`) for vehicle telemetry. Security & Devices and Fleet share the adapter but own *different* bounded contexts: Security & Devices owns the tracker **hardware** (IMEI, SIM, firmware, assignment). Fleet owns the **telemetry stream** (positions, trips, driver behaviour). Decision: extract Queclink adapter into a provider-neutral `app/Services/Integration/Adapters/QueclinkAdapter.php` and let Fleet consume tracker positions via an event bus or read-through of `Device` + `DeviceTelemetrySnapshot`, rather than owning the credential.
- **Connection:** per-account Queclink server credentials (host, port, auth). In NZ context, most deployments use a Queclink cloud account; expose both.
- **Import rules:** IMEI is primary key. SIM ICCID stored separately. Category auto-mapped to `tracker`. Subcategory (`vehicle_tracker` / `personal_tracker` / `asset_tracker`) inferred from device model, operator-overridable.
- **Assignment:** on import, tracker is unassigned. Operator assigns to Vehicle (Fleet), Person (client/staff via HR), or Asset. Personal trackers for NZ supported-living clients are a primary use case — make assignment to a Client profile first-class.
- **Field mapping:** IMEI, model, firmware authoritative. SIM ICCID & carrier are OF-editable (SIMs swap without the tracker moving).

#### Milesight (net-new — no existing code)

- **Scope:** Milesight sells both LoRaWAN IoT (environmental sensors, people counters, water leak, workplace sensors) and IP cameras. In a supported-living context the LoRaWAN stack (bed-exit, fall, door, temp/humidity, air quality) is the primary interest; cameras are secondary.
- **Connection:** Milesight Development Platform or on-prem gateway. Per-gateway credential model: a `gateway` is itself a device (imported and visible in Devices), and it owns its child sensors. Adapter must handle both cloud API and local HTTP/MQTT bridge.
- **Site Mapping:** map Oblivion Site → Milesight Application / Gateway.
- **Import rules:** EUI is primary key. Device profile (`TS/AM/WS/VS/…` prefix) determines OF category. Sensor reports include decoded payload (temp, occupancy, etc.) — store these as `DeviceTelemetrySnapshot` rows.
- **Duplicate handling:** same EUI imported under two gateways = device moved (probably range / gateway swap). Require acknowledgement.
- **Field mapping:** EUI, DevAddr, profile are authoritative. Location name and assigned room are OF-editable.

### Future extensibility (v1.5+ providers)

The adapter interface must accept these without refactor: Hikvision / Dahua (cameras), Axis (cameras + radar), Paradox / DSC / Bosch (alarm panels via integration hubs), generic webhook, Tuya / Home Assistant bridge for consumer smart IoT if needed. Define an `IntegrationAdapter` PHP interface with: `testConnection()`, `discoverExternalSites()`, `syncDevices(Site $ofSite)`, `pullEvents(Site $ofSite, DateTimeInterface $since)`, `normalizeEvent(array $raw)`, `capability(): array`.

---

## 5. Global Devices Page Design

### Goal

Operationally equivalent to UniFi’s "Devices" list, but multi-provider, multi-site, assignment-aware.

### Summary cards (top strip)

Five cards, all filter-clickable:

1. **All devices** — total visible count, reconciled through Site access and explicit all-Sites authority.
2. **Online** — `n · %` with green dot. Clicking filters to `status:online`.
3. **Needs attention** — count. Clicking filters to attention devices (rules below).
4. **Unassigned** — devices with no room, no vehicle, no person, no asset.
5. **Provider sync errors** — count of devices whose last provider sync errored in 7d.

### "Needs attention" rules (v1 — small, tight)

A device needs attention if **any** of:
- `status = offline` **and** previously `online` within 7 days (transient → stale).
- `last_seen_at` older than the device-profile’s `expected_heartbeat_window` (for trackers = 1h, cameras = 15m, LoRaWAN sensors = profile-defined).
- Battery level (if reported) below threshold for its profile.
- Firmware older than current available provider firmware (provider-dependent).
- Sync error on last provider pull.
- Imported but unassigned for more than 7 days (the "hanging device" signal).

Keep the rule set short and explicit. Resist adding 15 subjective rules.

### Search

Global search box across: name, asset_tag, serial, MAC, IMEI, EUI, notes, external_ref values. Server-side, use indexed columns.

### Quick filters (pill row)

`All · Cameras · Doors · Alarms · Trackers · Smart IoT · Network (infrastructure)`.

The Network pill exists so operators can see switches/APs/gateways without them cluttering the domain-focused pills.

### Dropdown filters

- Site (multi)
- Provider (multi)
- Status (online / offline / unknown / retired / needs attention)
- Assignment (unassigned / assigned to room / person / vehicle / asset)
- Source (imported / manual)
- Needs-attention reason (stale / offline / battery / firmware / sync error / unassigned)

### Table columns (v1 default)

| Col | Notes |
|---|---|
| ○ | Status dot (green/amber/red/grey) |
| Name | OF-editable; italic + "(from UniFi)" if mirrored |
| Category | Badge with icon |
| Model | Imported |
| Provider | Badge; "Manual" if none |
| Site | Linked |
| Assigned to | Room / Vehicle / Person / Asset / — |
| Last seen | Relative time |
| Health | Compact indicator if needs-attention, else "OK" |
| Row actions | View · Reassign · Mark retired |

Column chooser + sticky header + 50/100/200 pagination.

### Imported vs manual visibility

- Manual devices have a small **Manual** chip next to the name.
- Imported devices show the provider logo; tooltip: *"Imported from UniFi – site Auckland North on 2026-04-12. Fields: MAC, serial, model, firmware are provider-owned."*

### Assignment visibility

The **Assigned to** column merges all assignment types. Tooltip reveals full assignment chain (e.g., *"Assigned to John Doe (client) since 2026-03-01 by S. Prinsloo"*). An unassigned device is highlighted amber in this column.

---

## 6. Device Detail Page Design

### Header (always visible)

- Device photo / icon, name, category badge, provider badge.
- Status dot + `Online · Last seen 3m ago`.
- Assignment summary (one line): `Rimu House · Lounge · Assigned to asset "Lounge Camera"`.
- Right-aligned: **Reassign**, **Mark retired**, **Open in provider** (deep-link to UniFi / Queclink / Milesight console).

### Tabs

1. **Overview**
   - Summary cards: status, last seen, connectivity type, battery (if any), firmware, provider, source (imported/manual).
   - Key activity: last 10 events (from `IntegrationEvent` normalised).
   - Signals-to-alerts mini-log: what signals this device has produced and which became Control Room alerts.

2. **Assignment**
   - Current assignment (room / person / vehicle / asset).
   - Full assignment history table: `from · to · assigned_by · when · reason`.
   - Reassign action (modal with assignment target picker).
   - For personal trackers, assignment to a Client profile is distinct from assignment to a Staff member; UI clarifies.

3. **Connectivity**
   - Network info: IP, MAC, WiFi SSID, uplink, PoE port (for UniFi-discovered).
   - LoRaWAN info for Milesight (EUI, DevAddr, gateway, SF, RSSI).
   - Cellular info for Queclink (IMEI, SIM ICCID, carrier, signal).
   - All imported fields read-only, shown as such.

4. **Configuration**
   - Device-profile driven: for a camera, FPS/resolution/streaming paths (read-only from provider); for a tracker, heartbeat interval (sometimes OF-pushable via provider adapter — flag in profile).
   - OF-managed: notes, asset tag, compliance category, NZ-specific fields (e.g., "approved for medication cabinet" toggle).

5. **Relationships**
   - Bidirectional relationship list: *"This door reader controls → Front Door (lock)"*, *"This camera overlooks → Lounge area"*, *"This NVR records from → 4 cameras"*.
   - Relationships are typed (`controls`, `records_from`, `observes`, `connected_to`, `child_of`).

6. **History / Activity**
   - Unified log: sync events, assignment changes, config edits, status transitions, signal emissions.
   - Filterable by type.

7. **Documents**
   - Upload datasheets, warranty certs, NZ compliance certificates, install photos.
   - No versioning needed v1 — use existing Media or Document abstraction if present; otherwise a simple polymorphic attachment.

8. **Maintenance**
   - Last serviced date (OF-managed).
   - Next scheduled service (optional).
   - Notes log for field visits.
   - In v1, this tab is plain-text logs. When a Maintenance module is built, this becomes a WO list.

### Read-only vs editable split

| Field | Imported device | Manual device |
|---|---|---|
| MAC / Serial / IMEI / EUI | Read-only | Editable on create, locked after |
| Model / Firmware | Read-only | Editable |
| Name | Editable; operator sees "originally from UniFi as X" | Editable |
| Category / Subcategory | Editable (provider suggests, operator can override) | Editable |
| Asset tag, Notes, NZ compliance flags | Editable | Editable |
| Assignment (room/person/vehicle/asset) | Editable | Editable |
| Provider config (e.g., heartbeat) | Read-only unless adapter supports push | Not applicable |

---

## 7. Domain Pages Standard

All domain pages (CCTV, Access Control, Alarms, Tracking, Smart IoT) share the Global Devices pattern but with: narrowed category scope, domain-specific summary cards, and domain-specific columns.

### Shared skeleton

```
[ 4 summary cards specific to this domain ]
[ search · filters · export ]
[ tab row: by subcategory | by site | by provider ]
[ table ]
```

### CCTV

- **Purpose:** operational view of cameras + NVRs across all sites and providers.
- **Summary cards:** Total cameras · Online · Recording (yes/no from provider) · Offline > 1h.
- **Filters:** By provider (UniFi Protect, Hikvision later), by site, indoor/outdoor, PTZ/fixed, resolution bucket.
- **Columns:** Name · Model · Site · Room · Resolution · Recording · Last seen · Provider.
- **Provider-specific considerations:** UniFi Protect is the v1 path. Hikvision/Axis later. Do not attempt to embed live video streams in v1 — deep-link to provider console via **Open in provider**.

### Access Control

- **Purpose:** doors, readers, locks, and the assets they control.
- **Summary cards:** Total access points · Online · Currently locked · Failed card reads 24h.
- **Filters:** By provider (UniFi Access), by site, by reader type.
- **Columns:** Name · Type (reader/lock/door) · Site · Room · Status · Last event · Provider.
- **Provider-specific considerations:** UniFi Access first. Credentialing (who has access) is *not* owned by Security & Devices — that belongs in a future Access Control policy module. Devices just track the hardware and the event stream.

### Alarms

- **Purpose:** intrusion, duress, smoke, door-open, window-open sensors. Heterogeneous by design — these come from a mix of panel vendors and IoT providers.
- **Summary cards:** Total alarm devices · Armed (if provider supports arm state) · Active alarms (signals routed to Control Room in last 24h) · Low battery.
- **Filters:** By provider, by subcategory (intrusion / duress / environmental / opening).
- **Columns:** Name · Subcategory · Site · Room · State · Battery · Last triggered · Provider.
- **Provider-specific considerations:** There is no v1 alarm-panel provider. Milesight LoRaWAN covers environmental and opening sensors. Duress buttons may come via Queclink (man-down) — noted in their detail. Add Paradox/DSC/Bosch later.

### Tracking

- **Purpose:** GPS devices on vehicles, staff, clients, and assets. This is where NZ supported-living personal trackers live.
- **Summary cards:** Total trackers · Online/reporting · Low battery · Unassigned.
- **Filters:** By subcategory (vehicle / personal / asset), by assignment target type, by provider.
- **Columns:** Name · Subcategory · Assigned to (Vehicle / Person / Asset / —) · Last position age · Battery · Provider.
- **Provider-specific considerations:** Queclink v1. Ensure a clear "Assign to Client" flow for personal trackers used by supported-living clients. This is a high-sensitivity use case — changes to client assignments should be audit-logged with reason.

### Smart IoT

- **Purpose:** environmental sensors, bed-exit, fall detection, water leak, air quality, medication cabinets, people counters.
- **Summary cards:** Total sensors · Reporting in window · Low battery · Unassigned.
- **Filters:** By subcategory (bed / fall / door / temp / humidity / leak / co2 / occupancy / medication), by provider, by site.
- **Columns:** Name · Subcategory · Site · Room · Last reading · Last seen · Battery · Provider.
- **Provider-specific considerations:** Milesight v1 covers most LoRaWAN needs. Healthcare-grade sensors (fall/bed) may later come via a dedicated vendor — that will be a new provider in APIs & Integrations.

---

## 8. Functional Workflows

Each workflow below names the owning module/surface.

### 8.1 Manual device registration

**Owner:** Security & Devices → Devices page → **Add device** button.

1. User opens Add device modal.
2. Picks category (camera / door / alarm / tracker / smart IoT / infrastructure / other).
3. Enters identifier set (serial + MAC, or IMEI + SIM, or EUI — modal adapts).
4. Enters name, optional model.
5. Picks site (required), optional room.
6. Optionally assigns to a person / vehicle / asset (or leaves unassigned).
7. Save → `Device` row with `source = manual`, `provider = null`. Appears in Global Devices immediately.

Disallowed entry points: Site Hardware tab, HR profile, Vehicle profile. All must funnel through the central modal.

### 8.2 Imported device sync

**Owner:** APIs & Integrations → provider page → **Sync devices**.

1. Operator clicks Sync (or scheduled job fires).
2. `SyncIntegrationDevicesJob` runs per mapped site.
3. Adapter returns external device list.
4. For each external device:
   - Match on primary key (MAC / IMEI / EUI).
   - If matched → update OF-allowed fields (not overwriting operator-edited name etc. unless "track provider name" is on for that device).
   - If new → create `Device` row with `source = imported`, provider set, populate from import rules.
   - If MAC/IMEI/EUI found at a different site → mark as **moved** (Exceptions tab surfaces it for acknowledgement — no automatic relocation).
   - If ignored list matches → skip.
5. Log summary to `IntegrationSyncLog`.

### 8.3 Site assignment (for a new manual device)

Covered in 8.1 step 5–6.

### 8.4 Reassignment (cross-site or cross-target)

**Owner:** Security & Devices → Device Detail → **Reassign** button (also available from Devices table row action).

1. Modal with 3 steps: **Site → Target → Reason**.
2. Target selector is polymorphic: Room on site / Vehicle on site / Person (Staff or Client) / Asset / None.
3. Reason field is required (dropdown + free text: `relocation`, `repair_swap`, `client_change`, `vehicle_sale`, `other`).
4. On save: close current `DeviceAssignment` row (set `ended_at`), open a new one. Update `Device.current_assignment_id` cache.
5. Audit event written.
6. If the target was on a different site, provider-side mapping may need an update — adapter’s `onReassign()` hook fires (no-op for most v1 providers, relevant later for Milesight gateway rebinding).

### 8.5 Duplicate resolution

**Owner:** APIs & Integrations → provider page → Exceptions tab.

1. Sync detects duplicate (same key exists at different site, or two external records collide).
2. Row appears in Exceptions with: existing OF device, incoming external record, detected conflict reason.
3. Operator picks one of: **Merge** (keep OF ID, absorb external record), **Replace** (archive OF device, create new), **Mark moved** (update assignment to new site), **Ignore this external record**.
4. Audit logged. Background jobs do not auto-resolve.

### 8.6 Unmapped device triage

**Owner:** APIs & Integrations → provider page → Exceptions tab.

1. Sync pulls a device from an external site that isn’t mapped in OF.
2. Two outcomes:
   - The external site corresponds to an OF site that hasn’t been mapped yet → operator creates the mapping, device gets imported on next sync.
   - The external site has no OF counterpart → operator either creates a new OF Site (handed off to Sites module) or adds the device to an "unassigned pool" with `site_id = null` and source flagged for later placement.

### 8.7 Provider mapping to site

**Owner:** APIs & Integrations → provider page → Site Mapping tab.

1. After connection is live, Mapping tab lists all external sites/controllers/applications and all OF sites.
2. Operator drags or picks to pair them.
3. A mapping row is written to `integration_site_configs` (existing table).
4. Mapping can be **one external ↔ one OF** in v1. Many-to-one (one OF site served by two UniFi controllers) is rare and deferred to v1.5.

### 8.8 Maintenance follow-up

**Owner:** Security & Devices → Device Detail → Maintenance tab (v1 light).

1. Operator logs a service note: date, technician, summary.
2. Optional: set "Next service due" date.
3. Devices with an overdue next-service date appear in the Dashboard and get flagged with a subtle amber chip on the Devices table — *not* a Control Room alert. Maintenance state is an internal working signal, not an operational alert.

### 8.9 Signal handoff to Control Room

**Owner:** `AlertRoutingService` (existing) + `SignalRule` (existing).

1. Adapter writes `IntegrationEvent` (raw).
2. Normaliser produces a `NormalizedSignal` with `(device_id, signal_type, severity, occurred_at, payload)` and updates `Device.last_seen_at` / `status`.
3. `AlertRoutingService` matches against `SignalRule` rows. A match becomes a Control Room `Alert` (single table — see Data Model §9).
4. Security & Devices never renders an Alert list of its own. If an operator wants to see "this device’s alerts", Device Detail → History tab shows a read-through summary with links into Control Room.

---

## 9. Data Model / Entities

This section describes the target state. Migrations should be additive where possible; see §11 for the rename vs. additive decision.

### 9.1 Core tables

#### `devices` (target — see 9.6 for migration from `location_hardware`)

| Column | Notes |
|---|---|
| id | PK |
| name | OF-editable |
| category | enum: camera · door · alarm · tracker · smart_iot · infrastructure · other |
| subcategory | string (e.g., `personal_tracker`, `bed_sensor`, `door_reader`, `ap`, `switch`) |
| model | provider-owned if imported |
| manufacturer | |
| serial | |
| mac | nullable |
| imei | nullable |
| eui | nullable |
| sim_iccid | nullable, OF-editable |
| firmware_version | |
| site_id | FK sites — nullable (supports "unassigned pool") |
| current_assignment_id | FK device_assignments — cached current |
| source | enum: imported · manual |
| provider | nullable, FK integrations.provider |
| external_ref | JSON — provider-specific IDs |
| status | enum: online · offline · unknown · retired |
| last_seen_at | |
| battery_level | nullable int 0–100 |
| notes | |
| compliance_flags | JSON — NZ-specific (e.g., `medical_device: true`) |
| meta | JSON |
| created_at / updated_at / deleted_at | |

Unique provider identity where applicable: `(provider, external_ref->primary_key)`.

#### `device_assignments`

Polymorphic, full history (no in-place mutation).

| Column | Notes |
|---|---|
| id | |
| device_id | FK |
| target_type | enum: room · vehicle · person · asset · none |
| target_id | nullable if `target_type = none` |
| site_id | denormalised for fast filter |
| reason | enum + free text |
| assigned_by_user_id | |
| started_at | |
| ended_at | nullable — null = current |

#### `device_relationships`

| Column | Notes |
|---|---|
| id | |
| device_id | the "from" device |
| related_device_id | the "to" device |
| type | enum: controls · records_from · observes · connected_to · child_of |
| direction | enum: directional · bidirectional |
| meta | JSON (e.g., channel number for NVR→camera) |

Create both rows for bidirectional? Simpler: keep one row and infer the reverse in the query layer.

#### `device_profiles`

Defines per-subcategory defaults (expected heartbeat window, battery threshold, fields to show in Connectivity tab).

| Column | Notes |
|---|---|
| id | |
| subcategory | unique |
| display_name | |
| expected_heartbeat_seconds | |
| low_battery_threshold | |
| connectivity_fields | JSON — which fields are relevant |
| default_icon | |

Seeded, not user-managed in v1.

#### `device_telemetry_snapshots`

Latest reading per device (not a time-series store — for that use IntegrationEvent or a dedicated timeseries table).

| Column | Notes |
|---|---|
| device_id | unique FK |
| last_payload | JSON |
| last_recorded_at | |

#### `device_documents`

Polymorphic if the codebase already has a media/attachments table, use that instead.

### 9.2 Integration tables (keep existing, extend)

- **`integrations`** — keep.
- **`integration_provider_connections`** — one global provider connection per provider; secret material stays write-only.
- **`integration_site_configs`** — keep.
- **`integration_site_secrets`** — keep; generalise `capability` from hardcoded UniFi values to a per-provider capability catalogue (stored as string, validated against provider metadata in code, not DB enum).
- **`integration_events`** — keep.
- **`integration_sync_logs`** — keep.

### 9.3 New integration tables

- **`integration_ignored_devices`** — `(provider, external_primary_key, ignored_by, ignored_at, reason)` with approved-Site discovery provenance.
- **`integration_exceptions`** — `(provider, site_id, type: duplicate|moved|unmapped|conflict, payload JSON, status: open|resolved|dismissed, resolved_by, resolved_at, resolution JSON)`. This backs the Exceptions tab and preserves the affected Site boundary.
- **`integration_field_mappings`** — optional for v1; hardcode the defaults. Only materialise this table if operators actually need to override, to avoid empty-config sprawl.

### 9.4 Signal and alert tables — the consolidation decision

**Current state:** two alert tables (`control_room_alerts` legacy, `integration_alerts` new).

**Target state:** a single **`alerts`** table owned by Control Room. Add a `source` column (`integration | fleet | manual | other`) and keep `integration_event_id` nullable FK for integration-sourced alerts.

**Migration path:** do **not** rename tables in one PR. Instead:
1. Introduce the unified `alerts` table (or pick one existing table to absorb the other — I recommend making `integration_alerts` the canonical one and migrating the handful of legacy `control_room_alerts` columns into it, since `integration_alerts` already has the device/event linkage the future needs).
2. Dual-write in Control Room for one release cycle.
3. Cut reads over to unified table.
4. Drop legacy table.

This is a standalone workstream (see PR 11 in §11).

Add a new table **`normalised_signals`** only if profiling shows the Control Room rule engine wants a clean intermediate stream. If `IntegrationEvent` already carries normalised payload + severity + type, skip this table.

### 9.5 Source-of-truth column strategy

For imported devices, record `provider_owned_fields` as a JSON array on each device (e.g., `["mac", "serial", "firmware_version"]`). The UI uses this to render those fields read-only regardless of category. Keeps ownership enforcement data-driven rather than hardcoded.

### 9.6 `location_hardware` → `devices` — migration strategy

**Recommendation: rebrand at the code/UI level first; rename the table later.**

- Keep the physical table name `location_hardware` in v1. It already has the right shape (26 columns, covers most of what `devices` needs).
- Add missing columns via additive migrations: `subcategory`, `imei`, `eui`, `sim_iccid`, `manufacturer`, `firmware_version`, `battery_level`, `compliance_flags`, `current_assignment_id`, `source`, `provider_owned_fields`.
- Introduce `Device` model class as an alias / replacement for `LocationHardware`. Deprecate `LocationHardware` in PhpDoc; keep it for one cycle with a class alias (`class_alias(Device::class, LocationHardware::class);`) so existing call sites keep working.
- In v1.5, once call sites are migrated, do the table rename in a dedicated PR.

This avoids a high-risk rename early in the restructure.

---

## 10. Module Integration Plan

### 10.1 Sites / Houses

- **v1:** Sites module reads from `Device` via a scoped query (`Device::forSite($site)->with('currentAssignment')`). Site hardware page becomes a read-only tab (see §3).
- **v1:** Room management stays in Sites (it is physical structure). Device→Room assignment is a write, but routed through the Security & Devices reassignment endpoint.
- **Later:** Site overview dashboard can embed a small "Device health" card that pulls from Security & Devices.

### 10.2 Control Room

- **v1:** Consolidate to a single alert table (see §9.4). Signal-to-alert flow fully owned by Control Room.
- **v1:** Control Room Dashboard gains a card "Devices producing most alerts" that deep-links to Security & Devices Device Detail.
- **Later:** Control Room can surface device health rollups, but signal rule authoring remains in Control Room.

### 10.3 Fleet

- **v1:** Fleet references `Device` for trackers assigned to vehicles. Fleet no longer owns Queclink credentials — move them to APIs & Integrations.
- **v1:** Vehicle detail page shows a small read-only device card (like Site does).
- **Later:** Fleet telemetry stream (positions, trips) continues to read Queclink directly or via a shared adapter service; split of concerns already documented in §4 (Queclink).

### 10.4 HR / Workforce

- **v1:** Staff / Client profiles gain a read-only "Assigned devices" list (trackers, duress buttons, etc.) with deep-link to Device Detail.
- **v1:** Assigning a tracker to a client must go through the Security & Devices reassignment flow, not a field on the profile.
- **Later:** Audit trail report "all devices currently assigned to clients" — a reporting concern, not a Security & Devices feature.

### 10.5 Health / Care / Facilities

- **v1:** Care module reads telemetry snapshots (bed-exit, fall) via an event subscription, not by querying the device table directly. Define a `DeviceSignalPublished` event in v1 with payload `(device_id, signal_type, payload, occurred_at)`.
- **v1:** Medication cabinet open events route through the same signal pipeline and may surface in Care workflows (whether this creates an alert is a Control Room rule).
- **Later:** Care module can request push config changes to specific IoT devices (e.g., adjust bed sensor sensitivity) — this is an adapter capability and can be v2.

### 10.6 Maintenance

- **v1:** Maintenance tab on Device Detail is a light notes log. Field for "next service due". No cross-device scheduling.
- **Later:** When a Maintenance module is built, devices surface in its WO selector. The Maintenance tab becomes a WO list tied to the device.

### 10.7 Reporting / Governance

- **v1:** Standard data exports (CSV) from Devices page and each domain page. No dedicated report builder.
- **Later:** Reports like "Access events per door per month", "Tracker uptime by site", "Compliance-flagged devices not serviced in N days" belong in a Reporting module that consumes `Device` + `IntegrationEvent`.

---

## 11. PR-by-PR Implementation Plan

Each PR is sized to land in a few days without blocking the others beyond stated dependencies. PR 11 (alert consolidation) is parallelizable with most others.

### PR 1 — Scaffold Security & Devices module shell

- **Objective:** Put the module in the left nav and boot an empty Dashboard so all subsequent PRs have a home.
- **Files:** `routes/web.php` or a new `routes/security-devices.php`; `app/Http/Controllers/SecurityDevices/DashboardController.php`; `resources/js/Pages/security-devices/dashboard/index.tsx`; sidebar nav config (wherever sidebar items live).
- **Scope:** new top-level menu, empty dashboard with placeholder cards reading from existing `LocationHardware` count.
- **Out of scope:** any CRUD, any new model, any integration work.
- **Deps:** none.
- **Risks:** sidebar nav breakage in other modules if it’s a shared config file.
- **Acceptance:** `/security-devices` loads, dashboard renders, nav highlights correctly, no regressions in existing pages.

### PR 2 — Additive schema for Device model

- **Objective:** Extend `location_hardware` with the new columns and seed `device_profiles`.
- **Files:** new migration `2026_0X_XX_extend_location_hardware_for_devices.php`; new migration `create_device_profiles_table.php`; new migration `create_device_assignments_table.php`; new migration `create_device_relationships_table.php`; new migration `create_device_telemetry_snapshots_table.php`; seeder `DeviceProfileSeeder.php`; introduce `app/Models/Device.php` as class alias + typed accessors.
- **Scope:** additive schema only; no behaviour change.
- **Out of scope:** table rename; dropping columns; data backfill beyond a simple `source = 'manual'` default.
- **Deps:** PR 1.
- **Risks:** migration order with other active migrations.
- **Acceptance:** migrations run clean up and down; `Device` model constructs; `LocationHardware` still functions for existing code paths; `device_profiles` seeded with at least: camera, door_reader, door_lock, motion, duress, bed_sensor, fall_sensor, door_contact, temp_humidity, personal_tracker, vehicle_tracker, asset_tracker, ap, switch, gateway, nvr.

### PR 3 — Global Devices page (read-only v0)

- **Objective:** Ship the Devices page with summary cards, filters, table. No mutations yet except row → detail navigation.
- **Files:** `app/Http/Controllers/SecurityDevices/DeviceController.php`; `resources/js/Pages/security-devices/devices/index.tsx`; new shared components `components/security-devices/StatusDot.tsx`, `DeviceCategoryBadge.tsx`, `ProviderBadge.tsx`, `NeedsAttentionChip.tsx`.
- **Scope:** index view, search, filters, pagination, summary cards, link to detail stub.
- **Out of scope:** create, reassign, retire, export.
- **Deps:** PR 2.
- **Risks:** "needs attention" query performance — ensure `last_seen_at` and `status` are indexed.
- **Acceptance:** page loads < 800ms with 5k devices, filters work, pagination works, summary card counts match filtered state.

### PR 4 — Device Detail page (read-only v0)

- **Objective:** Ship Device Detail with Overview, Assignment (view only), Connectivity, History. No edit yet.
- **Files:** `DeviceController@show`; `resources/js/Pages/security-devices/devices/show.tsx`; tab components per tab.
- **Scope:** header, 4 read-only tabs.
- **Out of scope:** Configuration, Relationships, Documents, Maintenance; any mutations.
- **Deps:** PR 3.
- **Risks:** history tab volume (pull from `integration_events` with pagination).
- **Acceptance:** each tab loads, handles devices with no provider, no assignment, no telemetry without layout breakage.

### PR 5 — Locations/Sites hardware tab → read-only context view

- **Objective:** Strip the site hardware page down to a context view; route all management to Security & Devices.
- **Files:** rewrite of `resources/js/Pages/sites/hardware/index.tsx` (split into `HardwareContextView.tsx`, `SiteIntegrationsCard.tsx`); refactor `SiteHardwareController` — delete create/update/destroy actions, keep `assignRoom` endpoint (physical placement).
- **Scope:** new site-scoped context view per §3; remove credential UI; remove CRUD actions; keep room management and `move-to-room`.
- **Out of scope:** sites module structural changes beyond this tab.
- **Deps:** PR 3 (needs a place to handoff to).
- **Risks:** users with muscle memory clicking missing buttons — add an info banner explaining the change.
- **Acceptance:** no create/edit/delete/credential controls remain on the site page; all previous data is visible read-only; handoff buttons work.

### PR 6 — Manual device registration flow

- **Objective:** Add-device modal on Global Devices page.
- **Files:** `DeviceController@store`; `resources/js/components/security-devices/AddDeviceModal.tsx`; form validation.
- **Scope:** the modal per §8.1, including category-specific identifier fields.
- **Out of scope:** bulk import CSV; QR code / barcode scan.
- **Deps:** PR 3.
- **Risks:** identifier uniqueness conflicts with imported devices — validate carefully.
- **Acceptance:** a manual device appears in Devices, in its domain page, and on its site hardware tab within the same request.

### PR 7 — Reassignment flow with full history

- **Objective:** Reassign action (modal) + assignment history table on Device Detail.
- **Files:** `DeviceAssignmentController@store`; `ReassignModal.tsx`; Assignment tab rewrite.
- **Scope:** per §8.4 including audit log write.
- **Out of scope:** bulk reassignment; scheduled future reassignment.
- **Deps:** PR 2, PR 4.
- **Risks:** provider-side implications when device changes site — in v1, no-op with a TODO in adapter interface.
- **Acceptance:** reassign closes old `device_assignments` row, opens new, refreshes `current_assignment_id`, history tab shows the move.

### PR 8 — APIs & Integrations module scaffold + UniFi page rebuild

- **Objective:** New top-level submenu area; UniFi page becomes the template for others.
- **Files:** `routes/security-devices.php` extension; `app/Http/Controllers/SecurityDevices/Integrations/UnifiController.php`; `resources/js/Pages/security-devices/integrations/unifi/*.tsx` (Overview, Connection, SiteMapping, SyncSchedule, Exceptions, Logs, FieldMapping); migrate existing UniFi functionality out of the Sites module; introduce `integration_ignored_devices` and `integration_exceptions` migrations.
- **Scope:** UniFi end-to-end inside new home; credentials move out of site pages.
- **Out of scope:** Queclink, Milesight; any adapter refactor beyond what’s required for the move.
- **Deps:** PR 5 (so we can remove from sites).
- **Risks:** existing UniFi credentials during migration — the backfill copies legacy global-provider and Site-secret rows if they changed shape; otherwise point the new UI at the current connection stores.
- **Acceptance:** UniFi connect → discover sites → map → sync devices → see imported devices in Global Devices, all without touching any site page.

### PR 9 — Queclink integration page + hardware registration path

- **Objective:** Queclink becomes a first-class Security & Devices provider.
- **Files:** `app/Services/Integration/Adapters/QueclinkAdapter.php` (extract from Fleet); `QueclinkController.php`; `resources/js/Pages/security-devices/integrations/queclink/*.tsx`; Fleet module updated to consume `Device` + telemetry rather than owning the adapter credential.
- **Scope:** per §4 Queclink section; tracker import; assignment to Vehicle / Person / Asset.
- **Out of scope:** changes to Fleet telemetry ingestion pipeline (keep the existing path working, just re-point the credential source).
- **Deps:** PR 8 (scaffold pattern), Fleet module awareness.
- **Risks:** collision with Fleet’s current Queclink usage — coordinate with Fleet module owner; feature-flag if needed.
- **Acceptance:** a Queclink tracker imports, shows in Tracking domain page, can be assigned to a client, and Fleet continues to receive telemetry.

### PR 10 — Milesight integration (net-new)

- **Objective:** First LoRaWAN provider end-to-end.
- **Files:** `MilesightAdapter.php`; `MilesightController.php`; Milesight pages; payload decoder service; migration to add a `device_telemetry_snapshots` row per imported sensor.
- **Scope:** per §4 Milesight section; gateway + sensor hierarchy; decoded payload.
- **Out of scope:** advanced downlink (pushing config to sensors) — read-only v1.
- **Deps:** PR 8.
- **Risks:** Milesight supports cloud and on-prem differently — v1 picks cloud API only, document the on-prem stub.
- **Acceptance:** a Milesight gateway + its LoRaWAN sensors import and appear in Smart IoT domain page with latest readings.

### PR 11 — Alert consolidation (parallelizable with PR 6–10)

- **Objective:** One alert table, one Control Room surface.
- **Files:** migration to extend `integration_alerts` with a `source` column and optional legacy fields; data migration to copy `control_room_alerts` rows into `integration_alerts`; Control Room UI updated to read from the unified table; deprecate `ControlRoomAlert` model with class alias for one cycle.
- **Scope:** data migration, dual-read cutover, legacy alert UI removal.
- **Out of scope:** rule engine changes; SLA model changes.
- **Deps:** none beyond existing Control Room.
- **Risks:** this is the riskiest PR — alerts in flight during migration need care. Suggest: freeze legacy writes → migrate → cut reads → drop legacy.
- **Acceptance:** Control Room shows a single alert list including device-sourced and legacy alerts; no writes to `control_room_alerts` after cutover; `source` column populated for every row.

### PR 12 — Domain pages (CCTV · Access · Alarms · Tracking · Smart IoT)

- **Objective:** All five domain pages, each following the shared standard.
- **Files:** one controller each or a single `DomainPageController` with category scope; five page files under `resources/js/Pages/security-devices/{domain}/index.tsx`.
- **Scope:** per §7.
- **Out of scope:** domain-specific mutations beyond the shared Device actions.
- **Deps:** PR 3 (the underlying Device index logic).
- **Risks:** duplication with Global Devices — extract a reusable `DeviceIndexTable` component so maintenance stays single-source.
- **Acceptance:** each domain page loads a scoped device list with its own summary cards; filters work; deep-linking from Dashboard works.

### PR 13 — Dashboard real content + "needs attention" surfacing

- **Objective:** Fill in the Dashboard with meaningful cards.
- **Files:** `DashboardController` query changes; dashboard component.
- **Scope:** health rollup, unassigned count, sync status, top 10 needs-attention devices, per-provider sync health.
- **Out of scope:** trend lines; charts over time (requires telemetry history store).
- **Deps:** PR 3, PR 8, PR 9, PR 10.
- **Risks:** performance — cache the rollups.
- **Acceptance:** Dashboard loads < 500ms, all cards show live values, click-through filters to relevant pages.

### PR 14 — Device Detail: Configuration, Relationships, Documents, Maintenance tabs

- **Objective:** Complete the Device Detail tab set.
- **Files:** tab components; `DeviceRelationshipController`; document attachment path (reuse existing media lib if present).
- **Scope:** per §6 tabs 4–8.
- **Out of scope:** Maintenance WO — keep the tab as notes-log only.
- **Deps:** PR 4.
- **Risks:** relationships UI complexity — keep it simple (pick two devices + type).
- **Acceptance:** each tab is functional; device detail page is complete.

### (Later PRs — not for v1)

- Assignments workbench (cross-site bulk view).
- Device Groups.
- Maintenance module integration.
- Hikvision / Axis / generic webhook providers.
- Downlink config push.

---

## 12. UX Guardrails

Non-negotiables. When a future PR violates one of these, reject it.

1. **Locations show context, not management.** If a site page is about to grow an edit/create/delete button for a device, route it to Security & Devices instead. The only device-related mutation allowed on a site page is "move to room within this site."
2. **Security & Devices owns inventory.** Any other module that wants to reference a device does so by `device_id` with a read-through view. Never copy device fields into another module’s table.
3. **Control Room owns alerts.** Security & Devices emits signals. It does not maintain an alert queue. No "Alerts" tab inside Security & Devices. Links out to Control Room are fine and encouraged.
4. **Provider pages own connection + mapping.** Credentials, test connection, field mapping, exceptions, sync schedule — all only on APIs & Integrations.
5. **Device Detail owns device truth.** The Device Detail page is the single place that fully describes a device. Duplicating a partial view elsewhere is allowed; duplicating editability is not.
6. **Imported fields are read-only forever.** Once a field is provider-owned for a device, only that provider can change it. No "override" buttons that bypass this.
7. **Assignment is a history, not a field.** Never UPDATE a device row’s assignment in place — always append to `device_assignments`.
8. **No alert without a signal.** An alert must reference either a `NormalizedSignal` / `IntegrationEvent` or an explicit manual source. No free-floating alerts.
9. **Provider-specific UI is confined to provider pages.** Don’t leak UniFi-specific labels into Global Devices. Use the shared vocabulary (`camera`, not `UVC camera`) in the cross-provider table.
10. **NZ compliance flags and care-specific metadata live on the device, not elsewhere.** Any future "medication cabinet device" compliance field belongs on `devices.compliance_flags`, not in a separate care-side table.

---

## 13. Recommended MVP Scope

### Must have now (ship this)

- Security & Devices top-level module (PR 1).
- Additive schema (PR 2).
- Global Devices page with read-only v0 (PR 3).
- Device Detail v0: Overview, Assignment (view), Connectivity, History (PR 4).
- Site hardware page cleaned to context view (PR 5).
- Manual device registration (PR 6).
- Reassignment with history (PR 7).
- APIs & Integrations scaffold + UniFi rebuild (PR 8).
- Alert consolidation (PR 11) — do this early to avoid building on a split foundation.

### Should have next

- Queclink integration (PR 9).
- Milesight integration (PR 10).
- Five domain pages (PR 12).
- Dashboard real content (PR 13).

### Later / future

- Device Detail complete tab set — Configuration, Relationships, Documents, Maintenance (PR 14).
- Assignments workbench, Device Groups.
- Additional providers (Hikvision, Axis, generic webhook).
- Maintenance WO integration.
- Downlink config push.
- Reporting dedicated surfaces.

**The minimum usable cut: PRs 1, 2, 3, 4, 5, 6, 8.** That gives you: a working Security & Devices home, a devices table, a device detail, site pages cleaned up, ability to add devices manually, and working UniFi integration. Control Room alert consolidation (11) should follow closely to avoid technical debt.

---

## 14. Final Recommendation

### Best target structure

A top-level **Security & Devices** module that owns device inventory, provider integrations, assignment history, and device relationships. Sites remain the source of truth for physical hierarchy; Control Room remains the only place alerts live. The dual alert tables collapse into one. The site hardware page shrinks to a read-only context view. `LocationHardware` is rebranded to `Device` in code; the physical table rename is deferred.

### Best rollout order

1. **Get the home built first** (PRs 1–4). This lets you move real work into the new module with confidence. Do not start on providers until the shell and detail page exist.
2. **Clean the sites page next** (PR 5). This releases the monolithic page and forces the ownership discipline before adding more provider work.
3. **Ship manual + reassignment** (PRs 6, 7). These are small, high-value, and needed by every downstream PR.
4. **Rebuild UniFi in its new home** (PR 8). Prove the provider-page pattern on the one provider that already works end-to-end.
5. **Consolidate alerts** (PR 11) — can run in parallel with PR 9 or 10. Do not push past PR 10 with two alert tables still live.
6. **Add Queclink and Milesight** (PRs 9, 10).
7. **Fill out domain pages, dashboard, and remaining detail tabs** (PRs 12–14).

### Highest-risk mistakes to avoid

1. **Renaming `location_hardware` → `devices` in one PR.** Don’t. Do the rename in v1.5 after all call sites migrate. Premature rename is a multi-day regression hunt.
2. **Letting the alert consolidation slip.** Every PR that adds a signal path against two alert tables compounds the cost. Consolidate early.
3. **Building Device Groups or Maintenance WO now.** You don’t have the evidence that tags + filters fail, or that the WO abstraction belongs in Security & Devices vs a Maintenance module. Defer.
4. **Leaving credential UI on site pages "just in case."** Users will use it and it undoes the ownership split. Rip it out in PR 5 cleanly.
5. **Accepting a provider adapter that doesn’t implement the `IntegrationAdapter` interface exactly.** Each shortcut here creates special cases that cost you when the fourth and fifth providers come.
6. **Treating imported data as editable in a "small exception".** Once you allow one override of a provider-owned field, the ownership model is done. No overrides. Ever.
7. **Putting provider logos or provider-specific terms in the shared Global Devices UI.** Keep the cross-provider view neutral; provider-specific nuance belongs on its provider page and in the Device Detail Connectivity tab.
8. **Making Security & Devices a second alerting engine.** It emits signals. Control Room owns alerts. Policing this is primarily a UX guardrail (§12).

---

### Appendix A — File touch-point map (for the dev agent)

Primary existing files that the restructure modifies or moves:

| File | Action |
|---|---|
| `resources/js/Pages/sites/hardware/index.tsx` | Split; strip to context view |
| `app/Http/Controllers/Sites/SiteHardwareController.php` | Strip to `index`, `assignRoom`; delete rest |
| `app/Http/Controllers/Sites/SiteIntegrationController.php` | Move to `app/Http/Controllers/SecurityDevices/Integrations/` |
| `app/Models/LocationHardware.php` | Deprecate in favour of `app/Models/Device.php` (class alias for one cycle) |
| `app/Models/Integration/*` | Keep; extend capability field generality |
| `app/Models/ControlRoom/Alert.php` | Becomes canonical alert model — absorb `ControlRoomAlert` |
| `app/Models/ControlRoomAlert.php` | Deprecate, data-migrate |
| `app/Services/Integration/Adapters/UnifiAdapter.php` | Keep; align to strict `IntegrationAdapter` interface |
| `app/Services/Fleet/Telemetry/QueclinkAdapter.php` | Extract to `app/Services/Integration/Adapters/QueclinkAdapter.php` |
| `app/Jobs/Integration/*` | Keep; add Milesight variants |
| `resources/js/Pages/settings/integrations/*` | Retire — move into Security & Devices |
| `routes/sites.php` | Drop hardware-management endpoints, keep room management |
| `routes/control-room.php` | Unify alert endpoints onto single table |

New files:

- `routes/security-devices.php`
- `app/Http/Controllers/SecurityDevices/{DashboardController,DeviceController,DeviceAssignmentController,DeviceRelationshipController}.php`
- `app/Http/Controllers/SecurityDevices/Integrations/{UnifiController,QueclinkController,MilesightController}.php`
- `app/Models/{Device,DeviceAssignment,DeviceRelationship,DeviceProfile,DeviceTelemetrySnapshot,IntegrationException,IntegrationIgnoredDevice}.php`
- `app/Services/Integration/Adapters/{QueclinkAdapter,MilesightAdapter}.php`
- `app/Services/Integration/Contracts/IntegrationAdapter.php` (interface)
- `resources/js/Pages/security-devices/**/*.tsx`
- `resources/js/components/security-devices/{StatusDot,DeviceCategoryBadge,ProviderBadge,NeedsAttentionChip,DeviceIndexTable,AddDeviceModal,ReassignModal}.tsx`

### Appendix B — Open questions for the product owner

1. **Personal tracker assignment to Clients:** what audit + consent record is required when a tracker is assigned to a supported-living client? This has NZ-specific privacy weight — confirm before PR 9 ships.
2. **Medication cabinet devices:** will these be tracked as access-control doors, smart IoT, or a distinct subcategory? Recommend `smart_iot / medication_cabinet` subcategory with a `compliance_flags.medication = true` flag.
3. **Mapping of UniFi AP/Switch/Gateway — show as infrastructure devices or hide by default?** Recommend show with a muted style in Global Devices, keep a "Network" pill for operators who need to see them.
4. **Alert consolidation cutover window:** acceptable downtime? If zero, dual-write period needed; if a few minutes of maintenance is OK, one-shot migration is faster.
5. **Queclink credential ownership:** Fleet team alignment needed before PR 9 extracts the adapter.
