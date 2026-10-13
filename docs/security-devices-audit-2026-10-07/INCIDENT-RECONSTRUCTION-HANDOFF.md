# Shared incident reconstruction: Security & Devices, Control Room and H&S

7 October 2026 · Shared contract coordinated with **Audit health and safety UX** (`01a11476-f5fc-72b2-b6d4-ba7e1188f514`). Planning only; this document does not authorize implementation. H&S's independent response and agreed refinements are recorded below.

## User requirement

The user wants live CCTV inside Oblivion, with recordings allowed to open the vendor system. Most importantly, a fall or similar event must be reconstructable from all relevant sensors, cameras, device events and other evidence. They explicitly asked this audit to inform the H&S audit chat and plan the work together. Tracking includes vehicles, staff/lone workers and people supported by Oblivion. Attendance must have a dedicated HR authority, potentially confirmed from My Day at timesheet submission.

## Proposed ownership

- Security & Devices owns canonical devices, provider connections, monitoring, raw/normalized source observations, historical assignments and supported device commands.
- Control Room owns live detection, acknowledgement, triage, responder dispatch, escalation and immediate response. Recovery of a device does not establish that a person is safe.
- The existing canonical incident journey owns incident identity and links to the H&S event. H&S owns investigation, review, corrective actions and assurance. Extend the existing sensor-confirmation bridge rather than creating another incident ledger.
- A shared evidence service stores immutable references/snapshots and exposes a permission-filtered timeline to Control Room and H&S. Neither UI duplicates the investigation record or bypasses the source domain.
- Vendor/NVR owns recordings unless an approved evidence export is required. Oblivion stores camera/provider/recording/time references, availability, expiry and preservation outcome; a link is not a preservation guarantee.
- HR/Attendance owns confirmed work time and timesheet effects; My Day offers confirmation/correction. Access-reader data remains source evidence until explicit attendance rules accept it.

All boundaries are one organisation with approved sites, roles, canonical ownership and privacy. Do not introduce tenant-based scope.

## Required shared evidence contract

1. Stable source type/ID, provider event ID, canonical device, approved site, permitted room/zone/person/staff/vehicle references and event family.
2. Event time in UTC, original timezone/offset when available, received time, precision/known clock offset and confidence. Preserve late-arrival status and gaps.
3. Historical device-to-room and device-to-person/asset assignment valid at occurrence. Current reassignment must not silently rewrite history or expose a new person's data.
4. Safe normalized evidence plus checksum/provenance, schema/version, correlation identity and ingestion/replay history. Avoid copying secrets or unrestricted medical/location data into a broad alert.
5. Incident/journey reference, collection time window, why each source was included, missing/offline/stale source list and whether collection is complete.
6. Camera/recorder IDs, authorized live-session capability, vendor recording time reference, expected expiry, requested preservation/export and actual acknowledged result.
7. An append-only investigation evidence set: reviewer notes and corrections are attributed additions. A later sensor event or timestamp correction does not overwrite earlier evidence unnoticed.
8. Enforce permissions for each source when displayed and exported. A common incident or site does not automatically grant access to camera footage, staff location, HR or care details.

## Existing seams to retain

- `SensorIncidentBridgeService` already confirms an alert into the canonical incident journey and carries sensor evidence. Extend its evidence reference rather than another incident creation path.
- `MonitoringIncidentEvidenceService` provides useful immutable-checksum patterns, but its owner is an IT ticket. Do not route every care incident through IT.
- H&S's existing integration specification already requires canonical source identity, safe returns, independent domain lifecycle and privacy. This reconstruction contract extends that work.

## Priority source defects from the audit

**SD-01:** Milesight falls become `fall_detected`/critical at ingestion but the integration normalizer has no matching explicit registration; it falls back to `integration_unknown` under checked-in defaults. It omits canonical-device context downstream and uses null raw payload rather than the stored normalized evidence. Generic correlation can therefore match different devices by site. The incident bridge infers `fall` only for exact `fall_detected`. Severity remains critical; do not describe this as a severity downgrade. The existing Milesight contract test mocks alert routing.

**SD-02:** The shared processor suppresses signals during matching maintenance windows before evaluating alert rules, without a life-safety/critical exemption. A site-wide maintenance window can therefore mute safety events. Confirm the desired sensor-test workflow and technical-maintenance distinction.

See [audit findings and source references](AUDIT.md) for exact code and verification limits. Neither issue was exercised on a deployed system in this audit.

## Joint delivery sequence

1. Agree canonical event classification, urgent-response policy and safe correlation identity between Devices and Control Room. Implement SD-01/02 fixes with cross-boundary tests when implementation is authorized.
2. Devices supplies a verified event/evidence interface and a supported CCTV live/recording-reference interface; H&S defines the shared investigation timeline view and disclosure rules using its dedicated incident workspace proposal.
3. Control Room can launch a bounded evidence collection for an alert, confirm/link the canonical incident, and retain real-time response state. H&S can open that same evidence set later and expand the time window with provenance.
4. Keep unavailable sources visible. Do not invent fall causation, person identity, exact camera time or completeness when the source cannot establish it.
5. Independently validate retention/preservation with the selected NVR. Alert when footage will expire or preservation was refused/failed. Keep notes/evidence refs if recordings expire, clearly labelled unavailable.
6. Deliver a witnessed pilot using the actual supported Milesight model, UniFi camera/NVR and site context, then expand vendors.

## Shared acceptance scenarios

- R01: Verified Milesight fall → urgent Control Room alert → confirm/link incident → same H&S investigation, with source identity and no duplicate record.
- R02: Duplicate webhook/retry produces one source event and intended alert; two devices at the same site remain distinguishable.
- R03: Fall/panic during technical maintenance still follows the agreed safety response. Deliberate sensor test is explicitly identified, bounded and audited.
- R04: Incident window includes sensor, camera reference, door/access event, permitted tracking and response actions in a common timeline. Unavailable cameras/sensors are labelled.
- R05: Offline buffering and delayed delivery preserve occurrence/receipt times; clock skew is visible and does not manufacture exact ordering.
- R06: Device reassignment after an event does not rewrite historical location/person evidence or grant unrelated access.
- R07: A worker permitted to report an incident cannot thereby view restricted CCTV, staff location or other-person data. Exports apply the same checks.
- R08: Provider footage retention expires or preservation fails: show the actual state, never claim an archived clip exists.
- R09: Reopening or correcting an incident retains original evidence and a recorded amendment; closure of H&S does not silently close operational response or source-domain work.
- R10: Access events can suggest attendance only through HR's explicit rules; correction/submission in My Day retains provenance and approval history.

## Coordination status

The audit and handoff were sent under the user's explicit cross-chat instruction. H&S independently inspected SD-01/SD-02, agreed with both source findings and accepted the domain split and R01–R10 scenarios. See its [planning response](../health-safety-audit-2026-10-07/INCIDENT-RECONSTRUCTION.md).

H&S incorporated the requirement into [DELIVERY-PLAN.md](../health-safety-audit-2026-10-07/DELIVERY-PLAN.md), SPECIFICATION.md and INTEGRATIONS.md. SD-01/SD-02 are early shared prerequisites; HS22c covers shared evidence, HS22d covers footage preservation/live and vendor references, and HS26–27 include the joint hardware witness. Ordinary incident-page improvements can proceed separately, with reconstruction labelled incomplete until its own acceptance passes.

The Devices audit accepts these H&S refinements:

- Keep the nullable legacy Control Room device foreign key valid; propagate canonical device identity through its correct contract, never by putting a different table's ID into that foreign key.
- A metadata checksum is not proof of the integrity or retention of video never obtained. Record vendor preservation/export acknowledgements separately.
- Label live viewing as **Live now**. Historical playback must identify the incident time and whether seeking to that time is supported.
- Collection completeness is bounded by the declared source set and time window; never imply every possible event was found.
- Preserve historical assignment/context while evaluating current source access. Expanding a window needs a purpose, bounded scope and a new evidence-set version.

The user's subsequent answers are captured in [TARGET-SCOPE.md](TARGET-SCOPE.md): development-only synthetic pilot; broad market research; configurable internal/external/combined response, evidence trigger/window/sources and source permissions; recommended customisable HR attendance; deployment-variable endpoint modes; one complete release covering the agreed scope; Rory's rules. This **supersedes** the earlier suggestion that an ordinary incident-page change could ship independently within this programme. Work packages may be developed and tested independently, with one final release gate. Specific policy values remain deployment configuration rather than hardcoded assumptions. No application implementation was performed by this coordination.
