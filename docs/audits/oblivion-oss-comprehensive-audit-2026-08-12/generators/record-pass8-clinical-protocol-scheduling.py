#!/usr/bin/env python3
"""Record the clinical Pass-8 source challenge and its scheduling-wiring finding."""

from __future__ import annotations

import copy
import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
REPO = AUDIT.parent.parent.parent
SOURCE = AUDIT / "evidence" / "source"
GENERATED_AT = "2026-08-21T21:10:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
CURRENT_MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
FINDING_ID = "CLIN-PROTOCOL-SCHEDULING-01"
FEATURE_ID = "CAP-CLIN-PROTOCOL-DEFINITION-LIFECYCLE"
ROUTE_IDS = [f"ROUTE-{number:04d}" for number in range(1071, 1077)]
PAGE_IDS = ["PAGE-0344", "PAGE-0354", "PAGE-0356", "PAGE-0357", "PAGE-0358"]

PATHS = {
    "benchmark": SOURCE / "benchmark-final-904-mapping.json",
    "inventory": AUDIT / "inventory-904.json",
    "manifest": SOURCE / "working-capability-manifest-904.json",
    "findings": AUDIT / "findings.json",
    "reconciliation": SOURCE / "finding-link-reconciliation.json",
    "official_map": SOURCE / "official-nz-finding-proposition-map.json",
    "pointer": SOURCE / "canonical-audit-inputs.json",
    "pass8": SOURCE / "pass8-health-and-clinical-904-2026-08-21.json",
    "summary": SOURCE / "final-904-clinical-protocol-scheduling-generation-summary.json",
}

PRE_PINS = {
    "benchmark": "84f73bd34c2ff0e59551196a8a1886b4790de6eebc8f2be34b6e5978ea008491",
    "inventory": "579d2bde9e5f0d28ff1e912da354ec0244f6abe9eebaaf2eabf3c7ad3af2144e",
    "manifest": "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    "findings": "787ab9f0d52549d12be6f7c8e48f588b5fe028a71301adbacc04a662df134dd4",
    "reconciliation": "4183f9e1b31f3b0d787e31986f27c5bfc24992163eab9589c0d1852e5ac6ccb1",
    "official_map": "34e5453b4fb0a773b5ec762e862f104187090c440e5ed1ac5b596eedafecc3eb",
}

SOURCE_CHAIN = [
    {"path": "routes/health-clinical.php", "baseline_blob": "c40268f9bab43ff2db6811ca08421b6e42fcfe1d",
     "baseline_loci": "L69-L86", "current_blob": "c40268f9bab43ff2db6811ca08421b6e42fcfe1d", "current_loci": "L69-L86"},
    {"path": "app/Http/Controllers/Clinical/HealthClinicalProtocolController.php",
     "baseline_blob": "271195101f16ff31ae3d71e4d4faa3bd7226976d", "baseline_loci": "L22-L132",
     "current_blob": "eb8af63c9d12c3f2d710096b7710c8160addbcf0", "current_loci": "L22-L132"},
    {"path": "app/Domain/Clinical/Policies/ClinicalProtocolPolicy.php",
     "baseline_blob": "adb8f199a6eecfb0198d45344f867adc38ba2ae8", "baseline_loci": "L15-L42",
     "current_blob": "f6d57c0b9f480a198d652885903c284bc48cb7b8", "current_loci": "L15-L42"},
    {"path": "app/Domain/Clinical/Services/ClinicalProtocolService.php",
     "baseline_blob": "c7d754121247ee5e9bab748c6f225aa9bd9d73ee", "baseline_loci": "L25-L65,L68-L126",
     "current_blob": "c7d754121247ee5e9bab748c6f225aa9bd9d73ee", "current_loci": "L25-L65,L68-L126"},
    {"path": "app/Domain/Clinical/Enums/ProtocolFrequency.php",
     "baseline_blob": "76ede33b3a9e3bab594786ade2b2e84647baa51c", "baseline_loci": "L7-L13,L28-L41",
     "current_blob": "76ede33b3a9e3bab594786ade2b2e84647baa51c", "current_loci": "L7-L13,L28-L41"},
    {"path": "app/Http/Controllers/Clinical/ShiftClinicalController.php",
     "baseline_blob": "d16b5257f117a3f4b95ad3d4591771bd09732510", "baseline_loci": "L32-L49",
     "current_blob": "18cfe17a7b979001de3335d903ccc821e4975a4e", "current_loci": "L32-L49"},
    {"path": "app/Domain/Clinical/Services/ClinicalDashboardService.php",
     "baseline_blob": "98bd7681a1c83011bee2a42c92276dedea876bd8", "baseline_loci": "L60-L74,L240-L264,L922-L952",
     "current_blob": "631175337be83313f3c9dbd577bf0e993b42ed60", "current_loci": "L60-L74,L240-L264,L922-L952"},
    {"path": "routes/console.php", "baseline_blob": "13801c8af7e71149bf4c882157264dd3db855615",
     "baseline_loci": "full file", "current_blob": "26cb23a8880ba5c67413dd3adf2b829fe2dcc642", "current_loci": "full file"},
    {"path": "tests/Feature/Domain/Clinical/ClinicalProtocolServiceTest.php",
     "baseline_blob": "37e467ba0de54e88ba133cf5133b24b7a68a3766", "baseline_loci": "L33-L127",
     "current_blob": "37e467ba0de54e88ba133cf5133b24b7a68a3766", "current_loci": "L33-L127"},
    {"path": "tests/Feature/Domain/Clinical/ClinicalProtocolManagementControllerTest.php",
     "baseline_blob": "85113096949b2933f31999ee5a1b8314e6f4421c", "baseline_loci": "L133-L160",
     "current_blob": "b16958c48c18a1801427a33fbf44911d2af2c4da", "current_loci": "L133-L160"},
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
    return subprocess.check_output(["git", *args], cwd=REPO, text=True).strip()


def git_blob(blob: str) -> bytes:
    return subprocess.check_output(["git", "cat-file", "blob", blob], cwd=REPO)


def verify_source_chain() -> list[dict[str, Any]]:
    require(git("rev-parse", "HEAD") == AUDITED_COMMIT, "Audited worktree HEAD drift")
    require(git("rev-parse", "refs/remotes/origin/main") == CURRENT_MAIN, "Current-main cross-check drift")
    verified: list[dict[str, Any]] = []
    for row in SOURCE_CHAIN:
        baseline_ref = git("rev-parse", f"{AUDITED_COMMIT}:{row['path']}")
        current_ref = git("rev-parse", f"{CURRENT_MAIN}:{row['path']}")
        require(baseline_ref == row["baseline_blob"], f"Baseline blob drift: {row['path']}")
        require(current_ref == row["current_blob"], f"Current blob drift: {row['path']}")
        verified.append({**row, "baseline_sha256": sha_bytes(git_blob(row["baseline_blob"])),
                         "current_sha256": sha_bytes(git_blob(row["current_blob"]))})
    production_hits = git("grep", "-n", "generateSchedule", CURRENT_MAIN, "--", "app", "routes", "bootstrap", "config")
    expected_hit = f"{CURRENT_MAIN}:app/Domain/Clinical/Services/ClinicalProtocolService.php:25:    public function generateSchedule("
    require(production_hits == expected_hit, f"Production generateSchedule call-graph drift: {production_hits}")
    return verified


def finding_payload(findings: dict[str, Any]) -> dict[str, Any]:
    template = copy.deepcopy(next(row for row in findings["findings"] if row["id"] == "HR-WEBHOOK-OUTBOUND-SSRF-01"))
    anchors = [
        "routes/health-clinical.php:69-86",
        "app/Http/Controllers/Clinical/HealthClinicalProtocolController.php:22-132",
        "app/Domain/Clinical/Policies/ClinicalProtocolPolicy.php:15-42",
        "app/Domain/Clinical/Services/ClinicalProtocolService.php:25-65,68-126",
        "app/Domain/Clinical/Enums/ProtocolFrequency.php:7-13,28-41",
        "app/Http/Controllers/Clinical/ShiftClinicalController.php:32-49",
        "app/Domain/Clinical/Services/ClinicalDashboardService.php:60-74,240-264,922-952",
        "routes/console.php",
        "tests/Feature/Domain/Clinical/ClinicalProtocolServiceTest.php:33-127",
        "tests/Feature/Domain/Clinical/ClinicalProtocolManagementControllerTest.php:133-160",
    ]
    template.update({
        "id": FINDING_ID,
        "feature_ids": [FEATURE_ID],
        "passes": ["P1", "P2", "P5", "P6", "P7", "P8"],
        "module": "Health and clinical",
        "submodule": "Clinical protocol schedule generation",
        "actor_and_job": "A clinical lead creates or activates a time-based protocol so workers receive due observations and overdue monitoring.",
        "route_url": {"summary": "Six exact protocol lifecycle routes share the clinical protocol definition owner.",
                      "route_names": ["health-clinical.protocols.index", "health-clinical.protocols.store",
                                      "health-clinical.protocols.update", "health-clinical.protocols.edit",
                                      "health-clinical.protocols.toggle-active", "health-clinical.protocols.create"],
                      "route_paths": ["health-clinical/protocols", "health-clinical/protocols/{protocol}",
                                      "health-clinical/protocols/{protocol}/edit",
                                      "health-clinical/protocols/{protocol}/toggle-active",
                                      "health-clinical/protocols/create"]},
        "frontend_anchor": {"summary": "Create, edit and register pages own the protocol definition flow; no rendered defect is claimed.",
                            "page_files": ["resources/js/pages/health-clinical/protocols/Create.tsx",
                                           "resources/js/pages/health-clinical/protocols/Edit.tsx",
                                           "resources/js/pages/health-clinical/Protocols.tsx"],
                            "audited_commit": AUDITED_COMMIT},
        "visual_context": {"visual_id": "None assigned", "classification": "Source-inferred",
                           "role": "Clinical lead; representative runtime unavailable", "site_scope": "Site-scoped protocol owner",
                           "viewport": "Not safely reproduced", "state": "Source trace", "pattern_type": "backend/source finding",
                           "component_anchor": "See source anchors", "screenshot_reference": "None—no browser or care outcome is claimed",
                           "internal_baseline": "Native clinical protocol, schedule and due-observation aggregates"},
        "pattern_implementation": "Static route/controller/service/consumer call-graph review only; no scheduler, protocol, observation or browser flow was executed.",
        "backend_anchors": anchors,
        "current_behavior": "At both pinned source snapshots, six time-based protocol frequencies depend on ClinicalProtocolService::generateSchedule(), but production app/routes/bootstrap/config contains only the method declaration and no caller. Protocol controllers persist or toggle only ClinicalProtocol rows; shift and dashboard consumers only read existing schedule rows, and the empty dashboard denominator reports 100% compliance.",
        "current_workflow": {"summary": "Source-reviewed; six routes, five mapped pages and one canonical capability. Schedule generation and due/overdue projection were not executed.",
                             "failure_sequence": "A clinical lead creates or activates a daily, twice-daily, weekly, fortnightly, monthly or custom protocol; no production owner invokes schedule generation; due/overdue consumers receive no schedule row, while zero rows can report 100% compliance.",
                             "boundary": "Protocol definition, schedule materialisation, due/overdue projection and compliance denominator integrity.",
                             "completion_evidence": "Static current-main call-graph and consumer review only; no deployed protocol, missed observation or harm is claimed."},
        "evidence": {"anchors": anchors,
                     "existing_tests": ["ClinicalProtocolServiceTest calls generateSchedule directly", "ClinicalProtocolManagementControllerTest asserts the protocol row but not generated schedules or due/overdue visibility"],
                     "tests_executed": False,
                     "browser_claim_limit": "No credential, database row, scheduler, queue, observation, dashboard or resident-care workflow was exercised."},
        "problem_root_cause": "The schedule materialiser is an orphan service method rather than an owned production transition from time-based protocol creation, activation or reconciliation.",
        "impact": "Time-based protocols may never enter worker due/overdue surfaces, and an empty schedule set can overstate compliance. Actual deployed use, missed care and harm remain unverified.",
        "benchmark": {"selected": "Documented target-specific NCM already retained", "url_and_sha": "",
                      "verified_behavior": "Benchmark-only comparator adjudication; no Oblivion schedule behavior is inferred.",
                      "outcome": "Documented NCM direct retained; no benchmark count delta",
                      "no_match_evidence": "The existing target-specific NCM does not prove local generation, runtime or completion."},
        "neutral_requirements": "Own time-based schedule materialisation, bounded horizon, activation/deactivation reconciliation, replay identity and honest zero-denominator reporting in one native clinical lifecycle.",
        "better_oblivion_design": "Route protocol creation, activation and bounded reconciliation through one clinical schedule owner with idempotent schedule identity and explicit due/overdue projection.",
        "cross_module_effects": "Preserve protocol, observation, shift, dashboard, audit and notification ownership without creating a second schedule source of truth.",
        "rbac_privacy": "Keep existing protocol Site/capability policy; test same-Site positives and concealed foreign-Site direct objects separately from schedule correctness.",
        "priority": "P1", "effort": "M",
        "dependencies_sequence": "Clinical owner defines horizon and activation semantics; implement one schedule owner; add deterministic lifecycle/recovery/concurrency tests; then perform representative browser validation.",
        "proposed_owner": "Clinical Engineering and Clinical Governance",
        "confidence": "High for the static wiring absence; deployed data, scheduler execution, missed observations and harm remain unverified",
        "source_boundary": "Audited-baseline and current-main source are separately pinned. Official sources frame consumer care and clinical-owner review only; they do not mandate a scheduler implementation or prove non-compliance.",
        "interim_safeguard": "Clinical leads manually reconcile active time-based protocols against pending schedule rows and due/overdue worklists until production generation is governed.",
        "acceptance_criteria": [
            "Creating or activating each time-based frequency materialises a bounded, deterministic pending schedule visible to due/overdue consumers.",
            "Repeated or concurrent generation converges without duplicate schedule effects.",
            "Deactivation and definition changes reconcile future rows without rewriting historical provenance.",
            "An empty or failed materialisation cannot report successful compliance.",
            "Same-Site authority, concealed foreign-Site denial and recovery are verified independently.",
        ],
        "missing_tests": ["Controller-to-schedule integration for six time-based frequencies", "Activation/deactivation and horizon reconciliation",
                          "Replay and concurrent generation", "Failed/empty generation compliance reporting", "Representative due/overdue browser visibility"],
        "validation_plan": ["First confirm the source-observed absence in a disposable MySQL diagnostic scenario",
                            "After remediation, run lifecycle/replay/concurrency feature tests",
                            "Verify same-Site and foreign-Site authorization separately",
                            "Run representative due/overdue browser checks at required viewports",
                            "Retain open status until merged-to-main and runtime evidence are canonical"],
        "official_sources": [{"id": "NZ-HDC-CODE", "title": "Code of Health and Disability Services Consumers' Rights",
                              "authority": "Health and Disability Commissioner New Zealand",
                              "url": "https://www.hdc.org.nz/your-rights/about-the-code/code-of-health-and-disability-services-consumers-rights/",
                              "supporting_url": "", "inspected_date": "2026-08-12"}],
        "statement_types": {"source": "The protocol write paths, orphan generation method, read-only consumers and empty-denominator behavior are source-observed at the pinned commits.",
                            "official_source": "HDC-R4 and OWNER-CLINICAL frame the care and clinical-owner review boundary; they do not mandate a scheduler or prove legal non-compliance.",
                            "inference": "Missing due observations and overstated compliance are bounded source inferences; no deployed record or harm was observed.",
                            "specialist_decision": "P1 priority, horizon, activation and reconciliation semantics require Clinical Governance."},
        "official_source_proposition_keys": ["HDC-R4", "OWNER-CLINICAL"],
        "feature_link_reconciliation": {"method": "route-first: exact six protocol routes and source-owner call graph; mapped pages corroborate but do not add family credit",
                                        "projection_status": "literal_current_904_manifest_link_present; runtime_and_remediation_unverified",
                                        "legacy_feature_ids": ["CLIN-HEALTH-CLINICAL-PROTOCOL"],
                                        "decisions": [{"legacy_family_id": "independent-pass8-clinical-protocol-scheduling-2026-08-21",
                                                       "method": "source-proven exact current target route/backend intersection",
                                                       "feature_ids": [FEATURE_ID], "route_hits": ROUTE_IDS,
                                                       "page_hits": [{"page_id": page_id, "feature_id": FEATURE_ID} for page_id in PAGE_IDS],
                                                       "source_anchors": anchors,
                                                       "evidence": "Fresh clinical Pass-8 review traced protocol writes, the orphan schedule generator, read-only due/overdue consumers and the empty compliance denominator without runtime credit.",
                                                       "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN}],
                                        "uncertainties": [{"reason_code": "runtime_protocol_and_representative_role_unexecuted",
                                                           "detail": "Static evidence supports the finding; deployed protocol use, schedule execution, missed observations, dashboard behavior and harm remain unverified.",
                                                           "smallest_next_evidence": "Post an active twice-daily protocol in a disposable current-main MySQL lane and assert a bounded pending schedule reaches due/overdue projection."}]},
        "remediation": {"status": "open", "note": "No isolated remediation branch or runtime verification is recorded."},
    })
    template["ease_evidence"] = {
        "validation_status": "Blocked—source finding retained; no representative runtime or ten-dimension validation executed",
        "evidence_basis": "Static source trace only",
        "current_scores": {key: 0 for key in ["discoverability", "comprehension", "learnability", "efficiency", "error_prevention", "recovery", "accessibility", "safety_and_trust", "consistency", "cross_module_continuity"]},
        "friction": {"completion_time": "Not measured", "step_count": "Not measured", "required_field_count": "Not measured",
                     "decision_count": "Clinical-owner decision required", "context_switches": "Not measured", "dead_ends": "Runtime unknown",
                     "recovery_path": "Expose failed or missing schedule materialisation, preserve protocol provenance, and provide an authorised idempotent reconciliation path."},
        "target_scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
        "independent_review": "Independent source review confirmed a new P1 and rejected runtime, harm, legal and P0 claims.",
    }
    template["target_ease"] = {"scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
                               "measurable_outcome": "An authorised clinical lead creates a time-based protocol and a worker receives the correct due row without duplicate or hidden failure."}
    return template


def rebuild_reconciliation(payload: dict[str, Any], findings: dict[str, Any], manifest: dict[str, Any]) -> None:
    manifest_ids = {row["working_key"] for row in manifest["targets"]}
    rows = findings["findings"]
    exact = [(row["id"], feature) for row in rows for feature in row.get("feature_ids", []) if feature in manifest_ids]
    exact_findings = {finding_id for finding_id, _ in exact}
    p0p1 = [row for row in rows if row["priority"] in {"P0", "P1"}]
    p0p1_exact = {row["id"] for row in p0p1} & exact_findings
    decisions = [decision for row in rows for decision in row.get("feature_link_reconciliation", {}).get("decisions", [])]
    prior = payload["current_final_id_link_summary"]
    payload["generated_at"] = GENERATED_AT
    payload["status"] = "current_904_literal_link_reconciliation_partial_runtime_unverified"
    payload["scope_boundary"] = "Links preserve audited source and literal current 904 IDs; they do not establish runtime outcome, remediation or completion."
    payload["current_final_id_link_summary"] = {
        "literal_links": len(exact), "literal_targets": len({feature for _, feature in exact}),
        "explicitly_re_adjudicated_links": prior["explicitly_re_adjudicated_links"] + 1,
        "explicitly_re_adjudicated_findings": sorted(set(prior["explicitly_re_adjudicated_findings"]) | {FINDING_ID}),
        "findings_with_literal_exact_current_id": len(exact_findings),
        "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "p0_p1_with_literal_exact_current_id": len(p0p1_exact),
        "p0_p1_without_literal_exact_current_id": len(p0p1) - len(p0p1_exact), "complete": False,
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
    require(payload["counts"] == {"findings": 95, "total_links": 268, "findings_with_uncertainty": 27,
                                   "findings_without_literal_exact_current_id": 0, "route_intersection_groups": 42,
                                   "unique_page_intersection_groups": 4, "one_to_one_groups": 104}, "Finding reconciliation count drift")
    require(payload["current_final_id_link_summary"]["literal_links"] == 169, "Literal link drift")
    require(payload["current_final_id_link_summary"]["literal_targets"] == 137, "Literal target drift")
    require(payload["current_final_id_link_summary"]["p0_p1_with_literal_exact_current_id"] == 83, "P0/P1 literal drift")


def validate_existing() -> None:
    findings = load(PATHS["findings"])
    require(sum(row["id"] == FINDING_ID for row in findings["findings"]) == 1, "Existing finding duplication")
    pointer = load(PATHS["pointer"])
    require(pointer["artifacts"]["pass8_health_and_clinical"] == pin(PATHS["pass8"]), "Pass8 pointer drift")
    require(pointer["artifacts"]["clinical_protocol_scheduling_generation_summary"] == pin(PATHS["summary"]), "Summary pointer drift")
    summary = load(PATHS["summary"])
    for key in ("findings", "reconciliation", "official_map", "pass8"):
        require(summary["outputs"][key] == pin(PATHS[key]), f"Existing output drift: {key}")
    print(json.dumps({"status": "idempotent_no_change", "finding_id": FINDING_ID}, indent=2))


if any(row["id"] == FINDING_ID for row in load(PATHS["findings"])["findings"]):
    validate_existing()
    raise SystemExit(0)

for name, expected in PRE_PINS.items():
    require(sha_file(PATHS[name]) == expected, f"Input SHA drift: {name}")
verified_source = verify_source_chain()

benchmark = load(PATHS["benchmark"])
inventory = load(PATHS["inventory"])
manifest = load(PATHS["manifest"])
findings = load(PATHS["findings"])
reconciliation = load(PATHS["reconciliation"])
official_map = load(PATHS["official_map"])
pointer = load(PATHS["pointer"])

require(benchmark["summary"]["eligible_total"] == 464 and benchmark["summary"]["completion_unproved"]["total"] == 440, "Benchmark count drift")
by_benchmark = {row["working_key"]: row for row in benchmark["targets"]}
require(by_benchmark[FEATURE_ID]["status"] == "documented_ncm_direct" and by_benchmark[FEATURE_ID]["completion_credit"], "Clinical benchmark state drift")
clinical_features = [row for row in inventory["features"] if row["module_key"] == "CLINICAL"]
require(len(clinical_features) == 20, "Clinical denominator drift")
require({kind: sum(row["class"] == kind for row in clinical_features) for kind in ("H", "D", "M")} == {"H": 19, "D": 1, "M": 0}, "Clinical class drift")
require(sum(row["module_key"] == "CLINICAL" for row in inventory["tests"]) == 36, "Clinical test inventory drift")
route_rows = {row["route_id"]: row for row in inventory["routes"]}
require(all(route_rows[route_id]["working_canonical_feature_ids"] == [FEATURE_ID] for route_id in ROUTE_IDS), "Clinical route ownership drift")

pass8 = {
    "schema_version": "1.0.0", "artifact": "pass8-health-and-clinical-904-2026-08-21",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN,
    "status": "source_only_pass8_challenge_no_module_completion_credit",
    "selection": {"method": "Risk-ranked exact finding module score after completed eMAR and HR waves: 100×P0 + 10×P1 + P2.",
                  "selected_module": "CLINICAL", "selected_score": 200,
                  "next_scores": {"HEALTH_SAFETY": 131, "OPERATIONS": 130}},
    "module_counts": {"targets": 20, "H": 19, "D": 1, "M": 0, "benchmark_decided": 17, "benchmark_unproved": 3,
                      "linked_p0_p1_before_wave": 2, "linked_p0_p1_after_wave": 3,
                      "runtime_unexecuted": 20, "routes": 55, "pages": 22, "models": 6, "policies": 3,
                      "services": 8, "events": 2, "jobs": 0, "test_inventory_rows": 36},
    "eight_pass": {
        "P1": {"reviewed": 20, "denominator": 20, "boundary": "Static identity, routes, owners and source call graph."},
        "P2": {"executed": 0, "denominator": 20, "boundary": "Representative persisted tasks unexecuted."},
        "P3": {"decided": 17, "denominator": 20, "unproved": 3, "boundary": "Benchmark evidence only; no local behavior credit."},
        "P4": {"executed": 0, "denominator": 20, "boundary": "Happy/error/recovery/handoff/responsive/accessibility execution absent."},
        "P5": {"static_reviewed": 20, "denominator": 20, "runtime_data_effects_verified": 0},
        "P6": {"exact_source_finding_official_links": 1, "denominator": 20, "boundary": "HDC-R4 and OWNER-CLINICAL are guidance only."},
        "P7": {"source_constraint_failure_links": 1, "denominator": 20, "tests_executed": 0},
        "P8": {"static_identity_challenged": 20, "denominator": 20, "module_completion_credit": False},
    },
    "new_finding": {"id": FINDING_ID, "priority": "P1", "feature_ids": [FEATURE_ID], "route_ids": ROUTE_IDS,
                    "page_ids": PAGE_IDS, "verdict": "independently_reviewed_new_nonduplicate_p1_static_only"},
    "source_chain": verified_source,
    "call_graph": {"search_scope": ["app", "routes", "bootstrap", "config"],
                   "generate_schedule_hits": ["app/Domain/Clinical/Services/ClinicalProtocolService.php:25 declaration"],
                   "production_callers": 0},
    "duplicate_boundary": {"existing_finding_count": 94, "related_not_duplicate": ["CLIN-SCHEDULE-01"],
                           "distinction": "Existing finding governs wrong resident/type completion after a schedule exists; this finding governs absence of schedule creation."},
    "credit_boundary": {"runtime_credit_delta": 0, "browser_credit_delta": 0, "benchmark_credit_delta": 0,
                        "module_completion_delta": 0, "finding_delta": 1},
}
save(PATHS["pass8"], pass8)

findings["findings"].append(finding_payload(findings))
findings["findings"].sort(key=lambda row: row["id"])
findings["counts"]["P1"] = 64
links = findings["counts"]["feature_link_reconciliation"]
links.update({"benchmark_mapping": {"eligible": 464, "verified_benchmark": 375, "documented_no_credible_match": 89, "completion_unproved": 440},
              "findings": 95, "total_links": 268, "literal_exact_current_links": 169,
              "literal_exact_current_targets": 137, "findings_with_literal_exact_current_id": 95,
              "p0_p1_with_literal_exact_current_id": 83, "p0_p1_without_literal_exact_current_id": 0,
              "findings_with_uncertainty": 27, "findings_without_literal_exact_current_id": 0,
              "route_intersection_groups": 42, "unique_page_intersection_groups": 4})
findings["audit_status"] = "Blocked—not comprehensive or complete. The canonical 904-target register is current (790H/111D/3M). Benchmark/NCM completion credit is 464/904, visual final-ID linkage is 8,168/8,753, material-state linkage is 3,948/4,312, and 95 source-backed findings are retained. All 83/83 P0/P1 findings contain a literal current-manifest ID; runtime remains unexecuted."
findings["statement"] = "Full schema for every retained finding. Static evidence, inference, official propositions and owner decisions remain separated; runtime and representative-role completion are not claimed."
rebuild_reconciliation(reconciliation, findings, manifest)

require(official_map["denominator"] == official_map["reviewed"] == 52, "Official-map base drift")
official_map["findings"].append({"finding_id": FINDING_ID, "proposition_keys": ["HDC-R4", "OWNER-CLINICAL"]})
official_map["findings"].sort(key=lambda row: row["finding_id"])
official_map["denominator"] = official_map["reviewed"] = 53
official_map["coverage_percent"] = 100.0
official_map["owner_boundary_rows"] = sum(any(str(key).startswith("OWNER-") for key in row["proposition_keys"]) for row in official_map["findings"])
require(official_map["owner_boundary_rows"] == 28, "Official owner boundary drift")

save(PATHS["findings"], findings)
save(PATHS["reconciliation"], reconciliation)
save(PATHS["official_map"], official_map)

outputs = {key: pin(PATHS[key]) for key in ("findings", "reconciliation", "official_map", "pass8")}
summary = {
    "schema_version": "1.0.0", "artifact": "final-904-clinical-protocol-scheduling-generation-summary",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN,
    "finding_id": FINDING_ID, "status": "generated_open_p1_static_only_runtime_and_completion_blocked",
    "inputs": {key: {"path": rel(PATHS[key]), "sha256": value, "bytes": PATHS[key].stat().st_size} for key, value in PRE_PINS.items()},
    "source_chain": verified_source, "outputs": outputs,
    "counts": {"denominator": {"total": 904, "H": 790, "D": 111, "M": 3},
               "benchmark": {"eligible": 464, "verified": 375, "ncm": 89, "unproved": 440},
               "findings": {"total": 95, "P0": 19, "P1": 64, "P2": 12},
               "finding_links": {"total": 268, "literal": 169, "literal_targets": 137, "p0_p1_literal": 83},
               "official_map": {"denominator": 53, "reviewed": 53, "owner_boundary_rows": 28}},
    "credit_boundary": {"static_finding_added": 1, "runtime_credit_delta": 0, "browser_credit_delta": 0,
                        "benchmark_credit_delta": 0, "remediation_credit_delta": 0, "completion_credit_delta": 0},
    "idempotence": "A second run validates hashes and pointer entries and performs no write.",
}
save(PATHS["summary"], summary)

pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"findings": outputs["findings"], "finding_link_reconciliation": outputs["reconciliation"],
                             "official_nz_finding_proposition_map": outputs["official_map"],
                             "pass8_health_and_clinical": outputs["pass8"],
                             "clinical_protocol_scheduling_generation_summary": pin(PATHS["summary"])})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
pointer["runtime_credit_delta"] = 0
save(PATHS["pointer"], pointer)

validate_existing()
print(json.dumps({"status": "generated", "finding_id": FINDING_ID, "outputs": outputs, "pointer": pin(PATHS["pointer"])}, indent=2))
