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

### Live `.com` fixture inventory (to remove after `mysqldump` backup)

#### Release fixture packs to remove (2)
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
