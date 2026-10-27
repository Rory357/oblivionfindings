# Security & Devices — Delivery Progress

## Phase 0 — Clean-up, honesty, readiness, and simple navigation

### Summary of changes

1. **Test fixture purge command (`0.1`)**
   - Added `php artisan security-devices:purge-test-fixtures` (`--dry-run` / `--force`) in `app/Console/Commands/PurgeSecurityDevicesTestFixtures.php`.
   - Safely removes release acceptance fixture packs, standalone test/demo devices, and test-only Sites inside a database transaction while unlinking immutable `device_relationships` rows and preserving real devices (`UX7`, `Care tracker 867963069916998`, `unif`).
   - Verified `scripts/deploy-server.sh` and `.github/workflows/*` contain **no** deploy step that re-creates Security & Devices test fixtures.

2. **Honest health & monitoring status (`0.2`)**
   - Unmonitored devices now always report `not_monitored` (`Not monitored`) instead of `healthy` across list pages, workspaces, maintenance health summaries, and device profiles unless active check evidence exists.
   - Added `not_monitored` to `resources/js/lib/status-colors.ts` and `resources/js/lib/security-devices-labels.ts`.

3. **Honest CCTV live source status (`0.3`)**
   - Fixed the regression from `80993a992` in `SecurityWorkspacePresenter.php` so Hikvision/CCTV cameras only display `Live source verified` when `stream_state === 'verified'` and `stream_verified_at !== null`. Configured but unverified streams now show `Source configured (not verified)`.

4. **Windows ICMP ping accuracy (`0.4`)**
   - Rewrote `NativeIcmpTransport.php` to require a genuine echo reply line from the target IP address with parsed latency (`time=Xms` / `time<1ms`).
   - Rejects Windows `ping.exe` exit-code-`0` false positives (`Reply from <local-ip>: Destination host unreachable.`, `TTL expired in transit.`, or replies from a router/gateway IP rather than the target).

5. **Hide fake network configuration backup (`0.5`)**
   - Removed `backup_config` from `DeclaredDeviceCommandCapabilities.php` and failed closed in `NetworkDeviceCommandAdapter.php` until real vendor SSH configuration drivers land in Phase 5.

6. **Encrypted database credential store by default (`0.6`)**
   - Changed `MONITORING_CREDENTIAL_DRIVER` default in `config/monitoring.php` from `'unavailable'` to `'database'` (keeping HashiCorp Vault optional via `MONITORING_CREDENTIAL_DRIVER=vault`).
   - Added `monitoring_encrypted_secrets` migration (`2026_10_27_000001_create_monitoring_encrypted_secrets_table.php`), `MonitoringEncryptedSecret`, `EncryptedDatabaseSecretStore`, and `EncryptedDatabaseSecretLeaseIssuer` using `Encrypter` + HMAC-SHA256 reference fingerprints and `sodium_memzero` memory scrubbing.
   - Verified via feature tests that secret values never appear in Inertia responses, application logs, or audit log rows.

7. **Settings › Server readiness (`0.7`)**
   - Added `Settings › Server readiness` (`/settings/server-readiness`) via `ServerReadinessService`, `ServerReadinessController`, `system:scheduler-heartbeat` + `RecordDefaultWorkerHeartbeatJob` (every minute in `routes/console.php`), and `resources/js/pages/settings/server-readiness.tsx`.
   - Evaluates **Redis**, **Scheduler**, **Monitoring workers**, **Endpoint workers**, **Ping permission** (live loopback probe), **Collector keys**, **Release signing key**, and **Credential store**, showing `<StatusBadge>` green/red states with plain-English fix steps.

8. **Simple and easy navigation & plain English (`Item 7` inside Phase 0)**
   - Consolidated Security & Devices sidebar navigation to five entries: **Overview**, **Devices**, **Sites**, **Alerts & upkeep**, and **Setup**, with section tabs (`SecurityDevicesSectionTabs`) on grouped destinations.
   - Unified the **Devices** list (`/security-devices/devices`) with category/type filters and hid empty profile tabs.
   - Added a **First-run setup** checklist card on **Overview** (`/security-devices`).
   - Replaced internal jargon across Security & Devices UI copy (including simplifying `Records & governance` to `History & files`), fixed WCAG AA text contrast on the `DeviceProfileHeader` Event Horizon sky (`text-band-foreground/75`), and enforced `security-devices-copy/no-jargon` in `eslint.config.js` (blocking `canonical`, `governed`, `provenance`, `evidence-backed`, and `projection`).

---

### Live `.com` fixture purge (`0.1` — executed 28 Oct 2026 NZDT)

- **Pre-deploy & pre-purge MySQL backups verified on `oblivionfindings.com` (stored outside `/var/www/oblivionfindings`):**
  - `/home/oblivion/db-backups/oblivionfindings-pre-phase0-20261027T133359Z.sql.gz` (`43 MB`, `0600`, `gzip -t` OK)
  - `/home/oblivion/db-backups/oblivionfindings-pre-fixture-purge-20261027T134455Z.sql.gz` (`43 MB`, `0600`, `gzip -t` OK)
- **Live execution result (`php artisan security-devices:purge-test-fixtures --force` at `254675f68`):**
  - `Purge complete: 1 relationships unlinked, 19 devices hard-deleted, 3 devices soft-deleted (history retained), 5 Sites removed, 2 fixture packs removed.`
  - Post-purge `--dry-run`: `Release fixture packs (0)`, `Test fixture devices to remove (0)`, `Test Sites to remove (0)`, `Real devices preserved (3)` (`1` `UX7`, `2` `Care tracker 867963069916998`, `95` `unif`).
  - `php artisan database:verify-lifecycle-triggers postflight --json` → `"status": "verified"`, `16/16` triggers intact, `0` errors.
  - `git -C /var/www/oblivionfindings status --porcelain` → empty.

#### Release fixture packs removed (2)
| ID | Pack key | State | Manifest records |
| --- | --- | --- | --- |
| 8 | `it-security-desktop-release-v1` | `ready` | 76 |
| 9 | `it-security-desktop-release-v10` | `ready` | 76 |

#### Test fixture devices to remove (22)
| ID | UID | Name | Provider |
| --- | --- | --- | --- |
| 3 | `CCT-BFTN2GEX` | `UI Alignment Test Device` | `—` |
| 74 | `NET-WYWZ4RYS` | `RELEASE Alpha Gateway` | `manual` |
| 75 | `NET-VMEVKDVT` | `RELEASE Alpha Switch` | `manual` |
| 76 | `ACC-FAQFBGIX` | `RELEASE Alpha Door` | `release_fixture` |
| 77 | `ACC-PIUPXJ30` | `RELEASE Alpha Door Secondary` | `release_fixture` |
| 78 | `CCT-K7ABPD3E` | `RELEASE Alpha Camera` | `manual` |
| 79 | `FAL-EYW5NMIL` | `RELEASE Alpha Healthcare` | `manual` |
| 80 | `PER-I5RDFDHZ` | `RELEASE Alpha Personal Tracker` | `manual` |
| 81 | `VEH-8YCBYIES` | `RELEASE Alpha Fleet Tracker` | `manual` |
| 82 | `COL-0IBBBC8B` | `RELEASE Alpha Environment Sensor` | `manual` |
| 83 | `END-GBSAB6F4` | `RELEASE Hidden Device` | `manual` |
| 84 | `NET-XIZM77EC` | `RELEASE V10 Alpha Gateway` | `manual` |
| 85 | `NET-PWIMHRTE` | `RELEASE V10 Alpha Switch` | `manual` |
| 86 | `ACC-CHIGXHEL` | `RELEASE V10 Alpha Door` | `release_fixture` |
| 87 | `ACC-MRFXBJZM` | `RELEASE V10 Alpha Door Secondary` | `release_fixture` |
| 88 | `CCT-ZZQYLDE0` | `RELEASE V10 Alpha Camera` | `manual` |
| 89 | `FAL-MI4IREBL` | `RELEASE V10 Alpha Healthcare` | `manual` |
| 90 | `PER-NOVRY8AB` | `RELEASE V10 Alpha Personal Tracker` | `manual` |
| 91 | `VEH-SLSNLYKI` | `RELEASE V10 Alpha Fleet Tracker` | `manual` |
| 92 | `COL-JZGDXYMW` | `RELEASE V10 Alpha Environment Sensor` | `manual` |
| 93 | `END-VHL8CHRK` | `RELEASE V10 Hidden Device` | `manual` |
| 94 | `DEMO-LOCATION-20260922` | `Demo Location Tracker (simulated)` | `manual` |

#### Test Sites to remove (5)
| ID | Name |
| --- | --- |
| 9416 | `RELEASE Site Alpha` |
| 9417 | `RELEASE Site Hidden` |
| 9422 | `RELEASE V10 Site Alpha` |
| 9423 | `RELEASE V10 Site Hidden` |
| 9424 | `Demo Location Site` |

#### Real devices preserved (3)
| ID | UID | Name | Provider |
| --- | --- | --- | --- |
| 1 | `NET-S9OZVHUU` | `UX7` | `—` |
| 2 | `867963069916998` | `Care tracker 867963069916998` | `queclink` |
| 95 | `NET-QQKSSO9D` | `unif` | `unifi` |

---

### Verification & local walkthrough

- **Laravel Pint:** 0 issues across all 37 touched/new PHP files.
- **TypeScript (`tsc --noEmit`):** 0 errors across the repository.
- **ESLint (`--max-warnings=0`):** 0 errors and 0 warnings across all Security & Devices, Settings, sidebar, e2e, and `eslint.config.js` files (including `security-devices-copy/no-jargon`).
- **Vitest:** 99 test files / 986 tests passed.
- **Pest:**
  - Architecture & Unit (`tests/Architecture`, `tests/Unit/Monitoring`, `tests/Unit/SecurityDevices`): 943 passed (25,236 assertions) in touched scope.
  - Feature (`ServerReadinessTest`, `EncryptedDatabaseCredentialStoreTest`, `PurgeSecurityDevicesTestFixturesTest`, `SecurityWorkspaceTest`, `NetworkDevicePortAndBackupCommandsTest`, `DeviceControllerTest`, `DeviceProfileWorkspaceTest`, `MaintenanceHealthControllerTest`, `DashboardControllerTest`, `SecurityDevicesNavigationRoutesTest`, etc.): 236 passed (>3,100 assertions).
- **Home network Windows ICMP check (`172.16.0.0/24`):**
  - `127.0.0.1` → `reachable: true`, `latencyMs: 0`, `reasonCode: "reply"`
  - `172.16.0.93` (local PC) → `reachable: true`, `latencyMs: 0`, `reasonCode: "reply"`
  - `172.16.0.1` (home gateway) → `reachable: true`, `latencyMs: 0`, `reasonCode: "reply"`
  - `172.16.0.253` (unassigned LAN IP where Windows `ping.exe` exited `0` with `Reply from 172.16.0.93: Destination host unreachable.`) → `reachable: false`, `latencyMs: null`, `packetLossPercent: 100`, `reasonCode: "unreachable"`

#### Local walkthrough screenshots
- **Overview (5 sidebar links + First-run setup checklist):**
  ![Local Overview](screenshots/phase-0-local-overview.png)
- **Devices list (`Not monitored` shown instead of `Healthy` without active checks):**
  ![Local Devices](screenshots/phase-0-local-devices.png)
- **CCTV source honesty (`Source configured (not verified)` instead of `Live source verified`):**
  ![Local CCTV Honesty](screenshots/phase-0-local-cctv-honesty.png)
- **Network device profile (fake configuration backup hidden):**
  ![Local Device Profile](screenshots/phase-0-local-device-profile.png)
- **Settings › Server readiness:**
  ![Local Server Readiness](screenshots/phase-0-local-server-readiness.png)

#### Live `oblivionfindings.com` walkthrough (`254675f68` — post-purge)
- **Live Server readiness (`5` of `7` ready, `2` honestly flagged for Phase 2 keys):**
  - `Redis`: **Ready** (`Connected and responding`)
  - `Scheduler`: **Ready** (`Running every minute`)
  - `Worker pools`: **Ready** (`9 of 9 pools running`)
  - `Ping permission`: **Ready** (`Allowed · Local ping check succeeded`)
  - `Credential store`: **Ready** (`Encrypted database store ready`)
  - `Collector keys`: **Needs fix** (`Missing or unreadable: site hub configuration signing key, site hub certificate authority files` — scheduled for Phase 2 with server owner approval)
  - `Release key`: **Needs fix** (`No valid 32-byte Ed25519 publisher public key is configured` — scheduled for Phase 2)
- **Overview (`3` real devices, `0%` monitoring coverage, `First-run setup` `2 of 4 done`):**
  ![Live Overview](screenshots/phase-0-live-overview.png)
- **Devices list (`Care tracker 867963069916998`, `unif`, `UX7` — all 22 test devices removed, `Not monitored` shown honestly):**
  ![Live Devices](screenshots/phase-0-live-devices.png)
- **Device profile (`UX7` — `Not monitored`, `History & files` plain tab, fake configuration backup hidden):**
  ![Live Device Profile](screenshots/phase-0-live-device-profile.png)
- **Alerts & upkeep (`/security-devices/monitoring` — consolidated section tabs, `0` fixture checks remaining):**
  ![Live Alerts & Upkeep](screenshots/phase-0-live-alerts-upkeep.png)
- **Settings › Server readiness (live `oblivionfindings.com` checks):**
  ![Live Server Readiness](screenshots/phase-0-live-server-readiness.png)

---

## Phase 1 — The basics

### Summary of changes

1. **Edit device (`1.1`) & Register device (`1.2`)**
   - Updated `AddDeviceDialog` / `EditDeviceDialog` (`resources/js/components/security-devices/add-device-dialog.tsx`) and `DeviceController` (`app/Domain/SecurityDevices/Http/Controllers/DeviceController.php`) so both registration and editing expose a searchable Site combobox (`Popover` + `Command`) and **IP address or hostname** on Step 1 (**Classification**), alongside name, workspace/category/type, manufacturer/model, and notes.
   - `DeviceController::store` and `DeviceController::update` accept IPv4, IPv6, or RFC-1123 hostnames (`ipOrHostnameRules()`) and atomically assign, transfer, or release Site assignments (`DeviceAssignmentService`) while guarding non-Site person/room/vehicle assignments.

2. **`Ping now` on device profiles (`1.3`)**
   - Added `POST /security-devices/devices/{device}/ping` (`DevicePingController`) backed by `DnsResolver`, `EgressPolicy` (`deny_cidrs` SSRF protection preserved), and `IcmpTransport` (`NativeIcmpTransport`).
   - Added the **Connection test** card on `Status › Health` (`resources/js/pages/security-devices/devices/device-profile-sections.tsx`) showing live reply time (`< 1 ms`, `X ms`) or a plain-English failure reason (`missing_address`, `dns_failed`, `address_blocked`, `unreachable`, `timeout`, `permission_denied`).

3. **One-switch `"Alert me if this goes offline"` & seeded default profile (`1.4`)**
   - Added migration `2026_10_28_000001_seed_default_availability_profile.php` (`MonitoringProfile::DEFAULT_AVAILABILITY_NAME = 'Standard availability'`: 60s interval, 3 failure confirmations, 2 recovery confirmations, 300s stale threshold).
   - Added `POST /security-devices/devices/{device}/offline-alert` (`DeviceOfflineAlertController`) and the one-switch **Alert me if this goes offline** card on device profiles, auto-provisioning or toggling the Site's `'Registered device hosts'` scope (`/32` or `/128` host CIDR), `'Standard availability'` profile, and ICMP availability check.

4. **Always-visible `"Add check"` with plain-English missing-prerequisite guidance (`1.5`)**
   - Updated `DeviceProfilePresenter`, `MonitoringOperationsPresenter`, `device-profile-sections.tsx`, `monitoring.tsx`, and `native-monitor-dialogs.tsx` so **Add check** is always visible on device profiles and **Alerts & upkeep**, disabling cleanly with a plain-English explanation and shortcut button when a Site assignment, monitoring profile, or eligible device is missing.

5. **Discovery candidate review actions (`1.6`)**
   - Wired `DiscoveryCandidateService` (`adopt`, `sameAs`, `ignore`, `adoptAll`) to `DiscoveryCandidateReviewController`, `DiscoveryOperationsPresenter`, `discovery-candidate-dialogs.tsx`, and `discovery.tsx` (`Setup › Discovery & hubs › Candidates`).
   - Supports **Add as device**, **Same as…** (searchable Site device combobox that fills missing IP/MAC/hostname on the existing device), **Ignore**, and **Add all** (`ConfirmDialog`), normalising 12-hex MACs to `AA:BB:CC:11:22:33` without leaking raw `evidence_snapshot` payloads.

6. **Queclink unit console (`1.7`)**
   - Added `QueclinkUnitConsoleService`, `QueclinkUnitConsoleController`, `CommandBuilder::watchdogReboot` (`AT+GTDOG`), `queclink-unit-console.tsx`, and `docs/security-devices/SUPPORTED-DEVICES.md` citing the official *Queclink GL30MEU @Track Air Interface Protocol Specification* (`GL30MEUR01`, Rev. 2.04) and *GL320MG User Manual* (`GL320MGUM001`).
   - Renders on **Technology › Unit console** for Queclink trackers and via direct console links on `/security-devices/integrations/queclink`.
   - Masks IMEIs (`8679•••••••6998`), scrubs command passwords (`[REDACTED]`), enforces `PersonalTrackingPrivacyService` + `device_assignment_id` historical frame privacy so prior unconsented resident coordinates never leak after reassignment, and separates read-only queries (`Request current location`, `Read configuration`, `Request device status`) from confirmation-and-audit-reason gated changes (`Update reporting interval`, `Update scheduled watchdog`, `Reboot tracker`).

---

### Verification & local walkthrough

- **Laravel Pint:** 0 issues across all touched/new PHP files.
- **TypeScript (`tsc --noEmit`):** 0 errors across the repository.
- **ESLint (`--max-warnings=0`):** 0 errors and 0 warnings across all 16 touched/new TS/TSX files (including `security-devices-copy/no-jargon`).
- **Vitest (`--maxWorkers=2`):** 4 test files / 55 tests passed (`add-device-dialog.test.tsx`, `queclink-unit-console.test.tsx`, `monitoring.test.tsx`, `discovery.test.tsx`).
- **Pest:**
  - Architecture (`ItSecuritySingleTenantBoundaryTest`, `DeviceCommandOperationalBoundaryTest`, `MonitoringProbeEgressBoundaryTest`, `MonitoringSingleTenantBoundaryTest`): all passed (48+ tests, 657+ assertions).
  - Feature (`Phase1DeviceBasicsTest`, `DiscoveryCandidateActionsTest`, `QueclinkUnitConsoleTest`, `SettingsAuditTest`, `OperationsWorkspacesTest`, `DiscoveryIdentityTest`, `DiscoveryRunTest`): 69 passed (944 assertions).
- **Home network walkthrough (`172.16.0.0/24`):**
  - Registered `Home Gateway UX7` assigned to `Home Network Lab (172.16.0.0/24)` with IP `172.16.0.1` (`1.2`), verified `Edit device` prefill (`1.1`).
  - `Ping now` on `172.16.0.1` → `< 1 ms` (`Replied in < 1 ms.`) + toggled **Alert me if this goes offline** ON (`Offline alerts are on for 172.16.0.1.`) (`1.3`, `1.4`).
  - `Ping now` on `172.16.0.253` (unassigned LAN host) → `No reply` (`The network reported that this address is unreachable from the server.`) (`1.3`).
  - Unassigned `Spare Shelf Switch` (no Site, no IP) → **Add check** remains visible (`disabled`) with `Assign this device to a Site first so checks know where it lives.` and **Edit device** shortcut (`1.5`).
  - Exercised `Ignore`, `Same as…`, and `Add all` on Discovery › Candidates (`1.6`).
  - Exercised `Request current location` (`AT+GTRTO=[REDACTED],1,,,,,,6CC5$`) on `Care tracker 867963069916998` unit console with masked IMEI `8679•••••••6998` (`1.7`).

#### Local walkthrough screenshots
- **Register device (searchable Site combobox + IP address on Step 1):**
  ![Local Register Device](screenshots/phase-1-local-register-device.png)
- **Edit device (prefilled Site + IP/hostname):**
  ![Local Edit Device](screenshots/phase-1-local-edit-device.png)
- **`Ping now` (`< 1 ms` reply on `172.16.0.1`) + one-switch `"Alert me if this goes offline"` ON:**
  ![Local Ping Reply & Offline Switch](screenshots/phase-1-local-ping-reply-and-offline-switch.png)
- **`Ping now` plain-English unreachable reason (`172.16.0.253`):**
  ![Local Ping Unreachable](screenshots/phase-1-local-ping-unreachable.png)
- **Always-visible `"Add check"` with plain-English missing-Site reason:**
  ![Local Add Check Missing Reason](screenshots/phase-1-local-add-check-missing-reason.png)
- **Discovery › Candidates (`Add all` + per-candidate review actions):**
  ![Local Discovery Candidates](screenshots/phase-1-local-discovery-candidates.png)
- **Queclink unit console (masked IMEI, redacted command password, protocol citation, safe vs confirmation-gated commands):**
  ![Local Queclink Console](screenshots/phase-1-local-queclink-console.png)
