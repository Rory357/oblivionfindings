#!/usr/bin/env python3
"""Reconcile the required 904 aliases and Matrix 05 overlay evidence.

This is an audit-artifact-only transform. It does not award browser, runtime,
task, test, usability, remediation, release, or all-pass completion credit.
"""

from __future__ import annotations

import csv
import hashlib
import json
import shutil
import subprocess
from collections import Counter
from pathlib import Path
from typing import Any


GENERATOR = Path(__file__).resolve()
AUDIT = GENERATOR.parent.parent
SOURCE = AUDIT / "evidence" / "source"
HISTORICAL = AUDIT / "evidence" / "historical" / "canonical-902"

AUDITED_COMMIT = "081ef198f9f992f224e8c0c9fba33df33dde40be"
GENERATED_AT = "2026-08-23T15:44:58+12:00"

MANIFEST = SOURCE / "working-capability-manifest-904.json"
BENCHMARK = SOURCE / "benchmark-final-904-mapping.json"
CLASSIFIER = SOURCE / "overlay-trigger-classification.json"
VISUAL_SUMMARY = SOURCE / "final-904-visual-link-generation-summary.json"
CSV_VALIDATION = SOURCE / "csv-semantic-validation.json"
COMPLETION = SOURCE / "completion-gate-report.json"
RECONCILIATION = SOURCE / "dashboard-reconciliation-2026-08-23.json"

INVENTORY_VERSIONED = AUDIT / "inventory-904.json"
LEDGER_VERSIONED = AUDIT / "02-eight-pass-coverage-ledger-904.csv"
MATRIX_VERSIONED = AUDIT / "03-feature-to-benchmark-matrix-904.csv"
SCORECARD_VERSIONED = AUDIT / "04-workflow-usability-scorecard-904.csv"
VISUAL_VERSIONED = AUDIT / "05-browser-visual-coverage-matrix-904.csv"

ALIASES = {
    AUDIT / "inventory.json": INVENTORY_VERSIONED,
    AUDIT / "02-eight-pass-coverage-ledger.csv": LEDGER_VERSIONED,
    AUDIT / "03-feature-to-benchmark-matrix.csv": MATRIX_VERSIONED,
    AUDIT / "04-workflow-usability-scorecard.csv": SCORECARD_VERSIONED,
    AUDIT / "05-browser-visual-coverage-matrix.csv": VISUAL_VERSIONED,
}

HISTORICAL_HASHES = {
    "inventory.json": "076015a57c9368cdc737d0e8139589ba5708d0fa6bcee1d6654c2e72b4b9889a",
    "02-eight-pass-coverage-ledger.csv": "b1082f6ebd02715b3a88123927b0189df87c70b356bf8928837eddf915ac79bf",
    "03-feature-to-benchmark-matrix.csv": "f16d04ab2d25f30caca370bcbbb2ba504f0dd2d211b7b552018dd3bf7ec3d608",
    "04-workflow-usability-scorecard.csv": "db680bb3a4aa46de8124cf890debc5ccd4fe2647927bf5ad87be0683bb02e3af",
    "05-browser-visual-coverage-matrix.csv": "f0aed8a6cbc242651ef7cd702685f8c948af276b3830d4d5960ea6ece1e9f363",
}


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def load_json(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8-sig"))


def write_json(path: Path, value: Any) -> None:
    path.write_text(
        json.dumps(value, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
        newline="\n",
    )


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def rel(path: Path) -> str:
    return path.relative_to(AUDIT).as_posix()


def record(path: Path) -> dict[str, Any]:
    return {"path": rel(path), "sha256": sha256(path), "bytes": path.stat().st_size}


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        return list(reader.fieldnames or []), [dict(row) for row in reader]


def write_csv(path: Path, headers: list[str], rows: list[dict[str, str]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=headers,
            extrasaction="raise",
            lineterminator="\n",
        )
        writer.writeheader()
        writer.writerows(rows)


head = subprocess.run(
    ["git", "rev-parse", "HEAD"],
    cwd=AUDIT,
    check=True,
    stdout=subprocess.PIPE,
    stderr=subprocess.PIPE,
    text=True,
).stdout.strip()
require(head == AUDITED_COMMIT, f"Audited checkout drift: {head}")

# Preserve the displaced 902 defaults once, then require their byte identity on
# every rerun. This makes the required unversioned names unambiguously current.
HISTORICAL.mkdir(parents=True, exist_ok=True)
for alias, expected_hash in HISTORICAL_HASHES.items():
    destination = HISTORICAL / alias
    if not destination.exists():
        source = AUDIT / alias
        require(sha256(source) == expected_hash, f"Historical 902 preimage drift: {alias}")
        shutil.copy2(source, destination)
    require(sha256(destination) == expected_hash, f"Historical 902 archive drift: {alias}")

classifier = load_json(CLASSIFIER)
headers, visual_rows = read_csv(VISUAL_VERSIONED)
require(len(headers) == 22, "Matrix 05 column count drift")
require(len(visual_rows) == 8753, "Matrix 05 row count drift")
require(len({row["visual_id"] for row in visual_rows}) == 8753, "Duplicate VISUAL-ID")

custom_rows = classifier["custom_rows"]
primitive_rows = classifier["primitive_rows"]
explicit_rows = classifier["explicit_primitive_trigger_rows"]
require((len(custom_rows), len(primitive_rows), len(explicit_rows)) == (659, 477, 146), "Overlay classifier denominator drift")

custom_by_key = {
    (f"{row['file']}:{int(row['line'])}:{int(row['column'])}", str(row["symbol"])): row
    for row in custom_rows
}
primitive_by_key = {
    (f"{row['file']}:{int(row['line'])}:{int(row['column'])}", str(row["symbol"])): row
    for row in primitive_rows
}
require(len(custom_by_key) == 659, "Duplicate custom classifier identity")
require(len(primitive_by_key) == 477, "Duplicate primitive classifier identity")


def evidence_json(row: dict[str, Any]) -> str:
    return json.dumps(
        row.get("trigger_evidence", []),
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
    )


joined_custom: set[tuple[str, str]] = set()
joined_primitive: set[tuple[str, str]] = set()
trigger_or_classification_changes = 0
fully_rederived_rows = 0

for visual in visual_rows:
    layer = visual["pattern_type"]
    if layer not in {"overlay/custom-usage", "overlay/primitive-root"}:
        continue
    key = (visual["component_anchor"], visual["implementation"])
    if layer == "overlay/custom-usage":
        require(key in custom_by_key, f"Missing custom classifier row: {key}")
        source = custom_by_key[key]
        resolution = str(source["trigger_resolution"])
        if resolution == "resolved":
            trigger = "exact static trigger relation"
            classification = "Source-inferred"
        elif resolution == "source_inferred_not_exactly_paired":
            trigger = "source-inferred trigger candidate; not exactly paired"
            classification = "Source-inferred"
        else:
            require(resolution in {"unresolved", "blocked_parent_prop_not_present_at_callsite"}, f"Unknown custom resolution: {resolution}")
            trigger = "unresolved or blocked static trigger relation"
            classification = "Blocked"
        notes = (
            f"Classifier sync: class={source['trigger_classification']}; resolution={resolution}; "
            f"evidence={evidence_json(source)}; reachable={str(bool(source.get('reachable'))).lower()}. "
            f"{source['evidence_limit']}"
        )
        joined_custom.add(key)
    else:
        require(key in primitive_by_key, f"Missing primitive classifier row: {key}")
        source = primitive_by_key[key]
        resolution = str(source["trigger_resolution"])
        if resolution == "resolved":
            trigger = "exact static primitive trigger/state relation"
            classification = "Source-inferred"
        else:
            require(resolution == "unresolved", f"Unknown primitive resolution: {resolution}")
            trigger = "unresolved static trigger relation"
            classification = "Blocked"
        notes = (
            f"Classifier sync: class={source['trigger_classification']}; resolution={resolution}; "
            f"evidence={evidence_json(source)}. {source['evidence_limit']}"
        )
        joined_primitive.add(key)

    if (visual["trigger"], visual["classification"]) != (trigger, classification):
        trigger_or_classification_changes += 1
    visual["trigger"] = trigger
    visual["classification"] = classification
    visual["observed_notes"] = notes
    fully_rederived_rows += 1

require(len(joined_custom) == 659, "Custom matrix join is not 659/659")
require(len(joined_primitive) == 477, "Primitive matrix join is not 477/477")
require(fully_rederived_rows == 1136, "Overlay matrix sync row count drift")
require(trigger_or_classification_changes == 134, "Expected 100 custom plus 34 primitive trigger/classification changes")

custom_resolutions = Counter(str(row["trigger_resolution"]) for row in custom_rows)
primitive_resolutions = Counter(str(row["trigger_resolution"]) for row in primitive_rows)
require(custom_resolutions == Counter({"resolved": 253, "unresolved": 260, "source_inferred_not_exactly_paired": 144, "blocked_parent_prop_not_present_at_callsite": 2}), "Custom classifier partition drift")
require(primitive_resolutions == Counter({"resolved": 242, "unresolved": 235}), "Primitive classifier partition drift")

classification_counts = Counter(row["classification"] for row in visual_rows)
require(classification_counts == Counter({"Not safely reproducible": 4312, "Observed": 2503, "Source-inferred": 1441, "Blocked": 497}), "Post-sync classification count drift")
assigned = [row for row in visual_rows if row["feature_id"]]
material = [row for row in visual_rows if row["pattern_type"] == "material-state-applicability"]
require((len(assigned), len(visual_rows) - len(assigned)) == (8168, 585), "Visual ownership changed")
require((sum(bool(row["feature_id"]) for row in material), sum(not row["feature_id"] for row in material)) == (3948, 364), "Material ownership changed")

write_csv(VISUAL_VERSIONED, headers, visual_rows)

visual_summary = load_json(VISUAL_SUMMARY)
visual_summary["schema_version"] = "1.1"
visual_summary["generated_at"] = GENERATED_AT
visual_summary["inputs"]["overlay_classifier_sha256"] = sha256(CLASSIFIER)
visual_summary["counts"]["classification_counts"] = dict(sorted(classification_counts.items()))
visual_summary["outputs"]["matrix"] = rel(VISUAL_VERSIONED)
visual_summary["outputs"]["matrix_sha256"] = sha256(VISUAL_VERSIONED)
visual_summary["overlay_classifier_sync"] = {
    "custom_rows": 659,
    "custom_exact": 253,
    "custom_inferred": 144,
    "custom_unresolved_or_blocked": 262,
    "primitive_rows": 477,
    "primitive_exact": 242,
    "primitive_unresolved": 235,
    "explicit_primitive_trigger_rows_unchanged": 146,
    "trigger_or_classification_changes": 134,
    "fully_rederived_rows": 1136,
    "runtime_credit_delta": 0,
}
write_json(VISUAL_SUMMARY, visual_summary)

inventory = load_json(INVENTORY_VERSIONED)
inventory["overlay_census"] = {
    "layer_boundary": classifier["layer_boundary"],
    "parser": classifier["parser"],
    "custom_usage_layer": classifier["custom_usage_layer"],
    "primitive_root_layer": classifier["primitive_root_layer"],
    "runtime_boundary": classifier["runtime_boundary"],
    "source": "evidence/source/overlay-trigger-classification.json",
}
write_json(INVENTORY_VERSIONED, inventory)

for alias, versioned in ALIASES.items():
    shutil.copy2(versioned, alias)
    require(sha256(alias) == sha256(versioned), f"Canonical alias mismatch: {alias.name}")

manifest = load_json(MANIFEST)
benchmark = load_json(BENCHMARK)
csv_validation = load_json(CSV_VALIDATION)
csv_validation["schema_version"] = "4.0"
csv_validation["generated_at"] = GENERATED_AT
shapes = csv_validation["current_csv_shapes"]
shapes["02-eight-pass-coverage-ledger.csv"].update({
    "data_rows": 904, "required_rows": 904, "sha256": sha256(AUDIT / "02-eight-pass-coverage-ledger.csv")
})
shapes["03-feature-to-benchmark-matrix.csv"].update({
    "data_rows": 904,
    "required_rows": 904,
    "sha256": sha256(AUDIT / "03-feature-to-benchmark-matrix.csv"),
    "benchmark_mapped": benchmark["summary"]["eligible_total"],
    "benchmark_verified": benchmark["summary"]["verified_benchmark"]["total"],
    "benchmark_documented_no_credible_match": benchmark["summary"]["documented_no_credible_match"]["total"],
    "benchmark_completion_unproved": benchmark["summary"]["completion_unproved"]["total"],
    "feature_benchmark_gate_complete": False,
})
shapes["04-workflow-usability-scorecard.csv"].update({
    "data_rows": 790, "required_rows": 790, "sha256": sha256(AUDIT / "04-workflow-usability-scorecard.csv"),
    "runtime_executed": 0, "independently_reviewed": 0, "current_scores_measured": 0,
    "substantive_gate_complete": False,
})
manifest_ids = {row["working_key"] for row in manifest["targets"]}
lineage_ids: set[str] = set()
for row in visual_rows:
    for value in row.get("working_feature_ids", "").split("|"):
        value = value.strip()
        if value in manifest_ids:
            lineage_ids.add(value)
shapes["05-browser-visual-coverage-matrix.csv"].update({
    "data_rows": 8753,
    "required_rows": 8753,
    "sha256": sha256(AUDIT / "05-browser-visual-coverage-matrix.csv"),
    "semantic_tuple_sha256": visual_summary["outputs"]["semantic_tuple_sha256"],
    "assigned_final_feature_id": 8168,
    "unresolved_final_feature_id": 585,
    "unique_assigned_final_feature_ids": 774,
    "manifest_ids_with_any_visual_lineage": len(lineage_ids),
    "final_feature_links_complete": False,
    "classification_counts": dict(sorted(classification_counts.items())),
})
csv_validation["working_manifest"].update({
    "path": "working-capability-manifest-904.json",
    "sha256": sha256(MANIFEST),
    "rows": 904,
    "unique_stable_ids": 904,
    "classes": {"H": 790, "D": 111, "M": 3},
    "stable_id_provenance": {"exact_current": 881, "source_stable": 5, "audit_assigned": 18},
    "route_enrichment": {"targets": 903, "relations": 3076, "unique_routes": 2994, "inventory_routes": 3024, "accepted_percent": 99.01, "excluded_surface_relations": 30, "static_disposition_total": 3024},
    "page_enrichment": {"targets": 756, "relations": 1526, "unique_pages": 945, "inventory_pages": 962, "accepted_percent": 98.23, "excluded_surface_relations": 17, "static_disposition_total": 962},
    "backend_enrichment": {"targets": 731, "relations": 830, "unique_anchors": 469},
    "benchmark_mapping": {
        "eligible": 500, "verified_benchmark": 411, "verified_direct": 389, "verified_rename": 22,
        "documented_no_credible_match": 89, "documented_ncm_direct": 82, "documented_ncm_rename": 7,
        "completion_unproved": 404,
    },
    "derivation_note": "The required unversioned aliases now select the 904 register. The archived 902 defaults remain immutable historical evidence; excluded surfaces remain outside H/D/M counts.",
})
csv_validation["semantic_checks"].update({
    "csv_parse": True,
    "canonical_02_03_key_coverage": True,
    "canonical_04_human_key_coverage": True,
    "feature_benchmark_completion": False,
    "visual_final_id_linkage_complete": False,
    "current_visual_classification_arithmetic": True,
})
write_json(CSV_VALIDATION, csv_validation)

completion = load_json(COMPLETION)
completion["audit_boundary"] = (
    "Audit artifacts only for filesystem writes. No application code, configuration, routes, domain data, tests, deployment or Git history was changed. "
    "A task-created Composer dependency tree was quarantined inside evidence by an earlier failed preflight and is removed by the 23 August reconciliation; its small provenance record is retained."
)
completion["gates"]["agent_assignments_reconciled_and_none_running"] = {
    "completed": 105,
    "denominator": 105,
    "percent": 100.0,
    "status": "blocked-historical-register-only-current-universe-unestablished",
    "historical_snapshot": True,
    "detail": "The historical register contains 105/105 returned records. It does not enumerate the complete later task universe, so the prompt's final all-agent reconciliation gate remains blocked even though the three 23 August independent replay agents have now returned and no audit subagent is running.",
}
completion["gates"]["routes_mapped_to_accepted_canonical_feature_id"]["detail"] = (
    "2,994 frozen route rows map to accepted targets and 30 retain excluded SURFACE dispositions. Independent source census reconstructs 2,964 explicit application route objects; provenance for the remaining 30 tracked-source rows is unresolved, so the frozen 3,024 runtime inventory is retained without claiming full source reconstruction."
)
completion["remaining_static_work_not_requiring_user_input"] = [
    "Reconcile per-route provenance for the 30 frozen tracked-source route rows not reproduced by the 2,964 explicit source objects.",
    "Target-specific benchmark/NCM research for 404 targets.",
    "Resolve 585 visual rows and 364 material-state rows without family-level inheritance.",
    "Establish the complete agent-assignment universe and reconcile every returned assignment once.",
]
completion["status"] = "BLOCKED_NOT_COMPREHENSIVE_OR_COMPLETE"
write_json(COMPLETION, completion)

reconciliation = {
    "schema_version": "1.0",
    "artifact": "dashboard-reconciliation-2026-08-23",
    "generated_at": GENERATED_AT,
    "audited_commit": AUDITED_COMMIT,
    "status": "artifact_consistency_repaired_substantive_completion_blocked",
    "independent_reviews": [
        {"agent_id": "/root/audit_gate_replay", "verdict": "NO_GO_COMPLETION_OR_FREEZE"},
        {"agent_id": "/root/audit_source_census", "verdict": "NO_GO_SOURCE_CENSUS_CONSISTENCY"},
        {"agent_id": "/root/audit_visual_evidence", "verdict": "NO_GO_VISUAL_COMPLETION"},
    ],
    "corrections": {
        "historical_902_defaults_archived": [record(HISTORICAL / name) for name in HISTORICAL_HASHES],
        "canonical_904_aliases": [record(path) for path in ALIASES],
        "overlay_classifier_sync": visual_summary["overlay_classifier_sync"],
        "visual_classification_counts": dict(sorted(classification_counts.items())),
        "ownership_credit_delta": 0,
        "runtime_credit_delta": 0,
        "remediation_credit_delta": 0,
    },
    "replayed_gates": {
        "capabilities": "904/904 structural",
        "benchmark_or_ncm": "500/904",
        "tasks_executed": "0/790",
        "journeys_all_viewports": "0/8",
        "tests_executed": "0/867",
        "modules_all_eight_passes": "0/25",
        "visual_final_id": "8168/8753",
        "material_final_id": "3948/4312",
        "audited_baseline_visual_resample": "0/4",
    },
    "claim_limit": "Canonical aliases and evidence consistency only. The audit remains blocked and no product fix, runtime, browser, usability, release, or all-pass credit is awarded.",
}
write_json(RECONCILIATION, reconciliation)

print(json.dumps({
    "status": reconciliation["status"],
    "visual_matrix": record(VISUAL_VERSIONED),
    "inventory": record(INVENTORY_VERSIONED),
    "canonical_aliases": [record(path) for path in ALIASES],
    "csv_validation": record(CSV_VALIDATION),
    "completion_report": record(COMPLETION),
    "reconciliation": record(RECONCILIATION),
    "classification_counts": dict(sorted(classification_counts.items())),
}, indent=2))
