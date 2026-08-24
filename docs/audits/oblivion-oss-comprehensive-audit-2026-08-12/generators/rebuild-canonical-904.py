#!/usr/bin/env python3
"""Build the versioned 904-capability audit successor.

This is an audit-artifact-only transform.  It freezes every 902 artifact,
registers the two independently reconciled medical capabilities omitted from
that denominator, moves only their eight mutation routes from excluded surface
to accepted exact ownership, and materialises versioned ledgers/task scripts.
It deliberately grants no benchmark, runtime, browser, test, usability, or
product-completion credit.
"""

from __future__ import annotations

import copy
import csv
import hashlib
import json
import shutil
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"
ROOT = AUDIT.parents[2]

COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-21T17:05:00+12:00"

BASE_MANIFEST = SOURCE / "working-capability-manifest-902.json"
BASE_BENCHMARK = SOURCE / "benchmark-final-902-mapping.json"
BASE_GAP = SOURCE / "route-page-gap-reconciliation-902.json"
BASE_DISTINCT = SOURCE / "full-distinct-capability-adjudication.json"
BASE_INVENTORY = AUDIT / "inventory.json"
BASE_FINDINGS = AUDIT / "findings.json"
BASE_LEDGER = AUDIT / "02-eight-pass-coverage-ledger.csv"
BASE_MATRIX = AUDIT / "03-feature-to-benchmark-matrix.csv"
BASE_SCORECARD = AUDIT / "04-workflow-usability-scorecard.csv"
BASE_VISUAL = AUDIT / "05-browser-visual-coverage-matrix.csv"
BASE_TASK_DIR = AUDIT / "task-scripts" / "final-902"

ADJUDICATION = SOURCE / "canonical-denominator-904-adjudication.json"
MANIFEST = SOURCE / "working-capability-manifest-904.json"
BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
GAP = SOURCE / "route-page-gap-reconciliation-904.json"
INVENTORY = AUDIT / "inventory-904.json"
LEDGER = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
SCORECARD = AUDIT / "04-workflow-usability-scorecard-904.csv"
TASK_DIR = AUDIT / "task-scripts" / "final-904"
TASK_SUMMARY = SOURCE / "final-904-task-script-generation-summary.json"
SUMMARY = SOURCE / "canonical-denominator-904-generation-summary.json"
ACTIVE_INPUTS = SOURCE / "canonical-audit-inputs.json"

EXPECTED_INPUTS = {
    BASE_MANIFEST: "ded38bc3672bf51cb48a02a576cc36ca83d01af6a982dbd19c118ff50edf59b9",
    BASE_BENCHMARK: "c2cb6ea0f584b8eef7c6e74cf6aca3cf580139fabdb66198ace43e02fddabe3c",
    BASE_GAP: "cefc4af1571d50ad17c155c083635d2bacf79828a78d1d68ffc2ee86242c49eb",
    BASE_DISTINCT: "edab091ca88a0338b6ea17787df339867399e82314794035dccc5049beeb3715",
    BASE_INVENTORY: "076015a57c9368cdc737d0e8139589ba5708d0fa6bcee1d6654c2e72b4b9889a",
    BASE_FINDINGS: "4aacd5e5e7587578d7f242f1ed789ede6fcdcb05892eee0ab2ad5a07f8bf5ec7",
    BASE_LEDGER: "b1082f6ebd02715b3a88123927b0189df87c70b356bf8928837eddf915ac79bf",
    BASE_MATRIX: "f16d04ab2d25f30caca370bcbbb2ba504f0dd2d211b7b552018dd3bf7ec3d608",
    BASE_SCORECARD: "db680bb3a4aa46de8124cf890debc5ccd4fe2647927bf5ad87be0683bb02e3af",
}

SOURCE_PINS = {
    ROOT / "routes" / "clients.php": "4b0b75d442291eb0fe6064be06c7d86283c7b37734dc807e412a9f4a4d6b04a6",
    ROOT / "routes" / "operations.php": "fa810e000f222e6e9e7e97325cfe9a2bb36665af0b6f1e380a901608d31c3d2b",
    ROOT / "app" / "Http" / "Controllers" / "ClientMedicalController.php": "86f02f0f405aebe49deedbdcd45c614f48867c442e085aa0dae0f820b531fa63",
    ROOT / "app" / "Models" / "ClientCondition.php": "fba28af92cc0376fb48406f79ad7b3e48cc7c2e2b6d9cbb93acfe69b3ab0aa67",
    ROOT / "app" / "Models" / "ClientMedicalProfile.php": "eacd11f310b03ee009c4966a3298762475852042f7c7c9cb638df0492e8d8b44",
    ROOT / "resources" / "js" / "pages" / "clients" / "medical.tsx": "008a36479b8b179dd2fbabf16ed99adbb23204b216cd51acef57b2a04820fda4",
    ROOT / "resources" / "js" / "pages" / "operations" / "clients" / "medical.tsx": "2ffabc35640315308eca5aeb351f9432c96213c3e8d2c3f429e144bf3d987b4a",
    ROOT / "tests" / "Feature" / "MedicationControllerTest.php": "5c79ca009b2bc5cb3de4b5e6afdf8b82cd92c4b2634f9b19ec3a58456f6cf583",
}

ADDITIONS = [
    {
        "working_key": "CAP-MED-CLIENT-MEDICAL-CONDITION-LIFECYCLE",
        "id": "CAP-MED-CLIENT-MEDICAL-CONDITION-LIFECYCLE",
        "candidate_id": "CAP-MED-CLIENT-MEDICAL-PROFILE-CONDITIONS",
        "id_status": "audit_assigned_stable_name",
        "class": "H",
        "canonical_module": "EMAR",
        "source_family_ids": ["MED-CLIENT-MEDICAL"],
        "route_ids": [
            "ROUTE-0168", "ROUTE-0169", "ROUTE-0170",
            "ROUTE-2012", "ROUTE-2013", "ROUTE-2014",
        ],
        "page_ids": [],
        "backend_anchors": ["app/Http/Controllers/ClientMedicalController.php"],
        "origin_files": [
            "canonical-denominator-904-adjudication.json",
            "full-distinct-capability-adjudication.json",
            "route-page-gap-reconciliation-902.json",
        ],
        "absorbed_occurrences": [],
        "title": "Client Medical Condition Lifecycle",
        "actor": "Authorised client-care practitioner",
        "actions": ["storeCondition", "updateCondition", "destroyCondition"],
        "proof": {
            "routes": ["routes/clients.php:124-141", "routes/operations.php:289-307"],
            "controller": [
                "app/Http/Controllers/ClientMedicalController.php:672-715",
                "app/Http/Controllers/ClientMedicalController.php:717-743",
                "app/Http/Controllers/ClientMedicalController.php:745-763",
            ],
            "ui": [
                "resources/js/pages/clients/medical.tsx:749,790",
                "resources/js/pages/operations/clients/medical.tsx:1832,1890",
            ],
            "tests": ["tests/Feature/MedicationControllerTest.php:1845-1954"],
            "aggregate": "ClientCondition collection",
        },
    },
    {
        "working_key": "CAP-MED-CLIENT-MEDICAL-PROFILE",
        "id": "CAP-MED-CLIENT-MEDICAL-PROFILE",
        "candidate_id": "CAP-MED-CLIENT-MEDICAL-PROFILE-CONDITIONS",
        "id_status": "audit_assigned_stable_name",
        "class": "H",
        "canonical_module": "EMAR",
        "source_family_ids": ["MED-CLIENT-MEDICAL"],
        "route_ids": ["ROUTE-0180", "ROUTE-2023"],
        "page_ids": [],
        "backend_anchors": ["app/Http/Controllers/ClientMedicalController.php"],
        "origin_files": [
            "canonical-denominator-904-adjudication.json",
            "full-distinct-capability-adjudication.json",
            "route-page-gap-reconciliation-902.json",
        ],
        "absorbed_occurrences": [],
        "title": "Client Medical Profile",
        "actor": "Authorised client-care practitioner",
        "actions": ["updateProfile"],
        "proof": {
            "routes": ["routes/clients.php:124-141", "routes/operations.php:289-307"],
            "controller": ["app/Http/Controllers/ClientMedicalController.php:89-125"],
            "ui": [
                "resources/js/pages/clients/medical.tsx:336",
                "resources/js/pages/operations/clients/medical.tsx:1241",
            ],
            "tests": ["tests/Feature/MedicationControllerTest.php:417-452"],
            "aggregate": "ClientMedicalProfile singleton",
        },
    },
]


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def sha_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha_file(path: Path) -> str:
    return sha_bytes(path.read_bytes())


def sha_lines(values: list[str], *, sort: bool = True, terminal_lf: bool = False) -> str:
    rows = sorted(values) if sort else list(values)
    body = "\n".join(rows) + ("\n" if terminal_lf else "")
    return sha_bytes(body.encode("utf-8"))


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def rel(path: Path) -> str:
    return path.relative_to(AUDIT).as_posix()


def file_record(path: Path) -> dict[str, Any]:
    return {"path": rel(path), "sha256": sha_file(path), "bytes": path.stat().st_size}


for input_path, expected in EXPECTED_INPUTS.items():
    require(sha_file(input_path) == expected, f"Input SHA drift: {input_path}")
for source_path, expected in SOURCE_PINS.items():
    require(sha_file(source_path) == expected, f"Audited source SHA drift: {source_path}")
require(len(list(BASE_TASK_DIR.glob("*.md"))) == 788, "Expected 788 frozen 902 task scripts")

manifest_902 = load(BASE_MANIFEST)
benchmark_902 = load(BASE_BENCHMARK)
gap_902 = load(BASE_GAP)
inventory_902 = load(BASE_INVENTORY)

require(manifest_902["audited_commit"] == benchmark_902["audited_commit"] == COMMIT, "Audited commit drift")
require(len(manifest_902["targets"]) == 902, "902 manifest row drift")
require(len(benchmark_902["targets"]) == 902, "902 benchmark row drift")
require(len(inventory_902["features"]) == 902, "902 inventory row drift")

# 1. Independent denominator adjudication.
adjudication = {
    "schema_version": "1.0",
    "artifact": "canonical-denominator-904-adjudication",
    "generated_at": GENERATED_AT,
    "audited_commit": COMMIT,
    "status": "independently_reconciled_static_denominator_runtime_and_completion_blocked",
    "audit_boundary": "Audit artifacts and audited source inspection only. No application code, runtime, browser, data, test, deployment, or Git-history mutation; no runtime or completion credit.",
    "inputs": [
        {"path": rel(path), "sha256": expected}
        for path, expected in EXPECTED_INPUTS.items()
    ] + [
        {"path": path.relative_to(ROOT).as_posix(), "sha256": expected, "kind": "audited_product_source"}
        for path, expected in SOURCE_PINS.items()
    ],
    "prior_denominator": {"total": 902, "H": 788, "D": 111, "M": 3},
    "accepted_denominator": {"total": 904, "H": 790, "D": 111, "M": 3},
    "accepted_additions": [
        {
            key: copy.deepcopy(row[key])
            for key in (
                "working_key", "id", "candidate_id", "id_status", "class",
                "canonical_module", "source_family_ids", "route_ids", "page_ids",
                "backend_anchors", "origin_files", "absorbed_occurrences", "proof",
            )
        }
        for row in ADDITIONS
    ],
    "decision": {
        "reason": "The 902 register omitted two already-distinct source-owned medical jobs: collection CRUD over ClientCondition and singleton upsert of ClientMedicalProfile. Shared permission and source family do not collapse different aggregates, state, actions, UI sections, and test oracles.",
        "legacy_combined_id": "CAP-MED-CLIENT-MEDICAL-PROFILE-CONDITIONS",
        "legacy_combined_disposition": "superseded source-family projection evidence only; never an accepted 904 target",
        "wrong_owner_rejected": "CAP-MED-MEDICATION-ORDER-LIFECYCLE",
        "wrong_owner_reason": "Medication-order lifecycle owns a different aggregate and cannot absorb condition/profile mutations.",
        "pages_retained_excluded": ["PAGE-0038", "PAGE-0590"],
        "page_reason": "ClientMedicalController::show redirects to the canonical eMAR medication entry rather than rendering either legacy medical.tsx page; mutation callers prove source ownership, not current page reachability.",
    },
    "projected_static_counts": {
        "benchmark_decided": 451,
        "benchmark_unproved": 453,
        "task_scripts_before_successor_generation": "788/790",
        "accepted_routes": 2993,
        "excluded_routes": 31,
        "route_inventory": 3024,
        "accepted_pages": 945,
        "excluded_pages": 17,
        "page_inventory": 962,
        "accepted_route_page_union": 3938,
        "route_page_inventory": 3986,
    },
    "completion_credit": False,
}
write_json(ADJUDICATION, adjudication)

# 2. Versioned 904 manifest.
targets = [copy.deepcopy(row) for row in manifest_902["targets"]]
existing_ids = {row["working_key"] for row in targets}
for addition in ADDITIONS:
    require(addition["working_key"] not in existing_ids, f"Duplicate addition {addition['working_key']}")
    targets.append({key: copy.deepcopy(value) for key, value in addition.items() if key not in {"title", "actor", "actions", "proof"}})
targets.sort(key=lambda row: row["working_key"])
require(len(targets) == len({row["working_key"] for row in targets}) == 904, "904 manifest identity mismatch")
require(all(row["working_key"] == row["id"] for row in targets), "working_key/id divergence")

class_counts = Counter(row["class"] for row in targets)
status_counts = Counter(row["id_status"] for row in targets)
require(class_counts == Counter({"H": 790, "D": 111, "M": 3}), "904 class mismatch")
require(status_counts == Counter({
    "exact": 881,
    "source_stable_existing_feature_id": 4,
    "source_stable_reclassified": 1,
    "audit_assigned_stable_name": 18,
}), "904 ID-provenance mismatch")

module_counts: dict[str, dict[str, int]] = defaultdict(lambda: {"total": 0, "H": 0, "D": 0, "M": 0})
for row in targets:
    module_counts[row["canonical_module"]]["total"] += 1
    module_counts[row["canonical_module"]][row["class"]] += 1
module_counts = dict(sorted(module_counts.items()))
require(module_counts["EMAR"] == {"total": 74, "H": 50, "D": 24, "M": 0}, "EMAR denominator mismatch")

counts = copy.deepcopy(manifest_902["counts"])
counts.update({
    "total": 904, "H": 790, "D": 111, "M": 3,
    "audit_assigned_stable_names": 18,
    "targets_with_route_ids": 903,
    "route_relations": 3073,
    "unique_primary_route_ids": 2993,
    "route_ids_classified_outside_accepted_targets": 31,
    "targets_with_backend_anchors": 731,
    "backend_relations": 830,
    "unique_backend_anchors": 469,
    "source_family_relations": 995,
})
canonical_lines = [f"{row['working_key']}|{row['class']}|{row['canonical_module']}|{row['id_status']}" for row in targets]
stable_ids = [row["working_key"] for row in targets]
source_stable_ids = [row["working_key"] for row in targets if row["id_status"].startswith("source_stable")]
audit_ids = [row["working_key"] for row in targets if row["id_status"] == "audit_assigned_stable_name"]

manifest_904 = copy.deepcopy(manifest_902)
manifest_904.update({
    "schema_version": "1.4",
    "artifact": "working-capability-manifest-904",
    "status": "working_static_manifest_904_identity_reconciled_not_completion_claim",
    "generated_at": GENERATED_AT,
    "counts": counts,
    "module_counts": module_counts,
    "checksums": {
        "working_targets_sha256": sha_lines(canonical_lines),
        "canonical_stable_target_ids_sha256": sha_lines(stable_ids),
        "source_stable_ids_sha256": sha_lines(source_stable_ids),
        "audit_assigned_stable_names_sha256": sha_lines(audit_ids),
        "method": "lexicographic sort; LF join without terminal LF; UTF-8 SHA-256",
    },
    "targets": targets,
    "supersedes": list(manifest_902.get("supersedes", [])) + [{"file": BASE_MANIFEST.name, "sha256": sha_file(BASE_MANIFEST)}],
    "denominator_adjudication": {
        "file": ADJUDICATION.name,
        "sha256": sha_file(ADJUDICATION),
        "accepted_delta": {"total": 2, "H": 2, "D": 0, "M": 0},
        "runtime_claim": False,
    },
})
manifest_904["transformations"] = list(manifest_902.get("transformations", [])) + [{
    "stage": "medical_condition_and_profile_denominator_reconciliation",
    "accepted_additions": 2,
    "accepted_route_delta": 8,
    "result": 904,
    "count_fit_used": False,
    "runtime_claim": False,
}]
manifest_904["adjudication_inputs"] = sorted(set(manifest_902.get("adjudication_inputs", [])) | {ADJUDICATION.name})
write_json(MANIFEST, manifest_904)

# 3. Versioned route/page reconciliation; only eight routes change disposition.
gap_904 = copy.deepcopy(gap_902)
gap_904.update({
    "artifact": "route-page-gap-reconciliation-904",
    "generated_at": GENERATED_AT,
    "inputs": [
        {"file": BASE_GAP.name, "role": "frozen 902 route/page relation corpus", "sha256": sha_file(BASE_GAP)},
        {"file": MANIFEST.name, "role": "accepted 904 FEATURE-ID identity", "sha256": sha_file(MANIFEST)},
        {"file": ADJUDICATION.name, "role": "two-capability/eight-route denominator decision", "sha256": sha_file(ADJUDICATION)},
    ],
    "normalization_note": "The 902 surface corpus is retained except that the eight independently owned medical mutation routes move from medical_reachability_unproved to two accepted 904 H targets. PAGE-0038 and PAGE-0590 remain resolver-orphans.",
})
medical_rows = gap_904["routes"].get("medical_reachability_unproved", [])
require(sum(len(row["route_ids"]) for row in medical_rows) == 8, "Expected exactly eight medical challenge routes")
gap_904["routes"]["medical_reachability_unproved"] = []
gap_904["routes"]["accepted_new_exact"].extend([
    {"route_ids": copy.deepcopy(row["route_ids"]), "target_ids": [row["working_key"]]}
    for row in ADDITIONS
])
gap_904["routes"]["accepted_new_exact"].sort(key=lambda row: row["route_ids"][0])
gap_904["counts"]["routes"]["accepted_new_exact"] = 20
gap_904["counts"]["routes"]["medical_reachability_unproved"] = 0
gap_904["counts"]["route_target_relations"] = 204
gap_904["counts"]["medical_reachability_challenges"] = 0
gap_904["denominator"].update({
    "accepted_new": 10,
    "accepted": 904,
    "accepted_new_class_counts": {"H": 6, "D": 3, "M": 1},
    "accepted_new_target_ids": sorted(gap_904["denominator"]["accepted_new_target_ids"] + [row["working_key"] for row in ADDITIONS]),
})
gap_904["accepted_feature_id_gate"] = {
    "identity_register": "904/904 statically registered",
    "surface_disposition": "3024/3024 routes and 962/962 pages retain an accepted-target or excluded-SURFACE disposition",
    "completion_status": "blocked",
    "reason": "Accepted FEATURE-ID registration and static route/page disposition are not runtime, role, error, recovery, handoff, viewport, test, or product-completion proof.",
}

route_seen: dict[str, str] = {}
page_seen: dict[str, str] = {}
linked_targets: set[str] = set()
for group, rows in gap_904["routes"].items():
    for row in rows:
        linked_targets.update(row.get("target_ids", []))
        for route_id in row.get("route_ids", []):
            require(route_id not in route_seen, f"Duplicate route relation {route_id}")
            route_seen[route_id] = group
for group, rows in gap_904["pages"].items():
    for row in rows:
        linked_targets.update(row.get("target_ids", []))
        for page_id in row.get("page_ids", []):
            require(page_id not in page_seen, f"Duplicate page relation {page_id}")
            page_seen[page_id] = group
require(len(route_seen) == 197 and len(page_seen) == 63, "Surface challenge corpus drift")
require(linked_targets <= set(stable_ids), "Gap corpus references a non-904 target")

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
coverage_lines = [COMMIT, "denominator|904"]
coverage_lines.extend(f"accepted|{target_id}" for target_id in gap_904["denominator"]["accepted_new_target_ids"])
coverage_lines.extend(f"{route_id}|{route_labels[group]}" for route_id, group in sorted(route_seen.items()))
coverage_lines.extend(f"{page_id}|{page_labels[group]}" for page_id, group in sorted(page_seen.items()))
gap_904["checksums"]["coverage_sha256"] = sha_lines(coverage_lines, sort=False)
gap_904["validation"].update({
    "manifest_target_count": 904,
    "manifest_class_counts": {"H": 790, "D": 111, "M": 3},
    "manifest_id_provenance": {"exact_current": 881, "source_stable": 5, "audit_assigned": 18},
    "medical_orphan_pages": ["PAGE-0038", "PAGE-0590"],
    "medical_mutation_routes_promoted_to_exact_owner": 8,
    "medical_reachability_challenges": 0,
    "inventory_route_surface_preserved": "2993 accepted + 31 excluded = 3024",
    "inventory_page_surface_preserved": "945 accepted + 17 excluded = 962",
    "accepted_feature_id_completion_gate": "blocked",
})
write_json(GAP, gap_904)

# 4. Benchmark successor: prior 451 outcomes retained; two new targets unproved.
benchmark_targets = [copy.deepcopy(row) for row in benchmark_902["targets"]]
for addition in ADDITIONS:
    benchmark_targets.append({
        "working_key": addition["working_key"],
        "id_status": addition["id_status"],
        "class": "H",
        "canonical_module": "EMAR",
        "source_family_ids": ["MED-CLIENT-MEDICAL"],
        "status": "unproved_audit_assigned_id",
        "inheritance_method": "none_audit_assigned_target_requires_target_specific_adjudication",
        "prior_outcome": None,
        "source_units": [],
        "evidence_loci": [],
        "completion_credit": False,
    })
benchmark_targets.sort(key=lambda row: row["working_key"])
require(len(benchmark_targets) == 904, "904 benchmark row mismatch")
benchmark_status = Counter(row["status"] for row in benchmark_targets)
require(benchmark_status["unproved_audit_assigned_id"] == 12, "New targets must remain unproved")
eligible = sum(bool(row.get("completion_credit")) for row in benchmark_targets)
require(eligible == 451, "Denominator migration must not grant benchmark credit")
completion_unproved = {
    "ordinary": benchmark_status["unproved"],
    "audit_assigned_stable_name": benchmark_status["unproved_audit_assigned_id"],
    "prior_pending": benchmark_status["unproved_pending"],
    "prior_reject": benchmark_status["unproved_reject"],
    "source_stable_semantic_merge": benchmark_status["unproved_source_stable"],
}
completion_unproved["total"] = sum(completion_unproved.values())
require(completion_unproved == {
    "ordinary": 413,
    "audit_assigned_stable_name": 12,
    "prior_pending": 24,
    "prior_reject": 3,
    "source_stable_semantic_merge": 1,
    "total": 453,
}, "904 benchmark partition mismatch")

def mapping_tuple(row: dict[str, Any]) -> str:
    return "|".join([
        str(row["working_key"]),
        str(row["status"]),
        ";".join(sorted(set(str(v) for v in row.get("source_units", [])))),
        ";".join(sorted(set(str(v) for v in row.get("evidence_loci", [])))),
    ])

benchmark_904 = copy.deepcopy(benchmark_902)
benchmark_904.update({
    "artifact": "benchmark-final-904-mapping",
    "generated_at": GENERATED_AT,
    "status": "target_specific_451_of_904_complete_not_overall_audit_completion",
    "denominator": {"total": 904, "H": 790, "D": 111, "M": 3},
    "summary": {
        "verified_benchmark": copy.deepcopy(benchmark_902["summary"]["verified_benchmark"]),
        "documented_no_credible_match": copy.deepcopy(benchmark_902["summary"]["documented_no_credible_match"]),
        "eligible_total": 451,
        "completion_unproved": completion_unproved,
        "status_counts": dict(sorted(benchmark_status.items())),
    },
    "completion_boundary": {
        "eligible_rows": 451,
        "completion_unproved_rows": 453,
        "statement": benchmark_902["completion_boundary"]["statement"],
        "formal_audit_gate": "blocked_453_of_904_targets_lack_completed_target_specific_benchmark_or_documented_no_match_outcome",
    },
    "checksum_algorithm": {
        **copy.deepcopy(benchmark_902["checksum_algorithm"]),
        "full_mapping_sha256": sha_lines([mapping_tuple(row) for row in benchmark_targets]),
        "eligible_subset_sha256": sha_lines([mapping_tuple(row) for row in benchmark_targets if row.get("completion_credit")]),
    },
    "inputs": {
        "working_manifest": {
            "path": rel(MANIFEST),
            "file_sha256": sha_file(MANIFEST),
            "canonical_stable_target_ids_sha256": manifest_904["checksums"]["canonical_stable_target_ids_sha256"],
        },
        "prior_902_mapping": {
            "path": rel(BASE_BENCHMARK),
            "file_sha256": sha_file(BASE_BENCHMARK),
            "full_mapping_sha256": benchmark_902["checksum_algorithm"]["full_mapping_sha256"],
            "eligible_subset_sha256": benchmark_902["checksum_algorithm"]["eligible_subset_sha256"],
        },
        "denominator_904_adjudication": {"path": rel(ADJUDICATION), "file_sha256": sha_file(ADJUDICATION)},
        "inherited_902_evidence": copy.deepcopy(benchmark_902["inputs"]),
    },
    "targets": benchmark_targets,
})
write_json(BENCHMARK, benchmark_904)

# 5. Inventory successor, preserving the complete frozen census.
inventory_904 = copy.deepcopy(inventory_902)
inventory_904["schema_version"] = "3.3"
inventory_904["generated_at"] = GENERATED_AT
inventory_904["denominator_corrections"].append(
    "Independent Pass-8 reconciliation found that the 902 register omitted the ClientCondition collection lifecycle and ClientMedicalProfile singleton update. The versioned 904 successor adds exactly those two H capabilities and assigns only their eight mutation routes; PAGE-0038 and PAGE-0590 remain resolver-orphans."
)
den = inventory_904["denominators"]
den.update({
    "canonical_features_registered": 904,
    "working_accepted_capability_denominator": 904,
    "working_human_ui_capability_denominator": 790,
    "working_distinct_user_capability_denominator": 790,
    "working_accepted_distinct_capability_denominator": 904,
    "downstream_manifest_rows_integrated": 904,
    "canonical_human_capabilities_registered": 790,
    "canonical_route_relations_enriched": 3073,
    "canonical_unique_routes_enriched": 2993,
    "canonical_route_mapping_percent": round(2993 / 3024 * 100, 2),
    "final_id_task_scripts_structurally_materialized": 790,
})
for module in inventory_904["modules"]:
    if module["module_key"] == "EMAR":
        module.update({
            "feature_count": 74,
            "user_facing_feature_count": 50,
            "canonical_capability_count": 74,
            "human_capability_count": 50,
        })

benchmark_by_key = {row["working_key"]: row for row in benchmark_targets}
for addition in ADDITIONS:
    inventory_904["features"].append({
        "feature_id": addition["working_key"],
        "working_key": addition["working_key"],
        "id_status": addition["id_status"],
        "candidate_id": addition["candidate_id"],
        "class": "H",
        "module_key": "EMAR",
        "module": "eMAR and medications",
        "source_family_ids": ["MED-CLIENT-MEDICAL"],
        "source_family_link_status": "linked",
        "route_ids": copy.deepcopy(addition["route_ids"]),
        "route_enrichment_status": "target_supported_static_enrichment",
        "page_ids": [],
        "page_enrichment_status": "not_enriched_resolver_orphan_pages_retained_excluded",
        "backend_anchors": copy.deepcopy(addition["backend_anchors"]),
        "origin_files": copy.deepcopy(addition["origin_files"]),
        "absorbed_occurrences": [],
        "identity_evidence": {
            "naming_proof": "canonical-denominator-904-adjudication.json",
            "identity_proof": copy.deepcopy(addition["proof"]),
        },
        "manifest_proof_notes": {
            "legacy_combined_id": addition["candidate_id"],
            "page_exclusion": ["PAGE-0038", "PAGE-0590"],
        },
        "benchmark_mapping": {
            key: copy.deepcopy(benchmark_by_key[addition["working_key"]][key])
            for key in ("status", "completion_credit", "inheritance_method", "prior_outcome", "source_units", "evidence_loci")
        },
        "runtime_validation_status": "not_executed",
        "completion_claim": False,
    })
inventory_904["features"].sort(key=lambda row: row["working_key"])
inventory_904["benchmark_mapping"] = {
    **copy.deepcopy(inventory_902["benchmark_mapping"]),
    "working_manifest_eligible": 451,
    "working_manifest_verified_benchmark": 362,
    "working_manifest_verified_direct": 340,
    "working_manifest_verified_rename": 22,
    "working_manifest_documented_no_credible_match": 89,
    "working_manifest_documented_ncm_direct": 82,
    "working_manifest_documented_ncm_rename": 7,
    "working_manifest_completion_unproved": 453,
    "completion_gate_status": "451/904 final targets have evidence-preserving benchmark/NCM mapping; 453 remain completion-unproved",
}
inventory_904["pass_status"].update({
    "P1": "Blocked—904/904 accepted FEATURE-IDs registered; 3024/3024 routes and 962/962 pages have an accepted-target or excluded-surface static disposition; accepted-ID registration is not runtime completion proof",
    "P2": "Blocked—790/790 final-ID task scripts and scorecard rows are structurally materialized; 0/790 representative-role tasks executed or independently usability-validated",
    "P3": "Blocked—451/904 targets mapped with evidence-preserving completion credit (362 verified benchmark, 89 documented No Credible Match); 453 unproved",
})

addition_by_route = {route_id: row for row in ADDITIONS for route_id in row["route_ids"]}
for route in inventory_904["routes"]:
    addition = addition_by_route.get(route["route_id"])
    if not addition:
        continue
    target = addition["working_key"]
    route["canonical_capability_id"] = target
    route["working_canonical_feature_ids"] = [target]
    route["working_canonical_feature_link_status"] = "target_supported_static_enrichment"
    route["excluded_surface_disposition_ids"] = []
    route["static_disposition_ids"] = [target]
    route["static_disposition_kind"] = "accepted_capability_target"
    route["static_surface_disposition"] = None

inventory_904["capability_denominator_status"] = {
    **copy.deepcopy(inventory_902["capability_denominator_status"]),
    "status": "working_904_static_manifest_integrated_as_canonical_inventory_register_runtime_unverified",
    "earlier_denominator_status": "The 902/788/111/3 register is frozen as historical evidence. Independent reconciliation adds two omitted human medical capabilities and yields 904/790/111/3 without changing product source or runtime credit.",
    "working_accepted_denominator": 904,
    "working_human_ui": 790,
    "working_manifest": rel(MANIFEST),
    "working_manifest_sha256": sha_file(MANIFEST),
    "working_manifest_unique_stable_ids": 904,
    "stable_id_provenance": {"exact_current": 881, "source_stable": 5, "audit_assigned": 18},
    "route_enrichment": {"targets": 903, "relations": 3073, "unique_routes": 2993},
    "backend_enrichment": {"targets": 731, "relations": 830, "unique_backend_anchors": 469},
    "downstream_manifest_rows_integrated": 904,
    "reason": "All 904 stable IDs are materialised and every route/page retains an accepted-target or excluded-surface disposition. Static identity is not runtime completion: task execution, denied states, persisted outcomes, recovery, handoff, tests, viewports and usability remain incomplete.",
    "benchmark_mapping": {"eligible": 451, "verified_benchmark": 362, "documented_no_credible_match": 89, "completion_unproved": 453},
    "source_route_classification": "3024/3024 classified; 2993/3024 map to accepted 904 targets and 31 retain excluded SURFACE dispositions",
    "runtime_task_execution": "0/790 working human targets",
    "inventory_route_mapping": {"completed": 2993, "denominator": 3024, "percent": round(2993 / 3024 * 100, 2), "scope": "target-supported static enrichment"},
    "accepted_feature_id_completion_gate": {"status": "blocked", "accepted_ids_registered": 904, "reason": "Static accepted FEATURE-ID registration does not prove representative-role completion, persisted outcomes, recovery, handoff, viewport behavior, or runtime enforcement."},
}
inventory_904["canonical_feature_register_metadata"] = {
    **copy.deepcopy(inventory_902["canonical_feature_register_metadata"]),
    "count": 904,
    "counts": {"H": 790, "D": 111, "M": 3},
    "id_provenance": {"exact_current": 881, "source_stable": 5, "audit_assigned_stable_name": 18},
    "source_family_enrichment": {"targets_with_source_family_ids": 904, "targets_not_linked_to_source_family_ids": 0, "unique_source_family_ids": 521, "relations": 995},
    "route_enrichment": {"targets": 903, "relations": 3073, "unique_routes": 2993, "excluded_surface_routes": 31, "inventory_routes_with_static_disposition": 3024, "shared_route_ids": 30, "inventory_routes": 3024},
    "backend_enrichment": {"targets": 731, "relations": 830, "unique_anchors": 469},
    "benchmark_mapping": {"verified_benchmark": 362, "documented_no_credible_match": 89, "completion_credit": 451, "completion_unproved": 453},
    "source_artifacts": {
        "manifest": rel(MANIFEST), "manifest_sha256": sha_file(MANIFEST),
        "benchmark_mapping": rel(BENCHMARK), "benchmark_mapping_sha256": sha_file(BENCHMARK),
        "route_page_gap_reconciliation": rel(GAP), "route_page_gap_reconciliation_sha256": sha_file(GAP),
        "denominator_adjudication": rel(ADJUDICATION), "denominator_adjudication_sha256": sha_file(ADJUDICATION),
    },
}
write_json(INVENTORY, inventory_904)

# 6. Versioned ledgers and scorecard.
def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), [dict(row) for row in reader]


def write_csv(path: Path, headers: list[str], rows: list[dict[str, str]], *, quote_all: bool = False) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=headers,
            extrasaction="raise",
            lineterminator="\n",
            quoting=csv.QUOTE_ALL if quote_all else csv.QUOTE_MINIMAL,
        )
        writer.writeheader()
        writer.writerows(rows)


ledger_headers, ledger_rows = read_csv(BASE_LEDGER)
matrix_headers, matrix_rows = read_csv(BASE_MATRIX)
score_headers, score_rows = read_csv(BASE_SCORECARD)
route_by_id = {row["route_id"]: row for row in inventory_904["routes"]}


def successor_text(value: str) -> str:
    """Update only current-denominator prose inherited from the frozen 902 rows."""
    return (
        value
        .replace("corrected 902 identity/count reconciliation", "corrected 904 identity/count reconciliation")
        .replace("Corrected 902-target ledger row generated", "Corrected 904-target ledger row generated")
        .replace("0/788 representative-role tasks executed", "0/790 representative-role tasks executed")
    )


for rows in (ledger_rows, matrix_rows, score_rows):
    for row in rows:
        for key, value in row.items():
            row[key] = successor_text(value)

p2 = "Blocked—target-specific representative-role task and persisted outcome were not executed as part of the audit; later focused tests do not count as canonical task execution."
p4 = "Blocked—representative-role happy, error, recovery, handoff, responsive and accessibility states were not safely executed for this target."
p6 = "Blocked—target-specific official-source applicability and representative role/site/direct-object runtime evidence are not linked."
p7 = "Blocked—target-specific tests, constraints and failure-mode evidence are not linked; the complete audit-wide test denominator was not executed as one controlled gate."

for addition in ADDITIONS:
    key = addition["working_key"]
    route_rows = [route_by_id[route_id] for route_id in addition["route_ids"]]
    route_names = "; ".join(sorted({row["name"] for row in route_rows}))
    route_paths = "; ".join(f"{row['method']} {row['uri']} [{row['route_id']}]" for row in sorted(route_rows, key=lambda row: row["route_id"]))
    backend = "; ".join(sorted({f"{row['action']} [via exact target route {row['route_id']}]" for row in route_rows} | set(addition["backend_anchors"])))
    p1 = f"Reviewed—independently reconciled 904 target identity; exact mutation routes={len(route_rows)}, pages=0, backend_anchors=1. The two legacy medical pages remain resolver-orphans and are not entrypoint credit."
    p3 = "Blocked—newly registered 904 target has no completed target-specific benchmark or bounded No Credible Match adjudication."
    p5 = "Reviewed—static route/controller/model/UI/test ownership separates this aggregate from the other new medical target; architecture, data effects and runtime completion remain unverified."
    p8 = "Reviewed (fresh static challenge)—target added only after independent Pass-8 denominator reconciliation; no runtime or audit-completion claim follows."
    ledger_rows.append({
        "feature_id": key,
        "working_key": key,
        "class": "H",
        "module": "eMAR and medications",
        "submodule": f"{addition['title']} — stable working target",
        "source_family_inventory_envelope": f"Source-family inventory envelope (discovery context only; never exclusive target ownership): families=MED-CLIENT-MEDICAL; legacy_rows=1; routes={len(route_rows)}; pages=0; backend_anchors=1.",
        "P1_status": p1,
        "P2_status": p2,
        "P3_status": p3,
        "P4_status": p4,
        "P5_status": p5,
        "P6_status": p6,
        "P7_status": p7,
        "P8_status": p8,
        "agent_assignments": "Independent denominator review: pass8_gate_reconcile; orchestrator 904 materialization; runtime/browser/test lanes unassigned",
        "evidence_count": "8",
        "gaps": "target-specific benchmark/NCM; representative-role execution; error/recovery/handoff; independent usability scores; current reachable page entry",
        "reconciliation_status": "Corrected 904-target ledger row generated; static evidence only; audit completion not claimed",
    })
    matrix_rows.append({
        "feature_id": key,
        "module": "eMAR and medications",
        "submodule": f"{addition['title']} — stable working target (H)",
        "owning_actor": addition["actor"],
        "secondary_actors": "Unresolved—representative role/site variants unavailable",
        "user_job": f"Complete {addition['title']} on the canonical client record and verify the authoritative persisted outcome",
        "criticality": "Safety-critical clinical record mutation",
        "navigation_entry": "Not established—the source-resolved legacy medical pages remain resolver-orphans; mutation routes are exact ownership evidence, not current navigation proof",
        "route_names": route_names,
        "route_paths": route_paths,
        "page_files": "",
        "backend_anchors": backend,
        "current_states": f"Source-owned {addition['proof']['aggregate']} mutation states; representative runtime state coverage not executed",
        "current_workflow_summary": f"Exact static owner for {addition['proof']['aggregate']}; routes={len(route_rows)}, pages=0, controller actions={','.join(addition['actions'])}. PAGE-0038/PAGE-0590 remain resolver-orphans. No runtime completion claim.",
        "benchmark_candidates": "Not target-adjudicated",
        "selected_open_source_benchmark": "",
        "benchmark_url_and_sha": "",
        "verified_behaviour": "",
        "neutral_requirements_extracted": "",
        "no_match_evidence": "",
        "current_ease_score": "Not measured—representative-role runtime blocked",
        "target_ease_score": "Not scored—owner/user validation required",
        "P1": p1,
        "P2": p2,
        "P3": p3,
        "P4": p4,
        "P5": p5,
        "P6": p6,
        "P7": p7,
        "P8": p8,
        "finding_ids": "",
        "confidence": "High for static aggregate/route ownership; runtime, navigation, UX and completion unverified",
    })
    score_rows.append({
        "task_script_id": f"TASK-{key}",
        "feature_id": key,
        "module": "EMAR",
        "actor": addition["actor"],
        "task": f"Complete {addition['title']} and verify persisted completion and hand-off evidence",
        "start_condition": "Authorised representative actor and resettable synthetic prerequisite; exact mutation routes are source-owned but current page entry is unproved.",
        "goal": "Authoritative persisted outcome, provenance, downstream effect and next owner or terminal state.",
        "prerequisites": "Representative account; correct Site/ownership/parent relation; resettable fixture; wrong-object denial fixtures.",
        "observed_or_inferred": "Source-derived final-ID task; runtime unverified",
        "validation_status": "Blocked—source-derived final-ID task exists; 0/790 representative-role tasks executed; independent semantic/usability review pending",
        "score_measurement_status": "Not measured",
        "score_scale": "0-5; blank means not measured",
        "discoverability": "", "comprehension": "", "learnability": "", "efficiency": "",
        "error_prevention": "", "recovery": "", "accessibility": "", "safety_and_trust": "",
        "consistency": "", "cross_module_continuity": "",
        "completion_time": "Not measured",
        "step_count": "5 source-bound steps; rendered/conditional count not measured",
        "required_field_count": "Not measured", "decision_count": "Not measured",
        "context_switches": "Not measured", "dead_ends": "Not measured",
        "recovery_path": "Wrong-object denial; validation preservation; concurrency/replay at-most-once; authorised correction/reversal. Exact runtime paths unverified.",
        "target_scores": json.dumps({"all_dimensions": None, "safety_critical_error_prevention_and_trust": None}, separators=(",", ":")),
        "independent_review": "Blocked—no representative-user task execution or independent score review",
        "finding_ids": "",
    })

ledger_rows.sort(key=lambda row: row["feature_id"])
matrix_rows.sort(key=lambda row: row["feature_id"])
score_rows.sort(key=lambda row: row["feature_id"])
require(len(ledger_rows) == len(matrix_rows) == 904, "904 ledger row mismatch")
require(len(score_rows) == 790, "904 scorecard row mismatch")
write_csv(LEDGER, ledger_headers, ledger_rows)
write_csv(MATRIX, matrix_headers, matrix_rows)
write_csv(SCORECARD, score_headers, score_rows, quote_all=True)

# 7. Versioned 790-task materialisation: exact copies plus two new source scripts.
TASK_DIR.mkdir(parents=True, exist_ok=True)
expected_task_files = {path.name for path in BASE_TASK_DIR.glob("*.md")}
for path in BASE_TASK_DIR.glob("*.md"):
    shutil.copyfile(path, TASK_DIR / path.name)

def task_markdown(addition: dict[str, Any]) -> str:
    route_rows = [route_by_id[route_id] for route_id in addition["route_ids"]]
    route_names = ", ".join(f"`{row['name']}`" for row in sorted(route_rows, key=lambda row: row["name"]))
    route_paths = ", ".join(f"`{row['method']} {row['uri']}`" for row in sorted(route_rows, key=lambda row: row["route_id"]))
    return f"""# {addition['working_key']} — {addition['title']}

Status: **Blocked — source-derived 904 final-ID script; representative-role execution and independent task validation not performed.**

## Evidence boundary

- Capability: `{addition['working_key']}`
- Canonical module: `EMAR`
- ID provenance: `audit_assigned_stable_name`
- Source family: `MED-CLIENT-MEDICAL`
- Aggregate: `{addition['proof']['aggregate']}`
- Route evidence: {', '.join(f'`{route_id}`' for route_id in addition['route_ids'])}
- Route names: {route_names}
- Route paths: {route_paths}
- Target-supported controller actions: {', '.join(f'`{action}`' for action in addition['actions'])}
- Backend anchor: `app/Http/Controllers/ClientMedicalController.php`
- Page evidence: none. `PAGE-0038` and `PAGE-0590` remain resolver-orphans and are not entrypoint credit.
- Benchmark status: unproved; no comparator or No Credible Match credit was added by the denominator repair.

## Representative task

Actor: {addition['actor']}

Goal: Complete **{addition['title']}** on the authoritative client record, then verify the persisted outcome, actor/time provenance, downstream effect and next accountable owner or terminal state.

Prerequisites:

- A non-production representative account with the documented Site/role/permission scope.
- A resettable synthetic client in the correct prerequisite state.
- Known wrong-Site, wrong-parent and wrong-record fixtures for denial checks.
- A current reachable navigation entry must be established; the legacy medical pages are source callers but resolver-orphans.

Steps:

1. Enter through a current authorised route/page for this final capability; do not infer entry from the resolver-orphan source pages.
2. Confirm actor, Site, client/direct-object relationship and prerequisite state before disclosure or mutation.
3. Perform only the exact `{addition['proof']['aggregate']}` action owned by this target.
4. Verify authoritative persisted state plus actor/time/source provenance; a toast or HTTP success is insufficient.
5. Exercise safe invalid-input, wrong-object, replay/concurrency and correction paths, then verify the next owner or terminal state.

## Required error and recovery checks

- Wrong Site, client, parent or nested object: deny before disclosure or side effect.
- Invalid input: bind field errors and preserve authoritative state.
- Stale, concurrent or replayed action: at most one intended effect with safe review/retry.
- Correction/reversal: retain prior provenance and re-check authority and state.

## Current ease scores

All ten current scores are **Not measured**. Numeric zero is not substituted for absent representative-user measurement. Representative execution is 0/790 and independent score validation is 0/790.
"""

for addition in ADDITIONS:
    name = addition["working_key"].lower() + ".md"
    expected_task_files.add(name)
    (TASK_DIR / name).write_text(task_markdown(addition), encoding="utf-8", newline="\n")
for path in TASK_DIR.iterdir():
    require(path.is_file(), f"Unexpected non-file in {TASK_DIR}: {path}")
    if path.name not in expected_task_files:
        path.unlink()

script_rows = []
for path in sorted(TASK_DIR.glob("*.md"), key=lambda item: item.name):
    feature_id = path.stem.upper()
    script_rows.append({"feature_id": feature_id, "file": rel(path), "sha256": sha_file(path)})
require(len(script_rows) == 790, "904 task script file count mismatch")
script_index_sha = sha_lines([f"{row['feature_id']}|{row['file']}|{row['sha256']}" for row in script_rows], sort=False)
task_summary = {
    "schema_version": "1.0",
    "artifact": "final-904-task-script-generation-summary",
    "audited_commit": COMMIT,
    "generated_at": GENERATED_AT,
    "status": "structurally_materialized_runtime_and_independent_validation_blocked",
    "audit_boundary": "Audit artifacts only. No application source, configuration, routes, data, tests, browser state, deployment or Git history changed.",
    "inputs": {
        "manifest": file_record(MANIFEST),
        "inventory": file_record(INVENTORY),
        "findings": file_record(BASE_FINDINGS),
        "frozen_902_task_summary": file_record(SOURCE / "final-902-task-script-generation-summary.json"),
        "denominator_adjudication": file_record(ADJUDICATION),
    },
    "counts": {
        "manifest_total": 904,
        "human_targets": 790,
        "generated_task_scripts": 790,
        "scorecard_rows": 790,
        "representative_role_tasks_executed": 0,
        "independently_validated_scripts": 0,
        "current_scores_measured": 0,
    },
    "proof_boundary": {
        "structural_script_coverage": "790/790",
        "substantive_runtime_validation": "0/790",
        "ease_score_validation": "0/790; all current and target scores remain blank/null",
        "new_medical_pages": "PAGE-0038 and PAGE-0590 remain resolver-orphans; no navigation credit",
    },
    "outputs": {
        "directory": rel(TASK_DIR),
        "scorecard": rel(SCORECARD),
        "scorecard_sha256": sha_file(SCORECARD),
        "script_index_sha256": script_index_sha,
        "script_index_algorithm": "Ordinal file order; UTF-8 LF/no-terminal-LF feature_id|file|sha256",
    },
    "scripts": script_rows,
}
write_json(TASK_SUMMARY, task_summary)

# 8. Final validation summary and atomic active-input pointer.
outputs = [ADJUDICATION, MANIFEST, GAP, BENCHMARK, INVENTORY, LEDGER, MATRIX, SCORECARD, TASK_SUMMARY]
summary = {
    "schema_version": "1.0",
    "artifact": "canonical-denominator-904-generation-summary",
    "generated_at": GENERATED_AT,
    "audited_commit": COMMIT,
    "status": "validated_static_904_successor_runtime_and_completion_blocked",
    "generator": rel(GENERATOR),
    "input_pins": [{"path": rel(path), "sha256": expected} for path, expected in EXPECTED_INPUTS.items()],
    "outputs": [file_record(path) for path in outputs],
    "invariants": {
        "manifest": "904 unique = 790 H + 111 D + 3 M",
        "existing_902_ids_preserved": set(row["working_key"] for row in manifest_902["targets"]) < set(stable_ids),
        "exact_additions": sorted(set(stable_ids) - set(row["working_key"] for row in manifest_902["targets"])),
        "route_delta": "exactly eight routes moved from excluded surface to unique accepted target",
        "route_surface": "2993 accepted + 31 excluded = 3024",
        "page_surface": "945 accepted + 17 excluded = 962",
        "orphan_pages_preserved": ["PAGE-0038", "PAGE-0590"],
        "benchmark": "451 decided + 453 unproved = 904; zero credit delta",
        "ledger_rows": 904,
        "matrix_rows": 904,
        "scorecard_rows": 790,
        "task_scripts": 790,
        "runtime_credit_delta": 0,
        "browser_credit_delta": 0,
        "test_credit_delta": 0,
    },
    "completion_gate": {
        "complete": False,
        "reason": "453 benchmark outcomes, 790 representative tasks/scores, runtime routes/states/journeys/tests, visual ownership, all-eight-pass modules, fresh Pass 8 and orchestration/writer gates remain incomplete.",
    },
}
write_json(SUMMARY, summary)

active_inputs = {
    "schema_version": "1.0",
    "artifact": "canonical-audit-inputs",
    "generated_at": GENERATED_AT,
    "audited_commit": COMMIT,
    "status": "active_904_static_denominator_runtime_and_completion_blocked",
    "active_denominator": {"total": 904, "H": 790, "D": 111, "M": 3},
    "artifacts": {
        "denominator_adjudication": file_record(ADJUDICATION),
        "manifest": file_record(MANIFEST),
        "benchmark": file_record(BENCHMARK),
        "route_page_reconciliation": file_record(GAP),
        "inventory": file_record(INVENTORY),
        "eight_pass_ledger": file_record(LEDGER),
        "benchmark_matrix": file_record(MATRIX),
        "task_scorecard": file_record(SCORECARD),
        "task_generation_summary": file_record(TASK_SUMMARY),
        "visual_matrix": file_record(BASE_VISUAL),
        "findings": file_record(BASE_FINDINGS),
        "generation_summary": file_record(SUMMARY),
    },
    "historical_denominator": {
        "total": 902,
        "manifest": file_record(BASE_MANIFEST),
        "benchmark": file_record(BASE_BENCHMARK),
        "route_page_reconciliation": file_record(BASE_GAP),
    },
    "completion_status": "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE",
    "runtime_credit_delta": 0,
}
write_json(ACTIVE_INPUTS, active_inputs)

print(json.dumps({
    "manifest": file_record(MANIFEST),
    "benchmark": file_record(BENCHMARK),
    "route_page": file_record(GAP),
    "inventory": file_record(INVENTORY),
    "ledger_rows": len(ledger_rows),
    "matrix_rows": len(matrix_rows),
    "scorecard_rows": len(score_rows),
    "task_scripts": len(script_rows),
    "active_inputs": file_record(ACTIVE_INPUTS),
}, indent=2))
