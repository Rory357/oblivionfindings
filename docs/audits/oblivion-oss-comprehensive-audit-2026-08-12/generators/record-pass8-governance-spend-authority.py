#!/usr/bin/env python3
"""Record the independently reviewed Governance spend-authority Pass-8 finding."""

from __future__ import annotations

import copy
import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


AUDIT = Path(__file__).resolve().parents[1]
SOURCE = AUDIT / "evidence" / "source"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
CURRENT_MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
GENERATED_AT = "2026-08-22T00:15:00+12:00"
FINDING_ID = "GOV-SPEND-AUTHORITY-01"
FEATURE_IDS = [
    "CAP-GOV-SPEND-REQUEST-AUTHORING",
    "CAP-GOV-SPEND-ATTACHMENT-DOWNLOAD",
    "CAP-GOV-SPEND-APPROVAL-DECISION",
]
ROUTE_IDS = [f"ROUTE-{value:04d}" for value in range(1019, 1031)]
PAGE_IDS = ["PAGE-0328", "PAGE-0329", "PAGE-0330", "PAGE-0331"]

PATHS = {
    "manifest": SOURCE / "working-capability-manifest-904.json",
    "benchmark": SOURCE / "benchmark-final-904-mapping.json",
    "inventory": AUDIT / "inventory-904.json",
    "findings": AUDIT / "findings.json",
    "reconciliation": SOURCE / "finding-link-reconciliation.json",
    "official_map": SOURCE / "official-nz-finding-proposition-map.json",
    "pointer": SOURCE / "canonical-audit-inputs.json",
    "pass8": SOURCE / "pass8-governance-spend-authority-904-2026-08-21.json",
    "summary": SOURCE / "final-904-governance-spend-authority-generation-summary.json",
}

PRE_PINS = {
    "manifest": "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    "benchmark": "9a5aa8790a3d681d3f24ad5cf485da02f14db538eed5e65c8308a9d15f9bcf96",
    "inventory": "ac693df595d4d350263eea039a1775c78d06088aba3a2bea8867a1d0e883c99f",
    "findings": "12ad928897c5f7e1f45c61c20816004d630f00636418aeaf6fb174fe6d7794c2",
    "reconciliation": "044cd531f04f14833c9c6c190193e1f48c8d818155d7d309db3165af9b729dcd",
    "official_map": "12cbda8b0b113a32974b13b30dbc6b229c20feaf5696c180c19808a2da8b958e",
    "pointer": "01283bc2a0028ddb7e5e2db8f4aeef3dc556c17b5afb87e8051d83b30f08aa6d",
}

SOURCE_PINS = [
    {"path": "routes/governance.php", "baseline_sha256": "d51b57d4fa8ed8343447aa6771f22014fad5153b02380738ccff976ba4f7ce29", "current_sha256": "d51b57d4fa8ed8343447aa6771f22014fad5153b02380738ccff976ba4f7ce29", "loci": "22,372-392"},
    {"path": "app/Http/Middleware/EnsurePermission.php", "baseline_sha256": "d9477e5fe8d3dd762332be8ddf3929e4e6098d039e625af40a0241a0ab958e30", "current_sha256": "d9477e5fe8d3dd762332be8ddf3929e4e6098d039e625af40a0241a0ab958e30", "loci": "11-27"},
    {"path": "database/seeders/GovernancePermissionsSeeder.php", "baseline_sha256": "124bef53898fb07a561d33b235ac2f3813a3ceca8522529749def99ec722badb", "current_sha256": "124bef53898fb07a561d33b235ac2f3813a3ceca8522529749def99ec722badb", "loci": "91-94,108-179,217-245"},
    {"path": "app/Domain/Governance/Http/Controllers/SpendApprovalController.php", "baseline_sha256": "189546b2730f71d798def6605d6d0a6e6c019ac75afb13fc7eb5885faa6edb70", "current_sha256": "189546b2730f71d798def6605d6d0a6e6c019ac75afb13fc7eb5885faa6edb70", "loci": "18-46,86-117,140-229,245-351"},
    {"path": "app/Domain/Governance/Models/SpendApproval.php", "baseline_sha256": "4e1e69c5cde10178c3ff812d1b92b0e2ca46d8a7dd9308fc5c2932dd6abaa8d3", "current_sha256": "4e1e69c5cde10178c3ff812d1b92b0e2ca46d8a7dd9308fc5c2932dd6abaa8d3", "loci": "13-20,33-82"},
    {"path": "database/migrations/2026_05_20_100100_create_spend_approvals_and_budget_allocations.php", "baseline_sha256": "2e50c1c0cf95440de6a65a51ffe195018258fe18c04715544a27086ec6667fc0", "current_sha256": "2e50c1c0cf95440de6a65a51ffe195018258fe18c04715544a27086ec6667fc0", "loci": "17-57"},
    {"path": "tests/Feature/Governance/GovernanceSpendApprovalsTest.php", "baseline_sha256": "52107a6067d1fbf4c666d9dfd9e04b71c7b871acce419c0eeace06d6612c12f7", "current_sha256": "52107a6067d1fbf4c666d9dfd9e04b71c7b871acce419c0eeace06d6612c12f7", "loci": "78-194"},
    {"path": "app/Models/User.php", "baseline_sha256": "2d5aa8b65854c23b286951f00ee81c893ba9985adc12743caae5fddd372d9deb", "current_sha256": "1bcee6a3fc05e04ec75ef32c1a782d84ecda3ec6e9f191c4ac99775b8adb0519", "loci": "permission fast path; no record authority"},
    {"path": "resources/js/pages/Governance/SpendApprovals/Index.tsx", "baseline_sha256": "a9ca31ce1c2fab5d1776cb7e2ea209ea9cfebe4781892b28fbd0dc1dd7e678b6", "current_sha256": "1d84f315eadcd4d40b4f20cf0930dace6883dde24d180f3a6c84f26776a0cd5b", "loci": "128-134,169-184 audited; action reachability retained"},
    {"path": "resources/js/pages/Governance/SpendApprovals/Show.tsx", "baseline_sha256": "e024979baf607504c46a3823b1dba36f493fbe7fa8f2251806ba253c6e0da076", "current_sha256": "fb64a52fc1430989b52e0bb8bbe2139f5cc06462a9a92287a1f904bf2812e998", "loci": "47-87,100-175,181-240 audited; direct actions retained"},
]


def sha_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha_file(path: Path) -> str:
    return sha_bytes(path.read_bytes())


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def save(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def rel(path: Path) -> str:
    return path.relative_to(AUDIT).as_posix()


def pin(path: Path) -> dict[str, Any]:
    return {"path": rel(path), "sha256": sha_file(path), "bytes": path.stat().st_size}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def git_bytes(ref: str, path: str) -> bytes:
    return subprocess.run(["git", "show", f"{ref}:{path}"], check=True, stdout=subprocess.PIPE).stdout


def verify_source_chain() -> list[dict[str, Any]]:
    verified = []
    for row in SOURCE_PINS:
        baseline = git_bytes(AUDITED_COMMIT, row["path"])
        current = git_bytes(CURRENT_MAIN, row["path"])
        require(sha_bytes(baseline) == row["baseline_sha256"], f"Baseline source drift: {row['path']}")
        require(sha_bytes(current) == row["current_sha256"], f"Current source drift: {row['path']}")
        verified.append(copy.deepcopy(row))
    return verified


def rebuild_reconciliation(payload: dict[str, Any], findings: dict[str, Any], manifest: dict[str, Any]) -> None:
    manifest_ids = {row["working_key"] for row in manifest["targets"]}
    rows = findings["findings"]
    exact = [(row["id"], feature) for row in rows for feature in row.get("feature_ids", []) if feature in manifest_ids]
    exact_findings = {finding_id for finding_id, _ in exact}
    p0p1 = [row for row in rows if row["priority"] in {"P0", "P1"}]
    decisions = [decision for row in rows for decision in row.get("feature_link_reconciliation", {}).get("decisions", [])]
    prior = payload["current_final_id_link_summary"]
    payload["generated_at"] = GENERATED_AT
    payload["status"] = "current_904_literal_link_reconciliation_partial_runtime_unverified"
    payload["current_final_id_link_summary"] = {
        "literal_links": len(exact), "literal_targets": len({feature for _, feature in exact}),
        "explicitly_re_adjudicated_links": prior["explicitly_re_adjudicated_links"] + len(FEATURE_IDS),
        "explicitly_re_adjudicated_findings": sorted(set(prior["explicitly_re_adjudicated_findings"]) | {FINDING_ID}),
        "findings_with_literal_exact_current_id": len(exact_findings),
        "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "p0_p1_with_literal_exact_current_id": len({row["id"] for row in p0p1} & exact_findings),
        "p0_p1_without_literal_exact_current_id": len(p0p1) - len({row["id"] for row in p0p1} & exact_findings),
        "complete": False,
    }
    payload["counts"] = {
        "findings": len(rows), "total_links": sum(len(row.get("feature_ids", [])) for row in rows),
        "findings_with_uncertainty": sum(bool(row.get("feature_link_reconciliation", {}).get("uncertainties")) for row in rows),
        "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "route_intersection_groups": sum(bool(decision.get("route_hits")) for decision in decisions),
        "unique_page_intersection_groups": sum(bool(decision.get("page_hits")) for decision in decisions),
        "one_to_one_groups": sum("one-to-one" in str(decision.get("method", "")).lower() for decision in decisions),
    }
    payload["findings"] = [{"finding_id": row["id"], "feature_ids": row.get("feature_ids", []),
        "literal_current_feature_ids": [feature for feature in row.get("feature_ids", []) if feature in manifest_ids],
        "reconciliation": row.get("feature_link_reconciliation", {})} for row in rows]
    require(payload["counts"] == {"findings": 99, "total_links": 278, "findings_with_uncertainty": 31,
        "findings_without_literal_exact_current_id": 0, "route_intersection_groups": 46,
        "unique_page_intersection_groups": 8, "one_to_one_groups": 104}, f"Reconciliation drift: {payload['counts']}")
    require(payload["current_final_id_link_summary"]["literal_links"] == 179, "Literal-link drift")
    require(payload["current_final_id_link_summary"]["literal_targets"] == 147, "Literal-target drift")
    require(payload["current_final_id_link_summary"]["p0_p1_with_literal_exact_current_id"] == 87, "P0/P1 literal drift")


def finding_payload(template: dict[str, Any]) -> dict[str, Any]:
    row = copy.deepcopy(template)
    zeros = {key: 0 for key in ["discoverability", "comprehension", "learnability", "efficiency", "error_prevention", "recovery", "accessibility", "safety_and_trust", "consistency", "cross_module_continuity"]}
    row.update({
        "id": FINDING_ID, "feature_ids": FEATURE_IDS, "passes": ["P1", "P2", "P4", "P5", "P6", "P7", "P8"],
        "module": "Governance", "submodule": "Spend request, evidence and decision authority",
        "actor_and_job": "A spend requester authors only their own draft and an independent decision-maker decides the exact submitted version with durable evidence.",
        "route_url": {"summary": "Twelve exact spend routes establish authoring, evidence, download and decision boundaries.",
            "route_names": ["governance.spend-approvals.index", "governance.spend-approvals.store", "governance.spend-approvals.show", "governance.spend-approvals.update", "governance.spend-approvals.approve", "governance.spend-approvals.attachments.store", "governance.spend-approvals.attachments.destroy", "governance.spend-approvals.attachments.download", "governance.spend-approvals.edit", "governance.spend-approvals.reject", "governance.spend-approvals.submit", "governance.spend-approvals.create"],
            "route_paths": ["governance/spend-approvals", "governance/spend-approvals/{approval}", "governance/spend-approvals/{approval}/approve", "governance/spend-approvals/{approval}/attachments", "governance/spend-approvals/{approval}/attachments/{attachment}", "governance/spend-approvals/{approval}/attachments/{attachment}/download", "governance/spend-approvals/{approval}/edit", "governance/spend-approvals/{approval}/reject", "governance/spend-approvals/{approval}/submit", "governance/spend-approvals/create"]},
        "frontend_anchor": {"summary": "Create, Edit, Index and Show make the server-owned actions reachable; UI predicates do not protect direct routes.",
            "page_files": ["resources/js/pages/Governance/SpendApprovals/Create.tsx", "resources/js/pages/Governance/SpendApprovals/Edit.tsx", "resources/js/pages/Governance/SpendApprovals/Index.tsx", "resources/js/pages/Governance/SpendApprovals/Show.tsx"], "audited_commit": AUDITED_COMMIT},
        "visual_context": {"visual_id": "PAGE-0328 through PAGE-0331 source relations only", "classification": "Source-inferred", "role": "Spend requester and independent decision-maker; runtime unavailable", "site_scope": "Global-versus-owned Governance visibility is an explicit owner decision, not a proven privacy defect", "viewport": "Not safely reproduced", "state": "Draft, submitted and decided source trace", "pattern_type": "backend/source finding", "component_anchor": "Governance/SpendApprovals pages", "screenshot_reference": "None—no browser or mutation is claimed", "internal_baseline": "Server-governed own-draft authoring, immutable submitted evidence and version-bound independent decision"},
        "pattern_implementation": "Static route/middleware/seeder/controller/model/schema/test/UI review at audited and current-main commits; no spend record was read or mutated.",
        "backend_anchors": [item["path"] + ":" + item["loci"] for item in SOURCE_PINS],
        "current_behavior": "Request-capable actors can update and submit another requester's draft because update/submit check only draft state. Attachment ownership is checked only while draft, so any request-capable actor can add or remove evidence after submission or decision. Approve/reject revalidate no locked version and the same seeded actor may request and approve. Linked Site/finance/governance IDs are not validated through canonical relationships. Global list/show/download may be intentional Governance policy and is not independently claimed as a privacy breach.",
        "current_workflow": {"summary": "Source-reviewed 12-route/4-page authority boundary; no representative mutation, download, race or browser flow was executed.", "failure_sequence": "A request-capable actor directly updates or submits another requester's draft, changes evidence after submission/decision, or a stale concurrent approver/rejecter records conflicting outcomes without locked version/content binding.", "boundary": "Requester ownership, explicit manage-any authority, submitted evidence immutability, linked-object validity, independent decision and replay/concurrency.", "completion_evidence": "Static audited/current source equivalence only; no deployed unauthorized action or financial loss is claimed."},
        "ease_evidence": {"validation_status": "Blocked—source finding retained; no representative runtime or ten-dimension validation executed", "evidence_basis": "Static source and existing-test trace only", "current_scores": zeros, "friction": {"completion_time": "Not measured", "step_count": "Not measured", "required_field_count": "Not measured", "decision_count": "Governance/Finance owner decision required", "context_switches": "Not measured", "dead_ends": "Runtime unknown", "recovery_path": "Treat submitted evidence and decisions as untrusted until requester, version and decision provenance are reconciled."}, "target_scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5}, "independent_review": "Independent review accepted one P1 but removed the unproven Site-privacy/global-view claim and prohibited source_type/source_id injection wording."},
        "evidence": {"anchors": [item["path"] + ":" + item["loci"] for item in SOURCE_PINS], "existing_tests": ["GovernanceSpendApprovalsTest uses an admin actor for request and approval happy paths", "No cross-requester, non-draft attachment, independent decision, linked-object mismatch, replay, rollback or concurrency negative exists"], "tests_executed": False, "browser_claim_limit": "No credential, spend record, file, decision, viewport, focus or role flow was exercised."},
        "problem_root_cause": "Coarse route permissions are treated as record authority; no canonical policy/scope/locked command binds requester, evidence version, linked objects and terminal decision.",
        "impact": "A specialised authenticated Governance actor can rewrite or submit another actor's draft, alter submitted/decided evidence, bind unrelated records, or produce race-dependent decision provenance. Actual use or loss remains unverified.",
        "benchmark": {"selected": "No new comparator credit from this finding", "url_and_sha": "", "verified_behavior": "Finding evidence is independent of benchmark completion.", "outcome": "Benchmark mapping unchanged", "no_match_evidence": "Not an NCM adjudication."},
        "neutral_requirements": "Bind own-draft authoring, submitted evidence and independent decisions to one authorised, versioned, locked spend aggregate with explicit global-view policy.",
        "better_oblivion_design": "Preserve current routes/pages while routing authoring, submission, evidence and decision through one policy/scope/locked command owner.",
        "target_ease": {"scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5}, "measurable_outcome": "A requester safely authors their own draft and an independent approver decides one immutable submitted version; peer and stale actions fail clearly without partial effects."},
        "cross_module_effects": "Preserve canonical budget, line, funding, donor, resolution, audit, file and reporting owners; no source/family inheritance across the three final IDs.",
        "rbac_privacy": "Choose and document either explicit global Governance visibility or fail-closed owned/Site scope; global visibility never substitutes for request/manage/approve action authority.",
        "priority": "P1", "effort": "M", "dependencies_sequence": "Governance/Finance owners define global visibility, manage-any and requester/decider separation; then implement locked versioned evidence and run isolated tests/browser validation.",
        "proposed_owner": "Governance Product Owner, Board Secretary, Finance Owner and Backend Assurance", "confidence": "High for static financial-authority gaps; runtime occurrence and impact remain unverified",
        "source_boundary": "Internal source proves the authority gap. HISF separation/logging and Governance ownership frame risk only; no legal/accounting/certification claim is made.",
        "interim_safeguard": "Restrict request/approve grants to named trusted actors and reconcile non-owner draft changes, post-submission evidence changes and conflicting decisions before reliance.",
        "acceptance_criteria": ["Requester may alter or submit only their own draft unless a separately named manageAny authority is granted.", "Submitted/decided evidence is immutable or revised through a governed path that invalidates the prior submission/decision.", "Site, budget, line, funding, donor and resolution are resolved through canonical authorised relationships and parent equality.", "Requester/submitter and decision-maker are independent under an owner-approved rule.", "A transaction and row lock revalidate submitted state, version and content digest; replay converges and conflicts fail without partial effects.", "Global view/download policy is explicit; otherwise inaccessible records are filtered and direct IDs concealed."],
        "missing_tests": ["Same-Site cross-requester update/submit", "Non-draft attachment add/delete", "Self-approval and independent-approver positive", "Mismatched linked objects", "Stale/concurrent approve-vs-reject and rollback", "Evidence-version download and selected global-view policy", "Representative browser error/recovery"],
        "validation_plan": ["Add current-main policy/feature tests for requester and decision separation", "Use disposable MySQL for locked replay/concurrency/rollback and exact audit assertions", "Exercise file-write/DB/audit fault recovery", "Run requester/approver/no-authority browser tasks after remediation", "Retain open status until merged-to-main and exact runtime evidence are canonical"],
        "official_sources": [{"id": "NZ-HISO-10029-2022", "title": "HISO 10029:2022 Health Information Security Framework", "authority": "Health New Zealand / HISO", "url": "https://static.info.content.health.nz/docs/HISO/HISO%2010029%20Health%20Information%20Security%20Framework.pdf", "supporting_url": "https://www.healthnz.govt.nz/health-professionals/guidance-standards/topic/data-and-standards/health-information-standards/approved-health-information-standards/information-governance", "inspected_date": "2026-08-12"}],
        "statement_types": {"source": "Cross-requester draft mutation, non-draft evidence mutation, linked-object and unlocked-decision gaps are source-observed at pinned commits.", "official_source": "HISF-SOD, HISF-LOG and OWNER-GOVERNANCE frame assurance only; they do not prescribe the exact software control.", "inference": "Financial integrity impact is bounded inference; no deployed exploit, privacy breach or loss was observed.", "specialist_decision": "P1 priority, global-view policy, manage-any role and requester/decider separation require Governance and Finance ownership."},
        "official_source_proposition_keys": ["HISF-SOD", "HISF-LOG", "OWNER-GOVERNANCE"],
        "feature_link_reconciliation": {"method": "route-first: exact 12 spend routes and controller authority boundary; four pages corroborate without family inheritance", "projection_status": "literal_current_904_manifest_links_present; runtime_and_remediation_unverified", "legacy_feature_ids": [], "decisions": [{"legacy_family_id": "independent-pass8-governance-spend-authority-2026-08-21", "method": "source-proven exact current target route/backend intersection", "feature_ids": FEATURE_IDS, "route_hits": ROUTE_IDS, "page_hits": [{"page_id": page_id, "feature_ids": FEATURE_IDS if page_id == "PAGE-0331" else [FEATURE_IDS[0]]} for page_id in PAGE_IDS], "source_anchors": [item["path"] + ":" + item["loci"] for item in SOURCE_PINS], "evidence": "Fresh Governance Pass-8 review traced permissions, requester ownership, evidence, linked objects, decision concurrency, UI and tests without runtime credit.", "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN}], "uncertainties": [{"reason_code": "global_governance_visibility_and_runtime_unexecuted", "detail": "Global list/show/download may be intended; representative roles, record actions, concurrency and effects were not executed.", "smallest_next_evidence": "Owner decides global visibility; after remediation run isolated requester/peer/approver and replay/concurrency evidence."}]},
        "remediation": {"status": "open", "note": "No isolated remediation branch or runtime verification is recorded."},
    })
    return row


def validate_existing() -> None:
    findings = load(PATHS["findings"])
    require(sum(row["id"] == FINDING_ID for row in findings["findings"]) == 1, "Existing finding duplication")
    pointer = load(PATHS["pointer"])
    require(pointer["artifacts"]["pass8_governance_spend_authority"] == pin(PATHS["pass8"]), "Pass8 pointer drift")
    require(pointer["artifacts"]["governance_spend_authority_generation_summary"] == pin(PATHS["summary"]), "Summary pointer drift")
    summary = load(PATHS["summary"])
    for key in ("findings", "reconciliation", "official_map", "pass8"):
        require(summary["outputs"][key] == pin(PATHS[key]), f"Existing output drift: {key}")
    print(json.dumps({"status": "idempotent_no_change", "finding_id": FINDING_ID}, indent=2))


if any(row["id"] == FINDING_ID for row in load(PATHS["findings"])["findings"]):
    validate_existing()
    raise SystemExit(0)

for name, expected in PRE_PINS.items():
    require(sha_file(PATHS[name]) == expected, f"Input SHA drift: {name}")

verified_sources = verify_source_chain()
manifest, benchmark, inventory = load(PATHS["manifest"]), load(PATHS["benchmark"]), load(PATHS["inventory"])
findings, reconciliation, official_map, pointer = load(PATHS["findings"]), load(PATHS["reconciliation"]), load(PATHS["official_map"]), load(PATHS["pointer"])
manifest_ids = {row["working_key"] for row in manifest["targets"]}
require(set(FEATURE_IDS) <= manifest_ids, "Feature ID missing from manifest")
route_rows = {row["route_id"]: row for row in inventory["routes"]}
expected_route_owners = {
    "ROUTE-1019": [FEATURE_IDS[0]], "ROUTE-1020": [FEATURE_IDS[0]], "ROUTE-1021": [FEATURE_IDS[0]], "ROUTE-1022": [FEATURE_IDS[0]],
    "ROUTE-1023": [FEATURE_IDS[2]], "ROUTE-1024": [FEATURE_IDS[0]], "ROUTE-1025": [FEATURE_IDS[0]], "ROUTE-1026": [FEATURE_IDS[1]],
    "ROUTE-1027": [FEATURE_IDS[0]], "ROUTE-1028": [FEATURE_IDS[2]], "ROUTE-1029": [FEATURE_IDS[0]], "ROUTE-1030": [FEATURE_IDS[0]],
}
for route_id, owners in expected_route_owners.items():
    require(route_rows[route_id]["working_canonical_feature_ids"] == owners, f"Route owner drift: {route_id}")
page_rows = {row["page_id"]: row for row in inventory["pages"]}
require(all(page_id in page_rows for page_id in PAGE_IDS), "Spend page drift")
require(set(page_rows["PAGE-0331"]["working_canonical_feature_ids"]) == set(FEATURE_IDS), "Spend Show owners drift")
gov_rows = [row for row in benchmark["targets"] if row["canonical_module"] == "GOVERNANCE"]
gov_decided = sum(bool(row["completion_credit"]) for row in gov_rows)
require(len(gov_rows) == 62 and gov_decided == 23, "Governance benchmark partition drift")

pass8 = {"schema_version": "1.0.0", "artifact": "pass8-governance-spend-authority-904-2026-08-21", "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN,
    "status": "source_only_pass8_challenge_no_module_completion_credit",
    "module_selection": {"module": "GOVERNANCE", "targets": 62, "classes": {"H": 50, "D": 12, "M": 0},
        "benchmark_decided": gov_decided, "benchmark_unproved": 62 - gov_decided,
        "selection_reason": "Weighted unresolved surface/risk/runtime screen selected Governance; all representative task/runtime gates remain zero."},
    "pass_reconciliation": {"P1": {"static_identity_reviewed": 62, "denominator": 62}, "P2": {"representative_persisted_tasks_executed": 0, "denominator": 50}, "P3": {"benchmark_or_ncm_decided": gov_decided, "denominator": 62, "unproved": 62 - gov_decided}, "P4": {"representative_security_and_visual_tasks_executed": 0, "denominator": 50}, "P5": {"static_architecture_reviewed": 62, "runtime_data_effects_verified": 0, "denominator": 62}, "P6": {"fresh_exact_source_finding_official_links": 3, "denominator": 62, "boundary": "Official propositions frame risk only."}, "P7": {"fresh_source_constraint_failure_links": 3, "tests_executed": 0, "denominator": 62}, "P8": {"fresh_module_challenge": 1, "module_completion_credit": 0, "denominator": 62}},
    "new_finding": {"id": FINDING_ID, "priority": "P1", "feature_ids": FEATURE_IDS, "route_ids": ROUTE_IDS, "page_ids": PAGE_IDS, "runtime_credit_delta": 0, "browser_credit_delta": 0, "completion_credit_delta": 0},
    "source_chain": verified_sources,
    "duplicate_boundary": "No current finding owns the exact spend feature IDs, route IDs, controller or spend-approval semantics; GOV-NESTED-01 and GOV-RESOLUTION-QUORUM-01 are distinct aggregates.",
    "wording_corrections": ["No source-proven foreign-Site privacy claim; global Governance visibility requires an explicit owner decision.", "Do not claim source_type/source_id injection because validatePayload does not accept them."],
    "completion_boundary": "No runtime, browser, focus, keyboard, representative-user, remediation, release or overall completion credit."}
save(PATHS["pass8"], pass8)

template = next(row for row in findings["findings"] if row["id"] == "CLIN-PROTOCOL-SCHEDULING-01")
findings["findings"].append(finding_payload(template))
findings["findings"].sort(key=lambda row: row["id"])
findings["counts"]["P1"] = 66
links = findings["counts"]["feature_link_reconciliation"]
links.update({"benchmark_mapping": {"eligible": 491, "verified_benchmark": 402, "documented_no_credible_match": 89, "completion_unproved": 413},
    "findings": 99, "total_links": 278, "literal_exact_current_links": 179, "literal_exact_current_targets": 147,
    "findings_with_literal_exact_current_id": 99, "p0_p1_with_literal_exact_current_id": 87,
    "p0_p1_without_literal_exact_current_id": 0, "findings_with_uncertainty": 31,
    "findings_without_literal_exact_current_id": 0, "route_intersection_groups": 46, "unique_page_intersection_groups": 8})
findings["audit_status"] = "Blocked—not comprehensive or complete. The canonical 904-target register is current (790H/111D/3M). Benchmark/NCM completion credit is 491/904, visual final-ID linkage is 8,168/8,753, material-state linkage is 3,948/4,312, and 99 source-backed findings are retained. All 87/87 P0/P1 findings contain a literal current-manifest ID; runtime remains unexecuted."
rebuild_reconciliation(reconciliation, findings, manifest)

require(official_map["denominator"] == official_map["reviewed"] == 56, "Official-map base drift")
official_map["findings"].append({"finding_id": FINDING_ID, "proposition_keys": ["HISF-SOD", "HISF-LOG", "OWNER-GOVERNANCE"]})
official_map["findings"].sort(key=lambda row: row["finding_id"])
official_map["denominator"] = official_map["reviewed"] = 57
official_map["coverage_percent"] = 100.0
official_map["owner_boundary_rows"] = sum(any(str(key).startswith("OWNER-") for key in row["proposition_keys"]) for row in official_map["findings"])
require(official_map["owner_boundary_rows"] == 31, "Official owner-boundary drift")

save(PATHS["findings"], findings)
save(PATHS["reconciliation"], reconciliation)
save(PATHS["official_map"], official_map)
outputs = {key: pin(PATHS[key]) for key in ("findings", "reconciliation", "official_map", "pass8")}
summary = {"schema_version": "1.0.0", "artifact": "final-904-governance-spend-authority-generation-summary",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN,
    "finding_id": FINDING_ID, "status": "generated_open_p1_static_only_runtime_and_completion_blocked",
    "inputs": {key: {"path": rel(PATHS[key]), "sha256": value, "bytes": PATHS[key].stat().st_size} for key, value in PRE_PINS.items()},
    "outputs": outputs, "counts": {"findings": {"total": 99, "P0": 21, "P1": 66, "P2": 12},
        "links": {"total": 278, "literal": 179, "literal_targets": 147, "p0_p1_literal": 87},
        "official_map": {"denominator": 57, "reviewed": 57, "owner_boundary_rows": 31},
        "benchmark": {"eligible": 491, "unproved": 413}},
    "credit_boundary": {"runtime": 0, "browser": 0, "remediation": 0, "completion": 0},
    "idempotence": "A second run validates current outputs and pointer entries and performs no write."}
save(PATHS["summary"], summary)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"findings": outputs["findings"], "finding_link_reconciliation": outputs["reconciliation"],
    "official_nz_finding_proposition_map": outputs["official_map"], "pass8_governance_spend_authority": outputs["pass8"],
    "governance_spend_authority_generation_summary": pin(PATHS["summary"])})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
save(PATHS["pointer"], pointer)
print(json.dumps({"status": "generated", "finding_id": FINDING_ID, "outputs": outputs,
                  "summary": pin(PATHS["summary"]), "pointer": pin(PATHS["pointer"])}, indent=2))
