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
GENERATED_AT = "2026-08-22T00:05:00+12:00"
FINDING_ID = "HS-PROCEDURE-APPROVAL-ASSURANCE-01"
FEATURE_IDS = [
    "CAP-HS-PROCEDURE-AUTHORING-EVIDENCE",
    "CAP-HS-PROCEDURE-REVIEW-APPROVAL",
]
ROUTE_IDS = ["ROUTE-1178", "ROUTE-1180", "ROUTE-1191", "ROUTE-1182"]
PAGE_ID = "PAGE-0393"

PATHS = {
    "manifest": SOURCE / "working-capability-manifest-904.json",
    "benchmark": SOURCE / "benchmark-final-904-mapping.json",
    "inventory": AUDIT / "inventory-904.json",
    "findings": AUDIT / "findings.json",
    "reconciliation": SOURCE / "finding-link-reconciliation.json",
    "official_map": SOURCE / "official-nz-finding-proposition-map.json",
    "pointer": SOURCE / "canonical-audit-inputs.json",
    "pass8": SOURCE / "pass8-health-safety-procedure-approval-904-2026-08-21.json",
    "summary": SOURCE / "final-904-health-safety-procedure-approval-generation-summary.json",
}

PRE_PINS = {
    "manifest": "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    "benchmark": "dcda86ed2a6a4328ddc2ba23780641afd2daafe860d310c3c89218ffa8a07458",
    "inventory": "379b672e77be24ac4d1b8829099b41c5e6f790e58604d8b1218f4aaf20a2bef0",
    "findings": "f0354838685d1e59a528979a273a1ffb1ec4bccba984d87e5939d63085146067",
    "reconciliation": "d198541f594051b93a4ccb6802498a83bac9f3aecd940a10b87d8770837933ff",
    "official_map": "37ac7bb9f197c4aa98cb840bb709718d7a969e489ac72fbf89bd47f183891029",
    "pointer": "0e9e9ef42b074b540723e09b049369fcd85387aaa8fc903c717c80c5916a5213",
}

SOURCE_PINS = [
    {
        "path": "routes/health-safety.php",
        "baseline_sha256": "4b50e8e6fb8b58f22de143c544c9ae9a747ef2f1283d3e15f7f6651374cccf2c",
        "current_sha256": "1d763248f145410e2dede3213c112d2e78f99b4d48123e9da0474f4eb883dd32",
        "loci": "procedure routes including store, update, submit-for-review and approve",
    },
    {
        "path": "app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php",
        "baseline_sha256": "ee3b8474ce5dcad6eb7c9b6cb9281c30787318c10f1e5ee4c35e9373045591e1",
        "current_sha256": "ee3b8474ce5dcad6eb7c9b6cb9281c30787318c10f1e5ee4c35e9373045591e1",
        "loci": "189-234,766-786",
    },
    {
        "path": "app/Models/SafeWorkProcedure.php",
        "baseline_sha256": "e3ace2247a1b31af97f6b15817812d5f3d2191203b042ee1ea6029324bd4b3ef",
        "current_sha256": "e3ace2247a1b31af97f6b15817812d5f3d2191203b042ee1ea6029324bd4b3ef",
        "loci": "14-55",
    },
    {
        "path": "app/Models/SafeWorkProcedureVersion.php",
        "baseline_sha256": "32d08f780d19cc86219b4fd63e90c699d2b3f94b7d82beac015898e71cb76cd0",
        "current_sha256": "32d08f780d19cc86219b4fd63e90c699d2b3f94b7d82beac015898e71cb76cd0",
        "loci": "version snapshot model",
    },
    {
        "path": "database/migrations/2026_06_20_130003_grant_procedures_permissions.php",
        "baseline_sha256": "ad7c8c7bee7e68edcc806df2362765365b6d130372531ee8e6a7768f4da62bb5",
        "current_sha256": "ad7c8c7bee7e68edcc806df2362765365b6d130372531ee8e6a7768f4da62bb5",
        "loci": "20-64",
    },
    {
        "path": "database/migrations/2026_03_28_300003_create_safe_work_procedures_tables.php",
        "baseline_sha256": "6dd0e6be15fe67d010f71a8ad7d2328631dc943cbaf3abe6c6167e8ea47a525b",
        "current_sha256": "6dd0e6be15fe67d010f71a8ad7d2328631dc943cbaf3abe6c6167e8ea47a525b",
        "loci": "11-58",
    },
    {
        "path": "database/migrations/2026_06_20_130002_add_owner_and_cadence_to_safe_work_procedures.php",
        "baseline_sha256": "a423a3b81a7b38d0619922817fdb2c5ed6b4ce99d03b8cb16ff0ae38e1c231f7",
        "current_sha256": "a423a3b81a7b38d0619922817fdb2c5ed6b4ce99d03b8cb16ff0ae38e1c231f7",
        "loci": "owner and review cadence schema",
    },
    {
        "path": "database/seeders/RbacSeeder.php",
        "baseline_sha256": "da02e5e9d1970a18e4ef0a027775457e55b0f2a6c0c409f1c23d708adef97fb9",
        "current_sha256": "ab906f1096ab2dbfad92bac69884334905f315345e23a11c9d1ad170bdda64c7",
        "loci": "609,674,801,829 at audited source; equivalent procedure grants retained on current main",
    },
    {
        "path": "tests/Feature/HealthSafety/SafeWorkProcedureTest.php",
        "baseline_sha256": "570652f974597b6952075b5241586b91382be892a414431f7c7bfd217f2516e1",
        "current_sha256": "570652f974597b6952075b5241586b91382be892a414431f7c7bfd217f2516e1",
        "loci": "174-195,294-303",
    },
    {
        "path": "resources/js/pages/health-safety/procedures/index.tsx",
        "baseline_sha256": "cd5f21706cffcde7407bf77714e09ece02d305a99df7393848125b8a7b869d4d",
        "current_sha256": "a5447c7b1be1a8cd18f3c0acf14b37d6ae2f799c3e39fd8a7084cb60d67efa56",
        "loci": "216-218 at audited source; under-review presentation gate retained on current main",
    },
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


def git(*args: str) -> str:
    return subprocess.check_output(["git", *args], cwd=AUDIT.parents[2], text=True).strip()


def git_bytes(ref: str, path: str) -> bytes:
    return subprocess.check_output(["git", "show", f"{ref}:{path}"], cwd=AUDIT.parents[2])


def verify_source_chain() -> list[dict[str, Any]]:
    require(git("rev-parse", "HEAD") == AUDITED_COMMIT, "Audited HEAD drift")
    require(git("rev-parse", "refs/remotes/origin/main") == CURRENT_MAIN, "Current-main ref drift")
    verified = []
    for row in SOURCE_PINS:
        baseline = git_bytes(AUDITED_COMMIT, row["path"])
        current = git_bytes(CURRENT_MAIN, row["path"])
        require(sha_bytes(baseline) == row["baseline_sha256"], f"Baseline source drift: {row['path']}")
        require(sha_bytes(current) == row["current_sha256"], f"Current-main source drift: {row['path']}")
        verified.append(copy.deepcopy(row))
    controller = git_bytes(AUDITED_COMMIT, SOURCE_PINS[1]["path"]).decode("utf-8")
    for token in ("function submitForReview", "function approve", "function requestChanges", "approved_by", "approved_at", "snapshotNewVersion"):
        require(token in controller, f"Controller semantic token missing: {token}")
    require("status !== 'under_review'" not in controller[controller.index("function approve"):controller.index("function requestChanges")], "Approval state guard appeared")
    return verified


def finding_payload(template: dict[str, Any]) -> dict[str, Any]:
    row = copy.deepcopy(template)
    row.update({
        "id": FINDING_ID,
        "feature_ids": FEATURE_IDS,
        "passes": ["P1", "P2", "P5", "P6", "P7", "P8"],
        "module": "Health and safety",
        "submodule": "Safe-work procedure review and approval assurance",
        "actor_and_job": "An independent H&S approver reviews the exact submitted procedure version and records a durable approval decision before workers rely on it.",
        "route_url": {
            "summary": "Four exact procedure authoring/review routes establish the write and approval boundary.",
            "route_names": [
                "health-safety.procedures.store",
                "health-safety.procedures.update",
                "health-safety.procedures.submit-for-review",
                "health-safety.procedures.approve",
            ],
            "route_paths": [
                "health-safety/procedures",
                "health-safety/procedures/{procedure}",
                "health-safety/procedures/{procedure}/submit-for-review",
                "health-safety/procedures/{procedure}/approve",
            ],
        },
        "frontend_anchor": {
            "summary": "The procedure register displays Approve only for under-review records; this presentation gate does not protect the direct POST endpoint.",
            "page_files": ["resources/js/pages/health-safety/procedures/index.tsx"],
            "audited_commit": AUDITED_COMMIT,
        },
        "visual_context": {
            "visual_id": "PAGE-0393 source relation only",
            "classification": "Source-inferred",
            "role": "H&S approver; representative runtime unavailable",
            "site_scope": "Procedure authority and Site scope not executed",
            "viewport": "Not safely reproduced",
            "state": "Under-review action presentation and direct endpoint source trace",
            "pattern_type": "backend/source finding",
            "component_anchor": "resources/js/pages/health-safety/procedures/index.tsx",
            "screenshot_reference": "None—no browser or approval action is claimed",
            "internal_baseline": "Server-governed procedure state transition and immutable decision evidence",
        },
        "pattern_implementation": "Static route/controller/model/schema/permission/test/UI review at the audited commit and current main; no procedure transition was executed.",
        "backend_anchors": [
            "routes/health-safety.php procedure routes",
            "app/Http/Controllers/HealthSafety/SafeWorkProcedureController.php:189-234,766-786",
            "app/Models/SafeWorkProcedure.php:14-55",
            "app/Models/SafeWorkProcedureVersion.php",
            "database/migrations/2026_03_28_300003_create_safe_work_procedures_tables.php:11-58",
            "database/migrations/2026_06_20_130002_add_owner_and_cadence_to_safe_work_procedures.php",
            "database/migrations/2026_06_20_130003_grant_procedures_permissions.php:20-64",
            "database/seeders/RbacSeeder.php:609,674,801,829 at audited source",
            "tests/Feature/HealthSafety/SafeWorkProcedureTest.php:174-195,294-303",
        ],
        "current_behavior": "At both pinned source snapshots, approve validates only optional note/review_date and has no under-review precondition, creator/latest-editor/submitter separation, transaction, row lock, expected-version/content-digest check or deterministic replay binding. Direct POST can approve draft/archived records and re-approve an approved record, overwriting approved_by/approved_at. Generic audit changes and version snapshots exist, but approval lacks an immutable approval-specific attestation bound to submitted content; an optional note creates a snapshot only when non-empty.",
        "current_workflow": {
            "summary": "Source-reviewed four-route authoring/review boundary; no procedure, approval, concurrency or browser flow was executed.",
            "failure_sequence": "An actor with procedures.approve posts directly to the approval endpoint for a draft, archived, self-authored, stale or already-approved procedure; the controller writes approved status and mutable approval fields without binding the decision to an immutable submitted version.",
            "boundary": "Procedure lifecycle state, independent reviewer separation, submitted-content identity, decision provenance, replay and concurrency.",
            "completion_evidence": "Static audited/current source equivalence only; no deployed approval, unsafe decision or harm is claimed.",
        },
        "ease_evidence": {
            "validation_status": "Blocked—source finding retained; no representative runtime or ten-dimension validation executed",
            "evidence_basis": "Static source and existing-test trace only",
            "current_scores": {key: 0 for key in ["discoverability", "comprehension", "learnability", "efficiency", "error_prevention", "recovery", "accessibility", "safety_and_trust", "consistency", "cross_module_continuity"]},
            "friction": {
                "completion_time": "Not measured",
                "step_count": "Not measured",
                "required_field_count": "Not measured",
                "decision_count": "H&S owner decision required",
                "context_switches": "Not measured",
                "dead_ends": "Runtime unknown",
                "recovery_path": "Keep approval blocked unless the exact submitted version and independent decision can be verified.",
            },
            "target_scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
            "independent_review": "Independent source review accepted a new P1, corrected the audit/version wording, and rejected runtime, harm and P0 claims.",
        },
        "evidence": {
            "anchors": [item["path"] + ":" + item["loci"] for item in SOURCE_PINS],
            "existing_tests": [
                "SafeWorkProcedureTest covers draft submission, valid under-review approval and view-only denial",
                "It does not cover self-approval, invalid-state approval, stale version, replay, mandatory attestation or concurrency",
            ],
            "tests_executed": False,
            "browser_claim_limit": "No credential, procedure record, transition, persistence effect, viewport, focus or role flow was exercised.",
        },
        "problem_root_cause": "The approval endpoint treats broad permission plus optional form fields as sufficient, rather than owning a guarded, independently attested transition over an immutable submitted procedure version.",
        "impact": "A privileged workflow actor can bypass intended review state and separation, or overwrite approval provenance after content/state drift, weakening the assurance workers rely on. Actual use or harm remains unverified.",
        "benchmark": {
            "selected": "No new comparator credit from this finding",
            "url_and_sha": "",
            "verified_behavior": "The source finding is independent of benchmark coverage and grants no comparator or product completion credit.",
            "outcome": "Benchmark mapping unchanged",
            "no_match_evidence": "Not an NCM adjudication; benchmark research remains separately governed.",
        },
        "neutral_requirements": "Approve only the exact submitted procedure version from the valid review state, by an authorised independent reviewer, with atomic immutable decision evidence and deterministic replay behavior.",
        "better_oblivion_design": "Preserve the native procedure UI while routing submit/review/approve through one locked lifecycle owner that binds actor, state, version, digest, decision, reason and time.",
        "target_ease": {
            "scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
            "measurable_outcome": "An authorised independent reviewer can approve one exact submitted version once, while invalid state, self-approval, stale content and conflicting replay are clearly refused.",
        },
        "cross_module_effects": "Preserve procedure, acknowledgement, archive, attachment, export and audit owners; do not infer approval credit across PAGE-0393's other capability owners.",
        "rbac_privacy": "Keep action authority separate from Site/direct-object scope and from reviewer independence; explicit global Site authority never substitutes for procedures.approve or separation.",
        "priority": "P1",
        "effort": "M",
        "dependencies_sequence": "H&S owner defines independent-review and reapproval policy; add locked/version-bound lifecycle evidence; test replay/concurrency; then run representative role/browser validation.",
        "proposed_owner": "Health & Safety Product Owner and Backend Assurance",
        "confidence": "High for the static lifecycle and provenance gap; runtime reachability, frequency and harm remain unverified",
        "source_boundary": "Audited-baseline and current-main source are separately pinned. WorkSafe risk guidance and H&S owner review frame assurance only; they do not mandate this exact implementation or prove legal non-compliance.",
        "interim_safeguard": "Until the transition is governed, H&S owners independently reconcile approved procedures to their submitted version/history and restrict approval duty assignment.",
        "acceptance_criteria": [
            "Approval is accepted only from under_review and rejected for draft, archived, cancelled or already-approved state unless a separately governed reapproval workflow applies.",
            "Approver is distinct from creator and the submitted version's latest author/submitter.",
            "A transaction and row lock revalidate expected submitted version and content digest before mutation.",
            "Decision evidence immutably records actor, time, submitted version, digest, outcome and mandatory reason/attestation atomically with status.",
            "Same-key replay converges and conflicting replay, stale content and concurrent approval fail without partial effects.",
            "Same-Site positive and concealed foreign-Site/direct-object denial are independently verified.",
        ],
        "missing_tests": [
            "Self-approval and same-author denial",
            "Draft, archived and repeated-approval rejection",
            "Stale submitted version/content digest",
            "Idempotent replay, conflicting replay, concurrency and rollback",
            "Independent reviewer same-Site positive and foreign-Site concealment",
            "Representative browser error/recovery and approval evidence",
        ],
        "validation_plan": [
            "Add current-main feature tests for lifecycle state and independent reviewer separation",
            "Use disposable MySQL to prove locked stale/replay/concurrent/rollback behavior",
            "Verify immutable decision/version/digest persistence and no partial side effects",
            "Run representative H&S author and independent approver browser tasks at required viewports after remediation",
            "Retain open status until merged-to-main and exact runtime evidence are canonical",
        ],
        "official_sources": [{
            "id": "NZ-WORKSAFE-RISK",
            "title": "WorkSafe New Zealand risk management guidance",
            "authority": "WorkSafe New Zealand",
            "url": "https://www.worksafe.govt.nz/managing-health-and-safety/managing-risks/",
            "supporting_url": "",
            "inspected_date": "2026-08-12",
        }],
        "statement_types": {
            "source": "The unguarded approval transition, mutable fields, generic audit/version support, broad procedure permissions and test gaps are source-observed at pinned commits.",
            "official_source": "WS-RISK and OWNER-HS frame assurance and specialist ownership only; they do not prescribe the exact software control.",
            "inference": "Approval-integrity impact is a bounded source inference; no deployed bypass, unsafe procedure reliance or harm was observed.",
            "specialist_decision": "P1 priority, reviewer-separation roles, reapproval semantics and attestation content require H&S ownership.",
        },
        "official_source_proposition_keys": ["WS-RISK", "OWNER-HS"],
        "feature_link_reconciliation": {
            "method": "route-first: exact four procedure write/review routes and controller transition; PAGE-0393 corroborates but supplies no family inheritance",
            "projection_status": "literal_current_904_manifest_links_present; runtime_and_remediation_unverified",
            "legacy_feature_ids": ["HS-SAFE-WORK-PROCEDURE"],
            "decisions": [{
                "legacy_family_id": "independent-pass8-health-safety-procedure-approval-2026-08-21",
                "method": "source-proven exact current target route/backend intersection",
                "feature_ids": FEATURE_IDS,
                "route_hits": ROUTE_IDS,
                "page_hits": [{"page_id": PAGE_ID, "feature_id": feature_id} for feature_id in FEATURE_IDS],
                "source_anchors": [item["path"] + ":" + item["loci"] for item in SOURCE_PINS],
                "evidence": "Fresh Health & Safety Pass-8 review traced authoring, submit, approval, version, permission, UI and test boundaries without runtime credit.",
                "audited_commit": AUDITED_COMMIT,
                "current_main_static_cross_check": CURRENT_MAIN,
            }],
            "uncertainties": [{
                "reason_code": "runtime_procedure_approval_and_representative_roles_unexecuted",
                "detail": "Static evidence supports the finding; deployed role assignment, direct requests, persistence behavior and harm remain unverified.",
                "smallest_next_evidence": "After remediation, execute a disposable two-user procedure submit/approve lane with stale, self, wrong-state, replay and concurrent negatives.",
            }],
        },
        "remediation": {"status": "open", "note": "No isolated remediation branch or runtime verification is recorded."},
    })
    return row


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
        "literal_links": len(exact),
        "literal_targets": len({feature for _, feature in exact}),
        "explicitly_re_adjudicated_links": prior["explicitly_re_adjudicated_links"] + len(FEATURE_IDS),
        "explicitly_re_adjudicated_findings": sorted(set(prior["explicitly_re_adjudicated_findings"]) | {FINDING_ID}),
        "findings_with_literal_exact_current_id": len(exact_findings),
        "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "p0_p1_with_literal_exact_current_id": len({row["id"] for row in p0p1} & exact_findings),
        "p0_p1_without_literal_exact_current_id": len(p0p1) - len({row["id"] for row in p0p1} & exact_findings),
        "complete": False,
    }
    payload["counts"] = {
        "findings": len(rows),
        "total_links": sum(len(row.get("feature_ids", [])) for row in rows),
        "findings_with_uncertainty": sum(bool(row.get("feature_link_reconciliation", {}).get("uncertainties")) for row in rows),
        "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "route_intersection_groups": sum(bool(decision.get("route_hits")) for decision in decisions),
        "unique_page_intersection_groups": sum(bool(decision.get("page_hits")) for decision in decisions),
        "one_to_one_groups": sum("one-to-one" in str(decision.get("method", "")).lower() for decision in decisions),
    }
    payload["findings"] = [{
        "finding_id": row["id"],
        "feature_ids": row.get("feature_ids", []),
        "literal_current_feature_ids": [feature for feature in row.get("feature_ids", []) if feature in manifest_ids],
        "reconciliation": row.get("feature_link_reconciliation", {}),
    } for row in rows]
    require(payload["counts"] == {
        "findings": 98,
        "total_links": 275,
        "findings_with_uncertainty": 30,
        "findings_without_literal_exact_current_id": 0,
        "route_intersection_groups": 45,
        "unique_page_intersection_groups": 7,
        "one_to_one_groups": 104,
    }, f"Reconciliation count drift: {payload['counts']}")
    require(payload["current_final_id_link_summary"]["literal_links"] == 176, "Literal-link drift")
    require(payload["current_final_id_link_summary"]["literal_targets"] == 144, "Literal-target drift")
    require(payload["current_final_id_link_summary"]["p0_p1_with_literal_exact_current_id"] == 86, "P0/P1 literal drift")


def validate_existing() -> None:
    findings = load(PATHS["findings"])
    require(sum(row["id"] == FINDING_ID for row in findings["findings"]) == 1, "Existing finding duplication")
    pointer = load(PATHS["pointer"])
    require(pointer["artifacts"]["pass8_health_safety_procedure_approval"] == pin(PATHS["pass8"]), "Pass8 pointer drift")
    require(pointer["artifacts"]["health_safety_procedure_approval_generation_summary"] == pin(PATHS["summary"]), "Summary pointer drift")
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
manifest = load(PATHS["manifest"])
benchmark = load(PATHS["benchmark"])
inventory = load(PATHS["inventory"])
findings = load(PATHS["findings"])
reconciliation = load(PATHS["reconciliation"])
official_map = load(PATHS["official_map"])
pointer = load(PATHS["pointer"])

manifest_ids = {row["working_key"] for row in manifest["targets"]}
require(set(FEATURE_IDS) <= manifest_ids, "Feature ID missing from canonical manifest")
benchmark_rows = {row["working_key"]: row for row in benchmark["targets"]}
require(all(not benchmark_rows[feature_id]["completion_credit"] for feature_id in FEATURE_IDS), "Finding target benchmark state drift")
hs_benchmark = [row for row in benchmark["targets"] if row["canonical_module"] == "HEALTH_SAFETY"]
require(len(hs_benchmark) == 68, "Health & Safety denominator drift")
hs_decided = sum(bool(row["completion_credit"]) for row in hs_benchmark)
require(hs_decided == 18 and len(hs_benchmark) - hs_decided == 50, "Health & Safety benchmark partition drift")
route_rows = {row["route_id"]: row for row in inventory["routes"]}
expected_route_owners = {
    "ROUTE-1178": [FEATURE_IDS[0]],
    "ROUTE-1180": [FEATURE_IDS[0]],
    "ROUTE-1191": [FEATURE_IDS[1]],
    "ROUTE-1182": [FEATURE_IDS[1]],
}
for route_id, owners in expected_route_owners.items():
    require(route_rows[route_id]["working_canonical_feature_ids"] == owners, f"Route ownership drift: {route_id}")
page = next(row for row in inventory["pages"] if row["page_id"] == PAGE_ID)
require(set(FEATURE_IDS) <= set(page["working_canonical_feature_ids"]), "Procedure page owner drift")
require(len(page["working_canonical_feature_ids"]) == 6, "Procedure page collision denominator drift")

pass8 = {
    "schema_version": "1.0.0",
    "artifact": "pass8-health-safety-procedure-approval-904-2026-08-21",
    "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT,
    "current_main_static_cross_check": CURRENT_MAIN,
    "status": "source_only_pass8_challenge_no_module_completion_credit",
    "module_selection": {
        "module": "HEALTH_SAFETY",
        "targets": 68,
        "classes": {"H": 41, "D": 27, "M": 0},
        "benchmark_decided": hs_decided,
        "benchmark_unproved": len(hs_benchmark) - hs_decided,
        "selection_reason": "Fresh adversarial challenge of a high-risk procedure approval boundary; broader module runtime and task coverage remain zero.",
    },
    "pass_reconciliation": {
        "P1": {"static_identity_reviewed": 68, "denominator": 68, "boundary": "Canonical static register only."},
        "P2": {"representative_persisted_tasks_executed": 0, "denominator": 68},
        "P3": {"benchmark_or_ncm_decided": hs_decided, "denominator": 68, "unproved": len(hs_benchmark) - hs_decided},
        "P4": {"happy_error_recovery_handoff_responsive_a11y_executed": 0, "denominator": 68},
        "P5": {"static_architecture_reviewed": 68, "runtime_data_effects_verified": 0, "denominator": 68},
        "P6": {"fresh_exact_source_finding_official_links": 2, "denominator": 68, "boundary": "Official propositions frame risk only."},
        "P7": {"fresh_source_constraint_failure_links": 2, "tests_executed": 0, "denominator": 68},
        "P8": {"fresh_module_challenge": 1, "module_completion_credit": 0, "denominator": 68},
    },
    "new_finding": {
        "id": FINDING_ID,
        "priority": "P1",
        "feature_ids": FEATURE_IDS,
        "route_ids": ROUTE_IDS,
        "page_ids": [PAGE_ID],
        "runtime_credit_delta": 0,
        "browser_credit_delta": 0,
        "completion_credit_delta": 0,
    },
    "source_chain": verified_sources,
    "duplicate_boundary": "No prior finding owns the exact procedure feature IDs, route IDs or controller transition. HS-ASSURANCE-01, MED-VERIFY-01 and CARE-SIGNOFF-01 remain distinct aggregates.",
    "wording_corrections": [
        "Generic AuditableChanges and procedure version snapshots exist; the gap is approval-specific immutable attestation bound to submitted content/version.",
        "The existing positive approval test creates under_review; draft and archived approval are source-proven but untested.",
    ],
    "completion_boundary": "No runtime, browser, focus, keyboard, representative-user, remediation, release or overall completion credit.",
}
save(PATHS["pass8"], pass8)

template = next(row for row in findings["findings"] if row["id"] == "CLIN-PROTOCOL-SCHEDULING-01")
findings["findings"].append(finding_payload(template))
findings["findings"].sort(key=lambda row: row["id"])
findings["counts"]["P1"] = 65
links = findings["counts"]["feature_link_reconciliation"]
links.update({
    "benchmark_mapping": {"eligible": 484, "verified_benchmark": 395, "documented_no_credible_match": 89, "completion_unproved": 420},
    "findings": 98,
    "total_links": 275,
    "literal_exact_current_links": 176,
    "literal_exact_current_targets": 144,
    "findings_with_literal_exact_current_id": 98,
    "p0_p1_with_literal_exact_current_id": 86,
    "p0_p1_without_literal_exact_current_id": 0,
    "findings_with_uncertainty": 30,
    "findings_without_literal_exact_current_id": 0,
    "route_intersection_groups": 45,
    "unique_page_intersection_groups": 7,
})
findings["audit_status"] = "Blocked—not comprehensive or complete. The canonical 904-target register is current (790H/111D/3M). Benchmark/NCM completion credit is 484/904, visual final-ID linkage is 8,168/8,753, material-state linkage is 3,948/4,312, and 98 source-backed findings are retained. All 86/86 P0/P1 findings contain a literal current-manifest ID; runtime remains unexecuted."
rebuild_reconciliation(reconciliation, findings, manifest)

require(official_map["denominator"] == official_map["reviewed"] == 55, "Official-map base drift")
official_map["findings"].append({"finding_id": FINDING_ID, "proposition_keys": ["WS-RISK", "OWNER-HS"]})
official_map["findings"].sort(key=lambda row: row["finding_id"])
official_map["denominator"] = official_map["reviewed"] = 56
official_map["coverage_percent"] = 100.0
official_map["owner_boundary_rows"] = sum(any(str(key).startswith("OWNER-") for key in row["proposition_keys"]) for row in official_map["findings"])
require(official_map["owner_boundary_rows"] == 30, "Official owner-boundary drift")

save(PATHS["findings"], findings)
save(PATHS["reconciliation"], reconciliation)
save(PATHS["official_map"], official_map)

outputs = {key: pin(PATHS[key]) for key in ("findings", "reconciliation", "official_map", "pass8")}
summary = {
    "schema_version": "1.0.0",
    "artifact": "final-904-health-safety-procedure-approval-generation-summary",
    "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT,
    "current_main_static_cross_check": CURRENT_MAIN,
    "finding_id": FINDING_ID,
    "status": "generated_open_p1_static_only_runtime_and_completion_blocked",
    "inputs": {key: {"path": rel(PATHS[key]), "sha256": value, "bytes": PATHS[key].stat().st_size} for key, value in PRE_PINS.items()},
    "outputs": outputs,
    "counts": {
        "findings": {"total": 98, "P0": 21, "P1": 65, "P2": 12},
        "links": {"total": 275, "literal": 176, "literal_targets": 144, "p0_p1_literal": 86},
        "official_map": {"denominator": 56, "reviewed": 56, "owner_boundary_rows": 30},
        "benchmark": {"eligible": 484, "unproved": 420},
    },
    "credit_boundary": {"runtime": 0, "browser": 0, "remediation": 0, "completion": 0},
    "idempotence": "A second run validates current outputs and pointer entries and performs no write.",
}
save(PATHS["summary"], summary)

pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({
    "findings": outputs["findings"],
    "finding_link_reconciliation": outputs["reconciliation"],
    "official_nz_finding_proposition_map": outputs["official_map"],
    "pass8_health_safety_procedure_approval": outputs["pass8"],
    "health_safety_procedure_approval_generation_summary": pin(PATHS["summary"]),
})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
save(PATHS["pointer"], pointer)

print(json.dumps({
    "status": "generated",
    "finding_id": FINDING_ID,
    "outputs": outputs,
    "summary": pin(PATHS["summary"]),
    "pointer": pin(PATHS["pointer"]),
}, indent=2))
