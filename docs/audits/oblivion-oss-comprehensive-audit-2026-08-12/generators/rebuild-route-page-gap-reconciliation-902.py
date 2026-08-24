#!/usr/bin/env python3
"""Pin the corrected route/page surface reconciliation to manifest 902.

Audit-artifact-only deterministic transform. The one target added between the
901 and 902 manifests is a machine pipeline with no route or page relation, so
the regenerated 901 route/page relation corpus and corrected excluded-SURFACE
dispositions are retained while the accepted FEATURE-ID denominator is repinned.
"""

from __future__ import annotations

import copy
import hashlib
import json
from collections import Counter
from pathlib import Path


GENERATOR_DIR = Path(__file__).resolve().parent
AUDIT = GENERATOR_DIR.parent
SOURCE = AUDIT / "evidence" / "source"
BASE_GAP_PATH = SOURCE / "route-page-gap-reconciliation-901.json"
MANIFEST_PATH = SOURCE / "working-capability-manifest-902.json"
OUTPUT_PATH = SOURCE / "route-page-gap-reconciliation-902.json"
SUMMARY_PATH = SOURCE / "route-page-gap-reconciliation-902-generation-summary.json"

EXPECTED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
EXPECTED_BASE_GAP_SHA = "e04eacf0587ac2b24c5f3dde2edebba39a7dc2ce37329c5a3fc55cd4cfb52468"
EXPECTED_MANIFEST_SHA = "ded38bc3672bf51cb48a02a576cc36ca83d01af6a982dbd19c118ff50edf59b9"
NEW_TARGET_ID = "CAP-CR-SIGNAL-TO-ALERT-PIPELINE"


def load(path: Path) -> dict:
    with path.open("r", encoding="utf-8-sig") as handle:
        return json.load(handle)


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def lines_sha(values: list[str]) -> str:
    return hashlib.sha256("\n".join(values).encode("utf-8")).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


require(sha_file(BASE_GAP_PATH) == EXPECTED_BASE_GAP_SHA, "901 gap evidence SHA drift")
require(sha_file(MANIFEST_PATH) == EXPECTED_MANIFEST_SHA, "902 manifest SHA drift")

base = load(BASE_GAP_PATH)
manifest = load(MANIFEST_PATH)
targets = manifest.get("targets", [])
target_ids = {row["working_key"] for row in targets}
target_by_id = {row["working_key"]: row for row in targets}

require(base.get("audited_commit") == EXPECTED_COMMIT, "901 gap audited commit mismatch")
require(manifest.get("audited_commit") == EXPECTED_COMMIT, "902 manifest audited commit mismatch")
require(len(targets) == 902 and len(target_ids) == 902, "902 manifest identity count mismatch")
require(all(row["working_key"] == row["id"] for row in targets), "working_key/id divergence")
require(Counter(row["class"] for row in targets) == Counter({"H": 788, "D": 111, "M": 3}), "902 class count mismatch")
require(Counter(row["id_status"] for row in targets) == Counter({
    "exact": 881,
    "source_stable_existing_feature_id": 4,
    "source_stable_reclassified": 1,
    "audit_assigned_stable_name": 16,
}), "902 ID provenance mismatch")

new_target = target_by_id[NEW_TARGET_ID]
require(new_target["class"] == "M", "New signal-pipeline target must remain machine class")
require(new_target.get("route_ids") == [], "New signal-pipeline target unexpectedly gained routes")
require(new_target.get("page_ids") == [], "New signal-pipeline target unexpectedly gained pages")
require(new_target.get("source_family_ids") == ["CR-ALERT"], "New target source lineage drift")

payload = copy.deepcopy(base)
payload["artifact"] = "route-page-gap-reconciliation-902"
payload["generated_at"] = manifest.get("generated_at")
payload["inputs"] = [
    {
        "file": BASE_GAP_PATH.name,
        "role": "corrected route/page relation and excluded-SURFACE corpus",
        "sha256": EXPECTED_BASE_GAP_SHA,
    },
    {
        "file": MANIFEST_PATH.name,
        "role": "accepted FEATURE-ID identity and lineage pin",
        "sha256": EXPECTED_MANIFEST_SHA,
        "canonical_stable_target_ids_sha256": lines_sha(sorted(target_ids)),
    },
    {
        "source": "inventory.json route/page surface",
        "routes": {"accepted_target": 2985, "excluded_surface": 39, "total": 3024},
        "pages": {"accepted_target": 945, "excluded_surface": 17, "total": 962},
    },
    {
        "source": "902 denominator adjudication",
        "accepted_delta_from_901": {"total": 1, "H": 0, "D": 0, "M": 1},
        "route_delta": 0,
        "page_delta": 0,
    },
]

accepted_new = sorted([
    *base["denominator"]["accepted_new_target_ids"],
    NEW_TARGET_ID,
])
payload["denominator"] = {
    "baseline": 894,
    "accepted_new": 8,
    "accepted": 902,
    "accepted_new_class_counts": {"H": 4, "D": 3, "M": 1},
    "accepted_new_target_ids": accepted_new,
}
payload["normalization_note"] = (
    "The corrected 901 route/page corpus is retained. CAP-CR-SIGNAL-TO-ALERT-PIPELINE "
    "is an accepted machine FEATURE-ID with backend lineage but no route or page, so "
    "it changes the accepted denominator only and creates no surface relation."
)
payload["accepted_feature_id_gate"] = {
    "identity_register": "902/902 statically registered",
    "surface_disposition": "3024/3024 routes and 962/962 pages retain an accepted-target or excluded-SURFACE disposition",
    "completion_status": "blocked",
    "reason": "Accepted FEATURE-ID registration and static route/page disposition are not runtime, role, error, recovery, handoff, viewport, test, or product-completion proof.",
}

route_seen: dict[str, str] = {}
page_seen: dict[str, str] = {}
linked_target_ids: set[str] = set()
for group, rows in payload["routes"].items():
    for row in rows:
        linked_target_ids.update(row.get("target_ids", []))
        for route_id in row.get("route_ids", []):
            require(route_id not in route_seen, f"Duplicate route gap relation: {route_id}")
            route_seen[route_id] = group
for group, rows in payload["pages"].items():
    for row in rows:
        linked_target_ids.update(row.get("target_ids", []))
        for page_id in row.get("page_ids", []):
            require(page_id not in page_seen, f"Duplicate page gap relation: {page_id}")
            page_seen[page_id] = group

require(linked_target_ids <= target_ids, "Gap corpus references a non-902 target")
require(len(route_seen) == 197 and len(page_seen) == 63, "Gap corpus surface count drift")
require(payload["counts"]["route_target_relations"] == 196, "Route target-relation drift")
require(payload["counts"]["page_target_relations"] == 70, "Page target-relation drift")

route_labels = {
    "accepted_new_exact": "accepted_new_exact_target",
    "existing_exact": "existing_exact_target",
    "excluded_dead_or_unreachable": "excluded_dead_or_unreachable",
    "medical_reachability_unproved": "medical_reachability_unproved",
    "unresolved_ambiguity": "unresolved_ambiguity",
    "dead_or_noop": "dead_or_noop",
    "generated_or_test_only": "generated_or_test_only",
    "infrastructure_or_out_of_product": "infrastructure_or_out_of_product",
}
page_labels = {
    "accepted_new_exact": "accepted_new_exact_target",
    "existing_exact": "existing_exact_target",
    "support_only": "support_only",
    "resolver_orphan": "resolver_orphan",
    "dead_or_noop": "dead_or_noop",
    "generated_or_test_only": "generated_or_test_only",
    "infrastructure_or_out_of_product": "infrastructure_or_out_of_product",
}
checksum_lines = [EXPECTED_COMMIT, "denominator|902"]
checksum_lines.extend(f"accepted|{target_id}" for target_id in accepted_new)
checksum_lines.extend(f"{route_id}|{route_labels[group]}" for route_id, group in sorted(route_seen.items()))
checksum_lines.extend(f"{page_id}|{page_labels[group]}" for page_id, group in sorted(page_seen.items()))
payload["checksums"]["coverage_sha256"] = lines_sha(checksum_lines)
payload["checksums"]["coverage_recipe"] = (
    "audited commit, denominator, sorted accepted IDs since 894, then sorted "
    "ROUTE-ID|disposition and PAGE-ID|disposition; LF; no terminal LF; UTF-8 SHA-256"
)
payload["validation"].update({
    "manifest_target_count": 902,
    "manifest_class_counts": {"H": 788, "D": 111, "M": 3},
    "manifest_id_provenance": {"exact_current": 881, "source_stable": 5, "audit_assigned": 16},
    "new_machine_target_has_no_route_or_page": True,
    "inventory_route_surface_preserved": "2985 accepted + 39 excluded = 3024",
    "inventory_page_surface_preserved": "945 accepted + 17 excluded = 962",
    "accepted_feature_id_completion_gate": "blocked",
})

OUTPUT_PATH.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
output_sha = sha_file(OUTPUT_PATH)
summary = {
    "schema_version": "1.0",
    "artifact": "route-page-gap-reconciliation-902-generation-summary",
    "audited_commit": EXPECTED_COMMIT,
    "status": "complete_static_surface_refresh_runtime_and_completion_blocked",
    "audit_boundary": "Audit artifacts only; no application code, configuration, routes, pages, tests, browser state, data, deployment, or Git history changed.",
    "inputs": {
        "gap_901_sha256": EXPECTED_BASE_GAP_SHA,
        "manifest_902_sha256": EXPECTED_MANIFEST_SHA,
    },
    "counts": {
        "accepted_feature_ids": 902,
        "H": 788,
        "D": 111,
        "M": 3,
        "route_inventory": 3024,
        "accepted_routes": 2985,
        "excluded_surface_routes": 39,
        "page_inventory": 962,
        "accepted_pages": 945,
        "excluded_surface_pages": 17,
    },
    "outputs": {
        "gap_reconciliation": OUTPUT_PATH.name,
        "gap_reconciliation_sha256": output_sha,
        "coverage_sha256": payload["checksums"]["coverage_sha256"],
    },
    "completion_gate": {
        "complete": False,
        "reason": payload["accepted_feature_id_gate"]["reason"],
    },
}
SUMMARY_PATH.write_text(json.dumps(summary, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

print(json.dumps({
    "output": str(OUTPUT_PATH),
    "output_sha256": output_sha,
    "coverage_sha256": payload["checksums"]["coverage_sha256"],
    "summary_sha256": sha_file(SUMMARY_PATH),
    "routes": len(route_seen),
    "pages": len(page_seen),
}, indent=2))
