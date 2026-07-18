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

| Stream | State | Acceptance evidence |
| --- | --- | --- |
| 1. Platform foundations | In progress | Native monitor persistence and idempotent observation ingestion: `496dbce8f`, `334c98ffa` |
| 2. Connected monitoring-to-ticket lifecycle | In progress | L01-L04 vertical slice: `94a5f5830`, `21d5e0b93` |
| 3. Complete IT & Support | In progress | Typed monitoring links, governed service-management lifecycles, grouped navigation, catalogue, teams, queues, routing, and service ownership: `2bb1f5fd2`, `0c0a51410`, Tasks 2-8 in the implementation plan |
| 4. Complete Security & Devices workspaces | Planned | None recorded |
| 5. Cross-module projections and privacy | Planned | None recorded |
| 6. Production hardening and closure | Planned | None recorded |

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
- [ ] I02 Incidents, service requests, provisioning, problems, changes, security requests, and major incidents have governed lifecycles.
- [ ] I03 Shared work supports context links, queues/teams, assignee/owner, SLA, conversations, attachments, watchers, tasks, approvals, and timeline.
- [ ] I04 Help Centre, knowledge deflection, service catalogue, dynamic forms, My requests, and CSAT are end-to-end.
- [ ] I05 Inbound/outbound email threading, delivery/bounce state, deduplication, spoofing controls, and attachment quarantine are complete.
- [ ] I06 Secure API for approved systems uses service identities, scoped fields, idempotency, rate limits, and audit.
- [ ] I07 Queue views, saved filters, workload, SLA risk, waiting parties, bulk per-item results, and ticket workspace are complete.
- [ ] I08 Joiner/mover/leaver provisioning covers accounts, licences, equipment, network, access control, reversals, and HR completion.
- [ ] I09 Problems, known errors, workarounds, changes, validation/backout, knowledge lifecycle, major incidents, and reports are complete.
- [ ] I10 Existing ticket/provisioning references, routes, history, attachments, HR bridge, and permissions survive migration.

### Security & Devices experience

- [ ] S01 One global Security & Devices entry opens the approved grouped module side navigation.
- [ ] S02 Estate overview, Sites, and All devices answer health, change, coverage, site impact, and required action.
- [ ] S03 Network & IT workspace includes map, devices, interfaces, services, traffic/capacity, configuration, and firmware.
- [ ] S04 Security workspace includes CCTV, Alarms, Access Control, and Security events.
- [ ] S05 Healthcare workspace includes client/shared devices, connectivity/data flow, calibration, and maintenance without clinical-value duplication.
- [ ] S06 Tracking workspace separately covers personal safety, Fleet, assets, geofences, and history with purpose/consent controls.
- [ ] S07 Facilities & IoT covers environmental sensors, building systems, utilities, automations, and history.
- [ ] S08 Monitoring, Maintenance, Discovery & collectors, Integrations, and Settings & audit are complete operational workspaces.
- [ ] S09 Device profile is capability-driven and includes health, monitors, topology, interfaces/sensors, configuration, assignments, tickets, events, maintenance, documents, and audit.
- [ ] S10 Existing UniFi, Milesight, and Queclink capabilities operate through expanded common contracts.

### Management, security, and privacy

- [ ] M01 Observe, Operate, Manage, Control, and Admin capabilities enforce tenant, site, workspace, device class, and sensitivity.
- [ ] M02 Command requests validate capability/state, reason, step-up, approval/change, expiry, signature, idempotency, execution, reconciliation, and audit.
- [ ] M03 Door, alarm, camera, remote-control, wipe, firmware/configuration, healthcare, and broad suppression actions receive correct high-risk controls.
- [ ] M04 Break glass is time-limited, reviewed, notified, audited, and never reveals reusable secrets.
- [ ] M05 Secret-manager references, rotation, runtime delivery, collector encryption, and log redaction are proven.
- [ ] M06 Cross-module projections pass both source-domain and destination-context permission decisions.
- [ ] M07 Personal/client/staff tracking purpose, consent, expiry, withdrawal, retention, direct-link, export, and cached-access controls are proven.
- [ ] M08 Healthcare technical telemetry remains separate from clinical readings; CCTV/media access and export are governed.

### Cross-module projections

- [ ] X01 Site Profile Technology projection is complete.
- [ ] X02 Client Profile Healthcare Devices projection is complete with clinical separation.
- [ ] X03 HR Equipment & Access projection is complete with joiner/mover/leaver links.
- [ ] X04 Fleet Vehicle Technology projection is complete without duplicating vehicle operations.
- [ ] X05 Asset/Finance links retain financial ownership and disposal reconciliation.
- [ ] X06 IT and Control Room show shared live context and frozen incident-time evidence without duplicate work registers.

## End-to-end acceptance scenarios

- [ ] E01 Self-service request from knowledge/catalogue through fulfilment, closure, and CSAT.
- [ ] E02 Email creation, threading, deduplication, safe attachments, outbound delivery, and bounce visibility.
- [ ] E03 Joiner, mover, and leaver provisioning with HR and asset/access reconciliation.
- [ ] E04 Network failure confirmation, topology suppression, Control Room correlation, one IT incident, recovery, and technician resolution.
- [ ] E05 Healthcare device assignment, technical/data-flow projection, calibration/maintenance, IT link, and clinical separation.
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
3. Security & Devices information-architecture and workspace plan, created after plan 1 projection review.
4. Native discovery, protocol, topology, and collector runtime plan, created after plan 1 runtime-contract review.
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
