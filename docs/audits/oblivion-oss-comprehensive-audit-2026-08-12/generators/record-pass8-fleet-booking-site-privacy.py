#!/usr/bin/env python3
"""Record the Fleet Pass-8 source challenge and vehicle-booking Site privacy finding."""

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
GENERATED_AT = "2026-08-21T22:20:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
CURRENT_MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
FINDING_ID = "FLEET-BOOKING-SITE-PRIVACY-01"
FEATURE_IDS = ["CAP-FLEET-VEHICLE-BOOKING-REQUEST", "CAP-FLEET-VEHICLE-BOOKING-DECISION", "CAP-FLEET-VEHICLE-BOOKING-CHECKOUT-RETURN"]
ROUTE_IDS = [f"ROUTE-{number:04d}" for number in range(712, 721)]
PAGE_IDS = ["PAGE-0209", "PAGE-0210", "PAGE-0211"]

PATHS = {
    "benchmark": SOURCE / "benchmark-final-904-mapping.json",
    "inventory": AUDIT / "inventory-904.json",
    "manifest": SOURCE / "working-capability-manifest-904.json",
    "findings": AUDIT / "findings.json",
    "reconciliation": SOURCE / "finding-link-reconciliation.json",
    "official_map": SOURCE / "official-nz-finding-proposition-map.json",
    "pointer": SOURCE / "canonical-audit-inputs.json",
    "pass8": SOURCE / "pass8-fleet-904-2026-08-21.json",
    "summary": SOURCE / "final-904-fleet-booking-site-privacy-generation-summary.json",
}

PRE_PINS = {
    "benchmark": "0923c6681011fe90c1e9e71bbea6c1ac5dbed33b4773c884fae2958fe1df869b",
    "inventory": "598d76cd63b23a7ea49164ad43e12cb10afdb9fed8437807d8b50d20b090cb9b",
    "manifest": "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    "findings": "cafa63fdc38baf9db33a6deb759e51b400b96156abdb5652337e3f50d7e4a5d4",
    "reconciliation": "e3f3a1d5791e1b93653ce5f4b7d14f77e5cd62a36b8a9ead55bacc4e15fc024b",
    "official_map": "7981a2bc31ca0919969645881803af5946323caa888dc2308bbddab18e5a06b2",
}

SOURCE_CHAIN = [
    ("routes/fleet-assets.php", "b93cd9e4e5de55b3807c5aec389d52724749eec2", "L108-L126", "c117901e96a026aba846ce3ccc35a1625dadf1bb", "L108-L126"),
    ("app/Http/Controllers/FleetAssets/VehicleBookingController.php", "666253e172538c55438fb4a78a074446262fd8d5", "L23-L100,L202-L375,L391-L511", "25a32a6f4061652045f5f1874573fae8eee6882b", "L23-L100,L202-L375,L391-L511"),
    ("app/Models/FleetVehicleBooking.php", "b505a9089db79f9e395b41a3720bea281ef50a94", "model", "4ce4836c453172d187a090bb84951c2d1962c0a3", "model"),
    ("app/Policies/AssetPolicy.php", "d5841ed53e076a6fae71a7cefb512db771f0ec66", "L17-L21", "ef4931d4c88542a85f7de7bf343da8d668e49f57", "L17-L21"),
    ("app/Services/UserSiteAccessService.php", "fed7affe854534221417be93dc2b2d8445488c8d", "L53-L83,L825-L833", "41d3ed5c671c1774b4865b62e88e053e8aea55a8", "L53-L83,L825-L833"),
    ("database/seeders/RbacSeeder.php", "4859ab64c19b8a35ba0656ef2f9e40b18943cc98", "Fleet permissions", "317c399b138d42edcb2d001323f2edb085ab4e41", "L732-L759"),
    ("resources/js/pages/fleet-assets/bookings/book-vehicle-wizard.tsx", "d2f638e26a7ce787ff2b8ffc5c51eb23be139c5f", "L345-L373,L628-L661", "378ea55793f55b7cc1d7d115a591b2f8ed15f6fd", "L345-L373,L628-L661"),
    ("resources/js/pages/fleet-assets/bookings/show.tsx", "73caafda66153e4b443a1522dd4de83811321a0e", "L194-L228", "a8be925713d46869e5b60dc02611623f08bcb703", "L194-L228"),
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
    require(git("rev-parse", "HEAD") == AUDITED_COMMIT, "Audited worktree HEAD drift")
    require(git("rev-parse", "refs/remotes/origin/main") == CURRENT_MAIN, "Current-main cross-check drift")
    verified = []
    for path, baseline_blob, baseline_loci, current_blob, current_loci in SOURCE_CHAIN:
        require(git("rev-parse", f"{AUDITED_COMMIT}:{path}") == baseline_blob, f"Baseline blob drift: {path}")
        require(git("rev-parse", f"{CURRENT_MAIN}:{path}") == current_blob, f"Current blob drift: {path}")
        verified.append({"path": path, "baseline_blob": baseline_blob, "baseline_loci": baseline_loci, "current_blob": current_blob, "current_loci": current_loci})
    return verified


def finding_payload(findings: dict[str, Any]) -> dict[str, Any]:
    row = copy.deepcopy(next(item for item in findings["findings"] if item["id"] == "MED-READER-SITE-CONCEALMENT-01"))
    anchors = [
        "routes/fleet-assets.php:108-126", "database/seeders/RbacSeeder.php:732-759",
        "app/Http/Controllers/FleetAssets/VehicleBookingController.php:23-44,76-100,202-255,264-375,391-511",
        "app/Policies/AssetPolicy.php:17-21", "app/Services/UserSiteAccessService.php:53-83,825-833",
        "app/Domain/SecurityDevices/Services/SecurityDevicesAccessService.php:271-326,984-989",
        "resources/js/pages/fleet-assets/bookings/book-vehicle-wizard.tsx:345-373,628-661",
        "resources/js/pages/fleet-assets/bookings/show.tsx:194-228",
        "tests/Feature/FleetAssets/FleetDashboardResidentSiteIsolationTest.php:82-115,709-725",
        "tests/Browser/Fleet/FleetPermissionsTest.php:130-164",
    ]
    row.update({
        "id": FINDING_ID, "feature_ids": FEATURE_IDS, "passes": ["P1", "P2", "P5", "P6", "P7", "P8"],
        "module": "Fleet and vehicles", "submodule": "Vehicle-booking Site, Client, and mutation scope",
        "actor_and_job": "A Site-bound support worker views or requests a vehicle booking without seeing or mutating another Site's bookings, clients, transport needs or assets.",
        "route_url": {"summary": "Nine vehicle-booking read, create, decision, checkout, return and cancellation routes share the unscoped booking controller.", "route_names": ["fleet-assets.bookings.index", "fleet-assets.bookings.create", "fleet-assets.bookings.store", "fleet-assets.bookings.approve", "fleet-assets.bookings.show", "fleet-assets.bookings.checkout", "fleet-assets.bookings.decline", "fleet-assets.bookings.return", "fleet-assets.bookings.cancel"], "route_paths": ["fleet-assets/bookings", "fleet-assets/bookings/create", "fleet-assets/bookings/{booking}", "fleet-assets/bookings/{booking}/approve", "fleet-assets/bookings/{booking}/checkout", "fleet-assets/bookings/{booking}/decline", "fleet-assets/bookings/{booking}/return", "fleet-assets/bookings/{booking}/cancel"]},
        "frontend_anchor": {"summary": "Booking register, wizard and show surfaces render controller-supplied requester, purpose, Site, Client and transport-needs data.", "page_files": ["resources/js/pages/fleet-assets/bookings/index.tsx", "resources/js/pages/fleet-assets/bookings/book-vehicle-wizard.tsx", "resources/js/pages/fleet-assets/bookings/show.tsx"], "audited_commit": AUDITED_COMMIT},
        "visual_context": {"visual_id": "None assigned", "classification": "Source-inferred", "role": "Site-bound Support Worker", "site_scope": "Assigned operational Sites only", "viewport": "Not safely reproduced", "state": "Source trace", "pattern_type": "backend/source finding", "component_anchor": "Vehicle booking register, wizard and detail", "screenshot_reference": "None—no browser or data disclosure was exercised", "internal_baseline": "AssetPolicy, UserSiteAccessService and SecurityDevicesAccessService fail-closed Site/object scope"},
        "pattern_implementation": "Static route, seeder, controller, policy, service, UI-prop and test review only; no booking, Client, asset, database or browser flow was executed.",
        "backend_anchors": anchors,
        "current_behavior": "At both pinned source snapshots, an ordinary Support Worker can receive fleet.viewAny while VehicleBookingController globally lists, exports and counts bookings; returns all active vehicles, Sites and Clients including transport_needs; accepts global asset/site IDs; and direct-loads booking lifecycle mutations without canonical Site/object scope.",
        "current_workflow": {"summary": "Source-reviewed nine-route booking workflow; no representative role or persisted booking was executed.", "failure_sequence": "A Site-A viewer requests the register, wizard or a Site-B booking ID; global queries and direct bindings can project foreign requester, purpose, Client transport needs, asset/Site choices or mutation targets before canonical concealment.", "boundary": "Operational Site access, booking action authority, Client privacy, asset ownership and direct-object concealment.", "completion_evidence": "Static audited/current-main source and test review only; no foreign record was populated, accessed or mutated."},
        "evidence": {"anchors": anchors, "existing_tests": ["FleetDashboardResidentSiteIsolationTest checks local booking metrics", "FleetPermissionsTest checks generic viewer/manager permissions only"], "tests_executed": False, "browser_claim_limit": "No credential, booking, Client, asset, transport-needs prop, lifecycle mutation or cross-Site disclosure was exercised."},
        "problem_root_cause": "Vehicle booking read and mutation paths do not delegate to a single fail-closed booking scope derived from accessible operational Sites and authorised assets.",
        "impact": "Ordinary Site workers can be source-authorised to discover cross-Site booking metadata and Client transport needs and target foreign booking/asset/Site objects. Runtime population, access, exploitation and external disclosure remain unverified.",
        "benchmark": {"selected": "All three exact capabilities remain benchmark-unproved", "url_and_sha": "", "verified_behavior": "No external comparator credit applies.", "outcome": "Retain unproved; no benchmark count delta", "no_match_evidence": "No NCM claim."},
        "neutral_requirements": "Use one canonical fail-closed booking scope for lists, exports, counts, pickers and every direct-bound lifecycle action; conceal foreign objects and keep explicit global-Site authority separate from action permission.",
        "better_oblivion_design": "Resolve accessible Sites, Clients and assets once, apply the scope before projection, and authorize/lock the canonical booking before every decision or custody transition.",
        "cross_module_effects": "Preserve Fleet, Sites, Clients, asset/security-device access, transport needs, booking lifecycle and audit ownership without a parallel permission bypass.",
        "rbac_privacy": "Support Worker fleet.viewAny remains an action permission, not global Site authority; foreign direct IDs conceal with 404 and explicit global-Site positives still require the booking action capability.",
        "priority": "P0", "effort": "M", "dependencies_sequence": "Fleet/privacy owner confirms global-view atoms; implement one scope owner; add two-Site list/picker/direct-ID/mutation tests; then representative browser validation.",
        "proposed_owner": "Fleet Engineering and Privacy/Security", "confidence": "High for the source-level callable boundary; runtime data population, access and exploitation remain unverified",
        "source_boundary": "Audited baseline and current main are separately pinned. Official propositions frame privacy and owner review only; application source proves the defect and no legal non-compliance is claimed.",
        "interim_safeguard": "Restrict vehicle-booking access to trusted Fleet managers and manually review Site assignments until the canonical scope is enforced.",
        "acceptance_criteria": ["Register, export, counts, vehicle/Site/Client pickers and selected-asset availability are limited to accessible Sites.", "Foreign booking, Client, asset and Site IDs conceal before props, audit or side effects.", "Approve, decline, checkout, return and cancel revalidate canonical scoped booking state under lock.", "Explicit global-Site positives retain the exact booking action permission.", "Two-Site same-Site, foreign-ID, omitted-filter and replay/concurrency tests pass on current main."],
        "missing_tests": ["Site-A booking viewer versus Site-B list/export/counts", "Foreign Client transport-needs and picker concealment", "Foreign booking/asset/Site direct IDs across all lifecycle actions", "Explicit global-Site plus action-permission positive", "Representative desktop/mobile no-submit denial state"],
        "validation_plan": ["First run one disposable two-Site current-main feature lane", "Assert same-Site positives and foreign concealment with zero side effects", "After remediation run lifecycle/replay/concurrency tests", "Perform desktop and mobile representative browser checks", "Retain open status until merged-to-main and runtime evidence are canonical"],
        "official_sources": [{"id": "NZ-HIPC", "title": "Health Information Privacy Code 2020", "authority": "Office of the Privacy Commissioner New Zealand", "url": "https://www.privacy.org.nz/privacy-act-2020/codes-of-practice/hipc2020/", "supporting_url": "", "inspected_date": "2026-08-12"}],
        "statement_types": {"source": "The route permission, globally scoped queries, Client transport-needs projection, direct bindings and missing booking policy are source-observed.", "official_source": "HISF-ACCESS, HIPC-R5, MMH-BOLA and OWNER-FLEET-PRIVACY frame privacy and owner review; they do not prove a runtime disclosure or legal breach.", "inference": "Cross-Site exposure and mutation are bounded source inferences; no foreign record was accessed.", "specialist_decision": "P0 priority and global Fleet authority semantics require Privacy/Security and Fleet ownership."},
        "official_source_proposition_keys": ["HISF-ACCESS", "HIPC-R5", "MMH-BOLA", "OWNER-FLEET-PRIVACY"],
        "feature_link_reconciliation": {"method": "route-first: exact nine booking routes, three final capability owners and one controller/privacy boundary", "projection_status": "literal_current_904_manifest_links_present; runtime_and_remediation_unverified", "legacy_feature_ids": ["FLEET-VEHICLE-BOOKINGS"], "decisions": [{"legacy_family_id": "independent-pass8-fleet-booking-site-privacy-2026-08-21", "method": "source-proven exact current route/backend intersection", "feature_ids": FEATURE_IDS, "route_hits": ROUTE_IDS, "page_hits": [{"page_id": PAGE_IDS[index], "feature_id": feature} for index, feature in enumerate(FEATURE_IDS)], "source_anchors": anchors, "evidence": "Fresh Fleet Pass-8 review traced ordinary-role permission, global booking/Client/Site/asset projections, direct bindings and missing canonical Site scope without runtime credit.", "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN}], "uncertainties": [{"reason_code": "runtime_foreign_data_and_representative_role_unexecuted", "detail": "Static evidence supports the authorization defect; populated foreign data, actual access, mutation and disclosure remain unverified.", "smallest_next_evidence": "Use a disposable two-Site fixture and assert a Site-A Support Worker cannot receive Site-B booking, Client transport_needs or direct booking props."}]},
        "remediation": {"status": "open", "note": "No isolated remediation branch or runtime verification is recorded."},
    })
    row["ease_evidence"] = {"validation_status": "Blocked—source finding retained; no representative runtime or ten-dimension validation executed", "evidence_basis": "Static source trace only", "current_scores": {key: 0 for key in ["discoverability", "comprehension", "learnability", "efficiency", "error_prevention", "recovery", "accessibility", "safety_and_trust", "consistency", "cross_module_continuity"]}, "friction": {"completion_time": "Not measured", "step_count": "Not measured", "required_field_count": "Not measured", "decision_count": "Privacy/Fleet owner decision required", "context_switches": "Not measured", "dead_ends": "Runtime unknown", "recovery_path": "Conceal foreign objects and preserve a safe same-Site booking path."}, "target_scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5}, "independent_review": "Independent source review confirmed a new P0 and rejected runtime, exploitation and legal claims."}
    row["target_ease"] = {"scores": {"all_dimensions": 4, "safety_critical_error_prevention_and_trust": 5}, "measurable_outcome": "A Site-bound support worker completes a same-Site request while every foreign booking, Client, Site and asset remains concealed."}
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
    payload["scope_boundary"] = "Links preserve audited source and literal current 904 IDs; they do not establish runtime outcome, remediation or completion."
    payload["current_final_id_link_summary"] = {"literal_links": len(exact), "literal_targets": len({feature for _, feature in exact}), "explicitly_re_adjudicated_links": prior["explicitly_re_adjudicated_links"] + 3, "explicitly_re_adjudicated_findings": sorted(set(prior["explicitly_re_adjudicated_findings"]) | {FINDING_ID}), "findings_with_literal_exact_current_id": len(exact_findings), "findings_without_literal_exact_current_id": len(rows) - len(exact_findings), "p0_p1_with_literal_exact_current_id": len({row["id"] for row in p0p1} & exact_findings), "p0_p1_without_literal_exact_current_id": len(p0p1) - len({row["id"] for row in p0p1} & exact_findings), "complete": False}
    payload["counts"] = {"findings": len(rows), "total_links": sum(len(row.get("feature_ids", [])) for row in rows), "findings_with_uncertainty": sum(bool(row.get("feature_link_reconciliation", {}).get("uncertainties")) for row in rows), "findings_without_literal_exact_current_id": len(rows) - len(exact_findings), "route_intersection_groups": sum(bool(decision.get("route_hits")) for decision in decisions), "unique_page_intersection_groups": sum(bool(decision.get("page_hits")) for decision in decisions), "one_to_one_groups": sum("one-to-one" in str(decision.get("method", "")).lower() for decision in decisions)}
    payload["findings"] = [{"finding_id": row["id"], "feature_ids": row.get("feature_ids", []), "literal_current_feature_ids": [feature for feature in row.get("feature_ids", []) if feature in manifest_ids], "reconciliation": row.get("feature_link_reconciliation", {})} for row in rows]
    require(payload["counts"] == {"findings": 96, "total_links": 271, "findings_with_uncertainty": 28, "findings_without_literal_exact_current_id": 0, "route_intersection_groups": 43, "unique_page_intersection_groups": 5, "one_to_one_groups": 104}, "Reconciliation count drift")
    require(payload["current_final_id_link_summary"]["literal_links"] == 172 and payload["current_final_id_link_summary"]["literal_targets"] == 140 and payload["current_final_id_link_summary"]["p0_p1_with_literal_exact_current_id"] == 84, "Literal-link drift")


def validate_existing() -> None:
    findings = load(PATHS["findings"])
    require(sum(row["id"] == FINDING_ID for row in findings["findings"]) == 1, "Existing finding duplication")
    pointer = load(PATHS["pointer"])
    require(pointer["artifacts"]["pass8_fleet"] == pin(PATHS["pass8"]), "Pass8 pointer drift")
    require(pointer["artifacts"]["fleet_booking_site_privacy_generation_summary"] == pin(PATHS["summary"]), "Summary pointer drift")
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
require(benchmark["summary"]["eligible_total"] == 472 and benchmark["summary"]["completion_unproved"]["total"] == 432, "Benchmark count drift")
by_benchmark = {row["working_key"]: row for row in benchmark["targets"]}
require(all(by_benchmark[feature]["status"].startswith("unproved") and not by_benchmark[feature]["completion_credit"] for feature in FEATURE_IDS), "Fleet benchmark state drift")
fleet = [row for row in inventory["features"] if row["module_key"] == "FLEET"]
require(len(fleet) == 49 and {kind: sum(row["class"] == kind for row in fleet) for kind in ("H", "D", "M")} == {"H": 48, "D": 1, "M": 0}, "Fleet denominator drift")
route_rows = {row["route_id"]: row for row in inventory["routes"]}
require(all(set(route_rows[route]["working_canonical_feature_ids"]).issubset(set(FEATURE_IDS)) and route_rows[route]["working_canonical_feature_ids"] for route in ROUTE_IDS), "Fleet route ownership drift")

pass8 = {"schema_version": "1.0.0", "artifact": "pass8-fleet-904-2026-08-21", "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN, "status": "source_only_pass8_challenge_no_module_completion_credit", "selection": {"method": "Risk-ranked feature-linked unresolved finding score: 100×P0 + 10×P1 + P2.", "selected_module": "FLEET", "selected_score": 330, "next_scores": {"SECURITY_DEVICES": 220, "CLINICAL": 210, "OPERATIONS": 191, "SITES": 160}}, "module_counts": {"targets": 49, "H": 48, "D": 1, "M": 0, "benchmark_decided": 30, "benchmark_unproved": 19, "linked_p0_p1_before_wave": 6, "linked_p0_p1_after_wave": 7, "runtime_unexecuted": 49, "routes": 157, "resolver_pages": 61, "test_inventory_rows": 6}, "eight_pass": {"P1": {"reviewed": 49, "denominator": 49, "boundary": "Static identity, routes, owners and source call graph."}, "P2": {"executed": 0, "denominator": 49, "boundary": "Representative persisted tasks unexecuted."}, "P3": {"decided": 30, "denominator": 49, "unproved": 19, "boundary": "Benchmark evidence only; no local behavior credit."}, "P4": {"executed": 0, "denominator": 49, "boundary": "Happy/error/recovery/handoff/responsive/accessibility execution absent."}, "P5": {"static_reviewed": 49, "denominator": 49, "runtime_data_effects_verified": 0}, "P6": {"exact_source_finding_official_links": 1, "denominator": 49, "boundary": "Official propositions are guidance only."}, "P7": {"source_constraint_failure_links": 1, "denominator": 49, "tests_executed": 0}, "P8": {"static_identity_challenged": 49, "denominator": 49, "module_completion_credit": False}}, "new_finding": {"id": FINDING_ID, "priority": "P0", "feature_ids": FEATURE_IDS, "route_ids": ROUTE_IDS, "page_ids": PAGE_IDS, "verdict": "independently_reviewed_new_nonduplicate_p0_static_only"}, "source_chain": verified_source, "duplicate_boundary": {"existing_finding_count": 95, "related_not_duplicate": ["ASSET-RBAC-01", "FLEET-TRANSPORT-01", "FLEET-MED-WITNESS-01"], "distinction": "This finding owns the vehicle-booking aggregate, booking routes and Client/Site privacy boundary."}, "credit_boundary": {"runtime_credit_delta": 0, "browser_credit_delta": 0, "benchmark_credit_delta": 0, "module_completion_delta": 0, "finding_delta": 1}}
save(PATHS["pass8"], pass8)

findings["findings"].append(finding_payload(findings))
findings["findings"].sort(key=lambda row: row["id"])
findings["counts"]["P0"] = 20
links = findings["counts"]["feature_link_reconciliation"]
links.update({"benchmark_mapping": {"eligible": 472, "verified_benchmark": 383, "documented_no_credible_match": 89, "completion_unproved": 432}, "findings": 96, "total_links": 271, "literal_exact_current_links": 172, "literal_exact_current_targets": 140, "findings_with_literal_exact_current_id": 96, "p0_p1_with_literal_exact_current_id": 84, "p0_p1_without_literal_exact_current_id": 0, "findings_with_uncertainty": 28, "findings_without_literal_exact_current_id": 0, "route_intersection_groups": 43, "unique_page_intersection_groups": 5})
findings["audit_status"] = "Blocked—not comprehensive or complete. The canonical 904-target register is current (790H/111D/3M). Benchmark/NCM completion credit is 472/904, visual final-ID linkage is 8,168/8,753, material-state linkage is 3,948/4,312, and 96 source-backed findings are retained. All 84/84 P0/P1 findings contain a literal current-manifest ID; runtime remains unexecuted."
rebuild_reconciliation(reconciliation, findings, manifest)
require(official_map["denominator"] == official_map["reviewed"] == 53, "Official-map base drift")
official_map["findings"].append({"finding_id": FINDING_ID, "proposition_keys": ["HISF-ACCESS", "HIPC-R5", "MMH-BOLA", "OWNER-FLEET-PRIVACY"]})
official_map["findings"].sort(key=lambda row: row["finding_id"])
official_map["denominator"] = official_map["reviewed"] = 54
official_map["coverage_percent"] = 100.0
official_map["owner_boundary_rows"] = sum(any(str(key).startswith("OWNER-") for key in row["proposition_keys"]) for row in official_map["findings"])
require(official_map["owner_boundary_rows"] == 29, "Owner-boundary drift")
save(PATHS["findings"], findings); save(PATHS["reconciliation"], reconciliation); save(PATHS["official_map"], official_map)

outputs = {key: pin(PATHS[key]) for key in ("findings", "reconciliation", "official_map", "pass8")}
summary = {"schema_version": "1.0.0", "artifact": "final-904-fleet-booking-site-privacy-generation-summary", "generated_at": GENERATED_AT, "audited_commit": AUDITED_COMMIT, "current_main_static_cross_check": CURRENT_MAIN, "finding_id": FINDING_ID, "status": "generated_open_p0_static_only_runtime_and_completion_blocked", "inputs": {key: {"path": rel(PATHS[key]), "sha256": value, "bytes": PATHS[key].stat().st_size} for key, value in PRE_PINS.items()}, "source_chain": verified_source, "outputs": outputs, "counts": {"denominator": {"total": 904, "H": 790, "D": 111, "M": 3}, "benchmark": {"eligible": 472, "verified": 383, "ncm": 89, "unproved": 432}, "findings": {"total": 96, "P0": 20, "P1": 64, "P2": 12}, "finding_links": {"total": 271, "literal": 172, "literal_targets": 140, "p0_p1_literal": 84}, "official_map": {"denominator": 54, "reviewed": 54, "owner_boundary_rows": 29}}, "credit_boundary": {"static_finding_added": 1, "runtime_credit_delta": 0, "browser_credit_delta": 0, "benchmark_credit_delta": 0, "remediation_credit_delta": 0, "completion_credit_delta": 0}, "idempotence": "A second run validates hashes and pointer entries and performs no write."}
save(PATHS["summary"], summary)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"].update({"findings": outputs["findings"], "finding_link_reconciliation": outputs["reconciliation"], "official_nz_finding_proposition_map": outputs["official_map"], "pass8_fleet": outputs["pass8"], "fleet_booking_site_privacy_generation_summary": pin(PATHS["summary"])})
pointer["completion_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"; pointer["runtime_credit_delta"] = 0
save(PATHS["pointer"], pointer)
validate_existing()
print(json.dumps({"status": "generated", "finding_id": FINDING_ID, "outputs": outputs, "pointer": pin(PATHS["pointer"])}, indent=2))
