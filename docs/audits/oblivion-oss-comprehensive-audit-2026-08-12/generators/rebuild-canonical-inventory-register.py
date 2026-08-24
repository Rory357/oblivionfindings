#!/usr/bin/env python3
"""Integrate the corrected 902-target static register into audit inventory.json.

Audit-artifact-only mechanical transformation. It preserves the original
740-row projection verbatim as structured evidence and makes no runtime claim.
"""

from __future__ import annotations

import hashlib
import json
import os
from collections import Counter, defaultdict
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
INVENTORY_PATH = AUDIT / "inventory.json"
MANIFEST_PATH = SOURCE / "working-capability-manifest-902.json"
BENCHMARK_PATH = SOURCE / "benchmark-final-902-mapping.json"
GAP_PATH = SOURCE / "route-page-gap-reconciliation-902.json"
SUMMARY_PATH = SOURCE / "canonical-inventory-register-generation-summary.json"

EXPECTED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
EXPECTED_MANIFEST_SHA = "ded38bc3672bf51cb48a02a576cc36ca83d01af6a982dbd19c118ff50edf59b9"
EXPECTED_BENCHMARK_SHA = "c2cb6ea0f584b8eef7c6e74cf6aca3cf580139fabdb66198ace43e02fddabe3c"
EXPECTED_GAP_SHA = "cefc4af1571d50ad17c155c083635d2bacf79828a78d1d68ffc2ee86242c49eb"
EXPECTED_PROJECTION_SHA = "6f4be98348c78391acfbda866dd6217f5aa7e67958cb3076049c1c5318f79b1a"
EXPECTED_PROJECTION_IDS_SHA = "016bcb52a0742d57eae432646642aeb1ae14c2193126bc73a07014d2d75fec12"


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def compact_sha(value: object) -> str:
    data = json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(data).hexdigest()


def lines_sha(values: list[str]) -> str:
    return hashlib.sha256("\n".join(values).encode("utf-8")).hexdigest()


def load(path: Path) -> dict:
    with path.open("r", encoding="utf-8-sig") as handle:
        return json.load(handle)


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


require(INVENTORY_PATH.is_file(), f"Missing inventory: {INVENTORY_PATH}")
require(MANIFEST_PATH.is_file(), f"Missing manifest: {MANIFEST_PATH}")
require(BENCHMARK_PATH.is_file(), f"Missing benchmark mapping: {BENCHMARK_PATH}")
require(GAP_PATH.is_file(), f"Missing route/page reconciliation: {GAP_PATH}")
require(sha_file(MANIFEST_PATH) == EXPECTED_MANIFEST_SHA, "Manifest SHA drift")
require(sha_file(BENCHMARK_PATH) == EXPECTED_BENCHMARK_SHA, "Benchmark mapping SHA drift")
require(sha_file(GAP_PATH) == EXPECTED_GAP_SHA, "Route/page reconciliation SHA drift")

inventory = load(INVENTORY_PATH)
manifest = load(MANIFEST_PATH)
benchmark = load(BENCHMARK_PATH)
gap = load(GAP_PATH)

require(str(inventory.get("commit")) == EXPECTED_COMMIT, "Inventory audited commit mismatch")
require(str(manifest.get("audited_commit")) == EXPECTED_COMMIT, "Manifest audited commit mismatch")
require(str(benchmark.get("audited_commit")) == EXPECTED_COMMIT, "Benchmark audited commit mismatch")
require(str(gap.get("audited_commit")) == EXPECTED_COMMIT, "Route/page reconciliation audited commit mismatch")

if "superseded_feature_projection" in inventory:
    projection = inventory["superseded_feature_projection"]["features"]
else:
    projection = inventory.get("features", [])

require(len(projection) == 740, f"Expected 740 projection rows, found {len(projection)}")
require(len({row["feature_id"] for row in projection}) == 740, "Projection feature IDs are not unique")
require(compact_sha(projection) == EXPECTED_PROJECTION_SHA, "Projection semantic SHA drift")
require(lines_sha(sorted(row["feature_id"] for row in projection)) == EXPECTED_PROJECTION_IDS_SHA, "Projection ID SHA drift")

targets = sorted(manifest.get("targets", []), key=lambda row: row["working_key"])
mappings = sorted(benchmark.get("targets", []), key=lambda row: row["working_key"])
require(len(targets) == 902 and len(mappings) == 902, "Manifest/benchmark row count mismatch")
target_keys = [row["working_key"] for row in targets]
mapping_keys = [row["working_key"] for row in mappings]
require(target_keys == mapping_keys and len(set(target_keys)) == 902, "Manifest/benchmark key-set mismatch")
require(all(row["id"] == row["working_key"] for row in targets), "working_key/id divergence")

benchmark_by_key = {row["working_key"]: row for row in mappings}
module_display = {row["module_key"]: row["module"] for row in inventory.get("modules", [])}
route_ids_inventory = {row["route_id"] for row in inventory.get("routes", [])}
page_ids_inventory = {row["page_id"] for row in inventory.get("pages", [])}


def excluded_surface_map(kind: str, inventory_ids: set[str]) -> dict[str, dict]:
    result: dict[str, dict] = {}
    id_field = f"{kind}_ids"
    for category, entries in gap.get(f"{kind}s", {}).items():
        for entry in entries:
            surface_id = entry.get("excluded_disposition_id")
            if not surface_id:
                continue
            detail = {
                "surface_id": surface_id,
                "category": category,
                "denominator_status": "excluded_from_accepted_H_D_M_capability_register",
                "user_facing": False,
                "candidate_target_id": entry.get("candidate_target_id"),
                "tombstone_id": entry.get("tombstone_id"),
                "support_family": entry.get("support_family"),
                "reason": entry.get("reason"),
            }
            for item_id in entry.get(id_field, []):
                require(item_id in inventory_ids, f"Unknown excluded {kind} ID: {item_id}")
                require(item_id not in result, f"Duplicate excluded {kind} disposition: {item_id}")
                result[item_id] = detail
    return result


route_surface_by_id = excluded_surface_map("route", route_ids_inventory)
page_surface_by_id = excluded_surface_map("page", page_ids_inventory)
require(len(route_surface_by_id) == 39, f"Expected 39 route surface dispositions, got {len(route_surface_by_id)}")
require(len(page_surface_by_id) == 17, f"Expected 17 page surface dispositions, got {len(page_surface_by_id)}")

canonical_features: list[dict] = []
route_reverse: dict[str, list[str]] = defaultdict(list)
page_reverse: dict[str, list[str]] = defaultdict(list)

core_target_fields = {
    "working_key", "id", "candidate_id", "id_status", "class", "canonical_module",
    "source_family_ids", "route_ids", "page_ids", "backend_anchors", "origin_files",
    "absorbed_occurrences", "naming_proof", "identity_proof",
}

for target in targets:
    key = target["working_key"]
    mapping = benchmark_by_key[key]
    require(mapping["class"] == target["class"], f"Benchmark class mismatch: {key}")
    require(mapping["canonical_module"] == target["canonical_module"], f"Benchmark module mismatch: {key}")
    require(mapping.get("id_status") == target.get("id_status"), f"Benchmark ID status mismatch: {key}")
    require(sorted(mapping.get("source_family_ids", [])) == sorted(target.get("source_family_ids", [])), f"Benchmark source lineage mismatch: {key}")

    routes = sorted(set(target.get("route_ids", [])))
    pages = sorted(set(target.get("page_ids", [])))
    require(set(routes) <= route_ids_inventory, f"Unknown target route for {key}")
    require(set(pages) <= page_ids_inventory, f"Unknown target page for {key}")
    for route_id in routes:
        route_reverse[route_id].append(key)
    for page_id in pages:
        page_reverse[page_id].append(key)

    proof_notes = {name: value for name, value in target.items() if name not in core_target_fields}
    canonical_features.append({
        "feature_id": target["id"],
        "working_key": key,
        "id_status": target.get("id_status"),
        "candidate_id": target.get("candidate_id"),
        "class": target["class"],
        "module_key": target["canonical_module"],
        "module": module_display.get(target["canonical_module"], target["canonical_module"]),
        "source_family_ids": sorted(set(target.get("source_family_ids", []))),
        "source_family_link_status": "linked" if target.get("source_family_ids") else "not_recorded_in_working_manifest",
        "route_ids": routes,
        "route_enrichment_status": "target_supported_static_enrichment" if routes else "not_enriched",
        "page_ids": pages,
        "page_enrichment_status": "target_supported_static_enrichment" if pages else "not_enriched",
        "backend_anchors": sorted(set(target.get("backend_anchors", []))),
        "origin_files": sorted(set(target.get("origin_files", []))),
        "absorbed_occurrences": target.get("absorbed_occurrences", []),
        "identity_evidence": {
            "naming_proof": target.get("naming_proof"),
            "identity_proof": target.get("identity_proof"),
        },
        "manifest_proof_notes": proof_notes,
        "benchmark_mapping": {
            "status": mapping.get("status"),
            "completion_credit": bool(mapping.get("completion_credit")),
            "inheritance_method": mapping.get("inheritance_method"),
            "prior_outcome": mapping.get("prior_outcome"),
            "source_units": mapping.get("source_units", []),
            "evidence_loci": mapping.get("evidence_loci", []),
        },
        "runtime_validation_status": "not_executed",
        "completion_claim": False,
    })

route_target_count = sum(bool(row["route_ids"]) for row in canonical_features)
route_relation_count = sum(len(values) for values in route_reverse.values())
unique_route_count = len(route_reverse)
shared_route_count = sum(1 for values in route_reverse.values() if len(values) > 1)
page_target_count = sum(bool(row["page_ids"]) for row in canonical_features)
page_relation_count = sum(len(values) for values in page_reverse.values())
unique_page_count = len(page_reverse)
shared_page_count = sum(1 for values in page_reverse.values() if len(values) > 1)
backend_target_count = sum(bool(row["backend_anchors"]) for row in canonical_features)
backend_relation_count = sum(len(row["backend_anchors"]) for row in canonical_features)
unique_backend_anchor_count = len({anchor for row in canonical_features for anchor in row["backend_anchors"]})
source_family_relation_count = sum(len(row["source_family_ids"]) for row in canonical_features)

require(route_target_count == manifest["counts"]["targets_with_route_ids"], "Manifest route-target count mismatch")
require(unique_route_count == manifest["counts"]["unique_primary_route_ids"], "Manifest unique-route count mismatch")
require(page_target_count == manifest["counts"]["targets_with_page_ids"], "Manifest page-target count mismatch")
require(unique_page_count == manifest["counts"]["unique_page_ids"], "Manifest unique-page count mismatch")
require(unique_route_count <= len(route_ids_inventory), "Enriched route count exceeds inventory")
require(unique_page_count <= len(page_ids_inventory), "Enriched page count exceeds inventory")
require((route_target_count, route_relation_count, unique_route_count, shared_route_count) == (901, 3065, 2985, 30), "902 route enrichment totals drift")
require((page_target_count, page_relation_count, unique_page_count, shared_page_count) == (756, 1526, 945, 228), "902 page enrichment totals drift")
require((backend_target_count, backend_relation_count, unique_backend_anchor_count) == (729, 828, 469), "902 backend enrichment totals drift")
require(source_family_relation_count == 993, "902 source-family relation total drift")
require(len(route_ids_inventory) == 3024, "Route inventory denominator drift")
require(len(page_ids_inventory) == 962, "Page inventory denominator drift")

route_mapping_percent = round(100 * unique_route_count / len(route_ids_inventory), 2)
page_mapping_percent = round(100 * unique_page_count / len(page_ids_inventory), 2)

for route in inventory.get("routes", []):
    links = sorted(route_reverse.get(route["route_id"], []))
    surface = route_surface_by_id.get(route["route_id"])
    require(not (links and surface), f"Route is both accepted and excluded: {route['route_id']}")
    require(bool(links) != bool(surface), f"Route lacks exactly one static disposition: {route['route_id']}")
    route["working_canonical_feature_ids"] = links
    route["excluded_surface_disposition_ids"] = [surface["surface_id"]] if surface else []
    route["static_disposition_ids"] = links if links else [surface["surface_id"]]
    route["static_disposition_kind"] = "accepted_capability_target" if links else "excluded_surface_non_denominator"
    route["working_canonical_feature_link_status"] = "target_supported_static_enrichment" if links else "excluded_surface_disposition"
    route["static_surface_disposition"] = surface
    route["projection_feature_link_status"] = "superseded_projection_evidence_only"

for page in inventory.get("pages", []):
    links = sorted(page_reverse.get(page["page_id"], []))
    surface = page_surface_by_id.get(page["page_id"])
    require(not (links and surface), f"Page is both accepted and excluded: {page['page_id']}")
    require(bool(links) != bool(surface), f"Page lacks exactly one static disposition: {page['page_id']}")
    page["working_canonical_feature_ids"] = links
    page["excluded_surface_disposition_ids"] = [surface["surface_id"]] if surface else []
    page["static_disposition_ids"] = links if links else [surface["surface_id"]]
    page["static_disposition_kind"] = "accepted_capability_target" if links else "excluded_surface_non_denominator"
    page["working_canonical_feature_link_status"] = "target_supported_static_enrichment" if links else "excluded_surface_disposition"
    page["static_surface_disposition"] = surface
    page["projection_feature_link_status"] = "superseded_projection_evidence_only"

class_counts = Counter(row["class"] for row in canonical_features)
id_status_counts = Counter(row["id_status"] for row in canonical_features)
module_counts = Counter(row["module_key"] for row in canonical_features)
mapping_status_counts = Counter(row["benchmark_mapping"]["status"] for row in canonical_features)
credit_count = sum(1 for row in canonical_features if row["benchmark_mapping"]["completion_credit"])

require(class_counts == Counter({"H": 788, "D": 111, "M": 3}), f"Class counts wrong: {class_counts}")
require(id_status_counts == Counter({"exact": 881, "audit_assigned_stable_name": 16, "source_stable_existing_feature_id": 4, "source_stable_reclassified": 1}), f"ID provenance counts wrong: {id_status_counts}")
require(credit_count == 451, "Benchmark credit count mismatch")
allowed_mapping_statuses = {
    "verified_benchmark_direct", "verified_benchmark_rename",
    "documented_ncm_direct", "documented_ncm_rename",
    "unproved", "unproved_audit_assigned_id", "unproved_pending",
    "unproved_reject", "unproved_source_stable",
}
require(set(mapping_status_counts) <= allowed_mapping_statuses, f"Unexpected benchmark status: {mapping_status_counts}")
verified_direct_count = mapping_status_counts["verified_benchmark_direct"]
verified_rename_count = mapping_status_counts["verified_benchmark_rename"]
ncm_direct_count = mapping_status_counts["documented_ncm_direct"]
ncm_rename_count = mapping_status_counts["documented_ncm_rename"]
verified_count = verified_direct_count + verified_rename_count
ncm_count = ncm_direct_count + ncm_rename_count
unproved_count = len(canonical_features) - credit_count
require((verified_direct_count, verified_rename_count) == (340, 22), "Verified benchmark direct/rename partition mismatch")
require((ncm_direct_count, ncm_rename_count) == (82, 7), "Documented NCM direct/rename partition mismatch")
require(verified_count + ncm_count == 451, "Benchmark eligible breakdown mismatch")
require(unproved_count == 451, "Benchmark completion-unproved count mismatch")

declared_module_counts = manifest.get("module_counts", {})
require(set(module_counts) == set(declared_module_counts), "Module key-set mismatch")
for key, counts in declared_module_counts.items():
    require(module_counts[key] == counts["total"], f"Module count mismatch: {key}")

for module in inventory.get("modules", []):
    key = module["module_key"]
    counts = declared_module_counts[key]
    module.setdefault("superseded_projection_feature_count", module.get("feature_count"))
    module.setdefault("superseded_projection_user_facing_feature_count", module.get("user_facing_feature_count"))
    module["feature_count"] = counts["total"]
    module["canonical_capability_count"] = counts["total"]
    module["user_facing_feature_count"] = counts["H"]
    module["human_capability_count"] = counts["H"]
    module["download_or_api_capability_count"] = counts["D"]
    module["machine_ingress_capability_count"] = counts["M"]

inventory["schema_version"] = "3.2"
inventory["canonical_feature_register_metadata"] = {
    "status": "complete_static_identity_register_runtime_unverified",
    "register_field": "features",
    "count": 902,
    "counts": {"H": 788, "D": 111, "M": 3},
    "id_provenance": {"exact_current": 881, "source_stable": 5, "audit_assigned_stable_name": 16},
    "source_family_enrichment": {
        "targets_with_source_family_ids": sum(bool(row["source_family_ids"]) for row in canonical_features),
        "targets_not_linked_to_source_family_ids": sum(not row["source_family_ids"] for row in canonical_features),
        "unique_source_family_ids": len({family for row in canonical_features for family in row["source_family_ids"]}),
        "relations": source_family_relation_count,
    },
    "route_enrichment": {"targets": route_target_count, "relations": route_relation_count, "unique_routes": unique_route_count, "excluded_surface_routes": len(route_surface_by_id), "inventory_routes_with_static_disposition": unique_route_count + len(route_surface_by_id), "shared_route_ids": shared_route_count, "inventory_routes": len(route_ids_inventory)},
    "page_enrichment": {"targets": page_target_count, "relations": page_relation_count, "unique_pages": unique_page_count, "excluded_surface_pages": len(page_surface_by_id), "inventory_pages_with_static_disposition": unique_page_count + len(page_surface_by_id), "shared_page_ids": shared_page_count, "inventory_pages": len(page_ids_inventory)},
    "backend_enrichment": {"targets": backend_target_count, "relations": backend_relation_count, "unique_anchors": unique_backend_anchor_count},
    "benchmark_mapping": {"verified_benchmark": verified_count, "documented_no_credible_match": ncm_count, "completion_credit": 451, "completion_unproved": 451},
    "source_artifacts": {
        "manifest": "evidence/source/working-capability-manifest-902.json",
        "manifest_sha256": EXPECTED_MANIFEST_SHA,
        "benchmark_mapping": "evidence/source/benchmark-final-902-mapping.json",
        "benchmark_mapping_sha256": EXPECTED_BENCHMARK_SHA,
        "route_page_gap_reconciliation": "evidence/source/route-page-gap-reconciliation-902.json",
        "route_page_gap_reconciliation_sha256": EXPECTED_GAP_SHA,
    },
    "proof_rules": {
        "identity": "Static stable-ID and ownership register only.",
        "empty_source_family_ids": "Not recorded in the working manifest; never evidence that no source family exists.",
        "empty_route_or_page_ids": "No accepted-target relation is recorded; any discovered route/page still retains an excluded SURFACE disposition ID.",
        "excluded_surface_ids": "Stable audit disposition IDs preserve dead, ambiguous, test-only, infrastructure, orphan, no-op, or reachability-unproved surfaces. They are not accepted H/D/M capabilities and receive no benchmark or completion credit.",
        "shared_route_or_page_ids": "Some parameter-distinct or shared-support capabilities intentionally retain the same route/page evidence; declarations and proof boundaries remain in the enrichment artifacts.",
        "benchmark_completion_credit": "Benchmark/NCM mapping gate only; not product, runtime or usability completion.",
        "accepted_feature_id_completion_gate": "Blocked: accepted FEATURE-ID registration is static identity evidence and never runtime or product completion.",
        "runtime": "No representative-role, persisted outcome, recovery, error, handoff or viewport completion is claimed.",
        "security_boundary": "Single tenant, multiple sites; role, permission, ownership, direct-object and privacy boundaries. No tenant-isolation claim.",
    },
}
inventory["superseded_feature_projection"] = {
    "status": "evidence_only_not_canonical",
    "count": 740,
    "human": 677,
    "nonhuman": 63,
    "semantic_compact_json_sha256": EXPECTED_PROJECTION_SHA,
    "sorted_feature_ids_sha256": EXPECTED_PROJECTION_IDS_SHA,
    "features": projection,
}
inventory["features"] = canonical_features

definitions = inventory.setdefault("classification_definitions", {})
definitions["H"] = "Human-facing capability/job. Static registration does not prove reachability, usability or completion."
definitions["D"] = "Download, API or data-delivery capability. Static registration does not prove actor access or runtime response."
definitions["M"] = "Machine-ingress capability. Static registration does not prove delivery, replay or operational completion."
definitions["target_supported_static_enrichment"] = "Target-specific route/page relation retained by the corrected working manifest."
definitions["excluded_surface_disposition"] = "The discovered route/page is retained under a stable SURFACE disposition ID outside the accepted H/D/M target denominator."

denominators = inventory["denominators"]
denominators["canonical_features_registered"] = 902
denominators["canonical_human_capabilities_registered"] = 788
denominators["canonical_download_or_api_capabilities_registered"] = 111
denominators["canonical_machine_ingress_capabilities_registered"] = 3
denominators["working_accepted_capability_denominator"] = 902
denominators["working_human_ui_capability_denominator"] = 788
denominators["final_download_or_api_capability_denominator"] = 111
denominators["final_machine_ingress_capability_denominator"] = 3
denominators["working_distinct_user_capability_denominator"] = 788
denominators["working_accepted_distinct_capability_denominator"] = 902
denominators["downstream_manifest_rows_integrated"] = 902
denominators["canonical_route_relations_enriched"] = route_relation_count
denominators["canonical_unique_routes_enriched"] = unique_route_count
denominators["canonical_route_mapping_percent"] = route_mapping_percent
denominators["canonical_page_relations_enriched"] = page_relation_count
denominators["canonical_unique_pages_enriched"] = unique_page_count
denominators["canonical_page_mapping_percent"] = page_mapping_percent
denominators["route_inventory_ids_with_static_disposition"] = unique_route_count + len(route_surface_by_id)
denominators["page_inventory_ids_with_static_disposition"] = unique_page_count + len(page_surface_by_id)
denominators["final_id_task_scripts_structurally_materialized"] = 788
denominators["representative_actor_classes_executed"] = 12
denominators["rendered_component_viewport_rows"] = 1880
denominators["required_component_viewport_rows_present"] = 1880
denominators["component_families_with_all_required_viewports"] = 470
denominators["fully_measured_component_viewport_rows"] = 1876
denominators["fully_measured_component_families"] = 469

status = inventory["capability_denominator_status"]
status["status"] = "working_902_static_manifest_integrated_as_canonical_inventory_register_runtime_unverified"
status["earlier_denominator_status"] = "The 901/788/111/2 register is superseded by the source-owner-reconciled 902/788/111/3 register. The added machine target is the signal-to-alert processing pipeline; it has backend lineage but no route or page, so the 3024-route and 962-page surfaces are unchanged."
status["working_accepted_denominator"] = 902
status["working_human_ui"] = 788
status["final_download_or_api"] = 111
status["final_machine_ingress"] = 3
status["working_manifest"] = "evidence/source/working-capability-manifest-902.json"
status["working_manifest_unique_stable_ids"] = 902
status["stable_id_provenance"] = {
    "exact_current": 881,
    "source_stable": 5,
    "audit_assigned": 16,
}
status["downstream_manifest_rows_integrated"] = 902
status["downstream_manifest_percent"] = 100.0
status["projection_warning"] = "The original 740 feature rows are preserved under superseded_feature_projection as evidence only."
status["inventory_route_mapping"] = {"completed": unique_route_count, "denominator": len(route_ids_inventory), "percent": route_mapping_percent, "scope": "target-supported static enrichment"}
status["inventory_page_mapping"] = {"completed": unique_page_count, "denominator": len(page_ids_inventory), "percent": page_mapping_percent, "scope": "target-supported static enrichment"}
status["runtime_task_execution"] = "0/788 working human targets"
status["reason"] = "All 902 stable IDs are materialized and every inventoried route/page has either an accepted-target or excluded-surface static disposition. Accepted FEATURE-ID identity is not completion proof: the preserved audit-snapshot pass sampled 11 actor classes, and a bounded current-main direct-login pass now samples the Clinical/Medication Lead at all four required viewports, bringing actor entry to 12/12. Canonical task execution, denied states, persisted outcomes, recovery, handoff and usability validation remain incomplete."
status["accepted_feature_id_completion_gate"] = {
    "status": "blocked",
    "accepted_ids_registered": 902,
    "reason": "Static accepted FEATURE-ID registration does not prove representative-role completion, persisted outcomes, recovery, handoff, viewport behavior, or runtime enforcement.",
}

inventory["benchmark_mapping"]["working_manifest_eligible"] = 451
inventory["benchmark_mapping"]["working_manifest_verified_benchmark"] = verified_count
inventory["benchmark_mapping"]["working_manifest_verified_direct"] = verified_direct_count
inventory["benchmark_mapping"]["working_manifest_verified_rename"] = verified_rename_count
inventory["benchmark_mapping"]["working_manifest_documented_no_credible_match"] = ncm_count
inventory["benchmark_mapping"]["working_manifest_documented_ncm_direct"] = ncm_direct_count
inventory["benchmark_mapping"]["working_manifest_documented_ncm_rename"] = ncm_rename_count
inventory["benchmark_mapping"]["working_manifest_completion_unproved"] = 451

inventory["benchmark_mapping"]["completion_gate_status"] = "451/902 final targets have evidence-preserving benchmark/NCM mapping; 451 remain completion-unproved"
inventory["pass_status"]["P1"] = f"Blocked—902/902 accepted FEATURE-IDs registered; {len(route_ids_inventory)}/{len(route_ids_inventory)} routes and {len(page_ids_inventory)}/{len(page_ids_inventory)} pages have an accepted-target or excluded-surface static disposition; accepted-ID registration is not runtime completion proof"
inventory["pass_status"]["P2"] = "Blocked—788/788 final-ID task scripts and scorecard rows are structurally materialized; 0/788 representative-role tasks executed or independently usability-validated"
inventory["pass_status"]["P3"] = f"Blocked—451/902 targets mapped with evidence-preserving completion credit ({verified_count} verified benchmark, {ncm_count} documented No Credible Match); 451 unproved"
inventory["pass_status"]["P4"] = "Blocked—12/12 representative actor classes sampled for bounded entry; 0/8 common/safety-critical journeys executed at all required viewports"
inventory["pass_status"]["P6"] = "Blocked—official propositions source-reviewed 50/50; representative role/site/direct-object and privacy enforcement unexecuted"
inventory["pass_status"]["P8"] = "Blocked—fresh review found unresolved target route/page enrichment, benchmark research, role, runtime, state and visual evidence gaps"
inventory["audit_status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
inventory["environment_evidence"] = "Owner-confirmed test/development. The preserved audit-snapshot impersonation pass sampled 11 actor classes without domain-form submission. A later current-main direct-login pass sampled the synthetic Clinical/Medication Lead on Health & Clinical and eMAR at 390x844, 1024x768, 1280x800 and 1440x900. Only normal authentication/session activity occurred; no canonical task was submitted."
inventory["browser_evidence"] = {
    **dict(inventory.get("browser_evidence", {})),
    "representative_role_pass": "evidence/source/browser-representative-role-pass-901.json",
    "clinical_lead_current_main_pass": "evidence/source/browser-clinical-lead-current-main-pass-902.json",
    "roles_sampled": ["Administrator", "Support Worker", "Provider Manager", "Auditor (Read only)", "Next of Kin / Guardian (Portal)", "Coordinator / Scheduler", "HR", "Finance", "Health and Safety Officer", "Control Room operator", "Client portal user", "Clinical Lead"],
    "role_gap": "Closed for bounded actor entry: all 12 classes have a signed-in browser sample. This does not prove any of the 788 canonical tasks, eight journeys, denied states, recovery or handoff behavior.",
}

inventory["excluded_surface_disposition_register"] = {
    "status": "complete_static_surface_disposition_non_denominator",
    "accepted_target_ids_excluded": False,
    "route_surface_ids": sorted({value["surface_id"] for value in route_surface_by_id.values()}),
    "route_relations": len(route_surface_by_id),
    "page_surface_ids": sorted({value["surface_id"] for value in page_surface_by_id.values()}),
    "page_relations": len(page_surface_by_id),
    "manifest_excluded_tombstones_and_branches": manifest.get("excluded_surface_dispositions", []),
    "source_artifact": "evidence/source/route-page-gap-reconciliation-902.json",
    "source_sha256": EXPECTED_GAP_SHA,
    "rule": "SURFACE IDs and manifest tombstones preserve discovered implementation evidence without entering inventory.features, H/D/M counts, benchmark credit, task scripts, or completion numerators.",
}

status["working_manifest_sha256"] = EXPECTED_MANIFEST_SHA
status["route_enrichment"] = {
    "targets": route_target_count,
    "relations": route_relation_count,
    "unique_routes": unique_route_count,
}
status["page_enrichment"] = {
    "targets": page_target_count,
    "relations": page_relation_count,
    "unique_pages": unique_page_count,
}
status["backend_enrichment"] = {
    "targets": backend_target_count,
    "relations": backend_relation_count,
    "unique_backend_anchors": unique_backend_anchor_count,
}
status["benchmark_mapping"] = {
    "eligible": 451,
    "verified_benchmark": verified_count,
    "documented_no_credible_match": ncm_count,
    "completion_unproved": 451,
}
status["source_route_classification"] = (
    f"{len(route_ids_inventory)}/{len(route_ids_inventory)} classified and assigned to a source family; "
    f"{unique_route_count}/{len(route_ids_inventory)} map to accepted final working IDs and {len(route_surface_by_id)} retain excluded SURFACE dispositions"
)
status["source_page_classification"] = (
    f"{len(page_ids_inventory)}/{len(page_ids_inventory)} classified with a source-family relation; "
    f"{unique_page_count}/{len(page_ids_inventory)} map to accepted final working IDs and {len(page_surface_by_id)} retain excluded SURFACE dispositions"
)

temp_path = INVENTORY_PATH.with_suffix(".json.tmp")
encoded = (json.dumps(inventory, ensure_ascii=False, indent=2) + "\n").encode("utf-8")
temp_path.write_bytes(encoded)
reloaded = load(temp_path)

require(len(reloaded["features"]) == 902, "Reloaded canonical register row count mismatch")
require(len({row["feature_id"] for row in reloaded["features"]}) == 902, "Reloaded canonical IDs not unique")
require(len(reloaded["superseded_feature_projection"]["features"]) == 740, "Reloaded projection not preserved")
require(compact_sha(reloaded["superseded_feature_projection"]["features"]) == EXPECTED_PROJECTION_SHA, "Reloaded projection SHA drift")
require(all(not row["completion_claim"] and row["runtime_validation_status"] == "not_executed" for row in reloaded["features"]), "Runtime completion claim detected")
require(all(row.get("static_disposition_ids") for row in reloaded["routes"]), "A route lacks a static disposition ID")
require(all(row.get("static_disposition_ids") for row in reloaded["pages"]), "A page lacks a static disposition ID")
require(sum(row["static_disposition_kind"] == "accepted_capability_target" for row in reloaded["routes"]) == 2985, "Accepted route disposition count mismatch")
require(sum(row["static_disposition_kind"] == "excluded_surface_non_denominator" for row in reloaded["routes"]) == 39, "Excluded route disposition count mismatch")
require(sum(row["static_disposition_kind"] == "accepted_capability_target" for row in reloaded["pages"]) == 945, "Accepted page disposition count mismatch")
require(sum(row["static_disposition_kind"] == "excluded_surface_non_denominator" for row in reloaded["pages"]) == 17, "Excluded page disposition count mismatch")

os.replace(temp_path, INVENTORY_PATH)
inventory_sha = sha_file(INVENTORY_PATH)
canonical_tuple_sha = lines_sha([
    "|".join((row["feature_id"], row["class"], row["module_key"], ";".join(row["source_family_ids"]), ";".join(row["route_ids"]), ";".join(row["page_ids"])))
    for row in canonical_features
])

summary = {
    "schema_version": "1.0",
    "artifact": "canonical-inventory-register-generation-summary",
    "audited_commit": EXPECTED_COMMIT,
    "status": "complete_static_identity_register_runtime_unverified",
    "audit_boundary": "Audit artifacts only; no application code, configuration, routes, data, tests, browser state, deployment or Git history changed.",
    "inputs": {
        "manifest_sha256": EXPECTED_MANIFEST_SHA,
        "benchmark_mapping_sha256": EXPECTED_BENCHMARK_SHA,
        "route_page_gap_reconciliation_sha256": EXPECTED_GAP_SHA,
        "superseded_projection_semantic_sha256": EXPECTED_PROJECTION_SHA,
        "superseded_projection_id_sha256": EXPECTED_PROJECTION_IDS_SHA,
    },
    "counts": {
        "canonical_features": 902,
        "H": 788,
        "D": 111,
        "M": 3,
        "id_provenance": {"exact_current": 881, "source_stable": 5, "audit_assigned_stable_name": 16},
        "benchmark_completion_credit": 451,
        "benchmark_completion_unproved": 451,
        "verified_benchmark": verified_count,
        "documented_no_credible_match": ncm_count,
        "route_enriched_targets": route_target_count,
        "route_relations": route_relation_count,
        "unique_enriched_routes": unique_route_count,
        "shared_route_ids": shared_route_count,
        "excluded_route_surface_relations": len(route_surface_by_id),
        "route_inventory_ids_with_static_disposition": unique_route_count + len(route_surface_by_id),
        "page_enriched_targets": page_target_count,
        "page_relations": page_relation_count,
        "unique_enriched_pages": unique_page_count,
        "shared_page_ids": shared_page_count,
        "excluded_page_surface_relations": len(page_surface_by_id),
        "page_inventory_ids_with_static_disposition": unique_page_count + len(page_surface_by_id),
        "backend_enriched_targets": backend_target_count,
        "backend_relations": backend_relation_count,
        "unique_backend_anchors": unique_backend_anchor_count,
        "source_family_relations": source_family_relation_count,
        "representative_role_tasks_executed": 0,
    },
    "outputs": {
        "inventory": "../../inventory.json",
        "inventory_sha256": inventory_sha,
        "canonical_tuple_sha256": canonical_tuple_sha,
    },
    "completion_gate": {
        "status": "blocked",
        "accepted_feature_ids_registered": 902,
        "reason": "Static accepted FEATURE-ID registration and route/page disposition do not prove representative-role runtime completion, persisted outcomes, recovery, handoff, viewport behavior, or permission enforcement.",
    },
    "proof_boundary": "Empty target route/page arrays mean not enriched. Benchmark completion credit is only the benchmark/NCM gate. Runtime, usability, error, recovery, handoff and viewport completion remain unproved.",
}
SUMMARY_PATH.write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

print(json.dumps({
    "canonical_features": 902,
    "classes": dict(class_counts),
    "benchmark_completion_credit": credit_count,
    "route_relations": route_relation_count,
    "unique_routes": unique_route_count,
    "page_relations": page_relation_count,
    "unique_pages": unique_page_count,
    "inventory_sha256": inventory_sha,
    "canonical_tuple_sha256": canonical_tuple_sha,
    "summary_sha256": sha_file(SUMMARY_PATH),
}, indent=2))
