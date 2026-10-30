# Security & Devices — complete delivery plan and GPT-6.1 Sol handover

Prepared 11 October 2026 (Pacific/Auckland). This is a consolidation of the user's accepted scope and saved development evidence, not a new audit or release certification.

## Immediate instruction for the receiving chat

Use GPT-6.1 Sol at Extra High. The current request is to receive and understand this plan. Read this document, confirm the existing worktree and read the final safe-stop section of CHECKPOINT.md. Then give the user a short readiness acknowledgement and wait for an explicit instruction to resume implementation. Do not start an unbounded goal, launch subagents, run tests, call providers, or begin implementation during that intake. The original chat remains paused.

When the user resumes, continue from the existing source and evidence. Do not restart the programme or narrow it to endpoint management. Work in bounded packages with a clear end condition, report material results, and retain a concise checkpoint.

## Workspace and source of truth

- Implementation checkout: C:/Users/steph/.codex/worktrees/native-endpoint-management/oblivionfindings
- Existing branch, verified 11 October: codex/native-endpoint-management.
- The checkout contains substantial uncommitted and untracked work. Existing sources, native runtimes and exported evidence must remain intact. The current chat owns its managed-worktree attachment. A fresh local chat may need explicit filesystem approval for this existing path; do not bypass a denial, reset it, create an empty replacement, or copy only tracked files.
- C:/Users/steph/Herd/oblivionfindings is the primary repository and historical audit location. The new chat may initially open there; set the implementation workdir explicitly to the checkout above. Do not implement these changes in the primary checkout.
- No commit, push, production migration, signed release publication, real workstation repair/restart, remote input or vendor activation was performed by the last work session.
- The user's Orbit mockup last ran at http://127.0.0.1:52212/?v=orbit-contrast&preview=connection. Preserve it if still present; recorded process IDs from 8 October are historical and must never be reused for termination without fresh ownership checks.

Read in this order, selectively rather than repeatedly loading the full archive:
1. This plan and AGENTS.md.
2. docs/native-endpoint-management/OBLI-DECISIONS.md — latest accepted decisions.
3. docs/native-endpoint-management/PROGRAMME.md and the final safe-stop section of CHECKPOINT.md — accumulated progress and unfinished verification.
4. The relevant workstream contract from the document index below.
5. DESIGN.md and only the shared guides applicable to the UI being changed.
6. Historical requirements under C:/Users/steph/Herd/oblivionfindings/docs/security-devices-audit-2026-10-07: TARGET-SCOPE.md, AUDIT.md, MARKET-RESEARCH.md, DEVELOPMENT-PILOT.md, RORY-CONFORMANCE.md and INCIDENT-RECONSTRUCTION-HANDOFF.md.

Later user decisions override older text: Linux computer agents, NinjaOne integration and an entirely separate Windows collector installer are superseded. Older audit findings describe their date, not today's complete source state. Draft contracts are proposals until implemented and verified.

## What the user is building

One operational workspace for supported-living organisations: network and equipment visibility, supported device management, physical security, care signals, people/vehicle tracking, IT support and incident reconstruction. It must connect existing canonical modules instead of duplicating them.

Each Oblivion installation is single-tenant: one operating organisation across approved sites. Different customers have separate deployments with their own database, server address, credentials and enrolment authority. There is no shared cross-customer registry, tenant selector or tenant-authenticated collector transport.

The user wants one complete customer release. The work packages below are internal build and test order, not an MVP or permission to omit unfinished capabilities. “All equipment” is handled through a broad, explicit supported model/firmware/action matrix; inventory-only devices cannot be described as remotely managed.

### Accepted product decisions

- Hybrid platform: Oblivion owns common workflows and native Windows RMM; supported specialist vendors retain their actual capabilities.
- Network management should offer PRTG/Auvik-style discovery, sensors, history, alerting and supported management. Topology should show UniFi-style physical/logical relationships with ports and device context.
- Alarms include Ajax and supported UniFi equipment, with configurable internal, external or combined monitoring.
- CCTV includes UniFi and Hikvision live view. Vendor systems retain recordings; Oblivion keeps governed incident-time references and links. Preservation is a distinct evidenced workflow. Extend the supported-living/NZ market catalogue using primary vendor and current NZ supplier evidence when selecting additional providers; do not invent popularity or protocol support.
- Access control primarily uses UniFi. A dedicated HR Attendance module owns time; My Day is the worker confirmation/correction surface.
- Milesight and other supported care equipment must feed actual device health and relevant care/response workflows.
- Tracking covers vehicles, staff/lone workers and supported people, with different privacy, assignment and response rules.
- Windows-only native computer RMM and tray helper. Windows and Linux site collectors remain required.
- One Windows application/installer offers Computer support, Site monitoring or Both. The user-facing experience is unified while background service, site collection and interactive tray processes retain separate privileges and identities.
- Downloads originate from the organisation's Oblivion. Reviewed installation profiles bind a trusted deployment server address, approved site/device/capabilities and short-lived enrolment. No hard-coded universal server or client-visible AI secret.
- Native RMM replaces NinjaOne. Kaseya/NinjaOne remain capability references. Intune is read-only visibility; Microsoft retains enrolment, app/policy deployment, lock/wipe and other MDM writes.
- The staff helper is called Obli, using the approved “02 — Obli orbit” design. Source design: https://github.com/stephanv48v/oblivion-care-website, previously inspected at commit 4e9ea7cddfd32512bf1eefaa6301e20cb169eb69.
- Keep the simple compact Windows presentation, closely grouped icon/name, restrained animation, reduced-motion support and a motion setting. The mockup is not proof that the installed product works.

### Obli and technician requirements

Administrators centrally choose OpenAI/Claude, model, secret and spending limits. Provider keys stay server-side. Basic anonymous computer help is allowed; personal tickets and organisation knowledge require staff sign-in. Shared-computer sessions must not expose the previous worker's chat, tickets or organisation data.

Staff Obli reads only approved technical diagnostics, with visible sharing scope and redaction. Screenshots/files require explicit selection and preview. Conversations stay private; only chosen messages, summaries and attachments enter a user-reviewed IT ticket. Separate configurable retention applies to chat, diagnostics and attachments; submitted evidence follows the IT policy.

Required configurable helper features: personal ticket status/replies, request remote assistance, outage notices, approved software catalogue, device health warnings, restart reminders, offline ticket drafts, approved organisation/Oblivion knowledge, and voice/dictation with explicit microphone start and visible stop. Voice stays off until the user starts it.

IT & Support > Remote support is the technician entry, also linked from a ticket's affected computer. Required tools include screen viewing and actual mouse/keyboard control, terminal/PowerShell, approved scripts, service/process management, software and OS updates, file transfer, event logs, network diagnostics and restart scheduling. Separate permissions govern each.

Attended access defaults to the user clicking Allow connection or Decline, with technician identity, requested rights, visible active-session status and Stop session. Oblivion centrally configures attended policy, separately authorised unattended equipment and tightly governed emergency access. Video recording is configurable, off by default, with notice when enabled; action audit remains independent.

Technician Obli reads authorised diagnostics and proposes a repair. Explicit technician approval is the default; configurable automation must still use canonical approved action capabilities, current permissions, consent and verified results. Raw model text, a log entry or a retrieved article must never become an arbitrary privileged command.

## Architecture and ownership

| Area | Canonical owner and boundary |
| --- | --- |
| Device identity, provider links, observations, capabilities, approved site/custody | Security & Devices; link an existing physical asset instead of creating conflicting ownership |
| Network collection | Enrolled collectors scoped to approved sites, networks, devices and capabilities |
| Physical maintenance/custody | Existing Facilities/Assets records where canonical |
| Staff identity, attendance, approved time and payroll export | HR; My Day supplies worker interaction, access events remain evidence |
| Vehicle operations | Fleet; shared tracking observations and geofences must reference canonical records |
| Care records and person context | Existing care modules; a room or location observation alone does not establish person identity |
| Immediate response, assignments, escalation and handover | Control Room |
| Incident investigation, reconstruction window policy, evidence linking and preservation workflow | Incidents / Health & Safety |
| Technical support tickets and knowledge | IT & Support |
| Native endpoint actions | Existing Device Management command/approval/result lifecycle and enrolled Windows agent |
| MDM writes | Intune; Oblivion reads authorised observations and links to the management console |

Authorisation is the intersection of permitted action, current approved site/device/person scope, canonical ownership, source privacy and applicable consent/purpose. Recheck on every read, write, delivery, retry and evidence export. A sidebar link, old receipt, incident association or administrator title is not a substitute.

Reuse immutable command identity and durable receipts. Keep requested, queued, delivered, accepted, executed and independently observed outcome distinct. Preserve original source timestamps, received times and freshness; no success from merely sending a command, saving settings or downloading a package. Keep secrets outside public projections, URLs, logs and provider responses.

## Current implementation baseline and remaining work

These are source/evidence statements from the checkpoint, not installed-release claims.

| Workstream | Existing foundation to preserve | Remaining completion work |
| --- | --- | --- |
| Common device workspace | Canonical devices, assignments, provider observations and scoped action foundations | Reconcile capability matrix, lifecycle/history, maintenance links and truthful supported/unavailable actions across all equipment |
| Network monitoring/topology | Interactive graph, ports/path inspection, isolated nodes, retained snapshots, bounded search/paging and cross-page connection exploration with test/browser evidence | Complete discovery/monitoring coverage, thresholds/history/alert routing, supported configuration actions, retention/scale and live equipment acceptance |
| Alarms/response | Existing normalisation and response foundations; H&S owns signal/maintenance fixes | Actual Ajax adapter/partner access, exact UniFi alarm capabilities, policy settings and internal/external/combined delivery/acceptance/escalation integration |
| UniFi CCTV | Governed source wizard, signed collector media path, production browser player and actual synthetic RTSPS/TURN/Chrome decode/teardown evidence | Deployed camera/NVR/firmware acceptance, persistent installed packaging and full authorised operational workflows |
| Recording references/H&S | Immutable metadata receipts, separate permissions, source-state projection, creation/recovery wizard and permanent reader; genuine producer/browser evidence | Finish interrupted verification, H&S incident-link consumer/window policy, vendor recording access/expiry, and distinct preservation evidence |
| Hikvision CCTV | Official model/firmware research and test-only encrypted HTTP tunnel/H264 witness | Production bounded discovery/source strategy, HTTP-wrapper authentication, exact camera/NVR protocol support and real device acceptance |
| UniFi access/HR | Existing access events, credentials/schedules and HR attendance/time-entry foundations | Stable occurrence-effective employee/credential and reader-direction mapping; configurable evidence-to-suggestion/confirmation/automatic-reader integration |
| Care/Milesight | Existing Milesight webhook foundations and broad market catalogue | Model-specific health/events, care/response mapping, maintenance/test-mode separation and supported equipment acceptance |
| Tracking/lone worker | Existing tracking/Queclink foundations and canonical domain records | Full assignments/history, geofence workflows, welfare/SOS/check-ins, escalation, privacy and hardware/offline acceptance |
| Collectors/installers | Scoped enrolment/recovery, Windows/Linux site-host work, download catalogue, deployment profiles, combined installer and repair foundations | Signing, installed lifecycle/upgrade/rollback/uninstall, service acceptance, offline/revocation/rotation and publication |
| Native Windows RMM | Inventory/health, remote broker/consent/relay/browser foundations, service actions, scheduled restart and approved MSI catalogue workflows | Installed screen/control acceptance, remaining technician tools, script/OS-update/software breadth, governed file/process operations and self-service |
| Obli | Native Orbit UI, staff sign-in, private central text AI, approved diagnostics/knowledge, ticket conversations and reviewed computer association | Attachments/screenshots, voice, offline drafts, outage/health notices, remaining helpdesk features and signed installed acceptance |
| Intune | Read-only mapping/observation integration exists in development source | Licensed real Graph acceptance, varied OS/ownership profiles, stale/partial failures, mapping races and native workload-ownership conflict handling |

Hikvision's successful synthetic test proves RTSP-layer Digest inside an encrypted tunnel. The HTTP wrapper's Digest challenge currently receives 401 without a supported retry. Do not claim camera compatibility or solve this by falling back to plaintext transport. HIKVISION-CONTRACT-DRAFT.md records exact limitations and official references.

## Delivery order and bounded packages

### 0. Restore the known checkpoint

Only after the user resumes:
- Confirm branch, source state and applicable instructions. Preserve existing evidence; check for newer work rather than overwriting it.
- Run the one corrected camera_move dataset in CctvLiveSessionTest, not the entire already-run CCTV batch. Confirm current new-site list is empty/source unavailable/create disabled while the old reference detail/recovery/replay remain denied.
- Run the interrupted final frontend type/scoped-lint check once against final source.
- Record terminal results and clean only the exact owned temporary test database after its process exits. No old process ID is authority to stop a current process.

Exit: the remaining checkpoint verification is honestly classified, source/evidence intact and no orphan test process.

### 1. Reconcile coverage and shared operating policies

Use the existing 24-family market catalogue, audit and this baseline to maintain a concise implementation matrix: family, exact model/firmware/protocol, inventory, monitoring, supported commands, live/history/evidence, licensing/partner dependency, current proof and remaining acceptance.

Finish common device/capability and effective-policy contracts before expanding adapters. Provide organisation defaults and authorised site/event/severity overrides, schedules/effective dates and preview of which rule applies. Do not generate 24 sidebar modules or a second policy engine where a canonical owner exists.

Exit: every agreed feature maps to a concrete owner, dependency, current source and verifiable outcome; no unbounded “full vendor parity” promise.

### 2. Complete the physical incident journey with H&S

Coordinate at a natural checkpoint with the existing H&S owner. Implement reference attachment using the Devices resolver for the same actor plus independent incident/source/window-policy checks. Keep failed attachment recoverable without deleting the saved camera reference.

Add configurable event trigger, before/after duration, approved source selection and attributed policy revisions. The synthetic fall template is five minutes before/fifteen after; it is not a final operational policy. The current Devices reference limit is technical only and must not be relabelled as that policy.

Reconstruct using an ordered timeline with original/received times, clock uncertainty, historical assignment, unavailable/late sources and reviewer interpretation separated from facts. A window expansion creates a new attributed version. Do not infer a fall's cause or the person's identity from proximity.

Keep Live now, incident-time recording reference, preservation requested, provider-confirmed preservation and retained evidence distinct. Vendor retention is unknown unless actually returned. Preservation requires real retained bytes and an owning integrity/storage receipt; a configuration hash is not a clip hash.

Exit: fall/duplicate/second-room/delayed-event/permission-loss/partial-evidence journeys work across Devices, Control Room and H&S without duplicate incident ownership.

### 3. Network operations, alarms and response arrangements

Complete discovery/sensors/history and configurable monitoring thresholds, technical alert suppression/maintenance and supported device actions. Reuse current topology and current-authority history; stale data must not appear live.

Implement supported Ajax/UniFi alarm contracts and the internal/external/combined response policy. Track delivery, provider acknowledgement, human responsibility acceptance, escalation, fallback and handover independently. Transport acknowledgement must never imply a person accepted responsibility. Technical maintenance must not silently suppress a real fall or panic; bounded test mode is explicit.

Exit: outages, duplicates/replays, timeout/rejection, simultaneous responders and unaccepted responsibility remain visible and recoverable.

### 4. Access control and HR attendance

Default to evidence-assisted timekeeping. Offer manual/app, assisted reader suggestions and explicitly designated automatic attendance readers. HR owns canonical work time, corrections, approvals and export; My Day confirms/corrects starts, ends and breaks.

Bind stable credential/worker identity and reader direction/purpose at occurrence. The existing UniFi normaliser's display name/door-entry fields are not yet proof of that binding; never name-match a worker or convert every door opening into a clock-in.

Cover missed swipes, temporary exit, outreach/offsite work, travel/training, unscheduled/split/overnight shifts, sleepovers/on-call and NZ daylight-saving changes. Missing evidence creates a review exception, not zero time/pay. Preserve raw evidence and correction reasons; approved/exported periods use adjustment/reapproval.

Exit: identical source events produce one attributed suggestion/outcome; worker review and HR approval survive retries and conflicting evidence.

### 5. Care equipment and tracking

Extend the current Milesight and tracking foundations through the model/action matrix. Include care sensors, nurse-call/panic/welfare and relevant environment/power/maintenance equipment from the catalogue. Keep monitoring observations separate from clinical claims and person-identification assumptions.

Connect vehicles to Fleet, staff/lone-worker sessions to authorised work context and supported-person devices to permitted care records. Reuse canonical geofences/maps; preserve occurrence-effective assignment, stale/offline positions, battery/connectivity and history privacy. Configure check-in timers, missed welfare response, SOS/cancellation and escalating ownership.

Exit: offsite/offline/replayed events and reassignment cannot duplicate response or leak another person's location/history.

### 6. Complete native Windows RMM, remote support and Intune

Extend existing command/approval/consent/signed delivery/result infrastructure; do not replace it with generated-command execution. Finish actual installed screen viewing/control before exposing Connect as operational. Verify visible consent/Stop, permission revocation and expiry actually stop input/media.

Deliver remaining separately permissioned tools and their policy/recovery/outcome contracts: terminal/PowerShell, reviewed scripts, process/service control, file transfer, software/OS updates, event logs and network diagnostics. Preserve existing MSI and restart/service work. Capture independent readback and uncertain outcomes; a returned exit code is not always proof of intended repair.

Intune stays read-only. Match a managed device to its canonical record, retain last observation on sync failure, reject changed mappings mid-fetch and define one owner per overlapping software/update workload. Do not advertise native Android/macOS/Linux computer agents based on Intune visibility.

Exit: authorised and denied technician journeys, interrupted/lost results, consent changes and overlap conflicts behave correctly on signed installed test hosts.

### 7. Finish Obli and deployment lifecycle

Keep staff AI read-only, private and centrally budgeted. Complete explicit attachment/screenshot preview, voice start/stop, offline ticket drafts, notices, self-service catalogue and remote-support requests using existing IT records. Clear shared-PC data at sign-out; test privacy after account changes and lost responses. Apply separate retention and request cancellation.

Complete signed installer profiles for Computer/Site/Both and Linux site collectors: intended organisation/server review, enrolment, revoke/re-enrol, service identities, upgrade, repair, rollback and uninstall. Handle no logged-in user, reboot, offline buffering, credential rotation and invalid signatures. Clicking Download is never labelled installed.

Exit: real packages install and survive the complete lifecycle; one role cannot inherit another role's privileged credentials; no AI secrets ship to machines.

### 8. Complete-release verification and operations

Reconcile final provider/firmware/action support and all open evidence. Validate deployment backup/restore, secret rotation, observability, data retention, failure recovery and operator instructions. Run applicable cross-module regression once at stable integration, plus targeted security, migration and performance checks justified by changed risks.

Repeat the agreed synthetic/laboratory journeys with actual supported devices and licensed integrations. Signing certificates, hardware, partner APIs, customer monitoring contracts and actual deployment credentials are concrete dependencies to obtain when needed, not reasons to fabricate completion.

Exit: every declared supported capability meets the release gate below. External gaps remain visible; the complete goal cannot be marked achieved while required work or evidence is missing.

## Pilot and acceptance gate

Reuse DEVELOPMENT-PILOT.md's fictional Care Home and Outreach sites and devpilot identities. Correct its obsolete Linux computer-agent fixture to an inventory/visibility-only device or a Windows computer while retaining Linux site-collector coverage. Extend the 21-record baseline using the 24-family catalogue; counts are not a rollout limit. Do not create production addresses, people, credentials or vendor IDs.

For each declared capability require:
1. Implemented source tied to canonical ownership and permissions.
2. Meaningful positive/negative/recovery tests and original producer-to-consumer interoperability.
3. Real browser or native UI verification, including permissions, stale/empty/error states, long labels, keyboard, applicable narrow viewports and light/dark/branding.
4. Signed installed-host evidence where an agent/service/package is involved.
5. Exact supported vendor/model/firmware or licensed API witness where external behaviour matters.
6. Honest unavailable/unsupported states and documented operations/rollback.

Synthetic media and source tests are useful but never count as vendor or installed acceptance. No universal device compatibility claim.

## UI requirements — Rory's rules

Use DESIGN.md and its authoritative pattern guides. Shared PageHeader, Home-rooted breadcrumbs, existing shells, connected navigation, EntityCard/EntityTable, StatusBadge, semantic colours/typography and real scoped totals. Use WizardShell for multi-section setup/add/edit, shared calendar/time controls, scoped searchable record choices, shared file previews and canonical maps/geofences. Do not alter protected design guides to justify a one-off screen.

Show source time, freshness, supported capability, responsible person and useful next action in plain NZ English. Keep developer contract/protocol details in technical disclosure. Respect reduced motion and the native Orbit visual decision. A new decorative dashboard must not replace unfinished operational journeys.

## Coordination and resource discipline

The user previously authorised multiple GPT-6.1 Sol backend agents and a lead for frontend/architecture/integration. On implementation resume, use bounded parallel tasks only when genuinely independent: for example one PHP/API owner, one Go/C#/collector owner and lead frontend/integration. Have an independent review at security-sensitive boundaries. Assign exact file ownership and a termination condition. Do not spawn agents merely to reread the entire programme.

Keep exactly one owner of expensive isolated application-database suites; freeze related source before that suite. Batch meaningful tests, collect terminal output, reuse genuine exported fixtures and rerun only affected cases when a reviewed correction permits. Never repeatedly rebuild a database for unchanged evidence or perform unbounded polling. Record source changes, proof, remaining gaps and cleanup at package boundaries.

Use Extra High for the receiving lead as requested. Ultra is an optional escalation for a concrete unresolved security/architecture problem, not the default or an automatic setting change. There is no established credit-saving ratio for this task. Do not start schedules or a new unbounded goal from this handover alone.

H&S chat: “Audit health and safety UX”, thread01a11476-f5fc-72b2-b6d4-ba7e1188f514, host local. The original user explicitly requested coordination about incident reconstruction. That owner retains SD-01/SD-02, incident linking/window policy and preservation. Read its current doc/status before dependent changes:
C:/Users/steph/.codex/worktrees/health-safety-workspace/oblivionfindings/docs/health-safety-audit-2026-10-07/INCIDENT-RECONSTRUCTION.md.
Prior replies were recorded there; do not assume a callable route or merge exists. Do not silently copy that checkout's unrelated changes. Other chat messages alone do not confer permission to contact additional people/chats.

## Exact safe-stop evidence and immediate outstanding checks

On8October all parent/backend runners were stopped and owned temporary databases cleaned. These are historical records, not current running process claims:
- Recording-reference frontend:14 passing tests across3 files, including3 unchanged real PHP-producer consumers. Browser exercises passed ordinary workflow and four actual-projection Inertia navigations.
- PHP affected batch:21 passed,1 failed,407 assertions. The failure was an incorrect camera_move list expectation. Reviewed test-only correction saved; single-case rerun interrupted before assertions. Do not say all22 passed.
- Full TypeScript/scoped lint passed before final small additions; the final recheck was deliberately interrupted. It remains due.
- Original recording exports under storage/app remain accepted unmodified evidence: core SHA256 F52314DA66199ACEAC7CF027B5F4BE8771C29822368E88925B1C02794502A928; .withdrawn7105A8295C9792B8E30B40CEE30A866B5E16D9E8710DCAE61A0F0245B211E792; .pages2A7096739BB6BCBF4AA03EA98DDDCA99E7A507FD356E917A23786E7BBF440E37.
- Devices reference API: GET/POST /security-devices/devices/{camera}/recording-references; GET /security-devices/cctv/recording-references/{reference_uuid}; private recovery GET /security-devices/cctv/recording-references/requests/{request_key}?expected_actor_id={id}.
- Real internal resolver: app/Domain/SecurityDevices/Cctv/Services/CctvRecordingReferences.php::resolve(Request $request, string $uuid): array. An earlier cross-chat suggested ::read signature was explicitly corrected.
- Receipt schema1 is reference_only, preserved=false, recording_verified_at=null, vendor_retention_until=null, window_coverage=not_verified, binding_basis=reviewed_source_revision. Provider recorder ID is required. Separate current-source state never rewrites the immutable receipt.
- H&S has an incident-link proposal; no completed cross-branch incident-link implementation or preserved-footage capability is implied.

## Workstream document index

All paths below are relative to docs/native-endpoint-management in the implementation checkout:
- Overall: PROGRAMME.md, CHECKPOINT.md, OBLI-DECISIONS.md, IMPLEMENTATION.md.
- Network: TOPOLOGY-MAP.md.
- CCTV: CCTV-CONTRACT.md, HIKVISION-CONTRACT-DRAFT.md.
- Native remote support: REMOTE-SUPPORT.md, REMOTE-BROWSER.md, REMOTE-RELAY.md, REMOTE-WINDOWS.md.
- Native/Obli: STAFF-HELPER.md, TECHNICIAN-OBLI.md, approved-knowledge.md, obli-ticket-conversation.md, ticket-computer-association.md.
- Actions: SERVICE-ACTIONS.md, SCHEDULED-RESTART.md, SOFTWARE.md, SOFTWARE-CONTRACT.md, SCRIPT-CONTRACT-DRAFT.md (proposal, not completed implementation).
- Agent/collector lifecycle: INSTALLATION-PROFILES.md, COLLECTOR-ENROLMENT.md, collector-recovery-retention.md, WINDOWS-COLLECTOR.md, COMBINED-INSTALLATION.md, WINDOWS-EVENT-LOG.md, SERVICE-REPAIR.md.
- Release: RELEASE-DOWNLOADS.md, RELEASE-BUILDING.md, RELEASE-PUBLICATION.md, RELEASE-INSTALLATION.md; endpoint-agent/installer/README.md is relative to checkout root.

No current deadline or reliable total-effort estimate is established. Do not manufacture one from the amount of existing code. Preserve the user's complete intended outcome while proceeding in verifiable, affordable increments.
