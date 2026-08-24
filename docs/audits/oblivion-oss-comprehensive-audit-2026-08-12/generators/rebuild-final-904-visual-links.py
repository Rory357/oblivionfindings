#!/usr/bin/env python3
"""Apply independently reviewed 904 final-ID visual ownership only.

The transform preserves every observation/classification/screenshot field and
changes only feature_id, working_feature_ids, feature_link_status, and
feature_link_evidence for fifteen rows whose complete exact route set has one
current 904 target intersection.  It grants no browser, runtime, material-state
execution, usability, or completion credit.
"""

from __future__ import annotations

import csv
import hashlib
import json
from collections import Counter
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"

COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-21T17:40:00+12:00"

BASE_MATRIX = AUDIT / "05-browser-visual-coverage-matrix.csv"
MANIFEST = SOURCE / "working-capability-manifest-904.json"
INVENTORY = AUDIT / "inventory-904.json"
GAP = SOURCE / "route-page-gap-reconciliation-904.json"
ACTIVE_INPUTS = SOURCE / "canonical-audit-inputs.json"

OUTPUT = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
ADJUDICATION = SOURCE / "visual-final-id-ownership-904-wave1.json"
SUMMARY = SOURCE / "final-904-visual-link-generation-summary.json"

EXPECTED = {
    BASE_MATRIX: "f0aed8a6cbc242651ef7cd702685f8c948af276b3830d4d5960ea6ece1e9f363",
    MANIFEST: "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    INVENTORY: "40cb6916140b5e88fa2049b1185285d9497e2011064cd04cdc53eafd48223035",
    GAP: "1a237628080d225cd32cdfa173ea01737b6c9c32e51bbca183a8ec84a0b8d7be",
}

PROMOTIONS = [
    ("VIS-001929", "CAP-MED-SHIFT-MEDICATION-SNAPSHOT-API", "safe_route_exact_route_owner_page_anchor_rejected", ["ROUTE-0381"]),
    ("VIS-002295", "CAP-OPS-TIMESHEET-MANAGER-REVIEW", "safe_route_exact_route_owner_page_anchor_rejected", ["ROUTE-2234"]),
    ("VIS-014306", "CAP-INC-INCIDENT-AUTHOR", "material_state_all_routes_exact_intersection", ["ROUTE-1838", "ROUTE-1840", "ROUTE-1854"]),
    ("VIS-018652", "CAP-INC-SAFEGUARDING-TRIAGE-OWNERSHIP", "material_state_all_routes_exact_intersection", ["ROUTE-2504", "ROUTE-2505", "ROUTE-2509", "ROUTE-2514", "ROUTE-2520", "ROUTE-2522", "ROUTE-2523"]),
    ("VIS-016171", "CAP-HR-BENEFITS-PLAN-ADMINISTRATION", "material_state_all_routes_exact_intersection", ["ROUTE-1322", "ROUTE-1325"]),
    ("VIS-016365", "CAP-HR-IMPORT-EXPORT-TEMPLATE", "material_state_all_routes_exact_intersection", ["ROUTE-1482", "ROUTE-1485"]),
    ("VIS-020352", "CAP-OPS-ROSTERING-PLANNING", "material_state_all_routes_exact_intersection", ["ROUTE-2143", "ROUTE-2144", "ROUTE-2145"]),
    ("VIS-020878", "CAP-SET-ROLE-ASSIGNMENTS", "material_state_all_routes_exact_intersection", ["ROUTE-2615", "ROUTE-2616", "ROUTE-2628"]),
    ("VIS-020880", "CAP-SET-ROLE-ASSIGNMENTS", "material_state_all_routes_exact_intersection", ["ROUTE-2615", "ROUTE-2616", "ROUTE-2629"]),
    ("VIS-017850", "CAP-SITE-SITE-VENDOR-LIFECYCLE", "material_state_all_routes_exact_intersection", ["ROUTE-2891", "ROUTE-2892"]),
    ("VIS-021129", "CAP-SITE-SITE-VENDOR-LIFECYCLE", "material_state_all_routes_exact_intersection", ["ROUTE-2889", "ROUTE-2890", "ROUTE-2891", "ROUTE-2892"]),
    ("VIS-021131", "CAP-SITE-SITE-VENDOR-LIFECYCLE", "material_state_all_routes_exact_intersection", ["ROUTE-2888", "ROUTE-2889", "ROUTE-2890", "ROUTE-2891", "ROUTE-2892"]),
    ("VIS-018685", "CAP-MED-CLIENT-MEDICAL-CONDITION-LIFECYCLE", "material_state_named_route_set_exact_ownership", ["ROUTE-0168", "ROUTE-2012"]),
    ("VIS-014384", "CAP-MED-CLIENT-MEDICAL-CONDITION-LIFECYCLE", "material_state_named_route_set_exact_ownership", ["ROUTE-0169", "ROUTE-2013"]),
    ("VIS-018689", "CAP-MED-CLIENT-MEDICAL-CONDITION-LIFECYCLE", "material_state_named_route_set_exact_ownership", ["ROUTE-0169", "ROUTE-2013"]),
]

RETAIN_25 = [
    "VIS-020351", "VIS-016731", "VIS-020354", "VIS-017469", "VIS-020877",
    "VIS-017476", "VIS-020883", "VIS-017492", "VIS-015792", "VIS-019707",
    "VIS-017025", "VIS-020565", "VIS-002153",
]
RETAIN_MEDICAL = ["VIS-014386", "VIS-018686", "VIS-018687", "VIS-018688"]


def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(values: list[str], *, sort: bool = True) -> str:
    rows = sorted(values) if sort else list(values)
    return hashlib.sha256("\n".join(rows).encode("utf-8")).hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def rel(path: Path) -> str:
    return path.relative_to(AUDIT).as_posix()


def file_record(path: Path) -> dict[str, Any]:
    return {"path": rel(path), "sha256": sha_file(path), "bytes": path.stat().st_size}


for path, expected in EXPECTED.items():
    require(sha_file(path) == expected, f"Input SHA drift: {path}")

with BASE_MATRIX.open("r", encoding="utf-8-sig", newline="") as handle:
    reader = csv.DictReader(handle)
    headers = list(reader.fieldnames or [])
    rows = [dict(row) for row in reader]

require(len(rows) == 8753, "Visual row denominator drift")
require(len({row["visual_id"] for row in rows}) == 8753, "Duplicate visual IDs")

manifest = load_json(MANIFEST)
inventory = load_json(INVENTORY)
target_ids = {row["working_key"] for row in manifest["targets"]}
route_by_id = {row["route_id"]: row for row in inventory["routes"]}
row_by_id = {row["visual_id"]: row for row in rows}

promotion_by_id = {visual_id: (target, status, route_ids) for visual_id, target, status, route_ids in PROMOTIONS}
require(len(promotion_by_id) == 15, "Promotion packet duplicate")
require(set(promotion_by_id) <= set(row_by_id), "Promotion references missing visual ID")
require(set(RETAIN_25 + RETAIN_MEDICAL) <= set(row_by_id), "Retain packet references missing visual ID")

proof_rows = []
for visual_id, (target, status, route_ids) in sorted(promotion_by_id.items()):
    require(target in target_ids, f"Target missing from 904 manifest: {target}")
    source_row = row_by_id[visual_id]
    require(not source_row["feature_id"], f"Visual already assigned: {visual_id}")
    require(source_row["feature_link_status"].startswith("unresolved_"), f"Unexpected prior status: {visual_id}")
    owner_sets = []
    for route_id in route_ids:
        route = route_by_id.get(route_id)
        require(route is not None, f"Missing route {route_id}")
        owners = set(route.get("working_canonical_feature_ids", []))
        require(owners, f"Route lacks accepted target owner: {route_id}")
        owner_sets.append(owners)
    intersection = set.intersection(*owner_sets)
    require(intersection == {target}, f"Route-owner intersection is not singleton {visual_id}: {intersection}")
    proof_rows.append({
        "visual_id": visual_id,
        "target": target,
        "status": status,
        "route_ids": route_ids,
        "route_owner_sets": [sorted(owners) for owners in owner_sets],
        "prior_status": source_row["feature_link_status"],
        "classification": source_row["classification"],
        "pattern_type": source_row["pattern_type"],
    })

for visual_id in RETAIN_25 + RETAIN_MEDICAL:
    require(not row_by_id[visual_id]["feature_id"], f"Retained row unexpectedly assigned: {visual_id}")
    require(row_by_id[visual_id]["feature_link_status"].startswith("unresolved_"), f"Retained row status drift: {visual_id}")

adjudication = {
    "schema_version": "1.0",
    "artifact": "visual-final-id-ownership-904-wave1",
    "generated_at": GENERATED_AT,
    "audited_commit": COMMIT,
    "status": "independently_reviewed_final_id_lineage_only_runtime_unchanged",
    "audit_boundary": "Audit visual-to-final-ID lineage only. No source, runtime, browser observation, screenshot, classification, data, test, usability or completion credit changed.",
    "inputs": {name: {"path": rel(path), "sha256": expected} for name, (path, expected) in {
        "base_visual_matrix": (BASE_MATRIX, EXPECTED[BASE_MATRIX]),
        "manifest_904": (MANIFEST, EXPECTED[MANIFEST]),
        "inventory_904": (INVENTORY, EXPECTED[INVENTORY]),
        "route_page_904": (GAP, EXPECTED[GAP]),
    }.items()},
    "reviews": {
        "existing_25_row_packet": {
            "verdict": "GO",
            "selected_ids_sha256": "976d40691e3157dc9b0b1985bbe48f8c8e275dca5fa4f2aa33fe53ec4d59ebd7",
            "promoted_ids_sha256": "75090f8c641e07c5ee0f4da951e14ea8500ecfe3dbf9cb36cfa717c269560516",
            "assignment_sha256": "ea63e35f9cc05d6e359949b219291754290f21094fd36a1bdf7333410a099d78",
            "proof_map_sha256": "c60642dbc3e535aa062ab87646dfb8af54559f0a7acadae46cb7bcc3d3780544",
            "retained_ids_sha256": "3bc77d5a2599cb413a65ecde395c2b0eb8eadda2716201942986ee94617c9222",
            "rejection_map_sha256": "41bfd6abbe3b0d393ab2f3dd891f3d88fce1f21e9646f233ce6b79b589f4c1ef",
        },
        "medical_904_packet": {
            "selected_ids_sha256": "e3faa527694426d753717f21455fd75275a0308404a62372f709d717f006b812",
            "promoted_ids_sha256": "edf3f6be3582e005c2b4b6d7366ec32b13ddaae9c462822767a29445b95db715",
            "assignment_sha256": "8a5ca513c92ddfee38b8e8c0316b4e1c6a55e2c13d4e4f6a48f83d8121683fba",
            "retained_ids_sha256": "3cf487eabc37428ed06032066f384e0d9ae0ca62611663056e07a0670c523619",
            "verdict": "GO_independent_replay_exact_single_owner_route_sets",
        },
    },
    "promotions": proof_rows,
    "retained_unresolved": {
        "existing_packet": RETAIN_25,
        "medical_two_owner": ["VIS-014386", "VIS-018686"],
        "medical_three_owner": ["VIS-018687", "VIS-018688"],
        "reason": "Retained rows have no unique all-route owner and require decomposition; no source-family/page inheritance is allowed.",
    },
    "claim_limit": "Final-ID lineage only; all classification/runtime/browser/screenshot fields are preserved byte-for-field.",
}
write_json(ADJUDICATION, adjudication)

for row in rows:
    promotion = promotion_by_id.get(row["visual_id"])
    if not promotion:
        continue
    target, status, route_ids = promotion
    row["feature_id"] = target
    row["working_feature_ids"] = target
    row["feature_link_status"] = status
    row["feature_link_evidence"] = (
        f"Independent 904 final-ID review: exact routes {','.join(route_ids)} have sole all-route target intersection {target}; "
        "page/source-family anchors are descriptive only and grant no inherited ownership or runtime credit."
    )

with OUTPUT.open("w", encoding="utf-8", newline="") as handle:
    writer = csv.DictWriter(handle, fieldnames=headers, extrasaction="raise", lineterminator="\n")
    writer.writeheader()
    writer.writerows(rows)

assigned = [row for row in rows if row["feature_id"]]
unresolved = [row for row in rows if not row["feature_id"]]
status_counts = Counter(row["feature_link_status"] for row in rows)
classification_counts = Counter(row["classification"] for row in rows)
pattern_counts = Counter(row["pattern_type"] for row in rows)
material_rows = [row for row in rows if row["pattern_type"] == "material-state-applicability"]
material_assigned = sum(bool(row["feature_id"]) for row in material_rows)
material_unresolved = len(material_rows) - material_assigned
require((len(assigned), len(unresolved)) == (8168, 585), "Post-wave visual count mismatch")
require(len({row["feature_id"] for row in assigned}) == 774, "Unique represented target count mismatch")
require((material_assigned, material_unresolved) == (3948, 364), "Material linkage mismatch")
require(classification_counts == Counter({"Blocked": 547, "Not safely reproducible": 4312, "Observed": 2503, "Source-inferred": 1391}), "Classification changed")

semantic_lines = [
    "\x1f".join(row[field] for field in (
        "visual_id", "legacy_feature_id", "feature_id", "working_feature_ids", "feature_link_status", "feature_link_evidence"
    ))
    for row in rows
]
summary = {
    "schema_version": "1.0",
    "artifact": "final-904-visual-link-generation-summary",
    "generated_at": GENERATED_AT,
    "audited_commit": COMMIT,
    "status": "partial_final_id_linkage_observations_preserved_runtime_coverage_unchanged",
    "audit_boundary": "Audit artifacts only; no application code, configuration, data, tests, browser state, deployment or Git history changed.",
    "inputs": {
        "visual_matrix_input_sha256": sha_file(BASE_MATRIX),
        "manifest_904_sha256": sha_file(MANIFEST),
        "inventory_904_sha256": sha_file(INVENTORY),
        "route_page_904_sha256": sha_file(GAP),
        "adjudication_sha256": sha_file(ADJUDICATION),
    },
    "counts": {
        "rows": len(rows),
        "unique_visual_ids": len({row["visual_id"] for row in rows}),
        "assigned_final_feature_id": len(assigned),
        "unresolved_final_feature_id": len(unresolved),
        "unique_assigned_final_feature_ids": len({row["feature_id"] for row in assigned}),
        "material_state_rows": len(material_rows),
        "material_state_assigned": material_assigned,
        "material_state_unresolved": material_unresolved,
        "status_counts": dict(sorted(status_counts.items())),
        "classification_counts": dict(sorted(classification_counts.items())),
        "pattern_type_counts": dict(sorted(pattern_counts.items())),
    },
    "outputs": {
        "matrix": rel(OUTPUT),
        "matrix_sha256": sha_file(OUTPUT),
        "semantic_tuple_sha256": sha_lines(semantic_lines, sort=False),
        "semantic_tuple_algorithm": "Current row order; UTF-8; LF/no trailing LF; visual_id US legacy_feature_id US feature_id US working_feature_ids US feature_link_status US feature_link_evidence",
    },
    "wave1": {
        "promoted": 15,
        "retained_unresolved_reviewed": 17,
        "unique_promoted_targets": len({target for _, target, _, _ in PROMOTIONS}),
        "assignment_sha256": sha_lines(["\x1f".join([visual_id, target, status]) for visual_id, target, status, _ in PROMOTIONS]),
        "proof_map_sha256": sha_lines([
            "\x1f".join([row["visual_id"], row["target"], row["status"], ";".join(row["route_ids"])])
            for row in proof_rows
        ]),
        "claim_limit": "Final-ID lineage only. No row classification, browser/runtime observation, screenshot, material execution, usability or completion credit changed.",
    },
    "completion_gate": {
        "complete": False,
        "reason": "585 visual rows and 364 material-state rows remain without an exact final ID; runtime visual requirements remain incomplete.",
    },
}
write_json(SUMMARY, summary)

active = load_json(ACTIVE_INPUTS)
active["generated_at"] = max(str(active.get("generated_at", "")), GENERATED_AT)
active["artifacts"]["visual_matrix"] = file_record(OUTPUT)
active["artifacts"]["visual_ownership_adjudication"] = file_record(ADJUDICATION)
active["artifacts"]["visual_generation_summary"] = file_record(SUMMARY)
active["visual_counts"] = {
    "assigned": 8168, "total": 8753, "unresolved": 585,
    "material_assigned": 3948, "material_total": 4312, "material_unresolved": 364,
    "runtime_credit_delta": 0,
}
write_json(ACTIVE_INPUTS, active)

print(json.dumps({
    "matrix": file_record(OUTPUT),
    "adjudication": file_record(ADJUDICATION),
    "summary": file_record(SUMMARY),
    "active_inputs": file_record(ACTIVE_INPUTS),
    "assigned": len(assigned),
    "unresolved": len(unresolved),
    "material_assigned": material_assigned,
    "material_unresolved": material_unresolved,
}, indent=2))
