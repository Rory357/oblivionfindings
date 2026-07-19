# Security & Devices Information Architecture and Workspaces Implementation Plan

**Goal:** Replace the current flat, partially duplicated Security & Devices experience with the approved three-layer navigation and production-backed estate, site, workspace, operations, setup, and capability-driven device views while preserving canonical records and existing provider workflows.

**Source of truth:**

- `docs/superpowers/specs/2026-07-18-it-support-native-monitoring-platform-design.md`
- `docs/it-support-security-devices-completion-goal.md`
- `docs/superpowers/plans/2026-07-18-it-support-monitoring-foundation-vertical-slice.md`

**Branch:** `codex/it-security-monitoring-design`

**Status:** In progress

## Audited starting point

- The global application already has one **Security & Devices** entry, but its secondary navigation is a single permission-filtered group containing up to 13 destinations. It does not express Overview, Workspaces, Operations, and Setup.
- Canonical `Device`, `DeviceAssignment`, `DeviceRelationship`, `DeviceEvent`, `DeviceMaintenanceRecord`, `DeviceGroup`, document, asset-link, and provider-integration records already exist and must remain authoritative.
- The current dashboard, device inventory/profile, category pages, groups, event, maintenance, reports, and integration pages are production-backed. They must be composed into the new experience, not replaced with mock dashboards.
- Legacy `security-devices-shell.tsx`, `section.tsx`, `index.tsx`, and `config.ts` still describe pages as skeletons/future homes. They are stale beside the production-backed pages and must be removed once no route imports them.
- Monitoring has canonical collectors, profiles, monitors, append-only observations, confirmed state, Control Room correlation, and IT links. Full discovery, topology, protocol, remote-collector runtime, capacity, and PRTG/Auvik-class breadth remain the next implementation plan; this plan must expose only the monitoring state that truly exists.
- Existing UniFi, Milesight, and Queclink administration remains provider-specific under Integrations for diagnosis and setup. Routine estate/workspace navigation remains provider-neutral.
- Existing Fleet tracking, Client location, Site hardware, HR asset/access, IT work, and Control Room alert stores retain ownership. Security & Devices reads and deep-links to them through canonical relationships; it does not create parallel registers.

## Experience and ownership contract

1. Global navigation has one Security & Devices entry.
2. Module navigation has exactly four permission-aware groups:
    - **Overview:** Estate overview, Sites, All devices.
    - **Workspaces:** Network & IT, Security, Healthcare, Tracking, Facilities & IoT.
    - **Operations:** Monitoring, Maintenance.
    - **Setup:** Discovery & collectors, Integrations, Settings & audit.
3. Local tabs divide specialist work without adding more application-level destinations.
4. Every count is tenant/site/permission scoped and uses the same query definition as its destination.
5. Empty, unsupported, paused, stale, and not-configured states are explicit. No page describes future capability as if it is live.
6. Healthcare pages contain technical/connectivity/data-delivery state only. Clinical readings, thresholds, and clinical review stay in Client Health Monitoring.
7. Tracking pages retain distinct personal-safety, Fleet, and asset purpose/consent/retention rules even when maps and device records are shared.
8. Control Room owns operational alert correlation. IT & Support owns accountable technical work. Security & Devices owns device identity/state/capabilities.
9. Management actions appear only when a device declares the capability and the actor passes the command policy introduced by the later management plan.
10. Legacy deep links remain valid through compatible rendering or explicit redirects that preserve filters and record identifiers.

## Approved route map

| Destination            | Canonical route                    | Local tabs / purpose                                                          |
| ---------------------- | ---------------------------------- | ----------------------------------------------------------------------------- |
| Estate overview        | `/security-devices`                | health, change, coverage, affected sites, action                              |
| Sites                  | `/security-devices/sites`          | searchable site technology posture                                            |
| Site technology        | `/security-devices/sites/{site}`   | WAN path, topology, devices, alerts, IT work, maintenance, collector, changes |
| All devices            | `/security-devices/devices`        | inventory, saved views, bulk selection, export                                |
| Network & IT           | `/security-devices/network-it`     | overview, map, devices, interfaces, services, traffic, configuration          |
| Security               | `/security-devices/security`       | overview, CCTV, alarms, access control, events                                |
| Healthcare             | `/security-devices/healthcare`     | overview, client devices, shared/site devices, data flow, calibration         |
| Tracking               | `/security-devices/tracking`       | overview, personal safety, Fleet, assets, geofences, history                  |
| Facilities & IoT       | `/security-devices/facilities-iot` | overview, environment, building systems, utilities, automations, history      |
| Monitoring             | `/security-devices/monitoring`     | findings/state, coverage, dependencies, trends, data collection               |
| Maintenance            | `/security-devices/maintenance`    | due, overdue, planned, completed, calibration                                 |
| Discovery & collectors | `/security-devices/discovery`      | current collectors/coverage now; scopes, candidates, runs after runtime plan  |
| Integrations           | `/security-devices/integrations`   | provider connections, mappings, sync, exceptions                              |
| Settings & audit       | `/security-devices/settings`       | policies, defaults, data quality, audit                                       |

Legacy category paths (`/alarms`, `/cctv`, `/access-control`, `/tracking-devices`, `/smart-iot-healthcare`, `/it-infrastructure`, `/facilities`, `/maintenance-health`, `/alerts-events`) remain compatible and resolve to the corresponding canonical workspace/tab without losing query filters.

## Task 1: Establish the grouped module-navigation contract

**Files:**

- Create `resources/js/components/security-devices/security-devices-navigation.ts`
- Create `resources/js/components/security-devices/security-devices-module-shell.tsx`
- Modify `resources/js/components/app-sidebar.tsx`
- Modify `resources/js/pages/security-devices/dashboard.tsx`
- Add component/contract tests

- [x] Write failing tests for the four approved groups, exact destination order, permission filtering, active-state matching, and one global entry.
- [x] Extract the Security & Devices navigation definition from the large application sidebar so module shell, app flyout, command search, and tests share one source.
- [x] Render grouped secondary navigation in the existing app flyout and a compact in-module context/header suitable for desktop and mobile.
- [x] Keep icons plus text, keyboard focus, 44px mobile targets, active state, and no horizontal overflow.
- [x] Remove `Skeleton`, `Future home`, and contradictory phase-only copy from every reachable production page.
- [x] Verify component tests, TypeScript, targeted ESLint/Prettier, client build, SSR build, and desktop/mobile navigation.
- [x] Commit as `feat(security-devices): group module navigation`.

**Task 1 evidence (2026-07-19):** 20 route/navigation tests with 247 assertions and the five affected controller suites with 95 tests and 741 assertions passed. The controller proof includes six tenant-isolation regressions with 77 assertions covering estate, workspace, monitoring, maintenance, integration, and cross-tenant mutation boundaries. Three frontend files passed 9 tests; TypeScript, targeted ESLint/Prettier/Pint, client build (4,985 modules), SSR build (1,637 modules), and `git diff --check` passed. Every one of the 13 destinations passed the navigation, heading, console, and horizontal-overflow matrix on desktop and Pixel 7; the final desktop and mobile runs were executed separately to isolate a transient test-login fixture flake.

## Task 2: Make Estate overview, Sites, and All devices operational

**Files:**

- Modify `app/Domain/SecurityDevices/Http/Controllers/DashboardController.php`
- Create `app/Domain/SecurityDevices/Http/Controllers/SiteTechnologyController.php`
- Extend `app/Domain/SecurityDevices/Http/Controllers/DeviceController.php`
- Create shared Security & Devices presenters/query services
- Modify/create estate, sites, site-show, and inventory pages
- Add focused feature and component tests

- [x] Write failing tenant/site/permission tests before changing queries.
- [x] Estate overview answers: what is unhealthy, what changed, what is unmonitored, which sites are affected, and what needs action.
- [x] Sites lists permission-scoped health, device/monitor coverage, active findings/events, open IT work, maintenance, collector state, and last change.
- [x] Site technology shows WAN/SD-WAN context when known, relationship/topology summary, device groups, devices, monitoring state, canonical Control Room alerts, linked IT work, maintenance, collectors, changes, and contacts.
- [x] All devices supports clear ownership/context columns, saved views, permission-gated bulk selection/export, and stable filters without duplicating devices.
- [x] Counts and drill-down results reconcile exactly.
- [x] Add empty/stale/unknown states that never look healthy by default.
- [x] Commit as `feat(security-devices): add estate and site operations`.

**Task 2 evidence (2026-07-19):** Implementation committed as `aef51ae39`. The five affected Security & Devices suites passed 73 tests with 717 assertions, covering tenant, site, direct-link, permission, count-reconciliation, selected-export, stale, unknown, and unmonitored behavior. Two frontend files passed 3 component tests; TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build (4,986 modules), SSR build (1,638 modules), and `git diff --check` passed. The production-backed Estate → Sites → Site technology → All devices journey and the complete 13-destination navigation matrix both passed separately on desktop and Pixel 7 with console and horizontal-overflow checks.

## Task 3: Add the shared specialist-workspace shell and compatibility routing

**Files:**

- Create workspace route/controller/presenter contracts
- Create `resources/js/components/security-devices/security-devices-workspace-shell.tsx`
- Create shared summary, filter, freshness, and device-list components
- Modify `routes/security-devices.php`
- Add route/payload/component tests

- [x] Write failing tests for every canonical workspace route and legacy path.
- [x] Add URL-driven, keyboard-accessible local tabs with a compact summary and a consistent action/filter area.
- [x] Preserve query strings and device deep links across legacy redirects.
- [x] Use one production-backed device/event/maintenance query contract across workspaces.
- [x] Ensure unsupported tabs/metrics are absent or explicitly not configured, never fabricated.
- [x] Commit as `feat(security-devices): add specialist workspace shell`.

**Task 3 evidence (2026-07-19):** Implementation committed as `afeb5807c`. The final workspace and category contract passed 55 backend tests with 404 assertions, including canonical and legacy routes, permission denials, query/device-context preservation, active-tab count reconciliation, and honest unavailable states. Three frontend files passed 6 component tests. TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build (4,987 modules), SSR build (1,639 modules), and `git diff --check` passed. The production-backed legacy-CCTV-to-Security and Network & IT compatibility journeys passed separately on desktop and Pixel 7 with query preservation, console, and horizontal-overflow checks. This closes the shared shell only; S03-S07 remain open until each specialist workspace is complete.

## Task 4: Complete the Security workspace

**Files:**

- Create Security workspace controller/presenter and page components
- Reuse category, event, assignment, and relationship services
- Add focused backend/frontend/browser tests

- [x] Overview reconciles CCTV, alarms, access control, security events, unhealthy devices, sites affected, and required actions.
- [x] CCTV covers cameras, recorders, stream/recording health when observed, assignments, maintenance, and authorised links without exposing media to users lacking media permission.
- [x] Alarms covers panels, zones/sensors, state, events, sites, maintenance, and canonical Control Room alerts.
- [x] Access Control remains a first-class tab covering doors, locks, readers, panels, credentials/schedules/history where integrations provide them; software RBAC is excluded.
- [x] Security events reuses canonical `DeviceEvent` and Control Room context rather than creating another alert register.
- [x] Command buttons remain hidden until the management-command plan supplies capability/risk controls.
- [x] Commit as `feat(security-devices): complete security workspace`.

**Task 4 evidence (2026-07-19):** Implementation committed as `61fe23aa5`. The Security workspace and connected category, compatibility, event-signal, and maintenance regression passed 86 backend tests with 676 assertions. Four frontend files passed 12 component/contract tests; TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build (4,988 modules), SSR build (1,640 modules), and `git diff --check` passed. The production-backed Overview → CCTV → Alarms → Access Control → Security events journey passed against the rebuilt production assets on desktop and Pixel 7 with canonical Control Room links, explicit media permission, no management commands, console checks, and horizontal-overflow checks.

## Task 5: Complete the Healthcare workspace with clinical separation

**Files:**

- Create Healthcare workspace controller/presenter and page components
- Extend permission-safe assignment/maintenance/event presentation
- Add healthcare technical-state tests

- [x] Client devices show minimum-necessary client identity, device assignment, technical health, battery, connectivity, last successful data delivery, integration status, calibration/maintenance, support contact, and authorised IT links.
- [x] Shared/site devices show location and service responsibility without implying a client assignment.
- [x] Connectivity & data flow distinguishes device offline, integration failure, stale delivery, unsupported monitoring, and healthy flow.
- [x] Calibration & maintenance reconciles to canonical maintenance records.
- [x] Clinical values, clinical thresholds, diagnoses, medication data, and clinical review are absent from all payloads and exports.
- [x] Direct URLs and counts enforce both device-domain and client-context permissions.
- [x] Commit as `feat(security-devices): add healthcare device workspace`.

**Task 5 evidence (2026-07-19):** Implementation committed as `4f3149a05`. The Healthcare workspace and connected Security & Devices regressions passed 146 backend tests with 1,179 assertions; the final non-overlapping overview-count regression passed separately with 15 assertions. Five Healthcare component tests, TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build (4,989 modules), SSR build (1,641 modules), and diff checks passed. The production-backed Overview → Client devices → Shared & site devices → Connectivity & data flow → Calibration & maintenance journey passed against rebuilt assets on desktop and Pixel 7 with canonical assignment, IT, Site, Client Health Monitoring, and maintenance links, clinical-sentinel absence, client-policy enforcement, no console errors, and no horizontal overflow.

## Task 6: Complete the Tracking workspace without duplicating Fleet or Client records

**Files:**

- Create Tracking workspace controller/presenter and page components
- Reuse Fleet tracking, resident/client location, geofence, and canonical device services
- Add purpose/consent-aware tests

- [x] Overview separates personal safety, Fleet, and asset tracking with distinct counts and required actions.
- [x] Personal safety projects authorised resident/staff tracker state and deep-links to the canonical Client/HR workflow.
- [x] Fleet tracking projects vehicle/device health and deep-links to Fleet journeys/operations.
- [x] Asset tracking projects canonical device/asset assignments and deep-links to Asset/Fleet records.
- [x] Geofences and History use shared map infrastructure but apply each source domain's permissions, purpose, consent, retention, and export rules.
- [x] Unknown/withdrawn/expired consent cannot be represented as active permission.
- [x] Commit as `feat(security-devices): add tracking workspace`.

**Task 6 evidence (2026-07-19):** Implementation committed as `bc89731b5`. Purpose-scoped Client, staff, Fleet, and Asset projections; canonical deep links; consent expiry/withdrawal/refusal handling; retained/redacted history; geofences; direct-device location and provider-payload redaction; foreign-tenant denial; and truthful 100-device safety-cap reporting passed 155 connected backend tests / 1,407 assertions. Seven Security & Devices frontend files passed 24 component tests. TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build (4,990 modules), SSR build (1,642 modules), and diff checks passed. The current-commit production-backed six-tab journey passed on desktop and Pixel 7 with direct-URL privacy assertions, canonical Client/H&S/Fleet/Asset links, no console errors, and no horizontal overflow. The global browser setup's unrelated Rostering demo reseed warned about the isolated worktree encryption key; both scoped Tracking journeys still executed and passed.

## Task 7: Complete the Network & IT workspace on the native monitoring foundation

**Files:**

- Create Network & IT workspace controller/presenter and page components
- Reuse canonical Device, Monitor, Observation, relationship, IT-link, and provider data
- Add topology/coverage/capacity presentation tests

- [x] Overview shows sites, network device health, WAN-path knowledge, active monitoring state, capacity warnings, firmware/configuration drift evidence, and open technical work.
- [x] Network map renders known device relationships and clearly labels incomplete discovery/topology.
- [x] Devices and Interfaces present currently observed identifiers/counters without provider-specific navigation.
- [x] Services shows monitor coverage, current state, dependency context, and missing/unsupported checks.
- [x] Traffic & capacity displays only retained metrics that exist; collection gaps are visible.
- [x] Configuration & firmware shows observed/desired state only where supported; management controls wait for the command plan.
- [x] Full native discovery, SNMP/flow breadth, remote collector runtime, and topology inference remain traceable to the following runtime plan, not falsely checked here.
- [x] Commit as `feat(security-devices): add network operations workspace`.

**Task 7 evidence (2026-07-19):** Implementation committed as `d5cf7261b`. Canonical device/site/WAN posture, explicit relationship topology, native monitor and service state, allowlisted retained SNMP interface/capacity evidence, permission-gated IT links, and read-only configuration/firmware drift passed 61 focused backend tests / 519 assertions. The broader connected matrix passed 124 tests / 1,368 assertions across Network, the shared category/workspace contract, canonical devices, estate/site operations, Security, Healthcare, Tracking, and the native monitoring persistence/recovery foundation. Eight Security & Devices frontend files passed 29 component tests. TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build (4,991 modules), SSR build (1,643 modules), and diff checks passed. The current-commit seven-tab journey passed on desktop and Pixel 7 with raw-provider sentinels absent, no console errors, no horizontal overflow, and no ungoverned management controls. This closes the Network & IT presentation milestone only: E04, discovery/protocol/flow breadth, remote collector runtime, inferred topology, and governed command execution remain open for the following runtime and command plans.

## Task 8: Complete the Facilities & IoT workspace

**Files:**

- Create Facilities & IoT workspace controller/presenter and page components
- Reuse devices, events, maintenance, site, and integration records
- Add focused tests

- [x] Overview reconciles environment, building systems, utilities, active events, maintenance, sites affected, and data freshness.
- [x] Environmental sensors distinguish current technical state, data freshness, threshold-event evidence, and unmonitored state.
- [x] Building systems covers safety/building equipment without duplicating Site maintenance records.
- [x] Utilities and Automations show supported integrations and last execution/state truthfully.
- [x] History uses canonical events/observations with filters and permission-gated export.
- [x] Commit as `feat(security-devices): add facilities and iot workspace`.

**Task 8 evidence (2026-07-19):** Implementation committed as `2f1a17cc1`. Canonical facility grouping, site impact, native monitor/observation freshness, append-only threshold events, shared maintenance references, safe integration/sync projections, explicit read-only automation evidence, filtered history, and permission-gated export passed 6 focused backend tests / 115 assertions. The broader connected matrix passed 140 tests / 1,556 assertions across Facilities, the shared category/workspace contract, canonical devices, estate/site operations, Security, Healthcare, Tracking, Network, reports, canonical integration history, and the native monitoring persistence/recovery foundation. Nine Security & Devices frontend files passed 34 component tests. TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build (4,992 modules), SSR build (1,644 modules), and diff checks passed. The current-commit six-tab journey passed on desktop and Pixel 7 with raw device, provider, event, maintenance, observation, integration, and automation sentinels absent; no console errors; no horizontal overflow; and no automation command controls. This closes the Facilities & IoT presentation milestone only: operational workspaces, native collection/runtime breadth, capability-driven device management, integrations/settings, cross-module projections, and governed commands remain open.

## Task 9: Add operational Monitoring, Maintenance, and Discovery & collectors views

**Files:**

- Create monitoring/discovery controllers and presenters
- Modify maintenance controller/page into the canonical route/tab model
- Create operations pages and tests

- [ ] Monitoring shows active monitor states/findings, coverage, missing/unsupported/paused checks, dependencies, trends available from retained observations, and data-collection freshness.
- [ ] Maintenance separates overdue, due soon, planned, in progress, completed, calibration, and firmware/configuration work with reconcilable device/site filters.
- [ ] Discovery & collectors shows current collectors, assignment, heartbeat/lag, monitor load, site/path scope, and unsupported/not-configured state from the existing foundation.
- [ ] No discovery-run or candidate UI is shown until the following runtime plan provides canonical records.
- [ ] Collector failure cannot silently present every downstream device as independently failed.
- [ ] Commit as `feat(security-devices): add monitoring operations workspaces`.

## Task 10: Make the device profile capability-driven

**Files:**

- Extend device profile presenter/controller
- Refactor `resources/js/pages/security-devices/devices/show.tsx` into purpose-driven sections/components
- Add permission/capability/component/browser tests

- [ ] Concise header shows identity, location/assignment, health, freshness, provider observation, and required action.
- [ ] Sections cover Health, Monitors, Topology, Interfaces/sensors, Configuration, Assignments, Tickets, Events, Maintenance, Documents, and Audit.
- [ ] Existing assignment, relationship, event, maintenance, and document workflows remain functional.
- [ ] IT and Control Room context is permission-safe and uses canonical deep links.
- [ ] Capability-specific read/configure/control actions appear only when supported and authorised; high-risk actions remain unavailable until Task Plan 5.
- [ ] Mobile navigation avoids a horizontally overflowing wall of tabs.
- [ ] Commit as `feat(security-devices): expand device profile`.

## Task 11: Reconcile Integrations and add Settings & audit

**Files:**

- Extend integrations hub presenter/page
- Create settings/audit controller/page
- Preserve UniFi, Milesight, and Queclink provider routes
- Add audit/permission/exception tests

- [ ] Integrations summarizes connection health, credential reference/rotation due state, site mapping, last sync, exceptions, imported-device reconciliation, and provider drill-down.
- [ ] Provider pages remain diagnostic/setup workspaces; provider details do not dominate routine device screens.
- [ ] Settings & audit exposes classification defaults, monitoring/profile defaults that currently exist, assignment/data-quality exceptions, feature support, and immutable audit evidence.
- [ ] Missing credentials, unmapped sites, duplicate candidates, unsupported checks, stale sync, and integration errors are visible and actionable.
- [ ] Reusable secrets are never returned to Inertia payloads, logs, or audit detail.
- [ ] Remove dead skeleton files after import/route proof confirms they are unreachable.
- [ ] Commit as `feat(security-devices): complete integrations and audit`.

## Task 12: Verify the Security & Devices experience and update the master ledger

- [ ] Run all `tests/Feature/SecurityDevices`, `tests/Feature/Monitoring`, and connected IT/Control Room tests.
- [ ] Run tenant, site, role, sensitive-domain, direct-link, count, search, export, and mutation denial suites.
- [ ] Run all frontend tests, types, repository-wide ESLint, client build, and SSR build; record the repository-wide format baseline separately without bulk rewriting unrelated files.
- [ ] Run production-backed desktop/mobile browser journeys for Estate, Sites, device inventory/profile, each specialist workspace, Monitoring, Maintenance, Discovery, Integrations, and Settings.
- [ ] Run accessibility and overflow checks across grouped navigation and local tabs.
- [ ] Verify every legacy Security & Devices deep link and current UniFi/Milesight/Queclink route remains valid.
- [ ] Update only exact proven S01-S10, relevant X01-X06/E04-E09, and verification-gate evidence in `docs/it-support-security-devices-completion-goal.md`.
- [ ] Record explicit handoff gaps for the native runtime, management-command, and cross-module/privacy plans.
- [ ] Commit as `docs(security-devices): record workspace evidence`.

## Execution rule

Execute in order with test-driven development and a commit after each task. A route or tab is not complete merely because it renders: its payload, authorization, canonical links, action semantics, empty/stale/error states, responsive behavior, and focused evidence must pass. Do not mark the master goal complete after this plan; native discovery/protocol/topology/collector breadth, PRTG/Auvik-class monitoring, management commands/secrets, cross-module projections, privacy withdrawal, operational hardening, and final release verification remain separate streams.
