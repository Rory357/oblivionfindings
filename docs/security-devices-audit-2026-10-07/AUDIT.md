# Security & Devices: capability and integration audit

7 October 2026 · Source baseline `12d5665c47b0367a60ee9df5dcc46917c94b390f`

**User clarification:** Oblivion is development-only; nothing is in production. The user authorized a synthetic [development pilot](DEVELOPMENT-PILOT.md). References to commissioning/production below describe future acceptance, not a claim that an operational service already exists.

**Subsequent scope decisions:** one complete release; broad supported-living coverage informed by market research; configurable internal/external monitoring, evidence collection, permissions and attendance; adaptable endpoint providers/ownership/OS per separate organisation deployment; Rory's rules throughout. See the [current target scope](TARGET-SCOPE.md), [market catalogue](MARKET-RESEARCH.md) and [Rory conformance requirements](RORY-CONFORMANCE.md). These supersede unanswered hardware-count and phased-release assumptions; the source findings below remain valid.

**Latest build decision:** the user approved **Oblivion-native computer RMM**, replacing NinjaOne, with **read-only Intune visibility** as the working direction. The full Security & Devices programme resumed as a goal on 7 October 2026. The initial endpoint implementation is separate from these baseline audit findings and is not a completion claim.

## Outcome

Oblivion has substantial monitoring, device inventory, governance and tracking foundations. It does **not yet deliver the requested PRTG/Auvik, UniFi, physical security, care monitoring, RMM and MDM experience as one operational product**. Several areas have a catalogue, policy or screen ahead of an executable provider integration. Retain the foundations and complete concrete operational journeys; a wholesale rewrite is not justified by this review.

The most urgent source findings concern fall classification/device context and maintenance suppression of safety signals. These are distinct from unfinished product features. No claim is made that an actual deployed incident was lost.

## Agreed destination

- One organisation across multiple approved sites. Roles, site permissions, canonical ownership and privacy govern access. Collectors are scoped to approved sites, networks, devices and capabilities.
- Hybrid delivery: Oblivion owns the unified workspace, records, permissions, command history and cross-module workflows; supported provider systems can supply specialist execution and storage.
- Network discovery, sustained monitoring, understandable alerts, topology and supported management actions.
- Physical alarms, CCTV, UniFi access control, Milesight care sensors, vehicle/person/lone-worker tracking.
- Live CCTV inside the workspace; historical recordings may open the vendor system.
- Very important: reconstruct a fall or other incident using relevant device/sensor/camera/access/tracking evidence in Control Room and Health & Safety.
- A dedicated HR attendance function owns employment time; My Day may let workers confirm suggested times when reviewing/submitting timesheets.
- Downloadable Windows and Linux network collectors, plus a separately defined endpoint-management capability for computers and Android devices.

## Evidence and limits

This is a source, architecture and capability audit, with a runnable collector test suite. It is not a penetration test, production configuration review or witnessed hardware acceptance.

- **Executed:** collector suite on the installed Windows PHP 8.4 runtime: **37 tests passed, 335 assertions, 8.43 seconds**. See [collector test output](collector-tests.txt). Coverage includes signed configuration, scoped discovery, encrypted spool/replay, transport identity, commands and recovery. The command soak is simulated; this does not certify live device operation or an installable Windows service.
- **Attempted but unavailable:** main application's selected protocol unit tests could not start because root `vendor/bin/pest` is absent. See [attempt output](protocol-unit-tests.txt). Root frontend dependencies were also unavailable. Existing application tests were inspected as evidence of intended coverage, not counted as passed in this audit.
- **Browser:** the hosted Security & Devices URL redirected to sign-in. The user confirms it is development-only. No authenticated screens, application data, settings or live equipment were inspected.
- **Not done:** production changes, active network scanning, device commands, dependency installation, secret inspection or application implementation.
- “Present” below means source implementation found. “No integration found” refers to the inspected checkout; it is not a statement about an uninspected external deployment.

## What is currently implemented

### Network monitoring and topology

The monitoring domain has ICMP, TCP, DNS, HTTP, TLS, SNMP/interface monitoring, SSH and WinRM inventory, provider checks and collector checks. Discovery has scoped targets, candidate reconciliation and canonical device ownership. There are traps, syslog/flow ingestion, metric history/retention configuration, policies, maintenance and dependency handling, durable delivery and recovery machinery. Scheduled jobs and worker/listener deployment definitions exist.

UniFi imports, observations, events and topology are implemented. Native SNMP topology includes LLDP/CDP, ARP, forwarding and routing evidence. Immutable snapshots and change comparison exist. This is meaningful engineering, but does not establish PRTG/Auvik feature parity or a commissioned monitoring service.

Evidence: [monitor kinds](../../app/Domain/Monitoring/Enums/MonitorKind.php), [monitoring configuration](../../config/monitoring.php), [scheduled work](../../routes/console.php), [SNMP topology](../../app/Domain/Monitoring/Topology/Services/NativeSnmpTopologyProjector.php), [topology snapshots](../../app/Domain/Monitoring/Topology/Services/TopologySnapshotBuilder.php).

### Inventory, command governance and tracking

Canonical devices, assignments, site custody, maintenance, history, credentials, capability checks and command evidence are present. Commands validate provider/device/capability/site and credential availability; unavailable execution fails closed. Personal tracking has consent and assignment checks.

Tracking is one of the more developed areas: Queclink native transport, telemetry, provisioning/configuration, vehicle history, person locations and lone-worker connections exist. The Queclink cloud adapter's intentionally limited interface must not be mistaken for an absence of native tracking support. Model/firmware-specific deployment still needs acceptance.

Evidence: [assignments](../../app/Domain/SecurityDevices/Services/DeviceAssignmentService.php), [access rules](../../app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php), [tracking privacy](../../app/Domain/SecurityDevices/Services/PersonalTrackingPrivacyService.php), [people locations](../../app/Services/Tracking/PeopleLocationService.php), [Queclink commands](../../app/Domain/SecurityDevices/Management/Adapters/QueclinkTrackingCommandAdapter.php).

### Care events, Control Room and attendance

Milesight has inventory, observations and signed webhook ingestion, including classification of fall, bed-exit, panic and related events. Its empty polling `pullEvents()` method does not mean events are missing: webhooks supply them. Model coverage and sensor configuration/downlinks remain separate questions.

An existing sensor-confirmation bridge creates a canonical incident and connects the incident journey to Health & Safety. It currently carries a small evidence record, not a multi-device reconstruction. Technical monitoring also captures immutable evidence for IT tickets.

Dedicated HR attendance services and routes already exist. Attendance produces time entries and ties into timesheets; My Day already exposes attendance actions and timesheet access. A new parallel time ledger is unnecessary.

Evidence: [Milesight adapter](../../app/Services/Integration/Adapters/MilesightAdapter.php), [sensor incident bridge](../../app/Services/ControlRoom/SensorIncidentBridgeService.php), [IT evidence](../../app/Domain/Monitoring/Services/MonitoringIncidentEvidenceService.php), [HR attendance](../../app/Domain/Hr/Services/AttendanceService.php), [time-entry projection](../../app/Domain/Hr/Services/AttendanceTimeEntryProjector.php), [My Day](../../resources/js/pages/my-day/index.tsx).

## Findings

Priorities describe operational delivery risk, not vulnerability/CVSS scores. P1 should be resolved before using the affected journey for safety response. P2 is a material capability or acceptance gap.

### SD-01 · P1 · Milesight fall routing loses specific classification and device context

The Milesight adapter classifies falls as `fall_detected` with critical severity. The provider projector persists the canonical device and normalized evidence, but `IntegrationSignalNormaliser` has no explicit fall mapping. It tries `integration_fall_detected`, which has no checked-in registration, then falls back to `integration_unknown`. The seeded `fall_detected` type is a different code.

The normalizer also sets `device_id` null and omits `canonical_device_id` from normalized context. The checked-in unknown-event rule uses tier 1 and 30-minute correlation; without device/asset/person identity the correlation query can match by site. Consequently, distinct generic events from different devices at the same site can be grouped together. The normalizer uses `raw_payload` for the signal, while this projector deliberately stores normalized evidence with `raw_payload = null`. The original IntegrationEvent remains traceable by ID, but downstream evidence lacks the original safe evidence fields and direct canonical-device context.

The incident bridge infers a fall only from exact `fall_detected`; the fallback becomes `other` unless overridden. **Critical severity is retained by the integration catch-all rule; this finding does not claim it becomes informational.** Live rules may differ from checked-in defaults.

Evidence: [classification](../../app/Services/Integration/Adapters/MilesightAdapter.php#L298), [projection](../../app/Services/Integration/ProviderEventProjector.php#L136), [normalization](../../app/Services/Integration/IntegrationSignalNormaliser.php#L125), [context](../../app/Services/Integration/IntegrationSignalNormaliser.php#L343), [default catch-all rule](../../database/migrations/2026_04_10_230000_seed_integration_signal_rules.php#L122), [correlation](../../app/Services/ControlRoom/SignalProcessingService.php#L1064), [incident classification](../../app/Services/ControlRoom/SensorIncidentBridgeService.php#L106).

Acceptance: ingest a verified Milesight fall through the real normalizer/processor; preserve canonical device, site, source identity and safe evidence; apply the care-event type and urgent response rule. Two devices at one site must remain distinguishable; duplicate delivery of one event must remain idempotent. Confirming the fall must preserve evidence and create/link the correct incident. The existing Milesight webhook contract test mocks `AlertRoutingService`, so it does not cover this boundary.

### SD-02 · P1 · Maintenance windows can suppress critical safety signals

The shared processor checks maintenance before rule selection and returns after marking the signal suppressed. `isInMaintenanceWindow()` matches time/source/site/asset and has no severity or life-safety exemption. A matching broad/site maintenance window can therefore suppress a fall or panic signal even when its input severity is critical. This is source-confirmed behavior; whether a live window currently creates exposure is unknown.

Evidence: [early suppression](../../app/Services/ControlRoom/SignalProcessingService.php#L189), [maintenance predicate](../../app/Services/ControlRoom/SignalProcessingService.php#L1040).

Acceptance: define an explicit safety suppression policy. Ordinary technical maintenance must not silently mute genuine fall/SOS/panic response. Deliberate sensor testing needs a bounded, visible test workflow with responsible staff, audit history and reliable return to service. Exercise real and test signals during a site maintenance window.

### SD-03 · P2 · Broad command catalogue exceeds executable management

The catalogue declares 35 capability keys, including firmware, network configuration, alarm, access, camera and endpoint actions. The production command adapters currently registered implement **five distinct capability keys**: UniFi timed door unlock and Queclink location refresh, configuration refresh, reboot and configuration apply. The release-fixture adapter is explicitly staged test execution and is not another live provider. Availability of those five still depends on device, configuration, permissions and credentials.

Evidence: [catalogue](../../config/security_devices.php), [adapter registration](../../app/Providers/AppServiceProvider.php#L227), [UniFi command](../../app/Domain/SecurityDevices/Management/Adapters/UnifiAccessCommandAdapter.php#L23), [Queclink commands](../../app/Domain/SecurityDevices/Management/Adapters/QueclinkTrackingCommandAdapter.php#L28).

Acceptance: show a truthful support matrix for each provider/model/firmware/action: view-only, available, awaiting setup, unsupported or verified. Do not measure overall completion as five divided by 35; the catalogue does not capture the full product scope. Each added action needs execution, observed result, failure/retry handling and an operator-visible receipt.

### SD-04 · P2 · UniFi credential and access-schedule management is not connected

Credential issue and revoke explicitly throw an unavailable response because the provider execution/reconciliation adapter is absent. Local access schedules and lifecycle/evidence structures exist, but do not prove access rights reached the controller. Timed door unlock is a separate, implemented command.

Evidence: [issue/revoke](../../app/Domain/SecurityDevices/AccessControl/Services/AccessControlLifecycleService.php#L181).

Acceptance: grant/change/revoke a staff member's access against a supported UniFi controller, verify actual provider state, show pending/failed reconciliation and keep an audit trail. HR status and roster suitability must use an explicit policy, not silently confer door rights.

### SD-05 · P2 · In-app CCTV live viewing is not implemented as a complete provider journey

The security presenter consumes an observed `media_href` and permits internal links. The workspace displays that link, but this audit found no corresponding live-session route/media bridge for UniFi Protect or Hikvision. Test-supplied internal media URLs do not establish a working player. Camera inventory and health are useful foundations, not live viewing.

Evidence: [media presenter](../../app/Domain/SecurityDevices/Presenters/SecurityWorkspacePresenter.php#L574), [security workspace](../../resources/js/components/security-devices/security-workspace.tsx), [routes](../../routes/security-devices.php).

Acceptance: select an authorized camera, establish a short-lived live session, display stream/error/last-frame state and audit access. Restrict cameras by site and role. Open vendor recordings at the selected incident time where the vendor supports it; otherwise give an honest vendor/recorder/camera/time reference. Handle unreachable cameras and missing recordings explicitly.

### SD-06 · P2 · Ajax and supported Hikvision integration paths are absent

The adapter registry has UniFi, Milesight and Queclink. No Ajax adapter was found. Hikvision has a feature flag and legacy webhook parsing, but the current webhook entry path requires a registered verified adapter. Parsing code alone does not make an operational supported integration. UniFi network/access work likewise does not imply all UniFi physical-alarm products are integrated.

Evidence: [provider registrations](../../app/Providers/AppServiceProvider.php#L322), [webhook receiver](../../app/Http/Controllers/Api/WebhookReceiverController.php), [feature flags](../../config/features.php).

Acceptance: confirm exact alarm hubs/products and available vendor contracts first. Build event ingestion plus separately approved arm/disarm/bypass/configuration actions according to the chosen operational scope. Do not promise universal ONVIF or vendor feature support without model testing.

### SD-07 · P2 · Collector core exists; user-downloadable Windows/Linux product is unfinished

The standalone PHP collector supports scoped enrollment, signed configuration, transport authentication, encrypted buffering and recovery. Enrollment/re-enrollment/revocation screens/routes exist. Linux systemd deployment scripts and instructions exist. No in-app Windows/Linux release-download journey, packaged Windows service installer or managed release/update distribution was found.

Evidence: [collector README](../../collector/README.md), [collector management routes](../../routes/security-devices.php), [executed test evidence](collector-tests.txt).

Acceptance: release signed/versioned Windows and Linux packages with checksums, prerequisites, site-scoped enrollment, service lifecycle, upgrade/rollback, last-seen/version status and revocation. The network collector and an endpoint agent are different roles; one collector does not enroll all computers for remote desktop or Android management. The README's opening claim of no command channel is stale relative to the bounded command path now implemented.

### SD-08 · P2 · Topology data exists, but the display is not a UniFi-style network map

The topology component renders a grid of device cards and a relationship list. It does not lay out an interactive network graph with linked ports, path exploration and pan/zoom. The backend snapshots, evidence and confidence are valuable and should drive that view.

Evidence: [topology UI](../../resources/js/components/security-devices/topology-map.tsx#L112), [SNMP topology evidence](../../app/Domain/Monitoring/Topology/Services/NativeSnmpTopologyProjector.php).

Acceptance: navigate site → switch/AP → connected device, identify uplinks/ports and health, distinguish inferred/stale links, and compare historical topology without changing current device ownership.

### SD-09 · P2 · Dedicated HR attendance exists; access-reader suggestions are not connected

The HR attendance aggregate, time-entry projection and My Day actions are present. No UniFi door-event-to-attendance suggestion/confirmation flow was found. A door opening is evidence of access, not by itself proof that paid work started or ended.

Evidence: [HR attendance](../../app/Domain/Hr/Services/AttendanceService.php), [time projection](../../app/Domain/Hr/Services/AttendanceTimeEntryProjector.php), [HR routes](../../routes/hr.php), [My Day](../../resources/js/pages/my-day/index.tsx).

Proposed authority: HR/Attendance owns confirmed time and corrections; rostering owns planned shifts; My Day is the worker confirmation surface; Security & Devices owns raw access events. Acceptance covers missing exit swipes, offsite work, split/overnight shifts, late events and approved-timesheet corrections. User confirmation of the exact pay-time rules is still required.

### SD-10 · P2 · Multi-device incident reconstruction is not delivered

The sensor-to-incident bridge and IT monitoring snapshots are real. They do not assemble a historical time window containing multiple sensors/cameras/access/tracking sources into one investigation with missing-evidence indicators. The IT snapshot is tied to an IT ticket and should not become the owner of a care incident.

Evidence: [sensor evidence](../../app/Services/ControlRoom/SensorIncidentBridgeService.php#L94), [IT snapshots](../../app/Domain/Monitoring/Services/MonitoringIncidentEvidenceService.php#L78). See the [shared reconstruction plan](INCIDENT-RECONSTRUCTION-HANDOFF.md).

Acceptance: demonstrate a fall with sensor detection, available camera references, door activity, relevant tracking/location and staff response on a common timeline. Preserve device/room/person assignment as it was at occurrence; record observed and received times, clock uncertainty, access decisions, gaps, evidence provenance and later additions. Keep automated correlation distinct from a reviewer's conclusions. Vendor-hosted recordings need expiry/preservation handling; a bookmark alone cannot prevent deletion.

### SD-11 · P2 · Kaseya-like RMM and Intune-like endpoint management are missing execution products

SSH/WinRM inventory and generic command names do not provide a workstation agent, remote desktop, patch deployment, software rollout or mobile enrollment. No operational Intune/Android Enterprise integration or general RMM endpoint agent was found in application/config/routes/collector/UI searches.

For the requested hybrid design, add a dedicated endpoint-management area within IT/Devices. RMM scope should be decided around inventory, health, remote assistance, scripts, patches, software, service/process actions and result history. MDM scope should cover enrollment, ownership mode, device/app policies, compliance, approved apps, kiosk/shared-device use, lost-device action and retirement. Sensitive actions need specific permissions and provider result reconciliation.

The latest direction is native Oblivion computer management with Intune providing read-only device visibility. Intune retains its own MDM policies and write actions. Which operations can be embedded depends on each API, license and enrollment mode. Microsoft documents Intune enrollment, configuration, apps, compliance and remote actions; supported platforms do not imply equal features on every OS. See [Microsoft's Intune overview](https://learn.microsoft.com/en-us/intune/fundamentals/what-is-intune). The user's selection supersedes the original open shortlist; no purchase or provider connection was made by this audit.

### SD-12 · P2 · Completion labels, public promises and deployment proof do not align

The older next-session document calls the module “v1-complete” and says no unblocked items remain. The later completion goal says implementation is in progress and distinguishes formal evidence from an owner-approved development-phase deferral for hardware/provider-dependent rows. Those historical scores are not evidence of current end-to-end product completion.

The public-facing smart-monitoring page source describes automatic multi-camera incident timelines, clips and vendor/protocol support that this review could not connect to operational implementations. That creates a concrete expectation gap even during development; publication of those pages was not verified. Separately, future production retention, workers/listeners, recovery, credential rotation, capacity and hardware acceptance will need observed evidence; their presence in configuration cannot certify deployment.

Evidence: [older completion note](../security-devices-next-session.md#L5), [later completion goal](../it-support-security-devices-completion-goal.md#L53), [public claims](../../resources/js/pages/smart-monitoring.tsx), [public home claims](../../resources/js/pages/home.tsx).

Acceptance: reconcile documentation against a journey-based matrix with separate columns for implemented, tested locally, configured, witnessed on hardware and supported in production. Correct unsubstantiated product claims. Preserve past acceptance history rather than silently relabelling simulated tests as live proof.

## What appears to have gone wrong

The evidence supports a **scope and acceptance mismatch**, rather than a conclusion that nothing was built. An earlier, narrower version was described as complete; later engineering added strong infrastructure and safeguards. Your current goal also requires many provider-specific management actions, endpoint products and cross-domain operator journeys that those completion labels did not cover.

Implementation of shared safety/command machinery outpaced some provider adapters and screens. Tests sometimes stop at a mocked integration boundary, allowing individually tested pieces to miss the complete fall journey. Hardware deferrals explain missing witnessed acceptance, but do not explain away absent installers, video sessions, access provisioning or RMM/MDM execution. Public feature promises further obscured the difference. This causal assessment is an inference from the reviewed source and documents; it is not an attribution of blame to a person.

## Internal implementation and testing order

The user requires one complete release covering all agreed workstreams. The following order is for development dependencies and validation; it does not propose partial customer releases.

1. Agree the supported hardware/actions and domain authorities; correct the capability/completion matrix and unsubstantiated public claims.
2. Resolve SD-01/02 and prove one Milesight fall end-to-end through Control Room, the canonical incident and H&S, including replay, two devices, maintenance and evidence gaps.
3. Complete one live CCTV provider and vendor recording references, then build the shared reconstruction timeline around real evidence.
4. Commission a representative network site: discovery, monitoring, alerts, history, topology, outage recovery, signed collector downloads and support workflow.
5. Finish prioritized UniFi access/attendance and physical-alarm actions; expand the hardware acceptance matrix and tracking management.
6. Integrate the chosen endpoint-management execution provider(s), with separate computer RMM and Android/MDM acceptance.

For other CCTV candidates with NZ distribution, Axis and Hanwha are reasonable names to evaluate; Channel Ten lists both. This establishes local distribution, not market-share ranking or an implemented Oblivion integration. See [NZ distributor](https://www.channelten.co.nz/about-us).

Health & Safety independently confirmed SD-01/SD-02 and incorporated the shared reconstruction contract and R01–R10 into its plan. Its [planning response](../health-safety-audit-2026-10-07/INCIDENT-RECONSTRUCTION.md) records agreement and refinements, including correct canonical-device identity, live-now versus incident-time footage, bounded evidence collection and actual recording preservation.

No application changes are included in this audit. The [questions](QUESTIONS.md) define the remaining product choices before implementation is scoped.


## Subsequent development evidence — 8 October 2026

SD-08 now has an interactive connected topology implementation, including port inspection, evidence-based paths, pan/zoom, isolated devices and recent snapshot comparison. Current Site/device authority also filters historical evidence. See [topology development evidence](../native-endpoint-management/TOPOLOGY-MAP.md) and [integration checkpoint](../native-endpoint-management/CHECKPOINT.md). This supersedes the card-only implementation state described in the dated finding above. Subsequent source now browses older retained history with bounded current-authority pagination; its full-application follow-up passed22tests/300assertions. Further development now adds approved Site filters, literal name search across the full permitted inventory and bounded device paging beyond 100; frontend, focused query and actual-browser checks pass. Its corrected final application regression passed 25 tests with 405 assertions and verified isolated cleanup. Cross-page graph context, workload scale and live-equipment acceptance remain open; the original audit and full final-release requirements are preserved.


The next SD-08 increment adds bounded current connection exploration across ordinary inventory pages. It preserves exact incident edges, current visibility and canonical Site ownership, and supports onward device selection without claiming a whole-network route. Independent review and focused/frontend/browser checks pass; the full application follow-up passed 28 tests with 479 assertions and verified isolated cleanup, as recorded in CHECKPOINT.md. This advances the dated audit finding without changing its operational acceptance requirements.
