#!/usr/bin/env python3
"""Apply independently reviewed static route/page enrichment to the 894 manifest.

This generator only writes audit artifacts.  It does not inspect or mutate runtime
state.  Each enrichment file must name existing final working keys and existing
inventory route/page IDs.  Existing target allocations may only be retained or
expanded; a conflicting replacement is rejected.
"""

from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
MANIFEST_PATH = SOURCE / "working-capability-manifest-894.json"
INVENTORY_PATH = AUDIT / "inventory.json"
SOURCE_ADJUDICATION_PATH = SOURCE / "full-distinct-capability-adjudication.json"
SUMMARY_PATH = SOURCE / "static-route-enrichment-application-summary.json"
ENRICHMENT_PATHS = [
    SOURCE / "hr-operations-static-route-enrichment.json",
    SOURCE / "finance-sites-static-route-enrichment.json",
    SOURCE / "full-static-route-enrichment.json",
]

EXPECTED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"

# These routes bridge targets that were enriched by different source artifacts.
# They are declared here because neither individual artifact has the full global
# target set needed to express the shared relation.
CROSS_ARTIFACT_SHARED_ROUTE_CASES = [
    {
        "case": "board_member_appointment_role_assignment_boundary",
        "route_ids": ["ROUTE-2628", "ROUTE-2629"],
        "working_keys": ["CAP-GOV-BOARD-MEMBER-APPOINTMENT", "CAP-SET-ROLE-ASSIGNMENTS"],
        "reason": "The Settings AccessController board-member actions establish/remove the board appointment and its access-role assignment atomically; this is shared static evidence, not exclusive route ownership.",
    },
]


def load(path: Path) -> dict[str, Any]:
    with path.open("r", encoding="utf-8-sig") as handle:
        value = json.load(handle)
    if not isinstance(value, dict):
        raise RuntimeError(f"Expected a JSON object: {path}")
    return value


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def unique_strings(value: Any) -> list[str]:
    if value is None:
        return []
    if not isinstance(value, list):
        raise RuntimeError(f"Expected an array, found {type(value).__name__}")
    return sorted({str(item).strip() for item in value if str(item).strip()})


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


manifest_input_sha = sha_file(MANIFEST_PATH)
inventory_input_sha = sha_file(INVENTORY_PATH)
manifest = load(MANIFEST_PATH)
inventory = load(INVENTORY_PATH)
source_adjudication = load(SOURCE_ADJUDICATION_PATH)

require(manifest.get("audited_commit") == EXPECTED_COMMIT, "Manifest commit mismatch")
require(inventory.get("commit") == EXPECTED_COMMIT, "Inventory commit mismatch")

targets = list(manifest.get("targets", []))
require(len(targets) == 894, f"Expected 894 manifest targets, found {len(targets)}")
target_by_key = {str(row.get("working_key")): row for row in targets}
require(len(target_by_key) == 894, "Manifest working keys are not unique")

known_routes = {str(row.get("route_id")) for row in inventory.get("routes", [])}
known_pages = {str(row.get("page_id")) for row in inventory.get("pages", [])}
route_family_by_id = {
    str(row.get("route_id")): str(row.get("legacy_family_id", "")).strip()
    for row in inventory.get("routes", [])
}
known_families = {
    str(row.get("legacy_family_id"))
    for row in source_adjudication.get("decisions", [])
    if str(row.get("legacy_family_id", "")).strip()
}
known_families |= {
    str(row.get("legacy_family_id"))
    for row in inventory.get("routes", [])
    if str(row.get("legacy_family_id", "")).strip()
}

before = {
    "targets_with_routes": sum(bool(row.get("route_ids")) for row in targets),
    "route_relations": sum(len(unique_strings(row.get("route_ids", []))) for row in targets),
    "unique_routes": len({route for row in targets for route in unique_strings(row.get("route_ids", []))}),
    "targets_with_pages": sum(bool(row.get("page_ids")) for row in targets),
    "page_relations": sum(len(unique_strings(row.get("page_ids", []))) for row in targets),
    "unique_pages": len({page for row in targets for page in unique_strings(row.get("page_ids", []))}),
    "targets_with_backend_anchors": sum(bool(row.get("backend_anchors")) for row in targets),
}

seen_patch_keys: dict[str, str] = {}
applied_by_artifact: dict[str, int] = {}
input_records: list[dict[str, Any]] = []
all_unresolved: list[dict[str, str]] = []
declared_shared_routes: dict[str, dict[str, Any]] = {}


def register_shared_case(case: dict[str, Any], source_name: str) -> None:
    route_ids = unique_strings(case.get("route_ids", [case.get("route_id")] if case.get("route_id") else []))
    working_keys = unique_strings(case.get("working_keys", []))
    reason = str(case.get("reason", "")).strip()
    require(route_ids and len(working_keys) > 1 and reason, f"Incomplete shared-route declaration in {source_name}")
    for route_id in route_ids:
        require(route_id in known_routes, f"Unknown shared route {route_id} in {source_name}")
        require(route_id not in declared_shared_routes, f"Shared route {route_id} declared more than once")
        declared_shared_routes[route_id] = {
            "route_id": route_id,
            "working_keys": working_keys,
            "reason": reason,
            "source": source_name,
        }


for case in CROSS_ARTIFACT_SHARED_ROUTE_CASES:
    register_shared_case(case, "apply-static-route-enrichments.py")

for path in ENRICHMENT_PATHS:
    require(path.is_file(), f"Missing enrichment artifact: {path.name}")
    artifact = load(path)
    require(artifact.get("audited_commit") == EXPECTED_COMMIT, f"Commit mismatch: {path.name}")
    for case in artifact.get("deliberate_shared_route_cases", []):
        require(isinstance(case, dict), f"Invalid shared-route declaration in {path.name}")
        register_shared_case(case, path.name)
    patch_value = artifact.get("targets")
    route_family_exceptions = {
        (str(row.get("working_key")), str(row.get("route_id"))): str(row.get("justification", "")).strip()
        for row in artifact.get("route_family_exceptions", [])
        if isinstance(row, dict)
    }
    require(
        all(key and route and justification for (key, route), justification in route_family_exceptions.items()),
        f"Incomplete route-family exception in {path.name}",
    )
    if isinstance(patch_value, dict):
        patch_items = sorted(patch_value.items())
    elif isinstance(patch_value, list):
        patch_items = []
        for patch in patch_value:
            require(isinstance(patch, dict), f"Invalid target patch in {path.name}")
            key = str(patch.get("working_key") or patch.get("id") or "")
            require(bool(key), f"Target patch lacks working key in {path.name}")
            patch_items.append((key, patch))
        patch_items.sort()
    else:
        raise RuntimeError(f"Missing target patch map in {path.name}")

    applied = 0
    for key, patch in patch_items:
        key = str(key)
        require(key in target_by_key, f"Unknown final target {key} in {path.name}")
        require(isinstance(patch, dict), f"Invalid patch for {key} in {path.name}")
        require(key not in seen_patch_keys, f"Target {key} patched by both {seen_patch_keys.get(key)} and {path.name}")
        seen_patch_keys[key] = path.name

        target = target_by_key[key]
        patch_families = unique_strings(patch.get("source_family_ids", target.get("source_family_ids", [])))
        target_families = unique_strings(target.get("source_family_ids", []))
        require(
            set(target_families) <= set(patch_families),
            f"Source-family removal for {key}: manifest={target_families}, patch={patch_families}",
        )
        require(
            set(patch_families) <= known_families,
            f"Unknown source families for {key}: {sorted(set(patch_families) - known_families)}",
        )

        patch_routes = unique_strings(patch.get("route_ids", []))
        patch_pages = unique_strings(patch.get("page_ids", []))
        patch_backends = unique_strings(patch.get("backend_anchors", []))
        patch_origins = unique_strings(patch.get("origin_files", []))
        require(set(patch_routes) <= known_routes, f"Unknown route IDs for {key}: {sorted(set(patch_routes) - known_routes)}")
        require(set(patch_pages) <= known_pages, f"Unknown page IDs for {key}: {sorted(set(patch_pages) - known_pages)}")
        for route_id in patch_routes:
            route_family = route_family_by_id.get(route_id, "")
            if route_family and route_family not in patch_families:
                require(
                    bool(route_family_exceptions.get((key, route_id))),
                    f"Route-family mismatch for {key}/{route_id}: {route_family} not in {patch_families}",
                )

        current_routes = unique_strings(target.get("route_ids", []))
        current_pages = unique_strings(target.get("page_ids", []))
        current_backends = unique_strings(target.get("backend_anchors", []))
        require(set(current_routes) <= set(patch_routes), f"Route allocation conflict for {key}")
        require(set(current_pages) <= set(patch_pages), f"Page allocation conflict for {key}")
        require(set(current_backends) <= set(patch_backends), f"Backend allocation conflict for {key}")

        target["source_family_ids"] = patch_families
        target["route_ids"] = patch_routes
        target["page_ids"] = patch_pages
        target["backend_anchors"] = patch_backends
        target["origin_files"] = sorted(set(unique_strings(target.get("origin_files", []))) | set(patch_origins) | {path.name})
        applied += 1

    applied_by_artifact[path.name] = applied
    for unresolved in artifact.get("unresolved_targets", []):
        if isinstance(unresolved, str):
            all_unresolved.append({"working_key": unresolved, "artifact": path.name})
        elif isinstance(unresolved, dict):
            record = {str(k): str(v) for k, v in unresolved.items()}
            record["artifact"] = path.name
            all_unresolved.append(record)
    input_records.append({
        "file": path.name,
        "sha256": sha_file(path),
        "target_patches": applied,
        "status": artifact.get("status"),
    })

route_relations = sum(len(unique_strings(row.get("route_ids", []))) for row in targets)
page_relations = sum(len(unique_strings(row.get("page_ids", []))) for row in targets)
unique_routes = {route for row in targets for route in unique_strings(row.get("route_ids", []))}
unique_pages = {page for row in targets for page in unique_strings(row.get("page_ids", []))}
route_targets: dict[str, list[str]] = {}
for row in targets:
    for route_id in unique_strings(row.get("route_ids", [])):
        route_targets.setdefault(route_id, []).append(str(row["working_key"]))
actual_shared_routes = {
    route_id: sorted(keys)
    for route_id, keys in route_targets.items()
    if len(keys) > 1
}
require(
    set(actual_shared_routes) == set(declared_shared_routes),
    f"Shared-route declarations mismatch: actual-only={sorted(set(actual_shared_routes) - set(declared_shared_routes))}, declared-only={sorted(set(declared_shared_routes) - set(actual_shared_routes))}",
)
for route_id, keys in actual_shared_routes.items():
    require(
        set(keys) <= set(declared_shared_routes[route_id]["working_keys"]),
        f"Shared-route target mismatch for {route_id}: actual={keys}, declared={declared_shared_routes[route_id]['working_keys']}",
    )

after = {
    "targets_with_routes": sum(bool(row.get("route_ids")) for row in targets),
    "route_relations": route_relations,
    "unique_routes": len(unique_routes),
    "targets_with_pages": sum(bool(row.get("page_ids")) for row in targets),
    "page_relations": page_relations,
    "unique_pages": len(unique_pages),
    "targets_with_backend_anchors": sum(bool(row.get("backend_anchors")) for row in targets),
}

manifest["counts"]["targets_with_route_ids"] = after["targets_with_routes"]
manifest["counts"]["unique_primary_route_ids"] = after["unique_routes"]
manifest["counts"]["targets_with_page_ids"] = after["targets_with_pages"]
manifest["counts"]["unique_page_ids"] = after["unique_pages"]

previous_enrichment = next(
    (
        row for row in manifest.get("transformations", [])
        if isinstance(row, dict) and row.get("stage") == "target_specific_static_route_page_enrichment"
    ),
    None,
)
baseline_before = previous_enrichment.get("before", before) if previous_enrichment else before
transformations = [
    row for row in manifest.get("transformations", [])
    if not (isinstance(row, dict) and row.get("stage") == "target_specific_static_route_page_enrichment")
]
transformations.append({
    "stage": "target_specific_static_route_page_enrichment",
    "patched_targets": len(seen_patch_keys),
    "before": baseline_before,
    "after": after,
    "input_artifacts": [record["file"] for record in input_records],
    "runtime_claim": False,
})
manifest["transformations"] = transformations
manifest["adjudication_inputs"] = sorted(set(unique_strings(manifest.get("adjudication_inputs", []))) | {path.name for path in ENRICHMENT_PATHS})
manifest["proof_rules"]["route_page"] = (
    "Route/page arrays contain target-supported static evidence. Split-family allocations were independently "
    "partitioned by exact route actions, resource/permission boundaries and direct render pages; unresolved "
    "allocations remain empty. Shared host/support evidence is not exclusive ownership and no runtime completion is claimed."
)

MANIFEST_PATH.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
reloaded = load(MANIFEST_PATH)
require(len(reloaded.get("targets", [])) == 894, "Reloaded manifest row count changed")
require({row["working_key"] for row in reloaded["targets"]} == set(target_by_key), "Reloaded manifest key set changed")
require(reloaded["counts"]["total"] == 894, "Canonical denominator changed")

summary = {
    "schema_version": "1.0",
    "artifact": "static-route-enrichment-application-summary",
    "audited_commit": EXPECTED_COMMIT,
    "status": "static_target_lineage_enriched_runtime_unverified",
    "audit_boundary": "Audit artifacts only; no application code, configuration, data, tests, browser state, deployment or Git history changed.",
    "inputs": {
        "manifest_input_sha256": manifest_input_sha,
        "inventory_sha256": inventory_input_sha,
        "source_adjudication_sha256": sha_file(SOURCE_ADJUDICATION_PATH),
        "enrichment_artifacts": input_records,
    },
    "application": {
        "patched_targets": len(seen_patch_keys),
        "patches_by_artifact": applied_by_artifact,
        "before": baseline_before,
        "after": after,
        "unresolved_targets": all_unresolved,
        "declared_shared_routes": [declared_shared_routes[key] for key in sorted(declared_shared_routes)],
    },
    "output": {
        "manifest": MANIFEST_PATH.name,
        "manifest_sha256": sha_file(MANIFEST_PATH),
    },
    "validation": {
        "canonical_target_count_preserved": True,
        "canonical_working_key_set_preserved": True,
        "all_route_ids_exist_in_inventory": True,
        "all_page_ids_exist_in_inventory": True,
        "existing_allocations_only_retained_or_expanded": True,
        "all_shared_route_relations_explicitly_declared": True,
        "source_family_lineage_only_retained_or_expanded_from_known_inventory_families": True,
        "runtime_or_completion_claimed": False,
    },
}
SUMMARY_PATH.write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

print(json.dumps({
    "patched_targets": len(seen_patch_keys),
    "before": baseline_before,
    "after": after,
    "manifest_sha256": sha_file(MANIFEST_PATH),
    "summary_sha256": sha_file(SUMMARY_PATH),
}, indent=2))
