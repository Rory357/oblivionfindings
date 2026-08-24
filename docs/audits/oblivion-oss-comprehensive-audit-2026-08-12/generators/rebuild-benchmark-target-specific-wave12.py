#!/usr/bin/env python3
"""Build the independently reviewed twelfth target-specific benchmark payload."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


AUDIT = Path(__file__).resolve().parent.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST_PATH = SOURCE / "working-capability-manifest-902.json"
MAPPING_PATH = SOURCE / "benchmark-final-902-mapping.json"
OUTPUT_PATH = SOURCE / "benchmark-target-specific-adjudication-902-wave12.json"
COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-14T14:24:00+12:00"
PRE_WAVE_MAPPING_SHA = "788f0f78cb8a9fb31257c6fffed60b468c687bcaf8757acc2e8494d653ab5d9d"


def load(path: Path) -> dict:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


REPOS = {
    "CARE": {"repo": "CARE", "official_repository_url": "https://github.com/ohcnetwork/care", "commit_sha": "e926b6491e5640f9ab5f758c1e556b59cc6b729f", "spdx": "MIT", "license_locus": "LICENSE", "license_sha256": "b4185b46e22c62a37566cd953336dc92a8b836f3d750a4cf0788e05b79260d7b"},
    "SAHANA": {"repo": "Sahana Eden", "official_repository_url": "https://github.com/sahana/eden", "commit_sha": "0e55ecf7ed780b7ad659e70398b56260c41de021", "spdx": "MIT", "license_locus": "LICENSE", "license_sha256": "7c662d47bb673fa88d2b75f0f1859fb65dc16c41e7ecd749612024070c1cd06b"},
    "OPENMRS": {"repo": "OpenMRS Core", "official_repository_url": "https://github.com/openmrs/openmrs-core", "commit_sha": "6c1b124b30b5df1df6d1939fd629cea1ea002a4e", "spdx": "MPL-2.0", "license_locus": "LICENSE", "license_sha256": "7b284ff454b433a2343d6832749da4e8c6b26ee502cc6f4c4ffe50eb6f1a5e92"},
    "OPENPROJECT": {"repo": "OpenProject", "official_repository_url": "https://github.com/opf/openproject", "commit_sha": "d5fa0433dce7f3edd48d0120736ac844fe3748d9", "spdx": "GPL-3.0-or-later", "license_locus": "publiccode.yml:L55", "license_sha256": "3802de5be385f9de812523fbd963f2e1a7f9abbc41c0f525e1103bc6b9255da5"},
    "ORCA": {"repo": "ORCA", "official_repository_url": "https://github.com/SanteonNL/orca", "commit_sha": "b6654fe63d3cbdfab9f9790b24e2ab2003498418", "spdx": "GPL-3.0", "license_locus": "LICENSE", "license_sha256": "3972dc9744f6499f0f9b2dbf76696f2ae7ad8af9b23dde66d6af86c9dfb36986"},
    "HRMS": {"repo": "Frappe HRMS", "official_repository_url": "https://github.com/frappe/hrms", "commit_sha": "450f6ca52c5386020a1023b2f24e4af0ab20521e", "spdx": "GPL-3.0", "license_locus": "license.txt", "license_sha256": "f333043685c88280b1a0a41b4f8e2eacb02079f0bfca4d467e52c8834c658cea"},
}
for repo in REPOS.values():
    repo["edition_boundary"] = "Pinned official community repository source only; hosted services, paid/Enterprise/private extensions and unpinned refs are excluded. GPL/MPL source is behavioural evidence only."


# key, repository, exact loci(path, lines, SHA), current product loci,
# neutral requirement, proven material slice, conservative parity limits.
DIRECT = [
    ("CAP-CLIN-FRONTLINE-EVENT-RECORDING", "OPENMRS", [("api/src/main/java/org/openmrs/Encounter.java", "L47-L112", "e71ddeb81e0adc1fc648548667a60dcdd6b4c219ddb31ea599a06068eca09a76"), ("api/src/main/java/org/openmrs/api/impl/ObsServiceImpl.java", "L103-L130", "9d44ea8ce2d68c8591279404d599a0e10bbb1806b3a06d17b8b1546e770acb1e")], ["ShiftClinicalController.php:L132-L179,L185-L192", "ClientClinicalController.php:L84-L103"], "Record an authorised patient/encounter observation with encounter time, location, type and clinical data.", "OpenMRS provides encounter structure plus validated observation persistence.", "No Oblivion shift/Site policy, event taxonomy, attachment, escalation or runtime proof."),
    ("CAP-INC-INCIDENT-REPORT", "SAHANA", [("modules/s3db/event.py", "L2083-L2123,L2141-L2207", "b4da113acd83dd0853dbfdfc4ae57d7c6f0fd4ded7532e70052c49637be02a30")], ["IncidentReportController.php:L12-L100"], "Provide a structured, filterable incident register with occurrence, reporter, location, type, description and closure state.", "Sahana defines and exposes a structured incident-report register with those material fields and state.", "No Oblivion CSV, client/Site privacy, direct-object or runtime parity."),
    ("CAP-RESP-BOOKING-LIFECYCLE", "CARE", [("care/emr/models/scheduling/booking.py", "L9-L50", "f4c69c0d035717797b847ba1a4ab6f9a20957ebc7466a5b26f158e9b0bcc0215")], ["RespiteBookingController.php:L56-L147,L161-L283"], "Bind a client/patient booking to a resource slot, bounded time, actor and explicit state.", "CARE persists booking subject, resource, time, actor, status and optional encounter linkage.", "No funding, agreement, readiness, recurrence, collision or Oblivion transition proof."),
    ("CAP-RESP-RESOURCE-ALLOCATION", "CARE", [("care/emr/models/scheduling/booking.py", "L9-L18", "f4c69c0d035717797b847ba1a4ab6f9a20957ebc7466a5b26f158e9b0bcc0215")], ["RespiteResourceAllocationController.php:L37-L57", "ClientRespiteAllocationController.php:L11-L69"], "Represent a schedulable resource, bounded time and allocation count.", "CARE binds bookings to a schedulable resource and bounded time with allocation quantity.", "No booking ownership, collision prevention, Site scope or resource workflow proof."),
    ("CAP-RESP-STAY-LIFECYCLE", "CARE", [("care/emr/models/encounter.py", "L11-L37", "4ae86d2ce4920e060b9ffac2bc4da10adc9cd9869f455b878a074a7af04d8561")], ["RespiteStayController.php:L40-L69,L91-L155,L198-L241,L609-L645"], "Persist a stay/encounter with status history, period, facility, booking, location and discharge advice.", "CARE's encounter model materially represents those stay lifecycle elements.", "No respite admission/discharge gates, medication reconciliation, incidents, bed holds or Site policy."),
    ("CAP-RESP-PROCEDURE-RUN-EXECUTION", "ORCA", [("orchestrator/careplanservice/handle_updatetask.go", "L124-L166,L226-L261", "6e73d1d91ff7a18bd38047b81689ed7045d49a3d4fb77ffb151c6e24a21128c7")], ["RespiteProcedureRunController.php:L62-L121,L143-L200,L312-L340"], "Execute owner/requester-authorised procedure tasks through explicit state transitions with stable parent linkage.", "ORCA checks task ownership/requester relationships and applies governed task transitions.", "No procedure templates, required-task completion gate, escalation or product terminal-state parity."),
    ("CAP-DAY-MY-CALENDAR", "HRMS", [("hrms/api/__init__.py", "L41-L59,L92-L96,L319-L342", "61e4adba8852721c27fa0a5773684be3250b759e67dde9c47c7756374374d905")], ["MyCalendarController.php:L16-L35,L39-L153"], "Resolve the signed-in user to an active employee and show employee-specific active shift assignments and times.", "Frappe HRMS materially proves employee resolution and employee-specific active shift assignment retrieval.", "No Oblivion calendar joins, date parser, Site access, privacy or worker UX."),
    ("CAP-DAY-ALL-TASKS-WORKBENCH", "OPENPROJECT", [("lib/api/v3/work_packages/work_package_representer.rb", "L362-L376,L468-L470,L504-L510,L560-L578", "2c26dea717d1e659d99fea4fa5923b3046048cdd11f77bc227e06ef5d9cc66dd"), ("app/contracts/work_packages/update_contract.rb", "L31-L42,L62-L80", "8fa1c09c9e4db094d613aa57bf689642e6de9bb18a9c3d9b41b4440b1e3e2467")], ["AllTasksController.php:L34-L115,L218-L310,L381-L429"], "Expose task identity, dates, status and assignee through permission-governed read and update surfaces.", "OpenProject materially represents those task fields and permission-governed update checks.", "No Oblivion aggregation, provider visibility, watcher/split, CSV or Site/privacy parity."),
]


# key, current product loci, neutral requirement, rejected repository slices,
# bounded no-credible-match reason.
NCM = [
    ("CAP-CLIN-HEALTH-MONITORING-OVERSIGHT", ["HealthClinicalDashboardController.php:L235-L250"], "Provide a permitted multi-client health-monitoring rollup and response projection.", [("CARE", "care/emr/models/observation.py", "L6-L48", "571e82ad8b3b59af7d3c208e5b60cfec9f5e22d0999c3b49acdd0a3be20e4474", "Persists individual observations but not a permitted multi-client oversight rollup."), ("OPENMRS", "api/src/main/java/org/openmrs/api/impl/ObsServiceImpl.java", "L103-L148", "9d44ea8ce2d68c8591279404d599a0e10bbb1806b3a06d17b8b1546e770acb1e", "Persists individual observations but not monitoring oversight or response projection.")], "The inspected official health-record repositories prove individual observations, not the exact multi-client monitoring capability. This conclusion is bounded to the inspected corpus."),
    ("CAP-CLIN-PROTOCOL-DEFINITION-LIFECYCLE", ["HealthClinicalProtocolController.php:L22-L129"], "Govern protocol authoring, revision, activation and retirement.", [("CARE", "care/emr/models/scheduling/booking.py", "L9-L50", "f4c69c0d035717797b847ba1a4ab6f9a20957ebc7466a5b26f158e9b0bcc0215", "Booking scheduling does not prove protocol authoring or revision."), ("ORCA", "orchestrator/careplanservice/handle_createtask.go", "L29-L75,L117-L188", "2b30c5bab70f8687f0a56e92dfdd2fd5ae84076ac9ecb807a4b15c820a659152", "Care-plan task creation does not establish governed protocol lifecycle.")], "The inspected booking and task sources do not prove governed protocol definition, revision and activation. This conclusion is bounded to the inspected corpus."),
    ("CAP-INC-INCIDENT-FOLLOWUP", ["IncidentFollowupController.php:L13-L158"], "Provide an incident-owned follow-up lifecycle with accountable immutable completion.", [("SAHANA", "modules/s3db/event.py", "L2066-L2123,L2165-L2208", "b4da113acd83dd0853dbfdfc4ae57d7c6f0fd4ded7532e70052c49637be02a30", "Incident reports do not establish a follow-up lifecycle."), ("OPENPROJECT", "app/contracts/work_packages/update_contract.rb", "L31-L42,L62-L80", "8fa1c09c9e4db094d613aa57bf689642e6de9bb18a9c3d9b41b4440b1e3e2467", "Generic work-package updates do not prove incident-owned immutable completion."), ("OPENPROJECT", "app/services/work_packages/update_service.rb", "L31-L55,L57-L75", "d1fbeff98e8ef5825fa86094c892de7a4cc2cca5927c68ae799ad5d83792509a", "Generic update execution does not prove incident follow-up ownership and closure.")], "Sahana has reports and OpenProject has generic updates; neither proves the exact incident follow-up completion boundary. This conclusion is bounded to the inspected corpus."),
    ("CAP-INC-INCIDENT-TEMPLATE-LIFECYCLE", ["IncidentTemplateController.php:L10-L77", "IncidentTemplate.php:L8-L29"], "Govern an administrator-only reusable incident-template definition lifecycle.", [("SAHANA", "modules/s3db/event.py", "L1063-L1170", "b4da113acd83dd0853dbfdfc4ae57d7c6f0fd4ded7532e70052c49637be02a30", "Incident classification does not prove template lifecycle."), ("OPENPROJECT", "app/models/work_package.rb", "L57-L64,L103-L110,L274-L281,L308-L312", "fe6207cbd7edc33fdcbfce9e7da247c6bcf20bbbb0f7e33c8d4150abfb7aefa7", "Generic work-package configuration does not prove reusable incident-template governance.")], "Classification and generic work-package configuration do not establish an administrator-only incident-template lifecycle. This conclusion is bounded to the inspected corpus."),
]


manifest = load(MANIFEST_PATH)
mapping = load(MAPPING_PATH)
require(manifest.get("audited_commit") == mapping.get("audited_commit") == COMMIT, "Commit mismatch")
require(sha(MAPPING_PATH) == PRE_WAVE_MAPPING_SHA, "Pre-wave mapping SHA mismatch")
manifest_by_key = {row["working_key"]: row for row in manifest["targets"]}
mapping_by_key = {row["working_key"]: row for row in mapping["targets"]}
require(len(manifest_by_key) == len(mapping_by_key) == 902, "Target identity count mismatch")


def lineage(key: str) -> dict:
    identity = manifest_by_key[key]
    return {
        **{name: identity.get(name, []) for name in ("source_family_ids", "route_ids", "page_ids", "backend_anchors")},
        **{name: identity.get(name) for name in ("id_status", "class", "canonical_module")},
    }


evaluations = []
for key, repo_name, loci, current_loci, neutral, proven, limits in DIRECT:
    prior = mapping_by_key[key]
    require(prior.get("status") == "unproved" and prior.get("completion_credit") is False, f"Prior direct status drift: {key}")
    repo = REPOS[repo_name]
    exact_loci = [{"path": path, "lines": lines, "sha256": file_sha, "primary_source_url": f"{repo['official_repository_url']}/blob/{repo['commit_sha']}/{path}"} for path, lines, file_sha in loci]
    evidence_loci = [f"{repo['official_repository_url']}@{repo['commit_sha']} :: {path} :: {lines} :: sha256={file_sha}" for path, lines, file_sha in loci]
    evaluations.append({
        "working_key": key, "prior_status": "unproved", "candidate_status": "candidate_found_direct",
        "completion_credit_recommended": True, "neutral_requirement": neutral,
        "current_product_loci": current_loci, "current_source_lineage": lineage(key),
        "benchmark": {**repo, "exact_loci": exact_loci, "proven_slice": proven, "parity_limits": limits, "p6_caveats": "Benchmark-only behavior; do not copy source, schema, labels, layouts or product wording."},
        "evidence_loci": evidence_loci,
    })

for key, current_loci, neutral, rejected, bounded_reason in NCM:
    prior = mapping_by_key[key]
    require(prior.get("status") == "unproved" and prior.get("completion_credit") is False, f"Prior NCM status drift: {key}")
    rejected_repositories = []
    evidence_loci = []
    for repo_name, path, lines, file_sha, reason in rejected:
        repo = REPOS[repo_name]
        locus = f"{path}:{lines}"
        rejected_repositories.append({**repo, "source_loci": [locus], "exact_loci": [{"path": path, "lines": lines, "sha256": file_sha}], "reason": reason})
        evidence_loci.append(f"{repo['official_repository_url']}@{repo['commit_sha']} :: {path} :: {lines} :: sha256={file_sha}")
    require(len({row["official_repository_url"] for row in rejected_repositories}) >= 2, f"NCM corpus too narrow: {key}")
    evaluations.append({
        "working_key": key, "prior_status": "unproved", "candidate_status": "documented_ncm_direct",
        "completion_credit_recommended": True, "neutral_requirement": neutral,
        "current_product_loci": current_loci, "current_source_lineage": lineage(key),
        "evidence_loci": evidence_loci, "rejected_repositories": rejected_repositories,
        "bounded_ncm_reason": bounded_reason,
    })

keys = [row["working_key"] for row in evaluations]
require(len(keys) == len(set(keys)) == 12, "Wave-12 keys are not 12 unique targets")
keys_sha = hashlib.sha256("\n".join(sorted(keys)).encode()).hexdigest()
require(keys_sha == "c583554302f8bce2e00ae5d8f7a5a4d93c0bb134eec6862347f5d0a61cfdf0bf", "Wave-12 key SHA drift")
lineage_lines = sorted("|".join((row["working_key"], row["prior_status"], ";".join(sorted(row["current_source_lineage"]["route_ids"])), ";".join(sorted(row["current_source_lineage"]["page_ids"])), ";".join(sorted(row["current_source_lineage"]["backend_anchors"])))) for row in evaluations)
lineage_sha = hashlib.sha256("\n".join(lineage_lines).encode()).hexdigest()
require(lineage_sha == "58bb2e9868a46191fa7c5046607b2b6133ce1c370d2b3ba9129d1e9da215955b", "Wave-12 lineage SHA drift")
require(sum(row["candidate_status"] == "candidate_found_direct" for row in evaluations) == 8, "Wave-12 direct count drift")
require(sum(row["candidate_status"] == "documented_ncm_direct" for row in evaluations) == 4, "Wave-12 NCM count drift")

artifact = {
    "schema_version": "1.0.0", "artifact": "benchmark-target-specific-adjudication-902-wave12",
    "generated_at": GENERATED_AT, "audited_commit": COMMIT, "read_only": True,
    "scope": "Twelfth bounded target-specific wave: 12 current unique completion-unproved targets; eight material direct slices and four bounded NCM decisions.",
    "methodology": {"family_credit_inherited": False, "runtime_boundary": "No application, browser, database, deployment or Git state was changed.", "no_copy_rule": "Evidence is behavioural only; do not copy source, schema, UI, wording or distinctive layouts."},
    "source_slice_reuse_disclosure": ["No target-key reuse. CARE booking is intentionally shared by three distinct rows; Sahana incident material is shared by three incident rows; OpenMRS/OpenProject slices appeared against other keys in earlier waves. Every row uses independent current manifest lineage and receives no source-family inheritance."],
    "input_pins": {"working_capability_manifest_902": {"path": "evidence/source/working-capability-manifest-902.json", "file_sha256": sha(MANIFEST_PATH)}, "benchmark_final_902_before_wave": {"path": "evidence/source/benchmark-final-902-mapping.json", "file_sha256": sha(MAPPING_PATH)}},
    "repository_snapshots": REPOS,
    "counts": {"evaluated": 12, "verified_benchmark_direct_recommended": 8, "documented_ncm_direct_recommended": 4, "completion_credit_recommended": 12},
    "selected_keys_sha256": keys_sha, "selected_lineage_tuple_sha256": lineage_sha,
    "evaluations": evaluations,
    "projected_delta": {"verified_benchmark_direct": 8, "documented_ncm_direct": 4, "eligible_total": 12, "completion_unproved": -12},
}
OUTPUT_PATH.write_text(json.dumps(artifact, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
print(json.dumps({"output": str(OUTPUT_PATH), "sha256": sha(OUTPUT_PATH), "evaluated": 12, "direct": 8, "ncm": 4}, indent=2))
