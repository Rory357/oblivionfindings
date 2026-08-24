#!/usr/bin/env python3
"""Apply the independently reviewed ROUTE-0098/ROUTE-0821 static disposition correction."""

from __future__ import annotations

import copy
import csv
import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"
TASKS = AUDIT / "task-scripts" / "final-904"
AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
CURRENT_MAIN = "20ad5cef0aacb3d055e685d2f8b7b583cb8d78f4"
GENERATED_AT = "2026-08-21T23:35:00+12:00"

POINTER = SOURCE / "canonical-audit-inputs.json"
MANIFEST = SOURCE / "working-capability-manifest-904.json"
GAP = SOURCE / "route-page-gap-reconciliation-904.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
TASK_SUMMARY = SOURCE / "final-904-task-script-generation-summary.json"
FINDINGS = AUDIT / "findings.json"
ADJUDICATION = SOURCE / "route-support-projection-correction-904.json"
SUMMARY = SOURCE / "final-904-route-support-projection-generation-summary.json"

TARGETS = [
    "CAP-SITE-DIETARY-TAG-LIFECYCLE",
    "CAP-SITE-PRODUCT-LIFECYCLE",
    "CAP-SITE-RECIPE-LIFECYCLE",
]
TASK_FILES = {
    "CAP-SITE-DIETARY-TAG-LIFECYCLE": TASKS / "cap-site-dietary-tag-lifecycle.md",
    "CAP-SITE-PRODUCT-LIFECYCLE": TASKS / "cap-site-product-lifecycle.md",
    "CAP-SITE-RECIPE-LIFECYCLE": TASKS / "cap-site-recipe-lifecycle.md",
}

PRE = {
    POINTER: "4cfa80e83b2c146266c6cc2a7e07320baf0ec6afe059f21518d0e5a509740746",
    MANIFEST: "ffca48609deab9a8938105c857786594a9a5431c31efe329ef4288da6165358f",
    GAP: "1a237628080d225cd32cdfa173ea01737b6c9c32e51bbca183a8ec84a0b8d7be",
    INVENTORY: "80f0d84017fadd27a66256e633956cd44591b243591490c6dac4d4b81fa99f4c",
    LEDGER: "8bf2c90d0fba9c79a10f14aab773dcba17139e549d06732897a68d4d959df9d8",
    MATRIX: "9eb665837cd9f0a23018b2a78b765392e123d6d36fc5db05af3089c0e615e0db",
    TASK_SUMMARY: "90c8386810df9e406f799dc591dd2a1810e85bc32ae6432d650c1c297323f888",
    FINDINGS: "c3e72e15e079aaaaf3db7a5600349038532b971869a8b38e5daed2940929f584",
    TASK_FILES["CAP-SITE-DIETARY-TAG-LIFECYCLE"]: "85cabb985a7a7f558ad99252cbaf61453753c07f48b8b0cc0455af3bec72aad5",
    TASK_FILES["CAP-SITE-PRODUCT-LIFECYCLE"]: "d5dcefdb48b9143bd2f18d478e31da0161e20439659ef130f82cdf27b777ef34",
    TASK_FILES["CAP-SITE-RECIPE-LIFECYCLE"]: "700c838a1f829b780f264a2c567c87eb7af61e8d2adea143edc975ceed773951",
}

OLD_AMBIGUOUS = "SURFACE-ROUTE-0098-0124-1873-2499-2710-2986-UNRESOLVED-AMBIGUITY"
NEW_AMBIGUOUS = "SURFACE-ROUTE-0124-1873-2499-2710-2986-UNRESOLVED-AMBIGUITY"
OLD_NOOP = "SURFACE-ROUTE-0203-0205-0821-2933-2941-2942-DEAD-OR-NOOP"
NEW_NOOP = "SURFACE-ROUTE-0203-0205-2933-2941-2942-DEAD-OR-NOOP"
ROUTE_0821_SURFACE = "SURFACE-ROUTE-0821-REACHABLE-UI-ONLY-NONPERSISTENT-NOOP"
NOOP_REASON = (
    "Reachable authenticated UI; toggles and Save change transient client state only; "
    "no persistence request or backend owner exists."
)
SUPPORT_REASON = (
    "Shared read-only aggregate-count projection for recipe, product and dietary-tag libraries. "
    "It is nonexclusive support evidence only and awards no independent capability, lifecycle, "
    "Site-scope, browser, runtime or completion credit."
)


def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def sha_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8", newline="\n")


def rel(path: Path) -> str:
    return path.relative_to(AUDIT).as_posix()


def record(path: Path) -> dict[str, Any]:
    return {"path": rel(path), "sha256": sha(path), "bytes": path.stat().st_size}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def git_bytes(commit: str, path: str) -> bytes:
    return subprocess.check_output(["git", "show", f"{commit}:{path}"], cwd=AUDIT.parent.parent.parent)


def source_record(path: str, baseline_loci: list[str], main_loci: list[str]) -> dict[str, Any]:
    baseline = git_bytes(AUDITED_COMMIT, path)
    main = git_bytes(CURRENT_MAIN, path)
    return {
        "path": path,
        "audited_commit": AUDITED_COMMIT,
        "audited_sha256": sha_bytes(baseline),
        "audited_bytes": len(baseline),
        "audited_loci": baseline_loci,
        "current_main": CURRENT_MAIN,
        "current_main_sha256": sha_bytes(main),
        "current_main_bytes": len(main),
        "current_main_loci": main_loci,
        "semantic_relation": "byte_identical" if baseline == main else "source_drift_reviewed_same_bounded_semantics",
    }


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), list(reader)


def write_csv(path: Path, headers: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=headers, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def update_task_text(path: Path, target: str) -> None:
    text = path.read_text(encoding="utf-8")
    require("`ROUTE-0098`" not in text, f"Task already contains ROUTE-0098: {path}")
    route_line = next(line for line in text.splitlines() if line.startswith("- Route evidence:"))
    route_ids = [item.strip().strip("`") for item in route_line.split(":", 1)[1].split(",")]
    route_ids = sorted(route_ids + ["ROUTE-0098"])
    text = text.replace(route_line, "- Route evidence: " + ", ".join(f"`{item}`" for item in route_ids))

    name_line = next(line for line in text.splitlines() if line.startswith("- Route names:"))
    names = [item.strip().strip("`") for item in name_line.split(":", 1)[1].split(",")]
    names = sorted(names + ["catering.library-counts"])
    text = text.replace(name_line, "- Route names: " + ", ".join(f"`{item}`" for item in names))

    path_line = next(line for line in text.splitlines() if line.startswith("- Route paths:"))
    paths = [item.strip().strip("`") for item in path_line.split(":", 1)[1].split(",")]
    paths = sorted(set(paths + ["catering/library-counts"]))
    text = text.replace(path_line, "- Route paths: " + ", ".join(f"`{item}`" for item in paths))

    share_line = next(line for line in text.splitlines() if line.startswith("- Other accepted IDs sharing retained routes:"))
    others = [item for item in TARGETS if item != target]
    replacement = (
        "- Other accepted IDs sharing retained routes: `ROUTE-0098` is shared only with "
        + " and ".join(f"`{item}`" for item in others)
        + "; this support-only relation is not exclusive lifecycle ownership."
    )
    text = text.replace(share_line, replacement)
    anchor = next(line for line in text.splitlines() if line.startswith("- Backend anchors:"))
    text = text.replace(
        anchor,
        "- Shared support-only projection: `ROUTE-0098` / `catering.library-counts` reports aggregate counts only; "
        "it awards zero independent capability, lifecycle, Site-scope, browser, runtime or completion credit.\n" + anchor,
    )
    path.write_text(text, encoding="utf-8", newline="\n")


if SUMMARY.exists() and ADJUDICATION.exists():
    manifest = load(MANIFEST)
    rows = {row["working_key"]: row for row in manifest["targets"]}
    if all("ROUTE-0098" in rows[target]["route_ids"] for target in TARGETS):
        summary = load(SUMMARY)
        for item in summary["outputs"].values():
            path = AUDIT / item["path"]
            require(path.exists() and sha(path) == item["sha256"] and path.stat().st_size == item["bytes"], f"Post-state drift: {path}")
        post_pointer = load(POINTER)
        for key, path in (
            ("manifest", MANIFEST),
            ("route_page_reconciliation", GAP),
            ("inventory", INVENTORY),
            ("eight_pass_ledger", LEDGER),
            ("benchmark_matrix", MATRIX),
            ("task_generation_summary", TASK_SUMMARY),
            ("route_support_projection_correction", ADJUDICATION),
            ("route_support_projection_generation_summary", SUMMARY),
        ):
            item = post_pointer["artifacts"][key]
            require(
                item == record(path),
                f"Pointer record drift for {key}: expected {record(path)}, got {item}",
            )
        print(json.dumps({"status": "already_applied", **summary["outputs"]}, indent=2))
        raise SystemExit(0)

for path, expected in PRE.items():
    require(sha(path) == expected, f"Input SHA drift: {path}; expected {expected}, got {sha(path)}")
require(subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=AUDIT.parent.parent.parent, text=True).strip() == AUDITED_COMMIT, "Audited HEAD drift")
require(subprocess.check_output(["git", "rev-parse", "origin/main"], cwd=AUDIT.parent.parent.parent, text=True).strip() == CURRENT_MAIN, "Current origin/main drift")

manifest = load(MANIFEST)
gap = load(GAP)
inventory = load(INVENTORY)
task_summary = load(TASK_SUMMARY)
pointer = load(POINTER)

# Manifest: add one shared supporting route relation to three existing targets.
manifest_rows = {row["working_key"]: row for row in manifest["targets"]}
for target in TARGETS:
    require(target in manifest_rows, f"Missing target {target}")
    require("ROUTE-0098" not in manifest_rows[target]["route_ids"], f"Unexpected pre-link {target}")
    manifest_rows[target]["route_ids"] = sorted(manifest_rows[target]["route_ids"] + ["ROUTE-0098"])
manifest["counts"].update({
    "route_relations": 3076,
    "unique_primary_route_ids": 2994,
    "route_ids_classified_outside_accepted_targets": 30,
    "shared_route_ids": 31,
})
manifest["generated_at"] = max(str(manifest.get("generated_at", "")), GENERATED_AT)
manifest["transformations"].append({
    "stage": "route_0098_shared_support_projection_correction",
    "accepted_capability_delta": 0,
    "route_relation_delta": 3,
    "unique_accepted_route_delta": 1,
    "excluded_route_delta": -1,
    "runtime_claim": False,
})
manifest["adjudication_inputs"] = sorted(set(manifest.get("adjudication_inputs", [])) | {ADJUDICATION.name})
manifest["route_support_projection_correction"] = {
    "route_id": "ROUTE-0098",
    "target_ids": TARGETS,
    "relation_scope": "shared_supporting_projection_nonexclusive",
    "reason": SUPPORT_REASON,
    "runtime_credit": 0,
}
write_json(MANIFEST, manifest)

# Gap: split the two old grouped exclusions and register ROUTE-0098 as support-only.
ambiguous = gap["routes"]["unresolved_ambiguity"]
require(len(ambiguous) == 1 and "ROUTE-0098" in ambiguous[0]["route_ids"], "ROUTE-0098 ambiguity precondition failed")
ambiguous[0]["route_ids"].remove("ROUTE-0098")
ambiguous[0]["excluded_disposition_id"] = NEW_AMBIGUOUS

noop = gap["routes"]["dead_or_noop"]
old_noop_row = next(row for row in noop if "ROUTE-0821" in row["route_ids"])
old_noop_row["route_ids"].remove("ROUTE-0821")
old_noop_row["excluded_disposition_id"] = NEW_NOOP
noop.append({
    "route_ids": ["ROUTE-0821"],
    "excluded_disposition_id": ROUTE_0821_SURFACE,
    "reason": NOOP_REASON,
})
noop.sort(key=lambda row: row["route_ids"][0])

route_groups: dict[str, Any] = {}
for key, rows in gap["routes"].items():
    route_groups[key] = rows
    if key == "existing_exact":
        route_groups["support_only"] = [{
            "route_ids": ["ROUTE-0098"],
            "target_ids": TARGETS,
            "relation_scope": "shared_supporting_projection_nonexclusive",
            "reason": SUPPORT_REASON,
            "independent_capability_credit": False,
            "runtime_credit": False,
        }]
gap["routes"] = route_groups
gap["legend"]["route_support_only"] = "support_only"
page_noop = next(row for row in gap["pages"]["dead_or_noop"] if "PAGE-0252" in row["page_ids"])
page_noop["reason"] = NOOP_REASON
gap["counts"]["routes"].update({"support_only": 1, "unresolved_ambiguity": 5, "dead_or_noop": 6})
gap["counts"]["route_target_relations"] = 207
gap["normalization_note"] += " ROUTE-0098 is now a shared, nonexclusive support-only count projection for three accepted Site library lifecycles; ROUTE-0821/PAGE-0252 remain excluded as reachable UI-only nonpersistent no-op surfaces."
gap["generated_at"] = max(str(gap.get("generated_at", "")), GENERATED_AT)
for item in gap["inputs"]:
    if item["file"] == MANIFEST.name:
        item["sha256"] = sha(MANIFEST)

route_seen: dict[str, str] = {}
page_seen: dict[str, str] = {}
linked_targets: set[str] = set()
for group, rows in gap["routes"].items():
    for row in rows:
        linked_targets.update(row.get("target_ids", []))
        for route_id in row.get("route_ids", []):
            require(route_id not in route_seen, f"Duplicate route {route_id}")
            route_seen[route_id] = group
for group, rows in gap["pages"].items():
    for row in rows:
        linked_targets.update(row.get("target_ids", []))
        for page_id in row.get("page_ids", []):
            require(page_id not in page_seen, f"Duplicate page {page_id}")
            page_seen[page_id] = group
require(len(route_seen) == 197 and len(page_seen) == 63, "Gap corpus coverage drift")
require(linked_targets <= set(manifest_rows), "Gap target outside manifest")
route_labels = {
    "accepted_new_exact": "accepted_new_exact_target",
    "existing_exact": "existing_exact_target",
    "support_only": "support_only",
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
coverage_lines = [AUDITED_COMMIT, "denominator|904"]
coverage_lines.extend(f"accepted|{item}" for item in gap["denominator"]["accepted_new_target_ids"])
coverage_lines.extend(f"{route_id}|{route_labels[group]}" for route_id, group in sorted(route_seen.items()))
coverage_lines.extend(f"{page_id}|{page_labels[group]}" for page_id, group in sorted(page_seen.items()))
gap["checksums"]["coverage_sha256"] = sha_bytes("\n".join(coverage_lines).encode())
gap["validation"].update({
    "inventory_route_surface_preserved": "2994 accepted + 30 excluded = 3024",
    "route_0098_support_only_targets": TARGETS,
    "route_0098_independent_capability_credit": False,
    "route_0821_reachable_ui_only_nonpersistent_noop": True,
})
write_json(GAP, gap)

# Inventory feature and route dispositions.
features = {row["working_key"]: row for row in inventory["features"]}
for target in TARGETS:
    require("ROUTE-0098" not in features[target]["route_ids"], f"Inventory pre-link drift {target}")
    features[target]["route_ids"] = sorted(features[target]["route_ids"] + ["ROUTE-0098"])

routes = {row["route_id"]: row for row in inventory["routes"]}
for route_id in ["ROUTE-0124", "ROUTE-1873", "ROUTE-2499", "ROUTE-2710", "ROUTE-2986"]:
    row = routes[route_id]
    require(row["static_surface_disposition"]["surface_id"] == OLD_AMBIGUOUS, f"Ambiguity precondition {route_id}")
    row["excluded_surface_disposition_ids"] = [NEW_AMBIGUOUS]
    row["static_disposition_ids"] = [NEW_AMBIGUOUS]
    row["static_surface_disposition"]["surface_id"] = NEW_AMBIGUOUS
for route_id in ["ROUTE-0203", "ROUTE-0205", "ROUTE-2933", "ROUTE-2941", "ROUTE-2942"]:
    row = routes[route_id]
    require(row["static_surface_disposition"]["surface_id"] == OLD_NOOP, f"No-op precondition {route_id}")
    row["excluded_surface_disposition_ids"] = [NEW_NOOP]
    row["static_disposition_ids"] = [NEW_NOOP]
    row["static_surface_disposition"]["surface_id"] = NEW_NOOP

route0098 = routes["ROUTE-0098"]
require(route0098["static_surface_disposition"]["surface_id"] == OLD_AMBIGUOUS, "ROUTE-0098 precondition failed")
route0098.update({
    "working_canonical_feature_ids": TARGETS,
    "working_canonical_feature_link_status": "target_supported_shared_supporting_projection_nonexclusive",
    "projection_feature_link_status": "superseded_projection_evidence_only",
    "excluded_surface_disposition_ids": [],
    "static_disposition_ids": TARGETS,
    "static_disposition_kind": "accepted_capability_target_supporting_projection",
    "static_surface_disposition": None,
    "route_capability_relation": "shared_supporting_projection_nonexclusive",
    "independent_capability_credit": False,
    "runtime_credit": False,
    "relation_reason": SUPPORT_REASON,
})

route0821 = routes["ROUTE-0821"]
require(route0821["static_surface_disposition"]["surface_id"] == OLD_NOOP, "ROUTE-0821 precondition failed")
route0821["excluded_surface_disposition_ids"] = [ROUTE_0821_SURFACE]
route0821["static_disposition_ids"] = [ROUTE_0821_SURFACE]
route0821["static_surface_disposition"].update({
    "surface_id": ROUTE_0821_SURFACE,
    "user_facing": True,
    "reason": NOOP_REASON,
})
route0821["reachability_status"] = "reachable_authenticated_ui"
route0821["persistence_status"] = "client_state_only_nonpersistent_noop"

page0252 = next(row for row in inventory["pages"] if row["page_id"] == "PAGE-0252")
page0252["static_surface_disposition"].update({"user_facing": True, "reason": NOOP_REASON})
page0252["reachability_status"] = "reachable_authenticated_ui"
page0252["persistence_status"] = "client_state_only_nonpersistent_noop"

inventory["denominators"].update({
    "canonical_route_relations_enriched": 3076,
    "canonical_unique_routes_enriched": 2994,
    "canonical_route_mapping_percent": round(2994 / 3024 * 100, 2),
})
inventory["capability_denominator_status"]["route_enrichment"].update({"relations": 3076, "unique_routes": 2994})
inventory["capability_denominator_status"]["source_route_classification"] = "3024/3024 classified; 2994/3024 map to accepted 904 targets and 30 retain excluded SURFACE dispositions"
inventory["capability_denominator_status"]["inventory_route_mapping"].update({
    "completed": 2994, "percent": round(2994 / 3024 * 100, 2)
})
inventory["canonical_feature_register_metadata"]["route_enrichment"].update({
    "relations": 3076,
    "unique_routes": 2994,
    "excluded_surface_routes": 30,
    "shared_route_ids": 31,
})
register = inventory["excluded_surface_disposition_register"]
route_surface_ids = [item for item in register["route_surface_ids"] if item not in {OLD_AMBIGUOUS, OLD_NOOP}]
route_surface_ids.extend([NEW_AMBIGUOUS, NEW_NOOP, ROUTE_0821_SURFACE])
register["route_surface_ids"] = sorted(route_surface_ids)
register["route_relations"] = 30
inventory["generated_at"] = max(str(inventory.get("generated_at", "")), GENERATED_AT)
inventory["denominator_corrections"].append({
    "stage": "route_0098_support_projection_and_route_0821_noop_precision",
    "capability_delta": 0,
    "route_relation_delta": 3,
    "unique_accepted_route_delta": 1,
    "excluded_route_delta": -1,
    "runtime_credit": 0,
})
inventory["capability_denominator_status"]["working_manifest_sha256"] = sha(MANIFEST)
inventory["canonical_feature_register_metadata"]["source_artifacts"].update({
    "manifest_sha256": sha(MANIFEST),
    "route_page_gap_reconciliation_sha256": sha(GAP),
})
write_json(INVENTORY, inventory)

# Update the three affected ledger and benchmark-matrix rows without changing any benchmark outcome.
ledger_headers, ledger_rows = read_csv(LEDGER)
matrix_headers, matrix_rows = read_csv(MATRIX)
for row in ledger_rows:
    target = row["feature_id"]
    if target not in TARGETS:
        continue
    old_count = len(manifest_rows[target]["route_ids"]) - 1
    new_count = old_count + 1
    row["P1_status"] = row["P1_status"].replace(
        f"routes={old_count},",
        f"routes={new_count} ({old_count} lifecycle + 1 shared support-only count projection),",
    )
    row["P5_status"] += " ROUTE-0098 is nonexclusive count-only support and awards no independent lifecycle or runtime credit."
    row["evidence_count"] = str(int(row["evidence_count"]) + 1)
for row in matrix_rows:
    target = row["feature_id"]
    if target not in TARGETS:
        continue
    old_count = len(manifest_rows[target]["route_ids"]) - 1
    new_count = old_count + 1
    names = sorted(set(filter(None, row["route_names"].split("; "))) | {"catering.library-counts"})
    paths = sorted(set(filter(None, row["route_paths"].split("; "))) | {"GET|HEAD catering/library-counts [ROUTE-0098]"})
    row["route_names"] = "; ".join(names)
    row["route_paths"] = "; ".join(paths)
    row["current_workflow_summary"] = row["current_workflow_summary"].replace(
        f"routes={old_count},",
        f"routes={new_count} ({old_count} lifecycle + 1 shared support-only count projection),",
    ) + " ROUTE-0098 exposes aggregate library counts only and is not independent lifecycle, Site-scope or runtime proof."
    row["P1"] = row["P1"].replace(
        f"routes={old_count},",
        f"routes={new_count} ({old_count} lifecycle + 1 shared support-only count projection),",
    )
    row["P5"] += " ROUTE-0098 is nonexclusive count-only support and awards no independent lifecycle or runtime credit."
write_csv(LEDGER, ledger_headers, ledger_rows)
write_csv(MATRIX, matrix_headers, matrix_rows)

for target, path in TASK_FILES.items():
    update_task_text(path, target)

# Refresh task summary pins and the three changed script hashes.
task_summary["generated_at"] = max(str(task_summary.get("generated_at", "")), GENERATED_AT)
task_summary["inputs"]["manifest"] = record(MANIFEST)
task_summary["inputs"]["inventory"] = record(INVENTORY)
task_summary["inputs"]["findings"] = record(FINDINGS)
task_summary["proof_boundary"]["route_0098_support_projection"] = (
    "One shared count projection is linked to three existing Site library targets; no task, lifecycle, runtime, Site-scope or completion credit."
)
scripts = {row["feature_id"]: row for row in task_summary["scripts"]}
for target, path in TASK_FILES.items():
    scripts[target]["sha256"] = sha(path)
script_lines = [f"{row['feature_id']}|{row['file']}|{row['sha256']}" for row in task_summary["scripts"]]
task_summary["outputs"]["script_index_sha256"] = sha_bytes("\n".join(script_lines).encode())
write_json(TASK_SUMMARY, task_summary)

source_registry = [
    source_record("routes/catering.php", ["L25-L27"], ["L25-L27"]),
    source_record("app/Http/Controllers/Catering/DashboardController.php", ["L18-L24"], ["L18-L24"]),
    source_record("resources/js/pages/catering/_tabs.tsx", ["L72-L79"], ["L87-L100"]),
    source_record("routes/fleet-assets.php", ["L96-L99"], ["L96-L99"]),
    source_record("resources/js/pages/fleet-assets/settings/notifications.tsx", ["L168-L172", "L276-L286"], ["L188-L192", "L360-L371"]),
]
adjudication = {
    "schema_version": "1.0.0",
    "artifact": "route-support-projection-correction-904",
    "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT,
    "current_main": CURRENT_MAIN,
    "status": "accepted_static_ownership_precision_runtime_unverified",
    "inputs": {
        "pre_manifest": {"sha256": PRE[MANIFEST]},
        "pre_gap": {"sha256": PRE[GAP]},
        "pre_inventory": {"sha256": PRE[INVENTORY]},
    },
    "decisions": [
        {
            "route_id": "ROUTE-0098",
            "disposition": "shared_supporting_projection_nonexclusive",
            "target_ids": TARGETS,
            "reason": SUPPORT_REASON,
            "capability_delta": 0,
            "runtime_credit": 0,
        },
        {
            "route_id": "ROUTE-0821",
            "page_id": "PAGE-0252",
            "disposition": "reachable_ui_only_nonpersistent_noop",
            "reason": NOOP_REASON,
            "capability_credit": 0,
            "runtime_credit": 0,
        },
    ],
    "source_registry": source_registry,
    "count_delta": {
        "capabilities": 0,
        "route_relations": 3,
        "unique_accepted_routes": 1,
        "excluded_routes": -1,
        "shared_route_ids": 1,
        "pages": 0,
        "backend_anchors": 0,
        "benchmark": 0,
        "runtime": 0,
        "completion": 0,
    },
    "post_counts": {
        "capabilities": 904,
        "route_relations": 3076,
        "unique_accepted_routes": 2994,
        "excluded_routes": 30,
        "shared_route_ids": 31,
        "route_inventory": 3024,
        "route_mapping_percent": round(2994 / 3024 * 100, 2),
        "accepted_pages": 945,
        "excluded_pages": 17,
    },
    "claim_limit": "Static route ownership/disposition precision only. No product, runtime, browser, task, benchmark, release or completion credit.",
}
write_json(ADJUDICATION, adjudication)

changed_outputs = {
    "adjudication": record(ADJUDICATION),
    "manifest": record(MANIFEST),
    "gap": record(GAP),
    "inventory": record(INVENTORY),
    "ledger": record(LEDGER),
    "matrix": record(MATRIX),
    "task_summary": record(TASK_SUMMARY),
}
for target, path in TASK_FILES.items():
    changed_outputs[f"task_{target.lower().replace('-', '_')}"] = record(path)
summary = {
    "schema_version": "1.0.0",
    "artifact": "final-904-route-support-projection-generation-summary",
    "generated_at": GENERATED_AT,
    "status": "applied_idempotent_static_only",
    "generator": {"path": rel(GENERATOR), "sha256": sha(GENERATOR), "bytes": GENERATOR.stat().st_size},
    "inputs": {rel(path): {"sha256": digest} for path, digest in PRE.items()},
    "outputs": changed_outputs,
    "checks": {
        "route_0098_linked_to_exact_three_targets": True,
        "route_0098_support_only_nonexclusive": True,
        "route_0821_split_reason_exact": True,
        "page_0252_reason_exact": True,
        "route_inventory_partition_2994_plus_30_equals_3024": True,
        "capability_denominator_unchanged_904": True,
        "benchmark_counts_unchanged": True,
        "runtime_browser_task_completion_credit_delta_zero": True,
    },
    "required_downstream_regeneration": {
        "order": [
            "refresh-current-904-summaries.py",
            "refresh-audit-dashboard-data.py",
            "finalize-current-904-validation.py",
        ],
        "expected_static_counts": {
            "accepted_routes": 2994,
            "excluded_routes": 30,
            "route_relations": 3076,
            "combined_accepted_route_page": 3939,
            "combined_route_page_denominator": 3986,
        },
        "credit_boundary": "Regeneration reconciles static counts and hashes only; runtime, browser, task, benchmark and completion credit remain zero.",
    },
}
write_json(SUMMARY, summary)

pointer["generated_at"] = max(str(pointer.get("generated_at", "")), GENERATED_AT)
pointer["artifacts"].update({
    "manifest": record(MANIFEST),
    "route_page_reconciliation": record(GAP),
    "inventory": record(INVENTORY),
    "eight_pass_ledger": record(LEDGER),
    "benchmark_matrix": record(MATRIX),
    "task_generation_summary": record(TASK_SUMMARY),
    "route_support_projection_correction": record(ADJUDICATION),
    "route_support_projection_generation_summary": record(SUMMARY),
})
pointer["runtime_credit_delta"] = 0
write_json(POINTER, pointer)

# Final postconditions.
manifest = load(MANIFEST)
inventory = load(INVENTORY)
gap = load(GAP)
require(manifest["counts"]["total"] == 904 and manifest["counts"]["route_relations"] == 3076, "Manifest post-count mismatch")
require(len(routes["ROUTE-0098"]["working_canonical_feature_ids"]) == 3, "ROUTE-0098 post-link mismatch")
require(inventory["denominators"]["canonical_unique_routes_enriched"] == 2994, "Inventory route total mismatch")
require(gap["counts"]["routes"]["support_only"] == 1 and gap["counts"]["routes"]["unresolved_ambiguity"] == 5, "Gap count mismatch")
print(json.dumps({"status": "applied", **summary["outputs"]}, indent=2))
