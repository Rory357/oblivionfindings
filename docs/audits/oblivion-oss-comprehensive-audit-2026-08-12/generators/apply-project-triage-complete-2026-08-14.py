#!/usr/bin/env python3
"""Close the 97-project substantive-triage gate with explicit provenance.

Wave 4 is recovered from an exact archived 20-column CSV. Wave 5 was returned
as prose decisions, so its column text below is deliberately root-authored and
is never described as byte-identical recovered CSV. This changes catalogue
relations only; it does not grant 902-target benchmark completion credit.
"""

from __future__ import annotations

import csv
import hashlib
import io
import json
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
REGISTER = AUDIT / "06-open-source-benchmark-register.csv"
MANIFEST = AUDIT / "evidence" / "source" / "working-capability-manifest-902.json"
MAPPING = AUDIT / "evidence" / "source" / "benchmark-final-902-mapping.json"
OUTPUT = AUDIT / "evidence" / "source" / "project-specific-triage-complete-2026-08-14.json"
WAVE4_ARCHIVE = Path(r"<local-user>/.codex\archived_sessions\rollout-2026-08-14T13-45-10-019ffdf1-c0dd-73e2-be85-87d080154f0f.jsonl")
WAVE5_ARCHIVE = Path(r"<local-user>/.codex\archived_sessions\rollout-2026-08-14T13-46-02-019ffdf2-89e7-73f0-a542-ce78d86be1b6.jsonl")
REVIEW_ARCHIVE = Path(r"<local-user>/.codex\archived_sessions\rollout-2026-08-14T14-13-53-019ffe0c-18a8-79d1-9a99-bd7a1be790e1.jsonl")
INPUT_REGISTER_SHA = "d8c7c5ed79e7790413cc2343803471a44a7b5dc8b4ba86c33821fd7c75e0b7c8"
WAVE4_TEXT_SHA = "b0547f18ce1641fabdc80cae0db99db3d016222616d8a8462845df3a28add89d"
WAVE5_TEXT_SHA = "cae19efaef68692aeb3801ddf463ea9c9179c2e3e651043c95ac376d76941dd2"
REVIEW_TEXT_SHA = "d4f176a0f2a43e4bbe0e536ba5693881bb549c58b1ec2de437da607fb447e207"
EXPECTED_IDENTITY_SHA = "6be8ea0785cc0adc1556bbcb05dedfa569d5736d96e87ef946e48950616c44b0"
EXPECTED_SHA_SHA = "309a21c60a8e8c17f2b45afc63c9a3aab979b9b03b7da214247cdbb08f7e8eae"
EXPECTED_OUTCOME_SHA = "429d115a46f08656316390e1e83f6267aeb555589a10625efd86fb784b89fed2"
EXPECTED_WAVE_SHA = "7ab8cdcc776dce7df868d089b28ce4a8f66b4825e39cdda8a0f3e63abfe70e38"


def sha_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha(path: Path) -> str:
    return sha_bytes(path.read_bytes())


def assistant_final(path: Path, required: str) -> str:
    found = ""
    for line in path.read_text(encoding="utf-8").splitlines():
        try:
            item = json.loads(line)
        except json.JSONDecodeError:
            continue
        if item.get("type") != "response_item":
            continue
        payload = item.get("payload", {})
        if payload.get("role") != "assistant":
            continue
        text = "".join(
            part.get("text", "")
            for part in payload.get("content", [])
            if isinstance(part, dict)
        )
        if required in text:
            found = text
    if not found:
        raise RuntimeError(f"Archived final not found: {path}")
    return found


def semantic_sha(rows: list[dict[str, str]], fields: tuple[str, ...]) -> str:
    lines = sorted(
        ("\x1f".join(row[field] for field in fields) for row in rows),
        key=str.casefold,
    )
    return sha_bytes("\n".join(lines).encode("utf-8"))


with REGISTER.open("r", encoding="utf-8-sig", newline="") as handle:
    reader = csv.DictReader(handle)
    fieldnames = list(reader.fieldnames or [])
    rows = list(reader)

expected_header = [
    "category", "project", "canonical_url", "inspected_ref", "commit_sha",
    "inspected_date", "release_activity_signal", "root_licence",
    "edition_boundary", "exact_behaviour_screen_workflow_inspected",
    "related_feature_ids", "strengths", "limitations", "benchmark_outcome",
    "neutral_requirements_extracted", "security_or_operational_caveat",
    "reason_selected_or_excluded", "observer_agent", "neutral_writer_agent",
    "native_comparator_agent",
]
if fieldnames != expected_header:
    raise RuntimeError("Benchmark-register header drift")
if len(rows) != 98 or len({row["project"] for row in rows}) != 98 or len({row["canonical_url"] for row in rows}) != 98:
    raise RuntimeError("Benchmark-register identity drift")
if sha(REGISTER) != INPUT_REGISTER_SHA:
    if not OUTPUT.exists() or sha(REGISTER) != json.loads(OUTPUT.read_text(encoding="utf-8"))["output_register_sha256"]:
        raise RuntimeError(f"Unexpected benchmark-register input SHA: {sha(REGISTER)}")

wave4_text = assistant_final(WAVE4_ARCHIVE, "hapifhir/hapi-fhir-jpaserver-starter")
wave5_text = assistant_final(WAVE5_ARCHIVE, "novuhq/novu")
review_text = assistant_final(REVIEW_ARCHIVE, "ZoneMinder/zoneminder")
if sha_bytes(wave4_text.encode("utf-8")) != WAVE4_TEXT_SHA:
    raise RuntimeError("Wave-4 archived text drift")
if sha_bytes(wave5_text.encode("utf-8")) != WAVE5_TEXT_SHA:
    raise RuntimeError("Wave-5 archived text drift")
if sha_bytes(review_text.encode("utf-8")) != REVIEW_TEXT_SHA:
    raise RuntimeError("Independent correction text drift")

csv_start = wave4_text.index("```csv") + len("```csv")
csv_end = wave4_text.index("```", csv_start)
wave4_rows = list(csv.DictReader(io.StringIO(wave4_text[csv_start:csv_end].strip())))
if len(wave4_rows) != 12 or list(wave4_rows[0]) != fieldnames:
    raise RuntimeError("Wave-4 exact CSV recovery drift")

for row in wave4_rows:
    row["inspected_date"] = "2026-08-14"
    if row["benchmark_outcome"] == "Future":
        row["benchmark_outcome"] = "Separate future decision"
    if row["project"] == "home-assistant/core":
        row["edition_boundary"] = (
            "Pinned Apache-2.0 Home Assistant Core including in-tree integrations; frontend, Cloud, "
            "community/custom integrations, connected hardware and configured external services are excluded."
        )
    if row["project"] == "librenms/librenms":
        row["root_licence"] = "GPL-3.0-only; root LICENSE.txt records included third-party-package exceptions"


def w5(
    sha_value: str,
    licence: str,
    boundary: str,
    behavior: str,
    ids: str,
    strengths: str,
    limitations: str,
    outcome: str,
    requirement: str,
    caveat: str,
    reason: str,
) -> dict[str, str]:
    return {
        "commit_sha": sha_value,
        "root_licence": licence,
        "edition_boundary": boundary,
        "exact_behaviour_screen_workflow_inspected": behavior,
        "related_feature_ids": ids,
        "strengths": strengths,
        "limitations": limitations,
        "benchmark_outcome": outcome,
        "neutral_requirements_extracted": requirement,
        "security_or_operational_caveat": caveat,
        "reason_selected_or_excluded": reason,
    }


wave5 = {
    "novuhq/novu": w5(
        "4926ef59192017a3be329ab06e9d3ea68e085fb2",
        "MIT for core (LICENSE-MIT); enterprise paths are commercial/open-core exclusions",
        "Pinned public MIT core only; /enterprise, apps/web/src/ee, apps/dashboard/src/ee, cloud services and providers are excluded.",
        "Bulk-trigger processing resolves an active workflow, validates each event, returns per-event errors and queues only processed work. Exact locus: https://github.com/novuhq/novu/blob/4926ef59192017a3be329ab06e9d3ea68e085fb2/apps/api/src/app/events/usecases/process-bulk-trigger/process-bulk-trigger.usecase.ts#L18-L111",
        "None; future owner decision only.",
        "Material bulk-trigger validation, bounded error reporting and processed-work queuing.",
        "The material slice is notification infrastructure, not an Oblivion user-capability completion proof.",
        "Separate future decision",
        "If revisited, resolve an active template, validate each event, persist bounded outcomes and queue only authorised work.",
        "No Oblivion Site/privacy scope, user preference authority or delivery-receipt/recovery parity is proved.",
        "Future comparator only after target-specific owner review; no current 902 mapping credit.",
    ),
    "open-condo-software/condo": w5(
        "465b308c063a804b5d7ff0f159af2cde2423240b", "MIT",
        "Pinned public property-management repository only; payment/banking providers, hosted operation and mini-app integrations are excluded.",
        "A ticket status-transition map declares allowed labels. Exact locus: https://github.com/open-condo-software/condo/blob/465b308c063a804b5d7ff0f159af2cde2423240b/apps/condo/domains/ticket/constants/statusTransitions.js#L11-L30",
        "None—rejected from feature comparison.", "Clear declarative status vocabulary.",
        "The cited map proves no authoritative mutation, object authorization, audit or recovery behavior.", "Reject",
        "No feature credit; native ticket transitions still require server authority, provenance, idempotency and recovery.",
        "A label map must not be treated as an authorization or completion contract.",
        "Substantively rejected because the inspected slice is not executable workflow completion.",
    ),
    "opendcim/openDCIM": w5(
        "25168dbe4e8806e3c9c6b870c4c29df6a6b4041d", "GPL-3.0-only",
        "Pinned public data-centre application only; bundled third-party dependencies, facility hardware and external integrations are excluded; README maturity warnings are retained.",
        "Cabinet audit history records inspected cabinet, user and timestamp and retrieves the latest audit. Exact locus: https://github.com/opendcim/openDCIM/blob/25168dbe4e8806e3c9c6b870c4c29df6a6b4041d/classes/CabinetAudit.class.php#L26-L98",
        "CAP-SITE-SITE-INSPECTION-SITE-LIFECYCLE", "Concrete inspected asset, actor, timestamp and latest-audit retrieval.",
        "No Site authorization, inspection criteria, defect handling, immutable evidence chain or reporting-asset-condition workflow.", "Native benchmark",
        "Retain canonical inspected Site asset, accountable actor/time, criteria and latest immutable inspection evidence.",
        "The README describes a limited community-maintenance posture; deployment maturity must be independently assessed.",
        "Selected narrowly for Site inspection lifecycle evidence only; no reporting-asset-condition credit.",
    ),
    "openmrs/openmrs-module-fhir2": w5(
        "4dc2236334dd8ac35a61b2095b68f655f4cb9b65", "MPL-2.0",
        "Pinned public FHIR2 module only; OpenMRS core, other modules, deployments and hosting are excluded.",
        "FHIR patient service adapts and searches resources. Exact locus: https://github.com/openmrs/openmrs-module-fhir2/blob/4dc2236334dd8ac35a61b2095b68f655f4cb9b65/api/src/main/java/org/openmrs/module/fhir2/api/impl/FhirPatientServiceImpl.java#L68-L128",
        "None—rejected from feature comparison.", "Standards-oriented patient-resource adaptation and search.",
        "No authorised supported-living workflow, canonical Site/client scope or transactional action completion.", "Reject",
        "No feature credit; any FHIR boundary requires native identity, authorization, validation, provenance and recovery.",
        "Resource adaptation is neither care-workflow authority nor persistence assurance.",
        "Substantively rejected as an Oblivion workflow comparator.",
    ),
    "OpenNMS/opennms": w5(
        "a23f24cb9e15d8511073de90e413747bc76d0350", "AGPL-3.0-or-later",
        "Pinned public OpenNMS source only; Meridian, managed services and uncited integrations are excluded.",
        "Alarm acknowledgement validates selected action, records actor/time and supports unacknowledgement. Exact locus: https://github.com/OpenNMS/opennms/blob/a23f24cb9e15d8511073de90e413747bc76d0350/opennms-webapp/src/main/java/org/opennms/web/controller/alarm/AcknowledgeAlarmController.java#L91-L133",
        "CAP-CR-ALERT-RESPONSE-CLOSURE", "Actor/time acknowledgement with explicit reversal and constrained redirect behavior.",
        "Network-monitoring identities are not Oblivion Sites, custody, consent or safety authority; no generic alert-event credit is inferred.", "Native benchmark",
        "Validate alert/action, record actor/time, support authorised reversal and preserve an accountable response state.",
        "Acknowledgement is not incident resolution, closure evidence or Site-scoped safety completion.",
        "Selected narrowly for Control Room alert-response closure mechanics.",
    ),
    "openreferral/specification": w5(
        "eb55d3ba2c1ae37781c3942d3b08025e2449063f", "CC-BY-SA-4.0",
        "Pinned schema/documentation only; directory implementations and publisher data are excluded.",
        "HSDS documents organization, service and location structures. Exact locus: https://github.com/openreferral/specification/blob/eb55d3ba2c1ae37781c3942d3b08025e2449063f/docs/hsds/schema_reference.md#L1-L21",
        "None—rejected from feature comparison.", "Clear interoperability vocabulary.",
        "No executable workflow, authorization, persistence or recovery behavior.", "Reject",
        "No feature credit; schemas may inform vocabulary only after native governance and workflow design.",
        "Documentation must not be promoted to runtime completion evidence.", "Substantively rejected because no executable behavior is present.",
    ),
    "OpenSPP/OpenSPP2": w5(
        "c33d3cb132e1a33f2aa9734106c48a3d416c5ad1", "LGPL-3.0-only",
        "Pinned public OpenSPP modules only; Odoo platform behavior, deployments and uncited extensions are excluded.",
        "Program-membership service searches and creates or updates membership using sudo. Exact locus: https://github.com/OpenSPP/OpenSPP2/blob/c33d3cb132e1a33f2aa9734106c48a3d416c5ad1/spp_api_v2_programs/services/program_membership_service.py#L23-L98",
        "None—rejected from feature comparison.", "Concrete membership search/create/update service.",
        "The sudo path does not prove an object policy or Site boundary.", "Reject",
        "No feature credit; native membership requires canonical subject, Site scope, authorization and provenance.",
        "Elevated service access is unsuitable as an authority comparator without a bounded caller policy.",
        "Substantively rejected for fail-open authority semantics.",
    ),
    "opensrp/fhircore": w5(
        "9e8675d41a133230eaf6bf7fd79370c8d34fe326", "Apache-2.0",
        "Pinned Android FHIRCore engine only; reference apps, servers, identity and deployment infrastructure are excluded.",
        "Local register repository performs configured filtering and counting. Exact locus: https://github.com/opensrp/fhircore/blob/9e8675d41a133230eaf6bf7fd79370c8d34fe326/android/engine/src/main/java/org/smartregister/fhircore/engine/data/local/register/RegisterRepository.kt#L71-L145",
        "None—rejected from feature comparison.", "Configurable local register query mechanics.",
        "No complete authorised clinical/client workflow or server-side integrity contract.", "Reject",
        "No feature credit; native registers require server-owned identity, authorization, persistence and recovery.",
        "Local filtering cannot prove upstream visibility, synchronization or mutation authority.",
        "Substantively rejected as a workflow-completion comparator.",
    ),
    "SmartQHSE/hse-calculators": w5(
        "324829cf05f2b99363a4d4d0d59e008c4a300032", "MIT",
        "Pinned public calculator source only; external incident records, regulatory validation and hosting are excluded.",
        "Pure incident-rate functions calculate supplied values. Exact locus: https://github.com/SmartQHSE/hse-calculators/blob/324829cf05f2b99363a4d4d0d59e008c4a300032/src/core/incident-rates.ts#L9-L91",
        "None—rejected from feature comparison.", "Transparent incident-rate formulae.",
        "No hazard/incident ownership, approval, evidence or corrective-action lifecycle.", "Reject",
        "No feature credit; native reporting must govern source records, denominator, approval and provenance.",
        "A correct formula does not establish data quality, authority or regulatory applicability.",
        "Substantively rejected because calculator behavior is not operational workflow completion.",
    ),
    "wazuh/wazuh": w5(
        "ffbeb25ebd80541e54dfd141d131ce0d7633277a", "GPL-2.0-only WITH Wazuh OpenSSL-linking exception",
        "Pinned public agent, manager, rules and active-response source; dashboard, indexer, external services and vendor integrations are excluded.",
        "Repository documentation describes agent-to-manager collection, governed rule analysis, alert output and active response. Exact locus: https://github.com/wazuh/wazuh/blob/ffbeb25ebd80541e54dfd141d131ce0d7633277a/README.md#L12-L30",
        "Non-credit future relations only: SEC-ALERTS-EVENTS | CAP-CR-SIGNAL-TO-ALERT-PIPELINE",
        "Material detection pipeline separating collection, analysis, alert output and response.",
        "No target-specific authoritative user workflow was proved; dashboard/indexer are outside the repository boundary.",
        "Separate future decision",
        "If revisited, retain source identity, governed rules, durable outcome and a separately authorised response lifecycle.",
        "Device/security monitoring does not prove supported-person consent, temporal custody, Site privacy or human safety closure.",
        "Future comparator only; the listed target relations are explicitly non-credit.",
    ),
    "xeokit/xeokit-sdk": w5(
        "1a5beeed8828053f2164654ed3241f06b2919574", "AGPL-3.0-only",
        "Pinned public SDK only; hosted BIM services, model servers and external integrations are excluded.",
        "TreeViewPlugin exposes model navigation, visibility and selection mechanics. Exact locus: https://github.com/xeokit/xeokit-sdk/blob/1a5beeed8828053f2164654ed3241f06b2919574/src/plugins/TreeViewPlugin/TreeViewPlugin.js#L7-L22",
        "None—rejected from feature comparison.", "Useful model-tree presentation mechanics.",
        "No governed Site-plan editing, approval, persistence or audit workflow.", "Reject",
        "No feature credit; use native accessible tree mechanics inside an authoritative plan workflow only.",
        "SDK interaction state is not authoritative application evidence.",
        "Substantively rejected as a completion comparator.",
    ),
    "Ylianst/MeshCentral": w5(
        "6d335354ed54b914e55b13ed02c7edb2675bd247", "Apache-2.0",
        "Pinned public MeshCentral server only; hosted service, device firmware and third-party relay/management systems are excluded.",
        "Device-command routing resolves a device and aggregates rights and consent context before dispatch. Exact locus: https://github.com/Ylianst/MeshCentral/blob/6d335354ed54b914e55b13ed02c7edb2675bd247/meshuser.js#L275-L320",
        "CAP-SEC-QUECLINK-HUB-COMMANDS", "Canonical device resolution with command-specific rights and actor/session context.",
        "Mesh groups are not Oblivion Site access and generic consent flags do not establish care consent or command approval.",
        "Native benchmark",
        "Resolve canonical device, enforce command rights, bind actor/session/consent context and dispatch only after policy checks.",
        "Device command delivery and real-world effect still require durable outcome and recovery evidence.",
        "Selected narrowly for governed device-command dispatch mechanics.",
    ),
    "ZoneMinder/zoneminder": w5(
        "6b91eebd04f48f6010653872ce835e0b59773f41", "GPL-2.0-only",
        "Pinned public ZoneMinder repository only; camera firmware, hosted/video services and uncited ONVIF integrations are excluded.",
        "Event creation persists source monitor, start time, cause and state and updates aggregate counters. Exact locus: https://github.com/ZoneMinder/zoneminder/blob/6b91eebd04f48f6010653872ce835e0b59773f41/src/zm_event.cpp#L47-L188",
        "SEC-ALERTS-EVENTS", "Concrete event/source/time/cause/state persistence and aggregate counters.",
        "No retained-media/evidence claim; no Site/privacy scope, retention approval, supported-person consent or authorised human response lifecycle.",
        "Native benchmark",
        "Bind event to canonical source, time, cause and state and update counters without claiming evidence retention.",
        "Camera events are not care-safety alerts and media governance is outside the proven slice.",
        "Selected narrowly for security-alert event persistence only.",
    ),
}

by_project = {row["project"]: row for row in rows}
wave4_projects = {row["project"] for row in wave4_rows}
if len(wave4_projects) != 12 or set(wave5) & wave4_projects:
    raise RuntimeError("Wave partition drift")
for recovered in wave4_rows:
    by_project[recovered["project"]].update(recovered)
for project, patch in wave5.items():
    row = by_project[project]
    row.update(patch)
    row["inspected_date"] = "2026-08-14"
    row["release_activity_signal"] = (
        "Pinned immutable-source review completed 2026-08-14; repository reachability and exact loci were independently checked."
    )
    row["observer_agent"] = "project_triage_wave5"
    row["neutral_writer_agent"] = "root"
    row["native_comparator_agent"] = "p8_adversarial"

selected = [by_project[name] for name in sorted(wave4_projects | set(wave5), key=str.casefold)]
for row in selected:
    if "Evidence limit—catalogue identity and metadata only" in row["exact_behaviour_screen_workflow_inspected"]:
        raise RuntimeError(f"Generic metadata placeholder retained: {row['project']}")

semantic_rows = [
    {**row, "wave": "wave4" if row["project"] in wave4_projects else "wave5"}
    for row in selected
]
if semantic_sha(semantic_rows, ("project", "canonical_url")) != EXPECTED_IDENTITY_SHA:
    raise RuntimeError("Selected identity semantic SHA drift")
if semantic_sha(semantic_rows, ("project", "canonical_url", "commit_sha")) != EXPECTED_SHA_SHA:
    raise RuntimeError("Selected project/SHA semantic SHA drift")
if semantic_sha(semantic_rows, ("project", "canonical_url", "commit_sha", "benchmark_outcome")) != EXPECTED_OUTCOME_SHA:
    raise RuntimeError("Selected outcome semantic SHA drift")
if semantic_sha(semantic_rows, ("wave", "project", "canonical_url", "commit_sha", "benchmark_outcome")) != EXPECTED_WAVE_SHA:
    raise RuntimeError("Wave semantic SHA drift")

rows = sorted(by_project.values(), key=lambda row: (row["project"].casefold(), row["canonical_url"]))
outcomes = Counter(row["benchmark_outcome"] for row in rows)
if outcomes != Counter({"Native benchmark": 73, "Reject": 15, "Separate future decision": 10}):
    raise RuntimeError(f"Outcome-count drift: {outcomes}")
if [row["project"] for row in rows if row["project"] == "frappe/frappe"] != ["frappe/frappe"]:
    raise RuntimeError("Supplemental Frappe identity drift")

manifest_ids = Counter(
    target["working_key"]
    for target in json.loads(MANIFEST.read_text(encoding="utf-8"))["targets"]
)
native_ids = {
    "CAP-SITE-SITE-INSPECTION-SITE-LIFECYCLE",
    "CAP-CR-ALERT-RESPONSE-CLOSURE",
    "CAP-SEC-QUECLINK-HUB-COMMANDS",
    "SEC-ALERTS-EVENTS",
}
if any(manifest_ids[target] != 1 for target in native_ids):
    raise RuntimeError("Catalogue Native target identity drift")
mapping_sha_before = sha(MAPPING)

with REGISTER.open("w", encoding="utf-8", newline="") as handle:
    writer = csv.DictWriter(handle, fieldnames=fieldnames, quoting=csv.QUOTE_ALL, lineterminator="\n")
    writer.writeheader()
    writer.writerows(rows)

if sha(MAPPING) != mapping_sha_before:
    raise RuntimeError("Catalogue integration changed benchmark mapping")

payload = {
    "schema_version": "1.0.0",
    "artifact": "project-specific-triage-complete-2026-08-14",
    "generated_at": datetime.now(timezone.utc).isoformat(),
    "audited_commit": "081ef198f9f992f224e8c0c9fba33df33dde40be",
    "writer": "root",
    "read_only_research": True,
    "provenance_boundary": (
        "Wave 4 is an exact archived 20-column CSV. Wave 5 is a root-authored deterministic serialization "
        "of archived prose decisions plus independent corrections; no byte-identical recovered Wave-5 CSV is claimed."
    ),
    "input_register_sha256": INPUT_REGISTER_SHA,
    "output_register_sha256": sha(REGISTER),
    "prompt_gate": {"substantive": 97, "denominator": 97, "percent": 100.0, "remaining": 0},
    "physical_register": {"rows": 98, "outcomes": dict(sorted(outcomes.items()))},
    "source_text_sha256": {
        "wave4_exact_csv_final": WAVE4_TEXT_SHA,
        "wave5_prose_final": WAVE5_TEXT_SHA,
        "independent_correction_review": REVIEW_TEXT_SHA,
    },
    "semantic_sha256": {
        "selected_project_url": EXPECTED_IDENTITY_SHA,
        "selected_project_url_commit": EXPECTED_SHA_SHA,
        "selected_project_url_commit_outcome": EXPECTED_OUTCOME_SHA,
        "wave_project_url_commit_outcome": EXPECTED_WAVE_SHA,
    },
    "mapping_unchanged_sha256": mapping_sha_before,
    "rows": semantic_rows,
    "claim_limit": "Catalogue triage only; related IDs do not automatically change the 902 benchmark mapping or grant runtime/completion credit.",
}
OUTPUT.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({
    "register_sha256": sha(REGISTER),
    "artifact_sha256": sha(OUTPUT),
    "prompt_gate": "97/97",
    "physical_outcomes": dict(sorted(outcomes.items())),
}, indent=2))
