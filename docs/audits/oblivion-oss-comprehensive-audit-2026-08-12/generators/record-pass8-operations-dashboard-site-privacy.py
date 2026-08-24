#!/usr/bin/env python3
"""Record the Operations Pass-8 source challenge and dashboard/activity Site-privacy finding."""

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
GENERATED_AT = "2026-08-21T23:00:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
CURRENT_MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
FINDING_ID = "OPS-DASHBOARD-ACTIVITY-SITE-PRIVACY-01"
FEATURE_IDS = ["OPS-DASHBOARD", "OPS-ACTIVITY-FEED"]
ROUTE_IDS = ["ROUTE-1897", "ROUTE-1898"]
PAGE_IDS = ["PAGE-0651", "PAGE-0564"]

PATHS = {
    "benchmark": SOURCE / "benchmark-final-904-mapping.json",
    "inventory": AUDIT / "inventory-904.json",
    "manifest": SOURCE / "working-capability-manifest-904.json",
    "findings": AUDIT / "findings.json",
    "reconciliation": SOURCE / "finding-link-reconciliation.json",
    "official_map": SOURCE / "official-nz-finding-proposition-map.json",
    "pointer": SOURCE / "canonical-audit-inputs.json",
    "pass8": SOURCE / "pass8-operations-904-2026-08-21.json",
    "summary": SOURCE / "final-904-operations-dashboard-site-privacy-generation-summary.json",
}

PRE_PINS = {
    "benchmark": "3bda9a751759f2f24e0ff56f8e5fbddbedc548528ca4bcc5511b53d187eba710",
    "inventory": "bca2b2549bef0e737df5bde5e2db6a158dd53f978aa6b84800e3a7f561ed8ec2",
    "manifest": "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    "findings": "9b558790b30636fc4f7d2f7f6b0ccb7b79e4a595bf2f8ff5abfdc3dbdd8faf1c",
    "reconciliation": "4d9e5581fee9015c85c1c6d56395e14310373cf11a84ba006c0c2ea86ff8131c",
    "official_map": "a35aa48109023cb73bff0f37d945406740ded64eb92f22f1f340b0aae9172a46",
}

SOURCE_CHAIN = [
    ("routes/operations.php", "ba11f6dcad8fec04385b724a5c59278693280596", "L84-L96", "357afa5d5258d11e43696ae3061955c7d24f6604", "L93-L96"),
    ("app/Http/Controllers/Operations/DashboardController.php", "23fd64512c510c621652b0815d72b24844803fb3", "L17-L120,L151-L205,L238-L304,L340-L451,L481-L588,L616-L690", "23fd64512c510c621652b0815d72b24844803fb3", "same loci"),
    ("app/Http/Controllers/Operations/ActivityFeedController.php", "f363adac893b186d512e6a3cdab69ec8b28e66c1", "L15-L98", "f363adac893b186d512e6a3cdab69ec8b28e66c1", "same loci"),
    ("app/Http/Middleware/RoleScope.php", "52a85a1ec8324cb25570357ceaba85fdb0abab92", "L25-L55", "52a85a1ec8324cb25570357ceaba85fdb0abab92", "same loci"),
    ("app/Models/User.php", "59b118f636dcdcdc196886d0ac78fd41eafc0663", "L356-L383", "eb12246ce9dd33ebf46cc38cf4b09c43dde82068", "L342-L369"),
    ("app/Services/UserSiteAccessService.php", "fed7affe854534221417be93dc2b2d8445488c8d", "L22-L46,L196-L284", "41d3ed5c671c1774b4865b62e88e053e8aea55a8", "L53-L116,L653-L842,L1033-L1084"),
    ("database/seeders/RbacSeeder.php", "4859ab64c19b8a35ba0656ef2f9e40b18943cc98", "Coordinator and Operations permission grants", "317c399b138d42edcb2d001323f2edb085ab4e41", "L670-L699 and Operations permission definitions"),
    ("tests/Feature/Operations/ShiftSiteIsolationTest.php", "8fe74ceab2ebbc0cc0f6d714a887be621ab58e51", "L90-L120,L176-L208,L568-L633", "2665373284a3658db56748882e44d232de2ede63", "L95-L130,L181-L220,L594-L650"),
    ("resources/js/pages/operations/Index.tsx", "659f1e14479c28805e169d24e33aa2628b008378", "dashboard props", "30cd8ae87dbd4d98e39c7ce7b88915c996844668", "dashboard props"),
    ("resources/js/pages/operations/activity/Index.tsx", "66e1f82aa2c912ceec65da737f7b1cffb2add53f", "activity props", "c39ac64230b7a17e980249d187bd52fed1c08fe8", "activity props"),
]


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


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


def verify_source_chain() -> list[dict[str, Any]]:
    require(git("rev-parse", "HEAD") == AUDITED_COMMIT, "Audited HEAD drift")
    require(git("rev-parse", "refs/remotes/origin/main") == CURRENT_MAIN, "Current-main ref drift")
    verified = []
    for path, baseline_blob, baseline_loci, current_blob, current_loci in SOURCE_CHAIN:
        require(git("rev-parse", f"{AUDITED_COMMIT}:{path}") == baseline_blob, f"Baseline blob drift: {path}")
        require(git("rev-parse", f"{CURRENT_MAIN}:{path}") == current_blob, f"Current-main blob drift: {path}")
        verified.append({"path": path, "baseline_blob": baseline_blob, "baseline_loci": baseline_loci,
                         "current_main_blob": current_blob, "current_main_loci": current_loci})
    return verified


def finding_payload(findings: dict[str, Any]) -> dict[str, Any]:
    row = copy.deepcopy(next(item for item in findings["findings"] if item["id"] == "FLEET-BOOKING-SITE-PRIVACY-01"))
    anchors = [
        "routes/operations.php:84-96 (audited),93-96 (current main)",
        "app/Http/Middleware/RoleScope.php:25-55",
        "app/Models/User.php:356-383 (audited),342-369 (current main)",
        "database/seeders/RbacSeeder.php:670-699 (current main)",
        "app/Http/Controllers/Operations/DashboardController.php:17-120,151-205,238-304,340-451,481-588,616-690",
        "app/Http/Controllers/Operations/ActivityFeedController.php:15-98",
        "app/Services/UserSiteAccessService.php:53-116,653-842,1033-1084 (current main)",
        "tests/Feature/Operations/ShiftSiteIsolationTest.php:95-130,181-220,594-650 (current main)",
        "resources/js/pages/operations/Index.tsx",
        "resources/js/pages/operations/activity/Index.tsx",
    ]
    row.update({
        "id": FINDING_ID,
        "remediation": {"status": "open", "note": "No isolated remediation branch, targeted test execution or browser verification is recorded."},
        "feature_ids": FEATURE_IDS,
        "passes": ["P1", "P2", "P5", "P6", "P7", "P8"],
        "module": "Operations",
        "submodule": "Operations dashboard and activity-feed Site privacy",
        "actor_and_job": "A Site-bound Coordinator reviews operational workload and recent activity without seeing another Site's residents, staff, incidents, shifts, timesheets, Sites or derived metrics.",
        "route_url": {"summary": "Two authenticated Operations GET routes render global dashboard/activity projections without canonical Site scope.",
                      "route_names": ["operations.dashboard", "operations.activity.index"],
                      "route_paths": ["operations", "operations/activity"]},
        "frontend_anchor": {"summary": "Dashboard and activity pages render the controller-supplied aggregates, names, facts and links.",
                            "page_files": ["resources/js/pages/operations/Index.tsx", "resources/js/pages/operations/activity/Index.tsx"],
                            "audited_commit": AUDITED_COMMIT},
        "visual_context": {"visual_id": "None assigned", "classification": "Source-inferred", "role": "Site-bound Coordinator",
                           "site_scope": "Current active assigned Sites only", "viewport": "Not safely reproduced", "state": "Source trace",
                           "pattern_type": "backend/source finding", "component_anchor": "Operations dashboard and activity feed",
                           "screenshot_reference": "None—no authenticated operational data was accessed",
                           "internal_baseline": "UserSiteAccessService fail-closed Client, Shift, Timesheet, Incident, Site and staff scopes"},
        "pattern_implementation": "Static route, permission, controller, Site-scope service, seeder, UI-prop and test review only; no dashboard record, database or browser flow was executed.",
        "backend_anchors": anchors,
        "current_behavior": "At both pinned source snapshots, a Site-bound Coordinator can reach the Operations dashboard through broad management capabilities and the activity feed through clients.viewAny or shifts.viewAny. Both controllers issue global queries and project cross-Site aggregates or resident/staff/incident/shift/timesheet/Site details without UserSiteAccessService.",
        "current_workflow": {"summary": "Source-reviewed two-route read workflow; no representative Coordinator session or populated two-Site response was executed.",
                             "failure_sequence": "A Site-A Coordinator opens either page; global queries can include Site-B rows and influence totals before any accessible-Site filter. Activity Feed also emits direct Shift, Timesheet and Client links; Dashboard emits Site links and operational CTAs.",
                             "boundary": "Operational action permission is separate from explicit global-Site authority and from resident/staff privacy.",
                             "completion_evidence": "Static audited/current-main source composition only; no foreign record was populated or accessed."},
        "evidence": {"anchors": anchors,
                     "existing_tests": ["ShiftSiteIsolationTest proves broad management capabilities remain Site-bound on adjacent canonical Operations workflows; it does not directly exercise these two routes."],
                     "tests_executed": False,
                     "browser_claim_limit": "No credential, dashboard payload, activity filter, direct link or cross-Site disclosure was exercised."},
        "problem_root_cause": "The two read surfaces use broad action/role heuristics but never delegate every query and derived metric to one fail-closed accessible-Site scope.",
        "impact": "Source permits an ordinary Site-bound management role to receive cross-Site resident, staff, incident and operational information. Runtime population, access, exploitation and external disclosure remain unverified.",
        "benchmark": {"selected": "Both exact targets retain existing bounded documented_ncm_direct benchmark outcomes", "url_and_sha": "",
                      "verified_behavior": "Benchmark completion is independent of this product authorization finding.",
                      "outcome": "No benchmark count delta", "no_match_evidence": "Existing bounded NCM decisions remain unchanged."},
        "neutral_requirements": "Require the dashboard action capability and apply one accessible-Site set to every aggregate, trend, picker, nested row, recent item, link and derived metric; broad action permissions never imply global Site visibility.",
        "better_oblivion_design": "Enforce operations.dashboard.view on both routes, add an explicit operations.dashboard.viewAllSites atom, then compose UserSiteAccessService scopes consistently before projection.",
        "target_ease": {"scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
                        "measurable_outcome": "A Site-A Coordinator sees only Site-A dashboard/activity data and no Site-B value influences any prop or link."},
        "cross_module_effects": "Preserve Operations, Clients, Shifts, Timesheets, Incidents, Sites and staff ownership while preventing parallel scope logic.",
        "rbac_privacy": "operations.dashboard.view is an action capability, not global Site authority; a global positive requires that capability plus operations.dashboard.viewAllSites.",
        "priority": "P0", "effort": "M",
        "dependencies_sequence": "Operations/Privacy owner confirms intended roles and global atom; implement one scope owner; add two-Site payload tests; then representative browser validation.",
        "proposed_owner": "Operations Engineering and Privacy/Security",
        "confidence": "High for source-level reachability and projection; runtime data population, access and exploitation remain unverified",
        "source_boundary": "Audited baseline and current main are separately pinned. Official propositions frame risk only; application source proves the callable boundary and no legal non-compliance is claimed.",
        "interim_safeguard": "Restrict both Operations routes to trusted application-wide managers until canonical Site scope is enforced.",
        "acceptance_criteria": [
            "Both routes enforce operations.dashboard.view.",
            "Every Client, Shift, Timesheet, ClientIncident, Site, staff, count, group, trend, picker, timeline, nested row and link is scoped before projection.",
            "No-current-Site actors receive an empty non-disclosing result.",
            "Site-A tests prove Site-B names, IDs, counts, links, labels, incident facts and aggregate influence are absent across every prop and Activity filter.",
            "Broad management action permissions without operations.dashboard.viewAllSites remain Site-bound.",
            "A global positive requires both operations.dashboard.view and operations.dashboard.viewAllSites.",
        ],
        "missing_tests": ["Site-A Coordinator dashboard versus Site-B sentinel rows and aggregate influence", "Activity Feed filters and direct links",
                          "No-Site actor", "Missing action permission", "Broad action without global-Site atom", "Explicit action plus global-Site positive"],
        "validation_plan": ["Run one disposable two-Site current-main feature lane", "Assert same-Site positives and foreign sentinel absence across every prop",
                            "After remediation verify each filter and derived metric", "Perform desktop/mobile representative browser checks",
                            "Retain open status until merged-to-main and runtime evidence are canonical"],
        "official_sources": [{"id": "NZ-HIPC", "title": "Health Information Privacy Code 2020", "authority": "Office of the Privacy Commissioner New Zealand",
                              "url": "https://www.privacy.org.nz/privacy-act-2020/codes-of-practice/hipc2020/", "supporting_url": "", "inspected_date": "2026-08-12"}],
        "statement_types": {"source": "The route permissions, ordinary Coordinator grants, global queries, sensitive projections and missing Site-scope calls are source-observed.",
                            "official_source": "HISF-ACCESS, HIPC-R5 and MMH-BOLA frame risk only; they do not prove runtime disclosure, legal breach or conformance.",
                            "inference": "Cross-Site exposure is a bounded source inference; no foreign record was accessed.",
                            "specialist_decision": "P0 priority and the explicit global Operations authority atom require Privacy/Security and Operations ownership."},
        "official_source_proposition_keys": ["HISF-ACCESS", "HIPC-R5", "MMH-BOLA"],
        "feature_link_reconciliation": {"method": "route-first: exact two GET routes, two final capability owners and two routed-render pages",
            "projection_status": "literal_current_904_manifest_links_present; runtime_and_remediation_unverified",
            "legacy_feature_ids": ["OPS-DASHBOARD", "OPS-ACTIVITY-FEED"],
            "decisions": [{"legacy_family_id": "independent-pass8-operations-dashboard-site-privacy-2026-08-21",
                           "method": "source-proven exact current route/page/backend ownership",
                           "feature_ids": FEATURE_IDS, "route_hits": ROUTE_IDS,
                           "page_hits": [{"page_id": PAGE_IDS[0], "feature_id": FEATURE_IDS[0]}, {"page_id": PAGE_IDS[1], "feature_id": FEATURE_IDS[1]}],
                           "source_anchors": anchors,
                           "evidence": "Fresh Operations Pass-8 traced ordinary-role reachability, global projections and missing canonical Site scope without runtime credit.",
                           "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN}],
            "uncertainties": [{"reason_code": "runtime_foreign_data_and_representative_role_unexecuted",
                               "detail": "Static evidence supports the authorization defect; populated foreign data and actual access remain unverified.",
                               "smallest_next_evidence": "Use a disposable two-Site fixture and assert a Site-A Coordinator cannot receive any Site-B value or aggregate influence."}]},
    })
    row["ease_evidence"] = {"validation_status": "Blocked—source finding retained; no representative runtime or ten-dimension validation executed",
        "evidence_basis": "Static source trace only", "current_scores": {key: 0 for key in ["discoverability", "comprehension", "learnability", "efficiency", "error_prevention", "recovery", "accessibility", "safety_and_trust", "consistency", "cross_module_continuity"]},
        "friction": {"completion_time": "Not measured", "step_count": "Not measured", "required_field_count": "Not measured",
                     "decision_count": "Privacy/Operations owner decision required", "context_switches": "Not measured", "dead_ends": "Runtime unknown",
                     "recovery_path": "Conceal foreign data and preserve a safe same-Site read path."},
        "target_scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5},
        "independent_review": "Independent source review confirmed a new P0 and rejected runtime, exploitation and legal claims."}
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
        "literal_links": len(exact), "literal_targets": len({feature for _, feature in exact}),
        "explicitly_re_adjudicated_links": prior["explicitly_re_adjudicated_links"] + 2,
        "explicitly_re_adjudicated_findings": sorted(set(prior["explicitly_re_adjudicated_findings"]) | {FINDING_ID}),
        "findings_with_literal_exact_current_id": len(exact_findings), "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "p0_p1_with_literal_exact_current_id": len({row["id"] for row in p0p1} & exact_findings),
        "p0_p1_without_literal_exact_current_id": len(p0p1) - len({row["id"] for row in p0p1} & exact_findings), "complete": False}
    payload["counts"] = {"findings": len(rows), "total_links": sum(len(row.get("feature_ids", [])) for row in rows),
        "findings_with_uncertainty": sum(bool(row.get("feature_link_reconciliation", {}).get("uncertainties")) for row in rows),
        "findings_without_literal_exact_current_id": len(rows) - len(exact_findings),
        "route_intersection_groups": sum(bool(decision.get("route_hits")) for decision in decisions),
        "unique_page_intersection_groups": sum(bool(decision.get("page_hits")) for decision in decisions),
        "one_to_one_groups": sum("one-to-one" in str(decision.get("method", "")).lower() for decision in decisions)}
    payload["findings"] = [{"finding_id": row["id"], "feature_ids": row.get("feature_ids", []),
                            "literal_current_feature_ids": [feature for feature in row.get("feature_ids", []) if feature in manifest_ids],
                            "reconciliation": row.get("feature_link_reconciliation", {})} for row in rows]
    require(payload["counts"] == {"findings": 97, "total_links": 273, "findings_with_uncertainty": 29,
            "findings_without_literal_exact_current_id": 0, "route_intersection_groups": 44,
            "unique_page_intersection_groups": 6, "one_to_one_groups": 104}, "Reconciliation count drift")
    require(payload["current_final_id_link_summary"]["literal_links"] == 174 and payload["current_final_id_link_summary"]["literal_targets"] == 142
            and payload["current_final_id_link_summary"]["p0_p1_with_literal_exact_current_id"] == 85, "Literal-link drift")


def validate_existing() -> None:
    findings = load(PATHS["findings"])
    require(sum(row["id"] == FINDING_ID for row in findings["findings"]) == 1, "Existing finding duplication")
    pointer = load(PATHS["pointer"])
    require(pointer["artifacts"]["pass8_operations"] == pin(PATHS["pass8"]), "Pass8 pointer drift")
    require(pointer["artifacts"]["operations_dashboard_site_privacy_generation_summary"] == pin(PATHS["summary"]), "Summary pointer drift")
    print(json.dumps({"status": "idempotent_no_change", "finding_id": FINDING_ID}, indent=2))


if any(row["id"] == FINDING_ID for row in load(PATHS["findings"])["findings"]):
    validate_existing()
    raise SystemExit(0)

for name, expected in PRE_PINS.items():
    require(sha_file(PATHS[name]) == expected, f"Input SHA drift: {name}")
verified_source = verify_source_chain()
benchmark, inventory, manifest = load(PATHS["benchmark"]), load(PATHS["inventory"]), load(PATHS["manifest"])
findings, reconciliation = load(PATHS["findings"]), load(PATHS["reconciliation"])
official_map, pointer = load(PATHS["official_map"]), load(PATHS["pointer"])
require(benchmark["summary"]["eligible_total"] == 473 and benchmark["summary"]["completion_unproved"]["total"] == 431, "Benchmark count drift")
by_benchmark = {row["working_key"]: row for row in benchmark["targets"]}
require(all(by_benchmark[feature]["status"] == "documented_ncm_direct" and by_benchmark[feature]["completion_credit"] for feature in FEATURE_IDS), "Operations benchmark state drift")
ops = [row for row in inventory["features"] if row["module_key"] == "OPERATIONS"]
require(len(ops) == 76 and {kind: sum(row["class"] == kind for row in ops) for kind in ("H", "D", "M")} == {"H": 74, "D": 2, "M": 0}, "Operations denominator drift")
route_rows = {row["route_id"]: row for row in inventory["routes"]}
require(route_rows["ROUTE-1897"]["working_canonical_feature_ids"] == ["OPS-DASHBOARD"], "Dashboard route ownership drift")
require(route_rows["ROUTE-1898"]["working_canonical_feature_ids"] == ["OPS-ACTIVITY-FEED"], "Activity route ownership drift")

pass8 = {"schema_version": "1.0.0", "artifact": "pass8-operations-904-2026-08-21", "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN,
    "status": "source_only_pass8_challenge_no_module_completion_credit",
    "selection": {"method": "Highest remaining unresolved linked-finding risk after excluding freshly reviewed eMAR, HR and Fleet modules.",
                  "selected_module": "OPERATIONS", "selected_score": 130, "next_scores": {"CONTROL_ROOM": 121, "RESPITE": 120}},
    "module_counts": {"targets": 76, "H": 74, "D": 2, "M": 0, "benchmark_decided": 38, "benchmark_unproved": 38,
                      "linked_p0_p1_before_wave": 4, "linked_p0_p1_after_wave": 5, "runtime_unexecuted_human_targets": 74,
                      "primary_inventory_routes": 360, "primary_inventory_pages": 142, "test_inventory_rows": 66},
    "eight_pass": {"P1": {"reviewed": 76, "denominator": 76, "boundary": "Static identity, routes, owners and source call graph."},
        "P2": {"executed": 0, "denominator": 74, "boundary": "Representative persisted human tasks unexecuted."},
        "P3": {"decided": 38, "denominator": 76, "unproved": 38, "boundary": "Benchmark/NCM evidence only; no local behavior credit."},
        "P4": {"executed": 0, "denominator": 74, "boundary": "Happy/error/recovery/handoff/responsive/accessibility execution absent."},
        "P5": {"static_reviewed": 76, "denominator": 76, "runtime_data_effects_verified": 0},
        "P6": {"exact_source_finding_official_links": 1, "denominator": 76, "boundary": "Official propositions frame risk only."},
        "P7": {"source_constraint_failure_links": 1, "denominator": 76, "tests_executed": 0},
        "P8": {"static_identity_challenged": 76, "denominator": 76, "module_completion_credit": False}},
    "new_finding": {"id": FINDING_ID, "priority": "P0", "feature_ids": FEATURE_IDS, "route_ids": ROUTE_IDS, "page_ids": PAGE_IDS,
                    "verdict": "independently_reviewed_new_nonduplicate_p0_static_only"},
    "source_chain": verified_source,
    "duplicate_boundary": {"existing_finding_count": 96, "related_not_duplicate": ["TASK-RBAC-001", "WF-ATTENDANCE-FORCED-END-SITE", "CTRL-RBAC-001"],
                           "distinction": "This finding owns the two exact Operations read surfaces and their cross-Site projection boundary."},
    "credit_boundary": {"runtime_credit_delta": 0, "browser_credit_delta": 0, "benchmark_credit_delta": 0,
                        "module_completion_delta": 0, "finding_delta": 1}}
save(PATHS["pass8"], pass8)

findings["findings"].append(finding_payload(findings)); findings["findings"].sort(key=lambda row: row["id"])
findings["counts"]["P0"] = 21
links = findings["counts"]["feature_link_reconciliation"]
links.update({"benchmark_mapping": {"eligible": 473, "verified_benchmark": 384, "documented_no_credible_match": 89, "completion_unproved": 431},
              "findings": 97, "total_links": 273, "literal_exact_current_links": 174, "literal_exact_current_targets": 142,
              "findings_with_literal_exact_current_id": 97, "p0_p1_with_literal_exact_current_id": 85,
              "p0_p1_without_literal_exact_current_id": 0, "findings_with_uncertainty": 29,
              "findings_without_literal_exact_current_id": 0, "route_intersection_groups": 44, "unique_page_intersection_groups": 6})
findings["audit_status"] = "Blocked—not comprehensive or complete. The canonical 904-target register is current (790H/111D/3M). Benchmark/NCM completion credit is 473/904, visual final-ID linkage is 8,168/8,753, material-state linkage is 3,948/4,312, and 97 source-backed findings are retained. All 85/85 P0/P1 findings contain a literal current-manifest ID; runtime remains unexecuted."
rebuild_reconciliation(reconciliation, findings, manifest)
require(official_map["denominator"] == official_map["reviewed"] == 54, "Official-map base drift")
official_map["findings"].append({"finding_id": FINDING_ID, "proposition_keys": ["HISF-ACCESS", "HIPC-R5", "MMH-BOLA"]})
official_map["findings"].sort(key=lambda row: row["finding_id"])
official_map["denominator"] = official_map["reviewed"] = 55; official_map["coverage_percent"] = 100.0
official_map["owner_boundary_rows"] = sum(any(str(key).startswith("OWNER-") for key in row["proposition_keys"]) for row in official_map["findings"])
require(official_map["owner_boundary_rows"] == 29, "Owner-boundary drift")
save(PATHS["findings"], findings); save(PATHS["reconciliation"], reconciliation); save(PATHS["official_map"], official_map)

outputs = {key: pin(PATHS[key]) for key in ("findings", "reconciliation", "official_map", "pass8")}
summary = {"schema_version": "1.0.0", "artifact": "final-904-operations-dashboard-site-privacy-generation-summary",
    "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN,
    "finding_id": FINDING_ID, "status": "generated_open_p0_static_only_runtime_and_completion_blocked",
    "inputs": {key: {"path": rel(PATHS[key]), "sha256": value, "bytes": PATHS[key].stat().st_size} for key, value in PRE_PINS.items()},
    "source_chain": verified_source, "outputs": outputs,
    "counts": {"denominator": {"total": 904, "H": 790, "D": 111, "M": 3},
               "benchmark": {"eligible": 473, "verified": 384, "ncm": 89, "unproved": 431},
               "findings": {"total": 97, "P0": 21, "P1": 64, "P2": 12},
               "finding_links": {"total": 273, "literal": 174, "literal_targets": 142, "p0_p1_literal": 85},
               "official_map": {"denominator": 55, "reviewed": 55, "owner_boundary_rows": 29}},
    "credit_boundary": {"static_finding_added": 1, "runtime_credit_delta": 0, "browser_credit_delta": 0,
                        "benchmark_credit_delta": 0, "remediation_credit_delta": 0, "completion_credit_delta": 0},
    "idempotence": "A second run validates hashes and pointer entries and performs no write."}
save(PATHS["summary"], summary)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"findings": outputs["findings"], "finding_link_reconciliation": outputs["reconciliation"],
    "official_nz_finding_proposition_map": outputs["official_map"], "pass8_operations": outputs["pass8"],
    "operations_dashboard_site_privacy_generation_summary": pin(PATHS["summary"])})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"; pointer["runtime_credit_delta"] = 0
save(PATHS["pointer"], pointer)
validate_existing()
print(json.dumps({"status": "generated", "finding_id": FINDING_ID, "outputs": outputs, "pointer": pin(PATHS["pointer"])}, indent=2))
