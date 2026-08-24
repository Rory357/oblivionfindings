#!/usr/bin/env python3
"""Apply the independently corrected third project-specific catalogue wave."""

from __future__ import annotations

import csv
import hashlib
import json
from collections import Counter
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
REGISTER = AUDIT / "06-open-source-benchmark-register.csv"
OUTPUT = AUDIT / "evidence" / "source" / "project-specific-triage-wave3-2026-08-14.json"
INPUT_SHA = "10d239a49b4035fc591e02d2f31015d3f269f6739d29e74cff6910c557b462fe"
OUTPUT_SHA = "d8c7c5ed79e7790413cc2343803471a44a7b5dc8b4ba86c33821fd7c75e0b7c8"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def row(project: str, **updates: str) -> dict[str, str]:
    return {
        "project": project,
        "inspected_date": "2026-08-14",
        "observer_agent": "project_triage_wave3_recovery",
        "neutral_writer_agent": "root",
        "native_comparator_agent": "p8_adversarial",
        **updates,
    }


ROWS = [
    row(
        "apache/superset",
        release_activity_signal="commit=2026-08-11T23:07:58Z; pinned SHA reachable; archived=false",
        edition_boundary="Pinned Apache-2.0 repository only; external database engines, deployment services and vendor distributions excluded.",
        exact_behaviour_screen_workflow_inspected="The protected scheduled-report API exposes persisted report-definition, schedule, owner, recipient and action-configuration CRUD. It does not prove report execution or delivery. Exact official locus: https://github.com/apache/superset/blob/5248367587051278d815c5d8ef8dc23f18264b0e/superset/reports/api.py#L78-L210",
        related_feature_ids="REP-REPORTS",
        strengths="Persisted scheduled-report configuration and an explicit protected API action surface.",
        limitations="No source-system transaction completion, Oblivion Site/privacy scope, recipient authority, delivery receipt or retry recovery proof.",
        benchmark_outcome="Native benchmark",
        neutral_requirements_extracted="Persist a report definition, schedule, owner and recipients; authorize every read and mutation; retain separate delivery attempt, outcome and recovery evidence.",
        security_or_operational_caveat="The cited API proves schedule configuration, not row-level data scope, recipient authorization, execution or successful delivery.",
        reason_selected_or_excluded="Selected narrowly for scheduled-report definition mechanics; no dashboard-wide or delivery parity is inferred.",
    ),
    row(
        "chirpstack/chirpstack",
        release_activity_signal="commit=2026-07-31T09:00:58Z; pinned SHA reachable; archived=false",
        edition_boundary="Pinned MIT ChirpStack server repository only; gateways, device firmware, hosted services and third-party integrations excluded.",
        exact_behaviour_screen_workflow_inspected="Device downlink queue operations validate create, delete and list access for the canonical device EUI, optionally flush prior items, persist payload/encryption/count/confirmation/expiry fields, and return a queue identity. Exact official locus: https://github.com/chirpstack/chirpstack/blob/8c30625090aa9f5e14783cf380d9a3e6d42907e8/chirpstack/src/api/device.rs#L1037-L1181",
        related_feature_ids="CAP-SEC-QUECLINK-HUB-COMMANDS | SEC-INTEGRATIONS-HUB",
        strengths="Device-bound permission checks, explicit queue state, expiry, confirmation metadata and persisted command identity.",
        limitations="Generic downlink mechanics only; no Queclink protocol handling, supported-person consent, temporal custody, Site routing, safety meaning, delivery acknowledgement or failed-command recovery.",
        benchmark_outcome="Native benchmark",
        neutral_requirements_extracted="Resolve the canonical device; authorize create/delete/list separately; persist command identity, payload metadata and expiry; distinguish queued, delivered, acknowledged and failed outcomes.",
        security_or_operational_caveat="A queued downlink is not proof of device receipt or action; network-server permissions are not an Oblivion safety boundary.",
        reason_selected_or_excluded="Selected narrowly as a generic permissioned device-command queue analogue.",
    ),
    row(
        "Combodo/iTop",
        release_activity_signal="commit=2026-08-11T13:28:30Z; pinned SHA reachable; archived=false",
        edition_boundary="Pinned AGPL iTop repository only; official or commercial extensions, hosted operation and downstream customizations excluded.",
        exact_behaviour_screen_workflow_inspected="The incident model declaratively defines assignment, pending, resolved, closed and reopen stimuli, state-specific mandatory/read-only fields, assignment timestamps and constrained resolve/close/reopen transitions. Exact official loci: https://github.com/Combodo/iTop/blob/36fae27355f9c7e280705178ba484ec2e6ae82f0/datamodels/2.x/itop-incident-mgmt-itil/datamodel.itop-incident-mgmt-itil.xml#L541-L610 | https://github.com/Combodo/iTop/blob/36fae27355f9c7e280705178ba484ec2e6ae82f0/datamodels/2.x/itop-incident-mgmt-itil/datamodel.itop-incident-mgmt-itil.xml#L738-L806 | https://github.com/Combodo/iTop/blob/36fae27355f9c7e280705178ba484ec2e6ae82f0/datamodels/2.x/itop-incident-mgmt-itil/datamodel.itop-incident-mgmt-itil.xml#L1035-L1070",
        related_feature_ids="CAP-OPS-CLIENT-NOTE-REVIEW",
        strengths="Explicit owner/state machine, required solution fields, assignment time, resolution, closure and reopen paths.",
        limitations="Generic queue lifecycle/SLA machinery only; no healthcare incident semantics, clinical review authority, notifiability, Site/privacy scope, evidence chain or effectiveness review.",
        benchmark_outcome="Native benchmark",
        neutral_requirements_extracted="Give each review item an accountable owner, allowed state transitions, required resolution evidence and timestamps; preserve reopen history and next-owner visibility.",
        security_or_operational_caveat="Declarative lifecycle configuration does not itself prove runtime authorization, concurrency control or behavior supplied by excluded extensions.",
        reason_selected_or_excluded="Selected narrowly for generic review-queue lifecycle mechanics.",
    ),
    row(
        "dimagi/commcare-hq",
        release_activity_signal="commit=2026-08-11T19:08:35Z; pinned SHA reachable; archived=false",
        edition_boundary="Pinned CommCare HQ server repository only; CommCare mobile, hosted commcarehq.org, deployment tooling and paid services excluded.",
        exact_behaviour_screen_workflow_inspected="The case-update API distinguishes create, update and upsert, rejects missing/unknown identities and oversized bulk updates, resolves temporary/external IDs, validates access to owners and indexed cases, submits case blocks and returns persisted cases. Exact official loci: https://github.com/dimagi/commcare-hq/blob/5a8ed3420745c07e342865f49432fba16fcfcb3c/corehq/apps/hqcase/api/updates.py#L149-L216 | https://github.com/dimagi/commcare-hq/blob/5a8ed3420745c07e342865f49432fba16fcfcb3c/corehq/apps/hqcase/api/updates.py#L239-L297 | https://github.com/dimagi/commcare-hq/blob/5a8ed3420745c07e342865f49432fba16fcfcb3c/corehq/apps/hqcase/api/updates.py#L347-L391",
        related_feature_ids="CAP-OPS-CUSTOM-FORM-SUBMISSION | CLI-CLIENT-ASSESSMENT",
        strengths="Explicit operation semantics, canonical identifier checks, bounded bulk intake and linked-owner permission validation.",
        limitations="Server-side case mutation only; no custom-form rendering, assessment UX, Oblivion client identity, Site access, clinical protocol validation, approval or handover completion.",
        benchmark_outcome="Native benchmark",
        neutral_requirements_extracted="Bind every submission to the canonical client/case and authorised owner; reject forged or missing linked IDs and oversized batches; preflight all relations before persistence.",
        security_or_operational_caveat="CommCare owner semantics must not be imported as Oblivion Site authority; downstream transaction/conflict behavior is outside the cited slice.",
        reason_selected_or_excluded="Selected narrowly for case/form submission identity and authorization boundaries.",
    ),
    row(
        "earthians/marley",
        release_activity_signal="commit=2026-08-11T05:59:49Z; pinned SHA reachable; archived=false",
        edition_boundary="Pinned GPL Marley healthcare app only; external Frappe/ERPNext framework services, Frappe Cloud, other apps and billing services excluded.",
        exact_behaviour_screen_workflow_inspected="Marley-owned appointment validation derives schedule state, rejects patient/practitioner overlap and service-unit capacity conflicts, and supports check-in/cancel. Its inpatient medication entry selects eligible orders, updates completion counts and links stock effects through external Frappe/ERPNext services. Exact official loci: https://github.com/earthians/marley/blob/2338d889c1af2ca8555052cad44ec05a98cdd2af/healthcare/healthcare/doctype/patient_appointment/patient_appointment.py#L49-L60 | https://github.com/earthians/marley/blob/2338d889c1af2ca8555052cad44ec05a98cdd2af/healthcare/healthcare/doctype/patient_appointment/patient_appointment.py#L130-L234 | https://github.com/earthians/marley/blob/2338d889c1af2ca8555052cad44ec05a98cdd2af/healthcare/healthcare/doctype/patient_appointment/patient_appointment.py#L1031-L1067 | https://github.com/earthians/marley/blob/2338d889c1af2ca8555052cad44ec05a98cdd2af/healthcare/healthcare/doctype/inpatient_medication_entry/inpatient_medication_entry.py#L53-L127 | https://github.com/earthians/marley/blob/2338d889c1af2ca8555052cad44ec05a98cdd2af/healthcare/healthcare/doctype/inpatient_medication_entry/inpatient_medication_entry.py#L145-L205",
        related_feature_ids="CAP-OPS-CLIENT-CALENDAR | CAP-MED-EMAR-WORKSPACE | CAP-MED-MEDICATION-ORDER-LIFECYCLE",
        strengths="Patient/practitioner/time binding, overlap and terminal-state guards, appointment states, medication-order selection/completion and stock linkage.",
        limitations="No full bedside eMAR, five-right administration, worker competency, controlled-drug rules, PRN/override governance, Site/privacy authority or concurrent-dose guard.",
        benchmark_outcome="Native benchmark",
        neutral_requirements_extracted="Bind resident, practitioner, location and order; block overlap and terminal replay; persist explicit states; make medication and stock consequences atomically and reversibly evidenced.",
        security_or_operational_caveat="Permission authority and key stock services are outside the cited Marley-owned models; generic Frappe roles are not Oblivion Site scope.",
        reason_selected_or_excluded="Selected narrowly for appointment integrity and medication-order/stock linkage, with the external framework boundary explicit.",
    ),
    row(
        "edgexfoundry/device-sdk-go",
        release_activity_signal="commit=2026-08-06T10:45:46Z; pinned SHA reachable; archived=false",
        edition_boundary="Pinned Apache-2.0 Go device SDK only; EdgeX core services, concrete device services, hardware drivers and vendor integrations excluded.",
        exact_behaviour_screen_workflow_inspected="GET and SET command paths bind device/profile, reject locked/down/unprofiled services or devices, enforce read/write direction and maximum operations, invoke the driver, transform results and optionally emit an event while updating last-connected state. Exact official loci: https://github.com/edgexfoundry/device-sdk-go/blob/71eac3ea65cf19f4737c569a63d2974c7fa7b4b4/internal/controller/http/command.go#L28-L100 | https://github.com/edgexfoundry/device-sdk-go/blob/71eac3ea65cf19f4737c569a63d2974c7fa7b4b4/internal/application/command.go#L37-L114 | https://github.com/edgexfoundry/device-sdk-go/blob/71eac3ea65cf19f4737c569a63d2974c7fa7b4b4/internal/application/command.go#L221-L275 | https://github.com/edgexfoundry/device-sdk-go/blob/71eac3ea65cf19f4737c569a63d2974c7fa7b4b4/internal/application/command.go#L278-L482",
        related_feature_ids="CAP-SEC-QUECLINK-HUB-COMMANDS | SEC-ASSET-TELEMETRY-INGEST",
        strengths="Fail-closed device/service state validation, profile/resource binding, direction/size guards, driver execution, correlated result conversion and liveness update.",
        limitations="Generic SDK behavior only; no Queclink protocol, complete telemetry store, end-user authorization, custody, consent, Site assignment, command approval or delivery acknowledgement.",
        benchmark_outcome="Native benchmark",
        neutral_requirements_extracted="Resolve the registered device/profile; reject invalid state, direction or operation count; execute through the governed driver; persist correlated outcome and liveness evidence.",
        security_or_operational_caveat="The HTTP slice does not establish end-user authorization, and asynchronous event sending has no delivery-recovery proof in the cited path.",
        reason_selected_or_excluded="Selected narrowly for generic device-command validation and execution boundaries.",
    ),
    row(
        "edgexfoundry/edgex-go",
        release_activity_signal="commit=2026-08-06T10:46:29Z; pinned SHA reachable; archived=false",
        edition_boundary="Pinned Apache-2.0 EdgeX core/support repository only; SDKs, device services, drivers, user interfaces and cloud connectors excluded.",
        exact_behaviour_screen_workflow_inspected="Core Data rejects route/body profile, device or source mismatches and oversized events, then persists or publishes events/readings and queries them by device/time. Exact official loci: https://github.com/edgexfoundry/edgex-go/blob/2bee238e8510a15974a23743e1f7ddd35801407b/internal/core/data/controller/http/event.go#L61-L117 | https://github.com/edgexfoundry/edgex-go/blob/2bee238e8510a15974a23743e1f7ddd35801407b/internal/core/data/application/event.go#L29-L90 | https://github.com/edgexfoundry/edgex-go/blob/2bee238e8510a15974a23743e1f7ddd35801407b/internal/core/data/application/event.go#L200-L251",
        related_feature_ids="SEC-ASSET-TELEMETRY-INGEST",
        strengths="Route/body identity consistency, request-size guard, event/reading persistence or publication and device/time retrieval.",
        limitations="No Queclink frame decoding/diagnostics, supported-person binding, consent, temporal custody, Site/privacy scope, safety severity, alert lifecycle or retention approval.",
        benchmark_outcome="Native benchmark",
        neutral_requirements_extracted="Require route/body device identity parity; validate size/schema; persist event/readings with correlation; query the canonical device/time; govern retention separately.",
        security_or_operational_caveat="Publish mode may not provide the same local durability; generic device identity is not an Oblivion safety or privacy boundary.",
        reason_selected_or_excluded="Selected narrowly for generic telemetry-ingest identity, persistence/publication and retrieval boundaries.",
    ),
    row(
        "excalidraw/excalidraw",
        release_activity_signal="commit=2026-08-11T13:59:35Z; pinned SHA reachable; archived=false",
        edition_boundary="Pinned MIT editor and showcase app source only; external collaboration backends, hosted services, integrations and third-party libraries excluded.",
        exact_behaviour_screen_workflow_inspected="History records non-empty durable deltas, maintains undo/redo stacks, clears redo after a new local change, applies deltas to snapshots and exposes disabled undo/redo actions when stacks are empty. Exact official loci: https://github.com/excalidraw/excalidraw/blob/abeeaeba217ab3b5193b78c8d8d63c373b518ced/packages/excalidraw/history.ts#L90-L153 | https://github.com/excalidraw/excalidraw/blob/abeeaeba217ab3b5193b78c8d8d63c373b518ced/packages/excalidraw/history.ts#L159-L229 | https://github.com/excalidraw/excalidraw/blob/abeeaeba217ab3b5193b78c8d8d63c373b518ced/packages/excalidraw/actions/actionHistory.tsx#L63-L137",
        related_feature_ids="CAP-SITE-SITE-TYPE-PLAN-EDITOR",
        strengths="Explicit reversible edit history, snapshot application, redo invalidation and honest disabled-action state.",
        limitations="Generic canvas history is not a governed Site plan schema, persistence, published version, approval, accessibility proof, audit history or concurrent conflict resolution.",
        benchmark_outcome="Native benchmark",
        neutral_requirements_extracted="Record reversible plan deltas; maintain honest undo/redo state; validate restored elements; separate local draft recovery from governed save, approval and publish.",
        security_or_operational_caveat="Local/in-memory history is not authoritative persistence or audit evidence and does not settle concurrent edits.",
        reason_selected_or_excluded="Selected narrowly for real canvas edit recovery and reversible interaction mechanics.",
    ),
    row(
        "faveosuite/faveo-helpdesk",
        release_activity_signal="pinned Community commit=2024-10-03T09:20:47Z; SHA reachable; repository has later activity; cited snapshot is stale",
        edition_boundary="Pinned OSL Community Edition only; Freelancer/Enterprise editions, paid integrations and commercial support behavior excluded.",
        exact_behaviour_screen_workflow_inspected="The cited Community controller persists resolved/closed/reopened states with timestamps and status-thread events, and assigns or surrenders an agent/team owner. Exact official loci: https://github.com/faveosuite/faveo-helpdesk/blob/6568aa45f89b78028b05cddfb2d37c171d2fbab1/app/Http/Controllers/Agent/helpdesk/TicketController.php#L1135-L1281 | https://github.com/faveosuite/faveo-helpdesk/blob/6568aa45f89b78028b05cddfb2d37c171d2fbab1/app/Http/Controllers/Agent/helpdesk/TicketController.php#L1358-L1490",
        related_feature_ids="CAP-OPS-CLIENT-NOTE-REVIEW",
        strengths="Explicit ticket states, owner assignment/surrender, timestamps and thread/event provenance.",
        limitations="The pinned Community slice is monolithic and authority is insufficiently bounded; it proves no Site/privacy scope, healthcare urgency, evidence chain, corrective action or maintained-security assurance.",
        benchmark_outcome="Reject",
        neutral_requirements_extracted="No feature credit; a native review queue should still retain explicit owner, state, actor/time/reason history and reversible reassignment.",
        security_or_operational_caveat="The controller broadly loads tickets for some users without a cited object policy, and email/event side effects are not shown transactionally; do not copy this authority pattern.",
        reason_selected_or_excluded="Substantively rejected as a current comparator because the pinned Community slice is stale and insufficiently bounded; the project itself is not claimed abandoned.",
    ),
    row(
        "fleetbase/fleetbase",
        release_activity_signal="root commit=007a83389960bd4be281fb6b51d7350e5dc047f7 reachable; archived=false; root pins fleetops submodule fa37e7899b78e3cbe2f839e818561ae4c40189c2",
        edition_boundary="Pinned AGPL root commit 007a83389960bd4be281fb6b51d7350e5dc047f7 plus its official Fleet-Ops submodule commit fa37e7899b78e3cbe2f839e818561ae4c40189c2 only; Fleetbase Cloud, registry extensions and unpinned services excluded.",
        exact_behaviour_screen_workflow_inspected="The pinned Fleet-Ops UI posts driver/vehicle assignment or removal, updates the visible collection and emits assigned/unassigned events. Exact official loci: https://github.com/fleetbase/fleetops/blob/fa37e7899b78e3cbe2f839e818561ae4c40189c2/addon/components/fleet/driver-listing.js#L44-L68 | https://github.com/fleetbase/fleetops/blob/fa37e7899b78e3cbe2f839e818561ae4c40189c2/addon/components/fleet/vehicle-listing.js#L44-L68",
        related_feature_ids="CAP-FLEET-DRIVER-DIRECTORY | FLEET-ASSET",
        strengths="Explicit add/remove identities and immediate client collection/event reconciliation in the pinned fleet extension.",
        limitations="Client-side intent only; no server authorization, atomicity, audit history, driver eligibility, vehicle custody, Site scope or failed-write recovery.",
        benchmark_outcome="Reject",
        neutral_requirements_extracted="No feature credit; native assignment must resolve canonical fleet/driver/vehicle records, authorize the server mutation, persist atomically, record actor/time/reason and reconcile failures.",
        security_or_operational_caveat="The UI mutates its local collection after POST but the authoritative server contract and rollback behavior are absent from the cited source.",
        reason_selected_or_excluded="Substantively rejected because the exact slice proves UI intent, not authoritative workflow completion.",
    ),
    row(
        "freescout-help-desk/freescout",
        release_activity_signal="commit=2026-08-07T04:22:21Z; pinned SHA reachable; archived=false",
        edition_boundary="Pinned AGPL FreeScout core only; cloud hosting, mobile apps, official paid modules and community modules excluded.",
        exact_behaviour_screen_workflow_inspected="Conversation access is policy checked; assignment/status changes validate permission and state; reply/note/forward flows record conversation effects, including closure provenance. Exact official loci: https://github.com/freescout-help-desk/freescout/blob/9492779dfc83fde23073f7faa7d0d44700581f15/app/Http/Controllers/ConversationsController.php#L45-L50 | https://github.com/freescout-help-desk/freescout/blob/9492779dfc83fde23073f7faa7d0d44700581f15/app/Http/Controllers/ConversationsController.php#L580-L690 | https://github.com/freescout-help-desk/freescout/blob/9492779dfc83fde23073f7faa7d0d44700581f15/app/Http/Controllers/ConversationsController.php#L694-L1065",
        related_feature_ids="CAP-OPS-CONVERSATIONS | CAP-OPS-CLIENT-NOTE-REVIEW",
        strengths="Policy-gated read, explicit owner/state mutation, conversation effects and closure provenance.",
        limitations="Mailbox conversations are not authoritative care records; no client/Site privacy, care urgency, shift handoff, consent, clinical escalation or complete delivery recovery proof.",
        benchmark_outcome="Native benchmark",
        neutral_requirements_extracted="Authorize mailbox and conversation before read/mutation; persist owner, status and reply evidence; record closure actor/time; preserve a bounded recovery path for queued delivery.",
        security_or_operational_caveat="Mailbox permission is not Oblivion Site/client privacy, and the controller does not prove race-safe cancellation of every asynchronous delivery path.",
        reason_selected_or_excluded="Selected narrowly for permissioned conversation lifecycle and state/owner provenance.",
    ),
    row(
        "hapifhir/hapi-fhir",
        release_activity_signal="commit=2026-08-07T12:28:39Z; pinned SHA reachable; archived=false",
        edition_boundary="Pinned Apache-2.0 HAPI FHIR libraries only; JPA starter, Smile CDR, commercial support and downstream applications excluded.",
        exact_behaviour_screen_workflow_inspected="The library validates FHIR resources and bundle entries into structured results, optionally processes entries concurrently, and exposes a typed client transaction builder. Exact official loci: https://github.com/hapifhir/hapi-fhir/blob/d349713594e6a21b7f0ca2f5374fcd16289394ad/hapi-fhir-base/src/main/java/ca/uhn/fhir/validation/FhirValidator.java#L206-L358 | https://github.com/hapifhir/hapi-fhir/blob/d349713594e6a21b7f0ca2f5374fcd16289394ad/hapi-fhir-base/src/main/java/ca/uhn/fhir/rest/gclient/ITransaction.java#L25-L40",
        related_feature_ids="CAP-CLIN-FRONTLINE-OBSERVATION-RECORDING | CAP-OPS-CUSTOM-FORM-SUBMISSION",
        strengths="Standards-aware resource/per-entry validation with structured diagnostics and a typed transaction request surface.",
        limitations="A library/client interface does not establish an authorised workflow, canonical person/Site scope, server persistence, atomic commit, handoff or clinical safety state.",
        benchmark_outcome="Reject",
        neutral_requirements_extracted="No feature credit; native clinical intake should validate each submitted resource and return bounded diagnostics before its own authorization, canonical binding and transaction.",
        security_or_operational_caveat="Validation is not authorization or persistence, and a transaction interface does not prove server atomicity.",
        reason_selected_or_excluded="Substantively rejected as an Oblivion workflow comparator; exact library behavior is retained only as validation-boundary evidence.",
    ),
]


require(sha(REGISTER) in {INPUT_SHA, OUTPUT_SHA}, "Project register input/output SHA drift")
with REGISTER.open("r", encoding="utf-8-sig", newline="") as handle:
    reader = csv.DictReader(handle)
    fields = list(reader.fieldnames or [])
    current = list(reader)
require(len(current) == 98 and len({item["project"] for item in current}) == 98, "Register identity drift")
replacement = {item["project"]: item for item in ROWS}
require(len(replacement) == 12 and set(replacement) <= {item["project"] for item in current}, "Replacement key drift")

updated = []
for item in current:
    merged = dict(item)
    if item["project"] in replacement:
        merged.update(replacement[item["project"]])
    updated.append({field: merged.get(field, "") for field in fields})

tmp = REGISTER.with_suffix(".csv.tmp")
with tmp.open("w", encoding="utf-8", newline="") as handle:
    writer = csv.DictWriter(handle, fieldnames=fields, lineterminator="\n")
    writer.writeheader()
    writer.writerows(updated)
tmp.replace(REGISTER)

outcomes = Counter(item["benchmark_outcome"] for item in updated)
require(outcomes == Counter({"Native benchmark": 69, "Separate future decision": 16, "Reject": 13}), f"Outcome drift: {outcomes}")
selected_lines = sorted(f"{item['project']}@{next(row['commit_sha'] for row in updated if row['project'] == item['project'])}" for item in ROWS)
selected_sha = hashlib.sha256("\n".join(selected_lines).encode()).hexdigest()
require(selected_sha == "a9a48ec9e31347f24eeeb0b1b7535a98b269b8d88bf5898f830bb0d26b2cc4ba", "Materialized project/commit SHA drift")
require(sha(REGISTER) == OUTPUT_SHA, "Project register output SHA drift")

artifact = {
    "schema_version": "1.0.0",
    "artifact": "project-specific-triage-wave3-2026-08-14",
    "generated_at": "2026-08-14T13:15:00+12:00",
    "audited_commit": COMMIT,
    "read_only_research": True,
    "writer": "root",
    "input_register_sha256": INPUT_SHA,
    "output_register_sha256": sha(REGISTER),
    "row_count": 12,
    "gate_projection": {"before": 60, "after": 72, "denominator": 97, "percent": 74.23, "remaining": 25},
    "physical_register": {"rows": 98, "outcomes": dict(outcomes)},
    "source_selected_identity_sha256": "472fc10c1c044e5858502f636c85015e678ca047203f4c2dd52c995cb2d47081",
    "materialized_project_commit_sha256": selected_sha,
    "independent_review": {
        "reviewer": "project_wave3_review",
        "verdict": "accepted_after_target_id_locus_and_scope_corrections",
        "outcomes_flipped": 0,
        "rows_corrected": 9,
    },
    "rows": ROWS,
    "claim_limit": "Project-specific catalogue triage only. This grants no target-level benchmark completion credit; that requires separate target-specific adjudication.",
}
OUTPUT.write_text(json.dumps(artifact, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"register_sha256": sha(REGISTER), "artifact_sha256": sha(OUTPUT), "replaced": 12, "substantive": 72}, indent=2))
