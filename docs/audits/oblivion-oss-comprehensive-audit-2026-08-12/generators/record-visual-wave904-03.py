#!/usr/bin/env python3
"""Record independently reviewed visual Wave-904-03 zero-promotion evidence."""

from __future__ import annotations

import csv
import hashlib
import json
import re
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
ROOT = AUDIT.parent.parent.parent
SOURCE = AUDIT / "evidence" / "source"
GENERATED_AT = "2026-08-21T19:45:00+12:00"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"

MANIFEST = SOURCE / "working-capability-manifest-904.json"
INVENTORY = AUDIT / "inventory-904.json"
VISUAL = AUDIT / "05-browser-visual-coverage-matrix-904.csv"
POINTER = SOURCE / "canonical-audit-inputs.json"
ARTIFACT = SOURCE / "visual-final-id-adjudication-904-wave03.json"

INPUTS = {
    "manifest": (MANIFEST, "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f"),
    "inventory": (INVENTORY, "37cba2c22121ef641e425ba891e60757cc1a0b112ec9ec710ed71e317d673f6e"),
    "visual_matrix": (VISUAL, "707885a83264c8e2ab3f92898578a2b20ba3e8a2ccdb3ece17156d8c0774c293"),
}

WAVE1_EXCLUSION_SHA = "bbec2abef290aaaccce4960da49c9dac2bd0fde12be0d02590c73dbfb6140b41"
WAVE2_EXCLUSION_SHA = "3000af48cd790aebd2c33d7df5ea61e8edd151d2c5e7cfb2e1540b4522dea171"
COMBINED_EXCLUSION_SHA = "6bda5b208cbcb48d19806b89d7fbb381cc1b863fbd0ca2f8fee0d684f2fa9f62"
ORDERED_SELECTION_SHA = "c8e055cc898976aefb1197b4b04399a109ca00d592581eed778a5bde304d5c31"
SORTED_SELECTION_SHA = "53a320ff0ab8b8f5e005bea21dc91dc77ae98883e7ccde75f16136c50c411b87"
INDEPENDENT_REVIEW_PROOF_SHA = "a0f92c684cfc409697c56a2d31bbdb2bdcaf6c38b122402acb5dba7e298b2702"
SOURCE_PACKET_PROOF_SHA = "9c7888f895873d74a9a9ce22d999af58209426d93769592a2c4f0857580d0fa5"
SOURCE_MAP_SHA = "3d1a622611c7d803e4f9d09c98a41c6982b8072233d1a3fa04888582239af824"

SELECTED_IDS = [
    "VIS-020392", "VIS-020396", "VIS-021250", "VIS-021337", "VIS-021371",
    "VIS-014175", "VIS-014197", "VIS-014226", "VIS-014244", "VIS-014311",
    "VIS-015470", "VIS-015477", "VIS-015823", "VIS-016174", "VIS-017496",
    "VIS-017580", "VIS-017933", "VIS-018634", "VIS-018714", "VIS-018990",
    "VIS-018992", "VIS-019291", "VIS-019328", "VIS-019329", "VIS-019389",
]

PAGE_BY_VISUAL = {
    "VIS-014175": "PAGE-0394", "VIS-014197": "PAGE-0385",
    "VIS-014226": "PAGE-0393", "VIS-014244": "PAGE-0893",
    "VIS-014311": "PAGE-0526", "VIS-015470": "PAGE-0159",
    "VIS-015477": "PAGE-0159", "VIS-015823": "PAGE-0231",
    "VIS-016174": "PAGE-0415", "VIS-017496": "PAGE-0838",
    "VIS-017580": "PAGE-0851", "VIS-017933": "PAGE-0048",
    "VIS-018634": "PAGE-0526", "VIS-018714": "PAGE-0095",
    "VIS-018990": "PAGE-0831", "VIS-018992": "PAGE-0831",
    "VIS-019291": "PAGE-0961", "VIS-019328": "PAGE-0277",
    "VIS-019329": "PAGE-0277", "VIS-019389": "PAGE-0054",
}


def sha_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_lines(lines: list[str]) -> str:
    return hashlib.sha256("\n".join(lines).encode("utf-8")).hexdigest()


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def rel(path: Path) -> str:
    return path.relative_to(AUDIT).as_posix()


def record(path: Path) -> dict[str, Any]:
    return {"path": rel(path), "sha256": sha_file(path), "bytes": path.stat().st_size}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


require(sha_lines(SELECTED_IDS) == ORDERED_SELECTION_SHA, "Ordered selection digest drift")
require(sha_lines(sorted(SELECTED_IDS)) == SORTED_SELECTION_SHA, "Sorted selection digest drift")

if ARTIFACT.exists():
    artifact = load(ARTIFACT)
    require(artifact["selection"]["ordered_sha256"] == ORDERED_SELECTION_SHA, "Existing artifact selection drift")
    pointer = load(POINTER)
    require(pointer["artifacts"]["visual_wave904_03"] == record(ARTIFACT), "Existing pointer entry drift")
    print(json.dumps({"status": "already_recorded", "artifact": record(ARTIFACT)}, indent=2))
    raise SystemExit(0)

for name, (path, expected) in INPUTS.items():
    require(sha_file(path) == expected, f"{name} input SHA drift")

manifest = load(MANIFEST)
inventory = load(INVENTORY)
require(manifest["audited_commit"] == AUDITED_COMMIT, "Manifest audited commit drift")
require(inventory["commit"] == AUDITED_COMMIT, "Inventory audited commit drift")

with VISUAL.open(encoding="utf-8-sig", newline="") as handle:
    visual_rows = list(csv.DictReader(handle))
by_visual = {row["visual_id"]: row for row in visual_rows}
require(len(by_visual) == 8753, "Visual denominator drift")

routes_by_name_path: dict[tuple[str, str], list[dict[str, Any]]] = {}
for route in inventory["routes"]:
    routes_by_name_path.setdefault((route["name"], route["uri"]), []).append(route)
pages = {row["page_id"]: row for row in inventory["pages"]}

adjudications: list[dict[str, Any]] = []
for visual_id in SELECTED_IDS:
    require(visual_id in by_visual, f"Missing visual row: {visual_id}")
    row = by_visual[visual_id]
    require(not row["feature_id"], f"Visual row already assigned: {visual_id}")
    require(row["pattern_type"] == "material-state-applicability", f"Not material state: {visual_id}")
    require(row["feature_link_status"].startswith("unresolved"), f"Visual status drift: {visual_id}")

    names = [part.strip() for part in row["route_name"].split(" | ") if part.strip()]
    paths = [part.strip() for part in row["route_path"].split(" | ") if part.strip()]
    require(len(names) == len(paths) == 2, f"Expected two exact routes: {visual_id}")
    matched: list[dict[str, Any]] = []
    owners: list[str] = []
    controller_paths: list[str] = []
    methods: list[str] = []
    for name, path in zip(names, paths, strict=True):
        candidates = routes_by_name_path.get((name, path), [])
        require(len(candidates) == 1, f"Route name/path not singleton: {visual_id} {name}")
        route = candidates[0]
        route_owners = route["working_canonical_feature_ids"]
        require(len(route_owners) == 1, f"Route owner not singleton: {route['route_id']}")
        owners.append(route_owners[0])
        action = route["action"]
        require("@" in action, f"Controller method missing: {route['route_id']}")
        controller, method = action.split("@", 1)
        controller_path = controller.replace("\\", "/").replace("App/", "app/") + ".php"
        controller_paths.append(controller_path)
        methods.append(method)
        matched.append({
            "route_id": route["route_id"], "name": name, "path": path,
            "method": route["method"], "controller": controller_path,
            "controller_method": method, "owner": route_owners[0],
        })
    require(len(set(owners)) == 2, f"Owners do not differ: {visual_id}")
    require(len(set(controller_paths)) == 1, f"Controllers differ: {visual_id}")
    if row["component_anchor"].endswith(".php"):
        require(controller_paths[0] == row["component_anchor"], f"Component/controller mismatch: {visual_id}")
    controller_source = (ROOT / controller_paths[0]).read_text(encoding="utf-8")
    for method in methods:
        require(re.search(rf"\bfunction\s+{re.escape(method)}\s*\(", controller_source) is not None,
                f"Controller method not found: {visual_id} {method}")

    page_id = PAGE_BY_VISUAL.get(visual_id)
    page_owners: list[str] = []
    if page_id:
        require(page_id in pages, f"Missing page: {page_id}")
        page_owners = sorted(set(pages[page_id]["working_canonical_feature_ids"]))
        require(len(page_owners) != 1, f"Unexpected singleton page fallback: {visual_id}")

    adjudications.append({
        "visual_id": visual_id,
        "verdict": "RETAIN_UNRESOLVED",
        "reason": "Two exact actions have different singleton final owners; their intersection is empty and no singleton page fallback exists.",
        "routes": matched,
        "owner_intersection": [],
        "owner_union": sorted(set(owners)),
        "page_id": page_id,
        "page_owners": page_owners,
        "runtime_credit": 0,
    })

assigned = sum(bool(row["feature_id"]) for row in visual_rows)
material = [row for row in visual_rows if row["pattern_type"] == "material-state-applicability"]
material_assigned = sum(bool(row["feature_id"]) for row in material)
unique_targets = len({row["feature_id"] for row in visual_rows if row["feature_id"]})
require((assigned, len(visual_rows) - assigned, material_assigned, len(material) - material_assigned, unique_targets)
        == (8168, 585, 3948, 364, 774), "Visual count drift")

artifact = {
    "schema_version": "1.0.0",
    "artifact": "visual-final-id-adjudication-904-wave03",
    "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT,
    "status": "independently_reviewed_zero_promotion",
    "audit_boundary": "Static final-ID ownership adjudication only. No browser, runtime, usability, screenshot, finding, benchmark or completion credit is inferred.",
    "inputs": {name: record(path) for name, (path, _) in INPUTS.items()},
    "selection": {
        "rule": "Exclude reviewed Waves 01 and 02; require exactly two exact name/path routes with distinct singleton owners, one action controller, both methods present; rank exact controller-anchor rows then remaining rows by visual_id; take 25.",
        "wave1_exclusion_sha256": WAVE1_EXCLUSION_SHA,
        "wave2_exclusion_sha256": WAVE2_EXCLUSION_SHA,
        "combined_exclusion_sha256": COMBINED_EXCLUSION_SHA,
        "ordered_ids": SELECTED_IDS,
        "ordered_sha256": ORDERED_SELECTION_SHA,
        "sorted_sha256": SORTED_SELECTION_SHA,
        "source_packet_proof_sha256": SOURCE_PACKET_PROOF_SHA,
        "independent_review_proof_sha256": INDEPENDENT_REVIEW_PROOF_SHA,
        "source_map_sha256": SOURCE_MAP_SHA,
    },
    "adjudications": adjudications,
    "count_delta": {"visual_assigned": 0, "visual_unresolved": 0, "material_assigned": 0, "material_unresolved": 0, "runtime_credit": 0},
    "post_counts": {"visual_assigned": 8168, "visual_rows": 8753, "visual_unresolved": 585,
                    "material_assigned": 3948, "material_rows": 4312, "material_unresolved": 364,
                    "unique_assigned_targets": 774},
}
write_json(ARTIFACT, artifact)

pointer = load(POINTER)
pointer["generated_at"] = max(pointer.get("generated_at", ""), GENERATED_AT)
pointer["artifacts"]["visual_wave904_03"] = record(ARTIFACT)
write_json(POINTER, pointer)

print(json.dumps({"status": "recorded", "artifact": record(ARTIFACT), "pointer": record(POINTER)}, indent=2))
