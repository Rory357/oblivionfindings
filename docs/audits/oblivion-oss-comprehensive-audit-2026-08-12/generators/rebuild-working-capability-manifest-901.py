#!/usr/bin/env python3
"""Materialize the source-reviewed 901-target working audit manifest.

This generator writes audit artifacts only. It does not execute the application,
touch a database, alter source code, or claim runtime/benchmark completion.
"""

from __future__ import annotations

import hashlib
import json
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
BASE_PATH = SOURCE / "working-capability-manifest-894.json"
DECISION_PATH = SOURCE / "capability-denominator-901-adjudication.json"
ROUTE_PAGE_RECONCILIATION_PATH = SOURCE / "route-page-gap-reconciliation-901.json"
INVENTORY_PATH = AUDIT / "inventory.json"
OUTPUT_PATH = SOURCE / "working-capability-manifest-901.json"
SUMMARY_PATH = SOURCE / "working-capability-manifest-901-generation-summary.json"
EXPECTED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
EXPECTED_BASE_SHA = "ad62d982e8e2fa2917ecf0f5106559403a28f8acb9946f6e906c2b280c679c51"
LOCKFILE_PROVEN_VENDOR_PREFIXES = {
    "vendor/laravel/fortify/": "laravel/fortify",
}


def load(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8-sig") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise RuntimeError(f"Expected object: {path}")
    return value


def write(path: Path, value: dict[str, Any]) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(lines: list[str]) -> str:
    payload = "\n".join(sorted(lines)).encode("utf-8")
    return hashlib.sha256(payload).hexdigest()


def strings(value: Any) -> list[str]:
    if value is None:
        return []
    if not isinstance(value, list):
        raise RuntimeError(f"Expected array, got {type(value).__name__}")
    return sorted({str(item).strip() for item in value if str(item).strip()})


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def source_anchor_present(anchor: str) -> bool:
    path = AUDIT.parent.parent.parent / anchor
    if path.exists():
        return True
    package = next(
        (name for prefix, name in LOCKFILE_PROVEN_VENDOR_PREFIXES.items() if anchor.startswith(prefix)),
        None,
    )
    if package is None:
        return False
    composer_lock = load(AUDIT.parent.parent.parent / "composer.lock")
    return any(
        str(row.get("name", "")) == package
        for row in composer_lock.get("packages", [])
    )


base = load(BASE_PATH)
decision = load(DECISION_PATH)
route_page_reconciliation = load(ROUTE_PAGE_RECONCILIATION_PATH)
inventory = load(INVENTORY_PATH)

require(sha_file(BASE_PATH) == EXPECTED_BASE_SHA, "Base 894 manifest changed")
require(base.get("audited_commit") == EXPECTED_COMMIT, "Base commit mismatch")
require(decision.get("audited_commit") == EXPECTED_COMMIT, "Decision commit mismatch")
require(route_page_reconciliation.get("audited_commit") == EXPECTED_COMMIT, "Route/page reconciliation commit mismatch")
require(inventory.get("commit") == EXPECTED_COMMIT, "Inventory commit mismatch")

targets = [dict(row) for row in base.get("targets", [])]
require(len(targets) == 894, f"Expected 894 base targets, got {len(targets)}")
target_by_key = {str(row.get("working_key")): row for row in targets}
require(len(target_by_key) == 894, "Base working keys are not unique")

known_routes = {str(row.get("route_id")) for row in inventory.get("routes", [])}
known_pages = {str(row.get("page_id")) for row in inventory.get("pages", [])}

for patch in decision.get("existing_target_corrections", []):
    require(isinstance(patch, dict), "Invalid existing-target correction")
    key = str(patch.get("working_key", ""))
    require(key in target_by_key, f"Unknown correction target: {key}")
    target = target_by_key[key]
    if "source_family_ids" in patch:
        target["source_family_ids"] = strings(patch["source_family_ids"])
    for field, add_field in (
        ("route_ids", "add_route_ids"),
        ("page_ids", "add_page_ids"),
        ("backend_anchors", "add_backend_anchors"),
    ):
        if field in patch:
            target[field] = strings(patch[field])
        if add_field in patch:
            target[field] = sorted(set(strings(target.get(field, []))) | set(strings(patch[add_field])))
    target["origin_files"] = sorted(
        set(strings(target.get("origin_files", []))) | {DECISION_PATH.name}
    )

additions = decision.get("accepted_additions", [])
require(isinstance(additions, list) and len(additions) == 7, "Expected seven accepted additions")
for addition in additions:
    require(isinstance(addition, dict), "Invalid accepted addition")
    key = str(addition.get("working_key", ""))
    require(key and key not in target_by_key, f"Duplicate or empty addition: {key}")
    row = {
        field: addition.get(field)
        for field in (
            "working_key", "id", "candidate_id", "id_status", "class",
            "canonical_module", "source_family_ids", "route_ids", "page_ids",
            "backend_anchors", "origin_files", "absorbed_occurrences",
            "route_predicates", "identity_proof", "naming_proof",
        )
        if field in addition
    }
    for field in ("source_family_ids", "route_ids", "page_ids", "backend_anchors", "origin_files"):
        row[field] = strings(row.get(field, []))
    row.setdefault("absorbed_occurrences", [])
    targets.append(row)
    target_by_key[key] = row

# Apply only source-proved accepted/existing target relationships. Dead,
# ambiguous, test-only, infrastructure and reachability-unproved surfaces stay
# in the reconciliation artifact and do not inflate accepted target envelopes.
for group in ("accepted_new_exact", "existing_exact"):
    for relation in route_page_reconciliation.get("routes", {}).get(group, []):
        for key in strings(relation.get("target_ids", [])):
            require(key in target_by_key, f"Unknown route reconciliation target: {key}")
            target = target_by_key[key]
            target["route_ids"] = sorted(
                set(strings(target.get("route_ids", []))) | set(strings(relation.get("route_ids", [])))
            )
            target["origin_files"] = sorted(
                set(strings(target.get("origin_files", []))) | {ROUTE_PAGE_RECONCILIATION_PATH.name}
            )

for group in ("accepted_new_exact", "existing_exact", "support_only"):
    for relation in route_page_reconciliation.get("pages", {}).get(group, []):
        for key in strings(relation.get("target_ids", [])):
            require(key in target_by_key, f"Unknown page reconciliation target: {key}")
            target = target_by_key[key]
            target["page_ids"] = sorted(
                set(strings(target.get("page_ids", []))) | set(strings(relation.get("page_ids", [])))
            )
            target["origin_files"] = sorted(
                set(strings(target.get("origin_files", []))) | {ROUTE_PAGE_RECONCILIATION_PATH.name}
            )

targets.sort(key=lambda row: str(row["working_key"]))
require(len(targets) == 901 and len(target_by_key) == 901, "901 target cardinality failed")
require(len({str(row.get("id")) for row in targets}) == 901, "Stable IDs are not unique")
require(all(str(row.get("working_key")) == str(row.get("id")) for row in targets), "Working key and stable ID differ")

for row in targets:
    key = str(row["working_key"])
    routes = strings(row.get("route_ids", []))
    pages = strings(row.get("page_ids", []))
    require(set(routes) <= known_routes, f"Unknown routes for {key}: {sorted(set(routes) - known_routes)}")
    require(set(pages) <= known_pages, f"Unknown pages for {key}: {sorted(set(pages) - known_pages)}")
    row["source_family_ids"] = strings(row.get("source_family_ids", []))
    row["route_ids"] = routes
    row["page_ids"] = pages
    row["backend_anchors"] = strings(row.get("backend_anchors", []))
    row["origin_files"] = strings(row.get("origin_files", []))
    require(row["source_family_ids"], f"Missing source lineage for {key}")
    if DECISION_PATH.name in row["origin_files"]:
        for anchor in row["backend_anchors"]:
            if "*" not in anchor and ";" not in anchor and anchor.startswith(("app/", "config/", "vendor/", "routes/", "resources/", "tests/")):
                require(source_anchor_present(anchor), f"Missing backend/source anchor for {key}: {anchor}")

class_counts = Counter(str(row.get("class")) for row in targets)
require(class_counts == Counter({"H": 788, "D": 111, "M": 2}), f"Class mismatch: {class_counts}")

module_counts_raw: dict[str, Counter[str]] = defaultdict(Counter)
for row in targets:
    module_counts_raw[str(row["canonical_module"])][str(row["class"])] += 1
module_counts = {
    module: {
        "total": sum(counter.values()),
        "H": counter["H"],
        "D": counter["D"],
        "M": counter["M"],
    }
    for module, counter in sorted(module_counts_raw.items())
}

route_to_targets: dict[str, list[str]] = defaultdict(list)
page_to_targets: dict[str, list[str]] = defaultdict(list)
for row in targets:
    for route_id in row["route_ids"]:
        route_to_targets[route_id].append(str(row["working_key"]))
    for page_id in row["page_ids"]:
        page_to_targets[page_id].append(str(row["working_key"]))

route_nonaccepted_ids = {
    route_id
    for group in (
        "excluded_dead_or_unreachable", "medical_reachability_unproved",
        "unresolved_ambiguity", "dead_or_noop", "generated_or_test_only",
        "infrastructure_or_out_of_product",
    )
    for relation in route_page_reconciliation.get("routes", {}).get(group, [])
    for route_id in strings(relation.get("route_ids", []))
}
page_nonaccepted_ids = {
    page_id
    for group in (
        "support_only", "resolver_orphan", "dead_or_noop",
        "generated_or_test_only", "infrastructure_or_out_of_product",
    )
    for relation in route_page_reconciliation.get("pages", {}).get(group, [])
    if not relation.get("target_ids")
    for page_id in strings(relation.get("page_ids", []))
}
require(not (set(route_to_targets) & route_nonaccepted_ids), "Accepted and nonaccepted route sets overlap")
require(not (set(page_to_targets) & page_nonaccepted_ids), "Accepted and nonaccepted page sets overlap")
require(set(route_to_targets) | route_nonaccepted_ids == known_routes, "Route disposition coverage is not exhaustive")
require(set(page_to_targets) | page_nonaccepted_ids == known_pages, "Page disposition coverage is not exhaustive")

declared_1364 = next(
    case for case in decision.get("deliberate_shared_route_cases", [])
    if case.get("route_id") == "ROUTE-1364"
)
require(
    sorted(route_to_targets["ROUTE-1364"]) == strings(declared_1364.get("working_keys", [])),
    "ROUTE-1364 branch target declaration mismatch",
)

id_status_counts = Counter(str(row.get("id_status")) for row in targets)
require(
    id_status_counts == Counter({
        "exact": 881,
        "source_stable_existing_feature_id": 4,
        "source_stable_reclassified": 1,
        "audit_assigned_stable_name": 15,
    }),
    f"ID status mismatch: {id_status_counts}",
)

counts = {
    "total": 901,
    "H": 788,
    "D": 111,
    "M": 2,
    "exact_current_ids": id_status_counts["exact"],
    "source_stable_ids": id_status_counts["source_stable_existing_feature_id"] + id_status_counts["source_stable_reclassified"],
    "audit_assigned_stable_names": id_status_counts["audit_assigned_stable_name"],
    "candidate_unproved_ids": sum(bool(row.get("candidate_id")) for row in targets),
    "unspelled_targets": sum(not str(row.get("id", "")).strip() for row in targets),
    "targets_with_route_ids": sum(bool(row["route_ids"]) for row in targets),
    "route_relations": sum(len(row["route_ids"]) for row in targets),
    "unique_primary_route_ids": len(route_to_targets),
    "route_ids_classified_outside_accepted_targets": len(route_nonaccepted_ids),
    "route_inventory_ids_with_static_disposition": len(set(route_to_targets) | route_nonaccepted_ids),
    "shared_route_ids": sum(len(keys) > 1 for keys in route_to_targets.values()),
    "targets_with_page_ids": sum(bool(row["page_ids"]) for row in targets),
    "page_relations": sum(len(row["page_ids"]) for row in targets),
    "unique_page_ids": len(page_to_targets),
    "page_ids_classified_outside_accepted_targets": len(page_nonaccepted_ids),
    "page_inventory_ids_with_static_disposition": len(set(page_to_targets) | page_nonaccepted_ids),
    "targets_with_backend_anchors": sum(bool(row["backend_anchors"]) for row in targets),
    "backend_relations": sum(len(row["backend_anchors"]) for row in targets),
    "unique_backend_anchors": len({anchor for row in targets for anchor in row["backend_anchors"]}),
    "source_family_relations": sum(len(row["source_family_ids"]) for row in targets),
}

canonical_lines = [
    f"{row['working_key']}|{row['class']}|{row['canonical_module']}|{row['id_status']}"
    for row in targets
]
stable_ids = [str(row["working_key"]) for row in targets]
source_stable = [
    str(row["working_key"])
    for row in targets
    if str(row.get("id_status", "")).startswith("source_stable")
]
audit_assigned = [
    str(row["working_key"])
    for row in targets
    if row.get("id_status") == "audit_assigned_stable_name"
]

manifest = dict(base)
manifest.update({
    "schema_version": "1.2",
    "artifact": "working-capability-manifest-901",
    "status": "working_static_manifest_901_identity_reconciled_not_completion_claim",
    "generated_at": decision.get("generated_at"),
    "audit_boundary": "Audit-artifact-only static identity and evidence reconciliation. Zero representative-role runtime completion and incomplete benchmark/route/page gates prohibit a completed-audit claim.",
    "counts": counts,
    "module_counts": module_counts,
    "checksums": {
        "working_targets_sha256": sha_lines(canonical_lines),
        "canonical_stable_target_ids_sha256": sha_lines(stable_ids),
        "source_stable_ids_sha256": sha_lines(source_stable),
        "audit_assigned_stable_names_sha256": sha_lines(audit_assigned),
        "method": "lexicographic sort; LF join without terminal LF; UTF-8 SHA-256",
    },
    "targets": targets,
    "supersedes": [
        {"file": BASE_PATH.name, "sha256": sha_file(BASE_PATH)},
        "working-capability-manifest-895.json",
    ],
    "excluded_surface_dispositions": decision.get("excluded_surface_dispositions", []),
    "denominator_adjudication": {
        "file": DECISION_PATH.name,
        "sha256": sha_file(DECISION_PATH),
        "accepted_delta": decision.get("accepted_counts", {}).get("delta"),
        "runtime_claim": False,
    },
    "route_page_gap_reconciliation": {
        "file": ROUTE_PAGE_RECONCILIATION_PATH.name,
        "sha256": sha_file(ROUTE_PAGE_RECONCILIATION_PATH),
        "route_coverage": route_page_reconciliation.get("validation", {}).get("route_coverage"),
        "page_coverage": route_page_reconciliation.get("validation", {}).get("page_coverage"),
        "accepted_relationships_applied": True,
        "nonaccepted_dispositions_retained_separately": True,
        "runtime_claim": False,
    },
})
manifest["transformations"] = list(base.get("transformations", [])) + [{
    "stage": "zero_candidate_denominator_and_identity_adjudication",
    "accepted_additions": 7,
    "existing_target_corrections": len(decision.get("existing_target_corrections", [])),
    "excluded_surface_dispositions": len(decision.get("excluded_surface_dispositions", [])),
    "result": 901,
    "count_fit_used": False,
    "runtime_claim": False,
}]
manifest["adjudication_inputs"] = sorted(
    set(strings(base.get("adjudication_inputs", []))) | {DECISION_PATH.name, ROUTE_PAGE_RECONCILIATION_PATH.name}
)

write(OUTPUT_PATH, manifest)

summary = {
    "schema_version": "1.0",
    "artifact": "working-capability-manifest-901-generation-summary",
    "status": "generated_and_validated_static_manifest_not_completion_claim",
    "generated_at": decision.get("generated_at"),
    "audited_commit": EXPECTED_COMMIT,
    "inputs": [
        {"file": BASE_PATH.name, "sha256": sha_file(BASE_PATH)},
        {"file": DECISION_PATH.name, "sha256": sha_file(DECISION_PATH)},
        {"file": ROUTE_PAGE_RECONCILIATION_PATH.name, "sha256": sha_file(ROUTE_PAGE_RECONCILIATION_PATH)},
        {"file": INVENTORY_PATH.name, "sha256": sha_file(INVENTORY_PATH)},
    ],
    "output": {"file": OUTPUT_PATH.name, "sha256": sha_file(OUTPUT_PATH)},
    "counts": counts,
    "module_counts": module_counts,
    "id_status_counts": dict(sorted(id_status_counts.items())),
    "checksums": manifest["checksums"],
    "validation": {
        "unique_working_keys": len(target_by_key) == 901,
        "unique_stable_ids": len({str(row["id"]) for row in targets}) == 901,
        "all_routes_known": all(route in known_routes for route in route_to_targets),
        "all_pages_known": all(page in known_pages for page in page_to_targets),
        "route_1364_three_branch_mapping_declared": True,
        "all_inventory_routes_have_static_disposition": (set(route_to_targets) | route_nonaccepted_ids) == known_routes,
        "all_inventory_pages_have_static_disposition": (set(page_to_targets) | page_nonaccepted_ids) == known_pages,
        "accepted_and_nonaccepted_route_sets_disjoint": not (set(route_to_targets) & route_nonaccepted_ids),
        "accepted_and_nonaccepted_page_sets_disjoint": not (set(page_to_targets) & page_nonaccepted_ids),
        "working_key_equals_stable_id": all(str(row["working_key"]) == str(row["id"]) for row in targets),
        "candidate_unproved_count_asserted": counts["candidate_unproved_ids"] == 0,
        "unspelled_target_count_asserted": counts["unspelled_targets"] == 0,
        "class_sum_matches_901": sum(class_counts.values()) == 901,
        "module_sum_matches_901": sum(row["total"] for row in module_counts.values()) == 901,
    },
    "completion_gate": {
        "complete": False,
        "reason": "Static denominator identity and the 197-route/63-page residual disposition set are reconciled, but support/orphan disposition linkage, 901 target-specific benchmark/NCM adjudication, representative-role browser execution and safe disposable-database validation remain incomplete or blocked.",
    },
}
write(SUMMARY_PATH, summary)

print(json.dumps({
    "output": str(OUTPUT_PATH),
    "sha256": sha_file(OUTPUT_PATH),
    "counts": counts,
    "module_counts": module_counts,
}, indent=2))
