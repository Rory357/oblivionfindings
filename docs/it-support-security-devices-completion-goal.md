# IT & Support and Security & Devices Completion Goal

**Source design:** `docs/superpowers/specs/2026-07-18-it-support-native-monitoring-platform-design.md`

**Branch:** `codex/it-security-monitoring-design`

**Status:** Implementation in progress

## Completion rule

This ledger is the master acceptance record. The goal is complete only when every stream is `Acceptance verified`, its evidence links are recorded, the full regression/build gates pass, and the user has not left a material gap unaccepted. A merged or pushed branch is not the same as acceptance verification. Production deployment is separately authorised.

## Existing capability retained

The current IT foundation already includes requester self-service, agent queues, ticket references, comments/internal notes, attachments, watchers, merge, approval, CSAT, SLA policies and business hours, reports, knowledge, inbound email ingestion, mailbox connections, and onboarding-linked provisioning. These capabilities are migration inputs and regression obligations, not work to recreate under new names.

Security & Devices already provides the canonical `Device`, assignments, topology relationships, groups, events, maintenance, documents, asset links, provider integrations, and a DeviceEvent-to-Control Room signal bridge. Control Room remains the only operational signal/alert correlation engine.

## Delivery streams

| Stream                                      | State       | Acceptance evidence                                                                                                                                                                                                                                                                                        |
| ------------------------------------------- | ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1. Platform foundations                     | In progress | Native monitor persistence and idempotent observation ingestion: `496dbce8f`, `334c98ffa`                                                                                                                                                                                                                  |
| 2. Connected monitoring-to-ticket lifecycle | In progress | L01-L04 vertical slice: `94a5f5830`, `21d5e0b93`                                                                                                                                                                                                                                                           |
| 3. Complete IT & Support                    | In progress | Typed monitoring links, governed service-management lifecycles, grouped navigation, catalogue, teams, queues, routing, service ownership, and secure service API identities with scoped idempotent intake: `2bb1f5fd2`, `0c0a51410`, Tasks 2-9 in the implementation plan                                  |
| 4. Complete Security & Devices workspaces   | In progress | Grouped navigation, Estate/Sites/device inventory, specialist shell, Security, Healthcare, Tracking, Network & IT, Facilities & IoT, and operational Monitoring/Maintenance/Discovery: `b20817d43`, `aef51ae39`, `afeb5807c`, `61fe23aa5`, `4f3149a05`, `bc89731b5`, `d5cf7261b`, `2f1a17cc1`, `faf8f6a71` |
| 5. Cross-module projections and privacy     | Planned     | None recorded                                                                                                                                                                                                                                                                                              |
| 6. Production hardening and closure         | Planned     | None recorded                                                                                                                                                                                                                                                                                              |

Allowed states are `Planned`, `In progress`, `Implemented`, `Acceptance verified`, and `Blocked with evidence`.

## Requirement matrix

### Architecture and monitoring

- [ ] A01 Laravel/Inertia control plane and monitoring-runtime boundary are implemented.
- [ ] A02 Central SD-WAN monitoring works without a collector.
- [ ] A03 Optional remote collector is securely enrolled, scoped, buffered, monitored, revoked, and recovered.
- [ ] A04 Durable versioned observation/event/command contracts are idempotent and replayable.
- [ ] A05 Time-series storage and retention tiers are implemented with business-record references in MySQL.
- [ ] A06 Discovery scopes, runs, candidates, matching, merge/split, and exclusions are complete.
- [ ] A07 ICMP, TCP, DNS, HTTP/HTTPS, TLS, SNMPv3, traps, syslog, flow, SSH/WinRM-approved checks, and provider APIs are covered.
- [ ] A08 Monitor profiles, coverage gaps, dependencies, maintenance, confirmation, hysteresis, stale/unknown, baselines, and roll-ups are complete.
- [ ] A09 Topology snapshots, evidence/confidence, changes, maps, and root-cause suppression are complete.
- [ ] A10 Configuration/inventory snapshots, diff, firmware, and capacity history are complete.

Progress notes:

- A04 partial: durable `Monitor` and `MonitorObservation` persistence plus the v1 `ObservationInput` source-key contract are implemented and idempotent. Full version negotiation, event/command replay, and replay operations remain open.
- A08 partial: typed monitor profiles, failure/recovery confirmation thresholds, and honest stale/unknown transitions are implemented. Coverage analysis, dependencies, maintenance behavior, generalized hysteresis, baselines, and roll-ups remain open.

### Monitoring-to-work lifecycle

- [x] L01 Confirmed failure creates or updates one canonical Control Room correlation.
- [x] L02 Policy creates one linked IT incident for root-cause technical work.
- [x] L03 Repeated observations and downstream symptoms enrich existing work without duplicates.
- [x] L04 Confirmed recovery resolves the monitoring finding and marks the IT incident recovered without falsely resolving technician work.
- [ ] L05 Collector/path/runtime outages create one accurate correlation and stale state rather than device-alert storms.
- [ ] L06 Retry, ordering, dead-letter, replay, clock drift, gap, and backlog failures are visible and recoverable.

### IT & Support

- [x] I01 Navigation and labels use IT & Support with the approved Service Desk, Service Delivery, Operations, and Setup groups.
- [x] I02 Incidents, service requests, provisioning, problems, changes, security requests, and major incidents have governed lifecycles.
- [x] I03 Shared work supports context links, queues/teams, assignee/owner, SLA, conversations, attachments, watchers, tasks, approvals, and timeline.
- [x] I04 Help Centre, knowledge deflection, service catalogue, dynamic forms, My requests, and CSAT are end-to-end.
- [x] I05 Inbound/outbound email threading, delivery/bounce state, deduplication, spoofing controls, and attachment quarantine are complete.
- [x] I06 Secure API for approved systems uses service identities, scoped fields, idempotency, rate limits, and audit.
- [x] I07 Queue views, saved filters, workload, SLA risk, waiting parties, bulk per-item results, and ticket workspace are complete.
- [x] I08 Joiner/mover/leaver provisioning covers accounts, licences, equipment, network, access control, reversals, and HR completion.
- [x] I09 Problems, known errors, workarounds, changes, validation/backout, knowledge lifecycle, major incidents, and reports are complete.
- [x] I10 Existing ticket/provisioning references, routes, history, attachments, HR bridge, and permissions survive migration.

### Security & Devices experience

- [x] S01 One global Security & Devices entry opens the approved grouped module side navigation.
- [x] S02 Estate overview, Sites, and All devices answer health, change, coverage, site impact, and required action.
- [x] S03 Network & IT workspace includes map, devices, interfaces, services, traffic/capacity, configuration, and firmware.
- [x] S04 Security workspace includes CCTV, Alarms, Access Control, and Security events.
- [x] S05 Healthcare workspace includes client/shared devices, connectivity/data flow, calibration, and maintenance without clinical-value duplication.
- [x] S06 Tracking workspace separately covers personal safety, Fleet, assets, geofences, and history with purpose/consent controls.
- [x] S07 Facilities & IoT covers environmental sensors, building systems, utilities, automations, and history.
- [ ] S08 Monitoring, Maintenance, Discovery & collectors, Integrations, and Settings & audit are complete operational workspaces.
- [x] S09 Device profile is capability-driven and includes health, monitors, topology, interfaces/sensors, configuration, assignments, tickets, events, maintenance, documents, and audit.
- [ ] S10 Existing UniFi, Milesight, and Queclink capabilities operate through expanded common contracts.

Progress note: S08 is partial. Monitoring, Maintenance, Discovery & collectors, Integrations, and Settings & audit are complete presentation workspaces on the current native foundation. Runtime discovery records, protocol execution, topology inference, collector operations, queue/DLQ health, and retention remain open, so the group is not yet a complete operational runtime. S10 remains open because UniFi is substantive while Milesight and the cloud Queclink adapter still expose only honest connection/scaffold capability; direct Queclink TCP intake is not cloud-adapter completion.

### Management, security, and privacy

- [ ] M01 Observe, Operate, Manage, Control, and Admin capabilities enforce tenant, site, workspace, device class, and sensitivity.
- [ ] M02 Command requests validate capability/state, reason, step-up, approval/change, expiry, signature, idempotency, execution, reconciliation, and audit.
- [ ] M03 Door, alarm, camera, remote-control, wipe, firmware/configuration, healthcare, and broad suppression actions receive correct high-risk controls.
- [ ] M04 Break glass is time-limited, reviewed, notified, audited, and never reveals reusable secrets.
- [ ] M05 Secret-manager references, rotation, runtime delivery, collector encryption, and log redaction are proven.
- [ ] M06 Cross-module projections pass both source-domain and destination-context permission decisions.
- [ ] M07 Personal/client/staff tracking purpose, consent, expiry, withdrawal, retention, direct-link, export, and cached-access controls are proven.
- [x] M08 Healthcare technical telemetry remains separate from clinical readings; CCTV/media access and export are governed.

### Cross-module projections

- [ ] X01 Site Profile Technology projection is complete.
- [ ] X02 Client Profile Healthcare Devices projection is complete with clinical separation.
- [ ] X03 HR Equipment & Access projection is complete with joiner/mover/leaver links.
- [ ] X04 Fleet Vehicle Technology projection is complete without duplicating vehicle operations.
- [ ] X05 Asset/Finance links retain financial ownership and disposal reconciliation.
- [ ] X06 IT and Control Room show shared live context and frozen incident-time evidence without duplicate work registers.

## End-to-end acceptance scenarios

- [x] E01 Self-service request from knowledge/catalogue through fulfilment, closure, and CSAT.
- [x] E02 Email creation, threading, deduplication, safe attachments, outbound delivery, and bounce visibility.
- [x] E03 Joiner, mover, and leaver provisioning with HR and asset/access reconciliation.
- [ ] E04 Network failure confirmation, topology suppression, Control Room correlation, one IT incident, recovery, and technician resolution.
- [x] E05 Healthcare device assignment, technical/data-flow projection, calibration/maintenance, IT link, and clinical separation.
- [ ] E06 Tracking consent withdrawal removes collection/access across UI, API, export, direct URL, and cache.
- [ ] E07 Door command with reason, step-up, approval, signed dispatch, reconciliation, denial, and immutable audit.
- [ ] E08 Collector outage, stale state, one correlation, buffering, ordered return, and gap visibility.
- [ ] E09 Tenant, role, site, sensitive-domain, and command denial across view, count, search, export, link, mutation, and API.

## Verification gates

- [ ] V01 Domain and lifecycle test suites pass.
- [ ] V02 Protocol/provider integration and contract suites pass.
- [ ] V03 Queue/retry/replay/dead-letter suites pass.
- [ ] V04 Tenant/site/field/command/security suites pass.
- [ ] V05 Migration, compatibility, rollback/forward-repair, and reconciliation suites pass.
- [ ] V06 Browser acceptance journeys pass with production-backed fixtures.
- [ ] V07 Accessibility checks pass.
- [ ] V08 PHP tests, frontend tests, types, lint/format, client build, and SSR build pass.
- [ ] V09 Load/soak, latency, outage, restore, credential rotation, and runbook evidence is recorded.
- [ ] V10 Completion audit confirms no inert actions, mock-only dashboards, duplicate stores, or undocumented gaps.

## Implementation plans

1. `docs/superpowers/plans/2026-07-18-it-support-monitoring-foundation-vertical-slice.md`
2. `docs/superpowers/plans/2026-07-18-it-support-service-management-expansion.md`
3. `docs/superpowers/plans/2026-07-19-security-devices-information-architecture-workspaces.md`
4. `docs/superpowers/plans/2026-07-21-native-monitoring-runtime.md`
5. Device management, command, secrets, and audit plan, created after plans 3 and 4 establish capabilities.
6. Cross-module projections, privacy, hardening, and closeout plan, created after source contracts stabilise.

## Evidence log

- 2026-07-18: Isolated worktree baseline passed `npm test`: 89 files, 357 tests.
- 2026-07-18: Master design committed as `d21565b8f`.
- 2026-07-18: Native monitoring foundation and lifecycle slice committed as `496dbce8f`, `334c98ffa`, `94a5f5830`, `2bb1f5fd2`, `21d5e0b93`, and `0c0a51410`.
- 2026-07-18: Focused lifecycle proof passed: observation ingestion 5 tests / 35 assertions; Control Room recovery 3 / 10; canonical DeviceEvent signal pipeline 4 / 19; monitoring-to-ticket integration 9 / 29; IT ticket workspace 10 / 152; linked-context component 1 test.
- 2026-07-18: Connected backend regression passed `php artisan test tests/Feature/Monitoring tests/Feature/SecurityDevices/DeviceEventSignalPipelineTest.php tests/Feature/It`: 188 tests, 1,439 assertions.
- 2026-07-18: Frontend verification passed: Wayfinder generation with no tracked drift; `npm test` 90 files / 358 tests; `npm run types`; client build 4,966 modules; SSR build 1,618 modules.
- 2026-07-18: `vendor/bin/pint --dirty` and `git diff --check` passed. V01 and V08 remain open because only this vertical slice, not the full master-goal domain and release matrix, has been verified.
- 2026-07-18: Foundation contract review found the monitor/observation identity, `ObservationInput` idempotency, online recovery routing, typed `ItTicketLink` ownership, single `DeviceSignalPublished` monitoring-to-ticket path, and permission-aware ticket context stable for dependent plans.
- 2026-07-19: Secure service API Task 9 passed 10 focused tests / 198 assertions and the full IT regression at 229 tests / 2,147 assertions. TypeScript, targeted ESLint, 5 component tests, 2 desktop/mobile browser journeys, 4 versioned API routes plus 2 identity-admin routes, client build at 4,977 modules, SSR build at 1,629 modules, PHP syntax, targeted Pint, and diff checks passed. V01 and V08 remain open because the full master-goal domain and release matrix are not yet complete.
- 2026-07-19: Joiner/mover/leaver Task 10 passed 11 focused tests / 144 assertions and the full IT regression at 239 tests / 2,264 assertions. Onboarding, offboarding, and employee-profile compatibility suites passed; the provisioning UI passed 6 component tests and 2 production-backed desktop/mobile browser journeys. TypeScript, targeted ESLint, client build at 4,978 modules, SSR build at 1,630 modules, PHP syntax, route, targeted Pint, and diff checks passed. I08 is proven; V01, V06, and V08 remain open until the complete master-goal domain, browser, and release matrices pass.
- 2026-07-19: Service-operations Task 11 committed as `46aaa2360` and passed 18 focused tests / 207 assertions, 7 component tests, 2 desktop/mobile browser journeys, TypeScript, targeted ESLint, client build at 4,979 modules, SSR build at 1,631 modules, PHP syntax, routes/schedules, targeted Pint, diff checks, and Critical/Important review. Knowledge governance, delivery/bounce/retry visibility, automation-run audit, reconcilable reporting, and setup audit are operational.
- 2026-07-19: Complete IT & Support verification passed 272 backend tests / 2,583 assertions across IT, native monitoring, and the DeviceEvent signal pipeline; the focused secure API rerun passed 6 tests / 101 assertions. Frontend proof passed 94 files / 371 tests, TypeScript, repository-wide ESLint, client build at 4,979 modules, SSR build at 1,631 modules, targeted Prettier, targeted Pint, PHP syntax, and diff checks.
- 2026-07-19: Production-backed browser acceptance passed 7 journeys across desktop and Pixel 7 with 1 deliberate duplicate mobile accessibility scan skipped. Requester catalogue/knowledge/My requests/email isolation, technician incident/problem/change/major-incident/JML/API/delivery workspaces, denial behavior, responsive navigation, and a 10-route axe matrix with no serious or critical violations are proven. I01-I10 and E01-E03 are complete. X06 remains open until frozen incident-time evidence is implemented and accepted; E09 remains open for the whole-platform sensitive-domain and command-denial matrix.
- 2026-07-19: Repository-wide `npm run format:check` still reports 1,328 pre-existing files across unrelated modules. No bulk rewrite was performed; the new IT acceptance files pass targeted Prettier. V01 and V06-V08 remain open because their definitions cover the unfinished master Security & Devices/monitoring goal, and V08 additionally requires the repository format baseline to be resolved.
- 2026-07-19: Security & Devices navigation milestone committed as `b20817d43`. S01 is proven by 20 route/navigation tests with 247 assertions, five affected controller suites with 95 tests and 741 assertions, and six tenant-isolation regressions with 77 assertions. Three frontend files passed 9 tests; TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build at 4,985 modules, SSR build at 1,637 modules, route inspection, and diff checks passed. All 13 canonical destinations passed production-backed desktop and Pixel 7 navigation, heading, console, and horizontal-overflow checks. S02-S10 and the master verification gates remain open.
- 2026-07-19: Security & Devices estate operations milestone committed as `aef51ae39`. S02 is proven by 73 backend tests with 717 assertions across estate/site operations, device inventory, selected export, dashboard compatibility, and navigation routes. Three component tests, TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build at 4,986 modules, SSR build at 1,638 modules, and diff checks passed. Production-backed Estate-to-site-to-device and complete 13-destination navigation journeys passed separately on desktop and Pixel 7 with permission, console, and horizontal-overflow checks. S03-S10 and the master verification gates remain open.
- 2026-07-19: Shared specialist workspace shell committed as `afeb5807c`. Canonical and legacy compatibility, permission denials, query/device-context preservation, honest unavailable states, and active-tab reconciliation passed 55 backend tests / 404 assertions and six component tests. TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build at 4,987 modules, SSR build at 1,639 modules, diff checks, and production-backed desktop/Pixel 7 browser journeys passed. This is foundation evidence only: S03-S10 and the master verification gates remain open.
- 2026-07-19: Security workspace committed as `61fe23aa5`. Overview, CCTV, alarms, physical access control, canonical device events, Control Room context, observed-only provider evidence, maintenance, and permission-gated internal CCTV media links passed 86 connected backend tests / 676 assertions and 12 frontend tests across four files. TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build at 4,988 modules, SSR build at 1,640 modules, and diff checks passed. The production-backed five-tab journey passed against rebuilt assets on desktop and Pixel 7 with no console errors, overflow, duplicate alert actions, or premature device commands. S04 is complete; S03 and S05-S10 remain open, as do the master verification gates.
- 2026-07-19: Healthcare workspace committed as `4f3149a05`. Minimum-necessary client identity, shared/site responsibility, explicit offline/integration/stale/unsupported/healthy flow states, canonical maintenance and calibration, authorised IT links, and persistent clinical separation passed 146 connected backend tests / 1,179 assertions plus a final 15-assertion count regression and five Healthcare component tests. TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build at 4,989 modules, SSR build at 1,641 modules, and diff checks passed. The production-backed five-tab journey passed on desktop and Pixel 7 with client-policy enforcement, clinical sentinels absent from category/detail/export payloads, no console errors, and no horizontal overflow. S05, M08, and E05 are complete; X02 remains open until the Client Profile projection is implemented and accepted, and S03/S06-S10 plus the remaining master verification gates remain open.
- 2026-07-19: Tracking workspace committed as `bc89731b5`. Purpose-scoped Client, staff, Fleet, and Asset projections; canonical deep links; consent state, retained/redacted history, geofences, direct-device privacy, tenant denial, and truthful safety-cap reporting passed 155 connected backend tests / 1,407 assertions and 24 Security & Devices component tests across seven files. TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build at 4,990 modules, SSR build at 1,642 modules, and diff checks passed. The current-commit production-backed six-tab journey passed on desktop and Pixel 7 with direct-URL privacy assertions, no console errors, and no horizontal overflow. S06 is complete. M07 and E06 remain open because collection shutdown, API/export enforcement, and cache invalidation still require the privacy/runtime closeout plan; X04 remains open until Fleet itself exposes and accepts the Vehicle Technology projection.
- 2026-07-19: Network & IT workspace committed as `d5cf7261b`. Canonical device/site/WAN posture, explicit relationship topology, native monitor/service state, retained allowlisted SNMP interface and capacity observations, permission-gated IT work, and read-only configuration/firmware evidence passed 61 focused backend tests / 519 assertions and a broader 124-test / 1,368-assertion connected matrix. Eight Security & Devices frontend files passed 29 component tests. TypeScript, targeted ESLint/Prettier/Pint, PHP syntax, client build at 4,991 modules, SSR build at 1,643 modules, and diff checks passed. The current-commit seven-tab journey passed on desktop and Pixel 7 with raw-provider sentinels absent, no console errors, no horizontal overflow, and no premature management controls. S03 is complete as a presentation workspace on the current native foundation. E04, runtime discovery/protocol/flow breadth, remote collector behavior, inferred topology, and governed device commands remain open for the dedicated runtime and command plans.
- 2026-07-19: Monitoring operations workspaces committed as `faf8f6a71`. Monitoring state/findings/coverage/dependencies/trends/data collection, reconcilable maintenance queues, and direct-versus-remote discovery/collector paths passed 61 backend tests / 603 assertions and 3 frontend tests. TypeScript, targeted ESLint/Prettier/Pint, client build at 4,994 modules, SSR build at 1,646 modules, and diff checks passed. The final current-commit journey passed on desktop and Pixel 7 across every operations tab with raw evidence excluded, no console errors or horizontal overflow, and no unsupported discovery command UI. S08 remains partial only because Integrations and Settings & audit are not yet complete; runtime discovery, protocol breadth, and governed management remain open in their dedicated plans.
- 2026-07-21: Security & Devices Task 12 desktop-web verification passed the authoritative connected backend matrix at 774 tests / 8,056 assertions and, after fixing the visible category-search label in `e204149db`, passed 108 frontend files / 428 tests, TypeScript, repository-wide ESLint, targeted Prettier, client build at 4,993 modules, SSR build at 1,645 modules, and diff checks. `npm run format:check` still reports the unchanged 1,313-file repository baseline, so V08 remains open without a bulk rewrite.
- 2026-07-21: Exact-worktree Dusk/Chrome acceptance used an isolated Dusk database and production assets. All 16 canonical Security & Devices/provider destinations passed at 1440×1000 and 1024×768 with their intended headings, grouped module navigation, active state, main landmark, and no horizontal overflow. Twelve legacy/global aliases resolved to the intended canonical or retained compatibility page. A real fixture device exercised the ten available capability-driven profile sections with no unsafe restart/reboot/push/upgrade controls, and keyboard/name checks passed after the search-label fix. S09 is complete. V06 and V07 remain open for the future runtime-backed pages rather than being overstated from the current presentation milestone; mobile is not an acceptance requirement for this web application.
- 2026-07-21: Native monitoring runtime plan `60a9ebee8` records the remaining direct protocols, discovery, typed provider contracts, topology, collector, queue/DLQ/replay, retention, runtime UI, load/restore, and desktop evidence work. Device commands/secrets and cross-module/privacy hardening remain separate plans. S08, S10, E04, E08, and the corresponding master verification gates stay open until those implementations are proven.
