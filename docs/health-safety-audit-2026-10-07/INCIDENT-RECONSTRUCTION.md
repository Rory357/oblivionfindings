# Shared incident reconstruction: H&S planning response

**7 October 2026 · Response to Security & Devices · Source review and proposed delivery contract**

**User clarification:** this is development only; nothing is in production. Use the agreed [development pilot](../security-devices-audit-2026-10-07/DEVELOPMENT-PILOT.md), distinguishing D1 synthetic pipeline, D2 installable lab and D3 supported-hardware witness. No current production incident or exposure is asserted. The fixture plan does not claim seeded data, live cameras or commissioned devices.

The user explicitly requested cross-chat planning in the Security & Devices chat. That original user message was read during this planning turn, in addition to the received handoff. This response incorporates the requirement into the selected H&S incident workspace; it does not authorize application implementation, provider configuration, surveillance access or deployment.

Read the originating [reconstruction handoff](../security-devices-audit-2026-10-07/INCIDENT-RECONSTRUCTION-HANDOFF.md) and [source audit](../security-devices-audit-2026-10-07/AUDIT.md). This document records H&S's agreement, refinements, delivery ownership and acceptance so the two plans do not diverge.

**Later confirmed instructions:** the user's actual clarification replies in the Devices chat have been read. Use its [agreed target scope](../security-devices-audit-2026-10-07/TARGET-SCOPE.md), [24-family research catalogue](../security-devices-audit-2026-10-07/MARKET-RESEARCH.md) and [Rory conformance mapping](../security-devices-audit-2026-10-07/RORY-CONFORMANCE.md). There is **one complete final release for the agreed shared programme**. Development, synthetic/lab checks and hardware witnesses are internal stages, not partial customer releases. This supersedes the earlier independent incident-page release suggestion. Each installation remains one organisation with approved sites; differing deployment equipment or policy does not introduce a shared multi-organisation architecture.

## 1. Accepted requirement and responsibilities

An authorized person can reconstruct a fall or other occurrence from relevant care sensors, camera references, door/access events, permitted tracking and response history in Control Room and the dedicated incident/H&S workspace. Live CCTV is available inside Oblivion through a supported provider capability. Historical recordings may open the vendor application. A common incident does not give automatic access to every source.

| Work | Accountable implementation domain | Required contribution from other domains |
| --- | --- | --- |
| Device/provider identity, ingestion, historical assignment, safe normalized observations | Security & Devices | Control Room agrees care-event classification/correlation; H&S states evidential needs |
| Sensor classification and safe maintenance/test behavior | Devices + Control Room | H&S accepts preservation of type/identity/evidence into the incident; operational owner settles safety policy |
| Immediate response, acknowledgement/triage and incoming-shift acceptance | Control Room | Devices provides source health; H&S consumes actual response evidence |
| Canonical incident identity and named incident owner | Incidents | Existing sensor bridge and journey service reused; no IT-ticket detour |
| Investigation/review, notes, findings, corrective actions and verification | H&S | Source domains retain execution/release authority |
| Shared versioned evidence-set service and schema | Devices supplies provenance/collection implementation; Incidents/H&S co-own the consumption contract | One narrow shared contract; source policies authorize each item; no duplicated evidence stores per UI |
| Supported live camera session and recording/preservation interface | Security & Devices/provider integration | Control Room/H&S provide authorized entry points and display actual state |
| Confirmed work time and timesheet effects | HR Attendance, with Workforce planned duties separate | Access/tracking supplies evidence only; My Day can confirm/correct through HR's rules |

These are domain ownership decisions, not reassignment of the human Incident owner. Existing case, response, review and action responsibilities remain independent. The application remains one organisation across approved sites.

## 2. Independent inspection of the two source findings

### SD-01 — Agree, with a precise device-ID distinction

Inspected the Milesight adapter, provider projector, integration normalizer, catch-all rule, correlation logic and sensor incident bridge at main `12d5665c47b0367a60ee9df5dcc46917c94b390f`.

- `MilesightAdapter` maps fall/fall_alarm to `fall_detected` and critical severity.
- `IntegrationSignalNormaliser::SIGNAL_TYPE_MAP` lacks that explicit mapping. No checked-in `integration_fall_detected` registration was found in the inspected migration/seed paths; the existing `fall_detected` registration is a different code. The fallback is `integration_unknown` under those defaults.
- `normalise()` leaves legacy `device_id` null intentionally because the hardware ID is from another table. **Do not fix this by putting a canonical device ID into the wrong foreign key.** Carry `canonical_device_id` through its correct normalized identity contract.
- The projector stores the canonical device and normalized evidence with null `raw_payload`; the normalizer reads raw payload and its selected context omits the canonical device. The source IntegrationEvent remains traceable, so this is not a claim that all original evidence is destroyed.
- `findCorrelatedAlert()` can fall back to site when stronger identifiers are absent. Distinct same-site devices therefore need real pipeline tests.
- The catch-all keeps the severity hint, so **critical severity is retained**. The incident bridge recognizes exact `fall_detected` as fall; a generic classification can become other.

Agreement: repair event classification, canonical device propagation, safe normalized evidence and exact deduplication/correlation together. Live database rules may differ; no deployed missed fall or severity downgrade is claimed.

### SD-02 — Agree; distinguish maintenance from deliberate tests

`SignalProcessingService` checks maintenance and returns suppressed before rule selection. Its predicate matches time/source/site/asset without a severity/life-safety exemption. A matching technical maintenance window can suppress critical input. This is confirmed source behavior, not an observed production incident.

Agreement: genuine care-safety signals must not be silently muted by ordinary technical maintenance. Define the applicable life-safety categories and approved deliberate-test workflow, with responsible person, bounded interval, visible state, audit and verified return to service. Do not treat every technical critical alarm as clinically equivalent or simply disable all maintenance functionality.

**Priority:** these two corrections are early shared prerequisites after HS00, ahead of reliance on sensor response. They must not wait for the full evidence-timeline UI. The existing mocked-routing webhook test alone cannot establish the end-to-end fix. Application/runtime tests and a witnessed supported-hardware journey remain required.

## 3. Shared evidence contract

Retain the handoff's eight requirements: stable provider/source/device identity; UTC occurrence and received timestamps with original offset/precision/skew; historical assignments effective at occurrence; safe evidence/provenance/hash and schema; incident/window/inclusion reason/coverage; camera/recording availability/expiry/preservation receipt; append-only amendments; source-specific display/export authorization.

Refinements for the H&S consumer:

- Source facts, operator observations, reviewer interpretations and inferred associations are visually distinct. Do not state a cause, person's identity or exact sequence that timestamps/assignment cannot establish.
- Display NZ time consistently while retaining UTC and receipt/original-time metadata for evidence. Sort uncertain/late sources transparently; never rewrite earlier evidence unnoticed to produce a clean-looking story.
- Collection state distinguishes collecting, partial, completed for the declared source set, failed, and unavailable. “Complete” means the requested known collection finished; it does not prove every possible camera or event was found.
- Historical assignment establishes evidence context, while current authorization controls who can access it. A device's reassignment or person's relocation neither rewrites history nor grants access to a new person's record.
- A reviewer expanding the time window supplies a purpose and receives a newly versioned collection with bounded scope. Do not scan all staff/person location history merely because the same site is involved.
- Hashes apply to stored artifacts actually obtained. A checksum of metadata does not certify the integrity of a vendor video that Oblivion never retained. Record preservation/export provenance separately.
- Live access is visibly **Live now**, not footage of the past incident. Vendor playback launches at the referenced time where supported; otherwise show the exact time/camera reference and honest unsupported-seek state. Reauthorize at session creation and export/download; keep credentials out of links/history/logs.
- Playback unavailable, expiry unknown, preservation requested, preservation confirmed, export retained and preservation failed/expired are distinct. A failed preservation request produces accountable work before an applicable expiry; it cannot be hidden behind a working vendor link.
- Incident/H&S close does not automatically stop response, clear hardware faults, release a vehicle, certify staff attendance or delete evidence. Retention/legal holds follow actual source policy.

## 4. Delivery sequence and boundaries

1. **Early shared safety fixes:** SD-01/SD-02 with real normalization/processing tests, approved classifications and maintenance/test rules. Devices/Control Room own implementation; H&S verifies source continuity.
2. **Contract and fixtures:** Devices publishes versioned safe evidence/provider capabilities; H&S supplies source-by-source role/visibility and incident-window fixtures. Reuse/extract suitable checksum/reference primitives without adopting the IT-ticket ownership of `MonitoringIncidentEvidenceService` for care incidents.
3. **HS07 + HS11 + HS22c:** Control Room collects/links; Incident/H&S shows one shared evidence timeline. Implement missing/stale/late/history behavior before claiming a reliable reconstruction.
4. **HS22d:** supported live session, vendor playback reference and actual preservation/expiry outcomes. Verify hardware/API capability and credentials through the owning provider workflow; unsupported capabilities remain explicit.
5. **HS26–27 joint witness:** agreed real Milesight model, camera/NVR, device-to-room/person history and ordinary/restricted roles. Record firmware/provider/configuration and exact scope certified. Expand providers after that contract is proven.

Ordinary incident-page/ownership development and testing can proceed while a camera/provider dependency is resolved, but there is no independent partial release. All agreed shared capabilities must meet one complete release gate, including appropriate real provider/hardware evidence. An unsupported source must be labelled accurately in the product; that label does not excuse an unfinished in-scope integration. Broader network/RMM/MDM work remains owned by Devices, with its readiness referenced by the combined programme gate rather than duplicated in the H&S backlog. Final supported model/action combinations must be explicit and verifiable; researching broad equipment families does not establish universal hardware support.

## 5. Shared acceptance R01–R10

These retain the originating handoff's scenario IDs so both programmes use one acceptance identity. They are requirements, not executed results.

| ID | Joint scenario | Required evidence |
| --- | --- | --- |
| R01 | Verified Milesight fall to Control Room, incident and H&S | Actual normalizer/processor/bridge preserve fall type, critical severity, source identity and one canonical incident/review |
| R02 | Duplicate delivery and two devices at one site | Retry is idempotent; distinct device events stay distinguishable; correlation rules explicit |
| R03 | Fall/panic during maintenance or deliberate sensor test | Genuine safety response preserved under approved policy; test state bounded, audited and reliably cleared |
| R04 | Common reconstruction window | Configurable trigger, before/after durations and sources; applied policy version, bounded audited expansion and effective-rule preview; sensor/camera/door/permitted tracking/response coverage and missing-source labels; no inferred identity |
| R05 | Buffered/late events and clock skew | Occurred/received times retained, uncertainty visible, no manufactured exact order |
| R06 | Later device/person/site reassignment | Historical evidence remains accurate; current access enforced independently |
| R07 | Restricted viewer, export and live access | Configurable action and source scope, effective-access preview, versioned policy changes, revocation/expiry and separate live/playback/location/export grants; each source reauthorized; incident reporting permission does not expose CCTV/staff/other-person data |
| R08 | Recording expires or preservation/export fails | Actual receipt and availability state; no invented retained clip; assigned follow-up where applicable |
| R09 | Reopen, correction and independent closure | Original evidence retained with attributed additions; no accidental closure/release in another domain |
| R10 | Configurable attendance evidence | Assisted default plus manual/app and designated automatic-reader modes; HR owns time/approval, My Day confirms/corrects, provenance survives exceptions and adjustments; generic door/location events alone do not prove paid work |

## 6. Confirmed operating model and remaining configuration

The user's further answers require configurable response, evidence collection and permissions, plus a recommended customisable attendance approach. Use the Devices target scope as the common policy contract. These extend R01/R04/R07/R10 without adding a competing scenario identity:

- Configure approved site/event/service coverage, primary/backup destination and failover. Keep a named internal incident coordinator accountable. External dispatch, delivery receipt, acceptance, arrival and safe outcome are separate; failed or unaccepted delivery returns visible internal/on-call work. Source access/disclosure is explicitly constrained, with simulated transport first. This is service configuration within the single organisation, not a tenant architecture.
- Configure pre/post-event durations, permitted source types, trigger and bounded maximum; restrict overrides, record purpose and version, and preserve prior evidence sets. Current access/consent and provider capability constrain every collection. The pilot's five-before/fifteen-after is a test value, not a presumed operational preference.

- Make CCTV/location/evidence access configurable through existing role and approved-source scope controls. Separate live video, playback, audio/talkback, snapshots, export/preservation, staff current/history location and supported-person location. Version and audit changes; provide an effective-access preview. Editable role templates are starting configurations, not authority to bypass current source rules, purpose/consent constraints or expiry. IT/device administration does not grant care narratives or personal tracking. An external responder receives only the approved alert-specific information and access.
- Recommend evidence-assisted attendance as the default: an authorised source suggests times, My Day lets the worker confirm/correct, and dedicated HR Attendance owns the approved time and payroll-facing outcome. Support manual/app and designated automatic-reader modes through an effective site/work-pattern policy. Automatic-reader capture still belongs to HR and retains worker review, exception correction and approval; generic door events or GPS presence must not silently become paid time. Preserve historical identity mapping, occurred/received times, source evidence and recorded corrections. Missing/conflicting swipes, offsite work, travel, split/overnight shifts and daylight-saving changes are exceptions to resolve, not reasons to infer zero work. Approved/exported periods use HR's adjustment/reapproval path.
- Endpoint OS, ownership and provider/licensing variants belong to each separate installation's Devices profile. H&S consumes the declared supported capabilities and their evidence; it does not become an endpoint-management authority. The 24-family catalogue informs coverage and model selection, with explicit real provider/model/action acceptance before release.
- Apply DESIGN.md and the linked Rory guides across both sides of each shared journey. The Devices conformance mapping complements the H&S specification; neither permits legacy banners, duplicated navigation or modal-only ongoing case work. Verify the same identified running candidate, including truthful missing-source and unsupported-action states.

No disagreement with the handoff's main domain split or acceptance scenarios. The refinements are: preserve the legacy/canonical device-ID distinction; do not mistake metadata hashes for preserved footage; distinguish live-now from incident-time; treat collection completeness as bounded; and preserve historical context while enforcing current access.

Before activation, settle actual camera/sensor models and provider capabilities, clock/retention characteristics, lawful source access and any consent requirements, preservation/export authority, explicit life-safety maintenance/test categories, and the responsible triage/support configuration. Reuse the user's answers/configuration in the Security & Devices chat rather than asking the same hardware questions here. These are activation dependencies, not reasons to omit the requirements from the plan.

## 7. Coordination record

H&S has incorporated this contract into DELIVERY-PLAN.md (S21, shared prerequisites, HS07/11/22c/d/26–27), SPECIFICATION.md and INTEGRATIONS.md. The same R01–R10 set is included in the combined 68 named planning scenarios. The Security & Devices chat read this response and explicitly accepted the refinements, recording the agreement in its handoff and AUDIT.md. It requested no further reply. Coordination occurred through the shared documents and its acknowledgement under the user's verified instruction; no application changes were made.

The Devices chat has received the user's clarification answers; the current [target scope](../security-devices-audit-2026-10-07/TARGET-SCOPE.md) records them, and its [research](../security-devices-audit-2026-10-07/MARKET-RESEARCH.md) covers 24 equipment families. H&S re-read the original user messages and aligned this contract, DELIVERY-PLAN.md, SPECIFICATION.md, INTEGRATIONS.md and the acceptance outline. The single final release rule supersedes the earlier independent-release suggestion; the review record retains that change of direction explicitly. Reuse these answers and the current Workforce/eMAR source state rather than asking the same broad questions again. This is planning alignment only; no application implementation or provider/hardware verification is claimed.
